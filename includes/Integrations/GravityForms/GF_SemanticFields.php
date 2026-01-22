<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF_SemanticFields - Canonical Semantic Keys and Field Resolution
 *
 * Defines the canonical semantic keys for GF fields and provides
 * a unified resolution mechanism with legacy fallbacks.
 *
 * Resolution order:
 * 1. GF_FieldMap (inputName lookup)
 * 2. Legacy fallback (per-form hardcoded IDs) — temporary, logged
 *
 * Usage:
 *   $fid = GF_SemanticFields::field_id(44, 'coupon_code');
 *   $value = GF_SemanticFields::post_value(44, 'coupon_code');
 *   $value = GF_SemanticFields::entry_value($entry, 44, 'coupon_code');
 *
 * @since TCBF-14
 */
final class GF_SemanticFields {

	// =========================================================================
	// CANONICAL SEMANTIC KEYS
	// These must match the inputName values in your GF form templates.
	// =========================================================================

	// Partner attribution fields
	const KEY_PARTNER_OVERRIDE_CODE = 'partner_override_code';  // Admin-only partner code override
	const KEY_COUPON_CODE           = 'coupon_code';            // Partner coupon code (snapshot)
	const KEY_PARTNER_USER_ID       = 'partner_user_id';        // Partner WP user ID
	const KEY_PARTNER_DISCOUNT_PCT  = 'partner_discount_pct';   // Partner discount percentage
	const KEY_PARTNER_COMMISSION_PCT = 'partner_commission_pct'; // Partner commission percentage
	const KEY_PARTNER_EMAIL         = 'partner_email';          // Partner email address

	// Event fields (Form 44)
	const KEY_EVENT_ID              = 'event_id';               // Event post ID
	const KEY_EVENT_UID             = 'event_uid';              // Event unique identifier

	// User fields
	const KEY_USER_ROLE             = 'user_role';              // WordPress user role
	const KEY_USER_EMAIL            = 'user_email';             // User email
	const KEY_USER_NAME             = 'user_name';              // User full name

	// Ledger fields (booking products)
	const KEY_LEDGER_BASE           = 'ledger_base';            // Base price before discounts
	const KEY_LEDGER_EB_PCT         = 'ledger_eb_pct';          // Early booking discount %
	const KEY_LEDGER_EB_AMOUNT      = 'ledger_eb_amount';       // Early booking discount amount
	const KEY_LEDGER_PARTNER_AMOUNT = 'ledger_partner_amount';  // Partner discount amount
	const KEY_LEDGER_TOTAL          = 'ledger_total';           // Final total after discounts
	const KEY_LEDGER_COMMISSION     = 'ledger_commission';      // Partner commission amount

	// =========================================================================
	// LEGACY FALLBACK MAPS (temporary - to be removed once forms use inputName)
	// =========================================================================

	/**
	 * Legacy field ID fallbacks per form
	 *
	 * Format: form_id => [ semantic_key => field_id ]
	 *
	 * These are used when inputName is not set on the form.
	 * Log a warning when fallback is used to track migration progress.
	 */
	private const LEGACY_FALLBACKS = [
		// Form 44 - Events
		44 => [
			self::KEY_PARTNER_OVERRIDE_CODE  => 63,
			self::KEY_COUPON_CODE            => 154,
			self::KEY_PARTNER_USER_ID        => 166,
			self::KEY_PARTNER_DISCOUNT_PCT   => 152,
			self::KEY_PARTNER_COMMISSION_PCT => 161,
			self::KEY_PARTNER_EMAIL          => 153,
			self::KEY_EVENT_ID               => 20,
			self::KEY_EVENT_UID              => 145,
		],

		// Form 55 - Booking Products (current staging)
		// Also serves as default for configured booking form ID
		55 => [
			self::KEY_PARTNER_OVERRIDE_CODE  => 24,
			self::KEY_COUPON_CODE            => 10,
			self::KEY_USER_ROLE              => 1,
			self::KEY_USER_EMAIL             => 12,
			self::KEY_USER_NAME              => 11,
			self::KEY_LEDGER_BASE            => 15,
			self::KEY_LEDGER_EB_PCT          => 16,
			self::KEY_LEDGER_EB_AMOUNT       => 17,
			self::KEY_LEDGER_PARTNER_AMOUNT  => 18,
			self::KEY_LEDGER_TOTAL           => 20,
			self::KEY_LEDGER_COMMISSION      => 21,
		],
	];

