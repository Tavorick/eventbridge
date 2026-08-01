<?php

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

$command = isset( $argv[1] ) ? strtolower( trim( (string) $argv[1] ) ) : '';
$storage = isset( $argv[2] ) ? strtolower( trim( (string) $argv[2] ) ) : 'current';
if ( ! in_array( $command, array( 'create', 'run', 'cleanup' ), true ) ) {
	fwrite( STDERR, "Usage: php tests/local-woocommerce-harness.php create|run|cleanup [current|hpos|classic]\n" );
	exit( 1 );
}
if ( 'run' === $command && ! in_array( $storage, array( 'current', 'hpos', 'classic' ), true ) ) {
	fwrite( STDERR, "Storage mode must be current, hpos or classic.\n" );
	exit( 1 );
}

$wordpress_root = dirname( __DIR__, 4 );
require $wordpress_root . '/wp-load.php';

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: wp_get_environment_type() must be local.\n" );
	exit( 1 );
}

$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
if ( ! in_array( strtolower( (string) $home_host ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	fwrite( STDERR, "Refusing to run: WordPress must use localhost or a loopback host.\n" );
	exit( 1 );
}

if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, EventBridge_WooCommerce::MINIMUM_VERSION, '<' ) || ! function_exists( 'wc_get_order' ) ) {
	fwrite( STDERR, "Refusing to run: a supported WooCommerce version is required.\n" );
	exit( 1 );
}

const EVENTBRIDGE_WC_TEST_MANIFEST = 'eventbridge_wc_test_manifest_v1';
const EVENTBRIDGE_WC_TEST_MARKER   = '_eventbridge_test_run_id';

function eventbridge_wc_test_manifest() {
	$manifest = get_option( EVENTBRIDGE_WC_TEST_MANIFEST, array() );
	return is_array( $manifest ) ? $manifest : array();
}

function eventbridge_wc_save_manifest( $manifest ) {
	update_option( EVENTBRIDGE_WC_TEST_MANIFEST, $manifest, false );
}

function eventbridge_wc_count_order_warnings( $order_id ) {
	global $wpdb;

	$table   = $wpdb->prefix . 'eventbridge_logs';
	$pattern = '%' . $wpdb->esc_like( '"order_id":' . absint( $order_id ) ) . '%';

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $table . ' WHERE source = %s AND level = %s AND context LIKE %s',
			'woocommerce',
			'warning',
			$pattern
		)
	);
}

function eventbridge_wc_assert_purchase_route( $event, $events ) {
	$errors  = array();
	$trigger = null;
	$triggers = isset( $event['triggers'] ) && is_array( $event['triggers'] ) ? $event['triggers'] : array();

	foreach ( $triggers as $candidate ) {
		if ( is_array( $candidate )
			&& isset( $candidate['provider'], $candidate['trigger_type'] )
			&& 'woocommerce' === $candidate['provider']
			&& 'order_lifecycle' === $candidate['trigger_type']
		) {
			$trigger = $candidate;
			break;
		}
	}

	if ( ! is_array( $trigger ) ) {
		throw new RuntimeException( 'Validated event did not contain a WooCommerce order_lifecycle trigger.' );
	}

	$effective = $events->get_effective_event( $event, $trigger );
	if ( ! is_array( $effective )
		|| ! isset( $effective['trigger_provider'], $effective['trigger_type'], $effective['woocommerce'] )
		|| 'woocommerce' !== $effective['trigger_provider']
		|| 'woocommerce' !== $effective['trigger_type']
		|| ! is_array( $effective['woocommerce'] )
		|| ! isset( $effective['woocommerce']['event'], $effective['woocommerce']['purchase_preset'] )
		|| 'paid' !== $effective['woocommerce']['event']
		|| true !== (bool) $effective['woocommerce']['purchase_preset']
	) {
		$errors[] = 'effective WooCommerce Purchase route is invalid';
	}

	$parameter_found = false;
	foreach ( isset( $effective['parameters'] ) && is_array( $effective['parameters'] ) ? $effective['parameters'] : array() as $parameter ) {
		if ( is_array( $parameter )
			&& isset( $parameter['name'], $parameter['source'], $parameter['value'] )
			&& 'eventbridge_order_total' === $parameter['name']
			&& 'woocommerce_order' === $parameter['source']
			&& 'total' === $parameter['value']
		) {
			$parameter_found = true;
			break;
		}
	}
	if ( ! $parameter_found ) {
		$errors[] = 'effective WooCommerce parameters do not contain eventbridge_order_total=woocommerce_order/total';
	}

	if ( ! empty( $errors ) ) {
		throw new RuntimeException( 'Validated event route contract failed: ' . implode( ' | ', $errors ) );
	}
}

