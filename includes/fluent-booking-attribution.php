<?php

defined( 'ABSPATH' ) || exit;

/** Links Fluent bookings to EventBridge-owned profiles. */
class EventBridge_Fluent_Booking_Attribution {
	private $profiles;

	public function __construct( EventBridge_Profile_Service $profiles = null ) {
		$this->profiles = $profiles;
	}

	public function init() {
		add_action( 'fluent_booking/after_booking_scheduled', array( $this, 'bind_booking' ), 20, 1 );
		add_action( 'fluent_booking/after_booking_pending', array( $this, 'bind_booking' ), 20, 1 );
	}

	public function bind_booking( $booking ) {
		if ( ! $this->profiles || ! is_object( $booking ) || ! isset( $booking->id ) || ! is_scalar( $booking->id ) ) return;
		try {
			$this->profiles->link_external( 'fluent_booking', 'booking', (string) $booking->id );
		} catch ( Throwable $throwable ) {
			// A profile failure must never affect Fluent Booking's booking flow.
		}
	}
}
