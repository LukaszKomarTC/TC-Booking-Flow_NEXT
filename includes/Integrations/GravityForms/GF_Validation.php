<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms Validation
 *
 * Server-side validation logic for GF booking forms.
 * Extracted from Plugin class for better separation of concerns.
 */
final class GF_Validation {

	// GF field IDs used in validation
	const GF_FIELD_EVENT_ID      = 20;
	const GF_FIELD_RENTAL_TYPE   = 106;
	const GF_FIELD_TOTAL         = 76;
	const GF_FIELD_CLIENT_TOTAL_A = 79;  // legacy/optional (not present in current GF48 export)
	const GF_FIELD_CLIENT_TOTAL_B = 168; // canonical: Total client
	const GF_FIELD_BIKE_130      = 130;
	const GF_FIELD_BIKE_142      = 142;
	const GF_FIELD_BIKE_143      = 143;
	const GF_FIELD_BIKE_169      = 169;

	/**
	 * GF validation hook callback - validates booking form submission
	 *
	 * @param array $validation_result The GF validation result array
	 * @return array Modified validation result
	 */
	public static function gf_validation( array $validation_result ) : array {
		$form = isset($validation_result['form']) ? $validation_result['form'] : null;
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return $validation_result;

		// Basic required field: event_id
		// Prefer resolving by GF inputName (survives refactors / re-imports that change numeric field IDs).
		$event_field_id = self::find_field_id_by_input_name($form, 'event_id', self::GF_FIELD_EVENT_ID);
		$event_id = isset($_POST['input_' . $event_field_id]) ? (int) $_POST['input_' . $event_field_id] : 0;
		if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) {
			$validation_result['is_valid'] = false;
			$validation_result['form'] = self::gf_mark_field_invalid($form, $event_field_id, __('Invalid event. Please reload the event page and try again.', 'tc-booking-flow'));
			return $validation_result;
		}

		// =========================================================
		// Validation (ledger-first, deterministic)
		// =========================================================
		// GF calculated money fields are DISPLAY ONLY. We recompute server-side ledger totals from intent inputs,
		// then compare against the submitted client totals to prevent JS/locale drift and tampering.
		//
		// Strategy: LOG mismatches for debugging, but ALLOW submission (server ledger is authoritative).
		// The cart and order will recalculate from ledger anyway, so blocking here creates UX friction.
		//
		// IMPORTANT: Never parse money with (float) in a decimal-comma locale. Always use money_to_float().
		$part_price = \TC_BF\Support\Money::money_to_float( get_post_meta($event_id, 'event_price', true) );
		if ( $part_price < 0 ) $part_price = 0.0;

