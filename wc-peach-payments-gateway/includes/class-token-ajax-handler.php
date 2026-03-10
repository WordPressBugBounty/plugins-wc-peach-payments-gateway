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
		check_ajax_referer( 'pp_delete_card_nonce', 'nonce' );
	
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$card_id = sanitize_text_field( $_POST['card_id'] ?? '' );
	
		if ( empty( $card_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Card ID missing', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$user_id = get_current_user_id();
		$cards   = get_user_meta( $user_id, 'my-cards', true );
	
		if ( ! is_array( $cards ) || empty( $cards ) ) {
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
			wp_send_json_error( [ 'message' => __( 'Card not found.', WC_PEACH_TEXT_DOMAIN ) ] );
		}
	
		$registration_id = $found_card['id'];
	
		$linked_order_id = self::get_linked_subscription_order( $registration_id );
		if ( $linked_order_id ) {
			self::log( "User $user_id attempted to delete a card in use by subscription. Registration ID: $registration_id, Order ID: $linked_order_id" );
			wp_send_json_error( [
				'message' => __( 'This card is used for an active subscription and cannot be deleted.', WC_PEACH_TEXT_DOMAIN )
			] );
		}
	
		// Delete from Peach API
		$api          = new PP_Peach_API();
		$api_response = $api->delete_token( $registration_id );
	
		if ( is_wp_error( $api_response ) ) {
			self::log( "Error deleting card for user $user_id, ID: $registration_id — " . $api_response->get_error_message() );
			wp_send_json_error( [ 'message' => $api_response->get_error_message() ] );
		}
	
		// Remove from local user meta
		unset( $cards[ $found_index ] );
		update_user_meta( $user_id, 'my-cards', array_values( $cards ) );
	
		self::log( "User $user_id successfully deleted card ID: $registration_id" );
		wp_send_json_success( [ 'message' => __( 'Card deleted successfully.', WC_PEACH_TEXT_DOMAIN ) ] );
	}


	/**
	 * Check if registration ID is used in a parent order.
	 *
	 * @param string $registration_id
	 * @return int|null The order ID if found, otherwise null.
	 */
	protected static function get_linked_subscription_order( $registration_id ) {
		if ( empty( $registration_id ) ) {
			return null;
		}
	
		$args = [
			'post_type'      => 'shop_order',
			'post_status'    => [ 'wc-active', 'wc-pending', 'wc-on-hold', 'wc-processing' ],
			'post_parent'    => 0,
			'posts_per_page' => -1,
			'meta_query'     => [
				[
					'key'   => 'payment_registration_id',
					'value' => $registration_id,
				],
			],
			'fields' => 'ids',
		];
	
		$query = new WP_Query( $args );
	
		if ( $query->have_posts() ) {
        	foreach ( $query->posts as $order_id ) {
				if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order_id ) ) {
					return $order_id;
				}
	
				if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
					return $order_id;
				}
			}
		}
	
		return null;
	}

	/**
	 * Log activity to WooCommerce logger.
	 *
	 * @param string $message
	 */
	protected static function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info( $message, [ 'source' => 'peach-payments' ] );
		}
	}
}
