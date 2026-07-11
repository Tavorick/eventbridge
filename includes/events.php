<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Events {
	const OPTION_NAME = 'eventbridge_events';

	const LABEL_MAX_LENGTH       = 100;
	const DESCRIPTION_MAX_LENGTH = 500;
	const EVENT_NAME_MAX_LENGTH  = 100;

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
		);
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
		);

		return update_option( self::OPTION_NAME, $events );
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
