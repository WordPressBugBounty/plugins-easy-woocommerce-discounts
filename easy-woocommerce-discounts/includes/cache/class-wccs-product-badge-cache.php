<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCCS_Product_Badge_Cache
 *
 * Handles multi-tiered caching for product percentage discount badges.
 *
 * Tiers:
 * 1. Process Memory (L1): Fast in-memory array per request (0.0001 ms).
 * 2. Persistent Object Cache (L2): Redis / Memcached / RAM via wp_cache_* (< 0.05 ms).
 * 3. Persistent Database Cache (L2b): Custom wccs_cache table when external object cache is unavailable.
 *
 * Features:
 * - Negative caching: Non-sale products cached as 'none' to avoid redundant variation tree evaluations.
 * - Batch priming: prime_products_db_cache() eliminates N+1 queries on archive pages.
 * - Dynamic rule safety: Prevents uncacheable cart-dependent rules from poisoning cache.
 * - Expiry bounds: Automatically limits TTL to scheduled rule start/end dates.
 * - Atomic invalidation: Cascades to parent/child variations and bumps cache generation salts.
 *
 * @since 9.12.0
 */
class WCCS_Product_Badge_Cache extends WCCS_Abstract_Cache {

	const TYPE               = 'badge';
	const CACHE_GROUP        = 'asnp_wccs_product_badge';
	const PRODUCT_GEN_PREFIX = 'asnp_wccs_badge_pgen_';

	/**
	 * Memoized percentage badge enabled status.
	 *
	 * @var bool|null
	 */
	protected $badge_enabled = null;

	/**
	 * Constructor.
	 *
	 * @param WCCS_Pricing|null $pricing Pricing instance.
	 */
	public function __construct( $pricing = null ) {
		$this->pricing = null === $pricing ? WCCS()->pricing : $pricing;
		parent::__construct( 'wccs_product_badge_', self::CACHE_GROUP, self::TYPE, self::PRODUCT_GEN_PREFIX );
	}

	/**
	 * Check if percentage badge is enabled in plugin settings.
	 *
	 * The plugin has two mutually exclusive badge types for simple pricing:
	 * 1. Sale badge ('sale_badge_type' => 'sale'): displays "Sale" inside the badge.
	 * 2. Percentage badge ('sale_badge_type' => 'discount'): displays discount percentage inside the badge.
	 *
	 * Percentage badge ONLY works when explicitly enabled in plugin settings:
	 * - 'sale_badge' setting must include 'simple'.
	 * - 'sale_badge_type' setting must be 'discount'.
	 *
	 * When percentage badge is enabled, the sale badge is not available.
	 * When percentage badge is not enabled, percentage badge calculation returns false immediately.
	 *
	 * @return bool
	 */
	public function is_percentage_badge_enabled() {
		if ( null !== $this->badge_enabled ) {
			return $this->badge_enabled;
		}

		$sale_badge = WCCS()->settings->get_setting( 'sale_badge', array( 'simple' => '1' ) );
		if ( empty( $sale_badge ) || ! is_array( $sale_badge ) || empty( $sale_badge['simple'] ) ) {
			$this->badge_enabled = false;
			return apply_filters( 'wccs_percentage_badge_enabled', false );
		}

		$badge_type = WCCS()->settings->get_setting( 'sale_badge_type', 'sale' );
		$this->badge_enabled = ( 'discount' === $badge_type );

		return apply_filters( 'wccs_percentage_badge_enabled', $this->badge_enabled );
	}

	/**
	 * Check if percentage badge caching is enabled.
	 *
	 * @return bool
	 */
	public function is_cache_enabled() {
		if ( ! $this->is_percentage_badge_enabled() ) {
			return false;
		}

		if ( null === $this->cache_enabled ) {
			$this->cache_enabled = (int) WCCS()->settings->get_setting( 'cache_onsale_badge', 1 );
		}

		return 1 <= $this->cache_enabled;
	}

	/**
	 * Build deterministic cache key for percentage badge value.
	 *
	 * @param int             $product_id Product ID.
	 * @param WC_Product|null $product    Product object.
	 * @return string
	 */
	public function build_cache_key( $product_id, $product = null ) {
		$product_id     = (int) $product_id;
		$segment        = $this->get_user_segment();
		$pgen           = $this->get_product_cache_generation( $product_id );
		$pricing_method = WCCS()->settings->get_setting( 'change_price_method', 'lightweight' );
		$currency       = get_woocommerce_currency();

		$parts = array( 'wccs_bdg', $product_id, $segment, $pgen, $pricing_method, $currency );
		$parts = apply_filters( 'asnp_wccs_product_badge_cache_key_parts', $parts, $product_id, $product );

		return md5( implode( '_', $parts ) );
	}

