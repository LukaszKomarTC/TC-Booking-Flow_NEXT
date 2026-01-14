<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms JavaScript Injection
 *
 * Handles partner dropdown JavaScript injection for admin override functionality.
 * Extracted from Plugin class for better separation of concerns.
 */
final class GF_JS {

	// GF partner dropdown JS payload (per request, static for global access)
	private static $partner_js_payload = [];

	/**
	 * GF: Populate partner fields + inject JS for admin override dropdown (field 63).
	 *
	 * Active form id comes from Admin Settings (so clones like 47 work).
	 */
	public static function partner_prepare_form( $form ) {
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = (int) \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return $form;

		// Populate hidden partner fields into POST so GF calculations + conditional logic can use them.
		GF_Partner::prepare_post( $form_id );

		// Build partner map for JS (code => data)
		$partners = self::get_partner_map_for_js();

		// Determine initial partner code from context (admin override > logged-in partner > posted code)
		$ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );
		$initial_code = ( ! empty($ctx) && ! empty($ctx['active']) && ! empty($ctx['code']) ) ? (string) $ctx['code'] : '';

		// Cache payload for footer output.
		self::$partner_js_payload[ $form_id ] = [
			'partners'     => $partners,
			'initial_code' => $initial_code,
		];

		// Also register an init script so this works even when GF renders via AJAX.
		self::register_partner_init_script( $form_id, $partners, $initial_code );

