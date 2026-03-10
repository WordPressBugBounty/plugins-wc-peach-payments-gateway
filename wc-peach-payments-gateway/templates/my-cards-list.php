<?php
defined( 'ABSPATH' ) || exit;

$saved_cards = get_user_meta( get_current_user_id(), 'my-cards', true );

if ( empty( $saved_cards ) || ! is_array( $saved_cards ) ) {
	echo '<p>No saved cards found.</p>';
	return;
}
?>

<div class="pp-saved-cards-list">
	<?php foreach ( $saved_cards as $card ) : ?>
		<div class="pp-saved-card">
			<p><strong>Card:</strong> <?php echo esc_html( strtoupper( $card['brand'] ) ); ?> ending in <?php echo esc_html( $card['num'] ); ?></p>
			<p><strong>Holder:</strong> <?php echo esc_html( $card['holder'] ); ?></p>
			<p><strong>Expires:</strong> <?php echo esc_html( $card['exp_month'] . '/' . $card['exp_year'] ); ?></p>
			<button class="pp-delete-card" data-card-id="<?php echo esc_attr( $card['id'] ); ?>">Delete</button>
		</div>
	<?php endforeach; ?>
</div>
