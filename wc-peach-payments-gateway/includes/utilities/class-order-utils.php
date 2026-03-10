<?php
/**
 * Utility class for handling Peach Payments order-related logic.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Order_Utils {

	/**
	 * Check if an order contains a subscription product.
	 *
	 * @param int|\WC_Order $order Order ID or WC_Order object.
	 * @return bool
	 */
	public static function order_has_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}

		$order = is_numeric( $order ) ? wc_get_order( $order ) : $order;
		return wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order );
	}

	/**
	 * Get all orders where the card registration ID was used.
	 *
	 * @param string $registration_id
	 * @param int    $user_id
	 * @return array List of matching order IDs.
	 */
	public static function get_orders_using_card( $registration_id, $user_id = null ) {
		if ( empty( $registration_id ) ) {
			return array();
		}

		$args = array(
			'limit'        => -1,
			'customer_id'  => $user_id,
			'meta_key'     => '_peach_registration_id',
			'meta_value'   => $registration_id,
			'return'       => 'ids',
		);

		return wc_get_orders( $args );
	}

	/**
	 * Check if a card is associated with any subscription.
	 *
	 * @param string $registration_id
	 * @param int    $user_id
	 * @return bool
	 */
	public static function is_card_used_for_subscription( $registration_id, $user_id = null ) {
		$order_ids = self::get_orders_using_card( $registration_id, $user_id );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( self::order_has_subscription( $order ) ) {
				return true;
			}
		}

		return false;
	}
}
