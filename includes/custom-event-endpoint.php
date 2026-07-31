<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Custom_Event_Endpoint {
	const AJAX_ACTION  = 'eventbridge_custom_event';
	const NONCE_ACTION = 'eventbridge_custom_event';

	const NONCE_MAX_LENGTH                      = 64;
	const EVENT_KEY_MAX_LENGTH                  = 40;
	const TRIGGER_ID_MAX_LENGTH                 = 40;
	const EVENT_ID_MAX_LENGTH                   = 36;
	const PAGE_URL_MAX_LENGTH                   = 2048;
	const BROWSER_INVOKED_MAX_LENGTH            = 1;
	const BROWSER_METHOD_MAX_LENGTH             = 11;
	const ADVANCED_MATCHING_SIGNATURE_MAX_LENGTH = 64;
	const RATE_LIMIT_PER_MINUTE                 = 10;
	const RATE_LIMIT_PER_HOUR                   = 60;
	const IDEMPOTENCY_WINDOW                    = 600;

	private $events;
	private $meta_capi;
	private $log;
	private $fluent_booking;

	public function __construct( EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi, EventBridge_Log $log, EventBridge_Fluent_Booking $fluent_booking ) {
		$this->events    = $events;
		$this->meta_capi = $meta_capi;
		$this->log       = $log;
		$this->fluent_booking = $fluent_booking;
	}

	public function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( EventBridge_Log::CLEANUP_HOOK, array( $this, 'cleanup_security_transients' ) );
	}

	public function cleanup_security_transients() {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			return;
		}

		$now                = time();
		$rate_prefix        = $wpdb->esc_like( '_transient_eventbridge_rl_' ) . '%';
		$idempotency_prefix = $wpdb->esc_like( '_transient_eventbridge_idempotency_' ) . '%';

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d
				LIMIT 10000",
				$rate_prefix,
				$now
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				WHERE option_name LIKE %s
				AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d
				LIMIT 10000",
				$idempotency_prefix,
				$now
			)
		);
	}

	public function handle_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			$this->reject_without_log( 405 );
		}

		$nonce = $this->get_posted_string( 'nonce', self::NONCE_MAX_LENGTH, true );
		if ( false === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->reject_without_log( 403 );
		}

		$event_key          = $this->get_posted_string( 'event_key', self::EVENT_KEY_MAX_LENGTH, true );
		$trigger_id         = $this->get_posted_string( 'trigger_id', self::TRIGGER_ID_MAX_LENGTH );
		$event_id           = $this->get_posted_string( 'event_id', self::EVENT_ID_MAX_LENGTH, true );
		$page_url           = $this->get_posted_string( 'page_url', self::PAGE_URL_MAX_LENGTH, true );
		$browser_invoked    = $this->get_posted_string( 'browser_invoked', self::BROWSER_INVOKED_MAX_LENGTH );
		$browser_method     = $this->get_posted_string( 'browser_method', self::BROWSER_METHOD_MAX_LENGTH );
		$parameter_context  = $this->get_posted_string( 'parameter_context', EventBridge_Events::PARAMETER_CONTEXT_MAX_LENGTH );
		$advanced_signature = $this->get_posted_string( 'advanced_matching_signature', self::ADVANCED_MATCHING_SIGNATURE_MAX_LENGTH );
		$advanced_context   = $this->get_posted_string( 'advanced_matching_context', EventBridge_Events::ADVANCED_MATCHING_CONTEXT_MAX_LENGTH );
		$fluent_context     = $this->get_posted_string( 'fluent_booking_context', EventBridge_Fluent_Booking::CONTEXT_MAX_LENGTH );

		if ( false === $event_key
			|| false === $event_id
			|| false === $trigger_id
			|| false === $page_url
			|| false === $browser_invoked
			|| false === $browser_method
			|| false === $parameter_context
			|| false === $advanced_signature
			|| false === $advanced_context
			|| false === $fluent_context
			|| ! in_array( $browser_invoked, array( '', '0', '1' ), true )
			|| ( '1' !== $browser_invoked && '' !== $browser_method )
			|| ! $this->events->is_valid_event_key( $event_key )
			|| ! $this->is_valid_event_id( $event_id )
		) {
			$this->reject_without_log( 400 );
		}

		$event_source_url = $this->validate_source_url( $page_url );
		if ( '' === $event_source_url ) {
			$this->reject_without_log( 400 );
		}

		$event = $this->events->get_event( $event_key );

		if ( ! is_array( $event )
			|| true !== $event['enabled']
			|| EventBridge_Triggers::FAMILY_FRONTEND !== $this->events->get_event_family( $event )
		) {
			$this->reject_without_log( 400 );
		}

		$trigger = $this->events->get_trigger( $event_key, $trigger_id );
		if ( ! is_array( $trigger )
			|| ( '' !== $trigger_id && $trigger_id !== $trigger['trigger_id'] )
			|| ! isset( $trigger['provider'], $trigger['trigger_type'] )
			|| 'frontend' !== $trigger['provider']
			|| ! in_array( $trigger['trigger_type'], array( 'click', 'pageview' ), true )
		) {
			$this->reject_without_log( 400 );
		}

		$route = $this->events->get_effective_event( $event, $trigger );
		if ( ! is_array( $route ) || ! $this->has_valid_event_configuration( $route, $event_source_url ) ) {
			$this->reject(
				'invalid_event_configuration',
				array(
					'event_key' => $event_key,
					'trigger_id' => isset( $trigger['trigger_id'] ) ? $trigger['trigger_id'] : '',
					'event_id'  => $event_id,
					'page_url'  => $event_source_url,
				),
				400
			);
		}

		if ( $this->is_rate_limited( $event_key ) ) {
			$this->reject_without_log( 429 );
		}

		$trigger_id = $trigger['trigger_id'];
		$event      = $route;
		$event_name = trim( (string) $event['event_name'] );

		$browser_invoked = '1' === $browser_invoked;
		$capi_enabled    = true === (bool) $event['capi'];
		$expected_browser_method = $this->get_browser_method( $event_name );
		$browser_log_allowed     = $browser_invoked
			&& true === (bool) $event['browser']
			&& $expected_browser_method === $browser_method;

		if ( ( $browser_invoked && ! $browser_log_allowed )
			|| ( ! $browser_invoked && '' !== $browser_method )
			|| ( ! $capi_enabled && ! $browser_log_allowed )
		) {
			$this->reject(
				'invalid_event_configuration',
				array(
					'event_key' => $event_key,
					'event_id'  => $event_id,
					'page_url'  => $event_source_url,
				),
				400
			);
		}

		$query_parameter_values = array();
		if ( $this->events->has_query_parameter_sources( $event ) ) {
			$query_parameter_values = $this->events->verify_parameter_context( $event_key, $event, $parameter_context, $event_source_url );
			if ( false === $query_parameter_values ) {
				$this->reject_semantic( 'invalid_parameter_context', $event_key, $trigger_id, $event_id, $event_source_url );
			}
		} elseif ( '' !== $parameter_context ) {
			$this->reject_semantic( 'unexpected_parameter_context', $event_key, $trigger_id, $event_id, $event_source_url );
		}

		$parameter_map = $this->events->get_parameter_map( $event, $query_parameter_values );

		$capi_already_started = false;
		if ( '' !== $advanced_signature ) {
			if ( 'pageview' !== $event['trigger_type']
				|| ! $this->events->verify_advanced_matching_signature( $event_key, $event_id, $advanced_signature, $event )
			) {
				$this->reject_semantic( 'invalid_advanced_matching_signature', $event_key, $trigger_id, $event_id, $event_source_url );
			}

			$capi_already_started = true;
		}

		$fluent_capi_required    = $this->fluent_booking->is_capi_dependent( $event );
		$fluent_context_data     = false;
		if ( $fluent_capi_required && 'click' === $event['trigger_type'] ) {
			$fluent_context_data = $this->fluent_booking->verify_context( $event_key, $event, $event_source_url, $fluent_context );
			if ( is_array( $fluent_context_data ) ) {
				$parameter_map = array_merge( $parameter_map, $fluent_context_data['custom_data'] );
			}
		} elseif ( '' !== $fluent_context ) {
			$this->reject_semantic( 'unexpected_fluent_context', $event_key, $trigger_id, $event_id, $event_source_url );
		}

		$advanced_user_data   = array();
		$capi_validation_error = '';
		if ( $capi_enabled
			&& ! $capi_already_started
			&& 'click' === $event['trigger_type']
			&& $this->events->has_advanced_matching( $event )
		) {
			$advanced_static_values = $this->events->get_advanced_matching_values( $event, array(), 'static' );
			$advanced_user_data     = $this->events->get_advanced_matching_user_data( $advanced_static_values );

			if ( $this->events->has_advanced_matching_source( $event, 'query_parameter' ) ) {
				$advanced_query_user_data = $this->events->verify_advanced_matching_context( $event_key, $event, $event_source_url, $advanced_context );

				if ( false === $advanced_query_user_data ) {
					if ( empty( $advanced_user_data ) ) {
						$capi_validation_error = 'invalid_advanced_matching_context';
					}
				} else {
					$advanced_user_data = array_merge( $advanced_user_data, $advanced_query_user_data );
				}
			} elseif ( '' !== $advanced_context ) {
				$this->reject_semantic( 'unexpected_advanced_matching_context', $event_key, $trigger_id, $event_id, $event_source_url );
			}
		} elseif ( '' !== $advanced_context ) {
			$this->reject_semantic( 'unexpected_advanced_matching_context', $event_key, $trigger_id, $event_id, $event_source_url );
		}

		if ( is_array( $fluent_context_data ) ) {
			$advanced_user_data = array_merge( $advanced_user_data, $fluent_context_data['user_data'] );
		}

		$capi_can_start = $capi_enabled && ! $capi_already_started;
		if ( $capi_can_start && $fluent_capi_required && ! is_array( $fluent_context_data ) ) {
			$capi_validation_error = 'invalid_fluent_context';
		}
		if ( '' !== $capi_validation_error ) {
			$capi_can_start = false;
		}

		if ( $capi_enabled && ! $capi_already_started && ! $capi_can_start && ! $browser_log_allowed ) {
			$this->reject_semantic( $capi_validation_error, $event_key, $trigger_id, $event_id, $event_source_url );
		}

		if ( ! $this->reserve_event_id( $event_key, $trigger_id, $event_id, ! empty( $event['meta_test_mode'] ), ! empty( $event['eventbridge_is_compatibility_trigger'] ) ) ) {
			$this->reject_without_log( 409 );
		}

		$details = array(
			'event_key'  => $event_key,
			'trigger_id' => $trigger_id,
			'event_name' => $event_name,
			'event_id'   => $event_id,
			'page_url'   => $event_source_url,
		);

		$this->log->log( 'info', 'custom_event_endpoint', 'Custom event endpoint request accepted.', $details );

		if ( $browser_log_allowed ) {
			$browser_details            = $details;
			$browser_details['context'] = array( 'method' => $browser_method );
			$this->log->log( 'info', 'browser', 'Browser event invoked.', $browser_details );
		}

		if ( $capi_can_start ) {
			if ( ! $this->meta_capi->send_custom_event( $event_name, $event_id, $event_source_url, $parameter_map, $details, $advanced_user_data, $event ) ) {
				$this->reject_without_log( 400 );
			}

			wp_send_json_success( array( 'status' => 'started' ) );
		}

		if ( $capi_already_started ) {
			wp_send_json_success( array( 'status' => 'accepted' ) );
		}

		wp_send_json_success( array( 'status' => 'accepted' ) );
	}

	private function get_browser_method( $event_name ) {
		$standard_events = array(
			'AddPaymentInfo',
			'AddToCart',
			'AddToWishlist',
			'CompleteRegistration',
			'Contact',
			'CustomizeProduct',
			'Donate',
			'FindLocation',
			'InitiateCheckout',
			'Lead',
			'Purchase',
			'Schedule',
			'Search',
			'StartTrial',
			'SubmitApplication',
			'Subscribe',
			'ViewContent',
		);

		return in_array( $event_name, $standard_events, true ) ? 'track' : 'trackCustom';
	}

	private function get_posted_string( $key, $maximum_length, $required = false ) {
		if ( ! isset( $_POST[ $key ] ) ) {
			return $required ? false : '';
		}

		if ( ! is_string( $_POST[ $key ] ) ) {
			return false;
		}

		$value = wp_unslash( $_POST[ $key ] );

		if ( strlen( $value ) > $maximum_length ) {
			return false;
		}

		$value = trim( $value );

		if ( $required && '' === $value ) {
			return false;
		}

		return $value;
	}

	private function is_valid_event_id( $event_id ) {
		return is_string( $event_id )
			&& self::EVENT_ID_MAX_LENGTH === strlen( $event_id )
			&& (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_id );
	}

	private function has_valid_event_configuration( $event, $event_source_url ) {
		if ( ! is_array( $event )
			|| ! isset( $event['event_name'], $event['trigger_type'], $event['browser'], $event['capi'] )
			|| ! is_scalar( $event['event_name'] )
			|| ( true !== (bool) $event['browser'] && true !== (bool) $event['capi'] )
		) {
			return false;
		}

		$event_name = trim( (string) $event['event_name'] );
		if ( '' === $event_name
			|| strlen( $event_name ) > EventBridge_Events::EVENT_NAME_MAX_LENGTH
			|| ! preg_match( '/^[A-Za-z0-9_]+$/D', $event_name )
		) {
			return false;
		}

		if ( 'click' === $event['trigger_type'] ) {
			$selector = isset( $event['selector'] ) && is_scalar( $event['selector'] ) ? trim( (string) $event['selector'] ) : '';

			return '' !== $selector
				&& strlen( $selector ) <= EventBridge_Events::SELECTOR_MAX_LENGTH
				&& ! preg_match( '/[\r\n]/', $selector )
				&& $selector === wp_strip_all_tags( $selector );
		}

		if ( 'pageview' !== $event['trigger_type'] ) {
			return false;
		}

		$match_type  = isset( $event['url_match_type'] ) && is_scalar( $event['url_match_type'] ) ? trim( (string) $event['url_match_type'] ) : '';
		$match_value = isset( $event['url_match_value'] ) && is_scalar( $event['url_match_value'] ) ? trim( (string) $event['url_match_value'] ) : '';

		return in_array( $match_type, array( 'path_exact', 'path_contains', 'url_exact' ), true )
			&& '' !== $match_value
			&& strlen( $match_value ) <= EventBridge_Events::URL_MATCH_VALUE_MAX_LENGTH
			&& ! preg_match( '/[\r\n]/', $match_value )
			&& $match_value === wp_strip_all_tags( $match_value )
			&& $this->matches_pageview_route( $match_type, $match_value, $event_source_url );
	}

	private function matches_pageview_route( $match_type, $match_value, $event_source_url ) {
		$path = wp_parse_url( $event_source_url, PHP_URL_PATH );
		$path = is_string( $path ) && '' !== $path ? $path : '/';

		if ( 'path_exact' === $match_type ) {
			return $path === $match_value;
		}

		if ( 'path_contains' === $match_type ) {
			return false !== strpos( $path, $match_value );
		}

		$expected_url = $this->validate_source_url( $match_value );

		return '' !== $expected_url && $event_source_url === $expected_url;
	}

	private function validate_source_url( $url ) {
		return EventBridge_Meta_URL::canonicalize( $url );
	}

	private function is_rate_limited( $event_key ) {
		$remote_address = $this->get_remote_address();
		$minute_key     = $this->get_rate_limit_key( 'minute', $remote_address, $event_key );
		$hour_key       = $this->get_rate_limit_key( 'hour', $remote_address, $event_key );
		$minute_count   = $this->increment_transient_counter( $minute_key, 60 );
		$hour_count     = $this->increment_transient_counter( $hour_key, 3600 );

		return $minute_count > self::RATE_LIMIT_PER_MINUTE || $hour_count > self::RATE_LIMIT_PER_HOUR;
	}

	private function get_remote_address() {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) || ! is_string( $_SERVER['REMOTE_ADDR'] ) ) {
			return 'unavailable';
		}

		$remote_address = trim( $_SERVER['REMOTE_ADDR'] );

		return false !== filter_var( $remote_address, FILTER_VALIDATE_IP ) ? $remote_address : 'unavailable';
	}

	private function get_rate_limit_key( $window, $remote_address, $event_key ) {
		$material = $window . '|' . $remote_address . '|' . $event_key;
		$digest   = hash_hmac( 'sha256', $material, wp_salt( 'auth' ) );

		return 'eventbridge_rl_' . $window . '_' . $digest;
	}

	private function increment_transient_counter( $key, $expiration ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			return PHP_INT_MAX;
		}

		$option_name = '_transient_' . $key;
		$now          = time();
		$expires_at  = $now + $expiration;
		$value       = $expires_at . '|1';

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value =
					IF(
						option_value REGEXP '^[0-9]+[|][0-9]+$',
						IF(
							CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d,
							VALUES(option_value),
							CONCAT(
								SUBSTRING_INDEX(option_value, '|', 1),
								'|',
								LEAST(CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + 1, 2147483647)
							)
						),
						CONCAT(%d, '|2147483647')
					)",
				$option_name,
				$value,
				$now,
				$expires_at,
			)
		);

		if ( false === $result ) {
			return PHP_INT_MAX;
		}

		wp_cache_delete( $option_name, 'options' );
		$stored_value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			)
		);

		if ( ! is_string( $stored_value ) || ! preg_match( '/^[0-9]+\|([0-9]+)$/D', $stored_value, $matches ) ) {
			return PHP_INT_MAX;
		}

		return (int) $matches[1];
	}

	private function reserve_event_id( $event_key, $trigger_id, $event_id, $test_mode, $compatibility_route ) {
		$mode      = $test_mode ? 'test' : 'prod';
		$canonical = 'v2|' . $mode . '|' . $event_key . '|' . $trigger_id . '|' . $event_id;

		if ( ! $this->reserve_idempotency_material( $canonical, $event_id ) ) {
			return false;
		}

		if ( $compatibility_route && ! $this->reserve_idempotency_material( $event_key . '|' . $event_id, $event_id ) ) {
			return false;
		}

		return true;
	}

	private function reserve_idempotency_material( $material, $event_id ) {
		$digest        = hash_hmac( 'sha256', $material, wp_salt( 'auth' ) );
		$transient_key = 'eventbridge_idempotency_' . $digest;

		global $wpdb;
		if ( ! isset( $wpdb->options ) || ! is_string( $wpdb->options ) || '' === $wpdb->options ) {
			return false;
		}

		$option_name = '_transient_' . $transient_key;
		$now         = time();
		$expires_at  = $now + self::IDEMPOTENCY_WINDOW;
		$token       = hash_hmac( 'sha256', $event_id . '|' . microtime( true ), wp_salt( 'nonce' ) );
		$value       = $expires_at . '|' . $token;
		$result      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s
				WHERE option_name = %s
				AND option_value REGEXP '^[0-9]+[|][a-f0-9]{64}$'
				AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d",
				$value,
				$option_name,
				$now
			)
		);

		if ( 1 === $result ) {
			wp_cache_delete( $option_name, 'options' );
			return true;
		}
		if ( false === $result ) {
			return false;
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
				VALUES (%s, %s, 'no')",
				$option_name,
				$value
			)
		);
		wp_cache_delete( $option_name, 'options' );

		return 1 === $result;
	}

	private function reject_semantic( $reason, $event_key, $trigger_id, $event_id, $event_source_url ) {
		$this->reject(
			$reason,
			array(
				'event_key' => $event_key,
				'trigger_id' => $trigger_id,
				'event_id'  => $event_id,
				'page_url'  => $event_source_url,
			),
			400
		);
	}

	private function reject( $reason, $details = array(), $status_code = 400 ) {
		$details            = is_array( $details ) ? $details : array();
		$details['context'] = array( 'reason' => $reason );

		$this->log->log( 'warning', 'custom_event_endpoint', 'Custom event endpoint request rejected.', $details );

		$this->reject_without_log( $status_code );
	}

	private function reject_without_log( $status_code ) {
		wp_send_json_error( array( 'status' => 'rejected' ), $status_code );
	}
}
