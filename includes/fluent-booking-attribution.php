<?php

defined( 'ABSPATH' ) || exit;

/** Links Fluent bookings to EventBridge-owned profiles. */
class EventBridge_Fluent_Booking_Attribution {
	private $profiles;
	private $profile_repository;
	private $fluent_booking;
	private $conversions;
	private $browser_context;

	public function __construct( EventBridge_Profile_Service $profiles = null, EventBridge_Profile_Repository $profile_repository = null, EventBridge_Fluent_Booking $fluent_booking = null, EventBridge_Conversion_Service $conversions = null, EventBridge_Browser_Context_Service $browser_context = null ) {
		$this->profiles = $profiles;
		$this->profile_repository = $profile_repository;
		$this->fluent_booking = $fluent_booking;
		$this->conversions = $conversions;
		$this->browser_context = $browser_context;
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
			$link = $this->profile_repository ? $this->profile_repository->find_link( 'fluent_booking', 'booking', $external_id ) : false;
			$profile_id = is_array( $link ) && isset( $link['profile_id'] ) ? absint( $link['profile_id'] ) : 0;
			$is_web_booking = isset( $booking->source ) && is_scalar( $booking->source ) && 'web' === sanitize_key( (string) $booking->source );
			$is_followup_relevant = $this->fluent_booking && $this->fluent_booking->is_followup_relevant( $booking );
			$client_request_context = false;
			if ( $this->browser_context && $is_web_booking ) {
				try {
					$this->browser_context->capture_request_cookies( $profile_id );
				} catch ( Throwable $throwable ) {
					// Browser-context capture is best-effort and must not block the booking link or opportunity.
				}
				if ( $is_followup_relevant ) {
					try {
						$booking_ip_address = isset( $booking->ip_address ) && is_scalar( $booking->ip_address ) ? (string) $booking->ip_address : '';
						$client_request_context = $this->browser_context->get_request_client_context( $booking_ip_address );
					} catch ( Throwable $throwable ) {
						// Client-context capture is best-effort and must not block the booking link or opportunity.
						$client_request_context = false;
					}
				}
			}
			if ( ! $this->profile_repository || ! $this->fluent_booking || ! $this->conversions || ! $is_followup_relevant ) return;
			$event_source_url = $is_web_booking && isset( $booking->source_url ) && is_scalar( $booking->source_url ) ? (string) $booking->source_url : '';
			if ( is_array( $link ) ) $this->conversions->ensure_open_from_link( $link, 'fluent_booking', 'booking', $external_id, $this->fluent_booking->get_conversion_event_ids( $booking ), $client_request_context, $event_source_url );
		} catch ( Throwable $throwable ) {
			// A profile failure must never affect Fluent Booking's booking flow.
		}
	}
}
