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
		return array( 'followup_event_ids' => array() );
	}

	public function get_settings() {
		$settings = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $settings ) ? $settings : array(), $this->get_defaults() );
	}

	public function get_followup_event_ids() {
		$settings = $this->get_settings();
		return $this->normalize_event_ids( isset( $settings['followup_event_ids'] ) ? $settings['followup_event_ids'] : array() );
	}

	public function sanitize_settings( $input ) {
		$input = is_array( $input ) ? $input : array();

		// An absent marker means this request did not submit the Fluent form.
		if ( ! isset( $input['followup_event_ids_present'] ) || '1' !== (string) $input['followup_event_ids_present'] ) {
			return $this->get_settings();
		}

		return array(
			'followup_event_ids' => $this->normalize_event_ids( isset( $input['followup_event_ids'] ) ? $input['followup_event_ids'] : array() ),
		);
	}

	private function normalize_event_ids( $ids ) {
		$ids        = is_array( $ids ) ? $ids : array();
		$normalized = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = trim( sanitize_text_field( (string) $id ) );
			if ( '' !== $id && ! in_array( $id, $normalized, true ) ) {
				$normalized[] = $id;
			}
		}

		return $normalized;
	}
}
