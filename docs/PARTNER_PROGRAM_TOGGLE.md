# Partner Program Toggle Feature (TCBF-12)

## Overview

The partner program toggle feature allows events to enable or disable the partner/coupon discount system on a per-event basis. When partners are disabled for an event, all partner-related fields are hidden from the booking form and partner coupons are blocked at cart validation.

**Key Concepts:**
- **Event-level toggle**: Each event has a `tcbf_partners_enabled` meta field ('0' or '1')
- **Session-level partner**: A specific partner may be active for a booking session
- **Two-tier visibility**: Fields show only when BOTH event enables partners AND a partner is active

## Architecture Components

### 1. Database Layer
- **Event Meta**: `tcbf_partners_enabled` (post meta on event CPT)
  - Value: '0' (disabled) or '1' (enabled)
  - Accessed via: `\TC_BF\Domain\EventMeta::event_partners_enabled($event_id)`

### 2. Gravity Forms Integration
- **Form ID**: 48 (configurable via Admin Settings)
- **Total Partner Fields**: 11 (1 toggle + 5 hidden data + 2 visible calculations + 1 product + 2 admin override)

### 3. Code Files

| File | Purpose | Key Functions |
|------|---------|---------------|
| `includes/Domain/EventMeta.php` | Event meta access | `event_partners_enabled()` |
| `includes/Domain/PartnerResolver.php` | Partner context resolution | `resolve_partner_context()` |
| `includes/class-tc-bf-sc-event-extras.php` | Field 181 population (PHP) | Filter: `gform_field_value_partners_enabled` |
| `includes/Integrations/GravityForms/GF_Partner.php` | Hidden field population (PHP) | `prepare_post()` |
| `includes/Integrations/GravityForms/GF_JS.php` | Admin override & JS calculations | `partner_prepare_form()`, `build_partner_override_js()` |
| `includes/Integrations/GravityForms/GF_Validation.php` | Server-side validation | `validate_event_extras()` |
| `includes/Integrations/GravityForms/GF_Discount_Rounding.php` | Rounding parity with WooCommerce | Filter: `gform_product_info` |
| `includes/Plugin.php` | Cart & coupon validation | `woo_validate_partner_coupon()`, `woo_maybe_remove_partner_coupons()` |

---

## Field Structure & Dependencies

### Field Dependency Graph

```
Event Meta (tcbf_partners_enabled)
    ↓
Field 181 (Partners Enabled Hidden Field)
    ↓
    ├─→ Field 63 (Admin Override Dropdown) - Conditional Logic
    ├─→ Field 165 (Commission Amount Calculation) - Conditional Logic
    ├─→ Field 176 (Discount Amount Calculation) - Conditional Logic
    └─→ GF_Partner::prepare_post() - Runtime Gate
            ↓
        Partner Context Resolution
            ↓
        Fields 152, 154, 161, 153, 166 (Hidden Data Fields)
            ↓
            ├─→ Field 165 (Calculation: base × commission%)
            ├─→ Field 176 (Calculation: base × discount%)
            └─→ Field 180 (Product: Partner Discount)
```

### Field Definitions

#### Field 181: Partners Enabled (Toggle Status)
**Configuration:**
- **Type**: Hidden
- **Label**: "Partners Enabled"
- **Parameter Name**: `partners_enabled` (stored in `inputName`)
- **Default Value**: Empty (must be empty)
- **Allow Prepopulate**: Yes
- **Populated By**: PHP filter in `class-tc-bf-sc-event-extras.php:209-218`
- **Population Timing**: During `wp` action (before form render)
- **Value**: '0' (disabled) or '1' (enabled)
- **Purpose**: Master toggle that controls visibility of all partner fields

**PHP Population Code:**
```php
// File: includes/class-tc-bf-sc-event-extras.php
// Lines: 207-218
add_filter('gform_field_value_partners_enabled', function() use ($event_id) {
    $enabled = \TC_BF\Domain\EventMeta::event_partners_enabled($event_id);
    $value = $enabled ? '1' : '0';
    self::log('frontend.gf_population.partners_enabled', [
        'event_id' => $event_id,
        'enabled' => $enabled,
        'value' => $value,
    ]);
    return $value;
});
```

**Critical Note**: The `inputName` field must contain `"partners_enabled"` and `defaultValue` must be empty for the filter to work.

---

#### Field 63: Admin Override Dropdown
**Configuration:**
- **Type**: Select (Dropdown)
- **Label**: "Inscription made for" (multilingual)
- **Admin Label**: `inscription_for`
- **Parameter Name**: `inscription_for`
- **Purpose**: Allows administrators to manually select a partner for any booking

**Conditional Logic:**
```
Show if ALL match:
- Field 6 (user_role) contains "administrator"
- Field 181 is not equal to "0"
```

**Behavior:**
- Only visible to logged-in administrators
- Only visible when partners are enabled for the event
- Populated with list of all partners (user role: `hotel_partner`)
- Triggers JavaScript `applyPartner()` on change
- Takes highest priority in partner resolution

**Partner Resolution Priority:**
```php
// File: includes/Domain/PartnerResolver.php
// Lines: 23-29
1. Admin override (field 63) - Highest priority
2. Logged-in partner user meta (discount__code)
3. Coupon already applied in WC cart/session
4. Posted manual coupon field 154 - Lowest priority
```

---

