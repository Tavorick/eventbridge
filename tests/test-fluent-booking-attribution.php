<?php

class EventBridge_Fluent_Booking_Attribution_Test_Service extends EventBridge_Profile_Service {
	public $links = array();
	public function __construct() {}
	public function link_external( $provider, $entity_type, $external_id ) {
		$this->links[] = array( $provider, $entity_type, $external_id );
		return true;
	}
}

class EventBridge_Fluent_Booking_Attribution_Test_Booking {
	public $id = 4821;
	public $source = 'web';
	public $source_url = 'http://example.org/book-a-session/';
	public $ip_address = '203.0.113.42';
}

class EventBridge_Fluent_Booking_Attribution_Test_Browser_Context extends EventBridge_Browser_Context_Service {
	public $captures = 0;
	public $profile_ids = array();
	public $client_captures = array();
	public function __construct() {}
	public function capture_request_cookies( $profile_id = 0 ) { $this->captures++; $this->profile_ids[] = $profile_id; return true; }
	public function get_request_client_context( $booking_ip_address = '' ) { $this->client_captures[] = $booking_ip_address; return array( 'ip_address' => $booking_ip_address, 'user_agent' => 'EventBridge test booking agent' ); }
}

class EventBridge_Fluent_Booking_Attribution_Test_Throwing_Browser_Context extends EventBridge_Browser_Context_Service {
	public function __construct() {}
	public function capture_request_cookies( $profile_id = 0 ) { throw new RuntimeException( 'capture_failed' ); }
}

class EventBridge_Fluent_Booking_Attribution_Test_Failing_Client_Context extends EventBridge_Fluent_Booking_Attribution_Test_Browser_Context {
	public function get_request_client_context( $booking_ip_address = '' ) {
		$this->client_captures[] = $booking_ip_address;
		return false;
	}
}

class EventBridge_Fluent_Booking_Attribution_Test_Repository extends EventBridge_Profile_Repository {
	public function find_link( $provider, $entity_type, $external_id ) {
		return array( 'id' => 10, 'profile_id' => 20 );
	}
}

class EventBridge_Fluent_Booking_Attribution_Test_Fluent extends EventBridge_Fluent_Booking {
	public function is_followup_relevant( $booking ) { return true; }
	public function get_conversion_event_ids( $booking_or_calendar_id ) { return array( 'evt_11111111-1111-4111-8111-111111111111' ); }
}

class EventBridge_Fluent_Booking_Attribution_Test_Conversions extends EventBridge_Conversion_Service {
	public $calls = array();
	public function __construct() {}
	public function ensure_open_from_link( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array(), $client_request_context = false, $event_source_url = '' ) {
		$this->calls[] = compact( 'profile_link', 'provider', 'entity_type', 'external_id', 'conversion_event_ids', 'client_request_context', 'event_source_url' );
		return true;
	}
}

class EventBridge_Fluent_Booking_Attribution_Test extends WP_UnitTestCase {
	public function test_booking_creates_a_provider_neutral_profile_link() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$binder = new EventBridge_Fluent_Booking_Attribution( $service );
		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( array( array( 'fluent_booking', 'booking', '4821' ) ), $service->links );
	}

	public function test_invalid_booking_is_ignored_without_throwing() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$binder = new EventBridge_Fluent_Booking_Attribution( $service );
		$binder->bind_booking( new stdClass() );
		$this->assertSame( array(), $service->links );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_synchronizes_request_cookies_after_linking() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Browser_Context();
		$binder = new EventBridge_Fluent_Booking_Attribution( $service, null, null, null, $browser_context );
		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( 1, $browser_context->captures );
		$this->assertSame( array( 0 ), $browser_context->profile_ids );
		$this->assertSame( array(), $browser_context->client_captures );
		$this->assertCount( 1, $service->links );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_cookie_synchronization_targets_the_linked_profile() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Browser_Context();
		$binder = new EventBridge_Fluent_Booking_Attribution(
			$service,
			new EventBridge_Fluent_Booking_Attribution_Test_Repository(),
			null,
			null,
			$browser_context
		);

		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( array( 20 ), $browser_context->profile_ids );
		$this->assertSame( array(), $browser_context->client_captures );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_conversion_relevant_web_booking_captures_client_context_before_opening_opportunity() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Browser_Context();
		$conversions = new EventBridge_Fluent_Booking_Attribution_Test_Conversions();
		$binder = new EventBridge_Fluent_Booking_Attribution(
			$service,
			new EventBridge_Fluent_Booking_Attribution_Test_Repository(),
			new EventBridge_Fluent_Booking_Attribution_Test_Fluent(),
			$conversions,
			$browser_context
		);

		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( array( '203.0.113.42' ), $browser_context->client_captures );
		$this->assertCount( 1, $conversions->calls );
		$this->assertSame( array( 'ip_address' => '203.0.113.42', 'user_agent' => 'EventBridge test booking agent' ), $conversions->calls[0]['client_request_context'] );
		$this->assertSame( home_url( '/book-a-session/' ), $conversions->calls[0]['event_source_url'] );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_admin_booking_does_not_capture_admin_request_context() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Browser_Context();
		$conversions = new EventBridge_Fluent_Booking_Attribution_Test_Conversions();
		$binder = new EventBridge_Fluent_Booking_Attribution( $service, new EventBridge_Fluent_Booking_Attribution_Test_Repository(), new EventBridge_Fluent_Booking_Attribution_Test_Fluent(), $conversions, $browser_context );
		$booking = new EventBridge_Fluent_Booking_Attribution_Test_Booking();
		$booking->source = 'admin';

		$binder->bind_booking( $booking );
		$this->assertSame( 0, $browser_context->captures );
		$this->assertSame( array(), $browser_context->client_captures );
		$this->assertCount( 1, $conversions->calls );
		$this->assertFalse( $conversions->calls[0]['client_request_context'] );
		$this->assertSame( '', $conversions->calls[0]['event_source_url'] );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_failed_web_client_capture_excludes_pre_existing_context_from_snapshot_policy() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Failing_Client_Context();
		$conversions = new EventBridge_Fluent_Booking_Attribution_Test_Conversions();
		$binder = new EventBridge_Fluent_Booking_Attribution(
			$service,
			new EventBridge_Fluent_Booking_Attribution_Test_Repository(),
			new EventBridge_Fluent_Booking_Attribution_Test_Fluent(),
			$conversions,
			$browser_context
		);

		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( array( '203.0.113.42' ), $browser_context->client_captures );
		$this->assertCount( 1, $conversions->calls );
		$this->assertFalse( $conversions->calls[0]['client_request_context'] );
	}

	public function test_browser_capture_failure_does_not_block_the_conversion_opportunity() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$conversions = new EventBridge_Fluent_Booking_Attribution_Test_Conversions();
		$binder = new EventBridge_Fluent_Booking_Attribution(
			$service,
			new EventBridge_Fluent_Booking_Attribution_Test_Repository(),
			new EventBridge_Fluent_Booking_Attribution_Test_Fluent(),
			$conversions,
			new EventBridge_Fluent_Booking_Attribution_Test_Throwing_Browser_Context()
		);

		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertCount( 1, $service->links );
		$this->assertCount( 1, $conversions->calls );
	}
}
