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
}

class EventBridge_Fluent_Booking_Attribution_Test_Browser_Context extends EventBridge_Browser_Context_Service {
	public $captures = 0;
	public $profile_ids = array();
	public function __construct() {}
	public function capture_request_cookies( $profile_id = 0 ) { $this->captures++; $this->profile_ids[] = $profile_id; return true; }
}

class EventBridge_Fluent_Booking_Attribution_Test_Throwing_Browser_Context extends EventBridge_Browser_Context_Service {
	public function __construct() {}
	public function capture_request_cookies( $profile_id = 0 ) { throw new RuntimeException( 'capture_failed' ); }
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
	public function ensure_open_from_link( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array() ) {
		$this->calls[] = compact( 'profile_link', 'provider', 'entity_type', 'external_id', 'conversion_event_ids' );
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

	public function test_booking_synchronizes_request_cookies_after_linking() {
		$service = new EventBridge_Fluent_Booking_Attribution_Test_Service();
		$browser_context = new EventBridge_Fluent_Booking_Attribution_Test_Browser_Context();
		$binder = new EventBridge_Fluent_Booking_Attribution( $service, null, null, null, $browser_context );
		$binder->bind_booking( new EventBridge_Fluent_Booking_Attribution_Test_Booking() );
		$this->assertSame( 1, $browser_context->captures );
		$this->assertSame( array( 0 ), $browser_context->profile_ids );
		$this->assertCount( 1, $service->links );
	}

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
