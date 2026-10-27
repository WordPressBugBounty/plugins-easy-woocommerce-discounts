<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Clear_Cache {

    const GENERATION_OPTION = 'wccs_price_cache_generation';

    /**
     * Cache generation property memoized per request.
     *
     * @var int|null
     */
    protected static $generation = null;

    /**
     * Track products whose cache has already been deleted in the current request.
     * Prevents duplicate DB queries and child variation iterations across multiple WooCommerce hooks.
     *
     * @var array
     */
    protected static $deleted_products = array();

    /**
     * Track whether full pricing caches have already been cleared in the current request.
     * Prevents duplicate table truncations and generation bumps within a single request lifecycle.
     *
     * @var bool
     */
    protected static $pricing_caches_cleared = false;

    /**
     * Enable hooks for automatic cache invalidation on product/term/settings CRUD.
     *
     * @return void
     */
    public static function enable_hooks() {
        static $enabled = false;
        if ( $enabled ) {
            return;
        }
        $enabled = true;

        // WooCommerce Product CRUD Hooks (Lifecycle, Stock, Variations, Duplication)
        add_action( 'woocommerce_update_product', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_delete_product', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_trash_product', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_untrash_product', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_delete_product_transients', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_product_set_stock', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_variation_set_stock', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_ajax_save_product_variations', array( __CLASS__, 'delete_product_cache' ) );
        add_action( 'woocommerce_product_duplicate', array( __CLASS__, 'delete_product_cache' ) );

        add_action( 'asnp_wccs_daily_cache_cleanup', array( __CLASS__, 'purge_expired_db_cache' ) );

        if ( ! wp_next_scheduled( 'asnp_wccs_daily_cache_cleanup' ) ) {
            $hour = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
            wp_schedule_event( time() + $hour, 'daily', 'asnp_wccs_daily_cache_cleanup' );
        }

        // WP Post CRUD Hooks (filtered to product and variation post types, ignoring autosaves & revisions)
        add_action( 'save_post_product', array( __CLASS__, 'on_product_post_save' ) );
        add_action( 'save_post_product_variation', array( __CLASS__, 'on_product_post_save' ) );
        add_action( 'before_delete_post', array( __CLASS__, 'on_product_post_delete' ) );
        add_action( 'trashed_post', array( __CLASS__, 'on_product_post_delete' ) );
        add_action( 'untrashed_post', array( __CLASS__, 'on_product_post_save' ) );

        // Product Category & Tag Taxonomy Term CRUD Hooks
        add_action( 'created_product_cat', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'edited_product_cat', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'delete_product_cat', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'created_product_tag', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'edited_product_tag', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'delete_product_tag', array( __CLASS__, 'clear_pricing_caches' ) );

        // WooCommerce Product Attribute Taxonomy CRUD Hooks (Products > Attributes)
        add_action( 'woocommerce_attribute_added', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'woocommerce_attribute_updated', array( __CLASS__, 'clear_pricing_caches' ) );
        add_action( 'woocommerce_attribute_deleted', array( __CLASS__, 'clear_pricing_caches' ) );

        // Taxonomy Term CRUD Hooks (Unified for product_cat, product_tag, pa_* attributes, and custom brand taxonomies)
        add_action( 'create_term', array( __CLASS__, 'on_term_changed' ), 10, 3 );
        add_action( 'edit_term', array( __CLASS__, 'on_term_changed' ), 10, 3 );
        add_action( 'delete_term', array( __CLASS__, 'on_term_changed' ), 10, 3 );

        // Term Assignment Hook (when product_cat, product_tag, or pa_* changes on a product or variation)
        add_action( 'set_object_terms', array( __CLASS__, 'on_product_terms_changed' ), 10, 4 );

        // WooCommerce Settings Saved Hook
        add_action( 'woocommerce_settings_saved', array( __CLASS__, 'clear_pricing_caches' ) );
    }

    /**
     * Get current price cache generation counter.
     * Memoized per request for maximum performance.
     *
     * @return int
     */
    public static function get_generation() {
        if ( null !== self::$generation ) {
            return self::$generation;
        }

        $gen = (int) get_option( self::GENERATION_OPTION, 1 );
        self::$generation = $gen > 0 ? $gen : 1;

        return self::$generation;
    }

    /**
     * Bump price cache generation counter to instantly invalidate all cached product price & onsale keys site-wide.
     *
     * @return int New generation value
     */
    public static function bump_generation() {
        $next = self::get_generation() + 1;
        update_option( self::GENERATION_OPTION, $next, true );

        self::$generation = $next;

        return $next;
    }

    /**
     * Clear all pricing caches site-wide.
     *
     * Logical invalidation via bump_generation() occurs in O(1) time site-wide,
     * ensuring that no shopper is ever served stale prices.
     * Physical cache purge is handled safely without throwing DDL lock errors.
     * Idempotent per request to prevent redundant DB writes.
     *
     * @param  bool $force Whether to bypass request-level deduplication.
     * @return void
     */
    public static function clear_pricing_caches( $force = false ) {
        if ( ! $force && self::$pricing_caches_cleared ) {
            return;
        }
        self::$pricing_caches_cleared = true;

        self::bump_generation();
        self::$deleted_products = array();

        $wccs = WCCS();

        // Safe physical table purge (graceful fallback if TRUNCATE is restricted)
        $wccs->WCCS_DB_Cache->clear_cache();

        // Invalidate in-memory and object cache groups cleanly
        $wccs->WCCS_Product_Price_Cache->clear_cache();
        $wccs->WCCS_Product_Onsale_Cache->clear_cache();
        $wccs->WCCS_Product_Badge_Cache->clear_cache();
        $wccs->products->clear_discounted_products_cache();
        WCCS_Helpers::reset_display_price_cache();
        do_action( 'wccs_pricing_caches_cleared' );
    }

    /**
     * Asynchronously purge expired Layer 2 DB cache rows in non-blocking batches.
     *
     * Hooked into wc_scheduled_sales and daily cron.
     *
     * @return int
     */
    public static function purge_expired_db_cache() {
        return WCCS()->WCCS_DB_Cache->purge_expired( 1000 );
    }

    /**
     * Clear a specific product's cache.
     *
     * Accepts either a WC_Product object or an integer product ID.
     * Deduplicates multiple hook executions within the same request.
     *
     * @param  WC_Product|int $product Product instance or ID.
     * @param  bool           $force   Whether to bypass request-level deduplication.
     * @return void
     */
    public static function delete_product_cache( $product, $force = false ) {
        if ( is_a( $product, 'WC_Product' ) ) {
            $product_id = $product->get_id();
        } elseif ( is_numeric( $product ) ) {
            $product_id = absint( $product );
        } else {
            $product_id = 0;
        }

        if ( $product_id <= 0 ) {
            self::clear_pricing_caches( $force );
            return;
        }

        // Deduplicate multiple sequential hook fires for the same product in a single request
        if ( ! $force && isset( self::$deleted_products[ $product_id ] ) ) {
            return;
        }
        self::$deleted_products[ $product_id ] = true;

        $wccs = WCCS();

        // Invalidate price cache & on-sale cache for this product.
        // Each cache class encapsulates its own in-memory cache, object cache generation,
        // Layer 2 DB cache rows, and parent/children relationships without duplicate queries.
        $wccs->WCCS_Product_Price_Cache->delete_product_cache( $product_id );
        $wccs->WCCS_Product_Onsale_Cache->delete_product_cache( $product_id );
        $wccs->WCCS_Product_Badge_Cache->delete_product_cache( $product_id );

        // If product is a variation, mark its parent as processed in request deduplication
        $parent_id = wp_get_post_parent_id( $product_id );
        if ( $parent_id > 0 ) {
            self::$deleted_products[ $parent_id ] = true;
        }

        // Invalidate discounted products transient list
        $wccs->products->clear_discounted_products_cache();

        do_action( 'wccs_product_cache_deleted', $product_id );
    }

    /**
     * Reset the deleted products tracking cache and generation states (useful for long-running processes/tests).
     *
     * @return void
     */
    public static function reset_deleted_products() {
        self::$deleted_products = array();
    }

    /**
     * Complete cache state reset for testing or worker environments.
     *
     * @return void
     */
    public static function reset_cache_state() {
        self::$generation             = null;
        self::$deleted_products       = array();
        self::$pricing_caches_cleared = false;
    }

    /**
     * Invalidate on product post save, ignoring autosaves and revisions.
     *
     * @param int $post_id
     */
    public static function on_product_post_save( $post_id ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        $post_type = get_post_type( $post_id );
        if ( 'product' === $post_type || 'product_variation' === $post_type ) {
            self::delete_product_cache( $post_id );
        }
    }

    /**
     * Invalidate on product post delete or trash.
     *
     * @param int $post_id
     */
    public static function on_product_post_delete( $post_id ) {
        $post_type = get_post_type( $post_id );
        if ( 'product' === $post_type || 'product_variation' === $post_type ) {
            self::delete_product_cache( $post_id );
        }
    }

    /**
     * Invalidate when product category, tag, or attribute term is added/edited/deleted.
     * Excludes product_cat and product_tag since they have dedicated explicit hooks.
     *
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     */
    public static function on_term_changed( $term_id, $tt_id, $taxonomy ) {
        // Exclude product_cat and product_tag since they are handled by explicit dedicated hooks
        if ( in_array( $taxonomy, array( 'product_cat', 'product_tag' ), true ) ) {
            return;
        }

        if ( self::is_product_taxonomy( $taxonomy ) ) {
            self::clear_pricing_caches();
        }
    }

    /**
     * Invalidate when product category, tag, or attribute relationships change on a product or variation.
     *
     * @param int    $object_id
     * @param array  $terms
     * @param array  $tt_ids
     * @param string $taxonomy
     */
    public static function on_product_terms_changed( $object_id, $terms, $tt_ids, $taxonomy ) {
        if ( self::is_product_taxonomy( $taxonomy ) ) {
            $post_type = get_post_type( $object_id );
            if ( 'product' === $post_type || 'product_variation' === $post_type ) {
                self::delete_product_cache( $object_id );
            }
        }
    }

    /**
     * Check whether a taxonomy is a product category, tag, brand, attribute (pa_*), or custom product taxonomy.
     *
     * @param string $taxonomy
     * @return bool
     */
    public static function is_product_taxonomy( $taxonomy ) {
        if ( empty( $taxonomy ) || ! is_string( $taxonomy ) ) {
            return false;
        }

        // Standard WooCommerce product taxonomies and popular brand taxonomies
        if ( in_array( $taxonomy, array( 'product_cat', 'product_tag', 'product_brand', 'pwb-brand', 'yith_product_brand', 'product_shipping_class', 'product_type' ), true ) ) {
            return true;
        }

        // Prefix/substring matches for custom product attributes (pa_*) and product taxonomies (product_*, *brand*)
        if ( 0 === strpos( $taxonomy, 'pa_' ) || 0 === strpos( $taxonomy, 'product_' ) || false !== strpos( $taxonomy, 'brand' ) ) {
            return true;
        }

        // WooCommerce core product attribute check
        if ( taxonomy_is_product_attribute( $taxonomy ) ) {
            return true;
        }

        // Dynamic WP check for any taxonomy associated with the 'product' post type
        static $product_taxonomies = null;
        if ( null === $product_taxonomies ) {
            $product_taxonomies = get_object_taxonomies( 'product', 'names' );
        }
        if ( ! empty( $product_taxonomies ) && in_array( $taxonomy, $product_taxonomies, true ) ) {
            return true;
        }

        return false;
    }

}
