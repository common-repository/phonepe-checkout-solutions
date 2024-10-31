class PPEC_Order_Init_Response{
    constructor({
        merchantTransactionId,
        intentUrl,
        transactionId,
        isCouponEnabled,
        couponsCount,
        couponCode,
        couponDiscount,
        payableAmount
    }){
        this.merchant_transaction_id = merchantTransactionId;
        this.intent_url = intentUrl;
        this.transaction_id = transactionId;
        this.is_coupon_enabled = isCouponEnabled;
        this.coupons_count = couponsCount;
        this.coupon_code = couponCode;
        this.coupon_discount = couponDiscount;
        this.payable_amount = payableAmount;
    }
}