<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

// Load extracted classes
require_once TC_BF_PATH . 'includes/Support/Money.php';
require_once TC_BF_PATH . 'includes/Support/Logger.php';
require_once TC_BF_PATH . 'includes/Domain/EventConfig.php';
require_once TC_BF_PATH . 'includes/Domain/Ledger.php';
require_once TC_BF_PATH . 'includes/Domain/PartnerResolver.php';
require_once TC_BF_PATH . 'includes/Domain/Entry_State.php';
require_once TC_BF_PATH . 'includes/Domain/Entry_Expiry_Job.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_Partner.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_Validation.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_Discount_Rounding.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_JS.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_View_Filters.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo_OrderMeta.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo_Notifications.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Pack_Grouping.php';

/**
 * TC Booking Flow Plugin Main Class (Orchestrator)
 *
 * This class serves as a thin orchestrator that:
 * - Manages singleton instance
 * - Registers WordPress hooks
 * - Delegates most logic to specialized static classes
 * - Contains only methods that require instance state or haven't been extracted
 */
final class Plugin {

	private static $instance = null;

	const GF_FORM_ID = 44;

	// GF field IDs used in your form 44 export
	const GF_FIELD_EVENT_ID      = 20;
	const GF_FIELD_EVENT_TITLE   = 1;
	const GF_FIELD_TOTAL         = 76;
	// Client-facing totals (newer form variants)
	// Field 79 should match field 168 and match the cart/order client total (includes EB + partner discount)
	const GF_FIELD_CLIENT_TOTAL_A = 79;
	const GF_FIELD_CLIENT_TOTAL_B = 168;
	const GF_FIELD_START_RAW     = 132;
	const GF_FIELD_END_RAW       = 134;

	// Coupon / partner code (your form uses field 154 as "partner code" input)
	const GF_FIELD_COUPON_CODE   = 154;

	// EB hidden field is 172 with inputName early_booking_discount_pct (dynamic population)
	const GF_FIELD_EB_PCT        = 172;

	// Optional "bicycle choice" concat fields in your snippet
	const GF_FIELD_BIKE_130      = 130;
	const GF_FIELD_BIKE_142      = 142;
	const GF_FIELD_BIKE_143      = 143;
	const GF_FIELD_BIKE_169      = 169;

	// Optional participant fields used in your snippet (keep compatible)
	const GF_FIELD_FIRST_NAME    = 9;
	const GF_FIELD_LAST_NAME     = 10;

	// Optional rental type select field (used in your validation snippet)
	// Values like: ROAD / MTB / eMTB / GRAVEL (sometimes prefixed by labels)
	const GF_FIELD_RENTAL_TYPE   = 106;

	// Per-event config meta
	const META_EB_ENABLED                = 'tc_ebd_enabled';
	const META_EB_RULES_JSON             = 'tc_ebd_rules_json';
	const META_EB_CAP                    = 'tc_ebd_cap';
	const META_EB_PARTICIPATION_ENABLED  = 'tc_ebd_participation_enabled';
	const META_EB_RENTAL_ENABLED         = 'tc_ebd_rental_enabled';

	// Booking meta keys stored on cart items
	const BK_EVENT_ID      = '_event_id';
	const BK_EVENT_TITLE   = '_event_title';
	const BK_ENTRY_ID      = '_entry_id';
	const BK_CUSTOM_COST   = '_custom_cost';

	const BK_SCOPE         = '_tc_scope';          // 'participation' | 'rental'
	const BK_EB_PCT        = '_eb_pct';            // snapshot pct
	const BK_EB_AMOUNT     = '_eb_amount';         // snapshot discount amount per line (per unit)
	const BK_EB_ELIGIBLE   = '_eb_eligible';       // 0/1
	const BK_EB_DAYS       = '_eb_days_before';    // snapshot days
	const BK_EB_BASE       = '_eb_base_price';     // snapshot base (per line, before EB)
	const BK_EB_EVENT_TS   = '_eb_event_start_ts'; // snapshot for audit

	// GF entry meta keys (dedupe)
	const GF_META_CART_ADDED = 'tc_cart_added';

	// Request cache
	private $calc_cache = [];

	public static function instance() : self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() { $this->hooks(); }

	private function hooks() : void {

		// Admin: product meta field
		if ( is_admin() ) {
			\TC_BF\Admin\Product_Meta::init();
			\TC_BF\Admin\Settings::init();
			\TC_BF\Admin\Partners::init();
		}

		// ---- GF: dynamic EB% population (field 172)
		add_filter('gform_field_value_early_booking_discount_pct', [ $this, 'gf_populate_eb_pct' ]);

		// ---- GF: server-side validation (tamper-proof + self-heal)
		add_filter('gform_validation', [ $this, 'gf_validation' ], 10, 1);


		// ---- GF: partner + admin override wiring (populate hidden fields + inject JS)
		add_filter('gform_pre_render',             [ $this, 'gf_partner_prepare_form' ], 10, 1);
		add_filter('gform_pre_validation',         [ $this, 'gf_partner_prepare_form' ], 10, 1);
		// Ensure server-side calculation parity (GF entry values match UI/cart)
		if ( class_exists('\\TC_BF\\Integrations\\GravityForms\\GF_Discount_Rounding') ) {
			$rounding = new \TC_BF\Integrations\GravityForms\GF_Discount_Rounding();
			$rounding->init();
		}
		// GravityView filters: show only paid participants
		if ( class_exists('\\TC_BF\\Integrations\\GravityForms\\GF_View_Filters') ) {
			\TC_BF\Integrations\GravityForms\GF_View_Filters::init();
		}

		add_filter('gform_pre_submission_filter',  [ $this, 'gf_partner_prepare_form' ], 10, 1);
		add_action('wp_head',                      [ $this, 'output_form_field_css' ], 100); // CSS for enhanced fields
		add_action('wp_footer',                    [ $this, 'output_early_diagnostic' ], 5); // Early diagnostic
		add_action('wp_footer',                    [ $this, 'gf_output_partner_js' ], 100);
		add_action('wp_footer',                    [ $this, 'output_late_diagnostic' ], 200); // Late diagnostic


		// ---- GF: submission to cart (single source of truth)
		add_action('gform_after_submission', [ $this, 'gf_after_submission_add_to_cart' ], 10, 2);

		// ---- Woo Bookings: override booking cost when we pass _custom_cost (existing behavior)
		add_filter('woocommerce_bookings_calculated_booking_cost', [ $this, 'woo_override_booking_cost' ], 11, 3);

		// ---- EB: apply snapshot EB to eligible booking cart items
		add_action('woocommerce_before_calculate_totals', [ $this, 'woo_apply_eb_snapshot_to_cart' ], 20, 1);

		// ---- Cart display: show booking meta to the customer
		add_filter('woocommerce_get_item_data', [ $this, 'woo_cart_item_data' ], 20, 2);

		// ---- Cart display: hide WooCommerce Bookings meta fields we don't want to show
		add_filter('woocommerce_order_item_display_meta_key', [ $this, 'woo_filter_cart_meta_labels' ], 10, 3);

		// ---- Cart display: show EB discount badge after item name
		add_action('woocommerce_after_cart_item_name', [ $this, 'woo_cart_item_eb_badge' ], 10, 2);
		add_action('woocommerce_after_mini_cart_item_name', [ $this, 'woo_cart_item_eb_badge' ], 10, 2);

		// ---- Cart display: add "Included in pack" badge to product title
		add_filter('woocommerce_cart_item_name', [ $this, 'woo_add_pack_badge_to_title' ], 10, 3);

		// ---- Cart display: add pack grouping classes and data attributes to cart items
		add_filter('woocommerce_cart_item_class', [ $this, 'woo_add_pack_classes_to_cart_item' ], 10, 3);

		// ---- Cart display: output pack grouping JavaScript
		add_action('wp_footer', [ $this, 'output_pack_grouping_js' ], 50);

		// ---- Pack Grouping: atomic cart behavior for participation + rental
		if ( class_exists('\\TC_BF\\Integrations\\WooCommerce\\Pack_Grouping') ) {
			\TC_BF\Integrations\WooCommerce\Pack_Grouping::init();
		}

		// ---- Entry Expiry Job: scheduled cron to expire abandoned carts
		if ( class_exists('\\TC_BF\\Domain\\Entry_Expiry_Job') ) {
			\TC_BF\Domain\Entry_Expiry_Job::init();
		}

		// ---- Order item meta: persist booking meta to line items (your pasted snippet)
		add_action('woocommerce_checkout_create_order_line_item', [ $this, 'woo_checkout_create_order_line_item' ], 20, 4);

		// ---- Partner order meta: persist partner accounting meta (kept, but base uses snapshot if present)
		add_action('woocommerce_checkout_create_order', [ $this, 'partner_persist_order_meta' ], 25, 2);

		// ---- Ledger: write EB + partner ledger on order (snapshot-driven)
		add_action('woocommerce_checkout_order_processed', [ $this, 'eb_write_order_ledger' ], 40, 3);

		// ---- Entry State: set checkout guard when order is being created
		add_action('woocommerce_checkout_order_processed', [ $this, 'entry_state_set_checkout_guard' ], 5, 3);

		// ---- Entry State: mark entries as paid when payment succeeds
		add_action('woocommerce_payment_complete', [ $this, 'entry_state_mark_paid' ], 25, 1);
		add_action('woocommerce_order_status_processing', [ $this, 'entry_state_mark_paid' ], 25, 2);
		add_action('woocommerce_order_status_completed', [ $this, 'entry_state_mark_paid' ], 25, 2);

		// ---- Entry State: handle failed/cancelled/refunded orders
		add_action('woocommerce_order_status_failed', [ $this, 'entry_state_mark_payment_failed' ], 10, 2);
		add_action('woocommerce_order_status_cancelled', [ $this, 'entry_state_mark_cancelled' ], 10, 2);
		add_action('woocommerce_order_status_refunded', [ $this, 'entry_state_mark_refunded' ], 10, 2);

		// ---- GF notifications: custom event + fire on successful payment (parity with legacy snippets)
		add_filter('gform_notification_events', [ $this, 'gf_register_notification_events' ], 10, 1);
		add_action('woocommerce_payment_complete', [ $this, 'woo_fire_gf_paid_notifications' ], 20, 1);
		// Fallbacks for gateways / edge flows where payment_complete isn't triggered as expected
		add_action('woocommerce_order_status_processing', [ $this, 'woo_fire_gf_paid_notifications' ], 20, 2);
		add_action('woocommerce_order_status_completed',  [ $this, 'woo_fire_gf_paid_notifications' ], 20, 2);

		add_action('woocommerce_order_status_invoiced',   [ $this, 'woo_fire_gf_settled_notifications' ], 20, 2);
		// ---- Partner coupon: auto-apply partner coupon for logged-in partners (legacy parity)
		// Run late on wp_loaded so WC()->cart is available.
		add_action('wp_loaded', [ $this, 'maybe_auto_apply_partner_coupon' ], 30);

		// TCBF-12: block partner coupons when any event disables partner program (strict mode)
		add_filter('woocommerce_coupon_is_valid', [ $this, 'woo_validate_partner_coupon' ], 20, 3);
		add_action('woocommerce_before_calculate_totals', [ $this, 'woo_maybe_remove_partner_coupons' ], 5, 1);

	}

