<?php
/**
 * Hosted Peach Payments Gateway class.
 */

defined( 'ABSPATH' ) || exit;

class WC_Gateway_Peach_Hosted extends WC_Payment_Gateway {
	
	// Declare all formerly dynamic properties
	public $checkout_methods;
	public $checkout_methods_select;
	public $consolidated_label;
	public $consolidated_label_logos;
	public $embed_payments;
	public $embed_clientid;
	public $embed_clientsecret;
	public $embed_merchantid;
	public $card_storage;
	public $orderids;
	public $auto_complete;
	public $order_status;
	public $transaction_mode;
	public $peach_order_status;
	public $access_token;
	public $secret;
	public $channel_3ds;
	public $channel;
	public $card_webhook_key;

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
		$this->supports           = [ 'products', 'refunds', 'subscriptions', 'subscription_cancellation', 'subscription_reactivation', 'subscription_suspension', 'subscription_amount_changes', 'subscription_date_changes', 'subscription_payment_method_change', 'multiple_subscriptions', 'manual_subscriptions', 'subscription_payment_method_change_admin' ];

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
		if ( ! isset( $_GET['resourcePath'] ) || ! isset( $_GET['order_id'] ) ) {
			wp_redirect( wc_get_page_permalink( 'cart' ) );
			exit;
		}
	
		$order_id      = absint( $_GET['order_id'] );
		$resource_path = sanitize_text_field( $_GET['resourcePath'] );
		$order         = wc_get_order( $order_id );
	
		if ( ! $order || ! $order->get_id() ) {
			PP_Gateway_Logger::error( "[Hosted] Order not found: $order_id" );
			wp_redirect( wc_get_page_permalink( 'cart' ) );
			exit;
		}
	
		// Call Peach API to get result
		$url      = PP_Peach_API::get_endpoint_url( $resource_path );
		$response = PP_Peach_API::request( $url, [], 'GET' );
	
		if ( is_wp_error( $response ) ) {
			PP_Gateway_Logger::error( "[Hosted] API call failed for Order $order_id: " . $response->get_error_message() );
			wc_add_notice( __( 'Payment verification failed. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
	
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
	
		PP_Gateway_Logger::debug( "[Hosted] Peach Response for Order $order_id: " . print_r( $result, true ) );
	
		$code               = $result['result']['code'] ?? '';
		$peach_order_id     = $result['id'] ?? '';
		$registration_id    = $result['registrationId'] ?? '';
	
		// Save payment_order_id if not already saved
		if ( $peach_order_id && ! metadata_exists( 'post', $order_id, 'payment_order_id' ) ) {
			//update_post_meta( $order_id, 'payment_order_id', sanitize_text_field( $peach_order_id ) );
			$order->update_meta_data( 'payment_order_id', sanitize_text_field( $peach_order_id ) );
			PP_Gateway_Logger::debug( "[Hosted] Stored payment_order_id: $peach_order_id for Order $order_id" );
		}
	
		// Save registrationId if not already saved
		if ( $registration_id && ! metadata_exists( 'post', $order_id, 'payment_registration_id' ) ) {
			//update_post_meta( $order_id, 'payment_registration_id', sanitize_text_field( $registration_id ) );
			$order->update_meta_data( 'payment_registration_id', sanitize_text_field( $registration_id ) );
			PP_Gateway_Logger::debug( "[Hosted] Stored payment_registration_id: $registration_id for Order $order_id" );
		}
		
		$order->save();
	
		if ( str_starts_with( $code, '000.000.' ) || str_starts_with( $code, '000.100.1' ) ) {
			// Successful payment
			if ( $order->get_status() === 'pending' || $order->get_status() === 'failed' ) {
				$order->payment_complete( $peach_order_id );
				$order->add_order_note( __( 'Payment completed via Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
			}
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
	
		} elseif ( str_starts_with( $code, '000.200.' ) ) {
			// Payment pending
			$order->update_status( 'on-hold', __( 'Payment pending via Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
	
		} else {
			// Payment failed
			$order->update_status( 'failed', __( 'Payment failed or declined by Peach Payments.', WC_PEACH_TEXT_DOMAIN ) );
			$order->add_order_note( 'Payment failed or declined by Peach Payments.',0,false);
			wc_add_notice( __( 'Payment was declined. Please try again or use a different payment method.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}
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
		
		//echo '<pre>'.print_r($_POST, true).'</pre>'; die();
		
		if ( ! isset( $_POST, $_GET['order_id'] ) ) {
			wp_die( 'Missing parameters.' );
		}
	
		$order_id  = absint( $_GET['order_id'] );
		$order     = wc_get_order( $order_id );
	
		if ( ! $order ) {
			wp_die( 'Order not found.' );
		}
	
		PP_Gateway_Order_Utils::handle_payment_status( $order, $_POST );
	
		// Redirect to order received page
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
