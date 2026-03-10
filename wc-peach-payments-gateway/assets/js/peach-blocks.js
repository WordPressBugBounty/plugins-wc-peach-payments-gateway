//const { registerPaymentMethod } = window.wc.blocksCheckout;
import { registerPaymentMethod } from '@woocommerce/blocks-registry';

registerPaymentMethod({
	name: 'peach-payments',
	label: 'Peach Payments',
	edit: () => wp.element.createElement('div', {}, 'Peach Payments (edit block UI)'),
	content: () => wp.element.createElement('div', {}, 'You will be redirected to Peach Payments to complete your purchase.'),
	canMakePayment: () => {
		return true; // Ensure this always returns true for now
	},
	supports: {
		features: [ 'products', 'refunds' ],
	},
});
