<?php

class EventBridge_Profile_Test extends WP_UnitTestCase {
	private $repository;

	public function set_up() {
		parent::set_up();
		$this->repository = new EventBridge_Profile_Repository();
		$this->repository->ensure_tables();
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->repository->links_table() );
		$wpdb->query( 'DELETE FROM ' . $this->repository->profiles_table() );
		parent::tear_down();
	}

	public function test_profile_is_lazy_and_first_touch_is_preserved() {
		$hash = hash( 'sha256', 'test-token', true );
		$this->assertFalse( $this->repository->find_by_token_hash( $hash ) );
		$profile = $this->repository->get_or_create( $hash );
		$first = array( 'version' => 1, 'captured_at' => '2026-01-01T00:00:00+00:00', 'landing_url' => 'https://example.org/', 'utm_source' => 'google' );
		$last = array( 'version' => 1, 'captured_at' => '2026-01-02T00:00:00+00:00', 'landing_url' => 'https://example.org/contact/', 'utm_source' => 'facebook' );
		$this->assertTrue( $this->repository->save_touch( $profile, $first ) );
		$profile = $this->repository->get_by_id( $profile['id'] );
		$this->assertTrue( $this->repository->save_touch( $profile, $last ) );
		$profile = $this->repository->get_by_id( $profile['id'] );
		$this->assertSame( $first, json_decode( $profile['first_touch'], true ) );
		$this->assertSame( $last, json_decode( $profile['last_touch'], true ) );
	}

	public function test_external_link_is_idempotent_and_never_reassigns() {
		$first = $this->repository->get_or_create( hash( 'sha256', 'first', true ) );
		$second = $this->repository->get_or_create( hash( 'sha256', 'second', true ) );
		$this->assertTrue( $this->repository->link( $first['id'], 'fluent_booking', 'booking', '4821' ) );
		$this->assertTrue( $this->repository->link( $first['id'], 'fluent_booking', 'booking', '4821' ) );
		$this->assertFalse( $this->repository->link( $second['id'], 'fluent_booking', 'booking', '4821' ) );
	}
}