	/**
	 * Get cache duration TTL in seconds for product badge.
	 *
	 * Scoped strictly to simple pricing rules targeting the given product.
	 * If no simple rules target the product, returns default TTL with zero store-wide rule scanning.
	 *
	 * @param WC_Product|int|null $product Product object or ID.
	 * @return int TTL in seconds (0 for no expiration).
	 */
	public function get_badge_ttl( $product = null ) {
		$ttl = $this->get_default_ttl();

		if ( ! $product ) {
			return apply_filters( 'wccs_badge_cache_ttl', $ttl, $product );
		}

		$pricing = $this->pricing ? $this->pricing : WCCS()->pricing;
		if ( $pricing && $pricing->has_simple_rules() ) {
			$product_obj = is_a( $product, 'WC_Product' ) ? $product : wc_get_product( $product );
			if ( $product_obj ) {
				$rules = $product_obj->is_type( 'variable' )
					? WCCS()->WCCS_Product_Price_Cache->get_variable_product_all_simple_rules( $product_obj )
					: WCCS()->WCCS_Product_Price_Cache->get_product_all_simple_rules( $product_obj );

				if ( ! empty( $rules ) ) {
					$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $rules );
					if ( $time_expiry > 0 ) {
						$ttl = ( $ttl > 0 ) ? min( $ttl, $time_expiry ) : $time_expiry;
					}
				}
			}
		}

