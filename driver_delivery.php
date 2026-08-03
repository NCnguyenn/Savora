<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="delivery">
    <header class="driver-page-heading">
        <div>
            <p class="driver-eyebrow">Current route</p>
            <h1>Active delivery</h1>
            <p><strong data-active-order-id>No active order</strong> <span class="driver-chip" data-active-delivery-status>Waiting</span></p>
        </div>
        <a class="driver-secondary-action" href="driver_dashboard.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to overview</a>
    </header>

    <section data-active-delivery aria-live="polite">
        <div class="driver-empty-state driver-card" data-delivery-empty>
            <span><i class="fa-solid fa-route" aria-hidden="true"></i></span>
            <h2>No active delivery</h2>
            <p>Delivery milestones are validated and recorded by the server.</p>
            <a class="driver-primary-action" href="driver_dashboard.php">View server assignments</a>
        </div>

        <div class="driver-delivery-layout" data-delivery-content hidden>
            <div class="driver-delivery-map-column">
                <div class="driver-ready-banner driver-card" data-delivery-banner>
                    <span><i class="fa-solid fa-store" aria-hidden="true"></i></span>
                    <div><strong data-banner-title>Restaurant pickup</strong><p data-banner-copy>Follow the route to the pickup address.</p></div>
                </div>
                <div class="driver-map driver-route-map" data-delivery-map role="img" aria-label="Map showing pickup and drop-off route">
                    <span class="driver-map-road is-one" aria-hidden="true"></span>
                    <span class="driver-map-road is-two" aria-hidden="true"></span>
                    <span class="driver-map-road is-three" aria-hidden="true"></span>
                    <span class="driver-map-river" aria-hidden="true"></span>
                    <span class="driver-route-line" aria-hidden="true"></span>
                    <span class="driver-route-pin is-pickup" aria-hidden="true"><i class="fa-solid fa-store"></i></span>
                    <span class="driver-route-pin is-driver" aria-hidden="true"><i class="fa-solid fa-motorcycle"></i></span>
                    <span class="driver-route-pin is-customer" aria-hidden="true"><i class="fa-solid fa-house"></i></span>
                    <div class="driver-map-legend" aria-hidden="true"><span>Pickup</span><span>Driver</span><span>Customer</span></div>
                </div>
            </div>

            <div class="driver-delivery-details">
                <ol class="driver-card driver-delivery-timeline" data-delivery-timeline aria-label="Delivery progress"></ol>

                <div class="driver-party-grid">
                    <article class="driver-card driver-party-card" data-pickup-details>
                        <header><span><i class="fa-solid fa-store" aria-hidden="true"></i></span><p>Pickup</p></header>
                        <h2 data-pickup-name>Restaurant</h2>
                        <p data-pickup-address>Pickup address</p>
                        <span class="driver-chip">Location provided by restaurant</span>
                        <a class="driver-secondary-action" data-pickup-map-link href="https://www.google.com/maps" target="_blank" rel="noopener noreferrer">
                            <i class="fa-solid fa-map" aria-hidden="true"></i>Open in Maps
                        </a>
                    </article>
                    <article class="driver-card driver-party-card" data-customer-details>
                        <header><span><i class="fa-regular fa-user" aria-hidden="true"></i></span><p>Customer</p></header>
                        <h2 data-customer-name>Customer</h2>
                        <p data-customer-phone>Phone unavailable</p>
                        <p data-customer-address>Drop-off address</p>
                        <a class="driver-secondary-action" data-customer-call href="tel:+15550124580">
                            <i class="fa-solid fa-phone" aria-hidden="true"></i>Call customer
                        </a>
                    </article>
                </div>

                <div class="driver-delivery-meta-grid">
                    <article class="driver-card" data-delivery-items><h2>Items</h2><ul></ul></article>
                    <article class="driver-card"><h2>Delivery note</h2><p data-delivery-note>No delivery note provided.</p></article>
                </div>

                <div class="driver-card driver-delivery-payment" data-delivery-payment>
                    <span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i></span>
                    <strong data-payment-copy>Payment details</strong>
                </div>

                <div class="driver-delivery-actions">
                    <label class="driver-field" data-delivery-proof hidden for="driver-delivery-proof">
                        <span>Proof of delivery</span>
                        <input id="driver-delivery-proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf">
                        <small>Upload a photo or PDF (maximum 20 MB). Savora verifies the file before completing the delivery.</small>
                    </label>
                    <p class="driver-field-error" data-delivery-proof-status aria-live="polite"></p>
                    <button class="driver-primary-action" type="button" data-delivery-primary-action disabled>Loading server status…</button>
                    <button class="driver-danger-action" type="button" data-report-issue aria-controls="driver-issue-dialog">Report an issue</button>
                </div>
            </div>
        </div>
    </section>
</main>

<section id="driver-issue-dialog" class="driver-dialog" role="dialog" aria-modal="true" aria-labelledby="driver-issue-title" hidden>
    <div class="driver-dialog-scrim" data-close-driver-dialog="driver-issue-dialog"></div>
    <form class="driver-dialog-card" data-driver-issue-form>
        <header>
            <div><p class="driver-eyebrow">Delivery support</p><h2 id="driver-issue-title">Report an issue</h2></div>
            <button class="driver-icon-button" type="button" aria-label="Close issue form" data-close-driver-dialog="driver-issue-dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </header>
        <label class="driver-field" for="driver-issue-reason">
            <span>What happened?</span>
            <select id="driver-issue-reason" name="issue-reason" required>
                <option value="">Choose a reason</option>
                <option>Restaurant delay</option>
                <option>Cannot reach customer</option>
                <option>Address problem</option>
                <option>Order damaged</option>
                <option>Other</option>
            </select>
        </label>
        <label class="driver-field" for="driver-issue-note">
            <span>Details (optional)</span>
            <textarea id="driver-issue-note" name="issue-note" maxlength="200" placeholder="Add useful context for support"></textarea>
        </label>
        <p class="driver-field-error" data-driver-issue-error aria-live="polite"></p>
        <div class="driver-dialog-actions">
            <button class="driver-secondary-action" type="button" data-close-driver-dialog="driver-issue-dialog">Cancel</button>
            <button class="driver-primary-action" type="submit">Send report</button>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/components/driver_footer.php'; ?>
