<?php
/**
 * Class PP_Gateway_My_Cards_Endpoint
 * Handles the "My Cards" tab under WooCommerce > My Account.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_My_Cards_Endpoint {
	
	public static string $endpoint = 'my-cards';

	/**
	 * Register endpoint and hooks.
	 */
	public static function register() {
		add_action( 'init', [ __CLASS__, 'add_endpoint' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_filter( 'woocommerce_account_menu_items', [ __CLASS__, 'add_menu_item' ] );
		add_action( 'woocommerce_account_my-cards_endpoint', [ __CLASS__, 'endpoint_content' ] );

		// Ensure endpoint works after activation/update
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );
	}

	/**
	 * Add custom endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( 'my-cards', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'my-cards';
		return $vars;
	}

	/**
	 * Add "My Cards" item to My Account menu.
	 */
	public static function add_menu_item( $items ) {
		$logout = $items['customer-logout'];
		unset( $items['customer-logout'] );

		$items['my-cards'] = __( 'My Cards', 'woocommerce-gateway-peach-payments' );
		$items['customer-logout'] = $logout;

		return $items;
	}

	/**
	 * Output content of the My Cards page.
	 */
	public static function endpoint_content() {
		if($_GET){
			PP_Gateway_Logger::info( "Add Card GET. ".print_r($_GET, true) );
		}
		
		if($_POST){
			PP_Gateway_Logger::info( "Add Card POST. ".print_r($_POST, true) );
		}
		
		$saved_cards = get_user_meta( get_current_user_id(), 'my-cards', true );
		$options = get_option( 'woocommerce_peach-payments_settings');
		//$enabled = PP_Gateway_Settings::get('card_storage');

		echo '<h3>Saved Cards</h3>';
		
		if(!$options['card_storage']){
			echo '<p>Card storage is currently disabled. Please contact your system administrator for assistance.</p>';
			return;
		}elseif ( empty( $saved_cards ) || ! is_array( $saved_cards ) ) {
			echo '<p>No cards saved.</p>';
			return;
		}

		echo '<ul class="pp-my-cards-list">';

		foreach ( $saved_cards as $index => $card ) {
			echo '<li>';
			echo esc_html( strtoupper( $card['brand'] ) ) . ' ending in ' . esc_html( $card['num'] );
			echo ' - Expires: ' . esc_html( $card['exp_month'] . '/' . $card['exp_year'] );
			echo ' <button class="pp-delete-card-button" data-card-id="' . esc_attr( $card['id'] ) . '" data-index="' . esc_attr( $index ) . '">Delete</button>';
			echo '</li>';
		}

		echo '</ul>';

		// Enqueue the JS file
		wp_enqueue_script( 'pp-delete-card' );
		wp_localize_script( 'pp-delete-card', 'PPDeleteCardVars', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'pp_delete_card_nonce' ),
		] );
	}

	/**
	 * Flush rewrite rules if endpoint isn't working yet.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'pp_cards_endpoint_flushed' ) ) {
			return;
		}

		$permalinks_working = false;
		$test_url = home_url( '/my-account/my-cards/' );

		$response = wp_remote_get( $test_url );
		if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
			$permalinks_working = true;
		}

		if ( ! $permalinks_working ) {
			flush_rewrite_rules();
		}

		update_option( 'pp_cards_endpoint_flushed', true );
	}
}
