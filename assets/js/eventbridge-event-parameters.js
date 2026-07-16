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
	var advancedMatchingRows = document.querySelectorAll( '.eventbridge-advanced-matching-row' );
	var dataSourceProvider = document.getElementById( 'eventbridge_data_source_provider' );
	var fluentBookingSettings = document.getElementById( 'eventbridge-fluent-booking-settings' );
	var lookupValue = document.getElementById( 'eventbridge_data_source_lookup_value' );
	var capi = document.getElementById( 'eventbridge_event_capi' );

	function updateDataSourceFields() {
		var isFluentBooking = dataSourceProvider && dataSourceProvider.value === 'fluent_booking';

		if ( fluentBookingSettings ) {
			fluentBookingSettings.hidden = ! isFluentBooking;
		}
		if ( lookupValue ) {
			lookupValue.required = isFluentBooking;
		}
	}

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

	function updateParameterRow( row ) {
		var source = row.querySelector( '.eventbridge-parameter-source' );
		var label = row.querySelector( '.eventbridge-parameter-value-label-text' );
		var value = row.querySelector( '.eventbridge-parameter-value' );
		var fluentField = row.querySelector( '.eventbridge-parameter-fluent-field' );
		var isQueryParameter;
		var isFluentBooking;

		if ( ! source || ! label || ! value || ! fluentField ) {
			return;
		}

		isQueryParameter = source.value === 'query_parameter';
		isFluentBooking = source.value === 'fluent_booking';
		label.textContent = isFluentBooking ? 'Fluent Booking-veld' : ( isQueryParameter ? 'Queryparameternaam' : 'Vaste waarde' );
		value.hidden = isFluentBooking;
		value.disabled = isFluentBooking;
		fluentField.hidden = ! isFluentBooking;
		fluentField.disabled = ! isFluentBooking;
		value.placeholder = isQueryParameter ? 'Bijv. booking_type' : 'Bijv. hypnotherapy';
		value.maxLength = isQueryParameter ? 100 : 500;

		if ( isQueryParameter ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );
		}
	}

	function updateAdvancedMatchingRow( row ) {
		var source = row.querySelector( '.eventbridge-advanced-matching-source' );
		var label = row.querySelector( '.eventbridge-advanced-matching-value-label-text' );
		var value = row.querySelector( '.eventbridge-advanced-matching-value' );
		var isStatic;
		var isQueryParameter;
		var isConfigured;
		var isFluentBooking;

		if ( ! source || ! label || ! value ) {
			return;
		}

		isStatic = source.value === 'static';
		isQueryParameter = source.value === 'query_parameter';
		isFluentBooking = source.value === 'fluent_booking';
		isConfigured = isStatic || isQueryParameter;
		Array.prototype.forEach.call( source.options, function ( option ) {
			if ( option.value === 'fluent_booking' ) {
				option.disabled = ! ( capi && capi.checked ) && ! isFluentBooking;
			}
		} );
		source.disabled = false;
		value.disabled = ! isConfigured;
		value.required = isConfigured;
		label.textContent = isFluentBooking ? 'Fluent Booking' : ( isQueryParameter ? 'Queryparameternaam' : ( isStatic ? 'Vaste waarde' : 'Waarde' ) );
		value.placeholder = isQueryParameter
			? value.getAttribute( 'data-query-placeholder' ) || ''
			: ( isStatic ? value.getAttribute( 'data-static-placeholder' ) || '' : '' );
		value.maxLength = isQueryParameter ? 100 : 500;

		if ( isQueryParameter ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );

			if ( ! isConfigured ) {
				value.value = '';
			}
		}
	}

	if ( triggerType ) {
		triggerType.addEventListener( 'change', updateTriggerFields );
		updateTriggerFields();
	}

	if ( dataSourceProvider ) {
		dataSourceProvider.addEventListener( 'change', updateDataSourceFields );
		updateDataSourceFields();
	}

	if ( capi ) {
		capi.addEventListener( 'change', function () {
			advancedMatchingRows.forEach( updateAdvancedMatchingRow );
		} );
	}

	advancedMatchingRows.forEach( updateAdvancedMatchingRow );
	advancedMatchingRows.forEach( function ( row ) {
		var source = row.querySelector( '.eventbridge-advanced-matching-source' );

		if ( source ) {
			source.addEventListener( 'change', function () {
				updateAdvancedMatchingRow( row );
			} );
		}
	} );

	if ( ! container || ! addButton || ! template ) {
		return;
	}

	container.querySelectorAll( '.eventbridge-parameter-row' ).forEach( updateParameterRow );
	nextIndex = container.querySelectorAll( '.eventbridge-parameter-row' ).length;

	addButton.addEventListener( 'click', function () {
		var wrapper = document.createElement( 'div' );

		wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
		nextIndex += 1;

		while ( wrapper.firstChild ) {
			container.appendChild( wrapper.firstChild );
		}

		updateParameterRow( container.lastElementChild );
	} );

	container.addEventListener( 'change', function ( event ) {
		var source = event.target.closest( '.eventbridge-parameter-source' );

		if ( source && container.contains( source ) ) {
			updateParameterRow( source.closest( '.eventbridge-parameter-row' ) );
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
