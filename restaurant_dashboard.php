<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main">
    <header class="restaurant-page-heading">
        <div><h1>Restaurant Overview</h1><p id="restaurant-overview-date">Today’s local restaurant activity</p></div>
        <a class="restaurant-primary-action" href="restaurant_orders.php"><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i>View live orders</a>
    </header>
    <section class="restaurant-kpi-grid" data-overview-kpis aria-label="Restaurant performance summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Today’s revenue</p><h2 data-kpi="revenue">$0.00</h2><span class="restaurant-positive">Derived from completed local orders</span></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-bag-shopping restaurant-kpi-icon" aria-hidden="true"></i><div><p>Orders</p><h2 data-kpi="orders">0</h2><span class="restaurant-positive">Local demo order activity</span></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-clock restaurant-kpi-icon" aria-hidden="true"></i><div><p>Avg. prep time</p><h2 data-kpi="prep">20 min</h2><span class="restaurant-positive">Current operations setting</span></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-circle-check restaurant-kpi-icon" aria-hidden="true"></i><div><p>Acceptance rate</p><h2 data-kpi="acceptance">—</h2><span class="restaurant-positive">Confirmed from local orders</span></div></article>
    </section>
    <section class="restaurant-overview-grid" aria-label="Restaurant operations">
        <article class="restaurant-card restaurant-chart" data-overview-chart aria-labelledby="revenue-orders-title"><header class="restaurant-card-header"><h2 id="revenue-orders-title">Revenue &amp; orders</h2><a href="restaurant_analytics.php">View analytics</a></header><p class="restaurant-empty" data-chart-summary>Revenue and order activity will appear as local orders are completed.</p><div class="restaurant-chart-bars" aria-hidden="true" data-chart-bars></div><div class="restaurant-chart-labels" aria-hidden="true"><span>12 AM</span><span>6 AM</span><span>12 PM</span><span>6 PM</span><span>Now</span></div></article>
        <div class="restaurant-side-stack">
            <article class="restaurant-card" data-live-queue aria-labelledby="live-queue-title"><header class="restaurant-card-header"><h2 id="live-queue-title">Live order queue</h2><a href="restaurant_orders.php">View all orders</a></header><ul class="restaurant-queue-list" data-queue-list></ul></article>
            <article class="restaurant-card" data-top-items aria-labelledby="top-items-title"><header class="restaurant-card-header"><h2 id="top-items-title">Top menu items</h2><a href="restaurant_analytics.php">View full report</a></header><ol class="restaurant-top-items" data-top-items-list></ol></article>
        </div>
    </section>
    <section class="restaurant-card restaurant-low-stock" data-low-stock aria-labelledby="low-stock-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><div><h2 id="low-stock-title" data-low-stock-title>Menu availability</h2><p data-low-stock-copy>Availability is derived from this device’s Restaurant state.</p></div><a class="restaurant-primary-action" href="restaurant_menu.php">Review menu</a></section>
