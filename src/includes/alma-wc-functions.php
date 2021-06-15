<?php
/**
 * HELPER FUNCTIONS
 *
 * @package Alma_WooCommerce_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Not allowed' ); // Exit if accessed directly.
}

/**
 * Converts a float price to its integer cents value, used by the API.
 *
 * @param float $price Price.
 *
 * @return integer
 */
function alma_wc_price_to_cents( $price ) {
	return (int) ( round( $price * 100 ) );
}

/**
 * Converts an integer price in cents to a float price in the used currency units.
 *
 * @param int $price Price.
 *
 * @return float
 */
function alma_wc_price_from_cents( $price ) {
	return (float) ( $price / 100 );
}


/**
 * Merge arrays recursively.
 * From https://secure.php.net/manual/en/function.array-merge-recursive.php#104145
 * Addition: will automatically discard null values
 *
 * @return array
 * @throws RuntimeException Throws when argument count invalid or an argument is not an array.
 */
function alma_wc_array_merge_recursive() {
	if ( func_num_args() < 2 ) {
		throw new RuntimeException( __FUNCTION__ . ' needs two or more array arguments', E_USER_WARNING );
	}
	$arrays = func_get_args();
	$merged = array();
	while ( $arrays ) {
		$array = array_shift( $arrays );
		if ( null === $array ) {
			continue;
		}
		if ( ! is_array( $array ) ) {
			throw new RuntimeException( __FUNCTION__ . ' encountered a non array argument', E_USER_WARNING );
		}
		if ( ! $array ) {
			continue;
		}
		foreach ( $array as $key => $value ) {
			if ( is_string( $key ) ) {
				if ( is_array( $value ) && array_key_exists( $key, $merged ) && is_array( $merged[ $key ] ) ) {
					$merged[ $key ] = call_user_func( __FUNCTION__, $merged[ $key ], $value );
				} else {
					$merged[ $key ] = $value;
				}
			} else {
				$merged[] = $value;
			}
		}
	}

	return $merged;
}

/**
 * Converts a string (e.g. 'yes' or 'no') to a bool.
 *
 * Taken from WooCommerce, to which it was added in version 3.0.0 and we need support for older WC versions.
 *
 * @param string $string String to convert.
 * @return bool
 */
function alma_wc_string_to_bool( $string ) {
	return is_bool( $string )
		? $string
		: ( 'yes' === strtolower( $string ) || 1 === $string || 'true' === strtolower( $string ) || '1' === $string );
}
