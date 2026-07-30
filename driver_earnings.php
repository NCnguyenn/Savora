<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="earnings">
    <header class="driver-page-heading">
        <div>
            <p class="driver-eyebrow">Income overview</p>
            <h1>Earnings</h1>
            <p>Track your delivery income and cash balance.</p>
        </div>
        <div class="driver-heading-actions">
            <label class="driver-field driver-date-filter" for="driver-earnings-week">
                <span class="driver-sr-only">Earnings week</span>
                <input id="driver-earnings-week" type="week" data-earnings-week>
            </label>
            <button class="driver-secondary-action" type="button" data-download-statement>
                <i class="fa-solid fa-download" aria-hidden="true"></i>Download statement
            </button>
        </div>
    </header>

    <p class="driver-local-preview">Payouts and statements on this page are a local preview; no bank transfer or server-generated document is created.</p>

    <section class="driver-earnings-kpis" data-earnings-kpis aria-label="Earnings summary">
        <article class="driver-card"><span><i class="fa-solid fa-circle-dollar-to-slot" aria-hidden="true"></i></span><div><p>This week</p><strong data-earnings-total>$0.00</strong><small data-earnings-change>No previous week comparison</small></div></article>
        <article class="driver-card"><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i></span><div><p>Deliveries</p><strong data-earnings-deliveries>0</strong></div></article>
        <article class="driver-card"><span><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span><div><p>Average per delivery</p><strong data-earnings-average>$0.00</strong></div></article>
        <article class="driver-card"><span><i class="fa-solid fa-gift" aria-hidden="true"></i></span><div><p>Bonuses</p><strong data-earnings-bonuses>$0.00</strong></div></article>
    </section>

    <section class="driver-earnings-layout">
        <article class="driver-card driver-earnings-chart-card">
            <header class="driver-card-header"><div><p class="driver-eyebrow">Seven days</p><h2>Weekly earnings</h2></div><div class="driver-chart-legend"><span>Delivery fees</span><span>Bonuses</span></div></header>
            <div class="driver-earnings-chart" data-earnings-chart role="img" aria-label="Weekly driver earnings chart"></div>
        </article>
        <div class="driver-earnings-side">
            <article class="driver-card driver-payout-card" data-next-payout>
                <p>Next payout</p>
                <strong data-payout-amount>$0.00</strong>
                <small data-payout-date>Scheduled date unavailable</small>
                <div><i class="fa-solid fa-building-columns" aria-hidden="true"></i><span>Bank account &bull;&bull;&bull;&bull; 4821</span></div>
                <button class="driver-primary-action" type="button" data-view-payout>View payout details</button>
            </article>
            <article class="driver-card driver-cash-card" data-cash-balance>
                <h2>Cash balance</h2>
                <div><span><small>COD collected</small><strong data-cod-collected>$0.00</strong></span><span><small>Amount to settle</small><strong data-cod-settle>$0.00</strong></span></div>
                <p data-cod-message>No cash settlement is due.</p>
                <button class="driver-secondary-action" type="button" data-cod-instructions>View instructions</button>
            </article>
        </div>
    </section>

    <section class="driver-card driver-recent-earnings">
        <header class="driver-card-header"><h2>Recent earnings</h2><a href="driver_history.php">View delivery history</a></header>
        <div class="driver-responsive-table">
            <table>
                <caption>Recent driver earnings</caption>
                <thead><tr><th scope="col">Order</th><th scope="col">Completed</th><th scope="col">Base fee</th><th scope="col">Distance</th><th scope="col">Bonus</th><th scope="col">Total</th></tr></thead>
                <tbody data-earnings-records></tbody>
            </table>
        </div>
        <div class="driver-empty-state is-compact" data-earnings-empty hidden>
            <span><i class="fa-solid fa-wallet" aria-hidden="true"></i></span><h2>No earnings yet</h2><p>Completed deliveries will appear here.</p>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/components/driver_footer.php'; ?>
