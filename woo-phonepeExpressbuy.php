<?php 
require_once __DIR__ . '/includes/common/PhonepeConfig.php';
require_once __DIR__ . '/includes/common/HttpClient.php';
require_once __DIR__ . '/includes/common/ApiRequest.php';
require_once __DIR__ . '/includes/common/ApiResponse.php';
require_once __DIR__ . '/includes/common/ExpressbuyApi.php';
require_once __DIR__ . '/includes/common/PhonepePluginEvent.php';
require_once __DIR__ . '/includes/common/Exceptions/PhonepeException.php';
require_once __DIR__ . '/includes/common/Exceptions/ValidationException.php';
require_once __DIR__ . '/includes/common/Exceptions/CouponValidationException.php';
require_once __DIR__ . '/includes/common/Exceptions/RedundantCallbackException.php';
require_once __DIR__ . '/includes/api/api.php';
require_once __DIR__ . '/includes/api/order.php';
require_once __DIR__ . '/includes/api/order-status.php';
require_once __DIR__ . '/includes/api/event.php';
require_once __DIR__ . '/includes/api/apply-coupon.php';
require_once __DIR__.'/includes/api/cart.php';
require_once __DIR__.'/includes/api/PPEC_WC_Event.php';

define('PPEC_PLATFORM', 'woocommerce');

$woocommerce_express_buy_configs_json = file_get_contents(__DIR__ . '/config.json');
$woocommerce_express_buy_configs = json_decode($woocommerce_express_buy_configs_json, true);

$PHONEPE_EXPRESSBUY_DEBUG_MODE = $woocommerce_express_buy_configs['debug'];
$PHONEPE_EXPRESS_BUY_VERSION = $woocommerce_express_buy_configs['major'] . '.'
                            . $woocommerce_express_buy_configs['minor'] . '.'
                            . $woocommerce_express_buy_configs['patch'];
if($woocommerce_express_buy_configs['snapshot'])
    $PHONEPE_EXPRESS_BUY_VERSION .= '-snapshot';
$PHONEPE_EXPRESS_BUY_CURRENT_ENVIRONMENT = $woocommerce_express_buy_configs['environment'];

if(session_status() == PHP_SESSION_NONE) {
    session_start([
	    'read_and_close' => true,
    ]);
}
/**
 * Plugin Name: PhonePe Checkout Solutions
 * Plugin URI: https://github.com/PhonePe/
 * Description: PhonePe Checkout Integration for WooCommerce
 * Version: 1.2.0
 * Requires PHP: 5.6
 * "namespaces": [ "phonepe-expressbuy/v1",]
 */

add_action('plugins_loaded', 'ppec_woocommerce_phonepe_expressbuy_init', 0);

if (!defined('ABSPATH')){
  exit; // Exit if accessed directly
}

