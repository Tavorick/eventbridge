<?php

class EventBridge_WooCommerce_Capturing_Log extends EventBridge_Log {
	public $entries = array();

	public function log( $level, $source, $message, $details = array() ) {
		$this->entries[] = array(
			'level'   => $level,
			'source'  => $source,
			'message' => $message,
			'details' => $details,
		);

		return true;
	}
}

class EventBridge_WooCommerce_Capturing_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();

	public function send_server_event_confirmed( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = array(
			'event_name'         => $event_name,
			'event_id'           => $event_id,
			'custom_data'        => $custom_data,
			'advanced_user_data' => $advanced_user_data,
		);
		return array( 'status' => 'success', 'reason' => 'confirmed', 'http_code' => 200 );
	}
}

class EventBridge_WooCommerce_Sequenced_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();
	public $results = array();

	public function send_server_event_confirmed( $event_name, $event_id, $event_time, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = array( 'event_id' => $event_id, 'event_time' => $event_time );
		return empty( $this->results )
			? array( 'status' => 'retryable', 'reason' => 'transport_error', 'http_code' => 0 )
			: array_shift( $this->results );
	}
}

class EventBridge_WooCommerce_Counting_Dispatcher extends EventBridge_WooCommerce {
	public $uuid_count = 0;
	public $advanced_matching_count = 0;

	protected function generate_uuid() {
		++$this->uuid_count;
		return wp_generate_uuid4();
	}

	protected function get_advanced_user_data( $event, $order ) {
		++$this->advanced_matching_count;
		return parent::get_advanced_user_data( $event, $order );
	}

	public function get_order_client_user_data_for_test( $order ) {
		return parent::get_order_client_user_data( $order );
	}
}

class EventBridge_WooCommerce_Test extends WP_UnitTestCase {
	private $provider;
	private $events;

	public function set_up() {
		parent::set_up();

		$settings       = new EventBridge_Settings();
		$log            = new EventBridge_Log();
		$meta_capi      = new EventBridge_Meta_CAPI( $settings, $log );
		$this->provider = new EventBridge_WooCommerce( $meta_capi, $log );
		$this->events   = new EventBridge_Events( $this->provider );
		$this->provider->set_events( $this->events );
	}

	public function test_old_event_normalizes_with_disabled_woocommerce_preset() {
		$event = $this->events->normalize_event(
			array(
				'label'        => 'Bestaand',
				'event_name'   => 'Lead',
				'trigger_type' => 'click',
				'selector'     => '.lead',
				'browser'      => true,
			)
		);

		$this->assertSame(
			array(
				'event'           => '',
				'status'          => '',
				'purchase_preset' => false,
			),
			$event['woocommerce']
		);
	}

	public function test_normalization_preserves_additive_configuration_fields() {
		$event_key = 'evt_12121212-1212-4212-8212-121212121212';
		$event     = $this->events->normalize_event(
			array(
				'label'               => 'Forward compatible',
				'event_name'          => 'Purchase',
				'trigger_type'        => 'woocommerce',
				'capi'                => true,
				'future_event_field'  => 'keep',
				'woocommerce'        => array(
					'event'              => 'paid',
					'future_woo_field'   => array( 'version' => 2 ),
				),
			),
			$event_key
		);
		$route = $this->events->get_effective_event( $event, $event['triggers'][0] );

		$this->assertSame( 'keep', $event['future_event_field'] );
		$this->assertSame( array( 'version' => 2 ), $event['woocommerce']['future_woo_field'] );
		$this->assertSame( array( 'version' => 2 ), $event['triggers'][0]['provider_config']['future_woo_field'] );
		$this->assertSame( array( 'version' => 2 ), $route['woocommerce']['future_woo_field'] );
		$this->assertSame( 'paid', $route['woocommerce']['event'] );
		$this->assertFalse( $event['woocommerce']['purchase_preset'] );
	}

