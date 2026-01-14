# Field Mapping Strategy Analysis

## Current State: Problems & Risks

### Problem 1: Hardcoded Field IDs
**Current Code:**
```php
// GF_Partner.php
$_POST['input_154'] = $code;
$_POST['input_152'] = Money::pct_to_gf_str($discount_pct);
$_POST['input_161'] = Money::pct_to_gf_str($commission_pct);

// JavaScript
setValIfChanged(154, code, false)
setValIfChanged(152, discount, true)
setValIfChanged(161, commission, true)
```

**Risks:**
- ❌ Breaks if form is cloned (field IDs change)
- ❌ Breaks if fields are reordered/deleted (IDs shift)
- ❌ Not portable between installations
- ❌ Requires manual configuration per installation
- ❌ Silent failures - no validation
- ❌ Hard to diagnose issues

### Problem 2: Manual Form Setup
**Current Process:**
1. User must manually create 11 fields
2. Set exact field types
3. Configure parameter names (spelling must be perfect)
4. Set up conditional logic (4 fields, complex rules)
5. Configure calculation formulas
6. Set up product field pricing
7. Update admin settings with form ID

**Failure Points:**
- Typo in parameter name → silent failure
- Wrong field type → validation errors
- Missing conditional logic → UX issues
- Wrong formula → calculation errors
- Takes 30+ minutes to set up correctly

### Problem 3: No Validation/Diagnostics
**Current State:**
- No way to check if form is configured correctly
- Errors appear as broken functionality
- User has to debug by comparing to documentation
- No automated testing of configuration

---

## Solution Analysis: 6 Approaches

### Option 1: Auto-Create Form on Plugin Activation

**Implementation:**
```php
register_activation_hook(__FILE__, function(){
    if(!class_exists('GFAPI')) return;

    $form = [
        'title' => 'TC Event Booking',
        'fields' => [
            [
                'id' => 1,
                'type' => 'hidden',
                'label' => 'Partners Enabled',
                'inputName' => 'partners_enabled',
                'allowsPrepopulate' => true,
            ],
            // ... 10 more fields with full config
        ],
        'confirmations' => [...],
        'notifications' => [...],
    ];

    $form_id = GFAPI::add_form($form);
    update_option('tc_bf_form_id', $form_id);
});
```

**Pros:**
- ✅ Zero manual configuration
- ✅ Perfect setup guaranteed
- ✅ All conditional logic pre-configured
- ✅ Works immediately after activation

**Cons:**
- ❌ What if form 48 already exists?
- ❌ What if user customizes form? (gets overwritten on update)
- ❌ GF API complexity (100+ lines per form)
- ❌ Can't handle existing installations
- ❌ No flexibility for custom fields
- ❌ Update/upgrade strategy unclear

**Verdict:** ⚠️ **Not Recommended** - Too rigid, breaks customization

---

### Option 2: Pure inputName Mapping (No Hardcoded IDs)

**Implementation:**
```php
class GF_Partner {
    // Map logical name → parameter name
    const FIELD_MAP = [
        'partners_enabled' => 'partners_enabled',
        'discount_code' => 'discount_code',
        'discount_percent' => 'discount',
        'commission_percent' => 'partner_commission',
        'partner_email' => 'partner_email',
        'partner_id' => 'partnerID',
    ];

    public static function prepare_post($form_id) {
        $form = GFAPI::get_form($form_id);

        // Find fields by parameter name
        $discount_code_id = self::find_field_id($form, 'discount_code');
        $discount_pct_id = self::find_field_id($form, 'discount');
        $commission_pct_id = self::find_field_id($form, 'partner_commission');

        if(!$discount_code_id) {
            Logger::log('Missing discount_code field', [], 'error');
            return;
        }

        $_POST['input_' . $discount_code_id] = $code;
        $_POST['input_' . $discount_pct_id] = $discount_pct;
        // etc.
    }

    private static function find_field_id($form, $input_name) {
        foreach($form['fields'] as $field) {
            if($field->inputName === $input_name) {
                return $field->id;
            }
        }
        return null;
    }
}
```

