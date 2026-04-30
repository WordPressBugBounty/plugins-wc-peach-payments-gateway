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
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'woocommerce_scheduled_subscription_payment_retry', [ __CLASS__, 'log_scheduled_payment_retry' ], 5, 1 );
		add_action( 'woocommerce_subscriptions_changed_failing_payment_method_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_action( 'woocommerce_subscription_failing_payment_method_updated_peach-payments', [ __CLASS__, 'handle_changed_failing_payment_method' ], 10, 2 );
		add_filter( 'woocommerce_subscription_payment_meta', [ __CLASS__, 'add_subscription_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_subscription_validate_payment_meta', [ __CLASS__, 'validate_subscription_payment_meta' ], 10, 2 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_process_renewal', [ __CLASS__, 'maybe_force_admin_process_renewal' ], 999 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'capture_admin_renewal_pre_state' ], 1 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'prepare_admin_renewal_request' ], 5 );
		add_action( 'woocommerce_order_action_wcs_create_pending_renewal', [ __CLASS__, 'maybe_force_admin_create_pending_renewal' ], 999 );
		add_action( 'woocommerce_scheduled_subscription_payment', [ __CLASS__, 'capture_scheduled_renewal_pre_state' ], 1, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment', [ __CLASS__, 'prepare_scheduled_renewal_request' ], 5, 1 );
		add_action( 'woocommerce_scheduled_subscription_payment', [ __CLASS__, 'maybe_force_scheduled_subscription_payment' ], 999, 1 );
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

		PP_Gateway_Logger::info(
			sprintf(
				'Renewal payment attempt starting for renewal order #%1$d (parent #%2$d). Amount: %3$s. Registration source: %4$s.',
				$order_id,
				$parent_order_id,
				number_format( (float) $amount_to_charge, 2, '.', '' ),
				$payment_data['source']
			)
		);

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

		PP_Gateway_Order_Utils::handle_subscription_payment_status( $order, $response );
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
		$message    = sprintf( 'Peach Payments: admin renewal action preparation started for subscription #%d.', $subscription->get_id() );

		PP_Gateway_Logger::info( $message );
		$subscription->add_order_note( $message, 0, false );

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

		PP_Gateway_Logger::info( sprintf( 'Peach Payments: scheduled renewal action preparation started for subscription #%d.', $subscription->get_id() ) );

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

		$subscription_id = $subscription->get_id();
		$before_ids      = self::$scheduled_action_pre_state[ $subscription_id ] ?? [];
		$after_ids       = self::get_related_renewal_order_ids( $subscription );
		$new_ids         = array_values( array_diff( $after_ids, $before_ids ) );

		if ( ! empty( $new_ids ) ) {
			PP_Gateway_Logger::info( sprintf( 'Peach Payments: core scheduled renewal action created renewal order(s) for subscription #%1$d: %2$s.', $subscription_id, implode( ',', $new_ids ) ) );
			return;
		}

		if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
			$message = sprintf( 'Peach Payments scheduled renewal fallback failed for subscription #%d because wcs_create_renewal_order() is unavailable.', $subscription_id );
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		try {
			$renewal_order = wcs_create_renewal_order( $subscription );
		} catch ( Exception $e ) {
			$message = sprintf( 'Peach Payments scheduled renewal fallback failed for subscription #%1$d: %2$s', $subscription_id, $e->getMessage() );
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		if ( is_wp_error( $renewal_order ) || ! is_a( $renewal_order, 'WC_Order' ) ) {
			$message = sprintf( 'Peach Payments scheduled renewal fallback failed for subscription #%d because no renewal order could be created.', $subscription_id );
			if ( is_wp_error( $renewal_order ) ) {
				$message .= ' ' . $renewal_order->get_error_message();
			}
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		self::add_unique_order_note( $renewal_order, 'Peach Payments: renewal order created via gateway fallback from scheduled action.' );
		self::add_unique_order_note( $subscription, sprintf( 'Peach Payments: renewal order #%d created via gateway fallback from scheduled action.', $renewal_order->get_id() ) );

		$amount_to_charge = (float) $renewal_order->get_total();
		PP_Gateway_Logger::info( sprintf( 'Peach Payments: processing scheduled fallback renewal order #%1$d for subscription #%2$d. Amount %3$s.', $renewal_order->get_id(), $subscription_id, number_format( $amount_to_charge, 2, '.', '' ) ) );
		do_action( 'woocommerce_scheduled_subscription_payment_peach-payments', $amount_to_charge, $renewal_order );
	}

	/**
	 * Create/process a fallback renewal order when the core admin action does not produce one.
	 *
	 * @param WC_Order|WC_Subscription $subscription   Subscription object.
	 * @param bool                     $process_payment Whether to process payment immediately.
	 */
	protected static function maybe_force_admin_renewal_action( $subscription, $process_payment ) {
		if ( ! self::is_peach_subscription( $subscription ) ) {
			return;
		}

		$subscription_id = $subscription->get_id();
		$before_ids      = self::$admin_action_pre_state[ $subscription_id ] ?? [];
		$after_ids       = self::get_related_renewal_order_ids( $subscription );
		$new_ids         = array_values( array_diff( $after_ids, $before_ids ) );

		if ( ! empty( $new_ids ) ) {
			PP_Gateway_Logger::info( sprintf( 'Peach Payments: core admin renewal action created renewal order(s) for subscription #%1$d: %2$s.', $subscription_id, implode( ',', $new_ids ) ) );
			return;
		}

		if ( ! function_exists( 'wcs_create_renewal_order' ) ) {
			$message = sprintf( 'Peach Payments admin renewal fallback failed for subscription #%d because wcs_create_renewal_order() is unavailable.', $subscription_id );
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		try {
			$renewal_order = wcs_create_renewal_order( $subscription );
		} catch ( Exception $e ) {
			$message = sprintf( 'Peach Payments admin renewal fallback failed for subscription #%1$d: %2$s', $subscription_id, $e->getMessage() );
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		if ( is_wp_error( $renewal_order ) || ! is_a( $renewal_order, 'WC_Order' ) ) {
			$message = sprintf( 'Peach Payments admin renewal fallback failed for subscription #%d because no renewal order could be created.', $subscription_id );
			if ( is_wp_error( $renewal_order ) ) {
				$message .= ' ' . $renewal_order->get_error_message();
			}
			PP_Gateway_Logger::error( $message );
			self::add_unique_order_note( $subscription, $message );
			return;
		}

		self::add_unique_order_note( $renewal_order, 'Peach Payments: renewal order created via gateway fallback from admin action.' );
		self::add_unique_order_note( $subscription, sprintf( 'Peach Payments: renewal order #%d created via gateway fallback from admin action.', $renewal_order->get_id() ) );

		if ( $process_payment ) {
			$amount_to_charge = (float) $renewal_order->get_total();
			PP_Gateway_Logger::info( sprintf( 'Peach Payments: processing fallback renewal order #%1$d for subscription #%2$d. Amount %3$s.', $renewal_order->get_id(), $subscription_id, number_format( $amount_to_charge, 2, '.', '' ) ) );
			do_action( 'woocommerce_scheduled_subscription_payment_peach-payments', $amount_to_charge, $renewal_order );
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

		PP_Gateway_Logger::info( sprintf( 'WooCommerce Subscriptions scheduled retry triggered for Peach renewal order #%d.', $renewal_order_id ) );
		self::add_unique_order_note( $order, 'Peach Payments: WooCommerce Subscriptions scheduled retry triggered for this renewal order.' );
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

		PP_Gateway_Logger::info(
			sprintf(
				'Updated Peach recurring payment meta after failed renewal recovery. Original order #%1$d, renewal order #%2$d, registration ID %3$s.',
				$original_order->get_id(),
				$renewal_order->get_id(),
				self::mask_meta_value( $meta_to_sync['payment_registration_id'] )
			)
		);
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

		PP_Gateway_Logger::info(
			sprintf(
				'Synced Peach recurring payment meta from order #%1$d to %2$d related subscription(s) via %3$s. Registration ID %4$s.',
				$order->get_id(),
				count( $subscriptions ),
				$context_label,
				$masked_registration_id
			)
		);
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

		PP_Gateway_Logger::info(
			sprintf(
				'Backfilled Peach recurring payment meta from parent order #%1$d to subscription #%2$d. Registration ID %3$s.',
				$parent_order->get_id(),
				$subscription->get_id(),
				self::mask_meta_value( $meta_to_sync['payment_registration_id'] )
			)
		);

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
