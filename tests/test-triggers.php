<?php

class EventBridge_Triggers_Test extends WP_UnitTestCase {
	private $events;

	public function set_up() {
		parent::set_up();
		$this->events = new EventBridge_Events();
	}

	public function test_legacy_event_becomes_one_compatibility_trigger_with_deterministic_id() {
		$event_key = 'evt_11111111-1111-4111-8111-111111111111';
		$event     = $this->events->normalize_event(
			array(
				'label'        => 'Legacy click',
				'event_name'   => 'Lead',
				'trigger_type' => 'click',
				'selector'     => '.lead',
				'browser'      => true,
				'capi'         => false,
				'enabled'      => true,
				'future_field' => array( 'keep' => true ),
			),
			$event_key
		);

		$this->assertCount( 1, $event['triggers'] );
		$this->assertSame( 'trg_11111111-1111-4111-8111-111111111111', $event['triggers'][0]['trigger_id'] );
		$this->assertSame( $event['triggers'][0]['trigger_id'], $event['eventbridge_compat']['legacy_trigger_id'] );
		$this->assertSame( '.lead', $event['triggers'][0]['provider_config']['selector'] );
		$this->assertSame( array( 'keep' => true ), $event['future_field'] );
		$this->assertSame( 2, $event['eventbridge_schema_version'] );
	}

	public function test_two_frontend_triggers_have_independent_ids_configuration_and_parameters() {
		$validation = $this->events->validate_event(
			array(
				'label'        => 'Booking complete',
				'event_name'   => 'BookingComplete',
				'enabled'      => '1',
				'triggers'     => array(
					$this->click_trigger( '.route-a', 'route', 'A' ),
					$this->click_trigger( '.route-b', 'route', 'B' ),
				),
			)
		);

		$this->assertSame( array(), $validation['errors'] );
		$this->assertCount( 2, $validation['event']['triggers'] );
		$this->assertNotSame( $validation['event']['triggers'][0]['trigger_id'], $validation['event']['triggers'][1]['trigger_id'] );
		$this->assertSame( '.route-a', $validation['event']['triggers'][0]['provider_config']['selector'] );
		$this->assertSame( '.route-b', $validation['event']['triggers'][1]['provider_config']['selector'] );
		$this->assertSame( 'A', $validation['event']['triggers'][0]['parameters'][0]['value'] );
		$this->assertSame( 'B', $validation['event']['triggers'][1]['parameters'][0]['value'] );
	}

	public function test_parameter_context_is_bound_to_trigger_and_canonical_url() {
		$event_key  = 'evt_22222222-2222-4222-8222-222222222222';
		$validation = $this->events->validate_event(
			array(
				'label'      => 'Routes',
				'event_name' => 'BookingComplete',
				'enabled'    => '1',
				'triggers'   => array(
					$this->query_trigger( '.route-a', 'campaign_a' ),
					$this->query_trigger( '.route-b', 'campaign_b' ),
				),
			),
			null,
			true,
			$event_key
		);
		$event  = $validation['event'];
		$route_a = $this->events->get_effective_event( $event, $event['triggers'][0] );
		$route_b = $this->events->get_effective_event( $event, $event['triggers'][1] );
		$url     = 'https://example.org/booking-complete';
		$context = $this->events->create_parameter_context( $event_key, $route_a, array( 'campaign' => 'spring' ), $url );

		$this->assertNotSame( '', $context );
		$this->assertSame( array( 'campaign' => 'spring' ), $this->events->verify_parameter_context( $event_key, $route_a, $context, $url ) );
		$this->assertFalse( $this->events->verify_parameter_context( $event_key, $route_b, $context, $url ) );
		$this->assertFalse( $this->events->verify_parameter_context( $event_key, $route_a, $context, 'https://example.org/other' ) );
	}

	public function test_invalid_stored_trigger_does_not_change_a_valid_sibling() {
		$event_key = 'evt_33333333-3333-4333-8333-333333333333';
		$valid     = $this->click_trigger( '.valid', 'kind', 'valid' );
		$valid['trigger_id'] = 'trg_33333333-3333-4333-8333-333333333333';
		$invalid = $this->click_trigger( '.invalid', 'kind', 'invalid' );
		$invalid['trigger_id']   = 'trg_44444444-4444-4444-8444-444444444444';
		$invalid['provider']     = 'unknown';
		$invalid['trigger_type'] = 'unknown';

		$event = $this->events->normalize_event(
			array(
				'label'      => 'Stored routes',
				'event_name' => 'Lead',
				'enabled'    => true,
				'triggers'   => array( $valid, $invalid ),
				'eventbridge_compat' => array(
					'legacy_trigger_id'      => $valid['trigger_id'],
					'legacy_projection_hash' => '',
				),
			),
			$event_key
		);

		$this->assertCount( 2, $event['triggers'] );
		$this->assertSame( 'frontend', $event['triggers'][0]['provider'] );
		$this->assertSame( '.valid', $event['triggers'][0]['provider_config']['selector'] );
		$this->assertSame( 'unknown', $event['triggers'][1]['provider'] );
		$this->assertSame( 'unknown', $event['triggers'][1]['trigger_type'] );
	}

	private function click_trigger( $selector, $name, $value ) {
		return array(
			'trigger_id'      => '',
			'provider'        => 'frontend',
			'trigger_type'    => 'click',
			'provider_config' => array( 'selector' => $selector ),
			'channels'        => array( 'browser' => '1', 'capi' => '' ),
			'data_source'     => array(),
			'parameters'      => array(
				array( 'name' => $name, 'source' => 'static', 'value' => $value ),
			),
			'advanced_matching' => array(),
			'conditions'        => array(),
		);
	}

	private function query_trigger( $selector, $query_parameter ) {
		$trigger = $this->click_trigger( $selector, 'campaign', '' );
		$trigger['parameters'][0] = array(
			'name'   => 'campaign',
			'source' => 'query_parameter',
			'value'  => $query_parameter,
		);

		return $trigger;
	}
}
