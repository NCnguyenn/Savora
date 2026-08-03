<?php include 'components/customer_header.php'; ?>

<main class="customer-shell wallet-page">
    <header class="wallet-title-row">
        <div class="page-title-block">
            <p class="eyebrow">Server-backed wallet</p>
            <h1><span class="wallet-title-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span>Savora Pay</h1>
            <p>Your balance and transaction history are read from your authenticated account.</p>
        </div>
        <span class="status-chip"><i class="fa-solid fa-database" aria-hidden="true"></i>Server confirmed</span>
    </header>

    <section class="wallet-balance-card" aria-labelledby="wallet-balance-title">
        <div>
            <p id="wallet-balance-title">Available balance</p>
            <p class="wallet-balance-value" id="wallet-page-balance" aria-live="polite" aria-atomic="true">$0.00</p>
            <p class="wallet-balance-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Only confirmed server transactions affect this balance.</p>
        </div>
        <button class="wallet-add-button" id="wallet-open-topup" type="button" disabled aria-describedby="wallet-topup-unavailable">
            <i class="fa-solid fa-circle-plus" aria-hidden="true"></i>Top-up unavailable
        </button>
    </section>

    <div class="wallet-layout">
        <section class="surface-card wallet-activity-card" aria-labelledby="wallet-activity-title">
            <div class="section-heading-row">
                <div><p class="eyebrow">Money in and out</p><h2 id="wallet-activity-title">Recent activity</h2></div>
                <span class="wallet-activity-count" id="wallet-activity-count">0 transactions</span>
            </div>
            <ol class="wallet-transaction-list" id="wallet-transaction-list"></ol>
        </section>
        <aside class="wallet-side-stack" aria-label="Savora Pay information">
            <section class="surface-card wallet-info-card" aria-labelledby="wallet-topup-title">
                <span class="wallet-info-icon"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></span>
                <div><h2 id="wallet-topup-title">Top-up provider</h2><p id="wallet-topup-unavailable">Top-ups remain disabled until a verified payment provider flow is configured.</p></div>
            </section>
            <section class="surface-card wallet-info-card" aria-labelledby="wallet-security-title">
                <span class="wallet-info-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                <div><h2 id="wallet-security-title">Authoritative balance</h2><p>Local storage cannot create, edit, or delete wallet money.</p></div>
            </section>
        </aside>
    </div>
</main>

<script src="js/api_client.js"></script>
<script>
document.addEventListener('DOMContentLoaded', async () => {
    const balanceNode = document.getElementById('wallet-page-balance');
    const transactionList = document.getElementById('wallet-transaction-list');
    const transactionCount = document.getElementById('wallet-activity-count');
    const money = value => `$${Number(value || 0).toFixed(2)}`;
    const dateLabel = value => { const date = new Date(value); return Number.isNaN(date.valueOf()) ? 'Date unavailable' : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }); };
    function render(wallet) {
        const transactions = Array.isArray(wallet && wallet.transactions) ? wallet.transactions : [];
        balanceNode.textContent = money(wallet && wallet.balance);
        transactionCount.textContent = `${transactions.length} ${transactions.length === 1 ? 'transaction' : 'transactions'}`;
        const rows = transactions.length ? transactions.map(transaction => {
            const kind = transaction.kind === 'credit' ? 'credit' : 'debit';
            const sign = kind === 'credit' ? '+' : '-';
            return SavoraUI.el('li', { className: `wallet-transaction wallet-transaction-${kind}` }, [
                SavoraUI.el('span', { className: 'wallet-transaction-icon', 'aria-hidden': 'true' }, SavoraUI.el('i', { className: `fa-solid ${kind === 'credit' ? 'fa-arrow-down' : 'fa-arrow-up'}` })),
                SavoraUI.el('div', { className: 'wallet-transaction-copy' }, [SavoraUI.el('h3', {}, transaction.label || 'Savora Pay activity'), SavoraUI.el('p', {}, dateLabel(transaction.createdAt))]),
                SavoraUI.el('div', { className: 'wallet-transaction-value' }, [SavoraUI.el('strong', {}, `${sign}${money(transaction.amount)}`), SavoraUI.el('span', { className: `wallet-kind-label wallet-kind-${kind}` }, kind === 'credit' ? 'Credit' : 'Debit')])
            ]);
        }) : [SavoraUI.el('li', { className: 'wallet-empty-state' }, [SavoraUI.el('h3', {}, 'No server activity yet'), SavoraUI.el('p', {}, 'Completed wallet activity will appear here.')])];
        transactionList.replaceChildren(...rows);
    }
    try { const snapshot = await SavoraApi.get('api/profile.php'); render(snapshot.wallet || {}); }
    catch (error) { balanceNode.textContent = 'Unavailable'; transactionList.replaceChildren(SavoraUI.el('li', { className: 'wallet-empty-state', role: 'status' }, error.message || 'Wallet is unavailable.')); }
});
</script>

<?php include 'components/customer_footer.php'; ?>
