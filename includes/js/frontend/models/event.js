class PPEC_Event{
    constructor({
        eventType, 
        intentUrl=null, 
        merchantTransactionId=null, 
        transactionId=null, 
        method=null, 
        amount=null, 
        code=null, 
        message=null, 
        isCouponEnabled=null, 
        couponsCount=null, 
        couponCode=null, 
        couponDiscount=null
    }){
        this.eventType = eventType;
        this.intentUrl = intentUrl;
        this.merchantTransactionId = merchantTransactionId;
        this.transactionId = transactionId;
        this.method = method;
        this.amount = parseInt(amount);
        this.code = code;
        this.message = message;
        this.isCouponEnabled = isCouponEnabled;
        this.couponsCount = couponsCount;
        this.couponCode = couponCode;
        this.couponDiscount = couponDiscount;
        this.timestamp = Date.now();
        this.initialize_device_metadata();
    }

    initialize_property_from_cache(property){
        this[property] = JSON.parse(ls.get('ppecDeviceMeta'))[property];
    }

    initialize_device_metadata(){
        this.initialize_property_from_cache('network');
        this.initialize_property_from_cache('userOperatingSystem');
        this.initialize_property_from_cache('paymentRequestSupported');
        this.initialize_property_from_cache('canMakePayment');
        this.initialize_property_from_cache('hasEnrolledInstrument');
        this.initialize_property_from_cache('eligibility');
        this.initialize_property_from_cache('elapsedTime');
        this.pluginEnabled = "true";
        this.constraints = [];
    }

    toJSON(){
        return Object.getOwnPropertyNames(this).reduce((json, key) => {
            json[key] = this[key];
            return json;
        }, {});
    }
}