<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Product_Price_Cache extends WCCS_Abstract_Cache {

	const TYPE               = 'price';
	const CACHE_GROUP        = 'asnp_wccs_product_price';
	const PRODUCT_GEN_PREFIX = 'asnp_wccs_pgen_';

	/**
	 * Memoized product rules per request.
	 *
	 * @var array
	 */
	protected $product_rules_cache = array();

	/**
	 * Pre-scanned inverted index of uncacheable variation rules for O(1) variable product cache validation.
	 *
	 * @var array|null
	 */
	protected $uncacheable_variation_rules_index = null;

	/**
	 * Pre-scanned inverted index of scheduled variation rules for O(1) variable product TTL calculation.
	 *
	 * @var array|null
	 */
	protected $scheduled_variation_rules_index = null;

	/**
	 * Memoized can_cache_variation decisions per product ID for the current request.
	 *
	 * @var array
	 */
	protected $can_cache_variation_cache = array();

	public function __construct( $pricing = null ) {
		$this->pricing = null === $pricing ? WCCS()->pricing : $pricing;
		parent::__construct( 'wccs_product_price_', self::CACHE_GROUP, self::TYPE, self::PRODUCT_GEN_PREFIX );
	}

	public function get_price( $product, $price, $price_type, $base_price = null, $product_pricing = null ) {
		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return $price;
		}

		$cache_key = $this->build_smart_cache_key( $product_id, $price_type, $base_price );

		// 1. Fast local memory check (0.0001ms)
		if ( isset( $this->local_cache[ $cache_key ] ) ) {
			return 'null' === $this->local_cache[ $cache_key ] ? null : $this->local_cache[ $cache_key ];
		}

		// 2. Persistent Object Cache (Redis/Memcached) check
		if ( $this->is_cache_enabled() ) {
			$cached = wp_cache_get( $cache_key, $this->get_cache_group() );
			if ( false !== $cached ) {
				$this->local_cache[ $cache_key ] = $cached;
				return 'null' === $cached ? null : $cached;
			}

			// 2b. Persistent Layer 2 DB Cache (active when external object cache like Redis is absent)
			if ( ! wp_using_ext_object_cache() ) {
				$db_cached = $this->get_db_cache_entry( $product_id, $cache_key );
				if ( false !== $db_cached ) {
					$this->local_cache[ $cache_key ] = $db_cached;
					wp_cache_set( $cache_key, $db_cached, $this->get_cache_group() );
					return 'null' === $db_cached ? null : $db_cached;
				}
			}
		}

		// 3. Compute price using local instance to avoid memory retention
		$pricing_instance = is_a( $product_pricing, 'WCCS_Public_Product_Pricing' ) && (int) $product_pricing->product_id === $product_id
			? $product_pricing
			: new WCCS_Public_Product_Pricing( $product, $this->pricing );

		$valid_rules = $pricing_instance->get_simple_discounts();
		if ( empty( $valid_rules ) ) {
			if ( $this->is_cache_enabled() ) {
				$all_rules = $this->get_product_all_simple_rules( $product );
				if ( empty( $all_rules ) ) {
					$default_ttl  = $this->get_default_ttl();
					$val_to_cache = null === $price ? 'null' : $price;
					wp_cache_set( $cache_key, $val_to_cache, $this->get_cache_group(), $default_ttl > 0 ? max( 1, $default_ttl ) : 0 );
					if ( ! wp_using_ext_object_cache() ) {
						$this->set_db_cache_entry( $product_id, $cache_key, $val_to_cache, $default_ttl );
					}
				} elseif ( WCCS_Rules_Helpers::can_cache_rules( $all_rules, true ) ) {
					$default_ttl  = $this->get_default_ttl();
					$time_expiry  = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
					$ttl          = ( $time_expiry > 0 ) ? ( ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry ) : $default_ttl;
					$val_to_cache = null === $price ? 'null' : $price;
					wp_cache_set( $cache_key, $val_to_cache, $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
					if ( ! wp_using_ext_object_cache() ) {
						$this->set_db_cache_entry( $product_id, $cache_key, $val_to_cache, $ttl );
					}
				}
			}
			$this->local_cache[ $cache_key ] = null === $price ? 'null' : $price;
			return $price;
		}

		$computed = $pricing_instance->get_price( $base_price );
		if ( is_numeric( $computed ) && 0 <= $computed ) {
			if ( $this->is_cache_enabled() ) {
				$all_rules = $this->get_product_all_simple_rules( $product );
				if ( WCCS_Rules_Helpers::can_cache_rules( $all_rules, true ) ) {
					$default_ttl = $this->get_default_ttl();
					$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );

					if ( $time_expiry > 0 ) {
						$ttl = ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry;
					} else {
						$ttl = $default_ttl;
					}

					wp_cache_set( $cache_key, $computed, $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
					if ( ! wp_using_ext_object_cache() ) {
						$this->set_db_cache_entry( $product_id, $cache_key, $computed, $ttl );
					}
				}
			}
			$this->local_cache[ $cache_key ] = $computed;
			return $computed;
		}

		$this->local_cache[ $cache_key ] = null === $price ? 'null' : $price;

		return $price;
	}

	protected function build_smart_cache_key( $product_id, $price_type, $base_price = null ) {
		$parts = array( (int) $product_id, (string) $price_type );

		if ( null !== $base_price && '' !== $base_price ) {
			$parts[] = is_numeric( $base_price ) ? (string) (float) $base_price : (string) $base_price;
		}

		$currency = get_woocommerce_currency();
		if ( ! empty( $currency ) ) {
			$parts[] = (string) $currency;
		}

		$tax_display = get_option( 'woocommerce_tax_display_shop', 'excl' );
		$parts[]     = 'tax_' . $tax_display;

		$is_vat_exempt = ( WC()->customer && WC()->customer->get_is_vat_exempt() ) ? 'exempt' : 'taxable';
		$parts[]       = 'vat_' . $is_vat_exempt;

		$parts[] = $this->get_user_segment();
		$parts[] = $this->get_product_cache_generation( $product_id );

		$parts = apply_filters( 'asnp_wccs_product_price_cache_key_parts', $parts, $product_id, $price_type, $base_price );

		return md5( implode( '_', $parts ) );
	}

	/**
	 * Build cache key for bulk pricing HTML.
	 *
	 * Uses product ID, numeric base price, active currency, and product cache generation.
	 * Static rules are shared across all visitors for maximum cache hit rate and minimal memory footprint.
	 * Dynamic rules (role-based, cart-based) are never persisted cross-user via can_cache_rules().
	 *
	 * @param int               $product_id
	 * @param float|string|null $base_price
	 * @param string|null       $currency
	 * @return string
	 */
	public function build_bulk_cache_key( $product_id, $base_price = null, $currency = null ) {
		$parts = array(
			'bulk_html',
			(int) $product_id,
		);

		if ( null !== $base_price && '' !== $base_price ) {
			$parts[] = is_numeric( $base_price ) ? (string) (float) $base_price : (string) $base_price;
		}

		$currency = null === $currency ? get_woocommerce_currency() : $currency;
		if ( ! empty( $currency ) ) {
			$parts[] = (string) $currency;
		}

		$tax_display = get_option( 'woocommerce_tax_display_shop', 'excl' );
		$parts[]     = 'tax_' . $tax_display;

		$is_vat_exempt = ( WC()->customer && WC()->customer->get_is_vat_exempt() ) ? 'exempt' : 'taxable';
		$parts[]       = 'vat_' . $is_vat_exempt;

		$parts[] = $this->get_user_segment();
		$parts[] = $this->get_product_cache_generation( $product_id );

		$parts = apply_filters( 'asnp_wccs_product_bulk_price_cache_key_parts', $parts, $product_id, $base_price, $currency );

		return md5( implode( '_', $parts ) );
	}

	/**
	 * Retrieve cached bulk pricing HTML from local memory or object cache.
	 *
	 * Zero rule evaluation, zero database queries on cache hit.
	 *
	 * @param int               $product_id
	 * @param float|string|null $base_price
	 * @param string|null       $currency
	 * @return string|false False on cache miss, or cached string (can be 'false' for negative cache).
	 */
	public function get_cached_bulk_price_html( $product_id, $base_price = null, $currency = null ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 ) {
			return false;
		}

		$cache_key = $this->build_bulk_cache_key( $product_id, $base_price, $currency );

		// 1. Fast local memory cache check (0.0001ms)
		if ( isset( $this->local_cache[ $cache_key ] ) ) {
			return $this->local_cache[ $cache_key ];
		}

		// 2. Persistent Object Cache (Redis/Memcached) check
		if ( $this->is_cache_enabled() ) {
			$cached = wp_cache_get( $cache_key, $this->get_cache_group() );
			if ( false !== $cached ) {
				$this->local_cache[ $cache_key ] = $cached;
				return $cached;
			}

			// 2b. Persistent Layer 2 DB Cache check
			if ( ! wp_using_ext_object_cache() ) {
				$db_cached = $this->get_db_cache_entry( $product_id, $cache_key );
				if ( false !== $db_cached ) {
					$this->local_cache[ $cache_key ] = $db_cached;
					wp_cache_set( $cache_key, $db_cached, $this->get_cache_group() );
					return $db_cached;
				}
			}
		}

		return false;
	}

	/**
	 * Store bulk pricing HTML in local memory cache and persistent object cache if safe.
	 *
	 * @param WC_Product|int    $product
	 * @param string            $content
	 * @param float|string|null $base_price
	 * @param string|null       $currency
	 * @return bool
	 */
	public function cache_bulk_price_html( $product, $content, $base_price = null, $currency = null ) {
		if ( ! $product ) {
			return false;
		}

		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return false;
		}

		$cache_key = $this->build_bulk_cache_key( $product_id, $base_price, $currency );

		// 1. Store in local request memory cache
		$this->local_cache[ $cache_key ] = $content;

		// 2. Persistent Object Cache storage (if simple cache enabled)
		if ( $this->is_cache_enabled() ) {
			$all_bulk_rules = $this->get_product_all_bulk_rules( $product );

			if ( 'false' === $content ) {
				// If the product has NO candidate bulk rules configured at all, safely persist negative cache
				if ( empty( $all_bulk_rules ) ) {
					$default_ttl = $this->get_default_ttl();
					wp_cache_set( $cache_key, 'false', $this->get_cache_group(), $default_ttl > 0 ? max( 1, $default_ttl ) : 0 );
					if ( ! wp_using_ext_object_cache() ) {
						$this->set_db_cache_entry( $product_id, $cache_key, 'false', $default_ttl );
					}
				} elseif ( WCCS_Rules_Helpers::can_cache_rules( $all_bulk_rules, true ) ) {
					$default_ttl = $this->get_default_ttl();
					$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_bulk_rules );
					$ttl         = ( $time_expiry > 0 ) ? ( ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry ) : $default_ttl;
					wp_cache_set( $cache_key, 'false', $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
					if ( ! wp_using_ext_object_cache() ) {
						$this->set_db_cache_entry( $product_id, $cache_key, 'false', $ttl );
					}
				}
				return true;
			}

			// If candidate bulk rules are cacheable by role segment:
			if ( ! empty( $all_bulk_rules ) && WCCS_Rules_Helpers::can_cache_rules( $all_bulk_rules, true ) ) {
				$default_ttl = $this->get_default_ttl();
				$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_bulk_rules );

				if ( $time_expiry > 0 ) {
					$ttl = ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry;
				} else {
					$ttl = $default_ttl;
				}

				wp_cache_set( $cache_key, $content, $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
				if ( ! wp_using_ext_object_cache() ) {
					$this->set_db_cache_entry( $product_id, $cache_key, $content, $ttl );
				}
			}
		}

		return true;
	}

	/**
	 * Build unique deterministic cache key for variable product variation prices.
	 *
	 * Differentiates by product ID, display mode (lightweight vs advanced), tax display,
	 * customer VAT exemption, active currency, user segment, and generation salts.
	 *
	 * @param int    $product_id
	 * @param bool   $for_display
	 * @param string $mode 'lightweight' or 'advanced'
	 * @return string
	 */
	public function build_variation_cache_key( $product_id, $for_display = true, $mode = 'lightweight' ) {
		$parts = array(
			'var',
			(int) $product_id,
			(string) $mode,
			$for_display ? 'disp1' : 'disp0',
		);

		$currency = get_woocommerce_currency();
		if ( ! empty( $currency ) ) {
			$parts[] = (string) $currency;
		}

		if ( $for_display && wc_tax_enabled() ) {
			$parts[]       = 'tax_' . get_option( 'woocommerce_tax_display_shop', 'excl' );
			$is_vat_exempt = ( WC()->customer && WC()->customer->get_is_vat_exempt() ) ? 'exempt' : 'taxable';
			$parts[]       = 'vat_' . $is_vat_exempt;
		} else {
			$parts[] = 'notax';
		}

		$parts[] = $this->get_user_segment();
		$parts[] = $this->get_product_cache_generation( $product_id );

		$parts = apply_filters( 'asnp_wccs_product_variation_price_cache_key_parts', $parts, $product_id, $for_display, $mode );

		return md5( implode( '_', $parts ) );
	}

	/**
	 * Determine if variation prices can be safely read from cache for a product.
	 *
	 * Directly evaluates request context and delegates rule cacheability to can_cache_variation_prices(),
	 * eliminating duplicate memoization memory structures.
	 *
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public function can_read_variation_cached_prices( $product ) {
		if ( ! $this->is_cache_enabled() ) {
			return false;
		}

		if ( is_admin() && ! defined( 'DOING_AJAX' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
			return false;
		}

		if ( ! $this->can_cache_variation_prices( $product ) ) {
			return false;
		}

		if ( has_filter( 'wccs_product_price_cache_can_read_variation_cached_prices' ) ) {
			return (bool) apply_filters( 'wccs_product_price_cache_can_read_variation_cached_prices', true, $product );
		}

		return true;
	}

	/**
	 * Determine if variation prices can be stored into persistent cross-request cache.
	 *
	 * Single source of truth for variation rule cacheability evaluation.
	 * Uses Inverted Rule Indexing (Reverse Pre-Scan) for O(1) decision making.
	 *
	 * @param WC_Product|int $product
	 * @param array|null     $rules
	 * @return bool
	 */
	public function can_cache_variation_prices( $product, $rules = null ) {
		if ( ! $this->is_cache_enabled() ) {
			return false;
		}

		if ( null !== $rules ) {
			return empty( $rules ) || WCCS_Rules_Helpers::can_cache_rules( $rules, true );
		}

		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return false;
		}

		if ( isset( $this->can_cache_variation_cache[ $product_id ] ) ) {
			return $this->can_cache_variation_cache[ $product_id ];
		}

		// 1. Evaluate rules applying directly to the variable parent product (O(1))
		$parent_rules = $this->get_product_all_simple_rules( $product );
		if ( ! empty( $parent_rules ) && ! WCCS_Rules_Helpers::can_cache_rules( $parent_rules, true ) ) {
			$this->can_cache_variation_cache[ $product_id ] = false;
			return false;
		}

		// 2. Inverted Index Fast-Path: Check if any uncacheable rules target variations
		$index = $this->get_uncacheable_variation_rules_index();
		if ( ! $index['has_uncacheable_variation_rules'] ) {
			// FAST PATH: Zero uncacheable variation rules in the entire store!
			$this->can_cache_variation_cache[ $product_id ] = true;
			return true;
		}

		// 3. Inverted Index Parent Match: Check if this specific variable product owns targeted variations
		if ( isset( $index['uncacheable_parent_ids'][ $product_id ] ) ) {
			$this->can_cache_variation_cache[ $product_id ] = false;
			return false;
		}

		// 4. Check attribute conditions or global 'not_in_list' if present
		if ( $index['all_variable_affected'] || ! empty( $index['targeted_attributes'] ) ) {
			$var_rules = $this->get_variable_product_all_simple_rules( $product );
			$res       = empty( $var_rules ) || WCCS_Rules_Helpers::can_cache_rules( $var_rules, true );
			$this->can_cache_variation_cache[ $product_id ] = $res;
			return $res;
		}

		$this->can_cache_variation_cache[ $product_id ] = true;
		return true;
	}

	/**
	 * Retrieve cached variation prices array across 3 tiers (Memory -> Redis -> L2 DB).
	 *
	 * Fast local memory cache (Tier 1) is checked immediately without rule scanning or
	 * cache-enabled gating, ensuring microsecond response times for repeat calls in the same request.
	 *
	 * @param WC_Product|int $product
	 * @param bool           $for_display
	 * @param string         $mode 'lightweight' or 'advanced'
	 * @return array|false False on cache miss, or array containing 'price', 'regular_price', 'sale_price'
	 */
	public function get_variation_prices( $product, $for_display = true, $mode = 'lightweight' ) {
		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return false;
		}

		$cache_key = $this->build_variation_cache_key( $product_id, $for_display, $mode );

		// 1. Fast local memory cache check (0.0001ms) - always accessible during current request
		if ( isset( $this->local_cache[ $cache_key ] ) && is_array( $this->local_cache[ $cache_key ] ) ) {
			return $this->local_cache[ $cache_key ];
		}

		// 2. Persistent Cross-Request Cache (Redis / Memcached / Layer 2 DB)
		if ( $this->is_cache_enabled() && $this->can_read_variation_cached_prices( $product ) ) {
			$cached = wp_cache_get( $cache_key, $this->get_cache_group() );
			if ( false !== $cached && is_array( $cached ) ) {
				$this->local_cache[ $cache_key ] = $cached;
				return $cached;
			}

			// 3. Persistent Layer 2 DB Cache (wp_wccs_cache)
			if ( ! wp_using_ext_object_cache() ) {
				$db_cached = $this->get_db_cache_entry( $product_id, $cache_key );
				if ( false !== $db_cached && is_array( $db_cached ) ) {
					$this->local_cache[ $cache_key ] = $db_cached;
					wp_cache_set( $cache_key, $db_cached, $this->get_cache_group() );
					return $db_cached;
				}
			}
		}

		return false;
	}

	/**
	 * Store computed variation prices into 3-tier cache with write compaction and row-level TTL.
	 *
	 * Tier 1 (local memory cache) is always stored for the current request.
	 * Tier 2 (Redis) and Tier 3 (L2 DB) are safely guarded by $can_persist and $this->is_cache_enabled().
	 *
	 * @param int        $product_id
	 * @param string     $cache_key
	 * @param array      $prices_array
	 * @param int|null   $ttl
	 * @param bool       $can_persist
	 * @return bool
	 */
	public function set_variation_prices( $product_id, $cache_key, array $prices_array, $ttl = null, $can_persist = true ) {
		$product_id = (int) $product_id;
		if ( $product_id <= 0 || empty( $cache_key ) || empty( $prices_array ) ) {
			return false;
		}

		// 1. Tier 1: Local memory cache (always stored in RAM for current request)
		$this->local_cache[ $cache_key ] = $prices_array;

		// 2. Persistent Cross-Request Cache (Redis / Memcached / Layer 2 DB)
		if ( $can_persist && $this->is_cache_enabled() ) {
			$ttl = ( null === $ttl ) ? $this->get_default_ttl() : (int) $ttl;

			wp_cache_set( $cache_key, $prices_array, $this->get_cache_group(), $ttl > 0 ? max( 1, (int) $ttl ) : 0 );

			// 3. Tier 3: Layer 2 DB Cache (wp_wccs_cache)
			if ( ! wp_using_ext_object_cache() ) {
				$this->set_db_cache_entry( $product_id, $cache_key, $prices_array, $ttl );
			}
		}

		return true;
	}

	/**
	 * Extension for woocommerce_get_variation_prices_hash to keep WooCommerce core's
	 * internal transient (wc_var_prices_<id>) 100% synchronized with dynamic pricing.
	 *
	 * @param WC_Product|int $product
	 * @param bool           $for_display
	 * @return array
	 */
	public function get_variation_hash_data( $product, $for_display = false ) {
		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		return array(
			'gen'     => $this->get_cache_generation(),
			'pgen'    => $this->get_product_cache_generation( $product_id ),
			'user'    => $this->get_user_segment(),
			'curr'    => get_woocommerce_currency(),
			'vat'     => ( ! empty( WC()->customer ) && WC()->customer->is_vat_exempt() ) ? 1 : 0,
			'display' => $for_display ? 1 : 0,
		);
	}

	/**
	 * Delete in-memory and local cache entries for a single product ID.
	 *
	 * @param int $product_id
	 * @return void
	 */
	protected function delete_product_local_cache( $product_id ) {
		parent::delete_product_local_cache( $product_id );
		unset( $this->can_cache_variation_cache[ $product_id ] );
	}

	public function clear_local_cache() {
		parent::clear_local_cache();
		$this->product_rules_cache              = array();
		$this->can_cache_variation_cache        = array();
		$this->uncacheable_variation_rules_index = null;
		$this->scheduled_variation_rules_index  = null;
	}

	/**
	 * Pre-scanned inverted index of uncacheable variation rules for O(1) decision making.
	 *
	 * Pre-scans the store's active simple rules once per request.
	 * If no uncacheable rules target variations (95%+ of stores), variable products
	 * execute ZERO variation loops during archive page and catalog rendering.
	 *
	 * @return array
	 */
	public function get_uncacheable_variation_rules_index() {
		if ( null !== $this->uncacheable_variation_rules_index ) {
			return $this->uncacheable_variation_rules_index;
		}

		$index = array(
			'has_uncacheable_variation_rules' => false,
			'all_variable_affected'           => false,
			'uncacheable_parent_ids'          => array(),
			'targeted_attributes'             => array(),
			'uncacheable_rules'               => array(),
		);

		if ( ! $this->pricing ) {
			$this->uncacheable_variation_rules_index = $index;
			return $index;
		}

		$rules = $this->pricing->get_all_pricing_rules();
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			$this->uncacheable_variation_rules_index = $index;
			return $index;
		}

		foreach ( $rules as $rule ) {
			$mode = is_object( $rule ) ? ( isset( $rule->mode ) ? $rule->mode : '' ) : ( isset( $rule['mode'] ) ? $rule['mode'] : '' );
			if ( 'simple' !== $mode ) {
				continue;
			}

			// If the rule is cacheable, it cannot cause cache poisoning
			if ( WCCS_Rules_Helpers::can_cache_rules( array( $rule ), true ) ) {
				continue;
			}

			// Extract condition items from rule
			$items = WCCS_Rules_Helpers::get_rule_item_conditions( $rule );
			if ( empty( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$method = isset( $item['item'] ) ? $item['item'] : ( isset( $item['condition'] ) ? $item['condition'] : '' );

				switch ( $method ) {
					case 'subtotal_of_variations_include_tax':
					case 'subtotal_of_variations_exclude_tax':
					case 'product_variations_in_list':
						$var_ids = isset( $item['variations'] ) && is_array( $item['variations'] ) ? $item['variations'] : array();
						if ( ! empty( $var_ids ) ) {
							$index['has_uncacheable_variation_rules'] = true;
							$index['uncacheable_rules'][]             = $rule;
							foreach ( $var_ids as $var_id ) {
								$var_id    = absint( WCCS_Helpers::maybe_get_exact_item_id( $var_id ) );
								$parent_id = wp_get_post_parent_id( $var_id );
								if ( $parent_id <= 0 ) {
									$wc_p = wc_get_product( $var_id );
									if ( $wc_p ) {
										$parent_id = $wc_p->get_parent_id();
									}
								}
								if ( $parent_id > 0 ) {
									$index['uncacheable_parent_ids'][ (int) $parent_id ] = true;
								} else {
									// If parent ID cannot be resolved, safely mark all variable products
									$index['all_variable_affected'] = true;
								}
							}
						}
						break;

					case 'product_variations_not_in_list':
						$index['has_uncacheable_variation_rules'] = true;
						$index['all_variable_affected']           = true;
						$index['uncacheable_rules'][]             = $rule;
						break;

					case 'subtotal_of_attributes_include_tax':
					case 'subtotal_of_attributes_exclude_tax':
					case 'product_attributes':
						$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
						if ( ! empty( $attributes ) ) {
							$index['has_uncacheable_variation_rules'] = true;
							$index['targeted_attributes'][]           = $attributes;
							$index['uncacheable_rules'][]             = $rule;
						}
						break;

					case 'subtotal_of_products_include_tax':
					case 'subtotal_of_products_exclude_tax':
					case 'products_in_list':
						$pids = isset( $item['products'] ) && is_array( $item['products'] ) ? $item['products'] : array();
						foreach ( $pids as $pid ) {
							$pid       = absint( WCCS_Helpers::maybe_get_exact_item_id( $pid ) );
							$parent_id = wp_get_post_parent_id( $pid );
							if ( $parent_id > 0 ) {
								// An individual variation ID was specified inside products_in_list!
								$index['has_uncacheable_variation_rules']            = true;
								$index['uncacheable_parent_ids'][ (int) $parent_id ] = true;
								$index['uncacheable_rules'][]                        = $rule;
							}
						}
						break;
				}
			}
		}

		$this->uncacheable_variation_rules_index = $index;
		return $index;
	}

	/**
	 * Pre-scanned inverted index of scheduled variation rules for O(1) TTL calculation.
	 *
	 * @return array
	 */
	public function get_scheduled_variation_rules_index() {
		if ( null !== $this->scheduled_variation_rules_index ) {
			return $this->scheduled_variation_rules_index;
		}

		$index = array(
			'has_scheduled_variation_rules' => false,
			'all_variable_affected'         => false,
			'scheduled_parent_ids'          => array(),
			'targeted_attributes'           => array(),
			'scheduled_rules'               => array(),
		);

		if ( ! $this->pricing ) {
			$this->scheduled_variation_rules_index = $index;
			return $index;
		}

		$rules = $this->pricing->get_all_pricing_rules();
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			$this->scheduled_variation_rules_index = $index;
			return $index;
		}

		foreach ( $rules as $rule ) {
			$mode = is_object( $rule ) ? ( isset( $rule->mode ) ? $rule->mode : '' ) : ( isset( $rule['mode'] ) ? $rule['mode'] : '' );
			if ( 'simple' !== $mode && ! empty( $mode ) ) {
				continue;
			}

			$date_time = is_object( $rule ) ? ( isset( $rule->date_time ) ? $rule->date_time : array() ) : ( isset( $rule['date_time'] ) ? $rule['date_time'] : array() );
			if ( empty( $date_time ) || ! is_array( $date_time ) ) {
				continue;
			}

			// Check if rule has an expiry timestamp
			$has_expiry = false;
			foreach ( $date_time as $dt ) {
				if ( ! empty( $dt['end_date'] ) || ! empty( $dt['end_time'] ) ) {
					$has_expiry = true;
					break;
				}
			}
			if ( ! $has_expiry ) {
				continue;
			}

			$items = WCCS_Rules_Helpers::get_rule_item_conditions( $rule );
			if ( empty( $items ) ) {
				continue;
			}

			foreach ( $items as $item ) {
				$method = isset( $item['item'] ) ? $item['item'] : ( isset( $item['condition'] ) ? $item['condition'] : '' );

				switch ( $method ) {
					case 'subtotal_of_variations_include_tax':
					case 'subtotal_of_variations_exclude_tax':
					case 'product_variations_in_list':
						$var_ids = isset( $item['variations'] ) && is_array( $item['variations'] ) ? $item['variations'] : array();
						if ( ! empty( $var_ids ) ) {
							$index['has_scheduled_variation_rules'] = true;
							$index['scheduled_rules'][]             = $rule;
							foreach ( $var_ids as $var_id ) {
								$var_id    = absint( WCCS_Helpers::maybe_get_exact_item_id( $var_id ) );
								$parent_id = wp_get_post_parent_id( $var_id );
								if ( $parent_id > 0 ) {
									$index['scheduled_parent_ids'][ (int) $parent_id ] = true;
								}
							}
						}
						break;

					case 'product_variations_not_in_list':
						$index['has_scheduled_variation_rules'] = true;
						$index['all_variable_affected']         = true;
						$index['scheduled_rules'][]             = $rule;
						break;

					case 'subtotal_of_attributes_include_tax':
					case 'subtotal_of_attributes_exclude_tax':
					case 'product_attributes':
						$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] ) ? $item['attributes'] : array();
						if ( ! empty( $attributes ) ) {
							$index['has_scheduled_variation_rules'] = true;
							$index['targeted_attributes'][]         = $attributes;
							$index['scheduled_rules'][]             = $rule;
						}
						break;

					case 'subtotal_of_products_include_tax':
					case 'subtotal_of_products_exclude_tax':
					case 'products_in_list':
						$pids = isset( $item['products'] ) && is_array( $item['products'] ) ? $item['products'] : array();
						foreach ( $pids as $pid ) {
							$pid       = absint( WCCS_Helpers::maybe_get_exact_item_id( $pid ) );
							$parent_id = wp_get_post_parent_id( $pid );
							if ( $parent_id > 0 ) {
								// An individual variation ID was specified inside products_in_list!
								$index['has_scheduled_variation_rules']            = true;
								$index['scheduled_parent_ids'][ (int) $parent_id ] = true;
								$index['scheduled_rules'][]                        = $rule;
							}
						}
						break;
				}
			}
		}

		$this->scheduled_variation_rules_index = $index;
		return $index;
	}

	/**
	 * Retrieve all simple pricing rules that apply to a variable product or ANY of its variations.
	 *
	 * Uses inverted rule indexing to skip child variation loops when no variation rules exist.
	 * Memoized per request under 'var_parent_{product_id}'.
	 *
	 * @param WC_Product_Variable|WC_Product|int $product
	 * @return array
	 */
	public function get_variable_product_all_simple_rules( $product ) {
		if ( ! $this->pricing ) {
			return array();
		}

		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return array();
		}

		$cache_key = 'var_parent_' . $product_id;
		if ( isset( $this->product_rules_cache[ $cache_key ] ) ) {
			return $this->product_rules_cache[ $cache_key ];
		}

		if ( ! is_a( $product, 'WC_Product' ) ) {
			$product = wc_get_product( $product_id );
		}

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $this->get_product_all_simple_rules( $product );
		}

		$parent_rules = $this->get_product_all_simple_rules( $product );

		// Fast-path: Check if any rules in the store even target variations
		$index = $this->get_uncacheable_variation_rules_index();
		$sched = $this->get_scheduled_variation_rules_index();

		if ( ! $index['has_uncacheable_variation_rules'] && ! $sched['has_scheduled_variation_rules'] ) {
			// FAST PATH: Zero variation-targeting rules in the store!
			$this->product_rules_cache[ $cache_key ] = $parent_rules;
			return $parent_rules;
		}

		$all_rules      = array();
		$seen_rule_keys = array();

		if ( ! empty( $parent_rules ) ) {
			foreach ( $parent_rules as $rule ) {
				$rkey = isset( $rule->id ) ? (string) $rule->id : spl_object_hash( (object) $rule );
				if ( ! isset( $seen_rule_keys[ $rkey ] ) ) {
					$seen_rule_keys[ $rkey ] = true;
					$all_rules[]             = $rule;
				}
			}
		}

		// Only loop children if this specific product might be affected
		if (
			$index['all_variable_affected'] ||
			! empty( $index['targeted_attributes'] ) ||
			isset( $index['uncacheable_parent_ids'][ $product_id ] ) ||
			! empty( $sched['all_variable_affected'] ) ||
			! empty( $sched['targeted_attributes'] ) ||
			isset( $sched['scheduled_parent_ids'][ $product_id ] )
		) {
			$children = $product->get_children();
			if ( ! empty( $children ) && is_array( $children ) ) {
				foreach ( $children as $child_id ) {
					$child_id = (int) $child_id;
					if ( $child_id <= 0 ) {
						continue;
					}

					$var_cache_key = $product_id . '_' . $child_id;
					if ( isset( $this->product_rules_cache[ $var_cache_key ] ) ) {
						$child_rules = $this->product_rules_cache[ $var_cache_key ];
					} else {
						$child_rules = $this->pricing->get_product_simple_rules( $product_id, $child_id, false );
						$this->product_rules_cache[ $var_cache_key ] = $child_rules;
					}

					if ( ! empty( $child_rules ) ) {
						foreach ( $child_rules as $rule ) {
							$rkey = isset( $rule->id ) ? (string) $rule->id : spl_object_hash( (object) $rule );
							if ( ! isset( $seen_rule_keys[ $rkey ] ) ) {
								$seen_rule_keys[ $rkey ] = true;
								$all_rules[]             = $rule;
							}
						}
					}
				}
			}
		}

		$this->product_rules_cache[ $cache_key ] = $all_rules;

		return $all_rules;
	}

	public function is_cache_enabled() {
		if ( null === $this->cache_enabled ) {
			$this->cache_enabled = (int) WCCS()->settings->get_setting( 'cache_prices', 1 );
		}
		return 1 <= $this->cache_enabled;
	}

	public function get_product_all_simple_rules( $product ) {
		if ( ! $this->pricing ) {
			return array();
		}

		$target       = WCCS()->product_helpers->get_product_target( $product );
		$parent_id    = $target['parent_id'];
		$variation_id = $target['variation_id'];

		$cache_key = $parent_id . '_' . $variation_id;
		if ( isset( $this->product_rules_cache[ $cache_key ] ) ) {
			return $this->product_rules_cache[ $cache_key ];
		}

		$rules = $this->pricing->get_product_simple_rules( $parent_id, $variation_id, false );
		$this->product_rules_cache[ $cache_key ] = $rules;

		return $rules;
	}

	public function get_product_all_bulk_rules( $product ) {
		if ( ! $this->pricing ) {
			return array();
		}

		$target       = WCCS()->product_helpers->get_product_target( $product );
		$parent_id    = $target['parent_id'];
		$variation_id = $target['variation_id'];

		$cache_key = 'bulk_' . $parent_id . '_' . $variation_id;
		if ( isset( $this->product_rules_cache[ $cache_key ] ) ) {
			return $this->product_rules_cache[ $cache_key ];
		}

		$rules = $this->pricing->get_product_bulk_rules( $parent_id, $variation_id, false );

		$this->product_rules_cache[ $cache_key ] = $rules;

		return $rules;
	}

}
