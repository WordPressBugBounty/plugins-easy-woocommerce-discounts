<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Pricing {

	protected $pricings;

	protected $date_time_validator;

	protected $condition_validator;

	protected $cache;

	/**
	 * Memoized flag for whether any dynamic rules exist in the store.
	 *
	 * @var bool|null
	 */
	protected $has_dynamic_rules = null;

	/**
	 * Inverted index for candidate rule lookup.
	 *
	 * @var array|null
	 */
	protected $inverted_index = null;

	/**
	 * Memoized candidate rules per product.
	 *
	 * @var array
	 */
	protected $candidate_rules_cache = array();

	public $rules_filter;

	/**
	 * Constructor.
	 *
	 * @param array                                 $pricings
	 * @param WCCS_Pricing_Condition_Validator|null $condition_validator
	 * @param WCCS_Date_Time_Validator|null         $date_time_validator
	 * @param WCCS_Rules_Filter|null                $rules_filter
	 */
	public function __construct(
		array $pricings,
		$condition_validator = null,
		$date_time_validator = null,
		$rules_filter = null
	) {
		$wccs = WCCS();

		$this->pricings            = $pricings;
		$this->date_time_validator = null !== $date_time_validator ? $date_time_validator : $wccs->WCCS_Date_Time_Validator;
		$this->condition_validator = null !== $condition_validator ? $condition_validator : $wccs->WCCS_Pricing_Condition_Validator;
		$this->rules_filter        = null !== $rules_filter ? $rules_filter : new WCCS_Rules_Filter();
		$this->cache               = array(
			'simple'         => false,
			'bulk'           => false,
			'purchase'       => false,
			'exclude'        => false,
		);
	}

	public function get_all_pricing_rules() {
		return $this->pricings;
	}

	public function get_simple_pricings() {
		if ( false !== $this->cache['simple'] ) {
			return $this->cache['simple'];
		}

		$this->cache['simple'] = array();
		if ( empty( $this->pricings ) ) {
			return $this->cache['simple'] = apply_filters( 'wccs_pricing_simples', $this->cache['simple'], $this );
		}

		foreach ( $this->pricings as $pricing ) {
			if ( 'simple' !== $pricing->mode || empty( $pricing->items ) || ! isset( $pricing->discount ) || floatval( $pricing->discount ) < 0 ) {
				continue;
			} 
			
			// Validating rule usage limit.
			if ( ! empty( $pricing->usage_limit ) && ! WCCS_Usage_Validator::check_rule_usage_limit( $pricing ) ) {
				continue;
			} 
			
			// Validating date time.
			$pricing_date_time = isset( $pricing->date_time ) && is_array( $pricing->date_time ) ? $pricing->date_time : array();
			if ( ! $this->date_time_validator->is_valid_date_times( $pricing_date_time, ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) ) ) {
				continue;
			} 
			
			// Validating conditions.
			if ( ! $this->condition_validator->is_valid_conditions( $pricing, ( ! empty( $pricing->conditions_match_mode ) ? $pricing->conditions_match_mode : 'all' ) ) ) {
				continue;
			}

			$this->cache['simple'][ $pricing->id ] = array(
				'id'                       => absint( $pricing->id ),
				'name'                     => ! empty( $pricing->name ) ? sanitize_text_field( $pricing->name ) : '',
				'description'              => ! empty( $pricing->description ) ? sanitize_text_field( $pricing->description ) : '',
				'mode'                     => 'simple',
				'apply_mode'               => ! empty( $pricing->apply_mode ) ? $pricing->apply_mode : 'all',
				'order'                    => (int) $pricing->ordering,
				'discount'                 => floatval( $pricing->discount ),
				'discount_type'            => $pricing->discount_type,
				'items'                    => $pricing->items,
				'exclude_items'            => ! empty( $pricing->exclude_items ) ? $pricing->exclude_items : array(),
				'date_time'                => $pricing_date_time,
				'date_times_match_mode'    => ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one',
				'conditions'               => ! empty( $pricing->conditions ) && is_array( $pricing->conditions ) ? $pricing->conditions : array(),
				'message_type'             => ! empty( $pricing->message_type ) ? $pricing->message_type : 'text_message',
				'message_background_color' => ! empty( $pricing->message_background_color ) ? $pricing->message_background_color : '',
				'message_color'            => ! empty( $pricing->message_color ) ? $pricing->message_color : '',
				'receive_message'          => ! empty( $pricing->receive_message ) ? $pricing->receive_message : '',
			);
		}

		return $this->cache['simple'] = apply_filters( 'wccs_pricing_simples', $this->cache['simple'], $this );
	}

	public function get_bulk_pricings() {
		if ( false !== $this->cache['bulk'] ) {
			return $this->cache['bulk'];
		}

		$this->cache['bulk'] = array();
		if ( empty( $this->pricings ) ) {
			return $this->cache['bulk'] = apply_filters( 'wccs_pricing_bulks', $this->cache['bulk'], $this );
		}

		foreach ( $this->pricings as $pricing ) {
			if ( 'bulk' !== $pricing->mode || empty( $pricing->items ) || empty( $pricing->quantity_based_on ) || empty( $pricing->quantities ) ) {
				continue;
			} 
			
			// Validating rule usage limit.
			if ( ! empty( $pricing->usage_limit ) && ! WCCS_Usage_Validator::check_rule_usage_limit( $pricing ) ) {
				continue;
			} 
			
			// Validating date time.
			$pricing_date_time = isset( $pricing->date_time ) && is_array( $pricing->date_time ) ? $pricing->date_time : array();
			if ( ! $this->date_time_validator->is_valid_date_times( $pricing_date_time, ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) ) ) {
				continue;
			} 
			
			// Validating conditions.
			if ( ! $this->condition_validator->is_valid_conditions( $pricing, ( ! empty( $pricing->conditions_match_mode ) ? $pricing->conditions_match_mode : 'all' ) ) ) {
				continue;
			}

			// Validating quantities.
			$valid_quantities = array();
			foreach ( $pricing->quantities as $quantity ) {
				if ( empty( $quantity['min'] ) || intval( $quantity['min'] ) < 0 || empty( $quantity['discount_type'] ) || floatval( $quantity['discount'] ) < 0 ) {
					continue;
				} elseif ( ! empty( $quantity['max'] ) && ( intval( $quantity['max'] ) < 0 || intval( $quantity['max'] ) < intval( $quantity['min'] ) ) ) {
					continue;
				}

				$valid_quantities[] = $quantity;
			}
			if ( empty( $valid_quantities ) ) {
				continue;
			}

			$this->cache['bulk'][ $pricing->id ] = array(
				'id'                       => absint( $pricing->id ),
				'name'                     => ! empty( $pricing->name ) ? sanitize_text_field( $pricing->name ) : '',
				'description'              => ! empty( $pricing->description ) ? sanitize_text_field( $pricing->description ) : '',
				'mode'                     => 'bulk',
				'apply_mode'               => ! empty( $pricing->apply_mode ) ? $pricing->apply_mode : 'all',
				'order'                    => (int) $pricing->ordering,
				'quantities'               => $valid_quantities,
				'quantity_based_on'        => $pricing->quantity_based_on,
				'set_min_quantity'         => ! empty( $pricing->set_min_quantity ) ? $pricing->set_min_quantity : 'false',
				'items'                    => $pricing->items,
				'exclude_items'            => ! empty( $pricing->exclude_items ) ? $pricing->exclude_items : array(),
				'display_quantity'         => ! empty( $pricing->display_quantity ) ? $pricing->display_quantity : 'yes',
				'display_price'            => ! empty( $pricing->display_price ) ? $pricing->display_price : 'yes',
				'display_discount'         => ! empty( $pricing->display_discount ) ? $pricing->display_discount : 'no',
				'date_time'                => $pricing_date_time,
				'date_times_match_mode'    => ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one',
				'conditions'               => ! empty( $pricing->conditions ) && is_array( $pricing->conditions ) ? $pricing->conditions : array(),
				'message_type'             => ! empty( $pricing->message_type ) ? $pricing->message_type : 'text_message',
				'message_background_color' => ! empty( $pricing->message_background_color ) ? $pricing->message_background_color : '',
				'message_color'            => ! empty( $pricing->message_color ) ? $pricing->message_color : '',
				'receive_message'          => ! empty( $pricing->receive_message ) ? $pricing->receive_message : '',
			);
		}

		return $this->cache['bulk'] = apply_filters( 'wccs_pricing_bulks', $this->cache['bulk'], $this );
	}

	/**
	 * Get purchase pricing rules.
	 *
	 * @param  string $type possible values are 'all', 'auto', 'exclude_auto'
	 * @return array
	 */
	public function get_purchase_pricings( $type = 'all' ) {
		if ( false !== $this->cache['purchase'] ) {
			if ( ! empty( $type ) ) {
				return isset( $this->cache['purchase'][ $type ] ) ? $this->cache['purchase'][ $type ] : array();
			}
			return $this->cache['purchase']['all'];
		}

		$this->cache['purchase'] = array(
			'all'          => array(),
			'auto'         => array(),
			'exclude_auto' => array(),
		);
		if ( empty( $this->pricings ) ) {
			$this->cache['purchase'] = apply_filters( 'wccs_pricing_purchases', $this->cache['purchase'], $this );
			if ( ! empty( $type ) ) {
				return isset( $this->cache['purchase'][ $type ] ) ? $this->cache['purchase'][ $type ] : array();
			}
			return $this->cache['purchase']['all'];
		}

		foreach ( $this->pricings as $pricing ) {
			if ( 'purchase_x_receive_y' !== $pricing->mode && 'purchase_x_receive_y_same' !== $pricing->mode && 'bogo' !== $pricing->mode ) {
				continue;
			} elseif ( empty( $pricing->items ) || ( 'bogo' !== $pricing->mode && empty( $pricing->purchased_items ) ) ) {
				continue;
			} elseif ( empty( $pricing->purchase['purchase'] ) || intval( $pricing->purchase['purchase'] ) <= 0 ) {
				continue;
			} elseif ( empty( $pricing->purchase['receive'] ) || intval( $pricing->purchase['receive'] ) <= 0 ) {
				continue;
			} elseif ( empty( $pricing->purchase['discount'] ) || floatval( $pricing->purchase['discount'] ) < 0 ) {
				continue;
			} // Validating rule usage limit.
			elseif ( ! empty( $pricing->usage_limit ) && ! WCCS_Usage_Validator::check_rule_usage_limit( $pricing ) ) {
				continue;
			} // Validating date time.
			elseif ( ! $this->date_time_validator->is_valid_date_times( $pricing->date_time, ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) ) ) {
				continue;
			} // Validating conditions.
			elseif ( ! $this->condition_validator->is_valid_conditions( $pricing, ( ! empty( $pricing->conditions_match_mode ) ? $pricing->conditions_match_mode : 'all' ) ) ) {
				continue;
			}

			$rule = array(
				'id'                        => absint( $pricing->id ),
				'name'                      => ! empty( $pricing->name ) ? sanitize_text_field( $pricing->name ) : '',
				'description'               => ! empty( $pricing->description ) ? sanitize_text_field( $pricing->description ) : '',
				'mode'                      => 'purchase',
				'mode_type'                 => $pricing->mode,
				'apply_mode'                => ! empty( $pricing->apply_mode ) ? $pricing->apply_mode : 'all',
				'quantity_based_on'         => ! empty( $pricing->quantity_based_on ) ? $pricing->quantity_based_on : 'all_products',
				'order'                     => (int) $pricing->ordering,
				'purchased_items'           => ! empty( $pricing->purchased_items ) ? $pricing->purchased_items : [],
				'purchase'                  => $pricing->purchase,
				'message_type'              => ! empty( $pricing->message_type ) ? $pricing->message_type : 'text_message',
				'message_background_color'  => ! empty( $pricing->message_background_color ) ? $pricing->message_background_color : '',
				'message_color'             => ! empty( $pricing->message_color ) ? $pricing->message_color : '',
				'receive_message'           => $pricing->receive_message,
				'purchased_message'         => ! empty( $pricing->purchased_message ) ? $pricing->purchased_message : '',
				'repeat'                    => $pricing->repeat,
				'items'                     => $pricing->items,
				'exclude_items'             => ! empty( $pricing->exclude_items ) ? $pricing->exclude_items : array(),
				'date_time'                 => $pricing->date_time,
				'date_times_match_mode'     => ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one',
				'conditions'                => $pricing->conditions,
			);

			$this->cache['purchase']['all'][ $pricing->id ] = $rule;

			if ( $auto_add_product = $this->is_auto_add_rule( $rule ) ) {
				if ( 'bogo' !== $rule['mode_type'] ) {
					$rule['auto_add_product'] = $auto_add_product;
				}
				$this->cache['purchase']['auto'][ $pricing->id ] = $rule;
			} else {
				$this->cache['purchase']['exclude_auto'][ $pricing->id ] = $rule;
			}
		}

		$this->cache['purchase'] = apply_filters( 'wccs_pricing_purchases', $this->cache['purchase'], $this );

		if ( ! empty( $type ) ) {
			return isset( $this->cache['purchase'][ $type ] ) ? $this->cache['purchase'][ $type ] : array();
		}
		return $this->cache['purchase']['all'];
	}

	public function is_auto_add_rule( $pricing ) {
		if ( empty( $pricing ) ) {
			return false;
		}

		// Discounted product price should be zero or free.
		if ( 'percentage_discount' !== $pricing['purchase']['discount_type'] && 'fixed_price' !== $pricing['purchase']['discount_type'] ) {
			return false;
		} elseif ( 'percentage_discount' === $pricing['purchase']['discount_type'] ) {
			// Discounted product should get 100% discount to become free.
			if ( 100 != $pricing['purchase']['discount'] ) {
				return false;
			}
		} elseif ( 'fixed_price' === $pricing['purchase']['discount_type'] ) {
			// Discounted value should be 0 to become free.
			if ( 0 != $pricing['purchase']['discount'] ) {
				return false;
			}
		}

		if ( isset( $pricing['mode_type'] ) && 'bogo' === $pricing['mode_type'] ) {
			return true;
		}

		$product = 0;

		if ( empty( $pricing['items'] ) || 1 < count( $pricing['items'] ) ) {
			return false;
		} elseif ( isset( $pricing['items'][0][0] ) && 1 < count( $pricing['items'][0] ) ) {
			return false;
		}

		// Find item
		$item = null;
		if ( isset( $pricing['items'][0][0] ) ) {
			$item = $pricing['items'][0][0];
		} elseif ( isset( $pricing['items'][0]['item'] ) ) {
			$item = $pricing['items'][0];
		}
		if ( empty( $item ) || empty( $item['item'] ) ) {
			return false;
		}

		// Pricing should exactly discount one product or one variation.
		if ( 'products_in_list' !== $item['item'] && 'product_variations_in_list' !== $item['item'] ) {
			return false;
		} elseif ( 'products_in_list' === $item['item'] ) {
			if ( empty( $item['products'] ) || 1 < count( $item['products'] ) ) {
				return false;
			}

			$product = $item['products'][0];
			$product = wc_get_product( $product );
			if ( ! $product || 'simple' !== $product->get_type() ) {
				return false;
			}
			$product = $product->get_id();
		} elseif ( 'product_variations_in_list' === $item['item'] ) {
			if ( empty( $item['variations'] ) || 1 < count( $item['variations'] ) ) {
				return false;
			}

			$product = $item['variations'][0];
		}

		return 0 < $product ? $product : false;
	}

	public function get_exclude_rules() {
		return array();
	}

	/**
	 * Is given product in excluded rules.
	 *
	 * @since  1.1.0
	 *
	 * @param  int|WC_Product $product
	 * @param  int|WC_Product $variation
	 * @param  array          $variations
	 *
	 * @return boolean
	 */
	public function is_in_exclude_rules( $product, $variation = 0, array $variations = array() ) {
		return false;
	}

	public function get_pricings( array $pricing_types = array( 'simple', 'bulk', 'purchase' ) ) {
		$pricings = array();

		if ( in_array( 'simple', $pricing_types ) ) {
			$pricings['simple'] = $this->get_simple_pricings();
		}

		if ( in_array( 'bulk', $pricing_types ) ) {
			$pricings['bulk'] = $this->get_bulk_pricings();
		}

		if ( in_array( 'purchase', $pricing_types ) ) {
			$pricings['purchase'] = $this->get_purchase_pricings();
		}

		return apply_filters( 'wccs_pricing_pricings', $pricings, $pricing_types );
	}

	public function get_product_simple_rules( $product_id, $variation_id = 0, $check_date_time = true ) {
		$rules = array();
		if ( empty( $this->pricings ) ) {
			return $rules;
		}

		$candidates = $this->get_candidate_rules_for_product( 'simple', $product_id, $variation_id );

		foreach ( $candidates as $pricing ) {
			$mode                  = is_object( $pricing ) ? $pricing->mode : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items                 = is_object( $pricing ) ? $pricing->items : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$discount              = is_object( $pricing ) ? ( isset( $pricing->discount ) ? $pricing->discount : null ) : ( is_array( $pricing ) && isset( $pricing['discount'] ) ? $pricing['discount'] : null );
			$exclude_items         = is_object( $pricing ) ? ( isset( $pricing->exclude_items ) ? $pricing->exclude_items : array() ) : ( is_array( $pricing ) && isset( $pricing['exclude_items'] ) ? $pricing['exclude_items'] : array() );
			$date_time             = is_object( $pricing ) ? ( isset( $pricing->date_time ) ? $pricing->date_time : '' ) : ( is_array( $pricing ) && isset( $pricing['date_time'] ) ? $pricing['date_time'] : '' );
			$date_times_match_mode = is_object( $pricing ) ? ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) : ( ! empty( $pricing['date_times_match_mode'] ) ? $pricing['date_times_match_mode'] : 'one' );

			if ( 'simple' !== $mode || empty( $items ) || null === $discount || floatval( $discount ) < 0 ) {
				continue;
			}

			if ( $check_date_time && ! empty( $date_time ) && ! $this->date_time_validator->is_valid_date_times( $date_time, $date_times_match_mode ) ) {
				continue;
			}

			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $items, $product_id, $variation_id ) ) {
				continue;
			}

			if ( ! empty( $exclude_items ) && WCCS()->WCCS_Product_Validator->is_valid_product( $exclude_items, $product_id, $variation_id ) ) {
				continue;
			}

			$rules[] = $pricing;
		}

		return $rules;
	}

	public function get_product_bulk_rules( $product_id, $variation_id = 0, $check_date_time = true ) {
		$rules = array();
		if ( empty( $this->pricings ) ) {
			return $rules;
		}

		$candidates = $this->get_candidate_rules_for_product( 'bulk', $product_id, $variation_id );

		foreach ( $candidates as $pricing ) {
			$mode                  = is_object( $pricing ) ? $pricing->mode : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items                 = is_object( $pricing ) ? $pricing->items : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$quantities            = is_object( $pricing ) ? ( isset( $pricing->quantities ) ? $pricing->quantities : array() ) : ( is_array( $pricing ) && isset( $pricing['quantities'] ) ? $pricing['quantities'] : array() );
			$exclude_items         = is_object( $pricing ) ? ( isset( $pricing->exclude_items ) ? $pricing->exclude_items : array() ) : ( is_array( $pricing ) && isset( $pricing['exclude_items'] ) ? $pricing['exclude_items'] : array() );
			$date_time             = is_object( $pricing ) ? ( isset( $pricing->date_time ) ? $pricing->date_time : '' ) : ( is_array( $pricing ) && isset( $pricing['date_time'] ) ? $pricing['date_time'] : '' );
			$date_times_match_mode = is_object( $pricing ) ? ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) : ( ! empty( $pricing['date_times_match_mode'] ) ? $pricing['date_times_match_mode'] : 'one' );

			if ( 'bulk' !== $mode || empty( $items ) || empty( $quantities ) ) {
				continue;
			}

			if ( $check_date_time && ! empty( $date_time ) && ! $this->date_time_validator->is_valid_date_times( $date_time, $date_times_match_mode ) ) {
				continue;
			}

			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $items, $product_id, $variation_id ) ) {
				continue;
			}

			if ( ! empty( $exclude_items ) && WCCS()->WCCS_Product_Validator->is_valid_product( $exclude_items, $product_id, $variation_id ) ) {
				continue;
			}

			$rules[] = $pricing;
		}

		return $rules;
	}

	public function get_product_purchase_rules( $product_id, $variation_id = 0, $check_date_time = true ) {
		$rules = array();
		if ( empty( $this->pricings ) ) {
			return $rules;
		}

		$candidates = $this->get_candidate_rules_for_product( 'purchase', $product_id, $variation_id );

		foreach ( $candidates as $pricing ) {
			$mode                  = is_object( $pricing ) ? $pricing->mode : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items                 = is_object( $pricing ) ? $pricing->items : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$purchased_items       = is_object( $pricing ) ? ( isset( $pricing->purchased_items ) ? $pricing->purchased_items : array() ) : ( is_array( $pricing ) && isset( $pricing['purchased_items'] ) ? $pricing['purchased_items'] : array() );
			$purchase              = is_object( $pricing ) ? ( isset( $pricing->purchase ) ? $pricing->purchase : array() ) : ( is_array( $pricing ) && isset( $pricing['purchase'] ) ? $pricing['purchase'] : array() );
			$exclude_items         = is_object( $pricing ) ? ( isset( $pricing->exclude_items ) ? $pricing->exclude_items : array() ) : ( is_array( $pricing ) && isset( $pricing['exclude_items'] ) ? $pricing['exclude_items'] : array() );
			$date_time             = is_object( $pricing ) ? ( isset( $pricing->date_time ) ? $pricing->date_time : '' ) : ( is_array( $pricing ) && isset( $pricing['date_time'] ) ? $pricing['date_time'] : '' );
			$date_times_match_mode = is_object( $pricing ) ? ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) : ( ! empty( $pricing['date_times_match_mode'] ) ? $pricing['date_times_match_mode'] : 'one' );

			if ( 'purchase_x_receive_y' !== $mode && 'purchase_x_receive_y_same' !== $mode && 'bogo' !== $mode ) {
				continue;
			} elseif ( empty( $items ) || ( 'bogo' !== $mode && empty( $purchased_items ) ) ) {
				continue;
			} elseif ( empty( $purchase['purchase'] ) || intval( $purchase['purchase'] ) <= 0 ) {
				continue;
			} elseif ( empty( $purchase['receive'] ) || intval( $purchase['receive'] ) <= 0 ) {
				continue;
			} elseif ( empty( $purchase['discount'] ) || floatval( $purchase['discount'] ) < 0 ) {
				continue;
			}

			if ( $check_date_time && ! empty( $date_time ) && ! $this->date_time_validator->is_valid_date_times( $date_time, $date_times_match_mode ) ) {
				continue;
			}

			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $items, $product_id, $variation_id ) ) {
				continue;
			}

			if ( ! empty( $exclude_items ) && WCCS()->WCCS_Product_Validator->is_valid_product( $exclude_items, $product_id, $variation_id ) ) {
				continue;
			}

			$rules[] = $pricing;
		}

		return $rules;
	}

	/**
	 * Build inverted index across pricing rules to allow O(1) candidate rule retrieval.
	 *
	 * @return void
	 */
	protected function build_inverted_index() {
		if ( null !== $this->inverted_index ) {
			return;
		}

		$this->candidate_rules_cache = array();

		$this->inverted_index = array(
			'simple'   => array( 'universal' => array(), 'by_product' => array(), 'by_variation' => array(), 'by_category' => array(), 'by_tag' => array() ),
			'bulk'     => array( 'universal' => array(), 'by_product' => array(), 'by_variation' => array(), 'by_category' => array(), 'by_tag' => array() ),
			'purchase' => array( 'universal' => array(), 'by_product' => array(), 'by_variation' => array(), 'by_category' => array(), 'by_tag' => array() ),
		);

		if ( empty( $this->pricings ) ) {
			return;
		}

		// Support both flat rules list and pre-grouped category arrays
		$rules_to_index = array();
		if ( isset( $this->pricings['simple'] ) || isset( $this->pricings['bulk'] ) ) {
			foreach ( $this->pricings as $group ) {
				if ( is_array( $group ) ) {
					foreach ( $group as $r ) {
						$rules_to_index[] = $r;
					}
				}
			}
		} else {
			$rules_to_index = $this->pricings;
		}

		foreach ( $rules_to_index as $pricing ) {
			if ( is_object( $pricing ) ) {
				$mode    = isset( $pricing->mode ) ? $pricing->mode : '';
				$rule_id = isset( $pricing->id ) ? (int) $pricing->id : 0;
				$items   = ! empty( $pricing->items ) ? $pricing->items : array();
			} elseif ( is_array( $pricing ) ) {
				$mode    = isset( $pricing['mode'] ) ? $pricing['mode'] : '';
				$rule_id = isset( $pricing['id'] ) ? (int) $pricing['id'] : 0;
				$items   = ! empty( $pricing['items'] ) ? $pricing['items'] : array();
			} else {
				continue;
			}

			if ( 'purchase_x_receive_y' === $mode || 'purchase_x_receive_y_same' === $mode || 'bogo' === $mode ) {
				$mode_key = 'purchase';
			} elseif ( isset( $this->inverted_index[ $mode ] ) ) {
				$mode_key = $mode;
			} else {
				continue;
			}

			if ( $rule_id <= 0 ) {
				continue;
			}

			// Flatten nested items (e.g. OR groups)
			$flat_items = array();
			foreach ( $items as $it ) {
				if ( is_array( $it ) && ! isset( $it['item'] ) && ! isset( $it['condition'] ) && ! isset( $it['type'] ) ) {
					foreach ( $it as $sub_it ) {
						if ( ! empty( $sub_it ) ) {
							$flat_items[] = $sub_it;
						}
					}
				} elseif ( ! empty( $it ) ) {
					$flat_items[] = $it;
				}
			}

			if ( empty( $flat_items ) ) {
				$this->inverted_index[ $mode_key ]['universal'][ $rule_id ] = $pricing;
				continue;
			}

			$is_universal = false;
			$has_specific = false;

			foreach ( $flat_items as $item ) {
				if ( is_object( $item ) ) {
					$item = (array) $item;
				}

				$type = isset( $item['item'] ) ? $item['item'] : ( isset( $item['condition'] ) ? $item['condition'] : ( isset( $item['type'] ) ? $item['type'] : '' ) );

				switch ( $type ) {
					case 'products_in_list':
					case 'product_in_list':
					case 'subtotal_of_products_include_tax':
					case 'subtotal_of_products_exclude_tax':
						if ( ! empty( $item['products'] ) && is_array( $item['products'] ) ) {
							$has_specific = true;
							foreach ( $item['products'] as $p ) {
								$pid = (int) WCCS_Helpers::maybe_get_exact_item_id( $p );
								if ( $pid > 0 ) {
									$this->inverted_index[ $mode_key ]['by_product'][ $pid ][ $rule_id ] = $pricing;
								}
							}
						} else {
							$is_universal = true;
							break 2;
						}
						break;

					case 'product_variations_in_list':
					case 'variations_in_list':
					case 'subtotal_of_variations_include_tax':
					case 'subtotal_of_variations_exclude_tax':
						if ( ! empty( $item['variations'] ) && is_array( $item['variations'] ) ) {
							$has_specific = true;
							foreach ( $item['variations'] as $v ) {
								$vid = (int) WCCS_Helpers::maybe_get_exact_item_id( $v );
								if ( $vid > 0 ) {
									$this->inverted_index[ $mode_key ]['by_variation'][ $vid ][ $rule_id ] = $pricing;
								}
							}
						} else {
							$is_universal = true;
							break 2;
						}
						break;

					case 'categories_in_list':
					case 'product_categories':
					case 'subtotal_of_categories_include_tax':
					case 'subtotal_of_categories_exclude_tax':
						if ( ! empty( $item['categories'] ) && is_array( $item['categories'] ) ) {
							$has_specific = true;
							foreach ( $item['categories'] as $c ) {
								$cid = (int) WCCS_Helpers::maybe_get_exact_category_id( $c );
								if ( $cid > 0 ) {
									$this->inverted_index[ $mode_key ]['by_category'][ $cid ][ $rule_id ] = $pricing;
								}
							}
						} else {
							$is_universal = true;
							break 2;
						}
						break;

					case 'products_have_tags':
					case 'product_tags':
					case 'subtotal_of_tags_include_tax':
					case 'subtotal_of_tags_exclude_tax':
						if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
							$has_specific = true;
							foreach ( $item['tags'] as $t ) {
								$tid = (int) WCCS_Helpers::maybe_get_exact_tag_id( $t );
								if ( $tid > 0 ) {
									$this->inverted_index[ $mode_key ]['by_tag'][ $tid ][ $rule_id ] = $pricing;
								}
							}
						} else {
							$is_universal = true;
							break 2;
						}
						break;

					default:
						// Fallback to universal if the condition is not in specific indexable ones
						// (e.g. all_products, negative conditions, product attributes, custom taxonomies,
						// on-sale, regular, stock, prices, featured, top seller, customer history, meta fields,
						// or any new condition types added in future versions).
						$is_universal = true;
						break 2;
				}
			}

			if ( $is_universal || ! $has_specific ) {
				$this->inverted_index[ $mode_key ]['universal'][ $rule_id ] = $pricing;
			}
		}
	}

	/**
	 * Retrieve candidate rules that can apply to a given product/variation.
	 * O(1) hash map candidate lookup avoids scanning all rules in the store.
	 *
	 * @param string $mode
	 * @param int    $product_id
	 * @param int    $variation_id
	 * @return array
	 */
	public function get_candidate_rules_for_product( $mode, $product_id = 0, $variation_id = 0 ) {
		if ( is_numeric( $mode ) ) {
			$variation_id = (int) $product_id;
			$product_id   = (int) $mode;
			$mode         = 'all';
		}

		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;
		$cache_key    = $mode . '_' . $product_id . '_' . $variation_id;

		if ( isset( $this->candidate_rules_cache[ $cache_key ] ) ) {
			return $this->candidate_rules_cache[ $cache_key ];
		}

		$this->build_inverted_index();

		if ( 'all' === $mode ) {
			$candidates = array();
			foreach ( array( 'simple', 'bulk', 'tiered', 'purchase', 'products_group' ) as $m ) {
				$m_candidates = $this->get_candidate_rules_for_product( $m, $product_id, $variation_id );
				if ( ! empty( $m_candidates ) ) {
					foreach ( $m_candidates as $rid => $rule ) {
						$candidates[ $rid ] = $rule;
					}
				}
			}
			return $this->candidate_rules_cache[ $cache_key ] = $candidates;
		}

		if ( ! isset( $this->inverted_index[ $mode ] ) ) {
			return $this->candidate_rules_cache[ $cache_key ] = array();
		}

		$index      = $this->inverted_index[ $mode ];
		$candidates = $index['universal'];

		// Direct product matching
		if ( $product_id > 0 && isset( $index['by_product'][ $product_id ] ) ) {
			foreach ( $index['by_product'][ $product_id ] as $rid => $rule ) {
				$candidates[ $rid ] = $rule;
			}
		}

		// Direct variation matching
		if ( $variation_id > 0 && isset( $index['by_variation'][ $variation_id ] ) ) {
			foreach ( $index['by_variation'][ $variation_id ] as $rid => $rule ) {
				$candidates[ $rid ] = $rule;
			}
		}

		// Category matching (only query term IDs if rules targeting categories exist)
		if ( ! empty( $index['by_category'] ) && $product_id > 0 ) {
			$category_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
			if ( ! empty( $category_ids ) && is_array( $category_ids ) ) {
				foreach ( $category_ids as $cid ) {
					$cid = (int) $cid;
					if ( isset( $index['by_category'][ $cid ] ) ) {
						foreach ( $index['by_category'][ $cid ] as $rid => $rule ) {
							$candidates[ $rid ] = $rule;
						}
					}
				}
			}
		}

		// Tag matching (only query term IDs if rules targeting tags exist)
		if ( ! empty( $index['by_tag'] ) && $product_id > 0 ) {
			$tag_ids = wc_get_product_term_ids( $product_id, 'product_tag' );
			if ( ! empty( $tag_ids ) && is_array( $tag_ids ) ) {
				foreach ( $tag_ids as $tid ) {
					$tid = (int) $tid;
					if ( isset( $index['by_tag'][ $tid ] ) ) {
						foreach ( $index['by_tag'][ $tid ] as $rid => $rule ) {
							$candidates[ $rid ] = $rule;
						}
					}
				}
			}
		}

		// Preserve original rule ordering
		if ( count( $candidates ) > 1 ) {
			uasort( $candidates, function ( $a, $b ) {
				$order_a = is_object( $a ) ? ( isset( $a->ordering ) ? (int) $a->ordering : 0 ) : ( is_array( $a ) && isset( $a['order'] ) ? (int) $a['order'] : 0 );
				$order_b = is_object( $b ) ? ( isset( $b->ordering ) ? (int) $b->ordering : 0 ) : ( is_array( $b ) && isset( $b['order'] ) ? (int) $b['order'] : 0 );
				if ( $order_a === $order_b ) {
					return 0;
				}
				return ( $order_a < $order_b ) ? -1 : 1;
			} );
		}

		return $this->candidate_rules_cache[ $cache_key ] = $candidates;
	}

	/**
	 * Internal helper to retrieve flat list of pricing rules.
	 *
	 * @return array
	 */
	protected function get_flat_pricings_list() {
		if ( empty( $this->pricings ) ) {
			return array();
		}
		if ( isset( $this->pricings['simple'] ) || isset( $this->pricings['bulk'] ) || isset( $this->pricings['tiered'] ) ) {
			$flat = array();
			foreach ( $this->pricings as $group ) {
				if ( is_array( $group ) ) {
					foreach ( $group as $r ) {
						$flat[] = $r;
					}
				}
			}
			return $flat;
		}
		return $this->pricings;
	}

	/**
	 * Check whether the store has any active simple pricing rules configured.
	 * Fast O(1) in-memory check with request memoization.
	 * Bypasses heavy usage limit SQL queries, date checks, and cart condition traversals.
	 *
	 * @return bool
	 */
	public function has_simple_rules() {
		if ( isset( $this->cache['has_simple'] ) && null !== $this->cache['has_simple'] ) {
			return $this->cache['has_simple'];
		}

		$rules = $this->get_flat_pricings_list();
		if ( empty( $rules ) ) {
			return $this->cache['has_simple'] = false;
		}

		foreach ( $rules as $pricing ) {
			$mode     = is_object( $pricing ) ? ( isset( $pricing->mode ) ? $pricing->mode : '' ) : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items    = is_object( $pricing ) ? ( isset( $pricing->items ) ? $pricing->items : array() ) : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$discount = is_object( $pricing ) ? ( isset( $pricing->discount ) ? $pricing->discount : null ) : ( is_array( $pricing ) && isset( $pricing['discount'] ) ? $pricing['discount'] : null );

			if ( 'simple' === $mode && ! empty( $items ) && null !== $discount && floatval( $discount ) >= 0 ) {
				return $this->cache['has_simple'] = true;
			}
		}

		return $this->cache['has_simple'] = false;
	}

	/**
	 * Check if there are any bulk rules configured in the store.
	 * Fast O(1) in-memory check with request memoization.
	 *
	 * @return bool
	 */
	public function has_bulk_rules() {
		if ( isset( $this->cache['has_bulk'] ) && null !== $this->cache['has_bulk'] ) {
			return $this->cache['has_bulk'];
		}

		$rules = $this->get_flat_pricings_list();
		if ( empty( $rules ) ) {
			return $this->cache['has_bulk'] = false;
		}

		foreach ( $rules as $pricing ) {
			$mode              = is_object( $pricing ) ? ( isset( $pricing->mode ) ? $pricing->mode : '' ) : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items             = is_object( $pricing ) ? ( isset( $pricing->items ) ? $pricing->items : array() ) : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$quantity_based_on = is_object( $pricing ) ? ( isset( $pricing->quantity_based_on ) ? $pricing->quantity_based_on : '' ) : ( is_array( $pricing ) && isset( $pricing['quantity_based_on'] ) ? $pricing['quantity_based_on'] : '' );
			$quantities        = is_object( $pricing ) ? ( isset( $pricing->quantities ) ? $pricing->quantities : array() ) : ( is_array( $pricing ) && isset( $pricing['quantities'] ) ? $pricing['quantities'] : array() );

			if ( 'bulk' === $mode && ! empty( $items ) && ! empty( $quantity_based_on ) && ! empty( $quantities ) ) {
				return $this->cache['has_bulk'] = true;
			}
		}

		return $this->cache['has_bulk'] = false;
	}

	/**
	 * Check if ANY pricing rule is currently active and applicable.
	 * Fast short-circuiting method that exits on the very first valid rule.
	 * Avoids calculating the entire ruleset for simple existence checks.
	 *
	 * @return bool
	 */
	public function has_any_active_pricing_rule() {
		if ( isset( $this->cache['has_any_active'] ) && null !== $this->cache['has_any_active'] ) {
			return $this->cache['has_any_active'];
		}

		$rules = $this->get_flat_pricings_list();
		if ( empty( $rules ) ) {
			return $this->cache['has_any_active'] = false;
		}

		// Quick circuit breaker: check if any rules exist
		if ( ! $this->has_simple_rules() && ! $this->has_bulk_rules() ) {
			$has_other = false;
			foreach ( $rules as $pricing ) {
				$mode = is_object( $pricing ) ? ( isset( $pricing->mode ) ? $pricing->mode : '' ) : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
				if ( in_array( $mode, array( 'purchase_x_receive_y', 'purchase_x_receive_y_same', 'bogo' ), true ) ) {
					$has_other = true;
					break;
				}
			}
			if ( ! $has_other ) {
				return $this->cache['has_any_active'] = false;
			}
		}

		// Iterate through rules with lazy early exit on FIRST match
		foreach ( $rules as $pricing ) {
			$mode                  = is_object( $pricing ) ? ( isset( $pricing->mode ) ? $pricing->mode : '' ) : ( is_array( $pricing ) && isset( $pricing['mode'] ) ? $pricing['mode'] : '' );
			$items                 = is_object( $pricing ) ? ( isset( $pricing->items ) ? $pricing->items : array() ) : ( is_array( $pricing ) && isset( $pricing['items'] ) ? $pricing['items'] : array() );
			$usage_limit           = is_object( $pricing ) ? ( isset( $pricing->usage_limit ) ? $pricing->usage_limit : 0 ) : ( is_array( $pricing ) && isset( $pricing['usage_limit'] ) ? $pricing['usage_limit'] : 0 );
			$date_time             = is_object( $pricing ) ? ( isset( $pricing->date_time ) ? $pricing->date_time : '' ) : ( is_array( $pricing ) && isset( $pricing['date_time'] ) ? $pricing['date_time'] : '' );
			$date_times_match_mode = is_object( $pricing ) ? ( ! empty( $pricing->date_times_match_mode ) ? $pricing->date_times_match_mode : 'one' ) : ( ! empty( $pricing['date_times_match_mode'] ) ? $pricing['date_times_match_mode'] : 'one' );
			$conditions_match_mode = is_object( $pricing ) ? ( ! empty( $pricing->conditions_match_mode ) ? $pricing->conditions_match_mode : 'all' ) : ( ! empty( $pricing['conditions_match_mode'] ) ? $pricing['conditions_match_mode'] : 'all' );

			if ( empty( $mode ) || empty( $items ) ) {
				continue;
			}

			// Validate usage limit
			if ( ! empty( $usage_limit ) && ! WCCS_Usage_Validator::check_rule_usage_limit( $pricing ) ) {
				continue;
			}

			// Validate date time
			if ( ! empty( $date_time ) && ! $this->date_time_validator->is_valid_date_times( $date_time, $date_times_match_mode ) ) {
				continue;
			}

			// Validate conditions
			$conditions = is_object( $pricing ) ? ( isset( $pricing->conditions ) ? $pricing->conditions : array() ) : ( is_array( $pricing ) && isset( $pricing['conditions'] ) ? $pricing['conditions'] : array() );
			if ( ! empty( $conditions ) && $this->condition_validator && ! $this->condition_validator->is_valid_conditions( $pricing, $conditions_match_mode ) ) {
				continue;
			}

			// First match found! Early exit!
			return $this->cache['has_any_active'] = true;
		}

		return $this->cache['has_any_active'] = false;
	}

	/**
	 * Check if any pricing rules in the store have dynamic conditions.
	 *
	 * When false, all rules in the store are static (or role/time-based),
	 * meaning discounted products can be safely and persistently cached across runtimes.
	 *
	 * @return bool
	 */
	public function has_dynamic_rules() {
		if ( null !== $this->has_dynamic_rules ) {
			return $this->has_dynamic_rules;
		}

		if ( empty( $this->pricings ) ) {
			return $this->has_dynamic_rules = false;
		}

		// can_cache_rules returns true if rules only have static/role/time conditions.
		// allow_role_based = true because roles are segmented into separate cache keys.
		$this->has_dynamic_rules = ! WCCS_Rules_Helpers::can_cache_rules( $this->pricings, true );

		return $this->has_dynamic_rules;
	}

	/**
	 * Reset cached pricings.
	 *
	 * @since  2.8.0
	 *
	 * @return void
	 */
	public function reset_cache() {
		$this->has_dynamic_rules      = null;
		$this->inverted_index         = null;
		$this->candidate_rules_cache  = array();
		$this->cache = array(
			'simple'         => false,
			'bulk'           => false,
			'purchase'       => false,
			'exclude'        => false,
			'has_simple'     => null,
			'has_bulk'       => null,
			'has_any_active' => null,
		);
	}

}
