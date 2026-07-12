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

	function handleMatchedEvent( eventConfig, matchedElement ) {
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
			window.fbq( method, eventConfig.eventName );

			if ( window.EventBridge.debug === true ) {
				console.info( '[EventBridge] Browser event sent', {
					id: eventConfig.id,
					label: eventConfig.label,
					eventName: eventConfig.eventName,
					method: method,
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