function ppec_woocommerce_phonepe_expressbuy_init(){

    if (!class_exists('WC_Payment_Gateway')) return;

    class WC_phonepe_expressbuy extends WC_Payment_Gateway{
        protected $msg = array();

        public function __construct(){
            global $PHONEPE_EXPRESS_BUY_CURRENT_ENVIRONMENT;
            global $PHONEPE_EXPRESS_BUY_VERSION;

            $this->id = 'phonepe_expressbuy';
            $this->method_title = __('PhonePe Checkout Solutions');
            $this->icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/includes/assets/images/156pxv2.jpg';
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            $this->title = '';
            $this->method_description = 'PhonePe Checkout Integration for WooCommerce';
            $this->merchantIdentifier = sanitize_text_field($this->settings['merchantIdentifier']);
            $this->saltKey = sanitize_text_field($this->settings['saltKey']);
            $this->saltKeyIndex = sanitize_text_field($this->settings['Index']);
            $this->hostEnv = $PHONEPE_EXPRESS_BUY_CURRENT_ENVIRONMENT;
            $this->pluginVersion = $PHONEPE_EXPRESS_BUY_VERSION;
            $this->enabled = $this->settings['enabled'];
            $this->timeout = sanitize_text_field($this->settings['timeout']);
            $this->debug_url = "";
            $this->cod_config = isset($this->settings['cod_config']) ? $this->settings['cod_config'] : 'default_wc_cod';
            $this->pan_india_cod_charges = isset($this->settings['pan_india_cod_charges']) ? $this->settings['pan_india_cod_charges'] : 0;
            $this->msg['message'] = "";
            $this->msg['class'] = "";


            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
            } else {
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }
        }

        function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('PhonePe Checkout'),
                    'type' => 'checkbox',
                    'label' => __('Enable this to let users checkout with PhonePe!'),
                    'default' => 'yes'
                ),
                'merchantIdentifier' => array(
                    'title' => __('Merchant ID'),
                    'type' => 'text',
                    'description' => __('Merchant ID Provided by PhonePe'),
                    'desc_tip' => true,
                ),
                'saltKey' => array(
                    'title' => __('Salt Key'),
                    'type' => 'text',
                    'description' => __('Salt Key Provided by PhonePe'),
                    'desc_tip' => true,
                ),
                'Index' => array(
                    'title' => __('Salt Key Index'),
                    'type' => 'text',
                    'description' => __('Salt Key Index Provided by PhonePe'),
                    'desc_tip' => true,
                ),
                'timeout' => array(
                    'title' => __('Timeout for PhonePe Checkout'),
                    'type' => 'text',
                    'description' => __('Set Timeout for PhonePe Checkout'),
                    'desc_tip' => true,
                    'default' => '05:00'
                ),
                'cod_config' => array(
                    'title' => __('COD Settings'),
                    'id' => 'cod_config',
                    'default'  => 'default_wc_cod',
                    'type' => 'select',
                    'options' => array(
                        'default_wc_cod' => __('Default - WooCommerce COD'),
                        'pan_india_cod' => __('Custom - PAN India COD with charges'),
                    ),
                    'description' => __('Choose Default to select WooCommerce COD settings. Choose Custom to set COD charges for PAN India.'),
                    'desc_tip' => true,
                ),
                'pan_india_cod_charges'=> array(
                    'title' => __('PAN India COD Charges (in  ₹)'),
                    'id' => __('pan_india_cod_charges'),
                    'type' => 'number',
                    'description' => __('Enter additional charges for COD Payment Mode'),
                    'custom_attributes' => array(
                      'min'  => 1,
                      'step' => 0.01,
                    ),
                    'desc_tip' => true,
                )
            );
        }

        public function admin_options(){
            parent::admin_options();
            ppec_update_shipping_config();
        }
    }
    add_action('admin_enqueue_scripts', 'enqueue_script_for_phonepe_admin_screen');

    function enqueue_script_for_phonepe_admin_screen(){
      wp_register_script('phonepe_cod_settings', plugin_dir_url(__FILE__) . '/includes/js/admin/settings.js', null, null);
      wp_enqueue_script('phonepe_cod_settings');
    }

    /*
     * To create shortcut to PhonePe plugin specific settings for marchants 
     */

    function expressbuy_settings_link($links){
        // Build and escape the URL.
        $url = esc_url( add_query_arg(
            'page',
            'wc-settings',
            get_admin_url() . 'admin.php?&tab=checkout&section=phonepe_expressbuy'
        ) );
        // Create the link.
        $settings_link = "<a href='$url'>" . __( 'Settings' ) . '</a>';
        // Adds the link to the end of the array.
        array_push(
            $links,
            $settings_link
        );
        return $links;
    }

    add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'expressbuy_settings_link' );

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_phonepe_expressbuy_gateway($methods) {
        $methods[] = 'WC_phonepe_expressbuy';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_phonepe_expressbuy_gateway');


    /**
     * This removes the phonepe_expressbuy PG option from the list of payment methods on the checkout page
     */
    add_filter('woocommerce_available_payment_gateways', 'filter_gateways', 1);
    function filter_gateways($gateways) {
        unset($gateways['phonepe_expressbuy']);
        return $gateways;
    }

    /**
     * actions for shipping
     * these are triggers for when shipping settings are changed
     * the function ppec_update_shipping_config is the callback
     */
    add_action('woocommerce_after_shipping_zone_object_save', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_shipping_zone_method_added', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_shipping_zone_method_deleted', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_shipping_zone_method_status_toggled', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_shipping_zones_save_changes', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_shipping_zone_add_method', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_delete_shipping_zone', 'ppec_update_shipping_config', 11);
    add_action('woocommerce_update_options_shipping_flat_rate', 'ppec_update_shipping_config', 11);

    /**
     * converts stdClass properties for a shipping method to an associative array so that it can be json encoded
     * called from `get_data_for_all_shipping_methods`
     */
    function ppec_get_settings_for_method($method_obj) {
        $method_obj->init_instance_settings();
        $settings = array();
        foreach ($method_obj->get_instance_form_fields() as $id => $field) {
            try {
                $data = array(
                    'id' => $id,
                    'type' => $field['type'],
                    'value' => $method_obj->instance_settings[$id],
                    'default' => empty($field['default']) ? '' : $field['default'],
                );
                if (!empty($field['options'])) {
                    $data['options'] = $field['options'];
                }
                $settings[$id] = $data;
            } catch (Exception $e) {
                $ppec_failure_event = new PPEC_WC_Event();
                $ppec_failure_event->set_eventType('GET_SETTINGS_FOR_SHIPPING_METHOD');
                $ppec_failure_event->set_state('FAILURE');
                $ppec_failure_event->set_method($id);
                $ppec_failure_event->set_code($e->getCode());
                $ppec_failure_event->set_message($e->getTraceAsString());
                ppec_pushEvent($ppec_failure_event);
                continue;
            }
        }
        return $settings;
    }


    /**
     * converts the wc shipping type to corresponding enum values that phonepe systems understand
     */

    function ppec_get_shipping_type($type) {
        switch ($type) {
            case 'flat_rate':
                return 'FLAT_RATE_SHIPPING';
            case 'free_shipping':
                return 'FREE_SHIPPING';
        }
    }

    function ppec_convert_to_paisa($price) {
        if (is_string($price)) {
            $price = (double)$price;
        }
        return $price * 100;
    }

    /**
     * converts stdClass properties for all shipping methods into an associative array according to the contract
     * called from `ppec_update_shipping_config`
     */

    function ppec_get_data_from_all_shipping_methods($all_shipping_methods) {
        foreach ($all_shipping_methods as $method_obj) {
            $settings = ppec_get_settings_for_method($method_obj);
            $type = ppec_get_shipping_type($method_obj->id);
            $charges = isset($settings["cost"]) ? ppec_convert_to_paisa($settings["cost"]["value"]) : 0;
            $min_cart_value = isset($settings["min_amount"]) ? ppec_convert_to_paisa($settings["min_amount"]["value"]) : 0;

            $method = array(
                'id' => $method_obj->instance_id,
                'name' => $method_obj->instance_settings['title'],
                'enabled' => ('yes' === $method_obj->enabled),
                'type' => $type,
                'charges' => $charges,
                'minCartValue' => $min_cart_value
            );
            /**
             * edge case
             * when a flat rate method is configured, it defaults to 0 rupees as the flat rate
             * the callback is triggered, and phonepe returns an error because 0 rupees is being sent as flat rate
             * hence, we skip that config
             *
             * or if free shipping is set to be enabled only when a valid free shipping coupon is entered
             * we skip that config
             */
            $is_free_shipping_coupon_conditional = isset($settings["requires"]) ? ($settings["requires"]["value"] == "coupon" || $settings["requires"]["value"] == "both") : false;
            if (($type == 'FLAT_RATE_SHIPPING' && $charges == 0) || $is_free_shipping_coupon_conditional) {
                $method['enabled'] = false;
                unset($method['charges']);
                unset($method['minCartValue']);
            }

            $methods[] = $method;
        }
        return isset($methods) ? $methods : [];
    }

    /**
     * converts the wc area type to corresponding enum values that phonepe systems understand
     */

    function ppec_get_area_type($location_type) {
        switch ($location_type) {
            case "state":
                return "REGION";
            case "country":
                return "COUNTRY";
            case "postcode":
                return "PIN_CODE";
        }
    }

    /**
     * validates that locations are indian
     */

    function ppec_validate_indian_location($location_code, $location_type) {
        switch ($location_type) {
            case "state":
                if (substr($location_code, 0, 2) != "IN") // is of the form "IN:**", e.g "IN:MH"
                    return false;
                break;

            case "country":
                if ($location_code != "IN")
                    return false;
                break;

            case "postcode":
                // reference: https://stackoverflow.com/questions/33865525/indian-pincode-validation-regex-only-six-digits-shouldnt-start-with-0
                if (!preg_match('/^[1-9][0-9]{5}$/', $location_code))
                    return false;
                break;

            default:
                return false;
        }
        return true;
    }

    /**
     * gets the corresponding state key
     * wc state keys and phonepe's state keys are mapped in includes/assets/mapping.json
     */

    function ppec_get_key($location_code, $location_type, $state_code_mapping) {
        switch ($location_type) {
            case "state":
                $wc_state_key = substr($location_code, 3); // is of the form "IN:**", e.g "IN:MH"
                return $state_code_mapping[$wc_state_key];

            case "country":
            case "postcode":
                return $location_code;
        }
    }

    /**
     * converts an associative array(map) to an array
     */
    function ppec_convert_map_to_list($map) {
        if ($map == null) return null;
        foreach ($map as $key => $value)
            $list[] = $value;
        return $list;
    }

    /**
     * the callback for all the shipping actions
     * this function gets ALL SHIPPING CONFIGURATIONS and sends it to phonepe
     * since we follow a replace config model, with every push, the older shipping config gets overwritten i.e.
     * there is no need to maintain a history of which config was enabled before and has now been disabled
     */
    function ppec_update_shipping_config(){
        $wc_phonepe = new WC_phonepe_expressbuy();
        $merchant_id = $wc_phonepe->merchantIdentifier;
        $merchant_salt_key = $wc_phonepe->saltKey;
        $merchant_salt_index = $wc_phonepe->saltKeyIndex;
        $environment = $wc_phonepe->hostEnv;
        $debug_url = $wc_phonepe->debug_url;
        $plugin_version = $wc_phonepe->pluginVersion;

        $src = __DIR__ . '/includes/assets/mapping.json';
        $state_code_mapping = json_decode(file_get_contents($src), true);
        $all_zones = WC_Shipping_Zones::get_zones();

        foreach ($all_zones as $zone_obj) {
            $zone = WC_Shipping_Zones::get_zone((int)$zone_obj['id']);
            $locations_in_zone = $zone->get_data();
            $all_shipping_methods = $zone->get_shipping_methods();
            if ($all_shipping_methods == []) continue;

            $shipping_methods = ppec_get_data_from_all_shipping_methods($all_shipping_methods);

            if ($shipping_methods == []) continue;

            foreach ($locations_in_zone["zone_locations"] as $location) {
                if (!ppec_validate_indian_location($location->code, $location->type)) continue;

                $area_type = ppec_get_area_type($location->type);
                $key = ppec_get_key($location->code, $location->type, $state_code_mapping);
                $config = $shipping_methods; // a list of shipping methods, even if only one shipping method is configured

                // merge configurations for a region overlapping in multiple zones
                if (isset($shipping_configs[$key])) {
                    // run this for loop to avoid a list of shipping methods inside the original list of shipping methods
                    foreach ($config as $c)
                        array_push($shipping_configs[$key]["config"], $c);
                    continue;
                }

                // maintain shipping_configs as a map for regions in overlapping zones
                $shipping_configs[$key] = array(
                    "areaType" => $area_type,
                    "key" => $key,
                    "config" => $config
                );
            }
        }
        $shipping_configs = ppec_convert_map_to_list(isset($shipping_configs) ? $shipping_configs : null);

        $shipping_payload = array(
            "merchantId" => $merchant_id,
            "shippingConfigs" => $shipping_configs
        );

        try {
            PPEC_ExpressbuyApi::updateShippingConfig($shipping_payload, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
            ppec_update_cod_config(); // if shipping configs change, COD configs would also change
        } catch (Exception $e) {
            $ppec_failure_event = new PPEC_WC_Event();
            $ppec_failure_event->set_eventType('SHIPPING_CONFIG_UPDATE');
            $ppec_failure_event->set_state('FAILURE');
            $ppec_failure_event->set_code($e->getCode());
            $ppec_failure_event->set_message($e->getTraceAsString());
            ppec_pushEvent($ppec_failure_event);
        }
    }

    /**
     * actions for cod
     * these are triggers for when cod settings are changed
     * the function ppec_update_cod_config is the callback
     */

    add_action('woocommerce_update_options_checkout_cod', 'ppec_update_cod_config');

    function ppec_get_settings_for_gateway($gateway_obj) {
        $settings = array();
        $gateway_obj->init_form_fields();
        foreach ($gateway_obj->form_fields as $id => $field) {
            // Make sure we at least have a title and type.
            if (empty($field['title']) || empty($field['type'])) {
                continue;
            }

            // Ignore 'title' settings/fields -- they are UI only.
            if ('title' === $field['type']) {
                continue;
            }

            // Ignore 'enabled' and 'description' which get included elsewhere.
            if (in_array($id, array('enabled', 'description'), true)) {
                continue;
            }

            $data = array(
                'id' => $id,
                'value' => empty($gateway_obj->settings[$id]) ? '' : $gateway_obj->settings[$id],
                'default' => empty($field['default']) ? '' : $field['default']
            );
            if (!empty($field['options'])) {
                $data['options'] = $field['options'];
            }
            $settings[$id] = $data;
        }
        return $settings;
    }

    function ppec_get_payment_gateway_information($gateway_obj) {
        $gateway = array(
            'enabled' => ('yes' === $gateway_obj->enabled),
            'values' => ppec_get_settings_for_gateway($gateway_obj)['enable_for_methods']['value'],
        );
        return $gateway;
    }

    function ppec_starts_with ($string, $start_string) {
        $len = strlen($start_string);
        return (substr($string, 0, $len) === $start_string);
    }

    /**
     * the callback for the cod action
     * this function gets ALL ENABLED COD CONFIGURATIONS and sends it to phonepe
     * since we follow a replace config model, with every push, the older cod config gets overwritten i.e.
     * there is no need to maintain a history of which config was enabled before and has now been disabled
     *
     *
     * DON'T TRY TO REFACTOR THE CODE IN THIS FUNCTION.
     */

    function ppec_update_cod_config(){
        $wc_phonepe = new WC_phonepe_expressbuy();
        $merchant_id = $wc_phonepe->merchantIdentifier;
        $merchant_salt_key = $wc_phonepe->saltKey;
        $merchant_salt_index = $wc_phonepe->saltKeyIndex;
        $environment = $wc_phonepe->hostEnv;
        $debug_url = $wc_phonepe->debug_url;
        $plugin_version = $wc_phonepe->pluginVersion;

        if($wc_phonepe->cod_config == 'default_wc_cod'){
            $src = __DIR__ . '/includes/assets/mapping.json';
            $state_code_mapping = json_decode(file_get_contents($src), true);

            $gateway = null;
            $payment_gateways = WC()->payment_gateways->payment_gateways();

            foreach ($payment_gateways as $payment_gateway_id => $payment_gateway) {
                if ('cod' == $payment_gateway_id) {
                $gateway = $payment_gateway;
                break;
                }
            }

            $cod_gateway = ppec_get_payment_gateway_information($gateway);
            $values = $cod_gateway['values'];

            if ($values == "")
                $values = ['free_shipping', 'flat_rate'];

            foreach ($values as $key => $value) {
                $split_value = explode(":", $value);
                $shipping_type = $split_value[0];
                $values[$key] = ppec_get_shipping_type($shipping_type);
                if (isset($split_value[1])) $values[$key] = $values[$key] . ':' . $split_value[1];
            }

            $all_zones = WC_Shipping_Zones::get_zones();
            $shipping_configs = [];
            foreach ($all_zones as $zone_obj) {
                $zone = WC_Shipping_Zones::get_zone((int)$zone_obj['id']);
                $locations_in_zone = $zone->get_data();
                $all_shipping_methods = $zone->get_shipping_methods(true);

                if ($all_shipping_methods == []) continue;

                $shipping_methods = ppec_get_data_from_all_shipping_methods($all_shipping_methods);

                if ($shipping_methods == []) continue;

                foreach ($locations_in_zone["zone_locations"] as $location) {
                    if (!ppec_validate_indian_location($location->code, $location->type)) continue;

                    $area_type = ppec_get_area_type($location->type);
                    $key = ppec_get_key($location->code, $location->type, $state_code_mapping);
                    $config = $shipping_methods; // a list of shipping methods, even if only one shipping method is configured
                    foreach ($config as $c) {
                        $shipping_key = $c['type'] . ':' . $c['id'];

                        $config_array = array(
                        "areaType" => $area_type,
                        "key" => $key,
                        "enabled" => true,
                        "charges" => [
                            [
                            "charges" => 0, // no support in wc
                            "minCartValue" => 0 // no support in wc
                            ]
                        ]
                        );

                        if (isset($shipping_configs[$shipping_key]))
                            array_push($shipping_configs[$shipping_key], $config_array);
                        else
                            $shipping_configs[$shipping_key] = [$config_array];
                    }
                }
            }

            $cod_configs = [];
            if ($shipping_configs != null || $shipping_configs != []) {
                foreach ($values as $value) {
                    if (isset($shipping_configs[$value])) {
                        foreach ($shipping_configs[$value] as $shipping_config)
                            $cod_configs[$shipping_config['key']] = $shipping_config;
                    }else{
                        foreach ($shipping_configs as $key => $sc) {
                            if (ppec_starts_with($key, $value)) {
                                foreach ($shipping_configs[$key] as $shipping_config)
                                $cod_configs[$shipping_config['key']] = $shipping_config;
                            }
                        }
                    }
                }
            }

            if ($cod_gateway['enabled'] == false || $cod_configs == null || $cod_configs == []) {
                $cod_configs = null;
            }
            $cod_configs = ppec_convert_map_to_list($cod_configs);
        }elseif ($wc_phonepe->cod_config == 'pan_india_cod'){
            $cod_configs = [
                [
                    "areaType" => "COUNTRY",
                    "key" => "IN",
                    "enabled" => true,
                    "charges" => [
                        [
                        "charges" => ppec_convert_to_paisa($wc_phonepe->pan_india_cod_charges),
                        "minCartValue" => 0
                        ]
                    ]
                ]
            ];
        }
        $cod_payload = [
            "merchantId" => $merchant_id,
            "codConfigs" => $cod_configs
        ];

        try{
            PPEC_ExpressbuyApi::updateCodConfig($cod_payload, $merchant_salt_key, $merchant_salt_index, $environment, PPEC_PLATFORM, WOOCOMMERCE_VERSION, $plugin_version, $debug_url);
        }catch(Exception $e){
            $ppec_failure_event = new PPEC_WC_Event();
            $ppec_failure_event->set_eventType('COD_CONFIG_UPDATE');
            $ppec_failure_event->set_state('FAILURE');
            $ppec_failure_event->set_code($e->getCode());
            $ppec_failure_event->set_message($e->getTraceAsString());
            ppec_pushEvent($ppec_failure_event);
            //ExpressbuyApi::sendDebugResponse(json_encode($e->getMessage()), $debug_url . '/error');
        }
    }

    function isExpressbuyEnabled(){
        return (
            empty(get_option('woocommerce_phonepe_expressbuy_settings')['enabled']) === false
            && 'yes' == get_option('woocommerce_phonepe_expressbuy_settings')['enabled']
        );
    }

    function ppec_renderPhonepeProcessingModal(){
        $template = __DIR__ . '/templates/processing.php';
        load_template($template, false, array());
    }

    function ppec_enqueueScriptsForExpressbuy(){
        if(get_option('woocommerce_phonepe_expressbuy_settings') !== false) {
            $merchant_store_url = get_option('siteurl');
            $merchant_store_url = str_replace('http://', 'https://', $merchant_store_url);

            wp_register_script('phonepe_expressbuy_order_init', plugin_dir_url(__FILE__) . 'includes/js/frontend/models/order-init.js',  array(), null);
            wp_enqueue_script('phonepe_expressbuy_order_init');

            wp_register_script('phonepe_expressbuy_event', plugin_dir_url(__FILE__) . 'includes/js/frontend/models/event.js',  array(), null);
            wp_enqueue_script('phonepe_expressbuy_event');

            wp_register_script('phonepe_expressbuy_context', plugin_dir_url(__FILE__) . 'includes/js/frontend/models/context.js',  array(), null);
            wp_enqueue_script('phonepe_expressbuy_context');

            wp_register_script('phonepe_expressbuy_exception', plugin_dir_url(__FILE__) . 'includes/js/frontend/exception.js',  array(), null);
            wp_enqueue_script('phonepe_expressbuy_exception');

            wp_register_script('phonepe_expressbuy_utils', plugin_dir_url(__FILE__) . 'includes/js/frontend/utils.js',  array( ), null);
            wp_enqueue_script('phonepe_expressbuy_utils');

            wp_register_script('phonepe_expressbuy_checkout_view', plugin_dir_url(__FILE__) . 'includes/js/frontend/view/checkout-view.js', array( 'phonepe_expressbuy_checkout_controller' ), null);
            wp_enqueue_script('phonepe_expressbuy_checkout_view');

            wp_register_script('phonepe_expressbuy_checkout_controller', plugin_dir_url(__FILE__) . 'includes/js/frontend/controller/checkout-controller.js', 
                array( 
                    'jquery', 
                    'phonepe_expressbuy_order_init', 
                    'phonepe_expressbuy_event',
                    'phonepe_expressbuy_context',
                    'phonepe_expressbuy_exception',
                    'utils'
                ), null);
            wp_localize_script('phonepe_expressbuy_checkout_controller', 'context', array(
                'merchant_store_url' => $merchant_store_url,
                'merchant_store_name' => get_bloginfo('name'),
                'merchant_checkout_url' => wc_get_checkout_url(),
                'merchant_cart_url' => wc_get_cart_url(),
                'status_timeout' => get_option('woocommerce_phonepe_expressbuy_settings')['timeout'],
                'user_id' => wp_get_current_user()->ID,
            ));
            wp_enqueue_script('phonepe_expressbuy_checkout_controller');
        }
    }

    function initPaymentRequestWarmup(){
        $wc_phonepe = new WC_phonepe_expressbuy();
        $environment = $wc_phonepe->hostEnv;
        wp_register_script('phonepe_expressbuy_checkout', PPEC_ExpressbuyApi::getPaymentRequestUrl($environment), null, null);
        wp_enqueue_script('phonepe_expressbuy_checkout');

        wp_register_script('lottie-player', plugin_dir_url(__FILE__) . 'includes/js/frontend/lottie-player.js', null, null);
        wp_enqueue_script('lottie-player');

        wp_register_script('phonepe_expressbuy_init', plugin_dir_url(__FILE__) . 'includes/js/frontend/init-expressbuy.js', array( 'phonepe_expressbuy_checkout' ), null);
        wp_enqueue_script('phonepe_expressbuy_init');
    }

    if(isExpressbuyEnabled()){
        add_action('wp_head', 'initPaymentRequestWarmup', 0);
        // add checkout.js script to the cart and mini cart page
        add_action('wp_enqueue_scripts', 'ppec_enqueueScriptsForExpressbuy', 0);
        // adds modal html for processing screen on cart page
        add_action( 'wp_footer', 'ppec_renderPhonepeProcessingModal', 22);

        add_action( 'woocommerce_widget_shopping_cart_buttons', function(){
            // removes mini cart checkout button
            remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );
        },1);
    }

    add_filter('woocommerce_order_needs_shipping_address', '__return_true');
}