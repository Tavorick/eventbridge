<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Events {
	const OPTION_NAME = 'eventbridge_events';

	const LABEL_MAX_LENGTH       = 100;
	const DESCRIPTION_MAX_LENGTH = 500;
	const EVENT_NAME_MAX_LENGTH  = 100;
	const SELECTOR_MAX_LENGTH    = 255;

	public function get_events() {
		$events = get_option( self::OPTION_NAME, array() );

		return is_array( $events ) ? $events : array();
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
		);
	}

	public function normalize_event( $event ) {
		return wp_parse_args( is_array( $event ) ? $event : array(), $this->get_form_defaults() );
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
		$input = is_array( $input ) ? $input : array();
		$event = array(
			'label'       => $this->sanitize_text_value( $input, 'label', false ),
			'description' => $this->sanitize_text_value( $input, 'description', true ),
			'event_name'  => $this->sanitize_text_value( $input, 'event_name', false ),
			'browser'     => isset( $input['browser'] ),
			'capi'        => isset( $input['capi'] ),
			'enabled'     => isset( $input['enabled'] ),
			'trigger_type' => isset( $input['trigger_type'] ) && is_scalar( $input['trigger_type'] ) ? trim( wp_unslash( (string) $input['trigger_type'] ) ) : '',
			'selector'     => $this->sanitize_text_value( $input, 'selector', false ),
		);
		$errors = array();

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

		if ( 'click' !== $event['trigger_type'] ) {
			$errors[] = __( 'Triggertype is ongeldig.', 'eventbridge' );
		}

		$raw_selector = isset( $input['selector'] ) && is_scalar( $input['selector'] ) ? wp_unslash( (string) $input['selector'] ) : '';
		if ( '' === $event['selector'] ) {
			$errors[] = __( 'CSS-selector is verplicht.', 'eventbridge' );
		} elseif ( preg_match( '/[\r\n]/', $raw_selector ) ) {
			$errors[] = __( 'CSS-selector mag geen regeleinden bevatten.', 'eventbridge' );
		} elseif ( $raw_selector !== wp_strip_all_tags( $raw_selector ) ) {
			$errors[] = __( 'CSS-selector mag geen HTML-tags bevatten.', 'eventbridge' );
		} elseif ( $this->get_length( $event['selector'] ) > self::SELECTOR_MAX_LENGTH ) {
			$errors[] = sprintf( __( 'CSS-selector mag maximaal %d tekens bevatten.', 'eventbridge' ), self::SELECTOR_MAX_LENGTH );
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

	private function get_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
