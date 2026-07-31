# Savora Admin Portal Mockup Design

Date: 2026-07-31
Status: Approved page scope; pending written-spec review

## Goal

Create eleven high-fidelity desktop website mockups for the Savora Admin portal. The screens must use English UI copy, share the Customer, Restaurant Owner, and Delivery Driver portals' visual language, and communicate realistic platform-wide operations built on shared PHP/MySQL data.

The Admin is one full-access account. Admin actions are controlled interventions: sensitive actions require a reason, preserve business history, create an audit event, and notify affected roles.

## Output

- Eleven separate high-fidelity PNG mockups.
- Landscape desktop web-app composition at a consistent 16:10 ratio.
- Target folder: `docs/mockups/admin-portal/`.
- One consistent Admin shell and navigation across every screen.
- English UI copy only.
- No watermark, browser chrome, device frame, decorative illustration, or unrelated branding.

## Visual System

- Primary forest green: `#073B2B`.
- Deep forest green for header and hover: `#04291E`.
- Coral CTA and location accent: `#EF634B`.
- Ivory workspace background: `#FBF9F3`.
- Sage secondary surface: `#E8EDDF`.
- Primary text: `#1C2923`.
- Secondary text: `#657169`.
- Subtle border: `#DFE4DA`.
- Accessibility focus: `#1B75D0`.
- Typography: clear modern sans-serif; strong page titles, readable operational tables, restrained uppercase labels.
- Components: 14–16px rounded cards, subtle shadows, compact status badges, tabs, filters, search, pagination, charts, confirmation panels, drawers, and accessible form controls.
- Density: operational and data-rich, but with strong grouping and generous spacing. Avoid oversized empty cards or dashboard decoration.

## Shared Shell

- Persistent dark-forest left sidebar with Savora wordmark and `Admin Control` label.
- Navigation order: Overview; Accounts; Customers; Restaurants; Drivers; Orders; Cases & Refunds; Finance; Promotions & Fees; Analytics; Settings & Audit.
- Compact ivory top bar with page breadcrumb, global search, notification bell with badge, `Admin Mode` badge, and Admin avatar.
- Active navigation item uses a lighter green/sage treatment with an obvious marker.
- Notification center is a shared header drawer, not a separate page.
- Detail records open in a right-side drawer or modal when practical and do not count as separate pages.

## Shared Status Language

- Application: `Pending Review`, `Needs Changes`, `Approved`, `Rejected`.
- Account: `Active`, `Suspended`, `Blocked`.
- Order: `Pending`, `Confirmed`, `Preparing`, `Ready for Pickup`, `On the Way`, `Completed`, `Cancelled`, `Refunded`.
- Dispatch: `Searching Driver`, `Offer Sent`, `Assigned`, `Arrived`, `Picked Up`, `Delivered`.
- Case: `Open`, `Investigating`, `Waiting for Customer`, `Waiting for Partner`, `Resolved`.
- Payout: `Scheduled`, `Processing`, `Paid`, `Failed`, `On Hold`.

Status must be communicated through both text and color/icon, never color alone.

## Screen Specifications

### 1. System Overview

Filename: `01-admin-overview.png`

- Page title `System Overview` and date-range selector.
- KPI cards: Gross Order Value, Active Orders, Platform Revenue, Pending Approvals.
- Live Operations panel showing orders preparing, waiting for driver, on the way, and delayed.
- Approval Queue split between restaurants and drivers with `Review` actions.
- Revenue trend chart and order-status distribution.
- High-priority alerts for stuck orders, expiring driver documents, payout failure, and open refund cases.
- Recent Admin Activity timeline.

### 2. Accounts & Access

Filename: `02-accounts-access.png`

- Page title `Accounts & Access`.
- Search plus Role, Status, and Created Date filters.
- Summary chips for Active, Suspended, Blocked, and New This Week.
- Unified account table with user, role, contact, status, last sign-in, created date, and action menu.
- Selected account drawer with identity, recent sessions, role link, security history, and actions: `Reset Password`, `Revoke Sessions`, `Suspend Account`.
- Suspension confirmation requires a reason and explains the effect on the selected role.
- Restaurant and driver accounts cannot be created here before approval.

### 3. Customer Management

