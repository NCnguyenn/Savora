<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-order-center data-order-source="api/orders.php">
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Order operations</p><h1>Live Order Center</h1><p>Review, prepare, and hand off every server order on time.</p></div>
        <a class="restaurant-primary-action" href="restaurant_order_history.php"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i>Order history</a>
    </header>
    <p class="restaurant-empty" data-order-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" data-live-order-counts aria-label="Live order status summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-file-lines restaurant-kpi-icon" aria-hidden="true"></i><div><p>New</p><h2 data-order-count="pending">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-circle-check restaurant-kpi-icon" aria-hidden="true"></i><div><p>Preparing</p><h2 data-order-count="confirmed">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-utensils restaurant-kpi-icon" aria-hidden="true"></i><div><p>Preparing</p><h2 data-order-count="preparing">0</h2></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-bag-shopping restaurant-kpi-icon" aria-hidden="true"></i><div><p>Ready</p><h2 data-order-count="ready_for_pickup">0</h2></div></article>
    </section>
    <section class="restaurant-overview-grid" aria-label="Live order queue and selected order">
        <article class="restaurant-card" aria-labelledby="live-order-queue-title">
            <header class="restaurant-card-header"><h2 id="live-order-queue-title">Order queue</h2><span data-live-order-total>0 orders</span></header>
            <div role="tablist" aria-label="Filter live orders">
                <button type="button" data-live-order-filter="all" role="tab" aria-selected="true">All</button>
                <button type="button" data-live-order-filter="pending" role="tab" aria-selected="false">New</button>
                <button type="button" data-live-order-filter="confirmed" role="tab" aria-selected="false">Preparing</button>
                <button type="button" data-live-order-filter="preparing" role="tab" aria-selected="false">Preparing</button>
                <button type="button" data-live-order-filter="ready_for_pickup" role="tab" aria-selected="false">Ready</button>
            </div>
            <label class="restaurant-field" for="live-order-search"><span>Search live orders</span><input id="live-order-search" type="search" data-live-order-search placeholder="Search order or customer"></label>
            <div data-live-order-list aria-live="polite"></div>
        </article>
        <aside class="restaurant-card" data-order-details aria-labelledby="live-order-details-title">
            <h2 id="live-order-details-title">Order details</h2>
            <p class="restaurant-empty">Select a live order to review its items and actions.</p>
            <label class="restaurant-field" for="prep-minutes"><span>Preparation time</span><select id="prep-minutes" name="prep-minutes" disabled><option>20 minutes</option></select></label>
            <div class="restaurant-actions" aria-label="Order actions"><button type="button" data-order-action="accept" disabled>Accept and start preparing</button><button type="button" data-order-action="ready" disabled>Food is ready</button><button type="button" data-order-action="reject" disabled>Reject order</button></div>
        </aside>
    </section>
</main>
<script defer src="js/restaurant_orders.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
