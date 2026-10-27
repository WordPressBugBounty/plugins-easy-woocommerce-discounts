<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCCS_Background_Process', false ) ) {
	include_once WCCS_PLUGIN_DIR . 'includes/abstracts/class-wccs-background-process.php';
}

/**
 * WCCS_Background_Price_Updater Class.
 *
 * High-performance asynchronous worker that warms product prices, variation prices,
 * on-sale badge statuses, and percentage badges into the persistent 3-tier cache.
 *
 * @package    WC_Conditions
 * @subpackage WC_Conditions/includes
 */
class WCCS_Background_Price_Updater extends WCCS_Background_Process {

	/**
	 * Stores the current product ID being processed.
	 *
	 * @var integer
	 */
	protected $product_id = 0;

	/**
	 * Initiate new background process.
	 */
	public function __construct() {
		// Uses unique prefix per blog so each blog has separate queue.
		$this->prefix = 'wp_' . get_current_blog_id();
		$this->action = 'wccs_price_updater';

		parent::__construct();
	}

	/**
	 * Dispatch updater.
	 *
	 * Updater will still run via cron job if this fails for any reason.
	 */
	public function dispatch() {
		$dispatched = parent::dispatch();
		$logger     = WCCS_Helpers::wc_get_logger();

		if ( is_wp_error( $dispatched ) ) {
			if ( WCCS_Helpers::wc_version_check() ) {
				$logger->error(
					sprintf( __( 'Unable to dispatch WooCommerce Conditions price updater: %s', 'easy-woocommerce-discounts' ), $dispatched->get_error_message() ),
					array( 'source' => 'wccs_price_updater' )
				);
			} else {
				$logger->add( 'wccs_price_updater', sprintf( __( 'Unable to dispatch WooCommerce Conditions price updater: %s', 'easy-woocommerce-discounts' ), $dispatched->get_error_message() ) );
			}
		}
	}

	/**
	 * Handle cron healthcheck.
	 *
	 * Restart the background process if not already running
	 * and data exists in the queue.
	 */
	public function handle_cron_healthcheck() {
		if ( $this->is_process_running() ) {
			return;
		}

		if ( $this->is_queue_empty() ) {
			$this->clear_scheduled_event();
			return;
		}

		$this->handle();
	}

