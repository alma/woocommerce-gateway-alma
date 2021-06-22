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
		$this->form_fields = Alma_WC_Admin_Form::init_form_fields();
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
		$previously_saved = parent::process_admin_options();

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

		return $previously_saved && update_option( $this->get_option_key(), $this->settings );
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

	/**
	 * Generate a select `alma_fee` Input HTML (based on `select_alma_fee_plan` field definition).
	 * Warning: use only one `select_alma_fee_plan` type in your form (script & css are inlined here)
	 *
	 * @param  mixed $key as field key.
	 * @param  mixed $data as field configuration.
	 *
	 * @return string
	 * @see WC_Settings_API::generate_settings_html() that calls dynamically generate_<field_type>_html
	 */
	public function generate_select_alma_fee_plan_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'type'              => 'text',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => array(),
			'options'           => array(),
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<script>
			jQuery(document).ready(function() {
				jQuery('#' + '<?php echo esc_attr( $field_key ); ?>').change(function() {
					jQuery('.alma_fee_plan').hide();
					var $sections = jQuery('.alma_fee_plan_' + jQuery(this).val());
					$sections.show();
					$sections.effect('highlight', 1500);
					$sections.find('b').effect('highlight', 5000);
				});
			});
		</script>
		<style>
			.alma_fee_plan {
				padding-left: 6px;
				border-left: solid 2px lightgrey;
			}
			h3.alma_fee_plan {
				padding-top: 15px;
				padding-bottom: 15px;
			}
			div.alma_fee_plan,
			table.form-table.alma_fee_plan
			{
				margin-top: -15px;
			}
			table.form-table.alma_fee_plan tr:first-child th {
				padding-top: 35px;
			}
			@media(max-width: 782px) {
				table.form-table.alma_fee_plan th,
				table.form-table.alma_fee_plan td {
					padding-left: 6px;
					border-left: solid 1px lightgrey;
				}
				table.form-table.alma_fee_plan,
				.alma_fee_plan {
					margin-left: 36px;
				}
			}
			@media(min-width: 783px) {
				table.form-table.alma_fee_plan tr:first-child td {
					padding-top: 30px;
				}
				table.form-table.alma_fee_plan th {
					padding-left: 6px;
					border-left: solid 2px lightgrey;
				}
				table.form-table.alma_fee_plan,
				.alma_fee_plan {
					margin-left: 236px;
				}
			}
		</style>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
				<?php echo $this->get_tooltip_html( $data ); ?>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span></legend>
					<select class="select <?php echo esc_attr( $data['class'] ); ?>" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" <?php disabled( $data['disabled'], true ); ?> <?php echo $this->get_custom_attribute_html( $data ); ?>>
						<?php foreach ( (array) $data['options'] as $option_key => $option_value ) : ?>
							<option value="<?php echo esc_attr( $option_key ); ?>" <?php selected( $option_key, esc_attr( $this->get_option( $key ) ) ); ?>><?php echo esc_attr( $option_value ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php echo $this->get_description_html( $data ); ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Override WC_Settings_API Generate Title HTML.
	 * add css, description_css, table_css, description_class & table_class definitions.
	 *
	 * @param  mixed $key as string form field key.
	 * @param  mixed $data as field definition array.
	 * @since  1.0.0
	 * @return string
	 */
	public function generate_title_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'           => '',
			'class'           => '',
			'css'             => '',
			'description_css' => '',
			'table_css'       => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		</table>
		<h3 class="wc-settings-sub-title <?php echo esc_attr( $data['class'] ); ?>" style="<?php echo esc_attr( $data['css'] ); ?>" id="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></h3>
		<?php if ( ! empty( $data['description'] ) ) : ?>
			<div class="<?php echo $data['description_class']; ?>" style="<?php echo $data['description_css']; ?>"><?php echo wp_kses_post( $data['description'] ); ?></div>
		<?php endif; ?>
		<table class="form-table <?php echo $data['table_class']; ?>" style="<?php echo $data['table_css']; ?>">
		<?php

		return ob_get_clean();
	}
}