		return apply_filters( 'wccs_badge_cache_ttl', $ttl, $product );
	}

	/**
	 * Check if candidate discount rules for product are cacheable across visitors.
	 *
	 * Since percentage badges only apply to simple pricing rules, we only evaluate simple rules.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool
	 */
	public function can_cache_badge( $product ) {
		$pricing = $this->pricing ? $this->pricing : WCCS()->pricing;
		if ( ! $pricing || ! $pricing->has_simple_rules() ) {
			return true;
		}

		if ( $product->is_type( 'variable' ) ) {
			return WCCS()->WCCS_Product_Price_Cache->can_cache_variation_prices( $product );
		}

		$rules = WCCS()->WCCS_Product_Price_Cache->get_product_all_simple_rules( $product );
		if ( ! empty( $rules ) ) {
			return WCCS_Rules_Helpers::can_cache_rules( $rules, true );
		}

		return true;
	}

	/**
	 * Get the percentage badge value for a product with multi-tiered caching.
	 *
	 * Percentage badge ONLY works when explicitly enabled from plugin settings.
	 *
	 * @param WC_Product|int $product Product instance or ID.
	 * @return float|false Percentage discount value or false if not discounted or disabled.
	 */
	public function get_badge_value( $product ) {
		// Circuit-breaker: Percentage badge ONLY works when enabled from plugin settings
		if ( ! $this->is_percentage_badge_enabled() ) {
			return false;
		}

		$product_id = is_numeric( $product ) ? (int) $product : ( $product ? (int) $product->get_id() : 0 );
		if ( $product_id <= 0 ) {
			return false;
		}

		// 1. Fast local process memory check (0.0001 ms)
		if ( isset( $this->local_cache[ $product_id ] ) ) {
			return $this->local_cache[ $product_id ];
		}

		$product = is_numeric( $product ) ? wc_get_product( $product_id ) : $product;
		if ( ! $product ) {
			return $this->local_cache[ $product_id ] = false;
		}

		$cache_key     = $this->build_cache_key( $product_id, $product );
		$cache_enabled = $this->is_cache_enabled();

		// 2. Persistent Object Cache (Redis/Memcached) fast-path
		if ( $cache_enabled ) {
			$cached = wp_cache_get( $cache_key, $this->get_cache_group() );
			if ( false !== $cached ) {
				$val = ( 'none' === $cached ) ? false : (float) $cached;
				return $this->local_cache[ $product_id ] = $val;
			}

			// 2b. Persistent Layer 2 DB Cache check (active when external object cache like Redis is absent)
			if ( ! wp_using_ext_object_cache() ) {
				$db_cached = $this->get_db_cache_entry( $product_id, $cache_key );
				if ( false !== $db_cached ) {
					$val = ( 'none' === $db_cached ) ? false : (float) $db_cached;
					wp_cache_set( $cache_key, $db_cached, $this->get_cache_group() );
					return $this->local_cache[ $product_id ] = $val;
				}
			}
		}

		// 3. Cache Miss: Compute badge value
		$result = $this->compute_badge_value( $product );

		// 4. Store into Persistent Object Cache and Layer 2 DB Cache
		if ( $cache_enabled && $this->can_cache_badge( $product ) ) {
			$ttl          = $this->get_badge_ttl( $product );
			$val_to_cache = ( false === $result ) ? 'none' : (float) $result;

			wp_cache_set( $cache_key, $val_to_cache, $this->get_cache_group(), $ttl > 0 ? max( 1, $ttl ) : 0 );
			if ( ! wp_using_ext_object_cache() ) {
				$this->set_db_cache_entry( $product_id, $cache_key, $val_to_cache, $ttl );
			}
		}

		return $this->local_cache[ $product_id ] = $result;
	}

	/**
	 * Compute the raw percentage discount value for variable or simple products.
	 *
	 * @param WC_Product $product
	 * @return float|false
	 */
	protected function compute_badge_value( $product ) {
		$is_advanced = ( 'advanced' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' ) );

		if ( $product->is_type( 'variable' ) ) {
			$prices = $product->get_variation_prices();
			if ( empty( $prices['price'] ) || empty( $prices['regular_price'] ) ) {
				return false;
			}

			$max_percentage = 0;
			foreach ( $prices['price'] as $key => $price ) {
				$reg = isset( $prices['regular_price'][ $key ] ) ? (float) $prices['regular_price'][ $key ] : 0;
				if ( $reg > 0 && (float) $price < $reg ) {
					$percentage = ( $reg - (float) $price ) / $reg * 100;
					if ( $percentage > $max_percentage ) {
						$max_percentage = $percentage;
					}
				}
			}

			// In advanced mode, $prices already reflects plugin discounts via woocommerce_variation_prices.
			if ( $is_advanced ) {
				return ( $max_percentage > 0 ? $max_percentage : false );
			}

			// In lightweight mode: if product has NO simple on-sale rule in the plugin,
			// native variation sale percentage is the ONLY possible discount. Fast-exit without loading variations!
			if ( ! WCCS()->WCCS_Product_Onsale_Cache->is_simple_onsale( $product ) ) {
				return ( $max_percentage > 0 ? $max_percentage : false );
			}

			$optimized_ids = WCCS_Helpers::get_archive_optimized_variation_ids( $product );
			if ( false !== $optimized_ids && ! empty( $optimized_ids[0] ) ) {
				$min_var = wc_get_product( $optimized_ids[0] );
				if ( $min_var ) {
					$var_reg  = (float) $min_var->get_regular_price();
					$var_disc = WCCS_Helpers::get_product_discounted_price( $min_var );
					if ( is_numeric( $var_disc ) && $var_reg > 0 && (float) $var_disc < $var_reg && (float) $var_disc >= 0 ) {
						$pct = ( $var_reg - (float) $var_disc ) / $var_reg * 100;
						if ( $pct > $max_percentage ) {
							$max_percentage = $pct;
						}
					}
				}
				if ( isset( $optimized_ids[1] ) && $optimized_ids[1] !== $optimized_ids[0] ) {
					$max_var = wc_get_product( $optimized_ids[1] );
					if ( $max_var ) {
						$var_reg  = (float) $max_var->get_regular_price();
						$var_disc = WCCS_Helpers::get_product_discounted_price( $max_var );
						if ( is_numeric( $var_disc ) && $var_reg > 0 && (float) $var_disc < $var_reg && (float) $var_disc >= 0 ) {
							$pct = ( $var_reg - (float) $var_disc ) / $var_reg * 100;
							if ( $pct > $max_percentage ) {
								$max_percentage = $pct;
							}
						}
					}
				}
			} else {
				$discounted_prices = WCCS()->WCCS_Product_Price_Replace->get_discounted_variation_prices( $product, true );
				if ( ! empty( $discounted_prices['price'] ) && ! empty( $discounted_prices['regular_price'] ) ) {
					foreach ( $discounted_prices['price'] as $key => $price ) {
						$reg = isset( $discounted_prices['regular_price'][ $key ] ) ? (float) $discounted_prices['regular_price'][ $key ] : 0;
						if ( $reg > 0 && (float) $price < $reg ) {
							$pct = ( $reg - (float) $price ) / $reg * 100;
							if ( $pct > $max_percentage ) {
								$max_percentage = $pct;
							}
						}
					}
				}
			}

			return ( $max_percentage > 0 ? $max_percentage : false );
		}

		// Simple product fast-path: zero loops, minimal arithmetic
		$regular_price = (float) $product->get_regular_price();
		if ( $regular_price <= 0 ) {
			return false;
		}

		// Advanced mode: product price already modified by woocommerce_product_get_price hook
		if ( $is_advanced ) {
			$price = (float) $product->get_price();
			return ( $price < $regular_price ) ? ( ( $regular_price - $price ) / $regular_price * 100 ) : false;
		}

		// Lightweight mode: check native sale price
		$native_percentage = 0;
		$sale_price        = $product->get_sale_price();
		if ( '' !== (string) $sale_price && (float) $sale_price < $regular_price ) {
			$native_percentage = ( $regular_price - (float) $sale_price ) / $regular_price * 100;
		}

		// Fast circuit breaker: if product has no simple on-sale rule, return native sale discount
		if ( ! WCCS()->WCCS_Product_Onsale_Cache->is_simple_onsale( $product ) ) {
			return ( $native_percentage > 0 ? $native_percentage : false );
		}

		// Only calculate dynamic discounted price when product is confirmed on sale via plugin
		$discounted_price  = WCCS_Helpers::get_product_discounted_price( $product );
		$plugin_percentage = 0;
		if ( is_numeric( $discounted_price ) && (float) $discounted_price < $regular_price && (float) $discounted_price >= 0 ) {
			$plugin_percentage = ( $regular_price - (float) $discounted_price ) / $regular_price * 100;
		}

		$final_percentage = max( $native_percentage, $plugin_percentage );
		return ( $final_percentage > 0 ? $final_percentage : false );
	}

}
