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
		$convert = PP_Gateway_Settings::get( 'orderids' ) === 'yes';
		$meta = self::find_sequential_plugins();
		
		/**
		 * Sequential plugins not found
		*/
		if(!$meta){
			return $order_id;
		}
		
		/**
		 * Conversion not needed
		 * WC ID Sent and must use
		*/
		if(!$sequential_needed){
			return $order_id;
		}
		
		/**
		 * Settings: Must Use WC
		*/
		if($convert){
			return $order_id;
		}
		
		return self::convertSequentialNumber($order_identifier, $meta);
	}

	/**
	 * Find an order by WooCommerce order ID or sequential order number.
	 *
	 * @param string $order_identifier Order number (string) or ID.
	 * @return WC_Order|false
	 */
	public static function find_order_by_number( $order_identifier) {
		PP_Peach_API::log_error( 'Order Generation Needed', '', '', $order_identifier );
		return false;

		// If using default WooCommerce order IDs
		if ( $use_default_ids && is_numeric( $order_identifier ) ) {
			return wc_get_order( (int) $order_identifier );
		}

		// Otherwise search for _order_number post meta
		$args = [
			'limit'        => 1,
			'return'       => 'objects',
			'orderby'      => 'date',
			'order'        => 'DESC',
			'meta_key'     => '_order_number',
			'meta_value'   => $order_identifier,
			'meta_compare' => '=',
		];

		$orders = wc_get_orders( $args );

		return ! empty( $orders ) ? $orders[0] : wc_get_order( $order_identifier );
	}
	
	/**
	 * Prep order number: 8 digits requirement
	 *
	 * @param string $order_number Order number (string).
	 * @param bool $reversed Remove leading 0's.
	 * @return $order_number
	 */
	public static function order_number_prep( $order_number, $reversed = false ) {
		if($reversed){
			return ltrim($order_number, '0');
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
		if ( ! isset( $response['result']['code'] ) ) {
			$order->update_status( 'failed', __( 'Peach Payments response missing result code.', WC_PEACH_TEXT_DOMAIN ) );
			$order->add_order_note( 'Peach Recurring Payment Failed. Missing result code in response',0,false);
			return;
		}

		$status_code = $response['result']['code'];
		$transaction_id = isset( $response['id'] ) ? sanitize_text_field( $response['id'] ) : '';
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
				$order->add_order_note( 'Peach Recurring Payment Successfull.',0,false);
			}

		} else {
			// Failure case
			$error_msg = isset( $response['result']['description'] ) ? $response['result']['description'] : __( 'Peach payment failed.', WC_PEACH_TEXT_DOMAIN );
			$order->update_status( 'failed', sprintf( __( 'Peach Payments failure: %s (code: %s)', WC_PEACH_TEXT_DOMAIN ), $error_msg, $status_code ) );
			$order->add_order_note( 'Peach Recurring Payment Failed with code ['.$status_code.'].',0,false);
		}

		// Save changes
		$order->save();
	}
	
	public static function handle_payment_status( WC_Order $order, array $response ) {
		PP_Gateway_Logger::info( "Peach Response. ".print_r($response, true) );
		
		if ( ! isset( $response['result_code'] ) ) {
			$order->update_status( 'failed', __( 'Peach Payments response missing result code.', WC_PEACH_TEXT_DOMAIN ) );
			$order->add_order_note( 'Peach Recurring Payment Failed. Missing result code in response',0,false);
			return;
		}

		$status_code = $response['result_code'];
		$transaction_id = isset( $response['id'] ) ? sanitize_text_field( $response['id'] ) : '';
		$registration_id = isset( $response['registrationId'] ) ? sanitize_text_field( $response['registrationId'] ) : '';
		
		$InitiatedTransactionID = '';
		if (isset($_POST['resultDetails'])) {
			if (isset($_POST['resultDetails']['CardholderInitiatedTransactionID'])) {
				$InitiatedTransactionID = $_POST['resultDetails']['CardholderInitiatedTransactionID'];
			}
		}else if(isset($_POST['standingInstruction'])){
			if(isset($_POST['standingInstruction']['initialTransactionId'])){
				$InitiatedTransactionID = $_POST['standingInstruction']['initialTransactionId'];
			}
		}
		//update_post_meta($order->get_id(), 'payment_initial_id', $InitiatedTransactionID);
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
				$order->add_order_note( 'Peach Payment Successfull.',0,false);
			}
			
			// Save USer Card if applicable
			if ( PP_Gateway_Settings::get('card_storage') === 'yes' && $order->get_user_id() > 0 ) {
				$user_id = $order->get_user_id();
				
				if($registration_id){
					$user_tokens = self::get_user_card_tokens( $user_id );
					
					if(empty($user_tokens) || !in_array($registration_id,$user_tokens)){
						$card_data = [
							'id'        => $registration_id,
							'num'       => 'xxxx-'.$response['card_last4Digits'],
							'holder'    => $response['card_holder'],
							'brand'     => $response['paymentBrand'],
							'exp_month' => $response['card_expiryMonth'],
							'exp_year'  => $response['card_expiryYear'],
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

		} else {
			// Failure case
			$error_msg = isset( $response['result']['description'] ) ? $response['result']['description'] : __( 'Peach payment failed.', WC_PEACH_TEXT_DOMAIN );
			$order->update_status( 'failed', sprintf( __( 'Peach Payments failure: %s (code: %s)', WC_PEACH_TEXT_DOMAIN ), $error_msg, $status_code ) );
			$order->add_order_note( 'Peach Payment Failed with code ['.$status_code.'].',0,false);
		}

		// Save changes
		$order->save();
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
	 * Determines whether a Peach result code represents success.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function order_status_checks($order) {
		$status = $status_check = $order->get_status();
		
		$status_setting = PP_Gateway_Settings::get('order_status');
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
		
		foreach ( $user_cards as $index => $card ) {
			$saved_cards[] = $card['id'];
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
