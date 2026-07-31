<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Frontend {
	private $settings;
	private $events;
	private $meta_capi;
	private $fluent_booking;
	private $original_request_uri = '';

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi, EventBridge_Fluent_Booking $fluent_booking ) {
		$this->settings = $settings;
		$this->events   = $events;
		$this->meta_capi = $meta_capi;
		$this->fluent_booking = $fluent_booking;
	}

	public function init() {
		add_action( 'template_redirect', array( $this, 'protect_fluent_lookup_request_url' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
	}

	public function protect_fluent_lookup_request_url() {
		if ( $this->should_skip_request() || ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$query_parameters = $this->events->get_active_fluent_lookup_parameters();
		if ( empty( $query_parameters ) ) {
			return;
		}

		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		$safe_uri    = $this->remove_query_parameters_from_url( $request_uri, $query_parameters );
		if ( is_string( $safe_uri ) && '' !== $safe_uri && $safe_uri !== $request_uri ) {
			$this->original_request_uri = $request_uri;
			$_SERVER['REQUEST_URI']      = $safe_uri;
		}
	}

	public function enqueue_script() {
		if ( $this->should_skip_request() ) {
			return;
		}

		$settings = $this->settings->get_settings();
		$debug    = isset( $settings['debug'] ) && true === (bool) $settings['debug'];
		$events   = $this->get_frontend_events();

		if ( ! $debug && empty( $events ) ) {
			return;
		}

		$configuration = array(
			'debug'       => $debug,
			'events'      => $events,
			'endpointUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'eventbridge_custom_event' ),
		);
		$encoded_configuration = wp_json_encode( $configuration );

		if ( ! is_string( $encoded_configuration ) ) {
			return;
		}

		$handle = 'eventbridge';

		wp_enqueue_script(
			$handle,
			plugins_url( 'assets/js/eventbridge.js', dirname( __FILE__ ) ),
			array(),
			EVENTBRIDGE_VERSION,
			true
		);
		wp_add_inline_script( $handle, 'window.EventBridge = ' . $encoded_configuration . ';', 'before' );
	}

	private function should_skip_request() {
		return is_admin()
			|| wp_doing_cron()
			|| wp_doing_ajax()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] )
			|| is_feed()
			|| is_trackback()
			|| is_robots()
			|| ( function_exists( 'is_favicon' ) && is_favicon() );
	}

	private function get_frontend_events() {
		$frontend_events = array();
		$current_url      = $this->get_current_url();
		$privacy_url      = $this->get_privacy_url( $current_url );
		$normalized_events = $this->events->get_normalized_events();
		$fluent_privacy_path = $this->get_fluent_privacy_path( $current_url );
		$original_query   = is_array( $_GET ) ? $_GET : array();
		$tracking_query   = $this->events->get_tracking_query( $original_query );

		foreach ( $normalized_events as $event_key => $event ) {
			if ( ! is_string( $event_key )
				|| ! preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key )
				|| true !== $event['enabled']
			) {
				continue;
			}

			foreach ( $event['triggers'] as $trigger ) {
				if ( ! is_array( $trigger )
					|| ! isset( $trigger['provider'], $trigger['trigger_type'] )
					|| 'frontend' !== $trigger['provider']
					|| ! in_array( $trigger['trigger_type'], array( 'click', 'pageview' ), true )
				) {
					continue;
				}
				$route = $this->events->get_effective_event( $event, $trigger );
				if ( ! in_array( $route['trigger_type'], array( 'click', 'pageview' ), true )
					|| empty( $route['trigger_id'] )
					|| ! $this->events->is_valid_trigger_id( $route['trigger_id'] )
				) {
					continue;
				}

				$browser          = (bool) $route['browser'];
				$capi             = (bool) $route['capi'];
				$matches_pageview = false;

				if ( 'click' === $route['trigger_type'] ) {
					$selector = is_scalar( $route['selector'] ) ? trim( (string) $route['selector'] ) : '';
					if ( '' === $selector ) {
						continue;
					}
				} else {
					$match_type  = is_scalar( $route['url_match_type'] ) ? (string) $route['url_match_type'] : '';
					$match_value = is_scalar( $route['url_match_value'] ) ? (string) $route['url_match_value'] : '';
					if ( ! in_array( $match_type, array( 'path_exact', 'path_contains', 'url_exact' ), true ) || '' === $match_value ) {
						continue;
					}
					$matches_pageview = $this->matches_current_url( $match_type, $match_value, $current_url );
				}

				$needs_fluent = $this->fluent_booking->needs_lookup( $route );
				$fluent_snapshot = false;
				if ( $needs_fluent && ( 'click' === $route['trigger_type'] || $matches_pageview ) ) {
					$fluent_snapshot = $this->fluent_booking->resolve( $route, $original_query );
				}
				$fluent_valid            = ! $needs_fluent || is_array( $fluent_snapshot );
				$fluent_parameter_values = $fluent_valid ? $this->fluent_booking->get_parameter_data( $route, $fluent_snapshot ) : array();
				$query_parameter_values  = $this->events->get_query_parameter_values( $route, $tracking_query );
				$parameter_map           = $this->events->get_parameter_map( $route, $query_parameter_values, $fluent_parameter_values );
				$browser_parameter_map   = $this->events->get_parameter_map( $route, $query_parameter_values, $browser ? $fluent_parameter_values : array() );
				$capi_available          = $capi && ( ! $this->fluent_booking->is_capi_dependent( $route ) || $fluent_valid );

				if ( ! $browser && ! $capi ) {
					continue;
				}

				$frontend_event = array(
					'id'         => $event_key . '|' . $route['trigger_id'],
					'eventKey'   => $event_key,
					'triggerId'  => $route['trigger_id'],
					'label'      => is_scalar( $event['label'] ) ? (string) $event['label'] : '',
					'eventName'  => is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
					'trigger'    => $route['trigger_type'],
					'browser'    => $browser,
					'capi'       => $capi_available,
					'parameters' => (object) $browser_parameter_map,
				);
				if ( $needs_fluent && '' !== $fluent_privacy_path ) {
					$frontend_event['fluentPrivacyPath'] = $fluent_privacy_path;
				}

				if ( $this->events->has_query_parameter_sources( $route ) ) {
					$parameter_context = $this->events->create_parameter_context( $event_key, $route, $query_parameter_values, $privacy_url );
					if ( '' === $parameter_context ) {
						continue;
					}
					$frontend_event['parameterContext'] = $parameter_context;
				}

				if ( 'click' === $route['trigger_type'] ) {
					$frontend_event['selector'] = $selector;
					if ( $capi_available && $this->events->has_advanced_matching_source( $route, 'query_parameter' ) ) {
						$frontend_event['advancedMatchingContextRequired'] = true;
						if ( '' !== $privacy_url ) {
							$values  = $this->events->get_advanced_matching_values( $route, $tracking_query, 'query_parameter' );
							$user    = $this->events->get_advanced_matching_user_data( $values );
							$context = $this->events->create_advanced_matching_context( $event_key, $route, $privacy_url, $user );
							if ( '' !== $context ) {
								$frontend_event['advancedMatchingContext'] = $context;
							}
						}
					}
					if ( $capi_available && $this->fluent_booking->is_capi_dependent( $route ) ) {
						$frontend_event['fluentBookingContextRequired'] = true;
						$values  = $this->fluent_booking->get_advanced_matching_values( $route, $fluent_snapshot );
						$user    = $this->get_fluent_advanced_matching_user_data( $values );
						$context = '' !== $privacy_url ? $this->fluent_booking->create_context( $event_key, $route, $privacy_url, $fluent_parameter_values, $user ) : '';
						if ( '' !== $context ) {
							$frontend_event['fluentBookingContext'] = $context;
						} else {
							$frontend_event['capi'] = false;
						}
					}
				} else {
					$frontend_event['urlMatchType']  = $match_type;
					$frontend_event['urlMatchValue'] = $match_value;
					if ( $needs_fluent ) {
						$frontend_event['serverUrlMatched'] = $matches_pageview;
					}

					$requires_direct_capi = $this->events->has_advanced_matching( $route ) || $this->fluent_booking->is_capi_dependent( $route );
					if ( $capi_available && $requires_direct_capi && $matches_pageview ) {
						$fluent_values = $fluent_valid ? $this->fluent_booking->get_advanced_matching_values( $route, $fluent_snapshot ) : array();
						$values        = $this->events->get_advanced_matching_values( $route, $tracking_query, '', $fluent_values );
						$user          = $this->get_fluent_advanced_matching_user_data( $values );
						$event_id      = wp_generate_uuid4();
						$details       = array(
							'event_key'  => $event_key,
							'trigger_id' => $route['trigger_id'],
							'event_name' => $frontend_event['eventName'],
							'event_id'   => $event_id,
							'page_url'   => $privacy_url,
						);
						if ( '' !== $privacy_url && $this->meta_capi->send_custom_event( $frontend_event['eventName'], $event_id, $privacy_url, $parameter_map, $details, $user, $route ) ) {
							$frontend_event['advancedEventId']   = $event_id;
							$frontend_event['advancedSignature'] = $this->events->create_advanced_matching_signature( $event_key, $event_id, $route );
						} elseif ( $this->fluent_booking->is_capi_dependent( $route ) ) {
							$frontend_event['capi'] = false;
						}
					}
				}

				$frontend_events[] = $frontend_event;
			}
		}

		return $frontend_events;
	}

	private function get_fluent_advanced_matching_user_data( $values ) {
		$normalized_values = $this->events->get_normalized_advanced_matching_values( $values );

		return $this->events->get_advanced_matching_user_data_from_normalized_values( $normalized_values );
	}

	private function get_current_url() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		$request_uri = '' !== $this->original_request_uri ? $this->original_request_uri : wp_unslash( $_SERVER['REQUEST_URI'] );
		if ( ! is_array( $home_parts ) || empty( $home_parts['host'] ) || '' === $request_uri ) {
			return '';
		}

		if ( empty( $home_parts['scheme'] ) || ! in_array( strtolower( $home_parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		$origin = strtolower( $home_parts['scheme'] ) . '://' . $home_parts['host'];
		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . (int) $home_parts['port'];
		}

		$url = esc_url_raw( $origin . '/' . ltrim( $request_uri, '/' ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );

		return is_array( $parts ) && ! empty( $parts['host'] ) && 0 === strcasecmp( $parts['host'], $home_parts['host'] ) ? $url : '';
	}

	private function get_privacy_url( $url ) {
		return EventBridge_Meta_URL::canonicalize( $url );
	}

	private function get_fluent_privacy_path( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		if ( empty( $this->events->get_active_fluent_lookup_parameters() ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		return isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
	}

	private function remove_query_parameters_from_url( $url, $parameter_names ) {
		if ( ! is_string( $url ) || ! is_array( $parameter_names ) || empty( $parameter_names ) ) {
			return $url;
		}

		$fragment = '';
		$hash_at  = strpos( $url, '#' );
		if ( false !== $hash_at ) {
			$fragment = substr( $url, $hash_at );
			$url      = substr( $url, 0, $hash_at );
		}

		$query_at = strpos( $url, '?' );
		if ( false === $query_at ) {
			return $url . $fragment;
		}

		$base_url    = substr( $url, 0, $query_at );
		$query       = substr( $url, $query_at + 1 );
		$safe_fields = array();
		$removed     = false;

		foreach ( explode( '&', $query ) as $field ) {
			$name         = explode( '=', $field, 2 )[0];
			$decoded_name = rawurldecode( str_replace( '+', ' ', $name ) );

			if ( in_array( $decoded_name, $parameter_names, true ) ) {
				$removed = true;
				continue;
			}

			$safe_fields[] = $field;
		}

		if ( ! $removed ) {
			return $url . $fragment;
		}

		return $base_url . ( empty( $safe_fields ) ? '' : '?' . implode( '&', $safe_fields ) ) . $fragment;
	}

	private function matches_current_url( $match_type, $match_value, $current_url ) {
		$path = wp_parse_url( $current_url, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		if ( 'path_exact' === $match_type ) {
			return $path === $match_value;
		}

		if ( 'path_contains' === $match_type ) {
			return false !== strpos( $path, $match_value );
		}

		return 'url_exact' === $match_type && $current_url === $match_value;
	}

}
