<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Meta_CAPI {
	private $settings;
	private $log;
	private $request_sent = false;

	public function __construct( EventBridge_Settings $settings, EventBridge_Log $log ) {
		$this->settings = $settings;
		$this->log      = $log;
	}

	public function init() {
		add_action( 'template_redirect', array( $this, 'send_page_view' ) );
	}

	public function send_page_view() {
		if ( $this->request_sent || $this->should_skip_request() ) {
			return;
		}

		$event_source_url = $this->get_event_source_url();

		if ( '' === $event_source_url ) {
			return;
		}

		$event = array(
			'event_name'       => 'PageView',
			'event_time'       => time(),
			'action_source'    => 'website',
			'event_source_url' => $event_source_url,
			'user_data'        => $this->get_user_data(),
		);

		if ( $this->send_event( $event ) ) {
			$this->request_sent = true;
		}
	}

	public function send_custom_event( $event_name, $event_id, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$event_source_url = EventBridge_Meta_URL::canonicalize( $event_source_url );
		if ( '' === $event_source_url ) {
			return false;
		}

		$user_data = $this->get_user_data();

		if ( is_array( $advanced_user_data ) ) {
			foreach ( array( 'em', 'ph', 'fn', 'ln' ) as $key ) {
				if ( isset( $advanced_user_data[ $key ] ) && is_string( $advanced_user_data[ $key ] ) && preg_match( '/^[a-f0-9]{64}$/D', $advanced_user_data[ $key ] ) ) {
					$user_data[ $key ] = $advanced_user_data[ $key ];
				}
			}
		}

		$event = array(
			'event_name'       => $event_name,
			'event_time'       => time(),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => $event_source_url,
			'user_data'        => $user_data,
		);

		if ( is_array( $custom_data ) && ! empty( $custom_data ) ) {
			$event['custom_data'] = $custom_data;
		}
		if ( is_array( $details ) ) {
			$details['page_url'] = $event_source_url;
		}

		return $this->send_event(
			$event,
			$details,
			$this->get_test_event_code( $event_configuration )
		);
	}

	public function send_server_event( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$event_source_url = EventBridge_Meta_URL::canonicalize( $event_source_url );
		if ( '' === $event_source_url || ! is_string( $event_id ) || ! wp_is_uuid( $event_id, 4 ) ) {
			return false;
		}

		$user_data = array();
		if ( is_array( $advanced_user_data ) ) {
			foreach ( array( 'em', 'ph', 'fn', 'ln' ) as $key ) {
				if ( isset( $advanced_user_data[ $key ] ) && is_string( $advanced_user_data[ $key ] ) && preg_match( '/^[a-f0-9]{64}$/D', $advanced_user_data[ $key ] ) ) {
					$user_data[ $key ] = $advanced_user_data[ $key ];
				}
			}
		}

		$event = array(
			'event_name'       => $event_name,
			'event_time'       => max( 1, absint( $event_time ) ),
			'event_id'         => $event_id,
			'action_source'    => 'website',
			'event_source_url' => $event_source_url,
			'user_data'        => $user_data,
		);

		if ( is_array( $custom_data ) && ! empty( $custom_data ) ) {
			$event['custom_data'] = $custom_data;
		}
		if ( is_array( $details ) ) {
			$details['page_url'] = $event_source_url;
		}

		return $this->send_event(
			$event,
			$details,
			$this->get_test_event_code( $event_configuration )
		);
	}

	private function send_event( $event, $custom_event_details = null, $test_event_code = '' ) {
		if ( ! is_array( $event ) || ! isset( $event['event_source_url'] ) || ! is_string( $event['event_source_url'] ) ) {
			return false;
		}

		$event_source_url = EventBridge_Meta_URL::canonicalize( $event['event_source_url'] );
		if ( '' === $event_source_url ) {
			return false;
		}
		$event['event_source_url'] = $event_source_url;

		$settings   = $this->settings->get_settings();
		$pixel_id   = isset( $settings['pixel_id'] ) && is_scalar( $settings['pixel_id'] ) ? trim( (string) $settings['pixel_id'] ) : '';
		$capi_token = isset( $settings['capi_token'] ) && is_scalar( $settings['capi_token'] ) ? trim( (string) $settings['capi_token'] ) : '';

		if ( '' === $pixel_id || ! preg_match( '/^[0-9]+$/D', $pixel_id ) || '' === $capi_token ) {
			return false;
		}

		$request_body = array(
			'access_token' => $capi_token,
			'data'         => array( $event ),
		);

		if ( is_array( $custom_event_details ) && '' !== $test_event_code ) {
			$request_body['test_event_code'] = $test_event_code;
		}

		$body = wp_json_encode( $request_body );

		if ( ! is_string( $body ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://graph.facebook.com/' . EVENTBRIDGE_GRAPH_API_VERSION . '/' . rawurlencode( $pixel_id ) . '/events',
			array(
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => $body,
				'timeout'  => 5,
				'blocking' => false,
			)
		);

		if ( is_array( $custom_event_details ) && isset( $settings['debug'] ) && true === (bool) $settings['debug'] ) {
			$http_request_started = ! is_wp_error( $response );
			$error_category       = $http_request_started ? '' : 'transport_error';
			$debug_details        = $this->build_debug_details( $event, $custom_event_details, $test_event_code, $http_request_started, $error_category );

			$this->log->log( 'info', 'meta_capi_debug', 'Meta CAPI request body prepared.', $debug_details );
		}

		if ( is_array( $custom_event_details ) ) {
			if ( is_wp_error( $response ) ) {
				$custom_event_details['context'] = array( 'reason' => 'wp_remote_post_error' );
				$this->log->log( 'error', 'meta_capi', 'Custom CAPI request not started.', $custom_event_details );
			} else {
				$this->log->log( 'info', 'meta_capi', 'Custom CAPI request started.', $custom_event_details );
			}
		}

		return ! is_wp_error( $response );
	}

	private function build_debug_details( $event, $custom_event_details, $test_event_code, $http_request_started, $error_category = '' ) {
		$user_data                = isset( $event['user_data'] ) && is_array( $event['user_data'] ) ? $event['user_data'] : array();
		$advanced_matching_fields = array();

		foreach ( array( 'em', 'ph', 'fn', 'ln' ) as $field_name ) {
			if ( array_key_exists( $field_name, $user_data ) ) {
				$advanced_matching_fields[] = $field_name;
			}
		}

		return array(
			'event_key'  => isset( $custom_event_details['event_key'] ) && is_scalar( $custom_event_details['event_key'] ) ? (string) $custom_event_details['event_key'] : '',
			'trigger_id' => isset( $custom_event_details['trigger_id'] ) && is_scalar( $custom_event_details['trigger_id'] ) ? (string) $custom_event_details['trigger_id'] : '',
			'event_name' => isset( $event['event_name'] ) && is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
			'event_id'   => isset( $event['event_id'] ) && is_scalar( $event['event_id'] ) ? (string) $event['event_id'] : '',
			'context'    => array(
				'channel'                  => 'capi',
				'advanced_matching_fields' => $advanced_matching_fields,
				'has_fbp'                  => array_key_exists( 'fbp', $user_data ),
				'has_fbc'                  => array_key_exists( 'fbc', $user_data ),
				'test_mode'                => is_string( $test_event_code ) && '' !== $test_event_code,
				'http_request_started'      => true === $http_request_started,
				'error_category'            => 'transport_error' === $error_category ? 'transport_error' : '',
			),
		);
	}

	private function get_test_event_code( $event_configuration ) {
		if ( ! is_array( $event_configuration )
			|| ! isset( $event_configuration['capi'], $event_configuration['meta_test_mode'], $event_configuration['meta_test_event_code'] )
			|| true !== (bool) $event_configuration['capi']
			|| true !== $event_configuration['meta_test_mode']
			|| ! is_scalar( $event_configuration['meta_test_event_code'] )
		) {
			return '';
		}

		$test_event_code = trim( (string) $event_configuration['meta_test_event_code'] );

		return strlen( $test_event_code ) <= EventBridge_Events::META_TEST_EVENT_CODE_MAX_LENGTH && preg_match( '/^TEST[0-9]+$/D', $test_event_code ) ? $test_event_code : '';
	}

	private function should_skip_request() {
		return is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST )
			|| ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] )
			|| is_feed()
			|| is_trackback()
			|| is_robots()
			|| ( function_exists( 'is_favicon' ) && is_favicon() );
	}

	private function get_event_source_url() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$request_uri = trim( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) );
		$home_parts  = wp_parse_url( home_url( '/' ) );

		if ( '' === $request_uri || ! is_array( $home_parts ) || empty( $home_parts['host'] ) ) {
			return '';
		}

		if ( empty( $home_parts['scheme'] ) || ! in_array( strtolower( $home_parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		$origin = strtolower( $home_parts['scheme'] ) . '://' . $home_parts['host'];

		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . (int) $home_parts['port'];
		}

		return EventBridge_Meta_URL::canonicalize( $origin . '/' . ltrim( $request_uri, '/' ) );
	}

	private function get_user_data() {
		$user_data = array();
		$ip_address = $this->get_server_value( 'REMOTE_ADDR', 45 );

		if ( '' !== $ip_address && false !== filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			$user_data['client_ip_address'] = $ip_address;
		}

		$user_agent = $this->get_server_value( 'HTTP_USER_AGENT', 500 );

		if ( '' !== $user_agent ) {
			$user_data['client_user_agent'] = $user_agent;
		}

		$fbp = $this->get_cookie_value( '_fbp', 255 );
		$fbc = $this->get_cookie_value( '_fbc', 255 );

		if ( '' !== $fbp ) {
			$user_data['fbp'] = $fbp;
		}

		if ( '' !== $fbc ) {
			$user_data['fbc'] = $fbc;
		}

		return $user_data;
	}

	private function get_server_value( $key, $maximum_length ) {
		if ( ! isset( $_SERVER[ $key ] ) || ! is_string( $_SERVER[ $key ] ) ) {
			return '';
		}

		return $this->sanitize_input_value( wp_unslash( (string) $_SERVER[ $key ] ), $maximum_length );
	}

	private function get_cookie_value( $key, $maximum_length ) {
		if ( ! isset( $_COOKIE[ $key ] ) || ! is_string( $_COOKIE[ $key ] ) ) {
			return '';
		}

		return $this->sanitize_input_value( wp_unslash( (string) $_COOKIE[ $key ] ), $maximum_length );
	}

	private function sanitize_input_value( $value, $maximum_length ) {
		$value = trim( $value );

		if ( '' === $value || strlen( $value ) > $maximum_length ) {
			return '';
		}

		return $value;
	}
}
