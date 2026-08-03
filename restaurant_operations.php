<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-store-operations-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Service availability</p><h1>Operations &amp; Opening Hours</h1><p>Set the availability and hours supported by the catalog service.</p></div>
        <a class="restaurant-primary-action" href="restaurant_profile.php"><i class="fa-solid fa-store" aria-hidden="true"></i>Store profile</a>
    </header>
    <p class="restaurant-form-summary" data-operations-feedback aria-live="polite" aria-atomic="true"></p>
    <div class="restaurant-menu-editor-layout">
        <form class="restaurant-form" data-store-operations-form novalidate>
            <section class="restaurant-card" data-store-status aria-labelledby="store-status-title"><h2 id="store-status-title">Store status</h2><div class="restaurant-form-three-column">
                <label class="restaurant-check-field"><input name="accepting-orders" type="checkbox" checked><span>Accepting orders</span></label>
            </div></section>
            <section class="restaurant-card" aria-labelledby="weekly-hours-title"><div class="restaurant-card-header"><h2 id="weekly-hours-title">Weekly opening hours</h2><button type="button" data-copy-hours>Copy Monday to all days</button></div><div data-weekly-hours></div></section>
            <section class="restaurant-card" aria-labelledby="special-hours-title"><div class="restaurant-card-header"><h2 id="special-hours-title">Special hours</h2><button type="button" data-add-special-hours>Add special date</button></div><div data-special-hours></div></section>
            <div class="restaurant-editor-actions"><button type="submit" class="restaurant-primary-action">Save operations</button></div>
        </form>
        <aside class="restaurant-card restaurant-customer-preview" aria-labelledby="operations-preview-title"><h2 id="operations-preview-title">Customer status preview</h2><div data-operations-preview aria-live="polite"></div><p class="restaurant-field-hint">Availability updates after the server refresh succeeds.</p></aside>
    </div>
</main>
<script src="js/api_client.js"></script>
<script defer src="js/restaurant_storefront.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
