<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-documents-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Financial documents</p><h1>Invoices &amp; Statements</h1><p>Inspect server-generated invoices and authenticated payout statements.</p></div>
        <button type="button" class="restaurant-primary-action" data-monthly-statement>View current payout statement</button>
    </header>
    <p class="restaurant-form-summary" data-document-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" aria-label="Document summary"><article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-file-lines restaurant-kpi-icon" aria-hidden="true"></i><div><p>Server documents</p><h2 data-document-count>0</h2></div></article><article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Total invoiced</p><h2 data-document-total>$0.00</h2></div></article><article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-clock restaurant-kpi-icon" aria-hidden="true"></i><div><p>Pending documents</p><h2>0</h2><small>Server processing status</small></div></article></section>
    <div class="restaurant-documents-layout">
        <section class="restaurant-card" aria-labelledby="documents-list-title"><h2 id="documents-list-title">Documents</h2>
            <div class="restaurant-tabs" data-document-tabs role="tablist" aria-label="Financial document type">
                <button id="document-tab-invoices" type="button" role="tab" aria-controls="document-panel-invoices" aria-selected="true" tabindex="0" data-document-tab="invoices">Order invoices</button>
                <button id="document-tab-statements" type="button" role="tab" aria-controls="document-panel-statements" aria-selected="false" tabindex="-1" data-document-tab="statements">Payout statements</button>
                <button id="document-tab-tax" type="button" role="tab" aria-controls="document-panel-tax" aria-selected="false" tabindex="-1" data-document-tab="tax">Tax documents</button>
            </div>
            <section id="document-panel-invoices" role="tabpanel" aria-labelledby="document-tab-invoices" tabindex="0" data-document-panel="invoices">
                <form class="restaurant-finance-filters" data-document-filters><div class="restaurant-field"><label for="document-date-range">From date</label><input id="document-date-range" name="document-date-range" type="date"></div><div class="restaurant-field"><label for="document-search">Search invoice or order</label><input id="document-search" name="document-search" type="search"></div><div class="restaurant-field"><label for="document-status">Document status</label><select id="document-status" name="document-status"><option value="all">All document statuses</option><option value="available">Available</option></select></div></form>
                <div class="restaurant-table-wrap"><table class="restaurant-table" data-document-table><caption>Server financial documents</caption><thead><tr><th scope="col">Document</th><th scope="col">Order or period</th><th scope="col">Issued</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody data-document-table-body></tbody></table></div>
                <div class="restaurant-finance-cards" data-document-cards aria-live="polite"></div>
            </section>
            <section id="document-panel-statements" role="tabpanel" aria-labelledby="document-tab-statements" tabindex="0" data-document-panel="statements" hidden><h3>Payout statements</h3><p class="restaurant-field-hint">Payout statements are generated from the authenticated server ledger.</p><div data-statement-documents aria-live="polite"></div></section>
            <section id="document-panel-tax" role="tabpanel" aria-labelledby="document-tab-tax" tabindex="0" data-document-panel="tax" hidden><h3>Tax documents</h3><p class="restaurant-empty" data-tax-document-empty>No server tax document is available for this account.</p></section>
        </section>
        <aside class="restaurant-card restaurant-document-preview" data-document-preview aria-labelledby="document-preview-title"><h2 id="document-preview-title">Select a document</h2><p class="restaurant-field-hint">Choose a server-generated invoice or payout statement to inspect it.</p></aside>
    </div>
</main>
<script defer src="js/restaurant_finance.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
