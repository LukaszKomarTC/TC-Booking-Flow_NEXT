<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

// Load extracted classes
require_once TC_BF_PATH . 'includes/Support/Money.php';
require_once TC_BF_PATH . 'includes/Support/Logger.php';
require_once TC_BF_PATH . 'includes/Domain/EventConfig.php';
require_once TC_BF_PATH . 'includes/Domain/Ledger.php';
require_once TC_BF_PATH . 'includes/Domain/PartnerResolver.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_Partner.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_Validation.php';
require_once TC_BF_PATH . 'includes/Integrations/GravityForms/GF_JS.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo_OrderMeta.php';
require_once TC_BF_PATH . 'includes/Integrations/WooCommerce/Woo_Notifications.php';

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
		add_filter('gform_pre_submission_filter',  [ $this, 'gf_partner_prepare_form' ], 10, 1);
		add_action('wp_footer',                    [ $this, 'gf_output_partner_js' ], 100);


		// ---- GF: submission to cart (single source of truth)
		add_action('gform_after_submission', [ $this, 'gf_after_submission_add_to_cart' ], 10, 2);

		// ---- Woo Bookings: override booking cost when we pass _custom_cost (existing behavior)
		add_filter('woocommerce_bookings_calculated_booking_cost', [ $this, 'woo_override_booking_cost' ], 11, 3);

		// ---- EB: apply snapshot EB to eligible booking cart items
		add_action('woocommerce_before_calculate_totals', [ $this, 'woo_apply_eb_snapshot_to_cart' ], 20, 1);

		// ---- Cart display: show booking meta to the customer
		add_filter('woocommerce_get_item_data', [ $this, 'woo_cart_item_data' ], 20, 2);

		// ---- Order item meta: persist booking meta to line items (your pasted snippet)
		add_action('woocommerce_checkout_create_order_line_item', [ $this, 'woo_checkout_create_order_line_item' ], 20, 4);

		// ---- Partner order meta: persist partner accounting meta (kept, but base uses snapshot if present)
		add_action('woocommerce_checkout_create_order', [ $this, 'partner_persist_order_meta' ], 25, 2);

		// ---- Ledger: write EB + partner ledger on order (snapshot-driven)
		add_action('woocommerce_checkout_order_processed', [ $this, 'eb_write_order_ledger' ], 40, 3);

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
		// ... (rest of file unchanged)
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

}
