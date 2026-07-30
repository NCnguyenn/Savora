<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-order-history>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Order records</p><h1>Order History</h1><p>Search completed, cancelled, and refunded local records.</p></div>
        <a class="restaurant-primary-action" href="restaurant_orders.php"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>Live orders</a>
    </header>
    <p class="restaurant-empty" data-history-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" data-history-summary aria-label="Order history summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-circle-check restaurant-kpi-icon" aria-hidden="true"></i><div><p>Completed</p><h2 data-history-count="completed">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-circle-xmark restaurant-kpi-icon" aria-hidden="true"></i><div><p>Cancelled</p><h2 data-history-count="cancelled">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-rotate-left restaurant-kpi-icon" aria-hidden="true"></i><div><p>Refunded</p><h2 data-history-count="refunded">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Completed sales</p><h2 data-history-sales>$0.00</h2></div></article>
    </section>
    <section class="restaurant-card" aria-labelledby="history-records-title">
        <header class="restaurant-card-header"><h2 id="history-records-title">Order records</h2><span>Local demo data</span></header>
        <form class="restaurant-form" data-history-filters>
            <div class="restaurant-field"><label for="history-date">From date</label><input id="history-date" name="history-date" type="date"></div>
            <div class="restaurant-field"><label for="history-search">Search by order or customer</label><input id="history-search" name="history-search" type="search"></div>
            <div class="restaurant-field"><label for="history-status">Status</label><select id="history-status" name="history-status"><option value="all">All statuses</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option><option value="refunded">Refunded</option></select></div>
            <div class="restaurant-field"><label for="history-fulfillment">Fulfillment</label><select id="history-fulfillment" name="history-fulfillment"><option value="all">All fulfillment</option><option value="delivery">Delivery</option><option value="pickup">Pickup</option></select></div>
        </form>
        <div class="restaurant-table-wrap"><table class="restaurant-table" data-history-table><caption class="sr-only">Restaurant order history</caption><thead><tr><th scope="col">Order</th><th scope="col">Date</th><th scope="col">Customer</th><th scope="col">Fulfillment</th><th scope="col">Items</th><th scope="col">Total</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody data-history-table-body></tbody></table></div>
        <div data-history-cards aria-live="polite"></div>
        <nav data-history-pagination aria-label="Order history pagination"><button type="button" disabled aria-label="Previous page">Previous</button><span data-history-result-count>0 records</span><button type="button" disabled aria-label="Next page">Next</button></nav>
    </section>
    <aside class="restaurant-card" data-history-details aria-labelledby="history-details-title" hidden>
        <header class="restaurant-card-header"><h2 id="history-details-title">Order details</h2><button type="button" data-close-history-details aria-label="Close order details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
        <div data-history-detail-content></div>
        <ol data-status-timeline aria-label="Order status timeline"></ol>
        <a class="restaurant-primary-action" data-history-invoice href="customer_history.php?order=">View customer order</a>
        <a class="restaurant-primary-action" data-history-reorder href="restaurant_orders.php">Reorder details</a>
    </aside>
</main>
<script defer src="js/restaurant_orders.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
