( function () {
	'use strict';

	var form = document.getElementById( 'event-form' );
	if ( ! form ) {
		return;
	}

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
	var woocommerceFields = document.getElementById( 'eventbridge-woocommerce-fields' );
	var woocommerceEvent = document.getElementById( 'eventbridge_woocommerce_event' );
	var woocommerceStatusField = document.getElementById( 'eventbridge-woocommerce-status-field' );
	var woocommerceStatus = document.getElementById( 'eventbridge_woocommerce_status' );
	var purchasePresetWrap = document.getElementById( 'eventbridge-woocommerce-purchase-preset' );
	var purchasePreset = document.getElementById( 'eventbridge_woocommerce_purchase_preset' );
	var fluentDataSourceCard = document.getElementById( 'eventbridge-fluent-data-source-card' );
	var dataSourceProvider = document.getElementById( 'eventbridge_data_source_provider' );
	var fluentBookingSettings = document.getElementById( 'eventbridge-fluent-booking-settings' );
	var lookupValue = document.getElementById( 'eventbridge_data_source_lookup_value' );
	var browser = document.getElementById( 'eventbridge_event_browser' );
	var capi = document.getElementById( 'eventbridge_event_capi' );
	var channelError = document.getElementById( 'eventbridge-channel-error' );
	var advancedMatching = document.getElementById( 'eventbridge-advanced-matching' );
	var advancedMatchingWarning = document.getElementById( 'eventbridge-advanced-matching-capi-warning' );
	var diagnostics = document.getElementById( 'eventbridge-event-diagnostics' );
	var metaTestMode = document.getElementById( 'eventbridge_event_meta_test_mode' );
	var metaTestEventCodeField = document.getElementById( 'eventbridge-meta-test-event-code-field' );
	var metaTestEventCode = document.getElementById( 'eventbridge_event_meta_test_event_code' );
	var tokenInput = document.querySelector( 'input[name="eventbridge_meta_settings[capi_token]"]' );
	var removeToken = document.querySelector( 'input[name="eventbridge_meta_settings[remove_capi_token]"]' );
	var fluentAvailable = form.getAttribute( 'data-fluent-available' ) === '1';
	var woocommerceAvailable = form.getAttribute( 'data-woocommerce-available' ) === '1';
	var isNewEvent = form.getAttribute( 'data-new-event' ) === '1';
	var presetTouched = false;
	var nextIndex;

	function isLocked( row ) {
		return row && ( row.getAttribute( 'data-fluent-locked' ) === '1' || row.getAttribute( 'data-woocommerce-locked' ) === '1' );
	}

	function isWooCommerceTrigger() {
		return Boolean( triggerType && triggerType.value === 'woocommerce' );
	}

	function advancedRows() {
		return document.querySelectorAll( '.eventbridge-advanced-matching-row' );
	}

	function ensureCapiHiddenField( enabled ) {
		var hidden = document.getElementById( 'eventbridge_event_capi_required' );

		if ( enabled && ! hidden ) {
			hidden = document.createElement( 'input' );
			hidden.type = 'hidden';
			hidden.id = 'eventbridge_event_capi_required';
			hidden.name = 'eventbridge_event[capi]';
			hidden.value = '1';
			capi.insertAdjacentElement( 'afterend', hidden );
		} else if ( ! enabled && hidden ) {
			hidden.remove();
		}
	}

	function updateDataSourceFields() {
		var isWoo = isWooCommerceTrigger();
		var isFluentBooking = dataSourceProvider && dataSourceProvider.value === 'fluent_booking';
		var locked = isLocked( fluentBookingSettings );

		if ( fluentDataSourceCard ) {
			fluentDataSourceCard.hidden = isWoo;
		}
		if ( isWoo && dataSourceProvider && ! dataSourceProvider.disabled ) {
			dataSourceProvider.value = '';
			isFluentBooking = false;
		}
		if ( fluentBookingSettings ) {
			fluentBookingSettings.hidden = ! isFluentBooking;
		}
		if ( dataSourceProvider ) {
			dataSourceProvider.setAttribute( 'aria-expanded', isFluentBooking ? 'true' : 'false' );
		}
		if ( lookupValue && ! locked ) {
			lookupValue.required = Boolean( isFluentBooking && ! isWoo );
		}
		updateFluentDependency();
	}

	function updateWooCommerceEventFields() {
		var isWoo = isWooCommerceTrigger();
		var isStatus = isWoo && woocommerceEvent && woocommerceEvent.value === 'status';
		var locked = isLocked( woocommerceFields );

		if ( woocommerceStatusField ) {
			woocommerceStatusField.hidden = ! isStatus;
		}
		if ( woocommerceStatus && ! locked ) {
			woocommerceStatus.disabled = ! isStatus;
			woocommerceStatus.required = Boolean( isStatus );
		}
		if ( woocommerceEvent && ! locked ) {
			woocommerceEvent.disabled = ! isWoo;
			woocommerceEvent.required = isWoo;
		}
		if ( purchasePreset && ! locked ) {
			purchasePreset.disabled = ! isWoo;
		}
		if ( isNewEvent && ! presetTouched && purchasePreset ) {
			purchasePreset.checked = Boolean( isWoo && woocommerceEvent && woocommerceEvent.value === 'paid' );
		}
	}

	function updateTriggerFields() {
		var isPageview = triggerType && triggerType.value === 'pageview';
		var isWoo = isWooCommerceTrigger();

		if ( selectorRow && selector ) {
			selectorRow.hidden = isPageview || isWoo;
			selector.required = ! isPageview && ! isWoo;
		}
		if ( pageviewFields ) {
			pageviewFields.hidden = ! isPageview;
		}
		if ( urlMatchType && urlMatchValue ) {
			urlMatchType.required = Boolean( isPageview );
			urlMatchValue.required = Boolean( isPageview );
		}
		if ( woocommerceFields ) {
			woocommerceFields.hidden = ! isWoo;
		}
		if ( purchasePresetWrap ) {
			purchasePresetWrap.hidden = ! isWoo;
		}
		if ( triggerDescription ) {
			triggerDescription.textContent = isWoo
				? 'Dit event wordt server-side door een WooCommerce-ordergebeurtenis gestart.'
				: ( isPageview ? 'Het event vuurt af zodra iemand een passende pagina bezoekt.' : 'Het event vuurt af zodra iemand op het gekozen element klikt.' );
		}

		if ( browser ) {
			if ( isWoo ) {
				browser.checked = false;
			}
			browser.disabled = isWoo;
		}
		if ( capi ) {
			if ( isWoo ) {
				capi.checked = true;
			}
			capi.disabled = isWoo;
			if ( isWoo ) {
				capi.removeAttribute( 'name' );
			} else {
				capi.name = 'eventbridge_event[capi]';
			}
			ensureCapiHiddenField( isWoo );
		}

		updateWooCommerceEventFields();
		updateDataSourceFields();
		if ( container ) {
			container.querySelectorAll( '.eventbridge-parameter-row' ).forEach( updateParameterRow );
		}
		advancedRows().forEach( updateAdvancedMatchingRow );
		updateDeliveryFields();
	}

	function hasConfiguredAdvancedMatching() {
		return Array.prototype.some.call( advancedRows(), function ( row ) {
			var source = row.querySelector( '.eventbridge-advanced-matching-source' );
			return source && source.value !== '';
		} );
	}

	function hasConfiguredFluentSource() {
		var parameterHasFluent = container && Array.prototype.some.call( container.querySelectorAll( '.eventbridge-parameter-source' ), function ( source ) {
			return source.value === 'fluent_booking';
		} );
		var matchingHasFluent = Array.prototype.some.call( advancedRows(), function ( row ) {
			var source = row.querySelector( '.eventbridge-advanced-matching-source' );
			return source && source.value === 'fluent_booking';
		} );

		return Boolean( parameterHasFluent || matchingHasFluent );
	}

	function updateFluentDependency() {
		if ( ! dataSourceProvider || dataSourceProvider.disabled ) {
			return;
		}
		if ( isWooCommerceTrigger() ) {
			dataSourceProvider.setCustomValidity( '' );
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

	function setOptionAvailability( select, isWoo ) {
		Array.prototype.forEach.call( select.options, function ( option ) {
			if ( option.value === 'query_parameter' ) {
				option.disabled = isWoo;
			} else if ( option.value === 'fluent_booking' ) {
				option.disabled = isWoo || ! fluentAvailable;
			} else if ( option.value === 'woocommerce_order' || option.value === 'woocommerce_billing' ) {
				option.disabled = ! isWoo || ! woocommerceAvailable;
			}
		} );
	}

	function updateParameterRow( row ) {
		var source;
		var label;
		var value;
		var fluentField;
		var wooField;
		var isQuery;
		var isFluent;
		var isWooSource;
		var isWoo = isWooCommerceTrigger();

		if ( isLocked( row ) ) {
			return;
		}

		source = row.querySelector( '.eventbridge-parameter-source' );
		label = row.querySelector( '.eventbridge-parameter-value-label-text' );
		value = row.querySelector( '.eventbridge-parameter-value' );
		fluentField = row.querySelector( '.eventbridge-parameter-fluent-field' );
		wooField = row.querySelector( '.eventbridge-parameter-woocommerce-field' );
		if ( ! source || ! label || ! value || ! fluentField || ! wooField ) {
			return;
		}

		setOptionAvailability( source, isWoo );
		if ( source.selectedOptions.length && source.selectedOptions[0].disabled ) {
			source.value = 'static';
			value.value = '';
		}

		isQuery = source.value === 'query_parameter';
		isFluent = source.value === 'fluent_booking';
		isWooSource = source.value === 'woocommerce_order';
		label.textContent = isWooSource ? 'WooCommerce-orderveld' : ( isFluent ? 'Fluent Booking-veld' : ( isQuery ? 'Queryparameternaam' : 'Vaste waarde' ) );
		value.hidden = isFluent || isWooSource;
		value.disabled = isFluent || isWooSource;
		value.required = ! isFluent && ! isWooSource;
		fluentField.hidden = ! isFluent;
		fluentField.disabled = ! isFluent;
		fluentField.required = isFluent;
		wooField.hidden = ! isWooSource;
		wooField.disabled = ! isWooSource;
		wooField.required = isWooSource;
		value.placeholder = isQuery ? 'Bijv. booking_type' : 'Bijv. hypnotherapy';
		value.maxLength = isQuery ? 100 : 500;

		if ( isQuery ) {
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
		var fixed;
		var isStatic;
		var isQuery;
		var isFluent;
		var isWooSource;
		var isWoo = isWooCommerceTrigger();

		if ( isLocked( row ) ) {
			return;
		}

		source = row.querySelector( '.eventbridge-advanced-matching-source' );
		label = row.querySelector( '.eventbridge-advanced-matching-value-label-text' );
		value = row.querySelector( '.eventbridge-advanced-matching-value' );
		fixed = row.querySelector( '.eventbridge-advanced-matching-fixed-value' );
		if ( ! source || ! label || ! value ) {
			return;
		}

		setOptionAvailability( source, isWoo );
		if ( source.selectedOptions.length && source.selectedOptions[0].disabled ) {
			source.value = '';
		}

		isStatic = source.value === 'static';
		isQuery = source.value === 'query_parameter';
		isFluent = source.value === 'fluent_booking';
		isWooSource = source.value === 'woocommerce_billing';

		if ( isWooSource ) {
			value.value = source.getAttribute( 'data-woocommerce-value' ) || '';
			if ( ! fixed ) {
				fixed = document.createElement( 'input' );
				fixed.type = 'hidden';
				fixed.className = 'eventbridge-advanced-matching-fixed-value';
				fixed.name = value.name;
				value.insertAdjacentElement( 'afterend', fixed );
			}
			fixed.value = value.value;
			value.removeAttribute( 'name' );
		} else {
			if ( fixed ) {
				value.name = fixed.name;
				fixed.remove();
			}
			if ( ! value.name ) {
				value.name = source.name.replace( /\[source\]$/, '[value]' );
			}
		}

		value.disabled = ! isStatic && ! isQuery;
		value.required = isStatic || isQuery;
		label.textContent = isWooSource ? 'Automatisch uit facturatiegegevens' : ( isFluent ? 'Automatisch uit boeking' : ( isQuery ? 'Queryparameternaam' : ( isStatic ? 'Vaste waarde' : 'Waarde' ) ) );
		value.placeholder = isQuery
			? value.getAttribute( 'data-query-placeholder' ) || ''
			: ( isStatic ? value.getAttribute( 'data-static-placeholder' ) || '' : ( isWooSource ? 'Automatisch uit facturatiegegevens' : '' ) );
		value.maxLength = isQuery ? 100 : 500;

		if ( isQuery ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );
			if ( ! isStatic && ! isFluent && ! isWooSource ) {
				value.value = '';
			}
		}

		updateDeliveryFields();
		updateFluentDependency();
	}

	function updateTokenFields() {
		if ( tokenInput && removeToken ) {
			tokenInput.setCustomValidity( removeToken.checked && tokenInput.value.trim() !== '' ? 'Kies vervangen of verwijderen, niet beide.' : '' );
		}
	}

	if ( triggerType && ! triggerType.disabled ) {
		triggerType.addEventListener( 'change', updateTriggerFields );
	}
	if ( woocommerceEvent && ! woocommerceEvent.disabled ) {
		woocommerceEvent.addEventListener( 'change', updateWooCommerceEventFields );
	}
	if ( purchasePreset && ! purchasePreset.disabled ) {
		purchasePreset.addEventListener( 'change', function () {
			presetTouched = true;
		} );
	}
	if ( dataSourceProvider ) {
		dataSourceProvider.addEventListener( 'change', updateDataSourceFields );
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

	advancedRows().forEach( function ( row ) {
		var source = row.querySelector( '.eventbridge-advanced-matching-source' );
		if ( source && ! isLocked( row ) ) {
			source.addEventListener( 'change', function () {
				updateAdvancedMatchingRow( row );
			} );
		}
	} );

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

	form.addEventListener( 'submit', function () {
		updateDeliveryFields();
		if ( ! form.checkValidity() && hasConfiguredAdvancedMatching() && capi && ! capi.checked && advancedMatching ) {
			advancedMatching.open = true;
		}
	} );

	if ( container && addButton && template ) {
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
	}

	updateTriggerFields();
	updateDataSourceFields();
	advancedRows().forEach( updateAdvancedMatchingRow );
	updateDeliveryFields();
}() );
