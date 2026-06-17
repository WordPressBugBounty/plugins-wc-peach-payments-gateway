<?php
/**
 * Utility functions related to WooCommerce orders for Peach Payments Gateway.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Order_Utils {
	/**
	 * Find WooCommerce order ID or sequential order number.
	 *
	 * @param string $order_identifier Order number (string) or ID.
	 * @param bool $sequential_needed Sequential Number Needed.
	 * @return WC Number|Sequential Number
	 */
	public static function find_converted_number( $order_identifier, $sequential_needed = false ) {
		$order_id = $order_identifier;
		$convert  = PP_Gateway_Settings::get( 'orderids' ) === 'yes';

		/**
		 * Conversion not needed, or gateway setting explicitly says the raw
		 * WooCommerce order ID must be used.
		 */
		if ( ! $sequential_needed || $convert ) {
			return $order_id;
		}

		/**
		 * Prefer WooCommerce's public order-number API. Sequential-number plugins
		 * such as WebToffee hook into get_order_number(), and this is also safer
		 * for HPOS than reading post meta directly.
		 */
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_identifier );

			if ( $order && method_exists( $order, 'get_order_number' ) ) {
				$order_number = trim( (string) $order->get_order_number() );

				if ( '' !== $order_number ) {
					return $order_number;
				}
			}
		}

		/**
		 * Backwards-compatible fallback for older sequential-number plugins that
		 * stored the generated number directly in known order meta keys.
		 */
		$meta = self::find_sequential_plugins();
		if ( $meta ) {
			$converted = self::convertSequentialNumber( $order_identifier, $meta );

			if ( '' !== trim( (string) $converted ) ) {
				return $converted;
			}
		}

		return $order_id;
	}

	/**
	 * Find an order by WooCommerce order ID, Peach merchant reference, or sequential order number.
	 *
	 * @param string $order_identifier Order number, Peach merchantTransactionId, or ID.
	 * @return WC_Order|false
	 */
	public static function find_order_by_number( $order_identifier ) {
		$order_identifier = trim( (string) $order_identifier );

		if ( '' === $order_identifier || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$candidates = [ $order_identifier ];
		$normalised = self::order_number_prep( $order_identifier, true );
		if ( '' !== $normalised && $normalised !== $order_identifier ) {
			$candidates[] = $normalised;
		}

		$meta_keys = [
			'_peach_expected_merchant_transaction_id',
			'_order_number',
			'_order_number_formatted',
			'_alg_wc_full_custom_order_number',
		];

		$plugin_meta_key = self::find_sequential_plugins();
		if ( $plugin_meta_key ) {
			array_unshift( $meta_keys, $plugin_meta_key );
		}

		if ( function_exists( 'wc_get_orders' ) ) {
			foreach ( array_unique( $meta_keys ) as $meta_key ) {
				foreach ( array_unique( $candidates ) as $candidate ) {
					$orders = wc_get_orders( [
						'limit'        => 1,
						'return'       => 'objects',
						'orderby'      => 'date',
						'order'        => 'DESC',
						'meta_key'     => $meta_key,
						'meta_value'   => $candidate,
						'meta_compare' => '=',
					] );

					if ( ! empty( $orders ) && is_a( $orders[0], 'WC_Order' ) ) {
						return $orders[0];
					}
				}
			}

			// Raw WooCommerce order IDs may arrive zero-padded from Peach. Try this
			// only after stored Peach/sequential references, to avoid mistaking a
			// numeric sequential order number for a different raw WooCommerce ID.
			foreach ( array_unique( $candidates ) as $candidate ) {
				if ( ctype_digit( (string) $candidate ) ) {
					$order_id = absint( ltrim( (string) $candidate, '0' ) );
					if ( $order_id > 0 ) {
						$order = wc_get_order( $order_id );
						if ( $order && is_a( $order, 'WC_Order' ) ) {
							return $order;
						}
					}
				}
			}

			// Final compatibility fallback for plugins that calculate the order number
			// dynamically via WC_Order::get_order_number() instead of storing known meta.
			$recent_orders = wc_get_orders( [
				'limit'          => 100,
				'return'         => 'objects',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'payment_method' => 'peach-payments',
			] );

			foreach ( $recent_orders as $order ) {
				if ( ! is_a( $order, 'WC_Order' ) || ! method_exists( $order, 'get_order_number' ) ) {
					continue;
				}

				$order_number = trim( (string) $order->get_order_number() );
				if ( in_array( $order_number, $candidates, true ) ) {
					return $order;
				}
			}
		}

		return false;
	}
	
	/**
	 * Prep order number: 8 digits requirement
	 *
	 * @param string $order_number Order number (string).
	 * @param bool $reversed Remove leading 0's.
	 * @return $order_number
	 */
	public static function order_number_prep( $order_number, $reversed = false ) {
		$order_number = (string) $order_number;

		if ( $reversed ) {
			// Only strip Peach's left-zero padding from plain numeric WooCommerce IDs.
			// Custom/sequential references such as 000P9007 or PEACH00002231 must stay intact.
			if ( ctype_digit( $order_number ) ) {
				$trimmed = ltrim( $order_number, '0' );
				return '' === $trimmed ? $order_number : $trimmed;
			}

			return $order_number;
		}
		
		if (strlen($order_number) < 8) {
			return str_pad($order_number, 8, '0', STR_PAD_LEFT);
		}
		
		return $order_number;
	}
	
	public static function convertSequentialNumber( $order_identifier, $key ) {
		$all_meta = get_post_meta( $order_identifier );
		$order_number = get_post_meta( $order_identifier, $key, true );
		return !empty( $order_number ?? null ) ? $order_number : $order_identifier;
	}
	
	/**
	 * Generate nonce for order creation
	 *
	 * @param string $order Order object.
	 * @return nonce
	 */
	public static function create_nonce( $order ) {
		return wp_create_nonce( $order->get_order_key().'_'.time() );
	}


	/**
	 * Add a Peach plugin note without duplicating identical internal notes.
	 *
	 * @param WC_Order $order Order or subscription object.
	 * @param string   $note  Note content.
	 * @return void
	 */
	protected static function add_plugin_note( $order, $note ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		if ( class_exists( 'PP_Gateway_Subscription_Handler' ) && method_exists( 'PP_Gateway_Subscription_Handler', 'add_unique_order_note' ) ) {
			PP_Gateway_Subscription_Handler::add_unique_order_note( $order, $note );
			return;
		}

		$order->add_order_note( trim( (string) $note ), 0, false );
	}
	
		/**
	 * Check whether the initial Peach checkout payment has already been finalised for this order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $transaction_id Peach transaction ID.
	 * @return bool
	 */
	public static function initial_payment_already_processed( WC_Order $order, $transaction_id = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		if ( $order->get_meta( '_peach_initial_payment_processed', true ) ) {
			return true;
		}

		if ( $order->is_paid() ) {
			return true;
		}

		$transaction_id = trim( (string) $transaction_id );
		if ( '' !== $transaction_id ) {
			$stored_transaction_id = trim( (string) $order->get_transaction_id() );
			$stored_payment_order_id = trim( (string) $order->get_meta( 'payment_order_id', true ) );

			if ( '' !== $stored_transaction_id && $stored_transaction_id === $transaction_id ) {
				return true;
			}

			if ( '' !== $stored_payment_order_id && $stored_payment_order_id === $transaction_id && ! self::order_status_checks( $order ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Acquire a short-lived lock so only one request can finalise the initial payment.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public static function acquire_initial_payment_lock( WC_Order $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}

		$order_id  = $order->get_id();
		$lock_key  = '_peach_initial_payment_lock';
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
	 * Release the initial-payment processing lock.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return void
	 */
	public static function release_initial_payment_lock( WC_Order $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		delete_post_meta( $order->get_id(), '_peach_initial_payment_lock' );
	}

	/**
	 * Mark the initial Peach checkout payment as processed for this order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param string   $transaction_id Peach transaction ID.
	 * @param string   $source         Processing source.
	 * @return void
	 */
	public static function mark_initial_payment_processed( WC_Order $order, $transaction_id = '', $source = '' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}

		$order->update_meta_data( '_peach_initial_payment_processed', time() );

		$transaction_id = trim( (string) $transaction_id );
		if ( '' !== $transaction_id ) {
			$order->update_meta_data( '_peach_initial_payment_processed_txn', $transaction_id );
		}

		$source = trim( (string) $source );
		if ( '' !== $source ) {
			$order->update_meta_data( '_peach_initial_payment_processed_source', $source );
		}
	}

	/**
	 * Store Peach Payments metadata to the order (registrationId and orderId).
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $registration_id Peach registrationId.
	 * @param string   $payment_order_id Peach internal order id.
	 */
	public static function store_peach_meta( WC_Order $order, $registration_id = '', $payment_order_id = '' ) {
		if ( $registration_id && ! $order->get_meta( 'payment_registration_id' ) ) {
			$order->update_meta_data( 'payment_registration_id', $registration_id );
		}

		if ( $payment_order_id && ! $order->get_meta( 'payment_order_id' ) ) {
			$order->update_meta_data( 'payment_order_id', $payment_order_id );
		}

		$order->save();
	}

	/**
	 * Get stored Peach registrationId from order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public static function get_registration_id( WC_Order $order ) {
		return $order->get_meta( 'payment_registration_id' );
	}

	/**
	 * Get stored Peach internal order ID from order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|null
	 */
	public static function get_payment_order_id( WC_Order $order ) {
		return $order->get_meta( 'payment_order_id' );
	}
	
	public static function find_sequential_plugins() {
		if(in_array('woocommerce-sequential-order-numbers-pro/woocommerce-sequential-order-numbers-pro.php', apply_filters('active_plugins', get_option('active_plugins')))){
			return '_order_number_formatted';

		}else if(in_array('wt-woocommerce-sequential-order-numbers/wt-advanced-order-number.php', apply_filters('active_plugins', get_option('active_plugins')))){
			return '_order_number';

		}else if(in_array('custom-order-numbers-for-woocommerce/custom-order-numbers-for-woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))){
			return '_alg_wc_full_custom_order_number';

		}else{
			return false;
		}
	}
	
	public static function find_subscription_plugins() {
		if(in_array('woocommerce-subscriptions/woocommerce-subscriptions.php', apply_filters('active_plugins', get_option('active_plugins')))){
			return 'woocommerce_subscriptions';
		}else{
			return false;
		}
	}
	
	public static function handle_subscription_payment_status( WC_Order $order, array $response ) {
		$response = wp_unslash( $response );
	
		// Normalise: support both flat 'result_code' (webhook/return style) and nested 'result[code]' (API style).
		if ( ! isset( $response['result']['code'] ) && isset( $response['result_code'] ) ) {
			$response['result']['code'] = $response['result_code'];
		}
	
		if ( empty( $response['result']['code'] ) ) {
			PP_Gateway_Logger::warning( 'Peach recurring payment response missing result code for order #' . $order->get_id() . '. Order left unchanged. Response: ' . print_r( $response, true ) );
			return;
		}
	
		$status_code     = sanitize_text_field( (string) $response['result']['code'] );

		if ( self::is_non_final_result_code( $status_code ) ) {
			PP_Gateway_Logger::info( 'Peach recurring payment response for order #' . $order->get_id() . ' returned non-final result code ' . $status_code . '. Order left unchanged.' );
			$order->save();
			return;
		}

		$transaction_id  = isset( $response['id'] ) ? sanitize_text_field( $response['id'] ) : '';
		$registration_id = isset( $response['registrationId'] ) ? sanitize_text_field( $response['registrationId'] ) : '';
	
		// Save to order meta (if not already stored)
		if ( $transaction_id && ! metadata_exists( 'post', $order->get_id(), 'payment_order_id' ) ) {
			$order->update_meta_data( 'payment_order_id', $transaction_id );
		}
		if ( $registration_id && ! metadata_exists( 'post', $order->get_id(), 'payment_registration_id' ) ) {
			$order->update_meta_data( 'payment_registration_id', $registration_id );
		}
	
		// Determine order status based on result code
		if ( self::is_successful_result_code( $status_code ) ) {
	
			// Get plugin setting: order status to apply
			$settings      = get_option( 'woocommerce_peach-payments_settings', [] );
			$custom_status = isset( $settings['peach_order_status'] ) ? $settings['peach_order_status'] : 'processing';
	
			// Complete order (if not already marked)
			if ( self::order_status_checks($order)) {
				$order->payment_complete( $transaction_id );
				$order->update_status( $custom_status, __( 'Recurring Payment completed via Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
				self::add_plugin_note( $order, 'Peach recurring payment successful.' );
			}
	
		} else {
			$error_msg = isset( $response['result']['description'] ) ? $response['result']['description'] : __( 'Peach payment failed.', WC_PEACH_TEXT_DOMAIN );
	
			if ( $order->is_paid() ) {
				PP_Gateway_Logger::warning( 'Ignoring non-success recurring Peach response for already paid order #' . $order->get_id() . '. Result code: ' . $status_code . '. Response: ' . print_r( $response, true ) );
				$order->save();
				return;
			}
	
			self::add_plugin_note( $order, 'Peach recurring payment failed with code [' . $status_code . '].' );
			self::handle_subscription_payment_failure(
				$order,
				sprintf( __( 'Peach Payments failure: %s (code: %s)', WC_PEACH_TEXT_DOMAIN ), $error_msg, $status_code ),
				$status_code,
				[
					'reason'   => 'gateway_failure_response',
					'response' => $response,
				]
			);
			return;
		}
	
		// Save changes before handing the paid renewal order back to WooCommerce Subscriptions.
		$order->save();
	
		if ( class_exists( 'PP_Gateway_Subscription_Handler' ) ) {
			PP_Gateway_Subscription_Handler::record_renewal_payment_success_with_subscriptions( $order, $transaction_id );
			PP_Gateway_Subscription_Handler::sync_payment_meta_from_order_to_subscriptions( $order, 'recurring_payment_success' );
		}
	}

	/**
	 * Surface a failed recurring renewal into the Subscriptions failed-payment lifecycle.
	 *
	 * @param WC_Order $order       Renewal order object.
	 * @param string   $message     Failure message.
	 * @param string   $status_code Failure/status code.
	 * @param array    $context     Extra logging context.
	 */
	public static function handle_subscription_payment_failure( WC_Order $order, $message, $status_code = '', array $context = [] ) {
		$log_context = [
			'order_id'     => $order->get_id(),
			'status_code'  => $status_code,
			'context'      => $context,
			'order_status' => $order->get_status(),
		];

		PP_Gateway_Logger::error( 'Recurring payment failure for order #' . $order->get_id() . ': ' . $message . ' | Context: ' . print_r( $log_context, true ) );

		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			$lifecycle_triggered = false;
			$subscriptions       = [];

			if ( function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
				$subscriptions = wcs_get_subscriptions_for_renewal_order( $order );
			}

			if ( is_array( $subscriptions ) && ! empty( $subscriptions ) ) {
				foreach ( $subscriptions as $subscription ) {
					if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'payment_failed' ) ) {
						continue;
					}

					$subscription->payment_failed();
					self::add_plugin_note( $subscription, sprintf( 'Peach Payments: renewal order #%d failed and subscription entered the failed-payment lifecycle.', $order->get_id() ) );
					$lifecycle_triggered = true;
				}
			}

			if ( ! $lifecycle_triggered && class_exists( 'WC_Subscriptions_Manager' ) && method_exists( 'WC_Subscriptions_Manager', 'process_subscription_payment_failure_on_order' ) ) {
				WC_Subscriptions_Manager::process_subscription_payment_failure_on_order( $order->get_id() );
				$lifecycle_triggered = true;
			}

			if ( $lifecycle_triggered ) {
				self::add_plugin_note( $order, 'Peach Payments: renewal failure sent to WooCommerce Subscriptions failed-payment lifecycle.' );
				$order->save();
				return;
			}
		}

		$order->update_status( 'failed', $message );
		$order->save();
	}
	
	public static function handle_payment_status( WC_Order $order, array $response ) {
		$response = wp_unslash( $response );


		// Normalise: support both flat 'result_code' (return/webhook path) and nested 'result[code]' (API path).
		if ( ! isset( $response['result_code'] ) && isset( $response['result']['code'] ) ) {
			$response['result_code'] = $response['result']['code'];
		}

		if ( empty( $response['result_code'] ) ) {
			PP_Gateway_Logger::warning( 'Peach Payments response missing result code for order #' . $order->get_id() . '. Order left unchanged. Response: ' . print_r( $response, true ) );
			return;
		}

		$status_code     = sanitize_text_field( (string) $response['result_code'] );

		if ( self::is_non_final_result_code( $status_code ) ) {
			PP_Gateway_Logger::info( 'Peach payment response for order #' . $order->get_id() . ' returned non-final result code ' . $status_code . '. Order left unchanged.' );
			$order->save();
			return;
		}

		$transaction_id  = isset( $response['id'] ) ? sanitize_text_field( $response['id'] ) : '';
		$registration_id = isset( $response['registrationId'] ) ? sanitize_text_field( $response['registrationId'] ) : '';

		$InitiatedTransactionID = '';
		if ( isset( $response['resultDetails']['CardholderInitiatedTransactionID'] ) ) {
			$InitiatedTransactionID = sanitize_text_field( $response['resultDetails']['CardholderInitiatedTransactionID'] );
		} elseif ( isset( $response['standingInstruction']['initialTransactionId'] ) ) {
			$InitiatedTransactionID = sanitize_text_field( $response['standingInstruction']['initialTransactionId'] );
		}

		$order->update_meta_data( 'payment_initial_id', $InitiatedTransactionID );

		// Save to order meta (if not already stored)
		if ( $transaction_id && ! metadata_exists( 'post', $order->get_id(), 'payment_order_id' ) ) {
			$order->update_meta_data( 'payment_order_id', $transaction_id );
		}
		if ( $registration_id && ! metadata_exists( 'post', $order->get_id(), 'payment_registration_id' ) ) {
			$order->update_meta_data( 'payment_registration_id', $registration_id );
		}

		// Determine order status based on result code
		if ( self::is_successful_result_code( $status_code ) ) {

			if ( self::initial_payment_already_processed( $order, $transaction_id ) ) {
				$order->save();
				return;
			}

			$lock_acquired = self::acquire_initial_payment_lock( $order );
			if ( ! $lock_acquired ) {
				return;
			}

			try {
				if ( self::initial_payment_already_processed( $order, $transaction_id ) ) {
					$order->save();
					return;
				}

				// Get plugin setting: order status to apply
				$settings      = get_option( 'woocommerce_peach-payments_settings', [] );
				$custom_status = isset( $settings['peach_order_status'] ) ? $settings['peach_order_status'] : 'processing';

				//auto_complete for vitual products
				$auto_complete = PP_Gateway_Settings::get('auto_complete');
				if($auto_complete){
					$is_virtual = self::virtual_order_check($order);
					if($is_virtual){
						$custom_status = 'completed';
					}
				}

				// Complete order (if not already marked)
				if ( self::order_status_checks($order)) {

					$order->payment_complete( $transaction_id );
					$order->update_status( $custom_status, __( 'Payment completed via Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
					self::add_plugin_note( $order, 'Peach payment successful.' );
					self::mark_initial_payment_processed( $order, $transaction_id, 'return' );
				}

				// Save USer Card if applicable
				if ( PP_Gateway_Settings::get('card_storage') === 'yes' && $order->get_user_id() > 0 ) {
					$user_id = $order->get_user_id();

					if($registration_id){
						$user_tokens = self::get_user_card_tokens( $user_id );

						if(empty($user_tokens) || !in_array($registration_id,$user_tokens)){
							$card_data = [
								'id'        => $registration_id,
								'num'       => 'xxxx-' . ( $response['card_last4Digits'] ?? '' ),
								'holder'    => $response['card_holder'] ?? '',
								'brand'     => $response['paymentBrand'] ?? '',
								'exp_month' => $response['card_expiryMonth'] ?? '',
								'exp_year'  => $response['card_expiryYear'] ?? '',
							];

							$cards = get_user_meta( $user_id, 'my-cards', true );
							if ( ! is_array( $cards ) ) {
								$cards = [];
							}
							$cards[] = $card_data;
							update_user_meta( $user_id, 'my-cards', $cards );
						}
					}
				}

				// Save changes before releasing the lock.
				$order->save();

			} finally {
				self::release_initial_payment_lock( $order );
			}

		} else {
			$error_msg = isset( $response['result']['description'] ) ? $response['result']['description'] : __( 'Peach payment failed.', WC_PEACH_TEXT_DOMAIN );

			if ( $order->is_paid() ) {
				PP_Gateway_Logger::warning( 'Ignoring non-success Peach response for already paid order #' . $order->get_id() . '. Result code: ' . $status_code . '. Response: ' . print_r( $response, true ) );
				$order->save();
				return;
			}

			$order->update_status( 'failed', sprintf( __( 'Peach Payments failure: %s (code: %s)', WC_PEACH_TEXT_DOMAIN ), $error_msg, $status_code ) );
			self::add_plugin_note( $order, 'Peach payment failed with code [' . $status_code . '].' );
			$order->save();
		}

		if ( class_exists( 'PP_Gateway_Subscription_Handler' ) ) {
			PP_Gateway_Subscription_Handler::sync_payment_meta_from_order_to_subscriptions( $order, 'initial_payment_success' );
		}
	}
	
	/**
	 * Determines whether a Peach result code represents success.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function is_successful_result_code( $code ) {
		return preg_match( '/^(000\.000\.|000\.100\.1|000\.[36])/', $code );
	}

	/**
	 * Determines whether a Peach result code is informational/non-final.
	 *
	 * Peach can send 000.200.* codes while a checkout/payment session is still
	 * pending. These are not final failures and must not move an order to failed.
	 *
	 * @param string $code Peach result code.
	 * @return bool
	 */
	public static function is_non_final_result_code( $code ) {
		$code = trim( (string) $code );

		return '' !== $code && 0 === strpos( $code, '000.200.' );
	}
	
	/**
	 * Determines whether a Peach result code represents success.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function order_status_checks($order) {
		$status = $status_check = $order->get_status();
		
		$status_setting = PP_Gateway_Settings::get('peach_order_status');
		$alt_peach_status = str_replace('wc-', '', $status_setting);
		$default_statusses = ['wc-processing','processing','wc-on-hold','on-hold','wc-completed','completed','wc-refunded','refunded',$status_setting,$alt_peach_status,'wc-checkout-draft','checkout-draft','wc-failed','failed','wc-cancelled','cancelled','wc-pending','pending'];
		$unique_statusses = array_unique($default_statusses);
		if(!in_array($status,$unique_statusses)){
			$status_check = 'unique';
		}
		
		switch ($status_check) {
			case 'completed':
				$proceed = false;
				break;
			case 'wc-completed':
				$proceed = false;
				break;
			case $status_setting:
				$proceed = false;
				break;
			case $alt_peach_status:
				$proceed = false;
				break;
			case 'on-hold':
				$proceed = false;
				break;
			case 'wc-on-hold':
				$proceed = false;
				break;
			case 'refunded':
				$proceed = false;
				break;
			case 'wc-refunded':
				$proceed = false;
				break;
			case 'processing':
				$proceed = false;
				break;
			case 'wc-processing':
				$proceed = false;
				break;
			case 'unique':
				$proceed = false;
				break;
			default:
				$proceed = true;
		}
		
		return $proceed;
	}
	
	/**
	 * Get saved cards for user.
	 *
	 * @param string $user_id
	 * @return array
	 */
	public static function get_user_card_tokens( $user_id ) {
		$saved_cards = [];
		$user_cards = get_user_meta( $user_id, 'my-cards', true );
		if ( ! is_array( $user_cards ) ) {
			return $saved_cards;
		}
		
		foreach ( $user_cards as $index => $card ) {
			if ( isset( $card['id'] ) ) {
				$saved_cards[] = $card['id'];
			}
		}
		
		return $saved_cards;
	}
	
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
	
	public static function virtual_order_check($order){
		$force_complete = false;
		$mixed_products = false;

		if ( false !== $order && count( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {
				$_product = self::get_item_product( $item, $order );
				if ( $_product ) {
					if ( $_product->is_downloadable() || $_product->is_virtual() ) {
						$force_complete = true;
					} else {
						$mixed_products = true;
					}
				}
			}
		}
		if ( true === $mixed_products ) {
			$force_complete = false;
		}
		
		return $force_complete;
	}
	
	public static function get_item_product( $item = false, $order = false ) {
		$return = 0;
		if ( false !== $item ) {
			if ( defined( 'WC_VERSION' ) && WC_VERSION >= 3.0 ) {
				$return = $item->get_product();
			} else {
				$return = $order->get_product_from_item( $item );
			}
		}
		return $return;
	}
}