function eventbridge_wc_contract_value( $values, $key, &$missing ) {
	$missing = ! is_array( $values ) || ! array_key_exists( $key, $values );
	return $missing ? null : $values[ $key ];
}

function eventbridge_wc_purchase_contract_differences( $body, $order ) {
	$body        = is_array( $body ) ? $body : array();
	$event       = isset( $body['data'][0] ) && is_array( $body['data'][0] ) ? $body['data'][0] : array();
	$custom_data = isset( $event['custom_data'] ) && is_array( $event['custom_data'] ) ? $event['custom_data'] : array();
	$user_data   = isset( $event['user_data'] ) && is_array( $event['user_data'] ) ? $event['user_data'] : array();
	$differences = array();

	$add = function ( $field, $expected, $actual, $missing, $matches ) use ( &$differences ) {
		if ( $matches ) {
			return;
		}
		$differences[] = array(
			'field'       => $field,
			'expected'    => $expected,
			'actual'      => $missing ? null : $actual,
			'actual_type' => $missing ? 'missing' : gettype( $actual ),
			'missing'     => $missing,
		);
	};

	$value = eventbridge_wc_contract_value( $event, 'event_name', $missing );
	$add( 'event_name', 'Purchase', $value, $missing, ! $missing && 'Purchase' === $value );
	$value = eventbridge_wc_contract_value( $event, 'event_id', $missing );
	$add( 'event_id_present', true, $value, $missing, ! $missing && is_string( $value ) && '' !== $value );
	$value = eventbridge_wc_contract_value( $custom_data, 'value', $missing );
	$add( 'value', 20.0, $value, $missing, ! $missing && is_numeric( $value ) && 20.0 === (float) $value );
	$value = eventbridge_wc_contract_value( $custom_data, 'currency', $missing );
	$add( 'currency', 'EUR', $value, $missing, ! $missing && 'EUR' === $value );
	$value = eventbridge_wc_contract_value( $custom_data, 'num_items', $missing );
	$add( 'num_items', 2, $value, $missing, ! $missing && 2 === $value );
	$value = eventbridge_wc_contract_value( $custom_data, 'eventbridge_order_total', $missing );
	$add( 'eventbridge_order_total_present', true, $value, $missing, ! $missing );
	$add( 'eventbridge_order_total_type', 'integer|double', $value, $missing, ! $missing && ( is_int( $value ) || is_float( $value ) ) );
	$add( 'eventbridge_order_total_value', 20.0, $value, $missing, ! $missing && is_numeric( $value ) && 20.0 === (float) $value );
	$value = eventbridge_wc_contract_value( $user_data, 'client_ip_address', $missing );
	$add( 'client_ip_address', '203.0.113.24', $value, $missing, ! $missing && '203.0.113.24' === $value );
	$value = eventbridge_wc_contract_value( $user_data, 'client_user_agent', $missing );
	$add( 'client_user_agent', 'EventBridge local WooCommerce harness', $value, $missing, ! $missing && 'EventBridge local WooCommerce harness' === $value );
	foreach ( array(
		'em' => hash( 'sha256', 'eventbridge-buyer@example.test' ),
		'ph' => hash( 'sha256', '32470123456' ),
		'fn' => hash( 'sha256', 'event' ),
		'ln' => hash( 'sha256', 'bridge' ),
	) as $field => $expected ) {
		$value = eventbridge_wc_contract_value( $user_data, $field, $missing );
		$add( $field, $expected, $value, $missing, ! $missing && $expected === $value );
	}
	$value = eventbridge_wc_contract_value( $user_data, 'fbp', $missing );
	$add( 'fbp_present', false, $value, $missing, $missing );
	$value = eventbridge_wc_contract_value( $user_data, 'fbc', $missing );
	$add( 'fbc_present', false, $value, $missing, $missing );
	$value = eventbridge_wc_contract_value( $body, 'test_event_code', $missing );
	$add( 'test_event_code', 'TEST12345', $value, $missing, ! $missing && 'TEST12345' === $value );

	$item_quantity = $order->get_item_count();

	return array(
		'differences' => $differences,
		'keys'        => array(
			'top_level'   => array_values( array_diff( array_keys( $body ), array( 'access_token' ) ) ),
			'data_0'       => array_keys( $event ),
			'custom_data' => array_keys( $custom_data ),
			'user_data'   => array_keys( $user_data ),
		),
		'order'       => array(
			'total'          => $order->get_total(),
			'total_type'     => gettype( $order->get_total() ),
			'currency'       => $order->get_currency(),
			'currency_type'  => gettype( $order->get_currency() ),
			'item_quantity'      => $item_quantity,
			'item_quantity_type' => gettype( $item_quantity ),
		),
	);
}

