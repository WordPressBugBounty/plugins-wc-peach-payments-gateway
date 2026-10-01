<?php
/**
 * Handles AJAX requests related to deleting saved cards.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Token_Ajax_Handler {

	/**
	 * Handle AJAX request to delete a saved card.
	 */
	public static function handle_delete_card() {
		if ( false === check_ajax_referer( 'pp_delete_card_nonce', 'nonce', false ) ) {
			self::log( 'warning', 'Saved card deletion blocked. Reason: nonce verification failed.' );
			wp_die( -1, 403 );
		}
	
		if ( ! is_user_logged_in() ) {
			self::log( 'warning', 'Saved card deletion blocked. Reason: user is not logged in.' );
			wp_send_json_error( [ 'message' => __( 'Unauthorized', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$user_id = get_current_user_id();
		$card_id = sanitize_text_field( $_POST['card_id'] ?? '' );
	
		if ( empty( $card_id ) ) {
			self::log( 'warning', "Saved card deletion blocked. User ID: $user_id. Reason: card ID is missing." );
			wp_send_json_error( [ 'message' => __( 'Card ID missing', WC_PEACH_TEXT_DOMAIN ) ] );
		}
		$cards   = get_user_meta( $user_id, 'my-cards', true );
	
		if ( ! is_array( $cards ) || empty( $cards ) ) {
			self::log( 'warning', "Saved card deletion blocked. User ID: $user_id. Requested card ID: $card_id. Reason: no saved cards were found for the user." );
			wp_send_json_error( [ 'message' => __( 'No saved cards found.', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$found_index = null;
		$found_card  = null;
	
		foreach ( $cards as $index => $card ) {
			if ( isset( $card['id'] ) && $card['id'] === $card_id ) {
				$found_index = $index;
				$found_card  = $card;
				break;
			}
		}
	
		if ( is_null( $found_index ) ) {
			self::log( 'warning', "Saved card deletion blocked. User ID: $user_id. Requested card ID: $card_id. Reason: card was not found in the user's saved cards." );
			wp_send_json_error( [ 'message' => __( 'Card not found.', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$registration_id = $found_card['id'];
		$linked_subscriptions = self::get_linked_subscriptions( $registration_id );

		if ( is_wp_error( $linked_subscriptions ) ) {
			self::log( 'error', "Saved card deletion blocked. User ID: $user_id. Registration ID: $registration_id. Reason: subscription usage verification failed. Error: " . $linked_subscriptions->get_error_message() );
			wp_send_json_error( [
				'message' => __( 'Card deletion is temporarily unavailable because subscription usage could not be verified. Please try again shortly or contact support.', WC_PEACH_TEXT_DOMAIN ),
			] );
		}

		if ( ! empty( $linked_subscriptions ) ) {
			$subscription_ids = array_map( static function( $subscription ) {
				return isset( $subscription['id'] ) ? (int) $subscription['id'] : 0;
			}, $linked_subscriptions );
			$subscription_ids = array_values( array_filter( $subscription_ids ) );

			$has_alternative_card = false;
			foreach ( $cards as $card ) {
				$other_registration_id = isset( $card['id'] ) ? trim( (string) $card['id'] ) : '';
				if ( '' !== $other_registration_id && $registration_id !== $other_registration_id ) {
					$has_alternative_card = true;
					break;
				}
			}

			self::log(
				'warning',
				"Saved card deletion blocked. User ID: $user_id. Registration ID: $registration_id. Reason: card is assigned to protected Peach subscription(s). Subscription IDs: " .
				implode( ',', $subscription_ids )
			);

			if ( 1 === count( $subscription_ids ) ) {
				if ( $has_alternative_card ) {
					$message = sprintf(
						__( 'This card is used by Subscription #%d and cannot be deleted. Please change the subscription to another saved card first.', WC_PEACH_TEXT_DOMAIN ),
						$subscription_ids[0]
					);
				} else {
					$message = sprintf(
						__( 'This card is used by Subscription #%d and cannot be deleted. Please add another card first, then change the subscription card.', WC_PEACH_TEXT_DOMAIN ),
						$subscription_ids[0]
					);
				}
			} else {
				$subscription_list = implode( ', ', array_map( static function( $subscription_id ) {
					return '#' . $subscription_id;
				}, $subscription_ids ) );

				if ( $has_alternative_card ) {
					$message = sprintf(
						__( 'This card is used by subscriptions %s and cannot be deleted. Please change each subscription to another saved card first.', WC_PEACH_TEXT_DOMAIN ),
						$subscription_list
					);
				} else {
					$message = sprintf(
						__( 'This card is used by subscriptions %s and cannot be deleted. Please add another card first, then change each subscription card.', WC_PEACH_TEXT_DOMAIN ),
						$subscription_list
					);
				}
			}

			wp_send_json_error( [
				'message'       => $message,
				'subscriptions' => $linked_subscriptions,
			] );
		}
	
		// Delete from Peach API
		$api          = new PP_Peach_API();
		$api_response = $api->delete_token( $registration_id );
		$stale_registration_result_code = '';
	
		if ( is_wp_error( $api_response ) ) {
			$stale_registration_result_code = self::get_unavailable_registration_result_code( $api_response );

			if ( '' === $stale_registration_result_code ) {
				self::log( 'error', "Saved card deletion failed. User ID: $user_id. Registration ID: $registration_id. Reason: Peach API token deletion failed. Error: " . $api_response->get_error_message() );
				wp_send_json_error( [ 'message' => $api_response->get_error_message() ] );
			}

			self::log(
				'info',
				"Saved card Peach registration is already unavailable. User ID: $user_id. Registration ID: $registration_id. Peach result code: $stale_registration_result_code. Local removal is allowed because no protected subscription uses this registration."
			);
		}
	
		// Remove from local user meta. This runs only after Peach deregistration succeeds,
		// or Peach explicitly confirms that the unlinked registration is already unusable.
		unset( $cards[ $found_index ] );
		update_user_meta( $user_id, 'my-cards', array_values( $cards ) );
	
		if ( '' !== $stale_registration_result_code ) {
			self::log( 'info', "Saved card removed locally after Peach confirmed the registration is unavailable. User ID: $user_id. Registration ID: $registration_id. Peach result code: $stale_registration_result_code." );
		} else {
			self::log( 'info', "Saved card deleted successfully. User ID: $user_id. Registration ID: $registration_id." );
		}

		wp_send_json_success( [ 'message' => __( 'Card deleted successfully.', WC_PEACH_TEXT_DOMAIN ) ] );
	}


	/**
	 * Return Peach's result code when a failed deregistration definitively means
	 * the registration token is already absent or unusable at Peach Payments.
	 *
	 * Transient, configuration, authentication, and non-final registration states
	 * are intentionally excluded. A failed deregistration by itself is never enough
	 * to permit local card deletion.
	 *
	 * @param WP_Error $error Peach API error returned while deregistering the token.
	 * @return string Terminal Peach result code, or an empty string when local deletion must remain blocked.
	 */
	protected static function get_unavailable_registration_result_code( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return '';
		}

		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			return '';
		}

		$result_code = '';
		if ( isset( $data['result']['code'] ) ) {
			$result_code = trim( (string) $data['result']['code'] );
		} elseif ( isset( $data['resultCode'] ) ) {
			$result_code = trim( (string) $data['resultCode'] );
		} elseif ( isset( $data['result_code'] ) ) {
			$result_code = trim( (string) $data['result_code'] );
		}

		$unavailable_registration_codes = [
			'100.150.101', // Invalid registration ID format.
			'100.150.200', // Registration does not exist.
			'100.150.202', // Registration is already deregistered.
			'100.150.203', // Registration is not valid.
			'100.150.204', // Registration reference points to no registration transaction.
			'100.150.205', // Registration does not contain an account.
			'100.150.206', // Registration retention period expired.
			'100.350.303', // Cannot deregister an unregistered account/customer.
		];

		return in_array( $result_code, $unavailable_registration_codes, true ) ? $result_code : '';
	}


	/**
	 * Check whether a registration ID is still assigned to a protected Peach subscription.
	 *
	 * Protected subscription states are pending, active and on-hold. Historical completed
	 * renewal orders are intentionally ignored; only open/retryable renewal orders are
	 * checked as a fallback so an old card is not blocked merely because it was used before.
	 *
	 * @param string $registration_id Registration ID to check.
	 * @return array|WP_Error Linked subscription details, an empty array when clear, or WP_Error when verification cannot complete.
	 */
	protected static function get_linked_subscriptions( $registration_id ) {
		$registration_id = trim( (string) $registration_id );
		if ( '' === $registration_id ) {
			return [];
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'peach_subscription_check_no_user', 'Unable to verify subscriptions because the current user could not be determined.' );
		}

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return self::get_linked_subscriptions_from_storage( $registration_id, $user_id );
		}

		try {
			$subscriptions = wcs_get_users_subscriptions( $user_id );
		} catch ( Throwable $e ) {
			return new WP_Error( 'peach_subscription_check_failed', $e->getMessage() );
		}

		if ( ! is_array( $subscriptions ) ) {
			return new WP_Error( 'peach_subscription_check_invalid_response', 'WooCommerce Subscriptions returned an unexpected response while checking card usage.' );
		}

		$linked_subscriptions = [];

		foreach ( $subscriptions as $subscription ) {
			if (
				! is_object( $subscription ) ||
				! method_exists( $subscription, 'get_id' ) ||
				! method_exists( $subscription, 'get_payment_method' ) ||
				! method_exists( $subscription, 'has_status' )
			) {
				return new WP_Error( 'peach_subscription_check_invalid_subscription', 'WooCommerce Subscriptions returned an invalid subscription object while checking card usage.' );
			}

			if ( 'peach-payments' !== (string) $subscription->get_payment_method() ) {
				continue;
			}

			if ( ! $subscription->has_status( [ 'pending', 'active', 'on-hold' ] ) ) {
				continue;
			}

			$linked_registration_ids = [];

			if ( method_exists( $subscription, 'get_meta' ) ) {
				$linked_registration_ids[] = trim( (string) $subscription->get_meta( 'payment_registration_id', true ) );
				$linked_registration_ids[] = trim( (string) $subscription->get_meta( '_peach_subscription_payment_method', true ) );
			}

			$parent_order_id = method_exists( $subscription, 'get_parent_id' ) ? (int) $subscription->get_parent_id() : 0;
			if ( $parent_order_id && function_exists( 'wc_get_order' ) ) {
				$parent_order = wc_get_order( $parent_order_id );
				if ( $parent_order && method_exists( $parent_order, 'get_meta' ) ) {
					$linked_registration_ids[] = trim( (string) $parent_order->get_meta( '_peach_subscription_payment_method', true ) );
					$linked_registration_ids[] = trim( (string) $parent_order->get_meta( 'payment_registration_id', true ) );
					$linked_registration_ids[] = trim( (string) $parent_order->get_meta( '_payment_registration_id', true ) );
				}
			}

			$linked = self::registration_id_matches( $registration_id, $linked_registration_ids );

			if ( ! $linked ) {
				$renewal_match = self::registration_id_matches_open_renewal_order( $subscription, $registration_id );
				if ( is_wp_error( $renewal_match ) ) {
					return $renewal_match;
				}
				$linked = $renewal_match;
			}

			if ( ! $linked ) {
				continue;
			}

			$subscription_id = (int) $subscription->get_id();
			$linked_subscriptions[] = [
				'id'  => $subscription_id,
				'url' => self::get_change_card_url( $subscription_id ),
			];
		}

		return $linked_subscriptions;
	}

	/**
	 * Check persisted subscription storage when the WooCommerce Subscriptions runtime is unavailable.
	 *
	 * The decision remains card-specific: if the exact registration ID is not referenced by
	 * one of this customer's protected Peach subscriptions, deletion is allowed. Direct
	 * database access is used only as a fallback because the WCS object API is unavailable.
	 *
	 * @param string $registration_id Registration ID being deleted.
	 * @param int    $user_id         Current customer ID.
	 * @return array|WP_Error Linked subscription details, an empty array when clear, or WP_Error when verification cannot complete.
	 */
	protected static function get_linked_subscriptions_from_storage( $registration_id, $user_id ) {
		$hpos_authoritative = self::is_hpos_authoritative();
		if ( is_wp_error( $hpos_authoritative ) ) {
			return $hpos_authoritative;
		}

		$subscription_ids = $hpos_authoritative
			? self::get_hpos_linked_subscription_ids( $registration_id, $user_id )
			: self::get_legacy_linked_subscription_ids( $registration_id, $user_id );

		if ( is_wp_error( $subscription_ids ) ) {
			return $subscription_ids;
		}

		$subscription_ids = array_values( array_unique( array_map( 'absint', $subscription_ids ) ) );
		$subscription_ids = array_values( array_filter( $subscription_ids ) );

		return array_map( static function( $subscription_id ) {
			return [
				'id'  => $subscription_id,
				'url' => self::get_change_card_url( $subscription_id ),
			];
		}, $subscription_ids );
	}

	/**
	 * Determine which WooCommerce order datastore is authoritative.
	 *
	 * @return bool|WP_Error True for HPOS, false for posts/postmeta, or WP_Error when it cannot be determined.
	 */
	protected static function is_hpos_authoritative() {
		$order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';

		if ( class_exists( $order_util ) && method_exists( $order_util, 'custom_orders_table_usage_is_enabled' ) ) {
			try {
				return (bool) $order_util::custom_orders_table_usage_is_enabled();
			} catch ( Throwable $e ) {
				return new WP_Error( 'peach_subscription_storage_mode_failed', $e->getMessage() );
			}
		}

		if ( function_exists( 'get_option' ) ) {
			$hpos_option = get_option( 'woocommerce_custom_orders_table_enabled', 'no' );
			return in_array( $hpos_option, [ 'yes', true, 1, '1' ], true );
		}

		return new WP_Error( 'peach_subscription_storage_mode_unavailable', 'WooCommerce authoritative order storage could not be determined.' );
	}

	/**
	 * Find exact registration-ID usage in legacy WordPress order storage.
	 *
	 * @param string $registration_id Registration ID being deleted.
	 * @param int    $user_id         Current customer ID.
	 * @return array|WP_Error
	 */
	protected static function get_legacy_linked_subscription_ids( $registration_id, $user_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->posts ) || empty( $wpdb->postmeta ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return new WP_Error( 'peach_subscription_storage_unavailable', 'Legacy subscription storage could not be verified.' );
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT s.ID
			 FROM {$wpdb->posts} s
			 INNER JOIN {$wpdb->postmeta} customer_meta
				 ON customer_meta.post_id = s.ID
				AND customer_meta.meta_key = '_customer_user'
				AND customer_meta.meta_value = %s
			 INNER JOIN {$wpdb->postmeta} gateway_meta
				 ON gateway_meta.post_id = s.ID
				AND gateway_meta.meta_key = '_payment_method'
				AND gateway_meta.meta_value = 'peach-payments'
			 WHERE s.post_type = 'shop_subscription'
			   AND s.post_status IN ( 'wc-pending', 'wc-active', 'wc-on-hold' )
			   AND (
				 EXISTS (
					 SELECT 1 FROM {$wpdb->postmeta} subscription_reg
					 WHERE subscription_reg.post_id = s.ID
					   AND subscription_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method' )
					   AND subscription_reg.meta_value = %s
				 )
				 OR (
					 s.post_parent > 0
					 AND EXISTS (
						 SELECT 1 FROM {$wpdb->postmeta} parent_reg
						 WHERE parent_reg.post_id = s.post_parent
						   AND parent_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method', '_payment_registration_id' )
						   AND parent_reg.meta_value = %s
					 )
				 )
				 OR EXISTS (
					 SELECT 1
					 FROM {$wpdb->posts} renewal_order
					 INNER JOIN {$wpdb->postmeta} renewal_relation
						 ON renewal_relation.post_id = renewal_order.ID
						AND renewal_relation.meta_key = '_subscription_renewal'
						AND CAST( renewal_relation.meta_value AS UNSIGNED ) = s.ID
					 INNER JOIN {$wpdb->postmeta} renewal_reg
						 ON renewal_reg.post_id = renewal_order.ID
						AND renewal_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method', '_payment_registration_id' )
						AND renewal_reg.meta_value = %s
					 WHERE renewal_order.post_type = 'shop_order'
					   AND renewal_order.post_status IN ( 'wc-pending', 'wc-on-hold', 'wc-failed' )
				 )
			   )",
			(string) $user_id,
			$registration_id,
			$registration_id,
			$registration_id
		);

		$subscription_ids = $wpdb->get_col( $sql );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'peach_subscription_legacy_query_failed', $wpdb->last_error );
		}

		return is_array( $subscription_ids ) ? $subscription_ids : [];
	}

	/**
	 * Find exact registration-ID usage in WooCommerce HPOS subscription storage.
	 *
	 * @param string $registration_id Registration ID being deleted.
	 * @param int    $user_id         Current customer ID.
	 * @return array|WP_Error
	 */
	protected static function get_hpos_linked_subscription_ids( $registration_id, $user_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || empty( $wpdb->prefix ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) ) {
			return new WP_Error( 'peach_subscription_hpos_unavailable', 'HPOS subscription storage could not be verified.' );
		}

		$orders_table = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' )
			? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_orders_table_name()
			: $wpdb->prefix . 'wc_orders';
		$meta_table = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore' )
			? \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore::get_meta_table_name()
			: $wpdb->prefix . 'wc_orders_meta';

		$orders_table_exists = self::database_table_exists( $orders_table );
		if ( is_wp_error( $orders_table_exists ) ) {
			return $orders_table_exists;
		}
		if ( ! $orders_table_exists ) {
			return [];
		}

		$meta_table_exists = self::database_table_exists( $meta_table );
		if ( is_wp_error( $meta_table_exists ) ) {
			return $meta_table_exists;
		}
		if ( ! $meta_table_exists ) {
			return new WP_Error( 'peach_subscription_hpos_meta_missing', 'HPOS order storage exists but its metadata table could not be found.' );
		}

		$sql = $wpdb->prepare(
			"SELECT DISTINCT s.id
			 FROM {$orders_table} s
			 WHERE s.type = 'shop_subscription'
			   AND s.status IN ( 'wc-pending', 'wc-active', 'wc-on-hold' )
			   AND s.customer_id = %d
			   AND s.payment_method = 'peach-payments'
			   AND (
				 EXISTS (
					 SELECT 1 FROM {$meta_table} subscription_reg
					 WHERE subscription_reg.order_id = s.id
					   AND subscription_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method' )
					   AND subscription_reg.meta_value = %s
				 )
				 OR (
					 s.parent_order_id > 0
					 AND EXISTS (
						 SELECT 1 FROM {$meta_table} parent_reg
						 WHERE parent_reg.order_id = s.parent_order_id
						   AND parent_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method', '_payment_registration_id' )
						   AND parent_reg.meta_value = %s
					 )
				 )
				 OR EXISTS (
					 SELECT 1
					 FROM {$orders_table} renewal_order
					 INNER JOIN {$meta_table} renewal_relation
						 ON renewal_relation.order_id = renewal_order.id
						AND renewal_relation.meta_key = '_subscription_renewal'
						AND CAST( renewal_relation.meta_value AS UNSIGNED ) = s.id
					 INNER JOIN {$meta_table} renewal_reg
						 ON renewal_reg.order_id = renewal_order.id
						AND renewal_reg.meta_key IN ( 'payment_registration_id', '_peach_subscription_payment_method', '_payment_registration_id' )
						AND renewal_reg.meta_value = %s
					 WHERE renewal_order.type = 'shop_order'
					   AND renewal_order.status IN ( 'wc-pending', 'wc-on-hold', 'wc-failed' )
				 )
			   )",
			(int) $user_id,
			$registration_id,
			$registration_id,
			$registration_id
		);

		$subscription_ids = $wpdb->get_col( $sql );
		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'peach_subscription_hpos_query_failed', $wpdb->last_error );
		}

		return is_array( $subscription_ids ) ? $subscription_ids : [];
	}

	/**
	 * Check whether a database table exists without treating a missing optional table as an error.
	 *
	 * @param string $table_name Table name including prefix.
	 * @return bool|WP_Error
	 */
	protected static function database_table_exists( $table_name ) {
		global $wpdb;

		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) )
		);

		if ( ! empty( $wpdb->last_error ) ) {
			return new WP_Error( 'peach_subscription_table_check_failed', $wpdb->last_error );
		}

		return $table_name === $table_exists;
	}

	/**
	 * Check whether a registration ID is present in a list of possible IDs.
	 *
	 * @param string $registration_id Registration ID being deleted.
	 * @param array  $possible_ids    Possible linked registration IDs.
	 * @return bool
	 */
	protected static function registration_id_matches( $registration_id, array $possible_ids ) {
		foreach ( $possible_ids as $possible_id ) {
			$possible_id = trim( (string) $possible_id );
			if ( '' !== $possible_id && $registration_id === $possible_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check open/retryable renewal orders for a registration ID fallback.
	 *
	 * @param WC_Subscription $subscription    Subscription object.
	 * @param string          $registration_id Registration ID being deleted.
	 * @return bool|WP_Error
	 */
	protected static function registration_id_matches_open_renewal_order( $subscription, $registration_id ) {
		if ( ! method_exists( $subscription, 'get_related_orders' ) || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		try {
			$renewal_order_ids = $subscription->get_related_orders( 'ids', 'renewal' );
		} catch ( Throwable $e ) {
			return new WP_Error( 'peach_subscription_renewal_check_failed', $e->getMessage() );
		}

		if ( ! is_array( $renewal_order_ids ) ) {
			return new WP_Error( 'peach_subscription_renewal_check_invalid_response', 'WooCommerce Subscriptions returned an unexpected renewal-order response while checking card usage.' );
		}

		foreach ( $renewal_order_ids as $renewal_order_id ) {
			$renewal_order = wc_get_order( absint( $renewal_order_id ) );
			if ( ! $renewal_order || ! method_exists( $renewal_order, 'get_status' ) || ! method_exists( $renewal_order, 'get_meta' ) ) {
				continue;
			}

			if ( ! in_array( (string) $renewal_order->get_status(), [ 'pending', 'on-hold', 'failed' ], true ) ) {
				continue;
			}

			$renewal_registration_ids = [
				trim( (string) $renewal_order->get_meta( 'payment_registration_id', true ) ),
				trim( (string) $renewal_order->get_meta( '_peach_subscription_payment_method', true ) ),
				trim( (string) $renewal_order->get_meta( '_payment_registration_id', true ) ),
			];

			if ( self::registration_id_matches( $registration_id, $renewal_registration_ids ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build the existing Peach Change Card endpoint URL for a subscription.
	 *
	 * @param int $subscription_id Subscription ID.
	 * @return string
	 */
	protected static function get_change_card_url( $subscription_id ) {
		if ( ! class_exists( 'PP_Gateway_Change_Card_Endpoint' ) || ! function_exists( 'wc_get_account_endpoint_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}

		return add_query_arg(
			[ 'subscription_id' => (int) $subscription_id ],
			wc_get_account_endpoint_url( PP_Gateway_Change_Card_Endpoint::$endpoint )
		);
	}

	/**
	 * Log saved-card deletion activity through the plugin's centralized WooCommerce logger.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	protected static function log( $level, $message ) {
		// The centralized logger is loaded by the plugin bootstrap before this
		// handler. Do not fall back to an unsanitized logger if bootstrap is
		// incomplete, because deletion errors may contain Peach identifiers.
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::log( $level, $message );
		}
	}
}