Filename: `03-customer-management.png`

- Page title `Customers`.
- Search and filters for account status, order activity, wallet risk, and joined date.
- Customer table with total orders, lifetime spend, wallet balance, open cases, and status.
- Selected customer drawer showing profile, masked contact data, recent orders, wallet ledger, reviews, refunds, saved-address count, and risk signals.
- Contextual actions: open case, issue ledger adjustment through Finance, suspend account, or view full order history.
- No direct editing of wallet balance.

### 4. Restaurants & Approvals

Filename: `04-restaurants-approvals.png`

- Page title `Restaurants & Approvals`.
- Tabs: `Pending Applications`, `Active Restaurants`, `Suspended`.
- Pending count and SLA indicator.
- Application list with restaurant, owner, cuisine, city, submitted date, document completion, and risk flag.
- Selected application review panel with business registration, owner identity, bank verification, address, operating profile, document checklist, and notes.
- Primary actions: `Approve Restaurant`, `Request Changes`, `Reject Application`.
- Approval explains that the owner account and restaurant profile will be created together.
- Active restaurant table shows accepting-orders state, rating, live orders, cancellation rate, and payout status.

### 5. Drivers & Verification

Filename: `05-drivers-verification.png`

- Page title `Drivers & Verification`.
- Tabs: `Pending Applications`, `Active Drivers`, `Document Alerts`, `Suspended`.
- Application queue with driver name, city, vehicle, submitted date, document completion, and risk flag.
- Selected review panel with identity, driver license, vehicle registration, insurance, bank account, service area, and document expiry dates.
- Primary actions: `Approve Driver`, `Request Changes`, `Reject Application`.
- Active driver table shows Online/Offline, eligibility, active delivery, acceptance rate, completion rate, rating, and COD balance.
- Expired or rejected documents visibly make a driver ineligible for new offers.

### 6. Orders & Dispatch

Filename: `06-orders-dispatch.png`

- Page title `Orders & Dispatch`.
- Operational tabs: `Live Orders`, `Needs Attention`, `Order History`.
- Filters for order status, restaurant, driver, payment method, and time window.
- Live order table with order, customer, restaurant, driver, status, elapsed time, payment, and alert state.
- Selected order drawer displays the complete role-attributed status timeline, items, payment, restaurant preparation, dispatch attempts, driver milestones, and delivery address privacy treatment.
- Controlled actions: `Reassign Driver`, `Cancel Order`, `Open Incident`.
- Driver rejection and timeout remain dispatch events and do not change the customer-visible order status.

### 7. Cases, Incidents & Refunds

Filename: `07-cases-refunds.png`

- Page title `Cases, Incidents & Refunds`.
- Queue grouped by priority and SLA with type, reporting role, order, assignee label, age, and state.
- Selected case workspace with conversation timeline across Customer, Restaurant, Driver, and Admin; evidence attachments; linked order and payment snapshot.
- Resolution controls: `Request Information`, `Reassign Driver`, `Cancel Order`, `Partial Refund`, `Full Refund`, `Resolve Case`.
- Refund form shows refundable amount, destination, impact on restaurant/driver settlement, mandatory reason, and confirmation summary.
- Every resolution lists which roles will be notified.

### 8. Finance & Reconciliation

Filename: `08-finance-reconciliation.png`

- Page title `Finance & Reconciliation`.
- Tabs: `Transactions`, `Restaurant Payouts`, `Driver Payouts`, `COD Reconciliation`, `Refunds`.
- KPI cards: Customer Payments, Platform Commission, Partner Payables, Refunds.
- Ledger table with immutable transaction ID, order, type, party, gross, fee, net, method, date, and status.
- Payout batch panel with next payout date, eligible amount, held amount, failure count, and `Review Payout Batch` action.
- COD reconciliation highlights amount collected, amount due, discrepancies, and settlement status.
- Adjustments create new ledger entries; balances cannot be overwritten.

### 9. Promotions, Fees & Service Areas

Filename: `09-promotions-fees.png`

