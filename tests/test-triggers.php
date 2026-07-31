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

	public function test_woocommerce_interactions_share_the_frontend_family() {
		$triggers = new EventBridge_Triggers();
		foreach ( array( 'product_viewed', 'added_to_cart', 'checkout_started' ) as $type ) {
			$this->assertSame(
				EventBridge_Triggers::FAMILY_FRONTEND,
				$triggers->get_trigger_family( array( 'provider' => 'woocommerce', 'trigger_type' => $type ) )
			);
		}
	}

	public function test_interaction_only_compatibility_projection_is_disabled_and_preserved() {
		$triggers = new EventBridge_Triggers();
		$interaction = array(
			'trigger_id' => 'trg_abababab-abab-4bab-8bab-abababababab',
			'provider' => 'woocommerce', 'trigger_type' => 'product_viewed', 'provider_config' => array(),
			'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array(),
		);
		$stored = $triggers->apply_compatibility_shadow(
			array( 'label' => 'Product view', 'event_name' => 'ViewContent', 'enabled' => true, 'channels' => array( 'browser' => true, 'capi' => true ) ),
			array( $interaction ),
			$interaction['trigger_id']
		);

		$this->assertSame( 'eventbridge_disabled', $stored['trigger_type'] );
		$this->assertSame( '', $stored['eventbridge_compat']['legacy_trigger_id'] );
		$this->assertCount( 1, $stored['triggers'] );
		$stored['label'] = 'Changed while running 1.2.0';
		$normalized = $this->events->normalize_event( $stored, 'evt_abababab-abab-4bab-8bab-abababababab' );
		$this->assertSame( 'Changed while running 1.2.0', $normalized['label'] );
		$this->assertSame( 'product_viewed', $normalized['triggers'][0]['trigger_type'] );
		$this->assertSame( $interaction['trigger_id'], $normalized['triggers'][0]['trigger_id'] );

		$legacy_overwrite = $stored;
		unset( $legacy_overwrite['triggers'] );
		$restored = $this->events->normalize_event( $legacy_overwrite, 'evt_abababab-abab-4bab-8bab-abababababab' );
		$this->assertSame( 'product_viewed', $restored['triggers'][0]['trigger_type'] );
		$this->assertSame( $interaction['trigger_id'], $restored['triggers'][0]['trigger_id'] );
	}

	public function test_two_frontend_triggers_have_independent_ids_configuration_and_parameters() {
		$validation = $this->events->validate_event(
			array(
				'label'        => 'Booking complete',
				'event_name'   => 'BookingComplete',
				'enabled'      => '1',
				'channels'     => array( 'browser' => '1' ),
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
				'channels'   => array( 'browser' => '1' ),
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

		$raw = array(
				'label'      => 'Stored routes',
				'event_name' => 'Lead',
				'enabled'    => true,
				'trigger_type' => 'click',
				'selector'     => '.valid',
				'browser'      => true,
				'capi'         => false,
				'triggers'   => array( $valid, $invalid ),
				'eventbridge_compat' => array(
					'legacy_trigger_id'      => $valid['trigger_id'],
					'legacy_projection_hash' => '',
				),
			);
		$raw['eventbridge_compat']['legacy_projection_hash'] = ( new EventBridge_Triggers() )->get_projection_hash( $raw );
		$event = $this->events->normalize_event(
			$raw,
			$event_key
		);

		$this->assertCount( 2, $event['triggers'] );
		$this->assertSame( 'frontend', $event['triggers'][0]['provider'] );
		$this->assertSame( '.valid', $event['triggers'][0]['provider_config']['selector'] );
		$this->assertSame( 'unknown', $event['triggers'][1]['provider'] );
		$this->assertSame( 'unknown', $event['triggers'][1]['trigger_type'] );
	}

	public function test_compatible_frontend_combinations_share_one_family() {
		foreach ( array( array( 'click', 'click' ), array( 'click', 'pageview' ), array( 'pageview', 'pageview' ) ) as $types ) {
			$first  = 'pageview' === $types[0] ? $this->pageview_trigger( '/one' ) : $this->click_trigger( '.one', 'route', 'one' );
			$second = 'pageview' === $types[1] ? $this->pageview_trigger( '/two' ) : $this->click_trigger( '.two', 'route', 'two' );
			$validation = $this->events->validate_event(
				array(
					'label' => 'Frontend family', 'event_name' => 'Lead', 'enabled' => '1',
					'channels' => array( 'browser' => '1', 'capi' => '1' ),
					'triggers' => array( $first, $second ),
				)
			);
			$this->assertSame( array(), $validation['errors'] );
			$this->assertSame( EventBridge_Triggers::FAMILY_FRONTEND, $this->events->get_event_family( $validation['event'] ) );
		}
	}

	public function test_mixed_families_are_rejected_server_side() {
		$validation = $this->events->validate_event(
			array(
				'label' => 'Invalid mixed', 'event_name' => 'Lead', 'enabled' => '1',
				'channels' => array( 'capi' => '1' ),
				'triggers' => array( $this->click_trigger( '.lead', 'route', 'front' ), $this->woocommerce_trigger( 'paid' ) ),
			)
		);

		$this->assertNotEmpty( $validation['errors'] );
		$this->assertFalse( $validation['event']['enabled'] );
		$this->assertCount( 2, $validation['event']['triggers'] );
	}

	public function test_server_lifecycle_combinations_force_capi_only() {
		foreach ( array( array( 'created', 'paid' ), array( 'paid', 'status' ) ) as $events ) {
			$validation = $this->events->validate_event(
				array(
					'label' => 'Server family', 'event_name' => 'Purchase', 'enabled' => '1',
					'channels' => array( 'capi' => '1' ),
					'triggers' => array( $this->woocommerce_trigger( $events[0] ), $this->woocommerce_trigger( $events[1] ) ),
				)
			);
			$this->assertSame( array(), $validation['errors'] );
			$this->assertSame( EventBridge_Triggers::FAMILY_SERVER, $this->events->get_event_family( $validation['event'] ) );
			$this->assertSame( array( 'browser' => false, 'capi' => true ), $validation['event']['channels'] );
			$this->assertFalse( $this->events->get_effective_event( $validation['event'], $validation['event']['triggers'][0] )['browser'] );
		}
	}

	public function test_tampered_server_lifecycle_channels_are_rejected() {
		$validation = $this->events->validate_event(
			array(
				'label' => 'Tampered server', 'event_name' => 'Purchase', 'enabled' => '1',
				'channels' => array( 'browser' => '1' ),
				'triggers' => array( $this->woocommerce_trigger( 'paid' ) ),
			)
		);

		$this->assertNotEmpty( $validation['errors'] );
		$this->assertSame( array( 'browser' => false, 'capi' => true ), $validation['event']['channels'] );
	}

	public function test_local_mixed_schema_two_event_is_disabled_without_removing_triggers() {
		$frontend = $this->click_trigger( '.lead', 'route', 'front' );
		$frontend['trigger_id'] = 'trg_88888888-8888-4888-8888-888888888888';
		$frontend['channels'] = array( 'browser' => true, 'capi' => false );
		$server = $this->woocommerce_trigger( 'paid' );
		$server['trigger_id'] = 'trg_99999999-9999-4999-8999-999999999999';
		$server['channels'] = array( 'browser' => false, 'capi' => true );

		$raw = array(
				'label' => 'Stored mixed', 'event_name' => 'Lead', 'enabled' => true,
				'trigger_type' => 'click', 'selector' => '.lead', 'browser' => true, 'capi' => false,
				'triggers' => array( $frontend, $server ),
				'eventbridge_schema_version' => 2,
				'eventbridge_compat' => array( 'legacy_trigger_id' => $frontend['trigger_id'], 'legacy_projection_hash' => '' ),
			);
		$raw['eventbridge_compat']['legacy_projection_hash'] = ( new EventBridge_Triggers() )->get_projection_hash( $raw );
		$event = $this->events->normalize_event(
			$raw,
			'evt_88888888-8888-4888-8888-888888888888'
		);

		$this->assertFalse( $event['enabled'] );
		$this->assertCount( 2, $event['triggers'] );
		$this->assertArrayHasKey( EventBridge_Triggers::FAMILY_CONFLICT_KEY, $event );
		$this->assertSame( array( 'browser' => true, 'capi' => false ), $event['channels'] );
		$this->assertArrayNotHasKey( 'channels', $event['triggers'][0] );
		$this->assertArrayNotHasKey( 'channels', $event['triggers'][1] );
	}

	private function click_trigger( $selector, $name, $value ) {
		return array(
			'trigger_id'      => '',
			'provider'        => 'frontend',
			'trigger_type'    => 'click',
			'provider_config' => array( 'selector' => $selector ),
			'data_source'     => array(),
			'parameters'      => array(
				array( 'name' => $name, 'source' => 'static', 'value' => $value ),
			),
			'advanced_matching' => array(),
			'conditions'        => array(),
		);
	}

	private function pageview_trigger( $path ) {
		$trigger = $this->click_trigger( '', 'route', $path );
		$trigger['trigger_type'] = 'pageview';
		$trigger['provider_config'] = array( 'url_match_type' => 'path_exact', 'url_match_value' => $path );
		return $trigger;
	}

	private function woocommerce_trigger( $event ) {
		return array(
			'trigger_id' => '', 'provider' => 'woocommerce', 'trigger_type' => 'order_lifecycle',
			'provider_config' => array( 'event' => $event, 'status' => 'status' === $event ? 'completed' : '', 'purchase_preset' => false ),
			'data_source' => array(), 'parameters' => array(), 'advanced_matching' => array(), 'conditions' => array(),
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
