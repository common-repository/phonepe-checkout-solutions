function ppec_disable_button_and_change_text(checkout_button){
  if(!checkout_button) return;
  checkout_button.attr('original-text', checkout_button.text());
  checkout_button.text('Loading..');
  checkout_button.addClass('disable-click');
}
  
function ppec_enable_button_and_reset_text(checkout_button){
  if(!checkout_button) return;
  checkout_button.removeClass('disable-click');
  checkout_button.text(checkout_button.attr('original-text'));
}

function ppec_show_processing_modal(ppec_context){
  console.log(ppec_context);
  var status_timeout = ppec_context.status_timeout;
  var merchant_store_name = ppec_context.merchant_store_name;
  document.getElementById('phonepe-body').innerHTML = 'We are confirming your order with ' + merchant_store_name;
  document.getElementById('phonepe-timer').innerHTML = status_timeout + ' minutes';
  document.getElementById('phonepe-processing-modal').style.display = 'block';
}

function ppec_start_timer(merchant_transaction_id, ppec_context) {
  var status_timeout = ppec_context.status_timeout;
  var merchant_store_url = ppec_context.merchant_store_url;
  status_timeout = status_timeout.split(':');
  var minutes = parseInt(status_timeout[0][0]) * 10 + parseInt(status_timeout[0][1]);
  var seconds = parseInt(status_timeout[1][0]) * 10 + parseInt(status_timeout[1][1])
  var duration = 60 * minutes + seconds;
  var timer_element = document.getElementById('phonepe-timer');
  var timer = duration;
  var timer_countdown = setInterval(function () {
    minutes = parseInt(timer / 60, 10);
    seconds = parseInt(timer % 60, 10);

    minutes = minutes < 10 ? '0' + minutes : minutes;
    seconds = seconds < 10 ? '0' + seconds : seconds;

    timer_element.textContent = minutes + ':' + seconds + ' minutes';
    if (--timer < 0) {
      clearInterval(timer_countdown);
      alert('It looks like we\'re taking a while to process your order. Please reach out to the merchant for order confirmation. Your order id is: ' + merchant_transaction_id);
      ppec_redirect_to(merchant_store_url);
    }
  }, 1000);
}