<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Frontend {
	private $settings;
	private $events;
	private $meta_capi;

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi ) {
		$this->settings = $settings;
		$this->events   = $events;
		$this->meta_capi = $meta_capi;
	}

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_script' ) );
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
			'0.1.0',
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

		foreach ( $this->events->get_normalized_events() as $event_key => $event ) {
			if ( ! is_string( $event_key ) || ! preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $event_key ) ) {
				continue;
			}

			if ( true !== $event['enabled'] || ! in_array( $event['trigger_type'], array( 'click', 'pageview' ), true ) ) {
				continue;
			}

			$browser  = (bool) $event['browser'];
			$capi     = (bool) $event['capi'];

			if ( ! $browser && ! $capi ) {
				continue;
			}

			$frontend_event = array(
				'id'         => $event_key,
				'label'      => is_scalar( $event['label'] ) ? (string) $event['label'] : '',
				'eventName'  => is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
				'trigger'    => $event['trigger_type'],
				'browser'    => $browser,
				'capi'       => $capi,
				'parameters' => (object) $this->events->get_parameter_map( $event ),
			);

			if ( 'click' === $event['trigger_type'] ) {
				$selector = is_scalar( $event['selector'] ) ? trim( (string) $event['selector'] ) : '';

				if ( '' === $selector ) {
					continue;
				}

				$frontend_event['selector'] = $selector;
			} else {
				$match_type  = is_scalar( $event['url_match_type'] ) ? (string) $event['url_match_type'] : '';
				$match_value = is_scalar( $event['url_match_value'] ) ? (string) $event['url_match_value'] : '';

				if ( ! in_array( $match_type, array( 'path_exact', 'path_contains', 'url_exact' ), true ) || '' === $match_value ) {
					continue;
				}

				$frontend_event['urlMatchType']  = $match_type;
				$frontend_event['urlMatchValue'] = $match_value;

				if ( $capi && $this->events->has_advanced_matching( $event ) && $this->matches_current_url( $match_type, $match_value, $current_url ) ) {
					$advanced_user_data = $this->get_advanced_user_data( $this->events->get_advanced_matching_map( $event ) );
					$event_id           = wp_generate_uuid4();
					$details            = array(
						'event_key'  => $event_key,
						'event_name' => $frontend_event['eventName'],
						'event_id'   => $event_id,
						'page_url'   => $privacy_url,
					);

					if ( '' !== $privacy_url && $this->meta_capi->send_custom_event( $frontend_event['eventName'], $event_id, $privacy_url, $this->events->get_parameter_map( $event ), $details, $advanced_user_data ) ) {
						$frontend_event['advancedEventId']        = $event_id;
						$frontend_event['advancedSignature']      = $this->events->create_advanced_matching_signature( $event_key, $event_id );
						$frontend_event['removeQueryParameters']  = (bool) $event['remove_query_parameters'];
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
		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] );
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

	private function get_advanced_user_data( $mapping ) {
		$user_data = array();
		$meta_keys = array( 'email' => 'em', 'phone' => 'ph', 'first_name' => 'fn', 'last_name' => 'ln' );

		foreach ( $meta_keys as $mapping_key => $meta_key ) {
			$query_key = isset( $mapping[ $mapping_key ] ) ? $mapping[ $mapping_key ] : '';
			if ( '' === $query_key || ! isset( $_GET[ $query_key ] ) || ! is_scalar( $_GET[ $query_key ] ) ) {
				continue;
			}

			$value = trim( wp_unslash( (string) $_GET[ $query_key ] ) );
			if ( '' === $value || strlen( $value ) > 500 || $value !== wp_strip_all_tags( $value ) ) {
				continue;
			}

			if ( 'email' === $mapping_key ) {
				$value = strtolower( sanitize_email( $value ) );
				if ( '' === $value || false === is_email( $value ) ) {
					continue;
				}
			} elseif ( 'phone' === $mapping_key ) {
				$value = preg_replace( '/\D+/', '', $value );
				if ( ! is_string( $value ) || ! preg_match( '/^[1-9][0-9]{6,14}$/D', $value ) ) {
					continue;
				}
			} else {
				$value = sanitize_text_field( $value );
				$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
				if ( '' === $value ) {
					continue;
				}
			}

			$user_data[ $meta_key ] = hash( 'sha256', $value );
		}

		return $user_data;
	}
}
