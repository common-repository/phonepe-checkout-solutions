<?php


class PPEC_WC_Event extends PPEC_PhonepePluginEvent{
    public function __construct() {  
            $wc_phonepe = new WC_phonepe_expressbuy();
            $this->merchantId = $wc_phonepe->merchantIdentifier;
            $this->groupingKey = $wc_phonepe->merchantIdentifier;
            $this->pluginVersion = $wc_phonepe->pluginVersion;
            $this->platformVersion = WOOCOMMERCE_VERSION;
            $this->timestamp = time();
            $this->platform = 'woocommerce';
            $this->flowType = 'EXPRESS_BUY'; 
        }
    
    /**
     * Transforms array into object and returns it
     *
     * @return  self
     */ 
    public static function to_object(array $array) {
            $object = new PPEC_WC_Event();
            foreach ($array as $key => $value)
            {
                    // Add the value to the object
                    $object->{$key} = $value;
            }
            return $object;
    }
}



?>