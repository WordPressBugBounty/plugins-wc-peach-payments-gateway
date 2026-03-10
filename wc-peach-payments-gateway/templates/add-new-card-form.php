<?php
defined( 'ABSPATH' ) || exit;
?>

<div class="pp-add-card-button-wrap">
  <button id="pp-add-card-button"><?php esc_html_e( 'Add New Card', WC_PEACH_TEXT_DOMAIN ); ?></button>
</div>

<?php if ( 'MUR' === get_woocommerce_currency() ) : ?>
  <div class="pp-mur-card-disclaimer" style="width:100%;text-align:center;font-weight:bold;font-style:italic;margin-top:12px;">
    <?php echo esc_html__( 'Please note that a temporary authorization of MUR 1.00 will be processed and automatically reversed to verify your card', WC_PEACH_TEXT_DOMAIN ); ?>
  </div>
<?php endif; ?>

<div id="pp-add-card-wrapper" class="pp-add-card-wrapper"></div>
