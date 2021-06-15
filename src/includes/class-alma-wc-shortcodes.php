<?php
/**
 * Alma payments plugin for WooCommerce
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Class Wc_Alma_Shortcodes
 */
class Alma_Wc_Shortcodes {
	const CART_SHORTCODE_TAG    = 'alma-cart-eligibility';
	const PRODUCT_SHORTCODE_TAG = 'alma-product-eligibility';

	/**
	 * Init cart widget shortcode
	 *
	 * @param Alma_WC_Cart_Handler $handler as cart widget handler.
	 *
	 * @return void
	 */
	public function init_cart_widget_shortcode( Alma_WC_Cart_Handler $handler ) {
		add_shortcode(
			self::CART_SHORTCODE_TAG,
			function ( $atts, $content = '' ) use ( $handler ) {
				return $this->alma_cart_widget( $handler, $atts, $content );
			}
		);
	}

	/**
	 * Alma Cart Widget Shortcode
	 *
	 * @param Alma_WC_Cart_Handler $handler Cart handler.
	 * @param array|string         $atts Shortcode attributes.
	 * @param string               $content Shortcode content ([sc]content[/sc]).
	 *
	 * @return string the rendered cart eligibility widget to inject
	 */
	private function alma_cart_widget( Alma_WC_Cart_Handler $handler, $atts, $content = '' ) {

		if ( $handler->is_already_rendered() ) {

			return $this->render_empty( self::CART_SHORTCODE_TAG, $handler->get_eligibility_widget_already_rendered_message(), $atts, $content );
		}

		ob_start();
		$handler->display_cart_eligibility();

		return $this->render( self::CART_SHORTCODE_TAG, $atts, ob_get_clean(), $content );
	}

	/**
	 * Render default widget template
	 *
	 * @param string $shortcode_tag as shortcode name.
	 * @param mixed  $atts as shortcode attributes.
	 * @param string $alma_content as HTML Alma Widget or debug content.
	 * @param string $shortcode_content Shortcode content ([sc]content[/sc]).
	 *
	 * @return string the rendered eligibility widget to inject
	 */
	private function render( $shortcode_tag, $atts, $alma_content, $shortcode_content = '' ) {
		$class         = isset( $atts['class'] ) ? $atts['class'] : '';
		$style         = '';
		$debug_content = '';
		if ( $this->is_debug( $atts ) ) {
			$class        .= ' debug';
			$style         = 'border:red solid;padding: 0px 10px 10px 10px';
			$style_title   = 'color:red;font-size:12px;margin:-3px 0px 0px -13px;border:red solid;padding-left:3px;max-width:200px;';
			$debug_content = sprintf( '<div style="%s">%s</div>', $style_title, $shortcode_tag );
		}

		return sprintf( '<div class="%s %s" style="%s">%s<div class="alma_wc_content">%s</div>%s</div>', $shortcode_tag, $class, $style, $debug_content, do_shortcode( $shortcode_content ), $alma_content );

	}

	/**
	 * Render default widget template with empty content if debug is not configured into shortcode
	 *
	 * @param string $shortcode_tag as shortcode name.
	 * @param string $content as HTML Alma Widget or debug content.
	 * @param mixed  $atts as shortcode attributes.
	 * @param string $shortcode_content Shortcode content ([sc]content[/sc]).
	 *
	 * @return string
	 */
	private function render_empty( $shortcode_tag, $content, $atts, $shortcode_content = '' ) {
		if ( $this->is_debug( $atts ) ) {
			return $this->render( $shortcode_tag, $atts, $content, $shortcode_content );
		}

		return $this->render( $shortcode_tag, $atts, '', $shortcode_content );
	}

	/**
	 * Init product widget shortcode
	 *
	 * @param Alma_WC_Product_Handler $handler as product widget handler.
	 *
	 * @return void
	 */
	public function init_product_widget_shortcode( Alma_WC_Product_Handler $handler ) {
		add_shortcode(
			self::PRODUCT_SHORTCODE_TAG,
			function ( $atts, $content = '' ) use ( $handler ) {
				return $this->alma_product_widget( $handler, $atts, $content );
			}
		);
	}

	/**
	 * Alma Product Widget Shortcode
	 *
	 * @param Alma_WC_Product_Handler $handler Cart handler.
	 * @param array|string            $atts Shortcode attributes.
	 * @param string                  $content Shortcode content ([sc]content[/sc]).
	 *
	 * @return string the rendered product eligibility widget to inject
	 */
	private function alma_product_widget( Alma_WC_Product_Handler $handler, $atts, $content = '' ) {

		if ( $handler->is_already_rendered() ) {
			return $this->render_empty( self::PRODUCT_SHORTCODE_TAG, $handler->get_eligibility_widget_already_rendered_message(), $atts, $content );
		}

		$product = wc_get_product( isset( $atts['id'] ) ? $atts['id'] : false );

		if ( ! $product ) {
			/* translators: %s: #product_id (if any) */
			$product_not_found_content = sprintf( __( 'Product%s not found - Not displaying Alma', 'alma-woocommerce-gateway' ), isset( $atts['id'] ) ? ' with id #' . $atts['id'] : '' );

			return $this->render_empty( self::PRODUCT_SHORTCODE_TAG, $product_not_found_content, $atts, $content );
		}

		ob_start();
		$handler->inject_payment_plan( $product );

		return $this->render( self::PRODUCT_SHORTCODE_TAG, $atts, ob_get_clean(), $content );
	}

	/**
	 * Define if debug is given in shortcode attributes and if value is equivalent to true
	 *
	 * @param mixed $atts as shortcode attributes.
	 *
	 * @return bool
	 */
	private function is_debug( $atts ) {
		return isset( $atts['debug'] ) && ( filter_var( $atts['debug'], FILTER_VALIDATE_BOOLEAN ) );
	}

}
