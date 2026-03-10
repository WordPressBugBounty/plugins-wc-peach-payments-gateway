<?php
/**
 * Handles subscription renewals for Peach Payments.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Subscription_Handler {

	/**
	 * Register hooks.
	 */
	public static function register() {
		
	}

	/**
	 * Process a scheduled subscription payment.
	 *
	 * @param float      $amount_to_charge Amount to charge.
	 * @param WC_Order   $order            Renewal order object.
	 */
	public static function process_renewal_payment( $amount_to_charge, $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			PP_Gateway_Logger::error( "Invalid order passed for renewal." );
			return;
		}
		
		$order_id = $order->get_id();

		if ( wcs_order_contains_renewal( $order_id ) ) {
			$parent_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order_id );
		} else {
			$parent_order_id = $order_id;
		}
		
		$parent_order = wc_get_order( $parent_order_id );
		
		if ( ! is_a( $parent_order, 'WC_Order' ) ) {
			$order->update_status( 'failed', __( 'Missing parent order.', WC_PEACH_TEXT_DOMAIN ) );
			$order->add_order_note( "Peach Payments Renewal Failed — missing parent order for order #{$order_id}", 0, false );
			PP_Gateway_Logger::error( "Renewal failed — missing parent order for order #{$order_id}" );
			return;
		}
		
		$registration_id = $parent_order->get_meta( '_peach_subscription_payment_method', true );
		$plgvs = 'V2';
		
		if ( empty( $registration_id ) ) {
			$registration_id = $parent_order->get_meta( 'payment_registration_id', true );
			$plgvs = 'V4';
		}
		
		// Optional fallback 1: if any legacy code ever saved it with underscore (HPOS-safe)
		if ( empty( $registration_id ) ) {
			$registration_id = $parent_order->get_meta( '_payment_registration_id', true );
			$plgvs = 'V4';
		}
		
		// Optional fallback 2: old get_post_meta
		if ( empty( $registration_id ) ) {
			$registration_id = get_post_meta( $parent_order_id, 'payment_registration_id', true );
			$plgvs = 'V4';
		}
		
		// Optional fallback 3: old get_post_meta legacy underscore
		if ( empty( $registration_id ) ) {
			$registration_id = get_post_meta( $parent_order_id, '_payment_registration_id', true );
			$plgvs = 'V4';
		}
		
		// Minimal extra hardening: also check the current order (renewal) meta
		if ( empty( $registration_id ) ) {
			$registration_id = $order->get_meta( 'payment_registration_id', true );
			$plgvs = 'V4';
		}
		
		$registration_id = is_string( $registration_id ) ? trim( $registration_id ) : $registration_id;
		
		if ( ! is_string( $registration_id ) || $registration_id === '' ) {
			$order->update_status( 'failed', __( 'Missing saved card token (registration ID).', WC_PEACH_TEXT_DOMAIN ) );
			$order->add_order_note( "Peach Payments Renewal Failed — missing saved card token (registration ID)", 0, false );
			PP_Gateway_Logger::error( "Renewal failed — missing registration ID for order #{$order_id} (parent #{$parent_order_id})" );
			return;
		}

		$api = new PP_Peach_API();
		$response = $api->charge_saved_card( $registration_id, $order, $amount_to_charge );

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			$order->update_status( 'failed', $message );
			$order->add_order_note( "Peach Payments Renewal failed for order #{$order->get_id()}: " . $message,0,false);
			PP_Gateway_Logger::error( "Renewal failed for order #{$parent_order_id}: " . $message );
			return;
		}

		PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $response );
	}
}
