<?php
/**
 * Handles subscription renewals for Peach Payments.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Subscription_Handler {

	/**
	 * Track admin renewal action pre-state per subscription request.
	 *
	 * @var array
	 */
	protected static $admin_action_pre_state = [];

	/**
	 * Track scheduled renewal pre-state per subscription.
	 *
	 * @var array
	 */
	protected static $scheduled_action_pre_state = [];

	/**
	 * Track admin renewal orders processed by this request-level fallback.
	 *
	 * @var array
	 */
	protected static $admin_fallback_processed_orders = [];

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'woocommerce_scheduled_subscription_payment_retry', [ __CLASS__, 'log_scheduled_payment_retry' ], 5, 1 );
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_filter( 'woocommerce_subscription_payment_meta', [ __CLASS__, 'add_subscription_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', [ __CLASS__, 'validate_subscription_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'maybe_force_admin_process_renewal' ], 999 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'maybe_force_admin_create_pending_renewal' ], 999 );
		add_action( 'woocommerce_scheduled_subscription_payment', [ __CLASS__, 'prepare_scheduled_renewal_request' ], 5, 1 );
	}

	/**
	 * Process a scheduled subscription payment.
	 *
	 * @param float    $amount_to_charge Amount to charge.
	 * @param WC_Order $order            Renewal order object.
	 */
	public static function process_renewal_payment( $amount_to_charge, $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			PP_Gateway_Logger::error( 'Invalid order passed for renewal.' );
			return;
		}

		$order_id = $order->get_id();

		if ( self::renewal_payment_already_processed( $order ) ) {
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because this renewal order is already paid or already processed.' );
			return;
		}

		if ( wcs_order_contains_renewal( $order_id ) ) {
			$parent_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order_id );
		} else {
			$parent_order_id = $order_id;
		}

		$parent_order = wc_get_order( $parent_order_id );

		if ( ! is_a( $parent_order, 'WC_Order' ) ) {
			$order->add_order_note( sprintf( 'Peach Payments renewal failed — missing parent order for renewal order #%d.', $order_id ), 0, false );
			PP_Gateway_Order_Utils::handle_subscription_payment_failure(
				$order,
				__( 'Missing parent order.', WC_PEACH_TEXT_DOMAIN ),
				'',
				[
					'parent_order_id' => $parent_order_id,
					'reason'          => 'missing_parent_order',
				]
			);
			return;
		}

		$payment_data = self::get_recurring_payment_data( $order, $parent_order );
		$registration_id = $payment_data['registration_id'];


		if ( ! is_string( $registration_id ) || '' === $registration_id ) {
			$order->add_order_note( 'Peach Payments renewal failed — missing saved card token (registration ID).', 0, false );
			PP_Gateway_Order_Utils::handle_subscription_payment_failure(
				$order,
				__( 'Missing saved card token (registration ID).', WC_PEACH_TEXT_DOMAIN ),
				'',
				[
					'parent_order_id' => $parent_order_id,
					'reason'          => 'missing_registration_id',
					'registration_source' => $payment_data['source'],
				]
			);
			return;
		}

		// Do not block a new renewal order merely because another renewal for the same
		// subscription and amount was paid recently. WooCommerce Subscriptions can
		// legitimately create another renewal order during admin testing, catch-up
		// processing, or short renewal intervals. Per-order and active subscription
		// locks below still prevent concurrent duplicate charges.

		if ( ! self::acquire_renewal_payment_lock( $order ) ) {
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because another request is already processing this renewal order.' );
			return;
		}

		if ( ! self::acquire_subscription_renewal_charge_locks( $order ) ) {
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because another Peach renewal charge is already processing for the related subscription.' );
			self::release_renewal_payment_lock( $order );
			return;
		}

		try {
			if ( self::renewal_payment_already_processed( $order ) ) {
				self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because this renewal order became paid or processed while waiting.' );
				return;
			}

			// After the active processing locks have been acquired, only this exact
			// renewal order should be treated as idempotent. A separate renewal order
			// is a separate WooCommerce Subscriptions billing event and must be allowed
			// to charge even if it is close in time to the previous renewal.

			$api      = new PP_Peach_API();
			$response = $api->charge_saved_card( $registration_id, $order, $amount_to_charge );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				$order->add_order_note( 'Peach Payments renewal failed: ' . $message, 0, false );
				PP_Gateway_Order_Utils::handle_subscription_payment_failure(
					$order,
					$message,
					'',
					[
						'parent_order_id'      => $parent_order_id,
						'reason'               => 'api_wp_error',
						'registration_source'  => $payment_data['source'],
						'registration_id_tail' => self::mask_meta_value( $registration_id ),
					]
				);
				return;
			}

			self::mark_renewal_payment_processed( $order, isset( $response['id'] ) ? $response['id'] : '', 'api_charge_saved_card' );
			PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $response );
		} catch ( Throwable $e ) {
			$message = $e->getMessage();
			$order->add_order_note( 'Peach Payments renewal failed because an unexpected error occurred: ' . $message, 0, false );
			PP_Gateway_Logger::error( sprintf( 'Unexpected error while processing Peach renewal order #%1$d: %2$s in %3$s:%4$d', $order_id, $message, $e->getFile(), $e->getLine() ) );
			PP_Gateway_Order_Utils::handle_subscription_payment_failure(
				$order,
				sprintf( __( 'Unexpected renewal processing error: %s', WC_PEACH_TEXT_DOMAIN ), $message ),
				'',
				[
					'parent_order_id' => $parent_order_id,
					'reason'          => 'unexpected_throwable',
				]
			);
		} finally {
			self::release_subscription_renewal_charge_locks( $order );
			self::release_renewal_payment_lock( $order );
		}
	}


	/**
	 * Capture existing renewal orders before WooCommerce Subscriptions processes an admin renewal action.
	 *
	 * @param WC_Order|WC_Subscription $subscription Subscription object.
	 */
	public static function capture_admin_renewal_pre_state( $subscription ) {
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		self::$admin_action_pre_state[ $subscription->get_id() ] = self::get_related_renewal_order_ids( $subscription );
	}

	/**
	 * Prepare admin renewal actions by ensuring recurring meta is available before processing.
	 *
	 * @param WC_Order|WC_Subscription $subscription Subscription object.
	 */
	public static function prepare_admin_renewal_request( $subscription ) {
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		$backfilled = self::maybe_backfill_subscription_payment_meta_from_parent( $subscription );

		if ( $backfilled ) {
			$subscription->save();
		}
	}

	/**
	 * Fallback the admin Process Renewal action if Subscriptions does not create a renewal order.
	 *
	 * @param WC_Order|WC_Subscription $subscription Subscription object.
	 */
	public static function maybe_force_admin_process_renewal( $subscription ) {
		self::maybe_force_admin_renewal_action( $subscription, true );
	}

	/**
	 * Fallback the admin Create Pending Renewal action if Subscriptions does not create a renewal order.
	 *
	 * @param WC_Order|WC_Subscription $subscription Subscription object.
	 */
	public static function maybe_force_admin_create_pending_renewal( $subscription ) {
		self::maybe_force_admin_renewal_action( $subscription, false );
	}

	/**
	 * Capture existing renewal orders before WooCommerce Subscriptions processes a scheduled renewal action.
	 *
	 * @param int|WC_Subscription $subscription Subscription ID or object.
	 */
	public static function capture_scheduled_renewal_pre_state( $subscription ) {
		$subscription = self::normalize_subscription( $subscription );
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		self::$scheduled_action_pre_state[ $subscription->get_id() ] = self::get_related_renewal_order_ids( $subscription );
	}

	/**
	 * Prepare scheduled renewal processing by ensuring recurring meta is present before Subscriptions runs.
	 *
	 * @param int|WC_Subscription $subscription Subscription ID or object.
	 */
	public static function prepare_scheduled_renewal_request( $subscription ) {
		$subscription = self::normalize_subscription( $subscription );
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		$backfilled = self::maybe_backfill_subscription_payment_meta_from_parent( $subscription );


		if ( $backfilled ) {
			$subscription->save();
		}
	}

	/**
	 * Fallback the automatic scheduled renewal action if Subscriptions does not create a renewal order.
	 *
	 * @param int|WC_Subscription $subscription Subscription ID or object.
	 */
	public static function maybe_force_scheduled_subscription_payment( $subscription ) {
		$subscription = self::normalize_subscription( $subscription );
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

	}

	/**
	 * Create/process a fallback renewal order when the core admin action does not produce one.
	 *
	 * @param WC_Order|WC_Subscription $subscription   Subscription object.
	 * @param bool                     $process_payment Whether to process payment immediately.
	 */
	protected static function maybe_force_admin_renewal_action( $subscription, $process_payment ) {
		$subscription = self::normalize_subscription( $subscription );

		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		$subscription_id = $subscription->get_id();
		$before_ids      = isset( self::$admin_action_pre_state[ $subscription_id ] ) ? self::$admin_action_pre_state[ $subscription_id ] : [];
		$after_ids       = self::get_related_renewal_order_ids( $subscription );
		$new_order_ids   = array_values( array_diff( $after_ids, $before_ids ) );

		if ( ! empty( $new_order_ids ) ) {
			$renewal_order_id = absint( end( $new_order_ids ) );
			$renewal_order    = wc_get_order( $renewal_order_id );

			if ( is_a( $renewal_order, 'WC_Order' ) ) {
				self::ensure_renewal_order_has_peach_data( $renewal_order, $subscription );

				if ( $process_payment ) {
					self::maybe_process_admin_renewal_order( $subscription, $renewal_order, 'woocommerce_core_created_order' );
				}
			}

			unset( self::$admin_action_pre_state[ $subscription_id ] );
			return;
		}

		if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
			$message = sprintf( 'Peach Payments: admin renewal fallback could not create a renewal order for subscription #%d because wcs_create_renewal_order() is unavailable.', $subscription_id );
			self::add_unique_order_note( $subscription, $message );
			PP_Gateway_Logger::error( $message );
			unset( self::$admin_action_pre_state[ $subscription_id ] );
			return;
		}

		try {
			$renewal_order = wcs_create_renewal_order( $subscription );
		} catch ( Throwable $e ) {
			$message = sprintf( 'Peach Payments: admin renewal fallback failed to create a renewal order for subscription #%1$d. Error: %2$s', $subscription_id, $e->getMessage() );
			self::add_unique_order_note( $subscription, $message );
			PP_Gateway_Logger::error( $message . ' in ' . $e->getFile() . ':' . $e->getLine() );
			unset( self::$admin_action_pre_state[ $subscription_id ] );
			return;
		}

		if ( is_wp_error( $renewal_order ) ) {
			$message = sprintf( 'Peach Payments: admin renewal fallback failed to create a renewal order for subscription #%1$d. Error: %2$s', $subscription_id, $renewal_order->get_error_message() );
			self::add_unique_order_note( $subscription, $message );
			PP_Gateway_Logger::error( $message );
			unset( self::$admin_action_pre_state[ $subscription_id ] );
			return;
		}

		if ( ! is_a( $renewal_order, 'WC_Order' ) ) {
			$message = sprintf( 'Peach Payments: admin renewal fallback did not receive a valid renewal order object for subscription #%d.', $subscription_id );
			self::add_unique_order_note( $subscription, $message );
			PP_Gateway_Logger::error( $message );
			unset( self::$admin_action_pre_state[ $subscription_id ] );
			return;
		}

		self::ensure_renewal_order_has_peach_data( $renewal_order, $subscription );

		$message = sprintf( 'Peach Payments: admin renewal fallback created renewal order #%d after WooCommerce Subscriptions did not create one during the admin action.', $renewal_order->get_id() );
		self::add_unique_order_note( $subscription, $message );
		self::add_unique_order_note( $renewal_order, $message );
		PP_Gateway_Logger::warning( $message );

		if ( $process_payment ) {
			self::maybe_process_admin_renewal_order( $subscription, $renewal_order, 'plugin_admin_fallback_created_order' );
		} elseif ( in_array( $renewal_order->get_status(), [ 'checkout-draft', 'auto-draft' ], true ) ) {
			$renewal_order->update_status( 'pending', __( 'Pending renewal order created by Peach Payments admin fallback.', WC_PEACH_TEXT_DOMAIN ) );
		}

		unset( self::$admin_action_pre_state[ $subscription_id ] );
	}

	/**
	 * Ensure a renewal order has the Peach gateway and recurring metadata copied from its subscription.
	 *
	 * @param WC_Order $renewal_order Renewal order object.
	 * @param WC_Order $subscription  Subscription object.
	 * @return void
	 */
	protected static function ensure_renewal_order_has_peach_data( $renewal_order, $subscription ) {
		if ( ! is_a( $renewal_order, 'WC_Order' ) || ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		if ( 'peach-payments' !== $renewal_order->get_payment_method() ) {
			$renewal_order->set_payment_method( 'peach-payments' );
			$renewal_order->set_payment_method_title( 'Peach Payments' );
		}

		self::maybe_backfill_subscription_payment_meta_from_parent( $subscription );
		$meta_to_sync = self::get_payment_meta_from_order( $subscription );
		self::sync_payment_meta_to_order( $renewal_order, $meta_to_sync );
	}

	/**
	 * Process an admin-created renewal order when WooCommerce Subscriptions did not do so itself.
	 *
	 * @param WC_Order $subscription  Subscription object.
	 * @param WC_Order $renewal_order Renewal order object.
	 * @param string   $source        Fallback source/context.
	 * @return void
	 */
	protected static function maybe_process_admin_renewal_order( $subscription, $renewal_order, $source ) {
		if ( ! is_a( $renewal_order, 'WC_Order' ) || ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		$renewal_order_id = $renewal_order->get_id();

		if ( isset( self::$admin_fallback_processed_orders[ $renewal_order_id ] ) ) {
			return;
		}

		if ( self::renewal_payment_already_processed( $renewal_order ) ) {
			return;
		}

		self::$admin_fallback_processed_orders[ $renewal_order_id ] = true;

		self::process_renewal_payment( (float) $renewal_order->get_total(), $renewal_order );
	}

	/**
	 * Record a successful Peach renewal through WooCommerce Subscriptions' official renewal lifecycle.
	 *
	 * Peach charges saved cards from the WooCommerce Subscriptions scheduled-payment hook.
	 * After a successful gateway charge, Subscriptions must be told that the renewal
	 * order has been paid so it can advance dates, add the correct subscription notes,
	 * fire renewal-complete hooks, and schedule the next renewal action.
	 *
	 * @param WC_Order $order          Renewal order object.
	 * @param string   $transaction_id Peach transaction ID.
	 * @return void
	 */
	public static function record_renewal_payment_success_with_subscriptions( $order, $transaction_id = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( ! function_exists( 'wcs_order_contains_renewal' ) || ! wcs_order_contains_renewal( $order ) ) {
			return;
		}

		if ( $order->get_meta( '_peach_wcs_renewal_success_recorded', true ) ) {
			return;
		}

		if ( ! class_exists( 'WC_Subscriptions_Manager' ) || ! method_exists( 'WC_Subscriptions_Manager', 'process_subscription_payments_on_order' ) ) {
			PP_Gateway_Logger::warning( sprintf( 'Peach Payments could not hand renewal order #%d to WooCommerce Subscriptions because WC_Subscriptions_Manager::process_subscription_payments_on_order() is unavailable.', $order->get_id() ) );
			self::add_unique_order_note( $order, 'Peach Payments: renewal was paid, but WooCommerce Subscriptions payment-recording API was unavailable. Please verify the subscription next payment date and scheduled action.' );
			return;
		}

		try {
			WC_Subscriptions_Manager::process_subscription_payments_on_order( $order );

			$order->update_meta_data( '_peach_wcs_renewal_success_recorded', time() );
			if ( '' !== (string) $transaction_id ) {
				$order->update_meta_data( '_peach_wcs_renewal_success_recorded_txn', sanitize_text_field( (string) $transaction_id ) );
			}
			$order->save();

			self::add_unique_order_note( $order, 'Peach Payments: successful renewal payment recorded with WooCommerce Subscriptions so the next renewal can be scheduled.' );
		} catch ( TypeError $e ) {
			try {
				WC_Subscriptions_Manager::process_subscription_payments_on_order( $order->get_id() );

				$order->update_meta_data( '_peach_wcs_renewal_success_recorded', time() );
				if ( '' !== (string) $transaction_id ) {
					$order->update_meta_data( '_peach_wcs_renewal_success_recorded_txn', sanitize_text_field( (string) $transaction_id ) );
				}
				$order->save();

				self::add_unique_order_note( $order, 'Peach Payments: successful renewal payment recorded with WooCommerce Subscriptions so the next renewal can be scheduled.' );
			} catch ( Throwable $fallback_error ) {
				$message = sprintf( 'Peach Payments: renewal payment was successful, but WooCommerce Subscriptions did not record the renewal lifecycle. Error: %s', $fallback_error->getMessage() );
				self::add_unique_order_note( $order, $message );
				PP_Gateway_Logger::error( sprintf( 'Failed to record Peach renewal order #%1$d with WooCommerce Subscriptions. Error: %2$s in %3$s:%4$d', $order->get_id(), $fallback_error->getMessage(), $fallback_error->getFile(), $fallback_error->getLine() ) );
			}
		} catch ( Throwable $e ) {
			$message = sprintf( 'Peach Payments: renewal payment was successful, but WooCommerce Subscriptions did not record the renewal lifecycle. Error: %s', $e->getMessage() );
			self::add_unique_order_note( $order, $message );
			PP_Gateway_Logger::error( sprintf( 'Failed to record Peach renewal order #%1$d with WooCommerce Subscriptions. Error: %2$s in %3$s:%4$d', $order->get_id(), $e->getMessage(), $e->getFile(), $e->getLine() ) );
		}
	}

	/**
	 * Log when WooCommerce Subscriptions fires the scheduled retry action.
	 *
	 * @param int $renewal_order_id Renewal order ID.
	 */
	public static function log_scheduled_payment_retry( $renewal_order_id ) {
		$renewal_order_id = absint( $renewal_order_id );
		if ( ! $renewal_order_id ) {
			return;
		}

		$order = wc_get_order( $renewal_order_id );
		if ( ! is_a( $order, 'WC_Order' ) || 'peach-payments' !== $order->get_payment_method() ) {
			return;
		}

		self::add_unique_order_note( $order, 'Peach Payments: WooCommerce Subscriptions scheduled retry triggered for this renewal order.' );
	}

	/**
	 * Check whether a renewal order has already been paid/processed by Peach.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return bool
	 */
	protected static function renewal_payment_already_processed( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		if ( $order->is_paid() ) {
			return true;
		}

		if ( $order->get_meta( '_peach_renewal_payment_processed', true ) ) {
			return true;
		}

		$stored_transaction_id    = trim( (string) $order->get_transaction_id() );
		$stored_payment_order_id  = trim( (string) $order->get_meta( 'payment_order_id', true ) );
		$has_peach_transaction_id = ( '' !== $stored_transaction_id || '' !== $stored_payment_order_id );

		if ( $has_peach_transaction_id && class_exists( 'PP_Gateway_Order_Utils' ) && ! PP_Gateway_Order_Utils::order_status_checks( $order ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Acquire a short-lived lock before sending a renewal charge to Peach for this order.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return bool
	 */
	protected static function acquire_renewal_payment_lock( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		$order_id  = $order->get_id();
		$lock_key  = '_peach_renewal_payment_lock';
		$lock_time = get_post_meta( $order_id, $lock_key, true );

		if ( ! empty( $lock_time ) ) {
			$lock_age = time() - absint( $lock_time );
			if ( $lock_age < 300 ) {
				return false;
			}

			delete_post_meta( $order_id, $lock_key );
		}

		return (bool) add_post_meta( $order_id, $lock_key, time(), true );
	}

	/**
	 * Release the short-lived renewal order charge lock.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return void
	 */
	protected static function release_renewal_payment_lock( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		delete_post_meta( $order->get_id(), '_peach_renewal_payment_lock' );
	}

	/**
	 * Acquire short-lived subscription-level locks to prevent concurrent duplicate renewal charges.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return bool
	 */
	protected static function acquire_subscription_renewal_charge_locks( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		$subscriptions = self::get_subscriptions_for_order_context( $order );
		if ( empty( $subscriptions ) ) {
			return true;
		}

		$acquired_keys = [];

		foreach ( $subscriptions as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
				continue;
			}

			$subscription_id = absint( $subscription->get_id() );
			if ( ! $subscription_id ) {
				continue;
			}

			$option_key = 'pp_peach_renewal_charge_lock_' . $subscription_id;
			$lock_value = get_option( $option_key, '' );

			if ( '' !== $lock_value ) {
				$parts     = explode( '|', (string) $lock_value );
				$lock_time = isset( $parts[0] ) ? absint( $parts[0] ) : 0;

				if ( $lock_time && ( time() - $lock_time ) < 300 ) {
					self::release_subscription_renewal_charge_locks_by_keys( $acquired_keys );
					return false;
				}

				delete_option( $option_key );
			}

			if ( ! add_option( $option_key, time() . '|' . $order->get_id(), '', 'no' ) ) {
				self::release_subscription_renewal_charge_locks_by_keys( $acquired_keys );
				return false;
			}

			$acquired_keys[] = $option_key;
		}

		if ( ! empty( $acquired_keys ) ) {
			$order->update_meta_data( '_peach_renewal_charge_lock_keys', $acquired_keys );
			$order->save();
		}

		return true;
	}

	/**
	 * Release subscription-level renewal charge locks stored on an order.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return void
	 */
	protected static function release_subscription_renewal_charge_locks( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$lock_keys = $order->get_meta( '_peach_renewal_charge_lock_keys', true );
		if ( ! is_array( $lock_keys ) ) {
			$lock_keys = [];
		}

		self::release_subscription_renewal_charge_locks_by_keys( $lock_keys );
		$order->delete_meta_data( '_peach_renewal_charge_lock_keys' );
		$order->save();
	}

	/**
	 * Release subscription-level renewal charge locks by option key.
	 *
	 * @param array $lock_keys Option keys.
	 * @return void
	 */
	protected static function release_subscription_renewal_charge_locks_by_keys( array $lock_keys ) {
		foreach ( $lock_keys as $lock_key ) {
			$lock_key = sanitize_key( (string) $lock_key );
			if ( '' !== $lock_key ) {
				delete_option( $lock_key );
			}
		}
	}

	/**
	 * Mark a successful Peach renewal charge before order-status handling runs.
	 *
	 * @param WC_Order $order          Renewal order object.
	 * @param string   $transaction_id Peach transaction ID.
	 * @param string   $source         Processing source.
	 * @return void
	 */
	protected static function mark_renewal_payment_processed( $order, $transaction_id = '', $source = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order->update_meta_data( '_peach_renewal_payment_processed', time() );

		$transaction_id = trim( (string) $transaction_id );
		if ( '' !== $transaction_id ) {
			$order->update_meta_data( '_peach_renewal_payment_processed_txn', $transaction_id );
		}

		$source = trim( (string) $source );
		if ( '' !== $source ) {
			$order->update_meta_data( '_peach_renewal_payment_processed_source', $source );
		}

		$order->save();
	}

	/**
	 * Find a recently paid/processed Peach renewal order for the same subscription.
	 *
	 * This prevents multiple duplicate renewal orders created in the same short period from
	 * each sending a separate saved-card charge to Peach.
	 *
	 * @param WC_Order $order            Current renewal order.
	 * @param float    $amount_to_charge Amount being charged.
	 * @return int Matching renewal order ID, or 0 when no duplicate is found.
	 */
	protected static function find_recent_successful_renewal_for_subscription( $order, $amount_to_charge ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! function_exists( 'wcs_order_contains_renewal' ) || ! wcs_order_contains_renewal( $order ) ) {
			return 0;
		}

		$subscriptions = self::get_subscriptions_for_order_context( $order );
		if ( empty( $subscriptions ) ) {
			return 0;
		}

		$current_order_id = $order->get_id();
		$current_currency = $order->get_currency();
		$current_total    = (float) $amount_to_charge;
		$current_created  = $order->get_date_created();
		$current_time     = $current_created ? $current_created->getTimestamp() : time();
		$window_seconds   = 6 * HOUR_IN_SECONDS;

		foreach ( $subscriptions as $subscription ) {
			if ( ! self::is_peach_subscription( $subscription ) ) {
				continue;
			}

			foreach ( self::get_related_renewal_order_ids( $subscription ) as $related_order_id ) {
				if ( $related_order_id === $current_order_id ) {
					continue;
				}

				$related_order = wc_get_order( $related_order_id );
				if ( ! is_a( $related_order, 'WC_Order' ) ) {
					continue;
				}

				if ( 'peach-payments' !== $related_order->get_payment_method() ) {
					continue;
				}

				if ( ! self::renewal_payment_already_processed( $related_order ) ) {
					continue;
				}

				if ( $current_currency !== $related_order->get_currency() ) {
					continue;
				}

				if ( abs( (float) $related_order->get_total() - $current_total ) > 0.01 ) {
					continue;
				}

				$related_created = $related_order->get_date_created();
				$related_time    = $related_created ? $related_created->getTimestamp() : 0;

				if ( ! $related_time || abs( $current_time - $related_time ) > $window_seconds ) {
					continue;
				}

				return $related_order_id;
			}
		}

		return 0;
	}

	/**
	 * Place an uncharged duplicate renewal order on hold and record why it was not charged.
	 *
	 * @param WC_Order $order              Duplicate renewal order.
	 * @param int      $duplicate_order_id Existing successful renewal order ID.
	 * @return void
	 */
	protected static function hold_duplicate_renewal_order( $order, $duplicate_order_id ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$message = sprintf(
			'Peach Payments duplicate protection: renewal charge skipped because renewal order #%d was already successfully processed for this subscription in the duplicate-protection window.',
			absint( $duplicate_order_id )
		);

		$order->update_meta_data( '_peach_renewal_payment_skipped_duplicate_of', absint( $duplicate_order_id ) );
		self::add_unique_order_note( $order, $message );

		if ( ! $order->is_paid() && in_array( $order->get_status(), [ 'pending', 'failed' ], true ) ) {
			$order->update_status( 'on-hold', $message );
		} else {
			$order->save();
		}

		PP_Gateway_Logger::warning( sprintf( 'Peach Payments renewal charge skipped for order #%1$d because renewal order #%2$d already appears processed for the same subscription/amount within the duplicate-protection window.', $order->get_id(), absint( $duplicate_order_id ) ) );
	}

	/**
	 * Add a private order note only if the same note does not already exist on the order/subscription.
	 *
	 * @param WC_Order $order Order or subscription object.
	 * @param string   $note  Note content.
	 * @return bool
	 */
	public static function add_unique_order_note( $order, $note ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		$note = trim( (string) $note );
		if ( '' === $note ) {
			return false;
		}

		if ( function_exists( 'wc_get_order_notes' ) ) {
			$existing_notes = wc_get_order_notes( [
				'order_id' => $order->get_id(),
				'type'     => 'internal',
				'limit'    => 30,
			] );

			if ( is_array( $existing_notes ) ) {
				foreach ( $existing_notes as $existing_note ) {
					$content = '';
					if ( is_object( $existing_note ) ) {
						$content = isset( $existing_note->content ) ? (string) $existing_note->content : '';
					} elseif ( is_array( $existing_note ) ) {
						$content = isset( $existing_note['content'] ) ? (string) $existing_note['content'] : '';
					}

					if ( trim( wp_strip_all_tags( $content ) ) === $note ) {
						return false;
					}
				}
			}
		}

		$order->add_order_note( $note, 0, false );
		return true;
	}

	/**
	 * Remove redundant payment-method-change notes where the old and new payment methods are both Peach Payments.
	 *
	 * @param WC_Order $order Order or subscription object.
	 * @return void
	 */
	protected static function cleanup_redundant_payment_method_change_notes( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! function_exists( 'wc_get_order_notes' ) ) {
			return;
		}

		$notes = wc_get_order_notes( [
			'order_id' => $order->get_id(),
			'type'     => 'internal',
			'limit'    => 30,
		] );

		if ( ! is_array( $notes ) ) {
			return;
		}

		foreach ( $notes as $note ) {
			$note_id = 0;
			$content = '';
			if ( is_object( $note ) ) {
				$note_id = isset( $note->id ) ? (int) $note->id : 0;
				$content = isset( $note->content ) ? (string) $note->content : '';
			} elseif ( is_array( $note ) ) {
				$note_id = isset( $note['id'] ) ? (int) $note['id'] : 0;
				$content = isset( $note['content'] ) ? (string) $note['content'] : '';
			}

			if ( $note_id && preg_match( '/Payment method changed from ["\']?Peach Payments["\']? to ["\']?Peach Payments["\']?/i', wp_strip_all_tags( $content ) ) ) {
				wp_delete_comment( $note_id, true );
			}
		}
	}

	/**
	 * Expose recurring payment meta for admin payment method changes.
	 *
	 * @param array           $payment_meta Existing payment meta.
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array
	 */
	public static function add_subscription_payment_meta( $payment_meta, $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return $payment_meta;
		}

		$payment_meta['peach-payments'] = [
			'post_meta' => [
				'payment_registration_id' => [
					'value' => self::get_subscription_meta_with_fallback( $subscription, 'payment_registration_id' ),
					'label' => 'Peach Registration ID',
				],
				'payment_initial_id' => [
					'value' => self::get_subscription_meta_with_fallback( $subscription, 'payment_initial_id' ),
					'label' => 'Peach Initial Transaction ID',
				],
				'payment_order_id' => [
					'value' => self::get_subscription_meta_with_fallback( $subscription, 'payment_order_id' ),
					'label' => 'Peach Payment Order ID',
				],
			],
		];

		return $payment_meta;
	}

	/**
	 * Validate recurring payment meta entered by admins.
	 *
	 * @param string $payment_method_id Payment method ID.
	 * @param array  $payment_meta      Payment meta array.
	 * @throws Exception When invalid payment meta is supplied.
	 */
	public static function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
		if ( 'peach-payments' !== $payment_method_id ) {
			return;
		}

		$post_meta = $payment_meta['peach-payments']['post_meta'] ?? [];
		$registration_id = isset( $post_meta['payment_registration_id']['value'] ) ? trim( (string) $post_meta['payment_registration_id']['value'] ) : '';

		if ( '' === $registration_id ) {
			$subscription = self::get_current_subscription_from_request();

			if ( $subscription ) {
				self::maybe_backfill_subscription_payment_meta_from_parent( $subscription );
				$registration_id = self::get_subscription_meta_with_fallback( $subscription, 'payment_registration_id' );

				if ( '' === $registration_id ) {
					$registration_id = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );
				}
			}
		}

		if ( '' === $registration_id ) {
			if ( self::is_admin_subscription_renewal_action_request() ) {
				return;
			}

			throw new Exception( __( 'A Peach Registration ID is required for automatic renewal payments.', WC_PEACH_TEXT_DOMAIN ) );
		}
	}

	/**
	 * Update recurring payment meta after a failed renewal is recovered with a new payment method.
	 *
	 * @param WC_Order|int $original_order Original order object or ID.
	 * @param WC_Order|int $renewal_order  Renewal order object or ID.
	 */
	public static function handle_changed_failing_payment_method( $original_order, $renewal_order ) {
		$original_order = is_numeric( $original_order ) ? wc_get_order( $original_order ) : $original_order;
		$renewal_order  = is_numeric( $renewal_order ) ? wc_get_order( $renewal_order ) : $renewal_order;

		if ( ! is_a( $original_order, 'WC_Order' ) || ! is_a( $renewal_order, 'WC_Order' ) ) {
			PP_Gateway_Logger::error( 'Failed-payment payment-method update skipped because one or both orders were invalid.' );
			return;
		}

		$recovery_processed = (int) $renewal_order->get_meta( '_peach_failed_payment_method_recovery_processed', true );
		if ( $recovery_processed === (int) $renewal_order->get_id() ) {
			return;
		}

		$last_synced_renewal_order_id = (int) $original_order->get_meta( '_peach_last_failed_payment_method_sync_order_id', true );
		if ( $last_synced_renewal_order_id && $last_synced_renewal_order_id === (int) $renewal_order->get_id() ) {
			$renewal_order->update_meta_data( '_peach_failed_payment_method_recovery_processed', $renewal_order->get_id() );
			$renewal_order->save();
			return;
		}

		$meta_to_sync = self::get_payment_meta_from_order( $renewal_order );
		if ( '' === $meta_to_sync['payment_registration_id'] ) {
			PP_Gateway_Logger::warning( sprintf( 'Failed-payment payment-method update skipped because renewal order #%d did not contain a Peach registration ID.', $renewal_order->get_id() ) );
			return;
		}

		self::sync_payment_meta_to_order( $original_order, $meta_to_sync );
		$original_order->update_meta_data( '_peach_last_failed_payment_method_sync_order_id', $renewal_order->get_id() );
		$original_order->save();

		$subscriptions = self::get_subscriptions_for_parent_order( $original_order );
		foreach ( $subscriptions as $subscription ) {
			self::sync_payment_meta_to_order( $subscription, $meta_to_sync );
		}

		$renewal_order->update_meta_data( '_peach_failed_payment_method_recovery_processed', $renewal_order->get_id() );
		$renewal_order->save();

		$note = sprintf(
			'Peach Payments: recurring payment data updated after failed renewal recovery. Registration ID now %s.',
			self::mask_meta_value( $meta_to_sync['payment_registration_id'] )
		);

		self::add_unique_order_note( $original_order, $note );
		foreach ( $subscriptions as $subscription ) {
			self::add_unique_order_note( $subscription, $note );
			self::cleanup_redundant_payment_method_change_notes( $subscription );
		}

	}

	/**
	 * Collect recurring payment data, prioritising subscription-level meta so admin changes are honoured.
	 *
	 * @param WC_Order $order        Renewal order.
	 * @param WC_Order $parent_order Parent order.
	 * @return array
	 */

	/**
	 * Sync recurring payment meta from an order onto any related subscriptions.
	 *
	 * @param WC_Order $order   Order object containing recurring meta.
	 * @param string   $context Optional sync context for notes/logs.
	 */
	public static function sync_payment_meta_from_order_to_subscriptions( $order, $context = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( 'peach-payments' !== $order->get_payment_method() ) {
			return;
		}

		$meta_to_sync = self::get_payment_meta_from_order( $order );
		if ( '' === $meta_to_sync['payment_registration_id'] ) {
			return;
		}

		$subscriptions = self::get_subscriptions_for_order_context( $order );
		if ( empty( $subscriptions ) ) {
			return;
		}

		$masked_registration_id = self::mask_meta_value( $meta_to_sync['payment_registration_id'] );
		$context_label          = $context ? $context : 'order_sync';

		foreach ( $subscriptions as $subscription ) {
			if ( ! is_a( $subscription, 'WC_Order' ) ) {
				continue;
			}

			$current_registration_id = trim( (string) $subscription->get_meta( 'payment_registration_id', true ) );
			$current_legacy_id       = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );

			if ( $current_registration_id === $meta_to_sync['payment_registration_id'] && $current_legacy_id === $meta_to_sync['_peach_subscription_payment_method'] ) {
				continue;
			}

			self::sync_payment_meta_to_order( $subscription, $meta_to_sync );
			self::add_unique_order_note(
				$subscription,
				sprintf(
					'Peach Payments: recurring payment data synced from related order via %1$s. Registration ID %2$s.',
					$context_label,
					$masked_registration_id
				)
			);
		}

	}

	/**
	 * Backfill recurring payment meta onto the current subscription from its parent order when available.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return bool
	 */
	protected static function maybe_backfill_subscription_payment_meta_from_parent( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_meta' ) ) {
			return false;
		}

		$current_registration_id = trim( (string) $subscription->get_meta( 'payment_registration_id', true ) );
		$current_legacy_id       = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );

		if ( '' !== $current_registration_id || '' !== $current_legacy_id ) {
			return false;
		}

		$parent_order_id = method_exists( $subscription, 'get_parent_id' ) ? (int) $subscription->get_parent_id() : 0;
		if ( ! $parent_order_id ) {
			return false;
		}

		$parent_order = wc_get_order( $parent_order_id );
		if ( ! is_a( $parent_order, 'WC_Order' ) ) {
			return false;
		}

		$meta_to_sync = self::get_payment_meta_from_order( $parent_order );
		if ( '' === $meta_to_sync['payment_registration_id'] ) {
			return false;
		}

		self::sync_payment_meta_to_order( $subscription, $meta_to_sync );

		$note = sprintf(
			'Peach Payments: recurring payment data backfilled onto this subscription from parent order #%1$d. Registration ID %2$s.',
			$parent_order->get_id(),
			self::mask_meta_value( $meta_to_sync['payment_registration_id'] )
		);

		self::add_unique_order_note( $subscription, $note );
		self::add_unique_order_note( $parent_order, $note );


		return true;
	}

	/**
	 * Get the subscription currently being edited from the request when available.
	 *
	 * @return WC_Subscription|null
	 */

	/**
	 * Determine if the current admin save request is a renewal-related action.
	 *
	 * @return bool
	 */
	protected static function is_admin_subscription_renewal_action_request() {
		$action = '';
		if ( isset( $_POST['wc_order_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['wc_order_action'] ) );
		}
		return in_array( $action, [ 'wcs_process_renewal', 'wcs_create_pending_renewal' ], true );
	}

	/**
	 * Check if a given object is an active Peach subscription.
	 *
	 * @param mixed $subscription Potential subscription object.
	 * @return bool
	 */
	protected static function is_peach_subscription( $subscription ) {
		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_payment_method' ) || ! method_exists( $subscription, 'get_id' ) ) {
			return false;
		}

		if ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $subscription->get_id() ) ) {
			return false;
		}

		if ( 'peach-payments' !== $subscription->get_payment_method() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get renewal order IDs linked to a subscription.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @return array
	 */
	protected static function get_related_renewal_order_ids( $subscription ) {
		if ( ! self::is_peach_subscription( $subscription ) || ! method_exists( $subscription, 'get_related_orders' ) ) {
			return [];
		}

		$order_ids = $subscription->get_related_orders( 'ids', 'renewal' );
		$order_ids = is_array( $order_ids ) ? array_map( 'absint', $order_ids ) : [];
		$order_ids = array_filter( $order_ids );
		sort( $order_ids );

		return array_values( $order_ids );
	}

	/**
	 * Normalize a subscription input to a subscription object.
	 *
	 * @param int|WC_Subscription|WC_Order $subscription Subscription object or ID.
	 * @return WC_Subscription|WC_Order|null
	 */
	protected static function normalize_subscription( $subscription ) {
		if ( is_numeric( $subscription ) ) {
			$subscription = wc_get_order( absint( $subscription ) );
		}

		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return null;
		}

		return $subscription;
	}

	protected static function get_current_subscription_from_request() {
		$subscription_id = 0;

		if ( isset( $_POST['post_ID'] ) ) {
			$subscription_id = absint( wp_unslash( $_POST['post_ID'] ) );
		} elseif ( isset( $_GET['post'] ) ) {
			$subscription_id = absint( wp_unslash( $_GET['post'] ) );
		}

		if ( ! $subscription_id ) {
			return null;
		}

		$subscription = wc_get_order( $subscription_id );

		if ( ! $subscription || ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $subscription_id ) ) ) {
			return null;
		}

		return $subscription;
	}

	protected static function get_recurring_payment_data( $order, $parent_order ) {
		$subscriptions = self::get_subscriptions_for_parent_order( $parent_order );

		foreach ( $subscriptions as $subscription ) {
			$registration_id = trim( (string) $subscription->get_meta( 'payment_registration_id', true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id' => $registration_id,
					'source'          => 'subscription:payment_registration_id',
				];
			}

			$registration_id = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id' => $registration_id,
					'source'          => 'subscription:_peach_subscription_payment_method',
				];
			}
		}

		$parent_meta_keys = [ '_peach_subscription_payment_method', 'payment_registration_id', '_payment_registration_id' ];
		foreach ( $parent_meta_keys as $meta_key ) {
			$registration_id = trim( (string) $parent_order->get_meta( $meta_key, true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id' => $registration_id,
					'source'          => 'parent_order:' . $meta_key,
				];
			}
		}

		$legacy_meta_keys = [ 'payment_registration_id', '_payment_registration_id' ];
		foreach ( $legacy_meta_keys as $meta_key ) {
			$registration_id = trim( (string) get_post_meta( $parent_order->get_id(), $meta_key, true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id' => $registration_id,
					'source'          => 'legacy_parent_order:' . $meta_key,
				];
			}
		}

		$registration_id = trim( (string) $order->get_meta( 'payment_registration_id', true ) );
		if ( '' !== $registration_id ) {
			return [
				'registration_id' => $registration_id,
				'source'          => 'renewal_order:payment_registration_id',
			];
		}

		return [
			'registration_id' => '',
			'source'          => 'not_found',
		];
	}

	/**
	 * Read a subscription meta value, falling back to the parent order when needed.
	 *
	 * @param WC_Subscription $subscription Subscription object.
	 * @param string          $meta_key     Meta key.
	 * @return string
	 */
	protected static function get_subscription_meta_with_fallback( $subscription, $meta_key ) {
		$value = trim( (string) $subscription->get_meta( $meta_key, true ) );
		if ( '' !== $value ) {
			return $value;
		}

		$parent_order_id = method_exists( $subscription, 'get_parent_id' ) ? (int) $subscription->get_parent_id() : 0;
		if ( ! $parent_order_id ) {
			return '';
		}

		$parent_order = wc_get_order( $parent_order_id );
		if ( ! is_a( $parent_order, 'WC_Order' ) ) {
			return '';
		}

		return trim( (string) $parent_order->get_meta( $meta_key, true ) );
	}

	/**
	 * Get related subscriptions for a parent order.
	 *
	 * @param WC_Order $parent_order Parent order.
	 * @return array
	 */
	protected static function get_subscriptions_for_parent_order( $parent_order ) {
		return self::get_subscriptions_for_order_context( $parent_order, 'parent' );
	}

	/**
	 * Get related subscriptions for an order context.
	 *
	 * @param WC_Order $order      Order object.
	 * @param string   $order_type Optional order type hint.
	 * @return array
	 */
	protected static function get_subscriptions_for_order_context( $order, $order_type = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return [];
		}

		if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order->get_id() ) ) {
			return [ $order ];
		}

		$subscriptions = [];

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$query_args = [];
			if ( '' !== $order_type ) {
				$query_args['order_type'] = $order_type;
			}

			$subscriptions = wcs_get_subscriptions_for_order( $order, $query_args );
		}

		if ( empty( $subscriptions ) && function_exists( 'wcs_get_subscriptions_for_renewal_order' ) && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
		}

		return is_array( $subscriptions ) ? $subscriptions : [];
	}

	/**
	 * Extract Peach recurring payment meta from an order.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	protected static function get_payment_meta_from_order( $order ) {
		$payment_registration_id = trim( (string) $order->get_meta( 'payment_registration_id', true ) );
		$legacy_registration_id  = trim( (string) $order->get_meta( '_peach_subscription_payment_method', true ) );

		if ( '' === $payment_registration_id && '' !== $legacy_registration_id ) {
			$payment_registration_id = $legacy_registration_id;
		}

		return [
			'payment_registration_id'          => $payment_registration_id,
			'_peach_subscription_payment_method' => '' !== $legacy_registration_id ? $legacy_registration_id : $payment_registration_id,
			'payment_initial_id'               => trim( (string) $order->get_meta( 'payment_initial_id', true ) ),
			'payment_order_id'                 => trim( (string) $order->get_meta( 'payment_order_id', true ) ),
		];
	}

	/**
	 * Sync recurring payment meta onto an order/subscription object.
	 *
	 * @param WC_Order $order Order-like object.
	 * @param array    $meta  Meta values.
	 */
	protected static function sync_payment_meta_to_order( $order, array $meta ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$meta_keys = [ 'payment_registration_id', '_peach_subscription_payment_method', 'payment_initial_id', 'payment_order_id' ];
		foreach ( $meta_keys as $meta_key ) {
			if ( isset( $meta[ $meta_key ] ) && '' !== $meta[ $meta_key ] ) {
				$order->update_meta_data( $meta_key, $meta[ $meta_key ] );
			}
		}

		$order->save();
	}

	/**
	 * Mask a recurring payment meta value for notes/logs.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function mask_meta_value( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return 'N/A';
		}

		if ( strlen( $value ) <= 5 ) {
			return $value;
		}

		return '...' . substr( $value, -5 );
	}
}
