( function () {
	'use strict';

	var container = document.getElementById( 'eventbridge-event-parameters' );
	var addButton = document.getElementById( 'eventbridge-add-parameter' );
	var template = document.getElementById( 'eventbridge-parameter-template' );
	var nextIndex;
	var triggerType = document.getElementById( 'eventbridge_event_trigger_type' );
	var selectorRow = document.getElementById( 'eventbridge-selector-row' );
	var selector = document.getElementById( 'eventbridge_event_selector' );
	var urlMatchTypeRow = document.getElementById( 'eventbridge-url-match-type-row' );
	var urlMatchType = document.getElementById( 'eventbridge_event_url_match_type' );
	var urlMatchValueRow = document.getElementById( 'eventbridge-url-match-value-row' );
	var urlMatchValue = document.getElementById( 'eventbridge_event_url_match_value' );

	if ( ! container || ! addButton || ! template ) {
		return;
	}

	nextIndex = container.querySelectorAll( '.eventbridge-parameter-row' ).length;

	function updateTriggerFields() {
		var isPageview = triggerType && triggerType.value === 'pageview';

		if ( selectorRow && selector ) {
			selectorRow.hidden = isPageview;
			selector.required = ! isPageview;
		}

		if ( urlMatchTypeRow && urlMatchType && urlMatchValueRow && urlMatchValue ) {
			urlMatchTypeRow.hidden = ! isPageview;
			urlMatchValueRow.hidden = ! isPageview;
			urlMatchType.required = isPageview;
			urlMatchValue.required = isPageview;
		}
	}

	if ( triggerType ) {
		triggerType.addEventListener( 'change', updateTriggerFields );
		updateTriggerFields();
	}

	addButton.addEventListener( 'click', function () {
		var wrapper = document.createElement( 'div' );

		wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
		nextIndex += 1;

		while ( wrapper.firstChild ) {
			container.appendChild( wrapper.firstChild );
		}
	} );

	container.addEventListener( 'click', function ( event ) {
		var removeButton = event.target.closest( '.eventbridge-remove-parameter' );

		if ( ! removeButton || ! container.contains( removeButton ) ) {
			return;
		}

		removeButton.closest( '.eventbridge-parameter-row' ).remove();
	} );
}() );
