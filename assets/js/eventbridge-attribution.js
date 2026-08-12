( function () {
	'use strict';

	var storageKey = 'eventbridge.attribution.v1';
	var version = 1;
	var maximumValueLength = 255;
	var maximumRecordLength = 4096;
	var fields = [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ttclid' ];

	function sanitizeValue( value ) {
		if ( typeof value !== 'string' ) {
			return '';
		}

		value = value.trim();
		if ( value === '' || value.length > maximumValueLength || /[\u0000-\u001F\u007F]/.test( value ) ) {
			return '';
		}

		return value;
	}

	function sanitizeCapturedAt( value ) {
		var timestamp = sanitizeValue( value );

		if ( timestamp === '' || isNaN( Date.parse( timestamp ) ) ) {
			return '';
		}

		return timestamp;
	}

	function sanitizeReferrer( value ) {
		value = sanitizeValue( value );
		if ( value === '' || typeof window.URL !== 'function' ) {
			return '';
		}

		try {
			var url = new window.URL( value );
			return url.protocol === 'http:' || url.protocol === 'https:' ? sanitizeValue( url.origin ) : '';
		} catch ( error ) {
			return '';
		}
	}

	function normalizeTouch( candidate ) {
		var touch = {};
		var hasAttribution = false;

		if ( ! candidate || typeof candidate !== 'object' || Array.isArray( candidate ) ) {
			return null;
		}

		touch.captured_at = sanitizeCapturedAt( candidate.captured_at );
		if ( touch.captured_at === '' ) {
			return null;
		}

		fields.forEach( function ( field ) {
			var value = sanitizeValue( candidate[ field ] );
			if ( value !== '' ) {
				touch[ field ] = value;
				hasAttribution = true;
			}
		} );

		var referrer = sanitizeReferrer( candidate.referrer );
		if ( referrer !== '' ) {
			touch.referrer = referrer;
			hasAttribution = true;
		}

		return hasAttribution ? touch : null;
	}

	function readRecord() {
		var parsed;

		try {
			parsed = JSON.parse( window.localStorage.getItem( storageKey ) || 'null' );
		} catch ( error ) {
			return null;
		}

		if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) || parsed.version !== version ) {
			return null;
		}

		var firstTouch = normalizeTouch( parsed.first_touch );
		var lastTouch = normalizeTouch( parsed.last_touch );

		if ( ! firstTouch && ! lastTouch ) {
			return null;
		}

		return {
			version: version,
			first_touch: firstTouch,
			last_touch: lastTouch
		};
	}

	function getExternalReferrer() {
		var referrer;
		var current;

		if ( typeof document.referrer !== 'string' || document.referrer === '' || typeof window.URL !== 'function' ) {
			return '';
		}

		try {
			referrer = new window.URL( document.referrer );
			current = new window.URL( window.location.href );
		} catch ( error ) {
			return '';
		}

		if ( ( referrer.protocol !== 'http:' && referrer.protocol !== 'https:' ) || referrer.origin === current.origin ) {
			return '';
		}

		return sanitizeReferrer( referrer.origin );
	}

	function captureTouch() {
		var parameters;
		var touch = { captured_at: new Date().toISOString() };

		if ( typeof window.URLSearchParams === 'function' ) {
			try {
				parameters = new window.URLSearchParams( window.location.search );
				parameters.forEach( function ( value, name ) {
					var field = typeof name === 'string' ? name.toLowerCase() : '';
					if ( fields.indexOf( field ) !== -1 && ! Object.prototype.hasOwnProperty.call( touch, field ) ) {
						touch[ field ] = value;
					}
				} );
			} catch ( error ) {}
		}

		touch.referrer = getExternalReferrer();

		return normalizeTouch( touch );
	}

	function storeTouch( touch ) {
		var existing;
		var next;
		var encoded;

		if ( ! touch ) {
			return;
		}

		existing = readRecord();
		next = {
			version: version,
			first_touch: existing && existing.first_touch ? existing.first_touch : touch,
			last_touch: touch
		};

		try {
			encoded = JSON.stringify( next );
			if ( typeof encoded !== 'string' || encoded.length > maximumRecordLength ) {
				return;
			}
			window.localStorage.setItem( storageKey, encoded );
		} catch ( error ) {}
	}

	window.EventBridgeAttribution = {
		get: function () {
			var record = readRecord();
			return record ? JSON.parse( JSON.stringify( record ) ) : null;
		}
	};

	storeTouch( captureTouch() );
}() );
