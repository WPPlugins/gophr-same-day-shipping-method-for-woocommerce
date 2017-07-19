<?php

if (!class_exists('Gophr_Shipping')) {
    include_once 'class-gophr-shipping.php';
}

class Gophr_Shipping_Admin {

    public $debug;

    private $gophr_vehicle_type = array(
        "0" => "Auto", //Standard
        "10" => "Bike", //Standard
        "15" => "Cargo Bike",
        "20" => "MotorBike",
        //"30" => "Car",
        "40" => "Van",
    );
    
    private $pickup_code = array(
        '1' => "On Demand Pickup",
        '2' => "Hourly Pickup",
        '3' => "Daily Pickup",
    );
    
    private $gophr_services = array(
        "0" => "Same Day Delivery", //Standard
        "1" => "High Priority Delivery",
        "2" => "Rush Delivery",
    );

    public function __construct() {
        $this->gophr_init();

        //Print Shipping Label.
        if (is_admin()) {
            add_action('add_meta_boxes', array($this, 'gophr_add_metabox'), 15);
            add_action('admin_notices', array($this, 'gophr_admin_notice'), 15);
        }

        if (isset($_GET['gophr_shipment_confirm'])) {
            add_action('init', array($this, 'gophr_shipment_confirm'), 15);
        } else if (isset($_GET['gophr_shipment_accept'])) {
            add_action('init', array($this, 'gophr_shipment_accept'), 15);
        } else if (isset($_GET['gophr_void_shipment'])) {
            add_action('init', array($this, 'gophr_void_shipment'), 15);
        } else if (isset($_GET['gophr_void_shipment_confirm'])) {
            add_action('init', array($this, 'gophr_void_shipment_confirm'), 15);
        } else if (isset($_GET['gophr_void_shipment_cancel'])) {
            add_action('init', array($this, 'gophr_void_shipment_cancel'), 15);
        } else if (isset($_GET['gophr_update_shipment_status'])) {
            add_action('init', array($this, 'gophr_update_shipment_status'), 16);
        }
    }

    function gophr_admin_notice() {
        global $pagenow;
        global $post;
        
        if (!isset($_GET["gophrmsg"]) && empty($_GET["gophrmsg"])) {
            return;
        }

        $gophrmsg = $_GET["gophrmsg"];

        switch ($gophrmsg) {
            case "0":
                echo '<div class="error"><p>Gophr: Sorry, An unexpected error occurred.</p></div>';
                break;
            case "1":
                echo '<div class="updated"><p>Gophr: Shipment initiated successfully. Please proceed to Step 2, Accept Shipment.</p></div>';
                break;
            case "2":
            case "4.3": //Shipment cannot be cancelled at the momen
                $gophrmsg = get_post_meta($post->ID, 'gophrmsg', true);
                echo '<div class="error"><p>Gophr: ' . $gophrmsg . '</p></div>';
                break;
            case "3":
                echo '<div class="updated"><p>Gophr: Shipment accepted successfully.</p></div>';
                break;
            case "4":
                echo '<div class="updated"><p>Gophr: Cancellation of shipment completed successfully. You can re-initiate shipment.</p></div>';
                break;
            case "4.1":
                echo '<div class="updated"><p>Gophr: Shipment can be cancelled. Please check Cancellation Charges and Confirm Cancellation.</p></div>';
                break;
            case "4.2":
                echo '<div class="updated"><p>Gophr: Cancellation of shipment cancelled..</p></div>';
                break;
            case "5":
                echo '<div class="updated"><p>Gophr: Client side reset of labels and shipment completed. You can re-initiate shipment now.</p></div>';
                break;
            default:
                break;
        }
        //update_post_meta($post->ID, 'gophrmsg', '', true)
    }

    private function gophr_init() {
        global $post;
        
        $shipmentconfirm_requests = array();
        // Load Gophr Settings.
        $this->settings = get_option('woocommerce_' . GOPHR_ID . '_settings', null);
        //Print Label Settings.
        
        $this->packing_method = 'per_item';
        $this->disble_shipment_tracking = isset($this->settings['disble_shipment_tracking'])
                ? $this->settings['disble_shipment_tracking'] : 'TrueForCustomer';
        $this->manual_weight_dimensions = 'yes'; //no
        $this->show_label_in_browser = 'no';
        
        $this->api_mode = isset($this->settings['api_mode']) ? $this->settings['api_mode']: 'Test';
        $this->debug = isset($this->settings['debug']) && $this->settings['debug'] == 'yes' ? true : false;
        
        $this->access_key = isset($this->settings['access_key']) ? $this->settings['access_key'] : '';
        $this->order_preparation_time_secs = isset($this->settings['order_preparation_time']) ? ($this->settings['order_preparation_time'] * 60 * 60) : 0;
        $this->delivery_deadline_secs = isset($this->settings['delivery_deadline']) ? ($this->settings['delivery_deadline'] * 60 * 60) : 0;
     
        $this->pickup       = '1';
        
        $gophrShipping = new Gophr_Shipping();
        $this->gophr_working_hours = $gophrShipping->get_working_hours();
        
        $this->units = 'metric';
        if ($this->units == 'metric') {
            $this->weight_unit = 'KG';
            $this->dim_unit = 'CM';
        } else {
            $this->weight_unit = 'LB';
            $this->dim_unit = 'IN';
        }
    }

    function gophr_add_metabox() {
        global $post;

        if (!$post || get_post_type() != 'shop_order') return;

        $order = $this->gophr_load_order($post->ID);
        if (!$order) return;
        
        $shipping_service_data = $this->gophr_get_shipping_service_data($order);
        
        // Shipping method is available. 
        if ($shipping_service_data) {
            add_meta_box('CyDGophr_metabox',
                    __('Gophr Shipment', 'gophr-woocommerce-shipping'),
                    array($this, 'gophr_metabox_content'), 'shop_order', 'side',
                    'default');
        }
    }

