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
 * Alma_WC_Plugin
 */
class Alma_WC_Plugin {
	/**
	 * Filepath of main plugin file.
	 *
	 * @var string
	 */
	public $file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Absolute plugin path.
	 *
	 * @var string
	 */
	public $plugin_path;

	/**
	 * Absolute plugin URL.
	 *
	 * @var string
	 */
	public $plugin_url;

	/**
	 * Absolute path to plugin includes dir.
	 *
	 * @var string
	 */
	public $includes_path;

	/**
	 * Flag to indicate the plugin has been bootstrapped.
	 *
	 * @var bool
	 */
	private $_bootstrapped = false;

	/**
	 * Instance of Alma_Settings.
	 *
	 * @var Alma_WC_Settings
	 */
	public $settings;

	/**
	 * Instance of Alma Api client.
	 *
	 * @var \Alma\API\Client
	 */
	private $_alma_client;

	/**
	 * Instance of WC_Logger.
	 *
	 * @var WC_Logger
	 */
	private $logger;

	/**
	 * __construct
	 *
	 * @param string $file File.
	 * @param string $version Version.
	 *
	 * @return void
	 */
	public function __construct( $file, $version ) {
		$this->file    = $file;
		$this->version = $version;

		// Path.
		$this->plugin_path   = trailingslashit( plugin_dir_path( $this->file ) );
		$this->plugin_url    = trailingslashit( plugin_dir_url( $this->file ) );
		$this->includes_path = $this->plugin_path . trailingslashit( 'includes' );

		require_once $this->plugin_path . 'vendor/autoload.php';
		require_once $this->includes_path . 'class-alma-wc-logger.php';
		$this->logger = new Alma_WC_Logger();

		// Updates.
		if ( version_compare( $version, get_option( 'alma_version' ), '>' ) ) {
			$this->update_to( $version );
		}
	}

	/**
	 * Update to.
	 *
	 * @param string $new_version New version.
	 *
	 * @return void
	 */
	private function update_to( $new_version ) {
		// Right now, updating only means setting the correct version in options.
		update_option( 'alma_version', $new_version );
	}

	/**
	 * Init alma client.
	 *
	 * @return void
	 */
	public function init_alma_client() {
		try {
			$this->_alma_client = new \Alma\API\Client(
				$this->settings->get_active_api_key(),
				array(
					'mode'   => $this->settings->get_environment(),
					'logger' => $this->logger,
				)
			);

			$this->_alma_client->addUserAgentComponent( 'WordPress', get_bloginfo( 'version' ) );
			$this->_alma_client->addUserAgentComponent( 'WooCommerce', wc()->version );
			$this->_alma_client->addUserAgentComponent( 'Alma for WooCommerce', ALMA_WC_VERSION );
		} catch ( \Exception $e ) {
			if ( $this->settings->is_logging_enabled() ) {
				$this->logger->error( 'Error creating Alma API client: ' . print_r( $e, true ) );
			}
		}
	}

	/**
	 * Get alma client.
	 *
	 * @return \Alma\API\Client|null
	 */
	public function get_alma_client() {
		if ( ! $this->_alma_client ) {
			$this->init_alma_client();
		}

		return $this->_alma_client;
	}

