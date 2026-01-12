# Contributing — TC Booking Flow NEXT (TCBF NEXT)

This repo follows strict workflow rules to keep development deterministic and prevent regressions.

If you are an AI assistant collaborating on this project, you MUST follow the rules below.  
If you cannot follow them, stop and ask for a human to apply the changes.

---

## 0) Definitions

- **SSOT (Single Source of Truth)**: `docs/PROJECT_CONTROL.md`
- **Latest plugin snapshot**: `latest.zip` served from staging (see "Source Control Rules" below)
- **Ledger**: PHP ledger calculation is the only authoritative pricing logic.
- **Repo root = Plugin root**:  
  This GitHub repository root IS the WordPress plugin root  
  (e.g. `includes/`, `.github/`, `docs/`, `tc-booking-flow-next.php` live at repo root).

---

## 1) Source Control Rules (NON-NEGOTIABLE)

1. **Always pull from:**
   - `https://staging.lukaszkomar.com/dev/tc-booking-flow-next/latest.zip`

2. **Always confirm plugin version after pulling**
   - Read the header in: `tc-booking-flow-next.php`
   - Report the version in the response (e.g. `v0.3.18-alpha`)

3. **Never edit based on memory or old files**
   - Do not rely on previously pasted code.
   - Do not reuse older zips or local copies.
   - Every work session starts from a fresh pull of `latest.zip`.

4. **If a rule conflicts with a suggestion**
   - The rules in this file win.

---

## 2) File Delivery Rules (How changes are returned)

**CRITICAL:**  
This repository root IS the plugin root.  
All deliveries must preserve repo-relative paths exactly.

GitHub UI uploads must never alter folder structure or introduce nested roots.

---

### A) If only ONE file changed
- Provide **only the full file** (copy/paste ready).
- No diffs, no snippets.

---

### B) If ONE file changed and is >1500 lines
- Provide a **zip containing ONLY that file**
- The file MUST be placed inside the zip at its **repo-relative path**

Example (correct):
includes/Plugin.php

yaml
Copy code

---

### C) If TWO OR MORE files changed → **CHANGESET ZIP (REQUIRED)**

When more than one file is modified:

- Provide a **CHANGESET ZIP**
- The zip MUST contain:
  - **Only the changed and/or new files**
  - **Exact repo-relative paths**
- The zip MUST NOT:
  - Include the entire plugin unless explicitly requested
  - Include an extra top-level folder (e.g. `tc-booking-flow-next/`)

✅ Correct zip structure:
tc-booking-flow-next.php
includes/Plugin.php
includes/Domain/EventMeta.php

python
Copy code

❌ Forbidden zip structure:
tc-booking-flow-next/
└── tc-booking-flow-next.php

yaml
Copy code

Uploading a forbidden structure risks creating nested plugin roots and is not acceptable.

---

### D) Full plugin / repo zip
Provide a full plugin or repo zip **ONLY IF**:
- Explicitly requested by the human, OR
- A large refactor makes a changeset impractical

---

### E) REQUIRED MANIFEST for any zip delivery

Every zip (single-file or changeset) MUST include a file named:

__MANIFEST.txt

css
Copy code

With the following content:

Repo: TC-Booking-Flow_NEXT
Base branch: main
Pulled from: latest.zip (staging)

Version before → after:
0.3.17-alpha → 0.3.18-alpha

Modified:

tc-booking-flow-next.php

includes/Plugin.php

Added: none
Deleted: none

yaml
Copy code

This manifest is used for merge sanity checks and PR validation.

---

### F) No partial snippets (unchanged rule)
- Unless explicitly requested, never provide diffs or snippets.
- Default remains **full-file replacement**.

---

## 3) Thread Start Protocol (how to start a task)

To begin a new task thread, the requester should provide:

1) The issue ID or a clear title (e.g. `TCBF-028`)
2) A one-line instruction:
   - “Pull latest.zip and work on X”

The assistant must respond by:

1) Confirming it pulled `latest.zip`
2) Stating the plugin version from the header
3) Listing which file(s) it will modify
4) Then delivering changes using the File Delivery Rules

---

## 4) Pricing & Ledger Rules (LOCKED)

### A) Single Source of Truth
- **PHP ledger is the only authority.**
- Frontend (GF/JS) is display + UX only.
- WooCommerce must enforce ledger values (no drift).

### B) Locale (critical)
- Site uses **decimal_comma**
- Any JS-injected numeric values must respect locale:
  - `7,5` (never `7.5`)
- Parsing must support both:
  - input: `7,5` or `7.5`
  - output: always `decimal_comma`

### C) No silent recalculation
- Never introduce a second calculation path that can drift.
- If a field is missing/zero, self-heal by re-reading ledger source data.

---

## 5) Debug / Logging Rules

- Debug output must not leak to frontend or emails.
- Use logging mechanisms (e.g. `error_log`, plugin logger) rather than `echo/print`.
- Deprecation notices must not be user-visible.

---

## 6) Safety & Regression Rules

- Keep behavior backward-compatible unless explicitly approved.
- Preserve public HTML/CSS contracts for SC event header output.
- If a fix risks changing totals:
  - Do not proceed without explicitly describing the impact.

---

## 7) Documentation Rules

- `docs/PROJECT_CONTROL.md` is SSOT.
- When you discover a new persistent issue/idea:
  - Add it to Section B backlog and/or create a GitHub issue.
- When an item is resolved:
  - Update status in docs and close the GitHub issue.

---

## 8) Quick Checklist (Assistant must satisfy)

Before delivering changes, confirm:

- [ ] Pulled `latest.zip` from staging URL
- [ ] Confirmed plugin version from header
- [ ] Identified which files change
- [ ] Followed File Delivery Rules (full file / changeset zip)
- [ ] Zip (if any) preserves repo-relative paths and contains NO extra root folder
- [ ] Respected decimal_comma formatting
- [ ] Did not introduce new pricing authority outside ledger
