( function () {
	'use strict';

	if ( ! window.EventBridge ) {
		return;
	}

	if ( window.EventBridge.debug === true ) {
		console.info( '[EventBridge]', window.EventBridge );
	}

	var invalidSelectorWarnings = {};
	var events = Array.isArray( window.EventBridge.events ) ? window.EventBridge.events : [];
	var standardEvents = [
		'AddPaymentInfo',
		'AddToCart',
		'AddToWishlist',
		'CompleteRegistration',
		'Contact',
		'CustomizeProduct',
		'Donate',
		'FindLocation',
		'InitiateCheckout',
		'Lead',
		'Purchase',
		'Schedule',
		'Search',
		'StartTrial',
		'SubmitApplication',
		'Subscribe',
		'ViewContent'
	];

	function createEventId() {
		if ( window.crypto && typeof window.crypto.randomUUID === 'function' ) {
			return window.crypto.randomUUID();
		}

		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( character ) {
			var random = Math.floor( Math.random() * 16 );
			var value = character === 'x' ? random : ( random & 3 ) | 8;

			return value.toString( 16 );
		} );
	}

	function sendCapiEvent( eventConfig, eventId ) {
		var body;
		var pageUrl = window.location.href;

		if ( eventConfig.capi !== true ) {
			return;
		}

		if ( window.EventBridge.debug === true ) {
			console.info( '[EventBridge] CAPI request started', {
				eventId: eventId,
				label: eventConfig.label,
				eventName: eventConfig.eventName,
				id: eventConfig.id,
				pageUrl: pageUrl
			} );
		}

		if ( typeof window.fetch !== 'function'
			|| typeof window.EventBridge.endpointUrl !== 'string'
			|| typeof window.EventBridge.nonce !== 'string'
		) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] CAPI request failed' );
			}

			return;
		}

		body = new URLSearchParams();
		body.set( 'action', 'eventbridge_custom_event' );
		body.set( 'nonce', window.EventBridge.nonce );
		body.set( 'event_key', eventConfig.id );
		body.set( 'event_id', eventId );
		body.set( 'page_url', pageUrl );

		window.fetch( window.EventBridge.endpointUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( response && response.success === true && response.data && response.data.status === 'started' ) {
				if ( window.EventBridge.debug === true ) {
					console.info( '[EventBridge] CAPI request accepted' );
				}

				return;
			}

			throw new Error( 'Request rejected' );
		} ).catch( function () {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] CAPI request failed' );
			}
		} );
	}

	function handleMatchedEvent( eventConfig, matchedElement ) {
		var eventId = createEventId();
		var method;

		if ( window.EventBridge.debug === true ) {
			console.info( '[EventBridge] Trigger matched', {
				id: eventConfig.id,
				label: eventConfig.label,
				eventName: eventConfig.eventName,
				trigger: eventConfig.trigger,
				selector: eventConfig.selector,
				browser: eventConfig.browser,
				capi: eventConfig.capi,
				matchedElement: matchedElement
			} );
		}

		sendCapiEvent( eventConfig, eventId );

		if ( eventConfig.browser !== true ) {
			return;
		}

		if ( typeof eventConfig.eventName !== 'string' || eventConfig.eventName.trim() === '' ) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] Invalid event name', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName
				} );
			}

			return;
		}

		if ( typeof window.fbq !== 'function' ) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] Meta Pixel unavailable', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName
				} );
			}

			return;
		}

		method = standardEvents.indexOf( eventConfig.eventName ) !== -1 ? 'track' : 'trackCustom';

		try {
			window.fbq( method, eventConfig.eventName, {}, { eventID: eventId } );

			if ( window.EventBridge.debug === true ) {
				console.info( '[EventBridge] Browser event sent', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName,
					method: method,
					eventId: eventId,
					matchedElement: matchedElement
				} );
			}
		} catch ( error ) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] Browser event failed', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName,
					method: method,
					error: error
				} );
			}
		}
	}

	document.addEventListener( 'click', function ( clickEvent ) {
		var target = clickEvent.target;

		if ( ! target || typeof target.closest !== 'function' ) {
			return;
		}

		events.forEach( function ( configuredEvent ) {
			var matchedElement;

			if ( ! configuredEvent || configuredEvent.trigger !== 'click' ) {
				return;
			}

			try {
				matchedElement = target.closest( configuredEvent.selector );
			} catch ( error ) {
				if ( window.EventBridge.debug === true && ! invalidSelectorWarnings[ configuredEvent.id ] ) {
					invalidSelectorWarnings[ configuredEvent.id ] = true;
					console.warn( '[EventBridge] Invalid selector', {
						id: configuredEvent.id,
						label: configuredEvent.label,
						selector: configuredEvent.selector
					} );
				}

				return;
			}

			if ( matchedElement ) {
				handleMatchedEvent( configuredEvent, matchedElement );
			}
		} );
	} );
}() );
