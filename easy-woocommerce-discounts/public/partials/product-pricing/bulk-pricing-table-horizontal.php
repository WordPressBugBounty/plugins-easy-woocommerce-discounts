<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! empty( $view_args['table_title'] ) ) {
	echo '<p class="wccs-bulk-pricing-table-title"' . ( ! empty( $view_args['variation'] ) ? ' data-variation="' . esc_attr( $view_args['variation'] ) . '"' : '' ) . ' style="' . ( ! empty( $view_args['variation'] ) ? 'display:none' : '' ) . '"><strong>' . esc_html( $view_args['table_title'] ) . '</strong></p>';
}

$base_price = WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' );

$theme_color  = WCCS()->settings->get_setting( 'quantity_table_theme_color', '#6c7ae0' );
$theme_bg     = WCCS()->settings->get_setting( 'quantity_table_theme_bg', '#f8f6ff' );
$theme_text   = WCCS()->settings->get_setting( 'quantity_table_theme_text', '#6c7ae0' );
$theme_border = WCCS()->settings->get_setting( 'quantity_table_theme_border', '#c3caf7' );
$header_bg    = WCCS()->settings->get_setting( 'quantity_table_header_bg', '#6c7ae0' );
$header_text  = WCCS()->settings->get_setting( 'quantity_table_header_text', '#ffffff' );
$body_bg      = WCCS()->settings->get_setting( 'quantity_table_body_bg', '#ffffff' );
$stripe_bg    = WCCS()->settings->get_setting( 'quantity_table_stripe_bg', '#f8f6ff' );
$body_text    = WCCS()->settings->get_setting( 'quantity_table_body_text', '#808080' );
$hover_bg     = WCCS()->settings->get_setting( 'quantity_table_hover_bg', '#f1f3ff' );

$body_bg_clean = str_replace( '#', '', $body_bg );
if ( strlen( $body_bg_clean ) === 3 ) {
	$r = hexdec( substr( $body_bg_clean, 0, 1 ) . substr( $body_bg_clean, 0, 1 ) );
	$g = hexdec( substr( $body_bg_clean, 1, 1 ) . substr( $body_bg_clean, 1, 1 ) );
	$b = hexdec( substr( $body_bg_clean, 2, 1 ) . substr( $body_bg_clean, 2, 1 ) );
} else {
	$r = hexdec( substr( $body_bg_clean, 0, 2 ) );
	$g = hexdec( substr( $body_bg_clean, 2, 2 ) );
	$b = hexdec( substr( $body_bg_clean, 4, 2 ) );
}
$luminance = ( $r * 0.299 + $g * 0.587 + $b * 0.114 );
$scrollbar_thumb = $luminance < 120 ? $body_text : 'rgba(0, 0, 0, 0.25)';

