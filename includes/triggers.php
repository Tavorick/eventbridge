<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Triggers {
	const SCHEMA_VERSION = 2;
	const MAX_TRIGGERS   = 20;
	const FAMILY_FRONTEND = 'frontend_interaction';
	const FAMILY_SERVER   = 'server_lifecycle';
	const FAMILY_CONFLICT_KEY = 'eventbridge_family_conflict';

	public function get_trigger_descriptors() {
		return array(
			'frontend:click' => array(
				'provider'     => 'frontend',
				'trigger_type' => 'click',
				'family'       => self::FAMILY_FRONTEND,
			),
			'frontend:pageview' => array(
				'provider'     => 'frontend',
				'trigger_type' => 'pageview',
				'family'       => self::FAMILY_FRONTEND,
			),
			'woocommerce:product_viewed' => array(
				'provider'     => 'woocommerce',
				'trigger_type' => 'product_viewed',
				'family'       => self::FAMILY_FRONTEND,
			),
			'woocommerce:added_to_cart' => array(
				'provider'     => 'woocommerce',
				'trigger_type' => 'added_to_cart',
				'family'       => self::FAMILY_FRONTEND,
			),
			'woocommerce:checkout_started' => array(
				'provider'     => 'woocommerce',
				'trigger_type' => 'checkout_started',
				'family'       => self::FAMILY_FRONTEND,
			),
			'woocommerce:order_lifecycle' => array(
				'provider'     => 'woocommerce',
				'trigger_type' => 'order_lifecycle',
				'family'       => self::FAMILY_SERVER,
			),
		);
	}

	public function get_family_descriptors() {
		return array(
			self::FAMILY_FRONTEND => array(
				'capabilities' => array( 'browser' => true, 'capi' => true ),
				'required'     => array(),
			),
			self::FAMILY_SERVER => array(
				'capabilities' => array( 'browser' => false, 'capi' => true ),
				'required'     => array( 'capi' ),
			),
		);
	}

	public function get_trigger_family( $trigger ) {
		if ( ! is_array( $trigger ) || ! isset( $trigger['provider'], $trigger['trigger_type'] ) ) {
			return '';
		}

		$key         = sanitize_key( (string) $trigger['provider'] ) . ':' . sanitize_key( (string) $trigger['trigger_type'] );
		$descriptors = $this->get_trigger_descriptors();

		return isset( $descriptors[ $key ] ) ? $descriptors[ $key ]['family'] : '';
	}

	public function get_event_family( $triggers ) {
		$families = array();
		foreach ( is_array( $triggers ) ? $triggers : array() as $trigger ) {
			$family = $this->get_trigger_family( $trigger );
			if ( '' === $family ) {
				return '';
			}
			$families[ $family ] = true;
		}

		return 1 === count( $families ) ? (string) key( $families ) : '';
	}

	public function get_family_capabilities( $family ) {
		$families = $this->get_family_descriptors();

		return isset( $families[ $family ] )
			? $families[ $family ]['capabilities']
			: array( 'browser' => false, 'capi' => false );
	}

	public function normalize_channels( $channels, $family = '' ) {
		$channels = is_array( $channels ) ? $channels : array();
		$result   = array(
			'browser' => ! empty( $channels['browser'] ),
			'capi'    => ! empty( $channels['capi'] ),
		);

		$families = $this->get_family_descriptors();
		if ( isset( $families[ $family ] ) ) {
			$capabilities     = $families[ $family ]['capabilities'];
			$result['browser'] = $result['browser'] && ! empty( $capabilities['browser'] );
			$result['capi']    = $result['capi'] && ! empty( $capabilities['capi'] );
			foreach ( $families[ $family ]['required'] as $required_channel ) {
				$result[ $required_channel ] = true;
			}
		}

		return $result;
	}

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

		$provider        = in_array( $trigger_type, array( 'click', 'pageview' ), true )
			? 'frontend'
			: ( 'woocommerce' === $trigger_type ? 'woocommerce' : '' );
		$type            = 'woocommerce' === $trigger_type ? 'order_lifecycle' : $trigger_type;
		$woo             = isset( $event['woocommerce'] ) && is_array( $event['woocommerce'] ) ? $event['woocommerce'] : array();
		$provider_config = array(
			'selector'        => isset( $event['selector'] ) ? $event['selector'] : '',
			'url_match_type'  => isset( $event['url_match_type'] ) ? $event['url_match_type'] : '',
			'url_match_value' => isset( $event['url_match_value'] ) ? $event['url_match_value'] : '',
			'event'           => isset( $woo['event'] ) ? $woo['event'] : '',
			'status'          => isset( $woo['status'] ) ? $woo['status'] : '',
			'purchase_preset' => ! empty( $woo['purchase_preset'] ),
		);

		if ( 'woocommerce' === $provider && 'order_lifecycle' === $type ) {
			$provider_config = array_merge( $woo, $provider_config );
		}

		return array(
			'trigger_id'      => $trigger_id,
			'provider'        => $provider,
			'trigger_type'    => $type,
			'provider_config' => $provider_config,
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
		$family   = $this->get_trigger_family( $trigger );
		$channels = isset( $event['channels'] ) && is_array( $event['channels'] )
			? $this->normalize_channels( $event['channels'], $family )
			: $this->normalize_channels(
				array(
					'browser' => ! empty( $event['browser'] ),
					'capi'    => ! empty( $event['capi'] ),
				),
				$family
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
		$woocommerce = array(
			'event'           => $config['event'],
			'status'          => $config['status'],
			'purchase_preset' => true === (bool) $config['purchase_preset'],
		);
		if ( 'woocommerce' === $trigger['provider'] && 'order_lifecycle' === $trigger['trigger_type'] ) {
			$woocommerce = array_merge(
				array_diff_key(
					$config,
					array(
						'selector'        => true,
						'url_match_type'  => true,
						'url_match_value' => true,
					)
				),
				$woocommerce
			);
		}
		$effective['woocommerce'] = $woocommerce;

		return $effective;
	}

	public function migrate_event_structure( $event, $triggers, $legacy_trigger_id = '' ) {
		$event             = is_array( $event ) ? $event : array();
		$triggers          = is_array( $triggers ) ? array_values( $triggers ) : array();
		$legacy_trigger_id = $this->is_valid_trigger_id( $legacy_trigger_id ) ? $legacy_trigger_id : '';
		$families          = array();
		$legacy_channels   = array();
		$channel_sets      = array();

		foreach ( $triggers as $index => $trigger ) {
			if ( ! is_array( $trigger ) ) {
				continue;
			}
			$family = $this->get_trigger_family( $trigger );
			if ( '' !== $family ) {
				$families[ $family ] = true;
			}
			if ( isset( $trigger['channels'] ) && is_array( $trigger['channels'] ) ) {
				$choice         = $this->normalize_channels( $trigger['channels'] );
				$channel_sets[] = $choice;
				if ( ( '' !== $legacy_trigger_id && isset( $trigger['trigger_id'] ) && $legacy_trigger_id === $trigger['trigger_id'] ) || empty( $legacy_channels ) ) {
					$legacy_channels = $choice;
				}
			}
			unset( $trigger['channels'] );
			$triggers[ $index ] = $trigger;
		}

		$is_conflict = count( $families ) > 1;
		$family      = 1 === count( $families ) ? (string) key( $families ) : '';
		if ( isset( $event['channels'] ) && is_array( $event['channels'] ) ) {
			$channels = $this->normalize_channels( $event['channels'], $family );
		} elseif ( $is_conflict && ! empty( $legacy_channels ) ) {
			$channels = $legacy_channels;
		} elseif ( ! empty( $channel_sets ) ) {
			$channels = array_shift( $channel_sets );
			foreach ( $channel_sets as $choice ) {
				$channels['browser'] = $channels['browser'] && $choice['browser'];
				$channels['capi']    = $channels['capi'] && $choice['capi'];
			}
		} else {
			$channels = $this->normalize_channels(
				array(
					'browser' => ! empty( $event['browser'] ),
					'capi'    => ! empty( $event['capi'] ),
				),
				$family
			);
		}

		if ( $is_conflict ) {
			$event['enabled'] = false;
			$event[ self::FAMILY_CONFLICT_KEY ] = array( 'families' => array_keys( $families ) );
		} else {
			unset( $event[ self::FAMILY_CONFLICT_KEY ] );
			$channels = $this->normalize_channels( $channels, $family );
			if ( self::FAMILY_FRONTEND === $family && ! $channels['browser'] && ! $channels['capi'] ) {
				$channels         = array( 'browser' => false, 'capi' => true );
				$event['enabled'] = false;
			}
		}

		$event['channels'] = $channels;

		return array( 'event' => $event, 'triggers' => $triggers, 'family' => $family, 'conflict' => $is_conflict );
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
		$preserved_triggers = array_values(
			array_filter(
				$triggers,
				function ( $trigger ) {
					return is_array( $trigger )
						&& isset( $trigger['provider'], $trigger['trigger_type'] )
						&& 'woocommerce' === $trigger['provider']
						&& in_array( $trigger['trigger_type'], array( 'product_viewed', 'added_to_cart', 'checkout_started' ), true );
				}
			)
		);

		foreach ( $triggers as $trigger ) {
			if ( is_array( $trigger ) && isset( $trigger['trigger_id'] ) && $legacy_trigger_id === $trigger['trigger_id'] ) {
				$legacy_trigger = $trigger;
				break;
			}
		}
		if ( ! $this->is_legacy_representable( $legacy_trigger ) ) {
			$legacy_trigger = null;
			foreach ( $triggers as $trigger ) {
				if ( $this->is_legacy_representable( $trigger ) && ! empty( $trigger['trigger_id'] ) ) {
					$legacy_trigger    = $trigger;
					$legacy_trigger_id = $trigger['trigger_id'];
					break;
				}
			}
		}

		if ( is_array( $legacy_trigger ) && $this->is_legacy_representable( $legacy_trigger ) ) {
			$projection = $this->get_legacy_projection( $this->to_effective_event( $event, $legacy_trigger ) );
		} else {
			$legacy_trigger_id = '';
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
			'preserved_triggers'      => $preserved_triggers,
			'preserved_trigger_hash'  => hash( 'sha256', (string) wp_json_encode( $preserved_triggers ) ),
		);

		return $event;
	}

	private function is_legacy_representable( $trigger ) {
		if ( ! is_array( $trigger ) || ! isset( $trigger['provider'], $trigger['trigger_type'] ) ) {
			return false;
		}

		return ( 'frontend' === $trigger['provider'] && in_array( $trigger['trigger_type'], array( 'click', 'pageview' ), true ) )
			|| ( 'woocommerce' === $trigger['provider'] && 'order_lifecycle' === $trigger['trigger_type'] );
	}

	public function reconcile_legacy_projection( $event, $event_key ) {
		if ( ! is_array( $event )
			|| ! isset( $event['eventbridge_compat'] )
			|| ! is_array( $event['eventbridge_compat'] )
		) {
			return $event;
		}
		$event['triggers'] = isset( $event['triggers'] ) && is_array( $event['triggers'] ) ? $event['triggers'] : array();
		$preserved = isset( $event['eventbridge_compat']['preserved_triggers'] ) && is_array( $event['eventbridge_compat']['preserved_triggers'] )
			? $event['eventbridge_compat']['preserved_triggers']
			: array();
		foreach ( $preserved as $preserved_trigger ) {
			if ( ! is_array( $preserved_trigger ) || empty( $preserved_trigger['trigger_id'] ) || ! $this->is_valid_trigger_id( $preserved_trigger['trigger_id'] ) ) {
				continue;
			}
			$replaced = false;
			foreach ( $event['triggers'] as $index => $trigger ) {
				if ( is_array( $trigger ) && isset( $trigger['trigger_id'] ) && $preserved_trigger['trigger_id'] === $trigger['trigger_id'] ) {
					$event['triggers'][ $index ] = $preserved_trigger;
					$replaced = true;
					break;
				}
			}
			if ( ! $replaced && count( $event['triggers'] ) < self::MAX_TRIGGERS ) {
				$event['triggers'][] = $preserved_trigger;
			}
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

			$legacy_channels = array(
				'browser' => ! empty( $event['browser'] ),
				'capi'    => ! empty( $event['capi'] ),
			);
			$event['triggers'][ $index ] = array_merge(
				$trigger,
				$this->from_legacy_event( $event, $event_key, $legacy_trigger_id )
			);
			$family            = $this->get_trigger_family( $event['triggers'][ $index ] );
			$event['channels'] = $this->normalize_channels( $legacy_channels, $family );
			break;
		}

		return $event;
	}
}
