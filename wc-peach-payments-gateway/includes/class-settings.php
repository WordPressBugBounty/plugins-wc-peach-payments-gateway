<?php
defined( 'ABSPATH' ) || exit;

class PP_Gateway_Settings {
	public static function get( $key ) {
		$settings = get_option( 'woocommerce_peach-payments_settings', [] );
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}
}
