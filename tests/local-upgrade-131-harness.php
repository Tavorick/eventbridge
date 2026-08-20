<?php

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

$command       = isset( $argv[1] ) ? strtolower( trim( (string) $argv[1] ) ) : '';
$baseline_path = isset( $argv[2] ) ? rtrim( (string) $argv[2], '/\\' ) : '';
$commands      = array( 'prepare', 'verify-upgrade', 'rollback', 'verify-rollforward', 'cleanup' );
if ( ! in_array( $command, $commands, true ) ) {
	fwrite( STDERR, "Usage: php tests/local-upgrade-131-harness.php prepare|verify-upgrade|rollback|verify-rollforward|cleanup [path-to-v1.3.1]\n" );
	exit( 1 );
}

if ( 'rollback' === $command ) {
	define( 'WP_INSTALLING', true );
}
require dirname( __DIR__, 4 ) . '/wp-load.php';

const EVENTBRIDGE_UPGRADE_131_STATE_OPTION = 'eventbridge_upgrade_131_harness_state';
const EVENTBRIDGE_UPGRADE_131_EVENT_KEY    = 'evt_13100000-0000-4000-8000-000000000001';
const EVENTBRIDGE_UPGRADE_131_TRIGGER_ID   = 'trg_13100000-0000-4000-8000-000000000001';
const EVENTBRIDGE_UPGRADE_131_SECONDARY_ID = 'trg_13100000-0000-4000-8000-000000000002';
const EVENTBRIDGE_UPGRADE_131_DESCRIPTION  = 'Created in EventBridge 1.3.1';
const EVENTBRIDGE_UPGRADE_131_ROLLBACK     = 'Changed during EventBridge 1.3.1 rollback';
const EVENTBRIDGE_UPGRADE_131_PIXEL_ID     = '131000000000001';

function eventbridge_upgrade_131_fail( $message ) {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

function eventbridge_upgrade_131_assert_safe_site() {
	if ( ! function_exists( 'wp_get_environment_type' ) || 'local' !== wp_get_environment_type() ) {
		eventbridge_upgrade_131_fail( 'Refusing to run: wp_get_environment_type() must be local.' );
	}
	$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
	if ( ! in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
		eventbridge_upgrade_131_fail( 'Refusing to run: WordPress must use a loopback host.' );
	}
	$root = wp_normalize_path( (string) realpath( ABSPATH ) );
	$temp = trailingslashit( wp_normalize_path( (string) realpath( sys_get_temp_dir() ) ) );
	if ( '' === $root || '' === $temp || 0 !== strpos( trailingslashit( $root ), $temp ) ) {
		eventbridge_upgrade_131_fail( 'Refusing to run outside a disposable temporary WordPress site.' );
	}
}

function eventbridge_upgrade_131_state() {
	$state = get_option( EVENTBRIDGE_UPGRADE_131_STATE_OPTION, false );
	if ( ! is_array( $state ) ) {
		eventbridge_upgrade_131_fail( 'The EventBridge 1.3.1 upgrade fixture state is missing.' );
	}
	return $state;
}

function eventbridge_upgrade_131_assert_fixture( $expected_description ) {
	$state    = eventbridge_upgrade_131_state();
	$settings = get_option( 'eventbridge_meta_settings', array() );
	$events   = get_option( EventBridge_Events::OPTION_NAME, array() );
	$event    = isset( $events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ] ) && is_array( $events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ] )
		? $events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ]
		: array();
	if ( empty( $state['prepared'] )
		|| ! is_array( $settings )
		|| EVENTBRIDGE_UPGRADE_131_PIXEL_ID !== ( isset( $settings['pixel_id'] ) ? (string) $settings['pixel_id'] : '' )
		|| $expected_description !== ( isset( $event['description'] ) ? $event['description'] : '' )
		|| empty( $event['triggers'] )
		|| 2 !== count( $event['triggers'] )
		|| ! isset( $event['triggers'][0]['trigger_id'], $event['triggers'][1]['trigger_id'] )
		|| EVENTBRIDGE_UPGRADE_131_TRIGGER_ID !== $event['triggers'][0]['trigger_id']
		|| EVENTBRIDGE_UPGRADE_131_SECONDARY_ID !== $event['triggers'][1]['trigger_id']
	) {
		eventbridge_upgrade_131_fail( 'The EventBridge 1.3.1 upgrade fixture was not preserved.' );
	}
}