		return $form;
	}

	private static function get_partner_map_for_js() : array {

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
			$code = \TC_BF\Domain\PartnerResolver::normalize_partner_code( $code );
			if ( $code === '' ) continue;

			$commission = (float) get_user_meta( $uid, 'usrdiscount', true );
			if ( $commission < 0 ) $commission = 0.0;

			$discount = \TC_BF\Domain\PartnerResolver::get_coupon_percent_amount( $code );

			$map[ $code ] = [
				'id'         => $uid,
				'email'      => (string) (is_object($u) && isset($u->user_email) ? $u->user_email : ''),
				'commission' => $commission,
				'discount'   => $discount,
			];
		}

		return $map;
	}


	private static function register_partner_init_script( int $form_id, array $partners, string $initial_code = '' ) : void {
		if ( $form_id <= 0 ) return;
		if ( ! class_exists('\GFFormDisplay') ) return;

		$script = self::build_partner_override_js( $form_id, $partners, $initial_code );
		if ( $script === '' ) return;

		// Runs reliably for normal and AJAX-rendered forms.
		\GFFormDisplay::add_init_script(
			$form_id,
			'tc_bf_partner_override_' . $form_id,
			\GFFormDisplay::ON_PAGE_RENDER,
			$script
		);
	}

	/**
	 * IMPORTANT CHANGE (TCBF-12):
	 * - Partner field VISIBILITY is handled by Gravity Forms conditional logic based on field 181.
	 * - JS must NOT hide/show fields or force recalculation loops.
	 * - JS only populates partner hidden fields (154/152/161/153/166) and applies GF-Woo rounding parity (176).
	 */
	private static function build_partner_override_js( int $form_id, array $partners, string $initial_code = '' ) : string {

		// Map: { code => {id,email,commission,discount} }
		$json = wp_json_encode( $partners );

		// IMPORTANT: this is raw JS (no <script> wrapper). GF will wrap it.
		return "window.tcBfPartnerMap = window.tcBfPartnerMap || {};\n"
			. "window.tcBfPartnerMap[{$form_id}] = {$json};\n"
			. "(function(){\n"
			. "  var fid = {$form_id};\n"
			. "  var initialCode = '" . esc_js( $initial_code ) . "';\n"
			. "  function qs(sel,root){ return (root||document).querySelector(sel); }\n"
			. "  function parseLocaleFloat(raw){\n"
			. "    if(raw===null||typeof raw==='undefined') return 0;\n"
			. "    var s = String(raw).trim();\n"
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
			. "  function fmtPct(v){\n"
			. "    if(v===null||typeof v==='undefined') return '';\n"
			. "    if(typeof v==='number' && isFinite(v)) v = String(v);\n"
			. "    var s = String(v).trim();\n"
			. "    if(!s) return '';\n"
			. "    if(s.indexOf(',') !== -1) return s;\n"
			. "    if(s.indexOf('.') !== -1) return s.replace('.', ',');\n"
			. "    return s;\n"
			. "  }\n"
			. "  function setValIfChanged(fieldId, val, fire){\n"
			. "    var el = qs('#input_'+fid+'_'+fieldId);\n"
			. "    if(!el) return false;\n"
			. "    var next = (val===null||typeof val==='undefined') ? '' : String(val);\n"
			. "    // Numeric equivalence guard (prevents comma/dot flip-flop loops)\n"
			. "    if(fieldId === 152 || fieldId === 161 || fieldId === 176){\n"
			. "      var a = parseLocaleFloat(el.value);\n"
			. "      var b = parseLocaleFloat(next);\n"
			. "      if(a === b) return false;\n"
			. "    } else {\n"
			. "      if(el.value === next) return false;\n"
			. "    }\n"
			. "    el.value = next;\n"
			. "    if(fire){\n"
			. "      try{ el.dispatchEvent(new Event('change', {bubbles:true})); }catch(e){}\n"
			. "    }\n"
			. "    return true;\n"
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
			. "  function updatePartnerBanner(data, code){\n"
			. "    var banner = qs('#tcbf-partner-banner-'+fid);\n"
			. "    if(!banner) return;\n"
			. "    if(data && code && data.discount){\n"
			. "      var titleEl = qs('.tcbf-partner-banner__title', banner);\n"
			. "      var nameEl = qs('.tcbf-partner-name', banner);\n"
			. "      var discEl = qs('.tcbf-partner-discount', banner);\n"
			. "      if(titleEl){\n"
			. "        titleEl.textContent = '[:en]Partner Discount Applied[:es]Descuento Partner Aplicado[:]';\n"
			. "      }\n"
			. "      if(nameEl){\n"
			. "        nameEl.textContent = code.toUpperCase();\n"
			. "      }\n"
			. "      if(discEl){\n"
			. "        discEl.textContent = fmtPct(data.discount) + '% [:en]discount[:es]descuento[:]';\n"
			. "      }\n"
			. "      banner.style.display = 'flex';\n"
			. "    } else {\n"
			. "      banner.style.display = 'none';\n"
			. "    }\n"
			. "  }\n"
			. "  function isPartnerProgramEnabled(){\n"
			. "    // Visibility is handled by GF conditional logic; this is only a guard.\n"
			. "    try{\n"
			. "      var field181 = qs('#input_'+fid+'_181');\n"
			. "      if(!field181) return true; // fail-open\n"
			. "      var val = (field181.value||'').toString().trim();\n"
			. "      return val !== '0';\n"
			. "    }catch(e){ return true; }\n"
			. "  }\n"
			. "  var applyPartnerInProgress = false;\n"
			. "  var applyPartnerTimer = null;\n"
			. "  function requestApplyPartner(){\n"
			. "    if(applyPartnerTimer) clearTimeout(applyPartnerTimer);\n"
			. "    applyPartnerTimer = setTimeout(applyPartner, 20);\n"
			. "  }\n"
			. "  function applyPartner(){\n"
			. "    if(applyPartnerInProgress) return;\n"
			. "    applyPartnerInProgress = true;\n"
			. "    try{\n"
			. "      var changed = false;\n"
			. "      if(!isPartnerProgramEnabled()){\n"
			. "        // Partners disabled: clear partner-derived hidden fields and exit.\n"
			. "        changed = setValIfChanged(154,'',false) || changed;\n"
			. "        changed = setValIfChanged(152,'',true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(161,'',true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(153,'',false) || changed;\n"
			. "        changed = setValIfChanged(166,'',false) || changed;\n"
			. "        changed = setValIfChanged(176,'0',false) || changed;\n"
			. "        toggleSummary(null, '');\n"
			. "        updatePartnerBanner(null, '');\n"
			. "        return;\n"
			. "      }\n"
			. "      var map = (window.tcBfPartnerMap && window.tcBfPartnerMap[fid]) ? window.tcBfPartnerMap[fid] : {};\n"
			. "      var sel = qs('#input_'+fid+'_63');\n"
			. "      var code = '';\n"
			. "      var useField63 = false;\n"
			. "      if(sel){\n"
			. "        var field63Wrap = qs('#field_'+fid+'_63');\n"
			. "        var isVisible = field63Wrap && field63Wrap.offsetParent !== null && window.getComputedStyle(field63Wrap).display !== 'none';\n"
			. "        var hasValue = (sel.value||'').toString().trim() !== '';\n"
			. "        if(isVisible || hasValue){\n"
			. "          useField63 = true;\n"
			. "          code = (sel.value||'').toString().trim();\n"
			. "        }\n"
			. "      }\n"
			. "      if(!useField63){\n"
			. "        var codeEl = qs('#input_'+fid+'_154'); if(codeEl) code = (codeEl.value||'').toString().trim();\n"
			. "        if(!code && initialCode){ code = (initialCode||'').toString().trim(); }\n"
			. "      }\n"
			. "      if(sel && code && sel.value !== code){ try{ sel.value = code; }catch(e){} }\n"
			. "      var data = (code && map && map[code]) ? map[code] : null;\n"
			. "      if(!data){\n"
			. "        changed = setValIfChanged(154,'',false) || changed;\n"
			. "        changed = setValIfChanged(152,'',true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(161,'',true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(153,'',false) || changed;\n"
			. "        changed = setValIfChanged(166,'',false) || changed;\n"
			. "        updatePartnerBanner(null, '');\n"
			. "      } else {\n"
			. "        changed = setValIfChanged(154,code,false) || changed;\n"
			. "        changed = setValIfChanged(152, fmtPct(data.discount||''),true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(161, fmtPct(data.commission||''),true) || changed;  // Fire change to update calc fields\n"
			. "        changed = setValIfChanged(153,(data.email||''),false) || changed;\n"
			. "        changed = setValIfChanged(166,(data.id||''),false) || changed;\n"
			. "      }\n"
			. "      toggleSummary(data, code);\n"
			. "      updatePartnerBanner(data, code);\n"
			. "      if(changed && typeof window.gformCalculateTotalPrice === 'function'){\n"
			. "        try{ window.gformCalculateTotalPrice(fid); }catch(e){}\n"
			. "      }\n"
			. "    } finally {\n"
			. "      applyPartnerInProgress = false;\n"
			. "    }\n"
			. "  }\n"
			. "  // ---- Partner discount rounding parity (GF vs Woo) ----\n"
			. "  function roundDown2(v){\n"
			. "    var n = parseFloat(v);\n"
			. "    if(!isFinite(n)) return v;\n"
			. "    return String(Math.floor((n + 1e-9) * 100) / 100);\n"
			. "  }\n"
			. "  function initPartnerDiscountOverride(){\n"
			. "    if(!window.gform || !gform.addFilter){ return false; }\n"
			. "    try{\n"
			. "      gform.addFilter('gform_calculation_result', function(result, formulaField, formId){\n"
			. "        try{\n"
			. "          if(parseInt(formId,10) !== fid) return result;\n"
			. "          var fieldId = parseInt((formulaField && (formulaField.field_id || formulaField.id)) || 0, 10);\n"
			. "          if(fieldId !== 176) return result;\n"
			. "          if(!isPartnerProgramEnabled()) return '0';\n"
			. "          return roundDown2(result);\n"
			. "        }catch(e){ return result; }\n"
			. "      });\n"
			. "      return true;\n"
			. "    }catch(e){ return false; }\n"
			. "  }\n"
			. "  function bindOnce(){\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    if(sel && !sel.__tcBfBound){\n"
			. "      sel.__tcBfBound = true;\n"
			. "      sel.addEventListener('change', requestApplyPartner);\n"
			. "    }\n"
			. "    if(!window.__tcBfPartnerDiscInitialized){\n"
			. "      window.__tcBfPartnerDiscInitialized = initPartnerDiscountOverride();\n"
			. "    }\n"
			. "  }\n"
			. "  // Hook into GF lifecycle (NO MutationObserver)\n"
			. "  if(window.jQuery){\n"
			. "    try{\n"
			. "      jQuery(document).on('gform_post_render', function(e, formId){ if(parseInt(formId,10)===fid){ bindOnce(); requestApplyPartner(); } });\n"
			. "      jQuery(document).on('gform_post_conditional_logic', function(e, formId){ if(parseInt(formId,10)===fid){ bindOnce(); requestApplyPartner(); } });\n"
			. "    }catch(e){}\n"
			. "  }\n"
			. "  setTimeout(function(){ bindOnce(); requestApplyPartner(); }, 60);\n"
			. "})();\n";
	}

	public static function output_partner_js() : void {

		if ( empty( self::$partner_js_payload ) ) return;
		if ( is_admin() ) return;

		foreach ( self::$partner_js_payload as $form_id => $payload ) {
			$form_id = (int) $form_id;
			if ( $form_id <= 0 ) continue;

			$partners = (is_array($payload) && isset($payload['partners']) && is_array($payload['partners'])) ? $payload['partners'] : [];
			$initial_code = (is_array($payload) && isset($payload['initial_code'])) ? (string) $payload['initial_code'] : '';

			$js = self::build_partner_override_js( $form_id, $partners, $initial_code );
			if ( $js === '' ) continue;

			echo "\n<script id=\"tc-bf-partner-override-{$form_id}\">\n";
			echo $js;
			echo "\n</script>\n";
		}
	}

}
