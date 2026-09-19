<?php

class EventBridge_Destination_Test_Meta_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();
	public function __construct() {}

	public function send_custom_event( $event_name, $event_id, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = array(
			'method' => 'custom',
			'args'   => func_get_args(),
		);

		return true;
	}

	public function send_server_event( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array(), $action_source = 'website', $projection_context = array() ) {
		$this->calls[] = array(
			'method' => 'normal',
			'args'   => func_get_args(),
		);

		return 'started';
	}

	public function send_server_event_confirmed( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array(), $action_source = 'website', $projection_context = array() ) {
		$this->calls[] = array(
			'method' => 'confirmed',
			'args'   => func_get_args(),
		);

		return array( 'status' => 'success' );
	}
}

class EventBridge_Destination_Test_Destination implements EventBridge_Destination_Interface {
	private $id;
	private $capabilities;
	public $calls = array();

	public function __construct( $id = 'test', $capabilities = array() ) {
		$this->id           = $id;
		$this->capabilities = $capabilities;
	}

	public function get_id() {
		return $this->id;
	}

	public function get_label() {
		return 'Test';
	}

	public function get_capabilities() {
		return $this->capabilities;
	}

	public function project_event_configuration( $event ) {
		return array();
	}

	public function send_server_event( $occurrence, $confirmed = false ) {
		$this->calls[] = array( 'occurrence' => $occurrence, 'confirmed' => $confirmed );

		return $confirmed ? array( 'status' => 'success' ) : 'started';
	}

	public function send_custom_event( $occurrence ) {
		$this->calls[] = array( 'occurrence' => $occurrence, 'custom' => true );

		return true;
	}
}

class EventBridge_Destinations_Test extends WP_UnitTestCase {
	public function test_registry_registers_destinations_and_rejects_unknown_or_duplicate_ids() {
		$registry    = new EventBridge_Destination_Registry();
		$destination = new EventBridge_Destination_Test_Destination();

		$this->assertTrue( $registry->register( $destination ) );
		$this->assertSame( $destination, $registry->get_destination( 'test' ) );
		$this->assertFalse( $registry->get_destination( 'unknown' ) );
		$this->assertFalse( $registry->register( new EventBridge_Destination_Test_Destination() ) );
		$this->assertSame( array( 'test' => $destination ), $registry->get_destinations() );
	}

	public function test_meta_destination_has_the_expected_identity_and_capabilities() {
		$destination = new EventBridge_Meta_Destination( new EventBridge_Destination_Test_Meta_CAPI() );

		$this->assertSame( 'meta', $destination->get_id() );
		$this->assertSame( 'Meta', $destination->get_label() );
		$this->assertSame(
			array(
				'browser_events'            => true,
				'server_events'             => true,
				'confirmed_server_delivery' => true,
				'customer_matching'         => true,
				'test_mode'                 => true,
			),
			$destination->get_capabilities()
		);
	}

	public function test_meta_event_configuration_projection_preserves_the_source_event() {
		$destination = new EventBridge_Meta_Destination( new EventBridge_Destination_Test_Meta_CAPI() );
		$event       = array(
			'enabled'              => true,
			'event_name'           => 'Lead',
			'channels'             => array( 'browser' => true, 'capi' => false ),
			'browser'              => false,
			'capi'                 => true,
			'meta_test_mode'       => true,
			'meta_test_event_code' => 'TEST123',
			'unrelated'            => 'ignored',
		);
		$original    = $event;

		$this->assertSame(
			array(
				'id'         => 'meta',
				'enabled'    => true,
				'event_name' => 'Lead',
				'browser'    => array( 'enabled' => true ),
				'server'     => array( 'enabled' => false ),
				'test'       => array( 'enabled' => true, 'code' => 'TEST123' ),
			),
			$destination->project_event_configuration( $event )
		);
		$this->assertSame( $original, $event );
	}

