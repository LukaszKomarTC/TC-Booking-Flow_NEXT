# 📘 TC Booking Flow — Project Control Document

> **Single Source of Truth (SSOT)**  
> This document defines the architecture, execution history, and actionable backlog for the **TC Booking Flow** project.

---

## 📌 Purpose

This document serves **two distinct purposes**:

1. **Document the main execution roadmap** (historical, frozen)  
2. **Collect and manage all issues, ideas, and improvements** discovered during development (actionable)

---

## 🧭 How This Document Is Used

- **Section A — Main Execution Roadmap**
  - Historical
  - Mostly frozen
  - Explains *what*, *why*, and *in what order*
  - **Not** an issue tracker

- **Section B — Issues & Ideas Backlog**
  - Actionable
  - Non-sequential
  - Each item handled in a **separate chat / GitHub issue**
  - Evolves over time

- Chat threads are **short-lived**
- This document **preserves project knowledge**
- Legacy threads (e.g. *TC Booking Flow Analysis*, *Booking Flow Issues*) are **historical only**

---

# SECTION A — MAIN EXECUTION ROADMAP (HISTORICAL)

> ⚠️ This section is **read-only**.  
> It documents how the system was built and **is not worked through again**.

---

## TC Booking Flow — Main Work List

### 1. Freeze baseline + rules of the system
- Baseline version frozen (initial working state)
- **Rule locked**:  
  `Events decide → Forms collect → PHP calculates once → Woo enforces`
- **Status:** ✅ Done

---

### 2. Parity with legacy flow (GF → Cart → Order)
- GF44 population works on `sc_event` pages
- Driver fields populate
- Conditional logic triggers correctly
- Add-to-cart works deterministically
- Snapshot pricing stored on cart & order items  
**Status:** ✅ Done

---

### 3. Rental UI lifecycle hardening (GF image choices toggle bug)
- Fix image choices disabling after rental toggle
- Implement `gform_post_render` + re-enable logic in plugin
- Remove last fragile JS dependency  
**Status:** ✅ Done

---

### 4. Booking scopes separation (participation + rental)
- Participation booking (no resources)
- Rental booking (with resources)
- Linked via order + metadata
- Email / booking templates handle **no resource** safely  
**Status:** ✅ Done  
*(Fatal Shopkeeper template issue fixed)*

---

### 5. Coupon + partner auto-apply mechanics
- Partner coupon auto-apply (role / URL / session)
- Coupon casing normalized
- Endpoint visibility & permissions hardened  
**Status:** ✅ Done

---

### 6. Partner offline gateway cleanup
- Offline gateway performs **no calculations**
- Ledger is the single authority
- Offline channel still creates valid orders & bookings  
**Status:** ✅ Done

---

### 7. Partner portal reporting (safe replication)
- Replace legacy inference logic
- Use order ledger values
- Align with Woo gross/net settings
- Backward-compatible for older orders  
**Status:** ✅ Done

---

### 8. Validation (moved into plugin)
- Server-side price validation  
  - mismatch → block  
  - missing / zero → self-heal
- Rental selection integrity check  
**Status:** ✅ Done

---

### 9. Early Booking Discount (EB) engine
- EB rules stored per event (recurrence-friendly)
- EB applied **before** partner discount
- Ledger fields persisted:
  - `eb_details`
  - `base_after_eb`
  - `commission_basis`  
**Status:** ✅ Done

---

### 10. Hardening + snippet migration (legacy → plugin)

#### 10.1 Parity checks across channels
- Partner order ✅
- Stripe:
  - Client (no coupon) ✅
  - Client (with coupon) ✅
  - Partner logged-in ✅  
**Status:** ✅ Done

#### 10.2 Migrate legacy snippets into plugin
- `#163` — SC Event header meta + renderer
- Backward-compatible HTML & CSS variable contract
- Remove duplicate “details in content”
- Fix:
  - Date format when **Event Details Block** moved to header

> **Current test version:** `v0.2.65`

---

### 11. Event admin UX consolidation
- One clean meta panel:
  - Pricing + rentals
  - EB rules
  - Header controls + CSS variables
  - Partner toggles (if needed)
- Clear schema & validation  
**Status:** 🔜 Next (after 10.2 stabilizes)

---

### 12. Discount engine formalization
- EB (done)
- Partner rules
- Coupon lifecycle
- Multi-discount transparency (GF / cart / order / portal)  
**Status:** 🔜 Later

---

### 13. Cleanup & finalization
- Remove legacy snippets
- Remove fallbacks / debug overlays
- Lock public API
- Tag stable release  
**Status:** 🔜 Final stage

---

# SECTION B — ISSUE & IDEAS BACKLOG (ACTIONABLE)

> ⚠️ Items are **independent** and **non-sequential**.  
> Each item becomes **one GitHub issue / one chat thread**.

---

## Booking Flow Issues / Ideas

