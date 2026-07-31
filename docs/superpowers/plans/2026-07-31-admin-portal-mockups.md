# Savora Admin Portal Mockups Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate, validate, and save eleven consistent high-fidelity English desktop mockups for Savora's Admin portal.

**Architecture:** Generate the System Overview first as the visual anchor. Use that image as the reference for every later screen so the sidebar, top bar, palette, typography, density, spacing, and component language remain stable. Generate one distinct PNG per page, inspect every output, and perform targeted regeneration only when a screen fails its content or consistency checks.

**Tech Stack:** Built-in ImageGen; local image inspection; PNG assets stored in `docs/mockups/admin-portal/`.

## Global Constraints

- Source of truth: `docs/superpowers/specs/2026-07-31-admin-portal-mockups-design.md`.
- Exactly eleven separate landscape desktop mockups at a consistent 16:10 ratio.
- English UI copy only.
- Palette: `#073B2B`, `#04291E`, `#EF634B`, `#FBF9F3`, `#E8EDDF`, `#1C2923`, `#657169`, `#DFE4DA`, and focus blue `#1B75D0`.
- Consistent shell: dark-forest left sidebar, ivory top bar/workspace, global search, notification bell, Admin Mode badge, and Admin avatar.
- Realistic, shippable product UI rather than concept art.
- No watermark, browser chrome, device frame, decorative illustration, unrelated branding, or Vietnamese copy.
- Approval, suspension, order exceptions, refunds, payouts, and audit actions must expose their safeguards.

---

### Task 1: Generate and lock the visual anchor

**Files:**
- Create: `docs/mockups/admin-portal/01-admin-overview.png`

**Interfaces:**
- Consumes: the approved Admin mockup specification and global palette.
- Produces: the visual reference image used by Tasks 2–4.

- [ ] **Step 1: Generate System Overview**

Use built-in ImageGen with use case `ui-mockup`. Request a high-fidelity 16:10 desktop Admin dashboard with the complete shared shell and these visible sections: `System Overview`, date range, KPI cards for `Gross Order Value`, `Active Orders`, `Platform Revenue`, and `Pending Approvals`; `Live Operations`; `Approval Queue`; revenue trend; order-status distribution; urgent alerts; and `Recent Admin Activity`.

- [ ] **Step 2: Inspect the anchor**

Confirm the sidebar contains the eleven specified navigation labels, all visible copy is English, the content is operational and readable, and the visual hierarchy uses forest green, ivory, sage, coral, and subtle borders consistently.

- [ ] **Step 3: Regenerate once if necessary**

If the shell, palette, text language, or page hierarchy is wrong, make one targeted regeneration that changes only the failing constraint.

- [ ] **Step 4: Save the accepted PNG**

Copy the final generated image into `docs/mockups/admin-portal/01-admin-overview.png` without overwriting unrelated assets.

### Task 2: Generate identity and approval management screens

**Files:**
- Create: `docs/mockups/admin-portal/02-accounts-access.png`
- Create: `docs/mockups/admin-portal/03-customer-management.png`
- Create: `docs/mockups/admin-portal/04-restaurants-approvals.png`
- Create: `docs/mockups/admin-portal/05-drivers-verification.png`

**Interfaces:**
- Consumes: `01-admin-overview.png` as the visual reference and the exact page requirements in the approved spec.
- Produces: four consistent governance screens for account access, Customer oversight, Restaurant approval, and Driver verification.

- [ ] **Step 1: Generate Accounts & Access**

Preserve the anchor shell. Show `Accounts & Access`, global search, Role/Status/Created Date filters, account summary chips, a unified user table, and a selected-account right drawer with sessions, security history, `Reset Password`, `Revoke Sessions`, and `Suspend Account`. Include a mandatory suspension reason treatment.

- [ ] **Step 2: Generate Customer Management**

Preserve the anchor shell. Show `Customers`, filters, a customer table with order count, lifetime spend, wallet, open cases, and status; include a selected customer drawer with masked contact details, recent orders, immutable wallet ledger, reviews, refunds, and risk signals.

- [ ] **Step 3: Generate Restaurants & Approvals**

Preserve the anchor shell. Show tabs `Pending Applications`, `Active Restaurants`, and `Suspended`; an application queue; and a review panel with business, owner identity, bank, address, document checklist, notes, plus `Approve Restaurant`, `Request Changes`, and `Reject Application`.

- [ ] **Step 4: Generate Drivers & Verification**

