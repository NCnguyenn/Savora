<?php
$product_id = isset($_GET['id']) ? (string) $_GET['id'] : '1';
include 'components/customer_header.php';
?>

<main class="container product-detail-page" id="product-page-main">
    <p id="product-loading" class="empty-state" role="status">Loading product details…</p>

    <section id="product-not-found" class="empty-state product-not-found" aria-labelledby="not-found-title" hidden>
        <i class="fa-solid fa-bowl-food" aria-hidden="true"></i>
        <h1 id="not-found-title">Product not found</h1>
        <p>That dish is not in the current Savora catalog. It may have been removed or the link may be incorrect.</p>
        <a class="primary-action" href="customer_dashboard.php">Discover available dishes</a>
    </section>

    <div id="product-detail-content" hidden>
        <nav class="detail-breadcrumbs" aria-label="Breadcrumb">
            <a href="customer_dashboard.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to Discover</a>
            <span aria-hidden="true">/</span>
            <span id="breadcrumb-category"></span>
            <span aria-hidden="true">/</span>
            <span id="breadcrumb-product" aria-current="page"></span>
        </nav>

        <div class="customer-two-column product-detail-layout">
            <div class="product-visual-column">
                <img id="product-image" class="product-hero-image" src="" alt="">

                <section class="surface-card restaurant-detail-card" aria-labelledby="restaurant-detail-title">
                    <div class="restaurant-mark" aria-hidden="true"><i class="fa-solid fa-leaf"></i></div>
                    <div>
                        <p class="eyebrow">Restaurant</p>
                        <h2 id="restaurant-detail-title"></h2>
                        <p id="restaurant-description"></p>
                        <dl class="restaurant-facts">
                            <div>
                                <dt>Delivery time</dt>
                                <dd id="restaurant-prep-time"></dd>
                            </div>
                            <div>
                                <dt>Rating</dt>
                                <dd id="restaurant-rating"></dd>
                            </div>
                            <div>
                                <dt>Cuisine</dt>
                                <dd id="restaurant-cuisine"></dd>
                            </div>
                        </dl>
                    </div>
                </section>
            </div>

            <article class="product-customization" aria-labelledby="product-title">
                <p id="product-restaurant" class="product-restaurant-name"></p>
                <div class="product-title-actions">
                    <h1 id="product-title"></h1>
                    <button id="product-favorite-button" class="product-favorite-button" type="button" aria-pressed="false" aria-label="Add dish to favorites"></button>
                </div>
                <div class="product-meta">
                    <span><i class="fa-solid fa-star" aria-hidden="true"></i> <span id="product-rating"></span></span>
                    <span aria-hidden="true">·</span>
                    <span><i class="fa-regular fa-clock" aria-hidden="true"></i> <span id="product-prep-time"></span> delivery</span>
                </div>
                <p id="product-description" class="product-description"></p>
                <p id="product-base-price" class="product-base-price"></p>

                <div id="product-tags" class="product-tag-list" aria-label="Dietary and allergen information"></div>

                <section class="product-ingredients" aria-labelledby="ingredients-title">
                    <h2 id="ingredients-title">What’s inside</h2>
                    <p id="product-calories"></p>
                    <ul id="product-ingredient-list"></ul>
                </section>

                <form id="product-customization-form">
                    <fieldset id="portion-fieldset" class="option-fieldset">
                        <legend>1. Choose your portion</legend>
                        <div id="portion-options" class="option-grid"></div>
                    </fieldset>

                    <fieldset id="addon-fieldset" class="option-fieldset">
                        <legend>2. Add-ons <span>(Optional)</span></legend>
                        <div id="addon-options" class="option-grid"></div>
                    </fieldset>

                    <div class="special-instructions">
                        <label for="special-notes">3. Special instructions <span>(Optional)</span></label>
                        <textarea id="special-notes" name="special-notes" rows="3" maxlength="120" placeholder="E.g. No onions, less salt, sauce on the side…"></textarea>
                        <p><span id="note-count">0</span>/120</p>
                    </div>

                    <div class="product-purchase-row">
                        <div class="qty-control product-quantity" role="group" aria-label="Item quantity">
                            <button id="decrease-quantity" class="qty-btn" type="button" aria-label="Decrease quantity">−</button>
                            <span id="page-qty" aria-live="polite">1</span>
                            <button id="increase-quantity" class="qty-btn" type="button" aria-label="Increase quantity">+</button>
                        </div>
                        <button id="add-product-to-cart" class="primary-action add-product-button" type="submit">
                            <span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Add to cart</span>
                            <span id="page-total-price" aria-live="polite"></span>
                        </button>
                    </div>
                </form>
            </article>
        </div>
    </div>
