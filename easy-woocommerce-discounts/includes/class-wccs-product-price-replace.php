<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Product_Price_Replace {

    const PRICE         = 'price';
    const REGULAR_PRICE = 'regular_price';
    const SALE_PRICE    = 'sale_price';
    const PRIORITY      = 9999;

    public $should_replace_prices = false;

    protected $change_regular_price;

    protected $read_variation_cached_prices = null;

    protected $prices = array();

    /**
     * Map of variable product IDs currently being replaced to prevent infinite recursion cycles.
     *
     * @var array<int, bool>
     */
    protected $replacing_variation_prices = array();

    public function __construct( $should_replace_prices = false, $read_variation_cached_prices = null, $change_regular_price = false ) {
        $this->should_replace_prices        = $should_replace_prices;
        $this->read_variation_cached_prices = $read_variation_cached_prices;
        $this->change_regular_price         = $change_regular_price;
    }

    public function get_filters() {
        return array(
            /* array(
                'hook'          => 'woocommerce_get_variation_prices_hash',
                'component'     => $this,
                'callback'      => 'get_variation_prices_hash',
                'priority'      => self::PRIORITY,
                'accepted_args' => 3,
            ), */
            array(
                'hook'          => 'woocommerce_product_get_price',
                'component'     => $this,
                'callback'      => 'replace_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_product_get_sale_price',
                'component'     => $this,
                'callback'      => 'replace_sale_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_product_get_regular_price',
                'component'     => $this,
                'callback'      => 'replace_regular_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_product_variation_get_price',
                'component'     => $this,
                'callback'      => 'replace_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_product_variation_get_sale_price',
                'component'     => $this,
                'callback'      => 'replace_sale_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_product_variation_get_regular_price',
                'component'     => $this,
                'callback'      => 'replace_regular_price',
                'priority'      => self::PRIORITY,
                'accepted_args' => 2,
            ),
            array(
                'hook'          => 'woocommerce_variation_prices',
                'component'     => $this,
                'callback'      => 'replace_variation_prices',
                'priority'      => 999,
                'accepted_args' => 3,
            ),
        );
    }

    public function set_should_replace_prices( $value ) {
        $this->should_replace_prices = $value;
        return $this;
    }

    public function set_change_regular_price( $value ) {
        $this->change_regular_price = $value;
        return $this;
    }

    public function enable_hooks() {
        if ( ! $this->should_replace_prices ) {
            return $this;
        }

        foreach ( $this->get_filters() as $hook ) {
            add_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'], $hook['accepted_args'] );
        }

        return $this;
    }

    public function disable_hooks() {
        if ( ! $this->should_replace_prices ) {
            return;
        }

        foreach ( $this->get_filters() as $hook ) {
            remove_filter( $hook['hook'], array( $hook['component'], $hook['callback'] ), $hook['priority'] );
        }

        return $this;
    }

    public function replace( $price, $product, $price_type, $exchange_price = true ) {
        if ( ! $this->should_replace( $price, $product, $price_type ) ) {
            return apply_filters( 'wccs_product_price_replace_replace_price', $price, $price, $product, $price_type );
        }

        $cached_price = WCCS()->WCCS_Product_Price_Cache->get_price( $product, $price, $price_type );
        if ( ! is_numeric( $cached_price ) || 0 > $cached_price ) {
            return apply_filters( 'wccs_product_price_replace_replace_price', $price, $price, $product, $price_type );
        }

        if ( $cached_price != $price && $exchange_price ) {
            $cached_price = WCCS_Helpers::maybe_exchange_price( $cached_price );
        }

        return apply_filters( 'wccs_product_price_replace_replace_price', $cached_price, $price, $product, $price_type );
    }

    public function replace_price( $price, $product, $exchange_price = true ) {
        if ( ! $product ) {
            return $price;
        }

        $product_id = $product->get_id();
        if ( isset( $this->prices[ $product_id ]['price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product_id ]['price'][ strval( $price ) ];
        }

        if ( ! isset( $this->prices[ $product_id ] ) ) {
            $this->prices[ $product_id ] = array( 'price' => array() );
        }

        return $this->maybe_cache_price(
            $product,
            $price,
            'price',
            $this->replace( $price, $product, self::PRICE, $exchange_price )
        );
    }

    public function replace_sale_price( $price, $product, $exchange_price = true ) {
        if ( ! $product ) {
            return $price;
        }

        $product_id = $product->get_id();
        if ( isset( $this->prices[ $product_id ]['sale_price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product_id ]['sale_price'][ strval( $price ) ];
        }

        if ( ! isset( $this->prices[ $product_id ] ) ) {
            $this->prices[ $product_id ] = array( 'sale_price' => array() );
        }

        return $this->maybe_cache_price(
            $product,
            $price,
            'sale_price',
            $this->replace( $price, $product, self::SALE_PRICE, $exchange_price )
        );
    }

    public function replace_regular_price( $price, $product, $exchange_price = true ) {
        if ( ! $product ) {
            return $price;
        }

        $product_id = $product->get_id();
        if ( isset( $this->prices[ $product_id ]['regular_price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product_id ]['regular_price'][ strval( $price ) ];
        }

        if ( ! isset( $this->prices[ $product_id ] ) ) {
            $this->prices[ $product_id ] = array( 'regular_price' => array() );
        }

        return $this->maybe_cache_price(
            $product,
            $price,
            'regular_price',
            ( $this->change_regular_price && ! $product->is_on_sale( 'edit' ) ) ? $this->replace( $price, $product, self::REGULAR_PRICE, $exchange_price ) : $price
        );
    }

    public function get_discounted_variation_prices( $product, $for_display = true ) {
        if ( ! is_a( $product, 'WC_Product_Variable' ) ) {
            $product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
            if ( ! $product || ! $product->is_type( 'variable' ) ) {
                return array();
            }
        }

        if ( ! WCCS()->is_request( 'frontend' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return array();
        }

        // Do not replace price till woocommerce_cart_loaded_from_session done.
        if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return array();
        }

        $price_cache = WCCS()->WCCS_Product_Price_Cache;
        $product_id  = (int) $product->get_id();

        if ( ! empty( $this->replacing_variation_prices[ $product_id ] ) ) {
            return array();
        }

        $this->replacing_variation_prices[ $product_id ] = true;
        try {
            $cache_key = $price_cache->build_variation_cache_key( $product_id, $for_display, 'lightweight' );

            // 1. Check local instance memory cache (0.0001ms)
            if ( isset( $this->prices[ $product_id ][ $cache_key ] ) && is_array( $this->prices[ $product_id ][ $cache_key ] ) ) {
                return $this->prices[ $product_id ][ $cache_key ];
            }

            // 2. Check 3-Tier Cache (Local Memory -> Redis/Memcached -> Layer 2 DB Cache)
            $cached_prices = $price_cache->get_variation_prices( $product, $for_display, 'lightweight' );
            if ( false !== $cached_prices && is_array( $cached_prices ) && ! empty( $cached_prices['price'] ) ) {
                if ( ! isset( $this->prices[ $product_id ] ) ) {
                    $this->prices[ $product_id ] = array();
                }
                $this->prices[ $product_id ][ $cache_key ] = $cached_prices;
                return $cached_prices;
            }

            // 3. Compute on cache miss
            $variation_ids = $product->get_visible_children();
            if ( empty( $variation_ids ) ) {
                return array(
                    'price'         => array(),
                    'regular_price' => array(),
                    'sale_price'    => array(),
                );
            }

            // Bulk prime variation post objects, meta, and Layer 2 DB price cache in single batch query
            WCCS_Helpers::prime_product_caches( $variation_ids );

            $prices_array = array(
                'price'         => array(),
                'regular_price' => array(),
                'sale_price'    => array(),
            );

            $price_decimals = wc_get_price_decimals();
            $tax_enabled    = wc_tax_enabled();
            $is_incl_tax    = ( $for_display && $tax_enabled && 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' ) );

            foreach ( $variation_ids as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }

                $discounted_price = WCCS_Helpers::get_product_discounted_price( $variation );
                $price            = ( ! is_numeric( $discounted_price ) || 0 > $discounted_price ) ? $variation->get_price( 'edit' ) : $discounted_price;
                $regular_price    = $variation->get_regular_price( 'edit' );
                $sale_price       = ( ! is_numeric( $discounted_price ) || 0 > $discounted_price ) ? $variation->get_price( 'edit' ) : $discounted_price;

                // Skip empty prices
                if ( '' === $price ) {
                    continue;
                }

                // If sale price equals regular price or does not equal active price, it is not on sale
                if ( $sale_price === $regular_price || $sale_price !== $price ) {
                    $sale_price = $regular_price;
                }

                // Account for display taxes
                if ( $is_incl_tax ) {
                    $price         = '' === $price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
                    $regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
                    $sale_price    = '' === $sale_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
                } else {
                    $price         = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
                    $regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
                    $sale_price    = '' === $sale_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
                }

                $prices_array['price'][ $variation_id ]         = wc_format_decimal( $price, $price_decimals );
                $prices_array['regular_price'][ $variation_id ] = wc_format_decimal( $regular_price, $price_decimals );
                $prices_array['sale_price'][ $variation_id ]    = wc_format_decimal( $sale_price, $price_decimals );
            }

            // Allow third-party extensions to filter prices, safely avoiding recursion
            if ( has_filter( 'woocommerce_variation_prices' ) ) {
                $has_self_hook = has_filter( 'woocommerce_variation_prices', array( $this, 'replace_variation_prices' ) );
                if ( false !== $has_self_hook ) {
                    remove_filter( 'woocommerce_variation_prices', array( $this, 'replace_variation_prices' ), 999 );
                }
                $filtered = apply_filters( 'woocommerce_variation_prices', $prices_array, $product, $for_display );
                if ( false !== $has_self_hook ) {
                    add_filter( 'woocommerce_variation_prices', array( $this, 'replace_variation_prices' ), 999, 3 );
                }
                if ( is_array( $filtered ) ) {
                    $prices_array = $filtered;
                }
            }

            // Guaranteed ascending sort for mathematically accurate min/max calculations
            if ( ! empty( $prices_array['price'] ) && is_array( $prices_array['price'] ) ) {
                asort( $prices_array['price'] );
            }
            if ( ! empty( $prices_array['regular_price'] ) && is_array( $prices_array['regular_price'] ) ) {
                asort( $prices_array['regular_price'] );
            }
            if ( ! empty( $prices_array['sale_price'] ) && is_array( $prices_array['sale_price'] ) ) {
                asort( $prices_array['sale_price'] );
            }

            // Store into 3-tier cache (always in RAM for current request; in persistent cache if conditions permit)
            $can_persist = $price_cache->can_cache_variation_prices( $product );
            $ttl         = null;
            if ( $can_persist ) {
                $all_rules   = $price_cache->get_variable_product_all_simple_rules( $product );
                $default_ttl = $price_cache->get_default_ttl();
                $time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
                $ttl         = ( $time_expiry > 0 ) ? ( ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry ) : $default_ttl;
            }

            $price_cache->set_variation_prices( $product_id, $cache_key, $prices_array, $ttl, $can_persist );

            if ( ! isset( $this->prices[ $product_id ] ) ) {
                $this->prices[ $product_id ] = array();
            }
            $this->prices[ $product_id ][ $cache_key ] = $prices_array;

            return $prices_array;
        } finally {
            unset( $this->replacing_variation_prices[ $product_id ] );
        }
    }

    public function replace_variation_prices( $prices, $product, $for_display ) {
        if ( ! is_a( $product, 'WC_Product_Variable' ) ) {
            $product = is_numeric( $product ) ? wc_get_product( $product ) : $product;
            if ( ! $product || ! $product->is_type( 'variable' ) ) {
                return $prices;
            }
        }

        if ( ! WCCS()->is_request( 'frontend' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return $prices;
        }

        // Do not replace price till woocommerce_cart_loaded_from_session done.
        if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return $prices;
        }

        $price_cache = WCCS()->WCCS_Product_Price_Cache;
        $product_id  = (int) $product->get_id();

        if ( ! empty( $this->replacing_variation_prices[ $product_id ] ) ) {
            return $prices;
        }

        $this->replacing_variation_prices[ $product_id ] = true;
        try {
            $cache_key = $price_cache->build_variation_cache_key( $product_id, $for_display, 'advanced' );

            // 1. Check local instance memory cache (0.0001ms)
            if ( isset( $this->prices[ $product_id ][ $cache_key ] ) && is_array( $this->prices[ $product_id ][ $cache_key ] ) ) {
                return $this->prices[ $product_id ][ $cache_key ];
            }

            // 2. Check 3-Tier Cache (Local Memory -> Redis/Memcached -> Layer 2 DB Cache)
            $cached_prices = $price_cache->get_variation_prices( $product, $for_display, 'advanced' );
            if ( false !== $cached_prices && is_array( $cached_prices ) && ! empty( $cached_prices['price'] ) ) {
                if ( ! isset( $this->prices[ $product_id ] ) ) {
                    $this->prices[ $product_id ] = array();
                }
                $this->prices[ $product_id ][ $cache_key ] = $cached_prices;
                return $cached_prices;
            }

            // 3. Compute on cache miss
            $variation_ids = $product->get_visible_children();
            if ( empty( $variation_ids ) ) {
                return $prices;
            }

            // Bulk prime variation post objects, meta, and Layer 2 DB price cache in single batch query
            WCCS_Helpers::prime_product_caches( $variation_ids );

            $prices_array = array(
                'price'         => array(),
                'regular_price' => array(),
                'sale_price'    => array(),
            );

            $price_decimals = wc_get_price_decimals();
            $tax_enabled    = wc_tax_enabled();
            $is_incl_tax    = ( $for_display && $tax_enabled && 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' ) );

            foreach ( $variation_ids as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }

                $price         = $this->get_replaced_price( $variation->get_price( 'edit' ), $variation, false );
                $regular_price = $this->get_replaced_regular_price( $variation->get_regular_price( 'edit' ), $variation, false );
                $sale_price    = $this->get_replaced_sale_price( $variation->get_sale_price( 'edit' ), $variation, false );

                // Skip empty prices
                if ( '' === $price ) {
                    continue;
                }

                // If sale price equals regular price or does not equal active price, it is not on sale
                if ( $sale_price === $regular_price || $sale_price !== $price ) {
                    $sale_price = $regular_price;
                }

                // Account for display taxes
                if ( $is_incl_tax ) {
                    $price         = '' === $price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
                    $regular_price = '' === $regular_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
                    $sale_price    = '' === $sale_price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
                } else {
                    $price         = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
                    $regular_price = '' === $regular_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular_price ) );
                    $sale_price    = '' === $sale_price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale_price ) );
                }

                $prices_array['price'][ $variation_id ]         = wc_format_decimal( $price, $price_decimals );
                $prices_array['regular_price'][ $variation_id ] = wc_format_decimal( $regular_price, $price_decimals );
                $prices_array['sale_price'][ $variation_id ]    = wc_format_decimal( $sale_price, $price_decimals );
            }

            // Guaranteed ascending sort for mathematically accurate min/max calculations
            if ( ! empty( $prices_array['price'] ) && is_array( $prices_array['price'] ) ) {
                asort( $prices_array['price'] );
            }
            if ( ! empty( $prices_array['regular_price'] ) && is_array( $prices_array['regular_price'] ) ) {
                asort( $prices_array['regular_price'] );
            }
            if ( ! empty( $prices_array['sale_price'] ) && is_array( $prices_array['sale_price'] ) ) {
                asort( $prices_array['sale_price'] );
            }

            // Store into 3-tier cache (always in RAM for current request; in persistent cache if conditions permit)
            $can_persist = $price_cache->can_cache_variation_prices( $product );
            $ttl         = null;
            if ( $can_persist ) {
                $all_rules   = $price_cache->get_variable_product_all_simple_rules( $product );
                $default_ttl = $price_cache->get_default_ttl();
                $time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
                $ttl         = ( $time_expiry > 0 ) ? ( ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry ) : $default_ttl;
            }

            $price_cache->set_variation_prices( $product_id, $cache_key, $prices_array, $ttl, $can_persist );

            if ( ! isset( $this->prices[ $product_id ] ) ) {
                $this->prices[ $product_id ] = array();
            }
            $this->prices[ $product_id ][ $cache_key ] = $prices_array;

            return $prices_array;
        } finally {
            unset( $this->replacing_variation_prices[ $product_id ] );
        }
    }

    protected function should_replace( $price, $product, $price_type ) {
        if ( ! $this->should_replace_prices ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        if ( ! WCCS()->is_request( 'frontend' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        // Do not replace price till woocommerce_cart_loaded_from_session done.
        if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) && ! WCCS_Helpers::wc_is_rest_api_request() ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        // Do not replace variable products price.
        if ( $product->is_type( 'variable' ) ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        // Do not replace product price when it is empty.
        if ( '' === $price && self::PRICE === $price_type ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        // Do not replace price for cart items.
        if ( null !== WCCS()->custom_props->get_prop( $product, 'wccs_is_cart_item' ) ) {
            return apply_filters( 'wccs_product_price_replace_should_replace', false, $price, $product, $price_type, $this  );
        }

        return apply_filters( 'wccs_product_price_replace_should_replace', true, $price, $product, $price_type, $this );
    }

    public function get_replaced_price( $price, $product, $exchange_price = true ) {
        if ( isset( $this->prices[ $product->get_id() ]['price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product->get_id() ]['price'][ strval( $price ) ];
        }

        return $this->replace_price( $price, $product, $exchange_price );
    }

    public function get_replaced_sale_price( $price, $product, $exchange_price = true ) {
        if ( isset( $this->prices[ $product->get_id() ]['sale_price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product->get_id() ]['sale_price'][ strval( $price ) ];
        }

        return $this->replace_sale_price( $price, $product, $exchange_price );
    }

    public function get_replaced_regular_price( $price, $product, $exchange_price = true ) {
        if ( isset( $this->prices[ $product->get_id() ]['regular_price'][ strval( $price ) ] ) ) {
            return $this->prices[ $product->get_id() ]['regular_price'][ strval( $price ) ];
        }

        return $this->replace_regular_price( $price, $product, $exchange_price );
    }

    public function can_read_variation_cached_prices( $product ) {
        if ( null !== $this->read_variation_cached_prices ) {
            return $this->read_variation_cached_prices;
        }

        return WCCS()->WCCS_Product_Price_Cache->can_read_variation_cached_prices( $product );
    }

    public function can_cache_variation_prices( $product, $rules = null ) {
        return WCCS()->WCCS_Product_Price_Cache->can_cache_variation_prices( $product, $rules );
    }

    public function reset_cache() {
        $this->prices                       = array();
        $this->read_variation_cached_prices = null;
        $this->replacing_variation_prices   = array();
    }

    public function get_variation_prices_hash( $price_hash, $product, $for_display ) {
        if ( ! is_array( $price_hash ) ) {
            $price_hash = array();
        }
        $price_hash['wccs'] = WCCS()->WCCS_Product_Price_Cache->get_variation_hash_data( $product, $for_display );
        return $price_hash;
    }

    protected function maybe_cache_price( $product, $price, $price_type, $replaced_price ) {
        // Do not cache the price while woocommerce_cart_loaded_from_session done.
        if ( ! did_action( 'woocommerce_cart_loaded_from_session' ) ) {
            return $replaced_price;
        } elseif ( ! $product || ! $product->get_id() ) {
            return $replaced_price;
        } elseif ( null !== WCCS()->custom_props->get_prop( $product, 'wccs_is_cart_item' ) ) {
            return $replaced_price;
        } elseif ( empty( $price_type ) ) {
            return $replaced_price;
        }

        $this->prices[ $product->get_id() ][ $price_type ][ strval( $price ) ] = $replaced_price;

        return $replaced_price;
    }

}
