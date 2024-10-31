jQuery(async function() {
  try{
    const ppec_payment_request_context = {
      constraints: []
    }
    await ppec_check_app_and_cache_device_meta(ppec_payment_request_context);
    var ppec_context = new PPEC_Context(context);
    if(!ppec_does_location_match_url(ppec_context.merchant_cart_url)){
      jQuery('a[href^="' + ppec_context.merchant_checkout_url +'"]').hide();
      ppec_wc_events_to_bind().forEach((val) => jQuery('body').on(val, () => 
        jQuery('a[href^="' + ppec_context.merchant_checkout_url +'"]').hide()));
      return;
    };
    ppec_context.set_method('CART');
    ppec_context.set_batched_events([]);
    ppec_publish_event(ppec_context.merchant_store_url, ppec_construct_view_cart_event(ppec_context.method));
   
    ppec_add_to_batched_events(ppec_construct_displayed_wrapped_checkout_button_event(ppec_context.method), ppec_context.batched_events);
    jQuery('body').on('click', 'a', async (e) => {
      try{
        if(jQuery(e.target).attr('href') != ppec_context.merchant_checkout_url) return;
        e.preventDefault();
        await ppec_checkout_button_listener(jQuery(e.target), ppec_context);
      }catch(error){
        if(!(error instanceof PPEC_Exception))
        error = new PPEC_Exception('JQUERY_ROOT_FAILURE', error?.stack?.toString() ?? error?.toString(), ppec_get_from_cache('merchantTransactionId'),  ppec_get_from_cache('transactionId'));
        ppec_add_to_batched_events(ppec_construct_phonepe_checkout_failure_event_from_exception(error), ppec_context?.batched_events ?? []);
        ppec_default_checkout(null, ppec_context ?? context);
      }
    })
  }catch(error){
    console.log(error);
    if(!(error instanceof PPEC_Exception))
      error = new PPEC_Exception('SCRIPT_ROOT_FAILURE', error?.stack?.toString() ?? error?.toString(), ppec_get_from_cache('merchantTransactionId'),  ppec_get_from_cache('transactionId'));
    ppec_add_to_batched_events(ppec_construct_phonepe_checkout_failure_event_from_exception(error), ppec_context?.batched_events ?? []);
    ppec_publish_batched_events(ppec_context?.merchant_store_url ?? context.merchant_store_url, ppec_context?.batched_events ?? [ppec_construct_phonepe_checkout_failure_event(error)]);
  }
});

async function ppec_checkout_button_listener(checkout_button, ppec_context){
  try{
    console.log('button listener');
    ppec_disable_button_and_change_text(checkout_button);
    ppec_add_to_batched_events(ppec_construct_wrapped_checkout_button_click_event(ppec_context.method), ppec_context.batched_events);
    ppec_get_from_cached_device_meta('eligibility') ? 
      await ppec_fetch_intent_and_open_app(checkout_button, ppec_context)
      :
      ppec_default_checkout(checkout_button, ppec_context);
  }catch(error){
    if(error instanceof PPEC_Exception) throw error;
    throw new PPEC_Exception('CHECKOUT_BUTTON_LISTENER_FAILURE', error?.stack?.toString() ?? error?.toString(), ppec_get_from_cache('merchantTransactionId'),  ppec_get_from_cache('transactionId'));
  }
}

function ppec_default_checkout(checkout_button, ppec_context){
  ppec_add_to_batched_events(ppec_construct_default_checkout_init_event(ppec_context?.method), ppec_context?.batched_events ?? []);
  ppec_publish_batched_events(ppec_context?.merchant_store_url ?? '', ppec_context?.batched_events ?? []);
  ppec_enable_button_and_reset_text(checkout_button);
  ppec_redirect_to(ppec_context?.merchant_checkout_url ?? '/');
}

async function ppec_fetch_intent_and_open_app(checkout_button, ppec_context) {
  try{
    console.log('trying to open app');
    ppec_add_to_batched_events(ppec_construct_phonepe_checkout_init_event(ppec_context.method), ppec_context.batched_events);
    console.log('fetching intent');
    var order_init_response = await ppec_fetch_order_init_response(ppec_context.merchant_store_url, ppec_context.user_id);
    ppec_add_to_batched_events(ppec_construct_phonepe_intent_init_event(order_init_response), ppec_context.batched_events);
  
    ppec_set_in_cache('merchantTransactionId', order_init_response?.merchant_transaction_id);
    ppec_set_in_cache('transactionId', order_init_response?.transaction_id);
  
    phonepe_payment_request = ppec_create_payment_request({url: order_init_response?.intent_url, constraints: []}, order_init_response?.payable_amount);
    var response = await phonepe_payment_request.show();
    await response.complete('success');
    var merchant_transaction_id = JSON.parse(response['details']['result'])['merchantTransactionId'] ?? ppec_get_from_cache('merchantTransactionId');
    ppec_enable_button_and_reset_text(checkout_button);
    ppec_publish_batched_events(ppec_context.merchant_store_url, ppec_context.batched_events);
    ppec_show_processing_modal(ppec_context);
    console.log(response);
    ppec_start_recon(merchant_transaction_id, ppec_context);
  }catch(error){
    console.log(error);
    console.log(error.stack);
    if(error instanceof PPEC_Exception) throw error;
    throw new PPEC_Exception('FETCH_INTENT_APP_OPEN_FAILURE', error?.stack?.toString() ?? error?.toString(), ppec_get_from_cache('merchantTransactionId'),  ppec_get_from_cache('transactionId'));
  }
}

function ppec_start_recon(merchant_transaction_id, ppec_context) {
  console.log(ppec_context);
  ppec_start_timer(merchant_transaction_id, ppec_context);
  fetch(ppec_context.merchant_store_url + '/index.php/wp-json/wp-phonepe-expressbuy/v1/recon?merchantTransactionId=' + merchant_transaction_id)
    .then(response => response.json())
    .then(status_check_data => {
      if(status_check_data['success'] && status_check_data['redirectUrl'])
        ppec_redirect_to(status_check_data['redirectUrl']);  
      else{
        alert(status_check_data['message']);
        ppec_publish_event(ppec_context.merchant_store_url, ppec_construct_phonepe_checkout_failure_event('CHECK_STATUS_FAILURE', status_check_data['message'].toString(), merchant_transaction_id));
        ppec_redirect_to(ppec_context.merchant_store_url);
      }
    })
    .catch(error => { 
      console.log(error.stack);
      alert('An error occurred. Please reach out to the merchant for more details. Your order id is: ' + merchant_transaction_id);
      ppec_publish_event(ppec_context.merchant_store_url, ppec_construct_phonepe_checkout_failure_event('PROCESSING_SCREEN_FAILURE', error?.stack?.toString() ?? error?.toString(), merchant_transaction_id));
      ppec_redirect_to(ppec_context.merchant_store_url);
  });
};