		// Determine rental price (fixed per-event) based on rental type select (106)
		// or, as a fallback, based on which bike choice field has a value.
		$rental_raw = isset($_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) ? trim((string) $_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) : '';
		$meta_key   = '';
		if ( $rental_raw !== '' ) {
			$rt = strtoupper($rental_raw);
			if ( strpos($rt, 'ROAD') === 0 )        $meta_key = 'rental_price_road';
			elseif ( strpos($rt, 'MTB') === 0 )     $meta_key = 'rental_price_mtb';
			elseif ( strpos($rt, 'EMTB') === 0 )    $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E-MTB') === 0 )   $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E MTB') === 0 )   $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'GRAVEL') === 0 )  $meta_key = 'rental_price_gravel';
		}

		// Fallback: detect from selected bike field
		if ( $meta_key === '' ) {
			if ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_130]) )      $meta_key = 'rental_price_road';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_142]) ) $meta_key = 'rental_price_mtb';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_143]) ) $meta_key = 'rental_price_ebike';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_169]) ) $meta_key = 'rental_price_gravel';
		}

		$rental_price = 0.0;
		if ( $meta_key !== '' ) {
			$rental_price = \TC_BF\Support\Money::money_to_float( get_post_meta($event_id, $meta_key, true) );
		}
		if ( $rental_price < 0 ) $rental_price = 0.0;

		// ---- Build ledger totals (same rounding model as order ledger) ----
		$calc = \TC_BF\Domain\Ledger::calculate_for_event($event_id);
		$cfg  = (is_array($calc) && isset($calc['cfg']) && is_array($calc['cfg'])) ? (array) $calc['cfg'] : [];
		$eb_step = (is_array($calc) && isset($calc['step']) && is_array($calc['step'])) ? (array) $calc['step'] : [];

		$subtotal_original = \TC_BF\Support\Money::money_round( $part_price + $rental_price );

		// EB applies only to enabled scopes (participation/rental) and only when a step is active.
		$eb_base = 0.0;
		if ( ! empty($cfg['enabled']) ) {
			if ( ! empty($cfg['participation_enabled']) ) $eb_base += $part_price;
			if ( ! empty($cfg['rental_enabled']) )        $eb_base += $rental_price;
		}
		$eb_base = \TC_BF\Support\Money::money_round( $eb_base );

		$eb_amount = 0.0;
		$eb_pct_effective = 0.0;
		if ( $eb_base > 0 && ! empty($cfg['enabled']) && ! empty($eb_step) ) {
			$comp = \TC_BF\Domain\Ledger::compute_eb_amount( (float) $eb_base, (array) $eb_step, (array) ($cfg['global_cap'] ?? []) );
			$eb_amount = (float) ($comp['amount'] ?? 0.0);
			$eb_pct_effective = (float) ($comp['effective_pct'] ?? 0.0);
		}
		$eb_amount = \TC_BF\Support\Money::money_round( min($eb_base, max(0.0, $eb_amount)) );

		// Partner discount (percentage) — resolved deterministically from context.
		// Ensure hidden partner fields are written into POST (prevents stale/missing context).
		\TC_BF\Integrations\GravityForms\GF_Partner::prepare_post( $form_id );
		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );
		$partner_discount_pct = (float) (is_array($ctx) ? ($ctx['discount_pct'] ?? 0.0) : 0.0);
		if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0.0;

		$partner_base_total = \TC_BF\Support\Money::money_round( max(0.0, $subtotal_original - $eb_amount) );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = \TC_BF\Support\Money::money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$expected_client_total = \TC_BF\Support\Money::money_round( max(0.0, $partner_base_total - $client_discount) );

		// ---- Read posted client total (CANONICAL: field 168) ----
		// The GF form export confirms "Total client" is field 168 (used for partner notifications and UI).
		// For trustworthiness/simplicity: validate ONLY against 168 when it is present.
		$raw_168 = $_POST['input_' . self::GF_FIELD_CLIENT_TOTAL_B] ?? '';
		$posted_168 = \TC_BF\Support\Money::money_round( \TC_BF\Support\Money::money_to_float( $raw_168 ) );

		// Fallback: if 168 is not posted for some reason, fall back to legacy TOTAL (76).
		$legacy_raw = $_POST['input_' . self::GF_FIELD_TOTAL] ?? '';
		$posted_legacy = \TC_BF\Support\Money::money_round( \TC_BF\Support\Money::money_to_float( $legacy_raw ) );

		$have_168 = ($raw_168 !== '');
		$posted_total = $have_168 ? $posted_168 : $posted_legacy;
		$invalid_field_id = $have_168 ? self::GF_FIELD_CLIENT_TOTAL_B : self::GF_FIELD_TOTAL;
		$posted_raw_for_log = $have_168 ? (string) $raw_168 : (string) $legacy_raw;

		// ---- Comparisons (tolerance 0.02) ----
		$tol = 0.02;

		// 1) If the expected total is > 0 but the posted total is empty/zero, LOG but ALLOW.
		// (Changed from blocking - server will self-heal to correct total anyway)
		if ( $expected_client_total > 0 && $posted_total <= 0 ) {
			\TC_BF\Support\Logger::log('gf.validation.total_missing', [
				'event_id'              => $event_id,
				'expected_client_total' => $expected_client_total,
				'posted_raw'            => $posted_raw_for_log,
				'posted_total'          => $posted_total,
				'action'                => 'allowed_with_self_heal',
			], 'warning');
			// DO NOT BLOCK - self-heal below will fix it
		}

		// 2) Ledger parity (posted total vs expected ledger client total)
		// LOG mismatch but ALLOW submission - server ledger is authoritative
		if ( $posted_total > 0 && abs($posted_total - $expected_client_total) > $tol ) {

			\TC_BF\Support\Logger::log('gf.validation.total_mismatch', [
				'event_id'              => $event_id,
				'part_price'            => \TC_BF\Support\Money::money_round($part_price),
				'rental_price'          => \TC_BF\Support\Money::money_round($rental_price),
				'subtotal_original'     => $subtotal_original,
				'eb_base'               => $eb_base,
				'eb_amount'             => $eb_amount,
				'eb_effective_pct'      => \TC_BF\Support\Money::money_round($eb_pct_effective),
				'partner_discount_pct'  => \TC_BF\Support\Money::money_round($partner_discount_pct),
				'partner_base_total'    => $partner_base_total,
				'client_discount'       => $client_discount,
				'expected_client_total' => $expected_client_total,
				'posted_field'          => $invalid_field_id,
				'posted_raw'            => $posted_raw_for_log,
				'posted_total'          => $posted_total,
				'diff'                  => \TC_BF\Support\Money::money_round( abs($posted_total - $expected_client_total) ),
				'rental_type_raw'       => (string) $rental_raw,
				'rental_meta_key'       => (string) $meta_key,
				'action'                => 'allowed_with_self_heal',
			], 'warning');

			// DO NOT BLOCK - validation changed to log-only
			// Server ledger recalculation in cart/order is authoritative
			// This prevents UX friction from JS timing/locale/rounding differences
		}

		// 3) Self-heal: ensure correct total is in POST for downstream processing
		// This guarantees cart and order get the correct ledger-calculated value
		if ( $expected_client_total > 0 ) {
			// Write correct total to both fields for consistency
			$_POST['input_' . self::GF_FIELD_TOTAL] = wc_format_decimal($expected_client_total, 2);
			if ( $have_168 ) {
				$_POST['input_' . self::GF_FIELD_CLIENT_TOTAL_B] = wc_format_decimal($expected_client_total, 2);
			}
		}

		// Rental consistency: if any bike choice is present, require product_id + resource_id.
		$bike_raw = '';
		foreach ( [self::GF_FIELD_BIKE_130, self::GF_FIELD_BIKE_142, self::GF_FIELD_BIKE_143, self::GF_FIELD_BIKE_169] as $fid ) {
			$k = 'input_' . $fid;
			if ( ! empty($_POST[$k]) ) { $bike_raw = (string) $_POST[$k]; break; }
		}
		if ( $bike_raw !== '' ) {
			$parts = explode('_', $bike_raw);
			$pid = isset($parts[0]) ? (int) $parts[0] : 0;
			$rid = isset($parts[1]) ? (int) $parts[1] : 0;
			if ( $pid <= 0 || $rid <= 0 ) {
				$validation_result['is_valid'] = false;
				$validation_result['form'] = self::gf_mark_field_invalid($form, self::GF_FIELD_RENTAL_TYPE, __('Invalid bicycle selection. Please reselect your bicycle and try again.', 'tc-booking-flow'));
				return $validation_result;
			}
		}

		$validation_result['form'] = $form;
		return $validation_result;
	}

	/**
	 * Find a field ID by its inputName.
	 *
	 * Gravity Forms can change numeric field IDs after form duplication/rebuild/refactor,
	 * but inputName stays stable if you keep it.
	 */
	private static function find_field_id_by_input_name( $form, string $input_name, int $fallback_id ) : int {
		$input_name = trim($input_name);
		if ( $input_name === '' || ! is_array($form) || empty($form['fields']) || ! is_array($form['fields']) ) {
			return $fallback_id;
		}
		foreach ( $form['fields'] as $field ) {
			$fid = (int) (is_object($field) ? ($field->id ?? 0) : (is_array($field) ? (int)($field['id'] ?? 0) : 0));
			if ( $fid <= 0 ) continue;
			$in = '';
			if ( is_object($field) ) {
				$in = (string) ($field->inputName ?? '');
			} elseif ( is_array($field) ) {
				$in = (string) ($field['inputName'] ?? '');
			}
			if ( trim($in) === $input_name ) {
				return $fid;
			}
		}
		return $fallback_id;
	}

	/**
	 * Helper to mark a GF field as invalid with a validation message
	 *
	 * @param mixed $form The GF form array
	 * @param int $field_id The field ID to mark invalid
	 * @param string $message The validation message to display
	 * @return mixed The modified form
	 */
	private static function gf_mark_field_invalid( $form, int $field_id, string $message ) {
		if ( ! is_array($form) || empty($form['fields']) ) return $form;
		foreach ( $form['fields'] as &$field ) {
			$fid = (int) (is_object($field) ? $field->id : (is_array($field) ? ($field['id'] ?? 0) : 0));
			if ( $fid === $field_id ) {
				if ( is_object($field) ) {
					$field->failed_validation = true;
					$field->validation_message = $message;
				} elseif ( is_array($field) ) {
					$field['failed_validation'] = true;
					$field['validation_message'] = $message;
				}
				break;
			}
		}
		return $form;
	}

}
