<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Meta_Destination implements EventBridge_Destination_Interface {
	private $meta_capi;

	public function __construct( EventBridge_Meta_CAPI $meta_capi ) {
		$this->meta_capi = $meta_capi;
	}

	public function get_id() {
		return 'meta';
	}

	public function get_label() {
		return 'Meta';
	}

	public function get_capabilities() {
		return array(
			'browser_events'             => true,
			'server_events'              => true,
			'confirmed_server_delivery'  => true,
			'customer_matching'          => true,
			'test_mode'                  => true,
		);
	}

	public function project_event_configuration( $event ) {
		$event    = is_array( $event ) ? $event : array();
		$channels = isset( $event['channels'] ) && is_array( $event['channels'] ) ? $event['channels'] : array();
		$browser  = array_key_exists( 'browser', $channels ) ? ! empty( $channels['browser'] ) : ! empty( $event['browser'] );
		$server   = array_key_exists( 'capi', $channels ) ? ! empty( $channels['capi'] ) : ! empty( $event['capi'] );

		return array(
			'id'         => $this->get_id(),
			'enabled'    => ! empty( $event['enabled'] ),
			'event_name' => isset( $event['event_name'] ) && is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
			'browser'    => array(
				'enabled' => $browser,
			),
			'server'     => array(
				'enabled' => $server,
			),
			'test'       => array(
				'enabled' => ! empty( $event['meta_test_mode'] ),
				'code'    => isset( $event['meta_test_event_code'] ) && is_scalar( $event['meta_test_event_code'] ) ? (string) $event['meta_test_event_code'] : '',
			),
		);
	}

	public function send_server_event( $occurrence, $confirmed = false ) {
		$occurrence = is_array( $occurrence ) ? $occurrence : array();
		$occurrence['advanced_user_data'] = $this->add_stored_browser_identifiers(
			isset( $occurrence['advanced_user_data'] ) && is_array( $occurrence['advanced_user_data'] ) ? $occurrence['advanced_user_data'] : array(),
			isset( $occurrence['browser_context'] ) && is_array( $occurrence['browser_context'] ) ? $occurrence['browser_context'] : array(),
			isset( $occurrence['attribution_context'] ) && is_array( $occurrence['attribution_context'] ) ? $occurrence['attribution_context'] : array()
		);
		$arguments  = array(
			isset( $occurrence['event_name'] ) ? $occurrence['event_name'] : '',
			isset( $occurrence['event_id'] ) ? $occurrence['event_id'] : '',
			isset( $occurrence['event_time'] ) ? $occurrence['event_time'] : 0,
			isset( $occurrence['event_source_url'] ) ? $occurrence['event_source_url'] : '',
			isset( $occurrence['custom_data'] ) && is_array( $occurrence['custom_data'] ) ? $occurrence['custom_data'] : array(),
			isset( $occurrence['details'] ) && is_array( $occurrence['details'] ) ? $occurrence['details'] : array(),
			isset( $occurrence['advanced_user_data'] ) && is_array( $occurrence['advanced_user_data'] ) ? $occurrence['advanced_user_data'] : array(),
			isset( $occurrence['event_configuration'] ) && is_array( $occurrence['event_configuration'] ) ? $occurrence['event_configuration'] : array(),
		);

		if ( $confirmed ) {
			return $this->meta_capi->send_server_event_confirmed(
				$arguments[0],
				$arguments[1],
				$arguments[2],
				$arguments[3],
				$arguments[4],
				$arguments[5],
				$arguments[6],
				$arguments[7]
			);
		}

		return $this->meta_capi->send_server_event(
			$arguments[0],
			$arguments[1],
			$arguments[2],
			$arguments[3],
			$arguments[4],
			$arguments[5],
			$arguments[6],
			$arguments[7]
		);
	}

	public function send_custom_event( $occurrence ) {
		$occurrence = is_array( $occurrence ) ? $occurrence : array();

		return $this->meta_capi->send_custom_event(
			isset( $occurrence['event_name'] ) ? $occurrence['event_name'] : '',
			isset( $occurrence['event_id'] ) ? $occurrence['event_id'] : '',
			isset( $occurrence['event_source_url'] ) ? $occurrence['event_source_url'] : '',
			isset( $occurrence['custom_data'] ) && is_array( $occurrence['custom_data'] ) ? $occurrence['custom_data'] : array(),
			isset( $occurrence['details'] ) && is_array( $occurrence['details'] ) ? $occurrence['details'] : array(),
			isset( $occurrence['advanced_user_data'] ) && is_array( $occurrence['advanced_user_data'] ) ? $occurrence['advanced_user_data'] : array(),
			isset( $occurrence['event_configuration'] ) && is_array( $occurrence['event_configuration'] ) ? $occurrence['event_configuration'] : array()
		);
	}

	private function add_stored_browser_identifiers( array $user_data, array $browser_context, array $attribution_context ) {
		$cookies = isset( $browser_context['browser_cookie'] ) && is_array( $browser_context['browser_cookie'] ) ? $browser_context['browser_cookie'] : array();
		foreach ( array( '_fbp' => 'fbp', '_fbc' => 'fbc' ) as $context_key => $meta_key ) {
			$value = isset( $cookies[ $context_key ]['value'] ) && is_string( $cookies[ $context_key ]['value'] ) ? trim( $cookies[ $context_key ]['value'] ) : '';
			if ( $this->is_valid_browser_identifier( $value ) ) $user_data[ $meta_key ] = $value;
		}
		if ( empty( $user_data['fbc'] ) ) {
			foreach ( array( 'last_touch', 'first_touch' ) as $touch_key ) {
				$touch = isset( $attribution_context[ $touch_key ] ) && is_array( $attribution_context[ $touch_key ] ) ? $attribution_context[ $touch_key ] : array();
				$fbclid = isset( $touch['fbclid'] ) && is_string( $touch['fbclid'] ) ? trim( $touch['fbclid'] ) : '';
				$captured = isset( $touch['captured_at'] ) && is_string( $touch['captured_at'] ) ? strtotime( $touch['captured_at'] ) : false;
				if ( '' !== $fbclid && strlen( $fbclid ) <= 255 && ! preg_match( '/[^A-Za-z0-9._-]/', $fbclid ) && false !== $captured && $captured > 0 ) {
					$user_data['fbc'] = 'fb.1.' . ( $captured * 1000 ) . '.' . $fbclid;
					break;
				}
			}
		}
		return $user_data;
	}

	private function is_valid_browser_identifier( $value ) {
		return is_string( $value ) && strlen( $value ) <= 255 && (bool) preg_match( '/^fb\.1\.[0-9]{10,16}\.[A-Za-z0-9._-]+$/D', $value );
	}
}
