# TC Booking Flow — MASTER TRACKER (UPDATED)

*Last updated: 2026-01-11*

---

## FOUNDATION — LOCKED SYSTEMS

### GF Frontend Lifecycle & Price Integrity (LOCKED)

**Status:** ✅ DONE / 🔒 LOCKED

**Scope**

* Gravity Forms frontend lifecycle hardening
* Decimal-comma corruption detection and auto-repair (Stage-3)
* Single-bind JS strategy (no duplicate handlers)
* Debounced repairs + single recalculation point
* Admin-only diagnostics with log-once-per-session behavior

**Why this exists**
Gravity Forms may internally re-render or re-parse product base prices during:

* conditional logic application
* AJAX re-renders
* calculation cycles

In decimal-comma locales this can result in silent corruption, e.g.:

```
30,00 € → 3.000,00 €  (×100)
```

This layer guarantees **frontend price integrity** regardless of GF internal behavior.

**Behavior guarantees**

* Repairs run automatically and silently in production
* Booking totals remain correct even if GF reintroduces corruption
* Admin diagnostics log **at most one repair per field per session**

**Do NOT**

* Refactor or simplify this layer without verifying GF core behavior changes
* Reintroduce broad `change` listeners or non-debounced handlers

**Documentation**

* `docs/gf-frontend-lifecycle.md`

---

## COMPLETED TASKS

### Header / Event Display

* **TCBF-026** Logo sizing meta not applied on frontend — ✅ DONE
* **TCBF-027** Header date/details styling regression — ✅ DONE
* **TCBF-028** Header date format bug — ✅ DONE

### Gravity Forms / Booking Flow

* GF decimal-comma corruption resolved with Stage-3 auto-repair — ✅ DONE
* GF lifecycle hardening (Phases 1–4b) — ✅ DONE

---

## CURRENT MILESTONE

### **TCBF-11 — Event Admin UX Consolidation** 🔜 NEXT

**Goal**
Create a clear, unified admin experience for configuring events without requiring technical knowledge.

**Expected scope**

* Consolidated event meta panel
* Clear separation of:

  * pricing
  * rentals
  * early booking rules
  * header / display options
* Reduced risk of misconfiguration
* Improved clarity for non-technical admins

---

## QUEUED / OPTIONAL IMPROVEMENTS

### Ledger & Diagnostics

* Reduce `woo.cart.set_price_snapshot` log noise (log only on change)
* Add source context to snapshots (cart load / totals calc / checkout)

---

---

# docs/gf-frontend-lifecycle.md

## Gravity Forms Frontend Lifecycle & Price Integrity

This document explains **why** the GF frontend repair system exists, **how** it works, and **what must not be changed**.

---

## Problem Summary

On sites using **decimal comma locales**, Gravity Forms may intermittently re-parse product base prices during frontend lifecycle events.

### Observed corruption

```
Input shows:   30,00 €
GF internal:   3.000,00 €
Numeric value: 3000
```

This happens during:

* conditional logic re-application
* GF AJAX re-renders
* calculation cycles

The corruption is **silent** and would otherwise result in incorrect booking totals.

---

## Design Principles

1. **Correctness over elegance**
2. **Never trust GF internal price state**
3. **Repair instead of blocking**
4. **Minimal logging in production**

---

## Lifecycle Overview

### Trigger points

Stage-3 repair runs **only** after strong lifecycle events:

* `gform/post_render` (modern)
* `gform_post_render` (fallback)
* `gform/conditionalLogic/applyRules/end` (modern)
* `gform_post_conditional_logic` (fallback)

It does **not** run on every input change.

---

## Stage-3 Repair Logic

1. Read displayed base price
2. Parse numeric value using locale-aware parser
3. Compare against intended value (from event meta)
4. Detect ratios:

   * ×100
   * ×1000
5. Restore correct value if mismatch detected

---

## Logging Behavior

* Repairs always execute
* Logs are written **only once per field + intended value per page session**
* Logs appear only when **Debug mode is enabled**

### Log context

```
frontend_stage3_repair
```

Example payload:

```json
{
  "form_id": 48,
  "field": "ginput_base_price_48_141",
  "before_raw": "3.000,00 €",
  "after_raw": "30,00 €",
  "before_num": 3000,
  "intended_num": 30,
  "ratio": 100
}
```

---

## What NOT to Change

* Do not remove Stage-3 unless GF core behavior changes
* Do not add broad `change` listeners
* Do not remove debounce guards
* Do not log repairs on every occurrence

---

## Debugging Checklist

1. Enable **Debug mode** in TC Booking Flow settings
2. Load an event page with GF booking form
3. Check **Diagnostics** for `frontend_stage3_repair`
4. Verify booking totals remain correct

---

## Status

**This system is LOCKED.**

Any future GF-related work must assume this layer is present and operational.
