<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-menu-editor-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Menu item</p><h1 data-menu-editor-title>Add Menu Item</h1><p>Create a dish customers can discover and order.</p></div>
    </header>
    <p class="restaurant-form-summary" data-menu-validation aria-live="polite" aria-atomic="true"></p>
    <p class="restaurant-form-summary" data-menu-status aria-live="polite" aria-atomic="true"></p>
    <div class="restaurant-menu-editor-layout">
        <form class="restaurant-form" data-menu-editor-form novalidate>
            <section class="restaurant-card" aria-labelledby="menu-basic-title"><h2 id="menu-basic-title">Basic information</h2><div class="restaurant-form-two-column">
                <label class="restaurant-field"><span>Item name</span><input name="menu-name" maxlength="100" required aria-describedby="menu-name-error"><p id="menu-name-error" class="restaurant-field-error" data-menu-field-error="name"></p></label>
                <label class="restaurant-field"><span>Category</span><select name="menu-category" required aria-describedby="menu-category-error"><option value="">Choose a category</option><option value="burgers">Burgers</option><option value="pizza">Pizza</option><option value="pasta">Pasta</option><option value="drinks">Drinks</option><option value="sides">Sides</option><option value="lunch">Lunch</option></select><p id="menu-category-error" class="restaurant-field-error" data-menu-field-error="category"></p></label>
                <label class="restaurant-field restaurant-field-wide"><span>Description</span><textarea name="menu-description" maxlength="500" placeholder="Describe your dish for customers."></textarea></label>
            </div></section>
            <section class="restaurant-card" aria-labelledby="menu-photo-title"><h2 id="menu-photo-title">Photo</h2><label class="restaurant-field"><span>Local catalog image</span><select name="menu-image"><option value="">Use safe placeholder</option><option value="assets/images/catalog/mega-burger-feast-combo.jpg">Mega burger photo</option><option value="assets/images/catalog/supreme-pepperoni-pizza.jpg">Pepperoni pizza photo</option><option value="assets/images/catalog/brown-sugar-boba-milk-tea.jpg">Boba tea photo</option></select><small class="restaurant-field-hint">Only built-in local catalog images can be published.</small></label></section>
            <section class="restaurant-card" aria-labelledby="menu-pricing-title"><h2 id="menu-pricing-title">Pricing</h2><div class="restaurant-form-three-column">
                <label class="restaurant-field"><span>Base price</span><input name="menu-price" inputmode="decimal" type="number" min="0.01" step="0.01" required aria-describedby="menu-price-error"><p id="menu-price-error" class="restaurant-field-error" data-menu-field-error="price"></p></label>
                <label class="restaurant-field"><span>Compare-at price</span><input name="menu-compare-price" inputmode="decimal" type="number" min="0" step="0.01" aria-describedby="menu-compare-price-error"><p id="menu-compare-price-error" class="restaurant-field-error" data-menu-field-error="compareAtPrice"></p></label>
                <label class="restaurant-field"><span>Tax category</span><select name="menu-tax-category"><option value="Food &amp; beverage">Food &amp; beverage</option><option value="Prepared food">Prepared food</option><option value="Non-alcoholic beverage">Non-alcoholic beverage</option></select></label>
            </div></section>
            <section class="restaurant-card" aria-labelledby="menu-options-title"><h2 id="menu-options-title">Options &amp; add-ons</h2><div class="restaurant-menu-options"><div><h3>Option groups</h3><div data-menu-option-groups></div><label class="restaurant-field"><span>Option group name</span><input name="menu-option-group-name" placeholder="Choose a size"></label><label class="restaurant-check-field"><input name="menu-option-required" type="checkbox"><span>Required choice</span></label><label class="restaurant-field"><span>First option label</span><input name="menu-option-label" placeholder="Large"></label><label class="restaurant-field"><span>First option price</span><input name="menu-option-price" type="number" min="0" step="0.01"></label><button type="button" data-menu-add-option-group>Add option group</button></div><div><h3>Add-ons</h3><div data-menu-add-ons></div><label class="restaurant-field"><span>Add-on label</span><input name="menu-addon-label" placeholder="Extra parmesan"></label><label class="restaurant-field"><span>Add-on price</span><input name="menu-addon-price" type="number" min="0" step="0.01"></label><button type="button" data-menu-add-addon>Add add-on</button></div></div></section>
            <section class="restaurant-card" aria-labelledby="menu-availability-editor-title"><h2 id="menu-availability-editor-title">Availability</h2><div class="restaurant-form-three-column">
                <label class="restaurant-check-field"><input name="menu-available" type="checkbox" checked><span>Available to customers</span></label>
                <label class="restaurant-check-field"><input name="menu-stock-tracking" type="checkbox"><span>Track stock</span></label>
                <label class="restaurant-field"><span>Stock quantity</span><input name="menu-stock" type="number" min="0" step="1" value="0"></label>
                <label class="restaurant-field"><span>Prep time (minutes)</span><input name="menu-prep-time" type="number" min="1" step="1" value="20"></label>
                <label class="restaurant-field restaurant-field-wide"><span>Dietary tags (comma separated)</span><input name="menu-dietary-tags" placeholder="Vegetarian, gluten-free"></label>
            </div></section>
            <div class="restaurant-editor-actions"><a href="restaurant_menu.php">Cancel</a><button type="submit" data-menu-save="draft">Save as draft</button><button type="submit" class="restaurant-primary-action" data-menu-save="publish">Publish item</button></div>
        </form>
        <aside class="restaurant-card restaurant-customer-preview" aria-labelledby="customer-preview-title"><h2 id="customer-preview-title">Customer preview</h2><div data-menu-customer-preview aria-live="polite"></div><p class="restaurant-field-hint">This is how the item will appear in the local Customer catalog.</p></aside>
    </div>
</main>
<script src="js/api_client.js"></script>
<script defer src="js/restaurant_menu.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
