<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Easy WooCommerce Discounts compatibility with Advanced Product Fields for WooCommerce.
 *
 * @link  https://wordpress.org/plugins/advanced-product-fields-for-woocommerce/
 */
class WCCS_Compatibility_SW_WAPF {

    public static function init() {
        if ( 'product_price' === WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' ) ) {
            add_filter( 'wccs_cart_item_discounted_price', [ __CLASS__, 'cart_item_discounted_price' ], 10, 2 );
            add_filter( 'wccs_cart_item_main_price', [ __CLASS__, 'cart_item_main_price' ], 10, 2 );
            add_filter( 'wccs_cart_item_main_display_price', [ __CLASS__, 'cart_item_main_display_price' ], 10, 2 );
            add_filter( 'wccs_cart_item_before_discounted_price', [ __CLASS__, 'cart_item_before_discounted_price' ], 10, 2 );
            add_filter( 'wccs_cart_item_prices', [ __CLASS__, 'cart_item_prices' ], 10, 2 );
            // add_filter( 'wccs_live_price_cart_item_prices', [ __CLASS__, 'cart_item_prices' ], 10, 2 );
            // add_filter( 'wccs_live_price_get_prices_quantities', [ __CLASS__, 'cart_item_prices' ], 10, 2 );
            // add_filter( 'wccs_live_price_cart_item_discounted_price', [ __CLASS__, 'cart_item_discounted_price' ], 10, 2 );
            // add_filter( 'wccs_live_price_cart_item_main_price', [ __CLASS__, 'cart_item_discounted_price' ], 10, 2 );
        }

        if ( 'lightweight' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' ) ) {
            add_filter( 'woocommerce_product_get_price', [ __CLASS__, 'maybe_replace_product_price' ], 100, 2 );
            add_filter( 'woocommerce_available_variation', [ __CLASS__, 'modify_available_variation' ], 9999, 3 );
        }
    }

    public static function get_fields_price( $cart_item ) {
        if ( empty( $cart_item['wapf'] ) ) {
            return 0;
        }

        if ( class_exists( '\SW_WAPF_PRO\Includes\Classes\Cart' ) ) {
            if ( isset( $cart_item['wapf_item_price']['options_total'] ) ) {
                return (float) $cart_item['wapf_item_price']['options_total'];
            }

            $pricing = \SW_WAPF_PRO\Includes\Classes\Cart::calculate_cart_item_prices( $cart_item, true );
            if ( false !== $pricing && isset( $pricing['options_total'] ) ) {
                return (float) $pricing['options_total'];
            }

            return 0;
        }

        if ( class_exists( '\SW_WAPF\Includes\Classes\Fields' ) ) {
            $quantity = empty( $cart_item['quantity'] ) ? 1 : wc_stock_amount( $cart_item['quantity'] );
            $options_total = 0;
            foreach ( $cart_item['wapf'] as $field ) {
                if ( empty( $field['price'] ) ) {
                    continue;
                }

                foreach ( $field['price'] as $price ) {
                    if ( empty( $price['value'] ) ) {
                        continue;
                    }

                    $options_total += \SW_WAPF\Includes\Classes\Fields::do_pricing( $price['value'], $quantity );
                }
            }

            return $options_total;
        }

        return 0;
    }

    public static function maybe_replace_product_price( $price, $product ) {
        if ( ! static::is_current_product_page_product( $product ) ) {
            return $price;
        }

        if ( ! $product ) {
            return $price;
        }

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $product );
        if ( null === $adjusted_price ) {
            return $price;
        }

        return apply_filters( 
            'asnp_ewd_compatibility_sw_wapf_maybe_replace_product_price', 
            $adjusted_price, 
            $product,
            $adjusted_price
        );
    }

    public static function modify_available_variation( $available_variation, $product, $variation ) {
        if ( ! static::is_current_product_page_product( $product ) ) {
            return $available_variation;
        }

        if ( empty( $available_variation ) || ! $product || ! $variation ) {
            return $available_variation;
        }

        $adjusted_price = WCCS_Helpers::get_product_discounted_price( $variation );
        if ( is_numeric( $adjusted_price ) && 0 <= $adjusted_price ) {
            $available_variation['display_price'] = wc_get_price_to_display( $variation, [ 'price' => $adjusted_price ] );
        }

        return $available_variation;
    }

    protected static function is_current_product_page_product( $product ) {
        if ( ! $product || ! is_product() || static::is_rendering_mini_cart() ) {
            return false;
        }

        if ( null !== WCCS()->custom_props->get_prop( $product, 'wccs_is_cart_item' ) ) {
            return false;
        }

        $current_product_id = get_queried_object_id();
        if ( ! $current_product_id ) {
            return false;
        }

        $product_id = $product->get_id();
        if ( $product->is_type( 'variation' ) ) {
            $product_id = $product->get_parent_id();
        }

        return (int) $current_product_id === (int) $product_id;
    }

    protected static function is_rendering_mini_cart() {
        return did_action( 'woocommerce_before_mini_cart' ) && ! did_action( 'woocommerce_after_mini_cart' );
    }

    public static function cart_item_discounted_price( $discounted_price, $cart_item ) {
        if ( empty( $cart_item['wapf'] ) ) {
            return $discounted_price;
        }

        return (float) $discounted_price + (float) static::get_fields_price( $cart_item );
    }

    public static function cart_item_main_price( $price, $cart_item ) {
        if ( empty( $cart_item['wapf'] ) ) {
            return $price;
        }

        return (float) $price + (float) static::get_fields_price( $cart_item );
    }

    public static function cart_item_main_display_price( $price, $cart_item ) {
        if ( empty( $cart_item['wapf'] ) ) {
            return $price;
        }

        return (float) $price + (float) static::get_fields_price( $cart_item );
    }

    public static function cart_item_before_discounted_price( $price, $cart_item ) {
        if ( empty( $cart_item['wapf'] ) ) {
            return $price;
        }

        return WCCS()->cart->get_product_price(
            $cart_item['data'],
            array(
                'price' => (float) WCCS()->product_helpers->wc_get_price( $cart_item['data']->get_id() ) + (float) static::get_fields_price( $cart_item ),
            )
        );
    }

    public static function cart_item_prices( $prices, $cart_item ) {
        if ( empty( $cart_item['wapf'] ) || empty( $prices ) ) {
            return $prices;
        }

        $options_prices = (float) static::get_fields_price( $cart_item );
        if ( empty( $options_prices ) ) {
            return $prices;
        }

        $value = array();

        foreach ( $prices as $price => $qty ) {
            $price                    = (float) $price + $options_prices;
            $value[ (string) $price ] = $qty;
        }

        return $value;
    }

}
