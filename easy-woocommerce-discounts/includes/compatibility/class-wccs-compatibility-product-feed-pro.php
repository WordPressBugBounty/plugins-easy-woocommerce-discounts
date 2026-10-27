<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Compatibility_Product_Feed_Pro {

    protected $loader;

    protected $enabled_change_price = false;

    public function __construct( WCCS_Loader $loader ) {
        $this->loader = $loader;
    }

    public function init() {
        // $this->loader->add_action( 'woosea_cron_hook', $this, 'enable_change_price_hooks', 1 );
        // $this->loader->add_action( 'woosea_create_batch_event', $this, 'enable_change_price_hooks', 0 );
        // $this->loader->add_action( 'wccs_product_price_replace_should_replace', $this, 'should_replace', 10, 4 );
        add_filter( 'adt_get_product_data', array( $this, 'maybe_replace_product_price' ), 10, 3 );
    }

    public function maybe_replace_product_price( $product_data, $feed, $product ) {
        if ( ! $product ) {
            return $product_data;
        }

        $discount = WCCS_Helpers::get_product_discounted_price( $product );
        if ( null === $discount ) {
            return $product_data;
        }

        // Set country code.
        $country_code = ! empty( $feed->country ) ? $feed->country : '';

        $all_standard_taxes = WC_Tax::get_rates_for_tax_class( '' );
        $nr_standard_rates  = count( $all_standard_taxes );
        if ( ! empty( $all_standard_taxes ) && ( $nr_standard_rates > 1 ) ) {
            foreach ( $all_standard_taxes as $rate ) {
                $rate_arr = get_object_vars( $rate );
                if ( $rate_arr['tax_rate_country'] == $country_code ) {
                    $tax_rates[1]['rate'] = $rate_arr['tax_rate'];
                }
                unset( $rate_arr );
            }
        } elseif ( ! empty( $tax_rates ) ) {
            foreach ( $tax_rates as $tk => $tv ) {
                if ( $tv['rate'] > 0 ) {
                    $tax_rates[1]['rate'] = $tv['rate'];
                } else {
                    $tax_rates[1]['rate'] = 0;
                }
            }
        } else {
            $tax_rates[1]['rate'] = 0;
        }

        if ( empty( $tax_rates[1]['rate'] ) ) {
            if ( ! empty( $all_standard_taxes ) && ( $nr_standard_rates > 1 ) ) {
                foreach ( $all_standard_taxes as $rate ) {
                    $rate_arr = get_object_vars( $rate );
                    if ( $rate_arr['tax_rate_country'] == '' ) {
                        $tax_rates[1]['rate'] = $rate_arr['tax_rate'];
                    }
                    unset( $rate_arr );
                }
            }
        }
        // unset($all_standard_taxes);

        $tax_rates_first = reset( $tax_rates );
        $fullrate        = ! empty( $tax_rates_first ) ? 100 + $tax_rates_first['rate'] : 100;
        $regular_price   = $product_data['regular_price'];

        $decimals = wc_get_price_decimals();
        if ( $decimals < 1 ) {
            $discount                      = round( $discount, 0 );
            $product_data['sale_price']    = round( $discount, 0 );
            $product_data['price']         = $discount;
            $product_data['regular_price'] = $discount > $product_data['regular_price'] ? round( $discount, 0 ) : round( $product_data['regular_price'], 0 );
        } else {
            $discount                   = round( $discount, 2 );
            $product_data['sale_price'] = @number_format( $discount, 2 );
            $product_data['price']      = $discount;
            if ( $discount > $product_data['regular_price'] ) {
                $product_data['regular_price'] = $discount;
            }
        }

        $price_incl_tax = get_option( 'woocommerce_prices_include_tax' );
        if ( $price_incl_tax == 'yes' ) {
            $product_data['price_forced']              = $product_data['price'] * ( $fullrate / 100 );
            $product_data['price_forced_rounded']      = round( $product_data['price_forced'], 0 );
            $product_data['net_price']                 = $product_data['price'] / ( $fullrate / 100 );
            $product_data['net_price_rounded']         = round( $product_data['net_price'] ); // New Nov. 1st 2023
            $product_data['net_regular_price']         = $product_data['regular_price'] / ( $fullrate / 100 );
            $product_data['net_regular_price_rounded'] = round( $product_data['net_regular_price'], 0 ); // New Nov. 1st 2023
            if ( $discount < $regular_price ) {
                $product_data['net_sale_price']            = ( $discount / $fullrate ) * 100; // New Nov. 1st 2023
                $product_data['net_sale_price_rounded']    = round( $product_data['net_sale_price'], 0 ); // New Nov. 1st 2023
                $product_data['sale_price_forced']         = $discount * ( $fullrate / 100 );
                $product_data['sale_price_forced_rounded'] = round( $product_data['sale_price_forced'], 0 );
            }
        } elseif ( $discount < $regular_price ) {
            $product_data['net_sale_price']            = $discount;
            $product_data['sale_price_forced']         = round( $discount * ( $fullrate / 100 ), 2 );
            $product_data['sale_price_forced_rounded'] = round( $product_data['sale_price_forced'], 0 );
        }

        $thousand_separator = wc_get_price_thousand_separator();
        if ( $thousand_separator != ',' ) {
            $replaceWith                   = '';
            $product_data['price']         = preg_replace( '/,/', $replaceWith, $product_data['price'], 1 );
            $product_data['regular_price'] = preg_replace( '/,/', $replaceWith, $product_data['regular_price'], 1 );
            if ( isset( $product_data['sale_price'] ) && $product_data['sale_price'] > 0 ) {
                $product_data['sale_price'] = preg_replace( '/,/', $replaceWith, $product_data['sale_price'], 1 );
            }
        }
        
        return $product_data;
    }

    public function enable_change_price_hooks() {
        if ( $this->enabled_change_price ) {
            return;
        }

        $pricing = WCCS()->container()->get( 'pricing' );
        if ( ! $pricing ) {
            $pricing = new WCCS_Pricing( WCCS_Conditions_Provider::get_pricings( array( 'status' => 1, 'cache' => true ) ) );
            WCCS()->container()->set( 'pricing', $pricing );
        }

        $pricing_hooks = WCCS()->container()->get( 'WCCS_Public_Pricing_Hooks', array( $this->loader ) );
        if ( $pricing_hooks ) {
            $pricing_hooks->enable_change_price_hooks();
        }

        $this->enabled_change_price = true;
    }

    public function should_replace( $value, $price, $product, $price_type ) {
        if ( $value || ! $this->enabled_change_price ) {
            return $value;
        }

        if ( $product->is_type( 'variable' ) ) {
            return false;
        }

        if ( '' === $price && 'price' === $price_type ) {
            return false;
        }

        // Do not replace price for cart items.
        if ( null !== WCCS()->custom_props->get_prop( $product, 'wccs_is_cart_item' ) ) {
            return false;
        }

        return true;
    }

}
