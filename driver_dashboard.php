<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="overview" data-order-source="api/orders.php">
    <header class="driver-page-heading">
        <div>
            <p class="driver-eyebrow">Driver overview</p>
            <h1>Good afternoon, <span data-driver-first-name>Driver</span></h1>
            <p>Availability, offers, location, and delivery assignments are read from Savora's server model.</p>
        </div>
        <div class="driver-card" data-driver-dispatch-status role="status">Checking server dispatch status…</div>
    </header>

    <section class="driver-overview-grid" aria-label="Driver location and assigned deliveries">
        <div class="driver-overview-main">
            <article class="driver-card driver-location-card" data-driver-location>
                <div>
                    <span class="driver-card-icon"><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span>
                    <div>
                        <p>Your current location</p>
                        <h2 data-driver-location-address>Location unavailable</h2>
                    </div>
                </div>
                <div class="driver-location-actions">
                    <button class="driver-coral-action" type="button" data-use-driver-gps>
                        <i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use GPS
                    </button>
                    <button class="driver-secondary-action" type="button" data-enter-driver-address aria-controls="driver-address-dialog">
                        <i class="fa-solid fa-pen" aria-hidden="true"></i>Enter manually
                    </button>
                </div>
            </article>

            <div class="driver-map driver-overview-map" data-driver-map role="img" aria-label="Map showing the driver's current service area">
                <span class="driver-map-road is-one" aria-hidden="true"></span>
                <span class="driver-map-road is-two" aria-hidden="true"></span>
                <span class="driver-map-road is-three" aria-hidden="true"></span>
                <span class="driver-map-river" aria-hidden="true"></span>
                <span class="driver-map-radius" aria-hidden="true"></span>
                <span class="driver-map-pin is-driver" aria-hidden="true"><i class="fa-solid fa-motorcycle"></i></span>
                <span class="driver-map-label">Current service area</span>
            </div>

            <section class="driver-kpi-grid" data-driver-summary aria-label="Server delivery summary">
                <article class="driver-card"><i class="fa-solid fa-route" aria-hidden="true"></i><div><p>Assigned deliveries</p><strong data-summary-deliveries>0</strong></div></article>
                <article class="driver-card"><i class="fa-solid fa-dollar-sign" aria-hidden="true"></i><div><p>Assigned earnings</p><strong data-summary-earnings>$0.00</strong></div></article>
                <article class="driver-card"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i><div><p>Source of truth</p><strong data-summary-source>Server</strong></div></article>
            </section>
        </div>

        <aside class="driver-card driver-offer-card" data-delivery-offer aria-live="polite">
            <span><i class="fa-solid fa-server" aria-hidden="true"></i></span>
            <h2>Server delivery offers</h2>
            <p>Offers and responses are processed by the server. This page never creates or accepts a delivery locally.</p>
            <ol class="driver-server-order-list" data-server-order-list></ol>
        </aside>
    </section>
</main>

<section id="driver-address-dialog" class="driver-dialog" role="dialog" aria-modal="true" aria-labelledby="driver-address-title" hidden>
    <div class="driver-dialog-scrim" data-close-driver-dialog="driver-address-dialog"></div>
    <form class="driver-dialog-card" data-driver-address-form novalidate>
        <header>
            <div><p class="driver-eyebrow">Current location</p><h2 id="driver-address-title">Enter your address</h2></div>
            <button class="driver-icon-button" type="button" aria-label="Close address form" data-close-driver-dialog="driver-address-dialog">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </header>
        <p class="driver-muted">This preference is stored locally and does not change delivery ownership.</p>
        <label class="driver-field" for="driver-manual-address">
            <span>Current address</span>
            <input id="driver-manual-address" name="driver-address" type="text" autocomplete="street-address" required placeholder="Enter street, district, or landmark">
        </label>
        <p class="driver-field-error" data-driver-address-error aria-live="polite"></p>
        <div class="driver-dialog-actions">
            <button class="driver-secondary-action" type="button" data-close-driver-dialog="driver-address-dialog">Cancel</button>
            <button class="driver-primary-action" type="submit">Save address</button>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/components/driver_footer.php'; ?>
