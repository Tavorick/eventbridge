<?php

class EventBridge_Destination_Test_Meta_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();

	public function send_server_event( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = array(
			'method' => 'normal',
			'args'   => func_get_args(),
		);

		return 'started';
	}

	public function send_server_event_confirmed( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
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
		$this->assertSame( array_values( $occurrence ), $capi->calls[0]['args'] );
		$this->assertSame( array_values( $occurrence ), $capi->calls[1]['args'] );
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

	public function test_dispatcher_rejects_destinations_without_required_server_capabilities() {
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Destination_Test_Destination( 'browser-only', array( 'browser_events' => true ) ) );
		$registry->register( new EventBridge_Destination_Test_Destination( 'unconfirmed', array( 'server_events' => true ) ) );
		$dispatcher = new EventBridge_Dispatcher( $registry );

		$this->assertFalse( $dispatcher->dispatch_server_event( 'browser-only', $this->get_occurrence() ) );
		$this->assertFalse( $dispatcher->dispatch_server_event( 'unconfirmed', $this->get_occurrence(), true ) );
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
}
