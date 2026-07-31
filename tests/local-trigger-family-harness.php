<?php

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

define( 'WP_ADMIN', true );
require dirname( __DIR__, 4 ) . '/wp-load.php';

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: wp_get_environment_type() must be local.\n" );
	exit( 1 );
}

$settings   = new EventBridge_Settings();
$log        = new EventBridge_Log();
$conditions = new EventBridge_Conditions( array( new EventBridge_WooCommerce_Conditions() ), $settings, $log );
$capi       = new EventBridge_Meta_CAPI( $settings, $log );
$woocommerce = new EventBridge_WooCommerce( $capi, $log, $conditions );
$events      = new EventBridge_Events( $woocommerce, $conditions );
$woocommerce->set_events( $events );

$frontend = static function ( $type, $value ) {
	return array(
		'trigger_id'      => '',
		'provider'        => 'frontend',
		'trigger_type'    => $type,
		'provider_config' => 'click' === $type
			? array( 'selector' => $value )
			: array( 'url_match_type' => 'path_exact', 'url_match_value' => $value ),
		'parameters'        => array(),
		'conditions'        => array(),
		'data_source'       => array(),
		'advanced_matching' => array(),
	);
};

$server = static function ( $event ) {
	return array(
		'trigger_id'      => '',
		'provider'        => 'woocommerce',
		'trigger_type'    => 'order_lifecycle',
		'provider_config' => array(
			'event'           => $event,
			'status'          => 'status' === $event ? 'completed' : '',
			'purchase_preset' => false,
		),
		'parameters'        => array(),
		'conditions'        => array(),
		'data_source'       => array(),
		'advanced_matching' => array(),
	);
};

$interaction = static function ( $type ) {
	return array(
		'trigger_id' => '', 'provider' => 'woocommerce', 'trigger_type' => $type,
		'provider_config' => array(), 'parameters' => array(), 'conditions' => array(),
		'data_source' => array(), 'advanced_matching' => array(),
	);
};

$validate = static function ( $triggers, $channels ) use ( $events ) {
	return $events->validate_event(
		array(
			'label'      => 'Trigger family harness',
			'event_name' => 'FamilyHarness',
			'enabled'    => '1',
			'channels'   => $channels,
			'triggers'   => $triggers,
		)
	);
};

$results = array();
$details = array();
foreach ( array(
	'click_click'       => array( $frontend( 'click', '.one' ), $frontend( 'click', '.two' ) ),
	'click_pageview'    => array( $frontend( 'click', '.one' ), $frontend( 'pageview', '/two' ) ),
	'pageview_pageview' => array( $frontend( 'pageview', '/one' ), $frontend( 'pageview', '/two' ) ),
	'click_product_viewed' => array( $frontend( 'click', '.one' ), $interaction( 'product_viewed' ) ),
	'woo_interactions' => array( $interaction( 'added_to_cart' ), $interaction( 'checkout_started' ) ),
) as $name => $triggers ) {
	$validation = $validate( $triggers, array( 'browser' => '1', 'capi' => '1' ) );
	$results[ $name ] = empty( $validation['errors'] )
		&& EventBridge_Triggers::FAMILY_FRONTEND === $events->get_event_family( $validation['event'] );
}

foreach ( array(
	'created_paid' => array( $server( 'created' ), $server( 'paid' ) ),
	'paid_status'  => array( $server( 'paid' ), $server( 'status' ) ),
) as $name => $triggers ) {
	$validation = $validate( $triggers, array( 'capi' => '1' ) );
	$results[ $name ] = empty( $validation['errors'] )
		&& EventBridge_Triggers::FAMILY_SERVER === $events->get_event_family( $validation['event'] )
		&& array( 'browser' => false, 'capi' => true ) === $validation['event']['channels'];
}

foreach ( array(
	'browser' => array( 'browser' => '1' ),
	'capi'    => array( 'capi' => '1' ),
	'both'    => array( 'browser' => '1', 'capi' => '1' ),
) as $name => $channels ) {
	$validation = $validate( array( $frontend( 'click', '.channel' ) ), $channels );
	$results[ 'frontend_' . $name ] = empty( $validation['errors'] );
	$details[ 'frontend_' . $name ] = $validation['errors'];
}

$mixed = $validate( array( $frontend( 'click', '.mixed' ), $server( 'paid' ) ), array( 'capi' => '1' ) );
$results['mixed_rejected'] = ! empty( $mixed['errors'] ) && false === $mixed['event']['enabled'];

$advanced = $frontend( 'click', '.advanced' );
$advanced['advanced_matching'] = array( 'email' => array( 'source' => 'static', 'value' => 'test@example.com' ) );
$advanced_validation = $validate( array( $advanced ), array( 'browser' => '1' ) );
$results['advanced_requires_capi'] = ! empty( $advanced_validation['errors'] );

