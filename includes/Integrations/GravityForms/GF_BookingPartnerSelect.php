<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Admin\Settings;
use TC_BF\Domain\PartnerResolver;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF_BookingPartnerSelect - Populate partner override select for booking forms
 *
 * Populates the partner_override_code field (CSS class: partner_override_code) with:
 * - Users with role 'hotel' (same as event form)
 * - Users with discount__code meta
 *
 * Also injects JS to populate hidden partner fields when selection changes.
 * Business key remains 'partner_override_code' regardless of UI type (text vs select).
 *
 * @since TCBF-14
 */
final class GF_BookingPartnerSelect {

	/**
	 * Partner JS payload (per request)
	 * @var array
	 */
	private static array $partner_js_payload = [];

	/**
	 * Initialize hooks
	 */
	public static function init() : void {
		add_filter( 'gform_pre_render',            [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_pre_validation',        [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_admin_pre_render',      [ __CLASS__, 'populate_partner_choices' ], 15, 1 );
		add_filter( 'gform_pre_submission_filter', [ __CLASS__, 'populate_partner_choices' ], 15, 1 );

		// Output partner JS in footer
		add_action( 'wp_footer', [ __CLASS__, 'output_partner_js' ], 100 );

		// Output CSS for ledger summary in head
		add_action( 'wp_head', [ __CLASS__, 'output_ledger_css' ], 100 );
	}

	/**
	 * Populate partner select choices for booking form
	 *
	 * @param array $form GF form array
	 * @return array Modified form
	 */
	public static function populate_partner_choices( $form ) : array {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}

		$form_id = (int) $form['id'];
		$booking_form_id = Settings::get_booking_form_id();

		// Only process booking forms
		if ( $form_id !== $booking_form_id ) {
			return $form;
		}

		// Build partner choices
		$choices = self::build_partner_choices();

		// Find and populate partner_override_code field (select type)
		foreach ( $form['fields'] as &$field ) {
			$css_class = (string) ( $field->cssClass ?? '' );
			$input_name = (string) ( $field->inputName ?? '' );

			// Match by CSS class or inputName - business key is partner_override_code
			if ( strpos( $css_class, 'partner_override_code' ) !== false || $input_name === 'partner_override_code' ) {
				$field->choices = $choices;
			}
		}

		// Build partner map for JS and store for footer output
		$partner_map = self::get_partner_map_for_js();
		$ctx = PartnerResolver::resolve_partner_context( $form_id );
		$initial_code = ( ! empty( $ctx ) && ! empty( $ctx['active'] ) && ! empty( $ctx['code'] ) )
			? (string) $ctx['code']
			: '';

		self::$partner_js_payload[ $form_id ] = [
			'partners'     => $partner_map,
			'initial_code' => $initial_code,
		];

		// Register GF init script
		self::register_partner_init_script( $form_id, $partner_map, $initial_code );

		return $form;
	}

	/**
	 * Build partner choices array for select field
	 *
	 * @return array GF choices format
	 */
	private static function build_partner_choices() : array {
		$choices = [];

		// First option: direct booking (no partner)
		$choices[] = [
			'text'       => __( 'Direct booking (no partner)', 'tc-booking-flow-next' ),
			'value'      => '',
			'isSelected' => true,
		];

		// Get hotel users (same pattern as event form)
		$hotel_users = get_users([
			'role'    => 'hotel',
			'orderby' => 'user_nicename',
			'order'   => 'ASC',
		]);

		foreach ( $hotel_users as $user ) {
			$discount_code = (string) get_user_meta( $user->ID, 'discount__code', true );
			$discount_code = PartnerResolver::normalize_partner_code( $discount_code );

			if ( $discount_code === '' ) {
				continue;
			}

			$choices[] = [
				'text'  => $user->display_name . ' (' . $discount_code . ')',
				'value' => $discount_code,
			];
		}

		return $choices;
	}

