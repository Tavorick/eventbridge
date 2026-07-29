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
		$event = $this->events->normalize_event(
			array(
				'label'               => 'Forward compatible',
				'future_event_field'  => 'keep',
				'woocommerce'        => array(
					'event'              => 'paid',
					'future_woo_field'   => array( 'version' => 2 ),
				),
			)
		);

		$this->assertSame( 'keep', $event['future_event_field'] );
		$this->assertSame( array( 'version' => 2 ), $event['woocommerce']['future_woo_field'] );
		$this->assertFalse( $event['woocommerce']['purchase_preset'] );
	}

	public function test_woocommerce_parameter_values_keep_numeric_types() {
		$event = $this->events->normalize_event(
			array(
				'parameters' => array(
					array( 'name' => 'value', 'source' => 'woocommerce_order', 'value' => 'total' ),
					array( 'name' => 'count', 'source' => 'woocommerce_order', 'value' => 'product_quantity_total' ),
				),
			)
		);

		$parameters = $this->events->get_parameter_map(
			$event,
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

	public function test_database_version_does_not_change_for_110() {
		$this->assertSame( '1.1.0', EVENTBRIDGE_VERSION );
		$this->assertSame( 1, EVENTBRIDGE_DB_VERSION );
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
}