**Pros:**
- ✅ No hardcoded IDs
- ✅ Works with any field ID
- ✅ Form can be cloned/duplicated
- ✅ Portable between installations
- ✅ Self-documenting via parameter names

**Cons:**
- ❌ GFAPI::get_form() called on every request (performance)
- ❌ Still requires manual form creation
- ❌ No validation - silent failure if field missing
- ❌ Need fallback strategy
- ❌ JavaScript can't use same approach (no access to $form object)

**Verdict:** ⚠️ **Good but incomplete** - Needs caching and validation

---

### Option 3: Field Registry with Caching

**Implementation:**
```php
class FieldRegistry {
    // Logical field identifiers
    const PARTNERS_ENABLED = 'partners_enabled';
    const DISCOUNT_CODE = 'discount_code';
    const DISCOUNT_PERCENT = 'discount_percent';
    const COMMISSION_PERCENT = 'commission_percent';
    const PARTNER_EMAIL = 'partner_email';
    const PARTNER_ID = 'partner_id';
    const PARTNER_ADMIN_OVERRIDE = 'admin_partner_override';

    // Field definitions (metadata)
    private static $field_definitions = [
        self::PARTNERS_ENABLED => [
            'type' => 'hidden',
            'label' => 'Partners Enabled',
            'parameter_name' => 'partners_enabled',
            'required' => true,
            'default_id' => 181,  // Fallback for existing installs
            'description' => 'Controls partner program visibility (0/1)',
        ],
        self::DISCOUNT_CODE => [
            'type' => 'hidden',
            'label' => 'Discount Code',
            'parameter_name' => 'discount_code',
            'required' => true,
            'default_id' => 154,
            'description' => 'Partner coupon code',
        ],
        self::DISCOUNT_PERCENT => [
            'type' => 'hidden',
            'label' => 'Partner Discount %',
            'parameter_name' => 'discount',
            'required' => true,
            'default_id' => 152,
            'description' => 'Discount percentage (decimal comma format)',
        ],
        // ... etc for all 11 fields
    ];

    // Runtime cache: [form_id][field_key] => field_id
    private static $cache = [];

    /**
     * Get field ID by logical identifier
     *
     * @param int $form_id GF form ID
     * @param string $field_key Field constant (e.g., self::DISCOUNT_CODE)
     * @return int|null Field ID or null if not found
     */
    public static function get_field_id($form_id, $field_key) {
        // Check cache first
        if(isset(self::$cache[$form_id][$field_key])) {
            return self::$cache[$form_id][$field_key];
        }

        $definition = self::$field_definitions[$field_key] ?? null;
        if(!$definition) {
            Logger::log('Unknown field key', ['key' => $field_key], 'error');
            return null;
        }

        // Try to find by parameter name
        $form = GFAPI::get_form($form_id);
        foreach($form['fields'] as $field) {
            if($field->inputName === $definition['parameter_name']) {
                self::$cache[$form_id][$field_key] = $field->id;
                return $field->id;
            }
        }

        // Fallback to default ID (for existing installations)
        if(isset($definition['default_id'])) {
            $field = GFAPI::get_field($form, $definition['default_id']);
            if($field) {
                Logger::log('Using fallback field ID', [
                    'field_key' => $field_key,
                    'field_id' => $definition['default_id'],
                ], 'warning');
                self::$cache[$form_id][$field_key] = $definition['default_id'];
                return $definition['default_id'];
            }
        }

        // Field not found
        Logger::log('Required field not found', [
            'field_key' => $field_key,
            'parameter_name' => $definition['parameter_name'],
        ], 'error');

        return null;
    }

    /**
     * Validate form configuration
     * Returns array of issues or empty array if valid
     */
    public static function validate_form($form_id) {
        $issues = [];

        foreach(self::$field_definitions as $key => $def) {
            $field_id = self::get_field_id($form_id, $key);

            if(!$field_id && $def['required']) {
                $issues[] = [
                    'severity' => 'error',
                    'field' => $key,
                    'message' => "Required field '{$def['label']}' not found. Add a {$def['type']} field with parameter name '{$def['parameter_name']}'",
                ];
                continue;
            }

            // Validate field type
            $form = GFAPI::get_form($form_id);
            $field = GFAPI::get_field($form, $field_id);

            if($field && $field->type !== $def['type']) {
                $issues[] = [
                    'severity' => 'warning',
                    'field' => $key,
                    'message' => "Field '{$def['label']}' should be type '{$def['type']}' but is '{$field->type}'",
                ];
            }
        }

        return $issues;
    }

    /**
     * Get field definition by key
     */
    public static function get_definition($field_key) {
        return self::$field_definitions[$field_key] ?? null;
    }

    /**
     * Get all field definitions
     */
    public static function get_all_definitions() {
        return self::$field_definitions;
    }
}
```