	/**
	 * Build partner map for JavaScript
	 *
	 * @return array Partner data keyed by code
	 */
	private static function get_partner_map_for_js() : array {
		$map = [];

		// Query all users with discount__code meta
		$uq = new \WP_User_Query([
			'number'     => 200,
			'fields'     => [ 'ID', 'user_email' ],
			'meta_query' => [
				[
					'key'     => 'discount__code',
					'compare' => 'EXISTS',
				]
			]
		]);
		$users = $uq->get_results();

		if ( ! is_array( $users ) ) {
			$users = [];
		}

		foreach ( $users as $u ) {
			$uid = (int) ( is_object( $u ) && isset( $u->ID ) ? $u->ID : 0 );
			if ( $uid <= 0 ) {
				continue;
			}

			$code = (string) get_user_meta( $uid, 'discount__code', true );
			$code = PartnerResolver::normalize_partner_code( $code );

			if ( $code === '' ) {
				continue;
			}

			$commission = (float) get_user_meta( $uid, 'usrdiscount', true );
			if ( $commission < 0 ) {
				$commission = 0.0;
			}

			$discount = PartnerResolver::get_coupon_percent_amount( $code );

			$map[ $code ] = [
				'id'         => $uid,
				'email'      => (string) ( is_object( $u ) && isset( $u->user_email ) ? $u->user_email : '' ),
				'commission' => $commission,
				'discount'   => $discount,
			];
		}

		return $map;
	}

	/**
	 * Register GF init script for partner select
	 *
	 * @param int    $form_id      GF form ID
	 * @param array  $partners     Partner map
	 * @param string $initial_code Initial partner code
	 */
	private static function register_partner_init_script( int $form_id, array $partners, string $initial_code = '' ) : void {
		if ( $form_id <= 0 ) {
			return;
		}

		if ( ! class_exists( '\GFFormDisplay' ) ) {
			return;
		}

		$script = self::build_partner_select_js( $form_id, $partners, $initial_code );
		if ( $script === '' ) {
			return;
		}

		\GFFormDisplay::add_init_script(
			$form_id,
			'tc_bf_booking_partner_select_' . $form_id,
			\GFFormDisplay::ON_PAGE_RENDER,
			$script
		);
	}

