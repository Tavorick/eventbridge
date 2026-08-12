<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Profile_Service {
	const AJAX_ACTION = 'eventbridge_profile_capture';
	private $tokens;
	private $profiles;

	public function __construct( EventBridge_Profile_Token $tokens, EventBridge_Profile_Repository $profiles ) { $this->tokens = $tokens; $this->profiles = $profiles; }
	public function init() {
		add_action( 'template_redirect', array( $this, 'capture_request' ), 2 );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_capture' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_capture' ) );
	}
	public function capture_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_robots() ) return;
		$token = $this->tokens->get_or_create(); $this->tokens->clear_legacy_cookie();
		$touch = $this->touch_from_request( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '' );
		if ( $touch ) $this->persist_touch( $token, $touch );
	}
	public function handle_capture() {
		if ( 'POST' !== strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '' ) || ! $this->same_origin() ) wp_send_json_error( array( 'reason' => 'invalid_request' ), 403 );
		$landing = isset( $_POST['landing_url'] ) && is_string( $_POST['landing_url'] ) ? wp_unslash( $_POST['landing_url'] ) : '';
		$referrer = isset( $_POST['referrer'] ) && is_string( $_POST['referrer'] ) ? wp_unslash( $_POST['referrer'] ) : '';
		$fields = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$touch = $this->normalize_touch( $landing, $referrer, $fields );
		$token = $this->tokens->get_or_create(); $this->tokens->clear_legacy_cookie();
		if ( $touch ) $this->persist_touch( $token, $touch );
		wp_send_json_success( array( 'captured' => (bool) $touch ) );
	}
	public function link_external( $provider, $entity_type, $external_id ) {
		$token = $this->tokens->get_or_create(); $hash = $this->tokens->hash( $token ); if ( '' === $hash ) return false;
		$profile = $this->profiles->get_or_create( $hash ); return is_array( $profile ) && $this->profiles->link( $profile['id'], $provider, $entity_type, $external_id );
	}
	private function persist_touch( $token, $touch ) { $hash = $this->tokens->hash( $token ); if ( '' === $hash ) return false; $profile = $this->profiles->get_or_create( $hash ); return is_array( $profile ) && $this->profiles->save_touch( $profile, $touch ); }
	private function touch_from_request( $request_uri, $referrer ) { $url = home_url( '/' . ltrim( strtok( (string) $request_uri, '#' ), '/' ) ); $parts = wp_parse_url( $url ); parse_str( isset( $parts['query'] ) ? $parts['query'] : '', $fields ); return $this->normalize_touch( $url, $referrer, $fields ); }
	private function normalize_touch( $landing, $referrer, $fields ) {
		$landing = $this->landing_url( $landing ); if ( '' === $landing || ! is_array( $fields ) ) return false;
		$touch = array( 'version' => 1, 'captured_at' => gmdate( 'c' ), 'landing_url' => $landing ); $has = false;
		foreach ( array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'ttclid' ) as $key ) { if ( isset( $fields[ $key ] ) && is_string( $fields[ $key ] ) && $this->value( $fields[ $key ] ) ) { $touch[ $key ] = trim( $fields[ $key ] ); $has = true; } }
		$origin = $this->external_origin( $referrer ); if ( '' !== $origin ) { $touch['referrer'] = $origin; $has = true; }
		return $has ? $touch : false;
	}
	private function landing_url( $url ) { $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) ); if ( ! is_array( $parts ) || ! is_array( $home ) || empty( $parts['host'] ) || 0 !== strcasecmp( $parts['host'], $home['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) return ''; $origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ); if ( isset( $parts['port'] ) ) $origin .= ':' . (int) $parts['port']; return $origin . ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' ); }
	private function external_origin( $url ) { $parts = wp_parse_url( $url ); $home = wp_parse_url( home_url( '/' ) ); if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || ( is_array( $home ) && isset( $home['host'] ) && 0 === strcasecmp( $parts['host'], $home['host'] ) ) ) return ''; $origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] ); return isset( $parts['port'] ) ? $origin . ':' . (int) $parts['port'] : $origin; }
	private function value( $value ) { return '' !== trim( $value ) && strlen( $value ) <= 255 && trim( $value ) === $value && ! preg_match( '/[\x00-\x1F\x7F]/', $value ); }
	private function same_origin() {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' );
		$home = wp_parse_url( home_url( '/' ) ); $source = wp_parse_url( $origin );
		if ( ! is_array( $home ) || ! is_array( $source ) || ! isset( $home['scheme'], $home['host'], $source['scheme'], $source['host'] ) ) return false;
		return 0 === strcasecmp( $home['scheme'], $source['scheme'] ) && 0 === strcasecmp( $home['host'], $source['host'] ) && ( isset( $home['port'] ) ? (int) $home['port'] : 0 ) === ( isset( $source['port'] ) ? (int) $source['port'] : 0 );
	}
}
