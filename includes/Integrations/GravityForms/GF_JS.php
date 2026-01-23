<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Admin\Settings;
use TC_BF\Domain\PartnerResolver;

if ( ! defined('ABSPATH') ) exit;

/**
 * Gravity Forms JavaScript Injection
 *
 * Handles partner dropdown JavaScript injection for both Event and Booking forms.
 * Uses GF_SemanticFields for field resolution - unified code for both form types.
 *
 * @since TCBF-12
 * @updated TCBF-14 - Unified for both Event and Booking forms using semantic fields
 */
final class GF_JS {

	// GF partner dropdown JS payload (per request, static for global access)
	private static array $partner_js_payload = [];

	/**
	 * GF: Populate partner fields + inject JS for admin override dropdown.
	 *
	 * Works for both Event forms and Booking forms using semantic field resolution.
	 */
	public static function partner_prepare_form( $form ) {
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);

		// Check if this is an Event form OR Booking form
		$event_form_id = (int) Settings::get_form_id();
		$booking_form_id = (int) Settings::get_booking_form_id();

		$is_event_form = ( $form_id === $event_form_id );
		$is_booking_form = ( $form_id === $booking_form_id );

		if ( ! $is_event_form && ! $is_booking_form ) {
			return $form;
		}

		// Populate hidden partner fields into POST so GF calculations + conditional logic can use them.
		GF_Partner::prepare_post( $form_id );

		// Build partner map for JS (code => data)
		$partners = self::get_partner_map_for_js();

		// Determine initial partner code from context (admin override > logged-in partner > posted code)
		$ctx = PartnerResolver::resolve_partner_context( $form_id );
		$initial_code = ( ! empty($ctx) && ! empty($ctx['active']) && ! empty($ctx['code']) ) ? (string) $ctx['code'] : '';

		// Get translated strings for partner banner
		$i18n = self::get_partner_banner_i18n();

		// Resolve field IDs using semantic keys
		$field_ids = self::resolve_field_ids( $form_id );

		// Cache payload for footer output.
		self::$partner_js_payload[ $form_id ] = [
			'partners'     => $partners,
			'initial_code' => $initial_code,
			'i18n'         => $i18n,
			'field_ids'    => $field_ids,
		];

		// Also register an init script so this works even when GF renders via AJAX.
		self::register_partner_init_script( $form_id, $partners, $initial_code, $i18n, $field_ids );

