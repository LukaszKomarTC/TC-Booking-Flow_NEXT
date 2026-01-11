<?php
namespace TC_BF\Support;

if ( ! defined('ABSPATH') ) exit;

/**
 * Money utility functions for handling currency formatting and conversions.
 */
final class Money {

	public static function float_to_str( float $v ) : string {
		return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
	}

	/**
	 * Percent values must be fed into Gravity Forms calculation fields using the site locale.
	 *
	 * In a decimal-comma locale, Gravity Forms may interpret a dot as a thousands separator.
	 * Example: "7.5" can be parsed as "75" which turns a 7.5% partner discount into 75%.
	 *
	 * We therefore output percentages as a decimal-comma string ("7,5") for GF fields.
	 */
	public static function pct_to_gf_str( float $v ) : string {
		$s = self::float_to_str( $v );
		return (strpos($s, '.') !== false) ? str_replace('.', ',', $s) : $s;
	}

	public static function money_to_float( $val ) : float {
		if ( is_numeric($val) ) return (float) $val;
		$s = trim((string) $val);
		if ( $s === '' ) return 0.0;
		// keep digits, comma, dot, minus
		$s = preg_replace('/[^0-9,\.\-]/', '', $s);
		// If comma is used as decimal separator and dot as thousands, normalize.
		if ( substr_count($s, ',') === 1 && substr_count($s, '.') >= 1 ) {
			// assume last separator is decimal, remove the other
			if ( strrpos($s, ',') > strrpos($s, '.') ) {
				$s = str_replace('.', '', $s);
				$s = str_replace(',', '.', $s);
			} else {
				$s = str_replace(',', '', $s);
			}
		} elseif ( substr_count($s, ',') === 1 && substr_count($s, '.') === 0 ) {
			$s = str_replace(',', '.', $s);
		} else {
			// multiple commas: treat as thousands
			if ( substr_count($s, ',') > 1 ) $s = str_replace(',', '', $s);
		}
		return is_numeric($s) ? (float) $s : 0.0;
	}

	/**
	 * Round money values to currency cents consistently.
	 * We round at each ledger output step to avoid 0.01 drift between GF and PHP.
	 */
	public static function money_round( float $v ) : float {
		// tiny epsilon mitigates binary float artifacts like 19.999999 -> 20.00
		return round($v + 1e-9, 2);
	}

}