#### Field 67: Admin Override ID (Calculated)
**Configuration:**
- **Type**: Number (Calculation)
- **Label**: "Inscription made for - ID"
- **Visibility**: Hidden
- **Calculation Formula**: `{inscription_for:63}`
- **Purpose**: Stores the numeric partner ID selected from field 63

**Status**: ✅ Active - Not Legacy
- Field 67 automatically copies the value from field 63
- Used for data storage and backend processing
- Do NOT remove this field

---

#### Field 154: Discount Code (Hidden Data Field)
**Configuration:**
- **Type**: Hidden
- **Label**: "Discount code"
- **Parameter Name**: `discount_code`
- **Allow Prepopulate**: Yes
- **Populated By**:
  - PHP: `GF_Partner::prepare_post()` before form render
  - JavaScript: `applyPartner()` when field 63 changes

**Population Logic (PHP):**
```php
// File: includes/Integrations/GravityForms/GF_Partner.php
// Lines: 23-58

public static function prepare_post( int $form_id ) : void {
    $event_id = self::resolve_event_id_from_request( $form_id );

    // TCBF-12 gate: if partners disabled, force-clear
    if ( $event_id > 0 && class_exists('TC_BF\\Domain\\EventMeta') ) {
        try {
            if ( ! \TC_BF\Domain\EventMeta::event_partners_enabled( $event_id ) ) {
                self::clear_partner_fields();
                return;
            }
        } catch ( \Throwable $e ) {}
    }

    // Resolve active partner context
    $ctx = \TC_BF\Domain\PartnerResolver::resolve_partner_context( $form_id );

    if ( empty($ctx) || empty($ctx['active']) ) {
        self::clear_partner_fields();
        return;
    }

    // Populate field 154 with coupon code
    $_POST['input_154'] = (string) ($ctx['code'] ?? '');

    // ... populate other fields
}
```

**Population Logic (JavaScript):**
```javascript
// File: includes/Integrations/GravityForms/GF_JS.php
// Lines: 236, 230, 201

// When partner data exists:
changed = setValIfChanged(154, code, false) || changed;

// When no partner data:
changed = setValIfChanged(154, '', false) || changed;

// When partners disabled:
changed = setValIfChanged(154, '', false) || changed;
```

**Dependencies:**
- Field 154 is checked by field 180 (Partner Discount Product)
- Historically checked by fields 165, 176 (now check field 181)

---

#### Field 152: Partner Discount % (Hidden Data Field)
**Configuration:**
- **Type**: Hidden
- **Label**: "Partner Discount %"
- **Parameter Name**: `discount`
- **Allow Prepopulate**: Yes
- **Value Format**: Decimal comma (e.g., "7,5" not "7.5")
- **Populated By**: Same as field 154

**Population Logic (PHP):**
```php
// File: includes/Integrations/GravityForms/GF_Partner.php
// Lines: 52
$_POST['input_152'] = (string) \TC_BF\Support\Money::pct_to_gf_str(
    (float) ($ctx['discount_pct'] ?? 0)
);
```

**Population Logic (JavaScript):**
```javascript
// File: includes/Integrations/GravityForms/GF_JS.php
// Lines: 237, 231, 202

// When partner data exists (fire=true triggers calculations):
changed = setValIfChanged(152, fmtPct(data.discount||''), true) || changed;

// When no partner data (fire=true clears calculations):
changed = setValIfChanged(152, '', true) || changed;

// When partners disabled (fire=true clears calculations):
changed = setValIfChanged(152, '', true) || changed;
```

**Critical**: Field 152 fires change events (`fire: true`) to trigger recalculation of field 176.

**Dependencies:**
- Field 176 (Partner Discount Amount) uses field 152 in its calculation formula

---

#### Field 161: Partner Commission % (Hidden Data Field)
**Configuration:**
- **Type**: Hidden
- **Label**: "Partner commission %"
- **Parameter Name**: `partner_commission`
- **Allow Prepopulate**: Yes
- **Value Format**: Decimal comma (e.g., "20,0")
- **Populated By**: Same as field 152

**Population Logic (PHP):**
```php
// File: includes/Integrations/GravityForms/GF_Partner.php
// Lines: 53
$_POST['input_161'] = (string) \TC_BF\Support\Money::pct_to_gf_str(
    (float) ($ctx['commission_pct'] ?? 0)
);
```

**Population Logic (JavaScript):**
```javascript
// File: includes/Integrations/GravityForms/GF_JS.php
// Lines: 238, 232, 203

// When partner data exists (fire=true triggers calculations):
changed = setValIfChanged(161, fmtPct(data.commission||''), true) || changed;

// When no partner data (fire=true clears calculations):
changed = setValIfChanged(161, '', true) || changed;

// When partners disabled (fire=true clears calculations):
changed = setValIfChanged(161, '', true) || changed;
```

**Critical**: Field 161 fires change events (`fire: true`) to trigger recalculation of field 165.

**Dependencies:**
- Field 165 (Partner Commission Amount) uses field 161 in its calculation formula

---

#### Field 153: Partner Email (Hidden Data Field)
**Configuration:**
- **Type**: Hidden
- **Label**: "Partner email"
- **Parameter Name**: `partner_email`
- **Populated By**: Same as field 152

