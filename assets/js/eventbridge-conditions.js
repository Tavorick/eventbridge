( function ( $, config ) {
	'use strict';

	var form = document.getElementById( 'event-form' );
	var list = document.getElementById( 'eventbridge-trigger-list' );
	if ( ! form || ! list || ! config || ! config.catalog ) {
		return;
	}

	function isWooCard( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		return kind && kind.value.indexOf( 'woocommerce:' ) === 0;
	}

	function conditionContext( card ) {
		var kind = card.querySelector( '.eventbridge-trigger-kind' );
		return kind && kind.value === 'woocommerce:order_lifecycle' ? 'order' : ( kind && kind.value.indexOf( 'woocommerce:' ) === 0 ? kind.value.split( ':' )[1] : '' );
	}

	function updateFieldAvailability( card, row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var context = conditionContext( card );
		if ( ! field ) {
			return;
		}
		Array.prototype.forEach.call( field.options, function ( option ) {
			var contexts = ( option.getAttribute( 'data-contexts' ) || '' ).split( ',' );
			option.disabled = option.value !== '' && contexts.indexOf( context ) === -1 && ! option.selected;
		} );
	}

	function operatorConfig( row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var operator = row.querySelector( '.eventbridge-condition-operator' );
		return field && operator && config.catalog[ field.value ] && config.catalog[ field.value ].operators
			? config.catalog[ field.value ].operators[ operator.value ] || null
			: null;
	}

	function destroySearch( select ) {
		if ( ! select ) {
			return;
		}
		if ( typeof $.fn.selectWoo === 'function' && ( $( select ).data( 'select2' ) || select.classList.contains( 'select2-hidden-accessible' ) ) ) {
			$( select ).selectWoo( 'destroy' );
		}
		select.removeAttribute( 'data-eventbridge-selectwoo' );
	}

	function initializeSearch( select ) {
		var maximum;
		if ( ! select || select.getAttribute( 'data-eventbridge-selectwoo' ) === '1' || typeof $.fn.selectWoo !== 'function' ) {
			return;
		}
		maximum = select.getAttribute( 'data-eventbridge-single-value' ) === '1' ? 1 : ( select.multiple ? Number( config.maxReferences ) || 100 : 0 );
		try {
			$( select ).selectWoo( {
				width: '100%',
				placeholder: config.texts.chooseValue,
				allowClear: true,
				minimumInputLength: select.getAttribute( 'data-eventbridge-autocomplete-input' ) === '1' ? 1 : 0,
				maximumSelectionLength: maximum,
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
					processResults: function ( response ) {
						var data = response && response.success ? response.data : { results: [], more: false };
						return { results: data.results || [], pagination: { more: Boolean( data.more ) } };
					}
				}
			} );
			select.setAttribute( 'data-eventbridge-selectwoo', '1' );
		} catch ( error ) {
			select.setAttribute( 'data-eventbridge-selectwoo', 'fallback' );
		}
	}

	function configureSearch( select, current ) {
		if ( ! select || ! current ) {
			return;
		}
		if ( current.value_type === 'reference' ) {
			select.multiple = true;
			select.setAttribute( 'data-eventbridge-single-value', '1' );
		}
		if ( current.value_type === 'reference' || current.value_type === 'references' ) {
			select.setAttribute( 'data-eventbridge-autocomplete-input', '1' );
		}
		initializeSearch( select );
	}

	function syncValue( row ) {
		var field = row.querySelector( '.eventbridge-condition-field' );
		var wrapper = row.querySelector( '.eventbridge-condition-value' );
		var current = operatorConfig( row );
		var base;
		var input;
		if ( ! field || ! wrapper ) {
			return;
		}

		wrapper.querySelectorAll( '.eventbridge-condition-search' ).forEach( destroySearch );
		wrapper.replaceChildren();
		if ( ! current ) {
			input = document.createElement( 'input' );
			input.type = 'text';
			input.disabled = true;
			wrapper.appendChild( input );
			return;
		}

		base = field.name.replace( /\[field\]$/, '' );
		if ( current.value_type === 'reference' || current.value_type === 'references' || current.value_type === 'reference_string' ) {
			input = document.createElement( 'select' );
			input.className = 'eventbridge-condition-search';
			input.name = base + '[value]' + ( current.value_type === 'references' ? '[]' : '' );
			input.required = true;
			input.multiple = current.value_type === 'references';
			input.setAttribute( 'data-field', field.value );
			input.setAttribute( 'data-search', current.search || field.value );
			wrapper.appendChild( input );
			configureSearch( input, current );
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
		var previous = preserve && operator ? operator.value : '';
		var operators = field && config.catalog[ field.value ] ? config.catalog[ field.value ].operators : {};
		var placeholder;
		if ( ! field || ! operator ) {
			return;
		}
		operator.replaceChildren();
		placeholder = document.createElement( 'option' );
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

	function initializeRow( card, row ) {
		var search = row.querySelector( '.eventbridge-condition-search' );
		updateFieldAvailability( card, row );
		if ( search && isWooCard( card ) ) {
			configureSearch( search, operatorConfig( row ) );
		}
	}

	function syncCardSearches( card ) {
		card.querySelectorAll( '.eventbridge-condition-row' ).forEach( function ( row ) {
			updateFieldAvailability( card, row );
		} );
		card.querySelectorAll( '.eventbridge-condition-search' ).forEach( function ( select ) {
			if ( isWooCard( card ) ) {
				configureSearch( select, operatorConfig( select.closest( '.eventbridge-condition-row' ) ) );
			} else {
				destroySearch( select );
			}
		} );
	}

	list.querySelectorAll( '.eventbridge-trigger-card' ).forEach( function ( card ) {
		card.querySelectorAll( '.eventbridge-condition-row' ).forEach( function ( row ) {
			initializeRow( card, row );
		} );
	} );

	list.addEventListener( 'change', function ( event ) {
		var card = event.target.closest( '.eventbridge-trigger-card' );
		var row = event.target.closest( '.eventbridge-condition-row' );
		if ( ! card ) {
			return;
		}
		if ( event.target.classList.contains( 'eventbridge-trigger-kind' ) ) {
			syncCardSearches( card );
		} else if ( row && event.target.classList.contains( 'eventbridge-condition-field' ) ) {
			updateOperators( row, false );
		} else if ( row && event.target.classList.contains( 'eventbridge-condition-operator' ) ) {
			syncValue( row );
		}
	} );

	list.addEventListener( 'click', function ( event ) {
		var card = event.target.closest( '.eventbridge-trigger-card' );
		var add = event.target.closest( '.eventbridge-add-condition' );
		var remove = event.target.closest( '.eventbridge-remove-condition' );
		var removeTrigger = event.target.closest( '.eventbridge-remove-trigger' );
		var rows;
		var template;
		var wrapper;
		var row;
		var next;
		if ( removeTrigger && card ) {
			card.querySelectorAll( '.eventbridge-condition-search' ).forEach( destroySearch );
			return;
		}
		if ( add && card && isWooCard( card ) ) {
			rows = card.querySelector( '.eventbridge-condition-rows' );
			template = card.querySelector( '.eventbridge-condition-template' );
			next = Number( rows.getAttribute( 'data-next-index' ) || 0 );
			wrapper = document.createElement( 'div' );
			wrapper.innerHTML = template.innerHTML.replace( /__CONDITION__/g, String( next ) );
			row = wrapper.querySelector( '.eventbridge-condition-row' );
			if ( row ) {
				rows.appendChild( row );
				rows.setAttribute( 'data-next-index', String( next + 1 ) );
				updateFieldAvailability( card, row );
				updateOperators( row, false );
			}
			return;
		}
		if ( remove && card ) {
			row = remove.closest( '.eventbridge-condition-row' );
			if ( row && row.getAttribute( 'data-woocommerce-locked' ) !== '1' ) {
				row.querySelectorAll( '.eventbridge-condition-search' ).forEach( destroySearch );
				row.remove();
			}
		}
	} );
}( window.jQuery, window.eventbridgeConditions ) );
