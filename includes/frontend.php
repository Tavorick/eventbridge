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
			'debug'  => $debug,
			'events' => $events,
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

			if ( true !== $event['enabled'] || 'click' !== $event['trigger_type'] || ! is_scalar( $event['selector'] ) ) {
				continue;
			}

			$selector = trim( (string) $event['selector'] );
			$browser  = (bool) $event['browser'];
			$capi     = (bool) $event['capi'];

			if ( '' === $selector || ( ! $browser && ! $capi ) ) {
				continue;
			}

			$frontend_events[] = array(
				'id'        => $event_key,
				'label'     => is_scalar( $event['label'] ) ? (string) $event['label'] : '',
				'eventName' => is_scalar( $event['event_name'] ) ? (string) $event['event_name'] : '',
				'trigger'   => 'click',
				'selector'  => $selector,
				'browser'   => $browser,
				'capi'      => $capi,
			);
		}

		return $frontend_events;
	}
}
