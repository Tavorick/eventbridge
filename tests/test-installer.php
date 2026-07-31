<?php

class EventBridge_Installer_Test extends WP_UnitTestCase {
	private $log;
	private $status;
	private $installer;

	public function set_up() {
		parent::set_up();

		$this->log       = new EventBridge_Log();
		$this->status    = new EventBridge_Upgrade_Status();
		$this->installer = new EventBridge_Installer( $this->log, $this->status );

		delete_option( EventBridge_Installer::DB_VERSION_OPTION );
		delete_option( EventBridge_Upgrade_Status::OPTION_NAME );
		delete_option( 'eventbridge_meta_settings' );
		delete_option( 'eventbridge_events' );
		wp_clear_scheduled_hook( EventBridge_Log::CLEANUP_HOOK );
	}

	public function tear_down() {
		$this->log->ensure_table();
		wp_clear_scheduled_hook( EventBridge_Log::CLEANUP_HOOK );
		parent::tear_down();
	}

	public function test_fresh_install_creates_infrastructure() {
		$result = $this->installer->install();

		$this->assertTrue( $result['success'] );
		$this->assertTrue( $this->log->verify_table_schema() );
		$this->assertSame( 2, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
		$this->assertSame( array(), get_option( 'eventbridge_events' ) );
		$this->assertSame( 'daily', wp_get_schedule( EventBridge_Log::CLEANUP_HOOK ) );
	}

	public function test_install_is_idempotent_and_does_not_overwrite_options() {
		$settings = array( 'pixel_id' => '123', 'capi_token' => 'secret', 'debug' => true );
		$events   = array( 'stable-key' => array( 'event_name' => 'Lead' ) );
		add_option( 'eventbridge_meta_settings', $settings, '', false );
		add_option( 'eventbridge_events', $events, '', false );

		$this->installer->install();
		$this->installer->install();

		$this->assertSame( $settings, get_option( 'eventbridge_meta_settings' ) );
		$this->assertSame( $events, get_option( 'eventbridge_events' ) );
		$this->assertSame( 1, $this->count_cleanup_events() );
	}

	private function count_cleanup_events() {
		$count = 0;
		foreach ( (array) _get_cron_array() as $hooks ) {
			if ( isset( $hooks[ EventBridge_Log::CLEANUP_HOOK ] ) ) {
				$count += count( $hooks[ EventBridge_Log::CLEANUP_HOOK ] );
			}
		}

		return $count;
	}
}
