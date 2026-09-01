<?php
/**
 * Class PP_Gateway_Card_Manager
 *
 * Handles storage and retrieval of saved cards via user meta.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Card_Manager {

	/**
	 * Meta key for saved cards.
	 */
	const META_KEY = 'my-cards';

	/**
	 * Get saved cards for a user.
	 *
	 * @param int $user_id
	 *
	 * @return array
	 */
	public static function get_saved_cards( $user_id ) {
		$cards = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $cards ) ? $cards : [];
	}

	/**
	 * Save a card for a user.
	 *
	 * @param int   $user_id
	 * @param array $card_data
	 *
	 * @return void
	 */
	public static function save_card( $user_id, $card_data ) {
		$cards = self::get_saved_cards( $user_id );

		// Prevent duplicate registrationId rows while allowing an existing card to be enriched.
		foreach ( $cards as $index => $card ) {
			if ( isset( $card['id'] ) && $card['id'] === $card_data['id'] ) {
				$updated = false;

				foreach ( [ 'num', 'holder', 'brand', 'exp_month', 'exp_year', 'payment_initial_id', 'payment_order_id' ] as $field ) {
					if ( empty( $cards[ $index ][ $field ] ) && ! empty( $card_data[ $field ] ) ) {
						$cards[ $index ][ $field ] = $card_data[ $field ];
						$updated = true;
					}
				}

				if ( $updated ) {
					update_user_meta( $user_id, self::META_KEY, $cards );
					if ( class_exists( 'PP_Gateway_Logger' ) ) {
						PP_Gateway_Logger::info( 'Peach My Cards entry enriched for user #' . $user_id . ' without creating a duplicate registration row.' );
					}
				}

				return;
			}
		}

		$cards[] = $card_data;
		update_user_meta( $user_id, self::META_KEY, $cards );
	}

	/**
	 * Delete a card by registration ID.
	 *
	 * @param int    $user_id
	 * @param string $registration_id
	 *
	 * @return bool True on success, false if not deleted.
	 */
	public static function delete_card( $user_id, $registration_id ) {
		$cards = self::get_saved_cards( $user_id );
		$updated_cards = [];

		$deleted = false;

		foreach ( $cards as $card ) {
			if ( isset( $card['id'] ) && $card['id'] === $registration_id ) {
				$deleted = true;
				continue;
			}
			$updated_cards[] = $card;
		}

		if ( $deleted ) {
			update_user_meta( $user_id, self::META_KEY, $updated_cards );
		}

		return $deleted;
	}
}
