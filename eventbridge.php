<?php
/**
 * Plugin Name: EventBridge
 * Description: Configure and send marketing events to supported tracking platforms.
 * Version: 1.0.1
 * Update URI: false
 * Text Domain: eventbridge
 */

defined( 'ABSPATH' ) || exit;

define( 'EVENTBRIDGE_VERSION', '1.0.1' );
define( 'EVENTBRIDGE_DB_VERSION', 1 );
define( 'EVENTBRIDGE_GRAPH_API_VERSION', 'v25.0' );

require_once plugin_dir_path( __FILE__ ) . 'includes/log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/upgrade-status.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/installer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/upgrader.php';

$eventbridge_log       = new EventBridge_Log();
$eventbridge_status    = new EventBridge_Upgrade_Status();
$eventbridge_installer = new EventBridge_Installer( $eventbridge_log, $eventbridge_status );
$eventbridge_upgrader  = new EventBridge_Upgrader( $eventbridge_log, $eventbridge_installer, $eventbridge_status );

register_activation_hook( __FILE__, array( $eventbridge_installer, 'activate' ) );
register_deactivation_hook( __FILE__, array( $eventbridge_log, 'unschedule_cleanup' ) );

class EventBridge_Plugin {
	private $log;
	private $upgrader;
	private $status;

	public function __construct( EventBridge_Log $log, EventBridge_Upgrader $upgrader, EventBridge_Upgrade_Status $status ) {
		$this->log      = $log;
		$this->upgrader = $upgrader;
		$this->status   = $status;
	}

	public function init() {
		$this->log->init();
		$this->upgrader->run();

		require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/events.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-url.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/fluent-booking.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/frontend.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-pixel.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-capi.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/custom-event-endpoint.php';

		$settings   = new EventBridge_Settings();
		$events     = new EventBridge_Events();
		$fluent_booking = new EventBridge_Fluent_Booking();
		$meta_pixel = new EventBridge_Meta_Pixel( $settings );
		$meta_capi  = new EventBridge_Meta_CAPI( $settings, $this->log );
		$frontend   = new EventBridge_Frontend( $settings, $events, $meta_capi, $fluent_booking );
		$custom_event_endpoint = new EventBridge_Custom_Event_Endpoint( $events, $meta_capi, $this->log, $fluent_booking );

		$frontend->init();
		$meta_pixel->init();
		$custom_event_endpoint->init();

		if ( ! is_admin() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';

		$admin = new EventBridge_Admin( $settings, $events, $this->log, $fluent_booking, $this->status );

		$settings->set_admin( $admin );
		$settings->init();
		$this->status->init_admin();
		$admin->init();
	}
}

$eventbridge_plugin = new EventBridge_Plugin( $eventbridge_log, $eventbridge_upgrader, $eventbridge_status );
add_action( 'plugins_loaded', array( $eventbridge_plugin, 'init' ), 5 );
