<?php
/**
 * Alma Autoloader
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alma Autoloader.
 */
class Alma_WC_Autoloader {
	/**
	 * Singleton (autoloader is loaded if instance is populated)
	 *
	 * @var Alma_WC_Autoloader
	 */
	private static $instance;

	/**
	 * Path to the includes directory.
	 *
	 * @var string
	 */
	private $include_path;

	/**
	 * The Constructor.
	 *
	 * @throws Exception If sp_autoload_register fail.
	 */
	public function __construct() {
		if ( function_exists( '__autoload' ) ) {
			spl_autoload_register( '__autoload' );
		}

		spl_autoload_register( array( $this, 'load_class' ) );

		$this->include_path = untrailingslashit( ALMA_WC_PLUGIN_PATH ) . '/includes/';
	}

	/**
	 * Initialise auto loading
	 */
	public static function autoload() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
	}

	/**
	 * Take a class name and turn it into a file name.
	 *
	 * @param string $class as class name.
	 *
	 * @return string
	 */
	private function get_file_name_from_class( $class ) {
		return 'class-' . str_replace( '_', '-', $class ) . '.php';
	}

	/**
	 * Include a class file.
	 *
	 * @param string $path as php file path.
	 *
	 * @return bool successful or not
	 * @noinspection PhpIncludeInspection
	 */
	private function load_file( $path ) {
		if ( $path && is_readable( $path ) ) {
			include_once $path;

			return true;
		}

		return false;
	}

	/**
	 * Auto-load WC classes on demand to reduce memory consumption.
	 *
	 * @param string $class as class name.
	 */
	public function load_class( $class ) {
		$class = strtolower( $class );
		$file  = $this->get_file_name_from_class( $class );
		$path  = '';

		if ( preg_match( '#^alma_wc_model_#', $class ) ) {
			$path = $this->include_path . 'models/';
		}

		if ( empty( $path ) || ( ! $this->load_file( $path . $file ) && strpos( $class, 'wc_' ) === 0 ) ) {
			$this->load_file( $this->include_path . $file );
		}
	}
}
