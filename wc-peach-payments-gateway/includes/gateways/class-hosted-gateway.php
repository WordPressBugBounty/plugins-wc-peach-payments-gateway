<?php
/**
 * Hosted Peach Payments Gateway class.
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Peach_Hosted extends WC_Payment_Gateway {
	
	// Declare all gateway settings properties explicitly for PHP 8.2+ compatibility.
	// These are populated from $this->form_fields / saved gateway settings in __construct().
	public $enabled;
	public $section_general_title;
	public $title;
	public $description;
	public $redirect_notice;
	public $checkout_methods;
	public $checkout_methods_select;
	public $consolidated_label;
	public $consolidated_label_logos;
	public $embed_payments;
	public $section_api_title;
	public $embed_clientid;
	public $embed_clientsecret;
	public $embed_merchantid;
	public $access_token;
	public $channel_3ds;
	public $secret;
	public $section_req_title;
	public $channel;
	public $section_webhooks_title;
	public $card_webhook_key;
	public $section_cart_title;
	public $card_storage;
	public $section_wc_title;
	public $peach_order_status;
	public $orderids;
	public $auto_complete;
	public $card_only;
	public $order_status;
	public $transaction_mode;

	/**
	 * Order statuses for form field dropdown.
	 *
	 * @var array
	 */
	public $peach_statusses = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = 'peach-payments';
		$this->method_title       = __( 'Peach Payments', 'woocommerce-gateway-peach-payments' );
		$this->method_description = __( 'Secure hosted checkout and tokenised card payments via Peach Payments.', 'woocommerce-gateway-peach-payments' );
		$this->has_fields         = false;
		$this->supports           = [ 'products', 'refunds', 'subscriptions', 'subscription_cancellation', 'subscription_reactivation', 'subscription_suspension', 'subscription_amount_changes', 'subscription_date_changes', 'subscription_payment_method_change', 'subscription_payment_method_change_customer', 'multiple_subscriptions', 'manual_subscriptions', 'subscription_payment_method_change_admin' ];

		$this->icon = WC_PEACH_GATEWAY_URL . 'assets/images/Peach_Payments_Primary_logo.png';

		// Set order status options
		$this->peach_statusses = wc_get_order_statuses();

		// Load the settings.
		$this->init_form_fields();
		$this->init_settings();

		// Define user settings variables
		foreach ( $this->form_fields as $key => $field ) {
			$this->$key = $this->get_option( $key );
		}
		
		add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'custom_cart_validation' ] , 10, 2);
		add_action( 'woocommerce_blocks_checkout_order_processed', [ __CLASS__, 'custom_cart_validation_blocks' ] , 10, 1 );

		// Save admin options
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		// Hook into receipt page
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'render_receipt_page' ] );
		// Handle return redirect from Peach
		add_action( 'woocommerce_api_' . strtolower( __CLASS__ ), [ $this, 'handle_return_from_peach' ] );
		
		// Ensure cleared fields revert to defaults on save
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, function() {
			$defaults = [
				'title'           => 'Peach Payments', // default title
				'description'     => 'Pay securely via Peach Payments.', // default description
				'redirect_notice' => 'You will be redirected to Peach Payments to complete your purchase.' // default notice
			];
		
			foreach ( $defaults as $key => $default ) {
				if ( isset( $_POST[ $this->get_field_key( $key ) ] ) && $_POST[ $this->get_field_key( $key ) ] === '' ) {
					$_POST[ $this->get_field_key( $key ) ] = $default;
				}
			}
		});


	}
	
	/**
	 * Force defaults on settings page display.
	 */
	public function init_settings() {
		parent::init_settings();
	
		$defaults = [
			'title'           => 'Peach Payments',
			'description'     => 'Pay securely via Peach Payments.',
			'redirect_notice' => 'You will be redirected to Peach Payments to complete your purchase.'
		];
	
		foreach ( $defaults as $key => $default ) {
			if ( empty( $this->settings[ $key ] ) ) {
				$this->settings[ $key ] = $default;
			}
		}
	}
	
	public function admin_options() {
		echo '<div class="peach-payments-admin-wrapper">';
		echo '<div class="peach-admin-grid">';
	
		// Column 1: Settings form
		echo '<div class="peach-admin-column peach-admin-settings">';
		//echo '<form method="post" id="mainform" action="">';
			wp_nonce_field( 'woocommerce-settings' );
			do_action( 'woocommerce_settings_start', $this->id );
			parent::admin_options(); // renders fields normally
			do_action( 'woocommerce_settings_end', $this->id );
			echo '
			<h3 class="wc-settings-sub-title " id="woocommerce_peach-payments_section_rollback_title">Version Rollback</h3>
			<p><strong>Note:</strong> The rollback capability has been deprecated as of version 4.0 of this plugin.</p>
			';
		//echo '</form>';
		echo '</div>';
	
		// Column 2: Static info / support
		echo '<div class="peach-admin-column peach-admin-sidebar">';
		echo '<h2><img src="'.WC_PEACH_GATEWAY_URL . 'assets/images/Peach_Payments_Primary_logo.png" alt="Peach Payments" width="150" /></h2>';
		echo '<p class="intro">The secure African payment gateway<br>with easy integrations, 365-day support, and advanced orchestration.</p>';
		echo '<h3>My Dashboard</h3>';
		echo '<p><a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow" alt="Peach Payments Dashboard">Log in to your Peach Payments Dashboard</a></p>';
		echo '<h3>Need Help?</h3>';
		echo '<p><a href="https://www.peachpayments.com/resources/contact" target="_blank" rel="nofollow">Contact Us</a><br>Available 365 days a year by phone and email</p>';
		echo '<p><a href="https://support.peachpayments.com/support/home" target="_blank" rel="nofollow">Knowledge base</a><br>Everything you need to know</p>';
		echo '<p><a href="https://support.peachpayments.com/support/tickets/new" target="_blank" rel="nofollow">New support ticket</a><br>Create a new support ticket</p>';
		echo '<p><a href="https://support.peachpayments.com/support/login" target="_blank" rel="nofollow">Check ticket status</a><br>Log in to our support site to check a ticket</p>';
		echo '</div>';
	
		echo '</div>'; // end grid
		echo '</div>'; // end wrapper
	}
	
	public function process_admin_options(){
		if ( isset( $_POST ) && is_array( $_POST ) ) {
			$defaults = [
				'title'           => 'Peach Payments',
				'description'     => 'Pay securely via Peach Payments.',
				'redirect_notice' => 'You will be redirected to Peach Payments to complete your purchase.'
			];
	
			foreach ( $defaults as $key => $default ) {
				$field_key = $this->get_field_key( $key );
				if ( isset( $_POST[ $field_key ] ) && $_POST[ $field_key ] === '' ) {
					$_POST[ $field_key ] = $default;
				}
			}
		}
		return parent::process_admin_options();
	}

	/**
	 * Initialize Gateway Settings Form Fields
	 */
	public function init_form_fields() {
		require_once WC_PEACH_GATEWAY_PATH . 'includes/gateways/form-fields-peach-hosted.php';
		$this->form_fields = PP_Gateway_Form_Fields_Peach_Hosted::get_fields( $this->peach_statusses );
	}

	/**
	 * Replace title with logo in admin payment settings page
	 */
	public function custom_gateway_title( $title, $id ) {
		if ( $id === $this->id && is_admin() ) {
			return '<img name="Peach Payment Gateway" src="' . esc_url( WC_PEACH_GATEWAY_URL . 'assets/images/Peach_Payments_Primary_logo.png' ) . '" width="100" alt="Peach Payment Gateway" class="back-title">';
		}
		return $title;
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id
	 * @return array|null
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
	
		return [
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		];
	}
	
	public function render_receipt_page( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}

		// Generate checkout session with Peach
		$response = PP_Peach_API::create_checkout( $order );

		if ( is_wp_error( $response ) ) {
			echo '<p>' . esc_html__( 'An error occurred while connecting to Peach Payments. Please try again.', WC_PEACH_TEXT_DOMAIN ) . '</p>';
			return;
		}

		$redirect_url = $response['redirectUrl'] ?? '';

		if ( ! $redirect_url ) {
			echo '<p>' . esc_html__( 'Unable to retrieve the payment redirect URL. Please contact support.', WC_PEACH_TEXT_DOMAIN ) . '</p>';
			return;
		}

		echo '<div class="pp-redirect-message" style="text-align: center; padding: 2em;">
			<p style="font-size: 18px;">' . esc_html__( 'We’re redirecting you to Peach Payments to complete your purchase. Please do not close or refresh this page.', WC_PEACH_TEXT_DOMAIN ) . '</p>
			<div style="margin-top: 1em;">
				<img src="' . esc_url( WC_PEACH_GATEWAY_URL . 'assets/images/spinner.svg' ) . '" width="40" height="40" alt="Loading...">
			</div>
		</div>';

		echo '<script>
			setTimeout(function() {
				window.location.href = "' . $redirect_url . '";
			}, 2000);
		</script>';
	}

	
	public function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wp_kses_post( $this->description ) );
		}
		
		$logos = PP_Gateway_Settings::get('consolidated_label_logos');
	
		$redirect_notice = $this->get_option( 'redirect_notice' );
		$default_notice = __( 'You will be redirected to Peach Payments to complete your purchase.', WC_PEACH_TEXT_DOMAIN );
		
		if($logos && is_array($logos) && !empty($logos)){
			echo '<p class="peach-logos">';
			foreach($logos as $logo){
				echo '<span><img name="peach_payments_logos" src="' . esc_url( WC_PEACH_GATEWAY_URL . 'assets/images/'.$logo.'.png' ) . '" alt="Peach Payments Payment Options" /></span>';
			}
			echo '</p>';
		}
	
		echo '<p class="peach-redirect-message">' . esc_html( $redirect_notice ?: $default_notice ) . '</p>';
	}
	
	public function supports( $feature ) {
		if ( 'payment_block_support' === $feature ) {
			return true;
		}
		return parent::supports( $feature );
	}
	
	/**
	 * Handle the return from Peach Payments (resourcePath).
	 */
	public function handle_peach_return() {
		$this->handle_return_from_peach();
	}


	
	/**
	 * Get the correct order ID to use based on settings and plugin compatibility.
	 *
	 * @param WC_Order $order
	 * @return string
	 */
	protected function get_order_id_to_use( $order ) {
		$order_id = $order->get_id();
		$use_default = $this->get_option( 'use_default_order_ids', 'no' );
	
		if ( $use_default === 'yes' ) {
			return (string) $order_id;
		}
	
		// Tyche plugin support
		if ( function_exists( 'wt_get_order_number' ) ) {
			return wt_get_order_number( $order );
		}
	
		// WooCommerce Sequential Order Numbers (free or pro)
		if ( method_exists( $order, 'get_order_number' ) ) {
			return $order->get_order_number();
		}
	
		// Fallback to raw ID
		return (string) $order_id;
	}

	public static function generate_access_token() {
		$url = self::is_test_mode() ? 'https://sandbox-dashboard.peachpayments.com/api/oauth/token' : 'https://dashboard.peachpayments.com/api/oauth/token';
		
		$body = json_encode([
			'clientId' => PP_Gateway_Settings::get('embed_clientid'),
			'clientSecret' => PP_Gateway_Settings::get('embed_clientsecret'),
			'merchantId' => PP_Gateway_Settings::get('embed_merchantid')
		]);
	
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Content-Type: application/json' ] );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		$response = curl_exec( $ch );
		curl_close( $ch );
	
		$data = json_decode( $response, true );
		
		return [
			'access_token' => $data['access_token'] ?? '',
			'raw' => $data,
			'url' => $url,
			'body' => json_decode( $body, true )
		];
	}
	
	public static function create_checkout_session( $access_token, $payload ) {
		$url = self::is_test_mode() ? 'https://testsecure.peachpayments.com/v2/checkout' : 'https://secure.peachpayments.com/v2/checkout';
	
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Authorization: Bearer ' . $access_token,
			'Referer: ' . get_site_url()
		] );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( $payload ) );
		$response = curl_exec( $ch );
		curl_close( $ch );
		
		//PP_Peach_API::log_error( 'Checkout Session Response', $response, $payload, '' );
	
		return json_decode( $response, true );
	}
	
	public function handle_return_from_peach() {
		if ( ! isset( $_GET['order_id'] ) ) {
			wp_die( esc_html__( 'Missing order parameter.', WC_PEACH_TEXT_DOMAIN ) );
		}
	
		$order_id = absint( wp_unslash( $_GET['order_id'] ) );
		$order    = wc_get_order( $order_id );
	
		if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
			wp_die( esc_html__( 'Order not found.', WC_PEACH_TEXT_DOMAIN ) );
		}

		if ( $order->get_payment_method() !== $this->id ) {
			PP_Gateway_Logger::warning( 'Peach hosted return rejected for order #' . $order_id . ': order does not use the Peach Payments gateway.' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$resource_path = '';
		if ( isset( $_GET['resourcePath'] ) ) {
			$resource_path = sanitize_text_field( wp_unslash( $_GET['resourcePath'] ) );
		} elseif ( isset( $_POST['resourcePath'] ) ) {
			$resource_path = sanitize_text_field( wp_unslash( $_POST['resourcePath'] ) );
		}

		$posted_transaction_id = '';
		if ( isset( $_POST['id'] ) ) {
			$posted_transaction_id = sanitize_text_field( wp_unslash( $_POST['id'] ) );
		} elseif ( isset( $_POST['payment_id'] ) ) {
			$posted_transaction_id = sanitize_text_field( wp_unslash( $_POST['payment_id'] ) );
		} elseif ( isset( $_POST['paymentId'] ) ) {
			$posted_transaction_id = sanitize_text_field( wp_unslash( $_POST['paymentId'] ) );
		}

		$returned_order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		if ( '' !== $returned_order_key && ! hash_equals( (string) $order->get_order_key(), (string) $returned_order_key ) ) {
			PP_Gateway_Logger::warning( 'Peach hosted return rejected for order #' . $order_id . ': order key mismatch.' );
			wc_add_notice( __( 'Payment verification failed. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$stored_return_token = trim( (string) $order->get_meta( '_peach_return_token', true ) );
		$returned_token      = isset( $_GET['peach_return_token'] ) ? sanitize_text_field( wp_unslash( $_GET['peach_return_token'] ) ) : '';

		if ( '' !== $stored_return_token && ! hash_equals( $stored_return_token, (string) $returned_token ) ) {
			PP_Gateway_Logger::warning( 'Peach hosted return rejected for order #' . $order_id . ': return token mismatch.' );
			wc_add_notice( __( 'Payment verification failed. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$this->redirect_paid_or_processed_order_to_thank_you( $order, 'order was already paid before hosted return verification completed' );

		if ( '' === $resource_path && '' === $posted_transaction_id ) {
			PP_Gateway_Logger::warning( 'Peach hosted return for order #' . $order_id . ' did not include a resourcePath or transaction ID. Public POST result data was not trusted and the order was left unchanged.' );
			wc_add_notice( __( 'We could not verify your Peach Payments transaction yet. Please try again or contact support if you were charged.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		if ( '' !== $resource_path ) {
			$resource_check = PP_Peach_API::validate_checkout_resource_for_order( $order, $resource_path );
			if ( is_wp_error( $resource_check ) ) {
				PP_Gateway_Logger::warning( 'Peach hosted return rejected for order #' . $order_id . ': ' . $resource_check->get_error_message() );
				wc_add_notice( __( 'Payment verification failed. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
				wp_safe_redirect( $order->get_checkout_payment_url() );
				exit;
			}

			$result = PP_Peach_API::get_payment_result_from_resource_path( $resource_path );
		} else {
			PP_Gateway_Logger::info( 'Peach hosted return for order #' . $order_id . ' used a POST transaction ID fallback. Posted payment result fields were ignored and the transaction was verified server-to-server.' );
			$result = PP_Peach_API::get_payment_result_from_transaction_id( $posted_transaction_id );
		}
		if ( is_wp_error( $result ) ) {
			$this->redirect_paid_or_processed_order_to_thank_you( $order, 'hosted return verification failed after the order had already been paid: ' . $result->get_error_message() );

			PP_Gateway_Logger::error( 'Peach hosted return verification failed for order #' . $order_id . ': ' . $result->get_error_message() );
			wc_add_notice( __( 'Payment verification failed. Please try again or contact support if you were charged.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}


		$validation = PP_Peach_API::validate_payment_result_for_order( $order, $result, 'hosted_return' );
		if ( is_wp_error( $validation ) ) {
			$this->redirect_paid_or_processed_order_to_thank_you( $order, 'hosted return validation failed after the order had already been paid: ' . $validation->get_error_message() );

			PP_Gateway_Logger::error( 'Peach hosted return rejected for order #' . $order_id . ': ' . $validation->get_error_message() . ' Response: ' . print_r( $result, true ) );
			$order->add_order_note( 'Peach payment return rejected: ' . $validation->get_error_message() );
			$order->save();
			wc_add_notice( __( 'Payment verification failed. Please try again or contact support if you were charged.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$code = isset( $result['result']['code'] ) ? sanitize_text_field( (string) $result['result']['code'] ) : '';

		if ( PP_Gateway_Order_Utils::is_non_final_result_code( $code ) ) {
			$order->update_status( 'on-hold', __( 'Payment pending via Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
			$order->delete_meta_data( '_peach_return_token' );
			$order->save();
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		PP_Gateway_Order_Utils::handle_payment_status( $order, $result );
		$order->delete_meta_data( '_peach_return_token' );
		$order->save();

		if ( PP_Gateway_Order_Utils::is_successful_result_code( $code ) || $order->is_paid() || PP_Gateway_Order_Utils::initial_payment_already_processed( $order ) ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		wc_add_notice( __( 'Payment was declined. Please try again or use a different payment method.', WC_PEACH_TEXT_DOMAIN ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Redirect a validated Peach hosted return to the thank-you page when the
	 * WooCommerce order has already been finalised by another trusted flow, such
	 * as the Peach webhook.
	 *
	 * This prevents the customer being sent back to the pay-for-order screen for
	 * an order that has already moved to Processing/Completed or has already been
	 * marked as processed by the gateway.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $reason Internal log reason.
	 * @return void
	 */
	private function redirect_paid_or_processed_order_to_thank_you( WC_Order $order, $reason = '' ) {
		$latest_order = wc_get_order( $order->get_id() );

		if ( $latest_order && is_a( $latest_order, 'WC_Order' ) ) {
			$order = $latest_order;
		}

		if ( ! $order->is_paid() && ! PP_Gateway_Order_Utils::initial_payment_already_processed( $order ) ) {
			return;
		}

		$order->delete_meta_data( '_peach_return_token' );
		$order->save();

		$log_reason = rtrim( trim( (string) $reason ), '.' );
		if ( '' !== $log_reason ) {
			PP_Gateway_Logger::info( 'Peach hosted return for order #' . $order->get_id() . ' redirected to the thank-you page because ' . $log_reason . '.' );
		}

		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Check if the gateway is in test mode.
	 *
	 * @return bool
	 */
	public static function is_test_mode() {
		return PP_Gateway_Settings::get('transaction_mode') === 'INTEGRATOR_TEST';
	}
	
	/**
	 * Prevent mixed-basked checkout - blocks
	 *
	 */
	public static function custom_cart_validation_blocks($order){
		$phone = (string) $order->get_billing_phone();
		
		$has_subscription = false;
		$has_non_subscription = false;
		
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();

			if ( $product && $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) ) ) {
				$has_subscription = true;
			} else {
				$has_non_subscription = true;
			}

			if ( $has_subscription && $has_non_subscription ) {
				break;
			}
		}
		
		// If cart contains a subscription and has more than 1 item (i.e. mixed)
		/* UPDATE: must be able to have miced cart - 20251120 */
		/*
		if ( $has_subscription && $has_non_subscription) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'mixed_card',
					__( 'Peach Payments does not support mixed carts with subscriptions and other products. Please purchase them separately or choose another payment method.', WC_PEACH_TEXT_DOMAIN ),
					400
				);
			}
		}
		*/
		
		if($has_subscription){
			$compatable = PP_Gateway_Order_Utils::find_subscription_plugins();
			if(!$compatable){
				if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
						'incompatable_subscription_plugin',
						__( 'Peach Payments is not compatible with the subscription system currently used on this site. Please contact the website support team for assistance.', WC_PEACH_TEXT_DOMAIN ),
						400
					);
				}
			}
		}
		
		if ( $phone === '' ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'missing_phone',
					__( 'Please enter a phone number for billing.', WC_PEACH_TEXT_DOMAIN ),
					400
				);
			}
		}

		//Validate if the phone number contains only digits and has 10-15 characters
		if (! preg_match( '/^.{5,24}$/', $phone ) ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'invalid_phone',
					__( 'Please enter a valid phone number (5-24 digits, optional "+").', WC_PEACH_TEXT_DOMAIN ),
					400
				);
			}
		}
		
		//Ensure Cart Total is greater that 1
		$order_total = (float) $order->get_total();
		if ( $order_total < 1 ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
					'cart_total_too_low',
					__( 'Your current cart total is %1$s — you must have a cart total of at least %2$s to place your order.', WC_PEACH_TEXT_DOMAIN ),
					400
				);
			}
		}
	}
	
	/**
	 * Prevent mixed-basked checkout
	 *
	 */
	public static function custom_cart_validation($fields, $errors) {
		
		if ( ! isset( $_POST['payment_method'] ) || $_POST['payment_method'] !== 'peach-payments' ) {
			return;
		}
	
		$cart = WC()->cart;
		$has_subscription = false;
		$has_non_subscription = false;
	
		if ( ! empty( $cart->get_cart() ) ) {
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				$product = wc_get_product( $cart_item['product_id'] );
	
				if ( $product && $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) ) ) {
					$has_subscription = true;
				} else {
					$has_non_subscription = true;
				}
	
				if ( $has_subscription && $has_non_subscription ) {
					break;
				}
			}
		}
	
		// If cart contains a subscription and has more than 1 item (i.e. mixed)
		/* UPDATE: must be able to have miced cart - 20251120 */
		/*
		if ( $has_subscription && $has_non_subscription) {
			$errors->add( 'validation', __( 'Peach Payments does not support mixed carts with subscriptions and other products. Please purchase them separately or choose another payment method.', WC_PEACH_TEXT_DOMAIN ) );
		}
		*/
		
		if($has_subscription){
			$compatable = PP_Gateway_Order_Utils::find_subscription_plugins();
			if(!$compatable){
				$errors->add( 'validation', __( 'Peach Payments is not compatible with the subscription system currently used on this site. Please contact the website support team for assistance.', WC_PEACH_TEXT_DOMAIN ) );
			}
		}
		
		//Validate if the phone number contains only digits and has 10-15 characters
		if ( ! empty( $fields['billing_phone'] ) ) {
			if (! preg_match( '/^.{5,24}$/', $fields[ 'billing_phone' ] ) ) {
				$errors->add( 'validation', __( 'Please enter a valid phone number (5-24 digits, optional "+").', WC_PEACH_TEXT_DOMAIN ) );
			}
		}
		
		//Ensure Cart Total is greater that 1
		$minimum = 1; // Set your minimum subtotal here
	
		if ( WC()->cart && WC()->cart->get_subtotal() < $minimum ) {
			$message = sprintf(
				esc_html__(
					'Your current cart total is %1$s — you must have a cart total of at least %2$s to place your order.',
					WC_PEACH_TEXT_DOMAIN
				),
				wc_price( WC()->cart->get_subtotal() ),
				wc_price( $minimum )
			);
	
			if ( is_cart() ) {
				$errors->add( 'validation', $message );
			} else {
				$errors->add( 'validation', $message );
			}
		}
		
	}
	
	/**
	 * Process a refund.
	 *
	 * @param int    $order_id Order ID.
	 * @param float  $amount   Refund amount.
	 * @param string $reason   Reason for refund.
	 *
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		
		$order = wc_get_order( $order_id );
	
		if ( ! $order ) {
			return new WP_Error( 'peach_refund', __( 'Invalid order.', 'woocommerce-gateway-peach-payments' ) );
		}
	
		$transaction_id = get_post_meta( $order_id, 'payment_order_id', true );
	
		if ( ! $transaction_id ) {
			return new WP_Error( 'peach_refund', __( 'Missing transaction ID.', 'woocommerce-gateway-peach-payments' ) );
		}
	
		$response = PP_Peach_API::refund_payment( $transaction_id, $amount, $order->get_currency(), $reason );
	
		if ( is_wp_error( $response ) ) {
			PP_Gateway_Logger::error( "Refund failed: " . $response->get_error_message() );
			return $response;
		}
	
		$order->add_order_note( sprintf( 'Refund of %s processed successfully via Peach Payments.', wc_price( $amount ) ) );
	
		return true;
	}

	/*
	* Backed Plugin Settings Validation
	*/
	public function validate_embed_clientid_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Client ID is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
	
	public function validate_embed_clientsecret_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Client Secret is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
	
	public function validate_embed_merchantid_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Merchant ID is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
	
	public function validate_access_token_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Access Token is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
	
	public function validate_channel_3ds_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Entity ID is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
	
	public function validate_secret_field( $key, $value ) {
		
		if($value == ''){
			WC_Admin_Settings::add_error( 'Secret Token is required.' );
			$value = ''; // empty it because it is not correct
		}
	
		return $value;
	}
}