**Usage in GF_Partner:**
```php
public static function prepare_post($form_id) {
    $event_id = self::resolve_event_id_from_request($form_id);

    if(!EventMeta::event_partners_enabled($event_id)) {
        self::clear_partner_fields($form_id);
        return;
    }

    $ctx = PartnerResolver::resolve_partner_context($form_id);

    if(empty($ctx['active'])) {
        self::clear_partner_fields($form_id);
        return;
    }

    // Use registry to get field IDs
    $discount_code_id = FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_CODE);
    $discount_pct_id = FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_PERCENT);
    $commission_pct_id = FieldRegistry::get_field_id($form_id, FieldRegistry::COMMISSION_PERCENT);
    $email_id = FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_EMAIL);
    $partner_id_id = FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_ID);

    if(!$discount_code_id) {
        Logger::log('Cannot populate partner fields - discount_code field not found', [], 'error');
        return;
    }

    $_POST['input_' . $discount_code_id] = (string) ($ctx['code'] ?? '');
    $_POST['input_' . $discount_pct_id] = Money::pct_to_gf_str($ctx['discount_pct']);
    $_POST['input_' . $commission_pct_id] = Money::pct_to_gf_str($ctx['commission_pct']);
    $_POST['input_' . $email_id] = (string) ($ctx['partner_email'] ?? '');
    $_POST['input_' . $partner_id_id] = (string) ($ctx['partner_user_id'] ?? 0);
}
```

**JavaScript Integration:**
```php
// Pass field map to JavaScript
$field_map = [
    'partners_enabled' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNERS_ENABLED),
    'discount_code' => FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_CODE),
    'discount_percent' => FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_PERCENT),
    'commission_percent' => FieldRegistry::get_field_id($form_id, FieldRegistry::COMMISSION_PERCENT),
    'partner_email' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_EMAIL),
    'partner_id' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_ID),
];

echo "<script>window.tcBfFieldMap = " . json_encode($field_map) . ";</script>";
```

**JavaScript Usage:**
```javascript
var fieldMap = window.tcBfFieldMap || {};

function applyPartner(){
    var partnersEnabledId = fieldMap.partners_enabled || 181;
    var discountCodeId = fieldMap.discount_code || 154;
    var discountPercentId = fieldMap.discount_percent || 152;
    var commissionPercentId = fieldMap.commission_percent || 161;

    setValIfChanged(discountCodeId, code, false);
    setValIfChanged(discountPercentId, discount, true);
    setValIfChanged(commissionPercentId, commission, true);
}
```

**Pros:**
- ✅ Single source of truth
- ✅ No hardcoded IDs in business logic
- ✅ Cached lookups (performance)
- ✅ Validation built-in
- ✅ Backwards compatible (fallback IDs)
- ✅ Self-documenting
- ✅ Can generate diagnostics
- ✅ Works with JavaScript
- ✅ Easy to extend

**Cons:**
- ⚠️ Still requires manual form creation (but easier to validate)
- ⚠️ More abstraction (but cleaner)

