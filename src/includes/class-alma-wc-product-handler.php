<?php
/**
 * Alma product handler
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Alma_WC_Product_Handler
 */
class Alma_WC_Product_Handler extends Alma_WC_Generic_Handler {
	const JQUERY_VARIABLE_PRODUCT_UPDATE_EVENT = 'check_variations';

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		parent::__construct();

		if ( 'yes' === alma_wc_plugin()->settings->display_product_eligibility ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'inject_payment_plan' ), 29 );
		}
	}

	/**
	 * Return the default CSS selector for the price element of variable products, depending on the version of
	 * WooCommerce, as WooCommerce 4.4.0 added a `<bdi>` wrapper around the price.
	 *
	 * @return string
	 */
	public static function default_variable_price_selector() {
		$selector = 'form.variations_form div.woocommerce-variation-price span.woocommerce-Price-amount';
		if ( version_compare( wc()->version, '4.4.0', '>=' ) ) {
			$selector .= ' bdi';
		}

		return $selector;
	}

	/**
	 * Display payment plan below the 'add to cart' button to indicate whether Alma is available or not
	 *
	 * @param mixed $the_product Post object or post ID of the product.
	 */
	public function inject_payment_plan( $the_product = false ) {
		$has_excluded_products = false;

		$product = ( $the_product ) ? wc_get_product( $the_product ) : wc_get_product();
		if (
				isset( alma_wc_plugin()->settings->excluded_products_list ) &&
				is_array( alma_wc_plugin()->settings->excluded_products_list ) &&
				count( alma_wc_plugin()->settings->excluded_products_list ) > 0
		) {
			$product_id = $product->get_id();

			if ( $this->is_product_excluded( $product_id ) ) {
				$has_excluded_products = true;
			}
		}

		if ( ! $product->is_in_stock() ) {
			return;
		}
		$price = $product->get_price_including_tax();
		if ( ! $price ) {
			return;
		}
		$amount_query_selector = null;
		$jquery_update_event   = null;
		$first_render          = true;

		$is_variable_product = $product->get_type() === 'variable';

		if ( $is_variable_product ) {
			$amount_query_selector = alma_wc_plugin()->settings->variable_product_price_query_selector;
			$jquery_update_event   = self::JQUERY_VARIABLE_PRODUCT_UPDATE_EVENT;
			$first_render          = true;
		}

		$this->inject_payment_plan_widget(
			$has_excluded_products,
			alma_wc_price_to_cents( $price ),
			$jquery_update_event,
			$amount_query_selector,
			$first_render
		);
	}
}
