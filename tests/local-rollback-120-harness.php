<?php

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

$command = isset( $argv[1] ) ? strtolower( trim( (string) $argv[1] ) ) : '';
$old_plugin_path = isset( $argv[2] ) ? rtrim( (string) $argv[2], '/\\' ) : '';
if ( ! in_array( $command, array( 'prepare', 'run', 'verify', 'cleanup' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/local-rollback-120-harness.php prepare|run|verify|cleanup [path-to-1.2.0]\n" );
	exit( 1 );
}

if ( 'run' === $command ) {
	define( 'WP_INSTALLING', true );
}
require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: wp_get_environment_type() must be local.\n" );
	exit( 1 );
}
$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
if ( ! in_array( strtolower( (string) $home_host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	fwrite( STDERR, "Refusing to run: WordPress must use localhost or a loopback host.\n" );
	exit( 1 );
}

const EVENTBRIDGE_ROLLBACK_EVENT_KEY = 'evt_88888888-8888-4888-8888-888888888888';
const EVENTBRIDGE_ROLLBACK_MARKER    = 'EventBridge 1.3 rollback smoke fixture';
const EVENTBRIDGE_ROLLBACK_INTERACTION_KEY = 'evt_77777777-7777-4777-8777-777777777777';
const EVENTBRIDGE_ROLLBACK_INTERACTION_ID  = 'trg_77777777-7777-4777-8777-777777777777';

if ( 'prepare' === $command ) {
	$events = get_option( EventBridge_Events::OPTION_NAME, array() );
	if ( ! is_array( $events ) || isset( $events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ] ) || isset( $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] ) ) {
		fwrite( STDERR, "Refusing to overwrite an existing rollback fixture key.\n" );
		exit( 1 );
	}

	$first_id = 'trg_88888888-8888-4888-8888-888888888888';
	$triggers = array(
		array(
			'trigger_id'      => $first_id,
			'provider'        => 'frontend',
			'trigger_type'    => 'click',
			'provider_config' => array( 'selector' => '.compatibility-route' ),
			'data_source'     => array(),
			'parameters'      => array(),
			'advanced_matching' => array(),
			'conditions'        => array(),
		),
		array(
			'trigger_id'      => 'trg_99999999-9999-4999-8999-999999999999',
			'provider'        => 'frontend',
			'trigger_type'    => 'click',
			'provider_config' => array( 'selector' => '.secondary-route' ),
			'data_source'     => array(),
			'parameters'      => array(),
			'advanced_matching' => array(),
			'conditions'        => array(),
		),
	);
	$base = array(
		'label'        => EVENTBRIDGE_ROLLBACK_MARKER,
		'description'  => EVENTBRIDGE_ROLLBACK_MARKER,
		'event_name'   => 'RollbackSmoke',
		'enabled'      => true,
		'channels'     => array( 'browser' => true, 'capi' => false ),
		'meta_test_mode'       => false,
		'meta_test_event_code' => '',
		'remove_query_parameters' => true,
	);
	$events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ] = ( new EventBridge_Triggers() )->apply_compatibility_shadow( $base, $triggers, $first_id );
	$interaction_trigger = array(
		'trigger_id' => EVENTBRIDGE_ROLLBACK_INTERACTION_ID,
		'provider' => 'woocommerce', 'trigger_type' => 'checkout_started', 'provider_config' => array(),
		'data_source' => array(), 'parameters' => array(), 'advanced_matching' => array(), 'conditions' => array(),
	);
	$events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
		array_merge( $base, array( 'label' => 'Interaction rollback fixture', 'event_name' => 'InitiateCheckout' ) ),
		array( $interaction_trigger ),
		EVENTBRIDGE_ROLLBACK_INTERACTION_ID
	);
	if ( ! update_option( EventBridge_Events::OPTION_NAME, $events, false ) ) {
		fwrite( STDERR, "Unable to create the rollback smoke fixture.\n" );
		exit( 1 );
	}
	echo wp_json_encode( array( 'prepared' => true, 'event_key' => EVENTBRIDGE_ROLLBACK_EVENT_KEY ) ) . PHP_EOL;
	exit;
}

if ( 'cleanup' === $command ) {
	$events = get_option( EventBridge_Events::OPTION_NAME, array() );
	if ( isset( $events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ] )
		&& is_array( $events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ] )
		&& EVENTBRIDGE_ROLLBACK_MARKER === $events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ]['description']
	) {
		unset( $events[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ] );
	}
	if ( isset( $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] )
		&& is_array( $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] )
		&& 'Interaction rollback fixture' === $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['label']
	) {
		unset( $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] );
	}
	update_option( EventBridge_Events::OPTION_NAME, $events, false );
	echo wp_json_encode( array( 'cleaned' => ! isset( get_option( EventBridge_Events::OPTION_NAME, array() )[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ], get_option( EventBridge_Events::OPTION_NAME, array() )[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] ) ) ) . PHP_EOL;
	exit;
}

