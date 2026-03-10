<?php
defined( 'ABSPATH' ) || exit;

class PP_Gateway_Webhook_Handler {

	/**
	 * Register the webhook listener
	 * @wc_switch_webhook_peach_payments
	 */
	public static function init() {
		add_action( 'woocommerce_api_wc_switch_webhook_peach_payments', [ __CLASS__, 'handle_switch_webhook' ] );
		
		//Depreicated
		add_action( 'woocommerce_api_wc_switch_peach_payments', [ __CLASS__, 'handle_payments_webhook' ] );
		
		//Depreicated
		add_action( 'woocommerce_api_wc_payon_webhook_peach_payments', [ __CLASS__, 'handle_payon_webhook' ] ); //Refund or Recurring Subscription
	}
	
	/**
	 * Handle incoming Payments webhook from Peach Payments
	 */
	public static function handle_payments_webhook() {
		$webhook_method = 'json';
		$raw_body = file_get_contents( 'php://input' );
		$data = json_decode( $raw_body, true );
		
		if ( empty( $data ) && ! empty( $_POST ) ) {
			$data = $_POST;
			$webhook_method = 'post';
		}else{
			$data = self::decode_data($data);
		}
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}
		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payments_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg'].". ".print_r($data, true) );
			status_header( 200 );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'info'){
			PP_Gateway_Logger::info( "Handled 'handle_payments_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payments_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}
		
		status_header( 200 );
		echo $response['log_txt'];
		exit;
	}
	
	/**
	 * Handle incoming Payon webhook from Peach Payments
	 */
	public static function handle_payon_webhook() {
		$webhook_method = 'json';
		$raw_body = file_get_contents( 'php://input' );
		$data = json_decode( $raw_body, true );
		
		if ( empty( $data ) && ! empty( $_POST ) ) {
			$data = $_POST;
			$webhook_method = 'post';
		}else{
			$data = self::decode_data($data);
		}
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}
		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payon_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg'].". ".print_r($data, true) );
			status_header( 200 );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'info'){
			PP_Gateway_Logger::info( "Handled 'handle_payon_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payon_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}
		
		status_header( 200 );
		echo $response['log_txt'];
		exit;
	}
	
	/**
	 * Handle incoming Switch webhook from Peach Payments
	 */
	public static function handle_switch_webhook() {
		$webhook_method = 'json';
		$raw_body = file_get_contents( 'php://input' );
		$data = json_decode( $raw_body, true );
		
		if ( empty( $data ) && ! empty( $_POST ) ) {
			$data = $_POST;
			$webhook_method = 'post';
		}else{
			$data = self::decode_data($data);
		}
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}
		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_switch_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg'].". ".print_r($data, true) );
			status_header( 200 );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'info'){
			PP_Gateway_Logger::info( "Handled 'handle_switch_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_switch_webhook' Webhook [".$webhook_method."] — ".$response['log_msg'].". ".print_r($data, true) );
		}
		
		status_header( 200 );
		echo $response['log_txt'];
		exit;
	}

	/**
	 * Handle incoming webhook from Peach Payments
	 */
	public static function handle_webhook($data) {
		
		if(isset($data['type'])){
			$merchantTransactionId = $data['payload']['merchantTransactionId'];
			$result_code = $data['payload']['result']['code'];
			$payment_order_id = $data['payload']['id'];
			$registrationId = $data['payload']['registrationId'];
		}else{
			$merchantTransactionId = $data['merchantTransactionId'];
			$result_code = $data['result_code'];
			$payment_order_id = $data['id'];
			$registrationId = $data['registrationId'];
		}

		if ( empty( $merchantTransactionId ) || empty( $result_code ) ) {
			return ['log_type' => 'error', 'log_msg' => 'invalid webhook payload', 'log_txt' => 'Invalid webhook payload'];
		}

		$merchantTransactionId = sanitize_text_field( $merchantTransactionId );
		
		if (strpos($merchantTransactionId, "peach-card") !== false) {
			return ['log_type' => 'info', 'log_msg' => 'peach-card', 'log_txt' => 'User adding card'];
		}
		
		$order_number = PP_Gateway_Order_Utils::order_number_prep( $merchantTransactionId, true );
		
		$meta = PP_Gateway_Order_Utils::find_sequential_plugins();
		
		$order_id = $order_number;
		if($meta){
			$order_id = self::get_order_id_by_order_number( $order_number, $meta );
		}
		
		$order = wc_get_order( $order_id );
		
		//PP_Peach_API::log_error( 'API Webhook Order', '', $order, '' );

		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			return ['log_type' => 'error', 'log_msg' => 'order #'.$order_number.' not found', 'log_txt' => 'Order not found'];
		}
		
		//Ignore status code 100.396.104
		if ( $result_code && $result_code == '100.396.104' ) {
			$order->add_order_note( 'Peach Payment Webhook Code 100.396.104 received.',0,false);
			return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' received webhook code 100.396.104', 'log_txt' => 'Stop processing of Webhook Handler'];
		}

		// Prevent duplicate processing
		if ( $order->get_meta( 'peach_webhook_handled' ) ) {
			return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' already handled', 'log_txt' => 'Already handled'];
		}

		// Save metadata
		if ( ! empty( $payment_order_id ) ) {
			$order->update_meta_data( 'payment_order_id', $payment_order_id );
		}
		if ( ! empty( $registrationId ) ) {
			$order->update_meta_data( 'payment_registration_id', $registrationId );
		}
		
		if(PP_Gateway_Order_Utils::is_successful_result_code($result_code)){
			$settings      = get_option( 'woocommerce_peach-payments_settings', [] );
			$custom_status = isset( $settings['peach_order_status'] ) ? $settings['peach_order_status'] : 'processing';

			// Complete order (if not already marked)
			if ( PP_Gateway_Order_Utils::order_status_checks($order)) {
				if($payment_order_id){
					$order->payment_complete( $payment_order_id );
				}
				$order->update_status( $custom_status, __( 'Payment completed via Peach Payments Webhook.', WC_PEACH_TEXT_DOMAIN ) );
				$order->add_order_note( 'Peach Payment Successfull. Webhook.',0,false);
			}
			
			$order->update_meta_data( 'peach_webhook_handled', true );
			$order->save();
			return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' successfully handled', 'log_txt' => 'Webhook handled'];
		}else{
			return ['log_type' => 'error', 'log_msg' => 'order #'.$order_number.' failed', 'log_txt' => 'Not successfull status'];
		}
		
		//If all else fails
		return ['log_type' => 'error', 'log_msg' => 'unknown error occurred', 'log_txt' => 'Unknown Error'];
	}
	
	public static function get_order_id_by_order_number( $order_number, $meta ) {
		if ( ! $order_number ) {
			return false;
		}
	
		$query = new WP_Query( [
			'post_type'  => 'shop_order',
			'post_status'=> 'any',
			'meta_query' => [
				[
					'key'   => $meta,
					'value' => $order_number,
				]
			],
			'fields'     => 'ids',
			'posts_per_page' => 1,
		] );
	
		return ! empty( $query->posts ) ? $query->posts[0] : false;
	}
	
	public static function decode_data($data){
		
		$web_hook_key = PP_Gateway_Settings::get('card_webhook_key');
		
		if (empty($web_hook_key)) {
			return ['log_type' => 'error', 'log_msg' => 'missing Card Webhook Decryption key', 'log_txt' => 'Missing Card Webhook Decryption key'];
		}
		
		$headerVector = $_SERVER['HTTP_X_INITIALIZATION_VECTOR'] ?? '';
		$headerTag    = $_SERVER['HTTP_X_AUTHENTICATION_TAG'] ?? '';
		
		if (empty($headerVector) || empty($headerTag) || empty($data['encryptedBody'])) {
			return ['log_type' => 'error', 'log_msg' => 'missing required data', 'log_txt' => 'Invalid webhook request'];
		}
		
		$key         = hex2bin($web_hook_key);
		$iv          = hex2bin($headerVector);
		$auth_tag    = hex2bin($headerTag);
		$cipher_text = hex2bin($data['encryptedBody']);
		
		$result = openssl_decrypt(
			$cipher_text,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$auth_tag
		);
		
		if ($result === false) {
			return ['log_type' => 'error', 'log_msg' => 'webhook decryption failed: OpenSSL error', 'log_txt' => 'Decryption failed'];
		}
		
		return json_decode($result, true);

	}

}
