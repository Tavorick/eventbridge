<?php

class EventBridge_Admin_Test_Fluent_Booking extends EventBridge_Fluent_Booking {
	private $types;

	public function __construct( $types, EventBridge_Fluent_Booking_Settings $settings ) {
		parent::__construct( $settings );
		$this->types = $types;
	}

	public function is_available() {
		return true;
	}

	public function get_appointment_types() {
		return $this->types;
	}
}

class EventBridge_Admin_Test extends WP_UnitTestCase {
	private $settings;
	private $admin;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'EventBridge_Admin' ) ) {
			require_once dirname( __DIR__ ) . '/includes/admin.php';
		}
		if ( ! function_exists( 'submit_button' ) || ! function_exists( 'add_menu_page' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );

		$this->settings = new EventBridge_Settings();
		$log            = new EventBridge_Log();
		$status         = new EventBridge_Upgrade_Status();
		$fluent         = new EventBridge_Fluent_Booking();
		$conditions     = new EventBridge_Conditions( array( new EventBridge_WooCommerce_Conditions() ), $this->settings, $log );
		$capi           = new EventBridge_Meta_CAPI( $this->settings, $log );
		$registry       = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $capi ) );
		$woocommerce    = new EventBridge_WooCommerce( new EventBridge_Dispatcher( $registry ), $log, $conditions );
		$events         = new EventBridge_Events( $woocommerce, $conditions );
		$woocommerce->set_events( $events );
		$this->admin = new EventBridge_Admin( $this->settings, $events, $log, $fluent, $status, $woocommerce, $conditions );

		$this->settings->set_admin( $this->admin );
		$this->settings->register_settings();
		update_option(
			EventBridge_Settings::OPTION_NAME,
			array(
				'pixel_id'   => '123456789',
				'capi_token' => 'stored-secret-token',
				'debug'      => true,
			),
			false
		);
	}

	public function tear_down() {
		delete_option( EventBridge_Settings::OPTION_NAME );
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_admin_menu_registers_the_split_pages() {
		global $submenu;

		$this->admin->add_admin_menu();
		$slugs = wp_list_pluck( $submenu['eventbridge'], 2 );

		$this->assertContains( EventBridge_Admin::EVENTS_PAGE_SLUG, $slugs );
		$this->assertContains( EventBridge_Admin::CONNECTIONS_PAGE_SLUG, $slugs );
		$this->assertContains( EventBridge_Admin::CONVERSIONS_PAGE_SLUG, $slugs );
		$this->assertContains( EventBridge_Admin::SETTINGS_PAGE_SLUG, $slugs );
	}

	public function test_events_page_contains_only_event_management_ui() {
		$html = $this->render_page( 'render_events_page' );

		$this->assertStringContainsString( 'EventBridge Events', $html );
		$this->assertStringContainsString( 'id="event-form"', $html );
		$this->assertStringContainsString( 'page=eventbridge-events', $html );
		$this->assertStringNotContainsString( 'Meta-koppeling', $html );
		$this->assertStringNotContainsString( '>Diagnose<', $html );
	}

	public function test_connections_page_renders_meta_without_exposing_the_stored_token() {
		$html = $this->render_page( 'render_connections_page' );

		$this->assertStringContainsString( 'EventBridge Koppelingen', $html );
		$this->assertStringContainsString( 'Meta-koppeling', $html );
		$this->assertStringContainsString( 'Meta Pixel ID', $html );
		$this->assertStringContainsString( 'CAPI-token', $html );
		$this->assertStringContainsString( 'Token ingesteld', $html );
		$this->assertStringContainsString( 'name="eventbridge_meta_settings[debug]" value="1"', $html );
		$this->assertStringNotContainsString( 'stored-secret-token', $html );
		$this->assertStringNotContainsString( 'id="event-form"', $html );
		$this->assertStringNotContainsString( '>Diagnose<', $html );
	}

	public function test_connections_page_renders_the_fluent_booking_panel_without_meta_configuration() {
		$html = $this->render_page( 'render_connections_page' );

		$this->assertStringContainsString( '>Fluent Booking<', $html );
		$this->assertStringContainsString( 'Bestaande selecties blijven ongewijzigd bewaard.', $html );
		$this->assertStringNotContainsString( 'fluent_booking_followup_event_ids', $html );
	}

	public function test_connections_page_renders_selected_fluent_ids_in_its_own_save_form() {
		$fluent_settings = new EventBridge_Fluent_Booking_Settings();
		$fluent_settings->register_settings();
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );
		$this->replace_fluent_booking(
			new EventBridge_Admin_Test_Fluent_Booking(
				array(
					array( 'id' => '42', 'title' => 'Intake' ),
					array( 'id' => '84', 'title' => 'Vervolg' ),
				),
				$fluent_settings
			)
		);

		$html = $this->render_page( 'render_connections_page' );
		$this->assertStringContainsString( 'id="eventbridge-connections-settings-form"', $html );
		$this->assertStringContainsString( 'name="option_page" value="eventbridge_connections_settings_group"', $html );
		$this->assertStringContainsString( 'name="eventbridge_fluent_booking_settings[followups_present]" value="1"', $html );
		$this->assertStringContainsString( 'value="42" checked=', $html );
		$this->assertStringNotContainsString( 'value="84" checked=', $html );
	}

	public function test_settings_page_renders_diagnostics_and_preserves_meta_values_on_submit() {
		$html = $this->render_page( 'render_settings_page' );

		$this->assertStringContainsString( '>Diagnose<', $html );
		$this->assertStringContainsString( 'Debugmodus', $html );
		$this->assertStringContainsString( 'name="eventbridge_meta_settings[pixel_id]" value="123456789"', $html );
		$this->assertStringNotContainsString( 'Meta-koppeling', $html );
		$this->assertStringNotContainsString( 'CAPI-token', $html );
		$this->assertStringNotContainsString( 'stored-secret-token', $html );

		$sanitized = $this->settings->sanitize_settings(
			array(
				'pixel_id' => '123456789',
				'debug'    => '0',
			)
		);

		$this->assertSame( 'stored-secret-token', $sanitized['capi_token'] );
		$this->assertFalse( $sanitized['debug'] );
	}

	public function test_connection_submit_preserves_the_existing_debug_setting() {
		$sanitized = $this->settings->sanitize_settings(
			array(
				'pixel_id' => '987654321',
				'debug'    => '1',
			)
		);

		$this->assertSame( '987654321', $sanitized['pixel_id'] );
		$this->assertSame( 'stored-secret-token', $sanitized['capi_token'] );
		$this->assertTrue( $sanitized['debug'] );
	}

	private function render_page( $method ) {
		ob_start();
		$this->admin->$method();

		return ob_get_clean();
	}

	private function replace_fluent_booking( EventBridge_Fluent_Booking $fluent_booking ) {
		$log        = new EventBridge_Log();
		$status     = new EventBridge_Upgrade_Status();
		$conditions = new EventBridge_Conditions( array( new EventBridge_WooCommerce_Conditions() ), $this->settings, $log );
		$capi       = new EventBridge_Meta_CAPI( $this->settings, $log );
		$registry   = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $capi ) );
		$woocommerce = new EventBridge_WooCommerce( new EventBridge_Dispatcher( $registry ), $log, $conditions );
		$events      = new EventBridge_Events( $woocommerce, $conditions );
		$woocommerce->set_events( $events );
		$this->admin = new EventBridge_Admin( $this->settings, $events, $log, $fluent_booking, $status, $woocommerce, $conditions );
	}
}
