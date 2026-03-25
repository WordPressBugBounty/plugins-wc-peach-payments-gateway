<?php
/**
 * Initializes all core components of the Peach Payments Gateway plugin.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class WC_Peach_Gateway_Init {

	/**
	 * Initialize plugin components.
	 */
	public static function init() {
		// Load plugin text domain
		load_plugin_textdomain( WC_PEACH_TEXT_DOMAIN, false, dirname( plugin_basename( WC_PEACH_GATEWAY_PLUGIN_FILE ) ) . '/languages' );
		
		// Settings renderer
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-settings.php';

		// Logger (can be used globally)
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-logger.php';

		// Utilities
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-order-utils.php';
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-subscription-utils.php';

		// Admin notices
		require_once WC_PEACH_GATEWAY_PATH . 'includes/admin/class-admin-notices.php';

		// Hosted Gateway and form fields
		require_once WC_PEACH_GATEWAY_PATH . 'includes/gateways/class-hosted-gateway.php';
		require_once WC_PEACH_GATEWAY_PATH . 'includes/gateways/form-fields-peach-hosted.php';

		// Saved Cards
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-card-manager.php';
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-my-cards-endpoint.php';
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-change-card-endpoint.php';

		// Token handling
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-token-ajax-handler.php';
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-token-add-handler.php';

		// Peach API helper
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-peach-api.php';

		// Settings renderer
		require_once WC_PEACH_GATEWAY_PATH . 'includes/admin/class-settings-renderer.php';
		
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-status-mapper.php';
		
		// Recurring Subscriptions renderer
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-subscription-handler.php';
		
		// Webhook Handler
		require_once WC_PEACH_GATEWAY_PATH . 'includes/class-webhook-handler.php';
		PP_Gateway_Webhook_Handler::init();

		// Register payment gateway
		add_filter( 'woocommerce_payment_gateways', [ __CLASS__, 'register_gateway' ] );

		// Register token AJAX handler
		add_action( 'wp_ajax_pp_delete_saved_card', [ 'PP_Gateway_Token_Ajax_Handler', 'handle_delete_card' ] );
		add_action( 'wp_ajax_pp_add_saved_card', [ 'PP_Gateway_Token_Add_Handler', 'handle_add_card' ] );
		
		// Register token add handler (AFTER settings are loaded)
		PP_Gateway_Token_Add_Handler::register();
		
		add_action( 'woocommerce_gateway_peach-payments_woocommerce_block_support', '__return_true' );
		add_action( 'woocommerce_scheduled_subscription_payment_peach-payments', [ 'PP_Gateway_Subscription_Handler', 'process_renewal_payment' ], 10, 2 );
		
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $payment_method_registry ) {
				if ( class_exists( 'PP_Peach_Payments_Blocks' ) ) {
					$payment_method_registry->register( new PP_Peach_Payments_Blocks() );
				}
			}
		);
		
		//require_once WC_PEACH_GATEWAY_PATH . 'includes/class-ipn-handler.php';
		//PP_Gateway_IPN_Handler::init();
		
				
		add_action( 'init', function() {
			if ( isset( $_GET['resourcePath'] ) && isset( $_GET['order_id'] ) ) {
				$gateway = new WC_Gateway_Peach_Hosted();
				$gateway->handle_peach_return();
			}
			
			if ( isset($_GET['pp_add_card_return']) ) {
				if(isset($_POST)){
					//PP_Gateway_Logger::info( "Add Card POST INIT. ".print_r($_POST, true) );
					if(isset($_POST['customParameters']['WOOCOMMERCE_USER'])){
						$user_id = (int)$_POST['customParameters']['WOOCOMMERCE_USER'];
						// Add Card return handler logging (POST back from Peach).
						$pp_store_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'ZAR';
						$pp_store_currency = $pp_store_currency ? $pp_store_currency : 'ZAR';
						$pp_result_code = isset( $_POST['result_code'] ) ? sanitize_text_field( wp_unslash( $_POST['result_code'] ) ) : '';
						$pp_registration_id = isset( $_POST['registrationId'] ) ? sanitize_text_field( wp_unslash( $_POST['registrationId'] ) ) : '';
						$pp_txn_id = '';
						if ( isset( $_POST['id'] ) ) { $pp_txn_id = sanitize_text_field( wp_unslash( $_POST['id'] ) ); }
						elseif ( isset( $_POST['payment_id'] ) ) { $pp_txn_id = sanitize_text_field( wp_unslash( $_POST['payment_id'] ) ); }
						elseif ( isset( $_POST['paymentId'] ) ) { $pp_txn_id = sanitize_text_field( wp_unslash( $_POST['paymentId'] ) ); }
						PP_Gateway_Logger::info( 'Add Card Return - received. User ID: ' . $user_id . ' | Result: ' . ( $pp_result_code ?: 'N/A' ) . ' | Registration ID: ' . ( $pp_registration_id ?: 'N/A' ) . ' | Transaction ID: ' . ( $pp_txn_id ?: 'N/A' ) . ' | Store currency: ' . $pp_store_currency );

						if(isset($_POST['registrationId']) && isset($_POST['result_code'])){
							if(PP_Gateway_Order_Utils::is_successful_result_code($_POST['result_code'])){
								$user_tokens = PP_Gateway_Order_Utils::get_user_card_tokens( $user_id );
								$registration_id = $_POST['registrationId'];
								
								if(empty($user_tokens) || !in_array($registration_id,$user_tokens)){
									$card_data = [
										'id'        => $registration_id,
										'num'       => 'xxxx-'.$_POST['card_last4Digits'],
										'holder'    => $_POST['card_holder'],
										'brand'     => $_POST['paymentBrand'],
										'exp_month' => $_POST['card_expiryMonth'],
										'exp_year'  => $_POST['card_expiryYear'],
									];
							
									$cards = get_user_meta( $user_id, 'my-cards', true );
									if ( ! is_array( $cards ) ) {
										$cards = [];
									}
									$cards[] = $card_data;
									update_user_meta( $user_id, 'my-cards', $cards );
								}
							// For MUR, attempt reversal (RV) after successful registration. Card must remain saved regardless of reversal result.
							$pp_currency_for_reversal = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'ZAR';
							$pp_currency_for_reversal = $pp_currency_for_reversal ? $pp_currency_for_reversal : 'ZAR';
							if ( 'MUR' === strtoupper( (string) $pp_currency_for_reversal ) ) {
								$pp_txn_id_for_reversal = '';
								if ( isset( $_POST['id'] ) ) { $pp_txn_id_for_reversal = sanitize_text_field( wp_unslash( $_POST['id'] ) ); }
								elseif ( isset( $_POST['payment_id'] ) ) { $pp_txn_id_for_reversal = sanitize_text_field( wp_unslash( $_POST['payment_id'] ) ); }
								elseif ( isset( $_POST['paymentId'] ) ) { $pp_txn_id_for_reversal = sanitize_text_field( wp_unslash( $_POST['paymentId'] ) ); }
								if ( empty( $pp_txn_id_for_reversal ) ) {
									PP_Gateway_Logger::error( 'Add Card (MUR) - reversal skipped: missing transaction ID in POST.' );
								} else {
									PP_Gateway_Logger::info( 'Add Card (MUR) - reversal attempt started. Transaction ID: ' . $pp_txn_id_for_reversal );
									$pp_reversal_resp = PP_Peach_API::reverse_preauthorisation( $pp_txn_id_for_reversal );
									if ( is_wp_error( $pp_reversal_resp ) ) {
										PP_Gateway_Logger::error( 'Add Card (MUR) - reversal API call failed. Transaction ID: ' . $pp_txn_id_for_reversal . ' | Error: ' . $pp_reversal_resp->get_error_message() );
									} else {
										$pp_rev_code = is_array( $pp_reversal_resp ) ? ( $pp_reversal_resp['result']['code'] ?? '' ) : '';
										$pp_rev_desc = is_array( $pp_reversal_resp ) ? ( $pp_reversal_resp['result']['description'] ?? '' ) : '';
										if ( ! empty( $pp_rev_code ) && 0 === strpos( $pp_rev_code, '000.' ) ) {
											PP_Gateway_Logger::info( 'Add Card (MUR) - reversal successful. Transaction ID: ' . $pp_txn_id_for_reversal . ' | Result: ' . $pp_rev_code . ( $pp_rev_desc ? ' - ' . $pp_rev_desc : '' ) );
										} else {
											PP_Gateway_Logger::warning( 'Add Card (MUR) - reversal not successful. Transaction ID: ' . $pp_txn_id_for_reversal . ' | Result: ' . ( $pp_rev_code ?: 'N/A' ) . ( $pp_rev_desc ? ' - ' . $pp_rev_desc : '' ) );
										}
									}
								}
							}

							}else{
								PP_Gateway_Logger::error( "Add Card failed for user #".$user_id .". ".print_r($_POST, true) );
							}
						}
					}
				}
				
				$my_cards = wc_get_account_endpoint_url( 'my-cards' );
				nocache_headers();
				wp_safe_redirect( $my_cards, 303 ); // force POST→GET
				exit;
			}else{
				return;
			}
			
		} );



		// Initialize account endpoints.
		PP_Gateway_My_Cards_Endpoint::register();
		PP_Gateway_Change_Card_Endpoint::register();

		// Flush rewrite rules once after activation/update, after this plugin's endpoints
		// have been registered on the current request.
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite_rules' ], 99 );


	}
	
	/**
	 * Flush rewrite rules once after activation/update, after plugin endpoints are registered.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( 'yes' !== get_option( 'wc_peach_gateway_needs_rewrite_flush' ) ) {
			return;
		}
	
		delete_option( 'wc_peach_gateway_needs_rewrite_flush' );
		delete_option( 'pp_cards_endpoint_flushed' );
		delete_option( 'peach_change_card_endpoint_flushed' );
	
		flush_rewrite_rules();
	}

	/**
	 * Add the Peach Payments Gateway to WooCommerce.
	 *
	 * @param array $gateways Existing payment gateways.
	 * @return array
	 */
	public static function register_gateway( $gateways ) {
		$gateways[] = 'WC_Gateway_Peach_Hosted';
		return $gateways;
	}
}

WC_Peach_Gateway_Init::init();