( function () {
	'use strict';

	if ( ! window.EventBridge ) {
		return;
	}

	if ( window.EventBridge.debug === true ) {
		console.info( '[EventBridge]', {
			debug: window.EventBridge.debug,
			eventCount: Array.isArray( window.EventBridge.events ) ? window.EventBridge.events.length : 0,
			endpointUrl: window.EventBridge.endpointUrl
		} );
	}

	var invalidSelectorWarnings = {};
	var handledPageviewEvents = {};
	var removeQueryParameters = false;
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

	function sendEndpointEvent( eventConfig, eventId, browserMethod ) {
		var body;
		var hasAdvancedEvent = typeof eventConfig.advancedEventId === 'string' && eventConfig.advancedEventId !== '';
		var pageUrl = hasAdvancedEvent ? window.location.origin + window.location.pathname : window.location.href;

		if ( eventConfig.capi !== true && browserMethod === null ) {
			return;
		}

		if ( window.EventBridge.debug === true && eventConfig.capi === true ) {
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
			if ( window.EventBridge.debug === true && eventConfig.capi === true ) {
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
		if ( hasAdvancedEvent && typeof eventConfig.advancedSignature === 'string' ) {
			body.set( 'advanced_matching_signature', eventConfig.advancedSignature );
		}

		if ( browserMethod !== null ) {
			body.set( 'browser_invoked', '1' );
			body.set( 'browser_method', browserMethod );
		}

		window.fetch( window.EventBridge.endpointUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin'
		} ).then( function ( response ) {
			return response.json();
		} ).then( function ( response ) {
			if ( response && response.success === true && response.data
				&& ( response.data.status === 'started' || response.data.status === 'accepted' )
			) {
				if ( window.EventBridge.debug === true && eventConfig.capi === true ) {
					console.info( '[EventBridge] CAPI request accepted' );
				}

				return;
			}

			throw new Error( 'Request rejected' );
		} ).catch( function () {
			if ( window.EventBridge.debug === true && eventConfig.capi === true ) {
				console.warn( '[EventBridge] CAPI request failed' );
			}
		} );
	}

	function handleMatchedEvent( eventConfig, matchedElement ) {
		var eventId = typeof eventConfig.advancedEventId === 'string' && eventConfig.advancedEventId !== '' ? eventConfig.advancedEventId : createEventId();
		var browserMethod = null;

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

		if ( eventConfig.browser === true && ( typeof eventConfig.eventName !== 'string' || eventConfig.eventName.trim() === '' ) ) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] Invalid event name', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName
				} );
			}

		} else if ( eventConfig.browser === true && typeof window.fbq !== 'function' ) {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] Meta Pixel unavailable', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName
				} );
			}

		} else if ( eventConfig.browser === true ) {
			browserMethod = standardEvents.indexOf( eventConfig.eventName ) !== -1 ? 'track' : 'trackCustom';

			try {
				window.fbq(
					browserMethod,
					eventConfig.eventName,
					eventConfig.parameters && typeof eventConfig.parameters === 'object' ? eventConfig.parameters : {},
					{ eventID: eventId }
				);

				if ( window.EventBridge.debug === true ) {
					console.info( '[EventBridge] Browser event sent', {
						id: eventConfig.id,
						label: eventConfig.label,
						eventName: eventConfig.eventName,
						method: browserMethod,
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
						method: browserMethod,
						error: error
					} );
				}

				browserMethod = null;
			}
		}

		sendEndpointEvent( eventConfig, eventId, browserMethod );

		if ( typeof eventConfig.advancedEventId === 'string' && eventConfig.advancedEventId !== '' && eventConfig.removeQueryParameters === true ) {
			removeQueryParameters = true;
		}
	}

	function matchesCurrentUrl( eventConfig ) {
		if ( eventConfig.urlMatchType === 'path_exact' ) {
			return window.location.pathname === eventConfig.urlMatchValue;
		}

		if ( eventConfig.urlMatchType === 'path_contains' ) {
			return window.location.pathname.indexOf( eventConfig.urlMatchValue ) !== -1;
		}

		if ( eventConfig.urlMatchType === 'url_exact' ) {
			return window.location.href === eventConfig.urlMatchValue;
		}

		return false;
	}

	events.forEach( function ( configuredEvent ) {
		if ( ! configuredEvent || configuredEvent.trigger !== 'pageview' || handledPageviewEvents[ configuredEvent.id ] ) {
			return;
		}

		if ( matchesCurrentUrl( configuredEvent ) ) {
			handledPageviewEvents[ configuredEvent.id ] = true;
			handleMatchedEvent( configuredEvent, null );
		}
	} );

	if ( removeQueryParameters && window.history && typeof window.history.replaceState === 'function' ) {
		window.history.replaceState( window.history.state, '', window.location.pathname + window.location.hash );
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
