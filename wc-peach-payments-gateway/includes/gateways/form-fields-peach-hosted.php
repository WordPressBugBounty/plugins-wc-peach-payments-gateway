<?php
/**
 * Peach Payments Hosted Gateway Form Fields
 */

defined( 'ABSPATH' ) || exit;

class PP_Gateway_Form_Fields_Peach_Hosted {

	public static function get_fields( $statuses ) {
		return array(
			'enabled' => array(
				'title'       => 'Enable/Disable',
				'label'       => 'Enable Peach Payments Gateway',
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			),
			'transaction_mode' => array(
				'title'       => __( 'Transaction Mode', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'select',
				'description' => __( 'Set your gateway to LIVE when you are ready.', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'INTEGRATOR_TEST',
				'options'     => array(
					'INTEGRATOR_TEST' => 'Integrator Test',
					'CONNECTOR_TEST'  => 'Connector Test',
					'LIVE'            => 'Live',
				),
			),
			'section_general_title' => [
				'title' => 'General',
				'type'  => 'title',
			],
			'title' => array(
				'title'       => 'Title',
				'type'        => 'text',
				'description' => 'This controls the title which the user sees during checkout.',
				'default'     => 'Peach Payments',
				'desc_tip'    => true,
				'required'    => true,
			),
			'description' => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'This controls the description which the user sees during checkout.',
				'default'     => 'Pay with your credit card via our super-cool payment gateway.',
			),
			'redirect_notice' => array(
				'title'       => 'Redirect Notice Message',
				'type'        => 'textarea',
				'description' => 'Message shown to the customer on checkout before redirecting to Peach Payments.',
				'default'     => 'You will be redirected to Peach Payments to complete your purchase.',
			),
			'checkout_methods' => array(
				'title'       => __( 'Payment Methods', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'multiselect',
				'description' => __( 'This option were disabled in version 3.1.8 of this plugin.' ),
				'options'     => array(
					'MASTER' => 'Mastercard',
					'CAPITECPAY' => 'Capitec Pay',
					'AMEX'   => 'American Express',
					'DINERS' => 'Diners Club',
					'EFTSECURE'   => 'EFT Secure',
					'MOBICRED' => 'Mobicred',
					'1VOUCHER' => '1Voucher',
					'SCANTOPAY'   => 'Scan to Pay',
					'APPLE'   => 'ApplePay',
					'PAYPAL'   => 'PayPal',
					'MPESA'   => 'MPESA',
					'PAYFLEX'   => 'Payflex',
					'ZEROPAY'   => 'ZeroPay',
					'INSTANTEFT' => 'InstantEFT',
					'BLINKBYEMTEL' => 'Blink by EMTEL',
					'MCBJUICE' => 'MCB Juice',
					'FLOAT' => 'Float',
					'MAUCAS' => 'MauCAS'
				),
				'default'     => array('VISA','MASTER', 'CAPITECPAY', 'EFTSECURE', 'MOBICRED', 'SCANTOPAY'),
				'class'       => 'chosen_select checkout_methods',
				'css'         => 'width: 450px;',
			),
			'checkout_methods_select' => array(
				'title'       => __( 'Checkout Options', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'multiselect',
				'description' => __( 'Which payment options should display on the front-end? Hold down "CTRL" key to select multiples.' ),
				'options'     => array(
					'card'   => 'Card Payments',
					'hosted' => 'Consolidated Payments'
				),
				'default'     => array('card','hosted'),
				'class'       => 'chosen_select checkout_methods_select',
				'css'         => 'width: 450px;',
				'required'    => true,
			),
			'consolidated_label' => array(
				'title'       => __('Consolidated Payments Label'),
				'type'        => 'text',
				'description' => __( 'Front-end display label for consolidated payments.' ),
				'default'     => __( 'More payment types' ),
			),
			'consolidated_label_logos' => array(
				'title'       => __( 'Payment Logos', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'multiselect',
				'description' => __( 'Which logos should display on front-end for consolidated payments option.' ),
				'options'     => array(
					'VISA'   => 'VISA',
					'MASTER' => 'Mastercard',
					'CAPITECPAY' => 'Capitec Pay',
					'AMEX'   => 'American Express',
					'DINERS' => 'Diners Club',
					'EFTSECURE'   => 'EFT Secure',
					'MOBICRED' => 'Mobicred',
					'1VOUCHER' => '1Voucher',
					'SCANTOPAY'   => 'Scan to Pay',
					'APPLE'   => 'ApplePay',
					'PAYPAL'   => 'PayPal',
					'MPESA'   => 'MPESA',
					'PAYFLEX'   => 'Payflex',
					'ZEROPAY'   => 'ZeroPay',
					'INSTANTEFT' => 'InstantEFT',
					'BLINKBYEMTEL' => 'Blink by EMTEL',
					'MCBJUICE' => 'MCB Juice',
					'FLOAT' => 'Float',
					'MAUCAS' => 'MauCAS',
					'HAPPYPAY' => 'HappyPay',
					'GOOGLEPAY' => 'Google Pay',
					'SAMSUNGPAY' => 'Samsung Pay',
					'RCS' => 'RCS',
					'MONEYBADGER' => 'MoneyBadger',
				),
				'default'     => array('VISA','MASTER', 'CAPITECPAY', 'EFTSECURE'),
				'class'       => 'chosen_select consolidated_label_logos',
				'css'         => 'width: 450px;',
			),
			'embed_payments' => array(
				'title'       => 'Enable Embedded Checkout',
				'label'       => 'Only supports <a href="https://developer.peachpayments.com/docs/checkout-embedded#known-limitations" target="_blank" rel="nofollow">certain payment methods</a>.',
				'type'        => 'checkbox',
				'description' => 'Embedded Checkout enables the Peach Payments hosted payments page to load within your website without any redirects.',
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'section_api_title' => [
				'title' => 'API Keys',
				'type'  => 'title',
			],
			'embed_clientid' => array(
				'title'       => __( 'Client ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'embed_clientsecret' => array(
				'title'       => __( 'Client Secret', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'embed_merchantid' => array(
				'title'       => __( 'Merchant ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'access_token' => array(
				'title'       => 'Access Token',
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'channel_3ds' => array(
				'title'       => 'Entity ID',
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'secret' => array(
				'title'       => 'Secret Token',
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.'
			),
			'section_req_title' => [
				'title' => 'Recurring/Subscription Payments',
				'type'  => 'title',
			],
			'channel' => array(
				'title'       => __( 'Recurring Entity ID', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'text',
				'description' => 'This can be found in the <a href="https://dashboard.peachpayments.com/" target="_blank" rel="nofollow">Peach Payments dashboard</a> under Checkout tab.',
				'default'     => '',
			),
			'section_webhooks_title' => [
				'title' => 'Webhooks',
				'type'  => 'title',
			],
			'card_webhook_key' => array(
				'title'       => 'Card Webhook Decryption key',
				'type'        => 'text',
				'description' => 'You’ll receive this key from Peach Payments after your webhook is enabled.<br>To enable the webhook, please email <a href="mailto:support@peachpayments.com">support@peachpayments.com</a> to set up <a href="https://www.peachpayments.com/" target="_blank" rel="nofollow">https://www.peachpayments.com/</a> on your account.'
			),
			'section_cart_title' => [
				'title' => 'Cards',
				'type'  => 'title',
			],
			'card_storage' => array(
				'title'       => 'Card Storage',
				'label'       => 'Enable Card Storage',
				'type'        => 'checkbox',
				'description' => 'Allow customers to store cards against their account.',
				'default'     => 'no',
				'desc_tip'    => true,
			),
			'section_wc_title' => [
				'title' => 'WooCommerce',
				'type'  => 'title',
			],
			'peach_order_status' => array(
				'title'       => __( 'Order Status', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'select',
				'description' => __( 'Choose what the order successfull status should be.', 'woocommerce-gateway-peach-payments' ),
				'default'     => 'wc-processing',
				'options'     => $statuses,
			),
			'orderids' => array(
				'title'       => 'Order IDs',
				'label'       => 'Always use WooCommerce order IDs',
				'type'        => 'checkbox',
				'description' => 'Overwrite any custom generated order IDs by third party plugins e.g. sequentional order IDs.',
				'default'     => 'yes',
				'desc_tip'    => true,
			),
			'auto_complete' => array(
				'title'       => __( 'Auto Complete', 'woocommerce-gateway-peach-payments' ),
				'label'       => __( 'Enable Auto Complete for Virtual/Downloadable products.', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'Allow Peach Payments to update order status for successfull payments of Virtual/Downloadable products to "Completed".' ),
				'default'     => 'no',
			),
			'card_only' => array(
				'title'       => __( 'Card Payments Only', 'woocommerce-gateway-peach-payments' ),
				'label'       => __( 'Enable Card Payment only.', 'woocommerce-gateway-peach-payments' ),
				'type'        => 'checkbox',
				'description' => __( 'After redirect to Peach Payments, users will only be able to complete their payment using the Card option.' ),
				'default'     => 'no',
			)

		);
	}
}
