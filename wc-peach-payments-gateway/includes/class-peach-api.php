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
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 90 );
		
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

			$this->log_error(
				"cURL error while {$method} request: {$error_msg}",
				$data,
				null,
				$url
			);
			return new WP_Error( 'peach_api_curl_error', $error_msg );
		}

		curl_close( $ch );

		$decoded = json_decode( $response, true );
		
		$result_code = isset( $decoded['result']['code'] ) ? (string) $decoded['result']['code'] : '';

		if ( $result_code === $success_code || PP_Gateway_Order_Utils::is_non_final_result_code( $result_code ) ) {
			return $decoded;
		}else{
			if ( $responseCode >= 400 ) {
				$this->log_error(
					"Unexpected HTTP response code {$responseCode}",
					$data,
					$decoded,
					$url
				);
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

		$result_code = isset( $response['result']['code'] ) ? trim( (string) $response['result']['code'] ) : '';

		// A successful HTTP response can still contain a rejected Peach business result.
		// Do not let the caller remove the local card unless Peach actually accepted
		// the deregistration. Terminal stale-registration codes are handled explicitly
		// by the saved-card deletion flow after subscription usage has been verified.
		if ( '' === $result_code || ! PP_Gateway_Order_Utils::is_successful_result_code( $result_code ) ) {
			$message = isset( $response['result']['description'] ) && '' !== trim( (string) $response['result']['description'] )
				? trim( (string) $response['result']['description'] )
				: __( 'Peach Payments could not deregister the saved card.', WC_PEACH_TEXT_DOMAIN );

			return new WP_Error( 'peach_registration_delete_failed', $message, $response );
		}

		return true;
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
	 * Log API-related errors with optional request/response context.
	 *
	 * @param string      $message  Error message to log.
	 * @param array|null  $request  Request payload (optional).
	 * @param array|null  $response Response payload (optional).
	 * @param string|null $url      Endpoint URL (optional).
	 */
	public static function log_error( $message, $request = null, $response = null, $url = null ) {
		if ( ! function_exists( 'wc_get_logger' ) || ! class_exists( 'PP_Gateway_Logger' ) ) {
			return;
		}

		$log = 'Peach API Error: ' . $message;

		if ( $url ) {
			$log .= "\nURL: " . $url;
		}

		if ( null !== $request ) {
			$log .= "\nRequest: " . print_r( PP_Gateway_Logger::sanitize_data( $request ), true );
		}

		if ( null !== $response ) {
			$log .= "\nResponse: " . print_r( PP_Gateway_Logger::sanitize_data( $response ), true );
		}

		// PP_Gateway_Logger is the single final write boundary. It sanitizes the
		// fully composed message again immediately before WooCommerce writes it.
		PP_Gateway_Logger::error( $log, 'peach-payments' );
	}

	/**
	 * Mask sensitive data in logs.
	 *
	 * Supports both array payloads and URL-encoded query strings.
	 *
	 * @param mixed $data Request data to mask.
	 * @return mixed
	 */
	public static function mask_sensitive_data( $data ) {
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			return PP_Gateway_Logger::sanitize_data( $data );
		}

		return $data;
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
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 90 );
		
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
			curl_setopt( $ch, CURLOPT_TIMEOUT, 90 );
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
			curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
			curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
			
		}
		
	
		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	
		$curl_errno = curl_errno( $ch );
		if ( $curl_errno ) {
			$error_message = curl_error( $ch );
			curl_close( $ch );
	
			self::log_error(
				"cURL error [{$curl_errno}] while posting to {$endpoint}: {$error_message}",
				$payload,
				null,
				$full_url
			);
	
			return new WP_Error( 'peach_api_curl_error', $error_message, [ '_curl_errno' => $curl_errno ] );
		}
	
		curl_close( $ch );
	
		$response_data = json_decode( $response_body, true );
		if ( ! is_array( $response_data ) ) {
			self::log_error( 'Peach API returned an invalid or empty JSON response.', $payload, null, $full_url );
			return new WP_Error( 'peach_api_invalid_response', __( 'Invalid response received from Peach Payments.', 'woocommerce-gateway-peach-payments' ) );
		}

		$result_code = isset( $response_data['result']['code'] ) ? trim( (string) $response_data['result']['code'] ) : ( isset( $response_data['result_code'] ) ? trim( (string) $response_data['result_code'] ) : '' );
		if ( '' !== $result_code && PP_Gateway_Order_Utils::is_successful_result_code( $result_code ) ) {
			return $response_data;
		} else {
			if ( $response_code < 200 || $response_code >= 300 ) {
				self::log_error(
					"Unexpected HTTP response code {$response_code} from {$endpoint}",
					$payload,
					$response_data,
					$full_url
				);
		
				$response_data['_http_status'] = $response_code;
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
		return class_exists( 'PP_Gateway_Logger' ) ? PP_Gateway_Logger::sanitize_data( $data ) : $data;
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
	 * Get the full base URL for the Peach Checkout V2 API.
	 *
	 * @return string Fully qualified Checkout base URL ending with a slash.
	 */
	public static function get_checkout_endpoint_url() {
		$test_mode = 'INTEGRATOR_TEST' === PP_Gateway_Settings::get( 'transaction_mode' );

		return $test_mode ? 'https://testsecure.peachpayments.com/' : 'https://secure.peachpayments.com/';
	}


	/**
	 * Extract a Checkout V2 checkoutId from a Peach checkout-session response.
	 *
	 * Peach has returned both `id` and `checkoutId` shapes across hosted flows,
	 * so keep this centralised and tolerant while still validating the value
	 * before storing it against an order.
	 *
	 * @param array $response Peach checkout-session response.
	 * @return string Sanitized checkout ID, or an empty string when unavailable.
	 */
	public static function get_checkout_id_from_session_response( array $response ) {
		$candidate = self::get_first_response_value(
			$response,
			[
				'checkoutId',
				'checkout_id',
				'id',
				[ 'checkout', 'id' ],
				[ 'checkout', 'checkoutId' ],
				[ 'payload', 'checkoutId' ],
				[ 'payload', 'checkout', 'id' ],
			]
		);

		if ( '' !== (string) $candidate ) {
			$checkout_id = self::normalise_checkout_id( $candidate );
			if ( ! is_wp_error( $checkout_id ) ) {
				return $checkout_id;
			}
		}

		$redirect_url = ! empty( $response['redirectUrl'] ) ? (string) $response['redirectUrl'] : '';
		if ( '' !== $redirect_url ) {
			$parts = wp_parse_url( $redirect_url );

			if ( ! empty( $parts['query'] ) ) {
				$query_args = [];
				parse_str( $parts['query'], $query_args );

				foreach ( [ 'checkoutId', 'checkout_id', 'id' ] as $query_key ) {
					if ( empty( $query_args[ $query_key ] ) ) {
						continue;
					}

					$checkout_id = self::normalise_checkout_id( $query_args[ $query_key ] );
					if ( ! is_wp_error( $checkout_id ) ) {
						return $checkout_id;
					}
				}
			}

			if ( ! empty( $parts['path'] ) && preg_match( '#/checkout/([A-Za-z0-9.\-]+)#', $parts['path'], $matches ) ) {
				$checkout_id = self::normalise_checkout_id( $matches[1] );
				if ( ! is_wp_error( $checkout_id ) ) {
					return $checkout_id;
				}
			}
		}

		return '';
	}

	/**
	 * Sanitize a Checkout V2 checkoutId before using it in a server-to-server call.
	 *
	 * @param string $checkout_id Peach Checkout V2 checkout ID.
	 * @return string|WP_Error
	 */
	private static function normalise_checkout_id( $checkout_id ) {
		$checkout_id = trim( (string) wp_unslash( $checkout_id ) );
		$checkout_id = sanitize_text_field( $checkout_id );

		if ( '' === $checkout_id ) {
			return new WP_Error( 'peach_missing_checkout_id', __( 'Missing Peach Payments checkout ID.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( strlen( $checkout_id ) > 64 || ! preg_match( '/^[A-Za-z0-9.\-]+$/', $checkout_id ) ) {
			return new WP_Error( 'peach_invalid_checkout_id', __( 'Invalid Peach Payments checkout ID.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return $checkout_id;
	}

	/**
	 * Validate that a Checkout V2 checkoutId belongs to the WooCommerce order.
	 *
	 * @param WC_Order $order       WooCommerce order.
	 * @param string   $checkout_id Peach Checkout V2 checkout ID.
	 * @return true|WP_Error
	 */
	public static function validate_checkout_id_for_order( WC_Order $order, $checkout_id ) {
		$checkout_id = self::normalise_checkout_id( $checkout_id );
		if ( is_wp_error( $checkout_id ) ) {
			return $checkout_id;
		}

		$expected_checkout_id = trim( (string) $order->get_meta( '_peach_checkout_id', true ) );

		if ( '' === $expected_checkout_id ) {
			return new WP_Error( 'peach_missing_stored_checkout_id', __( 'The WooCommerce order is missing the Peach checkout ID needed for verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( ! hash_equals( $expected_checkout_id, (string) $checkout_id ) ) {
			return new WP_Error( 'peach_checkout_mismatch', __( 'Payment verification failed because the Peach checkout ID did not match the WooCommerce order.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return true;
	}

	/**
	 * Normalise a Peach payload signature value before comparison.
	 *
	 * @param string $signature Signature value.
	 * @return string
	 */
	private static function normalise_hosted_return_signature( $signature ) {
		$signature = trim( (string) $signature );
		$signature = preg_replace( '/^sha256=/i', '', $signature );
		return strtolower( trim( (string) $signature ) );
	}

	/**
	 * Retrieve configured secrets that may validate Peach's payload-level Checkout
	 * return signature field. This mirrors the webhook payload signature logic.
	 *
	 * @return array<string,string> Labelled non-empty secrets.
	 */
	private static function get_hosted_return_signature_secrets() {
		$candidates = [
			'Secret Token setting'  => PP_Gateway_Settings::get( 'secret' ),
			'Client Secret setting' => PP_Gateway_Settings::get( 'embed_clientsecret' ),
		];

		$secrets = [];
		foreach ( $candidates as $label => $secret ) {
			$secret = is_string( $secret ) ? trim( $secret ) : '';
			if ( '' === $secret ) {
				continue;
			}

			if ( in_array( $secret, $secrets, true ) ) {
				continue;
			}

			$secrets[ $label ] = $secret;
		}

		return $secrets;
	}

	/**
	 * Flatten signature parameters into Peach's bracket notation for nested data.
	 *
	 * @param array  $input  Input data.
	 * @param string $prefix Current key prefix.
	 * @return array
	 */
	private static function flatten_hosted_return_signature_params( array $input, $prefix = '' ) {
		$result = [];

		foreach ( $input as $key => $value ) {
			$current_key = '' === $prefix ? (string) $key : $prefix . '[' . (string) $key . ']';

			if ( is_array( $value ) ) {
				$result = array_merge( $result, self::flatten_hosted_return_signature_params( $value, $current_key ) );
			} else {
				$result[ $current_key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Convert PHP-normalised form keys back to Peach's signature key format.
	 *
	 * @param string $key Flattened parameter key.
	 * @return string
	 */
	private static function convert_hosted_return_signature_key( $key ) {
		$key = (string) $key;

		if ( false !== strpos( $key, '[' ) ) {
			$prefix = substr( $key, 0, strpos( $key, '[' ) );
			$suffix = substr( $key, strpos( $key, '[' ) );
			return str_replace( '_', '.', $prefix ) . $suffix;
		}

		return str_replace( '_', '.', $key );
	}

	/**
	 * Build Peach's concatenated signature string from flattened fields.
	 *
	 * @param array $fields Flattened signature fields.
	 * @return string
	 */
	private static function build_hosted_return_signature_string( array $fields ) {
		$prepared = [];

		foreach ( $fields as $key => $value ) {
			$key = (string) $key;

			if ( 'signature' === $key ) {
				continue;
			}

			if ( null === $value || false === $value ) {
				$value = '';
			}

			$prepared[ $key ] = $value;
		}

		ksort( $prepared, SORT_STRING );

		$string_to_sign = '';
		foreach ( $prepared as $key => $value ) {
			$string_to_sign .= $key . (string) $value;
		}

		return $string_to_sign;
	}

	/**
	 * Calculate a hosted-return payload-level signature.
	 *
	 * @param array  $data                Payload data.
	 * @param string $secret              Signature secret.
	 * @param bool   $convert_underscores Whether to convert PHP-normalised underscores back to dot notation.
	 * @return string
	 */
	private static function calculate_hosted_return_signature( array $data, $secret, $convert_underscores = true ) {
		$flattened = self::flatten_hosted_return_signature_params( $data );
		$converted = [];

		foreach ( $flattened as $key => $value ) {
			$new_key = $convert_underscores ? self::convert_hosted_return_signature_key( $key ) : (string) $key;
			$converted[ $new_key ] = $value;
		}

		return hash_hmac( 'sha256', self::build_hosted_return_signature_string( $converted ), $secret );
	}

	/**
	 * Parse a URL-encoded body while preserving original form field names such as
	 * result.code. PHP's normal request parsing changes dots to underscores.
	 *
	 * @param string $raw_body Raw request body.
	 * @return array
	 */
	private static function parse_hosted_return_raw_form_body_preserving_keys( $raw_body ) {
		$raw_body = (string) $raw_body;
		$fields   = [];

		if ( '' === $raw_body || false === strpos( $raw_body, '=' ) ) {
			return $fields;
		}

		$pairs = explode( '&', $raw_body );
		foreach ( $pairs as $pair ) {
			if ( '' === $pair ) {
				continue;
			}

			$parts = explode( '=', $pair, 2 );
			$key   = urldecode( str_replace( '+', ' ', $parts[0] ) );
			$value = isset( $parts[1] ) ? urldecode( str_replace( '+', ' ', $parts[1] ) ) : '';

			if ( '' === $key ) {
				continue;
			}

			$fields[ $key ] = $value;
		}

		return $fields;
	}

	/**
	 * Calculate all supported hosted-return payload signature variants.
	 *
	 * @param array  $data     Parsed hosted-return payload data.
	 * @param string $secret   Signature secret.
	 * @param string $raw_body Raw request body where available.
	 * @return array
	 */
	private static function calculate_hosted_return_signature_variants( array $data, $secret, $raw_body = '' ) {
		$variants = [
			'parsed-dot-normalised' => self::calculate_hosted_return_signature( $data, $secret, true ),
			'parsed-exact'          => self::calculate_hosted_return_signature( $data, $secret, false ),
		];

		$raw_fields = self::parse_hosted_return_raw_form_body_preserving_keys( $raw_body );
		if ( ! empty( $raw_fields ) ) {
			$variants['raw-exact'] = hash_hmac( 'sha256', self::build_hosted_return_signature_string( $raw_fields ), $secret );

			$raw_dot_fields = [];
			foreach ( $raw_fields as $key => $value ) {
				$raw_dot_fields[ self::convert_hosted_return_signature_key( $key ) ] = $value;
			}
			$variants['raw-dot-normalised'] = hash_hmac( 'sha256', self::build_hosted_return_signature_string( $raw_dot_fields ), $secret );
		}

		return array_unique( $variants );
	}

	/**
	 * Validate Peach's payload-level hosted-return signature field. Used before
	 * trusting the browser return POST as the final checkout result.
	 *
	 * @param array  $data     Hosted-return payload data.
	 * @param string $raw_body Raw request body, where available.
	 * @param int    $order_id WooCommerce order ID for logging.
	 * @return true|WP_Error
	 */
	public static function verify_hosted_return_payload_signature( array $data, $raw_body = '', $order_id = 0 ) {
		$secrets = self::get_hosted_return_signature_secrets();

		if ( empty( $secrets ) ) {
			PP_Gateway_Logger::error( 'Peach hosted return signature validation failed for order #' . absint( $order_id ) . ' - missing Secret Token / Client Secret.' );
			return new WP_Error( 'peach_missing_hosted_return_signature_secret', 'missing Peach hosted return payload signature secret' );
		}

		$received_signature = '';
		if ( isset( $data['signature'] ) && '' !== $data['signature'] ) {
			$received_signature = trim( (string) $data['signature'] );
		}

		if ( '' === $received_signature ) {
			PP_Gateway_Logger::warning( 'Peach hosted return signature validation failed for order #' . absint( $order_id ) . ' - missing signature field.' );
			return new WP_Error( 'peach_missing_hosted_return_payload_signature', 'missing Peach hosted return payload signature' );
		}

		$received_signature = self::normalise_hosted_return_signature( $received_signature );
		$matched_variant    = '';
		$matched_secret     = '';

		foreach ( $secrets as $secret_label => $secret ) {
			$variants = self::calculate_hosted_return_signature_variants( $data, $secret, $raw_body );

			foreach ( $variants as $variant_name => $calculated_signature ) {
				if ( hash_equals( $calculated_signature, $received_signature ) ) {
					$matched_variant = (string) $variant_name;
					$matched_secret  = (string) $secret_label;
					break 2;
				}
			}
		}

		if ( '' !== $matched_variant ) {
			PP_Gateway_Logger::info( 'Peach hosted return signature validation passed for order #' . absint( $order_id ) . ' using ' . sanitize_text_field( $matched_secret ) . ' / ' . sanitize_text_field( $matched_variant ) . '.' );
			return true;
		}

		PP_Gateway_Logger::warning( 'Peach hosted return signature validation failed for order #' . absint( $order_id ) . ' - invalid signature.' );
		return new WP_Error( 'peach_invalid_hosted_return_payload_signature', 'invalid Peach hosted return payload signature' );
	}

	/**
	 * Build the payment result array used by existing order-processing logic from
	 * a signature-verified hosted-return POST payload.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param array    $return_payload    Signature-verified hosted-return payload.
	 * @param bool     $enforce_checkout_id Whether the checkoutId must match the latest locally stored checkout ID.
	 * @return array|WP_Error
	 */
	public static function normalise_signed_hosted_return_result_for_order( WC_Order $order, array $return_payload, $enforce_checkout_id = true ) {
		$checkout_id = self::get_first_response_value( $return_payload, [ 'checkoutId', 'checkout_id' ] );
		if ( '' !== (string) $checkout_id ) {
			$checkout_check = self::validate_checkout_id_for_order( $order, $checkout_id );
			if ( is_wp_error( $checkout_check ) ) {
				if ( $enforce_checkout_id ) {
					return $checkout_check;
				}

				PP_Gateway_Logger::warning( 'Peach hosted return for order #' . $order->get_id() . ' contained a signature-verified checkoutId that did not match the latest locally stored checkoutId. Continuing because the Peach payload signature passed; merchant reference, amount and currency will still be validated against the WooCommerce order.' );
			}
		}

		$result = $return_payload;

		$result_code = self::get_first_response_value( $return_payload, [ [ 'result', 'code' ], 'result_code', 'result.code' ] );
		if ( '' === (string) $result_code ) {
			return new WP_Error( 'peach_missing_result_code', __( 'Missing Peach Payments result code.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
			$result['result'] = [];
		}

		$result['result']['code'] = sanitize_text_field( (string) $result_code );
		$result['result_code']    = sanitize_text_field( (string) $result_code );

		$result_description = self::get_first_response_value( $return_payload, [ [ 'result', 'description' ], 'result_description', 'result.description' ] );
		if ( '' !== (string) $result_description ) {
			$result['result']['description'] = sanitize_text_field( (string) $result_description );
			$result['result_description']    = sanitize_text_field( (string) $result_description );
		}

		$fields = [
			'checkoutId'            => [ 'checkoutId', 'checkout_id' ],
			'merchantTransactionId' => [ 'merchantTransactionId', 'merchant_transaction_id' ],
			'merchantInvoiceId'     => [ 'merchantInvoiceId', 'merchant_invoice_id' ],
			'amount'                => [ 'amount' ],
			'currency'              => [ 'currency' ],
			'id'                    => [ 'id', 'paymentId', 'payment_id' ],
			'registrationId'        => [ 'registrationId', 'registration_id' ],
			'paymentBrand'          => [ 'paymentBrand', 'payment_brand' ],
			'paymentType'           => [ 'paymentType', 'payment_type' ],
			'card_last4Digits'      => [ 'card_last4Digits', 'card.last4Digits' ],
			'card_holder'           => [ 'card_holder', 'card.holder' ],
			'card_expiryMonth'      => [ 'card_expiryMonth', 'card.expiryMonth' ],
			'card_expiryYear'       => [ 'card_expiryYear', 'card.expiryYear' ],
		];

		foreach ( $fields as $target_key => $paths ) {
			$value = self::get_first_response_value( $return_payload, $paths );
			$result = self::set_missing_checkout_status_value( $result, $target_key, $value );
		}

		PP_Gateway_Logger::info( 'Peach hosted return signature-verified normalised result for order #' . $order->get_id() . ': ' . print_r( $result, true ) );

		return $result;
	}

	/**
	 * Log Checkout V2 hosted-return debug data for this temporary live Apple Pay
	 * verification path. The bearer token is intentionally never logged.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 * @param array  $context Context values.
	 * @return void
	 */
	private static function log_checkout_v2_return_debug( $level, $message, array $context = [] ) {
		$log = $message;

		if ( ! empty( $context ) ) {
			$log .= "\n" . print_r( $context, true );
		}

		switch ( strtolower( (string) $level ) ) {
			case 'error':
				PP_Gateway_Logger::error( $log );
				break;
			case 'warning':
				PP_Gateway_Logger::warning( $log );
				break;
			case 'debug':
				PP_Gateway_Logger::debug( $log );
				break;
			case 'info':
			default:
				PP_Gateway_Logger::info( $log );
				break;
		}
	}

	/**
	 * Query Peach Checkout V2 status by checkoutId for hosted-return verification.
	 * This is used when the Checkout V2 hosted return does not include the legacy
	 * resourcePath parameter.
	 *
	 * @param string $checkout_id     Peach Checkout V2 checkout ID.
	 * @param int    $order_id        WooCommerce order ID for logging.
	 * @param array  $return_payload  Parsed return POST payload for debug logging.
	 * @param string $raw_return_body Raw return POST body for debug logging.
	 * @return array|WP_Error
	 */
	public static function get_checkout_status_from_checkout_id( $checkout_id, $order_id = 0, array $return_payload = [], $raw_return_body = '' ) {
		$checkout_id = self::normalise_checkout_id( $checkout_id );
		if ( is_wp_error( $checkout_id ) ) {
			return $checkout_id;
		}

		$token_response = WC_Gateway_Peach_Hosted::generate_access_token();
		$token          = ! empty( $token_response['access_token'] ) ? trim( (string) $token_response['access_token'] ) : '';
		$token_source   = 'checkout_oauth_token';

		if ( '' === $token ) {
			$token_response_log = isset( $token_response['raw'] ) && is_array( $token_response['raw'] ) ? $token_response['raw'] : [];
			foreach ( [ 'access_token', 'token', 'refresh_token', 'id_token' ] as $sensitive_key ) {
				if ( isset( $token_response_log[ $sensitive_key ] ) ) {
					$token_response_log[ $sensitive_key ] = '***not logged***';
				}
			}

			$token_request_log = isset( $token_response['body'] ) && is_array( $token_response['body'] ) ? $token_response['body'] : [];
			if ( isset( $token_request_log['clientSecret'] ) ) {
				$token_request_log['clientSecret'] = '***not logged***';
			}

			self::log_checkout_v2_return_debug(
				'warning',
				'Peach hosted return Checkout V2 status could not generate a Checkout OAuth token; falling back to the configured Access Token if available.',
				[
					'order_id'              => (int) $order_id,
					'checkoutId'            => $checkout_id,
					'oauth_token_url'       => isset( $token_response['url'] ) ? $token_response['url'] : '',
					'oauth_token_request'   => $token_request_log,
					'oauth_token_response'  => $token_response_log,
				]
			);

			$token        = trim( (string) self::get_auth_token() );
			$token_source = 'configured_access_token_fallback';
		}

		if ( '' === $token ) {
			return new WP_Error( 'peach_missing_credentials', __( 'Missing Peach Payments credentials for payment verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$url             = self::get_checkout_endpoint_url() . 'v2/checkout/' . rawurlencode( $checkout_id ) . '/status';
		$referer         = get_site_url();
		$transaction_mode = PP_Gateway_Settings::get( 'transaction_mode' );
		$ssl              = ( 'INTEGRATOR_TEST' === $transaction_mode ) ? false : true;

		$perform_checkout_status_request = function( array $headers, array $logged_headers, $attempt_label ) use ( $url, $ssl, $checkout_id, $order_id, $return_payload, $raw_return_body, $token_source ) {
			$request_log = [
				'order_id'             => (int) $order_id,
				'method'               => 'GET',
				'url'                  => $url,
				'attempt'              => $attempt_label,
				'token_source'         => $token_source,
				'headers'              => $logged_headers,
				'request_payload'      => [
					'checkoutId' => $checkout_id,
				],
				'return_post_payload'  => $return_payload,
				'raw_return_post_body' => (string) $raw_return_body,
			];

			self::log_checkout_v2_return_debug(
				'info',
				'Peach hosted return Checkout V2 status request.',
				$request_log
			);

			$ch = curl_init();
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
			curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'GET' );
			curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $ssl );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
			curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );

			$response_body = curl_exec( $ch );
			$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

			if ( curl_errno( $ch ) ) {
				$error_message = curl_error( $ch );
				$errno         = curl_errno( $ch );
				curl_close( $ch );

				self::log_checkout_v2_return_debug(
					'error',
					'Peach hosted return Checkout V2 status cURL error.',
					[
						'order_id'        => (int) $order_id,
						'checkoutId'      => $checkout_id,
						'url'             => $url,
						'attempt'         => $attempt_label,
						'token_source'    => $token_source,
						'curl_errno'      => $errno,
						'curl_error'      => $error_message,
						'request_payload' => [ 'checkoutId' => $checkout_id ],
					]
				);

				return new WP_Error( 'peach_api_curl_error', $error_message );
			}

			curl_close( $ch );

			$response_data = json_decode( (string) $response_body, true );

			self::log_checkout_v2_return_debug(
				'info',
				'Peach hosted return Checkout V2 status response.',
				[
					'order_id'         => (int) $order_id,
					'checkoutId'       => $checkout_id,
					'url'              => $url,
					'attempt'          => $attempt_label,
					'token_source'     => $token_source,
					'http_code'        => (int) $response_code,
					'raw_response'     => (string) $response_body,
					'decoded_response' => $response_data,
				]
			);

			return [
				'http_code' => (int) $response_code,
				'raw'       => (string) $response_body,
				'decoded'   => $response_data,
			];
		};

		$authenticated_headers = [
			'Authorization: Bearer ' . $token,
			'Accept: application/json',
			'Content-Type: application/json',
			'Referer: ' . $referer,
		];

		$authenticated_logged_headers = [
			'Authorization' => 'Bearer ***not logged***',
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Referer'       => $referer,
		];

		$response = $perform_checkout_status_request( $authenticated_headers, $authenticated_logged_headers, 'authenticated_checkout_oauth' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) $response['http_code'] ) {
			self::log_checkout_v2_return_debug(
				'warning',
				'Peach hosted return Checkout V2 status returned 401 with Authorization header. Retrying once with the documented Accept-only status request.',
				[
					'order_id'    => (int) $order_id,
					'checkoutId'  => $checkout_id,
					'url'         => $url,
					'token_source'=> $token_source,
				]
			);

			$accept_only_headers = [
				'Accept: application/json',
				'Referer: ' . $referer,
			];

			$accept_only_logged_headers = [
				'Accept'  => 'application/json',
				'Referer' => $referer,
			];

			$response = $perform_checkout_status_request( $accept_only_headers, $accept_only_logged_headers, 'accept_only_retry' );

			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		if ( $response['http_code'] < 200 || $response['http_code'] >= 300 ) {
			return new WP_Error( 'peach_api_http_error', __( 'Payment verification failed at Peach Payments.', WC_PEACH_TEXT_DOMAIN ), $response['decoded'] );
		}

		if ( ! is_array( $response['decoded'] ) ) {
			return new WP_Error( 'peach_api_invalid_json', __( 'Invalid payment verification response from Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
		}

		return $response['decoded'];
	}

	/**
	 * Return a nested/flat Checkout V2 status response value.
	 *
	 * @param array $response Response data.
	 * @param array $paths    List of string keys or nested key arrays.
	 * @return mixed|null
	 */
	private static function get_checkout_status_value( array $response, array $paths ) {
		return self::get_first_response_value( $response, $paths );
	}

	/**
	 * Copy the first available value into a response path when the path is missing.
	 *
	 * @param array  $target Target response.
	 * @param string $key    Top-level target key.
	 * @param mixed  $value  Value to set.
	 * @return array
	 */
	private static function set_missing_checkout_status_value( array $target, $key, $value ) {
		if ( null !== $value && '' !== $value && ( ! isset( $target[ $key ] ) || '' === $target[ $key ] || null === $target[ $key ] ) ) {
			$target[ $key ] = $value;
		}

		return $target;
	}

	/**
	 * Build the payment result array used by existing order-processing logic from
	 * the verified Checkout V2 status response and the hosted-return POST.
	 *
	 * The status endpoint is the trusted server-to-server verification step. The
	 * hosted-return POST is only used to fill fields that the status response does
	 * not always repeat, after the checkoutId has been matched to the order.
	 *
	 * @param WC_Order $order          WooCommerce order.
	 * @param array    $status_result  Checkout V2 status response.
	 * @param array    $return_payload Hosted-return POST payload.
	 * @return array|WP_Error
	 */
	public static function normalise_checkout_v2_status_result_for_order( WC_Order $order, array $status_result, array $return_payload = [] ) {
		$posted_checkout_id = self::get_checkout_status_value( $return_payload, [ 'checkoutId', 'checkout_id' ] );
		$status_checkout_id = self::get_checkout_status_value(
			$status_result,
			[
				'checkoutId',
				'checkout_id',
				[ 'checkout', 'id' ],
				[ 'checkout', 'checkoutId' ],
				[ 'payload', 'checkoutId' ],
			]
		);

		$checkout_id = '' !== (string) $status_checkout_id ? $status_checkout_id : $posted_checkout_id;
		$checkout_check = self::validate_checkout_id_for_order( $order, $checkout_id );
		if ( is_wp_error( $checkout_check ) ) {
			return $checkout_check;
		}

		$result = $status_result;

		$posted_result_code = self::get_checkout_status_value( $return_payload, [ [ 'result', 'code' ], 'result_code', 'result.code' ] );
		$status_result_code = self::get_checkout_status_value( $status_result, [ [ 'result', 'code' ], 'result_code', 'result.code', [ 'payment', 'result', 'code' ], [ 'payload', 'result', 'code' ] ] );
		$status             = self::get_checkout_status_value( $status_result, [ 'status', [ 'checkout', 'status' ], [ 'payment', 'status' ], [ 'payload', 'status' ] ] );
		$status_normalised  = strtolower( trim( sanitize_text_field( (string) $status ) ) );
		$status_successful  = in_array( $status_normalised, [ 'successful', 'success', 'succeeded', 'completed', 'complete', 'paid' ], true );

		if ( '' !== (string) $status_result_code ) {
			$result_code = $status_result_code;
		} elseif ( $status_successful && '' !== (string) $posted_result_code ) {
			$result_code = $posted_result_code;
		} else {
			PP_Gateway_Logger::warning( 'Peach hosted return Checkout V2 status response for order #' . $order->get_id() . ' did not include a trusted final result code. Checkout status: ' . sanitize_text_field( (string) $status ) . '. Posted result code: ' . sanitize_text_field( (string) $posted_result_code ) . '. Response: ' . print_r( $status_result, true ) );
			return new WP_Error( 'peach_missing_result_code', __( 'Missing Peach Payments result code.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( ! isset( $result['result'] ) || ! is_array( $result['result'] ) ) {
			$result['result'] = [];
		}

		$result['result']['code'] = sanitize_text_field( (string) $result_code );
		$result['result_code']    = sanitize_text_field( (string) $result_code );

		$result_description = self::get_checkout_status_value( $status_result, [ [ 'result', 'description' ], 'result_description', 'result.description', [ 'payment', 'result', 'description' ], [ 'payload', 'result', 'description' ] ] );
		if ( '' === (string) $result_description ) {
			$result_description = self::get_checkout_status_value( $return_payload, [ [ 'result', 'description' ], 'result_description', 'result.description' ] );
		}
		if ( '' !== (string) $result_description ) {
			$result['result']['description'] = sanitize_text_field( (string) $result_description );
			$result['result_description']    = sanitize_text_field( (string) $result_description );
		}

		$fields = [
			'checkoutId'            => [ 'checkoutId', 'checkout_id', [ 'checkout', 'id' ], [ 'checkout', 'checkoutId' ], [ 'payload', 'checkoutId' ] ],
			'merchantTransactionId' => [ 'merchantTransactionId', 'merchant_transaction_id', [ 'checkout', 'merchantTransactionId' ], [ 'payment', 'merchantTransactionId' ], [ 'payload', 'merchantTransactionId' ] ],
			'merchantInvoiceId'     => [ 'merchantInvoiceId', 'merchant_invoice_id', [ 'checkout', 'merchantInvoiceId' ], [ 'payment', 'merchantInvoiceId' ], [ 'payload', 'merchantInvoiceId' ] ],
			'amount'                => [ 'amount', [ 'checkout', 'amount' ], [ 'payment', 'amount' ], [ 'payload', 'amount' ] ],
			'currency'              => [ 'currency', [ 'checkout', 'currency' ], [ 'payment', 'currency' ], [ 'payload', 'currency' ] ],
			'id'                    => [ 'paymentId', 'payment_id', [ 'payment', 'id' ], [ 'payload', 'id' ], [ 'payload', 'paymentId' ], [ 'payload', 'payment', 'id' ], [ 'transaction', 'id' ] ],
			'registrationId'        => [ 'registrationId', 'registration_id', [ 'payment', 'registrationId' ], [ 'payload', 'registrationId' ], [ 'payload', 'payment', 'registrationId' ] ],
			'paymentBrand'          => [ 'paymentBrand', 'payment_brand', [ 'payment', 'paymentBrand' ], [ 'payload', 'paymentBrand' ] ],
			'paymentType'           => [ 'paymentType', 'payment_type', [ 'payment', 'paymentType' ], [ 'payload', 'paymentType' ] ],
			'payment_initial_id'    => [ 'payment_initial_id', 'cardholderInitiatedTransactionId', [ 'resultDetails', 'CardholderInitiatedTransactionID' ], 'resultDetails.CardholderInitiatedTransactionID', [ 'standingInstruction', 'initialTransactionId' ], 'standingInstruction.initialTransactionId', [ 'payment', 'cardholderInitiatedTransactionId' ], [ 'payment', 'resultDetails', 'CardholderInitiatedTransactionID' ], [ 'payment', 'standingInstruction', 'initialTransactionId' ], [ 'payload', 'cardholderInitiatedTransactionId' ], [ 'payload', 'resultDetails', 'CardholderInitiatedTransactionID' ], [ 'payload', 'standingInstruction', 'initialTransactionId' ], [ 'payload', 'payment', 'cardholderInitiatedTransactionId' ], [ 'payload', 'payment', 'resultDetails', 'CardholderInitiatedTransactionID' ], [ 'payload', 'payment', 'standingInstruction', 'initialTransactionId' ] ],
			'card_last4Digits'      => [ 'card_last4Digits', 'card.last4Digits', [ 'card', 'last4Digits' ], [ 'payment', 'card', 'last4Digits' ], [ 'payload', 'card', 'last4Digits' ] ],
			'card_holder'           => [ 'card_holder', 'card.holder', [ 'card', 'holder' ], [ 'payment', 'card', 'holder' ], [ 'payload', 'card', 'holder' ], [ 'payload', 'payment', 'card', 'holder' ] ],
			'card_expiryMonth'      => [ 'card_expiryMonth', 'card.expiryMonth', [ 'card', 'expiryMonth' ], [ 'payment', 'card', 'expiryMonth' ], [ 'payload', 'card', 'expiryMonth' ] ],
			'card_expiryYear'       => [ 'card_expiryYear', 'card.expiryYear', [ 'card', 'expiryYear' ], [ 'payment', 'card', 'expiryYear' ], [ 'payload', 'card', 'expiryYear' ] ],
		];

		foreach ( $fields as $target_key => $paths ) {
			$value = self::get_checkout_status_value( $status_result, $paths );
			if ( null === $value || '' === $value ) {
				$value = self::get_checkout_status_value( $return_payload, $paths );
			}
			$result = self::set_missing_checkout_status_value( $result, $target_key, $value );
		}

		if ( empty( $result['id'] ) ) {
			$posted_payment_id = self::get_checkout_status_value( $return_payload, [ 'id', 'paymentId', 'payment_id' ] );
			$result = self::set_missing_checkout_status_value( $result, 'id', $posted_payment_id );
		}

		PP_Gateway_Logger::info( 'Peach hosted return Checkout V2 normalised result for order #' . $order->get_id() . ': ' . print_r( $result, true ) );

		return $result;
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

		$is_pending_change = class_exists( 'PP_Gateway_Subscription_Handler' ) && PP_Gateway_Subscription_Handler::is_pending_customer_payment_method_change( $order );
		if ( 'peach-payments' !== $order->get_payment_method() && ! $is_pending_change ) {
			return new WP_Error( 'peach_wrong_gateway', __( 'The WooCommerce order does not belong to the Peach Payments gateway.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$result_code = self::get_first_response_value( $response, [ [ 'result', 'code' ], 'result_code', 'result.code', [ 'payment', 'result', 'code' ], [ 'payload', 'result', 'code' ] ] );
		if ( empty( $result_code ) ) {
			return new WP_Error( 'peach_missing_result_code', __( 'Missing Peach Payments result code.', WC_PEACH_TEXT_DOMAIN ) );
		}


		$expected_reference = self::get_expected_merchant_transaction_id( $order );
		$received_reference = self::get_first_response_value(
			$response,
			[
				'merchantTransactionId',
				'merchantInvoiceId',
				[ 'checkout', 'merchantTransactionId' ],
				[ 'checkout', 'merchantInvoiceId' ],
				[ 'payment', 'merchantTransactionId' ],
				[ 'payment', 'merchantInvoiceId' ],
				[ 'payload', 'merchantTransactionId' ],
				[ 'payload', 'merchantInvoiceId' ],
			]
		);

		$accepted_references = array_filter( array_unique( [
			$expected_reference,
			trim( (string) $order->get_meta( '_peach_renewal_attempt_reference', true ) ),
			trim( (string) $order->get_meta( '_peach_renewal_payment_unknown_reference', true ) ),
		] ) );
		$reference_matches = false;
		foreach ( $accepted_references as $accepted_reference ) {
			if ( self::merchant_references_match( $accepted_reference, (string) $received_reference ) ) { $reference_matches = true; break; }
		}
		if ( ! $reference_matches ) {
			return new WP_Error( 'peach_merchant_reference_mismatch', __( 'Peach Payments merchant reference did not match the WooCommerce order or a known Peach renewal attempt.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$received_amount   = self::get_first_response_value( $response, [ 'amount', [ 'checkout', 'amount' ], [ 'payment', 'amount' ], [ 'payload', 'amount' ], [ 'payload', 'payment', 'amount' ] ] );
		$received_currency = self::get_first_response_value( $response, [ 'currency', [ 'checkout', 'currency' ], [ 'payment', 'currency' ], [ 'payload', 'currency' ], [ 'payload', 'payment', 'currency' ] ] );

		if ( null === $received_amount || '' === $received_amount ) {
			return new WP_Error( 'peach_missing_amount', __( 'Missing Peach Payments amount for order verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( null === $received_currency || '' === $received_currency ) {
			return new WP_Error( 'peach_missing_currency', __( 'Missing Peach Payments currency for order verification.', WC_PEACH_TEXT_DOMAIN ) );
		}

		$stored_expected_amount   = trim( (string) $order->get_meta( '_peach_expected_amount', true ) );
		$stored_expected_currency = trim( (string) $order->get_meta( '_peach_expected_currency', true ) );
		$live_order_amount        = number_format( (float) $order->get_total(), 2, '.', '' );
		$live_order_currency      = strtoupper( (string) $order->get_currency() );

		$expected_amount = '' !== $stored_expected_amount
			? number_format( (float) str_replace( ',', '.', $stored_expected_amount ), 2, '.', '' )
			: $live_order_amount;
		$expected_currency = '' !== $stored_expected_currency
			? strtoupper( sanitize_text_field( $stored_expected_currency ) )
			: $live_order_currency;
		$received_amount   = number_format( (float) str_replace( ',', '.', (string) $received_amount ), 2, '.', '' );
		$received_currency = strtoupper( sanitize_text_field( (string) $received_currency ) );

		if ( '' !== $stored_expected_amount && $expected_amount !== $live_order_amount ) {
			PP_Gateway_Logger::info( 'Peach ' . sanitize_key( $context ) . ' verification for order #' . $order->get_id() . ' is using stored checkout amount ' . $expected_amount . ' instead of current order total ' . $live_order_amount . '.' );
		}

		if ( $expected_amount !== $received_amount ) {
			$amount_source = '' !== $stored_expected_amount ? '_peach_expected_amount checkout metadata' : 'current WooCommerce order total';
			PP_Gateway_Logger::error( 'Peach ' . sanitize_key( $context ) . ' amount verification failed for order #' . $order->get_id() . '. Expected ' . $expected_amount . ' from ' . $amount_source . ', received ' . $received_amount . '. Current order total: ' . $live_order_amount . '.' );
			return new WP_Error( 'peach_amount_mismatch', __( 'Peach Payments amount did not match the expected checkout amount.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( $expected_currency !== $received_currency ) {
			$currency_source = '' !== $stored_expected_currency ? '_peach_expected_currency checkout metadata' : 'current WooCommerce order currency';
			PP_Gateway_Logger::error( 'Peach ' . sanitize_key( $context ) . ' currency verification failed for order #' . $order->get_id() . '. Expected ' . $expected_currency . ' from ' . $currency_source . ', received ' . $received_currency . '. Current order currency: ' . $live_order_currency . '.' );
			return new WP_Error( 'peach_currency_mismatch', __( 'Peach Payments currency did not match the expected checkout currency.', WC_PEACH_TEXT_DOMAIN ) );
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

		// Customer-facing payments in this plugin are Peach Hosted Checkout only. Never
		// start a hosted debit for a renewal while an automatic recurring debit has an
		// unresolved outcome; two payment rails must not be active for the same renewal.
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && $order->get_meta( '_peach_renewal_payment_unknown', true ) ) {
			wc_add_notice( __( 'This renewal payment is still being verified with Peach Payments. Please do not pay it again yet.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			return new WP_Error( 'peach_renewal_payment_unresolved', __( 'A previous Peach renewal payment is still being verified.', WC_PEACH_TEXT_DOMAIN ) );
		}
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) && class_exists( 'PP_Gateway_Subscription_Handler' ) ) {
			// Serialize the start of Hosted Checkout against the automatic renewal worker.
			// Once this marker is persisted, the recurring worker will not start a debit
			// while the customer is completing Peach Hosted Checkout.
			if ( ! PP_Gateway_Subscription_Handler::acquire_renewal_payment_lock( $order ) ) {
				wc_add_notice( __( 'A Peach Payments renewal attempt is already being processed. Please wait before trying to pay this renewal.', WC_PEACH_TEXT_DOMAIN ), 'error' );
				return new WP_Error( 'peach_renewal_attempt_busy', __( 'A Peach renewal payment attempt is already in progress.', WC_PEACH_TEXT_DOMAIN ) );
			}
			$fresh = PP_Gateway_Order_Utils::get_fresh_order( $order_id );
			if ( $fresh && $fresh->get_meta( '_peach_renewal_payment_unknown', true ) ) {
				PP_Gateway_Subscription_Handler::release_renewal_payment_lock( $order_id );
				return new WP_Error( 'peach_renewal_payment_unresolved', __( 'A previous Peach renewal payment is still being verified.', WC_PEACH_TEXT_DOMAIN ) );
			}
			if ( $fresh ) { $order = $fresh; }
			$order->update_meta_data( '_peach_hosted_checkout_started', time() );
			$order->save();
			PP_Gateway_Subscription_Handler::release_renewal_payment_lock( $order_id );
		}
		
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
	
		// A WC_Subscription object reaches the gateway when an existing subscriber is
		// changing their payment method. Cancelling that hosted session must only
		// abandon the card change; it must never invoke WooCommerce's cancel-order
		// action against the subscription itself.
		$is_payment_method_change = function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order );
		$cancel_url               = $order->get_cancel_order_url_raw();

		if ( $is_payment_method_change ) {
			$cancel_url = add_query_arg(
				[
					'peach_change_cancelled' => '1',
					'subscription_id'       => $order_id,
					'_wpnonce'              => wp_create_nonce( 'peach_cancel_change_' . $order_id ),
				],
				PP_Gateway_Order_Utils::get_payment_method_change_return_url( $order, $cancel_url )
			);
		}

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
			'cancelUrl' => $cancel_url,
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

			// Peach Checkout V2 requires PA + createRegistration for zero-value tokenisation.
			// Keep normal paid subscription checkouts on the existing DB flow.
			if ( 0.0 === (float) $total ) {
				$payload['paymentType'] = 'PA';
			}

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
			$checkout_type          = $is_payment_method_change ? 'subscription payment-method change' : ( $is_subscription ? 'subscription checkout' : 'standard checkout' );
			$registration_flag      = ! empty( $payload['createRegistration'] ) ? 'true' : 'false';
			$payment_type            = isset( $payload['paymentType'] ) ? (string) $payload['paymentType'] : 'missing';

			PP_Gateway_Logger::error( 'Peach checkout session creation failed for order #' . $order_id . ' (' . $checkout_type . '): Peach returned no redirect URL. amount=' . number_format( (float) $total, 2, '.', '' ) . ', paymentType=' . $payment_type . ', createRegistration=' . $registration_flag . '. Response: ' . print_r( $response, true ) );
			self::log_error( 'Checkout session failed for order #' . $order_id . ' (' . $checkout_type . '): no redirect URL returned. amount=' . number_format( (float) $total, 2, '.', '' ) . ', paymentType=' . $payment_type . ', createRegistration=' . $registration_flag . '.', $payload, $response, '' );
			$order->delete_meta_data( '_peach_return_token' );
			$order->delete_meta_data( '_peach_hosted_checkout_started' );
			$order->save();
			$order->add_order_note( 'Peach API error: No redirect URL returned.' );
			wc_add_notice( __( 'Peach Payments error. Please try again or use a different payment method.', 'woocommerce-gateway-peach-payments' ), 'error' );
			return [ 'result' => 'failure' ];
		}

		$checkout_id = self::get_checkout_id_from_session_response( $response );
		if ( '' !== $checkout_id ) {
			$order->update_meta_data( '_peach_checkout_id', $checkout_id );
			$order->update_meta_data( '_peach_hosted_checkout_started', time() );
			$order->save();
		} else {
			PP_Gateway_Logger::warning( 'Peach checkout session response for order #' . $order_id . ' did not include a checkoutId/id for hosted-return verification. Response: ' . print_r( $response, true ) );
		}
	
		return $response;
	}

	/**
	 * Charge a saved card (for subscription renewals).
	 *
	 * @param string   $registration_id The saved card token (registration ID).
	 * @param WC_Order $order           WooCommerce order object.
	 * @param float    $amount          Amount to charge.
	 * @param string   $preferred_payment_initial_id Current subscription initial transaction ID when available.
	 *
	 * @return array|WP_Error
	 */
	public function charge_saved_card( $registration_id, $order, $amount, $preferred_payment_initial_id = '' ) {
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
		// Do not race a customer who is already in Peach Hosted Checkout for this renewal.
		// A hosted session is considered active for 30 minutes; callbacks may arrive later,
		// but no automatic debit is allowed while the customer is reasonably still paying.
		$hosted_started = absint( $order->get_meta( '_peach_hosted_checkout_started', true ) );
		if ( $hosted_started && ( time() - $hosted_started ) < 30 * MINUTE_IN_SECONDS ) {
			return new WP_Error( 'peach_hosted_checkout_in_progress', __( 'A Peach Hosted Checkout payment is already in progress for this renewal.', WC_PEACH_TEXT_DOMAIN ) );
		}

		// Peach Payments documents merchantTransactionId as 8-16 alphanumeric characters.
		// Use a separate immutable 16-character reference for each automatic debit attempt;
		// never overwrite the hosted-checkout expected reference.
		$attempt_reference = 'R' . strtoupper( substr( hash( 'sha256', wp_generate_uuid4() . '|' . $order_id . '|' . microtime( true ) ), 0, 15 ) );
		$order->update_meta_data( '_peach_renewal_attempt_reference', $attempt_reference );
		$order->update_meta_data( '_peach_renewal_attempt_started', time() );
		$order->save();
	
		$data = http_build_query( [
			'merchantTransactionId'	=> $attempt_reference,
			'merchantInvoiceId'      => $order_number,
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

		$payment_initial_id = trim( (string) $preferred_payment_initial_id );
		$initial_id_source  = '' !== $payment_initial_id ? 'subscription_context' : '';

		if ( '' === $payment_initial_id ) {
			$payment_initial_id = trim( (string) $order->get_meta( 'payment_initial_id', true ) );
			if ( '' !== $payment_initial_id ) {
				$initial_id_source = 'renewal_order';
			}
		}

		$parent_order = wc_get_order( $parent_order_id );
		if ( '' === $payment_initial_id && is_a( $parent_order, 'WC_Order' ) ) {
			$parent_registration_id = trim( (string) $parent_order->get_meta( 'payment_registration_id', true ) );
			if ( '' === $parent_registration_id ) {
				$parent_registration_id = trim( (string) $parent_order->get_meta( '_peach_subscription_payment_method', true ) );
			}

			if ( '' !== $parent_registration_id && $parent_registration_id !== $registration_id ) {
				PP_Gateway_Logger::warning( 'Peach renewal order #' . $order_id . ' skipped parent order COF metadata because the current registration ID differs from the parent registration ID. Current registration: ' . PP_Gateway_Logger::mask_identifier_for_log( $registration_id ) . '. Parent registration: ' . PP_Gateway_Logger::mask_identifier_for_log( $parent_registration_id ) . '. The renewal will continue without reusing the previous card initial transaction ID.' );
			} else {
				$payment_initial_id = trim( (string) $parent_order->get_meta( 'payment_initial_id', true ) );
				if ( '' !== $payment_initial_id ) {
					$initial_id_source = 'parent_order';
				} else {
					$entityId      = PP_Gateway_Settings::get( 'channel_3ds' );
					$accessToken   = PP_Gateway_Settings::get( 'access_token' );
					$transactionID = trim( (string) $parent_order->get_meta( '_peach_initial_id_reference_payment_id', true ) );
					// Backward compatibility: older orders predate the credential-specific reference.
					// A literal 'none' is written by Change Card when the selected credential has no
					// safe payment reference, preventing fallback to the historical old-card payment.
					if ( '' === $transactionID && ( ! method_exists( $parent_order, 'meta_exists' ) || ! $parent_order->meta_exists( '_peach_initial_id_reference_payment_id' ) ) ) {
						$transactionID = trim( (string) $parent_order->get_meta( 'payment_order_id', true ) );
					}
					if ( 'none' === $transactionID ) { $transactionID = ''; }

					if ( '' !== $transactionID ) {
						$payment_initial_id = $this->getInitialID( $accessToken, $entityId, $transactionID );
						if ( ! empty( $payment_initial_id ) ) {
							$initial_id_source = 'parent_order_query';
						}
					}
				}
			}
		}

		if ( ! empty( $payment_initial_id ) ) {
			$data .= '&standingInstruction.initialTransactionId=' . urlencode( $payment_initial_id );
			PP_Gateway_Logger::info( 'Peach renewal order #' . $order_id . ' using initial transaction ID from ' . $initial_id_source . ': ' . PP_Gateway_Logger::mask_identifier_for_log( $payment_initial_id ) . '.' );
		} else {
			PP_Gateway_Logger::warning( 'Peach renewal order #' . $order_id . ' has no resolved payment_initial_id for registration ' . PP_Gateway_Logger::mask_identifier_for_log( $registration_id ) . '. The recurring request will continue without standingInstruction.initialTransactionId to preserve legacy behaviour.' );
		}
	
		$url = '/v1/registrations/' . urlencode( $registration_id ) . '/payments';
	
		$response = self::post_request( $url, $data );
	
		if ( is_wp_error( $response ) ) {
			return $response;
		}
	
		$result_code = isset( $response['result']['code'] ) ? trim( (string) $response['result']['code'] ) : '';
		if ( PP_Gateway_Order_Utils::is_non_final_result_code( $result_code ) ) {
			// Preserve pending/indeterminate gateway responses so the caller can block
			// automatic retries without incorrectly classifying the charge as declined.
			return $response;
		}

		if ( empty( $response['id'] ) || ! PP_Gateway_Order_Utils::is_successful_result_code( $result_code ) ) {
			$error = $response['result']['description'] ?? 'Unknown error';
			return new WP_Error( 'peach_payment_failed', __( 'Payment failed: ', WC_PEACH_TEXT_DOMAIN ) . $error, $response );
		}
	
		return $response;
	}
	
	public static function query_recurring_transaction_by_merchant_reference( $merchant_transaction_id, $order = null, $known_transaction_id = '', $unknown_since = 0 ) {
		$merchant_transaction_id = trim( (string) $merchant_transaction_id );
		if ( '' === $merchant_transaction_id ) { return new WP_Error( 'peach_query_missing_reference', __( 'Missing Peach merchant transaction reference.', WC_PEACH_TEXT_DOMAIN ) ); }
		$entity_id = trim( (string) PP_Gateway_Settings::get( 'channel' ) );
		$token     = trim( (string) PP_Gateway_Settings::get( 'access_token' ) );
		if ( '' === $entity_id || '' === $token ) { return new WP_Error( 'peach_query_configuration', __( 'Peach recurring query credentials are incomplete.', WC_PEACH_TEXT_DOMAIN ) ); }
		$url = self::get_endpoint_url() . 'v3/query?' . http_build_query( [ 'merchantTransactionId' => $merchant_transaction_id, 'entityId' => $entity_id ] );
		$ch = curl_init();
		curl_setopt_array( $ch, [ CURLOPT_URL => $url, CURLOPT_HTTPHEADER => [ 'Authorization: Bearer ' . $token, 'Accept: application/json' ], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60 ] );
		$body = curl_exec( $ch ); $errno = curl_errno( $ch ); $error = curl_error( $ch ); $http = curl_getinfo( $ch, CURLINFO_HTTP_CODE ); curl_close( $ch );
		if ( $errno ) { return new WP_Error( 'peach_query_curl_error', $error, [ '_curl_errno' => $errno ] ); }
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) { return new WP_Error( 'peach_query_invalid_response', __( 'Invalid response received while reconciling Peach transaction.', WC_PEACH_TEXT_DOMAIN ) ); }
		if ( $http < 200 || $http >= 300 ) { return new WP_Error( 'peach_query_http_error', __( 'Peach transaction reconciliation query failed.', WC_PEACH_TEXT_DOMAIN ), [ '_http_status' => $http, 'response' => $decoded ] ); }

		// The query wrapper has its own result code (for example 000.000.100) and is
		// not a payment. Only inspect actual transaction records returned by Peach.
		$payments = [];
		foreach ( [ 'payments', 'records', 'transactions' ] as $key ) {
			if ( ! empty( $decoded[ $key ] ) && is_array( $decoded[ $key ] ) ) {
				foreach ( $decoded[ $key ] as $item ) { if ( is_array( $item ) ) { $payments[] = $item; } }
			}
		}
		$known_transaction_id = trim( (string) $known_transaction_id );
		$since = max( 0, absint( $unknown_since ) - 300 );
		$expected_amount = is_a( $order, 'WC_Order' ) ? wc_format_decimal( $order->get_total(), 2 ) : '';
		$expected_currency = is_a( $order, 'WC_Order' ) ? strtoupper( (string) $order->get_currency() ) : '';
		$matches = [];
		foreach ( $payments as $item ) {
			$ref = trim( (string) ( $item['merchantTransactionId'] ?? '' ) );
			$id  = trim( (string) ( $item['id'] ?? '' ) );
			if ( '' === $ref || '' === $id || ! hash_equals( $merchant_transaction_id, $ref ) ) { continue; }
			if ( 'DB' !== strtoupper( trim( (string) ( $item['paymentType'] ?? '' ) ) ) ) { continue; }
			if ( '' !== $known_transaction_id && ! hash_equals( $known_transaction_id, $id ) ) { continue; }
			$timestamp = ! empty( $item['timestamp'] ) ? strtotime( (string) $item['timestamp'] ) : false;
			if ( $since && $timestamp && $timestamp < $since ) { continue; }
			if ( '' !== $expected_amount && isset( $item['amount'] ) && wc_format_decimal( $item['amount'], 2 ) !== $expected_amount ) { continue; }
			if ( '' !== $expected_currency && ! empty( $item['currency'] ) && strtoupper( (string) $item['currency'] ) !== $expected_currency ) { continue; }
			$matches[] = $item;
		}
		if ( empty( $matches ) ) { return new WP_Error( 'peach_query_not_found', __( 'Peach transaction was not found during reconciliation.', WC_PEACH_TEXT_DOMAIN ) ); }
		usort( $matches, static function( $a, $b ) { return strtotime( $b['timestamp'] ?? '' ) <=> strtotime( $a['timestamp'] ?? '' ); } );
		foreach ( $matches as $item ) { if ( PP_Gateway_Order_Utils::is_successful_result_code( $item['result']['code'] ?? '' ) ) { return $item; } }
		return $matches[0];
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
		curl_close( $ch );
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
				}else if(!empty($record->cardholderInitiatedTransactionId)){
					$payment_initial_id = $record->cardholderInitiatedTransactionId;
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
	 * Refund a recurring/server-to-server debit using the recurring entity.
	 * Peach requires referencing transactions to use the same channel/entity as
	 * the original payment, so renewal refunds cannot use the Checkout entity.
	 */
	public static function refund_recurring_payment( $transaction_id, $amount, $currency, $reason = '' ) {
		$transaction_id = trim( (string) $transaction_id );
		$entity_id      = trim( (string) PP_Gateway_Settings::get( 'channel' ) );
		if ( '' === $transaction_id || empty( $amount ) || empty( $currency ) || '' === $entity_id ) {
			return new WP_Error( 'peach_refund_missing_data', __( 'Missing required recurring refund data.', 'woocommerce-gateway-peach-payments' ) );
		}
		$payload = http_build_query(
			[
				'entityId'    => $entity_id,
				'amount'      => number_format( (float) $amount, 2, '.', '' ),
				'currency'    => (string) $currency,
				'paymentType' => 'RF',
			]
		);
		return self::post_request( '/v1/payments/' . rawurlencode( $transaction_id ), $payload );
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
			PP_Gateway_Logger::info( 'Reversal attempt started. Transaction ID: ' . PP_Gateway_Logger::mask_identifier_for_log( $transaction_id ) );
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
			PP_Gateway_Logger::error( 'Reversal request failed (cURL). Transaction ID: ' . PP_Gateway_Logger::mask_identifier_for_log( $transaction_id ) . ' | Error: ' . $error_message );
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
			PP_Gateway_Logger::error( 'Reversal HTTP failure. Transaction ID: ' . PP_Gateway_Logger::mask_identifier_for_log( $transaction_id ) . ' | HTTP Code: ' . (int) $response_code );
		}
return new WP_Error( 'peach_reversal_failed', __( 'Reversal request failed.', 'woocommerce-gateway-peach-payments' ) );
		}

		$result_code = is_array( $response_data ) ? ( $response_data['result']['code'] ?? '' ) : '';
		$result_desc = is_array( $response_data ) ? ( $response_data['result']['description'] ?? '' ) : '';

		// Treat any 000.* as success (covers different success codes per payment type).
		if ( ! empty( $result_code ) && 0 === strpos( $result_code, '000.' ) ) {
		
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::info( 'Reversal successful. Transaction ID: ' . PP_Gateway_Logger::mask_identifier_for_log( $transaction_id ) . ' | Result: ' . $result_code . ( $result_desc ? ' - ' . $result_desc : '' ) );
		}
} else {
		
		if ( class_exists( 'PP_Gateway_Logger' ) ) {
			PP_Gateway_Logger::warning( 'Reversal not successful. Transaction ID: ' . PP_Gateway_Logger::mask_identifier_for_log( $transaction_id ) . ' | Result: ' . ( $result_code ?: 'N/A' ) . ( $result_desc ? ' - ' . $result_desc : '' ) );
		}
}

		return is_array( $response_data ) ? $response_data : [];
	}

}
