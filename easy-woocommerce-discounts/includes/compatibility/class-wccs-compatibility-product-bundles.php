<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Compatibility_Product_Bundles {

    public static function init() {
        add_filter( 'asnp_wepb_maybe_change_price', [ __CLASS__, 'maybe_change_price' ], 99, 3 );
    }

    public static function maybe_change_price( $price, $product, $price_type ) {
        if ( ! $product ) {
            return $price;
        }

        return WCCS()->WCCS_Product_Price_Replace->replace( $price, $product, $price_type, false );
    }

}
