<?php
if ( ! defined( 'WC_ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
    require_once( WC_ABSPATH . 'includes/abstracts/abstract-wc-shipping-method.php' );
}
if ( ! class_exists( 'WC_Product' ) ) {
    require_once( WC_ABSPATH . 'includes/abstracts/abstract-wc-product.php' );
}

/**
 * Gophr_Shipping_Gophr class.
 *
 * @extends WC_Shipping_Method
 */
class Gophr_Shipping extends WC_Shipping_Method {
    
    private $pickup_code = array(
        '1' => "On Demand Pickup",
        '2' => "Hourly Pickup",
        '3' => "Daily Pickup",
    );
    
    private $services = array(
        "0" => "Same Day Delivery",
        //"1" => "High Priority Delivery",
        //"2" => "Rush Delivery",
    );
    
    /**
     * __construct function.
     *
     * @access public
     * @return void
     */
    public function __construct() {
        $this->id = GOPHR_ID;
        $this->method_title = __('GOPHR', 'gophr-woocommerce-shipping');
        $this->method_description = __('The <strong>Gophr</strong> extension obtains rates dynamically from the Gophr API during cart/checkout.',
                'gophr-woocommerce-shipping');

        $this->units = 'metric'; //'imperial';
        // Units
        if ($this->units == 'metric') {
            $this->weight_unit  = 'KG';
            $this->dim_unit     = 'CM';
        } else {
            $this->weight_unit  = 'LB';
            $this->dim_unit     = 'IN';
        }
        $this->countries    =  array('GB');
        $this->offer_rates  = 'all';
        $this->pickup       = '1';
        $this->settings['availability'] = 'specific';
        $this->manual_weight_dimensions = 'yes';
        
        $this->packing_method   = 'per_item';
        $this->gophr_packaging  = array();
        $this->boxes            = array();
        
        // Gophr: Load Gophr Settings.
        $gophr_settings = get_option('woocommerce_' . GOPHR_ID . '_settings', null);
        $api_mode = isset($gophr_settings['api_mode']) ? $gophr_settings['api_mode'] : 'Test';
        
        if ("Live" == $api_mode) {
            $this->endpoint = 'https://api.gophr.com/v1/commercial-api/';
        } else {
            $this->endpoint = 'https://api-sandbox.gophr.com/v1/commercial-api/';
        }
        
        $this->debug = false;
        
        $this->init();
    }

    /**
     * Output a message or error
     * @param  string $message
     * @param  string $type
     */
    public function debug($message, $type = 'notice') {
        $type = 'notice';
        if ($this->debug && !is_admin()) {
            if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                wc_add_notice($message, $type);
            } else {
                global $woocommerce;
                $woocommerce->add_message($message);
            }
        }
    }

    /**
     * init function.
     *
     * @access public
     * @return void
     */
    private function init() {
        global $woocommerce;
        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->enabled = isset($this->settings['enabled']) ? $this->settings['enabled'] : $this->enabled;
        $this->title = isset($this->settings['title']) ? $this->settings['title'] : $this->method_title;
        
        $this->ship_from_address = isset($this->settings['ship_from_address']) ? 
                $this->settings['ship_from_address'] : 'shipping_address';
        $this->disble_shipment_tracking = isset($this->settings['disble_shipment_tracking'])
                    ? $this->settings['disble_shipment_tracking'] : 'TrueForCustomer';
        $this->api_mode = isset($this->settings['api_mode']) ? $this->settings['api_mode'] : 'Test';
        $this->gophr_user_name = isset($this->settings['gophr_user_name']) ? $this->settings['gophr_user_name'] : '';
        $this->origin_company_name = isset($this->settings['origin_company_name']) ? $this->settings['origin_company_name'] : '';
        $this->phone_number = isset($this->settings['phone_number']) ? $this->settings['phone_number'] : '';
        $this->email_address = isset($this->settings['email_address']) ? $this->settings['email_address'] : '';
        
        $this->access_key = isset($this->settings['access_key']) ? $this->settings['access_key'] : '';
        $this->origin_addressline = isset($this->settings['origin_addressline'])
                    ? $this->settings['origin_addressline'] : '';
        $this->origin_city = isset($this->settings['origin_city']) ? $this->settings['origin_city'] : '';
        $this->origin_postcode = isset($this->settings['origin_postcode']) ? 
                $this->settings['origin_postcode'] : '';
        $this->origin_country_state = isset($this->settings['origin_country_state']) ? 
                $this->settings['origin_country_state'] : '';
        
        $this->custom_services = isset($this->settings['services']) ? $this->settings['services'] : array();
        $this->order_preparation_time = isset($this->settings['order_preparation_time']) ? $this->settings['order_preparation_time'] : array();
        
        $this->insurance_required = isset($this->settings['insurance_required']) && 
                $this->settings['insurance_required'] == 'yes' ? true : false;

        if (strstr($this->origin_country_state, ':')) {
            // Gophr: Following strict php standards.
            $origin_country_state_array = explode(':', $this->origin_country_state);
            $this->origin_country = current($origin_country_state_array);
            $origin_country_state_array = explode(':', $this->origin_country_state);
            $this->origin_state = end($origin_country_state_array);
        } else {
            $this->origin_country = $this->origin_country_state;
            $this->origin_state = '';
        }

        // COD selected
        $this->cod = false;
        $this->cod_total = 0;

        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'clear_transients'));
    }

    /**
     * environment_check function.
     *
     * @access public
     * @return void
     */
    private function environment_check() {
        global $woocommerce;

        $error_message = '';

        // Check for Gophr User Name
        if (!$this->gophr_user_name && $this->enabled == 'yes') {
            $error_message .= '<p>' . __('Gophr is enabled, but Your Name has not been set.',
                            'gophr-woocommerce-shipping') . '</p>';
        }
        if (!$this->access_key && $this->enabled == 'yes') {
            $error_message .= '<p>' . __('Gophr is enabled, but the Gophr Access Key has not been set.',
                            'gophr-woocommerce-shipping') . '</p>';
        }
        
        if (!$this->origin_postcode && $this->enabled == 'yes') {
            $error_message .= '<p>' . __('Gophr is enabled, but the origin postcode has not been set.',
                            'gophr-woocommerce-shipping') . '</p>';
        }

        // Check for Origin country
        if (!$this->origin_country_state && $this->enabled == 'yes') {
            $error_message .= '<p>' . __('Gophr is enabled, but the origin country/state has not been set.',
                            'gophr-woocommerce-shipping') . '</p>';
        }

        // Check for at least one service enabled
        $ctr = 0;
        if (isset($this->custom_services) && is_array($this->custom_services)) {
            foreach ($this->custom_services as $key => $values) {
                if ($values['enabled'] == 1) $ctr++;
            }
        }
        if (( $ctr == 0 ) && $this->enabled == 'yes') {
            $error_message .= '<p>' . __('Gophr is enabled, but there are no services enabled.',
                            'gophr-woocommerce-shipping') . '</p>';
        }
        
        if (!$error_message == '') {
            echo '<div class="error">';
            echo $error_message;
            echo '</div>';
        }
    }

    /**
     * admin_options function.
     *
     * @access public
     * @return void
     */
    public function admin_options() {
        // Check users environment supports this method
        $this->environment_check();

        // Show settings
        parent::admin_options();
    }

    /**
     *
     * generate_working_hours_html function
     *
     * @access public
     * @return void
     */
    public function generate_working_hours_html() {
        global $woocommerce;
        
        $working_hours = $this->get_working_hours();
        
        if ($working_hours) {
            $working_hours_string = $working_hours['working_days_string'] 
                    . ' From '. $working_hours['start_time'] 
                    . ' Until '. $working_hours['stop_time'];
        } else {
            $working_hours_string = 'Not able to get working hours.';
        }
        
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="gophr_working_hours"><?php _e('Gophr Working Hours',
                'gophr-woocommerce-shipping');
        ?></label>
            </th>
            <td class="forminp">
                <p class=""><?php echo __($working_hours_string, 'gophr-woocommerce-shipping') ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }
    
    /**
     *
     * generate_single_select_country_html function
     *
     * @access public
     * @return void
     */
    function generate_single_select_country_html() {
        global $woocommerce;

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="origin_country"><?php _e('Origin Country', 'gophr-woocommerce-shipping');
        ?></label>
            </th>
            <td class="forminp"><select name="woocommerce_gophr_origin_country_state" 
                    id="woocommerce_gophr_origin_country_state" style="width: 250px;" 
                    data-placeholder="<?php _e('Choose a country&hellip;', 'woocommerce');
        ?>" title="Country" class="chosen_select">
<!--                    <option selected="selected" value="GB">United Kingdom (UK)</option>-->
                    <?php echo $woocommerce->countries->country_dropdown_options($this->origin_country,
                            $this->origin_state ? $this->origin_state : '*' );
                    ?>
                </select> <span class="description"><?php _e('Country for the <strong>sender</strong>.',
                    'gophr-woocommerce-shipping')
                    ?></span>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * generate_services_html function.
     *
     * @access public
     * @return void
     */
    function generate_services_html() {
        ob_start();
        ?>
        <tr valign="top" id="service_options">
            <td class="forminp" colspan="2" style="padding-left:0px">
                <style type="text/css">
                    .gophr_boxes td, .gophr_services td {
                        vertical-align: middle;
                        padding: 4px 7px;
                    }
                    .gophr_boxes th, .gophr_services th {
                        padding: 9px 7px;
                    }
                    .gophr_boxes td input {
                        margin-right: 4px;
                    }
                    .gophr_boxes .check-column {
                        vertical-align: middle;
                        text-align: left;
                        padding: 0 7px;
                    }
                    .gophr_services th.sort {
                        width: 16px;
                        padding: 0 16px;
                    }
                    .gophr_services td.sort {
                        cursor: move;
                        width: 16px;
                        padding: 0 16px;
                        cursor: move;
                        background: url(data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAYAAADED76LAAAAHUlEQVQYV2O8f//+fwY8gJGgAny6QXKETRgEVgAAXxAVsa5Xr3QAAAAASUVORK5CYII=) no-repeat center;					
                    }
                    </style>
                <table class="gophr_services widefat">
                    <thead>
                    <th class="sort">&nbsp;</th>
                    <!--<th><?php _e('Service Code', 'gophr-woocommerce-shipping'); ?></th>-->
                    <th><?php _e('Name', 'gophr-woocommerce-shipping'); ?></th>
                    <th><?php _e('Enabled', 'gophr-woocommerce-shipping'); ?></th>
                    <th><?php echo sprintf(__('Price Adjustment By (%s)', 'gophr-woocommerce-shipping'),
                            get_woocommerce_currency_symbol());
                    ?></th>
                    <th><?php _e('Price Adjustment By (%)', 'gophr-woocommerce-shipping'); ?></th>
                </thead>
                <tbody>
                    <?php
                    $sort = 0;
                    $this->ordered_services = array();

                    $use_services = $this->services;

                    foreach ($use_services as $code => $name) {

                        if (isset($this->custom_services[$code]['order'])) {
                            $sort = $this->custom_services[$code]['order'];
                        }

                        while (isset($this->ordered_services[$sort])) $sort++;

                        $this->ordered_services[$sort] = array($code, $name);

                        $sort++;
                    }

                    ksort($this->ordered_services);

                    foreach ($this->ordered_services as $value) {
                        $code = $value[0];
                        $name = $value[1];
                        ?>
                        <tr>
                            <td class="sort"><input type="hidden" class="order" name="gophr_service[<?php echo $code; ?>][order]" value="<?php echo isset($this->custom_services[$code]['order'])
                                       ? $this->custom_services[$code]['order'] : '';
                           ?>" /></td>
                            <!--<td><strong><?php echo $code; ?></strong></td>-->
                            <td><input type="text" name="gophr_service[<?php echo $code; ?>][name]" placeholder="<?php echo $name; ?> (<?php echo $this->title; ?>)" value="<?php echo isset($this->custom_services[$code]['name'])
                                       ? $this->custom_services[$code]['name'] : '';
                           ?>" size="50" /></td>
                            <td><input type="checkbox" name="gophr_service[<?php echo $code; ?>][enabled]" <?php checked((!isset($this->custom_services[$code]['enabled']) ||
                    !empty($this->custom_services[$code]['enabled'])), true);
                        ?> /></td>
                            <td><input type="text" name="gophr_service[<?php echo $code; ?>][adjustment]" placeholder="0" value="<?php echo isset($this->custom_services[$code]['adjustment'])
                        ? $this->custom_services[$code]['adjustment'] : 0;
            ?>" size="4" /></td>
                            <td><input type="text" name="gophr_service[<?php echo $code; ?>][adjustment_percent]" placeholder="0" value="<?php echo isset($this->custom_services[$code]['adjustment_percent'])
                        ? $this->custom_services[$code]['adjustment_percent'] : 0;
                        ?>" size="4" /></td>
                        </tr>
            <?php
        }
        ?>
                </tbody>
            </table>
        </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * validate_single_select_country_field function.
     *
     * @access public
     * @param mixed $key
     * @return void
     */
    public function validate_single_select_country_field($key) {

        if (isset($_POST['woocommerce_gophr_origin_country_state']))
                return $_POST['woocommerce_gophr_origin_country_state'];
        return '';
    }

    /**
     * validate_services_field function.
     *
     * @access public
     * @param mixed $key
     * @return void
     */
    public function validate_services_field($key) {
        $services = array();
        $posted_services = $_POST['gophr_service'];

        foreach ($posted_services as $code => $settings) {

            $services[$code] = array(
                'name' => wc_clean($settings['name']),
                'order' => wc_clean($settings['order']),
                'enabled' => isset($settings['enabled']) ? true : false,
                'adjustment' => wc_clean($settings['adjustment']),
                'adjustment_percent' => str_replace('%', '',
                        wc_clean($settings['adjustment_percent']))
            );
        }

        return $services;
    }

    /**
     * clear_transients function.
     *
     * @access public
     * @return void
     */
    public function clear_transients() {
        global $wpdb;

        $wpdb->query("DELETE FROM `$wpdb->options` WHERE `option_name` LIKE ('_transient_gophr_quote_%') OR `option_name` LIKE ('_transient_timeout_gophr_quote_%')");
    }

    /**
     * init_form_fields function.
     *
     * @access public
     * @return void
     */
    public function init_form_fields() {
        global $woocommerce;

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'gophr-woocommerce-shipping'),
                'type' => 'checkbox',
                'label' => __('Enable this shipping method', 'gophr-woocommerce-shipping'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Shipping Title', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.',
                        'gophr-woocommerce-shipping'),
                'default' => __('Gophr', 'gophr-woocommerce-shipping')
            ),
//            'debug' => array(
//                'title' => __('Debug', 'gophr-woocommerce-shipping'),
//                'label' => __('Enable debug mode', 'gophr-woocommerce-shipping'),
//                'type' => 'checkbox',
//                'default' => 'no',
//                'description' => __('Enable debug mode to show debugging information on your cart/checkout.',
//                        'gophr-woocommerce-shipping')
//            ),
            'api' => array(
                'title' => __('API Settings', 'gophr-woocommerce-shipping'),
                'type' => 'title',
                //'description' => __('Apply on <a href="https://developers.gophr.com" '
                //        . 'target="_blank">developers.gophr.com</a> for Gophr API key.',
                //        'gophr-woocommerce-shipping'),
            ),
            'access_key' => array(
                'title' => __('Gophr API Key', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Create account at book.gophr.com and request API key though live chat or email help@gophr.com.',
                        'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'api_mode' => array(
                'title' => __('API Key Mode', 'gophr-woocommerce-shipping'),
                'type' => 'select',
                'default' => 'yes',
                'options' => array(
                    'Live' => __('Live', 'gophr-woocommerce-shipping'),
                    'Test' => __('Test', 'gophr-woocommerce-shipping'),
                ),
                'description' => __('Set as Test to switch to Gophr api test servers. Transaction will be treated as sample transactions by Gophr.',
                        'gophr-woocommerce-shipping')
            ),
            'ship_from_address' => array(
                'title' => __('Ship To Address', 'gophr-woocommerce-shipping'),
                'type' => 'select',
                'default' => 'shipping_address',
                'options' => array(
                    'shipping_address' => __('Shipping Address', 'gophr-woocommerce-shipping'),
                    'billing_address' => __('Billing Address', 'gophr-woocommerce-shipping'),
                ),
                'description' => __('Change the preferance of Shipping Address printed on the label.',
                        'gophr-woocommerce-shipping')
            ),
            'disble_shipment_tracking' => array(
                'title' => __('Shipment Tracking', 'gophr-woocommerce-shipping'),
                'type' => 'select',
                'default' => 'yes',
                'options' => array(
                    'TrueForCustomer' => __('Disable for Customer', 'gophr-woocommerce-shipping'),
                    'False' => __('Enable', 'gophr-woocommerce-shipping'),
                    'True' => __('Disable', 'gophr-woocommerce-shipping'),
                ),
                'description' => __('Selecting Disable for customer will hide shipment tracking info from customer side order details page.',
                        'gophr-woocommerce-shipping')
            ),
            'gophr_user_name' => array(
                'title' => __('Your Name', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Enter your name', 'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'origin_company_name' => array(
                'title' => __('Company Name', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Your business/attention name.', 'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'origin_addressline' => array(
                'title' => __('Origin Address', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Address for the <strong>sender</strong>.',
                        'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'origin_city' => array(
                'title' => __('Origin City', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('City for the <strong>sender</strong>.',
                        'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'origin_country_state' => array(
                'type' => 'single_select_country',
            ),
            'origin_postcode' => array(
                'title' => __('Origin Postcode', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Zip/postcode for the <strong>sender</strong>.',
                        'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'phone_number' => array(
                'title' => __('Your Phone Number', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Your contact phone number.', 'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'email_address' => array(
                'title' => __('Your Email Address', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'description' => __('Your email address.', 'gophr-woocommerce-shipping'),
                'default' => '',
            ),
            'insurance_required' => array(
                'title' => __('Insured Value', 'gophr-woocommerce-shipping'),
                'label' => __('Request Insurance to be included in Gophr rates',
                        'gophr-woocommerce-shipping'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Enabling this will include insurance in Gophr rates',
                        'gophr-woocommerce-shipping')
            ),
            'gophr_availability' => array(
                'title' => __('Gophr Availability', 'gophr-woocommerce-shipping'),
                'type' => 'title',
                'description' => '',
            ),
            'gophr_working_hours' => array(
                'type' => 'working_hours',
            ),
            'order_preparation_time' => array(
                'title' => __('Order preparation time', 'gophr-woocommerce-shipping'),
                'type' => 'text',
                'css' => "width:2em",
                'description' => __('Order preparation and packaging time in hours.', 'gophr-woocommerce-shipping'),
                'default' => '1',
            ),
            'services_packaging' => array(
                'title' => __('Services',
                        'gophr-woocommerce-shipping'),
                'type' => 'title',
                'description' => '',
            ),
            'services' => array(
                'type' => 'services'
            ),
        );
    }

    /**
     * calculate_shipping function.
     *
     * @access public
     * @param mixed $package
     * @return void
     */
    public function calculate_shipping($package=array()) {
        global $woocommerce;
        
        $rates = array();
        $gophr_responses = array();
        $action = 'get-a-quote';
        
        if (empty($package['destination']['city'])) {
            $package['destination']['city'] = $package['destination']['state'];
        }
        if (empty($package['destination']['city'])) {
            $this->debug(__('Gophr: City not yet supplied. Rates not requested.', 'gophr-woocommerce-shipping'));
            return;
        }
        if ('' == $package['destination']['postcode']) {
            $this->debug(__('Gophr: Zip not yet supplied. Rates not requested.', 'gophr-woocommerce-shipping'));
            return;
        }
        
        $package_requests = $this->get_package_requests($package);
        $this->debug("PACKAGE Request <pre>" . print_r($package_requests, true) . "</pre>", 'error');

        if ($package_requests) {

            $rate_requests = $this->get_rate_requests($package_requests, $package);

            if (!$rate_requests) {
                $this->debug(__('Gophr: No Services are enabled in admin panel.',
                                'gophr-woocommerce-shipping'));
            }
            
            // get live or cached result for each rate
            foreach ($rate_requests as $code => $request) {

                //todo: temporary until Commercial api get-a-quote is not done.
                //check if we already have a job
//                $transient_job_id = $this->id . $code . '_gophr_job_id';
//                $this->job_id = get_transient($transient_job_id);
//                if ($this->job_id) {
//                    $action = 'update-job';
//                    $request['job_id'] = $this->job_id;
//                    //increase time out of the transient job_id
//                    set_transient($transient_job_id, $this->job_id, 60 * 5);
//                }

                $request_str = implode('', $request);
                $send_request = str_replace(array("\n", "\r"), '', $request);
                $send_request['packages'] = $package;
                $params = array (
                    'request'   => $send_request,
                    'transient' => 'gophr_quote_' . md5($request_str),
                    'transient_duration' => (0.5 * 60 * 60), // 30 mins
                );
                
                //$params['endpoint'] = $quote_url;
                $response = $this->make_gophr_request($action, $params);
                if ( isset($response['success']) ) {
                    $gophr_responses[$code] = $response;
                }
            } // foreach
            
            // parse the results
            foreach ($gophr_responses as $code => $response) {
                
                if ($response['success'] == true) {
                    
                    $service_name = $this->services[$code];
                    if ((bool) $woocommerce->cart->prices_include_tax) {
                        $rate_cost = (float) $response['data']['price_gross'];
                    } else {
                        $rate_cost = (float) $response['data']['price_net'];
                    }
                    //display prices excluding tax?
                    /*
                    if ((bool) $woocommerce->cart->display_cart_ex_tax) {
                        $rate_cost = (float) $response['data']['price_net'];
                    } else {
                        $rate_cost = (float) $response['data']['price_gross'];
                    }
                    */
                    /*foreach($response as $vehicle_type => $quote) {
                        if ((bool) $woocommerce->cart->prices_include_tax) {
                            $all_quotes[$vehicle_type] = $quote['final_service_price_gross'];
                        } else {
                            $all_quotes[$vehicle_type] = $quote['final_service_price_net'];
                        }
                    }
                    
                    //get the lowest rate of all rates
                    if (count($rate_cost)) {
                        $rate_cost = min($rate_cost);
                    }*/
                    
                    $rate_id     = $this->id . ':' . $code;
                    $rate_name   = $service_name . ' (' . $this->title . ')';
                    //$job_id = $code . strstr($response['data']['job_id'], '-', true);
                    
                    //store job id for 5 mins - for update-job vs create-job
                    //set_transient($this->id . $code . '_gophr_job_id', $response['data']['job_id'], 60 * 5);
                    
                    $this->debug("service name: $service_name, rate_id: $rate_id, rate_name: $rate_name");
                    // Name adjustment
                    if (!empty($this->custom_services[$code]['name'])) {
                        $rate_name = $this->custom_services[$code]['name'];
                    }
                    //$rate_name .= ' (' . $job_id . ')';
                    // Cost adjustment %
                    if (!empty($this->custom_services[$code]['adjustment_percent'])) {
                        $rate_cost = $rate_cost + ( $rate_cost * ( 
                                floatval($this->custom_services[$code]['adjustment_percent']) / 100 ) );
                    }
                    // Cost adjustment
                    if (!empty($this->custom_services[$code]['adjustment']))
                            $rate_cost = $rate_cost + floatval($this->custom_services[$code]['adjustment']);

                    // Sort
                    if (isset($this->custom_services[$code]['order'])) {
                        $sort = $this->custom_services[$code]['order'];
                    } else {
                        $sort = 999;
                    }
                    
                    $rates[$code] = array(
                        'id' => $rate_id,
                        'label' => $rate_name,
                        'cost' => $rate_cost,
                        'sort' => $sort
                    );
                    
                } else {
                    // Either there was an error on this rate, or the rate is not valid (i.e. it is a domestic rate, but shipping international)
                    $this->debug(sprintf(__('[Gophr] No rate returned for service code %s, %s (Gophr code: %s)', 
                            'gophr-woocommerce-shipping'), $code, $response['error']['code'], 
                            $response['error']['message']), 'error');
                }
            } // foreach ( $gophr_responses )
        } // foreach ( $package_requests )
        
        // Add rates
        if ($rates) {

            if ($this->offer_rates == 'all') {

                uasort($rates, array($this, 'sort_rates'));
                foreach ($rates as $key => $rate) {
                    $this->add_rate($rate);
                }
            } else {

                $cheapest_rate = '';
                foreach ($rates as $key => $rate) {
                    if (!$cheapest_rate || $cheapest_rate['cost'] > $rate['cost']) {
                        $cheapest_rate = $rate;
                    }
                }
                //$cheapest_rate['label'] = $this->title;

                $this->add_rate($cheapest_rate);
            }
        }
    }

    /**
     * sort_rates function.
     *
     * @access public
     * @param mixed $a
     * @param mixed $b
     * @return void
     */
    public function sort_rates($a, $b) {
        if ($a['sort'] == $b['sort']) return 0;
        return ( $a['sort'] < $b['sort'] ) ? -1 : 1;
    }

    /**
     * get_package_requests
     * 
     * @access private
     * @return void
     */
    private function get_package_requests($package, $params = array()) {

        // Choose selected packing
        switch ($this->packing_method) {
//            case 'box_packing' :
//                $requests = $this->box_shipping($package, $params);
//                $requests = $this->get_package_details($requests);
//                break;
            case 'per_item' :
            default :
                $requests = $this->per_item_shipping($package, $params);
                $requests = $this->get_package_details($requests);
                break;
        }

        return $requests;
    }

    public function get_package_details($shipping_items) {

        $package = array(
            'weight' => 0.0,
            'size_x' => 0,
            'size_y' => 0,
            'size_z' => 0,
            'quantity' => 0,
            'order_value' => 0,
        );
        $shipping_items = (Array) $shipping_items;
        foreach ($shipping_items as $key => $item) {
            $package['weight'] += $item['weight'];
            $package['size_x'] += $item['size_x'];
            $package['size_y'] += $item['size_y'];
            $package['size_z'] += $item['size_z'];
            if (isset($item['quantity'])) {
                $package['quantity'] += $item['quantity'];
            }
            $package['order_value'] += $item['item_value'];
        }
        $package['quantity'] = ($package['quantity'])? : 1;

        return $package;
    }

    /**
     * get_rate_requests
     *
     * Get rate requests for all
     * @access private
     * @return array of strings - XML
     *
     */
    private function get_rate_requests($package_requests, $package) {
        global $woocommerce;
        //global $wp_version;
        $package_key = key($package_requests);

        $customer = $woocommerce->customer;
        $rate_requests = array();

        foreach ($this->custom_services as $code => $settings) {
            if (1 != $settings['enabled']) {
                continue;
            }
            
            $params = array();
            //$package_requests_to_append	= $package_requests;
            // Shipment information
            $params['plugin_name'] = "woocommerce";
            $params['plugin_version'] = WOOCOMMERCE_VERSION; //$wp_version;
            $params['pickup_type'] = $this->pickup;
            $params['pickup_code'] = $this->pickup_code[$this->pickup];
            //pickup info
            $params['pickup_company_name'] = $this->origin_company_name;
            $params['pickup_address1'] = $this->origin_addressline;

            //$params['user_id'] = $this->shipper_number;
            $params['pickup_city'] = $this->origin_city;
            $params['pickup_postcode'] = $this->origin_postcode;
            $params['pickup_country_code'] = $this->origin_country;
            //pick up extra fields
            $params['pickup_person_name'] = $this->gophr_user_name;
//          $params['pickup_email'] = '';
            $params['pickup_mobile_number'] = $this->phone_number;
//          $params['pickup_phone_number'] = '';
            //delivery
            //$params['delivery_company_name'] = $this->origin_addressline;
            $params['delivery_address1'] = $package['destination']['postcode']; $package['destination']['address'];
            $params['delivery_city'] = $package['destination']['city'];
            $params['delivery_state'] = $package['destination']['state'];

            if (empty($params['delivery_city'])) {
                $params['delivery_city'] = ($params['delivery_state'])? $params['delivery_state'] : 'London';
            }

            $params['delivery_postcode'] = $package['destination']['postcode'];
            $params['delivery_country_code'] = $package['destination']['country'];
            
            // not getting this fields from the cart
            $params['delivery_person_name'] = 'woocommerce guest';
//          $params['delivery_email'] = '';
            $params['delivery_mobile_number'] = '1234567890';
//          $params['delivery_phone_number'] = '';
                
            $params['delivery_address_type'] = $this->ship_from_address; //'billing|shipping';

            $params['insurance_required'] = (int) $this->insurance_required;
            $params['job_priority'] = $code;

            // packages
            $request = array_merge($params, $package_requests);
            //$params['external_id'] = 'xxx'; //$package[
            //$params['reference_number'] = $package_key;

            $rate_requests[$code] = $request;
        }

        return $rate_requests;
    }

    /**
     * per_item_shipping function.
     *
     * @access private
     * @param mixed $package
     * @return mixed $requests - an array of XML strings
     */
    private function per_item_shipping($package, $params = array()) {
        global $woocommerce;

        $requests = array();

        $ctr = 0;
        $this->cod = sizeof($package['contents']) > 1 ? false : $this->cod; // For multiple packages COD is turned off
        foreach ($package['contents'] as $item_id => $values) {
            $ctr++;
            $request = array();
            // TODO throws php fatal error when using it alongside wp-lister
            //var_dump($values['data'], get_class_methods($values['data']));
            
            // call to memeber function needs_shipping on boolean
            if (!( $values['quantity'] > 0 && is_object($values['data']) && $values['data']->needs_shipping() )) {
                $this->debug(sprintf(__('Product #%d is virtual. Skipping.', 
                        'gophr-woocommerce-shipping'), $ctr));
                continue;
            }

            if (!$values['data']->get_weight()) {
                $this->debug(sprintf(__('Product #%d is missing weight. Aborting.', 
                        'gophr-woocommerce-shipping'), $ctr), 'error');
                return;
            }

            // get package weight
            $weight = wc_get_weight($values['data']->get_weight(), $this->weight_unit);
            //$weight = apply_filters('gophr_filter_product_weight', $weight, $package, $item_id );
            // get package dimensions
            if ($values['data']->get_length() && $values['data']->get_height() && $values['data']->get_width()) {

                $dimensions = array(number_format(wc_get_dimension($values['data']->get_length(),
                                    $this->dim_unit), 2, '.', ''),
                    number_format(wc_get_dimension($values['data']->get_height(),
                                    $this->dim_unit), 2, '.', ''),
                    number_format(wc_get_dimension($values['data']->get_width(),
                                    $this->dim_unit), 2, '.', ''));
                sort($dimensions);

                $request['size_x'] = $dimensions[2];
                $request['size_y'] = $dimensions[1];
                $request['size_z'] = $dimensions[0];
            }

            // get quantity in cart
            $cart_item_qty = $values['quantity'];
            $request['size_unit'] = $this->dim_unit;
            $request['weight_unit'] = $this->weight_unit;

            // make sure weight in KG - until gophr api can take other units
            if ($this->weight_unit == 'LB') {
                $weight = $weight * 0.453592;
            }
            $request['weight'] = $weight;

            $request['currency_code'] = get_woocommerce_currency();
            
            $request['item_value'] = $values['data']->get_price();
            //$request['order_value'] = (float) $request['item_value'] * $cart_item_qty;
            //if ($this->cod) {
            //    $request['order_value'] = $this->cod_total;
            //}

            $request['quantity'] = 1;

            for ($i = 0; $i < $cart_item_qty; $i++) {
                $requests[] = $request;
            }
        }

        return $requests;
    }
    
    public function gophr_get_api_rate_box_data($package, $packing_method) {
        $this->packing_method = $packing_method;
        $requests = $this->get_package_requests($package);

        return $requests;
    }
    
    public function gophr_set_cod_details($order) {
        
        if ($order->oid) {
            $this->cod = get_post_meta($order->oid, '_gophr_cod', true);
            $this->cod_total = $order->get_total();
        }
    }

    public function gophr_set_service_code($service_code) {
        $this->service_code = $service_code;
    }
    
    public function get_working_hours() {
        
        $action = 'get-working-hours';
        $params = array (
            'transient' =>  'get-working-hours',
            'transient_duration' => (5 * 24 * 60 * 60), //2 days in seconds
            'request'   => array('city' => 'London'),
        );
        
        $response = $this->make_gophr_request($action, $params);
        
        if (isset($response['success'])) {
            return $response['data'];
        }
        
        return false;
    }
    
    public function make_gophr_request($action, $params, $headers=array()) {
        
        $result = array();
        $cached_response = $transient = false;
        if (isset($params['transient'])) {
            $transient = $params['transient'];
            $cached_response = get_transient($transient);
        }
        //$this->debug( "cached_response: ".  print_r ( $cached_response, true) );
        
        if ($cached_response === false) {
            
            $request = $params['request'];
            
            if (!is_array($request)) {
                $this->debug('Expecting array: <pre>' . print_r($request, true) . '</pre>');
            }
            
            $request['frontend_version'] = '2.0.20161117';
            if (! isset($request['api_key'])) {
                $request['api_key'] = $this->access_key;
            }
            $endpoint = isset($params['endpoint'])? $params['endpoint'] : $this->endpoint;
            
            $this->debug('Gophr REQUEST: <pre>' . print_r($request, true) . '</pre>');
            
            $headers['Content-type'] = 'application/x-www-form-urlencoded';
            $response = wp_remote_post($endpoint . $action,
                array(
                    'timeout' => 70,
                    'sslverify' => 0,
                    'body' => $request,
                    'headers' => $headers,
                )
            );
            
            if (is_wp_error($response)) {
                $error_string = $response->get_error_message();
                $this->error_message;
                $this->debug('Gophr REQUEST FAILED: <pre>' . print_r($error_string, true) . '</pre>', 'error');
                
                $result = array('remote_post_error' => $error_string);
                
            } else if (!empty($response['body'])) {
                
                $result = json_decode($response['body'], true);
                
                if ($transient) {
                    $transient_duration = isset($params['transient_duration'])? 
                        $params['transient_duration'] : (0.5 * 60 * 60); //default - half an hour 
                    set_transient($transient, $response['body'], $transient_duration);
                }
                $this->debug('Gophr RESPONSE ('. (int) $cached_response . '): <pre>' . print_r($result, true) . '</pre>');
            }
        } else {
            $result = json_decode($cached_response, true);
            $this->debug('Gophr Cached RESPONSE ('. (int) $cached_response . '): <pre>' . print_r($result, true) . '</pre>');
        }
        
        return $result;
    }
}
