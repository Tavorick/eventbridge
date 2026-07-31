<?php

defined( 'ABSPATH' ) || exit;

class EventBridge_WooCommerce_Conditions implements EventBridge_Condition_Provider_Interface {
	const MINIMUM_VERSION = '8.2.0';
	const MAX_TEXT_LENGTH = 100;
	const MAX_AMOUNT      = 999999999999.99;
	const MAX_QUANTITY    = 100000000;

	public function get_key() {
		return 'woocommerce';
	}

	public function supports_event( $event ) {
		return is_array( $event ) && isset( $event['trigger_type'] )
			&& in_array( $event['trigger_type'], array( 'woocommerce', 'product_viewed', 'added_to_cart', 'checkout_started' ), true );
	}

	public function get_catalog() {
		$numeric_operators = array(
			'eq'  => __( 'is gelijk aan', 'eventbridge' ),
			'neq' => __( 'is niet gelijk aan', 'eventbridge' ),
			'gt'  => __( 'is groter dan', 'eventbridge' ),
			'gte' => __( 'is groter dan of gelijk aan', 'eventbridge' ),
			'lt'  => __( 'is kleiner dan', 'eventbridge' ),
			'lte' => __( 'is kleiner dan of gelijk aan', 'eventbridge' ),
		);

		return array(
			'product' => array(
				'label'     => __( 'Product', 'eventbridge' ),
				'contexts'  => array( 'order', 'product_viewed', 'added_to_cart', 'checkout_started' ),
				'operators' => array(
					'contains_exact'     => array( 'label' => __( 'bevat exact', 'eventbridge' ), 'value_type' => 'reference', 'search' => 'product' ),
					'contains_any'       => array( 'label' => __( 'bevat één van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product' ),
					'not_contains_exact' => array( 'label' => __( 'bevat niet', 'eventbridge' ), 'value_type' => 'reference', 'search' => 'product' ),
					'not_contains_any'   => array( 'label' => __( 'bevat geen van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product' ),
				),
			),
			'parent_product' => array(
				'label'     => __( 'Hoofdproduct inclusief variaties', 'eventbridge' ),
				'contexts'  => array( 'order', 'added_to_cart', 'checkout_started' ),
				'operators' => array( 'contains' => array( 'label' => __( 'bevat', 'eventbridge' ), 'value_type' => 'reference', 'search' => 'parent_product' ) ),
			),
			'variation' => array(
				'label'     => __( 'Specifieke variatie', 'eventbridge' ),
				'contexts'  => array( 'order', 'added_to_cart', 'checkout_started' ),
				'operators' => array( 'contains' => array( 'label' => __( 'bevat', 'eventbridge' ), 'value_type' => 'reference', 'search' => 'variation' ) ),
			),
			'product_category' => array(
				'label'     => __( 'Productcategorie', 'eventbridge' ),
				'contexts'  => array( 'order', 'product_viewed', 'added_to_cart', 'checkout_started' ),
				'operators' => array(
					'contains_any'     => array( 'label' => __( 'bevat één van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product_category' ),
					'not_contains_any' => array( 'label' => __( 'bevat geen van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product_category' ),
				),
			),
			'product_tag' => array(
				'label'     => __( 'Producttag', 'eventbridge' ),
				'contexts'  => array( 'order', 'product_viewed', 'added_to_cart', 'checkout_started' ),
				'operators' => array(
					'contains_any'     => array( 'label' => __( 'bevat één van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product_tag' ),
					'not_contains_any' => array( 'label' => __( 'bevat geen van', 'eventbridge' ), 'value_type' => 'references', 'search' => 'product_tag' ),
				),
			),
			'virtual_product' => array(
				'label'     => __( 'Virtueel product', 'eventbridge' ),
				'contexts'  => array( 'order', 'product_viewed', 'added_to_cart' ),
				'operators' => $this->get_flag_operators(),
			),
			'downloadable_product' => array(
				'label'     => __( 'Downloadbaar product', 'eventbridge' ),
				'contexts'  => array( 'order', 'product_viewed', 'added_to_cart' ),
				'operators' => $this->get_flag_operators(),
			),
			'order_total' => array(
				'label'     => __( 'Ordertotaal', 'eventbridge' ),
				'contexts'  => array( 'order' ),
				'operators' => $this->expand_operators( $numeric_operators, 'decimal' ),
			),
			'product_quantity_total' => array(
				'label'     => __( 'Totale producthoeveelheid', 'eventbridge' ),
				'contexts'  => array( 'order', 'checkout_started' ),
				'operators' => $this->expand_operators( $numeric_operators, 'integer' ),
			),
			'coupon' => array(
				'label'     => __( 'Coupon', 'eventbridge' ),
				'contexts'  => array( 'order', 'checkout_started' ),
				'operators' => array(
					'contains'     => array( 'label' => __( 'bevat', 'eventbridge' ), 'value_type' => 'coupon' ),
					'not_contains' => array( 'label' => __( 'bevat niet', 'eventbridge' ), 'value_type' => 'coupon' ),
				),
			),
			'payment_method' => array(
				'label'     => __( 'Betaalmethode', 'eventbridge' ),
				'contexts'  => array( 'order' ),
				'operators' => array(
					'eq'  => array( 'label' => __( 'is gelijk aan', 'eventbridge' ), 'value_type' => 'reference_string', 'search' => 'payment_method' ),
					'neq' => array( 'label' => __( 'is niet gelijk aan', 'eventbridge' ), 'value_type' => 'reference_string', 'search' => 'payment_method' ),
				),
			),
			'order_status' => array(
				'label'     => __( 'Orderstatus', 'eventbridge' ),
				'contexts'  => array( 'order' ),
				'operators' => array(
					'eq'  => array( 'label' => __( 'is gelijk aan', 'eventbridge' ), 'value_type' => 'reference_string', 'search' => 'order_status' ),
					'neq' => array( 'label' => __( 'is niet gelijk aan', 'eventbridge' ), 'value_type' => 'reference_string', 'search' => 'order_status' ),
				),
			),
			'action_quantity' => array(
				'label'     => __( 'Toegevoegd aantal', 'eventbridge' ),
				'contexts'  => array( 'added_to_cart' ),
				'operators' => $this->expand_operators( $numeric_operators, 'integer' ),
			),
			'cart_subtotal' => array(
				'label'     => __( 'Subtotaal winkelmand', 'eventbridge' ),
				'contexts'  => array( 'checkout_started' ),
				'operators' => $this->expand_operators( $numeric_operators, 'decimal' ),
			),
			'cart_total' => array(
				'label'     => __( 'Totaal winkelmand', 'eventbridge' ),
				'contexts'  => array( 'checkout_started' ),
				'operators' => $this->expand_operators( $numeric_operators, 'decimal' ),
			),
		);
	}

	public function validate_condition( $condition, $existing_conditions = array() ) {
		$errors   = array();
		$catalog  = $this->get_catalog();
		$field    = isset( $condition['field'] ) && is_scalar( $condition['field'] ) ? sanitize_key( wp_unslash( (string) $condition['field'] ) ) : '';
		$operator = isset( $condition['operator'] ) && is_scalar( $condition['operator'] ) ? sanitize_key( wp_unslash( (string) $condition['operator'] ) ) : '';
		$value    = array_key_exists( 'value', $condition ) ? $condition['value'] : null;

		if ( ! isset( $catalog[ $field ] ) ) {
			$errors[] = __( 'het gekozen veld is ongeldig.', 'eventbridge' );
		}
		if ( ! isset( $catalog[ $field ]['operators'][ $operator ] ) ) {
			$errors[] = __( 'de gekozen operator is ongeldig.', 'eventbridge' );
		}

		if ( ! empty( $errors ) ) {
			return array(
				'condition' => array( 'provider' => 'woocommerce', 'field' => $field, 'operator' => $operator, 'value' => null ),
				'errors'    => $errors,
			);
		}

		$operator_config = $catalog[ $field ]['operators'][ $operator ];
		$value_type      = $operator_config['value_type'];
		$normalized      = $this->normalize_value( $value, $value_type, $field );
		if ( ! $normalized['valid'] ) {
			$errors[] = $normalized['error'];
		}

		$normalized_value = $normalized['value'];
		$normalized_condition = array(
			'provider' => 'woocommerce',
			'field'    => $field,
			'operator' => $operator,
			'value'    => $normalized_value,
		);
		if ( empty( $errors ) && ! $this->is_runtime_available() && ! $this->condition_exists_exactly( $normalized_condition, $existing_conditions ) ) {
			$errors[] = __( 'WooCommerce is niet beschikbaar; alleen een bestaande ongewijzigde voorwaarde kan worden behouden.', 'eventbridge' );
		}

		if ( empty( $errors ) && in_array( $value_type, array( 'reference', 'references' ), true ) ) {
			$values = is_array( $normalized_value ) ? $normalized_value : array( $normalized_value );
			foreach ( $values as $reference ) {
				if ( ! $this->reference_exists( $field, $reference )
					&& ! $this->existing_reference_allowed( $field, $operator, $reference, $existing_conditions )
				) {
					$errors[] = sprintf( __( 'referentie #%d bestaat niet of is niet beschikbaar.', 'eventbridge' ), absint( $reference ) );
				}
			}
		}

		if ( empty( $errors ) && 'reference_string' === $value_type
			&& ! $this->string_reference_exists( $field, $normalized_value )
			&& ! $this->existing_reference_allowed( $field, $operator, $normalized_value, $existing_conditions )
		) {
			$errors[] = __( 'de gekozen waarde is niet beschikbaar.', 'eventbridge' );
		}

		return array(
			'condition' => $normalized_condition,
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	public function build_context( $trigger, $subject, $required_conditions ) {
		if ( is_array( $subject ) && isset( $subject['eventbridge_context'] ) ) {
			return $this->build_interaction_context( $trigger, $subject, $required_conditions );
		}
		if ( ! is_a( $subject, 'WC_Abstract_Order' ) || ! is_callable( array( $subject, 'get_items' ) ) ) {
			return array( 'provider' => 'woocommerce', 'available' => array(), 'values' => array(), 'reason' => 'order_context_missing' );
		}

		$fields = array();
		foreach ( is_array( $required_conditions ) ? $required_conditions : array() as $condition ) {
			if ( is_array( $condition ) && isset( $condition['field'] ) && is_scalar( $condition['field'] ) ) {
				$fields[ sanitize_key( (string) $condition['field'] ) ] = true;
			}
		}

		$available = array_fill_keys( array_keys( $fields ), true );
		$values    = array(
			'product'               => array(),
			'parent_product'        => array(),
			'variation'             => array(),
			'product_category'      => array(),
			'product_tag'           => array(),
			'virtual_product'       => array(),
			'downloadable_product'  => array(),
			'order_total'           => null,
			'product_quantity_total' => 0,
			'coupon'                => array(),
			'payment_method'        => '',
			'order_status'          => '',
		);

		if ( isset( $fields['order_total'] ) ) {
			$total = $this->normalize_decimal( $subject->get_total() );
			if ( false === $total ) {
				$available['order_total'] = false;
			} else {
				$values['order_total'] = $total;
			}
		}
		if ( isset( $fields['coupon'] ) ) {
			$coupons = $subject->get_coupon_codes();
			if ( ! is_array( $coupons ) ) {
				$available['coupon'] = false;
			} else {
				foreach ( $coupons as $coupon ) {
					$code = $this->normalize_coupon( $coupon );
					if ( '' !== $code ) {
						$values['coupon'][] = $code;
					}
				}
				$values['coupon'] = array_values( array_unique( $values['coupon'] ) );
			}
		}
		if ( isset( $fields['payment_method'] ) ) {
			$payment_method = $subject->get_payment_method();
			if ( ! is_scalar( $payment_method ) ) {
				$available['payment_method'] = false;
			} else {
				$values['payment_method'] = sanitize_key( (string) $payment_method );
			}
		}
		if ( isset( $fields['order_status'] ) ) {
			$status = $this->normalize_status( $subject->get_status() );
			if ( '' === $status ) {
				$available['order_status'] = false;
			} else {
				$values['order_status'] = $status;
			}
		}

		$item_fields = array( 'product', 'parent_product', 'variation', 'product_category', 'product_tag', 'virtual_product', 'downloadable_product', 'product_quantity_total' );
		if ( empty( array_intersect( array_keys( $fields ), $item_fields ) ) ) {
			return array( 'provider' => 'woocommerce', 'available' => $available, 'values' => $values, 'trigger' => $trigger );
		}

		$items = $subject->get_items( 'line_item' );
		if ( ! is_array( $items ) ) {
			foreach ( array_intersect( array_keys( $fields ), $item_fields ) as $field ) {
				$available[ $field ] = false;
			}
			return array( 'provider' => 'woocommerce', 'available' => $available, 'values' => $values, 'trigger' => $trigger );
		}

		$valid_product_rows = 0;
		foreach ( $items as $item ) {
			if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
				continue;
			}

			$product_id   = absint( $item->get_product_id() );
			$variation_id = absint( $item->get_variation_id() );
			$exact_id     = $variation_id > 0 ? $variation_id : $product_id;
			if ( $exact_id > 0 ) {
				$values['product'][] = $exact_id;
			}
			if ( $product_id > 0 ) {
				$values['parent_product'][] = $product_id;
			}
			if ( $variation_id > 0 ) {
				$values['variation'][] = $variation_id;
			}

			if ( isset( $fields['product_quantity_total'] ) ) {
				$quantity = $this->normalize_quantity( $item->get_quantity() );
				if ( false === $quantity || $values['product_quantity_total'] > self::MAX_QUANTITY - $quantity ) {
					$available['product_quantity_total'] = false;
				} else {
					$values['product_quantity_total'] += $quantity;
				}
			}

			if ( ! isset( $fields['product_category'] ) && ! isset( $fields['product_tag'] ) && ! isset( $fields['virtual_product'] ) && ! isset( $fields['downloadable_product'] ) ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! is_a( $product, 'WC_Product' ) ) {
				foreach ( array( 'product_category', 'product_tag', 'virtual_product', 'downloadable_product' ) as $field ) {
					if ( isset( $fields[ $field ] ) ) {
						$available[ $field ] = false;
					}
				}
				continue;
			}

			++$valid_product_rows;
			if ( isset( $fields['virtual_product'] ) ) {
				$values['virtual_product'][] = true === $product->is_virtual();
			}
			if ( isset( $fields['downloadable_product'] ) ) {
				$values['downloadable_product'][] = true === $product->is_downloadable();
			}

			if ( isset( $fields['product_category'] ) || isset( $fields['product_tag'] ) ) {
				$taxonomy_product = $product;
				if ( $variation_id > 0 ) {
					$taxonomy_product = wc_get_product( $product_id );
				}
				if ( ! is_a( $taxonomy_product, 'WC_Product' ) ) {
					if ( isset( $fields['product_category'] ) ) {
						$available['product_category'] = false;
					}
					if ( isset( $fields['product_tag'] ) ) {
						$available['product_tag'] = false;
					}
				} else {
					if ( isset( $fields['product_category'] ) ) {
						$values['product_category'] = array_merge( $values['product_category'], array_map( 'absint', $taxonomy_product->get_category_ids() ) );
					}
					if ( isset( $fields['product_tag'] ) ) {
						$values['product_tag'] = array_merge( $values['product_tag'], array_map( 'absint', $taxonomy_product->get_tag_ids() ) );
					}
				}
			}
		}

		foreach ( array( 'product', 'parent_product', 'variation', 'product_category', 'product_tag' ) as $field ) {
			$values[ $field ] = array_values( array_unique( array_filter( array_map( 'absint', $values[ $field ] ) ) ) );
		}
		foreach ( array( 'virtual_product', 'downloadable_product' ) as $field ) {
			if ( isset( $fields[ $field ] ) && ( 0 === $valid_product_rows || empty( $values[ $field ] ) ) ) {
				$available[ $field ] = false;
			}
		}

		return array( 'provider' => 'woocommerce', 'available' => $available, 'values' => $values, 'trigger' => $trigger );
	}

	private function build_interaction_context( $trigger, $subject, $required_conditions ) {
		$type = sanitize_key( (string) $subject['eventbridge_context'] );
		$fields = array();
		foreach ( is_array( $required_conditions ) ? $required_conditions : array() as $condition ) {
			if ( is_array( $condition ) && isset( $condition['field'] ) && is_scalar( $condition['field'] ) ) {
				$fields[ sanitize_key( (string) $condition['field'] ) ] = true;
			}
		}

		$catalog   = $this->get_catalog();
		$available = array();
		$values    = array();
		$map = array(
			'product'                => isset( $subject['product_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $subject['product_ids'] ) ) ) : array(),
			'parent_product'         => isset( $subject['parent_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $subject['parent_ids'] ) ) ) : array(),
			'variation'              => isset( $subject['variation_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $subject['variation_ids'] ) ) ) : array(),
			'product_category'       => isset( $subject['category_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $subject['category_ids'] ) ) ) : array(),
			'product_tag'            => isset( $subject['tag_ids'] ) ? array_values( array_unique( array_map( 'absint', (array) $subject['tag_ids'] ) ) ) : array(),
			'virtual_product'        => isset( $subject['virtual_flags'] ) ? array_map( 'boolval', (array) $subject['virtual_flags'] ) : array(),
			'downloadable_product'   => isset( $subject['downloadable_flags'] ) ? array_map( 'boolval', (array) $subject['downloadable_flags'] ) : array(),
			'product_quantity_total' => isset( $subject['total_quantity'] ) ? absint( $subject['total_quantity'] ) : null,
			'action_quantity'        => isset( $subject['quantity'] ) ? absint( $subject['quantity'] ) : null,
			'coupon'                 => isset( $subject['coupon_codes'] ) ? array_map( array( $this, 'normalize_coupon' ), (array) $subject['coupon_codes'] ) : array(),
			'cart_subtotal'          => isset( $subject['cart_subtotal'] ) ? $this->normalize_decimal( $subject['cart_subtotal'] ) : false,
			'cart_total'             => isset( $subject['cart_total'] ) ? $this->normalize_decimal( $subject['cart_total'] ) : false,
		);

		foreach ( array_keys( $fields ) as $field ) {
			$allowed = isset( $catalog[ $field ]['contexts'] ) && in_array( $type, $catalog[ $field ]['contexts'], true );
			$value   = array_key_exists( $field, $map ) ? $map[ $field ] : null;
			$valid   = $allowed && null !== $value && false !== $value;
			if ( $valid && in_array( $field, array( 'product', 'product_category', 'product_tag', 'virtual_product', 'downloadable_product' ), true ) ) {
				$valid = ! empty( $value );
			}
			$available[ $field ] = $valid;
			$values[ $field ]    = $value;
		}

		return array( 'provider' => 'woocommerce', 'available' => $available, 'values' => $values, 'trigger' => $trigger );
	}

	public function evaluate( $condition, $context ) {
		if ( ! is_array( $condition )
			|| ! isset( $condition['field'], $condition['operator'] )
			|| ! is_scalar( $condition['field'] )
			|| ! is_scalar( $condition['operator'] )
		) {
			return array( 'status' => 'invalid_context', 'reason' => 'condition_invalid' );
		}

		$field    = sanitize_key( (string) $condition['field'] );
		$operator = sanitize_key( (string) $condition['operator'] );
		$catalog  = $this->get_catalog();
		if ( ! isset( $catalog[ $field ]['operators'][ $operator ] ) ) {
			return array( 'status' => 'invalid_context', 'reason' => 'condition_catalog_invalid' );
		}
		if ( ! isset( $context['available'][ $field ] ) || true !== $context['available'][ $field ] || ! array_key_exists( $field, $context['values'] ) ) {
			return array( 'status' => 'invalid_context', 'reason' => $field . '_context_missing' );
		}

		$value_type = $catalog[ $field ]['operators'][ $operator ]['value_type'];
		$normalized = $this->normalize_value( isset( $condition['value'] ) ? $condition['value'] : null, $value_type, $field );
		if ( ! $normalized['valid'] ) {
			return array( 'status' => 'invalid_context', 'reason' => 'condition_value_invalid' );
		}

		$expected = $normalized['value'];
		$actual   = $context['values'][ $field ];
		$matched  = false;

		if ( 'product' === $field ) {
			$matched = in_array( $operator, array( 'contains_any', 'not_contains_any' ), true )
				? (bool) array_intersect( $actual, $expected )
				: in_array( $expected, $actual, true );
			if ( in_array( $operator, array( 'not_contains_exact', 'not_contains_any' ), true ) ) {
				$matched = ! $matched;
			}
		} elseif ( in_array( $field, array( 'parent_product', 'variation' ), true ) ) {
			$matched = in_array( $expected, $actual, true );
		} elseif ( in_array( $field, array( 'product_category', 'product_tag' ), true ) ) {
			$matched = (bool) array_intersect( $actual, $expected );
			if ( 'not_contains_any' === $operator ) {
				$matched = ! $matched;
			}
		} elseif ( in_array( $field, array( 'virtual_product', 'downloadable_product' ), true ) ) {
			$matched = 'any' === $operator
				? in_array( true, $actual, true )
				: ( 'all' === $operator ? ! in_array( false, $actual, true ) : ! in_array( true, $actual, true ) );
		} elseif ( in_array( $field, array( 'order_total', 'product_quantity_total', 'action_quantity', 'cart_subtotal', 'cart_total' ), true ) ) {
			$matched = $this->compare_numbers( $actual, $expected, $operator );
		} elseif ( 'coupon' === $field ) {
			$matched = in_array( $expected, $actual, true );
			if ( 'not_contains' === $operator ) {
				$matched = ! $matched;
			}
		} elseif ( in_array( $field, array( 'payment_method', 'order_status' ), true ) ) {
			$matched = (string) $actual === (string) $expected;
			if ( 'neq' === $operator ) {
				$matched = ! $matched;
			}
		} else {
			return array( 'status' => 'invalid_context', 'reason' => 'condition_field_invalid' );
		}

		return array( 'status' => $matched ? 'match' : 'mismatch', 'reason' => $matched ? '' : 'condition_not_matched' );
	}

	public function search_values( $field, $search, $page, $limit ) {
		$field  = sanitize_key( (string) $field );
		$search = trim( sanitize_text_field( (string) $search ) );
		$page   = max( 1, absint( $page ) );
		$limit  = min( 20, max( 1, absint( $limit ) ) );

		if ( in_array( $field, array( 'product', 'parent_product', 'variation' ), true ) ) {
			return $this->search_products( $field, $search, $page, $limit );
		}
		if ( in_array( $field, array( 'product_category', 'product_tag' ), true ) ) {
			return $this->search_terms( $field, $search, $page, $limit );
		}

		$options = 'payment_method' === $field ? $this->get_payment_methods() : ( 'order_status' === $field ? $this->get_order_statuses() : array() );
		$results = array();
		foreach ( $options as $id => $label ) {
			if ( '' === $search || false !== stripos( $id . ' ' . $label, $search ) ) {
				$results[] = array( 'id' => (string) $id, 'text' => (string) $label );
			}
		}

		return array( 'results' => array_slice( $results, ( $page - 1 ) * $limit, $limit ), 'more' => count( $results ) > $page * $limit );
	}

	public function resolve_value_labels( $field, $values ) {
		$field  = sanitize_key( (string) $field );
		$labels = array();

		foreach ( array_values( array_unique( $values ) ) as $value ) {
			if ( in_array( $field, array( 'product', 'parent_product', 'variation' ), true ) ) {
				$id      = absint( $value );
				$product = $id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : false;
				$labels[ (string) $id ] = $product && is_callable( array( $product, 'get_formatted_name' ) )
					? wp_strip_all_tags( $product->get_formatted_name() )
					: sprintf( __( '#%d — niet beschikbaar', 'eventbridge' ), $id );
			} elseif ( in_array( $field, array( 'product_category', 'product_tag' ), true ) ) {
				$id       = absint( $value );
				$taxonomy = 'product_category' === $field ? 'product_cat' : 'product_tag';
				$term     = $id > 0 ? get_term( $id, $taxonomy ) : false;
				$labels[ (string) $id ] = $term && ! is_wp_error( $term )
					? $term->name
					: sprintf( __( '#%d — niet beschikbaar', 'eventbridge' ), $id );
			} elseif ( 'payment_method' === $field ) {
				$options = $this->get_payment_methods();
				$key     = sanitize_key( (string) $value );
				$labels[ $key ] = isset( $options[ $key ] ) ? $options[ $key ] : sprintf( __( '%s — niet beschikbaar', 'eventbridge' ), $key );
			} elseif ( 'order_status' === $field ) {
				$options = $this->get_order_statuses();
				$key     = $this->normalize_status( $value );
				$labels[ $key ] = isset( $options[ $key ] ) ? $options[ $key ] : sprintf( __( '%s — niet beschikbaar', 'eventbridge' ), $key );
			}
		}

		return $labels;
	}

	private function get_flag_operators() {
		return array(
			'any'  => array( 'label' => __( 'minstens één is', 'eventbridge' ), 'value_type' => 'fixed_true' ),
			'all'  => array( 'label' => __( 'alle zijn', 'eventbridge' ), 'value_type' => 'fixed_true' ),
			'none' => array( 'label' => __( 'geen is', 'eventbridge' ), 'value_type' => 'fixed_true' ),
		);
	}

	private function expand_operators( $operators, $value_type ) {
		$expanded = array();
		foreach ( $operators as $key => $label ) {
			$expanded[ $key ] = array( 'label' => $label, 'value_type' => $value_type );
		}
		return $expanded;
	}

	private function normalize_value( $value, $type, $field ) {
		if ( 'fixed_true' === $type ) {
			$valid = true === $value || 1 === $value || '1' === $value || 'true' === $value;
			return array( 'valid' => $valid, 'value' => true, 'error' => __( 'de vaste waarde is ongeldig.', 'eventbridge' ) );
		}

		if ( 'reference' === $type ) {
			$id = is_scalar( $value ) ? absint( wp_unslash( (string) $value ) ) : 0;
			return array( 'valid' => $id > 0, 'value' => $id, 'error' => __( 'kies een geldige referentie.', 'eventbridge' ) );
		}

		if ( 'references' === $type ) {
			$values = is_array( $value ) ? $value : array();
			if ( empty( $values ) || count( $values ) > EventBridge_Conditions::MAX_REFERENCES ) {
				return array( 'valid' => false, 'value' => array(), 'error' => sprintf( __( 'kies tussen 1 en %d geldige referenties.', 'eventbridge' ), EventBridge_Conditions::MAX_REFERENCES ) );
			}
			$ids = array();
			foreach ( $values as $item ) {
				if ( ! is_scalar( $item ) || absint( wp_unslash( (string) $item ) ) < 1 ) {
					return array( 'valid' => false, 'value' => array(), 'error' => __( 'de referentielijst is ongeldig.', 'eventbridge' ) );
				}
				$ids[] = absint( wp_unslash( (string) $item ) );
			}
			return array( 'valid' => true, 'value' => array_values( array_unique( $ids ) ), 'error' => '' );
		}

		if ( 'decimal' === $type ) {
			$decimal = is_scalar( $value ) ? $this->normalize_decimal( wp_unslash( (string) $value ) ) : false;
			$valid   = false !== $decimal && (float) $decimal <= self::MAX_AMOUNT;
			return array( 'valid' => $valid, 'value' => $valid ? $decimal : '', 'error' => __( 'voer een geldig niet-negatief bedrag in.', 'eventbridge' ) );
		}

		if ( 'integer' === $type ) {
			$raw   = is_scalar( $value ) ? trim( wp_unslash( (string) $value ) ) : '';
			$valid = preg_match( '/^[0-9]+$/D', $raw ) && (float) $raw <= self::MAX_QUANTITY;
			return array( 'valid' => (bool) $valid, 'value' => $valid ? (int) $raw : 0, 'error' => __( 'voer een geldig niet-negatief geheel getal in.', 'eventbridge' ) );
		}

		if ( 'coupon' === $type ) {
			$raw    = is_scalar( $value ) ? trim( wp_unslash( (string) $value ) ) : '';
			$coupon = $this->normalize_coupon( $raw );
			$valid  = '' !== $coupon
				&& strlen( $coupon ) <= self::MAX_TEXT_LENGTH
				&& ! preg_match( '/[\x00-\x1F\x7F]/', $raw )
				&& $raw === wp_strip_all_tags( $raw );
			return array( 'valid' => $valid, 'value' => $coupon, 'error' => __( 'voer een geldige couponcode in.', 'eventbridge' ) );
		}

		if ( 'reference_string' === $type ) {
			$raw        = is_scalar( $value ) ? trim( wp_unslash( (string) $value ) ) : '';
			$normalized = 'order_status' === $field ? $this->normalize_status( $raw ) : sanitize_key( $raw );
			$valid      = '' !== $normalized
				&& strlen( $normalized ) <= self::MAX_TEXT_LENGTH
				&& ! preg_match( '/[\x00-\x1F\x7F]/', $raw )
				&& $raw === wp_strip_all_tags( $raw );
			return array( 'valid' => $valid, 'value' => $normalized, 'error' => __( 'kies een geldige waarde.', 'eventbridge' ) );
		}

		return array( 'valid' => false, 'value' => null, 'error' => __( 'het waardetype is ongeldig.', 'eventbridge' ) );
	}

	private function reference_exists( $field, $id ) {
		$id = absint( $id );
		if ( $id < 1 ) {
			return false;
		}
		if ( in_array( $field, array( 'product', 'parent_product', 'variation' ), true ) ) {
			if ( ! function_exists( 'wc_get_product' ) ) {
				return false;
			}
			$product = wc_get_product( $id );
			if ( ! is_a( $product, 'WC_Product' ) ) {
				return false;
			}
			if ( 'variation' === $field ) {
				return is_a( $product, 'WC_Product_Variation' );
			}
			if ( 'parent_product' === $field ) {
				return ! is_a( $product, 'WC_Product_Variation' );
			}
			return true;
		}
		if ( in_array( $field, array( 'product_category', 'product_tag' ), true ) ) {
			$taxonomy = 'product_category' === $field ? 'product_cat' : 'product_tag';
			return (bool) term_exists( $id, $taxonomy );
		}
		return false;
	}

	private function string_reference_exists( $field, $value ) {
		$options = 'payment_method' === $field ? $this->get_payment_methods() : ( 'order_status' === $field ? $this->get_order_statuses() : array() );
		return isset( $options[ $value ] );
	}

	private function existing_reference_allowed( $field, $operator, $reference, $existing_conditions ) {
		foreach ( is_array( $existing_conditions ) ? $existing_conditions : array() as $existing ) {
			if ( ! is_array( $existing )
				|| ! isset( $existing['provider'], $existing['field'], $existing['operator'] )
				|| 'woocommerce' !== $existing['provider']
				|| $field !== $existing['field']
				|| $operator !== $existing['operator']
			) {
				continue;
			}
			$values = isset( $existing['value'] ) && is_array( $existing['value'] ) ? $existing['value'] : array( isset( $existing['value'] ) ? $existing['value'] : null );
			if ( in_array( $reference, $values, true ) || in_array( (string) $reference, array_map( 'strval', $values ), true ) ) {
				return true;
			}
		}
		return false;
	}

	private function condition_exists_exactly( $condition, $existing_conditions ) {
		foreach ( is_array( $existing_conditions ) ? $existing_conditions : array() as $existing ) {
			if ( is_array( $existing )
				&& isset( $existing['provider'], $existing['field'], $existing['operator'] )
				&& $condition['provider'] === $existing['provider']
				&& $condition['field'] === $existing['field']
				&& $condition['operator'] === $existing['operator']
				&& isset( $existing['value'] )
				&& $condition['value'] === $existing['value']
			) {
				return true;
			}
		}
		return false;
	}

	private function is_runtime_available() {
		return defined( 'WC_VERSION' )
			&& version_compare( WC_VERSION, self::MINIMUM_VERSION, '>=' )
			&& function_exists( 'wc_get_order' )
			&& function_exists( 'wc_get_product' )
			&& class_exists( 'WC_Order' );
	}

	private function search_products( $field, $search, $page, $limit ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array( 'results' => array(), 'more' => false );
		}

		$results = array();
		if ( preg_match( '/^[1-9][0-9]*$/D', $search ) ) {
			$product = wc_get_product( absint( $search ) );
			if ( $this->product_matches_search_field( $product, $field ) ) {
				$results[] = $this->format_product_result( $product );
			}
		}

		$args = array(
			'limit'    => $limit,
			'page'     => $page,
			'paginate' => true,
			'status'   => array( 'publish', 'private', 'draft' ),
			'orderby'  => 'title',
			'order'    => 'ASC',
			'return'   => 'objects',
			's'        => $search,
		);
		if ( 'variation' === $field ) {
			$args['type'] = 'variation';
		}
		$query = wc_get_products( $args );
		$products = is_object( $query ) && isset( $query->products ) && is_array( $query->products ) ? $query->products : ( is_array( $query ) ? $query : array() );
		foreach ( $products as $product ) {
			if ( ! $this->product_matches_search_field( $product, $field ) ) {
				continue;
			}
			$result = $this->format_product_result( $product );
			if ( ! in_array( $result['id'], wp_list_pluck( $results, 'id' ), true ) ) {
				$results[] = $result;
			}
		}

		$total_pages = is_object( $query ) && isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : $page;
		return array( 'results' => array_values( $results ), 'more' => $page < $total_pages );
	}

	private function search_terms( $field, $search, $page, $limit ) {
		$taxonomy = 'product_category' === $field ? 'product_cat' : 'product_tag';
		$args     = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => $limit,
			'offset'     => ( $page - 1 ) * $limit,
			'search'     => $search,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array( 'results' => array(), 'more' => false );
		}

		$results = array();
		if ( preg_match( '/^[1-9][0-9]*$/D', $search ) ) {
			$term = get_term( absint( $search ), $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$results[] = array( 'id' => (string) $term->term_id, 'text' => $term->name );
			}
		}
		foreach ( $terms as $term ) {
			$id = (string) $term->term_id;
			if ( ! in_array( $id, wp_list_pluck( $results, 'id' ), true ) ) {
				$results[] = array( 'id' => $id, 'text' => $term->name );
			}
		}

		return array( 'results' => $results, 'more' => count( $terms ) === $limit );
	}

	private function product_matches_search_field( $product, $field ) {
		if ( ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}
		if ( 'variation' === $field ) {
			return is_a( $product, 'WC_Product_Variation' );
		}
		if ( 'parent_product' === $field ) {
			return ! is_a( $product, 'WC_Product_Variation' );
		}
		return true;
	}

	private function format_product_result( $product ) {
		$name = is_callable( array( $product, 'get_formatted_name' ) ) ? $product->get_formatted_name() : $product->get_name();
		return array( 'id' => (string) $product->get_id(), 'text' => wp_strip_all_tags( $name ) );
	}

	private function get_payment_methods() {
		$methods = array();
		if ( function_exists( 'WC' ) && WC() && is_callable( array( WC(), 'payment_gateways' ) ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			foreach ( is_array( $gateways ) ? $gateways : array() as $gateway ) {
				if ( is_object( $gateway ) && isset( $gateway->id ) ) {
					$id = sanitize_key( (string) $gateway->id );
					if ( '' !== $id ) {
						$title = is_callable( array( $gateway, 'get_title' ) ) ? $gateway->get_title() : $id;
						$methods[ $id ] = wp_strip_all_tags( (string) $title );
					}
				}
			}
		}
		return $methods;
	}

	private function get_order_statuses() {
		$statuses = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $status => $label ) {
				$key = $this->normalize_status( $status );
				if ( '' !== $key ) {
					$statuses[ $key ] = (string) $label;
				}
			}
		}
		return $statuses;
	}

	private function normalize_coupon( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( function_exists( 'wc_format_coupon_code' ) ) {
			return wc_format_coupon_code( $value );
		}
		return strtolower( sanitize_text_field( $value ) );
	}

	private function normalize_status( $status ) {
		$status = is_scalar( $status ) ? sanitize_key( (string) $status ) : '';
		return 0 === strpos( $status, 'wc-' ) ? substr( $status, 3 ) : $status;
	}

	private function normalize_decimal( $value ) {
		$raw = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $raw || ! preg_match( '/^[0-9]+(?:[.,][0-9]+)?$/D', $raw ) ) {
			return false;
		}
		$decimal = function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $raw, false ) : str_replace( ',', '.', $raw );
		return is_string( $decimal ) && preg_match( '/^[0-9]+(?:\.[0-9]+)?$/D', $decimal ) ? $decimal : false;
	}

	private function normalize_quantity( $value ) {
		if ( ! is_numeric( $value ) || (float) $value < 0 || floor( (float) $value ) !== (float) $value || (float) $value > self::MAX_QUANTITY ) {
			return false;
		}
		return (int) $value;
	}

	private function compare_numbers( $actual, $expected, $operator ) {
		$actual   = (float) $actual;
		$expected = (float) $expected;
		switch ( $operator ) {
			case 'eq':
				return $actual === $expected;
			case 'neq':
				return $actual !== $expected;
			case 'gt':
				return $actual > $expected;
			case 'gte':
				return $actual >= $expected;
			case 'lt':
				return $actual < $expected;
			case 'lte':
				return $actual <= $expected;
		}
		return false;
	}
}
