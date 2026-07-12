( function () {
	'use strict';

	if ( ! window.EventBridge ) {
		return;
	}

	if ( window.EventBridge.debug === true ) {
		console.info( '[EventBridge]', window.EventBridge );
	}
}() );
