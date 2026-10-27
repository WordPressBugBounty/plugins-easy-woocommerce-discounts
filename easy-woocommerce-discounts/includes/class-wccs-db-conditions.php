<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_DB_Conditions extends WCCS_DB {

	/**
	 * Local request cache for conditions.
	 *
	 * @var array
	 */
	protected $active_rules_cache = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		global $wpdb;

		$this->table_name         = $wpdb->prefix . 'wccs_conditions';
		$this->primary_key        = 'id';
		$this->version            = '1.0';
		$this->active_rules_cache = array();
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
			'id'       => '%d',
			'name'     => '%s',
			'type'     => '%s',
			'ordering' => '%d',
			'status'   => '%d',
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
		return array();
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
	public function add( array $data = array() ) {
		$defaults = $this->get_column_defaults();

		$args = wp_parse_args( $data, $defaults );

		if ( empty( $args['id'] ) && empty( $args['type'] ) ) {
			return false;
		}

		if ( isset( $args['id'] ) ) {
			$condition = $this->get_condition( $args['id'] );
			if ( $condition ) {
				$this->update( $condition->id, $args );
				return $condition->id;
			}
		}

		// setting ordering.
		if ( empty( $args['ordering'] ) ) {
			$args['ordering'] = $this->get_great_order( $args['type'] ) + 1;
		}

		$condition_id = $this->insert( $args, 'condition' );

		return $condition_id ? $condition_id : false;
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
	public function get_condition( $id, $output = OBJECT, $include_meta = true ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$condition = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $this->table_name WHERE id = %d LIMIT 1", $id ), $output );

		if ( $condition && $include_meta ) {
			$meta_data = WCCS()->condition_meta->get_meta( $condition->id, '', true );
			if ( ! empty( $meta_data ) ) {
				foreach ( $meta_data as $key => $value ) {
					if ( is_object( $condition ) ) {
						if ( ! isset( $condition->{$key} ) ) {
							$condition->{$key} = maybe_unserialize( $value[0] );
						}
					} elseif ( isset( $condition['id'] ) ) {
						if ( ! isset( $condition[ $key ] ) ) {
							$condition[ $key ] = maybe_unserialize( $value[0] );
						}
					}
				}
			}
		}

		return $condition ? $condition : false;
	}

	public function get_conditions_by_name( $name, array $args = [] ) {
		$args = array_merge( [
			'title'  => $name,
			'number' => -1,
		], $args );

		if ( is_null( $args['title'] ) || '' === $args['title'] || ctype_space( $args['title'] ) ) {
			return false;
		}

		return $this->get_conditions( $args );
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

		$condition = $this->get_condition( $id );

		if ( $condition && ! empty( $condition->id ) ) {
			global $wpdb;
			$delete = $wpdb->delete( $this->table_name, array( 'id' => $condition->id ), array( '%d' ) );

			if ( $delete ) {
				WCCS()->condition_meta->delete_all_meta( $condition->id );
			}

			return $delete;
		}

		return false;
	}

	/**
	 * Get the rules cache service instance.
	 *
	 * @return WCCS_Rules_Cache
	 */
	public function get_rules_cache() {
		$rules_cache = WCCS()->container()->get( WCCS_Rules_Cache::class );
		if ( $rules_cache instanceof WCCS_Rules_Cache ) {
			return $rules_cache;
		}

		return WCCS_Rules_Cache::instance();
	}

	/**
	 * Clear cached conditions across all cache tiers, optionally filtered by rule type.
	 *
	 * @param  string|null $type Optional condition type (e.g. 'pricing', 'cart-discount', 'checkout-fee', 'shipping').
	 * @return void
	 */
	public function clear_rules_cache( $type = null ) {
		if ( ! empty( $type ) && is_string( $type ) && ! empty( $this->active_rules_cache) ) {
			$type_prefix = 'asnp_wccs_rules_' . sanitize_key( $type ) . '_';
			$all_prefix  = 'asnp_wccs_rules_all_';
			foreach ( array_keys( $this->active_rules_cache ) as $key ) {
				if ( 0 === strpos( $key, $type_prefix ) || 0 === strpos( $key, $all_prefix ) ) {
					unset( $this->active_rules_cache[ $key ] );
				}
			}
		} else {
			$this->active_rules_cache = array();
		}

		$this->get_rules_cache()->clear_cache( $type );
	}

	/**
	 * Determine whether the conditions query should be cached in Multi-Tier Rules Cache.
	 *
	 * Caching defaults to false in get_conditions() and is strictly opt-in via 'cache' => true.
	 * Only queries explicitly requesting cache (cache => true) without suppress_cache or
	 * single-item lookups are eligible for rules caching.
	 *
	 * @param  array $args Query arguments.
	 * @return bool
	 */
	public function is_cacheable( array $args ) {
		if ( empty( $args['cache'] ) || ! empty( $args['suppress_cache'] ) ) {
			return false;
		}

		// Specific lookups by ID, name, or title are lookups, not evaluation rule sets
		if ( ! empty( $args['id'] ) || ! empty( $args['name'] ) || ! empty( $args['title'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Retrieve conditions from the database
	 *
	 * @since  1.0.0
	 *
	 * @param  array  $args
	 *
	 * @return array
	 */
	public function get_conditions( array $args = array() ) {
		global $wpdb;

		$defaults = array(
			'number'       => 20,
			'offset'       => 0,
			'orderby'      => 'id',
			'order'        => 'DESC',
			'output'       => OBJECT,
			'include_meta' => true,
			'cache'        => false,
		);

		$is_cacheable = $this->is_cacheable( $args );
		$args         = wp_parse_args( $args, $defaults );
		unset( $args['cache'], $args['suppress_cache'] );

		if ( $args['number'] < 1 ) {
			$args['number'] = 999999999999;
		}

		$valid_columns   = array_keys( $this->get_columns() );
		$args['orderby'] = in_array( $args['orderby'], $valid_columns, true ) ? $args['orderby'] : 'id';
		$args['order']   = 'ASC' === strtoupper( trim( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		// Check Multi-Tier Hybrid Rules Cache (Memory -> Object Cache -> OPcache File -> Master Snapshot Option)
		if ( $is_cacheable ) {
			$rules_cache = $this->get_rules_cache();
			$cached      = $rules_cache->get( $args );
			if ( false !== $cached ) {
				$cache_key = $rules_cache->generate_cache_key( $args );
				$this->active_rules_cache[ $cache_key ] = $cached;
				return $cached;
			}
		}

		$select_args = array();
		$where       = ' WHERE 1=1';

		// Specific conditions.
		if ( ! empty( $args['id'] ) ) {
			$raw_ids = is_array( $args['id'] ) ? $args['id'] : array( $args['id'] );
			$ids     = array_values( array_filter( array_map( 'absint', $raw_ids ) ) );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
				$where       .= " AND `id` IN ( {$placeholders} )";
				foreach ( $ids as $id ) {
					$select_args[] = $id;
				}
			} else {
				$where .= ' AND 1=0';
			}
		}

		// Specific type.
		if ( ! empty( $args['type'] ) ) {
			$raw_types = is_array( $args['type'] ) ? $args['type'] : array( $args['type'] );
			$types     = array_values( array_filter( array_map( 'sanitize_key', $raw_types ) ) );
			if ( ! empty( $types ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
				$where       .= " AND `type` IN ( {$placeholders} )";
				foreach ( $types as $type_slug ) {
					$select_args[] = $type_slug;
				}
			} else {
				$where .= ' AND 1=0';
			}
		}

		// Search by name.
		if ( ! empty( $args['name'] ) ) {
			$where         .= ' AND LOWER(`name`) LIKE %s';
			$select_args[] = '%' . $wpdb->esc_like( strtolower( sanitize_text_field( $args['name'] ) ) ) . '%';
			// Exact search by name.
		} elseif ( ! empty( $args['title'] ) ) {
			$where         .= ' AND LOWER(`name`) = %s';
			$select_args[] = strtolower( sanitize_text_field( $args['title'] ) );
		}

		// Status.
		if ( isset( $args['status'] ) && '' !== $args['status'] ) {
			$where         .= ' AND `status` = %d';
			$select_args[] = intval( $args['status'] );
		}

		$select_args[] = absint( $args['offset'] );
		$select_args[] = absint( $args['number'] );

		$conditions = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_name} {$where} ORDER BY `{$args['orderby']}` {$args['order']} LIMIT %d, %d;", $select_args ), $args['output'] );

		if ( ! empty( $conditions ) && $args['include_meta'] ) {
			$condition_ids = array();
			foreach ( $conditions as $c ) {
				$cid = is_object( $c ) ? (int) $c->id : (int) $c['id'];
				if ( $cid > 0 ) {
					$condition_ids[] = $cid;
				}
			}

			// Batch prime all metadata in ONE query instead of N+1 individual queries
			if ( ! empty( $condition_ids ) ) {
				update_meta_cache( 'wccs_condition', $condition_ids );
			}

			$wccs = WCCS();
			foreach ( $conditions as &$condition ) {
				$cid = is_object( $condition ) ? (int) $condition->id : (int) $condition['id'];
				$meta_data = $wccs->condition_meta->get_meta( $cid, '', true );
				if ( ! empty( $meta_data ) ) {
					foreach ( $meta_data as $key => $value ) {
						$val = maybe_unserialize( is_array( $value ) && isset( $value[0] ) ? $value[0] : $value );
						if ( is_object( $condition ) ) {
							if ( ! isset( $condition->{$key} ) ) {
								$condition->{$key} = $val;
							}
						} elseif ( isset( $condition['id'] ) ) {
							if ( ! isset( $condition[ $key ] ) ) {
								$condition[ $key ] = $val;
							}
						}
					}
				}
			}
			unset( $condition );
		}

		if ( $is_cacheable ) {
			$rules_cache = isset( $rules_cache ) ? $rules_cache : $this->get_rules_cache();
			$cache_key   = $rules_cache->generate_cache_key( $args );
			$this->active_rules_cache[ $cache_key ] = $conditions;
			$rules_cache->set( $args, $conditions );
		}

		return $conditions;
	}

	/**
	 * Updating conditions ordering.
	 *
	 * @since  1.0.0
	 *
	 * @param  array $conditions
	 *
	 * @return boolean
	 */
	public function update_conditions_ordering( array $conditions ) {
		if ( empty( $conditions ) ) {
			return false;
		}

		global $wpdb;

		foreach ( $conditions as $condition ) {
			if ( empty( $condition['id'] ) || ! isset( $condition['ordering'] ) || '' === $condition['ordering'] ) {
				continue;
			}

			if ( false === $wpdb->update( $this->table_name, array( 'ordering' => intval( $condition['ordering'] ) ), array( 'id' => absint( $condition['id'] ) ), array( '%d' ), array( '%d' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Duplicating a condition.
	 *
	 * @since  2.1.0
	 *
	 * @param  int $id
	 *
	 * @return int|false condition id on success and false on failure.
	 */
	public function duplicate( $id ) {
		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$condition = $this->get_condition( $id, ARRAY_A, false );
		if ( ! $condition ) {
			return false;
		}

		unset( $condition['id'] );
		$condition['name'] = sprintf( '%s (Copy)', $condition['name'] );
		$condition_id = $this->add( $condition );
		if ( ! $condition_id ) {
			return false;
		}

		$meta_data = WCCS()->condition_meta->get_meta( $id, '', true );
		if ( ! empty( $meta_data ) ) {
			foreach ( $meta_data as $meta_key => $meta_value ) {
				if ( ! WCCS()->condition_meta->add_meta( $condition_id, $meta_key, maybe_unserialize( $meta_value[0] ), true ) ) {
					return false;
				}
			}
		}

		return $condition_id;
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
	 * Getting great order in conditions by type.
	 *
	 * @since  1.0.0
	 *
	 * @return int
	 */
	protected function get_great_order( $type ) {
		global $wpdb;
		$great_order = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(ordering) FROM $this->table_name WHERE `type` = %s", sanitize_text_field( $type ) ) );

		return absint( $great_order );
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
		type varchar(200) NOT NULL,
		name mediumtext NOT NULL DEFAULT '',
		ordering MEDIUMINT NOT NULL DEFAULT 0,
		status TINYINT NOT NULL DEFAULT '1',
		PRIMARY KEY  (id),
		KEY type_status_ordering (type(50), status, ordering),
		KEY status (status)
		) $collate;";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}

}
