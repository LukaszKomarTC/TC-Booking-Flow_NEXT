<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner Resolution Logic
 *
 * Handles partner code resolution, validation, and context building.
 * Extracted from Plugin class for better separation of concerns.
 */
final class PartnerResolver {

	// GF field IDs
	const GF_PARTNER_CODE_FIELD = 63;  // Admin override partner code field
	const GF_FIELD_COUPON_CODE  = 154; // Manual coupon code input field

	/**
	 * Priority rule:
	 * 1) Admin override field 63 (string partner code like "bondia")
	 * 2) Logged-in partner user meta (discount__code)
	 * 3) Existing posted coupon code field 154 (manual)
	 */
	public static function resolve_partner_context( int $form_id ) : array {

		$override_code = isset($_POST['input_63']) ? trim((string) $_POST['input_63']) : '';
		$override_code = self::normalize_partner_code( $override_code );

		// 1) Admin override wins (only if current user is admin).
		if ( $override_code !== '' && current_user_can('administrator') ) {
			return self::build_partner_context_from_code( $override_code );
		}

		// 2) Logged-in partner user (discount__code).
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );
			$code = self::normalize_partner_code( $code_raw );
			if ( $code !== '' ) {
				return self::build_partner_context_from_code( $code, $user_id );
			}
		}

		// 3) Manual/posted coupon field 154 (if already present).
		$posted_code = isset($_POST['input_' . self::GF_FIELD_COUPON_CODE]) ? trim((string) $_POST['input_' . self::GF_FIELD_COUPON_CODE]) : '';
		$posted_code = self::normalize_partner_code( $posted_code );
		if ( $posted_code !== '' ) {
			return self::build_partner_context_from_code( $posted_code );
		}

		// 4) WooCommerce applied coupons (session/cart) — Way 1 (coupon-on-URL).
		//    This catches external plugins that apply partner coupons via URL/QR parameters.
		if ( function_exists('WC') && ( WC()->cart || WC()->session ) ) {
			$applied_codes = [];

			// Try cart first (most reliable source of truth)
			if ( WC()->cart ) {
				$applied_codes = WC()->cart->get_applied_coupons();
			}

			// Fallback to session if cart empty/unavailable
			if ( empty($applied_codes) && WC()->session ) {
				$applied_codes = WC()->session->get('applied_coupons', []);
			}

			if ( ! empty($applied_codes) && is_array($applied_codes) ) {
				// Normalize all codes for comparison
				$applied_codes = array_map( [ __CLASS__, 'normalize_partner_code' ], $applied_codes );

				// Find first partner coupon (percent type + has partner user)
				foreach ( $applied_codes as $code ) {
					if ( $code === '' ) continue;

					// Check if this is a partner coupon
					$partner_user_id = self::find_partner_user_id_by_code( $code );
					$discount_pct = self::get_coupon_percent_amount( $code );

					// Valid partner coupon = has partner user OR has percent discount
					if ( $partner_user_id > 0 || $discount_pct > 0 ) {
						return self::build_partner_context_from_code( $code, $partner_user_id );
					}
				}
			}
		}

		return [ 'active' => false ];
	}

	public static function normalize_partner_code( string $code ) : string {
		$code = trim($code);
		if ( $code === '' ) return '';
		if ( function_exists('wc_format_coupon_code') ) {
			$code = wc_format_coupon_code( $code );
		}
		return $code;
	}

	/**
	 * Build partner context from a partner code (coupon code).
	 */
	public static function build_partner_context_from_code( string $code, int $known_user_id = 0 ) : array {

		$code = self::normalize_partner_code( $code );
		if ( $code === '' ) return [ 'active' => false ];

		$user_id = $known_user_id;
		if ( $user_id <= 0 ) {
			$user_id = self::find_partner_user_id_by_code( $code );
		}

		$partner_email = '';
		$commission_pct = 0.0;

		if ( $user_id > 0 ) {
			$user = get_user_by('id', $user_id);
			if ( $user && ! is_wp_error($user) ) {
				$partner_email = (string) $user->user_email;
			}
			$commission_pct = (float) get_user_meta( $user_id, 'usrdiscount', true );
			if ( $commission_pct < 0 ) $commission_pct = 0.0;
		}

		$discount_pct = self::get_coupon_percent_amount( $code );

		return [
			'active'          => ($discount_pct > 0 || $commission_pct > 0 || $user_id > 0),
			'code'            => $code,
			'discount_pct'    => $discount_pct,
			'commission_pct'  => $commission_pct,
			'partner_email'   => $partner_email,
			'partner_user_id' => $user_id,
		];
	}

	public static function find_partner_user_id_by_code( string $code ) : int {
		$code = self::normalize_partner_code( $code );
		if ( $code === '' ) return 0;

		$uq = new \WP_User_Query([
			'number'     => 1,
			'fields'     => 'ID',
			'meta_query' => [
				[
					'key'     => 'discount__code',
					'value'   => $code,
					'compare' => '='
				]
			]
		]);
		$ids = $uq->get_results();
		if ( is_array($ids) && ! empty($ids) ) return (int) $ids[0];
		return 0;
	}

	public static function get_coupon_percent_amount( string $code ) : float {
		$code = self::normalize_partner_code( $code );
		if ( $code === '' ) return 0.0;
		if ( ! class_exists('WC_Coupon') ) return 0.0;

		try {
			$coupon = new \WC_Coupon( $code );
			$ctype = (string) $coupon->get_discount_type();
			if ( $ctype !== 'percent' ) return 0.0;
			$amt = (float) $coupon->get_amount();
			if ( $amt < 0 ) $amt = 0.0;
			return $amt;
		} catch ( \Throwable $e ) {
			return 0.0;
		}
	}
}
