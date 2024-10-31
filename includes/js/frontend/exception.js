function PPEC_Exception(code, message, merchantTransactionId=null, transactionId=null){
    this.code = code;
    this.message = message;
    this.merchantTransactionId = merchantTransactionId;
    this.transactionId = transactionId;
}