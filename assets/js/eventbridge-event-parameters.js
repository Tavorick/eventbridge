( function () {
	'use strict';

	var container = document.getElementById( 'eventbridge-event-parameters' );
	var addButton = document.getElementById( 'eventbridge-add-parameter' );
	var template = document.getElementById( 'eventbridge-parameter-template' );
	var nextIndex;

	if ( ! container || ! addButton || ! template ) {
		return;
	}

	nextIndex = container.querySelectorAll( '.eventbridge-parameter-row' ).length;

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
