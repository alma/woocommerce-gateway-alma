<?php
/**
 * Alma customer
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Alma_WC_Customer
 */
class Alma_WC_Customer {
	/**
	 * Legacy
	 *
	 * @var bool
	 */
	private $legacy = false;

	/**
	 * Customer
	 *
	 * @var WC_Customer|WP_User
	 */
	private $customer;

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		$this->legacy = version_compare( wc()->version, '3.0.0', '<' );

		if ( $this->legacy ) {
			$this->customer = wp_get_current_user();
		} else {
			$this->customer = wc()->customer;
		}
	}

	/**
	 * Has data
	 *
	 * @return bool
	 */
	public function has_data() {
		return ( $this->legacy && 0 !== $this->customer->ID ) || $this->customer->get_id();
	}

	/**
	 * Get data (legacy).
	 *
	 * @return array
	 */
	private function _get_legacy_data() {
		$data = array(
			'first_name' => $this->customer->first_name,
			'last_name'  => $this->customer->last_name,
			'email'      => $this->customer->user_email,
		);

		$data['addresses'] = array(
			$this->get_billing_address(),
			$this->get_shipping_address(),
		);

		return $data;
	}

	/**
	 * Get data (not legacy).
	 *
	 * @return array
	 */
	private function _get_data() {
		$data = array(
			'first_name' => $this->customer->get_first_name(),
			'last_name'  => $this->customer->get_last_name(),
			'email'      => $this->customer->get_email(),
			'phone'      => $this->customer->get_billing_phone(),
		);

		foreach ( array( 'first_name', 'last_name', 'email', 'phone' ) as $attr ) {
			$method = "get_billing_$attr";
			if ( empty( $data[ $attr ] ) && method_exists( $this->customer, $method ) ) {
				$data[ $attr ] = $this->customer->$method();
			}

			$method = "get_shipping_$attr";
			if ( empty( $data[ $attr ] ) && method_exists( $this->customer, $method ) ) {
				$data[ $attr ] = $this->customer->$method();
			}
		}

		$data['addresses'] = array(
			$this->get_billing_address(),
			$this->get_shipping_address(),
		);

		return $data;
	}

	/**
	 * Get data.
	 *
	 * @return array
	 */
	public function get_data() {
		if ( $this->legacy ) {
			return $this->_get_legacy_data();
		} else {
			return $this->_get_data();
		}
	}

	/**
	 * Get billing address.
	 *
	 * @return array
	 */
	public function get_billing_address() {
		if ( $this->legacy ) {
			$customer = wc()->customer;

			return array(
				'first_name'  => $this->customer->first_name,
				'last_name'   => $this->customer->last_name,
				'line1'       => $customer->get_address(),
				'line2'       => $customer->get_address_2(),
				'postal_code' => $customer->get_postcode(),
				'city'        => $customer->get_city(),
				'country'     => $customer->get_country(),
			);
		} else {
			return array(
				'first_name'  => $this->customer->get_billing_first_name(),
				'last_name'   => $this->customer->get_billing_last_name(),
				'line1'       => $this->customer->get_billing_address(),
				'line2'       => $this->customer->get_billing_address_2(),
				'postal_code' => $this->customer->get_billing_postcode(),
				'city'        => $this->customer->get_billing_city(),
				'country'     => $this->customer->get_billing_country(),
				'email'       => $this->customer->get_billing_email(),
				'phone'       => $this->customer->get_billing_phone(),
			);
		}
	}

	/**
	 * Get shipping address.
	 *
	 * @return array
	 */
	public function get_shipping_address() {
		if ( $this->legacy ) {
			$customer = wc()->customer;

			return array(
				'first_name'  => $this->customer->first_name,
				'last_name'   => $this->customer->last_name,
				'line1'       => $customer->get_shipping_address(),
				'line2'       => $customer->get_shipping_address_2(),
				'postal_code' => $customer->get_shipping_postcode(),
				'city'        => $customer->get_shipping_city(),
				'country'     => $customer->get_shipping_country(),
			);
		} else {
			return array(
				'first_name'  => $this->customer->get_shipping_first_name(),
				'last_name'   => $this->customer->get_shipping_last_name(),
				'line1'       => $this->customer->get_shipping_address(),
				'line2'       => $this->customer->get_shipping_address_2(),
				'postal_code' => $this->customer->get_shipping_postcode(),
				'city'        => $this->customer->get_shipping_city(),
				'country'     => $this->customer->get_shipping_country(),
			);
		}
	}
}
