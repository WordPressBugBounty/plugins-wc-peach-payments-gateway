<?php
/**
 * Handles admin notices for the Peach Payments Gateway plugin.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Admin_Notices {

	/**
	 * Show all relevant admin notices.
	 */
	public static function show_notices() {
		self::check_ssl();
		self::check_woocommerce_version();
		self::check_php_version();
		self::check_sequential_plugins();
	}

	/**
	 * Display notice if site is not using SSL.
	 */
	protected static function check_ssl() {
		if ( ! is_ssl() ) {
			self::render_notice(
				'error',
				__( '<strong>Peach Payments:</strong> Your site is not using SSL. Payment gateway will not work without HTTPS.', WC_PEACH_TEXT_DOMAIN )
			);
		}
	}

	/**
	 * Check WooCommerce version.
	 */
	protected static function check_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '5.7', '<' ) ) {
			self::render_notice(
				'error',
				sprintf(
					__( '<strong>Peach Payments:</strong> WooCommerce version %s or higher is required. You are using version %s.', WC_PEACH_TEXT_DOMAIN ),
					'5.7',
					WC_VERSION
				)
			);
		}
	}

	/**
	 * Check PHP version.
	 */
	protected static function check_php_version() {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			self::render_notice(
				'error',
				sprintf(
					__( '<strong>Peach Payments:</strong> PHP version %s or higher is recommended. You are using version %s.', WC_PEACH_TEXT_DOMAIN ),
					'7.4',
					PHP_VERSION
				)
			);
		}
	}

	/**
	 * Check if multiple sequential order number plugins are active.
	 */
	protected static function check_sequential_plugins() {
		$plugins = array(
			'WC_Sequential_Order_Numbers_Pro_Loader',
			'WC_Sequential_Order_Numbers_Loader',
		);

		$active = array_filter( $plugins, function ( $class ) {
			return class_exists( $class );
		} );

		if ( count( $active ) > 1 ) {
			self::render_notice(
				'warning',
				__( '<strong>Peach Payments:</strong> Multiple sequential order number plugins detected. This may lead to conflicts. Please ensure only one is active.', WC_PEACH_TEXT_DOMAIN ),
				true
			);
		}
	}

	/**
	 * Render an admin notice.
	 *
	 * @param string $type Notice type (error, warning, info, success).
	 * @param string $message Message to display.
	 * @param bool   $dismissible Whether the notice is dismissible.
	 */
	protected static function render_notice( $type, $message, $dismissible = false ) {
		$class = "notice notice-{$type}";
		if ( $dismissible ) {
			$class .= ' is-dismissible';
		}
		echo '<div class="' . esc_attr( $class ) . '"><p>' . wp_kses_post( $message ) . '</p></div>';
	}
}
