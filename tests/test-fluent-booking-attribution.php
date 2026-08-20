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
}
