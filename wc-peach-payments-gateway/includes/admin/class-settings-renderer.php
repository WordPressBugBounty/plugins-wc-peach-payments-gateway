<?php
/**
 * Admin Settings Renderer
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

class PP_Settings_Renderer {

	/**
	 * Render a custom HTML field type in the settings.
	 *
	 * @param array $field Field settings array.
	 */
	public static function render_custom_html_field( $field ) {
		if ( isset( $field['html'] ) ) {
			echo wp_kses_post( $field['html'] );
		}
	}

	/**
	 * Render a custom image field type in the settings.
	 *
	 * @param array $field Field settings array.
	 */
	public static function render_image_field( $field ) {
		$image_url = isset( $field['url'] ) ? esc_url( $field['url'] ) : '';

		if ( $image_url ) {
			echo '<img src="' . $image_url . '" alt="' . esc_attr( $field['alt'] ?? 'Gateway Logo' ) . '" width="' . esc_attr( $field['width'] ?? 100 ) . '"/>';
		}
	}
}
