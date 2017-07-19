<?php
/*
    Plugin Name: Gophr WooCommerce Shipping
    Plugin URI: https://www.gophr.com
    Description: Obtain Real time shipping rates and Track Shipment via the Gophr Shipping API.
    Version: 1.1.0
    Author: Gophr
    Author URI: http://www.gophr.com
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Required functions
if ( ! function_exists( 'gophr_is_woocommerce_active' ) ) {
    require_once( 'gophr-includes/gophr-functions.php' );
}

// WC active check
if ( ! gophr_is_woocommerce_active() ) {
    return;
}

define("GOPHR_ID", "gophr_shipping");

/**
 * WC class
 */
class Gophr_WooCommerce_Shipping {

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'gophr_plugin_action_links' ) );
        add_action( 'woocommerce_shipping_init', array( $this, 'gophr_shipping_init') );
        add_filter( 'woocommerce_shipping_methods', array( $this, 'gophr_add_method') );
        add_action( 'admin_enqueue_scripts', array( $this, 'gophr_scripts') );
    }

    public function init() {
        include_once ( 'includes/class-gophr-shipping-admin.php' );

        // Localisation
        //load_plugin_textdomain( 'gophr-woocommerce-shipping', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Plugin page links
     */
    public function gophr_plugin_action_links( $links ) {
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=shipping&section=gophr_shipping' ) . '">' . __( 'Settings', 'gophr-woocommerce-shipping' ) . '</a>',
            '<a href="https://developers.gophr.com">' . __( 'Support', 'gophr-woocommerce-shipping' ) . '</a>',
        );
        return array_merge( $plugin_links, $links );
    }

    /**
     * wc_init function.
     *
     * @access public
     * @return void
     */
    function gophr_shipping_init() {
        include_once( 'includes/class-gophr-shipping.php' );
    }

    /**
     * wc_add_method function.
     *
     * @access public
     * @param mixed $methods
     * @return void
     */
    function gophr_add_method( $methods ) {
        $methods[] = 'Gophr_Shipping';
        return $methods;
    }

    /**
     * wc_scripts function.
     *
     * @access public
     * @return void
     */
    function gophr_scripts() {
        wp_enqueue_script( 'jquery-ui-sortable' );
    }
}
new Gophr_WooCommerce_Shipping();
