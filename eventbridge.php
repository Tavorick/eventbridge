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
		if ( ! is_admin() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';

		$settings = new EventBridge_Settings();
		$admin    = new EventBridge_Admin( $settings );

		$settings->set_admin( $admin );
		$settings->init();
		$admin->init();
	}
}

$eventbridge_plugin = new EventBridge_Plugin();
$eventbridge_plugin->init();
