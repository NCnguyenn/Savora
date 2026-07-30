<?php include 'components/customer_header.php'; ?>

<main class="container checkout-page">
    <header class="checkout-title-row">
        <div class="page-title-block">
            <p class="eyebrow">Almost there</p>
            <h1>Checkout</h1>
            <p>Confirm delivery and payment for this local demo order.</p>
        </div>
        <ol class="checkout-steps" aria-label="Checkout progress">
            <li class="is-complete"><span><i class="fa-solid fa-check" aria-hidden="true"></i></span>Cart</li>
            <li class="is-current" aria-current="step"><span>2</span>Delivery</li>
            <li><span>3</span>Payment</li>
        </ol>
    </header>

    <form id="checkout-form" novalidate>
        <div id="checkout-page-layout" class="checkout-page-layout">
          <div class="checkout-form-column">
            <section class="surface-card checkout-section" aria-labelledby="delivery-heading">
                <div class="checkout-section-heading">
                    <span aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span>
                    <div>
                        <h2 id="delivery-heading">Delivery address</h2>
                        <p>Where should we bring your order?</p>
                    </div>
                </div>
                <div class="form-group">
                    <label for="checkout-address">Address</label>
                    <textarea id="checkout-address" name="address" rows="3" maxlength="300" required aria-describedby="checkout-address-help checkout-address-error" placeholder="Street, building and area"></textarea>
                    <p id="checkout-address-help" class="form-help">Include enough detail for a local demo delivery.</p>
                    <p id="checkout-address-error" class="field-error" aria-live="polite"></p>
                </div>
                <div class="form-group">
                    <label for="checkout-note">Delivery note <span>(optional)</span></label>
                    <textarea id="checkout-note" name="note" rows="3" maxlength="120" aria-describedby="checkout-note-count" placeholder="e.g. Leave at the front desk"></textarea>
                    <p id="checkout-note-count" class="form-help align-right">0/120</p>
                </div>
            </section>

            <section class="surface-card checkout-section" aria-labelledby="payment-heading">
                <div class="checkout-section-heading">
                    <span aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span>
                    <div>
                        <h2 id="payment-heading">Payment method</h2>
                        <p>Choose how to pay for this demo order.</p>
                    </div>
                </div>
                <fieldset class="payment-options">
                    <legend class="sr-only">Payment method</legend>
                    <div class="payment-option">
                        <input id="pay_savora" type="radio" name="payment" value="wallet" checked>
                        <label for="pay_savora">
                            <span class="payment-icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span>
                            <span><strong>Savora Pay</strong><small id="checkout-wallet-balance">Balance: $0.00</small></span>
                        </label>
                    </div>
                    <div class="payment-option">
                        <input id="pay_cash" type="radio" name="payment" value="cash">
                        <label for="pay_cash">
                            <span class="payment-icon" aria-hidden="true"><i class="fa-solid fa-money-bill-wave"></i></span>
                            <span><strong>Cash on delivery</strong><small>Pay the rider in this local demo</small></span>
                        </label>
                    </div>
                </fieldset>
            </section>

            <section class="surface-card checkout-section checkout-promo-section" aria-labelledby="checkout-promo-heading">
                <div>
                    <h2 id="checkout-promo-heading">Promotion code</h2>
                    <p>Demo codes can be recorded, but no discount engine is connected.</p>
                </div>
                <label for="checkout-promo">Promo code</label>
                <div class="promo-control">
                    <span aria-hidden="true"><i class="fa-solid fa-tag"></i></span>
                    <input id="checkout-promo" name="promo" type="text" autocomplete="off" maxlength="24" placeholder="Try LOCALDEMO">
                    <button id="checkout-apply-promo" class="secondary-action" type="button">Apply</button>
                </div>
                <p id="checkout-promo-status" class="form-help" aria-live="polite">No promo code applied. Demo totals remain unchanged.</p>
            </section>

            <div class="secure-payment-note">
                <span aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
                <div><strong>Your payment is secure</strong><p>This checkout saves only local demo data in your browser.</p></div>
            </div>
          </div>

        <aside class="order-summary checkout-order-summary" aria-labelledby="checkout-summary-title">
            <div class="section-heading-row">
                <h2 id="checkout-summary-title">Your order</h2>
                <span id="checkout-item-count">0 items</span>
            </div>
            <div id="checkout-items-list" class="checkout-items-list"></div>
            <dl class="summary-list">
                <div><dt>Subtotal</dt><dd id="checkout-subtotal">$0.00</dd></div>
                <div><dt>Delivery fee</dt><dd id="checkout-delivery">$0.00</dd></div>
                <div class="summary-total"><dt>Total</dt><dd id="checkout-total">$0.00</dd></div>
            </dl>
            <button id="place-order-button" class="primary-action summary-primary-action" type="submit">
                <span>Place order</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
            </button>
            <p class="secure-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> By placing your order, you confirm this is a local demo.</p>
            <div id="checkout-success" class="checkout-success" role="status" aria-live="polite" hidden>
                <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                <div><h2>Demo order placed locally</h2><p>Opening your order history…</p></div>
            </div>
          </aside>
        </div>
    </form>
</main>

