<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

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

	// Optional “bicycle choice” concat fields in your snippet
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

	// GF partner dropdown JS payload (per request)
	private $partner_js_payload = [];

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




	/* =========================================================
	 * Debug logger
	 * ========================================================= */

	private function is_debug() : bool {
		return class_exists('TC_BF\Admin\Settings') && \TC_BF\Admin\Settings::is_debug();
	}

	private function log( string $context, array $data = [], string $level = 'info' ) : void {
		if ( ! $this->is_debug() ) return;
		$row = [
			'time'    => (string) current_time('mysql'),
			'context' => $context,
			'data'    => (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES),
		];
		\TC_BF\Admin\Settings::append_log($row, 50);
		if ( function_exists('wc_get_logger') ) {
			try {
				wc_get_logger()->log($level, $row['context'].' '.$row['data'], ['source' => 'tc-booking-flow']);
			} catch ( \Throwable $e ) {}
		}
	}

	/* =========================================================
	 * Early Booking calculation (single source of truth)
	 * ========================================================= */

	public function get_event_config( int $event_id ) : array {

		// NOTE: This config is per sc_event. Rules are stored as JSON (schema v1) in META_EB_RULES_JSON.
		// We keep backwards compatibility with the earlier "[{days,pct},...]" array format.
		$cfg = [
			'enabled' => false,
			'participation_enabled' => true,
			'rental_enabled'        => true,
			'version' => 1,
			'global_cap' => [ 'enabled' => false, 'amount' => 0.0 ],
			'steps' => [],
		];

		$enabled = get_post_meta($event_id, self::META_EB_ENABLED, true);
		if ( $enabled !== '' ) {
			$val = strtolower(trim((string)$enabled));
			$cfg['enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		// Legacy cap meta (deprecated): if present we interpret it as a GLOBAL cap amount (in currency).
		$cap = get_post_meta($event_id, self::META_EB_CAP, true);
		if ( $cap !== '' && is_numeric($cap) ) {
			$cfg['global_cap'] = [ 'enabled' => true, 'amount' => (float) $cap ];
		}

		$rules_json = (string) get_post_meta($event_id, self::META_EB_RULES_JSON, true);
		if ( $rules_json !== '' ) {
			$decoded = json_decode($rules_json, true);
			if ( is_array($decoded) ) {
				// Schema v1: object with steps.
				if ( isset($decoded['steps']) && is_array($decoded['steps']) ) {
					$cfg['version'] = isset($decoded['version']) ? (int) $decoded['version'] : 1;
					if ( isset($decoded['global_cap']) && is_array($decoded['global_cap']) ) {
						$cfg['global_cap']['enabled'] = ! empty($decoded['global_cap']['enabled']);
						$cfg['global_cap']['amount']  = isset($decoded['global_cap']['amount']) ? (float) $decoded['global_cap']['amount'] : 0.0;
					}
					$steps = [];
					foreach ( $decoded['steps'] as $s ) {
						if ( ! is_array($s) ) continue;
						$min = isset($s['min_days_before']) ? (int) $s['min_days_before'] : 0;
						$type = isset($s['type']) ? strtolower((string) $s['type']) : 'percent';
						$value = isset($s['value']) ? (float) $s['value'] : 0.0;
						if ( $min < 0 || $value <= 0 ) continue;
						if ( ! in_array($type, ['percent','fixed'], true) ) $type = 'percent';
						$cap_s = [ 'enabled' => false, 'amount' => 0.0 ];
						if ( isset($s['cap']) && is_array($s['cap']) ) {
							$cap_s['enabled'] = ! empty($s['cap']['enabled']);
							$cap_s['amount']  = isset($s['cap']['amount']) ? (float) $s['cap']['amount'] : 0.0;
						}
						$steps[] = [
							'min_days_before' => $min,
							'type' => $type,
							'value' => $value,
							'cap' => $cap_s,
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				} else {
					// Legacy array: [ {days,pct}, ... ]
					$steps = [];
					foreach ( $decoded as $row ) {
						if ( ! is_array($row) ) continue;
						if ( ! isset($row['days'], $row['pct']) ) continue;
						if ( ! is_numeric($row['days']) || ! is_numeric($row['pct']) ) continue;
						$steps[] = [
							'min_days_before' => (int) $row['days'],
							'type' => 'percent',
							'value' => (float) $row['pct'],
							'cap' => [ 'enabled' => false, 'amount' => 0.0 ],
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				}
			}
		}

		$p = get_post_meta($event_id, self::META_EB_PARTICIPATION_ENABLED, true);
		if ( $p !== '' ) {
			$val = strtolower(trim((string)$p));
			$cfg['participation_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		$r = get_post_meta($event_id, self::META_EB_RENTAL_ENABLED, true);
		if ( $r !== '' ) {
			$val = strtolower(trim((string)$r));
			$cfg['rental_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		return $cfg;
	}

	private function select_eb_step( int $days_before, array $steps ) : array {
		if ( ! $steps ) return [];
		// steps already sorted desc by min_days_before
		foreach ( $steps as $s ) {
			$min = (int) ($s['min_days_before'] ?? 0);
			if ( $days_before >= $min ) return (array) $s;
		}
		return [];
	}

	private function compute_eb_amount( float $base_total, array $step, array $global_cap ) : array {
		$base_total = max(0.0, $base_total);
		if ( $base_total <= 0 || ! $step ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$type = strtolower((string)($step['type'] ?? 'percent'));
		$value = (float) ($step['value'] ?? 0.0);
		if ( $value <= 0 ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$amount = 0.0;
		if ( $type === 'fixed' ) {
			$amount = $value;
		} else {
			$amount = $base_total * ($value / 100);
		}
		// Step cap (amount)
		if ( isset($step['cap']) && is_array($step['cap']) && ! empty($step['cap']['enabled']) ) {
			$cap_amt = (float) ($step['cap']['amount'] ?? 0.0);
			if ( $cap_amt > 0 ) $amount = min($amount, $cap_amt);
		}
		// Global cap (amount)
		if ( isset($global_cap['enabled']) && $global_cap['enabled'] ) {
			$g = (float) ($global_cap['amount'] ?? 0.0);
			if ( $g > 0 ) $amount = min($amount, $g);
		}
		$amount = min($amount, $base_total);
		$effective_pct = $base_total > 0 ? (($amount / $base_total) * 100) : 0.0;
		return [ 'amount' => (float) $amount, 'effective_pct' => (float) $effective_pct ];
	}

	public function calculate_for_event( int $event_id ) : array {

		if ( $event_id <= 0 ) return ['enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>[]];

		if ( isset($this->calc_cache[$event_id]) ) return $this->calc_cache[$event_id];

		$cfg = $this->get_event_config($event_id);
		if ( empty($cfg['enabled']) ) {
			return $this->calc_cache[$event_id] = [
				'enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>$cfg
			];
		}

		$start_ts = 0;
		// canonical helper from your system
		if ( function_exists('tc_sc_event_dates') ) {
			$d = tc_sc_event_dates($event_id);
			if ( is_array($d) && ! empty($d['start_ts']) ) $start_ts = (int) $d['start_ts'];
		}
		// fallback
		if ( $start_ts <= 0 ) $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);

		$now_ts = (int) current_time('timestamp'); // site TZ
		$days_before = 0;
		if ( $start_ts > 0 ) {
			$days_before = (int) floor( ($start_ts - $now_ts) / DAY_IN_SECONDS );
			if ( $days_before < 0 ) $days_before = 0;
		}

		$step = $this->select_eb_step($days_before, (array) ($cfg['steps'] ?? []));
		// For GF display we only expose percent steps. Fixed discounts are handled server-side.
		$pct = 0.0;
		if ( $step && strtolower((string)($step['type'] ?? 'percent')) === 'percent' ) {
			$pct = (float) ($step['value'] ?? 0.0);
			if ( $pct < 0 ) $pct = 0.0;
		}

		return $this->calc_cache[$event_id] = [
			'enabled'=>true,
			'pct'=>(float)$pct,
			'days_before'=>$days_before,
			'event_start_ts'=>$start_ts,
			'cfg'=>$cfg,
			'step'=>$step,
		];
	}

	/* =========================================================
	 * GF helpers (dedupe + EB population)
	 * ========================================================= */

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



	/**
	 * GF: Populate partner fields + inject JS for admin override dropdown (field 63).
	 *
	 * Active form id comes from Admin Settings (so clones like 47 work).
	 */
	public function gf_partner_prepare_form( $form ) {
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = (int) \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return $form;

		// Populate hidden partner fields into POST so GF calculations + conditional logic can use them.
		$this->gf_partner_prepare_post( $form_id );

		// Build partner map for JS (code => data)
		$partners = $this->get_partner_map_for_js();

		// Determine initial partner code from context (admin override > logged-in partner > posted code)
		$ctx = $this->resolve_partner_context( $form_id );
		$initial_code = ( ! empty($ctx) && ! empty($ctx['active']) && ! empty($ctx['code']) ) ? (string) $ctx['code'] : '';

		// Cache payload for footer output.
		$this->partner_js_payload[ $form_id ] = [
			'partners'     => $partners,
			'initial_code' => $initial_code,
		];

		// Also register an init script so this works even when GF renders via AJAX.
		$this->gf_register_partner_init_script( $form_id, $partners, $initial_code );

		return $form;
	}

	/**
	 * Server-side: resolve partner context and write hidden inputs into POST.
	 * This is required for GF calculation fields (152/154/161/153/166) to compute before submit.
	 */
	private function gf_partner_prepare_post( int $form_id ) : void {

		$ctx = $this->resolve_partner_context( $form_id );
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
		$_POST['input_152'] = (string) $this->pct_to_gf_str( (float) ($ctx['discount_pct'] ?? 0) );
		$_POST['input_161'] = (string) $this->pct_to_gf_str( (float) ($ctx['commission_pct'] ?? 0) );
		$_POST['input_153'] = (string) ($ctx['partner_email'] ?? '');
		$_POST['input_166'] = (string) ((int) ($ctx['partner_user_id'] ?? 0));
	}

	/**
	 * Priority rule:
	 * 1) Admin override field 63 (string partner code like "bondia")
	 * 2) Logged-in partner user meta (discount__code)
	 * 3) Existing posted coupon code field 154 (manual)
	 */
	private function resolve_partner_context( int $form_id ) : array {

		$override_code = isset($_POST['input_63']) ? trim((string) $_POST['input_63']) : '';
		$override_code = $this->normalize_partner_code( $override_code );

		// 1) Admin override wins (only if current user is admin).
		if ( $override_code !== '' && current_user_can('administrator') ) {
			return $this->build_partner_context_from_code( $override_code );
		}

		// 2) Logged-in partner user (discount__code).
		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );
			$code = $this->normalize_partner_code( $code_raw );
			if ( $code !== '' ) {
				return $this->build_partner_context_from_code( $code, $user_id );
			}
		}

		// 3) Manual/posted coupon field 154 (if already present).
		$posted_code = isset($_POST['input_' . self::GF_FIELD_COUPON_CODE]) ? trim((string) $_POST['input_' . self::GF_FIELD_COUPON_CODE]) : '';
		$posted_code = $this->normalize_partner_code( $posted_code );
		if ( $posted_code !== '' ) {
			return $this->build_partner_context_from_code( $posted_code );
		}

		return [ 'active' => false ];
	}

	private function normalize_partner_code( string $code ) : string {
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
	private function build_partner_context_from_code( string $code, int $known_user_id = 0 ) : array {

		$code = $this->normalize_partner_code( $code );
		if ( $code === '' ) return [ 'active' => false ];

		$user_id = $known_user_id;
		if ( $user_id <= 0 ) {
			$user_id = $this->find_partner_user_id_by_code( $code );
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

		$discount_pct = $this->get_coupon_percent_amount( $code );

		return [
			'active'          => ($discount_pct > 0 || $commission_pct > 0 || $user_id > 0),
			'code'            => $code,
			'discount_pct'    => $discount_pct,
			'commission_pct'  => $commission_pct,
			'partner_email'   => $partner_email,
			'partner_user_id' => $user_id,
		];
	}

	private function find_partner_user_id_by_code( string $code ) : int {
		$code = $this->normalize_partner_code( $code );
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

	private function get_coupon_percent_amount( string $code ) : float {
		$code = $this->normalize_partner_code( $code );
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

	private function get_partner_map_for_js() : array {

		$map = [];

		$uq = new \WP_User_Query([
			'number'     => 200,
			'fields'     => ['ID','user_email'],
			'meta_query' => [
				[
					'key'     => 'discount__code',
					'compare' => 'EXISTS',
				]
			]
		]);
		$users = $uq->get_results();
		if ( ! is_array($users) ) $users = [];

		foreach ( $users as $u ) {
			$uid = (int) (is_object($u) && isset($u->ID) ? $u->ID : 0);
			if ( $uid <= 0 ) continue;
			$code = (string) get_user_meta( $uid, 'discount__code', true );
			$code = $this->normalize_partner_code( $code );
			if ( $code === '' ) continue;

			$commission = (float) get_user_meta( $uid, 'usrdiscount', true );
			if ( $commission < 0 ) $commission = 0.0;

			$discount = $this->get_coupon_percent_amount( $code );

			$map[ $code ] = [
				'id'         => $uid,
				'email'      => (string) (is_object($u) && isset($u->user_email) ? $u->user_email : ''),
				'commission' => $commission,
				'discount'   => $discount,
			];
		}

		return $map;
	}

	
	private function gf_register_partner_init_script( int $form_id, array $partners, string $initial_code = '' ) : void {
		if ( $form_id <= 0 ) return;
		if ( ! class_exists('\GFFormDisplay') ) return;

		$script = $this->build_partner_override_js( $form_id, $partners, $initial_code );
		if ( $script === '' ) return;

		// Runs reliably for normal and AJAX-rendered forms.
		\GFFormDisplay::add_init_script(
			$form_id,
			'tc_bf_partner_override_' . $form_id,
			\GFFormDisplay::ON_PAGE_RENDER,
			$script
		);
	}

	private function build_partner_override_js( int $form_id, array $partners, string $initial_code = '' ) : string {

		// Map: { code => {id,email,commission,discount} }
		$json = wp_json_encode( $partners );

		// IMPORTANT: this is raw JS (no <script> wrapper). GF will wrap it.
		return "window.tcBfPartnerMap = window.tcBfPartnerMap || {};\n"
			. "window.tcBfPartnerMap[{$form_id}] = {$json};\n"
			. "(function(){\n"
			. "  var fid = {$form_id};\n"
			. "  var initialCode = '" . esc_js( $initial_code ) . "';\n"
			. "  function qs(sel,root){ return (root||document).querySelector(sel); }\n"
			. "  function parseLocaleFloat(v){\n"
			. "    if(v===null||typeof v==='undefined') return 0;\n"
			. "    if(typeof v==='string') v = v.replace(',', '.');\n"
			. "    var n = parseFloat(v);\n"
			. "    return isNaN(n) ? 0 : n;\n"
			. "  }\n"
			. "  function fmtPct(v){\n"
			. "    if(v===null||typeof v==='undefined') return '';\n"
			. "    var s = String(v).trim();\n"
			. "    if(!s) return '';\n"
			. "    // Gravity Forms uses decimal_comma on this site; feed percentages as 7,5 not 7.5\n"
			. "    if(s.indexOf(',') !== -1) return s;\n"
			. "    if(s.indexOf('.') !== -1) return s.replace('.', ',');\n"
			. "    return s;\n"
			. "  }\n"
			. "  function setVal(fieldId, val){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(!el) return;\n"
			. "    el.value = (val===null||typeof val==='undefined') ? '' : String(val);\n"
			. "    try{ el.dispatchEvent(new Event('change', {bubbles:true})); }catch(e){}\n"
			. "  }\n"
			. "  function showField(fieldId){\n"
			. "    var wrap = qs('#field_'+fid+'_'+fieldId);\n"
			. "    if(wrap){ wrap.style.display=''; wrap.setAttribute('data-conditional-logic','visible'); }\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(el){ el.disabled=false; }\n"
			. "  }\n"
			. "  function hideField(fieldId){\n"
			. "    var wrap = qs('#field_'+fid+'_'+fieldId);\n"
			. "    if(wrap){ wrap.style.display='none'; wrap.setAttribute('data-conditional-logic','hidden'); }\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(el){ el.disabled=true; }\n"
			. "  }\n"
			. "  function toggleSummary(data, code){\n"
			. "    var summary = qs('#field_'+fid+'_177 .tc-bf-price-summary');\n"
			. "    if(!summary) return;\n"
			. "    var ebPct = parseLocaleFloat((qs('#input_'+fid+'_172')||{}).value||0);\n"
			. "    var ebLine = qs('.tc-bf-eb-line', summary);\n"
			. "    if(ebLine) ebLine.style.display = (ebPct>0) ? '' : 'none';\n"
			. "    var pLine = qs('.tc-bf-partner-line', summary);\n"
			. "    if(pLine) pLine.style.display = (data && code) ? '' : 'none';\n"
			. "    var commPct = parseLocaleFloat((qs('#input_'+fid+'_161')||{}).value||0);\n"
			. "    var cLine = qs('.tc-bf-commission', summary);\n"
			. "    if(cLine) cLine.style.display = (commPct>0 && data && code) ? '' : 'none';\n"
			. "  }\n"
			. "  function applyPartner(){\n"
			. "    var map = (window.tcBfPartnerMap && window.tcBfPartnerMap[fid]) ? window.tcBfPartnerMap[fid] : {};\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    var code = '';\n"
			. "    if(sel){\n"
			. "      // Field 63 exists -> admin selection is authoritative (including empty = Direct/admin).\n"
			. "      code = (sel.value||'').toString().trim();\n"
			. "    } else {\n"
			. "      // No override select -> fall back to hidden coupon/initial context.\n"
			. "      var codeEl = qs('#input_'+fid+'_154'); if(codeEl) code = (codeEl.value||'').toString().trim();\n"
			. "      if(!code && initialCode){ code = (initialCode||'').toString().trim(); }\n"
			. "    }\n"
			. "    // If admin override select exists and is empty, set it for consistency (best effort).\n"
			. "    if(sel && code && sel.value !== code){ try{ sel.value = code; }catch(e){} }\n"
			. "    var data = (code && map && map[code]) ? map[code] : null;\n"
			. "    if(!data){\n"
			. "      setVal(154,''); setVal(152,''); setVal(161,''); setVal(153,''); setVal(166,'');\n"
			. "      hideField(176); hideField(165);\n"
			. "    } else {\n"
			. "      setVal(154,code);\n"
			. "      setVal(152, fmtPct(data.discount||''));\n"
			. "      setVal(161, fmtPct(data.commission||''));\n"
			. "      setVal(153,(data.email||''));\n"
			. "      setVal(166,(data.id||''));\n"
			. "      showField(176); showField(165);\n"
			. "    }\n"
			. "    toggleSummary(data, code);\n"
			. "    if(typeof window.gformCalculateTotalPrice === 'function'){\n"
			. "      try{ window.gformCalculateTotalPrice(fid); }catch(e){}\n"
			. "    }\n"
			. "  }\n"
			. "  function bind(){\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    if(sel && !sel.__tcBfBound){\n"
			. "      sel.__tcBfBound = true;\n"
			. "      sel.addEventListener('change', applyPartner);\n"
			. "    }\n"
			. "    // Always apply once (supports logged-in partner context even if field 63 is hidden/absent).\n"
			. "    applyPartner();\n"
			. "    return true;\n"
			. "  }\n"
			. "  // Try now, then retry a few times.\n"
			. "  var tries = 0;\n"
			. "  (function loop(){\n"
			. "    if(bind()) return;\n"
			. "    tries++; if(tries<20) setTimeout(loop, 250);\n"
			. "  })();\n"
			. "  // Also watch for late DOM injection (popups, AJAX embeds).\n"
			. "  if(window.MutationObserver){\n"
			. "    try{\n"
			. "      var mo = new MutationObserver(function(){ bind(); });\n"
			. "      mo.observe(document.body, {childList:true, subtree:true});\n"
			. "    }catch(e){}\n"
			. "  }\n"
			. "})();\n";
	}

public function gf_output_partner_js() : void {

		if ( empty( $this->partner_js_payload ) ) return;
		if ( is_admin() ) return;

		foreach ( $this->partner_js_payload as $form_id => $payload ) {
			$form_id = (int) $form_id;
			if ( $form_id <= 0 ) continue;

			$partners = (is_array($payload) && isset($payload['partners']) && is_array($payload['partners'])) ? $payload['partners'] : [];
			$initial_code = (is_array($payload) && isset($payload['initial_code'])) ? (string) $payload['initial_code'] : '';

			$js = $this->build_partner_override_js( $form_id, $partners, $initial_code );
			if ( $js === '' ) continue;

			echo "\n<script id=\"tc-bf-partner-override-{$form_id}\">\n";
			echo $js;
			echo "\n</script>\n";
		}
	}

	private function float_to_str( float $v ) : string {
		return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
	}

	/**
	 * Percent values must be fed into Gravity Forms calculation fields using the site locale.
	 *
	 * In a decimal-comma locale, Gravity Forms may interpret a dot as a thousands separator.
	 * Example: "7.5" can be parsed as "75" which turns a 7.5% partner discount into 75%.
	 *
	 * We therefore output percentages as a decimal-comma string ("7,5") for GF fields.
	 */
	private function pct_to_gf_str( float $v ) : string {
		$s = $this->float_to_str( $v );
		return (strpos($s, '.') !== false) ? str_replace('.', ',', $s) : $s;
	}


	public function gf_populate_eb_pct( $value ) {

		if ( ! is_singular('sc_event') ) return $value;

		$event_id = (int) get_queried_object_id();
		$calc = $this->calculate_for_event($event_id);

		// IMPORTANT: return plain integer percent (e.g. "15"), not "15.00"
    	return (string) ((int) round((float) ($calc['pct'] ?? 0)));
	}

	public function gf_validation( array $validation_result ) : array {
		$form = isset($validation_result['form']) ? $validation_result['form'] : null;
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return $validation_result;

		// Basic required field: event_id
		$event_id = isset($_POST['input_' . self::GF_FIELD_EVENT_ID]) ? (int) $_POST['input_' . self::GF_FIELD_EVENT_ID] : 0;
		if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) {
			$validation_result['is_valid'] = false;
			$validation_result['form'] = $this->gf_mark_field_invalid($form, self::GF_FIELD_EVENT_ID, __('Invalid event. Please reload the event page and try again.', 'tc-booking-flow'));
			return $validation_result;
		}

		// =========================================================
		// Validation (ledger-first, deterministic)
		// =========================================================
		// GF calculated money fields are DISPLAY ONLY. We recompute server-side ledger totals from intent inputs,
		// then compare against the submitted client totals to prevent JS/locale drift and tampering.
		//
		// Requirements (current flow):
		// - Field 79 must match field 168 (both client-facing totals)
		// - Both must match the PHP ledger client total (cart/order parity), including EB + partner discount
		//
		// IMPORTANT: Never parse money with (float) in a decimal-comma locale. Always use money_to_float().
		$part_price = $this->money_to_float( get_post_meta($event_id, 'event_price', true) );
		if ( $part_price < 0 ) $part_price = 0.0;

		// Determine rental price (fixed per-event) based on rental type select (106)
		// or, as a fallback, based on which bike choice field has a value.
		$rental_raw = isset($_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) ? trim((string) $_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) : '';
		$meta_key   = '';
		if ( $rental_raw !== '' ) {
			$rt = strtoupper($rental_raw);
			if ( strpos($rt, 'ROAD') === 0 )        $meta_key = 'rental_price_road';
			elseif ( strpos($rt, 'MTB') === 0 )     $meta_key = 'rental_price_mtb';
			elseif ( strpos($rt, 'EMTB') === 0 )    $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E-MTB') === 0 )   $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E MTB') === 0 )   $meta_key = 'rental_price_ebike';
			elseif ( strpos($rt, 'GRAVEL') === 0 )  $meta_key = 'rental_price_gravel';
		}

		// Fallback: detect from selected bike field
		if ( $meta_key === '' ) {
			if ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_130]) )      $meta_key = 'rental_price_road';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_142]) ) $meta_key = 'rental_price_mtb';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_143]) ) $meta_key = 'rental_price_ebike';
			elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_169]) ) $meta_key = 'rental_price_gravel';
		}

		$rental_price = 0.0;
		if ( $meta_key !== '' ) {
			$rental_price = $this->money_to_float( get_post_meta($event_id, $meta_key, true) );
		}
		if ( $rental_price < 0 ) $rental_price = 0.0;

		// ---- Build ledger totals (same rounding model as order ledger) ----
		$calc = $this->calculate_for_event($event_id);
		$cfg  = (is_array($calc) && isset($calc['cfg']) && is_array($calc['cfg'])) ? (array) $calc['cfg'] : [];
		$eb_step = (is_array($calc) && isset($calc['step']) && is_array($calc['step'])) ? (array) $calc['step'] : [];

		$subtotal_original = $this->money_round( $part_price + $rental_price );

		// EB applies only to enabled scopes (participation/rental) and only when a step is active.
		$eb_base = 0.0;
		if ( ! empty($cfg['enabled']) ) {
			if ( ! empty($cfg['participation_enabled']) ) $eb_base += $part_price;
			if ( ! empty($cfg['rental_enabled']) )        $eb_base += $rental_price;
		}
		$eb_base = $this->money_round( $eb_base );

		$eb_amount = 0.0;
		$eb_pct_effective = 0.0;
		if ( $eb_base > 0 && ! empty($cfg['enabled']) && ! empty($eb_step) ) {
			$comp = $this->compute_eb_amount( (float) $eb_base, (array) $eb_step, (array) ($cfg['global_cap'] ?? []) );
			$eb_amount = (float) ($comp['amount'] ?? 0.0);
			$eb_pct_effective = (float) ($comp['effective_pct'] ?? 0.0);
		}
		$eb_amount = $this->money_round( min($eb_base, max(0.0, $eb_amount)) );

		// Partner discount (percentage) — resolved deterministically from context.
		// Ensure hidden partner fields are written into POST (prevents stale/missing context).
		$this->gf_partner_prepare_post( $form_id );
		$ctx = $this->resolve_partner_context( $form_id );
		$partner_discount_pct = (float) (is_array($ctx) ? ($ctx['discount_pct'] ?? 0.0) : 0.0);
		if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0.0;

		$partner_base_total = $this->money_round( max(0.0, $subtotal_original - $eb_amount) );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = $this->money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$expected_client_total = $this->money_round( max(0.0, $partner_base_total - $client_discount) );

		// ---- Read posted client totals (display fields) ----
		$raw_79  = $_POST['input_' . self::GF_FIELD_CLIENT_TOTAL_A] ?? '';
		$raw_168 = $_POST['input_' . self::GF_FIELD_CLIENT_TOTAL_B] ?? '';

		$posted_79  = $this->money_round( $this->money_to_float( $raw_79 ) );
		$posted_168 = $this->money_round( $this->money_to_float( $raw_168 ) );

		// Fallback: if those fields are not present in the active form, fall back to legacy TOTAL (76).
		$legacy_raw = $_POST['input_' . self::GF_FIELD_TOTAL] ?? '';
		$posted_legacy = $this->money_round( $this->money_to_float( $legacy_raw ) );

		$have_79  = ($raw_79 !== '');
		$have_168 = ($raw_168 !== '');

		// Choose primary posted value (prefer 79, then 168, then legacy 76).
		$posted_primary = $have_79 ? $posted_79 : ($have_168 ? $posted_168 : $posted_legacy);
		$primary_field_id = $have_79 ? self::GF_FIELD_CLIENT_TOTAL_A : ($have_168 ? self::GF_FIELD_CLIENT_TOTAL_B : self::GF_FIELD_TOTAL);

		// ---- Comparisons (tolerance 0.02) ----
		$tol = 0.02;

		// 1) Internal form consistency (79 vs 168) — hard fail when both exist.
		if ( $have_79 && $have_168 && abs($posted_79 - $posted_168) > $tol ) {
			$this->log('gf.validation.total_inconsistent', [
				'event_id'      => $event_id,
				'field_79_raw'  => (string) $raw_79,
				'field_168_raw' => (string) $raw_168,
				'field_79'      => $posted_79,
				'field_168'     => $posted_168,
				'diff'          => $this->money_round( abs($posted_79 - $posted_168) ),
			], 'warning');
			$validation_result['is_valid'] = false;
			$validation_result['form'] = $this->gf_mark_field_invalid($form, self::GF_FIELD_CLIENT_TOTAL_A, __('Total: Values in the form are out of sync. Please wait for totals to update, then submit again.', 'tc-booking-flow'));
			return $validation_result;
		}

		// 2) Ledger parity (posted total vs expected ledger client total)
		if ( $posted_primary > 0 && abs($posted_primary - $expected_client_total) > $tol ) {

			$this->log('gf.validation.total_mismatch', [
				'event_id'              => $event_id,
				'part_price'            => $this->money_round($part_price),
				'rental_price'          => $this->money_round($rental_price),
				'subtotal_original'     => $subtotal_original,
				'eb_base'               => $eb_base,
				'eb_amount'             => $eb_amount,
				'eb_effective_pct'      => $this->money_round($eb_pct_effective),
				'partner_discount_pct'  => $this->money_round($partner_discount_pct),
				'partner_base_total'    => $partner_base_total,
				'client_discount'       => $client_discount,
				'expected_client_total' => $expected_client_total,
				'posted_primary_field'  => $primary_field_id,
				'posted_primary_raw'    => $have_79 ? (string)$raw_79 : ($have_168 ? (string)$raw_168 : (string)$legacy_raw),
				'posted_primary'        => $posted_primary,
				'diff'                  => $this->money_round( abs($posted_primary - $expected_client_total) ),
				'rental_type_raw'       => (string) $rental_raw,
				'rental_meta_key'       => (string) $meta_key,
			], 'warning');

			$validation_result['is_valid'] = false;
			$validation_result['form'] = $this->gf_mark_field_invalid($form, $primary_field_id, __('Total: Price mismatch. Please refresh the page and submit again.', 'tc-booking-flow'));
			return $validation_result;
		}

		// 3) Self-heal: if totals are empty (rare), write expected total into legacy field to avoid downstream zero.
		if ( $posted_primary <= 0 && $expected_client_total > 0 ) {
			$_POST['input_' . self::GF_FIELD_TOTAL] = wc_format_decimal($expected_client_total, 2);
		}



		// Rental consistency: if any bike choice is present, require product_id + resource_id.
		$bike_raw = '';
		foreach ( [self::GF_FIELD_BIKE_130, self::GF_FIELD_BIKE_142, self::GF_FIELD_BIKE_143, self::GF_FIELD_BIKE_169] as $fid ) {
			$k = 'input_' . $fid;
			if ( ! empty($_POST[$k]) ) { $bike_raw = (string) $_POST[$k]; break; }
		}
		if ( $bike_raw !== '' ) {
			$parts = explode('_', $bike_raw);
			$pid = isset($parts[0]) ? (int) $parts[0] : 0;
			$rid = isset($parts[1]) ? (int) $parts[1] : 0;
			if ( $pid <= 0 || $rid <= 0 ) {
				$validation_result['is_valid'] = false;
				$validation_result['form'] = $this->gf_mark_field_invalid($form, self::GF_FIELD_RENTAL_TYPE, __('Invalid bicycle selection. Please reselect your bicycle and try again.', 'tc-booking-flow'));
				return $validation_result;
			}
		}

		$validation_result['form'] = $form;
		return $validation_result;
	}

	private function gf_mark_field_invalid( $form, int $field_id, string $message ) {
		if ( ! is_array($form) || empty($form['fields']) ) return $form;
		foreach ( $form['fields'] as &$field ) {
			$fid = (int) (is_object($field) ? $field->id : (is_array($field) ? ($field['id'] ?? 0) : 0));
			if ( $fid === $field_id ) {
				if ( is_object($field) ) {
					$field->failed_validation = true;
					$field->validation_message = $message;
				} elseif ( is_array($field) ) {
					$field['failed_validation'] = true;
					$field['validation_message'] = $message;
				}
				break;
			}
		}
		return $form;
	}

	/* =========================================================
	 * GF → Cart
	 * ========================================================= */

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

		// Determine “with rental” based on your current bike choice concat
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
		 * Your current snippet uses “tour product” as the booking product (and even uses bike resource on it).
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
		}

		// Mark GF entry only if we added at least the participation line
		if ( $added_part ) {
			$this->gf_entry_mark_cart_added($entry_id);
		}
	}

	/* =========================================================
	 * GF notifications (parity with legacy snippets)
	 * ========================================================= */

	/**
	 * Register custom GF notification events.
	 * Legacy key: WC___paid
	 */
	public function gf_register_notification_events( array $events ) : array {
		$events['WC___paid']    = __( 'Woocommerce payment confirmed', 'tc-booking-flow' );
		$events['WC___settled'] = __( 'Reservation confirmed (invoice/offline)', 'tc-booking-flow' );
		return $events;
	}

	/**
	 * Fire GF notifications when Woo payment is confirmed.
	 *
	 * Hooks:
	 * - woocommerce_payment_complete (order id)
	 * - woocommerce_order_status_processing/completed (order id, order)
	 */
	public function woo_fire_gf_paid_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// Avoid duplicate sends.
		$sent_flag = (string) get_post_meta( $order_id, '_tc_gf_paid_notifs_sent', true );
		if ( $sent_flag === '1' ) return;

		if ( ! class_exists('GFAPI') ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Gather GF entry ids from line items.
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );
		if ( ! $entry_ids ) return;

		$did_any = false;
		foreach ( $entry_ids as $entry_id ) {
			try {
				$entry = \GFAPI::get_entry( (int) $entry_id );
				if ( is_wp_error($entry) || ! is_array($entry) ) continue;
				$form_id = (int) rgar( $entry, 'form_id' );
				if ( $form_id <= 0 ) {
					$form_id = (int) \TC_BF\Admin\Settings::get_form_id();
				}
				if ( $form_id <= 0 ) continue;

				$form = \GFAPI::get_form( $form_id );
				if ( ! is_array($form) || empty($form['id']) ) continue;

				// Send custom notifications.
				\GFAPI::send_notifications( $form, $entry, 'WC___paid' );
				$did_any = true;
			} catch ( \Throwable $e ) {
				$this->log('gf.notif.wc_paid.exception', [
					'order_id' => $order_id,
					'entry_id' => (int) $entry_id,
					'err'      => $e->getMessage(),
				], 'error');
			}
		}

		if ( $did_any ) {
			update_post_meta( $order_id, '_tc_gf_paid_notifs_sent', '1' );
			$this->log('gf.notif.wc_paid.sent', ['order_id'=>$order_id,'entry_ids'=>$entry_ids]);
		}
	}

	/**
	 * Fire GF notifications when an order is confirmed via invoice/offline settlement.
	 *
	 * Hook: woocommerce_order_status_invoiced (order id, order)
	 */
	public function woo_fire_gf_settled_notifications( $order_id, $maybe_order = null ) : void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) return;

		// De-dupe: send once per order.
		if ( get_post_meta( $order_id, '_tc_gf_settled_notifs_sent', true ) ) return;

		$order = $maybe_order;
		if ( ! $order || ! is_object($order) || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) return;

		// Gather GF entry ids from line items.
		$entry_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object($item) || ! method_exists($item, 'get_meta') ) continue;
			$eid = (int) $item->get_meta( '_gf_entry_id', true );
			if ( $eid > 0 ) $entry_ids[] = $eid;
		}
		$entry_ids = array_values( array_unique( array_filter( $entry_ids ) ) );
		if ( empty( $entry_ids ) ) return;

		if ( ! class_exists('GFAPI') ) return;

		foreach ( $entry_ids as $eid ) {
			$entry = \GFAPI::get_entry( $eid );
			if ( is_wp_error($entry) || empty($entry) ) continue;

			$form = \GFAPI::get_form( (int)$entry['form_id'] );
			if ( empty($form) ) continue;

			\GFAPI::send_notifications( $form, $entry, 'WC___settled' );
		}

		update_post_meta( $order_id, '_tc_gf_settled_notifs_sent', 1 );
		$this->log('gf.settled_notifs.sent', [ 'order_id' => $order_id, 'entry_ids' => $entry_ids ]);
	}


	/**
	 * Resolve participation product ID.
	 * Keep your existing mapping logic here (categories → product IDs, rental/no rental, etc.).
	 *
	 * For now, this method returns the legacy tour product id if you stored it on the event,
	 * or 0 if nothing is available.
	 */
	private function resolve_participation_product_id( int $event_id, $entry ) : int {

	// 1) Explicit per-event override (strongest)
	$pid = (int) get_post_meta($event_id, 'tc_participation_product_id', true);
	if ( $pid > 0 && $this->is_valid_participation_product($pid) ) {
		$this->log('resolver.participation.override_event', ['event_id'=>$event_id,'product_id'=>$pid]);
		return $pid;
	}

	// 2) Legacy fallback (if you stored a general product_id)
	$pid = (int) get_post_meta($event_id, 'tc_product_id', true);
	if ( $pid > 0 && $this->is_valid_participation_product($pid) ) {
		$this->log('resolver.participation.override_legacy_meta', ['event_id'=>$event_id,'product_id'=>$pid]);
		return $pid;
	}

	// 3) Category slug → participation product meta mapping (recommended)
	$slugs = wp_get_post_terms( (int) $event_id, 'sc_event_category', [ 'fields' => 'slugs' ] );
	if ( is_wp_error($slugs) ) $slugs = [];
	$slugs = array_values(array_filter(array_map('strval', (array)$slugs)));

	if ( $slugs ) {
		$mapped = $this->find_participation_product_by_category_slugs($slugs);
		if ( $mapped > 0 ) { $this->log('resolver.participation.category_mapped', ['event_id'=>$event_id,'product_id'=>$mapped,'slugs'=>$slugs]); return $mapped; }
	}

	// 4) Legacy hardcoded fallback mapping (safe during transition).
	// NOTE: In the new model, rental does NOT affect participation product selection.
	$map = apply_filters('tc_bf_participation_product_map', [
		// Canonical "no rental" participation products:
		'guided' => 37916,
		'tdg'    => 48161,
	], $event_id, $slugs);

	$tdg_slugs    = apply_filters('tc_bf_tdg_category_slugs',    [ 'tour_de_girona' ]);
	$guided_slugs = apply_filters('tc_bf_guided_category_slugs', [ 'salidas_guiadas' ]);

	$is_tdg = (bool) array_intersect( (array) $tdg_slugs, (array) $slugs );
	$type   = $is_tdg ? 'tdg' : 'guided';

	if ( isset($map[$type]) && (int) $map[$type] > 0 && $this->is_valid_participation_product((int)$map[$type]) ) {
		$this->log('resolver.participation.legacy_map', ['event_id'=>$event_id,'type'=>$type,'product_id'=>(int)$map[$type],'slugs'=>$slugs]);
		return (int) $map[$type];
	}

	$this->log('resolver.participation.not_found', ['event_id'=>$event_id,'slugs'=>$slugs]);
	return 0;
}

	/* =========================================================
	 * Woo Bookings + Woo pricing
	 * ========================================================= */

	public function woo_override_booking_cost( $cost, $book_obj, $posted ) {
		if ( isset($posted[self::BK_CUSTOM_COST]) ) {
			$this->log('woo.bookings.override_cost', ['custom_cost'=>(float)$posted[self::BK_CUSTOM_COST]]);
			return (float) $posted[self::BK_CUSTOM_COST];
		}
		return $cost;
	}

	public function woo_apply_eb_snapshot_to_cart( $cart ) {

		if ( is_admin() && ! defined('DOING_AJAX') ) return;
		if ( ! $cart || ! is_a($cart, 'WC_Cart') ) return;

		foreach ( $cart->get_cart() as $key => $item ) {

			if ( empty($item['booking']) || empty($item['booking'][self::BK_EVENT_ID]) ) continue;

			$booking = (array) $item['booking'];
			$scope   = isset($booking[self::BK_SCOPE]) ? (string) $booking[self::BK_SCOPE] : '';

			$product = $item['data'] ?? null;
			if ( ! $product || ! is_object($product) || ! method_exists($product, 'set_price') ) continue;

			// -------------------------------------------------
			// PRICE SNAPSHOT (authoritative, once)
			// -------------------------------------------------
			// Rental MUST be snapshotted at add-to-cart time so Woo Bookings pricing
			// cannot drift or double-count later.
			if ( $scope === 'rental' && empty($booking[self::BK_CUSTOM_COST]) ) {
				$cost = $this->calculate_booking_cost_snapshot($product, $booking);
				if ( $cost !== null ) {
					$cart->cart_contents[$key]['booking'][self::BK_CUSTOM_COST] = wc_format_decimal((float)$cost, 2);
					$booking[self::BK_CUSTOM_COST] = (float) $cost;
				}
			}

			// If we have a snapshotted cost, enforce it on the cart item price.
			if ( isset($booking[self::BK_CUSTOM_COST]) && $booking[self::BK_CUSTOM_COST] !== '' ) {
				$product->set_price( (float) $booking[self::BK_CUSTOM_COST] );
				$this->log('woo.cart.set_price_snapshot', ['key'=>$key,'scope'=>$scope,'event_id'=>(int)$booking[self::BK_EVENT_ID],'price'=>(float)$booking[self::BK_CUSTOM_COST]]);
			}

			// -------------------------------------------------
			// EARLY BOOKING (discount snapshot applied on top)
			// -------------------------------------------------
			$eligible = ! empty($booking[self::BK_EB_ELIGIBLE]);
			if ( ! $eligible ) continue;

			$pct = isset($booking[self::BK_EB_PCT]) ? (float) $booking[self::BK_EB_PCT] : 0.0;
			$amt = isset($booking[self::BK_EB_AMOUNT]) ? (float) $booking[self::BK_EB_AMOUNT] : 0.0;
			if ( $pct <= 0 && $amt <= 0 ) continue;

			// Base price snapshot per line (store in booking meta)
			if ( empty($booking[self::BK_EB_BASE]) ) {
				$base = isset($booking[self::BK_CUSTOM_COST]) ? (float) $booking[self::BK_CUSTOM_COST] : (float) $product->get_price();
				$cart->cart_contents[$key]['booking'][self::BK_EB_BASE] = wc_format_decimal($base, 2);
			} else {
				$base = (float) $booking[self::BK_EB_BASE];
			}

			if ( $amt > 0 ) {
				$disc = $this->money_round($amt);
				$new  = $this->money_round($base - $disc);
			} else {
				$disc = $this->money_round($base * ($pct/100));
				$new  = $this->money_round($base - $disc);
			}
			if ( $new < 0 ) $new = 0;

			$product->set_price( $new );
		}
	}


	/* =========================================================
	 * Cart display (frontend)
	 * ========================================================= */

	public function woo_cart_item_data( array $item_data, array $cart_item ) : array {

		if ( empty($cart_item["booking"]) || ! is_array($cart_item["booking"]) ) return $item_data;
		$booking = (array) $cart_item["booking"];

		// Event title
		if ( ! empty($booking[self::BK_EVENT_TITLE]) ) {
			$item_data[] = [
				"name"  => __("Event", "tc-booking-flow"),
				"value" => wc_clean((string) $booking[self::BK_EVENT_TITLE]),
			];
		}

		// Participant
		if ( ! empty($booking["_participant"]) ) {
			$item_data[] = [
				"name"  => __("Participant", "tc-booking-flow"),
				"value" => wc_clean((string) $booking["_participant"]),
			];
		}

		// Scope (participation/rental)
		if ( ! empty($booking[self::BK_SCOPE]) ) {
			$scope = (string) $booking[self::BK_SCOPE];
			$label = $scope === "rental" ? __("Rental", "tc-booking-flow") : __("Participation", "tc-booking-flow");
			$item_data[] = [
				"name"  => __("Type", "tc-booking-flow"),
				"value" => wc_clean($label),
			];
		}

		// Bicycle label (rental line only)
		if ( ! empty($booking["_bicycle"]) ) {
			$item_data[] = [
				"name"  => __("Bike", "tc-booking-flow"),
				"value" => wc_clean((string) $booking["_bicycle"]),
			];
		}

		return $item_data;
	}

	/**
	 * Calculate a stable booking cost snapshot for a cart line.
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

	/**
	 * Localize multilingual strings for setups using qTranslate-X / qTranslate-XT.
	 * If no multilingual plugin is detected, returns the string unchanged.
	 */
	private function localize_text( string $text ) : string {
		if ( $text === '' ) return '';
		if ( function_exists('qtranxf_useCurrentLanguageIfNotFound') ) {
			return (string) qtranxf_useCurrentLanguageIfNotFound( $text );
		}
		if ( function_exists('qtrans_useCurrentLanguageIfNotFound') ) {
			return (string) qtrans_useCurrentLanguageIfNotFound( $text );
		}
		return $text;
	}

	/**
	 * Get a post title in the current language when using multilingual plugins.
	 */
	private function localize_post_title( int $post_id ) : string {
		if ( $post_id <= 0 ) return '';
		$title = get_the_title( $post_id );
		if ( ! is_string($title) ) $title = (string) $title;
		return $this->localize_text( $title );
	}


	/**
	 * Convert stored money strings to float.
	 * Accepts values like: "20", "20.00", "20,00", "20 €".
	 */
	private function money_to_float( $val ) : float {
		if ( is_numeric($val) ) return (float) $val;
		$s = trim((string) $val);
		if ( $s === '' ) return 0.0;
		// keep digits, comma, dot, minus
		$s = preg_replace('/[^0-9,\.\-]/', '', $s);
		// If comma is used as decimal separator and dot as thousands, normalize.
		if ( substr_count($s, ',') === 1 && substr_count($s, '.') >= 1 ) {
			// assume last separator is decimal, remove the other
			if ( strrpos($s, ',') > strrpos($s, '.') ) {
				$s = str_replace('.', '', $s);
				$s = str_replace(',', '.', $s);
			} else {
				$s = str_replace(',', '', $s);
			}
		} elseif ( substr_count($s, ',') === 1 && substr_count($s, '.') === 0 ) {
			$s = str_replace(',', '.', $s);
		} else {
			// multiple commas: treat as thousands
			if ( substr_count($s, ',') > 1 ) $s = str_replace(',', '', $s);
		}
		return is_numeric($s) ? (float) $s : 0.0;
	}

	/**
	 * Round money values to currency cents consistently.
	 * We round at each ledger output step to avoid 0.01 drift between GF and PHP.
	 */
	private function money_round( float $v ) : float {
		// tiny epsilon mitigates binary float artifacts like 19.999999 -> 20.00
		return round($v + 1e-9, 2);
	}

	/**
	 * Resolve a rental price (fixed per event) based on GF rental type or rental product category.
	 * Event meta keys used by current snippets:
	 * - rental_price_road
	 * - rental_price_mtb
	 * - rental_price_ebike
	 * - rental_price_gravel
	 */
	private function get_event_rental_price( int $event_id, $entry, int $rental_product_id ) : float {
		// 1) Prefer GF rental type select (field 106)
		$rental_raw = trim((string) rgar($entry, (string) self::GF_FIELD_RENTAL_TYPE));
		$key = '';
		if ( $rental_raw !== '' ) {
			$rt = strtoupper($rental_raw);
			if ( strpos($rt, 'ROAD') === 0 )       $key = 'rental_price_road';
			elseif ( strpos($rt, 'MTB') === 0 )    $key = 'rental_price_mtb';
			elseif ( strpos($rt, 'EMTB') === 0 )   $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E-MTB') === 0 )  $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'E MTB') === 0 )  $key = 'rental_price_ebike';
			elseif ( strpos($rt, 'GRAVEL') === 0 ) $key = 'rental_price_gravel';
		}

		// 2) Fallback: infer from product categories
		if ( $key === '' && $rental_product_id > 0 ) {
			$terms = get_the_terms( $rental_product_id, 'product_cat' );
			if ( is_array($terms) ) {
				$slugs = [ ];
				foreach ( $terms as $t ) { if ( isset($t->slug) ) $slugs[] = (string) $t->slug; }
				$slugs = array_unique($slugs);
				if ( in_array('rental_road', $slugs, true) )   $key = 'rental_price_road';
				elseif ( in_array('rental_mtb', $slugs, true) ) $key = 'rental_price_mtb';
				elseif ( in_array('rental_emtb', $slugs, true) )$key = 'rental_price_ebike';
				elseif ( in_array('rental_gravel', $slugs, true) )$key = 'rental_price_gravel';
			}
		}

		if ( $key === '' ) return 0.0;
		return $this->money_to_float( get_post_meta($event_id, $key, true) );
	}

	/* =========================================================
	 * Cart → Order meta copy-through (extend your existing behavior)
	 * ========================================================= */

	public function woo_checkout_create_order_line_item( $item, $cart_item_key, $values, $order ) {

		$cart_item = WC()->cart ? WC()->cart->get_cart_item( $cart_item_key ) : [];
		$booking   = ( isset($cart_item['booking']) && is_array($cart_item['booking']) ) ? $cart_item['booking'] : [];

		if ( ! empty( $booking[self::BK_EVENT_ID] ) ) {
			$item->add_meta_data( '_event_id', $booking[self::BK_EVENT_ID] );
		}
		if ( ! empty( $booking[self::BK_EVENT_TITLE] ) ) {
			$item->add_meta_data( 'event', $booking[self::BK_EVENT_TITLE] );
		}
		if ( ! empty( $booking['_participant'] ) ) {
			$item->add_meta_data( 'participant', $booking['_participant'] );
		}
		if ( ! empty( $booking['_bicycle'] ) ) {
			$item->add_meta_data( '_bicycle', $booking['_bicycle'] );
		}
		if ( ! empty( $booking[self::BK_ENTRY_ID] ) ) {
			$item->add_meta_data( '_gf_entry_id', $booking[self::BK_ENTRY_ID] );
		}
		if ( ! empty( $booking['_participant_email'] ) ) {
			$item->add_meta_data( 'email', $booking['_participant_email'] );
		}
		if ( ! empty( $booking['_confirmation'] ) ) {
			$item->add_meta_data( 'confirmation', __('[:es]Enviar email de confirmación al participante[:en]Send email confirmation to participant[:]') );
		}

		// New: scope + EB snapshot audit
		if ( ! empty( $booking[self::BK_SCOPE] ) ) {
			$item->add_meta_data( '_tc_scope', $booking[self::BK_SCOPE] );
		}
		if ( isset($booking[self::BK_EB_PCT]) ) {
			$item->add_meta_data( '_eb_pct', wc_format_decimal((float)$booking[self::BK_EB_PCT], 2) );
		}
		if ( isset($booking[self::BK_EB_AMOUNT]) ) {
			$item->add_meta_data( '_eb_amount', wc_format_decimal((float)$booking[self::BK_EB_AMOUNT], 2) );
		}
		if ( isset($booking[self::BK_EB_ELIGIBLE]) ) {
			$item->add_meta_data( '_eb_eligible', (int) $booking[self::BK_EB_ELIGIBLE] );
		}
		if ( isset($booking[self::BK_EB_DAYS]) ) {
			$item->add_meta_data( '_eb_days_before', (int) $booking[self::BK_EB_DAYS] );
		}
		if ( isset($booking[self::BK_EB_BASE]) ) {
			$item->add_meta_data( '_eb_base_price', wc_format_decimal((float)$booking[self::BK_EB_BASE], 2) );
		}
		if ( isset($booking[self::BK_EB_EVENT_TS]) ) {
			$item->add_meta_data( '_eb_event_start_ts', (string) $booking[self::BK_EB_EVENT_TS] );
		}
	}

	/* =========================================================
	 * Partner meta on order (kept, improved base detection)
	 * ========================================================= */

	public function partner_persist_order_meta( $order, $data ) {

		if ( ! $order || ! is_a($order, 'WC_Order') ) return;

		if ( $order->get_meta('partner_code') || $order->get_meta('partner_commission') || $order->get_meta('client_total') ) {
			return;
		}

		$coupon_codes = $order->get_coupon_codes();
		if ( empty($coupon_codes) ) return;

		$partner_user_id = 0;
		$partner_code    = '';

		foreach ( $coupon_codes as $code ) {
			$code = wc_format_coupon_code( $code );
			if ( $code === '' ) continue;

			$users = get_users([
				'meta_key'   => 'discount__code',
				'meta_value' => $code,
				'number'     => 1,
				'fields'     => 'ids',
			]);

			if ( ! empty($users[0]) ) {
				$partner_user_id = (int) $users[0];
				$partner_code    = $code;
				break;
			}
		}

		if ( ! $partner_user_id || $partner_code === '' ) return;

		$partner_commission_rate = (float) get_user_meta( $partner_user_id, 'usrdiscount', true );
		if ( $partner_commission_rate < 0 ) $partner_commission_rate = 0;

		$partner_discount_pct = 0.0;
		$partner_coupon_type  = '';
		try {
			$coupon = new \WC_Coupon( $partner_code );
			$partner_coupon_type = (string) $coupon->get_discount_type();
			if ( $partner_coupon_type === 'percent' ) {
				$partner_discount_pct = (float) $coupon->get_amount();
				if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0;
			}
		} catch ( \Exception $e ) {}

		// Base before EB and before coupons: prefer _eb_base_price snapshots if present
		$subtotal_original = 0.0;
		foreach ( $order->get_items() as $item ) {

			$event_id = $item->get_meta('_event_id', true);
			if ( ! $event_id ) continue;

			$base = $item->get_meta('_eb_base_price', true);
			if ( $base !== '' ) {
				$subtotal_original += (float) $base * max(1, (int) $item->get_quantity());
			} else {
				$subtotal_original += (float) $item->get_subtotal();
			}
		}
		if ( $subtotal_original <= 0 ) $subtotal_original = (float) $order->get_subtotal();
		$subtotal_original = $this->money_round( (float) $subtotal_original );

		// EB pct stored on order later by ledger; here we set placeholder 0 (ledger updates after)
		$early_booking_discount_pct = 0.0;
		$partner_base_total = $subtotal_original;

		$partner_base_total = $this->money_round( (float) $partner_base_total );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = $this->money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$client_total       = $this->money_round( max(0.0, $partner_base_total - $client_discount) );
		$partner_commission = $this->money_round( $partner_base_total * ($partner_commission_rate / 100) );

		$order->update_meta_data('partner_id', (string) $partner_user_id);
		$order->update_meta_data('partner_code', $partner_code);

		$order->update_meta_data('partner_coupon_type', $partner_coupon_type);
		$order->update_meta_data('partner_discount_pct', wc_format_decimal($partner_discount_pct, 2));
		$order->update_meta_data('partner_commission_rate', wc_format_decimal($partner_commission_rate, 2));

		$order->update_meta_data('early_booking_discount_pct', wc_format_decimal($early_booking_discount_pct, 2));
		$order->update_meta_data('subtotal_original', wc_format_decimal($subtotal_original, 2));
		$order->update_meta_data('partner_base_total', wc_format_decimal($partner_base_total, 2));

		$order->update_meta_data('client_total', wc_format_decimal($client_total, 2));
		$order->update_meta_data('client_discount', wc_format_decimal($client_discount, 2));
		$order->update_meta_data('partner_commission', wc_format_decimal($partner_commission, 2));
		$order->update_meta_data('tc_ledger_version', '2');

		$order->save();
	}

	/* =========================================================
	 * EB + partner ledger (snapshot-driven)
	 * ========================================================= */

	public function eb_write_order_ledger( $order_id, $posted_data, $order ) {

		if ( ! $order || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order($order_id);
			if ( ! $order ) return;
		}

		$subtotal_original = 0.0;
		$eb_amount_total   = 0.0;
		$eb_pct_seen       = null;
		$eb_days_seen      = null;
		$start_event_id    = 0;
		$start_ts          = 0;

		foreach ( $order->get_items() as $item ) {

			$event_id = (int) $item->get_meta('_event_id', true);
			if ( $event_id <= 0 ) continue;

			$start_event_id = $start_event_id ?: $event_id;

			// base snapshot
			$base = $item->get_meta('_eb_base_price', true);
			$qty  = max(1, (int) $item->get_quantity());

			$line_base = ($base !== '') ? ((float)$base * $qty) : (float) $item->get_subtotal();

			$subtotal_original += $line_base;

			$eligible = (int) $item->get_meta('_eb_eligible', true);
			$pct = (float) $item->get_meta('_eb_pct', true);
			$amt = (float) $item->get_meta('_eb_amount', true);

			if ( $eligible && $base !== '' ) {
				if ( $amt > 0 ) {
					$eb_amount_total += min($line_base, $amt * $qty);
				} elseif ( $pct > 0 ) {
					$eb_amount_total += $line_base - ($line_base * (1 - ($pct/100)));
				}
				if ( $eb_pct_seen === null ) $eb_pct_seen = $pct;
				$days = $item->get_meta('_eb_days_before', true);
				if ( $days !== '' && $eb_days_seen === null ) $eb_days_seen = (int) $days;
				$ts = $item->get_meta('_eb_event_start_ts', true);
				if ( $ts !== '' && ! $start_ts ) $start_ts = (int) $ts;
			}
		}

		if ( $subtotal_original <= 0 ) return;

		// Normalize monetary aggregates to currency cents to prevent 0.01 drift.
		$subtotal_original = $this->money_round( (float) $subtotal_original );
		$eb_amount_total   = $this->money_round( (float) $eb_amount_total );

		$partner_discount_pct    = (float) $order->get_meta('partner_discount_pct', true);
		$partner_commission_rate = (float) $order->get_meta('partner_commission_rate', true);
		if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0;
		if ( $partner_commission_rate < 0 ) $partner_commission_rate = 0;

		$partner_base_total = $this->money_round( max(0, $subtotal_original - $eb_amount_total) );

		// IMPORTANT: round discount amount first, then derive totals from rounded components.
		// This matches the GF UI where discount lines are rounded and total is computed as base - discount.
		$client_discount    = $this->money_round( $partner_base_total * ($partner_discount_pct / 100) );
		$client_total       = $this->money_round( max(0.0, $partner_base_total - $client_discount) );
		$partner_commission = $this->money_round( $partner_base_total * ($partner_commission_rate / 100) );

		$order->update_meta_data('subtotal_original', wc_format_decimal($subtotal_original, 2));

		if ( $start_event_id ) $order->update_meta_data('eb_event_id', (string)$start_event_id);
		if ( $start_ts ) $order->update_meta_data('eb_event_start_ts', (string)$start_ts);

		$order->update_meta_data('early_booking_discount_pct', wc_format_decimal((float)($eb_pct_seen ?? 0.0), 2));
		$order->update_meta_data('early_booking_discount_amount', wc_format_decimal($eb_amount_total, 2));

		if ( $eb_days_seen !== null ) {
			$order->update_meta_data('eb_days_before', (string)$eb_days_seen);
		}

		$order->update_meta_data('partner_base_total', wc_format_decimal($partner_base_total, 2));
		$order->update_meta_data('client_total', wc_format_decimal($client_total, 2));
		$order->update_meta_data('client_discount', wc_format_decimal($client_discount, 2));
		$order->update_meta_data('partner_commission', wc_format_decimal($partner_commission, 2));
		$order->update_meta_data('tc_ledger_version', '2');

		$order->save();
	}

