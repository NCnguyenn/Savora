<?php
$customer_page_styles = ['css/customer_restaurant.css'];
$customer_page_scripts = ['js/customer_restaurant.js'];
include 'components/customer_header.php';
?>

<main class="restaurant-page">
    <div class="container restaurant-page-container">
        <nav class="detail-breadcrumbs" aria-label="Breadcrumb">
            <a href="customer_dashboard.php">Discover</a><span aria-hidden="true">/</span><span>Restaurant</span>
        </nav>

        <p id="restaurant-error" class="form-help restaurant-error" role="alert" hidden></p>
        <section class="restaurant-hero-card" aria-labelledby="storefront-name">
            <div class="restaurant-hero-media"><img id="restaurant-hero-image" src="assets/images/food-placeholder.svg" alt="" data-critical-background></div>
            <div class="restaurant-hero-content">
                <div class="restaurant-brand-row">
                    <img id="restaurant-logo" class="restaurant-logo" src="assets/images/brands/restaurant-placeholder.svg" alt="Restaurant logo">
                    <div><p id="restaurant-cuisine" class="eyebrow">Local kitchen</p><h1 id="storefront-name">Loading restaurant</h1></div>
                </div>
                <p id="storefront-slogan" class="restaurant-slogan">Thoughtful food, served simply.</p>
                <p id="restaurant-description" class="restaurant-description"></p>
                <div class="restaurant-contact-row">
                    <span id="storefront-address"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Address unavailable</span>
                    <a id="restaurant-phone" href="#"><i class="fa-solid fa-phone" aria-hidden="true"></i><span>Phone unavailable</span></a>
                </div>
                <div class="restaurant-facts" aria-label="Restaurant information">
                    <div><strong id="restaurant-rating">—</strong><span>Rating</span></div>
                    <div><strong id="restaurant-status">Hours unavailable</strong><span>Today</span></div>
                    <div><strong id="restaurant-item-count">0</strong><span>Menu items</span></div>
                </div>
                <button id="restaurant-favorite-button" class="secondary-action restaurant-favorite-button" type="button" aria-pressed="false"><i class="fa-regular fa-heart" aria-hidden="true"></i><span>Save restaurant</span></button>
            </div>
        </section>

        <section id="storefront-offers" class="restaurant-offers" aria-labelledby="restaurant-offers-title" hidden>
            <div><p class="eyebrow">Available today</p><h2 id="restaurant-offers-title">Offers from this restaurant</h2></div>
            <div id="restaurant-promotions-list" class="restaurant-promotions-list"></div>
        </section>

        <div class="restaurant-content-layout">
            <div class="restaurant-menu-column">
                <section class="restaurant-menu-section" aria-labelledby="restaurant-menu-title">
                    <div class="section-heading-row restaurant-menu-heading">
                        <div><p class="eyebrow">Made to order</p><h2 id="restaurant-menu-title">Full menu</h2></div>
                        <span id="restaurant-menu-count" class="result-count" aria-live="polite"></span>
                    </div>
                    <div class="restaurant-menu-toolbar">
                        <label class="search-field restaurant-menu-search" for="restaurant-menu-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><span class="sr-only">Search this menu</span><input id="restaurant-menu-search" type="search" placeholder="Search this menu" autocomplete="off"></label>
                        <div id="restaurant-menu-filters" class="restaurant-menu-filters" aria-label="Filter this menu"></div>
                    </div>
                    <div id="restaurant-food-section" class="restaurant-item-section" aria-labelledby="restaurant-food-title">
                        <div class="section-heading-row"><h3 id="restaurant-food-title">Food</h3><span id="restaurant-food-count" class="result-count" aria-live="polite"></span></div>
                        <div id="storefront-food-grid" class="restaurant-menu-grid"></div>
                    </div>
                    <div id="restaurant-drinks-section" class="restaurant-item-section" aria-labelledby="restaurant-drinks-title">
                        <div class="section-heading-row"><h3 id="restaurant-drinks-title">Drinks</h3><span id="restaurant-drinks-count" class="result-count" aria-live="polite"></span></div>
                        <div id="storefront-drink-grid" class="restaurant-menu-grid"></div>
                    </div>
                </section>
            </div>

            <aside class="restaurant-sidebar" aria-label="Restaurant details">
                <section class="surface-card restaurant-hours-card" aria-labelledby="restaurant-hours-title">
                    <div class="section-heading-row"><h2 id="restaurant-hours-title">Opening hours</h2><i class="fa-regular fa-clock" aria-hidden="true"></i></div>
                    <div id="storefront-hours-list" class="restaurant-hours-list"></div>
                </section>
                <section id="storefront-active-order" class="surface-card storefront-active-order" aria-labelledby="storefront-active-order-title" hidden>
                    <h2 id="storefront-active-order-title">Active order</h2>
                    <p id="storefront-active-order-copy"></p>
                    <a class="primary-action" href="customer_history.php">Track order</a>
                </section>
            </aside>
        </div>
    </div>
</main>

<?php include 'components/customer_footer.php'; ?>
