<?php

function ppec_validateCouponRequestParams($param){
    if (empty(sanitize_text_field($param["couponCode"]))) {
        $response['success'] = false;
        $response['code'] = 'BAD_INPUT_COUPON_CODE_EMPTY';
        $response['message'] = "Coupon code is empty";
        return new WP_REST_Response($response, 400);
    } elseif (empty(sanitize_text_field($param["merchantTransactionId"]))) {
        $response['success'] = false;
        $response['code'] = 'BAD_INPUT_MERCHANT_TRANSACTION_ID_EMPTY';
        $response['message'] = "Merchant transaction id is empty";
        return new WP_REST_Response($response, 400);
    }
    return true;
}

function ppec_getErrorCodes($failureMessage){
    $result                 = [];

    if ($failureMessage === "" || is_null($failureMessage)) {
        $result["failure_reason"] = "";
    } elseif (stripos($failureMessage, "does not exist") !== false) {
        $result["failure_reason"] = "Coupon does not exist";
        $result["failure_code"]   = "INVALID_COUPON_CODE";
    } elseif (stripos($failureMessage, "coupon has expired") !== false) {
        $result["failure_reason"] = "This coupon has expired";
        $result["failure_code"] = "COUPON_EXPIRED";
    } elseif (stripos($failureMessage, "minimum spend") !== false) {
        $result["failure_reason"] = "Cart is below the minimum amount";
        $result["failure_code"] = "COUPON_MIN_SPEND_LIMIT_NOT_MET";
    } elseif (stripos($failureMessage, "maximum spend") !== false) {
        $result["failure_reason"] = "Cart is above maximum allowed amount";
        $result["failure_code"] = "COUPON_MAX_SPEND_LIMIT_BREACHED";
    } elseif (
        stripos($failureMessage, "not applicable to selected products") !== false) {
        $result["failure_reason"] = "Required product is missing";
        $result["failure_code"]   = "COUPON_NOT_APPLICABLE_ON_CART_ITEMS";
    } elseif (
        stripos($failureMessage, "Coupon usage limit has been reached") !== false){
        $result["failure_reason"] = "Coupon usage limit has been reached";
        $result["failure_code"] = "COUPON_COUNT_LIMIT_BREACHED";
    } else {
        $result["failure_reason"] = "Coupon could not be applied";
        $result["failure_code"]   = "INVALID_COUPON_CODE";
    }
    return $result;
}

function ppec_calculateTotal($coupon, $values){
    $type = $coupon->get_discount_type();
    switch($type){
        case 'fixed_cart':
            return round(ppec_convert_to_paisa($values['line_subtotal'] + $values['line_subtotal_tax']));
        case 'percent':
            if ( count( $coupon->get_product_ids() ) > 0 || $coupon->get_limit_usage_to_x_items() !==null) {
                return round(ppec_convert_to_paisa(max($values['line_total'] + $values['line_tax'],0)));
            }
            else{
                return round(ppec_convert_to_paisa($values['line_subtotal'] + $values['line_subtotal_tax']));
            }
        default:
            return round(ppec_convert_to_paisa(max($values['line_total'] + $values['line_tax'],0)));
    }
}

function ppec_removeOrderCoupons($order){
    if ( count( $order->get_coupon_codes() ) > 0 ) {
        foreach ( $order->get_coupon_codes() as $code ) {
            $order->remove_coupon($code);
        }
    }
}

function ppec_removeCartCoupons(){
    foreach ( WC()->cart->get_coupons() as $code => $oldCoupon ){
        WC()->cart->remove_coupon( $code );
    }
}

function ppec_isCouponDiscountTypeValid($coupon){
    if($coupon->get_discount_type() != 'fixed_cart'
        && $coupon->get_discount_type() != 'fixed_product'
        && $coupon->get_discount_type() != 'percent') {
        return false;
    }
    return true;
}

function ppec_buildCouponInvalidErrorResponse(){
    $response = [];

    return new WP_REST_Response($response, 400);
}