**Verdict:** ✅ **HIGHLY RECOMMENDED** - Best balance of flexibility and robustness

---

### Option 4: Form Template + Import System

**Implementation:**
```php
class FormTemplateManager {
    /**
     * Get form template JSON
     */
    public static function get_template() {
        return file_get_contents(__DIR__ . '/templates/event-booking-form.json');
    }

    /**
     * Import form from template
     *
     * @param bool $force_new Create new form even if one exists
     * @return int|WP_Error Form ID or error
     */
    public static function import_template($force_new = false) {
        if(!class_exists('GFAPI')) {
            return new WP_Error('gf_not_active', 'Gravity Forms is not active');
        }

        $existing_form_id = Settings::get_form_id();

        if($existing_form_id && !$force_new) {
            $form = GFAPI::get_form($existing_form_id);
            if($form) {
                return new WP_Error('form_exists', "Form #{$existing_form_id} already exists. Use force_new to create a new form.");
            }
        }

        $template_json = self::get_template();
        $form_data = json_decode($template_json, true);

        if(!$form_data) {
            return new WP_Error('invalid_template', 'Form template is invalid');
        }

        // Import form
        $result = GFAPI::add_form($form_data['0']);

        if(is_wp_error($result)) {
            return $result;
        }

        // Update settings
        Settings::set_form_id($result);

        Logger::log('Form imported from template', ['form_id' => $result], 'info');

        return $result;
    }

    /**
     * Export current form as template
     */
    public static function export_current_form() {
        $form_id = Settings::get_form_id();
        if(!$form_id) {
            return new WP_Error('no_form', 'No form configured');
        }

        $form = GFAPI::get_form($form_id);
        if(!$form) {
            return new WP_Error('form_not_found', "Form #{$form_id} not found");
        }

        $export_data = ['0' => $form, 'version' => GFForms::$version];

        return json_encode($export_data, JSON_PRETTY_PRINT);
    }
}
```

**Admin Page:**
```php
<div class="wrap">
    <h1>TC Booking Flow - Form Setup</h1>

    <?php $form_id = Settings::get_form_id(); ?>

    <div class="card">
        <h2>Current Form</h2>
        <?php if($form_id): ?>
            <p>Form ID: <strong><?php echo $form_id; ?></strong></p>
            <p><a href="<?php echo admin_url('admin.php?page=gf_edit_forms&id=' . $form_id); ?>">Edit Form</a></p>
        <?php else: ?>
            <p>No form configured</p>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2>Quick Start</h2>
        <p>Import pre-configured form template with all partner fields and conditional logic.</p>
        <form method="post">
            <?php wp_nonce_field('tc_bf_import_form'); ?>
            <button type="submit" name="action" value="import" class="button button-primary">
                Import Form Template
            </button>
        </form>
    </div>

    <div class="card">
        <h2>Validation</h2>
        <?php
        $issues = FieldRegistry::validate_form($form_id);
        if(empty($issues)): ?>
            <p style="color: green;">✓ Form configuration is valid</p>
        <?php else: ?>
            <p style="color: red;">✗ Configuration issues found:</p>
            <ul>
                <?php foreach($issues as $issue): ?>
                    <li>
                        <strong>[<?php echo strtoupper($issue['severity']); ?>]</strong>
                        <?php echo esc_html($issue['message']); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
```

**Pros:**
- ✅ One-click form creation
- ✅ Perfect configuration guaranteed
- ✅ Includes layout, styling, conditional logic
- ✅ Can be updated/versioned
- ✅ User can still customize after import

**Cons:**
- ⚠️ Need to maintain template JSON
- ⚠️ Still need FieldRegistry for runtime lookups

**Verdict:** ✅ **RECOMMENDED** - Great for new installations, combine with Option 3

---

### Option 5: Admin Diagnostics Page

