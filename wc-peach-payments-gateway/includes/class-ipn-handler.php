<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles Peach Payments IPN notifications (step 4 of hosted checkout).
 */
class PP_Gateway_IPN_Handler {

	public static function init() {
		add_action( 'woocommerce_api_wc_gateway_peach_hosted', [ __CLASS__, 'handle_ipn' ] );
	}

	public static function handle_ipn() {
		$raw_body = file_get_contents( 'php://input' );
		$payload  = json_decode( $raw_body, true );

		if ( empty( $payload['merchantTransactionId'] ) || empty( $payload['status'] ) ) {
			status_header( 400 );
			echo 'Missing required parameters';
			exit;
		}

		$order_number = sanitize_text_field( $payload['merchantTransactionId'] );
		$order = PP_Gateway_Order_Utils::find_order_by_number( $order_number );

		if ( ! $order || ! $order instanceof WC_Order ) {
			status_header( 404 );
			echo 'Order not found';
			exit;
		}

		$status      = $payload['status'];
		$order_id    = $order->get_id();
		$payment_id  = $payload['id'] ?? '';
		$reg_id      = $payload['registrationId'] ?? '';

		// Prevent duplicates
		if ( $order->get_meta( 'peach_ipn_handled' ) ) {
			status_header( 200 );
			echo 'Already handled';
			exit;
		}

		// A WC_Subscription object represents an existing-subscription payment-method
		// change. Legacy IPN data must not alter its lifecycle or recurring-payment
		// meta; verified hosted/webhook processing handles a successful new card.
		$is_payment_method_change = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );
		if ( $is_payment_method_change ) {
			PP_Gateway_Logger::info( 'Peach IPN received for subscription payment-method change #' . $order_id . ' with status ' . sanitize_text_field( (string) $status ) . '. Subscription and existing payment method left unchanged.' );
			status_header( 200 );
			echo 'OK';
			exit;
		}

		// Store meta fields
		if ( $payment_id ) {
			$order->update_meta_data( 'payment_order_id', $payment_id );
		}
		if ( $reg_id ) {
			$order->update_meta_data( 'payment_registration_id', $reg_id );
		}

		// Status mapping
		switch ( $status ) {
			case 'PAID':
			case 'SUCCESS':
			case 'COMPLETED':
				$order->payment_complete( $payment_id );
				$order_status = PP_Gateway_Settings::get( 'peach_order_status' ) ?: 'processing';
				$order->update_status( $order_status );
				$order->add_order_note( 'Payment successful via Peach. Status: ' . $status );
				break;

			case 'FAILED':
			case 'ERROR':
			case 'DECLINED':
				$order->update_status( 'failed', 'Payment failed via Peach. Status: ' . $status );
				break;

			case 'PENDING':
			default:
				$order->update_status( 'on-hold', 'Payment is pending via Peach. Status: ' . $status );
				break;
		}

		$order->update_meta_data( 'peach_ipn_handled', true );
		$order->save();

		if ( class_exists( 'PP_Gateway_Subscription_Handler' ) && in_array( $status, [ 'PAID', 'SUCCESS', 'COMPLETED' ], true ) ) {
			PP_Gateway_Subscription_Handler::sync_payment_meta_from_order_to_subscriptions( $order, 'ipn_success' );
		}

		status_header( 200 );
		echo 'OK';
		exit;
	}
}
