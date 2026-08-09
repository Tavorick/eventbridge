<?php

defined( 'ABSPATH' ) || exit;

interface EventBridge_Destination_Interface {
	public function get_id();

	public function get_label();

	public function get_capabilities();

	public function project_event_configuration( $event );

	public function send_server_event( $occurrence, $confirmed = false );
}
