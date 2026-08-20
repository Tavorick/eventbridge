<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Dispatcher {
	private $registry;

	public function __construct( EventBridge_Destination_Registry $registry ) {
		$this->registry = $registry;
	}

	public function dispatch_server_event( $destination_id, array $occurrence, $confirmed = false ) {
		$destination = $this->registry->get_destination( $destination_id );

		if ( false === $destination ) {
			return false;
		}

		$capabilities = $destination->get_capabilities();
		if ( ! is_array( $capabilities ) || empty( $capabilities['server_events'] ) ) {
			return false;
		}

		if ( $confirmed && empty( $capabilities['confirmed_server_delivery'] ) ) {
			return false;
		}

		return $destination->send_server_event( $occurrence, $confirmed );
	}

	public function dispatch_custom_event( $destination_id, array $occurrence ) {
		$destination = $this->registry->get_destination( $destination_id );

		if ( false === $destination ) {
			return false;
		}

		$capabilities = $destination->get_capabilities();
		if ( ! is_array( $capabilities ) || empty( $capabilities['server_events'] ) ) {
			return false;
		}

		return $destination->send_custom_event( $occurrence );
	}
}
