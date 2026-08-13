<?php

class EventBridge_Fluent_Booking_Test_Query {
	private $records;

	public function __construct( $records ) {
		$this->records = $records;
	}

	public function get() {
		return $this->records;
	}
}

if ( ! class_exists( '\\FluentBooking\\App\\Models\\CalendarSlot' ) ) {
	eval( 'namespace FluentBooking\\App\\Models; class Booking {} class CalendarSlot { public static $records = array(); public static function orderBy( $column, $direction ) { return new \\EventBridge_Fluent_Booking_Test_Query( self::$records ); } }' );
}

class EventBridge_Fluent_Booking_Test_Provider extends EventBridge_Fluent_Booking {
	public function is_available() {
		return true;
	}
}

class EventBridge_Fluent_Booking_Test extends WP_UnitTestCase {
	private $settings;

	public function set_up() {
		parent::set_up();
		$this->settings = new EventBridge_Fluent_Booking_Settings();
		$this->settings->register_settings();
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
	}

	public function tear_down() {
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
		delete_option( EventBridge_Settings::OPTION_NAME );
		parent::tear_down();
	}

	public function test_appointment_types_use_calendar_slot_id_and_title() {
		if ( ! property_exists( '\\FluentBooking\\App\\Models\\CalendarSlot', 'records' ) ) {
			$this->markTestSkipped( 'The installed Fluent Booking model is active.' );
		}

		\FluentBooking\App\Models\CalendarSlot::$records = array(
			(object) array( 'id' => 42, 'title' => 'Intakegesprek' ),
			(object) array( 'id' => 84, 'title' => 'Vervolgafspraak' ),
		);
		$provider = new EventBridge_Fluent_Booking_Test_Provider( $this->settings );

		$this->assertSame(
			array(
				array( 'id' => '42', 'title' => 'Intakegesprek' ),
				array( 'id' => '84', 'title' => 'Vervolgafspraak' ),
			),
			$provider->get_appointment_types()
		);
	}

	public function test_selected_ids_are_normalized_without_touching_meta_settings() {
		$meta = array( 'pixel_id' => '123456789', 'capi_token' => 'secret', 'debug' => true );
		update_option( EventBridge_Settings::OPTION_NAME, $meta, false );
		$sanitized = sanitize_option(
			EventBridge_Fluent_Booking_Settings::OPTION_NAME,
			array( 'followup_event_ids_present' => '1', 'followup_event_ids' => array( ' 42 ', '42', '', array( '84' ), 84 ) )
		);
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, $sanitized, false );

		$this->assertSame( array( '42', '84' ), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );
	}

	public function test_settings_api_save_flow_persists_selected_ids_and_allows_explicit_emptying() {
		$meta = array( 'pixel_id' => '123456789', 'capi_token' => 'secret', 'debug' => true );
		update_option( EventBridge_Settings::OPTION_NAME, $meta, false );

		$submitted = array( 'followup_event_ids_present' => '1', 'followup_event_ids' => array( '42', 84 ) );
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, sanitize_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, $submitted ), false );
		$this->assertSame( array( '42', '84' ), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );

		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, sanitize_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids_present' => '1' ) ), false );
		$this->assertSame( array(), $this->settings->get_followup_event_ids() );
		$this->assertSame( $meta, get_option( EventBridge_Settings::OPTION_NAME ) );
	}

	public function test_connections_group_allows_the_fluent_option_without_reusing_meta_storage() {
		global $new_allowed_options;
		$this->assertContains(
			EventBridge_Fluent_Booking_Settings::OPTION_NAME,
			$new_allowed_options[ EventBridge_Settings::CONNECTIONS_OPTION_GROUP ]
		);
		$this->assertNotSame( EventBridge_Fluent_Booking_Settings::OPTION_NAME, EventBridge_Settings::OPTION_NAME );
	}

	public function test_absent_form_marker_preserves_selection_and_explicit_empty_clears_it() {
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );

		$this->assertSame( array( '42' ), $this->settings->sanitize_settings( array() )['followup_event_ids'] );
		$this->assertSame( array(), $this->settings->sanitize_settings( array( 'followup_event_ids_present' => '1' ) )['followup_event_ids'] );
	}

	public function test_followup_relevance_is_a_pure_event_id_membership_check() {
		update_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME, array( 'followup_event_ids' => array( '42' ) ), false );
		$provider = new EventBridge_Fluent_Booking( $this->settings );

		$this->assertTrue( $provider->is_followup_relevant( (object) array( 'event_id' => 42 ) ) );
		$this->assertFalse( $provider->is_followup_relevant( (object) array( 'event_id' => 84 ) ) );
		$this->assertFalse( $provider->is_followup_relevant( (object) array() ) );
		$this->assertSame( array( '42' ), $this->settings->get_followup_event_ids() );
	}
}
