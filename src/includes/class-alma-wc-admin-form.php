<?php
/**
 * Alma WooCommerce payment gateway
 *
 * @package Alma_WooCommerce_Gateway
 * @noinspection HtmlUnknownTarget
 */

use Alma\API\RequestError;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Alma_WC_Admin_Form
 */
class Alma_WC_Admin_Form {

	/**
	 * Singleton static property
	 *
	 * @var self
	 */
	private static $instance;

	/**
	 * Admin Form fields initialisation
	 *
	 * @return array
	 */
	public static function init_form_fields() {

		$need_api_key     = alma_wc_plugin()->settings->need_api_key();
		$default_settings = Alma_WC_Settings::default_settings();

		if ( $need_api_key ) {
			return array_merge(
				self::get_instance()->init_enabled_field( $default_settings ),
				self::get_instance()->init_api_key_fields( __( '→ Start by filling in your API keys', 'alma-woocommerce-gateway' ), $default_settings ),
				self::get_instance()->init_debug_fields( $default_settings )
			);
		}
		return array_merge(
			self::get_instance()->init_enabled_field( $default_settings ),
			self::get_instance()->init_fee_plans_fields( $default_settings ),
			self::get_instance()->init_general_settings_fields( $default_settings ),
			self::get_instance()->init_api_key_fields( __( '→ API configuration', 'alma-woocommerce-gateway' ), $default_settings ),
			self::get_instance()->init_debug_fields( $default_settings )
		);
	}

	/**
	 * Init a fee_plan's fields
	 *
	 * @param array $fee_plan as fee plan definitions.
	 * @param array $default_settings as default settings definitions.
	 * @param bool  $selected if this field is currently selected.
	 *
	 * @return array[] as field_form definition
	 */
	private function init_fee_plan_fields( $fee_plan, $default_settings, $selected ) {
		$installments          = $fee_plan['installments_count'];
		$key                   = $installments . 'x';
		$class                 = "alma_fee_plan_{$key} alma_fee_plan";
		$css                   = $selected ? '' : 'display: none;';
		$default_min_amount    = alma_wc_price_from_cents( $fee_plan['min_purchase_amount'] );
		$default_max_amount    = alma_wc_price_from_cents( $fee_plan['max_purchase_amount'] );
		$merchant_fee_fixed    = alma_wc_price_from_cents( $fee_plan['merchant_fee_fixed'] );
		$merchant_fee_variable = $fee_plan['merchant_fee_variable'] / 100; // percent.
		$customer_fee_fixed    = alma_wc_price_from_cents( $fee_plan['customer_fee_fixed'] );
		$customer_fee_variable = $fee_plan['customer_fee_variable'] / 100; // percent.

		return array(
			"${installments}x_section"    => array(
				// translators: %d: number of installments.
				'title'             => sprintf( __( '→ %d-installment payment', 'alma-woocommerce-gateway' ), $fee_plan['installments_count'] ),
				'type'              => 'title',
				'description'       => $this->generate_fee_plan_description( $installments, $default_min_amount, $default_max_amount, $merchant_fee_fixed, $merchant_fee_variable, $customer_fee_fixed, $customer_fee_variable ),
				'class'             => $class,
				'description_class' => $class,
				'table_class'       => $class,
				'css'               => $css,
				'description_css'   => $css,
				'table_css'         => $css,
			),
			"enabled_${installments}x"    => array(
				'title'   => __( 'Enable/Disable', 'alma-woocommerce-gateway' ),
				'type'    => 'checkbox',
				// translators: %d: number of installments.
				'label'   => sprintf( __( 'Enable %d-installment payments with Alma', 'alma-woocommerce-gateway' ), $installments ),
				'default' => $default_settings[ "enabled_${installments}x" ],
			),
			"min_amount_${installments}x" => array(
				'title'             => __( 'Minimum amount', 'alma-woocommerce-gateway' ),
				'type'              => 'number',
				'css'               => 'width: 100px;',
				'custom_attributes' => array(
					'required' => 'required',
					'min'      => $default_min_amount,
					'max'      => $default_max_amount,
					'step'     => 0.01,
				),
				'default'           => $default_min_amount,
			),
			"max_amount_${installments}x" => array(
				'title'             => __( 'Maximum amount', 'alma-woocommerce-gateway' ),
				'type'              => 'number',
				'css'               => 'width: 100px;',
				'custom_attributes' => array(
					'required' => 'required',
					'min'      => $default_min_amount,
					'max'      => $default_max_amount,
					'step'     => 0.01,
				),
				'default'           => $default_max_amount,
			),
		);
	}