$custom_vars = sprintf(
	'--asnp-wccs-theme-color: %s; --asnp-wccs-theme-bg: %s; --asnp-wccs-theme-text: %s; --asnp-wccs-theme-border: %s; --asnp-wccs-header-bg: %s; --asnp-wccs-header-text: %s; --asnp-wccs-body-bg: %s; --asnp-wccs-stripe-bg: %s; --asnp-wccs-body-text: %s; --asnp-wccs-scrollbar-thumb: %s; --asnp-wccs-hover-bg: %s;',
	esc_attr( $theme_color ),
	esc_attr( $theme_bg ),
	esc_attr( $theme_text ),
	esc_attr( $theme_border ),
	esc_attr( $header_bg ),
	esc_attr( $header_text ),
	esc_attr( $body_bg ),
	esc_attr( $stripe_bg ),
	esc_attr( $body_text ),
	esc_attr( $scrollbar_thumb ),
	esc_attr( $hover_bg )
);
$enable_selection = WCCS()->settings->get_setting( 'quantity_table_enable_tier_selection', 'yes' );
?>
<div class="asnp-wccs-table-wrapper-outer">
	<div class="wccs-bulk-pricing-table-container asnp-wccs-horizontal-split-container <?php echo 'yes' === $enable_selection ? 'wccs-tier-selection-enabled' : ''; ?>" <?php echo ! empty( $view_args['product_id'] ) ? 'data-product="' . esc_attr( $view_args['product_id'] ) . '"' : '' ?> <?php echo ! empty( $view_args['variation'] ) ? 'data-variation="' . esc_attr( $view_args['variation'] ) . '"' : '' ?> <?php echo 'data-base-price="' . esc_attr( $base_price ) . '"' ?> style="<?php echo ! empty( $view_args['variation'] ) ? 'display:none;' : ''; ?> <?php echo $custom_vars; ?>">
		<div class="asnp-wccs-table-left">
			<table>
				<tbody>
					<?php if ( 'yes' === $view_args['discount']['display_quantity'] ) { ?>
						<tr>
							<th><?php echo esc_html( $view_args['quantity_label'] ) ?></th>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $view_args['discount']['display_discount'] ) { ?>
						<tr>
							<th><?php echo esc_html( $view_args['discount_label'] ) ?></th>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $view_args['discount']['display_price'] ) { ?>
						<tr>
							<th><?php echo esc_html( $view_args['price_label'] ) ?></th>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $enable_selection ) { ?>
						<tr class="wccs-row-select">
							<th><svg stroke="currentColor" fill="none" stroke-width="3" viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round" height="14" width="14" xmlns="http://www.w3.org/2000/svg" style="vertical-align: middle; display: inline-block;"><polyline points="20 6 9 17 4 12"></polyline></svg></th>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
		<div class="asnp-wccs-table-right asnp-wccs-horizontal-scroll">
			<table class="wccs-bulk-pricing-table wccs-horizontal-table" <?php echo ! empty( $view_args['variation'] ) ? 'data-variation="' . esc_attr( $view_args['variation'] ) . '"' : '' ?> <?php echo 'data-base-price="' . esc_attr( $base_price ) . '"' ?>>
				<tbody>
					<?php if ( 'yes' === $view_args['discount']['display_quantity'] ) { ?>
						<tr>
							<?php
							foreach ( $view_args['discount']['quantities'] as $discount ) {
								$price = apply_filters( 'wccs_quantity_table_price', $view_args['controller']->get_discounted_price( $discount['discount'], $discount['discount_type'] ), $view_args );
								$discount_value = apply_filters( 'wccs_quantity_table_discount', $view_args['controller']->get_discount_value_html( $discount['discount'], $discount['discount_type'] ), $view_args );
								echo '<td data-type="quantity" data-quantity-min="' . esc_attr( $discount['min'] ) . '" data-quantity-max="' . ( ! empty( $discount['max'] ) ? esc_attr( $discount['max'] ) : '' ) . '" data-price-html="' . esc_attr( $price ) . '" data-discount="' . esc_attr( $discount['discount'] ) . '" data-discount-type="' . esc_attr( $discount['discount_type'] ) . '" data-discount-html="' . esc_attr( $discount_value ) . '" data-base-price="' . WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' ) . '">' . esc_html( apply_filters( 'wccs_quantity_table_quantity', $discount['min'] . ( ! empty( $discount['max'] ) ? ( $discount['min'] != $discount['max'] ? ' - ' . $discount['max'] : '' ) : ' +' ) ) ) . '</td>';
							}
							?>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $view_args['discount']['display_discount'] ) { ?>
						<tr>
							<?php
							foreach ( $view_args['discount']['quantities'] as $discount ) {
								echo '<td data-type="discount" data-quantity-min="' . esc_attr( $discount['min'] ) . '" data-quantity-max="' . ( ! empty( $discount['max'] ) ? esc_attr( $discount['max'] ) : '' ) . '">' . apply_filters( 'wccs_quantity_table_discount', $view_args['controller']->get_discount_value_html( $discount['discount'], $discount['discount_type'] ) ) . '</td>';
							}
							?>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $view_args['discount']['display_price'] ) { ?>
						<tr>
							<?php
							foreach ( $view_args['discount']['quantities'] as $discount ) {
								echo '<td data-type="price" data-quantity-min="' . esc_attr( $discount['min'] ) . '" data-quantity-max="' . ( ! empty( $discount['max'] ) ? esc_attr( $discount['max'] ) : '' ) . '">' . apply_filters( 'wccs_quantity_table_price', $view_args['controller']->get_discounted_price( $discount['discount'], $discount['discount_type'] ) ) . '</td>';
							}
							?>
						</tr>
					<?php } ?>
					<?php if ( 'yes' === $enable_selection ) { ?>
						<tr class="wccs-row-select">
							<?php
							foreach ( $view_args['discount']['quantities'] as $discount ) {
								echo '<td style="text-align: center !important;"><input type="radio" name="wccs_tier_select_' . esc_attr( $view_args['product_id'] ) . '" class="wccs-tier-radio" /></td>';
							}
							?>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