    function gophr_metabox_content() {
        global $post;
        
        $shipment_id = get_post_meta( $post->ID , 'gophr_shipment_id', true );
        $order = $this->gophr_load_order($post->ID);
        
        $shipping_service_data = $this->gophr_get_shipping_service_data($order);
        $default_service_type = $shipping_service_data['shipping_service'];
        
        $created_shipments_details_array = get_post_meta($post->ID, 'gophr_created_shipments_details_array', true);
        // Create Job
        if (empty($created_shipments_details_array)) {
            
            $this->create_shipment_form($order);
            
        } else {

            // Draft Job is created
            //foreach
            //$shipment_id = key($created_shipments_details_array);
            $created_shipments_details = $created_shipments_details_array[$shipment_id];
            
            $confirmed_shipment_details_array = get_post_meta($post->ID, 'gophr_confirmed_shipment_details_array', true);
            if ($this->debug) {
                var_dump('$confirmed_shipment_details_array',$confirmed_shipment_details_array);
            }
            $job_priority = isset($created_shipments_details['job_priority'])? $created_shipments_details['job_priority'] : 0;
            //job priority and cost
            $job_priority_name = isset($this->gophr_services[$job_priority])? $this->gophr_services[$job_priority] : $this->gophr_services[0];
            
            echo "<strong>". __('Job ID: ', 'gophr-woocommerce-shipping') ."</strong>"
                . '<a href="'. $created_shipments_details["private_job_url"] . '"'
                . ' target="_blank">'. $shipment_id . '</a>'
                . '<hr style="border-color:#99ccff">';
        
            if (isset($confirmed_shipment_details_array[$shipment_id]['cancellation_cost'])) {
                $confirm_shipment_cancel_url = admin_url('/?gophr_void_shipment_confirm='. base64_encode($shipment_id.'|'.$post->ID));
                $cancel_cancellation_url = admin_url('/?gophr_void_shipment_cancel='. base64_encode($shipment_id.'|'.$post->ID));
            
                echo '<strong>Net Cancellation Charge: '. __('Cancellation Charges','gophr-woocommerce-shipping') .': £'
                    . $confirmed_shipment_details_array[$shipment_id]['cancellation_cost'] .'</strong>'
                    . '<hr style="border-color:#0074a2">';
                echo '<strong>'. __('Step 2: Confirm Cancellation.','gophr-woocommerce-shipping') .'</strong></br>'
                    . '<a class="button button-primary tips" href="'. $confirm_shipment_cancel_url .'" '
                    . 'data-tip="'. __('Confirm Shipment Cancellation', 'gophr-woocommerce-shipping') .'">'
                    . __('Confirm Shipment Cancellation', 'gophr-woocommerce-shipping').'</a>'
                    . '<hr style="border-color:#0074a2">';
                echo '<a class="button tips" href="'. $cancel_cancellation_url .'" data-tip="'
                    . __('Cancel Cancellation', 'gophr-woocommerce-shipping').'">'
                    . __('Cancel Cancellation', 'gophr-woocommerce-shipping').'</a>';
            
            // Accept/Confirm Job
            } elseif (empty($confirmed_shipment_details_array)) {
                
                $download_url = admin_url('/?gophr_shipment_accept=' . base64_encode($shipment_id.'|'.$post->ID));
                echo '<strong>Net Cost: '. __($job_priority_name, 'gophr-woocommerce-shipping') .': £'
                    .   $created_shipments_details['price_net'] .'</strong>'
                    .   '<hr style="border-color:#0074a2">';
                echo '<a class="button button-primary tips" href="'.$created_shipments_details["private_job_url"] 
                    .   '" data-tip="'. __('Edit/Draft Job Link', 'gophr-woocommerce-shipping').'" '
                    .   'target="_blank">'. __('Edit Draft Job', 'gophr-woocommerce-shipping').'</a>'
                    .   '<hr style="border-color:#0074a2">';
                echo '<strong>'. __('Step 2: Accept your shipment.', 'gophr-woocommerce-shipping')
                    .   '</strong></br><a class="button button-primary tips" href="'. $download_url
                    .   '" data-tip="'. __('Accept Shipment', 'gophr-woocommerce-shipping').'">'
                    .   __('Accept Shipment','gophr-woocommerce-shipping').'</a>'
                    .   '<hr style="border-color:#0074a2">';
                
                $reject_url = admin_url('/?gophr_void_shipment_confirm=' . base64_encode($shipment_id . '|' . $post->ID));
                echo '<strong>'. __('Void the Shipment', 'gophr-woocommerce-shipping').'</strong>'
                    .   '</br><a class="button tips" href="'. $reject_url.'" data-tip="'
                    .   __('Void Shipment', 'gophr-woocommerce-shipping').'">'
                    .   __('Void Shipment', 'gophr-woocommerce-shipping').'</a>'
                    .   '<hr style="border-color:#0074a2">';
                
            } else { // Job Confirmed - show tracking information

                $cancel_url = admin_url('/?gophr_void_shipment=' . base64_encode($shipment_id . '|' . $post->ID));
                
                //foreach
                //$shipment_id = key($confirmed_shipment_details_array);
                $confirmed_shipment_details = $created_shipments_details_array[$shipment_id];
                
                echo '<strong>Net Cost: '. __($job_priority_name, 'gophr-woocommerce-shipping').': £'
                    .   $created_shipments_details['price_net'] .'</strong>'
                    .   '<hr style="border-color:#0074a2">';
                echo '<strong>'. __('Tracker: ', 'gophr-woocommerce-shipping').'</strong><a '
                    .   'href="'. $confirmed_shipment_details["public_tracker_url"] 
                    .   '" target="_blank">'. $shipment_id .'</a><hr style="border-color:#99ccff">';
                echo '<a class="button button-primary tips" href="'
                    .   $confirmed_shipment_details["private_job_url"].'" data-tip="'
                    .   __('Active Job Link', 'gophr-woocommerce-shipping') .'" target="_blank">'
                    .   __('Open Active Job', 'gophr-woocommerce-shipping').'</a>'
                    .   '<hr style="border-color:#0074a2">';
                //<!--<strong>'. __('Cancel the Shipment', 'gophr-woocommerce-shipping').'</strong></br>-->
                echo '<a class="button tips" href="'. $cancel_url.'" data-tip="'
                    .   __('Cancel Shipment', 'gophr-woocommerce-shipping').'">'
                    .   __('Cancel Shipment', 'gophr-woocommerce-shipping') .'</a> &nbsp; '
                    .   '<img class="help_tip" style="float:none;margin-left:0;" '
                    .   'data-tip="'. __('There might be a cancellation fee.','gophr-woocommerce-shipping')
                    .   '" src="'. WC()->plugin_url() .'/assets/images/help.png" height="16" width="16" />';
                
                // Shipment tracking status
                $gophr_shipment_tracking_array = get_post_meta($post->ID, 'gophr_shipment_tracking_array', true);
                if (isset($gophr_shipment_tracking_array[$shipment_id])) {

                    echo '<hr style="border-color:#0074a2">
                            <strong>Shipment Status: </strong>
                            <ul>';

                    foreach ($gophr_shipment_tracking_array[$shipment_id] as $order=>$tracking) {
                        echo '<li>' 
                        .    date('H:i', $tracking['update_timestamp']). ': '
                        .    $tracking['status']
                        .   '</li>';
                    }
                    echo '</ul>';
                }
            } // job confirmed
        }
    }

