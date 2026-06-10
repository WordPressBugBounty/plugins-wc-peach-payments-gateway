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
	 * Get a request header value in a web-server agnostic way.
	 *
	 * @param string $header Header name.
	 * @return string
	 */
	private static function get_request_header( $header ) {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );
		if ( isset( $_SERVER[ $key ] ) ) {
			return trim( (string) wp_unslash( $_SERVER[ $key ] ) );
		}

		$alternate_key = strtoupper( str_replace( '-', '_', $header ) );
		if ( isset( $_SERVER[ $alternate_key ] ) ) {
			return trim( (string) wp_unslash( $_SERVER[ $alternate_key ] ) );
		}

		return '';
	}

	/**
	 * Build the full URL that Peach called. This URL is part of the HMAC message.
	 *
	 * @return string
	 */
	private static function get_current_request_url() {
		$forwarded_proto = self::get_request_header( 'x-forwarded-proto' );
		if ( false !== strpos( $forwarded_proto, ',' ) ) {
			$forwarded_proto_parts = explode( ',', $forwarded_proto );
			$forwarded_proto       = trim( $forwarded_proto_parts[0] );
		}

		$scheme = strtolower( $forwarded_proto );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			$scheme = is_ssl() ? 'https' : 'http';
		}

		$host = self::get_request_header( 'x-forwarded-host' );
		if ( false !== strpos( $host, ',' ) ) {
			$host_parts = explode( ',', $host );
			$host       = trim( $host_parts[0] );
		}

		if ( '' === $host ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		}

		if ( '' === $host ) {
			$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		$url         = $scheme . '://' . $host . $request_uri;

		/**
		 * Allows emergency adjustment of the URL used for Peach webhook signature
		 * validation on unusual reverse-proxy/load-balancer setups.
		 */
		return (string) apply_filters( 'wc_peach_webhook_signature_url', $url );
	}

	/**
	 * Normalise a Peach signature header value before comparison.
	 *
	 * @param string $signature Signature header value.
	 * @return string
	 */
	private static function normalise_signature_header( $signature ) {
		$signature = trim( (string) $signature );
		$signature = preg_replace( '/^sha256=/i', '', $signature );
		return strtolower( trim( (string) $signature ) );
	}

	/**
	 * Retrieve the configured secret used for Peach's optional x-webhook-* HMAC
	 * header signature validation.
	 *
	 * The client confirmed that the existing Peach Client Secret setting must be
	 * used for the HMAC header validation path.
	 *
	 * @return string
	 */
	private static function get_webhook_hmac_signature_secret() {
		$secret = PP_Gateway_Settings::get( 'embed_clientsecret' );
		return is_string( $secret ) ? trim( $secret ) : '';
	}

	/**
	 * Retrieve configured secrets that may validate Peach's normal payload-level
	 * Checkout webhook signature field.
	 *
	 * Peach's Checkout webhook payload documentation refers to this as the Secret
	 * Token. The plugin already has a dedicated Secret Token setting, so we try
	 * that first and keep the Client Secret as a compatibility fallback.
	 *
	 * @return array<string,string> Labelled non-empty secrets.
	 */
	private static function get_webhook_payload_signature_secrets() {
		$candidates = [
			'Secret Token setting' => PP_Gateway_Settings::get( 'secret' ),
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
	 * Check whether the request contains any of Peach's optional HMAC header
	 * signature fields. If any are present, all are required and must validate.
	 *
	 * @return bool
	 */
	private static function has_webhook_signature_headers() {
		$headers = [
			'x-webhook-signature-algorithm',
			'x-webhook-timestamp',
			'x-webhook-id',
			'x-webhook-signature',
		];

		foreach ( $headers as $header ) {
			if ( '' !== self::get_request_header( $header ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate Peach's optional HMAC SHA256 webhook signature headers before
	 * processing. This is the header-based protection described in Peach's
	 * Checkout Webhooks documentation.
	 *
	 * @param string $raw_body     Raw request body exactly as received.
	 * @param string $handler_name Handler name for logging.
	 * @return true|WP_Error
	 */
	private static function verify_webhook_header_signature( $raw_body, $handler_name ) {
		$secret = self::get_webhook_hmac_signature_secret();

		if ( '' === $secret ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments webhook HMAC signature validation failed for %s - missing Client Secret.', $handler_name ) );
			return new WP_Error( 'peach_missing_webhook_signature_secret', 'missing Client Secret / webhook HMAC signature secret' );
		}

		$algorithm          = self::get_request_header( 'x-webhook-signature-algorithm' );
		$timestamp          = self::get_request_header( 'x-webhook-timestamp' );
		$webhook_id         = self::get_request_header( 'x-webhook-id' );
		$received_signature = self::get_request_header( 'x-webhook-signature' );

		if ( '' === $algorithm || '' === $timestamp || '' === $webhook_id || '' === $received_signature ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments webhook HMAC signature validation failed for %s - incomplete Peach HMAC signature headers.', $handler_name ) );
			return new WP_Error( 'peach_missing_webhook_signature_headers', 'missing required Peach webhook signature headers' );
		}

		$normalised_algorithm = strtolower( preg_replace( '/[^a-z0-9]/i', '', $algorithm ) );
		if ( ! in_array( $normalised_algorithm, [ 'sha256', 'hmacsha256' ], true ) ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments webhook HMAC signature validation failed for %1$s - unsupported signature algorithm %2$s.', $handler_name, sanitize_text_field( $algorithm ) ) );
			return new WP_Error( 'peach_unsupported_webhook_signature_algorithm', 'unsupported Peach webhook signature algorithm' );
		}

		$request_url          = self::get_current_request_url();
		$message              = $timestamp . '.' . $webhook_id . '.' . $request_url . '.' . (string) $raw_body;
		$calculated_signature = hash_hmac( 'sha256', $message, $secret );
		$received_signature   = self::normalise_signature_header( $received_signature );

		$is_valid = hash_equals( $calculated_signature, $received_signature );

		if ( $is_valid ) {
			return true;
		}

		PP_Gateway_Logger::error( sprintf(
			'Peach Payments webhook HMAC signature validation failed for %1$s. Webhook ID: %2$s. Algorithm: %3$s.',
			$handler_name,
			sanitize_text_field( $webhook_id ),
			sanitize_text_field( $algorithm )
		) );
		return new WP_Error( 'peach_invalid_webhook_signature', 'invalid Peach webhook signature' );
	}

	/**
	 * Find a payload signature value. Supports normal decoded payloads and JSON
	 * event wrappers that place the payment fields inside a payload object.
	 *
	 * @param array $data Webhook payload.
	 * @return string
	 */
	private static function get_payload_signature_value( array $data ) {
		if ( isset( $data['signature'] ) && '' !== $data['signature'] ) {
			return trim( (string) $data['signature'] );
		}

		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) && isset( $data['payload']['signature'] ) && '' !== $data['payload']['signature'] ) {
			return trim( (string) $data['payload']['signature'] );
		}

		return '';
	}

	/**
	 * Get the data array that must be used when calculating the payload signature.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	private static function get_payload_signature_data( array $data ) {
		if ( isset( $data['signature'] ) ) {
			return $data;
		}

		if ( isset( $data['payload'] ) && is_array( $data['payload'] ) && isset( $data['payload']['signature'] ) ) {
			return $data['payload'];
		}

		return $data;
	}

	/**
	 * Flatten signature parameters into Peach's bracket notation for nested data.
	 *
	 * @param array  $input  Input data.
	 * @param string $prefix Current key prefix.
	 * @return array
	 */
	private static function flatten_signature_params( array $input, $prefix = '' ) {
		$result = [];

		foreach ( $input as $key => $value ) {
			$current_key = '' === $prefix ? (string) $key : $prefix . '[' . (string) $key . ']';

			if ( is_array( $value ) ) {
				$result = array_merge( $result, self::flatten_signature_params( $value, $current_key ) );
			} else {
				$result[ $current_key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Convert PHP-normalised webhook keys back to Peach's signature key format.
	 * PHP converts dots in form field names to underscores, so result_code must
	 * be signed as result.code, resultDetails_ConnectorTxID1 as
	 * resultDetails.ConnectorTxID1, etc.
	 *
	 * @param string $key Flattened parameter key.
	 * @return string
	 */
	private static function convert_payload_signature_key( $key ) {
		$key = (string) $key;

		if ( false !== strpos( $key, '[' ) ) {
			$prefix = substr( $key, 0, strpos( $key, '[' ) );
			$suffix = substr( $key, strpos( $key, '[' ) );
			return str_replace( '_', '.', $prefix ) . $suffix;
		}

		return str_replace( '_', '.', $key );
	}

	/**
	 * Build the concatenated Peach signature string from flattened fields.
	 *
	 * @param array $fields Flattened signature fields.
	 * @return string
	 */
	private static function build_payload_signature_string( array $fields ) {
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
	 * Calculate Peach's payload-level webhook signature.
	 *
	 * @param array  $data                Webhook payload data.
	 * @param string $secret              Signature secret.
	 * @param bool   $convert_underscores Whether to convert PHP-normalised underscores back to Peach dot notation.
	 * @return string
	 */
	private static function calculate_payload_signature( array $data, $secret, $convert_underscores = true ) {
		$flattened = self::flatten_signature_params( $data );
		$converted = [];

		foreach ( $flattened as $key => $value ) {
			$new_key = $convert_underscores ? self::convert_payload_signature_key( $key ) : (string) $key;
			$converted[ $new_key ] = $value;
		}

		return hash_hmac( 'sha256', self::build_payload_signature_string( $converted ), $secret );
	}

	/**
	 * Parse a URL-encoded body while preserving original form field names such as
	 * result.code. PHP's normal request parsing changes dots to underscores, which
	 * can make signature verification fail on some Peach webhook variants.
	 *
	 * @param string $raw_body Raw request body.
	 * @return array
	 */
	private static function parse_raw_form_body_preserving_keys( $raw_body ) {
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
	 * Calculate all supported Peach payload signature variants. Peach documentation
	 * shows dot-notation keys, while PHP-normalised form payloads can arrive with
	 * underscores. The client-provided sample uses underscore-to-dot conversion,
	 * but real Checkout webhooks can also contain original dot keys in the raw body.
	 *
	 * @param array  $data     Parsed webhook payload data.
	 * @param string $secret   Signature secret.
	 * @param string $raw_body Raw request body where available.
	 * @return array
	 */
	private static function calculate_payload_signature_variants( array $data, $secret, $raw_body = '' ) {
		$variants = [
			'parsed-dot-normalised' => self::calculate_payload_signature( $data, $secret, true ),
			'parsed-exact'          => self::calculate_payload_signature( $data, $secret, false ),
		];

		$raw_fields = self::parse_raw_form_body_preserving_keys( $raw_body );
		if ( ! empty( $raw_fields ) ) {
			$variants['raw-exact'] = hash_hmac( 'sha256', self::build_payload_signature_string( $raw_fields ), $secret );

			$raw_dot_fields = [];
			foreach ( $raw_fields as $key => $value ) {
				$raw_dot_fields[ self::convert_payload_signature_key( $key ) ] = $value;
			}
			$variants['raw-dot-normalised'] = hash_hmac( 'sha256', self::build_payload_signature_string( $raw_dot_fields ), $secret );
		}

		return array_unique( $variants );
	}

	/**
	 * Validate Peach's payload-level webhook signature field. Checkout webhooks
	 * are commonly sent as x-www-form-urlencoded payloads with a signature field.
	 * This validation is required when the optional Peach HMAC headers are not
	 * present on the request.
	 *
	 * @param array  $data         Webhook payload data.
	 * @param string $handler_name Handler name for logging.
	 * @param string $context      Context label for logging.
	 * @param string $raw_body     Raw request body, where available.
	 * @return true|WP_Error
	 */
	private static function verify_webhook_payload_signature( array $data, $handler_name, $context = 'payload', $raw_body = '' ) {
		$secrets = self::get_webhook_payload_signature_secrets();

		if ( empty( $secrets ) ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments webhook payload signature validation failed for %s - missing Secret Token / Client Secret.', $handler_name ) );
			return new WP_Error( 'peach_missing_webhook_signature_secret', 'missing Secret Token / Client Secret webhook payload signature secret' );
		}

		$signature_data     = self::get_payload_signature_data( $data );
		$received_signature = self::get_payload_signature_value( $data );

		if ( '' === $received_signature ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments webhook payload signature validation failed for %1$s - missing Peach payload signature field in %2$s.', $handler_name, sanitize_text_field( $context ) ) );
			return new WP_Error( 'peach_missing_webhook_payload_signature', 'missing Peach webhook payload signature' );
		}

		$received_signature = self::normalise_signature_header( $received_signature );
		$matched_variant    = '';

		foreach ( $secrets as $secret_label => $secret ) {
			$variants = self::calculate_payload_signature_variants( $signature_data, $secret, $raw_body );

			foreach ( $variants as $variant_name => $calculated_signature ) {
				if ( hash_equals( $calculated_signature, $received_signature ) ) {
					$matched_variant = (string) $variant_name;
					break 2;
				}
			}
		}

		$is_valid = '' !== $matched_variant;

		if ( $is_valid ) {
			return true;
		}

		PP_Gateway_Logger::error( sprintf(
			'Peach Payments webhook payload signature validation failed for %1$s using %2$s.',
			$handler_name,
			sanitize_text_field( $context )
		) );

		return new WP_Error( 'peach_invalid_webhook_payload_signature', 'invalid Peach webhook payload signature' );
	}

	/**
	 * Create nested array aliases for Peach's dotted webhook keys and preserve the
	 * original values for backward compatibility.
	 *
	 * @param array $data Webhook payload.
	 * @return array
	 */
	private static function normalise_signed_webhook_data( array $data ) {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$data[ $key ] = self::normalise_signed_webhook_data( $value );
			}

			if ( is_string( $key ) && false !== strpos( $key, '.' ) ) {
				self::set_dotted_webhook_value( $data, $key, $value );
			}
		}

		$result_code = self::get_webhook_value( $data, [ 'result_code', [ 'result', 'code' ], 'result.code' ] );
		if ( null !== $result_code && ! isset( $data['result_code'] ) ) {
			$data['result_code'] = $result_code;
		}

		return $data;
	}

	/**
	 * Set a nested value from a dotted Peach webhook key.
	 *
	 * @param array  $target Target array.
	 * @param string $dotted_key Dotted key.
	 * @param mixed  $value Value.
	 */
	private static function set_dotted_webhook_value( array &$target, $dotted_key, $value ) {
		$parts = array_values( array_filter( explode( '.', (string) $dotted_key ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return;
		}

		$cursor =& $target;
		foreach ( $parts as $index => $part ) {
			if ( count( $parts ) - 1 === $index ) {
				if ( ! array_key_exists( $part, $cursor ) ) {
					$cursor[ $part ] = $value;
				}
				return;
			}

			if ( ! isset( $cursor[ $part ] ) || ! is_array( $cursor[ $part ] ) ) {
				$cursor[ $part ] = [];
			}

			$cursor =& $cursor[ $part ];
		}
	}

	/**
	 * Retrieve the first matching webhook value from flat, dotted or nested keys.
	 *
	 * @param array $data  Source array.
	 * @param array $paths Candidate paths.
	 * @return mixed|null
	 */
	private static function get_webhook_value( array $data, array $paths ) {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && array_key_exists( $path, $data ) && '' !== $data[ $path ] && null !== $data[ $path ] ) {
				return $data[ $path ];
			}

			$segments = is_array( $path ) ? $path : ( false !== strpos( (string) $path, '.' ) ? explode( '.', (string) $path ) : [] );
			if ( empty( $segments ) ) {
				continue;
			}

			$value = $data;
			$found = true;
			foreach ( $segments as $segment ) {
				if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
					$found = false;
					break;
				}
				$value = $value[ $segment ];
			}

			if ( $found && '' !== $value && null !== $value ) {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Detect a Peach Dashboard webhook URL configuration/test request.
	 *
	 * Peach can send an initial JSON request while a webhook URL is being added
	 * in the Dashboard. That request may not contain the normal Checkout payload
	 * signature and must be acknowledged with HTTP 200 so the URL can be saved.
	 * It must never be passed into order/payment manipulation logic.
	 *
	 * @param array $data Parsed JSON payload.
	 * @return bool
	 */
	private static function is_dashboard_webhook_configuration_request( array $data ) {
		if ( '' !== self::get_payload_signature_value( $data ) || isset( $data['encryptedBody'] ) ) {
			return false;
		}

		$state_changing_value = self::get_webhook_value(
			$data,
			[
				[ 'payload', 'merchantTransactionId' ],
				'merchantTransactionId',
				[ 'payload', 'result', 'code' ],
				[ 'payload', 'result_code' ],
				[ 'result', 'code' ],
				'result.code',
				'result_code',
				[ 'payload', 'checkoutId' ],
				'checkoutId',
				[ 'payload', 'id' ],
				'id',
			]
		);

		return null === $state_changing_value;
	}

	/**
	 * Build a safe acknowledge-only response for non-transactional webhook probes.
	 *
	 * @return array
	 */
	private static function dashboard_webhook_configuration_acknowledgement() {
		return [
			'peach_ack_only' => true,
			'log_type'       => 'info',
			'log_msg'        => 'Peach dashboard webhook URL configuration request acknowledged without order processing',
			'log_txt'        => 'Webhook configuration acknowledged',
			'status_code'    => 200,
		];
	}

	/**
	 * Read and verify the webhook envelope. All webhook endpoints must first pass
	 * a Peach signature/authenticity validation before any payload can be decoded
	 * or used for state-changing order updates.
	 *
	 * Validation order:
	 * 1. If Peach HMAC headers are present, validate the raw request body using
	 *    the header-based HMAC method.
	 * 2. If those optional headers are not present, validate the normal Checkout
	 *    payload signature field.
	 * 3. Encrypted payloads are also decrypted with the configured Card Webhook
	 *    Decryption key before processing.
	 *
	 * @param string $handler_name Current handler name for logging.
	 * @param string $webhook_method Method label passed by reference.
	 * @return array
	 */
	private static function get_verified_webhook_data( $handler_name, &$webhook_method ) {
		$webhook_method         = 'signed';
		$raw_body               = file_get_contents( 'php://input' );
		$header_signature_valid = false;

		if ( self::has_webhook_signature_headers() ) {
			$signature_check = self::verify_webhook_header_signature( (string) $raw_body, $handler_name );
			if ( is_wp_error( $signature_check ) ) {
				return [
					'log_type'    => 'error',
					'log_msg'     => $signature_check->get_error_message(),
					'log_txt'     => 'Webhook signature validation failed',
					'status_code' => 401,
				];
			}

			$header_signature_valid = true;
		}

		$data = json_decode( (string) $raw_body, true );
		if ( is_array( $data ) && JSON_ERROR_NONE === json_last_error() ) {
			if ( isset( $data['encryptedBody'] ) ) {
				$webhook_method = $header_signature_valid ? 'header-signed-json-encrypted' : 'payload-signed-json-encrypted';
				return self::decode_data( $data, $handler_name, ! $header_signature_valid );
			}

			if ( ! $header_signature_valid ) {
				if ( '' === self::get_payload_signature_value( $data ) && self::is_dashboard_webhook_configuration_request( $data ) ) {
					$webhook_method = 'dashboard-configuration-json';
					return self::dashboard_webhook_configuration_acknowledgement();
				}

				$payload_signature_check = self::verify_webhook_payload_signature( $data, $handler_name, 'json payload', (string) $raw_body );
				if ( is_wp_error( $payload_signature_check ) ) {
					return [
						'log_type'    => 'error',
						'log_msg'     => $payload_signature_check->get_error_message(),
						'log_txt'     => 'Webhook signature validation failed',
						'status_code' => 401,
					];
				}
			}

			$webhook_method = $header_signature_valid ? 'header-signed-json' : 'payload-signed-json';
			return self::normalise_signed_webhook_data( $data );
		}

		$post_data = [];
		if ( ! empty( $_POST ) && is_array( $_POST ) ) {
			$post_data = wp_unslash( $_POST );
		} elseif ( '' !== (string) $raw_body ) {
			wp_parse_str( (string) $raw_body, $post_data );
		}

		if ( ! empty( $post_data ) && is_array( $post_data ) ) {
			if ( isset( $post_data['encryptedBody'] ) ) {
				$webhook_method = $header_signature_valid ? 'header-signed-post-encrypted' : 'payload-signed-post-encrypted';
				return self::decode_data( $post_data, $handler_name, ! $header_signature_valid );
			}

			if ( ! $header_signature_valid ) {
				$payload_signature_check = self::verify_webhook_payload_signature( $post_data, $handler_name, 'form payload', (string) $raw_body );
				if ( is_wp_error( $payload_signature_check ) ) {
					return [
						'log_type'    => 'error',
						'log_msg'     => $payload_signature_check->get_error_message(),
						'log_txt'     => 'Webhook signature validation failed',
						'status_code' => 401,
					];
				}
			}

			$webhook_method = $header_signature_valid ? 'header-signed-post' : 'payload-signed-post';
			return self::normalise_signed_webhook_data( $post_data );
		}

		return [
			'log_type'    => 'error',
			'log_msg'     => 'signed webhook request contained no readable payload',
			'log_txt'     => 'Invalid webhook request',
			'status_code' => 400,
		];
	}
	
	/**
	 * Handle incoming Payments webhook from Peach Payments
	 */
	public static function handle_payments_webhook() {
		$webhook_method = 'json';
		$data = self::get_verified_webhook_data( __FUNCTION__, $webhook_method );
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}

		if ( ! empty( $data['peach_ack_only'] ) ) {
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo isset( $data['log_txt'] ) ? $data['log_txt'] : 'Webhook acknowledged';
			exit;
		}

		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payments_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg']."." );
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'warning'){
			PP_Gateway_Logger::warning( "Handled 'handle_payments_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payments_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
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
		$data = self::get_verified_webhook_data( __FUNCTION__, $webhook_method );
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}

		if ( ! empty( $data['peach_ack_only'] ) ) {
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo isset( $data['log_txt'] ) ? $data['log_txt'] : 'Webhook acknowledged';
			exit;
		}

		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payon_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg']."." );
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'warning'){
			PP_Gateway_Logger::warning( "Handled 'handle_payon_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_payon_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
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
		$data = self::get_verified_webhook_data( __FUNCTION__, $webhook_method );
		
		if(isset($data['type']) && $data['type'] == 'REGISTRATION'){
			status_header( 200 );
			echo 'User Adding Card';
			exit;
		}

		if ( ! empty( $data['peach_ack_only'] ) ) {
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo isset( $data['log_txt'] ) ? $data['log_txt'] : 'Webhook acknowledged';
			exit;
		}

		
		if(isset($data['log_type']) && $data['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_switch_webhook' Decode Webhook [".$webhook_method."] — ".$data['log_msg']."." );
			$status_code = isset( $data['status_code'] ) ? absint( $data['status_code'] ) : 200;
			status_header( $status_code );
			echo $data['log_txt'];
			exit;
		}
		
		//log_type, log_msg, log_txt 
		$response = self::handle_webhook($data);
		
		if($response['log_type'] == 'info' && $response['log_msg'] == 'peach-card'){
			status_header( 200 );
			echo $response['log_txt'];
			exit;
		}elseif($response['log_type'] == 'warning'){
			PP_Gateway_Logger::warning( "Handled 'handle_switch_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
		}elseif($response['log_type'] == 'error'){
			PP_Gateway_Logger::error( "Handled 'handle_switch_webhook' Webhook [".$webhook_method."] — ".$response['log_msg']."." );
		}
		
		status_header( 200 );
		echo $response['log_txt'];
		exit;
	}

	/**
	 * Determine whether a Peach webhook result code is informational/non-final.
	 *
	 * These messages can be legitimately signed by Peach during the checkout
	 * lifecycle, but they are not final payment results and must not move the
	 * WooCommerce order to success or failure.
	 *
	 * @param string $result_code Peach result code.
	 * @return bool
	 */
	private static function is_non_final_webhook_result_code( $result_code ) {
		return PP_Gateway_Order_Utils::is_non_final_result_code( $result_code );
	}

	/**
	 * Handle incoming webhook from Peach Payments
	 */
	public static function handle_webhook($data) {
		
		$merchantTransactionId = self::get_webhook_value(
			$data,
			[
				[ 'payload', 'merchantTransactionId' ],
				'merchantTransactionId',
			]
		);
		$result_code = self::get_webhook_value(
			$data,
			[
				[ 'payload', 'result', 'code' ],
				[ 'payload', 'result_code' ],
				[ 'result', 'code' ],
				'result.code',
				'result_code',
			]
		);
		$payment_order_id = self::get_webhook_value(
			$data,
			[
				[ 'payload', 'id' ],
				'id',
				[ 'payload', 'resultDetails', 'ConnectorTxID1' ],
				'resultDetails.ConnectorTxID1',
			]
		);
		$registrationId = self::get_webhook_value(
			$data,
			[
				[ 'payload', 'registrationId' ],
				'registrationId',
			]
		);

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

		if ( self::is_non_final_webhook_result_code( $result_code ) ) {
			return [
				'log_type' => 'info',
				'log_msg'  => 'order #'.$order_number.' received non-final Peach webhook code '.$result_code.' and was left unchanged',
				'log_txt'  => 'Non-final webhook ignored',
			];
		}

		$is_successful_result = PP_Gateway_Order_Utils::is_successful_result_code( $result_code );
		if (
			! $is_successful_result
			&& (
				$order->is_paid()
				|| $order->get_meta( 'peach_webhook_handled' )
				|| PP_Gateway_Order_Utils::initial_payment_already_processed( $order, $payment_order_id )
			)
		) {
			return [
				'log_type' => 'warning',
				'log_msg'  => 'non-success Peach webhook received for already-paid order #'.$order_number.' and ignored. Result code: '.sanitize_text_field( (string) $result_code ),
				'log_txt'  => 'Non-success webhook ignored',
			];
		}

		$validation_payload = isset( $data['type'] ) && isset( $data['payload'] ) && is_array( $data['payload'] ) ? $data['payload'] : $data;
		$validation = PP_Peach_API::validate_payment_result_for_order( $order, $validation_payload, 'webhook' );
		if ( is_wp_error( $validation ) ) {
			return [
				'log_type' => 'error',
				'log_msg'  => 'order #'.$order_number.' webhook verification failed: ' . $validation->get_error_message(),
				'log_txt'  => 'Webhook verification failed',
			];
		}

		// Save metadata early so later duplicate requests still have the IDs available.
		if ( ! empty( $payment_order_id ) ) {
			$order->update_meta_data( 'payment_order_id', $payment_order_id );
		}
		if ( ! empty( $registrationId ) ) {
			$order->update_meta_data( 'payment_registration_id', $registrationId );
		}
		
		if( $is_successful_result ){
			if ( $order->get_meta( 'peach_webhook_handled' ) || PP_Gateway_Order_Utils::initial_payment_already_processed( $order, $payment_order_id ) ) {
				$order->save();
				return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' already handled', 'log_txt' => 'Already handled'];
			}

			$lock_acquired = PP_Gateway_Order_Utils::acquire_initial_payment_lock( $order );
			if ( ! $lock_acquired ) {
				return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' already being processed', 'log_txt' => 'Already handled'];
			}

			try {
				if ( $order->get_meta( 'peach_webhook_handled' ) || PP_Gateway_Order_Utils::initial_payment_already_processed( $order, $payment_order_id ) ) {
					$order->save();
					return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' already handled after lock', 'log_txt' => 'Already handled'];
				}

				$settings      = get_option( 'woocommerce_peach-payments_settings', [] );
				$custom_status = isset( $settings['peach_order_status'] ) ? $settings['peach_order_status'] : 'processing';

				// Complete order (if not already marked)
				if ( PP_Gateway_Order_Utils::order_status_checks($order)) {
					if($payment_order_id){
						$order->payment_complete( $payment_order_id );
					}
					$order->update_status( $custom_status, __( 'Payment completed via Peach Payments Webhook.', WC_PEACH_TEXT_DOMAIN ) );

					if ( class_exists( 'PP_Gateway_Subscription_Handler' ) && method_exists( 'PP_Gateway_Subscription_Handler', 'add_unique_order_note' ) ) {
						PP_Gateway_Subscription_Handler::add_unique_order_note( $order, 'Peach Payment Successfull. Webhook.' );
					} else {
						$order->add_order_note( 'Peach Payment Successfull. Webhook.',0,false);
					}

					PP_Gateway_Order_Utils::mark_initial_payment_processed( $order, $payment_order_id, 'webhook' );
				}
				
				$order->update_meta_data( 'peach_webhook_handled', true );
				$order->save();

			} finally {
				PP_Gateway_Order_Utils::release_initial_payment_lock( $order );
			}

			if ( class_exists( 'PP_Gateway_Subscription_Handler' ) ) {
				PP_Gateway_Subscription_Handler::sync_payment_meta_from_order_to_subscriptions( $order, 'webhook_success' );
			}

			return ['log_type' => 'info', 'log_msg' => 'order #'.$order_number.' successfully handled', 'log_txt' => 'Webhook handled'];
		}else{
			return [
				'log_type' => 'error',
				'log_msg'  => 'order #'.$order_number.' failed. Result code: '.sanitize_text_field( (string) $result_code ),
				'log_txt'  => 'Not successfull status',
			];
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
	
	public static function decode_data( $data, $handler_name = 'decode_data', $require_payload_signature = true ){
		
		$web_hook_key = PP_Gateway_Settings::get('card_webhook_key');
		
		if (empty($web_hook_key)) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments encrypted webhook validation failed for %s - missing Card Webhook Decryption key.', $handler_name ) );
			return ['log_type' => 'error', 'log_msg' => 'missing Card Webhook Decryption key', 'log_txt' => 'Missing Card Webhook Decryption key'];
		}
		
		$headerVector = $_SERVER['HTTP_X_INITIALIZATION_VECTOR'] ?? '';
		$headerTag    = $_SERVER['HTTP_X_AUTHENTICATION_TAG'] ?? '';
		
		if (empty($headerVector) || empty($headerTag) || empty($data['encryptedBody'])) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments encrypted webhook validation failed for %s - missing encrypted webhook fields or encryption headers.', $handler_name ) );
			return ['log_type' => 'error', 'log_msg' => 'missing required data', 'log_txt' => 'Invalid webhook request'];
		}
		
		$key         = hex2bin($web_hook_key);
		$iv          = hex2bin($headerVector);
		$auth_tag    = hex2bin($headerTag);
		$cipher_text = hex2bin($data['encryptedBody']);
		
		if ( false === $key || false === $iv || false === $auth_tag || false === $cipher_text ) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments encrypted webhook validation failed for %s - invalid hex encoded encryption data.', $handler_name ) );
			return ['log_type' => 'error', 'log_msg' => 'invalid encrypted webhook encoding', 'log_txt' => 'Invalid webhook request'];
		}
		
		$result = openssl_decrypt(
			$cipher_text,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$auth_tag
		);
		
		if ($result === false) {
			PP_Gateway_Logger::error( sprintf( 'Peach Payments encrypted webhook validation failed for %s - OpenSSL could not decrypt encryptedBody.', $handler_name ) );
			return ['log_type' => 'error', 'log_msg' => 'webhook decryption failed: OpenSSL error', 'log_txt' => 'Decryption failed'];
		}
		
		
		$decoded = json_decode($result, true);
		if ( is_array( $decoded ) ) {
			if ( $require_payload_signature ) {
				if ( '' !== self::get_payload_signature_value( $decoded ) ) {
					$payload_signature_check = self::verify_webhook_payload_signature( $decoded, $handler_name, 'decrypted payload' );
					if ( is_wp_error( $payload_signature_check ) ) {
						return [
							'log_type'    => 'error',
							'log_msg'     => $payload_signature_check->get_error_message(),
							'log_txt'     => 'Webhook signature validation failed',
							'status_code' => 401,
						];
					}
				}
			}
			return self::normalise_signed_webhook_data( $decoded );
		}

		return $decoded;

	}

}
