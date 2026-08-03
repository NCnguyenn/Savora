<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-store-profile-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Customer-facing profile</p><h1>Store Profile</h1><p>Update the restaurant details supported by the catalog service.</p></div>
        <a class="restaurant-primary-action" href="restaurant_operations.php"><i class="fa-regular fa-clock" aria-hidden="true"></i>Opening hours</a>
    </header>
    <p class="restaurant-form-summary" data-profile-feedback aria-live="polite" aria-atomic="true"></p>
    <div class="restaurant-menu-editor-layout">
        <form class="restaurant-form" data-store-profile-form novalidate>
            <section class="restaurant-card" aria-labelledby="brand-details-title"><h2 id="brand-details-title">Brand &amp; details</h2><div class="restaurant-form-two-column">
                <label class="restaurant-field"><span>Restaurant name</span><input name="profile-name" maxlength="100" required></label>
                <label class="restaurant-field"><span>Cuisine</span><input name="profile-cuisine" maxlength="100" placeholder="Italian, burgers, healthy"></label>
                <label class="restaurant-field"><span>Phone number</span><input name="profile-phone" maxlength="100" inputmode="tel"></label>
            </div></section>
            <section class="restaurant-card" aria-labelledby="restaurant-address-title"><h2 id="restaurant-address-title">Restaurant address</h2>
                <p class="restaurant-field-hint">Choose a current location only when you want to share it. You can always enter the address manually.</p>
                <div class="restaurant-editor-actions"><button type="button" data-use-current-location><i class="fa-solid fa-location-arrow" aria-hidden="true"></i>Use current location</button><button type="button" data-manual-address><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Enter address manually</button></div>
                <p class="restaurant-form-summary" data-address-feedback aria-live="polite" aria-atomic="true"></p>
                <div class="restaurant-form-two-column">
                    <label class="restaurant-field"><span>Address line 1</span><input name="address-line1" maxlength="150" autocomplete="address-line1"></label>
                    <label class="restaurant-field"><span>City</span><input name="address-city" maxlength="100" autocomplete="address-level2"></label>
                </div>
                <div id="restaurant-location-map" class="restaurant-card" aria-label="Saved restaurant location map"></div>
                <p class="restaurant-field-hint" data-map-fallback>Map preview is available without map tiles; your saved coordinates and manual address remain available.</p>
            </section>
            <div class="restaurant-editor-actions"><button type="submit" class="restaurant-primary-action">Save profile</button></div>
        </form>
        <aside class="restaurant-card restaurant-customer-preview" aria-labelledby="storefront-preview-title"><h2 id="storefront-preview-title">Customer preview</h2><div data-storefront-preview aria-live="polite"></div><p class="restaurant-field-hint">Preview reflects the server-backed catalog fields.</p></aside>
    </div>
</main>
<script src="js/api_client.js"></script>
<script defer src="assets/vendor/leaflet/leaflet.js"></script>
<script defer src="js/restaurant_storefront.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
