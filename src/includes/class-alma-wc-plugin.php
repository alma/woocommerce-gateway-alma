<?php
/**
 * Alma payments plugin for WooCommerce
 *
 * @package Alma_WooCommerce_Gateway
 * @noinspection HtmlUnknownTarget
 */

use Alma\API\Client;
use Alma\API\RequestError;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Alma_WC_Plugin
 */
class Alma_WC_Plugin {
	/**
	 * Flag to indicate the plugin has been bootstrapped.
	 *
	 * @var bool
	 */
	private $bootstrapped = false;

	/**
	 * Instance of Alma_Settings.
	 *
	 * @var Alma_WC_Settings
	 */
	public $settings;

	/**
	 * Instance of Alma Api client.
	 *
	 * @var Client
	 */
	private $alma_client;

	/**
	 * Instance of WC_Logger.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		$this->logger = new Alma_WC_Logger();
		$this->self_update();
	}

	/**
	 * Init the alma widget handlers :
	 * - hooked on Woocommerce Cart & Product actions
	 * - AND add associated shortcodes
	 */
	private function init_widget_handlers() {
		$shortcodes = new Alma_Wc_Shortcodes();

		$cart_handler = new Alma_WC_Cart_Handler();
		$shortcodes->init_cart_widget_shortcode( $cart_handler );

		$product_handler = new Alma_WC_Product_Handler();
		$shortcodes->init_product_widget_shortcode( $product_handler );
	}

	/**
	 * Update plugin to the latest version.
	 *
	 * @return void
	 */
	private function self_update() {
		if ( version_compare( ALMA_WC_VERSION, get_option( 'alma_version' ), '>' ) ) {
			update_option( 'alma_version', ALMA_WC_VERSION );
		}
	}

	/**
	 * Init alma client.
	 *
	 * @return void
	 */
	public function init_alma_client() {
		try {
			$this->alma_client = new Client(
				$this->settings->get_active_api_key(),
				array(
					'mode'   => $this->settings->get_environment(),
					'logger' => $this->logger,
				)
			);

			$this->alma_client->addUserAgentComponent( 'WordPress', get_bloginfo( 'version' ) );
			$this->alma_client->addUserAgentComponent( 'WooCommerce', wc()->version );
			$this->alma_client->addUserAgentComponent( 'Alma for WooCommerce', ALMA_WC_VERSION );
		} catch ( \Exception $e ) {
			if ( $this->settings->is_logging_enabled() ) {
				$this->logger->log_stack_trace( 'Error creating Alma API client', $e );
			}
		}
	}

	/**
	 * Get alma client.
	 *
	 * @return Client|null
	 */
	public function get_alma_client() {
		if ( ! $this->alma_client ) {
			$this->init_alma_client();
		}

		return $this->alma_client;
	}