	/**
	 * Init enabled Admin field
	 *
	 * @param array $default_settings as default settings.
	 *
	 * @return array[]
	 */
	private function init_enabled_field( $default_settings ) {
		return array(
			'enabled' => array(
				'title'   => __( 'Enable/Disable', 'alma-woocommerce-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable monthly payments with Alma', 'alma-woocommerce-gateway' ),
				'default' => $default_settings['enabled'],
			),
		);
	}

	/**
	 * Init test & live api keys fields
	 *
	 * @param string $keys_title as section title.
	 * @param array  $default_settings as default settings.
	 *
	 * @return array[]
	 */
	private function init_api_key_fields( $keys_title, $default_settings ) {

		return array(
			'keys_section' => array(
				'title'       => '<hr>' . $keys_title,
				'type'        => 'title',
				/* translators: %s Alma security URL */
				'description' => sprintf( __( 'You can find your API keys on <a href="%s" target="_blank">your Alma dashboard</a>', 'alma-woocommerce-gateway' ), alma_wc_plugin()->get_alma_dashboard_url( 'security' ) ),
			),
			'live_api_key' => array(
				'title' => __( 'Live API key', 'alma-woocommerce-gateway' ),
				'type'  => 'text',
			),
			'test_api_key' => array(
				'title' => __( 'Test API key', 'alma-woocommerce-gateway' ),
				'type'  => 'text',
			),
			'environment'  => array(
				'title'       => __( 'API Mode', 'alma-woocommerce-gateway' ),
				'type'        => 'select',
				'description' => __( 'Use <b>Test</b> mode until you are ready to take real orders with Alma<br>In Test mode, only admins can see Alma on cart/checkout pages.', 'alma-woocommerce-gateway' ),
				'default'     => $default_settings['environment'],
				'options'     => array(
					'test' => __( 'Test', 'alma-woocommerce-gateway' ),
					'live' => __( 'Live', 'alma-woocommerce-gateway' ),
				),
			),
		);
	}

	/**
	 * Init all allowed fee plans admin field
	 *
	 * @param array $default_settings as default settings.
	 *
	 * @return array|array[]
	 */
	private function init_fee_plans_fields( $default_settings ) {
		$fee_plans_fields  = array();
		$select_options    = array();
		$selected_fee_plan = $default_settings['selected_fee_plan'];
		$title_field       = array(
			'fee_plan_section' => array(
				'title' => '<hr>' . __( '→ Fee plans configuration', 'alma-woocommerce-gateway' ),
				'type'  => 'title',
			),
		);
		try {
			$merchant       = alma_wc_plugin()->get_alma_client()->merchants->me();
			$fee_plans      = $merchant->fee_plans;
			$select_options = $this->generate_select_options( $fee_plans );
			if ( count( $select_options ) === 0 ) {
				/* translators: %s: Alma conditions URL */
				$title_field['fee_plan_section']['description'] = sprintf( __( '⚠ There is no fee plan allowed in your <a href="%s" target="_blank">Alma dashboard</a>.', 'alma-woocommerce-gateway' ), alma_wc_plugin()->get_alma_dashboard_url( 'conditions' ) );

				return $title_field;
			}
			$selected_fee_plan = $this->generate_selected_free_plan_key( $select_options, $default_settings );
			foreach ( $fee_plans as $fee_plan ) {
				if ( $fee_plan['allowed'] ) {
					$fee_plan_key     = $fee_plan['installments_count'] . 'x';
					$fee_plans_fields = array_merge(
						$fee_plans_fields,
						$this->init_fee_plan_fields( $fee_plan, $default_settings, $selected_fee_plan === $fee_plan_key )
					);
				}
			}
		} catch ( RequestError $e ) {
			alma_wc_plugin()->handle_settings_exception( $e );
		}

		return array_merge(
			$title_field,
			array(
				'selected_fee_plan' => array(
					'title'       => __( 'Select a fee plan to update', 'alma-woocommerce-gateway' ),
					'type'        => 'select_alma_fee_plan',
					/* translators: %s: Alma conditions URL */
					'description' => sprintf( __( 'Choose which fee plan you want to modify<br>(only your <a href="%s" target="_blank">Alma dashboard</a> available fee plans are shown here).', 'alma-woocommerce-gateway' ), alma_wc_plugin()->get_alma_dashboard_url( 'conditions' ) ),
					'default'     => $selected_fee_plan,
					'options'     => $select_options,
					'fee_plans'   => $fee_plans_fields,
				),
			)
		);
	}

