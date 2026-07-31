<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Triggers {
	const SCHEMA_VERSION = 2;
	const MAX_TRIGGERS   = 20;

	public function is_valid_trigger_id( $trigger_id ) {
		return is_string( $trigger_id )
			&& (bool) preg_match( '/^trg_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $trigger_id );
	}

	public function get_legacy_trigger_id( $event_key ) {
		if ( ! is_string( $event_key )
			|| ! preg_match( '/^evt_([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$/D', $event_key, $matches )
		) {
			return '';
		}

		return 'trg_' . $matches[1];
	}

	public function create_trigger_id() {
		return 'trg_' . wp_generate_uuid4();
	}

	public function get_trigger_defaults() {
		return array(
			'trigger_id'      => '',
			'provider'        => 'frontend',
			'trigger_type'    => 'click',
			'provider_config' => array(
				'selector'        => '',
				'url_match_type'  => '',
				'url_match_value' => '',
				'event'           => '',
				'status'          => '',
				'purchase_preset' => false,
			),
			'channels' => array(
				'browser' => false,
				'capi'    => false,
			),
			'parameters'        => array(),
			'conditions'        => array(),
			'data_source'       => array(),
			'advanced_matching' => array(),
		);
	}

	public function from_legacy_event( $event, $event_key = '', $trigger_id = '' ) {
		$event        = is_array( $event ) ? $event : array();
		$trigger_type = isset( $event['trigger_type'] ) && is_scalar( $event['trigger_type'] )
			? sanitize_key( (string) $event['trigger_type'] )
			: '';

		if ( '' === $trigger_id ) {
			$trigger_id = $this->get_legacy_trigger_id( $event_key );
		}

		$provider = in_array( $trigger_type, array( 'click', 'pageview' ), true )
			? 'frontend'
			: ( 'woocommerce' === $trigger_type ? 'woocommerce' : '' );
		$type     = 'woocommerce' === $trigger_type ? 'order_lifecycle' : $trigger_type;
		$woo      = isset( $event['woocommerce'] ) && is_array( $event['woocommerce'] ) ? $event['woocommerce'] : array();

		return array(
			'trigger_id'      => $trigger_id,
			'provider'        => $provider,
			'trigger_type'    => $type,
			'provider_config' => array(
				'selector'        => isset( $event['selector'] ) ? $event['selector'] : '',
				'url_match_type'  => isset( $event['url_match_type'] ) ? $event['url_match_type'] : '',
				'url_match_value' => isset( $event['url_match_value'] ) ? $event['url_match_value'] : '',
				'event'           => isset( $woo['event'] ) ? $woo['event'] : '',
				'status'          => isset( $woo['status'] ) ? $woo['status'] : '',
				'purchase_preset' => ! empty( $woo['purchase_preset'] ),
			),
			'channels' => array(
				'browser' => ! empty( $event['browser'] ),
				'capi'    => ! empty( $event['capi'] ),
			),
			'parameters'        => isset( $event['parameters'] ) ? $event['parameters'] : array(),
			'conditions'        => isset( $event['conditions'] ) ? $event['conditions'] : array(),
			'data_source'       => isset( $event['data_source'] ) ? $event['data_source'] : array(),
			'advanced_matching' => isset( $event['advanced_matching'] ) ? $event['advanced_matching'] : array(),
		);
	}

	public function to_effective_event( $event, $trigger ) {
		$event   = is_array( $event ) ? $event : array();
		$trigger = wp_parse_args( is_array( $trigger ) ? $trigger : array(), $this->get_trigger_defaults() );
		$config  = wp_parse_args(
			is_array( $trigger['provider_config'] ) ? $trigger['provider_config'] : array(),
			$this->get_trigger_defaults()['provider_config']
		);
		$channels = wp_parse_args(
			is_array( $trigger['channels'] ) ? $trigger['channels'] : array(),
			$this->get_trigger_defaults()['channels']
		);

		$effective = $event;
		unset( $effective['triggers'], $effective['eventbridge_compat'], $effective['eventbridge_schema_version'] );

		$legacy_type = 'woocommerce' === $trigger['provider'] && 'order_lifecycle' === $trigger['trigger_type']
			? 'woocommerce'
			: $trigger['trigger_type'];

		$effective['trigger_id']      = isset( $trigger['trigger_id'] ) ? $trigger['trigger_id'] : '';
		$effective['trigger_provider'] = isset( $trigger['provider'] ) ? $trigger['provider'] : '';
		$effective['trigger_type']    = $legacy_type;
		$effective['selector']        = 'click' === $legacy_type ? $config['selector'] : '';
		$effective['url_match_type']  = 'pageview' === $legacy_type ? $config['url_match_type'] : '';
		$effective['url_match_value'] = 'pageview' === $legacy_type ? $config['url_match_value'] : '';
		$effective['browser']         = true === (bool) $channels['browser'];
		$effective['capi']            = true === (bool) $channels['capi'];
		$effective['parameters']      = is_array( $trigger['parameters'] ) ? $trigger['parameters'] : array();
		$effective['conditions']      = is_array( $trigger['conditions'] ) ? $trigger['conditions'] : array();
		$effective['data_source']     = is_array( $trigger['data_source'] ) ? $trigger['data_source'] : array();
		$effective['advanced_matching'] = is_array( $trigger['advanced_matching'] ) ? $trigger['advanced_matching'] : array();
		$effective['woocommerce'] = array(
			'event'           => $config['event'],
			'status'          => $config['status'],
			'purchase_preset' => true === (bool) $config['purchase_preset'],
		);

		return $effective;
	}

	public function get_legacy_projection( $event ) {
		$event = is_array( $event ) ? $event : array();

		return array(
			'trigger_type'      => isset( $event['trigger_type'] ) ? $event['trigger_type'] : '',
			'selector'          => isset( $event['selector'] ) ? $event['selector'] : '',
			'url_match_type'    => isset( $event['url_match_type'] ) ? $event['url_match_type'] : '',
			'url_match_value'   => isset( $event['url_match_value'] ) ? $event['url_match_value'] : '',
			'browser'           => ! empty( $event['browser'] ),
			'capi'              => ! empty( $event['capi'] ),
			'parameters'        => isset( $event['parameters'] ) && is_array( $event['parameters'] ) ? $event['parameters'] : array(),
			'conditions'        => isset( $event['conditions'] ) && is_array( $event['conditions'] ) ? $event['conditions'] : array(),
			'data_source'       => isset( $event['data_source'] ) && is_array( $event['data_source'] ) ? $event['data_source'] : array(),
			'advanced_matching' => isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array(),
			'woocommerce'       => isset( $event['woocommerce'] ) && is_array( $event['woocommerce'] ) ? $event['woocommerce'] : array(),
		);
	}

	public function get_projection_hash( $event ) {
		$encoded = wp_json_encode( $this->get_legacy_projection( $event ) );

		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	public function apply_compatibility_shadow( $event, $triggers, $legacy_trigger_id ) {
		$event             = is_array( $event ) ? $event : array();
		$triggers          = is_array( $triggers ) ? array_values( $triggers ) : array();
		$legacy_trigger_id = $this->is_valid_trigger_id( $legacy_trigger_id ) ? $legacy_trigger_id : '';
		$legacy_trigger    = null;

		foreach ( $triggers as $trigger ) {
			if ( is_array( $trigger ) && isset( $trigger['trigger_id'] ) && $legacy_trigger_id === $trigger['trigger_id'] ) {
				$legacy_trigger = $trigger;
				break;
			}
		}

		if ( is_array( $legacy_trigger ) ) {
			$projection = $this->get_legacy_projection( $this->to_effective_event( $event, $legacy_trigger ) );
		} else {
			$projection = array(
				'trigger_type'      => 'eventbridge_disabled',
				'selector'          => '',
				'url_match_type'    => '',
				'url_match_value'   => '',
				'browser'           => false,
				'capi'              => false,
				'parameters'        => array(),
				'conditions'        => array(),
				'data_source'       => array(),
				'advanced_matching' => array(),
				'woocommerce'       => array(),
			);
		}

		$event = array_merge( $event, $projection );
		$event['triggers']                   = $triggers;
		$event['eventbridge_schema_version'] = self::SCHEMA_VERSION;
		$event['eventbridge_compat'] = array(
			'legacy_trigger_id'      => $legacy_trigger_id,
			'legacy_projection_hash' => $this->get_projection_hash( $event ),
		);

		return $event;
	}

	public function reconcile_legacy_projection( $event, $event_key ) {
		if ( ! is_array( $event )
			|| ! isset( $event['triggers'], $event['eventbridge_compat'] )
			|| ! is_array( $event['triggers'] )
			|| ! is_array( $event['eventbridge_compat'] )
		) {
			return $event;
		}

		$legacy_trigger_id = isset( $event['eventbridge_compat']['legacy_trigger_id'] )
			&& is_string( $event['eventbridge_compat']['legacy_trigger_id'] )
			? $event['eventbridge_compat']['legacy_trigger_id']
			: '';
		$stored_hash = isset( $event['eventbridge_compat']['legacy_projection_hash'] )
			&& is_string( $event['eventbridge_compat']['legacy_projection_hash'] )
			? $event['eventbridge_compat']['legacy_projection_hash']
			: '';

		if ( '' === $legacy_trigger_id || hash_equals( $stored_hash, $this->get_projection_hash( $event ) ) ) {
			return $event;
		}

		foreach ( $event['triggers'] as $index => $trigger ) {
			if ( ! is_array( $trigger )
				|| ! isset( $trigger['trigger_id'] )
				|| $legacy_trigger_id !== $trigger['trigger_id']
			) {
				continue;
			}

			$event['triggers'][ $index ] = array_merge(
				$trigger,
				$this->from_legacy_event( $event, $event_key, $legacy_trigger_id )
			);
			break;
		}

		return $event;
	}
}