	/**
	 * Default fallback for booking product forms (when form ID is configured in admin)
	 */
	private const BOOKING_FORM_FALLBACKS = [
		self::KEY_PARTNER_OVERRIDE_CODE  => 24,
		self::KEY_COUPON_CODE            => 10,
		self::KEY_USER_ROLE              => 1,
		self::KEY_USER_EMAIL             => 12,
		self::KEY_USER_NAME              => 11,
		self::KEY_LEDGER_BASE            => 15,
		self::KEY_LEDGER_EB_PCT          => 16,
		self::KEY_LEDGER_EB_AMOUNT       => 17,
		self::KEY_LEDGER_PARTNER_AMOUNT  => 18,
		self::KEY_LEDGER_TOTAL           => 20,
		self::KEY_LEDGER_COMMISSION      => 21,
	];

	/**
	 * Tracks logged fallbacks to avoid spam (once per request per key)
	 * @var array<string, bool>
	 */
	private static array $logged_fallbacks = [];

	// =========================================================================
	// FIELD ID RESOLUTION
	// =========================================================================

	/**
	 * Resolve field ID for a semantic key
	 *
	 * Resolution order:
	 * 1. GF_FieldMap (inputName lookup)
	 * 2. Legacy fallback (with logging)
	 *
	 * @param int    $form_id      GF form ID
	 * @param string $key          Semantic key (use KEY_* constants)
	 * @param int|null $override   Optional explicit override (skips all resolution)
	 * @return int|null Field ID or null if not resolvable
	 */
	public static function field_id( int $form_id, string $key, ?int $override = null ) : ?int {
		// Explicit override always wins
		if ( $override !== null && $override > 0 ) {
			return $override;
		}

		// 1. Try GF_FieldMap (inputName lookup)
		$map = GF_FieldMap::for_form( $form_id );
		$field_id = $map->get( $key );

		if ( $field_id !== null ) {
			return $field_id;
		}

		// 2. Legacy fallback
		$fallback_id = self::get_legacy_fallback( $form_id, $key );

		if ( $fallback_id !== null ) {
			self::log_fallback_used( $form_id, $key, $fallback_id );
			return $fallback_id;
		}

		// Not found anywhere
		self::log_not_found( $form_id, $key );
		return null;
	}

	/**
	 * Resolve field ID, returning 0 if not found (never null)
	 *
	 * @param int    $form_id GF form ID
	 * @param string $key     Semantic key
	 * @return int Field ID or 0
	 */
	public static function require_field_id( int $form_id, string $key ) : int {
		return self::field_id( $form_id, $key ) ?? 0;
	}

	// =========================================================================
	// VALUE HELPERS
	// =========================================================================

	/**
	 * Get POST value for a semantic key
	 *
	 * @param int    $form_id GF form ID
	 * @param string $key     Semantic key
	 * @return string|null Value or null if field not found
	 */
	public static function post_value( int $form_id, string $key ) : ?string {
		$field_id = self::field_id( $form_id, $key );
		if ( $field_id === null ) {
			return null;
		}

		$post_key = 'input_' . $field_id;
		if ( isset( $_POST[ $post_key ] ) ) {
			return trim( (string) $_POST[ $post_key ] );
		}

		return null;
	}

	/**
	 * Get entry value for a semantic key
	 *
	 * @param array  $entry   GF entry array
	 * @param int    $form_id GF form ID
	 * @param string $key     Semantic key
	 * @return string|null Value or null if field not found
	 */
	public static function entry_value( array $entry, int $form_id, string $key ) : ?string {
		$field_id = self::field_id( $form_id, $key );
		if ( $field_id === null ) {
			return null;
		}

		// GF entries use string keys for field IDs
		$entry_key = (string) $field_id;
		if ( isset( $entry[ $entry_key ] ) ) {
			return trim( (string) $entry[ $entry_key ] );
		}

		return null;
	}

	/**
	 * Set POST value for a semantic key
	 *
	 * @param int    $form_id GF form ID
	 * @param string $key     Semantic key
	 * @param string $value   Value to set
	 * @return bool True if field was found and value was set
	 */
	public static function set_post_value( int $form_id, string $key, string $value ) : bool {
		$field_id = self::field_id( $form_id, $key );
		if ( $field_id === null ) {
			return false;
		}

		$_POST[ 'input_' . $field_id ] = $value;
		return true;
	}

	// =========================================================================
	// LEGACY FALLBACK HELPERS
	// =========================================================================

	/**
	 * Get legacy fallback field ID for a form and key
	 *
	 * @param int    $form_id GF form ID
	 * @param string $key     Semantic key
	 * @return int|null Legacy field ID or null
	 */
	private static function get_legacy_fallback( int $form_id, string $key ) : ?int {
		// Check explicit form mapping first
		if ( isset( self::LEGACY_FALLBACKS[ $form_id ][ $key ] ) ) {
			return self::LEGACY_FALLBACKS[ $form_id ][ $key ];
		}

		// Check if this is the configured booking form
		$booking_form_id = self::get_booking_form_id();
		if ( $form_id === $booking_form_id && isset( self::BOOKING_FORM_FALLBACKS[ $key ] ) ) {
			return self::BOOKING_FORM_FALLBACKS[ $key ];
		}

		return null;
	}