**Population Logic (PHP):**
```php
// File: includes/Integrations/GravityForms/GF_Partner.php
// Lines: 54
$_POST['input_153'] = (string) ($ctx['partner_email'] ?? '');
```

**Population Logic (JavaScript):**
```javascript
// Lines: 239, 233, 204
changed = setValIfChanged(153, (data.email||''), false) || changed;
```

---

#### Field 166: Partner ID (Hidden Data Field)
**Configuration:**
- **Type**: Hidden
- **Label**: "Partner ID"
- **Parameter Name**: `partnerID`
- **Populated By**: Same as field 152

**Population Logic (PHP):**
```php
// File: includes/Integrations/GravityForms/GF_Partner.php
// Lines: 55
$_POST['input_166'] = (string) ((int) ($ctx['partner_user_id'] ?? 0));
```

**Population Logic (JavaScript):**
```javascript
// Lines: 240, 234, 205
changed = setValIfChanged(166, (data.id||''), false) || changed;
```

---

#### Field 165: Partner Commission Amount (Visible Calculation)
**Configuration:**
- **Type**: Number (Calculation)
- **Label**: "Partner commission amount"
- **Admin Label**: Empty
- **Visibility**: Visible
- **Calculation Formula**: `({Base after EB:174} / 100) * {Partner commission %:161}`
- **Calculation Rounding**: No rounding specified (uses GF default)
- **Enable Calculation**: Yes

**Conditional Logic:**
```
Show if ALL match:
- Field 181 is not equal to "0"
```

**How It Works:**
1. Field 181 = '1' → Field 165 becomes visible
2. Field 161 fires change event → GF recalculates field 165
3. Formula: `(base_price / 100) * commission_percent`
4. Result displays as formatted currency (e.g., "45,00 €")

**Dependencies:**
- **Input**: Field 174 (Base price after early booking), Field 161 (Commission %)
- **Trigger**: Field 161 change events
- **Visibility**: Field 181 value

---

#### Field 176: Partner Discount Amount (Visible Calculation)
**Configuration:**
- **Type**: Number (Calculation)
- **Label**: "Descuento partner" (Partner discount)
- **Admin Label**: "Descuento partner"
- **Visibility**: Visible
- **Calculation Formula**: `{Base after EB:174} * ( {Discount %:152} / 100 )`
- **Calculation Rounding**: 2 decimals
- **Enable Calculation**: Yes

**Conditional Logic:**
```
Show if ALL match:
- Field 181 is not equal to "0"
```

**Server-Side Rounding Override:**
```javascript
// File: includes/Integrations/GravityForms/GF_JS.php
// Lines: 256-270

gform.addFilter('gform_calculation_result', function(result, formulaField, formId){
    if(parseInt(formId,10) !== fid) return result;
    var fieldId = parseInt((formulaField && (formulaField.field_id || formulaField.id)) || 0, 10);
    if(fieldId !== 176) return result;
    if(!isPartnerProgramEnabled()) return '0';
    return roundDown2(result);  // Floor rounding to 2 decimals
});

function roundDown2(v){
    var n = parseFloat(v);
    if(!isFinite(n)) return v;
    return String(Math.floor((n + 1e-9) * 100) / 100);
}
```

**PHP Server-Side Rounding:**
```php
// File: includes/Integrations/GravityForms/GF_Discount_Rounding.php
// Lines: 41-54

// Override partner discount product to match GF floor rounding
$partners_enabled = isset($_POST['input_181']) ? trim((string) $_POST['input_181']) : '1';
if ( $partners_enabled === '0' ) {
    return '0';
}

$partner_discount_pct = (float) ($field_152_val ?? 0);
if ( $partner_discount_pct > 0 ) {
    $base_after_eb = (float) ($field_174_val ?? 0);
    $partner_disc = $base_after_eb * ( $partner_discount_pct / 100 );
    $rounded = floor( ($partner_disc + 1e-9) * 100 ) / 100;
    return number_format( $rounded, 2, ',', '' );
}
```

**Purpose of Rounding:**
- Ensures GF and WooCommerce calculate identical discount amounts
- Prevents cart validation failures due to rounding differences
- Uses floor rounding (round down) for consistency

**Dependencies:**
- **Input**: Field 174 (Base price after early booking), Field 152 (Discount %)
- **Trigger**: Field 152 change events
- **Visibility**: Field 181 value

---

#### Field 180: Partner Discount Product
**Configuration:**
- **Type**: Product
- **Label**: "Partner discount"
- **Base Price**: "0,00 €"
- **Product Field Type**: Discount product (negative price)

**Conditional Logic:**
```
Show if ALL match:
- Field 154 is not equal to ""
- Field 176 > 0
```

**How It Works:**
1. Product price is derived from field 176 (as negative value)
2. Only shows when field 154 has a coupon code AND field 176 > 0
3. Adds negative line item to cart total (discount)

**Dependencies:**
- **Visibility**: Field 154 (must have value), Field 176 (must be > 0)
- **Price Source**: Field 176 (Partner Discount Amount)

**Why Different Conditional Logic:**
- Fields 165, 176 check field 181 (show when partners enabled)
- Field 180 checks field 154 (show only when partner is ACTIVE)
- This prevents showing "0,00 €" discount when no partner is active

---

## Data Flow & Population Sequence

### Initial Page Load (PHP)

