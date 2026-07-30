<?php

class EventBridge_Test_Condition_Provider implements EventBridge_Condition_Provider_Interface {
	public $evaluated = 0;

	public function get_key() {
		return 'test';
	}

	public function supports_event( $event ) {
		return isset( $event['trigger_type'] ) && 'test' === $event['trigger_type'];
	}

	public function get_catalog() {
		return array(
			'allowed' => array(
				'label'     => 'Allowed',
				'operators' => array( 'eq' => array( 'label' => 'Equals', 'value_type' => 'boolean' ) ),
			),
		);
	}

	public function validate_condition( $condition, $existing_conditions = array() ) {
		$value = isset( $condition['value'] ) && in_array( $condition['value'], array( true, 1, '1' ), true );
		return array(
			'condition' => array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => $value ),
			'errors'    => array(),
		);
	}

	public function build_context( $trigger, $subject, $required_conditions ) {
		return array( 'provider' => 'test', 'value' => (bool) $subject );
	}

	public function evaluate( $condition, $context ) {
		++$this->evaluated;
		if ( ! isset( $context['value'] ) ) {
			return array( 'status' => 'invalid_context', 'reason' => 'missing_value' );
		}
		return array(
			'status' => (bool) $condition['value'] === (bool) $context['value'] ? 'match' : 'mismatch',
			'reason' => 'test_result',
		);
	}

	public function search_values( $field, $search, $page, $limit ) {
		return array( 'results' => array(), 'more' => false );
	}

	public function resolve_value_labels( $field, $values ) {
		return array();
	}
}

class EventBridge_Test_Condition_Log extends EventBridge_Log {
	public $entries = array();

	public function log( $level, $source, $message, $details = array() ) {
		$this->entries[] = compact( 'level', 'source', 'message', 'details' );
		return true;
	}
}

class EventBridge_Conditions_Test extends WP_UnitTestCase {
	private $provider;
	private $conditions;

	public function set_up() {
		parent::set_up();
		$this->provider   = new EventBridge_Test_Condition_Provider();
		$this->conditions = new EventBridge_Conditions( array( $this->provider ) );
	}

	public function test_empty_conditions_match_without_provider_evaluation() {
		$result = $this->conditions->evaluate( array(), array() );

		$this->assertSame( 'match', $result['status'] );
		$this->assertSame( 0, $this->provider->evaluated );
	}

	public function test_all_conditions_are_combined_with_and_and_short_circuit() {
		$conditions = array(
			array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => true ),
			array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => false ),
			array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => true ),
		);

		$result = $this->conditions->evaluate( $conditions, array( 'provider' => 'test', 'value' => true ) );

		$this->assertSame( 'mismatch', $result['status'] );
		$this->assertSame( 2, $this->provider->evaluated );
	}

	public function test_wrong_provider_and_malformed_list_fail_closed() {
		$wrong = $this->conditions->evaluate(
			array( array( 'provider' => 'missing', 'field' => 'allowed', 'operator' => 'eq', 'value' => true ) ),
			array( 'provider' => 'test', 'value' => true )
		);
		$malformed = $this->conditions->evaluate( 'invalid', array() );

		$this->assertSame( 'invalid_context', $wrong['status'] );
		$this->assertSame( 'invalid_context', $malformed['status'] );
	}

	public function test_validation_rejects_provider_for_another_trigger() {
		$result = $this->conditions->validate_conditions(
			array( array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => '1' ) ),
			array( 'trigger_type' => 'woocommerce' )
		);

		$this->assertNotEmpty( $result['errors'] );
		$this->assertTrue( $result['conditions'][0]['value'] );
	}

	public function test_mismatch_is_silent_and_invalid_context_debug_is_deduplicated() {
		$old_settings = get_option( EventBridge_Settings::OPTION_NAME, false );
		$log          = new EventBridge_Test_Condition_Log();
		$settings     = new EventBridge_Settings();
		$engine       = new EventBridge_Conditions( array( $this->provider ), $settings, $log );
		$event_key    = 'evt_cccccccc-cccc-4ccc-8ccc-cccccccccccc';
		$transient    = 'eventbridge_condition_debug_' . substr( hash( 'sha256', $event_key . '|condition_invalid' ), 0, 32 );
		try {
			update_option( EventBridge_Settings::OPTION_NAME, array( 'pixel_id' => '', 'capi_token' => '', 'debug' => true ), false );
			delete_transient( $transient );

			$engine->evaluate(
				array( array( 'provider' => 'test', 'field' => 'allowed', 'operator' => 'eq', 'value' => false ) ),
				array( 'provider' => 'test', 'value' => true ),
				$event_key
			);
			$this->assertSame( array(), $log->entries );

			$engine->evaluate( array( 'invalid-row' ), array(), $event_key );
			$engine->evaluate( array( 'invalid-row' ), array(), $event_key );
			$this->assertCount( 1, $log->entries );
			$this->assertSame( 'info', $log->entries[0]['level'] );
		} finally {
			delete_transient( $transient );
			if ( false === $old_settings ) {
				delete_option( EventBridge_Settings::OPTION_NAME );
			} else {
				update_option( EventBridge_Settings::OPTION_NAME, $old_settings, false );
			}
		}
	}

	public function test_old_event_normalizes_to_empty_conditions_without_rewrite() {
		$provider = new EventBridge_WooCommerce_Conditions();
		$engine   = new EventBridge_Conditions( array( $provider ) );
		$capi     = new EventBridge_Meta_CAPI( new EventBridge_Settings(), new EventBridge_Log() );
		$woo      = new EventBridge_WooCommerce( $capi, new EventBridge_Log(), $engine );
		$events   = new EventBridge_Events( $woo, $engine );
		$stored   = array(
			'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => array(
				'label'        => 'Legacy',
				'event_name'   => 'BookingComplete',
				'trigger_type' => 'click',
				'selector'     => '.book',
				'browser'      => true,
			),
		);
		$old_events = get_option( EventBridge_Events::OPTION_NAME, false );
		try {
			update_option( EventBridge_Events::OPTION_NAME, $stored, false );

			$normalized = $events->get_normalized_events();

			$this->assertSame( array(), $normalized['evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']['conditions'] );
			$this->assertSame( $stored, get_option( EventBridge_Events::OPTION_NAME ) );
		} finally {
			if ( false === $old_events ) {
				delete_option( EventBridge_Events::OPTION_NAME );
			} else {
				update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
			}
		}
	}
}
