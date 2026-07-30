<?php include 'components/customer_header.php'; ?>

<main class="container cart-page">
    <nav class="page-breadcrumb" aria-label="Breadcrumb">
        <a href="customer_dashboard.php">Home</a>
        <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
        <span aria-current="page">Your cart</span>
    </nav>

    <header class="page-title-block">
        <p class="eyebrow">Your order</p>
        <h1>Your cart</h1>
        <p>Review your items and proceed when everything looks right.</p>
    </header>

    <div id="cart-page-layout" class="cart-page-layout">
        <div class="cart-page-main">
            <section class="surface-card cart-lines-card" aria-labelledby="cart-lines-title">
                <div class="section-heading-row">
                    <div>
                        <h2 id="cart-lines-title">Your items</h2>
                        <p id="cart-item-count">Loading your cart…</p>
                    </div>
                    <a class="text-action" href="customer_dashboard.php">Add more items</a>
                </div>
                <div id="full-cart-items" class="full-cart-items" aria-live="polite"></div>
            </section>

            <section class="cart-discover-card" aria-labelledby="cart-discover-title">
                <span class="cart-discover-icon" aria-hidden="true"><i class="fa-solid fa-seedling"></i></span>
                <div>
                    <h2 id="cart-discover-title">Craving something else?</h2>
                    <p>Add another local favorite before checkout.</p>
                </div>
                <a class="secondary-action" href="customer_dashboard.php">Discover food <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </section>
        </div>

        <aside class="order-summary cart-order-summary" aria-labelledby="cart-summary-title">
            <h2 id="cart-summary-title">Order summary</h2>
            <dl class="summary-list">
                <div>
                    <dt id="page-cart-subtotal-label">Subtotal</dt>
                    <dd id="page-cart-subtotal">$0.00</dd>
                </div>
                <div>
                    <dt>Delivery fee</dt>
                    <dd id="page-cart-delivery">$0.00</dd>
                </div>
                <div class="summary-total">
                    <dt>Total</dt>
                    <dd id="page-cart-total">$0.00</dd>
                </div>
            </dl>

            <form id="cart-promo-form" class="promo-form">
                <label for="cart-promo">Promo code</label>
                <div class="promo-control">
                    <span aria-hidden="true"><i class="fa-solid fa-tag"></i></span>
                    <input id="cart-promo" name="promo" type="text" autocomplete="off" maxlength="24" placeholder="Enter demo code">
                    <button class="secondary-action" type="submit">Apply</button>
                </div>
                <p id="cart-promo-status" class="form-help" aria-live="polite">Try LOCALDEMO or SAVORA. Demo totals remain unchanged.</p>
            </form>

            <a id="btn-checkout" class="primary-action summary-primary-action" href="customer_checkout.php">
                Continue to checkout <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </a>
            <p class="secure-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure local demo checkout</p>
        </aside>
    </div>
</main>

