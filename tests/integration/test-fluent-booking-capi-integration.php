<?php

use FluentBooking\App\Models\Booking;
use FluentBooking\App\Models\Calendar;
use FluentBooking\App\Models\CalendarSlot;
use FluentBooking\App\Services\BookingService;
use FluentBooking\Database\DBMigrator;

class EventBridge_Fluent_Booking_CAPI_Integration_Test_Log extends EventBridge_Log {
	public $records = array();

	public function log( $level, $source, $message, $details = array() ) {
		$this->records[] = compact( 'level', 'source', 'message', 'details' );
		return true;
	}
}

class EventBridge_Fluent_Booking_CAPI_Integration_Test extends WP_UnitTestCase {
	private $profiles;
	private $contexts;
	private $conversions;
	private $requests;
	private $log;
	private $created_booking_id;
	private $created_calendar_id;
	private $created_slot_id;
	private $previous_profile_cookie;

	public function set_up() {
		parent::set_up();
		DBMigrator::run();
		$this->profiles    = new EventBridge_Profile_Repository();
		$this->contexts    = new EventBridge_Profile_Context_Repository();
		$this->conversions = new EventBridge_Conversion_Repository();
		$this->requests    = array();
		$this->profiles->ensure_tables();
		$this->contexts->ensure_table();
		$this->conversions->ensure_table();
		$this->previous_profile_cookie = isset( $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] ) ? $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] : null;
		$_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] = str_repeat( 'A', 43 );
		update_option( EventBridge_Settings::OPTION_NAME, array( 'pixel_id' => '123456789', 'capi_token' => 'integration-token', 'debug' => false ), false );
		add_filter( 'pre_http_request', array( $this, 'block_or_mock_http' ), PHP_INT_MIN, 3 );
		add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MIN );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'block_or_mock_http' ), PHP_INT_MIN );
		remove_filter( 'pre_wp_mail', '__return_true', PHP_INT_MIN );
		if ( null === $this->previous_profile_cookie ) {
			unset( $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] );
		} else {
			$_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ] = $this->previous_profile_cookie;
		}
		delete_option( EventBridge_Settings::OPTION_NAME );
		delete_option( EventBridge_Events::OPTION_NAME );
		delete_option( EventBridge_Fluent_Booking_Settings::OPTION_NAME );
		global $wpdb;
		if ( $this->created_booking_id ) {
			if ( function_exists( 'as_unschedule_all_actions' ) && $this->created_slot_id ) {
				as_unschedule_all_actions( 'fluent_booking/run_booking_integrations_for_scheduled', array( $this->created_booking_id, $this->created_slot_id ), 'fluent-booking' );
				as_unschedule_all_actions( 'fluent_booking/after_booking_scheduled_async', array( $this->created_booking_id, $this->created_slot_id ), 'fluent-booking' );
			}
			$wpdb->delete( $wpdb->prefix . 'fcal_booking_activity', array( 'booking_id' => $this->created_booking_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'fcal_booking_meta', array( 'booking_id' => $this->created_booking_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'fcal_booking_hosts', array( 'booking_id' => $this->created_booking_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'fcal_bookings', array( 'id' => $this->created_booking_id ), array( '%d' ) );
		}
		if ( $this->created_slot_id ) {
			$wpdb->delete( $wpdb->prefix . 'fcal_calendar_events', array( 'id' => $this->created_slot_id ), array( '%d' ) );
		}
		if ( $this->created_calendar_id ) {
			$wpdb->delete( $wpdb->prefix . 'fcal_calendars', array( 'id' => $this->created_calendar_id ), array( '%d' ) );
		}
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->deliveries_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->conversions->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->contexts->table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->links_table() );
		$wpdb->query( 'TRUNCATE TABLE ' . $this->profiles->profiles_table() );
		parent::tear_down();
	}

	public function block_or_mock_http( $preempt, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://graph.facebook.com/' ) ) {
			return new WP_Error( 'unexpected_external_request', 'The Fluent integration test blocks all external HTTP requests.' );
		}

		$this->requests[] = array( 'url' => $url, 'args' => $args );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( array( 'events_received' => 1, 'messages' => array(), 'fbtrace_id' => 'TEST_TRACE' ) ),
			'response' => array( 'code' => 200, 'message' => 'OK' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function test_real_fluent_booking_reaches_the_confirmed_meta_contract() {
		$user_id  = self::factory()->user->create( array( 'user_email' => 'host@example.test' ) );
		$calendar = Calendar::create(
			array(
				'user_id'         => $user_id,
				'title'           => 'Integration calendar',
				'slug'            => 'integration-calendar',
				'settings'        => array(),
				'status'          => 'active',
				'type'            => 'simple',
				'event_type'      => 'scheduling',
				'account_type'    => 'free',
				'visibility'      => 'private',
				'author_timezone' => 'UTC',
			)
		);
		$this->created_calendar_id = (int) $calendar->id;
		$slot = CalendarSlot::create(
			array(
				'user_id'           => $user_id,
				'calendar_id'       => $calendar->id,
				'duration'          => 30,
				'title'             => 'Session booked integration',
				'slug'              => 'session-booked-integration',
				'settings'          => array( 'booking_form' => array() ),
				'location_settings' => array(),
				'status'            => 'active',
				'type'              => 'free',
				'event_type'        => 'single',
				'max_book_per_slot' => 1,
			)
		);
		$this->created_slot_id = (int) $slot->id;
		$event_key = $this->store_test_event();
		update_option(
			EventBridge_Fluent_Booking_Settings::OPTION_NAME,
			array( 'followups' => array( (string) $slot->id => array( 'conversion_event_ids' => array( $event_key ) ) ) ),
			false
		);

		$booking = BookingService::createBooking(
			array(
				'event_id'          => $slot->id,
				'email'             => 'invitee@example.test',
				'phone'             => '+32 470 12 34 56',
				'first_name'        => 'Test',
				'last_name'         => 'Invitee',
				'person_time_zone'  => 'UTC',
				'start_time'        => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
				'status'            => 'scheduled',
				'location_details'  => 'Integration test',
				'source'            => 'web',
				'source_url'        => home_url( '/integration-booking/' ),
			),
			$slot
		);
		$this->assertInstanceOf( Booking::class, $booking );
		$this->created_booking_id = (int) $booking->id;

		$link = $this->profiles->find_link( 'fluent_booking', 'booking', (string) $booking->id );
		$this->assertIsArray( $link );
		$open = $this->conversions->get_open();
		$this->assertCount( 1, $open );
		$this->assertSame( (string) $booking->id, $open[0]['external_id'] );
		$this->assertSame( array( $event_key ), json_decode( $open[0]['conversion_event_ids'], true ) );

		$result = $this->make_conversion_service()->execute( $open[0]['id'] );
		$this->assertSame( 'converted', $result['code'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'v25.0', EVENTBRIDGE_GRAPH_API_VERSION );
		$this->assertSame( 'https://graph.facebook.com/v25.0/123456789/events', $this->requests[0]['url'] );
		$body       = json_decode( $this->requests[0]['args']['body'], true );
		$deliveries = $this->conversions->get_deliveries( $open[0]['id'] );
		$occurrence = json_decode( $deliveries[0]['occurrence'], true );
		$this->assertSame( 'SessionBookedTest', $body['data'][0]['event_name'] );
		$this->assertSame( $occurrence['event_id'], $body['data'][0]['event_id'] );
		$this->assertSame( $occurrence['event_time'], $body['data'][0]['event_time'] );
		$this->assertSame( (string) $booking->id, $body['data'][0]['custom_data']['booking_id'] );
		$this->assertSame( hash( 'sha256', 'invitee@example.test' ), $body['data'][0]['user_data']['em'] );
		$this->assertSame( hash( 'sha256', '32470123456' ), $body['data'][0]['user_data']['ph'] );
		$this->assertSame( 'TEST12345', $body['test_event_code'] );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );
		$this->assertCount( 1, $this->log->records );
		$this->assertSame( array( 'events_received' => 1, 'message_count' => 0, 'fbtrace_id' => 'TEST_TRACE' ), $this->log->records[0]['details']['context']['meta_response'] );
		$encoded_log = wp_json_encode( $this->log->records );
		$this->assertStringNotContainsString( 'integration-token', $encoded_log );
		$this->assertStringNotContainsString( 'invitee@example.test', $encoded_log );
		$this->assertStringNotContainsString( '+32 470 12 34 56', $encoded_log );
	}

	private function make_conversion_service() {
		$this->log = new EventBridge_Fluent_Booking_CAPI_Integration_Test_Log();
		$capi      = new EventBridge_Meta_CAPI( new EventBridge_Settings(), $this->log );
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $capi ) );
		return new EventBridge_Conversion_Service(
			$this->conversions,
			new EventBridge_Events(),
			new EventBridge_Dispatcher( $registry ),
			$registry,
			$this->profiles,
			$this->contexts,
			new EventBridge_Fluent_Booking( new EventBridge_Fluent_Booking_Settings() )
		);
	}

	private function store_test_event() {
		$events = new EventBridge_Events();
		$key    = 'evt_' . wp_generate_uuid4();
		$event  = array_merge(
			$events->get_form_defaults(),
			array(
				'label'                => 'Session booked test',
				'event_name'           => 'SessionBookedTest',
				'enabled'              => true,
				'channels'             => array( 'browser' => false, 'capi' => true ),
				'browser'              => false,
				'capi'                 => true,
				'meta_test_mode'       => true,
				'meta_test_event_code' => 'TEST12345',
				'parameters'           => array( array( 'name' => 'booking_id', 'source' => 'fluent_booking', 'value' => 'booking_id' ) ),
				'advanced_matching'    => array(
					'email' => array( 'source' => 'fluent_booking', 'value' => '' ),
					'phone' => array( 'source' => 'fluent_booking', 'value' => '' ),
				),
			)
		);
		unset( $event['triggers'], $event['eventbridge_schema_version'], $event['eventbridge_compat'] );
		update_option( EventBridge_Events::OPTION_NAME, array( $key => $event ), false );
		return $key;
	}
}