function eventbridge_upgrade_131_assert_current( $expected_description ) {
	if ( ! defined( 'EVENTBRIDGE_VERSION' ) || '2.0.0' !== EVENTBRIDGE_VERSION ) {
		eventbridge_upgrade_131_fail( 'The active EventBridge version is not 2.0.0.' );
	}
	if ( 6 !== absint( get_option( EventBridge_Installer::DB_VERSION_OPTION, 0 ) ) ) {
		eventbridge_upgrade_131_fail( 'The EventBridge database was not upgraded to version 6.' );
	}
	if ( ! in_array( 'eventbridge/eventbridge.php', (array) get_option( 'active_plugins', array() ), true ) ) {
		eventbridge_upgrade_131_fail( 'EventBridge is not active after the upgrade.' );
	}
	eventbridge_upgrade_131_assert_fixture( $expected_description );
	if ( ! ( new EventBridge_Log() )->verify_table_schema()
		|| ! ( new EventBridge_Profile_Repository() )->verify_tables()
		|| ! ( new EventBridge_Profile_Context_Repository() )->verify_table()
		|| ! ( new EventBridge_Conversion_Repository() )->verify_table()
		|| 'daily' !== wp_get_schedule( EventBridge_Profile_Cleanup::CLEANUP_HOOK )
	) {
		eventbridge_upgrade_131_fail( 'The EventBridge 2.0.0 database infrastructure is incomplete.' );
	}
}

eventbridge_upgrade_131_assert_safe_site();

if ( 'prepare' === $command ) {
	if ( ! defined( 'EVENTBRIDGE_VERSION' ) || '1.3.1' !== EVENTBRIDGE_VERSION ) {
		eventbridge_upgrade_131_fail( 'The preparation baseline is not EventBridge 1.3.1.' );
	}
	if ( 2 !== absint( get_option( EventBridge_Installer::DB_VERSION_OPTION, 0 ) ) ) {
		eventbridge_upgrade_131_fail( 'The EventBridge 1.3.1 baseline does not use database version 2.' );
	}
	if ( false !== get_option( EVENTBRIDGE_UPGRADE_131_STATE_OPTION, false ) ) {
		eventbridge_upgrade_131_fail( 'Refusing to overwrite an existing EventBridge 1.3.1 upgrade fixture.' );
	}
	$settings_missing = new stdClass();
	$events_missing   = new stdClass();
	$original_settings = get_option( 'eventbridge_meta_settings', $settings_missing );
	$original_events   = get_option( EventBridge_Events::OPTION_NAME, $events_missing );
	$settings          = is_array( $original_settings ) ? $original_settings : array();
	$events            = is_array( $original_events ) ? $original_events : array();
	if ( isset( $events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ] ) ) {
		eventbridge_upgrade_131_fail( 'Refusing to overwrite the EventBridge 1.3.1 upgrade event fixture.' );
	}
	$state = array(
		'prepared'        => true,
		'settings_exists' => $settings_missing !== $original_settings,
		'events_exists'   => $events_missing !== $original_events,
		'settings'        => is_array( $original_settings ) ? $original_settings : array(),
		'events'          => is_array( $original_events ) ? $original_events : array(),
	);
	if ( ! add_option( EVENTBRIDGE_UPGRADE_131_STATE_OPTION, $state, '', false ) ) {
		eventbridge_upgrade_131_fail( 'Unable to store the EventBridge 1.3.1 upgrade fixture state.' );
	}
	$settings['pixel_id'] = EVENTBRIDGE_UPGRADE_131_PIXEL_ID;
	$settings['debug']    = false;
	$triggers = array(
		array(
			'trigger_id' => EVENTBRIDGE_UPGRADE_131_TRIGGER_ID, 'provider' => 'frontend', 'trigger_type' => 'click',
			'provider_config' => array( 'selector' => '.upgrade-131-primary' ), 'data_source' => array(),
			'parameters' => array(), 'advanced_matching' => array(), 'conditions' => array(),
		),
		array(
			'trigger_id' => EVENTBRIDGE_UPGRADE_131_SECONDARY_ID, 'provider' => 'frontend', 'trigger_type' => 'click',
			'provider_config' => array( 'selector' => '.upgrade-131-secondary' ), 'data_source' => array(),
			'parameters' => array(), 'advanced_matching' => array(), 'conditions' => array(),
		),
	);
	$event = array(
		'label' => 'EventBridge 1.3.1 upgrade fixture', 'description' => EVENTBRIDGE_UPGRADE_131_DESCRIPTION,
		'event_name' => 'Lead', 'enabled' => true, 'channels' => array( 'browser' => true, 'capi' => false ),
		'meta_test_mode' => false, 'meta_test_event_code' => '', 'remove_query_parameters' => true,
	);
	$events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ] = ( new EventBridge_Triggers() )->apply_compatibility_shadow( $event, $triggers, EVENTBRIDGE_UPGRADE_131_TRIGGER_ID );
	update_option( 'eventbridge_meta_settings', $settings, false );
	update_option( EventBridge_Events::OPTION_NAME, $events, false );
	EventBridge_Upgrader::store_event_schema_state( $events );
	eventbridge_upgrade_131_assert_fixture( EVENTBRIDGE_UPGRADE_131_DESCRIPTION );
	echo wp_json_encode( array( 'prepared' => true, 'version' => EVENTBRIDGE_VERSION, 'db_version' => 2 ) ) . PHP_EOL;
	exit;
}

