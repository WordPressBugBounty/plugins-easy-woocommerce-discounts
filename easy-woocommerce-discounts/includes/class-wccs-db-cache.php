<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_DB_Cache extends WCCS_DB {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name  = $wpdb->prefix . 'wccs_cache';
		$this->primary_key = 'id';
		$this->version     = '1.0';
	}

	/**
	 * Get columns and formats
	 *
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	public function get_columns() {
		return array(
			'id'         => '%d',
			'product_id' => '%d',
			'cache_type' => '%s',
			'value'      => '%s',
			'expires_at' => '%d',
		);
	}

	/**
	 * Get default column values.
	 *
	 * @since  1.0.0
	 *
	 * @return array
	 */
	public function get_column_defaults() {
		return array(
			'expires_at' => 0,
		);
	}

	/**
	 * Add a condition.
	 * Update condition if exists otherwise insert new one.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $data
	 *
	 * @return false|int
	 */
	public function add( array $args = array() ) {
		if ( isset( $args['id'] ) ) {
			$item = $this->get_item( $args['id'] );
			unset( $args['id'] );
			if ( $item ) {
				$this->update( $item->id, $args );
				return $item->id;
			}
		} elseif ( ! empty( $args['product_id'] ) && ! empty( $args['cache_type'] ) ) {
			$val = isset( $args['value'] ) ? $args['value'] : '';
			$exp = isset( $args['expires_at'] ) ? (int) $args['expires_at'] : 0;
			return $this->set_cache( $args['product_id'], $args['cache_type'], $val, $exp );
		}

		$args = wp_parse_args( $args, $this->get_column_defaults() );
		$id   = $this->insert( $args, 'cache' );

		return $id ? $id : false;
	}

	/**
	 * Atomically insert or update a cache entry for a given product and cache type.
	 *
	 * Guaranteed race-condition free under any concurrency using MySQL ON DUPLICATE KEY UPDATE.
	 * Returns the primary key ID of the inserted or updated row.
	 *
	 * @since  9.12.0
	 *
	 * @param  int          $product_id Product ID.
	 * @param  string       $cache_type Cache type (e.g. 'price', 'onsale').
	 * @param  string|array $value      Cached payload (array or string, serialized automatically).
	 * @param  int          $expires_at UNIX timestamp expiration (0 for non-expiring).
	 *
	 * @return int|false Primary key ID on success, false on failure.
	 */
	public function set_cache( $product_id, $cache_type, $value, $expires_at = 0 ) {
		$product_id = absint( $product_id );
		$cache_type = sanitize_text_field( $cache_type );
		if ( 0 >= $product_id || '' === trim( $cache_type ) ) {
			return false;
		}

		global $wpdb;

		$serialized = maybe_serialize( $value );
		$expires_at = (int) $expires_at;

		$sql = $wpdb->prepare(
			"INSERT INTO {$this->table_name} (`product_id`, `cache_type`, `value`, `expires_at`)
			VALUES (%d, %s, %s, %d)
			ON DUPLICATE KEY UPDATE
				`value` = VALUES(`value`),
				`expires_at` = VALUES(`expires_at`)",
			$product_id,
			$cache_type,
			$serialized,
			$expires_at
		);

		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			return false;
		}

		return $result;
	}

	/**
	 * Atomically insert or update multiple cache entries in a single SQL query.
	 *
	 * Guaranteed race-condition free under any concurrency using MySQL ON DUPLICATE KEY UPDATE.
	 * Reduces N database round-trips to 1 query.
	 *
	 * @since  9.12.0
	 *
	 * @param  array $rows Array of associative arrays, each with keys: product_id, cache_type, value, expires_at.
	 * @return int|false Number of affected rows or false on failure.
	 */
	public function set_caches( array $rows ) {
		if ( empty( $rows ) ) {
			return 0;
		}

		// Bound batch size to 500 rows per query for MySQL packet and lock safety
		if ( count( $rows ) > 500 ) {
			$total_result = 0;
			$chunks       = array_chunk( $rows, 500 );
			foreach ( $chunks as $chunk ) {
				$res = $this->set_caches( $chunk );
				if ( false !== $res ) {
					$total_result += (int) $res;
				}
			}
			return $total_result;
		}

		global $wpdb;

		$values_sql = array();
		$params     = array();

		foreach ( $rows as $row ) {
			$product_id = absint( $row['product_id'] );
			$cache_type = sanitize_text_field( $row['cache_type'] );
			if ( $product_id <= 0 || '' === trim( $cache_type ) ) {
				continue;
			}

			$serialized = maybe_serialize( $row['value'] );
			$expires_at = isset( $row['expires_at'] ) ? (int) $row['expires_at'] : 0;

			$values_sql[] = '(%d, %s, %s, %d)';
			$params[]     = $product_id;
			$params[]     = $cache_type;
			$params[]     = $serialized;
			$params[]     = $expires_at;
		}

		if ( empty( $values_sql ) ) {
			return 0;
		}

		$sql = "INSERT INTO {$this->table_name} (`product_id`, `cache_type`, `value`, `expires_at`)
			VALUES " . implode( ', ', $values_sql ) . "
			ON DUPLICATE KEY UPDATE
				`value` = VALUES(`value`),
				`expires_at` = VALUES(`expires_at`)";

		$result = $wpdb->query( $wpdb->prepare( $sql, $params ) );

		return $result;
	}

	/**
	 * Retrieves a single condition from the database;
	 *
	 * @since  1.0.0
	 *
	 * @param  int     $id
	 * @param  string  $output
	 * @param  boolean $include_meta
	 *
	 * @return Object|Array|false  False on failure
	 */
	public function get_item( $id, $output = OBJECT ) {
		$id = absint( $id );
		if ( 0 >= $id ) {
			return false;
		}

		global $wpdb;

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->table_name WHERE id = %d LIMIT 1", $id ), $output );

		if ( $item ) {
			if ( is_object( $item ) ) {
				$item->value = maybe_unserialize( $item->value );
			} elseif ( isset( $item['value'] ) ) {
				$item['value'] = maybe_unserialize( $item['value'] );
			}
		}

		return $item ? $item : false;
	}
	
	public function get_item_by_product( $product_id, $type, $output = OBJECT ) {
		$product_id = absint( $product_id );
		if ( 0 >= $product_id ) {
			return false;
		}

		if ( '' === trim( $type ) ) {
			return false;
		}

		global $wpdb;

		$item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->table_name WHERE product_id = %d AND cache_type = %s LIMIT 1", $product_id, sanitize_text_field( $type ) ), $output );

		if ( $item ) {
			if ( is_object( $item ) ) {
				$item->value = maybe_unserialize( $item->value );
			} elseif ( isset( $item['value'] ) ) {
				$item['value'] = maybe_unserialize( $item['value'] );
			}
		}

		return $item ? $item : false;
	}

	/**
	 * Retrieve multiple cached items for a list of product IDs in a single query.
	 *
	 * Uses composite index (product_id, cache_type) for O(1) batch lookup.
	 *
	 * @since  9.11.0
	 *
	 * @param  array  $product_ids
	 * @param  string $type
	 * @param  string $output
	 *
	 * @return array
	 */
	public function get_items_by_products( array $product_ids, $type, $output = OBJECT ) {
		$product_ids = array_filter( array_map( 'absint', $product_ids ) );
		if ( empty( $product_ids ) || '' === trim( $type ) ) {
			return array();
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$params       = array_merge( array_values( $product_ids ), array( sanitize_text_field( $type ) ) );

		$sql     = $wpdb->prepare( "SELECT * FROM {$this->table_name} WHERE product_id IN ($placeholders) AND cache_type = %s", $params );
		$results = $wpdb->get_results( $sql, $output );

		if ( ! empty( $results ) ) {
			foreach ( $results as &$item ) {
				if ( is_object( $item ) ) {
					$item->value = maybe_unserialize( $item->value );
				} elseif ( isset( $item['value'] ) ) {
					$item['value'] = maybe_unserialize( $item['value'] );
				}
			}
			unset( $item );
		}

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * Deleting a condition by it's id.
	 *
	 * @since  1.0.0
	 *
	 * @param  int $id
	 *
	 * @return boolean
	 */
	public function delete( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$item = $this->get_item( $id );

		if ( $item && ! empty( $item->id ) ) {
			global $wpdb;
			return $wpdb->delete( $this->table_name, array( 'id' => $item->id ), array( '%d' ) );
		}

		return false;
	}

	public function delete_item_by_product( $product_id, $type = '' ) {
		$product_id = absint( $product_id );
		if ( 0 >= $product_id ) {
			return false;
		}

		global $wpdb;

		if ( '' === trim( $type ) ) {
			return $wpdb->delete( $this->table_name, array( 'product_id' => $product_id ), array( '%d' ) );
		}

		return $wpdb->delete( $this->table_name, array( 'product_id' => $product_id, 'cache_type' => sanitize_text_field( $type ) ), array( '%d', '%s' ) );
	}

	/**
	 * Delete multiple cached items for a list of product IDs in a single query.
	 *
	 * @since  9.12.0
	 *
	 * @param  array  $product_ids Array of product IDs.
	 * @param  string $type        Cache type (e.g. 'price', 'onsale', 'badge'). Optional.
	 * @return int|false Number of rows deleted, or false on failure.
	 */
	public function delete_items_by_products( array $product_ids, $type = '' ) {
		$product_ids = array_filter( array_map( 'absint', $product_ids ) );
		if ( empty( $product_ids ) ) {
			return false;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		if ( '' === trim( $type ) ) {
			return $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE product_id IN ($placeholders)",
				$product_ids
			) );
		}

		$params = array_merge( array_values( $product_ids ), array( sanitize_text_field( $type ) ) );

		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM {$this->table_name} WHERE product_id IN ($placeholders) AND cache_type = %s",
			$params
		) );
	}

	public function delete_items_by_type( $type ) {
		if ( '' === trim( $type ) ) {
			return false;
		}

		global $wpdb;
		return $wpdb->delete( $this->table_name, array( 'cache_type' => sanitize_text_field( $type ) ), array( '%s' ) );
	}

	public function delete_product_price( $product_id ) {
		return $this->delete_item_by_product( $product_id, 'price' );
	}

	/**
	 * Clear all cache entries from the table safely.
	 *
	 * Falls back gracefully to DELETE if TRUNCATE is restricted by database user permissions.
	 *
	 * @return bool|int
	 */
	public function clear_cache() {
		global $wpdb;

		// Suppress database errors in case TRUNCATE permission is restricted on managed hosting
		$suppress = $wpdb->suppress_errors();
		$result   = $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
		$wpdb->suppress_errors( $suppress );

		if ( false === $result ) {
			return $wpdb->query( "DELETE FROM {$this->table_name}" );
		}

		return $result;
	}

	/**
	 * Purge expired cache rows in non-blocking batches.
	 *
	 * Uses composite/range index KEY expires_at (expires_at) for O(log N) sweep.
	 *
	 * @since  9.12.0
	 *
	 * @param  int $batch_size Maximum rows to delete per loop.
	 * @return int Total rows deleted.
	 */
	public function purge_expired( $batch_size = 1000 ) {
		global $wpdb;

		$now        = time();
		$batch_size = max( 10, absint( $batch_size ) );
		$total      = 0;

		do {
			$deleted = $wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->table_name} WHERE expires_at > 0 AND expires_at <= %d LIMIT %d",
				$now,
				$batch_size
			) );

			if ( false === $deleted || $deleted <= 0 ) {
				break;
			}

			$total += (int) $deleted;
		} while ( $deleted >= $batch_size && $total < 50000 );

		return $total;
	}

	/**
	 * Counting number of conditions.
	 *
	 * @since  1.0.0
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;

		$count = $wpdb->get_var( "SELECT COUNT( $this->primary_key ) FROM $this->table_name" );

		return absint( $count );
	}

	/**
	 * Create the table
	 *
	 * @since 1.0.0
	 */
	public function create_table() {
		global $wpdb;

		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$sql = "CREATE TABLE " . $this->table_name . " (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		product_id bigint(20) NOT NULL,
		cache_type varchar(20) NOT NULL DEFAULT '',
		value longtext NOT NULL DEFAULT '',
		expires_at bigint(20) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		UNIQUE KEY product_cache (product_id, cache_type),
		KEY cache_type (cache_type),
		KEY expires_at (expires_at)
		) $collate;";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}

}
