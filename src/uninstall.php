<?php
/**
 * Uninstall WooCommerce Gateway Alma plugin
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once dirname( __FILE__ ) . '/includes/class-alma-wc-settings.php';
delete_option( Alma_WC_Settings::OPTIONS_KEY );
