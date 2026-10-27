<?php
defined( 'ABSPATH' ) || exit;

class WCCS_Compatibility_CTXFeed {

    public static function init() {
        add_filter( 'woo_feed_filter_product_regular_price', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );
		add_filter( 'woo_feed_filter_product_regular_price_with_tax', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );

        add_filter( 'woo_feed_filter_product_sale_price', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );
		add_filter( 'woo_feed_filter_product_sale_price_with_tax', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );

		add_filter( 'woo_feed_filter_product_price', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );
		add_filter( 'woo_feed_filter_product_price_with_tax', [ __CLASS__,'maybe_replace_product_price' ], 9, 5 );
    }

    public static function maybe_replace_product_price( $price, $product, $feedConfig, $tax , $price_type ) {
        if ( ! $product ) {
            return $price;
        }

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $product );
        if ( null === $adjusted_price ) {
            return $price;
        }

        if ( $tax ) {
            $adjusted_price = wc_get_price_including_tax( $product, [ 'price' => $adjusted_price ] );
        }
        
        if ( 'regular_price' === $price_type && $adjusted_price <= $price ) {
            return $price;
        }

        return apply_filters( 
            'asnp_ewd_compatibility_ctxfeed_maybe_replace_product_price', 
            $adjusted_price, 
            $price, 
            $product, 
            $feedConfig, 
            $tax, 
            $price_type 
        );
    }
    
}
