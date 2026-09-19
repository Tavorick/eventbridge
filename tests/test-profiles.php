<?php

class EventBridge_Profile_Context_Test_Failing_Repository extends EventBridge_Profile_Context_Repository {
	public function save( $profile_id, $namespace, array $values ) { return false; }
}

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
		unset( $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ], $_COOKIE['_fbc'], $_COOKIE['_fbp'] );
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

	public function test_profile_context_preserves_capture_time_for_same_value_and_refreshes_it_for_a_new_value() {
		global $wpdb;
		$profile = $this->repository->get_or_create( hash( 'sha256', 'context-capture-time', true ) );
		$this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.1' ) );
		$wpdb->update( $this->contexts->table(), array( 'captured_at' => '2000-01-01 00:00:00' ), array( 'profile_id' => $profile['id'], 'context_key' => '_fbp' ) );
		$this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.1' ) );
		$this->assertSame( '2000-01-01 00:00:00', $this->contexts->get_for_profile( $profile['id'] )['browser_cookie']['_fbp']['captured_at'] );
		$this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.2' ) );
		$this->assertNotSame( '2000-01-01 00:00:00', $this->contexts->get_for_profile( $profile['id'] )['browser_cookie']['_fbp']['captured_at'] );
	}

	public function test_case_only_browser_identifier_changes_refresh_capture_time() {
		global $wpdb;
		$profile = $this->repository->get_or_create( hash( 'sha256', 'case-sensitive-context', true ) );
		foreach ( array( '_fbc', '_fbp' ) as $key ) {
			$this->assertTrue( $this->contexts->save( $profile['id'], 'browser_cookie', array( $key => 'fb.1.1700000000000.AbC' ) ) );
			$wpdb->update( $this->contexts->table(), array( 'captured_at' => '2000-01-01 00:00:00' ), array( 'profile_id' => $profile['id'], 'context_key' => $key ) );
			$this->assertTrue( $this->contexts->save( $profile['id'], 'browser_cookie', array( $key => 'fb.1.1700000000000.aBc' ) ) );
			$stored = $this->contexts->get_for_profile( $profile['id'] )['browser_cookie'][ $key ];
			$this->assertSame( 'fb.1.1700000000000.aBc', $stored['value'] );
			$this->assertNotSame( '2000-01-01 00:00:00', $stored['captured_at'] );
		}
	}

	public function test_booking_request_cookie_capture_accepts_only_valid_meta_identifiers_without_deleting_existing_values() {
		$token = str_repeat( 'a', 43 );
		$_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] = $token;
		$_COOKIE['_fbc'] = 'fb.1.1700000000000.valid-click';
		$_COOKIE['_fbp'] = 'invalid-browser-id';
		$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );
		$this->assertTrue( $service->capture_request_cookies() );
		$profile = $this->repository->find_by_token_hash( hash( 'sha256', $token, true ) );
		$context = $this->contexts->get_for_profile( $profile['id'] );
		$this->assertSame( 'fb.1.1700000000000.valid-click', $context['browser_cookie']['_fbc']['value'] );
		$this->assertArrayNotHasKey( '_fbp', $context['browser_cookie'] );

		$this->contexts->save( $profile['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1700000000000.existing-browser' ) );
		unset( $_COOKIE['_fbc'] );
		$_COOKIE['_fbp'] = 'invalid-browser-id';
		$this->assertTrue( $service->capture_request_cookies() );
		$context = $this->contexts->get_for_profile( $profile['id'] );
		$this->assertSame( 'fb.1.1700000000000.valid-click', $context['browser_cookie']['_fbc']['value'] );
		$this->assertSame( 'fb.1.1700000000000.existing-browser', $context['browser_cookie']['_fbp']['value'] );
	}

	public function test_booking_request_cookie_capture_can_target_the_already_linked_profile_without_a_profile_cookie() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'linked-booking-profile', true ) );
		unset( $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] );
		$_COOKIE['_fbc'] = 'fb.1.1700000000000.linked-click';
		$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );

		$this->assertTrue( $service->capture_request_cookies( $profile['id'] ) );
		$this->assertSame( 'fb.1.1700000000000.linked-click', $this->contexts->get_for_profile( $profile['id'] )['browser_cookie']['_fbc']['value'] );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_request_client_context_uses_booking_ip_and_request_user_agent() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'booking-client-context', true ) );
		$had_remote_addr = isset( $_SERVER['REMOTE_ADDR'] );
		$old_remote_addr = $had_remote_addr ? $_SERVER['REMOTE_ADDR'] : null;
		$had_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] );
		$old_user_agent = $had_user_agent ? $_SERVER['HTTP_USER_AGENT'] : null;
		try {
			$_SERVER['REMOTE_ADDR'] = '198.51.100.200';
			$_SERVER['HTTP_USER_AGENT'] = 'EventBridge synthetic visitor/1.0';
			$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], '203.0.113.42' ) );
			$context = $this->contexts->get_for_profile( $profile['id'] );
			$this->assertSame( '203.0.113.42', $context['client_request']['ip_address']['value'] );
			$this->assertSame( 'EventBridge synthetic visitor/1.0', $context['client_request']['user_agent']['value'] );
		} finally {
			if ( $had_remote_addr ) $_SERVER['REMOTE_ADDR'] = $old_remote_addr; else unset( $_SERVER['REMOTE_ADDR'] );
			if ( $had_user_agent ) $_SERVER['HTTP_USER_AGENT'] = $old_user_agent; else unset( $_SERVER['HTTP_USER_AGENT'] );
		}
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_request_client_context_rejects_invalid_values_and_requires_a_linked_profile() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'invalid-booking-client-context', true ) );
		$had_remote_addr = isset( $_SERVER['REMOTE_ADDR'] );
		$old_remote_addr = $had_remote_addr ? $_SERVER['REMOTE_ADDR'] : null;
		$had_user_agent = isset( $_SERVER['HTTP_USER_AGENT'] );
		$old_user_agent = $had_user_agent ? $_SERVER['HTTP_USER_AGENT'] : null;
		try {
			$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
			$_SERVER['HTTP_USER_AGENT'] = "invalid\nagent";
			$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], 'also-not-an-ip' ) );
			$this->assertFalse( $service->capture_request_client_context( 0, '203.0.113.42' ) );
			$this->assertSame( array(), $this->contexts->get_for_profile( $profile['id'] ) );
		} finally {
			if ( $had_remote_addr ) $_SERVER['REMOTE_ADDR'] = $old_remote_addr; else unset( $_SERVER['REMOTE_ADDR'] );
			if ( $had_user_agent ) $_SERVER['HTTP_USER_AGENT'] = $old_user_agent; else unset( $_SERVER['HTTP_USER_AGENT'] );
		}
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_request_client_context_replaces_fluent_server_address_with_remote_address() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'server-address-booking-context', true ) );
		$previous = array();
		foreach ( array( 'SERVER_ADDR', 'REMOTE_ADDR', 'HTTP_USER_AGENT' ) as $key ) {
			$previous[ $key ] = array( 'present' => isset( $_SERVER[ $key ] ), 'value' => isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : null );
		}
		try {
			$_SERVER['SERVER_ADDR'] = '203.0.113.10';
			$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
			$_SERVER['HTTP_USER_AGENT'] = 'EventBridge direct visitor/1.0';
			$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], '203.0.113.10' ) );
			$this->assertSame( '198.51.100.77', $this->contexts->get_for_profile( $profile['id'] )['client_request']['ip_address']['value'] );
		} finally {
			foreach ( $previous as $key => $value ) {
				if ( $value['present'] ) $_SERVER[ $key ] = $value['value']; else unset( $_SERVER[ $key ] );
			}
		}
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_booking_request_client_context_atomically_replaces_partial_and_empty_captures() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'atomic-booking-client-context', true ) );
		$this->contexts->save( $profile['id'], 'client_request', array( 'ip_address' => '203.0.113.42', 'user_agent' => 'Stale visitor agent' ) );
		$previous = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT' ) as $key ) {
			$previous[ $key ] = array( 'present' => isset( $_SERVER[ $key ] ), 'value' => isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : null );
		}
		try {
			$service = new EventBridge_Browser_Context_Service( new EventBridge_Profile_Token(), $this->repository, $this->contexts );
			$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
			$_SERVER['HTTP_USER_AGENT'] = 'Fresh visitor agent';
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], 'also-not-an-ip' ) );
			$context = $this->contexts->get_for_profile( $profile['id'] );
			$this->assertArrayNotHasKey( 'ip_address', $context['client_request'] );
			$this->assertSame( 'Fresh visitor agent', $context['client_request']['user_agent']['value'] );

			$_SERVER['REMOTE_ADDR'] = '198.51.100.77';
			unset( $_SERVER['HTTP_USER_AGENT'] );
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], '' ) );
			$context = $this->contexts->get_for_profile( $profile['id'] );
			$this->assertSame( '198.51.100.77', $context['client_request']['ip_address']['value'] );
			$this->assertArrayNotHasKey( 'user_agent', $context['client_request'] );

			$_SERVER['REMOTE_ADDR'] = 'not-an-ip';
			$this->assertTrue( $service->capture_request_client_context( $profile['id'], '' ) );
			$this->assertArrayNotHasKey( 'client_request', $this->contexts->get_for_profile( $profile['id'] ) );
		} finally {
			foreach ( $previous as $key => $value ) {
				if ( $value['present'] ) $_SERVER[ $key ] = $value['value']; else unset( $_SERVER[ $key ] );
			}
		}
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_context_namespace_replace_rolls_back_when_new_values_cannot_be_saved() {
		$profile = $this->repository->get_or_create( hash( 'sha256', 'failed-atomic-client-context', true ) );
		$this->contexts->save( $profile['id'], 'client_request', array( 'ip_address' => '203.0.113.42', 'user_agent' => 'Original visitor agent' ) );
		$failing = new EventBridge_Profile_Context_Test_Failing_Repository();

		$this->assertFalse( $failing->replace_namespace( $profile['id'], 'client_request', array( 'ip_address' => '198.51.100.77' ) ) );
		$context = $this->contexts->get_for_profile( $profile['id'] );
		$this->assertSame( '203.0.113.42', $context['client_request']['ip_address']['value'] );
		$this->assertSame( 'Original visitor agent', $context['client_request']['user_agent']['value'] );
	}

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_admin_batch_reads_exclude_token_hashes_and_unknown_context_keys() {
		$first = $this->repository->get_or_create( hash( 'sha256', 'admin-first', true ) );
		$second = $this->repository->get_or_create( hash( 'sha256', 'admin-second', true ) );
		$this->contexts->save( $first['id'], 'browser_cookie', array( '_fbp' => 'fb.1.1', '_fbc' => 'fb.1.2', 'api_token' => 'secret' ) );
		$this->contexts->save( $first['id'], 'private', array( '_fbp' => 'wrong-namespace' ) );
		$this->contexts->save( $first['id'], 'client_request', array( 'ip_address' => '203.0.113.42', 'user_agent' => 'Private visitor agent' ) );

		$profiles = $this->repository->get_admin_attribution( array( $first['id'], $second['id'], $first['id'] ) );
		$contexts = $this->contexts->get_admin_contexts( array( $first['id'], $second['id'], $first['id'] ) );
		$this->assertCount( 2, $profiles );
		$this->assertArrayNotHasKey( 'browser_token_hash', $profiles[ $first['id'] ] );
		$this->assertSame( array( '_fbp' => 'fb.1.1', '_fbc' => 'fb.1.2' ), $contexts[ $first['id'] ] );
		$this->assertArrayNotHasKey( $second['id'], $contexts );
	}
}
