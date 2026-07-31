<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Events {
	const OPTION_NAME = 'eventbridge_events';

	const LABEL_MAX_LENGTH       = 100;
	const DESCRIPTION_MAX_LENGTH = 500;
	const EVENT_NAME_MAX_LENGTH  = 100;
	const SELECTOR_MAX_LENGTH        = 255;
	const URL_MATCH_VALUE_MAX_LENGTH = 2048;
	const PARAMETER_NAME_MAX_LENGTH  = 100;
	const PARAMETER_VALUE_MAX_LENGTH = 500;
	const QUERY_PARAMETER_NAME_MAX_LENGTH = 100;
	const FLUENT_FIELD_MAX_LENGTH         = 50;
	const META_TEST_EVENT_CODE_MAX_LENGTH = 64;
	const PARAMETER_CONTEXT_MAX_LENGTH    = 65536;
	const ADVANCED_MATCHING_CONTEXT_MAX_LENGTH = 4096;
	const ADVANCED_MATCHING_CONTEXT_TTL        = 1800;
	const ADVANCED_MATCHING_CONTEXT_CLOCK_SKEW = 60;

	private $woocommerce;
	private $conditions;
	private $triggers;

	public function __construct( EventBridge_WooCommerce $woocommerce = null, EventBridge_Conditions $conditions = null, EventBridge_Triggers $triggers = null ) {
		$this->woocommerce = $woocommerce;
		$this->conditions  = $conditions;
		$this->triggers    = $triggers ? $triggers : new EventBridge_Triggers();
	}

	public function get_events() {
		$events = get_option( self::OPTION_NAME, array() );

		return is_array( $events ) ? $events : array();
	}

	public function get_normalized_events() {
		$normalized_events = array();

		foreach ( $this->get_events() as $event_key => $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$normalized_events[ $event_key ] = $this->normalize_event( $event, $event_key );
		}

		return $normalized_events;
	}

	public function get_active_fluent_lookup_parameters( $exclude_event_key = '' ) {
		$lookup_parameters = array();
		$exclude_event_key = is_string( $exclude_event_key ) ? $exclude_event_key : '';

		foreach ( $this->get_normalized_events() as $event_key => $event ) {
			if ( $event_key === $exclude_event_key || true !== (bool) $event['enabled'] ) {
				continue;
			}

			foreach ( $event['triggers'] as $trigger ) {
				$route = $this->get_effective_event( $event, $trigger );
				if ( 'fluent_booking' !== $route['data_source']['provider']
					|| 'query_parameter' !== $route['data_source']['lookup_source']
					|| ! preg_match( '/^[A-Za-z0-9_]{1,100}$/D', $route['data_source']['lookup_value'] )
				) {
					continue;
				}

				$lookup_parameters[] = $route['data_source']['lookup_value'];
			}
		}

		return array_values( array_unique( $lookup_parameters ) );
	}

	public function get_tracking_query( $query ) {
		$query             = is_array( $query ) ? $query : array();
		$lookup_parameters = $this->get_active_fluent_lookup_parameters();
		$lookup_values     = array();

		foreach ( $lookup_parameters as $lookup_parameter ) {
			if ( ! array_key_exists( $lookup_parameter, $query ) || ! is_scalar( $query[ $lookup_parameter ] ) ) {
				continue;
			}

			$lookup_value = trim( wp_unslash( (string) $query[ $lookup_parameter ] ) );
			if ( '' !== $lookup_value ) {
				$lookup_values[] = $lookup_value;
			}
		}

		$tracking_query = array();
		foreach ( $query as $parameter_name => $parameter_value ) {
			if ( ! is_string( $parameter_name ) || in_array( $parameter_name, $lookup_parameters, true ) ) {
				continue;
			}

			if ( is_scalar( $parameter_value )
				&& in_array( trim( wp_unslash( (string) $parameter_value ) ), $lookup_values, true )
			) {
				continue;
			}

			$tracking_query[ $parameter_name ] = $parameter_value;
		}

		return $tracking_query;
	}

	public function get_form_defaults() {
		$trigger_defaults = $this->triggers->get_trigger_defaults();

		return array(
			'label'       => '',
			'description' => '',
			'event_name'  => '',
			'channels'    => array(
				'browser' => false,
				'capi'    => false,
			),
			'browser'     => false,
			'capi'        => false,
			'meta_test_mode'       => false,
			'meta_test_event_code' => '',
			'enabled'     => true,
			'trigger_type' => 'click',
			'selector'     => '',
			'url_match_type'  => '',
			'url_match_value' => '',
			'parameters'   => array(),
			'conditions'   => array(),
			'data_source' => $this->get_data_source_defaults(),
			'advanced_matching' => $this->get_advanced_matching_defaults(),
			'woocommerce' => $this->woocommerce ? $this->woocommerce->get_configuration_defaults() : array(
				'event'           => '',
				'status'          => '',
				'purchase_preset' => false,
			),
			'remove_query_parameters' => true,
			'triggers' => array( $trigger_defaults ),
			'eventbridge_schema_version' => EventBridge_Triggers::SCHEMA_VERSION,
			'eventbridge_compat' => array(
				'legacy_trigger_id'      => '',
				'legacy_projection_hash' => '',
			),
		);
	}

	private function normalize_projected_event( $event ) {
		$event               = wp_parse_args( is_array( $event ) ? $event : array(), $this->get_form_defaults() );
		unset( $event['triggers'], $event['eventbridge_schema_version'], $event['eventbridge_compat'] );
		$event['channels'] = $this->triggers->normalize_channels( $event['channels'] );
		$event['parameters'] = $this->normalize_parameters( $event['parameters'] );
		$event['conditions'] = $this->conditions ? $this->conditions->normalize_conditions( $event['conditions'] ) : array();
		$event['data_source'] = $this->normalize_data_source( $event['data_source'] );
		$event['advanced_matching'] = $this->normalize_advanced_matching( $event['advanced_matching'] );
		$event['woocommerce'] = $this->woocommerce
			? $this->woocommerce->normalize_configuration( $event['woocommerce'] )
			: wp_parse_args( is_array( $event['woocommerce'] ) ? $event['woocommerce'] : array(), array( 'event' => '', 'status' => '', 'purchase_preset' => false ) );
		$event['remove_query_parameters'] = (bool) $event['remove_query_parameters'];
		$event['meta_test_mode'] = (bool) $event['meta_test_mode'];
		$event['meta_test_event_code'] = is_scalar( $event['meta_test_event_code'] ) ? trim( (string) $event['meta_test_event_code'] ) : '';

		if ( ! (bool) $event['capi']
			|| ! $event['meta_test_mode']
			|| ! preg_match( '/^TEST[0-9]+$/D', $event['meta_test_event_code'] )
			|| $this->get_length( $event['meta_test_event_code'] ) > self::META_TEST_EVENT_CODE_MAX_LENGTH
		) {
			$event['meta_test_mode']       = false;
			$event['meta_test_event_code'] = '';
		}

		return $event;
	}

	public function normalize_event( $event, $event_key = '' ) {
		$raw_event    = is_array( $event ) ? $event : array();
		$has_triggers = isset( $raw_event['triggers'] ) && is_array( $raw_event['triggers'] );
		$raw_event    = $this->triggers->reconcile_legacy_projection( $raw_event, $event_key );
		$raw_triggers = $has_triggers && isset( $raw_event['triggers'] ) && is_array( $raw_event['triggers'] )
			? array_slice( $raw_event['triggers'], 0, EventBridge_Triggers::MAX_TRIGGERS + 1 )
			: array( $this->triggers->from_legacy_event( $raw_event, $event_key ) );
		$compatibility = isset( $raw_event['eventbridge_compat'] ) && is_array( $raw_event['eventbridge_compat'] )
			? $raw_event['eventbridge_compat']
			: array();
		$legacy_trigger_id = isset( $compatibility['legacy_trigger_id'] ) && is_string( $compatibility['legacy_trigger_id'] )
			? $compatibility['legacy_trigger_id']
			: $this->triggers->get_legacy_trigger_id( $event_key );
		$migration    = $this->triggers->migrate_event_structure( $raw_event, $raw_triggers, $legacy_trigger_id );
		$raw_event    = $migration['event'];
		$raw_triggers = $migration['triggers'];
		$base         = $this->normalize_projected_event( $raw_event );
		$normalized   = array();

		foreach ( $raw_triggers as $raw_trigger ) {
			if ( ! is_array( $raw_trigger ) ) {
				continue;
			}

			$trigger   = wp_parse_args( $raw_trigger, $this->triggers->get_trigger_defaults() );
			$projected = $this->normalize_projected_event( $this->triggers->to_effective_event( $base, $trigger ) );
			$normalized_trigger = $this->triggers->from_legacy_event(
				$projected,
				$event_key,
				isset( $trigger['trigger_id'] ) && is_string( $trigger['trigger_id'] ) ? $trigger['trigger_id'] : ''
			);
			$normalized_trigger['provider'] = isset( $trigger['provider'] ) && is_scalar( $trigger['provider'] )
				? sanitize_key( (string) $trigger['provider'] )
				: '';
			$normalized_trigger['trigger_type'] = isset( $trigger['trigger_type'] ) && is_scalar( $trigger['trigger_type'] )
				? sanitize_key( (string) $trigger['trigger_type'] )
				: '';
			$normalized[] = array_merge( $raw_trigger, $normalized_trigger );
		}

		if ( '' === $legacy_trigger_id && ! empty( $normalized ) && isset( $normalized[0]['trigger_id'] ) ) {
			$legacy_trigger_id = $normalized[0]['trigger_id'];
		}

		return $this->triggers->apply_compatibility_shadow( $base, $normalized, $legacy_trigger_id );
	}

	public function get_effective_event( $event, $trigger ) {
		$event     = is_array( $event ) ? $event : array();
		$effective = $this->normalize_projected_event( $this->triggers->to_effective_event( $event, $trigger ) );
		$compat    = isset( $event['eventbridge_compat'] ) && is_array( $event['eventbridge_compat'] ) ? $event['eventbridge_compat'] : array();
		$effective['eventbridge_is_compatibility_trigger'] = isset( $compat['legacy_trigger_id'], $effective['trigger_id'] )
			&& $compat['legacy_trigger_id'] === $effective['trigger_id'];

		return $effective;
	}

	public function get_trigger( $event_key, $trigger_id = '' ) {
		$event = $this->get_event( $event_key );
		if ( ! is_array( $event ) ) {
			return false;
		}

		if ( '' === $trigger_id && isset( $event['eventbridge_compat']['legacy_trigger_id'] ) ) {
			$trigger_id = $event['eventbridge_compat']['legacy_trigger_id'];
		}

		foreach ( $event['triggers'] as $trigger ) {
			if ( is_array( $trigger )
				&& isset( $trigger['trigger_id'] )
				&& $trigger_id === $trigger['trigger_id']
			) {
				return $trigger;
			}
		}

		return false;
	}

	public function is_valid_trigger_id( $trigger_id ) {
		return $this->triggers->is_valid_trigger_id( $trigger_id );
	}

	public function get_trigger_family( $trigger ) {
		return $this->triggers->get_trigger_family( $trigger );
	}

	public function get_event_family( $event ) {
		return is_array( $event ) && isset( $event['triggers'] )
			? $this->triggers->get_event_family( $event['triggers'] )
			: '';
	}

	public function get_parameter_map( $event, $query_parameter_values = array(), $fluent_parameter_values = array(), $woocommerce_order_values = array() ) {
		$parameter_map = array();
		$parameters    = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();
		$query_parameter_values = is_array( $query_parameter_values ) ? $query_parameter_values : array();
		$fluent_parameter_values = is_array( $fluent_parameter_values ) ? $fluent_parameter_values : array();
		$woocommerce_order_values = is_array( $woocommerce_order_values ) ? $woocommerce_order_values : array();
		$reserved_query_parameters = $this->get_active_fluent_lookup_parameters();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'static' === $parameter['source'] ) {
				$parameter_map[ $parameter['name'] ] = $parameter['value'];
				continue;
			}

			if ( 'fluent_booking' === $parameter['source'] ) {
				if ( isset( $fluent_parameter_values[ $parameter['name'] ] ) ) {
					$value = $this->get_runtime_parameter_value( $fluent_parameter_values[ $parameter['name'] ] );
					if ( '' !== $value ) {
						$parameter_map[ $parameter['name'] ] = $value;
					}
				}
				continue;
			}

			if ( 'woocommerce_order' === $parameter['source'] ) {
				if ( array_key_exists( $parameter['value'], $woocommerce_order_values )
					&& ( is_scalar( $woocommerce_order_values[ $parameter['value'] ] ) || null === $woocommerce_order_values[ $parameter['value'] ] )
					&& '' !== $woocommerce_order_values[ $parameter['value'] ]
					&& null !== $woocommerce_order_values[ $parameter['value'] ]
				) {
					$parameter_map[ $parameter['name'] ] = $woocommerce_order_values[ $parameter['value'] ];
				}
				continue;
			}

			if ( in_array( $parameter['value'], $reserved_query_parameters, true )
				|| ! isset( $query_parameter_values[ $parameter['name'] ] )
			) {
				continue;
			}

			$value = $this->get_runtime_parameter_value( $query_parameter_values[ $parameter['name'] ] );
			if ( '' !== $value ) {
				$parameter_map[ $parameter['name'] ] = $value;
			}
		}

		return $parameter_map;
	}

	public function get_query_parameter_values( $event, $query ) {
		$values     = array();
		$parameters = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();
		$reserved   = $this->get_active_fluent_lookup_parameters();
		$query      = $this->get_tracking_query( $query );

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' !== $parameter['source'] || in_array( $parameter['value'], $reserved, true ) ) {
				continue;
			}

			$value = $this->get_query_parameter_value( $query, $parameter['value'] );
			if ( '' !== $value ) {
				$values[ $parameter['name'] ] = $value;
			}
		}

		return $values;
	}

	public function get_advanced_matching_values( $event, $query, $source = '', $fluent_values = array() ) {
		$values = array();
		$source = is_string( $source ) ? $source : '';
		$reserved = $this->get_active_fluent_lookup_parameters();
		$query = $this->get_tracking_query( $query );

		$fluent_values = is_array( $fluent_values ) ? $fluent_values : array();

		if ( '' !== $source && ! in_array( $source, array( 'static', 'query_parameter', 'fluent_booking' ), true ) ) {
			return $values;
		}

		foreach ( $this->get_advanced_matching_map( $event ) as $field => $configuration ) {
			if ( '' !== $source && $source !== $configuration['source'] ) {
				continue;
			}
			if ( 'query_parameter' === $configuration['source'] && in_array( $configuration['value'], $reserved, true ) ) {
				continue;
			}

			if ( 'static' === $configuration['source'] ) {
				$value = $this->get_runtime_parameter_value( $configuration['value'] );
			} elseif ( 'query_parameter' === $configuration['source'] ) {
				$value = $this->get_query_parameter_value( $query, $configuration['value'] );
			} elseif ( 'fluent_booking' === $configuration['source'] && isset( $fluent_values[ $field ] ) ) {
				$value = $this->get_runtime_parameter_value( $fluent_values[ $field ] );
			} else {
				$value = '';
			}

			if ( '' !== $value ) {
				$values[ $field ] = $value;
			}
		}

		return $values;
	}

	public function get_advanced_matching_user_data( $values ) {
		return $this->get_advanced_matching_user_data_from_normalized_values(
			$this->get_normalized_advanced_matching_values( $values )
		);
	}

	public function get_normalized_advanced_matching_values( $values ) {
		$normalized_values = array();
		$meta_keys         = array( 'email', 'phone', 'first_name', 'last_name' );
		$values            = is_array( $values ) ? $values : array();

		foreach ( $meta_keys as $value_key ) {
			if ( ! isset( $values[ $value_key ] ) || ! is_string( $values[ $value_key ] ) || '' === $values[ $value_key ] ) {
				continue;
			}

			$value = $values[ $value_key ];

			if ( 'email' === $value_key ) {
				$value = strtolower( sanitize_email( $value ) );
				if ( '' === $value || false === is_email( $value ) ) {
					continue;
				}
			} elseif ( 'phone' === $value_key ) {
				$value = preg_replace( '/\D+/', '', $value );
				if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{6,14}$/D', $value ) ) {
					continue;
				}
			} else {
				$value = sanitize_text_field( $value );
				$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
				if ( '' === $value ) {
					continue;
				}
			}

			$normalized_values[ $value_key ] = $value;
		}

		return $normalized_values;
	}

	public function get_advanced_matching_user_data_from_normalized_values( $values ) {
		$user_data = array();
		$meta_keys = array( 'email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln' );
		$values    = is_array( $values ) ? $values : array();

		foreach ( $meta_keys as $value_key => $meta_key ) {
			if ( isset( $values[ $value_key ] ) && is_string( $values[ $value_key ] ) && '' !== $values[ $value_key ] ) {
				$user_data[ $meta_key ] = hash( 'sha256', $values[ $value_key ] );
			}
		}

		return $user_data;
	}

	public function has_query_parameter_sources( $event ) {
		$parameters = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();
		$reserved   = $this->get_active_fluent_lookup_parameters();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' === $parameter['source'] && ! in_array( $parameter['value'], $reserved, true ) ) {
				return true;
			}
		}

		return false;
	}

	public function create_parameter_context( $event_key, $event, $query_parameter_values, $event_source_url = '' ) {
		if ( ! $this->is_valid_event_key( $event_key ) || ! $this->has_query_parameter_sources( $event ) ) {
			return '';
		}
		$event_source_url = class_exists( 'EventBridge_Meta_URL' ) ? EventBridge_Meta_URL::canonicalize( $event_source_url ) : '';

		$payload = wp_json_encode(
			array( 'values' => $this->filter_query_parameter_values( $event, $query_parameter_values ) )
		);

		if ( ! is_string( $payload ) ) {
			return '';
		}

		$encoded_payload = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$trigger_id      = $this->get_route_trigger_id( $event );
		if ( '' === $event_source_url && ! $this->triggers->is_valid_trigger_id( $trigger_id ) ) {
			$legacy_signature = hash_hmac( 'sha256', $event_key . '|v2|' . $encoded_payload, wp_salt( 'auth' ) );
			$legacy_context   = 'v2.' . $encoded_payload . '.' . $legacy_signature;

			return strlen( $legacy_context ) <= self::PARAMETER_CONTEXT_MAX_LENGTH ? $legacy_context : '';
		}
		if ( ! $this->triggers->is_valid_trigger_id( $trigger_id ) ) {
			return '';
		}
		if ( '' === $event_source_url ) {
			return '';
		}
		$fingerprint = $this->get_parameter_configuration_fingerprint( $event );
		$signature   = hash_hmac( 'sha256', $event_key . '|v3|' . $trigger_id . '|' . $fingerprint . '|' . $event_source_url . '|' . $encoded_payload, wp_salt( 'auth' ) );
		$context     = 'v3.' . $encoded_payload . '.' . $signature;

		return strlen( $context ) <= self::PARAMETER_CONTEXT_MAX_LENGTH ? $context : '';
	}

	public function verify_parameter_context( $event_key, $event, $context, $event_source_url = '' ) {
		if ( ! $this->is_valid_event_key( $event_key )
			|| ! is_string( $context )
			|| '' === $context
			|| strlen( $context ) > self::PARAMETER_CONTEXT_MAX_LENGTH
		) {
			return false;
		}

		$parts = explode( '.', $context );
		if ( 3 === count( $parts ) && 'v3' === $parts[0] ) {
			$encoded_payload   = $parts[1];
			$signature         = $parts[2];
			$trigger_id        = $this->get_route_trigger_id( $event );
			if ( ! $this->triggers->is_valid_trigger_id( $trigger_id ) ) {
				return false;
			}
			$event_source_url = class_exists( 'EventBridge_Meta_URL' ) ? EventBridge_Meta_URL::canonicalize( $event_source_url ) : '';
			if ( '' === $event_source_url ) {
				return false;
			}
			$signature_payload = $event_key . '|v3|' . $trigger_id . '|' . $this->get_parameter_configuration_fingerprint( $event ) . '|' . $event_source_url . '|' . $encoded_payload;
		} elseif ( ( $this->is_compatibility_route( $event ) || ! $this->triggers->is_valid_trigger_id( $this->get_route_trigger_id( $event ) ) ) && 3 === count( $parts ) && 'v2' === $parts[0] ) {
			$encoded_payload   = $parts[1];
			$signature         = $parts[2];
			$signature_payload = $event_key . '|v2|' . $encoded_payload;
		} elseif ( ( $this->is_compatibility_route( $event ) || ! $this->triggers->is_valid_trigger_id( $this->get_route_trigger_id( $event ) ) ) && 2 === count( $parts ) && empty( $this->get_active_fluent_lookup_parameters() ) ) {
			$encoded_payload   = $parts[0];
			$signature         = $parts[1];
			$signature_payload = $event_key . '|' . $encoded_payload;
		} else {
			return false;
		}

		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/D', $encoded_payload ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return false;
		}

		$expected_signature = hash_hmac( 'sha256', $signature_payload, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return false;
		}

		$base64_payload = strtr( $encoded_payload, '-_', '+/' );
		$padding_length = ( 4 - strlen( $base64_payload ) % 4 ) % 4;
		$payload        = base64_decode( $base64_payload . str_repeat( '=', $padding_length ), true );
		$decoded         = is_string( $payload ) ? json_decode( $payload, true ) : null;

		if ( ! is_array( $decoded ) || ! isset( $decoded['values'] ) || ! is_array( $decoded['values'] ) ) {
			return false;
		}

		return $this->filter_query_parameter_values( $event, $decoded['values'] );
	}

	public function get_advanced_matching_map( $event ) {
		$mapping = is_array( $event ) && isset( $event['advanced_matching'] ) ? $event['advanced_matching'] : array();

		return $this->normalize_advanced_matching( $mapping );
	}

	public function has_advanced_matching( $event ) {
		foreach ( $this->get_advanced_matching_map( $event ) as $configuration ) {
			if ( 'fluent_booking' === $configuration['source'] || ( in_array( $configuration['source'], array( 'static', 'query_parameter' ), true ) && '' !== $configuration['value'] ) ) {
				return true;
			}
		}

		return false;
	}

	public function has_advanced_matching_source( $event, $source ) {
		if ( ! in_array( $source, array( 'static', 'query_parameter', 'fluent_booking' ), true ) ) {
			return false;
		}

		$reserved = 'query_parameter' === $source ? $this->get_active_fluent_lookup_parameters() : array();

		foreach ( $this->get_advanced_matching_map( $event ) as $configuration ) {
			if ( $source === $configuration['source'] && ( 'fluent_booking' === $source || '' !== $configuration['value'] ) ) {
				if ( 'query_parameter' === $source && in_array( $configuration['value'], $reserved, true ) ) {
					continue;
				}
				return true;
			}
		}

		return false;
	}

	public function create_advanced_matching_context( $event_key, $event, $event_source_url, $user_data ) {
		if ( ! $this->is_valid_event_key( $event_key )
			|| ! is_string( $event_source_url )
			|| '' === $event_source_url
			|| ! $this->has_advanced_matching_source( $event, 'query_parameter' )
		) {
			return '';
		}

		$user_data = $this->filter_advanced_matching_user_data( $event, $user_data, 'query_parameter' );
		$issued_at = time();
		$payload   = wp_json_encode(
			array(
				'version'    => 1,
				'issued_at'  => $issued_at,
				'expires_at' => $issued_at + self::ADVANCED_MATCHING_CONTEXT_TTL,
				'user_data'  => $user_data,
			)
		);
		$key       = $this->get_advanced_matching_context_key();

		if ( ! is_string( $payload ) || '' === $key || ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
			return '';
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$payload,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$this->get_advanced_matching_context_aad( $event_key, $event, $event_source_url, false ),
			16
		);

		if ( ! is_string( $ciphertext ) || '' === $ciphertext || 16 !== strlen( $tag ) ) {
			return '';
		}

		$context = 'v2.' . $this->base64url_encode( $iv ) . '.' . $this->base64url_encode( $tag ) . '.' . $this->base64url_encode( $ciphertext );

		return strlen( $context ) <= self::ADVANCED_MATCHING_CONTEXT_MAX_LENGTH ? $context : '';
	}

	public function verify_advanced_matching_context( $event_key, $event, $event_source_url, $context ) {
		if ( ! $this->is_valid_event_key( $event_key )
			|| ! is_string( $event_source_url )
			|| '' === $event_source_url
			|| ! is_string( $context )
			|| '' === $context
			|| strlen( $context ) > self::ADVANCED_MATCHING_CONTEXT_MAX_LENGTH
			|| ! $this->has_advanced_matching_source( $event, 'query_parameter' )
			|| ! function_exists( 'openssl_decrypt' )
		) {
			return false;
		}

		$parts = explode( '.', $context );
		if ( 4 !== count( $parts )
			|| ! in_array( $parts[0], array( 'v1', 'v2' ), true )
			|| ( 'v1' === $parts[0] && ! $this->is_compatibility_route( $event ) )
		) {
			return false;
		}
		$legacy_context = 'v1' === $parts[0];

		$iv         = $this->base64url_decode( $parts[1] );
		$tag        = $this->base64url_decode( $parts[2] );
		$ciphertext = $this->base64url_decode( $parts[3] );
		$key        = $this->get_advanced_matching_context_key();

		if ( ! is_string( $iv ) || 12 !== strlen( $iv )
			|| ! is_string( $tag ) || 16 !== strlen( $tag )
			|| ! is_string( $ciphertext ) || '' === $ciphertext
			|| '' === $key
		) {
			return false;
		}

		$payload = openssl_decrypt(
			$ciphertext,
			'aes-256-gcm',
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			$this->get_advanced_matching_context_aad( $event_key, $event, $event_source_url, $legacy_context )
		);
		$decoded = is_string( $payload ) ? json_decode( $payload, true ) : null;

		if ( ! is_array( $decoded )
			|| ! isset( $decoded['version'], $decoded['issued_at'], $decoded['expires_at'], $decoded['user_data'] )
			|| 1 !== $decoded['version']
			|| ! is_int( $decoded['issued_at'] )
			|| ! is_int( $decoded['expires_at'] )
			|| ! is_array( $decoded['user_data'] )
		) {
			return false;
		}

		$now = time();
		if ( $decoded['issued_at'] > $now + self::ADVANCED_MATCHING_CONTEXT_CLOCK_SKEW
			|| $decoded['expires_at'] < $now
			|| $decoded['expires_at'] <= $decoded['issued_at']
			|| $decoded['expires_at'] - $decoded['issued_at'] > self::ADVANCED_MATCHING_CONTEXT_TTL
			|| ! $this->is_valid_advanced_matching_user_data( $event, $decoded['user_data'], 'query_parameter' )
		) {
			return false;
		}

		return $decoded['user_data'];
	}

	public function create_advanced_matching_signature( $event_key, $event_id, $event = array() ) {
		$trigger_id = $this->get_route_trigger_id( $event );

		return hash_hmac( 'sha256', $event_key . '|v2|' . $trigger_id . '|' . $event_id, wp_salt( 'auth' ) );
	}

	public function verify_advanced_matching_signature( $event_key, $event_id, $signature, $event = array() ) {
		if ( ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return false;
		}

		if ( hash_equals( $this->create_advanced_matching_signature( $event_key, $event_id, $event ), $signature ) ) {
			return true;
		}

		return $this->is_compatibility_route( $event )
			&& hash_equals( hash_hmac( 'sha256', $event_key . '|' . $event_id, wp_salt( 'auth' ) ), $signature );
	}

	public function is_valid_event_key( $event_key ) {
		return is_string( $event_key ) && (bool) preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key );
	}

	public function get_event( $event_key ) {
		if ( ! $this->is_valid_event_key( $event_key ) ) {
			return false;
		}

		$events = $this->get_events();

		return isset( $events[ $event_key ] ) && is_array( $events[ $event_key ] ) ? $this->normalize_event( $events[ $event_key ], $event_key ) : false;
	}

	public function validate_event( $input, $existing_event = null, $fluent_available = true, $event_key = '' ) {
		$input          = is_array( $input ) ? $input : array();
		$existing_event = is_array( $existing_event ) ? $this->normalize_event( $existing_event, $event_key ) : null;

		if ( ! isset( $input['triggers'] ) ) {
			$validation = $this->validate_projected_event(
				$input,
				is_array( $existing_event ) && ! empty( $existing_event['triggers'] )
					? $this->get_effective_event( $existing_event, $existing_event['triggers'][0] )
					: null,
				$fluent_available,
				$event_key
			);
			$trigger_id = is_array( $existing_event ) && ! empty( $existing_event['triggers'][0]['trigger_id'] )
				? $existing_event['triggers'][0]['trigger_id']
				: $this->triggers->get_legacy_trigger_id( $event_key );
			if ( '' === $trigger_id ) {
				$trigger_id = $this->triggers->create_trigger_id();
			}
			$trigger = $this->triggers->from_legacy_event( $validation['event'], $event_key, $trigger_id );
			$family  = $this->triggers->get_trigger_family( $trigger );
			$validation['event']['channels'] = $this->triggers->normalize_channels(
				array(
					'browser' => ! empty( $validation['event']['browser'] ),
					'capi'    => ! empty( $validation['event']['capi'] ),
				),
				$family
			);
			$validation['event'] = $this->triggers->apply_compatibility_shadow(
				$validation['event'],
				array( $trigger ),
				$trigger_id
			);

			return $validation;
		}

		$errors       = array();
		$raw_triggers = is_array( $input['triggers'] ) ? $input['triggers'] : array();
		if ( ! is_array( $input['triggers'] ) ) {
			$errors[] = __( 'De triggerlijst is ongeldig.', 'eventbridge' );
		}
		if ( empty( $raw_triggers ) ) {
			$errors[] = __( 'Voeg minstens één trigger toe.', 'eventbridge' );
		}
		if ( count( $raw_triggers ) > EventBridge_Triggers::MAX_TRIGGERS ) {
			$errors[] = sprintf( __( 'Een event mag maximaal %d triggers bevatten.', 'eventbridge' ), EventBridge_Triggers::MAX_TRIGGERS );
		}

		$existing_by_id = array();
		if ( is_array( $existing_event ) ) {
			foreach ( $existing_event['triggers'] as $existing_trigger ) {
				if ( is_array( $existing_trigger ) && ! empty( $existing_trigger['trigger_id'] ) ) {
					$existing_by_id[ $existing_trigger['trigger_id'] ] = $existing_trigger;
				}
			}
		}

		$base_input = $input;
		unset( $base_input['triggers'] );
		$submitted_channels = isset( $input['channels'] ) && is_array( $input['channels'] )
			? $input['channels']
			: array(
				'browser' => isset( $input['browser'] ),
				'capi'    => isset( $input['capi'] ),
			);
		$base_input['channels'] = $this->triggers->normalize_channels( $submitted_channels );
		$validated_triggers = array();
		$seen_ids           = array();
		$families           = array();
		$first_event        = null;
		$has_capi           = false;

		foreach ( array_slice( $raw_triggers, 0, EventBridge_Triggers::MAX_TRIGGERS, true ) as $index => $raw_trigger ) {
			$number = is_numeric( $index ) ? absint( $index ) + 1 : count( $validated_triggers ) + 1;
			if ( ! is_array( $raw_trigger ) ) {
				$errors[] = sprintf( __( 'Trigger %d is ongeldig.', 'eventbridge' ), $number );
				continue;
			}

			$trigger_id = isset( $raw_trigger['trigger_id'] ) && is_scalar( $raw_trigger['trigger_id'] )
				? trim( wp_unslash( (string) $raw_trigger['trigger_id'] ) )
				: '';
			if ( '' === $trigger_id ) {
				$trigger_id = $this->triggers->create_trigger_id();
			} elseif ( ! $this->triggers->is_valid_trigger_id( $trigger_id ) ) {
				$errors[] = sprintf( __( 'Trigger %d heeft een ongeldige trigger-ID.', 'eventbridge' ), $number );
			}
			if ( isset( $seen_ids[ $trigger_id ] ) ) {
				$errors[] = sprintf( __( 'Trigger-ID in trigger %d komt meer dan één keer voor.', 'eventbridge' ), $number );
			}
			$seen_ids[ $trigger_id ] = true;

			$provider = isset( $raw_trigger['provider'] ) && is_scalar( $raw_trigger['provider'] )
				? sanitize_key( wp_unslash( (string) $raw_trigger['provider'] ) )
				: '';
			$type = isset( $raw_trigger['trigger_type'] ) && is_scalar( $raw_trigger['trigger_type'] )
				? sanitize_key( wp_unslash( (string) $raw_trigger['trigger_type'] ) )
				: '';
			if ( ! ( 'frontend' === $provider && in_array( $type, array( 'click', 'pageview' ), true ) )
				&& ! ( 'woocommerce' === $provider && 'order_lifecycle' === $type )
			) {
				$errors[] = sprintf( __( 'Provider of triggertype in trigger %d is ongeldig.', 'eventbridge' ), $number );
			}
			$family = $this->triggers->get_trigger_family( array( 'provider' => $provider, 'trigger_type' => $type ) );
			if ( '' !== $family ) {
				$families[ $family ] = true;
			}

			$raw_trigger['trigger_id']   = $trigger_id;
			$raw_trigger['provider']     = $provider;
			$raw_trigger['trigger_type'] = $type;
			$route_input = $this->triggers->to_effective_event( $base_input, $raw_trigger );
			foreach ( array( 'browser', 'capi' ) as $channel ) {
				if ( empty( $route_input[ $channel ] ) ) {
					unset( $route_input[ $channel ] );
				} else {
					$route_input[ $channel ] = '1';
				}
			}
			if ( empty( $route_input['capi'] ) ) {
				unset( $route_input['meta_test_mode'], $route_input['meta_test_event_code'] );
			}

			$existing_route = isset( $existing_by_id[ $trigger_id ] )
				? $this->get_effective_event( $existing_event, $existing_by_id[ $trigger_id ] )
				: null;
			$route_validation = $this->validate_projected_event(
				$route_input,
				$existing_route,
				$fluent_available,
				$event_key
			);
			foreach ( $route_validation['errors'] as $route_error ) {
				$errors[] = sprintf( __( 'Trigger %d: %s', 'eventbridge' ), $number, $route_error );
			}
			if ( null === $first_event ) {
				$first_event = $route_validation['event'];
			}

			$normalized_trigger = $this->triggers->from_legacy_event(
				$route_validation['event'],
				$event_key,
				$trigger_id
			);
			$normalized_trigger['provider']     = $provider;
			$normalized_trigger['trigger_type'] = $type;
			if ( isset( $existing_by_id[ $trigger_id ] ) ) {
				$normalized_trigger = array_merge( $existing_by_id[ $trigger_id ], $normalized_trigger );
			}
			unset( $normalized_trigger['channels'] );
			$validated_triggers[] = $normalized_trigger;
			$has_capi = $has_capi || ! empty( $route_validation['event']['capi'] );
		}

		if ( null === $first_event ) {
			$fallback = $this->triggers->to_effective_event( $base_input, $this->triggers->get_trigger_defaults() );
			$first_event = $this->validate_projected_event( $fallback, null, $fluent_available, $event_key )['event'];
		}

		$event_family = 1 === count( $families ) ? (string) key( $families ) : '';
		if ( count( $families ) > 1 ) {
			$errors[] = __( 'Alle triggers binnen één event moeten tot dezelfde triggerfamilie behoren. Splits frontend- en WooCommerce-triggers over afzonderlijke events.', 'eventbridge' );
		} elseif ( '' === $event_family && ! empty( $validated_triggers ) ) {
			$errors[] = __( 'De triggerfamilie kon niet veilig worden bepaald.', 'eventbridge' );
		}
		$event_channels = $this->triggers->normalize_channels( $submitted_channels, $event_family );
		if ( EventBridge_Triggers::FAMILY_FRONTEND === $event_family && ! $event_channels['browser'] && ! $event_channels['capi'] ) {
			$errors[] = __( 'Schakel minstens één verzendkanaal in voor frontendtriggers.', 'eventbridge' );
		}
		if ( EventBridge_Triggers::FAMILY_SERVER === $event_family
			&& ( ! empty( $submitted_channels['browser'] ) || empty( $submitted_channels['capi'] ) )
		) {
			$errors[] = __( 'Backendtriggers vereisen uitsluitend Meta Conversion API; browser is niet toegestaan.', 'eventbridge' );
		}
		$first_event['channels'] = $event_channels;

		$meta_test_mode = isset( $input['meta_test_mode'] ) && is_scalar( $input['meta_test_mode'] ) && '1' === (string) $input['meta_test_mode'];
		$meta_test_code = isset( $input['meta_test_event_code'] ) && is_scalar( $input['meta_test_event_code'] )
			? sanitize_text_field( trim( wp_unslash( (string) $input['meta_test_event_code'] ) ) )
			: '';
		if ( $meta_test_mode && ! $has_capi ) {
			$errors[] = __( 'Meta CAPI-testmodus vereist minstens één trigger met Conversion API.', 'eventbridge' );
		}
		$first_event['meta_test_mode']       = $meta_test_mode && $has_capi;
		$first_event['meta_test_event_code'] = $meta_test_mode && $has_capi ? $meta_test_code : '';

		$compatibility = is_array( $existing_event ) && isset( $existing_event['eventbridge_compat'] )
			? $existing_event['eventbridge_compat']
			: array();
		$legacy_trigger_id = isset( $compatibility['legacy_trigger_id'] ) && is_string( $compatibility['legacy_trigger_id'] )
			? $compatibility['legacy_trigger_id']
			: '';
		if ( '' === $legacy_trigger_id && ! empty( $validated_triggers ) ) {
			$legacy_trigger_id = $validated_triggers[0]['trigger_id'];
		}

		$event = $this->triggers->apply_compatibility_shadow(
			$first_event,
			$validated_triggers,
			$legacy_trigger_id
		);
		if ( count( $families ) > 1 ) {
			$event['enabled'] = false;
			$event[ EventBridge_Triggers::FAMILY_CONFLICT_KEY ] = array( 'families' => array_keys( $families ) );
		} else {
			unset( $event[ EventBridge_Triggers::FAMILY_CONFLICT_KEY ] );
		}
		$errors = array_merge( $errors, $this->validate_trigger_query_conflicts( $validated_triggers ) );

		return array(
			'event'  => $event,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	private function validate_projected_event( $input, $existing_event = null, $fluent_available = true, $event_key = '' ) {
		$input                = is_array( $input ) ? $input : array();
		$existing_event       = is_array( $existing_event ) ? $this->normalize_projected_event( $existing_event ) : null;
		$fluent_available     = true === $fluent_available;
		$event_key            = $this->is_valid_event_key( $event_key ) ? $event_key : '';

		if ( ! $fluent_available && is_array( $existing_event ) ) {
			$input = $this->complete_missing_existing_fluent_input( $input, $existing_event );
		}

		$parameter_validation = $this->validate_parameters( isset( $input['parameters'] ) ? $input['parameters'] : array() );
		$advanced_matching_validation = $this->validate_advanced_matching( isset( $input['advanced_matching'] ) ? $input['advanced_matching'] : array() );
		$data_source_validation = $this->validate_data_source( isset( $input['data_source'] ) ? $input['data_source'] : array() );
		$meta_test_mode_is_valid = ! isset( $input['meta_test_mode'] ) || ( is_scalar( $input['meta_test_mode'] ) && '1' === (string) $input['meta_test_mode'] );
		$meta_test_mode       = isset( $input['meta_test_mode'] ) && is_scalar( $input['meta_test_mode'] ) && '1' === (string) $input['meta_test_mode'];
		$meta_test_code_is_scalar = ! isset( $input['meta_test_event_code'] ) || is_scalar( $input['meta_test_event_code'] );
		$unslashed_meta_test_event_code = isset( $input['meta_test_event_code'] ) && is_scalar( $input['meta_test_event_code'] ) ? wp_unslash( (string) $input['meta_test_event_code'] ) : '';
		$raw_meta_test_event_code = trim( $unslashed_meta_test_event_code );
		$meta_test_event_code = sanitize_text_field( $raw_meta_test_event_code );
		$event                = array(
			'label'       => $this->sanitize_text_value( $input, 'label', false ),
			'description' => $this->sanitize_text_value( $input, 'description', true ),
			'event_name'  => $this->sanitize_text_value( $input, 'event_name', false ),
			'browser'     => isset( $input['browser'] ),
			'capi'        => isset( $input['capi'] ),
			'meta_test_mode'       => $meta_test_mode,
			'meta_test_event_code' => $meta_test_mode ? $meta_test_event_code : '',
			'enabled'     => isset( $input['enabled'] ),
			'trigger_type' => isset( $input['trigger_type'] ) && is_scalar( $input['trigger_type'] ) ? trim( wp_unslash( (string) $input['trigger_type'] ) ) : '',
			'selector'     => $this->sanitize_text_value( $input, 'selector', false ),
			'url_match_type'  => isset( $input['url_match_type'] ) && is_scalar( $input['url_match_type'] ) ? trim( wp_unslash( (string) $input['url_match_type'] ) ) : '',
			'url_match_value' => $this->sanitize_text_value( $input, 'url_match_value', false ),
			'parameters'   => $parameter_validation['parameters'],
			'conditions'   => array(),
			'data_source'  => $data_source_validation['data_source'],
			'advanced_matching' => $advanced_matching_validation['mapping'],
			'woocommerce' => $this->woocommerce
				? $this->woocommerce->normalize_configuration( isset( $input['woocommerce'] ) ? $input['woocommerce'] : array(), false )
				: array( 'event' => '', 'status' => '', 'purchase_preset' => false ),
			'remove_query_parameters' => isset( $input['remove_query_parameters'] ),
		);
		$errors = array_merge( $parameter_validation['errors'], $advanced_matching_validation['errors'], $data_source_validation['errors'] );

		if ( $this->conditions ) {
			$existing_conditions = is_array( $existing_event ) && isset( $existing_event['conditions'] ) ? $existing_event['conditions'] : array();
			$condition_validation = $this->conditions->validate_conditions(
				isset( $input['conditions'] ) ? $input['conditions'] : array(),
				$event,
				$existing_conditions
			);
			$event['conditions'] = $condition_validation['conditions'];
			$errors              = array_merge( $errors, $condition_validation['errors'] );

			if ( ! empty( $event['conditions'] ) ) {
				if ( 'woocommerce' !== $event['trigger_type'] ) {
					$errors[] = __( 'Voorwaarden zijn in EventBridge 1.2.0 alleen beschikbaar voor een WooCommerce-trigger.', 'eventbridge' );
				}
				foreach ( $event['conditions'] as $condition ) {
					if ( ! is_array( $condition ) || ! isset( $condition['provider'] ) || 'woocommerce' !== $condition['provider'] ) {
						$errors[] = __( 'Alle voorwaarden moeten in EventBridge 1.2.0 de WooCommerce-provider gebruiken.', 'eventbridge' );
						break;
					}
				}
			}
		} elseif ( isset( $input['conditions'] ) && ! empty( $input['conditions'] ) ) {
			$errors[] = __( 'De voorwaardenprovider is niet beschikbaar.', 'eventbridge' );
		}

		if ( ! $fluent_available ) {
			$fluent_protection = $this->protect_unavailable_fluent_configuration( $input, $event, $existing_event );
			$event             = $fluent_protection['event'];
			$errors            = array_merge( $errors, $fluent_protection['errors'] );
		}

		if ( ! $meta_test_mode_is_valid ) {
			$errors[] = __( 'De waarde voor Meta CAPI-testmodus is ongeldig.', 'eventbridge' );
		}

		if ( ! $meta_test_code_is_scalar ) {
			$errors[] = __( 'De Meta Test Event Code is ongeldig.', 'eventbridge' );
		} elseif ( $meta_test_mode ) {
			if ( ! $event['capi'] ) {
				$errors[] = __( 'Meta CAPI-testmodus vereist dat Conversion API is ingeschakeld.', 'eventbridge' );
			}

			if ( preg_match( '/[\r\n]/', $unslashed_meta_test_event_code ) ) {
				$errors[] = __( 'Meta Test Event Code mag geen regeleinden bevatten.', 'eventbridge' );
			} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $unslashed_meta_test_event_code ) ) {
				$errors[] = __( 'Meta Test Event Code mag geen control characters bevatten.', 'eventbridge' );
			} elseif ( $raw_meta_test_event_code !== wp_strip_all_tags( $raw_meta_test_event_code ) ) {
				$errors[] = __( 'Meta Test Event Code mag geen HTML bevatten.', 'eventbridge' );
			} elseif ( '' === $meta_test_event_code ) {
				$errors[] = __( 'Meta Test Event Code is verplicht wanneer testmodus actief is.', 'eventbridge' );
			} elseif ( $this->get_length( $meta_test_event_code ) > self::META_TEST_EVENT_CODE_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Meta Test Event Code mag maximaal %d tekens bevatten.', 'eventbridge' ), self::META_TEST_EVENT_CODE_MAX_LENGTH );
			} elseif ( ! preg_match( '/^TEST[0-9]+$/D', $meta_test_event_code ) ) {
				$errors[] = __( 'Meta Test Event Code moet bestaan uit TEST gevolgd door cijfers.', 'eventbridge' );
			}
		}

		$has_fluent_source = false;

		foreach ( $event['parameters'] as $parameter ) {
			$has_fluent_source = $has_fluent_source || 'fluent_booking' === $parameter['source'];
		}

		foreach ( $event['advanced_matching'] as $configuration ) {
			$has_fluent_source = $has_fluent_source || 'fluent_booking' === $configuration['source'];
		}

		if ( $has_fluent_source && ( 'fluent_booking' !== $event['data_source']['provider'] || 'query_parameter' !== $event['data_source']['lookup_source'] || '' === $event['data_source']['lookup_value'] ) ) {
			$errors[] = __( 'Fluent Booking-bronnen vereisen een volledige Fluent Booking-databronconfiguratie.', 'eventbridge' );
		}

		if ( ! $event['browser'] && ! $event['capi'] ) {
			$errors[] = __( 'Schakel minstens één verzendkanaal in: Meta Pixel in de browser of Meta Conversion API.', 'eventbridge' );
		}

		if ( $this->has_advanced_matching( $event ) && ! $event['capi'] ) {
			$errors[] = __( 'Meta Advanced Matching vereist dat Meta Conversion API is ingeschakeld.', 'eventbridge' );
		}

		$advanced_query_parameters = array();
		foreach ( $event['advanced_matching'] as $configuration ) {
			if ( 'query_parameter' === $configuration['source'] && '' !== $configuration['value'] ) {
				$advanced_query_parameters[] = $configuration['value'];
			}
		}

		foreach ( $event['parameters'] as $parameter ) {
			if ( 'query_parameter' === $parameter['source'] && in_array( $parameter['value'], $advanced_query_parameters, true ) ) {
				$errors[] = sprintf( __( 'Queryparameter "%s" kan niet tegelijk als gewone eventparameter en voor Advanced Matching worden gebruikt.', 'eventbridge' ), $parameter['value'] );
			}
		}

		if ( 'fluent_booking' === $event['data_source']['provider'] && '' !== $event['data_source']['lookup_value'] ) {
			if ( in_array( $event['data_source']['lookup_value'], $advanced_query_parameters, true ) ) {
				$errors[] = __( 'De Fluent Booking-lookupqueryparameter kan niet voor Advanced Matching worden gebruikt.', 'eventbridge' );
			}
			foreach ( $event['parameters'] as $parameter ) {
				if ( 'query_parameter' === $parameter['source'] && $event['data_source']['lookup_value'] === $parameter['value'] ) {
					$errors[] = __( 'De Fluent Booking-lookupqueryparameter kan niet als gewone eventparameter worden gebruikt.', 'eventbridge' );
					break;
				}
			}
		}

		$active_fluent_lookups = $this->get_active_fluent_lookup_parameters( $event_key );
		foreach ( $event['parameters'] as $parameter ) {
			if ( 'query_parameter' === $parameter['source'] && in_array( $parameter['value'], $active_fluent_lookups, true ) ) {
				$errors[] = sprintf( __( 'Queryparameter "%s" is gereserveerd voor een actieve Fluent Booking-lookup.', 'eventbridge' ), $parameter['value'] );
			}
		}
		foreach ( $event['advanced_matching'] as $configuration ) {
			if ( 'query_parameter' === $configuration['source'] && in_array( $configuration['value'], $active_fluent_lookups, true ) ) {
				$errors[] = sprintf( __( 'Advanced Matching-queryparameter "%s" is gereserveerd voor een actieve Fluent Booking-lookup.', 'eventbridge' ), $configuration['value'] );
			}
		}

		if ( 'fluent_booking' === $event['data_source']['provider'] && '' !== $event['data_source']['lookup_value'] ) {
			$active_query_parameters = $this->get_active_regular_query_parameters( $event_key );
			if ( in_array( $event['data_source']['lookup_value'], $active_query_parameters, true ) ) {
				$errors[] = __( 'De Fluent Booking-lookupqueryparameter wordt al door een ander actief event als gewone parameter of voor Advanced Matching gebruikt.', 'eventbridge' );
			}
		}

		if ( '' === $event['label'] ) {
			$errors[] = __( 'Interne naam is verplicht.', 'eventbridge' );
		} elseif ( $this->get_length( $event['label'] ) > self::LABEL_MAX_LENGTH ) {
			$errors[] = sprintf( __( 'Interne naam mag maximaal %d tekens bevatten.', 'eventbridge' ), self::LABEL_MAX_LENGTH );
		}

		if ( $this->get_length( $event['description'] ) > self::DESCRIPTION_MAX_LENGTH ) {
			$errors[] = sprintf( __( 'Beschrijving mag maximaal %d tekens bevatten.', 'eventbridge' ), self::DESCRIPTION_MAX_LENGTH );
		}

		if ( '' === $event['event_name'] ) {
			$errors[] = __( 'Meta-eventnaam is verplicht.', 'eventbridge' );
		} elseif ( $this->get_length( $event['event_name'] ) > self::EVENT_NAME_MAX_LENGTH ) {
			$errors[] = sprintf( __( 'Meta-eventnaam mag maximaal %d tekens bevatten.', 'eventbridge' ), self::EVENT_NAME_MAX_LENGTH );
		} elseif ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $event['event_name'] ) ) {
			$errors[] = __( 'Meta-eventnaam mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' );
		}

		if ( ! in_array( $event['trigger_type'], array( 'click', 'pageview', 'woocommerce' ), true ) ) {
			$errors[] = __( 'Triggertype is ongeldig.', 'eventbridge' );
		}

		$raw_selector = isset( $input['selector'] ) && is_scalar( $input['selector'] ) ? wp_unslash( (string) $input['selector'] ) : '';
		if ( 'click' === $event['trigger_type'] && '' === $event['selector'] ) {
			$errors[] = __( 'CSS-selector is verplicht.', 'eventbridge' );
		} elseif ( 'click' === $event['trigger_type'] && preg_match( '/[\r\n]/', $raw_selector ) ) {
			$errors[] = __( 'CSS-selector mag geen regeleinden bevatten.', 'eventbridge' );
		} elseif ( 'click' === $event['trigger_type'] && $raw_selector !== wp_strip_all_tags( $raw_selector ) ) {
			$errors[] = __( 'CSS-selector mag geen HTML-tags bevatten.', 'eventbridge' );
		} elseif ( 'click' === $event['trigger_type'] && $this->get_length( $event['selector'] ) > self::SELECTOR_MAX_LENGTH ) {
			$errors[] = sprintf( __( 'CSS-selector mag maximaal %d tekens bevatten.', 'eventbridge' ), self::SELECTOR_MAX_LENGTH );
		}

		if ( 'pageview' === $event['trigger_type'] ) {
			$raw_url_match_value = isset( $input['url_match_value'] ) && is_scalar( $input['url_match_value'] ) ? wp_unslash( (string) $input['url_match_value'] ) : '';

			if ( ! in_array( $event['url_match_type'], array( 'path_exact', 'path_contains', 'url_exact' ), true ) ) {
				$errors[] = __( 'URL-vergelijking is ongeldig.', 'eventbridge' );
			}

			if ( '' === $event['url_match_value'] ) {
				$errors[] = __( 'URL-waarde is verplicht.', 'eventbridge' );
			} elseif ( preg_match( '/[\r\n]/', $raw_url_match_value ) ) {
				$errors[] = __( 'URL-waarde mag geen regeleinden bevatten.', 'eventbridge' );
			} elseif ( $raw_url_match_value !== wp_strip_all_tags( $raw_url_match_value ) ) {
				$errors[] = __( 'URL-waarde mag geen HTML-tags bevatten.', 'eventbridge' );
			} elseif ( $this->get_length( $event['url_match_value'] ) > self::URL_MATCH_VALUE_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'URL-waarde mag maximaal %d tekens bevatten.', 'eventbridge' ), self::URL_MATCH_VALUE_MAX_LENGTH );
			} elseif ( 'url_exact' === $event['url_match_type'] && false === wp_http_validate_url( $event['url_match_value'] ) ) {
				$errors[] = __( 'Volledige URL moet een geldige absolute HTTP(S)-URL zijn.', 'eventbridge' );
			}
		}

		if ( $this->woocommerce ) {
			$woocommerce_validation = $this->woocommerce->validate_event_configuration( $event, $existing_event );
			$event                  = $woocommerce_validation['event'];
			$errors                 = array_merge( $errors, $woocommerce_validation['errors'] );
		}

		return array(
			'event'  => $event,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	public function add_event( $event ) {
		$events = $this->get_events();

		do {
			$event_key = 'evt_' . wp_generate_uuid4();
		} while ( isset( $events[ $event_key ] ) );

		$event = $this->normalize_event( $event, $event_key );
		if ( empty( $event['eventbridge_compat']['legacy_trigger_id'] ) && ! empty( $event['triggers'][0]['trigger_id'] ) ) {
			$event = $this->triggers->apply_compatibility_shadow(
				$event,
				$event['triggers'],
				$event['triggers'][0]['trigger_id']
			);
		}
		$events[ $event_key ] = $event;

		return update_option( self::OPTION_NAME, $events );
	}

	public function update_event( $event_key, $event ) {
		if ( ! $this->is_valid_event_key( $event_key ) ) {
			return 'invalid_key';
		}

		$events = $this->get_events();

		if ( ! isset( $events[ $event_key ] ) || ! is_array( $events[ $event_key ] ) ) {
			return 'not_found';
		}

		$updated_event = array_merge( $events[ $event_key ], $this->normalize_event( $event, $event_key ) );

		if ( $events[ $event_key ] === $updated_event ) {
			return 'updated';
		}

		$events[ $event_key ] = $updated_event;

		return update_option( self::OPTION_NAME, $events ) ? 'updated' : 'save_failed';
	}

	public function delete_event( $event_key ) {
		if ( ! $this->is_valid_event_key( $event_key ) ) {
			return 'invalid_key';
		}

		$events = $this->get_events();

		if ( ! array_key_exists( $event_key, $events ) ) {
			return 'not_found';
		}

		unset( $events[ $event_key ] );

		if ( ! update_option( self::OPTION_NAME, $events ) ) {
			return 'save_failed';
		}

		return 'deleted';
	}

	private function sanitize_text_value( $input, $key, $multiline ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}

		$value = trim( wp_unslash( (string) $input[ $key ] ) );

		return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}

	private function validate_parameters( $input ) {
		$parameters = array();
		$errors     = array();
		$names      = array();

		if ( ! is_array( $input ) ) {
			return array(
				'parameters' => $parameters,
				'errors'     => array( __( 'De parameterlijst is ongeldig.', 'eventbridge' ) ),
			);
		}

		foreach ( $input as $index => $row ) {
			$valid_row      = is_array( $row );
			$row            = $valid_row ? $row : array();
			$name_is_scalar   = isset( $row['name'] ) && is_scalar( $row['name'] );
			$source_is_scalar = isset( $row['source'] ) && is_scalar( $row['source'] );
			$value_is_scalar  = isset( $row['value'] ) && is_scalar( $row['value'] );
			$raw_name       = $name_is_scalar ? trim( wp_unslash( (string) $row['name'] ) ) : '';
			$raw_source     = $source_is_scalar ? trim( wp_unslash( (string) $row['source'] ) ) : '';
			$raw_value      = $value_is_scalar ? trim( wp_unslash( (string) $row['value'] ) ) : '';
			$name           = sanitize_text_field( $raw_name );
			$source         = sanitize_key( $raw_source );
			$value          = sanitize_text_field( $raw_value );
			$row_number     = is_numeric( $index ) ? (int) $index + 1 : count( $parameters ) + 1;

			if ( ! $valid_row || ! $name_is_scalar || ! $source_is_scalar || ! $value_is_scalar ) {
				$errors[] = sprintf( __( 'Parameterregel %d is ongeldig.', 'eventbridge' ), $row_number );
				$parameters[] = array(
					'name'   => $name,
					'source' => $source,
					'value'  => $value,
				);
				continue;
			}

			if ( '' === $raw_name && '' === $raw_value && 'static' === $source ) {
				continue;
			}

			$parameters[] = array(
				'name'   => $name,
				'source' => $source,
				'value'  => $value,
			);

			if ( ! in_array( $source, array( 'static', 'query_parameter', 'fluent_booking', 'woocommerce_order' ), true ) ) {
				$errors[] = sprintf( __( 'Bron in parameterregel %d is ongeldig.', 'eventbridge' ), $row_number );
			}

			if ( '' === $name ) {
				$errors[] = sprintf( __( 'Parameternaam in regel %d is verplicht.', 'eventbridge' ), $row_number );
			} elseif ( $this->get_length( $name ) > self::PARAMETER_NAME_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Parameternaam in regel %1$d mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $row_number, self::PARAMETER_NAME_MAX_LENGTH );
			} elseif ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $name ) ) {
				$errors[] = sprintf( __( 'Parameternaam in regel %d mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' ), $row_number );
			} elseif ( isset( $names[ $name ] ) ) {
				$errors[] = sprintf( __( 'Parameternaam "%s" komt meer dan één keer voor.', 'eventbridge' ), $name );
			} else {
				$names[ $name ] = true;
			}

			if ( '' === $value ) {
				$errors[] = sprintf( __( 'Waarde in parameterregel %d is verplicht.', 'eventbridge' ), $row_number );
			} elseif ( preg_match( '/[\r\n]/', $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde in parameterregel %d mag geen regeleinden bevatten.', 'eventbridge' ), $row_number );
			} elseif ( $raw_value !== wp_strip_all_tags( $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde in parameterregel %d mag geen HTML bevatten.', 'eventbridge' ), $row_number );
			} elseif ( 'query_parameter' === $source && $this->get_length( $value ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Queryparameternaam in regel %1$d mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $row_number, self::QUERY_PARAMETER_NAME_MAX_LENGTH );
			} elseif ( 'query_parameter' === $source && ! preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ) {
				$errors[] = sprintf( __( 'Queryparameternaam in regel %d mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' ), $row_number );
			} elseif ( 'fluent_booking' === $source && ! in_array( $value, $this->get_fluent_parameter_fields(), true ) ) {
				$errors[] = sprintf( __( 'Fluent Booking-veld in parameterregel %d is ongeldig.', 'eventbridge' ), $row_number );
			} elseif ( 'woocommerce_order' === $source
				&& ( ! $this->woocommerce || ! isset( $this->woocommerce->get_order_parameter_fields()[ $value ] ) )
			) {
				$errors[] = sprintf( __( 'WooCommerce-orderveld in parameterregel %d is ongeldig.', 'eventbridge' ), $row_number );
			} elseif ( 'static' === $source && $this->get_length( $value ) > self::PARAMETER_VALUE_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Waarde in parameterregel %1$d mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $row_number, self::PARAMETER_VALUE_MAX_LENGTH );
			}
		}

		return array(
			'parameters' => array_values( $parameters ),
			'errors'     => $errors,
		);
	}

	private function normalize_parameters( $parameters ) {
		$normalized = array();
		$names      = array();

		if ( ! is_array( $parameters ) ) {
			return $normalized;
		}

		foreach ( $parameters as $parameter ) {
			if ( ! is_array( $parameter )
				|| ! isset( $parameter['name'], $parameter['value'] )
				|| ! is_scalar( $parameter['name'] )
				|| ! is_scalar( $parameter['value'] )
			) {
				continue;
			}

			$name       = trim( (string) $parameter['name'] );
			$source     = ! isset( $parameter['source'] ) ? 'static' : ( is_scalar( $parameter['source'] ) ? trim( (string) $parameter['source'] ) : '' );
			$value      = trim( (string) $parameter['value'] );
			$safe_name  = sanitize_text_field( $name );
			$safe_source = sanitize_key( $source );
			$safe_value = sanitize_text_field( $value );

			if ( '' === $safe_name
				|| '' === $safe_value
				|| preg_match( '/[\r\n]/', $value )
				|| $value !== wp_strip_all_tags( $value )
				|| $this->get_length( $safe_name ) > self::PARAMETER_NAME_MAX_LENGTH
				|| ! in_array( $safe_source, array( 'static', 'query_parameter', 'fluent_booking', 'woocommerce_order' ), true )
				|| ( 'static' === $safe_source && $this->get_length( $safe_value ) > self::PARAMETER_VALUE_MAX_LENGTH )
				|| ( 'query_parameter' === $safe_source && $this->get_length( $safe_value ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH )
				|| ( 'query_parameter' === $safe_source && ! preg_match( '/^[A-Za-z0-9_]+$/D', $safe_value ) )
				|| ( 'fluent_booking' === $safe_source && ! in_array( $safe_value, $this->get_fluent_parameter_fields(), true ) )
				|| ( 'woocommerce_order' === $safe_source
					&& ( ! $this->woocommerce || ! isset( $this->woocommerce->get_order_parameter_fields()[ $safe_value ] ) )
				)
				|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $safe_name )
				|| isset( $names[ $safe_name ] )
			) {
				continue;
			}

			$names[ $safe_name ] = true;
			$normalized[] = array(
				'name'   => $safe_name,
				'source' => $safe_source,
				'value'  => $safe_value,
			);
		}

		return $normalized;
	}

	private function filter_query_parameter_values( $event, $values ) {
		$filtered   = array();
		$parameters = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();
		$values     = is_array( $values ) ? $values : array();
		$reserved   = $this->get_active_fluent_lookup_parameters();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' !== $parameter['source']
				|| in_array( $parameter['value'], $reserved, true )
				|| ! isset( $values[ $parameter['name'] ] )
			) {
				continue;
			}

			$value = $this->get_runtime_parameter_value( $values[ $parameter['name'] ] );
			if ( '' !== $value ) {
				$filtered[ $parameter['name'] ] = $value;
			}
		}

		return $filtered;
	}

	private function get_runtime_parameter_value( $value, $unslash = false ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$raw_value = trim( $unslash ? wp_unslash( (string) $value ) : (string) $value );
		if ( '' === $raw_value
			|| preg_match( '/[\x00-\x1F\x7F]/', $raw_value )
			|| $raw_value !== wp_strip_all_tags( $raw_value )
			|| $this->get_length( $raw_value ) > self::PARAMETER_VALUE_MAX_LENGTH
		) {
			return '';
		}

		return sanitize_text_field( $raw_value );
	}

	private function get_query_parameter_value( $query, $query_parameter ) {
		if ( ! is_array( $query ) || ! is_scalar( $query_parameter ) ) {
			return '';
		}

		$raw_query_parameter = trim( (string) $query_parameter );
		$query_parameter     = sanitize_text_field( $raw_query_parameter );

		if ( '' === $query_parameter
			|| $raw_query_parameter !== wp_strip_all_tags( $raw_query_parameter )
			|| preg_match( '/[\x00-\x1F\x7F]/', $raw_query_parameter )
			|| $this->get_length( $query_parameter ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH
			|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $query_parameter )
			|| ! isset( $query[ $query_parameter ] )
		) {
			return '';
		}

		return $this->get_runtime_parameter_value( $query[ $query_parameter ], true );
	}

	private function get_advanced_matching_context_key() {
		if ( ! function_exists( 'hash_hkdf' ) ) {
			return '';
		}

		$key = hash_hkdf( 'sha256', wp_salt( 'auth' ), 32, 'eventbridge-advanced-matching-context-v1' );

		return is_string( $key ) && 32 === strlen( $key ) ? $key : '';
	}

	private function get_route_trigger_id( $event ) {
		return is_array( $event ) && isset( $event['trigger_id'] ) && is_string( $event['trigger_id'] )
			? $event['trigger_id']
			: '';
	}

	private function is_compatibility_route( $event ) {
		$trigger_id = $this->get_route_trigger_id( $event );
		if ( ! $this->triggers->is_valid_trigger_id( $trigger_id ) ) {
			return true;
		}

		return isset( $event['eventbridge_is_compatibility_trigger'] )
			&& true === (bool) $event['eventbridge_is_compatibility_trigger'];
	}

	private function get_parameter_configuration_fingerprint( $event ) {
		$parameters = is_array( $event ) && isset( $event['parameters'] ) && is_array( $event['parameters'] )
			? $this->normalize_parameters( $event['parameters'] )
			: array();
		$query_parameters = array();

		foreach ( $parameters as $parameter ) {
			if ( 'query_parameter' === $parameter['source'] ) {
				$query_parameters[] = $parameter;
			}
		}

		$encoded = wp_json_encode( $query_parameters );

		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	private function get_advanced_matching_context_aad( $event_key, $event, $event_source_url, $legacy = false ) {
		$query_configuration = array();
		$reserved            = $this->get_active_fluent_lookup_parameters();

		foreach ( $this->get_advanced_matching_map( $event ) as $field => $configuration ) {
			if ( 'query_parameter' === $configuration['source'] && ! in_array( $configuration['value'], $reserved, true ) ) {
				$query_configuration[ $field ] = $configuration;
			}
		}

		$encoded_configuration = wp_json_encode( $query_configuration );
		$fingerprint           = is_string( $encoded_configuration ) ? hash( 'sha256', $encoded_configuration ) : '';

		if ( $legacy ) {
			return 'eventbridge|advanced_matching|v1|' . $event_key . '|click|' . $event_source_url . '|' . $fingerprint;
		}

		return 'eventbridge|advanced_matching|v2|' . $event_key . '|' . $this->get_route_trigger_id( $event ) . '|' . ( isset( $event['trigger_type'] ) ? $event['trigger_type'] : '' ) . '|' . $event_source_url . '|' . $fingerprint;
	}

	private function filter_advanced_matching_user_data( $event, $user_data, $source ) {
		$filtered     = array();
		$allowed_keys = $this->get_advanced_matching_meta_keys( $event, $source );
		$user_data    = is_array( $user_data ) ? $user_data : array();

		foreach ( $allowed_keys as $meta_key ) {
			if ( isset( $user_data[ $meta_key ] )
				&& is_string( $user_data[ $meta_key ] )
				&& preg_match( '/^[a-f0-9]{64}$/D', $user_data[ $meta_key ] )
			) {
				$filtered[ $meta_key ] = $user_data[ $meta_key ];
			}
		}

		return $filtered;
	}

	private function is_valid_advanced_matching_user_data( $event, $user_data, $source ) {
		if ( ! is_array( $user_data ) ) {
			return false;
		}

		$allowed_keys = $this->get_advanced_matching_meta_keys( $event, $source );
		foreach ( $user_data as $meta_key => $value ) {
			if ( ! is_string( $meta_key )
				|| ! in_array( $meta_key, $allowed_keys, true )
				|| ! is_string( $value )
				|| ! preg_match( '/^[a-f0-9]{64}$/D', $value )
			) {
				return false;
			}
		}

		return true;
	}

	private function get_advanced_matching_meta_keys( $event, $source ) {
		$meta_keys = array( 'email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln' );
		$allowed   = array();
		$reserved  = 'query_parameter' === $source ? $this->get_active_fluent_lookup_parameters() : array();

		foreach ( $this->get_advanced_matching_map( $event ) as $field => $configuration ) {
			if ( $source === $configuration['source']
				&& isset( $meta_keys[ $field ] )
				&& ( 'query_parameter' !== $source || ! in_array( $configuration['value'], $reserved, true ) )
			) {
				$allowed[] = $meta_keys[ $field ];
			}
		}

		return $allowed;
	}

	private function get_active_regular_query_parameters( $exclude_event_key = '' ) {
		$query_parameters = array();
		$exclude_event_key = is_string( $exclude_event_key ) ? $exclude_event_key : '';

		foreach ( $this->get_normalized_events() as $event_key => $event ) {
			if ( $event_key === $exclude_event_key || true !== (bool) $event['enabled'] ) {
				continue;
			}

			foreach ( $event['triggers'] as $trigger ) {
				$route = $this->get_effective_event( $event, $trigger );
				foreach ( $route['parameters'] as $parameter ) {
					if ( 'query_parameter' === $parameter['source'] ) {
						$query_parameters[] = $parameter['value'];
					}
				}

				foreach ( $route['advanced_matching'] as $configuration ) {
					if ( 'query_parameter' === $configuration['source'] ) {
						$query_parameters[] = $configuration['value'];
					}
				}
			}
		}

		return array_values( array_unique( $query_parameters ) );
	}

	private function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private function base64url_decode( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $value ) ) {
			return false;
		}

		$encoded        = strtr( $value, '-_', '+/' );
		$padding_length = ( 4 - strlen( $encoded ) % 4 ) % 4;

		return base64_decode( $encoded . str_repeat( '=', $padding_length ), true );
	}

	private function get_advanced_matching_defaults() {
		return array(
			'email'      => array( 'source' => '', 'value' => '' ),
			'phone'      => array( 'source' => '', 'value' => '' ),
			'first_name' => array( 'source' => '', 'value' => '' ),
			'last_name'  => array( 'source' => '', 'value' => '' ),
		);
	}

	private function validate_advanced_matching( $input ) {
		$mapping = $this->get_advanced_matching_defaults();
		$errors  = array();
		$labels  = array(
			'email'      => __( 'E-mail', 'eventbridge' ),
			'phone'      => __( 'Telefoon', 'eventbridge' ),
			'first_name' => __( 'Voornaam', 'eventbridge' ),
			'last_name'  => __( 'Achternaam', 'eventbridge' ),
		);

		if ( ! is_array( $input ) ) {
			return array( 'mapping' => $mapping, 'errors' => array( __( 'De advanced-matchingconfiguratie is ongeldig.', 'eventbridge' ) ) );
		}

		foreach ( $mapping as $key => $unused ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $input[ $key ] ) ) {
				$errors[] = sprintf( __( 'Advanced Matching voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
				continue;
			}

			$row              = $input[ $key ];
			$source_is_scalar = isset( $row['source'] ) && is_scalar( $row['source'] );
			$value_is_scalar  = ! isset( $row['value'] ) || is_scalar( $row['value'] );
			$raw_source       = $source_is_scalar ? trim( wp_unslash( (string) $row['source'] ) ) : '';
			$raw_value        = isset( $row['value'] ) && is_scalar( $row['value'] ) ? trim( wp_unslash( (string) $row['value'] ) ) : '';
			$source           = sanitize_key( $raw_source );
			$value            = sanitize_text_field( $raw_value );
			$mapping[ $key ]  = array(
				'source' => $source,
				'value'  => $value,
			);

			if ( ! $source_is_scalar ) {
				$errors[] = sprintf( __( 'Bron voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
				continue;
			}

			if ( '' === $source ) {
				$mapping[ $key ] = array( 'source' => '', 'value' => '' );
				continue;
			}

			if ( ! in_array( $source, array( 'static', 'query_parameter', 'fluent_booking', 'woocommerce_billing' ), true ) ) {
				$errors[] = sprintf( __( 'Bron voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
				continue;
			}

			if ( 'fluent_booking' === $source ) {
				$mapping[ $key ] = array( 'source' => 'fluent_booking', 'value' => '' );
				continue;
			}

			if ( 'woocommerce_billing' === $source ) {
				$billing_map = $this->woocommerce ? $this->woocommerce->get_billing_field_map() : array();
				$expected    = isset( $billing_map[ $key ] ) ? $billing_map[ $key ] : '';
				$mapping[ $key ] = array( 'source' => 'woocommerce_billing', 'value' => $value );
				if ( '' === $expected || $expected !== $value ) {
					$errors[] = sprintf( __( 'WooCommerce-facturatieveld voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
				}
				continue;
			}

			if ( ! $value_is_scalar ) {
				$errors[] = sprintf( __( 'Waarde voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( '' === $value ) {
				$errors[] = sprintf( __( 'Waarde voor %s is verplicht.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( preg_match( '/[\r\n]/', $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde voor %s mag geen regeleinden bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( preg_match( '/[\x00-\x1F\x7F]/', $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde voor %s mag geen control characters bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( $raw_value !== wp_strip_all_tags( $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde voor %s mag geen HTML bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( 'query_parameter' === $source && $this->get_length( $value ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Queryparameter voor %1$s mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $labels[ $key ], self::QUERY_PARAMETER_NAME_MAX_LENGTH );
			} elseif ( 'query_parameter' === $source && ! preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ) {
				$errors[] = sprintf( __( 'Queryparameter voor %s mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( 'static' === $source && $this->get_length( $value ) > self::PARAMETER_VALUE_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Vaste waarde voor %1$s mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $labels[ $key ], self::PARAMETER_VALUE_MAX_LENGTH );
			}
		}

		return array( 'mapping' => $mapping, 'errors' => $errors );
	}

	private function normalize_advanced_matching( $input ) {
		$mapping = $this->get_advanced_matching_defaults();

		if ( ! is_array( $input ) ) {
			return $mapping;
		}

		foreach ( $mapping as $key => $unused ) {
			if ( ! isset( $input[ $key ] ) ) {
				continue;
			}

			if ( ! is_array( $input[ $key ] )
				|| ! isset( $input[ $key ]['source'] )
				|| ! is_scalar( $input[ $key ]['source'] )
				|| ( isset( $input[ $key ]['value'] ) && ! is_scalar( $input[ $key ]['value'] ) )
			) {
				continue;
			}

			$source    = trim( (string) $input[ $key ]['source'] );
			$raw_value = isset( $input[ $key ]['value'] ) ? trim( (string) $input[ $key ]['value'] ) : '';
			$value     = sanitize_text_field( $raw_value );

			if ( 'fluent_booking' === $source ) {
				$mapping[ $key ] = array( 'source' => 'fluent_booking', 'value' => '' );
				continue;
			}

			if ( 'woocommerce_billing' === $source ) {
				$billing_map = $this->woocommerce ? $this->woocommerce->get_billing_field_map() : array();
				if ( isset( $billing_map[ $key ] ) && $billing_map[ $key ] === $value ) {
					$mapping[ $key ] = array( 'source' => 'woocommerce_billing', 'value' => $value );
				}
				continue;
			}

			if ( '' === $value
				|| $raw_value !== wp_strip_all_tags( $raw_value )
				|| preg_match( '/[\x00-\x1F\x7F]/', $raw_value )
			) {
				continue;
			}

			if ( 'static' === $source && $this->get_length( $value ) <= self::PARAMETER_VALUE_MAX_LENGTH ) {
				$mapping[ $key ] = array( 'source' => 'static', 'value' => $value );
			} elseif ( 'query_parameter' === $source
				&& $this->get_length( $value ) <= self::QUERY_PARAMETER_NAME_MAX_LENGTH
				&& preg_match( '/^[A-Za-z0-9_]+$/D', $value )
			) {
				$mapping[ $key ] = array( 'source' => 'query_parameter', 'value' => $value );
			}
		}

		return $mapping;
	}

	private function get_data_source_defaults() {
		return array(
			'provider'          => '',
			'lookup_source'     => '',
			'lookup_value'      => '',
			'expected_event_id' => '',
		);
	}

	private function validate_data_source( $input ) {
		$errors = array();
		$input  = is_array( $input ) ? $input : array();
		$raw_provider = isset( $input['provider'] ) && is_scalar( $input['provider'] ) ? trim( wp_unslash( (string) $input['provider'] ) ) : '';
		$raw_lookup_source = isset( $input['lookup_source'] ) && is_scalar( $input['lookup_source'] ) ? trim( wp_unslash( (string) $input['lookup_source'] ) ) : '';
		$raw_lookup_value = isset( $input['lookup_value'] ) && is_scalar( $input['lookup_value'] ) ? trim( wp_unslash( (string) $input['lookup_value'] ) ) : '';
		$raw_expected_event_id = isset( $input['expected_event_id'] ) && is_scalar( $input['expected_event_id'] ) ? trim( wp_unslash( (string) $input['expected_event_id'] ) ) : '';

		$data_source = array(
			'provider'          => sanitize_key( $raw_provider ),
			'lookup_source'     => sanitize_key( $raw_lookup_source ),
			'lookup_value'      => sanitize_text_field( $raw_lookup_value ),
			'expected_event_id' => sanitize_text_field( $raw_expected_event_id ),
		);

		if ( '' === $data_source['provider'] ) {
			return array( 'data_source' => $this->get_data_source_defaults(), 'errors' => $errors );
		}

		if ( 'fluent_booking' !== $data_source['provider'] ) {
			$errors[] = __( 'Databronprovider is ongeldig.', 'eventbridge' );
		}
		if ( 'query_parameter' !== $data_source['lookup_source'] ) {
			$errors[] = __( 'Fluent Booking-lookupbron is ongeldig.', 'eventbridge' );
		}
		if ( '' === $data_source['lookup_value'] ) {
			$errors[] = __( 'Fluent Booking-queryparameternaam is verplicht.', 'eventbridge' );
		} elseif ( $raw_lookup_value !== wp_strip_all_tags( $raw_lookup_value ) || preg_match( '/[\x00-\x1F\x7F]/', $raw_lookup_value ) || $this->get_length( $data_source['lookup_value'] ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH || ! preg_match( '/^[A-Za-z0-9_]+$/D', $data_source['lookup_value'] ) ) {
			$errors[] = __( 'Fluent Booking-queryparameternaam mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' );
		}
		if ( '' !== $data_source['expected_event_id'] && ( ! preg_match( '/^[1-9][0-9]*$/D', $data_source['expected_event_id'] ) || strlen( $data_source['expected_event_id'] ) > 20 ) ) {
			$errors[] = __( 'Verwacht Fluent Event ID moet een positief geheel getal zijn.', 'eventbridge' );
		}

		return array( 'data_source' => $data_source, 'errors' => $errors );
	}

	private function normalize_data_source( $input ) {
		$input = wp_parse_args( is_array( $input ) ? $input : array(), $this->get_data_source_defaults() );
		$provider = is_scalar( $input['provider'] ) ? sanitize_key( (string) $input['provider'] ) : '';
		$lookup_source = is_scalar( $input['lookup_source'] ) ? sanitize_key( (string) $input['lookup_source'] ) : '';
		$lookup_value = is_scalar( $input['lookup_value'] ) ? sanitize_text_field( trim( (string) $input['lookup_value'] ) ) : '';
		$expected_event_id = is_scalar( $input['expected_event_id'] ) ? sanitize_text_field( trim( (string) $input['expected_event_id'] ) ) : '';

		if ( 'fluent_booking' !== $provider || 'query_parameter' !== $lookup_source || ! preg_match( '/^[A-Za-z0-9_]{1,100}$/D', $lookup_value ) || ( '' !== $expected_event_id && ( ! preg_match( '/^[1-9][0-9]*$/D', $expected_event_id ) || strlen( $expected_event_id ) > 20 ) ) ) {
			return $this->get_data_source_defaults();
		}

		return array( 'provider' => $provider, 'lookup_source' => $lookup_source, 'lookup_value' => $lookup_value, 'expected_event_id' => $expected_event_id );
	}

	private function get_fluent_parameter_fields() {
		return array( 'booking_id', 'event_id', 'calendar_id', 'start_time', 'event_title' );
	}

	private function protect_unavailable_fluent_configuration( $input, $event, $existing_event ) {
		$errors              = array();
		$existing_projection = is_array( $existing_event ) ? $this->get_fluent_configuration_projection( $existing_event ) : array();
		$submitted_projection = $this->get_fluent_configuration_projection( $event );

		if ( empty( $existing_projection ) ) {
			if ( ! empty( $submitted_projection ) || $this->input_has_fluent_configuration( $input ) ) {
				$errors[] = __( 'Fluent Booking is momenteel niet beschikbaar. Nieuwe Fluent Booking-configuratie kan pas worden opgeslagen wanneer de plugin actief en beschikbaar is.', 'eventbridge' );
			}

			return array( 'event' => $event, 'errors' => $errors );
		}

		if ( $this->has_changed_existing_fluent_configuration( $input, $existing_event )
			|| ! $this->is_fluent_projection_subset( $submitted_projection, $existing_projection )
		) {
			$errors[] = __( 'De bestaande Fluent Booking-configuratie kan niet worden gewijzigd zolang Fluent Booking niet beschikbaar is. Andere eventvelden kunnen wel worden aangepast.', 'eventbridge' );
		}

		return array(
			'event'  => $this->merge_existing_fluent_configuration( $event, $existing_event ),
			'errors' => $errors,
		);
	}

	private function complete_missing_existing_fluent_input( $input, $existing_event ) {
		if ( 'fluent_booking' === $existing_event['data_source']['provider'] ) {
			$input['data_source'] = isset( $input['data_source'] ) && is_array( $input['data_source'] ) ? $input['data_source'] : array();
			foreach ( $existing_event['data_source'] as $key => $value ) {
				if ( ! array_key_exists( $key, $input['data_source'] ) ) {
					$input['data_source'][ $key ] = $value;
				}
			}
		}

		$input['parameters'] = isset( $input['parameters'] ) && is_array( $input['parameters'] ) ? $input['parameters'] : array();
		foreach ( $existing_event['parameters'] as $existing_parameter ) {
			if ( 'fluent_booking' !== $existing_parameter['source'] ) {
				continue;
			}

			$matched = false;
			foreach ( $input['parameters'] as &$submitted_parameter ) {
				if ( ! is_array( $submitted_parameter )
					|| ! isset( $submitted_parameter['name'] )
					|| ! is_scalar( $submitted_parameter['name'] )
					|| sanitize_text_field( trim( wp_unslash( (string) $submitted_parameter['name'] ) ) ) !== $existing_parameter['name']
				) {
					continue;
				}

				$matched = true;
				foreach ( array( 'source', 'value' ) as $key ) {
					if ( ! array_key_exists( $key, $submitted_parameter ) ) {
						$submitted_parameter[ $key ] = $existing_parameter[ $key ];
					}
				}
				break;
			}
			unset( $submitted_parameter );

			if ( ! $matched ) {
				$input['parameters'][] = $existing_parameter;
			}
		}

		$input['advanced_matching'] = isset( $input['advanced_matching'] ) && is_array( $input['advanced_matching'] ) ? $input['advanced_matching'] : array();
		foreach ( $existing_event['advanced_matching'] as $field => $existing_configuration ) {
			if ( 'fluent_booking' !== $existing_configuration['source'] ) {
				continue;
			}

			$input['advanced_matching'][ $field ] = isset( $input['advanced_matching'][ $field ] ) && is_array( $input['advanced_matching'][ $field ] )
				? $input['advanced_matching'][ $field ]
				: array();
			foreach ( array( 'source', 'value' ) as $key ) {
				if ( ! array_key_exists( $key, $input['advanced_matching'][ $field ] ) ) {
					$input['advanced_matching'][ $field ][ $key ] = $existing_configuration[ $key ];
				}
			}
		}

		return $input;
	}

	private function input_has_fluent_configuration( $input ) {
		if ( isset( $input['data_source']['provider'] )
			&& is_scalar( $input['data_source']['provider'] )
			&& 'fluent_booking' === sanitize_key( wp_unslash( (string) $input['data_source']['provider'] ) )
		) {
			return true;
		}

		foreach ( isset( $input['parameters'] ) && is_array( $input['parameters'] ) ? $input['parameters'] : array() as $parameter ) {
			if ( is_array( $parameter )
				&& isset( $parameter['source'] )
				&& is_scalar( $parameter['source'] )
				&& 'fluent_booking' === sanitize_key( wp_unslash( (string) $parameter['source'] ) )
			) {
				return true;
			}
		}

		foreach ( isset( $input['advanced_matching'] ) && is_array( $input['advanced_matching'] ) ? $input['advanced_matching'] : array() as $configuration ) {
			if ( is_array( $configuration )
				&& isset( $configuration['source'] )
				&& is_scalar( $configuration['source'] )
				&& 'fluent_booking' === sanitize_key( wp_unslash( (string) $configuration['source'] ) )
			) {
				return true;
			}
		}

		return false;
	}

	private function get_fluent_configuration_projection( $event ) {
		$event      = $this->normalize_projected_event( $event );
		$projection = array();

		if ( 'fluent_booking' === $event['data_source']['provider'] ) {
			$projection['data_source'] = $event['data_source'];
		}

		foreach ( $event['parameters'] as $parameter ) {
			if ( 'fluent_booking' === $parameter['source'] ) {
				$projection['parameters'][] = $parameter;
			}
		}

		foreach ( $event['advanced_matching'] as $field => $configuration ) {
			if ( 'fluent_booking' === $configuration['source'] ) {
				$projection['advanced_matching'][ $field ] = $configuration;
			}
		}

		return $projection;
	}

	private function has_changed_existing_fluent_configuration( $input, $existing_event ) {
		if ( 'fluent_booking' === $existing_event['data_source']['provider'] && isset( $input['data_source'] ) && is_array( $input['data_source'] ) ) {
			foreach ( $existing_event['data_source'] as $key => $existing_value ) {
				if ( ! array_key_exists( $key, $input['data_source'] ) || ! is_scalar( $input['data_source'][ $key ] ) ) {
					continue;
				}

				$submitted_value = in_array( $key, array( 'provider', 'lookup_source' ), true )
					? sanitize_key( wp_unslash( (string) $input['data_source'][ $key ] ) )
					: sanitize_text_field( trim( wp_unslash( (string) $input['data_source'][ $key ] ) ) );
				if ( $submitted_value !== $existing_value ) {
					return true;
				}
			}
		}

		$submitted_parameters = isset( $input['parameters'] ) && is_array( $input['parameters'] ) ? $input['parameters'] : array();
		foreach ( $existing_event['parameters'] as $existing_parameter ) {
			if ( 'fluent_booking' !== $existing_parameter['source'] ) {
				continue;
			}

			foreach ( $submitted_parameters as $submitted_parameter ) {
				if ( ! is_array( $submitted_parameter )
					|| ! isset( $submitted_parameter['name'] )
					|| ! is_scalar( $submitted_parameter['name'] )
					|| sanitize_text_field( trim( wp_unslash( (string) $submitted_parameter['name'] ) ) ) !== $existing_parameter['name']
				) {
					continue;
				}

				if ( isset( $submitted_parameter['source'] )
					&& ( ! is_scalar( $submitted_parameter['source'] ) || sanitize_key( wp_unslash( (string) $submitted_parameter['source'] ) ) !== $existing_parameter['source'] )
				) {
					return true;
				}
				if ( isset( $submitted_parameter['value'] )
					&& ( ! is_scalar( $submitted_parameter['value'] ) || sanitize_text_field( trim( wp_unslash( (string) $submitted_parameter['value'] ) ) ) !== $existing_parameter['value'] )
				) {
					return true;
				}
			}
		}

		$submitted_matching = isset( $input['advanced_matching'] ) && is_array( $input['advanced_matching'] ) ? $input['advanced_matching'] : array();
		foreach ( $existing_event['advanced_matching'] as $field => $existing_configuration ) {
			if ( 'fluent_booking' !== $existing_configuration['source'] || ! array_key_exists( $field, $submitted_matching ) ) {
				continue;
			}

			if ( ! is_array( $submitted_matching[ $field ] ) ) {
				return true;
			}
			if ( isset( $submitted_matching[ $field ]['source'] )
				&& ( ! is_scalar( $submitted_matching[ $field ]['source'] ) || sanitize_key( wp_unslash( (string) $submitted_matching[ $field ]['source'] ) ) !== $existing_configuration['source'] )
			) {
				return true;
			}
			if ( isset( $submitted_matching[ $field ]['value'] )
				&& ( ! is_scalar( $submitted_matching[ $field ]['value'] ) || sanitize_text_field( trim( wp_unslash( (string) $submitted_matching[ $field ]['value'] ) ) ) !== $existing_configuration['value'] )
			) {
				return true;
			}
		}

		return false;
	}

	private function is_fluent_projection_subset( $submitted, $existing ) {
		if ( isset( $submitted['data_source'] )
			&& ( ! isset( $existing['data_source'] ) || $submitted['data_source'] !== $existing['data_source'] )
		) {
			return false;
		}

		$existing_parameters = isset( $existing['parameters'] ) ? $existing['parameters'] : array();
		foreach ( isset( $submitted['parameters'] ) ? $submitted['parameters'] : array() as $parameter ) {
			if ( ! in_array( $parameter, $existing_parameters, true ) ) {
				return false;
			}
		}

		$existing_matching = isset( $existing['advanced_matching'] ) ? $existing['advanced_matching'] : array();
		foreach ( isset( $submitted['advanced_matching'] ) ? $submitted['advanced_matching'] : array() as $field => $configuration ) {
			if ( ! isset( $existing_matching[ $field ] ) || $configuration !== $existing_matching[ $field ] ) {
				return false;
			}
		}

		return true;
	}

	private function merge_existing_fluent_configuration( $event, $existing_event ) {
		if ( 'fluent_booking' === $existing_event['data_source']['provider'] ) {
			$event['data_source'] = $existing_event['data_source'];
		}

		$parameters = array_values(
			array_filter(
				$event['parameters'],
				function ( $parameter ) {
					return 'fluent_booking' !== $parameter['source'];
				}
			)
		);

		foreach ( $existing_event['parameters'] as $index => $parameter ) {
			if ( 'fluent_booking' === $parameter['source'] ) {
				array_splice( $parameters, min( (int) $index, count( $parameters ) ), 0, array( $parameter ) );
			}
		}
		$event['parameters'] = $parameters;

		foreach ( $existing_event['advanced_matching'] as $field => $configuration ) {
			if ( 'fluent_booking' === $configuration['source'] ) {
				$event['advanced_matching'][ $field ] = $configuration;
			}
		}

		return $event;
	}

	private function validate_trigger_query_conflicts( $triggers ) {
		$lookups = array();
		$regular = array();

		foreach ( is_array( $triggers ) ? $triggers : array() as $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}
			$data_source = isset( $trigger['data_source'] ) && is_array( $trigger['data_source'] ) ? $trigger['data_source'] : array();
			if ( isset( $data_source['provider'], $data_source['lookup_source'], $data_source['lookup_value'] )
				&& 'fluent_booking' === $data_source['provider']
				&& 'query_parameter' === $data_source['lookup_source']
				&& is_string( $data_source['lookup_value'] )
				&& '' !== $data_source['lookup_value']
			) {
				$lookups[] = $data_source['lookup_value'];
			}

			foreach ( isset( $trigger['parameters'] ) && is_array( $trigger['parameters'] ) ? $trigger['parameters'] : array() as $parameter ) {
				if ( is_array( $parameter )
					&& isset( $parameter['source'], $parameter['value'] )
					&& 'query_parameter' === $parameter['source']
					&& is_string( $parameter['value'] )
				) {
					$regular[] = $parameter['value'];
				}
			}
			foreach ( isset( $trigger['advanced_matching'] ) && is_array( $trigger['advanced_matching'] ) ? $trigger['advanced_matching'] : array() as $configuration ) {
				if ( is_array( $configuration )
					&& isset( $configuration['source'], $configuration['value'] )
					&& 'query_parameter' === $configuration['source']
					&& is_string( $configuration['value'] )
				) {
					$regular[] = $configuration['value'];
				}
			}
		}

		$errors = array();
		foreach ( array_unique( array_intersect( $lookups, $regular ) ) as $parameter ) {
			$errors[] = sprintf(
				__( 'Queryparameter "%s" kan niet tegelijk als Fluent Booking-lookup en als gewone of Advanced Matching-bron in een trigger worden gebruikt.', 'eventbridge' ),
				$parameter
			);
		}

		return $errors;
	}

	private function get_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
