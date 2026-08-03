<?php include 'components/customer_header.php'; ?>
<script src="js/api_client.js"></script>

<main class="container checkout-page">
    <header class="checkout-title-row">
        <div class="page-title-block"><p class="eyebrow">Almost there</p><h1>Checkout</h1><p>Review a server-calculated quote before placing your order.</p></div>
        <ol class="checkout-steps" aria-label="Checkout progress"><li class="is-complete"><span><i class="fa-solid fa-check" aria-hidden="true"></i></span>Cart</li><li class="is-current" aria-current="step"><span>2</span>Delivery</li><li><span>3</span>Payment</li></ol>
    </header>

    <form id="checkout-form" novalidate>
        <div id="checkout-page-layout" class="checkout-page-layout">
            <div class="checkout-form-column">
                <section class="surface-card checkout-section" aria-labelledby="delivery-heading">
                    <div class="checkout-section-heading"><span aria-hidden="true"><i class="fa-solid fa-location-dot"></i></span><div><h2 id="delivery-heading">Saved delivery address</h2><p>Checkout uses an address already saved to your account.</p></div></div>
                    <div class="form-group"><label for="checkout-address">Address</label><textarea id="checkout-address" name="address" rows="3" maxlength="300" required readonly aria-describedby="checkout-address-help checkout-address-error"></textarea><p id="checkout-address-help" class="form-help">Update delivery addresses from your <a href="customer_profile.php">Profile</a>.</p><p id="checkout-address-error" class="field-error" aria-live="polite"></p></div>
                    <div class="form-group"><label for="checkout-note">Delivery note <span>(optional)</span></label><textarea id="checkout-note" name="note" rows="3" maxlength="120" aria-describedby="checkout-note-count" placeholder="e.g. Leave at the front desk"></textarea><p id="checkout-note-count" class="form-help align-right">0/120</p></div>
                </section>
                <section class="surface-card checkout-section" aria-labelledby="payment-heading">
                    <div class="checkout-section-heading"><span aria-hidden="true"><i class="fa-solid fa-credit-card"></i></span><div><h2 id="payment-heading">Payment method</h2><p>Wallet debits are server-atomic; cash remains pending until collected.</p></div></div>
                    <fieldset class="payment-options"><legend class="sr-only">Payment method</legend><div class="payment-option"><input id="pay_savora" type="radio" name="payment" value="wallet" checked><label for="pay_savora"><span class="payment-icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></span><span><strong>Savora Pay</strong><small id="checkout-wallet-balance">Balance: unavailable</small></span></label></div><div class="payment-option"><input id="pay_cash" type="radio" name="payment" value="cash"><label for="pay_cash"><span class="payment-icon" aria-hidden="true"><i class="fa-solid fa-money-bill-wave"></i></span><span><strong>Cash on delivery</strong><small>Payment remains pending on the server</small></span></label></div></fieldset>
                </section>
                <section class="surface-card checkout-section checkout-promo-section" aria-labelledby="checkout-promo-heading"><div><h2 id="checkout-promo-heading">Promotion code</h2><p>The server validates eligibility and calculates any discount.</p></div><label for="checkout-promo">Promo code</label><div class="promo-control"><span aria-hidden="true"><i class="fa-solid fa-tag"></i></span><input id="checkout-promo" name="promo" type="text" autocomplete="off" maxlength="50" placeholder="Optional code"><button id="checkout-apply-promo" class="secondary-action" type="button">Apply</button></div><p id="checkout-promo-status" class="form-help" aria-live="polite">No promotion code applied.</p></section>
                <div class="secure-payment-note"><span aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span><div><strong>Server-authoritative checkout</strong><p>Only the returned server quote can be submitted.</p></div></div>
            </div>
            <aside class="order-summary checkout-order-summary" aria-labelledby="checkout-summary-title">
                <div class="section-heading-row"><h2 id="checkout-summary-title">Your quote</h2><span id="checkout-item-count">0 items</span></div>
                <div id="checkout-items-list" class="checkout-items-list"></div>
                <dl class="summary-list"><div><dt>Subtotal</dt><dd id="checkout-subtotal">—</dd></div><div><dt>Discount</dt><dd id="checkout-discount">—</dd></div><div><dt>Delivery fee</dt><dd id="checkout-delivery">—</dd></div><div class="summary-total"><dt>Total</dt><dd id="checkout-total">—</dd></div></dl>
                <button id="place-order-button" class="primary-action summary-primary-action" type="submit" disabled><span>Requesting quote…</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                <button id="cancel-checkout-button" class="secondary-action" type="button">Cancel checkout</button>
                <p id="checkout-feedback" class="form-help" role="status" aria-live="polite"></p>
                <div id="checkout-success" class="checkout-success" role="status" aria-live="polite" hidden><i class="fa-solid fa-circle-check" aria-hidden="true"></i><div><h2>Order placed on the server</h2><p>Opening your order history…</p></div></div>
            </aside>
        </div>
    </form>
</main>

