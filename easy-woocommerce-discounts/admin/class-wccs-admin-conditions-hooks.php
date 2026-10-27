<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WCCS_Admin_Conditions_Hooks {

    protected $loader;

    public function __construct( WCCS_Loader $loader ) {
        $this->loader = $loader;
    }

    public function enable_hooks() {
        $this->loader->add_action( 'wccs_condition_added', $this, 'condition_added' );
        $this->loader->add_action( 'wccs_condition_deleted', $this, 'condition_deleted' );
        $this->loader->add_action( 'wccs_condition_updated', $this, 'condition_updated' );
        $this->loader->add_action( 'wccs_conditions_ordering_updated', $this, 'conditions_ordering_updated' );
        $this->loader->add_action( 'wccs_condition_duplicated', $this, 'condition_duplicated' );
    }

    protected function get_condition_type( $condition ) {
        if ( is_object( $condition ) && isset( $condition->type ) ) {
            return $condition->type;
        }
        if ( is_array( $condition ) && isset( $condition['type'] ) ) {
            return $condition['type'];
        }
        return null;
    }

    public function condition_added( $condition ) {
        if ( ! $condition || ( ! isset( $condition->id ) && ! isset( $condition['id'] ) ) ) {
            return;
        }

        $type = $this->get_condition_type( $condition );
        $this->clear_rules_cache_by_type( $type );
    }

    public function condition_deleted( $condition ) {
        if ( ! $condition || ( ! isset( $condition->id ) && ! isset( $condition['id'] ) ) ) {
            return;
        }

        $type = $this->get_condition_type( $condition );
        $this->clear_rules_cache_by_type( $type );
    }

    public function condition_updated( $condition ) {
        if ( ! $condition || ( ! isset( $condition->id ) && ! isset( $condition['id'] ) ) ) {
            return;
        }

        $type = $this->get_condition_type( $condition );
        $this->clear_rules_cache_by_type( $type );
    }

    public function condition_duplicated( $condition_id ) {
        if ( ! $condition_id ) {
            return;
        }

        $condition = WCCS()->conditions->get_condition( $condition_id );
        $type      = $this->get_condition_type( $condition );
        $this->clear_rules_cache_by_type( $type );
    }

    public function conditions_ordering_updated( $type ) {
        $this->clear_rules_cache_by_type( $type );
    }

    /**
     * Clear cached rules and services selectively based on rule type.
     *
     * @param string|null $type Condition type (e.g. 'pricing', 'cart-discount', 'checkout-fee', 'shipping').
     * @return void
     */
    protected function clear_rules_cache_by_type( $type = null ) {
        WCCS()->conditions->clear_rules_cache( $type );

        // When a pricing rule changes (or all rules are cleared), clear product price and badge caches
        if ( 'pricing' === $type || empty( $type ) ) {
            WCCS_Clear_Cache::clear_pricing_caches( true );
        }
    }

}
