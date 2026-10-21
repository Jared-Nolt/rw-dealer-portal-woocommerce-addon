/**
 * [rwdpwa_product_list]: add a card's rows to the cart without leaving the
 * page. Without JS the form posts normally and the page reloads.
 */
( function () {
	'use strict';

	var settings = window.rwdpwaProductList || {};
	if ( ! settings.ajaxUrl || ! window.fetch || ! window.FormData ) {
		return;
	}

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'rwdpwa-pl__form' ) ) {
			return;
		}
		event.preventDefault();

		var button  = form.querySelector( '.rwdpwa-pl__button' );
		var message = form.querySelector( '.rwdpwa-pl__message' );
		var label   = button.textContent;

		button.disabled    = true;
		button.textContent = settings.i18n.adding;
		message.className  = 'rwdpwa-pl__message';
		message.textContent = '';

		fetch( settings.ajaxUrl, { method: 'POST', body: new FormData( form ), credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				var payload = data && data.data && ! data.message ? data.data : data;
				message.innerHTML = ( payload && payload.message ) || settings.i18n.error;
				message.classList.add( data && data.success ? 'is-success' : 'is-error' );
				if ( data && data.success && window.jQuery ) {
					window.jQuery( document.body ).trigger( 'wc_fragment_refresh' );
				}
			} )
			.catch( function () {
				message.textContent = settings.i18n.error;
				message.classList.add( 'is-error' );
			} )
			.then( function () {
				button.disabled    = false;
				button.textContent = label;
			} );
	} );
}() );