function eventbridge_wc_assert_rest_ledger_protection( $order ) {
	$original_user = get_current_user_id();
	$shop_manager  = wp_insert_user(
		array(
			'user_login' => 'eventbridge-shop-manager-' . wp_generate_password( 8, false ),
			'user_pass'  => wp_generate_password( 24, true, true ),
			'user_email' => 'eventbridge-shop-' . wp_generate_password( 8, false ) . '@example.test',
			'role'       => 'shop_manager',
		)
	);
	if ( is_wp_error( $shop_manager ) ) {
		throw new RuntimeException( 'Unable to create the REST shop-manager fixture.' );
	}
	$administrators = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
	if ( empty( $administrators ) ) {
		throw new RuntimeException( 'Unable to find an administrator for the REST fixture.' );
	}
	$administrator = absint( $administrators[0] );

	$production = array( 'version' => 1, 'entries' => array( 'fixture' => array( 'event_id' => wp_generate_uuid4() ) ) );
	$test       = array( 'version' => 1, 'entries' => array( 'fixture' => array( 'event_id' => wp_generate_uuid4() ) ) );
	$order->update_meta_data( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, $production );
	$order->update_meta_data( EventBridge_WooCommerce::LEDGER_TEST_META, $test );
	$order->save_meta_data();
	$production_bytes = maybe_serialize( wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true ) );
	$test_bytes       = maybe_serialize( wc_get_order( $order->get_id() )->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true ) );
	$ledger_id = 0;
	foreach ( wc_get_order( $order->get_id() )->get_meta_data() as $meta ) {
		if ( EventBridge_WooCommerce::LEDGER_TEST_META === $meta->key ) {
			$ledger_id = absint( $meta->id );
		}
	}

	try {
		wp_set_current_user( $shop_manager );
		$get = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order->get_id() ) );
		if ( 200 !== $get->get_status() ) {
			throw new RuntimeException( 'Shop manager could not read the REST order fixture.' );
		}
		$keys = wp_list_pluck( isset( $get->get_data()['meta_data'] ) ? $get->get_data()['meta_data'] : array(), 'key' );
		if ( in_array( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, $keys, true ) || in_array( EventBridge_WooCommerce::LEDGER_TEST_META, $keys, true ) ) {
			throw new RuntimeException( 'A reserved ledger key was exposed through Woo REST.' );
		}

		foreach ( array( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, EventBridge_WooCommerce::LEDGER_TEST_META ) as $reserved_key ) {
			$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
			$request->set_body_params( array( 'meta_data' => array( array( 'key' => $reserved_key, 'value' => array() ) ) ) );
			if ( 403 !== rest_do_request( $request )->get_status() ) {
				throw new RuntimeException( 'Shop manager could write a reserved ledger key through Woo REST.' );
			}
		}

		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params( array( 'meta_data' => array( array( 'id' => $ledger_id, 'key' => 'eventbridge_renamed', 'value' => 'tampered' ) ) ) );
		if ( 403 !== rest_do_request( $request )->get_status() ) {
			throw new RuntimeException( 'Shop manager could rename a reserved ledger meta ID through Woo REST.' );
		}

		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params( array( 'meta_data' => array( array( 'key' => 'eventbridge_normal_meta', 'value' => 'allowed' ) ) ) );
		if ( 200 !== rest_do_request( $request )->get_status() || 'allowed' !== wc_get_order( $order->get_id() )->get_meta( 'eventbridge_normal_meta', true ) ) {
			throw new RuntimeException( 'Normal shop-manager order meta was blocked.' );
		}

		wp_set_current_user( $administrator );
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params( array( 'meta_data' => array( array( 'key' => EventBridge_WooCommerce::LEDGER_TEST_META, 'value' => array() ) ) ) );
		if ( 403 !== rest_do_request( $request )->get_status() ) {
			throw new RuntimeException( 'Administrator could write a reserved ledger key through Woo REST.' );
		}
		$request = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $order->get_id() );
		$request->set_body_params( array( 'meta_data' => array( array( 'key' => 'eventbridge_admin_normal_meta', 'value' => 'allowed' ) ) ) );
		if ( 200 !== rest_do_request( $request )->get_status() || 'allowed' !== wc_get_order( $order->get_id() )->get_meta( 'eventbridge_admin_normal_meta', true ) ) {
			throw new RuntimeException( 'Normal administrator order meta was blocked.' );
		}

		$stored = wc_get_order( $order->get_id() );
		if ( $production_bytes !== maybe_serialize( $stored->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true ) )
			|| $test_bytes !== maybe_serialize( $stored->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true ) )
		) {
			throw new RuntimeException( 'REST ledger protection changed existing ledger data.' );
		}
	} finally {
		wp_set_current_user( $original_user );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $shop_manager );
	}
}

