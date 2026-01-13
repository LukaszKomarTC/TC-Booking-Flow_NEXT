<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * Provides partner dropdown JavaScript injection for admin override functionality.
 */
final class GF_JS {

	private static $partner_js_payload = [];

	/**
	 * Gravity Forms hook entrypoint.
	 *
	 * Called by Plugin::gf_partner_prepare_form() on:
	 * - gform_pre_render
	 * - gform_pre_validation
	 * - gform_pre_submission_filter
	 *
	 * Responsibilities:
	 * 1) Populate partner context fields into $_POST (GF_Partner::prepare_post).
	 * 2) For admins: enqueue partner dropdown JS payload (code => {discount, commission, email, id}).
	 *
	 * Must be fail-safe: never throw fatals (booking must remain possible).
	 */
	public static function partner_prepare_form( $form ) {
		try {
			$form_id = 0;
			if ( is_array($form) && isset($form['id']) ) {
				$form_id = (int) $form['id'];
			} elseif ( is_object($form) && isset($form->id) ) {
				$form_id = (int) $form->id;
			}

			$target_form_id = 0;
			if ( class_exists('TC_BF\\Admin\\Settings') && method_exists('TC_BF\\Admin\\Settings', 'get_form_id') ) {
				$target_form_id = (int) \TC_BF\Admin\Settings::get_form_id();
			}
			if ( $form_id <= 0 || ($target_form_id > 0 && $form_id !== $target_form_id) ) {
				return $form;
			}

			// 1) Populate partner context fields for GF calculations + conditional logic.
			if ( class_exists('TC_BF\\Integrations\\GravityForms\\GF_Partner') ) {
				GF_Partner::prepare_post( $form_id );
			}

			// 2) Admin-only partner dropdown payload (Way 3 UI).
			if ( current_user_can('manage_options') ) {
				$payload = [
					'partners' => self::get_partner_map_for_js(),
				];

				// Initial partner code (if any) from resolver context.
				$initial_code = '';
				if ( class_exists('TC_BF\\Domain\\PartnerResolver') && method_exists('TC_BF\\Domain\\PartnerResolver', 'resolve_partner_context') ) {
					$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );
					if ( is_array($ctx) && ! empty($ctx['active']) && ! empty($ctx['code']) ) {
						$initial_code = (string) $ctx['code'];
					}
				}
				if ( $initial_code !== '' ) {
					$payload['initial_code'] = $initial_code;
				}

				self::enqueue_partner_js_payload( $form_id, $payload );
			}
		} catch ( \Throwable $e ) {
			// Fail open.
		}

