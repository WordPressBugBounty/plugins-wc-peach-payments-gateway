<?php
/**
 * Class PP_Gateway_Change_Card_Endpoint
 * Allows customers to change the saved card (registration ID) used for an active subscription.
 *
 * This is a custom flow (NOT WooCommerce Subscriptions' default payment-method-change UI),
 * because the gateway stores cards in user meta (my-cards) rather than WC payment tokens.
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Change_Card_Endpoint {

	public static string $endpoint = 'peach-change-card';

	/**
	 * Register endpoint and hooks.
	 */
	public static function register() {
		add_action( 'init', [ __CLASS__, 'add_endpoint' ] );
		add_filter( 'query_vars', [ __CLASS__, 'add_query_vars' ] );
		add_action( 'woocommerce_account_' . self::$endpoint . '_endpoint', [ __CLASS__, 'endpoint_content' ] );
		add_action( 'init', [ __CLASS__, 'maybe_flush_rewrite_rules' ] );

		// Add "Change Card" action button on subscription view.
		add_filter( 'wcs_view_subscription_actions', [ __CLASS__, 'add_change_card_action' ], 10, 2 );
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
	 * Add a "Change Card" action to subscription view actions.
	 *
	 * @param array           $actions
	 * @param WC_Subscription $subscription
	 * @return array
	 */
	public static function add_change_card_action( $actions, $subscription ) {
		if ( ! is_user_logged_in() ) {
			return $actions;
		}

		if ( ! is_object( $subscription ) || ! method_exists( $subscription, 'get_id' ) ) {
			return $actions;
		}

		// Only for this gateway's subscriptions.
		if ( method_exists( $subscription, 'get_payment_method' ) ) {
			$method = (string) $subscription->get_payment_method();
			if ( $method !== 'peach-payments' ) {
				return $actions;
			}
		}

		// Only allow the owner to change.
		if ( method_exists( $subscription, 'get_user_id' ) ) {
			if ( (int) $subscription->get_user_id() !== (int) get_current_user_id() ) {
				return $actions;
			}
		}

		// Only when card storage is enabled.
		$enabled = PP_Gateway_Settings::get( 'card_storage' );
		if ( $enabled === 'no' ) {
			return $actions;
		}

		$url = add_query_arg(
			[
				'subscription_id' => (int) $subscription->get_id(),
			],
			wc_get_account_endpoint_url( self::$endpoint )
		);

		$actions['pp_change_card'] = [
			'url'  => esc_url( $url ),
			'name' => __( 'Change Card', WC_PEACH_TEXT_DOMAIN ),
		];

		return $actions;
	}

	/**
	 * Render endpoint content and handle POST.
	 */
	public static function endpoint_content() {
		if ( ! is_user_logged_in() ) {
			echo '<p>' . esc_html__( 'You must be logged in to manage subscription cards.', WC_PEACH_TEXT_DOMAIN ) . '</p>';
			return;
		}

		$enabled = PP_Gateway_Settings::get( 'card_storage' );
		if ( $enabled === 'no' ) {
			wc_add_notice( __( 'Card storage is currently disabled. Please contact your system administrator.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			return;
		}

		$subscription_id = isset( $_GET['subscription_id'] ) ? absint( $_GET['subscription_id'] ) : 0;
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			wc_add_notice( __( 'Invalid subscription.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::error( 'Change Card: invalid or missing subscription_id.' );
			return;
		}

		$subscription = wcs_get_subscription( $subscription_id );
		if ( ! $subscription || ! is_object( $subscription ) ) {
			wc_add_notice( __( 'Subscription not found.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::error( 'Change Card: subscription not found for ID #' . $subscription_id );
			return;
		}

		if ( (int) $subscription->get_user_id() !== (int) get_current_user_id() ) {
			wc_add_notice( __( 'You do not have permission to manage this subscription.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::warning( 'Change Card: permission denied for user #' . get_current_user_id() . ' on subscription #' . $subscription_id );
			return;
		}

		// Only allow for Peach Payments method subscriptions.
		if ( (string) $subscription->get_payment_method() !== 'peach-payments' ) {
			wc_add_notice( __( 'This subscription is not paid via Peach Payments.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::warning( 'Change Card: non-peach subscription #' . $subscription_id );
			return;
		}

		$user_id = get_current_user_id();
		$saved_cards = PP_Gateway_Card_Manager::get_saved_cards( $user_id );

		if ( empty( $saved_cards ) ) {
			wc_add_notice( __( 'You do not have any saved cards yet. Please add a card first.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			return;
		}

		// Current registration id on subscription (best-effort).
		$current_reg_id = (string) $subscription->get_meta( 'payment_registration_id', true );
		if ( $current_reg_id === '' ) {
			$current_reg_id = (string) $subscription->get_meta( '_peach_subscription_payment_method', true );
		}

		// Handle POST.
		if ( isset( $_POST['pp_change_subscription_card_submit'] ) ) {
			self::handle_post( $subscription, $saved_cards, $current_reg_id );
			// If handle_post redirects, we won't reach here.
			$current_reg_id = (string) $subscription->get_meta( 'payment_registration_id', true );
		}


		// --- Subscription context header (lightweight, no template overrides) ---
		echo '<h2>' . sprintf( esc_html__( 'Subscription #%d', WC_PEACH_TEXT_DOMAIN ), (int) $subscription_id ) . '</h2>';

		// Subscription totals (best-effort). This uses the subscription object's built-in formatted totals.
		$totals = [];
		if ( method_exists( $subscription, 'get_order_item_totals' ) ) {
			$totals = (array) $subscription->get_order_item_totals();
		}
		if ( ! empty( $totals ) ) {
			echo '<h3>' . esc_html__( 'Subscription totals', WC_PEACH_TEXT_DOMAIN ) . '</h3>';
			echo '<table class="shop_table shop_table_responsive subscription_totals">';
			foreach ( $totals as $key => $total ) {
				$label = isset( $total['label'] ) ? $total['label'] : '';
				$value = isset( $total['value'] ) ? $total['value'] : '';
				if ( $label === '' || $value === '' ) {
					continue;
				}
				echo '<tr>'; 
				echo '<th>' . wp_kses_post( $label ) . '</th>';
				echo '<td data-title="' . esc_attr( wp_strip_all_tags( $label ) ) . '">' . wp_kses_post( $value ) . '</td>';
				echo '</tr>';
			}
			echo '</table>';
		}

		// Subscription info table.
		echo '<h3>' . esc_html__( 'Subscription', WC_PEACH_TEXT_DOMAIN ) . '</h3>';
		echo '<table class="shop_table shop_table_responsive subscription_info">';

		$status = method_exists( $subscription, 'get_status' ) ? (string) $subscription->get_status() : '';
		$status_label = $status ? wc_get_order_status_name( 'wc-' . $status ) : '';
		$payment_title = method_exists( $subscription, 'get_payment_method_title' ) ? (string) $subscription->get_payment_method_title() : '';

		$start_date = method_exists( $subscription, 'get_date_to_display' ) ? (string) $subscription->get_date_to_display( 'start' ) : '';
		$next_payment = method_exists( $subscription, 'get_date_to_display' ) ? (string) $subscription->get_date_to_display( 'next_payment' ) : '';
		$end_date = method_exists( $subscription, 'get_date_to_display' ) ? (string) $subscription->get_date_to_display( 'end' ) : '';

		$last_order_date = '';
		if ( method_exists( $subscription, 'get_last_order' ) ) {
			$last_order = $subscription->get_last_order( 'all', 'any' );
			if ( $last_order && is_object( $last_order ) && method_exists( $last_order, 'get_date_created' ) ) {
				$date = $last_order->get_date_created();
				if ( $date ) {
					$last_order_date = wc_format_datetime( $date );
				}
			}
		}

		$rows = [
			[ esc_html__( 'Status', WC_PEACH_TEXT_DOMAIN ), $status_label ],
			[ esc_html__( 'Start date', WC_PEACH_TEXT_DOMAIN ), $start_date ],
			[ esc_html__( 'Last order date', WC_PEACH_TEXT_DOMAIN ), $last_order_date ],
			[ esc_html__( 'Next payment date', WC_PEACH_TEXT_DOMAIN ), $next_payment ],
			[ esc_html__( 'End date', WC_PEACH_TEXT_DOMAIN ), $end_date ],
			[ esc_html__( 'Payment', WC_PEACH_TEXT_DOMAIN ), $payment_title ],
		];

		foreach ( $rows as $row ) {
			$label = isset( $row[0] ) ? (string) $row[0] : '';
			$value = isset( $row[1] ) ? (string) $row[1] : '';
			if ( $value === '' ) {
				continue;
			}
			echo '<tr>'; 
			echo '<th>' . esc_html( $label ) . '</th>';
			echo '<td>' . wp_kses_post( $value ) . '</td>';
			echo '</tr>';
		}
		echo '</table>';

		echo '<h3>' . esc_html__( 'Change Subscription Card', WC_PEACH_TEXT_DOMAIN ) . '</h3>';
		echo '<p>' . esc_html__( 'Select a saved card to use for future recurring payments on this subscription.', WC_PEACH_TEXT_DOMAIN ) . '</p>';

		echo '<form method="post" class="pp-change-card-form">';
		wp_nonce_field( 'pp_change_subscription_card_' . $subscription_id, 'pp_change_subscription_card_nonce' );
		echo '<input type="hidden" name="subscription_id" value="' . esc_attr( $subscription_id ) . '" />';

		echo '<div class="pp-change-card-list">';

		foreach ( $saved_cards as $idx => $card ) {
			$reg_id = isset( $card['id'] ) ? (string) $card['id'] : '';
			if ( $reg_id === '' ) {
				continue;
			}

			$brand = isset( $card['brand'] ) ? strtoupper( (string) $card['brand'] ) : 'CARD';
			$num   = isset( $card['num'] ) ? (string) $card['num'] : '';
			$exp_m = isset( $card['exp_month'] ) ? (string) $card['exp_month'] : '';
			$exp_y = isset( $card['exp_year'] ) ? (string) $card['exp_year'] : '';

			$checked = checked( $current_reg_id, $reg_id, false );

			echo '<label class="pp-change-card-option" style="display:block;margin:10px 0;">';
			echo '<input type="radio" name="pp_selected_registration_id" value="' . esc_attr( $reg_id ) . '" ' . $checked . ' /> ';
			echo '<strong>' . esc_html( $brand ) . '</strong> ';
			if ( $num !== '' ) {
				echo esc_html__( 'ending in', WC_PEACH_TEXT_DOMAIN ) . ' ' . esc_html( $num ) . ' ';
			}
			if ( $exp_m !== '' && $exp_y !== '' ) {
				echo '<span>(' . esc_html__( 'Expires', WC_PEACH_TEXT_DOMAIN ) . ': ' . esc_html( $exp_m . '/' . $exp_y ) . ')</span>';
			}
			echo '</label>';
		}

		echo '</div>';

		echo '<button type="submit" name="pp_change_subscription_card_submit" class="button">' . esc_html__( 'Update Card', WC_PEACH_TEXT_DOMAIN ) . '</button>';
		echo '</form>';
	}

	/**
	 * Handle updating subscription + parent order meta.
	 *
	 * @param WC_Subscription $subscription
	 * @param array           $saved_cards
	 * @param string          $current_reg_id
	 */
	protected static function handle_post( $subscription, $saved_cards, $current_reg_id ) {
		$subscription_id = (int) $subscription->get_id();

		$nonce = isset( $_POST['pp_change_subscription_card_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['pp_change_subscription_card_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'pp_change_subscription_card_' . $subscription_id ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::error( 'Change Card: nonce verification failed for subscription #' . $subscription_id );
			return;
		}

		$selected_reg_id = isset( $_POST['pp_selected_registration_id'] ) ? sanitize_text_field( wp_unslash( $_POST['pp_selected_registration_id'] ) ) : '';
		$selected_reg_id = is_string( $selected_reg_id ) ? trim( $selected_reg_id ) : '';

		if ( $selected_reg_id === '' ) {
			wc_add_notice( __( 'Please select a card.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::warning( 'Change Card: empty selection for subscription #' . $subscription_id );
			return;
		}

		// Ensure selected reg id belongs to current user.
		$selected_card = null;
		foreach ( $saved_cards as $card ) {
			if ( isset( $card['id'] ) && (string) $card['id'] === $selected_reg_id ) {
				$selected_card = $card;
				break;
			}
		}

		if ( ! $selected_card ) {
			wc_add_notice( __( 'Selected card could not be found. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
			PP_Gateway_Logger::error( 'Change Card: selected reg id not found in user cards. user #' . get_current_user_id() . ' subscription #' . $subscription_id );
			return;
		}

		if ( $current_reg_id !== '' && hash_equals( $current_reg_id, $selected_reg_id ) ) {
			wc_add_notice( __( 'That card is already set for this subscription.', WC_PEACH_TEXT_DOMAIN ), 'notice' );
			PP_Gateway_Logger::info( 'Change Card: no-op (same card) for subscription #' . $subscription_id );
			return;
		}

		$old_display = $current_reg_id !== '' ? $current_reg_id : 'none';
		$new_display = $selected_reg_id;

		// Prefer masking in notes/logs if available.
		if ( is_array( $selected_card ) && isset( $selected_card['num'] ) ) {
			$new_display = (string) $selected_card['num'];
		}

		// Update subscription meta.
		try {
			$subscription->update_meta_data( 'payment_registration_id', $selected_reg_id );
			$subscription->update_meta_data( '_peach_subscription_payment_method', $selected_reg_id );
			$subscription->save();

			// Update parent order meta too (best-effort, keeps your renewal handler happy).
			$parent_order    = null;
			$parent_order_id = method_exists( $subscription, 'get_parent_id' ) ? (int) $subscription->get_parent_id() : 0;
			if ( $parent_order_id ) {
				$parent_order = wc_get_order( $parent_order_id );
				if ( $parent_order ) {
					$parent_order->update_meta_data( 'payment_registration_id', $selected_reg_id );
					$parent_order->update_meta_data( '_peach_subscription_payment_method', $selected_reg_id );
					$parent_order->save();
				}
			}

			// Add order note on subscription + parent order (audit trail).
			$old_short = $current_reg_id !== '' ? '...' . substr( $current_reg_id, -5 ) : '';
			$new_short = $selected_reg_id !== '' ? '...' . substr( $selected_reg_id, -5 ) : '';

			$note = sprintf(
				/* translators: 1: old registration id (short), 2: new registration id (short) */
				__( 'Peach Payments: subscription card updated by user. Registration ID changed from %1$s to %2$s.', WC_PEACH_TEXT_DOMAIN ),
				esc_html( $old_short ),
				esc_html( $new_short )
			);
			$subscription->add_order_note( $note );

			// Add the same note on the parent order too (requested for back-end visibility).
			if ( ! empty( $parent_order ) && is_object( $parent_order ) && method_exists( $parent_order, 'add_order_note' ) ) {
				$parent_order->add_order_note( $note );
			}

			PP_Gateway_Logger::info(
				'Change Card: updated subscription #' . $subscription_id .
				' user #' . get_current_user_id() .
				' from [' . $old_display . '] to [' . $new_display . '].'
			);

			wc_add_notice( __( 'Subscription card updated successfully.', WC_PEACH_TEXT_DOMAIN ), 'success' );

			// Redirect back to subscription view page to avoid form resubmission.
			$redirect_url = '';
			if ( function_exists( 'wcs_get_view_subscription_url' ) ) {
				$redirect_url = wcs_get_view_subscription_url( $subscription );
			}
			if ( ! $redirect_url ) {
				$redirect_url = wc_get_endpoint_url( 'view-subscription', $subscription_id, wc_get_page_permalink( 'myaccount' ) );
			}

			wp_safe_redirect( $redirect_url );
			exit;

		} catch ( Exception $e ) {
			PP_Gateway_Logger::error( 'Change Card: exception updating subscription #' . $subscription_id . ' — ' . $e->getMessage() );
			wc_add_notice( __( 'Could not update subscription card. Please try again.', WC_PEACH_TEXT_DOMAIN ), 'error' );
		}
	}

	/**
	 * Flush rewrite rules if endpoint isn't working yet.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( get_option( 'peach_change_card_endpoint_flushed' ) ) {
			return;
		}

		$test_url = home_url( '/my-account/' . self::$endpoint . '/' );
		$response = wp_remote_get( $test_url );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			flush_rewrite_rules();
		}

		update_option( 'peach_change_card_endpoint_flushed', true );
	}
}