**Implementation:**
```php
class DiagnosticsPage {
    public static function render() {
        $form_id = Settings::get_form_id();
        $issues = FieldRegistry::validate_form($form_id);

        echo '<div class="wrap">';
        echo '<h1>TC Booking Flow - Diagnostics</h1>';

        // Overall status
        if(empty($issues)) {
            echo '<div class="notice notice-success"><p>✓ All checks passed</p></div>';
        } else {
            $errors = array_filter($issues, fn($i) => $i['severity'] === 'error');
            $warnings = array_filter($issues, fn($i) => $i['severity'] === 'warning');

            if(!empty($errors)) {
                echo '<div class="notice notice-error"><p>✗ ' . count($errors) . ' critical issues found</p></div>';
            }
            if(!empty($warnings)) {
                echo '<div class="notice notice-warning"><p>⚠ ' . count($warnings) . ' warnings found</p></div>';
            }
        }

        // Field-by-field status
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>Field</th>';
        echo '<th>Status</th>';
        echo '<th>Field ID</th>';
        echo '<th>Type</th>';
        echo '<th>Parameter Name</th>';
        echo '<th>Details</th>';
        echo '</tr></thead><tbody>';

        foreach(FieldRegistry::get_all_definitions() as $key => $def) {
            $field_id = FieldRegistry::get_field_id($form_id, $key);
            $status = $field_id ? '✓' : '✗';
            $status_class = $field_id ? 'success' : 'error';

            $field = null;
            if($field_id) {
                $form = GFAPI::get_form($form_id);
                $field = GFAPI::get_field($form, $field_id);
            }

            echo '<tr>';
            echo '<td><strong>' . esc_html($def['label']) . '</strong></td>';
            echo '<td><span style="color:' . ($field_id ? 'green' : 'red') . '">' . $status . '</span></td>';
            echo '<td>' . ($field_id ?: 'N/A') . '</td>';
            echo '<td>' . ($field ? $field->type : 'N/A') . '</td>';
            echo '<td><code>' . esc_html($def['parameter_name']) . '</code></td>';
            echo '<td>';

            if(!$field_id) {
                echo '<span style="color:red">Field not found. Add a ' . $def['type'] . ' field with parameter name "' . $def['parameter_name'] . '"</span>';
            } elseif($field && $field->type !== $def['type']) {
                echo '<span style="color:orange">Wrong type (expected: ' . $def['type'] . ', found: ' . $field->type . ')</span>';
            } else {
                echo '<span style="color:green">OK</span>';
            }

            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        // Test button
        echo '<h2>Test Configuration</h2>';
        echo '<form method="post">';
        wp_nonce_field('tc_bf_test_config');
        echo '<button type="submit" name="action" value="test" class="button">Run Test</button>';
        echo '</form>';

        echo '</div>';
    }
}
```

**Pros:**
- ✅ Clear visibility into configuration
- ✅ Actionable error messages
- ✅ Helps users fix issues
- ✅ Can include "fix" buttons
- ✅ Good for support/debugging

**Cons:**
- ⚠️ Still requires manual fixes

**Verdict:** ✅ **ESSENTIAL** - Must-have for any solution

---

### Option 6: Hybrid Approach (RECOMMENDED)

**Combine the best of all options:**

#### Phase 1: Field Registry (Immediate)
1. Create `FieldRegistry` class with all field definitions
2. Replace all hardcoded IDs with registry lookups
3. Add runtime caching
4. Keep fallback IDs for backwards compatibility

#### Phase 2: Diagnostics (Immediate)
1. Create admin diagnostics page
2. Show field-by-field validation
3. Provide fix suggestions
4. Add "Test Configuration" button

#### Phase 3: Form Template (Next)
1. Export current working form as template
2. Create import functionality
3. Add "Quick Start" button in admin
4. Handle updates gracefully

#### Phase 4: Auto-Repair (Future)
1. "Fix Issues" button that attempts to repair common problems
2. Add missing parameter names to existing fields
3. Fix conditional logic rules
4. Suggest field creation if missing

---

## Recommendation: Implementation Plan

### Immediate Action (Backward Compatible)

**Step 1: Create Field Registry**

