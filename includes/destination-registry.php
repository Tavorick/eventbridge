<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Destination_Registry {
	private $destinations = array();

	public function register( EventBridge_Destination_Interface $destination ) {
		$id = $destination->get_id();

		if ( ! is_string( $id ) || '' === $id || isset( $this->destinations[ $id ] ) ) {
			return false;
		}

		$this->destinations[ $id ] = $destination;

		return true;
	}

	public function get_destination( $id ) {
		if ( ! is_string( $id ) || ! isset( $this->destinations[ $id ] ) ) {
			return false;
		}

		return $this->destinations[ $id ];
	}

	public function get_destinations() {
		return $this->destinations;
	}
}