<script>
    (() => {
        const DEMO_PROMO_CODES = new Set(['LOCALDEMO', 'SAVORA']);
        let appliedPromoCode = '';
        let isSubmitting = false;

        const money = value => `$${Number(value || 0).toFixed(2)}`;

        function showCheckoutToast(message) {
            if (window.SavoraUI && typeof window.SavoraUI.showToast === 'function') window.SavoraUI.showToast(message);
            else if (window.SavoraUI && typeof window.SavoraUI.announce === 'function') window.SavoraUI.announce(message);
        }

        function setSubmitting(pending) {
            const form = document.getElementById('checkout-form');
            const submitButton = document.getElementById('place-order-button');
            isSubmitting = pending;
            submitButton.disabled = pending;
            submitButton.setAttribute('aria-busy', pending ? 'true' : 'false');
            form.setAttribute('aria-busy', pending ? 'true' : 'false');
            submitButton.querySelector('span').textContent = pending ? 'Placing order…' : 'Place order';
        }

        function renderCheckout() {
            const state = window.SavoraState.load();
            const UI = window.SavoraUI;
            const list = document.getElementById('checkout-items-list');
            const fragment = document.createDocumentFragment();
            const itemCount = state.cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
            const subtotal = state.cart.reduce((sum, line) => sum + Number(line.unitPrice || 0) * Number(line.quantity || 0), 0);
            const delivery = state.cart.length ? Number(window.SavoraState.DELIVERY_FEE || 2) : 0;

            state.cart.forEach(line => {
                const catalogProduct = window.SavoraCatalog.products[String(line.id)] || null;
                const image = UI.el('img', { className: 'checkout-item-image', src: window.SavoraCatalog.imageFor(catalogProduct), alt: '' });
                fragment.append(UI.el('article', { className: 'checkout-item' }, [
                    image,
                    UI.el('div', {}, [
                        UI.el('h3', {}, line.name || 'Item'),
                        UI.el('p', {}, `Quantity ${line.quantity}`)
                    ]),
                    UI.el('strong', {}, money(Number(line.unitPrice || 0) * Number(line.quantity || 0)))
                ]));
            });
            list.replaceChildren(fragment);

            document.getElementById('checkout-item-count').textContent = itemCount === 1 ? '1 item' : `${itemCount} items`;
            document.getElementById('checkout-subtotal').textContent = money(subtotal);
            document.getElementById('checkout-delivery').textContent = money(delivery);
            document.getElementById('checkout-total').textContent = money(subtotal + delivery);
            document.getElementById('checkout-wallet-balance').textContent = `Balance: ${money(state.wallet.balance)}`;

            const address = document.getElementById('checkout-address');
            if (!address.value && state.profile.address) address.value = state.profile.address;

            if (!state.cart.length) {
                showCheckoutToast('Your cart is empty. Add an item before checkout.');
                window.location.replace('customer_cart.php');
            }
        }

        function applyCheckoutPromo(showToast = true) {
            const input = document.getElementById('checkout-promo');
            const status = document.getElementById('checkout-promo-status');
            const code = input.value.trim().toUpperCase();
            input.value = code;

            if (!code) {
                appliedPromoCode = '';
                status.textContent = 'No promo code applied. Demo totals remain unchanged.';
                if (showToast) showCheckoutToast('No promo code applied.');
                return true;
            }
            if (!DEMO_PROMO_CODES.has(code)) {
                appliedPromoCode = '';
                status.textContent = 'That demo code is unavailable. Totals remain unchanged.';
                if (showToast) showCheckoutToast('That demo promo code is unavailable.');
                return false;
            }

            appliedPromoCode = code;
            status.textContent = `${code} will be saved with your demo order. Totals stay unchanged.`;
            if (showToast) showCheckoutToast(`${code} saved. Demo totals stay unchanged.`);
            return true;
        }

        function validateCheckout() {
            const address = document.getElementById('checkout-address');
            const addressError = document.getElementById('checkout-address-error');
            const value = address.value.trim();
            address.setAttribute('aria-invalid', value ? 'false' : 'true');
            addressError.textContent = value ? '' : 'Enter a delivery address.';

            if (!value) {
                address.focus();
                throw new Error('Delivery address is required.');
            }
            if (!applyCheckoutPromo(false)) {
                document.getElementById('checkout-promo').focus();
                throw new Error('Use LOCALDEMO or SAVORA, or clear the promo code.');
            }
            return value;
        }

        function handleCheckoutSubmit(event) {
            event.preventDefault();
            if (isSubmitting) return;
            setSubmitting(true);

            try {
                const address = validateCheckout();
                const payment = document.querySelector('input[name="payment"]:checked');
                const result = window.SavoraState.placeDemoOrder(window.SavoraState.load(), {
                    address,
                    deliveryNote: document.getElementById('checkout-note').value,
                    paymentMethod: payment ? payment.value : 'cash',
                    promoCode: appliedPromoCode
                });
                window.SavoraState.persist(result.state);
                if (window.SavoraUI && typeof window.SavoraUI.refreshChrome === 'function') window.SavoraUI.refreshChrome();

                document.getElementById('checkout-success').hidden = false;
                document.getElementById('checkout-success').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                showCheckoutToast('Demo order placed locally.');
                window.setTimeout(() => window.location.assign('customer_history.php'), 850);
            } catch (error) {
                setSubmitting(false);
                showCheckoutToast(error.message || 'Unable to place your demo order.');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const promoFromCart = new URLSearchParams(window.location.search).get('promo');
            if (promoFromCart) {
                document.getElementById('checkout-promo').value = promoFromCart;
                applyCheckoutPromo(false);
            }
            document.getElementById('checkout-note').addEventListener('input', event => {
                document.getElementById('checkout-note-count').textContent = `${event.target.value.length}/120`;
            });
            document.getElementById('checkout-apply-promo').addEventListener('click', () => applyCheckoutPromo(true));
            document.getElementById('checkout-form').addEventListener('submit', handleCheckoutSubmit);
            renderCheckout();
        });
    })();
</script>

<?php include 'components/customer_footer.php'; ?>