function ppec_applyCouponOnCartForPhonepeCheckout(WP_REST_Request $request){
	$wc_phonepe = new WC_phonepe_expressbuy();
	$debug_url = $wc_phonepe->debug_url;

    $params = $request->get_params();
    $validation_result = ppec_validateCouponRequestParams($params);
    if(!$validation_result)
        return $validation_result;

    $couponCode = sanitize_text_field($params["couponCode"]);
    $orderId    = sanitize_text_field($params["merchantTransactionId"]);
    $order  = wc_get_order($orderId);
    $discountAmountOnCart = null;

    $ppec_event = new PPEC_WC_Event();
    $ppec_event->set_couponCode($couponCode);


    try{
        if(!$order || !$order->get_item_count())
            throw new PPEC_ValidationException('MERCHANT_TRANSACTION_ID_NOT_FOUND');

        if (get_option("woocommerce_enable_coupons") === "no") {
            throw new PPEC_CouponValidationException('COUPONS_DISABLED', $couponCode);
        }

        $coupon = new WC_Coupon($couponCode);
        if(!ppec_isCouponDiscountTypeValid($coupon)){
            throw new PPEC_CouponValidationException('COUPON_TYPE_NOT_SUPPORTED', $couponCode, $coupon->get_discount_type());
        }

        if(!ppec_isCouponValidForGuestCheckout($coupon)){
            throw new PPEC_CouponValidationException('USER_SPECIFIC_COUPONS_NOT_SUPPORTED', $couponCode);
        }

        ppec_initSessionAndCart();
        ppec_createCart($order);
        ppec_removeOrderCoupons($order);
        ppec_removeCartCoupons();

        $isCouponAppliedOnCart = WC()->cart->add_discount($couponCode);
        wc_clear_notices();
        if(!$isCouponAppliedOnCart){
            $markup       = wc_print_notices(true);
            $errorArray   = explode("<li>", $markup);
            $errorMessage = preg_replace(
                "/\t|\n/",
                "",
                strip_tags(end($errorArray))
            );
            $failureReason = html_entity_decode($errorMessage);

            $error = ppec_getErrorCodes($failureReason);

            $response['success'] = false;
            $response['code'] = 'INVALID_COUPON_CODE';
            $response['message'] = $error["failure_reason"];

            $ppec_event->set_eventType('COUPON_APPLIED');
            $ppec_event->set_code($error["failure_code"]);
            $ppec_event->set_merchantTransactionId($orderId);
            $ppec_event->set_message($error["failure_reason"]);
            $ppec_event->set_eventState('FAILURE');
            ppec_pushEvent($ppec_event);
            
            return new WP_REST_Response($response, 400);
        }

        $discountAmountOnCart = round(ppec_convert_to_paisa(WC()->cart->get_cart_discount_total() + WC()->cart->get_cart_discount_tax_total()));
		if(!$discountAmountOnCart){
            throw new PPEC_CouponValidationException('COUPON_DISCOUNT_ZERO', $couponCode);
        }

        $cartSubtotalAfterCouponDiscount = round(ppec_convert_to_paisa(WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax()));
        if($cartSubtotalAfterCouponDiscount <= 0){
            throw new PPEC_CouponValidationException('ZERO_CART_TOTAL', $couponCode);
        }

        $eligibleCartItemsCount = 0;

        $cart = WC()->cart;
        $items = $cart->get_cart_contents();
        $cart_data = array();

        $limit_usage_qty = $cart->get_cart_contents_count();
        if ( null !== $coupon->get_limit_usage_to_x_items() ) {
            $limit_usage_qty = $coupon->get_limit_usage_to_x_items();
        }

        foreach($items as $item => $values) {
            $product = wc_get_product( $values['data']->get_id());
            $image = wp_get_attachment_image_src( get_post_thumbnail_id( $product->get_id() ), 'single-post-thumbnail' );
            if($values['line_subtotal'] > $values['line_total']) $eligibleCartItemsCount += $values['quantity'];
            $sellingPrice = ($product->get_sale_price() ? $product->get_sale_price() : $product->get_regular_price());

            $cart_data[]=array(
                'id' => $values['product_id'],
                'name' => $product->get_title(),
                'imageUrl' => isset($image[0]) ? $image[0] : '',
                'quantity' => $values['quantity'],
                'sellingPrice' => round(ppec_convert_to_paisa($sellingPrice)),
                'totalSellingPrice' =>  round(ppec_convert_to_paisa($values['line_subtotal'] + $values['line_subtotal_tax'])),
                'total' =>   ppec_calculateTotal($coupon, $values),
                'description' => $product->get_type() == 'variation' ? $product->get_data()['attribute_summary'] : ppec_parseDescription($product->get_short_description())
            );
        }

        $appliedCoupon = [];
        $appliedCoupon["discount"] = $discountAmountOnCart;
        $appliedCoupon["code"] = $couponCode;
        $appliedCoupon['description'] = $coupon->get_description();
        $appliedCoupon['eligibleCartItems'] = min($eligibleCartItemsCount, $limit_usage_qty);

        WC()->cart->remove_coupon($couponCode);
        wc_clear_notices();

        $data = [];
        $data["cartItems"]   =  $cart_data;
        $data['payableAmount'] = $cartSubtotalAfterCouponDiscount;
        $data['appliedCoupon'] = $appliedCoupon;

        $ppec_event->set_eventType('COUPON_APPLIED');
        $ppec_event->set_code('SUCCESS');
        $ppec_event->set_merchantTransactionId($orderId);
        $ppec_event->set_eventState('SUCCESS');
        $ppec_event->set_couponDiscount($discountAmountOnCart);
        ppec_pushEvent($ppec_event);

        return new WP_REST_Response($data, 200);
    }catch(PPEC_CouponValidationException $couponValidationException){
        $ppec_event->set_eventType('COUPON_APPLIED');
        $ppec_event->set_code($couponValidationException->getCode());
        $ppec_event->set_message($couponValidationException->getTraceAsString());
        $ppec_event->set_merchantTransactionId($orderId);
        $ppec_event->set_eventState('FAILURE');
        $ppec_event->set_couponType($couponValidationException->getCouponType());
        $ppec_event->set_couponDiscount($discountAmountOnCart);
        ppec_pushEvent($ppec_event);

        return ppec_buildCouponInvalidErrorResponse();
    }catch(PPEC_ValidationException $validationException){
        $ppec_event->set_eventType('COUPON_APPLIED');
        $ppec_event->set_code($validationException->getCode());
        $ppec_event->set_message($validationException->getTraceAsString());
        $ppec_event->set_merchantTransactionId($orderId);
        $ppec_event->set_eventState('FAILURE');
        $ppec_event->set_couponDiscount($discountAmountOnCart);
        ppec_pushEvent($ppec_event);

        return ppec_buildCouponInvalidErrorResponse();
    }
    catch(Exception $exception) {
        $ppec_event->set_eventType('COUPON_APPLIED');
        $ppec_event->set_code('PHP_ERROR_' .$exception->getCode());
        $ppec_event->set_message($exception->getTraceAsString());
        $ppec_event->set_merchantTransactionId($orderId);
        $ppec_event->set_eventState('FAILURE');
        $ppec_event->set_couponDiscount($discountAmountOnCart);
        ppec_pushEvent($ppec_event);

        return ppec_buildCouponInvalidErrorResponse();
    }
}
