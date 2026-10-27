<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Updates {

	public static function clear_pricing_caches() {
		WCCS_Clear_Cache::clear_pricing_caches();
	}

	public static function update_110_conditions() {
		global $wpdb;

		$condition_meta = WCCS()->condition_meta;

		// Adding apply_mode for pricing and cart_discount conditions.
		$results = $wpdb->get_col( "SELECT `id` FROM {$wpdb->prefix}wccs_conditions WHERE `type` IN ('pricing','cart-discount') AND `id` NOT IN ( SELECT DISTINCT `wccs_condition_id` FROM {$wpdb->prefix}wccs_condition_meta WHERE `meta_key` = 'apply_mode' )" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $condition_id ) {
				$condition_meta->update_meta( $condition_id, 'apply_mode', 'all' );
			}
		}

		// Adding exclude_items for pricing conditions.
		$results = $wpdb->get_col( "SELECT `id` FROM {$wpdb->prefix}wccs_conditions WHERE `type` = 'pricing' AND `id` NOT IN ( SELECT DISTINCT `wccs_condition_id` FROM {$wpdb->prefix}wccs_condition_meta WHERE `meta_key` = 'exclude_items' )" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $condition_id ) {
				$condition_meta->update_meta( $condition_id, 'exclude_items', array() );
			}
		}

		// Adding private_note for cart_discount conditions.
		$results = $wpdb->get_col( "SELECT `id` FROM {$wpdb->prefix}wccs_conditions WHERE `type` = 'cart-discount' AND `id` NOT IN ( SELECT DISTINCT `wccs_condition_id` FROM {$wpdb->prefix}wccs_condition_meta WHERE `meta_key` = 'private_note' )" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $condition_id ) {
				$condition_meta->update_meta( $condition_id, 'private_note', '' );
			}
		}

		// Adding date_times_match_mode to conditions.
		$results = $wpdb->get_col( "SELECT `id` FROM {$wpdb->prefix}wccs_conditions WHERE `type` IN ('pricing','cart-discount','products-list') AND `id` NOT IN ( SELECT DISTINCT `wccs_condition_id` FROM {$wpdb->prefix}wccs_condition_meta WHERE `meta_key` = 'date_times_match_mode' )" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $condition_id ) {
				$condition_meta->update_meta( $condition_id, 'date_times_match_mode', 'one' );
			}
		}

		// Adding conditions_match_mode to conditions.
		$results = $wpdb->get_col( "SELECT `id` FROM {$wpdb->prefix}wccs_conditions WHERE `type` IN ('pricing','cart-discount','products-list') AND `id` NOT IN ( SELECT DISTINCT `wccs_condition_id` FROM {$wpdb->prefix}wccs_condition_meta WHERE `meta_key` = 'conditions_match_mode' )" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $condition_id ) {
				$condition_meta->update_meta( $condition_id, 'conditions_match_mode', 'all' );
			}
		}

		// Updating pricing products to adding quantity.
		$results = $wpdb->get_results( "SELECT `conditions`.id, `conditions_meta`.meta_value FROM `{$wpdb->prefix}wccs_conditions` AS `conditions` JOIN `{$wpdb->prefix}wccs_condition_meta` AS `conditions_meta` ON `conditions`.id = `conditions_meta`.wccs_condition_id WHERE `conditions`.`type` = 'pricing' AND `conditions_meta`.meta_key = 'items' AND `conditions_meta`.meta_value != ''" );
		if ( ! empty( $results ) ) {
			foreach ( $results as $result ) {
				if ( ! empty( $result->meta_value ) ) {
					$update = false;
					$items = maybe_unserialize( $result->meta_value );
					foreach ( $items as &$item ) {
						if ( ! isset( $item['quantity'] ) ) {
							$update = true;
							$item['quantity'] = '';
						}
					}
					unset( $item );

					if ( $update ) {
						$condition_meta->update_meta( $result->id, 'items', $items );
					}
				}
			}
		}
	}

	public static function update_110_db_version() {
		WCCS_Activator::update_db_version( '1.1.0' );
	}

	public static function update_301() {
		WCCS_Clear_Cache::clear_pricing_caches();
	}

	public static function update_460() {
		WCCS_Clear_Cache::clear_pricing_caches();

		if ( wp_using_ext_object_cache() ) {
            return;
		}

		global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_wccs-product-' ) . '%',
                $wpdb->esc_like( '_transient_timeout_wccs-product-' ) . '%'
            )
        );
	}

	public static function update_600() {
		global $wpdb;

		$results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}wccs_condition_meta WHERE meta_key IN ( 'conditions', 'date_time', 'items', 'exclude_items', 'purchased_items' )" );
		if ( empty( $results ) ) {
			return;
		}

		foreach ( $results as $row ) {
			switch ( $row->meta_key ) {
				case 'conditions':
					self::add_or_condition( $row );
					break;

				case 'date_time':
					self::add_or_date_time( $row );
					break;

				case 'items':
					self::add_or_item( $row );
					break;

				default:
					self::add_or_rule( $row, $row->meta_key );
					break;
			}
		}

		self::clear_pricing_caches();
	}

	public static function update_700() {
		WCCS()->WCCS_Product_Price_Cache->clear_cache_deprecated();
        WCCS()->WCCS_Product_Quantity_Table_Cache->clear_cache_deprecated();
        WCCS()->WCCS_Product_Onsale_Cache->clear_cache_deprecated();
	}

	public static function clear_table_cache() {
		WCCS()->WCCS_Product_Quantity_Table_Cache->clear_cache();
	}

	public static function update_9120_cache_table() {
		global $wpdb;

		// 1. wp_wccs_cache: column and indexes
		$cache_table = $wpdb->prefix . 'wccs_cache';
		$col = $wpdb->get_results( "SHOW COLUMNS FROM `{$cache_table}` LIKE 'expires_at'" );
		if ( empty( $col ) ) {
			$wpdb->query( "ALTER TABLE `{$cache_table}` ADD COLUMN `expires_at` bigint(20) NOT NULL DEFAULT 0" );
		}

		$existing_indices = $wpdb->get_results( "SHOW INDEX FROM `{$cache_table}` WHERE Key_name = 'product_cache'" );
		if ( empty( $existing_indices ) ) {
			$wpdb->query( "ALTER TABLE `{$cache_table}` ADD UNIQUE KEY `product_cache` (`product_id`, `cache_type`)" );
		}

		$existing_type_indices = $wpdb->get_results( "SHOW INDEX FROM `{$cache_table}` WHERE Key_name = 'cache_type'" );
		if ( empty( $existing_type_indices ) ) {
			$wpdb->query( "ALTER TABLE `{$cache_table}` ADD INDEX `cache_type` (`cache_type`)" );
		}

		$existing_exp_indices = $wpdb->get_results( "SHOW INDEX FROM `{$cache_table}` WHERE Key_name = 'expires_at'" );
		if ( empty( $existing_exp_indices ) ) {
			$wpdb->query( "ALTER TABLE `{$cache_table}` ADD INDEX `expires_at` (`expires_at`)" );
		}

		// 2. wp_wccs_conditions: composite index for fast rule querying
		$conditions_table = $wpdb->prefix . 'wccs_conditions';
		$existing_cond_idx = $wpdb->get_results( "SHOW INDEX FROM `{$conditions_table}` WHERE Key_name = 'type_status_ordering'" );
		if ( empty( $existing_cond_idx ) ) {
			$wpdb->query( "ALTER TABLE `{$conditions_table}` ADD INDEX `type_status_ordering` (`type`(50), `status`, `ordering`)" );
		}
		$existing_status_idx = $wpdb->get_results( "SHOW INDEX FROM `{$conditions_table}` WHERE Key_name = 'status'" );
		if ( empty( $existing_status_idx ) ) {
			$wpdb->query( "ALTER TABLE `{$conditions_table}` ADD INDEX `status` (`status`)" );
		}

		// 3. wp_wccs_condition_meta: composite lookup index
		$meta_table = $wpdb->prefix . 'wccs_condition_meta';
		$existing_meta_idx = $wpdb->get_results( "SHOW INDEX FROM `{$meta_table}` WHERE Key_name = 'condition_meta'" );
		if ( empty( $existing_meta_idx ) ) {
			$wpdb->query( "ALTER TABLE `{$meta_table}` ADD INDEX `condition_meta` (`wccs_condition_id`, `meta_key`(100))" );
		}

		// 4. wp_wccs_user_usage_logs: composite lookup index
		$user_logs_table = $wpdb->prefix . 'wccs_user_usage_logs';
		$existing_user_idx = $wpdb->get_results( "SHOW INDEX FROM `{$user_logs_table}` WHERE Key_name = 'rule_user'" );
		if ( empty( $existing_user_idx ) ) {
			$wpdb->query( "ALTER TABLE `{$user_logs_table}` ADD INDEX `rule_user` (`rule_id`, `used_by`(100))" );
		}

		// 5. wp_wccs_rule_usage_logs: composite lookup index
		$rule_logs_table = $wpdb->prefix . 'wccs_rule_usage_logs';
		$existing_order_idx = $wpdb->get_results( "SHOW INDEX FROM `{$rule_logs_table}` WHERE Key_name = 'rule_order'" );
		if ( empty( $existing_order_idx ) ) {
			$wpdb->query( "ALTER TABLE `{$rule_logs_table}` ADD INDEX `rule_order` (`rule_id`, `order_id`)" );
		}

		WCCS_Clear_Cache::clear_pricing_caches();
	}

	public static function update_required() {
		$current_db_version = get_option( 'woocommerce_conditions_db_version' );
		$upgraded_from      = get_option( 'wccs_version_upgraded_from' );
		return false !== $upgraded_from && ( false === $current_db_version || version_compare( $current_db_version, '6.0.0', '<' ) );
	}

	protected static function add_or_condition( $row ) {
		if ( empty( $row ) || empty( $row->wccs_condition_id ) ) {
			return;
		}

		$rules = maybe_unserialize( $row->meta_value );
		if ( empty( $rules ) || isset( $rules[0][0] ) ) {
			return;
		}

		global $wpdb;
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wccs_condition_meta WHERE wccs_condition_id = %d AND meta_key = 'conditions_match_mode'", (int) $row->wccs_condition_id ) );
		$type = ! empty( $type ) ? strtolower( $type ) : 'all';

		$update = array();
		if ( 'all' === $type ) {
			$update[] = $rules;
		} elseif ( 'one' === $type ) {
			foreach ( $rules as $rule ) {
				$update[] = array( $rule );
			}
		}

		WCCS()->condition_meta->update_meta( (int) $row->wccs_condition_id, 'conditions', $update );
	}

	protected static function add_or_date_time( $row ) {
		if ( empty( $row ) || empty( $row->wccs_condition_id ) ) {
			return;
		}

		$rules = maybe_unserialize( $row->meta_value );
		if ( empty( $rules ) || isset( $rules[0][0] ) ) {
			return;
		}

		global $wpdb;
		$type = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wccs_condition_meta WHERE wccs_condition_id = %d AND meta_key = 'date_times_match_mode'", (int) $row->wccs_condition_id ) );
		$type = ! empty( $type ) ? strtolower( $type ) : 'one';

		$update = array();
		if ( 'all' === $type ) {
			$update[] = $rules;
		} elseif ( 'one' === $type ) {
			foreach ( $rules as $rule ) {
				$update[] = array( $rule );
			}
		}

		WCCS()->condition_meta->update_meta( (int) $row->wccs_condition_id, 'date_time', $update );
	}

	protected static function add_or_item( $row ) {
		if ( empty( $row ) || empty( $row->wccs_condition_id ) ) {
			return;
		}

		$rules = maybe_unserialize( $row->meta_value );
		if ( empty( $rules ) || isset( $rules[0][0] ) ) {
			return;
		}

		global $wpdb;

		$type = $wpdb->get_var( $wpdb->prepare( "SELECT `type` FROM {$wpdb->prefix}wccs_conditions WHERE id = %d", (int) $row->wccs_condition_id ) );
		if ( empty( $type ) || 'auto-add-products' === $type ) {
			return;
		}

		if ( 'pricing' === $type ) {
			$mode = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}wccs_condition_meta WHERE wccs_condition_id = %d AND meta_key = 'mode'", (int) $row->wccs_condition_id ) );
			// Do not update pricing products-group mode items.
			if ( ! empty( $mode ) && 'products_group' === $mode ) {
				return;
			}
		}

		WCCS()->condition_meta->update_meta( (int) $row->wccs_condition_id, 'items', array( $rules ) );
	}

	protected static function add_or_rule( $row, $type ) {
		if ( empty( $row ) || empty( $type )|| empty( $row->wccs_condition_id ) ) {
			return;
		}

		$rules = maybe_unserialize( $row->meta_value );
		if ( empty( $rules ) || isset( $rules[0][0] ) ) {
			return;
		}

		WCCS()->condition_meta->update_meta( (int) $row->wccs_condition_id, sanitize_text_field( $type ), array( $rules ) );
	}

}
