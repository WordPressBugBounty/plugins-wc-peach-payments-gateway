<?php
/**
 * Handles AJAX requests for adding a new card.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Token_Add_Handler {

	/**
	 * User meta key used to keep short-lived add-card Checkout V2 sessions that
	 * still need to be reconciled after Peach redirects/webhooks complete.
	 */
	const PENDING_CARD_CHECKOUTS_META_KEY = '_peach_pending_card_checkouts';
	
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
		// Peach can normalise add-card merchant references in webhook/status payloads.
		// Keep this reference short and alphanumeric so it survives that round trip unchanged.
		$order_number = 'peachcard' . strtolower( wp_generate_password( 7, false, false ) );
		
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
		$card_return_token = wp_generate_password( 32, false );
		set_transient(
			self::get_card_return_transient_key( $user_id, $card_return_token ),
			[
				'user_id' => $user_id,
				'merchant_transaction_id' => $order_number,
				'amount' => number_format( (float) $total, 2, '.', '' ),
				'currency' => $currency,
				'return_token' => $card_return_token,
				'created_at' => time(),
			],
			HOUR_IN_SECONDS
		);
		$result_url = add_query_arg(
			[
				'pp_add_card_return' => '1',
				'pp_card_token' => $card_return_token,
			],
			home_url( '/' )
		);
		
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

		PP_Gateway_Logger::info( 'Peach add-card Checkout V2 session request: ' . print_r( $payload, true ) );
		PP_Gateway_Logger::info( 'Peach add-card Checkout V2 session response: ' . print_r( $response, true ) );
		
		if ( empty( $response['redirectUrl'] ) ) {
			delete_transient( self::get_card_return_transient_key( $user_id, $card_return_token ) );
			PP_Peach_API::log_error( 'Redirect URL', $payload, $response, '' );
			wp_send_json_error( [ 'message' => 'Registration ID not received.', 'response' => $body ], 400 );
		}else{
			$transient_key = self::get_card_return_transient_key( $user_id, $card_return_token );
			$expected      = get_transient( $transient_key );
			$checkout_id   = self::get_checkout_id_from_session_response( $response );

			if ( is_array( $expected ) && '' !== $checkout_id ) {
				$expected['checkout_id'] = $checkout_id;
				set_transient( $transient_key, $expected, HOUR_IN_SECONDS );
				self::store_pending_card_checkout( $user_id, $expected );
			} elseif ( is_array( $expected ) ) {
				self::store_pending_card_checkout( $user_id, $expected );
				PP_Gateway_Logger::warning( 'Peach add-card Checkout V2 session response did not include a checkoutId for return verification. Response: ' . print_r( $response, true ) );
			}

			wp_send_json_success( [ 'redirectUrl' => $response['redirectUrl'], "mode" => $transaction_mode ] );
		}
	}
	
	private static function get_card_return_transient_key( $user_id, $token ) {
		return 'peach_card_return_' . absint( $user_id ) . '_' . md5( (string) $token );
	}

	/**
	 * Extract the Checkout V2 checkout ID from a Peach session response.
	 *
	 * Peach normally returns checkoutId, but this also supports older/variant
	 * shapes and falls back to the checkoutId query arg inside redirectUrl.
	 *
	 * @param array $response Peach checkout session response.
	 * @return string
	 */
	private static function get_checkout_id_from_session_response( array $response ) {
		$candidates = [
			$response['checkoutId'] ?? '',
			$response['checkout_id'] ?? '',
			$response['id'] ?? '',
			$response['checkout']['id'] ?? '',
			$response['checkout']['checkoutId'] ?? '',
		];

		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}

		if ( ! empty( $response['redirectUrl'] ) ) {
			$parts = wp_parse_url( (string) $response['redirectUrl'] );
			if ( ! empty( $parts['query'] ) ) {
				parse_str( $parts['query'], $query_args );
				if ( ! empty( $query_args['checkoutId'] ) ) {
					return sanitize_text_field( (string) $query_args['checkoutId'] );
				}
			}
		}

		return '';
	}

	/**
	 * Return a sanitized value from GET/POST for Peach return handling.
	 *
	 * @param string $key Request key.
	 * @return string
	 */
	private static function get_return_request_value( $key ) {
		if ( isset( $_GET[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
		}

		if ( isset( $_POST[ $key ] ) ) {
			return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		}

		return '';
	}

	/**
	 * Return the first non-empty value found in a nested Peach response.
	 *
	 * @param array $response Response data.
	 * @param array $paths    List of string keys or nested key arrays.
	 * @return mixed|null
	 */
	private static function get_first_response_value( array $response, array $paths ) {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && isset( $response[ $path ] ) && '' !== $response[ $path ] && null !== $response[ $path ] ) {
				return $response[ $path ];
			}

			if ( is_array( $path ) ) {
				$value = $response;
				$found = true;

				foreach ( $path as $key ) {
					if ( ! is_array( $value ) || ! array_key_exists( $key, $value ) ) {
						$found = false;
						break;
					}

					$value = $value[ $key ];
				}

				if ( $found && '' !== $value && null !== $value ) {
					return $value;
				}
			}
		}

		return null;
	}

	/**
	 * Normalise a verified Checkout V2 status response into the legacy add-card result shape.
	 *
	 * @param array $status_result  Server-to-server Checkout V2 status response.
	 * @param array $return_payload Posted hosted-return payload. Used only to fill non-authoritative display fields.
	 * @return array|WP_Error
	 */
	private static function normalise_checkout_v2_status_result_for_card_add( array $status_result, array $return_payload = [] ) {
		$result = $status_result;

		$status_result_code = self::get_first_response_value(
			$status_result,
			[
				[ 'result', 'code' ],
				'result_code',
				'result.code',
				[ 'payment', 'result', 'code' ],
				[ 'payload', 'result', 'code' ],
				[ 'payload', 'payment', 'result', 'code' ],
			]
		);

		$status            = self::get_first_response_value( $status_result, [ 'status', [ 'checkout', 'status' ], [ 'payment', 'status' ], [ 'payload', 'status' ], [ 'payload', 'payment', 'status' ] ] );
		$status_normalised = strtolower( trim( sanitize_text_field( (string) $status ) ) );
		$status_successful = in_array( $status_normalised, [ 'successful', 'success', 'succeeded', 'completed', 'complete', 'paid' ], true );

		if ( '' !== (string) $status_result_code ) {
			$result_code = sanitize_text_field( (string) $status_result_code );
		} elseif ( $status_successful ) {
			$result_code = '000.000.000';
		} else {
			return new WP_Error( 'peach_missing_result_code', __( 'Missing Peach Payments result code.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
			$result['result'] = [];
		}
		$result['result']['code'] = $result_code;

		$fields = [
			'checkoutId'            => [ 'checkoutId', 'checkout_id', [ 'checkout', 'id' ], [ 'checkout', 'checkoutId' ], [ 'payload', 'checkoutId' ] ],
			'merchantTransactionId' => [ 'merchantTransactionId', 'merchant_transaction_id', [ 'checkout', 'merchantTransactionId' ], [ 'payment', 'merchantTransactionId' ], [ 'payload', 'merchantTransactionId' ], [ 'payload', 'payment', 'merchantTransactionId' ] ],
			'merchantInvoiceId'     => [ 'merchantInvoiceId', 'merchant_invoice_id', [ 'checkout', 'merchantInvoiceId' ], [ 'payment', 'merchantInvoiceId' ], [ 'payload', 'merchantInvoiceId' ], [ 'payload', 'payment', 'merchantInvoiceId' ] ],
			'amount'                => [ 'amount', [ 'checkout', 'amount' ], [ 'payment', 'amount' ], [ 'payload', 'amount' ], [ 'payload', 'payment', 'amount' ] ],
			'currency'              => [ 'currency', [ 'checkout', 'currency' ], [ 'payment', 'currency' ], [ 'payload', 'currency' ], [ 'payload', 'payment', 'currency' ] ],
			'id'                    => [ 'paymentId', 'payment_id', [ 'payment', 'id' ], [ 'payload', 'paymentId' ], [ 'payload', 'payment', 'id' ], [ 'transaction', 'id' ] ],
			'registrationId'        => [ 'registrationId', 'registration_id', [ 'registration', 'id' ], [ 'payment', 'registrationId' ], [ 'payment', 'registration', 'id' ], [ 'payload', 'registrationId' ], [ 'payload', 'registration', 'id' ], [ 'payload', 'payment', 'registrationId' ], [ 'payload', 'payment', 'registration', 'id' ] ],
			'paymentBrand'          => [ 'paymentBrand', 'payment_brand', [ 'payment', 'paymentBrand' ], [ 'payload', 'paymentBrand' ], [ 'payload', 'payment', 'paymentBrand' ] ],
			'card_last4Digits'      => [ 'card_last4Digits', 'card.last4Digits', [ 'card', 'last4Digits' ], [ 'payment', 'card', 'last4Digits' ], [ 'payload', 'card', 'last4Digits' ], [ 'payload', 'payment', 'card', 'last4Digits' ] ],
			'card_holder'           => [ 'card_holder', 'card.holder', [ 'card', 'holder' ], [ 'payment', 'card', 'holder' ], [ 'payload', 'card', 'holder' ], [ 'payload', 'payment', 'card', 'holder' ] ],
			'card_expiryMonth'      => [ 'card_expiryMonth', 'card.expiryMonth', [ 'card', 'expiryMonth' ], [ 'payment', 'card', 'expiryMonth' ], [ 'payload', 'card', 'expiryMonth' ], [ 'payload', 'payment', 'card', 'expiryMonth' ] ],
			'card_expiryYear'       => [ 'card_expiryYear', 'card.expiryYear', [ 'card', 'expiryYear' ], [ 'payment', 'card', 'expiryYear' ], [ 'payload', 'card', 'expiryYear' ], [ 'payload', 'payment', 'card', 'expiryYear' ] ],
		];

		foreach ( $fields as $target_key => $paths ) {
			$value = self::get_first_response_value( $status_result, $paths );
			if ( ( null === $value || '' === $value ) && is_array( $return_payload ) ) {
				$value = self::get_first_response_value( $return_payload, $paths );
			}

			if ( null !== $value && '' !== $value && ( ! isset( $result[ $target_key ] ) || '' === $result[ $target_key ] || null === $result[ $target_key ] ) ) {
				$result[ $target_key ] = $value;
			}
		}

		if ( ! isset( $result['card'] ) || ! is_array( $result['card'] ) ) {
			$result['card'] = [];
		}

		if ( empty( $result['card']['last4Digits'] ) && ! empty( $result['card_last4Digits'] ) ) {
			$result['card']['last4Digits'] = $result['card_last4Digits'];
		}
		if ( empty( $result['card']['holder'] ) && ! empty( $result['card_holder'] ) ) {
			$result['card']['holder'] = $result['card_holder'];
		}
		if ( empty( $result['card']['expiryMonth'] ) && ! empty( $result['card_expiryMonth'] ) ) {
			$result['card']['expiryMonth'] = $result['card_expiryMonth'];
		}
		if ( empty( $result['card']['expiryYear'] ) && ! empty( $result['card_expiryYear'] ) ) {
			$result['card']['expiryYear'] = $result['card_expiryYear'];
		}

		return $result;
	}

	/**
	 * Transient key used to map a Peach add-card merchant reference back to the
	 * WordPress user/session. This lets the webhook and My Cards page reconcile
	 * a successful registration even when Peach does not return through the
	 * browser URL with the original pp_card_token query string intact.
	 *
	 * @param string $merchant_reference Peach merchantTransactionId/merchantInvoiceId.
	 * @return string
	 */
	private static function get_card_merchant_transient_key( $merchant_reference ) {
		return 'peach_card_merchant_' . md5( sanitize_text_field( (string) $merchant_reference ) );
	}

	/**
	 * Store a short-lived pending add-card checkout for later reconciliation.
	 *
	 * @param int   $user_id  WordPress user ID.
	 * @param array $expected Expected checkout metadata.
	 * @return void
	 */
	private static function store_pending_card_checkout( $user_id, array $expected ) {
		$user_id = absint( $user_id );
		if ( ! $user_id || empty( $expected['merchant_transaction_id'] ) ) {
			return;
		}

		$expected['user_id']    = $user_id;
		$expected['created_at'] = ! empty( $expected['created_at'] ) ? absint( $expected['created_at'] ) : time();
		$expected['updated_at'] = time();

		$checkout_id = ! empty( $expected['checkout_id'] ) ? sanitize_text_field( (string) $expected['checkout_id'] ) : '';
		$merchant_id = sanitize_text_field( (string) $expected['merchant_transaction_id'] );
		$pending_key = '' !== $checkout_id ? $checkout_id : $merchant_id;

		$pending = get_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY, true );
		if ( ! is_array( $pending ) ) {
			$pending = [];
		}

		$pending[ $pending_key ] = $expected;
		update_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY, $pending );

		set_transient( self::get_card_merchant_transient_key( $merchant_id ), $expected, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Remove a pending add-card checkout after it has been saved or rejected.
	 *
	 * @param int   $user_id  WordPress user ID.
	 * @param array $expected Expected checkout metadata.
	 * @return void
	 */
	private static function remove_pending_card_checkout( $user_id, array $expected ) {
		$user_id      = absint( $user_id );
		$checkout_id  = ! empty( $expected['checkout_id'] ) ? sanitize_text_field( (string) $expected['checkout_id'] ) : '';
		$merchant_id  = ! empty( $expected['merchant_transaction_id'] ) ? sanitize_text_field( (string) $expected['merchant_transaction_id'] ) : '';
		$return_token = ! empty( $expected['return_token'] ) ? sanitize_text_field( (string) $expected['return_token'] ) : '';

		if ( $user_id ) {
			$pending = get_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY, true );
			if ( is_array( $pending ) ) {
				foreach ( $pending as $key => $session ) {
					$session_checkout = ! empty( $session['checkout_id'] ) ? sanitize_text_field( (string) $session['checkout_id'] ) : '';
					$session_merchant = ! empty( $session['merchant_transaction_id'] ) ? sanitize_text_field( (string) $session['merchant_transaction_id'] ) : '';

					if ( ( '' !== $checkout_id && $checkout_id === $session_checkout ) || ( '' !== $merchant_id && $merchant_id === $session_merchant ) ) {
						unset( $pending[ $key ] );
					}
				}

				if ( empty( $pending ) ) {
					delete_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY );
				} else {
					update_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY, $pending );
				}
			}
		}

		if ( '' !== $merchant_id ) {
			delete_transient( self::get_card_merchant_transient_key( $merchant_id ) );
		}

		if ( $user_id && '' !== $return_token ) {
			delete_transient( self::get_card_return_transient_key( $user_id, $return_token ) );
		}
	}

	/**
	 * Extract a verified add-card response into the plugin's saved-card structure.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param array  $response Verified Peach response/status payload.
	 * @param array  $expected Expected checkout metadata.
	 * @param string $source   Source used for logging.
	 * @return true|WP_Error
	 */
	private static function save_card_from_verified_response( $user_id, array $response, array $expected, $source = 'status' ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return new WP_Error( 'peach_missing_user_id', __( 'Missing user for card registration.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$result_code = self::get_first_response_value(
			$response,
			[
				[ 'result', 'code' ],
				'result_code',
				'result.code',
				[ 'payload', 'result', 'code' ],
				[ 'payload', 'result_code' ],
				[ 'payload', 'payment', 'result', 'code' ],
			]
		);

		if ( '' === (string) $result_code || ! PP_Gateway_Order_Utils::is_successful_result_code( sanitize_text_field( (string) $result_code ) ) ) {
			return new WP_Error( 'peach_card_registration_not_successful', __( 'Card registration was not successful.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$received_reference = self::get_first_response_value(
			$response,
			[
				'merchantTransactionId',
				'merchant_transaction_id',
				[ 'checkout', 'merchantTransactionId' ],
				[ 'payment', 'merchantTransactionId' ],
				[ 'payload', 'merchantTransactionId' ],
				[ 'payload', 'payment', 'merchantTransactionId' ],
				'merchantInvoiceId',
				[ 'payload', 'merchantInvoiceId' ],
			]
		);

		if ( '' !== (string) $received_reference && ! PP_Peach_API::merchant_references_match( (string) $expected['merchant_transaction_id'], (string) $received_reference ) ) {
			return new WP_Error( 'peach_card_reference_mismatch', __( 'Card registration reference mismatch.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$amount = self::get_first_response_value( $response, [ 'amount', [ 'checkout', 'amount' ], [ 'payment', 'amount' ], [ 'payload', 'amount' ], [ 'payload', 'payment', 'amount' ] ] );
		if ( null !== $amount && '' !== $amount && isset( $expected['amount'] ) && number_format( (float) $amount, 2, '.', '' ) !== number_format( (float) $expected['amount'], 2, '.', '' ) ) {
			return new WP_Error( 'peach_card_amount_mismatch', __( 'Card registration amount mismatch.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$currency = self::get_first_response_value( $response, [ 'currency', [ 'checkout', 'currency' ], [ 'payment', 'currency' ], [ 'payload', 'currency' ], [ 'payload', 'payment', 'currency' ] ] );
		if ( null !== $currency && '' !== $currency && isset( $expected['currency'] ) && strtoupper( (string) $currency ) !== strtoupper( (string) $expected['currency'] ) ) {
			return new WP_Error( 'peach_card_currency_mismatch', __( 'Card registration currency mismatch.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$registration_id = self::get_first_response_value(
			$response,
			[
				'registrationId',
				'registration_id',
				[ 'registration', 'id' ],
				[ 'payment', 'registrationId' ],
				[ 'payment', 'registration', 'id' ],
				[ 'payload', 'registrationId' ],
				[ 'payload', 'registration', 'id' ],
				[ 'payload', 'payment', 'registrationId' ],
				[ 'payload', 'payment', 'registration', 'id' ],
			]
		);
		$registration_id = sanitize_text_field( (string) $registration_id );

		if ( '' === $registration_id ) {
			return new WP_Error( 'peach_missing_registration_id', __( 'Missing card registration ID.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$last4 = self::get_first_response_value(
			$response,
			[
				[ 'card', 'last4Digits' ],
				'card_last4Digits',
				'card.last4Digits',
				[ 'payment', 'card', 'last4Digits' ],
				[ 'payload', 'card', 'last4Digits' ],
				[ 'payload', 'payment', 'card', 'last4Digits' ],
			]
		);
		$holder = self::get_first_response_value( $response, [ [ 'card', 'holder' ], 'card_holder', 'card.holder', [ 'payment', 'card', 'holder' ], [ 'payload', 'card', 'holder' ], [ 'payload', 'payment', 'card', 'holder' ] ] );
		$brand  = self::get_first_response_value( $response, [ 'paymentBrand', 'payment_brand', [ 'payment', 'paymentBrand' ], [ 'payload', 'paymentBrand' ], [ 'payload', 'payment', 'paymentBrand' ] ] );
		$month  = self::get_first_response_value( $response, [ [ 'card', 'expiryMonth' ], 'card_expiryMonth', 'card.expiryMonth', [ 'payment', 'card', 'expiryMonth' ], [ 'payload', 'card', 'expiryMonth' ], [ 'payload', 'payment', 'card', 'expiryMonth' ] ] );
		$year   = self::get_first_response_value( $response, [ [ 'card', 'expiryYear' ], 'card_expiryYear', 'card.expiryYear', [ 'payment', 'card', 'expiryYear' ], [ 'payload', 'card', 'expiryYear' ], [ 'payload', 'payment', 'card', 'expiryYear' ] ] );

		PP_Gateway_Card_Manager::save_card( $user_id, [
			'id'        => $registration_id,
			'num'       => 'xxxx-' . sanitize_text_field( (string) $last4 ),
			'holder'    => sanitize_text_field( (string) $holder ),
			'brand'     => sanitize_text_field( (string) $brand ),
			'exp_year'  => sanitize_text_field( (string) $year ),
			'exp_month' => sanitize_text_field( (string) $month ),
		] );

		self::remove_pending_card_checkout( $user_id, $expected );
		PP_Gateway_Logger::info( 'Peach add-card saved card for user #' . $user_id . ' from ' . sanitize_text_field( (string) $source ) . '. Registration ID: ' . $registration_id );

		return true;
	}

	/**
	 * Reconcile pending add-card checkout sessions for the current My Cards page.
	 * This covers cases where Peach redirects the shopper back to /my-account/my-cards/
	 * without the original pp_add_card_return query string being processed.
	 *
	 * @param int  $user_id     WordPress user ID.
	 * @param bool $add_notice  Whether to show a success notice if a card is saved.
	 * @return int Number of cards saved.
	 */
	public static function process_pending_add_card_checkouts_for_user( $user_id, $add_notice = false ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return 0;
		}

		$pending = get_user_meta( $user_id, self::PENDING_CARD_CHECKOUTS_META_KEY, true );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return 0;
		}

		$saved_count = 0;
		$now         = time();

		foreach ( $pending as $key => $expected ) {
			if ( ! is_array( $expected ) || empty( $expected['merchant_transaction_id'] ) ) {
				unset( $pending[ $key ] );
				continue;
			}

			if ( ! empty( $expected['created_at'] ) && ( $now - absint( $expected['created_at'] ) ) > ( 6 * HOUR_IN_SECONDS ) ) {
				self::remove_pending_card_checkout( $user_id, $expected );
				continue;
			}

			$checkout_id = ! empty( $expected['checkout_id'] ) ? sanitize_text_field( (string) $expected['checkout_id'] ) : '';
			if ( '' === $checkout_id ) {
				continue;
			}

			$status_result = PP_Peach_API::get_checkout_status_from_checkout_id( $checkout_id, 0, [], '' );
			if ( is_wp_error( $status_result ) ) {
				PP_Gateway_Logger::warning( 'Peach pending add-card status check failed for user #' . $user_id . '. Checkout ID: ' . $checkout_id . '. Error: ' . $status_result->get_error_message() );
				continue;
			}

			$response = self::normalise_checkout_v2_status_result_for_card_add( $status_result, [] );
			if ( is_wp_error( $response ) ) {
				PP_Gateway_Logger::warning( 'Peach pending add-card status response was not ready for user #' . $user_id . '. Checkout ID: ' . $checkout_id . '. Error: ' . $response->get_error_message() );
				continue;
			}

			$saved = self::save_card_from_verified_response( $user_id, $response, $expected, 'pending-status' );
			if ( is_wp_error( $saved ) ) {
				PP_Gateway_Logger::warning( 'Peach pending add-card could not be saved for user #' . $user_id . '. Checkout ID: ' . $checkout_id . '. Error: ' . $saved->get_error_message() . '. Response: ' . print_r( $response, true ) );
				continue;
			}

			$saved_count++;
		}

		if ( $saved_count > 0 && $add_notice && function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Card saved successfully.', WC_PEACH_TEXT_DOMAIN ), 'success' );
		}

		return $saved_count;
	}

	/**
	 * Attempt to save an add-card registration from a verified Peach webhook.
	 *
	 * @param array $data Verified and normalised webhook data.
	 * @return true|WP_Error
	 */
	public static function handle_add_card_webhook( array $data ) {
		$merchant_reference = self::get_first_response_value(
			$data,
			[
				[ 'payload', 'merchantTransactionId' ],
				'merchantTransactionId',
				[ 'payload', 'merchantInvoiceId' ],
				'merchantInvoiceId',
			]
		);
		$merchant_reference = sanitize_text_field( (string) $merchant_reference );

		if ( '' === $merchant_reference ) {
			return new WP_Error( 'peach_missing_card_webhook_reference', __( 'Missing add-card webhook reference.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$expected = get_transient( self::get_card_merchant_transient_key( $merchant_reference ) );
		if ( ! is_array( $expected ) || empty( $expected['user_id'] ) ) {
			PP_Gateway_Logger::info( 'Peach add-card webhook acknowledged but no pending card session was found for reference ' . $merchant_reference . '.' );
			return true;
		}

		$user_id     = absint( $expected['user_id'] );
		$checkout_id = ! empty( $expected['checkout_id'] ) ? sanitize_text_field( (string) $expected['checkout_id'] ) : '';
		if ( '' === $checkout_id ) {
			$checkout_id = self::get_first_response_value( $data, [ [ 'payload', 'checkoutId' ], 'checkoutId', [ 'payload', 'checkout', 'id' ], [ 'checkout', 'id' ] ] );
			$checkout_id = sanitize_text_field( (string) $checkout_id );
		}

		$response = $data;
		if ( '' !== $checkout_id ) {
			$status_result = PP_Peach_API::get_checkout_status_from_checkout_id( $checkout_id, 0, [], '' );
			if ( ! is_wp_error( $status_result ) ) {
				$normalised = self::normalise_checkout_v2_status_result_for_card_add( $status_result, $data );
				if ( ! is_wp_error( $normalised ) ) {
					$response = $normalised;
				} else {
					PP_Gateway_Logger::warning( 'Peach add-card webhook status response could not be normalised for reference ' . $merchant_reference . ': ' . $normalised->get_error_message() );
				}
			} else {
				PP_Gateway_Logger::warning( 'Peach add-card webhook status lookup failed for reference ' . $merchant_reference . ': ' . $status_result->get_error_message() );
			}
		}

		return self::save_card_from_verified_response( $user_id, $response, $expected, 'webhook' );
	}

	/**
	 * Handle the Peach hosted add-card return.
	 *
	 * Supports the legacy resourcePath return and the Checkout V2 checkoutId return.
	 * Card data is saved only after a server-to-server verification with Peach.
	 */
	public static function maybe_handle_resource_path() {
		if ( ! isset( $_GET['pp_add_card_return'] ) && ! isset( $_POST['pp_add_card_return'] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'Please log in before saving a Peach Payments card.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		$return_token = self::get_return_request_value( 'pp_card_token' );
		$user_id      = get_current_user_id();

		if ( '' === $return_token ) {
			PP_Gateway_Logger::warning( 'Peach add-card return rejected for user #' . $user_id . ': missing return token.' );
			wc_add_notice( __( 'Card registration could not be verified.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		$transient_key = self::get_card_return_transient_key( $user_id, $return_token );
		$expected      = get_transient( $transient_key );

		if ( ! is_array( $expected ) || empty( $expected['merchant_transaction_id'] ) || (int) $expected['user_id'] !== (int) $user_id ) {
			PP_Gateway_Logger::warning( 'Peach add-card return rejected for user #' . $user_id . ': return token missing or expired.' );
			wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		$resource_path      = self::get_return_request_value( 'resourcePath' );
		$checkout_id        = self::get_return_request_value( 'checkoutId' );
		$checkout_id_source = 'return_payload';
		if ( '' === $checkout_id ) {
			$checkout_id = self::get_return_request_value( 'checkout_id' );
		}
		if ( '' === $checkout_id && ! empty( $expected['checkout_id'] ) ) {
			$checkout_id        = sanitize_text_field( (string) $expected['checkout_id'] );
			$checkout_id_source = 'stored_return_token';
		}

		$raw_return_body       = (string) file_get_contents( 'php://input' );
		$posted_return_payload = is_array( $_POST ) ? wc_clean( wp_unslash( $_POST ) ) : [];

		PP_Gateway_Logger::info(
			'Peach add-card return received: ' . print_r(
				[
					'user_id'             => (int) $user_id,
					'has_resourcePath'    => ( '' !== $resource_path ),
					'checkoutId'          => $checkout_id,
					'checkoutId_source'   => $checkout_id_source,
					'get_payload'         => is_array( $_GET ) ? wc_clean( wp_unslash( $_GET ) ) : [],
					'post_payload'        => $posted_return_payload,
					'raw_return_body'     => $raw_return_body,
				],
				true
			)
		);

		if ( '' !== $resource_path ) {
			$response = PP_Peach_API::get_registration_result( $resource_path );
		} elseif ( '' !== $checkout_id ) {
			if ( empty( $expected['checkout_id'] ) ) {
				PP_Gateway_Logger::error( 'Peach add-card Checkout V2 return rejected for user #' . $user_id . ': no checkout ID was stored for this card return token. Posted payload: ' . print_r( $posted_return_payload, true ) );
				wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
				exit;
			}

			if ( ! hash_equals( (string) $expected['checkout_id'], (string) $checkout_id ) ) {
				delete_transient( $transient_key );
				PP_Gateway_Logger::error( 'Peach add-card Checkout V2 return rejected for user #' . $user_id . ': checkout ID mismatch. Expected: ' . sanitize_text_field( (string) $expected['checkout_id'] ) . '. Received: ' . sanitize_text_field( (string) $checkout_id ) . '. Posted payload: ' . print_r( $posted_return_payload, true ) );
				wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
				exit;
			}

			$status_result = PP_Peach_API::get_checkout_status_from_checkout_id( $checkout_id, 0, $posted_return_payload, $raw_return_body );
			if ( is_wp_error( $status_result ) ) {
				$response = $status_result;
			} else {
				$response = self::normalise_checkout_v2_status_result_for_card_add( $status_result, $posted_return_payload );
			}
		} else {
			PP_Gateway_Logger::warning( 'Peach add-card return reached without resourcePath or checkoutId. Public POST data was not trusted and no card was saved.' );
			wc_add_notice( __( 'Card registration could not be verified.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}
	
		if ( is_wp_error( $response ) ) {
			PP_Gateway_Logger::error( 'Peach add-card verification failed for user #' . $user_id . ': ' . $response->get_error_message() );
			wc_add_notice( __( 'Failed to retrieve card registration.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		PP_Gateway_Logger::info( 'Peach add-card verified response for user #' . $user_id . ': ' . print_r( $response, true ) );

		$result_code = isset( $response['result']['code'] ) ? sanitize_text_field( (string) $response['result']['code'] ) : '';
		if ( '' === $result_code || ! PP_Gateway_Order_Utils::is_successful_result_code( $result_code ) ) {
			delete_transient( $transient_key );
			PP_Gateway_Logger::warning( 'Peach add-card registration failed for user #' . $user_id . '. Response: ' . print_r( $response, true ) );
			wc_add_notice( __( 'Card registration failed.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		$received_reference = isset( $response['merchantTransactionId'] ) ? sanitize_text_field( (string) $response['merchantTransactionId'] ) : '';
		if ( '' === $received_reference && isset( $response['merchantInvoiceId'] ) ) {
			$received_reference = sanitize_text_field( (string) $response['merchantInvoiceId'] );
		}

		if ( '' !== $received_reference && ! PP_Peach_API::merchant_references_match( (string) $expected['merchant_transaction_id'], $received_reference ) ) {
			delete_transient( $transient_key );
			PP_Gateway_Logger::error( 'Peach add-card return rejected for user #' . $user_id . ': merchant reference mismatch. Response: ' . print_r( $response, true ) );
			wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		if ( '' === $received_reference ) {
			PP_Gateway_Logger::warning( 'Peach add-card verified response for user #' . $user_id . ' did not include merchantTransactionId/merchantInvoiceId. Continuing because the stored checkoutId was verified server-to-server.' );
		}

		if ( isset( $response['amount'] ) && number_format( (float) $response['amount'], 2, '.', '' ) !== number_format( (float) $expected['amount'], 2, '.', '' ) ) {
			delete_transient( $transient_key );
			PP_Gateway_Logger::error( 'Peach add-card return rejected for user #' . $user_id . ': amount mismatch. Response: ' . print_r( $response, true ) );
			wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}

		if ( isset( $response['currency'] ) && strtoupper( (string) $response['currency'] ) !== strtoupper( (string) $expected['currency'] ) ) {
			delete_transient( $transient_key );
			PP_Gateway_Logger::error( 'Peach add-card return rejected for user #' . $user_id . ': currency mismatch. Response: ' . print_r( $response, true ) );
			wc_add_notice( __( 'Card registration could not be verified. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
			exit;
		}
	
		if ( isset( $response['card'] ) ) {
			$registration_id = isset( $response['registrationId'] ) ? sanitize_text_field( (string) $response['registrationId'] ) : '';
			if ( '' === $registration_id && isset( $response['id'] ) && '' !== $resource_path ) {
				$registration_id = sanitize_text_field( (string) $response['id'] );
			}

			if ( '' === $registration_id ) {
				delete_transient( $transient_key );
				PP_Gateway_Logger::error( 'Peach add-card return did not include a registrationId for user #' . $user_id . '. Response: ' . print_r( $response, true ) );
				wc_add_notice( __( 'Card registration failed.', WC_PEACH_TEXT_DOMAIN ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
				exit;
			}

			// Save card data to user_meta only after the Peach result has been verified server-to-server.
			PP_Gateway_Card_Manager::save_card( $user_id, [
				'id'        => $registration_id,
				'num'       => 'xxxx-' . sanitize_text_field( $response['card']['last4Digits'] ?? '' ),
				'holder'    => sanitize_text_field( $response['card']['holder'] ?? '' ),
				'brand'     => sanitize_text_field( $response['paymentBrand'] ?? '' ),
				'exp_year'  => sanitize_text_field( $response['card']['expiryYear'] ?? '' ),
				'exp_month' => sanitize_text_field( $response['card']['expiryMonth'] ?? '' ),
			] );
			self::remove_pending_card_checkout( $user_id, $expected );
			delete_transient( $transient_key );
	
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
						PP_Gateway_Logger::warning( 'Card add reversal not successful. Transaction ID: ' . ( sanitize_text_field( $response['id'] ) ?: 'N/A' ) . ' | Result: ' . ( $rv_result_code ?: 'N/A' ) . ( $rv_result_desc ? ' - ' . $rv_result_desc : '' ) );
					}
				}
			}

		} else {
			delete_transient( $transient_key );
			wc_add_notice( __( 'Card registration failed.', WC_PEACH_TEXT_DOMAIN ), 'error' );
		}
	
		wp_safe_redirect( wc_get_account_endpoint_url( 'my-cards' ) );
		exit;
	}

}
