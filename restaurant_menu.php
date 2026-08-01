<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-menu-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Menu &amp; availability</p><h1>Menu Management</h1><p>Keep your customer-facing menu accurate and available.</p></div>
        <a class="restaurant-primary-action" href="restaurant_menu_item.php"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add menu item</a>
    </header>
    <section class="restaurant-card restaurant-menu-alert" aria-labelledby="menu-availability-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><div><h2 id="menu-availability-title">Customer catalog availability</h2><p>Availability updates appear in the Customer catalog on this device.</p></div></section>
    <section class="restaurant-menu-controls" aria-label="Menu filters">
        <label class="restaurant-field"><span>Search menu items</span><input type="search" data-menu-search placeholder="Search menu items"></label>
        <label class="restaurant-field"><span>Category</span><select data-menu-category><option value="all">All categories</option><option value="burgers">Burgers</option><option value="pizza">Pizza</option><option value="pasta">Pasta</option><option value="drinks">Drinks</option><option value="sides">Sides</option><option value="lunch">Lunch</option></select></label>
        <label class="restaurant-field"><span>Availability</span><select data-menu-availability><option value="all">All availability</option><option value="available">Available</option><option value="unavailable">Unavailable</option></select></label>
        <label class="restaurant-field"><span>Sort menu items</span><select data-menu-sort><option value="name">Sort: Name</option><option value="price">Sort: Price</option></select></label>
        <div class="restaurant-menu-view-controls" aria-label="Menu view"><button type="button" data-menu-view="grid" aria-pressed="true"><i class="fa-solid fa-table-cells" aria-hidden="true"></i><span class="sr-only">Grid view</span></button><button type="button" data-menu-view="list" aria-pressed="false"><i class="fa-solid fa-list" aria-hidden="true"></i><span class="sr-only">List view</span></button></div>
    </section>
    <p class="restaurant-empty" data-menu-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-menu-grid" data-menu-list data-view="grid" aria-label="Menu items"></section>
</main>
<script src="js/api_client.js"></script>
<script defer src="js/restaurant_menu.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
