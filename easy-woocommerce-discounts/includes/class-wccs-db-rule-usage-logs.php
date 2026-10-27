<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_DB_Rule_Usage_Logs extends WCCS_DB {

	/**
	 * In-memory cache for usage counts.
	 *
	 * @var array
	 */
	protected $usage_count_cache = array();

    /**
	 * Constructor.
	 *
	 * @since 8.19.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name  = $wpdb->prefix . 'wccs_rule_usage_logs';
		$this->primary_key = 'id';
		$this->version     = '1.0';
	}

	/**
	 * Get columns and formats
	 *
	 * @since   8.19.0
	 *
	 * @return  array
	 */
	public function get_columns() {
		return array(
			'id'          => '%d',
			'rule_id'     => '%d',
			'order_id'    => '%d',
			'usage_count' => '%d',
			'created_at'  => '%s',
		);
	}

	/**
	 * Get default column values.
	 *
	 * @since  8.19.0
	 *
	 * @return array
	 */
	public function get_column_defaults() {
		return array(
			'usage_count' => 1,
			'created_at'  => current_time( 'mysql' ),
		);
	}

	/**
	 * Clear usage count cache for a rule or all rules.
	 *
	 * @param int $rule_id
	 * @return void
	 */
	public function clear_rule_usage_cache( $rule_id = 0 ) {
		$rule_id = absint( $rule_id );
		if ( $rule_id > 0 ) {
			unset( $this->usage_count_cache[ $rule_id ] );
			$cache_key = 'asnp_wccs_rule_usage_' . $rule_id;
			wp_cache_delete( $cache_key, 'asnp_wccs_rules' );
		} else {
			$this->usage_count_cache = array();
		}
	}

	public function log_rule_usage( $rule_id, $order_id, $usage_count = 1 ) {
		if ( empty( $rule_id ) || empty( $order_id ) ) {
			throw new Exception( 'Rule ID and Order ID is required.' );
		}

		$this->insert( array(
			'rule_id'     => absint( $rule_id ),
			'order_id'    => absint( $order_id ),
			'usage_count' => absint( $usage_count ),
			'created_at'  => current_time( 'mysql' ),
		), 'rule_usage_log' );

		$this->clear_rule_usage_cache( $rule_id );
	}

	public function log_rules_usage( $rules_usage ) {
		if ( empty( $rules_usage ) ) {
			return;
		}

		global $wpdb;

		$columns = $this->get_columns();
		unset( $columns['id'] );

		$placeholders = '(' . implode( ',', array_values( $columns ) ) . ')';
		$query_values = [];
		$query_data   = [];
		$affected_rule_ids = [];

		foreach ( $rules_usage as $rule_usage ) {
			$values = wp_parse_args( $rule_usage, $this->get_column_defaults() );

			$query_values[] = $placeholders;

			foreach ( array_keys( $columns ) as $column ) {
				$query_data[] = isset( $values[ $column ] ) ? $values[ $column ] : null;
			}

			if ( ! empty( $values['rule_id'] ) ) {
				$affected_rule_ids[] = absint( $values['rule_id'] );
			}
		}

		$query = $wpdb->prepare(
			"INSERT INTO {$this->table_name} (" . implode( ',', array_keys( $columns ) ) . ") VALUES " . implode( ',', $query_values ),
			$query_data
		);

		$result = $wpdb->query( $query ) ? $wpdb->rows_affected : false;

		foreach ( array_unique( $affected_rule_ids ) as $rid ) {
			$this->clear_rule_usage_cache( $rid );
		}

		return $result;
	}

	public function get_rule_usage_count( $rule_id ) {
		if ( empty( $rule_id ) ) {
			throw new Exception( 'Rule ID is required.' );
		}

		global $wpdb;

		$usage_count = $wpdb->get_var( $wpdb->prepare(
			"SELECT SUM(usage_count) FROM {$this->table_name} WHERE rule_id = %d",
			$rule_id
		) );

		return (int) $usage_count;
	}

	public function get_unique_order_count_by_rule( $rule_id ) {
		if ( empty( $rule_id ) ) {
			throw new Exception( 'Rule ID is required.' );
		}

		$rule_id = absint( $rule_id );
		if ( isset( $this->usage_count_cache[ $rule_id ] ) ) {
			return $this->usage_count_cache[ $rule_id ];
		}

		$cache_key = 'asnp_wccs_rule_usage_' . $rule_id;
		$cached    = wp_cache_get( $cache_key, 'asnp_wccs_rules' );
		if ( false !== $cached ) {
			$this->usage_count_cache[ $rule_id ] = (int) $cached;
			return (int) $cached;
		}

		global $wpdb;

		$usage_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT order_id) FROM {$this->table_name} WHERE rule_id = %d",
			$rule_id
		) );

		$this->usage_count_cache[ $rule_id ] = $usage_count;
		wp_cache_set( $cache_key, $usage_count, 'asnp_wccs_rules', 300 );

		return $usage_count;
	}

    /**
	 * Create the table
	 *
	 * @since 8.19.0
	 */
	public function create_table() {
		global $wpdb;

		$collate = '';
		if ( $wpdb->has_cap( 'collation' ) ) {
			$collate = $wpdb->get_charset_collate();
		}

		$sql = "CREATE TABLE " . $this->table_name . " (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        rule_id BIGINT(20) UNSIGNED NOT NULL,
		order_id BIGINT(20) UNSIGNED NOT NULL,
		usage_count INT(11) UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY rule_id (rule_id),
        KEY order_id (order_id),
        KEY rule_order (rule_id, order_id)
		) $collate;";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}

}
