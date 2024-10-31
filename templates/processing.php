<style>
  .phonepe-header{
    margin: auto;
    font-family: 'Roboto';
    font-style: normal;
    font-weight: 700;
    padding-top: 50px;
    font-size: 20px;
    text-align: center;
    color: #212121;
  }

  .phonepe-body{
    margin: auto;
    font-family: 'Roboto';
    font-style: normal;
    font-weight: 400;
    width: 100%;
    font-size: 15px;
    padding-top: 7px;
    text-align: center;

    color: #212121;
  }

  .phonepe-timer{
    padding-top: 10px;
    padding-bottom: 10px;
    margin: auto;
    width: 80%;
    font-family: 'Roboto';
    font-style: normal;
    font-weight: 700;
    font-size: 17px;

    text-align: center;

    color: #20A15C;
  }

  .phonepe-footer{
    font-family: 'Roboto';
    font-style: normal;
    font-weight: 400;
    font-size: 14px;
    bottom: 30px;
    position: fixed;
    text-align: center;
    width: 90%;
    color: #616161;
  }

  .phonepe-lottie{
    margin: auto;
    padding-top: 10px;
    width: 140px;
    height: 150px;
  }

  .phonepe-processing-modal{
    display: none;
    position: fixed; /* Stay in place */
    z-index: 20000; /* Sit on top */
    left: 0;
    top: 0;
    width: 100%; /* Full width */
    height: 100%; /* Full height */
    overflow: hidden; /* Disable scroll */
    background-color: rgb(0,0,0); /* Fallback color */
    background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
  }

  .phonepe-processing-modal-content{
    background-color: #fefefe;
    border: 1px solid #888;
    width: 100vw;
    height: 100vh;
  }
  
</style>
<div class="phonepe-processing-modal" id="phonepe-processing-modal">
  <div class="phonepe-processing-modal-content" id="phonepe-processing-modal-content">
    <div style="margin: auto; width: 90%">
      <div class="phonepe-header"> Almost there! </div>
      <lottie-player src="<?php echo esc_url(plugins_url( 'includes/assets/images/light_mode_processing.json' , dirname(__FILE__ ))); ?>"  background="transparent"  speed="1" class="phonepe-lottie" loop autoplay></lottie-player>
      <div class="phonepe-body" id="phonepe-body"> We are confirming your order with  </div>
      <div class="phonepe-timer" id="phonepe-timer"></div>
      <div class="phonepe-footer">Taking you back to the merchant website shortly.</div>
    </div>
  </div>
</div>
