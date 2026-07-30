<?php include 'components/customer_header.php'; ?>

<main class="customer-shell wallet-page">
    <header class="wallet-title-row">
        <div class="page-title-block">
            <p class="eyebrow">Local demo wallet</p>
            <h1><span class="wallet-title-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>Savora Pay</h1>
            <p>Explore wallet activity saved only in this browser.</p>
        </div>
        <span class="status-chip"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>On this device</span>
    </header>

    <section class="wallet-balance-card" aria-labelledby="wallet-balance-title">
        <div>
            <p id="wallet-balance-title">Available balance</p>
            <p class="wallet-balance-value" id="wallet-page-balance" aria-live="polite" aria-atomic="true">$0.00</p>
            <p class="wallet-balance-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Demo funds only. No payment or bank transfer occurs.</p>
        </div>
        <button class="wallet-add-button" id="wallet-open-topup" type="button" aria-haspopup="dialog" aria-controls="wallet-topup-dialog">
            <i class="fa-solid fa-circle-plus" aria-hidden="true"></i>Add demo funds
        </button>
    </section>

    <div class="wallet-layout">
        <section class="surface-card wallet-activity-card" aria-labelledby="wallet-activity-title">
            <div class="section-heading-row">
                <div>
                    <p class="eyebrow">Money in and out</p>
                    <h2 id="wallet-activity-title">Recent activity</h2>
                </div>
                <span class="wallet-activity-count" id="wallet-activity-count">0 transactions</span>
            </div>
            <ol class="wallet-transaction-list" id="wallet-transaction-list"></ol>
        </section>

        <aside class="wallet-side-stack" aria-label="Savora Pay information">
            <section class="surface-card wallet-info-card" aria-labelledby="wallet-demo-title">
                <span class="wallet-info-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span>
                <div>
                    <h2 id="wallet-demo-title">Local demo balance</h2>
                    <p>Top-ups and activity remain in this browser. They are not server-confirmed payments and do not represent real money.</p>
                </div>
            </section>
            <section class="surface-card wallet-info-card" aria-labelledby="wallet-security-title">
                <span class="wallet-info-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                <div>
                    <h2 id="wallet-security-title">Designed for clarity</h2>
                    <p>Every activity row includes Credit or Debit text, so meaning never depends on color alone.</p>
                </div>
            </section>
        </aside>
    </div>
</main>

<section id="wallet-topup-dialog" class="dialog" role="dialog" aria-modal="true" aria-labelledby="wallet-topup-title" hidden>
    <div class="dialog-scrim" data-close-dialog="wallet-topup-dialog"></div>
    <div class="dialog-panel wallet-topup-panel" role="document">
        <header class="modal-header">
            <div>
                <p class="eyebrow">Local demo only</p>
                <h2 id="wallet-topup-title">Add demo funds</h2>
            </div>
            <button class="icon-button" type="button" aria-label="Close top-up dialog" data-close-dialog="wallet-topup-dialog"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </header>
        <form id="wallet-topup-form" class="wallet-topup-form">
            <div class="form-field">
                <label for="wallet-topup-amount">Top-up amount</label>
                <div class="money-input-wrap"><span aria-hidden="true">$</span><input id="wallet-topup-amount" name="amount" type="number" inputmode="decimal" min="0.01" step="0.01" value="50" required aria-describedby="wallet-topup-help"></div>
                <p class="form-help" id="wallet-topup-help">Enter an amount greater than $0. This updates only your local demo state.</p>
            </div>
            <div class="wallet-topup-presets" aria-label="Suggested amounts">
                <button class="secondary-action" type="button" data-wallet-topup-preset="20">$20</button>
                <button class="secondary-action" type="button" data-wallet-topup-preset="50">$50</button>
                <button class="secondary-action" type="button" data-wallet-topup-preset="100">$100</button>
            </div>
            <div class="wallet-topup-actions">
                <button class="secondary-action" type="button" data-close-dialog="wallet-topup-dialog">Cancel</button>
                <button class="primary-action" type="submit">Add demo funds</button>
            </div>
        </form>
    </div>