Preserve the anchor shell. Show tabs `Pending Applications`, `Active Drivers`, `Document Alerts`, and `Suspended`; an application queue; and a review panel with identity, license, registration, insurance, bank, service area, expiries, plus `Approve Driver`, `Request Changes`, and `Reject Application`.

- [ ] **Step 5: Inspect and save all four screens**

Confirm each screen uses English only, preserves the anchor shell, displays the correct active navigation item, and clearly communicates that Restaurant and Driver accounts are created only after approval. Save the four accepted PNGs to their specified paths.

### Task 3: Generate operations and money-flow screens

**Files:**
- Create: `docs/mockups/admin-portal/06-orders-dispatch.png`
- Create: `docs/mockups/admin-portal/07-cases-refunds.png`
- Create: `docs/mockups/admin-portal/08-finance-reconciliation.png`
- Create: `docs/mockups/admin-portal/09-promotions-fees.png`

**Interfaces:**
- Consumes: `01-admin-overview.png` as the visual reference and shared order/financial rules from the approved spec.
- Produces: four operational screens showing controlled interventions and immutable money flows.

- [ ] **Step 1: Generate Orders & Dispatch**

Preserve the anchor shell. Show `Orders & Dispatch`, tabs for live/attention/history, operational filters, a live order table, and a selected order drawer with role-attributed timeline, items, payment, restaurant preparation, dispatch attempts, driver milestones, and controlled `Reassign Driver`, `Cancel Order`, and `Open Incident` actions.

- [ ] **Step 2: Generate Cases, Incidents & Refunds**

Preserve the anchor shell. Show an SLA-prioritized case queue and selected case workspace with four-role conversation, evidence, linked order/payment, resolution controls, refund amount/destination/settlement impact, mandatory reason, and affected-role notification summary.

- [ ] **Step 3: Generate Finance & Reconciliation**

Preserve the anchor shell. Show tabs for transactions, Restaurant payouts, Driver payouts, COD reconciliation, and refunds; financial KPIs; an immutable ledger; payout batch review; COD discrepancies; and append-only adjustment language.

- [ ] **Step 4: Generate Promotions, Fees & Service Areas**

Preserve the anchor shell. Show tabs for promotions, platform fees, delivery fees, and service areas; a promotion table/editor; scheduled fee cards; zone health; eligibility rules; and a Customer-checkout effect preview.

- [ ] **Step 5: Inspect and save all four screens**

Confirm role-owned order states are respected, exception actions require safeguards, balances are not directly editable, and each output is English-only and visually consistent. Save all four accepted PNGs.

### Task 4: Generate intelligence/settings screens and verify the complete set

**Files:**
- Create: `docs/mockups/admin-portal/10-analytics-reports.png`
- Create: `docs/mockups/admin-portal/11-settings-audit.png`
- Create: `docs/mockups/admin-portal/00-admin-contact-sheet.png`

**Interfaces:**
- Consumes: all accepted mockups from Tasks 1–3.
- Produces: the final two mockups, a review contact sheet, and a verified eleven-screen deliverable set.

- [ ] **Step 1: Generate Analytics & Reports**

Preserve the anchor shell. Show `Analytics & Reports`, date/comparison/city/restaurant/delivery filters, KPI cards, revenue and order trends, completion funnel, cancellation reasons, order-status mix, hourly demand, Restaurant/Driver performance, retention, and secondary CSV/PDF export controls.

- [ ] **Step 2: Generate Settings & Audit Log**

Preserve the anchor shell. Show tabs `Platform Settings`, `Notifications`, `Security`, and `Audit Log`; order/dispatch/support settings; notification templates by role; one-Admin security rules; and an immutable audit table with reason and before/after summary.

- [ ] **Step 3: Inspect the two screens**

Confirm charts remain legible, exports are secondary, security controls match one full-access Admin, and the audit log looks immutable and filterable.

- [ ] **Step 4: Verify all deliverables**

List `docs/mockups/admin-portal/*.png` and confirm the eleven numbered page files exist, are non-empty PNGs, share a landscape aspect ratio, and match the approved filenames.

- [ ] **Step 5: Create a contact sheet**

Create `00-admin-contact-sheet.png` from the eleven final images for quick visual review. The contact sheet is supplementary and does not count as a twelfth page mockup.

- [ ] **Step 6: Commit the mockups**

Stage only `docs/mockups/admin-portal/*.png` and this implementation plan, then commit with message `design: add admin portal mockups`.
