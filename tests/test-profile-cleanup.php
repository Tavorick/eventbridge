<?php

class EventBridge_Profile_Cleanup_Test extends WP_UnitTestCase {
	private $profiles;
	private $contexts;
	private $conversions;
	private $cleanup;
	private $sequence = 0;

	public function set_up() {
		parent::set_up();
		$this->profiles = new EventBridge_Profile_Repository();
		$this->profiles->ensure_tables();
		$this->contexts = new EventBridge_Profile_Context_Repository();
		$this->contexts->ensure_table();
		$this->conversions = new EventBridge_Conversion_Repository();
		$this->conversions->ensure_table();
		$this->cleanup = new EventBridge_Profile_Cleanup( $this->profiles, $this->conversions, $this->contexts );
		remove_all_filters( 'eventbridge_profile_retention_days' );
	}

	public function tear_down() {
		global $wpdb;
		remove_action( EventBridge_Profile_Cleanup::CLEANUP_HOOK, array( $this->cleanup, 'cleanup' ) );
		remove_action( 'admin_post_eventbridge_profile_cleanup_preview', array( $this->cleanup, 'handle_manual_preview' ) );
		remove_action( 'admin_post_eventbridge_profile_cleanup', array( $this->cleanup, 'handle_manual_cleanup' ) );
		remove_all_filters( 'eventbridge_profile_retention_days' );
		delete_option( EventBridge_Profile_Cleanup::LOCK_OPTION );
		delete_transient( EventBridge_Profile_Cleanup::get_preview_transient_key( get_current_user_id() ) );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->contexts->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		parent::tear_down();
	}

