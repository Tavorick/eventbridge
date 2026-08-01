<?php

class EventBridge_131_Regression_Test extends WP_UnitTestCase {
	public function test_custom_and_standard_event_names_remain_free_form_and_conditionless() {
		$provider   = new EventBridge_WooCommerce_Conditions();
		$conditions = new EventBridge_Conditions( array( $provider ) );
		$capi       = new EventBridge_Meta_CAPI( new EventBridge_Settings(), new EventBridge_Log() );
		$woo        = new EventBridge_WooCommerce( $capi, new EventBridge_Log(), $conditions );
		$events     = new EventBridge_Events( $woo, $conditions );

		foreach ( array( 'BookingComplete', 'Purchase' ) as $event_name ) {
			$validation = $events->validate_event(
				array(
					'label'        => $event_name,
					'event_name'   => $event_name,
					'trigger_type' => 'click',
					'selector'     => '.book',
					'browser'      => '1',
					'enabled'      => '1',
				)
			);
			$this->assertSame( array(), $validation['errors'] );
			$this->assertSame( $event_name, $validation['event']['event_name'] );
			$this->assertSame( array(), $validation['event']['conditions'] );
			$this->assertSame( array(), $validation['event']['parameters'] );
		}
	}

	public function test_public_and_database_versions_are_131() {
		$this->assertSame( '1.3.1', EVENTBRIDGE_VERSION );
		$this->assertSame( 2, EVENTBRIDGE_DB_VERSION );
	}
}
