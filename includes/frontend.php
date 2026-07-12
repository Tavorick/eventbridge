<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_Frontend {
	private $settings;
	private $events;

	public function __construct( EventBridge_Settings $settings, EventBridge_Events $events ) {
		$this->settings = $settings;
		$this->events   = $events;
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
			}

			$frontend_events[] = $frontend_event;
		}

		return $frontend_events;
	}
}
