<?php
/**
 * Handles AJAX requests for adding a new card.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Token_Add_Handler {
	
	public static function register() {
		add_action( 'wp_ajax_pp_get_registration_id', [ __CLASS__, 'handle_get_registration_id' ] );
	
		// Existing handlers...
		add_action( 'wp_ajax_pp_save_new_card', [ __CLASS__, 'handle_add_card' ] );
		add_action( 'wp_ajax_pp_delete_saved_card', [ __CLASS__, 'handle_delete_card' ] );
		add_action( 'template_redirect', [ 'PP_Gateway_Token_Add_Handler', 'maybe_handle_resource_path' ] );
	}


	/**
	 * Handles the AJAX request to add a new card.
	 */
	public static function handle_add_card() {
		check_ajax_referer( 'pp_add_card_nonce', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized request.', WC_PEACH_TEXT_DOMAIN ) ] );
		}
		
		PP_Gateway_Logger::error( "Add card POST. ".print_r($_POST, true) );

		$user_id = get_current_user_id();

		$card_number = sanitize_text_field( $_POST['card_number'] ?? '' );
		$exp_month   = sanitize_text_field( $_POST['exp_month'] ?? '' );
		$exp_year    = sanitize_text_field( $_POST['exp_year'] ?? '' );
		$cvv         = sanitize_text_field( $_POST['cvv'] ?? '' );
		$holder      = sanitize_text_field( $_POST['card_holder'] ?? '' );

		if ( empty( $card_number ) || empty( $exp_month ) || empty( $exp_year ) || empty( $cvv ) || empty( $holder ) ) {
			wp_send_json_error( [ 'message' => __( 'All fields are required.', WC_PEACH_TEXT_DOMAIN ) ] );
		}

		$api = new PP_Peach_API();

		$response = $api->create_token( [
			'card_number' => $card_number,
			'expiry_month' => $exp_month,
			'expiry_year' => $exp_year,
			'cvv' => $cvv,
			'holder' => $holder,
			'brand' => 'VISA'
		] );

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			wp_send_json_error( [ 'message' => $error_message ] );
		}

		$registration_id = $response['id'] ?? null;
		$masked_number   = $response['masked'] ?? 'xxxx-xxxx';
		$brand           = $response['brand'] ?? 'Card';

		if ( ! $registration_id ) {
			wp_send_json_error( [ 'message' => __( 'Failed to store card. No token returned.', WC_PEACH_TEXT_DOMAIN ) ] );
		}

		// Save card to user meta
		$card_data = [
			'id'        => $registration_id,
			'num'       => $masked_number,
			'holder'    => $holder,
			'brand'     => $brand,
			'exp_month' => $exp_month,
			'exp_year'  => $exp_year,
		];

		$cards = get_user_meta( $user_id, 'my-cards', true );
		if ( ! is_array( $cards ) ) {
			$cards = [];
		}

		$cards[] = $card_data;
		update_user_meta( $user_id, 'my-cards', $cards );

		wp_send_json_success( [ 'message' => __( 'Card added successfully.', WC_PEACH_TEXT_DOMAIN ) ] );
	}
	
	public static function handle_get_registration_id() {
		if ( ! is_user_logged_in() || ! check_ajax_referer( 'pp_add_card_nonce', 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 401 );
		}
		
		$user_id = get_current_user_id();
		$user_info = get_userdata( $user_id );
		
		$transaction_mode = PP_Gateway_Settings::get('transaction_mode');
	
		// Generate access token
		$token_response = WC_Gateway_Peach_Hosted::generate_access_token();
		if ( empty( $token_response['access_token'] ) ) {
			PP_Peach_API::log_error( 'Token Response', $token_response['body'], $token_response['raw'], $token_response['url'] );
			wc_add_notice( __( 'Unable to connect to Peach Payments. Please try again.', 'woocommerce-gateway-peach-payments' ), 'error' );
			wp_send_json_error( [ 'message' => 'Error in generating Token for request to add card.', 'response' => $body ], 400 );
		}
		$access_token = $token_response['access_token'];
		
		// Dummy Order Details
		$currency = get_woocommerce_currency();
		$currency = ! empty( $currency ) ? strtoupper( $currency ) : 'ZAR';
		// Some currencies (e.g. MUR) do not support 0.00 preauthorisations.
		$total = ( 'MUR' === $currency ) ? 1.00 : 0.00;

		$order_key = '';
		$order_number = 'peach-card-'.get_current_user_id().'-'.time();
		
		$nonce = wp_create_nonce( $order_number );
		
		$entity_id = strval(PP_Gateway_Settings::get('channel_3ds'));
		
		// Get billing details
		$billing_first_name = get_user_meta( $user_id, 'billing_first_name', true );
		$billing_last_name  = get_user_meta( $user_id, 'billing_last_name', true );
		$billing_email      = get_user_meta( $user_id, 'billing_email', true );
	
		// Fallbacks to WordPress account details if billing fields are empty
		if ( empty( $billing_first_name ) && ! empty( $user_info->first_name ) ) {
			$billing_first_name = $user_info->first_name;
		}
		if ( empty( $billing_last_name ) && ! empty( $user_info->last_name ) ) {
			$billing_last_name = $user_info->last_name;
		}
		if ( empty( $billing_email ) && ! empty( $user_info->user_email ) ) {
			$billing_email = $user_info->user_email;
		}
		
		$billing_address = get_user_meta( $user_id, 'billing_address_1', true );
		
		//New 3D Secure Rule. Address can't exceed 50 chars
		if(!empty( $billing_address )){
			$billing_address = substr($billing_address,0,50);
			$billing_address = str_replace('&', ' ',$billing_address);
			$billing_address = str_replace('.', '',$billing_address);
		}
		
		$customer = [];
		if(!empty( $billing_email )){
			$customer['email'] = $billing_email;
		}else{
			PP_Peach_API::log_error( 'Adding Card Request', '', 'Missing customer email address.', '' );
			wc_add_notice( __( 'Missing account Email Address. Please update your details in the Account section and try again.', 'woocommerce-gateway-peach-payments' ), 'error' );
			wp_send_json_error( [ 'message' => 'Missing customer email address.', 'response' => $body ], 400 );
		}
		
		if(!empty( $billing_first_name )){
			$customer['givenName'] = str_replace(' ', '', $billing_first_name);
		}else{
			PP_Peach_API::log_error( 'Adding Card Request', '', 'Missing customer first name.', '' );
			wc_add_notice( __( 'Missing account First Name. Please update your details in the Account section and try again.', 'woocommerce-gateway-peach-payments' ), 'error' );
			wp_send_json_error( [ 'message' => 'Missing customer first name.', 'response' => $body ], 400 );
		}
		
		if(!empty( $billing_last_name )){
			$customer['surname'] = str_replace(' ', '', $billing_last_name);
		}else{
			PP_Peach_API::log_error( 'Adding Card Request', '', 'Missing customer surname.', '' );
			wc_add_notice( __( 'Missing account Surname. Please update your details in the Account section and try again.', 'woocommerce-gateway-peach-payments' ), 'error' );
			wp_send_json_error( [ 'message' => 'Missing customer surname.', 'response' => $body ], 400 );
		}
		
		$billing = [];
		$billing_city      = get_user_meta( $user_id, 'billing_city', true );
		$billing_postcode  = get_user_meta( $user_id, 'billing_postcode', true );
		$billing_country   = get_user_meta( $user_id, 'billing_country', true );
		
		if ( ! empty( $billing_address ) 
		 && ! empty( $billing_postcode ) 
		 && ! empty( $billing_country ) 
		 && ! empty( $billing_city ) ) {
				 
			$billing['city'] = $billing_city;
			$billing['country'] = $billing_country;
			$billing['postcode'] = $billing_postcode ;
			$billing['street1'] = $billing_address;
		}
		
		//$result_url = wc_get_account_endpoint_url( 'my-cards' );
		$result_url = add_query_arg( 'pp_add_card_return', '1', home_url( '/' ) );
		
		// Prepare payload
		$payload = [
			'authentication.entityId' => $entity_id,
			'merchantTransactionId' => $order_number,
			'amount' => number_format( $total, 2, '.', '' ),
			'currency' => $currency,
			'nonce' => $nonce,
			'shopperResultUrl' => $result_url,
			'cancelUrl' => $result_url,
			'merchantInvoiceId' => $order_number,
			'paymentType' => 'PA',
			//'customer' => [$customer],
			'customParameters' => [
				'PHP_VERSION' => WC_PEACH_PHP,
				'WORDPRESS_VERSION' => WC_PEACH_WP_VER,
				'WOOCOMMERCE_VERSION' => WC_PEACH_WC_VER,
				'WOO_SUBSCRIPTION_VERSION' => WC_PEACH_WC_SUB_VER,
				'PEACH_PLUGIN_VERSION' => WC_PEACH_VER,
				'INTEGRATION_METHOD' => 'Hosted',
				'PAYMENT_PLUGIN' => 'woocommerce',
				'WOOCOMMERCE_USER' => strval($user_id)
			]
		];
		
		if(!empty( $billing )){
			$payload['billing'] = $billing;
		}
		
		$payload['defaultPaymentMethod'] = 'CARD';
		$payload['forceDefaultMethod'] = true;
		$payload['createRegistration'] = true;
		//Reqyested by Peach
		$payload['standingInstruction'] = [
			"type" => "UNSCHEDULED",
			"mode" => "INITIAL"
		];
		
		$response = WC_Gateway_Peach_Hosted::create_checkout_session( $access_token, $payload );
		
		//PP_Gateway_Logger::info( "Add Card Checkout Payload. ".print_r($payload, true) );
		//PP_Gateway_Logger::info( "Add Card Checkout Session. ".print_r($response, true) );
		
		if ( empty( $response['redirectUrl'] ) ) {
			PP_Peach_API::log_error( 'Redirect URL', $payload, $response, '' );
			wp_send_json_error( [ 'message' => 'Registration ID not received.', 'response' => $body ], 400 );
		}else{
			wp_send_json_success( [ 'redirectUrl' => $response['redirectUrl'], "mode" => $transaction_mode ] );
		}
	}
	
	public static function maybe_handle_resource_path() {
		if ( ! is_user_logged_in() || empty( $_GET['resourcePath'] ) || empty( $_GET['pp_add_card_return'] ) ) {
			return;
		}
	
		$resource_path = sanitize_text_field( wp_unslash( $_GET['resourcePath'] ) );
		$user_id       = get_current_user_id();
	
		$response = PP_Peach_API::get_registration_result( $resource_path );
	
		if ( is_wp_error( $response ) ) {
			wc_add_notice( __( 'Failed to retrieve card registration.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}
	
		if ( isset( $response['card'] ) ) {
			// Save card data to user_meta
			PP_Gateway_Card_Manager::save_card( $user_id, [
				'id'        => sanitize_text_field( $response['id'] ),
				'num'       => 'xxxx-' . $response['card']['last4Digits'],
				'holder'    => sanitize_text_field( $response['card']['holder'] ),
				'brand'     => sanitize_text_field( $response['paymentBrand'] ),
				'exp_year'  => sanitize_text_field( $response['card']['expiryYear'] ),
				'exp_month' => sanitize_text_field( $response['card']['expiryMonth'] ),
			] );
	
			wc_add_notice( __( 'Card saved successfully.', WC_PEACH_TEXT_DOMAIN ), 'success' );

			// If the add-card flow used a MUR preauth (1.00), reverse it after a successful registration.
			$tx_currency = strtoupper( $response['currency'] ?? ( $response['payment']['currency'] ?? '' ) );
			if ( 'MUR' === $tx_currency && ! empty( $response['id'] ) ) {
				$rv = PP_Peach_API::reverse_preauthorisation( sanitize_text_field( $response['id'] ) );
				if ( is_wp_error( $rv ) ) {
					PP_Gateway_Logger::error( 'Card add reversal failed. Transaction ID: ' . sanitize_text_field( $response['id'] ) . ' | Error: ' . $rv->get_error_message() );
				} else {
					$rv_result_code = $rv['result']['code'] ?? '';
					$rv_result_desc = $rv['result']['description'] ?? '';
					if ( ! empty( $rv_result_code ) && 0 === strpos( $rv_result_code, '000.' ) ) {
						PP_Gateway_Logger::info( 'Card add reversal successful. Transaction ID: ' . sanitize_text_field( $response['id'] ) . ' | Result: ' . $rv_result_code . ( $rv_result_desc ? ' - ' . $rv_result_desc : '' ) );
					} else {
						PP_Gateway_Logger::warning( 'Card add reversal not successful. Transaction ID: ' . sanitize_text_field( $response['id'] ) . ' | Result: ' . ( $rv_result_code ?: 'N/A' ) . ( $rv_result_desc ? ' - ' . $rv_result_desc : '' ) );
					}
				}}

		} else {
			wc_add_notice( __( 'Card registration failed.', WC_PEACH_TEXT_DOMAIN ), 'error' );
		}
	
		wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
		exit;
	}


}
