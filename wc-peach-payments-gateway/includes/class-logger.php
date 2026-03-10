<?php
/**
 * Class PP_Gateway_Logger
 *
 * Centralized logging utility for the Peach Payments Gateway.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Logger {

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger
	 */
	protected static $logger;

	/**
	 * Log context
	 *
	 * @var string
	 */
	protected static $context = 'peach_payments';

	/**
	 * Get WooCommerce logger instance
	 *
	 * @return WC_Logger
	 */
	protected static function get_logger() {
		if ( ! self::$logger ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	/**
	 * Add info log entry.
	 *
	 * @param string $message
	 */
	public static function info( $message ) {
		self::get_logger()->info( $message, [ 'source' => self::$context ] );
	}

	/**
	 * Add error log entry.
	 *
	 * @param string $message
	 */
	public static function error( $message ) {
		self::get_logger()->error( $message, [ 'source' => self::$context ] );
	}

	/**
	 * Add debug log entry.
	 *
	 * @param string $message
	 */
	public static function debug( $message ) {
		self::get_logger()->debug( $message, [ 'source' => self::$context ] );
	}

	/**
	 * Add warning log entry.
	 *
	 * @param string $message
	 */
	public static function warning( $message ) {
		self::get_logger()->warning( $message, [ 'source' => self::$context ] );
	}

	/**
	 * Log any type of message with level.
	 *
	 * @param string $level
	 * @param string $message
	 */
	public static function log( $level, $message ) {
		$logger = self::get_logger();

		switch ( strtolower( $level ) ) {
			case 'info':
				$logger->info( $message, [ 'source' => self::$context ] );
				break;
			case 'error':
				$logger->error( $message, [ 'source' => self::$context ] );
				break;
			case 'warning':
				$logger->warning( $message, [ 'source' => self::$context ] );
				break;
			case 'debug':
			default:
				$logger->debug( $message, [ 'source' => self::$context ] );
				break;
		}
	}
}