File: `includes/Integrations/GravityForms/FieldRegistry.php`

```php
<?php
namespace TC_BF\Integrations\GravityForms;

final class FieldRegistry {
    // Field identifiers
    const PARTNERS_ENABLED = 'partners_enabled';
    const DISCOUNT_CODE = 'discount_code';
    const DISCOUNT_PERCENT = 'discount_percent';
    const COMMISSION_PERCENT = 'commission_percent';
    const PARTNER_EMAIL = 'partner_email';
    const PARTNER_ID = 'partner_id';
    const PARTNER_EMAIL_FIELD = 'partner_email_field';
    const ADMIN_OVERRIDE = 'admin_partner_override';
    const ADMIN_OVERRIDE_ID = 'admin_partner_override_id';
    const COMMISSION_AMOUNT = 'commission_amount';
    const DISCOUNT_AMOUNT = 'discount_amount';
    const DISCOUNT_PRODUCT = 'partner_discount_product';

    private static $definitions = [
        self::PARTNERS_ENABLED => [
            'type' => 'hidden',
            'label' => 'Partners Enabled',
            'parameter_name' => 'partners_enabled',
            'required' => true,
            'default_id' => 181,
            'description' => 'Master toggle: 0=disabled, 1=enabled',
            'populated_by' => 'PHP filter',
        ],
        self::DISCOUNT_CODE => [
            'type' => 'hidden',
            'label' => 'Discount Code',
            'parameter_name' => 'discount_code',
            'required' => true,
            'default_id' => 154,
            'description' => 'Partner coupon code',
            'populated_by' => 'PHP + JavaScript',
        ],
        self::DISCOUNT_PERCENT => [
            'type' => 'hidden',
            'label' => 'Partner Discount %',
            'parameter_name' => 'discount',
            'required' => true,
            'default_id' => 152,
            'description' => 'Discount percentage (decimal comma)',
            'populated_by' => 'PHP + JavaScript',
            'fires_change_events' => true,
        ],
        self::COMMISSION_PERCENT => [
            'type' => 'hidden',
            'label' => 'Partner Commission %',
            'parameter_name' => 'partner_commission',
            'required' => true,
            'default_id' => 161,
            'description' => 'Commission percentage (decimal comma)',
            'populated_by' => 'PHP + JavaScript',
            'fires_change_events' => true,
        ],
        self::PARTNER_EMAIL => [
            'type' => 'hidden',
            'label' => 'Partner Email',
            'parameter_name' => 'partner_email',
            'required' => false,
            'default_id' => 153,
            'description' => 'Partner email address',
            'populated_by' => 'PHP + JavaScript',
        ],
        self::PARTNER_ID => [
            'type' => 'hidden',
            'label' => 'Partner ID',
            'parameter_name' => 'partnerID',
            'required' => false,
            'default_id' => 166,
            'description' => 'WP user ID of partner',
            'populated_by' => 'PHP + JavaScript',
        ],
        self::ADMIN_OVERRIDE => [
            'type' => 'select',
            'label' => 'Inscription made for',
            'parameter_name' => 'inscription_for',
            'required' => false,
            'default_id' => 63,
            'description' => 'Admin override partner selection',
            'conditional_logic' => [
                'Field 6 contains "administrator"',
                'Field 181 is not "0"',
            ],
        ],
        self::ADMIN_OVERRIDE_ID => [
            'type' => 'number',
            'label' => 'Inscription made for - ID',
            'parameter_name' => null,
            'required' => false,
            'default_id' => 67,
            'description' => 'Calculated copy of field 63',
            'calculation' => '{inscription_for:63}',
        ],
        self::COMMISSION_AMOUNT => [
            'type' => 'number',
            'label' => 'Partner Commission Amount',
            'parameter_name' => null,
            'required' => true,
            'default_id' => 165,
            'description' => 'Calculated commission amount',
            'calculation' => '({Base after EB:174} / 100) * {Partner commission %:161}',
            'conditional_logic' => [
                'Field 181 is not "0"',
            ],
        ],
        self::DISCOUNT_AMOUNT => [
            'type' => 'number',
            'label' => 'Partner Discount Amount',
            'parameter_name' => null,
            'required' => true,
            'default_id' => 176,
            'description' => 'Calculated discount amount',
            'calculation' => '{Base after EB:174} * ({Discount %:152} / 100)',
            'conditional_logic' => [
                'Field 181 is not "0"',
            ],
        ],
        self::DISCOUNT_PRODUCT => [
            'type' => 'product',
            'label' => 'Partner Discount',
            'parameter_name' => null,
            'required' => true,
            'default_id' => 180,
            'description' => 'Discount product (negative line item)',
            'conditional_logic' => [
                'Field 154 is not ""',
                'Field 176 > 0',
            ],
        ],
    ];

    private static $cache = [];

    public static function get_field_id(int $form_id, string $field_key): ?int {
        // Check cache
        if(isset(self::$cache[$form_id][$field_key])) {
            return self::$cache[$form_id][$field_key];
        }

        $def = self::$definitions[$field_key] ?? null;
        if(!$def) return null;

        // Try by parameter name
        if($def['parameter_name']) {
            $form = \GFAPI::get_form($form_id);
            foreach($form['fields'] as $field) {
                if(($field->inputName ?? '') === $def['parameter_name']) {
                    self::$cache[$form_id][$field_key] = $field->id;
                    return $field->id;
                }
            }
        }

        // Fallback to default ID
        if(isset($def['default_id'])) {
            self::$cache[$form_id][$field_key] = $def['default_id'];
            return $def['default_id'];
        }

        return null;
    }

    public static function get_definition(string $field_key): ?array {
        return self::$definitions[$field_key] ?? null;
    }

    public static function get_all_definitions(): array {
        return self::$definitions;
    }

    public static function validate_form(int $form_id): array {
        $issues = [];
        $form = \GFAPI::get_form($form_id);

        if(!$form) {
            return [['severity' => 'error', 'message' => "Form #{$form_id} not found"]];
        }

        foreach(self::$definitions as $key => $def) {
            $field_id = self::get_field_id($form_id, $key);

            if(!$field_id && $def['required']) {
                $issues[] = [
                    'severity' => 'error',
                    'field' => $key,
                    'field_id' => null,
                    'message' => "Required field '{$def['label']}' not found",
                    'fix' => "Add a {$def['type']} field" .
                             ($def['parameter_name'] ? " with parameter name '{$def['parameter_name']}'" : ''),
                ];
                continue;
            }

            if($field_id) {
                $field = \GFAPI::get_field($form, $field_id);

                if($field->type !== $def['type']) {
                    $issues[] = [
                        'severity' => 'warning',
                        'field' => $key,
                        'field_id' => $field_id,
                        'message' => "Field '{$def['label']}' has wrong type",
                        'detail' => "Expected: {$def['type']}, Found: {$field->type}",
                    ];
                }

                if($def['parameter_name'] && $field->inputName !== $def['parameter_name']) {
                    $issues[] = [
                        'severity' => 'warning',
                        'field' => $key,
                        'field_id' => $field_id,
                        'message' => "Field '{$def['label']}' has wrong parameter name",
                        'detail' => "Expected: {$def['parameter_name']}, Found: {$field->inputName}",
                        'fix' => "Set parameter name to '{$def['parameter_name']}'",
                    ];
                }
            }
        }

        return $issues;
    }

    public static function clear_cache(): void {
        self::$cache = [];
    }
}
```

