<?php
/**
 * Plugin Name: WooCommerce Peach Payments Gateway
 * Plugin URI: http://woothemes.com/products/peach-payments/
 * Description: A payment gateway for <a href="https://www.peachpayments.com/" target="_blank" rel="noopener noreferrer">Peach Payments</a>.
 * Author: Peach Payments
 * Author URI: https://peachpayments.com
 * Version: 4.0
 * Requires at least: 6.8
 * Tested up to: 6.8
 * Text Domain: woocommerce-gateway-peach-payments
 */

defined( 'ABSPATH' ) || exit;

if( ! function_exists('get_plugin_data') ){
	require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
}

$plugin_data = get_plugin_data( __FILE__ ,true, false);

$version = explode('.', phpversion());
define( 'WC_PEACH_PHP', $version[0]);

$subscription_version = null;
if ( class_exists( 'WC_Subscriptions' ) && property_exists( 'WC_Subscriptions', 'version' ) ) {
    $subscription_version = WC_Subscriptions::$version;
}

// Plugin constants
define( 'WC_PEACH_VER', $plugin_data['Version'] );
define( 'WC_PEACH_MAJOR_VER', '4.0.0' );
define( 'WC_PEACH_WP_VER', get_bloginfo( 'version' ) );
define( 'WC_PEACH_WC_SUB_VER', $subscription_version );
define( 'WC_PEACH_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );

define( 'WC_PEACH_SITE_URL', get_site_url().'/' );

define( 'WC_PEACH_GATEWAY_VERSION', $plugin_data['Version'] );
define( 'WC_PEACH_GATEWAY_PLUGIN_FILE', __FILE__ );
define( 'WC_PEACH_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_PEACH_GATEWAY_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_PEACH_TEXT_DOMAIN', 'woocommerce-gateway-peach-payments' );

define( 'PEACH_FILE', 'wc-peach-payments-gateway/woocommerce-gateway-peach-payments.php' );

// Load logger
require_once WC_PEACH_GATEWAY_PATH . 'includes/class-logger.php';

// Load after WooCommerce
add_action( 'plugins_loaded', 'wc_peach_payments_init', 11 );

/*
* We are not supporting the following plugins anymore
* @Paid Membership Pro
* @WP-Graphql
*/

function wc_peach_payments_init() {
	//CleanTalk Plugin Compatibility
	if(null !== get_option('cleantalk_settings')){
		$cleanTalk = get_option('cleantalk_settings');
		
		if(isset($cleanTalk['exclusions__urls'])){
			$cleanTalk['exclusions__urls'] = '(\/order-pay\/)';
		}
		if(isset($cleanTalk['exclusions__urls__use_regexp'])){
			$cleanTalk['exclusions__urls__use_regexp'] = 1;
		}
		update_option('cleantalk_settings', $cleanTalk);
	}
	
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>WooCommerce must be installed and active to use the Peach Payments Gateway.</strong></p></div>';
		} );
		return;
	}

	// Include requirements checker
	require_once WC_PEACH_GATEWAY_PATH . 'includes/class-requirements-check.php';

	if ( ! class_exists( 'PP_Gateway_Requirements' ) || ! PP_Gateway_Requirements::check() ) {
		// Will log or show admin notice as needed
		return;
	}

	// Load plugin core
	require_once WC_PEACH_GATEWAY_PATH . 'includes/class-init.php';
	
	// Load and init asset manager
	require_once WC_PEACH_GATEWAY_PATH . 'includes/enqueue-assets.php';
	PP_Gateway_Assets::init();
}

add_action( 'in_plugin_update_message-' . PEACH_FILE, function( $plugin_data ) {
	version_update_warning( WC_PEACH_MAJOR_VER, WC_PEACH_VER );
} );

