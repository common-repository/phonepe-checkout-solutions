<?php

function ppec_validateEventMandatoryParams($eventData){
  return true;
}

function ppec_pushEventEndpoint(){
  $requestPayload = file_get_contents('php://input');
  $event_data = json_decode($requestPayload, true);
  $ppec_event = PPEC_WC_Event::to_object($event_data);
  // $event_data = $ppec_event->to_array();
  ppec_pushEvent($ppec_event);
}

function ppec_pushBatchEventEndpoint(){
  $requestPayload = file_get_contents('php://input');
  $batch_events = json_decode($requestPayload, true);
  $ppec_events = array();
  foreach ($batch_events as $batch_event){
    $ppec_event = PPEC_WC_Event::to_object($batch_event);
    array_push($ppec_events, $ppec_event);
  }
  ppec_pushBatchEvents($ppec_events);
}

function ppec_pushEvent($ppec_event){
  $wc_phonepe = new WC_phonepe_expressbuy();
  $merchant_id = $wc_phonepe->merchantIdentifier;
  $merchant_salt_key = $wc_phonepe->saltKey;
  $merchant_salt_index = $wc_phonepe->saltKeyIndex;
  $environment = $wc_phonepe->hostEnv;
  $debug_url = $wc_phonepe->debug_url;
  $plugin_version = $wc_phonepe->pluginVersion;
  ppec_validateEventMandatoryParams($ppec_event);
  PPEC_ExpressbuyApi::pushEvent($ppec_event, $merchant_id, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
}

function ppec_pushBatchEvents($ppec_events){
  $wc_phonepe = new WC_phonepe_expressbuy();
  $merchant_id = $wc_phonepe->merchantIdentifier;
  $merchant_salt_key = $wc_phonepe->saltKey;
  $merchant_salt_index = $wc_phonepe->saltKeyIndex;
  $environment = $wc_phonepe->hostEnv;
  $debug_url = $wc_phonepe->debug_url;
  $plugin_version = $wc_phonepe->pluginVersion;

  ppec_validateEventMandatoryParams($ppec_events);
  PPEC_ExpressbuyApi::pushBatchEvents($ppec_events, $merchant_id, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
}
