function ppec_does_location_match_url(url){
    return window.location.href == url;
}

function ppec_publish_event(merchant_store_url, event){
    console.log(event);
    fetch(merchant_store_url + '/index.php/wp-json/wp-phonepe-expressbuy/v1/event', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(event)
    });
}

function ppec_publish_batched_events(merchant_store_url, events){
    console.log(events);
    fetch(merchant_store_url + '/index.php/wp-json/wp-phonepe-expressbuy/v1/events', {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(events)
    }).then(val => events = []);
}

function ppec_set_in_cache(key, value){
    sessionStorage.setItem(key, value);
}
  
function ppec_get_from_cache(key){
    return sessionStorage.getItem(key);
}

function ppec_get_from_cached_device_meta(key){
    var device_meta = JSON.parse(ls.get('ppecDeviceMeta'));
    console.log(device_meta);
    return device_meta[key];
}

function ppec_construct_wrapped_checkout_button_click_event(method) {
    return new PPEC_Event({
        eventType: 'WRAPPED_CHECKOUT_BUTTON_CLICKED',
        method: method
    });
}

function ppec_construct_displayed_wrapped_checkout_button_event(method){
    return new PPEC_Event({
        eventType: 'DISPLAY_WRAPPED_CHECKOUT_BUTTON',
        method: method,
    });
}

function ppec_construct_view_cart_event(method){
    return new PPEC_Event({
        eventType: 'VIEW_CART',
        method: method
    });
}

function ppec_construct_phonepe_checkout_init_event(method){
    return new PPEC_Event({
        eventType: 'PHONEPE_CHECKOUT_INIT', 
        method: method
      });
}

function ppec_construct_phonepe_checkout_failure_event(code, message, merchant_transaction_id=null, transaction_id=null){
    return new PPEC_Event({
        eventType: 'PHONEPE_CHECKOUT_FAILURE',
        code: code,
        message: message,
        merchantTransactionId: merchant_transaction_id,
        transactionId: transaction_id
      });
}

function ppec_construct_phonepe_checkout_failure_event_from_exception(ppec_exception){
    return ppec_construct_phonepe_checkout_failure_event(
        ppec_exception?.code, 
        ppec_exception?.message, 
        ppec_exception?.merchant_transaction_id,
        ppec_exception?.transaction_id
    );
}

function ppec_add_to_batched_events(event, batched_events){
    batched_events.push(event);
}

async function ppec_fetch_order_init_response(merchant_store_url, user_id){
    console.log('fetching intent..');
    var order_init_response = await fetch(merchant_store_url + '/index.php/wp-json/wp-phonepe-expressbuy/v2/order/create', {
        method: 'POST',
        body: JSON.stringify({
          'userId': user_id
        })
    });
    var order_init_decoded_response = await order_init_response.json();
    console.log('order init data: ');
    console.log(order_init_decoded_response);
    if(order_init_decoded_response['success'] == false) throw new PPEC_Exception('INTENT_GENERATION_FAILURE_' + order_init_decoded_response['errorCode'], order_init_decoded_response['errorMessage']); 
    return new PPEC_Order_Init_Response(order_init_decoded_response['data']);
}

function ppec_construct_phonepe_intent_init_event(order_init_response){
    return new PPEC_Event({
        eventType: 'PHONEPE_INTENT_INIT',
        merchantTransactionId: order_init_response?.merchant_transaction_id,
        intentUrl: order_init_response?.intent_url,
        transactionId: order_init_response?.transaction_id,
        isCouponEnabled: order_init_response?.is_coupon_enabled,
        couponsCount: order_init_response?.coupons_count,
        couponCode: order_init_response?.coupon_code,
        couponDiscount: order_init_response?.coupon_discount
    });
}

function ppec_construct_default_checkout_init_event(method){
    return new PPEC_Event({
        eventType: 'DEFAULT_CHECKOUT_INIT',
        method: method
    });
}

function ppec_wc_events_to_bind(){
    return [ 
        'wc_cart_emptied',
        'update_checkout',
        'updated_wc_div',
        'updated_cart_totals',
        'country_to_state_changed',
        'updated_shipping_method',
        'applied_coupon',
        'removed_coupon',
        'adding_to_cart',
        'added_to_cart', 
        'removed_from_cart', 
        'wc_cart_button_updated', 
        'cart_page_refreshed',
        'cart_totals_refreshed',
        'wc_fragments_loaded',
        'wc_fragments_refresh',
        'wc_fragments_refreshed'
    ];
}

function ppec_redirect_to(url){
    window.location.href = url;
}