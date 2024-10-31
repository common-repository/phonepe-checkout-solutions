<?php
if(!defined('PPEC_COMMON_ROOT'))
  define('PPEC_COMMON_ROOT', dirname(__FILE__));

require_once(PPEC_COMMON_ROOT . '/ChecksumUtils.php');
require_once(PPEC_COMMON_ROOT . '/PhonepeConfig.php');

class PPEC_ExpressbuyApi{

  private static function getBaseUrl($environment){
    switch($environment){
      case "STAGE_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PPBASE_URL_STAGE;
      case "UAT_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PPBASE_URL_UAT;
      case "PROD_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PPBASE_URL_PROD;
      default: return PPEC_PhonepeConstantsPG::PPBASE_URL_STAGE;
    }
  }

  private static function getBaseEventsUrl($environment){
      switch ($environment){
          case "PROD_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PPBASE_URL_PROD_EVENTS;
          default: return PPEC_PhonepeConstantsPG::PPBASE_URL_STAGE_EVENTS;
      }
  }

  private static function encodePayload($payload){
	  return json_encode(array(
		  "request" => base64_encode(json_encode($payload))
	  ));
  }

  public static function getPaymentRequestUrl($environment){
    switch($environment){
      case "STAGE_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PAYMENTREQUEST_URL_STAGE;
      case "UAT_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PAYMENTREQUEST_URL_UAT;
      case "PROD_ENVIRONMENT": return PPEC_PhonepeConstantsPG::PAYMENTREQUEST_URL_PROD;
      default: return PPEC_PhonepeConstantsPG::PAYMENTREQUEST_URL_STAGE;
    }
  }

  private static function generateChecksumHeaders($payload, $endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion){
    $encoded_payload = $payload != "" ? base64_encode(json_encode($payload)) : "";
    return array(
      'Content-Type' =>  'application/json',
      'Accept' => 'application/json',
      'X-SOURCE' => 'plugin',
      'X-SOURCE-PLATFORM' => $platform,
      'X-SOURCE-PLATFORM-VERSION' => $platformVersion,
      'X-SOURCE-VERSION' => $pluginVersion,
      'X-VERIFY' => PPEC_PhonepeChecksumPG::phonepeExpressBuyCalculateChecksum($encoded_payload, $endpoint, $merchant_key, $key_index)
    );
  }

  public static function sendDebugResponse($data, $debug_url=""){
    return;
  }

  public static function updateCodConfig($cod_config, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion, $debug_url=""){
    $cod_config_endpoint = "/express-buy/v1/configs/cod";
    $base_url = self::getBaseUrl($environment);
    $headers = self::generateChecksumHeaders($cod_config, $cod_config_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion);

    $apiRequestObj = new PPEC_ApiRequest($base_url . $cod_config_endpoint, $headers, self::encodePayload($cod_config));
    $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);

