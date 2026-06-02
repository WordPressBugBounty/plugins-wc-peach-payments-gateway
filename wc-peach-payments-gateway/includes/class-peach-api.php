<?php
/**
 * Handles communication with the Peach Payments API.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Peach_API {

	/**
	 * Make a cURL request to the Peach Payments API.
	 *
	 * @param string $endpoint API endpoint (relative).
	 * @param string $method HTTP method (GET, POST, DELETE).
	 * @param array  $data Request body data (will be JSON-encoded).
	 *
	 * @return array|WP_Error
	 */
	public function request( $endpoint, $method = 'POST', $data = [] ) {
		$ch = curl_init();
		
		$entity_id = PP_Gateway_Settings::get('channel_3ds');
		
		$url     = trailingslashit( $this->get_base_url() ) . $endpoint;
		$url .= "?entityId=".$entity_id;
		
		$headers = [
			'Authorization: Bearer ' . self::get_auth_token(),
		];
		
		$transaction_mode = PP_Gateway_Settings::get('transaction_mode');
		$ssl = ( $transaction_mode === 'INTEGRATOR_TEST' ) ? false : true;
		$success_code = ( $transaction_mode === 'INTEGRATOR_TEST' ) ? '000.100.110' : '000.000.000';

		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, strtoupper( $method ) );
		
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		
		if(strtoupper( $method ) != 'DELETE'){
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl);
		}
		
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

		if ( in_array( strtoupper( $method ), [ 'POST', 'PUT', 'DELETE' ], true ) && ! empty( $data ) ) {
			$body = json_encode( $data );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
			$headers[] = 'Content-Type: application/json';
			$headers[] = 'Accept: application/json';
		}

		$response     = curl_exec( $ch );
		$responseCode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			$error_msg = curl_error( $ch );
			curl_close( $ch );

			$this->log_error( $url, $method, $data, $error_msg );
			return new WP_Error( 'peach_api_curl_error', $error_msg );
		}

		curl_close( $ch );

		$decoded = json_decode( $response, true );
		
		if($decoded['result']['code'] == $success_code || $decoded['result']['code'] == '000.200.100'){
			return $decoded;
		}else{
			PP_Gateway_Logger::error( "Request to Peach API. ".print_r($decoded, true) );
			PP_Gateway_Logger::error( "Request to Peach API URL. ".print_r($url, true) );
			PP_Gateway_Logger::error( "Request to Peach API BODY. ".print_r($body, true) );
			if ( $responseCode >= 400 ) {
				$this->log_error( $url, $method, $data, $decoded );
				return new WP_Error( 'peach_api_http_error', $decoded['message'] ?? 'API error', $decoded );
			}
		}

		return $decoded;
	}

	/**
	 * Delete a stored token from Peach Payments.
	 *
	 * @param string $registration_id
	 * @return true|WP_Error
	 */
	public function delete_token( $registration_id ) {
		$response = $this->request( "v1/registrations/{$registration_id}", 'DELETE' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return true;
	}
	
	/**
	 * Create (register) a new token using Peach Payments API.
	 *
	 * @param array $card {
	 *     @type string $holder     Cardholder name.
	 *     @type string $num        Card number.
	 *     @type string $exp_month  Expiry month (MM).
	 *     @type string $exp_year   Expiry year (YYYY).
	 * }
	 *
	 * @return array|WP_Error Array with registrationId on success, or WP_Error on failure.
	 */
	public function create_token( $card ) {
		$url = $this->base_url . '/v1/registrations';
		/*
		$payload = [
			'paymentBrand' => $this->detect_brand( $card['num'] ),
			'card' => [
				'holder'     => $card['holder'],
				'number'     => $card['card_number'],
				'expiryMonth'=> $card['expiry_month'],
				'expiryYear' => $card['expiry_year']
			]
		];
		*/
		$payload = [
			//'entityId'          => $entity_id,
			'paymentBrand'      => 'VISA',
			'card.number'       => $card['card_number'],
			'card.holder'       => $card['holder'],
			'card.expiryMonth'  => $card['expiry_month'],
			'card.expiryYear'   => $card['holder'],
			'card.cvv'          => $card['expiry_year'],
		];
	
		$response = $this->post_request( $url, $payload );
	
		if ( is_wp_error( $response ) ) {
			return $response;
		}
	
		if ( empty( $response['id'] ) ) {
			return new WP_Error( 'no_registration_id', 'No registration ID returned from Peach.' );
		}
	
		return [
			'registrationId' => $response['id'],
			'brand'          => $payload['paymentBrand']
		];
	}


	/**
	 * Get the base API URL depending on mode.
	 *
	 * @return string
	 */
	public static function get_base_url() {
		$test_mode = 'INTEGRATOR_TEST' === PP_Gateway_Settings::get('transaction_mode');
		
		return $test_mode ? 'https://sandbox-card.peachpayments.com/' : 'https://card.peachpayments.com/';
	}

	/**
	 * Get the Peach API username and password string.
	 *
	 * @return string
	 */
	private function get_auth_string() {
		$user = get_option( 'woocommerce_peach-payments_user_id', '' );
		$pass = get_option( 'woocommerce_peach-payments_password', '' );
		return $user . ':' . $pass;
	}

	/**
	 * Log API-related errors with optional request/response context.
	 *
	 * @param string      $message  Error message to log.
	 * @param array|null  $request  Request payload (optional).
	 * @param array|null  $response Response payload (optional).
	 * @param string|null $url      Endpoint URL (optional).
	 */
	public static function log_error( $message, $request = null, $response = null, $url = null ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
	
		$logger = wc_get_logger();
		$log    = "Peach API Error: $message";
	
		if ( $url ) {
			$log .= "\nURL: $url";
		}
	
		if ( $request ) {
			$masked = self::mask_sensitive_data( $request );
			$log   .= "\nRequest: " . print_r( $masked, true );
		}
	
		if ( $response ) {
			$log .= "\nResponse: " . print_r( $response, true );
		}
	
		$logger->error( $log, [ 'source' => 'peach-payments' ] );
	}


	/**
	 * Mask sensitive data in logs.
	 *
	 * @param array $data
	 * @return array
	 */
	public static function mask_sensitive_data( $data ) {
		$masked = $data;
	
		if ( isset( $masked['card.number'] ) ) {
			$last4 = substr( $masked['card.number'], -4 );
			$masked['card.number'] = '**** **** **** ' . $last4;
		}
	
		if ( isset( $masked['card.cvv'] ) ) {
			$masked['card.cvv'] = '***';
		}
	
		if ( isset( $masked['authentication.userId'] ) ) {
			$masked['authentication.userId'] = '***';
		}
	
		if ( isset( $masked['authentication.password'] ) ) {
			$masked['authentication.password'] = '***';
		}
	
		return $masked;
	}


	/**
	 * Flatten multidimensional array using dot notation.
	 *
	 * @param array  $array
	 * @param string $prefix
	 * @return array
	 */
	private function flatten_array( array $array, $prefix = '' ) {
		$result = [];

		foreach ( $array as $key => $value ) {
			$new_key = $prefix === '' ? $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$result += $this->flatten_array( $value, $new_key );
			} else {
				$result[ $new_key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Unflatten dot notation array back into nested array.
	 *
	 * @param array $array
	 * @return array
	 */
	private function unflatten_array( array $array ) {
		$result = [];

		foreach ( $array as $flat_key => $value ) {
			$keys = explode( '.', $flat_key );
			$temp =& $result;

			foreach ( $keys as $key ) {
				if ( ! isset( $temp[ $key ] ) || ! is_array( $temp[ $key ] ) ) {
					$temp[ $key ] = [];
				}
				$temp =& $temp[ $key ];
			}

			$temp = $value;
		}

		return $result;
	}
	
	/**
	 * Detect card brand based on the card number.
	 *
	 * @param string $number Card number.
	 * @return string Card brand (e.g. VISA, MASTER, AMEX).
	 */
	protected function detect_brand( $number ) {
		$number = preg_replace( '/\D/', '', $number ); // Remove non-digits
	
		if ( preg_match( '/^4[0-9]{12}(?:[0-9]{3})?$/', $number ) ) {
			return 'VISA';
		}
	
		if ( preg_match( '/^5[1-5][0-9]{14}$/', $number ) ) {
			return 'MASTER';
		}
	
		if ( preg_match( '/^3[47][0-9]{13}$/', $number ) ) {
			return 'AMEX';
		}
	
		if ( preg_match( '/^6(?:011|5[0-9]{2})[0-9]{12}$/', $number ) ) {
			return 'DISCOVER';
		}
	
		if ( preg_match( '/^(?:2131|1800|35\d{3})\d{11}$/', $number ) ) {
			return 'JCB';
		}
	
		// Default fallback
		return 'VISA';
	}
	
	/**
	 * Perform a POST request to the Peach Payments API.
	 *
	 * @param string $endpoint Relative API endpoint (e.g., '/v1/registrations').
	 * @param array  $payload  Request body to send.
	 * @return array|WP_Error  API response or WP_Error on failure.
	 */
	public static function post_request( $endpoint, $payload, $type = '' ) {
		$transaction_mode = PP_Gateway_Settings::get('transaction_mode');
		$ssl = ( $transaction_mode === 'INTEGRATOR_TEST' ) ? false : true;
		
		$ch = curl_init();
		
		if($type != 'refund'){
			$full_url = self::get_endpoint_url() . ltrim( $endpoint, '/' );
			$headers = [
				'Authorization: Bearer ' . self::get_auth_token(),
			];
			
			curl_setopt($ch, CURLOPT_URL, $full_url);
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			
		}else{
			$full_url = $endpoint;
			$headers = [
				'Content-Type: application/x-www-form-urlencoded',
			];
			
			curl_setopt($ch, CURLOPT_URL, $full_url);
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_ENCODING, '');
			curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
			curl_setopt($ch, CURLOPT_TIMEOUT, 0);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
			
		}
		
	
		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	
		if ( curl_errno( $ch ) ) {
			$error_message = curl_error( $ch );
			curl_close( $ch );
	
			self::log_error(
				"cURL error [".curl_errno( $ch )."] while posting to {$endpoint}: {$error_message}",
				$full_url,
				$payload,
				null
			);
	
			return new WP_Error( 'peach_api_curl_error', $error_message );
		}
	
		curl_close( $ch );
	
		$response_data = json_decode( $response_body, true );
		
		
		if(PP_Gateway_Order_Utils::is_successful_result_code($response_data['result']['code'])){
			return $response_data;
		}else{
			if ( $response_code < 200 || $response_code >= 300 ) {
				self::log_error(
					"Unexpected HTTP response code {$response_code} from {$endpoint}",
					$full_url,
					$payload,
					$response_data
				);
		
				return new WP_Error(
					'peach_api_http_error',
					'Unexpected HTTP response code: ' . $response_code,
					$response_data
				);
			}
		}
		
		return $response_data;
	}
	
	/**
	 * Get the full base URL for the Peach Payments API.
	 *
	 * @return string Fully qualified base API URL ending with a slash.
	 */
	public static function get_endpoint_url() {
		$test_mode = 'INTEGRATOR_TEST' === PP_Gateway_Settings::get('transaction_mode');
		
		return $test_mode ? 'https://sandbox-card.peachpayments.com/' : 'https://card.peachpayments.com/';

	}

	
	/**
	 * Mask sensitive card fields for logging.
	 */
	private function mask_sensitive_fields( $data ) {
		if ( isset( $data['card']['number'] ) ) {
			$data['card']['number'] = '****' . substr( $data['card']['number'], -4 );
		}
		if ( isset( $data['card']['cvv'] ) ) {
			$data['card']['cvv'] = '***';
		}
		return $data;
	}
	
	/**
	 * Returns the Peach Payments access token (same for both test and live modes).
	 *
	 * @return string
	 */
	public static function get_auth_token() {
		$settings = get_option( 'woocommerce_peach-payments_settings', [] );
		return trim( $settings['access_token'] ?? '' );
	}
	
	/**
	 * Normalise and validate a Peach Payments resourcePath before using it in a
	 * server-to-server lookup. Public return URLs may include this value, so it
	 * must never be used as a full URL or trusted without validation.
	 *
	 * @param string $resource_path Peach resource path from the return request.
	 * @return string|WP_Error
	 */
	private static function normalise_resource_path( $resource_path ) {
		$resource_path = trim( (string) wp_unslash( $resource_path ) );
		$resource_path = rawurldecode( $resource_path );

		if ( '' === $resource_path ) {
			return new WP_Error( 'peach_missing_resource_path', __( 'Missing Peach Payments resource path.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$parts = wp_parse_url( $resource_path );
		$path  = '';
		$query = '';

		if ( is_array( $parts ) && ! empty( $parts['path'] ) ) {
			$path = $parts['path'];
			if ( ! empty( $parts['query'] ) ) {
				$query = '?' . $parts['query'];
			}
		} else {
			$path = $resource_path;
		}

		$path = '/' . ltrim( $path, '/' );

		if ( ! preg_match( '#^/v[0-9]+/(checkouts|payments|registrations|query)/[A-Za-z0-9._~%/\-]+#', $path ) ) {
			return new WP_Error( 'peach_invalid_resource_path', __( 'Invalid Peach Payments resource path.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return $path . $query;
	}

	/**
	 * Fetch a Peach Payments result from a return resourcePath by calling Peach
	 * server-to-server with the configured credentials.
	 *
	 * @param string $resource_path Peach resource path.
	 * @return array|WP_Error
	 */
	public static function get_result_from_resource_path( $resource_path ) {
		$resource_path = self::normalise_resource_path( $resource_path );
		if ( is_wp_error( $resource_path ) ) {
			return $resource_path;
		}

		$entity_id = trim( (string) PP_Gateway_Settings::get( 'channel_3ds' ) );
		$token     = trim( (string) self::get_auth_token() );

		if ( '' === $entity_id || '' === $token ) {
			return new WP_Error( 'peach_missing_credentials', __( 'Missing Peach Payments credentials for payment verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$url = self::get_endpoint_url() . ltrim( $resource_path, '/' );
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( [ 'entityId' => $entity_id ] );

		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
		];

		$transaction_mode = PP_Gateway_Settings::get( 'transaction_mode' );
		$ssl              = ( 'INTEGRATOR_TEST' === $transaction_mode ) ? false : true;

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $ssl );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			$error_message = curl_error( $ch );
			$errno         = curl_errno( $ch );
			curl_close( $ch );

			self::log_error(
				'cURL error [' . $errno . '] while verifying Peach Payments resource path: ' . $error_message,
				[ 'resource_path' => $resource_path ],
				null,
				$url
			);

			return new WP_Error( 'peach_api_curl_error', $error_message );
		}

		curl_close( $ch );

		$response_data = json_decode( (string) $response_body, true );

		if ( $response_code < 200 || $response_code >= 300 ) {
			self::log_error(
				'Unexpected HTTP response code while verifying Peach Payments resource path: ' . (int) $response_code,
				[ 'resource_path' => $resource_path ],
				$response_body,
				$url
			);

			return new WP_Error( 'peach_api_http_error', __( 'Payment verification failed at Peach Payments.', WC_PEACH_TEXT_DOMAIN ), $response_data );
		}

		if ( ! is_array( $response_data ) ) {
			self::log_error(
				'Invalid JSON returned while verifying Peach Payments resource path.',
				[ 'resource_path' => $resource_path ],
				$response_body,
				$url
			);

			return new WP_Error( 'peach_api_invalid_json', __( 'Invalid payment verification response from Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return $response_data;
	}

	/**
	 * Backwards-compatible wrapper used by the hosted checkout return flow.
	 *
	 * @param string $resource_path Peach resource path.
	 * @return array|WP_Error
	 */
	public static function get_payment_result_from_resource_path( $resource_path ) {
		return self::get_result_from_resource_path( $resource_path );
	}


	/**
	 * Verify a Peach Payments transaction ID by querying Peach server-to-server.
	 * This supports older return integrations that POST a transaction ID instead
	 * of a resourcePath, without trusting the posted payment result fields.
	 *
	 * @param string $transaction_id Peach transaction/payment ID.
	 * @return array|WP_Error
	 */
	public static function get_payment_result_from_transaction_id( $transaction_id ) {
		$transaction_id = trim( (string) wp_unslash( $transaction_id ) );
		$transaction_id = sanitize_text_field( $transaction_id );

		if ( '' === $transaction_id ) {
			return new WP_Error( 'peach_missing_transaction_id', __( 'Missing Peach Payments transaction ID.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$entity_id = trim( (string) PP_Gateway_Settings::get( 'channel_3ds' ) );
		$token     = trim( (string) self::get_auth_token() );

		if ( '' === $entity_id || '' === $token ) {
			return new WP_Error( 'peach_missing_credentials', __( 'Missing Peach Payments credentials for payment verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$url = self::get_endpoint_url() . 'v3/query/' . rawurlencode( $transaction_id ) . '?' . http_build_query( [ 'entityId' => $entity_id ] );

		$headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
		];

		$transaction_mode = PP_Gateway_Settings::get( 'transaction_mode' );
		$ssl              = ( 'INTEGRATOR_TEST' === $transaction_mode ) ? false : true;

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $ssl );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			$error_message = curl_error( $ch );
			$errno         = curl_errno( $ch );
			curl_close( $ch );

			self::log_error(
				'cURL error [' . $errno . '] while verifying Peach Payments transaction ID: ' . $error_message,
				[ 'transaction_id' => $transaction_id ],
				null,
				$url
			);

			return new WP_Error( 'peach_api_curl_error', $error_message );
		}

		curl_close( $ch );

		$response_data = json_decode( (string) $response_body, true );

		if ( $response_code < 200 || $response_code >= 300 ) {
			self::log_error(
				'Unexpected HTTP response code while verifying Peach Payments transaction ID: ' . (int) $response_code,
				[ 'transaction_id' => $transaction_id ],
				$response_body,
				$url
			);

			return new WP_Error( 'peach_api_http_error', __( 'Payment verification failed at Peach Payments.', WC_PEACH_TEXT_DOMAIN ), $response_data );
		}

		if ( ! is_array( $response_data ) ) {
			return new WP_Error( 'peach_api_invalid_json', __( 'Invalid payment verification response from Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( isset( $response_data['records'] ) && is_array( $response_data['records'] ) ) {
			foreach ( $response_data['records'] as $record ) {
				if ( is_array( $record ) && isset( $record['id'] ) && (string) $record['id'] === (string) $transaction_id ) {
					return $record;
				}
			}

			foreach ( $response_data['records'] as $record ) {
				if ( is_array( $record ) ) {
					return $record;
				}
			}
		}

		return $response_data;
	}

	/**
	 * Backwards-compatible wrapper used by the add-card flow.
	 *
	 * @param string $resource_path Peach resource path.
	 * @return array|WP_Error
	 */
	public static function get_registration_result( $resource_path ) {
		return self::get_result_from_resource_path( $resource_path );
	}

	/**
	 * Legacy method retained for compatibility with older internal callers.
	 *
	 * @param string $resource_path Peach resource path.
	 * @param string $registration_id Unused legacy parameter.
	 * @return array|WP_Error
	 */
	public static function get_payment_status( $resource_path, $registration_id = '' ) {
		return self::get_result_from_resource_path( $resource_path );
	}

	/**
	 * Check whether a Peach return resourcePath still points to the checkout that
	 * was created for this WooCommerce order. This is an additional correlation
	 * check; the payment result is still verified server-to-server afterwards.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $resource_path Peach resource path.
	 * @return true|WP_Error
	 */
	public static function validate_checkout_resource_for_order( WC_Order $order, $resource_path ) {
		$expected_checkout_id = trim( (string) $order->get_meta( '_peach_checkout_id', true ) );

		if ( '' === $expected_checkout_id ) {
			return true;
		}

		$resource_path = rawurldecode( (string) $resource_path );

		if ( false === strpos( $resource_path, $expected_checkout_id ) ) {
			return new WP_Error( 'peach_checkout_mismatch', __( 'Payment verification failed because the Peach checkout session did not match the WooCommerce order.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return true;
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
	 * Normalise a merchant transaction/reference value for comparison.
	 *
	 * @param string $value Reference value.
	 * @return string
	 */
	private static function normalise_merchant_reference( $value ) {
		$value = trim( (string) $value );
		$trimmed = ltrim( $value, '0' );
		return '' === $trimmed ? $value : $trimmed;
	}

	/**
	 * Compare Peach merchant references while allowing the plugin's 8-character
	 * left-zero padding format.
	 *
	 * @param string $expected Expected reference.
	 * @param string $received Received reference.
	 * @return bool
	 */
	public static function merchant_references_match( $expected, $received ) {
		$expected = trim( (string) $expected );
		$received = trim( (string) $received );

		if ( '' === $expected || '' === $received ) {
			return false;
		}

		return $expected === $received || self::normalise_merchant_reference( $expected ) === self::normalise_merchant_reference( $received );
	}

	/**
	 * Get the expected Peach merchant transaction ID for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	public static function get_expected_merchant_transaction_id( WC_Order $order ) {
		$expected = trim( (string) $order->get_meta( '_peach_expected_merchant_transaction_id', true ) );

		if ( '' !== $expected ) {
			return $expected;
		}

		$order_number = strval( PP_Gateway_Order_Utils::find_converted_number( $order->get_id(), true ) );
		return strval( PP_Gateway_Order_Utils::order_number_prep( $order_number ) );
	}

	/**
	 * Validate a verified Peach payment response against the WooCommerce order
	 * before any payment status changes are applied.
	 *
	 * @param WC_Order $order    WooCommerce order.
	 * @param array    $response Peach response already fetched/decrypted from Peach.
	 * @param string   $context  Log context.
	 * @return true|WP_Error
	 */
	public static function validate_payment_result_for_order( WC_Order $order, array $response, $context = 'return' ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'peach_invalid_order', __( 'Invalid WooCommerce order for Peach Payments verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( 'peach-payments' !== $order->get_payment_method() ) {
			return new WP_Error( 'peach_wrong_gateway', __( 'The WooCommerce order does not belong to the Peach Payments gateway.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$result_code = self::get_first_response_value( $response, [ [ 'result', 'code' ], 'result_code' ] );
		if ( empty( $result_code ) ) {
			return new WP_Error( 'peach_missing_result_code', __( 'Missing Peach Payments result code.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$expected_reference = self::get_expected_merchant_transaction_id( $order );
		$received_reference = self::get_first_response_value(
			$response,
			[
				'merchantTransactionId',
				'merchantInvoiceId',
				[ 'payload', 'merchantTransactionId' ],
				[ 'payload', 'merchantInvoiceId' ],
			]
		);

		if ( ! self::merchant_references_match( $expected_reference, (string) $received_reference ) ) {
			return new WP_Error( 'peach_merchant_reference_mismatch', __( 'Peach Payments merchant reference did not match the WooCommerce order.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$received_amount   = self::get_first_response_value( $response, [ 'amount', [ 'payment', 'amount' ], [ 'payload', 'amount' ], [ 'payload', 'payment', 'amount' ] ] );
		$received_currency = self::get_first_response_value( $response, [ 'currency', [ 'payment', 'currency' ], [ 'payload', 'currency' ], [ 'payload', 'payment', 'currency' ] ] );

		if ( null === $received_amount || '' === $received_amount ) {
			return new WP_Error( 'peach_missing_amount', __( 'Missing Peach Payments amount for order verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( null === $received_currency || '' === $received_currency ) {
			return new WP_Error( 'peach_missing_currency', __( 'Missing Peach Payments currency for order verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$expected_amount   = number_format( (float) $order->get_total(), 2, '.', '' );
		$received_amount   = number_format( (float) str_replace( ',', '.', (string) $received_amount ), 2, '.', '' );
		$expected_currency = strtoupper( (string) $order->get_currency() );
		$received_currency = strtoupper( sanitize_text_field( (string) $received_currency ) );

		if ( $expected_amount !== $received_amount ) {
			return new WP_Error( 'peach_amount_mismatch', __( 'Peach Payments amount did not match the WooCommerce order total.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( $expected_currency !== $received_currency ) {
			return new WP_Error( 'peach_currency_mismatch', __( 'Peach Payments currency did not match the WooCommerce order currency.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return true;
	}

	/**
	 * Build standing instruction for subscription checkouts.
	 *
	 * @param WC_Order $order
	 * @return array{expiry:string,frequency:int}
	 */
	private static function get_standing_instruction_from_order( WC_Order $order ) {
		$expiry    = '9999-12-31';
		$frequency = 30;

		// WooCommerce Subscriptions not active.
		if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return [
				'expiry'    => $expiry,
				'frequency' => $frequency,
			];
		}

		try {
			foreach ( $order->get_items() as $item ) {
				if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				if ( method_exists( 'WC_Subscriptions_Product', 'is_subscription' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
					$interval = (int) ( method_exists( 'WC_Subscriptions_Product', 'get_interval' ) ? WC_Subscriptions_Product::get_interval( $product ) : 0 );
					$period   = (string) ( method_exists( 'WC_Subscriptions_Product', 'get_period' ) ? WC_Subscriptions_Product::get_period( $product ) : '' );
					$length   = (int) ( method_exists( 'WC_Subscriptions_Product', 'get_length' ) ? WC_Subscriptions_Product::get_length( $product ) : 0 );

					if ( $interval < 1 ) {
						$interval = 1;
					}

					// Frequency must be in days for Peach standingInstruction.
					switch ( $period ) {
						case 'day':
							$frequency = 1 * $interval;
							break;
						case 'week':
							$frequency = 7 * $interval;
							break;
						case 'month':
							// WC Subscriptions uses calendar months; Peach expects an integer day frequency.
							// Use 30-day approximation as a sensible default.
							$frequency = 30 * $interval;
							break;
						case 'year':
							$frequency = 365 * $interval;
							break;
						default:
							$frequency = 30;
							break;
					}

					if ( $frequency < 1 ) {
						$frequency = 30;
					}

					// Expiry: use subscription length if it exists, otherwise fall back to "no expiry" default.
					if ( $length > 0 ) {
						$total = $length * $interval;

						$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : null;
						$dt = new DateTime( 'now', $tz instanceof DateTimeZone ? $tz : null );

						switch ( $period ) {
							case 'day':
								$dt->add( new DateInterval( 'P' . $total . 'D' ) );
								break;
							case 'week':
								$dt->add( new DateInterval( 'P' . $total . 'W' ) );
								break;
							case 'month':
								$dt->add( new DateInterval( 'P' . $total . 'M' ) );
								break;
							case 'year':
								$dt->add( new DateInterval( 'P' . $total . 'Y' ) );
								break;
							default:
								// Unknown period: keep default expiry.
								break;
						}

						$expiry = $dt->format( 'Y-m-d' );
					}

					break; // Use the first subscription item found.
				}
			}
		} catch ( Exception $e ) {
			// Fallback to defaults on any failure.
			$expiry    = '9999-12-31';
			$frequency = 30;
		}

		return [
			'expiry'    => $expiry,
			'frequency' => (int) $frequency,
		];
	}


public static function create_checkout( WC_Order $order ) {
		$order_id = $order->get_id();
		
		$is_subscription = PP_Gateway_Order_Utils::is_subscription( $order );
		
		$cardTokens = [];
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$cardTokens = PP_Gateway_Order_Utils::get_user_card_tokens($user_id);
		}
		
		// Generate access token
		$token_response = WC_Gateway_Peach_Hosted::generate_access_token();
		if ( empty( $token_response['access_token'] ) ) {
			self::log_error( 'Token Response ['.$order_id.']', $token_response['body'], $token_response['raw'], $token_response['url'] );
			wc_add_notice( __( 'Unable to connect to Peach Payments. Please try again.', 'woocommerce-gateway-peach-payments' ), 'error' );
			return [ 'result' => 'failure' ];
		}
		$access_token = $token_response['access_token'];
	
		// Get the order total
		$total = $order->get_total();
		$currency = $order->get_currency();
		$order_key = $order->get_order_key();
	
		// Get WooCommerce order number (consider plugin settings)
		$order_number = strval(PP_Gateway_Order_Utils::find_converted_number( $order_id, true ));
		$order_number = strval(PP_Gateway_Order_Utils::order_number_prep( $order_number ));
		
		$nonce = PP_Gateway_Order_Utils::create_nonce( $order );
		$return_token = wp_generate_password( 32, false );
		
		$order->update_meta_data( '_peach_return_token', $return_token );
		$order->update_meta_data( '_peach_expected_merchant_transaction_id', $order_number );
		$order->update_meta_data( '_peach_expected_amount', number_format( (float) $total, 2, '.', '' ) );
		$order->update_meta_data( '_peach_expected_currency', $currency );
		$order->save();
		
		$entity_id = strval(PP_Gateway_Settings::get('channel_3ds'));
		
		//New 3D Secure Rule. Address can't exceed 50 chars
		$billing_address = substr($order->get_billing_address_1(),0,50);
		$billing_address = str_replace('&', ' ',$billing_address);
		$billing_address = str_replace('.', '',$billing_address);
	
		// Prepare payload
		$payload = [
			'authentication.entityId' => $entity_id,
			'merchantTransactionId' => $order_number,
			'amount' => number_format( $total, 2, '.', '' ),
			'currency' => $currency,
			'nonce' => $nonce,
			//'shopperResultUrl' => $this->get_return_url( $order ),
			//'shopperResultUrl' => $order->get_checkout_payment_url( true ),
			//'shopperResultUrl' => $order->get_checkout_order_received_url(),
			'shopperResultUrl' => add_query_arg(
				[
					'wc-api' => 'WC_Gateway_Peach_Hosted',
					'order_id' => $order->get_id(),
					'key' => $order_key,
					'peach_return_token' => $return_token,
				],
				WC_PEACH_SITE_URL
			),
			'cancelUrl' => $order->get_cancel_order_url(),
			'merchantInvoiceId' => $order_number,
			'paymentType' => 'DB',
			'customer' => [
				'email' => $order->get_billing_email(),
				'surname' => str_replace(' ', '', $order->get_billing_last_name()),
				'givenName' => str_replace(' ', '', $order->get_billing_first_name())
			],
			'billing' => [
				'city' => $order->get_billing_city(),
				'country' => $order->get_billing_country(),
				'postcode' => $order->get_billing_postcode(),
				'street1' => $billing_address,
			],
			'customParameters' => [
				'PHP_VERSION' => WC_PEACH_PHP,
				'WORDPRESS_VERSION' => WC_PEACH_WP_VER,
				'WOOCOMMERCE_VERSION' => WC_PEACH_WC_VER,
				'WOO_SUBSCRIPTION_VERSION' => WC_PEACH_WC_SUB_VER,
				'PEACH_PLUGIN_VERSION' => WC_PEACH_VER,
				'INTEGRATION_METHOD' => 'Hosted',
				'PAYMENT_PLUGIN' => 'woocommerce',
			]
		];
		
		// Handle recurring flag if subscription
		if ( $is_subscription ) {
			$payload['defaultPaymentMethod'] = 'CARD';
			$payload['forceDefaultMethod']   = true;
			$payload['createRegistration']   = true;

			$si = self::get_standing_instruction_from_order( $order );

			$payload['standingInstruction'] = [
				'expiry'        => ! empty( $si['expiry'] ) ? $si['expiry'] : '9999-12-31',
				'frequency'     => ! empty( $si['frequency'] ) ? (int) $si['frequency'] : 30,
				'recurringType' => 'SUBSCRIPTION',
				'type'          => 'RECURRING',
				'mode'          => 'INITIAL',
			];
			
			/* Peach suggested we remove this */
			/*
			if ( is_array( $cardTokens ) && ! empty( $cardTokens ) ) {
				$payload['cardTokens'] = $cardTokens;
			}
			*/
		} else {
			// Check for Save Card Option
			if ( PP_Gateway_Settings::get('card_only') != 'no' ) {
				$payload['defaultPaymentMethod'] = 'CARD';
				$payload['forceDefaultMethod'] = true;
			}
			
			// Check for Save Card Option
			if ( PP_Gateway_Settings::get('card_storage') != 'no' ) {
				if ( is_user_logged_in() ) {
					$payload['allowStoringDetails'] = true;
					if(is_array($cardTokens) && !empty($cardTokens)){
						$payload['cardTokens'] = $cardTokens;
					}
				}
			}
		}
	
		// Call Peach API to create checkout session
		$response = WC_Gateway_Peach_Hosted::create_checkout_session( $access_token, $payload );
	
		if ( empty( $response['redirectUrl'] ) ) {
			self::log_error( 'Redirect URL ['.$order_id.']', $payload, $response, '' );
			$order->delete_meta_data( '_peach_return_token' );
			$order->save();
			$order->add_order_note( 'Peach API error: No redirect URL returned.' );
			wc_add_notice( __( 'Peach Payments error. Please try again or use a different payment method.', 'woocommerce-gateway-peach-payments' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		if ( ! empty( $response['id'] ) ) {
			$order->update_meta_data( '_peach_checkout_id', sanitize_text_field( (string) $response['id'] ) );
			$order->save();
		}
	
		return $response;
	}

	/**
	 * Charge a saved card (for subscription renewals).
	 *
	 * @param string   $registration_id The saved card token (registration ID).
	 * @param WC_Order $order           WooCommerce order object.
	 * @param float    $amount          Amount to charge.
	 *
	 * @return array|WP_Error
	 */
	public function charge_saved_card( $registration_id, $order, $amount ) {
		if ( empty( $registration_id ) || ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 'peach_invalid_data', __( 'Invalid data provided for token charge.', WC_PEACH_TEXT_DOMAIN ) );
		}
	
		$entity_id = PP_Gateway_Settings::get( 'channel' );
		
		if(!isset($entity_id) || $entity_id == ''){
			return new WP_Error( 'peach_invalid_data', __( 'Missing Recurring Entity ID.', WC_PEACH_TEXT_DOMAIN ) );
		}
		
		$currency  = $order->get_currency();
		$order_id = $order->get_id();
		$desc      = sprintf( 'Subscription renewal for Order #%d', $order_id );
		
		// Get WooCommerce order number (consider plugin settings)
		$order_number = strval(PP_Gateway_Order_Utils::find_converted_number( $order_id, true ));
		$order_number = strval(PP_Gateway_Order_Utils::order_number_prep( $order_number ));
	
		$data = http_build_query( [
			'merchantTransactionId'	=> $order_number, //Review 20250910
			'entityId'        => $entity_id,
			'amount'          => number_format( $amount, 2, '.', '' ),
			'currency'        => $currency,
			'paymentType'     => 'DB',
			'standingInstruction.mode'   => 'REPEATED',
			'standingInstruction.type'   => 'RECURRING',
			'standingInstruction.source'   => 'MIT',
			'standingInstruction.recurringType' => 'SUBSCRIPTION'
		] );
		
		if ( wcs_order_contains_renewal( $order_id ) ) {
			$parent_order_id = WC_Subscriptions_Renewal_Order::get_parent_order_id( $order_id );
		}else{
			$parent_order_id = $order_id;
		}
		$payment_initial_id = get_post_meta( $order_id, 'payment_initial_id', true );
		if ( ! empty( $payment_initial_id ) ) {
			$data .= "&standingInstruction.initialTransactionId=".$payment_initial_id;
		}else{
			$entityId = PP_Gateway_Settings::get( 'channel_3ds' );
			$accessToken = PP_Gateway_Settings::get( 'access_token' );
			$transactionID = get_post_meta( $parent_order_id, 'payment_order_id', true );
			
			$payment_initial_id = $this->getInitialID($accessToken, $entityId, $transactionID);
			
			if(!empty($payment_initial_id)){
				$data .= "&standingInstruction.initialTransactionId=".$payment_initial_id;
			}
		}
	
		$url = '/v1/registrations/' . urlencode( $registration_id ) . '/payments';
	
		$response = self::post_request( $url, $data );
	
		if ( is_wp_error( $response ) ) {
			return $response;
		}
	
		if ( empty( $response['id'] ) || !PP_Gateway_Order_Utils::is_successful_result_code($response['result']['code']) ) {
			$error = $response['result']['description'] ?? 'Unknown error';
			return new WP_Error( 'peach_payment_failed', __( 'Payment failed: ', WC_PEACH_TEXT_DOMAIN ) . $error );
		}
	
		return $response;
	}
	
	public static function getInitialID($accesstoken, $entityId, $transactionID){
		$full_url = self::get_endpoint_url();
		
		$url = $full_url.'v3/query/'.$transactionID.'?entityId='.$entityId;

		$headers = [
			'Authorization: Bearer '.$accesstoken,
			'Content-Type: application/x-www-form-urlencoded'
		];
		
		$data = http_build_query([
			'entityId' => $entityId
		]);
		
		//First Test
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_POSTFIELDS => $data,
		]);
		
		$responseData = curl_exec($ch);
		$response = json_decode($responseData);
		
		$payment_initial_id = $CardholderInitiatedTransactionID = '';
		
		if (!empty($response->records) && is_array($response->records)) {
			foreach ($response->records as $record) {
				if(!empty($record->resultDetails->CardholderInitiatedTransactionID)) {
					$payment_initial_id = $record->resultDetails->CardholderInitiatedTransactionID;
					break;
				}else if(!empty($record->standingInstruction->initialTransactionId)){
					$payment_initial_id = $record->standingInstruction->initialTransactionId;
					break;
				}
			}
		}
		
		return $payment_initial_id;
	}
	
	/**
	 * Perform a refund request via Peach Payments.
	 *
	 * @param string $transaction_id The original Peach Payments transaction ID.
	 * @param float  $amount         The refund amount.
	 * @param string $currency       The transaction currency (e.g., ZAR).
	 * @param string $reason         Optional refund reason.
	 *
	 * @return array|WP_Error        API response or WP_Error on failure.
	 */
	public static function refund_payment( $transaction_id, $amount, $currency, $reason = '' ) {
		if ( empty( $transaction_id ) || empty( $amount ) || empty( $currency ) ) {
			return new WP_Error( 'peach_refund_missing_data', __( 'Missing required refund data.', 'woocommerce-gateway-peach-payments' ) );
		}
		
		$url = 'https://api.peachpayments.com/v1/checkout/refund';
		if(PP_Gateway_Settings::get('transaction_mode') == 'INTEGRATOR_TEST'){
			$url = 'https://testapi.peachpayments.com/v1/checkout/refund';
		}
		
		$amount = number_format( (float) $amount, 2, '.', '' );
		
		$sig_string = 'amount'.$amount.'authentication.entityId'.PP_Gateway_Settings::get('channel_3ds').'currency'.$currency.'id'.$transaction_id.'paymentTypeRF';
		$secret = PP_Gateway_Settings::get('secret');
		$signature = hash_hmac('sha256', $sig_string, $secret);
		
		$payload = http_build_query([
			'amount' => $amount,
			'authentication.entityId' => PP_Gateway_Settings::get('channel_3ds'),
			'currency' => $currency,
			'id' => $transaction_id,
			'paymentType' => 'RF',
			'signature' => $signature,
		]);
		
		/*	
		if ( ! empty( $reason ) ) {
			$payload['customParameters'] = [ 'REFUND_REASON' => $reason ];
		}
		*/
	
		return self::post_request( $url, $payload, 'refund' );
	}

	/**
	 * Reverse (RV) a preauthorisation transaction.
	 *
	 * Peach Payments docs: POST /v1/payments/{id} with paymentType=RV.
	 *
	 * @param string $transaction_id PA transaction payment ID.
	 * @return array|WP_Error
	 */
	public static function reverse_preauthorisation( $transaction_id ) {
		$transaction_id = trim( (string) $transaction_id );


		if ( empty( $transaction_id ) ) {
			return new WP_Error( 'peach_reversal_missing_id', __( 'Missing reversal transaction ID.', 'woocommerce-gateway-peach-payments' ) );
		}

		$entity_id = PP_Gateway_Settings::get( 'channel_3ds' );
		$token     = self::get_auth_token();
		if ( empty( $entity_id ) || empty( $token ) ) {
			return new WP_Error( 'peach_reversal_missing_credentials', __( 'Missing Peach Payments credentials for reversal.', 'woocommerce-gateway-peach-payments' ) );
		}

		$url = self::get_base_url() . 'v1/payments/' . rawurlencode( $transaction_id );
		$payload = http_build_query(
			[
				'entityId'    => $entity_id,
				'paymentType' => 'RV',
			]
		);

		$headers = [
			'Authorization: Bearer ' . $token,
		];

		$transaction_mode = PP_Gateway_Settings::get( 'transaction_mode' );
		$ssl              = ( $transaction_mode === 'INTEGRATOR_TEST' ) ? false : true;


		
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::info( 'Reversal attempt started. Transaction ID: ' . $transaction_id );
		}
$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'POST' );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $ssl );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

		if ( curl_errno( $ch ) ) {
			$error_message = curl_error( $ch );
			$errno         = curl_errno( $ch );
			curl_close( $ch );

			self::log_error(
				'cURL error [' . $errno . '] while posting reversal: ' . $error_message,
				[ 'transaction_id' => $transaction_id, 'payload' => $payload ],
				null,
				$url
			);


			
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::error( 'Reversal request failed (cURL). Transaction ID: ' . $transaction_id . ' | Error: ' . $error_message );
		}
return new WP_Error( 'peach_api_curl_error', $error_message );
		}

		curl_close( $ch );
		$response_data = json_decode( (string) $response_body, true );

		if ( $response_code < 200 || $response_code >= 300 ) {
			self::log_error(
				'Reversal request failed (HTTP ' . (int) $response_code . ').',
				[ 'transaction_id' => $transaction_id, 'payload' => $payload ],
				$response_body,
				$url
			);


			
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::error( 'Reversal HTTP failure. Transaction ID: ' . $transaction_id . ' | HTTP Code: ' . (int) $response_code );
		}
return new WP_Error( 'peach_reversal_failed', __( 'Reversal request failed.', 'woocommerce-gateway-peach-payments' ) );
		}

		$result_code = is_array( $response_data ) ? ( $response_data['result']['code'] ?? '' ) : '';
		$result_desc = is_array( $response_data ) ? ( $response_data['result']['description'] ?? '' ) : '';

		// Treat any 000.* as success (covers different success codes per payment type).
		if ( ! empty( $result_code ) && 0 === strpos( $result_code, '000.' ) ) {
		
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::info( 'Reversal successful. Transaction ID: ' . $transaction_id . ' | Result: ' . $result_code . ( $result_desc ? ' - ' . $result_desc : '' ) );
		}
} else {
		
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::warning( 'Reversal not successful. Transaction ID: ' . $transaction_id . ' | Result: ' . ( $result_code ?: 'N/A' ) . ( $result_desc ? ' - ' . $result_desc : '' ) );
		}
}

		return is_array( $response_data ) ? $response_data : [];
	}

}
