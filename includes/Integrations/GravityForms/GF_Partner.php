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
 * - Way 3: admin override via GF field (handled in PartnerResolver)
 *
 * Field IDs are resolved via GF_SemanticFields (inputName lookup with legacy fallback).
 *
 * @see GF_SemanticFields for semantic key definitions
 */
final class GF_Partner {

	public static function prepare_post( int $form_id ) : void {

		$event_id = self::resolve_event_id_from_request( $form_id );

		// TCBF-12 gate: if partners are disabled for this event, force-clear.
		if ( $event_id > 0 && class_exists('TC_BF\\Domain\\EventMeta') ) {
			try {
				if ( ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
					self::clear_partner_fields( $form_id );
					self::dbg('GF_Partner: partners disabled for event_id=' . $event_id);
					return;
				}
			} catch ( \Throwable $e ) {
				// Fail open. Never break booking.
			}
		}

		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );

		if ( empty($ctx) || empty($ctx['active']) ) {
			self::clear_partner_fields( $form_id );
			self::dbg('GF_Partner: no active partner ctx (event_id=' . (int)$event_id . ')');
			return;
		}

		// Write values deterministically using semantic field resolution
		$partner_code = (string) ($ctx['code'] ?? '');
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_COUPON_CODE, $partner_code );

		// CRITICAL: Also populate partner_coupon_code (field 26 in booking form)
		// Field 33 (display_partner_discount) conditional logic depends on this field!
		// Without this, partner discount display stays hidden even when partner is active.
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_COUPON_CODE, $partner_code );

		// IMPORTANT: feed percent values as decimal-comma to avoid GF interpreting "7.5" as "75".
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_DISCOUNT_PCT,
			(string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['discount_pct'] ?? 0) ) );

		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_COMMISSION_PCT,
			(string) \TC_BF\Support\Money::pct_to_gf_str( (float) ($ctx['commission_pct'] ?? 0) ) );

		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_EMAIL,
			(string) ($ctx['partner_email'] ?? '') );

		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_USER_ID,
			(string) ((int) ($ctx['partner_user_id'] ?? 0)) );

		$code = GF_SemanticFields::post_value( $form_id, GF_SemanticFields::KEY_COUPON_CODE );
		self::dbg('GF_Partner: applied ctx code=' . (string) $code);
	}

	/**
	 * Clear partner fields using semantic resolution
	 */
	private static function clear_partner_fields( int $form_id ) : void {
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_COUPON_CODE, '' );
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_COUPON_CODE, '' );
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_DISCOUNT_PCT, '' );
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_COMMISSION_PCT, '' );
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_EMAIL, '' );
		GF_SemanticFields::set_post_value( $form_id, GF_SemanticFields::KEY_PARTNER_USER_ID, '' );
	}

	/**
	 * Robust event_id resolution using GF_SemanticFields
	 */
	private static function resolve_event_id_from_request( int $form_id ) : int {

		// Use GF_SemanticFields for event_id resolution
		$event_id_value = GF_SemanticFields::post_value( $form_id, GF_SemanticFields::KEY_EVENT_ID );
		if ( $event_id_value !== null ) {
			$eid = (int) $event_id_value;
			if ( $eid > 0 ) return $eid;
		}

		// Fallbacks (keep existing compatibility)
		if ( isset($_GET['event_id']) ) return (int) $_GET['event_id'];
		if ( isset($_GET['event']) ) return (int) $_GET['event'];

		return 0;
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
