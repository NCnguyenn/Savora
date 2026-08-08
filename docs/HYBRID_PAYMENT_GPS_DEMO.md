# Hybrid Payment and GPS Demo Runbook

Use this runbook against `http://localhost/Savora/` to demonstrate an online SeaPay payment and a cash-on-delivery (COD) order through the same four-role delivery flow.

## Preparation

1. In the ignored `config/local.php`, set `'SAVORA_DEMO_MODE' => true`. No machine-wide environment change is required.
2. Ensure the development database migrations are current and the local web server is running.
3. Open Customer, Restaurant, Driver, and Admin in four isolated browser profiles or different browsers. Do not assume multiple private windows from one profile have separate cookies. If four isolated sessions are unavailable, use the sequential fallback below.
4. Sign in to each role. Use a Driver with saved profile coordinates and an eligible profile.
5. In the Driver session, select **Start demo shift**. Confirm the Driver is online before any Restaurant marks an order ready; this refreshes the saved Driver location for dispatch.

## Simultaneous four-role demo

### SeaPay demo order

1. Customer places an order and chooses **Pay now**.
2. Customer selects **Simulate successful payment**. The payment is paid before Restaurant processing.
3. Restaurant selects **Accept and start preparing**, then **Food is ready**.
4. Driver accepts the automatically created offer, then selects **Picked up - start delivery**.
5. Customer watches the 60-second route. The route progress is server-timed.
6. At the end of the route, Driver selects **Delivered to Customer**.
7. Customer selects **I received my order** and verifies completed history. The SeaPay payment remains paid.
8. Admin verifies the same order, payment, dispatch, delivery route, notifications, and audit records.

### COD demo order

Repeat the same preparation, Restaurant, Driver, Customer, and Admin sequence, but Customer chooses **Pay on receipt**.

1. Restaurant selects **Accept and start preparing**, then **Food is ready**.
2. Driver accepts the automatically created offer and selects **Picked up - start delivery**.
3. Driver selects **Delivered to Customer** only after the 60-second route has arrived.
4. Before the Customer confirmation, verify the payment is still pending.
5. Customer selects **Received and paid** / **I received my order**. The order becomes completed and the payment becomes paid.
6. Admin verifies the same order, payment, dispatch, and audit records.

## Sequential fallback

When only one browser profile is available, perform the same actions while logging out and in as the next role:

1. Customer: place and, for SeaPay, simulate payment.
2. Restaurant: accept, start preparing, and mark the food ready.
3. Driver: start the demo shift if it has not already been started, accept the offer, and start delivery.
4. Customer or Admin: inspect tracking while the Driver route is in progress.
5. Driver: deliver after arrival.
6. Customer: confirm receipt; then Admin: verify the records.

The route timer continues on the server while switching roles. Do not expect a fresh 60-second route after logging into another role, and do not select **Delivered to Customer** before tracking reports arrival.

## Expected state sequences

| Branch | Order state | Payment state |
| --- | --- | --- |
| SeaPay | `pending → confirmed → ready_for_pickup → assigned → picked_up → delivered → completed` | `pending → paid`, then remains `paid` |
| COD | `pending → confirmed → ready_for_pickup → assigned → picked_up → delivered → completed` | `pending` through Driver delivery, then `paid` on Customer receipt confirmation |

Each order history should identify Restaurant for `confirmed` and `ready_for_pickup`, Driver for `assigned`, `picked_up`, and `delivered`, and Customer for `completed`. Repeated clicks with the same completed request must replay the result rather than duplicating history, notifications, payment settlement, or delivery state.

## Browser verification record

This repository environment does not expose browser automation or an interactive browser session, so the following manual observations were not performed here. The automated PHP integration scenario covers the recorded service states and idempotent replays; an operator must fill in the browser observations when running this runbook locally.

| Role | Expected observed status | Browser observation in this environment |
| --- | --- | --- |
| Customer | SeaPay payment can be simulated; live route is visible; receipt completes the order; COD stays pending until receipt | Not observed: browser automation unavailable |
| Restaurant | Paid SeaPay order can be accepted; both orders can be marked **Food is ready** | Not observed: browser automation unavailable |
| Driver | **Start demo shift** refreshes location; offer can be accepted; delivery is gated until route arrival | Not observed: browser automation unavailable |
| Admin | Order, payment, dispatch, route, notifications, and audit records agree | Not observed: browser automation unavailable |

During a local manual run, additionally record whether:

- every role update appears without a forced page refresh;
- the browser console has no errors;
- the tracking map fallback remains usable after disabling network access; and
- repeated action clicks do not duplicate state, history, or notifications.

Do not mark these browser checks as passed until they have been observed in the local browser sessions.
