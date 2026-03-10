/**
 * Peach Payments Admin Settings JavaScript
 */
jQuery(document).ready(function ($) {
	// Add class for easier targeting of Peach Payments settings
	const peachSection = $('form.woocommerce form[name="mainform"]');
	if (peachSection.length && window.location.href.includes('section=peach-payments')) {
		peachSection.addClass('peach-payments-settings');
	}

	// Optional: Toggle visibility of certain fields based on selection (example: show client ID only if embedded is enabled)
	$('#woocommerce_peach_payments_embed_payments').on('change', function () {
		const isEnabled = $(this).is(':checked');
		const fieldsToToggle = [
			'#woocommerce_peach_payments_embed_clientid',
			'#woocommerce_peach_payments_embed_clientsecret',
			'#woocommerce_peach_payments_embed_merchantid',
		];

		fieldsToToggle.forEach(selector => {
			const fieldRow = $(selector).closest('tr');
			if (isEnabled) {
				fieldRow.show();
			} else {
				fieldRow.hide();
			}
		});
	}).trigger('change');

	// Add description tooltips if needed
	$('.peach-payments-settings .form-table .desc_tip').each(function () {
		const tooltipText = $(this).attr('data-tip');
		if (tooltipText) {
			$(this).attr('title', tooltipText);
		}
	});
});
