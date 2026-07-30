( function ( $, config ) {
	'use strict';

	var form = document.getElementById( 'event-form' );
	var section = document.getElementById( 'eventbridge-conditions-section' );
	if ( ! form || ! section || ! config || ! config.catalog ) {
		return;
	}

	var rows = document.getElementById( 'eventbridge-condition-rows' );
	var template = document.getElementById( 'eventbridge-condition-template' );
	var addButton = document.getElementById( 'eventbridge-add-condition' );
	var trigger = document.getElementById( 'eventbridge_event_trigger_type' );
	var warning = document.getElementById( 'eventbridge-condition-trigger-warning' );
	var nextIndex = rows ? rows.querySelectorAll( '.eventbridge-condition-row' ).length : 0;
	var initializedAttribute = 'data-eventbridge-selectwoo';
	var signatureAttribute = 'data-eventbridge-control-signature';

	function isWooCommerce() {
		return trigger && trigger.value === 'woocommerce';
	}

	function operatorConfig( row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var operator = row.querySelector( '.eventbridge-condition-operator' );
		if ( ! field || ! operator || ! config.catalog[ field.value ] || ! config.catalog[ field.value ].operators ) {
			return null;
		}
		return config.catalog[ field.value ].operators[ operator.value ] || null;
	}

	function configureSearchControl( select, current ) {
		var isSingleReference = current && current.value_type === 'reference';
		var isAutocompleteInput = current && ( current.value_type === 'reference' || current.value_type === 'references' );
		var emptyOption;
		var wrapper;
		if ( ! select ) {
			return;
		}

		wrapper = select.closest( '.eventbridge-condition-value' );
		if ( isAutocompleteInput ) {
			select.setAttribute( 'data-eventbridge-autocomplete-input', '1' );
			if ( wrapper ) {
				wrapper.classList.add( 'eventbridge-condition-value--autocomplete-input' );
			}
		} else {
			select.removeAttribute( 'data-eventbridge-autocomplete-input' );
			if ( wrapper ) {
				wrapper.classList.remove( 'eventbridge-condition-value--autocomplete-input' );
			}
		}

		if ( isSingleReference ) {
			select.multiple = true;
			select.setAttribute( 'data-eventbridge-single-value', '1' );
			emptyOption = select.querySelector( 'option[value=""]' );
			if ( emptyOption ) {
				emptyOption.remove();
			}
			return;
		}

		select.removeAttribute( 'data-eventbridge-single-value' );
	}

	function initializeSearch( select ) {
		var wrapper;
		var state;
		var selectedValue;
		var placeholder;
		var maximumSelections;
		if ( ! select ) {
			return;
		}

		wrapper = select.closest( '.eventbridge-condition-value' );
		state = select.getAttribute( initializedAttribute );
		maximumSelections = select.getAttribute( 'data-eventbridge-single-value' ) === '1'
			? 1
			: ( select.multiple ? Number( config.maxReferences ) || 100 : 0 );
		if ( ! select.multiple && ( ! select.firstElementChild || select.firstElementChild.value !== '' ) ) {
			selectedValue = select.value;
			placeholder = document.createElement( 'option' );
			placeholder.value = '';
			select.insertBefore( placeholder, select.firstElementChild );
			if ( selectedValue !== '' ) {
				select.value = selectedValue;
			}
		}
		if ( state === 'initialized' || $( select ).data( 'select2' ) || select.classList.contains( 'select2-hidden-accessible' ) ) {
			select.setAttribute( initializedAttribute, 'initialized' );
			if ( wrapper ) {
				wrapper.classList.add( 'eventbridge-condition-value--ready' );
				wrapper.classList.remove( 'eventbridge-condition-value--fallback' );
			}
			return;
		}

		if ( state === 'initializing' || state === 'fallback' || state === 'failed' ) {
			return;
		}

		if ( typeof $.fn.selectWoo !== 'function' ) {
			select.setAttribute( initializedAttribute, 'fallback' );
			if ( wrapper ) {
				wrapper.classList.add( 'eventbridge-condition-value--fallback' );
			}
			return;
		}

		select.setAttribute( initializedAttribute, 'initializing' );
		try {
			$( select ).selectWoo( {
				width: '100%',
				placeholder: config.texts.chooseValue,
				allowClear: ! select.multiple,
				minimumInputLength: select.getAttribute( 'data-eventbridge-autocomplete-input' ) === '1' ? 1 : 0,
				maximumSelectionLength: maximumSelections,
				ajax: {
					url: config.ajaxUrl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							action: 'eventbridge_condition_search',
							nonce: config.nonce,
							provider: config.provider,
							field: select.getAttribute( 'data-field' ),
							q: params.term || '',
							page: params.page || 1
						};
					},
					processResults: function ( response, params ) {
						var data = response && response.success ? response.data : { results: [], more: false };
						params.page = params.page || 1;
						return {
							results: data.results || [],
							pagination: { more: Boolean( data.more ) }
						};
					}
				}
			} );
		} catch ( error ) {
			select.setAttribute( initializedAttribute, 'failed' );
			if ( wrapper ) {
				wrapper.classList.add( 'eventbridge-condition-value--fallback' );
			}
			return;
		}
		select.setAttribute( initializedAttribute, 'initialized' );
		if ( wrapper ) {
			wrapper.classList.add( 'eventbridge-condition-value--ready' );
			wrapper.classList.remove( 'eventbridge-condition-value--fallback' );
		}
	}

	function destroySearch( select ) {
		var wrapper;
		if ( ! select ) {
			return;
		}

		wrapper = select.closest( '.eventbridge-condition-value' );
		if ( typeof $.fn.selectWoo === 'function' && ( $( select ).data( 'select2' ) || select.classList.contains( 'select2-hidden-accessible' ) ) ) {
			$( select ).selectWoo( 'destroy' );
		}
		$( select ).off( '.eventbridgeConditions' );
		select.removeAttribute( initializedAttribute );
		if ( wrapper ) {
			wrapper.classList.remove( 'eventbridge-condition-value--ready', 'eventbridge-condition-value--fallback' );
		}
	}

	function controlSignature( field, current ) {
		if ( ! field || ! current ) {
			return ( field ? field.value : '' ) + '|empty|single';
		}

		return field.value + '|' + current.value_type + '|' + ( current.value_type === 'references' ? 'multiple' : 'single' );
	}

	function syncValue( row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var wrapper = row.querySelector( '.eventbridge-condition-value' );
		var current = operatorConfig( row );
		var base;
		var input;
		var signature;
		if ( ! field || ! wrapper ) {
			return;
		}

		signature = controlSignature( field, current );
		if ( wrapper.getAttribute( signatureAttribute ) === signature ) {
			input = wrapper.querySelector( '.eventbridge-condition-search' );
			if ( input ) {
				initializeSearch( input );
			}
			return;
		}

		base = field.name.replace( /\[field\]$/, '' );
		$( wrapper ).find( '.eventbridge-condition-search' ).each( function () {
			destroySearch( this );
		} );
		wrapper.classList.remove( 'eventbridge-condition-value--autocomplete-input' );
		wrapper.replaceChildren();
		wrapper.setAttribute( signatureAttribute, signature );
		if ( ! current ) {
			input = document.createElement( 'input' );
			input.type = 'text';
			input.disabled = true;
			wrapper.appendChild( input );
			return;
		}

		if ( current.value_type === 'reference' || current.value_type === 'references' || current.value_type === 'reference_string' ) {
			input = document.createElement( 'select' );
			input.className = 'eventbridge-condition-search';
			input.name = base + '[value]' + ( current.value_type === 'references' ? '[]' : '' );
			input.required = true;
			input.multiple = current.value_type === 'references';
			input.setAttribute( 'data-field', field.value );
			input.setAttribute( 'data-search', current.search || field.value );
			wrapper.appendChild( input );
			configureSearchControl( input, current );
			initializeSearch( input );
			return;
		}

		if ( current.value_type === 'fixed_true' ) {
			input = document.createElement( 'input' );
			input.type = 'text';
			input.value = config.texts.yes;
			input.disabled = true;
			wrapper.appendChild( input );
			input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = base + '[value]';
			input.value = '1';
			wrapper.appendChild( input );
			return;
		}

		input = document.createElement( 'input' );
		input.name = base + '[value]';
		input.required = true;
		if ( current.value_type === 'decimal' || current.value_type === 'integer' ) {
			input.type = 'number';
			input.min = '0';
			input.step = current.value_type === 'decimal' ? 'any' : '1';
		} else {
			input.type = 'text';
			input.maxLength = 100;
		}
		wrapper.appendChild( input );
	}

	function updateOperators( row, preserve ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var operator = row.querySelector( '.eventbridge-condition-operator' );
		var previous = preserve ? operator.value : '';
		var operators = field && config.catalog[ field.value ] ? config.catalog[ field.value ].operators : {};
		var placeholder = document.createElement( 'option' );
		if ( ! field || ! operator ) {
			return;
		}

		operator.innerHTML = '';
		placeholder.value = '';
		placeholder.textContent = 'Kies een operator';
		operator.appendChild( placeholder );
		Object.keys( operators || {} ).forEach( function ( key ) {
			var option = document.createElement( 'option' );
			option.value = key;
			option.textContent = operators[ key ].label;
			operator.appendChild( option );
		} );
		operator.value = operators && operators[ previous ] ? previous : Object.keys( operators || {} )[0] || '';
		syncValue( row );
	}

	function initializeRow( row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var wrapper = row.querySelector( '.eventbridge-condition-value' );
		var search = row.querySelector( '.eventbridge-condition-search' );
		var current = operatorConfig( row );
		if ( wrapper ) {
			wrapper.setAttribute( signatureAttribute, controlSignature( field, current ) );
		}
		if ( search ) {
			configureSearchControl( search, current );
			initializeSearch( search );
		}
	}

	function updateVisibility() {
		var hasRows = rows && rows.querySelector( '.eventbridge-condition-row' );
		var isWoo = isWooCommerce();
		section.hidden = ! isWoo && ! hasRows;
		if ( warning ) {
			warning.hidden = isWoo || ! hasRows;
		}
		if ( addButton ) {
			addButton.disabled = ! isWoo || section.getAttribute( 'data-woocommerce-locked' ) === '1';
		}
	}

	if ( rows ) {
		rows.querySelectorAll( '.eventbridge-condition-row' ).forEach( initializeRow );
		rows.addEventListener( 'change', function ( event ) {
			var row = event.target.closest( '.eventbridge-condition-row' );
			if ( ! row || row.getAttribute( 'data-woocommerce-locked' ) === '1' ) {
				return;
			}
			if ( event.target.classList.contains( 'eventbridge-condition-field' ) ) {
				updateOperators( row, false );
			} else if ( event.target.classList.contains( 'eventbridge-condition-operator' ) ) {
				syncValue( row );
			}
		} );
		rows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.eventbridge-remove-condition' );
			var row;
			if ( ! button ) {
				return;
			}
			row = button.closest( '.eventbridge-condition-row' );
			if ( row ) {
				$( row ).find( '.eventbridge-condition-search' ).each( function () {
					destroySearch( this );
				} );
				row.remove();
				updateVisibility();
			}
		} );
	}

	if ( addButton && template && rows ) {
		addButton.addEventListener( 'click', function () {
			var wrapper = document.createElement( 'div' );
			var row;
			wrapper.innerHTML = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
			nextIndex += 1;
			row = wrapper.querySelector( '.eventbridge-condition-row' );
			if ( row ) {
				updateOperators( row, false );
				rows.appendChild( row );
				updateVisibility();
			}
		} );
	}

	if ( trigger ) {
		trigger.addEventListener( 'change', updateVisibility );
	}

	updateVisibility();
}( window.jQuery, window.eventbridgeConditions ) );
