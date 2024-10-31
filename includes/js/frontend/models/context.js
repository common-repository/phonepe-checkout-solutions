class PPEC_Context{
    constructor({
        merchant_store_url,
        merchant_store_name,
        merchant_checkout_url,
        merchant_cart_url,
        status_timeout,
        user_id
    }){
        this.merchant_store_url = merchant_store_url;
        this.merchant_store_name = merchant_store_name;
        this.merchant_checkout_url = merchant_checkout_url;
        this.merchant_cart_url = merchant_cart_url;
        this.status_timeout = status_timeout;
        this.user_id = user_id;
        this.method = null;
        this.batched_events = [];
    }
    
    set_method(method) {
        this.method = method;
    }

    set_batched_events(batched_events){
        this.batched_events = batched_events;
    }
}