if ( 'verify-upgrade' === $command ) {
	eventbridge_upgrade_131_assert_current( EVENTBRIDGE_UPGRADE_131_DESCRIPTION );
	echo wp_json_encode( array( 'upgraded' => true, 'version' => EVENTBRIDGE_VERSION, 'db_version' => 6 ) ) . PHP_EOL;
	exit;
}

if ( 'rollback' === $command ) {
	$resolved_baseline = (string) realpath( $baseline_path );
	$temp              = trailingslashit( wp_normalize_path( (string) realpath( sys_get_temp_dir() ) ) );
	$resolved_baseline = wp_normalize_path( $resolved_baseline );
	if ( '' === $resolved_baseline || 0 !== strpos( trailingslashit( $resolved_baseline ), $temp ) || ! is_file( $resolved_baseline . '/eventbridge.php' ) ) {
		eventbridge_upgrade_131_fail( 'A readable EventBridge v1.3.1 snapshot inside the temporary directory is required.' );
	}
	require $resolved_baseline . '/eventbridge.php';
	if ( ! defined( 'EVENTBRIDGE_VERSION' ) || '1.3.1' !== EVENTBRIDGE_VERSION ) {
		eventbridge_upgrade_131_fail( 'The rollback snapshot is not EventBridge 1.3.1.' );
	}
	global $eventbridge_plugin;
	if ( ! is_object( $eventbridge_plugin ) ) {
		eventbridge_upgrade_131_fail( 'EventBridge 1.3.1 did not bootstrap for rollback verification.' );
	}
	$eventbridge_plugin->init();
	if ( 6 !== absint( get_option( EventBridge_Installer::DB_VERSION_OPTION, 0 ) ) ) {
		eventbridge_upgrade_131_fail( 'EventBridge 1.3.1 changed the migrated database version.' );
	}
	eventbridge_upgrade_131_assert_fixture( EVENTBRIDGE_UPGRADE_131_DESCRIPTION );
	$events = get_option( EventBridge_Events::OPTION_NAME, array() );
	$event  = $events[ EVENTBRIDGE_UPGRADE_131_EVENT_KEY ];
	$event['description'] = EVENTBRIDGE_UPGRADE_131_ROLLBACK;
	if ( 'updated' !== ( new EventBridge_Events() )->update_event( EVENTBRIDGE_UPGRADE_131_EVENT_KEY, $event ) ) {
		eventbridge_upgrade_131_fail( 'EventBridge 1.3.1 could not update the migrated event.' );
	}
	eventbridge_upgrade_131_assert_fixture( EVENTBRIDGE_UPGRADE_131_ROLLBACK );
	echo wp_json_encode( array( 'rollback_verified' => true, 'version' => EVENTBRIDGE_VERSION, 'db_version' => 6 ) ) . PHP_EOL;
	exit;
}

if ( 'verify-rollforward' === $command ) {
	eventbridge_upgrade_131_assert_current( EVENTBRIDGE_UPGRADE_131_ROLLBACK );
	echo wp_json_encode( array( 'rollforward_verified' => true, 'version' => EVENTBRIDGE_VERSION, 'db_version' => 6 ) ) . PHP_EOL;
	exit;
}

$state = eventbridge_upgrade_131_state();
if ( ! empty( $state['settings_exists'] ) ) {
	update_option( 'eventbridge_meta_settings', $state['settings'], false );
} else {
	delete_option( 'eventbridge_meta_settings' );
}
if ( ! empty( $state['events_exists'] ) ) {
	update_option( EventBridge_Events::OPTION_NAME, $state['events'], false );
} else {
	delete_option( EventBridge_Events::OPTION_NAME );
}
if ( class_exists( 'EventBridge_Upgrader' ) ) {
	EventBridge_Upgrader::store_event_schema_state( ! empty( $state['events_exists'] ) ? $state['events'] : array() );
}
delete_option( EVENTBRIDGE_UPGRADE_131_STATE_OPTION );
echo wp_json_encode( array( 'cleaned' => false === get_option( EVENTBRIDGE_UPGRADE_131_STATE_OPTION, false ) ) ) . PHP_EOL;