</main>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const ui = window.SavoraRestaurantUI;
    const restaurant = window.SavoraRestaurantState;
    const customer = window.SavoraState;
    if (!ui || !restaurant || !customer) return;
    const restaurantState = SavoraRestaurantState.load();
    const customerState = SavoraState.load();
    const profile = restaurantState.profile || {};
    const operations = restaurantState.operations || {};
    const orders = (customerState.orders || []).filter(order => order && order.restaurantId === profile.id);
    const completed = orders.filter(order => order.status === 'completed');
    const revenue = completed.reduce((sum, order) => sum + Number(order.total || 0), 0);
    const accepted = orders.filter(order => ['confirmed', 'preparing', 'ready_for_pickup', 'on_the_way', 'completed'].includes(order.status)).length;
    const set = (selector, value) => { const node = document.querySelector(selector); if (node) node.textContent = value; };
    set('[data-kpi="revenue"]', ui.formatMoney(revenue));
    set('[data-kpi="orders"]', String(orders.length));
    set('[data-kpi="prep"]', `${Number(operations.prepMinutes || 20)} min`);
    set('[data-kpi="acceptance"]', orders.length ? `${Math.round((accepted / orders.length) * 100)}%` : '—');
    set('#restaurant-overview-date', new Intl.DateTimeFormat('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }).format(new Date()));
    document.querySelectorAll('[data-restaurant-name]').forEach(node => { node.textContent = profile.name || 'Savora Kitchen'; });

    const queue = document.querySelector('[data-queue-list]');
    queue.replaceChildren();
    const active = orders.filter(order => !['completed', 'cancelled'].includes(order.status)).slice(0, 3);
    if (!active.length) queue.append(SavoraRestaurantUI.el('li', { className: 'restaurant-empty' }, 'No live orders yet.'));
    active.forEach(order => queue.append(SavoraRestaurantUI.el('li', {}, [ui.statusChip(order.status), SavoraRestaurantUI.el('div', {}, [SavoraRestaurantUI.el('strong', {}, order.id), SavoraRestaurantUI.el('div', { className: 'restaurant-queue-meta' }, `${(order.items || []).length} item${(order.items || []).length === 1 ? '' : 's'} · ${order.address ? 'Delivery' : 'Pickup'}`)]), SavoraRestaurantUI.el('a', { href: 'restaurant_orders.php', 'aria-label': `Open ${order.id}` }, '›')]));

    const counts = new Map();
    orders.forEach(order => (order.items || []).forEach(item => { const name = String(item.name || 'Menu item'); counts.set(name, (counts.get(name) || 0) + Number(item.quantity || 1)); }));
    const topItems = document.querySelector('[data-top-items-list]');
    topItems.replaceChildren();
    const itemEntries = [...counts.entries()].sort((a, b) => b[1] - a[1]).slice(0, 5);
    if (!itemEntries.length) topItems.append(SavoraRestaurantUI.el('li', { className: 'restaurant-empty' }, 'No item sales yet.'));
    itemEntries.forEach(([name, quantity]) => topItems.append(SavoraRestaurantUI.el('li', {}, [SavoraRestaurantUI.el('span', { className: 'restaurant-top-item-image', 'aria-hidden': 'true' }, '🍽'), SavoraRestaurantUI.el('span', {}, name), SavoraRestaurantUI.el('span', {}, `${quantity} sold`)])));

    const bars = document.querySelector('[data-chart-bars]');
    bars.replaceChildren();
    const hourly = Array.from({ length: 12 }, (_, index) => completed.filter(order => new Date(order.createdAt).getHours() % 12 === index).reduce((sum, order) => sum + Number(order.total || 0), 0));
    const maximum = Math.max(1, ...hourly);
    hourly.forEach((value, index) => bars.append(SavoraRestaurantUI.el('span', { className: 'restaurant-chart-bar', style: `height:${Math.max(5, Math.round((value / maximum) * 100))}%`, title: `${index}:00 ${ui.formatMoney(value)}` })));
    set('[data-chart-summary]', `${ui.formatMoney(revenue)} in completed revenue across ${completed.length} completed local order${completed.length === 1 ? '' : 's'}.`);

    const unavailable = (restaurantState.menuItems || []).filter(item => item.available === false).map(item => item.name || 'Unnamed menu item');
    if (unavailable.length) { set('[data-low-stock-title]', `${unavailable.length} menu item${unavailable.length === 1 ? ' is' : 's are'} unavailable`); set('[data-low-stock-copy]', `${unavailable.join(' and ')} ${unavailable.length === 1 ? 'is' : 'are'} currently unavailable in local Restaurant state.`); }
    else { set('[data-low-stock-title]', 'Menu availability looks good'); set('[data-low-stock-copy]', 'All tracked menu items are available in local Restaurant state.'); }
});
</script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
