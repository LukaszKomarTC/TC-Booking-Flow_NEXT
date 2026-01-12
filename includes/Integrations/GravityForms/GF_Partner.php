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
	 * @param int $form_id The Gravity Forms form ID
	 * @return void
	 */
	public static function prepare_post( int $form_id ) : void {

		// TCBF-12: event-level partner program gate
		$event_id = isset($_POST['input_20']) ? (int) $_POST['input_20'] : 0;
		if ( $event_id > 0 && ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
			// Clear fields to avoid stale partner values.
			$_POST['input_' . self::GF_FIELD_COUPON_CODE] = '';
			$_POST['input_152'] = '';
			$_POST['input_161'] = '';
			$_POST['input_153'] = '';
			$_POST['input_166'] = '';
			return;
		}


		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );
		if ( empty($ctx) || empty($ctx['active']) ) {
			// Clear fields to avoid stale partner values.
			$_POST['input_' . self::GF_FIELD_COUPON_CODE] = '';
			$_POST['input_152'] = '';
			$_POST['input_161'] = '';
			$_POST['input_153'] = '';
			$_POST['input_166'] = '';
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

}
