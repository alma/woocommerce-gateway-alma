<?php
/**
 * Alma payment validator
 *
 * @package Alma_WooCommerce_Gateway
 */

use Alma\API\Entities\Instalment;
use Alma\API\Entities\Payment;
use Alma\API\RequestError;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alma_WC_Payment_Validator
 */
class Alma_WC_Payment_Validator {

	/**
	 * Validate payment
	 *
	 * @param string $payment_id Payment Id.
	 *
	 * @return Alma_WC_Order
	 *
	 * @throws Alma_WC_Payment_Validation_Error Alma WC payment validation error.
	 */
	public static function validate_payment( $payment_id ) {
		$logger = new Alma_WC_Logger();

		$alma = alma_wc_plugin()->get_alma_client();
		if ( ! $alma ) {
			throw new Alma_WC_Payment_Validation_Error( 'api_client_init' );
		}

		try {
			$payment = $alma->payments->fetch( $payment_id );
		} catch ( RequestError $e ) {
			$logger->error( 'Error while fetching payment with id ' . $payment_id . ': ' . $e->getMessage() );
			throw new Alma_WC_Payment_Validation_Error( 'payment_fetch_error' );
		}

		try {
			$order = new Alma_WC_Order( $payment->custom_data['order_id'], $payment->custom_data['order_key'] );
		} catch ( \Exception $e ) {
			$logger->error( "Error getting order associated to payment '$payment_id': " . $e->getMessage() );
			throw new Alma_WC_Payment_Validation_Error( 'order_fetch_error' );
		}

		if ( $order->get_wc_order()->has_status( apply_filters( 'alma_wc_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled' ) ) ) ) {
			if ( $order->get_total() !== $payment->purchase_amount ) {
				$error = "Order {$order->get_id()} total ({$order->get_total()}) does not match purchase amount of '$payment_id' ({$payment->purchase_amount})";
				$logger->error( $error );

				try {
					$logger->warning( "Amount mismatch for order {$order->get_id()}" );
					$alma->payments->flagAsPotentialFraud( $payment_id, Payment::FRAUD_AMOUNT_MISMATCH );
				} catch ( RequestError $e ) {
					$logger->warning( $e->getMessage() );
				}

				throw new Alma_WC_Payment_Validation_Error( $error );
			}

			$first_instalment = $payment->payment_plan[0];
			if ( ! in_array( $payment->state, array( Payment::STATE_IN_PROGRESS, Payment::STATE_PAID ), true ) ||
				Instalment::STATE_PAID !== $first_instalment->state ) {

				$error = "Payment '$payment_id': state incorrect {$payment->state} & {$first_instalment->state}";
				$logger->error( $error );

				try {
					$logger->warning( "Payment state error for order {$order->get_id()}" );
					$alma->payments->flagAsPotentialFraud( $payment_id, Payment::FRAUD_STATE_ERROR );
				} catch ( RequestError $e ) {
					$logger->warning( $e->getMessage() );
				}

				throw new Alma_WC_Payment_Validation_Error( $error );
			}

			// If we're down here, everything went OK and we  can validate the order!
			$order->payment_complete( $payment_id );
		}

		return $order;
	}

}
