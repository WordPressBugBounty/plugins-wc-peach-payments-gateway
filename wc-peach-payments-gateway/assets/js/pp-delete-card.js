jQuery(document).ready(function($) {
  $('.pp-delete-card-button').on('click', function(e) {
    e.preventDefault();

    const button = $(this);
    const cardId = button.data('card-id');
    const cardEntry = button.closest('.pp-card-entry');

    cardEntry.find('.pp-card-delete-error').remove();

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
          cardEntry.fadeOut(300, function() {
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

          if (response.data && Array.isArray(response.data.subscriptions) && response.data.subscriptions.length) {
            const errorBox = $('<div>', {
              'class': 'woocommerce-error pp-card-delete-error',
              'role': 'alert'
            });

            $('<div>').text(message).appendTo(errorBox);

            response.data.subscriptions.forEach(function(subscription) {
              if (!subscription || !subscription.id || !subscription.url) {
                return;
              }

              const linkRow = $('<div>');
              $('<a>', {
                href: subscription.url,
                text: 'Change card for Subscription #' + subscription.id
              }).appendTo(linkRow);
              linkRow.appendTo(errorBox);
            });

            cardEntry.find('.pp-card-actions').after(errorBox);
          } else {
            alert(message);
          }

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
