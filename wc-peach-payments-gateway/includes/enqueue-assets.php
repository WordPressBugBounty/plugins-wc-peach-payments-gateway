<?php
/**
 * Register and enqueue scripts and styles for the Peach Payments Gateway.
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Assets {

	/**
	 * Hook into WordPress.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'register_frontend_assets' ] );
		add_action( 'woocommerce_account_my-cards_endpoint', [ __CLASS__, 'enqueue_my_cards_assets' ] );
		
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_my_account_styles' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_checkout_styles' ] );
		
		add_action( 'admin_enqueue_scripts', function( $hook ) {
			if ( 'woocommerce_page_wc-settings' !== $hook ) return;
			if ( isset( $_GET['section'] ) && $_GET['section'] === 'peach-payments' ) {
				wp_enqueue_style( 'peach-gateway-admin-style', WC_PEACH_GATEWAY_URL . 'assets/css/admin-settings.css', [], WC_PEACH_GATEWAY_VERSION );
			}
		});


	}

	/**
	 * Register plugin scripts and styles for later use.
	 */
	public static function register_frontend_assets() {
		$version = WC_PEACH_GATEWAY_VERSION;

		// JS: Delete Card
		wp_register_script(
			'pp-delete-card',
			WC_PEACH_GATEWAY_URL . 'assets/js/pp-delete-card.js',
			[ 'jquery' ],
			$version,
			true
		);

		// CSS: My Cards
		wp_register_style(
			'pp-my-cards-style',
			WC_PEACH_GATEWAY_URL . 'assets/css/pp-my-cards.css',
			[],
			$version
		);

		// JS: Add Card
		wp_register_script(
			'pp-add-card',
			WC_PEACH_GATEWAY_URL . 'assets/js/pp-add-card.js',
			[ 'jquery' ],
			$version,
			true
		);

		// CSS: Add Card
		wp_register_style(
			'pp-add-card-style',
			WC_PEACH_GATEWAY_URL . 'assets/css/pp-add-card.css',
			[],
			$version
		);
		
		// CSS: Checkout
		wp_register_style(
			'pp-checkout-style',
			WC_PEACH_GATEWAY_URL . 'assets/css/pp-checkout.css',
			[],
			WC_PEACH_GATEWAY_VERSION
		);

	}

	/**
	 * Enqueue the assets for the My Cards endpoint.
	 */
	public static function enqueue_my_cards_assets() {
		// Enqueue styles
		wp_enqueue_style( 'pp-my-cards-style' );
		wp_enqueue_style( 'pp-add-card-style' );

		// Enqueue scripts
		wp_enqueue_script( 'pp-delete-card' );
		wp_localize_script( 'pp-delete-card', 'pp_delete_card_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pp_delete_card_nonce' ),
		] );

		wp_enqueue_script( 'pp-add-card' );
		wp_localize_script( 'pp-add-card', 'pp_add_card_ajax', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pp_add_card_nonce' ),
		] );
	}
	
	public static function enqueue_my_account_styles() {
		if ( is_account_page() && get_query_var( 'my-cards', false ) !== false ) {
			wp_enqueue_style( 'pp-my-cards-style' );
			wp_enqueue_style( 'pp-add-card-style' );
			wp_enqueue_script( 'pp-delete-card' );
			wp_enqueue_script( 'pp-add-card' );
	
			wp_localize_script( 'pp-delete-card', 'pp_delete_card_ajax', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'pp_delete_card_nonce' ),
			] );
	
			wp_localize_script( 'pp-add-card', 'pp_add_card_ajax', [
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'pp_add_card_nonce' ),
			] );
		}
	}
	
	public static function enqueue_checkout_styles() {
		// Enqueue only on checkout
		if ( is_checkout() ) {
			wp_enqueue_style( 'pp-checkout-style' );
		}
	}

}