	public function test_woocommerce_parameter_values_keep_numeric_types() {
		$event_key  = 'evt_13131313-1313-4313-8313-131313131313';
		$trigger_id = 'trg_13131313-1313-4313-8313-131313131313';
		$event      = $this->events->normalize_event(
			array(
				'label'      => 'Numeric order values',
				'event_name' => 'Purchase',
				'channels'   => array( 'browser' => false, 'capi' => true ),
				'triggers'   => array(
					array(
						'trigger_id'      => $trigger_id,
						'provider'        => 'woocommerce',
						'trigger_type'    => 'order_lifecycle',
						'provider_config' => array( 'event' => 'paid', 'status' => '', 'purchase_preset' => false ),
						'parameters'      => array(
							array( 'name' => 'value', 'source' => 'woocommerce_order', 'value' => 'total' ),
							array( 'name' => 'count', 'source' => 'woocommerce_order', 'value' => 'product_quantity_total' ),
						),
						'conditions'        => array(),
						'data_source'       => array(),
						'advanced_matching' => array(),
					),
				),
			),
			$event_key
		);
		$route = $this->events->get_effective_event( $event, $event['triggers'][0] );

		$parameters = $this->events->get_parameter_map(
			$route,
			array(),
			array(),
			array(
				'total'                  => 10.25,
				'product_quantity_total' => 3,
			)
		);

		$this->assertSame( 10.25, $parameters['value'] );
		$this->assertSame( 3, $parameters['count'] );
	}

	public function test_interaction_parameter_values_keep_meta_types_and_arrays() {
		$event = array(
				'trigger_type' => 'checkout_started',
				'parameters' => array(
					array( 'name' => 'value', 'source' => 'woocommerce_interaction', 'value' => 'cart_total' ),
					array( 'name' => 'content_ids', 'source' => 'woocommerce_interaction', 'value' => 'content_ids' ),
					array( 'name' => 'contents', 'source' => 'woocommerce_interaction', 'value' => 'contents' ),
				),
			);
		$parameters = $this->events->get_parameter_map(
			$event,
			array(), array(), array(),
			array(
				'cart_total' => 29.95,
				'content_ids' => array( 10, 22 ),
				'contents' => array( array( 'id' => 10, 'quantity' => 2, 'item_price' => 14.975 ) ),
			)
		);

		$this->assertSame( 29.95, $parameters['value'] );
		$this->assertSame( array( 10, 22 ), $parameters['content_ids'] );
		$this->assertSame( 2, $parameters['contents'][0]['quantity'] );
	}

	public function test_new_woocommerce_configuration_is_rejected_when_provider_is_unavailable() {
		if ( $this->provider->is_available() ) {
			$this->markTestSkipped( 'This assertion targets the WooCommerce-absent test environment.' );
		}

		$validation = $this->events->validate_event(
			array(
				'label'        => 'Paid',
				'event_name'   => 'Purchase',
				'trigger_type' => 'woocommerce',
				'capi'         => '1',
				'enabled'      => '1',
				'woocommerce'  => array(
					'event'           => 'paid',
					'status'          => '',
					'purchase_preset' => '1',
				),
			)
		);

		$this->assertNotEmpty( $validation['errors'] );
		$this->assertSame( 'paid', $validation['event']['woocommerce']['event'] );
	}

