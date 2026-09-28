<?php

class EventBridge_Therapist_Appointments_Test_Fluent extends EventBridge_Fluent_Booking {
	public $available = true;
	public $owned_ids = array();
	public $owners = array();
	public $presentations = array();
	public $searches = array();
	public function is_available() { return $this->available; }

	public function find_owned_conversion_booking_ids( $user_id, $search = '' ) {
		$this->searches[] = array( absint( $user_id ), (string) $search );
		return isset( $this->owned_ids[ absint( $user_id ) ] ) ? $this->owned_ids[ absint( $user_id ) ] : array();
	}

	public function user_owns_conversion_booking( $external_id, $user_id ) {
		return isset( $this->owners[ (string) $external_id ] ) && absint( $this->owners[ (string) $external_id ] ) === absint( $user_id );
	}

	public function get_conversion_presentations( array $external_ids ) {
		$result = array();
		foreach ( $external_ids as $external_id ) {
			$external_id = (string) $external_id;
			$result[ $external_id ] = isset( $this->presentations[ $external_id ] ) ? $this->presentations[ $external_id ] : array();
		}
		return $result;
	}
}

class EventBridge_Therapist_Appointments_Test_Service extends EventBridge_Conversion_Service {
	public $calls = array();
	public $result = array( 'code' => 'converted', 'feedback_code' => 'converted' );
	public function __construct() {}
	public function execute( $conversion_id ) {
		$this->calls[] = absint( $conversion_id );
		return $this->result;
	}
}

class EventBridge_Therapist_Appointments_Test_Admin_Bar {
	public $nodes = array();
	public function add_node( $node ) { $this->nodes[] = $node; }
}

class EventBridge_Therapist_Appointments_Admin_Test extends WP_UnitTestCase {
	private $profiles;
	private $conversions;
	private $fluent;
	private $service;
	private $admin;
	private $author_id;

