<?php

class EventBridge_Profile_Test extends WP_UnitTestCase {
	private $repository;
	private $contexts;

	public function set_up() {
		parent::set_up();
		$this->repository = new EventBridge_Profile_Repository();
		$this->contexts = new EventBridge_Profile_Context_Repository();
		$this->repository->ensure_tables();
		$this->contexts->ensure_table();
	}

	public function tear_down() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $this->contexts->table() );
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

	public function test_generic_profile_context_updates_non_empty_allowlisted_values() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'context-profile', true ) );
		$this->assertTrue( $this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.1', '_fbc' => '' ) ) );
		$this->assertTrue( $this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.2' ) ) );
		$context = $this->contexts->get_for_profile( $profile['id'] );
		$this->assertSame( 'fb.1.1700000000000.2', $context['browser_cookie']['_fbp']['value'] );
		$this->assertArrayNotHasKey( '_fbc', $context['browser_cookie'] );
	}

	public function test_admin_batch_reads_exclude_token_hashes_and_unknown_context_keys() {
		$first = $this->repository->get_or_create( hash( 'sha256', 'admin-first', true ) );
		$second = $this->repository->get_or_create( hash( 'sha256', 'admin-second', true ) );
		$this->contexts->save( $first['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1', '_fbc' => 'fb.1.2', 'api_token' => 'secret' ) );
		$this->contexts->save( $first['id'], 'private', array( '_fbp' => 'wrong-namespace' ) );

		$profiles = $this->repository->get_admin_attribution( array( $first['id'], $second['id'], $first['id'] ) );
		$contexts = $this->contexts->get_admin_contexts( array( $first['id'], $second['id'], $first['id'] ) );
		$this->assertCount( 2, $profiles );
		$this->assertArrayNotHasKey( 'browser_token_hash', $profiles[ $first['id'] ] );
		$this->assertSame( array( '_fbp' => 'fb.1.1', '_fbc' => 'fb.1.2' ), $contexts[ $first['id'] ] );
		$this->assertArrayNotHasKey( $second['id'], $contexts );
	}
}
