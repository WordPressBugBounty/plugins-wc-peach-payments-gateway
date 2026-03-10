jQuery(document).ready(function ($) {
	$('.pp-delete-card').on('click', function (e) {
		e.preventDefault();

		const button = $(this);
		const registrationId = button.data('card-id');

		if (!registrationId) {
			alert('Invalid card selected.');
			return;
		}

		if (!confirm('Are you sure you want to delete this card?')) {
			return;
		}

		button.prop('disabled', true).text('Deleting...');

		$.ajax({
			type: 'POST',
			url: pp_delete_card.ajax_url,
			data: {
				action: 'pp_delete_card',
				registration_id: registrationId,
				nonce: pp_delete_card.nonce,
			},
			success: function (response) {
				if (response.success) {
					button.closest('.pp-saved-card').fadeOut(300, function () {
						$(this).remove();
					});
				} else {
					alert(response.data || 'Failed to delete the card.');
					button.prop('disabled', false).text('Delete');
				}
			},
			error: function () {
				alert('An unexpected error occurred. Please try again.');
				button.prop('disabled', false).text('Delete');
			}
		});
	});
});