**Step 2: Update GF_Partner to use Registry**

```php
// OLD:
$_POST['input_154'] = $code;
$_POST['input_152'] = $discount_pct;

// NEW:
$discount_code_id = FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_CODE);
$discount_pct_id = FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_PERCENT);

$_POST['input_' . $discount_code_id] = $code;
$_POST['input_' . $discount_pct_id] = $discount_pct;
```

**Step 3: Update JavaScript to use Dynamic Field Map**

```php
// In GF_JS.php, pass field map to JavaScript
$field_map = [
    'partners_enabled' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNERS_ENABLED),
    'discount_code' => FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_CODE),
    'discount_percent' => FieldRegistry::get_field_id($form_id, FieldRegistry::DISCOUNT_PERCENT),
    'commission_percent' => FieldRegistry::get_field_id($form_id, FieldRegistry::COMMISSION_PERCENT),
    'partner_email' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_EMAIL),
    'partner_id' => FieldRegistry::get_field_id($form_id, FieldRegistry::PARTNER_ID),
];

$js .= "var fieldMap = " . json_encode($field_map) . ";\n";
$js .= "var fid = {$form_id};\n";
$js .= "function getFieldId(key){ return fieldMap[key] || null; }\n";

// Then in applyPartner():
$js .= "var discountCodeId = getFieldId('discount_code');\n";
$js .= "var discountPctId = getFieldId('discount_percent');\n";
$js .= "setValIfChanged(discountCodeId, code, false);\n";
$js .= "setValIfChanged(discountPctId, discount, true);\n";
```

