<?php

class EventBridge_Manual_Conversion_CAPI_Test_Log extends EventBridge_Log {
	public $records = array();

	public function log( $level, $source, $message, $details = array() ) {
		$this->records[] = compact( 'level', 'source', 'message', 'details' );
		return true;
	}
}

class EventBridge_Manual_Conversion_CAPI_Test extends WP_UnitTestCase {
	private $profiles;
	private $contexts;
	private $conversions;
	private $requests;
	private $responses;

	public function set_up() {
		parent::set_up();
		$this->profiles    = new EventBridge_Profile_Repository();
		$this->contexts    = new EventBridge_Profile_Context_Repository();
		$this->conversions = new EventBridge_Conversion_Repository();
		$this->requests    = array();
		$this->responses   = array( $this->http_response( 200, array( 'events_received' => 1, 'messages' => array(), 'fbtrace_id' => 'TEST_TRACE' ) ) );
		$this->profiles->ensure_tables();
		$this->contexts->ensure_table();
		$this->conversions->ensure_table();
		update_option( EventBridge_Settings::OPTION_NAME, array( 'pixel_id' => '123456789', 'capi_token' => 'contract-token', 'debug' => false ), false );
		add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), PHP_INT_MIN, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ), PHP_INT_MIN );
		delete_option( EventBridge_Settings::OPTION_NAME );
		delete_option( EventBridge_Events::OPTION_NAME );
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->contexts->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		parent::tear_down();
	}

	public function mock_http_request( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://graph.facebook.com/' ) ) {
			return new WP_Error( 'unexpected_external_request', 'The contract test blocks every non-Meta HTTP request.' );
		}

		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return ! empty( $this->responses ) ? array_shift( $this->responses ) : new WP_Error( 'missing_fake_response', 'No fake Meta response was configured.' );
	}

	public function test_testmode_manual_conversion_preserves_configuration_and_builds_the_meta_contract() {
		$event_key  = $this->store_event( 'SessionBookedTest', true );
		$conversion = $this->create_conversion( $event_key );
		$service    = $this->make_service();

		$result = $service->execute( $conversion['id'] );

		$this->assertSame( 'converted', $result['code'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'v25.0', EVENTBRIDGE_GRAPH_API_VERSION );
		$this->assertSame( 'https://graph.facebook.com/v25.0/123456789/events', $this->requests[0]['url'] );
		$this->assertTrue( $this->requests[0]['args']['blocking'] );
		$body       = json_decode( $this->requests[0]['args']['body'], true );
		$deliveries = $this->conversions->get_deliveries( $conversion['id'] );
		$occurrence = json_decode( $deliveries[0]['occurrence'], true );
		$this->assertCount( 1, $body['data'] );
		$this->assertSame( 'SessionBookedTest', $body['data'][0]['event_name'] );
		$this->assertSame( $occurrence['event_id'], $body['data'][0]['event_id'] );
		$this->assertSame( $occurrence['event_time'], $body['data'][0]['event_time'] );
		$this->assertSame( $deliveries[0]['event_id'], $body['data'][0]['event_id'] );
		$this->assertSame( (int) $deliveries[0]['event_time'], $body['data'][0]['event_time'] );
		$this->assertSame( 'TEST12345', $body['test_event_code'] );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );
		$this->assertTrue( $occurrence['event_configuration']['meta_test_mode'] );
		$this->assertSame( 'TEST12345', $occurrence['event_configuration']['meta_test_event_code'] );
		$this->assertSame( EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED, $deliveries[0]['status'] );
		$this->assertSame( 200, (int) $deliveries[0]['last_http_code'] );
	}

	public function test_retry_reuses_the_same_manual_occurrence_and_test_code() {
		$event_key       = $this->store_event( 'SessionBookedTest', true );
		$conversion      = $this->create_conversion( $event_key );
		$service         = $this->make_service();
		$this->responses = array(
			$this->http_response( 500, array( 'error' => array( 'message' => 'temporary' ) ) ),
			$this->http_response( 200, array( 'events_received' => 1, 'messages' => array(), 'fbtrace_id' => 'TEST_TRACE' ) ),
		);

		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$stored_occurrence = $this->conversions->get_deliveries( $conversion['id'] )[0]['occurrence'];
		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );

		$this->assertCount( 2, $this->requests );
		$first  = json_decode( $this->requests[0]['args']['body'], true );
		$second = json_decode( $this->requests[1]['args']['body'], true );
		$this->assertSame( $first['data'][0]['event_name'], $second['data'][0]['event_name'] );
		$this->assertSame( $first['data'][0]['event_id'], $second['data'][0]['event_id'] );
		$this->assertSame( $first['data'][0]['event_time'], $second['data'][0]['event_time'] );
		$this->assertSame( 'TEST12345', $first['test_event_code'] );
		$this->assertSame( $first['test_event_code'], $second['test_event_code'] );
		$this->assertSame( $stored_occurrence, $this->conversions->get_deliveries( $conversion['id'] )[0]['occurrence'] );
	}

	public function test_production_manual_conversion_omits_test_event_code() {
		$event_key  = $this->store_event( 'SessionBooked', false );
		$conversion = $this->create_conversion( $event_key );

		$this->assertSame( 'converted', $this->make_service()->execute( $conversion['id'] )['code'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'test_event_code', $body );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );
	}

	public function test_unconfirmed_http_200_keeps_the_conversion_retryable_and_occurrence_stable() {
		$event_key       = $this->store_event( 'SessionBookedTest', true );
		$conversion      = $this->create_conversion( $event_key );
		$service         = $this->make_service();
		$this->responses = array(
			$this->http_response( 200, array() ),
			$this->http_response( 200, array( 'events_received' => 0, 'messages' => array(), 'fbtrace_id' => 'TEST_TRACE' ) ),
		);

		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$first_delivery = $this->conversions->get_deliveries( $conversion['id'] )[0];
		$this->assertSame( EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, $first_delivery['status'] );
		$this->assertSame( 'invalid_success_response', $first_delivery['last_error_code'] );
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$second_delivery = $this->conversions->get_deliveries( $conversion['id'] )[0];
		$this->assertSame( $first_delivery['occurrence'], $second_delivery['occurrence'] );
		$this->assertSame( EventBridge_Conversion_Repository::STATUS_OPEN, $this->conversions->get_by_id( $conversion['id'] )['status'] );
		$this->assertSame( json_decode( $this->requests[0]['args']['body'], true )['data'][0]['event_id'], json_decode( $this->requests[1]['args']['body'], true )['data'][0]['event_id'] );
	}

	private function make_service() {
		$log      = new EventBridge_Manual_Conversion_CAPI_Test_Log();
		$capi     = new EventBridge_Meta_CAPI( new EventBridge_Settings(), $log );
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $capi ) );

		return new EventBridge_Conversion_Service(
			$this->conversions,
			new EventBridge_Events(),
			new EventBridge_Dispatcher( $registry ),
			$registry,
			$this->profiles,
			$this->contexts,
			new EventBridge_Fluent_Booking()
		);
	}

	private function store_event( $event_name, $test_mode ) {
		$events = new EventBridge_Events();
		$key    = 'evt_' . wp_generate_uuid4();
		$event  = array_merge(
			$events->get_form_defaults(),
			array(
				'label'                => $event_name,
				'event_name'           => $event_name,
				'enabled'              => true,
				'channels'             => array( 'browser' => false, 'capi' => true ),
				'browser'              => false,
				'capi'                 => true,
				'meta_test_mode'       => (bool) $test_mode,
				'meta_test_event_code' => $test_mode ? 'TEST12345' : '',
			)
		);
		unset( $event['triggers'], $event['eventbridge_schema_version'], $event['eventbridge_compat'] );
		update_option( EventBridge_Events::OPTION_NAME, array( $key => $event ), false );
		return $key;
	}

	private function create_conversion( $event_key ) {
		$profile = $this->profiles->get_or_create( hash( 'sha256', wp_generate_uuid4(), true ) );
		$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', '4821' );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', '4821' );
		$this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '4821', array( $event_key ) );
		return $this->conversions->get_open()[0];
	}

	private function http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
