<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Schemas\ExtendSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CartItemSchema;

class WCCS_Store_API {

	/**
	 * Stores Rest Extending instance.
	 *
	 * @var ExtendSchema
	 */
	private static $extend;

	/**
	 * Plugin Identifier, unique to each plugin.
	 *
	 * @var string
	 */
	const IDENTIFIER = 'asnp_ewd';

	/**
	 * Bootstraps the class and hooks required data.
	 *
	 * @param ExtendSchema $extend_rest_api An instance of the ExtendSchema class.
	 */
	public static function init( ExtendSchema $extend_rest_api ) {
		self::$extend = $extend_rest_api;
		self::extend_store();
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'edit_cart_items_data' ], 10, 3 );
		add_filter( 'woocommerce_hydration_request_after_callbacks', [ __CLASS__, 'edit_cart_items_data' ], 10, 3 );
	}

	/**
	 * Registers the actual data into each endpoint.
	 */
	public static function extend_store() {
		if ( is_callable( [ self::$extend, 'register_endpoint_data' ] ) ) {
			self::$extend->register_endpoint_data(
				[
					'endpoint'        => CartSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => [ __CLASS__, 'extend_cart_data' ],
					'schema_callback' => [ __CLASS__, 'extend_cart_schema' ],
					'schema_type'     => ARRAY_A,
				]
			);

			self::$extend->register_endpoint_data(
				[
					'endpoint'        => CartItemSchema::IDENTIFIER,
					'namespace'       => self::IDENTIFIER,
					'data_callback'   => [ __CLASS__, 'extend_cart_item_data' ],
					'schema_callback' => [ __CLASS__, 'extend_cart_item_schema' ],
					'schema_type'     => ARRAY_A,
				]
			);
		}
	}

	/**
	 * Register total discount data into cart endpoint.
	 *
	 * @return array Registered data or empty array if condition is not satisfied.
	 */
	public static function extend_cart_data() {
		$total_discounts = static::get_total_discounts_data();

		if ( empty( $total_discounts ) ) {
			return [];
		}

		return [
			'total_discounts' => $total_discounts,
		];
	}

	/**
	 * Register total discount schema into cart endpoint.
	 *
	 * @return array Registered schema.
	 */
	public static function extend_cart_schema() {
		return [
			'total_discounts' => [
				'description' => __( 'Total discounts data.', 'easy-woocommerce-discounts' ),
				'type'        => [ 'object', 'null' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	/**
	 * Register subscription product data into cart/items endpoint.
	 *
	 * @param array $cart_item Current cart item data.
	 *
	 * @return array $item_data Registered data or empty array if condition is not satisfied.
	 */
	public static function extend_cart_item_data( $cart_item ) {
		$item_data = array( 'data' => array() );

		if ( WCCS_Helpers::is_auto_added_product( $cart_item ) ) {
			$item_data['data']['auto_added'] = 1;
			if ( ! isset( $cart_item['_ewd_is_removable'] ) || 0 == $cart_item['_ewd_is_removable'] ) {
				$item_data['data']['not_removable'] = 1;
			}
		}

		if ( isset( $cart_item['_wccs_discounted_price'] ) ) {
			$item_data['data']['discounted'] = 1;
		}

		if ( isset( $item_data['data']['auto_added'] ) || isset( $item_data['data']['discounted'] ) ) {
			if ( ! isset( $cart_item['asnp_wepb_items'] ) && ! isset( $cart_item['asnp_wepb_parent_id'] ) ) {
				$show = (int) WCCS()->settings->get_setting( 'show_free_gift_badge', 1 );
				if ( $show && apply_filters( 'wccs_add_free_gift_badge', true, $cart_item ) ) {
					$item_data['data']['add_free_gift_badge'] = 1;
				}
			}
		}

		return $item_data;
	}

	/**
	 * Register subscription product schema into cart/items endpoint.
	 *
	 * @return array Registered schema.
	 */
	public static function extend_cart_item_schema() {
		return [
			'data' => [
				'description' => __( 'Discount data', 'easy-woocommerce-discounts' ),
				'type'        => [ 'object', 'null' ],
				'context'     => [ 'view', 'edit' ],
				'readonly'    => true,
			],
		];
	}

	public static function edit_cart_items_data( $response, $server, $request ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( false === strpos( $request->get_route(), 'wc/store' ) ) {
			return $response;
		}

		if ( ! WC()->cart ) {
			return $response;
		}

		$data = $response->get_data();
		if ( empty( $data['items'] ) ) {
			return $response;
		}

		$cart_contents = WC()->cart->get_cart();

		foreach ( $data['items'] as &$item_data ) {
			$cart_item_key = $item_data['key'];
			$cart_item     = isset( $cart_contents[ $cart_item_key ] ) ? $cart_contents[ $cart_item_key ] : null;

			if ( ! $cart_item ) {
				continue;
			}

			if ( WCCS_Helpers::is_auto_added_product( $cart_item ) ) {
				$item_data['quantity_limits'] = static::item_quantity_limits( $cart_item, $item_data['quantity_limits'] );
			}

			$custom_regular_price = apply_filters(
				'wccs_store_api_cart_item_regular_price',
				isset( $cart_item['_wccs_main_price'] ) ? (float) $cart_item['_wccs_main_price'] : null,
				$cart_item,
				$item_data,
				$response
			);

			if ( null !== $custom_regular_price && is_numeric( $custom_regular_price ) && isset( $item_data['prices'] ) ) {
				$item_data['prices'] = static::update_cart_item_regular_price( $item_data['prices'], (float) $custom_regular_price, $cart_item );
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Update regular price in Store API prices object/array.
	 *
	 * @param object|array $prices       Prices object or array.
	 * @param float        $regular_price Custom regular price.
	 * @param array        $cart_item    Cart item array.
	 * @return object|array
	 */
	protected static function update_cart_item_regular_price( $prices, $regular_price, $cart_item ) {
		$product = isset( $cart_item['data'] ) && is_a( $cart_item['data'], 'WC_Product' ) ? $cart_item['data'] : null;
		if ( $product ) {
			$tax_display_mode = get_option( 'woocommerce_tax_display_cart' );
			$price_func       = 'incl' === $tax_display_mode ? 'wc_get_price_including_tax' : 'wc_get_price_excluding_tax';
			$calc_price       = $price_func( $product, [ 'price' => $regular_price ] );
		} else {
			$calc_price = $regular_price;
		}

		$price_decimals     = wc_get_price_decimals();
		$rounding_precision = wc_get_rounding_precision();
		$formatted_price    = static::prepare_money_response( $calc_price, $price_decimals );
		$raw_price          = static::prepare_money_response( $calc_price, $rounding_precision );

		if ( is_object( $prices ) ) {
			$prices->regular_price = $formatted_price;
			if ( isset( $prices->raw_prices ) ) {
				if ( is_object( $prices->raw_prices ) ) {
					$prices->raw_prices->regular_price = $raw_price;
				} elseif ( is_array( $prices->raw_prices ) ) {
					$prices->raw_prices['regular_price'] = $raw_price;
				}
			}
		} elseif ( is_array( $prices ) ) {
			$prices['regular_price'] = $formatted_price;
			if ( isset( $prices['raw_prices'] ) ) {
				if ( is_object( $prices['raw_prices'] ) ) {
					$prices['raw_prices']->regular_price = $raw_price;
				} elseif ( is_array( $prices['raw_prices'] ) ) {
					$prices['raw_prices']['regular_price'] = $raw_price;
				}
			}
		}

		return $prices;
	}

	protected static function get_total_discounts_data() {
		if ( ! (int) WCCS()->settings->get_setting( 'display_total_discounts', 0 ) ) {
			return [];
		}

		$discount = WCCS_Total_Discounts::get_discounts();
		if ( ! $discount ) {
			return [];
		}

		$label = __( 'You Saved', 'easy-woocommerce-discounts' );
		if ( (int) WCCS()->settings->get_setting( 'localization_enabled', 1 ) ) {
			$label = WCCS()->settings->get_setting( 'total_discounts_label', $label );
		}

		return [
			'amount' => static::prepare_money_response( $discount, wc_get_price_decimals() ),
			'label'  => $label,
			'value'  => WCCS_Total_Discounts::get_discounts_html( $discount ),
		];
	}

	protected static function item_quantity_limits( $cart_item, $quantity_limits ) {
		$quantity_limits           = is_array( $quantity_limits ) ? (object) $quantity_limits : $quantity_limits;
		$quantity_limits->minimum  = $quantity_limits->maximum = $cart_item['quantity'];
		// $quantity_limits->editable = true;
		return $quantity_limits;
	}

	/**
	 * Convert monetary values from WooCommerce to string based integers, using
	 * the smallest unit of a currency.
	 *
	 * @param string|float $amount Monetary amount with decimals.
	 * @param int          $decimals Number of decimals the amount is formatted with.
	 * @param int          $rounding_mode Defaults to the PHP_ROUND_HALF_UP constant.
	 * @return string      The new amount.
	 */
	protected static function prepare_money_response( $amount, $decimals = 2, $rounding_mode = PHP_ROUND_HALF_UP ) {
		if ( static::$extend && is_callable( [ static::$extend, 'get_formatter' ] ) ) {
			return static::$extend->get_formatter( 'money' )->format(
				$amount,
				[
					'decimals'      => $decimals,
					'rounding_mode' => $rounding_mode,
				]
			);
		}

		return (string) intval(
			round(
				( (float) wc_format_decimal( $amount ) ) * ( 10 ** $decimals ),
				0,
				$rounding_mode
			)
		);
	}

}
