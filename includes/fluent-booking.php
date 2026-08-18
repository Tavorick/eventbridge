<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Fluent_Booking {
	const HASH_MAX_LENGTH    = 192;
	const CONTEXT_MAX_LENGTH = 8192;
	const CONTEXT_TTL        = 1800;
	const CONTEXT_CLOCK_SKEW = 60;

	private $cache = array();
	private $presentation_cache = array();
	private $conversion_search_cache = array();
	private $settings;

	public function __construct( EventBridge_Fluent_Booking_Settings $settings = null ) {
		$this->settings = $settings ? $settings : new EventBridge_Fluent_Booking_Settings();
	}

	public function is_available() {
		return defined( 'FLUENT_BOOKING_VERSION' ) && class_exists( '\\FluentBooking\\App\\Models\\Booking' );
	}

	/** Returns appointment types for display; their IDs are the only persisted values. */
	public function get_appointment_types() {
		$calendar_slot_class = '\\FluentBooking\\App\\Models\\CalendarSlot';
		if ( ! $this->is_available() || ! class_exists( $calendar_slot_class ) ) {
			return array();
		}

		try {
			$slots = $calendar_slot_class::with( array( 'calendar' ) )->orderBy( 'title', 'asc' )->get();
			$types = array();
			foreach ( $slots as $slot ) {
				if ( ! is_object( $slot ) || ! isset( $slot->id ) || ! is_scalar( $slot->id ) ) {
					continue;
				}
				$id = $this->get_scalar_value( $slot->id );
				if ( '' !== $id ) {
					$calendar_name = '';
					try {
						if ( isset( $slot->calendar ) && is_object( $slot->calendar ) && isset( $slot->calendar->title ) ) {
							$calendar_name = $this->get_scalar_value( $slot->calendar->title );
						}
					} catch ( Throwable $throwable ) {
						$calendar_name = '';
					}
					$types[] = array(
						'id'            => $id,
						'title'         => isset( $slot->title ) ? $this->get_scalar_value( $slot->title ) : '',
						'calendar_name' => $calendar_name,
					);
				}
			}
			return $types;
		} catch ( Throwable $throwable ) {
			return array();
		}
	}

	/** A pure provider-owned eligibility check for later conversion handling. */
	public function is_followup_relevant( $booking ) {
		if ( ! is_object( $booking ) || ! isset( $booking->event_id ) || ! is_scalar( $booking->event_id ) ) {
			return false;
		}

		$event_id = $this->get_scalar_value( $booking->event_id );
		return '' !== $event_id && in_array( $event_id, $this->settings->get_followup_event_ids(), true );
	}

	public function get_followup_event_ids() {
		return $this->settings->get_followup_event_ids();
	}

	public function get_conversion_event_ids( $booking ) {
		if ( ! is_object( $booking ) || ! isset( $booking->event_id ) ) {
			return array();
		}
		return $this->settings->get_conversion_event_ids( $this->get_scalar_value( $booking->event_id ) );
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
				'status'     => $this->get_scalar_value( $booking->status ),
				'start_time' => $this->get_scalar_value( $booking->start_time ),
				'event_title' => is_object( $calendar_event ) && isset( $calendar_event->title ) ? $this->get_scalar_value( $calendar_event->title ) : '',
				'email'      => $this->get_scalar_value( $booking->email ),
				'phone'      => $this->get_scalar_value( $phone ),
				'first_name' => $this->get_scalar_value( $booking->first_name ),
				'last_name'  => $this->get_scalar_value( $booking->last_name ),
				'full_name'  => isset( $booking->full_name ) ? $this->get_scalar_value( $booking->full_name ) : '',
			);

			$this->cache[ $cache_key ] = $snapshot;
			return $snapshot;
		} catch ( Throwable $throwable ) {
			return false;
		}
	}

	/** Resolves canonical conversion data by Fluent's stored booking id, never by live follow-up settings. */
	public function resolve_by_external_id( $event, $external_id ) {
		if ( ! $this->is_available() || ! is_scalar( $external_id ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $external_id ) ) return false;
		try {
			$booking_class = '\\FluentBooking\\App\\Models\\Booking';
			$booking = $booking_class::where( 'id', (string) $external_id )->first();
			if ( ! $booking instanceof $booking_class ) return false;
			$calendar_event = null; $phone = isset( $booking->phone ) ? $booking->phone : '';
			if ( $this->needs_parameter_field( $event, 'event_title' ) || $this->needs_advanced_field( $event, 'phone' ) ) {
				try { $calendar_event = $booking->calendar_event; } catch ( Throwable $throwable ) { $calendar_event = null; }
			}
			if ( $this->needs_advanced_field( $event, 'phone' ) && is_object( $calendar_event ) ) {
				try { $phone = $booking->getInviteePhoneNumber( $calendar_event ); } catch ( Throwable $throwable ) { $phone = isset( $booking->phone ) ? $booking->phone : ''; }
			}
			return array(
				'booking_id' => $this->get_scalar_value( $booking->id ), 'event_id' => $this->get_scalar_value( $booking->event_id ),
				'calendar_id' => $this->get_scalar_value( $booking->calendar_id ), 'status' => $this->get_scalar_value( $booking->status ),
				'start_time' => $this->get_scalar_value( $booking->start_time ), 'event_title' => is_object( $calendar_event ) && isset( $calendar_event->title ) ? $this->get_scalar_value( $calendar_event->title ) : '',
				'email' => $this->get_scalar_value( $booking->email ), 'phone' => $this->get_scalar_value( $phone ),
				'first_name' => $this->get_scalar_value( $booking->first_name ), 'last_name' => $this->get_scalar_value( $booking->last_name ),
				'full_name' => isset( $booking->full_name ) ? $this->get_scalar_value( $booking->full_name ) : '',
			);
		} catch ( Throwable $throwable ) { return false; }
	}

	/** Returns transient display-only data. Callers must not persist or log it. */
	public function get_conversion_presentation( $external_id ) {
		$presentations = $this->get_conversion_presentations( array( $external_id ) );
		$key = is_scalar( $external_id ) ? (string) $external_id : '';
		return isset( $presentations[ $key ] ) ? $presentations[ $key ] : array();
	}

	/** Batch variant for admin lists, cached only for the lifetime of this request. */
	public function get_conversion_presentations( array $external_ids ) {
		$ids = array();
		foreach ( $external_ids as $external_id ) {
			if ( is_scalar( $external_id ) && preg_match( '/^[1-9][0-9]*$/D', (string) $external_id ) ) $ids[ (string) $external_id ] = (string) $external_id;
		}
		if ( empty( $ids ) ) return array();

		$missing = array();
		foreach ( $ids as $id ) {
			if ( ! array_key_exists( $id, $this->presentation_cache ) ) $missing[] = $id;
		}
		if ( ! empty( $missing ) ) {
			foreach ( $missing as $id ) $this->presentation_cache[ $id ] = array();
			if ( $this->is_available() ) {
				try {
					$booking_class = '\\FluentBooking\\App\\Models\\Booking';
					$bookings = $booking_class::with( array( 'calendar_event', 'calendar' ) )->whereIn( 'id', $missing )->get();
					foreach ( $bookings as $booking ) {
						if ( ! $booking instanceof $booking_class || ! isset( $booking->id ) ) continue;
						$id = $this->get_scalar_value( $booking->id );
						if ( ! isset( $ids[ $id ] ) ) continue;
						$event_title = ''; $calendar_name = '';
						try { $event_title = isset( $booking->calendar_event->title ) ? $this->get_scalar_value( $booking->calendar_event->title ) : ''; } catch ( Throwable $throwable ) { $event_title = ''; }
						try { $calendar_name = isset( $booking->calendar->title ) ? $this->get_scalar_value( $booking->calendar->title ) : ''; } catch ( Throwable $throwable ) { $calendar_name = ''; }
						$first_name = isset( $booking->first_name ) ? $this->get_scalar_value( $booking->first_name ) : '';
						$last_name  = isset( $booking->last_name ) ? $this->get_scalar_value( $booking->last_name ) : '';
						$name       = isset( $booking->full_name ) ? $this->get_scalar_value( $booking->full_name ) : trim( $first_name . ' ' . $last_name );
						$this->presentation_cache[ $id ] = array(
							'booking_id'    => $id,
							'first_name'    => $first_name,
							'last_name'     => $last_name,
							'name'          => $name,
							'phone'         => isset( $booking->phone ) ? $this->get_scalar_value( $booking->phone ) : '',
							'email'         => isset( $booking->email ) ? $this->get_scalar_value( $booking->email ) : '',
							'event_title'   => $event_title,
							'calendar_name' => $calendar_name,
						);
					}
				} catch ( Throwable $throwable ) {
					// Display data is optional; the canonical conversion remains available.
				}
			}
		}
		$result = array();
		foreach ( $ids as $id ) $result[ $id ] = $this->presentation_cache[ $id ];
		return $result;
	}

	/** Returns matching canonical booking IDs only; no Fluent PII leaves this read-only adapter. */
	public function find_conversion_booking_ids( $search ) {
		$search = is_scalar( $search ) ? trim( sanitize_text_field( (string) $search ) ) : '';
		if ( '' === $search ) return array();
		$cache_key = hash( 'sha256', $search );
		if ( array_key_exists( $cache_key, $this->conversion_search_cache ) ) return $this->conversion_search_cache[ $cache_key ];
		$this->conversion_search_cache[ $cache_key ] = array();
		if ( ! $this->is_available() ) return array();

		try {
			global $wpdb;
			$booking_class = '\\FluentBooking\\App\\Models\\Booking';
			$pattern = '%' . $wpdb->esc_like( $search ) . '%';
			$query = $booking_class::query();
			$query->where( function ( $query ) use ( $pattern, $search ) {
				$query->where( 'first_name', 'LIKE', $pattern )
					->orWhere( 'last_name', 'LIKE', $pattern )
					->orWhere( 'email', 'LIKE', $pattern )
					->orWhere( 'phone', 'LIKE', $pattern );
				if ( preg_match( '/^[1-9][0-9]*$/D', $search ) ) $query->orWhere( 'id', '=', absint( $search ) );
				$query->orWhereHas( 'calendar_event', function ( $relation ) use ( $pattern ) { $relation->where( 'title', 'LIKE', $pattern ); } );
				$query->orWhereHas( 'calendar', function ( $relation ) use ( $pattern ) { $relation->where( 'title', 'LIKE', $pattern ); } );
			} );
			$ids = array();
			foreach ( $query->pluck( 'id' ) as $booking_id ) {
				$booking_id = is_scalar( $booking_id ) ? (string) $booking_id : '';
				if ( preg_match( '/^[1-9][0-9]*$/D', $booking_id ) ) $ids[ $booking_id ] = $booking_id;
			}
			$this->conversion_search_cache[ $cache_key ] = array_values( $ids );
		} catch ( Throwable $throwable ) {
			// Canonical EventBridge search remains available when Fluent lookup fails.
		}
		return $this->conversion_search_cache[ $cache_key ];
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

		$context = 'v2.' . $this->base64url_encode( $iv ) . '.' . $this->base64url_encode( $tag ) . '.' . $this->base64url_encode( $ciphertext );
		return strlen( $context ) <= self::CONTEXT_MAX_LENGTH ? $context : '';
	}

	public function verify_context( $event_key, $event, $event_source_url, $context ) {
		if ( ! is_string( $context ) || '' === $context || strlen( $context ) > self::CONTEXT_MAX_LENGTH || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}

		$parts = explode( '.', $context );
		if ( 4 !== count( $parts ) || ! in_array( $parts[0], array( 'v1', 'v2' ), true ) ) {
			return false;
		}
		$has_route_id = isset( $event['trigger_id'] ) && is_string( $event['trigger_id'] )
			&& preg_match( '/^trg_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event['trigger_id'] );
		if ( 'v1' === $parts[0] && $has_route_id && empty( $event['eventbridge_is_compatibility_trigger'] ) ) {
			return false;
		}

		$iv         = $this->base64url_decode( $parts[1] );
		$tag        = $this->base64url_decode( $parts[2] );
		$ciphertext = $this->base64url_decode( $parts[3] );
		$key        = $this->get_context_key();
		if ( ! is_string( $iv ) || 12 !== strlen( $iv ) || ! is_string( $tag ) || 16 !== strlen( $tag ) || ! is_string( $ciphertext ) || '' === $ciphertext || '' === $key ) {
			return false;
		}

		$legacy  = 'v1' === $parts[0];
		$payload = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $this->get_context_aad( $event_key, $event, $event_source_url, $legacy ) );
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

	private function get_context_aad( $event_key, $event, $event_source_url, $legacy = false ) {
		$data_source = $this->get_data_source( $event );
		$fingerprint = hash( 'sha256', wp_json_encode( array( $data_source, $this->get_parameters( $event ), isset( $event['advanced_matching'] ) ? $event['advanced_matching'] : array() ) ) );
		if ( $legacy ) {
			return 'eventbridge|fluent_booking|v1|' . $event_key . '|' . ( isset( $event['trigger_type'] ) ? $event['trigger_type'] : '' ) . '|' . $event_source_url . '|' . $fingerprint;
		}

		$trigger_id = isset( $event['trigger_id'] ) && is_string( $event['trigger_id'] ) ? $event['trigger_id'] : '';
		return 'eventbridge|fluent_booking|v2|' . $event_key . '|' . $trigger_id . '|' . ( isset( $event['trigger_type'] ) ? $event['trigger_type'] : '' ) . '|' . $event_source_url . '|' . $fingerprint;
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
