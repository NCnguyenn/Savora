<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="overview">
    <header class="driver-page-heading">
        <div>
            <p class="driver-eyebrow">Driver overview</p>
            <h1>Good afternoon, <span data-driver-first-name>Driver</span></h1>
            <p>Set your location, go online, and review each delivery before accepting.</p>
        </div>
        <button class="driver-availability" type="button" data-driver-availability aria-pressed="false">
            <span aria-hidden="true"></span><strong>Offline</strong><small>Not receiving offers</small>
        </button>
    </header>

    <section class="driver-overview-grid" aria-label="Driver location and delivery offers">
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

            <section class="driver-kpi-grid" data-driver-summary aria-label="Today's delivery summary">
                <article class="driver-card"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i><div><p>Today's deliveries</p><strong data-summary-deliveries>0</strong></div></article>
                <article class="driver-card"><i class="fa-solid fa-dollar-sign" aria-hidden="true"></i><div><p>Today's earnings</p><strong data-summary-earnings>$0.00</strong></div></article>
                <article class="driver-card"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i><div><p>Acceptance rate</p><strong data-summary-acceptance>100%</strong></div></article>
            </section>
        </div>

        <aside class="driver-card driver-offer-card" data-delivery-offer aria-live="polite">
            <div class="driver-offer-empty" data-offer-empty>
                <span><i class="fa-solid fa-satellite-dish" aria-hidden="true"></i></span>
                <h2>Go online to receive offers</h2>
                <p>Your next eligible delivery will appear here with restaurant, customer, route, and earnings details.</p>
            </div>
            <div data-offer-content hidden>
                <header class="driver-offer-header">
                    <div><p class="driver-eyebrow">Exclusive offer</p><h2>New delivery offer</h2></div>
                    <div class="driver-offer-countdown" aria-label="Offer time remaining">
                        <time data-offer-countdown datetime="PT30S">00:30</time>
                    </div>
                </header>
                <section class="driver-offer-party">
                    <span><i class="fa-solid fa-store" aria-hidden="true"></i></span>
                    <div><small>Pickup</small><h3 data-offer-restaurant>Restaurant</h3><p data-offer-pickup-address></p></div>
                </section>
                <section class="driver-offer-party">
                    <span><i class="fa-regular fa-user" aria-hidden="true"></i></span>
                    <div><small>Customer</small><h3 data-offer-customer>Customer</h3><p data-offer-dropoff-address></p></div>
                </section>
                <section class="driver-offer-items">
                    <h3>Order items</h3>
                    <ul data-offer-items></ul>
                </section>
                <div class="driver-offer-route">
                    <span class="driver-chip" data-offer-pickup-distance>0 km to pickup</span>
                    <span class="driver-chip" data-offer-distance>0 km trip</span>
                </div>
                <div class="driver-offer-payment">
                    <div><small>Estimated earnings</small><strong data-offer-earnings>$0.00</strong></div>
                    <span class="driver-chip" data-offer-payment>Payment</span>
                </div>
                <div class="driver-offer-actions">
                    <button class="driver-primary-action" type="button" data-accept-offer>Accept delivery</button>
                    <button class="driver-danger-action" type="button" data-decline-offer>Decline</button>
                </div>
            </div>
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
        <p class="driver-muted">This address helps Savora estimate which restaurants are closest to you.</p>
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
