<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Product_Onsale_Cache extends WCCS_Abstract_Cache {

	const TYPE               = 'onsale';
	const PRODUCT_GEN_PREFIX = 'asnp_wccs_onsale_pgen_';

	/**
	 * Map of supported pricing modes to their rule collection getters on WCCS_Pricing.
	 *
	 * @var array<string, string>
	 */
	const MODE_METHODS = array(
		'simple'   => 'get_simple_pricings',
		'bulk'     => 'get_bulk_pricings',
		'purchase' => 'get_purchase_pricings',
	);

	/**
	 * Memoized configured sale badge types for current request.
	 *
	 * @var array|null
	 */
	protected $configured_types = null;

	/**
	 * Memoized candidate rules per product target for current request.
	 *
	 * @var array<string, array>
	 */
	protected $product_rules_cache = array();

	/**
	 * Pre-scanned inverted index of simple pricing rules targeting child variations.
	 * Memoized per request for O(1) variable product on-sale checks.
	 *
	 * @var array|null
	 */
	protected $simple_variation_rules_index = null;

	/**
	 * Constructor.
	 *
	 * @param WCCS_Pricing|null $pricing
	 */
	public function __construct( $pricing = null ) {
		$this->pricing = null === $pricing ? WCCS()->pricing : $pricing;
		parent::__construct( 'wccs_product_onsale_', 'wccs_product_onsale', self::TYPE, self::PRODUCT_GEN_PREFIX );
	}

	/**
	 * Get configured sale badge pricing types from plugin settings.
	 * Memoized per request for minimal CPU overhead.
	 *
	 * @return array
	 */
	public function get_configured_sale_badge_types() {
		if ( null !== $this->configured_types ) {
			return $this->configured_types;
		}

		$sale_badge = WCCS()->settings->get_setting( 'sale_badge', array( 'simple' => '1' ) );
		if ( ! is_array( $sale_badge ) || empty( $sale_badge ) ) {
			return $this->configured_types = array();
		}

		return $this->configured_types = array_filter( $sale_badge );
	}

	/**
	 * Normalize pricing types into a standard associative array [ 'type' => 1 ].
	 *
	 * @param array|string|null $pricing_types
	 * @return array
	 */
	public function normalize_pricing_types( $pricing_types = null ) {
		if ( null === $pricing_types ) {
			return $this->get_configured_sale_badge_types();
		}

		if ( empty( $pricing_types ) ) {
			return array();
		}

		if ( is_string( $pricing_types ) ) {
			return array( $pricing_types => 1 );
		}

		if ( ! is_array( $pricing_types ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $pricing_types as $key => $value ) {
			if ( is_int( $key ) && is_string( $value ) ) {
				$normalized[ $value ] = 1;
			} elseif ( ! empty( $value ) ) {
				$normalized[ $key ] = 1;
			}
		}

		return $normalized;
	}

	/**
	 * Is on-sale badge caching enabled.
	 *
	 * @return boolean
	 */
	public function is_cache_enabled() {
		if ( null === $this->cache_enabled ) {
			$this->cache_enabled = (int) WCCS()->settings->get_setting( 'cache_onsale_badge', 1 );
		}
		return 1 <= $this->cache_enabled;
	}

	/**
	 * Get tri-state on-sale status for a product.
	 *
	 * Returns:
	 * - 'yes_simple': On sale via a simple pricing rule (immediate single-item price discount).
	 * - 'yes': On sale via bulk, tiered, purchase, or products group rule (qualifies for sale badge only).
	 * - 'no': Not on sale.
	 *
	 * @param WC_Product|int    $product
	 * @param array|string|null $pricing_types
	 * @return string 'yes_simple'|'yes'|'no'
	 */
	public function get_onsale_status( $product, $pricing_types = null ) {
		if ( ! $product ) {
			return 'no';
		}

		$pricing_types = $this->normalize_pricing_types( $pricing_types );
		if ( empty( $pricing_types ) ) {
			return 'no';
		}

		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return 'no';
		}

		$pgen      = $this->get_product_cache_generation( $product_id );
		$segment   = $this->get_user_segment();
		$parts     = array( 'wccs_os', $product_id, $segment, $pgen );
		$parts     = apply_filters( 'asnp_wccs_product_onsale_cache_key_parts', $parts, $product_id, $pricing_types );
		$cache_key = md5( implode( '_', $parts ) );

		// 1. Fast local request memory cache check (0.0001ms)
		if ( isset( $this->local_cache[ $cache_key ] ) ) {
			return $this->local_cache[ $cache_key ];
		}

		// Resolve target IDs without retaining full WC_Product object
		$target       = WCCS()->product_helpers->get_product_target( $product );
		$parent_id    = $target['parent_id'];
		$variation_id = $target['variation_id'];
		$is_variable  = ! empty( $target['is_variable'] );

		if ( $parent_id <= 0 ) {
			return 'no';
		}

		// 2. Check persistent Object Cache (Redis/Memcached/RAM) fast-path
		if ( $this->is_cache_enabled() ) {
			$can_read_persistent = true;
			if ( $is_variable ) {
				$can_read_persistent = WCCS()->WCCS_Product_Price_Cache->can_cache_variation_prices( $product );
			}

			if ( $can_read_persistent ) {
				$cached = wp_cache_get( $cache_key, $this->get_cache_group() );
				if ( false !== $cached ) {
					$this->local_cache[ $cache_key ] = $cached;
					return $cached;
				}

				// 2b. Persistent Layer 2 DB Cache check (active when external object cache like Redis is absent)
				if ( ! wp_using_ext_object_cache() ) {
					$db_cached = $this->get_db_cache_entry( $product_id, $cache_key );
					if ( false !== $db_cached ) {
						$this->local_cache[ $cache_key ] = $db_cached;
						wp_cache_set( $cache_key, $db_cached, $this->get_cache_group() );
						return $db_cached;
					}
				}
			}
		}

		// 3. Cache Miss: Evaluate pricing rules
		$attributes    = array();
		$onsale_status = 'no';

		// Fast circuit breaker: if no rules are configured in the store, product cannot be on sale.
		if ( ! $this->pricing || empty( $this->pricing->get_all_pricing_rules() ) ) {
			$this->local_cache[ $cache_key ] = 'no';
			if ( $this->is_cache_enabled() ) {
				$default_ttl = $this->get_default_ttl();
				wp_cache_set( $cache_key, 'no', $this->get_cache_group(), $default_ttl > 0 ? max( 1, $default_ttl ) : 0 );
				if ( ! wp_using_ext_object_cache() ) {
					$this->set_db_cache_entry( $product_id, $cache_key, 'no', $default_ttl );
				}
			}
			return 'no';
		}

		// Fast circuit breaker: if no active pricing rules exist right now in store, product is not on sale.
		// Note: We avoid directly caching 'no' into persistent storage here to prevent locking dynamic
		// or scheduled rules into a stale 'no' status. Step 4 validates can_cache_rules() and time expiry.
		if ( ! $this->pricing->has_any_active_pricing_rule() ) {
			$onsale_status = 'no';
		} elseif ( ! $this->pricing->is_in_exclude_rules( $parent_id, $variation_id, $attributes ) ) {
			// Check Simple pricing rules first -> 'yes_simple'
			$matched_child_rule = null;
			if ( ! empty( $pricing_types['simple'] ) && $this->pricing->has_simple_rules() ) {
				$simple_rules = $this->pricing->get_simple_pricings();
				if ( ! empty( $simple_rules ) ) {
					if ( $this->check_rules( $simple_rules, $parent_id, $variation_id, $attributes ) ) {
						$onsale_status = 'yes_simple';
					} elseif ( $is_variable ) {
						// Product is a variable parent product without a direct rule on the parent.
						// Check inverted index: Does any child variation have a qualifying simple rule?
						$matched_child_rule = $this->find_matching_child_simple_rule( $product, $parent_id );
						if ( false !== $matched_child_rule ) {
							$onsale_status = 'yes_simple';
						}
					}
				}
			}

			// If not simple, check other enabled modes (bulk, tiered, purchase, products_group) -> 'yes'
			if ( 'no' === $onsale_status ) {
				foreach ( self::MODE_METHODS as $type => $method ) {
					if ( 'simple' === $type || empty( $pricing_types[ $type ] ) || ! method_exists( $this->pricing, $method ) ) {
						continue;
					}
					if ( 'bulk' === $type && ! $this->pricing->has_bulk_rules() ) {
						continue;
					}
					$rules = $this->pricing->$method();
					if ( ! empty( $rules ) ) {
						if ( $this->check_rules( $rules, $parent_id, $variation_id, $attributes ) ) {
							$onsale_status = 'yes';
							break;
						}
					}
				}
			}
		}

		$this->local_cache[ $cache_key ] = $onsale_status;

		// 4. Persistent Object Cache and Layer 2 DB Cache storage
		if ( $this->is_cache_enabled() ) {
			if ( 'yes_simple' === $onsale_status ) {
				// Fast path: Product is on sale via simple rule. Only simple candidate rules
				// determine cacheability and expiry, avoiding unnecessary checks on bulk/tiered rules.
				$candidate_types = array( 'simple' );
			} elseif ( 'yes' === $onsale_status ) {
				// Product is on sale via non-simple rule. Simple rules did not match, so inspect active non-simple rules.
				$candidate_types = array_values( array_diff( array_keys( $pricing_types ), array( 'simple' ) ) );
			} else {
				// Product is not on sale. Inspect all configured pricing types to ensure candidate rules allow caching.
				$candidate_types = array_keys( $pricing_types );
			}

			$candidate_rules = $this->get_product_candidate_rules( $parent_id, $variation_id, $candidate_types );

			// Persist if candidate rules for this product are cacheable across visitors of this segment.
			$can_persist = WCCS_Rules_Helpers::can_cache_rules( $candidate_rules, true );

			// When evaluating a variable product parent (variation_id === 0), ensure no child variation
			// has uncacheable (dynamic) pricing rules via O(1) Inverted Index.
			if ( $can_persist && $is_variable ) {
				$can_persist = WCCS()->WCCS_Product_Price_Cache->can_cache_variation_prices( $product );
			}

			if ( $can_persist ) {
				$default_ttl = $this->get_default_ttl();
				$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $candidate_rules );

				if ( $time_expiry > 0 ) {
					$ttl = ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry;
				} else {
					$ttl = $default_ttl;
				}

				wp_cache_set( $cache_key, $onsale_status, $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
				if ( ! wp_using_ext_object_cache() ) {
					$this->set_db_cache_entry( $product_id, $cache_key, $onsale_status, $ttl );
				}
			}
		}

		return $onsale_status;
	}

	/**
	 * Check if product is on sale.
	 *
	 * Returns true if product has any on-sale status ('yes_simple' or 'yes').
	 *
	 * @param WC_Product|int    $product
	 * @param array|string|null $pricing_types
	 * @return bool
	 */
	public function is_onsale( $product, $pricing_types = null ) {
		$status = $this->get_onsale_status( $product, $pricing_types );
		return ( 'yes' === $status || 'yes_simple' === $status );
	}

	/**
	 * Check if product is on sale via simple pricing rule.
	 *
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public function is_simple_onsale( $product ) {
		return ( 'yes_simple' === $this->get_onsale_status( $product ) );
	}

	/**
	 * Get candidate pricing rules targeting a product for the active pricing types.
	 *
	 * Resolves candidate rules across the requested modes in a single pass, deduplicating by rule ID.
	 * Memoized per request for optimal CPU and memory usage.
	 *
	 * @param int   $parent_id
	 * @param int   $variation_id
	 * @param array $types
	 * @return array
	 */
	public function get_product_candidate_rules( $parent_id, $variation_id, array $types ) {
		if ( ! $this->pricing || empty( $types ) ) {
			return array();
		}

		$cache_key = (int) $parent_id . '_' . (int) $variation_id . '_' . implode( '_', $types );
		if ( isset( $this->product_rules_cache[ $cache_key ] ) ) {
			return $this->product_rules_cache[ $cache_key ];
		}

		$candidate_rules = array();

		foreach ( $types as $mode ) {
			$method = 'get_product_' . $mode . '_rules';
			if ( ! is_callable( [ $this->pricing, $method ] ) ) {
				continue;
			}

			$rules = $this->pricing->$method( $parent_id, $variation_id, false );
			if ( empty( $rules ) ) {
				continue;
			}

			$is_simple = ( 'simple' === $mode );
			foreach ( $rules as $rule ) {
				// Fee rules do not provide sale discounts
				if ( $is_simple ) {
					$discount_type = is_object( $rule ) ? ( isset( $rule->discount_type ) ? $rule->discount_type : '' ) : ( isset( $rule['discount_type'] ) ? $rule['discount_type'] : '' );
					if ( 'percentage_fee' === $discount_type || 'price_fee' === $discount_type ) {
						continue;
					}
				}

				$rule_id = is_object( $rule ) ? (int) $rule->id : (int) $rule['id'];
				$candidate_rules[ $rule_id ] = $rule;
			}
		}

		// When evaluating variable product parent, include candidate simple rules targeting its variations
		if ( 0 === $variation_id && in_array( 'simple', $types, true ) && WCCS()->product_helpers->is_variable_product( $parent_id ) ) {
			$var_index = $this->get_simple_variation_rules_index();
			if ( ! empty( $var_index['parent_ids'][ $parent_id ] ) ) {
				foreach ( $var_index['parent_ids'][ $parent_id ] as $var_id => $rules ) {
					foreach ( $rules as $rule ) {
						$rule_id = is_object( $rule ) ? (int) $rule->id : (int) $rule['id'];
						$candidate_rules[ $rule_id ] = $rule;
					}
				}
			}
			if ( ! empty( $var_index['targeted_attributes'] ) ) {
				foreach ( $var_index['targeted_attributes'] as $attr_data ) {
					$rule    = $attr_data['rule'];
					$rule_id = is_object( $rule ) ? (int) $rule->id : (int) $rule['id'];
					$candidate_rules[ $rule_id ] = $rule;
				}
			}
		}

		$result = array_values( $candidate_rules );
		$this->product_rules_cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Check if any rule in a collection matches the product target.
	 *
	 * @param array $rules
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param array $attributes
	 * @return bool
	 */
	protected function check_rules( array $rules, $product_id, $variation_id = 0, array $attributes = array() ) {
		if ( empty( $rules ) || empty( $product_id ) ) {
			return false;
		}

		foreach ( $rules as $rule ) {
			if ( empty( $rule['mode'] ) ) {
				continue;
			}

			$match = ( 'products_group' === $rule['mode'] )
				? $this->check_groups_rule( $rule, $product_id, $variation_id, $attributes )
				: $this->check_rule( $rule, $product_id, $variation_id, $attributes );

			if ( $match ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check single rule for match against product target.
	 *
	 * @param array $rule
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param array $attributes
	 * @return bool
	 */
	protected function check_rule( array $rule, $product_id, $variation_id = 0, array $attributes = array() ) {
		if ( empty( $rule ) || empty( $product_id ) ) {
			return false;
		}

		if ( ! empty( $rule['mode'] ) && 'simple' === $rule['mode'] ) {
			if ( isset( $rule['discount_type'] ) && ( 'percentage_fee' === $rule['discount_type'] || 'price_fee' === $rule['discount_type'] ) ) {
				return false;
			}
		}

		if ( 0 === $variation_id && $this->rule_targets_variations( $rule ) ) {
			return false;
		}

		$validator = WCCS()->WCCS_Product_Validator;
		if ( ! $validator || ! $validator->is_valid_product( $rule['items'], $product_id, $variation_id, $attributes ) ) {
			return false;
		}

		if ( ! empty( $rule['exclude_items'] ) && $validator->is_valid_product( $rule['exclude_items'], $product_id, $variation_id, $attributes ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if a rule specifically targets variations or variation attributes.
	 *
	 * When evaluating a parent variable product directly (variation_id === 0),
	 * variation-targeted rules should not match the parent directly. They are instead
	 * evaluated per child variation via find_matching_child_simple_rule().
	 *
	 * @param array $rule
	 * @return bool
	 */
	protected function rule_targets_variations( array $rule ) {
		$items = WCCS_Rules_Helpers::get_rule_item_conditions( $rule );
		if ( empty( $items ) ) {
			return false;
		}

		$variation_items = array(
			'product_attributes',
			'subtotal_of_attributes_include_tax',
			'subtotal_of_attributes_exclude_tax',
			'product_variations_in_list',
			'variations_in_list',
			'subtotal_of_variations_include_tax',
			'subtotal_of_variations_exclude_tax',
		);

		foreach ( $items as $item ) {
			$method = isset( $item['item'] ) ? $item['item'] : ( isset( $item['condition'] ) ? $item['condition'] : ( isset( $item['type'] ) ? $item['type'] : '' ) );
			if ( in_array( $method, $variation_items, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check products group rule for match against product target.
	 *
	 * @param array $rule
	 * @param int   $product_id
	 * @param int   $variation_id
	 * @param array $attributes
	 * @return bool
	 */
	protected function check_groups_rule( array $rule, $product_id, $variation_id = 0, array $attributes = array() ) {
		if ( empty( $rule ) || empty( $product_id ) || empty( $rule['mode'] ) || 'products_group' !== $rule['mode'] || empty( $rule['groups'] ) ) {
			return false;
		}

		$validator = WCCS()->WCCS_Product_Validator;
		if ( ! $validator ) {
			return false;
		}

		foreach ( $rule['groups'] as $group ) {
			if ( empty( $group['items'] ) || ! $validator->is_valid_product( $group['items'], $product_id, $variation_id, $attributes ) ) {
				continue;
			}

			if ( ! empty( $rule['exclude_items'] ) && $validator->is_valid_product( $rule['exclude_items'], $product_id, $variation_id, $attributes ) ) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Clear the local request cache and memoized generation salts.
	 *
	 * @return void
	 */
	public function clear_local_cache() {
		parent::clear_local_cache();
		$this->product_rules_cache          = array();
		$this->configured_types             = null;
		$this->simple_variation_rules_index = null;
	}

	/**
	 * Register variation targets in the inverted variation rules index.
	 *
	 * Deduplicates parent ID resolution and index registration across condition types.
	 *
	 * @param array        $index Reference to index array.
	 * @param array        $target_ids Array of variation or product IDs.
	 * @param int          $rule_id Rule ID.
	 * @param object|array $rule Rule object or array.
	 * @return void
	 */
	protected function index_variation_rule_targets( array &$index, array $target_ids, $rule_id, $rule ) {
		foreach ( $target_ids as $id ) {
			$id        = absint( WCCS_Helpers::maybe_get_exact_item_id( $id ) );
			$parent_id = (int) wp_get_post_parent_id( $id );
			// if ( $parent_id <= 0 ) {
			// 	$wc_p = wc_get_product( $id );
			// 	if ( $wc_p && $wc_p->is_type( 'variation' ) ) {
			// 		$parent_id = (int) $wc_p->get_parent_id();
			// 	}
			// }
			if ( $parent_id > 0 ) {
				$index['has_variation_rules'] = true;
				if ( ! isset( $index['parent_ids'][ $parent_id ] ) ) {
					$index['parent_ids'][ $parent_id ] = array();
				}
				if ( ! isset( $index['parent_ids'][ $parent_id ][ $id ] ) ) {
					$index['parent_ids'][ $parent_id ][ $id ] = array();
				}
				$index['parent_ids'][ $parent_id ][ $id ][ $rule_id ] = $rule;
			}
		}
	}

	/**
	 * Pre-scanned inverted index of active simple pricing rules targeting variations.
	 *
	 * Builds an O(1) lookup map of parent IDs and variation rules on the first check per request.
	 * Bypasses WC_Product_Variation object instantiations completely.
	 *
	 * @return array
	 */
	public function get_simple_variation_rules_index() {
		if ( null !== $this->simple_variation_rules_index ) {
			return $this->simple_variation_rules_index;
		}

		$index = array(
			'has_variation_rules'   => false,
			'parent_ids'            => array(),
			'targeted_attributes'   => array(),
			'all_variable_affected' => false,
		);

		if ( ! $this->pricing || ! $this->pricing->has_simple_rules() ) {
			return $this->simple_variation_rules_index = $index;
		}

		$simple_rules = $this->pricing->get_simple_pricings();
		if ( empty( $simple_rules ) ) {
			return $this->simple_variation_rules_index = $index;
		}

		foreach ( $simple_rules as $rule ) {
			$discount_type = is_object( $rule ) ? ( isset( $rule->discount_type ) ? $rule->discount_type : '' ) : ( isset( $rule['discount_type'] ) ? $rule['discount_type'] : '' );
			if ( 'percentage_fee' === $discount_type || 'price_fee' === $discount_type ) {
				continue;
			}

			$items = WCCS_Rules_Helpers::get_rule_item_conditions( $rule );
			if ( empty( $items ) ) {
				continue;
			}

			$rule_id = is_object( $rule ) ? ( isset( $rule->id ) ? (int) $rule->id : 0 ) : ( isset( $rule['id'] ) ? (int) $rule['id'] : 0 );

			foreach ( $items as $item ) {
				$method = isset( $item['item'] ) ? $item['item'] : ( isset( $item['condition'] ) ? $item['condition'] : ( isset( $item['type'] ) ? $item['type'] : '' ) );

				switch ( $method ) {
					case 'subtotal_of_variations_include_tax':
					case 'subtotal_of_variations_exclude_tax':
					case 'product_variations_in_list':
					case 'variations_in_list':
						$var_ids = isset( $item['variations'] ) && is_array( $item['variations'] ) ? $item['variations'] : array();
						if ( ! empty( $var_ids ) ) {
							$this->index_variation_rule_targets( $index, $var_ids, $rule_id, $rule );
						}
						break;

					case 'product_variations_not_in_list':
						$index['has_variation_rules']   = true;
						$index['all_variable_affected'] = true;
						break;

					case 'subtotal_of_attributes_include_tax':
					case 'subtotal_of_attributes_exclude_tax':
					case 'product_attributes':
						$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
						if ( ! empty( $attributes ) ) {
							$index['has_variation_rules']   = true;
							$index['targeted_attributes'][] = array(
								'rule'       => $rule,
								'attributes' => $attributes,
							);
						}
						break;

					case 'subtotal_of_products_include_tax':
					case 'subtotal_of_products_exclude_tax':
					case 'products_in_list':
						$pids = isset( $item['products'] ) && is_array( $item['products'] ) ? $item['products'] : array();
						if ( ! empty( $pids ) ) {
							$this->index_variation_rule_targets( $index, $pids, $rule_id, $rule );
						}
						break;
				}
			}
		}

		return $this->simple_variation_rules_index = $index;
	}

	/**
	 * Find if any child variation of a variable product matches an active simple rule.
	 *
	 * Uses inverted index with zero object instantiation for O(1) determination.
	 *
	 * @param WC_Product|int $product
	 * @param int            $parent_id
	 * @return array|object|false Matching rule array/object or false
	 */
	protected function find_matching_child_simple_rule( $product, $parent_id ) {
		$var_index = $this->get_simple_variation_rules_index();

		// Circuit breaker 1: Zero variation rules in the entire store
		if ( ! $var_index['has_variation_rules'] ) {
			return false;
		}

		$wc_prod = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $parent_id );
		if ( ! $wc_prod || ! $wc_prod->is_type( 'variable' ) ) {
			return false;
		}

		// Fast Path 1: Check inverted parent index (O(1) direct parent hash map lookup)
		if ( isset( $var_index['parent_ids'][ $parent_id ] ) ) {
			foreach ( $var_index['parent_ids'][ $parent_id ] as $var_id => $rules ) {
				foreach ( $rules as $rule ) {
					$rule_array = is_array( $rule ) ? $rule : (array) $rule;
					if ( ! $this->pricing->is_in_exclude_rules( $parent_id, $var_id ) ) {
						if ( $this->check_rule( $rule_array, $parent_id, $var_id ) ) {
							return $rule;
						}
					}
				}
			}
		}

		$children = null;

		// Fast Path 2: Universal variation rules (e.g. variations_not_in_list)
		if ( $var_index['all_variable_affected'] ) {
			$children = isset( $children ) ? $children : $wc_prod->get_children();
			if ( ! empty( $children ) ) {
				$simple_rules = $this->pricing->get_simple_pricings();
				if ( ! empty( $simple_rules ) ) {
					foreach ( $children as $child_id ) {
						$child_id = (int) $child_id;
						if ( ! $this->pricing->is_in_exclude_rules( $parent_id, $child_id ) ) {
							foreach ( $simple_rules as $rule ) {
								$rule_array = is_array( $rule ) ? $rule : (array) $rule;
								if ( $this->check_rule( $rule_array, $parent_id, $child_id ) ) {
									return $rule;
								}
							}
						}
					}
				}
			}
		}

		return false;
	}

}