		return $form;
	}

	/**
	 * Resolve all needed field IDs via GF_SemanticFields
	 *
	 * @param int $form_id GF form ID
	 * @return array Field IDs keyed by semantic name (0 if not found)
	 */
	private static function resolve_field_ids( int $form_id ) : array {
		return [
			// Partner fields
			'partner_override'     => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_OVERRIDE_CODE ) ?? 0,
			'coupon_code'          => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_COUPON_CODE ) ?? 0,
			'partner_discount_pct' => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_DISCOUNT_PCT ) ?? 0,
			'partner_commission'   => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_COMMISSION_PCT ) ?? 0,
			'partner_email'        => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_EMAIL ) ?? 0,
			'partner_user_id'      => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNER_USER_ID ) ?? 0,
			'partners_enabled'     => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_PARTNERS_ENABLED ) ?? 0,

			// Early booking fields
			'eb_discount_pct'      => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_EB_DISCOUNT_PCT ) ?? 0,

			// Display fields (product-type for visual presentation)
			'display_eb'           => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_DISPLAY_EB_DISCOUNT ) ?? 0,
			'display_partner'      => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_DISPLAY_PARTNER_DISCOUNT ) ?? 0,

			// Ledger fields (booking forms)
			'ledger_base'          => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_LEDGER_BASE ) ?? 0,
			'ledger_eb_pct'        => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_LEDGER_EB_PCT ) ?? 0,
			'ledger_eb_amount'     => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_LEDGER_EB_AMOUNT ) ?? 0,
			'ledger_partner_amt'   => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_LEDGER_PARTNER_AMOUNT ) ?? 0,
			'ledger_total'         => GF_SemanticFields::field_id( $form_id, GF_SemanticFields::KEY_LEDGER_TOTAL ) ?? 0,
		];
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
			$code = PartnerResolver::normalize_partner_code( $code );
			if ( $code === '' ) continue;

			$commission = (float) get_user_meta( $uid, 'usrdiscount', true );
			if ( $commission < 0 ) $commission = 0.0;

			$discount = PartnerResolver::get_coupon_percent_amount( $code );

			$map[ $code ] = [
				'id'         => $uid,
				'email'      => (string) (is_object($u) && isset($u->user_email) ? $u->user_email : ''),
				'commission' => $commission,
				'discount'   => $discount,
			];
		}

		return $map;
	}

	/**
	 * Get translated strings for partner banner (qTranslateX compatible).
	 */
	private static function get_partner_banner_i18n() : array {
		$title = '[:en]Partner Discount Applied[:es]Descuento Partner Aplicado[:]';
		$discount_label = '[:en]discount[:es]descuento[:]';
		$eb_label = '[:en]EARLY BOOKING[:es]RESERVA ANTICIPADA[:]';
		$base_label = '[:en]Base price[:es]Precio base[:]';
		$total_label = '[:en]Total[:es]Total[:]';

		// Use qTranslateX helper if available
		if ( function_exists( 'tc_sc_event_tr' ) ) {
			$title = tc_sc_event_tr( $title );
			$discount_label = tc_sc_event_tr( $discount_label );
			$eb_label = tc_sc_event_tr( $eb_label );
			$base_label = tc_sc_event_tr( $base_label );
			$total_label = tc_sc_event_tr( $total_label );
		} elseif ( function_exists( 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage' ) ) {
			$title = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $title );
			$discount_label = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $discount_label );
			$eb_label = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $eb_label );
			$base_label = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $base_label );
			$total_label = qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $total_label );
		}

		return [
			'title'          => $title,
			'discount_label' => $discount_label,
			'eb_label'       => $eb_label,
			'base_label'     => $base_label,
			'total_label'    => $total_label,
		];
	}

	private static function register_partner_init_script( int $form_id, array $partners, string $initial_code, array $i18n, array $field_ids ) : void {
		if ( $form_id <= 0 ) return;
		if ( ! class_exists('\GFFormDisplay') ) return;

		$script = self::build_partner_override_js( $form_id, $partners, $initial_code, $i18n, $field_ids );
		if ( $script === '' ) return;

		\GFFormDisplay::add_init_script(
			$form_id,
			'tc_bf_partner_override_' . $form_id,
			\GFFormDisplay::ON_PAGE_RENDER,
			$script
		);
	}

	/**
	 * Build unified partner JS for both Event and Booking forms.
	 *
	 * Uses semantic field IDs passed from PHP - no hardcoded field numbers in JS.
	 */
	private static function build_partner_override_js( int $form_id, array $partners, string $initial_code, array $i18n, array $field_ids ) : string {
		$json = wp_json_encode( $partners );
		$field_ids_json = wp_json_encode( $field_ids );

		// i18n strings (qTranslateX processed server-side)
		$banner_title   = esc_js( $i18n['title'] ?? 'Partner Discount Applied' );
		$discount_label = esc_js( $i18n['discount_label'] ?? 'discount' );
		$eb_label       = esc_js( $i18n['eb_label'] ?? 'EARLY BOOKING' );
		$base_label     = esc_js( $i18n['base_label'] ?? 'Base price' );
		$total_label    = esc_js( $i18n['total_label'] ?? 'Total' );

		return <<<JS
window.tcBfPartnerMap = window.tcBfPartnerMap || {};
window.tcBfPartnerMap[{$form_id}] = {$json};
(function(){
  var fid = {$form_id};
  var initialCode = '{$initial_code}';
  var F = {$field_ids_json};

  // i18n
  var i18n = {
    bannerTitle: '{$banner_title}',
    discount: '{$discount_label}',
    eb: '{$eb_label}',
    base: '{$base_label}',
    total: '{$total_label}'
  };

  function qs(sel, root){ return (root||document).querySelector(sel); }

  function parseLocaleFloat(raw){
    if(raw===null||typeof raw==='undefined') return 0;
    var s = String(raw).trim();
    if(!s) return 0;
    s = s.replace(/\u00A0/g,' ').replace(/€/g,'').trim();
    s = s.replace(/[^\d,.\-\s]/g,'').replace(/\s+/g,'');
    if(s.indexOf(',')!==-1 && s.indexOf('.')!==-1){
      s = s.replace(/\./g,'').replace(',', '.');
    } else if(s.indexOf(',')!==-1){
      s = s.replace(',', '.');
    }
    var n = parseFloat(s);
    return isNaN(n) ? 0 : n;
  }

  function fmtPct(v){
    if(v===null||typeof v==='undefined') return '';
    if(typeof v==='number' && isFinite(v)) v = String(v);
    var s = String(v).trim();
    if(!s) return '';
    if(s.indexOf(',') !== -1) return s;
    if(s.indexOf('.') !== -1) return s.replace('.', ',');
    return s;
  }

  function fmtCurrency(v){
    var n = parseLocaleFloat(v);
    return n.toLocaleString('es-ES', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
  }

  function getVal(fieldId){
    if(!fieldId || fieldId <= 0) return '';
    var el = qs('#input_'+fid+'_'+fieldId);
    return el ? (el.value||'').toString().trim() : '';
  }

  function setValIfChanged(fieldId, val, fire){
    if(!fieldId || fieldId <= 0) return false;
    var el = qs('#input_'+fid+'_'+fieldId);
    if(!el) return false;
    var next = (val===null||typeof val==='undefined') ? '' : String(val);
    // Numeric equivalence guard for percentage fields
    if(fieldId === F.partner_discount_pct || fieldId === F.partner_commission){
      var a = parseLocaleFloat(el.value);
      var b = parseLocaleFloat(next);
      if(a === b) return false;
    } else {
      if(el.value === next) return false;
    }
    el.value = next;
    if(fire){
      try{ el.dispatchEvent(new Event('change', {bubbles:true})); }catch(e){}
    }
    return true;
  }

  // ===== Partner Banner =====
  function updatePartnerBanner(data, code){
    var banner = qs('#tcbf-partner-banner-'+fid);
    if(!banner) return;
    if(data && code && data.discount){
      var titleEl = qs('.tcbf-partner-banner__title', banner);
      var nameEl = qs('.tcbf-partner-name', banner);
      var discEl = qs('.tcbf-partner-discount', banner);
      if(titleEl) titleEl.textContent = i18n.bannerTitle;
      if(nameEl) nameEl.textContent = code.toUpperCase();
      if(discEl) discEl.textContent = fmtPct(data.discount) + '% ' + i18n.discount;
      banner.style.display = 'flex';
    } else {
      banner.style.display = 'none';
    }
  }

  // ===== Enhanced Product Display (EB) =====
  function enhanceEBDisplay(){
    if(!F.display_eb || F.display_eb <= 0) return;
    var fieldWrap = qs('#field_'+fid+'_'+F.display_eb);
    if(!fieldWrap) return;
    var container = qs('.ginput_container', fieldWrap);
    if(!container) return;
    var enhanced = qs('.tcbf-eb-enhanced', container);
    if(!enhanced){
      enhanced = document.createElement('div');
      enhanced.className = 'tcbf-eb-enhanced';
      container.appendChild(enhanced);
      var wrapper = qs('.ginput_product_price_wrapper', fieldWrap);
      if(wrapper) wrapper.style.display = 'none';
    }
    var ebPct = parseLocaleFloat(getVal(F.eb_discount_pct) || getVal(F.ledger_eb_pct));
    if(ebPct > 0){
      var amountSpan = qs('#input_'+fid+'_'+F.display_eb);
      var amount = amountSpan ? amountSpan.textContent : '';
      enhanced.innerHTML = '<div class="tcbf-eb-badge"><span class="tcbf-eb-icon">⏰</span><span class="tcbf-eb-text">'+i18n.eb+'</span></div><div class="tcbf-eb-info"><div class="tcbf-eb-pct">'+fmtPct(ebPct)+'% '+i18n.discount+'</div><div class="tcbf-eb-amt">'+amount+'</div></div>';
      enhanced.style.display = 'flex';
    } else {
      enhanced.style.display = 'none';
    }
  }

  // ===== Enhanced Product Display (Partner) =====
  function enhancePartnerDisplay(data, code){
    if(!F.display_partner || F.display_partner <= 0) return;
    var fieldWrap = qs('#field_'+fid+'_'+F.display_partner);
    if(!fieldWrap) return;
    var container = qs('.ginput_container', fieldWrap);
    if(!container) return;
    var enhanced = qs('.tcbf-partner-enhanced', container);
    if(!enhanced){
      enhanced = document.createElement('div');
      enhanced.className = 'tcbf-partner-enhanced';
      container.appendChild(enhanced);
      var wrapper = qs('.ginput_product_price_wrapper', fieldWrap);
      if(wrapper) wrapper.style.display = 'none';
    }
    if(data && code && data.discount){
      var amountSpan = qs('#input_'+fid+'_'+F.display_partner);
      var amount = amountSpan ? amountSpan.textContent : '';
      enhanced.innerHTML = '<div class="tcbf-partner-badge"><span class="tcbf-partner-icon">✓</span><span class="tcbf-partner-code">'+code.toUpperCase()+'</span></div><div class="tcbf-partner-info"><div class="tcbf-partner-pct">'+fmtPct(data.discount)+'% '+i18n.discount+'</div><div class="tcbf-partner-amt">'+amount+'</div></div>';
      enhanced.style.display = 'flex';
    } else {
      enhanced.style.display = 'none';
    }
  }

  // ===== Ledger Summary (Booking forms) =====
  function updateLedgerSummary(data, code){
    var container = qs('#tcbf-booking-ledger-summary');
    if(!container) return;
    var base = parseLocaleFloat(getVal(F.ledger_base));
    var ebPct = parseLocaleFloat(getVal(F.ledger_eb_pct));
    var ebAmt = parseLocaleFloat(getVal(F.ledger_eb_amount));
    var partnerAmt = parseLocaleFloat(getVal(F.ledger_partner_amt));
    var total = parseLocaleFloat(getVal(F.ledger_total));
    var partnerCode = (code || getVal(F.coupon_code) || '').toUpperCase();
    var partnerPct = data ? parseLocaleFloat(data.discount) : parseLocaleFloat(getVal(F.partner_discount_pct));
    var html = '';
    // Base price row
    if(base > 0){
      html += '<div class="tcbf-ledger-row tcbf-ledger-base">';
      html += '<span class="tcbf-ledger-label">' + i18n.base + '</span>';
      html += '<span class="tcbf-ledger-value">' + fmtCurrency(base) + '</span>';
      html += '</div>';
    }
    // EB discount row
    if(ebAmt > 0){
      html += '<div class="tcbf-ledger-row tcbf-ledger-eb">';
      html += '<div class="tcbf-ledger-badge"><span class="tcbf-ledger-icon">⏰</span><span class="tcbf-ledger-text">' + i18n.eb + '</span></div>';
      html += '<div class="tcbf-ledger-info"><span class="tcbf-ledger-pct">' + fmtPct(ebPct) + '% ' + i18n.discount + '</span><span class="tcbf-ledger-amt">-' + fmtCurrency(ebAmt) + '</span></div>';
      html += '</div>';
    }
    // Partner discount row
    if(partnerAmt > 0 && partnerCode){
      html += '<div class="tcbf-ledger-row tcbf-ledger-partner">';
      html += '<div class="tcbf-ledger-badge"><span class="tcbf-ledger-icon">✓</span><span class="tcbf-ledger-text">' + partnerCode + '</span></div>';
      html += '<div class="tcbf-ledger-info"><span class="tcbf-ledger-pct">' + fmtPct(partnerPct) + '% ' + i18n.discount + '</span><span class="tcbf-ledger-amt">-' + fmtCurrency(partnerAmt) + '</span></div>';
      html += '</div>';
    }
    // Total row
    if(total > 0){
      html += '<div class="tcbf-ledger-row tcbf-ledger-total">';
      html += '<span class="tcbf-ledger-label">' + i18n.total + '</span>';
      html += '<span class="tcbf-ledger-value">' + fmtCurrency(total) + '</span>';
      html += '</div>';
    }
    container.innerHTML = html;
    container.style.display = html ? 'block' : 'none';
  }

  // ===== Partners Enabled Check =====
  function isPartnerProgramEnabled(){
    if(!F.partners_enabled || F.partners_enabled <= 0) return true; // fail-open
    try{
      var field = qs('#input_'+fid+'_'+F.partners_enabled);
      if(!field) return true;
      var val = (field.value||'').toString().trim();
      return val !== '0';
    }catch(e){ return true; }
  }

  // ===== Main Apply Partner Logic =====
  var applyPartnerInProgress = false;
  var applyPartnerTimer = null;

  function requestApplyPartner(){
    if(applyPartnerTimer) clearTimeout(applyPartnerTimer);
    applyPartnerTimer = setTimeout(applyPartner, 20);
  }

  function applyPartner(){
    if(applyPartnerInProgress) return;
    applyPartnerInProgress = true;
    try{
      var changed = false;

      if(!isPartnerProgramEnabled()){
        // Partners disabled: clear partner-derived hidden fields
        changed = setValIfChanged(F.coupon_code,'',false) || changed;
        changed = setValIfChanged(F.partner_discount_pct,'',true) || changed;
        changed = setValIfChanged(F.partner_commission,'',true) || changed;
        changed = setValIfChanged(F.partner_email,'',false) || changed;
        changed = setValIfChanged(F.partner_user_id,'',false) || changed;
        updatePartnerBanner(null, '');
        enhancePartnerDisplay(null, '');
        setTimeout(enhanceEBDisplay, 50);
        updateLedgerSummary(null, '');
        return;
      }

      var map = (window.tcBfPartnerMap && window.tcBfPartnerMap[fid]) ? window.tcBfPartnerMap[fid] : {};
      var sel = F.partner_override > 0 ? qs('#input_'+fid+'_'+F.partner_override) : null;
      var code = '';
      var useOverride = false;

      if(sel){
        var fieldWrap = qs('#field_'+fid+'_'+F.partner_override);
        var isVisible = fieldWrap && fieldWrap.offsetParent !== null && window.getComputedStyle(fieldWrap).display !== 'none';
        var hasValue = (sel.value||'').toString().trim() !== '';
        if(isVisible || hasValue){
          useOverride = true;
          code = (sel.value||'').toString().trim();
        }
      }

      if(!useOverride){
        var codeEl = F.coupon_code > 0 ? qs('#input_'+fid+'_'+F.coupon_code) : null;
        if(codeEl) code = (codeEl.value||'').toString().trim();
        if(!code && initialCode) code = initialCode;
      }

      if(sel && code && sel.value !== code){ try{ sel.value = code; }catch(e){} }

      var data = (code && map && map[code]) ? map[code] : null;

      if(!data){
        changed = setValIfChanged(F.coupon_code,'',false) || changed;
        changed = setValIfChanged(F.partner_discount_pct,'',true) || changed;
        changed = setValIfChanged(F.partner_commission,'',true) || changed;
        changed = setValIfChanged(F.partner_email,'',false) || changed;
        changed = setValIfChanged(F.partner_user_id,'',false) || changed;
        updatePartnerBanner(null, '');
        enhancePartnerDisplay(null, '');
      } else {
        changed = setValIfChanged(F.coupon_code, code, false) || changed;
        changed = setValIfChanged(F.partner_discount_pct, fmtPct(data.discount||''), true) || changed;
        changed = setValIfChanged(F.partner_commission, fmtPct(data.commission||''), true) || changed;
        changed = setValIfChanged(F.partner_email, (data.email||''), false) || changed;
        changed = setValIfChanged(F.partner_user_id, (data.id||''), false) || changed;
      }

      updatePartnerBanner(data, code);
      enhancePartnerDisplay(data, code);

      if(changed && typeof window.gformCalculateTotalPrice === 'function'){
        try{ window.gformCalculateTotalPrice(fid); }catch(e){}
      }

      setTimeout(function(){
        enhanceEBDisplay();
        updateLedgerSummary(data, code);
      }, 50);

    } finally {
      applyPartnerInProgress = false;
    }
  }

  // ===== Bind Events =====
  function bindOnce(){
    if(F.partner_override > 0){
      var sel = qs('#input_'+fid+'_'+F.partner_override);
      if(sel && !sel.__tcBfBound){
        sel.__tcBfBound = true;
        sel.addEventListener('change', requestApplyPartner);
      }
    }
  }

  // Hook into GF lifecycle
  if(window.jQuery){
    try{
      jQuery(document).on('gform_post_render', function(e, formId){ if(parseInt(formId,10)===fid){ bindOnce(); requestApplyPartner(); } });
      jQuery(document).on('gform_post_conditional_logic', function(e, formId){ if(parseInt(formId,10)===fid){ bindOnce(); requestApplyPartner(); } });
    }catch(e){}
  }
  setTimeout(function(){ bindOnce(); requestApplyPartner(); }, 60);
})();
JS;
	}

	public static function output_partner_js() : void {
		if ( empty( self::$partner_js_payload ) ) return;
		if ( is_admin() ) return;

		foreach ( self::$partner_js_payload as $form_id => $payload ) {
			$form_id = (int) $form_id;
			if ( $form_id <= 0 ) continue;

			$partners = (is_array($payload) && isset($payload['partners']) && is_array($payload['partners'])) ? $payload['partners'] : [];
			$initial_code = (is_array($payload) && isset($payload['initial_code'])) ? (string) $payload['initial_code'] : '';
			$i18n = (is_array($payload) && isset($payload['i18n']) && is_array($payload['i18n'])) ? $payload['i18n'] : [];
			$field_ids = (is_array($payload) && isset($payload['field_ids']) && is_array($payload['field_ids'])) ? $payload['field_ids'] : [];

			$js = self::build_partner_override_js( $form_id, $partners, $initial_code, $i18n, $field_ids );
			if ( $js === '' ) continue;

			echo "\n<script id=\"tc-bf-partner-override-{$form_id}\">\n";
			echo $js;
			echo "\n</script>\n";
		}
	}

	/**
	 * Output CSS for enhanced product displays and ledger summary.
	 */
	public static function output_partner_css() : void {
		if ( is_admin() ) return;

		// Output CSS whenever GF forms might be rendered
		// Broader check to ensure CSS is available for booking forms in various contexts
		$should_output = is_singular( 'product' )
			|| is_singular( 'sc_event' )
			|| is_cart()
			|| is_checkout()
			|| ! empty( self::$partner_js_payload );  // Form was processed

		if ( ! $should_output ) {
			return;
		}

		echo "\n<!-- TC Booking Flow: Partner UI CSS -->\n";
		echo "<style id=\"tcbf-partner-ui-css\">\n";

		// Partner Banner
		echo ".tcbf-partner-banner {
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  border-left: 4px solid #22c55e;
  padding: 16px 20px;
  margin: 16px 0;
  display: flex;
  align-items: center;
  gap: 14px;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(34, 197, 94, 0.1);
}
.tcbf-partner-banner__icon { font-size: 28px; color: #22c55e; }
.tcbf-partner-banner__title { font-size: 16px; font-weight: 700; color: #14532d; margin-bottom: 4px; }
.tcbf-partner-banner__details { font-size: 14px; color: #166534; display: flex; gap: 8px; }
.tcbf-partner-name { font-weight: 600; }
";

		// Enhanced EB Display
		echo ".tcbf-eb-enhanced {
  background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 8px 0;
}
.tcbf-eb-badge { display: flex; align-items: center; gap: 10px; }
.tcbf-eb-icon { font-size: 24px; }
.tcbf-eb-text { font-size: 14px; font-weight: 700; color: #fff; letter-spacing: 0.5px; }
.tcbf-eb-info { text-align: right; }
.tcbf-eb-pct { font-size: 13px; color: #fff; opacity: 0.9; }
.tcbf-eb-amt { font-size: 20px; font-weight: 700; color: #fff; }
";

		// Enhanced Partner Display
		echo ".tcbf-partner-enhanced {
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 8px 0;
}
.tcbf-partner-badge { display: flex; align-items: center; gap: 10px; }
.tcbf-partner-icon { font-size: 24px; color: #22c55e; }
.tcbf-partner-code { font-size: 16px; font-weight: 700; color: #14532d; }
.tcbf-partner-info { text-align: right; }
.tcbf-partner-pct { font-size: 13px; color: #14532d; }
.tcbf-partner-amt { font-size: 20px; font-weight: 700; color: #14532d; }
";

		// Ledger Summary
		echo ".tcbf-ledger-summary {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px;
  margin: 16px 0;
}
.tcbf-ledger-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #e2e8f0;
}
.tcbf-ledger-row:last-child { border-bottom: none; }
.tcbf-ledger-base { color: #475569; }
.tcbf-ledger-label { font-weight: 500; }
.tcbf-ledger-value { font-weight: 600; }
.tcbf-ledger-eb {
  background: linear-gradient(45deg, #3d61aa 0%, #b74d96 100%);
  border-radius: 6px;
  padding: 12px 16px;
  margin: 8px 0;
  border: none;
}
.tcbf-ledger-eb .tcbf-ledger-badge { display: flex; align-items: center; gap: 8px; }
.tcbf-ledger-eb .tcbf-ledger-icon { font-size: 20px; }
.tcbf-ledger-eb .tcbf-ledger-text { font-size: 14px; font-weight: 700; color: #fff; letter-spacing: 0.5px; }
.tcbf-ledger-eb .tcbf-ledger-info { text-align: right; }
.tcbf-ledger-eb .tcbf-ledger-pct { font-size: 13px; color: #fff; opacity: 0.9; }
.tcbf-ledger-eb .tcbf-ledger-amt { font-size: 18px; font-weight: 700; color: #fff; }
.tcbf-ledger-partner {
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  border-radius: 6px;
  padding: 12px 16px;
  margin: 8px 0;
  border: none;
}
.tcbf-ledger-partner .tcbf-ledger-badge { display: flex; align-items: center; gap: 8px; }
.tcbf-ledger-partner .tcbf-ledger-icon { font-size: 20px; color: #22c55e; }
.tcbf-ledger-partner .tcbf-ledger-text { font-size: 16px; font-weight: 700; color: #14532d; letter-spacing: 0.5px; }
.tcbf-ledger-partner .tcbf-ledger-info { text-align: right; }
.tcbf-ledger-partner .tcbf-ledger-pct { font-size: 13px; color: #14532d; }
.tcbf-ledger-partner .tcbf-ledger-amt { font-size: 18px; font-weight: 700; color: #14532d; }
.tcbf-ledger-total {
  padding-top: 12px;
  margin-top: 8px;
  border-top: 2px solid #cbd5e1;
}
.tcbf-ledger-total .tcbf-ledger-label { font-size: 16px; font-weight: 600; color: #1e293b; }
.tcbf-ledger-total .tcbf-ledger-value { font-size: 20px; font-weight: 700; color: #1e293b; }
";
		echo "</style>\n";
	}
}
