<?php
/**
 * Add New Card Response Template
 *
 * @package WooCommerce Peach Payments Gateway
 */

defined( 'ABSPATH' ) || exit;

?>

<div class="pp-card-response-message">
	<?php if ( isset( $success ) && $success ) : ?>
		<div class="pp-card-response-success">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php else : ?>
		<div class="pp-card-response-error">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
	<?php endif; ?>
</div>
