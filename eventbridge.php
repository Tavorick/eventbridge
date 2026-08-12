<?php
/**
 * Plugin Name: EventBridge
 * Description: Configure and send marketing events to supported tracking platforms.
 * Version: 1.3.1
 * Author: Lars
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Tested up to: 7.0
 * Update URI: https://github.com/Tavorick/eventbridge
 * Text Domain: eventbridge
 */

defined( 'ABSPATH' ) || exit;

define( 'EVENTBRIDGE_VERSION', '1.3.1' );
define( 'EVENTBRIDGE_DB_VERSION', 3 );
define( 'EVENTBRIDGE_GRAPH_API_VERSION', 'v25.0' );
define( 'EVENTBRIDGE_PLUGIN_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/log.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/plugin-updater.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/upgrade-status.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/triggers.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/installer.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/upgrader.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/profile-token.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/profile-repository.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/profile-service.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/profile-cleanup.php';

$eventbridge_plugin_updater = new EventBridge_Plugin_Updater();
$eventbridge_plugin_updater->register_hooks();

$eventbridge_log       = new EventBridge_Log();
$eventbridge_status    = new EventBridge_Upgrade_Status();
$eventbridge_installer = new EventBridge_Installer( $eventbridge_log, $eventbridge_status );
$eventbridge_upgrader  = new EventBridge_Upgrader( $eventbridge_log, $eventbridge_installer, $eventbridge_status );

register_activation_hook( __FILE__, array( $eventbridge_installer, 'activate' ) );
register_deactivation_hook( __FILE__, array( $eventbridge_log, 'unschedule_cleanup' ) );
register_deactivation_hook( __FILE__, function() {
	( new EventBridge_Profile_Cleanup( new EventBridge_Profile_Repository() ) )->unschedule();
} );

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
		require_once plugin_dir_path( __FILE__ ) . 'includes/condition-provider.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/conditions.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/woocommerce-conditions.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/events.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-url.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/fluent-booking.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/fluent-booking-attribution.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/frontend.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-pixel.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/meta-capi.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/destination-interface.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/destination-registry.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/dispatcher.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/destinations/meta-destination.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/woocommerce.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/woocommerce-interactions.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/custom-event-endpoint.php';

		$settings   = new EventBridge_Settings();
		$fluent_booking = new EventBridge_Fluent_Booking();
		$profile_tokens = new EventBridge_Profile_Token();
		$profile_repository = new EventBridge_Profile_Repository();
		$profile_service = new EventBridge_Profile_Service( $profile_tokens, $profile_repository );
		$profile_cleanup = new EventBridge_Profile_Cleanup( $profile_repository );
		$fluent_booking_attribution = new EventBridge_Fluent_Booking_Attribution( $profile_service );
		$meta_pixel = new EventBridge_Meta_Pixel( $settings );
		$meta_capi  = new EventBridge_Meta_CAPI( $settings, $this->log );
		$destination_registry = new EventBridge_Destination_Registry();
		$destination_registry->register( new EventBridge_Meta_Destination( $meta_capi ) );
		$dispatcher = new EventBridge_Dispatcher( $destination_registry );
		$woocommerce_condition_provider = new EventBridge_WooCommerce_Conditions();
		$conditions = new EventBridge_Conditions( array( $woocommerce_condition_provider ), $settings, $this->log );
		$woocommerce = new EventBridge_WooCommerce( $dispatcher, $this->log, $conditions );
		$events     = new EventBridge_Events( $woocommerce, $conditions );
		$woocommerce->set_events( $events );
		$woocommerce_interactions = new EventBridge_WooCommerce_Interactions( $events, $dispatcher, $this->log, $conditions, $fluent_booking );
		$frontend   = new EventBridge_Frontend( $settings, $events, $dispatcher, $fluent_booking, $woocommerce_interactions );
		$custom_event_endpoint = new EventBridge_Custom_Event_Endpoint( $events, $dispatcher, $this->log, $fluent_booking );

		$woocommerce->init();
		$profile_service->init();
		$profile_cleanup->init();
		$fluent_booking_attribution->init();
		$woocommerce_interactions->init();
		$frontend->init();
		$meta_pixel->init();
		$custom_event_endpoint->init();

		if ( ! is_admin() ) {
			return;
		}

		require_once plugin_dir_path( __FILE__ ) . 'includes/admin.php';

		$admin = new EventBridge_Admin( $settings, $events, $this->log, $fluent_booking, $this->status, $woocommerce, $conditions );

		$settings->set_admin( $admin );
		$settings->init();
		$this->status->init_admin();
		$admin->init();
	}
}

$eventbridge_plugin = new EventBridge_Plugin( $eventbridge_log, $eventbridge_upgrader, $eventbridge_status );
add_action( 'plugins_loaded', array( $eventbridge_plugin, 'init' ), 5 );