add_action( 'in_plugin_update_message-' . PEACH_FILE, function( $plugin_data ) {

    // Ensure we have a new version value.
    if ( empty( $plugin_data['new_version'] ) ) {
        return;
    }
	
	$current_version = isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '';

    if ( version_compare( $current_version, '4.0.0', '<' ) && version_compare( $plugin_data['new_version'], '4.0.0', '>=' ) ) {
        version_update_warning();
    }

} );

// Add settings link to plugin list
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_peach_gateway_plugin_action_links' );
function wc_peach_gateway_plugin_action_links( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=peach-payments' ) . '">' . __( 'Settings', WC_PEACH_TEXT_DOMAIN ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}

add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_peach_woocommerce_block_support' );

function woocommerce_gateway_peach_woocommerce_block_support() {
	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once __DIR__ . '/integrations/blocks/class-wc-peach-payments-blocks.php';

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
				$payment_method_registry->register( new WC_Gateway_Peach_Blocks_Support() );
			}
		);
	}
}

add_action( 'woocommerce_init', 'prefix_woocommerce_init' );
function prefix_woocommerce_init() {
	define( 'WC_PEACH_WC_VER', wc()->version );
}

add_action( 'admin_notices', 'pp_show_missing_fields_notice' );
add_action( 'admin_init', 'pp_check_required_fields_on_update' );

add_action( 'update_option_woocommerce_peach-payments_embed_clientid', 'pp_recheck_legacy_fields' );
add_action( 'update_option_woocommerce_peach-payments_embed_clientsecret', 'pp_recheck_legacy_fields' );
add_action( 'update_option_woocommerce_peach-payments_embed_merchantid', 'pp_recheck_legacy_fields' );
add_action( 'update_option_woocommerce_peach-payments_access_token', 'pp_recheck_legacy_fields' );
add_action( 'update_option_woocommerce_peach-payments_channel_3ds', 'pp_recheck_legacy_fields' );
add_action( 'update_option_woocommerce_peach-payments_secret', 'pp_recheck_legacy_fields' );

function pp_check_required_fields_on_update() {
	
	$options = get_option( 'woocommerce_peach-payments_settings');

	$required_keys = [
		'embed_clientid',
		'embed_clientsecret',
		'embed_merchantid',
		'access_token',
		'channel_3ds',
		'secret',
	];

	$missing = false;

	foreach ( $required_keys as $key ) {
		$value = $options[$key];
		if ( empty( $value ) ) {
			$missing = true;
			break;
		}
	}

	if ( $missing ) {
		update_option( 'pp_v4_show_legacy_field_notice', true );
	} else {
		delete_option( 'pp_v4_show_legacy_field_notice');
	}
}

function pp_show_missing_fields_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) return;

	if ( get_option( 'pp_v4_show_legacy_field_notice' ) ) {
		echo '<div class="notice notice-error"><p><strong>';
		echo __( 'Peach Payments Gateway: Important legacy fields are missing after updating to version 4.0. Please review your gateway settings.', 'woocommerce-gateway-peach-payments' );
		echo '</strong></p></div>';
	}
}

function pp_recheck_legacy_fields() {
	// Re-run check to see if all are now filled
	delete_option( 'pp_v4_missing_legacy_fields_checked' );
	pp_check_required_fields_on_update();
}

function version_update_warning(){
	?>
    <hr class="e-major-update-warning__separator" />
    <div class="e-major-update-warning">
        <div class="e-major-update-warning__icon">
            <i class="eicon-info-circle"></i>
        </div>
        <div>
            <div class="e-major-update-warning__title">
                <?php echo esc_html__( 'Heads up, Major upgrade!', WC_PEACH_TEXT_DOMAIN ); ?>
            </div>
            <div class="e-major-update-warning__message">
                <?php
                printf(
					esc_html__( 'Version 4 requires some important legacy fields to be active. Please ensure you %1$supdate your Peach Payments Gateway settings%2$s after upgrading.', WC_PEACH_TEXT_DOMAIN ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=peach-payments' ) ) . '">',
					'</a>'
				);
                ?>
            </div>
        </div>
    </div>
    <?php
}