</main>

<script src="js/api_client.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const productId = <?php echo json_encode($product_id, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const catalog = window.SavoraCatalog;
    const stateApi = window.SavoraState;
    const ui = window.SavoraUI;
    const loading = document.getElementById('product-loading');
    const notFound = document.getElementById('product-not-found');
    const content = document.getElementById('product-detail-content');

    if (!catalog || !stateApi || !ui) {
        loading.textContent = 'Product details are unavailable.';
        return;
    }

    const isAuthenticated = window.SavoraCustomerAuthenticated === true;
    let profileSnapshot = { favorites: [] };
    try {
        await catalog.hydrate();
    } catch (error) {
        loading.textContent = error.message || 'Product details are temporarily unavailable.';
        return;
    }
    if (isAuthenticated) {
        try { profileSnapshot = await SavoraApi.get('api/profile.php'); }
        catch (_) { profileSnapshot = { favorites: [] }; }
    }

    const item = SavoraCatalog.products[String(productId)];
    if (!item) {
        loading.hidden = true;
        notFound.hidden = false;
        return;
    }

    const restaurant = SavoraCatalog.restaurants[item.restaurant] || {
        name: item.restaurant,
        cuisine: item.categories[0],
        rating: '—',
        prepTime: item.prepTime
    };
    let quantity = 1;

    const money = value => `$${Number(value || 0).toFixed(2)}`;
    const setText = (id, value) => {
        const node = document.getElementById(id);
        if (node) node.textContent = value;
    };
    const createElement = (tag, attributes = {}, children = []) => {
        const node = document.createElement(tag);
        Object.entries(attributes).forEach(([name, value]) => {
            if (name === 'className') node.className = value;
            else if (name === 'text') node.textContent = value;
            else if (name === 'checked') node.checked = value;
            else node.setAttribute(name, value);
        });
        (Array.isArray(children) ? children : [children]).filter(Boolean).forEach(child => node.append(child));
        return node;
    };

    function optionLabel(control, label, price) {
        const copy = createElement('span', { className: 'option-copy' }, [
            createElement('strong', { text: label }),
            createElement('span', { text: price ? `+${money(price)}` : 'Included' })
        ]);
        return createElement('label', { className: 'product-option', for: control.id }, [control, copy]);
    }

    const portions = item.portions.map((portion, index) => {
        const id = `portion-${item.id}-${portion.id}`;
        const input = createElement('input', {
            id,
            type: 'radio',
            name: 'portion',
            value: portion.id,
            checked: index === 0
        });
        input.addEventListener('change', updateTotal);
        document.getElementById('portion-options').append(optionLabel(input, portion.label, portion.price));
        return { ...portion, input };
    });

    const addOns = item.addOns.filter(option => option.productId === item.id).map(option => {
        const id = `addon-${item.id}-${option.id}`;
        const input = createElement('input', { id, type: 'checkbox', value: option.id });
        input.addEventListener('change', updateTotal);
        document.getElementById('addon-options').append(optionLabel(input, option.label, option.price));
        return { ...option, input };
    });

    function selectedPortion() {
        return portions.find(portion => portion.input.checked) || portions[0];
    }

    function selectedOptions() {
        const portion = selectedPortion();
        return [
            { id: `portion-${portion.id}`, label: portion.label, price: portion.price },
            ...addOns.filter(option => option.input.checked).map(({ id, label, price }) => ({ id, label, price }))
        ];
    }

    function unitPrice() {
        return item.price + selectedOptions().reduce((sum, option) => sum + Number(option.price || 0), 0);
    }

    function updateTotal() {
        setText('page-qty', String(quantity));
        setText('page-total-price', money(unitPrice() * quantity));
        document.getElementById('decrease-quantity').disabled = quantity === 1;
    }

    function changeQuantity(delta) {
        quantity = Math.max(1, quantity + delta);
        updateTotal();
    }

    setText('breadcrumb-category', restaurant.cuisine);
    setText('breadcrumb-product', item.name);
    setText('product-restaurant', item.restaurant);
    setText('product-title', item.name);
    setText('product-rating', `${restaurant.rating} rating`);
    setText('product-prep-time', item.prepTime);
    setText('product-description', item.description);
    setText('product-base-price', money(item.price));
    setText('product-calories', `${item.calories} kcal per regular serving`);
    setText('restaurant-detail-title', restaurant.name);
    setText('restaurant-description', `${restaurant.name} brings Savora customers ${restaurant.cuisine.toLowerCase()} favorites prepared to order.`);
    setText('restaurant-prep-time', restaurant.prepTime);
    setText('restaurant-rating', `★ ${restaurant.rating}`);
    setText('restaurant-cuisine', restaurant.cuisine);
    document.getElementById('product-image').src = SavoraCatalog.imageFor(item);
    document.getElementById('product-image').alt = item.name;
    document.title = `${item.name} | Savora`;

    const favoriteButton = document.getElementById('product-favorite-button');
    function renderFavoriteButton() {
        const saved = (profileSnapshot.favorites || []).some(entry => entry.type === 'product' && entry.publicId === String(item.id));
        favoriteButton.setAttribute('aria-pressed', String(saved));
        favoriteButton.setAttribute('aria-label', `${saved ? 'Remove' : 'Add'} ${item.name} ${saved ? 'from' : 'to'} favorites`);
        favoriteButton.setAttribute('title', `${saved ? 'Remove' : 'Add'} ${item.name} ${saved ? 'from' : 'to'} favorites`);
        favoriteButton.replaceChildren(createElement('i', {
            className: `fa-${saved ? 'solid' : 'regular'} fa-heart`,
            'aria-hidden': 'true'
        }));
    }
    renderFavoriteButton();
    favoriteButton.addEventListener('click', async () => {
        if (!isAuthenticated) {
            const returnTo = `product_detail.php?id=${encodeURIComponent(productId)}`;
            window.location.assign(`login.php?return_to=${encodeURIComponent(returnTo)}`);
            return;
        }
        const active = !(profileSnapshot.favorites || []).some(entry => entry.type === 'product' && entry.publicId === String(item.id));
        const scope = `customer-favorite-product-${item.id}`; favoriteButton.disabled = true;
        try {
            await SavoraApi.post('api/profile.php', { action: 'set_favorite', payload: { type: 'product', publicId: String(item.id), active, version: 0 } }, SavoraApi.intentKey(scope));
            profileSnapshot = await SavoraApi.get('api/profile.php'); SavoraApi.clearIntentKey(scope); renderFavoriteButton();
            ui.announce(`${item.name} ${active ? 'added to' : 'removed from'} favorites.`);
        } catch (error) { favoriteButton.disabled = false; ui.announce(error.message || 'Favorite was not changed.'); }
    });

    const tags = [
        ...item.dietaryTags.map(tag => ({ icon: 'fa-leaf', label: tag })),
        ...item.allergens.map(allergen => ({ icon: 'fa-circle-info', label: `Contains ${allergen}` }))
    ];
    document.getElementById('product-tags').replaceChildren(...tags.map(tag =>
        createElement('span', { className: 'status-chip' }, [
            createElement('i', { className: `fa-solid ${tag.icon}`, 'aria-hidden': 'true' }),
            document.createTextNode(tag.label.replaceAll('-', ' '))
        ])
    ));
    document.getElementById('product-ingredient-list').replaceChildren(...item.ingredients.map(ingredient =>
        createElement('li', { text: ingredient })
    ));

    document.getElementById('decrease-quantity').addEventListener('click', () => changeQuantity(-1));
    document.getElementById('increase-quantity').addEventListener('click', () => changeQuantity(1));
    document.getElementById('special-notes').addEventListener('input', event => {
        setText('note-count', String(event.target.value.length));
    });
    document.getElementById('product-customization-form').addEventListener('submit', event => {
        event.preventDefault();
        const next = SavoraState.addCartLine(
            SavoraState.load(),
            item,
            quantity,
            selectedOptions(),
            document.getElementById('special-notes').value.trim()
        );
        SavoraState.persist(next);
        SavoraUI.refreshChrome();
        SavoraUI.announce(`Added ${quantity} × ${item.name} to cart.`);
    });

    loading.hidden = true;
    content.hidden = false;
    updateTotal();
});
</script>

<?php include 'components/customer_footer.php'; ?>
