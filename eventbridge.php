<?php
/**
 * Plugin Name: EventBridge
 * Description: Configure and send marketing events to supported tracking platforms.
 * Version: 0.1.0
 * Text Domain: eventbridge
 */

defined( 'ABSPATH' ) || exit;

class EventBridge_Plugin {
	public function init() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/events.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/frontend.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-pixel.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-capi.php';

		$settings   = new EventBridge_Settings();
		$events     = new EventBridge_Events();
		$frontend   = new EventBridge_Frontend( $settings, $events );
		$meta_pixel = new EventBridge_Meta_Pixel( $settings );
		$meta_capi  = new EventBridge_Meta_CAPI( $settings );

		$frontend->init();
		$meta_pixel->init();
		$meta_capi->init();

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
