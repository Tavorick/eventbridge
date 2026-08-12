<?php

class EventBridge_Interaction_Capturing_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();

	public function send_custom_event( $event_name, $event_id, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = array(
			'event_name'          => $event_name,
			'event_id'            => $event_id,
			'event_source_url'    => $event_source_url,
			'custom_data'         => $custom_data,
			'details'             => $details,
			'advanced_user_data'  => $advanced_user_data,
			'event_configuration' => $event_configuration,
		);
		return true;
	}
}

class EventBridge_Interaction_Failing_Dispatcher extends EventBridge_Dispatcher {
	public $calls = array();

	public function dispatch_custom_event( $destination_id, array $occurrence ) {
		$this->calls[] = array( 'destination_id' => $destination_id, 'occurrence' => $occurrence );
		return false;
	}
}

class EventBridge_WooCommerce_Interactions_Test extends WP_UnitTestCase {
	private $interactions;
	private $capi;
	private $events;
	private $log;
	private $conditions;

	public function set_up() {
		parent::set_up();
		$settings = new EventBridge_Settings();
		$this->log  = new EventBridge_Log();
		$this->capi = new EventBridge_Interaction_Capturing_CAPI( $settings, $this->log );
		$registry = new EventBridge_Destination_Registry();
		$registry->register( new EventBridge_Meta_Destination( $this->capi ) );
		$dispatcher = new EventBridge_Dispatcher( $registry );
		$woo      = new EventBridge_WooCommerce( $dispatcher, $this->log );
		$provider = new EventBridge_WooCommerce_Conditions();
		$this->conditions = new EventBridge_Conditions( array( $provider ), $settings, $this->log );
		$this->events   = new EventBridge_Events( $woo, $this->conditions );
		$this->interactions = new EventBridge_WooCommerce_Interactions( $this->events, $dispatcher, $this->log, $this->conditions );
	}

	public function test_refresh_refetch_and_retry_keep_the_same_attempt() {
		$started = $this->interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
		$started['reported'] = true;
		$started['claims'] = array( 'route' => array( 'event_id' => '11111111-1111-4111-8111-111111111111' ) );

		foreach ( array( 'reload', 'navigate', 'back_forward' ) as $navigation ) {
			$resolved = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', $navigation, 1100 );
			$this->assertSame( $started['id'], $resolved['id'] );
			$this->assertTrue( $resolved['reported'] );
			$this->assertSame( $started['claims'], $resolved['claims'] );
		}
	}

	public function test_confirmed_leave_only_creates_a_new_attempt_on_active_reentry() {
		$started = $this->interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
		$started['left'] = true;
		$history = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', 'back_forward', 1100 );
		$this->assertSame( $started['id'], $history['id'] );
		$this->assertFalse( $history['left'] );

		$started['left'] = true;
		$active = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', 'navigate', 1100 );
		$this->assertNotSame( $started['id'], $active['id'] );
	}

	public function test_cart_fingerprint_and_timeout_are_independent_new_attempt_fallbacks() {
		$started = $this->interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
		$changed = $this->interactions->resolve_checkout_attempt( $started, 'cart-b', 'reload', 1100 );
		$this->assertNotSame( $started['id'], $changed['id'] );

		$timed_out = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', 'reload', 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
		$this->assertNotSame( $started['id'], $timed_out['id'] );
	}

	public function test_gateway_flow_grace_prevents_timeout_from_splitting_the_attempt() {
		$started = $this->interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
		$started['flow_until'] = 1000 + EventBridge_WooCommerce_Interactions::FLOW_GRACE_TTL;

		$during_flow = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', 'navigate', 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
		$this->assertSame( $started['id'], $during_flow['id'] );

		$after_flow = $this->interactions->resolve_checkout_attempt( $started, 'cart-a', 'navigate', 1000 + EventBridge_WooCommerce_Interactions::FLOW_GRACE_TTL + 1 );
		$this->assertNotSame( $started['id'], $after_flow['id'] );
	}

