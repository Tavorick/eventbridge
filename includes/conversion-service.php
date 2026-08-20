<?php

defined( 'ABSPATH' ) || exit;

/** Owns conversion orchestration; transport and platform projection remain destination-owned. */
class EventBridge_Conversion_Service {
	private $repository;
	private $events;
	private $dispatcher;
	private $registry;
	private $profiles;
	private $contexts;
	private $fluent_booking;

	public function __construct( EventBridge_Conversion_Repository $repository, EventBridge_Events $events = null, EventBridge_Dispatcher $dispatcher = null, EventBridge_Destination_Registry $registry = null, EventBridge_Profile_Repository $profiles = null, EventBridge_Profile_Context_Repository $contexts = null, EventBridge_Fluent_Booking $fluent_booking = null ) {
		$this->repository = $repository; $this->events = $events; $this->dispatcher = $dispatcher; $this->registry = $registry; $this->profiles = $profiles; $this->contexts = $contexts; $this->fluent_booking = $fluent_booking;
	}

	public function ensure_open_from_link( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array() ) {
		return $this->repository->ensure_open( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids );
	}

	public function execute( $conversion_id ) {
		$conversion = $this->repository->get_by_id( $conversion_id );
		if ( ! is_array( $conversion ) ) return $this->result( 'not_found' );
		if ( EventBridge_Conversion_Repository::STATUS_CONVERTED === $conversion['status'] ) return $this->result( 'already_converted', $conversion );
		$snapshot = $this->repository->decode_snapshot( $conversion['conversion_event_ids'] );
		if ( false === $snapshot || empty( $snapshot ) ) return $this->result( 'mapping_missing', $conversion );
		if ( ! $this->events || ! $this->dispatcher || ! $this->registry ) return $this->result( 'runtime_unavailable', $conversion );

		$prepared = $this->prepare_deliveries( $conversion, $snapshot );
		if ( ! $this->repository->reconcile_deliveries( $conversion['id'], $prepared ) ) return $this->result( 'storage_failed', $conversion );

		foreach ( $this->repository->get_deliveries( $conversion['id'] ) as $delivery ) {
			if ( EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED === $delivery['status'] || '' === $delivery['destination_id'] ) continue;
			$claimed = $this->repository->claim_delivery( $delivery['id'] );
			if ( ! is_array( $claimed ) ) continue;
			$error = $this->validate_claimed_delivery( $claimed );
			$occurrence = is_string( $claimed['occurrence'] ) ? json_decode( $claimed['occurrence'], true ) : null;
			if ( '' === $error && ! $this->is_safe_occurrence( $occurrence ) ) $error = 'unsafe_occurrence';
			if ( '' !== $error ) {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_BLOCKED, $error );
				continue;
			}
			$result = $this->dispatcher->dispatch_server_event( $claimed['destination_id'], $occurrence, true );
			$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : 'retryable';
			$reason = is_array( $result ) && isset( $result['reason'] ) ? sanitize_key( $result['reason'] ) : 'invalid_confirmed_result';
			$http   = is_array( $result ) && isset( $result['http_code'] ) ? absint( $result['http_code'] ) : 0;
			if ( 'success' === $status ) {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED, '', $http );
			} elseif ( 'terminal' === $status ) {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_BLOCKED, $reason, $http );
			} else {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, $reason, $http );
			}
		}

		$converted = $this->repository->finalize_if_complete( $conversion['id'], $snapshot );
		$conversion = $this->repository->get_by_id( $conversion['id'] );
		return $this->result( $converted ? 'converted' : 'incomplete', $conversion );
	}

	private function prepare_deliveries( array $conversion, array $snapshot ) {
		$existing = $this->repository->get_deliveries( $conversion['id'] );
		$identities = array();
		foreach ( $existing as $delivery ) {
			if ( ! isset( $identities[ $delivery['event_key'] ] ) ) $identities[ $delivery['event_key'] ] = array( $delivery['event_id'], absint( $delivery['event_time'] ) );
		}
		$context = $this->get_profile_context( $conversion );
		$prepared = array();
		foreach ( $snapshot as $event_key ) {
			$identity   = isset( $identities[ $event_key ] ) ? $identities[ $event_key ] : array( wp_generate_uuid4(), time() );
			$event      = $this->events->get_event( $event_key );
			$destinations = is_array( $event ) ? $this->get_confirmed_destinations( $event ) : array();
			$error = ! is_array( $event ) ? 'event_unavailable' : ( empty( $event['enabled'] ) ? 'event_disabled' : ( empty( $event['event_name'] ) ? 'invalid_event' : ( empty( $destinations ) ? 'no_server_destination' : '' ) ) );
			if ( '' !== $error ) {
				$prepared[] = array( 'event_key' => $event_key, 'destination_id' => '', 'event_id' => $identity[0], 'event_time' => $identity[1], 'status' => EventBridge_Conversion_Repository::DELIVERY_BLOCKED, 'occurrence' => null, 'error_code' => $error );
				continue;
			}
			$occurrence = $this->build_occurrence( $conversion, $event_key, $event, $identity, $context );
			if ( ! $this->is_safe_occurrence( $occurrence ) ) {
				$prepared[] = array( 'event_key' => $event_key, 'destination_id' => '', 'event_id' => $identity[0], 'event_time' => $identity[1], 'status' => EventBridge_Conversion_Repository::DELIVERY_BLOCKED, 'occurrence' => null, 'error_code' => 'unsafe_occurrence' );
				continue;
			}
			foreach ( $destinations as $destination_id ) {
				$prepared[] = array( 'event_key' => $event_key, 'destination_id' => $destination_id, 'event_id' => $identity[0], 'event_time' => $identity[1], 'status' => EventBridge_Conversion_Repository::DELIVERY_PENDING, 'occurrence' => $occurrence, 'error_code' => '' );
			}
		}
		return $prepared;
	}

	private function build_occurrence( array $conversion, $event_key, array $event, array $identity, array $profile_context ) {
		$touch = ! empty( $profile_context['last_touch'] ) ? $profile_context['last_touch'] : $profile_context['first_touch'];
		$query = is_array( $touch ) ? $touch : array();
		$fluent_snapshot = $this->fluent_booking ? $this->fluent_booking->resolve_by_external_id( $event, $conversion['external_id'] ) : false;
		$fluent_parameters = is_array( $fluent_snapshot ) && $this->fluent_booking ? $this->fluent_booking->get_parameter_data( $event, $fluent_snapshot ) : array();
		$fluent_matching = is_array( $fluent_snapshot ) && $this->fluent_booking ? $this->fluent_booking->get_advanced_matching_values( $event, $fluent_snapshot ) : array();
		$matching_values = $this->events->get_advanced_matching_values( $event, $query, '', $fluent_matching );
		$user_data = $this->events->get_advanced_matching_user_data( $matching_values );
		$source_url = $this->get_source_url( $profile_context );
		return array(
			'event_name'          => (string) $event['event_name'],
			'event_id'            => $identity[0],
			'event_time'          => $identity[1],
			'event_source_url'    => $source_url,
			'custom_data'         => $this->events->get_parameter_map( $event, $this->events->get_query_parameter_values( $event, $query ), $fluent_parameters ),
			'details'             => array( 'event_key' => $event_key, 'event_name' => (string) $event['event_name'], 'event_id' => $identity[0], 'context' => array( 'conversion_id' => absint( $conversion['id'] ), 'trigger' => 'manual_conversion' ) ),
			'advanced_user_data'  => $user_data,
			'event_configuration' => array( 'capi' => true, 'meta_test_mode' => ! empty( $event['meta_test_mode'] ), 'meta_test_event_code' => isset( $event['meta_test_event_code'] ) ? (string) $event['meta_test_event_code'] : '' ),
			'attribution_context' => array( 'first_touch' => $profile_context['first_touch'], 'last_touch' => $profile_context['last_touch'] ),
			'browser_context'     => $profile_context['browser_context'],
		);
	}

	private function get_profile_context( array $conversion ) {
		$profile = $this->profiles ? $this->profiles->get_by_id( $conversion['profile_id'] ) : false;
		$first = is_array( $profile ) && is_string( $profile['first_touch'] ) ? json_decode( $profile['first_touch'], true ) : array();
		$last  = is_array( $profile ) && is_string( $profile['last_touch'] ) ? json_decode( $profile['last_touch'], true ) : array();
		return array(
			'first_touch'     => is_array( $first ) ? $first : array(),
			'last_touch'      => is_array( $last ) ? $last : array(),
			'browser_context' => $this->contexts ? $this->contexts->get_for_profile( $conversion['profile_id'] ) : array(),
		);
	}

	private function get_source_url( array $context ) {
		foreach ( array( $context['last_touch'], $context['first_touch'] ) as $touch ) {
			$url = is_array( $touch ) && isset( $touch['landing_url'] ) ? EventBridge_Meta_URL::canonicalize( $touch['landing_url'] ) : '';
			if ( '' !== $url ) return $url;
		}
		return EventBridge_Meta_URL::canonicalize( home_url( '/' ) );
	}

	private function get_confirmed_destinations( array $event ) {
		$ids = array();
		foreach ( $this->registry->get_destinations() as $id => $destination ) {
			$capabilities = $destination->get_capabilities();
			$projected = $destination->project_event_configuration( $event );
			if ( is_array( $capabilities ) && ! empty( $capabilities['server_events'] ) && ! empty( $capabilities['confirmed_server_delivery'] ) && is_array( $projected ) && ! empty( $projected['enabled'] ) && ! empty( $projected['server']['enabled'] ) ) $ids[] = $id;
		}
		return $ids;
	}

	private function validate_claimed_delivery( array $delivery ) {
		$event = $this->events->get_event( $delivery['event_key'] );
		if ( ! is_array( $event ) ) return 'event_unavailable';
		if ( empty( $event['enabled'] ) ) return 'event_disabled';
		$destination = $this->registry->get_destination( $delivery['destination_id'] );
		if ( false === $destination ) return 'destination_unavailable';
		$capabilities = $destination->get_capabilities(); $projected = $destination->project_event_configuration( $event );
		return ! is_array( $capabilities ) || empty( $capabilities['confirmed_server_delivery'] ) || ! is_array( $projected ) || empty( $projected['enabled'] ) || empty( $projected['server']['enabled'] ) ? 'destination_unavailable' : '';
	}

	private function is_safe_occurrence( $occurrence ) {
		if ( ! is_array( $occurrence ) || empty( $occurrence['event_id'] ) || ! wp_is_uuid( $occurrence['event_id'], 4 ) || empty( $occurrence['event_source_url'] ) ) return false;
		$user_data = isset( $occurrence['advanced_user_data'] ) && is_array( $occurrence['advanced_user_data'] ) ? $occurrence['advanced_user_data'] : array();
		foreach ( $user_data as $key => $value ) if ( ! in_array( $key, array( 'em', 'ph', 'fn', 'ln' ), true ) || ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) return false;
		foreach ( array( 'email', 'phone', 'first_name', 'last_name', 'full_name' ) as $forbidden ) if ( array_key_exists( $forbidden, $occurrence ) ) return false;
		return true;
	}

	private function result( $code, $conversion = null ) {
		return array( 'code' => sanitize_key( $code ), 'conversion' => is_array( $conversion ) ? $conversion : null, 'deliveries' => is_array( $conversion ) && ! empty( $conversion['id'] ) ? $this->repository->get_deliveries( $conversion['id'] ) : array() );
	}
}
