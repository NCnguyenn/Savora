<?php require_once __DIR__ . '/components/restaurant_header.php'; ?>
<main id="restaurant-main" class="restaurant-main" data-reviews-page>
    <header class="restaurant-page-heading"><div><p class="restaurant-eyebrow">Customer experience</p><h1>Ratings &amp; Feedback</h1><p>Review server-verified feedback and respond with care.</p></div><button type="button" class="restaurant-secondary-action" data-export-reviews>Export feedback</button></header>
    <p class="restaurant-form-summary" data-review-feedback aria-live="polite" aria-atomic="true"></p>
    <section class="restaurant-kpi-grid" data-review-summary aria-label="Rating summary">
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-star restaurant-kpi-icon" aria-hidden="true"></i><div><p>Overall rating</p><h2 data-review-average>—</h2><small data-review-count>No verified reviews</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-utensils restaurant-kpi-icon" aria-hidden="true"></i><div><p>Food quality</p><h2 data-review-food>—</h2><small>Verified feedback</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-box restaurant-kpi-icon" aria-hidden="true"></i><div><p>Packaging</p><h2 data-review-packaging>—</h2><small>Verified feedback</small></div></article>
        <article class="restaurant-card restaurant-kpi"><i class="fa-solid fa-clock restaurant-kpi-icon" aria-hidden="true"></i><div><p>Preparation</p><h2 data-review-preparation>—</h2><small>Verified feedback</small></div></article>
    </section>
    <div class="restaurant-finance-layout">
        <section class="restaurant-card" aria-labelledby="review-list-title"><header class="restaurant-card-header"><div><h2 id="review-list-title">Verified reviews</h2><p class="restaurant-field-hint">Only delivered orders verified by the server appear here.</p></div></header>
            <form class="restaurant-finance-filters" data-review-filters><div class="restaurant-field"><label for="review-rating">Rating</label><select id="review-rating" name="review-rating"><option value="all">All ratings</option><option value="5">5 stars</option><option value="4">4 stars</option><option value="3">3 stars or less</option></select></div><div class="restaurant-field"><label for="review-status">Reply status</label><select id="review-status" name="review-status"><option value="all">All reviews</option><option value="needs-reply">Needs reply</option><option value="replied">Replied</option></select></div><div class="restaurant-field"><label for="review-search">Search reviews</label><input id="review-search" name="review-search" type="search" placeholder="Customer or feedback"></div></form>
            <div data-review-list aria-live="polite"></div>
        </section>
        <aside class="restaurant-card" data-review-context aria-labelledby="review-context-title"><h2 id="review-context-title">Select a review</h2><p class="restaurant-field-hint" data-review-order-context>Choose a verified review to see its order context.</p><form data-review-reply><div class="restaurant-field"><label for="review-public-reply">Public reply</label><textarea id="review-public-reply" name="review-public-reply" maxlength="300" aria-describedby="review-character-count"></textarea><p id="review-character-count" class="restaurant-field-hint" data-review-character-count>0 / 300</p><p class="restaurant-field-hint">Replies are visible to all customers in this local demo.</p></div><div class="restaurant-editor-actions"><button type="button" data-review-save-draft>Save draft</button><button type="button" class="restaurant-primary-action" data-review-publish>Publish reply</button></div></form></aside>
    </div>
    <section class="restaurant-card restaurant-low-stock" data-review-topics aria-labelledby="review-topics-title"><i class="fa-solid fa-comment-dots" aria-hidden="true"></i><div><h2 id="review-topics-title">Most mentioned locally</h2><p data-review-topics-list>No verified feedback topics yet.</p></div></section>
</main>
<script src="js/api_client.js"></script>
<script defer src="js/restaurant_insights.js"></script>
<?php require_once __DIR__ . '/components/restaurant_footer.php'; ?>