	/**
	 * Try running.
	 *
	 * @return void
	 */
	public function try_running() {
		require_once $this->includes_path . 'class-alma-wc-webhooks.php';

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

		add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'plugin_action_links' ) );
		add_action( 'wp_ajax_alma_dismiss_notice_message', array( $this, 'ajax_dismiss_notice' ) );
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
			if ( $this->_bootstrapped ) {
				throw new Exception( __( 'WooCommerce Gateway Alma plugin can only be bootstrapped once', 'alma-woocommerce-gateway' ) );
			}

			$this->load_plugin_textdomain();

			delete_option( 'alma_bootstrap_warning_message' );

			$this->_check_dependencies();

			// TODO: Handle privacy message/personal data exporter/eraser.
			/* require_once( $this->includes_path . 'class-alma-privacy.php' ); */
			require_once $this->includes_path . 'models/class-alma-wc-cart.php';
			require_once $this->includes_path . 'models/class-alma-wc-customer.php';
			require_once $this->includes_path . 'models/class-alma-wc-order.php';
			require_once $this->includes_path . 'models/class-alma-wc-payment.php';
			require_once $this->includes_path . 'alma-wc-functions.php';
			require_once $this->includes_path . 'class-alma-wc-generic-handler.php';
			require_once $this->includes_path . 'class-alma-wc-cart-handler.php';
			require_once $this->includes_path . 'class-alma-wc-product-handler.php';
			require_once $this->includes_path . 'class-alma-wc-settings.php';
			$this->settings = new Alma_WC_Settings();

			$this->_run();

			if ( is_admin() ) {
				// Defer settings check to after potential settings update.
				$this->settings->warnings_handled = false;
				$this->settings->save();
				add_action(
					'admin_notices',
					function () {
						$this->check_settings( false );
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
		if ( $this->settings && $this->settings->warnings_handled ) {
			return;
		}

		delete_option( 'alma_bootstrap_warning_message_dismissed' );
		update_option( 'alma_bootstrap_warning_message', $e->getMessage() );

		add_action( 'admin_notices', array( $this, 'show_settings_warning' ) );

		if ( $this->settings ) {
			$this->settings->warnings_handled = true;
			$this->settings->save();
		}
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
	protected function _check_dependencies() {
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
	 * @param bool $force Force re-check.
	 *
	 * @return void
	 */
	public function check_settings( $force = true ) {
		if ( ( $this->settings->fully_configured || $this->settings->warnings_handled ) && ! $force ) {
			return;
		}

		try {
			$this->settings->fully_configured = false;
			$this->settings->warnings_handled = false;

			$this->check_activation();
			$this->check_credentials();
			$this->check_merchant_status();

			$this->settings->fully_configured = true;

			$this->settings->save();
		} catch ( Exception $e ) {
			$this->handle_settings_exception( $e );
		}
	}

	/**
	 * Check activation.
	 *
	 * @return void
	 *
	 * @throws \Exception Exception.
	 */
	public function check_activation() {
		$gateway = new Alma_WC_Payment_Gateway();
		$enabled = $gateway->get_option( 'enabled', 'no' );

		if ( ! wc_string_to_bool( $enabled ) ) {
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
	protected function _run() {

		require_once $this->includes_path . 'class-alma-wc-payment-validation-error.php';
		require_once $this->includes_path . 'class-alma-wc-payment-validator.php';
		require_once $this->includes_path . 'class-alma-wc-payment-gateway.php';

		$this->cart_handler    = new Alma_WC_Cart_Handler();
		$this->product_handler = new Alma_WC_Product_Handler();

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
		} catch ( \Alma\API\RequestError $e ) {
			if ( $e->response && 401 === $e->response->responseCode ) {
				$dashboard_url = 'https://dashboard.getalma.eu/security';

				throw new Exception(
					sprintf(
						// translators: %s: Alma dashboard url.
						__( 'Could not connect to Alma using your API keys.<br>Please double check your keys on your <a href="%1$s" target="_blank">Alma dashboard</a>.', 'alma-woocommerce-gateway' ),
						$dashboard_url
					)
				);
			} else {
				throw new Exception(
					sprintf(
						// translators: %s: Error message.
						__( 'Alma encountered an error when fetching merchant status: %s', 'alma-woocommerce-gateway' ),
						$e->getMessage()
					)
				);
			}
		}

		if ( ! $merchant->can_create_payments ) {
			$dashboard_url = 'https://dashboard.getalma.eu/settings';
			throw new Exception(
				sprintf(
					// translators: %s: Alma dashboard url.
					__( 'Your Alma account needs to be activated before you can use Alma on your shop.<br>Go to your <a href="%1$s" target="_blank">Alma dashboard</a> to activate your account.<br><a href="%2$s">Refresh</a> the page when ready.', 'alma-woocommerce-gateway' ),
					esc_url( $dashboard_url ),
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
		load_plugin_textdomain( 'alma-woocommerce-gateway', false, plugin_basename( $this->plugin_path ) . '/languages' );
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
		return $this->plugin_url . 'assets/' . $path;
	}

	/** WEBHOOKS HANDLERS **/
	private function get_payment_to_validate() {
		$payment_id = $_GET['pid'];

		if ( ! $payment_id ) {
			$this->logger->error( 'Payment validation webhook called without a payment ID' );

			wc_add_notice(
				__( 'Payment validation error: no ID provided.<br>Please try again or contact us if the problem persists.', 'alma-woocommerce-gateway' ),
				'error'
			);

			wp_redirect( wc_get_cart_url() );
			die();
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
}
