<?php

defined( 'ABSPATH' ) || exit;

class WCCS_Usage_Validator {

	/**
	 * Memoized usage limit results per request.
	 *
	 * @var array
	 */
	protected static $checked_limits = array();

	public static function check_rule_usage_limit( $rule ) {
		$rule_id = is_object( $rule ) ? ( isset( $rule->id ) ? (int) $rule->id : 0 ) : ( is_array( $rule ) ? ( isset( $rule['id'] ) ? (int) $rule['id'] : 0 ) : 0 );
		if ( $rule_id <= 0 ) {
			return false;
		}

		$usage_limit = is_object( $rule ) ? ( isset( $rule->usage_limit ) ? (int) $rule->usage_limit : 0 ) : ( is_array( $rule ) ? ( isset( $rule['usage_limit'] ) ? (int) $rule['usage_limit'] : 0 ) : 0 );
		if ( $usage_limit <= 0 ) {
			return true;
		}

		if ( isset( self::$checked_limits[ $rule_id ] ) ) {
			return self::$checked_limits[ $rule_id ];
		}

		$model = WCCS()->container()->get( WCCS_DB_Rule_Usage_Logs::class );

		$usage_count = $model->get_unique_order_count_by_rule( $rule_id );

		if ( empty( $usage_count ) ) {
			return self::$checked_limits[ $rule_id ] = true;
		}

		return self::$checked_limits[ $rule_id ] = ( (int) $usage_count < $usage_limit );
	}

	public static function reset_cache() {
		self::$checked_limits = array();
	}

}
