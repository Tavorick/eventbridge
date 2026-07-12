<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Custom_Event_Endpoint {
	const AJAX_ACTION  = 'eventbridge_custom_event';
	const NONCE_ACTION = 'eventbridge_custom_event';

	private $events;
	private $meta_capi;
	private $log;

	public function __construct( EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi, EventBridge_Log $log ) {
		$this->events    = $events;
		$this->meta_capi = $meta_capi;
		$this->log       = $log;
	}

	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
	}

	public function handle_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			$this->reject( 'invalid_request_method' );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->reject( 'invalid_nonce' );
		}

		$event_key        = $this->get_posted_string( 'event_key' );
		$event_id         = $this->get_posted_string( 'event_id' );
		$event_source_url = $this->validate_source_url( $this->get_posted_string( 'page_url' ) );
		$browser_invoked  = '1' === $this->get_posted_string( 'browser_invoked' );
		$browser_method   = $this->get_posted_string( 'browser_method' );

		if ( ! $this->events->is_valid_event_key( $event_key )
			|| '' === $event_id
			|| strlen( $event_id ) > 100
			|| ! preg_match( '/^[A-Za-z0-9_-]+$/D', $event_id )
			|| '' === $event_source_url
		) {
			$this->reject( 'invalid_request_fields' );
		}

		$event = $this->events->get_event( $event_key );

		if ( ! is_array( $event )
			|| true !== $event['enabled']
			|| ! in_array( $event['trigger_type'], array( 'click', 'pageview' ), true )
			|| ! is_scalar( $event['event_name'] )
		) {
			$this->reject(
				'invalid_event_configuration',
				array(
					'event_key' => $event_key,
					'event_id'  => $event_id,
					'page_url'  => $event_source_url,
				)
			);
		}

		$event_name = trim( (string) $event['event_name'] );

		if ( '' === $event_name
			|| strlen( $event_name ) > EventBridge_Events::EVENT_NAME_MAX_LENGTH
			|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $event_name )
		) {
			$this->reject(
				'invalid_event_name',
				array(
					'event_key' => $event_key,
					'event_id'  => $event_id,
					'page_url'  => $event_source_url,
				)
			);
		}

		$capi_enabled            = true === (bool) $event['capi'];
		$expected_browser_method = $this->get_browser_method( $event_name );
		$browser_log_allowed      = $browser_invoked
			&& true === (bool) $event['browser']
			&& $expected_browser_method === $browser_method;

		if ( ! $capi_enabled && ! $browser_log_allowed ) {
			$this->reject(
				'invalid_event_configuration',
				array(
					'event_key' => $event_key,
					'event_id'  => $event_id,
					'page_url'  => $event_source_url,
				)
			);
		}

		$details = array(
			'event_key'  => $event_key,
			'event_name' => $event_name,
			'event_id'   => $event_id,
			'page_url'   => $event_source_url,
		);

		$this->log->log( 'info', 'custom_event_endpoint', 'Custom event endpoint request accepted.', $details );

		if ( $browser_log_allowed ) {
			$browser_details            = $details;
			$browser_details['context'] = array( 'method' => $browser_method );
			$this->log->log( 'info', 'browser', 'Browser event invoked.', $browser_details );
		}

		if ( $capi_enabled ) {
			if ( ! $this->meta_capi->send_custom_event( $event_name, $event_id, $event_source_url, $this->events->get_parameter_map( $event ), $details ) ) {
				wp_send_json_error( array( 'status' => 'rejected' ) );
			}

			wp_send_json_success( array( 'status' => 'started' ) );
		}

		wp_send_json_success( array( 'status' => 'accepted' ) );
	}

	private function get_browser_method( $event_name ) {
		$standard_events = array(
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		);

		return in_array( $event_name, $standard_events, true ) ? 'track' : 'trackCustom';
	}

	private function get_posted_string( $key ) {
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return '';
		}

		return trim( wp_unslash( (string) $_POST[ $key ] ) );
	}

	private function validate_source_url( $url ) {
		$url        = esc_url_raw( $url, array( 'http', 'https' ) );
		$url_parts  = wp_parse_url( $url );
		$home_parts = wp_parse_url( home_url( '/' ) );

		if ( '' === $url
			|| ! is_array( $url_parts )
			|| ! is_array( $home_parts )
			|| empty( $url_parts['scheme'] )
			|| empty( $url_parts['host'] )
			|| empty( $home_parts['host'] )
			|| ! in_array( strtolower( $url_parts['scheme'] ), array( 'http', 'https' ), true )
			|| 0 !== strcasecmp( $url_parts['host'], $home_parts['host'] )
		) {
			return '';
		}

		return $url;
	}

	private function reject( $reason, $details = array() ) {
		$details            = is_array( $details ) ? $details : array();
		$details['context'] = array( 'reason' => $reason );

		$this->log->log( 'warning', 'custom_event_endpoint', 'Custom event endpoint request rejected.', $details );

		wp_send_json_error( array( 'status' => 'rejected' ) );
	}
}
