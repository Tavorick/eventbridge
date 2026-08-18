<?php

$eventbridge_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $eventbridge_tests_dir ) {
	$eventbridge_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $eventbridge_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test suite not found. Set WP_TESTS_DIR.\n" );
	exit( 1 );
}

$eventbridge_tests_config = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
$eventbridge_tests_config = is_string( $eventbridge_tests_config ) && '' !== $eventbridge_tests_config ? $eventbridge_tests_config : $eventbridge_tests_dir . '/wp-tests-config.php';
$eventbridge_config_body  = is_readable( $eventbridge_tests_config ) ? file_get_contents( $eventbridge_tests_config ) : false;
$eventbridge_test_db_name = is_string( $eventbridge_config_body ) && preg_match( "/define\\(\\s*['\"]DB_NAME['\"]\\s*,\\s*['\"]([^'\"]+)['\"]\\s*\\)/", $eventbridge_config_body, $eventbridge_db_match ) ? $eventbridge_db_match[1] : '';
if ( '' === $eventbridge_test_db_name || false === strpos( strtolower( $eventbridge_test_db_name ), 'test' ) ) {
	fwrite( STDERR, "Refusing to run: the WordPress PHPUnit database name must contain 'test'.\n" );
	exit( 1 );
}

$eventbridge_polyfills = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills';
if ( ! file_exists( $eventbridge_polyfills . '/phpunitpolyfills-autoload.php' ) ) {
	fwrite( STDERR, "Yoast PHPUnit Polyfills not found. Run composer install.\n" );
	exit( 1 );
}

$eventbridge_fluent_dir = getenv( 'FLUENT_BOOKING_PLUGIN_DIR' );
$eventbridge_fluent_dir = is_string( $eventbridge_fluent_dir ) ? rtrim( $eventbridge_fluent_dir, '/\\' ) : '';
if ( '' === $eventbridge_fluent_dir || ! file_exists( $eventbridge_fluent_dir . '/fluent-booking.php' ) ) {
	fwrite( STDERR, "Fluent Booking not found. Set FLUENT_BOOKING_PLUGIN_DIR to the plugin directory.\n" );
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $eventbridge_polyfills );
}

require_once $eventbridge_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $eventbridge_fluent_dir ) {
		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'external_http_blocked', 'External HTTP is disabled in the Fluent Booking integration suite.' );
			},
			PHP_INT_MIN,
			3
		);
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MIN );
		require $eventbridge_fluent_dir . '/fluent-booking.php';
		require dirname( __DIR__ ) . '/eventbridge.php';
	}
);

require $eventbridge_tests_dir . '/includes/bootstrap.php';
