jQuery(document).ready(function($) {
  $('.pp-delete-card-button').on('click', function(e) {
    e.preventDefault();

    const button = $(this);
    const cardId = button.data('card-id');

    if (!cardId) {
      alert('Card ID missing.');
      return;
    }

    if (!confirm('Are you sure you want to delete this card?')) {
      return;
    }

    button.prop('disabled', true).text('Deleting...');

    $.ajax({
      url: pp_delete_card_ajax.ajax_url,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'pp_delete_saved_card',
        card_id: cardId,
        nonce: pp_delete_card_ajax.nonce
      },
      success: function(response) {
        if (response.success) {
          button.closest('.pp-card-entry').fadeOut(300, function() {
            $(this).remove();

            if ($('.pp-card-entry').length === 0) {
              $('.pp-my-cards-wrapper').html('<div class="pp-my-cards-empty">You have no saved cards.</div>');
            }
          });
        } else {
			let message = 'Failed to delete card.';
			if (typeof response.data === 'string') {
			  message = response.data;
			} else if (response.data && typeof response.data.message === 'string') {
			  message = response.data.message;
			}
			alert(message);
			button.prop('disabled', false).text('Delete Card');
        }
      },
      error: function(xhr, status, error) {
        console.error('AJAX error:', status, error);
        alert('An error occurred. Please try again.');
        button.prop('disabled', false).text('Delete Card');
      }
    });
  });
});