    function gophr_shipment_confirm() {
        if (!$this->gophr_user_check()) {
            echo "You don't have admin privileges to view this page.";
            exit;
        }

        $gophrmsg = '';
        // Load Gophr Settings.
        $gophr_settings = get_option('woocommerce_' . GOPHR_ID . '_settings', null);
        // API Settings
        $api_mode = isset($gophr_settings['api_mode']) ? $gophr_settings['api_mode']: 'Test';
        
        $query_string = explode('|', base64_decode($_GET['gophr_shipment_confirm']));
        $post_id = $query_string[1];
        
        $cod = isset($_GET['cod']) ? $_GET['cod'] : '';
        if ($cod == 'true') {
            update_post_meta($post_id, '_gophr_cod', true);
        } else {
            delete_post_meta($post_id, '_gophr_cod');
        }
        $is_return_label = isset($_GET['is_return_label']) ? $_GET['is_return_label'] : '';
        if ($is_return_label == 'true') {
            $gophr_return = true;
        } else {
            $gophr_return = false;
        }

        $order = $this->gophr_load_order($post_id);

        $request = $this->gophr_shipment_confirmrequest($order);
        
        if (! is_array($request)) return;
        
        $action = 'create-job';
        $request_str = implode($request);
        //$send_request['packages'] = $package;
        $params = array (
            'request'   => $request,
            'transient' => 'gophr_quote_' . md5($request_str),
            'transient_duration' => (0.5 * 60 * 60),
        );
        
        $gophrShipping = new Gophr_Shipping();
        $response = $gophrShipping->make_gophr_request($action, $params);

        if (isset($response['remote_post_error'])) {
            $gophrmsg = 2;
            update_post_meta($post_id, 'gophrmsg', 'Sorry. Something went wrong: ' . $response['remote_post_error']);
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        }
        
        if (false == $response['success']) {
            $error_code = (string) $response['error']['code'];
            $error_desc = (string) $response['error']['message'];

            $gophrmsg = 2;
            update_post_meta($post_id, 'gophrmsg', $error_desc . ' [Error Code: ' . $error_code . ']');
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        }
        
        $job_data = $response['data'];
        
        $created_shipments_details_array = array();
        $shipment_id = (string) $job_data['job_id'];
        
        $created_shipments_details_array[$shipment_id] = $job_data;
        
        update_post_meta($post_id, 'gophr_shipment_id', $shipment_id);
        update_post_meta($post_id, 'gophr_created_shipments_details_array', $created_shipments_details_array);

        $gophrmsg = 1;
        wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
        exit;
    }
    
