<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="history">
    <header class="driver-page-heading">
        <div>
            <p class="driver-eyebrow">Delivery records</p>
            <h1>Delivery history</h1>
            <p>Review completed, cancelled, and failed deliveries.</p>
        </div>
        <button class="driver-secondary-action" type="button" data-history-export>
            <i class="fa-solid fa-download" aria-hidden="true"></i>Export
        </button>
    </header>

    <section class="driver-history-kpis" data-history-summary aria-label="Delivery history summary">
        <article class="driver-card"><span><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span><div><p>Completed</p><strong data-history-completed>0</strong></div></article>
        <article class="driver-card"><span class="is-coral"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i></span><div><p>Cancelled</p><strong data-history-cancelled>0</strong></div></article>
        <article class="driver-card"><span><i class="fa-solid fa-route" aria-hidden="true"></i></span><div><p>Total distance</p><strong data-history-distance>0 km</strong></div></article>
    </section>

    <section class="driver-card driver-history-panel">
        <form class="driver-history-filters" data-history-filters>
            <label class="driver-field" for="driver-history-search">
                <span>Search deliveries</span>
                <input id="driver-history-search" type="search" data-history-search placeholder="Search order or restaurant">
            </label>
            <label class="driver-field" for="driver-history-date">
                <span>Completed after</span>
                <input id="driver-history-date" type="date" data-history-date>
            </label>
            <label class="driver-field" for="driver-history-status">
                <span>Status</span>
                <select id="driver-history-status" data-history-status>
                    <option value="all">All statuses</option>
                    <option value="delivered">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="failed">Failed</option>
                </select>
            </label>
        </form>

        <p class="driver-result-count" data-history-count aria-live="polite">0 deliveries</p>
        <div class="driver-responsive-table">
            <table>
                <caption>Driver delivery records</caption>
                <thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Restaurant</th><th scope="col">Customer</th><th scope="col">Route</th><th scope="col">Status</th><th scope="col">Earnings</th><th scope="col">Action</th></tr></thead>
                <tbody data-history-results></tbody>
            </table>
        </div>
        <div class="driver-history-cards" data-history-cards></div>
        <div class="driver-empty-state is-compact" data-history-empty hidden>
            <span><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i></span>
            <h2>No delivery records found</h2>
            <p>Completed and cancelled deliveries will appear here.</p>
        </div>
    </section>
</main>

<aside id="driver-history-drawer" class="driver-history-drawer" data-history-drawer role="dialog" aria-modal="true" aria-labelledby="driver-history-detail-title" hidden>
    <div class="driver-dialog-scrim" data-history-close></div>
    <div class="driver-history-drawer-panel" role="document">
        <header>
            <div><p class="driver-eyebrow">Delivery record</p><h2 id="driver-history-detail-title">Delivery details</h2></div>
            <button class="driver-icon-button" type="button" data-history-close aria-label="Close delivery details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </header>
        <div data-history-detail></div>
    </div>
</aside>

<?php require_once __DIR__ . '/components/driver_footer.php'; ?>