<script>
    (() => {
        const DEMO_PROMO_CODES = new Set(['LOCALDEMO', 'SAVORA']);
        let appliedPromoCode = '';

        const money = value => `$${Number(value || 0).toFixed(2)}`;
        const stateApi = () => window.SavoraState;
        const uiApi = () => window.SavoraUI;

        function showCartToast(message) {
            const ui = uiApi();
            if (ui && typeof ui.showToast === 'function') ui.showToast(message);
            else if (ui && typeof ui.announce === 'function') ui.announce(message);
        }

        function createEmptyCart(el) {
            return el('div', { className: 'empty-state full-cart-empty' }, [
                el('span', { className: 'empty-state-icon', 'aria-hidden': 'true' }, [
                    el('i', { className: 'fa-solid fa-basket-shopping' })
                ]),
                el('h2', {}, 'Your cart is empty'),
                el('p', {}, 'Explore nearby favorites and add something delicious.'),
                el('a', { className: 'primary-action', href: 'customer_dashboard.php' }, 'Discover food')
            ]);
        }

        function createLine(line, el) {
            const name = line.name || 'Item';
            const lineTotal = Number(line.unitPrice || 0) * Number(line.quantity || 0);
            const catalogProduct = window.SavoraCatalog.products[String(line.id)] || null;
            const image = el('img', { className: 'full-cart-line-image', src: window.SavoraCatalog.imageFor(catalogProduct), alt: '' });
            const quantityControl = el('div', { className: 'qty-control full-cart-quantity' }, [
                el('button', {
                    className: 'qty-btn',
                    type: 'button',
                    'aria-label': `Decrease quantity for ${name}`,
                    onclick: () => changeCartQuantity(line.lineId, -1)
                }, '−'),
                el('span', { 'aria-live': 'polite' }, String(line.quantity)),
                el('button', {
                    className: 'qty-btn',
                    type: 'button',
                    'aria-label': `Increase quantity for ${name}`,
                    onclick: () => changeCartQuantity(line.lineId, 1)
                }, '+')
            ]);
            quantityControl.setAttribute('role', 'group');
            quantityControl.setAttribute('aria-label', 'Item quantity');

            const customizationItems = [];
            (line.options || []).forEach(option => customizationItems.push(
                el('li', {}, `${option.label || 'Option'}${Number(option.price || 0) ? ` (+${money(option.price)})` : ''}`)
            ));
            if (line.note) customizationItems.push(el('li', {}, `Note: ${line.note}`));

            return el('article', { className: 'full-cart-line' }, [
                image,
                el('div', { className: 'full-cart-line-copy' }, [
                    el('p', { className: 'line-eyebrow' }, 'Local favorite'),
                    el('h3', {}, name),
                    customizationItems.length
                        ? el('div', { className: 'line-customizations' }, [
                            el('span', {}, 'Customizations'),
                            el('ul', {}, customizationItems)
                        ])
                        : el('p', { className: 'line-no-customizations' }, 'Standard preparation')
                ]),
                el('div', { className: 'full-cart-line-actions' }, [
                    el('strong', { className: 'full-cart-line-total' }, money(lineTotal)),
                    quantityControl,
                    el('button', {
                        className: 'remove-line-button',
                        type: 'button',
                        'aria-label': `Remove ${name}`,
                        onclick: () => removeCartLine(line.lineId)
                    }, [
                        el('i', { className: 'fa-regular fa-trash-can', 'aria-hidden': 'true' }),
                        'Remove'
                    ])
                ])
            ]);
        }

        function renderFullCart() {
            const State = stateApi();
            const UI = uiApi();
            const container = document.getElementById('full-cart-items');
            if (!State || !UI || !container) return;

            const state = window.SavoraState.load();
            const el = window.SavoraUI.el;
            const itemCount = state.cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
            const subtotal = state.cart.reduce((sum, line) => sum + Number(line.unitPrice || 0) * Number(line.quantity || 0), 0);
            const delivery = state.cart.length ? Number(State.DELIVERY_FEE || 2) : 0;
            const fragment = document.createDocumentFragment();

            if (!state.cart.length) fragment.append(createEmptyCart(el));
            else state.cart.forEach(line => fragment.append(createLine(line, el)));
            container.replaceChildren(fragment);

            document.getElementById('cart-item-count').textContent = itemCount === 1 ? '1 item' : `${itemCount} items`;
            document.getElementById('page-cart-subtotal-label').textContent = `Subtotal (${itemCount} ${itemCount === 1 ? 'item' : 'items'})`;
            document.getElementById('page-cart-subtotal').textContent = money(subtotal);
            document.getElementById('page-cart-delivery').textContent = money(delivery);
            document.getElementById('page-cart-total').textContent = money(subtotal + delivery);

            const checkoutLink = document.getElementById('btn-checkout');
            checkoutLink.hidden = state.cart.length === 0;
            updateCheckoutLink();
        }

        function persistAndRefresh(state) {
            stateApi().persist(state);
            renderFullCart();
            if (uiApi() && typeof uiApi().refreshChrome === 'function') uiApi().refreshChrome();
        }

        function changeCartQuantity(lineId, delta) {
            const State = stateApi();
            const before = State.load();
            const line = before.cart.find(item => item.lineId === lineId);
            const next = window.SavoraState.updateCartQuantity(before, lineId, delta);
            persistAndRefresh(next);
            if (line && !next.cart.some(item => item.lineId === lineId)) showCartToast(`${line.name || 'Item'} removed from your cart.`);
        }

        function removeCartLine(lineId) {
            const State = stateApi();
            const before = State.load();
            const line = before.cart.find(item => item.lineId === lineId);
            persistAndRefresh(window.SavoraState.removeCartLine(before, lineId));
            showCartToast(`${line && line.name ? line.name : 'Item'} removed from your cart.`);
        }

        function updateCheckoutLink() {
            const checkoutLink = document.getElementById('btn-checkout');
            if (!checkoutLink) return;
            const query = appliedPromoCode ? `?promo=${encodeURIComponent(appliedPromoCode)}` : '';
            checkoutLink.href = `customer_checkout.php${query}`;
        }

        function applyCartPromo(event) {
            event.preventDefault();
            const input = document.getElementById('cart-promo');
            const status = document.getElementById('cart-promo-status');
            const code = input.value.trim().toUpperCase();
            input.value = code;

            if (!code) {
                appliedPromoCode = '';
                status.textContent = 'No promo code applied. Demo totals remain unchanged.';
                showCartToast('No promo code applied.');
            } else if (DEMO_PROMO_CODES.has(code)) {
                appliedPromoCode = code;
                status.textContent = `${code} will be saved with your demo order. Totals stay unchanged.`;
                showCartToast(`${code} saved for checkout. Demo totals stay unchanged.`);
            } else {
                appliedPromoCode = '';
                status.textContent = 'That demo code is unavailable. Totals remain unchanged.';
                showCartToast('That demo promo code is unavailable.');
            }
            updateCheckoutLink();
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('cart-promo-form').addEventListener('submit', applyCartPromo);
            renderFullCart();
        });

        window.renderFullCart = renderFullCart;
    })();
</script>

<?php include 'components/customer_footer.php'; ?>