	/**
	 * Build JavaScript for partner select functionality
	 *
	 * Uses semantic field IDs resolved via GF_SemanticFields.
	 *
	 * @param int    $form_id      GF form ID
	 * @param array  $partners     Partner map
	 * @param string $initial_code Initial partner code
	 * @return string JavaScript code
	 */
	private static function build_partner_select_js( int $form_id, array $partners, string $initial_code = '' ) : string {
		$json = wp_json_encode( $partners );

		// Resolve field IDs using semantic keys - Partner fields
		$field_override     = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_OVERRIDE_CODE );
		$field_coupon_code  = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_COUPON_CODE );
		$field_discount_pct = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_DISCOUNT_PCT );
		$field_commission   = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_COMMISSION_PCT );
		$field_email        = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_EMAIL );
		$field_user_id      = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_PARTNER_USER_ID );

		// Resolve field IDs - Ledger fields for pricing summary
		$field_ledger_base   = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_LEDGER_BASE );
		$field_ledger_eb_pct = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_LEDGER_EB_PCT );
		$field_ledger_eb_amt = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_LEDGER_EB_AMOUNT );
		$field_ledger_partner = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_LEDGER_PARTNER_AMOUNT );
		$field_ledger_total  = GF_SemanticFields::require_field_id( $form_id, GF_SemanticFields::KEY_LEDGER_TOTAL );

		// Translations
		$i18n_eb_label       = esc_js( __( 'EARLY BOOKING', 'tc-booking-flow-next' ) );
		$i18n_discount       = esc_js( __( 'discount', 'tc-booking-flow-next' ) );
		$i18n_base           = esc_js( __( 'Base price', 'tc-booking-flow-next' ) );
		$i18n_total          = esc_js( __( 'Total', 'tc-booking-flow-next' ) );

		return "window.tcBfBookingPartnerMap = window.tcBfBookingPartnerMap || {};\n"
			. "window.tcBfBookingPartnerMap[{$form_id}] = {$json};\n"
			. "(function(){\n"
			. "  var fid = {$form_id};\n"
			. "  var initialCode = '" . esc_js( $initial_code ) . "';\n"
			// Partner field IDs
			. "  var FIELD_OVERRIDE = {$field_override};\n"
			. "  var FIELD_COUPON = {$field_coupon_code};\n"
			. "  var FIELD_DISCOUNT = {$field_discount_pct};\n"
			. "  var FIELD_COMMISSION = {$field_commission};\n"
			. "  var FIELD_EMAIL = {$field_email};\n"
			. "  var FIELD_USER_ID = {$field_user_id};\n"
			// Ledger field IDs
			. "  var FIELD_LEDGER_BASE = {$field_ledger_base};\n"
			. "  var FIELD_LEDGER_EB_PCT = {$field_ledger_eb_pct};\n"
			. "  var FIELD_LEDGER_EB_AMT = {$field_ledger_eb_amt};\n"
			. "  var FIELD_LEDGER_PARTNER = {$field_ledger_partner};\n"
			. "  var FIELD_LEDGER_TOTAL = {$field_ledger_total};\n"
			// i18n
			. "  var i18n = { eb: '{$i18n_eb_label}', discount: '{$i18n_discount}', base: '{$i18n_base}', total: '{$i18n_total}' };\n"
			// Helpers
			. "  function qs(sel){ return document.querySelector(sel); }\n"
			. "  function getVal(fieldId){\n"
			. "    if(fieldId <= 0) return '';\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    return el ? (el.value||'').toString().trim() : '';\n"
			. "  }\n"
			. "  function setVal(fieldId, val){\n"
			. "    if(fieldId <= 0) return;\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(!el) return;\n"
			. "    var next = (val===null||typeof val==='undefined') ? '' : String(val);\n"
			. "    if(el.value !== next){\n"
			. "      el.value = next;\n"
			. "      try{ el.dispatchEvent(new Event('change', {bubbles:true})); }catch(e){}\n"
			. "    }\n"
			. "  }\n"
			. "  function parseNum(v){\n"
			. "    if(!v) return 0;\n"
			. "    var s = String(v).replace(/[^\\d,\\.\\-]/g,'').replace(',','.');\n"
			. "    var n = parseFloat(s);\n"
			. "    return isNaN(n) ? 0 : n;\n"
			. "  }\n"
			. "  function fmtPct(v){\n"
			. "    if(v===null||typeof v==='undefined') return '';\n"
			. "    var s = String(v).trim();\n"
			. "    if(!s) return '';\n"
			. "    if(s.indexOf(',') !== -1) return s;\n"
			. "    if(s.indexOf('.') !== -1) return s.replace('.', ',');\n"
			. "    return s;\n"
			. "  }\n"
			. "  function fmtCurrency(v){\n"
			. "    var n = parseNum(v);\n"
			. "    return n.toLocaleString('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';\n"
			. "  }\n"
			// Pricing summary display
			. "  function updateLedgerSummary(){\n"
			. "    var container = qs('#tcbf-booking-ledger-summary');\n"
			. "    if(!container) return;\n"
			. "    var base = parseNum(getVal(FIELD_LEDGER_BASE));\n"
			. "    var ebPct = parseNum(getVal(FIELD_LEDGER_EB_PCT));\n"
			. "    var ebAmt = parseNum(getVal(FIELD_LEDGER_EB_AMT));\n"
			. "    var partnerAmt = parseNum(getVal(FIELD_LEDGER_PARTNER));\n"
			. "    var total = parseNum(getVal(FIELD_LEDGER_TOTAL));\n"
			. "    var partnerCode = getVal(FIELD_COUPON).toUpperCase();\n"
			. "    var partnerPct = parseNum(getVal(FIELD_DISCOUNT));\n"
			. "    var html = '';\n"
			// Base price row
			. "    if(base > 0){\n"
			. "      html += '<div class=\"tcbf-ledger-row tcbf-ledger-base\">';\n"
			. "      html += '<span class=\"tcbf-ledger-label\">' + i18n.base + '</span>';\n"
			. "      html += '<span class=\"tcbf-ledger-value\">' + fmtCurrency(base) + '</span>';\n"
			. "      html += '</div>';\n"
			. "    }\n"
			// EB discount row (if applicable)
			. "    if(ebAmt > 0){\n"
			. "      html += '<div class=\"tcbf-ledger-row tcbf-ledger-eb\">';\n"
			. "      html += '<div class=\"tcbf-ledger-badge\"><span class=\"tcbf-ledger-icon\">⏰</span><span class=\"tcbf-ledger-text\">' + i18n.eb + '</span></div>';\n"
			. "      html += '<div class=\"tcbf-ledger-info\"><span class=\"tcbf-ledger-pct\">' + fmtPct(ebPct) + '% ' + i18n.discount + '</span><span class=\"tcbf-ledger-amt\">-' + fmtCurrency(ebAmt) + '</span></div>';\n"
			. "      html += '</div>';\n"
			. "    }\n"
			// Partner discount row (if applicable)
			. "    if(partnerAmt > 0 && partnerCode){\n"
			. "      html += '<div class=\"tcbf-ledger-row tcbf-ledger-partner\">';\n"
			. "      html += '<div class=\"tcbf-ledger-badge\"><span class=\"tcbf-ledger-icon\">✓</span><span class=\"tcbf-ledger-text\">' + partnerCode + '</span></div>';\n"
			. "      html += '<div class=\"tcbf-ledger-info\"><span class=\"tcbf-ledger-pct\">' + fmtPct(partnerPct) + '% ' + i18n.discount + '</span><span class=\"tcbf-ledger-amt\">-' + fmtCurrency(partnerAmt) + '</span></div>';\n"
			. "      html += '</div>';\n"
			. "    }\n"
			// Total row
			. "    if(total > 0){\n"
			. "      html += '<div class=\"tcbf-ledger-row tcbf-ledger-total\">';\n"
			. "      html += '<span class=\"tcbf-ledger-label\">' + i18n.total + '</span>';\n"
			. "      html += '<span class=\"tcbf-ledger-value\">' + fmtCurrency(total) + '</span>';\n"
			. "      html += '</div>';\n"
			. "    }\n"
			. "    container.innerHTML = html;\n"
			. "    container.style.display = html ? 'block' : 'none';\n"
			. "  }\n"
			// Apply partner and update summary
			. "  function applyPartner(){\n"
			. "    var map = (window.tcBfBookingPartnerMap && window.tcBfBookingPartnerMap[fid]) ? window.tcBfBookingPartnerMap[fid] : {};\n"
			. "    var sel = qs('#input_'+fid+'_'+FIELD_OVERRIDE);\n"
			. "    var code = sel ? (sel.value||'').toString().trim() : '';\n"
			. "    if(!code && initialCode) code = initialCode;\n"
			. "    var data = (code && map && map[code]) ? map[code] : null;\n"
			. "    if(!data){\n"
			. "      setVal(FIELD_COUPON, '');\n"
			. "      setVal(FIELD_DISCOUNT, '');\n"
			. "      setVal(FIELD_COMMISSION, '');\n"
			. "      setVal(FIELD_EMAIL, '');\n"
			. "      setVal(FIELD_USER_ID, '');\n"
			. "    } else {\n"
			. "      setVal(FIELD_COUPON, code);\n"
			. "      setVal(FIELD_DISCOUNT, fmtPct(data.discount||''));\n"
			. "      setVal(FIELD_COMMISSION, fmtPct(data.commission||''));\n"
			. "      setVal(FIELD_EMAIL, data.email||'');\n"
			. "      setVal(FIELD_USER_ID, data.id||'');\n"
			. "    }\n"
			. "    setTimeout(updateLedgerSummary, 50);\n"
			. "  }\n"
			. "  function bindOnce(){\n"
			. "    var sel = qs('#input_'+fid+'_'+FIELD_OVERRIDE);\n"
			. "    if(sel && !sel.__tcBfBound){\n"
			. "      sel.__tcBfBound = true;\n"
			. "      sel.addEventListener('change', applyPartner);\n"
			. "    }\n"
			. "  }\n"
			. "  if(window.jQuery){\n"
			. "    try{\n"
			. "      jQuery(document).on('gform_post_render', function(e, formId){ if(parseInt(formId,10)===fid){ bindOnce(); applyPartner(); updateLedgerSummary(); } });\n"
			. "    }catch(e){}\n"
			. "  }\n"
			. "  setTimeout(function(){ bindOnce(); applyPartner(); updateLedgerSummary(); }, 60);\n"
			. "})();\n";
	}

	/**
	 * Output partner JS in footer (fallback for non-AJAX forms)
	 */
	public static function output_partner_js() : void {
		if ( empty( self::$partner_js_payload ) ) {
			return;
		}

		if ( is_admin() ) {
			return;
		}

		foreach ( self::$partner_js_payload as $form_id => $payload ) {
			$form_id = (int) $form_id;
			if ( $form_id <= 0 ) {
				continue;
			}

			$partners = ( is_array( $payload ) && isset( $payload['partners'] ) && is_array( $payload['partners'] ) )
				? $payload['partners']
				: [];
			$initial_code = ( is_array( $payload ) && isset( $payload['initial_code'] ) )
				? (string) $payload['initial_code']
				: '';

			$js = self::build_partner_select_js( $form_id, $partners, $initial_code );
			if ( $js === '' ) {
				continue;
			}

			echo "\n<script id=\"tc-bf-booking-partner-select-{$form_id}\">\n";
			echo $js;
			echo "\n</script>\n";
		}
	}

	/**
	 * Output CSS for ledger summary display
	 */
	public static function output_ledger_css() : void {
		if ( is_admin() ) {
			return;
		}

		// Only output on product pages or when booking form is likely to be used
		if ( ! is_singular( 'product' ) && ! is_cart() && ! is_checkout() ) {
			return;
		}

		echo "\n<!-- TC Booking Flow: Ledger Summary CSS -->\n";
		echo "<style id=\"tcbf-ledger-summary-css\">\n";
		echo ".tcbf-ledger-summary {\n";
		echo "  background: #f8fafc;\n";
		echo "  border: 1px solid #e2e8f0;\n";
		echo "  border-radius: 8px;\n";
		echo "  padding: 16px;\n";
		echo "  margin: 16px 0;\n";
		echo "}\n";
		echo ".tcbf-ledger-row {\n";
		echo "  display: flex;\n";
		echo "  justify-content: space-between;\n";
		echo "  align-items: center;\n";
		echo "  padding: 10px 0;\n";
		echo "  border-bottom: 1px solid #e2e8f0;\n";
		echo "}\n";
		echo ".tcbf-ledger-row:last-child { border-bottom: none; }\n";
		echo ".tcbf-ledger-base { color: #475569; }\n";
		echo ".tcbf-ledger-label { font-weight: 500; }\n";
		echo ".tcbf-ledger-value { font-weight: 600; }\n";
		// EB row styling
		echo ".tcbf-ledger-eb {\n";
		echo "  background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);\n";
		echo "  border-radius: 6px;\n";
		echo "  padding: 12px 16px;\n";
		echo "  margin: 8px 0;\n";
		echo "  border: none;\n";
		echo "}\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-badge { display: flex; align-items: center; gap: 8px; }\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-icon { font-size: 20px; }\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-text { font-size: 14px; font-weight: 700; color: #fff; letter-spacing: 0.5px; }\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-info { text-align: right; }\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-pct { font-size: 13px; color: #fff; opacity: 0.9; }\n";
		echo ".tcbf-ledger-eb .tcbf-ledger-amt { font-size: 18px; font-weight: 700; color: #fff; }\n";
		// Partner row styling
		echo ".tcbf-ledger-partner {\n";
		echo "  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);\n";
		echo "  border-radius: 6px;\n";
		echo "  padding: 12px 16px;\n";
		echo "  margin: 8px 0;\n";
		echo "  border: none;\n";
		echo "}\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-badge { display: flex; align-items: center; gap: 8px; }\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-icon { font-size: 20px; color: #22c55e; }\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-text { font-size: 16px; font-weight: 700; color: #14532d; letter-spacing: 0.5px; }\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-info { text-align: right; }\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-pct { font-size: 13px; color: #14532d; }\n";
		echo ".tcbf-ledger-partner .tcbf-ledger-amt { font-size: 18px; font-weight: 700; color: #14532d; }\n";
		// Total row styling
		echo ".tcbf-ledger-total {\n";
		echo "  padding-top: 12px;\n";
		echo "  margin-top: 8px;\n";
		echo "  border-top: 2px solid #cbd5e1;\n";
		echo "}\n";
		echo ".tcbf-ledger-total .tcbf-ledger-label { font-size: 16px; font-weight: 600; color: #1e293b; }\n";
		echo ".tcbf-ledger-total .tcbf-ledger-value { font-size: 20px; font-weight: 700; color: #1e293b; }\n";
		echo "</style>\n";
	}
}
