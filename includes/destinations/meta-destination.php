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
}
