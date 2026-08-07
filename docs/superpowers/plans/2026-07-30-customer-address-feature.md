# Customer Address and Device Location Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Customer users save a delivery address manually or with a single device-location click, then show that same local address in Discover and Checkout.

**Architecture:** Keep `SavoraState.setProfile` as the existing local persistence boundary. `customer_profile.php` owns the one-shot browser location interaction and writes a formatted coordinate fallback into the existing address field. `customer_dashboard.php` reads `state.profile.address`, while the existing Checkout prefill continues to read that value.

**Tech Stack:** PHP templates, vanilla browser JavaScript, localStorage-backed `SavoraState`, Node built-in test runner, existing browser QA script.

## Global Constraints

- Do not use Google Maps, any paid map API, OpenStreetMap/Nominatim, or any outbound geocoding request.
- Use `navigator.geolocation.getCurrentPosition` only after an explicit button click; never call `watchPosition`, `setInterval`, or background location access.
- Preserve manual entry, existing page structure, existing local-only profile persistence, and the 300-character address limit.
- On location failure, permission denial, timeout, or unsupported browsers, keep manual entry available and give accessible explanatory feedback.
- Address data must be rendered through existing safe text APIs, not HTML insertion.
- This workspace has no usable Git repository; record verification in the task report instead of committing.

---

### Task 1: Add the local address and one-shot location experience

**Files:**
- Modify: `tests/customer_markup.test.js`
- Modify: `tests/task7_browser_qa.mjs`
- Modify: `customer_profile.php`
- Modify: `customer_dashboard.php`
- Modify: `.superpowers/sdd/customer-ui-2026-07-29/progress.md`
- Create: `.superpowers/sdd/customer-ui-2026-07-29/task-8-report.md`

**Interfaces:**
- Consumes: `SavoraState.load()`, `SavoraState.setProfile(state, patch)`, `SavoraState.persist(state)`, and `SavoraUI.refreshChrome()`.
- Produces: profile control `#use-current-location`, live status `#profile-location-status`, Discover text target `[data-delivery-location]`, and browser-QA coverage for a stubbed successful location lookup.

- [ ] **Step 1: Write failing source and browser-QA tests**

Add source assertions that `customer_profile.php` contains an editable `#profile-address`, `#use-current-location`, `navigator.geolocation.getCurrentPosition`, no `watchPosition`, no Google or geocoding hostname, and a live location status. Add assertions that Discover owns `[data-delivery-location]`. Extend browser QA to stub `getCurrentPosition`, click the button, verify the profile field and saved-address card, then verify the same value appears in Discover and Checkout.

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `node --test tests/customer_markup.test.js`

Expected: FAIL because the new address-location controls and browser-QA behavior are absent.

- [ ] **Step 3: Implement the smallest one-shot location flow**

In `customer_profile.php`, add the secondary button and an `aria-live` status below the existing address field. On click: reject unsupported geolocation with a manual-entry message; set the button pending; call `navigator.geolocation.getCurrentPosition` once with `{ enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 }`; validate finite latitude/longitude and format each to five decimals; set the textarea to `Current location (<lat>, <lon>)`; persist the existing profile fields via `SavoraState.setProfile`; refresh the page profile view and shared chrome; re-enable the button. Map numeric error codes to clear manual-entry guidance. Do not create an external request.

In `customer_dashboard.php`, replace the literal sample address with `[data-delivery-location]`, then render the saved profile address or `Choose a delivery address in Profile` on DOMContentLoaded using `textContent`.

- [ ] **Step 4: Run focused tests and verify GREEN**

Run: `node --test tests/customer_markup.test.js`

Expected: PASS, including the newly added source-level address assertions.

- [ ] **Step 5: Run integration verification and write the task report**

Run: `node --check js/customer_state.js; node --check js/customer_ui.js; node --test tests/customer_state.test.js tests/customer_markup.test.js; D:\\Xampp\\php\\php.exe -l customer_profile.php; D:\\Xampp\\php\\php.exe -l customer_dashboard.php; D:\\Xampp\\php\\php.exe -l customer_checkout.php; SAVORA_CDP_PORT=<fresh-port> node tests/task7_browser_qa.mjs`.

Expected: all checks pass, the browser test makes no external geocoding request, and the successful stubbed location is visible across Profile, Discover, and Checkout. Record changed files, exact commands, results, and self-review in `.superpowers/sdd/customer-ui-2026-07-29/task-8-report.md`.

- [ ] **Step 6: Review and ledger**

Create a manual pre-change snapshot, generate a `git diff --no-index --no-index` review package from that snapshot, obtain an independent review for specification compliance and code quality, resolve every Critical or Important finding, and append the clean review outcome to `.superpowers/sdd/customer-ui-2026-07-29/progress.md`.