    function gophr_shipment_confirmrequest($order, $return_label = false) {
        global $post;

        $gophr_settings = get_option('woocommerce_' . GOPHR_ID . '_settings', null);
        // Define user set variables
        $gophr_enabled = isset($gophr_settings['enabled']) ? $gophr_settings['enabled'] : '';
        $gophr_title = isset($gophr_settings['title']) ? $gophr_settings['title'] : 'Gophr';
        $gophr_availability = isset($gophr_settings['availability']) ? $gophr_settings['availability'] : 'all';
        $gophr_countries = isset($gophr_settings['countries']) ? $gophr_settings['countries'] : array();
        
        $ship_from_address = isset($gophr_settings['ship_from_address']) ? $gophr_settings['ship_from_address'] : 'shipping_address';
        $phone_number = isset($gophr_settings['phone_number']) ? $gophr_settings['phone_number'] : '';
        $email_address = isset($gophr_settings['email_address']) ? $gophr_settings['email_address'] : '';
        $gophr_manual_weight_dimensions = 'yes';
        
        // API Settings
        $gophr_user_name = isset($gophr_settings['gophr_user_name']) ? $gophr_settings['gophr_user_name'] : '';
        $origin_company_name = isset($gophr_settings['origin_company_name']) ? $gophr_settings['origin_company_name'] : '';
        $gophr_access_key = isset($gophr_settings['access_key']) ? $gophr_settings['access_key'] : '';
        
        $gophr_insurance_required = isset($gophr_settings['insurance_required']) 
                && $gophr_settings['insurance_required'] == 'yes' ? true : false;

        $shipping_first_name = $order->get_shipping_first_name();
        $shipping_last_name = $order->get_shipping_last_name();
        $shipping_full_name = $shipping_first_name . ' ' . $shipping_last_name;
        $shipping_company = $order->get_shipping_company();
        $shipping_address_1 = $order->get_shipping_address_1();
        $shipping_address_2 = $order->get_shipping_address_2();
        $shipping_city = $order->get_shipping_city();
        $shipping_postcode = $order->get_shipping_postcode();
        $shipping_country = $order->get_shipping_country();
        $shipping_state = $order->get_shipping_state();
        $billing_email = $order->get_billing_email();
        $billing_phone = $order->get_billing_phone();
        
        $gophr_origin_addressline = $order->get_billing_address_1() . ', ' . $order->get_billing_address_2();
        $gophr_origin_city = $order->get_billing_city();
        $gophr_origin_postcode = $order->get_billing_postcode();
        $origin_country = $order->get_billing_country();
        $origin_state = $order->get_billing_state();
        
        $cod = get_post_meta($order->oid, '_gophr_cod', true);
        $order_total = $order->get_total();
        $order_currency = $order->get_order_currency();

        $vehicle_type = isset($_GET['vehicle_type'])? $_GET['vehicle_type'] : 0;
        
        $ship_options = array('return_label' => $return_label); // Array to pass options like return label on the fly.

        if ('billing_address' == $ship_from_address) {
            $origin_company_name = $order->get_billing_company();
            $phone_number = $billing_phone;
            $billing_full_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $gophr_user_name = $billing_full_name;
        } else {
            $gophr_origin_addressline = isset($gophr_settings['origin_addressline'])
                        ? $gophr_settings['origin_addressline'] : '';
            $gophr_origin_city = isset($gophr_settings['origin_city']) ? $gophr_settings['origin_city']
                        : '';
            $gophr_origin_postcode = isset($gophr_settings['origin_postcode']) ? $gophr_settings['origin_postcode']
                        : '';
            $gophr_origin_country_state = isset($gophr_settings['origin_country_state'])
                        ? $gophr_settings['origin_country_state'] : '';

            if (strstr($gophr_origin_country_state, ':')) {
                $origin_country_state_array = explode(':', $gophr_origin_country_state);
                $origin_country = current($origin_country_state_array);
                $origin_country_state_array = explode(':', $gophr_origin_country_state);
                $origin_state = end($origin_country_state_array);
            } else {
                $origin_country = $gophr_origin_country_state;
                $origin_state = '';
            }

            if ('' == $origin_company_name) {
                $origin_company_name = $gophr_user_name;
            }
        }

        $shipping_service_data = $this->gophr_get_shipping_service_data($order);
        
        if ($gophr_manual_weight_dimensions == 'yes') {
            $package_data = $this->gophr_get_package_data_manual($order, $ship_options);
        } else {
            $package_data = $this->gophr_get_package_data($order, $ship_options);
        }
        
        if (empty($package_data)) {
            return false;
        }
        
        $shipment_description = $this->gophr_get_shipment_description($order);

        // map to gophr params
        $request = array();
        $request['api_key']     = $gophr_access_key;
        //$request['user_id']     = $gophr_user_id;
        //$request['password']    = $gophr_password;
        
        $request['action']      = 'create-job';//ReturnService
        //$request['callback_action'] = array('cheapest-rate');// , 'multiple-rates', package/vehicle
        
        $request['preferred_vehicle_type'] = $vehicle_type;
        
        $params['plugin_name'] = "woocommerce";
        $request['pickup_type'] = $this->pickup;
        $request['pickup_code'] = $this->pickup_code[$this->pickup];
        
        $request['plugin_name'] = 'woocommerce';
        $request['plugin_version'] = WC_VERSION;
        
        $request['reference_number']   = $order->oid;
        $request['description'] = $shipment_description;
        $request['external_id'] = $order->oid;
        
        if ($this->disble_shipment_tracking != 'True') {
            //TODO: not configured for https
            $request['callback_url'] = admin_url( '/?post='.( $order->oid).'&gophr_update_shipment_status' );
            //$request_method = ($_SERVER['HTTPS'] != "on")? 'http://' : 'https://';
            //$request['callback_url'] = $request_method . $_SERVER['SERVER_NAME'].$_SERVER['SCRIPT_NAME'].'?post='.$order->oid.'&gophr_update_shipment_status';
        }
        
        $request['pickup_company_name'] = htmlspecialchars($origin_company_name);
        $request['pickup_person_name'] = htmlspecialchars($gophr_user_name);
        $request['pickup_mobile_number'] = (strlen($phone_number) < 10)? '1234567890' : 
                htmlspecialchars($phone_number)  ;
        $request['pickup_email'] = htmlspecialchars($email_address);
        
        $request['pickup_address1'] =  htmlspecialchars($gophr_origin_addressline);
        $request['pickup_city']     = empty($gophr_origin_city)? $origin_state : $gophr_origin_city;
        $request['pickup_postcode'] = $gophr_origin_postcode;
        $request['pickup_country_code'] = $origin_country;
        
        if ('' == trim($shipping_company)) {
            $request['delivery_company_name'] = $shipping_company;
        }
        
        $request['delivery_person_name'] = htmlspecialchars($shipping_full_name);
        $request['delivery_address1']   = htmlspecialchars($shipping_address_1);
        $request['delivery_address2']   = htmlspecialchars($shipping_address_2);
        $request['delivery_city']       = empty($shipping_city)? $shipping_state : $shipping_city;
        $request['delivery_state']      = $shipping_state;
        $request['delivery_postcode']   = $shipping_postcode;
        $request['delivery_country_code']  = $shipping_country;
        $request['delivery_mobile_number'] = $billing_phone;
        $request['delivery_email']      = $billing_email;
        
        //any job schedule
        $schedule = $this->gophr_get_pickup_schedule();
        $request['earliest_pickup_time'] = $schedule['pickup_time'];
        $request['delivery_deadline'] = $schedule['delivery_time'];
        
        //initialise weight and size
        $request['weight'] = 0;
        $request['size_x'] = 0;
        $request['size_y'] = 0;
        $request['size_z'] = 0;
        $request['quantity'] = 0;
        
        //foreach ($package_data as $key=>$package) {
            $request['weight'] += $package_data['weight'];
            $request['size_x'] += $package_data['size_x'];
            $request['size_y'] += $package_data['size_y'];
            $request['size_z'] += $package_data['size_z'];
            $request['quantity'] += $package_data['quantity'];
            //$request['packages'][] = $package;
        //}
        
        //include return label
        $request['include_return_label'] = $return_label; //$print_label_size
        
        if ($cod) {
            $request['cod'] = $cod;
            $request['cod_value'] = $cod_value;
        }

        $request['order_value'] = $order_total;
        $request['order_currency'] = $order_currency;
        $request['insurance_required'] = $gophr_insurance_required;
        
        //if priority requested
        $request['job_priority'] = $shipping_service_data['shipping_service'];
        
        $request['shipping_method']     = $shipping_service_data['shipping_method'];
        $request['shipping_service']    = $shipping_service_data['shipping_service'];
        $request['shipping_service_name'] = $shipping_service_data['shipping_service_name'];
        
        return $request;
    }
    
