window.onload = function() {
	if(jQuery('form.wc-block-components-form').length){
		checkCheckoutBtn();
	}
};

jQuery( document ).ready(function($) {

	setCookieIfNotExists(['PeachManualCheckout','PeachExpressCheckoutPlugin']);

	function setCookieIfNotExists(peach_cookies) {
		peach_cookies.forEach(function(cookieName) {
			console.log(cookieName);
			var cookieValue = getCookie(cookieName);
			if (!cookieValue) {
				setCookie(cookieName, 'dontsave', 1);
			}else{
				setCookie(cookieName, cookieValue, 1);
			}
		});
    }
	
	$('.peach-update-card').on( 'click', function(e) {
		e.preventDefault();
		var order_id = $(this).data('id');
		var card_id = $('#peach-cards').val();
		
		jQuery.ajax({
			url:peach_ajax_object.ajax_url,
			data:{ 
			  action: 'peachCardUpdateOrder',
			  cardID: card_id,
			  orderID: order_id
			},
			success:function(data){
				if(data === '1'){
					$('.update-card-result').html('<div class="result">Card updated successfully!</div>');
					setTimeout(
					function() 
					{
						$('.update-card-result').html('');
					}, 3000);
				}
			}
		});
		
	});
	
	$('input[name="peach_remove_card"]').on( 'click', function() {
		var cardID = $(this).data('id');
		
		jQuery.ajax({
			url:peach_ajax_object.ajax_url,
			data:{ 
			  action: 'peachCardUpdate',
			  card: cardID
			},
			success:function(data){
				if(data == 'success'){
					$('#'+cardID).fadeOut();
				}
			}
		});
		
	});
	
	if($('form.checkout').length || $('form.wc-block-components-form').length){
		checkCheckoutBtn();
	}
	
	$('form.checkout').on('change', 'input[name="payment_method"]', function(){
		checkCheckoutBtn();
	});
	
	$(document).on('click','label[for="radio-control-wc-payment-method-options-peach-payments"]', function () {
		checkCheckoutBtn();
	});
	
	$(document).on('mouseenter','.peachpopcont', function (event) {
		$('.peachpop').css('display', 'block');
	}).on('mouseleave','.peachpopcont',  function(){
		$('.peachpop').css('display', 'none');
	});
	
	var selected = false;
	$( '.peachpayopt input' ).each(function( index ) {
		if($( this ).is(':checked')) {
			selected = true;
			$('input[name="billing_peach"]').val($( this ).val());
		}
	});
	
	$(document).on('keyup change', '.wpwl-control-expiry', function(e) {
		validateExpiry(e);
	});
	
	if(!selected){
		setTimeout(
		function() 
		{
			$( '#place_order' ).prop('disabled', true);
		}, 1500);
	}
	
});

function process_embed(status, transactionid, code){
	jQuery.ajax({
		url:peach_ajax_object.ajax_url,
		data:{ 
		  action: 'peachEmbedUpdateOrder',
		  mystatus: status,
		  transaction: transactionid,
		  mycode: code
		},
		success:function(data){
			return data;
		}
	});
}

function checkCheckoutBtn(){
	var option = jQuery("input[name='payment_method']:checked").val();
	var option_select = jQuery("input[name='peach_payment_id']:checked").val();

	setCookie('PeachManualCheckout', option_select, 1);
	setCookie('PeachExpressCheckoutPlugin', option_select, 1);
	
	setTimeout(
	function() 
	{
		if(jQuery('.disable-checkout').length && option == 'peach-payments'){
			jQuery( '#place_order' ).prop('disabled', true);
		}else{
			jQuery( '#place_order' ).prop('disabled', false);
		}
	}, 2500);
}

function getValue(value) {
	var PeachExpress = getCookie('PeachExpressCheckoutPlugin');
	var PeachManual = getCookie('PeachManualCheckout');

	if (PeachManual != '') {
		setCookie('PeachManualCheckout', value, 1);
	}
	if (PeachExpress != '') {
		setCookie('PeachExpressCheckoutPlugin', value, 1);
	}
	
	if(jQuery('#billing_peach').length){
		jQuery('#billing_peach').val(value);
	}
}

function getCookie(cname) {
  var name = cname + "=";
  var ca = document.cookie.split(';');
  for(let i = 0; i < ca.length; i++) {
    var c = ca[i];
    while (c.charAt(0) == ' ') {
      c = c.substring(1);
    }
    if (c.indexOf(name) == 0) {
      return c.substring(name.length, c.length);
    }
  }
  return "";
}

function setCookie(cname, cvalue, exdays) {
  const d = new Date();
  d.setTime(d.getTime() + (exdays * 24 * 60 * 60 * 1000));
  let expires = "expires="+d.toUTCString();
  let secure = location.protocol === 'https:' ? 'Secure; ' : '';
  document.cookie = cname + "=" + cvalue + ";" + expires + "; path=/; " + secure + "SameSite=Lax";
  //document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
}