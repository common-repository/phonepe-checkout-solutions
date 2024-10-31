<?php
function ppec_parseDescription($description){
  return html_entity_decode(strip_tags((string)$description));
}

function ppec_createOrderV1(){
  $wc_phonepe = new WC_phonepe_expressbuy();
  $merchant_id = $wc_phonepe->merchantIdentifier;
  $merchant_salt_key = $wc_phonepe->saltKey;
  $merchant_salt_index = $wc_phonepe->saltKeyIndex;
  $environment = $wc_phonepe->hostEnv;
  $debug_url = $wc_phonepe->debug_url;
  $plugin_version = $wc_phonepe->pluginVersion;

  $requestPayload = file_get_contents('php://input');
  $requestPayload = json_decode($requestPayload, true);
  $user_id = $requestPayload['userId'];

  $user = wp_set_current_user($user_id);
  $email = isset($user->user_email) ? $user->user_email  : '';

  $cart = WC()->cart;
  $items = $cart->get_cart_contents();
  $cart_data = array();
  $payable_amount = ppec_convert_to_paisa($cart->get_cart_contents_total() + $cart->get_cart_contents_tax());
  $payable_amount_in_rupees = $cart->get_cart_contents_total() + $cart->get_cart_contents_tax();

  foreach($items as $item => $values) {
	$product = wc_get_product($values['data']->get_id());
	$image = wp_get_attachment_image_src(get_post_thumbnail_id($product->get_id()), 'single-post-thumbnail');
    $attributes = $product->get_attributes();
	$cart_data[] = array(
	  'id'=> $values['data']->get_id(),
	  'name' => $product->get_title(),
      'description' => $product->get_type() == 'variation' ? $product->get_data()['attribute_summary'] : ppec_parseDescription($product->get_short_description()),
	  'mrp' => ppec_convert_to_paisa($product->get_regular_price()),
	  'totalSellingPrice' => ppec_convert_to_paisa($values['line_total'] + $values['line_tax']), // backend maintained actual value of the product
	  'sellingPrice' => ppec_convert_to_paisa($product->get_sale_price()), // rounded off value of the product - UI
	  'quantity' => $values['quantity'],
	  'total' => ppec_convert_to_paisa($values['line_total'] + $values['line_tax']), // total discounted price (discounted_for_one_item * quantity)
	  'imageUrl' => isset($image[0]) ? $image[0] : '',
 	);
  }
  $checkout = WC()->checkout();
  $order_id = $checkout->create_order(array(
    'billing_email' => $email,
    'payment_method' => 'phonepe_expressbuy'
  ));

  $order = wc_get_order($order_id);
  update_post_meta($order_id, '_customer_user', $user_id);

  $order->calculate_totals();

  $siteurl = get_option('siteurl');
  $siteurl = str_replace('http://', 'https://', $siteurl);

  $callback = sanitize_url($siteurl . '/index.php/wp-json/wp-phonepe-expressbuy/v1/expressbuy/callback');
  $order_init_data = array(
    'merchantId' => $merchant_id,
    'merchantTransactionId' => (string)$order->get_id(),
    'merchantUserId' => (string)$user_id,
    'cartItems' => $cart_data,
    'callbackUrl' => $callback,
    'payableAmount' => $payable_amount
  );
  try{
    $intent_response['data'] = PPEC_ExpressbuyApi::orderInit($order_init_data, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
    $intent_response['data']['payableAmount'] = $payable_amount_in_rupees;
    $intent_response['data']['merchantTransactionId'] = (string)$order->get_id();
    $intent_response['success'] = true;
  }catch(Exception $exception){
    PPEC_ExpressbuyApi::sendDebugResponse(json_encode($exception->getMessage()), $debug_url);
    $intent_response['success'] = false;
    $intent_response['message'] = "Something went wrong. Please try again later.";
    $intent_response['errorCode'] = $exception->getCode();
    $intent_response['errorMessage'] = $exception->getMessage();
    return new WP_REST_Response($intent_response, 400);
  }
  return new WP_REST_Response($intent_response, 200);
}

function ppec_getAllApplicableCouponsV2(&$coupons_enabled, &$coupons_count) {

    // This will return all coupons since coupons are saved as post_type shop_coupon
    $args = array(
      'post_type'      => 'shop_coupon',
      'orderby'        => 'title',
      'order'          => 'ASC',
      'fields'         => 'ids',
      'posts_per_page' => -1, // By default WP_Query will return only 10 posts, to avoid that we need to pass -1
      'post_status'      => 'publish'
    );

    $cart = WC()->cart;
    $preAppliedCouponsOnCart = WC()->cart->get_applied_coupons();
    $preAppliedCoupon = null;
    if(count($preAppliedCouponsOnCart) == 1){
        $initialCoupon = array_values($preAppliedCouponsOnCart)[0];
        $preAppliedCoupon = new WC_Coupon($initialCoupon);
        $cart->remove_coupon($preAppliedCoupon->get_code());
    }

    $applicable_coupons = array();
    if (get_option("woocommerce_enable_coupons") === "no") {
        $coupons_enabled = false;
        return $applicable_coupons;
    }

    $coupons = get_posts( $args );
    $coupons_count = count($coupons);

    foreach($coupons as $coupon_code) {
        $coupon = new WC_Coupon($coupon_code);
        if(ppec_is_coupon_valid($coupon) && ppec_isCouponValidForGuestCheckout($coupon)) {
            $data = new stdClass();
            $data->code = $coupon->get_code();
            $data->description = $coupon->get_description();

            $cart->remove_coupon($coupon->get_code());
            $isCouponAppliedOnCart = $cart->add_discount($coupon->get_code());
            wc_clear_notices();

            $cartSubtotal = 0;
            if($isCouponAppliedOnCart) {
                $data->discount = round(ppec_convert_to_paisa($cart->get_cart_discount_total() + $cart->get_cart_discount_tax_total()));
                $cartSubtotal = round(ppec_convert_to_paisa($cart->get_subtotal() + $cart->get_subtotal_tax()));
                $cart->remove_coupon($coupon->get_code());
            } else {
                $data->discount = 0;
            }

            if($data->discount && $cartSubtotal>($data->discount)){
                $applicable_coupons[] = $data;
            }
        }
    }

    if(!is_null($preAppliedCoupon)) {
        $cart->add_discount($preAppliedCoupon->get_code());
    }
    wc_clear_notices();
  
    usort($applicable_coupons, function($coupon_1, $coupon_2){ return $coupon_1->discount < $coupon_2->discount; });  
    $applicable_coupons = array_slice($applicable_coupons, 0, 20);
    return $applicable_coupons;
}

function ppec_is_coupon_valid( $coupon){
    if(!ppec_isCouponDiscountTypeValid($coupon))
        return false;

    $discounts = new WC_Discounts( WC()->cart );
    $response = $discounts->is_coupon_valid( $coupon );
    return is_wp_error( $response ) ? false : true;
}

function ppec_isCouponValidForGuestCheckout($coupon){
    $restrictions = $coupon->get_email_restrictions();
    if ( is_array( $restrictions ) && 0 < count( $restrictions )) {
        return false;
    }

    $coupon_usage_limit = $coupon->get_usage_limit_per_user();
    if ($coupon_usage_limit > 0){
        return false;
    }
    return true;
}

function ppec_createOrderV2(){
  try{
    $wc_phonepe = new WC_phonepe_expressbuy();
    $merchant_id = $wc_phonepe->merchantIdentifier;
    $merchant_salt_key = $wc_phonepe->saltKey;
    $merchant_salt_index = $wc_phonepe->saltKeyIndex;
    $environment = $wc_phonepe->hostEnv;
    $debug_url = $wc_phonepe->debug_url;
    $plugin_version = $wc_phonepe->pluginVersion;

    $requestPayload = file_get_contents('php://input');
    $requestPayload = json_decode($requestPayload, true);
    $user_id = $requestPayload['userId'];

    $user = wp_set_current_user($user_id);
    $email = isset($user->user_email) ? $user->user_email : '';

    $cart = WC()->cart;
    $items = $cart->get_cart_contents();
    $cart_data = array();
    $payable_amount = round(ppec_convert_to_paisa($cart->get_cart_contents_total() + $cart->get_cart_contents_tax()));
    $payable_amount_in_rupees = $cart->get_cart_contents_total() + $cart->get_cart_contents_tax();
    $preAppliedCouponsOnCart = $cart->get_applied_coupons();

    $preAppliedCoupon = null;
    $coupon = null;

    $ppec_failure_event = new PPEC_WC_Event();
    $ppec_failure_event->set_pluginVersion($plugin_version);
    $ppec_failure_event->set_merchantId($merchant_id);

    /*
    * TODO: Put preapplied coupon handling in function
    */
    if(count($preAppliedCouponsOnCart) == 1){
      $preAppliedCoupon = new stdClass();
      $usedCoupon = array_values($preAppliedCouponsOnCart)[0];
      $coupon = new WC_Coupon($usedCoupon);

      if(!ppec_isCouponDiscountTypeValid($coupon)){
          $ppec_failure_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
          $ppec_failure_event->set_code('PREAPPLIED_COUPON_TYPE_NOT_SUPPORTED');
          $ppec_failure_event->set_couponCode($usedCoupon);
          $ppec_failure_event->set_couponType($coupon->get_discount_type());
          $ppec_failure_event->set_eventState('FAILURE');
          ppec_pushEvent($ppec_failure_event);

          $intent_response['success'] = false;
          $intent_response['message'] = "Preapplied Coupon type is not supported";
          return new WP_REST_Response($intent_response, 400);
      }
      $couponDiscountAmount = $cart->get_coupon_discount_amount($usedCoupon, false);

      $preAppliedCoupon->code = $usedCoupon;
      $preAppliedCoupon->description =  $coupon->get_description();
      $preAppliedCoupon->discount = round(ppec_convert_to_paisa($couponDiscountAmount));
      $preAppliedCoupon->type = $coupon->get_discount_type();

      if($payable_amount < 100){
        $ppec_failure_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
        $ppec_failure_event->set_code('PREAPPLIED_COUPON_ZERO_CART_TOTAL');
        $ppec_failure_event->set_couponCode($usedCoupon);
        $ppec_failure_event->set_couponDiscount(round(ppec_convert_to_paisa($couponDiscountAmount)));
        $ppec_failure_event->set_eventState('FAILURE');
        
        ppec_pushEvent($ppec_failure_event);

        $intent_response['success'] = false;
        $intent_response['message'] = "In Preapplied Coupon, Cart Order Amount becomes zero";
        return new WP_REST_Response($intent_response, 400);
      }
    }
    else if(count($preAppliedCouponsOnCart) > 1){
        $ppec_failure_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
        $ppec_failure_event->set_code('MULTIPLE_PREAPPLIED_COUPON');
        $ppec_failure_event->set_eventState('FAILURE');
        ppec_pushEvent($ppec_failure_event);

        $intent_response['success'] = false;
        $intent_response['message'] = "Multiple pre applied coupons";
        return new WP_REST_Response($intent_response, 400);
    }

    foreach($items as $item => $values) {
      $product = wc_get_product($values['data']->get_id());
      $image = wp_get_attachment_image_src( get_post_thumbnail_id( $product->get_id() ), 'single-post-thumbnail' );
      $sellingPrice = ($product->get_sale_price() ? $product->get_sale_price() : $product->get_regular_price());
      $cart_data[] = array(
        'id'=> $values['data']->get_id(),
        'name' => $product->get_title(),
        'imageUrl' => isset($image[0]) ? $image[0] : '',
        'quantity' => $values['quantity'],
        'sellingPrice' => round(ppec_convert_to_paisa($sellingPrice)),
        'totalSellingPrice' => round(ppec_convert_to_paisa($values['line_subtotal'] + $values['line_subtotal_tax'])),
        'total' => sizeof($preAppliedCouponsOnCart) > 0 ? ppec_calculateTotal($coupon,$values) : round(ppec_convert_to_paisa($values['line_total'] + $values['line_tax'])),
        'description' =>  $product->get_type() == 'variation' ? $product->get_data()['attribute_summary'] : ppec_parseDescription($product->get_short_description())
      );
    }

    $checkout = WC()->checkout();
    $order_id = $checkout->create_order(array(
      'billing_email' => $email,
      'payment_method' => 'phonepe_expressbuy'
    ));

    $order = wc_get_order($order_id);
    update_post_meta($order_id, '_customer_user', $user_id);

    $order->calculate_totals();

    $siteurl = get_option('siteurl');
    $siteurl = str_replace('http://', 'https://', $siteurl);

    $callback = sanitize_url($siteurl . '/index.php/wp-json/wp-phonepe-expressbuy/v1/expressbuy/callback');

    $coupons_enabled = true;
    $coupons_count = 20;

    $order_init_data = array(
      'merchantId' => $merchant_id,
      'merchantTransactionId' => (string)($order->get_id()),
      'merchantUserId' => (string)$user_id,
      'cartItems' => $cart_data,
      'payableAmount' => $payable_amount,
      'callbackUrl' => $callback,
    );

    if(empty($preAppliedCouponsOnCart)){
      $order_init_data['coupons'] = ppec_getAllApplicableCouponsV2($coupons_enabled, $coupons_count);
      $order_init_data['couponApplicabilityUrl'] = sanitize_url($siteurl . '/index.php/wp-json/wp-phonepe-expressbuy/v2/apply/coupon');
    }
      $intent_response['data'] = PPEC_ExpressbuyApi::orderInit($order_init_data, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
    $intent_response['data']['payableAmount'] = $payable_amount_in_rupees;
    $intent_response['data']['merchantTransactionId'] = (string)$order->get_id();
    $intent_response['data']['isCouponEnabled'] = $coupons_enabled;
    $intent_response['data']['couponsCount'] = $coupons_count;
    $intent_response['data']['couponCode'] = $preAppliedCoupon ? $preAppliedCoupon->code : null;
    $intent_response['data']['couponDiscount'] = $preAppliedCoupon ? $preAppliedCoupon->discount : null;
    $intent_response['success'] = true;
    return new WP_REST_Response($intent_response, 200);
  }catch(Exception $exception){
    PPEC_ExpressbuyApi::sendDebugResponse(json_encode($exception->getMessage()), $debug_url);
    $intent_response['success'] = false;
    $intent_response['message'] = "Something went wrong. Please try again later.";
    $intent_response['errorCode'] = $exception->getCode();
    $intent_response['errorMessage'] = $exception->getMessage();

    $ppec_failure_event->set_eventType('PHONEPE_CHECKOUT_FAILURE');
    $ppec_failure_event->set_code($exception->getCode());
    $ppec_failure_event->set_message($exception->getTraceAsString());
    $ppec_failure_event->set_eventState('FAILURE');
    ppec_pushEvent($ppec_failure_event);
    
    return new WP_REST_Response($intent_response, 400);
  }
}