**Step 4: Create Diagnostics Admin Page**

File: `includes/Admin/DiagnosticsPage.php`

(See implementation in Option 5 above)

### Benefits of This Approach:

✅ **Backwards Compatible**: Existing installations keep working via fallback IDs
✅ **Future-Proof**: New installations use parameter name mapping
✅ **Portable**: Forms can be cloned, fields can be reordered
✅ **Validated**: Admin can see exactly what's wrong
✅ **Maintainable**: Single source of truth, easy to update
✅ **Clear Errors**: Users get actionable error messages
✅ **Performance**: Cached lookups, minimal overhead
✅ **Flexible**: Can add form templates later

---

## Migration Path

### For Existing Installations:
1. Deploy FieldRegistry update
2. Fallback IDs keep everything working
3. Admin can validate configuration
4. Admin can fix any issues found
5. System automatically switches to parameter name mapping once validated

### For New Installations:
1. Install plugin
2. Admin clicks "Import Form Template"
3. Form created with perfect configuration
4. Zero manual setup required

---

## Cost/Benefit Analysis

| Solution | Dev Time | Maintenance | Flexibility | User Experience | Recommendation |
|----------|----------|-------------|-------------|-----------------|----------------|
| Option 1: Auto-Create | 8h | High | Low | Excellent (first install only) | ❌ No |
| Option 2: inputName Only | 4h | Medium | High | Poor (silent failures) | ⚠️ Incomplete |
| Option 3: Field Registry | 6h | Low | High | Good (with validation) | ✅ **YES** |
| Option 4: Form Template | 4h | Low | Medium | Excellent | ✅ **YES** |
| Option 5: Diagnostics | 4h | Low | N/A | Excellent | ✅ **YES** |
| Option 6: Hybrid | 12h | Very Low | Very High | Excellent | ✅ **BEST** |

---

## Final Recommendation

**Implement Option 6 (Hybrid Approach) in phases:**

### Phase 1 (Immediate - 6 hours):
- ✅ Create FieldRegistry class
- ✅ Update GF_Partner, GF_JS, GF_Validation to use registry
- ✅ Maintain backwards compatibility via fallback IDs
- ✅ Add validation method

### Phase 2 (Next - 4 hours):
- ✅ Create admin diagnostics page
- ✅ Show field-by-field status
- ✅ Provide fix suggestions

### Phase 3 (Future - 4 hours):
- ✅ Export current form as template
- ✅ Create form import functionality
- ✅ Add "Quick Start" button

This gives you:
1. **Immediate benefit**: No more hardcoded IDs
2. **Backwards compatible**: Existing installations work
3. **Self-documenting**: Clear field definitions
4. **User-friendly**: Clear error messages
5. **Future-proof**: Can evolve without breaking changes
6. **Low maintenance**: Single source of truth

**Next step**: Should I implement Phase 1 (FieldRegistry + updates)?
