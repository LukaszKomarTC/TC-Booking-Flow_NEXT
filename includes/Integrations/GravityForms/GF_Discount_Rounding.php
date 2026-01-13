<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Support\Money;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms calculation parity with WooCommerce rounding.
 *
 * Problem:
 * - GF calculated field #176 ("Partner discount") computes 1% of "Base after EB".
 * - GF rounds half-up -> 0.475 becomes 0.48.
 * - Woo coupon distribution effectively rounds down -> 0.475 becomes 0.47.
 *
 * This hook forces GF to round DOWN for that specific calculated field, so:
 * - Frontend display matches Woo cart
 * - The submitted entry + notifications store the same value
 */
final class GF_Discount_Rounding {

	/**
	 * Form + field ids for the current TC Booking Flow GF form.
	 *
	 * If the form is duplicated/changed, update these ids or extend with settings.
	 */
	private const FORM_ID  = 48;
	private const FIELD_ID = 176; // Partner discount (number calculation)

	public function init() : void {
		// Server-side calculation rounding. Affects entry values + confirmations.
		add_filter('gform_calculation_result', [ $this, 'filter_calc_result' ], 20, 4);
	}

	/**
	 * @param mixed $result
	 * @param string $formula
	 * @param \GF_Field $field
	 * @param array $form
	 * @return mixed
	 */
	public function filter_calc_result( $result, $formula, $field, $form ) {
		$fid = isset($form['id']) ? (int) $form['id'] : 0;
		$fld = isset($field->id) ? (int) $field->id : 0;

		if ( $fid !== self::FORM_ID || $fld !== self::FIELD_ID ) {
			return $result;
		}

		// TCBF-12: If partner program disabled for this event, force discount to 0
		$partners_enabled = isset($_POST['input_181']) ? trim((string) $_POST['input_181']) : '1';
		if ( $partners_enabled === '0' ) {
			return '0';
		}

		// Normalize to float, round DOWN to cents, return as a numeric string.
		$v = is_numeric($result) ? (float) $result : Money::money_to_float($result);
		$v = Money::money_round_down($v);
		return Money::float_to_str($v);
	}
}
