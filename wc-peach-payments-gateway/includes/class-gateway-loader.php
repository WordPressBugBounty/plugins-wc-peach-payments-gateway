<?php
/**
 * Gateway Loader Class
 *
 * Registers Peach Payments gateway with WooCommerce.
 *
 * @package WooCommerce\PeachPayments
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Loader {

	/**
	 * Hook into WooCommerce and load our gateway
	 */
	public static function init() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_gateway' ) );
	}

	/**
	 * Add the gateway to WooCommerce's list
	 *
	 * @param array $gateways Existing gateways.
	 * @return array
	 */
	public static function add_gateway( $gateways ) {
		if ( class_exists( 'WC_Gateway_Peach_Hosted' ) ) {
			$gateways[] = 'WC_Gateway_Peach_Hosted';
		}
		return $gateways;
	}
}

PP_Gateway_Loader::init();