```
1. WordPress 'wp' action fires
   ↓
2. class-tc-bf-sc-event-extras.php:maybe_hook_gf_population()
   Registers filter: gform_field_value_partners_enabled
   ↓
3. Gravity Forms pre_render
   ↓
4. GF_JS.php:partner_prepare_form() called
   ├─→ GF_Partner::prepare_post() - Populates $_POST with partner data
   │   ├─→ Check: event_partners_enabled($event_id)
   │   │   If '0': Clear all partner fields, return
   │   │   If '1': Continue
   │   ├─→ PartnerResolver::resolve_partner_context($form_id)
   │   │   Priority:
   │   │   1. Admin override (field 63 in $_POST)
   │   │   2. Logged-in user meta (discount__code)
   │   │   3. WooCommerce cart applied coupons
   │   │   4. Posted field 154
   │   └─→ Populate $_POST['input_152'], ['input_154'], ['input_161'], etc.
   │
   └─→ Build JavaScript partner map
       └─→ Inject JS code into form footer
   ↓
5. GF renders form with populated field values
   ↓
6. Field 181 = '0' or '1' (from PHP filter)
   Field 152, 154, 161, 153, 166 = populated if partner active
   ↓
7. GF Conditional Logic evaluates:
   - Field 63: Show if admin AND field 181 ≠ '0'
   - Field 165: Show if field 181 ≠ '0'
   - Field 176: Show if field 181 ≠ '0'
   - Field 180: Show if field 154 ≠ '' AND field 176 > 0
   ↓
8. JavaScript loads
   ↓
9. bindOnce() registers field 63 change listener
   ↓
10. applyPartner() runs once
    ↓
11. Form is interactive
```

### Admin Changes Field 63 (JavaScript)

```
1. Admin selects partner from field 63 dropdown
   ↓
2. 'change' event fires on field 63
   ↓
3. requestApplyPartner() called
   ↓ (20ms debounce)
4. applyPartner() executes
   ├─→ Check isPartnerProgramEnabled() (field 181 ≠ '0')
   │   If false: Clear all fields, return
   │   If true: Continue
   ├─→ Get selected code from field 63
   ├─→ Lookup partner data in window.tcBfPartnerMap[formId][code]
   ├─→ If no data: Clear fields 152, 154, 161, 153, 166
   │   If data exists:
   │       setValIfChanged(154, code, false)
   │       setValIfChanged(152, discount%, true)  ← Fires change event
   │       setValIfChanged(161, commission%, true) ← Fires change event
   │       setValIfChanged(153, email, false)
   │       setValIfChanged(166, user_id, false)
   ↓
5. Fields 152, 161 fire 'change' events
   ↓
6. Gravity Forms detects dependency changes
   ↓
7. GF recalculates field 165 formula:
   ({Base after EB:174} / 100) * {Partner commission %:161}
   ↓
8. GF recalculates field 176 formula:
   {Base after EB:174} * ( {Discount %:152} / 100 )
   ↓
9. gform_calculation_result filter fires for field 176
   └─→ roundDown2() applies floor rounding
   ↓
10. If changed: gformCalculateTotalPrice(formId) called
    ↓
11. GF conditional logic re-evaluates
    ├─→ Field 180: Show if field 154 ≠ '' AND field 176 > 0
    │   (Now both conditions met)
    └─→ Field 180 becomes visible with discount product
    ↓
12. Form total updates with partner discount applied
```

### Form Submission (Server-Side)

```
1. User clicks "Add to Cart"
   ↓
2. GF_Validation::validate_event_extras() called
   ├─→ GF_Partner::prepare_post() called again
   │   (Ensures fresh partner context)
   ├─→ PartnerResolver::resolve_partner_context()
   ├─→ Check field 181 value
   │   If '0': Force partner_discount_pct = 0
   │   If '1': Use resolved partner discount
   └─→ Validate calculated totals match expected values
   ↓
3. GF_Discount_Rounding: Override product prices
   ├─→ Check field 181: if '0', return '0' for partner discount
   └─→ Apply floor rounding to match frontend calculation
   ↓
4. Plugin::gf_after_submission_add_to_cart() (lines 377-698)
   ├─→ Build WooCommerce cart items from GF entry
   ├─→ Add product: Event ticket
   ├─→ Add product: Early booking discount (if applicable)
   ├─→ Add product: Partner discount (if field 180 visible)
   └─→ Store partner metadata in cart item
   ↓
5. WooCommerce cart updated
   ↓
6. Plugin::woo_validate_partner_coupon() (lines 270-292)
   ├─→ Check if cart has partner coupon applied
   ├─→ Get event_id from cart items
   ├─→ Check EventMeta::event_partners_enabled($event_id)
   └─→ If '0': Remove coupon, show error message
   ↓
7. Plugin::woo_maybe_remove_partner_coupons() (lines 298-314)
   └─→ Additional validation before checkout
   ↓
8. Cart validates successfully
   ↓
9. User proceeds to checkout
```

---

## Partner Resolution Hierarchy

### Priority Order (Highest to Lowest)

