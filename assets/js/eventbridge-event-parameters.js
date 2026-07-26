( function () {
	'use strict';

	var form = document.getElementById( 'event-form' );
	var container = document.getElementById( 'eventbridge-event-parameters' );
	var addButton = document.getElementById( 'eventbridge-add-parameter' );
	var template = document.getElementById( 'eventbridge-parameter-template' );
	var triggerType = document.getElementById( 'eventbridge_event_trigger_type' );
	var triggerDescription = document.getElementById( 'eventbridge-trigger-description' );
	var selectorRow = document.getElementById( 'eventbridge-selector-row' );
	var selector = document.getElementById( 'eventbridge_event_selector' );
	var pageviewFields = document.getElementById( 'eventbridge-pageview-fields' );
	var urlMatchType = document.getElementById( 'eventbridge_event_url_match_type' );
	var urlMatchValue = document.getElementById( 'eventbridge_event_url_match_value' );
	var dataSourceProvider = document.getElementById( 'eventbridge_data_source_provider' );
	var fluentBookingSettings = document.getElementById( 'eventbridge-fluent-booking-settings' );
	var lookupValue = document.getElementById( 'eventbridge_data_source_lookup_value' );
	var browser = document.getElementById( 'eventbridge_event_browser' );
	var capi = document.getElementById( 'eventbridge_event_capi' );
	var channelError = document.getElementById( 'eventbridge-channel-error' );
	var advancedMatching = document.getElementById( 'eventbridge-advanced-matching' );
	var advancedMatchingRows = document.querySelectorAll( '.eventbridge-advanced-matching-row' );
	var advancedMatchingWarning = document.getElementById( 'eventbridge-advanced-matching-capi-warning' );
	var diagnostics = document.getElementById( 'eventbridge-event-diagnostics' );
	var metaTestMode = document.getElementById( 'eventbridge_event_meta_test_mode' );
	var metaTestEventCodeField = document.getElementById( 'eventbridge-meta-test-event-code-field' );
	var metaTestEventCode = document.getElementById( 'eventbridge_event_meta_test_event_code' );
	var tokenInput = document.querySelector( 'input[name="eventbridge_meta_settings[capi_token]"]' );
	var removeToken = document.querySelector( 'input[name="eventbridge_meta_settings[remove_capi_token]"]' );
	var fluentAvailable = form && form.getAttribute( 'data-fluent-available' ) === '1';
	var nextIndex;

	function isLocked( row ) {
		return row && row.getAttribute( 'data-fluent-locked' ) === '1';
	}

	function updateDataSourceFields() {
		var isFluentBooking = dataSourceProvider && dataSourceProvider.value === 'fluent_booking';
		var locked = isLocked( fluentBookingSettings );

		if ( fluentBookingSettings ) {
			fluentBookingSettings.hidden = ! isFluentBooking;
		}
		if ( dataSourceProvider ) {
			dataSourceProvider.setAttribute( 'aria-expanded', isFluentBooking ? 'true' : 'false' );
		}
		if ( lookupValue && ! locked ) {
			lookupValue.required = Boolean( isFluentBooking );
		}
		updateFluentDependency();
	}

	function updateTriggerFields() {
		var isPageview = triggerType && triggerType.value === 'pageview';

		if ( selectorRow && selector ) {
			selectorRow.hidden = isPageview;
			selector.required = ! isPageview;
		}
		if ( pageviewFields ) {
			pageviewFields.hidden = ! isPageview;
		}
		if ( urlMatchType && urlMatchValue ) {
			urlMatchType.required = Boolean( isPageview );
			urlMatchValue.required = Boolean( isPageview );
		}
		if ( triggerType ) {
			triggerType.setAttribute( 'aria-expanded', isPageview ? 'true' : 'false' );
		}
		if ( triggerDescription ) {
			triggerDescription.textContent = isPageview
				? 'Het event vuurt af zodra iemand een passende pagina bezoekt.'
				: 'Het event vuurt af zodra iemand op het gekozen element klikt.';
		}
	}

	function hasConfiguredAdvancedMatching() {
		return Array.prototype.some.call( advancedMatchingRows, function ( row ) {
			var source = row.querySelector( '.eventbridge-advanced-matching-source' );
			return source && source.value !== '';
		} );
	}

	function hasConfiguredFluentSource() {
		var parameterHasFluent = container && Array.prototype.some.call( container.querySelectorAll( '.eventbridge-parameter-source' ), function ( source ) {
			return source.value === 'fluent_booking';
		} );
		var matchingHasFluent = Array.prototype.some.call( advancedMatchingRows, function ( row ) {
			var source = row.querySelector( '.eventbridge-advanced-matching-source' );
			return source && source.value === 'fluent_booking';
		} );

		return Boolean( parameterHasFluent || matchingHasFluent );
	}

	function updateFluentDependency() {
		if ( ! dataSourceProvider || dataSourceProvider.disabled ) {
			return;
		}

		dataSourceProvider.setCustomValidity(
			hasConfiguredFluentSource() && dataSourceProvider.value !== 'fluent_booking'
				? 'Kies Fluent Booking als externe databron om deze Fluent-velden te gebruiken.'
				: ''
		);
	}

	function updateDeliveryFields() {
		var browserEnabled = browser && browser.checked;
		var capiEnabled = capi && capi.checked;
		var matchingConfigured = hasConfiguredAdvancedMatching();
		var testModeEnabled = capiEnabled && metaTestMode && metaTestMode.checked;

		if ( browser ) {
			browser.setCustomValidity( ! browserEnabled && ! capiEnabled ? 'Schakel minstens één verzendkanaal in.' : '' );
		}
		if ( capi ) {
			capi.setCustomValidity( matchingConfigured && ! capiEnabled ? 'Meta Advanced Matching vereist Meta Conversion API.' : '' );
		}
		if ( channelError ) {
			channelError.hidden = Boolean( browserEnabled || capiEnabled );
		}
		if ( advancedMatchingWarning ) {
			advancedMatchingWarning.hidden = Boolean( capiEnabled );
		}
		if ( diagnostics ) {
			diagnostics.hidden = ! capiEnabled;
		}
		if ( metaTestMode ) {
			metaTestMode.disabled = ! capiEnabled;
			metaTestMode.setAttribute( 'aria-expanded', testModeEnabled ? 'true' : 'false' );
		}
		if ( metaTestEventCodeField ) {
			metaTestEventCodeField.hidden = ! testModeEnabled;
		}
		if ( metaTestEventCode ) {
			metaTestEventCode.disabled = ! testModeEnabled;
			metaTestEventCode.required = Boolean( testModeEnabled );
		}
		if ( matchingConfigured && ! capiEnabled && advancedMatching ) {
			advancedMatching.open = true;
		}
	}

	function updateParameterRow( row ) {
		var source;
		var label;
		var value;
		var fluentField;
		var isQueryParameter;
		var isFluentBooking;

		if ( isLocked( row ) ) {
			return;
		}

		source = row.querySelector( '.eventbridge-parameter-source' );
		label = row.querySelector( '.eventbridge-parameter-value-label-text' );
		value = row.querySelector( '.eventbridge-parameter-value' );
		fluentField = row.querySelector( '.eventbridge-parameter-fluent-field' );

		if ( ! source || ! label || ! value || ! fluentField ) {
			return;
		}

		Array.prototype.forEach.call( source.options, function ( option ) {
			if ( option.value === 'fluent_booking' ) {
				option.disabled = ! fluentAvailable;
			}
		} );

		isQueryParameter = source.value === 'query_parameter';
		isFluentBooking = source.value === 'fluent_booking';
		label.textContent = isFluentBooking ? 'Fluent Booking-veld' : ( isQueryParameter ? 'Queryparameternaam' : 'Vaste waarde' );
		value.hidden = isFluentBooking;
		value.disabled = isFluentBooking;
		value.required = ! isFluentBooking;
		fluentField.hidden = ! isFluentBooking;
		fluentField.disabled = ! isFluentBooking;
		fluentField.required = isFluentBooking;
		value.placeholder = isQueryParameter ? 'Bijv. booking_type' : 'Bijv. hypnotherapy';
		value.maxLength = isQueryParameter ? 100 : 500;

		if ( isQueryParameter ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );
		}
		updateFluentDependency();
	}

	function updateAdvancedMatchingRow( row ) {
		var source;
		var label;
		var value;
		var isStatic;
		var isQueryParameter;
		var isConfigured;
		var isFluentBooking;

		if ( isLocked( row ) ) {
			return;
		}

		source = row.querySelector( '.eventbridge-advanced-matching-source' );
		label = row.querySelector( '.eventbridge-advanced-matching-value-label-text' );
		value = row.querySelector( '.eventbridge-advanced-matching-value' );

		if ( ! source || ! label || ! value ) {
			return;
		}

		Array.prototype.forEach.call( source.options, function ( option ) {
			if ( option.value === 'fluent_booking' ) {
				option.disabled = ! fluentAvailable;
			}
		} );

		isStatic = source.value === 'static';
		isQueryParameter = source.value === 'query_parameter';
		isFluentBooking = source.value === 'fluent_booking';
		isConfigured = isStatic || isQueryParameter;
		value.disabled = ! isConfigured;
		value.required = isConfigured;
		label.textContent = isFluentBooking ? 'Automatisch uit boeking' : ( isQueryParameter ? 'Queryparameternaam' : ( isStatic ? 'Vaste waarde' : 'Waarde' ) );
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

		updateDeliveryFields();
		updateFluentDependency();
	}

	function updateTokenFields() {
		if ( ! tokenInput || ! removeToken ) {
			return;
		}

		tokenInput.setCustomValidity( removeToken.checked && tokenInput.value.trim() !== '' ? 'Kies vervangen of verwijderen, niet beide.' : '' );
	}

	if ( triggerType ) {
		triggerType.addEventListener( 'change', updateTriggerFields );
		updateTriggerFields();
	}

	if ( dataSourceProvider ) {
		dataSourceProvider.addEventListener( 'change', updateDataSourceFields );
		updateDataSourceFields();
	}

	if ( browser ) {
		browser.addEventListener( 'change', updateDeliveryFields );
	}
	if ( capi ) {
		capi.addEventListener( 'change', updateDeliveryFields );
	}
	if ( metaTestMode ) {
		metaTestMode.addEventListener( 'change', updateDeliveryFields );
	}

	advancedMatchingRows.forEach( updateAdvancedMatchingRow );
	advancedMatchingRows.forEach( function ( row ) {
		var source = row.querySelector( '.eventbridge-advanced-matching-source' );
		if ( source && ! isLocked( row ) ) {
			source.addEventListener( 'change', function () {
				updateAdvancedMatchingRow( row );
			} );
		}
	} );
	updateDeliveryFields();

	if ( tokenInput && removeToken ) {
		tokenInput.addEventListener( 'input', updateTokenFields );
		removeToken.addEventListener( 'change', updateTokenFields );
		updateTokenFields();
	}

	document.querySelectorAll( '.eventbridge-delete-form' ).forEach( function ( deleteForm ) {
		deleteForm.addEventListener( 'submit', function ( event ) {
			var message = deleteForm.getAttribute( 'data-confirm' );
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );

	if ( form ) {
		form.addEventListener( 'submit', function () {
			updateDeliveryFields();
			if ( ! form.checkValidity() && hasConfiguredAdvancedMatching() && capi && ! capi.checked && advancedMatching ) {
				advancedMatching.open = true;
			}
		} );
	}

	if ( ! container || ! addButton || ! template ) {
		return;
	}

	container.querySelectorAll( '.eventbridge-parameter-row' ).forEach( updateParameterRow );
	nextIndex = container.querySelectorAll( '.eventbridge-parameter-row' ).length;

	addButton.addEventListener( 'click', function () {
		var wrapper = document.createElement( 'div' );
		var addedRows;

		wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
		nextIndex += 1;
		addedRows = Array.prototype.slice.call( wrapper.children );

		while ( wrapper.firstChild ) {
			container.appendChild( wrapper.firstChild );
		}
		addedRows.forEach( updateParameterRow );
	} );

	container.addEventListener( 'change', function ( event ) {
		var source = event.target.closest( '.eventbridge-parameter-source' );
		if ( source && container.contains( source ) ) {
			updateParameterRow( source.closest( '.eventbridge-parameter-row' ) );
		}
	} );

	container.addEventListener( 'click', function ( event ) {
		var removeButton = event.target.closest( '.eventbridge-remove-parameter' );
		var row;

		if ( ! removeButton || ! container.contains( removeButton ) ) {
			return;
		}

		row = removeButton.closest( '.eventbridge-parameter-row' );
		if ( ! isLocked( row ) ) {
			row.remove();
			updateFluentDependency();
		}
	} );
}() );