	public function test_default_zero_retention_keeps_old_profiles_but_removes_orphan_links_and_contexts() {
		global $wpdb;
		$profile = $this->create_old_profile();
		$this->insert_orphan_link( 900001, 'orphan-link' );
		$this->contexts->save( 900002, 'browser_cookie', array( '_fbp' => 'orphan-context' ) );

		$this->assertSame( 0, $this->cleanup->cleanup() );
		$this->assertIsArray( $this->profiles->get_by_id( $profile['id'] ) );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->profiles->links_table() . ' WHERE profile_id = 900001' ) );
		$this->assertSame( 0, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->contexts->table() . ' WHERE profile_id = 900002' ) );
	}

	public function test_positive_retention_only_removes_old_anonymous_profiles_without_links() {
		global $wpdb;
		$eligible = $this->create_old_profile();
		$linked = $this->create_old_profile();
		$this->profiles->link( $linked['id'], 'fluent_booking', 'booking', '1001' );
		$wpdb->update( $this->profiles->profiles_table(), array( 'last_activity_at' => '2000-01-01 00:00:00' ), array( 'id' => $linked['id'] ) );
		$identified = $this->create_old_profile( 'identified' );
		$fresh = $this->create_old_profile();
		$wpdb->update( $this->profiles->profiles_table(), array( 'last_activity_at' => current_time( 'mysql', true ) ), array( 'id' => $fresh['id'] ) );
		add_filter( 'eventbridge_profile_retention_days', function () { return 30; } );

		$this->assertSame( 1, $this->cleanup->cleanup() );
		$this->assertNull( $this->profiles->get_by_id( $eligible['id'] ) );
		$this->assertIsArray( $this->profiles->get_by_id( $linked['id'] ) );
		$this->assertIsArray( $this->profiles->get_by_id( $identified['id'] ) );
		$this->assertIsArray( $this->profiles->get_by_id( $fresh['id'] ) );
	}

	public function test_open_and_converted_conversions_protect_unlinked_profiles() {
		global $wpdb;
		$open = $this->create_conversion_profile( '2001', EventBridge_Conversion_Repository::STATUS_OPEN );
		$converted = $this->create_conversion_profile( '2002', EventBridge_Conversion_Repository::STATUS_CONVERTED );
		$wpdb->insert( $this->conversions->deliveries_table(), array(
			'conversion_id' => $converted['conversion_id'],
			'event_key' => 'evt_11111111-1111-4111-8111-111111111111',
			'destination_id' => 'meta',
			'event_id' => wp_generate_uuid4(),
			'event_time' => time(),
			'status' => EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED,
			'created_at' => current_time( 'mysql', true ),
			'updated_at' => current_time( 'mysql', true ),
			'succeeded_at' => current_time( 'mysql', true ),
		) );

		$this->assertSame( 0, $this->cleanup->cleanup( 30 ) );
		$this->assertIsArray( $this->profiles->get_by_id( $open['profile_id'] ) );
		$this->assertIsArray( $this->profiles->get_by_id( $converted['profile_id'] ) );
		$this->assertIsArray( $this->conversions->get_by_id( $converted['conversion_id'] ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->conversions->deliveries_table() . ' WHERE conversion_id = %d', $converted['conversion_id'] ) ) );
	}

	public function test_cleanup_batches_each_category_at_one_hundred_and_preview_is_not_limited() {
		global $wpdb;
		for ( $index = 1; $index <= 101; $index++ ) {
			$this->create_old_profile();
			$this->insert_orphan_link( 910000 + $index, 'orphan-link-' . $index );
			$this->contexts->save( 920000 + $index, 'browser_cookie', array( '_fbp' => 'orphan-context-' . $index ) );
		}
		$this->assertSame( 101, $this->cleanup->preview( 30 ) );
		$this->assertSame( 100, $this->cleanup->cleanup( 30 ) );
		$this->assertSame( 1, $this->cleanup->preview( 30 ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->profiles->links_table() ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->contexts->table() ) );
	}

	public function test_detailed_preview_reports_every_category_without_changing_data() {
		global $wpdb;
		$this->create_old_profile();
		$this->insert_orphan_link( 930001, 'preview-orphan-link' );
		$this->contexts->save( 930002, 'browser_cookie', array( '_fbp' => 'preview-orphan-context' ) );
		$preview = $this->cleanup->get_preview( 30 );

		$this->assertSame( array( 'links' => 1, 'contexts' => 1, 'conversion_contexts' => 0, 'profiles' => 1 ), $preview );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->profiles->links_table() . ' WHERE profile_id = 930001' ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->contexts->table() . ' WHERE profile_id = 930002' ) );
	}

	public function test_cleanup_lock_blocks_parallel_run_and_expired_lock_recovers() {
		$this->create_old_profile();
		add_option( EventBridge_Profile_Cleanup::LOCK_OPTION, array( 'token' => 'other-run', 'expires_at' => time() + EventBridge_Profile_Cleanup::LOCK_TTL ), '', false );
		$blocked = $this->cleanup->run_cleanup( 30 );
		$this->assertTrue( $blocked['locked'] );
		$this->assertSame( 0, $blocked['profiles'] );
		$this->assertSame( 'other-run', get_option( EventBridge_Profile_Cleanup::LOCK_OPTION )['token'] );

		update_option( EventBridge_Profile_Cleanup::LOCK_OPTION, array( 'token' => 'expired-run', 'expires_at' => time() - 1 ), false );
		$recovered = $this->cleanup->run_cleanup( 30 );
		$this->assertFalse( $recovered['locked'] );
		$this->assertSame( 1, $recovered['profiles'] );
		$this->assertFalse( get_option( EventBridge_Profile_Cleanup::LOCK_OPTION, false ) );
	}

	public function test_expired_conversion_request_context_is_redacted_without_removing_attribution_or_diagnostics() {
		global $wpdb;
		$record = $this->create_conversion_profile( '3001', EventBridge_Conversion_Repository::STATUS_OPEN );
		$snapshot = array(
			'version' => 1,
			'snapshot_captured_at' => '2000-01-01 00:00:00',
			'selected_touch' => 'last_touch',
			'first_touch' => array(),
			'last_touch' => array( 'version' => 1, 'captured_at' => '2000-01-01T00:00:00+00:00', 'landing_url' => home_url( '/booking/' ) ),
			'browser_context' => array(
				'browser_cookie' => array( '_fbp' => array( 'value' => 'fb.1.1700000000000.browser-1', 'captured_at' => '2000-01-01 00:00:00' ) ),
				'client_request' => array( 'ip_address' => array( 'value' => '203.0.113.42', 'captured_at' => '2000-01-01 00:00:00' ), 'user_agent' => array( 'value' => 'Old booking agent', 'captured_at' => '2000-01-01 00:00:00' ) ),
			),
		);
		$occurrence = array( 'browser_context' => $snapshot['browser_context'], 'event_id' => wp_generate_uuid4() );
		$this->contexts->save( $record['profile_id'], 'client_request', array( 'ip_address' => '203.0.113.42', 'user_agent' => 'Old booking agent' ) );
		$wpdb->update( $this->contexts->table(), array( 'captured_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00' ), array( 'profile_id' => $record['profile_id'], 'context_namespace' => 'client_request' ) );
		$wpdb->update( $this->conversions->table(), array( 'created_at' => '2000-01-01 00:00:00', 'attribution_snapshot' => wp_json_encode( $snapshot ) ), array( 'id' => $record['conversion_id'] ) );
		$wpdb->insert( $this->conversions->deliveries_table(), array(
			'conversion_id' => $record['conversion_id'], 'event_key' => 'evt_11111111-1111-4111-8111-111111111111', 'destination_id' => 'meta',
			'event_id' => $occurrence['event_id'], 'event_time' => time(), 'status' => EventBridge_Conversion_Repository::DELIVERY_RETRYABLE,
			'occurrence' => wp_json_encode( $occurrence ), 'outbound_diagnostics' => wp_json_encode( array( 'privacy_safe' => true ) ),
			'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00',
		) );

		$this->assertSame( 1, $this->cleanup->get_preview( 0 )['conversion_contexts'] );
		$result = $this->cleanup->run_cleanup( 0 );
		$this->assertSame( 1, $result['conversion_contexts'] );
		$redacted_snapshot = json_decode( $this->conversions->get_by_id( $record['conversion_id'] )['attribution_snapshot'], true );
		$this->assertArrayNotHasKey( 'client_request', $redacted_snapshot['browser_context'] );
		$this->assertArrayHasKey( 'browser_cookie', $redacted_snapshot['browser_context'] );
		$delivery = $this->conversions->get_deliveries( $record['conversion_id'] )[0];
		$this->assertArrayNotHasKey( 'client_request', json_decode( $delivery['occurrence'], true )['browser_context'] );
		$this->assertSame( array( 'privacy_safe' => true ), json_decode( $delivery['outbound_diagnostics'], true ) );
		$this->assertArrayNotHasKey( 'client_request', $this->contexts->get_for_profile( $record['profile_id'] ) );
	}

	public function test_expired_conversion_cleanup_does_not_delete_fresh_shared_profile_request_context() {
		global $wpdb;
		$record = $this->create_conversion_profile( '3002', EventBridge_Conversion_Repository::STATUS_OPEN );
		$snapshot = array( 'version' => 1, 'snapshot_captured_at' => '2000-01-01 00:00:00', 'selected_touch' => 'none', 'first_touch' => array(), 'last_touch' => array(), 'browser_context' => array( 'client_request' => array( 'user_agent' => array( 'value' => 'Old snapshot agent', 'captured_at' => '2000-01-01 00:00:00' ) ) ) );
		$wpdb->update( $this->conversions->table(), array( 'created_at' => '2000-01-01 00:00:00', 'attribution_snapshot' => wp_json_encode( $snapshot ) ), array( 'id' => $record['conversion_id'] ) );
		$this->contexts->save( $record['profile_id'], 'client_request', array( 'ip_address' => '203.0.113.84', 'user_agent' => 'Fresh concurrent booking agent' ) );

		$result = $this->cleanup->run_cleanup( 0 );
		$this->assertSame( 1, $result['conversion_contexts'] );
		$profile_context = $this->contexts->get_for_profile( $record['profile_id'] );
		$this->assertSame( 'Fresh concurrent booking agent', $profile_context['client_request']['user_agent']['value'] );
		$redacted_snapshot = json_decode( $this->conversions->get_by_id( $record['conversion_id'] )['attribution_snapshot'], true );
		$this->assertArrayNotHasKey( 'client_request', $redacted_snapshot['browser_context'] );
	}

	public function test_cron_and_manual_hooks_are_registered_on_the_same_cleanup_component() {
		$this->cleanup->init();
		$this->assertSame( 10, has_action( EventBridge_Profile_Cleanup::CLEANUP_HOOK, array( $this->cleanup, 'cleanup' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_eventbridge_profile_cleanup_preview', array( $this->cleanup, 'handle_manual_preview' ) ) );
		$this->assertSame( 10, has_action( 'admin_post_eventbridge_profile_cleanup', array( $this->cleanup, 'handle_manual_cleanup' ) ) );
	}

	private function create_old_profile( $status = 'anonymous' ) {
		global $wpdb;
		$this->sequence++;
		$profile = $this->profiles->get_or_create( hash( 'sha256', 'cleanup-profile-' . $this->sequence, true ) );
		$wpdb->update(
			$this->profiles->profiles_table(),
			array( 'identification_status' => $status, 'last_activity_at' => '2000-01-01 00:00:00' ),
			array( 'id' => $profile['id'] )
		);
		return $this->profiles->get_by_id( $profile['id'] );
	}

	private function create_conversion_profile( $external_id, $status ) {
		global $wpdb;
		$profile = $this->create_old_profile();
		$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', $external_id );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', $external_id );
		$this->conversions->ensure_open( $link, 'fluent_booking', 'booking', $external_id, array( 'evt_11111111-1111-4111-8111-111111111111' ) );
		$conversion_id = (int) $wpdb->insert_id;
		if ( EventBridge_Conversion_Repository::STATUS_CONVERTED === $status ) {
			$wpdb->update( $this->conversions->table(), array( 'status' => $status, 'converted_at' => current_time( 'mysql', true ) ), array( 'id' => $conversion_id ) );
		}
		$wpdb->delete( $this->profiles->links_table(), array( 'id' => $link['id'] ) );
		$wpdb->update( $this->profiles->profiles_table(), array( 'last_activity_at' => '2000-01-01 00:00:00' ), array( 'id' => $profile['id'] ) );
		return array( 'profile_id' => (int) $profile['id'], 'conversion_id' => $conversion_id );
	}

	private function insert_orphan_link( $profile_id, $external_id ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( $this->profiles->links_table(), array(
			'profile_id' => $profile_id,
			'provider' => 'fluent_booking',
			'entity_type' => 'booking',
			'external_id' => $external_id,
			'external_id_hash' => hash( 'sha256', $external_id, true ),
			'created_at' => $now,
			'last_seen_at' => $now,
		) );
	}
}
