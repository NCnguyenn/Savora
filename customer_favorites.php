<?php include 'components/customer_header.php'; ?>

<main class="container favorites-page">
    <header class="page-title-block favorites-title"><p class="eyebrow">Saved for later</p><h1>Favorites</h1><p>Your saved restaurants and dishes follow your authenticated account.</p></header>
    <p class="form-help" id="favorite-feedback" role="status" aria-live="polite"></p>
    <div class="favorite-tabs" role="tablist" aria-label="Favorite type">
        <button id="favorite-restaurants-tab" type="button" role="tab" aria-selected="true" aria-controls="favorite-restaurants-panel" tabindex="0"><i class="fa-solid fa-store" aria-hidden="true"></i><span>Restaurants</span></button>
        <button id="favorite-products-tab" type="button" role="tab" aria-selected="false" aria-controls="favorite-products-panel" tabindex="-1"><i class="fa-solid fa-bowl-food" aria-hidden="true"></i><span>Dishes</span></button>
    </div>
    <section id="favorite-restaurants-panel" class="favorite-panel" role="tabpanel" aria-labelledby="favorite-restaurants-tab" tabindex="0">
        <div class="section-heading-row"><div><p class="eyebrow">Your saved places</p><h2>Restaurants</h2></div><span id="favorite-restaurant-count" class="result-count" aria-live="polite"></span></div>
        <div id="favorite-restaurants-grid" class="favorite-card-grid"></div>
    </section>
    <section id="favorite-products-panel" class="favorite-panel" role="tabpanel" aria-labelledby="favorite-products-tab" tabindex="0" hidden>
        <div class="section-heading-row"><div><p class="eyebrow">Ready to revisit</p><h2>Dishes</h2></div><span id="favorite-product-count" class="result-count" aria-live="polite"></span></div>
        <div id="favorite-products-grid" class="favorite-card-grid"></div>
    </section>
</main>

<script src="js/api_client.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const catalog = window.SavoraCatalog; const ui = window.SavoraUI; const feedback = document.getElementById('favorite-feedback');
    if (!catalog || !ui || !window.SavoraApi) return;
    const tabs = [...document.querySelectorAll('[role="tab"]')];
    const restaurantGrid = document.getElementById('favorite-restaurants-grid'); const productGrid = document.getElementById('favorite-products-grid');
    const restaurantCount = document.getElementById('favorite-restaurant-count'); const productCount = document.getElementById('favorite-product-count');
    const icon = className => ui.el('i', { className: `fa-solid ${className}`, 'aria-hidden': 'true' });
    const money = value => `$${Number(value || 0).toFixed(2)}`;
    let snapshot = { favorites: [] };

    function activateTab(tab, moveFocus = false) {
        tabs.forEach(option => { const selected = option === tab; option.setAttribute('aria-selected', String(selected)); option.tabIndex = selected ? 0 : -1; document.getElementById(option.getAttribute('aria-controls')).hidden = !selected; });
        if (moveFocus) tab.focus();
    }
    function emptyState(kind) {
        const restaurant = kind === 'restaurant';
        return ui.el('div', { className: 'favorite-empty-state', role: 'status' }, [
            ui.el('span', { className: 'empty-state-icon', 'aria-hidden': 'true' }, icon(restaurant ? 'fa-store' : 'fa-bowl-food')),
            ui.el('h3', {}, restaurant ? 'No favorite restaurants yet' : 'No favorite dishes yet'),
            ui.el('p', {}, 'Save favorites from discovery and they will appear here.'),
            ui.el('a', { className: 'primary-action', href: 'customer_dashboard.php' }, [icon('fa-compass'), 'Explore'])
        ]);
    }
    async function removeFavorite(type, publicId, name) {
        const scope = `customer-favorite-${type}-${publicId}`;
        try {
            await SavoraApi.post('api/profile.php', { action: 'set_favorite', payload: { type, publicId, active: false, version: 0 } }, SavoraApi.intentKey(scope));
            await hydrate(); SavoraApi.clearIntentKey(scope); ui.announce(`${name} removed from favorites.`);
        } catch (error) { feedback.textContent = error.message || 'Favorite was not changed.'; }
    }
    function removeButton(type, publicId, name) {
        const button = ui.el('button', { className: 'favorite-heart-button', type: 'button', 'aria-label': `Remove ${name} from favorites`, title: `Remove ${name} from favorites` }, icon('fa-heart'));
        button.addEventListener('click', () => removeFavorite(type, publicId, name)); return button;
    }
    function restaurantCard(restaurant) {
        const product = restaurant.productIds.map(id => catalog.products[id]).find(Boolean);
        const open = ui.el('a', { className: 'favorite-card-navigation', href: `customer_restaurant.php?restaurant=${encodeURIComponent(restaurant.publicId)}`, 'aria-label': `Open ${restaurant.name} restaurant page` }, [
            ui.el('img', { src: catalog.imageFor({ image: restaurant.heroImage || restaurant.image || (product && product.image) }), alt: '' }),
            ui.el('span', { className: 'favorite-card-copy' }, [ui.el('strong', {}, restaurant.name), ui.el('span', {}, restaurant.slogan || restaurant.cuisine || 'Restaurant')])
        ]);
        return ui.el('article', { className: 'favorite-card favorite-restaurant-card' }, [open, removeButton('restaurant', restaurant.publicId, restaurant.name)]);
    }
    function productCard(product) {
        return ui.el('article', { className: 'favorite-card favorite-product-card' }, [
            ui.el('a', { className: 'favorite-card-navigation', href: `product_detail.php?id=${encodeURIComponent(product.id)}`, 'aria-label': `View ${product.name}` }, [
                ui.el('img', { src: catalog.imageFor(product), alt: '' }), ui.el('span', { className: 'favorite-card-copy' }, [ui.el('strong', {}, product.name), ui.el('span', {}, product.restaurant), ui.el('span', { className: 'favorite-product-price' }, money(product.price))])
            ]), removeButton('product', product.id, product.name)
        ]);
    }
    function renderFavorites() {
        const favorite = (type, id) => snapshot.favorites.some(item => item.type === type && item.publicId === String(id));
        const restaurants = Object.values(catalog.restaurants).filter(item => favorite('restaurant', item.publicId));
        const products = Object.values(catalog.products).filter(item => favorite('product', item.id));
        restaurantCount.textContent = `${restaurants.length} ${restaurants.length === 1 ? 'restaurant' : 'restaurants'}`;
        productCount.textContent = `${products.length} ${products.length === 1 ? 'dish' : 'dishes'}`;
        restaurantGrid.replaceChildren(...(restaurants.length ? restaurants.map(restaurantCard) : [emptyState('restaurant')]));
        productGrid.replaceChildren(...(products.length ? products.map(productCard) : [emptyState('product')]));
    }
    async function hydrate() { await catalog.hydrate(); snapshot = await SavoraApi.get('api/profile.php'); renderFavorites(); }
    tabs.forEach((tab, index) => { tab.addEventListener('click', () => activateTab(tab)); tab.addEventListener('keydown', event => { let next = null; if (event.key === 'ArrowRight') next = (index + 1) % tabs.length; if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length; if (event.key === 'Home') next = 0; if (event.key === 'End') next = tabs.length - 1; if (next !== null) { event.preventDefault(); activateTab(tabs[next], true); } }); });
    try { await hydrate(); } catch (error) { feedback.textContent = error.message || 'Favorites are unavailable.'; }
});
</script>
<?php include 'components/customer_footer.php'; ?>
