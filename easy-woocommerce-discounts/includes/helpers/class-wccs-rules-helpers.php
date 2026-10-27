<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Rules_Helpers {

    const STATIC_RULE  = 'static';
    const TIME_BASED   = 'time_based';
    const DYNAMIC_RULE = 'dynamic';

    protected static $cart_conditions = array(
        'products_in_cart',
        'product_variations_in_cart',
        'featured_products_in_cart',
        'onsale_products_in_cart',
        'product_categories_in_cart',
        'product_attributes_in_cart',
        'product_tags_in_cart',
        'shipping_classes_in_cart',
        'number_of_cart_items',
        'quantity_of_cart_items',
        'max_width_of_cart_items',
        'max_height_of_cart_items',
        'max_length_of_cart_items',
        'min_stock_of_cart_items',
        'subtotal_including_tax',
        'subtotal_excluding_tax',
        'subtotal_including_tax_excluding_coupons',
        'subtotal_excluding_tax_excluding_coupons',
        'cart_total_weight',
        'coupons_applied',
        'quantity_of_products',
        'quantity_of_variations',
        'quantity_of_categories',
        'quantity_of_attributes',
        'quantity_of_tags',
        'products_in_package',
        'product_variations_in_package',
        'product_categories_in_package',
        'product_attributes_in_package',
        'product_tags_in_package',
        'shipping_classes_in_package',
        'number_of_package_items',
        'quantity_of_package_items',
        'package_total_weight',
        'subtotal_of_products_include_tax',
        'subtotal_of_products_exclude_tax',
        'subtotal_of_variations_include_tax',
        'subtotal_of_variations_exclude_tax',
        'subtotal_of_categories_include_tax',
        'subtotal_of_categories_exclude_tax',
        'subtotal_of_attributes_include_tax',
        'subtotal_of_attributes_exclude_tax',
        'subtotal_of_tags_include_tax',
        'subtotal_of_tags_exclude_tax',
        'subtotal_of_regular_products_include_tax',
        'subtotal_of_regular_products_exclude_tax',
        'subtotal_of_onsale_products_include_tax',
        'subtotal_of_onsale_products_exclude_tax',
        'payment_method',
        'shipping_method',
        'taxonomy_in_cart',
        'taxonomy_subtotal_including_tax',
        'taxonomy_subtotal_excluding_tax',
        'taxonomy_quantity_in_cart',
    );

    protected static $user_conditions = array(
        'customers',
        'roles',
        'is_logged_in',
        'user_capability',
        'user_meta',
        'email',
        'user_usage_limit',
        'money_spent',
        'number_of_orders',
        'last_order_amount',
        'average_money_spent_per_order',
        'last_order_date',
        'number_of_products_reviews',
        'bought_products',
        'bought_product_variations',
        'bought_product_categories',
        'bought_product_attributes',
        'bought_product_tags',
        'bought_featured_products',
        'bought_onsale_products',
        'quantity_of_bought_products',
        'quantity_of_bought_variations',
        'quantity_of_bought_categories',
        'quantity_of_bought_attributes',
        'quantity_of_bought_tags',
        'amount_of_bought_products',
        'amount_of_bought_variations',
        'amount_of_bought_categories',
        'amount_of_bought_attributes',
        'amount_of_bought_tags',
        'taxonomy_bought',
        'taxonomy_bought_amount',
        'taxonomy_bought_quantity',
        'shipping_country',
        'shipping_state',
        'shipping_city',
        'shipping_postcode',
        'shipping_zone',
    );

    protected static $role_conditions = array(
        'roles',
        'is_logged_in',
        'user_capability',
    );

    protected static $personal_conditions = array(
        'customers',
        'user_meta',
        'email',
        'user_usage_limit',
        'money_spent',
        'number_of_orders',
        'last_order_amount',
        'average_money_spent_per_order',
        'last_order_date',
        'number_of_products_reviews',
        'bought_products',
        'bought_product_variations',
        'bought_product_categories',
        'bought_product_attributes',
        'bought_product_tags',
        'bought_featured_products',
        'bought_onsale_products',
        'quantity_of_bought_products',
        'quantity_of_bought_variations',
        'quantity_of_bought_categories',
        'quantity_of_bought_attributes',
        'quantity_of_bought_tags',
        'amount_of_bought_products',
        'amount_of_bought_variations',
        'amount_of_bought_categories',
        'amount_of_bought_attributes',
        'amount_of_bought_tags',
        'taxonomy_bought',
        'taxonomy_bought_amount',
        'taxonomy_bought_quantity',
        'shipping_country',
        'shipping_state',
        'shipping_city',
        'shipping_postcode',
        'shipping_zone',
    );

    protected static $dynamic_product_items = array(
        'recently_viewed_products',
        'similar_products_to_customer_bought_products',
        'similar_products_to_customer_cart_products',
        'top_seller_products',
        'top_earner_products',
        'top_free_products',
        'discounted_products',
        'product_stock_quantity',
        'product_display_price',
        'product_meta_field',
    );

    public static function classify_rule( $pricing ) {
        $conditions = array();
        if ( is_object( $pricing ) && isset( $pricing->conditions ) ) {
            $conditions = $pricing->conditions;
        } elseif ( is_array( $pricing ) && isset( $pricing['conditions'] ) ) {
            $conditions = $pricing['conditions'];
        }

        $has_cart     = self::has_condition_type( $conditions, self::$cart_conditions );
        $has_user     = self::has_condition_type( $conditions, self::$user_conditions );
        $has_dyn_prod = self::has_dynamic_product_items( $pricing );
        $has_time     = self::has_date_time_conditions( $pricing );

        if ( $has_cart || $has_user || $has_dyn_prod ) {
            return self::DYNAMIC_RULE;
        }

        if ( $has_time ) {
            return self::TIME_BASED;
        }

        return self::STATIC_RULE;
    }

    public static function has_dynamic_product_items( $pricing ) {
        $items = array();
        if ( is_object( $pricing ) ) {
            if ( ! empty( $pricing->items ) ) {
                $items[] = $pricing->items;
            }
            if ( ! empty( $pricing->exclude_items ) ) {
                $items[] = $pricing->exclude_items;
            }
        } elseif ( is_array( $pricing ) ) {
            if ( ! empty( $pricing['items'] ) ) {
                $items[] = $pricing['items'];
            }
            if ( ! empty( $pricing['exclude_items'] ) ) {
                $items[] = $pricing['exclude_items'];
            }
        }

        if ( empty( $items ) ) {
            return false;
        }

        foreach ( $items as $item_group ) {
            if ( ! is_array( $item_group ) ) {
                continue;
            }

            foreach ( $item_group as $group ) {
                if ( empty( $group ) || ! is_array( $group ) ) {
                    continue;
                }

                $rules = isset( $group[0] ) && is_array( $group[0] ) ? $group : array( $group );
                foreach ( $rules as $rule ) {
                    $type = '';
                    if ( is_array( $rule ) ) {
                        $type = isset( $rule['item'] ) ? $rule['item'] : ( isset( $rule['type'] ) ? $rule['type'] : '' );
                    }
                    if ( $type && in_array( $type, self::$dynamic_product_items, true ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function has_cart_conditions( $rules ) {
        if ( empty( $rules ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $conditions = array();
            if ( is_object( $rule ) && isset( $rule->conditions ) ) {
                $conditions = $rule->conditions;
            } elseif ( is_array( $rule ) && isset( $rule['conditions'] ) ) {
                $conditions = $rule['conditions'];
            }
            if ( self::has_condition_type( $conditions, self::$cart_conditions ) ) {
                return true;
            }
        }
        return false;
    }

    public static function has_role_conditions( $rules ) {
        if ( empty( $rules ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $conditions = array();
            if ( is_object( $rule ) && isset( $rule->conditions ) ) {
                $conditions = $rule->conditions;
            } elseif ( is_array( $rule ) && isset( $rule['conditions'] ) ) {
                $conditions = $rule['conditions'];
            }
            if ( self::has_condition_type( $conditions, self::$role_conditions ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get visitor cache segment based on user roles.
     *
     * Groups visitors into deterministic segments ('guest', 'role_customer', 'role_wholesale', etc.)
     * to avoid database/cache bloat while maximizing cache reuse across identical user roles.
     *
     * @return string
     */
    public static function get_user_segment() {
        $segment = 'guest';

        if ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $roles = ( $user && ! empty( $user->roles ) ) ? (array) $user->roles : array( 'customer' );
            sort( $roles );
            $segment = 'role_' . implode( '_', $roles );
        }

        return apply_filters( 'asnp_wccs_user_cache_segment', $segment );
    }

    public static function has_personal_conditions( $rules ) {
        if ( empty( $rules ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $conditions = array();
            if ( is_object( $rule ) && isset( $rule->conditions ) ) {
                $conditions = $rule->conditions;
            } elseif ( is_array( $rule ) && isset( $rule['conditions'] ) ) {
                $conditions = $rule['conditions'];
            }
            if ( self::has_condition_type( $conditions, self::$personal_conditions ) ) {
                return true;
            }
        }
        return false;
    }

    public static function has_user_conditions( $rules ) {
        if ( empty( $rules ) ) {
            return false;
        }
        foreach ( $rules as $rule ) {
            $conditions = array();
            if ( is_object( $rule ) && isset( $rule->conditions ) ) {
                $conditions = $rule->conditions;
            } elseif ( is_array( $rule ) && isset( $rule['conditions'] ) ) {
                $conditions = $rule['conditions'];
            }
            if ( self::has_condition_type( $conditions, self::$user_conditions ) ) {
                return true;
            }
        }
        return false;
    }

    public static function has_static_rules_only( $rules ) {
        if ( empty( $rules ) ) {
            return true;
        }
        foreach ( $rules as $rule ) {
            if ( self::DYNAMIC_RULE === self::classify_rule( $rule ) ) {
                return false;
            }
        }
        return true;
    }

    public static function can_cache_rules( $rules, $allow_role_based = false ) {
        if ( empty( $rules ) ) {
            return true;
        }

        foreach ( $rules as $rule ) {
            $usage_limit = 0;
            if ( is_object( $rule ) && ! empty( $rule->usage_limit ) ) {
                $usage_limit = (int) $rule->usage_limit;
            } elseif ( is_array( $rule ) && ! empty( $rule['usage_limit'] ) ) {
                $usage_limit = (int) $rule['usage_limit'];
            }

            if ( $usage_limit > 0 ) {
                return false;
            }

            $conditions = array();
            if ( is_object( $rule ) && isset( $rule->conditions ) ) {
                $conditions = $rule->conditions;
            } elseif ( is_array( $rule ) && isset( $rule['conditions'] ) ) {
                $conditions = $rule['conditions'];
            }

            if ( self::has_condition_type( $conditions, self::$cart_conditions ) ) {
                return false;
            }

            if ( self::has_dynamic_product_items( $rule ) ) {
                return false;
            }

            if ( self::has_condition_type( $conditions, self::$personal_conditions ) ) {
                return false;
            }

            if ( ! $allow_role_based && self::has_condition_type( $conditions, self::$role_conditions ) ) {
                return false;
            }
        }

        return true;
    }

    protected static function has_condition_type( $conditions, $types ) {
        if ( empty( $conditions ) || ! is_array( $conditions ) ) {
            return false;
        }

        $flat = array();
        if ( isset( $conditions[0] ) && is_array( $conditions[0] ) && ! isset( $conditions[0]['condition'] ) ) {
            foreach ( $conditions as $group ) {
                if ( ! empty( $group ) ) {
                    foreach ( $group as $c ) {
                        $flat[] = $c;
                    }
                }
            }
        } else {
            $flat = $conditions;
        }

        foreach ( $flat as $c ) {
            if ( is_array( $c ) && isset( $c['condition'] ) ) {
                $cond = $c['condition'];

                // Strip taxonomy suffix (e.g. 'taxonomy_in_cart__pa_color' -> 'taxonomy_in_cart')
                $base_cond = false !== strpos( $cond, '__' ) ? substr( $cond, 0, strpos( $cond, '__' ) ) : $cond;

                if ( in_array( $cond, $types, true ) || in_array( $base_cond, $types, true ) ) {
                    return true;
                }

                foreach ( $types as $type ) {
                    if ( false !== strpos( $cond, $type ) || false !== strpos( $base_cond, $type ) ) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected static function has_date_time_conditions( $pricing ) {
        $date_time = array();
        if ( is_object( $pricing ) && ! empty( $pricing->date_time ) ) {
            $date_time = $pricing->date_time;
        } elseif ( is_array( $pricing ) && ! empty( $pricing['date_time'] ) ) {
            $date_time = $pricing['date_time'];
        }

        return ! empty( $date_time );
    }

    public static function get_nearest_time_expiry( $pricing ) {
        if ( empty( $pricing ) ) {
            return 0;
        }

        $date_time = array();
        if ( is_object( $pricing ) ) {
            if ( ! empty( $pricing->date_time ) ) {
                $date_time = $pricing->date_time;
            }
        } elseif ( is_array( $pricing ) ) {
            if ( ! empty( $pricing['date_time'] ) ) {
                $date_time = $pricing['date_time'];
            }
        }

        // If $pricing is an array of rules (list of rule arrays or objects)
        if ( empty( $date_time ) && is_array( $pricing ) ) {
            $min_ttl = 0;
            foreach ( $pricing as $single_rule ) {
                if ( is_array( $single_rule ) || is_object( $single_rule ) ) {
                    $ttl = self::get_nearest_time_expiry( $single_rule );
                    if ( $ttl > 0 && ( 0 === $min_ttl || $ttl < $min_ttl ) ) {
                        $min_ttl = $ttl;
                    }
                }
            }
            return $min_ttl > 0 ? $min_ttl : 0;
        }

        if ( empty( $date_time ) ) {
            return 0;
        }

        $flat = array();
        if ( is_array( $date_time[0] ) && ! isset( $date_time[0]['type'] ) ) {
            foreach ( $date_time as $group ) {
                if ( ! empty( $group ) ) {
                    foreach ( $group as $dt ) {
                        $flat[] = $dt;
                    }
                }
            }
        } else {
            $flat = $date_time;
        }

        $tz      = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $now_dt  = new DateTime( 'now', $tz );
        $now_ts  = $now_dt->getTimestamp();
        $min_ttl = 0;

        foreach ( $flat as $dt ) {
            if ( empty( $dt['type'] ) ) {
                continue;
            }

            $ttl = 0;
            if ( in_array( $dt['type'], array( 'date', 'date_time' ), true ) ) {
                $is_date_only = ( 'date' === $dt['type'] );

                if ( ! empty( $dt['start']['time'] ) ) {
                    $start_str = trim( $dt['start']['time'] );
                    if ( $is_date_only && strlen( $start_str ) <= 10 ) {
                        $start_str .= ' 00:00:00';
                    }
                    try {
                        $start_dt = new DateTime( $start_str, $tz );
                        $start_ts = $start_dt->getTimestamp();
                        if ( $start_ts > $now_ts ) {
                            $ttl = $start_ts - $now_ts;
                        }
                    } catch ( Exception $e ) {}
                }

                if ( 0 === $ttl && ! empty( $dt['end']['time'] ) ) {
                    $end_str = trim( $dt['end']['time'] );
                    if ( $is_date_only && strlen( $end_str ) <= 10 ) {
                        $end_str .= ' 23:59:59';
                    }
                    try {
                        $end_dt = new DateTime( $end_str, $tz );
                        $end_ts = $end_dt->getTimestamp();
                        if ( $end_ts > $now_ts ) {
                            $ttl = $end_ts - $now_ts;
                        }
                    } catch ( Exception $e ) {}
                }
            } elseif ( 'specific_date' === $dt['type'] ) {
                // Specific dates transition at midnight
                try {
                    $midnight = new DateTime( 'tomorrow 00:00:00', $tz );
                    $ttl      = $midnight->getTimestamp() - $now_ts;
                } catch ( Exception $e ) {}
            } elseif ( 'time' === $dt['type'] ) {
                if ( ! empty( $dt['start_time'] ) && ! empty( $dt['end_time'] ) ) {
                    try {
                        $today_date = $now_dt->format( 'Y-m-d' );
                        $start_dt   = new DateTime( $today_date . ' ' . $dt['start_time'], $tz );
                        $end_dt     = new DateTime( $today_date . ' ' . $dt['end_time'], $tz );

                        if ( $end_dt < $start_dt ) {
                            $end_dt->modify( '+1 day' );
                        }

                        $start_ts = $start_dt->getTimestamp();
                        $end_ts   = $end_dt->getTimestamp();

                        if ( $now_ts < $start_ts ) {
                            $ttl = $start_ts - $now_ts;
                        } elseif ( $now_ts >= $start_ts && $now_ts <= $end_ts ) {
                            $ttl = $end_ts - $now_ts;
                        } else {
                            $start_dt->modify( '+1 day' );
                            $ttl = $start_dt->getTimestamp() - $now_ts;
                        }
                    } catch ( Exception $e ) {}
                }
            } elseif ( 'days' === $dt['type'] ) {
                // Day-of-week rules transition at midnight when the day changes
                try {
                    $midnight = new DateTime( 'tomorrow 00:00:00', $tz );
                    $ttl      = $midnight->getTimestamp() - $now_ts;
                } catch ( Exception $e ) {}
            }

            if ( $ttl > 0 && ( 0 === $min_ttl || $ttl < $min_ttl ) ) {
                $min_ttl = $ttl;
            }
        }

        return $min_ttl > 0 ? $min_ttl : 0;
    }

    /**
     * Flatten and extract all item condition arrays from a pricing rule.
     *
     * Handles both flat condition lists and nested condition groups.
     *
     * @since  9.12.0
     *
     * @param  object|array $rule Pricing rule object or array.
     * @return array List of condition item arrays.
     */
    public static function get_rule_item_conditions( $rule ) {
        $rule_array = (array) $rule;
        $raw_items  = array();

        if ( ! empty( $rule_array['items'] ) && is_array( $rule_array['items'] ) ) {
            $raw_items[] = $rule_array['items'];
        }
        if ( ! empty( $rule_array['exclude_items'] ) && is_array( $rule_array['exclude_items'] ) ) {
            $raw_items[] = $rule_array['exclude_items'];
        }

        if ( empty( $raw_items ) ) {
            return array();
        }

        $flat = array();
        foreach ( $raw_items as $item_group ) {
            if ( isset( $item_group[0] ) && is_array( $item_group[0] ) && ! isset( $item_group[0]['item'] ) && ! isset( $item_group[0]['condition'] ) ) {
                foreach ( $item_group as $group ) {
                    if ( is_array( $group ) ) {
                        foreach ( $group as $item ) {
                            if ( is_array( $item ) ) {
                                $flat[] = $item;
                            }
                        }
                    }
                }
            } else {
                foreach ( $item_group as $item ) {
                    if ( is_array( $item ) ) {
                        $flat[] = $item;
                    }
                }
            }
        }

        return $flat;
    }

}
