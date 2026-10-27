<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

global $post, $product;

$is_shortcode = isset( $view_args['controller'] ) && is_a( $view_args['controller'], 'WCCS_Shortcode_Sale_Flash' );

if ( ! $product || ( ! $is_shortcode && $product->is_on_sale() ) ) {
    return;
}

$onsale_status = WCCS()->WCCS_Product_Onsale_Cache->get_onsale_status( $product );
$is_on_sale    = ( 'no' !== $onsale_status || $product->is_on_sale() );

if ( ! $is_on_sale ) {
    return;
}

$type = WCCS()->settings->get_setting( 'sale_badge_type', 'sale' );

if ( 'discount' === $type && ( 'yes_simple' === $onsale_status || $product->is_on_sale( 'edit' ) ) ) {
    if ( $discount = WCCS()->product_helpers->get_percentage_badge_value( $product ) ) {
        $html = '<span class="onsale wccs-onsale-badge wccs-onsale-badge-discount">';
        $html .= apply_filters( 'wccs_sale_flash_negative_symbol', '<span class="wccs-sale-flash-negative-symbol">-</span>' )
                . esc_html( apply_filters( 'wccs_sale_flash_percentage_value', round( $discount ), $discount ) )
                . apply_filters( 'wccs_sale_flash_percentage_symbol', '<span class="wccs-sale-flash-percentage-symbol">%</span>' );
        $html .= '</span>';
        echo apply_filters( 'wccs_sale_flash_discount_value', $html, $discount, $product, $post );
        return;
    }
}

echo apply_filters( 'wccs_sale_flash', '<span class="onsale wccs-onsale-badge">' . esc_html__( 'Sale!', 'woocommerce' ) . '</span>', $post, $product );
