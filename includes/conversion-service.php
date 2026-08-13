<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Conversion_Service {
	private $repository;
	public function __construct( EventBridge_Conversion_Repository $repository ) { $this->repository = $repository; }
	public function ensure_open_from_link( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array() ) {
		return $this->repository->ensure_open( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids );
	}
}
