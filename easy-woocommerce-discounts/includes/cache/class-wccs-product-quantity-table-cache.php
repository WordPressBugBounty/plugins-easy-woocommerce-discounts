<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Product_Quantity_Table_Cache extends WCCS_Abstract_Cache {

    const TYPE = 'quantity_table';

    public function __construct() {
        parent::__construct( 'wccs_product_quantity_table_', 'wccs_product_quantity_table' );
    }

    protected function get_object_cache_key( $product_id ) {
        return $this->get_cache_prefix() . absint( $product_id ) . '_' . $this->get_transient_version();
    }

    public function get_quantity_table( array $args ) {
        if ( empty( $args ) || empty( $args['product_id'] ) ) {
            return false;
        }

        $product_id = absint( $args['product_id'] );
        if ( 0 >= $product_id ) {
            return false;
        }

        $cache_key = $this->get_object_cache_key( $product_id );
        $value     = wp_cache_get( $cache_key, $this->get_cache_group() );
        $key       = md5( wp_json_encode( $args ) );

        if ( false !== $value && is_array( $value ) ) {
            return isset( $value[ $key ] ) ? $value[ $key ] : false;
        }

        $cache = WCCS()->WCCS_DB_Cache->get_item_by_product( $product_id, static::TYPE );
        $value = ! empty( $cache->value ) && is_array( $cache->value ) ? $cache->value : array();

        wp_cache_set( $cache_key, $value, $this->get_cache_group(), DAY_IN_SECONDS );

        return isset( $value[ $key ] ) ? $value[ $key ] : false;
    }

    public function set_quantity_table( array $args, $table ) {
        if ( empty( $args ) || empty( $args['product_id'] ) ) {
            return false;
        }

        $product_id = absint( $args['product_id'] );
        if ( 0 >= $product_id ) {
            return false;
        }

        $cache = WCCS()->WCCS_DB_Cache->get_item_by_product( $product_id, static::TYPE );
        $value = ! empty( $cache->value ) && is_array( $cache->value ) ? $cache->value : array();
        $key   = md5( wp_json_encode( $args ) );

        $value[ $key ] = $table;

        if ( $cache ) {
            $result = WCCS()->WCCS_DB_Cache->update( $cache->id, array( 'value' => maybe_serialize( $value ) ) ); 
        } else {
            $result = WCCS()->WCCS_DB_Cache->add( array( 'product_id' => $product_id, 'cache_type' => static::TYPE, 'value' => maybe_serialize( $value ) ) );
        }

        $cache_key = $this->get_object_cache_key( $product_id );
        wp_cache_set( $cache_key, $value, $this->get_cache_group(), DAY_IN_SECONDS );

        return $result;
    }

    public function delete_product_cache( $product_id ) {
        $product_id = absint( $product_id );
        if ( 0 >= $product_id ) {
            return false;
        }

        $cache_key = $this->get_object_cache_key( $product_id );
        return wp_cache_delete( $cache_key, $this->get_cache_group() );
    }

    public function clear_cache() {
        parent::clear_cache();
        $this->get_transient_version( true );
    }

}