> **Issue format convention (recommended for GitHub):**
> - **ID**
> - **Title**
> - **Why**
> - **Done when**

---

### 1. Email missing bike rental details (client-facing)
- **Why:** Confusion → support tickets
- **Done when:** Email prints rental/bike info from reliable meta (not `get_resource()`)

---

### 2. Visually connect participation + rental (grouping)
- **Why:** Admin & customer confusion
- **Pattern:** `tc_group_id`, `tc_scope`, `tc_hide_line`, `tc_event_key`
- **Done when:** Cart, checkout, order, emails show one grouped package

---

### 3. Deprecated API replacement
- **API:** `WC_Bookings_Controller::get_bookings_in_date_range`
- **Done when:** Replaced with `WC_Booking_Data_Store::get_bookings_in_date_range`

---

### 4. Resource can be false — defensive rendering
- **Why:** Avoid fatals & wrong display
- **Done when:** All templates handle “no resource” via meta

---

### 5. Booking group concept (data model)
- **Why:** Woo sees separate bookings
- **Done when:** Shared group ID stored on cart items, order items & bookings

---

### 6. Availability logic hardening
- **Why:** Prevent overbooking
- **Done when:** One centralized helper covers all edge cases

---

### 7. Scale-proof extras (delivery / insurance / shuttle)
- **Why:** UI breaks with more services
- **Done when:** Grouping supports multiple scoped services

---

### 8. Debug visibility control
- **Done when:** Notices logged only, never echoed

---

### 9. Partner coupon auto-apply policy
- **Question:** Can it be removed?
- **Done when:** Policy chosen & enforced consistently

---

### 10. Partner Portal v2 (optional)
- Filter by partner meta
- CSV export
- Woo-style tables  
**Done when:** Portal is robust & exportable

---

### 11. Partner fast checkout (hidden billing fields)
- **Why:** UX improvement
- **Done when:** Safe, role-limited, attribution preserved

---

### 12. Admin mode in Partner panel
- **Why:** Ops + reporting
- **Done when:** Admin can view/filter all partners

---

### 13. Order summary price split (partner-aware)
- **Done when:** Correct totals render per role from ledger

---

### 14. EB settings UX (no raw JSON)
- **Why:** Reduce admin errors
- **Done when:** UI-based tier editor with validation

---

### 15. GF entry cleanup to prevent ghost participants
- **Why:** GravityView noise
- **Done when:** Cart removal / expiry updates GF entry state

---

### 16. Show EB + partner discount visibly in event & form
- **Why:** Trust + clarity
- **Done when:** Form, cart, order show same discount logic

---

### 17. Clear EB rule messaging per event
- **Done when:** “Book now save X% until DATE” visible

---

### 18. Visual differentiation of categories
- **Options:** Colors / icons / logos
- **Done when:** Category recognizable instantly

---

### 19. Notifications overhaul
- **Include:** Actions, cancellation policy, event links
- **Done when:** Consistent, professional, actionable

---

### 20. Field mapping layer in plugin settings
- **Why:** Remove hardcoded GF field IDs
- **Done when:** Mapping UI with validation exists

---

### 21. Consent field with Privacy Policy link
- **Done when:** Dynamic, multilingual-safe link

---

### 22. qTranslate raw strings in admin
- **Done when:** Admin output is clean

---

### 23. Image choice design for unavailable resources
- **Done when:** CSS-class-based state system replaces inline CSS

---

### 24. Snippet audit candidate #136
- **Why:** Possible OpenPOS dependency
- **Done when:** Dependency verified and refactored safely

---

### 25. Technical recommendations bundle
- 25A Multi-day availability range bug  
- 25B Hardcoded product_cat IDs  
- 25C Timezone consistency  
- 25D Partner detection performance  
- 25E Pricing model transition (GF display only)  
**Done when:** Each sub-item resolved safely

---

### 26. TCBF-026 — Logo sizing meta not applied
- **Status:** OPEN
- **Phase:** 10.2
- **Scope:** Event header
- **Done when:** Logo size respects per-event meta + CSS contract

---

### 27. TCBF-027 — Header date/details styling regression
- **Status:** OPEN
- **Phase:** 10.2
- **Done when:** Layout matches intended design

---

### 28. TCBF-028 — Header date format bug
- **Preferred:** `4/02/2026 10:00–13:00`
- **Scope:** Event header date renderer
- **Done when:** Dates render **according to Sugar Calendar settings**, compact for same-day events

---

### 29. GravityView participants index starts at 0
- **Done when:** Index is 1-based

---

### 30. Core integrity upgrade (GF lifecycle)
- State machine: `draft → in_cart → paid → expired`
- Cart TTL alignment
- Reverse hooks
- Idempotency key  
**Done when:** Ghost entries eliminated & dedupe is bulletproof

---

## 🧩 Notes / Edge Cases

- Bike rental price `1,00 €` incorrectly showing as `100`
- Must support **0 € rentals** safely
