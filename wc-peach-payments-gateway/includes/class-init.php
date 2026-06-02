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
		PP_Gateway_Subscription_Handler::register();
		
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
		
		//require_once WC_PEACH_GATEWAY_PATH . 'includes/class-ipn-handler.php';
		//PP_Gateway_IPN_Handler::init();
		
				
		add_action( 'init', function() {
			if ( isset( $_GET['resourcePath'] ) && isset( $_GET['order_id'] ) ) {
				$gateway = new WC_Gateway_Peach_Hosted();
				$gateway->handle_peach_return();
			}

			// Do not process add-card state changes from raw public POST data.
			// Verified add-card returns are handled by PP_Gateway_Token_Add_Handler::maybe_handle_resource_path().
			if ( isset( $_GET['pp_add_card_return'] ) && ! isset( $_GET['resourcePath'] ) ) {
				PP_Gateway_Logger::warning( 'Peach add-card return reached without resourcePath. Public POST data was not trusted and no card was saved.' );
				$my_cards = wc_get_account_endpoint_url( 'my-cards' );
				nocache_headers();
				wp_safe_redirect( $my_cards, 303 );
				exit;
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