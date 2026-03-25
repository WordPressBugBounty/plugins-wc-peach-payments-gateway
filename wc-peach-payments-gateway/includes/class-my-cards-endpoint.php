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
		add_action( 'woocommerce_account_' . self::$endpoint . '_endpoint', [ __CLASS__, 'endpoint_content' ] );
	}

	/**
	 * Add custom endpoint.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::$endpoint, EP_ROOT | EP_PAGES );
	}

	/**
	 * Add query vars.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = self::$endpoint;
		return $vars;
	}

	/**
	 * Add "My Cards" item to My Account menu.
	 */
	public static function add_menu_item( $items ) {
		if ( isset( $items['customer-logout'] ) ) {
			$logout = $items['customer-logout'];
			unset( $items['customer-logout'] );
		}
		
		$enabled = PP_Gateway_Settings::get('card_storage');
		
		if ( $enabled !== 'no' ) {
			$items[ self::$endpoint ] = __( 'My Cards', WC_PEACH_TEXT_DOMAIN );
		}

		if ( isset( $logout ) ) {
			$items['customer-logout'] = $logout;
		}

		return $items;
	}

	/**
	 * Output content of the My Cards page.
	 */
	public static function endpoint_content() {
		$user_id     = get_current_user_id();
		$saved_cards = get_user_meta( $user_id, 'my-cards', true );
		$options = get_option( 'woocommerce_peach-payments_settings');

		echo '<h3>' . esc_html__( 'Saved Cards', WC_PEACH_TEXT_DOMAIN ) . '</h3>';

		if($options['card_storage'] == 'no'){
			echo '<p>' . esc_html__( 'Card storage is currently disabled. Please contact your system administrator for assistance.', WC_PEACH_TEXT_DOMAIN ) . '</p>';
			return;
		}elseif( empty( $saved_cards ) || ! is_array( $saved_cards ) ) {
			echo '<p>' . esc_html__( 'You have no saved cards.', WC_PEACH_TEXT_DOMAIN ) . '</p>';
		} else {
			echo '<div class="pp-my-cards-container">';
			foreach ( $saved_cards as $index => $card ) {
				echo '<div class="pp-my-card pp-card-entry">';
				echo '<div class="pp-card-header">' . esc_html( strtoupper( $card['brand'] ) ) . ' ending in ' . esc_html( $card['num'] ) . '</div>';
				echo '<div class="pp-card-meta">';
				echo '<div>' . esc_html__( 'Expires:', WC_PEACH_TEXT_DOMAIN ) . ' ' . esc_html( $card['exp_month'] . '/' . $card['exp_year'] ) . '</div>';
				echo '<div>' . esc_html__( 'Cardholder:', WC_PEACH_TEXT_DOMAIN ) . ' ' . esc_html( $card['holder'] ) . '</div>';
				echo '</div>';
				echo '<div class="pp-card-actions">';
				echo '<button class="pp-delete-card-button" data-card-id="' . esc_attr( $card['id'] ) . '" data-index="' . esc_attr( $index ) . '">' . esc_html__( 'Delete Card', WC_PEACH_TEXT_DOMAIN ) . '</button>';
				echo '</div>';
				echo '</div>';
			}
			echo '</div>';
		}

		// Add Card Form (render via template)
		$template = WC_PEACH_GATEWAY_PATH . 'templates/add-new-card-form.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}
}