/**
 * Validate participation product is a booking product (and exists).
 */
private function is_valid_participation_product( int $product_id ) : bool {
	if ( $product_id <= 0 ) return false;
	$p = wc_get_product($product_id);
	if ( ! $p ) return false;
	return ( function_exists('is_wc_booking_product') && is_wc_booking_product($p) );
}

/**
 * Find participation product by matching any sc_event_category slug against product meta.
 * Meta key: tc_participation_category_key (set on the product).
 */
private function find_participation_product_by_category_slugs( array $slugs ) : int {

	$slugs = array_values(array_filter(array_map(function($s){
		$s = strtolower((string)$s);
		return preg_replace('/[^a-z0-9_\-]/', '', $s);
	}, $slugs)));

	if ( ! $slugs ) return 0;

	static $cache = [];
	$ck = implode('|', $slugs);
	if ( isset($cache[$ck]) ) return (int) $cache[$ck];

	$q = new \WP_Query([
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'     => \TC_BF\Admin\Product_Meta::META_KEY,
				'value'   => $slugs,
				'compare' => 'IN',
			],
		],
	]);

	if ( $q->have_posts() ) {
		foreach ( $q->posts as $pid ) {
			$pid = (int) $pid;
			if ( $this->is_valid_participation_product($pid) ) {
				return $cache[$ck] = $pid;
			}
		}
	}

	return $cache[$ck] = 0;
}

}
