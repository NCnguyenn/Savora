<?php include 'components/customer_header.php'; ?>

<main>
    <section class="discovery-hero" data-critical-background data-background-fallback="ivory gradient" aria-labelledby="discover-title">
        <div class="discovery-hero-copy">
            <p class="eyebrow">Good food, delivered thoughtfully</p>
            <h1 id="discover-title">Deliver something good</h1>
            <p>Your favorite local restaurants and dishes, ready when you are.</p>

            <form id="discovery-search-form" class="discovery-search" role="search">
                <label class="search-field" for="search-input">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <span class="sr-only">Search restaurants or dishes</span>
                    <input id="search-input" type="search" autocomplete="off" placeholder="Search restaurants, cuisines, or dishes">
                </label>
                <button class="delivery-location" type="button" aria-label="Delivery location" data-customer-location-trigger aria-controls="customer-location-dialog" aria-expanded="false">
                    <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                    <span><small>Deliver to</small><strong data-customer-location-label>Choose delivery address</strong></span>
                </button>
                <button class="primary-action" type="submit">Find food</button>
            </form>

            <div id="category-controls" class="category-row" aria-label="Filter by category">
                <button class="category-card" type="button" data-category="all" aria-pressed="true">
                    <i class="fa-solid fa-grip" aria-hidden="true"></i>
                    <span>All</span>
                </button>
            </div>
        </div>
    </section>

    <section id="customer-location-dialog" class="dialog" role="dialog" aria-modal="true" aria-labelledby="customer-location-title" hidden>
        <div class="dialog-scrim" data-customer-location-close></div>
        <div class="dialog-panel customer-location-dialog-panel" role="document">
            <header class="modal-header">
                <div><p class="eyebrow">Delivery location</p><h2 id="customer-location-title">Where should we deliver?</h2></div>
                <button class="icon-button" type="button" aria-label="Close delivery location" data-customer-location-close><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <form data-customer-location-form>
                <label class="form-field" for="customer-location-address">Delivery address
                    <textarea id="customer-location-address" data-customer-location-input rows="3" maxlength="500" autocomplete="street-address" placeholder="Street, building and area"></textarea>
                </label>
                <p class="form-help" data-customer-location-status aria-live="polite" aria-atomic="true"></p>
                <div class="dialog-actions customer-location-actions">
                    <button class="secondary-action" type="button" data-customer-use-gps><i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use current location</button>
                    <button class="primary-action" type="submit">Save address</button>
                    <button class="secondary-action" type="button" data-customer-location-skip>Skip</button>
                </div>
                <small class="form-help">Powered by Geoapify for GPS-assisted addresses.</small>
            </form>
        </div>
    </section>

    <div class="container discovery-layout">
        <div class="discovery-feed">
            <section class="promo-banner" data-critical-background data-background-fallback="forest gradient" aria-labelledby="promotion-title">
                <div>
                    <p class="eyebrow">This week only</p>
                    <h2 id="promotion-title">Free delivery on orders over $25</h2>
                    <p>Use code <strong>SAVORA25</strong> at checkout.</p>
                </div>
                <a class="promo-action" href="product_detail.php?id=1">Order now <span aria-hidden="true">→</span></a>
            </section>

            <section class="section-margin" aria-labelledby="products-title">
                <div class="section-heading-row">
                    <div>
                        <p class="eyebrow">Curated for today</p>
                        <h2 id="products-title">Popular dishes</h2>
                    </div>
                    <span id="product-result-count" class="result-count" aria-live="polite"></span>
                </div>
                <div class="grid-4-col discovery-card-grid" id="food-products-grid"></div>
            </section>

            <section class="section-margin" aria-labelledby="restaurants-title">
                <div class="section-heading-row">
                    <div>
                        <p class="eyebrow">Explore nearby</p>
                        <h2 id="restaurants-title">Restaurants near you</h2>
                    </div>
                    <span id="restaurant-result-count" class="result-count" aria-live="polite"></span>
                </div>
                <div class="grid-4-col discovery-card-grid" id="restaurant-grid"></div>
            </section>
        </div>

        <aside class="discovery-sidebar" aria-label="Order tracking">
            <section class="surface-card tracking-card" aria-labelledby="tracking-title">
                <div class="section-heading-row">
                    <h2 id="tracking-title">Active order</h2>
                    <a href="customer_history.php">View all</a>
                </div>
                <div id="active-order-content" aria-live="polite"></div>
            </section>

            <section class="surface-card rewards-card" aria-labelledby="rewards-title">
                <i class="fa-solid fa-leaf" aria-hidden="true"></i>
                <div>
                    <h2 id="rewards-title">Earn rewards with every order</h2>
                    <p>Local demo purchases help you explore the Savora experience.</p>
                </div>
            </section>
        </aside>
    </div>
</main>

