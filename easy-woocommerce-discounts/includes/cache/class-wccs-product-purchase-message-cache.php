<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WCCS_Product_Purchase_Message_Cache
 *
 * @deprecated 9.12.0 Purchase message caching is deprecated and disabled.
 *                    Purchase messages are rendered directly on single product pages with minimal overhead.
 *                    This class is retained as a lightweight shim for backwards compatibility.
 */
class WCCS_Product_Purchase_Message_Cache extends WCCS_Abstract_Cache {

    const TYPE = 'message';

    public function __construct() {
        parent::__construct( 'wccs_product_purchase_message_', 'wccs_product_purchase_message' );
    }

    /**
     * @deprecated 9.12.0
     * @param array $args
     * @return false
     */
    public function get_purchase_message( array $args ) {
        return false;
    }

    /**
     * @deprecated 9.12.0
     * @param array  $args
     * @param string $message
     * @return bool
     */
    public function set_purchase_message( array $args, $message ) {
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

}