	/* =========================================================
	 * Methods that remain in Plugin (not extracted)
	 * ========================================================= */

	/**
	 * Auto-apply partner coupon for logged-in users who have a discount__code set.
	 *
	 * Notes:
	 * - Woo coupon codes are effectively case-insensitive, but we normalize with wc_format_coupon_code().
	 * - We only auto-apply when there is at least one TC Booking Flow cart item.
	 */
	public function maybe_auto_apply_partner_coupon() : void {
		// Frontend only
		if ( is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) ) return;
		if ( ! is_user_logged_in() ) return;
			if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return;
		$cart = WC()->cart;
		if ( $cart->is_empty() ) return;

		// Only apply if cart contains at least one TC Booking Flow item.
		$has_tc_item = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset($cart_item['booking']) && is_array($cart_item['booking']) && ! empty($cart_item['booking'][self::BK_EVENT_ID]) ) {
				$has_tc_item = true;
				break;
			}
		}
		if ( ! $has_tc_item ) return;

		// TCBF-12: strict mode — if ANY event in cart has partners disabled, do not auto-apply partner coupon.
		if ( ! $this->cart_allows_partner_program( $cart ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );
		$code_raw = trim($code_raw);
		if ( $code_raw === '' ) return;

		$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);
		if ( $code === '' ) return;

		// Already applied?
		$applied = array_map('wc_format_coupon_code', (array) $cart->get_applied_coupons());
		if ( in_array($code, $applied, true) ) return;

		// Coupon exists?
		if ( ! function_exists('wc_get_coupon_id_by_code') ) return;
		$coupon_id = (int) wc_get_coupon_id_by_code( $code );
		if ( $coupon_id <= 0 ) {
			// Some stores save post_title in uppercase; try raw too.
			$coupon_id = (int) wc_get_coupon_id_by_code( $code_raw );
		}
		if ( $coupon_id <= 0 ) {
			$this->log('partner.coupon.auto_apply.missing', ['user_id'=>$user_id,'code_raw'=>$code_raw,'code_norm'=>$code]);
			return;
		}

		$ok = $cart->add_discount( $code );
		$this->log('partner.coupon.auto_apply', ['user_id'=>$user_id,'code'=>$code,'ok'=>$ok ? 1 : 0]);
	}

	
	/**
	 * TCBF-12 (Strict Mode)
	 * Return true only if every TC event item in cart has partner program enabled.
	 */
	private function cart_allows_partner_program( $cart ) : bool {
		if ( ! $cart || ! is_a( $cart, 'WC_Cart' ) ) return true;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset($cart_item['booking']) || ! is_array($cart_item['booking']) ) continue;
			$event_id = isset($cart_item['booking'][self::BK_EVENT_ID]) ? (int) $cart_item['booking'][self::BK_EVENT_ID] : 0;
			if ( $event_id <= 0 ) continue;
			if ( ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Detect if a coupon code is a "partner coupon" (linked to any user meta discount__code).
	 * Cached per-request to avoid repeated DB hits.
	 */
	private function is_partner_coupon_code( string $code ) : bool {
		$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code) : strtolower($code);
		if ( $code === '' ) return false;

		static $cache = [];
		if ( array_key_exists( $code, $cache ) ) return (bool) $cache[$code];

		$users = get_users([
			'meta_key'   => 'discount__code',
			'meta_value' => $code,
			'number'     => 1,
			'fields'     => 'ids',
		]);

		$cache[$code] = ! empty($users[0]);
		return (bool) $cache[$code];
	}

	/**
	 * Block partner coupons if the cart contains any TC event with partners disabled.
	 * (Woo applies coupons cart-wide, so we must prevent leakage.)
	 */
	public function woo_validate_partner_coupon( $valid, $coupon, $discount ) {
		if ( ! $valid ) return $valid;
		if ( is_admin() && ! wp_doing_ajax() ) return $valid;
		if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return $valid;

		$cart = WC()->cart;
		if ( $cart->is_empty() ) return $valid;

		// Only care if the coupon is a partner coupon.
		$code = is_object($coupon) && method_exists($coupon,'get_code') ? (string) $coupon->get_code() : '';
		if ( $code === '' || ! $this->is_partner_coupon_code( $code ) ) return $valid;

		// If cart has any TC event with partners disabled, reject partner coupon.
		if ( ! $this->cart_allows_partner_program( $cart ) ) {
			if ( is_object($coupon) && method_exists($coupon,'add_coupon_message') ) {
				// no-op; coupon object doesn't expose messages consistently across versions
			}
			$this->log('partner.coupon.blocked_by_event_toggle', ['code'=>$code]);
			return false;
		}

		return $valid;
	}

	/**
	 * Ensure partner coupons are removed if cart contains any event with partners disabled.
	 * Runs before totals calculation.
	 */
	public function woo_maybe_remove_partner_coupons( $cart ) : void {
		if ( is_admin() && ! wp_doing_ajax() ) return;
		if ( ! $cart || ! is_a($cart, 'WC_Cart') ) return;
		if ( $cart->is_empty() ) return;

		if ( $this->cart_allows_partner_program( $cart ) ) return;

		$applied = (array) $cart->get_applied_coupons();
		if ( empty($applied) ) return;

		foreach ( $applied as $code ) {
			if ( $this->is_partner_coupon_code( (string) $code ) ) {
				$cart->remove_coupon( $code );
				$this->log('partner.coupon.removed_by_event_toggle', ['code'=>(string)$code]);
			}
		}
	}


