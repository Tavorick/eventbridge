<?php

class EventBridge_WooCommerce_Conditions_Test extends WP_UnitTestCase {
	private $provider;

	public function set_up() {
		parent::set_up();
		$this->provider = new EventBridge_WooCommerce_Conditions();
	}

	public function test_catalog_contains_the_complete_120_contract() {
		$catalog = $this->provider->get_catalog();

		$this->assertSame(
			array(
				'product',
				'parent_product',
				'variation',
				'product_category',
				'product_tag',
				'virtual_product',
				'downloadable_product',
				'order_total',
				'product_quantity_total',
				'coupon',
				'payment_method',
				'order_status',
			),
			array_keys( $catalog )
		);
		$this->assertSame( array( 'any', 'all', 'none' ), array_keys( $catalog['virtual_product']['operators'] ) );
		$this->assertSame( array( 'eq', 'neq', 'gt', 'gte', 'lt', 'lte' ), array_keys( $catalog['order_total']['operators'] ) );
	}

	public function test_scalar_and_collection_operators_use_expected_semantics() {
		$context = array(
			'provider'  => 'woocommerce',
			'available' => array(
				'product'               => true,
				'parent_product'        => true,
				'variation'             => true,
				'product_category'      => true,
				'product_tag'           => true,
				'virtual_product'       => true,
				'downloadable_product'  => true,
				'order_total'           => true,
				'product_quantity_total' => true,
				'coupon'                => true,
				'payment_method'        => true,
				'order_status'          => true,
			),
			'values' => array(
				'product'               => array( 10, 22 ),
				'parent_product'        => array( 10, 20 ),
				'variation'             => array( 22 ),
				'product_category'      => array( 5, 6 ),
				'product_tag'           => array( 8 ),
				'virtual_product'       => array( true, false ),
				'downloadable_product'  => array( true, true ),
				'order_total'           => '125.50',
				'product_quantity_total' => 3,
				'coupon'                => array( 'summer' ),
				'payment_method'        => 'bacs',
				'order_status'          => 'processing',
			),
		);
		$matches = array(
			array( 'field' => 'product', 'operator' => 'contains_exact', 'value' => 22 ),
			array( 'field' => 'product', 'operator' => 'contains_any', 'value' => array( 1, 10 ) ),
			array( 'field' => 'product', 'operator' => 'not_contains_exact', 'value' => 99 ),
			array( 'field' => 'parent_product', 'operator' => 'contains', 'value' => 20 ),
			array( 'field' => 'variation', 'operator' => 'contains', 'value' => 22 ),
			array( 'field' => 'product_category', 'operator' => 'contains_any', 'value' => array( 4, 6 ) ),
			array( 'field' => 'product_tag', 'operator' => 'not_contains_any', 'value' => array( 9 ) ),
			array( 'field' => 'virtual_product', 'operator' => 'any', 'value' => true ),
			array( 'field' => 'downloadable_product', 'operator' => 'all', 'value' => true ),
			array( 'field' => 'virtual_product', 'operator' => 'none', 'value' => true, 'expected' => 'mismatch' ),
			array( 'field' => 'order_total', 'operator' => 'gte', 'value' => '125.50' ),
			array( 'field' => 'product_quantity_total', 'operator' => 'eq', 'value' => 3 ),
			array( 'field' => 'coupon', 'operator' => 'contains', 'value' => 'SUMMER' ),
			array( 'field' => 'payment_method', 'operator' => 'eq', 'value' => 'bacs' ),
			array( 'field' => 'order_status', 'operator' => 'neq', 'value' => 'completed' ),
		);

		foreach ( $matches as $condition ) {
			$expected = isset( $condition['expected'] ) ? $condition['expected'] : 'match';
			unset( $condition['expected'] );
			$condition['provider'] = 'woocommerce';
			$result = $this->provider->evaluate( $condition, $context );
			$this->assertSame( $expected, $result['status'], $condition['field'] . ':' . $condition['operator'] );
		}
	}

	public function test_missing_required_field_context_fails_closed() {
		$result = $this->provider->evaluate(
			array( 'provider' => 'woocommerce', 'field' => 'product_tag', 'operator' => 'contains_any', 'value' => array( 1 ) ),
			array( 'provider' => 'woocommerce', 'available' => array( 'product_tag' => false ), 'values' => array( 'product_tag' => array() ) )
		);

		$this->assertSame( 'invalid_context', $result['status'] );
	}

