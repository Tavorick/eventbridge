<?php

class EventBridge_Meta_CAPI_Test_Log extends EventBridge_Log {
	public $records = array();

	public function log( $level, $source, $message, $details = array() ) {
		$this->records[] = compact( 'level', 'source', 'message', 'details' );
		return true;
	}
}

class EventBridge_Meta_CAPI_Test extends WP_UnitTestCase {
	private $capi;
	private $log;
	private $captured_args;
	private $captured_url;
	private $response;

	public function set_up() {
		parent::set_up();
		update_option(
			EventBridge_Settings::OPTION_NAME,
			array( 'pixel_id' => '123456789', 'capi_token' => 'test-token', 'debug' => false ),
			false
		);
		$this->log           = new EventBridge_Meta_CAPI_Test_Log();
		$this->capi          = new EventBridge_Meta_CAPI( new EventBridge_Settings(), $this->log );
		$this->captured_args = array();
		$this->captured_url  = '';
		$this->response      = $this->http_response( 200, array( 'events_received' => 1 ) );
		add_filter( 'pre_http_request', array( $this, 'mock_request' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_request' ), 10 );
		parent::tear_down();
	}

	public function mock_request( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'graph.facebook.com/' ) ) {
			return $preempt;
		}
		$this->captured_args = $args;
		$this->captured_url  = $url;
		return $this->response;
	}

	public function test_confirmed_server_event_requires_meta_success() {
		$result = $this->send_confirmed();

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( 'confirmed', $result['reason'] );
		$this->assertSame( array( 'events_received' => 1, 'message_count' => 0, 'fbtrace_id' => '' ), $result['diagnostics'] );
		$this->assertTrue( $this->captured_args['blocking'] );
		$this->assertSame( 5, $this->captured_args['timeout'] );
		$this->assertSame( 'https://graph.facebook.com/' . EVENTBRIDGE_GRAPH_API_VERSION . '/123456789/events', $this->captured_url );
	}

	public function test_confirmed_success_returns_and_logs_safe_meta_diagnostics() {
		$this->response = $this->http_response(
			200,
			array(
				'events_received' => 1,
				'messages'        => array(),
				'fbtrace_id'      => 'TEST_TRACE',
			)
		);

		$result = $this->send_confirmed();

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( array( 'events_received' => 1, 'message_count' => 0, 'fbtrace_id' => 'TEST_TRACE' ), $result['diagnostics'] );
		$this->assertCount( 1, $this->log->records );
		$this->assertSame( $result['diagnostics'], $this->log->records[0]['details']['context']['meta_response'] );
		$encoded_log = wp_json_encode( $this->log->records );
		$this->assertStringNotContainsString( 'test-token', $encoded_log );
		$this->assertStringNotContainsString( 'access_token', $encoded_log );
		$this->assertStringNotContainsString( 'messages', $encoded_log );
	}

	public function test_transport_timeout_and_retryable_http_responses_are_distinguished() {
		$this->response = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$this->assertSame( array( 'status' => 'retryable', 'reason' => 'timeout', 'http_code' => 0 ), $this->send_confirmed() );

		$this->response = new WP_Error( 'http_request_failed', 'Connection reset' );
		$this->assertSame( array( 'status' => 'retryable', 'reason' => 'transport_error', 'http_code' => 0 ), $this->send_confirmed() );

		foreach ( array( 429, 500 ) as $code ) {
			$this->response = $this->http_response( $code, array( 'error' => array( 'message' => 'failure' ) ) );
			$result = $this->send_confirmed();
			$this->assertSame( 'retryable', $result['status'] );
			$this->assertSame( 'http_' . $code, $result['reason'] );
		}
	}

	public function test_http_400_is_terminal_and_invalid_200_is_retryable() {
		$this->response = $this->http_response( 400, array( 'error' => array( 'message' => 'invalid' ) ) );
		$this->assertSame( array( 'status' => 'terminal', 'reason' => 'http_400', 'http_code' => 400 ), $this->send_confirmed() );

		$this->response = $this->http_response( 200, array() );
		$result = $this->send_confirmed();
		$this->assertSame( 'retryable', $result['status'] );
		$this->assertSame( 'invalid_success_response', $result['reason'] );
		$this->assertSame( array( 'events_received' => 0, 'message_count' => 0, 'fbtrace_id' => '' ), $result['diagnostics'] );

		$this->response = $this->http_response( 200, array( 'events_received' => 0, 'messages' => array( 'invitee@example.test' ), 'fbtrace_id' => 'TEST_TRACE' ) );
		$result = $this->send_confirmed();
		$this->assertSame( 'retryable', $result['status'] );
		$this->assertSame( array( 'events_received' => 0, 'message_count' => 1, 'fbtrace_id' => 'TEST_TRACE' ), $result['diagnostics'] );
		$this->assertStringNotContainsString( 'invitee@example.test', wp_json_encode( $this->log->records ) );

		$this->response = $this->raw_http_response( 200, '{malformed' );
		$result = $this->send_confirmed();
		$this->assertSame( 'retryable', $result['status'] );
		$this->assertSame( array( 'events_received' => 0, 'message_count' => 0, 'fbtrace_id' => '' ), $result['diagnostics'] );
	}

	public function test_test_event_code_is_top_level_and_omitted_in_production_mode() {
		$this->capi->send_server_event_confirmed(
			'Purchase',
			'11111111-1111-4111-8111-111111111111',
			1000,
			home_url( '/shop/' ),
			array(),
			array( 'event_key' => 'evt_test' ),
			array(),
			array( 'capi' => true, 'meta_test_mode' => true, 'meta_test_event_code' => 'TEST12345' )
		);
		$body = json_decode( $this->captured_args['body'], true );
		$this->assertSame( 'TEST12345', $body['test_event_code'] );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );

		$this->capi->send_server_event_confirmed(
			'Purchase',
			'22222222-2222-4222-8222-222222222222',
			1001,
			home_url( '/shop/' ),
			array(),
			array( 'event_key' => 'evt_production' ),
			array(),
			array( 'capi' => true, 'meta_test_mode' => false, 'meta_test_event_code' => 'TEST12345' )
		);
		$body = json_decode( $this->captured_args['body'], true );
		$this->assertArrayNotHasKey( 'test_event_code', $body );
		$this->assertArrayNotHasKey( 'test_event_code', $body['data'][0] );
	}

	public function test_confirmed_server_event_accepts_persisted_browser_identifiers() {
		$this->capi->send_server_event_confirmed( 'Lead', '11111111-1111-4111-8111-111111111111', 1000, home_url( '/landing/' ), array(), array(), array( 'fbp' => 'fb.1.1700000000000.123456', 'fbc' => 'fb.1.1700000000000.click-1' ) );
		$body = json_decode( $this->captured_args['body'], true );
		$this->assertSame( 'fb.1.1700000000000.123456', $body['data'][0]['user_data']['fbp'] );
		$this->assertSame( 'fb.1.1700000000000.click-1', $body['data'][0]['user_data']['fbc'] );
	}

	private function send_confirmed() {
		return $this->capi->send_server_event_confirmed(
			'Purchase',
			'11111111-1111-4111-8111-111111111111',
			1000,
			home_url( '/shop/' ),
			array( 'value' => 10 ),
			array( 'event_key' => 'evt_test' )
		);
	}

	private function http_response( $code, $body ) {
		return $this->raw_http_response( $code, wp_json_encode( $body ) );
	}

	private function raw_http_response( $code, $body ) {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