<script src="js/api_client.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const catalog = window.SavoraCatalog;
    const stateApi = window.SavoraState;
    const ui = window.SavoraUI;
    if (!catalog || !stateApi || !ui) return;
    let profileSnapshot;
    try {
        await catalog.hydrate();
        profileSnapshot = await SavoraApi.get('api/profile.php');
    } catch (error) {
        const message = document.getElementById('product-result-count');
        if (message) message.textContent = error.message || 'Catalog is temporarily unavailable.';
        return;
    }
    let serverOrders = [];
    try {
        const orderData = await SavoraApi.get('api/orders.php');
        serverOrders = Array.isArray(orderData && orderData.orders) ? orderData.orders : [];
    } catch (_) { serverOrders = []; }

    const products = Object.values(SavoraCatalog.products);
    const categories = catalog.categories || [];
    const restaurants = Object.values(SavoraCatalog.restaurants).map(restaurant => {
        const menu = restaurant.productIds.map(id => SavoraCatalog.products[id]).filter(Boolean);
        return {
            ...restaurant,
            categories: [...new Set(menu.flatMap(item => item.categories))],
            image: restaurant.image ? SavoraCatalog.imageFor({ image: restaurant.image }) : SavoraCatalog.imageFor(menu[0])
        };
    });
    const searchInput = document.getElementById('search-input');
    const productGrid = document.getElementById('food-products-grid');
    const restaurantGrid = document.getElementById('restaurant-grid');
    const categoryControls = document.getElementById('category-controls');
    let selectedCategory = 'all';
    const favoriteSaved = (type, id) => (profileSnapshot.favorites || []).some(item => item.type === type && item.publicId === String(id));

    const money = value => `$${Number(value || 0).toFixed(2)}`;
    const createElement = (tag, attributes = {}, children = []) => {
        const node = document.createElement(tag);
        Object.entries(attributes).forEach(([name, value]) => {
            if (name === 'className') node.className = value;
            else if (name === 'text') node.textContent = value;
            else node.setAttribute(name, value);
        });
        (Array.isArray(children) ? children : [children]).filter(Boolean).forEach(child => node.append(child));
        return node;
    };

    function favoriteButton(kind, id, name) {
        const value = String(id);
        const label = kind === 'products' ? 'dish' : 'restaurant';
        const button = createElement('button', {
            className: 'discovery-favorite-button',
            type: 'button',
            'data-favorite-kind': kind,
            'data-favorite-id': value
        });
        const render = saved => {
            button.setAttribute('aria-pressed', String(saved));
            button.setAttribute('aria-label', `${saved ? 'Remove' : 'Add'} ${name} ${saved ? 'from' : 'to'} favorites`);
            button.setAttribute('title', `${saved ? 'Remove' : 'Add'} ${name} ${saved ? 'from' : 'to'} favorites`);
            button.replaceChildren(createElement('i', {
                className: `fa-${saved ? 'solid' : 'regular'} fa-heart`,
                'aria-hidden': 'true'
            }));
        };
        render(favoriteSaved(kind === 'products' ? 'product' : 'restaurant', value));
        button.addEventListener('click', async () => {
            const type = kind === 'products' ? 'product' : 'restaurant';
            const active = !favoriteSaved(type, value); const scope = `customer-favorite-${type}-${value}`;
            button.disabled = true;
            try {
                await SavoraApi.post('api/profile.php', { action: 'set_favorite', payload: { type, publicId: value, active, version: 0 } }, SavoraApi.intentKey(scope));
                profileSnapshot = await SavoraApi.get('api/profile.php'); SavoraApi.clearIntentKey(scope); filterDiscovery();
                ui.announce(`${name} ${active ? 'added to' : 'removed from'} favorites.`);
            } catch (error) { button.disabled = false; ui.announce(error.message || 'Favorite was not changed.'); }
        });
        return button;
    }

    function makeEmptyState(message) {
        return createElement('div', { className: 'empty-state', role: 'status' }, [
            createElement('i', { className: 'fa-solid fa-seedling', 'aria-hidden': 'true' }),
            createElement('p', { text: message }),
            createElement('button', { className: 'secondary-action', type: 'button', text: 'Clear filters' })
        ]);
    }

    function productCard(item) {
        const image = createElement('img', { className: 'food-card-img', src: SavoraCatalog.imageFor(item), alt: '' });
        const title = createElement('h3', { className: 'food-card-title', text: item.name });
        const meta = createElement('p', {
            className: 'food-card-meta',
            text: `${item.restaurant} · ${item.prepTime} · ${item.categories[0]}`
        });
        const price = createElement('strong', { className: 'card-price', text: money(item.price) });
        const card = createElement('a', {
            className: 'food-card discovery-card',
            href: `product_detail.php?id=${encodeURIComponent(item.id)}`,
            'aria-label': `View ${item.name} from ${item.restaurant}`
        }, [image, title, meta, price]);
        return createElement('article', { className: 'discovery-card-shell' }, [
            card,
            favoriteButton('products', item.id, item.name)
        ]);
    }

    function restaurantCard(restaurant) {
        const image = createElement('img', { className: 'food-card-img', src: SavoraCatalog.imageFor({ image: restaurant.image }), alt: '' });
        const title = createElement('h3', { className: 'food-card-title', text: restaurant.name });
        const meta = createElement('p', {
            className: 'food-card-meta',
            text: `★ ${restaurant.rating} · ${restaurant.cuisine} · ${restaurant.prepTime}`
        });
        const action = createElement('span', { className: 'restaurant-card-action', text: 'View menu →' });
        const card = createElement('button', {
            className: 'food-card discovery-card restaurant-discovery-card',
            type: 'button',
            'aria-label': `Open ${restaurant.name} menu`
        }, [image, title, meta, action]);
        card.addEventListener('click', () => SavoraUI.openMenuModal(restaurant.name));
        return createElement('article', { className: 'discovery-card-shell' }, [
            card,
            favoriteButton('restaurants', restaurant.publicId, restaurant.name)
        ]);
    }

    function renderList(grid, entries, renderCard, emptyMessage) {
        if (entries.length) {
            grid.replaceChildren(...entries.map(renderCard));
            return;
        }
        const empty = makeEmptyState(emptyMessage);
        empty.querySelector('button').addEventListener('click', clearFilters);
        grid.replaceChildren(empty);
    }

    function filterDiscovery() {
        const query = searchInput.value.trim().toLowerCase();
        const matches = item =>
            (selectedCategory === 'all' || item.categories.includes(selectedCategory)) &&
            `${item.name} ${item.restaurant || item.cuisine || ''} ${item.categories.join(' ')}`.toLowerCase().includes(query);
        const visibleProducts = products.filter(matches);
        const visibleRestaurants = restaurants.filter(matches);

        renderList(productGrid, visibleProducts, productCard, 'No dishes match this search and category.');
        renderList(restaurantGrid, visibleRestaurants, restaurantCard, 'No restaurants match this search and category.');
        document.getElementById('product-result-count').textContent = `${visibleProducts.length} ${visibleProducts.length === 1 ? 'dish' : 'dishes'}`;
        document.getElementById('restaurant-result-count').textContent = `${visibleRestaurants.length} ${visibleRestaurants.length === 1 ? 'restaurant' : 'restaurants'}`;
    }

    function clearFilters() {
        selectedCategory = 'all';
        searchInput.value = '';
        categoryControls.querySelectorAll('[data-category]').forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.category === 'all'));
        });
        filterDiscovery();
        searchInput.focus();
    }

    categories.forEach(category => {
        const button = createElement('button', {
            className: 'category-card',
            type: 'button',
            'data-category': category.id,
            'aria-pressed': 'false'
        }, [
            createElement('i', { className: 'fa-solid fa-utensils', 'aria-hidden': 'true' }),
            createElement('span', { text: category.label })
        ]);
        categoryControls.append(button);
    });

    categoryControls.addEventListener('click', event => {
        const button = event.target.closest('[data-category]');
        if (!button) return;
        selectedCategory = button.dataset.category;
        categoryControls.querySelectorAll('[data-category]').forEach(option => {
            option.setAttribute('aria-pressed', String(option === button));
        });
        filterDiscovery();
    });
    searchInput.addEventListener('input', filterDiscovery);
    document.getElementById('discovery-search-form').addEventListener('submit', event => {
        event.preventDefault();
        filterDiscovery();
    });

    function initializeTrackingMap(delivery) {
        const mapElement = document.getElementById('order-map');
        if (!mapElement) return;
        const markDegraded = () => {
            mapElement.dataset.mapStatus = 'degraded';
            mapElement.classList.add('map-tiles-degraded');
            mapElement.setAttribute('aria-label', 'Demo delivery route. Map tiles are unavailable; route markers remain visible.');
        };
        if (!window.L) {
            markDegraded();
            return;
        }
        const customerLocation = delivery && delivery.customerLocation && Number.isFinite(Number(delivery.customerLocation.latitude)) && Number.isFinite(Number(delivery.customerLocation.longitude))
            ? [Number(delivery.customerLocation.latitude), Number(delivery.customerLocation.longitude)] : null;
        const rawDriverLocation = delivery && delivery.location;
        const driverIsFresh = rawDriverLocation && rawDriverLocation.recordedAt && (Date.now() - new Date(rawDriverLocation.recordedAt).getTime()) <= 5 * 60 * 1000;
        const driverLocation = driverIsFresh && Number.isFinite(Number(rawDriverLocation.latitude)) && Number.isFinite(Number(rawDriverLocation.longitude))
            ? [Number(rawDriverLocation.latitude), Number(rawDriverLocation.longitude)] : null;
        const center = customerLocation || driverLocation || [0, 0];
        const map = L.map('order-map', { zoomControl: false, scrollWheelZoom: false }).setView(center, customerLocation || driverLocation ? 14 : 2);
        let tileFailed = false;
        const tiles = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 20,
            attribution: '&copy; OpenStreetMap &copy; CARTO'
        });
        tiles.on('tileerror', () => {
            tileFailed = true;
            markDegraded();
        });
        tiles.on('load', () => {
            if (!tileFailed) mapElement.dataset.mapStatus = 'ready';
        });
        tiles.addTo(map);
        const markers = [];
        if (customerLocation) markers.push(L.circleMarker(customerLocation, { radius: 8, color: '#fff', weight: 3, fillColor: '#ef634b', fillOpacity: 1 }).addTo(map));
        if (driverLocation) {
            const driverMarker = L.circleMarker(driverLocation, { radius: 9, color: '#fff', weight: 3, fillColor: '#073b2b', fillOpacity: 1 }).addTo(map);
            markers.push(driverMarker);
        }
        if (markers.length > 1) map.fitBounds(L.featureGroup(markers).getBounds().pad(0.25));
        if (!driverLocation) mapElement.dataset.locationStatus = 'unavailable';
        mapElement.setAttribute('aria-label', driverLocation ? `Server Driver location recorded ${rawDriverLocation.recordedAt}.` : 'Driver location temporarily unavailable.');
    }

    function renderTracking() {
        const tracking = document.getElementById('active-order-content');
        const activeOrder = serverOrders.find(order => ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'assigned', 'picked_up', 'on_the_way'].includes(order.status)) || null;
        if (!activeOrder) {
            const empty = createElement('div', { className: 'empty-state tracking-empty' }, [
                createElement('i', { className: 'fa-solid fa-bicycle', 'aria-hidden': 'true' }),
                createElement('h3', { text: 'Nothing on the way yet' }),
                createElement('p', { text: 'Choose a local favorite and your delivery progress will appear here.' }),
                createElement('a', { className: 'primary-action', href: '#products-title', text: 'Discover dishes' })
            ]);
            tracking.replaceChildren(empty);
            return;
        }

        const statusLabels = {
            pending: 'Pending restaurant confirmation',
            confirmed: 'Confirmed',
            preparing: 'Preparing',
            ready_for_pickup: 'Ready for pickup',
            on_the_way: 'On the way'
        };
        const status = statusLabels[activeOrder.status] || 'Order update';
        const itemCount = activeOrder.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
        const delivery = activeOrder.assignment;
        const dispatch = activeOrder.dispatch;
        const driverCopy = activeOrder.status === 'ready_for_pickup' && !delivery
            ? dispatch && dispatch.status === 'offer_sent'
                ? 'Savora has sent this delivery to an eligible nearby driver'
                : 'Searching for a nearby driver'
            : delivery
                ? `Driver assigned · ${delivery.driverName || 'Savora driver'} · ${delivery.vehicle || 'Vehicle details unavailable'}`
                : '';
        const article = createElement('article', { className: 'active-order' }, [
            createElement('p', { className: 'status-chip', text: status }),
            createElement('h3', { text: `Order ${activeOrder.id}` }),
            createElement('p', { text: `${itemCount} ${itemCount === 1 ? 'item' : 'items'} · ${money(activeOrder.total)}` }),
            createElement('p', { className: 'tracking-estimate', text: 'Estimated arrival: 10–20 minutes' }),
            driverCopy ? createElement('p', { className: 'tracking-driver', text: driverCopy }) : null,
            activeOrder.deliveryNote ? createElement('p', { className: 'tracking-delivery-note', text: `Delivery note: ${activeOrder.deliveryNote}` }) : null,
            createElement('div', { id: 'order-map', className: 'order-map', role: 'img', 'aria-label': 'Server delivery location', 'data-map-status': 'loading' }, [
                createElement('p', { className: 'map-fallback-message', text: 'Map tiles unavailable — delivery markers remain visible.' })
            ]),
            !delivery || !delivery.location || !delivery.location.recordedAt || (Date.now() - new Date(delivery.location.recordedAt).getTime()) > 5 * 60 * 1000
                ? createElement('p', { className: 'tracking-location-unavailable', text: 'Location temporarily unavailable. The last server update is stale or not yet available.' }) : null,
            createElement('a', { className: 'primary-action', href: 'customer_history.php', text: 'Track order' })
        ].filter(Boolean));
        tracking.replaceChildren(article);
        initializeTrackingMap({
            customerLocation: activeOrder.deliveryLocation,
            location: delivery && delivery.location
        });
    }

    filterDiscovery();
    renderTracking();
});
</script>

<?php include 'components/customer_footer.php'; ?>
