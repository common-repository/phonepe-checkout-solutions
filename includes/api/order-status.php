<?php

function ppec_validateData($data){
  if($data == null)
    throw new PPEC_ValidationException('CHECKSUM_VERIFICATION_FAILED');
}

function ppec_validateOrder($order){
  if($order == false)
    throw new PPEC_ValidationException('MERCHANT_TRANSACTION_ID_NOT_FOUND');
  else if($order->get_status() != 'pending') // order is already in a terminal state
    throw new PPEC_RedundantCallbackException();
}

function ppec_validateCouponAmount($coupon_amount, $woocommerce_coupon_amount, $coupon_code){
    if($coupon_amount != $woocommerce_coupon_amount)
        throw new PPEC_CouponValidationException('COUPON_AMOUNT_MISMATCH', $coupon_code);
}

function ppec_validateOrderAmount($order_amount, $woocommerce_order_amount, $total, $shipping_charges, $payment_charges, $coupon_discount){
  if($order_amount < 0)
    throw new PPEC_ValidationException('NEGATIVE_ORDER_AMOUNT');
  
  // format prices from ₹2.989 to ₹2.99 - refer lines
  if($order_amount != $woocommerce_order_amount)
    throw new PPEC_ValidationException('ORDER_AMOUNT_MISMATCH');

  if(abs(($total + $coupon_discount) - ($order_amount + $shipping_charges + $payment_charges)) > 100) //1 paisa buffer due to woocommerce gst tax error
    throw new PPEC_ValidationException('TOTAL_AMOUNT_SUMMATION_INCORRECT');

  if($total < 0)
    throw new PPEC_ValidationException('NEGATIVE_TOTAL_AMOUNT');
}

function ppec_validateShipping($supported_shipping_methods, $shipping_method_key, $shipping_charges){
  if($shipping_charges < 0)
    throw new PPEC_ValidationException('NEGATIVE_SHIPPING_CHARGES');

  if(!isset($supported_shipping_methods[$shipping_method_key]))
    throw new PPEC_ValidationException('UNDEFINED_SHIPPING_METHOD');

  $woocommerce_shipping_charges = $supported_shipping_methods[$shipping_method_key]['shipping_charges'];
  if($shipping_charges != $woocommerce_shipping_charges)
    throw new PPEC_ValidationException('SHIPPING_CHARGES_MISMATCH');
}

function ppec_validatePayment($supported_payment_methods, $payment_method_key)
{
  if(!isset($supported_payment_methods[$payment_method_key]))
    throw new PPEC_ValidationException('UNDEFINED_PAYMENT_METHOD');
}

function ppec_validatePhoneNumber($phone_number)
{
  $phone_number_regex = '/^[4-9][0-9]{9}$/';  
  if(preg_match($phone_number_regex, $phone_number) != 1)
    throw new PPEC_ValidationException('INVALID_PHONE_NUMBER');
}

function ppec_get_coupon_code_and_discount($data){
  $coupon_details = [];
  foreach($data['amountBreakup'] as $details)
  {
    $type = $details['type'];
    switch($type){
      case 'ORDER':
      case 'PAYMENT':
      case 'SHIPPING':
            break;

      case 'COUPON':
          $coupon_details = $details;
            break;
      default:
          throw new ValidationException('UNDEFINED_TYPE_IN_AMOUNT_BREAKUP');
          break;
    }
  }

  $coupon = [];
  $coupon['coupon_discount'] = isset($coupon_details['couponDiscount']) ? $coupon_details['couponDiscount'] : 0;
  $coupon['coupon_code'] = isset($coupon_details['couponCode']) ? $coupon_details['couponCode'] : null;
 
  return $coupon;
}