	public function test_place_order_activity_refreshes_the_known_attempt_after_a_long_checkout_form() {
		$started = $this->interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
		$refreshed = $this->interactions->refresh_checkout_flow_attempt( $started, 'cart-a', $started['id'], 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
		$this->assertSame( $started['id'], $refreshed['id'] );
		$this->assertSame( 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1, $refreshed['last_activity'] );

		$changed = $this->interactions->refresh_checkout_flow_attempt( $started, 'cart-b', $started['id'], 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
		$this->assertNotSame( $started['id'], $changed['id'] );
	}

	public function test_technical_checkout_routes_are_not_confirmed_as_storefront_leaves() {
		$method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'is_confirmed_checkout_leave_destination' );
		$method->setAccessible( true );

		foreach ( array(
			home_url( '/?wc-api=WC_Gateway_Test' ),
			home_url( '/?wc-ajax=checkout' ),
			home_url( '/?rest_route=/wc/store/v1/checkout' ),
			home_url( '/checkout/order-pay/123/' ),
			home_url( '/order-received/123/' ),
			home_url( '/gateway/callback/' ),
			home_url( '/3ds/return/' ),
		) as $technical_route ) {
			$this->assertFalse( $method->invoke( $this->interactions, $technical_route ), $technical_route );
		}

		$this->assertTrue( $method->invoke( $this->interactions, home_url( '/shop/?filter=featured' ) ) );
	}

	public function test_product_context_is_only_prepared_for_the_server_confirmed_product_query() {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$product = new WC_Product_Simple();
		try {
			$product->set_name( 'Canonical interaction product' );
			$product->set_status( 'publish' );
			$product->set_regular_price( '12.50' );
			$product->save();
			$trigger = array(
				'trigger_id' => 'trg_cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd',
				'provider' => 'woocommerce', 'trigger_type' => 'product_viewed', 'provider_config' => array(),
				'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array(),
			);
			$event = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
				array( 'label' => 'Product', 'event_name' => 'ViewContent', 'enabled' => true, 'channels' => array( 'browser' => true, 'capi' => false ) ),
				array( $trigger ),
				$trigger['trigger_id']
			);
			update_option( EventBridge_Events::OPTION_NAME, array( 'evt_cdcdcdcd-cdcd-4dcd-8dcd-cdcdcdcdcdcd' => $event ) );

			$this->go_to( home_url( '/' ) );
			$this->assertSame( '', $this->interactions->get_client_configuration()['productViewContext'] );
			$this->go_to( get_permalink( $product->get_id() ) );
			$this->assertNotSame( '', $this->interactions->get_client_configuration()['productViewContext'] );
		} finally {
			update_option( EventBridge_Events::OPTION_NAME, $old_events );
			if ( $product->get_id() ) {
				$product->delete( true );
			}
		}
	}

	public function test_browser_and_capi_share_one_claimed_event_id() {
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$trigger = array(
			'trigger_id' => 'trg_efefefef-efef-4fef-8fef-efefefefefef',
			'provider' => 'woocommerce', 'trigger_type' => 'added_to_cart', 'provider_config' => array(),
			'parameters' => array( array( 'name' => 'value', 'source' => 'woocommerce_interaction', 'value' => 'line_value' ) ),
			'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array(),
		);
		$event = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
			array( 'label' => 'Add', 'event_name' => 'AddToCart', 'enabled' => true, 'channels' => array( 'browser' => true, 'capi' => true ) ),
			array( $trigger ),
			$trigger['trigger_id']
		);
		try {
			update_option( EventBridge_Events::OPTION_NAME, array( 'evt_efefefef-efef-4fef-8fef-efefefefefef' => $event ) );
			$method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'dispatch_occurrence' );
			$method->setAccessible( true );
			$claims = array();
			$args = array(
				'added_to_cart',
				array( 'eventbridge_context' => 'added_to_cart', 'line_value' => 25.0 ),
				'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				home_url( '/shop/' ),
				&$claims,
				array(),
			);
			$deliveries = $method->invokeArgs( $this->interactions, $args );
			$this->assertCount( 1, $deliveries );
			$this->assertCount( 1, $this->capi->calls );
			$this->assertSame( $this->capi->calls[0]['event_id'], $deliveries[0]['eventId'] );
			$this->assertSame( 25.0, $deliveries[0]['parameters']->value );

