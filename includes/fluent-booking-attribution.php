<?php

defined( 'ABSPATH' ) || exit;

/** Links Fluent bookings to EventBridge-owned profiles. */
class EventBridge_Fluent_Booking_Attribution {
	private $profiles;
	private $profile_repository;
	private $fluent_booking;
	private $conversions;

	public function __construct( EventBridge_Profile_Service $profiles = null, EventBridge_Profile_Repository $profile_repository = null, EventBridge_Fluent_Booking $fluent_booking = null, EventBridge_Conversion_Service $conversions = null ) {
		$this->profiles = $profiles;
		$this->profile_repository = $profile_repository;
		$this->fluent_booking = $fluent_booking;
		$this->conversions = $conversions;
	}

	public function init() {
		add_action( 'fluent_booking/after_booking_scheduled', array( $this, 'bind_booking' ), 20, 1 );
		add_action( 'fluent_booking/after_booking_pending', array( $this, 'bind_booking' ), 20, 1 );
	}

	public function bind_booking( $booking ) {
		if ( ! $this->profiles || ! is_object( $booking ) || ! isset( $booking->id ) || ! is_scalar( $booking->id ) ) return;
		try {
			$external_id = (string) $booking->id;
			if ( ! $this->profiles->link_external( 'fluent_booking', 'booking', $external_id ) ) return;
			if ( ! $this->profile_repository || ! $this->fluent_booking || ! $this->conversions || ! $this->fluent_booking->is_followup_relevant( $booking ) ) return;
			$link = $this->profile_repository->find_link( 'fluent_booking', 'booking', $external_id );
			if ( is_array( $link ) ) $this->conversions->ensure_open_from_link( $link, 'fluent_booking', 'booking', $external_id, $this->fluent_booking->get_conversion_event_ids( $booking ) );
		} catch ( Throwable $throwable ) {
			// A profile failure must never affect Fluent Booking's booking flow.
		}
	}
}
