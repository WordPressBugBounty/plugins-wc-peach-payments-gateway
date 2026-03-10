<?php
defined( 'ABSPATH' ) || exit;

class PP_Gateway_Status_Mapper {

	/**
	 * Determines if a Peach result code indicates success.
	 */
	public static function is_success( $code ) {
		return preg_match( '/^000\.000\./', $code ) ||
		       in_array( $code, [ '000.100.110', '100.396.101' ], true );
	}

	/**
	 * Returns the order status to apply on success, based on plugin setting.
	 */
	public static function get_success_status() {
		$status = PP_Gateway_Settings::get( 'peach_order_status' );
		return $status && in_array( $status, wc_get_order_statuses(), true )
			? str_replace( 'wc-', '', $status )
			: 'processing';
	}

	/**
	 * Returns the final status to set based on result code.
	 */
	public static function get_order_status( $result_code ) {
		return self::is_success( $result_code ) ? self::get_success_status() : 'failed';
	}
}
