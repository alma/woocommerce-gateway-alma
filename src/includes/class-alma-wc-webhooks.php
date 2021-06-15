<?php
/**
 * Alma webhooks
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles settings retrieval from the settings API.
 */
class Alma_WC_Webhooks {
	const CUSTOMER_RETURN = 'alma_customer_return';
	const IPN_CALLBACK    = 'alma_ipn_callback';

	/**
	 * Get webhook url
	 *
	 * @param string $webhook Webhook.
	 *
	 * @return string
	 */
	public static function url_for( $webhook ) {
		return wc()->api_request_url( $webhook );
	}

	/**
	 * Get webhook action.
	 *
	 * @param string $webhook Webhook.
	 *
	 * @return string
	 */
	public static function action_for( $webhook ) {
		return "woocommerce_api_$webhook";
	}
}
