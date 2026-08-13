<?php

defined( 'ABSPATH' ) || exit;

/** Stores EventBridge-owned configuration for the Fluent Booking integration. */
class EventBridge_Fluent_Booking_Settings {
	const OPTION_NAME  = 'eventbridge_fluent_booking_settings';
	const OPTION_GROUP = 'eventbridge_fluent_booking_settings_group';

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);
		register_setting( EventBridge_Settings::CONNECTIONS_OPTION_GROUP, self::OPTION_NAME );
	}

	public function get_defaults() {
		return array( 'followup_event_ids' => array(), 'followups' => null );
	}

	public function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $this->get_defaults() );
	}

	public function get_followup_event_ids() {
		$settings = $this->get_settings();
		if ( isset( $settings['followups'] ) && is_array( $settings['followups'] ) ) {
			return array_keys( $this->normalize_followups( $settings['followups'] ) );
		}
		return $this->normalize_event_ids( isset( $settings['followup_event_ids'] ) ? $settings['followup_event_ids'] : array() );
	}

	/** Returns the EventBridge event-key snapshot configuration for one Fluent appointment type. */
	public function get_conversion_event_ids( $fluent_event_id ) {
		$fluent_event_id = $this->normalize_event_id( $fluent_event_id );
		$settings        = $this->get_settings();
		if ( '' === $fluent_event_id || ! isset( $settings['followups'] ) || ! is_array( $settings['followups'] ) ) {
			return array();
		}
		$followups = $this->normalize_followups( $settings['followups'] );
		return isset( $followups[ $fluent_event_id ] ) ? $followups[ $fluent_event_id ]['conversion_event_ids'] : array();
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		// An absent marker means this request did not submit the Fluent form.
		if ( isset( $input['followups_present'] ) && '1' === (string) $input['followups_present'] ) {
			return array( 'followups' => $this->sanitize_followups( isset( $input['followups'] ) ? $input['followups'] : array() ) );
		}

		// Keep accepting the former checkbox-only form shape for integrations and old pages.
		if ( isset( $input['followup_event_ids_present'] ) && '1' === (string) $input['followup_event_ids_present'] ) {
			$followups = array();
			$event_ids = $this->normalize_event_ids( isset( $input['followup_event_ids'] ) ? $input['followup_event_ids'] : array() );
			foreach ( $event_ids as $event_id ) {
				$followups[ $event_id ] = array( 'conversion_event_ids' => array() );
			}
			return array( 'followup_event_ids' => $event_ids, 'followups' => $followups );
		}


		return $this->get_settings();
	}

	private function normalize_followups( $followups ) {
		$followups  = is_array( $followups ) ? $followups : array();
		$normalized = array();
		foreach ( $followups as $fluent_event_id => $followup ) {
			$fluent_event_id = $this->normalize_event_id( $fluent_event_id );
			if ( '' === $fluent_event_id || ! is_array( $followup ) ) {
				continue;
			}
			$normalized[ $fluent_event_id ] = array(
				'conversion_event_ids' => $this->normalize_conversion_event_ids( isset( $followup['conversion_event_ids'] ) ? $followup['conversion_event_ids'] : array() ),
			);
		}
		return $normalized;
	}

	private function sanitize_followups( $followups ) {
		$followups = is_array( $followups ) ? $followups : array();
		$enabled    = array();
		foreach ( $followups as $fluent_event_id => $followup ) {
			if ( is_array( $followup ) && ! empty( $followup['enabled'] ) ) {
				$enabled[ $fluent_event_id ] = $followup;
			}
		}
		return $this->normalize_followups( $enabled );
	}

	private function normalize_event_ids( $ids ) {
		$ids        = is_array( $ids ) ? $ids : array();
		$normalized = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = $this->normalize_event_id( $id );
			if ( '' !== $id && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}

		return $normalized;
	}

	private function normalize_event_id( $id ) {
		return is_scalar( $id ) ? trim( sanitize_text_field( (string) $id ) ) : '';
	}

	private function normalize_conversion_event_ids( $ids ) {
		$ids        = is_array( $ids ) ? $ids : array();
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}
			$id = trim( sanitize_text_field( (string) $id ) );
			if ( preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id ) && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}
		return $normalized;
	}
}
