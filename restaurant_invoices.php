<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-documents-page>
    <header class="restaurant-page-heading">
        <div><p class="restaurant-eyebrow">Financial documents</p><h1>Invoices &amp; Statements</h1><p>Inspect local order summaries and payout-preview statements.</p></div>
        <button type="button" class="restaurant-primary-action" data-monthly-statement>Prepare monthly statement preview</button>
    </header>
    <p class="restaurant-form-summary" data-document-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" aria-label="Document summary"><article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-file-lines restaurant-kpi-icon" aria-hidden="true"></i><div><p>Local documents</p><h2 data-document-count>0</h2></div></article><article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-dollar-sign restaurant-kpi-icon" aria-hidden="true"></i><div><p>Total invoiced</p><h2 data-document-total>$0.00</h2></div></article><article class="restaurant-card restaurant-kpi"><i class="fa-regular fa-clock restaurant-kpi-icon" aria-hidden="true"></i><div><p>Pending documents</p><h2>0</h2><small>Local data has no processing service</small></div></article></section>
    <div class="restaurant-documents-layout">
        <section class="restaurant-card" aria-labelledby="documents-list-title"><h2 id="documents-list-title">Documents</h2>
            <div class="restaurant-tabs" data-document-tabs role="tablist" aria-label="Financial document type"><button type="button" role="tab" aria-selected="true" data-document-tab="invoices">Order invoices</button><button type="button" role="tab" aria-selected="false" data-document-tab="statements">Payout statements</button><button type="button" role="tab" aria-selected="false" data-document-tab="tax">Tax documents</button></div>
            <form class="restaurant-finance-filters" data-document-filters><div class="restaurant-field"><label for="document-date-range">From date</label><input id="document-date-range" name="document-date-range" type="date"></div><div class="restaurant-field"><label for="document-search">Search invoice or order</label><input id="document-search" name="document-search" type="search"></div><div class="restaurant-field"><label for="document-status">Document status</label><select id="document-status" name="document-status"><option value="all">All document statuses</option><option value="available">Available</option></select></div></form>
            <div class="restaurant-table-wrap"><table class="restaurant-table" data-document-table><caption>Local demo financial documents</caption><thead><tr><th scope="col">Document</th><th scope="col">Order or period</th><th scope="col">Issued</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Actions</th></tr></thead><tbody data-document-table-body></tbody></table></div>
            <div class="restaurant-finance-cards" data-document-cards aria-live="polite"></div>
            <p class="restaurant-empty" data-tax-document-empty hidden>No tax documents are available in this local demo. Tax records are not generated here.</p>
        </section>
        <aside class="restaurant-card restaurant-document-preview" data-document-preview aria-labelledby="document-preview-title"><h2 id="document-preview-title">Select a document</h2><p class="restaurant-field-hint">Choose an order invoice or local payout-preview statement to inspect it.</p></aside>
    </div>
</main>
<script defer src="js/restaurant_finance.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
