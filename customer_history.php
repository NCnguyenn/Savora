<?php include 'components/customer_header.php'; ?>

<main class="container orders-page">
    <header class="page-title-block orders-title-row">
        <div>
            <p class="eyebrow">From checkout to doorstep</p>
            <h1>Your orders</h1>
            <p>Track active deliveries and revisit orders from your account.</p>
        </div>
    </header>

    <div class="order-filter-bar" aria-label="Filter orders by status">
        <button type="button" data-order-filter="all" aria-pressed="true">
            <i class="fa-solid fa-list" aria-hidden="true"></i><span>All</span>
        </button>
        <button type="button" data-order-filter="active" aria-pressed="false">
            <i class="fa-solid fa-circle" aria-hidden="true"></i><span>Active</span>
        </button>
        <button type="button" data-order-filter="completed" aria-pressed="false">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Completed</span>
        </button>
        <button type="button" data-order-filter="cancelled" aria-pressed="false">
            <i class="fa-solid fa-circle-xmark" aria-hidden="true"></i><span>Cancelled</span>
        </button>
    </div>

    <div class="orders-layout">
        <section class="orders-history-section" aria-labelledby="order-history-title">
            <div class="section-heading-row">
                <div>
                    <p class="eyebrow">Server history</p>
                    <h2 id="order-history-title">Order history</h2>
                </div>
                <span id="order-result-count" class="result-count" aria-live="polite"></span>
            </div>
            <ol id="order-history-list" class="order-history-list"></ol>
        </section>

        <aside class="surface-card active-order-panel" aria-labelledby="active-order-title">
            <div class="section-heading-row">
                <div>
                    <p class="eyebrow">Live status</p>
                    <h2 id="active-order-title">Active order</h2>
                </div>
                <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
            </div>
            <div id="active-order-details" aria-live="polite"></div>
        </aside>
    </div>
</main>

