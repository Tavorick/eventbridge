<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Profile_Token {
	const COOKIE_NAME = 'eventbridge_profile_v1';
	const LEGACY_COOKIE_NAME = 'eventbridge_attribution_transport_v1';
	const TOKEN_BYTES = 32;

	public function get_or_create() {
		$token = $this->get();
		if ( '' !== $token ) {
			return $token;
		}

		try {
			$token = rtrim( strtr( base64_encode( random_bytes( self::TOKEN_BYTES ) ), '+/', '-_' ), '=' );
		} catch ( Exception $exception ) {
			return '';
		}

		$this->set_cookie( self::COOKIE_NAME, $token, time() + YEAR_IN_SECONDS, true );
		return $token;
	}

	public function get() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) || ! is_string( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return '';
		}

		$token = wp_unslash( $_COOKIE[ self::COOKIE_NAME ] );
		return preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) ? $token : '';
	}

	public function hash( $token ) {
		return is_string( $token ) && preg_match( '/^[A-Za-z0-9_-]{43}$/D', $token ) ? hash( 'sha256', $token, true ) : '';
	}

	public function clear_legacy_cookie() {
		if ( isset( $_COOKIE[ self::LEGACY_COOKIE_NAME ] ) ) {
			$this->set_cookie( self::LEGACY_COOKIE_NAME, '', time() - HOUR_IN_SECONDS, false );
		}
	}

	private function set_cookie( $name, $value, $expires, $http_only ) {
		if ( headers_sent() ) {
			return false;
		}

		return setcookie( $name, $value, array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => $http_only,
			'samesite' => 'Lax',
		) );
	}
}
