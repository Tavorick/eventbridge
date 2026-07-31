<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_WooCommerce {
	const MINIMUM_VERSION = '8.2.0';

	const LEDGER_PRODUCTION_META = '_eventbridge_dispatch_ledger_prod_v1';
	const LEDGER_TEST_META       = '_eventbridge_dispatch_ledger_test_v1';
	const LEDGER_MAX_ENTRIES     = 100;

	const LOCK_PREFIX = 'eventbridge_wc_lock_';
	const LOCK_TTL    = 30;

	const MAX_LINE_ITEMS       = 100;
	const MAX_COUPONS          = 50;
	const MAX_IDENTIFIER_LENGTH = 100;
	const MAX_STRING_LENGTH     = 500;
	const MAX_COUPON_STRING     = 500;
	const MAX_QUANTITY          = 1000000;
	const MAX_AMOUNT            = 999999999999.99;

	private $meta_capi;
	private $log;
	private $events;
	private $conditions;
	private $created_order_ids = array();

	public function __construct( EventBridge_Meta_CAPI $meta_capi, EventBridge_Log $log, EventBridge_Conditions $conditions = null ) {
		$this->meta_capi = $meta_capi;
		$this->log       = $log;
		$this->conditions = $conditions;
	}

	public function set_events( EventBridge_Events $events ) {
		$this->events = $events;
	}

	public function init() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );

		if ( ! $this->is_available() ) {
			return;
		}

		add_action( 'woocommerce_new_order', array( $this, 'handle_new_order' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'handle_status_changed' ), 10, 4 );
		add_action( 'shutdown', array( $this, 'flush_created_orders' ), 20 );
	}

	public function declare_hpos_compatibility() {
		$class_name = '\Automattic\WooCommerce\Utilities\FeaturesUtil';
		if ( class_exists( $class_name ) && defined( 'EVENTBRIDGE_PLUGIN_FILE' ) ) {
			$class_name::declare_compatibility( 'custom_order_tables', EVENTBRIDGE_PLUGIN_FILE, true );
		}
	}

	public function is_available() {
		return 'available' === $this->get_runtime_status();
	}

	public function get_runtime_status() {
		$plugin_file = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' : '';
		$installed   = '' !== $plugin_file && file_exists( $plugin_file );

		if ( ! $installed ) {
			return 'unavailable';
		}

		if ( ! defined( 'WC_VERSION' ) ) {
			return $this->is_plugin_active() ? 'not_ready' : 'installed_inactive';
		}

		if ( version_compare( WC_VERSION, self::MINIMUM_VERSION, '<' ) ) {
			return 'unsupported';
		}

		if ( ! function_exists( 'wc_get_order' )
			|| ! function_exists( 'wc_get_order_statuses' )
			|| ! function_exists( 'wc_get_is_paid_statuses' )
			|| ! class_exists( 'WC_Order' )
		) {
			return 'not_ready';
		}

		return 'available';
	}

	public function get_configuration_defaults() {
		return array(
			'event'           => '',
			'status'          => '',
			'purchase_preset' => false,
		);
	}

	public function normalize_configuration( $configuration, $preserve_unknown = true ) {
		$raw_configuration = is_array( $configuration ) ? $configuration : array();
		$configuration     = wp_parse_args( $raw_configuration, $this->get_configuration_defaults() );
		$event         = isset( $configuration['event'] ) && is_scalar( $configuration['event'] ) ? sanitize_key( (string) $configuration['event'] ) : '';
		$status        = isset( $configuration['status'] ) && is_scalar( $configuration['status'] ) ? $this->normalize_status( $configuration['status'] ) : '';

		$normalized                    = $preserve_unknown ? $raw_configuration : array();
		$normalized['event']           = in_array( $event, array( 'created', 'paid', 'status' ), true ) ? $event : '';
		$normalized['status']          = $status;
		$normalized['purchase_preset'] = true === (bool) $configuration['purchase_preset'];

		return $normalized;
	}

	public function get_order_parameter_fields() {
		return array(
			'order_id'              => __( 'Order-ID', 'eventbridge' ),
			'order_number'          => __( 'Ordernummer', 'eventbridge' ),
			'status'                => __( 'Orderstatus', 'eventbridge' ),
			'currency'              => __( 'Valuta', 'eventbridge' ),
			'total'                 => __( 'Totaal', 'eventbridge' ),
			'subtotal'              => __( 'Subtotaal', 'eventbridge' ),
			'tax_total'             => __( 'Belasting', 'eventbridge' ),
			'shipping_total'        => __( 'Verzendkosten', 'eventbridge' ),
			'discount_total'        => __( 'Korting', 'eventbridge' ),
			'payment_method_id'     => __( 'Betaalmethode-ID', 'eventbridge' ),
			'payment_method_title'  => __( 'Naam betaalmethode', 'eventbridge' ),
			'date_created'          => __( 'Aanmaakdatum', 'eventbridge' ),
			'date_paid'             => __( 'Betaaldatum', 'eventbridge' ),
			'line_item_count'       => __( 'Aantal orderregels', 'eventbridge' ),
			'product_quantity_total' => __( 'Totaal aantal producten', 'eventbridge' ),
			'coupon_codes'          => __( 'Couponcodes', 'eventbridge' ),
		);
	}

	public function get_interaction_parameter_fields( $trigger_type = '' ) {
		$fields = array(
			'product_id'       => array( 'label' => __( 'Product-ID', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart' ) ),
			'parent_id'        => array( 'label' => __( 'Hoofdproduct-ID', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart' ) ),
			'variation_id'     => array( 'label' => __( 'Variatie-ID', 'eventbridge' ), 'contexts' => array( 'added_to_cart' ) ),
			'product_name'     => array( 'label' => __( 'Productnaam', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart' ) ),
			'unit_price'       => array( 'label' => __( 'Eenheidsprijs', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart' ) ),
			'min_price'        => array( 'label' => __( 'Minimumprijs', 'eventbridge' ), 'contexts' => array( 'product_viewed' ) ),
			'max_price'        => array( 'label' => __( 'Maximumprijs', 'eventbridge' ), 'contexts' => array( 'product_viewed' ) ),
			'quantity'         => array( 'label' => __( 'Toegevoegd aantal', 'eventbridge' ), 'contexts' => array( 'added_to_cart' ) ),
			'line_value'       => array( 'label' => __( 'Waarde van toevoeging', 'eventbridge' ), 'contexts' => array( 'added_to_cart' ) ),
			'currency'         => array( 'label' => __( 'Valuta', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart', 'checkout_started' ) ),
			'content_ids'      => array( 'label' => __( 'Content-ID\'s', 'eventbridge' ), 'contexts' => array( 'product_viewed', 'added_to_cart', 'checkout_started' ) ),
			'contents'         => array( 'label' => __( 'Inhoud van winkelmand', 'eventbridge' ), 'contexts' => array( 'checkout_started' ) ),
			'cart_subtotal'    => array( 'label' => __( 'Subtotaal winkelmand', 'eventbridge' ), 'contexts' => array( 'checkout_started' ) ),
			'cart_total'       => array( 'label' => __( 'Totaal winkelmand', 'eventbridge' ), 'contexts' => array( 'checkout_started' ) ),
			'total_quantity'   => array( 'label' => __( 'Totaal aantal producten', 'eventbridge' ), 'contexts' => array( 'checkout_started' ) ),
			'coupon_codes'     => array( 'label' => __( 'Couponcodes', 'eventbridge' ), 'contexts' => array( 'checkout_started' ) ),
		);

		if ( '' === $trigger_type ) {
			return $fields;
		}

		return array_filter(
			$fields,
			function ( $field ) use ( $trigger_type ) {
				return in_array( $trigger_type, $field['contexts'], true );
			}
		);
	}

	public function get_billing_field_map() {
		return array(
			'email'      => 'billing_email',
			'phone'      => 'billing_phone',
			'first_name' => 'billing_first_name',
			'last_name'  => 'billing_last_name',
		);
	}

	public function get_order_statuses() {
		if ( ! $this->is_available() ) {
			return array();
		}

		$statuses = array();
		foreach ( wc_get_order_statuses() as $status => $label ) {
			$slug = $this->normalize_status( $status );
			if ( '' !== $slug && is_scalar( $label ) ) {
				$statuses[ $slug ] = (string) $label;
			}
		}

		return $statuses;
	}

	public function validate_event_configuration( $event, $existing_event = null ) {
		$errors         = array();
		$event          = is_array( $event ) ? $event : array();
		$existing_event = is_array( $existing_event ) ? $existing_event : null;
		$is_woocommerce = isset( $event['trigger_type'] ) && 'woocommerce' === $event['trigger_type'];
		$configuration  = isset( $event['woocommerce'] ) ? $this->normalize_configuration( $event['woocommerce'], false ) : $this->get_configuration_defaults();
		$event['woocommerce'] = $configuration;

		$has_woocommerce_source = false;
		foreach ( isset( $event['parameters'] ) && is_array( $event['parameters'] ) ? $event['parameters'] : array() as $parameter ) {
			if ( is_array( $parameter ) && isset( $parameter['source'] ) && 'woocommerce_order' === $parameter['source'] ) {
				$has_woocommerce_source = true;
			}
		}
		foreach ( isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array() as $mapping ) {
			if ( is_array( $mapping ) && isset( $mapping['source'] ) && 'woocommerce_billing' === $mapping['source'] ) {
				$has_woocommerce_source = true;
			}
		}

		if ( ! $is_woocommerce ) {
			if ( $has_woocommerce_source || '' !== $configuration['event'] || '' !== $configuration['status'] || $configuration['purchase_preset'] ) {
				$errors[] = __( 'WooCommerce-bronnen en instellingen vereisen een WooCommerce-trigger.', 'eventbridge' );
			}
			$event['woocommerce'] = $this->get_configuration_defaults();
			return array( 'event' => $event, 'errors' => $errors );
		}

		if ( ! $this->is_available() && ! $this->matches_existing_projection( $event, $existing_event ) ) {
			$errors[] = __( 'WooCommerce is niet beschikbaar of niet ondersteund. Bestaande WooCommerce-configuratie kan behouden maar niet gewijzigd worden.', 'eventbridge' );
		}

		if ( true === (bool) $event['browser'] ) {
			$errors[] = __( 'WooCommerce-lifecycleevents kunnen niet via de browser worden verstuurd.', 'eventbridge' );
		}
		if ( true !== (bool) $event['capi'] ) {
			$errors[] = __( 'WooCommerce-lifecycleevents vereisen Meta Conversion API.', 'eventbridge' );
		}

		if ( ! in_array( $configuration['event'], array( 'created', 'paid', 'status' ), true ) ) {
			$errors[] = __( 'Kies een geldige WooCommerce-gebeurtenis.', 'eventbridge' );
		}
		if ( 'status' === $configuration['event'] ) {
			if ( '' === $configuration['status'] ) {
				$errors[] = __( 'Kies een WooCommerce-orderstatus.', 'eventbridge' );
			} elseif ( $this->is_available() && ! isset( $this->get_order_statuses()[ $configuration['status'] ] ) ) {
				$errors[] = __( 'De gekozen WooCommerce-orderstatus is niet beschikbaar.', 'eventbridge' );
			}
		} else {
			$event['woocommerce']['status'] = '';
		}

		if ( isset( $event['data_source']['provider'] ) && '' !== $event['data_source']['provider'] ) {
			$errors[] = __( 'Een WooCommerce-trigger kan geen Fluent Booking-databron gebruiken.', 'eventbridge' );
		}

		$order_fields = $this->get_order_parameter_fields();
		$reserved     = array( 'value', 'currency', 'order_id', 'content_type', 'content_ids', 'contents', 'num_items' );
		foreach ( $event['parameters'] as $parameter ) {
			if ( ! is_array( $parameter ) ) {
				continue;
			}
			if ( ! in_array( $parameter['source'], array( 'static', 'woocommerce_order' ), true ) ) {
				$errors[] = __( 'WooCommerce-events ondersteunen alleen vaste parameters en WooCommerce-orderwaarden.', 'eventbridge' );
			}
			if ( 'woocommerce_order' === $parameter['source'] && ! isset( $order_fields[ $parameter['value'] ] ) ) {
				$errors[] = __( 'Een gekozen WooCommerce-orderveld is ongeldig.', 'eventbridge' );
			}
			if ( $configuration['purchase_preset'] && in_array( $parameter['name'], $reserved, true ) ) {
				$errors[] = sprintf( __( 'Parameter "%s" wordt door de WooCommerce Purchase-preset gereserveerd.', 'eventbridge' ), $parameter['name'] );
			}
		}

		$billing_fields = $this->get_billing_field_map();
		foreach ( $event['advanced_matching'] as $field => $mapping ) {
			if ( ! is_array( $mapping ) || '' === $mapping['source'] ) {
				continue;
			}
			if ( 'woocommerce_billing' !== $mapping['source']
				|| ! isset( $billing_fields[ $field ] )
				|| $billing_fields[ $field ] !== $mapping['value']
			) {
				$errors[] = __( 'WooCommerce Advanced Matching vereist de vaste bijbehorende facturatievelden.', 'eventbridge' );
			}
		}

		return array(
			'event'  => $event,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	public function handle_new_order( $order_id, $order = null ) {
		$order_id = absint( $order_id );
		if ( $order_id < 1 || ! is_a( $order, 'WC_Abstract_Order' ) ) {
			$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, '', '', '', 'created', '', false, 'invalid_created_hook_order' );
			return;
		}

		if ( $order_id !== absint( $order->get_id() ) ) {
			$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, '', '', '', 'created', '', false, 'created_hook_order_mismatch' );
			return;
		}

		if ( ! $this->is_supported_order( $order ) ) {
			$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, '', '', '', 'created', $order->get_status(), false, 'unsupported_order_type' );
			return;
		}

		if ( $this->is_transient_order_status( $order->get_status() ) ) {
			return;
		}

		$this->created_order_ids[ $order_id ] = true;
	}

	public function flush_created_orders() {
		$order_ids              = array_keys( $this->created_order_ids );
		$this->created_order_ids = array();

		foreach ( $order_ids as $order_id ) {
			$this->dispatch_signal( $order_id, 'created' );
		}
	}

	public function handle_payment_complete( $order_id, $transaction_id = '' ) {
		$this->dispatch_signal( absint( $order_id ), 'paid' );
	}

	public function handle_status_changed( $order_id, $from, $to, $order = null ) {
		$from = $this->normalize_status( $from );
		$to   = $this->normalize_status( $to );

		if ( '' === $to || $from === $to ) {
			return;
		}

		$this->dispatch_signal( absint( $order_id ), 'status', $to );

		$paid_statuses = array_map( array( $this, 'normalize_status' ), wc_get_is_paid_statuses() );
		if ( ! in_array( $from, $paid_statuses, true ) && in_array( $to, $paid_statuses, true ) ) {
			$this->dispatch_signal( absint( $order_id ), 'paid', $to );
		}
	}

	private function dispatch_signal( $order_id, $signal, $status = '' ) {
		if ( ! $this->is_available() || ! $this->events || $order_id < 1 ) {
			return;
		}

		$matching_events = $this->get_matching_events( $signal, $status );
		if ( empty( $matching_events ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			if ( 'created' !== $signal ) {
				$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, '', '', '', $signal, $status, false, 'order_reload_missing' );
			}
			return;
		}
		if ( ! $this->is_supported_order( $order ) ) {
			$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, '', '', '', $signal, $status, false, 'unsupported_order_type' );
			return;
		}
		if ( 'created' === $signal && $this->is_transient_order_status( $order->get_status() ) ) {
			return;
		}

		if ( 'paid' === $signal ) {
			$paid_statuses = array_map( array( $this, 'normalize_status' ), wc_get_is_paid_statuses() );
			if ( ! in_array( $this->normalize_status( $order->get_status() ), $paid_statuses, true ) ) {
				return;
			}
		}

		$conditional_rows = array();
		foreach ( $matching_events as $route_record ) {
			$event = $route_record['event'];
			if ( isset( $event['conditions'] ) && is_array( $event['conditions'] ) && ! empty( $event['conditions'] ) ) {
				$conditional_rows = array_merge( $conditional_rows, $event['conditions'] );
			}
		}

		$condition_context = array();
		if ( ! empty( $conditional_rows ) && $this->conditions ) {
			$condition_context = $this->conditions->build_context(
				'woocommerce',
				array(
					'type'   => 'woocommerce',
					'signal' => $signal,
					'status' => $status,
				),
				$order,
				$conditional_rows
			);
		}

		foreach ( $matching_events as $route_record ) {
			$event_key        = $route_record['event_key'];
			$trigger_id       = $route_record['trigger_id'];
			$event            = $route_record['event'];
			$compatibility    = $route_record['compatibility'];
			$event_conditions = isset( $event['conditions'] ) ? $event['conditions'] : array();
			if ( ! empty( $event_conditions ) ) {
				if ( ! $this->conditions ) {
					continue;
				}
				$evaluation = $this->conditions->evaluate( $event_conditions, $condition_context, $event_key, $trigger_id );
				if ( ! isset( $evaluation['status'] ) || 'match' !== $evaluation['status'] ) {
					continue;
				}
			}

			$logical_trigger = 'status' === $signal ? 'status:' . $status : $signal;
			$this->dispatch_event( $order_id, $event_key, $trigger_id, $event, $logical_trigger, $status, $compatibility );
		}
	}

	private function get_matching_events( $signal, $status ) {
		$matching_events = array();

		foreach ( $this->events->get_normalized_events() as $event_key => $stored_event ) {
			if ( ! is_string( $event_key )
				|| true !== (bool) $stored_event['enabled']
				|| EventBridge_Triggers::FAMILY_SERVER !== $this->events->get_event_family( $stored_event )
			) {
				continue;
			}

			foreach ( isset( $stored_event['triggers'] ) && is_array( $stored_event['triggers'] ) ? $stored_event['triggers'] : array() as $trigger ) {
				if ( ! is_array( $trigger )
					|| ! isset( $trigger['trigger_id'], $trigger['provider'], $trigger['trigger_type'] )
					|| ! $this->events->is_valid_trigger_id( $trigger['trigger_id'] )
					|| 'woocommerce' !== $trigger['provider']
					|| 'order_lifecycle' !== $trigger['trigger_type']
				) {
					continue;
				}

				$event = $this->events->get_effective_event( $stored_event, $trigger );
				if ( ! is_array( $event )
					|| 'woocommerce' !== $event['trigger_type']
					|| true !== (bool) $event['capi']
					|| true === (bool) $event['browser']
					|| ! isset( $event['woocommerce']['event'] )
					|| $signal !== $event['woocommerce']['event']
					|| ( 'status' === $signal && $status !== $event['woocommerce']['status'] )
				) {
					continue;
				}

				$matching_events[] = array(
					'event_key'     => $event_key,
					'trigger_id'    => $trigger['trigger_id'],
					'event'         => $event,
					'compatibility' => ! empty( $event['eventbridge_is_compatibility_trigger'] ),
				);
			}
		}

		return $matching_events;
	}

	private function dispatch_event( $order_id, $event_key, $trigger_id, $event, $logical_trigger, $status, $compatibility ) {
		$test_mode   = true === (bool) $event['meta_test_mode'];
		$logical_key = 'v2|' . $event_key . '|' . $trigger_id . '|' . $logical_trigger;
		$legacy_key  = $event_key . '|' . $logical_trigger;
		$lock       = $this->acquire_lock( $order_id, $logical_key, $test_mode );

		if ( false === $lock ) {
			$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], '', $logical_trigger, $status, $test_mode, 'lock_unavailable', $trigger_id );
			return;
		}

		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], '', $logical_trigger, $status, $test_mode, 'order_disappeared_during_dispatch', $trigger_id );
				return;
			}
			if ( ! $this->is_supported_order( $order ) ) {
				$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], '', $logical_trigger, $status, $test_mode, 'unsupported_order_during_dispatch', $trigger_id );
				return;
			}

			$meta_key = $test_mode ? self::LEDGER_TEST_META : self::LEDGER_PRODUCTION_META;
			$mode_version = $test_mode ? 'test_v2' : 'prod_v2';
			$legacy_mode_version = $test_mode ? 'test_v1' : 'prod_v1';
			$ledger   = $this->normalize_ledger( $order->get_meta( $meta_key, true ), $mode_version );
			$entry    = isset( $ledger['entries'][ $logical_key ] ) ? $ledger['entries'][ $logical_key ] : null;
			$legacy_entry = $compatibility && isset( $ledger['entries'][ $legacy_key ] ) ? $ledger['entries'][ $legacy_key ] : null;
			$aliases_changed = false;

			if ( is_array( $entry ) && is_array( $legacy_entry ) && ! $this->ledger_entries_match( $entry, $legacy_entry ) ) {
				$this->log_dispatch( 'error', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], '', $logical_trigger, $status, $test_mode, 'ledger_alias_conflict', $trigger_id );
				return;
			}
			if ( null === $entry && is_array( $legacy_entry ) ) {
				$entry                = $legacy_entry;
				$entry['version']     = $mode_version;
				$entry['logical_key'] = $logical_key;
				$ledger['entries'][ $logical_key ] = $entry;
				$aliases_changed = true;
			}
			if ( $compatibility && is_array( $entry ) && null === $legacy_entry ) {
				$ledger['entries'][ $legacy_key ] = $this->create_ledger_alias( $entry, $legacy_key, $legacy_mode_version );
				$aliases_changed = true;
			}
			if ( $aliases_changed
				&& ( ! $this->save_ledger( $order, $meta_key, $ledger, $logical_key, $entry['event_id'], $mode_version )
					|| ! $this->saved_ledger_entry_matches( $order, $meta_key, $legacy_key, $entry['event_id'], $legacy_mode_version ) )
			) {
				$this->log_dispatch( 'error', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, 'ledger_write_failed', $trigger_id );
				return;
			}

			if ( is_array( $entry ) && 'started' === $entry['state'] ) {
				return;
			}
			if ( null === $entry ) {
				if ( $this->get_ledger_route_count( $ledger ) >= self::LEDGER_MAX_ENTRIES ) {
					$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], '', $logical_trigger, $status, $test_mode, 'ledger_full', $trigger_id );
					return;
				}

				$entry = array(
					'version'      => $mode_version,
					'logical_key'  => $logical_key,
					'event_id'     => $this->generate_uuid(),
					'event_time'   => $this->get_event_time( $order, $logical_trigger ),
					'state'        => 'pending',
					'attempts'     => 0,
					'created_at'   => time(),
					'updated_at'   => time(),
				);
				$ledger['entries'][ $logical_key ] = $entry;
				if ( $compatibility ) {
					$ledger['entries'][ $legacy_key ] = $this->create_ledger_alias( $entry, $legacy_key, $legacy_mode_version );
				}
				if ( ! $this->save_ledger( $order, $meta_key, $ledger, $logical_key, $entry['event_id'], $mode_version )
					|| ( $compatibility && ! $this->saved_ledger_entry_matches( $order, $meta_key, $legacy_key, $entry['event_id'], $legacy_mode_version ) )
				) {
					$this->log_dispatch( 'error', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, 'ledger_write_failed', $trigger_id );
					return;
				}
			}

			$snapshot = $this->create_order_snapshot( $order );
			if ( ! is_array( $snapshot ) ) {
				$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, 'snapshot_invalid', $trigger_id );
				return;
			}

			$custom_data = $this->events->get_parameter_map(
				$event,
				array(),
				array(),
				$this->get_order_parameter_values( $snapshot )
			);

			if ( true === (bool) $event['woocommerce']['purchase_preset'] ) {
				$preset = $this->build_purchase_data( $snapshot );
				if ( ! $preset['success'] ) {
					$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, $preset['reason'], $trigger_id );
					return;
				}
				$custom_data = array_merge( $custom_data, $preset['data'] );
			}

			$advanced_user_data = $this->get_advanced_user_data( $event, $order );
			$details            = array(
				'event_key'  => $event_key,
				'trigger_id' => $trigger_id,
				'event_name' => $event['event_name'],
				'event_id'   => $entry['event_id'],
				'context'    => array(
					'order_id'  => $order_id,
					'trigger'   => $logical_trigger,
					'status'    => $status,
					'test_mode' => $test_mode,
				),
			);

			$started = $this->meta_capi->send_server_event(
				$event['event_name'],
				$entry['event_id'],
				$entry['event_time'],
				$this->get_event_source_url(),
				$custom_data,
				$details,
				$advanced_user_data,
				$event
			);

			$entry['attempts']   = min( 2147483647, absint( $entry['attempts'] ) + 1 );
			$entry['updated_at'] = time();
			if ( $started ) {
				$entry['state'] = 'started';
			}
			$ledger['entries'][ $logical_key ] = $entry;
			if ( $compatibility ) {
				$ledger['entries'][ $legacy_key ] = $this->create_ledger_alias( $entry, $legacy_key, $legacy_mode_version );
			}

			if ( ! $this->save_ledger( $order, $meta_key, $ledger, $logical_key, $entry['event_id'], $mode_version )
				|| ( $compatibility && ! $this->saved_ledger_entry_matches( $order, $meta_key, $legacy_key, $entry['event_id'], $legacy_mode_version ) )
			) {
				$this->log_dispatch( 'error', 'WooCommerce event ledger update failed.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, 'ledger_finalize_failed', $trigger_id );
			} elseif ( ! $started ) {
				$this->log_dispatch( 'warning', 'WooCommerce event dispatch skipped.', $order_id, $event_key, $event['event_name'], $entry['event_id'], $logical_trigger, $status, $test_mode, 'capi_not_started', $trigger_id );
			}
		} finally {
			$this->release_lock( $lock );
		}
	}

	private function create_order_snapshot( $order ) {
		$amounts = array(
			'total'          => $this->normalize_amount( $order->get_total() ),
			'subtotal'       => $this->normalize_amount( $order->get_subtotal() ),
			'tax_total'      => $this->normalize_amount( $order->get_total_tax() ),
			'shipping_total' => $this->normalize_amount( $order->get_shipping_total() ),
			'discount_total' => $this->normalize_amount( $order->get_discount_total() ),
		);
		if ( in_array( null, $amounts, true ) ) {
			return false;
		}

		$raw_items       = $order->get_items( 'line_item' );
		$line_item_count = is_array( $raw_items ) ? count( $raw_items ) : 0;
		$items_truncated = $line_item_count > self::MAX_LINE_ITEMS;
		$items           = array();
		$total_quantity  = 0;

		foreach ( array_slice( is_array( $raw_items ) ? $raw_items : array(), 0, self::MAX_LINE_ITEMS, true ) as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$quantity = $this->normalize_quantity( $item->get_quantity() );
			$line_total = $this->normalize_amount( $item->get_total() );
			$line_tax   = $this->normalize_amount( $item->get_total_tax() );
			if ( null === $quantity || null === $line_total || null === $line_tax ) {
				continue;
			}

			$product = $item->get_product();
			$items[] = array(
				'product_id'   => absint( $item->get_product_id() ),
				'variation_id' => absint( $item->get_variation_id() ),
				'sku'          => $product && is_callable( array( $product, 'get_sku' ) ) ? $this->bounded_string( $product->get_sku(), self::MAX_IDENTIFIER_LENGTH ) : '',
				'name'         => $this->bounded_string( $item->get_name(), self::MAX_STRING_LENGTH ),
				'quantity'     => $quantity,
				'line_total'   => $line_total,
				'line_tax'     => $line_tax,
			);
			$total_quantity = min( self::MAX_QUANTITY * self::MAX_LINE_ITEMS, $total_quantity + $quantity );
		}

		$raw_coupons       = $order->get_coupon_codes();
		$coupons_truncated = is_array( $raw_coupons ) && count( $raw_coupons ) > self::MAX_COUPONS;
		$coupons           = array();
		foreach ( array_slice( is_array( $raw_coupons ) ? $raw_coupons : array(), 0, self::MAX_COUPONS ) as $coupon ) {
			$coupon = $this->bounded_string( $coupon, self::MAX_IDENTIFIER_LENGTH );
			if ( '' !== $coupon ) {
				$coupons[] = $coupon;
			}
		}

		$date_created = $order->get_date_created();
		$date_paid    = $order->get_date_paid();

		return array_merge(
			array(
				'order_id'               => absint( $order->get_id() ),
				'order_number'           => $this->bounded_string( $order->get_order_number(), self::MAX_IDENTIFIER_LENGTH ),
				'status'                 => $this->normalize_status( $order->get_status() ),
				'currency'               => strtoupper( $this->bounded_string( $order->get_currency(), 3 ) ),
				'payment_method_id'      => $this->bounded_string( $order->get_payment_method(), self::MAX_IDENTIFIER_LENGTH ),
				'payment_method_title'   => $this->bounded_string( $order->get_payment_method_title(), self::MAX_STRING_LENGTH ),
				'date_created'           => $this->format_date( $date_created ),
				'date_paid'              => $this->format_date( $date_paid ),
				'line_item_count'        => $line_item_count,
				'product_quantity_total' => $total_quantity,
				'coupon_codes'           => $coupons,
				'items'                  => $items,
				'items_truncated'        => $items_truncated,
				'coupons_truncated'      => $coupons_truncated,
			),
			$amounts
		);
	}

	private function get_order_parameter_values( $snapshot ) {
		$values = $snapshot;
		unset( $values['items'], $values['items_truncated'], $values['coupons_truncated'] );
		$values['coupon_codes'] = $this->join_coupon_codes( $snapshot['coupon_codes'] );

		return $values;
	}

	private function build_purchase_data( $snapshot ) {
		if ( true === $snapshot['items_truncated'] ) {
			return array( 'success' => false, 'reason' => 'purchase_item_limit_exceeded', 'data' => array() );
		}
		if ( ! $this->is_valid_currency( $snapshot['currency'] ) ) {
			return array( 'success' => false, 'reason' => 'purchase_currency_invalid', 'data' => array() );
		}

		$content_ids = array();
		$contents    = array();
		$num_items   = 0;

		foreach ( $snapshot['items'] as $item ) {
			$content_id = $item['variation_id'] > 0 ? $item['variation_id'] : $item['product_id'];
			if ( $content_id < 1 ) {
				continue;
			}

			$item_price = $this->normalize_amount( ( $item['line_total'] + $item['line_tax'] ) / $item['quantity'] );
			if ( null === $item_price ) {
				continue;
			}

			$id = (string) $content_id;
			if ( ! in_array( $id, $content_ids, true ) ) {
				$content_ids[] = $id;
			}
			$contents[] = array(
				'id'         => $id,
				'quantity'   => $item['quantity'],
				'item_price' => $item_price,
			);
			$num_items += $item['quantity'];
		}

		if ( empty( $contents ) ) {
			return array( 'success' => false, 'reason' => 'purchase_contents_empty', 'data' => array() );
		}

		return array(
			'success' => true,
			'reason'  => '',
			'data'    => array(
				'value'        => $snapshot['total'],
				'currency'     => $snapshot['currency'],
				'order_id'     => $snapshot['order_number'],
				'content_type' => 'product',
				'content_ids'  => $content_ids,
				'contents'     => $contents,
				'num_items'    => $num_items,
			),
		);
	}

	protected function get_advanced_user_data( $event, $order ) {
		$values = array();
		$map    = $this->get_billing_field_map();

		foreach ( $map as $field => $billing_field ) {
			if ( ! isset( $event['advanced_matching'][ $field ]['source'], $event['advanced_matching'][ $field ]['value'] )
				|| 'woocommerce_billing' !== $event['advanced_matching'][ $field ]['source']
				|| $billing_field !== $event['advanced_matching'][ $field ]['value']
			) {
				continue;
			}

			$getter = 'get_' . $billing_field;
			if ( is_callable( array( $order, $getter ) ) ) {
				$value = $order->{$getter}();
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$values[ $field ] = $value;
				}
			}
		}

		return $this->events->get_advanced_matching_user_data( $values );
	}

	private function get_event_source_url() {
		$url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
		$url = EventBridge_Meta_URL::canonicalize( is_string( $url ) ? $url : '' );

		return '' !== $url ? $url : EventBridge_Meta_URL::canonicalize( home_url( '/' ) );
	}

	private function get_event_time( $order, $logical_trigger ) {
		$date = 'created' === $logical_trigger ? $order->get_date_created() : ( 'paid' === $logical_trigger ? $order->get_date_paid() : null );

		return is_object( $date ) && is_callable( array( $date, 'getTimestamp' ) ) ? max( 1, absint( $date->getTimestamp() ) ) : time();
	}

	private function normalize_ledger( $ledger, $mode_version = '' ) {
		$entries = array();
		if ( is_array( $ledger ) && isset( $ledger['entries'] ) && is_array( $ledger['entries'] ) ) {
			foreach ( array_slice( $ledger['entries'], 0, self::LEDGER_MAX_ENTRIES * 2, true ) as $key => $entry ) {
				if ( ! is_string( $key )
					|| ! is_array( $entry )
					|| ! isset( $entry['event_id'], $entry['event_time'], $entry['state'] )
					|| ! is_string( $entry['event_id'] )
					|| ! wp_is_uuid( $entry['event_id'], 4 )
					|| ! in_array( $entry['state'], array( 'pending', 'started' ), true )
				) {
					continue;
				}
				$entries[ $key ] = array(
					'version'    => isset( $entry['version'] ) && is_string( $entry['version'] ) ? $entry['version'] : $mode_version,
					'logical_key' => isset( $entry['logical_key'] ) && is_string( $entry['logical_key'] ) ? $entry['logical_key'] : $key,
					'event_id'   => $entry['event_id'],
					'event_time' => max( 1, absint( $entry['event_time'] ) ),
					'state'      => $entry['state'],
					'attempts'   => isset( $entry['attempts'] ) ? min( 2147483647, absint( $entry['attempts'] ) ) : 0,
					'created_at' => isset( $entry['created_at'] ) ? absint( $entry['created_at'] ) : 0,
					'updated_at' => isset( $entry['updated_at'] ) ? absint( $entry['updated_at'] ) : 0,
				);
			}
		}

		return array(
			'version' => 1,
			'entries' => $entries,
		);
	}

	private function save_ledger( $order, $meta_key, $ledger, $logical_key, $event_id, $expected_version = '' ) {
		$order->update_meta_data( $meta_key, $ledger );
		$order->save_meta_data();

		$mode_version = '' !== $expected_version ? $expected_version : ( self::LEDGER_TEST_META === $meta_key ? 'test_v1' : 'prod_v1' );
		$stored       = $this->normalize_ledger( $order->get_meta( $meta_key, true ), $mode_version );

		return isset( $stored['entries'][ $logical_key ]['event_id'] )
			&& $event_id === $stored['entries'][ $logical_key ]['event_id']
			&& $logical_key === $stored['entries'][ $logical_key ]['logical_key']
			&& $mode_version === $stored['entries'][ $logical_key ]['version'];
	}

	private function saved_ledger_entry_matches( $order, $meta_key, $logical_key, $event_id, $expected_version ) {
		$stored = $this->normalize_ledger( $order->get_meta( $meta_key, true ), $expected_version );

		return isset( $stored['entries'][ $logical_key ] )
			&& $event_id === $stored['entries'][ $logical_key ]['event_id']
			&& $logical_key === $stored['entries'][ $logical_key ]['logical_key']
			&& $expected_version === $stored['entries'][ $logical_key ]['version'];
	}

	private function create_ledger_alias( $entry, $logical_key, $version ) {
		$alias                = $entry;
		$alias['logical_key'] = $logical_key;
		$alias['version']     = $version;

		return $alias;
	}

	private function ledger_entries_match( $left, $right ) {
		foreach ( array( 'event_id', 'event_time', 'state', 'attempts' ) as $field ) {
			if ( ! isset( $left[ $field ], $right[ $field ] ) || (string) $left[ $field ] !== (string) $right[ $field ] ) {
				return false;
			}
		}

		return true;
	}

	private function get_ledger_route_count( $ledger ) {
		$entries = isset( $ledger['entries'] ) && is_array( $ledger['entries'] ) ? $ledger['entries'] : array();
		$count   = 0;

		foreach ( $entries as $logical_key => $entry ) {
			if ( 0 === strpos( $logical_key, 'v2|' ) ) {
				$count++;
				continue;
			}

			$parts = explode( '|', $logical_key, 2 );
			if ( 2 !== count( $parts ) || ! preg_match( '/^evt_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $parts[0] ) ) {
				$count++;
				continue;
			}

			$canonical = 'v2|' . $parts[0] . '|trg_' . substr( $parts[0], 4 ) . '|' . $parts[1];
			if ( ! isset( $entries[ $canonical ] ) ) {
				$count++;
			}
		}

		return $count;
	}

	private function acquire_lock( $order_id, $logical_key, $test_mode ) {
		global $wpdb;

		if ( ! isset( $wpdb->options ) ) {
			return false;
		}

		$name  = self::LOCK_PREFIX . hash_hmac( 'sha256', $order_id . '|' . ( $test_mode ? 'test' : 'prod' ), wp_salt( 'auth' ) );
		$now   = time();
		$token = hash( 'sha256', $this->generate_uuid() . '|' . microtime( true ) . '|' . wp_rand() );
		$value = ( $now + self::LOCK_TTL ) . '|' . $token;

		if ( add_option( $name, $value, '', false ) ) {
			return array( 'name' => $name, 'value' => $value );
		}

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
				SET option_value = %s, autoload = 'no'
				WHERE option_name = %s
				AND (
					option_value NOT REGEXP '^[0-9]+[|][a-f0-9]{64}$'
					OR CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d
				)",
				$value,
				$name,
				$now
			)
		);
		wp_cache_delete( $name, 'options' );

		return 1 === $result ? array( 'name' => $name, 'value' => $value ) : false;
	}

	private function release_lock( $lock ) {
		global $wpdb;

		if ( ! is_array( $lock ) || ! isset( $lock['name'], $lock['value'] ) ) {
			return;
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$lock['name'],
				$lock['value']
			)
		);
		wp_cache_delete( $lock['name'], 'options' );
	}

	protected function generate_uuid() {
		return wp_generate_uuid4();
	}

	private function matches_existing_projection( $event, $existing_event ) {
		if ( ! is_array( $existing_event ) || ! isset( $existing_event['trigger_type'] ) || 'woocommerce' !== $existing_event['trigger_type'] ) {
			return false;
		}

		$projection = $this->get_woocommerce_projection( $event );
		$existing   = $this->get_woocommerce_projection( $existing_event );

		return wp_json_encode( $projection ) === wp_json_encode( $existing );
	}

	private function get_woocommerce_projection( $event ) {
		$parameters = array();
		foreach ( isset( $event['parameters'] ) && is_array( $event['parameters'] ) ? $event['parameters'] : array() as $parameter ) {
			if ( is_array( $parameter ) && isset( $parameter['source'] ) && 'woocommerce_order' === $parameter['source'] ) {
				$parameters[] = $parameter;
			}
		}

		$advanced_matching = array();
		foreach ( isset( $event['advanced_matching'] ) && is_array( $event['advanced_matching'] ) ? $event['advanced_matching'] : array() as $field => $mapping ) {
			if ( is_array( $mapping ) && isset( $mapping['source'] ) && 'woocommerce_billing' === $mapping['source'] ) {
				$advanced_matching[ $field ] = $mapping;
			}
		}

		return array(
			'trigger_type'      => isset( $event['trigger_type'] ) ? $event['trigger_type'] : '',
			'browser'           => isset( $event['browser'] ) ? (bool) $event['browser'] : false,
			'capi'              => isset( $event['capi'] ) ? (bool) $event['capi'] : false,
			'data_source'       => isset( $event['data_source']['provider'] ) ? $event['data_source']['provider'] : '',
			'parameters'        => $parameters,
			'advanced_matching' => $advanced_matching,
			'woocommerce'       => isset( $event['woocommerce'] ) ? $this->normalize_configuration( $event['woocommerce'], false ) : $this->get_configuration_defaults(),
		);
	}

	private function is_plugin_active() {
		if ( ! function_exists( 'is_plugin_active' ) && defined( 'ABSPATH' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' )
			&& (
				is_plugin_active( 'woocommerce/woocommerce.php' )
				|| ( is_multisite() && is_plugin_active_for_network( 'woocommerce/woocommerce.php' ) )
			);
	}

	private function is_supported_order( $order ) {
		return is_a( $order, 'WC_Order' )
			&& ! is_a( $order, 'WC_Order_Refund' )
			&& is_callable( array( $order, 'get_type' ) )
			&& 'shop_order' === $order->get_type();
	}

	private function is_transient_order_status( $status ) {
		return in_array( $this->normalize_status( $status ), array( 'auto-draft', 'draft', 'checkout-draft' ), true );
	}

	private function normalize_status( $status ) {
		if ( ! is_scalar( $status ) ) {
			return '';
		}

		$status = sanitize_key( (string) $status );
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	private function normalize_quantity( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$float = (float) $value;
		if ( ! is_finite( $float ) || $float < 1 || $float > self::MAX_QUANTITY || floor( $float ) !== $float ) {
			return null;
		}

		return (int) $float;
	}

	private function normalize_amount( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$amount = (float) $value;
		if ( ! is_finite( $amount ) || abs( $amount ) > self::MAX_AMOUNT ) {
			return null;
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return round( $amount, min( 8, max( 0, absint( $decimals ) ) ) );
	}

	private function bounded_string( $value, $maximum_length ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( (string) $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $maximum_length ) : substr( $value, 0, $maximum_length );
	}

	private function format_date( $date ) {
		if ( ! is_object( $date ) || ! is_callable( array( $date, 'getTimestamp' ) ) ) {
			return '';
		}

		return gmdate( 'c', $date->getTimestamp() );
	}

	private function join_coupon_codes( $coupons ) {
		$joined = '';
		foreach ( is_array( $coupons ) ? $coupons : array() as $coupon ) {
			$candidate = '' === $joined ? $coupon : $joined . ',' . $coupon;
			if ( strlen( $candidate ) > self::MAX_COUPON_STRING ) {
				break;
			}
			$joined = $candidate;
		}

		return $joined;
	}

	private function is_valid_currency( $currency ) {
		if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
			return false;
		}

		$currencies = function_exists( 'get_woocommerce_currencies' ) ? get_woocommerce_currencies() : array();
		return isset( $currencies[ $currency ] );
	}

	private function log_dispatch( $level, $message, $order_id, $event_key, $event_name, $event_id, $trigger, $status, $test_mode, $reason, $trigger_id = '' ) {
		$this->log->log(
			$level,
			'woocommerce',
			$message,
			array(
				'event_key'  => $event_key,
				'trigger_id' => $trigger_id,
				'event_name' => $event_name,
				'event_id'   => $event_id,
				'context'    => array(
					'order_id'  => absint( $order_id ),
					'trigger'   => sanitize_text_field( (string) $trigger ),
					'status'    => $this->normalize_status( $status ),
					'test_mode' => true === $test_mode,
					'reason'    => sanitize_key( $reason ),
				),
			)
		);
	}
}
