<?php

class EventBridge_Conversion_Test_Destination implements EventBridge_Destination_Interface {
	public $calls = array();
	public $results = array();
	public function get_id() { return 'conversion-test'; }
	public function get_label() { return 'Conversion test'; }
	public function get_capabilities() { return array( 'server_events' => true, 'confirmed_server_delivery' => true ); }
	public function project_event_configuration( $event ) { return array( 'enabled' => ! empty( $event['enabled'] ), 'server' => array( 'enabled' => ! empty( $event['capi'] ) ) ); }
	public function send_server_event( $occurrence, $confirmed = false ) { $this->calls[] = array( 'occurrence' => $occurrence, 'confirmed' => $confirmed ); return empty( $this->results ) ? array( 'status' => 'success', 'reason' => 'confirmed', 'http_code' => 200 ) : array_shift( $this->results ); }
	public function send_custom_event( $occurrence ) { return false; }
}

class EventBridge_Conversion_Test_Fluent extends EventBridge_Fluent_Booking {
	public function resolve_by_external_id( $event, $external_id ) {
		return array( 'booking_id' => (string) $external_id, 'event_id' => '42', 'calendar_id' => '7', 'status' => 'scheduled', 'start_time' => '2026-08-20 10:00:00', 'event_title' => 'Intake', 'email' => 'Lead@Example.test', 'phone' => '+32470123456', 'first_name' => 'Lars', 'last_name' => 'Test', 'full_name' => 'Lars Test' );
	}
}

class EventBridge_Conversion_Test extends WP_UnitTestCase {
	private $profiles;
	private $conversions;
	private $contexts;

	public function set_up() {
		parent::set_up();
		$this->profiles = new EventBridge_Profile_Repository();
		$this->conversions = new EventBridge_Conversion_Repository();
		$this->contexts = new EventBridge_Profile_Context_Repository();
		$this->profiles->ensure_tables();
		$this->contexts->ensure_table();
		$this->conversions->ensure_table();
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->contexts->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		parent::tear_down();
	}