```php
// File: includes/Domain/PartnerResolver.php
// Function: resolve_partner_context()

1. ADMIN OVERRIDE (Field 63)
   - Check: $_POST['input_63'] has value AND current_user_can('administrator')
   - Source: Admin manually selects from dropdown
   - Use Case: Admin booking on behalf of customer with specific partner

2. LOGGED-IN PARTNER USER META
   - Check: is_user_logged_in() AND get_user_meta($user_id, 'discount__code')
   - Source: User logged in with role 'hotel_partner'
   - Use Case: Partner logs in and creates booking (automatic)

3. WOOCOMMERCE CART APPLIED COUPONS
   - Check: WC()->cart->get_applied_coupons() OR WC()->session->get('applied_coupons')
   - Source: Partner URL with ?coupon=CODE parameter
   - Use Case: Partner shares URL, coupon auto-applies before form loads

4. POSTED MANUAL COUPON (Field 154)
   - Check: $_POST['input_154'] has value
   - Source: Field 154 was previously populated (fallback)
   - Use Case: Edge case - field already has value from previous submission
```

### Context Structure

```php
// Return value from resolve_partner_context()
[
    'active'           => true,              // Is partner active?
    'code'             => 'PARTNER123',      // Coupon code
    'discount_pct'     => 7.5,               // Discount percentage
    'commission_pct'   => 20.0,              // Commission percentage
    'partner_email'    => 'partner@mail.com',// Partner email
    'partner_user_id'  => 42,                // WP user ID
]

// No partner active:
[
    'active' => false
]
```

---

## JavaScript Helper Functions

### setValIfChanged(fieldId, val, fire)
**Purpose**: Idempotent field value setter with change event control

**Location**: `includes/Integrations/GravityForms/GF_JS.php:148-166`

**Behavior:**
```javascript
function setValIfChanged(fieldId, val, fire){
    var el = qs('#input_'+fid+'_'+fieldId);
    if(!el) return false;

    var next = (val===null||typeof val==='undefined') ? '' : String(val);

    // Numeric equivalence guard (prevents comma/dot flip-flop loops)
    if(fieldId === 152 || fieldId === 161 || fieldId === 176){
        var a = parseLocaleFloat(el.value);
        var b = parseLocaleFloat(next);
        if(a === b) return false;  // No change needed
    } else {
        if(el.value === next) return false;  // No change needed
    }

    el.value = next;

    if(fire){
        try{ el.dispatchEvent(new Event('change', {bubbles:true})); }catch(e){}
    }

    return true;  // Value was changed
}
```

**Parameters:**
- `fieldId`: GF field ID (e.g., 152, 154, 161)
- `val`: New value to set
- `fire`: Boolean - whether to fire change event

**Returns**: `true` if value changed, `false` if unchanged

**Critical Logic:**
- Fields 152, 161, 176 use numeric equivalence check
- Prevents infinite loops from locale formatting differences (7.5 vs 7,5)
- Only fires change event if explicitly requested AND value actually changed

---

### isPartnerProgramEnabled()
**Purpose**: Check if partners are enabled for current event

**Location**: `includes/Integrations/GravityForms/GF_JS.php:179-187`

**Behavior:**
```javascript
function isPartnerProgramEnabled(){
    try{
        var field181 = qs('#input_'+fid+'_181');
        if(!field181) return true;  // fail-open (safety)
        var val = (field181.value||'').toString().trim();
        return val !== '0';
    }catch(e){ return true; }
}
```

**Returns**:
- `true` if field 181 = '1' (enabled)
- `true` if field 181 not found (fail-open)
- `false` if field 181 = '0' (disabled)

**Safety**: Fails open to prevent breaking bookings if field missing

---

### applyPartner()
**Purpose**: Main partner field population logic

**Location**: `includes/Integrations/GravityForms/GF_JS.php:194-248`

**Call Triggers:**
- On form render (`gform_post_render` event)
- After conditional logic (`gform_post_conditional_logic` event)
- When field 63 changes (admin override)
- Debounced via `requestApplyPartner()` (20ms delay)

**Logic Flow:**
```javascript
function applyPartner(){
    if(applyPartnerInProgress) return;  // Prevent recursion
    applyPartnerInProgress = true;

    try{
        var changed = false;

        // GATE: Check if partners enabled
        if(!isPartnerProgramEnabled()){
            // Clear all partner fields
            changed = setValIfChanged(154,'',false) || changed;
            changed = setValIfChanged(152,'',true) || changed;  // Fire change
            changed = setValIfChanged(161,'',true) || changed;  // Fire change
            changed = setValIfChanged(153,'',false) || changed;
            changed = setValIfChanged(166,'',false) || changed;
            changed = setValIfChanged(176,'0',false) || changed;
            toggleSummary(null, '');
            return;
        }

        // Get partner map from global scope
        var map = window.tcBfPartnerMap[fid] || {};

        // Resolve partner code
        var code = '';
        var sel = qs('#input_'+fid+'_63');  // Admin override field

        if(sel && field63 is visible){
            code = sel.value.trim();
        } else {
            // Fallback to field 154 or initialCode
            var codeEl = qs('#input_'+fid+'_154');
            code = codeEl ? codeEl.value.trim() : initialCode;
        }

        // Lookup partner data
        var data = (code && map[code]) ? map[code] : null;

        if(!data){
            // No partner data: Clear fields
            changed = setValIfChanged(154,'',false) || changed;
            changed = setValIfChanged(152,'',true) || changed;  // Fire change
            changed = setValIfChanged(161,'',true) || changed;  // Fire change
            changed = setValIfChanged(153,'',false) || changed;
            changed = setValIfChanged(166,'',false) || changed;
        } else {
            // Populate partner fields
            changed = setValIfChanged(154, code, false) || changed;
            changed = setValIfChanged(152, fmtPct(data.discount), true) || changed;  // Fire change
            changed = setValIfChanged(161, fmtPct(data.commission), true) || changed; // Fire change
            changed = setValIfChanged(153, data.email, false) || changed;
            changed = setValIfChanged(166, data.id, false) || changed;
        }

        toggleSummary(data, code);

        // If anything changed, recalculate total
        if(changed && typeof window.gformCalculateTotalPrice === 'function'){
            try{ window.gformCalculateTotalPrice(fid); }catch(e){}
        }

    } finally {
        applyPartnerInProgress = false;
    }
}
```

