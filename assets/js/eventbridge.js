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

			if ( matchedElement && window.EventBridge.debug === true ) {
				console.info( '[EventBridge] Trigger matched', {
					id: configuredEvent.id,
					label: configuredEvent.label,
					eventName: configuredEvent.eventName,
					trigger: configuredEvent.trigger,
					selector: configuredEvent.selector,
					browser: configuredEvent.browser,
					capi: configuredEvent.capi,
					matchedElement: matchedElement
				} );
			}
		} );
	} );
}() );
