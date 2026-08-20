<?php

class EventBridge_Conversion_Admin_Test_Fluent extends EventBridge_Fluent_Booking {
	public $requested_ids = array();
	public $presentations = array();
	public $search_results = array();
	public $search_terms = array();

	public function find_conversion_booking_ids( $search ) {
		$this->search_terms[] = (string) $search;
		return isset( $this->search_results[ $search ] ) ? $this->search_results[ $search ] : array();
	}

	public function get_conversion_presentations( array $external_ids ) {
		$this->requested_ids = array_values( array_unique( array_map( 'strval', $external_ids ) ) );
		$result = array();
		foreach ( $this->requested_ids as $external_id ) $result[ $external_id ] = isset( $this->presentations[ $external_id ] ) ? $this->presentations[ $external_id ] : array();
		return $result;
	}
}

class EventBridge_Conversion_Admin_Test extends WP_UnitTestCase {
	private $profiles;
	private $contexts;
	private $conversions;
	private $admin;
	private $fluent;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'EventBridge_Admin' ) ) require_once dirname( __DIR__ ) . '/includes/admin.php';
		if ( ! function_exists( 'submit_button' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->profiles = new EventBridge_Profile_Repository(); $this->profiles->ensure_tables();
		$this->contexts = new EventBridge_Profile_Context_Repository(); $this->contexts->ensure_table();
		$this->conversions = new EventBridge_Conversion_Repository(); $this->conversions->ensure_table();
		$settings = new EventBridge_Settings(); $log = new EventBridge_Log(); $status = new EventBridge_Upgrade_Status(); $this->fluent = new EventBridge_Conversion_Admin_Test_Fluent();
		$conditions = new EventBridge_Conditions( array( new EventBridge_WooCommerce_Conditions() ), $settings, $log );
		$registry = new EventBridge_Destination_Registry(); $registry->register( new EventBridge_Meta_Destination( new EventBridge_Meta_CAPI( $settings, $log ) ) );
		$woocommerce = new EventBridge_WooCommerce( new EventBridge_Dispatcher( $registry ), $log, $conditions ); $events = new EventBridge_Events( $woocommerce, $conditions ); $woocommerce->set_events( $events );
		$this->admin = new EventBridge_Admin( $settings, $events, $log, $this->fluent, $status, $woocommerce, $conditions, $this->conversions, null, $this->profiles, $this->contexts );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		global $wpdb;
		unset( $_SERVER['REQUEST_METHOD'], $_POST['conversion_id'], $_POST['_wpnonce'], $_GET['paged'], $_GET['s'] );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() ); $wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->contexts->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() ); $wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		wp_set_current_user( 0 ); parent::tear_down();
	}

	public function test_open_conversion_renders_nonce_protected_post_action_and_converted_hides_it() {
		$event_key = 'evt_11111111-1111-4111-8111-111111111111'; $conversion = $this->create_conversion( array( $event_key ) );
		$html = $this->render();
		$this->assertStringContainsString( 'method="post"', $html ); $this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'eventbridge_convert_conversion', $html ); $this->assertStringContainsString( 'Sessie geboekt', $html ); $this->assertStringContainsString( '_wpnonce', $html );
		global $wpdb; $wpdb->update( $this->conversions->table(), array( 'status' => EventBridge_Conversion_Repository::STATUS_CONVERTED, 'converted_at' => current_time( 'mysql', true ) ), array( 'id' => $conversion['id'] ) );
		$html = $this->render(); $this->assertStringContainsString( 'Geconverteerd', $html ); $this->assertStringNotContainsString( 'Sessie geboekt', $html );
	}

	public function test_empty_mapping_shows_feedback_and_no_action() {
		$this->create_conversion( array() ); $html = $this->render();
		$this->assertStringContainsString( 'Geen eventmapping', $html ); $this->assertStringNotContainsString( 'Sessie geboekt', $html );
	}

	public function test_live_fluent_presentation_and_allowlisted_details_are_escaped_without_secrets() {
		global $wpdb;
		$event_key = 'evt_11111111-1111-4111-8111-111111111111';
		$conversion = $this->create_conversion( array( $event_key ) );
		$this->assertIsArray( $conversion );
		$this->assertGreaterThan( 0, absint( $conversion['id'] ) );
		$this->fluent->presentations['4821'] = array(
			'first_name' => 'Lars<script>alert(1)</script>', 'last_name' => 'Test', 'phone' => '+32470123456',
			'email' => 'lead@example.test', 'event_title' => 'Intake', 'calendar_name' => 'Praktijk Lars',
		);
		$first = array( 'captured_at' => '2026-08-01T10:00:00+00:00', 'landing_url' => 'https://example.org/intake', 'utm_source' => 'google', 'api_token' => 'ATTRIBUTION_SECRET' );
		$last = array( 'captured_at' => '2026-08-02T10:00:00+00:00', 'landing_url' => 'https://example.org/contact', 'gclid' => 'CLICK-ID', 'hashed_pii' => 'HASHED_PII_SECRET' );
		$this->assertSame( 1, $wpdb->update( $this->profiles->profiles_table(), array( 'first_touch' => wp_json_encode( $first ), 'last_touch' => wp_json_encode( $last ) ), array( 'id' => $conversion['profile_id'] ) ), $wpdb->last_error );
		$this->assertTrue( $this->contexts->save( $conversion['profile_id'], 'browser_cookie', array( '_fbp' => 'fb.1.123', '_fbc' => 'fb.1.456', 'api_token' => 'CONTEXT_SECRET' ) ), $wpdb->last_error );
		$this->assertTrue( $this->conversions->reconcile_deliveries( $conversion['id'], array(
			array(
				'event_key' => $event_key, 'destination_id' => 'meta', 'event_id' => wp_generate_uuid4(), 'event_time' => time(),
				'status' => EventBridge_Conversion_Repository::DELIVERY_PENDING, 'occurrence' => array( 'secret' => 'OCCURRENCE_SECRET' ),
			),
		) ), $wpdb->last_error );
		$stored_deliveries = $this->conversions->get_deliveries( $conversion['id'] );
		$this->assertCount( 1, $stored_deliveries, $wpdb->last_error );
		$delivery_id = absint( $stored_deliveries[0]['id'] );
		$this->assertGreaterThan( 0, $delivery_id );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$claimed = $this->conversions->claim_delivery( $delivery_id );
			$this->assertIsArray( $claimed, $wpdb->last_error );
			$this->assertTrue( $this->conversions->complete_delivery( $delivery_id, $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, 'temporary_failure', 503 ), $wpdb->last_error );
		}
		$admin_deliveries = $this->conversions->get_admin_deliveries( array( $conversion['id'] ) );
		$this->assertArrayHasKey( absint( $conversion['id'] ), $admin_deliveries, $wpdb->last_error );
		$this->assertCount( 1, $admin_deliveries[ absint( $conversion['id'] ) ], $wpdb->last_error );
		$this->assertSame( EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, $admin_deliveries[ absint( $conversion['id'] ) ][0]['status'] );
		$this->assertSame( 2, (int) $admin_deliveries[ absint( $conversion['id'] ) ][0]['attempt_count'] );
		$this->assertSame( 'temporary_failure', $admin_deliveries[ absint( $conversion['id'] ) ][0]['last_error_code'] );

		$html = $this->render();
		$this->assertStringContainsString( 'Lars&lt;script&gt;alert(1)&lt;/script&gt; Test', $html );
		$this->assertStringContainsString( '+32470123456', $html );
		$this->assertStringContainsString( 'lead@example.test', $html );
		$this->assertStringContainsString( 'Intake', $html );
		$this->assertStringContainsString( 'Praktijk Lars', $html );
		$this->assertStringContainsString( 'utm_source', $html );
		$this->assertStringContainsString( 'CLICK-ID', $html );
		$this->assertStringContainsString( 'fb.1.123', $html );
		$this->assertStringContainsString( 'temporary_failure', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringNotContainsString( 'ATTRIBUTION_SECRET', $html );
		$this->assertStringNotContainsString( 'HASHED_PII_SECRET', $html );
		$this->assertStringNotContainsString( 'CONTEXT_SECRET', $html );
		$this->assertStringNotContainsString( 'OCCURRENCE_SECRET', $html );
	}

	public function test_missing_fluent_booking_keeps_canonical_conversion_visible() {
		$this->create_conversion( array( 'evt_11111111-1111-4111-8111-111111111111' ) );
		$html = $this->render();
		$this->assertStringContainsString( 'Niet beschikbaar', $html );
		$this->assertStringContainsString( 'fluent_booking', $html );
		$this->assertStringContainsString( 'Booking #4821', $html );
		$this->assertStringContainsString( 'Sessie geboekt', $html );
	}

	public function test_third_page_after_one_hundred_records_is_reachable_and_batches_only_that_page() {
		$profile = $this->profiles->get_or_create( hash( 'sha256', 'pagination-profile', true ) );
		for ( $id = 1; $id <= 101; $id++ ) {
			$this->profiles->link( $profile['id'], 'fluent_booking', 'booking', (string) $id );
			$link = $this->profiles->find_link( 'fluent_booking', 'booking', (string) $id );
			$this->conversions->ensure_open( $link, 'fluent_booking', 'booking', (string) $id, array( 'evt_11111111-1111-4111-8111-111111111111' ) );
		}
		$_GET['paged'] = '3';
		$seen = array();
		for ( $page = 1; $page <= 3; $page++ ) $seen = array_merge( $seen, wp_list_pluck( $this->conversions->get_for_admin( $page, 50 )['records'], 'external_id' ) );
		$this->assertCount( 101, $seen );
		$this->assertCount( 101, array_unique( $seen ) );
		$html = $this->render();
		$this->assertStringContainsString( '101 conversies', $html );
		$this->assertCount( 1, $this->fluent->requested_ids );
		$this->assertStringContainsString( 'Booking #' . $this->fluent->requested_ids[0], $html );
	}

	public function test_canonical_search_filters_by_booking_conversion_provider_and_status() {
		$first = $this->create_conversion_for( '7001' );
		$this->create_conversion_for( '7002', 'custom_provider' );

		$_GET['s'] = '7001';
		$html = $this->render();
		$this->assertStringContainsString( 'Booking #7001', $html );
		$this->assertStringNotContainsString( 'Booking #7002', $html );

		$_GET['s'] = (string) $first['id'];
		$html = $this->render();
		$this->assertStringContainsString( 'Booking #7001', $html );

		$_GET['s'] = 'custom provider';
		$html = $this->render();
		$this->assertStringContainsString( 'Booking #7002', $html );
		$this->assertStringNotContainsString( 'Booking #7001', $html );

		$_GET['s'] = 'open';
		$html = $this->render();
		$this->assertStringContainsString( '2 conversies', $html );
	}

	public function test_live_fluent_search_finds_record_outside_normal_first_page() {
		for ( $id = 1; $id <= 501; $id++ ) $this->create_conversion_for( (string) $id );
		$this->fluent->search_results['Lars'] = array( '1' );
		$_GET['s'] = 'Lars';
		$html = $this->render();
		$this->assertStringContainsString( 'Booking #1', $html );
		$this->assertStringContainsString( '1 conversie', $html );
		$this->assertSame( array( 'Lars' ), $this->fluent->search_terms );
		$this->assertSame( array( '1' ), $this->fluent->requested_ids );
	}

	public function test_search_results_paginate_and_keep_search_term_in_links() {
		$ids = array();
		for ( $id = 1; $id <= 101; $id++ ) { $this->create_conversion_for( (string) $id ); $ids[] = (string) $id; }
		$this->fluent->search_results['client'] = $ids;
		$_GET['s'] = 'client'; $_GET['paged'] = '3';
		$html = $this->render();
		$this->assertStringContainsString( '101 conversies', $html );
		$this->assertStringContainsString( 's=client', $html );
		$this->assertStringContainsString( 'paged=2', $html );
		$this->assertStringContainsString( 'Filter wissen', $html );
		$this->assertCount( 1, $this->fluent->requested_ids );
	}

	public function test_empty_and_hostile_search_terms_are_safe() {
		$this->create_conversion_for( '8001' );
		$_GET['s'] = '   ';
		$this->assertStringContainsString( 'Booking #8001', $this->render() );
		$this->assertSame( array(), $this->fluent->search_terms );

		$_GET['s'] = '\"><script>alert(1)</script>\' OR 1=1 --';
		$html = $this->render();
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'Geen conversies gevonden', $html );
	}

	public function test_page_render_performs_no_mutating_queries() {
		$this->create_conversion( array( 'evt_11111111-1111-4111-8111-111111111111' ) );
		$_GET['s'] = '4821';
		$mutations = array();
		$observer = function ( $query ) use ( &$mutations ) { if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|TRUNCATE)\b/i', $query ) ) $mutations[] = $query; return $query; };
		add_filter( 'query', $observer );
		try { $this->render(); } finally { remove_filter( 'query', $observer ); }
		$this->assertSame( array(), $mutations );
	}

	public function test_conversion_admin_hook_is_registered_and_page_requires_manage_options() {
		$this->admin->init(); $this->assertSame( 10, has_action( 'admin_post_eventbridge_convert_conversion', array( $this->admin, 'handle_conversion_action' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) ); $this->expectException( WPDieException::class ); $this->admin->render_conversions_page();
	}

	public function test_conversion_action_rejects_get_and_missing_capability_before_state_change() {
		$_SERVER['REQUEST_METHOD'] = 'GET'; $this->expectException( WPDieException::class ); $this->admin->handle_conversion_action();
	}

	public function test_conversion_action_requires_manage_options_for_post() {
		$_SERVER['REQUEST_METHOD'] = 'POST'; wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class ); $this->admin->handle_conversion_action();
	}

	public function test_conversion_action_requires_conversion_specific_nonce() {
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['conversion_id'] = '4821';
		$this->expectException( WPDieException::class ); $this->admin->handle_conversion_action();
	}

	private function create_conversion( array $events ) {
		return $this->create_conversion_for( '4821', 'fluent_booking', $events );
	}

	private function create_conversion_for( $external_id, $provider = 'fluent_booking', $events = null ) {
		$events = is_array( $events ) ? $events : array( 'evt_11111111-1111-4111-8111-111111111111' );
		$profile = $this->profiles->get_or_create( hash( 'sha256', wp_generate_uuid4(), true ) );
		$this->profiles->link( $profile['id'], $provider, 'booking', $external_id );
		$link = $this->profiles->find_link( $provider, 'booking', $external_id );
		$this->conversions->ensure_open( $link, $provider, 'booking', $external_id, $events );
		return $this->conversions->get_by_id( $GLOBALS['wpdb']->insert_id );
	}

	private function render() { ob_start(); $this->admin->render_conversions_page(); return ob_get_clean(); }
}
