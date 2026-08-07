# Hybrid Payment and Simulated GPS Demo Design

## Context

Savora already supports server-authoritative checkout, Cash on Delivery (COD),
Savora Wallet, a SeaPay QR page and authenticated SeaPay webhook, Restaurant
order operations, Driver dispatch, delivery milestones, and storage of the
Driver's latest coordinates. The remaining demo gap is a single, understandable
workflow that connects these features from Customer checkout through Restaurant
preparation, Driver delivery, live Customer tracking, and Customer receipt
confirmation.

The demonstration runs on one Windows laptop with XAMPP, PHP, MySQL, and the
existing Vanilla JavaScript frontend. It must support both four simultaneous
browser sessions and a sequential fallback in which one person logs out and
changes roles. No paid service, Node.js process, WebSocket server, scheduler, or
physical laptop movement is required.

## Goals

1. Present two payment timings at checkout: pay now or pay on receipt.
2. Keep real SeaPay webhook support while providing a reliable local payment
   simulator in explicit demo mode.
3. Show the same order moving through Customer, Restaurant, and Driver pages.
4. Display a live Customer tracking card and an automatically moving Driver
   marker during delivery.
5. Make Customer receipt confirmation the final action that completes an order.
6. Preserve authorization, ownership checks, idempotency, optimistic locking,
   audit history, and existing server-authoritative data boundaries.

## Non-goals

- Production-scale real-time transport, background workers, or WebSockets.
- Automatic route calculation from an external routing API.
- Card payment integration.
- A mobile application or real movement of the laptop.
- A redesign of unrelated Customer, Restaurant, Driver, or Admin pages.
- Offline caching or bulk downloading of OpenStreetMap tiles.

## End-to-end Workflow

### 1. Customer checkout and payment

Checkout groups the existing choices by payment timing:

- **Pay now**: SeaPay is the primary demo path. Savora Wallet remains available
  as an already-supported immediate payment method.
- **Pay on receipt**: COD creates a pending payment that is collected when the
  Customer confirms receipt.

Order creation remains atomic and idempotent. For Wallet, the payment is already
paid. For COD, the Restaurant can process the order immediately while payment
remains pending. For SeaPay, the order exists for the Customer with the label
"Waiting for payment," but the Restaurant cannot read or act on it until the
payment is paid.

The SeaPay page behaves in two compatible modes:

- In explicit Savora demo mode, the authenticated Customer sees a
  **Simulate successful payment** button. The action is protected by CSRF,
  idempotency, order ownership, payment-method, amount, and current-status
  checks. It creates a unique demo provider transaction and calls the same
  payment confirmation service used by the real webhook.
- When real SeaPay bank and webhook configuration is present, the QR and
  existing provider webhook remain usable. The webhook authenticates with the
  configured API key, matches the order reference and exact amount, and rejects
  duplicate provider transactions. Real SeaPay is optional for the laptop demo.

Migration `020_sepay_webhook_hardening` must be applied before either path is
used. Personal bank details must not remain hard-coded in the page. If no bank
configuration is present, demo mode shows a local demo payment panel and does
not generate a transfer QR that appears real.

### 2. Restaurant preparation

The Restaurant Live Orders page refreshes server data while visible. It displays
COD orders immediately and online-payment orders only after payment succeeds.

The Restaurant uses two primary actions:

1. **Accept and start preparing** changes a pending order to `confirmed`.
   Customer copy maps `confirmed` to "Restaurant is preparing."
2. **Food is ready** allows `confirmed -> ready_for_pickup` for this simple
   workflow and continues to allow legacy `preparing -> ready_for_pickup` data.
   Entering `ready_for_pickup` creates the existing dispatch workflow.

The Restaurant may reject an order before it becomes ready. Repeated clicks and
stale versions return the existing safe idempotent/conflict responses.

### 3. Driver assignment and pickup

When the order becomes ready, an eligible Driver receives the existing delivery
offer. The Driver selects **Accept delivery**. The order becomes assigned and the
Customer card displays that a Driver has accepted the trip.

For the simple demo, the active delivery page exposes **Picked up - start
delivery**. In demo mode this server command atomically records the required
pickup milestones, changes the order to `picked_up`, and starts one simulated
route. Production-style individual arrival/pickup commands remain available to
the service layer but are not required by the demo UI.

### 4. Server-timed simulated route

The simulated route is server-timed so it continues when the Driver page is
closed or the user switches roles. Starting the route stores:

- delivery identifier and Driver identifier;
- Restaurant start latitude/longitude;
- Customer destination latitude/longitude;
- server start time and a 60-second demo duration;
- route status and optimistic-lock version.

The route uses a deterministic set of interpolated points between the saved
Restaurant and Customer coordinates. It does not call Geoapify or an external
routing service. Reading tracking data calculates progress from server time and
returns the current point. At 100 percent, the marker waits at the Customer
location; the system does not automatically mark the order delivered.

The Customer tracking card refreshes with recursive `setTimeout` calls every two
seconds only while the page is visible and the order is active. The Driver and
Restaurant live views use the same bounded refresh behavior. A failed request
backs off exponentially to a maximum of 15 seconds and retains the last valid
state rather than inventing new coordinates.