	public function test_interaction_trigger_validates_as_frontend_with_contextual_source() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$validation = $this->events->validate_event(
			array(
				'label' => 'Cart interaction', 'event_name' => 'AddToCart', 'enabled' => '1',
				'channels' => array( 'browser' => '1', 'capi' => '1' ),
				'triggers' => array(
					array(
						'trigger_id' => '', 'provider' => 'woocommerce', 'trigger_type' => 'added_to_cart',
						'provider_config' => array(), 'data_source' => array(),
						'parameters' => array( array( 'name' => 'value', 'source' => 'woocommerce_interaction', 'value' => 'line_value' ) ),
						'advanced_matching' => array(), 'conditions' => array(),
					),
				),
			)
		);
		$this->assertSame( array(), $validation['errors'] );
		$this->assertSame( EventBridge_Triggers::FAMILY_FRONTEND, $this->events->get_event_family( $validation['event'] ) );
		$this->assertSame( 'eventbridge_disabled', $validation['event']['trigger_type'] );
		$this->assertSame( 'added_to_cart', $validation['event']['triggers'][0]['trigger_type'] );
	}

	public function test_interaction_trigger_rejects_order_billing_advanced_matching() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$validation = $this->events->validate_event(
			array(
				'label' => 'Checkout interaction', 'event_name' => 'InitiateCheckout', 'enabled' => '1',
				'channels' => array( 'capi' => '1' ),
				'triggers' => array(
					array(
						'trigger_id' => '', 'provider' => 'woocommerce', 'trigger_type' => 'checkout_started',
						'provider_config' => array(), 'data_source' => array(), 'parameters' => array(), 'conditions' => array(),
						'advanced_matching' => array( 'email' => array( 'source' => 'woocommerce_billing', 'value' => 'billing_email' ) ),
					),
				),
			)
		);
		$this->assertNotEmpty( $validation['errors'] );
		$this->assertStringContainsString( 'pas beschikbaar nadat een bestelling bestaat', implode( ' ', $validation['errors'] ) );
	}

	public function test_non_woocommerce_event_cannot_use_woocommerce_sources() {
		$validation = $this->events->validate_event(
			array(
				'label'        => 'Klik',
				'event_name'   => 'Lead',
				'trigger_type' => 'click',
				'selector'     => '.lead',
				'browser'      => '1',
				'enabled'      => '1',
				'parameters'   => array(
					array( 'name' => 'order_value', 'source' => 'woocommerce_order', 'value' => 'total' ),
				),
			)
		);

		$this->assertNotEmpty( $validation['errors'] );
	}

	public function test_database_version_is_two_for_130() {
		$this->assertSame( '1.3.0', EVENTBRIDGE_VERSION );
		$this->assertSame( 2, EVENTBRIDGE_DB_VERSION );
	}

	public function test_deleted_created_order_is_silent_after_valid_hook() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		$order_id   = 0;

		try {
			list( $provider, $events, $log ) = $this->get_capturing_provider();
			update_option( EventBridge_Events::OPTION_NAME, array( 'evt_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' => $this->get_created_event() ), false );
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$this->assertInstanceOf( 'WC_Order', $order );
			$order_id = $order->get_id();

			$provider->handle_new_order( $order_id, $order );
			$order->delete( true );
			$provider->flush_created_orders();

			$this->assertFalse( wc_get_order( $order_id ) );
			$this->assertSame( array(), $log->entries );
		} finally {
			if ( $order_id > 0 && wc_get_order( $order_id ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_checkout_draft_is_ignored_without_warning() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$order = null;
		list( $provider, $events, $log ) = $this->get_capturing_provider();

		try {
			$order = wc_create_order( array( 'status' => 'checkout-draft' ) );
			$this->assertInstanceOf( 'WC_Order', $order );

			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();

			$this->assertSame( array(), $log->entries );
		} finally {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
		}
	}

	public function test_refund_passed_to_created_hook_remains_a_warning() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$order  = null;
		$refund = null;
		list( $provider, $events, $log ) = $this->get_capturing_provider();

		try {
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$this->assertInstanceOf( 'WC_Order', $order );
			$refund = new WC_Order_Refund();
			$refund->set_parent_id( $order->get_id() );
			$refund->save();

			$provider->handle_new_order( $refund->get_id(), $refund );

			$this->assertCount( 1, $log->entries );
			$this->assertSame( 'warning', $log->entries[0]['level'] );
			$this->assertSame( 'unsupported_order_type', $log->entries[0]['details']['context']['reason'] );
		} finally {
			if ( is_a( $refund, 'WC_Order_Refund' ) ) {
				$refund->delete( true );
			}
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
		}
	}

	public function test_condition_mismatch_precedes_uuid_ledger_advanced_matching_and_capi() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$settings           = new EventBridge_Settings();
			$log                = new EventBridge_WooCommerce_Capturing_Log();
			$capi               = new EventBridge_WooCommerce_Capturing_CAPI( $settings, $log );
			$condition_provider = new EventBridge_WooCommerce_Conditions();
			$conditions         = new EventBridge_Conditions( array( $condition_provider ), $settings, $log );
			$provider           = new EventBridge_WooCommerce_Counting_Dispatcher( $capi, $log, $conditions );
			$events             = new EventBridge_Events( $provider, $conditions );
			$provider->set_events( $events );
			$event_key = 'evt_bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
			$event     = $this->get_created_event();
			$event['event_name'] = 'BookingComplete';
			$event['conditions'] = array(
				array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'gte', 'value' => '10.00' ),
			);
			update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $event ), false );

			$order = wc_create_order( array( 'status' => 'pending' ) );
			$this->assertInstanceOf( 'WC_Order', $order );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();

			$order = wc_get_order( $order->get_id() );
			$this->assertSame( 0, $provider->uuid_count );
			$this->assertSame( 0, $provider->advanced_matching_count );
			$this->assertSame( array(), $capi->calls );
			$this->assertSame( '', $order->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true ) );
			$this->assertSame( array(), $log->entries );

			$event['conditions'][0] = array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'lte', 'value' => '0.00' );
			update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $event ), false );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();

			$this->assertSame( 1, $provider->advanced_matching_count );
			$this->assertCount( 1, $capi->calls );
			$this->assertSame( 'BookingComplete', $capi->calls[0]['event_name'] );
			$this->assertSame( array(), $capi->calls[0]['custom_data'] );
			$ledger = $order->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$this->assertIsArray( $ledger );
			$this->assertCount( 2, $ledger['entries'] );
		} finally {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_order_client_user_data_uses_only_valid_order_values() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$order = null;
		$old_remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : null;
		$old_user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null;
		try {
			$settings   = new EventBridge_Settings();
			$log        = new EventBridge_Log();
			$capi       = new EventBridge_Meta_CAPI( $settings, $log );
			$dispatcher = new EventBridge_WooCommerce_Counting_Dispatcher( $capi, $log );
			$order      = wc_create_order( array( 'status' => 'pending' ) );

			$_SERVER['REMOTE_ADDR']     = '198.51.100.200';
			$_SERVER['HTTP_USER_AGENT'] = 'Webhook request agent';
			$order->set_customer_ip_address( '203.0.113.24' );
			$order->set_customer_user_agent( 'Order customer agent' );
			$this->assertSame(
				array( 'client_ip_address' => '203.0.113.24', 'client_user_agent' => 'Order customer agent' ),
				$dispatcher->get_order_client_user_data_for_test( $order )
			);

			$order->set_customer_ip_address( '' );
			$this->assertSame( array( 'client_user_agent' => 'Order customer agent' ), $dispatcher->get_order_client_user_data_for_test( $order ) );

			$order->set_customer_ip_address( 'not-an-ip' );
			$order->set_customer_user_agent( '' );
			$this->assertSame( array(), $dispatcher->get_order_client_user_data_for_test( $order ) );

			$order->set_customer_ip_address( '2001:db8::24' );
			$order->set_customer_user_agent( str_repeat( 'a', 501 ) );
			$this->assertSame( array( 'client_ip_address' => '2001:db8::24' ), $dispatcher->get_order_client_user_data_for_test( $order ) );
		} finally {
			if ( null === $old_remote_addr ) {
				unset( $_SERVER['REMOTE_ADDR'] );
			} else {
				$_SERVER['REMOTE_ADDR'] = $old_remote_addr;
			}
			if ( null === $old_user_agent ) {
				unset( $_SERVER['HTTP_USER_AGENT'] );
			} else {
				$_SERVER['HTTP_USER_AGENT'] = $old_user_agent;
			}
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
		}
	}

	public function test_reserved_ledger_keys_are_rejected_and_hidden_by_rest_filters() {
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/1' );
		$request->set_param( 'meta_data', array( array( 'key' => EventBridge_WooCommerce::LEDGER_PRODUCTION_META, 'value' => array() ) ) );
		$result = $this->provider->protect_ledger_meta_request( new stdClass(), $request, false );
		$this->assertWPError( $result );
		$this->assertSame( 'eventbridge_reserved_order_meta', $result->get_error_code() );

		$response = new WP_REST_Response(
			array(
				'meta_data' => array(
					array( 'id' => 1, 'key' => EventBridge_WooCommerce::LEDGER_PRODUCTION_META, 'value' => array() ),
					(object) array( 'id' => 2, 'key' => EventBridge_WooCommerce::LEDGER_TEST_META, 'value' => array() ),
					array( 'id' => 3, 'key' => 'merchant_note', 'value' => 'keep' ),
				),
			)
		);
		$filtered = $this->provider->protect_ledger_meta_response( $response, null, new WP_REST_Request() )->get_data();
		$this->assertSame( array( array( 'id' => 3, 'key' => 'merchant_note', 'value' => 'keep' ) ), $filtered['meta_data'] );
	}

	public function test_reserved_ledger_meta_id_cannot_be_renamed_via_rest() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$order = wc_create_order( array( 'status' => 'pending' ) );
		try {
			$order->update_meta_data( EventBridge_WooCommerce::LEDGER_TEST_META, array( 'version' => 1, 'entries' => array() ) );
			$order->save_meta_data();
			$ledger_id = 0;
			foreach ( wc_get_order( $order->get_id() )->get_meta_data() as $meta ) {
				if ( EventBridge_WooCommerce::LEDGER_TEST_META === $meta->key ) {
					$ledger_id = absint( $meta->id );
				}
			}
			$this->assertGreaterThan( 0, $ledger_id );
			$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
			$request->set_param( 'id', $order->get_id() );
			$request->set_param( 'meta_data', array( array( 'id' => $ledger_id, 'key' => 'merchant_note', 'value' => 'replace' ) ) );
			$this->assertWPError( $this->provider->protect_ledger_meta_request( $order, $request, false ) );
			$this->assertSame( array( 'version' => 1, 'entries' => array() ), wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true ) );
		} finally {
			$order->delete( true );
		}
	}

	public function test_ledger_budget_is_independent_per_mode_and_rejects_route_101() {
		$hundred = $this->get_budget_events( 100, 100 );
		$budget  = $this->provider->get_ledger_budget_status( $hundred );
		$this->assertFalse( $budget['production']['over_budget'] );
		$this->assertFalse( $budget['test']['over_budget'] );

		$over = $this->provider->get_ledger_budget_status( $this->get_budget_events( 101, 100 ) );
		$this->assertTrue( $over['production']['over_budget'] );
		$this->assertFalse( $over['test']['over_budget'] );
	}

	public function test_prospective_ledger_budget_rejects_101_but_allows_reduction() {
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		try {
			$current = $this->get_budget_events( 100, 0 );
			update_option( EventBridge_Events::OPTION_NAME, $current, false );
			$validation = $this->events->validate_event( $this->get_created_event(), null, true );
			$errors = array_values(
				array_filter(
					$validation['errors'],
					function ( $error ) { return false !== strpos( $error, '101' ); }
				)
			);
			$this->assertNotEmpty( $errors );
			$this->assertStringContainsString( '101', $errors[0] );
			$this->assertStringContainsString( '100', $errors[0] );

			$over = $this->get_budget_events( 101, 0 );
			update_option( EventBridge_Events::OPTION_NAME, $over, false );
			$editing_key = array_key_first( $over );
			$disabled = $over[ $editing_key ];
			$disabled['enabled'] = false;
			$this->assertSame( array(), $this->provider->validate_ledger_budget_for_event( $disabled, $editing_key ) );
		} finally {
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_overfull_raw_ledger_blocks_batch_without_overwrite() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$log      = new EventBridge_WooCommerce_Capturing_Log();
			$capi     = new EventBridge_WooCommerce_Capturing_CAPI( new EventBridge_Settings(), $log );
			$provider = new EventBridge_WooCommerce( $capi, $log );
			$events   = new EventBridge_Events( $provider );
			$provider->set_events( $events );
			update_option( EventBridge_Events::OPTION_NAME, $this->get_budget_events( 2, 0 ), false );

			$entries = array();
			for ( $index = 0; $index < 201; $index++ ) {
				$key = 'historical-' . $index;
				$entries[ $key ] = array(
					'version' => 'prod_v2', 'logical_key' => $key, 'event_id' => wp_generate_uuid4(),
					'event_time' => 1000 + $index, 'state' => 'confirmed', 'attempts' => 1,
					'created_at' => 1000, 'updated_at' => 1000,
				);
			}
			$raw = array( 'version' => 1, 'entries' => $entries );
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$order->update_meta_data( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, $raw );
			$order->save_meta_data();

			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();

			$this->assertCount( 0, $capi->calls );
			$this->assertSame( $raw, wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true ) );
			$capacity_logs = array_filter( $log->entries, function ( $entry ) {
				return 'WooCommerce event dispatch blocked by ledger capacity.' === $entry['message'];
			} );
			$this->assertCount( 1, $capacity_logs );
		} finally {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_overbudget_configuration_blocks_only_affected_mode() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$log      = new EventBridge_WooCommerce_Capturing_Log();
			$capi     = new EventBridge_WooCommerce_Capturing_CAPI( new EventBridge_Settings(), $log );
			$provider = new EventBridge_WooCommerce( $capi, $log );
			$events   = new EventBridge_Events( $provider );
			$provider->set_events( $events );
			update_option( EventBridge_Events::OPTION_NAME, $this->get_budget_events( 101, 1 ), false );
			$order = wc_create_order( array( 'status' => 'pending' ) );

			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();

			$this->assertCount( 1, $capi->calls );
			$this->assertEmpty( wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true ) );
			$this->assertNotEmpty( wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true ) );
			$capacity_logs = array_filter( $log->entries, function ( $entry ) {
				return 'configuration_ledger_budget_exceeded' === $entry['details']['context']['reason'];
			} );
			$this->assertCount( 1, $capacity_logs );
		} finally {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_retry_keeps_event_identity_and_confirmed_state_is_final() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$log      = new EventBridge_WooCommerce_Capturing_Log();
			$capi     = new EventBridge_WooCommerce_Sequenced_CAPI( new EventBridge_Settings(), $log );
			$capi->results = array(
				array( 'status' => 'retryable', 'reason' => 'http_500', 'http_code' => 500 ),
				array( 'status' => 'success', 'reason' => 'confirmed', 'http_code' => 200 ),
			);
			$provider = new EventBridge_WooCommerce( $capi, $log );
			$events   = new EventBridge_Events( $provider );
			$provider->set_events( $events );
			$event_key = 'evt_cccccccc-cccc-4ccc-8ccc-cccccccccccc';
			update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $this->get_created_event() ), false );
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$ledger = wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$logical_key = current( array_filter( array_keys( $ledger['entries'] ), function ( $key ) { return 0 === strpos( $key, 'v2|' ); } ) );
			$this->assertSame( 'pending', $ledger['entries'][ $logical_key ]['state'] );

			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$confirmed = wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$this->assertSame( 'confirmed', $confirmed['entries'][ $logical_key ]['state'] );
			$this->assertCount( 2, $capi->calls );
			$this->assertSame( $capi->calls[0], $capi->calls[1] );

			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$this->assertCount( 2, $capi->calls );
		} finally {
			wp_clear_scheduled_hook( EventBridge_WooCommerce::RETRY_HOOK );
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_retryable_delivery_stops_after_three_attempts() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$log      = new EventBridge_WooCommerce_Capturing_Log();
			$capi     = new EventBridge_WooCommerce_Sequenced_CAPI( new EventBridge_Settings(), $log );
			$capi->results = array_fill( 0, 3, array( 'status' => 'retryable', 'reason' => 'http_500', 'http_code' => 500 ) );
			$provider = new EventBridge_WooCommerce( $capi, $log );
			$events   = new EventBridge_Events( $provider );
			$provider->set_events( $events );
			$event_key = 'evt_dddddddd-dddd-4ddd-8ddd-dddddddddddd';
			update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $this->get_created_event() ), false );
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$ledger = wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$logical_key = current( array_filter( array_keys( $ledger['entries'] ), function ( $key ) { return 0 === strpos( $key, 'v2|' ); } ) );
			$args = array( $order->get_id(), $logical_key, 0 );
			$this->assertNotFalse( wp_next_scheduled( EventBridge_WooCommerce::RETRY_HOOK, $args ) );

			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$terminal = wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$this->assertSame( 'terminal', $terminal['entries'][ $logical_key ]['state'] );
			$this->assertSame( 3, $terminal['entries'][ $logical_key ]['attempts'] );
			$this->assertFalse( wp_next_scheduled( EventBridge_WooCommerce::RETRY_HOOK, $args ) );
			$this->assertCount( 3, $capi->calls );
			$this->assertSame( $capi->calls[0], $capi->calls[1] );
			$this->assertSame( $capi->calls[0], $capi->calls[2] );

			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$this->assertCount( 3, $capi->calls );
		} finally {
			wp_clear_scheduled_hook( EventBridge_WooCommerce::RETRY_HOOK );
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	public function test_terminal_delivery_is_not_retried() {
		if ( ! $this->provider->is_available() ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}
		$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
		$order      = null;
		try {
			$log      = new EventBridge_WooCommerce_Capturing_Log();
			$capi     = new EventBridge_WooCommerce_Sequenced_CAPI( new EventBridge_Settings(), $log );
			$capi->results = array( array( 'status' => 'terminal', 'reason' => 'http_400', 'http_code' => 400 ) );
			$provider = new EventBridge_WooCommerce( $capi, $log );
			$events   = new EventBridge_Events( $provider );
			$provider->set_events( $events );
			$event_key = 'evt_eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
			update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $this->get_created_event() ), false );
			$order = wc_create_order( array( 'status' => 'pending' ) );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$ledger = wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
			$logical_key = current( array_filter( array_keys( $ledger['entries'] ), function ( $key ) { return 0 === strpos( $key, 'v2|' ); } ) );
			$this->assertSame( 'terminal', $ledger['entries'][ $logical_key ]['state'] );
			$this->assertSame( 'http_400', $ledger['entries'][ $logical_key ]['failure_reason'] );
			$this->assertFalse( wp_next_scheduled( EventBridge_WooCommerce::RETRY_HOOK, array( $order->get_id(), $logical_key, 0 ) ) );

			$provider->handle_retry( $order->get_id(), $logical_key, 0 );
			$provider->handle_new_order( $order->get_id(), $order );
			$provider->flush_created_orders();
			$this->assertCount( 1, $capi->calls );
		} finally {
			wp_clear_scheduled_hook( EventBridge_WooCommerce::RETRY_HOOK );
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		}
	}

	private function get_capturing_provider() {
		$settings = new EventBridge_Settings();
		$log      = new EventBridge_WooCommerce_Capturing_Log();
		$capi     = new EventBridge_Meta_CAPI( $settings, $log );
		$provider = new EventBridge_WooCommerce( $capi, $log );
		$events   = new EventBridge_Events( $provider );
		$provider->set_events( $events );

		return array( $provider, $events, $log );
	}

	private function get_created_event() {
		return array(
			'label'        => 'Created regression',
			'description'  => '',
			'event_name'   => 'Purchase',
			'browser'      => false,
			'capi'         => true,
			'meta_test_mode'       => false,
			'meta_test_event_code' => '',
			'enabled'      => true,
			'trigger_type' => 'woocommerce',
			'selector'     => '',
			'url_match_type'  => '',
			'url_match_value' => '',
			'parameters'   => array(),
			'data_source'  => array(),
			'advanced_matching' => array(),
			'woocommerce' => array( 'event' => 'created', 'status' => '', 'purchase_preset' => false ),
			'remove_query_parameters' => true,
		);
	}

	private function get_budget_events( $production_count, $test_count ) {
		$events = array();
		foreach ( array( false => $production_count, true => $test_count ) as $test_mode => $count ) {
			for ( $index = 0; $index < $count; $index++ ) {
				$event_uuid  = wp_generate_uuid4();
				$trigger_uuid = wp_generate_uuid4();
				$event_key   = 'evt_' . $event_uuid;
				$trigger_id  = 'trg_' . $trigger_uuid;
				$trigger = array(
					'trigger_id' => $trigger_id, 'provider' => 'woocommerce', 'trigger_type' => 'order_lifecycle',
					'provider_config' => array( 'event' => 'created', 'status' => '', 'purchase_preset' => false ),
					'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array(),
				);
				$events[ $event_key ] = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
					array( 'label' => 'Budget', 'event_name' => 'Purchase', 'enabled' => true, 'channels' => array( 'browser' => false, 'capi' => true ), 'meta_test_mode' => (bool) $test_mode, 'meta_test_event_code' => $test_mode ? 'TEST123' : '' ),
					array( $trigger ),
					$trigger_id
				);
			}
		}
		return $events;
	}
}