- Page title `Promotions, Fees & Service Areas`.
- Tabs: `Promotions`, `Platform Fees`, `Delivery Fees`, `Service Areas`.
- Promotion table with code, audience, benefit, budget, redemptions, schedule, state, and action menu.
- Promotion editor panel includes eligibility, minimum order, usage cap, start/end time, restaurant scope, and previewed Customer checkout effect.
- Fee cards show platform commission, base delivery fee, distance rate, surge rule, and effective date.
- Service-area panel lists zones with active status, delivery radius, minimum order policy, and available-driver health.
- Fee changes are scheduled and audited, not silently applied retroactively.

### 10. Analytics & Reports

Filename: `10-analytics-reports.png`

- Page title `Analytics & Reports`.
- Date range, comparison period, city, restaurant, and delivery-type filters.
- KPI cards: GMV, Completed Orders, Cancellation Rate, Average Delivery Time.
- Charts for revenue/orders trend, funnel from placed to completed, cancellation reasons, order-status mix, and hourly demand.
- Performance tables for top restaurants and driver efficiency.
- Customer retention and repeat-order panel.
- Export actions for CSV/PDF appear as secondary controls.

### 11. Settings & Audit Log

Filename: `11-settings-audit.png`

- Page title `Settings & Audit Log`.
- Tabs: `Platform Settings`, `Notifications`, `Security`, `Audit Log`.
- Settings cards for order timeouts, dispatch offer duration, support SLA, maintenance mode, and platform contact details.
- Notification templates show event, recipient roles, channels, status, and `Edit Template` action.
- Security section shows session policy and sensitive-action confirmation rules for the one full-access Admin account.
- Audit table includes timestamp, Admin, action, entity, before/after summary, reason, IP/session, and result.
- Audit entries are immutable and filterable by action, entity type, result, and date.

## Cross-Role Interaction Rules

### Partner registration

Restaurant Owner and Driver submissions are stored as applications. Admin approval creates the login account and role profile atomically. `Request Changes` returns the application to the applicant without creating an operational account. Rejection records a reason and sends a notification.

### Shared order lifecycle

- Customer creates `Pending` orders.
- Restaurant owns `Confirmed`, `Preparing`, and `Ready for Pickup` transitions.
- Dispatch offers the ready order exclusively to eligible drivers.
- Driver owns arrival, pickup, on-the-way, and delivery milestones.
- Admin observes all stages and uses controlled exception actions only.

### Suspension effects

- Suspended Customer: no new login or checkout; historical data remains.
- Suspended Restaurant: hidden from Customer discovery and blocked from new orders; active orders require explicit resolution.
- Suspended Driver: removed from dispatch eligibility; an active delivery must be resolved before suspension completes unless it is an emergency action.

### Financial integrity

Completed orders produce customer payment, platform commission, restaurant payable, and driver earning ledger entries. Refunds and adjustments append compensating entries. Admin never overwrites a wallet or payout balance.

## Interaction and State Requirements

- Every list has loading, empty, error, filtered-empty, and populated states.
- Destructive or financially sensitive actions use a confirmation dialog with a mandatory reason.
- Successful actions show a toast, update the current record, append an audit event, and create role-specific notifications.
- Failed actions preserve entered data and explain the corrective next step.
- Table rows are keyboard reachable; controls have visible `#1B75D0` focus styling.
- Drawers and dialogs trap focus, support Escape where safe, and return focus to the triggering control.
- Personally identifiable information is masked until intentionally revealed for an authorized task.

## Data and Architecture Boundary

The mockups assume PHP session authentication and MySQL as the authoritative source for accounts, applications, profiles, restaurants, menus, orders, dispatch, payments, wallet ledger, payouts, cases, notifications, and audit logs. Browser local storage may retain only non-authoritative UI preferences or an unsubmitted cart.

All state-changing Admin actions are server validated, transaction-safe where multiple records change, role authorized, idempotent when repeat submission is possible, and written to the audit log.

## Mockup Acceptance Criteria

- Exactly eleven separate screens are delivered with the specified filenames.
- All visible interface copy is English.
- All screens share the same shell, palette, component language, spacing, and status vocabulary.
- Each screen clearly communicates its primary task and highest-priority action.
- Approval, suspension, order exception, refund, payout, and audit flows visually expose their safeguards.
- The mockups remain practical product UI rather than decorative concept art.
- No screen includes a watermark, device frame, browser frame, illegible ornamental text, or unrelated navigation item.
