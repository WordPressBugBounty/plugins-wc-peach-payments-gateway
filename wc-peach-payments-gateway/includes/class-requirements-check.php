<?php
/**
 * Class PP_Gateway_Requirements
 *
 * Checks environment and dependencies for the Peach Payments Gateway.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Requirements {

	/**
	 * Perform all checks.
	 *
	 * @return bool
	 */
	public static function check() {
		// Check WooCommerce
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'woocommerce_missing_notice' ] );
			PP_Gateway_Logger::error( 'WooCommerce is not active.' );
			return false;
		}

		// Check WooCommerce version
		if ( version_compare( WC()->version, '5.7', '<' ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'woocommerce_version_notice' ] );
			PP_Gateway_Logger::error( 'WooCommerce version is below 5.7.' );
			return false;
		}

		// Check PHP version
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'php_version_notice' ] );
			PP_Gateway_Logger::error( 'PHP version is below 7.4.' );
			return false;
		}

		// Check SSL
		if ( ! is_ssl() ) {
			add_action( 'admin_notices', [ __CLASS__, 'ssl_notice' ] );
			PP_Gateway_Logger::warning( 'SSL is not enabled.' );
		}

		// Check for multiple Sequential Order Numbers plugins
		if ( class_exists( 'WC_Sequential_Order_Numbers_Pro_Loader' ) && class_exists( 'WC_Sequential_Order_Numbers_Loader' ) ) {
			add_action( 'admin_notices', [ __CLASS__, 'sequential_order_warning' ] );
			PP_Gateway_Logger::warning( 'Multiple Sequential Order Numbers plugins are active.' );
		}

		return true;
	}

	/**
	 * Display notice for missing WooCommerce.
	 */
	public static function woocommerce_missing_notice() {
		echo '<div class="notice notice-error"><p><strong>WooCommerce must be installed and active to use the Peach Payments Gateway.</strong></p></div>';
	}

	/**
	 * Display notice for outdated WooCommerce.
	 */
	public static function woocommerce_version_notice() {
		echo '<div class="notice notice-error"><p><strong>The Peach Payments Gateway requires WooCommerce 5.7 or higher.</strong></p></div>';
	}

	/**
	 * Display notice for outdated PHP.
	 */
	public static function php_version_notice() {
		echo '<div class="notice notice-error"><p><strong>The Peach Payments Gateway requires PHP 7.4 or higher.</strong></p></div>';
	}

	/**
	 * Display notice for missing SSL.
	 */
	public static function ssl_notice() {
		echo '<div class="notice notice-warning is-dismissible"><p><strong>SSL is not enabled. Peach Payments requires HTTPS to process payments securely.</strong></p></div>';
	}

	/**
	 * Display warning for multiple sequential order number plugins.
	 */
	public static function sequential_order_warning() {
		echo '<div class="notice notice-warning is-dismissible"><p><strong>Multiple Sequential Order Numbers plugins detected. This may cause issues with order ID generation.</strong></p></div>';
	}
}
