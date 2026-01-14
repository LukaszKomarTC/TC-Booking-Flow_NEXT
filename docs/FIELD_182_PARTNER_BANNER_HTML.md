# Gravity Forms Field 182 - Partner Banner HTML

## Instructions

1. Open Gravity Forms editor for Form 48 (Evento [conectado con WC])
2. Add a new **HTML** field
3. The field ID should be **182** (auto-assigned as nextFieldId)
4. Set **CSS Class Name** to: `tcbf-partner-banner-field`
5. Set **Conditional Logic**:
   - Show this field if Field 181 (Partners Enabled) is NOT equal to `0`
6. Copy the HTML content below into the **Content** field
7. Save the form

---

## HTML Content for Field 182

```html
<div class="tcbf-partner-banner"
     id="tcbf-partner-banner-48"
     data-form-id="48"
     style="display:none;">

    <div class="tcbf-partner-banner__icon">✓</div>
    <div class="tcbf-partner-banner__content">
        <div class="tcbf-partner-banner__title"></div>
        <div class="tcbf-partner-banner__details">
            <span class="tcbf-partner-name"></span>
            <span class="tcbf-partner-discount"></span>
        </div>
    </div>
</div>

<style>
.tcbf-partner-banner {
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
.tcbf-partner-banner__icon {
    font-size: 28px;
    line-height: 1;
    flex-shrink: 0;
    color: #22c55e;
}
.tcbf-partner-banner__content {
    flex: 1;
}
.tcbf-partner-banner__title {
    font-size: 16px;
    font-weight: 700;
    color: #14532d;
    margin: 0 0 4px 0;
    line-height: 1.3;
}
.tcbf-partner-banner__details {
    font-size: 14px;
    font-weight: 400;
    color: #166534;
    margin: 0;
    line-height: 1.4;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.tcbf-partner-name {
    font-weight: 600;
}
.tcbf-partner-banner__details span {
    display: inline-block;
}
.tcbf-partner-banner__details span:not(:last-child)::after {
    content: ' •';
    margin-left: 8px;
    opacity: 0.6;
}
@media (max-width: 768px) {
    .tcbf-partner-banner {
        padding: 14px 16px;
        gap: 12px;
    }
    .tcbf-partner-banner__icon {
        font-size: 24px;
    }
    .tcbf-partner-banner__title {
        font-size: 15px;
    }
    .tcbf-partner-banner__details {
        font-size: 13px;
    }
}
</style>
```

---

## Field Placement Recommendation

Place field 182 **near the pricing summary** (field 177) so users see the partner discount confirmation while reviewing their booking details.

Suggested order:
- Field 177: Pricing Summary
- **Field 182: Partner Banner** ← NEW
- (other form fields)

---

## How It Works

1. **Default State**: Banner is hidden (`display:none`)
2. **JavaScript Detection**: When partner context is detected (logged-in partner, field 63 selection, or manual coupon), JavaScript populates:
   - `.tcbf-partner-banner__title` → "Partner Discount Applied"
   - `.tcbf-partner-name` → Partner code (e.g., "CYCLING-CLUB-25")
   - `.tcbf-partner-discount` → Discount percentage (e.g., "25% discount")
3. **Show Banner**: JavaScript sets `display: flex` to reveal banner
4. **Dynamic Updates**: When field 63 (partner selection) changes, banner updates instantly

---

## Conditional Logic Details

The field should have GF conditional logic:
- **Show** if Field 181 (Partners Enabled) **is not** `0`

This ensures the banner is only visible when the partner program is enabled for the event.

---

## Visual Design

- **Background**: Light green gradient (#f0fdf4 → #dcfce7)
- **Border**: 4px solid green (#22c55e) on left
- **Icon**: ✓ (checkmark) in green
- **Text**: Dark green (#14532d, #166534)
- **Shadow**: Subtle green shadow
- **Mobile**: Responsive padding and font sizes

---

## Testing Checklist

After adding field 182:

- [ ] Field ID is 182
- [ ] CSS class is `tcbf-partner-banner-field`
- [ ] Conditional logic set (show if field 181 ≠ 0)
- [ ] Banner is hidden by default
- [ ] When partner is logged in → banner shows with correct info
- [ ] When field 63 changes → banner updates instantly
- [ ] When partners disabled (field 181 = 0) → banner hidden
- [ ] Mobile responsive (test on phone)
- [ ] Multilingual text works (if using qTranslate-X)

---

## Troubleshooting

**Banner doesn't show:**
- Check field ID is exactly 182
- Check conditional logic is set correctly
- Check browser console for JavaScript errors
- Verify partner context exists (logged-in partner or field 63 has value)

**Banner shows but empty:**
- Check partner data is valid in field 154 (partner code)
- Check field 152 has discount percentage
- Verify JavaScript is loading (check Network tab)

**Banner doesn't update when field 63 changes:**
- Check field 63 has change event listener bound
- Check browser console for errors
- Verify `updatePartnerBanner()` function is in page source

---

## Related Files

This banner is part of the EB & Partner Discount Banners feature:

- `/includes/sc-event-template-functions.php` - EB stripe function
- `/single-sc_event.php` - EB stripe rendering
- `/includes/Integrations/GravityForms/GF_JS.php` - Partner banner JavaScript
- **This file** - Field 182 HTML content

---

## Support

If you encounter issues, check:
1. Browser console for JavaScript errors
2. Gravity Forms conditional logic logs
3. Partner resolution logic in `PartnerResolver.php`
4. Field population in `GF_Partner.php`
