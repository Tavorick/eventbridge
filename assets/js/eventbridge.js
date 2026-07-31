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
	var events = Array.isArray( window.EventBridge.events ) ? window.EventBridge.events : [];
	var initialLocationHref = window.location.href;
	var initialLocationPathname = window.location.pathname;
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
		var hasAdvancedContext = typeof eventConfig.advancedMatchingContext === 'string' && eventConfig.advancedMatchingContext !== '';
		var hasFluentContext = typeof eventConfig.fluentBookingContext === 'string' && eventConfig.fluentBookingContext !== '';
		var pageUrl = window.location.origin + window.location.pathname;

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
		body.set( 'event_key', eventConfig.eventKey );
		body.set( 'trigger_id', eventConfig.triggerId );
		body.set( 'event_id', eventId );
		body.set( 'page_url', pageUrl );
		if ( typeof eventConfig.parameterContext === 'string' && eventConfig.parameterContext !== '' ) {
			body.set( 'parameter_context', eventConfig.parameterContext );
		}
		if ( hasAdvancedEvent && typeof eventConfig.advancedSignature === 'string' ) {
			body.set( 'advanced_matching_signature', eventConfig.advancedSignature );
		}
		if ( hasAdvancedContext ) {
			body.set( 'advanced_matching_context', eventConfig.advancedMatchingContext );
		}
		if ( hasFluentContext ) {
			body.set( 'fluent_booking_context', eventConfig.fluentBookingContext );
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
	}

	function matchesCurrentUrl( eventConfig ) {
		if ( typeof eventConfig.serverUrlMatched === 'boolean' ) {
			return eventConfig.serverUrlMatched;
		}

		if ( eventConfig.urlMatchType === 'path_exact' ) {
			return initialLocationPathname === eventConfig.urlMatchValue;
		}

		if ( eventConfig.urlMatchType === 'path_contains' ) {
			return initialLocationPathname.indexOf( eventConfig.urlMatchValue ) !== -1;
		}

		if ( eventConfig.urlMatchType === 'url_exact' ) {
			return initialLocationHref === eventConfig.urlMatchValue;
		}

		return false;
	}

	function evaluatePageviewEvents() {
		var awaitingMetaPixel = false;
		var metaPixelAvailable = typeof window.fbq === 'function';

		events.forEach( function ( configuredEvent ) {
			if ( ! configuredEvent || configuredEvent.trigger !== 'pageview' || handledPageviewEvents[ configuredEvent.id ] ) {
				return;
			}

			if ( ! matchesCurrentUrl( configuredEvent ) ) {
				return;
			}

			if ( configuredEvent.browser === true && ! metaPixelAvailable ) {
				awaitingMetaPixel = true;
				return;
			}

			handledPageviewEvents[ configuredEvent.id ] = true;
			handleMatchedEvent( configuredEvent, null );
		} );

		return awaitingMetaPixel;
	}

	if ( evaluatePageviewEvents() ) {
		window.addEventListener( 'eventbridge:meta-pixel-ready', evaluatePageviewEvents, { once: true } );
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

	var wooConfig = window.EventBridge.woocommerceInteractions && typeof window.EventBridge.woocommerceInteractions === 'object'
		? window.EventBridge.woocommerceInteractions
		: null;
	var checkoutStorageKey = 'eventbridge.checkout.attempt.v1';
	var leaveStorageKey = 'eventbridge.checkout.leave.v1';

	function navigationType() {
		if ( window.performance && typeof window.performance.getEntriesByType === 'function' ) {
			var entries = window.performance.getEntriesByType( 'navigation' );
			if ( entries.length && [ 'navigate', 'reload', 'back_forward' ].indexOf( entries[0].type ) !== -1 ) {
				return entries[0].type;
			}
		}
		return 'navigate';
	}

	function deliverWooEvents( deliveries ) {
		if ( ! Array.isArray( deliveries ) || ! deliveries.length ) {
			return;
		}
		if ( typeof window.fbq !== 'function' ) {
			window.addEventListener( 'eventbridge:meta-pixel-ready', function () {
				deliverWooEvents( deliveries );
			}, { once: true } );
			return;
		}
		deliveries.forEach( function ( delivery ) {
			if ( ! delivery || typeof delivery.eventName !== 'string' || typeof delivery.eventId !== 'string' ) {
				return;
			}
			var method = standardEvents.indexOf( delivery.eventName ) !== -1 ? 'track' : 'trackCustom';
			try {
				window.fbq( method, delivery.eventName, delivery.parameters && typeof delivery.parameters === 'object' ? delivery.parameters : {}, { eventID: delivery.eventId } );
				if ( window.EventBridge.debug === true ) {
					console.info( '[EventBridge] WooCommerce browser event sent', { eventName: delivery.eventName, eventId: delivery.eventId } );
				}
			} catch ( error ) {
				if ( window.EventBridge.debug === true ) {
					console.warn( '[EventBridge] WooCommerce browser event failed', { eventName: delivery.eventName, eventId: delivery.eventId } );
				}
			}
		} );
	}

	function buildWooInteractionBody( interaction, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', 'eventbridge_woocommerce_interaction' );
		body.set( 'nonce', wooConfig.nonce );
		body.set( 'interaction', interaction );
		body.set( 'page_url', window.location.origin + window.location.pathname );
		if ( wooConfig.routeContexts && typeof wooConfig.routeContexts === 'object' ) {
			body.set( 'route_contexts', JSON.stringify( wooConfig.routeContexts ) );
		}
		Object.keys( extra || {} ).forEach( function ( key ) {
			if ( typeof extra[ key ] === 'string' ) {
				body.set( key, extra[ key ] );
			}
		} );
		return body;
	}

	function sendWooInteraction( interaction, extra ) {
		if ( ! wooConfig || typeof window.fetch !== 'function' || typeof wooConfig.endpointUrl !== 'string' || typeof wooConfig.nonce !== 'string' ) {
			return Promise.resolve( null );
		}
		var body = buildWooInteractionBody( interaction, extra );
		return window.fetch( wooConfig.endpointUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin'
		} ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
			if ( ! response || response.success !== true || ! response.data ) {
				throw new Error( 'WooCommerce interaction rejected' );
			}
			deliverWooEvents( response.data.deliveries );
			return response.data;
		} ).catch( function () {
			if ( window.EventBridge.debug === true ) {
				console.warn( '[EventBridge] WooCommerce interaction request failed', { interaction: interaction } );
			}
			return null;
		} );
	}

	function reportCheckoutFlowActivity() {
		if ( ! wooConfig || typeof wooConfig.endpointUrl !== 'string' || typeof wooConfig.nonce !== 'string' || typeof wooConfig.checkoutContext !== 'string' || wooConfig.checkoutContext === '' ) {
			return;
		}
		var attemptId = '';
		try {
			attemptId = window.localStorage.getItem( checkoutStorageKey ) || '';
		} catch ( error ) {}
		var body = buildWooInteractionBody( 'checkout_flow_activity', { context: wooConfig.checkoutContext, attempt_id: attemptId } ).toString();
		if ( window.navigator && typeof window.navigator.sendBeacon === 'function' ) {
			try {
				if ( window.navigator.sendBeacon( wooConfig.endpointUrl, new Blob( [ body ], { type: 'application/x-www-form-urlencoded;charset=UTF-8' } ) ) ) {
					return;
				}
			} catch ( error ) {}
		}
		sendWooInteraction( 'checkout_flow_activity', { context: wooConfig.checkoutContext, attempt_id: attemptId } );
	}

	var addedToCartRequest = null;
	var addedToCartQueued = false;

	function reportAddedToCart() {
		if ( addedToCartRequest ) {
			addedToCartQueued = true;
			return addedToCartRequest;
		}
		addedToCartRequest = sendWooInteraction( 'added_to_cart', {} ).then( function ( data ) {
			addedToCartRequest = null;
			if ( addedToCartQueued ) {
				addedToCartQueued = false;
				reportAddedToCart();
			}
			return data;
		} );
		return addedToCartRequest;
	}

	if ( wooConfig ) {
		if ( typeof wooConfig.productViewContext === 'string' && wooConfig.productViewContext !== '' ) {
			sendWooInteraction( 'product_viewed', { context: wooConfig.productViewContext } );
		}
		if ( wooConfig.addedToCart === true ) {
			reportAddedToCart();
			document.body.addEventListener( 'wc-blocks_added_to_cart', reportAddedToCart );
			document.body.addEventListener( 'added_to_cart', reportAddedToCart );
			if ( window.jQuery ) {
				window.jQuery( document.body ).on( 'added_to_cart', reportAddedToCart );
			}
		}

		if ( wooConfig.isCheckout === true && typeof wooConfig.checkoutContext === 'string' && wooConfig.checkoutContext !== '' ) {
			sendWooInteraction( 'checkout_started', { context: wooConfig.checkoutContext, navigation_type: navigationType() } ).then( function ( data ) {
				if ( data && typeof data.attemptId === 'string' && data.attemptId !== '' ) {
					try {
						window.localStorage.setItem( checkoutStorageKey, data.attemptId );
					} catch ( error ) {}
				}
			} );

			document.addEventListener( 'click', function ( event ) {
				var placeOrder = event.target && typeof event.target.closest === 'function' ? event.target.closest( '#place_order, .wc-block-components-checkout-place-order-button' ) : null;
				if ( placeOrder ) {
					reportCheckoutFlowActivity();
				}
				var link = event.target && typeof event.target.closest === 'function' ? event.target.closest( 'a[href]' ) : null;
				if ( ! link ) {
					return;
				}
				try {
					var destination = new URL( link.href, window.location.href );
					var attemptId = window.localStorage.getItem( checkoutStorageKey );
					if ( destination.origin === window.location.origin && destination.href !== window.location.href ) {
						window.localStorage.setItem( leaveStorageKey, JSON.stringify( { attemptId: attemptId || '', context: wooConfig.checkoutContext, destination: destination.origin + destination.pathname + destination.search, createdAt: Date.now() } ) );
					}
				} catch ( error ) {}
			}, true );

			document.addEventListener( 'submit', function ( event ) {
				var form = event.target;
				if ( form && typeof form.matches === 'function' && form.matches( 'form.checkout, form#order_review' ) ) {
					reportCheckoutFlowActivity();
				}
			}, true );
		} else if ( navigationType() !== 'back_forward' ) {
			try {
				var candidate = JSON.parse( window.localStorage.getItem( leaveStorageKey ) || 'null' );
				var currentPage = window.location.origin + window.location.pathname + window.location.search;
				if ( candidate && candidate.destination === currentPage && Date.now() - Number( candidate.createdAt ) < 300000 ) {
					sendWooInteraction( 'confirm_checkout_leave', { attempt_id: String( candidate.attemptId || '' ), context: String( candidate.context || '' ), destination: currentPage } );
				}
				window.localStorage.removeItem( leaveStorageKey );
			} catch ( error ) {}
		}
	}
}() );
