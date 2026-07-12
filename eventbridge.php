<?php
/**
 * Plugin Name: EventBridge
 * Description: Configure and send marketing events to supported tracking platforms.
 * Version: 0.1.0
 * Text Domain: eventbridge
 */

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( __FILE__ ) . 'includes/log.php';

function eventbridge_activate() {
	$log = new EventBridge_Log();
	$log->activate();
}

function eventbridge_deactivate() {
	$log = new EventBridge_Log();
	$log->unschedule_cleanup();
}

register_activation_hook( __FILE__, 'eventbridge_activate' );
register_deactivation_hook( __FILE__, 'eventbridge_deactivate' );

$eventbridge_log = new EventBridge_Log();
$eventbridge_log->init();

class EventBridge_Plugin {
	public function init() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/events.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/frontend.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-pixel.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-capi.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/custom-event-endpoint.php';

		$settings   = new EventBridge_Settings();
		$events     = new EventBridge_Events();
		$frontend   = new EventBridge_Frontend( $settings, $events );
		$meta_pixel = new EventBridge_Meta_Pixel( $settings );
		$meta_capi  = new EventBridge_Meta_CAPI( $settings );
		$custom_event_endpoint = new EventBridge_Custom_Event_Endpoint( $events, $meta_capi );

		$frontend->init();
		$meta_pixel->init();
		$meta_capi->init();
		$custom_event_endpoint->init();

		if ( ! is_admin() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';

		$admin = new EventBridge_Admin( $settings, $events );

		$settings->set_admin( $admin );
		$settings->init();
		$admin->init();
	}
}

$eventbridge_plugin = new EventBridge_Plugin();
$eventbridge_plugin->init();
