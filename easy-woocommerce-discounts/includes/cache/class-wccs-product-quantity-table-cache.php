<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WCCS_Product_Quantity_Table_Cache
 *
 * @deprecated 9.12.0 Quantity table caching is deprecated and disabled.
 *                    Quantity tables are rendered directly on single product pages with minimal overhead.
 *                    This class is retained as a lightweight shim for backwards compatibility.
 */
class WCCS_Product_Quantity_Table_Cache extends WCCS_Abstract_Cache {

	const TYPE        = 'quantity_table';
	const CACHE_GROUP = 'asnp_wccs_product_quantity_table';

	protected $pricing;

	public function __construct( $pricing = null ) {
		$this->pricing = null === $pricing ? ( function_exists( 'WCCS' ) ? WCCS()->pricing : null ) : $pricing;
		parent::__construct( 'wccs_product_quantity_table_', static::CACHE_GROUP );
	}

	/**
	 * @deprecated 9.12.0
	 * @return bool
	 */
	public function is_cache_enabled() {
		return false;
	}

	/**
	 * @deprecated 9.12.0
	 * @param array $args
	 * @return false
	 */
	public function build_cache_key( array $args ) {
		return false;
	}

	/**
	 * @deprecated 9.12.0
	 * @param array $args
	 * @return false
	 */
	public function get_quantity_table( array $args ) {
		return false;
	}

	/**
	 * @deprecated 9.12.0
	 * @param array          $args
	 * @param string         $table
	 * @param WC_Product|int $product
	 * @return bool
	 */
	public function set_quantity_table( array $args, $table, $product = null ) {
		return true;
	}

	/**
	 * @deprecated 9.12.0
	 * @param int $parent_id
	 * @param int $variation_id
	 * @return array
	 */
	public function get_product_candidate_rules( $parent_id, $variation_id = 0 ) {
		return array();
	}

	/**
	 * @deprecated 9.12.0
	 * @param int $product_id
	 * @return string
	 */
	public function get_product_cache_generation( $product_id ) {
		return '';
	}

	/**
	 * @deprecated 9.12.0
	 * @param int $product_id
	 * @return bool
	 */
	public function delete_product_cache( $product_id ) {
		return true;
	}

	/**
	 * @deprecated 9.12.0
	 * @return void
	 */
	public function clear_cache() {
		// No-op for deprecated cache.
	}

	/**
	 * @deprecated 9.12.0
	 * @return void
	 */
	public function clear_cache_deprecated() {
		// No-op for deprecated cache.
	}

	/**
	 * @deprecated 9.12.0
	 * @return void
	 */
	public function clear_local_cache() {
		// No-op for deprecated cache.
	}

}
