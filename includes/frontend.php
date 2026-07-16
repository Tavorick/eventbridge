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

		$events           = $this->events->get_normalized_events();
		$query_parameters = $this->get_active_fluent_lookup_parameters( $events );
		if ( empty( $query_parameters ) ) {
			return;
		}

		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
		$safe_uri    = remove_query_arg( $query_parameters, $request_uri );
		if ( is_string( $safe_uri ) && '' !== $safe_uri ) {
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
			'0.1.4',
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
		$fluent_privacy_path = $this->get_fluent_privacy_path( $current_url, $normalized_events );

		foreach ( $normalized_events as $event_key => $event ) {
			if ( ! is_string( $event_key ) || ! preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key ) ) {
				continue;
			}

			if ( true !== $event['enabled'] || ! in_array( $event['trigger_type'], array( 'click', 'pageview' ), true ) ) {
				continue;
			}

			$browser  = (bool) $event['browser'];
			$capi     = (bool) $event['capi'];
			$matches_pageview = false;

			if ( 'click' === $event['trigger_type'] ) {
				$selector = is_scalar( $event['selector'] ) ? trim( (string) $event['selector'] ) : '';
				if ( '' === $selector ) {
					continue;
				}
			} else {
				$match_type  = is_scalar( $event['url_match_type'] ) ? (string) $event['url_match_type'] : '';
				$match_value = is_scalar( $event['url_match_value'] ) ? (string) $event['url_match_value'] : '';
				if ( ! in_array( $match_type, array( 'path_exact', 'path_contains', 'url_exact' ), true ) || '' === $match_value ) {
					continue;
				}
				$matches_pageview = $this->matches_current_url( $match_type, $match_value, $current_url );
			}

			$needs_fluent   = $this->fluent_booking->needs_lookup( $event );
			$fluent_snapshot = false;
			if ( $needs_fluent && ( 'click' === $event['trigger_type'] || $matches_pageview ) ) {
				$fluent_snapshot = $this->fluent_booking->resolve( $event, $_GET );
			}
			$fluent_valid = ! $needs_fluent || is_array( $fluent_snapshot );
			$fluent_parameter_values = $fluent_valid ? $this->fluent_booking->get_parameter_data( $event, $fluent_snapshot ) : array();
			$query_parameter_values = $this->events->get_query_parameter_values( $event, $_GET );
			$parameter_map          = $this->events->get_parameter_map( $event, $query_parameter_values, $fluent_parameter_values );
			$browser_parameter_map  = $this->events->get_parameter_map( $event, $query_parameter_values, $browser ? $fluent_parameter_values : array() );
			$capi_available         = $capi && ( ! $this->fluent_booking->is_capi_dependent( $event ) || $fluent_valid );

			if ( ! $browser && ! $capi ) {
				continue;
			}

			$frontend_event = array(
				'id'         => $event_key,
				'label'      => is_scalar( $event['label'] ) ? (string) $event['label'] : '',
				'eventName'  => is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
				'trigger'    => $event['trigger_type'],
				'browser'    => $browser,
				'capi'       => $capi_available,
				'parameters' => (object) $browser_parameter_map,
			);
			if ( $needs_fluent && isset( $event['data_source']['lookup_value'] ) && is_string( $event['data_source']['lookup_value'] ) ) {
				if ( '' !== $fluent_privacy_path ) {
					$frontend_event['fluentPrivacyPath'] = $fluent_privacy_path;
				}
			}

			if ( $this->events->has_query_parameter_sources( $event ) ) {
				$parameter_context = $this->events->create_parameter_context( $event_key, $event, $query_parameter_values );
				if ( '' === $parameter_context ) {
					continue;
				}

				$frontend_event['parameterContext'] = $parameter_context;
			}

			if ( 'click' === $event['trigger_type'] ) {
				$frontend_event['selector'] = $selector;

				if ( $capi_available && $this->events->has_advanced_matching_source( $event, 'query_parameter' ) ) {
					$frontend_event['advancedMatchingContextRequired'] = true;

					if ( '' !== $privacy_url ) {
						$advanced_query_values    = $this->events->get_advanced_matching_values( $event, $_GET, 'query_parameter' );
						$advanced_query_user_data = $this->events->get_advanced_matching_user_data( $advanced_query_values );
						$advanced_context         = $this->events->create_advanced_matching_context( $event_key, $event, $privacy_url, $advanced_query_user_data );

						if ( '' !== $advanced_context ) {
							$frontend_event['advancedMatchingContext'] = $advanced_context;
						}
					}
				}

				if ( $capi_available && $this->fluent_booking->is_capi_dependent( $event ) ) {
					$frontend_event['fluentBookingContextRequired'] = true;
					$fluent_advanced_values = $this->fluent_booking->get_advanced_matching_values( $event, $fluent_snapshot );
					$fluent_user_data       = $this->events->get_advanced_matching_user_data( $fluent_advanced_values );
					$fluent_context         = '' !== $privacy_url ? $this->fluent_booking->create_context( $event_key, $event, $privacy_url, $fluent_parameter_values, $fluent_user_data ) : '';
					if ( '' !== $fluent_context ) {
						$frontend_event['fluentBookingContext'] = $fluent_context;
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

				$requires_direct_capi = $this->events->has_advanced_matching( $event ) || $this->fluent_booking->is_capi_dependent( $event );
				if ( $capi_available && $requires_direct_capi && $matches_pageview ) {
					$fluent_advanced_values = $fluent_valid ? $this->fluent_booking->get_advanced_matching_values( $event, $fluent_snapshot ) : array();
					$advanced_values    = $this->events->get_advanced_matching_values( $event, $_GET, '', $fluent_advanced_values );
					$advanced_user_data = $this->events->get_advanced_matching_user_data( $advanced_values );
					$event_id           = wp_generate_uuid4();
					$details            = array(
						'event_key'  => $event_key,
						'event_name' => $frontend_event['eventName'],
						'event_id'   => $event_id,
						'page_url'   => $privacy_url,
					);

					if ( '' !== $privacy_url && $this->meta_capi->send_custom_event( $frontend_event['eventName'], $event_id, $privacy_url, $parameter_map, $details, $advanced_user_data ) ) {
						$frontend_event['advancedEventId']        = $event_id;
						$frontend_event['advancedSignature']      = $this->events->create_advanced_matching_signature( $event_key, $event_id );
					} elseif ( $this->fluent_booking->is_capi_dependent( $event ) ) {
						$frontend_event['capi'] = false;
					}
				}
			}

			$frontend_events[] = $frontend_event;
		}

		return $frontend_events;
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

		$origin = ( is_ssl() ? 'https' : 'http' ) . '://' . $home_parts['host'];
		if ( isset( $home_parts['port'] ) ) {
			$origin .= ':' . (int) $home_parts['port'];
		}

		$url = esc_url_raw( $origin . '/' . ltrim( $request_uri, '/' ), array( 'http', 'https' ) );
		$parts = wp_parse_url( $url );

		return is_array( $parts ) && ! empty( $parts['host'] ) && 0 === strcasecmp( $parts['host'], $home_parts['host'] ) ? $url : '';
	}

	private function get_privacy_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$privacy_url = $parts['scheme'] . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$privacy_url .= ':' . (int) $parts['port'];
		}

		return $privacy_url . ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/' );
	}

	private function get_fluent_privacy_path( $url, $events ) {
		if ( ! is_string( $url ) || '' === $url || ! is_array( $events ) ) {
			return '';
		}

		$query_parameters = array();
		foreach ( $events as $event ) {
			if ( ! $this->fluent_booking->needs_lookup( $event ) || ! isset( $event['data_source']['lookup_value'] ) || ! is_string( $event['data_source']['lookup_value'] ) || ! preg_match( '/^[A-Za-z0-9_]+$/D', $event['data_source']['lookup_value'] ) ) {
				continue;
			}
			$query_parameters[] = $event['data_source']['lookup_value'];
		}

		if ( empty( $query_parameters ) ) {
			return '';
		}

		$parts = wp_parse_url( remove_query_arg( array_values( array_unique( $query_parameters ) ), $url ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		return $path . ( isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '' );
	}

	private function get_active_fluent_lookup_parameters( $events ) {
		$query_parameters = array();
		foreach ( $events as $event ) {
			if ( $this->fluent_booking->needs_lookup( $event ) && isset( $event['data_source']['lookup_value'] ) && is_string( $event['data_source']['lookup_value'] ) && isset( $_GET[ $event['data_source']['lookup_value'] ] ) ) {
				$query_parameters[] = $event['data_source']['lookup_value'];
			}
		}

		return array_values( array_unique( $query_parameters ) );
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
