<?php
/**
 * Alma generic handler
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Alma_WC_Generic_Handler
 */
class Alma_WC_Generic_Handler {

	/**
	 * Logger
	 *
	 * @var Alma_WC_Logger
	 */
	private $logger;

	/**
	 * Plugin settings
	 *
	 * @var Alma_WC_Settings
	 */
	protected $settings;

	/**
	 * Has any child handler already rendered the widget in this page
	 *
	 * @var bool
	 */
	private static $is_already_rendered = false;

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		$this->logger   = new Alma_WC_Logger();
		$this->settings = alma_wc_plugin()->settings;
	}

	/**
	 * Has any child handler already rendered the widget in this page
	 *
	 * @return bool as rendered widget state.
	 */
	public function is_already_rendered() {

		return self::$is_already_rendered;
	}

	/**
	 * Check whether we're in a situation where we can inject JS for our payment plan widget
	 *
	 * @return bool
	 */
	private function is_usable() {
		if ( ! $this->settings->is_usable() ) {
			return false;
		}

		if ( ! alma_wc_plugin()->check_locale() || ! alma_wc_plugin()->check_currency() ) {
			return false;
		}

		if ( ! count( $this->settings->enabled_pnx_plans() ) ) {
			$this->logger->info( 'No payment plans have been activated - Not displaying Alma' );
			return false;
		}

		return true;
	}

	/**
	 * Inject payment plan.
	 *
	 * @param bool        $has_excluded_products Skip payment plan injection if true.
	 * @param int         $amount Amount.
	 * @param string|null $jquery_update_event Jquery update event.
	 * @param string|null $amount_query_selector Amount query selector.
	 * @param bool        $first_render First render.
	 *
	 * @return void
	 * @TODO verify if amount_query_selector is really useful
	 */
	protected function inject_payment_plan_widget(
		$has_excluded_products,
		$amount = 0,
		$jquery_update_event = null,
		$amount_query_selector = null,
		$first_render = true
	) {
		if ( $this->is_already_rendered() ) {
			$this->logger->info( $this->get_eligibility_widget_already_rendered_message() );
			return;
		}

		if ( ! $this->is_usable() ) {
			return;
		}

		$merchant_id = $this->settings->merchant_id;
		if ( empty( $merchant_id ) ) {
			return;
		}

		$enabled_plans = $this->settings->enabled_pnx_plans();
		$api_mode      = $this->settings->environment;

		$widget_settings = array(
			'hasExcludedProducts' => $has_excluded_products,
			'merchantId'          => $merchant_id,
			'apiMode'             => $api_mode,
			'amount'              => $amount,
			'enabledPlans'        => $enabled_plans,
			'amountQuerySelector' => $amount_query_selector,
			'jqueryUpdateEvent'   => $jquery_update_event,
			'firstRender'         => $first_render,
			'decimalSeparator'    => wc_get_price_decimal_separator(),
			'thousandSeparator'   => wc_get_price_thousand_separator(),
		);

		// Inject JS/CSS required for the eligibility/payment plans info display.
		$alma_widgets_js_url = 'https://unpkg.com/@alma/widgets@1.x.x/dist/alma-widgets.umd.js';
		wp_enqueue_script( 'alma-widgets', $alma_widgets_js_url, array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		$alma_widgets_css_url = 'https://unpkg.com/@alma/widgets@1.x.x/dist/alma-widgets.css';
		wp_enqueue_style( 'alma-widgets', $alma_widgets_css_url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		$alma_widgets_injection_url = alma_wc_plugin()->get_asset_url( 'js/alma-widgets-inject.js' );
		wp_enqueue_script( 'alma-widgets-injection', $alma_widgets_injection_url, array(), ALMA_WC_VERSION, true );

		?>
		<div style="margin: 15px 0; max-width: 350px">
			<div id="alma-payment-plans" data-settings="<?php echo esc_attr( wp_json_encode( $widget_settings ) ); ?>"></div>

			<?php
			if ( $has_excluded_products ) {
				$logo_url      = alma_wc_plugin()->get_asset_url( 'images/alma_logo.svg' );
				$exclusion_msg = $this->settings->cart_not_eligible_message_gift_cards;
				?>
				<img src="<?php echo esc_attr( $logo_url ); ?>"
					alt="Alma"
					style="width: auto !important; height: 25px !important; border: none !important; vertical-align: middle; display: inline-block;">
				<span style="text-transform: initial"><?php echo wp_kses_post( $exclusion_msg ); ?></span>
				<?php
			}
			?>
		</div>
		<?php
		self::$is_already_rendered = true;
	}

	/**
	 * Check if a given product is excluded.
	 *
	 * @param int $product_id Product Id.
	 *
	 * @return bool
	 */
	protected function is_product_excluded( $product_id ) {
		foreach ( alma_wc_plugin()->settings->excluded_products_list as $category_slug ) {
			if ( has_term( $category_slug, 'product_cat', $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A translated message about already rendered widget
	 *
	 * @return string|void
	 */
	public function get_eligibility_widget_already_rendered_message() {
		return __( 'Alma "Eligibility Widget" (cart or product) already rendered on this page - Not displaying Alma', 'alma-woocommerce-gateway' );
	}
}
