<?php
defined( 'ABSPATH' ) || exit;

class PP_Gateway_Subscription_Utils {

	public static function is_subscription( $order ) {
		return function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order );
	}

	public static function is_renewal( $order ) {
		return function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order );
	}

	public static function get_parent_order( $order ) {
		if ( self::is_renewal( $order ) && method_exists( $order, 'get_parent_id' ) ) {
			return wc_get_order( $order->get_parent_id() );
		}
		return false;
	}
}