function eventbridge_wc_create_fixtures() {
	if ( ! empty( eventbridge_wc_test_manifest() ) ) {
		throw new RuntimeException( 'A test manifest already exists. Run cleanup first.' );
	}

	$run_id   = wp_generate_uuid4();
	$manifest = array(
		'run_id'      => $run_id,
		'orders'      => array(),
		'products'    => array(),
		'variations'  => array(),
		'coupon'      => 0,
		'customer_id' => 0,
		'event_keys'  => array(),
	);

	$free = new WC_Product_Simple();
	$free->set_name( 'EventBridge Testproduct Gratis' );
	$free->set_status( 'publish' );
	$free->set_virtual( true );
	$free->set_regular_price( '0' );
	$free->set_price( '0' );
	$free->set_sku( 'EVENTBRIDGE-TEST-' . $run_id . '-FREE' );
	$free->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
	$manifest['products'][] = $free->save();

	$paid = new WC_Product_Simple();
	$paid->set_name( 'EventBridge Testproduct Betaald' );
	$paid->set_status( 'publish' );
	$paid->set_virtual( true );
	$paid->set_regular_price( '10' );
	$paid->set_price( '10' );
	$paid->set_sku( 'EVENTBRIDGE-TEST-' . $run_id . '-PAID' );
	$paid->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
	$manifest['products'][] = $paid->save();

	$variable = new WC_Product_Variable();
	$variable->set_name( 'EventBridge Testproduct Variatie' );
	$variable->set_status( 'publish' );
	$variable->set_virtual( true );
	$variable->set_sku( 'EVENTBRIDGE-TEST-' . $run_id . '-VARIABLE' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Uitvoering' );
	$attribute->set_options( array( 'Basis', 'Plus' ) );
	$attribute->set_visible( true );
	$attribute->set_variation( true );
	$variable->set_attributes( array( $attribute ) );
	$variable->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
	$variable_id = $variable->save();
	$manifest['products'][] = $variable_id;

	foreach ( array( 'basis' => '15', 'plus' => '20' ) as $variation_name => $price ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $variable_id );
		$variation->set_status( 'publish' );
		$variation->set_virtual( true );
		$variation->set_regular_price( $price );
		$variation->set_price( $price );
		$variation->set_sku( 'EVENTBRIDGE-TEST-' . $run_id . '-' . strtoupper( $variation_name ) );
		$variation->set_attributes( array( 'uitvoering' => ucfirst( $variation_name ) ) );
		$variation->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
		$manifest['variations'][] = $variation->save();
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( 'eventbridge-test-25-' . substr( str_replace( '-', '', $run_id ), 0, 12 ) );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( 25 );
	$coupon->set_individual_use( false );
	$coupon->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
	$manifest['coupon'] = $coupon->save();

	$email       = 'eventbridge-' . substr( str_replace( '-', '', $run_id ), 0, 12 ) . '@example.test';
	$customer_id = wc_create_new_customer( $email, '', wp_generate_password( 24, true, true ) );
	if ( is_wp_error( $customer_id ) ) {
		throw new RuntimeException( 'Unable to create the test customer.' );
	}
	update_user_meta( $customer_id, EVENTBRIDGE_WC_TEST_MARKER, $run_id );
	$manifest['customer_id'] = $customer_id;

	eventbridge_wc_save_manifest( $manifest );
	return $manifest;
}

function eventbridge_wc_run_capture( $storage = 'current' ) {
	$manifest = eventbridge_wc_test_manifest();
	if ( empty( $manifest['run_id'] ) || empty( $manifest['products'][1] ) ) {
		throw new RuntimeException( 'Create fixtures before running the capture.' );
	}

	$run_id       = $manifest['run_id'];
	$product      = wc_get_product( $manifest['products'][1] );
	$old_events   = get_option( EventBridge_Events::OPTION_NAME, array() );
	$old_settings = get_option( EventBridge_Settings::OPTION_NAME, array() );
	$old_currency = get_option( 'woocommerce_currency', false );
	$had_currency = false !== $old_currency;
	$captured     = array();
	$event_key    = 'evt_' . wp_generate_uuid4();
	$manifest['event_keys'][] = $event_key;
	eventbridge_wc_save_manifest( $manifest );
	$event_input = array(
		'label'        => 'EventBridge lokale WooCommerce-test',
		'description'  => '',
		'event_name'   => 'Purchase',
		'capi'         => '1',
		'meta_test_mode'       => '1',
		'meta_test_event_code' => 'TEST12345',
		'enabled'      => '1',
		'trigger_type' => 'woocommerce',
		'selector'     => '',
		'url_match_type'  => '',
		'url_match_value' => '',
		'parameters'   => array(
			array( 'name' => 'eventbridge_order_total', 'source' => 'woocommerce_order', 'value' => 'total' ),
		),
		'conditions'   => array(
			array( 'provider' => 'woocommerce', 'field' => 'product', 'operator' => 'contains_exact', 'value' => $product->get_id() ),
			array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'gte', 'value' => '20.00' ),
			array( 'provider' => 'woocommerce', 'field' => 'product_quantity_total', 'operator' => 'eq', 'value' => '2' ),
			array( 'provider' => 'woocommerce', 'field' => 'virtual_product', 'operator' => 'all', 'value' => '1' ),
			array( 'provider' => 'woocommerce', 'field' => 'payment_method', 'operator' => 'eq', 'value' => 'bacs' ),
		),
		'data_source'  => array( 'provider' => '', 'lookup_source' => '', 'lookup_value' => '', 'expected_event_id' => 0 ),
		'advanced_matching' => array(
			'email'      => array( 'source' => 'woocommerce_billing', 'value' => 'billing_email' ),
			'phone'      => array( 'source' => 'woocommerce_billing', 'value' => 'billing_phone' ),
			'first_name' => array( 'source' => 'woocommerce_billing', 'value' => 'billing_first_name' ),
			'last_name'  => array( 'source' => 'woocommerce_billing', 'value' => 'billing_last_name' ),
		),
		'woocommerce' => array( 'event' => 'paid', 'status' => '', 'purchase_preset' => '1' ),
		'remove_query_parameters' => '1',
	);
	$validation_log      = new EventBridge_Log();
	$validation_settings = new EventBridge_Settings();
	$validation_capi     = new EventBridge_Meta_CAPI( $validation_settings, $validation_log );
	$validation_condition_provider = new EventBridge_WooCommerce_Conditions();
	$validation_conditions = new EventBridge_Conditions( array( $validation_condition_provider ), $validation_settings, $validation_log );
	$validation_provider = new EventBridge_WooCommerce( $validation_capi, $validation_log, $validation_conditions );
	$validation_events   = new EventBridge_Events( $validation_provider, $validation_conditions );
	$validation_provider->set_events( $validation_events );
	$validation          = $validation_events->validate_event( $event_input, null, true, $event_key );
	if ( ! empty( $validation['errors'] ) ) {
		throw new RuntimeException( 'Server-side event validation failed: ' . implode( ' | ', $validation['errors'] ) );
	}
	eventbridge_wc_assert_purchase_route( $validation['event'], $validation_events );
	$features_controller = wc_get_container()->get( '\Automattic\WooCommerce\Internal\Features\FeaturesController' );
	$original_hpos       = $features_controller->feature_is_enabled( 'custom_order_tables' );
	$requested_hpos      = 'current' === $storage ? $original_hpos : 'hpos' === $storage;
	$storage_changed     = false;
	$order               = null;
	$order_id            = 0;
	$order_deleted_for_regression = false;

	$capture = function ( $preempt, $args, $url ) use ( &$captured ) {
		if ( false !== strpos( $url, 'graph.facebook.com/' ) ) {
			$captured[] = array( 'url' => $url, 'args' => $args );
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'events_received' => 1 ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		return $preempt;
	};

	try {
		update_option( 'woocommerce_currency', 'EUR', false );
		if ( $requested_hpos !== $original_hpos ) {
			$features_controller->change_feature_enable( 'custom_order_tables', $requested_hpos );
			$storage_changed = true;
			if ( $requested_hpos !== $features_controller->feature_is_enabled( 'custom_order_tables' ) ) {
				throw new RuntimeException( 'WooCommerce refused the requested order storage mode.' );
			}
		}

		update_option(
			EventBridge_Settings::OPTION_NAME,
			array( 'pixel_id' => '123456789', 'capi_token' => 'eventbridge-local-test-token', 'debug' => false ),
			false
		);
		update_option(
			EventBridge_Events::OPTION_NAME,
			array( $event_key => $validation['event'] ),
			false
		);

		add_filter( 'pre_http_request', $capture, 10, 3 );

		$order = wc_create_order( array( 'customer_id' => $manifest['customer_id'], 'status' => 'pending' ) );
		if ( is_wp_error( $order ) ) {
			throw new RuntimeException( 'Unable to create the test order.' );
		}
		$order_id = $order->get_id();
		$order->update_meta_data( EVENTBRIDGE_WC_TEST_MARKER, $run_id );
		$order->save_meta_data();
		$manifest['orders'][] = $order_id;
		eventbridge_wc_save_manifest( $manifest );

		$order->add_product( $product, 2 );
		$order->set_payment_method( 'bacs' );
		$order->set_payment_method_title( 'Directe bankoverschrijving' );
		$order->set_billing_email( 'eventbridge-buyer@example.test' );
		$order->set_billing_phone( '+32470123456' );
		$order->set_billing_first_name( 'Event' );
		$order->set_billing_last_name( 'Bridge' );
		$order->set_customer_ip_address( '203.0.113.24' );
		$order->set_customer_user_agent( 'EventBridge local WooCommerce harness' );
		$order->calculate_totals();
		$order->save();

		$order->payment_complete( 'eventbridge-local-transaction' );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'ignored-duplicate' );
		do_action( 'woocommerce_payment_complete', $order->get_id(), 'ignored-duplicate' );

		if ( 1 !== count( $captured ) ) {
			throw new RuntimeException( 'Expected exactly one captured Meta request; got ' . count( $captured ) . '.' );
		}

		$body       = json_decode( $captured[0]['args']['body'], true );
		$diagnostic = eventbridge_wc_purchase_contract_differences( $body, $order );
		if ( ! empty( $diagnostic['differences'] ) ) {
			throw new RuntimeException( 'Captured Meta request did not match the safe Purchase contract: ' . wp_json_encode( $diagnostic ) );
		}
		$event = isset( $body['data'][0] ) && is_array( $body['data'][0] ) ? $body['data'][0] : array();

		$production_ledger = $order->get_meta( EventBridge_WooCommerce::LEDGER_PRODUCTION_META, true );
		if ( ! empty( $production_ledger ) ) {
			throw new RuntimeException( 'Meta test mode consumed production idempotency.' );
		}

		$warnings_before = eventbridge_wc_count_order_warnings( $order_id );
		$created_event   = $validation['event'];
		$created_event['event_name'] = 'BookingComplete';
		$created_event['parameters'] = array();
		$created_event['woocommerce'] = array( 'event' => 'created', 'status' => '', 'purchase_preset' => false );
		$created_event['conditions'] = array(
			array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'gt', 'value' => '999.00' ),
		);
		update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $created_event ), false );

		$validation_provider->handle_new_order( $order_id, $order );
		$validation_provider->flush_created_orders();
		if ( 1 !== count( $captured ) ) {
			throw new RuntimeException( 'A condition mismatch unexpectedly started a Meta request.' );
		}
		$test_ledger_after_mismatch = $order->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true );
		if ( is_array( $test_ledger_after_mismatch )
			&& isset( $test_ledger_after_mismatch['entries'][ $event_key . '|created' ] )
		) {
			throw new RuntimeException( 'A condition mismatch unexpectedly created a ledger entry.' );
		}

		$created_event['conditions'][0] = array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'lte', 'value' => '20.00' );
		$secondary_trigger = $created_event['triggers'][0];
		$secondary_trigger['trigger_id'] = 'trg_' . wp_generate_uuid4();
		$secondary_trigger['provider_config']['event'] = 'created';
		$secondary_trigger['provider_config']['status'] = '';
		$secondary_trigger['provider_config']['purchase_preset'] = false;
		$secondary_trigger['parameters'] = array(
			array( 'name' => 'eventbridge_route', 'source' => 'static', 'value' => 'secondary' ),
		);
		$secondary_trigger['conditions'] = array(
			array( 'provider' => 'woocommerce', 'field' => 'order_total', 'operator' => 'lte', 'value' => '20.00' ),
		);
		$created_event['triggers'][] = $secondary_trigger;
		update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $created_event ), false );
		$validation_provider->handle_new_order( $order_id, $order );
		$validation_provider->flush_created_orders();
		$created_body = isset( $captured[1]['args']['body'] ) ? json_decode( $captured[1]['args']['body'], true ) : null;
		$created_capture = is_array( $created_body ) && isset( $created_body['data'][0] ) ? $created_body['data'][0] : array();
		$secondary_body = isset( $captured[2]['args']['body'] ) ? json_decode( $captured[2]['args']['body'], true ) : null;
		$secondary_capture = is_array( $secondary_body ) && isset( $secondary_body['data'][0] ) ? $secondary_body['data'][0] : array();
		if ( 3 !== count( $captured )
			|| 'BookingComplete' !== $created_capture['event_name']
			|| empty( $created_capture['event_id'] )
			|| $event['event_id'] === $created_capture['event_id']
			|| isset( $created_capture['custom_data'] )
			|| 'BookingComplete' !== $secondary_capture['event_name']
			|| empty( $secondary_capture['event_id'] )
			|| $created_capture['event_id'] === $secondary_capture['event_id']
			|| 'secondary' !== $secondary_capture['custom_data']['eventbridge_route']
		) {
			throw new RuntimeException( 'Two valid created-order routes did not produce two independent server events.' );
		}

		$order       = wc_get_order( $order_id );
		$test_ledger = $order->get_meta( EventBridge_WooCommerce::LEDGER_TEST_META, true );
		$compatibility_trigger_id = $created_event['eventbridge_compat']['legacy_trigger_id'];
		$compatibility_key = 'v2|' . $event_key . '|' . $compatibility_trigger_id . '|created';
		$secondary_key = 'v2|' . $event_key . '|' . $secondary_trigger['trigger_id'] . '|created';
		if ( ! isset( $test_ledger['entries'][ $event_key . '|created' ], $test_ledger['entries'][ $compatibility_key ], $test_ledger['entries'][ $secondary_key ] )
			|| $test_ledger['entries'][ $event_key . '|created' ]['event_id'] !== $test_ledger['entries'][ $compatibility_key ]['event_id']
			|| $test_ledger['entries'][ $secondary_key ]['event_id'] === $test_ledger['entries'][ $compatibility_key ]['event_id']
		) {
			throw new RuntimeException( 'The compatibility alias and canonical multitrigger ledger entries are invalid.' );
		}

		$validation_provider->handle_new_order( $order_id, $order );
		$validation_provider->flush_created_orders();
		if ( 3 !== count( $captured ) ) {
			throw new RuntimeException( 'A duplicate multitrigger callback started another Meta request.' );
		}

		$status_event = $created_event;
		$status_event['event_name'] = 'OrderStatusComplete';
		$status_event['woocommerce'] = array( 'event' => 'status', 'status' => 'completed', 'purchase_preset' => false );
		$status_event['triggers'] = array( $status_event['triggers'][0] );
		$status_event['triggers'][0]['provider_config']['event'] = 'status';
		$status_event['triggers'][0]['provider_config']['status'] = 'completed';
		update_option( EventBridge_Events::OPTION_NAME, array( $event_key => $status_event ), false );
		$order->set_status( 'completed' );
		$order->save();
		$validation_provider->handle_status_changed( $order_id, 'processing', 'completed', $order );
		$status_body = isset( $captured[3]['args']['body'] ) ? json_decode( $captured[3]['args']['body'], true ) : null;
		$status_capture = is_array( $status_body ) && isset( $status_body['data'][0] ) ? $status_body['data'][0] : array();
		if ( 4 !== count( $captured )
			|| 'OrderStatusComplete' !== $status_capture['event_name']
			|| '203.0.113.24' !== $status_capture['user_data']['client_ip_address']
			|| 'EventBridge local WooCommerce harness' !== $status_capture['user_data']['client_user_agent']
			|| 'TEST12345' !== $status_body['test_event_code']
		) {
			throw new RuntimeException( 'The status lifecycle event did not preserve order client data or test mode.' );
		}
		eventbridge_wc_assert_rest_ledger_protection( $order );

		$validation_provider->handle_new_order( $order_id, $order );
		$order->delete( true );
		$order_deleted_for_regression = true;
		$validation_provider->flush_created_orders();

		if ( wc_get_order( $order_id )
			|| 4 !== count( $captured )
			|| $warnings_before !== eventbridge_wc_count_order_warnings( $order_id )
		) {
			throw new RuntimeException( 'A deleted created-order produced an unexpected WooCommerce warning or remained loadable.' );
		}

		return array(
			'storage'        => $requested_hpos ? 'hpos' : 'classic',
			'order_id'       => $order_id,
			'event_id'       => $event['event_id'],
			'captured_calls' => count( $captured ),
			'paid_captured_calls' => 1,
			'created_captured_calls' => 2,
			'status_captured_calls' => 1,
			'multitrigger_ledger' => 'passed',
			'rest_ledger_protection' => 'passed',
			'value'          => $event['custom_data']['value'],
			'currency'       => $event['custom_data']['currency'],
			'fixture_currency_before' => $old_currency,
			'num_items'      => $event['custom_data']['num_items'],
			'user_data_keys' => array_keys( $event['user_data'] ),
			'condition_mismatch' => 'passed',
			'deleted_created_regression' => 'passed',
		);
	} finally {
		remove_filter( 'pre_http_request', $capture, 10 );
		update_option( EventBridge_Events::OPTION_NAME, $old_events, false );
		update_option( EventBridge_Settings::OPTION_NAME, $old_settings, false );
		if ( $had_currency ) {
			update_option( 'woocommerce_currency', $old_currency, false );
		} else {
			delete_option( 'woocommerce_currency' );
		}
		if ( $storage_changed ) {
			if ( ! $order_deleted_for_regression && is_a( $order, 'WC_Order' ) && $run_id === $order->get_meta( EVENTBRIDGE_WC_TEST_MARKER, true ) ) {
				$order->delete( true );
			}
			try {
				$features_controller->change_feature_enable( 'custom_order_tables', $original_hpos );
			} catch ( Throwable $throwable ) {
				throw new RuntimeException( 'WooCommerce order storage mode could not be restored: ' . $throwable->getMessage(), 0, $throwable );
			}
			if ( $original_hpos !== $features_controller->feature_is_enabled( 'custom_order_tables' ) ) {
				throw new RuntimeException( 'WooCommerce order storage mode could not be restored automatically.' );
			}
		}
	}
}

