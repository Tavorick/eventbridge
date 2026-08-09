<?php

class EventBridge_Frontend_Test_Meta_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();

	public function send_custom_event( $event_name, $event_id, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = func_get_args();

		return true;
	}
}

class EventBridge_Frontend_Test_Dispatcher extends EventBridge_Dispatcher {
	public $calls = array();
	private $result;

	public function __construct( EventBridge_Destination_Registry $registry, $result = true ) {
		parent::__construct( $registry );
		$this->result = $result;
	}

	public function dispatch_custom_event( $destination_id, array $occurrence ) {
		$this->calls[] = array( 'destination_id' => $destination_id, 'occurrence' => $occurrence );

		return $this->result ? parent::dispatch_custom_event( $destination_id, $occurrence ) : false;
	}
}

class EventBridge_Frontend_Test_Fluent_Booking extends EventBridge_Fluent_Booking {
	private $snapshot;

	public function __construct( array $snapshot = array() ) {
		$this->snapshot = $snapshot;
	}

	public function needs_lookup( $event ) {
		return ! empty( $this->snapshot );
	}

	public function is_capi_dependent( $event ) {
		return ! empty( $this->snapshot );
	}

	public function resolve( $event, $query ) {
		return $this->snapshot;
	}
}

class EventBridge_Frontend_Test extends WP_UnitTestCase {
	private $event_keys = array();
	private $original_request_uri;
	private $original_get;

	public function set_up() {
		parent::set_up();
		$this->original_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;
		$this->original_get         = $_GET;
		$_SERVER['REQUEST_URI']     = '/frontend-direct-capi/?ignored=1';
		$_GET                       = array( 'ignored' => '1' );
	}

	public function tear_down() {
		$events = get_option( EventBridge_Events::OPTION_NAME, array() );
		foreach ( $this->event_keys as $event_key ) {
			unset( $events[ $event_key ] );
		}
		update_option( EventBridge_Events::OPTION_NAME, $events );

		if ( null === $this->original_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri;
		}
		$_GET = $this->original_get;
		parent::tear_down();
	}

	public function test_direct_advanced_matching_pageview_dispatches_the_existing_meta_custom_event() {
		$event_key = $this->store_pageview_event(
			array(
				'parameters'        => array( array( 'name' => 'source', 'source' => 'static', 'value' => 'frontend' ) ),
				'advanced_matching' => array( 'email' => array( 'source' => 'static', 'value' => 'person@example.test' ) ),
			)
		);
		$settings   = new EventBridge_Settings();
		$events     = new EventBridge_Events();
		$capi       = new EventBridge_Frontend_Test_Meta_CAPI( $settings, new EventBridge_Log() );
		$registry   = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $capi ) );
		$dispatcher = new EventBridge_Frontend_Test_Dispatcher( $registry );
		$frontend   = new EventBridge_Frontend( $settings, $events, $dispatcher, new EventBridge_Fluent_Booking() );

		$frontend_events = $this->get_frontend_events( $frontend );

		$this->assertCount( 1, $dispatcher->calls );
		$this->assertSame( 'meta', $dispatcher->calls[0]['destination_id'] );
		$this->assertCount( 1, $capi->calls );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['event_name'], $capi->calls[0][0] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['event_id'], $capi->calls[0][1] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['event_source_url'], $capi->calls[0][2] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['custom_data'], $capi->calls[0][3] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['details'], $capi->calls[0][4] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['advanced_user_data'], $capi->calls[0][5] );
		$this->assertSame( $dispatcher->calls[0]['occurrence']['event_configuration'], $capi->calls[0][6] );

		$occurrence = $dispatcher->calls[0]['occurrence'];
		$this->assertSame( 'Lead', $occurrence['event_name'] );
		$this->assertSame( $event_key, $occurrence['details']['event_key'] );
		$this->assertSame( array( 'source' => 'frontend' ), $occurrence['custom_data'] );
		$this->assertSame( hash( 'sha256', 'person@example.test' ), $occurrence['advanced_user_data']['em'] );
		$this->assertTrue( $occurrence['event_configuration']['meta_test_mode'] );
		$this->assertSame( 'TEST123', $occurrence['event_configuration']['meta_test_event_code'] );
		$this->assertSame( $occurrence['event_id'], $frontend_events[0]['advancedEventId'] );
		$this->assertSame( $events->create_advanced_matching_signature( $event_key, $occurrence['event_id'], $occurrence['event_configuration'] ), $frontend_events[0]['advancedSignature'] );
	}

	public function test_direct_fluent_pageview_preserves_context_data_and_disables_capi_when_dispatch_fails() {
		$this->store_pageview_event(
			array(
				'parameters'        => array( array( 'name' => 'booking_id', 'source' => 'fluent_booking', 'value' => 'booking_id' ) ),
				'advanced_matching' => array( 'email' => array( 'source' => 'fluent_booking', 'value' => '' ) ),
			)
		);
		$settings = new EventBridge_Settings();
		$events   = new EventBridge_Events();
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( new EventBridge_Frontend_Test_Meta_CAPI( $settings, new EventBridge_Log() ) ) );
		$dispatcher = new EventBridge_Frontend_Test_Dispatcher( $registry, false );
		$fluent     = new EventBridge_Frontend_Test_Fluent_Booking(
			array( 'booking_id' => 'booking-42', 'email' => 'booking@example.test' )
		);
		$frontend = new EventBridge_Frontend( $settings, $events, $dispatcher, $fluent );

		$frontend_events = $this->get_frontend_events( $frontend );

		$this->assertCount( 1, $dispatcher->calls );
		$this->assertSame( array( 'booking_id' => 'booking-42' ), $dispatcher->calls[0]['occurrence']['custom_data'] );
		$this->assertSame( hash( 'sha256', 'booking@example.test' ), $dispatcher->calls[0]['occurrence']['advanced_user_data']['em'] );
		$this->assertFalse( $frontend_events[0]['capi'] );
		$this->assertArrayNotHasKey( 'advancedEventId', $frontend_events[0] );
		$this->assertArrayNotHasKey( 'advancedSignature', $frontend_events[0] );
	}

	private function store_pageview_event( array $overrides ) {
		$events    = new EventBridge_Events();
		$event_key = 'evt_' . wp_generate_uuid4();
		$event     = array_merge(
			array(
				'label'                => 'Frontend direct CAPI',
				'event_name'           => 'Lead',
				'enabled'              => true,
				'trigger_type'         => 'pageview',
				'url_match_type'       => 'path_exact',
				'url_match_value'      => '/frontend-direct-capi/',
				'browser'              => true,
				'capi'                 => true,
				'meta_test_mode'       => true,
				'meta_test_event_code' => 'TEST123',
			),
			$overrides
		);
		$stored_events               = get_option( EventBridge_Events::OPTION_NAME, array() );
		$stored_events[ $event_key ] = $events->normalize_event( $event, $event_key );
		update_option( EventBridge_Events::OPTION_NAME, $stored_events );
		$this->event_keys[] = $event_key;

		return $event_key;
	}

	private function get_frontend_events( EventBridge_Frontend $frontend ) {
		$method = new ReflectionMethod( EventBridge_Frontend::class, 'get_frontend_events' );
		$method->setAccessible( true );

		return $method->invoke( $frontend );
	}
}