**Key Points:**
- Always checks `isPartnerProgramEnabled()` first
- Fields 152, 161 always fire change events (`fire: true`)
- Field 154 never fires change events (`fire: false`)
- Calls `gformCalculateTotalPrice()` only if values changed
- Protected by `applyPartnerInProgress` flag (prevents infinite loops)

---

## Conditional Logic Rules (Gravity Forms Admin)

### Field 63: Admin Override Dropdown
```
Action: Show
Logic: ALL of the following match
Rules:
  - Field 6 (user_role) contains "administrator"
  - Field 181 (partners_enabled) is not equal to "0"
```

### Field 165: Partner Commission Amount
```
Action: Show
Logic: ALL of the following match
Rules:
  - Field 181 (partners_enabled) is not equal to "0"
```

### Field 176: Partner Discount Amount
```
Action: Show
Logic: ALL of the following match
Rules:
  - Field 181 (partners_enabled) is not equal to "0"
```

### Field 180: Partner Discount Product
```
Action: Show
Logic: ALL of the following match
Rules:
  - Field 154 (discount_code) is not equal to ""
  - Field 176 (discount_amount) is greater than "0"
```

**Note**: Field 180 uses different logic (checks field 154) to avoid showing "0,00 €" discount when no partner is active.

---

## Testing Scenarios

### Scenario 1: Event with Partners Disabled
**Setup:**
- Event meta: `tcbf_partners_enabled = '0'`
- User: Any role

**Expected Behavior:**
1. Field 181 = '0'
2. Fields 63, 165, 176 hidden (conditional logic)
3. Field 180 hidden (field 154 empty)
4. If user tries to apply partner coupon in cart: Blocked with error message

**Validation Points:**
- `class-tc-bf-sc-event-extras.php:210` - Field 181 returns '0'
- `GF_Partner.php:30` - Returns early, clears partner fields
- `Plugin.php:270-292` - Removes partner coupon from cart

---

### Scenario 2: Event with Partners Enabled, No Active Partner
**Setup:**
- Event meta: `tcbf_partners_enabled = '1'`
- User: Not logged in, no coupon in cart, not admin

**Expected Behavior:**
1. Field 181 = '1'
2. Fields 165, 176 visible but show "0,00 €"
3. Field 63 hidden (not admin)
4. Field 180 hidden (field 154 empty)

**Why Fields Show 0,00:**
- Field 181 = '1' makes fields 165, 176 visible
- But no partner context exists, so fields 152, 161 are empty
- Calculations: empty × price = 0,00

**Recommendation**: Update conditional logic to check BOTH field 181 AND field 154 to avoid confusing UX.

---

### Scenario 3: Admin Override Partner Selection
**Setup:**
- Event meta: `tcbf_partners_enabled = '1'`
- User: Administrator
- Action: Admin selects partner from field 63

**Expected Behavior:**
1. Field 181 = '1'
2. Field 63 visible (admin + partners enabled)
3. Admin selects "Hotel ABC" from dropdown
4. JavaScript `applyPartner()` fires
5. Fields 152, 154, 161, 153, 166 populated from partner map
6. Fields 152, 161 fire change events
7. Fields 165, 176 recalculate and show amounts
8. Field 180 becomes visible (field 154 has value, field 176 > 0)
9. Form total updates with discount

**Validation Points:**
- `GF_JS.php:211-222` - Field 63 takes priority
- `GF_JS.php:236-240` - Partner data populated
- `GF_JS.php:237-238` - Change events fired
- `GF_JS.php:259-267` - Field 176 rounding filter applied
- `GF_JS.php:243-244` - Total recalculated

---

### Scenario 4: Logged-In Partner User
**Setup:**
- Event meta: `tcbf_partners_enabled = '1'`
- User: Logged in with role `hotel_partner`
- User meta: `discount__code = 'PARTNER123'`

**Expected Behavior:**
1. Field 181 = '1'
2. PHP `GF_Partner::prepare_post()` runs before render
3. `PartnerResolver::resolve_partner_context()` finds logged-in partner
4. Fields 152, 154, 161, 153, 166 pre-populated in `$_POST`
5. Form renders with partner data already filled
6. Fields 165, 176 show calculated amounts
7. Field 180 visible with discount
8. Field 63 hidden (not admin)

**Validation Points:**
- `PartnerResolver.php:32-39` - Logged-in partner detection
- `GF_Partner.php:48-55` - Fields populated in `$_POST`
- Form renders with values already present

---

### Scenario 5: Partner URL with Coupon Parameter
**Setup:**
- Event meta: `tcbf_partners_enabled = '1'`
- User: Not logged in
- URL: `https://site.com/event?coupon=PARTNER123`
- WooCommerce: Coupon auto-applied to cart