function eventbridge_wc_cleanup_fixtures() {
	$manifest = eventbridge_wc_test_manifest();
	if ( empty( $manifest['run_id'] ) ) {
		return array( 'removed' => 0 );
	}

	$run_id  = $manifest['run_id'];
	$removed = 0;

	foreach ( isset( $manifest['orders'] ) ? $manifest['orders'] : array() as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $run_id === $order->get_meta( EVENTBRIDGE_WC_TEST_MARKER, true ) ) {
			$order->delete( true );
			$removed++;
		}
	}
	foreach ( array_merge( isset( $manifest['variations'] ) ? $manifest['variations'] : array(), isset( $manifest['products'] ) ? $manifest['products'] : array() ) as $product_id ) {
		$product = wc_get_product( $product_id );
		$sku_prefix = 'EVENTBRIDGE-TEST-' . $run_id . '-';
		$is_variation = in_array( $product_id, isset( $manifest['variations'] ) ? $manifest['variations'] : array(), true );
		$name_matches = $is_variation || in_array(
			$product ? $product->get_name() : '',
			array( 'EventBridge Testproduct Gratis', 'EventBridge Testproduct Betaald', 'EventBridge Testproduct Variatie' ),
			true
		);
		$parent_matches = ! $is_variation || ( $product && in_array( $product->get_parent_id(), isset( $manifest['products'] ) ? $manifest['products'] : array(), true ) );
		if ( $product
			&& $run_id === $product->get_meta( EVENTBRIDGE_WC_TEST_MARKER, true )
			&& 0 === strpos( (string) $product->get_sku(), $sku_prefix )
			&& $name_matches
			&& $parent_matches
		) {
			$product->delete( true );
			$removed++;
		}
	}
	if ( ! empty( $manifest['coupon'] ) ) {
		$coupon = new WC_Coupon( $manifest['coupon'] );
		$expected_coupon = 'eventbridge-test-25-' . substr( str_replace( '-', '', $run_id ), 0, 12 );
		if ( $coupon->get_id()
			&& $run_id === $coupon->get_meta( EVENTBRIDGE_WC_TEST_MARKER, true )
			&& $expected_coupon === $coupon->get_code()
		) {
			$coupon->delete( true );
			$removed++;
		}
	}
	if ( ! empty( $manifest['customer_id'] ) && $run_id === get_user_meta( $manifest['customer_id'], EVENTBRIDGE_WC_TEST_MARKER, true ) ) {
		$customer = get_userdata( $manifest['customer_id'] );
		$expected_email = 'eventbridge-' . substr( str_replace( '-', '', $run_id ), 0, 12 ) . '@example.test';
		if ( $customer && $expected_email === $customer->user_email ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $manifest['customer_id'] );
			$removed++;
		}
	}
	if ( ! empty( $manifest['event_keys'] ) ) {
		global $wpdb;
		$log_table = $wpdb->prefix . 'eventbridge_logs';
		foreach ( $manifest['event_keys'] as $event_key ) {
			if ( is_string( $event_key ) && preg_match( '/^evt_[0-9a-f-]{36}$/D', $event_key ) ) {
				$removed += absint( $wpdb->delete( $log_table, array( 'event_key' => $event_key ), array( '%s' ) ) );
			}
		}
	}

	delete_option( EVENTBRIDGE_WC_TEST_MANIFEST );
	return array( 'removed' => $removed );
}

try {
	if ( 'create' === $command ) {
		$result = eventbridge_wc_create_fixtures();
	} elseif ( 'run' === $command ) {
		$result = eventbridge_wc_run_capture( $storage );
	} else {
		$result = eventbridge_wc_cleanup_fixtures();
	}
	echo wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'EventBridge WooCommerce harness failed: ' . $throwable->getMessage() . PHP_EOL );
	exit( 1 );
}
