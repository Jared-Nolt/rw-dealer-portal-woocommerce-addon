( function ( $ ) {
	'use strict';

	function toggleSourceFields() {
		var selected = $( '#rwdpwa-dealer-source' ).val();
		$( '.rwdpwa-source-fields' ).removeClass( 'is-active' );
		$( '.rwdpwa-source-fields[data-source="' + selected + '"]' ).addClass( 'is-active' );
	}

	function toggleCustomTextField() {
		$( '.rwdpwa-custom-text-field' ).toggleClass( 'is-active', $( '#rwdpwa-text-overrides-toggle' ).is( ':checked' ) );
	}

	function escapeHtml( value ) {
		return $( '<div>' ).text( value ).html();
	}

	function renderResults( data ) {
		var $results = $( '#rwdpwa-test-address-results' );

		if ( ! data.matches.length ) {
			$results.html(
				'<p class="rwdpwa-summary">' +
					escapeHtml( rwdpwaAdmin.i18n.noMatches ) +
					' (' + data.totalDealers + ' dealers checked, ' + data.radius + ' mile radius)</p>'
			);
			return;
		}

		var rows = data.matches.map( function ( match ) {
			return (
				'<tr>' +
					'<td>' + escapeHtml( match.label ) + '</td>' +
					'<td>' + match.distance + ' mi</td>' +
					'<td>' + escapeHtml( match.emails.join( ', ' ) ) + '</td>' +
				'</tr>'
			);
		} ).join( '' );

		$results.html(
			'<p class="rwdpwa-summary">' + data.matches.length + ' of ' + data.totalDealers + ' dealers matched within ' + data.radius + ' miles.</p>' +
			'<table>' +
				'<thead><tr><th>Dealer</th><th>Distance</th><th>Email(s)</th></tr></thead>' +
				'<tbody>' + rows + '</tbody>' +
			'</table>'
		);
	}

	$( function () {
		toggleSourceFields();
		$( '#rwdpwa-dealer-source' ).on( 'change', toggleSourceFields );

		toggleCustomTextField();
		$( '#rwdpwa-text-overrides-toggle' ).on( 'change', toggleCustomTextField );

		$( '#rwdpwa-test-address-button' ).on( 'click', function () {
			var address = $.trim( $( '#rwdpwa-test-address' ).val() );
			var $results = $( '#rwdpwa-test-address-results' );

			if ( ! address ) {
				return;
			}

			$results.html( '<p>' + escapeHtml( rwdpwaAdmin.i18n.testing ) + '</p>' );

			$.post( rwdpwaAdmin.ajaxUrl, {
				action: 'rwdpwa_test_address',
				nonce: rwdpwaAdmin.nonce,
				address: address
			} ).done( function ( response ) {
				if ( response.success ) {
					renderResults( response.data );
				} else {
					$results.html( '<p class="rwdpwa-error">' + escapeHtml( response.data.message || rwdpwaAdmin.i18n.errorGeneric ) + '</p>' );
				}
			} ).fail( function () {
				$results.html( '<p class="rwdpwa-error">' + escapeHtml( rwdpwaAdmin.i18n.errorGeneric ) + '</p>' );
			} );
		} );
	} );
}( jQuery ) );