**Expected Behavior:**
1. Field 181 = '1'
2. `PartnerResolver::resolve_partner_context()` finds coupon in WC cart
3. PHP populates partner fields before form render
4. Same as Scenario 4 - form renders with partner data

**Validation Points:**
- `PartnerResolver.php:42-46` - Reads from `WC()->cart->get_applied_coupons()`
- `PartnerResolver.php:58-89` - Fallback to session coupons

---

## Common Issues & Troubleshooting

### Issue: Field 181 Shows "partners_enabled" String
**Cause**: Field 181 configuration error
- `inputName` is empty (should be "partners_enabled")
- `defaultValue` has "partners_enabled" (should be empty)

**Fix**:
1. Edit Form 48 → Field 181
2. Check "Allow field to be populated dynamically"
3. Set "Parameter Name" = `partners_enabled`
4. Clear "Default Value" field completely
5. Save form

**Verify**: View page source, field 181 should have `value="0"` or `value="1"`, not `value="partners_enabled"`

---

### Issue: Fields 165, 176 Show 0,00 Despite Hidden Fields Being Populated
**Cause**: Fields 152, 161 not firing change events

**Fix**: Applied in commit `38afc66`
- Changed `setValIfChanged(152, value, false)` to `setValIfChanged(152, value, true)`
- Changed `setValIfChanged(161, value, false)` to `setValIfChanged(161, value, true)`

**Why**: GF calculation fields don't auto-recalculate when dependencies change without events

---

### Issue: Infinite Loop / Page Freeze
**Cause**: (Historical) JavaScript was calling `gformCalculateTotalPrice()` unconditionally + DOM manipulation + MutationObserver

**Fix**: Applied in commit `6867e9a`
- Removed DOM manipulation (`hideField()`, `showField()`)
- Only call `gformCalculateTotalPrice()` if values actually changed
- Removed MutationObserver
- Added `applyPartnerInProgress` guard flag
- Use GF event hooks instead: `gform_post_render`, `gform_post_conditional_logic`

---

### Issue: Partner Coupon Applies in Cart Despite Event Having Partners Disabled
**Cause**: Cart validation not checking event-level toggle

**Fix**: Already implemented
- `Plugin.php:270-292` - `woo_validate_partner_coupon()` checks `event_partners_enabled()`
- If disabled: Remove coupon, show error message
- Runs on `woocommerce_after_calculate_totals` hook

---

### Issue: GF Calculations Don't Match WooCommerce Cart Totals
**Cause**: Rounding differences between GF and WooCommerce

**Fix**: Already implemented
- `GF_Discount_Rounding.php:41-77` - Server-side floor rounding
- `GF_JS.php:250-270` - Client-side floor rounding filter
- Both use: `Math.floor((amount + 1e-9) * 100) / 100`

---

## File Reference Index

### PHP Files

#### includes/Domain/EventMeta.php
```php
/**
 * Event meta access layer
 */
public static function event_partners_enabled( int $event_id ) : bool
```
**Purpose**: Single source of truth for partner toggle status
**Returns**: `true` if partners enabled, `false` otherwise

---

#### includes/Domain/PartnerResolver.php
```php
/**
 * Partner context resolution with priority hierarchy
 */
public static function resolve_partner_context( int $form_id ) : array
```
**Purpose**: Determines active partner for current booking session
**Priority**: Admin override > Logged-in user > Cart coupon > Posted code
**Returns**: Partner context array or `['active' => false]`

---

#### includes/class-tc-bf-sc-event-extras.php
**Lines 207-218**: Field 181 population filter
```php
add_filter('gform_field_value_partners_enabled', function() use ($event_id) {
    $enabled = \TC_BF\Domain\EventMeta::event_partners_enabled($event_id);
    return $enabled ? '1' : '0';
});
```
**Purpose**: Populates field 181 before form renders

---

#### includes/Integrations/GravityForms/GF_Partner.php
**Lines 23-58**: `prepare_post()` - Hidden field population
```php
public static function prepare_post( int $form_id ) : void
```
**Purpose**: Populates `$_POST` with partner data before form render/validation
**Called By**:
- `GF_JS.php:28` (during form render)
- `GF_Validation.php:114` (during validation)

---

#### includes/Integrations/GravityForms/GF_JS.php
**Lines 22-47**: `partner_prepare_form()` - Main entry point
```php
public static function partner_prepare_form( $form )
```
**Called By**: `gform_pre_render` filter
**Purpose**: Prepares partner data and injects JavaScript

**Lines 83-288**: `build_partner_override_js()` - JavaScript generation
- Builds partner map
- Generates `applyPartner()` function
- Injects into form footer

---

#### includes/Integrations/GravityForms/GF_Validation.php
**Lines 111-132**: Partner validation during submission
```php
// Enforce TCBF-12 partner toggle
if ( $partners_enabled === '0' ) {
    $partner_discount_pct = 0.0;
}
```
**Purpose**: Server-side enforcement of partner toggle

---

#### includes/Integrations/GravityForms/GF_Discount_Rounding.php
**Lines 41-77**: Product price override filter
```php
add_filter('gform_product_info_{$form_id}', ...)
```
**Purpose**: Ensures server-side rounding matches client-side calculations

---