$legacy_frontend = $frontend( 'click', '.stored' );
$legacy_frontend['trigger_id'] = 'trg_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$legacy_frontend['channels']   = array( 'browser' => true, 'capi' => false );
$legacy_server = $server( 'paid' );
$legacy_server['trigger_id'] = 'trg_bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$legacy_server['channels']   = array( 'browser' => false, 'capi' => true );
$stored_raw = array(
		'label' => 'Stored mixed', 'event_name' => 'StoredMixed', 'enabled' => true,
		'trigger_type' => 'click', 'selector' => '.stored', 'browser' => true, 'capi' => false,
		'triggers' => array( $legacy_frontend, $legacy_server ),
		'eventbridge_schema_version' => 2,
		'eventbridge_compat' => array( 'legacy_trigger_id' => $legacy_frontend['trigger_id'], 'legacy_projection_hash' => '' ),
);
$stored_raw['eventbridge_compat']['legacy_projection_hash'] = ( new EventBridge_Triggers() )->get_projection_hash( $stored_raw );
$stored = $events->normalize_event(
	$stored_raw,
	'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'
);
$results['stored_mixed_fail_closed'] = false === $stored['enabled']
	&& 2 === count( $stored['triggers'] )
	&& isset( $stored[ EventBridge_Triggers::FAMILY_CONFLICT_KEY ] )
	&& ! isset( $stored['triggers'][0]['channels'], $stored['triggers'][1]['channels'] );

$legacy_120 = $events->normalize_event(
	array(
		'label' => 'Legacy 1.2', 'event_name' => 'LegacyLead', 'enabled' => true,
		'trigger_type' => 'click', 'selector' => '.legacy', 'browser' => true, 'capi' => false,
	),
	'evt_cccccccc-cccc-4ccc-8ccc-cccccccccccc'
);
$results['legacy_120_channels'] = array( 'browser' => true, 'capi' => false ) === $legacy_120['channels']
	&& 1 === count( $legacy_120['triggers'] )
	&& ! isset( $legacy_120['triggers'][0]['channels'] );

$compatible_one = $frontend( 'click', '.compatible-one' );
$compatible_one['trigger_id'] = 'trg_cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$compatible_one['channels'] = array( 'browser' => true, 'capi' => true );
$compatible_two = $frontend( 'pageview', '/compatible-two' );
$compatible_two['trigger_id'] = 'trg_dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$compatible_two['channels'] = array( 'browser' => true, 'capi' => false );
$compatible_raw = array(
	'label' => 'Compatible stored', 'event_name' => 'CompatibleStored', 'enabled' => true,
	'trigger_type' => 'click', 'selector' => '.compatible-one', 'browser' => true, 'capi' => true,
	'triggers' => array( $compatible_one, $compatible_two ),
	'eventbridge_schema_version' => 2,
	'eventbridge_compat' => array( 'legacy_trigger_id' => $compatible_one['trigger_id'], 'legacy_projection_hash' => '' ),
);
$compatible_raw['eventbridge_compat']['legacy_projection_hash'] = ( new EventBridge_Triggers() )->get_projection_hash( $compatible_raw );
$compatible = $events->normalize_event( $compatible_raw, 'evt_dddddddd-dddd-4ddd-8ddd-dddddddddddd' );
$results['compatible_channel_intersection'] = true === $compatible['enabled']
	&& array( 'browser' => true, 'capi' => false ) === $compatible['channels']
	&& ! isset( $compatible['triggers'][0]['channels'], $compatible['triggers'][1]['channels'] );

$results['interaction_round_trip'] = true;
foreach ( array(
	'product_viewed' => array( 'trg_11111111-1111-4111-8111-111111111111', 'min_price', 'virtual_product', 'any', true ),
	'added_to_cart' => array( 'trg_22222222-2222-4222-8222-222222222222', 'quantity', 'action_quantity', 'gte', 2 ),
	'checkout_started' => array( 'trg_33333333-3333-4333-8333-333333333333', 'cart_total', 'cart_total', 'gte', '10' ),
) as $type => $case ) {
	$event_key = 'evt_' . substr( $case[0], 4 );
	$raw = array(
		'label' => 'Round trip ' . $type, 'event_name' => 'WooInteraction', 'enabled' => true,
		'channels' => array( 'browser' => true, 'capi' => true ),
		'triggers' => array(
			array(
				'trigger_id' => $case[0], 'provider' => 'woocommerce', 'trigger_type' => $type, 'provider_config' => array(),
				'parameters' => array( array( 'name' => 'context_value', 'source' => 'woocommerce_interaction', 'value' => $case[1] ) ),
				'conditions' => array( array( 'provider' => 'woocommerce', 'field' => $case[2], 'operator' => $case[3], 'value' => $case[4] ) ),
				'data_source' => array(),
				'advanced_matching' => array( 'email' => array( 'source' => 'static', 'value' => 'person@example.com' ) ),
			),
		),
	);
	$existing = $events->normalize_event( $raw, $event_key );
	$before = $existing['triggers'][0];
	$validation = $events->validate_event(
		array(
			'label' => $existing['label'], 'event_name' => $existing['event_name'], 'enabled' => '1',
			'channels' => array( 'browser' => '1', 'capi' => '1' ), 'triggers' => $existing['triggers'],
		),
		$existing,
		true,
		$event_key
	);
	$details['interaction_round_trip_' . $type] = array(
		'errors' => $validation['errors'],
		'same_trigger' => $before === $validation['event']['triggers'][0],
		'family' => $events->get_event_family( $validation['event'] ),
	);
	$results['interaction_round_trip'] = $results['interaction_round_trip']
		&& empty( $validation['errors'] )
		&& $before === $validation['event']['triggers'][0]
		&& EventBridge_Triggers::FAMILY_FRONTEND === $events->get_event_family( $validation['event'] );
}

if ( in_array( false, $results, true ) ) {
	fwrite( STDERR, "EventBridge trigger family harness failed.\n" );
	fwrite( STDERR, wp_json_encode( $results, JSON_PRETTY_PRINT ) . PHP_EOL );
	fwrite( STDERR, wp_json_encode( $details, JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
