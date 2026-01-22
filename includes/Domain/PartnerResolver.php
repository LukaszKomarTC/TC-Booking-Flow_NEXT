<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner Resolution Logic
 *
 * Priority:
 * 1) Admin override field (admin only) - form-specific field ID
 * 2) Logged-in partner user meta (discount__code)  [Way 2]
 * 3) Coupon already applied in WC session/cart      [Way 1]
 * 4) Posted manual coupon field (form-specific)    [fallback]
 *
 * Supports multiple forms with different field mappings:
 * - Form 44 (Events): fields 63 (admin override), 154 (coupon code)
 * - Form 45 (Booking Products): field 24 (admin override), 10 (coupon snapshot)
 */
final class PartnerResolver {

	/**
	 * Form-specific field mappings
	 *
	 * Each form can have different field IDs for:
	 * - admin_override: Field where admin can manually enter partner code
	 * - coupon_code: Field containing the coupon/partner code
	 */
	const FORM_FIELD_MAPS = [
		// Form 44 - Events (legacy)
		44 => [
			'admin_override' => 63,
			'coupon_code'    => 154,
		],
		// Form 45 - Booking Products (TCBF-13)
		45 => [
			'admin_override' => 24,
			'coupon_code'    => 10,
		],
	];

	// Legacy constants for backward compatibility
	const GF_PARTNER_CODE_FIELD = 63;  // Admin override partner code field (Form 44)
	const GF_FIELD_COUPON_CODE  = 154; // Manual/hidden coupon code input field (Form 44)

	/**
	 * Get field IDs for a specific form
	 *
	 * @param int $form_id GF form ID
	 * @return array Field mapping with 'admin_override' and 'coupon_code' keys
	 */
	public static function get_field_map( int $form_id ) : array {
		// Return form-specific mapping if defined
		if ( isset( self::FORM_FIELD_MAPS[ $form_id ] ) ) {
			return self::FORM_FIELD_MAPS[ $form_id ];
		}

		// Default to Form 44 mapping for backward compatibility
		return self::FORM_FIELD_MAPS[44];
	}

	public static function resolve_partner_context( int $form_id ) : array {

		$field_map = self::get_field_map( $form_id );

		// 1) Admin override wins (only if current user is admin).
		// Check form-specific field first, then fall back to legacy field
		$override_code = '';

		// Try form-specific admin override field
		$admin_field = $field_map['admin_override'];
		if ( isset( $_POST[ 'input_' . $admin_field ] ) ) {
			$override_code = trim( (string) $_POST[ 'input_' . $admin_field ] );
		}

		// Legacy fallback for Form 44 field
		if ( $override_code === '' && $admin_field !== self::GF_PARTNER_CODE_FIELD ) {
			if ( isset( $_POST[ 'input_' . self::GF_PARTNER_CODE_FIELD ] ) ) {
				$override_code = trim( (string) $_POST[ 'input_' . self::GF_PARTNER_CODE_FIELD ] );
			}
		}

		$override_code = self::normalize_partner_code( $override_code );

		if ( $override_code !== '' && current_user_can('administrator') ) {
			return self::build_partner_context_from_code( $override_code );
		}

		// 2) Logged-in partner user meta (Way 2).
		if ( is_user_logged_in() ) {
			$user_id  = get_current_user_id();
			$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );
			$code     = self::normalize_partner_code( $code_raw );

			if ( $code !== '' ) {
				return self::build_partner_context_from_code( $code, $user_id );
			}
		}

		// 3) Way 1: Coupon already applied in WC session/cart.
		$applied_code = self::get_applied_percent_coupon_code();
		if ( $applied_code !== '' ) {
			return self::build_partner_context_from_code( $applied_code );
		}

		// 4) Manual/posted coupon field (if already present).
		// Check form-specific coupon field first, then fall back to legacy field
		$posted_code = '';

		$coupon_field = $field_map['coupon_code'];
		if ( isset( $_POST[ 'input_' . $coupon_field ] ) ) {
			$posted_code = trim( (string) $_POST[ 'input_' . $coupon_field ] );
		}

		// Legacy fallback for Form 44 field
		if ( $posted_code === '' && $coupon_field !== self::GF_FIELD_COUPON_CODE ) {
			if ( isset( $_POST[ 'input_' . self::GF_FIELD_COUPON_CODE ] ) ) {
				$posted_code = trim( (string) $_POST[ 'input_' . self::GF_FIELD_COUPON_CODE ] );
			}
		}

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
	 * Way 1 support:
	 * Detect a percent coupon already applied in WC session/cart.
	 *
	 * IMPORTANT CHANGE vs previous patch:
	 * - We do NOT require a mapped partner user to treat it as "partner discount visible".
	 * - Discount visibility must work even if partner-user mapping is missing.
	 */
	private static function get_applied_percent_coupon_code() : string {
		if ( ! function_exists('WC') || ! WC() ) return '';

		$codes = [];

		// Session-level coupons (works even when cart is empty)
		try {
			if ( WC()->session ) {
				$sc = WC()->session->get('applied_coupons');
				if ( is_array($sc) ) $codes = array_merge($codes, $sc);
			}
		} catch ( \Throwable $e ) {}

		// Cart-level coupons (when cart exists)
		try {
			if ( WC()->cart ) {
				$cc = WC()->cart->get_applied_coupons();
				if ( is_array($cc) ) $codes = array_merge($codes, $cc);
			}
		} catch ( \Throwable $e ) {}

		if ( empty($codes) ) return '';

		$codes = array_values(array_unique(array_filter(array_map(function($c){
			return self::normalize_partner_code( (string) $c );
		}, $codes))));

		foreach ( $codes as $code ) {
			if ( $code === '' ) continue;
			if ( self::get_coupon_percent_amount( $code ) > 0 ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Build partner context from a coupon code.
	 * If a partner user exists, commission/email are populated.
	 */
	public static function build_partner_context_from_code( string $code, int $known_user_id = 0 ) : array {

		$code = self::normalize_partner_code( $code );
		if ( $code === '' ) return [ 'active' => false ];

		$user_id = $known_user_id;
		if ( $user_id <= 0 ) {
			$user_id = self::find_partner_user_id_by_code( $code );
		}

		$partner_email   = '';
		$commission_pct  = 0.0;

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
			$ctype  = (string) $coupon->get_discount_type();
			if ( $ctype !== 'percent' ) return 0.0;

			$amt = (float) $coupon->get_amount();
			if ( $amt < 0 ) $amt = 0.0;

			return $amt;
		} catch ( \Throwable $e ) {
			return 0.0;
		}
	}
}
