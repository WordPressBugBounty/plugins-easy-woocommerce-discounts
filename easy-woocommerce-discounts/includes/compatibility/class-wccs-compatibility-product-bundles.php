<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Compatibility_Product_Bundles {

    public static function init() {
        add_filter( 'asnp_wepb_maybe_change_price', [ __CLASS__, 'maybe_change_price' ], 99, 3 );
        add_filter( 'asnp_wepb_get_bundle_item_price', [ __CLASS__, 'maybe_change_bundle_item_price' ], 10, 3 );
        add_filter( 'asnp_wepb_prepare_product_prices', [ __CLASS__, 'maybe_change_product_prices' ], 99, 3 );
        add_filter( 'asnp_wccs_total_discounts_process_cart_item', [ __CLASS__, 'include_in_total_discount' ], 10, 2 );
        add_filter( 'wccs_auto_add_products_bogo_process_cart_item', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_purchase_discounts', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_purchase_pricings', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_bulk_discounts', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_bulk_pricings', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_tiered_discounts', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_tiered_pricings', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_products_group_discounts', [ __CLASS__, 'process_cart_item' ], 10, 2 );
        add_filter( 'wccs_cart_item_products_group_pricings', [ __CLASS__, 'process_cart_item' ], 10, 2 );
    }

    public static function maybe_change_price( $price, $product, $price_type ) {
        if ( ! $product ) {
            return $price;
        }

        if ( $product->is_type( 'easy_product_bundle' ) && ! $product->is_fixed_price() ) {
            return $price;
        } 

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $product );
        if ( is_numeric( $adjusted_price ) && 0 <= $adjusted_price ) {
            if ( 'regular_price' === $price_type && $adjusted_price <= $price ) {
                return $price;
            }
            return $adjusted_price;
        }

        return $price;
    }

    public static function maybe_change_bundle_item_price( $price, $product, $args ) {
        if ( ! $product ) {
            return $price;
        }

        if ( ! empty( $args['has_discount'] ) ) {
            return $price;
        }

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $product );
        if ( is_numeric( $adjusted_price ) && 0 <= $adjusted_price ) {
            if ( ! empty( $args['exchange_price'] ) ) {
                $adjusted_price = WCCS_Helpers::maybe_exchange_price( $adjusted_price );
            }
            return $adjusted_price;
        }

        return $price;
    }

    public static function maybe_change_product_prices( $prices, $product, $item ) {
        if ( empty( $prices ) || ! $product ) {
            return $prices;
        }

        if ( ! empty( $prices['display_price'] ) && '' === $prices['sale_price'] ) {
            if ( ! $product->is_type( 'variable' ) ) {
                $prices['display_price'] = wc_price( $prices['regular_price'] ) . $product->get_price_suffix();
            }
        }

        return $prices;
    }

    public static function process_cart_item( $process, $cart_item ) {
        if ( isset( $cart_item['asnp_wepb_items'] ) || isset( $cart_item['asnp_wepb_parent_id'] ) ) {
            return false;
        }

        return $process;
    }

    public static function include_in_total_discount( $include, $cart_item ) {
        if ( ! $include ) {
            return false;
        }

        if ( 
            isset( $cart_item['asnp_wepb_items'] ) && 
            isset( $cart_item['asnp_wepb_is_fixed_price'] ) && 
            false === $cart_item['asnp_wepb_is_fixed_price'] &&
            'true' !== $cart_item['data']->get_include_parent_price()
        ) {
            return false;
        }

        if ( 
            isset( $cart_item['asnp_wepb_parent_id'] ) && 
            isset( $cart_item['asnp_wepb_parent_is_fixed_price'] ) && 
            true === $cart_item['asnp_wepb_parent_is_fixed_price'] 
        ) {
            return false;
        }

        return true;
    }

}
