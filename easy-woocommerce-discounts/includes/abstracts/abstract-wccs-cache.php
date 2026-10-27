<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Abstract_Cache {

    protected $cache_prefix;
    protected $cache_group;
    protected $type;
    protected $product_gen_prefix;
    protected $default_ttl = null;

    /**
     * Pricing service instance.
     *
     * @var WCCS_Pricing|null
     */
    protected $pricing = null;

    /**
     * Local memory cache for current request.
     *
     * @var array
     */
    protected $local_cache = array();

    /**
     * Memoized product cache generation salts per request.
     *
     * @var array<int, string>
     */
    protected $product_gens = array();

    /**
     * Memoized DB cache rows for the current request.
     *
     * @var array<int, array>
     */
    protected $db_cache_rows = array();

    /**
     * Memoized DB cache row primary key IDs for the current request.
     *
     * @var array<int, int>
     */
    protected $db_cache_row_ids = array();

    /**
     * Memoized cache enabled status.
     *
     * @var int|bool|null
     */
    protected $cache_enabled = null;

    /**
     * Whether batch write mode is currently active.
     *
     * @var bool
     */
    protected $is_batching = false;

    /**
     * Buffer of pending rows to insert/update in bulk.
     *
     * @var array
     */
    protected $batch_buffer = array();

    public function __construct( $cache_prefix, $cache_group, $type = '', $product_gen_prefix = '' ) {
        $this->cache_prefix       = (string) $cache_prefix;
        $this->cache_group        = (string) $cache_group;
        $this->type               = (string) $type;
        $this->product_gen_prefix = (string) $product_gen_prefix;

        // On web/frontend, AJAX, and REST requests, enable batch write mode by default so all DB cache writes
        // buffer in RAM and flush in a single multi-row query on shutdown.
        $is_ajax = function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
        $is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
        $is_cli  = ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === php_sapi_name();

        if ( ( ! is_admin() || $is_ajax || $is_rest ) && ! $is_cli ) {
            $this->is_batching = true;
        }

        register_shutdown_function( array( $this, 'commit_batch' ) );
    }

    public function get_cache_prefix() {
        return $this->cache_prefix;
    }

    public function get_cache_group() {
        return $this->cache_group;
    }

    public function get_type() {
        return $this->type;
    }

    public function get_product_gen_prefix() {
        return $this->product_gen_prefix;
    }

    public function get_cache_generation() {
        return WCCS_Clear_Cache::get_generation();
    }

    /**
     * Get visitor cache segment based on user roles.
     *
     * @return string
     */
    public function get_user_segment() {
        return WCCS_Rules_Helpers::get_user_segment();
    }

    /**
     * Get default cache duration TTL in seconds.
     *
     * @return int TTL in seconds (0 for no expiration).
     */
    public function get_default_ttl() {
        if ( null === $this->default_ttl ) {
            $setting = WCCS()->settings->get_setting( 'cache_duration', 43200 );
            if ( null === $setting || '' === $setting ) {
                $setting = 43200; // Best default: 30 days (43,200 minutes / 2,592,000 seconds).
            }
            $this->default_ttl = (int) $setting * 60;
        }
        return $this->default_ttl;
    }

    /**
     * Check if cache is enabled.
     *
     * @return bool
     */
    public function is_cache_enabled() {
        return null !== $this->cache_enabled ? ( 1 <= (int) $this->cache_enabled ) : true;
    }

    /**
     * Set cache enabled flag.
     *
     * @param int|bool $enabled
     * @return void
     */
    public function set_cache_enabled( $enabled ) {
        $this->cache_enabled = (int) $enabled;
    }

    /**
     * Flush the object cache group if supported by persistent backend.
     *
     * @return void
     */
    protected function flush_cache_group() {
        if ( wp_cache_supports( 'flush_group' ) ) {
            wp_cache_flush_group( $this->get_cache_group() );
        }
    }

    /**
     * Clear common in-memory request cache state.
     *
     * @return void
     */
    public function clear_local_cache() {
        $this->local_cache      = array();
        $this->product_gens     = array();
        $this->db_cache_rows    = array();
        $this->db_cache_row_ids = array();
        $this->batch_buffer     = array();
    }

    /**
     * Invalidate local memory and flush object cache group.
     *
     * @return void
     */
    public function invalidate_cache() {
        $this->clear_local_cache();
        $this->flush_cache_group();
    }

    /**
     * Clear all persistent and memory caches for this type.
     *
     * @return void
     */
    public function clear_cache() {
        if ( ! empty( $this->type ) ) {
            WCCS()->WCCS_DB_Cache->delete_items_by_type( $this->type );
        }

        $this->invalidate_cache();
    }



    /**
     * Legacy transient cleanup (maintained for backwards compatibility with updater routine).
     *
     * @return void
     */
    public function clear_cache_deprecated() {
        $this->delete_transient_group();
    }

    /**
     * Delete transient group options from the options table.
     *
     * @return void
     */
    public function delete_transient_group() {
        if ( wp_using_ext_object_cache() ) {
            return;
        }

        do_action( 'wccs_cache_' . __FUNCTION__, $this->cache_group, $this->cache_prefix );

        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_' . $this->cache_group ) . '%',
                $wpdb->esc_like( '_transient_timeout_' . $this->cache_group ) . '%',
                $wpdb->esc_like( '_transient_' . $this->cache_group . '-transient-version' ) . '%'
            )
        );
    }

    /**
     * Get product-specific cache generation counter/salt.
     * Memoized per request to eliminate duplicate wp_cache_get calls during catalog loops.
     *
     * @param int $product_id
     * @return string
     */
    public function get_product_cache_generation( $product_id ) {
        $product_id = (int) $product_id;
        if ( isset( $this->product_gens[ $product_id ] ) ) {
            return $this->product_gens[ $product_id ];
        }

        $global_gen = $this->get_cache_generation();

        // When external object cache is not in use or no prefix configured,
        // product generation always matches global generation (zero memory & zero query overhead).
        if ( empty( $this->product_gen_prefix ) || ! wp_using_ext_object_cache() ) {
            return $this->product_gens[ $product_id ] = (string) $global_gen;
        }

        $pgen = wp_cache_get( $this->product_gen_prefix . $product_id, $this->get_cache_group() );
        $gen  = ( false === $pgen ) ? (string) $global_gen : $global_gen . '_' . $pgen;

        return $this->product_gens[ $product_id ] = $gen;
    }

    /**
     * Prime Layer 2 DB cache in a single batch query for multiple product IDs.
     * Eliminates N+1 queries on archive pages.
     *
     * @param array $product_ids
     * @return void
     */
    public function prime_products_db_cache( array $product_ids ) {
        if ( ! $this->is_cache_enabled() || wp_using_ext_object_cache() || empty( $product_ids ) || empty( $this->type ) ) {
            return;
        }

        $to_load = array();
        foreach ( $product_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 && ! isset( $this->db_cache_rows[ $pid ] ) ) {
                $to_load[]                      = $pid;
                $this->db_cache_rows[ $pid ]    = array();
                $this->db_cache_row_ids[ $pid ] = 0;
            }
        }

        if ( empty( $to_load ) ) {
            return;
        }

        $items = WCCS()->WCCS_DB_Cache->get_items_by_products( $to_load, $this->type );
        if ( ! empty( $items ) ) {
            foreach ( $items as $item ) {
                $pid = (int) $item->product_id;
                $this->db_cache_rows[ $pid ]    = ( ! empty( $item->value ) && is_array( $item->value ) ) ? $item->value : array();
                $this->db_cache_row_ids[ $pid ] = (int) $item->id;
            }
        }
    }

    /**
     * Retrieve a cached entry from Layer 2 DB Cache.
     *
     * @param int    $product_id
     * @param string $cache_key
     * @return mixed|false Value if valid and unexpired, false otherwise.
     */
    public function get_db_cache_entry( $product_id, $cache_key ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 || empty( $this->type ) ) {
            return false;
        }

        if ( ! isset( $this->db_cache_rows[ $product_id ] ) ) {
            $item = WCCS()->WCCS_DB_Cache->get_item_by_product( $product_id, $this->type );
            $this->db_cache_rows[ $product_id ]    = ( $item && ! empty( $item->value ) && is_array( $item->value ) ) ? $item->value : array();
            $this->db_cache_row_ids[ $product_id ] = ( $item && isset( $item->id ) ) ? (int) $item->id : 0;
        }

        if ( isset( $this->db_cache_rows[ $product_id ][ $cache_key ] ) ) {
            $entry = $this->db_cache_rows[ $product_id ][ $cache_key ];
            if ( is_array( $entry ) && isset( $entry['val'] ) ) {
                if ( empty( $entry['exp'] ) || (int) $entry['exp'] > time() ) {
                    return $entry['val'];
                }
                unset( $this->db_cache_rows[ $product_id ][ $cache_key ] );
            } elseif ( ! is_array( $entry ) ) {
                return $entry;
            }
        }

        return false;
    }

    /**
     * Store a value into Layer 2 DB Cache.
     *
     * Compulsorily prunes expired keys to bound serialized payload size
     * and calculates row-level expires_at for fast MySQL index sweeps.
     *
     * @param int    $product_id
     * @param string $cache_key
     * @param mixed  $value
     * @param int    $ttl
     * @return bool
     */
    public function set_db_cache_entry( $product_id, $cache_key, $value, $ttl = 0 ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 || empty( $cache_key ) || empty( $this->type ) ) {
            return false;
        }

        if ( ! isset( $this->db_cache_rows[ $product_id ] ) ) {
            $db_cache = WCCS()->WCCS_DB_Cache->get_item_by_product( $product_id, $this->type );
            if ( $db_cache && isset( $db_cache->id ) ) {
                $this->db_cache_row_ids[ $product_id ] = (int) $db_cache->id;
                $this->db_cache_rows[ $product_id ]    = ( ! empty( $db_cache->value ) && is_array( $db_cache->value ) ) ? $db_cache->value : array();
            } else {
                $this->db_cache_row_ids[ $product_id ] = 0;
                $this->db_cache_rows[ $product_id ]    = array();
            }
        }

        $data = $this->db_cache_rows[ $product_id ];
        $now  = time();

        // Tier 2: Array Compaction on Write - Prune expired keys to strictly bound serialized blob size
        foreach ( $data as $k => $entry ) {
            if ( is_array( $entry ) && ! empty( $entry['exp'] ) && (int) $entry['exp'] <= $now ) {
                unset( $data[ $k ] );
            }
        }

        $data[ $cache_key ] = array(
            'val' => null === $value ? 'null' : $value,
            'exp' => 0 !== (int) $ttl ? $now + (int) $ttl : 0,
        );

        // Tier 3: Calculate row-level expires_at for O(log N) MySQL range sweeps
        $has_non_expiring = false;
        $max_exp          = 0;
        foreach ( $data as $entry ) {
            if ( is_array( $entry ) ) {
                if ( empty( $entry['exp'] ) ) {
                    $has_non_expiring = true;
                } else {
                    $max_exp = max( $max_exp, (int) $entry['exp'] );
                }
            }
        }
        $row_expires_at                     = $has_non_expiring ? 0 : $max_exp;
        $this->db_cache_rows[ $product_id ] = $data;

        if ( $this->is_batching ) {
            $this->batch_buffer[ $product_id ] = array(
                'product_id' => $product_id,
                'cache_type' => $this->type,
                'value'      => $data,
                'expires_at' => $row_expires_at,
            );
            return true;
        }

        $saved = WCCS()->WCCS_DB_Cache->set_cache( $product_id, $this->type, $data, $row_expires_at );
        return false !== $saved;
    }

    /**
     * Start batch write mode. All subsequent set_db_cache_entry calls are buffered in RAM.
     *
     * @return void
     */
    public function start_batch() {
        $this->is_batching = true;
    }

    /**
     * Stop batch write mode without committing.
     *
     * @return void
     */
    public function stop_batch() {
        $this->is_batching = false;
    }

    /**
     * Check if batch write mode is currently active.
     *
     * @return bool
     */
    public function is_batching() {
        return (bool) $this->is_batching;
    }

    /**
     * Commit all buffered DB cache entries in a single atomic SQL query.
     * Drains the buffer without disabling batch mode, allowing subsequent writes to keep batching.
     *
     * @return bool|int
     */
    public function commit_batch() {
        if ( empty( $this->batch_buffer ) ) {
            return true;
        }

        $buffer             = $this->batch_buffer;
        $this->batch_buffer = array();

        return WCCS()->WCCS_DB_Cache->set_caches( array_values( $buffer ) );
    }

    /**
     * Delete in-memory and local cache entries for a single product ID.
     *
     * @param int $product_id
     * @return void
     */
    protected function delete_product_local_cache( $product_id ) {
        unset(
            $this->db_cache_rows[ $product_id ],
            $this->db_cache_row_ids[ $product_id ],
            $this->product_gens[ $product_id ],
            $this->local_cache[ $product_id ],
            $this->batch_buffer[ $product_id ]
        );
    }

    /**
     * Delete single product cache dynamically across DB and object cache.
     * Cascades invalidation to parent variable product or child variations with zero redundant queries.
     *
     * @param int $product_id
     * @return bool
     */
    public function delete_product_cache( $product_id ) {
        $product_id = absint( $product_id );
        if ( $product_id <= 0 ) {
            return false;
        }

        $ids_to_clear = array( $product_id );

        // If variation, resolve parent ID directly without hydrating full WC_Product object
        $parent_id = wp_get_post_parent_id( $product_id );
        if ( $parent_id > 0 ) {
            $ids_to_clear[] = (int) $parent_id;
        } else {
            // Top-level product: inspect if variable product with children
            $product = wc_get_product( $product_id );
            if ( $product && $product->is_type( 'variable' ) ) {
                $children = $product->get_children();
                if ( ! empty( $children ) ) {
                    foreach ( $children as $child_id ) {
                        $ids_to_clear[] = (int) $child_id;
                    }
                }
            }
        }

        $ids_to_clear  = array_values( array_unique( $ids_to_clear ) );
        $use_ext_cache = wp_using_ext_object_cache();
        $ttl           = ( ! empty( $this->product_gen_prefix ) && $use_ext_cache ) ? max( 1, $this->get_default_ttl() ) : 0;
        $stamp         = ( ! empty( $this->product_gen_prefix ) && $use_ext_cache ) ? time() : 0;

        foreach ( $ids_to_clear as $id ) {
            $this->delete_product_local_cache( $id );

            // Object cache salt bump is only executed when persistent external object cache (Redis/Memcached) is active
            if ( $use_ext_cache && ! empty( $this->product_gen_prefix ) ) {
                wp_cache_set( $this->product_gen_prefix . $id, $stamp, $this->get_cache_group(), $ttl );
            }
        }

        if ( ! empty( $this->type ) ) {
            WCCS()->WCCS_DB_Cache->delete_items_by_products( $ids_to_clear, $this->type );
        }

        return true;
    }

}