	/**
	 * Init default plugin fields
	 *
	 * @param array $default_settings as default settings.
	 *
	 * @return array
	 */
	private function init_general_settings_fields( array $default_settings ) {
		return array(
			'general_section'                       => array(
				'title' => '<hr>' . __( '→ General configuration', 'alma-woocommerce-gateway' ),
				'type'  => 'title',
			),
			'title'                                 => array(
				'title'       => __( 'Title', 'alma-woocommerce-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the payment method name which the user sees during checkout.', 'alma-woocommerce-gateway' ),
				'default'     => $default_settings['title'],
				'desc_tip'    => true,
			),
			'description'                           => array(
				'title'       => __( 'Description', 'alma-woocommerce-gateway' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => __( 'This controls the payment method description which the user sees during checkout.', 'alma-woocommerce-gateway' ),
				'default'     => $default_settings['description'],
			),
			'display_product_eligibility'           => array(
				'title'   => __( 'Product eligibility notice', 'alma-woocommerce-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Display a message about product eligibility for monthly payments', 'alma-woocommerce-gateway' ),
				'default' => $default_settings['display_product_eligibility'],
			),
			'variable_product_price_query_selector' => array(
				'title'       => __( 'Variable products price query selector', 'alma-woocommerce-gateway' ),
				'type'        => 'text',
				'description' => __( 'Query selector used to get the price of product with variations', 'alma-woocommerce-gateway' ),
				'desc_tip'    => true,
				'default'     => $default_settings['variable_product_price_query_selector'],
			),
			'display_cart_eligibility'              => array(
				'title'   => __( 'Cart eligibility notice', 'alma-woocommerce-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Display a message about cart eligibility for monthly payments', 'alma-woocommerce-gateway' ),
				'default' => $default_settings['display_cart_eligibility'],
			),
			'excluded_products_list'                => array(
				'title'       => __( 'Excluded product categories', 'alma-woocommerce-gateway' ),
				'type'        => 'multiselect',
				'description' => __( 'Exclude all virtual/downloadable product categories, as you cannot sell them with Alma', 'alma-woocommerce-gateway' ),
				'desc_tip'    => true,
				'css'         => 'height: 150px;',
				'options'     => $this->generate_categories_options(),
			),
			'cart_not_eligible_message_gift_cards'  => array(
				'title'       => __( 'Non-eligibility message for excluded products', 'alma-woocommerce-gateway' ),
				'type'        => 'text',
				'description' => __( 'Message displayed below the cart totals when it contains excluded products', 'alma-woocommerce-gateway' ),
				'desc_tip'    => true,
				'default'     => $default_settings['cart_not_eligible_message_gift_cards'],
			),
		);
	}

	/**
	 * Init debug fields
	 *
	 * @param array $default_settings as default settings.
	 *
	 * @return array
	 */
	private function init_debug_fields( $default_settings ) {
		return array(
			'debug_section' => array(
				'title' => '<hr>' . __( '→ Debug options', 'alma-woocommerce-gateway' ),
				'type'  => 'title',
			),
			'debug'         => array(
				'title'       => __( 'Debug mode', 'alma-woocommerce-gateway' ),
				'type'        => 'checkbox',
				// translators: %s: Admin logs url.
				'label'       => __( 'Activate debug mode', 'alma-woocommerce-gateway' ) . sprintf( __( ' (<a href="%s">Go to logs</a>)', 'alma-woocommerce-gateway' ), alma_wc_plugin()->get_admin_logs_url() ),
				'description' => __( 'Enable logging info and errors to help debug any issue with the plugin', 'alma-woocommerce-gateway' ),
				'desc_tip'    => true,
				'default'     => $default_settings['debug'],
			),
		);
	}

	/**
	 * Alma_WC_Admin_Form constructor.
	 */
	private function __construct() {}

	/**
	 * Singleton static method
	 *
	 * @return Alma_WC_Admin_Form
	 */
	private static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Product categories options.
	 *
	 * @return array
	 */
	private function generate_categories_options() {
		$product_categories = get_terms(
			'product_cat',
			array(
				'orderby'    => 'name',
				'order'      => 'asc',
				'hide_empty' => false,
			)
		);

		$options = array();
		foreach ( $product_categories as $category ) {
			$options[ $category->slug ] = $category->name;
		}

		return $options;
	}

	/**
	 * Get fee plan description.
	 *
	 * @param int   $installments Number of installments.
	 * @param float $min_amount Min amount.
	 * @param float $max_amount Max amount.
	 * @param float $merchant_fee_fixed Merchant fee fixed.
	 * @param float $merchant_fee_variable Merchant fee variable.
	 * @param float $customer_fee_fixed Customer fee fixed.
	 * @param float $customer_fee_variable Customer fee variable.
	 *
	 * @return string
	 */
	private function generate_fee_plan_description(
		$installments,
		$min_amount,
		$max_amount,
		$merchant_fee_fixed,
		$merchant_fee_variable,
		$customer_fee_fixed,
		$customer_fee_variable
	) {
		$you_can_offer = sprintf(
			// translators: %d: number of installments.
			__( 'You can offer %1$d-installment payments for amounts between <b>%2$d€</b> and <b>%3$d€</b>.', 'alma-woocommerce-gateway' ),
			$installments,
			$min_amount,
			$max_amount
		);
		$fees_applied  = __( 'Fees applied to each transaction for this plan:', 'alma-woocommerce-gateway' );
		$you_pay       = $this->generate_fee_to_pay_description( __( 'You pay:', 'alma-woocommerce-gateway' ), $merchant_fee_variable, $merchant_fee_fixed );
		$customer_pays = $this->generate_fee_to_pay_description( __( 'Customer pays:', 'alma-woocommerce-gateway' ), $customer_fee_variable, $customer_fee_fixed );

		return sprintf( '<p>%s<br>%s %s %s</p>', $you_can_offer, $fees_applied, $you_pay, $customer_pays );
	}

	/**
	 * Generate a string with % + € OR only % OR only € (depending on parameters given)
	 * If all fees are <= 0 : return an empty string
	 *
	 * @param string $translation as description prefix.
	 * @param float  $fee_variable as variable amount (if any).
	 * @param float  $fee_fixed as fixed amount (if any).
	 *
	 * @return string
	 */
	private function generate_fee_to_pay_description( $translation, $fee_variable, $fee_fixed ) {
		if ( ! $fee_variable && ! $fee_fixed ) {
			return '';
		}

		$fees = '';
		if ( $fee_variable ) {
			$fees .= $fee_variable . '%';
		}

		if ( $fee_fixed ) {
			if ( $fee_variable ) {
				$fees .= ' + ';
			}
			$fees .= $fee_fixed . '€';
		}

		return sprintf( '<br><b>%s</b> %s', $translation, $fees );
	}

	/**
	 * Generate select options key values for allowed fee_plans
	 *
	 * @param array $fee_plans as merchant fee_plans.
	 *
	 * @return array
	 */
	private function generate_select_options( $fee_plans ) {
		$select_options = array();
		foreach ( $fee_plans as $fee_plan ) {
			if ( $fee_plan['allowed'] ) {
				$option_key = $fee_plan['installments_count'] . 'x';
				// translators: %d: number of installments.
				$select_options[ $option_key ] = sprintf( __( '→ %d-installment payment', 'alma-woocommerce-gateway' ), $fee_plan['installments_count'] );
			}
		}
		return $select_options;
	}

	/**
	 * Generate the selected option for current fee_plan_keys options
	 *
	 * @param array $select_options as key,value allowed fee_plan options.
	 * @param array $default_settings as default settings.
	 *
	 * @return string
	 */
	private function generate_selected_free_plan_key( array $select_options, $default_settings ) {
		$selected_fee_plan   = alma_wc_plugin()->settings->selected_fee_plan ? alma_wc_plugin()->settings->selected_fee_plan : $default_settings['selected_fee_plan'];
		$select_options_keys = array_keys( $select_options );

		return in_array( $selected_fee_plan, $select_options_keys, true ) ? $selected_fee_plan : $select_options_keys[0];
	}

}
