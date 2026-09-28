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

	public function ensure_open_from_link( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids = array(), $client_request_context = false, $event_source_url = '' ) {
		$profile_id = is_array( $profile_link ) && isset( $profile_link['profile_id'] ) ? absint( $profile_link['profile_id'] ) : 0;
		return $this->repository->ensure_open( $profile_link, $provider, $entity_type, $external_id, $conversion_event_ids, $this->build_attribution_snapshot( $profile_id, $client_request_context, $event_source_url ) );
	}

	public function execute( $conversion_id ) {
		$conversion = $this->repository->get_by_id( $conversion_id );
		if ( ! is_array( $conversion ) ) return $this->result( 'not_found' );
		if ( EventBridge_Conversion_Repository::STATUS_CONVERTED === $conversion['status'] ) return $this->result( 'already_converted', $conversion );
		$snapshot = $this->repository->decode_snapshot( $conversion['conversion_event_ids'] );
		if ( false === $snapshot || empty( $snapshot ) ) return $this->result( 'mapping_missing', $conversion );
		if ( ! $this->events || ! $this->dispatcher || ! $this->registry ) return $this->result( 'runtime_unavailable', $conversion );
		$recovery = $this->ensure_execution_attribution( $conversion );
		if ( 'valid' !== $recovery['status'] ) {
			if ( 'unavailable' === $recovery['status'] && ! $this->record_recovery_failure( $conversion, $snapshot ) ) return $this->result( 'storage_failed', $conversion );
			if ( 'permanent' === $recovery['status'] ) return $this->result( 'incomplete', $conversion, 'permanent_incomplete' );
			return 'unavailable' === $recovery['status'] ? $this->result( 'incomplete', $conversion, 'legacy_recovery_unavailable' ) : $this->result( 'storage_failed', $conversion );
		}
		$conversion = $recovery['conversion'];

		$prepared = $this->prepare_deliveries( $conversion, $snapshot, time() );
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
			$diagnostics = is_array( $result ) && isset( $result['outbound_diagnostics'] ) && is_array( $result['outbound_diagnostics'] ) ? $result['outbound_diagnostics'] : null;
			if ( 'success' === $status ) {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_SUCCEEDED, '', $http, $diagnostics );
			} elseif ( 'terminal' === $status ) {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_BLOCKED, $reason, $http, $diagnostics );
			} else {
				$this->repository->complete_delivery( $claimed['id'], $claimed['lease_token'], EventBridge_Conversion_Repository::DELIVERY_RETRYABLE, $reason, $http, $diagnostics );
			}
		}

		$converted = $this->repository->finalize_if_complete( $conversion['id'], $snapshot );
		$conversion = $this->repository->get_by_id( $conversion['id'] );
		return $this->result( $converted ? 'converted' : 'incomplete', $conversion, $converted ? 'converted' : $this->get_incomplete_feedback_code( $conversion['id'] ) );
	}

	private function prepare_deliveries( array $conversion, array $snapshot, $manual_event_time ) {
		$existing = $this->repository->get_deliveries( $conversion['id'] );
		$identities = array();
		foreach ( $existing as $delivery ) {
			if ( ! isset( $identities[ $delivery['event_key'] ] ) ) $identities[ $delivery['event_key'] ] = array( $delivery['event_id'], absint( $delivery['event_time'] ) );
		}
		$context = $this->get_profile_context( $conversion );
		$prepared = array();
		foreach ( $snapshot as $event_key ) {
			$identity   = isset( $identities[ $event_key ] ) ? $identities[ $event_key ] : array( wp_generate_uuid4(), max( 1, absint( $manual_event_time ) ) );
			if ( ! in_array( $context['attribution_source'], array( 'booking_snapshot', 'legacy_recovery_snapshot' ), true ) ) {
				$prepared[] = array( 'event_key' => $event_key, 'destination_id' => '', 'event_id' => $identity[0], 'event_time' => $identity[1], 'status' => EventBridge_Conversion_Repository::DELIVERY_BLOCKED, 'occurrence' => null, 'error_code' => 'attribution_snapshot_missing' );
				continue;
			}
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
		$selected_touch = isset( $profile_context['selected_touch'] ) && in_array( $profile_context['selected_touch'], array( 'last_touch', 'first_touch' ), true ) ? $profile_context['selected_touch'] : '';
		$touch = '' !== $selected_touch && ! empty( $profile_context[ $selected_touch ] ) ? $profile_context[ $selected_touch ] : ( ! empty( $profile_context['last_touch'] ) ? $profile_context['last_touch'] : $profile_context['first_touch'] );
		$query = is_array( $touch ) ? $touch : array();
		$fluent_snapshot = $this->fluent_booking ? $this->fluent_booking->resolve_by_external_id( $event, $conversion['external_id'] ) : false;
		$fluent_parameters = is_array( $fluent_snapshot ) && $this->fluent_booking ? $this->fluent_booking->get_parameter_data( $event, $fluent_snapshot ) : array();
		$fluent_matching = is_array( $fluent_snapshot ) && $this->fluent_booking ? $this->fluent_booking->get_advanced_matching_values( $event, $fluent_snapshot ) : array();
		$matching_values = $this->events->get_advanced_matching_values( $event, $query, '', $fluent_matching );
		$user_data = $this->events->get_advanced_matching_user_data( $matching_values );
		$event_source_url = isset( $profile_context['event_source_url'] ) ? EventBridge_Meta_URL::canonicalize( $profile_context['event_source_url'] ) : '';
		if ( '' === $event_source_url && isset( $query['landing_url'] ) ) $event_source_url = EventBridge_Meta_URL::canonicalize( $query['landing_url'] );
		return array(
			'event_name'          => (string) $event['event_name'],
			'event_id'            => $identity[0],
			'event_time'          => $identity[1],
			'action_source'       => 'website',
			'event_source_url'    => $event_source_url,
			'custom_data'         => $this->events->get_parameter_map( $event, $this->events->get_query_parameter_values( $event, $query ), $fluent_parameters ),
			'details'             => array( 'event_key' => $event_key, 'event_name' => (string) $event['event_name'], 'event_id' => $identity[0], 'context' => array( 'conversion_id' => absint( $conversion['id'] ), 'trigger' => 'manual_conversion', 'attribution_source' => $profile_context['attribution_source'] ) ),
			'advanced_user_data'  => $user_data,
			'event_configuration' => array( 'capi' => true, 'meta_test_mode' => ! empty( $event['meta_test_mode'] ), 'meta_test_event_code' => isset( $event['meta_test_event_code'] ) ? (string) $event['meta_test_event_code'] : '' ),
			'attribution_context' => array( 'first_touch' => $profile_context['first_touch'], 'last_touch' => $profile_context['last_touch'], 'selected_touch' => $profile_context['selected_touch'] ),
			'browser_context'     => $profile_context['browser_context'],
			'attribution_source'  => $profile_context['attribution_source'],
		);
	}

	private function get_profile_context( array $conversion ) {
		$snapshot = $this->repository->decode_attribution_snapshot( isset( $conversion['attribution_snapshot'] ) ? $conversion['attribution_snapshot'] : '' );
		if ( is_array( $snapshot ) ) {
			$provenance_source = isset( $snapshot['provenance']['source'] ) ? $snapshot['provenance']['source'] : '';
			return array(
				'first_touch'       => $snapshot['first_touch'],
				'last_touch'        => $snapshot['last_touch'],
				'selected_touch'    => $snapshot['selected_touch'],
				'browser_context'   => $snapshot['browser_context'],
				'event_source_url'  => isset( $snapshot['event_source_url'] ) ? $snapshot['event_source_url'] : '',
				'attribution_source'=> 'legacy_recovery' === $provenance_source ? 'legacy_recovery_snapshot' : 'booking_snapshot',
			);
		}
		$profile = $this->profiles ? $this->profiles->get_by_id( $conversion['profile_id'] ) : false;
		$first = is_array( $profile ) && is_string( $profile['first_touch'] ) ? json_decode( $profile['first_touch'], true ) : array();
		$last  = is_array( $profile ) && is_string( $profile['last_touch'] ) ? json_decode( $profile['last_touch'], true ) : array();
		return array(
			'first_touch'       => is_array( $first ) ? $first : array(),
			'last_touch'        => is_array( $last ) ? $last : array(),
			'selected_touch'    => ! empty( $last ) ? 'last_touch' : ( ! empty( $first ) ? 'first_touch' : 'none' ),
			'browser_context'   => $this->contexts ? $this->contexts->get_for_profile( $conversion['profile_id'] ) : array(),
			'event_source_url'  => '',
			'attribution_source'=> 'legacy_live_profile',
		);
	}

	private function ensure_execution_attribution( array $conversion ) {
		$raw = isset( $conversion['attribution_snapshot'] ) && is_string( $conversion['attribution_snapshot'] ) ? $conversion['attribution_snapshot'] : '';
		if ( is_array( $this->repository->decode_attribution_snapshot( $raw ) ) ) return array( 'status' => 'valid', 'conversion' => $conversion );
		if ( '' !== trim( $raw ) || 'fluent_booking' !== $conversion['provider'] || 'booking' !== $conversion['entity_type'] || ! $this->profiles || ! $this->contexts || ! $this->fluent_booking ) return array( 'status' => 'permanent', 'conversion' => $conversion );
		$created_timestamp = $this->parse_context_timestamp( isset( $conversion['created_at'] ) ? $conversion['created_at'] : '', true );
		$profile = $this->profiles->get_by_id( isset( $conversion['profile_id'] ) ? absint( $conversion['profile_id'] ) : 0 );
		$booking = $this->fluent_booking->get_legacy_recovery_context( isset( $conversion['external_id'] ) ? $conversion['external_id'] : '' );
		if ( ! $created_timestamp || ! is_array( $profile ) || ! is_array( $booking ) || 'web' !== ( isset( $booking['source'] ) ? $booking['source'] : '' ) ) return array( 'status' => 'unavailable', 'conversion' => $conversion );

		$first = $this->get_eligible_legacy_touch( isset( $profile['first_touch'] ) ? $profile['first_touch'] : '', $created_timestamp );
		$last  = $this->get_eligible_legacy_touch( isset( $profile['last_touch'] ) ? $profile['last_touch'] : '', $created_timestamp );
		$selected = ! empty( $last ) ? 'last_touch' : ( ! empty( $first ) ? 'first_touch' : 'none' );
		$touch = 'last_touch' === $selected ? $last : ( 'first_touch' === $selected ? $first : array() );
		$touch_timestamp = isset( $touch['captured_at'] ) ? $this->parse_context_timestamp( $touch['captured_at'] ) : false;
		if ( ! $touch_timestamp ) return array( 'status' => 'unavailable', 'conversion' => $conversion );

		$event_source_url = EventBridge_Meta_URL::canonicalize( isset( $booking['source_url'] ) ? $booking['source_url'] : '' );
		if ( '' === $event_source_url && isset( $touch['landing_url'] ) ) $event_source_url = EventBridge_Meta_URL::canonicalize( $touch['landing_url'] );
		$stored_context = $this->contexts->get_for_profile( $conversion['profile_id'] );
		$client_request = isset( $stored_context['client_request'] ) && is_array( $stored_context['client_request'] ) ? $stored_context['client_request'] : array();
		$user_agent = $this->get_eligible_context_value( $client_request, 'user_agent', $touch_timestamp, $created_timestamp );
		if ( '' === $event_source_url || '' === $user_agent || strlen( $user_agent ) > 500 || preg_match( '/[\x00-\x1F\x7F]/', $user_agent ) ) return array( 'status' => 'unavailable', 'conversion' => $conversion );

		$browser_context = array( 'client_request' => array( 'user_agent' => array( 'value' => $user_agent, 'captured_at' => $client_request['user_agent']['captured_at'] ) ) );
		$booking_ip = isset( $booking['ip_address'] ) && is_string( $booking['ip_address'] ) ? trim( $booking['ip_address'] ) : '';
		if ( false !== filter_var( $booking_ip, FILTER_VALIDATE_IP ) ) {
			$browser_context['client_request']['ip_address'] = array( 'value' => $booking_ip, 'captured_at' => $conversion['created_at'] );
		}
		$cookies = isset( $stored_context['browser_cookie'] ) && is_array( $stored_context['browser_cookie'] ) ? $stored_context['browser_cookie'] : array();
		foreach ( array( '_fbc', '_fbp' ) as $key ) {
			$value = $this->get_eligible_context_value( $cookies, $key, 0, $created_timestamp );
			if ( '' !== $value ) $browser_context['browser_cookie'][ $key ] = array( 'value' => $value, 'captured_at' => $cookies[ $key ]['captured_at'] );
		}
		$recovered_at = current_time( 'mysql', true );
		$snapshot = array(
			'version' => 1, 'snapshot_captured_at' => $recovered_at, 'selected_touch' => $selected,
			'first_touch' => $first, 'last_touch' => $last, 'browser_context' => $browser_context, 'event_source_url' => $event_source_url,
			'provenance' => array( 'source' => 'legacy_recovery', 'recovered_at' => $recovered_at, 'conversion_created_at' => $conversion['created_at'] ),
		);
		$stored = $this->repository->fill_missing_attribution_snapshot( $conversion['id'], $snapshot );
		if ( false === $stored ) return array( 'status' => 'storage_failed', 'conversion' => $conversion );
		$updated = $this->repository->get_by_id( $conversion['id'] );
		return is_array( $updated ) && is_array( $this->repository->decode_attribution_snapshot( $updated['attribution_snapshot'] ) ) ? array( 'status' => 'valid', 'conversion' => $updated ) : array( 'status' => 'storage_failed', 'conversion' => $conversion );
	}

	private function get_eligible_legacy_touch( $encoded, $maximum_timestamp ) {
		$touch = is_string( $encoded ) ? json_decode( $encoded, true ) : array();
		$captured = is_array( $touch ) && isset( $touch['captured_at'] ) ? $this->parse_context_timestamp( $touch['captured_at'] ) : false;
		return $captured && $captured <= $maximum_timestamp ? $touch : array();
	}

	private function get_eligible_context_value( array $namespace, $key, $minimum_timestamp, $maximum_timestamp ) {
		$value = isset( $namespace[ $key ]['value'] ) && is_string( $namespace[ $key ]['value'] ) ? trim( $namespace[ $key ]['value'] ) : '';
		$captured = isset( $namespace[ $key ]['captured_at'] ) ? $this->parse_context_timestamp( $namespace[ $key ]['captured_at'], true ) : false;
		return '' !== $value && $captured && $captured >= $minimum_timestamp && $captured <= $maximum_timestamp ? $value : '';
	}

	private function parse_context_timestamp( $value, $assume_utc = false ) {
		if ( ! is_string( $value ) || '' === trim( $value ) ) return false;
		$value = trim( $value );
		if ( $assume_utc && preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value ) ) $value .= ' UTC';
		return strtotime( $value );
	}

	private function record_recovery_failure( array $conversion, array $snapshot ) {
		$prepared = array();
		$existing = $this->repository->get_deliveries( $conversion['id'] );
		$identities = array();
		foreach ( $existing as $delivery ) if ( ! isset( $identities[ $delivery['event_key'] ] ) ) $identities[ $delivery['event_key'] ] = array( $delivery['event_id'], absint( $delivery['event_time'] ) );
		foreach ( $snapshot as $event_key ) {
			$identity = isset( $identities[ $event_key ] ) ? $identities[ $event_key ] : array( wp_generate_uuid4(), time() );
			$prepared[] = array( 'event_key' => $event_key, 'destination_id' => '', 'event_id' => $identity[0], 'event_time' => $identity[1], 'status' => EventBridge_Conversion_Repository::DELIVERY_BLOCKED, 'occurrence' => null, 'error_code' => 'legacy_recovery_unavailable' );
		}
		return $this->repository->reconcile_deliveries( $conversion['id'], $prepared );
	}

	private function get_incomplete_feedback_code( $conversion_id ) {
		foreach ( $this->repository->get_deliveries( $conversion_id ) as $delivery ) {
			if ( EventBridge_Conversion_Repository::DELIVERY_RETRYABLE === $delivery['status'] || EventBridge_Conversion_Repository::DELIVERY_PROCESSING === $delivery['status'] || ( EventBridge_Conversion_Repository::DELIVERY_BLOCKED === $delivery['status'] && 'destination_unavailable' === $delivery['last_error_code'] ) ) return 'retryable_incomplete';
		}
		return 'permanent_incomplete';
	}

	private function build_attribution_snapshot( $profile_id, $client_request_context = false, $event_source_url = '' ) {
		$profile = $profile_id && $this->profiles ? $this->profiles->get_by_id( $profile_id ) : false;
		$first = is_array( $profile ) && is_string( $profile['first_touch'] ) ? json_decode( $profile['first_touch'], true ) : array();
		$last  = is_array( $profile ) && is_string( $profile['last_touch'] ) ? json_decode( $profile['last_touch'], true ) : array();
		$first = is_array( $first ) ? $first : array();
		$last  = is_array( $last ) ? $last : array();
		$browser_context = $profile_id && $this->contexts ? $this->contexts->get_for_profile( $profile_id ) : array();
		unset( $browser_context['client_request'] );
		if ( is_array( $client_request_context ) ) {
			$captured_at = current_time( 'mysql', true );
			$ip_address = isset( $client_request_context['ip_address'] ) && is_string( $client_request_context['ip_address'] ) ? trim( $client_request_context['ip_address'] ) : '';
			$user_agent = isset( $client_request_context['user_agent'] ) && is_string( $client_request_context['user_agent'] ) ? trim( $client_request_context['user_agent'] ) : '';
			if ( false !== filter_var( $ip_address, FILTER_VALIDATE_IP ) ) $browser_context['client_request']['ip_address'] = array( 'value' => $ip_address, 'captured_at' => $captured_at );
			if ( '' !== $user_agent && strlen( $user_agent ) <= 500 && ! preg_match( '/[\x00-\x1F\x7F]/', $user_agent ) ) $browser_context['client_request']['user_agent'] = array( 'value' => $user_agent, 'captured_at' => $captured_at );
		}
		$snapshot = array(
			'version'              => 1,
			'snapshot_captured_at' => current_time( 'mysql', true ),
			'selected_touch'       => ! empty( $last ) ? 'last_touch' : ( ! empty( $first ) ? 'first_touch' : 'none' ),
			'first_touch'          => $first,
			'last_touch'           => $last,
			'browser_context'      => $browser_context,
		);
		$event_source_url = EventBridge_Meta_URL::canonicalize( $event_source_url );
		if ( '' !== $event_source_url ) $snapshot['event_source_url'] = $event_source_url;
		return $snapshot;
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
		if ( ! is_array( $occurrence ) || empty( $occurrence['event_id'] ) || ! wp_is_uuid( $occurrence['event_id'], 4 ) || ! isset( $occurrence['event_time'] ) || ! is_numeric( $occurrence['event_time'] ) || absint( $occurrence['event_time'] ) < 1 ) return false;
		$action_source = isset( $occurrence['action_source'] ) && is_string( $occurrence['action_source'] ) ? $occurrence['action_source'] : 'website';
		if ( 'website' === $action_source && ( empty( $occurrence['event_source_url'] ) || '' === EventBridge_Meta_URL::canonicalize( $occurrence['event_source_url'] ) ) ) return false;
		$is_manual_conversion = isset( $occurrence['details']['context']['trigger'] ) && 'manual_conversion' === $occurrence['details']['context']['trigger'];
		if ( 'website' === $action_source && $is_manual_conversion ) {
			$client_request = isset( $occurrence['browser_context']['client_request'] ) && is_array( $occurrence['browser_context']['client_request'] ) ? $occurrence['browser_context']['client_request'] : array();
			$user_agent = isset( $client_request['user_agent']['value'] ) && is_string( $client_request['user_agent']['value'] ) ? trim( $client_request['user_agent']['value'] ) : '';
			if ( '' === $user_agent || strlen( $user_agent ) > 500 || preg_match( '/[\x00-\x1F\x7F]/', $user_agent ) ) return false;
		}
		if ( in_array( $action_source, array( 'phone_call', 'other' ), true ) && ! empty( $occurrence['event_source_url'] ) ) return false;
		if ( ! in_array( $action_source, array( 'website', 'phone_call', 'other' ), true ) ) return false;
		$user_data = isset( $occurrence['advanced_user_data'] ) && is_array( $occurrence['advanced_user_data'] ) ? $occurrence['advanced_user_data'] : array();
		foreach ( $user_data as $key => $value ) if ( ! in_array( $key, array( 'em', 'ph', 'fn', 'ln' ), true ) || ! is_string( $value ) || ! preg_match( '/^[a-f0-9]{64}$/D', $value ) ) return false;
		foreach ( array( 'email', 'phone', 'first_name', 'last_name', 'full_name' ) as $forbidden ) if ( array_key_exists( $forbidden, $occurrence ) ) return false;
		return true;
	}

	private function result( $code, $conversion = null, $feedback_code = '' ) {
		$code = sanitize_key( $code );
		$allowed_feedback = array( 'converted', 'already_converted', 'retryable_incomplete', 'legacy_recovery_unavailable', 'permanent_incomplete', 'mapping_missing', 'storage_failed', 'runtime_unavailable', 'not_found' );
		$feedback_code = sanitize_key( $feedback_code );
		if ( ! in_array( $feedback_code, $allowed_feedback, true ) ) $feedback_code = in_array( $code, $allowed_feedback, true ) ? $code : 'permanent_incomplete';
		return array( 'code' => $code, 'feedback_code' => $feedback_code, 'conversion' => is_array( $conversion ) ? $conversion : null, 'deliveries' => is_array( $conversion ) && ! empty( $conversion['id'] ) ? $this->repository->get_deliveries( $conversion['id'] ) : array() );
	}
}
