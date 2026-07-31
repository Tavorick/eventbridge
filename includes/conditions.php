<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Conditions {
	const MAX_CONDITIONS = 50;
	const MAX_REFERENCES = 100;
	const DEBUG_TTL      = 3600;

	private $providers = array();
	private $settings;
	private $log;

	public function __construct( $providers = array(), EventBridge_Settings $settings = null, EventBridge_Log $log = null ) {
		$this->settings = $settings;
		$this->log      = $log;

		$providers = apply_filters( 'eventbridge_condition_providers', is_array( $providers ) ? $providers : array() );
		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof EventBridge_Condition_Provider_Interface ) {
				continue;
			}

			$key = sanitize_key( (string) $provider->get_key() );
			if ( '' === $key || isset( $this->providers[ $key ] ) ) {
				continue;
			}

			$this->providers[ $key ] = $provider;
		}
	}

	public function get_provider( $key ) {
		$key = is_scalar( $key ) ? sanitize_key( (string) $key ) : '';

		return isset( $this->providers[ $key ] ) ? $this->providers[ $key ] : false;
	}

	public function get_catalog( $provider_key ) {
		$provider = $this->get_provider( $provider_key );
		$catalog  = $provider ? $provider->get_catalog() : array();

		return is_array( $catalog ) ? $catalog : array();
	}

	public function normalize_conditions( $conditions ) {
		if ( ! is_array( $conditions ) ) {
			return array( $this->get_invalid_condition() );
		}

		$normalized = array();
		foreach ( array_slice( $conditions, 0, self::MAX_CONDITIONS + 1, true ) as $condition ) {
			if ( ! is_array( $condition ) ) {
				$normalized[] = $this->get_invalid_condition();
				continue;
			}

			$provider = isset( $condition['provider'] ) && is_scalar( $condition['provider'] ) ? sanitize_key( (string) $condition['provider'] ) : '';
			$field    = isset( $condition['field'] ) && is_scalar( $condition['field'] ) ? sanitize_key( (string) $condition['field'] ) : '';
			$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] ) ? sanitize_key( (string) $condition['operator'] ) : '';
			$value    = array_key_exists( 'value', $condition ) ? $this->normalize_generic_value( $condition['value'] ) : null;

			$normalized[] = array(
				'provider' => $provider,
				'field'    => $field,
				'operator' => $operator,
				'value'    => $value,
			);
		}

		return array_values( $normalized );
	}

	public function validate_conditions( $input, $event, $existing_conditions = array() ) {
		$errors     = array();
		$conditions = array();
		$event      = is_array( $event ) ? $event : array();
		$existing   = is_array( $existing_conditions ) ? $existing_conditions : array();

		if ( null === $input ) {
			return array( 'conditions' => array(), 'errors' => array() );
		}

		if ( ! is_array( $input ) ) {
			return array(
				'conditions' => array( $this->get_invalid_condition() ),
				'errors'     => array( __( 'De voorwaardenconfiguratie is ongeldig.', 'eventbridge' ) ),
			);
		}

		if ( count( $input ) > self::MAX_CONDITIONS ) {
			$errors[] = sprintf( __( 'Een event mag maximaal %d voorwaarden bevatten.', 'eventbridge' ), self::MAX_CONDITIONS );
		}

		foreach ( array_slice( $input, 0, self::MAX_CONDITIONS, true ) as $index => $condition ) {
			$row_number = is_numeric( $index ) ? absint( $index ) + 1 : count( $conditions ) + 1;
			if ( ! is_array( $condition ) ) {
				$conditions[] = $this->get_invalid_condition();
				$errors[]     = sprintf( __( 'Voorwaardenrij %d is ongeldig.', 'eventbridge' ), $row_number );
				continue;
			}

			$provider_key = isset( $condition['provider'] ) && is_scalar( $condition['provider'] ) ? sanitize_key( wp_unslash( (string) $condition['provider'] ) ) : '';
			$provider     = $this->get_provider( $provider_key );
			if ( ! $provider ) {
				$conditions[] = $this->normalize_conditions( array( $condition ) )[0];
				$errors[]     = sprintf( __( 'De provider in voorwaardenrij %d is ongeldig.', 'eventbridge' ), $row_number );
				continue;
			}

			if ( ! $provider->supports_event( $event ) ) {
				$errors[] = sprintf( __( 'Voorwaardenrij %d hoort niet bij de gekozen trigger.', 'eventbridge' ), $row_number );
			}

			$result = $provider->validate_condition( $condition, $existing );
			if ( isset( $result['condition'] ) && is_array( $result['condition'] ) ) {
				$conditions[] = $result['condition'];
			} else {
				$conditions[] = $this->get_invalid_condition();
			}

			foreach ( isset( $result['errors'] ) && is_array( $result['errors'] ) ? $result['errors'] : array() as $message ) {
				$errors[] = sprintf( __( 'Voorwaardenrij %1$d: %2$s', 'eventbridge' ), $row_number, $message );
			}
		}

		return array(
			'conditions' => array_values( $conditions ),
			'errors'     => array_values( array_unique( $errors ) ),
		);
	}

	public function build_context( $provider_key, $trigger, $subject, $conditions ) {
		$provider = $this->get_provider( $provider_key );
		if ( ! $provider ) {
			return array( 'status' => 'invalid_context', 'reason' => 'provider_unavailable' );
		}

		$context = $provider->build_context( $trigger, $subject, $conditions );
		if ( ! is_array( $context ) || ! isset( $context['provider'] ) || $provider_key !== $context['provider'] ) {
			return array( 'status' => 'invalid_context', 'reason' => 'context_invalid' );
		}

		return $context;
	}

	public function evaluate( $conditions, $context, $event_key = '', $trigger_id = '' ) {
		if ( ! is_array( $conditions ) || count( $conditions ) > self::MAX_CONDITIONS ) {
			$this->diagnose( $event_key, $trigger_id, 'conditions_invalid' );
			return array( 'status' => 'invalid_context', 'reason' => 'conditions_invalid', 'index' => -1 );
		}

		if ( empty( $conditions ) ) {
			return array( 'status' => 'match', 'reason' => '', 'index' => -1 );
		}

		foreach ( array_values( $conditions ) as $index => $condition ) {
			if ( ! is_array( $condition ) || ! isset( $condition['provider'] ) || ! is_scalar( $condition['provider'] ) ) {
				$this->diagnose( $event_key, $trigger_id, 'condition_invalid' );
				return array( 'status' => 'invalid_context', 'reason' => 'condition_invalid', 'index' => $index );
			}

			$provider_key = sanitize_key( (string) $condition['provider'] );
			$provider     = $this->get_provider( $provider_key );
			if ( ! $provider || ! is_array( $context ) || ! isset( $context['provider'] ) || $provider_key !== $context['provider'] ) {
				$this->diagnose( $event_key, $trigger_id, 'condition_provider_mismatch' );
				return array( 'status' => 'invalid_context', 'reason' => 'condition_provider_mismatch', 'index' => $index );
			}

			$result = $provider->evaluate( $condition, $context );
			$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : 'invalid_context';
			$reason = is_array( $result ) && isset( $result['reason'] ) ? sanitize_key( (string) $result['reason'] ) : 'evaluation_invalid';

			if ( 'match' === $status ) {
				continue;
			}
			if ( 'mismatch' === $status ) {
				return array( 'status' => 'mismatch', 'reason' => $reason, 'index' => $index );
			}

			$this->diagnose( $event_key, $trigger_id, $reason );
			return array( 'status' => 'invalid_context', 'reason' => $reason, 'index' => $index );
		}

		return array( 'status' => 'match', 'reason' => '', 'index' => -1 );
	}

	public function search_values( $provider_key, $field, $search, $page = 1, $limit = 20 ) {
		$provider = $this->get_provider( $provider_key );
		if ( ! $provider ) {
			return array( 'results' => array(), 'more' => false );
		}

		return $provider->search_values(
			sanitize_key( (string) $field ),
			is_scalar( $search ) ? sanitize_text_field( (string) $search ) : '',
			max( 1, absint( $page ) ),
			min( 20, max( 1, absint( $limit ) ) )
		);
	}

	public function resolve_value_labels( $provider_key, $field, $values ) {
		$provider = $this->get_provider( $provider_key );

		return $provider ? $provider->resolve_value_labels( sanitize_key( (string) $field ), is_array( $values ) ? $values : array( $values ) ) : array();
	}

	private function normalize_generic_value( $value ) {
		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( array_slice( $value, 0, self::MAX_REFERENCES + 1, true ) as $item ) {
				if ( is_scalar( $item ) || null === $item ) {
					$normalized[] = is_string( $item ) ? trim( $item ) : $item;
				} else {
					$normalized[] = null;
				}
			}
			return array_values( $normalized );
		}

		if ( is_scalar( $value ) || null === $value ) {
			return is_string( $value ) ? trim( $value ) : $value;
		}

		return null;
	}

	private function get_invalid_condition() {
		return array(
			'provider' => '',
			'field'    => '',
			'operator' => '',
			'value'    => null,
		);
	}

	private function diagnose( $event_key, $trigger_id, $reason ) {
		if ( ! $this->settings || ! $this->log ) {
			return;
		}

		$settings = $this->settings->get_settings();
		if ( ! isset( $settings['debug'] ) || true !== (bool) $settings['debug'] ) {
			return;
		}

		$event_key = is_string( $event_key ) ? $event_key : '';
		$trigger_id = is_string( $trigger_id ) ? $trigger_id : '';
		$reason    = sanitize_key( (string) $reason );
		$key       = 'eventbridge_condition_debug_' . substr( hash( 'sha256', $event_key . '|' . $trigger_id . '|' . $reason ), 0, 32 );
		if ( false !== get_transient( $key ) ) {
			return;
		}

		set_transient( $key, '1', self::DEBUG_TTL );
		$this->log->log(
			'info',
			'conditions',
			'Conditional dispatch skipped because condition data was invalid.',
			array(
				'event_key' => $event_key,
				'trigger_id' => $trigger_id,
				'context'   => array( 'reason' => $reason ),
			)
		);
	}
}
