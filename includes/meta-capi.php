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

	public function send_custom_event( $event_name, $event_id, $event_source_url, $details ) {
		return $this->send_event(
			array(
				'event_name'       => $event_name,
				'event_time'       => time(),
				'event_id'         => $event_id,
				'action_source'    => 'website',
				'event_source_url' => $event_source_url,
				'user_data'        => $this->get_user_data(),
			),
			$details
		);
	}

	private function send_event( $event, $custom_event_details = null ) {
		$settings   = $this->settings->get_settings();
		$pixel_id   = isset( $settings['pixel_id'] ) && is_scalar( $settings['pixel_id'] ) ? trim( (string) $settings['pixel_id'] ) : '';
		$capi_token = isset( $settings['capi_token'] ) && is_scalar( $settings['capi_token'] ) ? trim( (string) $settings['capi_token'] ) : '';

		if ( '' === $pixel_id || ! preg_match( '/^[0-9]+$/D', $pixel_id ) || '' === $capi_token ) {
			return false;
		}

		$body = wp_json_encode(
			array(
				'access_token' => $capi_token,
				'data'         => array( $event ),
			)
		);

		if ( ! is_string( $body ) ) {
			return false;
		}

		$response = wp_remote_post(
			'https://graph.facebook.com/v25.0/' . rawurlencode( $pixel_id ) . '/events',
			array(
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => $body,
				'timeout'  => 5,
				'blocking' => false,
			)
		);

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

		$scheme = is_ssl() ? 'https' : 'http';
		$origin = $scheme . '://' . $home_parts['host'];

		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . (int) $home_parts['port'];
		}

		$url   = esc_url_raw( $origin . '/' . ltrim( $request_uri, '/' ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );

		if ( '' === $url || ! is_array( $parts ) || empty( $parts['host'] ) || $home_parts['host'] !== $parts['host'] ) {
			return '';
		}

		return $url;
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
