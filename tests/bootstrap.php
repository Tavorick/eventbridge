<?php

$eventbridge_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $eventbridge_tests_dir ) {
	$eventbridge_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $eventbridge_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

require_once $eventbridge_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/eventbridge.php';
	}
);

require $eventbridge_tests_dir . '/includes/bootstrap.php';