#### includes/Plugin.php
**Lines 270-292**: `woo_validate_partner_coupon()`
```php
add_action('woocommerce_after_calculate_totals', [$this, 'woo_validate_partner_coupon'], 99);
```
**Purpose**: Removes partner coupons if event has partners disabled

**Lines 298-314**: `woo_maybe_remove_partner_coupons()`
```php
add_action('woocommerce_checkout_update_order_review', ...)
```
**Purpose**: Additional validation before checkout

**Lines 377-698**: `gf_after_submission_add_to_cart()`
```php
add_action('gform_after_submission_48', ...)
```
**Purpose**: Converts GF entry to WooCommerce cart items

---

## Regenerating This Feature

If you need to recreate this feature from scratch, follow these steps:

### Step 1: Add Event Meta Field
```php
// Add to event custom post type
add_post_meta($event_id, 'tcbf_partners_enabled', '1', true);

// Create helper function
public static function event_partners_enabled( int $event_id ) : bool {
    $value = get_post_meta($event_id, 'tcbf_partners_enabled', true);
    return $value === '1' || $value === 1 || $value === true;
}
```

---

### Step 2: Create Field 181 in Gravity Forms
1. Add hidden field
2. Set "Allow field to be populated dynamically" = Yes
3. Set "Parameter Name" = `partners_enabled`
4. Leave "Default Value" empty
5. Note field ID (e.g., 181)

---

### Step 3: Register PHP Population Filter
```php
add_filter('gform_field_value_partners_enabled', function() use ($event_id) {
    $enabled = EventMeta::event_partners_enabled($event_id);
    return $enabled ? '1' : '0';
});
```
**Critical**: Register during `wp` action, BEFORE `gform_pre_render`

---

### Step 4: Update Conditional Logic
For each partner-related field:
1. Edit field in GF admin
2. Enable "Conditional Logic"
3. Add rule: "Show if Field 181 is not equal to 0"
4. Apply to fields: 63, 165, 176

---

### Step 5: Implement GF_Partner Class
Create class to populate hidden fields:
```php
public static function prepare_post( int $form_id ) : void {
    // Check partners enabled
    if ( ! EventMeta::event_partners_enabled($event_id) ) {
        self::clear_partner_fields();
        return;
    }

    // Resolve partner context
    $ctx = PartnerResolver::resolve_partner_context($form_id);

    if ( empty($ctx['active']) ) {
        self::clear_partner_fields();
        return;
    }

    // Populate $_POST
    $_POST['input_154'] = $ctx['code'];
    $_POST['input_152'] = Money::pct_to_gf_str($ctx['discount_pct']);
    $_POST['input_161'] = Money::pct_to_gf_str($ctx['commission_pct']);
    // ... etc
}
```

---

### Step 6: Build JavaScript Handler
```javascript
function applyPartner(){
    if(!isPartnerProgramEnabled()){
        // Clear fields with change events
        setValIfChanged(152, '', true);
        setValIfChanged(161, '', true);
        return;
    }

    var code = resolvePartnerCode();
    var data = lookupPartnerData(code);

    if(data){
        setValIfChanged(152, data.discount, true);  // Fire change
        setValIfChanged(161, data.commission, true); // Fire change
        setValIfChanged(154, code, false);
    }

    if(changed) gformCalculateTotalPrice(formId);
}
```

---

### Step 7: Add Cart Validation
```php
add_action('woocommerce_after_calculate_totals', function($cart){
    foreach($cart->get_cart() as $item){
        $event_id = $item['event_id'];
        if( ! EventMeta::event_partners_enabled($event_id) ){
            // Remove all partner coupons
            foreach($cart->get_applied_coupons() as $code){
                if( is_partner_coupon($code) ){
                    $cart->remove_coupon($code);
                    wc_add_notice('Partner discounts disabled for this event', 'error');
                }
            }
        }
    }
}, 99);
```

---

## Version History

- **2026-01-14**: Field 181 conditional logic finalized, change event firing implemented
- **2026-01-13**: Simplified JS implementation, removed DOM manipulation and MutationObserver
- **2026-01-12**: Initial implementation of partner toggle feature (TCBF-12)

---

## Related Documentation

- `docs/PARTNER_SYSTEM.md` - Overall partner program architecture (if exists)
- `docs/GRAVITY_FORMS.md` - GF integration overview (if exists)
- WooCommerce coupon system documentation
- Gravity Forms conditional logic documentation

---

## Support & Maintenance

For issues or questions about this feature:
1. Check browser console for JavaScript errors
2. Check WordPress debug log for PHP errors
3. Use logging calls: `\TC_BF\Support\Logger::log('tag', $data, 'info')`
4. Verify field 181 value in page source
5. Verify partner map exists: `console.log(window.tcBfPartnerMap)`

**Key logging points:**
- `class-tc-bf-sc-event-extras.php:212` - Field 181 population
- `GF_Partner.php:32` - Partners disabled for event
- `GF_Partner.php:44` - No partner context found
- `GF_Partner.php:57` - Partner context applied

**Debug checklist:**
- [ ] Field 181 has correct inputName configuration
- [ ] Field 181 returns '0' or '1' (not "partners_enabled")
- [ ] Conditional logic rules are correct
- [ ] JavaScript partner map is populated
- [ ] Change events fire for fields 152, 161
- [ ] No JavaScript errors in console
- [ ] PHP filter registered before form render
