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
				. "    // Remove currency/symbols/spaces; keep digits, separators and minus\n"
				. "    s = s.replace(/\u00A0/g,' ').replace(/€/g,'').trim();\n"
				. "    s = s.replace(/[^\d,\.\-\s]/g,'');\n"
				. "    s = s.replace(/\s+/g,'');\n"
				. "    // If both '.' and ',' exist -> assume '.' thousands and ',' decimal (1.234,56)\n"
				. "    if(s.indexOf(',')!==-1 && s.indexOf('.')!==-1){\n"
				. "      s = s.replace(/\./g,'');\n"
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
			. "    var useField63 = false;\n"
			. "    // Only treat field 63 as authoritative if it's actually visible/enabled OR has explicit value.\n"
			. "    // This prevents hidden admin override field from wiping partner context for Way 1/2.\n"
			. "    if(sel){\n"
			. "      var field63Wrap = qs('#field_'+fid+'_63');\n"
			. "      var isVisible = field63Wrap && field63Wrap.offsetParent !== null && window.getComputedStyle(field63Wrap).display !== 'none';\n"
			. "      var hasValue = (sel.value||'').toString().trim() !== '';\n"
			. "      // Field 63 is authoritative ONLY if visible OR has explicit value\n"
			. "      if(isVisible || hasValue){\n"
			. "        useField63 = true;\n"
			. "        code = (sel.value||'').toString().trim();\n"
			. "      }\n"
			. "    }\n"
			. "    // Fallback: use hidden coupon field or initial context if field 63 not authoritative\n"
			. "    if(!useField63){\n"
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
			. "  // ---- Partner discount WC-parity calculation (per-scope rounding) ----\n"
			. "  // Override field 176 calculation to match WooCommerce per-item coupon rounding.\n"
			. "  function initPartnerDiscountOverride(){\n"
			. "    if(!window.gform || !gform.addFilter){ console.warn('[partner_disc] gform.addFilter not available'); return false; }\n"
			. "    try{\n"
			. "      console.log('[partner_disc] Binding gform_calculation_result hook for form', fid);\n"
			. "      gform.addFilter('gform_calculation_result', function(result, formulaField, formId){\n"
			. "        try{\n"
			. "          if(parseInt(formId,10) !== fid) return result;\n"
			. "          var fieldId = parseInt((formulaField && (formulaField.field_id || formulaField.id)) || 0, 10);\n"
			. "          console.log('[partner_disc] calc_result fired for field', fieldId, 'original result:', result);\n"
			. "          if(fieldId !== 176) return result; // Only intercept partner discount field\n"
			. "          // Read partner %\n"
			. "          var partnerPct = parseLocaleFloat((qs('#input_'+fid+'_152')||{}).value||0);\n"
			. "          console.log('[partner_disc] partnerPct:', partnerPct);\n"
			. "          if(partnerPct <= 0) { console.log('[partner_disc] No partner %, using default'); return result; }\n"
			. "          // Read EB % and scope totals from new hidden calculation fields\n"
			. "          var ebPct = parseLocaleFloat((qs('#input_'+fid+'_172')||{}).value||0);\n"
			. "          var partTotal = parseLocaleFloat((qs('#input_'+fid+'_181')||{}).value||0);\n"
			. "          var rentalTotal = parseLocaleFloat((qs('#input_'+fid+'_182')||{}).value||0);\n"
			. "          console.log('[partner_disc] Per-scope calculation: partTotal='+partTotal+', rentalTotal='+rentalTotal+', ebPct='+ebPct);\n"
			. "          var subtotalOriginal = partTotal + rentalTotal;\n"
			. "          if(subtotalOriginal <= 0) { console.log('[partner_disc] No subtotal, using default'); return result; }\n"
			. "          // Calculate EB amount (on original subtotal)\n"
			. "          var ebAmount = 0;\n"
			. "          if(ebPct > 0){\n"
			. "            ebAmount = Math.round((subtotalOriginal * (ebPct/100) + 0.000000001) * 100) / 100;\n"
			. "          }\n"
			. "          // Distribute EB proportionally between participation and rental scopes\n"
			. "          var ebAmtPart = 0, ebAmtRental = 0;\n"
			. "          if(ebAmount > 0 && subtotalOriginal > 0){\n"
			. "            ebAmtPart = Math.round((ebAmount * (partTotal / subtotalOriginal) + 0.000000001) * 100) / 100;\n"
			. "            ebAmtRental = Math.round((ebAmount * (rentalTotal / subtotalOriginal) + 0.000000001) * 100) / 100;\n"
			. "            // Drift correction to ensure sum equals total EB\n"
			. "            var drift = Math.round((ebAmount - (ebAmtPart + ebAmtRental)) * 100) / 100;\n"
			. "            if(Math.abs(drift) > 0.0001){\n"
			. "              if(rentalTotal > 0) ebAmtRental = Math.max(0, ebAmtRental + drift);\n"
			. "              else ebAmtPart = Math.max(0, ebAmtPart + drift);\n"
			. "            }\n"
			. "          }\n"
			. "          // Calculate per-scope bases after EB\n"
			. "          var partBaseAfterEB = Math.max(0, Math.round((partTotal - ebAmtPart + 0.000000001) * 100) / 100);\n"
			. "          var rentalBaseAfterEB = Math.max(0, Math.round((rentalTotal - ebAmtRental + 0.000000001) * 100) / 100);\n"
			. "          // Calculate per-scope partner discount (matches WC per-item rounding)\n"
			. "          var discPart = 0;\n"
			. "          var discRental = 0;\n"
			. "          if(partnerPct > 0){\n"
			. "            discPart = Math.round((partBaseAfterEB * (partnerPct/100) + 0.000000001) * 100) / 100;\n"
			. "            if(rentalTotal > 0){\n"
			. "              discRental = Math.round((rentalBaseAfterEB * (partnerPct/100) + 0.000000001) * 100) / 100;\n"
			. "            }\n"
			. "          }\n"
			. "          var totalDisc = discPart + discRental;\n"
			. "          console.log('[partner_disc] EB distribution: part='+ebAmtPart+', rental='+ebAmtRental);\n"
			. "          console.log('[partner_disc] Bases after EB: part='+partBaseAfterEB+', rental='+rentalBaseAfterEB);\n"
			. "          console.log('[partner_disc] Per-scope discounts: part='+discPart+', rental='+discRental+', total='+totalDisc);\n"
			. "          console.log('[partner_disc] Returning (WC-parity):', String(totalDisc.toFixed(2)));\n"
			. "          return String(totalDisc.toFixed(2));\n"
			. "        }catch(e){ console.error('[partner_disc_override]', e); return result; }\n"
			. "      });\n"
			. "      return true;\n"
			. "    }catch(e){ console.error('[init_partner_disc_override]', e); return false; }\n"
			. "  }\n"
			. "  function bind(){\n"
			. "    var sel = qs('#input_'+fid+'_63');\n"
			. "    if(sel && !sel.__tcBfBound){\n"
			. "      sel.__tcBfBound = true;\n"
			. "      sel.addEventListener('change', applyPartner);\n"
			. "    }\n"
			. "    // Initialize partner discount override (once)\n"
			. "    if(!window.__tcBfPartnerDiscInitialized){\n"
			. "      window.__tcBfPartnerDiscInitialized = initPartnerDiscountOverride();\n"
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
