( function () {
	'use strict';

	var root = document.querySelector( '[data-eventbridge-fluent-followups]' );
	if ( ! root ) {
		return;
	}

	var search = root.querySelector( '#eventbridge-fluent-followup-search' );
	var picker = root.querySelector( '#eventbridge-fluent-followup-picker' );
	var add = root.querySelector( '[data-eventbridge-add-followup]' );
	var list = root.querySelector( '[data-eventbridge-followup-list]' );
	var empty = root.querySelector( '[data-eventbridge-followup-empty]' );
	var template = document.getElementById( 'eventbridge-fluent-followup-template' );

	function updateEmptyState() {
		empty.hidden = list.querySelectorAll( '[data-eventbridge-followup-card]' ).length > 0;
	}

	function replacePlaceholders( value, eventId, title, calendarName ) {
		return value
			.split( '__EVENT_ID__' ).join( eventId )
			.split( '__EVENT_TITLE__' ).join( title )
			.split( '__CALENDAR_NAME__' ).join( calendarName );
	}

	function populateCard( card, eventId, title, calendarName ) {
		var walker = document.createTreeWalker( card, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT );
		var node = walker.currentNode;
		while ( node ) {
			if ( node.nodeType === Node.ELEMENT_NODE ) {
				Array.prototype.forEach.call( node.attributes, function ( attribute ) {
					if ( attribute.value.indexOf( '__' ) !== -1 ) {
						node.setAttribute( attribute.name, replacePlaceholders( attribute.value, eventId, title, calendarName ) );
					}
				} );
			} else if ( node.nodeValue.indexOf( '__' ) !== -1 ) {
				node.nodeValue = replacePlaceholders( node.nodeValue, eventId, title, calendarName );
			}
			node = walker.nextNode();
		}
	}

	function filterOptions() {
		var term = search.value.toLowerCase().trim();
		Array.prototype.forEach.call( picker.options, function ( option, index ) {
			if ( index === 0 ) {
				return;
			}
			option.hidden = term !== '' && option.text.toLowerCase().indexOf( term ) === -1;
		} );
	}

	function addFollowup() {
		var option = picker.options[ picker.selectedIndex ];
		var eventId;
		var title;
		var calendarName;
		var wrapper;
		var card;
		if ( ! option || option.value === '' || option.disabled || ! template ) {
			return;
		}
		eventId = option.value;
		title = option.getAttribute( 'data-title' ) || option.text;
		calendarName = option.getAttribute( 'data-calendar-name' ) || '';
		wrapper = document.createElement( 'div' );
		wrapper.innerHTML = template.innerHTML.trim();
		card = wrapper.firstElementChild;
		populateCard( card, eventId, title, calendarName );
		list.appendChild( card );
		option.disabled = true;
		option.hidden = true;
		picker.value = '';
		updateEmptyState();
	}

	search.addEventListener( 'input', filterOptions );
	add.addEventListener( 'click', addFollowup );
	picker.addEventListener( 'keydown', function ( event ) {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			addFollowup();
		}
	} );
	list.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '[data-eventbridge-remove-followup]' );
		var card;
		var option;
		if ( ! button ) {
			return;
		}
		card = button.closest( '[data-eventbridge-followup-card]' );
		option = null;
		if ( card ) {
			Array.prototype.some.call( picker.options, function ( candidate ) {
				if ( candidate.value === card.getAttribute( 'data-event-id' ) ) {
					option = candidate;
					return true;
				}
				return false;
			} );
		}
		if ( option ) {
			option.disabled = false;
			option.hidden = false;
		}
		if ( card ) {
			card.remove();
		}
		updateEmptyState();
	} );
	updateEmptyState();
}() );
