<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-finance-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Finance</p><h1>Revenue &amp; Payouts</h1><p>Review local completed-order revenue and transparent demo estimates.</p></div>
        <div class="restaurant-editor-actions"><a href="restaurant_invoices.php">Invoices &amp; statements</a><button type="button" class="restaurant-primary-action" data-request-payout>Request payout</button></div>
    </header>
    <p class="restaurant-form-summary" data-finance-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" data-finance-kpis aria-label="Revenue summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Gross sales</p><h2 data-finance-gross-sales>$0.00</h2><small>Completed local orders</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-wallet restaurant-kpi-icon" aria-hidden="true"></i><div><p>Net revenue</p><h2 data-finance-net-revenue>$0.00</h2><small>After the demo fee estimate</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-percent restaurant-kpi-icon" aria-hidden="true"></i><div><p>Demo platform fees</p><h2 data-finance-fees>$0.00</h2><small>10% of completed orders</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-rotate-left restaurant-kpi-icon" aria-hidden="true"></i><div><p>Refunds</p><h2 data-finance-refunds>$0.00</h2><small>Refunded local orders</small></div></article>
    </section>
    <div class="restaurant-finance-layout">
        <section class="restaurant-card restaurant-chart" data-finance-chart aria-labelledby="finance-chart-title">
            <header class="restaurant-card-header"><div><h2 id="finance-chart-title">Revenue breakdown</h2><p class="restaurant-field-hint">Sales, demo fees, and refunds from this device’s customer orders.</p></div></header>
            <div class="restaurant-chart-bars" data-finance-chart-bars role="img" aria-label="Revenue chart showing local sales, estimated fees, and refunds by order date"></div>
            <p class="restaurant-chart-labels" data-finance-chart-summary>There are no completed or refunded local orders yet.</p>
        </section>
        <aside class="restaurant-side-stack">
            <section class="restaurant-card" data-payout-preview aria-labelledby="next-payout-title"><h2 id="next-payout-title">Next payout preview</h2><p class="restaurant-field-hint">Illustrative local-demo preview. No money will move.</p><p class="restaurant-finance-amount" data-next-payout>$0.00</p><dl class="restaurant-definition-list"><div><dt>Orders included</dt><dd data-payout-order-count>0</dd></div><div><dt>Status</dt><dd>Local preview</dd></div></dl><button type="button" data-request-payout>Request local preview</button></section>
            <section class="restaurant-card" data-payout-account aria-labelledby="payout-account-title"><h2 id="payout-account-title">Payout account</h2><p><strong>Local demo account</strong><br><span class="restaurant-field-hint">No bank account is connected.</span></p><button type="button" data-manage-payout-account>Manage local demo account</button></section>
        </aside>
    </div>
    <section class="restaurant-card" aria-labelledby="transactions-title">
        <header class="restaurant-card-header"><div><h2 id="transactions-title">Transactions</h2><p class="restaurant-field-hint">Derived from completed and refunded local orders only.</p></div></header>
        <form class="restaurant-finance-filters" data-transaction-filters>
            <div class="restaurant-field"><label for="finance-date-range">From date</label><input id="finance-date-range" name="finance-date-range" type="date"></div>
            <div class="restaurant-field"><label for="finance-transaction-search">Search transactions</label><input id="finance-transaction-search" name="finance-transaction-search" type="search" placeholder="Order reference"></div>
            <div class="restaurant-field"><label for="finance-transaction-type">Transaction type</label><select id="finance-transaction-type" name="finance-transaction-type"><option value="all">All transactions</option><option value="sale">Sales</option><option value="refund">Refunds</option></select></div>
        </form>
        <div class="restaurant-table-wrap"><table class="restaurant-table" data-finance-transactions><caption>Local demo transactions</caption><thead><tr><th scope="col">Date</th><th scope="col">Order</th><th scope="col">Type</th><th scope="col">Amount</th><th scope="col">Demo fee</th><th scope="col">Net</th><th scope="col">Status</th></tr></thead><tbody data-finance-transaction-body></tbody></table></div>
        <div class="restaurant-finance-cards" data-finance-transaction-cards aria-live="polite"></div>
    </section>
</main>
<script defer src="js/restaurant_finance.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
