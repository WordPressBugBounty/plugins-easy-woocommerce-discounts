<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Helpers {

	/**
	 * Static memoization storage for display price checks.
	 *
	 * @var bool|null
	 */
	protected static $display_price_cache = null;

	/**
	 * Static memoization storage for display price HTML checks.
	 *
	 * @var array
	 */
	protected static $display_price_html_cache = array();

	/**
	 * Static memoization storage for display price without HTML check.
	 *
	 * @var bool|null
	 */
	protected static $display_price_ex_html_cache = null;

	/**
	 * Static memoization storage for primed product IDs.
	 *
	 * @var array<int, bool>
	 */
	protected static $primed_ids = array();

	/**
	 * Checking a version against WooCommerce version.
	 *
	 * @since  1.1.0
	 *
	 * @param  string $version
	 * @param  string $operator
	 *
	 * @return boolean
	 */
	public static function wc_version_check( $version = '3.0', $operator = '>=' ) {
		return version_compare( WC_VERSION, $version, $operator );
	}

	/**
	 * Wrapper for wc_get_logger function.
	 *
	 * @since  1.1.0
	 *
	 * @return WC_Logger
	 */
	public static function wc_get_logger() {
		return self::wc_version_check() ? wc_get_logger() : new WC_Logger();
	}

	/**
	 * Format a price range for display.
	 * Wrapper method for WooCommerce wc_format_price_range function.
	 *
	 * @since  1.1.0
	 *
	 * @param  string $from Price from.
	 * @param  string $to   Price to.
	 *
	 * @return string
	 */
	public static function wc_format_price_range( $from, $to ) {
		if ( self::wc_version_check() ) {
			return wc_format_price_range( $from, $to );
		}

		/* translators: 1: price from 2: price to */
		$price = sprintf( _x( '%1$s &ndash; %2$s', 'Price range: from-to', 'woocommerce' ), is_numeric( $from ) ? wc_price( $from ) : $from, is_numeric( $to ) ? wc_price( $to ) : $to );
		return apply_filters( 'woocommerce_format_price_range', $price, $from, $to );
	}

	/**
	 * Checking is product in given include and exclude products.
	 *
	 * @since  1.1.0
	 *
	 * @param  array   $include
	 * @param  array   $exclude
	 * @param  integer $product_id
	 * @param  integer $variation_id
	 *
	 * @return boolean
	 */
	public static function is_product_in_items( $include, $exclude, $product_id, $variation_id = 0 ) {
		if ( empty( $include ) && empty( $exclude ) ) {
			return false;
		}

		if ( isset( $include['all_products'] ) || isset( $include['all_categories'] ) ) {
			if ( ! empty( $exclude ) ) {
				if ( isset( $exclude[ $product_id ] ) || ( 0 < (int) $variation_id && isset( $exclude[ $variation_id ] ) ) ) {
					return false;
				}
			}
			return true;
		} elseif ( isset( $include[ $product_id ] ) || ( 0 < (int) $variation_id && isset( $include[ $variation_id ] ) ) ) {
			if ( ! empty( $exclude ) ) {
				if ( isset( $exclude[ $product_id ] ) || ( 0 < (int) $variation_id && isset( $exclude[ $variation_id ] ) ) ) {
					return false;
				}
			}
			return true;
		} elseif ( empty( $include ) && ! empty( $exclude ) ) {
			if ( ! isset( $exclude[ $product_id ] ) && ( 0 >= (int) $variation_id || ! isset( $exclude[ $variation_id ] ) ) ) {
				return true;
			}
			return false;
		}

		return false;
	}

	/**
	 * Getting term hierarchy name.
	 *
	 * @since  2.0.0
	 *
	 * @param  int|WP_Term|object $term_id
	 * @param  string             $taxonomy
	 * @param  string             $separator
	 * @param  boolean            $nicename
	 * @param  array              $visited
	 *
	 * @return string
	 */
	public static function get_term_hierarchy_name( $term_id, $taxonomy, $separator = '/', $nicename = false, $visited = array() ) {
		$chain = '';
		$term = get_term( $term_id, $taxonomy );

		if ( is_wp_error( $term ) ) {
			return '';
		}

		$name = $term->name;
		if ( $nicename ) {
			$name = $term->slug;
		}

		if ( $term->parent && ( $term->parent != $term->term_id ) && ! in_array( $term->parent, $visited ) ) {
			$visited[] = $term->parent;
			$chain .= self::get_term_hierarchy_name( $term->parent, $taxonomy, $separator, $nicename, $visited );
		}

		$chain .= $name . $separator;

		return $chain;
	}

	/**
	 * Get rounding precision for internal WC calculations.
	 * Will increase the precision of wc_get_price_decimals by 2 decimals, unless WC_ROUNDING_PRECISION is set to a higher number.
	 *
	 * @since  2.2.2
	 *
	 * @return int
	 */
	public static function wc_get_rounding_precision() {
		if ( function_exists( 'wc_get_rounding_precision' ) ) {
			return wc_get_rounding_precision();
		}

		$precision = wc_get_price_decimals() + 2;
		if ( absint( WC_ROUNDING_PRECISION ) > $precision ) {
			$precision = absint( WC_ROUNDING_PRECISION );
		}
		return $precision;
	}

	/**
	 * Add precision to a number and return a number.
	 *
	 * @since  2.2.2
	 *
	 * @param  float $value Number to add precision to.
	 * @param  bool  $round If should round after adding precision.
	 *
	 * @return int|float
	 */
	public static function wc_add_number_precision( $value, $round = true ) {
		if ( function_exists( 'wc_add_number_precision' ) ) {
			return wc_add_number_precision( $value, $round );
		}

		$cent_precision = pow( 10, wc_get_price_decimals() );
		$value = $value * $cent_precision;
		return $round ? round( $value, self::wc_get_rounding_precision() - wc_get_price_decimals() ) : $value;
	}

	/**
	 * Remove precision from a number and return a float.
	 *
	 * @since  2.2.2
	 *
	 * @param  float $value Number to add precision to.
	 * @return float
	 */
	public static function wc_remove_number_precision( $value ) {
		if ( function_exists( 'wc_remove_number_precision' ) ) {
			return wc_remove_number_precision( $value );
		}

		$cent_precision = pow( 10, wc_get_price_decimals() );
		return $value / $cent_precision;
	}

	/**
	 * Add precision to an array of number and return an array of int.
	 *
	 * @since  2.2.2
	 *
	 * @param  array $value Number to add precision to.
	 * @param  bool  $round Should we round after adding precision?.
	 *
	 * @return int|array
	 */
	public static function wc_add_number_precision_deep( $value, $round = true ) {
		if ( function_exists( 'wc_add_number_precision_deep' ) ) {
			return wc_add_number_precision_deep( $value, $round );
		}

		if ( ! is_array( $value ) ) {
			return self::wc_add_number_precision( $value, $round );
		}

		foreach ( $value as $key => $sub_value ) {
			$value[ $key ] = self::wc_add_number_precision_deep( $sub_value, $round );
		}

		return $value;
	}

	/**
	 * Remove precision from an array of number and return an array of int.
	 *
	 * @since  5.2.0
	 * @param  array $value Number to add precision to.
	 * @return int|array
	 */
	public static function wc_remove_number_precision_deep( $value ) {
		if ( function_exists( 'wc_remove_number_precision_deep' ) ) {
			return wc_remove_number_precision_deep( $value );
		}

		if ( ! is_array( $value ) ) {
			return self::wc_remove_number_precision( $value );
		}

		foreach ( $value as $key => $sub_value ) {
			$value[ $key ] = self::wc_remove_number_precision_deep( $sub_value );
		}

		return $value;
	}

	/**
	 * Returns true if the request is a non-legacy REST API request.
	 *
	 * Legacy REST requests should still run some extra code for backwards compatibility.
	 *
	 * @todo: replace this function once core WP function is available: https://core.trac.wordpress.org/ticket/42061.
	 *
	 * @return bool
	 */
	public static function wc_is_rest_api_request() {
		if ( is_callable( array( WC(), 'is_rest_api_request' ) ) ) {
			return WC()->is_rest_api_request();
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$is_rest_api_request = ( false !== strpos( $_SERVER['REQUEST_URI'], $rest_prefix ) ); // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return apply_filters( 'woocommerce_is_rest_api_request', $is_rest_api_request );
	}

	/**
	 * Check is the cart item automatically added by the plugin or no.
	 *
	 * @param  array $cart_item
	 *
	 * @return boolean
	 * @throws Exception
	 */
	public static function is_auto_added_product( $cart_item ) {
		if ( ! $cart_item ) {
			throw new Exception( 'Cart item is required.' );
		}

		return isset( $cart_item[ WCCS_Public_Auto_Add_To_Cart::CART_ITEM_ID ] ) ||
			isset( $cart_item['_ewd_auto_added_product'] ) ||
			isset( $cart_item['_ewd_urlc_auto_added_product'] );
	}

	/**
	 * Round a number using the built-in `round` function, but unless the value to round is numeric
	 * (a number or a string that can be parsed as a number), apply 'floatval' first to it
	 * (so it will convert it to 0 in most cases).
	 *
	 * This is needed because in PHP 7 applying `round` to a non-numeric value returns 0,
	 * but in PHP 8 it throws an error. Specifically, in WooCommerce we have a few places where
	 * round('') is often executed.
	 *
	 * @since 5.2.0
	 *
	 * @param mixed $val The value to round.
	 * @param int   $precision The optional number of decimal digits to round to.
	 * @param int   $mode A constant to specify the mode in which rounding occurs.
	 *
	 * @return float The value rounded to the given precision as a float, or the supplied default value.
	 */
	public static function round( $val, $precision = 0, $mode = PHP_ROUND_HALF_UP ) {
		if ( ! is_numeric( $val ) ) {
			$val = floatval( $val );
		}
		return round( $val, $precision, $mode );
	}

	/**
	 * Maybe exchange price with multicurrency plugins.
	 *
	 * @param mixed  $price
	 * @param string $type
	 *
	 * @return mixed
	 */
	public static function maybe_exchange_price( $price, $type = 'product' ) {
		if ( empty( $price ) ) {
			return $price;
		}

		return apply_filters( 'wccs_maybe_exchange_price', $price, $type );
	}

	/**
	 * Reset static memoization caches for display price checks.
	 *
	 * @return void
	 */
	public static function reset_display_price_cache() {
		self::$display_price_cache         = null;
		self::$display_price_html_cache    = array();
		self::$display_price_ex_html_cache = null;
		self::$primed_ids                  = array();
	}

	/**
	 * Check whether display price is set to change without HTML strikethrough.
	 *
	 * @return bool
	 */
	public static function is_change_display_price_ex_html() {
		if ( null !== self::$display_price_ex_html_cache ) {
			return self::$display_price_ex_html_cache;
		}

		return self::$display_price_ex_html_cache = ( 'simple_ex_html' === WCCS()->settings->get_setting( 'change_display_price', 'simple' ) );
	}

	/**
	 * Check whether display prices should be changed by simple pricing rules.
	 * Decoupled from runtime condition evaluation; checks setting and rule presence.
	 *
	 * @return bool
	 */
	public static function should_change_display_price() {
		if ( null !== self::$display_price_cache ) {
			return self::$display_price_cache;
		}

		$wccs = WCCS();

		if ( 'none' === $wccs->settings->get_setting( 'change_display_price', 'simple' ) ) {
			return self::$display_price_cache = false;
		}

		return self::$display_price_cache = $wccs->pricing->has_simple_rules();
	}

	/**
	 * Check whether display price HTML filter should be hooked.
	 * Decoupled from runtime condition evaluation; checks setting and rule presence.
	 *
	 * @param string $type 'simple' or 'all'
	 * @return bool
	 */
	public static function should_change_display_price_html( $type = 'simple' ) {
		if ( isset( self::$display_price_html_cache[ $type ] ) ) {
			return self::$display_price_html_cache[ $type ];
		}

		$wccs = WCCS();

		$change = $wccs->settings->get_setting( 'change_display_price', 'simple' );
		if ( 'all' !== $change && 'simple' !== $change ) {
			return self::$display_price_html_cache[ $type ] = false;
		}

		$has_simple = $wccs->pricing->has_simple_rules();

		if ( $has_simple ) {
			return self::$display_price_html_cache[ $type ] = true;
		}

		if ( 'all' === $change && 'all' === $type ) {
			return self::$display_price_html_cache[ $type ] = $wccs->pricing->has_bulk_rules();
		}

		return self::$display_price_html_cache[ $type ] = false;
	}

	public static function is_allowed_auto_add_product_type( $type ) {
		if ( empty( $type ) ) {
			return false;
		}

		$types = apply_filters(
			'wccs_not_allowed_auto_add_product_types',
			array(
				'variable',
				'composite',
				'booking',
			)
		);

		foreach ( $types as $not_allowed ) {
			if (
				$type === $not_allowed ||
				false !== strpos( $type, $not_allowed )
			) {
				return false;
			}
		}

		return true;
	}

	public static function maybe_get_exact_item_id( $id, $type = 'product' ) {
		if ( ! is_numeric( $id ) || 0 >= $id ) {
			return absint( $id );
		}

		return apply_filters( 'wccs_exact_item_id', absint( $id ), $type );
	}

	public static function maybe_get_exact_category_id( $id ) {
		return self::maybe_get_exact_item_id( $id, 'product_cat' );
	}

	public static function maybe_get_exact_tag_id( $id ) {
		return self::maybe_get_exact_item_id( $id, 'product_tag' );
	}

	public static function maybe_get_exact_product( $product ) {
		if ( ! $product ) {
			return $product;
		}

		if ( $product instanceof WC_Product ) {
			return apply_filters( 'wccs_exact_product', $product );
		} elseif ( is_numeric( $product ) ) {
			return self::maybe_get_exact_item_id( $product, 'product' );
		}

		return $product;
	}

	public static function is_product_page() {
		if ( is_product() ) {
			return true;
		}

		global $post;
		return ! empty( $post->post_content ) && false !== strpos( $post->post_content, '[product_page' );
	}

	public static function get_applying_coupon() {
		// If it's a WooCommerce REST request (Cart Block), retrieve the 'code' parameter from JSON payload
		if (
			! empty( $_SERVER['REQUEST_URI'] ) &&
			false !== strpos( $_SERVER['REQUEST_URI'], '/wc/store' )
		) {
			$data = file_get_contents( 'php://input' );
			$data = json_decode( $data, true );

			if (
				is_array( $data ) &&
				isset( $data['requests'][0]['path'] ) &&
				false !== strpos( $data['requests'][0]['path'], '/apply-coupon' ) &&
				isset( $data['requests'][0]['data']['code'] )
			) {
				return wc_format_coupon_code( wp_unslash( $data['requests'][0]['data']['code'] ) );
			}
		}

		if ( ! check_ajax_referer( 'apply-coupon', 'security', false ) ) {
			return '';
		}

		// Fallback for traditional WooCommerce cart form submission
		return ! empty( $_POST['coupon_code'] ) ? wc_format_coupon_code( wp_unslash( $_POST['coupon_code'] ) ) : '';
	}

	public static function get_billing_email() {
		$email = '';

		if ( isset( WC()->customer ) ) {
			$email = WC()->customer->get_billing_email();
		}

		if ( empty( $email ) && ! empty( $_POST['billing_email'] ) ) {
			$email = $_POST['billing_email'];
		} elseif ( empty( $email ) && ! empty( $_POST['post_data'] ) ) {
			parse_str( $_POST['post_data'], $post );
			if ( ! empty( $post['billing_email'] ) ) {
				$email = $post['billing_email'];
			}
		}

		if ( empty( $email ) && ! empty( $_SERVER['REQUEST_URI'] ) && false !== strpos( $_SERVER['REQUEST_URI'], '/wc/store' ) ) {
			$data = file_get_contents( 'php://input' );
			$data = json_decode( $data, true );

			if (
				is_array( $data ) &&
				isset( $data['requests'][0]['path'] ) &&
				false !== strpos( $data['requests'][0]['path'], '/update-customer' ) &&
				isset( $data['requests'][0]['data'] )
			) {
				$data = $data['requests'][0]['data'];
				if ( isset( $data['billing_address']['email'] ) ) {
					$email = $data['billing_address']['email'];
				}
			}
		}

		$email = strtolower( sanitize_email( $email ) );

		return is_email( $email ) ? $email : '';
	}

	public static function get_used_by() {
		$used_by = get_current_user_id();
		$used_by = empty( $used_by ) ? static::get_billing_email() : $used_by;
		return $used_by;
	}

	public static function register_polyfills( $react = false ) {
		static $registered;
		if ( $registered ) {
			return;
		}

		global $wp_version;

		$handles = array(
			'wp-i18n' => array( '6.0', array() ),
			'wp-hooks' => array( '6.0', array() ),
			'wp-api-fetch' => array( '6.0', array() ),
			'moment' => array( '2.29.4', array() ),
			'lodash' => array( '4.17.21', array() ),
		);
		if ( $react ) {
			$handles['react'] = array( '17.0.2', array() );
			$handles['react-dom'] = array( '17.0.2', array( 'react' ) );
		}

		foreach ( $handles as $handle => $value ) {
			if ( ! version_compare( $wp_version, '5.9', '>=' ) && in_array( $handle, array( 'react', 'react-dom' ) ) ) {
				wp_deregister_script( $handle );
			}

			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script(
					$handle,
					plugins_url( 'admin/js/vendor/' . $handle . '.js', WCCS_PLUGIN_FILE ),
					$value[1],
					$value[0],
					true
				);
			}
		}

		$registered = true;
	}

	public static function get_review() {
		return get_option( 'asnp_ewd_review', array() );
	}

	public static function set_review( $review ) {
		return update_option( 'asnp_ewd_review', $review );
	}

	public static function maybe_show_review() {
		$review = self::get_review();
		if ( isset( $review['dismissed'] ) ) {
			return false;
		}

		if ( 0 >= WCCS()->conditions->count() ) {
			return false;
		}

		$schedule = strtotime( '+7 days' );
		if ( empty( $review['schedule'] ) ) {
			$review['schedule'] = $schedule;
			self::set_review( $review );
		} else {
			$schedule = (int) $review['schedule'];
		}

		if ( empty( $schedule ) || time() < $schedule ) {
			return false;
		}

		return true;
	}

	/**
	 * Get product discounted price by plugin pricing rules.
	 *
	 * @param int|WC_Product $product
	 * @param float|null $custom_price
	 * @param bool $exchange_price
	 * @param WCCS_Public_Product_Pricing|null $product_pricing
	 * @return float|null
	 */
	public static function get_product_discounted_price( $product, $custom_price = null, $exchange_price = false, $product_pricing = null ) {
		if ( ! $product || ! WCCS()->pricing ) {
			return $custom_price;
		}

		$price_cache = WCCS()->container()->get( WCCS_Product_Price_Cache::class );
		if ( $price_cache ) {
			$price = $price_cache->get_price( $product, $custom_price, 'price', $custom_price, $product_pricing );
		} else {
			$product_id = is_numeric( $product ) ? (int) $product : (int) $product->get_id();
			if ( ! is_a( $product_pricing, 'WCCS_Public_Product_Pricing' ) || (int) $product_pricing->product_id !== $product_id ) {
				$product_pricing = new WCCS_Public_Product_Pricing( $product );
			}
			$price = $product_pricing->get_price( $custom_price );
		}
		
		if ( ! is_numeric( $price ) || 0 > $price ) {
			return $custom_price;
		}

		if ( $price != $custom_price && $exchange_price ) {
			$price = static::maybe_exchange_price( $price );
		}

		return $price;
	}

	public static function get_archive_optimized_variation_ids( $product ) {
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false;
		}

		$threshold = (int) WCCS()->settings->get_setting( 'variation_archive_pruning', 0 );
		if ( $threshold <= 0 ) {
			return false;
		}

		$variation_ids = $product->get_visible_children();
		if ( count( $variation_ids ) <= $threshold ) {
			return false;
		}

		if ( ! is_single( $product->get_id() ) && ( is_shop() || is_product_category() || is_product_tag() || is_product_taxonomy() ) ) {
			$prices = $product->get_variation_prices( true );
			if ( empty( $prices['price'] ) ) {
				return false;
			}

			$prices = 'regular_price' === WCCS()->settings->get_setting( 'on_sale_products_price', 'on_sale_price' ) ? $prices['regular_price'] : $prices['price'];

			$min_price = min( $prices );
			$max_price = max( $prices );

			$min_ids = array_keys( $prices, $min_price );
			$max_ids = array_keys( $prices, $max_price );

			$min_id = $min_ids[0];
			$max_id = $max_ids[0];

			$result = array( $min_id );
			if ( $max_id !== $min_id ) {
				$result[] = $max_id;
			}

			return $result;
		}

		return false;
	}

	/**
	 * Preload posts and postmeta caches for a list of product or variation IDs in bulk.
	 *
	 * Eliminates N+1 database queries when instantiating child variations.
	 * Uses WordPress 6.1+ _prime_post_caches() or fallback to update_meta_cache().
	 *
	 * @since 9.12.0
	 *
	 * @param array $product_ids
	 * @return void
	 */
	public static function prime_product_caches( $product_ids ) {
		if ( empty( $product_ids ) || ! is_array( $product_ids ) ) {
			return;
		}

		$ids = array_values( array_filter( array_map( 'absint', $product_ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$to_prime = array();
		foreach ( $ids as $id ) {
			if ( ! isset( self::$primed_ids[ $id ] ) ) {
				self::$primed_ids[ $id ] = true;
				$to_prime[]              = $id;
			}
		}

		if ( empty( $to_prime ) ) {
			return;
		}

		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $to_prime, true, true );
		} else {
			if ( function_exists( 'update_meta_cache' ) ) {
				update_meta_cache( 'post', $to_prime );
			}
		}

		if ( ! wp_using_ext_object_cache() ) {
			WCCS()->WCCS_Product_Price_Cache->prime_products_db_cache( $to_prime );
			WCCS()->WCCS_Product_Onsale_Cache->prime_products_db_cache( $to_prime );
			if ( WCCS()->WCCS_Product_Badge_Cache->is_percentage_badge_enabled() ) {
				WCCS()->WCCS_Product_Badge_Cache->prime_products_db_cache( $to_prime );
			}
		}
	}

	/**
	 * Fast O(N) integer array deduplication with minimal memory footprint.
	 *
	 * Uses hash-map lookup instead of expensive array_unique copies.
	 * Filters out zero, negative, and non-numeric values while preserving insertion order.
	 *
	 * @since 9.12.0
	 *
	 * @param  array $array
	 * @return array
	 */
	public static function fast_dedup_ids( array $array ) {
		if ( empty( $array ) ) {
			return array();
		}

		$seen   = array();
		$result = array();
		foreach ( $array as $id ) {
			$id = (int) $id;
			if ( $id > 0 && ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$result[]    = $id;
			}
		}
		return $result;
	}

}
