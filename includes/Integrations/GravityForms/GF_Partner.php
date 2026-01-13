<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms Partner Integration
 *
 * Populates partner-related hidden fields into $_POST early enough for:
 * - GF conditional logic (show/hide partner discount rows)
 * - GF calculation fields (percent values)
 *
 * Supports:
 * - Way 1: coupon already applied in WC session/cart (partner URL flow)
 * - Way 2: logged-in partner via user meta discount__code
 * - Way 3: admin override via GF field 63 (handled in PartnerResolver)
 */
final class GF_Partner {

	// Partner-related GF fields (must match your form)
	const GF_FIELD_COUPON_CODE = 154;

	public static function prepare_post( int $form_id ) : void {

		$event_id = self::resolve_event_id_from_request( $form_id );

		// TCBF-12 gate: if partners are disabled for this event, force-clear.
		if ( $event_id > 0 && class_exists('TC_BF\\Domain\\EventMeta') ) {
			try {
				if ( ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
					self::clear_partner_fields();
					self::dbg('GF_Partner: partners disabled for event_id=' . $event_id);
					return;
				}
			} catch ( \Throwable $e ) {
				// Fail open. Never break booking.
			}
		}

		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );

		if ( empty($ctx) || empty($ctx['active']) ) {
			self::clear_partner_fields();
			self::dbg('GF_Partner: no active partner ctx (event_id=' . (int)$event_id . ')');
			return;
		}

		// Write values deterministically.
		$_POST['input_' . self::GF_FIELD_COUPON_CODE] = (string) ($ctx['code'] ?? '');

		// IMPORTANT: feed percent values as decimal-comma to avoid GF interpreting "7.5" as "75".
		$_POST['input_152'] = (string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['discount_pct'] ?? 0) );
		$_POST['input_161'] = (string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['commission_pct'] ?? 0) );
		$_POST['input_153'] = (string) ($ctx['partner_email'] ?? '');
		$_POST['input_166'] = (string) ((int) ($ctx['partner_user_id'] ?? 0));

		self::dbg('GF_Partner: applied ctx code=' . (string)($_POST['input_' . self::GF_FIELD_COUPON_CODE] ?? ''));
	}

	private static function clear_partner_fields() : void {
		$_POST['input_' . self::GF_FIELD_COUPON_CODE] = '';
		$_POST['input_152'] = '';
		$_POST['input_161'] = '';
		$_POST['input_153'] = '';
		$_POST['input_166'] = '';
	}

	/**
	 * Robust event_id resolution (must NOT hardcode field id).
	 * Uses same approach as GF_Validation: inputName=event_id.
	 */
	private static function resolve_event_id_from_request( int $form_id ) : int {

		// Best: use GFAPI form metadata to locate the field by inputName.
		if ( class_exists('\\GFAPI') && $form_id > 0 ) {
			try {
				$form = \GFAPI::get_form( $form_id );
				$field_id = self::find_field_id_by_input_name($form, 'event_id', 20);

				if ( $field_id > 0 ) {
					$key = 'input_' . $field_id;
					if ( isset($_POST[$key]) ) {
						$eid = (int) $_POST[$key];
						if ( $eid > 0 ) return $eid;
					}
				}
			} catch ( \Throwable $e ) {}
		}

		// Fallbacks (keep existing compatibility)
		if ( isset($_POST['input_20']) ) return (int) $_POST['input_20'];
		if ( isset($_GET['event_id']) ) return (int) $_GET['event_id'];
		if ( isset($_GET['event']) ) return (int) $_GET['event'];

		return 0;
	}

	private static function find_field_id_by_input_name( $form, string $input_name, int $fallback ) : int {
		try {
			if ( is_array($form) && isset($form['fields']) && is_array($form['fields']) ) {
				foreach ( $form['fields'] as $field ) {
					$iname = '';
					$fid   = 0;

					if ( is_object($field) ) {
						$iname = isset($field->inputName) ? (string) $field->inputName : '';
						$fid   = isset($field->id) ? (int) $field->id : 0;
					} elseif ( is_array($field) ) {
						$iname = isset($field['inputName']) ? (string) $field['inputName'] : '';
						$fid   = isset($field['id']) ? (int) $field['id'] : 0;
					}

					if ( $iname === $input_name && $fid > 0 ) {
						return $fid;
					}
				}
			}
		} catch ( \Throwable $e ) {}

		return $fallback;
	}

	/**
	 * Debug helper (server-only). Never breaks if Settings logger changes.
	 */
	private static function dbg( string $msg ) : void {
		try {
			if ( class_exists('\\TC_BF\\Admin\\Settings') && method_exists('\\TC_BF\\Admin\\Settings', 'append_log') ) {
				\TC_BF\Admin\Settings::append_log((string) $msg);
			}
		} catch ( \Throwable $e ) {}
	}
}