		return $form;
	}

	/**
	 * Build map of partner coupon codes for JS dropdown usage.
	 *
	 * Output shape: code => {code, discount, commission, email, id}
	 */
	private static function get_partner_map_for_js() : array {
		$map = [];
		try {
			$uq = new \WP_User_Query([
				'number'     => 500,
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

				$code_raw = (string) get_user_meta( $uid, 'discount__code', true );
				$code = $code_raw;
				if ( class_exists('TC_BF\\Domain\\PartnerResolver') && method_exists('TC_BF\\Domain\\PartnerResolver', 'normalize_partner_code') ) {
					$code = \TC_BF\Domain\PartnerResolver::normalize_partner_code( $code_raw );
				}
				if ( $code === '' ) continue;

				$commission = (float) get_user_meta( $uid, 'usrdiscount', true );
				if ( $commission < 0 ) $commission = 0.0;

				$discount = 0.0;
				if ( class_exists('TC_BF\\Domain\\PartnerResolver') && method_exists('TC_BF\\Domain\\PartnerResolver', 'get_coupon_percent_amount') ) {
					$discount = (float) \TC_BF\Domain\PartnerResolver::get_coupon_percent_amount( $code );
				}

				$map[ $code ] = [
					'code'       => $code,
					'discount'   => $discount,
					'commission' => $commission,
					'email'      => (string) (is_object($u) && isset($u->user_email) ? $u->user_email : ''),
					'id'         => $uid,
				];
			}
		} catch ( \Throwable $e ) {
			return [];
		}

		return $map;
	}

	public static function enqueue_partner_js_payload( int $form_id, array $payload ) : void {
		if ( $form_id <= 0 ) return;
		self::$partner_js_payload[ $form_id ] = $payload;
	}

	public static function output_admin_partner_js( int $form_id ) : string {

		if ( $form_id <= 0 ) return '';
		if ( ! current_user_can('manage_options') ) return '';

		// JSON payload: partners list + mapping (code => {discount, commission, email, id})
		$payload = self::$partner_js_payload[ $form_id ] ?? [];
		if ( empty($payload) ) return '';

		$json = wp_json_encode( $payload );
		if ( ! $json ) return '';

		// Build inline JS (no jQuery required).
		return
			"(function(){\n"
			. "  var fid = ".(int)$form_id.";\n"
			. "  var data = ".$json.";\n"
			. "  function qs(sel){ return document.querySelector(sel); }\n"
			. "  function getVal(fieldId){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    return el ? (el.value||'') : '';\n"
			. "  }\n"
			. "  function setVal(fieldId, v){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(!el) return;\n"
			. "    el.value = (v===null||v===undefined) ? '' : String(v);\n"
			. "    try{ el.dispatchEvent(new Event('change',{bubbles:true})); }catch(e){}\n"
			. "  }\n"
			. "  function parseMoney(v){\n"
			. "    if(v===null||v===undefined) return 0;\n"
			. "    var s = String(v).trim();\n"
			. "    if(!s) return 0;\n"
			. "    // Remove currency/symbols/spaces; keep digits, separators and minus\n"
			. "    s = s.replace(/\\u00A0/g,' ').replace(/€/g,'').trim();\n"
			. "    s = s.replace(/[^\\d,\\.\\-\\s]/g,'');\n"
			. "    s = s.replace(/\\s+/g,'');\n"
			. "    // If both '.' and ',' exist -> assume '.' thousands and ',' decimal (1.234,56)\n"
			. "    if(s.indexOf(',')!==-1 && s.indexOf('.')!==-1){\n"
			. "      s = s.replace(/\\./g,'');\n"
			. "      s = s.replace(',', '.');\n"
			. "    } else if(s.indexOf(',')!==-1){\n"
			. "      s = s.replace(',', '.');\n"
			. "    }\n"
			. "    var n = parseFloat(s);\n"
			. "    return isNaN(n) ? 0 : n;\n"
			. "  }\n"
			. "  function fmtPct(v){\n"
			. "    // Always write percent with comma decimal for GF locale\n"
			. "    var n = parseMoney(v);\n"
			. "    if(!(n>0)) return '';\n"
			. "    var s = String(n);\n"
			. "    s = s.replace('.', ',');\n"
			. "    return s;\n"
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
			. "  function recalc(){\n"
			. "    try{ if(window.gform && typeof window.gformCalculateTotalPrice === 'function'){ window.gformCalculateTotalPrice(fid); } }catch(e){}\n"
			. "  }\n"
			. "  function applyPartner(){\n"
			. "    try{\n"
			. "      var codeField = 78; // partner code hidden field\n"
			. "      var discountPctField = 152; // percent\n"
			. "      var commissionPctField = 161; // percent\n"
			. "      var emailField = 77;\n"
			. "      var userIdField = 74;\n"
			. "      var roleField = 6;\n"
			. "      var select = qs('#tc_bf_partner_select_'+fid);\n"
			. "      if(!select) return;\n"
			. "      var code = String(select.value||'').trim();\n"
			. "      if(!code){\n"
			. "        // Hide override-only fields if no selection\n"
			. "        hideField(discountPctField);\n"
			. "        hideField(commissionPctField);\n"
			. "        hideField(emailField);\n"
			. "        hideField(userIdField);\n"
			. "        return;\n"
			. "      }\n"
			. "      var p = data && data.partners ? data.partners[code] : null;\n"
			. "      if(!p) return;\n"
			. "      setVal(codeField, p.code || code);\n"
			. "      setVal(discountPctField, fmtPct(p.discount||0));\n"
			. "      setVal(commissionPctField, fmtPct(p.commission||0));\n"
			. "      setVal(emailField, p.email||'');\n"
			. "      setVal(userIdField, p.id||'');\n"
			. "      setVal(roleField, 'hotel');\n"
			. "      // Ensure override fields are visible/enabled (for admin only)\n"
			. "      showField(discountPctField);\n"
			. "      showField(commissionPctField);\n"
			. "      showField(emailField);\n"
			. "      showField(userIdField);\n"
			. "      // Trigger GF recalculation\n"
			. "      window.setTimeout(recalc, 30);\n"
			. "    }catch(e){}\n"
			. "  }\n"
			. "  function build(){\n"
			. "    try{\n"
			. "      var container = qs('#field_'+fid+'_78');\n"
			. "      if(!container) return;\n"
			. "      if(qs('#tc_bf_partner_select_'+fid)) return;\n"
			. "      var select = document.createElement('select');\n"
			. "      select.id = 'tc_bf_partner_select_'+fid;\n"
			. "      select.style.width = '100%';\n"
			. "      select.style.maxWidth = '420px';\n"
			. "      var opt0 = document.createElement('option');\n"
			. "      opt0.value = '';\n"
			. "      opt0.textContent = '— Partner override (admin) —';\n"
			. "      select.appendChild(opt0);\n"
			. "      var partners = data && data.partners ? data.partners : {};\n"
			. "      Object.keys(partners).sort().forEach(function(code){\n"
			. "        var p = partners[code];\n"
			. "        if(!p) return;\n"
			. "        var opt = document.createElement('option');\n"
			. "        opt.value = code;\n"
			. "        opt.textContent = code + (p.email ? (' — ' + p.email) : '');\n"
			. "        select.appendChild(opt);\n"
			. "      });\n"
			. "      // Insert select before the hidden input\n"
			. "      container.insertBefore(select, container.firstChild);\n"
			. "      select.addEventListener('change', function(){ applyPartner(); });\n"
			. "      // Apply initial selection if provided\n"
			. "      if(data && data.initial_code){\n"
			. "        var ic = String(data.initial_code||'').trim();\n"
			. "        if(ic && partners[ic]){\n"
			. "          select.value = ic;\n"
			. "          applyPartner();\n"
			. "        }\n"
			. "      }\n"
			. "    }catch(e){}\n"
			. "  }\n"
			. "  function bind(){ build(); }\n"
			. "  function onReady(fn){\n"
			. "    if(document.readyState === 'complete' || document.readyState === 'interactive') return fn();\n"
			. "    document.addEventListener('DOMContentLoaded', fn);\n"
			. "  }\n"
			. "  onReady(function(){\n"
			. "    bind();\n"
			. "    applyPartner();\n"
			. "    // Rounding fix is handled via GF-native calculation filter (output_rounding_fix_js).\n"
			. "    // Some themes load GF via AJAX; rebind safely\n"
			. "    try{\n"
			. "      var mo = new MutationObserver(function(){ bind(); });\n"
			. "      mo.observe(document.body, {childList:true, subtree:true});\n"
			. "    }catch(e){}\n"
			. "  });\n"
			. "})();\n";
	}

	/**
	 * Frontend-only JS that corrects partner discount display to match Woo's per-line rounding.
	 *
	 * This script NEVER changes partner identification fields (code/percent/email/user_id).
	 * It only alters the calculation result of the "Partner discount amount" field (ID 176)
	 * using Gravity Forms' calculation pipeline hook:
	 *   gform.addFilter('gform_calculation_result', ...)
	 *
	 * Rationale:
	 * - WooCommerce percent coupons are applied per line item (rounded per line, then summed).
	 * - The form previously calculated discount on the total base (rounded once), which can create
	 *   a 0,01€ mismatch.
	 * - We mirror Woo-style rounding by splitting the base into 2 scopes (participation + rental),
	 *   applying EB% per scope, then rounding each scope discount to cents before summing.
	 *
	 * Locale:
	 * - Site uses decimal_comma for display, but Gravity Forms' internal calculation engine expects
	 *   dot decimals in calculation results.
	 * - Therefore we RETURN a dot-decimal numeric string (e.g. "0.47") and let GF format it for display.
	 */
	public static function output_rounding_fix_js( int $form_id ) : string {
		if ( $form_id <= 0 ) return '';

		return
			"(function(){\n"
			. "  var fid = " . (int) $form_id . ";\n"
			. "  function qs(sel){ return document.querySelector(sel); }\n"
			. "  function parseMoney(v){\n"
			. "    if(v===null||v===undefined) return 0;\n"
			. "    var s = String(v).trim();\n"
			. "    if(!s) return 0;\n"
			. "    s = s.replace(/\\u00A0/g,' ').replace(/€/g,'').trim();\n"
			. "    s = s.replace(/[^\\d,\\.\\-\\s]/g,'');\n"
			. "    s = s.replace(/\\s+/g,'');\n"
			. "    // If both '.' and ',' exist -> assume '.' thousands and ',' decimal (1.234,56)\n"
			. "    if(s.indexOf(',')!==-1 && s.indexOf('.')!==-1){\n"
			. "      s = s.replace(/\\./g,'');\n"
			. "      s = s.replace(',', '.');\n"
			. "    } else if(s.indexOf(',')!==-1){\n"
			. "      s = s.replace(',', '.');\n"
			. "    }\n"
			. "    var n = parseFloat(s);\n"
			. "    return isNaN(n) ? 0 : n;\n"
			. "  }\n"
			. "  function round2(n){\n"
			. "    n = parseFloat(n||0);\n"
			. "    if(isNaN(n)) n = 0;\n"
			. "    return Math.round((n + 1e-9) * 100) / 100;\n"
			. "  }\n"
			. "  // IMPORTANT: return dot-decimal for GF internal calculations (display formatting is handled by GF locale).\n"
			. "  function fmtMoneyForCalc(v){ return round2(v).toFixed(2); }\n"
			. "  function getVal(fieldId){ var el = qs('#input_'+fid+'_'+fieldId); return el ? (el.value||'') : ''; }\n"
			. "  function getPrice(fieldId){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId+'_2');\n"
			. "    if(!el) return 0;\n"
			. "    return parseMoney(el.value);\n"
			. "  }\n"
			. "  function computePartnerDiscount(){\n"
			. "    var pct = parseMoney(getVal(152));\n"
			. "    if(!(pct>0)) return null;\n"
			. "    var ebPct = parseMoney(getVal(172));\n"
			. "    if(!(ebPct>=0)) ebPct = 0;\n"
			. "    // Scope bases: participation vs rental\n"
			. "    var basePart = getPrice(138) || getPrice(137) || 0;\n"
			. "    var baseRent = getPrice(139) || getPrice(140) || getPrice(141) || getPrice(171) || 0;\n"
			. "    if(!(basePart>0) && !(baseRent>0)) return null;\n"
			. "    function afterEb(x){ if(!(x>0)) return 0; return round2(x * (1 - (ebPct/100))); }\n"
			. "    var partAfterEb = afterEb(basePart);\n"
			. "    var rentAfterEb = afterEb(baseRent);\n"
			. "    // Woo-like rounding: round each scope discount to cents, then sum\n"
			. "    var discPart = round2(partAfterEb * (pct/100));\n"
			. "    var discRent = round2(rentAfterEb * (pct/100));\n"
			. "    var partnerDisc = round2(discPart + discRent);\n"
			. "    return fmtMoneyForCalc(partnerDisc);\n"
			. "  }\n"
			. "  function getFieldId(formulaField){\n"
			. "    var id = 0;\n"
			. "    try{\n"
			. "      if(formulaField){\n"
			. "        id = parseInt(formulaField.field_id || formulaField.fieldId || formulaField.id || 0, 10) || 0;\n"
			. "      }\n"
			. "    }catch(e){}\n"
			. "    return id;\n"
			. "  }\n"
			. "  function bind(){\n"
			. "    if(!window.gform || typeof window.gform.addFilter !== 'function') return false;\n"
			. "    if(window.__tcBfCalcFilterBound && window.__tcBfCalcFilterBound[fid]) return true;\n"
			. "    window.__tcBfCalcFilterBound = window.__tcBfCalcFilterBound || {};\n"
			. "    window.__tcBfCalcFilterBound[fid] = true;\n"
			. "    try{\n"
			. "      window.gform.addFilter('gform_calculation_result', function(result, formulaField, formId){\n"
			. "        try{\n"
			. "          if(parseInt(formId,10) !== fid) return result;\n"
			. "          var fieldId = getFieldId(formulaField);\n"
			. "          if(fieldId !== 176) return result;\n"
			. "          var corrected = computePartnerDiscount();\n"
			. "          return (corrected === null) ? result : corrected;\n"
			. "        }catch(e){ return result; }\n"
			. "      });\n"
			. "    }catch(e){}\n"
			. "    return true;\n"
			. "  }\n"
			. "  function tryBind(retries){\n"
			. "    if(bind()) return;\n"
			. "    if(retries <= 0) return;\n"
			. "    window.setTimeout(function(){ tryBind(retries-1); }, 200);\n"
			. "  }\n"
			. "  function onReady(fn){ if(document.readyState==='complete'||document.readyState==='interactive') return fn(); document.addEventListener('DOMContentLoaded', fn); }\n"
			. "  onReady(function(){\n"
			. "    tryBind(25);\n"
			. "    // Also bind after GF renders (covers AJAX-loaded forms and ensures gform is available).\n"
			. "    if(window.jQuery){\n"
			. "      try{ window.jQuery(document).on('gform_post_render', function(e, formId){ if(parseInt(formId,10)===fid){ tryBind(5); } }); }catch(e){}\n"
			. "    }\n"
			. "  });\n"
			. "})();\n";
	}

	public static function output_partner_js() : void {
		if ( is_admin() ) return;
		if ( ! function_exists('is_singular') || ! is_singular('sc_event') ) return;
		if ( ! class_exists('TC_BF\\Admin\\Settings') || ! method_exists('TC_BF\\Admin\\Settings', 'get_form_id') ) return;

		$form_id = (int) \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id <= 0 ) return;

		// 1) Always output the rounding fix for ALL users (Way 1/2/3): display alignment only.
		$fix = self::output_rounding_fix_js( $form_id );
		if ( $fix !== '' ) {
			echo "<script>\n";
			echo $fix;
			echo "</script>\n";
		}

		// 2) Admin-only partner override dropdown script.
		if ( current_user_can('manage_options') ) {
			$admin = self::output_admin_partner_js( $form_id );
			if ( $admin !== '' ) {
				echo "<script>\n";
				echo $admin;
				echo "</script>\n";
			}
		}
	}
}