/**
	 * GF field value population for EB percentage (field 172).
	 */
	public function gf_populate_eb_pct( $value ) {
		// IMPORTANT:
		// The booking form is rendered on the single sc_event page. After refactors,
		// relying on a query-string (?event_id=) is brittle (and often missing),
		// which would make EB appear as 0% on the form even though the server/cart
		// correctly applies EB.
		//
		// We therefore resolve the event id in a resilient order:
		// 1) current queried object (preferred)
		// 2) explicit GET param (legacy)
		$event_id = 0;
		if ( function_exists('is_singular') && is_singular('sc_event') ) {
			$event_id = (int) get_queried_object_id();
		}
		if ( $event_id <= 0 && isset($_GET['event_id']) ) {
			$event_id = (int) $_GET['event_id'];
		}
		if ( $event_id <= 0 ) return $value;
		$calc = $this->calculate_for_event($event_id);
		$pct = (float) ($calc['pct'] ?? 0.0);
		if ( $pct <= 0 ) return $value;
		return $this->pct_to_gf_str($pct);
	}

	private function gf_entry_mark_cart_added( int $entry_id ) : void {
		if ( function_exists('gform_update_meta') ) {
			gform_update_meta($entry_id, self::GF_META_CART_ADDED, '1');
		}
	}

	private function gf_entry_was_cart_added( int $entry_id ) : bool {
		if ( ! function_exists('gform_get_meta') ) return false;
		return (string) gform_get_meta($entry_id, self::GF_META_CART_ADDED) === '1';
	}

	/**
	 * Guard against duplicate cart adds when legacy snippets (or refresh/back) already added items
	 * but GF entry meta has not yet been marked.
	 */
	private function cart_contains_entry_id( int $entry_id ) : bool {
		if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return false;
		$cart = WC()->cart->get_cart();
		if ( ! is_array($cart) ) return false;

		foreach ( $cart as $cart_item ) {
			if ( ! is_array($cart_item) ) continue;
			// Our booking payload lives under 'booking'
			if ( isset($cart_item['booking']) && is_array($cart_item['booking']) ) {
				$bid = isset($cart_item['booking'][ self::BK_ENTRY_ID ]) ? (int) $cart_item['booking'][ self::BK_ENTRY_ID ] : 0;
				if ( $bid === $entry_id ) return true;
			}
			// Back-compat: some flows store entry id on the line item meta directly
			if ( isset($cart_item['_gf_entry_id']) && (int) $cart_item['_gf_entry_id'] === $entry_id ) return true;
		}
		return false;
	}

	public function gf_after_submission_add_to_cart( $entry, $form ) {

		// Only run for the configured GF form
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return;


		$entry_id = (int) rgar($entry, 'id');
		if ( $entry_id <= 0 ) return;

		if ( $this->gf_entry_was_cart_added($entry_id) ) return;

		if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return;

			// Bulletproof duplicate guard: if the cart already contains an item linked to this GF entry,
			// do NOT add again (covers refresh/back, retries, or legacy snippet overlap).
			if ( $this->cart_contains_entry_id($entry_id) ) {
				$this->log('cart.add.skip.already_in_cart', ['entry_id'=>$entry_id]);
				$this->gf_entry_mark_cart_added($entry_id);
				return;
			}

		$event_id    = (int) rgar($entry, (string) self::GF_FIELD_EVENT_ID);
		// Prefer the actual event post title (current language). Fall back to GF field.
		$event_title = $this->localize_post_title( $event_id );
		if ( $event_title === '' ) {
			$event_title = (string) rgar($entry, (string) self::GF_FIELD_EVENT_TITLE);
		}

		if ( $event_id <= 0 ) return;

		// Canonical event dates / duration
		if ( ! function_exists('tc_sc_event_dates') ) return;

		$d = tc_sc_event_dates($event_id);
		if ( ! is_array($d) || empty($d['start_ts']) || empty($d['end_ts']) ) return;

		$start_ts = (int) $d['start_ts'];
		$end_ts   = (int) $d['end_ts'];

		// duration days (end exclusive)
		$duration_days = (int) ceil( max(1, ($end_ts - $start_ts) / DAY_IN_SECONDS ) );

		$start_year  = (int) gmdate('Y', $start_ts);
		$start_month = (int) gmdate('n', $start_ts);
		$start_day   = (int) gmdate('j', $start_ts);

		// participant
		$first = (string) rgar($entry, (string) self::GF_FIELD_FIRST_NAME);
		$last  = (string) rgar($entry, (string) self::GF_FIELD_LAST_NAME);

		// coupon code (partner)
		$coupon_code = trim((string) rgar($entry, (string) self::GF_FIELD_COUPON_CODE));
		$coupon_code = $coupon_code ? wc_format_coupon_code($coupon_code) : '';

		// EB snapshot (once)
		$calc = $this->calculate_for_event($event_id);
		$eb_days   = (int)   ($calc['days_before'] ?? 0);
		$eb_evt_ts = (int)   ($calc['event_start_ts'] ?? 0);
		$cfg       = (array) ($calc['cfg'] ?? []);
		$eb_step   = (array) ($calc['step'] ?? []);

		// Determine "with rental" based on your current bike choice concat
		$bicycle_choice = (string) rgar($entry, (string) self::GF_FIELD_BIKE_130)
			. (string) rgar($entry, (string) self::GF_FIELD_BIKE_142)
			. (string) rgar($entry, (string) self::GF_FIELD_BIKE_143)
			. (string) rgar($entry, (string) self::GF_FIELD_BIKE_169);

		$bicycle_choice_ = explode('_', $bicycle_choice);
		$product_id_bicycle  = isset($bicycle_choice_[0]) ? (int) $bicycle_choice_[0] : 0;
		$resource_id_bicycle = isset($bicycle_choice_[1]) ? (int) $bicycle_choice_[1] : 0;

		$has_rental = ($product_id_bicycle > 0 && $resource_id_bicycle > 0);

		$eb_pct_display = 0.0;
		if ( $eb_step && strtolower((string)($eb_step['type'] ?? 'percent')) === 'percent' ) {
			$eb_pct_display = (float) ($eb_step['value'] ?? 0.0);
		}
		$this->log('gf.after_submission.start', [
			'form_id' => $form_id,
			'entry_id' => $entry_id,
			'event_id' => $event_id,
			'event_title' => $event_title,
			'has_rental' => $has_rental,
			'product_id_bicycle' => $product_id_bicycle,
			'resource_id_bicycle' => $resource_id_bicycle,
			'eb_pct' => $eb_pct_display,
			'eb_days' => $eb_days,
		], 'info');

		/**
		 * IMPORTANT:
		 * Your current snippet uses "tour product" as the booking product (and even uses bike resource on it).
		 * For the new split model, we expect:
		 * - participation product id resolved from your mapping (existing logic)
		 * - rental product id = selected bicycle product (bookable)
		 *
		 * Until your product scheme is rearranged, we keep a safe fallback:
		 * If we cannot add a clean participation booking without resources, we keep the legacy single-line behavior.
		 */

		$quantity = 1;

		// ---- Resolve participation product (KEEP: put your mapping here) ----
		$product_id_participation = $this->resolve_participation_product_id($event_id, $entry);
		$this->log('resolver.participation.result', ['event_id'=>$event_id,'product_id'=>$product_id_participation]);

		if ( $product_id_participation <= 0 ) return;

		$product_part = wc_get_product($product_id_participation);
		if ( ! $product_part || ! function_exists('is_wc_booking_product') || ! is_wc_booking_product($product_part) ) return;

		// Participation booking posted data (no resource)
		$sim_post_part = [
			'wc_bookings_field_duration'         => $duration_days,
			'wc_bookings_field_start_date_year'  => $start_year,
			'wc_bookings_field_start_date_month' => $start_month,
			'wc_bookings_field_start_date_day'   => $start_day,
		];

		// If participation product requires resource, but we don't have one, we will fallback to legacy mode.
		$part_requires_resource = method_exists($product_part, 'has_resources') ? (bool) $product_part->has_resources() : false;

		// -------------------------
		// Build participation cart item
		// -------------------------
		$cart_item_meta_part = [];
		$cart_item_meta_part['booking'] = wc_bookings_get_posted_data($sim_post_part, $product_part);

		$cart_item_meta_part['booking'][self::BK_EVENT_ID]    = $event_id;
		$cart_item_meta_part['booking'][self::BK_EVENT_TITLE] = $event_title;
		$cart_item_meta_part['booking'][self::BK_ENTRY_ID]    = $entry_id;
		$cart_item_meta_part['booking'][self::BK_SCOPE]       = 'participation';

		$participant_name = trim($first . ' ' . $last);
		if ( $participant_name !== '' ) {
			$cart_item_meta_part['booking']['_participant'] = $participant_name;
		}


		// EB snapshot fields for participation
		$eligible_part = ! empty($cfg['enabled']) && ! empty($cfg['participation_enabled']);
		$cart_item_meta_part['booking'][self::BK_EB_ELIGIBLE] = $eligible_part ? 1 : 0;
		$cart_item_meta_part['booking'][self::BK_EB_DAYS]     = (string) $eb_days;
		$cart_item_meta_part['booking'][self::BK_EB_EVENT_TS] = (string) $eb_evt_ts;

		/**
		 * Cost model:
		 * Participation price comes from event meta (single source of truth).
		 *
		 * Keys used today (from your snippets / event metabox):
		 * - event_price  (participation base)
		 *
		 * We always snapshot it into BK_CUSTOM_COST so Woo Bookings cannot drift.
		 * If meta is missing, we fall back to the GF total as a last-resort safety net.
		 */
		$part_price = $this->money_to_float( get_post_meta($event_id, 'event_price', true) );
		if ( $part_price > 0 ) {
			$cart_item_meta_part['booking'][self::BK_CUSTOM_COST] = wc_format_decimal($part_price, 2);
		} else {
			$legacy_total = (float) rgar($entry, (string) self::GF_FIELD_TOTAL);
			if ( $legacy_total > 0 ) {
				$cart_item_meta_part['booking'][self::BK_CUSTOM_COST] = wc_format_decimal($legacy_total, 2);
			}
		}

		// -------------------------------------------------
		// EB discount distribution (event-wise meta rules)
		// Compute once per submission and distribute across eligible scopes.
		// -------------------------------------------------
		$base_part = isset($cart_item_meta_part['booking'][self::BK_CUSTOM_COST]) ? (float) $cart_item_meta_part['booking'][self::BK_CUSTOM_COST] : 0.0;
		$eligible_bases = [];
		if ( $eligible_part && $base_part > 0 ) {
			$eligible_bases['part'] = $base_part;
		}

		$rental_fixed_preview = 0.0;
		$eligible_rental = false;
		if ( $has_rental ) {
			$eligible_rental = ! empty($cfg['enabled']) && ! empty($cfg['rental_enabled']);
			// We need the fixed rental price now (to correctly distribute EB across scopes).
			$rental_fixed_preview = $this->get_event_rental_price($event_id, $entry, $product_id_bicycle);
			if ( $eligible_rental && $rental_fixed_preview > 0 ) {
				$eligible_bases['rental'] = (float) $rental_fixed_preview;
			}
		}

		$eb_total_amt = 0.0;
		$eb_eff_pct   = 0.0;
		$eb_amt_part  = 0.0;
		$eb_amt_rental = 0.0;
		$eligible_sum = array_sum($eligible_bases);
		if ( ! empty($cfg['enabled']) && $eligible_sum > 0 && $eb_step ) {
			$comp = $this->compute_eb_amount((float)$eligible_sum, $eb_step, (array)($cfg['global_cap'] ?? []));
			$eb_total_amt = (float) ($comp['amount'] ?? 0.0);
			$eb_eff_pct   = (float) ($comp['effective_pct'] ?? 0.0);

			if ( $eb_total_amt > 0 ) {
				// Proportional distribution with rounding.
				if ( isset($eligible_bases['part']) && $eligible_bases['part'] > 0 ) {
					$eb_amt_part = $this->money_round($eb_total_amt * ($eligible_bases['part'] / $eligible_sum));
				}
				if ( isset($eligible_bases['rental']) && $eligible_bases['rental'] > 0 ) {
					$eb_amt_rental = $this->money_round($eb_total_amt * ($eligible_bases['rental'] / $eligible_sum));
				}
				// Fix rounding drift on last eligible line.
				$drift = $this->money_round($eb_total_amt - ($eb_amt_part + $eb_amt_rental));
				if ( abs($drift) > 0.0001 ) {
					if ( isset($eligible_bases['rental']) ) {
						$eb_amt_rental = max(0.0, $eb_amt_rental + $drift);
					} else {
						$eb_amt_part = max(0.0, $eb_amt_part + $drift);
					}
				}
			}
		}

		// Apply EB snapshot fields (audit) to participation booking payload
		$cart_item_meta_part['booking'][self::BK_EB_ELIGIBLE] = $eligible_part ? 1 : 0;
		$cart_item_meta_part['booking'][self::BK_EB_PCT]      = $eligible_part ? wc_format_decimal($eb_eff_pct, 2) : '0';
		$cart_item_meta_part['booking'][self::BK_EB_AMOUNT]   = $eligible_part ? wc_format_decimal($eb_amt_part, 2) : '0';


		if ( function_exists('wc_load_cart') ) {
			wc_load_cart();
		}

		$cart_obj = WC()->cart;

		$added_keys = [];

		// If participation product requires resource and rental exists, use rental resource as fallback (legacy compatibility)
		if ( $part_requires_resource && $has_rental ) {
			$cart_item_meta_part['booking']['resource_id'] = $resource_id_bicycle;
			$cart_item_meta_part['booking']['wc_bookings_field_resource'] = $resource_id_bicycle;
		}

		$this->log('cart.add.participation', ['event_id'=>$event_id,'product_id'=>$product_id_participation,'custom_cost'=>$cart_item_meta_part['booking'][self::BK_CUSTOM_COST] ?? null,'duration_days'=>$duration_days]);
		$added_part = $cart_obj->add_to_cart($product_id_participation, $quantity, 0, [], $cart_item_meta_part);
		if ( $added_part ) { $this->log('cart.add.participation.ok', ['cart_key'=>$added_part]); }
		if ( $added_part ) $added_keys[] = $added_part;

		// -------------------------
		// Add rental as separate cart item (only if scheme supports it)
		// -------------------------
		if ( $has_rental ) {

			$product_rental = wc_get_product($product_id_bicycle);

			if ( $product_rental && function_exists('is_wc_booking_product') && is_wc_booking_product($product_rental) ) {

				$sim_post_rental = [
					'wc_bookings_field_duration'         => $duration_days,
					'wc_bookings_field_start_date_year'  => $start_year,
					'wc_bookings_field_start_date_month' => $start_month,
					'wc_bookings_field_start_date_day'   => $start_day,
					'wc_bookings_field_resource'         => $resource_id_bicycle,
				];

				$cart_item_meta_rental = [];
				$cart_item_meta_rental['booking'] = wc_bookings_get_posted_data($sim_post_rental, $product_rental);

				$cart_item_meta_rental['booking'][self::BK_EVENT_ID]    = $event_id;
				$cart_item_meta_rental['booking'][self::BK_EVENT_TITLE] = $event_title;
				$cart_item_meta_rental['booking'][self::BK_ENTRY_ID]    = $entry_id;
				$cart_item_meta_rental['booking'][self::BK_SCOPE]       = 'rental';

				// Participant + bicycle label snapshots (for cart/order display)
				$participant_name = trim($first . ' ' . $last);
				if ( $participant_name !== '' ) {
					$cart_item_meta_rental['booking']['_participant'] = $participant_name;
				}

				$bicycle_label = '';
				if ( is_object($product_rental) && method_exists($product_rental, 'get_name') ) {
					$bicycle_label = (string) $product_rental->get_name();
				}
				if ( $resource_id_bicycle > 0 ) {
					$res_title = $this->localize_post_title( $resource_id_bicycle );
					if ( $res_title ) {
						$bicycle_label = $bicycle_label ? ($bicycle_label . ' — ' . $res_title) : (string) $res_title;
					}
				}
				if ( $bicycle_label !== '' ) {
					$cart_item_meta_rental['booking']['_bicycle'] = $bicycle_label;
				}


				// EB snapshot fields for rental (distributed amounts computed above)
				$cart_item_meta_rental['booking'][self::BK_EB_ELIGIBLE] = $eligible_rental ? 1 : 0;
				$cart_item_meta_rental['booking'][self::BK_EB_PCT]      = $eligible_rental ? wc_format_decimal($eb_eff_pct, 2) : '0';
				$cart_item_meta_rental['booking'][self::BK_EB_AMOUNT]   = $eligible_rental ? wc_format_decimal($eb_amt_rental, 2) : '0';
				$cart_item_meta_rental['booking'][self::BK_EB_DAYS]     = (string) $eb_days;
				$cart_item_meta_rental['booking'][self::BK_EB_EVENT_TS] = (string) $eb_evt_ts;
					// Rental price is fixed per event (stored on event meta) and must be snapshotted.
					$rental_fixed = $rental_fixed_preview;
					if ( $rental_fixed <= 0 ) {
						$rental_fixed = $this->get_event_rental_price($event_id, $entry, $product_id_bicycle);
					}
					if ( $rental_fixed > 0 ) {
						$cart_item_meta_rental['booking'][self::BK_CUSTOM_COST] = wc_format_decimal($rental_fixed, 2);
					}

				$this->log('cart.add.rental', [
					'event_id' => $event_id,
					'product_id' => $product_id_bicycle,
					'resource_id' => $resource_id_bicycle,
					'custom_cost' => ($cart_item_meta_rental['booking'][self::BK_CUSTOM_COST] ?? ''),
					'eligible_eb' => ($cart_item_meta_rental['booking'][self::BK_EB_ELIGIBLE] ?? 0),
				]);
				$added_rental = $cart_obj->add_to_cart($product_id_bicycle, $quantity, 0, [], $cart_item_meta_rental);
				if ( $added_rental ) $added_keys[] = $added_rental;
			}
		}

		// Apply coupon after items exist (Woo validates)
		if ( $coupon_code && $added_keys ) {
			$cart_obj->add_discount($coupon_code);
			// Persist coupon + totals immediately.
			if ( method_exists($cart_obj, 'calculate_totals') ) {
				$cart_obj->calculate_totals();
			}
			if ( method_exists($cart_obj, 'set_session') ) {
				$cart_obj->set_session();
			}
			if ( WC()->session && method_exists(WC()->session, 'save_data') ) {
				WC()->session->save_data();
			}
		}

		// Mark GF entry only if we added at least the participation line
		if ( $added_part ) {
			$this->gf_entry_mark_cart_added($entry_id);

			// Mark entry as in_cart (Phase 2: Entry State)
			if ( class_exists('\\TC_BF\\Domain\\Entry_State') ) {
				\TC_BF\Domain\Entry_State::mark_in_cart( $entry_id );
			}
		}
	}

	/**
	 * Calculate booking cost snapshot (not extracted - remains here).
	 *
	 * We intentionally calculate once and then enforce via set_price() + BK_CUSTOM_COST.
	 * This prevents Woo Bookings from recalculating later in the funnel.
	 */
	private function calculate_booking_cost_snapshot( $product, array $booking ) : ?float {

		// Strip our internal meta keys from the posted array.
		$posted = $booking;
		unset(
			$posted[self::BK_EVENT_ID],
			$posted[self::BK_EVENT_TITLE],
			$posted[self::BK_ENTRY_ID],
			$posted[self::BK_SCOPE],
			$posted[self::BK_EB_PCT],
			$posted[self::BK_EB_ELIGIBLE],
			$posted[self::BK_EB_DAYS],
			$posted[self::BK_EB_BASE],
			$posted[self::BK_EB_EVENT_TS],
			$posted[self::BK_CUSTOM_COST]
		);

		// Primary path: Woo Bookings cost calculator (if available).
		if ( class_exists('WC_Bookings_Cost_Calculation') && is_callable(['WC_Bookings_Cost_Calculation', 'calculate_booking_cost']) ) {
			try {
				$cost = \WC_Bookings_Cost_Calculation::calculate_booking_cost( $posted, $product );
				if ( is_numeric($cost) ) return (float) $cost;
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		// Fallback: if Woo already set a price for this cart item, we can snapshot it.
		// (Better than nothing; still locks the number.)
		if ( is_object($product) && method_exists($product, 'get_price') ) {
			$maybe = (float) $product->get_price();
			if ( $maybe > 0 ) return $maybe;
		}

		return null;
	}

	/* =========================================================
	 * Delegation wrappers
	 * ========================================================= */

	// Logger delegation
	private function is_debug() : bool {
		return Support\Logger::is_debug();
	}

	private function log( string $context, array $data = [], string $level = 'info' ) : void {
		Support\Logger::log($context, $data, $level);
	}

	// EventConfig delegation
	public function get_event_config( int $event_id ) : array {
		return Domain\EventConfig::get_event_config($event_id);
	}

	// Ledger delegation
	private function select_eb_step( int $days_before, array $steps ) : array {
		return Domain\Ledger::select_eb_step($days_before, $steps);
	}

	private function compute_eb_amount( float $base_total, array $step, array $global_cap ) : array {
		return Domain\Ledger::compute_eb_amount($base_total, $step, $global_cap);
	}

	public function calculate_for_event( int $event_id ) : array {
		// Check instance cache first
		if ( isset($this->calc_cache[$event_id]) ) return $this->calc_cache[$event_id];
		// Delegate to Ledger and cache result
		$result = Domain\Ledger::calculate_for_event($event_id);
		$this->calc_cache[$event_id] = $result;
		return $result;
	}

	// Money delegation
	private function float_to_str( float $v ) : string {
		return Support\Money::float_to_str($v);
	}

	private function pct_to_gf_str( float $v ) : string {
		return Support\Money::pct_to_gf_str($v);
	}

	private function money_to_float( $val ) : float {
		return Support\Money::money_to_float($val);
	}

	private function money_round( float $v ) : float {
		return Support\Money::money_round($v);
	}

	// PartnerResolver delegation
	private function resolve_partner_context( int $form_id ) : array {
		return Domain\PartnerResolver::resolve_partner_context($form_id);
	}

	private function normalize_partner_code( string $code ) : string {
		return Domain\PartnerResolver::normalize_partner_code($code);
	}

	private function build_partner_context_from_code( string $code, int $known_user_id = 0 ) : array {
		return Domain\PartnerResolver::build_partner_context_from_code($code, $known_user_id);
	}

	private function find_partner_user_id_by_code( string $code ) : int {
		return Domain\PartnerResolver::find_partner_user_id_by_code($code);
	}

	private function get_coupon_percent_amount( string $code ) : float {
		return Domain\PartnerResolver::get_coupon_percent_amount($code);
	}

	// GF_Validation delegation
	public function gf_validation( array $validation_result ) : array {
		return Integrations\GravityForms\GF_Validation::gf_validation($validation_result);
	}

	// GF_JS delegation
	public function gf_partner_prepare_form( $form ) {
		return Integrations\GravityForms\GF_JS::partner_prepare_form($form);
	}

	public function gf_output_partner_js() : void {
		Integrations\GravityForms\GF_JS::output_partner_js();
	}

	/**
	 * Output CSS for enhanced form fields, cart badges, and coupon styling.
	 * Runs in wp_head on single-sc_event, cart, and checkout pages.
	 */
	public function output_form_field_css() : void {
		if ( is_admin() ) return;

		$is_event = is_singular('sc_event');
		$is_cart = is_cart() || is_checkout();

		if ( ! $is_event && ! $is_cart ) return;

		// Get dynamic form ID
		$form_id = (int) Admin\Settings::get_form_id();
		if ( $form_id <= 0 ) $form_id = 48; // Fallback

		echo "\n<!-- TC Booking Flow: Enhanced Field CSS -->\n";
		echo "<style id=\"tc-bf-enhanced-fields\">\n";
		echo "/* Enhanced Field 179 - EB Discount (form ID: {$form_id}) */\n";
		echo "#field_{$form_id}_179 .gfield_label { display: none !important; }\n";
		echo ".tcbf-eb-enhanced {\n";
		echo "  background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);\n";
		echo "  padding: 16px 20px;\n";
		echo "  display: flex;\n";
		echo "  justify-content: space-between;\n";
		echo "  align-items: center;\n";
		echo "  gap: 16px;\n";
		echo "}\n";
		echo ".tcbf-eb-badge { display: flex; align-items: center; gap: 10px; }\n";
		echo ".tcbf-eb-icon { font-size: 24px; color: #ffffff; line-height: 1; }\n";
		echo ".tcbf-eb-text { font-size: 18px; font-weight: 700; color: #ffffff; letter-spacing: 0.5px; }\n";
		echo ".tcbf-eb-info { text-align: right; display: flex; flex-direction: column; gap: 2px; }\n";
		echo ".tcbf-eb-pct { font-size: 14px; color: #ffffff; font-weight: 500; opacity: 0.95; }\n";
		echo ".tcbf-eb-amt { font-size: 20px; font-weight: 700; color: #ffffff; }\n";
		echo "/* Enhanced Field 180 - Partner Discount (form ID: {$form_id}) */\n";
		echo "#field_{$form_id}_180 .gfield_label { display: none !important; }\n";
		echo "#field_{$form_id}_182 { display: none !important; }\n";
		echo ".tcbf-partner-enhanced {\n";
		echo "  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);\n";
		echo "  padding: 16px 20px;\n";
		echo "  display: flex;\n";
		echo "  justify-content: space-between;\n";
		echo "  align-items: center;\n";
		echo "  gap: 16px;\n";
		echo "}\n";
		echo ".tcbf-partner-badge { display: flex; align-items: center; gap: 10px; }\n";
		echo ".tcbf-partner-icon { font-size: 24px; color: #22c55e; line-height: 1; }\n";
		echo ".tcbf-partner-code { font-size: 18px; font-weight: 700; color: #14532d; letter-spacing: 0.5px; }\n";
		echo ".tcbf-partner-info { text-align: right; display: flex; flex-direction: column; gap: 2px; }\n";
		echo ".tcbf-partner-pct { font-size: 14px; color: #14532d; font-weight: 500; }\n";
		echo ".tcbf-partner-amt { font-size: 20px; font-weight: 700; color: #14532d; }\n";
		echo "@media (max-width: 768px) {\n";
		echo "  .tcbf-eb-enhanced, .tcbf-partner-enhanced { padding: 14px 16px; gap: 12px; }\n";
		echo "  .tcbf-eb-icon, .tcbf-partner-icon { font-size: 20px; }\n";
		echo "  .tcbf-eb-text, .tcbf-partner-code { font-size: 16px; }\n";
		echo "  .tcbf-eb-pct, .tcbf-partner-pct { font-size: 13px; }\n";
		echo "  .tcbf-eb-amt, .tcbf-partner-amt { font-size: 18px; }\n";
		echo "}\n";

		// Cart-specific styling
		if ( $is_cart ) {
			echo "\n/* ===== Pack Grouping Visual Styles ===== */\n";
			echo "tbody.tcbf-pack-group {\n";
			echo "  position: relative;\n";
			echo "  background: rgba(61, 97, 170, 0.02);\n";
			echo "  border-left: 3px solid rgba(61, 97, 170, 0.15);\n";
			echo "}\n";

			echo "tbody.tcbf-pack-group tr.tcbf-pack-item td {\n";
			echo "  border-top: 1px dashed rgba(61, 97, 170, 0.08) !important;\n";
			echo "}\n";

			echo "tbody.tcbf-pack-group tr.tcbf-pack-item:first-of-type td {\n";
			echo "  padding-top: 20px !important;\n";
			echo "}\n";

			echo "tbody.tcbf-pack-group tr.tcbf-pack-item:last-of-type td {\n";
			echo "  padding-bottom: 20px !important;\n";
			echo "}\n";

			echo "tr.tcbf-pack-header td {\n";
			echo "  padding: 0 !important;\n";
			echo "  border: none !important;\n";
			echo "  background: transparent !important;\n";
			echo "}\n";

			echo ".tcbf-pack-participant-badge {\n";
			echo "  display: inline-block;\n";
			echo "  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);\n";
			echo "  border: 1px solid rgba(61, 97, 170, 0.2);\n";
			echo "  color: #3d61aa;\n";
			echo "  padding: 6px 14px;\n";
			echo "  border-radius: 12px;\n";
			echo "  font-size: 13px;\n";
			echo "  font-weight: 600;\n";
			echo "  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);\n";
			echo "  margin: 12px 0 8px 0;\n";
			echo "}\n";

			echo ".tcbf-pack-participant-badge__icon {\n";
			echo "  font-size: 14px;\n";
			echo "  margin-right: 6px;\n";
			echo "}\n";

			echo "\n/* ===== Cart Item Footer (for EB badges) ===== */\n";
			echo ".tcbf-cart-item-footer {\n";
			echo "  margin-top: 12px;\n";
			echo "  display: flex;\n";
			echo "  flex-direction: column;\n";
			echo "  gap: 8px;\n";
			echo "}\n";

			echo "\n/* Cart EB Discount Badge */\n";
			echo ".tcbf-cart-eb-badge {\n";
			echo "  background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);\n";
			echo "  color: #ffffff;\n";
			echo "  padding: 8px 14px;\n";
			echo "  border-radius: 6px;\n";
			echo "  font-size: 14px;\n";
			echo "  font-weight: 600;\n";
			echo "  display: inline-flex;\n";
			echo "  align-items: center;\n";
			echo "  gap: 10px;\n";
			echo "  line-height: 1.3;\n";
			echo "  align-self: flex-start;\n";
			echo "}\n";
			echo ".tcbf-cart-eb-badge__icon {\n";
			echo "  font-size: 24px;\n";
			echo "  line-height: 1;\n";
			echo "}\n";
			echo ".tcbf-cart-eb-badge__text {\n";
			echo "  white-space: nowrap;\n";
			echo "}\n";

			echo "\n/* Inline Pack Badge (in product title) */\n";
			echo ".tcbf-pack-badge-inline {\n";
			echo "  background: rgba(107, 114, 128, 0.12);\n";
			echo "  color: rgba(55, 65, 81, 0.7);\n";
			echo "  padding: 3px 8px;\n";
			echo "  border-radius: 4px;\n";
			echo "  font-size: 11px;\n";
			echo "  font-weight: 500;\n";
			echo "  display: inline-flex;\n";
			echo "  align-items: center;\n";
			echo "  gap: 4px;\n";
			echo "  margin-left: 8px;\n";
			echo "  vertical-align: middle;\n";
			echo "}\n";
			echo ".tcbf-pack-badge-inline__icon {\n";
			echo "  font-size: 11px;\n";
			echo "  opacity: 0.7;\n";
			echo "}\n";
			echo ".tcbf-pack-badge-inline__text {\n";
			echo "  font-style: italic;\n";
			echo "}\n";

			echo "\n/* Participant Badge (for pack parent items) */\n";
			echo ".tcbf-participant-badge {\n";
			echo "  background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 100%);\n";
			echo "  color: #312e81;\n";
			echo "  padding: 6px 12px;\n";
			echo "  border-radius: 6px;\n";
			echo "  font-size: 13px;\n";
			echo "  font-weight: 600;\n";
			echo "  display: inline-flex;\n";
			echo "  align-items: center;\n";
			echo "  gap: 8px;\n";
			echo "  border: 1px solid rgba(99, 102, 241, 0.2);\n";
			echo "}\n";
			echo ".tcbf-participant-badge__icon {\n";
			echo "  font-size: 16px;\n";
			echo "  line-height: 1;\n";
			echo "}\n";
			echo ".tcbf-participant-badge__text {\n";
			echo "  line-height: 1.3;\n";
			echo "}\n";

			echo "\n/* Partner Coupon Styling in Cart Totals */\n";
			echo ".cart_totals .coupon,\n";
			echo ".woocommerce-cart-form .coupon,\n";
			echo ".woocommerce-checkout-review-order .coupon {\n";
			echo "  /* Target partner coupons specifically */\n";
			echo "}\n";

			echo "/* Style partner coupon rows in cart totals */\n";
			echo ".cart_totals tr.cart-discount,\n";
			echo ".woocommerce-checkout-review-order tr.cart-discount {\n";
			echo "  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);\n";
			echo "}\n";

			echo ".cart_totals tr.cart-discount th,\n";
			echo ".woocommerce-checkout-review-order tr.cart-discount th {\n";
			echo "  color: #14532d !important;\n";
			echo "  font-weight: 600;\n";
			echo "  padding: 12px !important;\n";
			echo "}\n";

			echo ".cart_totals tr.cart-discount td,\n";
			echo ".woocommerce-checkout-review-order tr.cart-discount td {\n";
			echo "  color: #14532d !important;\n";
			echo "  font-weight: 700;\n";
			echo "  padding: 12px !important;\n";
			echo "}\n";

			echo ".cart_totals tr.cart-discount .woocommerce-remove-coupon,\n";
			echo ".woocommerce-checkout-review-order tr.cart-discount .woocommerce-remove-coupon {\n";
			echo "  color: #22c55e !important;\n";
			echo "  text-decoration: none;\n";
			echo "  font-weight: 600;\n";
			echo "}\n";

			echo ".cart_totals tr.cart-discount .woocommerce-remove-coupon:hover,\n";
			echo ".woocommerce-checkout-review-order tr.cart-discount .woocommerce-remove-coupon:hover {\n";
			echo "  color: #16a34a !important;\n";
			echo "}\n";

			echo "\n/* Mobile responsive */\n";
			echo "@media (max-width: 768px) {\n";
			echo "  .tcbf-cart-eb-badge {\n";
			echo "    padding: 6px 12px;\n";
			echo "    font-size: 13px;\n";
			echo "    gap: 8px;\n";
			echo "  }\n";
			echo "  .tcbf-cart-eb-badge__icon {\n";
			echo "    font-size: 20px;\n";
			echo "  }\n";
			echo "  .tcbf-pack-participant-badge {\n";
			echo "    font-size: 11px;\n";
			echo "    padding: 3px 10px;\n";
			echo "  }\n";
			echo "  .tcbf-pack-group {\n";
			echo "    padding: 12px;\n";
			echo "  }\n";
			echo "  .tcbf-pack-badge-inline {\n";
			echo "    font-size: 10px;\n";
			echo "    padding: 2px 6px;\n";
			echo "    margin-left: 6px;\n";
			echo "  }\n";
			echo "}\n";
		}

		echo "</style>\n";
		echo "<!-- /TC Booking Flow: Enhanced Field CSS -->\n";
	}

	/**
	 * Early diagnostic script to detect JavaScript syntax errors before our main code.
	 * Outputs at wp_footer priority 5 (very early) to help identify if page has syntax errors.
	 */
	public function output_early_diagnostic() : void {
		if ( is_admin() ) return;
		if ( ! Admin\Settings::is_debug() ) return; // Only when debug mode enabled

		// Output minimal diagnostic script to test if JS execution works
		echo "\n<!-- TC Booking Flow: Early JS Diagnostic (priority 5) -->\n";
		echo "<script id=\"tc-bf-early-diagnostic\">\n";
		echo "(function(){\n";
		echo "  try{\n";
		echo "    console.log('[TC-BF-DIAG] Early diagnostic loaded at priority 5');\n";
		echo "    window.tcBfEarlyDiagnostic = {loaded: true, time: Date.now()};\n";
		echo "  }catch(e){ console.error('[TC-BF-DIAG]', e); }\n";
		echo "})();\n";
		echo "</script>\n";
		echo "<!-- /TC Booking Flow: Early diagnostic -->\n";
	}

	/**
	 * Late diagnostic script to verify our main code executed.
	 * Outputs at wp_footer priority 200 (after our main script at 100).
	 */
	public function output_late_diagnostic() : void {
		if ( is_admin() ) return;
		if ( ! Admin\Settings::is_debug() ) return;

		echo "\n<!-- TC Booking Flow: Late JS Diagnostic (priority 200) -->\n";
		echo "<script id=\"tc-bf-late-diagnostic\">\n";
		echo "(function(){\n";
		echo "  try{\n";
		echo "    console.log('[TC-BF-DIAG] Late diagnostic loaded at priority 200');\n";
		echo "    console.log('[TC-BF-DIAG] Early diagnostic present:', !!window.tcBfEarlyDiagnostic);\n";
		echo "    console.log('[TC-BF-DIAG] Partner disc initialized:', !!window.__tcBfPartnerDiscInitialized);\n";
		echo "    console.log('[TC-BF-DIAG] Partner map present:', !!window.tcBfPartnerMap);\n";
		echo "    console.log('[TC-BF-DIAG] gform.addFilter available:', !!(window.gform && window.gform.addFilter));\n";
		echo "  }catch(e){ console.error('[TC-BF-DIAG-LATE]', e); }\n";
		echo "})();\n";
		echo "</script>\n";
		echo "<!-- /TC Booking Flow: Late diagnostic -->\n";
	}

	// Woo delegation
	public function woo_override_booking_cost( $cost, $book_obj, $posted ) {
		return Integrations\WooCommerce\Woo::woo_override_booking_cost($cost, $book_obj, $posted);
	}

	public function woo_apply_eb_snapshot_to_cart( $cart ) {
		Integrations\WooCommerce\Woo::woo_apply_eb_snapshot_to_cart($cart);
	}

	public function woo_cart_item_data( array $item_data, array $cart_item ) : array {
		return Integrations\WooCommerce\Woo::woo_cart_item_data($item_data, $cart_item);
	}

	/**
	 * Filter cart meta field labels to hide WooCommerce Bookings fields we don't want to show.
	 * Hide: Booking date, Duration, Size (but keep them as order meta for admin).
	 *
	 * @param string $display_key  Meta key display label
	 * @param object $meta         Meta object
	 * @param object $item         Order/cart item
	 * @return string Empty string to hide, original key to show
	 */
	public function woo_filter_cart_meta_labels( $display_key, $meta = null, $item = null ) {
		// Hide WooCommerce Bookings auto-generated fields from cart/checkout display
		$hidden_keys = [
			'Booking Date',
			'Booking Dates',
			'Duration',
			'Size',
			'Persons',
			'Resource',
		];

		// Check if this is a hidden key
		foreach ( $hidden_keys as $hidden ) {
			if ( stripos( $display_key, $hidden ) !== false ) {
				return ''; // Return empty string to hide
			}
		}

		return $display_key;
	}

	/**
	 * Add "Included in pack" badge to product title for child items (rentals).
	 *
	 * @param string $product_name Product name HTML
	 * @param array  $cart_item    Cart item data
	 * @param string $cart_item_key Cart item key
	 * @return string Modified product name with badge
	 */
	public function woo_add_pack_badge_to_title( $product_name, $cart_item, $cart_item_key ) {
		// Check if this is a child item (rental in pack)
		$role = isset( $cart_item['tc_group_role'] ) ? $cart_item['tc_group_role'] : '';

		if ( $role === 'child' ) {
			// Multilingual badge label with qTranslate support
			$label = '[:en]Included in pack[:es]Incluido en el pack[:]';

			// Translate if qTranslate or custom tc_sc_event_tr function available
			if ( function_exists( 'tc_sc_event_tr' ) ) {
				$label = tc_sc_event_tr( $label );
			} elseif ( function_exists( 'qtranxf_useCurrentLanguageIfNotFound' ) ) {
				$label = qtranxf_useCurrentLanguageIfNotFound( $label );
			} elseif ( function_exists( 'qtrans_useCurrentLanguageIfNotFound' ) ) {
				$label = qtrans_useCurrentLanguageIfNotFound( $label );
			}

			// Add grey semi-transparent badge inline with title
			$badge = ' <span class="tcbf-pack-badge-inline">';
			$badge .= '<span class="tcbf-pack-badge-inline__icon">📦</span>';
			$badge .= '<span class="tcbf-pack-badge-inline__text">' . esc_html( $label ) . '</span>';
			$badge .= '</span>';

			$product_name .= $badge;
		}

		return $product_name;
	}

	/**
	 * Add pack grouping classes and data attributes to cart item rows.
	 *
	 * @param string $class         Cart item class
	 * @param array  $cart_item     Cart item data
	 * @param string $cart_item_key Cart item key
	 * @return string Modified class with pack data attributes
	 */
	public function woo_add_pack_classes_to_cart_item( $class, $cart_item, $cart_item_key ) {
		$group_id = isset( $cart_item['tc_group_id'] ) ? (int) $cart_item['tc_group_id'] : 0;
		$role = isset( $cart_item['tc_group_role'] ) ? $cart_item['tc_group_role'] : '';
		$participant = '';

		if ( $group_id > 0 ) {
			$class .= ' tcbf-pack-item';
			$class .= ' tcbf-pack-group-' . $group_id;
			$class .= ' tcbf-pack-role-' . $role;

			// Get participant name for floating badge
			if ( ! empty( $cart_item['booking']['_participant'] ) ) {
				$participant = wc_clean( (string) $cart_item['booking']['_participant'] );
			}

			// Add data attributes for JavaScript grouping
			$class .= '" data-pack-group="' . esc_attr( $group_id );
			$class .= '" data-pack-role="' . esc_attr( $role );
			$class .= '" data-pack-participant="' . esc_attr( $participant );
		}

		return $class;
	}

	/**
	 * Output JavaScript to visually group pack items in cart.
	 *
	 * Groups consecutive cart items that belong to the same pack and adds
	 * a floating participant badge over the group.
	 */
	public function output_pack_grouping_js() : void {
		if ( ! is_cart() && ! is_checkout() ) {
			return;
		}

		echo "\n<!-- TC Booking Flow: Pack Grouping Script -->\n";
		echo "<script id=\"tc-bf-pack-grouping\">\n";
		echo "(function($) {\n";
		echo "  'use strict';\n";
		echo "  $(document).ready(function() {\n";
		echo "    // Find all pack items\n";
		echo "    var packItems = $('.tcbf-pack-item');\n";
		echo "    if (packItems.length === 0) return;\n";
		echo "\n";
		echo "    // Group items by pack ID\n";
		echo "    var groups = {};\n";
		echo "    packItems.each(function() {\n";
		echo "      var groupId = $(this).attr('data-pack-group');\n";
		echo "      if (!groupId) return;\n";
		echo "      if (!groups[groupId]) groups[groupId] = [];\n";
		echo "      groups[groupId].push(this);\n";
		echo "    });\n";
		echo "\n";
		echo "    // Wrap each group and add participant badge\n";
		echo "    $.each(groups, function(groupId, items) {\n";
		echo "      if (items.length === 0) return;\n";
		echo "      \n";
		echo "      var participant = $(items[0]).attr('data-pack-participant') || '';\n";
		echo "      \n";
		echo "      // Wrap items in pack group container\n";
		echo "      var wrapper = $('<tbody class=\"tcbf-pack-group\" data-pack-group=\"' + groupId + '\"></tbody>');\n";
		echo "      $(items[0]).before(wrapper);\n";
		echo "      $(items).each(function() { wrapper.append(this); });\n";
		echo "      \n";
		echo "      // Add floating participant badge if participant name exists\n";
		echo "      if (participant) {\n";
		echo "        var badge = $('<tr class=\"tcbf-pack-header\"><td colspan=\"6\"><div class=\"tcbf-pack-participant-badge\"><span class=\"tcbf-pack-participant-badge__icon\">👤</span> ' + participant + '</div></td></tr>');\n";
		echo "        wrapper.prepend(badge);\n";
		echo "      }\n";
		echo "    });\n";
		echo "  });\n";
		echo "})(jQuery);\n";
		echo "</script>\n";
		echo "<!-- /TC Booking Flow: Pack Grouping Script -->\n";
	}

	/**
	 * Display EB discount badge after cart item name.
	 * Shows percentage and amount saved with EB gradient styling.
	 */
	public function woo_cart_item_eb_badge( $cart_item, $cart_item_key = null ) {
		// Handle both cart and mini-cart signatures
		// Mini-cart passes ($cart_item_key, $cart_item) — reversed!
		// Cart passes ($cart_item, $cart_item_key)
		// Detect by checking if first param has 'booking' key (cart item array) or not (string key)
		if ( is_string( $cart_item ) && is_array( $cart_item_key ) ) {
			// Mini-cart signature: swap parameters
			$temp = $cart_item;
			$cart_item = $cart_item_key;
			$cart_item_key = $temp;
		}

		if ( empty( $cart_item['booking'] ) || ! is_array( $cart_item['booking'] ) ) {
			return;
		}

		$booking = (array) $cart_item['booking'];

		// Check if EB is applied to this item
		$eligible = ! empty( $booking[self::BK_EB_ELIGIBLE] );
		if ( ! $eligible ) {
			return;
		}

		$pct = isset( $booking[self::BK_EB_PCT] ) ? (float) $booking[self::BK_EB_PCT] : 0.0;
		$amt = isset( $booking[self::BK_EB_AMOUNT] ) ? (float) $booking[self::BK_EB_AMOUNT] : 0.0;

		// If we have a percentage, calculate the actual discount amount
		if ( $pct > 0 && $amt <= 0 ) {
			$base = isset( $booking[self::BK_EB_BASE] ) ? (float) $booking[self::BK_EB_BASE] : 0.0;
			if ( $base > 0 ) {
				$amt = Support\Money::money_round( $base * ( $pct / 100 ) );
			}
		}

		if ( $pct <= 0 && $amt <= 0 ) {
			return;
		}

		// Format the discount amount
		$amount_formatted = wc_price( $amt );

		// Multilingual label
		$label = '[:en]EB discount[:es]Descuento RA[:]';
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			$label = tc_sc_event_tr( $label );
		}

		// Output the badge - wrapped in container for positioning
		echo '<div class="tcbf-cart-item-footer">';
		echo '<div class="tcbf-cart-eb-badge">';
		echo '<span class="tcbf-cart-eb-badge__icon">⏰</span>';
		echo '<span class="tcbf-cart-eb-badge__text">';

		if ( $pct > 0 ) {
			echo esc_html( number_format_i18n( $pct, 0 ) ) . '% | ';
		}

		echo wp_kses_post( $amount_formatted ) . ' ' . esc_html( $label );
		echo '</span>';
		echo '</div>';
		echo '</div>';
	}

	private function localize_text( string $text ) : string {
		return Integrations\WooCommerce\Woo::localize_text($text);
	}

	private function localize_post_title( int $post_id ) : string {
		return Integrations\WooCommerce\Woo::localize_post_title($post_id);
	}

	private function get_event_rental_price( int $event_id, $entry, int $rental_product_id ) : float {
		return Integrations\WooCommerce\Woo::get_event_rental_price($event_id, $entry, $rental_product_id);
	}

	private function resolve_participation_product_id( int $event_id, $entry ) : int {
		return Integrations\WooCommerce\Woo::resolve_participation_product_id($event_id, $entry);
	}

	private function is_valid_participation_product( int $product_id ) : bool {
		return Integrations\WooCommerce\Woo::is_valid_participation_product($product_id);
	}

	private function find_participation_product_by_category_slugs( array $slugs ) : int {
		return Integrations\WooCommerce\Woo::find_participation_product_by_category_slugs($slugs);
	}

	// Woo_Notifications delegation
	public function gf_register_notification_events( array $events ) : array {
		return Integrations\WooCommerce\Woo_Notifications::gf_register_notification_events($events);
	}

	public function woo_fire_gf_paid_notifications( $order_id, $maybe_order = null ) : void {
		Integrations\WooCommerce\Woo_Notifications::woo_fire_gf_paid_notifications($order_id, $maybe_order);
	}

	public function woo_fire_gf_settled_notifications( $order_id, $maybe_order = null ) : void {
		Integrations\WooCommerce\Woo_Notifications::woo_fire_gf_settled_notifications($order_id, $maybe_order);
	}

	// Woo_OrderMeta delegation
	public function woo_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {
		Integrations\WooCommerce\Woo_OrderMeta::woo_checkout_create_order_line_item($item, $cart_item_key, $values, $order);
	}

	public function partner_persist_order_meta( $order, $data ) {
		Integrations\WooCommerce\Woo_OrderMeta::partner_persist_order_meta($order, $data);
	}

	public function eb_write_order_ledger( $order_id, $posted_data, $order ) {
		Integrations\WooCommerce\Woo_OrderMeta::eb_write_order_ledger($order_id, $posted_data, $order);
	}

	// ========================================================================
	// Entry State Management (Phase 2)
	// ========================================================================

	/**
	 * Set checkout guard when order is being created
	 *
	 * Prevents cart clearing during checkout from marking entries as removed.
	 *
	 * @param int   $order_id    Order ID
	 * @param array $posted_data Posted data
	 * @param object $order      WC_Order instance
	 */
	public function entry_state_set_checkout_guard( int $order_id, array $posted_data, $order ) : void {

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		// Extract entry IDs from order items and set checkout guard
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			// Try to get entry_id from pack metadata or booking meta
			$group_id = $item->get_meta( 'tc_group_id', true );
			if ( ! $group_id ) {
				// Fallback: try booking meta
				$booking = $item->get_meta( 'booking', true );
				if ( is_array( $booking ) && isset( $booking[ self::BK_ENTRY_ID ] ) ) {
					$group_id = (int) $booking[ self::BK_ENTRY_ID ];
				}
			}

			if ( $group_id > 0 ) {
				$entry_ids[] = (int) $group_id;
			}
		}

		$entry_ids = array_unique( $entry_ids );

		// Set checkout guard for all entries
		if ( class_exists( '\\TC_BF\\Integrations\\WooCommerce\\Pack_Grouping' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Integrations\WooCommerce\Pack_Grouping::mark_checkout_in_progress( $entry_id );
			}
		}

		$this->log( 'entry_state.checkout_guard.set', [
			'order_id'   => $order_id,
			'entry_ids'  => $entry_ids,
		] );
	}

	/**
	 * Mark entries as paid when payment succeeds
	 *
	 * @param int    $order_id    Order ID
	 * @param object $maybe_order Optional order object (for status hooks)
	 */
	public function entry_state_mark_paid( int $order_id, $maybe_order = null ) : void {

		if ( $order_id <= 0 ) {
			return;
		}

		$order = $maybe_order && is_object( $maybe_order ) ? $maybe_order : wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		// Only mark as paid if order is actually paid
		if ( ! $order->is_paid() && ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
			return;
		}

		// Extract entry IDs from order items
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$group_id = $item->get_meta( 'tc_group_id', true );
			if ( ! $group_id ) {
				$booking = $item->get_meta( 'booking', true );
				if ( is_array( $booking ) && isset( $booking[ self::BK_ENTRY_ID ] ) ) {
					$group_id = (int) $booking[ self::BK_ENTRY_ID ];
				}
			}

			if ( $group_id > 0 ) {
				$entry_ids[] = (int) $group_id;
			}
		}

		$entry_ids = array_unique( $entry_ids );

		// Mark entries as paid
		if ( class_exists( '\\TC_BF\\Domain\\Entry_State' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Domain\Entry_State::mark_paid( $entry_id, $order_id );
			}
		}

		// Clear checkout guards
		if ( class_exists( '\\TC_BF\\Integrations\\WooCommerce\\Pack_Grouping' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Integrations\WooCommerce\Pack_Grouping::clear_checkout_guard( $entry_id );
			}
		}

		$this->log( 'entry_state.mark_paid', [
			'order_id'   => $order_id,
			'entry_ids'  => $entry_ids,
		] );
	}

	/**
	 * Mark entries as payment_failed when order payment fails
	 *
	 * @param int    $order_id    Order ID
	 * @param object $maybe_order Optional order object
	 */
	public function entry_state_mark_payment_failed( int $order_id, $maybe_order = null ) : void {

		if ( $order_id <= 0 ) {
			return;
		}

		$order = $maybe_order && is_object( $maybe_order ) ? $maybe_order : wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$entry_ids = $this->extract_entry_ids_from_order( $order );

		if ( class_exists( '\\TC_BF\\Domain\\Entry_State' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Domain\Entry_State::mark_payment_failed( $entry_id, $order_id );
			}
		}

		$this->log( 'entry_state.payment_failed', [
			'order_id'   => $order_id,
			'entry_ids'  => $entry_ids,
		] );
	}

	/**
	 * Mark entries as cancelled when order is cancelled
	 *
	 * @param int    $order_id    Order ID
	 * @param object $maybe_order Optional order object
	 */
	public function entry_state_mark_cancelled( int $order_id, $maybe_order = null ) : void {

		if ( $order_id <= 0 ) {
			return;
		}

		$order = $maybe_order && is_object( $maybe_order ) ? $maybe_order : wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$entry_ids = $this->extract_entry_ids_from_order( $order );

		if ( class_exists( '\\TC_BF\\Domain\\Entry_State' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Domain\Entry_State::mark_cancelled( $entry_id, $order_id );
			}
		}

		$this->log( 'entry_state.cancelled', [
			'order_id'   => $order_id,
			'entry_ids'  => $entry_ids,
		] );
	}

	/**
	 * Mark entries as refunded when order is refunded
	 *
	 * @param int    $order_id    Order ID
	 * @param object $maybe_order Optional order object
	 */
	public function entry_state_mark_refunded( int $order_id, $maybe_order = null ) : void {

		if ( $order_id <= 0 ) {
			return;
		}

		$order = $maybe_order && is_object( $maybe_order ) ? $maybe_order : wc_get_order( $order_id );
		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return;
		}

		$entry_ids = $this->extract_entry_ids_from_order( $order );

		if ( class_exists( '\\TC_BF\\Domain\\Entry_State' ) ) {
			foreach ( $entry_ids as $entry_id ) {
				\TC_BF\Domain\Entry_State::mark_refunded( $entry_id, $order_id );
			}
		}

		$this->log( 'entry_state.refunded', [
			'order_id'   => $order_id,
			'entry_ids'  => $entry_ids,
		] );
	}

	/**
	 * Extract entry IDs from order items
	 *
	 * @param object $order WC_Order instance
	 * @return array Array of entry IDs
	 */
	private function extract_entry_ids_from_order( $order ) : array {

		if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
			return [];
		}

		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! method_exists( $item, 'get_meta' ) ) {
				continue;
			}

			$group_id = $item->get_meta( 'tc_group_id', true );
			if ( ! $group_id ) {
				$booking = $item->get_meta( 'booking', true );
				if ( is_array( $booking ) && isset( $booking[ self::BK_ENTRY_ID ] ) ) {
					$group_id = (int) $booking[ self::BK_ENTRY_ID ];
				}
			}

			if ( $group_id > 0 ) {
				$entry_ids[] = (int) $group_id;
			}
		}

		return array_unique( $entry_ids );
	}

}