	/**
	 * Get the configured booking form ID (from admin settings)
	 *
	 * @return int
	 */
	private static function get_booking_form_id() : int {
		if ( class_exists( '\\TC_BF\\Admin\\Settings' ) && method_exists( '\\TC_BF\\Admin\\Settings', 'get_booking_form_id' ) ) {
			return \TC_BF\Admin\Settings::get_booking_form_id();
		}
		return 55; // Default fallback
	}

	// =========================================================================
	// LOGGING
	// =========================================================================

	/**
	 * Log when fallback is used (once per request per form+key)
	 */
	private static function log_fallback_used( int $form_id, string $key, int $fallback_id ) : void {
		$log_key = $form_id . ':' . $key;
		if ( isset( self::$logged_fallbacks[ $log_key ] ) ) {
			return;
		}
		self::$logged_fallbacks[ $log_key ] = true;

		self::log( sprintf(
			'GF_SemanticFields: fallback used form_id=%d key=%s resolved_fid=%d source=legacy',
			$form_id,
			$key,
			$fallback_id
		) );
	}

	/**
	 * Log when key is not found anywhere
	 */
	private static function log_not_found( int $form_id, string $key ) : void {
		$log_key = $form_id . ':' . $key . ':notfound';
		if ( isset( self::$logged_fallbacks[ $log_key ] ) ) {
			return;
		}
		self::$logged_fallbacks[ $log_key ] = true;

		self::log( sprintf(
			'GF_SemanticFields: key not found form_id=%d key=%s (no inputName, no fallback)',
			$form_id,
			$key
		) );
	}

	/**
	 * Internal logging helper
	 */
	private static function log( string $msg ) : void {
		try {
			if ( class_exists( '\\TC_BF\\Admin\\Settings' ) && method_exists( '\\TC_BF\\Admin\\Settings', 'append_log' ) ) {
				\TC_BF\Admin\Settings::append_log( $msg );
			}
		} catch ( \Throwable $e ) {
			// Silent fail
		}
	}

	// =========================================================================
	// VALIDATION / DEBUG
	// =========================================================================

	/**
	 * Validate that a form has all required semantic keys
	 *
	 * @param int   $form_id       GF form ID
	 * @param array $required_keys Array of required semantic keys
	 * @return array{valid: bool, missing: array, using_fallback: array}
	 */
	public static function validate_form( int $form_id, array $required_keys ) : array {
		$map = GF_FieldMap::for_form( $form_id );
		$missing = [];
		$using_fallback = [];

		foreach ( $required_keys as $key ) {
			$from_map = $map->get( $key );

			if ( $from_map !== null ) {
				// Found via inputName - good
				continue;
			}

			$from_fallback = self::get_legacy_fallback( $form_id, $key );

			if ( $from_fallback !== null ) {
				$using_fallback[ $key ] = $from_fallback;
			} else {
				$missing[] = $key;
			}
		}

		return [
			'valid'          => empty( $missing ),
			'missing'        => $missing,
			'using_fallback' => $using_fallback,
		];
	}

	/**
	 * Get debug report for a form's semantic field resolution
	 *
	 * @param int $form_id GF form ID
	 * @return array
	 */
	public static function debug_report( int $form_id ) : array {
		$map = GF_FieldMap::for_form( $form_id );

		// All known keys
		$all_keys = [
			self::KEY_PARTNER_OVERRIDE_CODE,
			self::KEY_COUPON_CODE,
			self::KEY_PARTNER_USER_ID,
			self::KEY_PARTNER_DISCOUNT_PCT,
			self::KEY_PARTNER_COMMISSION_PCT,
			self::KEY_PARTNER_EMAIL,
			self::KEY_EVENT_ID,
			self::KEY_EVENT_UID,
			self::KEY_USER_ROLE,
			self::KEY_USER_EMAIL,
			self::KEY_USER_NAME,
			self::KEY_LEDGER_BASE,
			self::KEY_LEDGER_EB_PCT,
			self::KEY_LEDGER_EB_AMOUNT,
			self::KEY_LEDGER_PARTNER_AMOUNT,
			self::KEY_LEDGER_TOTAL,
			self::KEY_LEDGER_COMMISSION,
		];

		$resolution = [];
		foreach ( $all_keys as $key ) {
			$from_map = $map->get( $key );
			$from_fallback = self::get_legacy_fallback( $form_id, $key );

			$resolution[ $key ] = [
				'field_id' => $from_map ?? $from_fallback,
				'source'   => $from_map !== null ? 'inputName' : ( $from_fallback !== null ? 'legacy_fallback' : 'not_found' ),
			];
		}

		return [
			'form_id'        => $form_id,
			'fieldmap_valid' => $map->is_valid(),
			'fieldmap_errors'=> $map->get_errors(),
			'resolution'     => $resolution,
		];
	}
}
