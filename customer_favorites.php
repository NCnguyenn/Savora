<?php include 'components/customer_header.php'; ?>

<main class="container favorites-page">
    <header class="page-title-block favorites-title">
        <p class="eyebrow">Saved for later</p>
        <h1>Favorites</h1>
        <p>Keep the restaurants and dishes you love close at hand.</p>
    </header>

    <div class="favorite-tabs" role="tablist" aria-label="Favorite type">
        <button id="favorite-restaurants-tab" type="button" role="tab"
            aria-selected="true" aria-controls="favorite-restaurants-panel" tabindex="0">
            <i class="fa-solid fa-store" aria-hidden="true"></i><span>Restaurants</span>
        </button>
        <button id="favorite-products-tab" type="button" role="tab"
            aria-selected="false" aria-controls="favorite-products-panel" tabindex="-1">
            <i class="fa-solid fa-bowl-food" aria-hidden="true"></i><span>Dishes</span>
        </button>
    </div>

    <section id="favorite-restaurants-panel" class="favorite-panel" role="tabpanel"
        aria-labelledby="favorite-restaurants-tab" tabindex="0">
        <div class="section-heading-row">
            <div>
                <p class="eyebrow">Your saved places</p>
                <h2>Restaurants</h2>
            </div>
            <span id="favorite-restaurant-count" class="result-count" aria-live="polite"></span>
        </div>
        <div id="favorite-restaurants-grid" class="favorite-card-grid"></div>
    </section>

    <section id="favorite-products-panel" class="favorite-panel" role="tabpanel"
        aria-labelledby="favorite-products-tab" tabindex="0" hidden>
        <div class="section-heading-row">
            <div>
                <p class="eyebrow">Ready to revisit</p>
                <h2>Dishes</h2>
            </div>
            <span id="favorite-product-count" class="result-count" aria-live="polite"></span>
        </div>
        <div id="favorite-products-grid" class="favorite-card-grid"></div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const stateApi = window.SavoraState;
    const catalog = window.SavoraCatalog;
    const ui = window.SavoraUI;
    if (!stateApi || !catalog || !ui) return;

    const tabs = [...document.querySelectorAll('[role="tab"]')];
    const restaurantGrid = document.getElementById('favorite-restaurants-grid');
    const productGrid = document.getElementById('favorite-products-grid');
    const restaurantCount = document.getElementById('favorite-restaurant-count');
    const productCount = document.getElementById('favorite-product-count');
    const icon = className => ui.el('i', { className: `fa-solid ${className}`, 'aria-hidden': 'true' });
    const money = value => `$${Number(value || 0).toFixed(2)}`;

    function activateTab(tab, moveFocus = false) {
        tabs.forEach(option => {
            const selected = option === tab;
            option.setAttribute('aria-selected', String(selected));
            option.tabIndex = selected ? 0 : -1;
            document.getElementById(option.getAttribute('aria-controls')).hidden = !selected;
        });
        if (moveFocus) tab.focus();
    }

    function removeFavorite(kind, id, name) {
        const nextState = SavoraState.toggleFavorite(stateApi.load(), kind, id);
        stateApi.persist(nextState);
        ui.refreshChrome();
        renderFavorites();
        ui.announce(`${name} removed from favorites.`);
    }

    function removeButton(kind, id, name) {
        return ui.el('button', {
            className: 'favorite-heart-button',
            type: 'button',
            'aria-label': `Remove ${name} from favorites`,
            title: `Remove ${name} from favorites`,
            onclick: () => removeFavorite(kind, id, name)
        }, icon('fa-heart'));
    }

    function emptyState(kind) {
        const isRestaurant = kind === 'restaurants';
        return ui.el('div', { className: 'favorite-empty-state', role: 'status' }, [
            ui.el('span', { className: 'empty-state-icon', 'aria-hidden': 'true' }, icon(isRestaurant ? 'fa-store' : 'fa-bowl-food')),
            ui.el('h3', {}, isRestaurant ? 'No favorite restaurants yet' : 'No favorite dishes yet'),
            ui.el('p', {}, isRestaurant
                ? 'Save a restaurant and it will be waiting here for your next meal.'
                : 'Tap the heart on a dish to keep it close for later.'),
            ui.el('a', { className: 'primary-action', href: 'customer_dashboard.php' }, [
                icon('fa-compass'), isRestaurant ? 'Explore restaurants' : 'Explore dishes'
            ])
        ]);
    }

    function restaurantCard(restaurant) {
        const product = restaurant.productIds.map(id => catalog.products[id]).find(Boolean);
        const visual = ui.el('img', { src: catalog.imageFor(product), alt: '' });
        const openButton = ui.el('button', {
            className: 'favorite-card-navigation',
            type: 'button',
            'aria-label': `Open ${restaurant.name} menu`,
            onclick: () => SavoraUI.openMenuModal(restaurant.name)
        }, [
            visual,
            ui.el('span', { className: 'favorite-card-copy' }, [
                ui.el('strong', {}, restaurant.name),
                ui.el('span', {}, `${restaurant.cuisine} · ${restaurant.prepTime}`),
                ui.el('span', { className: 'favorite-rating' }, [icon('fa-star'), `${restaurant.rating} rating`])
            ])
        ]);
        return ui.el('article', { className: 'favorite-card favorite-restaurant-card' }, [
            openButton,
            removeButton('restaurants', restaurant.name, restaurant.name)
        ]);
    }

    function productCard(product) {
        const visual = ui.el('img', { src: catalog.imageFor(product), alt: '' });
        const link = ui.el('a', {
            className: 'favorite-card-navigation',
            href: `product_detail.php?id=${encodeURIComponent(product.id)}`,
            'aria-label': `View ${product.name}`
        }, [
            visual,
            ui.el('span', { className: 'favorite-card-copy' }, [
                ui.el('strong', {}, product.name),
                ui.el('span', {}, `${product.restaurant} · ${product.prepTime}`),
                ui.el('span', { className: 'favorite-product-price' }, money(product.price))
            ])
        ]);
        return ui.el('article', { className: 'favorite-card favorite-product-card' }, [
            link,
            removeButton('products', product.id, product.name)
        ]);
    }

    function renderFavorites() {
        const state = stateApi.load();
        const restaurants = state.favorites.restaurants.map(name => catalog.restaurants[name]).filter(Boolean);
        const products = state.favorites.products.map(id => catalog.products[id]).filter(Boolean);

        restaurantCount.textContent = `${restaurants.length} ${restaurants.length === 1 ? 'restaurant' : 'restaurants'}`;
        productCount.textContent = `${products.length} ${products.length === 1 ? 'dish' : 'dishes'}`;
        restaurantGrid.replaceChildren(...(restaurants.length ? restaurants.map(restaurantCard) : [emptyState('restaurants')]));
        productGrid.replaceChildren(...(products.length ? products.map(productCard) : [emptyState('products')]));
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab));
        tab.addEventListener('keydown', event => {
            let nextIndex = null;
            if (event.key === 'ArrowRight') nextIndex = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') nextIndex = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = tabs.length - 1;
            if (nextIndex === null) return;
            event.preventDefault();
            activateTab(tabs[nextIndex], true);
        });
    });

    renderFavorites();
});
</script>

<?php include 'components/customer_footer.php'; ?>