	public function test_meta_event_configuration_uses_legacy_channel_fallbacks() {
		$destination = new EventBridge_Meta_Destination( new EventBridge_Destination_Test_Meta_CAPI() );

		$configuration = $destination->project_event_configuration(
			array(
				'enabled'    => true,
				'event_name' => 'Purchase',
				'browser'    => true,
				'capi'       => true,
			)
		);

		$this->assertTrue( $configuration['browser']['enabled'] );
		$this->assertTrue( $configuration['server']['enabled'] );
		$this->assertFalse( $configuration['test']['enabled'] );
		$this->assertSame( '', $configuration['test']['code'] );
	}

	public function test_meta_destination_translates_normal_and_confirmed_occurrences() {
		$capi        = new EventBridge_Destination_Test_Meta_CAPI();
		$destination = new EventBridge_Meta_Destination( $capi );
		$occurrence  = $this->get_occurrence();

		$this->assertSame( 'started', $destination->send_server_event( $occurrence ) );
		$this->assertSame( array( 'status' => 'success' ), $destination->send_server_event( $occurrence, true ) );
		$this->assertSame( 'normal', $capi->calls[0]['method'] );
		$this->assertSame( 'confirmed', $capi->calls[1]['method'] );
		$this->assertSame( array_values( $occurrence ), array_slice( $capi->calls[0]['args'], 0, 8 ) );
		$this->assertSame( array_values( $occurrence ), array_slice( $capi->calls[1]['args'], 0, 8 ) );
		$this->assertSame( 'website', $capi->calls[0]['args'][8] );
		$this->assertSame( array( 'fbc_source' => 'none', 'attribution_source' => 'legacy_live_profile' ), $capi->calls[0]['args'][9] );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_meta_destination_projects_stored_browser_context_and_reconstructs_fbc() {
		$capi = new EventBridge_Destination_Test_Meta_CAPI(); $destination = new EventBridge_Meta_Destination( $capi );
		$occurrence = $this->get_occurrence();
		$occurrence['browser_context'] = array(
			'browser_cookie' => array( '_fbp' => array( 'value' => 'fb.1.1700000000000.123456', 'captured_at' => '2026-01-01 00:00:00' ) ),
			'client_request' => array(
				'ip_address' => array( 'value' => '203.0.113.42', 'captured_at' => '2026-01-01 00:00:00' ),
				'user_agent' => array( 'value' => 'EventBridge synthetic visitor/1.0', 'captured_at' => '2026-01-01 00:00:00' ),
			),
		);
		$occurrence['attribution_context'] = array( 'last_touch' => array( 'captured_at' => '2026-01-02T00:00:00+00:00', 'fbclid' => 'click-1' ) );

		$destination->send_server_event( $occurrence, true );
		$user_data = $capi->calls[0]['args'][6];
		$this->assertSame( 'fb.1.1700000000000.123456', $user_data['fbp'] );
		$this->assertSame( 'fb.1.1767312000000.click-1', $user_data['fbc'] );
		$this->assertSame( '203.0.113.42', $user_data['client_ip_address'] );
		$this->assertSame( 'EventBridge synthetic visitor/1.0', $user_data['client_user_agent'] );
		$this->assertSame( 'fbclid_fallback', $capi->calls[0]['args'][9]['fbc_source'] );
	}

	public function test_meta_destination_prefers_cookie_fbc_and_forwards_manual_action_source() {
		$capi = new EventBridge_Destination_Test_Meta_CAPI(); $destination = new EventBridge_Meta_Destination( $capi );
		$occurrence = $this->get_occurrence();
		$occurrence['action_source'] = 'phone_call';
		$occurrence['event_source_url'] = '';
		$occurrence['attribution_source'] = 'booking_snapshot';
		$occurrence['browser_context'] = array( 'browser_cookie' => array( '_fbc' => array( 'value' => 'fb.1.1700000000000.cookie-click', 'captured_at' => '2026-01-01 00:00:00' ) ) );
		$occurrence['attribution_context'] = array( 'last_touch' => array( 'captured_at' => '2026-01-02T00:00:00+00:00', 'fbclid' => 'fallback-click' ) );

		$destination->send_server_event( $occurrence, true );
		$this->assertSame( 'fb.1.1700000000000.cookie-click', $capi->calls[0]['args'][6]['fbc'] );
		$this->assertSame( 'phone_call', $capi->calls[0]['args'][8] );
		$this->assertSame( 'cookie', $capi->calls[0]['args'][9]['fbc_source'] );
		$this->assertSame( 'booking_snapshot', $capi->calls[0]['args'][9]['attribution_source'] );
	}

	public function test_meta_destination_translates_custom_occurrences() {
		$capi        = new EventBridge_Destination_Test_Meta_CAPI();
		$destination = new EventBridge_Meta_Destination( $capi );
		$occurrence  = $this->get_custom_occurrence();

		$this->assertTrue( $destination->send_custom_event( $occurrence ) );
		$this->assertSame( 'custom', $capi->calls[0]['method'] );
		$this->assertSame( array_values( $occurrence ), $capi->calls[0]['args'] );
	}

	public function test_dispatcher_handles_normal_confirmed_and_unknown_destinations() {
		$registry    = new EventBridge_Destination_Registry();
		$destination = new EventBridge_Destination_Test_Destination(
			'test',
			array( 'server_events' => true, 'confirmed_server_delivery' => true )
		);
		$registry->register( $destination );
		$dispatcher = new EventBridge_Dispatcher( $registry );

		$this->assertSame( 'started', $dispatcher->dispatch_server_event( 'test', $this->get_occurrence() ) );
		$this->assertSame( array( 'status' => 'success' ), $dispatcher->dispatch_server_event( 'test', $this->get_occurrence(), true ) );
		$this->assertFalse( $dispatcher->dispatch_server_event( 'unknown', $this->get_occurrence() ) );
		$this->assertFalse( $destination->calls[0]['confirmed'] );
		$this->assertTrue( $destination->calls[1]['confirmed'] );
	}

	public function test_dispatcher_handles_custom_and_unknown_destinations() {
		$registry    = new EventBridge_Destination_Registry();
		$destination = new EventBridge_Destination_Test_Destination( 'test', array( 'server_events' => true ) );
		$registry->register( $destination );
		$dispatcher = new EventBridge_Dispatcher( $registry );

		$this->assertTrue( $dispatcher->dispatch_custom_event( 'test', $this->get_custom_occurrence() ) );
		$this->assertFalse( $dispatcher->dispatch_custom_event( 'unknown', $this->get_custom_occurrence() ) );
		$this->assertTrue( $destination->calls[0]['custom'] );
		$this->assertSame( $this->get_custom_occurrence(), $destination->calls[0]['occurrence'] );
	}

	public function test_dispatcher_rejects_destinations_without_required_server_capabilities() {
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Destination_Test_Destination( 'browser-only', array( 'browser_events' => true ) ) );
		$registry->register( new EventBridge_Destination_Test_Destination( 'unconfirmed', array( 'server_events' => true ) ) );
		$dispatcher = new EventBridge_Dispatcher( $registry );

		$this->assertFalse( $dispatcher->dispatch_server_event( 'browser-only', $this->get_occurrence() ) );
		$this->assertFalse( $dispatcher->dispatch_server_event( 'unconfirmed', $this->get_occurrence(), true ) );
		$this->assertFalse( $dispatcher->dispatch_custom_event( 'browser-only', $this->get_custom_occurrence() ) );
	}

	private function get_occurrence() {
		return array(
			'event_name'          => 'Purchase',
			'event_id'            => '11111111-1111-4111-8111-111111111111',
			'event_time'          => 1000,
			'event_source_url'    => 'https://example.org/checkout/',
			'custom_data'         => array( 'value' => 10 ),
			'details'             => array( 'event_key' => 'purchase' ),
			'advanced_user_data'  => array( 'em' => 'hash' ),
			'event_configuration' => array( 'capi' => true ),
		);
	}

	private function get_custom_occurrence() {
		return array(
			'event_name'          => 'Lead',
			'event_id'            => '11111111-1111-4111-8111-111111111111',
			'event_source_url'    => 'https://example.org/contact/',
			'custom_data'         => array( 'source' => 'form' ),
			'details'             => array( 'event_key' => 'lead', 'trigger_id' => 'contact-form' ),
			'advanced_user_data'  => array( 'em' => 'hash' ),
			'event_configuration' => array( 'capi' => true, 'meta_test_mode' => true, 'meta_test_event_code' => 'TEST123' ),
		);
	}
}