<script src="js/api_client.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const stateApi = window.SavoraState;
    const catalog = window.SavoraCatalog;
    const ui = window.SavoraUI;
    if (!stateApi || !catalog || !ui) return;

    const historyList = document.getElementById('order-history-list');
    const activeDetails = document.getElementById('active-order-details');
    const resultCount = document.getElementById('order-result-count');
    const activeStatuses = new Set(['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'on_the_way']);
    const params = new URLSearchParams(window.location.search);
    const requestedOrderId = params.get('order') || params.get('reorder');
    let selectedFilter = 'all';
    let requestedCard = null;
    let serverReviews = [];
    let serverOrders = [];

    const statusDetails = {
        pending: { label: 'Pending confirmation', icon: 'fa-hourglass-half' },
        confirmed: { label: 'Confirmed', icon: 'fa-circle-check' },
        preparing: { label: 'Preparing', icon: 'fa-kitchen-set' },
        ready_for_pickup: { label: 'Ready for pickup', icon: 'fa-bag-shopping' },
        on_the_way: { label: 'On the way', icon: 'fa-bicycle' },
        completed: { label: 'Completed', icon: 'fa-circle-check' },
        delivered: { label: 'Delivered', icon: 'fa-circle-check' },
        cancelled: { label: 'Cancelled', icon: 'fa-circle-xmark' },
        refunded: { label: 'Refunded', icon: 'fa-rotate-left' }
    };

    const money = value => `$${Number(value || 0).toFixed(2)}`;
    const productForLine = line => catalog.products[String(line.id)] || null;
    const driverForOrder = order => order && order.assignment ? order.assignment : null;
    const dispatchForOrder = order => order && order.dispatch ? order.dispatch : null;
    const restaurantForOrder = order => {
        const product = order.items.map(productForLine).find(Boolean);
        return product ? product.restaurant : 'Savora order';
    };
    const itemCount = order => order.items.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
    const formatDate = value => {
        const date = new Date(value);
        return Number.isNaN(date.getTime())
            ? 'Date unavailable'
            : new Intl.DateTimeFormat('en', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
    };
    const icon = className => ui.el('i', { className: `fa-solid ${className}`, 'aria-hidden': 'true' });
    const statusChip = status => {
        const details = statusDetails[status] || { label: 'Order update unavailable', icon: 'fa-circle-info' };
        return ui.el('span', { className: `order-status-chip is-${status}` }, [icon(details.icon), details.label]);
    };
    const emptyState = (title, message) => ui.el('div', { className: 'orders-empty-state', role: 'status' }, [
        ui.el('span', { className: 'empty-state-icon', 'aria-hidden': 'true' }, icon('fa-bag-shopping')),
        ui.el('h3', {}, title),
        ui.el('p', {}, message),
        ui.el('a', { className: 'primary-action', href: 'customer_dashboard.php' }, [icon('fa-compass'), 'Discover restaurants'])
    ]);

    function matchesFilter(order) {
        if (selectedFilter === 'all') return true;
        if (selectedFilter === 'active') return activeStatuses.has(order.status);
        return order.status === selectedFilter;
    }

    function exactProductSnapshot(line) {
        const catalogProduct = productForLine(line);
        const optionTotal = line.options.reduce((sum, option) => sum + Number(option.price || 0), 0);
        return {
            id: line.id,
            name: line.name || (catalogProduct && catalogProduct.name) || 'Saved item',
            image: catalog.imageFor(catalogProduct),
            price: Math.max(0, Number(line.unitPrice || 0) - optionTotal)
        };
    }

    function reorder(order, button) {
        if (!order.items.length) {
            ui.announce('This saved order has no items to reorder.');
            return;
        }
        let nextState = stateApi.load();
        order.items.forEach(line => {
            nextState = SavoraState.addCartLine(nextState, exactProductSnapshot(line), line.quantity, line.options, line.note);
        });
        stateApi.persist(nextState);
        ui.refreshChrome();
        ui.announce(`${itemCount(order)} ${itemCount(order) === 1 ? 'item' : 'items'} added to your cart with the saved configuration.`);
        button.textContent = 'Added to cart';
        window.setTimeout(() => {
            button.replaceChildren(icon('fa-rotate-right'), document.createTextNode('Reorder'));
        }, 1800);
    }

    function reviewForm(order) {
        const existing = serverReviews.find(review => review.orderReference === String(order.id));
        if (existing) return ui.el('p', { className: 'form-help' }, `Your ${existing.rating}-star review is saved.`);
        if (!['completed', 'delivered'].includes(order.status)) return null;
        const form = ui.el('form', { className: 'order-review-form', 'aria-label': `Review order ${order.id}` });
        const rating = ui.el('select', { name: 'rating', 'aria-label': 'Rating' }, [1, 2, 3, 4, 5].map(value => ui.el('option', { value: String(value), selected: value === 5 }, `${value} stars`)));
        const comment = ui.el('textarea', { name: 'comment', maxlength: '1000', 'aria-label': 'Review comment', placeholder: 'Share your experience' });
        const submit = ui.el('button', { type: 'submit', className: 'secondary-action' }, [icon('fa-star'), 'Submit review']);
        const status = ui.el('p', { className: 'form-help', role: 'status', 'aria-live': 'polite' });
        form.append(rating, comment, submit, status);
        form.addEventListener('submit', async event => {
            event.preventDefault(); const scope = `customer-review-${order.id}`; submit.disabled = true;
            try {
                await SavoraApi.post('api/reviews.php', { action: 'create_review', payload: { orderReference: String(order.id), rating: Number(rating.value), comment: comment.value.trim(), version: 0 } }, SavoraApi.intentKey(scope));
                serverReviews = await SavoraApi.get('api/reviews.php'); SavoraApi.clearIntentKey(scope); renderHistory(); ui.announce(`Review for order ${order.id} submitted.`);
            } catch (error) { status.textContent = error.message || 'Review was not submitted.'; submit.disabled = false; }
        });
        return form;
    }

    function orderCard(order) {
        const firstLine = order.items[0];
        const product = firstLine && productForLine(firstLine);
        const visual = ui.el('img', { className: 'order-card-image', src: catalog.imageFor(product), alt: '' });
        const summary = order.items.length
            ? order.items.map(line => `${line.quantity}× ${line.name || (productForLine(line) && productForLine(line).name) || 'Saved item'}`).join(', ')
            : 'No item details saved';
        const reorderButton = ui.el('button', {
            className: 'secondary-action order-reorder-button',
            type: 'button',
            'aria-label': `Reorder ${order.id}`,
            onclick: event => reorder(order, event.currentTarget)
        }, [icon('fa-rotate-right'), 'Reorder']);
        if (!order.items.length) reorderButton.disabled = true;
        const deliveryNote = order.deliveryNote
            ? ui.el('p', { className: 'order-delivery-note' }, [icon('fa-message'), 'Delivery note: ', order.deliveryNote])
            : null;

        const article = ui.el('article', {
            className: 'surface-card order-card',
            'data-customer-order-id': order.id,
            tabindex: order.id === requestedOrderId ? '-1' : null
        }, [
            visual,
            ui.el('div', { className: 'order-card-copy' }, [
                ui.el('p', { className: 'order-restaurant' }, restaurantForOrder(order)),
                ui.el('h3', {}, `Order ${order.id}`),
                ui.el('p', { className: 'order-item-summary' }, summary),
                ui.el('p', { className: 'order-date' }, [icon('fa-calendar-days'), formatDate(order.createdAt)]),
                deliveryNote
            ].filter(Boolean)),
            ui.el('div', { className: 'order-card-total' }, [
                ui.el('strong', {}, money(order.total)),
                statusChip(order.status)
            ]),
            ui.el('div', { className: 'order-card-actions' }, [reorderButton, reviewForm(order)].filter(Boolean))
        ]);
        if (order.id === requestedOrderId) requestedCard = article;
        return ui.el('li', {}, article);
    }

    function renderHistory() {
        const orders = serverOrders.filter(matchesFilter);
        resultCount.textContent = `${orders.length} ${orders.length === 1 ? 'order' : 'orders'}`;
        if (!orders.length) {
            historyList.replaceChildren(ui.el('li', {}, emptyState(
                selectedFilter === 'all' ? 'No orders yet' : `No ${selectedFilter.replace('_', ' ')} orders`,
                selectedFilter === 'all'
                    ? 'Orders placed from your account will appear here.'
                    : 'Try another status or discover something new.'
            )));
            return;
        }
        requestedCard = null;
        historyList.replaceChildren(...orders.map(orderCard));
        if (requestedCard) {
            requestedCard.focus();
            requestedCard.scrollIntoView({ block: 'center' });
        }
    }

    function renderActiveOrder() {
        const activeOrder = serverOrders.find(order => activeStatuses.has(order.status)) || null;
        if (!activeOrder) {
            activeDetails.replaceChildren(emptyState(
                'No active orders',
                'When you place an order, its live status will appear here.'
            ));
            return;
        }

        const steps = ['pending', 'confirmed', 'preparing', 'ready_for_pickup', 'on_the_way', 'completed'];
        const currentIndex = steps.indexOf(activeOrder.status);
        const progress = ui.el('ol', { className: 'order-progress', 'aria-label': 'Order progress' },
            steps.map((status, index) => {
                const details = statusDetails[status];
                const state = index < currentIndex ? 'is-complete' : index === currentIndex ? 'is-current' : 'is-upcoming';
                return ui.el('li', { className: state }, [
                    ui.el('span', { 'aria-hidden': 'true' }, icon(index <= currentIndex ? details.icon : 'fa-circle')),
                    ui.el('strong', {}, details.label),
                    ui.el('small', {}, index === currentIndex ? 'Current status' : index < currentIndex ? 'Complete' : 'Upcoming')
                ]);
            })
        );
        const address = activeOrder.address
            ? ui.el('p', { className: 'active-order-address' }, [icon('fa-location-dot'), activeOrder.address])
            : null;
        const deliveryNote = activeOrder.deliveryNote
            ? ui.el('p', { className: 'active-order-delivery-note' }, [icon('fa-message'), 'Delivery note: ', activeOrder.deliveryNote])
            : null;
        const delivery = driverForOrder(activeOrder);
        const dispatch = dispatchForOrder(activeOrder);
        const dispatchStatus = activeOrder.status === 'ready_for_pickup' && !delivery
            ? ui.el('section', { className: 'active-order-driver' }, [
                icon('fa-satellite-dish'),
                ui.el('div', {}, [
                    ui.el('strong', {}, dispatch && dispatch.status === 'offer_sent' ? 'Offer sent to a nearby driver' : 'Searching for a nearby driver'),
                    ui.el('p', {}, dispatch && dispatch.status === 'offer_sent'
                        ? 'This offer is exclusive for 30 seconds. Savora will continue the search if it is declined or expires.'
                        : 'The restaurant is ready and Savora is sending the delivery to eligible drivers.')
                ])
            ])
            : delivery
                ? ui.el('section', { className: 'active-order-driver' }, [
                    icon('fa-motorcycle'),
                    ui.el('div', {}, [
                        ui.el('strong', {}, 'Driver assigned'),
                        ui.el('p', {}, `${delivery.driverName || 'Savora driver'} · ${delivery.vehicle || 'Vehicle details unavailable'}`),
                        ui.el('small', {}, delivery.status === 'picked_up' ? 'Your order is on the way.' : `Delivery status: ${delivery.status.replace(/_/g, ' ')}`)
                    ])
                ])
                : null;
        activeDetails.replaceChildren(ui.el('article', { className: 'active-order-summary' }, [
            statusChip(activeOrder.status),
            ui.el('h3', {}, `Order ${activeOrder.id}`),
            ui.el('p', {}, `${itemCount(activeOrder)} ${itemCount(activeOrder) === 1 ? 'item' : 'items'} · ${money(activeOrder.total)}`),
            progress,
            dispatchStatus,
            address,
            deliveryNote,
            ui.el('a', { className: 'primary-action', href: '#order-history-title' }, [
                icon('fa-location-arrow'), 'Track order status'
            ])
        ].filter(Boolean)));
    }

    document.querySelectorAll('[data-order-filter]').forEach(button => {
        button.addEventListener('click', () => {
            selectedFilter = button.dataset.orderFilter;
            document.querySelectorAll('[data-order-filter]').forEach(option => {
                option.setAttribute('aria-pressed', String(option === button));
            });
            renderHistory();
        });
    });

    try {
        const orderData = await SavoraApi.get('api/orders.php');
        serverOrders = Array.isArray(orderData && orderData.orders) ? orderData.orders : [];
    } catch (_) { serverOrders = []; }
    try { serverReviews = await SavoraApi.get('api/reviews.php'); }
    catch (_) { serverReviews = []; }
    renderHistory();
    renderActiveOrder();
});
</script>

<?php include 'components/customer_footer.php'; ?>
