<?php

if ( defined( 'PHPUNIT_COMPOSER_INSTALL' ) || class_exists( '\PHPUnit\Framework\TestCase' ) ) {
	return;
}

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This harness only runs from PHP CLI.\n" );
	exit( 1 );
}

require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || ! in_array( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ), array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
	fwrite( STDERR, "Refusing to run outside a loopback local WordPress installation.\n" );
	exit( 1 );
}

class EventBridge_Local_Interaction_CAPI extends EventBridge_Meta_CAPI {
	public $calls = array();
	public function send_custom_event( $event_name, $event_id, $event_source_url, $custom_data, $details, $advanced_user_data = array(), $event_configuration = array() ) {
		$this->calls[] = compact( 'event_name', 'event_id', 'custom_data' );
		return true;
	}
}

class EventBridge_Local_Interaction_Session {
	public $data = array();
	public function get( $key, $default = null ) { return array_key_exists( $key, $this->data ) ? $this->data[ $key ] : $default; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
}

class EventBridge_Local_Interaction_Ajax_Die extends RuntimeException {}

$settings   = new EventBridge_Settings();
$log        = new EventBridge_Log();
$capi       = new EventBridge_Local_Interaction_CAPI( $settings, $log );
$provider   = new EventBridge_WooCommerce_Conditions();
$conditions = new EventBridge_Conditions( array( $provider ), $settings, $log );
$woo        = new EventBridge_WooCommerce( $capi, $log, $conditions );
$events     = new EventBridge_Events( $woo, $conditions );
$woo->set_events( $events );
$fluent     = new EventBridge_Fluent_Booking();
$interactions = new EventBridge_WooCommerce_Interactions( $events, $capi, $log, $conditions, $fluent );
$old_events = get_option( EventBridge_Events::OPTION_NAME, array() );
$exit_code  = 0;
$output     = '';
$ajax_die_filter_added = false;

if ( ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}

$trigger = array(
	'trigger_id' => 'trg_66666666-6666-4666-8666-666666666666',
	'provider' => 'woocommerce', 'trigger_type' => 'added_to_cart', 'provider_config' => array(),
	'parameters' => array( array( 'name' => 'value', 'source' => 'woocommerce_interaction', 'value' => 'line_value' ) ),
	'conditions' => array( array( 'provider' => 'woocommerce', 'field' => 'action_quantity', 'operator' => 'gte', 'value' => 2 ) ),
	'data_source' => array(), 'advanced_matching' => array(),
);
$product_trigger = array(
	'trigger_id' => 'trg_55555555-5555-4555-8555-555555555555',
	'provider' => 'woocommerce', 'trigger_type' => 'product_viewed', 'provider_config' => array(),
	'parameters' => array(), 'conditions' => array(), 'data_source' => array(), 'advanced_matching' => array(),
);
$fixture = ( new EventBridge_Triggers() )->apply_compatibility_shadow(
	array( 'label' => 'Interaction harness', 'event_name' => 'AddToCart', 'enabled' => true, 'channels' => array( 'browser' => true, 'capi' => true ) ),
	array( $trigger, $product_trigger ),
	$trigger['trigger_id']
);

try {
	update_option( EventBridge_Events::OPTION_NAME, array( 'evt_66666666-6666-4666-8666-666666666666' => $fixture ) );
	$product = new WC_Product_Simple();
	$product->set_name( 'EventBridge canonical-view harness product' );
	$product->set_status( 'publish' );
	$product->set_regular_price( '12.50' );
	$product->save();
	$parent = new WC_Product_Variable();
	$parent->set_name( 'EventBridge variable harness product' );
	$parent->set_status( 'publish' );
	$parent->save();
	$category = wp_insert_term( 'EventBridge interaction category ' . wp_generate_uuid4(), 'product_cat' );
	$tag = wp_insert_term( 'EventBridge interaction tag ' . wp_generate_uuid4(), 'product_tag' );
	if ( is_wp_error( $category ) || is_wp_error( $tag ) ) {
		throw new RuntimeException( 'Unable to create temporary product terms.' );
	}
	wp_set_object_terms( $parent->get_id(), array( $category['term_id'] ), 'product_cat', false );
	wp_set_object_terms( $parent->get_id(), array( $tag['term_id'] ), 'product_tag', false );
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_status( 'publish' );
	$variation->set_regular_price( '7.50' );
	$variation->set_virtual( true );
	$variation->set_downloadable( true );
	$variation->save();
	$old_session = WC()->session;
	$fake_session = new EventBridge_Local_Interaction_Session();
	WC()->session = $fake_session;
	$ajax_die_filter = function () {
		return function () {
			throw new EventBridge_Local_Interaction_Ajax_Die();
		};
	};
	add_filter( 'wp_die_ajax_handler', $ajax_die_filter );
	$ajax_die_filter_added = true;
	$interactions->capture_add_to_cart( 'simple-key', $product->get_id(), 2, 0, array(), array() );
	$interactions->capture_add_to_cart( 'variation-key', $parent->get_id(), 3, $variation->get_id(), array(), array() );
	$receipts = $fake_session->get( EventBridge_WooCommerce_Interactions::RECEIPT_SESSION, array() );
	$old_query = isset( $GLOBALS['wp_query'] ) ? $GLOBALS['wp_query'] : null;
	$old_post  = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
	$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'product', 'posts_per_page' => 10 ) );
	$archive_context = $interactions->get_client_configuration()['productViewContext'];
	$GLOBALS['wp_query'] = new WP_Query( array( 'post_type' => 'product', 'p' => $product->get_id() ) );
	$GLOBALS['post'] = $GLOBALS['wp_query']->post;
	setup_postdata( $GLOBALS['post'] );
	$product_context = $interactions->get_client_configuration()['productViewContext'];
	$method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'dispatch_occurrence' );
	$method->setAccessible( true );
	$claims = array();
	$args = array(
		'added_to_cart',
		array(
			'eventbridge_context' => 'added_to_cart', 'line_value' => 39.98, 'quantity' => 2,
			'product_ids' => array( 10 ), 'parent_ids' => array( 10 ), 'variation_ids' => array(),
		),
		'55555555-5555-4555-8555-555555555555',
		home_url( '/shop/' ),
		&$claims,
		array(),
	);
	$deliveries = $method->invokeArgs( $interactions, $args );
	$duplicate  = $method->invokeArgs( $interactions, $args );
	$product_claims = array();
	$product_args = array(
		'product_viewed', $receipts[0]['snapshot'], '44444444-4444-4444-8444-444444444444',
		home_url( '/product/' ), &$product_claims, array(),
	);
	$product_deliveries = $method->invokeArgs( $interactions, $product_args );

	$variation_conditions = array(
		array( 'field' => 'product', 'operator' => 'contains_exact', 'value' => $parent->get_id() ),
		array( 'field' => 'parent_product', 'operator' => 'contains', 'value' => $parent->get_id() ),
		array( 'field' => 'variation', 'operator' => 'contains', 'value' => $variation->get_id() ),
		array( 'field' => 'product_category', 'operator' => 'contains_any', 'value' => array( $category['term_id'] ) ),
		array( 'field' => 'product_tag', 'operator' => 'contains_any', 'value' => array( $tag['term_id'] ) ),
		array( 'field' => 'virtual_product', 'operator' => 'any', 'value' => true ),
		array( 'field' => 'downloadable_product', 'operator' => 'any', 'value' => true ),
		array( 'field' => 'action_quantity', 'operator' => 'gte', 'value' => 3 ),
	);
	$variation_context = $provider->build_context( 'added_to_cart', $receipts[1]['snapshot'], $variation_conditions );
	$variation_matches = array_map(
		function ( $condition ) use ( $provider, $variation_context ) {
			return 'match' === $provider->evaluate( $condition, $variation_context )['status'];
		},
		$variation_conditions
	);
	$negative_product = $provider->evaluate(
		array( 'field' => 'product', 'operator' => 'not_contains_any', 'value' => array( $product->get_id() ) ),
		$variation_context
	);
	$cart_conditions = array(
		array( 'field' => 'product', 'operator' => 'contains_any', 'value' => array( $product->get_id(), $variation->get_id() ) ),
		array( 'field' => 'cart_subtotal', 'operator' => 'gte', 'value' => 20 ),
		array( 'field' => 'cart_total', 'operator' => 'lte', 'value' => 50 ),
		array( 'field' => 'product_quantity_total', 'operator' => 'eq', 'value' => 5 ),
		array( 'field' => 'coupon', 'operator' => 'contains', 'value' => 'summer' ),
	);
	$cart_context = $provider->build_context(
		'checkout_started',
		array(
			'eventbridge_context' => 'checkout_started', 'product_ids' => array( $product->get_id(), $variation->get_id() ),
			'cart_subtotal' => 25, 'cart_total' => 24, 'total_quantity' => 5, 'coupon_codes' => array( 'SUMMER' ),
		),
		$cart_conditions
	);
	$cart_matches = array_map(
		function ( $condition ) use ( $provider, $cart_context ) {
			return 'match' === $provider->evaluate( $condition, $cart_context )['status'];
		},
		$cart_conditions
	);

	$attempt = $interactions->resolve_checkout_attempt( array(), 'cart-a', 'navigate', 1000 );
	$refresh = $interactions->resolve_checkout_attempt( $attempt, 'cart-a', 'reload', 1100 );
	$left = $attempt;
	$left['left'] = true;
	$history = $interactions->resolve_checkout_attempt( $left, 'cart-a', 'back_forward', 1100 );
	$reentry = $interactions->resolve_checkout_attempt( $left, 'cart-a', 'navigate', 1100 );
	$changed = $interactions->resolve_checkout_attempt( $attempt, 'cart-b', 'reload', 1100 );
	$timeout = $interactions->resolve_checkout_attempt( $attempt, 'cart-a', 'reload', 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
	$gateway_attempt = $attempt;
	$gateway_attempt['flow_until'] = 1000 + EventBridge_WooCommerce_Interactions::FLOW_GRACE_TTL;
	$gateway_active = $interactions->resolve_checkout_attempt( $gateway_attempt, 'cart-a', 'navigate', 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
	$gateway_expired = $interactions->resolve_checkout_attempt( $gateway_attempt, 'cart-a', 'navigate', 1000 + EventBridge_WooCommerce_Interactions::FLOW_GRACE_TTL + 1 );
	$long_form_active = $interactions->refresh_checkout_flow_attempt( $attempt, 'cart-a', $attempt['id'], 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
	$long_form_changed = $interactions->refresh_checkout_flow_attempt( $attempt, 'cart-b', $attempt['id'], 1000 + EventBridge_WooCommerce_Interactions::ATTEMPT_TIMEOUT + 1 );
	$leave_classification_method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'classify_checkout_leave_destination' );
	$leave_classification_method->setAccessible( true );
	$context_method = new ReflectionMethod( EventBridge_WooCommerce_Interactions::class, 'sign_context' );
	$context_method->setAccessible( true );
	$checkout_context = $context_method->invoke( $interactions, array( 'kind' => 'checkout_started', 'issued_at' => time() ) );
	$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/?page_id=1' );
	$technical_routes = array(
		'wc_api_query'        => home_url( '/?wc-api=WC_Gateway_Test' ),
		'wc_ajax_query'       => home_url( '/?wc-ajax=checkout' ),
		'rest_route_query'    => home_url( '/?rest_route=/wc/store/v1/checkout' ),
		'checkout_query'      => $checkout_url,
		'order_pay_query'     => home_url( '/?order-pay=123' ),
		'order_received_query' => home_url( '/?order-received=123' ),
		'order_pay_path'      => home_url( '/checkout/order-pay/123/' ),
		'order_received_path' => home_url( '/order-received/123/' ),
		'gateway_path'        => home_url( '/gateway/callback/' ),
		'three_ds_path'       => home_url( '/3ds/return/' ),
	);
	$ordinary_routes = array(
		'root_query_storefront' => home_url( '/?filter=featured' ),
		'shop_storefront'       => home_url( '/shop/?filter=featured' ),
	);
	$run_checkout_leave = function ( $name, $destination, $expected_left ) use ( $interactions, $fake_session, $checkout_context, $leave_classification_method ) {
		global $_POST, $_REQUEST, $_SERVER;
		$previous_post    = $_POST;
		$previous_request = $_REQUEST;
		$previous_server  = $_SERVER;
		$attempt = $interactions->resolve_checkout_attempt( array(), 'checkout-leave-' . $name, 'navigate', time() );
		$fake_session->set( EventBridge_WooCommerce_Interactions::ATTEMPT_SESSION, $attempt );
		$before = $fake_session->get( EventBridge_WooCommerce_Interactions::ATTEMPT_SESSION, array() );
		$parts = wp_parse_url( $destination );
		$query_args = array();
		if ( is_array( $parts ) && ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
		}
		$_POST = array(
			'action'      => EventBridge_WooCommerce_Interactions::AJAX_ACTION,
			'nonce'       => wp_create_nonce( EventBridge_WooCommerce_Interactions::NONCE_ACTION ),
			'interaction' => 'confirm_checkout_leave',
			'page_url'    => home_url( '/checkout/' ),
			'attempt_id'  => $attempt['id'],
			'context'     => $checkout_context,
			'destination' => $destination,
		);
		$_REQUEST = $_POST;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$response = null;
		ob_start();
		try {
			$interactions->handle_request();
		} catch ( EventBridge_Local_Interaction_Ajax_Die $exception ) {
			// wp_send_json_* terminates an AJAX request after emitting its response.
		} finally {
			$response = json_decode( ob_get_clean(), true );
			$_POST    = $previous_post;
			$_REQUEST = $previous_request;
			$_SERVER  = $previous_server;
		}
		$after = $fake_session->get( EventBridge_WooCommerce_Interactions::ATTEMPT_SESSION, array() );
		$left_after = ! empty( $after['left'] );
		$passed = is_array( $response ) && ! empty( $response['success'] )
			&& ! empty( $before['id'] ) && isset( $after['id'] ) && $before['id'] === $after['id']
			&& empty( $before['left'] ) && $expected_left === $left_after;

		return array(
			'passed' => $passed,
			'diagnostic' => array(
				'original_destination' => $destination,
				'canonical_destination' => EventBridge_Meta_URL::canonicalize( $destination ),
				'path' => is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '',
				'query' => $query_args,
				'classifier_branch' => $leave_classification_method->invoke( $interactions, $destination ),
				'request_method' => 'POST',
				'nonce_verified' => is_array( $response ) && ! empty( $response['success'] ),
				'storage_key' => EventBridge_WooCommerce_Interactions::ATTEMPT_SESSION,
				'attempt_id_before' => isset( $before['id'] ) ? $before['id'] : '',
				'attempt_id_after' => isset( $after['id'] ) ? $after['id'] : '',
				'left_before' => ! empty( $before['left'] ),
				'left_after' => $left_after,
				'response' => $response,
			),
		);
	};
	$technical_leave_results = array();
	foreach ( $technical_routes as $name => $route ) {
		$technical_leave_results[ $name ] = $run_checkout_leave( $name, $route, false );
	}
	$ordinary_leave_results = array();
	foreach ( $ordinary_routes as $name => $route ) {
		$ordinary_leave_results[ $name ] = $run_checkout_leave( $name, $route, true );
	}
	$technical_routes_ignored = ! in_array( false, array_column( $technical_leave_results, 'passed' ), true );
	$ordinary_store_page = ! in_array( false, array_column( $ordinary_leave_results, 'passed' ), true );
	$leave_diagnostics = array( 'technical' => array(), 'ordinary' => array() );
	foreach ( $technical_leave_results as $name => $route_result ) {
		$leave_diagnostics['technical'][ $name ] = $route_result['diagnostic'];
	}
	foreach ( $ordinary_leave_results as $name => $route_result ) {
		$leave_diagnostics['ordinary'][ $name ] = $route_result['diagnostic'];
	}

	$rollback = $fixture;
	unset( $rollback['triggers'] );
	$rollback = $events->normalize_event( $rollback, 'evt_66666666-6666-4666-8666-666666666666' );

	$result = array(
		'shared_event_id' => 1 === count( $deliveries ) && isset( $capi->calls[0] ) && $deliveries[0]['eventId'] === $capi->calls[0]['event_id'],
		'typed_parameter' => 39.98 === $deliveries[0]['parameters']->value,
		'deduplicated' => empty( $duplicate ) && 2 === count( $capi->calls ),
		'no_conditions' => 1 === count( $product_deliveries ),
		'variation_conditions' => ! in_array( false, $variation_matches, true ),
		'negative_product_condition' => 'match' === $negative_product['status'],
		'cart_conditions' => ! in_array( false, $cart_matches, true ),
		'refresh_same' => $attempt['id'] === $refresh['id'],
		'history_same' => $attempt['id'] === $history['id'],
		'active_reentry_new' => $attempt['id'] !== $reentry['id'],
		'cart_change_new' => $attempt['id'] !== $changed['id'],
		'timeout_new' => $attempt['id'] !== $timeout['id'],
		'gateway_flow_same' => $attempt['id'] === $gateway_active['id'],
		'gateway_flow_expired' => $attempt['id'] !== $gateway_expired['id'],
		'long_form_same' => $attempt['id'] === $long_form_active['id'],
		'long_form_cart_change' => $attempt['id'] !== $long_form_changed['id'],
		'technical_redirect_ignored' => $technical_routes_ignored && true === $ordinary_store_page,
		'rollback_restored' => isset( $rollback['triggers'][0]['trigger_type'] ) && 'added_to_cart' === $rollback['triggers'][0]['trigger_type'],
		'canonical_product_only' => '' === $archive_context && '' !== $product_context,
		'simple_add_receipt' => 2 === count( $receipts ) && $product->get_id() === $receipts[0]['snapshot']['product_id'] && 2 === $receipts[0]['snapshot']['quantity'],
		'variation_add_receipt' => 2 === count( $receipts ) && $variation->get_id() === $receipts[1]['snapshot']['product_id'] && in_array( $parent->get_id(), $receipts[1]['snapshot']['product_ids'], true ),
	);
	if ( in_array( false, $result, true ) ) {
		$exit_code = 1;
		$output = "EventBridge WooCommerce interaction harness failed.\n" . wp_json_encode( array_merge( $result, array( 'technical_redirect_diagnostics' => $leave_diagnostics ) ), JSON_PRETTY_PRINT ) . PHP_EOL;
	} else {
		$output = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	}
} finally {
	if ( $ajax_die_filter_added ) {
		remove_filter( 'wp_die_ajax_handler', $ajax_die_filter );
	}
	if ( isset( $product ) && is_a( $product, 'WC_Product' ) && $product->get_id() ) {
		$product->delete( true );
	}
	if ( isset( $variation ) && is_a( $variation, 'WC_Product' ) && $variation->get_id() ) {
		$variation->delete( true );
	}
	if ( isset( $parent ) && is_a( $parent, 'WC_Product' ) && $parent->get_id() ) {
		$parent->delete( true );
	}
	if ( isset( $category['term_id'] ) ) {
		wp_delete_term( $category['term_id'], 'product_cat' );
	}
	if ( isset( $tag['term_id'] ) ) {
		wp_delete_term( $tag['term_id'], 'product_tag' );
	}
	if ( isset( $old_session ) ) {
		WC()->session = $old_session;
	}
	if ( isset( $old_query ) ) {
		$GLOBALS['wp_query'] = $old_query;
	}
	if ( isset( $old_post ) ) {
		$GLOBALS['post'] = $old_post;
	}
	wp_reset_postdata();
	update_option( EventBridge_Events::OPTION_NAME, $old_events );
}

if ( $exit_code ) {
	fwrite( STDERR, $output );
	exit( $exit_code );
}
echo $output;
