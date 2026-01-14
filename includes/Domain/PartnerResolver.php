<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Partner Resolution Logic
 *
 * Priority:
 * 1) Admin override field 63 (admin only)
 * 2) Logged-in partner user meta (discount__code)  [Way 2]
 * 3) Coupon already applied in WC session/cart      [Way 1]
 * 4) Posted manual coupon field 154                [fallback]
 */
final class PartnerResolver {

	// GF field IDs
	const GF_PARTNER_CODE_FIELD = 63;  // Admin override partner code field
	const GF_FIELD_COUPON_CODE  = 154; // Manual/hidden coupon code input field

	public static function resolve_partner_context( int $form_id ) : array {

		// 1) Admin override wins (only if current user is admin).
		$override_code = isset($_POST['input_' . self::GF_PARTNER_CODE_FIELD]) ? trim((string) $_POST['input_' . self::GF_PARTNER_CODE_FIELD]) : '';
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
		$posted_code = isset($_POST['input_' . self::GF_FIELD_COUPON_CODE]) ? trim((string) $_POST['input_' . self::GF_FIELD_COUPON_CODE]) : '';
		$posted_code = self::normalize_partner_code( $posted_code );

		if ( $posted_code !== '' ) {
			return self::build_partner_context_from_code( $posted_code );
		}

		// 5) WooCommerce applied coupons (session/cart) — Way 1 (coupon-on-URL).
		//    This catches external plugins that apply partner coupons via URL/QR parameters.
		//
		// CRITICAL HARDENING:
		// This resolver can be invoked while rendering Sugar Calendar pages (non-shop routes).
		// On those routes WooCommerce often exists but session/cart are not initialized yet.
		// We must never trigger WC session/cart bootstrapping or call methods on null.
		if ( self::is_woo_context_safe_for_coupons() ) {
			$wc = WC();
			$applied_codes = [];

			// Try cart first (most reliable source of truth).
			try {
				if ( isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'get_applied_coupons' ) ) {
					$applied_codes = (array) $wc->cart->get_applied_coupons();
				}
			} catch ( \Throwable $e ) {
				$applied_codes = [];
			}

			// Fallback to session if cart empty/unavailable.
			try {
				if ( empty( $applied_codes ) && isset( $wc->session ) && is_object( $wc->session ) && method_exists( $wc->session, 'get' ) ) {
					$maybe = $wc->session->get( 'applied_coupons', [] );
					if ( is_array( $maybe ) ) {
						$applied_codes = $maybe;
					}
				}
			} catch ( \Throwable $e ) {
				// ignore
			}

			if ( ! empty( $applied_codes ) && is_array( $applied_codes ) ) {
				// Normalize all codes for comparison.
				$applied_codes = array_map( [ __CLASS__, 'normalize_partner_code' ], $applied_codes );

				// Find first partner coupon (percent type + has partner user).
				foreach ( $applied_codes as $code ) {
					if ( $code === '' ) continue;

					$partner_user_id = self::find_partner_user_id_by_code( $code );
					$discount_pct    = self::get_coupon_percent_amount( $code );

					// Valid partner coupon = has partner user OR has percent discount.
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
		if ( ! self::is_woo_context_safe_for_coupons() ) {
			return '';
		}

		$wc = WC();

		$codes = [];

		// Session-level coupons (works even when cart is empty)
		try {
			if ( isset( $wc->session ) && is_object( $wc->session ) && method_exists( $wc->session, 'get' ) ) {
				$sc = $wc->session->get('applied_coupons');
				if ( is_array($sc) ) $codes = array_merge($codes, $sc);
			}
		} catch ( \Throwable $e ) {}

		// Cart-level coupons (when cart exists)
		try {
			if ( isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'get_applied_coupons' ) ) {
				$cc = $wc->cart->get_applied_coupons();
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
	 * Defensive gate: only touch WC session/cart when Woo is initialized enough.
	 *
	 * Why:
	 * - This resolver is used on Sugar Calendar routes (event pages/lists).
	 * - On non-shop routes, WooCommerce can be loaded but cart/session may be null.
	 * - Accessing WC()->session or WC()->cart too early can fatally break rendering.
	 *
	 * Policy:
	 * - Fail closed (return false) if unsure.
	 */
	private static function is_woo_context_safe_for_coupons() : bool {
		if ( ! function_exists( 'WC' ) ) return false;
		$wc = WC();
		if ( ! $wc ) return false;

		// Ensure Woo has had a chance to initialize its frontend objects.
		// wp_loaded is late enough for most setups; woocommerce_init is a good signal too.
		if ( ! did_action( 'wp_loaded' ) && ! did_action( 'woocommerce_init' ) ) {
			return false;
		}

		// Session/cart may still be absent on some routes; require at least one usable object.
		$has_cart = isset( $wc->cart ) && is_object( $wc->cart ) && method_exists( $wc->cart, 'get_applied_coupons' );
		$has_sess = isset( $wc->session ) && is_object( $wc->session ) && method_exists( $wc->session, 'get' );
		return ( $has_cart || $has_sess );
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
