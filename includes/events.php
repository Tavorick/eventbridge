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
		);
	}

	public function normalize_event( $event ) {
		$event               = wp_parse_args( is_array( $event ) ? $event : array(), $this->get_form_defaults() );
		$event['parameters'] = $this->normalize_parameters( $event['parameters'] );

		return $event;
	}

	public function get_parameter_map( $event ) {
		$parameter_map = array();
		$parameters    = is_array( $event ) && isset( $event['parameters'] ) ? $event['parameters'] : array();

		foreach ( $this->normalize_parameters( $parameters ) as $parameter ) {
			$parameter_map[ $parameter['name'] ] = $parameter['value'];
		}

		return $parameter_map;
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
		);
		$errors = $parameter_validation['errors'];

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
			$name_is_scalar  = isset( $row['name'] ) && is_scalar( $row['name'] );
			$value_is_scalar = isset( $row['value'] ) && is_scalar( $row['value'] );
			$raw_name       = $name_is_scalar ? trim( wp_unslash( (string) $row['name'] ) ) : '';
			$raw_value      = $value_is_scalar ? trim( wp_unslash( (string) $row['value'] ) ) : '';
			$name           = sanitize_text_field( $raw_name );
			$value          = sanitize_text_field( $raw_value );
			$row_number     = is_numeric( $index ) ? (int) $index + 1 : count( $parameters ) + 1;

			if ( ! $valid_row || ! $name_is_scalar || ! $value_is_scalar ) {
				$errors[] = sprintf( __( 'Parameterregel %d is ongeldig.', 'eventbridge' ), $row_number );
				$parameters[] = array(
					'name'  => $name,
					'value' => $value,
				);
				continue;
			}

			if ( '' === $raw_name && '' === $raw_value ) {
				continue;
			}

			$parameters[] = array(
				'name'  => $name,
				'value' => $value,
			);

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
			} elseif ( $raw_value !== wp_strip_all_tags( $raw_value ) ) {
				$errors[] = sprintf( __( 'Waarde in parameterregel %d mag geen HTML bevatten.', 'eventbridge' ), $row_number );
			} elseif ( $this->get_length( $value ) > self::PARAMETER_VALUE_MAX_LENGTH ) {
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
			$value      = trim( (string) $parameter['value'] );
			$safe_name  = sanitize_text_field( $name );
			$safe_value = sanitize_text_field( $value );

			if ( '' === $safe_name
				|| '' === $safe_value
				|| $value !== wp_strip_all_tags( $value )
				|| $this->get_length( $safe_name ) > self::PARAMETER_NAME_MAX_LENGTH
				|| $this->get_length( $safe_value ) > self::PARAMETER_VALUE_MAX_LENGTH
				|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $safe_name )
				|| isset( $names[ $safe_name ] )
			) {
				continue;
			}

			$names[ $safe_name ] = true;
			$normalized[] = array(
				'name'  => $safe_name,
				'value' => $safe_value,
			);
		}

		return $normalized;
	}

	private function get_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
