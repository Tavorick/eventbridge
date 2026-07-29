<?php

class EventBridge_Failing_Log extends EventBridge_Log {
	public function ensure_table() {
		return false;
	}

	public function table_exists() {
		return false;
	}
}

class EventBridge_Upgrader_Test extends WP_UnitTestCase {
	private $log;
	private $status;

	public function set_up() {
		parent::set_up();

		$this->log    = new EventBridge_Log();
		$this->status = new EventBridge_Upgrade_Status();

		delete_option( EventBridge_Installer::DB_VERSION_OPTION );
		delete_option( EventBridge_Upgrade_Status::OPTION_NAME );
		delete_option( EventBridge_Upgrader::LOCK_OPTION );
		delete_option( EventBridge_Upgrader::CRON_CHECK_OPTION );
		delete_option( 'eventbridge_meta_settings' );
		delete_option( 'eventbridge_events' );
		wp_clear_scheduled_hook( EventBridge_Log::CLEANUP_HOOK );
	}

	public function tear_down() {
		$this->log->ensure_table();
		wp_clear_scheduled_hook( EventBridge_Log::CLEANUP_HOOK );
		parent::tear_down();
	}

	public function test_legacy_install_is_upgraded_without_rewriting_data() {
		$settings = array( 'pixel_id' => '123', 'capi_token' => 'keep-me', 'debug' => false );
		$events   = array( 'existing-key' => array( 'event_name' => 'PageView' ) );
		add_option( 'eventbridge_meta_settings', $settings, '', false );
		add_option( 'eventbridge_events', $events, '', false );

		$this->make_upgrader()->run();

		$this->assertSame( 1, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
		$this->assertSame( $settings, get_option( 'eventbridge_meta_settings' ) );
		$this->assertSame( $events, get_option( 'eventbridge_events' ) );
		$this->assertTrue( $this->log->verify_table_schema() );
	}

	public function test_plugin_110_keeps_database_version_one() {
		$this->assertSame( '1.1.0', EVENTBRIDGE_VERSION );
		$this->assertSame( 1, EVENTBRIDGE_DB_VERSION );
	}

	public function test_valid_lock_prevents_concurrent_upgrade() {
		add_option( 'eventbridge_events', array( 'legacy' => array() ), '', false );
		add_option(
			EventBridge_Upgrader::LOCK_OPTION,
			( time() + EventBridge_Upgrader::LOCK_TTL ) . '|' . str_repeat( 'a', 64 ),
			'',
			false
		);

		$this->make_upgrader()->run();

		$this->assertFalse( get_option( EventBridge_Installer::DB_VERSION_OPTION, false ) );
	}

	public function test_stale_lock_is_taken_over_and_removed() {
		add_option( 'eventbridge_events', array( 'legacy' => array() ), '', false );
		add_option( EventBridge_Upgrader::LOCK_OPTION, ( time() - 1 ) . '|' . str_repeat( 'b', 64 ), '', false );

		$this->make_upgrader()->run();

		$this->assertSame( 1, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( EventBridge_Upgrader::LOCK_OPTION, false ) );
	}

	public function test_failure_does_not_advance_version_and_sets_backoff() {
		$failing_log = new EventBridge_Failing_Log();
		$installer   = new EventBridge_Installer( $failing_log, $this->status );
		$upgrader    = new EventBridge_Upgrader( $failing_log, $installer, $this->status );

		$upgrader->run();
		$status = $this->status->get();

		$this->assertFalse( get_option( EventBridge_Installer::DB_VERSION_OPTION, false ) );
		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'log_table_unavailable', $status['error_code'] );
		$this->assertGreaterThan( time(), $status['next_retry_at'] );
	}

	public function test_correct_cleanup_schedule_is_not_duplicated() {
		add_option( EventBridge_Installer::DB_VERSION_OPTION, 1, '', false );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', EventBridge_Log::CLEANUP_HOOK );
		$timestamp = wp_next_scheduled( EventBridge_Log::CLEANUP_HOOK );

		$this->make_upgrader()->run();

		$this->assertSame( $timestamp, wp_next_scheduled( EventBridge_Log::CLEANUP_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( EventBridge_Log::CLEANUP_HOOK ) );
	}

	private function make_upgrader() {
		$installer = new EventBridge_Installer( $this->log, $this->status );

		return new EventBridge_Upgrader( $this->log, $installer, $this->status );
	}
}
