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
		delete_option( EventBridge_Upgrader::CONFIG_STATE_OPTION );
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

		$this->assertSame( 2, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
		$this->assertSame( $settings, get_option( 'eventbridge_meta_settings' ) );
		$this->assertSame( $events, get_option( 'eventbridge_events' ) );
		$this->assertTrue( $this->log->verify_table_schema() );
	}

	public function test_plugin_131_uses_database_version_two() {
		$this->assertSame( '1.3.1', EVENTBRIDGE_VERSION );
		$this->assertSame( 2, EVENTBRIDGE_DB_VERSION );
	}

	public function test_steady_state_does_not_write_or_take_the_upgrade_lock() {
		$events = array();
		add_option( EventBridge_Installer::DB_VERSION_OPTION, EVENTBRIDGE_DB_VERSION, '', false );
		add_option( 'eventbridge_events', $events, '', false );
		add_option( EventBridge_Upgrader::CRON_CHECK_OPTION, time(), '', false );
		EventBridge_Upgrader::store_event_schema_state( $events );
		$writes = array();
		$filter = function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		add_filter( 'query', $filter );
		$this->make_upgrader()->run();
		remove_filter( 'query', $filter );

		$this->assertSame( array(), $writes );
		$this->assertFalse( get_option( EventBridge_Upgrader::LOCK_OPTION, false ) );
	}

	public function test_explicit_recovery_marker_reconciles_once_and_clears_marker() {
		$events = array();
		add_option( EventBridge_Installer::DB_VERSION_OPTION, EVENTBRIDGE_DB_VERSION, '', false );
		add_option( 'eventbridge_events', $events, '', false );
		add_option( EventBridge_Upgrader::CRON_CHECK_OPTION, time(), '', false );
		EventBridge_Upgrader::store_event_schema_state( $events, true );

		$this->make_upgrader()->run();

		$state = get_option( EventBridge_Upgrader::CONFIG_STATE_OPTION );
		$this->assertFalse( $state['reconcile_required'] );
		$this->assertFalse( get_option( EventBridge_Upgrader::LOCK_OPTION, false ) );
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

		$this->assertSame( 2, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
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

	public function test_version_one_event_is_migrated_additively_to_one_trigger() {
		$event_key = 'evt_55555555-5555-4555-8555-555555555555';
		$legacy    = array(
			'label'        => 'Legacy click',
			'event_name'   => 'Lead',
			'trigger_type' => 'click',
			'selector'     => '.legacy',
			'browser'      => true,
			'capi'         => false,
			'enabled'      => true,
			'unknown'      => array( 'preserved' => true ),
		);
		add_option( EventBridge_Installer::DB_VERSION_OPTION, 1, '', false );
		add_option( 'eventbridge_events', array( $event_key => $legacy ), '', false );

		$this->make_upgrader()->run();

		$events = get_option( 'eventbridge_events' );
		$this->assertSame( 2, get_option( EventBridge_Installer::DB_VERSION_OPTION ) );
		$this->assertArrayHasKey( $event_key, $events );
		$this->assertSame( array( 'preserved' => true ), $events[ $event_key ]['unknown'] );
		$this->assertCount( 1, $events[ $event_key ]['triggers'] );
		$this->assertSame( 'trg_55555555-5555-4555-8555-555555555555', $events[ $event_key ]['triggers'][0]['trigger_id'] );
		$this->assertSame( '.legacy', $events[ $event_key ]['selector'] );
		$this->assertSame( array( 'browser' => true, 'capi' => false ), $events[ $event_key ]['channels'] );
		$this->assertArrayNotHasKey( 'channels', $events[ $event_key ]['triggers'][0] );
	}

	public function test_existing_mixed_schema_two_event_is_disabled_and_preserved() {
		$event_key = 'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$frontend_id = 'trg_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
		$server_id   = 'trg_bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
		$event = array(
			'label' => 'Mixed local', 'event_name' => 'Lead', 'enabled' => true,
			'trigger_type' => 'click', 'selector' => '.lead', 'browser' => true, 'capi' => false,
			'triggers' => array(
				array( 'trigger_id' => $frontend_id, 'provider' => 'frontend', 'trigger_type' => 'click', 'provider_config' => array( 'selector' => '.lead' ), 'channels' => array( 'browser' => true, 'capi' => false ), 'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array() ),
				array( 'trigger_id' => $server_id, 'provider' => 'woocommerce', 'trigger_type' => 'order_lifecycle', 'provider_config' => array( 'event' => 'paid' ), 'channels' => array( 'browser' => false, 'capi' => true ), 'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array() ),
			),
			'eventbridge_schema_version' => 2,
			'eventbridge_compat' => array( 'legacy_trigger_id' => $frontend_id, 'legacy_projection_hash' => '' ),
		);
		$event['eventbridge_compat']['legacy_projection_hash'] = ( new EventBridge_Triggers() )->get_projection_hash( $event );
		add_option( EventBridge_Installer::DB_VERSION_OPTION, 2, '', false );
		add_option( 'eventbridge_events', array( $event_key => $event ), '', false );

		$this->make_upgrader()->run();
		$stored = get_option( 'eventbridge_events' )[ $event_key ];

		$this->assertFalse( $stored['enabled'] );
		$this->assertCount( 2, $stored['triggers'] );
		$this->assertSame( '.lead', $stored['triggers'][0]['provider_config']['selector'] );
		$this->assertArrayHasKey( EventBridge_Triggers::FAMILY_CONFLICT_KEY, $stored );
		$this->assertArrayNotHasKey( 'channels', $stored['triggers'][0] );
		$this->assertArrayNotHasKey( 'channels', $stored['triggers'][1] );
	}

	public function test_schema_healthcheck_reconciles_rollback_projection_without_losing_secondary_trigger() {
		$event_key = 'evt_66666666-6666-4666-8666-666666666666';
		$legacy    = array(
			'label'        => 'Rollback',
			'event_name'   => 'Lead',
			'trigger_type' => 'click',
			'selector'     => '.before',
			'browser'      => true,
			'capi'         => false,
			'enabled'      => true,
		);
		add_option( EventBridge_Installer::DB_VERSION_OPTION, 1, '', false );
		add_option( 'eventbridge_events', array( $event_key => $legacy ), '', false );
		$upgrader = $this->make_upgrader();
		$upgrader->run();

		$stored = get_option( 'eventbridge_events' );
		$secondary = $stored[ $event_key ]['triggers'][0];
		$secondary['trigger_id'] = 'trg_77777777-7777-4777-8777-777777777777';
		$secondary['provider_config']['selector'] = '.secondary';
		$stored[ $event_key ]['triggers'][] = $secondary;
		$stored[ $event_key ]['selector'] = '.changed-in-120';
		update_option( 'eventbridge_events', $stored, false );

		$upgrader->run();
		$reconciled = get_option( 'eventbridge_events' );

		$this->assertCount( 2, $reconciled[ $event_key ]['triggers'] );
		$this->assertSame( '.changed-in-120', $reconciled[ $event_key ]['triggers'][0]['provider_config']['selector'] );
		$this->assertSame( '.secondary', $reconciled[ $event_key ]['triggers'][1]['provider_config']['selector'] );
	}

	private function make_upgrader() {
		$installer = new EventBridge_Installer( $this->log, $this->status );

		return new EventBridge_Upgrader( $this->log, $installer, $this->status );
	}
}
