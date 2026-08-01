<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-store-profile-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Customer-facing profile</p><h1>Store Profile</h1><p>Control how your restaurant appears to customers on this device.</p></div>
        <a class="restaurant-primary-action" href="restaurant_operations.php"><i class="fa-regular fa-clock" aria-hidden="true"></i>Opening hours</a>
    </header>
    <p class="restaurant-form-summary" data-profile-feedback aria-live="polite" aria-atomic="true"></p>
    <div class="restaurant-menu-editor-layout">
        <form class="restaurant-form" data-store-profile-form novalidate>
            <section class="restaurant-card" aria-labelledby="brand-details-title"><h2 id="brand-details-title">Brand &amp; details</h2><div class="restaurant-form-two-column">
                <label class="restaurant-field"><span>Restaurant name</span><input name="profile-name" maxlength="100" required></label>
                <label class="restaurant-field"><span>Cuisine</span><input name="profile-cuisine" maxlength="100" placeholder="Italian, burgers, healthy"></label>
                <label class="restaurant-field restaurant-field-wide"><span>Short description</span><textarea name="profile-description" maxlength="500" placeholder="Describe your restaurant for customers."></textarea></label>
                <label class="restaurant-field"><span>Phone number</span><input name="profile-phone" maxlength="100" inputmode="tel"></label>
                <label class="restaurant-field"><span>Local cover image</span><select name="profile-image"><option value="">Use the local placeholder</option><option value="assets/images/catalog/mega-burger-feast-combo.jpg">Savora kitchen</option><option value="assets/images/catalog/supreme-pepperoni-pizza.jpg">Restaurant interior</option></select></label>
            </div></section>
            <section class="restaurant-card" aria-labelledby="restaurant-address-title"><h2 id="restaurant-address-title">Restaurant address</h2>
                <p class="restaurant-field-hint">Choose a current location only when you want to share it. You can always enter the address manually.</p>
                <div class="restaurant-editor-actions"><button type="button" data-use-current-location><i class="fa-solid fa-location-arrow" aria-hidden="true"></i>Use current location</button><button type="button" data-manual-address><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Enter address manually</button></div>
                <p class="restaurant-form-summary" data-address-feedback aria-live="polite" aria-atomic="true"></p>
                <div class="restaurant-form-two-column">
                    <label class="restaurant-field"><span>Address line 1</span><input name="address-line1" maxlength="150" autocomplete="address-line1"></label>
                    <label class="restaurant-field"><span>Address line 2 (optional)</span><input name="address-line2" maxlength="150" autocomplete="address-line2"></label>
                    <label class="restaurant-field"><span>City</span><input name="address-city" maxlength="100" autocomplete="address-level2"></label>
                    <label class="restaurant-field"><span>State or province</span><input name="address-state" maxlength="100" autocomplete="address-level1"></label>
                    <label class="restaurant-field"><span>ZIP or postal code</span><input name="address-postal-code" maxlength="30" autocomplete="postal-code"></label>
                    <label class="restaurant-field"><span>Country</span><input name="address-country" maxlength="100" autocomplete="country-name"></label>
                </div>
                <div id="restaurant-location-map" class="restaurant-card" aria-label="Saved restaurant location map"></div>
                <p class="restaurant-field-hint" data-map-fallback>Map preview is available without map tiles; your saved coordinates and manual address remain available.</p>
            </section>
            <section class="restaurant-card" aria-labelledby="delivery-information-title"><h2 id="delivery-information-title">Delivery information</h2><div class="restaurant-form-three-column">
                <label class="restaurant-field"><span>Delivery radius (mi)</span><input name="delivery-radius" type="number" min="0.1" max="50" step="0.1" inputmode="decimal"></label>
                <label class="restaurant-field"><span>Minimum order</span><input name="minimum-order" type="number" min="0" step="0.01" inputmode="decimal" value="0"></label>
                <label class="restaurant-field"><span>Estimated prep time (minutes)</span><input name="profile-prep-minutes" type="number" min="1" max="180" step="1" inputmode="numeric"></label>
            </div></section>
            <div class="restaurant-editor-actions"><button type="submit" class="restaurant-primary-action">Save profile</button></div>
        </form>
        <aside class="restaurant-card restaurant-customer-preview" aria-labelledby="storefront-preview-title"><h2 id="storefront-preview-title">Customer preview</h2><div data-storefront-preview aria-live="polite"></div><p class="restaurant-field-hint">This preview uses the same local Restaurant state as the Customer catalog.</p></aside>
    </div>
</main>
<script src="js/api_client.js"></script>
<script defer src="assets/vendor/leaflet/leaflet.js"></script>
<script defer src="js/restaurant_storefront.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
