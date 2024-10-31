<?php

define('PPEC_EXPRESSBUY_ROUTES_BASE', 'wp-phonepe-expressbuy/v1');
define('PPEC_EXPRESSBUY_ROUTES_BASE_V2', 'wp-phonepe-expressbuy/v2');

add_filter('woocommerce_is_rest_api_request', 'ppec_simulate_as_not_rest');
/**
 * We have to tell WC that this should not be handled as a REST request.
 * Otherwise we can't use the product loop template contents properly.
 * Since WooCommerce 3.6
 *
 * @param bool $is_rest_api_request
 * @return bool
 */
function ppec_simulate_as_not_rest($is_rest_api_request) {
	if(empty($_SERVER['REQUEST_URI'])){
        return $is_rest_api_request;
	}

    if(strpos($_SERVER['REQUEST_URI'],'/index.php/wp-json/' . PPEC_EXPRESSBUY_ROUTES_BASE . '/order/create') !== false){
        return false;
    }

    if(strpos($_SERVER['REQUEST_URI'],'/index.php/wp-json/' . PPEC_EXPRESSBUY_ROUTES_BASE_V2 . '/order/create') !== false){
        return false;
    }

	return $is_rest_api_request;
}

function ppec_expressbuyInitRestApi(){
  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE . '/expressbuy',
    '/callback',
    array(
      'methods'  => 'POST',
      'callback' => 'ppec_expressbuyCallback',
      'permission_callback' => '__return_true',
    )
  );

  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE,
    '/recon',
    array(
      'methods'  => 'GET',
      'callback' => 'ppec_startRecon',
      'permission_callback' => '__return_true',
    )
  );

  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE,
    '/order/create',
    array(
      'methods'  => 'POST',
      'callback' => 'ppec_createOrderV1',
      'permission_callback' => '__return_true',
    )
  );

  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE_V2,
    '/apply/coupon',
    array(
      'methods'  => 'POST',
      'callback' => 'ppec_applyCouponOnCartForPhonepeCheckout',
      'permission_callback' => '__return_true',
    )
  );

  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE,
    '/event',
    array(
      'methods'  => 'POST',
      'callback' => 'ppec_pushEventEndpoint',
      'permission_callback' => '__return_true',
    )
  );

  register_rest_route(
    PPEC_EXPRESSBUY_ROUTES_BASE,
    '/events',
    array(
        'methods'  => 'POST',
        'callback' => 'ppec_pushBatchEventEndpoint',
        'permission_callback' => '__return_true',
    )
  );


    register_rest_route(
        PPEC_EXPRESSBUY_ROUTES_BASE_V2,
        '/order/create',
        array(
            'methods'  => 'POST',
            'callback' => 'ppec_createOrderV2',
            'permission_callback' => '__return_true',
        )
    );
}

add_action('rest_api_init', 'ppec_expressbuyInitRestApi');

function ppec_initSessionAndCart()
{
    if (defined('WC_ABSPATH')) {
        // WC 3.6+ - Cart and other frontend functions are not included for REST requests.
        include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
        include_once WC_ABSPATH . 'includes/wc-notice-functions.php';
        include_once WC_ABSPATH . 'includes/wc-template-hooks.php';
    }

    if (null === WC()->session) {
        $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
        WC()->session  = new $session_class();
        WC()->session->init();
    }

    if (null === WC()->customer) {
        WC()->customer = new WC_Customer(get_current_user_id(), true);
    }

    if (null === WC()->cart) {
        WC()->cart = new WC_Cart();
        WC()->cart->get_cart();
    }
}
