<?php
/**
 * Alma WooCommerce payment gateway
 *
 * @package Alma_WooCommerce_Gateway
 * @noinspection HtmlUnknownTarget
 */

use Alma\API\Endpoints\Results\Eligibility;
use Alma\API\RequestError;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
	return;
}

/**
 * Alma_WC_Payment_Gateway
 */
class Alma_WC_Payment_Gateway extends WC_Payment_Gateway {
	const GATEWAY_ID = 'alma';

	const ALMA_PAYMENT_PLAN_TABLE_ID_TEMPLATE = 'alma-payment-plan-table-%d-installments';
	const ALMA_PAYMENT_PLAN_TABLE_CSS_CLASS   = 'js-alma-payment-plan-table';

	/**
	 * Logger
	 *
	 * @var Alma_WC_Logger
	 */
	private $logger;

	/**
	 * Eligibilities
	 *
	 * @var array<int,Eligibility>|null
	 */
	private $eligibilities;

	/**
	 * __construct
	 */
	public function __construct() {
		$this->id                 = self::GATEWAY_ID;
		$this->has_fields         = true;
		$this->method_title       = __( 'Alma monthly payments', 'alma-woocommerce-gateway' );
		$this->method_description = __( 'Easily provide monthly payments to your customers, risk-free!', 'alma-woocommerce-gateway' );

		$this->logger = new Alma_WC_Logger();

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' )
		);
	}

	/**
	 * Get option from DB.
	 *
	 * Gets an option from the settings API, using defaults if necessary to prevent undefined notices.
	 * This is overridden so that values saved in cents in the DB can be shown in euros to the user.
	 *
	 * @param string $key Option key.
	 * @param mixed  $empty_value Value when empty.
	 *
	 * @return string The value specified for the option or a default value for the option.
	 */
	public function get_option( $key, $empty_value = null ) {
		$option = parent::get_option( $key, $empty_value );

		if ( in_array( $key, Alma_WC_Settings::AMOUNT_KEYS, true ) ) {
			return strval( alma_wc_price_from_cents( $option ) );
		}

		return $option;
	}

	/**
	 * Get Alma icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		$icon_url = alma_wc_plugin()->get_asset_url( 'images/alma_logo.svg' );
		$icon     = '<img src="' . WC_HTTPS::force_https_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" style="width: auto !important; height: 25px !important; border: none !important;">';

		return apply_filters( 'alma_wc_gateway_icon', $icon, $this->id );
	}

	/**
	 * Init admin settings form fields.
	 */
	public function init_form_fields() {
		$need_api_key = alma_wc_plugin()->settings->need_api_key();

		$default_settings = Alma_WC_Settings::default_settings();

		if ( $need_api_key ) {
			$keys_title = __( '→ Start by filling in your API keys', 'alma-woocommerce-gateway' );
		} else {
			$keys_title = __( '→ API configuration', 'alma-woocommerce-gateway' );
		}

		$enabled_option = array(
			'title'   => __( 'Enable/Disable', 'alma-woocommerce-gateway' ),
			'type'    => 'checkbox',
			'label'   => __( 'Enable monthly payments with Alma', 'alma-woocommerce-gateway' ),
			'default' => $default_settings['enabled'],
		);

		$api_key_fields = array(
			'keys_section' => array(
				'title'       => '<hr>' . $keys_title,
				'type'        => 'title',
				'description' => __( 'You can find your API keys on <a href="https://dashboard.getalma.eu/security" target="_blank">your Alma dashboard</a>', 'alma-woocommerce-gateway' ),
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

		$settings_fields = array(
			'enabled' => $enabled_option,
		);

		if ( ! $need_api_key ) {
			try {
				$merchant = alma_wc_plugin()->get_alma_client()->merchants->me();

				foreach ( $merchant->fee_plans as $fee_plan ) {
					if ( $fee_plan['allowed'] ) {
						$installments          = $fee_plan['installments_count'];
						$default_min_amount    = alma_wc_price_from_cents( $fee_plan['min_purchase_amount'] );
						$default_max_amount    = alma_wc_price_from_cents( $fee_plan['max_purchase_amount'] );
						$merchant_fee_fixed    = alma_wc_price_from_cents( $fee_plan['merchant_fee_fixed'] );
						$merchant_fee_variable = $fee_plan['merchant_fee_variable'] / 100; // percent.
						$customer_fee_fixed    = alma_wc_price_from_cents( $fee_plan['customer_fee_fixed'] );
						$customer_fee_variable = $fee_plan['customer_fee_variable'] / 100; // percent.

						$settings_fields = array_merge(
							$settings_fields,
							array(
								"${installments}x_section" => array(
									// translators: %d: number of installments.
									'title'       => '<hr>' . sprintf( __( '→ %d-installment payment', 'alma-woocommerce-gateway' ), $installments ),
									'type'        => 'title',
									'description' => $this->get_fee_plan_description( $installments, $default_min_amount, $default_max_amount, $merchant_fee_fixed, $merchant_fee_variable, $customer_fee_fixed, $customer_fee_variable ),
								),
								"enabled_${installments}x" => array(
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
							)
						);
					}
				}
			} catch ( RequestError $e ) {
				alma_wc_plugin()->handle_settings_exception( $e );
			}
		}

		$settings_fields = array_merge(
			$settings_fields,
			array(
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
					'options'     => $this->product_categories_options(),
				),
				'cart_not_eligible_message_gift_cards'  => array(
					'title'       => __( 'Non-eligibility message for excluded products', 'alma-woocommerce-gateway' ),
					'type'        => 'text',
					'description' => __( 'Message displayed below the cart totals when it contains excluded products', 'alma-woocommerce-gateway' ),
					'desc_tip'    => true,
					'default'     => $default_settings['cart_not_eligible_message_gift_cards'],
				),
			)
		);

		$debug_fields = array(
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

		if ( $need_api_key ) {
			$this->form_fields = array_merge( array( 'enabled' => $enabled_option ), $api_key_fields, $debug_fields );
		} else {
			$this->form_fields = array_merge( $settings_fields, $api_key_fields, $debug_fields );
		}
	}

	/**
	 * Init settings.
	 */
	public function init_settings() {
		parent::init_settings();
		alma_wc_plugin()->settings->update_from( $this->settings );
	}

	/**
	 * Processes and saves options.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$post_data = $this->get_post_data();

		// After settings have been updated, override min/max amounts to convert them to cents.
		foreach ( $this->get_form_fields() as $key => $field ) {
			if ( in_array( $key, Alma_WC_Settings::AMOUNT_KEYS, true ) ) {
				try {
					$amount                 = $this->get_field_value( $key, $field, $post_data );
					$this->settings[ $key ] = alma_wc_price_to_cents( $amount );
				} catch ( Exception $e ) {
					$this->add_error( $e->getMessage() );
				}
			}
		}

		alma_wc_plugin()->settings->update_from( $this->settings );
		alma_wc_plugin()->init_alma_client();

		// If API is configured with its keys, try to fetch info from merchant account.
		$need_api_key = alma_wc_plugin()->settings->need_api_key();
		if ( ! $need_api_key ) {
			try {
				$merchant = alma_wc_plugin()->get_alma_client()->merchants->me();

				// store merchant id.
				$this->settings['merchant_id'] = $merchant->id;

				foreach ( $merchant->fee_plans as $fee_plan ) {
					$installments       = $fee_plan['installments_count'];
					$default_min_amount = $fee_plan['min_purchase_amount'];
					$default_max_amount = $fee_plan['max_purchase_amount'];

					if ( $fee_plan['allowed'] ) {
						// set min and max amount default values.
						if ( ! isset( $this->settings[ "min_amount_${installments}x" ] ) ) {
							$this->settings[ "min_amount_${installments}x" ] = $default_min_amount;
						}
						if ( ! isset( $this->settings[ "max_amount_${installments}x" ] ) ) {
							$this->settings[ "max_amount_${installments}x" ] = $default_max_amount;
						}
					} else {
						// force disable not available fee_plans to prevent showing them in checkout.
						$this->settings[ "enabled_${installments}x" ] = 'no';
						// reset min and max amount for disabled plans to prevent multiplication by 100 on each save.
						$this->settings[ "min_amount_${installments}x" ] = $default_min_amount;
						$this->settings[ "max_amount_${installments}x" ] = $default_max_amount;
					}
				}
			} catch ( RequestError $e ) {
				alma_wc_plugin()->handle_settings_exception( $e );
			}
		} else {
			// reset merchant id.
			$this->settings['merchant_id'] = null;
			// reset min and max amount for all plans.
			foreach ( Alma_WC_Settings::AMOUNT_KEYS as $amount_key ) {
				$this->settings[ $amount_key ] = null;
			}
		}

		alma_wc_plugin()->settings->update_from( $this->settings );
		alma_wc_plugin()->check_settings();

		return $saved && update_option( $this->get_option_key(), $this->settings );
	}

	/**
	 * Check if the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( wc()->cart === null ) {
			return parent::is_available();
		}

		if ( ! alma_wc_plugin()->check_locale() || ! alma_wc_plugin()->check_currency() ) {
			return false;
		}

		if ( ! alma_wc_plugin()->settings->is_cart_eligible() ) {
			return false;
		}

		if (
			array_key_exists( 'excluded_products_list', $this->settings ) &&
			is_array( $this->settings['excluded_products_list'] ) &&
			count( $this->settings['excluded_products_list'] ) > 0
		) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$product_id = $cart_item['product_id'];

				foreach ( $this->settings['excluded_products_list'] as $category_slug ) {
					if ( has_term( $category_slug, 'product_cat', $product_id ) ) {
						return false;
					}
				}
			}
		}

		$eligibilities = $this->get_cart_eligibilities();

		if ( ! $eligibilities ) {
			return false;
		}

		$is_eligible = false;

		foreach ( $eligibilities as $plan ) {
			$is_eligible = $is_eligible || $plan->isEligible; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		return $is_eligible && parent::is_available();
	}

	/**
	 * Output HTML for a single payment field.
	 *
	 * @param int     $n                    Installments count.
	 * @param boolean $with_radio_button    Include a radio button for plan selection.
	 * @param boolean $checked              Should the radio button be checked.
	 */
	private function payment_field( $n, $with_radio_button, $checked ) {
		$plan_class = '.' . self::ALMA_PAYMENT_PLAN_TABLE_CSS_CLASS;
		$plan_id    = '#' . sprintf( self::ALMA_PAYMENT_PLAN_TABLE_ID_TEMPLATE, $n );
		$logo_url   = alma_wc_plugin()->get_asset_url( "images/p${n}x_logo.svg" );
		?>
		<input
				type="<?php echo $with_radio_button ? 'radio' : 'hidden'; ?>"
				value="<?php echo esc_attr( $n ); ?>"
				id="alma_installments_count_<?php echo esc_attr( $n ); ?>"
				name="alma_installments_count"

				<?php if ( $with_radio_button ) : ?>
					style="margin-right: 5px;"
					<?php echo $checked ? 'checked' : ''; ?>
					onchange="if (this.checked) { jQuery( '<?php echo esc_js( $plan_class ); ?>' ).hide(); jQuery( '<?php echo esc_js( $plan_id ); ?>' ).show() }"
				<?php endif; ?>
		>
		<label
				class="checkbox"
				style="margin-right: 10px; display: inline;"
				for="alma_installments_count_<?php echo esc_attr( $n ); ?>"
		>
			<img src="<?php echo esc_attr( $logo_url ); ?>"
				style="float: unset !important; width: auto !important; height: 30px !important;  border: none !important; vertical-align: middle; display: inline-block;"
				alt="
					<?php
					// translators: %d: number of installments.
					echo sprintf( esc_html__( '%d installments', 'alma-woocommerce-gateway' ), esc_html( $n ) );
					?>
					">
		</label>
		<?php
	}

	/**
	 * Custom payment fields.
	 */
	public function payment_fields() {
		echo wp_kses_post( $this->description );

		$eligible_installments = alma_wc_plugin()->settings->get_eligible_installments_for_cart();
		$default_installments  = self::get_default_pnx( $eligible_installments );
		$multiple_plans        = count( $eligible_installments ) > 1;

		if ( $multiple_plans ) {
			?>
			<p><?php echo esc_html__( 'How many installments do you want to pay?', 'alma-woocommerce-gateway' ); ?><span class="required">*</span></p>
			<?php
		}
		?>
		<p>
			<?php
			foreach ( $eligible_installments as $n ) {
				$this->payment_field( $n, $multiple_plans, $n === $default_installments );
			}

			$this->render_payment_plan( $default_installments );
			?>
		</p>
		<?php
	}

	/**
	 * Validate payment fields.
	 *
	 * @return bool
	 */
	public function validate_fields() {
		if ( empty( $_POST['alma_installments_count'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wc_add_notice( '<strong>Installments count</strong> is required.', 'error' );
			return false;
		}
		$allowed_values = array_map( 'strval', alma_wc_plugin()->settings->get_eligible_installments_for_cart() );
		if ( ! in_array( $_POST['alma_installments_count'], $allowed_values, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wc_add_notice( '<strong>Installments count</strong> is invalid.', 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Process Payment.
	 *
	 * @param int $order_id Order ID.
	 *
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$error_msg = __( 'There was an error processing your payment.<br>Please try again or contact us if the problem persists.', 'alma-woocommerce-gateway' );

		$alma = alma_wc_plugin()->get_alma_client();
		if ( ! $alma ) {
			wc_add_notice( $error_msg, 'error' );

			return array(
				'result' => 'error',
			);
		}

		try {
			// phpcs:ignore WordPress.Security.NonceVerification
			$payment = $alma->payments->create( Alma_WC_Model_Payment::from_order( $order_id, intval( $_POST['alma_installments_count'] ) ) );
		} catch ( RequestError $e ) {
			$this->logger->error( 'Error while creating payment: ' . $e->getMessage() );
			wc_add_notice( $error_msg, 'error' );

			return array( 'result' => 'error' );
		}

		// Redirect user to our payment page.
		return array(
			'result'   => 'success',
			'redirect' => $payment->url,
		);
	}

	/**
	 * Redirect to cart with error.
	 *
	 * @param string $error_msg Error message.
	 */
	private function redirect_to_cart_with_error( $error_msg ) {
		wc_add_notice( $error_msg, 'error' );

		$cart_url = wc_get_cart_url();
		wp_safe_redirect( $cart_url );
		exit();
	}

	/**
	 * Validate payment on customer return.
	 *
	 * @param string $payment_id Payment Id.
	 */
	public function validate_payment_on_customer_return( $payment_id ) {
		$order     = null;
		$error_msg = __( 'There was an error when validating your payment.<br>Please try again or contact us if the problem persists.', 'alma-woocommerce-gateway' );
		try {
			$order = Alma_WC_Payment_Validator::validate_payment( $payment_id );
		} catch ( Alma_WC_Payment_Validation_Error $e ) {
			$this->redirect_to_cart_with_error( $error_msg );
		} catch ( \Exception $e ) {
			$this->redirect_to_cart_with_error( $e->getMessage() );
		}

		if ( ! $order ) {
			$this->redirect_to_cart_with_error( $error_msg );
		}

		// Redirect user to the order confirmation page.
		$return_url = $this->get_return_url( $order->get_wc_order() );
		wp_safe_redirect( $return_url );
		exit();
	}

	/**
	 * Validate payment from ipn.
	 *
	 * @param string $payment_id Payment Id.
	 */
	public function validate_payment_from_ipn( $payment_id ) {
		try {
			Alma_WC_Payment_Validator::validate_payment( $payment_id );
		} catch ( \Exception $e ) {
			status_header( 500 );
			wp_send_json( array( 'error' => $e->getMessage() ) );
		}

		wp_send_json( array( 'success' => true ) );
	}

	/**
	 * Product categories options.
	 *
	 * @return array
	 */
	private function product_categories_options() {
		$product_categories = get_terms(
			'product_cat',
			array(
				'orderby'    => 'name',
				'order'      => 'asc',
				'hide_empty' => false,
			)
		);

		$options = array();
		if ( ! empty( $product_categories ) ) {
			foreach ( $product_categories as $category ) {
				$options[ $category->slug ] = $category->name;
			}
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
	private function get_fee_plan_description(
		$installments,
		$min_amount,
		$max_amount,
		$merchant_fee_fixed,
		$merchant_fee_variable,
		$customer_fee_fixed,
		$customer_fee_variable
	) {
		$description = '<p>';

		// translators: %d: number of installments.
		$description .= sprintf( __( 'You can offer %1$d-installment payments for amounts between <b>%2$d€</b> and <b>%3$d€</b>.', 'alma-woocommerce-gateway' ), $installments, $min_amount, $max_amount )
			. '<br>'
			. __( 'Fees applied to each transaction for this plan:', 'alma-woocommerce-gateway' );

		if ( $merchant_fee_variable || $merchant_fee_fixed ) {
			$description .= '<br>';
			$description .= '<b>' . __( 'You pay:', 'alma-woocommerce-gateway' ) . '</b> ';
		}

		if ( $merchant_fee_variable ) {
			$description .= $merchant_fee_variable . '%';
		}

		if ( $merchant_fee_fixed ) {
			if ( $merchant_fee_variable ) {
				$description .= ' + ';
			}
			$description .= $merchant_fee_fixed . '€';
		}

		if ( $customer_fee_variable || $customer_fee_fixed ) {
			$description .= '<br>';
			$description .= '<b>' . __( 'Customer pays:', 'alma-woocommerce-gateway' ) . '</b> ';
		}

		if ( $customer_fee_variable ) {
			$description .= $customer_fee_variable . '%';
		}

		if ( $customer_fee_fixed ) {
			if ( $customer_fee_variable ) {
				$description .= ' + ';
			}
			$description .= $customer_fee_fixed . '€';
		}

		$description .= '</p>';

		return $description;
	}

	/**
	 * Render payment plan with dates.
	 *
	 * @param int $default_installments Number of installments.
	 *
	 * @return void
	 */
	private function render_payment_plan( $default_installments ) {
		$eligibilities = $this->get_cart_eligibilities();
		if ( $eligibilities ) {
			foreach ( $eligibilities as $n => $plan ) {
				?>
				<div
					id="<?php echo esc_attr( sprintf( self::ALMA_PAYMENT_PLAN_TABLE_ID_TEMPLATE, $n ) ); ?>"
					class="<?php echo esc_attr( self::ALMA_PAYMENT_PLAN_TABLE_CSS_CLASS ); ?>"
					style="
						margin: 0 auto;
						<?php if ( $n !== $default_installments ) { ?>
						display: none;
						<?php	} ?>
					"
				>
					<?php
					$plans_count = count( $plan->paymentPlan ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
					$plan_index  = 0;
					foreach ( $plan->paymentPlan as $step ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
						?>
						<!--suppress CssReplaceWithShorthandSafely -->
						<p style="
							display: flex;
							justify-content: space-between;
							padding: 4px 0;
							margin: 4px 0;
							<?php if ( ++$plan_index !== $plans_count ) { ?>
								border-bottom: 1px solid lightgrey;
							<?php	} else { ?>
								padding-bottom: 0;
								margin-bottom: 0;
							<?php	} ?>
						">
							<span><?php echo esc_html( date_i18n( get_option( 'date_format' ), $step['due_date'] ) ); ?></span>
							<span>€<?php echo esc_html( alma_wc_price_from_cents( $step['purchase_amount'] + $step['customer_fee'] ) ); ?></span>
						</p>
					<?php } ?>
				</div>
				<?php
			}
		}
	}

	/**
	 * Get eligibilities from cart.
	 *
	 * @return array<int,Eligibility>|null
	 */
	private function get_cart_eligibilities() {
		if ( ! $this->eligibilities ) {
			$alma = alma_wc_plugin()->get_alma_client();
			if ( ! $alma ) {
				return null;
			}

			try {
				$this->eligibilities = $alma->payments->eligibility( Alma_WC_Model_Payment::from_cart() );
			} catch ( RequestError $e ) {
				alma_wc_plugin()->log_stack_trace( 'Error while checking payment eligibility: ', $e );
				return null;
			}
		}

		return $this->eligibilities;
	}

	/**
	 * Get default pnx according to eligible pnx list.
	 *
	 * @param int[] $pnx_list the list of eligible pnx.
	 *
	 * @return int|null
	 */
	private static function get_default_pnx( $pnx_list ) {
		if ( ! count( $pnx_list ) ) {
			return null;
		}

		if ( in_array( 3, $pnx_list, true ) ) {
			return 3;
		}

		return end( $pnx_list );
	}
}