	/**
	 * Schedule fallback event.
	 */
	protected function schedule_event() {
		if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
			wp_schedule_event( time() + 10, $this->cron_interval_identifier, $this->cron_hook_identifier );
		}
	}

	/**
	 * Is the updater running?
	 *
	 * @return boolean
	 */
	public function is_updating() {
		return false === $this->is_queue_empty();
	}

	/**
	 * Task
	 *
	 * Process a queue batch with maximum memory efficiency and minimal database overhead.
	 * Warms persistent price, variation price, bulk HTML table, on-sale badge,
	 * and percentage discount badge caches strictly for guest users.
	 *
	 * Supports both chunked arrays of product IDs (for high throughput and reduced option rows)
	 * and single product ID items (for backward compatibility and single product updates).
	 *
	 * @param mixed $item
	 * @return mixed False on completion/removal, or modified item to re-queue.
	 */
	protected function task( $item ) {
		if ( ! is_array( $item ) ) {
			return false;
		}

		// 1. Generation validation: If cache generation changed since queueing, abort stale batch immediately.
		$current_gen = WCCS_Clear_Cache::get_generation();
		if ( isset( $item['generation'] ) && (int) $item['generation'] !== (int) $current_gen ) {
			return false;
		}

		// 2. Extract and sanitize product IDs.
		$product_ids = array();
		if ( ! empty( $item['product_ids'] ) && is_array( $item['product_ids'] ) ) {
			$product_ids = array_filter( array_map( 'absint', $item['product_ids'] ) );
		} elseif ( ! empty( $item['product_id'] ) ) {
			$single_id = absint( $item['product_id'] );
			if ( $single_id > 0 ) {
				$product_ids = array( $single_id );
			}
		}

		if ( empty( $product_ids ) ) {
			return false;
		}

		// Save original user and customer context to isolate worker under clean Guest context.
		$original_user_id  = get_current_user_id();
		$original_customer = isset( WC()->customer ) ? WC()->customer : null;
		wp_set_current_user( 0 );
		if ( ! WC()->customer ) {
			WC()->customer = new WC_Customer( 0, true );
		}

		try {
			// 3. Hoist dependencies and configuration under clean Guest context (O(1) lookups).
			$pricing      = new WCCS_Pricing( WCCS_Conditions_Provider::get_pricings( array( 'status' => 1, 'cache' => true ) ) );
			$price_cache  = new WCCS_Product_Price_Cache( $pricing );
			$onsale_cache = new WCCS_Product_Onsale_Cache( $pricing );
			$badge_cache  = new WCCS_Product_Badge_Cache( $pricing );

			$price_enabled  = $price_cache->is_cache_enabled();
			$onsale_enabled = $onsale_cache->is_cache_enabled();
			$badge_enabled  = $badge_cache->is_cache_enabled();

			// Early exit if caching is disabled store-wide.
			if ( ! $price_enabled && ! $onsale_enabled && ! $badge_enabled ) {
				return false;
			}

			$has_bulk_rules = 'all' === WCCS()->settings->get_setting( 'change_display_price', 'simple' ) && $pricing->has_bulk_rules();
			$is_ext_cache   = wp_using_ext_object_cache();
			$mode           = WCCS()->settings->get_setting( 'change_price_method', 'lightweight' );
			$price_decimals = wc_get_price_decimals();
			$tax_enabled    = wc_tax_enabled();
			$is_incl_tax    = ( $tax_enabled && 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' ) );

			// 4. Preload post caches, meta caches, and Layer 2 DB cache in bulk (eliminates N+1 queries).
			WCCS_Helpers::prime_product_caches( $product_ids );

			// 5. Warm each product with minimal memory footprint and zero hook-cascade overhead.
			foreach ( $product_ids as $product_id ) {
				$this->product_id = $product_id;

				try {
					$product = wc_get_product( $product_id );
					if ( ! $product || 'publish' !== $product->get_status() ) {
						continue;
					}

					$is_variable = $product->is_type( 'variable' );

					// 1. Bulk pricing HTML: generate and cache bulk range HTML if store has bulk rules.
					$product_pricing = null;
					if ( $has_bulk_rules ) {
						$product_pricing = new WCCS_Public_Product_Pricing( $product, $pricing );
						$product_pricing->get_bulk_price_html();
					}

					// 2. Simple and variation prices: evaluate rules and warm into persistent DB cache.
					if ( $price_enabled ) {
						if ( $is_variable ) {
							$variation_ids = $product->get_visible_children();
							if ( ! empty( $variation_ids ) ) {
								WCCS_Helpers::prime_product_caches( $variation_ids );

								$prices_array = array(
									'price'         => array(),
									'regular_price' => array(),
									'sale_price'    => array(),
								);

								foreach ( $variation_ids as $var_id ) {
									$variation = wc_get_product( $var_id );
									if ( $variation ) {
										$discounted = $price_cache->get_price( $variation, null, 'price' );

										// Warm variation on-sale status matching frontend simple rule badge check.
										if ( $onsale_enabled ) {
											$onsale_cache->is_onsale( $variation );
										}

										// Warm variation percentage badge value when percentage badge caching is active.
										if ( $badge_enabled ) {
											$badge_cache->get_badge_value( $variation );
										}

										$price   = ( ! is_numeric( $discounted ) || 0 > $discounted ) ? $variation->get_price( 'edit' ) : $discounted;
										$regular = $variation->get_regular_price( 'edit' );
										$sale    = ( ! is_numeric( $discounted ) || 0 > $discounted ) ? $variation->get_price( 'edit' ) : $discounted;

										if ( '' !== $price ) {
											if ( $sale === $regular || $sale !== $price ) {
												$sale = $regular;
											}
											if ( $is_incl_tax ) {
												$price   = '' === $price ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
												$regular = '' === $regular ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $regular ) );
												$sale    = '' === $sale ? '' : wc_get_price_including_tax( $variation, array( 'qty' => 1, 'price' => $sale ) );
											} else {
												$price   = '' === $price ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $price ) );
												$regular = '' === $regular ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $regular ) );
												$sale    = '' === $sale ? '' : wc_get_price_excluding_tax( $variation, array( 'qty' => 1, 'price' => $sale ) );
											}
											$prices_array['price'][ $var_id ]         = wc_format_decimal( $price, $price_decimals );
											$prices_array['regular_price'][ $var_id ] = wc_format_decimal( $regular, $price_decimals );
											$prices_array['sale_price'][ $var_id ]    = wc_format_decimal( $sale, $price_decimals );
										}

										unset( $variation );
									}

									// Safely purge internal WP object cache without firing expensive action hooks.
									if ( ! $is_ext_cache ) {
										wp_cache_delete( $var_id, 'posts' );
										wp_cache_delete( $var_id, 'post_meta' );
									}
								}

								// Warm variable product variation prices array for instant catalog display.
								if ( ! empty( $prices_array['price'] ) ) {
									asort( $prices_array['price'] );
								}
								if ( ! empty( $prices_array['regular_price'] ) ) {
									asort( $prices_array['regular_price'] );
								}
								if ( ! empty( $prices_array['sale_price'] ) ) {
									asort( $prices_array['sale_price'] );
								}

								$can_persist = $price_cache->can_cache_variation_prices( $product );
								$ttl         = null;
								if ( $can_persist ) {
									$all_rules   = $price_cache->get_variable_product_all_simple_rules( $product );
									$default_ttl = $price_cache->get_default_ttl();
									$time_expiry = WCCS_Rules_Helpers::get_nearest_time_expiry( $all_rules );
									$ttl         = ( $time_expiry > 0 ) ? ( ( $default_ttl > 0 ) ? min( $default_ttl, $time_expiry ) : $time_expiry ) : $default_ttl;
								}

								$var_key = $price_cache->build_variation_cache_key( $product_id, true, $mode );
								$price_cache->set_variation_prices( $product_id, $var_key, $prices_array, $ttl, $can_persist );
							}
						} else {
							// Pass existing $product_pricing instance to avoid redundant object instantiation.
							$price_cache->get_price( $product, null, 'price', null, $product_pricing );
						}
					}

					// 3. On-sale badge status: warm unified tri-state badge status.
					if ( $onsale_enabled ) {
						$onsale_cache->get_onsale_status( $product );
					}

					// 4. Percentage badge: warm percentage discount badge when badge type is percentage in settings.
					if ( $badge_enabled ) {
						$badge_cache->get_badge_value( $product );
					}

					// Free memory immediately to prevent object accumulation across large catalogs.
					unset( $product, $product_pricing );

					if ( ! $is_ext_cache ) {
						wp_cache_delete( $product_id, 'posts' );
						wp_cache_delete( $product_id, 'post_meta' );
					}
				} catch ( Exception $e ) {
					// Prevent single product failure from halting the queue.
					continue;
				}
			}

			// Clean SQL query log if SAVEQUERIES is active to prevent memory bloat in debug/staging environments.
			if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && isset( $GLOBALS['wpdb']->queries ) ) {
				$GLOBALS['wpdb']->queries = array();
			}
		} catch ( Exception $e ) {
			return false;
		} finally {
			WC()->customer = $original_customer;
			wp_set_current_user( $original_user_id );
		}

		return false;
	}

	/**
	 * Complete callback when queue finishes.
	 */
	protected function complete() {
		$logger = WCCS_Helpers::wc_get_logger();
		if ( WCCS_Helpers::wc_version_check() ) {
			$logger->info( __( 'Completed product price update job.', 'easy-woocommerce-discounts' ), array( 'source' => 'wccs_price_updater' ) );
		} else {
			$logger->add( 'wccs_price_updater', __( 'Completed product price update job.', 'easy-woocommerce-discounts' ) );
		}

		WCCS()->WCCS_Admin_Notices->remove_notice( 'update_price' );

		delete_transient( 'asnp_wccs_updating_prices_running' );

		$this->clear_scheduled_event();

		parent::complete();
	}

}
