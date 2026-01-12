<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms Partner Integration
 *
 * Handles partner code resolution and POST preparation for GF forms.
 * Extracted from Plugin class for better separation of concerns.
 */
final class GF_Partner {

	// GF field IDs for partner-related fields
	const GF_FIELD_COUPON_CODE = 154;

	/**
	 * Server-side: resolve partner context and write hidden inputs into POST.
	 * This is required for GF calculation fields (152/154/161/153/166) to compute before submit.
	 *
	 * NOTE: This runs on gform_pre_render (see GF_JS::partner_prepare_form()).
	 */
	public static function prepare_post( int $form_id ) : void {

		// Resolve event_id as early as possible (GF48 field 20 has inputName=event_id).
		$event_id = 0;
		if ( isset($_POST['input_20']) ) {
			$event_id = (int) $_POST['input_20'];
		} elseif ( isset($_GET['event_id']) ) {
			$event_id = (int) $_GET['event_id'];
		} elseif ( isset($_GET['event']) ) {
			$event_id = (int) $_GET['event'];
		}

		// TCBF-12 gate: if partner program disabled for this event, wipe partner fields and exit.
		if ( $event_id > 0 && class_exists('TC_BF\\Domain\\EventMeta') ) {
			try {
				if ( ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
					self::clear_partner_fields();
					return;
				}
			} catch ( \Throwable $e ) {
				// Fail open (do not break booking).
			}
		}

		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );
		if ( empty($ctx) || empty($ctx['active']) ) {
			self::clear_partner_fields();
			return;
		}

		// Write values deterministically.
		$_POST['input_' . self::GF_FIELD_COUPON_CODE] = (string) ($ctx['code'] ?? '');
		// IMPORTANT: feed percent values as decimal-comma to avoid GF interpreting "7.5" as "75".
		$_POST['input_152'] = (string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['discount_pct'] ?? 0) );
		$_POST['input_161'] = (string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['commission_pct'] ?? 0) );
		$_POST['input_153'] = (string) ($ctx['partner_email'] ?? '');
		$_POST['input_166'] = (string) ((int) ($ctx['partner_user_id'] ?? 0));
	}

	private static function clear_partner_fields() : void {
		$_POST['input_' . self::GF_FIELD_COUPON_CODE] = '';
		$_POST['input_152'] = '';
		$_POST['input_161'] = '';
		$_POST['input_153'] = '';
		$_POST['input_166'] = '';
	}
}
