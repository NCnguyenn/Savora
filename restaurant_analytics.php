<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-analytics-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Insights</p><h1>Business Analytics</h1><p>Understand local demand, menu performance, and kitchen efficiency.</p></div>
        <div class="restaurant-editor-actions"><label class="sr-only" for="analytics-range">Analytics range</label><select id="analytics-range" data-analytics-range><option value="30">Last 30 days</option><option value="7">Last 7 days</option></select><button type="button" class="restaurant-primary-action" data-export-analytics>Export report</button></div>
    </header>
    <p class="restaurant-form-summary" data-analytics-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" data-analytics-kpis aria-label="Analytics summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Net revenue</p><h2 data-analytics-revenue>$0.00</h2><small>Completed local orders</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-bag-shopping restaurant-kpi-icon" aria-hidden="true"></i><div><p>Orders</p><h2 data-analytics-orders>0</h2><small>In the selected period</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-receipt restaurant-kpi-icon" aria-hidden="true"></i><div><p>Average order value</p><h2 data-analytics-aov>$0.00</h2><small>Completed local orders</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-users restaurant-kpi-icon" aria-hidden="true"></i><div><p>Repeat customers</p><h2 data-analytics-repeat>0</h2><small>Known from local orders</small></div></article>
    </section>
    <div class="restaurant-finance-layout">
        <section class="restaurant-card restaurant-chart" data-sales-chart aria-labelledby="sales-chart-title">
            <header class="restaurant-card-header"><div><h2 id="sales-chart-title">Sales &amp; order volume</h2><p class="restaurant-field-hint">Completed local sales by day.</p></div></header>
            <div class="restaurant-chart-bars" data-sales-chart-bars role="img" aria-label="Sales and order volume chart"></div>
            <p class="restaurant-chart-labels" data-sales-chart-summary>No completed local orders in this range.</p>
        </section>
        <section class="restaurant-card" data-status-chart aria-labelledby="status-chart-title"><h2 id="status-chart-title">Order status</h2><p class="restaurant-field-hint">All local orders in this range.</p><dl class="restaurant-definition-list" data-status-chart-list></dl><p data-status-chart-summary>No local orders in this range.</p></section>
    </div>
    <div class="restaurant-overview-grid">
        <section class="restaurant-card" data-ordering-heatmap aria-labelledby="ordering-times-title"><h2 id="ordering-times-title">Popular ordering times</h2><p class="restaurant-field-hint" data-ordering-heatmap-summary>No local ordering-time data in this range.</p><div data-ordering-heatmap-grid aria-label="Ordering time distribution"></div></section>
        <section class="restaurant-card" data-menu-performance aria-labelledby="menu-performance-title"><h2 id="menu-performance-title">Top menu performance</h2><p class="restaurant-field-hint">Completed local orders only.</p><ul class="restaurant-insight-items" data-menu-performance-list></ul></section>
        <section class="restaurant-card" data-kitchen-performance aria-labelledby="kitchen-performance-title"><h2 id="kitchen-performance-title">Kitchen performance</h2><p class="restaurant-finance-amount" data-kitchen-prep>0 min</p><p class="restaurant-field-hint" data-kitchen-performance-summary>No local prep-time data in this range.</p></section>
    </div>
    <section class="restaurant-card restaurant-low-stock" data-analytics-insight aria-labelledby="analytics-insight-title"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i><div><h2 id="analytics-insight-title">Local insight</h2><p data-analytics-insight-copy>Complete local orders to surface a practical insight.</p></div></section>
</main>
<script defer src="js/restaurant_insights.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
