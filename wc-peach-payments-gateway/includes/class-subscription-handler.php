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

	/** @var array<int,array<int,string>> Subscription lock keys held per renewal order in this request. */
	protected static $renewal_charge_lock_keys = [];

	/** @var bool Whether process_renewal_payment() is running from the deferred Hosted Checkout worker. */
	protected static $processing_deferred_hosted_retry = false;

	/** @var bool Explicit merchant override allowing a stale auth-blocked renewal to be retried once. */
	protected static $allow_stale_auth_manual_retry = false;

	/** @var array<int,string> Request-local outcome markers for renewal processing callbacks. */
	protected static $renewal_process_outcomes = [];

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'woocommerce_scheduled_subscription_payment_retry', [ __CLASS__, 'log_scheduled_payment_retry' ], 5, 1 );
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_filter( 'woocommerce_subscription_payment_meta', [ __CLASS__, 'add_subscription_payment_meta' ], 10, 2 );
		add_filter( 'wc_subscriptions_renewal_order_data', [ __CLASS__, 'exclude_order_specific_renewal_meta' ], 10, 3 );
		add_action( 'woocommerce_subscription_validate_payment_meta', [ __CLASS__, 'validate_subscription_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'maybe_force_admin_process_renewal' ], 999 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'maybe_force_admin_create_pending_renewal' ], 999 );
		add_action( 'woocommerce_scheduled_subscription_payment', [ __CLASS__, 'prepare_scheduled_renewal_request' ], 5, 1 );
		// Peach uses a hosted, asynchronous zero-value registration flow for customer payment-method changes.
		// Prevent Subscriptions from switching the recurring gateway before Peach has verified the new registration.
		add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode', [ __CLASS__, 'delay_customer_payment_method_change' ], 10, 3 );
		add_action( 'template_redirect', [ __CLASS__, 'handle_cancelled_customer_payment_method_change' ], 5 );
		add_action( 'peach_abort_abandoned_payment_method_change', [ __CLASS__, 'abort_abandoned_customer_payment_method_change' ], 10, 2 );
		add_action( 'peach_reconcile_unknown_renewal_payment', [ __CLASS__, 'reconcile_unknown_renewal_payment' ], 10, 2 );
		add_action( 'peach_deferred_hosted_renewal_retry', [ __CLASS__, 'process_deferred_hosted_renewal_retry' ], 10, 1 );
		add_action( 'peach_recover_auth_blocked_renewals', [ __CLASS__, 'recover_auth_blocked_renewals' ] );
		add_action( 'peach_recover_auth_blocked_renewal', [ __CLASS__, 'recover_auth_blocked_renewal' ], 10, 1 );
		add_action( 'woocommerce_update_options_payment_gateways_peach-payments', [ __CLASS__, 'schedule_auth_blocked_recovery_after_settings_save' ], 50 );
		add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'maybe_clear_resolved_auth_block' ], 20, 4 );
		add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_clear_unknown_payment_order_action' ], 10, 2 );
		add_action( 'woocommerce_order_action_peach_clear_unknown_payment_block', [ __CLASS__, 'clear_unknown_payment_block_order_action' ] );
		add_action( 'woocommerce_order_action_peach_release_auth_block', [ __CLASS__, 'release_auth_block_order_action' ] );
		add_action( 'woocommerce_order_action_peach_retry_renewal_payment', [ __CLASS__, 'retry_peach_renewal_payment_order_action' ] );
		add_filter( 'woocommerce_order_needs_payment', [ __CLASS__, 'block_customer_payment_while_unknown' ], 10, 3 );
		add_action( 'admin_notices', [ __CLASS__, 'render_admin_alerts' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_admin_alert_dismissal' ] );
	}

	/** Prevent transaction/session Peach metadata being copied from subscriptions to renewal orders. */
	public static function exclude_order_specific_renewal_meta( $data, $renewal_order = null, $subscription = null ) {
		$persistent = [
			'payment_registration_id',
			'_peach_subscription_payment_method',
			'payment_initial_id',
			'_peach_initial_id_reference_payment_id',
		];
		foreach ( $data as $index => $item ) {
			$key = is_array( $item ) && isset( $item['meta_key'] ) ? (string) $item['meta_key'] : ( is_string( $index ) ? $index : '' );
			if ( '' !== $key && ( 0 === strpos( $key, '_peach_' ) || 0 === strpos( $key, 'peach_' ) || 'payment_order_id' === $key ) && ! in_array( $key, $persistent, true ) ) {
				unset( $data[ $index ] );
			}
		}
		return $data;
	}

	/**
	 * Delay customer-initiated subscription payment-method changes to Peach until
	 * the hosted zero-value registration has been verified successfully.
	 *
	 * WooCommerce Subscriptions applies this filter before calling the gateway's
	 * process_payment() method. Returning false for Peach keeps the subscription's
	 * current gateway in place while the customer is away at hosted checkout.
	 *
	 * @param bool            $update_now         Whether Subscriptions should update immediately.
	 * @param string          $new_payment_method Selected gateway ID.
	 * @param WC_Subscription $subscription       Subscription being changed.
	 * @return bool
	 */

	public static function delay_customer_payment_method_change( $update_now, $new_payment_method, $subscription ) {
		if ( 'peach-payments' !== (string) $new_payment_method || ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return $update_now;
		}

		// This filter is also evaluated while Subscriptions renders capability/UI state.
		// Keep it side-effect free; the real attempt state is captured in process_payment().
		return false;
	}

	/**
	 * Whether a subscription has a live Peach customer payment-method-change attempt.
	 * Attempts expire after 24 hours and, once Peach has issued a checkout ID, are
	 * scoped to that checkout by the normal checkout-ID validation.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return bool
	 */
	public static function is_pending_customer_payment_method_change( $subscription ) {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return false;
		}

		$created = absint( $subscription->get_meta( '_peach_pending_payment_method_change', true ) );
		return $created > 0 && ( time() - $created ) <= DAY_IN_SECONDS;
	}

	/**
	 * Remove the optimistic notice WooCommerce Subscriptions queues immediately
	 * after process_payment() succeeds. Peach's delayed change is not complete until
	 * a verified final result is received.
	 *
	 * @return void
	 */
	public static function clear_optimistic_change_payment_notice() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->session || ! function_exists( 'wc_get_notices' ) || ! function_exists( 'wc_set_notices' ) ) {
			return;
		}

		$notices = wc_get_notices();
		$optimistic = [
			__( 'Payment method updated.', 'woocommerce-subscriptions' ),
			__( 'Payment method added.', 'woocommerce-subscriptions' ),
			__( 'Payment method updated for all your current subscriptions.', 'woocommerce-subscriptions' ),
		];

		$keep = static function ( $notice ) use ( $optimistic ) {
			$message = is_array( $notice ) && isset( $notice['notice'] ) ? $notice['notice'] : $notice;
			return ! in_array( wp_strip_all_tags( (string) $message ), $optimistic, true );
		};

		foreach ( [ 'success', 'notice' ] as $type ) {
			if ( empty( $notices[ $type ] ) || ! is_array( $notices[ $type ] ) ) {
				continue;
			}
			$notices[ $type ] = array_values( array_filter( $notices[ $type ], $keep ) );
		}

		wc_set_notices( $notices );
	}

	/**
	 * Restore an abandoned Peach payment-method change after its 24-hour window.
	 *
	 * The checkout ID makes the cleanup attempt-specific: a delayed action from an
	 * older attempt cannot abort a newer Peach session for the same subscription.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $checkout_id     Peach checkout ID for the abandoned attempt.
	 * @return void
	 */
	public static function abort_abandoned_customer_payment_method_change( $subscription_id, $checkout_id ) {
		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( absint( $subscription_id ) ) : wc_get_order( absint( $subscription_id ) );
		if ( ! $subscription || ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		$created = absint( $subscription->get_meta( '_peach_pending_payment_method_change', true ) );
		$current_checkout_id = (string) $subscription->get_meta( '_peach_checkout_id', true );
		if ( $created <= 0 || '' === (string) $checkout_id || ! hash_equals( $current_checkout_id, (string) $checkout_id ) ) {
			return;
		}

		// Never let an early/duplicate scheduler run abort an attempt before expiry.
		if ( ( time() - $created ) < DAY_IN_SECONDS ) {
			return;
		}

		self::abort_customer_payment_method_change( $subscription );
	}

	/**
	 * Restore the state captured before an unsuccessful/cancelled Peach change.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return void
	 */
	public static function abort_customer_payment_method_change( $subscription ) {
		if ( ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		self::clear_optimistic_change_payment_notice();

		$previous_gateway = (string) $subscription->get_meta( '_peach_previous_payment_method', true );
		$previous_manual  = (string) $subscription->get_meta( '_peach_previous_requires_manual_renewal', true );
		$checkout_id      = (string) $subscription->get_meta( '_peach_checkout_id', true );

		if ( '' !== $previous_gateway && $subscription->get_payment_method() !== $previous_gateway ) {
			$subscription->set_payment_method( $previous_gateway );
		}
		if ( in_array( $previous_manual, [ 'yes', 'no' ], true ) ) {
			$subscription->set_requires_manual_renewal( 'yes' === $previous_manual );
		}

		self::unschedule_payment_method_change_cleanup( $subscription->get_id(), $checkout_id );
		self::clear_customer_payment_method_change_state( $subscription );
		$subscription->save();
	}

	/**
	 * Remove a pending abandoned-change cleanup for a completed attempt.
	 *
	 * @param int    $subscription_id Subscription ID.
	 * @param string $checkout_id     Peach checkout ID.
	 * @return void
	 */
	private static function unschedule_payment_method_change_cleanup( $subscription_id, $checkout_id ) {
		if ( '' === (string) $checkout_id || ! function_exists( 'as_unschedule_action' ) ) {
			return;
		}

		as_unschedule_action(
			'peach_abort_abandoned_payment_method_change',
			[ absint( $subscription_id ), (string) $checkout_id ],
			'peach-payments'
		);
	}

	private static function clear_customer_payment_method_change_state( $subscription ) {
		$subscription->delete_meta_data( '_peach_pending_payment_method_change' );
		$subscription->delete_meta_data( '_peach_previous_payment_method' );
		$subscription->delete_meta_data( '_peach_previous_requires_manual_renewal' );
	}

	/**
	 * Clean up a hosted card-change session when Peach sends the customer to the
	 * explicit cancel URL. The URL is non-destructive and contains only the
	 * subscription ID plus a nonce scoped to this cancellation action.
	 *
	 * @return void
	 */
	public static function handle_cancelled_customer_payment_method_change() {
		if ( empty( $_GET['peach_change_cancelled'] ) || empty( $_GET['subscription_id'] ) || empty( $_GET['_wpnonce'] ) ) {
			return;
		}

		$subscription_id = absint( $_GET['subscription_id'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'peach_cancel_change_' . $subscription_id ) ) {
			return;
		}

		$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : wc_get_order( $subscription_id );
		if ( ! $subscription || ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $subscription ) ) {
			return;
		}

		if ( (int) $subscription->get_user_id() !== (int) get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		self::abort_customer_payment_method_change( $subscription );
		wc_add_notice( __( 'Card change cancelled. Your existing payment method is unchanged.', WC_PEACH_TEXT_DOMAIN ), 'notice' );

		wp_safe_redirect( PP_Gateway_Order_Utils::get_payment_method_change_return_url( $subscription ) );
		exit;
	}

	/**
	 * Commit a delayed customer payment-method change after Peach has returned a
	 * verified successful registration.
	 *
	 * @param WC_Order $subscription Subscription object.
	 * @return void
	 */
	public static function finalize_customer_payment_method_change( $subscription ) {
		if ( ! self::is_pending_customer_payment_method_change( $subscription ) ) {
			return;
		}

		if ( ! PP_Gateway_Order_Utils::acquire_initial_payment_lock( $subscription ) ) {
			return;
		}

		try {
			$fresh = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription->get_id() ) : wc_get_order( $subscription->get_id() );
			if ( ! $fresh || ! self::is_pending_customer_payment_method_change( $fresh ) ) {
				return;
			}

			$checkout_id = (string) $fresh->get_meta( '_peach_checkout_id', true );

			if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'update_payment_method' ) ) {
				WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $fresh, 'peach-payments' );

				if ( $fresh->get_meta( '_delayed_update_payment_method_all', true ) && method_exists( 'WC_Subscriptions_Change_Payment_Gateway', 'update_all_payment_methods_from_subscription' ) ) {
					WC_Subscriptions_Change_Payment_Gateway::update_all_payment_methods_from_subscription( $fresh, 'peach-payments' );
					$fresh->delete_meta_data( '_delayed_update_payment_method_all' );
				}
			}

			$fresh->set_requires_manual_renewal( false );
			self::unschedule_payment_method_change_cleanup( $fresh->get_id(), $checkout_id );
			self::clear_customer_payment_method_change_state( $fresh );
			$fresh->save();
		} finally {
			PP_Gateway_Order_Utils::release_initial_payment_lock( $subscription );
		}
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
		self::$renewal_process_outcomes[ $order_id ] = 'started';

		if ( self::renewal_payment_already_processed( $order ) ) {
			self::$renewal_process_outcomes[ $order_id ] = 'already_processed';
			self::remove_auth_blocked_renewal( $order );
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because this renewal order is already paid or already processed.' );
			return;
		}

		if ( ! self::$allow_stale_auth_manual_retry && self::renewal_auth_recovery_is_stale( $order ) ) {
			self::$renewal_process_outcomes[ $order_id ] = 'auth_stale_blocked';
			self::add_unique_order_note( $order, 'Peach Payments: automatic renewal was not attempted because this order has an expired authentication-recovery block. Review the existing renewal order and use the “Retry Peach renewal payment” order action only if a saved-card retry is still appropriate.' );
			return;
		}

		if ( wcs_order_contains_renewal( $order_id ) ) {
			$parent_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order_id );
		} else {
			$parent_order_id = $order_id;
		}

		$parent_order = wc_get_order( $parent_order_id );

		if ( ! is_a( $parent_order, 'WC_Order' ) ) {
			self::$renewal_process_outcomes[ $order_id ] = 'failure';
			self::remove_auth_blocked_renewal( $order );
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

		$payment_data       = self::get_recurring_payment_data( $order, $parent_order );
		$registration_id    = $payment_data['registration_id'];
		$payment_initial_id = isset( $payment_data['payment_initial_id'] ) ? trim( (string) $payment_data['payment_initial_id'] ) : '';


		if ( ! is_string( $registration_id ) || '' === $registration_id ) {
			self::$renewal_process_outcomes[ $order_id ] = 'failure';
			self::remove_auth_blocked_renewal( $order );
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
			self::$renewal_process_outcomes[ $order_id ] = 'lock_busy';
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because another request is already processing this renewal order.' );
			return;
		}

		if ( ! self::acquire_subscription_renewal_charge_locks( $order ) ) {
			self::$renewal_process_outcomes[ $order_id ] = 'lock_busy';
			self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because another Peach renewal charge is already processing for the related subscription.' );
			self::release_renewal_payment_lock( $order );
			return;
		}

		try {
			$fresh_order = PP_Gateway_Order_Utils::get_fresh_order( $order->get_id() );
			if ( $fresh_order ) { $order = $fresh_order; }
			if ( self::renewal_payment_already_processed( $order ) ) {
				self::$renewal_process_outcomes[ $order_id ] = 'already_processed';
				self::remove_auth_blocked_renewal( $order );
				if ( $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
					$ref = trim( (string) $order->get_meta( '_peach_renewal_payment_unknown_txn', true ) );
					if ( '' === $ref ) { $ref = trim( (string) $order->get_meta( '_peach_renewal_payment_unknown_reference', true ) ); }
					self::add_unique_order_note( $order, 'Peach Payments: automatic renewal blocked pending verification of Peach transaction ' . ( $ref ?: 'reference unavailable' ) . '.' );
				} else { self::add_unique_order_note( $order, 'Peach Payments: renewal charge skipped because this renewal order became paid or processed while waiting.' ); }
				return;
			}

			// Re-check the stale authentication safety state after the locks and fresh reload.
			// This closes the race where another request can age the block while this
			// renewal is waiting for its processing locks.
			if ( ! self::$allow_stale_auth_manual_retry && self::renewal_auth_recovery_is_stale( $order ) ) {
				self::$renewal_process_outcomes[ $order_id ] = 'auth_stale_blocked';
				self::add_unique_order_note( $order, 'Peach Payments: automatic renewal was not attempted because this order has an expired authentication-recovery block. Review the existing renewal order and use the “Retry Peach renewal payment” order action only if a saved-card retry is still appropriate.' );
				return;
			}

			// After the active processing locks have been acquired, only this exact
			// renewal order should be treated as idempotent. A separate renewal order
			// is a separate WooCommerce Subscriptions billing event and must be allowed
			// to charge even if it is close in time to the previous renewal.

			if ( '' === $payment_initial_id ) {
				PP_Gateway_Logger::warning( 'Peach renewal order #' . $order_id . ' resolved registration ID from ' . $payment_data['source'] . ' but no matching payment_initial_id from ' . ( $payment_data['initial_id_source'] ?? 'not_found' ) . '. Safe fallback resolution will be attempted.' );
			}

			$api      = new PP_Peach_API();
			$response = $api->charge_saved_card( $registration_id, $order, $amount_to_charge, $payment_initial_id );

			if ( is_wp_error( $response ) ) {
				$message = $response->get_error_message();
				$error_code = $response->get_error_code();

				if ( 'peach_hosted_checkout_in_progress' === $error_code ) {
					self::$renewal_process_outcomes[ $order_id ] = 'hosted_deferred';
					$started = absint( $order->get_meta( '_peach_hosted_checkout_started', true ) );
					$run_at  = max( time() + 60, $started + ( 30 * MINUTE_IN_SECONDS ) + 60 );
					self::schedule_deferred_hosted_renewal_retry( $order_id, $run_at, self::$processing_deferred_hosted_retry );
					self::add_unique_order_note( $order, 'Peach Payments: automatic renewal deferred because the customer is completing Hosted Checkout.' );
					return;
				}

				$error_data = $response->get_error_data();
				$peach_code = is_array( $error_data ) && isset( $error_data['result']['code'] ) ? trim( (string) $error_data['result']['code'] ) : '';
				$http_code  = is_array( $error_data ) && isset( $error_data['_http_status'] ) ? absint( $error_data['_http_status'] ) : 0;
				$curl_errno = is_array( $error_data ) && isset( $error_data['_curl_errno'] ) ? absint( $error_data['_curl_errno'] ) : 0;
				$definitely_not_sent = 'peach_api_curl_error' === $error_code && in_array( $curl_errno, [ CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT ], true );
				$auth_failure = 'peach_api_http_error' === $error_code && in_array( $http_code, [ 401, 403 ], true );
				$inconclusive = ( 'peach_api_curl_error' === $error_code && ! $definitely_not_sent )
					|| 'peach_api_invalid_response' === $error_code
					|| ( 'peach_api_http_error' === $error_code && ! $auth_failure && ( '' === $peach_code || PP_Gateway_Order_Utils::is_non_final_result_code( $peach_code ) ) );

				if ( $inconclusive ) {
					self::$renewal_process_outcomes[ $order_id ] = 'unknown';
					self::mark_renewal_payment_unknown( $order );
					self::add_unique_order_note( $order, 'Peach Payments: renewal payment outcome is unknown because Peach did not return a conclusive final result. Automatic retry is blocked pending verification.' );
					return;
				}
				if ( $auth_failure ) {
					self::$renewal_process_outcomes[ $order_id ] = 'auth_blocked';
					if ( $order->get_meta( '_peach_auth_blocked_stale', true ) ) {
						self::add_unique_order_note( $order, 'Peach Payments renewal was not attempted successfully because the gateway authentication/entity configuration was rejected. This renewal remains outside automatic recovery because its authentication block is older than the safety window; correct the Peach settings and explicitly retry this existing renewal only after review.' );
					} else {
						self::add_unique_order_note( $order, 'Peach Payments renewal was not attempted successfully because the gateway authentication/entity configuration was rejected. Customer dunning was not triggered; an administrator must correct the Peach Payments settings. This renewal will be re-evaluated after the configuration is updated or a later Peach renewal succeeds.' );
					}
					PP_Gateway_Logger::error( 'Peach renewal order #' . $order_id . ' received HTTP ' . $http_code . ' authentication/permission failure. Check Peach gateway credentials/entity configuration.' );
					self::record_auth_blocked_renewal( $order_id );
					return;
				}

				self::$renewal_process_outcomes[ $order_id ] = 'failure';
				self::remove_auth_blocked_renewal( $order );
				$order->add_order_note( 'Peach Payments renewal failed: ' . $message, 0, false );
				PP_Gateway_Order_Utils::handle_subscription_payment_failure( $order, $message, '', [
					'parent_order_id'      => $parent_order_id,
					'reason'               => 'api_wp_error',
					'registration_source'  => $payment_data['source'],
					'registration_id_tail' => self::mask_meta_value( $registration_id ),
					'initial_id_source'    => $payment_data['initial_id_source'] ?? 'not_found',
					'initial_id_tail'      => self::mask_meta_value( $payment_initial_id ),
				] );
				return;
			}

			$result_code = isset( $response['result']['code'] ) ? trim( (string) $response['result']['code'] ) : '';
			if ( PP_Gateway_Order_Utils::is_non_final_result_code( $result_code ) ) {
				self::$renewal_process_outcomes[ $order_id ] = 'unknown';
				self::mark_renewal_payment_unknown( $order, ! empty( $response['id'] ) ? $response['id'] : '' );
				self::add_unique_order_note( $order, 'Peach Payments: renewal payment is pending at Peach Payments. Automatic retry is blocked until the transaction reaches a final state.' );
				PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $response );
				return;
			}

			if ( PP_Gateway_Order_Utils::is_successful_result_code( $result_code ) ) {
				self::$renewal_process_outcomes[ $order_id ] = 'success';
				self::mark_renewal_payment_processed( $order, isset( $response['id'] ) ? $response['id'] : '', 'api_charge_saved_card' );
			} else {
				self::$renewal_process_outcomes[ $order_id ] = 'failure';
				self::remove_auth_blocked_renewal( $order );
			}
			PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $response );
		} catch ( Throwable $e ) {
			$message = $e->getMessage();
			$order->add_order_note( 'Peach Payments renewal failed because an unexpected error occurred: ' . $message, 0, false );
			PP_Gateway_Logger::error( sprintf( 'Unexpected error while processing Peach renewal order #%1$d: %2$s in %3$s:%4$d', $order_id, $message, $e->getFile(), $e->getLine() ) );
			if ( $order->get_meta( '_peach_renewal_payment_processed', true ) ) {
				self::$renewal_process_outcomes[ $order_id ] = 'success';
				self::add_unique_order_note( $order, 'Peach Payments: the renewal charge was confirmed successful, but WooCommerce status processing raised an error. The payment will not be retried automatically; review this order manually.' );
				return;
			}
			self::$renewal_process_outcomes[ $order_id ] = 'failure';
			self::remove_auth_blocked_renewal( $order );
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
	/** Schedule a deferred renewal re-check after a Hosted Checkout protection window. */
	protected static function schedule_deferred_hosted_renewal_retry( $order_id, $run_at, $from_running_callback = false ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) { return; }
		as_schedule_single_action(
			max( time() + 60, absint( $run_at ) ),
			'peach_deferred_hosted_renewal_retry',
			[ absint( $order_id ) ],
			'peach-payments',
			! $from_running_callback
		);
	}

	/** Return whether this renewal is still eligible for an automatic Peach renewal attempt. */
	protected static function deferred_renewal_is_eligible( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || 'peach-payments' !== $order->get_payment_method() || ! $order->needs_payment() ) { return false; }
		if ( $order->get_meta( '_peach_renewal_payment_unknown', true ) ) { return false; }
		$subscriptions = self::get_subscriptions_for_order_context( $order );
		if ( empty( $subscriptions ) ) { return false; }
		foreach ( $subscriptions as $subscription ) {
			if ( ! is_a( $subscription, 'WC_Subscription' ) ) { return false; }
			if ( $subscription->is_manual() || 'peach-payments' !== $subscription->get_payment_method() ) { return false; }
			if ( ! $subscription->has_status( [ 'active', 'on-hold' ] ) ) { return false; }
		}
		return true;
	}

	/** Re-evaluate a renewal after the Hosted Checkout protection window. */
	public static function process_deferred_hosted_renewal_retry( $order_id ) {
		$order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) );
		if ( ! self::deferred_renewal_is_eligible( $order ) ) { return; }
		$started = absint( $order->get_meta( '_peach_hosted_checkout_started', true ) );
		if ( $started && ( time() - $started ) < 30 * MINUTE_IN_SECONDS ) {
			$run_at = $started + ( 30 * MINUTE_IN_SECONDS ) + 60;
			// This callback is itself an in-progress Action Scheduler action, so a
			// self-reschedule must not use Action Scheduler's unique flag.
			self::schedule_deferred_hosted_renewal_retry( $order->get_id(), $run_at, true );
			return;
		}
		self::$processing_deferred_hosted_retry = true;
		try {
			self::process_renewal_payment( (float) $order->get_total(), $order );
			if ( 'lock_busy' === ( self::$renewal_process_outcomes[ $order->get_id() ] ?? '' ) ) {
				self::schedule_deferred_hosted_renewal_retry( $order->get_id(), time() + 120, true );
			}
		} finally {
			self::$processing_deferred_hosted_retry = false;
		}
	}

	protected static function remove_admin_alerts_for_order( $order_id, $contains = '' ) {
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( ! is_array( $alerts ) ) { return; }
		foreach ( $alerts as $key => $alert ) {
			if ( ! is_array( $alert ) || absint( $alert['order_id'] ?? 0 ) !== absint( $order_id ) ) { continue; }
			$message = (string) ( $alert['message'] ?? '' );
			if ( '' === $contains || false !== stripos( $message, $contains ) ) { unset( $alerts[ $key ] ); }
		}
		update_option( 'peach_payments_admin_alerts', $alerts, false );
	}

	/** Remove a structured Peach admin alert for one exact order/type pair. */
	protected static function remove_admin_alerts_for_order_type( $order_id, $type ) {
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( ! is_array( $alerts ) ) { return; }
		$order_id = absint( $order_id );
		$type     = sanitize_key( (string) $type );
		$changed  = false;
		foreach ( $alerts as $key => $alert ) {
			if ( ! is_array( $alert ) ) { continue; }
			if ( absint( $alert['order_id'] ?? 0 ) === $order_id && sanitize_key( (string) ( $alert['type'] ?? '' ) ) === $type ) {
				unset( $alerts[ $key ] );
				$changed = true;
			}
		}
		if ( $changed ) { update_option( 'peach_payments_admin_alerts', $alerts, false ); }
	}

	public static function raise_admin_alert( $message, $type = 'general', $order_id = 0 ) {
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( ! is_array( $alerts ) ) { $alerts = []; }
		$message = sanitize_text_field( (string) $message );
		$type = sanitize_key( (string) $type );
		$order_id = absint( $order_id );
		if ( '' !== $message ) {
			$key = md5( $type . '|' . $order_id . '|' . $message );
			$alerts[ $key ] = [ 'type' => $type ?: 'general', 'order_id' => $order_id, 'message' => $message, 'time' => time() ];
		}
		$alerts = array_filter( $alerts, static function( $item ) { return is_array( $item ) && ! empty( $item['time'] ) && ( time() - absint( $item['time'] ) ) < 7 * DAY_IN_SECONDS; } );
		update_option( 'peach_payments_admin_alerts', $alerts, false );
	}

	protected static function remove_admin_alerts_by_type( $type ) {
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( ! is_array( $alerts ) ) { return; }
		foreach ( $alerts as $key => $alert ) {
			if ( is_array( $alert ) && sanitize_key( (string) ( $alert['type'] ?? '' ) ) === sanitize_key( $type ) ) { unset( $alerts[ $key ] ); }
		}
		update_option( 'peach_payments_admin_alerts', $alerts, false );
	}

	/**
	 * Enforce the seven-day authentication-recovery safety boundary.
	 *
	 * This also closes the gap where an old active marker has not yet been visited by
	 * the recovery coordinator: normal WCS processing must not be able to charge it.
	 *
	 * @param WC_Order $order Renewal order.
	 * @return bool True when automatic saved-card charging must remain blocked.
	 */
	protected static function renewal_auth_recovery_is_stale( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return false; }

		if ( $order->get_meta( '_peach_auth_blocked_stale', true ) ) {
			return true;
		}

		$blocked_at = absint( $order->get_meta( '_peach_auth_blocked', true ) );
		if ( ! $blocked_at || ( time() - $blocked_at ) < 7 * DAY_IN_SECONDS ) {
			return false;
		}

		$order->delete_meta_data( '_peach_auth_blocked' );
		$order->update_meta_data( '_peach_auth_blocked_stale', $blocked_at );
		$order->save();
		self::refresh_auth_blocked_admin_alert();
		self::raise_admin_alert( 'Renewal order #' . $order->get_id() . ' was blocked by Peach authentication/configuration for 7 days or more and will not be charged automatically. Review this existing renewal order. If the saved Peach card should be retried, use “Retry Peach renewal payment” on this order. Use “Release Peach auth block” only to remove the protection without attempting a charge.', 'auth_stale', $order->get_id() );
		return true;
	}

	/** Return IDs of renewal orders currently blocked by Peach authentication/configuration errors. */
	protected static function get_auth_blocked_renewal_ids() {
		if ( ! function_exists( 'wc_get_orders' ) ) { return []; }
		$ids = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'type'       => 'shop_order',
			'meta_query' => [ [ 'key' => '_peach_auth_blocked', 'compare' => 'EXISTS' ] ],
		] );
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : [] ) ) ) );
	}

	/** Refresh the single aggregated authentication/configuration admin warning. */
	protected static function refresh_auth_blocked_admin_alert() {
		self::remove_admin_alerts_by_type( 'auth_failure' );
		$count = count( self::get_auth_blocked_renewal_ids() );
		if ( $count ) {
			self::raise_admin_alert( sprintf( '%d Peach Payments automatic renewal%s blocked by an authentication/entity configuration error. Check the Peach Payments credentials and recurring entity settings.', $count, 1 === $count ? ' is' : 's are' ), 'auth_failure', 0 );
		}
	}

	protected static function record_auth_blocked_renewal( $order_id ) {
		$order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) );
		if ( ! is_a( $order, 'WC_Order' ) || $order->get_meta( '_peach_auth_blocked', true ) ) { return; }

		// Once an authentication block has aged into manual-review state, another
		// authentication failure must not restart the seven-day automatic-recovery clock.
		// Only an explicit merchant action can authorize another saved-card attempt.
		if ( $order->get_meta( '_peach_auth_blocked_stale', true ) ) {
			return;
		}

		$order->update_meta_data( '_peach_auth_blocked', time() );
		$order->save();
		self::refresh_auth_blocked_admin_alert();
	}

	public static function schedule_auth_blocked_recovery_after_settings_save() {
		self::schedule_auth_blocked_recovery( 60 );
	}

	/** Clear active/stale auth recovery state when an order status change makes it no longer payable. */
	public static function maybe_clear_resolved_auth_block( $order_id, $from_status = '', $to_status = '', $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { $order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) ); }
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		if ( ! $order->get_meta( '_peach_auth_blocked', true ) && ! $order->get_meta( '_peach_auth_blocked_stale', true ) ) { return; }
		if ( ! $order->needs_payment() ) { self::remove_auth_blocked_renewal( $order ); }
	}

	/** Schedule a coordinator which fans recovery out to one Action Scheduler job per order. */
	protected static function schedule_auth_blocked_recovery( $delay = 60 ) {
		if ( empty( self::get_auth_blocked_renewal_ids() ) || ! function_exists( 'as_schedule_single_action' ) ) { return; }
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'peach_recover_auth_blocked_renewals', [], 'peach-payments' ) ) { return; }
		as_schedule_single_action( time() + max( 30, absint( $delay ) ), 'peach_recover_auth_blocked_renewals', [], 'peach-payments', true );
	}

	/** Fan blocked renewals out to isolated jobs so one slow/crashed charge cannot lose other orders. */
	public static function recover_auth_blocked_renewals() {
		$ids = self::get_auth_blocked_renewal_ids();
		if ( empty( $ids ) ) { self::refresh_auth_blocked_admin_alert(); return; }
		foreach ( $ids as $order_id ) {
			$args = [ absint( $order_id ) ];
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'peach_recover_auth_blocked_renewal', $args, 'peach-payments' ) ) { continue; }
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( 'peach_recover_auth_blocked_renewal', $args, 'peach-payments', true );
			} elseif ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, 'peach_recover_auth_blocked_renewal', $args, 'peach-payments', true );
			}
		}
		self::refresh_auth_blocked_admin_alert();
	}

	/** Recover one authentication-blocked renewal without clearing its durable marker prematurely. */
	public static function recover_auth_blocked_renewal( $order_id ) {
		$order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) );
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		$blocked_at = absint( $order->get_meta( '_peach_auth_blocked', true ) );
		if ( ! $blocked_at ) { return; }

		// Revalidate the current WCS state before applying the age safety rule. A paid,
		// cancelled, manual, or otherwise ineligible renewal no longer needs an auth block.
		if ( ! self::deferred_renewal_is_eligible( $order ) ) {
			self::remove_auth_blocked_renewal( $order );
			return;
		}

		// Old backlogs require deliberate merchant review and are removed from the
		// automatic recovery query so later successful renewals do not enqueue them again.
		if ( ( time() - $blocked_at ) >= 7 * DAY_IN_SECONDS ) {
			$order->delete_meta_data( '_peach_auth_blocked' );
			$order->update_meta_data( '_peach_auth_blocked_stale', $blocked_at );
			$order->save();
			self::refresh_auth_blocked_admin_alert();
			self::raise_admin_alert( 'Renewal order #' . $order->get_id() . ' was blocked by Peach authentication/configuration for 7 days or more and will not be charged automatically. Review this existing renewal order. If the saved Peach card should be retried, use “Retry Peach renewal payment” on this order. Use “Release Peach auth block” only to remove the protection without attempting a charge.', 'auth_stale', $order->get_id() );
			return;
		}

		// Keep _peach_auth_blocked in place while processing. A fatal/timeout therefore cannot lose this order.
		self::process_renewal_payment( (float) $order->get_total(), $order );
		$outcome = self::$renewal_process_outcomes[ $order->get_id() ] ?? '';
		if ( 'lock_busy' === $outcome && function_exists( 'as_schedule_single_action' ) ) {
			// This callback is currently running, so the replacement must be non-unique.
			as_schedule_single_action( time() + 120, 'peach_recover_auth_blocked_renewal', [ $order->get_id() ], 'peach-payments', false );
		}
		self::refresh_auth_blocked_admin_alert();
	}

	public static function render_admin_alerts() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( ! is_array( $alerts ) || empty( $alerts ) ) { return; }
		foreach ( $alerts as $key => $alert ) {
			if ( empty( $alert['message'] ) ) { continue; }
			$url = wp_nonce_url( add_query_arg( [ 'peach_dismiss_alert' => rawurlencode( (string) $key ) ] ), 'peach_dismiss_alert_' . $key );
			echo '<div class="notice notice-error"><p><strong>Peach Payments:</strong> ' . esc_html( $alert['message'] ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Dismiss', WC_PEACH_TEXT_DOMAIN ) . '</a></p></div>';
		}
	}

	public static function handle_admin_alert_dismissal() {
		if ( ! current_user_can( 'manage_woocommerce' ) || empty( $_GET['peach_dismiss_alert'] ) ) { return; }
		$key = sanitize_text_field( wp_unslash( $_GET['peach_dismiss_alert'] ) );
		if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'peach_dismiss_alert_' . $key ) ) { return; }
		$alerts = get_option( 'peach_payments_admin_alerts', [] );
		if ( is_array( $alerts ) && isset( $alerts[ $key ] ) ) { unset( $alerts[ $key ] ); update_option( 'peach_payments_admin_alerts', $alerts, false ); }
	}

	public static function block_customer_payment_while_unknown( $needs_payment, $order, $valid_statuses = [] ) {
		if ( is_a( $order, 'WC_Order' ) && function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
			return false;
		}
		return $needs_payment;
	}

	protected static function mark_renewal_payment_unknown( $order, $transaction_id = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		$had_auth_block = (bool) ( $order->get_meta( '_peach_auth_blocked', true ) || $order->get_meta( '_peach_auth_blocked_stale', true ) );
		if ( $had_auth_block ) {
			$order->delete_meta_data( '_peach_auth_blocked' );
			$order->delete_meta_data( '_peach_auth_blocked_stale' );
		}
		$reference = trim( (string) $order->get_meta( '_peach_renewal_attempt_reference', true ) );
		if ( '' === $reference ) { $reference = strval( PP_Gateway_Order_Utils::order_number_prep( strval( PP_Gateway_Order_Utils::find_converted_number( $order->get_id(), true ) ) ) ); }
		$order->update_meta_data( '_peach_renewal_payment_unknown', time() );
		$order->update_meta_data( '_peach_renewal_payment_unknown_reference', $reference );
		if ( '' !== trim( (string) $transaction_id ) ) { $order->update_meta_data( '_peach_renewal_payment_unknown_txn', sanitize_text_field( $transaction_id ) ); }
		$order->save();
		if ( $had_auth_block ) {
			self::remove_admin_alerts_for_order_type( $order->get_id(), 'auth_stale' );
			self::refresh_auth_blocked_admin_alert();
		}
		self::schedule_unknown_reconciliation( $order->get_id(), 0, 300, true );
	}

	// $attempt is a scheduling sequence used to keep Action Scheduler arguments distinct; the safety cap is elapsed-time based.
	protected static function schedule_unknown_reconciliation( $order_id, $attempt, $delay = 900, $unique = false ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + absint( $delay ), 'peach_reconcile_unknown_renewal_payment', [ absint( $order_id ), absint( $attempt ) ], 'peach-payments', (bool) $unique );
		}
	}

	public static function clear_unknown_payment_state( $order, $note = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		$had_auth_block = (bool) ( $order->get_meta( '_peach_auth_blocked', true ) || $order->get_meta( '_peach_auth_blocked_stale', true ) );
		$order->delete_meta_data( '_peach_renewal_payment_unknown' );
		$order->delete_meta_data( '_peach_renewal_payment_unknown_reference' );
		$order->delete_meta_data( '_peach_renewal_payment_unknown_txn' );
		$order->delete_meta_data( '_peach_auth_blocked' );
		$order->delete_meta_data( '_peach_auth_blocked_stale' );
		$order->save();
		self::remove_admin_alerts_for_order( $order->get_id(), 'unresolved Peach payment' );
		if ( $had_auth_block ) {
			self::remove_admin_alerts_for_order_type( $order->get_id(), 'auth_stale' );
			self::refresh_auth_blocked_admin_alert();
		}
		if ( $note ) { self::add_unique_order_note( $order, $note ); }
	}

	public static function reconcile_unknown_renewal_payment( $order_id, $attempt = 0 ) {
		$order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) );
		if ( ! $order || ! $order->get_meta( '_peach_renewal_payment_unknown', true ) ) { return; }
		if ( ! self::acquire_renewal_payment_lock( $order ) ) { self::schedule_unknown_reconciliation( $order_id, $attempt, 300 ); return; }
		try {
			$order = PP_Gateway_Order_Utils::get_fresh_order( absint( $order_id ) );
			if ( ! $order || ! $order->get_meta( '_peach_renewal_payment_unknown', true ) ) { return; }
			if ( ( time() - absint( $order->get_meta( '_peach_renewal_attempt_started', true ) ?: $order->get_meta( '_peach_renewal_payment_unknown', true ) ) ) >= DAY_IN_SECONDS ) {
				self::add_unique_order_note( $order, 'Peach Payments: automatic reconciliation stopped after 24 hours. The renewal remains blocked; verify the transaction in Peach Payments and use the clear-block order action only after verification.' );
				self::raise_admin_alert( 'Renewal order #' . $order->get_id() . ' has an unresolved Peach payment after 24 hours. Verify it in Peach Payments before retrying or clearing the block.', 'unknown_payment', $order->get_id() );
				return;
			}
			$reference = trim( (string) $order->get_meta( '_peach_renewal_payment_unknown_reference', true ) );
			$unknown_txn = trim( (string) $order->get_meta( '_peach_renewal_payment_unknown_txn', true ) );
			$result = PP_Peach_API::query_recurring_transaction_by_merchant_reference( $reference, $order, $unknown_txn, absint( $order->get_meta( '_peach_renewal_attempt_started', true ) ) );
			if ( is_wp_error( $result ) ) { self::schedule_unknown_reconciliation( $order_id, $attempt + 1 ); return; }
			$code = trim( (string) ( $result['result']['code'] ?? '' ) );
			if ( '' === $code || PP_Gateway_Order_Utils::is_non_final_result_code( $code ) ) { self::schedule_unknown_reconciliation( $order_id, $attempt + 1 ); return; }
			$result_txn = trim( (string) ( $result['id'] ?? '' ) );
			if ( PP_Gateway_Order_Utils::is_successful_result_code( $code ) && $order->is_paid() ) {
				$paid_txn = trim( (string) $order->get_transaction_id() );
				if ( '' !== $result_txn && '' !== $paid_txn && ! hash_equals( $result_txn, $paid_txn ) ) {
					self::add_unique_order_note( $order, 'Peach Payments WARNING: a second successful debit was found while reconciling this already-paid renewal. WooCommerce transaction ' . $paid_txn . '; additional Peach transaction ' . $result_txn . '. Verify both in Peach Payments and refund the duplicate if appropriate.' );
					PP_Gateway_Logger::error( 'Possible duplicate Peach payment on renewal order #' . $order->get_id() . ': paid transaction ' . $paid_txn . ', reconciled transaction ' . $result_txn . '.' );
					self::raise_admin_alert( 'Possible duplicate payment on renewal order #' . $order->get_id() . '. Verify Peach transactions ' . $paid_txn . ' and ' . $result_txn . ' and refund the duplicate if appropriate.', 'duplicate_payment', $order->get_id() );
					$order->update_meta_data( '_peach_possible_duplicate_transaction_id', $result_txn );
					$order->save();
					$admin_email = sanitize_email( get_option( 'admin_email' ) );
					if ( $admin_email ) { wp_mail( $admin_email, 'Peach Payments: possible duplicate renewal payment', 'Renewal order #' . $order->get_id() . ' may have two successful Peach transactions: ' . $paid_txn . ' and ' . $result_txn . '. Verify both transactions and refund the duplicate if appropriate.' ); }
					self::clear_unknown_payment_state( $order );
					return;
				}
			}
			self::clear_unknown_payment_state( $order );
			if ( PP_Gateway_Order_Utils::is_successful_result_code( $code ) ) { self::mark_renewal_payment_processed( $order, $result_txn, 'api_charge_saved_card' ); }
			PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $result );
		} finally { self::release_renewal_payment_lock( absint( $order_id ) ); }
	}

	public static function add_clear_unknown_payment_order_action( $actions, $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return $actions; }
		if ( $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
			$actions['peach_clear_unknown_payment_block'] = __( 'Clear Peach unknown-payment block', WC_PEACH_TEXT_DOMAIN );
		}

		$has_auth_block = (bool) ( $order->get_meta( '_peach_auth_blocked', true ) || $order->get_meta( '_peach_auth_blocked_stale', true ) );
		if ( $has_auth_block ) {
			$actions['peach_release_auth_block'] = __( 'Release Peach auth block', WC_PEACH_TEXT_DOMAIN );
		}

		// Offer a supported retry for the existing renewal order itself, not only
		// for authentication-recovery cases. This avoids directing merchants to
		// subscription-level Process Renewal, which can create a new renewal order.
		if ( function_exists( 'wcs_order_contains_renewal' )
			&& wcs_order_contains_renewal( $order )
			&& ! $order->get_meta( '_peach_renewal_payment_unknown', true )
			&& self::deferred_renewal_is_eligible( $order ) ) {
			$hosted_started = absint( $order->get_meta( '_peach_hosted_checkout_started', true ) );
			if ( ! $hosted_started || ( time() - $hosted_started ) >= 30 * MINUTE_IN_SECONDS ) {
				$actions['peach_retry_renewal_payment'] = __( 'Retry Peach renewal payment', WC_PEACH_TEXT_DOMAIN );
			}
		}
		return $actions;
	}

	public static function clear_unknown_payment_block_order_action( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		self::clear_unknown_payment_state( $order, 'Peach Payments: unknown-payment retry block was manually cleared. Verify the transaction in Peach Payments first: if it succeeded, mark/reconcile this order as paid; if it did not succeed, use the WooCommerce renewal retry workflow.' );
	}

	/** Release an authentication recovery block without attempting a charge. */
	public static function release_auth_block_order_action( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }
		$order = PP_Gateway_Order_Utils::get_fresh_order( $order->get_id() );
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }

		$user = wp_get_current_user();
		$actor = ( $user && $user->exists() )
			? sprintf( '%s (user ID %d)', $user->display_name ?: $user->user_login, absint( $user->ID ) )
			: 'administrator (user ID unavailable)';

		if ( self::remove_auth_blocked_renewal( $order ) ) {
			$order = PP_Gateway_Order_Utils::get_fresh_order( $order->get_id() );
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->add_order_note( sprintf(
					'Peach Payments: authentication recovery block manually released by %s. No payment was attempted. If this existing renewal still requires payment, use “Retry Peach renewal payment” on this order for a saved-card retry or allow the customer to pay this same renewal through Peach Hosted Checkout.',
					$actor
				) );
			}
		}
	}

	/** Explicitly retry the saved-card charge for this existing renewal order after merchant review. */
	public static function retry_peach_renewal_payment_order_action( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) || ! function_exists( 'wcs_order_contains_renewal' ) || ! wcs_order_contains_renewal( $order ) ) { return; }

		$order = PP_Gateway_Order_Utils::get_fresh_order( $order->get_id() );
		if ( ! is_a( $order, 'WC_Order' ) ) { return; }

		$user = wp_get_current_user();
		$actor = ( $user && $user->exists() )
			? sprintf( '%s (user ID %d)', $user->display_name ?: $user->user_login, absint( $user->ID ) )
			: 'administrator (user ID unavailable)';

		// This is an administrator-triggered financial action, so every click gets
		// its own attributed audit note rather than a de-duplicated status note.
		$order->add_order_note( sprintf(
			'Peach Payments: saved-card renewal retry manually requested by %s for this existing renewal order. If this charge is declined, WooCommerce Subscriptions may count the failure toward its failed-payment retry lifecycle and adjust the remaining retry schedule.',
			$actor
		) );

		if ( $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
			$order->add_order_note( 'Peach Payments: manual renewal retry was not started because a previous Peach payment outcome is still unresolved.' );
			return;
		}

		if ( ! self::deferred_renewal_is_eligible( $order ) ) {
			$order->add_order_note( 'Peach Payments: manual renewal retry was not started because this existing renewal is no longer eligible for an automatic saved-card charge. Review the order/subscription state or allow the customer to pay through Peach Hosted Checkout where appropriate.' );
			return;
		}

		$hosted_started = absint( $order->get_meta( '_peach_hosted_checkout_started', true ) );
		if ( $hosted_started && ( time() - $hosted_started ) < 30 * MINUTE_IN_SECONDS ) {
			$order->add_order_note( 'Peach Payments: manual renewal retry was not started because a customer Hosted Checkout session is still within its protection window.' );
			return;
		}

		self::$allow_stale_auth_manual_retry = true;
		try {
			self::process_renewal_payment( (float) $order->get_total(), $order );
		} finally {
			self::$allow_stale_auth_manual_retry = false;
		}
	}

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

		if ( $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
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
	public static function acquire_renewal_payment_lock( $order ) {
		$order_id = is_a( $order, 'WC_Order' ) ? $order->get_id() : absint( $order );
		return $order_id > 0 && PP_Gateway_Order_Utils::acquire_runtime_lock( '_peach_renewal_payment_lock_' . $order_id, 300 );
	}

	public static function release_renewal_payment_lock( $order ) {
		$order_id = is_a( $order, 'WC_Order' ) ? $order->get_id() : absint( $order );
		if ( $order_id > 0 ) { PP_Gateway_Order_Utils::release_runtime_lock( '_peach_renewal_payment_lock_' . $order_id ); }
	}

	/**
	 * Acquire short-lived subscription-level locks to prevent concurrent duplicate renewal charges.
	 *
	 * @param WC_Order $order Renewal order object.
	 * @return bool
	 */
	protected static function acquire_subscription_renewal_charge_locks( $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) { return false; }
		$acquired = [];
		foreach ( self::get_subscriptions_for_order_context( $order ) as $subscription ) {
			if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) { continue; }
			$key = 'pp_peach_renewal_charge_lock_' . absint( $subscription->get_id() );
			if ( ! PP_Gateway_Order_Utils::acquire_runtime_lock( $key, 300 ) ) {
				foreach ( $acquired as $held ) { PP_Gateway_Order_Utils::release_runtime_lock( $held ); }
				return false;
			}
			$acquired[] = $key;
		}
		self::$renewal_charge_lock_keys[ $order->get_id() ] = $acquired;
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

		$lock_keys = self::$renewal_charge_lock_keys[ $order->get_id() ] ?? [];
		self::release_subscription_renewal_charge_locks_by_keys( is_array( $lock_keys ) ? $lock_keys : [] );
		unset( self::$renewal_charge_lock_keys[ $order->get_id() ] );
	}

	/**
	 * Release subscription-level renewal charge locks by option key.
	 *
	 * @param array $lock_keys Option keys.
	 * @return void
	 */
	protected static function release_subscription_renewal_charge_locks_by_keys( array $lock_keys ) {
		foreach ( $lock_keys as $lock_key ) { PP_Gateway_Order_Utils::release_runtime_lock( (string) $lock_key ); }
	}


	/** Remove active/stale authentication recovery state. Returns true only when state changed. */
	protected static function remove_auth_blocked_renewal( $order_or_id ) {
		$order = is_a( $order_or_id, 'WC_Order' ) ? $order_or_id : PP_Gateway_Order_Utils::get_fresh_order( absint( $order_or_id ) );
		if ( ! is_a( $order, 'WC_Order' ) ) { return false; }
		if ( ! $order->get_meta( '_peach_auth_blocked', true ) && ! $order->get_meta( '_peach_auth_blocked_stale', true ) ) { return false; }

		$order->delete_meta_data( '_peach_auth_blocked' );
		$order->delete_meta_data( '_peach_auth_blocked_stale' );
		$order->save();
		self::remove_admin_alerts_for_order_type( $order->get_id(), 'auth_stale' );
		self::refresh_auth_blocked_admin_alert();
		return true;
	}

	/**
	 * Mark a successful Peach renewal charge before order-status handling runs.
	 *
	 * @param WC_Order $order          Renewal order object.
	 * @param string   $transaction_id Peach transaction ID.
	 * @param string   $source         Processing source.
	 * @return void
	 */
	public static function mark_renewal_payment_processed( $order, $transaction_id = '', $source = '' ) {
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
		self::remove_auth_blocked_renewal( $order );
		self::schedule_auth_blocked_recovery( 60 );
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
			$payment_initial_id = trim( (string) $subscription->get_meta( 'payment_initial_id', true ) );
			$registration_id    = trim( (string) $subscription->get_meta( 'payment_registration_id', true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id'   => $registration_id,
					'source'            => 'subscription:payment_registration_id',
					'payment_initial_id' => $payment_initial_id,
					'initial_id_source'  => '' !== $payment_initial_id ? 'subscription:payment_initial_id' : 'not_found',
				];
			}

			$registration_id = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id'   => $registration_id,
					'source'            => 'subscription:_peach_subscription_payment_method',
					'payment_initial_id' => $payment_initial_id,
					'initial_id_source'  => '' !== $payment_initial_id ? 'subscription:payment_initial_id' : 'not_found',
				];
			}
		}

		$parent_initial_id = trim( (string) $parent_order->get_meta( 'payment_initial_id', true ) );
		$parent_meta_keys = [ '_peach_subscription_payment_method', 'payment_registration_id', '_payment_registration_id' ];
		foreach ( $parent_meta_keys as $meta_key ) {
			$registration_id = trim( (string) $parent_order->get_meta( $meta_key, true ) );
			if ( '' !== $registration_id ) {
				return [
					'registration_id'   => $registration_id,
					'source'            => 'parent_order:' . $meta_key,
					'payment_initial_id' => $parent_initial_id,
					'initial_id_source'  => '' !== $parent_initial_id ? 'parent_order:payment_initial_id' : 'not_found',
				];
			}
		}


		$registration_id = trim( (string) $order->get_meta( 'payment_registration_id', true ) );
		$renewal_initial_id = trim( (string) $order->get_meta( 'payment_initial_id', true ) );
		if ( '' !== $registration_id ) {
			return [
				'registration_id'   => $registration_id,
				'source'            => 'renewal_order:payment_registration_id',
				'payment_initial_id' => $renewal_initial_id,
				'initial_id_source'  => '' !== $renewal_initial_id ? 'renewal_order:payment_initial_id' : 'not_found',
			];
		}

		return [
			'registration_id'   => '',
			'source'            => 'not_found',
			'payment_initial_id' => '',
			'initial_id_source'  => 'not_found',
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
			'_peach_initial_id_reference_payment_id' => trim( (string) $order->get_meta( '_peach_initial_id_reference_payment_id', true ) ),
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

		$new_registration = isset( $meta['payment_registration_id'] ) ? trim( (string) $meta['payment_registration_id'] ) : '';
		$current_registration = trim( (string) $order->get_meta( 'payment_registration_id', true ) );
		$registration_changed = '' !== $new_registration && $new_registration !== $current_registration;

		if ( '' !== $new_registration ) {
			$order->update_meta_data( 'payment_registration_id', $new_registration );
			$order->update_meta_data( '_peach_subscription_payment_method', $registration_changed ? $new_registration : ( ! empty( $meta['_peach_subscription_payment_method'] ) ? trim( (string) $meta['_peach_subscription_payment_method'] ) : $new_registration ) );
		}

		$initial_id = isset( $meta['payment_initial_id'] ) ? trim( (string) $meta['payment_initial_id'] ) : '';
		$reference  = isset( $meta['_peach_initial_id_reference_payment_id'] ) ? trim( (string) $meta['_peach_initial_id_reference_payment_id'] ) : '';

		if ( $registration_changed ) {
			// Registration, initial transaction ID and lookup reference form one credential.
			// Never leave the previous card's initial ID/reference attached to a new token.
			if ( '' !== $initial_id ) {
				$order->update_meta_data( 'payment_initial_id', $initial_id );
			} else {
				$order->delete_meta_data( 'payment_initial_id' );
			}

			if ( '' === $reference ) {
				$reference = isset( $meta['payment_order_id'] ) ? trim( (string) $meta['payment_order_id'] ) : '';
			}
			$order->update_meta_data( '_peach_initial_id_reference_payment_id', '' !== $reference ? $reference : 'none' );
		} else {
			if ( '' !== $initial_id ) { $order->update_meta_data( 'payment_initial_id', $initial_id ); }
			if ( '' !== $reference ) { $order->update_meta_data( '_peach_initial_id_reference_payment_id', $reference ); }
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

		return class_exists( 'PP_Gateway_Logger' )
			? PP_Gateway_Logger::mask_identifier_for_log( $value )
			: str_repeat( '*', max( 0, strlen( $value ) - 4 ) ) . substr( $value, -4 );
	}
}
