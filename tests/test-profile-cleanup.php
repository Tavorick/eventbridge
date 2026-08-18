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
		remove_action( 'admin_post_eventbridge_profile_cleanup', array( $this->cleanup, 'handle_manual_cleanup' ) );
		remove_all_filters( 'eventbridge_profile_retention_days' );
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

	public function test_open_conversion_protects_unlinked_profile_but_converted_conversion_does_not() {
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

		$this->assertSame( 1, $this->cleanup->cleanup( 30 ) );
		$this->assertIsArray( $this->profiles->get_by_id( $open['profile_id'] ) );
		$this->assertNull( $this->profiles->get_by_id( $converted['profile_id'] ) );
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

	public function test_cron_and_manual_hooks_are_registered_on_the_same_cleanup_component() {
		$this->cleanup->init();
		$this->assertSame( 10, has_action( EventBridge_Profile_Cleanup::CLEANUP_HOOK, array( $this->cleanup, 'cleanup' ) ) );
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
