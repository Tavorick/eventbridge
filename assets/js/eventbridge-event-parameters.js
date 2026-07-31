( function () {
	'use strict';

	var form = document.getElementById( 'event-form' );
	if ( ! form ) {
		return;
	}

	var list = document.getElementById( 'eventbridge-trigger-list' );
	var triggerTemplate = document.getElementById( 'eventbridge-trigger-template' );
	var addTrigger = document.getElementById( 'eventbridge-add-trigger' );
	var diagnostics = document.getElementById( 'eventbridge-event-diagnostics' );
	var testMode = document.getElementById( 'eventbridge_event_meta_test_mode' );
	var testCodeRow = document.getElementById( 'eventbridge-meta-test-event-code-field' );
	var testCode = document.getElementById( 'eventbridge_event_meta_test_event_code' );
	var fluentAvailable = form.getAttribute( 'data-fluent-available' ) === '1';
	var wooAvailable = form.getAttribute( 'data-woocommerce-available' ) === '1';
	var maximumTriggers = 20;

	function cards() {
		return list ? Array.prototype.slice.call( list.querySelectorAll( ':scope > .eventbridge-trigger-card' ) ) : [];
	}

	function isWoo( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		return kind && kind.value === 'woocommerce:order_lifecycle';
	}

	function setGroupDisabled( group, disabled ) {
		if ( ! group ) {
			return;
		}
		group.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( control ) {
			if ( control.getAttribute( 'aria-disabled' ) === 'true' ) {
				return;
			}
			control.disabled = disabled;
		} );
	}

	function setOption( select, value, disabled ) {
		var option = select ? select.querySelector( 'option[value="' + value + '"]' ) : null;
		if ( option ) {
			option.disabled = disabled;
		}
	}

	function updateParameterRow( card, row ) {
		var source = row.querySelector( '.eventbridge-parameter-source' );
		var value = row.querySelector( '.eventbridge-parameter-value' );
		var fluent = row.querySelector( '.eventbridge-parameter-fluent-field' );
		var woo = row.querySelector( '.eventbridge-parameter-woocommerce-field' );
		var routeIsWoo = isWoo( card );
		var selected;

		if ( ! source || ! value || ! fluent || ! woo || row.hasAttribute( 'data-fluent-locked' ) || row.hasAttribute( 'data-woocommerce-locked' ) ) {
			return;
		}

		setOption( source, 'query_parameter', routeIsWoo );
		setOption( source, 'fluent_booking', routeIsWoo || ! fluentAvailable );
		setOption( source, 'woocommerce_order', ! routeIsWoo || ! wooAvailable );
		if ( source.selectedOptions.length && source.selectedOptions[0].disabled ) {
			source.value = 'static';
		}

		selected = source.value;
		value.hidden = selected === 'fluent_booking' || selected === 'woocommerce_order';
		value.disabled = value.hidden;
		value.required = ! value.hidden;
		fluent.hidden = selected !== 'fluent_booking';
		fluent.disabled = fluent.hidden;
		fluent.required = ! fluent.hidden;
		woo.hidden = selected !== 'woocommerce_order';
		woo.disabled = woo.hidden;
		woo.required = ! woo.hidden;
		value.maxLength = selected === 'query_parameter' ? 100 : 500;
		if ( selected === 'query_parameter' ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );
		}
	}

	function updateAdvancedRow( card, row ) {
		var source = row.querySelector( '.eventbridge-advanced-matching-source' );
		var value = row.querySelector( '.eventbridge-advanced-matching-value' );
		var fixed = row.querySelector( '.eventbridge-advanced-matching-fixed-value' );
		var routeIsWoo = isWoo( card );
		var selected;
		var valueName;

		if ( ! source || ! value || ! fixed || row.getAttribute( 'data-source-locked' ) === '1' ) {
			return;
		}

		setOption( source, 'static', routeIsWoo );
		setOption( source, 'query_parameter', routeIsWoo );
		setOption( source, 'fluent_booking', routeIsWoo || ! fluentAvailable );
		setOption( source, 'woocommerce_billing', ! routeIsWoo || ! wooAvailable );
		if ( source.selectedOptions.length && source.selectedOptions[0].disabled ) {
			source.value = '';
		}

		selected = source.value;
		valueName = source.name.replace( /\[source\]$/, '[value]' );
		if ( selected === 'woocommerce_billing' ) {
			value.value = source.getAttribute( 'data-woocommerce-value' ) || '';
			value.disabled = true;
			value.removeAttribute( 'name' );
			fixed.disabled = false;
			fixed.name = valueName;
			fixed.value = value.value;
		} else {
			fixed.disabled = true;
			value.disabled = selected !== 'static' && selected !== 'query_parameter';
			if ( value.disabled ) {
				value.removeAttribute( 'name' );
			} else {
				value.name = valueName;
			}
		}
		value.required = ! value.disabled;
		value.maxLength = selected === 'query_parameter' ? 100 : 500;
		if ( selected === 'query_parameter' ) {
			value.setAttribute( 'pattern', '[A-Za-z0-9_]+' );
		} else {
			value.removeAttribute( 'pattern' );
		}
	}

	function updateDataSource( card ) {
		var provider = card.querySelector( '.eventbridge-data-source-provider' );
		var fluent = card.querySelector( '.eventbridge-fluent-config' );
		var selected = provider && provider.value === 'fluent_booking';
		if ( ! fluent ) {
			return;
		}
		fluent.hidden = ! selected;
		fluent.querySelectorAll( 'input:not([type="hidden"])' ).forEach( function ( input ) {
			if ( input.getAttribute( 'aria-disabled' ) !== 'true' ) {
				input.disabled = ! selected;
				input.required = selected && input.name.indexOf( '[lookup_value]' ) !== -1;
			}
		} );
	}

	function updateWooEvent( card ) {
		var eventControl = card.querySelector( '.eventbridge-woocommerce-event' );
		var statusGroup = card.querySelector( '.eventbridge-woocommerce-status' );
		var statusControl = statusGroup ? statusGroup.querySelector( 'select' ) : null;
		var showStatus = isWoo( card ) && eventControl && eventControl.value === 'status';
		if ( statusGroup ) {
			statusGroup.hidden = ! showStatus;
		}
		if ( statusControl && statusControl.getAttribute( 'aria-disabled' ) !== 'true' ) {
			statusControl.disabled = ! showStatus;
			statusControl.required = showStatus;
		}
	}

	function updateCard( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		var provider = card.querySelector( '.eventbridge-trigger-provider' );
		var type = card.querySelector( '.eventbridge-trigger-type' );
		var click = card.querySelector( '.eventbridge-click-config' );
		var pageview = card.querySelector( '.eventbridge-pageview-config' );
		var wooConfig = card.querySelector( '.eventbridge-woocommerce-config' );
		var frontendSources = card.querySelector( '.eventbridge-frontend-sources' );
		var conditions = card.querySelector( '.eventbridge-route-conditions' );
		var browser = card.querySelector( '.eventbridge-channel-browser' );
		var capi = card.querySelector( '.eventbridge-channel-capi' );
		var capiRequired = card.querySelector( '.eventbridge-channel-capi-required' );
		var selector = card.querySelector( '.eventbridge-selector' );
		var urlValue = card.querySelector( '.eventbridge-url-match-value' );
		var routeIsWoo = isWoo( card );
		var routeIsPageview = kind && kind.value === 'frontend:pageview';

		card.classList.toggle( 'is-woocommerce', routeIsWoo );
		if ( provider && type && kind ) {
			provider.value = routeIsWoo ? 'woocommerce' : 'frontend';
			type.value = routeIsWoo ? 'order_lifecycle' : ( routeIsPageview ? 'pageview' : 'click' );
		}
		if ( click ) {
			click.hidden = routeIsWoo || routeIsPageview;
		}
		if ( pageview ) {
			pageview.hidden = ! routeIsPageview;
		}
		if ( wooConfig ) {
			wooConfig.hidden = ! routeIsWoo;
			setGroupDisabled( wooConfig, ! routeIsWoo );
		}
		if ( frontendSources ) {
			frontendSources.hidden = routeIsWoo;
			setGroupDisabled( frontendSources, routeIsWoo );
		}
		if ( conditions ) {
			conditions.hidden = ! routeIsWoo;
			setGroupDisabled( conditions, ! routeIsWoo );
		}
		if ( selector ) {
			selector.required = ! routeIsWoo && ! routeIsPageview;
			selector.disabled = routeIsWoo || routeIsPageview;
		}
		if ( urlValue ) {
			urlValue.required = routeIsPageview;
			urlValue.disabled = ! routeIsPageview;
		}
		if ( browser ) {
			browser.disabled = routeIsWoo;
			if ( routeIsWoo ) {
				browser.checked = false;
			}
		}
		if ( capi ) {
			capi.disabled = routeIsWoo;
			if ( routeIsWoo ) {
				capi.checked = true;
			}
		}
		if ( capiRequired ) {
			capiRequired.disabled = ! routeIsWoo;
		}

		updateWooEvent( card );
		updateDataSource( card );
		card.querySelectorAll( '.eventbridge-parameter-row:not(.eventbridge-advanced-matching-row)' ).forEach( function ( row ) {
			updateParameterRow( card, row );
		} );
		card.querySelectorAll( '.eventbridge-advanced-matching-row' ).forEach( function ( row ) {
			updateAdvancedRow( card, row );
		} );
	}

	function updateDiagnostics() {
		var hasCapi = cards().some( function ( card ) {
			var capi = card.querySelector( '.eventbridge-channel-capi' );
			return isWoo( card ) || ( capi && capi.checked );
		} );

		if ( diagnostics ) {
			diagnostics.hidden = ! hasCapi;
		}
		if ( testMode ) {
			testMode.disabled = ! hasCapi;
			if ( ! hasCapi ) {
				testMode.checked = false;
			}
		}
		if ( testCodeRow ) {
			testCodeRow.hidden = ! hasCapi || ! testMode || ! testMode.checked;
		}
		if ( testCode ) {
			testCode.disabled = ! hasCapi || ! testMode || ! testMode.checked;
			testCode.required = ! testCode.disabled;
		}
	}

	function rebuildSeparators() {
		if ( ! list ) {
			return;
		}
		list.querySelectorAll( ':scope > .eventbridge-trigger-or' ).forEach( function ( separator ) {
			separator.remove();
		} );
		cards().forEach( function ( card, index ) {
			var heading = card.querySelector( '.eventbridge-trigger-card__header h4' );
			var separator;
			if ( heading ) {
				heading.textContent = 'Trigger ' + String( index + 1 );
			}
			if ( index > 0 ) {
				separator = document.createElement( 'div' );
				separator.className = 'eventbridge-trigger-or';
				separator.setAttribute( 'aria-label', 'OF' );
				separator.innerHTML = '<span>OF</span>';
				list.insertBefore( separator, card );
			}
		} );
		cards().forEach( function ( card ) {
			var remove = card.querySelector( '.eventbridge-remove-trigger' );
			if ( remove ) {
				remove.disabled = cards().length <= 1;
			}
		} );
		if ( addTrigger ) {
			addTrigger.disabled = cards().length >= maximumTriggers;
		}
	}

	function initializeCard( card ) {
		updateCard( card );
	}

	if ( list ) {
		cards().forEach( initializeCard );
		list.addEventListener( 'change', function ( event ) {
			var card = event.target.closest( '.eventbridge-trigger-card' );
			if ( ! card ) {
				return;
			}
			if ( event.target.matches( '.eventbridge-trigger-kind, .eventbridge-data-source-provider, .eventbridge-woocommerce-event' ) ) {
				updateCard( card );
			} else if ( event.target.matches( '.eventbridge-parameter-source' ) ) {
				updateParameterRow( card, event.target.closest( '.eventbridge-parameter-row' ) );
			} else if ( event.target.matches( '.eventbridge-advanced-matching-source' ) ) {
				updateAdvancedRow( card, event.target.closest( '.eventbridge-advanced-matching-row' ) );
			}
			updateDiagnostics();
		} );

		list.addEventListener( 'click', function ( event ) {
			var card = event.target.closest( '.eventbridge-trigger-card' );
			var removeTrigger = event.target.closest( '.eventbridge-remove-trigger' );
			var addParameter = event.target.closest( '.eventbridge-add-parameter' );
			var removeParameter = event.target.closest( '.eventbridge-remove-parameter' );
			var rows;
			var template;
			var next;
			var wrapper;
			var row;

			if ( removeTrigger && card && cards().length > 1 ) {
				card.remove();
				rebuildSeparators();
				updateDiagnostics();
				return;
			}
			if ( addParameter && card ) {
				rows = card.querySelector( '.eventbridge-parameter-rows' );
				template = card.querySelector( '.eventbridge-parameter-template' );
				next = Number( rows.getAttribute( 'data-next-index' ) || 0 );
				wrapper = document.createElement( 'div' );
				wrapper.innerHTML = template.innerHTML.replace( /__PARAMETER__/g, String( next ) );
				rows.setAttribute( 'data-next-index', String( next + 1 ) );
				row = wrapper.querySelector( '.eventbridge-parameter-row' );
				if ( row ) {
					rows.appendChild( row );
					updateParameterRow( card, row );
				}
				return;
			}
			if ( removeParameter && card ) {
				row = removeParameter.closest( '.eventbridge-parameter-row' );
				if ( row && ! row.hasAttribute( 'data-fluent-locked' ) && ! row.hasAttribute( 'data-woocommerce-locked' ) ) {
					row.remove();
				}
			}
		} );
	}

	if ( addTrigger && triggerTemplate && list ) {
		addTrigger.addEventListener( 'click', function () {
			var next = Number( list.getAttribute( 'data-next-index' ) || 0 );
			var wrapper = document.createElement( 'div' );
			var card;
			if ( cards().length >= maximumTriggers ) {
				return;
			}
			wrapper.innerHTML = triggerTemplate.innerHTML.replace( /__TRIGGER__/g, String( next ) );
			card = wrapper.querySelector( '.eventbridge-trigger-card' );
			if ( card ) {
				list.appendChild( card );
				list.setAttribute( 'data-next-index', String( next + 1 ) );
				initializeCard( card );
				rebuildSeparators();
				updateDiagnostics();
				card.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}
		} );
	}

	if ( testMode ) {
		testMode.addEventListener( 'change', updateDiagnostics );
	}

	document.querySelectorAll( '.eventbridge-delete-form' ).forEach( function ( deleteForm ) {
		deleteForm.addEventListener( 'submit', function ( event ) {
			var message = deleteForm.getAttribute( 'data-confirm' );
			if ( message && ! window.confirm( message ) ) {
				event.preventDefault();
			}
		} );
	} );

	rebuildSeparators();
	updateDiagnostics();
}() );
