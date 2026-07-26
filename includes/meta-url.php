<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Meta_URL {
	const MAX_LENGTH = 2048;

	public static function canonicalize( $url ) {
		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = trim( $url );
		if ( '' === $url || strlen( $url ) > self::MAX_LENGTH || preg_match( '/[\x00-\x20\x7F]/', $url ) ) {
			return '';
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		$url_parts  = wp_parse_url( $url );

		if ( ! self::has_valid_origin( $home_parts ) || ! self::has_valid_origin( $url_parts ) ) {
			return '';
		}

		$home_scheme = strtolower( $home_parts['scheme'] );
		$url_scheme  = strtolower( $url_parts['scheme'] );
		if ( $home_scheme !== $url_scheme
			|| 0 !== strcasecmp( $home_parts['host'], $url_parts['host'] )
			|| self::get_effective_port( $home_parts ) !== self::get_effective_port( $url_parts )
		) {
			return '';
		}

		$home_path = self::normalize_path( isset( $home_parts['path'] ) ? $home_parts['path'] : '/' );
		$url_path  = self::normalize_path( isset( $url_parts['path'] ) ? $url_parts['path'] : '/' );
		if ( false === $home_path || false === $url_path ) {
			return '';
		}

		$home_base = '/' === $home_path ? '/' : rtrim( $home_path, '/' );
		if ( '/' !== $home_base && $url_path !== $home_base && 0 !== strpos( $url_path, $home_base . '/' ) ) {
			return '';
		}

		$host = strtolower( $home_parts['host'] );
		if ( false !== strpos( $host, ':' ) && '[' !== substr( $host, 0, 1 ) ) {
			$host = '[' . $host . ']';
		}

		$origin = $home_scheme . '://' . $host;
		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . (int) $home_parts['port'];
		}

		$canonical_url = $origin . $url_path;

		return strlen( $canonical_url ) <= self::MAX_LENGTH ? $canonical_url : '';
	}

	private static function has_valid_origin( $parts ) {
		if ( ! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| empty( $parts['host'] )
			|| ! is_string( $parts['scheme'] )
			|| ! is_string( $parts['host'] )
			|| ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| preg_match( '/[\x00-\x20\x7F\/\\\\@]/', $parts['host'] )
		) {
			return false;
		}

		if ( isset( $parts['port'] ) && ( ! is_int( $parts['port'] ) || $parts['port'] < 1 || $parts['port'] > 65535 ) ) {
			return false;
		}

		return true;
	}

	private static function get_effective_port( $parts ) {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( $parts['scheme'] ) ? 443 : 80;
	}

	private static function normalize_path( $path ) {
		if ( ! is_string( $path ) || preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) || preg_match( '/%(?![0-9A-Fa-f]{2})/', $path ) ) {
			return false;
		}

		if ( '' === $path ) {
			return '/';
		}

		if ( '/' !== substr( $path, 0, 1 ) ) {
			return false;
		}

		$segments       = array();
		$trailing_slash = '/' === substr( $path, -1 );

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment ) {
				continue;
			}

			$decoded = rawurldecode( $segment );
			if ( preg_match( '/[\x00-\x1F\x7F\/\\\\]/', $decoded ) ) {
				return false;
			}

			if ( '.' === $decoded ) {
				continue;
			}

			if ( '..' === $decoded ) {
				array_pop( $segments );
				continue;
			}

			$encoded_segment = rawurlencode( $decoded );
			$segments[]       = str_replace(
				array( '%21', '%24', '%26', '%27', '%28', '%29', '%2A', '%2B', '%2C', '%3B', '%3D', '%3A', '%40' ),
				array( '!', '$', '&', "'", '(', ')', '*', '+', ',', ';', '=', ':', '@' ),
				$encoded_segment
			);
		}

		$normalized = '/' . implode( '/', $segments );
		if ( $trailing_slash && '/' !== $normalized ) {
			$normalized .= '/';
		}

		return $normalized;
	}
}
