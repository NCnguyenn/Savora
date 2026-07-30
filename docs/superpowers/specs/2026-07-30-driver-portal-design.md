# Savora Driver Portal Design

Date: 2026-07-30  
Status: Approved for planning

## Objective

Add a driver portal that connects cleanly to Savora's existing customer and
restaurant-owner order flows. The system offers each delivery to one suitable
nearby online driver at a time. The driver has 30 seconds to accept or reject
the offer. A rejection or timeout causes the system to offer the delivery to
the next suitable driver.

## Approved Page Scope

The driver portal has five top-level pages:

1. **Overview (`driver_dashboard.php`)**
   - Switch between online and offline availability.
   - Show the driver's current location and today's summary.
   - Receive a 30-second delivery offer as a full-screen dialog or prominent
     modal.
   - Show restaurant distance, delivery distance, estimated earnings, payment
     method, and Accept/Reject actions.

2. **Active Delivery (`driver_delivery.php`)**
   - Show restaurant, customer, addresses, delivery note, order reference, and
     navigation context.
   - Guide the driver through: assigned, arrived at restaurant, picked up, on
     the way, and delivered.
   - Provide contextual contact and issue-reporting actions without exposing
     unnecessary customer data after completion.

3. **Delivery History (`driver_history.php`)**
   - List completed, cancelled, and failed deliveries.
   - Filter by date and outcome.
   - Open a delivery record to review its route, milestones, and earnings.

4. **Earnings (`driver_earnings.php`)**
   - Show earnings by day, week, and month.
   - Break down delivery fees, incentives, and adjustments.
   - Show cash-on-delivery reconciliation where applicable.

5. **Profile & Settings (`driver_profile.php`)**
   - Manage driver, vehicle, document, service-area, and notification details.
   - Display account and document verification status.
   - Keep logout and availability preferences accessible.

Notifications and support are not separate top-level pages in the initial
scope. Delivery offers appear on Overview, while support and incident actions
are contextual to Active Delivery. These can become separate pages later if
real operational usage requires them.

## Shared Order Flow

The shared order lifecycle remains:

`pending -> confirmed -> preparing -> ready_for_pickup -> on_the_way -> completed`

Responsibilities are divided as follows:

- Customer creates an order and observes its progress.
- Restaurant moves it from `pending` through `ready_for_pickup`.
- System searches for drivers, sends exclusive offers, handles timeouts, and
  assigns the first driver who accepts.
- Driver confirms arrival, pickup, departure, and successful delivery.
- Customer and restaurant both see `on_the_way` after pickup and `completed`
  after successful delivery.

The restaurant must no longer move a delivery order from `ready_for_pickup` to
`on_the_way` or `completed`; those transitions belong to the assigned driver.

## Delivery Dispatch State

Dispatch details are stored separately from the shared order status so that
offer rejection and timeout do not create misleading customer-visible status
changes.

Recommended delivery states:

`searching_driver -> offer_sent -> assigned -> arrived -> picked_up -> delivered`

Key rules:

- Only online, eligible drivers without an active delivery are candidates.
- Each offer is exclusive to one driver for 30 seconds.
- Accepting an active offer atomically assigns the driver and invalidates the
  offer.
- Rejecting or timing out returns the delivery to `searching_driver` and
  selects the next candidate.
- A driver can have only one active delivery in the initial scope.
- If no eligible driver is available, the order remains
  `ready_for_pickup`; customer and restaurant receive a waiting-for-driver
  message without changing the shared order status.

## Cross-Role Visibility

| Event | Customer sees | Restaurant sees | Driver sees |
|---|---|---|---|
| Restaurant starts preparing | Preparing | Preparing | No offer |
| Restaurant marks ready | Ready for pickup | Searching for driver | Offer if selected |
| Driver accepts | Driver assigned | Driver assigned | Active delivery |
| Driver confirms pickup | On the way | On the way | Navigate to customer |
| Driver completes delivery | Completed | Completed | Completed summary |
| Driver rejects or times out | Ready for pickup | Searching for driver | Offer dismissed |

## Error and Exception Handling

- Reject and timeout have the same redispatch result, but are recorded
  separately for operational reporting.
- Repeated Accept actions are idempotent and cannot assign the same order
  twice.
- An offline driver cannot receive a new offer.
- Losing connection does not silently complete or cancel a delivery; the last
  confirmed state remains.
- Delivery completion requires explicit confirmation. Proof-of-delivery or OTP
  can be added later without creating another top-level page.
- Cancellation and delivery incidents must identify the acting role and reason
  in the status history.

## Responsive Navigation

Desktop may use the existing Savora sidebar pattern. Mobile should use a compact
bottom navigation for Overview, Active Delivery, History, Earnings, and
Profile. The 30-second offer must remain visible and actionable on both
layouts.

## Acceptance Criteria

- The driver can go online and receive an exclusive 30-second offer.
- Accept starts one active delivery; Reject and timeout search for another
  driver.
- Driver milestones update the same order observed by customer and restaurant.
- Restaurant actions stop at `ready_for_pickup` for delivery orders.
- No role can perform a transition owned by another role.
- All five pages have useful empty, loading, active, error, and completed
  states.
- Existing customer order history and restaurant order center reflect driver
  updates consistently.