    function gophr_shipment_accept() {
        if (!$this->gophr_user_check()) {
            echo "You don't have admin privileges to view this page.";
            exit;
        }

        $query_string = explode('|', base64_decode($_GET['gophr_shipment_accept']));
        $shipment_id = $query_string[0];
        $post_id = $query_string[1];
        $gophrmsg = '';
        
        //previous draft job details
        $created_shipments_details_array = get_post_meta($post_id, 'gophr_created_shipments_details_array', true);
        $created_shipments_details = $created_shipments_details_array[$shipment_id];
        //service type
        $job_priority = $created_shipments_details['job_priority'];
        $gophr_selected_service = $this->gophr_services[$job_priority];
        
        // Load Gophr Settings.
        $gophr_settings = get_option('woocommerce_' . GOPHR_ID . '_settings', null);
        
        $request = array();
        $request['job_id'] = $shipment_id;
        //$request['external_id'] = $post_id;
        
        $action = 'confirm-job';
        $params['request'] = $request;
        
        $gophrShipping = new Gophr_Shipping();
        $response = $gophrShipping->make_gophr_request($action, $params);

        if (isset($response['remote_post_error'])) {
            $gophrmsg = 2;
            update_post_meta($post_id, 'gophrmsg', 'Sorry. Something went wrong: ' . $response['remote_post_error']);
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        }

        if (false == $response['success']) {
            $error_code = (string) $response['error']['code'];
            $error_desc = (string) $response['error']['message'];

            //check ERROR_JOB_STATUS
            // if job is already created then call get-job and update the status accordingly
            
            //else
            $gophrmsg = 2;
            update_post_meta($post_id, 'gophrmsg', $error_desc . ' [Error Code: ' . $error_code . ']');
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        }
        
        $job_data = $response['data'];
        $confirmed_shipment_details_array = array();
        
        if ( !empty($created_shipments_details) ) {
            $confirmed_shipment_details_array[$shipment_id] = array_merge($created_shipments_details, $job_data);
        }
        
        $shipment_id_cs = $shipment_id;
        $shipment_id_cs = rtrim($shipment_id_cs, ',');
        
        if ( empty($confirmed_shipment_details_array) ) {
            $gophrmsg = 0;
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        } else {
            update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $confirmed_shipment_details_array);
        }
        
        update_post_meta($post_id, 'gophr_selected_service', $gophr_selected_service);

        //remove tracking box
        /*if ('True' != $this->disble_shipment_tracking) {
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&gophr_track_shipment=' . $shipment_id_cs));
            exit;
        }*/
        
