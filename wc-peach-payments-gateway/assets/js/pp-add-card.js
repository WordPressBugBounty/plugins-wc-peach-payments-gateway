jQuery(document).ready(function ($) {
	function toggleAddCardWrapper() {
		var $wrapper = $('#pp-add-card-wrapper');
		if (!$wrapper.length) {
			$wrapper = $('.pp-add-card-wrapper');
		}
		if (!$wrapper.length) {
			return;
		}

		// Hide when empty (or whitespace-only) to avoid showing an empty box.
		if ($wrapper.text().trim().length === 0) {
			$wrapper.hide();
		} else {
			$wrapper.show();
		}
	}

	// Initial check on load.
	toggleAddCardWrapper();

	// Observe content changes inside the wrapper (messages/form are injected via JS).
	var target = document.querySelector('#pp-add-card-wrapper, .pp-add-card-wrapper');
	if (target && window.MutationObserver) {
		var observer = new MutationObserver(function () {
			toggleAddCardWrapper();
		});
		observer.observe(target, { childList: true, subtree: true, characterData: true });
	}

	$('#pp-add-card-button').on('click', function () {
		const wrapper = $('#pp-add-card-wrapper');
		wrapper.html('<p>Retrieving your details and redirecting. Please do not interrupt this process.</p>');
		// Ensure wrapper becomes visible once it has content.
		wrapper.show();

		$.ajax({
			url: pp_add_card_ajax.ajax_url,
			type: 'POST',
			data: {
				action: 'pp_get_registration_id',
				nonce: pp_add_card_ajax.nonce,
			},
			success: function (res) {
				if (res.success && res.data.redirectUrl) {
					const redirectUrl = res.data.redirectUrl;
					const mode = res.data.mode;
					window.location.assign(redirectUrl);
				} else {
					wrapper.html('<p>Error: Unable to create redirect to Peach Payments.</p>');
				}
			},
			error: function (jqXHR, textStatus, errorThrown) {
				let message = 'Error: Something went wrong. Please try again later.';
				let json = JSON.parse(jqXHR.responseText);
				if (json.data && json.data.message) {
					message = json.data.message;
				}
				wrapper.html('<p>Error: '+message+'</p>');
			},
		});
	});

	function renderPeachForm(registrationId, wrapper, mode) {
		const script = document.createElement('script');
		var url = 'https://sandbox-card.peachpayments.com/v1/';		

		if(mode != 'INTEGRATOR_TEST'){
			url = 'https://sandbox-card.peachpayments.com/v1/';
		}
		
		//<script src="https://sandbox-card.peachpayments.com/v1/paymentWidgets.js?checkoutId={checkoutId}/registration"></script
		
		script.src = url + 'paymentWidgets.js?checkoutId=' + registrationId + '/registration';
		script.async = true;

		const formHtml = `
			<form action="${window.location.href}" class="paymentWidgets" data-brands="VISA MASTER"></form>
		`;

		wrapper.html(formHtml);
		document.body.appendChild(script);
	}
});
