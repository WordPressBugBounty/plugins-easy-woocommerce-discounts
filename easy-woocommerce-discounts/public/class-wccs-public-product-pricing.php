<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Public_Product_Pricing extends WCCS_Public_Controller {

	protected $pricing;

	protected $apply_method;

	protected $simple_discounts;

	protected $simple_fees;

	protected $bulk_pricings;

	protected $tiered_pricings;

	protected $purchase_pricings;

	protected $is_in_excludes;

	public $product;

	public $product_id;

	public $parent_id;

	public function __construct( $product, $pricing = null, $apply_method = '' ) {
		if ( is_numeric( $product ) ) {
			$this->product = wc_get_product( $product );
		} else {
			$this->product = $product;
		}

		$wccs = WCCS();

		$this->product_id = $this->product->get_id();
		$this->parent_id = $this->product->is_type( 'variation' ) ? $wccs->product_helpers->get_parent_id( $this->product ) : $this->product_id;
		$this->pricing = null === $pricing ? WCCS()->pricing : $pricing;
		$this->apply_method = ! empty( $apply_method ) ? $apply_method : $wccs->settings->get_setting( 'product_pricing_discount_apply_method', 'sum' );
	}

	public function get_price_html( $price = '' ) {
		if ( 'advanced' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' ) ) {
			return $this->advanced_get_price_html( $price );
		}

		do_action( 'wccs_public_product_pricing_before_get_price_html', $this, $price );

		if (
			'all' === WCCS()->settings->get_setting( 'change_display_price', 'simple' ) &&
			! is_single( $this->parent_id )
		) {
			$bulk = $this->get_bulk_price_html( $price );
			if ( ! empty( $bulk ) ) {
				do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
				return apply_filters( 'wccs_public_product_pricing_' . __FUNCTION__, $bulk, $this->product, $price );
			}
		}

		if ( $this->product->is_type( 'variable' ) ) {
			$prices = $this->product->get_variation_prices( true );
			if ( empty( $prices['regular_price'] ) ) {
				return $price;
			}

			$variable_regular_prices = apply_filters(
				'wccs_product_pricing_variable_regular_prices',
				array(
					'min' => current( $prices['regular_price'] ),
					'max' => end( $prices['regular_price'] ),
				),
				$this->product,
				$this
			);
			$min_price = isset( $variable_regular_prices['min'] ) ? $variable_regular_prices['min'] : current( $prices['regular_price'] );
			$max_price = isset( $variable_regular_prices['max'] ) ? $variable_regular_prices['max'] : end( $prices['regular_price'] );

			$optimized_ids = WCCS_Helpers::get_archive_optimized_variation_ids( $this->product );

			if ( false !== $optimized_ids ) {
				$min_variation = wc_get_product( $optimized_ids[0] );
				$max_variation = isset( $optimized_ids[1] ) ? wc_get_product( $optimized_ids[1] ) : $min_variation;

				$min_raw_discounted = WCCS_Helpers::get_product_discounted_price( $min_variation, null, true, $this );
				$min_discounted     = $min_raw_discounted;
				if ( is_numeric( $min_discounted ) && 0 <= $min_discounted ) {
					$min_discounted = wc_get_price_to_display( $min_variation, array( 'price' => $min_discounted ) );
				}

				$max_raw_discounted = WCCS_Helpers::get_product_discounted_price( $max_variation, null, true, $this );
				$max_discounted     = $max_raw_discounted;
				if ( is_numeric( $max_discounted ) && 0 <= $max_discounted ) {
					$max_discounted = wc_get_price_to_display( $max_variation, array( 'price' => $max_discounted ) );
				}

				if ( ( ! is_numeric( $min_discounted ) || 0 > $min_discounted ) && ( ! is_numeric( $max_discounted ) || 0 > $max_discounted ) ) {
					return $price;
				} elseif ( ! is_numeric( $min_discounted ) || 0 > $min_discounted ) {
					$min_discounted = $max_discounted;
				} elseif ( ! is_numeric( $max_discounted ) || 0 > $max_discounted ) {
					$max_discounted = $min_discounted;
				}

				if ( $min_discounted > $max_discounted ) {
					$temp = $min_discounted;
					$min_discounted = $max_discounted;
					$max_discounted = $temp;
				}

				if ( (float) $min_price == (float) $min_discounted && (float) $max_price == (float) $max_discounted ) {
					if ( false !== strpos( $price, '<del' ) ) {
						$clean_price = ( $min_price !== $max_price ) ? WCCS_Helpers::wc_format_price_range( $min_price, $max_price ) : wc_price( $min_price );
						return $clean_price . $this->product->get_price_suffix();
					}
					return $price;
				}

				if ( $min_price !== $max_price ) {
					$display_price = WCCS_Helpers::wc_format_price_range( $min_price, $max_price );
				} else {
					$display_price = wc_price( $min_price );
				}

				$display_price = apply_filters(
					'wccs_product_pricing_variable_display_regular_price',
					$display_price,
					$min_price,
					$max_price,
					$this->product,
					$this
				);

				if ( $min_discounted !== $max_discounted ) {
					$discounted_price = WCCS_Helpers::wc_format_price_range( $min_discounted, $max_discounted );
				} else {
					$discounted_price = wc_price( $min_discounted );
				}

				$price_suffix = $this->product->get_price_suffix( is_numeric( $min_raw_discounted ) && 0 <= $min_raw_discounted ? $min_raw_discounted : '' );

				if ( (float) $min_price > (float) $min_discounted || (float) $max_price > (float) $max_discounted ) {
					if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
						$discounted_price = $discounted_price . $price_suffix;
					} else {
						$discounted_price = '<del aria-hidden="true">' . $display_price . '</del> <ins>' . $discounted_price . '</ins>' . $price_suffix;
					}
				} else {
					$discounted_price = $discounted_price . $price_suffix;
				}
			} else {
				$product_discounted_price = WCCS()->WCCS_Product_Price_Replace->get_discounted_variation_prices( $this->product, true );

				$min_product_discounted_price = apply_filters(
					'wccs_public_product_pricing_get_price_html_min_variation_price',
					current( $product_discounted_price['price'] ),
					key( $product_discounted_price['price'] ),
					$price,
					$this
				);
				$max_product_discounted_price = apply_filters(
					'wccs_public_product_pricing_get_price_html_max_variation_price',
					end( $product_discounted_price['price'] ),
					key( $product_discounted_price['price'] ),
					$price,
					$this
				);

				$min_orig_price = current( $prices['price'] );
				$max_orig_price = end( $prices['price'] );
				if ( ( (float) $min_price == $min_product_discounted_price && (float) $max_price == $max_product_discounted_price ) ||
				     ( (float) $min_orig_price == $min_product_discounted_price && (float) $max_orig_price == $max_product_discounted_price ) ) {
					if ( false !== strpos( $price, '<del' ) ) {
						$clean_price = ( $min_price !== $max_price ) ? WCCS_Helpers::wc_format_price_range( $min_price, $max_price ) : wc_price( $min_price );
						return $clean_price . $this->product->get_price_suffix();
					}
					return $price;
				}

				if ( $min_price !== $max_price ) {
					$display_price = WCCS_Helpers::wc_format_price_range( $min_price, $max_price );
				} else {
					$display_price = wc_price( $min_price );
				}

				$display_price = apply_filters(
					'wccs_product_pricing_variable_display_regular_price',
					$display_price,
					$min_price,
					$max_price,
					$this->product,
					$this
				);

				if ( $min_product_discounted_price !== $max_product_discounted_price ) {
					$discounted_price = WCCS_Helpers::wc_format_price_range( $min_product_discounted_price, $max_product_discounted_price );
				} else {
					$discounted_price = wc_price( $min_product_discounted_price );
				}

				$min_product_raw_price = current( $product_discounted_price['price'] );
				$price_suffix = $this->product->get_price_suffix( is_numeric( $min_product_raw_price ) ? $min_product_raw_price : '' );

				if ( (float) $min_price > $min_product_discounted_price || (float) $max_price > $max_product_discounted_price ) {
					if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
						$discounted_price = $discounted_price . $price_suffix;
					} else {
						$discounted_price = '<del aria-hidden="true">' . $display_price . '</del> <ins>' . $discounted_price . '</ins>' . $price_suffix;
					}
				} else {
					$discounted_price = $discounted_price . $price_suffix;
				}
			}
		} else {
			$product_discounted_price = WCCS_Helpers::get_product_discounted_price( $this->product, null, true, $this );
			if ( ! is_numeric( $product_discounted_price ) || 0 > $product_discounted_price ) {
				return $price;
			}

			$raw_discounted_price     = $product_discounted_price;
			$product_discounted_price = wc_get_price_to_display( $this->product, [ 'price' => $product_discounted_price ] );
			$display_price = apply_filters(
				'wccs_product_pricing_display_regular_price',
				wc_get_price_to_display( $this->product, $this->product->is_on_sale( 'edit' ) ? array( 'price' => $this->product->get_regular_price() ) : array() ),
				$this->product,
				$this
			);

			if ( $product_discounted_price == $display_price ) {
				if ( false !== strpos( $price, '<del' ) ) {
					return wc_price( $display_price ) . $this->product->get_price_suffix( $raw_discounted_price );
				}
				return $price;
			}
			
			$price_suffix = $this->product->get_price_suffix( $raw_discounted_price );

			if ( $product_discounted_price < $display_price ) {
				if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
					$discounted_price = wc_price( $product_discounted_price ) . $price_suffix;
				} else {
					$discounted_price = wc_format_sale_price( wc_price( $display_price ), wc_price( $product_discounted_price ) ) . $price_suffix;
				}
			} else {
				$discounted_price = wc_price( $product_discounted_price ) . $price_suffix;
			}
		}

		do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );

		return apply_filters( 'wccs_product_pricing_get_price_html', $discounted_price, $this->product, $price );
	}

	public function advanced_get_price_html( $price = '' ) {
		do_action( 'wccs_public_product_pricing_before_get_price_html', $this, $price );

		if (
			'all' === WCCS()->settings->get_setting( 'change_display_price', 'simple' ) &&
			! is_single( $this->parent_id )
		) {
			$bulk = $this->get_bulk_price_html( $price );
			if ( ! empty( $bulk ) ) {
				do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
				return apply_filters( 'wccs_public_product_pricing_' . __FUNCTION__, $bulk, $this->product, $price );
			}
		}

		if ( $this->product->is_type( 'variable' ) ) {
			$prices = $this->product->get_variation_prices( true );
			if ( empty( $prices['regular_price'] ) ) {
				do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
				return $price;
			}

			$variable_regular_prices = apply_filters(
				'wccs_product_pricing_variable_regular_prices',
				array(
					'min' => current( $prices['regular_price'] ),
					'max' => end( $prices['regular_price'] ),
				),
				$this->product,
				$this
			);
			$min_price = isset( $variable_regular_prices['min'] ) ? $variable_regular_prices['min'] : current( $prices['regular_price'] );
			$max_price = isset( $variable_regular_prices['max'] ) ? $variable_regular_prices['max'] : end( $prices['regular_price'] );

			$optimized_ids = WCCS_Helpers::get_archive_optimized_variation_ids( $this->product );

			if ( false !== $optimized_ids ) {
				$min_variation = wc_get_product( $optimized_ids[0] );
				$max_variation = isset( $optimized_ids[1] ) ? wc_get_product( $optimized_ids[1] ) : $min_variation;

				$min_raw_discounted = WCCS_Helpers::get_product_discounted_price( $min_variation, null, true, $this );
				$min_discounted     = $min_raw_discounted;
				if ( is_numeric( $min_discounted ) && 0 <= $min_discounted ) {
					$min_discounted = wc_get_price_to_display( $min_variation, array( 'price' => $min_discounted ) );
				}

				$max_raw_discounted = WCCS_Helpers::get_product_discounted_price( $max_variation, null, true, $this );
				$max_discounted     = $max_raw_discounted;
				if ( is_numeric( $max_discounted ) && 0 <= $max_discounted ) {
					$max_discounted = wc_get_price_to_display( $max_variation, array( 'price' => $max_discounted ) );
				}

				if ( ( ! is_numeric( $min_discounted ) || 0 > $min_discounted ) && ( ! is_numeric( $max_discounted ) || 0 > $max_discounted ) ) {
					return $price;
				} elseif ( ! is_numeric( $min_discounted ) || 0 > $min_discounted ) {
					$min_discounted = $max_discounted;
				} elseif ( ! is_numeric( $max_discounted ) || 0 > $max_discounted ) {
					$max_discounted = $min_discounted;
				}

				if ( $min_discounted > $max_discounted ) {
					$temp = $min_discounted;
					$min_discounted = $max_discounted;
					$max_discounted = $temp;
				}

				if ( (float) $min_price == (float) $min_discounted && (float) $max_price == (float) $max_discounted ) {
					if ( false !== strpos( $price, '<del' ) ) {
						$clean_price = ( $min_price !== $max_price ) ? WCCS_Helpers::wc_format_price_range( $min_price, $max_price ) : wc_price( $min_price );
						$price = $clean_price . $this->product->get_price_suffix();
					}
					do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
					return $price;
				}

				if ( $min_price !== $max_price ) {
					$display_price = WCCS_Helpers::wc_format_price_range( $min_price, $max_price );
				} else {
					$display_price = wc_price( $min_price );
				}

				$display_price = apply_filters(
					'wccs_product_pricing_variable_display_regular_price',
					$display_price,
					$min_price,
					$max_price,
					$this->product,
					$this
				);

				if ( $min_discounted !== $max_discounted ) {
					$discounted_price = WCCS_Helpers::wc_format_price_range( $min_discounted, $max_discounted );
				} else {
					$discounted_price = wc_price( $min_discounted );
				}

				$price_suffix = $this->product->get_price_suffix( is_numeric( $min_raw_discounted ) && 0 <= $min_raw_discounted ? $min_raw_discounted : '' );

				if ( (float) $min_price > (float) $min_discounted || (float) $max_price > (float) $max_discounted ) {
					if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
						$discounted_price = $discounted_price . $price_suffix;
					} else {
						$discounted_price = '<del aria-hidden="true">' . $display_price . '</del> <ins>' . $discounted_price . '</ins>' . $price_suffix;
					}
				} else {
					$discounted_price = $discounted_price . $price_suffix;
				}
			} else {
				$product_discounted_price = WCCS()->product_helpers->wc_get_variation_prices( $this->product, true, false );
				if ( empty( $product_discounted_price['price'] ) ) {
					do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
					return $price;
				}

				$min_product_discounted_price = apply_filters(
					'wccs_public_product_pricing_get_price_html_min_variation_price',
					current( $product_discounted_price['price'] ),
					key( $product_discounted_price['price'] ),
					$price,
					$this
				);
				$max_product_discounted_price = apply_filters(
					'wccs_public_product_pricing_get_price_html_max_variation_price',
					end( $product_discounted_price['price'] ),
					key( $product_discounted_price['price'] ),
					$price,
					$this
				);

				$min_orig_price = current( $prices['price'] );
				$max_orig_price = end( $prices['price'] );
				if ( ( (float) $min_price == $min_product_discounted_price && (float) $max_price == $max_product_discounted_price ) ||
				     ( (float) $min_orig_price == $min_product_discounted_price && (float) $max_orig_price == $max_product_discounted_price ) ) {
					if ( false !== strpos( $price, '<del' ) ) {
						$clean_price = ( $min_price !== $max_price ) ? WCCS_Helpers::wc_format_price_range( $min_price, $max_price ) : wc_price( $min_price );
						$price = $clean_price . $this->product->get_price_suffix();
					}
					do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
					return $price;
				}

				if ( $min_price !== $max_price ) {
					$display_price = WCCS_Helpers::wc_format_price_range( $min_price, $max_price );
				} else {
					$display_price = wc_price( $min_price );
				}

				$display_price = apply_filters(
					'wccs_product_pricing_variable_display_regular_price',
					$display_price,
					$min_price,
					$max_price,
					$this->product,
					$this
				);

				if ( $min_product_discounted_price !== $max_product_discounted_price ) {
					$discounted_price = WCCS_Helpers::wc_format_price_range( $min_product_discounted_price, $max_product_discounted_price );
				} else {
					$discounted_price = wc_price( $min_product_discounted_price );
				}

				$min_product_raw_price = current( $product_discounted_price['price'] );
				$price_suffix          = $this->product->get_price_suffix( is_numeric( $min_product_raw_price ) && 0 <= $min_product_raw_price ? $min_product_raw_price : '' );

				if ( (float) $min_price > $min_product_discounted_price || (float) $max_price > $max_product_discounted_price ) {
					if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
						$discounted_price = $discounted_price . $price_suffix;
					} else {
						$discounted_price = '<del aria-hidden="true">' . $display_price . '</del> <ins>' . $discounted_price . '</ins>' . $price_suffix;
					}
				} else {
					$discounted_price = $discounted_price . $price_suffix;
				}
			}
		} else {
			$display_price = apply_filters(
				'wccs_product_pricing_display_regular_price',
				WCCS()->product_helpers->wc_get_price_to_display( $this->product, $this->product->is_on_sale( 'edit' ) ? array( 'price' => WCCS()->product_helpers->wc_get_regular_price( $this->product ) ) : array() ),
				$this->product,
				$this
			);
			$product_discounted_price = WCCS()->product_helpers->wc_get_price_to_display( $this->product, array(), false );
			if ( $product_discounted_price < 0 || $product_discounted_price == $display_price || false === $product_discounted_price ) {
				if ( false !== strpos( $price, '<del' ) && $product_discounted_price == $display_price ) {
					$price = wc_price( $display_price ) . $this->product->get_price_suffix( $this->product->get_price() );
				}
				do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
				return $price;
			}

			if ( $this->product->is_on_sale( 'edit' ) && (float) $product_discounted_price === (float) wc_get_price_to_display( $this->product ) ) {
				do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );
				return $price;
			}

			$price_suffix = $this->product->get_price_suffix( $this->product->get_price() );

			if ( $product_discounted_price < $display_price ) {
				if ( WCCS_Helpers::is_change_display_price_ex_html() && ! $this->product->is_on_sale( 'edit' ) ) {
					$discounted_price = wc_price( $product_discounted_price ) . $price_suffix;
				} else {
					$discounted_price = wc_format_sale_price( wc_price( $display_price ), wc_price( $product_discounted_price ) ) . $price_suffix;
				}
			} else {
				$discounted_price = wc_price( $product_discounted_price ) . $price_suffix;
			}
		}

		do_action( 'wccs_public_product_pricing_after_get_price_html', $this, $price );

		return apply_filters( 'wccs_product_pricing_get_price_html', $discounted_price, $this->product, $price );
	}

	/**
	 * Get bulk pricing HTML for the product.
	 *
	 * Uses high-performance cache-first resolution: checks local and object cache BEFORE any rule evaluations.
	 *
	 * @param string $price
	 * @return string|false
	 */
	public function get_bulk_price_html( $price = '' ) {
		if ( $this->product->is_type( 'variation' ) ) {
			return false;
		}

		// Fast exit: if no bulk pricing rules exist anywhere in the store, skip immediately.
		if ( ! $this->pricing->has_bulk_rules() ) {
			return false;
		}

		$base_price = $this->get_base_price();
		$currency   = get_woocommerce_currency();

		// 1. FAST CACHE CHECK FIRST: zero rule evaluation, zero exclusion check on cache hit!
		$cached = WCCS()->WCCS_Product_Price_Cache->get_cached_bulk_price_html( $this->product_id, $base_price, $currency );
		if ( false !== $cached ) {
			return 'false' === $cached ? false : $cached;
		}

		// 2. CACHE MISS: Only evaluate exclusions, stock, and rules on cache miss
		if ( $this->is_in_exclude_rules() ) {
			WCCS()->WCCS_Product_Price_Cache->cache_bulk_price_html( $this->product, 'false', $base_price, $currency );
			return false;
		}

		$manage_stock   = $this->product->get_manage_stock();
		$stock_setting  = (int) WCCS()->settings->get_setting( 'quantity_table_stock_management', 0 );
		$stock_quantity = $this->product->get_stock_quantity();

		if ( $manage_stock && $stock_setting && 1 >= $stock_quantity ) {
			return false;
		}

		$rules = $this->get_bulk_pricings();
		$rule  = ! empty( $rules ) ? current( $rules ) : null;

		if ( empty( $rule ) || empty( $rule['quantities'] ) ) {
			// Persist negative result if safe (e.g. product has no bulk rules)
			WCCS()->WCCS_Product_Price_Cache->cache_bulk_price_html( $this->product, 'false', $base_price, $currency );
			return false;
		}

		$quantities = $rule['quantities'];
		$min_tier   = $quantities[ count( $quantities ) - 1 ];
		$max_tier   = $quantities[0];

		$regular_price = '';
		$prices        = array();

		if ( $this->product->is_type( 'variable' ) ) {
			$variable_prices = WCCS()->product_helpers->wc_get_variation_prices( $this->product, true );
			if ( empty( $variable_prices['price'] ) ) {
				return false;
			}

			if ( ! isset( $max_tier['min'] ) || 1 != $max_tier['min'] ) {
				$prices[] = current( $variable_prices['price'] );
				$prices[] = end( $variable_prices['price'] );
			} elseif ( isset( $max_tier['min'] ) && 1 == $max_tier['min'] && 0 == $max_tier['discount'] ) {
				if ( 'fixed_price' !== $max_tier['discount_type'] ) {
					$prices[] = current( $variable_prices['price'] );
					$prices[] = end( $variable_prices['price'] );
				}
			}

			$this->collect_tier_prices( $min_tier, $prices );
			$this->collect_tier_prices( $max_tier, $prices );

			$variable_regular_prices = apply_filters(
				'wccs_product_pricing_variable_regular_prices',
				array(
					'min' => current( $variable_prices['regular_price'] ),
					'max' => end( $variable_prices['regular_price'] ),
				),
				$this->product,
				$this
			);
			$min_reg = isset( $variable_regular_prices['min'] ) ? $variable_regular_prices['min'] : current( $variable_prices['regular_price'] );
			$max_reg = isset( $variable_regular_prices['max'] ) ? $variable_regular_prices['max'] : end( $variable_prices['regular_price'] );
			if ( $min_reg !== $max_reg ) {
				$regular_price = WCCS_Helpers::wc_format_price_range( $min_reg, $max_reg );
			} else {
				$regular_price = wc_price( $min_reg );
			}

			$regular_price = apply_filters(
				'wccs_product_pricing_variable_display_regular_price',
				$regular_price,
				$min_reg,
				$max_reg,
				$this->product,
				$this
			);
		} else {
			$min_discounted = $this->calculate_discounted_price( $min_tier['discount'], $min_tier['discount_type'] );
			if ( false !== $min_discounted ) {
				$prices[] = $min_discounted;
			}

			$max_discounted = $this->calculate_discounted_price( $max_tier['discount'], $max_tier['discount_type'] );
			if ( false !== $max_discounted ) {
				$prices[] = $max_discounted;
			}

			$display_price = apply_filters(
				'wccs_product_pricing_display_regular_price',
				WCCS()->product_helpers->wc_get_price_to_display( $this->product, array( 'price' => WCCS()->product_helpers->wc_get_regular_price( $this->product ) ) ),
				$this->product,
				$this
			);

			if ( ! isset( $max_tier['min'] ) || 1 != $max_tier['min'] ) {
				$prices[] = apply_filters(
					'wccs_get_bulk_price_html_display_price',
					WCCS()->product_helpers->wc_get_price_to_display( $this->product ),
					$this->product,
					$price,
					$this
				);
			} elseif ( isset( $max_tier['min'] ) && 1 == $max_tier['min'] && 0 == $max_tier['discount'] ) {
				if ( 'fixed_price' !== $max_tier['discount_type'] ) {
					$prices[] = apply_filters(
						'wccs_get_bulk_price_html_display_price',
						WCCS()->product_helpers->wc_get_price_to_display( $this->product ),
						$this->product,
						$price,
						$this
					);
				}
			}

			$regular_price = $display_price;
		}

		$prices = array_filter( $prices );
		if ( empty( $prices ) ) {
			return false;
		}

		$from = min( $prices );
		$to   = max( $prices );

		$content = '';
		if ( isset( $from ) && isset( $to ) && $from != $to ) {
			$content = wc_format_price_range( $from, $to );
		} elseif ( isset( $from ) ) {
			$content = wc_price( $from );
		} elseif ( isset( $to ) ) {
			$content = wc_price( $to );
		}

		if ( '' !== $regular_price ) {
			if ( $this->product->is_type( 'variable' ) ) {
				if ( $min_reg < $from || $max_reg > $to ) {
					$content = '<del aria-hidden="true">' . $regular_price . '</del> <ins>' . $content . '</ins>';
				}
			} elseif ( $regular_price < $from || $regular_price > $to ) {
				$content = '<del aria-hidden="true">' . wc_price( $regular_price ) . '</del> <ins>' . $content . '</ins>';
			}
		}

		$content .= $this->product->get_price_suffix();

		$content = apply_filters( 'wccs_public_product_pricing_' . __FUNCTION__, $content, $prices, $this, $price );

		// 3. Save in cache
		WCCS()->WCCS_Product_Price_Cache->cache_bulk_price_html( $this->product, $content, $base_price, $currency );

		return $content;
	}

	/**
	 * Calculate and collect min/max discounted prices for a quantity tier on variable products.
	 *
	 * @param array $tier
	 * @param array $prices
	 */
	protected function collect_tier_prices( array $tier, array &$prices ) {
		$discount      = isset( $tier['discount'] ) ? $tier['discount'] : 0;
		$discount_type = isset( $tier['discount_type'] ) ? $tier['discount_type'] : '';

		$v_price = $this->calculate_discounted_price( $discount, $discount_type );
		if ( is_array( $v_price ) ) {
			if ( isset( $v_price['min'] ) ) {
				$prices[] = $v_price['min'];
			}
			if ( isset( $v_price['max'] ) ) {
				$prices[] = $v_price['max'];
			}
		} elseif ( false !== $v_price && null !== $v_price ) {
			$prices[] = $v_price;
		}
	}

	/**
	 * Getting price.
	 *
	 * @since  1.0.0
	 *
	 * @return float
	 */
	public function get_price( $base_price = null ) {
		if ( $this->product->is_type( 'variable' ) ) {
			return false;
		}

		if ( $this->is_in_exclude_rules() ) {
			return false;
		}

		// Fix #13 and using get_base_price instead of get_base_price_to_display that caused issues.
		$base_price = null === $base_price ? $this->get_base_price() : $base_price;
		$adjusted_price = $this->apply_simple_discounts( $base_price );

		if ( $base_price != $adjusted_price ) {
			if ( apply_filters( 'wccs_public_product_pricing_apply_adjusted_price', true, $adjusted_price, $this->product ) ) {
				return $adjusted_price;
			}
		}

		return false;
	}

	public function get_base_price( $product = null ) {
		$product = null === $product ? $this->product : $product;

		do_action( 'wccs_public_product_pricing_before_get_base_price', $this );

		$base_price = (float) $product->get_price( 'edit' );
		if ( $product->is_on_sale( 'edit' ) ) {
			if ( 'regular_price' === WCCS()->settings->get_setting( 'on_sale_products_price', 'on_sale_price' ) ) {
				$base_price = (float) $product->get_regular_price( 'edit' );
			}
		}

		do_action( 'wccs_public_product_pricing_after_get_base_price', $this );

		return apply_filters(
			'wccs_public_product_pricing_' . __FUNCTION__,
			$base_price,
			$product,
			$this
		);
	}

	public function get_base_price_to_display( $product = null ) {
		$product = null === $product ? $this->product : $product;

		$args = array( 'price' => $product->get_price( 'edit' ) );
		if ( $product->is_on_sale( 'edit' ) ) {
			if ( 'regular_price' === WCCS()->settings->get_setting( 'on_sale_products_price', 'on_sale_price' ) ) {
				$args['price'] = $product->get_regular_price( 'edit' );
			}
		}

		return apply_filters(
			'wccs_public_product_pricing_' . __FUNCTION__,
			wc_get_price_to_display( $product, $args ),
			$product,
			$this
		);
	}

	/**
	 * Getting product price based on given discount and discount_type.
	 *
	 * @since  1.0.0
	 *
	 * @param  $discount      float
	 * @param  $discount_type string
	 *
	 * @return string
	 */
	public function get_discounted_price( $discount, $discount_type ) {
		$discount = (float) $discount;
		if ( $discount <= 0 || empty( $discount_type ) ) {
			$price = WCCS()->product_helpers->wc_get_price_to_display( $this->product );
			return wc_price( $price );
		}

		do_action( 'wccs_public_product_pricing_before_get_discounted_price', $discount, $discount_type, $this );
		$price = $this->calculate_discounted_price( $discount, $discount_type );
		do_action( 'wccs_public_product_pricing_after_get_discounted_price', $discount, $discount_type, $this );

		if ( false === $price ) {
			$price = WCCS()->product_helpers->wc_get_price_to_display( $this->product );
			return wc_price( $price );
		}

		if ( is_array( $price ) ) {
			if ( $price['min'] !== $price['max'] ) {
				$price = WCCS_Helpers::wc_format_price_range( $price['min'], $price['max'] );
				return $price;
			} else {
				$price = $price['min'];
			}
		}

		return wc_price( $price );
	}

	public function calculate_discounted_price( $discount, $discount_type ) {
		$discount = (float) $discount;
		if ( $discount <= 0 || empty( $discount_type ) ) {
			return false;
		}

		if ( $this->product->is_type( 'variable' ) ) {
			$optimized_ids = WCCS_Helpers::get_archive_optimized_variation_ids( $this->product );
			$variation_ids = false !== $optimized_ids ? $optimized_ids : $this->product->get_visible_children();
			if ( empty( $variation_ids ) ) {
				return false;
			}
			WCCS_Helpers::prime_product_caches( $variation_ids );

			$variable_prices = array();
			foreach ( $variation_ids as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				$base_price = $this->get_base_price( $variation );
				if ( $base_price < 0 ) {
					continue;
				}

				$discount_amount = 0;
				if ( 'percentage_discount' === $discount_type ) {
					if ( $discount / 100 * $base_price > 0 ) {
						$discount_amount = $discount / 100 * $base_price;
					}
				} elseif ( 'price_discount' === $discount_type ) {
					if ( $discount > 0 ) {
						$discount_amount = $discount;
					}
				}

				$variation_price = WCCS()->product_helpers->wc_get_price_to_display( $variation );
				if ( $base_price - $discount_amount >= 0 ) {
					$variation_price = WCCS()->product_helpers->wc_get_price_to_display(
						$variation,
						array(
							'qty' => 1,
							'price' => $base_price - $discount_amount,
						)
					);
					$variation_price = WCCS_Helpers::maybe_exchange_price( $variation_price );
				}

				$variable_prices[ $variation_id ] = apply_filters(
					'wccs_public_product_pricing_get_discounted_price_variation',
					$variation_price,
					$variation_id,
					$variation,
					$discount,
					$discount_type,
					$this
				);
			}

			if ( ! empty( $variable_prices ) ) {
				$min_price = min( $variable_prices );
				$max_price = max( $variable_prices );

				return array(
					'min' => $min_price,
					'max' => $max_price,
				);
			}
		} // End if().
		// Simple and Variation product.
		else {
			$base_price = $this->get_base_price();
			$discount_amount = 0;
			if ( 'percentage_discount' === $discount_type ) {
				if ( $discount / 100 * $base_price > 0 ) {
					$discount_amount = $discount / 100 * $base_price;
				}
			} elseif ( 'price_discount' === $discount_type ) {
				if ( $discount > 0 ) {
					$discount_amount = $discount;
				}
			}

			$price = WCCS()->product_helpers->wc_get_price_to_display( $this->product );
			if ( $base_price - $discount_amount >= 0 ) {
				$price = WCCS()->product_helpers->wc_get_price_to_display(
					$this->product,
					array(
						'qty' => 1,
						'price' => $base_price - $discount_amount,
					)
				);
				$price = WCCS_Helpers::maybe_exchange_price( $price );
			}

			return apply_filters(
				'wccs_public_product_pricing_get_discounted_price_product',
				$price,
				$this->product,
				$discount,
				$discount_type,
				$this
			);

		}

		return false;
	}

	/**
	 * Get discount value html.
	 *
	 * @since  2.8.0
	 *
	 * @param  float  $discount
	 * @param  string $discount_type
	 *
	 * @return string
	 */
	public function get_discount_value_html( $discount, $discount_type ) {
		$discount = (float) $discount;
		if ( $discount < 0 || empty( $discount_type ) ) {
			return apply_filters( 'wccs_product_pricing_discount_value_html', '' );
		}

		if ( 'percentage_discount' === $discount_type ) {
			return apply_filters( 'wccs_product_pricing_discount_value_html', $discount . '%' );
		} elseif ( 'price_discount' === $discount_type ) {
			return apply_filters( 'wccs_product_pricing_discount_value_html', wc_price( WCCS_Helpers::maybe_exchange_price( $discount ) ) );
		}

		return apply_filters( 'wccs_product_pricing_discount_value_html', '' );
	}

	public function bulk_pricing_table() {
		// Fast exit: if no bulk pricing rules exist anywhere in the store, skip immediately.
		if ( ! $this->pricing || ! $this->pricing->has_bulk_rules() ) {
			return;
		}

		$settings       = WCCS()->settings;
		$manage_stock   = $this->product->get_manage_stock();
		$stock_setting  = (int) $settings->get_setting( 'quantity_table_stock_management', 0 );
		$stock_quantity = $this->product->get_stock_quantity();

		if ( ( ! $manage_stock || ! $stock_setting || 1 < $stock_quantity ) && ! $this->is_in_exclude_rules() ) {
			$bulks = $this->get_bulk_pricings();

			if ( ! empty( $bulks ) ) {
				$view           = $settings->get_setting( 'quantity_table_layout', 'bulk-pricing-table-vertical' );
				$table_title    = __( 'Discount per Quantity', 'easy-woocommerce-discounts' );
				$price_label    = __( 'Price', 'easy-woocommerce-discounts' );
				$discount_label = __( 'Discount', 'easy-woocommerce-discounts' );
				$quantity_label = __( 'Quantity', 'easy-woocommerce-discounts' );

				if ( (int) $settings->get_setting( 'localization_enabled', 1 ) ) {
					$table_title    = $settings->get_setting( 'quantity_table_title', $table_title );
					$price_label    = $settings->get_setting( 'price_label', $price_label );
					$discount_label = $settings->get_setting( 'discount_label', $discount_label );
					$quantity_label = $settings->get_setting( 'quantity_label', $quantity_label );
				}

				$variation = $this->product->is_type( 'variation' ) ? $this->product_id : '';

				do_action( 'asnp_wccs_before_bulk_pricing_table', $bulks, $this );

				$table = '';
				foreach ( $bulks as $discount ) {
					ob_start();
					$this->render_view(
						"product-pricing.$view",
						array(
							'controller'     => $this,
							'discount'       => $discount,
							'table_title'    => $table_title,
							'quantity_label' => $quantity_label,
							'price_label'    => $price_label,
							'discount_label' => $discount_label,
							'product_id'     => $this->product_id,
							'variation'      => $variation,
						)
					);
					$table .= ob_get_clean();
				}

				if ( ! empty( $table ) ) {
					echo apply_filters( 'wccs_product_pricing_bulk_pricing_table', $table, $this );
				}
			}
		}

		if ( $this->product->is_type( 'variable' ) ) {
			// Disable plugin price replacer hooks to get variations main price.
			WCCS()->WCCS_Product_Price_Replace->disable_hooks();
			add_filter( 'woocommerce_show_variation_price', '__return_false', 100 );
			$variations = $this->product->get_available_variations( 'objects' );
			// Enable plugin price replacer hooks.
			WCCS()->WCCS_Product_Price_Replace->enable_hooks();
			remove_filter( 'woocommerce_show_variation_price', '__return_false', 100 );
			if ( ! empty( $variations ) ) {
				foreach ( $variations as $variation ) {
					$variation_id = is_array( $variation ) ? $variation['variation_id'] : $variation->get_id();
					$variation_pricing = new WCCS_Public_Product_Pricing( $variation_id, $this->pricing, $this->apply_method );
					$variation_pricing->bulk_pricing_table();
				}
			}
		}
	}

	public function purchase_message() {
		if ( ! $this->pricing || $this->is_in_exclude_rules() ) {
			return;
		}

		$rules = $this->pricing->get_pricings();

		foreach ( $rules as $type => $pricings ) {
			if ( empty( $pricings ) ) {
				continue;
			}

			foreach ( $pricings as $pricing ) {
				if ( empty( $pricing['purchased_message'] ) && empty( $pricing['receive_message'] ) ) {
					continue;
				}

				if ( ! empty( $pricing['purchased_message'] ) ) {
					echo $this->get_pricing_purchase_message( $pricing, 'purchased_message' );
				}

				if ( ! empty( $pricing['receive_message'] ) ) {
					echo $this->get_pricing_purchase_message( $pricing, 'receive_message' );
				}
			}
		}

		if ( $this->product->is_type( 'variable' ) ) {
			// Disable plugin price replacer hooks to get variations main price.
			WCCS()->WCCS_Product_Price_Replace->disable_hooks();
			add_filter( 'woocommerce_show_variation_price', '__return_false', 100 );
			$variations = $this->product->get_available_variations( 'objects' );
			// Enable plugin price replacer hooks.
			WCCS()->WCCS_Product_Price_Replace->enable_hooks();
			remove_filter( 'woocommerce_show_variation_price', '__return_false', 100 );
			if ( ! empty( $variations ) ) {
				foreach ( $variations as $variation ) {
					$variation_id = is_array( $variation ) ? $variation['variation_id'] : $variation->get_id();
					$variation_pricing = new WCCS_Public_Product_Pricing( $variation_id, $this->pricing, $this->apply_method );
					$variation_pricing->purchase_message();
				}
			}
		}
	}

	protected function get_pricing_purchase_message( $pricing, $message_type = 'receive_message' ) {
		if ( empty( $pricing ) || empty( $pricing[ $message_type ] ) ) {
			return '';
		}

		$attributes   = array();
		$variation_id = $this->product->is_type( 'variation' ) ? $this->product_id : 0;
		$validator    = WCCS()->WCCS_Product_Validator;

		if ( 'purchased_message' === $message_type ) {
			if ( empty( $pricing['purchased_items'] ) || ! $validator->is_valid_product( $pricing['purchased_items'], $this->parent_id, $variation_id, $attributes ) ) {
				return '';
			}
		} elseif ( 'receive_message' === $message_type ) {
			if ( 'products_group' === $pricing['mode'] ) {
				$is_valid = false;
				if ( ! empty( $pricing['groups'] ) ) {
					foreach ( $pricing['groups'] as $group ) {
						if ( ! empty( $group['items'] ) && $validator->is_valid_product( $group['items'], $this->parent_id, $variation_id, $attributes ) ) {
							$is_valid = true;
							break;
						}
					}
				}

				if ( ! $is_valid ) {
					return '';
				}
			} elseif ( empty( $pricing['items'] ) || ! $validator->is_valid_product( $pricing['items'], $this->parent_id, $variation_id, $attributes ) ) {
				return '';
			}
		}

		if ( ! empty( $pricing['exclude_items'] ) && $validator->is_valid_product( $pricing['exclude_items'], $this->parent_id, $variation_id, $attributes ) ) {
			return '';
		}

		if ( ! empty( $pricing['message_type'] ) && 'shortcode' === $pricing['message_type'] ) {
			$message = '<div class="wccs-shortcode-purchase-message wccs-shortcode-' . esc_attr( $message_type ) . '"' .
				( $this->product->is_type( 'variation' ) ? "data-variation='{$this->product_id}' style='display: none;'" : '' ) .
				do_shortcode( $pricing[ $message_type ] ) .
				'</div>';
			return apply_filters( "wccs_purchase_{$message_type}", $message, $pricing, $this );
		}

		$settings                 = WCCS()->settings;
		$default_background_color = $settings->get_setting( 'purchase_message_background_color', '' );
		$default_color            = $settings->get_setting( 'purchase_message_color', '' );

		$style = '';
		if ( ! empty( $pricing['message_background_color'] ) ) {
			$style .= 'background-color: ' . $pricing['message_background_color'] . ';';
		} elseif ( ! empty( $default_background_color ) ) {
			$style .= 'background-color: ' . $default_background_color . ';';
		}

		if ( ! empty( $pricing['message_color'] ) ) {
			$style .= 'color: ' . $pricing['message_color'] . ';';
		} elseif ( ! empty( $default_color ) ) {
			$style .= 'color: ' . $default_color . ';';
		}

		if ( $this->product->is_type( 'variation' ) ) {
			$style .= 'display: none;';
		}

		$message = '<div class="wccs-purchase-message wccs-' . esc_attr( $message_type ) . '"' .
			( $this->product->is_type( 'variation' ) ? "data-variation='{$this->product_id}'" : '' ) .
			( ! empty( $style ) ? "style='$style'" : '' ) . '>' . __( wp_kses_post( wp_unslash( $pricing[ $message_type ] ) ), 'easy-woocommerce-discounts' ) . '</div>';

		return apply_filters( "wccs_purchase_{$message_type}", $message, $pricing, $this );
	}

	public function get_simple_discounts() {
		if ( isset( $this->simple_discounts ) ) {
			return $this->simple_discounts;
		}

		$simples = $this->pricing->get_simple_pricings();
		if ( empty( $simples ) ) {
			$this->simple_discounts = array();
			return array();
		}

		$attributes = array();
		$discounts  = array();

		foreach ( $simples as $pricing_id => $pricing ) {
			if ( in_array( $pricing['discount_type'], array( 'percentage_fee', 'price_fee' ) ) ) {
				continue;
			}

			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			if ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			$discounts[ $pricing_id ] = $pricing;
		}

		if ( ! empty( $discounts ) ) {
			usort( $discounts, array( WCCS()->WCCS_Sorting, 'sort_by_order_asc' ) );
			$discounts = $this->pricing->rules_filter->by_apply_mode( $discounts );
		}

		$this->simple_discounts = $discounts;
		return $discounts;
	}

	public function get_bulk_pricings() {
		if ( isset( $this->bulk_pricings ) ) {
			return $this->bulk_pricings;
		}

		$bulks = $this->pricing->get_bulk_pricings();
		if ( empty( $bulks ) ) {
			$this->bulk_pricings = array();
			return array();
		}

		$attributes = array();
		$pricings   = array();
		foreach ( $bulks as $pricing_id => $pricing ) {
			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			if ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			$pricings[ $pricing_id ] = $pricing;
		}

		if ( ! empty( $pricings ) ) {
			usort( $pricings, array( WCCS()->WCCS_Sorting, 'sort_by_order_asc' ) );
			$pricings = $this->pricing->rules_filter->by_apply_mode( $pricings );
		}

		$this->bulk_pricings = $pricings;
		return $pricings;
	}

	public function get_purchase_pricings() {
		if ( isset( $this->purchase_pricings ) ) {
			return $this->purchase_pricings;
		}

		$purchases = $this->pricing->get_purchase_pricings();
		if ( empty( $purchases ) ) {
			$this->purchase_pricings = array();
			return array();
		}

		$attributes = array();
		$pricings   = array();
		foreach ( $purchases as $pricing_id => $pricing ) {
			if ( ! WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			if ( ! empty( $pricing['exclude_items'] ) && WCCS()->WCCS_Product_Validator->is_valid_product( $pricing['exclude_items'], $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ), $attributes ) ) {
				continue;
			}

			$pricings[ $pricing_id ] = $pricing;
		}

		if ( ! empty( $pricings ) ) {
			usort( $pricings, array( WCCS()->WCCS_Sorting, 'sort_by_order_asc' ) );
			$pricings = $this->pricing->rules_filter->by_apply_mode( $pricings );
		}

		$this->purchase_pricings = $pricings;
		return $pricings;
	}

	protected function apply_simple_discounts( $base_price ) {
		$discounts = $this->get_simple_discounts();
		if ( empty( $discounts ) ) {
			return $base_price;
		}

		// Get discount limit.
		$discount_limit = '';

		$discount_amounts = array();
		foreach ( $discounts as $discount ) {
			$discount_amount = false;
			if ( '' !== $discount_limit && 0 >= $discount_limit ) {
				break;
			}

			if ( 'percentage_discount' === $discount['discount_type'] ) {
				if ( (float) $discount['discount'] / 100 * $base_price > 0 ) {
					$discount_amount = (float) $discount['discount'] / 100 * $base_price;
					// Limit discount amount if limit exists.
					if ( '' !== $discount_limit && (float) $discount_amount > (float) $discount_limit ) {
						$discount_amount = (float) $discount_limit;
					}
				}
			} elseif ( 'price_discount' === $discount['discount_type'] ) {
				if ( (float) $discount['discount'] > 0 ) {
					$discount_amount = (float) $discount['discount'];
					// Limit discount amount if limit exists.
					if ( '' !== $discount_limit && (float) $discount_amount > (float) $discount_limit ) {
						$discount_amount = (float) $discount_limit;
					}
				}
			}

			if ( false !== $discount_amount ) {
				if ( '' !== $discount_limit ) {
					$discount_limit -= $discount_amount;
				}

				$discount_amounts[] = $discount_amount;
			}
		}

		if ( ! empty( $discount_amounts ) ) {
			$discount_amount = 0;
			if ( 'first' === $this->apply_method ) {
				$discount_amount = $discount_amounts[0];
			} elseif ( 'max' === $this->apply_method ) {
				$discount_amount = max( $discount_amounts );
			} elseif ( 'min' === $this->apply_method ) {
				$discount_amount = min( $discount_amounts );
			} elseif ( 'sum' === $this->apply_method ) {
				$discount_amount = array_sum( $discount_amounts );
			}

			if ( $base_price - $discount_amount >= 0 ) {
				return $base_price - $discount_amount;
			}
			return 0;
		}

		return $base_price;
	}

	protected function is_in_exclude_rules() {
		if ( isset( $this->is_in_excludes ) ) {
			return $this->is_in_excludes;
		}

		if ( $this->pricing->is_in_exclude_rules( $this->parent_id, ( $this->product->is_type( 'variation' ) ? $this->product_id : 0 ) ) ) {
			$this->is_in_excludes = true;
			return true;
		}

		$this->is_in_excludes = false;
		return false;
	}

}