	public function test_missing_saved_reference_is_preserved_but_new_missing_reference_is_rejected() {
		$condition = array( 'provider' => 'woocommerce', 'field' => 'payment_method', 'operator' => 'eq', 'value' => 'removed_gateway' );

		$preserved = $this->provider->validate_condition( $condition, array( $condition ) );
		$new       = $this->provider->validate_condition( $condition, array() );

		$this->assertSame( array(), $preserved['errors'] );
		$this->assertNotEmpty( $new['errors'] );
		$this->assertSame( 'removed_gateway', $preserved['condition']['value'] );
	}

	public function test_real_order_context_includes_variation_parent_taxonomy_and_flags() {
		if ( ! function_exists( 'wc_create_order' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'A live WooCommerce runtime is required.' );
		}

		$product   = new WC_Product_Simple();
		$parent    = new WC_Product_Variable();
		$variation = new WC_Product_Variation();
		$order     = null;
		$category  = null;
		$tag       = null;
		try {
			$product->set_name( 'Conditional physical product' );
			$product->set_status( 'publish' );
			$product->set_regular_price( '5' );
			$product->save();

			$category = wp_insert_term( 'EventBridge condition category', 'product_cat' );
			$tag      = wp_insert_term( 'EventBridge condition tag', 'product_tag' );
			$this->assertFalse( is_wp_error( $category ) );
			$this->assertFalse( is_wp_error( $tag ) );

			$parent->set_name( 'Conditional variable parent' );
			$parent->set_status( 'publish' );
			$parent->set_category_ids( array( absint( $category['term_id'] ) ) );
			$parent->set_tag_ids( array( absint( $tag['term_id'] ) ) );
			$parent->save();

			$variation->set_parent_id( $parent->get_id() );
			$variation->set_status( 'publish' );
			$variation->set_regular_price( '25' );
			$variation->set_virtual( true );
			$variation->set_downloadable( true );
			$variation->save();

			$order = wc_create_order( array( 'status' => 'pending' ) );
			$order->add_product( $variation, 2 );
			$order->add_product( $product, 1 );
			$order->calculate_totals();
			$order->save();

			$conditions = array(
				array( 'provider' => 'woocommerce', 'field' => 'product', 'operator' => 'contains_exact', 'value' => $variation->get_id() ),
				array( 'provider' => 'woocommerce', 'field' => 'parent_product', 'operator' => 'contains', 'value' => $parent->get_id() ),
				array( 'provider' => 'woocommerce', 'field' => 'variation', 'operator' => 'contains', 'value' => $variation->get_id() ),
				array( 'provider' => 'woocommerce', 'field' => 'product_category', 'operator' => 'contains_any', 'value' => array( absint( $category['term_id'] ) ) ),
				array( 'provider' => 'woocommerce', 'field' => 'product_tag', 'operator' => 'contains_any', 'value' => array( absint( $tag['term_id'] ) ) ),
				array( 'provider' => 'woocommerce', 'field' => 'virtual_product', 'operator' => 'any', 'value' => true ),
				array( 'provider' => 'woocommerce', 'field' => 'downloadable_product', 'operator' => 'any', 'value' => true ),
				array( 'provider' => 'woocommerce', 'field' => 'product_quantity_total', 'operator' => 'eq', 'value' => 3 ),
			);
			$context = $this->provider->build_context( array( 'signal' => 'created' ), $order, $conditions );

			foreach ( $conditions as $condition ) {
				$this->assertSame( 'match', $this->provider->evaluate( $condition, $context )['status'] );
			}
		} finally {
			if ( is_a( $order, 'WC_Order' ) ) {
				$order->delete( true );
			}
			if ( $product->get_id() ) {
				$product->delete( true );
			}
			if ( $variation->get_id() ) {
				$variation->delete( true );
			}
			if ( $parent->get_id() ) {
				$parent->delete( true );
			}
			if ( is_array( $category ) && isset( $category['term_id'] ) ) {
				wp_delete_term( $category['term_id'], 'product_cat' );
			}
			if ( is_array( $tag ) && isset( $tag['term_id'] ) ) {
				wp_delete_term( $tag['term_id'], 'product_tag' );
			}
		}
	}
}
