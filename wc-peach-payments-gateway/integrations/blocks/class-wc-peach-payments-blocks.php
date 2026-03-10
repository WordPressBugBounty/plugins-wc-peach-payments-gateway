<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Gateway_Peach_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'peach-payments';

    public function initialize() {
        $this->settings = get_option('woocommerce_peach-payments_settings', []);
    }

    public function is_active() {
        $payment_gateways_class = WC()->payment_gateways();
        $payment_gateways = $payment_gateways_class->payment_gateways();
        return isset($payment_gateways['peach-payments']) && $payment_gateways['peach-payments']->is_available();
    }

    public function get_payment_method_script_handles() {
		$plugin_base_url = plugin_dir_url( WC_PEACH_GATEWAY_PLUGIN_FILE );
		$script_asset_path = WC_PEACH_GATEWAY_PATH . 'integrations/blocks/frontend/blocks.asset.php';
		$script_url        = $plugin_base_url . 'integrations/blocks/frontend/blocks.js';
		
        $script_asset = file_exists($script_asset_path)
            ? require $script_asset_path
            : ['dependencies' => [], 'version' => WC_PEACH_VER];

        wp_register_script(
            'wc-peach-payments-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('wc-peach-payments-blocks', 'woocommerce-gateway-peach-payments', WC_PEACH_GATEWAY_PATH . '/languages/');
        }

        return ['wc-peach-payments-blocks'];
    }

    public function get_payment_method_data() {
        $payment_gateways_class = WC()->payment_gateways();
        $payment_gateways = $payment_gateways_class->payment_gateways();
        $gateway = $payment_gateways['peach-payments'];

        ob_start();
        $gateway->payment_fields();
        $output = ob_get_clean();

        return [
            'title'       => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
			'icon'        => plugins_url( 'assets/images/Peach_Payments_Primary_logo.png', WC_PEACH_GATEWAY_PLUGIN_FILE ),
            'supports'    => array_filter($gateway->supports, [$gateway, 'supports']),
            'whatever'    => $output
        ];
    }
}