			$duplicate = $method->invokeArgs( $this->interactions, $args );
			$this->assertSame( array(), $duplicate );
			$this->assertCount( 1, $this->capi->calls );
		} finally {
			update_option( EventBridge_Events::OPTION_NAME, $old_events );
		}
	}

	public function test_interactions_dispatch_custom_occurrences_to_meta_with_the_complete_existing_payload() {
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$types = array( 'product_viewed', 'added_to_cart', 'checkout_started' );
		try {
			foreach ( $types as $index => $type ) {
				$trigger = array(
					'trigger_id' => 'trg_12345678-1234-4234-8234-12345678901' . $index,
					'provider' => 'woocommerce', 'trigger_type' => $type, 'provider_config' => array(),
					'parameters' => array( array( 'name' => 'value', 'source' => 'woocommerce_interaction', 'value' => 'line_value' ) ),
					'conditions' => array(), 'data_source' => array(),
					'advanced_matching' => array( 'email' => array( 'source' => 'static', 'value' => 'person@example.test' ) ),
				);
				$event = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
					array(
						'label' => 'Interaction', 'event_name' => 'Event' . $index, 'enabled' => true,
						'channels' => array( 'browser' => true, 'capi' => true ),
						'meta_test_mode' => true, 'meta_test_event_code' => 'TEST123',
					),
					array( $trigger ),
					$trigger['trigger_id']
				);
				update_option( EventBridge_Events::OPTION_NAME, array( 'evt_12345678-1234-4234-8234-12345678901' . $index => $event ) );
				$claims = array();
				$deliveries = $this->dispatch_occurrence( $this->interactions, $type, array( 'eventbridge_context' => $type, 'line_value' => 25.0 ), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa' . $index, $claims );
				$call = $this->capi->calls[ $index ];

				$this->assertCount( 1, $deliveries );
				$this->assertSame( 'Event' . $index, $call['event_name'] );
				$this->assertSame( $deliveries[0]['eventId'], $call['event_id'] );
				$this->assertSame( home_url( '/shop/' ), $call['event_source_url'] );
				$this->assertSame( array( 'value' => 25.0 ), $call['custom_data'] );
				$this->assertSame( array( 'interaction' => $type, 'occurrence_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa' . $index ), $call['details']['context'] );
				$this->assertArrayHasKey( 'em', $call['advanced_user_data'] );
				$this->assertTrue( $call['event_configuration']['meta_test_mode'] );
				$this->assertSame( 'TEST123', $call['event_configuration']['meta_test_event_code'] );
			}
		} finally {
			update_option( EventBridge_Events::OPTION_NAME, $old_events );
		}
	}

	public function test_dispatcher_failure_keeps_existing_browser_channel_behavior() {
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$registry = new EventBridge_Destination_Registry();
		$dispatcher = new EventBridge_Interaction_Failing_Dispatcher( $registry );
		$interactions = new EventBridge_WooCommerce_Interactions( $this->events, $dispatcher, $this->log, $this->conditions );
		$method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'dispatch_occurrence' );
		$method->setAccessible( true );
		try {
			foreach ( array( false, true ) as $browser ) {
				$trigger = array( 'trigger_id' => 'trg_abcdefab-cdef-4def-8def-abcdefabcdef', 'provider' => 'woocommerce', 'trigger_type' => 'added_to_cart', 'provider_config' => array(), 'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array() );
				$event = ( new EventBridge_Triggers() )->apply_compatibility_shadow( array( 'label' => 'Failure', 'event_name' => 'AddToCart', 'enabled' => true, 'channels' => array( 'browser' => $browser, 'capi' => true ) ), array( $trigger ), $trigger['trigger_id'] );
				update_option( EventBridge_Events::OPTION_NAME, array( 'evt_abcdefab-cdef-4def-8def-abcdefabcdef' => $event ) );
				$claims = array();
				$args = array( 'added_to_cart', array( 'eventbridge_context' => 'added_to_cart' ), 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', home_url( '/shop/' ), &$claims, array() );
				$deliveries = $method->invokeArgs( $interactions, $args );
				$this->assertSame( $browser ? 1 : 0, count( $deliveries ) );
				$this->assertSame( $browser, ! empty( $claims ) );
			}
			$this->assertCount( 2, $dispatcher->calls );
		} finally {
			update_option( EventBridge_Events::OPTION_NAME, $old_events );
		}
	}

	private function dispatch_occurrence( $interactions, $type, $snapshot, $occurrence_id, &$claims ) {
		$method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'dispatch_occurrence' );
		$method->setAccessible( true );
		$args = array( $type, $snapshot, $occurrence_id, home_url( '/shop/' ), &$claims, array() );
		return $method->invokeArgs( $interactions, $args );
	}
}
