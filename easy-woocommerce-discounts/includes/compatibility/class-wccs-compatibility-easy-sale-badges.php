<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Compatibility_Easy_Sale_Badges {

    public static function init() {
        if ( 'lightweight' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' ) ) {
            add_filter( 'asnp_wesb_product_get_price', [ __CLASS__, 'product_get_price' ], 9999, 2 );
            add_filter( 'asnp_wesb_variation_prices', [ __CLASS__, 'variation_prices' ], 9999, 2 );
            add_filter( 'woocommerce_available_variation', [ __CLASS__, 'modify_available_variation' ], 9999, 3 );
        }
    }

    public static function product_get_price( $price, $product ) {
        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $product, null, true );
        if ( is_numeric( $adjusted_price ) && 0 <= $adjusted_price ) {
            return $adjusted_price;
        }

        return $price;
    }

    public static function variation_prices( $prices, $product ) {
        $adjusted_prices = WCCS()->WCCS_Product_Price_Replace->get_discounted_variation_prices( $product, true );
        if ( ! empty( $adjusted_prices ) ) {
            return $adjusted_prices;
        }

        return $prices;
    }

    public static function modify_available_variation( $available_variation, $product, $variation ) {
        if ( empty( $available_variation ) || ! $product || ! $variation ) {
            return $available_variation;
        }

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $variation, null, true );
        if ( is_numeric( $adjusted_price ) && 0 <= $adjusted_price ) {
            $available_variation['display_price'] = wc_get_price_to_display( $variation, [ 'price' => $adjusted_price ] );
        }

        return $available_variation;
    }

}