        $gophrmsg = 3;
        wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
        exit;
    }

    function gophr_void_shipment() {
        if (!$this->gophr_user_check()) {
            echo "You don't have admin privileges to view this page.";
            exit;
        }

        $query_string = explode('|', base64_decode($_GET['gophr_void_shipment']));
        $shipment_id = $query_string[0];
        $post_id = $query_string[1];
        $gophrmsg = '';
        
        $confirmed_shipment_details_array = get_post_meta($post_id, 'gophr_confirmed_shipment_details_array', true);
        
        if (!empty($confirmed_shipment_details_array) && $shipment_id) {
            
            $request = array();
            //$request['api_key']     = $gophr_access_key;
            $request['job_id'] = $shipment_id;
            $request['external_id'] = $post_id;
            
            $last_gophrmsg = get_post_meta($post_id, 'gophrmsg', true);
            if ($last_gophrmsg) { // timed out etc
                $request['reason'] = $last_gophrmsg;
            }
            
            $action = 'get-cancelation-cost';
            $request_str = implode($request);
            //$send_request['packages'] = $package;
            $params = array (
                'request'   => $request,
                //'transient' => 'gophr_quote_' . md5($request_str),
                //'transient_duration' => 2 * 60 * 60,
            );

            $gophrShipping = new Gophr_Shipping();
            $response = $gophrShipping->make_gophr_request($action, $params);

            if (isset($response['remote_post_error'])) {
                $gophrmsg = 2;
                update_post_meta($post_id, 'gophrmsg', 'Sorry. Something went wrong: ' . $response['remote_post_error']);
                wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
                exit;
            }
            // It is an error response.
            if (false == $response['success']) {
                $error_code = (string) $response['error']['code'];
                $error_desc = (string) $response['error']['message'];

                $message = '<strong>' . $error_desc . ' [Error Code: ' . $error_code . ']' . '. </strong>';

                $gophrmsg = 2;
                update_post_meta($post_id, 'gophrmsg', $message);
                wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
                exit;
            }
            
            $job_data = $response['data'];
            
            // if there is a cost
            //if ((int)$job_data['price'] > 0) {

                //if (! isset($job_data['message']) && (int)$job_data['price'] > 0 ) {
                    //$confirmed_shipment_details_array['cancellation_message'] = '<strong>Subject to cancellation charges' . $job_data['price'] .'. </strong>';
                //}
                
                $confirmed_shipment_details_array[$shipment_id]['cancellation_cost'] = (float) $job_data['cancelation_cost'];
                update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $confirmed_shipment_details_array);
                
                $gophrmsg = 4.1;
                update_post_meta($post_id, 'gophrmsg', $message);
                wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
                exit;
            //}
        }
        
        // if there are no cost or no job id created
        $this->gophr_void_shipment_confirm();
        //$this->gophr_void_return_shipment($post_id, $shipment_id);
    }

    function gophr_void_shipment_confirm() {
        
         if (!$this->gophr_user_check()) {
            echo "You don't have admin privileges to view this page.";
            exit;
        }

        $query_string = explode('|', base64_decode($_GET['gophr_void_shipment_confirm']));
        $shipment_id = $query_string[0];
        $post_id = $query_string[1];
        $gophrmsg = '';
        
        $confirmed_shipment_details_array = get_post_meta($post_id,'gophr_confirmed_shipment_details_array', true);
        
        $client_side_reset = false;
        if (isset($_GET['client_reset'])) {
            $client_side_reset = true;
        }
        
        if (!empty($confirmed_shipment_details_array) && $shipment_id) {
            
            $request                = array();
            $request['job_id']      = $shipment_id;
            $request['external_id'] = $post_id;
            
            if ($last_gophrmsg) { // timed out etc
                $request['reason']  = $last_gophrmsg;
            }

            $action = 'cancel-job';
            $params = array (
                'request' => $request,
            );
            $gophrShipping = new Gophr_Shipping();
            $response = $gophrShipping->make_gophr_request($action, $params);

            if (isset($response['remote_post_error'])) {
                $gophrmsg = 2;
                update_post_meta($post_id, 'gophrmsg', 'Sorry. Something went wrong: ' . $response['remote_post_error']);
                wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
                exit;
            }
            // It is an error response.
            if (false == $response['success']) {
                $error_code = (string) $response['error']['code'];
                $error_desc = (string) $response['error']['message'];

                $message = '<strong>' . $error_desc . ' [Error Code: ' . $error_code . ']' . '. </strong>';

                $current_page_uri = $_SERVER['REQUEST_URI'];
                $href_url = $current_page_uri . '&client_reset';

                $message .= 'Please contact Gophr to void/cancel this shipment. <br/>';
                $message .= 'If you have already cancelled this shipment by calling Gophr customer care, and you would like to create shipment again then click <a class="button button-primary tips" href="' . $href_url . '" data-tip="Client Side Reset">Client Side Reset</a>';
                $message .= '<p style="color:red"><strong>Note: </strong>Previous shipment details and label will be removed from Order page.</p>';

                unset($confirmed_shipment_details_array[$shipment_id]['cancellation_cost']);
                update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $confirmed_shipment_details_array);
                
                $gophrmsg = 4.3;
                update_post_meta($post_id, 'gophrmsg', $message);
                wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
                exit;
            }
        }
        $empty_array = array();
        
        update_post_meta($post_id, 'gophr_created_shipments_details_array', $empty_array);
        update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $empty_array);
        delete_post_meta($post_id, 'gophr_selected_service');
        
        delete_post_meta( $post_id , 'gophr_shipment_id');
        
        // Reset of stored meta elements done. Back to admin order page. 
        if ($client_side_reset) {
            $gophrmsg = 5;
            wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
            exit;
        }

        $gophrmsg = 4;
        wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
        exit;
    }
    
    function gophr_void_shipment_cancel($gophrmsg) {
        
         if (!$this->gophr_user_check()) {
            echo "You don't have admin privileges to view this page.";
            exit;
        }

        $query_string = explode('|', base64_decode($_GET['gophr_void_shipment_cancel']));
        $shipment_id = $query_string[0];
        $post_id = $query_string[1];
        $gophrmsg = '';
        
        $confirmed_shipment_details_array = get_post_meta($post_id, 'gophr_confirmed_shipment_details_array', true);
        unset($confirmed_shipment_details_array[$shipment_id]['cancellation_cost']);
        update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $confirmed_shipment_details_array);
        
        $gophrmsg = 4.2;
        update_post_meta($post_id, 'gophrmsg', '');
        wp_redirect(admin_url('/post.php?post=' . $post_id . '&action=edit&gophrmsg=' . $gophrmsg));
        exit;
    }
    
    private function gophr_get_shipment_description($order) {
        
        $shipment_description = '';
        $order_items = $order->get_items();

        foreach ($order_items as $order_item) {
            $product_data = wc_get_product($order_item['variation_id'] ? $order_item['variation_id']
                                : $order_item['product_id'] );
            $title = $product_data->get_title();
            $shipment_description .= $title . ', ';
        }

        if ('' == $shipment_description) {
            $shipment_description = 'Package/customer supplied.';
        }

        $shipment_description = ( strlen($shipment_description) >= 50 ) ? substr($shipment_description,
                        0, 45) . '...' : $shipment_description;

        return $shipment_description;
    }

    function gophr_get_package_data($order, $ship_options = array()) {
        $package = $this->gophr_create_package($order);
        $shipping_service_data = $this->gophr_get_shipping_service_data($order);

        if (!$shipping_service_data) {
            return false;
        }
        
        $wcsgophr = new Gophr_Shipping();
        $package_data_array = array();

        if (empty($ship_options['return_label'])) { // If return label is printing, cod can't be applied
            $wcsgophr->gophr_set_cod_details($order);
        }
        
        $service_code = get_post_meta($order->oid, 'gophr_selected_service', 1);
        if ($service_code) {
            $wcsgophr->gophr_set_service_code($service_code);
        }

        $package_data = $wcsgophr->gophr_get_api_rate_box_data($package, $this->packing_method);

        return $package_data;
    }

    function gophr_get_package_data_manual($order, $ship_options = array()) {
        global $woocommerce;

        if ($this->units == 'metric') {
            $weight_unit = 'KG';
            $dim_unit = 'CM';
        } else {
            $weight_unit = 'LB';
            $dim_unit = 'IN';
        }
        
        $request= array();
        $weight = isset($_GET['weight']) ? $_GET['weight'] : false;
        $height = isset($_GET['height']) ? $_GET['height'] : false;
        $width  = isset($_GET['width']) ? $_GET['width'] : false;
        $length = isset($_GET['length']) ? $_GET['length'] : false;
        $quantity = isset($_GET['quantity']) ? $_GET['quantity'] : 1;

        $dimensions = array($height, $width, $length);
        sort($dimensions);
        
        if ($this->weight_unit == 'LB') {
            $weight = $weight * 0.453592;
        }
        $request['weight'] = $weight;
        $request['weight_unit'] = $weight_unit;
        
        $request['size_unit'] = $dim_unit;
        $request['size_x'] = $dimensions[2];
        $request['size_y'] = $dimensions[1];
        $request['size_z'] = $dimensions[0];
        $request['quantity'] = $quantity;
        
        return $request;
    }

    function gophr_get_pickup_schedule() {

        $request = array();
        $pickup_date = isset($_GET['pickup_date']) ? $_GET['pickup_date'] : false;
        
        if ($pickup_date) {
            $request['pickup_time']     = empty($_GET['pickup_time'])? '' :
                    $pickup_date .' '. $_GET['pickup_time'];
            $request['delivery_time']   = empty($_GET['delivery_time'])? '' :
                    $pickup_date. ' '. $_GET['delivery_time'];
        }
        
        return $request;
    }
    
    function gophr_create_package($order) {
        $orderItems = $order->get_items();

        foreach ($orderItems as $orderItem) {
            $item_id = $orderItem['variation_id'] ? $orderItem['variation_id'] : $orderItem['product_id'];
            $product_data = wc_get_product($item_id);
            $items[$item_id] = array('data' => $product_data, 'quantity' => $orderItem['qty']);
        }

        $package['contents'] = $items;
        $package['destination'] = array(
            'country' => $order->get_shipping_country(),
            'state' => $order->get_shipping_state(),
            'postcode' => $order->get_shipping_postcode(),
            'city' => $order->get_shipping_city(),
            'address' => $order->get_shipping_address_1(),
            'address_2' => $order->get_shipping_address_2());

        return $package;
    }

    function gophr_load_order($orderId) {
        if (!class_exists('WC_Order')) {
            return false;
        }
        $order = new WC_Order($orderId);
        $order->oid = ( WC_VERSION < '2.7.0' ) ? $order->id : $order->get_id();
        
        return $order;
    }

    function gophr_user_check() {
        if (is_admin()) {
            return true;
        }
        return false;
    }

    function gophr_get_shipping_service_data($order) {
        //TODO: Take the first shipping method. The use case of multiple shipping method for single order is not handled.

        $shipping_methods = $order->get_shipping_methods();
        if (!$shipping_methods) {
            return false;
        }

        $shipping_method = array_shift($shipping_methods);
        $shipping_service_tmp_data = explode(':', $shipping_method['method_id']);
        $gophr_selected_service = '';
        
        $gophr_selected_service = get_post_meta($order->oid, 'gophr_selected_service', true);

        if ('' != $gophr_selected_service) {
            $shipping_service_data['shipping_method'] = GOPHR_ID;
            $shipping_service_data['shipping_service'] = $gophr_selected_service;
            $shipping_service_data['shipping_service_name'] = isset($gophr_services[$gophr_selected_service])
                        ? $gophr_services[$gophr_selected_service] : '';
        } else if (!isset($shipping_service_tmp_data[0]) || ( isset($shipping_service_tmp_data[0]) &&
                $shipping_service_tmp_data[0] != GOPHR_ID )) {
            $shipping_service_data['shipping_method'] = GOPHR_ID;
            $shipping_service_data['shipping_service'] = '';
            $shipping_service_data['shipping_service_name'] = '';
        } else {
            $shipping_service_data['shipping_method'] = $shipping_service_tmp_data[0];
            $shipping_service_data['shipping_service'] = $shipping_service_tmp_data[1];
            $shipping_service_data['shipping_service_name'] = $shipping_method['name'];
        }

        return $shipping_service_data;
    }
    
    function gophr_pickup_delivery_time($order) {
        
        $shipping_times = array();
        
        $start_time_gmt = strtotime($this->gophr_working_hours['start_time']);
        $stop_time_gmt  = strtotime($this->gophr_working_hours['stop_time']);
        
        $start_time = date_i18n('H:i', $start_time_gmt);//, true
        $stop_time = date_i18n('H:i', $stop_time_gmt);//, true
        
        $start_time = strtotime($start_time);
        $stop_time = strtotime($stop_time);
        
        //pickup time
        $pickup_time   = time() + $this->order_preparation_time_secs;
        if ($pickup_time < $start_time) {
            $pickup_time = $start_time;
        }
        
        //delivery time
        if (empty($this->delivery_deadline_secs)) {
            $delivery_time = $stop_time;
        } else if (($pickup_time + $this->delivery_deadline_secs) > $stop_time) {
            $delivery_time = $stop_time;
        } else {
            $delivery_time = $pickup_time + $this->delivery_deadline_secs;
        }
        
        if ($pickup_time > $stop_time) {
            $pickup_time = strtotime("tomorrow " . $this->gophr_working_hours['start_time']) + $this->order_preparation_time_secs;
            $delivery_time = strtotime("tomorrow " . $this->gophr_working_hours['stop_time']);
        }
        $shipping_times['pickup'] = $pickup_time;
        $shipping_times['deliver']  = $delivery_time;
        
        return $shipping_times;
    }
    
    function gophr_update_shipment_status() {
        global $pagenow;
        global $post;
        
        if (!isset($_GET['post'])) return;
        $post_id = $_GET['post'];
        
        if (!isset($_POST["api_key"]) && empty($_POST["api_key"])) {
            return;
        }
        
        if ($_POST["api_key"] != $this->access_key) {
            //report it too
            return;
        }
        
        $job_status_array = $_POST;
        $job_status_array['update_timestamp'] = time();
        
        $shipment_id = $job_status_array['job_id'];
        //$order->oid    = $job_status_array['external_id'];
        
        $order = $this->gophr_load_order($post_id);
        if (!$order) return;
        
        unset($job_status_array['api_key'], $job_status_array['external_id'], $job_status_array['job_id']);
        
        //$job_status_array['finished'], $job_status_array['status'], $job_status_array['progress'], 
        //$job_status_array['pickup_eta'], $job_status_array['delivery_eta'], 
        //$job_status_array['courier_name']
        
        $gophr_shipment_tracking_array = get_post_meta($post_id, 'gophr_shipment_tracking_array', true);
        if (empty($gophr_shipment_tracking_array)) {
            $gophr_shipment_tracking_array = array();
        }
        $gophr_shipment_tracking_array[$shipment_id][] = $job_status_array;
        
        update_post_meta($post_id, 'gophr_shipment_tracking_array', $gophr_shipment_tracking_array);
        
        echo '{"status":"success"}';
        
        // if job was created from here but confirmed from webapp
        $created_shipments_details_array = get_post_meta($post_id, 'gophr_created_shipments_details_array', true);
        if (! empty($created_shipments_details_array)) {
            $confirmed_shipment_details_array = get_post_meta($post_id, 'gophr_confirmed_shipment_details_array', true);
            if ( ! isset($confirmed_shipment_details_array[$shipment_id]) || empty($confirmed_shipment_details_array[$shipment_id]) ) {
                $confirmed_shipment_details_array[$shipment_id] = $job_status_array;
                update_post_meta($post_id, 'gophr_confirmed_shipment_details_array', $confirmed_shipment_details_array);
            }
        }
        
        exit;
    }
    
    protected function create_shipment_form($order) {
        global $post;
        
        $shipment_id = '';
        //$package = $this->gophr_create_package($order);
        $package_data = $this->gophr_get_package_data($order);
        
        $shipping_times = $this->gophr_pickup_delivery_time($order);
        $pickup_time = $shipping_times['pickup'];
        $delivery_time = $shipping_times['deliver'];

        $default_vehicle_type = 0;
        
        $download_url = admin_url('/?gophr_shipment_confirm=' . base64_encode($shipment_id . '|' . $post->ID));

        echo '<strong>'. __('Step 1: Initiate your shipment.','gophr-woocommerce-shipping').'</strong></br>';

        //echo 'Select Preferred Service: 
        //    <img class="help_tip" style="float:none;" data-tip="'
        //    . __('Contact Gophr for more info on this services.', 'gophr-woocommerce-shipping') 
        //    . '" src="'. WC()->plugin_url() .'/assets/images/help.png" height="16" width="16" />';

        echo '<ul>';
        
        echo '<li>Delivery Date: &nbsp; <input type="text" class="date-picker" name="pickup_date" id="pickup_date" maxlength="10" size="10" value="'. date_i18n( 'Y-m-d', $pickup_time ) .'" pattern="[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])" /></li>';
        
        echo '<li class="wide">Vehicle <select class="select" id="gophr_vehicle_type" name="gophr_vehicle_type">';
        foreach ($this->gophr_vehicle_type as $vehicle_code => $vehicle_name) {
            echo '<option value="' . $vehicle_code . '" ' . selected($default_vehicle_type,
                    $vehicle_code) . ' >' . $vehicle_name . '</option>';
        }
        echo '</select></li>';
        
        echo '  <li class="" style="line-height: 31px;">PickUp: &nbsp;<input type="checkbox" id="pickup_anytime" name="pickup_anytime" checked="true" value="1" onclick="jQuery(\'#pickup_time\').toggle();" > Anytime<img class="help_tip" style="float:none;margin-left:0;" data-tip="'. __('Earliest pickup time:- package is ready to be picked up.','gophr-woocommerce-shipping') .'" src="'. WC()->plugin_url() .'/assets/images/help.png" height="16" width="16" />';
        
            echo '<span id="pickup_time" style="display: none;">@ <input type="text" class="hour" placeholder="'. esc_attr__( 'h', 'woocommerce' ) .'" name="pickup_hour" id="pickup_hour" maxlength="2" size="2" value="'. date_i18n( 'H', ($pickup_time) ) .'" pattern="\-?\d+(\.\d{0,})?" />:<input type="text" class="minute" placeholder="'. esc_attr__( 'm', 'woocommerce' ) .'" name="pickup_minute" id="pickup_minute" maxlength="2" size="2" value="'. date_i18n( 'i', $pickup_time ) .'" pattern="\-?\d+(\.\d{0,})?" /></span></li>';

        echo '<li class="" style="line-height: 31px;">Deliver: <input type="checkbox" id="delivery_anytime" name="delivery_anytime" checked="true" value="1" onclick="jQuery(\'#delivery_time\').toggle();"  > Anytime<img class="help_tip" style="float:none;margin-left:0;" data-tip="'. __('Delivery Deadline:- Leave it blank if there is no rush which can be bit costly.','gophr-woocommerce-shipping') .'" src="'. WC()->plugin_url() .'/assets/images/help.png" height="16" width="16" />';
                echo '<span id="delivery_time" style="display: none;">@ <input type="text" class="hour" placeholder="'. esc_attr__( 'h', 'woocommerce' ) .'" name="delivery_hour" id="delivery_hour" maxlength="2" size="2" value="'. date_i18n( 'H', $delivery_time ) .'" pattern="\-?\d+(\.\d{0,})?" />:<input type="text" class="minute" placeholder="'. esc_attr__( 'm', 'woocommerce' ) .'" name="delivery_minute" id="delivery_minute" maxlength="2" size="2" value="00" pattern="\-?\d+(\.\d{0,})?" /></span></li>';
        
        if ($this->manual_weight_dimensions == 'yes') {
            echo '<li style="width: 226px;">
                <strong>Weight:&nbsp;</strong><input type="text" id="manual_weight" name="manual_weight" value="'. $package_data['weight'] .'" size="3" />&nbsp;'. $this->weight_unit .'<br>     
                <strong>&nbsp;Height:&nbsp;</strong><input type="text" id="manual_height" name="manual_height" value="'. $package_data['size_z'] .'" size="3" />&nbsp;'. $this->dim_unit .'<br>
                <strong>&nbsp;&nbsp;Width:&nbsp;</strong><input type="text" id="manual_width" name="manual_width" value="'. $package_data['size_y'] .'" size="3" />&nbsp;'. $this->dim_unit .'<br>
                <strong>Length:&nbsp;</strong><input type="text" id="manual_length" name="manual_length" value="'. $package_data['size_x'] .'" size="3" />&nbsp;'. $this->dim_unit .'
            </li>';
            echo '<input type="hidden" id="manual_quantity" name="manual_quantity" value="'. $package_data['quantity'] .'" />';
        }
        echo '<a class="button button-primary tips gophr_create_shipment" href="'. $download_url .'" data-tip="'. __('Initiate Shipment', 'gophr-woocommerce-shipping') .'">'. __('Initiate Shipment', 'gophr-woocommerce-shipping') .'</a><hr style="border-color:#0074a2">';
        ?>

        <script type="text/javascript">
            jQuery("a.gophr_create_shipment").on("click", function () {
                pickup_time = delivery_time = '';
                if (! jQuery('#pickup_anytime').checked) {
                    pickup_time = jQuery('#pickup_hour').val() +':'+ jQuery('#pickup_minute').val()
                }
                if (! jQuery('#delivery_anytime').checked) {
                    delivery_time = jQuery('#delivery_hour').val() +':'+ jQuery('#delivery_minute').val()
                }
                jQuery('#deliver_hour').val()
                jQuery('#deliver_minute').val()
                location.href = this.href + '&weight=' + jQuery('#manual_weight').val() +
                        '&length=' + jQuery('#manual_length').val()
                        + '&width=' + jQuery('#manual_width').val()
                        + '&height=' + jQuery('#manual_height').val()
                        + '&height=' + jQuery('#manual_height').val()
                        + '&quantity=' + jQuery('#manual_quantity').val()
                        + '&pickup_date=' + jQuery('#pickup_date').val()
                        + '&pickup_time=' + pickup_time
                        + '&delivery_time=' + delivery_time;
                        + '&vehicle_type=' + jQuery('#gophr_vehicle_type').val()
                return false;
            });
        </script>
        <?php
    }
}
new Gophr_Shipping_Admin();