	public function set_up() {
		parent::set_up();
		$this->profiles = new EventBridge_Profile_Repository(); $this->profiles->ensure_tables();
		$this->conversions = new EventBridge_Conversion_Repository(); $this->conversions->ensure_table();
		$this->fluent = new EventBridge_Therapist_Appointments_Test_Fluent();
		$this->service = new EventBridge_Therapist_Appointments_Test_Service();
		$this->admin = new EventBridge_Therapist_Appointments_Admin( $this->fluent, $this->conversions, $this->service );
		$this->author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $this->author_id );
	}

	public function tear_down() {
		global $wpdb, $menu, $submenu;
		wp_dequeue_style( 'eventbridge-therapist-appointments' );
		unset( $_SERVER['REQUEST_METHOD'], $_POST['conversion_id'], $_POST['_wpnonce'], $_GET['paged'], $_GET['s'], $_GET['eventbridge_appointment_status'] );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		$menu = array(); $submenu = array(); wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_author_gets_standalone_menu_and_admin_bar_link() {
		global $menu;
		$this->admin->add_admin_menu();
		$slugs = array_map( function ( $item ) { return isset( $item[2] ) ? $item[2] : ''; }, (array) $menu );
		$this->assertContains( EventBridge_Therapist_Appointments_Admin::PAGE_SLUG, $slugs );
		$bar = new EventBridge_Therapist_Appointments_Test_Admin_Bar();
		$this->admin->add_admin_bar_link( $bar );
		$this->assertCount( 1, $bar->nodes );
		$this->assertSame( 'Telefoonafspraken', $bar->nodes[0]['title'] );
		$this->assertStringContainsString( 'page=' . EventBridge_Therapist_Appointments_Admin::PAGE_SLUG, $bar->nodes[0]['href'] );
	}

	public function test_page_hooks_menu_admin_bar_and_assets_are_absent_without_fluent_booking() {
		global $menu;
		$this->fluent->available = false;
		$this->admin->init();
		$this->assertFalse( has_action( 'admin_menu', array( $this->admin, 'add_admin_menu' ) ) );
		$this->assertFalse( has_action( 'admin_bar_menu', array( $this->admin, 'add_admin_bar_link' ) ) );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( $this->admin, 'enqueue_assets' ) ) );
		$this->assertFalse( has_action( 'admin_post_' . EventBridge_Therapist_Appointments_Admin::ACTION, array( $this->admin, 'handle_conversion_action' ) ) );

		$this->admin->add_admin_menu();
		$slugs = array_map( function ( $item ) { return isset( $item[2] ) ? $item[2] : ''; }, (array) $menu );
		$this->assertNotContains( EventBridge_Therapist_Appointments_Admin::PAGE_SLUG, $slugs );
		$bar = new EventBridge_Therapist_Appointments_Test_Admin_Bar();
		$this->admin->add_admin_bar_link( $bar );
		$this->assertCount( 0, $bar->nodes );
		$this->admin->enqueue_assets( 'toplevel_page_' . EventBridge_Therapist_Appointments_Admin::PAGE_SLUG );
		$this->assertFalse( wp_style_is( 'eventbridge-therapist-appointments', 'enqueued' ) );
	}

	public function test_stylesheet_is_loaded_only_on_the_standalone_page() {
		$this->admin->enqueue_assets( 'dashboard_page_unrelated' );
		$this->assertFalse( wp_style_is( 'eventbridge-therapist-appointments', 'enqueued' ) );
		$this->admin->enqueue_assets( 'toplevel_page_' . EventBridge_Therapist_Appointments_Admin::PAGE_SLUG );
		$this->assertTrue( wp_style_is( 'eventbridge-therapist-appointments', 'enqueued' ) );
	}

	public function test_page_renders_only_current_users_records_with_simple_escaped_content() {
		$own = $this->create_conversion( '4821' );
		$this->create_conversion( '4822' );
		$this->fluent->owned_ids[ $this->author_id ] = array( '4821' );
		$this->fluent->presentations['4821'] = array(
			'start_time' => '2026-09-20 14:30:00', 'first_name' => 'Eigen<script>alert(1)</script>', 'last_name' => 'Cliënt',
			'email' => 'eigen@example.test', 'phone' => '+32470000001', 'event_title' => 'Telefonische afspraak', 'calendar_name' => 'Praktijk',
		);
		$_GET['s'] = 'eigen@example.test';
		$html = $this->render();

		$this->assertSame( array( array( $this->author_id, 'eigen@example.test' ) ), $this->fluent->searches );
		$this->assertStringContainsString( 'Eigen&lt;script&gt;alert(1)&lt;/script&gt; Cliënt', $html );
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( 'eigen@example.test', $html );
		$this->assertStringContainsString( 'Telefonische afspraak', $html );
		$this->assertStringContainsString( 'Sessie geboekt', $html );
		$this->assertStringContainsString( 'eventbridge-therapist__hero', $html );
		$this->assertStringContainsString( 'eventbridge-therapist__status is-open', $html );
		$this->assertStringContainsString( 'data-label="Status"', $html );
		$this->assertStringContainsString( 'value="' . $own['id'] . '"', $html );
		$this->assertStringNotContainsString( '4822', $html );
		$this->assertStringNotContainsString( 'Details bekijken', $html );
		$this->assertStringNotContainsString( 'CAPI', $html );
	}

	public function test_administrator_is_still_scoped_to_own_host_id() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		$this->create_conversion( '7001' ); $this->create_conversion( '7002' );
		$this->fluent->owned_ids[ $admin_id ] = array( '7002' );
		$this->fluent->presentations['7002'] = array( 'start_time' => '2026-09-21 09:00:00', 'first_name' => 'Eigen', 'last_name' => 'Admin', 'email' => 'admin@example.test', 'phone' => '+32470000002', 'event_title' => 'Belafspraak' );
		$html = $this->render();
		$this->assertStringContainsString( 'Eigen Admin', $html );
		$this->assertStringNotContainsString( '7001', $html );
	}

	public function test_owner_gateway_blocks_other_and_non_fluent_records_before_service() {
		$own = $this->create_conversion( '4821' );
		$other = $this->create_conversion( '4822' );
		$non_fluent = $this->create_conversion( '9001', 'other_provider' );
		$this->fluent->owners['4821'] = $this->author_id;
		$this->fluent->owners['4822'] = self::factory()->user->create( array( 'role' => 'author' ) );

		$this->assertSame( 'converted', $this->admin->execute_owned_conversion( $own['id'], $this->author_id )['code'] );
		$this->assertWPError( $this->admin->execute_owned_conversion( $other['id'], $this->author_id ) );
		$this->assertWPError( $this->admin->execute_owned_conversion( $non_fluent['id'], $this->author_id ) );
		$this->assertSame( array( absint( $own['id'] ) ), $this->service->calls );
	}

	public function test_subscriber_cannot_render_and_get_request_cannot_execute() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$bar = new EventBridge_Therapist_Appointments_Test_Admin_Bar(); $this->admin->add_admin_bar_link( $bar );
		$this->assertSame( array(), $bar->nodes );
		try {
			$this->admin->render_page();
			$this->fail( 'Subscriber render should have died.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->service->calls );
		}
		wp_set_current_user( $this->author_id ); $_SERVER['REQUEST_METHOD'] = 'GET';
		$this->expectException( WPDieException::class );
		$this->admin->handle_conversion_action();
	}

	public function test_post_with_valid_nonce_still_rejects_another_hosts_conversion() {
		$other = $this->create_conversion( '4822' );
		$this->fluent->owners['4822'] = self::factory()->user->create( array( 'role' => 'author' ) );
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['conversion_id'] = (string) $other['id'];
		$_POST['_wpnonce'] = wp_create_nonce( EventBridge_Therapist_Appointments_Admin::ACTION . '_' . $other['id'] );
		try {
			$this->admin->handle_conversion_action();
			$this->fail( 'Another host conversion should have died.' );
		} catch ( WPDieException $exception ) {
			$this->assertSame( array(), $this->service->calls );
		}
	}

	public function test_post_requires_conversion_specific_nonce() {
		$_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['conversion_id'] = '4821';
		$this->expectException( WPDieException::class );
		$this->admin->handle_conversion_action();
	}

	public function test_permanent_legacy_failure_shows_actionable_feedback_without_retry_or_internal_code() {
		$conversion = $this->create_conversion( '4821' );
		$this->fluent->owned_ids[ $this->author_id ] = array( '4821' );
		$this->fluent->presentations['4821'] = array( 'first_name' => 'Test', 'last_name' => 'Client', 'event_title' => 'Telefonische afspraak' );
		$this->assertTrue( $this->conversions->reconcile_deliveries( $conversion['id'], array( array( 'event_key' => 'evt_11111111-1111-4111-8111-111111111111', 'destination_id' => '', 'event_id' => wp_generate_uuid4(), 'event_time' => time(), 'status' => EventBridge_Conversion_Repository::DELIVERY_BLOCKED, 'occurrence' => null, 'error_code' => 'legacy_recovery_unavailable' ) ) ) );
		$_GET['eventbridge_appointment_status'] = 'legacy_recovery_unavailable';
		$html = $this->render();
		$this->assertStringContainsString( 'Deze oudere afspraak mist voldoende oorspronkelijke trackinggegevens.', $html );
		$this->assertStringContainsString( 'Kan niet veilig worden verwerkt', $html );
		$this->assertStringNotContainsString( 'legacy_recovery_unavailable', $html );
		$this->assertStringNotContainsString( '>Opnieuw proberen<', $html );
		$this->assertStringNotContainsString( '>Sessie geboekt<', $html );
	}

	public function test_retryable_delivery_shows_retry_feedback_and_retry_button() {
		$conversion = $this->create_conversion( '4821' );
		$this->fluent->owned_ids[ $this->author_id ] = array( '4821' );
		$this->assertTrue( $this->conversions->reconcile_deliveries( $conversion['id'], array( array( 'event_key' => 'evt_11111111-1111-4111-8111-111111111111', 'destination_id' => 'meta', 'event_id' => wp_generate_uuid4(), 'event_time' => time(), 'status' => EventBridge_Conversion_Repository::DELIVERY_PENDING, 'occurrence' => array( 'safe' => true ), 'error_code' => '' ) ) ) );
		$delivery = $this->conversions->get_deliveries( $conversion['id'] )[0]; $claimed = $this->conversions->claim_delivery( $delivery['id'] );
		$this->assertTrue( $this->conversions->complete_delivery( $delivery['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, 'timeout' ) );
		$_GET['eventbridge_appointment_status'] = 'retryable_incomplete';
		$html = $this->render();
		$this->assertStringContainsString( 'Probeer het later opnieuw.', $html );
		$this->assertStringContainsString( 'Tijdelijk probleem', $html );
		$this->assertStringContainsString( '>Opnieuw proberen<', $html );
		$this->assertStringNotContainsString( 'timeout', $html );
	}

	private function create_conversion( $external_id, $provider = 'fluent_booking' ) {
		$profile = $this->profiles->get_or_create( hash( 'sha256', wp_generate_uuid4(), true ) );
		$this->profiles->link( $profile['id'], $provider, 'booking', $external_id );
		$link = $this->profiles->find_link( $provider, 'booking', $external_id );
		$this->conversions->ensure_open( $link, $provider, 'booking', $external_id, array( 'evt_11111111-1111-4111-8111-111111111111' ) );
		return $this->conversions->get_by_id( $GLOBALS['wpdb']->insert_id );
	}

	private function render() { ob_start(); $this->admin->render_page(); return ob_get_clean(); }
}
