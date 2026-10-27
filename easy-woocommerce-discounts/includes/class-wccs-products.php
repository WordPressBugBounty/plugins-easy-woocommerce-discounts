<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Products {

	protected $custom_taxonomies;

	/**
	 * In-memory cache for get_discounted_products() per request.
	 *
	 * @var array
	 */
	protected $discounted_products_cache = array();

	/**
	 * In-memory memoized registry of transient keys for the current request.
	 *
	 * @var array|null
	 */
	protected $registered_transient_keys = null;

	/**
	 * Memoized published product IDs for current request.
	 *
	 * @var array|null
	 */
	protected $all_published_product_ids = null;

	public function get_products( array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'status'         => array( 'draft', 'pending', 'private', 'publish' ),
			'type'           => array_merge( array_keys( wc_get_product_types() ) ),
			'parent'         => null,
			'sku'            => '',
			'category'          => array(),
			'category_tax_opts' => array(),
			'tag'               => array(),
			'tag_tax_opts'      => array(
				'field'    => 'slug',
				'operator' => 'IN',
			),
			'limit'          => get_option( 'posts_per_page' ),
			'offset'         => null,
			'page'           => 1,
			'include'        => array(),
			'exclude'        => array(),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'return'         => 'objects',
			'paginate'       => false,
			'shipping_class' => array(),
			'meta_query'     => array(),
			'tax_query'      => array(),
			'date_query'     => array(),
			'post_title'     => '',
			'post_id'        => '',
		) );

		/**
		 * Generate WP_Query args.
		 */
		$wp_query_args = array(
			'post_type'      => 'variation' === $args['type'] ? 'product_variation' : 'product',
			'post_status'    => $args['status'],
			'posts_per_page' => $args['limit'],
			'meta_query'     => $args['meta_query'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'tax_query'      => $args['tax_query'],
			'date_query'     => $args['date_query'],
		);
		// Do not load unnecessary post data if the user only wants IDs.
		if ( 'ids' === $args['return'] ) {
			$wp_query_args['fields'] = 'ids';
		}

		if ( 'variation' !== $args['type'] ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => $args['type'],
			);
		}

		if ( ! empty( $args['sku'] ) ) {
			$wp_query_args['meta_query'][] = array(
				'key'     => '_sku',
				'value'   => $args['sku'],
				'compare' => 'LIKE',
			);
		}

		if ( ! empty( $args['category'] ) ) {
			if ( ! empty( $args['category_tax_opts']['field'] ) ) {
				$cat_field = $args['category_tax_opts']['field'];
			} else {
				$first_cat = is_array( $args['category'] ) ? reset( $args['category'] ) : $args['category'];
				$cat_field = is_numeric( $first_cat ) ? 'term_id' : 'slug';
			}

			$cat_tax_query = array(
				'taxonomy' => 'product_cat',
				'field'    => $cat_field,
				'terms'    => $args['category'],
				'operator' => isset( $args['category_tax_opts']['operator'] ) ? $args['category_tax_opts']['operator'] : 'IN',
			);

			if ( isset( $args['category_tax_opts']['include_children'] ) ) {
				$cat_tax_query['include_children'] = (bool) $args['category_tax_opts']['include_children'];
			}

			$wp_query_args['tax_query'][] = $cat_tax_query;
		}

		if ( ! empty( $args['tag'] ) ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => 'product_tag',
				'field'    => isset( $args['tag_tax_opts']['field'] ) ? $args['tag_tax_opts']['field'] : 'slug',
				'terms'    => $args['tag'],
				'operator' => isset( $args['tag_tax_opts']['operator'] ) ? $args['tag_tax_opts']['operator'] : 'IN',
			);
		}

		if ( ! empty( $args['shipping_class'] ) ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => 'product_shipping_class',
				'field'    => 'slug',
				'terms'    => $args['shipping_class'],
			);
		}

		// Custom product taxonomies.
		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['taxonomies'] ) ) {
			$wp_query_args['tax_query'][] = array(
				'taxonomy' => sanitize_text_field( $args['taxonomy'] ),
				'field'    => 'term_id',
				'terms'    => $args['taxonomies'],
				'operator' => isset( $args['tax_opts']['operator'] ) ? $args['tax_opts']['operator'] : 'IN',
			);
		}

		if ( ! is_null( $args['parent'] ) ) {
			$wp_query_args['post_parent'] = absint( $args['parent'] );
		}

		if ( ! is_null( $args['offset'] ) ) {
			$wp_query_args['offset'] = absint( $args['offset'] );
		} else {
			$wp_query_args['paged'] = absint( $args['page'] );
		}

		if ( ! empty( $args['include'] ) ) {
			$wp_query_args['post__in'] = array_map( 'absint', $args['include'] );
		}

		if ( ! empty( $args['exclude'] ) ) {
			$wp_query_args['post__not_in'] = array_map( 'absint', $args['exclude'] );
		}

		if ( ! $args['paginate'] ) {
			$wp_query_args['no_found_rows'] = true;
		}

		if ( ! empty( $args['meta_key'] ) ) {
			$wp_query_args['meta_key'] = $args['meta_key'];
		}

		if ( ! empty( $args['post_title'] ) ) {
			$wp_query_args['wccs_post_title'] = $args['post_title'];
		}

		if ( ! empty( $args['post_id'] ) ) {
			$wp_query_args['wccs_post_id'] = $args['post_id'];
		}

		$wp_query_args = apply_filters( 'wccs_products_query', $wp_query_args, $args );

		// Get results.
		$products = new WP_Query( $wp_query_args );

		if ( 'wp_query' === strtolower( $args['return'] ) ) {
			$return = $products;
		} elseif ( 'objects' === $args['return'] ) {
			$return = array_map( 'wc_get_product', $products->posts );
		} else {
			$return = $products->posts;
		}

		if ( $args['paginate'] ) {
			return (object) array(
				'products'      => $return,
				'total'         => $products->found_posts,
				'max_num_pages' => $products->max_num_pages,
			);
		} else {
			return $return;
		}
	}

	public function get_top_rated_products( $limit = 12, $return = 'ids' ) {
		return array();
	}

	public function get_recently_viewed_products( $limit = 12 ) {
		return array();
	}

	public function get_categories( array $args = array() ) {
		$defaults = array(
			'separator'          => '/',
			'nicename'           => false,
			'pad_counts'         => 1,
			'show_count'         => 1,
			'hierarchical'       => 1,
			'hide_empty'         => 0,
			'show_uncategorized' => 0,
			'orderby'            => 'name',
			'menu_order'         => false,
		);

		$args = wp_parse_args( $args, $defaults );

		if ( 'order' === $args['orderby'] ) {
			$args['menu_order'] = 'asc';
			$args['orderby']    = 'name';
		}

		$terms = get_terms( 'product_cat', apply_filters( 'wccs_wc_products_get_categories_args', $args ) );

		if ( empty( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $category ) {
			$categories[] = (object) array(
				'id'   => $category->term_id,
				'text' => rtrim( WCCS_Helpers::get_term_hierarchy_name( $category->term_id, 'product_cat', $args['separator'], $args['nicename'] ), $args['separator'] ),
				'slug' => $category->slug,
				'name' => $category->name,
			);
		}

		return $categories;
	}

	public function get_categories_not_in_list( array $categories ) {
		$all_categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'fields'     => 'ids',
			'hide_empty' => false,
		) );

		if ( is_wp_error( $all_categories ) || empty( $all_categories ) ) {
			return array();
		}

		return array_diff( $all_categories, $categories );
	}

	public function get_tags( array $args = array() ) {
		return array();
	}

	public function get_products_have_tags( array $tags, $have = 'at_least_one_of', $return_only_ids = true ) {
		return array();
	}

	public function get_categories_products( array $categories, $return_only_ids = true ) {
		if ( empty( $categories ) ) {
			return array();
		}

		$category_ids = array();
		foreach ( $categories as $cat ) {
			if ( is_numeric( $cat ) ) {
				$category_ids[] = absint( $cat );
			} else {
				$term = get_term_by( 'slug', $cat, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$category_ids[] = $term->term_id;
				}
			}
		}

		$category_ids = WCCS_Helpers::fast_dedup_ids( $category_ids );
		if ( empty( $category_ids ) ) {
			return array();
		}

		$all_category_ids = $category_ids;
		foreach ( $category_ids as $cat_id ) {
			$children = get_term_children( $cat_id, 'product_cat' );
			if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
				$all_category_ids = array_merge( $all_category_ids, $children );
			}
		}
		$all_category_ids = WCCS_Helpers::fast_dedup_ids( $all_category_ids );

		if ( empty( $all_category_ids ) ) {
			return array();
		}

		if ( $return_only_ids ) {
			$product_ids = get_objects_in_term( $all_category_ids, 'product_cat' );
			if ( is_wp_error( $product_ids ) || empty( $product_ids ) ) {
				return array();
			}
			return WCCS_Helpers::fast_dedup_ids( $product_ids );
		}

		$args = array(
			'status'            => 'publish',
			'category'          => $all_category_ids,
			'category_tax_opts' => array(
				'field' => 'term_id',
			),
			'limit'             => -1,
		);

		$products = $this->get_products( $args );

		if ( empty( $products ) ) {
			return array();
		}

		return $products;
	}

	/**
	 * Get products by specified price value and type.
	 *
	 * @since  2.0.0
	 *
	 * @param  array $args
	 *
	 * @return array
	 */
	public function get_products_by_price( array $args ) {
		return array();
	}

	/**
	 * Get products by stock quantity value.
	 *
	 * @since  2.0.0
	 *
	 * @param  array $args
	 *
	 * @return array
	 */
	public function get_products_by_stock_quantity( array $args ) {
		return array();
	}

	/**
	 * Get all published product IDs directly from DB with maximum speed and zero WP_Query overhead.
	 *
	 * @return array
	 */
	public function get_all_published_product_ids() {
		if ( null !== $this->all_published_product_ids ) {
			return $this->all_published_product_ids;
		}

		global $wpdb;
		$results = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		$this->all_published_product_ids = ! empty( $results ) ? array_map( 'absint', $results ) : array();

		return $this->all_published_product_ids;
	}

	/**
	 * Getting discounted products.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $args
	 *
	 * @return array
	 */
	public function get_discounted_products( array $args = array() ) {
		$args = wp_parse_args( $args, array(
			'status'          => 'publish',
			'return'          => 'ids',
			'limit'           => -1,
			'include'         => array(),
			'exclude'         => array(),
			'onsale_products' => true,
		) );

		$user_id = get_current_user_id();

		// 1. Fast in-memory request cache check (0.0001ms)
		// Memoizes within the current HTTP request to prevent redundant rule evaluations during the same page load.
		$req_cache_key = md5( wp_json_encode( $args ) . '_' . $user_id );
		if ( isset( $this->discounted_products_cache[ $req_cache_key ] ) ) {
			return $this->discounted_products_cache[ $req_cache_key ];
		}

		$onsale_cache = null;
		$get_onsale   = function() use ( &$onsale_cache, $args ) {
			if ( null !== $onsale_cache ) {
				return $onsale_cache;
			}
			if ( ! empty( $args['onsale_products'] ) ) {
				$raw          = wc_get_product_ids_on_sale();
				$onsale_cache = ( ! empty( $raw ) && is_array( $raw ) ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();
			} else {
				$onsale_cache = array();
			}
			return $onsale_cache;
		};

		$wccs_pricing = WCCS()->pricing;
		$can_persist  = 'ids' === $args['return'] && ! $wccs_pricing->has_dynamic_rules();

		// 2. Fast persistent cache check (0.2ms)
		// When all store rules are static (thousands of static rules scenario),
		// skip evaluating rules, selectors, and database queries entirely!
		$transient_key = '';
		if ( $can_persist ) {
			$segment       = WCCS_Rules_Helpers::get_user_segment();
			$cache_args    = $args;
			unset( $cache_args['onsale_products'] );
			$transient_key = 'asnp_wccs_dp_' . substr( md5( $segment . '_' . wp_json_encode( $cache_args ) ), 0, 24 );

			$cached_products = get_transient( $transient_key );
			if ( false !== $cached_products && ( 'all_products' === $cached_products || is_array( $cached_products ) ) ) {
				if ( 'all_products' === $cached_products ) {
					$result = 'all_products';
				} else {
					$result = $cached_products;
					if ( ! empty( $args['onsale_products'] ) ) {
						$onsale = $get_onsale();
						if ( ! empty( $onsale ) ) {
							$result = WCCS_Helpers::fast_dedup_ids( array_merge( $result, $onsale ) );
						}
					}
					if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) && ! empty( $result ) ) {
						$caller_excludes = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
						$result = array_values( array_filter( $result, function( $id ) use ( $caller_excludes ) {
							return ! isset( $caller_excludes[ $id ] );
						} ) );
					}
					$result = array_values( array_unique( array_map( 'WCCS_Helpers::maybe_get_exact_product', $result ) ) );
				}
				$this->discounted_products_cache[ $req_cache_key ] = $result;
				return $result;
			}
		}

		$pricings = array();

		if ( ! empty( $args['pricing_type'] ) ) {
			if ( 'simple' === $args['pricing_type'] ) {
				$pricings['simple'] = $wccs_pricing->get_simple_pricings();
			} elseif ( 'bulk' === $args['pricing_type'] ) {
				$pricings['bulk'] = $wccs_pricing->get_bulk_pricings();
			}
		} else {
			$pricings = $wccs_pricing->get_pricings( array( 'simple', 'bulk' ) );
		}

		// Collect all active rules for the current runtime context
		$all_active_rules = array();
		foreach ( $pricings as $type => $discounts ) {
			if ( ! empty( $discounts ) && is_array( $discounts ) ) {
				foreach ( $discounts as $discount ) {
					$discount_type = '';
					if ( is_array( $discount ) && isset( $discount['discount_type'] ) ) {
						$discount_type = $discount['discount_type'];
					} elseif ( is_object( $discount ) && isset( $discount->discount_type ) ) {
						$discount_type = $discount->discount_type;
					}

					if ( in_array( $discount_type, array( 'percentage_fee', 'price_fee' ), true ) ) {
						continue;
					}

					$all_active_rules[] = $discount;
				}
			}
		}

		// If no rules are active at this runtime, cache empty array for plugin rules and return on-sale or empty
		if ( empty( $all_active_rules ) ) {
			if ( $can_persist && ! empty( $transient_key ) ) {
				$ttl         = DAY_IN_SECONDS * 30;
				$all_rules   = $wccs_pricing->get_all_pricing_rules();
				$expiry      = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
				$ttl         = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
				$this->set_discounted_transient( $transient_key, array(), $ttl );
			}
			$result = array();
			if ( ! empty( $args['onsale_products'] ) ) {
				$onsale = $get_onsale();
				if ( ! empty( $onsale ) ) {
					$result = $onsale;
				}
			}
			if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) && ! empty( $result ) ) {
				$caller_excludes = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
				$result = array_values( array_filter( $result, function( $id ) use ( $caller_excludes ) {
					return ! isset( $caller_excludes[ $id ] );
				} ) );
			}
			$result = array_values( array_unique( array_map( 'WCCS_Helpers::maybe_get_exact_product', $result ) ) );
			$this->discounted_products_cache[ $req_cache_key ] = $result;
			return $result;
		}

		$product_selector = new WCCS_Discounted_Products_Selector();

		// 1. Early Short-Circuit Check:
		// If any active rule discounts all products without rule exclusions,
		// all products are discounted. No other rules need to be evaluated!
		$has_all_products        = false;
		$all_products_is_dynamic = false;

		foreach ( $all_active_rules as $discount ) {
			if ( empty( $discount['items'] ) || ! empty( $discount['exclude_items'] ) ) {
				continue;
			}

			$products = $product_selector->get_products( $discount['items'] );
			if ( 'all_products' === $products ) {
				$has_all_products        = true;
				$all_products_is_dynamic = ! WCCS_Rules_Helpers::can_cache_rules( array( $discount ), true );
				break;
			}
		}

		// Check global exclude rules
		$exclude_rules = $wccs_pricing->get_exclude_rules();

		// If all products are discounted and there are NO exclusions (global, rule, or caller) and NO include restriction:
		// Return 'all_products' immediately with ZERO database queries in class-wccs-products.php.
		if ( $has_all_products && empty( $exclude_rules ) && empty( $args['exclude'] ) && empty( $args['include'] ) ) {
			if ( $can_persist && ! $all_products_is_dynamic && ! empty( $transient_key ) ) {
				$ttl         = DAY_IN_SECONDS * 30;
				$all_rules   = $wccs_pricing->get_all_pricing_rules();
				$expiry      = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
				$ttl         = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
				$this->set_discounted_transient( $transient_key, 'all_products', $ttl );
			}

			$this->discounted_products_cache[ $req_cache_key ] = 'all_products';
			return 'all_products';
		}

		$static_matched_ids  = array();
		$dynamic_matched_ids = array();

		if ( ! $has_all_products ) {
			// Separate active rules into static and dynamic rules
			$static_active_rules  = array();
			$dynamic_active_rules = array();

			foreach ( $all_active_rules as $discount ) {
				if ( WCCS_Rules_Helpers::can_cache_rules( array( $discount ), true ) ) {
					$static_active_rules[] = $discount;
				} else {
					$dynamic_active_rules[] = $discount;
				}
			}

			// Fast path for static rules: cache matched product IDs persistently by segment
			if ( ! empty( $static_active_rules ) ) {
				$segment        = WCCS_Rules_Helpers::get_user_segment();
				$static_ids_key = 'asnp_wccs_s_ids_' . substr( md5( $segment ), 0, 24 );

				$cached_static_ids = get_transient( $static_ids_key );
				if ( false !== $cached_static_ids ) {
					if ( 'all_products' === $cached_static_ids ) {
						$has_all_products = true;
					} elseif ( is_array( $cached_static_ids ) ) {
						$static_matched_ids = array_map( 'absint', $cached_static_ids );
					}
				} else {
					foreach ( $static_active_rules as $discount ) {
						if ( empty( $discount['items'] ) ) {
							continue;
						}

						$products = $product_selector->get_products( $discount['items'] );
						if ( empty( $products ) ) {
							continue;
						}

						if ( 'all_products' === $products ) {
							if ( ! empty( $discount['exclude_items'] ) ) {
								$excluded = $product_selector->get_products( $discount['exclude_items'] );
								if ( 'all_products' === $excluded ) {
									continue;
								}
								$all_ids = $this->get_all_published_product_ids();
								if ( ! empty( $all_ids ) && is_array( $all_ids ) ) {
									if ( ! empty( $excluded ) && is_array( $excluded ) ) {
										$ex_map = array_fill_keys( array_map( 'absint', $excluded ), true );
										$filtered = array();
										foreach ( $all_ids as $aid ) {
											if ( ! isset( $ex_map[ $aid ] ) ) {
												$filtered[] = $aid;
											}
										}
										$all_ids = $filtered;
									}
									$static_matched_ids = array_merge( $static_matched_ids, $all_ids );
								}
							} else {
								$has_all_products = true;
								break; // All products are discounted, no need to evaluate subsequent static rules.
							}
							continue;
						}

						if ( is_array( $products ) ) {
							$products = array_values( array_filter( array_map( 'absint', $products ) ) );
							if ( ! empty( $discount['exclude_items'] ) ) {
								$excluded = $product_selector->get_products( $discount['exclude_items'] );
								if ( 'all_products' === $excluded ) {
									continue;
								}
								if ( ! empty( $excluded ) && is_array( $excluded ) ) {
									$products = array_diff( $products, array_map( 'absint', $excluded ) );
								}
							}
							if ( ! empty( $products ) ) {
								$static_matched_ids = array_merge( $static_matched_ids, $products );
							}
						}
					}

					$ttl    = DAY_IN_SECONDS * 30;
					$expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $static_active_rules );
					$ttl    = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;

					if ( $has_all_products ) {
						$this->set_discounted_transient( $static_ids_key, 'all_products', $ttl );
					} else {
						$static_matched_ids = WCCS_Helpers::fast_dedup_ids( $static_matched_ids );
						$this->set_discounted_transient( $static_ids_key, $static_matched_ids, $ttl );
					}
				}
			}

			// Dynamic rules are evaluated dynamically at runtime for the current cart/customer.
			// If all products are already discounted by a static rule, skip dynamic rules.
			if ( ! $has_all_products && ! empty( $dynamic_active_rules ) ) {
				foreach ( $dynamic_active_rules as $discount ) {
					if ( empty( $discount['items'] ) ) {
						continue;
					}

					$products = $product_selector->get_products( $discount['items'] );
					if ( empty( $products ) ) {
						continue;
					}

					if ( 'all_products' === $products ) {
						if ( ! empty( $discount['exclude_items'] ) ) {
							$excluded = $product_selector->get_products( $discount['exclude_items'] );
							if ( 'all_products' === $excluded ) {
								continue;
							}
							$all_ids = $this->get_all_published_product_ids();
							if ( ! empty( $all_ids ) && is_array( $all_ids ) ) {
								if ( ! empty( $excluded ) && is_array( $excluded ) ) {
									$ex_map = array_fill_keys( array_map( 'absint', $excluded ), true );
									$filtered = array();
									foreach ( $all_ids as $aid ) {
										if ( ! isset( $ex_map[ $aid ] ) ) {
											$filtered[] = $aid;
										}
									}
									$all_ids = $filtered;
								}
								$dynamic_matched_ids = array_merge( $dynamic_matched_ids, $all_ids );
							}
						} else {
							$has_all_products        = true;
							$all_products_is_dynamic = true;
							break; // All products are discounted, no need to evaluate subsequent dynamic rules.
						}
						continue;
					}

					if ( is_array( $products ) ) {
						$products = array_values( array_filter( array_map( 'absint', $products ) ) );
						if ( ! empty( $discount['exclude_items'] ) ) {
							$excluded = $product_selector->get_products( $discount['exclude_items'] );
							if ( 'all_products' === $excluded ) {
								continue;
							}
							if ( ! empty( $excluded ) && is_array( $excluded ) ) {
								$products = array_diff( $products, array_map( 'absint', $excluded ) );
							}
						}
						if ( ! empty( $products ) ) {
							$dynamic_matched_ids = array_merge( $dynamic_matched_ids, $products );
						}
					}
				}

				$dynamic_matched_ids = WCCS_Helpers::fast_dedup_ids( $dynamic_matched_ids );
			}

			// If all products became true during dynamic rules and no exclusions exist:
			if ( $has_all_products && empty( $exclude_rules ) && empty( $args['exclude'] ) && empty( $args['include'] ) ) {
				if ( $can_persist && ! $all_products_is_dynamic && ! empty( $transient_key ) ) {
					$ttl         = DAY_IN_SECONDS * 30;
					$all_rules   = $wccs_pricing->get_all_pricing_rules();
					$expiry      = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
					$ttl         = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
					$this->set_discounted_transient( $transient_key, 'all_products', $ttl );
				}

				$this->discounted_products_cache[ $req_cache_key ] = 'all_products';
				return 'all_products';
			}
		}

		if ( $has_all_products ) {
			if ( ! empty( $args['include'] ) && is_array( $args['include'] ) ) {
				$matched_product_ids = WCCS_Helpers::fast_dedup_ids( $args['include'] );
			} else {
				$matched_product_ids = $this->get_all_published_product_ids();
			}
		} else {
			$matched_product_ids = WCCS_Helpers::fast_dedup_ids( array_merge( $static_matched_ids, $dynamic_matched_ids ) );
		}

		// Filter out products in global exclude rules via O(1) set subtraction (eliminates N-queries)
		if ( ! empty( $matched_product_ids ) && ! empty( $exclude_rules ) ) {
			$all_excluded_ids = array();
			foreach ( $exclude_rules as $exclude ) {
				if ( empty( $exclude['items'] ) ) {
					continue;
				}
				$ex_products = $product_selector->get_products( $exclude['items'] );
				if ( 'all_products' === $ex_products ) {
					$matched_product_ids = array();
					break;
				} elseif ( ! empty( $ex_products ) && is_array( $ex_products ) ) {
					foreach ( $ex_products as $ex_id ) {
						$all_excluded_ids[ (int) $ex_id ] = true;
					}
				}
			}

			if ( ! empty( $matched_product_ids ) && ! empty( $all_excluded_ids ) ) {
				$filtered = array();
				foreach ( $matched_product_ids as $pid ) {
					$pid = (int) $pid;
					if ( ! isset( $all_excluded_ids[ $pid ] ) ) {
						$filtered[] = $pid;
					}
				}
				$matched_product_ids = $filtered;
			}
		}

		// If no products were matched by any discount rule, cache empty array and return on-sale or empty immediately.
		if ( empty( $matched_product_ids ) ) {
			if ( $can_persist && ! $all_products_is_dynamic && ! empty( $transient_key ) ) {
				$ttl       = DAY_IN_SECONDS * 30;
				$all_rules = $wccs_pricing->get_all_pricing_rules();
				$expiry    = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
				$ttl       = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
				$this->set_discounted_transient( $transient_key, array(), $ttl );
			}
			$result = array();
			if ( ! empty( $args['onsale_products'] ) ) {
				$onsale = $get_onsale();
				if ( ! empty( $onsale ) ) {
					$result = $onsale;
				}
			}
			if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) && ! empty( $result ) ) {
				$caller_excludes = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
				$result = array_values( array_filter( $result, function( $id ) use ( $caller_excludes ) {
					return ! isset( $caller_excludes[ $id ] );
				} ) );
			}
			$result = array_values( array_unique( array_map( 'WCCS_Helpers::maybe_get_exact_product', $result ) ) );
			$this->discounted_products_cache[ $req_cache_key ] = $result;
			return $result;
		}

		if ( ! empty( $args['include'] ) && is_array( $args['include'] ) && ! $has_all_products ) {
			$matched_product_ids = array_merge( $matched_product_ids, array_map( 'absint', $args['include'] ) );
		}

		$matched_product_ids = WCCS_Helpers::fast_dedup_ids( $matched_product_ids );

		if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) ) {
			$caller_excludes = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
			$filtered        = array();
			foreach ( $matched_product_ids as $mid ) {
				if ( ! isset( $caller_excludes[ $mid ] ) ) {
					$filtered[] = $mid;
				}
			}
			$matched_product_ids = $filtered;
		}

		if ( empty( $matched_product_ids ) ) {
			if ( $can_persist && ! $all_products_is_dynamic && ! empty( $transient_key ) ) {
				$ttl       = DAY_IN_SECONDS * 30;
				$all_rules = $wccs_pricing->get_all_pricing_rules();
				$expiry    = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
				$ttl       = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
				$this->set_discounted_transient( $transient_key, array(), $ttl );
			}
			$result = array();
			if ( ! empty( $args['onsale_products'] ) ) {
				$onsale = $get_onsale();
				if ( ! empty( $onsale ) ) {
					$result = $onsale;
				}
			}
			if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) && ! empty( $result ) ) {
				$caller_excludes = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
				$result = array_values( array_filter( $result, function( $id ) use ( $caller_excludes ) {
					return ! isset( $caller_excludes[ $id ] );
				} ) );
			}
			$result = array_values( array_unique( array_map( 'WCCS_Helpers::maybe_get_exact_product', $result ) ) );
			$this->discounted_products_cache[ $req_cache_key ] = $result;
			return $result;
		}

		$needs_wp_query = false;
		if ( 'ids' !== $args['return'] ) {
			$needs_wp_query = true;
		} elseif ( ! empty( $args['tax_query'] ) || ! empty( $args['meta_query'] ) || ! empty( $args['category'] ) || ! empty( $args['tag'] ) || ( isset( $args['limit'] ) && $args['limit'] > 0 ) || ! empty( $args['orderby'] ) ) {
			$needs_wp_query = true;
		}

		if ( $needs_wp_query ) {
			$query_args            = $args;
			$query_args['include'] = $matched_product_ids;
			$query_args['exclude'] = ! empty( $args['exclude'] ) ? array_values( array_filter( array_map( 'absint', (array) $args['exclude'] ) ) ) : array();
			$discounted_products   = $this->get_products( $query_args );
		} else {
			$discounted_products = $matched_product_ids;
		}

		if ( ! is_array( $discounted_products ) ) {
			$discounted_products = array();
		}

		// Persist canonical plugin-discounted IDs in transient when all store rules are static
		if ( $can_persist && ! $all_products_is_dynamic && ! empty( $transient_key ) ) {
			$ttl       = DAY_IN_SECONDS * 30;
			$all_rules = $wccs_pricing->get_all_pricing_rules();
			$expiry    = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
			$ttl       = ( $expiry > 0 && $expiry < $ttl ) ? $expiry : $ttl;
			$this->set_discounted_transient( $transient_key, $discounted_products, $ttl );
		}

		// Dynamically append WooCommerce native on-sale products if requested
		if ( ! empty( $args['onsale_products'] ) ) {
			$onsale = $get_onsale();
			if ( ! empty( $onsale ) ) {
				$discounted_products = WCCS_Helpers::fast_dedup_ids( array_merge( $discounted_products, $onsale ) );
			}
		}

		if ( ! empty( $args['exclude'] ) && is_array( $args['exclude'] ) ) {
			$caller_excludes     = array_fill_keys( array_map( 'absint', (array) $args['exclude'] ), true );
			$discounted_products = array_values( array_filter( $discounted_products, function( $id ) use ( $caller_excludes ) {
				return ! isset( $caller_excludes[ $id ] );
			} ) );
		}

		// Apply multilingual translation strictly on return
		$result = array_values( array_unique( array_map( 'WCCS_Helpers::maybe_get_exact_product', $discounted_products ) ) );
		$this->discounted_products_cache[ $req_cache_key ] = $result;

		return $result;
	}

	/**
	 * Set a discounted products transient and register its key for direct deletion on cache clear.
	 *
	 * Matches WooCommerce core's explicit transient model (delete_transient).
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 * @return bool
	 */
	public function set_discounted_transient( $key, $value, $ttl ) {
		$this->register_discounted_transient_key( $key );
		return set_transient( $key, $value, $ttl );
	}

	/**
	 * Register a transient key into the tracked keys registry.
	 * Memoized in-memory per request; only updates wp_options on new keys.
	 *
	 * @param string $key
	 * @return void
	 */
	protected function register_discounted_transient_key( $key ) {
		if ( null === $this->registered_transient_keys ) {
			$keys = get_option( 'asnp_wccs_dp_transient_keys', array() );
			$this->registered_transient_keys = is_array( $keys ) ? $keys : array();
		}

		if ( ! in_array( $key, $this->registered_transient_keys, true ) ) {
			$this->registered_transient_keys[] = $key;
			update_option( 'asnp_wccs_dp_transient_keys', $this->registered_transient_keys, false );
		}
	}

	/**
	 * Clear all discounted products caches.
	 *
	 * Directly deletes transients matching WooCommerce core's delete_transient('wc_products_onsale') pattern.
	 * Crucially, this DOES NOT invalidate individual product price caches or on-sale caches,
	 * protecting valid cached pricing calculations from being discarded when only product lists are refreshed.
	 *
	 * @return void
	 */
	public function clear_discounted_products_cache() {
		$this->discounted_products_cache = array();
		$this->all_published_product_ids = null;

		if ( null === $this->registered_transient_keys ) {
			$keys = get_option( 'asnp_wccs_dp_transient_keys', array() );
			$this->registered_transient_keys = is_array( $keys ) ? $keys : array();
		}

		if ( ! empty( $this->registered_transient_keys ) ) {
			foreach ( $this->registered_transient_keys as $key ) {
				delete_transient( $key );
			}
			delete_option( 'asnp_wccs_dp_transient_keys' );
		}

		$this->registered_transient_keys = array();
	}

	/**
     * Get product custom taxonomies.
	 *
	 * @since  5.2.0
	 *
     * @return array|null
     */
    public function get_custom_taxonomies() {
        if ( isset( $this->custom_taxonomies ) ) {
            return $this->custom_taxonomies;
        }

        $taxonomies = get_taxonomies( array(
			'show_ui'      => true,
			'show_in_menu' => true,
			'object_type'  => array( 'product' ),
		), 'objects' );

		$this->custom_taxonomies = array();
		foreach ( (array) $taxonomies as $key => $taxonomy ) {
			if ( ! in_array( $taxonomy->name, array( 'product_cat', 'product_tag' ) ) ) {
				$this->custom_taxonomies[ $key ] = $taxonomy;
			}
		}

		$this->custom_taxonomies = apply_filters( 'wccs_product_helpers_' . __FUNCTION__, $this->custom_taxonomies );

		return $this->custom_taxonomies;
	}

}
