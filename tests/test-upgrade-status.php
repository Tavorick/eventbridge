<?php

class EventBridge_Upgrade_Status_Test extends WP_UnitTestCase {
	private $status;

	public function set_up() {
		parent::set_up();

		$this->status = new EventBridge_Upgrade_Status();
		delete_option( EventBridge_Upgrade_Status::OPTION_NAME );

		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
	}

	public function tear_down() {
		delete_option( EventBridge_Upgrade_Status::OPTION_NAME );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_healthy_installation_has_no_inline_status() {
		$this->status->mark_succeeded( EVENTBRIDGE_DB_VERSION, EVENTBRIDGE_DB_VERSION );

		ob_start();
		$this->status->render_inline_status();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_failed_status_remains_visible_inline() {
		$this->status->mark_failed( 0, EVENTBRIDGE_DB_VERSION, 1, 'log_table_unavailable' );

		ob_start();
		$this->status->render_inline_status();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'EventBridge-migratie 1', $output );
		$this->assertStringContainsString( 'log_table_unavailable', $output );
	}

	public function test_one_time_success_notice_is_preserved() {
		$this->status->mark_succeeded( 0, EVENTBRIDGE_DB_VERSION );

		ob_start();
		$this->status->render_admin_notice();
		$first_output = ob_get_clean();

		ob_start();
		$this->status->render_admin_notice();
		$second_output = ob_get_clean();

		$this->assertStringContainsString( 'EventBridge is succesvol bijgewerkt', $first_output );
		$this->assertSame( '', $second_output );
	}
}
