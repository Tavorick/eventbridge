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
if ( ! function_exists( 'submit_button' ) ) {
	require_once ABSPATH . 'wp-admin/includes/template.php';
}

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: wp_get_environment_type() must be local.\n" );
	exit( 1 );
}

$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
if ( ! in_array( strtolower( (string) $home_host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	fwrite( STDERR, "Refusing to run: WordPress must use localhost or a loopback host.\n" );
	exit( 1 );
}

$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( empty( $administrators ) ) {
	fwrite( STDERR, "Refusing to run: no local administrator is available.\n" );
	exit( 1 );
}
wp_set_current_user( absint( $administrators[0] ) );

$settings           = new EventBridge_Settings();
$log                = new EventBridge_Log();
$status             = new EventBridge_Upgrade_Status();
$fluent             = new EventBridge_Fluent_Booking();
$condition_provider = new EventBridge_WooCommerce_Conditions();
$conditions         = new EventBridge_Conditions( array( $condition_provider ), $settings, $log );
$capi               = new EventBridge_Meta_CAPI( $settings, $log );
$woocommerce        = new EventBridge_WooCommerce( $capi, $log, $conditions );
$events             = new EventBridge_Events( $woocommerce, $conditions );
$woocommerce->set_events( $events );
$admin              = new EventBridge_Admin( $settings, $events, $log, $fluent, $status, $woocommerce, $conditions );
$values             = $events->get_form_defaults();
$values['label']     = 'EventBridge admin multitrigger test';
$values['event_name'] = 'BookingComplete';
$values['enabled']   = true;
$values['triggers']  = array(
	array(
		'trigger_id'      => 'trg_11111111-1111-4111-8111-111111111111',
		'provider'        => 'frontend',
		'trigger_type'    => 'click',
		'provider_config' => array( 'selector' => '.booking-a' ),
		'channels'        => array( 'browser' => true, 'capi' => true ),
		'data_source'     => array(),
		'parameters'      => array( array( 'name' => 'route', 'source' => 'static', 'value' => 'A' ) ),
		'advanced_matching' => array(),
		'conditions'        => array(),
	),
	array(
		'trigger_id'      => 'trg_22222222-2222-4222-8222-222222222222',
		'provider'        => 'frontend',
		'trigger_type'    => 'pageview',
		'provider_config' => array( 'url_match_type' => 'path_exact', 'url_match_value' => '/thanks' ),
		'channels'        => array( 'browser' => true, 'capi' => false ),
		'data_source'     => array(),
		'parameters'      => array(),
		'advanced_matching' => array(),
		'conditions'        => array(),
	),
	array(
		'trigger_id'      => 'trg_33333333-3333-4333-8333-333333333333',
		'provider'        => 'woocommerce',
		'trigger_type'    => 'order_lifecycle',
		'provider_config' => array( 'event' => 'paid', 'status' => '', 'purchase_preset' => true ),
		'channels'        => array( 'browser' => false, 'capi' => true ),
		'data_source'     => array(),
		'parameters'      => array( array( 'name' => 'total', 'source' => 'woocommerce_order', 'value' => 'total' ) ),
		'advanced_matching' => array(),
		'conditions'        => array(
			array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'gte', 'value' => '10.00' ),
		),
	),
);
$values = $events->normalize_event( $values, 'evt_99999999-9999-4999-8999-999999999999' );

$values_property = new ReflectionProperty( EventBridge_Admin::class, 'event_form_values' );
$values_property->setAccessible( true );
$values_property->setValue( $admin, $values );
$render = new ReflectionMethod( EventBridge_Admin::class, 'render_event_form' );
$render->setAccessible( true );

ob_start();
$render->invoke( $admin );
$html = ob_get_clean();

preg_match_all( '/\sid="([^"]+)"/', $html, $id_matches );
$ids        = isset( $id_matches[1] ) ? $id_matches[1] : array();
$duplicates = array_diff_assoc( $ids, array_unique( $ids ) );
$result     = array(
	'trigger_cards'    => substr_count( $html, '<article class="eventbridge-trigger-card' ) - 1,
	'or_separators'    => substr_count( $html, 'class="eventbridge-trigger-or"' ),
	'has_add_button'   => false !== strpos( $html, 'id="eventbridge-add-trigger"' ),
	'has_trigger_zero' => false !== strpos( $html, 'eventbridge_event[triggers][0][trigger_id]' ),
	'has_trigger_one'  => false !== strpos( $html, 'eventbridge_event[triggers][1][trigger_id]' ),
	'has_trigger_two'  => false !== strpos( $html, 'eventbridge_event[triggers][2][trigger_id]' ),
	'event_channels'   => substr_count( $html, 'id="eventbridge-event-channels"' ),
	'trigger_channels' => substr_count( $html, '[triggers][0][channels]' ) + substr_count( $html, '[triggers][1][channels]' ) + substr_count( $html, '[triggers][2][channels]' ),
	'family_conflict'  => false !== strpos( $html, 'id="eventbridge-family-conflict"' ) && false === strpos( $html, 'id="eventbridge-family-conflict" class="eventbridge-inline-notice is-error" role="alert" hidden' ),
	'family_options'   => substr_count( $html, 'data-family="frontend_interaction"' ) >= 2 && false !== strpos( $html, 'data-family="server_lifecycle"' ),
	'trigger_toggles'  => substr_count( $html, 'class="eventbridge-trigger-toggle"' ),
	'family_labels'    => false !== strpos( $html, 'Frontendtriggers' ) && false !== strpos( $html, 'Backendtriggers' ) && false !== strpos( $html, '>WooCommerce<' ),
	'interaction_labels' => false !== strpos( $html, 'WooCommerce: product bekeken' )
		&& false !== strpos( $html, 'WooCommerce: toegevoegd aan winkelmand' )
		&& false !== strpos( $html, 'WooCommerce: checkout gestart' ),
	'backend_choices' => false !== strpos( $html, 'Bestelling aangemaakt' )
		&& false !== strpos( $html, 'Betaling voltooid' )
		&& false !== strpos( $html, 'Bestelling krijgt gekozen status' ),
	'legacy_visible_label' => (bool) preg_match( '/>[^<]*(?:order lifecycle|server lifecycle)[^<]*</i', $html ),
	'duplicate_ids'    => array_values( array_unique( $duplicates ) ),
);

if ( 3 !== $result['trigger_cards']
	|| 2 !== $result['or_separators']
	|| ! $result['has_add_button']
	|| ! $result['has_trigger_zero']
	|| ! $result['has_trigger_one']
	|| ! $result['has_trigger_two']
	|| 1 !== $result['event_channels']
	|| 0 !== $result['trigger_channels']
	|| ! $result['family_conflict']
	|| ! $result['family_options']
	|| 4 !== $result['trigger_toggles']
	|| ! $result['family_labels']
	|| ! $result['interaction_labels']
	|| ! $result['backend_choices']
	|| $result['legacy_visible_label']
	|| ! empty( $result['duplicate_ids'] )
) {
	fwrite( STDERR, "EventBridge admin render harness failed.\n" );
	fwrite( STDERR, wp_json_encode( $result, JSON_PRETTY_PRINT ) . PHP_EOL );
	exit( 1 );
}

echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