	/**
	 * Try running.
	 *
	 * @return void
	 */
	public function try_running() {
		add_action(
			Alma_WC_Webhooks::action_for( Alma_WC_Webhooks::CUSTOMER_RETURN ),
			array(
				$this,
				'handle_customer_return',
			)
		);

		add_action(
			Alma_WC_Webhooks::action_for( Alma_WC_Webhooks::IPN_CALLBACK ),
			array(
				$this,
				'handle_ipn_callback',
			)
		);

		add_action( 'init', array( $this, 'bootstrap' ) );
		add_filter( 'allowed_redirect_hosts', array( $this, 'alma_domains_whitelist' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( ALMA_WC_PLUGIN_FILE ), array( $this, 'plugin_action_links' ) );
		add_action( 'wp_ajax_alma_dismiss_notice_message', array( $this, 'ajax_dismiss_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'alma_admin_enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts needed into admin form
	 */
	public function alma_admin_enqueue_scripts() {
		wp_enqueue_style(
			'alma-admin-styles',
			alma_wc_plugin()->get_asset_url( 'css/alma-admin.css' ),
			array(),
			ALMA_WC_VERSION
		);

		wp_enqueue_script(
			'alma-admin-scripts',
			alma_wc_plugin()->get_asset_url( 'js/alma-admin.js' ),
			array( 'jquery-effects-highlight' ),
			ALMA_WC_VERSION,
			true
		);
	}

	/**
	 * Bootstrap
	 *
	 * @return void
	 *
	 * @throws \Exception Exception.
	 */
	public function bootstrap() {
		try {
			if ( $this->bootstrapped ) {
				throw new Exception( __( 'WooCommerce Gateway Alma plugin can only be bootstrapped once', 'alma-woocommerce-gateway' ) );
			}

			$this->load_plugin_textdomain();

			delete_option( 'alma_bootstrap_warning_message' );

			$this->check_dependencies();

			$this->settings = new Alma_WC_Settings();

			$this->run();

			if ( is_admin() ) {
				// Defer settings check to after potential settings update.
				update_option( 'alma_warnings_handled', false );
				$this->settings->save();
				add_action(
					'admin_notices',
					function () {
						$this->check_settings();
					}
				);
			}
		} catch ( Exception $e ) {
			$this->logger->error( 'Bootstrap error: ' . $e->getMessage() );
			$this->handle_settings_exception( $e );
		}
	}

	/**
	 * Handle settings exception.
	 *
	 * @param \Exception $e Exception.
	 *
	 * @return void
	 */
	public function handle_settings_exception( $e ) {
		if ( get_option( 'alma_warnings_handled' ) ) {
			return;
		}

		delete_option( 'alma_bootstrap_warning_message_dismissed' );
		update_option( 'alma_bootstrap_warning_message', $e->getMessage() );

		add_action( 'admin_notices', array( $this, 'show_settings_warning' ) );

		update_option( 'alma_warnings_handled', true );
	}

	/**
	 * Show settings warning.
	 *
	 * @return void
	 */
	public function show_settings_warning() {
		$message = get_option( 'alma_bootstrap_warning_message', '' );
		if ( ! empty( $message ) && ! get_option( 'alma_bootstrap_warning_message_dismissed' ) ) {
			?>
			<div class="notice notice-warning is-dismissible alma-dismiss-bootstrap-warning-message">
				<p>
					<strong><?php echo wp_kses_post( $message ); ?></strong>
				</p>
			</div>
			<script>
				(function ($) {
					$('.alma-dismiss-bootstrap-warning-message').on('click', '.notice-dismiss', function () {
						jQuery.post("<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>", {
							action: "alma_dismiss_notice_message",
							dismiss_action: "alma_dismiss_bootstrap_warning_message",
							nonce: "<?php echo esc_js( wp_create_nonce( 'alma_dismiss_notice' ) ); ?>"
						});
					});
				})(jQuery);
			</script>
			<?php
		}
	}

	/**
	 * Check dependencies.
	 *
	 * @throws Exception Exception.
	 */
	protected function check_dependencies() {
		if ( ! function_exists( 'WC' ) ) {
			throw new Exception( __( 'Alma requires WooCommerce to be activated', 'alma-woocommerce-gateway' ) );
		}

		if ( version_compare( wc()->version, '2.6', '<' ) ) {
			throw new Exception( __( 'Alma requires WooCommerce version 2.6 or greater', 'alma-woocommerce-gateway' ) );
		}

		if ( ! function_exists( 'curl_init' ) ) {
			throw new Exception( __( 'Alma requires the cURL PHP extension to be installed on your server', 'alma-woocommerce-gateway' ) );
		}

		if ( ! function_exists( 'json_decode' ) ) {
			throw new Exception( __( 'Alma requires the JSON PHP extension to be installed on your server', 'alma-woocommerce-gateway' ) );
		}

		$openssl_warning = __( 'Alma requires OpenSSL >= 1.0.1 to be installed on your server', 'alma-woocommerce-gateway' );
		if ( ! defined( 'OPENSSL_VERSION_TEXT' ) ) {
			throw new Exception( $openssl_warning );
		}

		preg_match( '/^(?:Libre|Open)SSL ([\d.]+)/', OPENSSL_VERSION_TEXT, $matches );
		if ( empty( $matches[1] ) ) {
			throw new Exception( $openssl_warning );
		}

		if ( ! version_compare( $matches[1], '1.0.1', '>=' ) ) {
			throw new Exception( $openssl_warning );
		}
	}

	/**
	 * Check settings.
	 *
	 * @return void
	 * @see self::force_check_settings()
	 */
	public function check_settings() {
		if ( ( $this->settings->fully_configured || get_option( 'alma_warnings_handled' ) ) ) {
			return;
		}

		$this->force_check_settings();
	}

	/**
	 *  Check that Alma is available for the current locale
	 */
	public function check_locale() {
		$locale      = get_locale();
		$main_locale = substr( get_locale(), 0, 3 );

		// By default, activate Alma only for french locales.
		$enable_locale = apply_filters( 'alma_wc_enable_for_locale', 'fr_' === $main_locale, $locale );

		if ( ! $enable_locale ) {
			$this->logger->info( "Alma is not enabled for locale '$locale'" );
			return false;
		}

		return true;
	}


	/**
	 *  Check that Alma is available for the current currency
	 */
	public function check_currency() {
		$currency = get_woocommerce_currency();
		if ( 'EUR' !== $currency ) {
			$this->logger->info( "Currency $currency not supported - Not displaying Alma" );
			return false;
		}

		return true;
	}

	/**
	 * Check activation.
	 *
	 * @return void
	 *
	 * @throws Exception Exception.
	 */
	public function check_activation() {
		$gateway = new Alma_WC_Payment_Gateway();
		$enabled = $gateway->get_option( 'enabled', 'no' );

		if ( ! alma_wc_string_to_bool( $enabled ) ) {
			throw new Exception(
				sprintf(
					// translators: %s: Admin settings url.
					__( "Thanks for installing Alma! Start by <a href='%s'>activating Alma's payment method</a>, then set it up to get started.", 'alma-woocommerce-gateway' ),
					esc_url( $this->get_admin_setting_url( false ) )
				)
			);
		}
	}

	/**
	 * Check that we have an API key.
	 * If not, it will throw an exception that will result in a prompt to configure the plugin
	 *
	 * @throws Exception Exception.
	 */
	public function check_credentials() {
		if ( $this->settings->need_api_key() ) {
			$settings_url = $this->get_admin_setting_url();
			throw new Exception(
				sprintf(
					// translators: %s: Admin settings url.
					__( 'Alma is almost ready. To get started, <a href="%s">fill in your API keys</a>.', 'alma-woocommerce-gateway' ),
					esc_url( $settings_url )
				)
			);
		}
	}

	/**
	 * Run the plugin.
	 */
	private function run() {

		if ( $this->settings->is_enabled() ) {
			$this->init_widget_handlers();
		}

		// Don't advertise our payment gateway if we're in test mode and current user is not an admin.
		if ( $this->settings->get_environment() === 'test' && ! current_user_can( 'administrator' ) ) {
			$this->logger->info( 'Not displaying Alma in Test mode to non-admin user' );

			return;
		}

		add_filter( 'woocommerce_payment_gateways', array( $this, 'payment_gateways' ) );
	}

	/**
	 * Check merchant status.
	 *
	 * @throws Exception Exception.
	 */
	public function check_merchant_status() {
		$alma = $this->get_alma_client();

		$settings_url = $this->get_admin_setting_url();
		$logs_url     = $this->get_admin_logs_url();

		if ( ! $alma ) {
			throw new Exception(
				sprintf(
					// translators: %1%s: Admin settings url, %s: Admin logs url.
					__( 'Error while initializing Alma API client.<br><a href="%1$s">Activate debug mode</a> and <a href="%2$s">check logs</a> for more details.', 'alma-woocommerce-gateway' ),
					esc_url( $settings_url ),
					esc_url( $logs_url )
				)
			);
		}

		try {
			$merchant = $alma->merchants->me();
		} catch ( RequestError $e ) {
			if ( $e->response && 401 === $e->response->responseCode ) {
				throw new Exception(
					sprintf(
						// translators: %s: Alma dashboard url.
						__( 'Could not connect to Alma using your API keys.<br>Please double check your keys on your <a href="%1$s" target="_blank">Alma dashboard</a>.', 'alma-woocommerce-gateway' ),
						$this->get_alma_dashboard_url( 'security' )
					)
				);
			} else {
				throw new Exception(
					sprintf(
						// translators: %s: Error message.
						__( 'Alma encountered an error when fetching merchant status: %s', 'alma-woocommerce-gateway' ),
						$e->getMessage()
					),
					$e->getCode(),
					$e
				);
			}
		}

		if ( ! $merchant->can_create_payments ) {
			throw new Exception(
				sprintf(
					// translators: %s: Alma dashboard url.
					__( 'Your Alma account needs to be activated before you can use Alma on your shop.<br>Go to your <a href="%1$s" target="_blank">Alma dashboard</a> to activate your account.<br><a href="%2$s">Refresh</a> the page when ready.', 'alma-woocommerce-gateway' ),
					$this->get_alma_dashboard_url( 'settings' ),
					esc_url( $settings_url )
				)
			);
		}
	}

	/**
	 * Register Alma payment gateway.
	 *
	 * @param string[] $methods Payment methods.
	 *
	 * @return string[]
	 */
	public function payment_gateways( $methods ) {
		$methods[] = 'Alma_WC_Payment_Gateway';

		return $methods;
	}

	/**
	 * AJAX handler for dismiss notice action.
	 */
	public function ajax_dismiss_notice() {
		if ( empty( $_POST['dismiss_action'] ) ) {
			return;
		}

		check_ajax_referer( 'alma_dismiss_notice', 'nonce' );
		if ( 'alma_dismiss_bootstrap_warning_message' === $_POST['dismiss_action'] ) {
			update_option( 'alma_bootstrap_warning_message_dismissed', true );
		}

		wp_die();
	}

	/**
	 * Link to settings screen.
	 *
	 * @param bool $alma_section Go to alma section.
	 *
	 * @return string
	 */
	public function get_admin_setting_url( $alma_section = true ) {
		if ( $alma_section ) {
			return admin_url( 'admin.php?page=wc-settings&tab=checkout&section=alma' );
		} else {
			return admin_url( 'admin.php?page=wc-settings&tab=checkout' );
		}
	}

	/**
	 * Get admin logs url.
	 *
	 * @return string
	 */
	public function get_admin_logs_url() {
		return admin_url( 'admin.php?page=wc-status&tab=logs' );
	}

	/**
	 * Allow Alma domains for redirect.
	 *
	 * @param string[] $domains Whitelisted domains for `wp_safe_redirect`.
	 *
	 * @return string[]
	 */
	public function alma_domains_whitelist( $domains ) {
		$domains[] = 'pay.getalma.eu';
		$domains[] = 'pay.sandbox.getalma.eu';

		return $domains;
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_plugin_textdomain() {
		load_plugin_textdomain( 'alma-woocommerce-gateway', false, plugin_basename( ALMA_WC_PLUGIN_PATH ) . '/languages' );
	}

	/**
	 * Add relevant links to plugins page.
	 *
	 * @param array $links Plugin action links.
	 *
	 * @return array Plugin action links
	 */
	public function plugin_action_links( $links ) {
		$plugin_links = array();

		if ( function_exists( 'WC' ) ) {
			$setting_url    = $this->get_admin_setting_url();
			$plugin_links[] = '<a href="' . esc_url( $setting_url ) . '">' . esc_html__( 'Settings', 'alma-woocommerce-gateway' ) . '</a>';
		}

		return array_merge( $plugin_links, $links );
	}

	/** HELPERS **/

	/**
	 * Get asset url.
	 *
	 * @param string $path Path to asset relative to the plugin's assets directory.
	 *
	 * @return string URL to given asset
	 */
	public function get_asset_url( $path ) {
		return ALMA_WC_PLUGIN_URL . 'assets/' . $path;
	}

	/**
	 * Webhooks handlers
	 */
	private function get_payment_to_validate() {
		$payment_id = isset( $_GET['pid'] ) ? $_GET['pid'] : null; // phpcs:ignore WordPress.Sniffs.Security.NonceVerificationSniff

		if ( ! $payment_id ) {
			$this->logger->error( 'Payment validation webhook called without a payment ID' );

			wc_add_notice(
				__( 'Payment validation error: no ID provided.<br>Please try again or contact us if the problem persists.', 'alma-woocommerce-gateway' ),
				'error'
			);

			wp_safe_redirect( wc_get_cart_url() );
			exit();
		}

		return $payment_id;
	}

	/**
	 * Handle customer return.
	 *
	 * @return void
	 */
	public function handle_customer_return() {
		$payment_id = $this->get_payment_to_validate();
		$gateway    = new Alma_WC_Payment_Gateway();
		$gateway->validate_payment_on_customer_return( $payment_id );
	}

	/**
	 * Handle ipn callback.
	 *
	 * @return void
	 */
	public function handle_ipn_callback() {
		$payment_id = $this->get_payment_to_validate();
		$gateway    = new Alma_WC_Payment_Gateway();
		$gateway->validate_payment_from_ipn( $payment_id );
	}

	/**
	 * Get Alma full URL depends on test or live mode (sandbox or not)
	 *
	 * @param string $path as path to add after default scheme://host/ infos.
	 *
	 * @return string as full URL
	 */
	public function get_alma_dashboard_url( $path = '' ) {
		if ( $this->settings->is_live() ) {
			/* translators: %s -> path to add after dashboard url */
			return esc_url( sprintf( __( 'https://dashboard.getalma.eu/%s', 'alma-woocommerce-gateway' ), $path ) );
		}
		/* translators: %s -> path to add after sandbox dashboard url */
		return esc_url( sprintf( __( 'https://dashboard.sandbox.getalma.eu/%s', 'alma-woocommerce-gateway' ), $path ) );
	}

	/**
	 * Force Check settings.
	 *
	 * @return void
	 */
	public function force_check_settings() {
		try {
			update_option( 'alma_warnings_handled', false );
			$this->settings->fully_configured = false;

			$this->check_activation();
			$this->check_credentials();
			$this->check_merchant_status();

			$this->settings->fully_configured = true;

			$this->settings->save();
		} catch ( Exception $exception ) {
			$this->handle_settings_exception( $exception );
		}
	}
}
