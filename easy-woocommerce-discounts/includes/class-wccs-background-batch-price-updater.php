<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WCCS_Background_Batch_Price_Updater Class.
 *
 * Orchestrates background price cache warming across store products with chunked
 * execution, debounced updates, and memory protection.
 *
 * @package    WC_Conditions
 * @subpackage WC_Conditions/includes
 */
class WCCS_Background_Batch_Price_Updater {

    /**
     * Background process instance.
     *
     * @var WCCS_Background_Price_Updater
     */
    protected $background_process;

    /**
     * Product IDs queued for update during the current request.
     *
     * @var array<int>
     */
    protected $products_to_update = array();

    /**
     * Flag indicating full store price update is scheduled for shutdown.
     *
     * @var bool
     */
    protected $update_all_scheduled = false;

    public function init() {
        if ( ! apply_filters( 'wccs_background_products_price_updates', true ) ) {
            return;
        }

        include_once dirname( __FILE__ ) . '/class-wccs-background-price-updater.php';

        $this->background_process = new WCCS_Background_Price_Updater();

        if ( current_user_can( 'manage_woocommerce' ) ) {
            add_action( 'admin_init', array( $this, 'update_prices_request' ) );
        }

        // Automatically update prices if enabled in plugin settings.
        if ( (int) apply_filters( 'wccs_background_auto_update_products_price', WCCS()->settings->get_setting( 'auto_update_products_price', 0 ) ) ) {
            add_action( 'wccs_pricing_caches_cleared', array( $this, 'schedule_full_update' ) );
            add_action( 'wccs_product_cache_deleted', array( $this, 'maybe_update_product_price' ) );
            add_action( 'woocommerce_settings_saved', array( $this, 'schedule_full_update' ) );
            add_action( 'update_option_wccs_settings', array( $this, 'schedule_full_update' ) );
            add_action( 'shutdown', array( $this, 'process_scheduled_updates' ) );
        }

        add_action( 'admin_init', array( $this, 'updating_prices_notice' ) );
        add_action( 'wccs_hide_updating_prices_notice', array( $this, 'dismiss_updating_prices_notice' ) );
    }

    /**
     * Get the background process instance.
     *
     * @return WCCS_Background_Price_Updater
     */
    public function get_background_process() {
        return $this->background_process;
    }

    public function schedule_full_update() {
        $this->update_all_scheduled = true;
    }

    public function maybe_update_product_price( $product_id ) {
        $product_id = absint( $product_id );
        if ( $product_id > 0 && ! in_array( $product_id, $this->products_to_update, true ) ) {
            $this->products_to_update[] = $product_id;
        }
    }

    public function process_scheduled_updates() {
        if ( $this->update_all_scheduled ) {
            $this->queue_update_prices();
            return;
        }

        if ( empty( $this->products_to_update ) ) {
            return;
        }

        // Queue single/batch updated products without canceling the full queue
        $chunk_size  = apply_filters( 'wccs_background_price_updater_chunk_size', 20 );
        $chunks      = array_chunk( $this->products_to_update, $chunk_size );
        $current_gen = WCCS_Clear_Cache::get_generation();

        foreach ( $chunks as $chunk ) {
            $this->background_process->push_to_queue( array(
                'product_ids' => $chunk,
                'generation'  => $current_gen,
            ) );
        }

        $this->products_to_update = array();
        $this->background_process->save()->dispatch();
    }

    public function update_prices_request() {
        if ( empty( $_GET['do_update_products_price_asnp_wccs'] ) ) {
            return;
        } 
        
        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wccs_update_products_price_nonce'] ) ), 'wccs_update_products_price_nonce' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $this->queue_update_prices();
        wp_safe_redirect( remove_query_arg( array( 'do_update_products_price_asnp_wccs', '_wccs_update_products_price_nonce' ), wp_get_referer() ) );
        exit;
    }

    public function maybe_update_prices() {
        $this->queue_update_prices();
    }

    public function queue_update_prices() {
        global $wpdb;

        // Cancel existing running queue to avoid redundant passes
        $this->background_process->kill_process();

        $product_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
              AND post_status = 'publish'
            ORDER BY ID DESC"
        );

        if ( empty( $product_ids ) ) {
            return;
        }

        $chunk_size  = apply_filters( 'wccs_background_price_updater_chunk_size', 20 );
        $chunks      = array_chunk( $product_ids, $chunk_size );
        $current_gen = WCCS_Clear_Cache::get_generation();

        foreach ( $chunks as $chunk ) {
            $this->background_process->push_to_queue( array(
                'product_ids' => $chunk,
                'generation'  => $current_gen,
            ) );
        }

        // Dispatch the queue to start processing
        $this->background_process->save()->dispatch();

        set_transient( 'asnp_wccs_updating_prices_running', 1, 3600 );
        WCCS()->WCCS_Admin_Notices->add_notice( 'update_price' );
    }

    public function updating_prices_notice() {
        if ( $this->background_process->is_updating() ) {
            WCCS()->WCCS_Admin_Notices->add_notice( 'update_price' );
        } else {
            WCCS()->WCCS_Admin_Notices->remove_notice( 'update_price' );
            delete_transient( 'asnp_wccs_updating_prices_running' );
        }
    }

    public function dismiss_updating_prices_notice() {
        if ( ! $this->background_process ) {
            return;
        }

        $this->background_process->kill_process();
        delete_transient( 'asnp_wccs_updating_prices_running' );
        WCCS()->WCCS_Admin_Notices->remove_notice( 'update_price' );

        $logger = WCCS_Helpers::wc_get_logger();
        if ( WCCS_Helpers::wc_version_check() ) {
            $logger->info( __( 'Cancelled product price update job.', 'easy-woocommerce-discounts' ), array( 'source' => 'wccs_price_updater' ) );
        } else {
            $logger->add( 'wccs_price_updater', __( 'Cancelled product price update job.', 'easy-woocommerce-discounts' ) );
        }
    }

}
