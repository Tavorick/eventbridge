<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Custom_Event_Endpoint {
	const AJAX_ACTION  = 'eventbridge_custom_event';
	const NONCE_ACTION = 'eventbridge_custom_event';

	private $events;
	private $meta_capi;

	public function __construct( EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi ) {
		$this->events    = $events;
		$this->meta_capi = $meta_capi;
	}

	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
	}

	public function handle_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			$this->reject();
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->reject();
		}

		$event_key        = $this->get_posted_string( 'event_key' );
		$event_id         = $this->get_posted_string( 'event_id' );
		$event_source_url = $this->validate_source_url( $this->get_posted_string( 'page_url' ) );

		if ( ! $this->events->is_valid_event_key( $event_key )
			|| '' === $event_id
			|| strlen( $event_id ) > 100
			|| ! preg_match( '/^[A-Za-z0-9_-]+$/D', $event_id )
			|| '' === $event_source_url
		) {
			$this->reject();
		}

		$event = $this->events->get_event( $event_key );

		if ( ! is_array( $event )
			|| true !== $event['enabled']
			|| 'click' !== $event['trigger_type']
			|| true !== $event['capi']
			|| ! is_scalar( $event['event_name'] )
		) {
			$this->reject();
		}

		$event_name = trim( (string) $event['event_name'] );

		if ( '' === $event_name
			|| strlen( $event_name ) > EventBridge_Events::EVENT_NAME_MAX_LENGTH
			|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $event_name )
		) {
			$this->reject();
		}

		if ( ! $this->meta_capi->send_custom_event( $event_name, $event_id, $event_source_url ) ) {
			$this->reject();
		}

		wp_send_json_success( array( 'status' => 'started' ) );
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

	private function reject() {
		wp_send_json_error( array( 'status' => 'rejected' ) );
	}
}
