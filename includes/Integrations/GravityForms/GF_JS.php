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
			. "  function toggleSummary(data, code){\n"
			. "    var summary = qs('#field_'+fid+'_177 .tc-bf-price-summary');\n"
			. "    if(!summary) return;\n"
			. "    var active = (data && code) ? '' : 'none';\n"
			. "    summary.style.display = active;\n"
			. "    var codeEl = summary.querySelector('.tc-bf-partner-code');\n"
			. "    if(codeEl) codeEl.textContent = code || '';\n"
			. "    var discEl = summary.querySelector('.tc-bf-partner-discount');\n"
			. "    if(discEl) discEl.textContent = (data && data.discount) ? String(data.discount).replace('.', ',') : '';\n"
			. "    var commEl = summary.querySelector('.tc-bf-partner-commission');\n"
			. "    if(commEl) commEl.textContent = (data && data.commission) ? String(data.commission).replace('.', ',') : '';\n"
			. "  }\n"

			// --- NEW: per-line rounding to match WooCommerce percent coupon behavior ---
			. "  function round2(n){\n"
			. "    n = parseFloat(n||0);\n"
			. "    if(isNaN(n)) n = 0;\n"
			. "    // mimic Money::money_round (epsilon) but in JS\n"
			. "    return Math.round((n + 1e-9) * 100) / 100;\n"
			. "  }\n"
			. "  function fmtMoney(v){\n"
			. "    v = round2(v);\n"
			. "    var s = v.toFixed(2);\n"
			. "    return s.replace('.', ',');\n"
			. "  }\n"
			. "  function getPrice(fieldId){\n"
			. "    // Single Product price input is usually #input_{fid}_{fieldId}_2\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId+'_2');\n"
			. "    if(!el) return 0;\n"
			. "    return parseMoney(el.value);\n"
			. "  }\n"
			. "  function fixPartnerPerLineRounding(){\n"
			. "    // Only if partner % > 0\n"
			. "    var pct = parseMoney(getVal(152));\n"
			. "    if(!(pct > 0)) return;\n"
			. "\n"
			. "    // EB % (may be 0)\n"
			. "    var ebPct = parseMoney(getVal(172));\n"
			. "    if(!(ebPct >= 0)) ebPct = 0;\n"
			. "\n"
			. "    // Commission % (optional)\n"
			. "    var commPct = parseMoney(getVal(161));\n"
			. "    if(!(commPct >= 0)) commPct = 0;\n"
			. "\n"
			. "    // Participation: member OR regular\n"
			. "    var part = getPrice(138) || getPrice(137) || 0;\n"
			. "\n"
			. "    // Rental: one of rental products (may be 0)\n"
			. "    var rental = getPrice(139) || getPrice(140) || getPrice(141) || getPrice(171) || 0;\n"
			. "\n"
			. "    // If nothing priced, do nothing.\n"
			. "    if(!(part > 0) && !(rental > 0)) return;\n"
			. "\n"
			. "    function afterEb(x){\n"
			. "      if(!(x > 0)) return 0;\n"
			. "      return round2(x * (1 - (ebPct/100)));\n"
			. "    }\n"
			. "\n"
			. "    var partAfterEb   = afterEb(part);\n"
			. "    var rentalAfterEb = afterEb(rental);\n"
			. "\n"
			. "    // EB discount per line (to align with WC line rounding)\n"
			. "    var ebDisc = round2( round2(part - partAfterEb) + round2(rental - rentalAfterEb) );\n"
			. "\n"
			. "    // Partner discount per line, rounded per line then summed (WC behavior)\n"
			. "    var partDisc   = round2(partAfterEb * (pct/100));\n"
			. "    var rentalDisc = round2(rentalAfterEb * (pct/100));\n"
			. "    var partnerDisc = round2(partDisc + rentalDisc);\n"
			. "\n"
			. "    // Base after EB total (sum of rounded per-line bases)\n"
			. "    var baseAfterEb = round2(partAfterEb + rentalAfterEb);\n"
			. "\n"
			. "    // Totals\n"
			. "    var discountTotal = round2(ebDisc + partnerDisc);\n"
			. "    var clientTotal   = round2(baseAfterEb - partnerDisc);\n"
			. "\n"
			. "    // Partner commission amount (mirror per-line rounding too)\n"
			. "    var commAmt = 0;\n"
			. "    if(commPct > 0){\n"
			. "      var partComm = round2(partAfterEb * (commPct/100));\n"
			. "      var rentComm = round2(rentalAfterEb * (commPct/100));\n"
			. "      commAmt = round2(partComm + rentComm);\n"
			. "    }\n"
			. "\n"
			. "    // Push into number fields (display + calculations that use {Field:value})\n"
			. "    // Note: these fields are formula-based in GF, so we set AFTER GF recalculation.\n"
			. "    setVal(174, fmtMoney(baseAfterEb));\n"
			. "    setVal(175, fmtMoney(ebDisc));\n"
			. "    setVal(176, fmtMoney(partnerDisc));\n"
			. "    setVal(164, fmtMoney(discountTotal));\n"
			. "    setVal(168, fmtMoney(clientTotal));\n"
			. "    setVal(165, fmtMoney(commAmt));\n"
			. "\n"
			. "    // Also refresh summary block if present (it reads the same fields)\n"
			. "    try{ toggleSummary({discount:pct, commission:commPct}, (getVal(154)||'')); }catch(e){}\n"
			. "  }\n"
			// --- END NEW ---

			. "  function applyPartner(){\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    var code = '';\n"
			. "    if(sel){\n"
			. "      code = (sel.value||'').toString().trim();\n"
			. "    } else {\n"
			. "      var codeEl = qs('#input_'+fid+'_154');\n"
			. "      if(codeEl) code = (codeEl.value||'').toString().trim();\n"
			. "    }\n"
			. "    code = code.toLowerCase();\n"
			. "    var p = data && data.partners ? data.partners[code] : null;\n"
			. "    if(!p){\n"
			. "      // clear partner fields\n"
			. "      setVal(154,'');\n"
			. "      setVal(152,'');\n"
			. "      setVal(161,'');\n"
			. "      setVal(153,'');\n"
			. "      setVal(166,'');\n"
			. "      hideField(176); hideField(165);\n"
			. "    } else {\n"
			. "      setVal(154,(p.code||code||''));\n"
			. "      setVal(152, fmtPct(p.discount||''));\n"
			. "      setVal(161, fmtPct(p.commission||''));\n"
			. "      setVal(153,(p.email||''));\n"
			. "      setVal(166,(p.id||''));\n"
			. "      showField(176); showField(165);\n"
			. "    }\n"
			. "    toggleSummary(p, code);\n"
			. "    if(typeof window.gformCalculateTotalPrice === 'function'){\n"
			. "      try{ window.gformCalculateTotalPrice(fid); }catch(e){}\n"
			. "    }\n"
			. "    // Align GF discount rounding with WooCommerce percent coupon behavior (per-line rounding).\n"
			. "    window.setTimeout(function(){ try{ fixPartnerPerLineRounding(); }catch(e){} }, 60);\n"
			. "  }\n"
			. "  function bind(){\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    if(sel && !sel.__tcBfBound){\n"
			. "      sel.__tcBfBound = true;\n"
			. "      sel.addEventListener('change', function(){ applyPartner(); });\n"
			. "    }\n"
			. "  }\n"
			. "  function onReady(fn){\n"
			. "    if(document.readyState === 'complete' || document.readyState === 'interactive') return fn();\n"
			. "    document.addEventListener('DOMContentLoaded', fn);\n"
			. "  }\n"
			. "  onReady(function(){\n"
			. "    bind();\n"
			. "    applyPartner();\n"
			. "    // Keep GF display in sync with Woo rounding whenever GF recalculates/changes visibility.\n"
			. "    if(window.jQuery){\n"
			. "      try{\n"
			. "        window.jQuery(document).on('gform_post_conditional_logic', function(e, formId){\n"
			. "          if(parseInt(formId,10) === fid){ window.setTimeout(function(){ try{ fixPartnerPerLineRounding(); }catch(e){} }, 60); }\n"
			. "        });\n"
			. "      }catch(e){}\n"
			. "    }\n"
			. "    // Also react to direct input changes (products / EB% / partner%).\n"
			. "    try{\n"
			. "      var ids = [137,138,139,140,141,171,172,152,161];\n"
			. "      ids.forEach(function(id){\n"
			. "        var el = qs('#input_'+fid+'_'+id) || qs('#input_'+fid+'_'+id+'_2');\n"
			. "        if(!el || el.__tcBfRoundFixBound) return;\n"
			. "        el.__tcBfRoundFixBound = true;\n"
			. "        el.addEventListener('change', function(){ window.setTimeout(function(){ try{ fixPartnerPerLineRounding(); }catch(e){} }, 60); });\n"
			. "      });\n"
			. "    }catch(e){}\n"
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
	 * It only writes calculated DISPLAY number fields so the form matches the cart to the cent.
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
			. "  function fmtMoney(v){ return round2(v).toFixed(2).replace('.', ','); }\n"
			. "  function getVal(fieldId){ var el = qs('#input_'+fid+'_'+fieldId); return el ? (el.value||'') : ''; }\n"
			. "  function setVal(fieldId, v){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(!el) return;\n"
			. "    el.value = (v===null||v===undefined) ? '' : String(v);\n"
			. "    try{ el.dispatchEvent(new Event('change',{bubbles:true})); }catch(e){}\n"
			. "  }\n"
			. "  function getPrice(fieldId){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId+'_2');\n"
			. "    if(!el) return 0;\n"
			. "    return parseMoney(el.value);\n"
			. "  }\n"
			. "  function fix(){\n"
			. "    var pct = parseMoney(getVal(152));\n"
			. "    if(!(pct>0)) return;\n"
			. "    var ebPct = parseMoney(getVal(172));\n"
			. "    if(!(ebPct>=0)) ebPct = 0;\n"
			. "    var commPct = parseMoney(getVal(161));\n"
			. "    if(!(commPct>=0)) commPct = 0;\n"
			. "    var part = getPrice(138) || getPrice(137) || 0;\n"
			. "    var rental = getPrice(139) || getPrice(140) || getPrice(141) || getPrice(171) || 0;\n"
			. "    if(!(part>0) && !(rental>0)) return;\n"
			. "    function afterEb(x){ if(!(x>0)) return 0; return round2(x * (1 - (ebPct/100))); }\n"
			. "    var partAfterEb = afterEb(part);\n"
			. "    var rentalAfterEb = afterEb(rental);\n"
			. "    var ebDisc = round2( round2(part - partAfterEb) + round2(rental - rentalAfterEb) );\n"
			. "    var partDisc = round2(partAfterEb * (pct/100));\n"
			. "    var rentalDisc = round2(rentalAfterEb * (pct/100));\n"
			. "    var partnerDisc = round2(partDisc + rentalDisc);\n"
			. "    var baseAfterEb = round2(partAfterEb + rentalAfterEb);\n"
			. "    var discountTotal = round2(ebDisc + partnerDisc);\n"
			. "    var clientTotal = round2(baseAfterEb - partnerDisc);\n"
			. "    var commAmt = 0;\n"
			. "    if(commPct>0){\n"
			. "      var partComm = round2(partAfterEb * (commPct/100));\n"
			. "      var rentComm = round2(rentalAfterEb * (commPct/100));\n"
			. "      commAmt = round2(partComm + rentComm);\n"
			. "    }\n"
			. "    setVal(174, fmtMoney(baseAfterEb));\n"
			. "    setVal(175, fmtMoney(ebDisc));\n"
			. "    setVal(176, fmtMoney(partnerDisc));\n"
			. "    setVal(164, fmtMoney(discountTotal));\n"
			. "    setVal(168, fmtMoney(clientTotal));\n"
			. "    setVal(165, fmtMoney(commAmt));\n"
			. "  }\n"
			. "  function schedule(){ window.setTimeout(function(){ try{ fix(); }catch(e){} }, 60); }\n"
			. "  function onReady(fn){ if(document.readyState==='complete'||document.readyState==='interactive') return fn(); document.addEventListener('DOMContentLoaded', fn); }\n"
			. "  onReady(function(){\n"
			. "    schedule();\n"
			. "    if(window.jQuery){\n"
			. "      try{ window.jQuery(document).on('gform_post_conditional_logic', function(e, formId){ if(parseInt(formId,10)===fid) schedule(); }); }catch(e){}\n"
			. "    }\n"
			. "    try{\n"
			. "      var ids = [137,138,139,140,141,171,172,152,161];\n"
			. "      ids.forEach(function(id){\n"
			. "        var el = qs('#input_'+fid+'_'+id) || qs('#input_'+fid+'_'+id+'_2');\n"
			. "        if(!el || el.__tcBfRoundFixBound) return;\n"
			. "        el.__tcBfRoundFixBound = true;\n"
			. "        el.addEventListener('change', schedule);\n"
			. "      });\n"
			. "    }catch(e){}\n"
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
