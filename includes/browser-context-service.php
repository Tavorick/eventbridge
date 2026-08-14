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
		$values = array();
		foreach ( array( '_fbp', '_fbc' ) as $key ) {
			$value = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? trim( $input[ $key ] ) : '';
			if ( '' !== $value && strlen( $value ) <= 255 && ! preg_match( '/[\x00-\x1F\x7F]/', $value ) ) $values[ $key ] = $value;
		}
		if ( empty( $values ) ) wp_send_json_success( array( 'captured' => false ) );
		$token = $this->tokens->get_or_create(); $hash = $this->tokens->hash( $token );
		if ( '' === $hash ) wp_send_json_error( array( 'reason' => 'profile_unavailable' ), 500 );
		$profile = $this->profiles->get_or_create( $hash );
		$saved = is_array( $profile ) && $this->contexts->save( $profile['id'], 'browser_cookie', $values );
		$saved ? wp_send_json_success( array( 'captured' => true ) ) : wp_send_json_error( array( 'reason' => 'storage_failed' ), 500 );
	}

	private function same_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );
		$home = wp_parse_url( home_url( '/' ) ); $source = wp_parse_url( $origin );
		if ( ! is_array( $home ) || ! is_array( $source ) || ! isset( $home['scheme'], $home['host'], $source['scheme'], $source['host'] ) ) return false;
		return 0 === strcasecmp( $home['scheme'], $source['scheme'] ) && 0 === strcasecmp( $home['host'], $source['host'] ) && ( isset( $home['port'] ) ? (int) $home['port'] : 0 ) === ( isset( $source['port'] ) ? (int) $source['port'] : 0 );
	}
}