if ( 'verify' === $command ) {
	$events = ( new EventBridge_Events() )->get_normalized_events();
	$interaction = isset( $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] ) ? $events[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] : array();
	$ok = isset( $interaction['triggers'][0]['trigger_id'], $interaction['triggers'][0]['trigger_type'] )
		&& EVENTBRIDGE_ROLLBACK_INTERACTION_ID === $interaction['triggers'][0]['trigger_id']
		&& 'checkout_started' === $interaction['triggers'][0]['trigger_type']
		&& 'Changed and saved in EventBridge 1.2.0' === $interaction['description'];
	if ( ! $ok ) {
		fwrite( STDERR, "EventBridge interaction roll-forward verification failed.\n" );
		exit( 1 );
	}
	echo wp_json_encode( array( 'verified' => true, 'trigger_id' => EVENTBRIDGE_ROLLBACK_INTERACTION_ID ) ) . PHP_EOL;
	exit;
}

if ( '' === $old_plugin_path || ! is_file( $old_plugin_path . '/eventbridge.php' ) ) {
	fwrite( STDERR, "A readable EventBridge 1.2.0 snapshot is required.\n" );
	exit( 1 );
}
require $old_plugin_path . '/eventbridge.php';
if ( ! defined( 'EVENTBRIDGE_VERSION' ) || '1.2.0' !== EVENTBRIDGE_VERSION ) {
	fwrite( STDERR, "The supplied plugin snapshot is not EventBridge 1.2.0.\n" );
	exit( 1 );
}

global $eventbridge_plugin;
if ( ! is_object( $eventbridge_plugin ) ) {
	fwrite( STDERR, "EventBridge 1.2.0 did not bootstrap.\n" );
	exit( 1 );
}
$eventbridge_plugin->init();

$_SERVER['REQUEST_URI'] = '/hypnotherapielars/rollback-smoke';
$settings       = new EventBridge_Settings();
$log            = new EventBridge_Log();
$fluent         = new EventBridge_Fluent_Booking();
$capi           = new EventBridge_Meta_CAPI( $settings, $log );
$woocommerce    = new EventBridge_WooCommerce( $capi, $log );
$events_service = new EventBridge_Events( $woocommerce );
$woocommerce->set_events( $events_service );
$frontend       = new EventBridge_Frontend( $settings, $events_service, $capi, $fluent );
$method         = new ReflectionMethod( EventBridge_Frontend::class, 'get_frontend_events' );
$method->setAccessible( true );
$frontend_events = $method->invoke( $frontend );
$matching        = array_values(
	array_filter(
		$frontend_events,
		function ( $event ) {
			return isset( $event['id'] ) && EVENTBRIDGE_ROLLBACK_EVENT_KEY === $event['id'];
		}
	)
);
$stored = get_option( EventBridge_Events::OPTION_NAME, array() );
$interaction = isset( $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] ) ? $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ] : array();
if ( is_array( $interaction ) ) {
	$interaction['description'] = 'Changed and saved in EventBridge 1.2.0';
	$events_service->update_event( EVENTBRIDGE_ROLLBACK_INTERACTION_KEY, $interaction );
	$stored = get_option( EventBridge_Events::OPTION_NAME, array() );
}
$result = array(
	'version'             => EVENTBRIDGE_VERSION,
	'db_version'          => absint( get_option( EventBridge_Installer::DB_VERSION_OPTION, 0 ) ),
	'frontend_routes'     => count( $matching ),
	'compat_selector'     => 1 === count( $matching ) && isset( $matching[0]['selector'] ) ? $matching[0]['selector'] : '',
	'stored_trigger_count' => isset( $stored[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ]['triggers'] ) ? count( $stored[ EVENTBRIDGE_ROLLBACK_EVENT_KEY ]['triggers'] ) : 0,
	'interaction_projection' => isset( $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['trigger_type'] ) ? $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['trigger_type'] : '',
	'interaction_trigger_count' => isset( $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['triggers'] ) ? count( $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['triggers'] ) : 0,
	'interaction_description' => isset( $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['description'] ) ? $stored[ EVENTBRIDGE_ROLLBACK_INTERACTION_KEY ]['description'] : '',
);

if ( 2 !== $result['db_version']
	|| 1 !== $result['frontend_routes']
	|| '.compatibility-route' !== $result['compat_selector']
	|| 2 !== $result['stored_trigger_count']
	|| 'eventbridge_disabled' !== $result['interaction_projection']
	|| 1 !== $result['interaction_trigger_count']
	|| 'Changed and saved in EventBridge 1.2.0' !== $result['interaction_description']
) {
	fwrite( STDERR, "EventBridge 1.2.0 rollback smoke failed.\n" );
	fwrite( STDERR, wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