    $max_retries = 5;
    $http_response_code = $apiResponseObj->get_http_code();
    while($http_response_code >= 500 && $http_response_code <= 599 && $max_retries--) {
      $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);
      $http_response_code = $apiResponseObj->get_http_code();
    }

    if($debug_url != "") self::sendDebugResponse(json_encode(["headers" => $headers, "data" => $cod_config, "output" => $apiResponseObj->get_data()]), $debug_url);
	  $server_output = json_decode($apiResponseObj->get_data(), true);

    if($server_output == null || !isset($server_output['success']))
      throw new Exception("Something went wrong, please try again later.");

    if($server_output['success'])
      return $server_output['data'];
    else if(isset($server_output['message']))
      throw new Exception($server_output['message']);
    else throw new Exception('Something went wrong. Please try again later.' . json_encode($server_output));
  }

  public static function updateShippingConfig($shipping_config, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion , $debug_url=""){
    $shipping_config_endpoint = "/express-buy/v1/configs/shipping";
    $base_url = self::getBaseUrl($environment);
    $headers = self::generateChecksumHeaders($shipping_config, $shipping_config_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion);

	  $apiRequestObj = new PPEC_ApiRequest($base_url . $shipping_config_endpoint, $headers, self::encodePayload($shipping_config));
	  $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);

    $max_retries = 5;
    $http_response_code = $apiResponseObj->get_http_code();
    while($http_response_code >= 500 && $http_response_code <= 599 && $max_retries--) {
		  $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);
		  $http_response_code = $apiResponseObj->get_http_code();
    }

    if($debug_url != "") self::sendDebugResponse(json_encode(["headers" => $headers, "data" => $shipping_config, "output" => $apiResponseObj->get_data()]), $debug_url);
    $server_output = json_decode($apiResponseObj->get_data(), true);

    if($server_output == null || !isset($server_output['success']))
      throw new Exception('Something went wrong, please try again later.');

    if($server_output['success'])
      return $server_output['data'];
    else if(isset($server_output['message']))
      throw new Exception($server_output['message']);
    else throw new Exception('Something went wrong. Please try again later.' . json_encode($server_output));
  }

  public static function orderInit($order_init_data, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion, $debug_url=""){
    $order_init_endpoint = "/express-buy/v1/order/init";
    $base_url = self::getBaseUrl($environment);
    $headers = self::generateChecksumHeaders($order_init_data, $order_init_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion);

	  $apiRequestObj = new PPEC_ApiRequest($base_url . $order_init_endpoint, $headers, self::encodePayload($order_init_data));
	  $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);

    if($debug_url != "") self::sendDebugResponse(json_encode(["headers" => $headers, "data" => $order_init_data, "output" => $apiResponseObj->get_data()]), $debug_url);
	  $server_output = json_decode($apiResponseObj->get_data(), true);

    if($server_output == null || !isset($server_output['success']))
      throw new Exception("Something went wrong, please try again later.");

    if($server_output['success'])
      return $server_output['data'];
    else if(isset($server_output['message']))
      throw new Exception($server_output['message']);
    else throw new Exception('Something went wrong. Please try again later.' . json_encode($server_output));
  }

  public static function checkStatus($merchant_id, $merchant_transaction_id, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion, $debug_url){

    $check_status_endpoint = "/express-buy/v1/order/status/" . $merchant_id . "/" . $merchant_transaction_id;
    $base_url = self::getBaseUrl($environment);
    $headers = self::generateChecksumHeaders("", $check_status_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion); // GET request checksum will have an empty payload

	  $headers = wp_parse_args( $headers, array(
		'X-CLIENT-ID' => $merchant_id
	  ));

	  $apiRequestObj = new PPEC_ApiRequest($base_url . $check_status_endpoint, $headers);
	  $apiResponseObj = PPEC_HttpClient::get($apiRequestObj);

    $max_retries = 5;
    $http_response_code = $apiResponseObj->get_http_code();

    while($http_response_code >= 500 && $http_response_code <= 599 && $max_retries--){
	    $apiResponseObj = PPEC_HttpClient::get($apiRequestObj);
      $http_response_code = $apiResponseObj->get_http_code();
    }

    if($debug_url != "") self::sendDebugResponse(json_encode(["headers" => $headers, "endpoint" => $check_status_endpoint, "output" => $apiResponseObj->get_data()]), $debug_url);
	  $server_output = json_decode($apiResponseObj->get_data(), true);

    if($server_output == null || !isset($server_output['success']))
      throw new Exception("Something went wrong, please try again later.");

    if(($server_output['code'] == 'SUCCESS' || $server_output['code'] == 'PENDING' || $server_output['code'] == 'FAILED') && $server_output['data'])
      return $server_output['data'];
    else throw new Exception('Something went wrong. Please try again later.' . json_encode($server_output));
  }

  /*
  * verifies checksum and decodes the payload
  */
  public static function handleCallback($payload, $headers, $merchant_key, $key_index){
    $checksum = $headers["Verify"];
    if($headers["Salt-Index"] != "") // authmode is off - hermes callbacks
      $checksum = $checksum ."###" . $headers["Salt-Index"];

    $decoded_payload = json_decode($payload, true);
    $data = base64_decode($decoded_payload["response"]);
    $data = json_decode($data, true);
    $generated_checksum = PPEC_PhonepeChecksumPG::phonepeExpressBuyCalculateChecksum($decoded_payload['response'], "", $merchant_key, $key_index);
    PPEC_ExpressbuyApi::sendDebugResponse(json_encode([
      $data, $checksum, $generated_checksum
    ], JSON_PRETTY_PRINT));
    if($checksum != $generated_checksum) return null;
    return $data['data'];
  }
  
  public static function pushEvent($ppec_event, $merchant_id, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion, $debug_url){
    $event_data = $ppec_event->to_array();

    $event_endpoint = "/plugin/ingest-event";
    $base_url = self::getBaseUrl($environment);
	  $headers = self::generateChecksumHeaders($event_data, $event_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion);

	  $apiRequestObj = new PPEC_ApiRequest($base_url . $event_endpoint, $headers, self::encodePayload($event_data));
    $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);

	  $max_retries = 5;
    $http_response_code = $apiResponseObj->get_http_code();

    while($http_response_code >= 500 && $http_response_code <= 599 && $max_retries--) {
	  $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);
      $http_response_code = $apiResponseObj->get_http_code();
    }
  }

  public static function pushBatchEvents($ppec_events, $merchant_id, $merchant_key, $key_index, $environment, $platform, $platformVersion, $pluginVersion, $debug_url){
    $batch_events = array();
    foreach ($ppec_events as $ppec_event){
      $batch_event_data = $ppec_event->to_array();
      array_push($batch_events, $batch_event_data);
    }
    $batch_events_data['merchantId'] = $merchant_id;
    $batch_events_data['events'] = $batch_events;

    $event_endpoint = "/plugin/events";
    $base_url = self::getBaseEventsUrl($environment);
    $headers = self::generateChecksumHeaders($batch_events_data, $event_endpoint, $merchant_key, $key_index, $platform, $platformVersion, $pluginVersion);

    $apiRequestObj = new PPEC_ApiRequest($base_url . $event_endpoint, $headers, self::encodePayload($batch_events_data));
    $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);

    $max_retries = 5;
    $http_response_code = $apiResponseObj->get_http_code();

    while($http_response_code >= 500 && $http_response_code <= 599 && $max_retries--){
      $apiResponseObj = PPEC_HttpClient::post($apiRequestObj);
      $http_response_code = $apiResponseObj->get_http_code();
    }
  }
}