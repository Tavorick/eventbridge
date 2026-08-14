<?php

class EventBridge_Conversion_Admin_Test extends WP_UnitTestCase {
	private $profiles;
	private $conversions;
	private $admin;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'EventBridge_Admin' ) ) require_once dirname( __DIR__ ) . '/includes/admin.php';
		if ( ! function_exists( 'submit_button' ) ) require_once ABSPATH . 'wp-admin/includes/template.php';
		$this->profiles = new EventBridge_Profile_Repository(); $this->profiles->ensure_tables();
		$this->conversions = new EventBridge_Conversion_Repository(); $this->conversions->ensure_table();
		$settings = new EventBridge_Settings(); $log = new EventBridge_Log(); $status = new EventBridge_Upgrade_Status(); $fluent = new EventBridge_Fluent_Booking();
		$conditions = new EventBridge_Conditions( array( new EventBridge_WooCommerce_Conditions() ), $settings, $log );
		$registry = new EventBridge_Destination_Registry(); $registry->register( new EventBridge_Meta_Destination( new EventBridge_Meta_CAPI( $settings, $log ) ) );
		$woocommerce = new EventBridge_WooCommerce( new EventBridge_Dispatcher( $registry ), $log, $conditions ); $events = new EventBridge_Events( $woocommerce, $conditions ); $woocommerce->set_events( $events );
		$this->admin = new EventBridge_Admin( $settings, $events, $log, $fluent, $status, $woocommerce, $conditions, $this->conversions );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		global $wpdb;
		unset( $_SERVER['REQUEST_METHOD'], $_POST['conversion_id'], $_POST['_wpnonce'] );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() ); $wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
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
		$profile = $this->profiles->get_or_create( hash( 'sha256', wp_generate_uuid4(), true ) ); $this->profiles->link( $profile['id'], 'fluent_booking', 'booking', '4821' );
		$link = $this->profiles->find_link( 'fluent_booking', 'booking', '4821' ); $this->conversions->ensure_open( $link, 'fluent_booking', 'booking', '4821', $events ); return $this->conversions->get_open()[0];
	}

	private function render() { ob_start(); $this->admin->render_conversions_page(); return ob_get_clean(); }
}
