<?php

class EventBridge_Meta_CAPI_Test extends WP_UnitTestCase {
	private $capi;
	private $captured_args;
	private $response;

	public function set_up() {
		parent::set_up();
		update_option(
			EventBridge_Settings::OPTION_NAME,
			array( 'pixel_id' => '123456789', 'capi_token' => 'test-token', 'debug' => false ),
			false
		);
		$this->capi          = new EventBridge_Meta_CAPI( new EventBridge_Settings(), new EventBridge_Log() );
		$this->captured_args = array();
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
		return $this->response;
	}

	public function test_confirmed_server_event_requires_meta_success() {
		$result = $this->send_confirmed();

		$this->assertSame( 'success', $result['status'] );
		$this->assertSame( 'confirmed', $result['reason'] );
		$this->assertTrue( $this->captured_args['blocking'] );
		$this->assertSame( 5, $this->captured_args['timeout'] );
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
		$this->assertSame( array( 'status' => 'retryable', 'reason' => 'invalid_success_response', 'http_code' => 200 ), $this->send_confirmed() );
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
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $code, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
