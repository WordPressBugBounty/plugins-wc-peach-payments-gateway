<?php
/**
 * Class PP_Gateway_Logger
 *
 * Centralized logging utility for the Peach Payments Gateway.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Logger {

	/**
	 * WooCommerce logger instance.
	 *
	 * @var WC_Logger
	 */
	protected static $logger;

	/**
	 * Log context.
	 *
	 * @var string
	 */
	protected static $context = 'peach_payments';

	/**
	 * Get WooCommerce logger instance.
	 *
	 * @return WC_Logger
	 */
	protected static function get_logger() {
		if ( ! self::$logger ) {
			self::$logger = wc_get_logger();
		}
		return self::$logger;
	}

	/**
	 * Mask every digit except the final four while preserving separators.
	 *
	 * @param string $value Value that may contain a PAN or other numeric identifier.
	 * @return string
	 */
	private static function mask_digits_keep_last_four( $value ) {
		$value       = (string) $value;
		$digit_count = preg_match_all( '/\d/', $value, $unused );

		if ( $digit_count <= 0 ) {
			return $value;
		}

		$digits_to_mask = max( 0, $digit_count - 4 );
		$masked         = '';
		$seen_digits    = 0;
		$length         = strlen( $value );

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $value[ $i ];
			if ( $char >= '0' && $char <= '9' ) {
				$seen_digits++;
				$masked .= ( $seen_digits <= $digits_to_mask ) ? '*' : $char;
			} else {
				$masked .= $char;
			}
		}

		return $masked;
	}

	/**
	 * Mask an opaque identifier while keeping only its final four characters.
	 *
	 * @param mixed $value Identifier value.
	 * @return string
	 */
	private static function mask_identifier( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return $value;
		}

		$length = strlen( $value );
		if ( $length <= 4 ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - 4 ) . substr( $value, -4 );
	}


	/**
	 * Public helper for call sites that deliberately log a Peach identifier.
	 *
	 * @param mixed $value Identifier value.
	 * @return string
	 */
	public static function mask_identifier_for_log( $value ) {
		return self::mask_identifier( $value );
	}

	/**
	 * Return configured credentials that must never be emitted to logs, even
	 * when they appear inside an unstructured error message without a field name.
	 *
	 * @return array
	 */
	private static function get_known_secrets() {
		$secrets = [];

		if ( function_exists( 'get_option' ) ) {
			$settings = get_option( 'woocommerce_peach-payments_settings', [] );
			if ( is_array( $settings ) ) {
				foreach ( [ 'access_token', 'secret', 'embed_clientsecret', 'card_webhook_key', 'password' ] as $key ) {
					if ( isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ) {
						$value = trim( (string) $settings[ $key ] );
						if ( '' !== $value ) {
							$secrets[] = $value;
						}
					}
				}
			}

			$legacy_password = get_option( 'woocommerce_peach-payments_password', '' );
			if ( is_scalar( $legacy_password ) && '' !== trim( (string) $legacy_password ) ) {
				$secrets[] = trim( (string) $legacy_password );
			}
		}

		return array_values( array_unique( $secrets ) );
	}

	/**
	 * Apply a masker to values that are labelled with one of the supplied keys.
	 * Handles JSON, PHP print_r(), query strings and common human-readable logs.
	 *
	 * @param string   $message     Log message.
	 * @param string   $key_pattern Regex alternation for sensitive field names.
	 * @param callable $masker      Callback receiving the raw value.
	 * @return string
	 */
	private static function mask_labelled_values( $message, $key_pattern, $masker ) {
		// JSON / JSON-like quoted key/value pairs. Allow escaped characters inside
		// the value so malformed/partial JSON still gets a safe regex fallback.
		$message = preg_replace_callback(
			'/(["\'](?:' . $key_pattern . ')["\']\s*:\s*)(["\'])((?:\\\\.|(?!\2).)*)\2/is',
			function( $matches ) use ( $masker ) {
				return $matches[1] . $matches[2] . call_user_func( $masker, $matches[3] ) . $matches[2];
			},
			$message
		);

		// JSON numeric/unquoted values.
		$message = preg_replace_callback(
			'/(["\'](?:' . $key_pattern . ')["\']\s*:\s*)(-?[0-9]+(?:\.[0-9]+)?|true|false|null)/i',
			function( $matches ) use ( $masker ) {
				return $matches[1] . call_user_func( $masker, $matches[2] );
			},
			$message
		);

		// PHP var_export() style quoted key/value pairs.
		$message = preg_replace_callback(
			'/(["\'](?:' . $key_pattern . ')["\']\s*=>\s*)(["\'])((?:\\\\.|(?!\2).)*)\2/is',
			function( $matches ) use ( $masker ) {
				return $matches[1] . $matches[2] . call_user_func( $masker, $matches[3] ) . $matches[2];
			},
			$message
		);

		// PHP print_r() array output.
		$message = preg_replace_callback(
			'/(\[(?:' . $key_pattern . ')\]\s*=>\s*)([^\r\n\[]*)/i',
			function( $matches ) use ( $masker ) {
				return $matches[1] . call_user_func( $masker, trim( $matches[2] ) );
			},
			$message
		);

		// Query strings, form bodies and simple key=value fragments.
		$message = preg_replace_callback(
			'/((?:^|[?&;\s])(?:' . $key_pattern . ')\s*=(?!>)\s*)([^&;\s]*)/i',
			function( $matches ) use ( $masker ) {
				return $matches[1] . call_user_func( $masker, $matches[2] );
			},
			$message
		);

		// Human-readable "Field: value" / "Field => value" fragments.
		$message = preg_replace_callback(
			'/((?<![A-Za-z0-9_])(?:' . $key_pattern . ')(?![A-Za-z0-9_])\s*(?::|=>)\s*)([^\s,;\]\}\)]+)/i',
			function( $matches ) use ( $masker ) {
				$value    = $matches[2];
				$trailing = '';
				if ( preg_match( '/[.]+$/', $value, $punctuation ) ) {
					$trailing = $punctuation[0];
					$value    = substr( $value, 0, -strlen( $trailing ) );
				}
				return $matches[1] . call_user_func( $masker, $value ) . $trailing;
			},
			$message
		);

		return $message;
	}

	/**
	 * Recursively sanitize a complete JSON object/array string.
	 *
	 * Structured JSON is decoded first so nested and JSON-inside-JSON values use
	 * the same recursive rules as native PHP arrays/objects. Regex masking remains
	 * as a fallback for malformed or partial JSON later in sanitize_message().
	 *
	 * @param string $message Candidate JSON string.
	 * @return string|null Sanitized JSON string, or null when the input is not valid JSON.
	 */
	private static function sanitize_json_string( $message ) {
		$message = (string) $message;
		$trimmed = trim( $message );

		if ( '' === $trimmed || ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) ) {
			return null;
		}

		$decoded = json_decode( $trimmed, true, 512, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return null;
		}

		$sanitized = self::sanitize_data( $decoded );
		$encoded   = json_encode( $sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( false === $encoded ) {
			return null;
		}

		$leading_length  = strlen( $message ) - strlen( ltrim( $message ) );
		$trailing_length = strlen( $message ) - strlen( rtrim( $message ) );
		$leading         = $leading_length > 0 ? substr( $message, 0, $leading_length ) : '';
		$trailing        = $trailing_length > 0 ? substr( $message, -$trailing_length ) : '';

		return $leading . $encoded . $trailing;
	}

	/**
	 * Sanitize valid JSON objects/arrays embedded inside a larger log message.
	 *
	 * A small bracket scanner is used instead of a JSON regex so escaped strings
	 * and arbitrarily nested objects/arrays are handled correctly.
	 *
	 * @param string $message Log message.
	 * @return string
	 */
	private static function sanitize_embedded_json_fragments( $message ) {
		$length = strlen( $message );
		$output = '';

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $message[ $i ];
			if ( '{' !== $char && '[' !== $char ) {
				$output .= $char;
				continue;
			}

			$stack     = [ '{' === $char ? '}' : ']' ];
			$in_string = false;
			$escaped   = false;
			$end       = null;

			for ( $j = $i + 1; $j < $length; $j++ ) {
				$current = $message[ $j ];

				if ( $in_string ) {
					if ( $escaped ) {
						$escaped = false;
						continue;
					}
					if ( '\\' === $current ) {
						$escaped = true;
						continue;
					}
					if ( '"' === $current ) {
						$in_string = false;
					}
					continue;
				}

				if ( '"' === $current ) {
					$in_string = true;
					continue;
				}

				if ( '{' === $current ) {
					$stack[] = '}';
					continue;
				}
				if ( '[' === $current ) {
					$stack[] = ']';
					continue;
				}

				$expected = end( $stack );
				if ( $current === $expected ) {
					array_pop( $stack );
					if ( empty( $stack ) ) {
						$end = $j;
						break;
					}
				}
			}

			if ( null === $end ) {
				$output .= $char;
				continue;
			}

			$candidate = substr( $message, $i, $end - $i + 1 );

			// PHP print_r()/array output uses bracketed keys such as `[0] =>`
			// and `[cardTokens] =>`. A fragment like `[0]` is also valid JSON,
			// so treating it as embedded JSON would rewrite the key before the
			// dedicated print_r() masking rules run. Preserve these PHP array-key
			// fragments and let the labelled-value sanitizers handle them later.
			if ( '[' === $char && preg_match( '/^\s*=>/', substr( $message, $end + 1 ) ) ) {
				$output .= $candidate;
				$i       = $end;
				continue;
			}

			$sanitized = self::sanitize_json_string( $candidate );
			if ( null === $sanitized ) {
				$output .= $char;
				continue;
			}

			$output .= $sanitized;
			$i       = $end;
		}

		return $output;
	}

	/**
	 * Sanitize URL-encoded JSON fragments embedded in otherwise unstructured text.
	 *
	 * @param string $message Log message.
	 * @return string
	 */
	private static function sanitize_urlencoded_json_fragments( $message ) {
		return preg_replace_callback(
			'/((?:^|[?&=:\s]))((?:%7B|%5B)[^&\s,;#)\]}]+)/i',
			function( $matches ) {
				$encoded_value = $matches[2];
				$decoded_value = rawurldecode( $encoded_value );
				$sanitized     = self::sanitize_json_string( $decoded_value );

				// application/x-www-form-urlencoded data may represent spaces as '+'.
				if ( null === $sanitized && false !== strpos( $encoded_value, '+' ) ) {
					$decoded_value = urldecode( $encoded_value );
					$sanitized     = self::sanitize_json_string( $decoded_value );
				}

				if ( null === $sanitized ) {
					return $matches[0];
				}

				return $matches[1] . rawurlencode( $sanitized );
			},
			$message
		);
	}

	/**
	 * Sanitize a scalar log message.
	 *
	 * This is the final safety net for every plugin log entry. It deliberately
	 * runs after callers have composed their diagnostic text so a raw print_r(),
	 * JSON response, query string, URL or exception message cannot bypass the
	 * masking rules.
	 *
	 * @param mixed $message Log message.
	 * @return string
	 */
	public static function sanitize_message( $message ) {
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = print_r( self::sanitize_data( $message ), true );
		} else {
			$message = (string) $message;
		}

		// Prefer parse-then-recurse for valid JSON, including JSON strings nested
		// inside structured values. This avoids relying on regex for escaped JSON.
		$json_message = self::sanitize_json_string( $message );
		if ( null !== $json_message ) {
			$message = $json_message;
		} else {
			$message = self::sanitize_embedded_json_fragments( $message );
		}

		// Also protect a JSON payload that has been URL-encoded inside a URL, query
		// parameter, error string or other diagnostic text.
		$message = self::sanitize_urlencoded_json_fragments( $message );

		// Never expose configured credentials if they are echoed back by Peach,
		// cURL, an exception, or a future diagnostic statement.
		foreach ( self::get_known_secrets() as $secret ) {
			$message = str_replace( $secret, '***', $message );
			$encoded = rawurlencode( $secret );
			if ( $encoded !== $secret ) {
				$message = str_ireplace( $encoded, '***', $message );
			}
		}

		// Authorization headers are credentials regardless of their field name.
		$message = preg_replace( '/\bBearer\s+[^\s,;\]\}\)]+/i', 'Bearer ***', $message );
		$message = preg_replace( '/\bBasic\s+[^\s,;\]\}\)]+/i', 'Basic ***', $message );

		$pan_keys = '(?:card(?:\.|_|%2e|%5b)?(?:number|pan)(?:%5d)?|cardnumber|card_number|pan|primaryaccountnumber)';
		$message  = self::mask_labelled_values( $message, $pan_keys, [ __CLASS__, 'mask_digits_keep_last_four' ] );

		$cvv_keys = '(?:card(?:\.|_|%2e|%5b)?(?:cvv|cvc|cvv2|cvc2|securitycode|verificationcode)(?:%5d)?|cvv|cvc|cvv2|cvc2|securitycode|security_code|verificationcode|verification_code)';
		$message  = self::mask_labelled_values(
			$message,
			$cvv_keys,
			function() {
				return '***';
			}
		);

		$bin_keys = '(?:card(?:\.|_|%2e|%5b)?bin(?:%5d)?|card_bin|cardbin|bin)';
		$message  = self::mask_labelled_values(
			$message,
			$bin_keys,
			function( $value ) {
				return str_repeat( '*', max( 3, strlen( (string) $value ) ) );
			}
		);

		$secret_keys = '(?:clientsecret|client_secret|access_token|accesstoken|refresh_token|refreshtoken|id_token|idtoken|secret_token|secrettoken|secret|token|api_key|apikey|card_webhook_key|webhook_key|webhookkey|password|authentication\.(?:password|userid)|authorization|x-webhook-signature|webhook_signature|signature|_wpnonce|nonce)';
		$message     = self::mask_labelled_values(
			$message,
			$secret_keys,
			function() {
				return '***';
			}
		);

		$identifier_keys = '(?:registration(?:id|_id|token|_token|\s+id)?|cardtoken|card_token|payment_registration_id|payment_initial_id|initial(?:transactionid|_transaction_id|\s+transaction\s+id)|cardholderinitiatedtransactionid|payment_order_id|payment(?:id|_id|\s+id)|transaction(?:id|_id|\s+id)|checkout(?:id|_id|\s+id)|webhook(?:id|_id|\s+id)|x-webhook-id|peach_return_token|return_token|order_key|current\s+registration|parent\s+registration|previous\s+registration|new\s+registration)';
		$message         = self::mask_labelled_values( $message, $identifier_keys, [ __CLASS__, 'mask_identifier' ] );

		// Peach response objects frequently use a generic `id` for the payment ID.
		// Mask that key in structured/serialized data without masking useful human
		// log labels such as WooCommerce User ID / Order ID / Subscription ID.
		$message = preg_replace_callback(
			'/(["\']id["\']\s*:\s*["\'])([^"\']*)(["\'])/i',
			function( $matches ) {
				return $matches[1] . self::mask_identifier( $matches[2] ) . $matches[3];
			},
			$message
		);
		$message = preg_replace_callback(
			'/(\[id\]\s*=>\s*)([^\r\n\[]*)/i',
			function( $matches ) {
				return $matches[1] . self::mask_identifier( trim( $matches[2] ) );
			},
			$message
		);

		// Registration-token arrays are commonly logged as an indexed list. Their
		// individual values have no field label, so sanitize the known container.
		$message = preg_replace_callback(
			'/(["\'](?:cardTokens|registrationIds|registrationTokens)["\']\s*:\s*\[)([^\]]*)(\])/i',
			function( $matches ) {
				$items = preg_replace_callback(
					'/(["\'])([^"\']+)(["\'])/',
					function( $item ) {
						return $item[1] . self::mask_identifier( $item[2] ) . $item[3];
					},
					$matches[2]
				);
				return $matches[1] . $items . $matches[3];
			},
			$message
		);

		$message = preg_replace_callback(
			'/(\[(?:cardTokens|registrationIds|registrationTokens)\]\s*=>\s*Array\s*\(\s*)(.*?)(\r?\n\s*\))/is',
			function( $matches ) {
				$items = preg_replace_callback(
					'/(\[\d+\]\s*=>\s*)([^\r\n]+)/',
					function( $item ) {
						return $item[1] . self::mask_identifier( trim( $item[2] ) );
					},
					$matches[2]
				);
				return $matches[1] . $items . $matches[3];
			},
			$message
		);

		// Card metadata and customer details may be useful diagnostically as field
		// presence, but their raw values are not required in logs.
		$private_keys = '(?:card_holder|cardholder|holder|card\.holder|card_expirymonth|card_expiryyear|expirymonth|expiryyear|card\.expirymonth|card\.expiryyear|email|customer\.email|givenname|given_name|surname|firstname|first_name|lastname|last_name|phone|mobile|billing_address|billingaddress|shipping_address|shippingaddress|street1|street2|address1|address2|city|postcode|postalcode)';
		$message      = self::mask_labelled_values(
			$message,
			$private_keys,
			function() {
				return '***';
			}
		);

		// Sensitive query parameters can appear inside return/cancel URLs.
		$message = preg_replace_callback(
			'/([?&](?:key|order_key|peach_return_token|return_token|token|_wpnonce|signature)=)([^&\s]+)/i',
			function( $matches ) {
				return $matches[1] . self::mask_identifier( rawurldecode( $matches[2] ) );
			},
			$message
		);

		// Peach resource URLs/paths often contain registration, checkout or payment IDs.
		$message = preg_replace_callback(
			'#(/(?:v1/)?(?:registrations|payments|checkouts|transactions)/)([^/?&\s]+)#i',
			function( $matches ) {
				return $matches[1] . self::mask_identifier( rawurldecode( $matches[2] ) );
			},
			$message
		);

		// Last-resort PAN safety net for an unlabelled card number. This also masks
		// long numeric identifiers rather than risk allowing an unexpected PAN shape
		// to escape through a third-party error string.
		$message = preg_replace_callback(
			'/(?<!\d)(?:\d[ -]?){11,18}\d(?!\d)/',
			function( $matches ) {
				return self::mask_digits_keep_last_four( $matches[0] );
			},
			$message
		);

		// Cosmetic normalization only: independent credential rules can both mask
		// a free-text Authorization header, yielding "*** ***". Collapse already
		// masked header values to a single marker after every security rule has run.
		// This deliberately does not alter or replace any credential masking above.
		$message = preg_replace(
			'/((?:^|[^A-Za-z0-9_-])(?:Proxy-)?Authorization\s*(?::|=>|=)\s*)\*{3}(?:\s+\*{3})+/i',
			'$1***',
			$message
		);

		return $message;
	}

	/**
	 * Recursively sanitize structured data before it is converted to text.
	 *
	 * @param mixed  $data        Data to sanitize.
	 * @param string $parent_path Parent field path.
	 * @return mixed
	 */
	public static function sanitize_data( $data, $parent_path = '' ) {
		if ( is_object( $data ) ) {
			$data = get_object_vars( $data );
		}

		if ( ! is_array( $data ) ) {
			if ( ! is_scalar( $data ) ) {
				return $data;
			}

			$sanitized_scalar = self::sanitize_message( (string) $data );

			// Preserve the original scalar type when sanitization did not alter its
			// textual value. This keeps harmless JSON numbers/booleans intact after
			// decode -> sanitize -> encode, while any value that actually requires
			// masking is safely returned as the masked string.
			if ( ! is_string( $data ) && (string) $data === $sanitized_scalar ) {
				return $data;
			}

			return $sanitized_scalar;
		}

		$sanitized = [];
		foreach ( $data as $key => $value ) {
			$key_string = (string) $key;
			$path       = '' === $parent_path ? $key_string : $parent_path . '.' . $key_string;
			$normalised = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $path ) );
			$key_norm   = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $key_string ) );

			if ( is_array( $value ) || is_object( $value ) ) {
				$sanitized[ $key ] = self::sanitize_data( $value, $path );
				continue;
			}

			$scalar = is_scalar( $value ) ? (string) $value : $value;

			$is_card_security_code = in_array( $key_norm, [ 'cvv', 'cvc', 'cvv2', 'cvc2', 'securitycode', 'verificationcode' ], true )
				|| ( false !== strpos( $normalised, 'card' ) && preg_match( '/(?:cvv|cvc|cvv2|cvc2|securitycode|verificationcode)$/', $normalised ) );
			if ( $is_card_security_code ) {
				$sanitized[ $key ] = '***';
				continue;
			}

			$is_card_number = in_array( $key_norm, [ 'cardnumber', 'pan', 'primaryaccountnumber' ], true ) || ( false !== strpos( $normalised, 'card' ) && preg_match( '/(?:number|pan)$/', $normalised ) );
			if ( $is_card_number ) {
				$sanitized[ $key ] = self::mask_digits_keep_last_four( $scalar );
				continue;
			}

			if ( 'bin' === $key_norm && false !== strpos( $normalised, 'card' ) ) {
				$sanitized[ $key ] = str_repeat( '*', max( 3, strlen( (string) $scalar ) ) );
				continue;
			}

			$parent_normalised = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $parent_path ) );
			if ( preg_match( '/(?:cardtokens|registrationids|registrationtokens)$/', $parent_normalised ) ) {
				$sanitized[ $key ] = self::mask_identifier( $scalar );
				continue;
			}

			if ( in_array( $key_norm, [ 'clientsecret', 'accesstoken', 'refreshtoken', 'idtoken', 'secrettoken', 'secret', 'token', 'apikey', 'cardwebhookkey', 'webhookkey', 'password', 'authenticationpassword', 'authenticationuserid', 'authorization', 'signature', 'webhooksignature', 'wpnonce', 'nonce' ], true ) ) {
				$sanitized[ $key ] = '***';
				continue;
			}

			if ( in_array( $key_norm, [ 'id', 'registrationid', 'registration', 'registrationtoken', 'cardtoken', 'paymentregistrationid', 'paymentinitialid', 'initialtransactionid', 'cardholderinitiatedtransactionid', 'paymentorderid', 'paymentid', 'transactionid', 'checkoutid', 'webhookid', 'peachreturntoken', 'returntoken', 'orderkey' ], true ) ) {
				$sanitized[ $key ] = self::mask_identifier( $scalar );
				continue;
			}

			if ( in_array( $key_norm, [ 'holder', 'cardholder', 'email', 'billingaddress', 'shippingaddress', 'expirymonth', 'expiryyear', 'givenname', 'surname', 'firstname', 'lastname', 'phone', 'mobile', 'street1', 'street2', 'address1', 'address2', 'city', 'postcode', 'postalcode' ], true ) || false !== strpos( $normalised, 'cardholder' ) || preg_match( '/card(?:holder|expirymonth|expiryyear)$/', $normalised ) ) {
				$sanitized[ $key ] = '***';
				continue;
			}

			if ( is_scalar( $value ) ) {
				$sanitized_scalar = self::sanitize_message( (string) $value );

				// Preserve harmless non-string scalar types (int/float/bool) when
				// sanitization leaves their textual value unchanged. Strings remain
				// strings, and any masked value remains the resulting masked string.
				if ( ! is_string( $value ) && (string) $value === $sanitized_scalar ) {
					$sanitized[ $key ] = $value;
				} else {
					$sanitized[ $key ] = $sanitized_scalar;
				}
			} else {
				$sanitized[ $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * Final write boundary for every plugin log entry.
	 *
	 * All public logging methods route through here so sanitization cannot be
	 * bypassed by a caller that needs a custom WooCommerce log source.
	 *
	 * @param string      $level   Log level.
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	private static function write( $level, $message, $source = null ) {
		$logger  = self::get_logger();
		$message = self::sanitize_message( $message );
		$context = [ 'source' => $source ? (string) $source : self::$context ];

		switch ( strtolower( (string) $level ) ) {
			case 'info':
				$logger->info( $message, $context );
				break;
			case 'error':
				$logger->error( $message, $context );
				break;
			case 'warning':
				$logger->warning( $message, $context );
				break;
			case 'debug':
			default:
				$logger->debug( $message, $context );
				break;
		}
	}

	/**
	 * Add info log entry.
	 *
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	public static function info( $message, $source = null ) {
		self::write( 'info', $message, $source );
	}

	/**
	 * Add error log entry.
	 *
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	public static function error( $message, $source = null ) {
		self::write( 'error', $message, $source );
	}

	/**
	 * Add debug log entry.
	 *
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	public static function debug( $message, $source = null ) {
		self::write( 'debug', $message, $source );
	}

	/**
	 * Add warning log entry.
	 *
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	public static function warning( $message, $source = null ) {
		self::write( 'warning', $message, $source );
	}

	/**
	 * Log any type of message with level.
	 *
	 * @param string      $level   Log level.
	 * @param mixed       $message Log message.
	 * @param string|null $source  Optional WooCommerce log source.
	 */
	public static function log( $level, $message, $source = null ) {
		self::write( $level, $message, $source );
	}

}
