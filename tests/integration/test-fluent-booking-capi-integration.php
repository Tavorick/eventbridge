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
	private $previous_meta_cookies;
	private $previous_client_server_values;

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
		$this->previous_meta_cookies = array();
		foreach ( array( '_fbc', '_fbp' ) as $key ) {
			$this->previous_meta_cookies[ $key ] = array( 'present' => isset( $_COOKIE[ $key ] ), 'value' => isset( $_COOKIE[ $key ] ) ? $_COOKIE[ $key ] : null );
		}
		$this->previous_client_server_values = array();
		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT' ) as $key ) {
			$this->previous_client_server_values[ $key ] = array( 'present' => isset( $_SERVER[ $key ] ), 'value' => isset( $_SERVER[ $key ] ) ? $_SERVER[ $key ] : null );
		}
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
		foreach ( $this->previous_meta_cookies as $key => $previous ) {
			if ( $previous['present'] ) $_COOKIE[ $key ] = $previous['value'];
			else unset( $_COOKIE[ $key ] );
		}
		foreach ( $this->previous_client_server_values as $key => $previous ) {
			if ( $previous['present'] ) $_SERVER[ $key ] = $previous['value'];
			else unset( $_SERVER[ $key ] );
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

	/** @group eventbridge-forensic-checkpoint4 */
	public function test_real_fluent_booking_manual_conversion_builds_test_events_request_from_stored_event_configuration() {
		$_COOKIE['_fbc'] = 'fb.1.1785747600000.original-click';
		$_COOKIE['_fbp'] = 'fb.1.1785747600000.original-browser';
		$_SERVER['REMOTE_ADDR'] = '198.51.100.10';
		$_SERVER['HTTP_USER_AGENT'] = 'EventBridge synthetic booking visitor/1.0';
		$profile = $this->profiles->get_or_create( hash( 'sha256', $_COOKIE[ EventBridge_Profile_Token::COOKIE_NAME ], true ) );
		$this->profiles->save_touch( $profile, array( 'version' => 1, 'captured_at' => '2026-08-02T09:00:00+00:00', 'landing_url' => home_url( '/first-touch/' ), 'utm_source' => 'facebook', 'fbclid' => 'first-click' ) );
		$profile = $this->profiles->get_by_id( $profile['id'] );
		$this->profiles->save_touch( $profile, array( 'version' => 1, 'captured_at' => '2026-08-03T09:00:00+00:00', 'landing_url' => home_url( '/last-touch/' ), 'utm_source' => 'facebook', 'fbclid' => 'original-click' ) );
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
		$stored_event = ( new EventBridge_Events() )->get_event( $event_key );
		$this->assertIsArray( $stored_event );
		$this->assertSame( 'sessiegeboekttest', $stored_event['event_name'] );
		$this->assertTrue( $stored_event['capi'] );
		$this->assertTrue( $stored_event['meta_test_mode'] );
		$this->assertSame( 'TEST33281', $stored_event['meta_test_event_code'] );
		$this->assertSame( '123456789', ( new EventBridge_Settings() )->get_settings()['pixel_id'] );
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
				'ip_address'        => '203.0.113.42',
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
		$attribution_snapshot = $this->conversions->decode_attribution_snapshot( $open[0]['attribution_snapshot'] );
		$this->assertIsArray( $attribution_snapshot );
		$this->assertSame( 'first-click', $attribution_snapshot['first_touch']['fbclid'] );
		$this->assertSame( 'original-click', $attribution_snapshot['last_touch']['fbclid'] );
		$this->assertSame( 'last_touch', $attribution_snapshot['selected_touch'] );
		$this->assertSame( $_COOKIE['_fbc'], $attribution_snapshot['browser_context']['browser_cookie']['_fbc']['value'] );
		$this->assertSame( $_COOKIE['_fbp'], $attribution_snapshot['browser_context']['browser_cookie']['_fbp']['value'] );
		$this->assertSame( '203.0.113.42', $attribution_snapshot['browser_context']['client_request']['ip_address']['value'] );
		$this->assertSame( 'EventBridge synthetic booking visitor/1.0', $attribution_snapshot['browser_context']['client_request']['user_agent']['value'] );

		$profile = $this->profiles->get_by_id( $link['profile_id'] );
		$this->profiles->save_touch( $profile, array( 'version' => 1, 'captured_at' => '2026-08-04T09:00:00+00:00', 'landing_url' => home_url( '/organic/' ), 'utm_source' => 'organic' ) );
		$this->contexts->save( $link['profile_id'], 'browser_cookie', array( '_fbc' => 'fb.1.1785834000000.later-click', '_fbp' => 'fb.1.1785834000000.later-browser' ) );
		$this->contexts->save( $link['profile_id'], 'client_request', array( 'ip_address' => '198.51.100.200', 'user_agent' => 'Administrator conversion agent' ) );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.201';
		$_SERVER['HTTP_USER_AGENT'] = 'Administrator request agent';

		$service = $this->make_conversion_service();
		$result  = $service->execute( $open[0]['id'] );
		$this->assertSame( 'converted', $result['code'] );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'v25.0', EVENTBRIDGE_GRAPH_API_VERSION );
		$this->assertSame( 'https://graph.facebook.com/v25.0/123456789/events', $this->requests[0]['url'] );
		$body       = json_decode( $this->requests[0]['args']['body'], true );
		$deliveries = $this->conversions->get_deliveries( $open[0]['id'] );
		$this->assertCount( 1, $deliveries );
		$occurrence = json_decode( $deliveries[0]['occurrence'], true );
		$this->assertCount( 1, $body['data'] );
		$this->assertSame( 'sessiegeboekttest', $body['data'][0]['event_name'] );
		$this->assertSame( 'website', $body['data'][0]['action_source'] );
		$this->assertSame( home_url( '/integration-booking/' ), $body['data'][0]['event_source_url'] );
		$this->assertTrue( wp_is_uuid( $body['data'][0]['event_id'], 4 ) );
		$this->assertGreaterThan( 0, $body['data'][0]['event_time'] );
		$this->assertSame( $occurrence['event_id'], $body['data'][0]['event_id'] );
		$this->assertSame( $occurrence['event_time'], $body['data'][0]['event_time'] );
		$this->assertSame( 'manual_conversion', $occurrence['details']['context']['trigger'] );
		$this->assertSame( 'booking_snapshot', $occurrence['details']['context']['attribution_source'] );
		$this->assertTrue( $occurrence['event_configuration']['capi'] );
		$this->assertTrue( $occurrence['event_configuration']['meta_test_mode'] );
		$this->assertSame( 'TEST33281', $occurrence['event_configuration']['meta_test_event_code'] );
		$this->assertSame( (string) $booking->id, $body['data'][0]['custom_data']['booking_id'] );
		$this->assertSame( hash( 'sha256', 'invitee@example.test' ), $body['data'][0]['user_data']['em'] );
		$this->assertSame( hash( 'sha256', '32470123456' ), $body['data'][0]['user_data']['ph'] );
		$this->assertSame( 'fb.1.1785747600000.original-click', $body['data'][0]['user_data']['fbc'] );
		$this->assertSame( 'fb.1.1785747600000.original-browser', $body['data'][0]['user_data']['fbp'] );
		$this->assertSame( '203.0.113.42', $body['data'][0]['user_data']['client_ip_address'] );
		$this->assertSame( 'EventBridge synthetic booking visitor/1.0', $body['data'][0]['user_data']['client_user_agent'] );
		$this->assertNotSame( $_SERVER['REMOTE_ADDR'], $body['data'][0]['user_data']['client_ip_address'] );
		$this->assertNotSame( $_SERVER['HTTP_USER_AGENT'], $body['data'][0]['user_data']['client_user_agent'] );
		$this->assertSame( 'TEST33281', $body['test_event_code'] );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );
		$this->assertSame( EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED, $deliveries[0]['status'] );
		$this->assertSame( 200, (int) $deliveries[0]['last_http_code'] );
		$diagnostics = $this->conversions->decode_delivery_diagnostics( $deliveries[0]['outbound_diagnostics'] );
		$this->assertSame( 'booking_snapshot', $diagnostics['attribution_source'] );
		$this->assertSame( 'website', $diagnostics['action_source'] );
		$this->assertTrue( $diagnostics['event_source_url_present'] );
		$this->assertTrue( $diagnostics['test_mode'] );
		$this->assertSame( '123456789', $diagnostics['dataset_id'] );
		$this->assertSame( 'cookie', $diagnostics['fbc_source'] );
		$this->assertTrue( $diagnostics['has_fbp'] );
		$this->assertTrue( $diagnostics['has_client_ip_address'] );
		$this->assertTrue( $diagnostics['has_client_user_agent'] );
		$this->assertSame( 1, $diagnostics['events_received'] );
		$encoded_diagnostics = wp_json_encode( $diagnostics );
		$this->assertStringNotContainsString( 'TEST33281', $encoded_diagnostics );
		$this->assertCount( 1, $this->log->records );
		$this->assertSame( array( 'events_received' => 1, 'message_count' => 0, 'fbtrace_id' => 'TEST_TRACE' ), $this->log->records[0]['details']['context']['meta_response'] );
		$encoded_log = wp_json_encode( $this->log->records );
		$this->assertStringNotContainsString( 'TEST33281', $encoded_log );
		$this->assertStringNotContainsString( 'integration-token', $encoded_log );
		$this->assertStringNotContainsString( 'invitee@example.test', $encoded_log );
		$this->assertStringNotContainsString( '+32 470 12 34 56', $encoded_log );
		$this->assertStringNotContainsString( '203.0.113.42', $encoded_log );
		$this->assertStringNotContainsString( 'EventBridge synthetic booking visitor/1.0', $encoded_log );
		$this->assertStringNotContainsString( 'Administrator conversion agent', $encoded_log );

		$duplicate = $service->execute( $open[0]['id'] );
		$this->assertSame( 'already_converted', $duplicate['code'] );
		$this->assertCount( 1, $this->conversions->get_deliveries( $open[0]['id'] ) );
		$this->assertCount( 1, $this->requests );
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
				'event_name'           => 'sessiegeboekttest',
				'enabled'              => true,
				'channels'             => array( 'browser' => false, 'capi' => true ),
				'browser'              => false,
				'capi'                 => true,
				'meta_test_mode'       => true,
				'meta_test_event_code' => 'TEST33281',
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
