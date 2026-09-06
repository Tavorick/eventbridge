<?php

defined( 'ABSPATH' ) || exit;

/** Captures allowlisted browser identifiers independently from attribution/linking. */
class EventBridge_Browser_Context_Service {
	const AJAX_ACTION = 'eventbridge_browser_context_capture';
	private $tokens;
	private $profiles;
	private $contexts;

	public function __construct( EventBridge_Profile_Token $tokens, EventBridge_Profile_Repository $profiles, EventBridge_Profile_Context_Repository $contexts ) {
		$this->tokens = $tokens; $this->profiles = $profiles; $this->contexts = $contexts;
	}

	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_capture' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_capture' ) );
	}

	public function handle_capture() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) || ! $this->same_origin() ) {
			wp_send_json_error( array( 'reason' => 'invalid_request' ), 403 );
		}
		$input = isset( $_POST['browser_cookie'] ) && is_array( $_POST['browser_cookie'] ) ? wp_unslash( $_POST['browser_cookie'] ) : array();
		$values = $this->normalize_values( $input );
		if ( empty( $values ) ) wp_send_json_success( array( 'captured' => false ) );
		$saved = $this->persist_values( $values );
		$saved ? wp_send_json_success( array( 'captured' => true ) ) : wp_send_json_error( array( 'reason' => 'storage_failed' ), 500 );
	}

	/** Synchronize Meta browser cookies present on the current first-party request. */
	public function capture_request_cookies( $profile_id = 0 ) {
		try {
			$input = array();
			foreach ( array( '_fbp', '_fbc' ) as $key ) {
				if ( isset( $_COOKIE[ $key ] ) && is_string( $_COOKIE[ $key ] ) ) $input[ $key ] = wp_unslash( $_COOKIE[ $key ] );
			}
			$values = $this->normalize_values( $input );
			return empty( $values ) ? true : $this->persist_values( $values, $profile_id );
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	private function normalize_values( array $input ) {
		$values = array();
		foreach ( array( '_fbp', '_fbc' ) as $key ) {
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			if ( $this->is_valid_browser_identifier( $value ) ) $values[ $key ] = $value;
		}
		return $values;
	}

	private function persist_values( array $values, $profile_id = 0 ) {
		$profile_id = absint( $profile_id );
		if ( $profile_id ) return $this->contexts->save( $profile_id, 'browser_cookie', $values );
		$token = $this->tokens->get_or_create();
		$hash  = $this->tokens->hash( $token );
		if ( '' === $hash ) return false;
		$profile = $this->profiles->get_or_create( $hash );
		return is_array( $profile ) && $this->contexts->save( $profile['id'], 'browser_cookie', $values );
	}

	private function is_valid_browser_identifier( $value ) {
		return is_string( $value ) && strlen( $value ) <= 255 && (bool) preg_match( '/^fb\.1\.[0-9]{10,16}\.[A-Za-z0-9._-]+$/D', $value );
	}

	private function same_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );
		$home = wp_parse_url( home_url( '/' ) ); $source = wp_parse_url( $origin );
		if ( ! is_array( $home ) || ! is_array( $source ) || ! isset( $home['scheme'], $home['host'], $source['scheme'], $source['host'] ) ) return false;
		return 0 === strcasecmp( $home['scheme'], $source['scheme'] ) && 0 === strcasecmp( $home['host'], $source['host'] ) && ( isset( $home['port'] ) ? (int) $home['port'] : 0 ) === ( isset( $source['port'] ) ? (int) $source['port'] : 0 );
	}
}