The map uses the Leaflet library already bundled in the repository. Normal
interactive OpenStreetMap tiles may be loaded only for the visible demo
viewport, with attribution. If tiles are unavailable, the card still renders a
local neutral map surface, route line, endpoints, marker, percentage, and text
status; order tracking therefore remains demonstrable without the tile service.

### 5. Delivery and Customer confirmation

After the route reaches the destination, Driver selects **Delivered to
Customer**. The delivery and order become `delivered`, the simulated route stops,
and the Customer card displays a confirmation action.

Customer selects:

- **I received my order** for prepaid orders; or
- **Received and paid** for COD orders.

This authenticated Customer-only command verifies ownership, order status,
expected version, and idempotency key. It changes the order from `delivered` to
the new final state `completed` and appends order history and audit records. For
COD it also changes the payment from pending to paid in the same database
transaction. Driver delivery alone must no longer mark COD as paid.

Completed orders leave the live tracking card and remain available in Customer,
Restaurant, Driver, and Admin history views.

## Customer Tracking Card

One card presents the current order through these states:

1. Waiting for SeaPay payment.
2. Order placed - waiting for Restaurant.
3. Restaurant accepted - preparing.
4. Food ready - finding a Driver.
5. Driver accepted the delivery.
6. On the way - live simulated GPS is visible.
7. Driver reported delivery - waiting for Customer confirmation.
8. Completed.

Before delivery starts, the card shows the progress timeline and current
participant. During `picked_up`, it additionally shows the map, Driver marker,
route progress, and last server update. During `delivered`, it shows the receipt
confirmation button instead of treating the order as complete.

## Role and Session Behavior

- **Customer** places, pays, watches, and confirms the order.
- **Restaurant** accepts the paid/COD order and marks food ready.
- **Driver** accepts the delivery, starts the simulated route, and reports
  delivery.
- **Admin** observes the same order/payment/dispatch records and can be tested
  independently; Admin is not required to manufacture normal order state.

Simultaneous testing uses separate browser profiles, private windows, or
different browsers so each role has a distinct PHP session cookie. Sequential
testing uses normal logout/login. Because route position derives from server
time, it continues across sequential role changes.

## Components and Boundaries

### Payment

- A focused payment confirmation service owns matching, duplicate protection,
  status change, `paid_at`, provider reference, notification, and audit behavior.
- The SeaPay webhook parses and authenticates provider input, then calls the
  payment confirmation service.
- A demo-only authenticated endpoint constructs a valid simulated provider event
  for the Customer's own pending SeaPay order, then calls the same service.
- Restaurant order queries enforce the prepaid-payment gate on the server, not
  only in JavaScript.

### Tracking

- A migration stores one active/completed demo route per delivery.
- A simulated route service owns route creation, time-based point calculation,
  status, and authorization-safe output.
- A focused tracking endpoint returns only the order, payment, delivery, route,
  and current-location fields needed by authorized participants.
- Existing order and delivery services remain authoritative for business status
  transitions.

### Customer completion

- A Customer receipt service owns the `delivered -> completed` transition and
  the atomic COD payment settlement.
- The generic Restaurant/Driver transition endpoint does not grant this
  Customer authority.

## Failure Handling

- SeaPay wrong amount, wrong reference, duplicate transaction, unpaid order, or
  missing configuration leaves payment pending and returns a bounded message.
- The demo payment action is unavailable unless demo mode is explicitly enabled.
- A Restaurant cannot accept an unpaid SeaPay order even with a crafted request.
- A Driver cannot start or complete another Driver's delivery.
- A simulated route cannot start without assigned delivery ownership and saved
  Restaurant/Customer coordinates.
- Refresh failures preserve the last rendered state and retry with bounded
  backoff. Stale location data is labelled.
- Repeated actions use idempotency keys; stale versions require a server refresh.
- Customer confirmation cannot run before Driver delivery or more than once.

## Verification Strategy

Automated tests cover:

1. Payment service parity between real webhook and demo simulator.
2. SeaPay authorization, exact amount, unique provider transaction, and retries.
3. Server-side hiding/rejection of unpaid SeaPay orders for Restaurant.
4. COD remaining pending after Driver delivery and becoming paid only on
   Customer confirmation.
5. Order status permissions, versions, history, notifications, and idempotency.
6. Demo route start authorization, deterministic progress, completion boundary,
   and sequential-session time progression.
7. Tracking endpoint visibility for Customer, assigned Driver, owning
   Restaurant, and Admin, plus denial for unrelated accounts.
8. Customer tracking card states, map fallback, refresh/backoff, and confirmation
   controls.
9. Restaurant and Driver action labels and server commands.

A manual four-role scenario verifies the full flow twice:

- once with four separate browser sessions open simultaneously; and
- once sequentially by logging out and changing roles while the route continues.

The primary demo uses simulated SeaPay. An optional integration check may use
SeaPay Test Mode or a real configured webhook through a public HTTPS endpoint,
but external connectivity is not a completion requirement for the local demo.
