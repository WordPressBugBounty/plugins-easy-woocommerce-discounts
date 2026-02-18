<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Cart_Item_Pricing_Discounts {

	public $item_id;

	public $item;

	public $product_id;

	public $variation_id;

	protected $cart;

	protected $pricing;

	protected $pricings;

	protected $pricing_cache;

	/**
	 * Constructor.
	 *
	 * @param string                        $cart_item_id
	 * @param array                         $cart_item
	 * @param WCCS_Pricing                  $pricing
	 * @param WCCS_Cart|null                $cart
	 * @param WCCS_Cart_Pricing_Cache|null  $pricing_cache
	 */
	public function __construct( $cart_item_id, $cart_item, WCCS_Pricing $pricing, $cart = null, $pricing_cache = null ) {
		$this->item_id = $cart_item_id;
		$this->item = $cart_item;
		$this->pricing = $pricing;
		$this->pricings = $this->pricing->get_pricings();
		$this->product_id = $cart_item['product_id'];
		$this->variation_id = $cart_item['variation_id'];
		$this->cart = null !== $cart ? $cart : WCCS()->cart;
		$this->pricing_cache = $pricing_cache;
	}

	public function get_discounts() {
		$discounts = $this->get_simple_discounts()
			+ $this->get_bulk_discounts()
			+ $this->get_purchase_discounts();

		if ( ! empty( $discounts ) ) {
			usort( $discounts, array( WCCS()->WCCS_Sorting, 'sort_by_order_asc' ) );
			$discounts = $this->pricing->rules_filter->by_apply_mode( $discounts );
		}

		return $discounts;
	}

	public function get_pricings() {
		$pricings = $this->get_simple_pricings()
			+ $this->get_bulk_pricings()
			+ $this->get_purchase_pricings();

		if ( ! empty( $pricings ) ) {
			usort( $pricings, array( WCCS()->WCCS_Sorting, 'sort_by_order_asc' ) );
			$pricings = $this->pricing->rules_filter->by_apply_mode( $pricings );
		}

		return $pricings;
	}

	public function get_simple_discounts() {
		if ( ! apply_filters( 'wccs_cart_item_simple_discounts', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['simple'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_simple_discounts', array() );
		}

		$discounts = array();
		foreach ( $this->pricings['simple'] as $pricing_id => $pricing ) {
			if ( in_array( $pricing['discount_type'], array( 'percentage_fee', 'price_fee' ) ) ) {
				continue;
			} elseif ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			} elseif ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$discounts[ $pricing_id ] = array(
				'id' => $pricing_id,
				'name' => $pricing['name'],
				'description' => $pricing['description'],
				'mode' => $pricing['mode'],
				'apply_mode' => $pricing['apply_mode'],
				'order' => (int) $pricing['order'],
				'discount' => (float) $pricing['discount'],
				'discount_type' => $pricing['discount_type'],
				'date_time' => $pricing['date_time'],
				'date_times_match_mode' => $pricing['date_times_match_mode'],
			);
		}

		return apply_filters( 'wccs_cart_item_pricing_simple_discounts', $discounts );
	}

	public function get_simple_pricings() {
		if ( ! apply_filters( 'wccs_cart_item_simple_pricings', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['simple'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_simple_pricings', array() );
		}

		$pricings = array();
		foreach ( $this->pricings['simple'] as $pricing_id => $pricing ) {
			if ( in_array( $pricing['discount_type'], array( 'percentage_fee', 'price_fee' ) ) ) {
				continue;
			} elseif ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			} elseif ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$pricings[ $pricing_id ] = array(
				'id' => $pricing_id,
				'name' => $pricing['name'],
				'description' => $pricing['description'],
				'mode' => $pricing['mode'],
				'apply_mode' => $pricing['apply_mode'],
				'order' => (int) $pricing['order'],
				'date_time' => $pricing['date_time'],
				'date_times_match_mode' => $pricing['date_times_match_mode'],
			);
		}

		return apply_filters( 'wccs_cart_item_pricing_simple_pricings', $pricings );
	}

	public function get_bulk_discounts() {
		if ( ! apply_filters( 'wccs_cart_item_bulk_discounts', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['bulk'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_bulk_discounts', array() );
		}

		$discounts = array();
		foreach ( $this->pricings['bulk'] as $pricing_id => $pricing ) {
			if ( empty( $pricing['quantities'] ) ) {
				continue;
			} elseif ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			} elseif ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$items_quantities = $this->cart->get_items_quantities( $pricing['items'], $pricing['quantity_based_on'], true );
			if ( empty( $items_quantities ) ) {
				continue;
			}

			$item_quantity = 0;

			if ( 'single_product' === $pricing['quantity_based_on'] ) {
				if ( isset( $items_quantities[ $this->product_id ] ) ) {
					$item_quantity += $items_quantities[ $this->product_id ]['count'];
				}
			}

			if ( $item_quantity > 0 ) {
				foreach ( $pricing['quantities'] as $quantity ) {
					if ( intval( $quantity['min'] ) <= $item_quantity && ( '' === $quantity['max'] || intval( $quantity['max'] ) >= $item_quantity ) ) {
						$discounts[ $pricing_id ] = array(
							'id' => $pricing_id,
							'name' => $pricing['name'],
							'description' => $pricing['description'],
							'mode' => $pricing['mode'],
							'apply_mode' => $pricing['apply_mode'],
							'order' => (int) $pricing['order'],
							'discount' => (float) $quantity['discount'],
							'discount_type' => $quantity['discount_type'],
							'date_time' => $pricing['date_time'],
							'date_times_match_mode' => $pricing['date_times_match_mode'],
						);
						break;
					}
				}
			}
		}

		return apply_filters( 'wccs_cart_item_pricing_bulk_discounts', $discounts );
	}

	public function get_bulk_pricings() {
		if ( ! apply_filters( 'wccs_cart_item_bulk_pricings', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['bulk'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_bulk_pricings', array() );
		}

		$pricings = array();
		foreach ( $this->pricings['bulk'] as $pricing_id => $pricing ) {
			if ( empty( $pricing['quantities'] ) ) {
				continue;
			} elseif ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			} elseif ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$pricings[ $pricing_id ] = array(
				'id' => $pricing_id,
				'name' => $pricing['name'],
				'description' => $pricing['description'],
				'mode' => $pricing['mode'],
				'apply_mode' => $pricing['apply_mode'],
				'order' => (int) $pricing['order'],
				'date_time' => $pricing['date_time'],
				'date_times_match_mode' => $pricing['date_times_match_mode'],
			);
		}

		return apply_filters( 'wccs_cart_item_pricing_bulk_pricings', $pricings );
	}

	public function get_purchase_discounts() {
		if ( ! apply_filters( 'wccs_cart_item_purchase_discounts', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['purchase'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_purchase_discounts', array() );
		}

		$rules = $this->pricings['purchase'];
		if ( (int) WCCS()->settings->get_setting( 'auto_add_free_to_cart', 1 ) ) {
			$rules = $this->pricing->get_purchase_pricings( 'exclude_auto' );
			if ( empty( $rules ) ) {
				return apply_filters( 'wccs_cart_item_pricing_purchase_discounts', array() );
			}
		}

		$applied_pricings = $this->pricing_cache ? $this->pricing_cache->get_applied_pricings() : array();

		$discounts = array();
		$consumed_quantities = array();
		foreach ( $rules as $pricing_id => $pricing ) {
			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			if ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$receive_items = array();

			if ( isset( $pricing['mode_type'] ) && 'bogo' === $pricing['mode_type'] ) {
				$bogo = $this->calculate_bogo( $pricing, $consumed_quantities );
				if ( empty( $bogo ) ) {
					continue;
				}

				if ( ! empty( $bogo['consumed'] ) ) {
					$consumed_quantities[ $this->item_id ] = isset( $consumed_quantities[ $this->item_id ] ) ?
						$consumed_quantities[ $this->item_id ] + $bogo['consumed'] : $bogo['consumed'];
				}

				if ( ! empty( $bogo['get'] ) ) {
					$receive_items[ $this->item_id ] = $bogo['get'];
				}
			} else {
				// Checking if this pricing rule already cached?
				if ( isset( $applied_pricings[ $pricing_id ] ) ) {
					if ( isset( $applied_pricings[ $pricing_id ]['receive_items'][ $this->item_id ] ) ) {
						$discounts[ $pricing_id ] = $applied_pricings[ $pricing_id ];
						$discounts[ $pricing_id ]['receive_quantity'] = $applied_pricings[ $pricing_id ]['receive_items'][ $this->item_id ];
					}
					continue;
				}

				// Get items quantities group sorted by price in descending or highest prices first.
				$purchase_quantities_group = $this->cart->get_items_quantities(
					$pricing['purchased_items'],
					( ! empty( $pricing['quantity_based_on'] ) ? $pricing['quantity_based_on'] : 'all_products' ),
					true,
					'price',
					'desc',
					! empty( $pricing['exclude_items'] ) ? $pricing['exclude_items'] : array(),
					true
				);
				if ( empty( $purchase_quantities_group ) ) {
					continue;
				}

				foreach ( $purchase_quantities_group as $key => $group ) {
					if ( empty( $group['count'] ) ) {
						continue;
					}

					$quantities = $this->find_purchase_in_group( $pricing, $group, $consumed_quantities );
					if ( empty( $quantities ) || empty( $quantities['receive'] ) ) {
						continue;
					}

					foreach ( $quantities['receive'] as $key => $quantity ) {
						$receive_items[ $key ] = ! empty( $receive_items[ $key ] ) ?
							$receive_items[ $key ] + $quantity : $quantity;
					}

					if ( empty( $quantities['receive'][ $this->item_id ] ) ) {
						continue;
					}

					if ( 'true' !== $pricing['repeat'] ) {
						break;
					}
				}
			}

			if ( ! empty( $receive_items[ $this->item_id ] ) ) {
				$discount_content = array(
					'id' => $pricing_id,
					'name' => $pricing['name'],
					'description' => $pricing['description'],
					'mode' => $pricing['mode'],
					'apply_mode' => $pricing['apply_mode'],
					'order' => (int) $pricing['order'],
					'discount' => (float) $pricing['purchase']['discount'],
					'discount_type' => $pricing['purchase']['discount_type'],
					'receive_quantity' => $receive_items[ $this->item_id ],
					'receive_items' => $receive_items,
					'date_time' => $pricing['date_time'],
					'date_times_match_mode' => $pricing['date_times_match_mode'],
				);

				$discounts[ $pricing_id ] = $discount_content;

				if ( $this->pricing_cache ) {
					$this->pricing_cache->add_applied_pricing( $pricing_id, $discount_content );
				}
			}
		}

		return apply_filters( 'wccs_cart_item_pricing_purchase_discounts', $discounts );
	}

	/**
	 * Find purhcase discount type in the given group.
	 *
	 * @since  4.0.0
	 *
	 * @param  array $pricing
	 * @param  array $group
	 * @param  array $consumed_quantities
	 *
	 * @return array
	 */
	protected function find_purchase_in_group( $pricing, $group, &$consumed_quantities = array() ) {
		if ( empty( $group['count'] ) ) {
			return array();
		}

		// Checking if purchased items same as discounted items.
		$same_items = false;
		if ( ( ! empty( $pricing['mode_type'] ) && 'purchase_x_receive_y_same' === $pricing['mode_type'] ) || $pricing['items'] == $pricing['purchased_items'] ) {
			if ( empty( $pricing['exclude_items'] ) || ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				$same_items = true;
			}
		}

		$items = $group['items'];
		$receive_items_quantities = array();
		if ( ! $same_items ) {
			// Get receive items quantities with lowest price items first.
			$receive_items_quantities = $this->get_receive_items_quantities( $pricing['items'] );
			if ( empty( $receive_items_quantities ) ) {
				return array();
			}

			/**
			 * Append receive items to the end of items if they are exists in the items.
			 * The receive items will be calculated at the end.
			 */
			$items = array();
			$end_items = array();
			foreach ( $group['items'] as $key => $value ) {
				if ( isset( $receive_items_quantities[ $key ] ) ) {
					$end_items[ $key ] = $value;
				} else {
					$items[ $key ] = $value;
				}
			}
			$items = array_merge( $items, $end_items );
		}

		return $this->retrieve_purchase_receive_quantities( $pricing, $items, $same_items, $consumed_quantities, $receive_items_quantities );
	}

	/**
	 * Set purchase and receive quantities for BOGO deals.
	 *
	 * @param array   $pricing                  pricing rule
	 * @param array   $items                    Items to find purchase and receive quantities between them
	 * @param boolean $same_items               If Buy and Get items are same
	 * @param array   $consumed_quantities      Consumed quantities
	 * @param array   $receive_items_quantities Get or Receive items quantities
	 *
	 * @return array
	 */
	protected function retrieve_purchase_receive_quantities(
		$pricing,
		$items,
		$same_items,
		&$consumed_quantities,
		$receive_items_quantities
	) {
		$quantities = array( 'purchase' => array(), 'receive' => array() );
		$temp_consumed_quantities = $consumed_quantities;
		while ( $purchase_quantities = $this->find_purchase_quantities( $items, (int) $pricing['purchase']['purchase'], $temp_consumed_quantities ) ) {
			$temp_consumed_quantities = $consumed_quantities;
			foreach ( $purchase_quantities as $key => $quantity ) {
				$temp_consumed_quantities[ $key ] = ! empty( $temp_consumed_quantities[ $key ] ) ?
					$temp_consumed_quantities[ $key ] + $quantity : $quantity;
			}

			$receive_quantitites = $this->find_purchase_quantities(
					// group['items'] is in highest prices first order and reversed for lowest prices first order.
				( $same_items ? array_reverse( $items, true ) : $receive_items_quantities ),
				(int) $pricing['purchase']['receive'],
				$temp_consumed_quantities,
				false
			);
			if ( ! $receive_quantitites ) {
				break;
			}

			foreach ( $purchase_quantities as $key => $quantity ) {
				$quantities['purchase'][ $key ] = ! empty( $quantities['purchase'][ $key ] ) ?
					$quantities['purchase'][ $key ] + $quantity : $quantity;
			}

			foreach ( $receive_quantitites as $key => $quantity ) {
				$quantities['receive'][ $key ] = ! empty( $quantities['receive'][ $key ] ) ?
					$quantities['receive'][ $key ] + $quantity : $quantity;

				$temp_consumed_quantities[ $key ] = ! empty( $temp_consumed_quantities[ $key ] ) ?
					$temp_consumed_quantities[ $key ] + $quantity : $quantity;
			}

			$consumed_quantities = $temp_consumed_quantities;

			if ( 'true' !== $pricing['repeat'] ) {
				break;
			}
		}

		return $quantities;
	}

	/**
	 * Find given quantities numbers in the given cart items quantities.
	 *
	 * @param  array   $cart_items_quantities Array of cart items key with associated number of quantities.
	 * @param  integer $quantities            Number of quantities to find.
	 * @param  array   $consumed_quantities   Quantities already used and can not been take into account.
	 * @param  boolean $find_all_quantities   Should all of number of quantities to find or part of it is enough
	 *
	 * @return false|array
	 */
	protected function find_purchase_quantities( $cart_items_quantities, $quantities, $consumed_quantities, $find_all_quantities = true ) {
		if ( empty( $cart_items_quantities ) || empty( $quantities ) ) {
			return false;
		}

		$found_quantities = array();
		foreach ( $cart_items_quantities as $cart_item_key => $quantity ) {
			if ( 0 >= $quantities ) {
				break;
			}

			if ( ! empty( $consumed_quantities[ $cart_item_key ] ) ) {
				$quantity -= $consumed_quantities[ $cart_item_key ];
			}

			if ( 0 >= $quantity ) {
				continue;
			}

			$found_quantities[ $cart_item_key ] = $quantities >= $quantity ? $quantity : $quantities;
			$quantities -= $quantity;
		}

		/**
		 * If could not find quantities.
		 * Or should find all of quantities but could not find all of them.
		 */
		if ( empty( $found_quantities ) || ( $find_all_quantities && 0 < $quantities ) ) {
			return false;
		}

		return $found_quantities;
	}

	/**
	 * Get purchase xy discount receive items with associated quantities.
	 * Items ordered by price ascending or lowest prices first.
	 *
	 * @param  array $items
	 *
	 * @return array
	 */
	protected function get_receive_items_quantities( array $items ) {
		if ( empty( $items ) ) {
			return array();
		}

		$cart_items = $this->cart->sort_cart_items( $this->cart->filter_cart_items( $items, true, array(), true ) );
		if ( empty( $cart_items ) ) {
			return array();
		}

		$quantities = array();
		foreach ( $cart_items as $cart_item_key => $cart_item ) {
			$quantities[ $cart_item_key ] = $cart_item['quantity'];
		}
		return $quantities;
	}

	protected function calculate_bogo( $pricing, $consumed_quantities ) {
		if ( empty( $pricing ) ) {
			return false;
		}

		if ( empty( $pricing['purchase']['purchase'] ) || empty( $pricing['purchase']['receive'] ) ) {
			return false;
		}

		$quantity = $this->item['quantity'];
		if ( ! empty( $consumed_quantities[ $this->item_id ] ) ) {
			$quantity -= $consumed_quantities[ $this->item_id ];
		}

		if ( 0 >= $quantity ) {
			return false;
		}

		$buy = (float) $pricing['purchase']['purchase'];
		$get = (float) $pricing['purchase']['receive'];

		if ( 0 >= $buy || 0 >= $get ) {
			return false;
		}

		$group_size = $buy + $get;

		$bogo_groups = floor( $quantity / $group_size );
		$consumed = $bogo_groups * $group_size;

		if (
			1 < $bogo_groups &&
			( ! isset( $pricing['repeat'] ) || 'true' !== $pricing['repeat'] )
		) {
			$bogo_groups = 1;
			$consumed = $group_size;
		}

		return [
			'consumed' => $consumed,
			'get' => $bogo_groups * $get,
		];
	}

	public function get_purchase_pricings() {
		if ( ! apply_filters( 'wccs_cart_item_purchase_pricings', true, $this->item, $this ) ) {
			return [];
		}

		if ( empty( $this->pricings ) || empty( $this->pricings['purchase'] ) ) {
			return apply_filters( 'wccs_cart_item_pricing_purchase_pricings', array() );
		}

		$pricings = array();
		foreach ( $this->pricings['purchase'] as $pricing_id => $pricing ) {
			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			} elseif ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->product_id, $this->variation_id, ( ! empty( $this->item['variation'] ) ? $this->item['variation'] : array() ), $this->item ) ) {
				continue;
			}

			$pricings[ $pricing_id ] = array(
				'id' => $pricing_id,
				'name' => $pricing['name'],
				'description' => $pricing['description'],
				'mode' => $pricing['mode'],
				'apply_mode' => $pricing['apply_mode'],
				'order' => (int) $pricing['order'],
				'date_time' => $pricing['date_time'],
				'date_times_match_mode' => $pricing['date_times_match_mode'],
			);
		}

		return apply_filters( 'wccs_cart_item_pricing_purchase_pricings', $pricings );
	}

}
