<?php

class EventBridge_Conversion_Test extends WP_UnitTestCase {
	private $profiles;
	private $conversions;

	public function set_up() {
		parent::set_up();
		$this->profiles = new EventBridge_Profile_Repository();
		$this->conversions = new EventBridge_Conversion_Repository();
		$this->profiles->ensure_tables();
		$this->conversions->ensure_table();
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
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
}
