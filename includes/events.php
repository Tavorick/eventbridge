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
	const PARAMETER_CONTEXT_MAX_LENGTH    = 65536;
	const ADVANCED_MATCHING_PARAMETER_MAX_LENGTH = 100;

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

			$normalized_events[ $event_key ] = $this->normalize_event( $event );
		}

		return $normalized_events;
	}

	public function get_form_defaults() {
		return array(
			'label'       => '',
			'description' => '',
			'event_name'  => '',
			'browser'     => false,
			'capi'        => false,
			'enabled'     => true,
			'trigger_type' => 'click',
			'selector'     => '',
			'url_match_type'  => '',
			'url_match_value' => '',
			'parameters'   => array(),
			'advanced_matching' => $this->get_advanced_matching_defaults(),
			'remove_query_parameters' => true,
		);
	}

	public function normalize_event( $event ) {
		$event               = wp_parse_args( is_array( $event ) ? $event : array(), $this->get_form_defaults() );
		$event['parameters'] = $this->normalize_parameters( $event['parameters'] );
		$event['advanced_matching'] = $this->normalize_advanced_matching( $event['advanced_matching'] );
		$event['remove_query_parameters'] = (bool) $event['remove_query_parameters'];

		return $event;
	}

	public function get_parameter_map( $event, $query_parameter_values = array() ) {
		$parameter_map = array();
		$parameters    = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();
		$query_parameter_values = is_array( $query_parameter_values ) ? $query_parameter_values : array();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'static' === $parameter['source'] ) {
				$parameter_map[ $parameter['name'] ] = $parameter['value'];
				continue;
			}

			if ( ! isset( $query_parameter_values[ $parameter['name'] ] ) ) {
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
		$query      = is_array( $query ) ? $query : array();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' !== $parameter['source'] || ! isset( $query[ $parameter['value'] ] ) ) {
				continue;
			}

			$value = $this->get_runtime_parameter_value( $query[ $parameter['value'] ], true );
			if ( '' !== $value ) {
				$values[ $parameter['name'] ] = $value;
			}
		}

		return $values;
	}

	public function has_query_parameter_sources( $event ) {
		$parameters = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' === $parameter['source'] ) {
				return true;
			}
		}

		return false;
	}

	public function create_parameter_context( $event_key, $event, $query_parameter_values ) {
		if ( ! $this->is_valid_event_key( $event_key ) || ! $this->has_query_parameter_sources( $event ) ) {
			return '';
		}

		$payload = wp_json_encode(
			array( 'values' => $this->filter_query_parameter_values( $event, $query_parameter_values ) )
		);

		if ( ! is_string( $payload ) ) {
			return '';
		}

		$encoded_payload = rtrim( strtr( base64_encode( $payload ), '+/', '-_' ), '=' );
		$signature       = hash_hmac( 'sha256', $event_key . '|' . $encoded_payload, wp_salt( 'auth' ) );
		$context         = $encoded_payload . '.' . $signature;

		return strlen( $context ) <= self::PARAMETER_CONTEXT_MAX_LENGTH ? $context : '';
	}

	public function verify_parameter_context( $event_key, $event, $context ) {
		if ( ! $this->is_valid_event_key( $event_key )
			|| ! is_string( $context )
			|| '' === $context
			|| strlen( $context ) > self::PARAMETER_CONTEXT_MAX_LENGTH
		) {
			return false;
		}

		$parts = explode( '.', $context, 2 );
		if ( 2 !== count( $parts ) || ! preg_match( '/^[A-Za-z0-9_-]+$/D', $parts[0] ) || ! preg_match( '/^[a-f0-9]{64}$/D', $parts[1] ) ) {
			return false;
		}

		$expected_signature = hash_hmac( 'sha256', $event_key . '|' . $parts[0], wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected_signature, $parts[1] ) ) {
			return false;
		}

		$encoded_payload = strtr( $parts[0], '-_', '+/' );
		$padding_length  = ( 4 - strlen( $encoded_payload ) % 4 ) % 4;
		$payload         = base64_decode( $encoded_payload . str_repeat( '=', $padding_length ), true );
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
		foreach ( $this->get_advanced_matching_map( $event ) as $query_parameter ) {
			if ( '' !== $query_parameter ) {
				return true;
			}
		}

		return false;
	}

	public function create_advanced_matching_signature( $event_key, $event_id ) {
		return hash_hmac( 'sha256', $event_key . '|' . $event_id, wp_salt( 'auth' ) );
	}

	public function verify_advanced_matching_signature( $event_key, $event_id, $signature ) {
		if ( ! is_string( $signature ) || ! preg_match( '/^[a-f0-9]{64}$/D', $signature ) ) {
			return false;
		}

		return hash_equals( $this->create_advanced_matching_signature( $event_key, $event_id ), $signature );
	}

	public function is_valid_event_key( $event_key ) {
		return is_string( $event_key ) && (bool) preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key );
	}

	public function get_event( $event_key ) {
		if ( ! $this->is_valid_event_key( $event_key ) ) {
			return false;
		}

		$events = $this->get_events();

		return isset( $events[ $event_key ] ) && is_array( $events[ $event_key ] ) ? $this->normalize_event( $events[ $event_key ] ) : false;
	}

	public function validate_event( $input ) {
		$input                = is_array( $input ) ? $input : array();
		$parameter_validation = $this->validate_parameters( isset( $input['parameters'] ) ? $input['parameters'] : array() );
		$advanced_matching_validation = $this->validate_advanced_matching( isset( $input['advanced_matching'] ) ? $input['advanced_matching'] : array() );
		$event                = array(
			'label'       => $this->sanitize_text_value( $input, 'label', false ),
			'description' => $this->sanitize_text_value( $input, 'description', true ),
			'event_name'  => $this->sanitize_text_value( $input, 'event_name', false ),
			'browser'     => isset( $input['browser'] ),
			'capi'        => isset( $input['capi'] ),
			'enabled'     => isset( $input['enabled'] ),
			'trigger_type' => isset( $input['trigger_type'] ) && is_scalar( $input['trigger_type'] ) ? trim( wp_unslash( (string) $input['trigger_type'] ) ) : '',
			'selector'     => $this->sanitize_text_value( $input, 'selector', false ),
			'url_match_type'  => isset( $input['url_match_type'] ) && is_scalar( $input['url_match_type'] ) ? trim( wp_unslash( (string) $input['url_match_type'] ) ) : '',
			'url_match_value' => $this->sanitize_text_value( $input, 'url_match_value', false ),
			'parameters'   => $parameter_validation['parameters'],
			'advanced_matching' => $advanced_matching_validation['mapping'],
			'remove_query_parameters' => isset( $input['remove_query_parameters'] ),
		);
		$errors = array_merge( $parameter_validation['errors'], $advanced_matching_validation['errors'] );

		if ( 'pageview' === $event['trigger_type'] ) {
			$advanced_query_parameters = array_filter( $event['advanced_matching'] );
			foreach ( $event['parameters'] as $parameter ) {
				if ( 'query_parameter' === $parameter['source'] && in_array( $parameter['value'], $advanced_query_parameters, true ) ) {
					$errors[] = sprintf( __( 'Queryparameter "%s" kan niet tegelijk als gewone eventparameter en voor Advanced Matching worden gebruikt.', 'eventbridge' ), $parameter['value'] );
				}
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

		if ( ! in_array( $event['trigger_type'], array( 'click', 'pageview' ), true ) ) {
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

		if ( 'pageview' !== $event['trigger_type'] ) {
			$event['advanced_matching'] = $this->get_advanced_matching_defaults();
		}

		return array(
			'event'  => $event,
			'errors' => $errors,
		);
	}

	public function add_event( $event ) {
		$events = $this->get_events();

		do {
			$event_key = 'evt_' . wp_generate_uuid4();
		} while ( isset( $events[ $event_key ] ) );

		$events[ $event_key ] = array(
			'label'       => $event['label'],
			'description' => $event['description'],
			'event_name'  => $event['event_name'],
			'browser'     => (bool) $event['browser'],
			'capi'        => (bool) $event['capi'],
			'enabled'     => (bool) $event['enabled'],
			'trigger_type' => $event['trigger_type'],
			'selector'     => $event['selector'],
			'url_match_type'  => $event['url_match_type'],
			'url_match_value' => $event['url_match_value'],
			'parameters'   => $event['parameters'],
			'advanced_matching' => $event['advanced_matching'],
			'remove_query_parameters' => (bool) $event['remove_query_parameters'],
		);

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

		$updated_event = array(
			'label'       => $event['label'],
			'description' => $event['description'],
			'event_name'  => $event['event_name'],
			'browser'     => (bool) $event['browser'],
			'capi'        => (bool) $event['capi'],
			'enabled'     => (bool) $event['enabled'],
			'trigger_type' => $event['trigger_type'],
			'selector'     => $event['selector'],
			'url_match_type'  => $event['url_match_type'],
			'url_match_value' => $event['url_match_value'],
			'parameters'   => $event['parameters'],
			'advanced_matching' => $event['advanced_matching'],
			'remove_query_parameters' => (bool) $event['remove_query_parameters'],
		);

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

			if ( ! in_array( $source, array( 'static', 'query_parameter' ), true ) ) {
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
				|| ! in_array( $safe_source, array( 'static', 'query_parameter' ), true )
				|| ( 'static' === $safe_source && $this->get_length( $safe_value ) > self::PARAMETER_VALUE_MAX_LENGTH )
				|| ( 'query_parameter' === $safe_source && $this->get_length( $safe_value ) > self::QUERY_PARAMETER_NAME_MAX_LENGTH )
				|| ( 'query_parameter' === $safe_source && ! preg_match( '/^[A-Za-z0-9_]+$/D', $safe_value ) )
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

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			if ( 'query_parameter' !== $parameter['source'] || ! isset( $values[ $parameter['name'] ] ) ) {
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
			|| preg_match( '/[\r\n]/', $raw_value )
			|| $raw_value !== wp_strip_all_tags( $raw_value )
			|| $this->get_length( $raw_value ) > self::PARAMETER_VALUE_MAX_LENGTH
		) {
			return '';
		}

		return sanitize_text_field( $raw_value );
	}

	private function get_advanced_matching_defaults() {
		return array(
			'email'      => '',
			'phone'      => '',
			'first_name' => '',
			'last_name'  => '',
		);
	}

	private function validate_advanced_matching( $input ) {
		$mapping = $this->get_advanced_matching_defaults();
		$errors  = array();
		$labels  = array(
			'email'      => __( 'Email', 'eventbridge' ),
			'phone'      => __( 'Telefoon', 'eventbridge' ),
			'first_name' => __( 'Voornaam', 'eventbridge' ),
			'last_name'  => __( 'Achternaam', 'eventbridge' ),
		);

		if ( ! is_array( $input ) ) {
			return array( 'mapping' => $mapping, 'errors' => array( __( 'De advanced-matchingconfiguratie is ongeldig.', 'eventbridge' ) ) );
		}

		foreach ( $mapping as $key => $unused ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				continue;
			}

			if ( ! is_scalar( $input[ $key ] ) ) {
				$errors[] = sprintf( __( 'Queryparameter voor %s is ongeldig.', 'eventbridge' ), $labels[ $key ] );
				continue;
			}

			$raw_value = trim( wp_unslash( (string) $input[ $key ] ) );
			$value     = sanitize_text_field( $raw_value );
			$mapping[ $key ] = $value;

			if ( preg_match( '/[\r\n]/', $raw_value ) ) {
				$errors[] = sprintf( __( 'Queryparameter voor %s mag geen regeleinden bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( $raw_value !== wp_strip_all_tags( $raw_value ) ) {
				$errors[] = sprintf( __( 'Queryparameter voor %s mag geen HTML bevatten.', 'eventbridge' ), $labels[ $key ] );
			} elseif ( $this->get_length( $value ) > self::ADVANCED_MATCHING_PARAMETER_MAX_LENGTH ) {
				$errors[] = sprintf( __( 'Queryparameter voor %1$s mag maximaal %2$d tekens bevatten.', 'eventbridge' ), $labels[ $key ], self::ADVANCED_MATCHING_PARAMETER_MAX_LENGTH );
			} elseif ( '' !== $value && ! preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ) {
				$errors[] = sprintf( __( 'Queryparameter voor %s mag alleen letters, cijfers en underscores bevatten.', 'eventbridge' ), $labels[ $key ] );
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
			if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
				continue;
			}

			$value = trim( (string) $input[ $key ] );
			if ( '' !== $value && $this->get_length( $value ) <= self::ADVANCED_MATCHING_PARAMETER_MAX_LENGTH && preg_match( '/^[A-Za-z0-9_]+$/D', $value ) ) {
				$mapping[ $key ] = $value;
			}
		}

		return $mapping;
	}

	private function get_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
