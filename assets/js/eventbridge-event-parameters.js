( function () {
	'use strict';

	var form = document.getElementById( 'event-form' );
	if ( ! form ) {
		return;
	}

	var list = document.getElementById( 'eventbridge-trigger-list' );
	var triggerTemplate = document.getElementById( 'eventbridge-trigger-template' );
	var addTrigger = document.getElementById( 'eventbridge-add-trigger' );
	var familyConflict = document.getElementById( 'eventbridge-family-conflict' );
	var submitButton = document.getElementById( 'eventbridge-event-submit' );
	var channelSection = document.getElementById( 'eventbridge-event-channels' );
	var browserChannel = document.getElementById( 'eventbridge_event_browser' );
	var capiChannel = document.getElementById( 'eventbridge_event_capi' );
	var capiRequired = document.getElementById( 'eventbridge_event_capi_required' );
	var channelExplanation = document.getElementById( 'eventbridge-channel-explanation' );
	var channelAdjustment = document.getElementById( 'eventbridge-channel-adjustment' );
	var channelError = document.getElementById( 'eventbridge-channel-error' );
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
		return kind && kind.value === 'backend:woocommerce';
	}

	function interactionType( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		var subtype = card.querySelector( '.eventbridge-woocommerce-interaction-event' );
		return kind && kind.value === 'frontend:woocommerce' && subtype ? subtype.value : '';
	}

	function isAnyWoo( card ) {
		return isWoo( card ) || interactionType( card ) !== '';
	}

	function familyOf( card ) {
		var kind = card ? card.querySelector( '.eventbridge-trigger-kind' ) : null;
		var option = kind && kind.selectedOptions.length ? kind.selectedOptions[0] : null;
		return option ? option.getAttribute( 'data-family' ) || '' : '';
	}

	function setCardExpanded( card, expanded ) {
		var toggle = card ? card.querySelector( '.eventbridge-trigger-toggle' ) : null;
		var body = card ? card.querySelector( '.eventbridge-trigger-card__body' ) : null;
		if ( ! card || ! toggle || ! body ) {
			return;
		}
		card.classList.toggle( 'is-expanded', expanded );
		toggle.setAttribute( 'aria-expanded', expanded ? 'true' : 'false' );
		body.hidden = ! expanded;
	}

	function updateCardSummary( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		var summary = card.querySelector( '.eventbridge-trigger-summary' );
		var selector = card.querySelector( '.eventbridge-selector' );
		var url = card.querySelector( '.eventbridge-url-match-value' );
		var wooEvent = card.querySelector( '.eventbridge-woocommerce-event' );
		var interactionEvent = card.querySelector( '.eventbridge-woocommerce-interaction-event' );
		var text = '';
		if ( ! kind || ! summary ) {
			return;
		}
		if ( kind.value === 'backend:woocommerce' ) {
			text = 'WooCommerce' + ( wooEvent && wooEvent.selectedOptions.length ? ' — ' + wooEvent.selectedOptions[0].textContent : '' );
		} else if ( kind.value === 'frontend:woocommerce' ) {
			text = 'WooCommerce' + ( interactionEvent && interactionEvent.selectedOptions.length ? ' — ' + interactionEvent.selectedOptions[0].textContent : '' );
		} else if ( kind.value === 'frontend:pageview' ) {
			text = url && url.value ? url.value : 'Paginabezoek';
		} else {
			text = selector && selector.value ? selector.value : 'CSS-selector';
		}
		summary.textContent = text;
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
			option.disabled = disabled && ! option.selected;
		}
	}

	function markParameterCompatibility( row, source, interaction, incompatible, invalidControl ) {
		var note = row.querySelector( '.eventbridge-compatibility-note' );
		var message = 'Deze parameterbron is niet beschikbaar voor de gekozen WooCommerce-gebeurtenis. Kies een passend interactieveld of herstel het subtype.';
		row.classList.toggle( 'is-incompatible', incompatible );
		if ( incompatible ) {
			row.setAttribute( 'data-eventbridge-incompatible', '1' );
		} else {
			row.removeAttribute( 'data-eventbridge-incompatible' );
		}
		[ source, interaction ].forEach( function ( control ) {
			if ( ! control ) {
				return;
			}
			control.removeAttribute( 'aria-invalid' );
			control.setCustomValidity( '' );
		} );
		if ( incompatible && invalidControl ) {
			invalidControl.setAttribute( 'aria-invalid', 'true' );
			invalidControl.setCustomValidity( message );
		}
		if ( note ) {
			note.hidden = ! incompatible;
		}
	}

	function updateParameterRow( card, row ) {
		var source = row.querySelector( '.eventbridge-parameter-source' );
		var value = row.querySelector( '.eventbridge-parameter-value' );
		var fluent = row.querySelector( '.eventbridge-parameter-fluent-field' );
		var woo = row.querySelector( '.eventbridge-parameter-woocommerce-field' );
		var interaction = row.querySelector( '.eventbridge-parameter-interaction-field' );
		var routeIsWoo = isWoo( card );
		var interactionContext = interactionType( card );
		var selected;
		var selectedInteraction;
		var interactionContexts;
		var incompatible;
		var sourceIncompatible;
		var fieldIncompatible;

		if ( ! source || ! value || ! fluent || ! woo || ! interaction || row.hasAttribute( 'data-fluent-locked' ) || row.hasAttribute( 'data-woocommerce-locked' ) ) {
			return;
		}

		setOption( source, 'query_parameter', routeIsWoo );
		setOption( source, 'fluent_booking', routeIsWoo || ! fluentAvailable );
		setOption( source, 'woocommerce_order', ! routeIsWoo || ! wooAvailable );
		setOption( source, 'woocommerce_interaction', interactionContext === '' || ! wooAvailable );

		selected = source.value;
		value.hidden = selected === 'fluent_booking' || selected === 'woocommerce_order' || selected === 'woocommerce_interaction';
		value.disabled = value.hidden;
		value.required = ! value.hidden;
		fluent.hidden = selected !== 'fluent_booking';
		fluent.disabled = fluent.hidden;
		fluent.required = ! fluent.hidden;
		woo.hidden = selected !== 'woocommerce_order';
		woo.disabled = woo.hidden;
		woo.required = ! woo.hidden;
		interaction.hidden = selected !== 'woocommerce_interaction';
		interaction.disabled = interaction.hidden;
		interaction.required = ! interaction.hidden;
		Array.prototype.forEach.call( interaction.options, function ( option ) {
			var contexts = ( option.getAttribute( 'data-contexts' ) || '' ).split( ',' );
			option.disabled = contexts.indexOf( interactionContext ) === -1 && ! option.selected;
		} );
		selectedInteraction = interaction.selectedOptions.length ? interaction.selectedOptions[0] : null;
		interactionContexts = selectedInteraction ? ( selectedInteraction.getAttribute( 'data-contexts' ) || '' ).split( ',' ) : [];
		sourceIncompatible = ( selected === 'woocommerce_interaction' && interactionContext === '' )
			|| ( selected === 'woocommerce_order' && ! routeIsWoo )
			|| ( routeIsWoo && ( selected === 'query_parameter' || selected === 'fluent_booking' ) );
		fieldIncompatible = selected === 'woocommerce_interaction' && interactionContext !== '' && ( ! selectedInteraction || interactionContexts.indexOf( interactionContext ) === -1 );
		incompatible = sourceIncompatible || fieldIncompatible;
		markParameterCompatibility( row, source, interaction, incompatible, fieldIncompatible ? interaction : source );
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
		var interactionConfig = card.querySelector( '.eventbridge-woocommerce-interaction-config' );
		var wooConfig = card.querySelector( '.eventbridge-woocommerce-config' );
		var frontendSources = card.querySelector( '.eventbridge-frontend-sources' );
		var conditions = card.querySelector( '.eventbridge-route-conditions' );
		var selector = card.querySelector( '.eventbridge-selector' );
		var urlValue = card.querySelector( '.eventbridge-url-match-value' );
		var routeIsWoo = isWoo( card );
		var routeIsAnyWoo = isAnyWoo( card );
		var interactionContext = interactionType( card );
		var routeIsPageview = kind && kind.value === 'frontend:pageview';
		var hasConfiguredConditions = conditions && conditions.querySelector( '.eventbridge-condition-row' );

		card.classList.toggle( 'is-woocommerce-lifecycle', routeIsWoo );
		card.classList.toggle( 'is-woocommerce-interaction', interactionContext !== '' );
		card.setAttribute( 'data-condition-context', routeIsWoo ? 'order' : interactionContext );
		if ( provider && type && kind ) {
			provider.value = routeIsAnyWoo ? 'woocommerce' : 'frontend';
			type.value = routeIsWoo ? 'order_lifecycle' : ( interactionContext || ( routeIsPageview ? 'pageview' : 'click' ) );
		}
		if ( click ) {
			click.hidden = routeIsAnyWoo || routeIsPageview;
		}
		if ( pageview ) {
			pageview.hidden = ! routeIsPageview;
		}
		if ( interactionConfig ) {
			interactionConfig.hidden = interactionContext === '';
			setGroupDisabled( interactionConfig, interactionContext === '' );
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
			conditions.hidden = ! routeIsAnyWoo && ! hasConfiguredConditions;
			setGroupDisabled( conditions, conditions.hidden );
		}
		if ( selector ) {
			selector.required = ! routeIsAnyWoo && ! routeIsPageview;
			selector.disabled = routeIsAnyWoo || routeIsPageview;
		}
		if ( urlValue ) {
			urlValue.required = routeIsPageview;
			urlValue.disabled = ! routeIsPageview;
		}
		updateWooEvent( card );
		updateDataSource( card );
		card.querySelectorAll( '.eventbridge-parameter-row:not(.eventbridge-advanced-matching-row)' ).forEach( function ( row ) {
			updateParameterRow( card, row );
		} );
		card.querySelectorAll( '.eventbridge-advanced-matching-row' ).forEach( function ( row ) {
			updateAdvancedRow( card, row );
		} );
		updateCardSummary( card );
	}

	function updateDiagnostics() {
		var hasCapi = capiChannel && capiChannel.checked;

		if ( diagnostics ) {
			diagnostics.hidden = ! hasCapi;
		}
		if ( testMode ) {
			testMode.disabled = ! hasCapi;
			if ( ! hasCapi ) {
				testMode.checked = false;
			}
		}
		if ( testCode ) {
			// Keep this successful form control enabled: it is event-level state and
			// must survive a temporary browser-only/CAPI-off selection.
			testCode.required = hasCapi && testMode && testMode.checked;
		}
		cards().forEach( function ( card ) {
			var warning = card.querySelector( '.eventbridge-route-advanced-capi-warning' );
			if ( warning ) {
				warning.hidden = hasCapi;
			}
		} );
	}

	function updateFamilyAndChannels( explainAdjustment ) {
		var currentCards = cards();
		var family = currentCards.length ? familyOf( currentCards[0] ) : '';
		var conflict = false;
		var hasIncompatible = Boolean( form.querySelector( '[data-eventbridge-incompatible="1"]' ) );

		currentCards.forEach( function ( card, index ) {
			var select = card.querySelector( '.eventbridge-trigger-kind' );
			if ( index > 0 && familyOf( card ) !== family ) {
				conflict = true;
			}
			if ( ! select ) {
				return;
			}
			Array.prototype.forEach.call( select.options, function ( option ) {
				var optionFamily = option.getAttribute( 'data-family' ) || '';
				var unavailableWoo = option.getAttribute( 'data-woocommerce' ) === '1' && ! wooAvailable && ! option.selected;
				var optionIncompatible = index > 0 && optionFamily !== family && ! option.selected;
				option.disabled = unavailableWoo || optionIncompatible;
			} );
		} );

		if ( familyConflict ) {
			familyConflict.hidden = ! conflict;
		}
		if ( submitButton ) {
			submitButton.disabled = conflict || hasIncompatible;
		}
		if ( addTrigger ) {
			addTrigger.disabled = conflict || currentCards.length >= maximumTriggers || ( family === 'server_lifecycle' && ! wooAvailable );
		}

		if ( channelSection ) {
			channelSection.setAttribute( 'data-family', family );
		}
		if ( family === 'server_lifecycle' ) {
			if ( browserChannel && browserChannel.checked && explainAdjustment && channelAdjustment ) {
				channelAdjustment.textContent = 'Browser is uitgeschakeld omdat backendtriggers uitsluitend via CAPI verzenden.';
				channelAdjustment.hidden = false;
			}
			if ( browserChannel ) {
				browserChannel.checked = false;
				browserChannel.disabled = true;
			}
			if ( capiChannel ) {
				capiChannel.checked = true;
				capiChannel.disabled = true;
			}
			if ( capiRequired ) {
				capiRequired.disabled = false;
			}
			if ( channelExplanation ) {
				channelExplanation.textContent = 'Backendtriggers worden uitsluitend via Meta Conversion API verstuurd.';
			}
		} else {
			if ( channelAdjustment ) {
				channelAdjustment.hidden = true;
				channelAdjustment.textContent = '';
			}
			if ( browserChannel ) {
				browserChannel.disabled = false;
			}
			if ( capiChannel ) {
				capiChannel.disabled = false;
			}
			if ( capiRequired ) {
				capiRequired.disabled = true;
			}
			if ( channelExplanation ) {
				channelExplanation.textContent = 'Frontendtriggers kunnen via browser, CAPI of beide worden verstuurd.';
			}
		}
		if ( channelError ) {
			channelError.hidden = family === 'server_lifecycle' || ( browserChannel && browserChannel.checked ) || ( capiChannel && capiChannel.checked );
		}
		updateDiagnostics();
	}

	function rebuildSeparators() {
		if ( ! list ) {
			return;
		}
		list.querySelectorAll( ':scope > .eventbridge-trigger-or' ).forEach( function ( separator ) {
			separator.remove();
		} );
		cards().forEach( function ( card, index ) {
			var title = card.querySelector( '.eventbridge-trigger-title' );
			var toggle = card.querySelector( '.eventbridge-trigger-toggle' );
			var body = card.querySelector( '.eventbridge-trigger-card__body' );
			var kind = card.querySelector( '.eventbridge-trigger-kind' );
			var help = card.querySelector( '.eventbridge-trigger-family-help' );
			var separator;
			var panelId = 'eventbridge-trigger-panel-' + String( index );
			var helpId = 'eventbridge-trigger-family-help-' + String( index );
			if ( title ) {
				title.textContent = 'Trigger ' + String( index + 1 );
			}
			if ( body && toggle ) {
				body.id = panelId;
				toggle.setAttribute( 'aria-controls', panelId );
			}
			if ( kind && help ) {
				help.id = helpId;
				kind.setAttribute( 'aria-describedby', helpId );
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
		setCardExpanded( card, card.classList.contains( 'is-expanded' ) );
	}

	if ( list ) {
		cards().forEach( initializeCard );
		list.addEventListener( 'change', function ( event ) {
			var card = event.target.closest( '.eventbridge-trigger-card' );
			if ( card && event.target.matches( '.eventbridge-trigger-kind, .eventbridge-data-source-provider, .eventbridge-woocommerce-event, .eventbridge-woocommerce-interaction-event' ) ) {
				updateCard( card );
			} else if ( card && event.target.matches( '.eventbridge-parameter-source, .eventbridge-parameter-interaction-field' ) ) {
				updateParameterRow( card, event.target.closest( '.eventbridge-parameter-row' ) );
			} else if ( card && event.target.matches( '.eventbridge-advanced-matching-source' ) ) {
				updateAdvancedRow( card, event.target.closest( '.eventbridge-advanced-matching-row' ) );
			}
			updateFamilyAndChannels( !! card && event.target.matches( '.eventbridge-trigger-kind' ) );
		} );
		list.addEventListener( 'eventbridge:compatibilitychange', function () {
			updateFamilyAndChannels( false );
		} );

		list.addEventListener( 'click', function ( event ) {
			var card = event.target.closest( '.eventbridge-trigger-card' );
			var header = event.target.closest( '.eventbridge-trigger-card__header' );
			var toggle = event.target.closest( '.eventbridge-trigger-toggle' );
			var removeTrigger = event.target.closest( '.eventbridge-remove-trigger' );
			var addParameter = event.target.closest( '.eventbridge-add-parameter' );
			var removeParameter = event.target.closest( '.eventbridge-remove-parameter' );
			var rows;
			var template;
			var next;
			var wrapper;
			var row;

			if ( card && header && ! removeTrigger && ( toggle || ! event.target.closest( 'button, input, select, textarea, a, label' ) ) ) {
				setCardExpanded( card, ! card.classList.contains( 'is-expanded' ) );
				return;
			}

			if ( removeTrigger && card && cards().length > 1 ) {
				card.remove();
				rebuildSeparators();
				updateFamilyAndChannels( true );
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
					updateFamilyAndChannels( false );
				}
			}
		} );

		list.addEventListener( 'input', function ( event ) {
			var card = event.target.closest( '.eventbridge-trigger-card' );
			if ( card && event.target.matches( '.eventbridge-selector, .eventbridge-url-match-value' ) ) {
				updateCardSummary( card );
			}
		} );
	}

	form.addEventListener( 'invalid', function ( event ) {
		var card = event.target.closest( '.eventbridge-trigger-card' );
		if ( card ) {
			setCardExpanded( card, true );
		}
	}, true );

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
				var family = cards().length ? familyOf( cards()[0] ) : '';
				var kind = card.querySelector( '.eventbridge-trigger-kind' );
				if ( kind && family ) {
					Array.prototype.some.call( kind.options, function ( option ) {
						if ( option.getAttribute( 'data-family' ) === family && ! option.disabled ) {
							kind.value = option.value;
							return true;
						}
						return false;
					} );
				}
				list.appendChild( card );
				list.setAttribute( 'data-next-index', String( next + 1 ) );
				initializeCard( card );
				rebuildSeparators();
				updateFamilyAndChannels( false );
				card.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
			}
		} );
	}

	if ( testMode ) {
		testMode.addEventListener( 'change', updateDiagnostics );
	}

	if ( browserChannel ) {
		browserChannel.addEventListener( 'change', function () { updateFamilyAndChannels( false ); } );
	}
	if ( capiChannel ) {
		capiChannel.addEventListener( 'change', function () { updateFamilyAndChannels( false ); } );
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
	updateFamilyAndChannels( false );
}() );
