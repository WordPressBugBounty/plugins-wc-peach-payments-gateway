<?php
defined( 'ABSPATH' ) || exit;
?>

<div class="pp-add-card-form-wrapper">
	<h3><?php esc_html_e( 'Add New Card', WC_PEACH_TEXT_DOMAIN ); ?></h3>
	<form id="pp-add-card-form" method="post">
		<div class="pp-form-row">
			<label for="pp_holder"><?php esc_html_e( 'Cardholder Name', WC_PEACH_TEXT_DOMAIN ); ?></label>
			<input type="text" id="pp_holder" name="holder" required>
		</div>
		<div class="pp-form-row">
			<label for="pp_number"><?php esc_html_e( 'Card Number', WC_PEACH_TEXT_DOMAIN ); ?></label>
			<input type="text" id="pp_number" name="number" maxlength="19" inputmode="numeric" required>
		</div>
		<div class="pp-form-row">
			<label for="pp_brand"><?php esc_html_e( 'Card Brand', WC_PEACH_TEXT_DOMAIN ); ?></label>
			<select id="pp_brand" name="brand" required>
				<option value="VISA">VISA</option>
				<option value="MASTER">MASTERCARD</option>
			</select>
		</div>
		<div class="pp-form-row pp-expiry">
			<div>
				<label for="pp_exp_month"><?php esc_html_e( 'Expiry Month', WC_PEACH_TEXT_DOMAIN ); ?></label>
				<input type="text" id="pp_exp_month" name="exp_month" maxlength="2" required>
			</div>
			<div>
				<label for="pp_exp_year"><?php esc_html_e( 'Expiry Year', WC_PEACH_TEXT_DOMAIN ); ?></label>
				<input type="text" id="pp_exp_year" name="exp_year" maxlength="4" required>
			</div>
		</div>
		<div class="pp-form-row">
			<button type="submit" id="pp_add_card_submit"><?php esc_html_e( 'Add Card', WC_PEACH_TEXT_DOMAIN ); ?></button>
			<span id="pp-add-card-message" class="pp-add-card-message"></span>
		</div>
	</form>
</div>
