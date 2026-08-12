( function () {
	'use strict';

	var legacyStorageKey = 'eventbridge.attribution.v1';
	var legacyCookieName = 'eventbridge_attribution_transport_v1';
	var fields = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ttclid' ];
	var config = window.EventBridgeProfileCapture || {};

	function clearLegacyStorage() {
		try { window.localStorage.removeItem( legacyStorageKey ); } catch ( error ) {}
		try { document.cookie = legacyCookieName + '=; Path=/; SameSite=Lax; Max-Age=0' + ( window.location.protocol === 'https:' ? '; Secure' : '' ); } catch ( error ) {}
	}

	function value( input ) {
		if ( typeof input !== 'string' ) return '';
		input = input.trim();
		return input !== '' && input.length <= 255 && ! /[\u0000-\u001F\u007F]/.test( input ) ? input : '';
	}

	function capture() {
		var url, parameters, data = {}, referrer = '';
		if ( typeof config.endpointUrl !== 'string' || config.endpointUrl === '' || typeof window.URL !== 'function' ) return;
		try {
			url = new window.URL( window.location.href );
			parameters = new window.URLSearchParams( url.search );
			parameters.forEach( function ( input, name ) {
				name = typeof name === 'string' ? name.toLowerCase() : '';
				input = value( input );
				if ( fields.indexOf( name ) !== -1 && input !== '' && ! Object.prototype.hasOwnProperty.call( data, name ) ) data[ name ] = input;
			} );
			if ( document.referrer ) {
				referrer = new window.URL( document.referrer );
				if ( referrer.origin === url.origin || ( referrer.protocol !== 'http:' && referrer.protocol !== 'https:' ) ) referrer = '';
				else referrer = referrer.origin;
			}
		} catch ( error ) { return; }
		if ( Object.keys( data ).length === 0 && referrer === '' ) return;
		var body = new window.URLSearchParams();
		body.append( 'action', 'eventbridge_profile_capture' );
		body.append( 'landing_url', window.location.href );
		body.append( 'referrer', referrer );
		Object.keys( data ).forEach( function ( key ) { body.append( 'fields[' + key + ']', data[ key ] ); } );
		try { window.fetch( config.endpointUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body.toString() } ); } catch ( error ) {}
	}

	clearLegacyStorage();
	capture();
}() );
