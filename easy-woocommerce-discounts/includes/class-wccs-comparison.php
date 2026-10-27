<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WCCS_Comparison {

	/**
	 * Compares two arrays based on given type.
	 *
	 * @since  2.0.0
	 *
	 * @param  array  $cmp
	 * @param  array  $cmp_to
	 * @param  string $type
	 * @param  int    $number
	 *
	 * @return boolean
	 */
	public static function union_compare( array $cmp, array $cmp_to, $type, $number = 2 ) {
		switch ( $type ) {
			case 'none':
			case 'none_of':
				return ! count( array_intersect( $cmp, $cmp_to ) );

			case 'at_least_one_of':
				return count( array_intersect( $cmp, $cmp_to ) );

			case 'all_of':
				return ! count( array_diff( $cmp, $cmp_to ) );

			case 'only':
				return ! count( array_diff( $cmp, $cmp_to ) ) && ! count( array_diff( $cmp_to, $cmp ) );

			case 'at_least':
				return count( array_intersect( $cmp, $cmp_to ) ) >= $number;

			case 'less_than':
				return count( array_intersect( $cmp, $cmp_to ) ) < $number;

			default:
				break;
		}

		return false;
	}

	/**
	 * Compare two values by given math operations.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed  $value
	 * @param  mixed  $against_value
	 * @param  string $operation
	 *
	 * @return boolean
	 */
	public static function math_compare( $value, $against_value, $operation ) {
		if ( 'less_than' === $operation ) {
			return $value < $against_value;
		} elseif ( 'less_equal_to' === $operation ) {
			return $value <= $against_value;
		} elseif ( 'greater_than' === $operation ) {
			return $value > $against_value;
		} elseif ( 'greater_equal_to' === $operation ) {
			return $value >= $against_value;
		} elseif ( 'equal_to' === $operation ) {
			return $value == $against_value;
		} elseif ( 'not_equal_to' === $operation ) {
			return $value != $against_value;
		}

		return false;
	}

	/**
	 * Is given values equals.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $value
	 * @param  mixed $cmp_value
	 *
	 * @return boolean
	 */
	public static function equal_to( $value, $cmp_value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $v ) {
				if ( ! static::equal_to( $v, $cmp_value ) ) {
					return false;
				}
			}

			return true;
		}

		return $value == $cmp_value;
	}

	/**
	 * Is given value less than compare value.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $value
	 * @param  mixed $cmp_value
	 *
	 * @return boolean
	 */
	public static function less_than( $value, $cmp_value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $v ) {
				if ( ! static::less_than( $v, $cmp_value ) ) {
					return false;
				}
			}

			return true;
		}

		return $value < $cmp_value;
	}

	/**
	 * Is given values less or equal to compare value.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $value
	 * @param  mixed $cmp_value
	 *
	 * @return boolean
	 */
	public static function less_equal_to( $value, $cmp_value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $v ) {
				if ( ! static::less_equal_to( $v, $cmp_value ) ) {
					return false;
				}
			}

			return true;
		}

		return $value <= $cmp_value;
	}

	/**
	 * Is given values greater than compare value.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $value
	 * @param  mixed $cmp_value
	 *
	 * @return boolean
	 */
	public static function greater_than( $value, $cmp_value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $v ) {
				if ( ! static::greater_than( $v, $cmp_value ) ) {
					return false;
				}
			}

			return true;
		}

		return $value > $cmp_value;
	}

	/**
	 * Is given values greater or equal to compare value.
	 *
	 * @since  2.0.0
	 *
	 * @param  mixed $value
	 * @param  mixed $cmp_value
	 *
	 * @return boolean
	 */
	public static function greater_equal_to( $value, $cmp_value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return false;
			}

			foreach ( $value as $v ) {
				if ( ! static::greater_equal_to( $v, $cmp_value ) ) {
					return false;
				}
			}

			return true;
		}

		return $value >= $cmp_value;
	}

}
