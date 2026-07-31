<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_WooCommerce_Interactions {
	const AJAX_ACTION       = 'eventbridge_woocommerce_interaction';
	const NONCE_ACTION      = 'eventbridge_woocommerce_interaction';
	const RECEIPT_SESSION   = 'eventbridge_add_receipts_v1';
	const CLAIM_SESSION     = 'eventbridge_interaction_claims_v1';
	const ATTEMPT_SESSION   = 'eventbridge_checkout_attempt_v1';
	const RECEIPT_TTL       = 600;
	const ATTEMPT_TIMEOUT   = 1800;
	const FLOW_GRACE_TTL    = 7200;
	const MAX_RECEIPTS      = 50;
	const MAX_CLAIM_BUCKETS = 100;

	private $events;
	private $meta_capi;
	private $log;
	private $conditions;
	private $fluent_booking;

	public function __construct( EventBridge_Events $events, EventBridge_Meta_CAPI $meta_capi, EventBridge_Log $log, EventBridge_Conditions $conditions = null, EventBridge_Fluent_Booking $fluent_booking = null ) {
		$this->events     = $events;
		$this->meta_capi  = $meta_capi;
		$this->log        = $log;
		$this->conditions = $conditions;
		$this->fluent_booking = $fluent_booking;
	}

	public function init() {
		add_action( 'woocommerce_add_to_cart', array( $this, 'capture_add_to_cart' ), 10, 6 );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'add_receipt_fragment' ) );
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_request' ) );
	}

	public function has_active_trigger( $type = '' ) {
		foreach ( $this->events->get_normalized_events() as $event ) {
			if ( true !== $event['enabled'] || EventBridge_Triggers::FAMILY_FRONTEND !== $this->events->get_event_family( $event ) ) {
				continue;
			}
			foreach ( $event['triggers'] as $trigger ) {
				if ( is_array( $trigger )
					&& isset( $trigger['provider'], $trigger['trigger_type'] )
					&& 'woocommerce' === $trigger['provider']
					&& in_array( $trigger['trigger_type'], array( 'product_viewed', 'added_to_cart', 'checkout_started' ), true )
					&& ( '' === $type || $type === $trigger['trigger_type'] )
				) {
					return true;
				}
			}
		}
		return false;
	}

	public function get_client_configuration() {
		if ( ! $this->has_active_trigger() ) {
			return array();
		}

		$config = array(
			'endpointUrl'       => admin_url( 'admin-ajax.php' ),
			'nonce'             => wp_create_nonce( self::NONCE_ACTION ),
			'addedToCart'       => $this->has_active_trigger( 'added_to_cart' ),
			'isCheckout'        => false,
			'productViewContext' => '',
			'checkoutContext'   => '',
			'routeContexts'      => (object) $this->get_client_route_contexts(),
		);

		if ( $this->has_active_trigger( 'product_viewed' ) && $this->is_canonical_product_request() ) {
			$product = wc_get_product( get_queried_object_id() );
			$snapshot = $this->build_product_snapshot( $product, 1, 'product_viewed' );
			if ( ! empty( $snapshot ) ) {
				$config['productViewContext'] = $this->sign_context(
					array(
						'kind'       => 'product_viewed',
						'issued_at'  => time(),
						'occurrence' => wp_generate_uuid4(),
						'snapshot'   => $snapshot,
					)
				);
			}
		}

		if ( $this->has_active_trigger( 'checkout_started' ) && $this->is_canonical_checkout_request() ) {
			$config['isCheckout']      = true;
			$config['checkoutContext'] = $this->sign_context( array( 'kind' => 'checkout_started', 'issued_at' => time() ) );
		}

		return $config;
	}

	public function capture_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( ! $this->has_active_trigger( 'added_to_cart' ) || ! $this->get_session() ) {
			return;
		}
		$concrete_id = absint( $variation_id ) > 0 ? absint( $variation_id ) : absint( $product_id );
		$product     = wc_get_product( $concrete_id );
		$snapshot    = $this->build_product_snapshot( $product, $quantity, 'added_to_cart' );
		if ( empty( $snapshot ) ) {
			return;
		}

		$receipts   = $this->get_receipts();
		$receipts[] = array(
			'id'         => wp_generate_uuid4(),
			'created_at' => time(),
			'snapshot'   => $snapshot,
			'claims'     => array(),
			'reported'   => false,
		);
		$receipts = array_slice( $receipts, -self::MAX_RECEIPTS );
		$this->get_session()->set( self::RECEIPT_SESSION, $receipts );
	}

	public function add_receipt_fragment( $fragments ) {
		$fragments = is_array( $fragments ) ? $fragments : array();
		$ids       = array();
		foreach ( $this->get_receipts() as $receipt ) {
			if ( empty( $receipt['reported'] ) && ! empty( $receipt['id'] ) ) {
				$ids[] = $receipt['id'];
			}
		}
		$fragments['div.eventbridge-add-receipts'] = '<div class="eventbridge-add-receipts" hidden data-receipts="' . esc_attr( wp_json_encode( $ids ) ) . '"></div>';
		return $fragments;
	}

	public function register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
			|| ! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CartSchema' )
		) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
				'namespace'       => 'eventbridge',
				'data_callback'   => function () {
					$ids = array();
					foreach ( $this->get_receipts() as $receipt ) {
						if ( empty( $receipt['reported'] ) && ! empty( $receipt['id'] ) ) {
							$ids[] = $receipt['id'];
						}
					}
					return array( 'pending_receipts' => array_slice( $ids, 0, self::MAX_RECEIPTS ) );
				},
				'schema_callback' => function () {
					return array(
						'pending_receipts' => array(
							'description' => 'Opaque EventBridge add-to-cart receipt identifiers.',
							'type'        => 'array',
							'readonly'    => true,
							'items'       => array( 'type' => 'string' ),
						),
					);
				},
			)
		);
	}

	public function handle_request() {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			wp_send_json_error( array( 'reason' => 'method_not_allowed' ), 405 );
		}
		$nonce = isset( $_POST['nonce'] ) && is_string( $_POST['nonce'] ) ? trim( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_nonce' ), 403 );
		}
		$type     = isset( $_POST['interaction'] ) && is_string( $_POST['interaction'] ) ? sanitize_key( wp_unslash( $_POST['interaction'] ) ) : '';
		$page_url = isset( $_POST['page_url'] ) && is_string( $_POST['page_url'] ) ? EventBridge_Meta_URL::canonicalize( wp_unslash( $_POST['page_url'] ) ) : '';
		if ( '' === $page_url || ! $this->same_origin( $page_url ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_page_url' ), 400 );
		}
		$route_contexts = $this->get_posted_route_contexts();

		if ( 'product_viewed' === $type ) {
			$context = isset( $_POST['context'] ) && is_string( $_POST['context'] ) ? $this->verify_context( wp_unslash( $_POST['context'] ), 'product_viewed' ) : false;
			if ( ! is_array( $context ) || empty( $context['snapshot'] ) || empty( $context['occurrence'] ) ) {
				wp_send_json_error( array( 'reason' => 'invalid_product_context' ), 400 );
			}
			$deliveries = $this->dispatch_claim_bucket( 'product_viewed', $context['snapshot'], $context['occurrence'], $page_url, $route_contexts );
			wp_send_json_success( array( 'deliveries' => $deliveries ) );
		}

		if ( 'added_to_cart' === $type ) {
			$deliveries = array();
			$receipts   = $this->get_receipts();
			foreach ( $receipts as $index => $receipt ) {
				if ( ! is_array( $receipt ) || ! empty( $receipt['reported'] ) || empty( $receipt['id'] ) || empty( $receipt['snapshot'] ) ) {
					continue;
				}
				$claims = isset( $receipt['claims'] ) && is_array( $receipt['claims'] ) ? $receipt['claims'] : array();
				$deliveries = array_merge( $deliveries, $this->dispatch_occurrence( 'added_to_cart', $receipt['snapshot'], $receipt['id'], $page_url, $claims, $route_contexts ) );
				$receipts[ $index ]['claims']   = $claims;
				$receipts[ $index ]['reported'] = true;
			}
			if ( $this->get_session() ) {
				$this->get_session()->set( self::RECEIPT_SESSION, $receipts );
			}
			wp_send_json_success( array( 'deliveries' => $deliveries ) );
		}

		if ( 'checkout_started' === $type ) {
			$context = isset( $_POST['context'] ) && is_string( $_POST['context'] ) ? $this->verify_context( wp_unslash( $_POST['context'] ), 'checkout_started' ) : false;
			$navigation_type = isset( $_POST['navigation_type'] ) && is_string( $_POST['navigation_type'] ) ? sanitize_key( wp_unslash( $_POST['navigation_type'] ) ) : 'navigate';
			if ( ! is_array( $context ) || ! in_array( $navigation_type, array( 'navigate', 'reload', 'back_forward' ), true ) ) {
				wp_send_json_error( array( 'reason' => 'invalid_checkout_context' ), 400 );
			}
			$snapshot = $this->build_cart_snapshot();
			if ( empty( $snapshot ) ) {
				wp_send_json_success( array( 'deliveries' => array(), 'attemptId' => '' ) );
			}
			$attempt = $this->get_checkout_attempt( $snapshot['fingerprint'], $navigation_type );
			$claims  = isset( $attempt['claims'] ) && is_array( $attempt['claims'] ) ? $attempt['claims'] : array();
			$deliveries = empty( $attempt['reported'] )
				? $this->dispatch_occurrence( 'checkout_started', $snapshot, $attempt['id'], $page_url, $claims, $route_contexts )
				: array();
			$attempt['claims']   = $claims;
			$attempt['reported'] = true;
			$this->get_session()->set( self::ATTEMPT_SESSION, $attempt );
			wp_send_json_success( array( 'deliveries' => $deliveries, 'attemptId' => $attempt['id'] ) );
		}

		if ( 'checkout_flow_activity' === $type ) {
			$context = isset( $_POST['context'] ) && is_string( $_POST['context'] ) ? $this->verify_context( wp_unslash( $_POST['context'] ), 'checkout_started', self::FLOW_GRACE_TTL ) : false;
			$attempt_id = isset( $_POST['attempt_id'] ) && is_string( $_POST['attempt_id'] ) ? trim( wp_unslash( $_POST['attempt_id'] ) ) : '';
			if ( ! is_array( $context ) ) {
				wp_send_json_error( array( 'reason' => 'invalid_checkout_context' ), 400 );
			}
			$snapshot = $this->build_cart_snapshot();
			if ( ! empty( $snapshot['fingerprint'] ) ) {
				$attempt = $this->get_session()->get( self::ATTEMPT_SESSION, array() );
				$attempt = $this->refresh_checkout_flow_attempt( $attempt, $snapshot['fingerprint'], $attempt_id, time() );
				$this->get_session()->set( self::ATTEMPT_SESSION, $attempt );
			}
			wp_send_json_success( array( 'deliveries' => array() ) );
		}

		if ( 'confirm_checkout_leave' === $type ) {
			$attempt_id = isset( $_POST['attempt_id'] ) && is_string( $_POST['attempt_id'] ) ? trim( wp_unslash( $_POST['attempt_id'] ) ) : '';
			$destination = isset( $_POST['destination'] ) && is_string( $_POST['destination'] ) ? EventBridge_Meta_URL::canonicalize( wp_unslash( $_POST['destination'] ) ) : '';
			$context = isset( $_POST['context'] ) && is_string( $_POST['context'] ) ? $this->verify_context( wp_unslash( $_POST['context'] ), 'checkout_started', self::FLOW_GRACE_TTL ) : false;
			$attempt = $this->get_session() ? $this->get_session()->get( self::ATTEMPT_SESSION, array() ) : array();
			if ( ( ! is_array( $attempt ) || empty( $attempt['id'] ) ) && is_array( $context ) ) {
				$snapshot = $this->build_cart_snapshot();
				if ( ! empty( $snapshot['fingerprint'] ) ) {
					$attempt = $this->get_checkout_attempt( $snapshot['fingerprint'], 'navigate' );
				}
			}
			$id_matches = is_array( $attempt ) && ! empty( $attempt['id'] )
				&& ( ( '' !== $attempt_id && hash_equals( $attempt['id'], $attempt_id ) ) || ( '' === $attempt_id && is_array( $context ) ) );
			if ( $id_matches && $this->is_ordinary_store_url( $destination ) ) {
				$attempt['left']    = true;
				$attempt['left_at'] = time();
				$attempt['flow_until'] = 0;
				$this->get_session()->set( self::ATTEMPT_SESSION, $attempt );
			}
			wp_send_json_success( array( 'deliveries' => array() ) );
		}

		wp_send_json_error( array( 'reason' => 'invalid_interaction' ), 400 );
	}

	private function dispatch_claim_bucket( $type, $snapshot, $occurrence_id, $page_url, $route_contexts ) {
		$session = $this->get_session();
		if ( ! $session ) {
			return array();
		}
		$buckets = $session->get( self::CLAIM_SESSION, array() );
		$buckets = is_array( $buckets ) ? $buckets : array();
		$now     = time();
		foreach ( $buckets as $key => $bucket ) {
			if ( ! is_array( $bucket ) || empty( $bucket['created_at'] ) || (int) $bucket['created_at'] < $now - self::RECEIPT_TTL ) {
				unset( $buckets[ $key ] );
			}
		}
		$bucket = isset( $buckets[ $occurrence_id ] ) && is_array( $buckets[ $occurrence_id ] )
			? $buckets[ $occurrence_id ]
			: array( 'created_at' => $now, 'claims' => array(), 'reported' => false );
		$deliveries = empty( $bucket['reported'] )
			? $this->dispatch_occurrence( $type, $snapshot, $occurrence_id, $page_url, $bucket['claims'], $route_contexts )
			: array();
		$bucket['reported'] = true;
		$buckets[ $occurrence_id ] = $bucket;
		if ( count( $buckets ) > self::MAX_CLAIM_BUCKETS ) {
			$buckets = array_slice( $buckets, -self::MAX_CLAIM_BUCKETS, null, true );
		}
		$session->set( self::CLAIM_SESSION, $buckets );
		return $deliveries;
	}

	private function dispatch_occurrence( $type, $snapshot, $occurrence_id, $page_url, &$claims, $route_contexts = array() ) {
		$deliveries = array();
		foreach ( $this->get_matching_routes( $type ) as $route_data ) {
			$event_key  = $route_data['event_key'];
			$trigger_id = $route_data['trigger']['trigger_id'];
			$route      = $route_data['route'];
			$claim_key  = $event_key . '|' . $trigger_id;
			$client_context = isset( $route_contexts[ $claim_key ] ) && is_array( $route_contexts[ $claim_key ] ) ? $route_contexts[ $claim_key ] : array();
			if ( isset( $claims[ $claim_key ] ) ) {
				continue;
			}
			if ( ! empty( $route['conditions'] ) ) {
				if ( ! $this->conditions ) {
					continue;
				}
				$context = $this->conditions->build_context( 'woocommerce', $type, $snapshot, $route['conditions'] );
				$result  = $this->conditions->evaluate( $route['conditions'], $context, $event_key, $trigger_id );
				if ( ! is_array( $result ) || 'match' !== $result['status'] ) {
					continue;
				}
			}

			$query_values = array();
			if ( $this->events->has_query_parameter_sources( $route ) ) {
				$query_values = isset( $client_context['parameter'] ) && is_string( $client_context['parameter'] )
					? $this->events->verify_parameter_context( $event_key, $route, $client_context['parameter'], $page_url )
					: false;
				if ( false === $query_values ) {
					continue;
				}
			}
			$fluent_data = false;
			if ( $this->fluent_booking && $this->fluent_booking->needs_lookup( $route ) ) {
				$fluent_data = isset( $client_context['fluent'] ) && is_string( $client_context['fluent'] )
					? $this->fluent_booking->verify_context( $event_key, $route, $page_url, $client_context['fluent'] )
					: false;
				if ( ! is_array( $fluent_data ) ) {
					continue;
				}
			}

			$event_id = wp_generate_uuid4();
			$parameters = $this->events->get_parameter_map( $route, $query_values, array(), array(), $snapshot );
			if ( is_array( $fluent_data ) ) {
				$parameters = array_merge( $parameters, $fluent_data['custom_data'] );
			}
			$details = array(
				'event_key'  => $event_key,
				'trigger_id' => $trigger_id,
				'event_name' => $route['event_name'],
				'event_id'   => $event_id,
				'page_url'   => $page_url,
				'context'    => array( 'interaction' => $type, 'occurrence_id' => $occurrence_id ),
			);
			$capi_started = false;
			if ( ! empty( $route['capi'] ) ) {
				$values = $this->events->get_advanced_matching_values( $route, array(), 'static' );
				$user   = $this->events->get_advanced_matching_user_data( $values );
				if ( $this->events->has_advanced_matching_source( $route, 'query_parameter' ) ) {
					$query_user = isset( $client_context['advanced'] ) && is_string( $client_context['advanced'] )
						? $this->events->verify_advanced_matching_context( $event_key, $route, $page_url, $client_context['advanced'] )
						: false;
					if ( false === $query_user && empty( $user ) ) {
						continue;
					}
					if ( is_array( $query_user ) ) {
						$user = array_merge( $user, $query_user );
					}
				}
				if ( is_array( $fluent_data ) ) {
					$user = array_merge( $user, $fluent_data['user_data'] );
				}
				$capi_started = $this->meta_capi->send_custom_event( $route['event_name'], $event_id, $page_url, $parameters, $details, $user, $route );
				if ( ! $capi_started && empty( $route['browser'] ) ) {
					continue;
				}
			}
			$claims[ $claim_key ] = array( 'event_id' => $event_id, 'created_at' => time(), 'capi' => $capi_started );
			$this->log->log( 'info', 'woocommerce_interaction', 'WooCommerce interaction accepted.', $details );
			if ( ! empty( $route['browser'] ) ) {
				$deliveries[] = array(
					'eventName'  => $route['event_name'],
					'eventId'    => $event_id,
					'parameters' => (object) $parameters,
					'eventKey'   => $event_key,
					'triggerId'  => $trigger_id,
				);
			}
		}
		return $deliveries;
	}

	private function get_matching_routes( $type ) {
		$routes = array();
		foreach ( $this->events->get_normalized_events() as $event_key => $event ) {
			if ( true !== $event['enabled'] || EventBridge_Triggers::FAMILY_FRONTEND !== $this->events->get_event_family( $event ) ) {
				continue;
			}
			foreach ( $event['triggers'] as $trigger ) {
				if ( ! is_array( $trigger ) || 'woocommerce' !== $trigger['provider'] || $type !== $trigger['trigger_type'] ) {
					continue;
				}
				$route = $this->events->get_effective_event( $event, $trigger );
				if ( ! empty( $route['event_name'] ) && ( ! empty( $route['browser'] ) || ! empty( $route['capi'] ) ) ) {
					$routes[] = array( 'event_key' => $event_key, 'trigger' => $trigger, 'route' => $route );
				}
			}
		}
		return $routes;
	}

	private function get_client_route_contexts() {
		$contexts = array();
		$page_url = $this->get_current_page_url();
		if ( '' === $page_url ) {
			return $contexts;
		}
		$query = $this->events->get_tracking_query( is_array( $_GET ) ? $_GET : array() );
		foreach ( array( 'product_viewed', 'added_to_cart', 'checkout_started' ) as $type ) {
			foreach ( $this->get_matching_routes( $type ) as $route_data ) {
				$event_key = $route_data['event_key'];
				$route     = $route_data['route'];
				$key       = $event_key . '|' . $route_data['trigger']['trigger_id'];
				$context   = array();
				if ( $this->events->has_query_parameter_sources( $route ) ) {
					$values = $this->events->get_query_parameter_values( $route, $query );
					$signed = $this->events->create_parameter_context( $event_key, $route, $values, $page_url );
					if ( '' !== $signed ) {
						$context['parameter'] = $signed;
					}
				}
				if ( $this->events->has_advanced_matching_source( $route, 'query_parameter' ) ) {
					$values = $this->events->get_advanced_matching_values( $route, $query, 'query_parameter' );
					$user   = $this->events->get_advanced_matching_user_data( $values );
					$signed = $this->events->create_advanced_matching_context( $event_key, $route, $page_url, $user );
					if ( '' !== $signed ) {
						$context['advanced'] = $signed;
					}
				}
				if ( $this->fluent_booking && $this->fluent_booking->needs_lookup( $route ) ) {
					$snapshot = $this->fluent_booking->resolve( $route, is_array( $_GET ) ? $_GET : array() );
					if ( is_array( $snapshot ) ) {
						$parameters = $this->fluent_booking->get_parameter_data( $route, $snapshot );
						$values     = $this->fluent_booking->get_advanced_matching_values( $route, $snapshot );
						$user       = $this->events->get_advanced_matching_user_data( $values );
						$signed     = $this->fluent_booking->create_context( $event_key, $route, $page_url, $parameters, $user );
						if ( '' !== $signed ) {
							$context['fluent'] = $signed;
						}
					}
				}
				if ( ! empty( $context ) ) {
					$contexts[ $key ] = $context;
				}
			}
		}
		return $contexts;
	}

	private function get_posted_route_contexts() {
		if ( ! isset( $_POST['route_contexts'] ) || ! is_string( $_POST['route_contexts'] ) ) {
			return array();
		}
		$raw = wp_unslash( $_POST['route_contexts'] );
		if ( strlen( $raw ) > 131072 ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || count( $decoded ) > EventBridge_Triggers::MAX_TRIGGERS * 10 ) {
			return array();
		}
		$result = array();
		foreach ( $decoded as $key => $context ) {
			if ( ! is_string( $key ) || ! preg_match( '/^evt_[0-9a-f-]{36}\|trg_[0-9a-f-]{36}$/D', $key ) || ! is_array( $context ) ) {
				continue;
			}
			$result[ $key ] = array();
			foreach ( array( 'parameter', 'advanced', 'fluent' ) as $field ) {
				if ( isset( $context[ $field ] ) && is_string( $context[ $field ] ) ) {
					$result[ $key ][ $field ] = $context[ $field ];
				}
			}
		}
		return $result;
	}

	private function get_current_page_url() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$home = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['scheme'] ) || empty( $home['host'] ) ) {
			return '';
		}
		$origin = strtolower( $home['scheme'] ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . absint( $home['port'] ) : '' );
		return EventBridge_Meta_URL::canonicalize( $origin . '/' . ltrim( wp_unslash( $_SERVER['REQUEST_URI'] ), '/' ) );
	}

	private function build_product_snapshot( $product, $quantity, $context ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return array();
		}
		$concrete_id = absint( $product->get_id() );
		$variation_id = is_a( $product, 'WC_Product_Variation' ) ? $concrete_id : 0;
		$parent_id = $variation_id > 0 ? absint( $product->get_parent_id() ) : $concrete_id;
		$taxonomy_product = $variation_id > 0 ? wc_get_product( $parent_id ) : $product;
		$quantity = max( 1, absint( $quantity ) );
		$price = $product->get_price();
		$snapshot = array(
			'eventbridge_context' => $context,
			'product_id'          => $concrete_id,
			'parent_id'           => $parent_id,
			'variation_id'        => $variation_id,
			'product_name'        => wp_strip_all_tags( $product->get_name() ),
			'quantity'            => $quantity,
			'currency'            => get_woocommerce_currency(),
			'content_ids'         => array( $concrete_id ),
			'product_ids'         => array_values( array_unique( array_filter( array( $concrete_id, $parent_id ) ) ) ),
			'parent_ids'          => array( $parent_id ),
			'variation_ids'       => $variation_id > 0 ? array( $variation_id ) : array(),
			'category_ids'        => is_a( $taxonomy_product, 'WC_Product' ) ? $taxonomy_product->get_category_ids() : array(),
			'tag_ids'             => is_a( $taxonomy_product, 'WC_Product' ) ? $taxonomy_product->get_tag_ids() : array(),
			'virtual_flags'       => array( true === $product->is_virtual() ),
			'downloadable_flags'  => array( true === $product->is_downloadable() ),
		);
		if ( is_numeric( $price ) ) {
			$snapshot['unit_price'] = (float) $price;
			$snapshot['line_value'] = (float) $price * $quantity;
		}
		if ( 'product_viewed' === $context && is_a( $product, 'WC_Product_Variable' ) ) {
			$snapshot['min_price'] = (float) $product->get_variation_price( 'min', false );
			$snapshot['max_price'] = (float) $product->get_variation_price( 'max', false );
			if ( $snapshot['min_price'] !== $snapshot['max_price'] ) {
				unset( $snapshot['unit_price'] );
			}
		}
		return $snapshot;
	}

	private function build_cart_snapshot() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}
		$items = array();
		$contents = array();
		$snapshot = array(
			'eventbridge_context' => 'checkout_started',
			'product_ids' => array(), 'parent_ids' => array(), 'variation_ids' => array(),
			'category_ids' => array(), 'tag_ids' => array(), 'virtual_flags' => array(), 'downloadable_flags' => array(),
			'content_ids' => array(), 'coupon_codes' => array_values( WC()->cart->get_applied_coupons() ),
			'currency' => get_woocommerce_currency(), 'total_quantity' => 0,
			'cart_subtotal' => (float) WC()->cart->get_subtotal(), 'cart_total' => (float) WC()->cart->get_total( 'edit' ),
		);
		foreach ( array_slice( WC()->cart->get_cart(), 0, EventBridge_WooCommerce::MAX_LINE_ITEMS, true ) as $item ) {
			$product = isset( $item['data'] ) && is_a( $item['data'], 'WC_Product' ) ? $item['data'] : false;
			if ( ! $product ) {
				continue;
			}
			$quantity = max( 1, absint( isset( $item['quantity'] ) ? $item['quantity'] : 1 ) );
			$row = $this->build_product_snapshot( $product, $quantity, 'checkout_started' );
			if ( empty( $row ) ) {
				continue;
			}
			foreach ( array( 'product_ids', 'parent_ids', 'variation_ids', 'category_ids', 'tag_ids', 'virtual_flags', 'downloadable_flags' ) as $key ) {
				$snapshot[ $key ] = array_merge( $snapshot[ $key ], isset( $row[ $key ] ) ? $row[ $key ] : array() );
			}
			$snapshot['content_ids'][] = $row['product_id'];
			$snapshot['total_quantity'] += $quantity;
			$contents[] = array( 'id' => $row['product_id'], 'quantity' => $quantity, 'item_price' => isset( $row['unit_price'] ) ? $row['unit_price'] : 0 );
			$variation = isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array();
			ksort( $variation );
			$items[] = array( 'product_id' => $row['parent_id'], 'variation_id' => $row['variation_id'], 'variation' => $variation, 'quantity' => $quantity );
		}
		if ( empty( $items ) ) {
			return array();
		}
		usort( $items, function ( $a, $b ) { return strcmp( wp_json_encode( $a ), wp_json_encode( $b ) ); } );
		foreach ( array( 'product_ids', 'parent_ids', 'variation_ids', 'category_ids', 'tag_ids', 'content_ids' ) as $key ) {
			$snapshot[ $key ] = array_values( array_unique( array_filter( array_map( 'absint', $snapshot[ $key ] ) ) ) );
		}
		$snapshot['contents']    = $contents;
		$snapshot['fingerprint'] = hash( 'sha256', (string) wp_json_encode( $items ) );
		return $snapshot;
	}

	private function get_checkout_attempt( $fingerprint, $navigation_type ) {
		$attempt = $this->get_session()->get( self::ATTEMPT_SESSION, array() );
		return $this->resolve_checkout_attempt( $attempt, $fingerprint, $navigation_type, time() );
	}

	public function resolve_checkout_attempt( $attempt, $fingerprint, $navigation_type, $now = null ) {
		$now     = null === $now ? time() : absint( $now );
		$attempt = is_array( $attempt ) ? $attempt : array();
		$fingerprint = is_string( $fingerprint ) ? $fingerprint : '';
		$navigation_type = in_array( $navigation_type, array( 'navigate', 'reload', 'back_forward' ), true ) ? $navigation_type : 'navigate';
		$new = empty( $attempt['id'] )
			|| empty( $attempt['fingerprint'] )
			|| ! hash_equals( (string) $attempt['fingerprint'], (string) $fingerprint )
			|| empty( $attempt['last_activity'] )
			|| ( (int) $attempt['last_activity'] < $now - self::ATTEMPT_TIMEOUT && ( empty( $attempt['flow_until'] ) || (int) $attempt['flow_until'] < $now ) )
			|| ( ! empty( $attempt['left'] ) && 'back_forward' !== $navigation_type );
		if ( $new ) {
			$attempt = array( 'id' => wp_generate_uuid4(), 'created_at' => $now, 'fingerprint' => $fingerprint, 'claims' => array(), 'reported' => false, 'left' => false );
		} elseif ( ! empty( $attempt['left'] ) && 'back_forward' === $navigation_type ) {
			$attempt['left'] = false;
		}
		$attempt['last_activity'] = $now;
		$attempt['fingerprint']   = $fingerprint;
		return $attempt;
	}

	public function refresh_checkout_flow_attempt( $attempt, $fingerprint, $attempt_id, $now = null ) {
		$now = null === $now ? time() : absint( $now );
		$attempt = is_array( $attempt ) ? $attempt : array();
		$fingerprint = is_string( $fingerprint ) ? $fingerprint : '';
		$attempt_id = is_string( $attempt_id ) ? trim( $attempt_id ) : '';
		$can_refresh = ! empty( $attempt['id'] ) && '' !== $attempt_id
			&& hash_equals( (string) $attempt['id'], $attempt_id )
			&& ! empty( $attempt['fingerprint'] ) && hash_equals( (string) $attempt['fingerprint'], $fingerprint );
		if ( ! $can_refresh ) {
			$attempt = $this->resolve_checkout_attempt( $attempt, $fingerprint, 'navigate', $now );
		}
		$attempt['last_activity'] = $now;
		$attempt['flow_until']    = $now + self::FLOW_GRACE_TTL;
		return $attempt;
	}

	private function get_receipts() {
		$session = $this->get_session();
		if ( ! $session ) {
			return array();
		}
		$now = time();
		return array_values( array_filter( (array) $session->get( self::RECEIPT_SESSION, array() ), function ( $receipt ) use ( $now ) {
			return is_array( $receipt ) && ! empty( $receipt['created_at'] ) && (int) $receipt['created_at'] >= $now - self::RECEIPT_TTL;
		} ) );
	}

	private function get_session() {
		return function_exists( 'WC' ) && WC() && WC()->session ? WC()->session : false;
	}

	private function is_canonical_product_request() {
		if ( ! function_exists( 'is_product' ) || ! is_product() || ! is_singular( 'product' ) ) {
			return false;
		}
		$object = get_queried_object();
		return is_a( $object, 'WP_Post' ) && 'product' === $object->post_type && absint( $object->ID ) === absint( get_queried_object_id() );
	}

	private function is_canonical_checkout_request() {
		return function_exists( 'is_checkout' ) && is_checkout()
			&& ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() )
			&& function_exists( 'WC' ) && WC()->cart && ! WC()->cart->is_empty();
	}

	private function sign_context( $payload ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) ) {
			return '';
		}
		$encoded = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
		return 'v1.' . $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
	}

	private function verify_context( $context, $kind, $ttl = self::ATTEMPT_TIMEOUT ) {
		if ( ! is_string( $context ) || strlen( $context ) > 65536 ) {
			return false;
		}
		$parts = explode( '.', $context );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] || ! hash_equals( hash_hmac( 'sha256', $parts[1], wp_salt( 'auth' ) ), $parts[2] ) ) {
			return false;
		}
		$padding = strlen( $parts[1] ) % 4;
		$decoded = base64_decode( strtr( $parts[1], '-_', '+/' ) . ( $padding ? str_repeat( '=', 4 - $padding ) : '' ), true );
		$data = is_string( $decoded ) ? json_decode( $decoded, true ) : null;
		$ttl = max( 1, absint( $ttl ) );
		return is_array( $data ) && isset( $data['kind'], $data['issued_at'] ) && $kind === $data['kind'] && (int) $data['issued_at'] >= time() - $ttl ? $data : false;
	}

	private function same_origin( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$test = wp_parse_url( $url );
		return is_array( $home ) && is_array( $test ) && ! empty( $home['host'] ) && ! empty( $test['host'] )
			&& 0 === strcasecmp( $home['host'], $test['host'] )
			&& ( isset( $home['port'] ) ? (int) $home['port'] : 0 ) === ( isset( $test['port'] ) ? (int) $test['port'] : 0 );
	}

	private function is_ordinary_store_url( $url ) {
		if ( '' === $url || ! $this->same_origin( $url ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$query_args = array();
		if ( '' !== $query ) {
			parse_str( $query, $query_args );
		}
		foreach ( array_keys( $query_args ) as $query_key ) {
			if ( in_array( strtolower( str_replace( '_', '-', (string) $query_key ) ), array( 'wc-api', 'wc-ajax', 'rest-route', 'order-pay', 'order-received' ), true ) ) {
				return false;
			}
		}
		$checkout_path = function_exists( 'wc_get_checkout_url' ) ? (string) wp_parse_url( wc_get_checkout_url(), PHP_URL_PATH ) : '';
		if ( '' !== $checkout_path && 0 === strpos( trailingslashit( $path ), trailingslashit( $checkout_path ) ) ) {
			return false;
		}
		return ! preg_match( '#/(order-pay|order-received|wc-api|checkout/order-pay|checkout/order-received)(/|$)#i', $path );
	}
}