</section>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const balanceNode = document.getElementById('wallet-page-balance');
        const transactionList = document.getElementById('wallet-transaction-list');
        const transactionCount = document.getElementById('wallet-activity-count');
        const openButton = document.getElementById('wallet-open-topup');
        const topupForm = document.getElementById('wallet-topup-form');
        const amountInput = document.getElementById('wallet-topup-amount');

        function money(value) {
            return `$${Number(value || 0).toFixed(2)}`;
        }

        function transactionDate(value) {
            const date = new Date(value);
            return Number.isNaN(date.getTime()) ? 'Date unavailable' : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
        }

        function renderWallet() {
            const state = SavoraState.load();
            const balance = Number(state.wallet.balance);
            const transactions = Array.isArray(state.wallet.transactions) ? state.wallet.transactions : [];
            balanceNode.textContent = money(Number.isFinite(balance) ? balance : 0);
            transactionCount.textContent = `${transactions.length} ${transactions.length === 1 ? 'transaction' : 'transactions'}`;

            const fragment = document.createDocumentFragment();
            if (!transactions.length) {
                fragment.append(SavoraUI.el('li', { className: 'wallet-empty-state' }, [
                    SavoraUI.el('span', { className: 'empty-state-icon', 'aria-hidden': 'true' }, SavoraUI.el('i', { className: 'fa-solid fa-receipt' })),
                    SavoraUI.el('h3', {}, 'No activity yet'),
                    SavoraUI.el('p', {}, 'Add demo funds to create your first local Credit transaction.')
                ]));
            } else {
                transactions.forEach(function (transaction) {
                    const kind = transaction.kind === 'credit' ? 'credit' : 'debit';
                    const kindLabel = kind === 'credit' ? 'Credit' : 'Debit';
                    const icon = kind === 'credit' ? 'fa-arrow-down' : 'fa-arrow-up';
                    const sign = kind === 'credit' ? '+' : '-';
                    fragment.append(SavoraUI.el('li', { className: `wallet-transaction wallet-transaction-${kind}` }, [
                        SavoraUI.el('span', { className: 'wallet-transaction-icon', 'aria-hidden': 'true' }, SavoraUI.el('i', { className: `fa-solid ${icon}` })),
                        SavoraUI.el('div', { className: 'wallet-transaction-copy' }, [
                            SavoraUI.el('h3', {}, transaction.label || 'Savora Pay activity'),
                            SavoraUI.el('p', {}, transactionDate(transaction.createdAt))
                        ]),
                        SavoraUI.el('div', { className: 'wallet-transaction-value' }, [
                            SavoraUI.el('strong', {}, `${sign}${money(transaction.amount)}`),
                            SavoraUI.el('span', { className: `wallet-kind-label wallet-kind-${kind}` }, kindLabel)
                        ])
                    ]));
                });
            }
            transactionList.replaceChildren(fragment);
        }

        openButton.addEventListener('click', function () {
            SavoraUI.openDialog('wallet-topup-dialog', openButton);
        });

        document.querySelectorAll('[data-wallet-topup-preset]').forEach(function (button) {
            button.addEventListener('click', function () {
                amountInput.value = button.dataset.walletTopupPreset;
                amountInput.setCustomValidity('');
                amountInput.focus();
            });
        });

        amountInput.addEventListener('input', function () {
            amountInput.setCustomValidity('');
        });

        topupForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const amount = Number(amountInput.value);
            if (!Number.isFinite(amount) || amount <= 0) {
                amountInput.setCustomValidity('Enter an amount greater than $0.');
                amountInput.reportValidity();
                SavoraUI.showToast('Enter a top-up amount greater than $0.');
                return;
            }

            try {
                const state = SavoraState.topUpWallet(SavoraState.load(), amount);
                SavoraState.persist(state);
                SavoraUI.refreshChrome();
                renderWallet();
                SavoraUI.closeDialog('wallet-topup-dialog');
                SavoraUI.showToast(`${money(amount)} added to the local demo wallet.`);
            } catch (error) {
                SavoraUI.showToast(error.message || 'Unable to add demo funds.');
            }
        });

        renderWallet();
    });
</script>

<?php include 'components/customer_footer.php'; ?>
