<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Public_Pricing_Hooks extends WCCS_Public_Controller {

	protected $loader;

	protected $pricing;

	protected $product_pricing;

	protected $discounted_products;

	protected $price_html_cache = array();

	protected $price_hooks_enabled = false;

	public $applied_pricings = false;

	/**
	 * An array contains cart_item_key and its associated discounted prices.
	 *
	 * @var array
	 */
	public $applied_discounts = array();

	public function __construct( WCCS_Loader $loader ) {
		$this->loader = $loader;
	}

	public function enable_hooks() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'remove_pricings' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_pricings' ), 9999 );
	}

	public function disable_hooks() {
		remove_action( 'woocommerce_before_calculate_totals', array( $this, 'remove_pricings' ) );
		remove_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_pricings' ) );
	}

	public function init() {
		$this->loader->add_action( 'woocommerce_cart_loaded_from_session', $this, 'cart_loaded_from_session' );
		$this->loader->add_action( 'woocommerce_cart_loaded_from_session', $this, 'enable_price_and_badge_hooks' );
		$this->loader->add_action( 'woocommerce_init', $this, 'rest_api' );
		$this->loader->add_action( 'wccs_virtual_cart_before_calculate_totals', $this, 'remove_pricings' );
		$this->loader->add_action( 'woocommerce_before_calculate_totals', $this, 'remove_pricings' );
		$this->loader->add_action( 'woocommerce_before_calculate_totals', $this, 'apply_pricings', 9999 );
		$this->loader->add_action( 'woocommerce_add_to_cart', $this, 'reset_applied_pricings' );
		$this->loader->add_action( 'woocommerce_cart_item_removed', $this, 'reset_applied_pricings' );
		$this->loader->add_action( 'woocommerce_cart_emptied', $this, 'reset_applied_pricings' );
		$this->loader->add_action( 'woocommerce_checkout_update_order_review', $this, 'reset_applied_pricings' );
		$this->loader->add_action( 'woocommerce_after_cart_item_quantity_update', $this, 'reset_applied_pricings' );
		$this->loader->add_filter( 'woocommerce_cart_item_price', $this, 'cart_item_price', 10, 3 );
		$this->loader->add_filter( 'woocommerce_widget_cart_item_quantity', $this, 'widget_cart_item_quantity', 10, 3 );

		// Fix mini cart issue with subtotal conditions of simple pricing rules.
		$this->loader->add_action( 'woocommerce_before_mini_cart_contents', $this, 'calculate_cart_totals', 10 );

		if ( (int) WCCS()->settings->get_setting( 'display_quantity_table', 1 ) ) {
			$this->quantity_table_hooks();
		}

		if ( (int) WCCS()->settings->get_setting( 'purchase_x_receive_y_message_display', 1 ) ) {
			$this->purchase_message_hooks();
		}

		if ( (int) WCCS()->settings->get_setting( 'show_free_gift_badge', 1 ) ) {
			$this->loader->add_filter( 'woocommerce_cart_item_name', $this, 'cart_item_name', 999, 2 );
		}
	}

	public function cart_loaded_from_session( $cart ) {
		if ( ! WCCS()->is_request( 'frontend' ) ) {
			return;
		}

		$cart_contents = $cart->get_cart();
		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			// It is a cart item product so do not override its price.
			if ( isset( $cart->cart_contents[ $cart_item_key ]['data'] ) ) {
				WCCS()->custom_props->set_prop( $cart->cart_contents[ $cart_item_key ]['data'], 'wccs_is_cart_item', true );
			}
		}
	}

	public function enable_change_price_hooks() {
		if ( ! WCCS_Helpers::should_change_display_price() ) {
			return;
		}

		WCCS()->WCCS_Product_Price_Replace->set_should_replace_prices( true )
			->set_change_regular_price( WCCS_Helpers::should_change_display_price_html() ? false : true )
			->enable_hooks();
	}

	public function enable_price_and_badge_hooks( $force = false ) {
		if ( ! $force && $this->price_hooks_enabled ) {
			return;
		}

		if ( ! WCCS()->is_request( 'frontend' ) ) {
			return;
		}
		$this->price_hooks_enabled = true;

		if ( 'advanced' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' ) ) {
			$this->enable_change_price_hooks();
		} else {
			/**
			 * Always show variation prices even variations has the same price.
			 * It is required for change price of a variation.
			 */
			if ( $this->should_hook_price_html() ) {
				add_filter( 'woocommerce_show_variation_price', '__return_true', 25 );
			}
		}

		if ( $this->should_hook_price_html() ) {
			add_filter( 'woocommerce_get_price_html', array( &$this, 'get_price_html' ), 10, 2 );
		}

		$sale_badge = WCCS()->settings->get_setting( 'sale_badge', array( 'simple' => '1' ) );

		if ( ! empty( $sale_badge ) && is_array( $sale_badge ) ) {
			if ( ! empty( $sale_badge['simple'] ) ) {
				add_filter( 'woocommerce_product_is_on_sale', array( &$this, 'woocommerce_product_is_on_sale' ), 10, 2 );

				if ( 'discount' === WCCS()->settings->get_setting( 'sale_badge_type', 'sale' ) ) {
					add_filter( 'woocommerce_sale_flash', array( &$this, 'percentage_sale_badge' ), 10, 3 );
				}
			}

			$has_non_simple = false;
			foreach ( array( 'bulk', 'purchase' ) as $type ) {
				if ( ! empty( $sale_badge[ $type ] ) ) {
					$has_non_simple = true;
					break;
				}
			}

			if ( $has_non_simple ) {
				$this->sale_badge_hooks();
			}
		}

		// Prime catalog product caches only when price/badge hooks are active and object caching is not external.
		if ( ! wp_using_ext_object_cache() ) {
			add_filter( 'the_posts', array( $this, 'prime_catalog_product_caches' ), 10, 2 );
		}
	}

	public function rest_api() {
		if ( ! WCCS_Helpers::wc_is_rest_api_request() ) {
			return;
		}

		$this->enable_change_price_hooks();

		if ( ! wp_using_ext_object_cache() ) {
			add_filter( 'the_posts', array( $this, 'prime_catalog_product_caches' ), 10, 2 );
		}
	}

	public function get_price_html( $price, $product ) {
		if ( empty( $price ) ) {
			return $price;
		}

		if ( ! apply_filters( 'asnp_wccs_apply_get_price_html', true, $price, $product ) ) {
			return $price;
		}

		$cache_key = $this->get_price_html_cache_key( $price, $product );
		if ( isset( $this->price_html_cache[ $cache_key ] ) ) {
			return $this->price_html_cache[ $cache_key ];
		}

		$product_pricing = $this->get_product_pricing( $product );
		$html            = $product_pricing->get_price_html( $price );

		$this->price_html_cache[ $cache_key ] = $html;

		return $html;
	}

	public function apply_pricings( $cart = null ) {
		if ( ! $this->should_apply_pricing() ) {
			return;
		}

		$this->clear_price_html_cache();
		$this->applied_discounts = array();

		$cart = $cart && is_a( $cart, 'WC_Cart' ) ? $cart : WC()->cart;
		if ( $cart->is_empty() ) {
			return;
		}

		$this->pricing = WCCS()->pricing;
		$this->pricing->reset_cache();

		$cart_contents = $cart->get_cart();
		if ( ! empty( $cart_contents ) ) {
			$pricing_cache = new WCCS_Cart_Pricing_Cache();
		}

		do_action( 'wccs_public_pricing_hooks_before_apply_pricings', $this );

		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			// It is a cart item product so do not override its price.
			if ( isset( $cart->cart_contents[ $cart_item_key ]['data'] ) ) {
				WCCS()->custom_props->set_prop( $cart->cart_contents[ $cart_item_key ]['data'], 'wccs_is_cart_item', true );
			}

			$product = $cart_item['data'];
			if ( isset( $cart_item['_wccs_main_price'] ) ) {
				$product->set_price( $cart_item['_wccs_main_price'] );
				if ( isset( $cart_item['_wccs_main_sale_price'] ) ) {
					$product->set_sale_price( $cart_item['_wccs_main_sale_price'] );
				}
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_sale_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_display_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_before_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_prices'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_prices_main'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_applied_rules'] );
			}

			if ( ! apply_filters( 'wccs_apply_pricing_on_cart_item', true, $cart_item ) ) {
				continue;
			}

			$pricing_item          = new WCCS_Public_Cart_Item_Pricing( $cart_item_key, $cart_item, $this->pricing, '', null, $pricing_cache );
			$item_discounted_price = $pricing_item->get_price();
			if ( false === $item_discounted_price || $item_discounted_price < 0 ) {
				continue;
			}

			$item_discounted_price = apply_filters( 'wccs_cart_item_discounted_price', $item_discounted_price, $cart_item );

			if ( ! apply_filters( 'wccs_apply_discounted_price_on_cart_item', true, $item_discounted_price, $cart_item['data'], $cart_item ) ) {
				continue;
			}

			do_action( 'wccs_apply_pricing_before_set_item_prices', $cart_item, $pricing_item, $item_discounted_price );

			$cart->cart_contents[ $cart_item_key ]['_wccs_main_price']              = $this->get_cart_item_main_price( $cart_item, $pricing_item->product );
			$cart->cart_contents[ $cart_item_key ]['_wccs_main_display_price']      = $this->get_cart_item_main_display_price( $cart_item, $pricing_item->product );
			$cart->cart_contents[ $cart_item_key ]['_wccs_before_discounted_price'] = $this->get_cart_item_before_discounted_price( $cart_item, $product );
			$cart->cart_contents[ $cart_item_key ]['_wccs_discounted_price']        = wc_format_decimal( $item_discounted_price );
			$cart->cart_contents[ $cart_item_key ]['_wccs_prices']                  = apply_filters( 'wccs_cart_item_prices', $pricing_item->get_prices(), $cart_item );
			// Do not apply any filter on _wccs_prices_main.
			$cart->cart_contents[ $cart_item_key ]['_wccs_prices_main']             = $pricing_item->get_prices();

			// Set applied rules.
			$applied_rules = $pricing_item->get_applied_rules();
			if ( ! empty( $applied_rules ) ) {
				$applied_rules = array_map( function( $rule ) {
					return [
						'id'            => $rule['id'],
						'name'          => $rule['name'],
						'description'   => ! empty( $rule['description'] ) ? $rule['description'] : '',
						'type'          => isset( $rule['type'] ) ? $rule['type'] : 'discount',
						'discount_type' => isset( $rule['discount_type'] ) ? $rule['discount_type'] : '',
						'discount'      => isset( $rule['discount'] ) ? $rule['discount'] : 0,
						'apply_mode'    => isset( $rule['apply_mode'] ) ? $rule['apply_mode'] : '',
						'mode'          => isset( $rule['mode'] ) ? $rule['mode'] : '',
						'amount'        => isset( $rule['amount'] ) ? $rule['amount'] : 0,
					];
				}, $applied_rules );
			}
			$cart->cart_contents[ $cart_item_key ]['_wccs_applied_rules'] = $applied_rules;

			do_action( 'wccs_apply_pricing_after_set_item_prices', $cart_item, $pricing_item, $item_discounted_price );

			$this->applied_discounts[ $cart_item_key ] = array(
				'discounted_price' => $item_discounted_price,
			);

			// Setting sale price.
			if ( $item_discounted_price < $pricing_item->get_base_price() ) {
				$cart->cart_contents[ $cart_item_key ]['_wccs_main_sale_price'] = apply_filters( 'wccs_cart_item_main_sale_price', $pricing_item->product->get_sale_price( 'edit' ), $cart_item );
				$product->set_sale_price( $item_discounted_price );
				$this->applied_discounts[ $cart_item_key ]['discounted_sale_price'] = $item_discounted_price;
			}

			$product->set_price( $item_discounted_price );
		}

		do_action( 'wccs_public_pricing_hooks_after_apply_pricings', $this );

		$this->applied_pricings = true;
		$this->clear_price_html_cache();
	}

	/**
	 * Reset applied pricings.
	 *
	 * @since  2.8.0
	 *
	 * @return void
	 */
	public function reset_applied_pricings() {
		$this->clear_price_html_cache();
		$this->pricing = WCCS()->pricing;

		if ( $this->applied_pricings ) {
			$this->applied_pricings = false;
			if ( isset( $this->pricing ) ) {
				$this->pricing->reset_cache();
			}
			// Enable remove pricing hook after reset applied pricings.
			if ( ! has_action( 'woocommerce_before_calculate_totals', array( &$this, 'remove_pricings' ) ) ) {
				add_action( 'woocommerce_before_calculate_totals', array( &$this, 'remove_pricings' ) );
			}
		}
	}

	public function remove_pricings( $cart = null ) {
		$this->clear_price_html_cache();

		$cart = $cart && is_a( $cart, 'WC_Cart' ) ? $cart : WC()->cart;
		if ( $cart->is_empty() ) {
			return;
		}

		$cart_contents = $cart->get_cart();
		foreach ( $cart_contents as $cart_item_key => $cart_item ) {
			if ( isset( $cart_item['_wccs_main_price'] ) ) {
				$cart_item['data']->set_price( $cart_item['_wccs_main_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_display_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_before_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_discounted_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_prices'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_prices_main'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_applied_rules'] );
			}

			if ( isset( $cart_item['_wccs_main_sale_price'] ) ) {
				$cart_item['data']->set_sale_price( $cart_item['_wccs_main_sale_price'] );
				unset( $cart->cart_contents[ $cart_item_key ]['_wccs_main_sale_price'] );
			}

			// It is a cart item product so do not override its price.
			if ( isset( $cart->cart_contents[ $cart_item_key ]['data'] ) ) {
				WCCS()->custom_props->set_prop( $cart->cart_contents[ $cart_item_key ]['data'], 'wccs_is_cart_item', true );
			}
		}
	}

	protected function should_apply_pricing() {
		return apply_filters( 'wccs_should_apply_pricing', true, $this );
	}

	public function cart_item_price( $price, $cart_item, $cart_item_key ) {
		if ( ! isset( $cart_item['_wccs_discounted_price'] ) || ! isset( $cart_item['_wccs_before_discounted_price'] ) || ! isset( $cart_item['_wccs_main_price'] ) ) {
			return $price;
		}

		if ( ! apply_filters( 'wccs_change_cart_item_price', true ) ) {
			return $price;
		}

		if ( isset( $cart_item['asnp_wepb_items'] ) || isset( $cart_item['asnp_wepb_parent_id'] ) ) {
			return $price;
		}

		if ( isset( $cart_item['_wccs_main_sale_price'] ) && $cart_item['_wccs_main_sale_price'] == $cart_item['_wccs_main_price'] ) {
			$before_discounted_price = apply_filters(
				'wccs_cart_item_price_before_discounted_price',
				WCCS()->cart->get_product_price( $cart_item['data'], array( 'price' => $cart_item['data']->get_regular_price(), 'qty' => 1 ) ),
				$cart_item,
				$cart_item_key,
				$price
			);
			$main_price = (float) $cart_item['data']->get_regular_price();
		} else {
			$before_discounted_price = apply_filters(
				'wccs_cart_item_price_before_discounted_price',
				$cart_item['_wccs_before_discounted_price'],
				$cart_item,
				$cart_item_key,
				$price
			);
			$main_price = (float) $cart_item['_wccs_main_price'];
		}

		if ( $main_price > (float) $cart_item['_wccs_discounted_price'] ) {
			$content = '<del>' . $before_discounted_price . '</del> <ins>' . $price . '</ins>';
			if ( ! empty( $cart_item['_wccs_prices'] ) && 1 < count( $cart_item['_wccs_prices'] ) ) {
				$content = '<div class="wccs_prices">';
				foreach ( $cart_item['_wccs_prices'] as $price_str => $quantity ) {
					if (
						(float) $price_str != (float) $cart_item['_wccs_main_display_price'] ||
						( isset( $cart_item['_wccs_main_sale_price'] ) && $cart_item['_wccs_main_sale_price'] == $cart_item['_wccs_main_price'] )
					) {
						$content .= '<div class="wccs_prices_price"><span class="wccs_prices_price_container"><del>' . $before_discounted_price . '</del> <ins>' . apply_filters( 'wccs_cart_item_price_prices_price', wc_price( $price_str ), $price_str, $cart_item, $cart_item_key, $price ) . '</ins></span>';
					} else {
						$content .= '<div class="wccs_prices_price"><span class="wccs_prices_price_container"><ins>' . $before_discounted_price . '</ins></span>';
					}

					$content .= '<span class="wccs_prices_quantity_container"><strong><span class="wccs_prices_times">&times;</span> <span class="wccs_prices_quantity">' . $quantity . '</span></strong></span></div>';
				}
				$content .= '</div>';
			}

			return apply_filters( 'wccs_cart_item_price', $content, $price, $cart_item, $cart_item_key );
		}

		return apply_filters( 'wccs_cart_item_price', $price, $price, $cart_item, $cart_item_key );
	}

	public function widget_cart_item_quantity( $quantity, $cart_item, $cart_item_key ) {
		if ( ! isset( $cart_item['_wccs_discounted_price'] ) || ! isset( $cart_item['_wccs_before_discounted_price'] ) || ! isset( $cart_item['_wccs_main_price'] ) ) {
			return $quantity;
		}

		if ( (float) $cart_item['_wccs_main_price'] > (float) $cart_item['_wccs_discounted_price'] ) {
			if ( ! empty( $cart_item['_wccs_prices'] ) && 1 < count( $cart_item['_wccs_prices'] ) ) {
				$quantity = str_replace( $cart_item['quantity'] . ' &times; ', '', $quantity );
			}
		}

		return $quantity;
	}

	public function calculate_cart_totals() {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		} elseif ( (int) WCCS()->settings->get_setting( 'disable_calculate_cart_totals', 0 ) ) {
			return;
		} elseif ( ! apply_filters( 'wccs_calculate_cart_totals', true ) ) {
			return;
		}

		do_action( 'wccs_before_calculate_cart_totals' );
		WC()->cart->calculate_totals();
		do_action( 'wccs_after_calculate_cart_totals' );
	}

	protected function quantity_table_hooks() {
		$position = WCCS()->settings->get_setting( 'quantity_table_position', 'before_add_to_cart_button' );

		switch ( $position ) {
			case 'before_add_to_cart_button' :
			case 'after_add_to_cart_button' :
				$add_to_cart_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' );
				if ( 'before_add_to_cart_button' === $position ) {
					$add_to_cart_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', $add_to_cart_priority - 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', 29 );
				} elseif ( 'after_add_to_cart_button' === $position ) {
					$add_to_cart_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', $add_to_cart_priority + 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', 31 );
				}
				break;

			case 'before_add_to_cart_form':
				$this->loader->add_action( 'woocommerce_before_add_to_cart_form', $this, 'display_bulk_pricing_table' );
				break;

			case 'after_add_to_cart_form':
				$this->loader->add_action( 'woocommerce_after_add_to_cart_form', $this, 'display_bulk_pricing_table' );
				break;

			case 'before_excerpt' :
			case 'after_excerpt' :
				$excerpt_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' );
				if ( 'before_excerpt' === $position ) {
					$excerpt_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', $excerpt_priority - 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', 19 );
				} elseif ( 'after_excerpt' === $position ) {
					$excerpt_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', $excerpt_priority + 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', 21 );
				}
				break;

			case 'after_product_meta' :
				$meta_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta' );
				$meta_priority ?
					$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', $meta_priority + 1 ) :
					$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_bulk_pricing_table', 41 );
				break;

			case 'in_modal' :
				break;

			default :
				break;
		}
	}

	public function display_bulk_pricing_table() {
		global $product;
		if ( ! $product ) {
			return;
		}

		$product_pricing = $this->get_product_pricing( $product );
		$product_pricing->bulk_pricing_table();
	}

	protected function purchase_message_hooks() {
		$position = WCCS()->settings->get_setting( 'purchase_x_receive_y_message_position', 'before_add_to_cart_button' );

		switch ( $position ) {
			case 'before_add_to_cart_button' :
			case 'after_add_to_cart_button' :
				$add_to_cart_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart' );
				if ( 'before_add_to_cart_button' === $position ) {
					$add_to_cart_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', $add_to_cart_priority - 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', 29 );
				} elseif ( 'after_add_to_cart_button' === $position ) {
					$add_to_cart_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', $add_to_cart_priority + 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', 31 );
				}
				break;

			case 'before_add_to_cart_form':
				$this->loader->add_action( 'woocommerce_before_add_to_cart_form', $this, 'display_purchase_message' );
				break;

			case 'after_add_to_cart_form':
				$this->loader->add_action( 'woocommerce_after_add_to_cart_form', $this, 'display_purchase_message' );
				break;

			case 'before_excerpt' :
			case 'after_excerpt' :
				$excerpt_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_excerpt' );
				if ( 'before_excerpt' === $position ) {
					$excerpt_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', $excerpt_priority - 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', 19 );
				} elseif ( 'after_excerpt' === $position ) {
					$excerpt_priority ?
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', $excerpt_priority + 1 ) :
						$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', 21 );
				}
				break;

			case 'after_product_meta' :
				$meta_priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta' );
				$meta_priority ?
					$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', $meta_priority + 1 ) :
					$this->loader->add_action( 'woocommerce_single_product_summary', $this, 'display_purchase_message', 41 );
				break;

			case 'in_modal' :
				break;

			default :
				break;
		}
	}

	public function display_purchase_message() {
		global $product;
		if ( ! $product ) {
			return;
		}

		if ( ! apply_filters( 'wccs_display_purchase_message', true, $product ) ) {
			return;
		}

		$product_pricing = $this->get_product_pricing( $product );
		$product_pricing->purchase_message();
	}

	protected function sale_badge_hooks() {
		$loop_position = WCCS()->settings->get_setting( 'loop_sale_badge_position', 'before_shop_loop_item_thumbnail' );
		switch ( $loop_position ) {
			case 'before_shop_loop_item_thumbnail':
				$priority = has_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail' );
				if ( $priority ) {
					add_action( 'woocommerce_before_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_before_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 9 );
				}
				break;

			case 'after_shop_loop_item_thumbnail':
				$priority = has_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail' );
				if ( $priority ) {
					add_action( 'woocommerce_before_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_before_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 11 );
				}
				break;

			case 'before_shop_loop_item_title':
				$priority = has_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title' );
				if ( $priority ) {
					add_action( 'woocommerce_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 9 );
				}
				break;

			case 'after_shop_loop_item_title':
				$priority = has_action( 'woocommerce_shop_loop_item_title', 'woocommerce_template_loop_product_title' );
				if ( $priority ) {
					add_action( 'woocommerce_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 10 );
				}
				break;

			case 'before_shop_loop_item_rating':
				$priority = has_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating' );
				if ( $priority ) {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 4 );
				}
				break;

			case 'after_shop_loop_item_rating':
				$priority = has_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating' );
				if ( $priority ) {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 6 );
				}
				break;

			case 'before_shop_loop_item_price':
				$priority = has_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price' );
				if ( $priority ) {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 9 );
				}
				break;

			case 'after_shop_loop_item_price':
				$priority = has_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price' );
				if ( $priority ) {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_after_shop_loop_item_title', array( &$this, 'display_sale_badge' ), 11 );
				}
				break;
		}

		$single_position = WCCS()->settings->get_setting( 'single_sale_badge_position', 'before_single_item_images' );
		switch ( $single_position ) {
			case 'before_single_item_images':
				$priority = has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' );
				if ( $priority ) {
					add_action( 'woocommerce_before_single_product_summary', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_before_single_product_summary', array( &$this, 'display_sale_badge' ), 19 );
				}
				break;

			case 'after_single_item_images':
				$priority = has_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images' );
				if ( $priority ) {
					add_action( 'woocommerce_before_single_product_summary', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_before_single_product_summary', array( &$this, 'display_sale_badge' ), 21 );
				}
				break;

			case 'before_single_item_title':
				$priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title' );
				if ( $priority ) {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), 4 );
				}
				break;

			case 'after_single_item_title':
				$priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_title' );
				if ( $priority ) {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), 6 );
				}
				break;

			case 'before_single_item_price':
				$priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price' );
				if ( $priority ) {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), $priority - 1 );
				} else {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), 9 );
				}
				break;

			case 'after_single_item_price':
				$priority = has_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price' );
				if ( $priority ) {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), $priority + 1 );
				} else {
					add_action( 'woocommerce_single_product_summary', array( &$this, 'display_sale_badge' ), 11 );
				}
				break;
		}
	}

	public function display_sale_badge() {
		$this->render_view( 'product-pricing.sale-flash', array( 'controller' => $this ) );
	}

	public function woocommerce_product_is_on_sale( $on_sale, $product ) {
		if ( $on_sale ) {
			return $on_sale;
		}

		return WCCS()->WCCS_Product_Onsale_Cache->is_simple_onsale( $product );
	}

	public function percentage_sale_badge( $sale_badge, $post, $product ) {
		if ( ! $product && ! empty( $post ) ) {
			$product = wc_get_product( $post->ID );
		}

		if ( $product && ( $discount = WCCS()->product_helpers->get_percentage_badge_value( $product ) ) ) {
			$html = '<span class="onsale wccs-onsale-badge wccs-onsale-badge-discount">';
			$html .= apply_filters( 'wccs_sale_flash_negative_symbol', '<span class="wccs-sale-flash-negative-symbol">-</span>' )
					. esc_html( apply_filters( 'wccs_sale_flash_percentage_value', round( $discount ), $discount ) )
					. apply_filters( 'wccs_sale_flash_percentage_symbol', '<span class="wccs-sale-flash-percentage-symbol">%</span>' );
			$html .= '</span>';
			$sale_badge = apply_filters( 'wccs_sale_flash_discount_value', $html, $discount, $product, $post );
		}
		return $sale_badge;
	}

	/**
	 * Check whether woocommerce_get_price_html filter should be hooked.
	 *
	 * In advanced mode, if change_display_price is simple_ex_html, WCCS_Product_Price_Replace replaces
	 * regular and sale prices directly, so woocommerce_get_price_html is not hooked.
	 * In lightweight mode, woocommerce_get_price_html must be hooked to modify the display price without strikethrough.
	 *
	 * @return bool
	 */
	public function should_hook_price_html() {
		if ( WCCS_Helpers::should_change_display_price_html( 'all' ) ) {
			return true;
		}

		if ( 'lightweight' === WCCS()->settings->get_setting( 'change_price_method', 'lightweight' )
			&& WCCS_Helpers::is_change_display_price_ex_html()
			&& WCCS_Helpers::should_change_display_price()
		) {
			return true;
		}

		return false;
	}

	public function enable_price_hooks( $hooks = array() ) {
		if ( ! empty( $hooks ) ) {
			if ( in_array( 'woocommerce_get_price_html', $hooks ) && $this->should_hook_price_html() ) {
				add_filter( 'woocommerce_get_price_html', array( &$this, 'get_price_html' ), 10, 2 );
			}
		} else {
			if ( $this->should_hook_price_html() ) {
				add_filter( 'woocommerce_get_price_html', array( &$this, 'get_price_html' ), 10, 2 );
			}
		}
	}

	public function disable_price_hooks( $hooks = array() ) {
		if ( ! empty( $hooks ) ) {
			if ( in_array( 'woocommerce_get_price_html', $hooks ) ) {
				remove_filter( 'woocommerce_get_price_html', array( &$this, 'get_price_html' ) );
			}
		} else {
			remove_filter( 'woocommerce_get_price_html', array( &$this, 'get_price_html' ) );
		}
	}

	public function cart_item_name( $product_name, $cart_item ) {
		if ( ! isset( $cart_item['data'] ) || 0 != $cart_item['data']->get_price() ) {
			return $product_name;
		}

		// Is the cart item discounted by the discount plugin.
		if ( ! WCCS_Helpers::is_auto_added_product( $cart_item ) && ! isset( $cart_item['_wccs_discounted_price'] ) ) {
			return $product_name;
		}

		// Do not add gift badge to the bundle and bundle items.
		if ( isset( $cart_item['asnp_wepb_items'] ) || isset( $cart_item['asnp_wepb_parent_id'] ) ) {
			return $product_name;
		}

		if ( ! apply_filters( 'wccs_add_free_gift_badge', true, $cart_item ) ) {
			return $product_name;
		}

		// $badge = apply_filters( 'wccs_cart_free_badge', ' <span class="asnp-ewd-free-badge" style="background-color: #00a651; color: #fff; font-size: 15px; padding: 2px 6px; margin-left: 2px; border-radius: 4px;">' . __( 'Free', 'easy-woocommerce-discounts' ) . '</span>' );
		$badge = apply_filters( 'wccs_cart_free_badge', ' <img draggable="false" role="img" class="emoji" alt="🎁" style="float:none; display: inline; border: none; box-shadow: none; height: 1em; width: 1em; background: none; padding: 0; vertical-align: -0.1em; margin: 0 0.07em;" src="https://s.w.org/images/core/emoji/14.0.0/svg/1f381.svg"/>' );

		return $product_name . $badge;
	}

	protected function get_product_pricing( $product, $pricing = null ) {
		if ( null === $pricing ) {
			$pricing = $this->pricing = WCCS()->pricing;
		}

		if ( ! isset( $this->product_pricing ) ) {
			$this->product_pricing = new WCCS_Public_Product_Pricing( $product, $pricing );
		} elseif ( is_numeric( $product ) && $product != $this->product_pricing->product->get_id() ) {
			$this->product_pricing = new WCCS_Public_Product_Pricing( $product, $pricing );
		} elseif ( $product !== $this->product_pricing->product ) {
			$this->product_pricing = new WCCS_Public_Product_Pricing( $product, $pricing );
		}

		return $this->product_pricing;
	}

	protected function get_price_html_cache_key( $price, $product ) {
		$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
		$parts      = [
			'product_id' => $product_id,
			'price'      => $price,
		];

		return md5( wp_json_encode( apply_filters( 'asnp_wccs_public_pricing_hooks_price_html_cache_key_parts', $parts, $price, $product ) ) );
	}

	protected function clear_price_html_cache() {
		$this->price_html_cache = [];
	}

	protected function get_cart_item_main_price( $cart_item, $product ) {
		if ( 'cart_item_price' === WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' ) ) {
			return apply_filters(
				'wccs_cart_item_main_price',
				$cart_item['data']->get_price( 'edit' ),
				$cart_item,
				$product
			);
		}
		return apply_filters(
			'wccs_cart_item_main_price',
			$product->get_price( 'edit' ),
			$cart_item,
			$product
		);
	}

	protected function get_cart_item_main_display_price( $cart_item, $product ) {
		if ( 'cart_item_price' === WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' ) ) {
			return apply_filters(
				'wccs_cart_item_main_display_price',
				wc_get_price_to_display( $cart_item['data'], array( 'price' => $cart_item['data']->get_price( 'edit' ), 'display_context' => 'cart' ) ),
				$cart_item,
				$product
			);
		}
		return apply_filters(
			'wccs_cart_item_main_display_price',
			wc_get_price_to_display( $product, array( 'price' => $product->get_price( 'edit' ), 'display_context' => 'cart' ) ),
			$cart_item,
			$product
		);
	}

	protected function get_cart_item_before_discounted_price( $cart_item, $product ) {
		if ( 'cart_item_price' === WCCS()->settings->get_setting( 'pricing_product_base_price', 'cart_item_price' ) ) {
			return apply_filters(
				'wccs_cart_item_before_discounted_price',
				WC()->cart->get_product_price( $cart_item['data'] ),
				$cart_item,
				$product
			);
		}
		return apply_filters(
			'wccs_cart_item_before_discounted_price',
			WC()->cart->get_product_price( $product ),
			$cart_item,
			$product
		);
	}

	/**
	 * Prime product price and on-sale caches for catalog and archive query results in batch.
	 *
	 * Eliminates N+1 database queries on shop, category, tag, search, and shortcode pages.
	 * Runs ONLY when required on product queries with active rules.
	 *
	 * @param array         $posts Array of post objects or IDs.
	 * @param WP_Query|null $query The WP_Query instance.
	 * @return array
	 */
	public function prime_catalog_product_caches( $posts, $query = null ) {
		if ( empty( $posts ) || ! is_array( $posts ) ) {
			return $posts;
		}

		// Never run during cron jobs, CLI, or admin requests (except storefront catalog AJAX).
		if ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $posts;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			return $posts;
		}

		// Guard against periodic background AJAX tasks (Heartbeat, Action Scheduler runner, autosave, refreshed cart fragments).
		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( 'heartbeat' === $action || 'wp-autosave' === $action || 0 === strpos( $action, 'as_' ) ) {
				return $posts;
			}
		}

		$wc_ajax = isset( $_REQUEST['wc-ajax'] ) ? sanitize_key( wp_unslash( $_REQUEST['wc-ajax'] ) ) : '';
		if ( 'get_refreshed_fragments' === $wc_ajax ) {
			return $posts;
		}

		// Persistent object caches (Redis, Memcached) handle memory caching directly without L2 DB meta batch priming.
		if ( wp_using_ext_object_cache() ) {
			return $posts;
		}

		// Don't run if DB caches are disabled.
		$price_cache_enabled  = WCCS()->WCCS_Product_Price_Cache->is_cache_enabled();
		$onsale_cache_enabled = WCCS()->WCCS_Product_Onsale_Cache->is_cache_enabled();
		if ( ! $price_cache_enabled && ! $onsale_cache_enabled ) {
			return $posts;
		}

		// Don't run if there are no active pricing rules.
		if ( empty( WCCS()->pricing ) || empty( WCCS()->pricing->get_all_pricing_rules() ) ) {
			return $posts;
		}

		// Don't run if neither price modification nor sale badges are active.
		$sale_badge = WCCS()->settings->get_setting( 'sale_badge', array( 'simple' => '1' ) );
		if ( ! WCCS_Helpers::should_change_display_price_html() && ! WCCS_Helpers::should_change_display_price() && empty( $sale_badge ) ) {
			return $posts;
		}

		// Validate that the query is specifically a product query.
		$is_product_query = false;
		if ( is_a( $query, 'WP_Query' ) ) {
			$post_type = $query->get( 'post_type' );
			if ( is_string( $post_type ) ) {
				$is_product_query = ( 'product' === $post_type || 'product_variation' === $post_type );
			} elseif ( is_array( $post_type ) ) {
				$is_product_query = in_array( 'product', $post_type, true ) || in_array( 'product_variation', $post_type, true );
			}
			if ( ! $is_product_query ) {
				return $posts;
			}
		}

		$product_ids = array();
		foreach ( $posts as $post ) {
			if ( is_a( $post, 'WP_Post' ) ) {
				if ( 'product' === $post->post_type || 'product_variation' === $post->post_type ) {
					$product_ids[] = (int) $post->ID;
				}
			} elseif ( is_numeric( $post ) && $is_product_query ) {
				$product_ids[] = (int) $post;
			}
		}

		if ( ! empty( $product_ids ) ) {
			WCCS_Helpers::prime_product_caches( $product_ids );
		}

		return $posts;
	}

}