	public function test_open_opportunity_is_idempotent_per_external_booking() {
		$profile = $this->profiles->get_or_create( hash( 'sha256', 'conversion-profile', true ) );
		$this->assertTrue( $this->profiles->link( $profile['id'], 'fluent_booking', 'booking', '4821' ) );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', '4821' );
		$this->assertTrue( $this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '4821' ) );
		$this->assertTrue( $this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '4821' ) );
		$this->assertCount( 1, $this->conversions->get_open() );
	}

	public function test_two_bookings_for_one_profile_create_two_opportunities() {
		$profile = $this->profiles->get_or_create( hash( 'sha256', 'same-person', true ) );
		foreach ( array( '100', '200' ) as $booking_id ) {
			$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', $booking_id );
			$this->assertTrue( $this->conversions->ensure_open( $this->profiles->find_link( 'fluent_booking', 'booking', $booking_id ), 'fluent_booking', 'booking', $booking_id ) );
		}
		$this->assertCount( 2, $this->conversions->get_open() );
	}

	public function test_open_opportunity_snapshots_eventbridge_event_keys_without_overwriting_it_on_retry() {
		$first = 'evt_11111111-1111-4111-8111-111111111111';
		$second = 'evt_22222222-2222-4222-8222-222222222222';
		$profile = $this->profiles->get_or_create( hash( 'sha256', 'snapshot-profile', true ) );
		$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', '999' );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', '999' );

		$this->assertTrue( $this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '999', array( $first, $first, $second ) ) );
		$this->assertTrue( $this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '999', array() ) );
		$records = $this->conversions->get_open();

		$this->assertCount( 1, $records );
		$this->assertSame( array( $first, $second ), json_decode( $records[0]['conversion_event_ids'], true ) );
	}

	public function test_manual_conversion_dispatches_multiple_snapshot_events_once_and_converts() {
		$first = $this->store_event( 'Lead' ); $second = $this->store_event( 'Schedule' );
		$conversion = $this->create_conversion( array( $first, $second ) );
		list( $service, $destination ) = $this->make_service();

		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );
		$this->assertSame( 'already_converted', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 2, $destination->calls );
		$this->assertTrue( $destination->calls[0]['confirmed'] );
		$this->assertSame( EventBridge_Conversion_Repository::STATUS_CONVERTED, $this->conversions->get_by_id( $conversion['id'] )['status'] );
		foreach ( $this->conversions->get_deliveries( $conversion['id'] ) as $delivery ) $this->assertSame( EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED, $delivery['status'] );
	}

	public function test_partial_retry_only_dispatches_failed_event_and_reuses_identity() {
		$first = $this->store_event( 'Lead' ); $second = $this->store_event( 'Schedule' );
		$conversion = $this->create_conversion( array( $first, $second ) );
		list( $service, $destination ) = $this->make_service();
		$destination->results = array( array( 'status' => 'success', 'reason' => 'confirmed', 'http_code' => 200 ), array( 'status' => 'retryable', 'reason' => 'timeout', 'http_code' => 0 ) );

		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$failed_id = $destination->calls[1]['occurrence']['event_id'];
		$destination->results = array( array( 'status' => 'success', 'reason' => 'confirmed', 'http_code' => 200 ) );
		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 3, $destination->calls );
		$this->assertSame( $failed_id, $destination->calls[2]['occurrence']['event_id'] );
	}

	public function test_terminal_delivery_stays_blocked_and_is_not_claimed_again() {
		$event_key = $this->store_event( 'Lead' );
		$conversion = $this->create_conversion( array( $event_key ) );
		list( $service, $destination ) = $this->make_service();
		$destination->results = array( array( 'status' => 'terminal', 'reason' => 'invalid_request', 'http_code' => 400 ) );

		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 1, $destination->calls );
		$delivery = $this->conversions->get_deliveries( $conversion['id'] )[0];
		$this->assertSame( EventBridge_Conversion_Repository::DELIVERY_BLOCKED, $delivery['status'] );
		$this->assertSame( 'invalid_request', $delivery['last_error_code'] );
	}

	public function test_converted_at_is_set_once() {
		$event_key = $this->store_event( 'Lead' );
		$conversion = $this->create_conversion( array( $event_key ) );
		list( $service ) = $this->make_service();
		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );
		$converted_at = $this->conversions->get_by_id( $conversion['id'] )['converted_at'];
		$this->assertNotEmpty( $converted_at );
		$this->assertSame( 'already_converted', $service->execute( $conversion['id'] )['code'] );
		$this->assertSame( $converted_at, $this->conversions->get_by_id( $conversion['id'] )['converted_at'] );
	}

	public function test_claim_is_atomic_and_expired_lease_reuses_the_stored_occurrence() {
		$event_key = $this->store_event( 'Lead' ); $conversion = $this->create_conversion( array( $event_key ) );
		list( $service ) = $this->make_service();
		$prepared = new ReflectionMethod( $service, 'prepare_deliveries' ); $prepared->setAccessible( true );
		$context = new ReflectionMethod( $service, 'get_profile_context' ); $context->setAccessible( true );
		$this->assertTrue( $this->conversions->reconcile_deliveries( $conversion['id'], $prepared->invoke( $service, $conversion, array( $event_key ) ) ) );
		$delivery = $this->conversions->get_deliveries( $conversion['id'] )[0];
		$first_claim = $this->conversions->claim_delivery( $delivery['id'] );
		$this->assertIsArray( $first_claim );
		$this->assertFalse( $this->conversions->claim_delivery( $delivery['id'] ) );
		global $wpdb; $wpdb->update( $this->conversions->deliveries_table(), array( 'lease_expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $delivery['id'] ) );
		$second_claim = $this->conversions->claim_delivery( $delivery['id'] );
		$this->assertSame( $first_claim['event_id'], $second_claim['event_id'] );
		$this->assertSame( $first_claim['occurrence'], $second_claim['occurrence'] );
	}

	public function test_empty_or_missing_mapping_never_converts() {
		$conversion = $this->create_conversion( array() ); list( $service, $destination ) = $this->make_service();
		$this->assertSame( 'mapping_missing', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 0, $destination->calls );
		global $wpdb; $wpdb->update( $this->conversions->table(), array( 'conversion_event_ids' => null ), array( 'id' => $conversion['id'] ) );
		$this->assertSame( 'mapping_missing', $service->execute( $conversion['id'] )['code'] );
		$wpdb->update( $this->conversions->table(), array( 'conversion_event_ids' => '{invalid' ), array( 'id' => $conversion['id'] ) );
		$this->assertSame( 'mapping_missing', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 0, $this->conversions->get_deliveries( $conversion['id'] ) );
	}

	public function test_missing_event_is_blocked_without_dispatching_another_event() {
		$missing = 'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'; $valid = $this->store_event( 'Lead' );
		$conversion = $this->create_conversion( array( $missing, $valid ) ); list( $service, $destination ) = $this->make_service();
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 1, $destination->calls );
		$deliveries = $this->conversions->get_deliveries( $conversion['id'] );
		$this->assertSame( 'event_unavailable', $deliveries[0]['last_error_code'] );
	}

	public function test_browser_only_event_is_blocked_without_dispatch() {
		$event_key = $this->store_event( 'Lead', array( 'channels' => array( 'browser' => true, 'capi' => false ), 'browser' => true, 'capi' => false ) );
		$conversion = $this->create_conversion( array( $event_key ) );
		list( $service, $destination ) = $this->make_service();
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 0, $destination->calls );
		$this->assertSame( 'no_server_destination', $this->conversions->get_deliveries( $conversion['id'] )[0]['last_error_code'] );
	}

	public function test_restored_exact_event_key_replaces_event_blocker() {
		$event_key = 'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$conversion = $this->create_conversion( array( $event_key ) );
		list( $service, $destination ) = $this->make_service();
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->store_event( 'Wrong key' );
		$this->assertSame( 'incomplete', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 0, $destination->calls );

		$events = new EventBridge_Events();
		$event = array_merge( $events->get_form_defaults(), array( 'label' => 'Restored', 'event_name' => 'Lead', 'enabled' => true, 'channels' => array( 'browser' => false, 'capi' => true ), 'browser' => false, 'capi' => true ) );
		unset( $event['triggers'], $event['eventbridge_schema_version'], $event['eventbridge_compat'] );
		$stored = get_option( EventBridge_Events::OPTION_NAME, array() ); $stored[ $event_key ] = $event; update_option( EventBridge_Events::OPTION_NAME, $stored, false );
		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );
		$this->assertCount( 1, $destination->calls );
		$deliveries = $this->conversions->get_deliveries( $conversion['id'] );
		$this->assertCount( 1, $deliveries );
		$this->assertSame( $event_key, $deliveries[0]['event_key'] );
		$this->assertSame( 'conversion-test', $deliveries[0]['destination_id'] );
	}

	public function test_occurrence_contains_stored_attribution_and_only_hashed_fluent_pii() {
		$event_key = $this->store_event( 'Lead', array( 'parameters' => array( array( 'name' => 'campaign_source', 'source' => 'query_parameter', 'value' => 'utm_source' ) ), 'advanced_matching' => array( 'email' => array( 'source' => 'fluent_booking', 'value' => 'email' ), 'phone' => array( 'source' => 'fluent_booking', 'value' => 'phone' ) ) ) );
		$conversion = $this->create_conversion( array( $event_key ) );
		$profile = $this->profiles->get_by_id( $conversion['profile_id'] );
		$this->profiles->save_touch( $profile, array( 'version' => 1, 'captured_at' => '2026-08-01T10:00:00+00:00', 'landing_url' => home_url( '/landing/' ), 'utm_source' => 'facebook', 'fbclid' => 'click-1' ) );
		$this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.123456' ) );
		list( $service, $destination ) = $this->make_service( new EventBridge_Conversion_Test_Fluent() );
		$this->assertSame( 'converted', $service->execute( $conversion['id'] )['code'] );
		$occurrence = $destination->calls[0]['occurrence'];
		$this->assertSame( home_url( '/landing/' ), $occurrence['event_source_url'] );
		$this->assertSame( hash( 'sha256', 'lead@example.test' ), $occurrence['advanced_user_data']['em'] );
		$this->assertSame( 'facebook', $occurrence['custom_data']['campaign_source'] );
		$this->assertSame( 'facebook', $occurrence['attribution_context']['last_touch']['utm_source'] );
		$this->assertSame( 'fb.1.1700000000000.123456', $occurrence['browser_context']['browser_cookie']['_fbp']['value'] );
		$encoded = wp_json_encode( $occurrence );
		$this->assertStringNotContainsString( 'Lead@Example.test', $encoded ); $this->assertStringNotContainsString( '+32470123456', $encoded ); $this->assertStringNotContainsString( 'Lars Test', $encoded );
	}

	private function store_event( $name, array $overrides = array() ) {
		$events = new EventBridge_Events(); $key = 'evt_' . wp_generate_uuid4();
		$event = array_merge( $events->get_form_defaults(), array( 'label' => $name, 'event_name' => $name, 'enabled' => true, 'channels' => array( 'browser' => false, 'capi' => true ), 'browser' => false, 'capi' => true ), $overrides );
		unset( $event['triggers'], $event['eventbridge_schema_version'], $event['eventbridge_compat'] );
		$stored = get_option( EventBridge_Events::OPTION_NAME, array() ); $stored[ $key ] = $event; update_option( EventBridge_Events::OPTION_NAME, $stored, false );
		return $key;
	}

	private function create_conversion( array $event_keys ) {
		$profile = $this->profiles->get_or_create( hash( 'sha256', wp_generate_uuid4(), true ) );
		$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', '4821' );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', '4821' );
		$this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '4821', $event_keys );
		return $this->conversions->get_open()[0];
	}

	private function make_service( EventBridge_Fluent_Booking $fluent = null ) {
		$destination = new EventBridge_Conversion_Test_Destination(); $registry = new EventBridge_Destination_Registry(); $registry->register( $destination );
		$dispatcher = new EventBridge_Dispatcher( $registry ); $events = new EventBridge_Events();
		return array( new EventBridge_Conversion_Service( $this->conversions, $events, $dispatcher, $registry, $this->profiles, $this->contexts, $fluent ? $fluent : new EventBridge_Fluent_Booking() ), $destination );
	}
}
