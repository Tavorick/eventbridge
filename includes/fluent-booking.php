<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Fluent_Booking {
	const HASH_MAX_LENGTH    = 192;
	const CONTEXT_MAX_LENGTH = 8192;
	const CONTEXT_TTL        = 1800;
	const CONTEXT_CLOCK_SKEW = 60;

	private $cache = array();

	public function is_available() {
		return defined( 'FLUENT_BOOKING_VERSION' ) && class_exists( '\\FluentBooking\\App\\Models\\Booking' );
	}

	public function has_parameter_sources( $event ) {
		foreach ( $this->get_parameters( $event ) as $parameter ) {
			if ( 'fluent_booking' === $parameter['source'] ) {
				return true;
			}
		}

		return false;
	}

	public function has_advanced_matching( $event ) {
		$mapping = is_array( $event ) && isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array();

		foreach ( array( 'email', 'phone', 'first_name', 'last_name' ) as $field ) {
			if ( isset( $mapping[ $field ]['source'] ) && 'fluent_booking' === $mapping[ $field ]['source'] ) {
				return true;
			}
		}

		return false;
	}

	public function needs_lookup( $event ) {
		return $this->has_parameter_sources( $event ) || $this->has_advanced_matching( $event );
	}

	public function is_capi_dependent( $event ) {
		return ! empty( $event['capi'] ) && $this->needs_lookup( $event );
	}

	public function resolve( $event, $query ) {
		if ( ! $this->needs_lookup( $event ) || ! $this->is_available() ) {
			return false;
		}

		$data_source = $this->get_data_source( $event );
		$hash        = $this->get_hash( $query, $data_source['lookup_value'] );
		if ( '' === $hash ) {
			return false;
		}

		$cache_key = hash( 'sha256', $hash . '|' . $data_source['expected_event_id'] );
		if ( array_key_exists( $cache_key, $this->cache ) ) {
			return $this->cache[ $cache_key ];
		}

		$this->cache[ $cache_key ] = false;

		try {
			$booking_class = '\\FluentBooking\\App\\Models\\Booking';
			$booking       = $booking_class::where( 'hash', $hash )->first();

			if ( ! $booking instanceof $booking_class || 'scheduled' !== (string) $booking->status ) {
				return false;
			}

			if ( '' !== $data_source['expected_event_id'] && (string) $booking->event_id !== $data_source['expected_event_id'] ) {
				return false;
			}

			$calendar_event = null;
			$phone          = isset( $booking->phone ) ? $booking->phone : '';
			if ( $this->needs_parameter_field( $event, 'event_title' ) || $this->needs_advanced_field( $event, 'phone' ) ) {
				try {
					$calendar_event = $booking->calendar_event;
				} catch ( Throwable $throwable ) {
					$calendar_event = null;
				}
			}
			if ( $this->needs_advanced_field( $event, 'phone' ) && is_object( $calendar_event ) ) {
				try {
					$phone = $booking->getInviteePhoneNumber( $calendar_event );
				} catch ( Throwable $throwable ) {
					$phone = isset( $booking->phone ) ? $booking->phone : '';
				}
			}

			$snapshot = array(
				'booking_id' => $this->get_scalar_value( $booking->id ),
				'event_id'   => $this->get_scalar_value( $booking->event_id ),
				'calendar_id' => $this->get_scalar_value( $booking->calendar_id ),
				'start_time' => $this->get_scalar_value( $booking->start_time ),
				'event_title' => is_object( $calendar_event ) && isset( $calendar_event->title ) ? $this->get_scalar_value( $calendar_event->title ) : '',
				'email'      => $this->get_scalar_value( $booking->email ),
				'phone'      => $this->get_scalar_value( $phone ),
				'first_name' => $this->get_scalar_value( $booking->first_name ),
				'last_name'  => $this->get_scalar_value( $booking->last_name ),
			);

			$this->cache[ $cache_key ] = $snapshot;
			return $snapshot;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	public function get_parameter_data( $event, $snapshot ) {
		$data     = array();
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		foreach ( $this->get_parameters( $event ) as $parameter ) {
			if ( 'fluent_booking' !== $parameter['source'] || ! isset( $snapshot[ $parameter['value'] ] ) || '' === $snapshot[ $parameter['value'] ] ) {
				continue;
			}

			$data[ $parameter['name'] ] = $snapshot[ $parameter['value'] ];
		}

		return $data;
	}

	public function get_advanced_matching_values( $event, $snapshot ) {
		$values   = array();
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$mapping  = is_array( $event ) && isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array();

		foreach ( array( 'email', 'phone', 'first_name', 'last_name' ) as $field ) {
			if ( isset( $mapping[ $field ]['source'] ) && 'fluent_booking' === $mapping[ $field ]['source'] && ! empty( $snapshot[ $field ] ) ) {
				$values[ $field ] = $snapshot[ $field ];
			}
		}

		return $values;
	}

	public function create_context( $event_key, $event, $event_source_url, $custom_data, $user_data ) {
		$custom_data = $this->filter_custom_data( $event, $custom_data );
		$user_data   = $this->filter_user_data( $event, $user_data );
		$issued_at   = time();
		$payload     = wp_json_encode( array(
			'version'     => 1,
			'issued_at'   => $issued_at,
			'expires_at'  => $issued_at + self::CONTEXT_TTL,
			'custom_data' => $custom_data,
			'user_data'   => $user_data,
		) );
		$key         = $this->get_context_key();

		if ( ! is_string( $payload ) || '' === $key || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt( $payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $this->get_context_aad( $event_key, $event, $event_source_url ), 16 );
		if ( ! is_string( $ciphertext ) || '' === $ciphertext || 16 !== strlen( $tag ) ) {
			return '';
		}

		$context = 'v1.' . $this->base64url_encode( $iv ) . '.' . $this->base64url_encode( $tag ) . '.' . $this->base64url_encode( $ciphertext );
		return strlen( $context ) <= self::CONTEXT_MAX_LENGTH ? $context : '';
	}

	public function verify_context( $event_key, $event, $event_source_url, $context ) {
		if ( ! is_string( $context ) || '' === $context || strlen( $context ) > self::CONTEXT_MAX_LENGTH || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$parts = explode( '.', $context );
		if ( 4 !== count( $parts ) || 'v1' !== $parts[0] ) {
			return false;
		}

		$iv         = $this->base64url_decode( $parts[1] );
		$tag        = $this->base64url_decode( $parts[2] );
		$ciphertext = $this->base64url_decode( $parts[3] );
		$key        = $this->get_context_key();
		if ( ! is_string( $iv ) || 12 !== strlen( $iv ) || ! is_string( $tag ) || 16 !== strlen( $tag ) || ! is_string( $ciphertext ) || '' === $ciphertext || '' === $key ) {
			return false;
		}

		$payload = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $this->get_context_aad( $event_key, $event, $event_source_url ) );
		$decoded = is_string( $payload ) ? json_decode( $payload, true ) : null;
		if ( ! is_array( $decoded ) || ! isset( $decoded['version'], $decoded['issued_at'], $decoded['expires_at'], $decoded['custom_data'], $decoded['user_data'] ) || 1 !== $decoded['version'] || ! is_int( $decoded['issued_at'] ) || ! is_int( $decoded['expires_at'] ) || ! is_array( $decoded['custom_data'] ) || ! is_array( $decoded['user_data'] ) ) {
			return false;
		}

		$now = time();
		if ( $decoded['issued_at'] > $now + self::CONTEXT_CLOCK_SKEW || $decoded['expires_at'] < $now || $decoded['expires_at'] <= $decoded['issued_at'] || $decoded['expires_at'] - $decoded['issued_at'] > self::CONTEXT_TTL ) {
			return false;
		}

		$custom_data = $this->filter_custom_data( $event, $decoded['custom_data'] );
		$user_data   = $this->filter_user_data( $event, $decoded['user_data'] );
		if ( $custom_data !== $decoded['custom_data'] || $user_data !== $decoded['user_data'] ) {
			return false;
		}

		return array( 'custom_data' => $custom_data, 'user_data' => $user_data );
	}

	private function get_hash( $query, $lookup_value ) {
		if ( ! is_array( $query ) || '' === $lookup_value || ! isset( $query[ $lookup_value ] ) || ! is_scalar( $query[ $lookup_value ] ) ) {
			return '';
		}

		$raw  = trim( wp_unslash( (string) $query[ $lookup_value ] ) );
		$safe = sanitize_text_field( $raw );
		if ( '' === $raw || strlen( $raw ) > self::HASH_MAX_LENGTH || $raw !== $safe || $raw !== wp_strip_all_tags( $raw ) || preg_match( '/[\x00-\x1F\x7F]/', $raw ) ) {
			return '';
		}

		return $raw;
	}

	private function get_data_source( $event ) {
		$defaults = array( 'provider' => '', 'lookup_source' => '', 'lookup_value' => '', 'expected_event_id' => '' );
		return wp_parse_args( is_array( $event ) && isset( $event['data_source'] ) && is_array( $event['data_source'] ) ? $event['data_source'] : array(), $defaults );
	}

	private function get_parameters( $event ) {
		return is_array( $event ) && isset( $event['parameters'] ) && is_array( $event['parameters'] ) ? $event['parameters'] : array();
	}

	private function needs_parameter_field( $event, $field ) {
		foreach ( $this->get_parameters( $event ) as $parameter ) {
			if ( isset( $parameter['source'], $parameter['value'] ) && 'fluent_booking' === $parameter['source'] && $field === $parameter['value'] ) {
				return true;
			}
		}

		return false;
	}

	private function needs_advanced_field( $event, $field ) {
		return is_array( $event ) && isset( $event['advanced_matching'][ $field ]['source'] ) && 'fluent_booking' === $event['advanced_matching'][ $field ]['source'];
	}

	private function filter_custom_data( $event, $custom_data ) {
		$filtered    = array();
		$custom_data = is_array( $custom_data ) ? $custom_data : array();

		foreach ( $this->get_parameters( $event ) as $parameter ) {
			$name = isset( $parameter['name'] ) && is_string( $parameter['name'] ) ? $parameter['name'] : '';
			if ( 'fluent_booking' !== $parameter['source'] || '' === $name || ! isset( $custom_data[ $name ] ) || ! is_scalar( $custom_data[ $name ] ) ) {
				continue;
			}

			$value = sanitize_text_field( (string) $custom_data[ $name ] );
			if ( '' !== $value && strlen( $value ) <= 500 ) {
				$filtered[ $name ] = $value;
			}
		}

		return $filtered;
	}

	private function filter_user_data( $event, $user_data ) {
		$filtered  = array();
		$user_data = is_array( $user_data ) ? $user_data : array();
		$mapping   = is_array( $event ) && isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array();
		$meta_keys = array( 'email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln' );

		foreach ( $meta_keys as $field => $meta_key ) {
			if ( isset( $mapping[ $field ]['source'] ) && 'fluent_booking' === $mapping[ $field ]['source'] && isset( $user_data[ $meta_key ] ) && is_string( $user_data[ $meta_key ] ) && preg_match( '/^[a-f0-9]{64}$/D', $user_data[ $meta_key ] ) ) {
				$filtered[ $meta_key ] = $user_data[ $meta_key ];
			}
		}

		return $filtered;
	}

	private function get_context_key() {
		$material = wp_salt( 'auth' );
		return function_exists( 'hash_hkdf' ) ? hash_hkdf( 'sha256', $material, 32, 'eventbridge-fluent-booking-context-v1', 'eventbridge' ) : hash( 'sha256', 'eventbridge-fluent-booking-context-v1|' . $material, true );
	}

	private function get_context_aad( $event_key, $event, $event_source_url ) {
		$data_source = $this->get_data_source( $event );
		$fingerprint = hash( 'sha256', wp_json_encode( array( $data_source, $this->get_parameters( $event ), isset( $event['advanced_matching'] ) ? $event['advanced_matching'] : array() ) ) );
		return 'eventbridge|fluent_booking|v1|' . $event_key . '|' . ( isset( $event['trigger_type'] ) ? $event['trigger_type'] : '' ) . '|' . $event_source_url . '|' . $fingerprint;
	}

	private function get_scalar_value( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
	}

	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			return false;
		}

		$encoded = strtr( $value, '-_', '+/' );
		return base64_decode( $encoded . str_repeat( '=', ( 4 - strlen( $encoded ) % 4 ) % 4 ), true );
	}
}