<script>
document.addEventListener('DOMContentLoaded', async () => {
    const stateApi = window.SavoraState; const catalog = window.SavoraCatalog; const ui = window.SavoraUI;
    const form = document.getElementById('checkout-form'); const address = document.getElementById('checkout-address'); const note = document.getElementById('checkout-note');
    const promo = document.getElementById('checkout-promo'); const promoStatus = document.getElementById('checkout-promo-status'); const feedback = document.getElementById('checkout-feedback');
    const submit = document.getElementById('place-order-button'); const cancel = document.getElementById('cancel-checkout-button'); const itemList = document.getElementById('checkout-items-list');
    let snapshot = null; let quote = null; let submitting = false;
    const money = value => `$${Number(value || 0).toFixed(2)}`;
    const cartPayload = () => stateApi.load().cart.map(line => ({ itemPublicId: String(line.id), quantity: Number(line.quantity || 0), optionPublicIds: (line.options || []).map(option => String(option.id)) }));
    const setText = (id, value) => { const node = document.getElementById(id); if (node) node.textContent = value; };
    function setBusy(value) { submitting = value; submit.disabled = value || !quote; submit.setAttribute('aria-busy', String(value)); cancel.disabled = value; submit.querySelector('span').textContent = value ? 'Placing order…' : quote ? 'Place order' : 'Requesting quote…'; }
    function renderQuote(data) {
        quote = data;
        const items = Array.isArray(data && data.items) ? data.items : [];
        setText('checkout-item-count', `${items.reduce((sum, item) => sum + Number(item.quantity || 0), 0)} items`);
        itemList.replaceChildren(...items.map(item => ui.el('article', { className: 'checkout-item' }, [ui.el('div', {}, [ui.el('h3', {}, item.name || item.itemPublicId), ui.el('p', {}, `Quantity ${item.quantity}`)]), ui.el('strong', {}, money(item.lineTotal))])));
        setText('checkout-subtotal', money(data.subtotal)); setText('checkout-discount', data.discount ? `-${money(data.discount)}` : money(0)); setText('checkout-delivery', money(data.deliveryFee)); setText('checkout-total', money(data.total));
        submit.disabled = submitting; submit.querySelector('span').textContent = 'Place order';
    }
    async function requestQuote() {
        quote = null; submit.disabled = true; submit.querySelector('span').textContent = 'Requesting quote…'; SavoraApi.clearIntentKey('customer-place-order');
        if (!snapshot || !snapshot.addresses || !snapshot.addresses.length) throw new Error('Save a delivery address in Profile before checkout.');
        const selectedAddress = snapshot.addresses.find(item => item.isDefault) || snapshot.addresses[0];
        if (!selectedAddress || !selectedAddress.publicId) throw new Error('A server address is required before checkout.');
        const result = await SavoraApi.post('api/checkout.php', { action: 'quote', payload: { items: cartPayload(), addressPublicId: selectedAddress.publicId, promotionCode: promo.value.trim() || null } }, SavoraApi.intentKey('customer-checkout-quote'));
        renderQuote(result); SavoraApi.clearIntentKey('customer-checkout-quote');
        promoStatus.textContent = promo.value.trim() ? 'Promotion eligibility and totals were confirmed by the server.' : 'Server quote refreshed.';
    }
    try {
        snapshot = await SavoraApi.get('api/profile.php');
        const selectedAddress = (snapshot.addresses || []).find(item => item.isDefault) || (snapshot.addresses || [])[0];
        if (selectedAddress) address.value = [selectedAddress.addressLine1, selectedAddress.city].filter(Boolean).join(', ');
        setText('checkout-wallet-balance', `Balance: ${money(snapshot.wallet && snapshot.wallet.balance)}`);
        if (!stateApi.load().cart.length) { ui.showToast('Your cart is empty.'); window.location.replace('customer_cart.php'); return; }
        await requestQuote();
    } catch (error) { feedback.textContent = error.message || 'Checkout is unavailable.'; submit.disabled = true; }
    promo.addEventListener('input', () => { quote = null; submit.disabled = true; });
    document.getElementById('checkout-apply-promo').addEventListener('click', async () => { try { await requestQuote(); } catch (error) { feedback.textContent = error.message || 'Quote was not refreshed.'; } });
    note.addEventListener('input', event => setText('checkout-note-count', `${event.target.value.length}/120`));
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (submitting || !quote) return; setBusy(true);
        try {
            const payment = document.querySelector('input[name="payment"]:checked');
            const result = await SavoraApi.post('api/checkout.php', { action: 'place_order', payload: { quoteId: quote.quoteId, paymentMethod: payment ? payment.value : 'cash', deliveryNote: note.value.trim() } }, SavoraApi.intentKey('customer-place-order'));
            stateApi.persist({ cart: [] }); SavoraApi.clearIntentKey('customer-place-order');
            document.getElementById('checkout-success').hidden = false; ui.showToast(`Order ${result.referenceCode} placed.`); window.setTimeout(() => window.location.assign('customer_history.php'), 850);
        } catch (error) { setBusy(false); feedback.textContent = error.message || 'Order was not placed.'; }
    });
    cancel.addEventListener('click', () => { if (submitting) return; SavoraApi.clearIntentKey('customer-checkout-quote'); SavoraApi.clearIntentKey('customer-place-order'); window.location.assign('customer_cart.php'); });
});
</script>

<?php include 'components/customer_footer.php'; ?>
