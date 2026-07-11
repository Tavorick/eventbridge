<?php
/**
 * Plugin Name: EventBridge
 * Description: Configure and send marketing events to supported tracking platforms.
 * Version: 0.1.0
 * Text Domain: eventbridge
 */

defined( 'ABSPATH' ) || exit;

if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';
}
