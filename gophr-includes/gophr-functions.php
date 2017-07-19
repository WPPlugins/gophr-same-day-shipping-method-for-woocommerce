<?php
/**
 * Functions used by plugins
 */
if ( ! class_exists( 'Gophr_Dependencies' ) )
	require_once 'class-gophr-dependencies.php';

/**
 * WC Detection
 */
if ( ! function_exists( 'gophr_is_woocommerce_active' ) ) {
	function gophr_is_woocommerce_active() {
		return Gophr_Dependencies::wc_active_check();
	}
}