function ppec_startRecon($data){
  $merchant_transaction_id = sanitize_text_field($data->get_param('merchantTransactionId')); //$_SESSION['merchant_transaction_id'] ?? $data['merchantTransactionId'];
  PPEC_ExpressbuyApi::sendDebugResponse(json_encode($merchant_transaction_id));
  if(empty($merchant_transaction_id)){
    $response['code'] = 'MERCHANT_TRANSACTION_ID_NOT_FOUND';
    $response['message'] = 'Something went wrong. Please contact the merchant for more information.';
    $response['success'] = false;
    return new WP_REST_Response($response, 400);
  }

  $wc_phonepe = new WC_phonepe_expressbuy();
  $merchant_id = $wc_phonepe->merchantIdentifier;
  $merchant_salt_key = $wc_phonepe->saltKey;
  $merchant_salt_index = $wc_phonepe->saltKeyIndex;
  $environment = $wc_phonepe->hostEnv;
  $debug_url = $wc_phonepe->debug_url;
  $plugin_version = $wc_phonepe->pluginVersion;
  $configurable_timeout = $wc_phonepe->timeout; // "mm:ss"

  $method = 'STATUS_CHECK';

  $ppec_event = new PPEC_WC_Event();
  $ppec_event->set_method('STATUS_CHECK');
  $ppec_event->set_merchantId($merchant_id);
  $ppec_event->set_merchantTransactionId($merchant_transaction_id);
  $ppec_event->set_pluginVersion($plugin_version);

  $split_time = explode(":", $configurable_timeout);
  $mm = $split_time[0];
  $ss = $split_time[1];
  $minutes = $mm[0] * 10 + $mm[1];
  $seconds = $ss[0] * 10 + $ss[1];  
  $timeout = $minutes * 60 + $seconds; // convert to seconds

  $time_passed = 0;
  $backoff = 0;

  do{
    try{
      sleep($backoff);
      $data = PPEC_ExpressbuyApi::checkStatus($merchant_id, $merchant_transaction_id, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
      $order_state = sanitize_text_field($data['state']);
      $time_passed += $backoff + 1; // assume network calls to be under a second when debug is off
      $backoff = $backoff * 2 + 1; // exponential backoff strategy
    }catch(Exception $exception){
      $ppec_event->set_eventType('ORDER_STATUS_UPDATE');
      $ppec_event->set_state('FAILURE');
      $ppec_event->set_code($exception->getCode());
      $ppec_event->set_message($exception->getTraceAsString());

      $response['success'] = false;
      $response['message'] = 'Something went wrong. Please reach out to the merchant for more details.';

      ppec_pushEvent($ppec_event);
      return new WP_REST_Response($response, 400);
    }
  }while($order_state != 'COMPLETED' && $order_state != 'FAILED' && $time_passed <= $timeout);
  
  $order = wc_get_order($merchant_transaction_id);

  if($order_state == 'COMPLETED' || $order_state == 'FAILED'){
    try{
      $redirect = ppec_logExpressbuyOrder($data, $method);
      $coupon = ppec_get_coupon_code_and_discount($data);

      $ppec_event->set_eventType('ORDER_STATUS_UPDATE');
      $ppec_event->set_state('SUCCESS');
      $ppec_event->set_couponCode($coupon['coupon_code']);
      $ppec_event->set_couponDiscount($coupon['coupon_discount']);
      ppec_pushEvent($ppec_event);

      $response = [];
      if($redirect == 'success'){
        $redirectUrl = $order->get_checkout_order_received_url();
        $response["success"] = true;
        $response["redirectUrl"] = $redirectUrl;
      }
      else if($redirect == 'failure'){
        $response = [
          "success" => false,
          "message" => "Something went wrong with your payment. Your order has been cancelled. Please contact the merchant for more details.",
          "redirectUrl" => get_option('siteurl')
        ];
      }
    return new WP_REST_Response($response, 200);
    }catch(PPEC_CouponValidationException $couponValidationException){
      $ppec_event->set_eventType('ORDER_STATUS_UPDATE');
      $ppec_event->set_state('FAILURE');
      $ppec_event->set_merchantTransactionId();
      $ppec_event->set_code($couponValidationException->getCode(sanitize_text_field($data['merchantTransactionId'])));
      $ppec_event->set_message($couponValidationException->getTraceAsString());
      $ppec_event->set_couponCode($couponValidationException->getCouponCode());

      ppec_pushEvent($ppec_event);
      $response = [
        "success" => false, 
        "message" => "Something went wrong. Please reach out to the merchant for more details.", 
        "redirectUrl" => get_option('siteurl'),
        "code" => $couponValidationException->getCode()
      ];
      return new WP_REST_Response($response, 200);
    }catch(PPEC_RedundantCallbackException $redundantCallbackException){
      $order_state = sanitize_text_field($data['state']);
      $response = [];

      if($order_state == 'COMPLETED'){
        $redirectUrl = $order->get_checkout_order_received_url();
        $response["success"] = true;
        $response["redirectUrl"] = $redirectUrl;
      }else if($order_state == 'FAILED'){
        $response = [
        "success" => false, 
        "message" => "Something went wrong with your payment. Your order has been cancelled. Please contact the merchant for more details.", 
        "redirectUrl" => get_option('siteurl')
        ];
      }

      $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
      $ppec_event->set_state('FAILURE');
      $ppec_event->set_code($redundantCallbackException->getCode());
      $ppec_event->set_message($redundantCallbackException->getTraceAsString());
      ppec_pushEvent($ppec_event);

      return new WP_REST_Response($response, 200);
    }catch(PPEC_ValidationException $validationException){  
      $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
      $ppec_event->set_state('FAILURE');
      $ppec_event->set_code($validationException->getCode());
      $ppec_event->set_message($validationException->getTraceAsString());
      ppec_pushEvent($ppec_event);

      $response = [
        "success" => false, 
        "message" => "Something went wrong. Please reach out to the merchant for more details.", 
        "redirectUrl" => get_option('siteurl'),
        "code" => $validationException->getCode()
      ];
     
      return new WP_REST_Response($response, 200);
    }catch(Exception $exception){   
      $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
      $ppec_event->set_state('FAILURE');
      $ppec_event->set_merchantTransactionId(sanitize_text_field($data['merchantTransactionId']));
      $ppec_event->set_code($exception->getCode());
      $ppec_event->set_message($exception->getTraceAsString());
      ppec_pushEvent($ppec_event);
      $response = [
        "success" => false, 
        "message" => "Something went wrong. Please reach out to the merchant for more details.", 
        "redirectUrl" => get_option('siteurl'),
        "code" => $exception->getCode()
      ];
      return new WP_REST_Response($response, 200);
    }
  }

  $response = [];
  $response['message'] = "It looks like we're taking a while to process your order. Please reach out to the merchant for more details.";
  $response["success"] = false;
  return new WP_REST_Response($response, 408);
}

function ppec_expressbuyCallback(){
  /*
  * updates the following information in the order
  * 1. customer information
  * 2. shipping information
  * 3. payment information
  * 4. coupon information
  */
  $wc_phonepe = new WC_phonepe_expressbuy();
  $merchant_salt_key = $wc_phonepe->saltKey;
  $merchant_salt_index = $wc_phonepe->saltKeyIndex;
  $debug_url = $wc_phonepe->debug_url;

  $payload = file_get_contents('php://input');
  $authProperties = [
	  'Verify' => sanitize_text_field($_SERVER["HTTP_X_VERIFY"]),
	  'Salt-Index' => sanitize_text_field($_SERVER["HTTP_X_SALT_INDEX"])
  ];

  PPEC_ExpressbuyApi::sendDebugResponse(json_encode($authProperties, JSON_PRETTY_PRINT), "");
  $data = PPEC_ExpressbuyApi::handleCallback($payload, $authProperties, $merchant_salt_key, $merchant_salt_index);
  PPEC_ExpressbuyApi::sendDebugResponse(json_encode(["callback received", $data]), $debug_url);
  $method = 'CALLBACK';

  $ppec_event = new PPEC_WC_Event();
  $ppec_event->set_method('CALLBACK');
  $ppec_event->set_merchantId($wc_phonepe->merchantIdentifier);
  $ppec_event->set_merchantTransactionId($data['merchantTransactionId']);
  $ppec_event->set_pluginVersion($wc_phonepe->pluginVersion);

  try{
    ppec_logExpressbuyOrder($data, $method);
    $coupon = ppec_get_coupon_code_and_discount($data);

    $ppec_event->set_eventType('ORDER_STATUS_UPDATE');
    $ppec_event->set_state('SUCCESS');
    $ppec_event->set_couponCode($coupon['coupon_code']);
    $ppec_event->set_couponDiscount($coupon['coupon_discount']);
    ppec_pushEvent($ppec_event);

    $response = ["success" => true];
    // a 200 is expected to let Hermes know the cb was received
    return new WP_REST_Response($response, 200);
      
  }catch(PPEC_RedundantCallbackException $redundantCallbackException){
    $response = ["success" => true];

    $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
    $ppec_event->set_code($redundantCallbackException->getCode());
    $ppec_event->set_message($redundantCallbackException->getTraceAsString());
    pppec_pushEvent($ppec_event);

      
    return new WP_REST_Response($response, 200);
  }catch(PPEC_ValidationException $exception){
    $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
    $ppec_event->set_state('SUCCESS');
    $ppec_event->set_code($exception->getCode());
    $ppec_event->set_message($exception->getTraceAsString());
    ppec_pushEvent($ppec_event);

    $response = [
      "success" => false, 
      "code" => $exception->getCode(),
      "message" => 'Something went wrong with logging the order'
    ];
   
    return new WP_REST_Response($response, 400);
  }catch(Exception $exception){
    $response = [
      "success" => false, 
      "code" => $exception->getCode(),
      "message" => 'Something went wrong with logging the order'
    ];

    $ppec_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
    $ppec_event->set_state('SUCCESS');
    $ppec_event->set_merchantTransactionId(sanitize_text_field($data['merchantTransactionId']));
    $ppec_event->set_code($exception->getCode());
    $ppec_event->set_message($exception->getTraceAsString()); 
    ppec_pushEvent($ppec_event);
    return new WP_REST_Response($response, 400);
  }
  $response['success'] = true;
  return new WP_REST_Response($response);
}

function getShippingMethodByInstaceId($shipping_method_instance_id){
  $all_zones = WC_Shipping_Zones::get_zones();

		foreach($all_zones as $zone_obj){
      $zone = WC_Shipping_Zones::get_zone((int) $zone_obj['id']);
      $locations_in_zone = $zone->get_data();
      $all_shipping_methods = $zone->get_shipping_methods();
      if($all_shipping_methods == []) continue;

      $shipping_methods = ppec_get_data_from_all_shipping_methods($all_shipping_methods);
      foreach($shipping_methods as $shipping_method){
        if($shipping_method['id'] == $shipping_method_instance_id){
          return $shipping_method;
        }
      }
    }
    return null;
}

function getWcShippingType($phonepe_shipping_type){
  switch($phonepe_shipping_type){
    case 'FLAT_RATE_SHIPPING': return 'flat_rate';
    case 'FREE_SHIPPING': return 'free_shipping';
  }
}

function ppec_logExpressbuyOrder($data, $method){
  // for avoiding race conditions - https://stackoverflow.com/questions/9289269/most-reliable-safe-method-of-preventing-race-conditions-in-php
  session_start([
	  'read_and_close' => true,
  ]);

  $wc_phonepe = new WC_phonepe_expressbuy();
  $debug_url = $wc_phonepe->debug_url;
  ppec_validateData($data);

  $merchant_transaction_id = sanitize_text_field($data['merchantTransactionId']);
  $order = wc_get_order($merchant_transaction_id);
  ppec_validateOrder($order);

  $transaction_id = $data['transactionId'];
  $order_state = sanitize_text_field($data['state']);
  if($order_state == "FAILED"){
    $order->update_status('wc-failed'); 
    $note = __("Payment failed. Failure Reason: " . sanitize_text_field($data['responseCode']) . "\nPhonePe TransactionID : ". $transaction_id);
    $order->add_order_note( $note );
    $order->save();
    return "failure";
  }

  $payment_details = [];
  $shipping_details = [];
  $coupon_details = [];
  foreach($data['amountBreakup'] as $details)
  {
    $type = $details['type'];
    switch($type){
      case 'ORDER':
        $order_amount = $details['amount'];
        break;

      case 'PAYMENT':
          $payment_details = $details;
          break;

      case 'SHIPPING':
          $shipping_details = $details;
          break;

      case 'COUPON':
          $coupon_details = $details;
            break;
      default:
          throw new PPEC_ValidationException('UNDEFINED_TYPE_IN_AMOUNT_BREAKUP');
          break;
    }
  }
  $address = $data['address'];
  $shipping_charges = isset($shipping_details['shippingCharges']) ? $shipping_details['shippingCharges'] : 0;
  $payment_charges = isset($payment_details['paymentCharges']) ? $payment_details['paymentCharges'] : 0;
  $coupon_discount = isset($coupon_details['couponDiscount']) ? $coupon_details['couponDiscount'] : 0;
  $coupon_code = isset($coupon_details['couponCode']) ? sanitize_text_field($coupon_details['couponCode']) : null;
  $total = $data['totalAmount'];
  $discountAmountOnCart = 0;

  if($coupon_code != ""){
    ppec_initSessionAndCart();
    ppec_createCart($order);
    ppec_removeOrderCoupons($order);
    ppec_removeCartCoupons();

    $isCouponAppliedOnCart = WC()->cart->add_discount($coupon_code);
    if($isCouponAppliedOnCart){
        $discountAmountOnCart = round(ppec_convert_to_paisa(WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total()));
    }
    ppec_validateCouponAmount($coupon_discount, $discountAmountOnCart, $coupon_code);
  }

  $woocommerce_order_amount = $order->get_subtotal() + $order->get_cart_tax();
  if(empty($coupon_code)){
	  $woocommerce_order_amount -= $order->get_discount_total();
  }
  $woocommerce_order_amount = round(ppec_convert_to_paisa($woocommerce_order_amount));
  ppec_validateOrderAmount($order_amount, $woocommerce_order_amount, $total, $shipping_charges, $payment_charges, $coupon_discount);

  $shipping_method_instance_id = $shipping_details['shippingId'];
  $wc_shipping_method = getShippingMethodByInstaceId($shipping_method_instance_id);
  if($wc_shipping_method == null)
    throw new PPEC_ValidationException('SHIPPING_METHOD_NO_LONGER_EXISTS');

  $wc_shipping_charges = $wc_shipping_method['charges'];
  $wc_shipping_title = 'Shipping (' . $wc_shipping_method['name'] . ')';
  $wc_shipping_id = getWcShippingType($wc_shipping_method['type']) . ':' . $shipping_method_instance_id;

  $supported_shipping_methods = [
    'FLAT_RATE_SHIPPING' => [
        'shipping_charges' => $wc_shipping_charges
    ],
    'FREE_SHIPPING' => [
        'shipping_charges' => 0
    ]
  ];
  
  $shipping_method_key = sanitize_text_field($shipping_details['shippingType']);
  ppec_validateShipping($supported_shipping_methods, $shipping_method_key, $shipping_charges);

  $payment_title = $payment_code = "";
  $wc_cod_payment_charges = 0;
  switch($wc_phonepe->cod_config){
    case 'default_wc_cod':
      break;

    case 'pan_india_cod':
      $wc_cod_payment_charges = $wc_phonepe->pan_india_cod_charges;
      break;
  }

  $supported_payment_methods = [
    'COD' => [
        'payment_title' => 'Cash on Delivery with PhonePe Checkout',
        'payment_code' => 'phonepe_expressbuy',
        'payment_successful_order_status' => 'on-hold',
        'payment_charges' => $wc_cod_payment_charges,
        'payment_fees_title' => 'PAN India COD Charges'
    ],
    'PREPAID' => [
        'payment_title' => 'Prepaid with PhonePe Checkout', 
        'payment_code' => 'phonepe_expressbuy',
        'payment_successful_order_status' => 'processing',
        'payment_fees_title' => 'Prepaid Charges'
    ]
  ];
  $payment_method_key = sanitize_text_field($payment_details['paymentMode']);
  ppec_validatePayment($supported_payment_methods, $payment_method_key, $payment_charges);
  $payment_method = $supported_payment_methods[$payment_method_key];
  $payment_title = $payment_method['payment_title'];
  $payment_fees_title = $payment_method['payment_fees_title'];
  $payment_code = $payment_method['payment_code'];
  $payment_successful_order_status = $payment_method['payment_successful_order_status'];
  ppec_validatePhoneNumber($address['phoneNumber']);

  try{
    if($order_state == "COMPLETED"){
      $order->update_status($payment_successful_order_status);
      $note = __("Payment successful. PhonePe TransactionID : ". $transaction_id);
      $order->add_order_note( $note );
      $address_1 = $address['houseNo'] . ', ' . $address['address'];
      $address_1 = isset($address['locality']) ? $address_1 . ', ' . $address['locality'] : $address_1;
      $address_1 = isset($address['landmark']) ? $address_1 . ', ' . $address['landmark'] : $address_1;
      $orderAddress = array(
        'first_name' => sanitize_text_field($address['name']),
        'phone' => sanitize_text_field($address['phoneNumber']),
        'address_1' => sanitize_text_field($address_1),
        'address_2' => 'Intelligent Address: ' . sanitize_text_field($address['formattedAddress']) . ' (powered by PhonePe)',
        'city' => sanitize_text_field($address['city']),
        'state' => sanitize_text_field($address['state']),
        'postcode' => sanitize_text_field($address['pin']),
      );
      $google_maps_link = 'https://www.google.com/maps/search/?api=1&query' . $address['location']['latitude'] . ',' . $address['location']['longitude'];
      $order->add_order_note($google_maps_link);

      $order->set_address($orderAddress,'billing');
      $order->set_address($orderAddress,'shipping');

      $shipping_fee = new WC_Order_Item_Shipping();
      $shipping_fee->set_method_title($wc_shipping_title);
      $shipping_fee->set_method_id($wc_shipping_id);
      $shipping_fee->set_total((float)$shipping_charges / 100.0);  
      $order->add_item($shipping_fee);

      $order->set_payment_method($payment_code);
      $order->set_payment_method_title($payment_title);

      $payment_fee = new WC_Order_Item_Fee();
      $payment_fee->set_name("Fees (" . $payment_fees_title . ")"); 
      $payment_fee->set_amount((float)$payment_charges / 100.0); // Fee amount
      $payment_fee->set_tax_status('none');
      $payment_fee->set_total((float)$payment_charges / 100.0);
      $order->add_item($payment_fee);

      if(isset($coupon_code)){
          $coupon = new WC_Coupon($coupon_code);
          $coupon_usage_count = $coupon->get_usage_count();
          $coupon_usage_total_limit = $coupon->get_usage_limit();

          if($coupon_usage_total_limit && $coupon_usage_count >= $coupon_usage_total_limit){
              $note = __("Please note : This order has been placed after reaching the maximum limit of Coupon '" . $coupon_code . "' . Please proceed as convenient");
              $order->add_order_note( $note );
          }

          $order->apply_coupon($coupon_code);
      }

      $order->calculate_totals();
      $order->save();

      $wc_phonepe = new WC_phonepe_expressbuy();
      $ppec_event = new PPEC_WC_Event();
      $ppec_event->set_eventType('ORDER_STATUS_UPDATE');
      $ppec_event->set_method($method);
      $ppec_event->set_merchantTransactionId($merchant_transaction_id);
      $ppec_event->set_state('SUCCESS');
      $ppec_event->set_couponCode($coupon_code);
      $ppec_event->set_couponDiscount($coupon_discount); 
      ppec_pushEvent($ppec_event);
      session_write_close();
      return "success"; // the only point where we know the order was successfully logged
    }
  }catch(Exception $exception){
    session_write_close(); // close session and then throw error
    throw new Exception($exception);
  }
  session_write_close();
  return "failure";
}