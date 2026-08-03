<?php
declare(strict_types=1);
$admin_page_title = 'Restaurants & Approvals';
require_once __DIR__ . '/components/admin_header.php';
$data = admin_page_data($conn, 'restaurants', ['id' => $_GET['id'] ?? 0]);
$selected = $data['selected'];
?>
<main class="admin-main" id="admin-main" tabindex="-1">
<header class="admin-page-heading"><div><p class="admin-eyebrow">PARTNER GOVERNANCE</p><h1>Restaurants &amp; Approvals</h1><p>Review Restaurant identity and business details before activating an owner account.</p></div></header>
<nav class="admin-tabs" aria-label="Restaurant sections"><a class="is-active" href="admin_restaurants.php">Pending Applications</a><a href="admin_restaurants.php?tab=active">Active Restaurants</a><a href="admin_restaurants.php?tab=review">Needs Review</a><a href="admin_restaurants.php?tab=suspended">Suspended</a></nav>
<section class="admin-kpi-grid">
<article class="admin-kpi-card"><div class="admin-kpi-card__label">Pending Applications</div><strong><?= admin_escape($data['summary']['pending'] ?? 0) ?></strong><small>Awaiting Admin decision</small></article>
<article class="admin-kpi-card"><div class="admin-kpi-card__label">Active Restaurants</div><strong><?= admin_escape(count($data['restaurants'])) ?></strong><small>Approved storefronts</small></article>
<article class="admin-kpi-card"><div class="admin-kpi-card__label">Needs Review</div><strong><?= admin_escape($data['summary']['needs_review'] ?? 0) ?></strong><small>Higher-risk applications</small></article>
<article class="admin-kpi-card admin-kpi-card--dark"><div class="admin-kpi-card__label">Average Queue Age</div><strong><?= admin_escape(round((float) ($data['summary']['average_age_hours'] ?? 0))) ?>h</strong><small>From application submission</small></article>
</section>
<div class="admin-approval-layout">
<section class="admin-card admin-card--flush"><header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">ONBOARDING</span><h2>Application Queue</h2></div></header><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Restaurant</th><th>Owner</th><th>City</th><th>Risk</th><th>Submitted</th><th></th></tr></thead><tbody><?php foreach ($data['applications'] as $app): ?><tr><td><strong><?= admin_escape($app['restaurant_name']) ?></strong><small class="admin-cell-note"><?= admin_escape($app['reference_code']) ?></small></td><td><?= admin_escape($app['owner_name']) ?></td><td><?= admin_escape($app['city']) ?></td><td><span class="admin-risk admin-risk--<?= admin_escape($app['risk_level']) ?>"><?= admin_escape($app['risk_level']) ?></span></td><td><?= admin_escape(date('M j, H:i', strtotime((string) $app['submitted_at']))) ?></td><td><a class="admin-row-action" href="admin_restaurants.php?id=<?= admin_escape($app['id']) ?>" aria-label="Review <?= admin_escape($app['restaurant_name']) ?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a></td></tr><?php endforeach; ?></tbody></table></div></section>
<aside class="admin-detail-panel admin-approval-panel">
<?php if ($selected): ?>
<header><span class="admin-eyebrow">APPLICATION <?= admin_escape($selected['reference_code']) ?></span><h2><?= admin_escape($selected['restaurant_name']) ?></h2><p><?= admin_escape($selected['owner_name']) ?> · <?= admin_escape($selected['city']) ?></p></header>
<?php if (($data['logo']['public_id'] ?? '') !== ''): ?><img src="media.php?asset=<?= admin_escape($data['logo']['public_id']) ?>" alt="Restaurant logo submitted by <?= admin_escape($selected['restaurant_name']) ?>" style="max-width:128px;max-height:128px;border-radius:16px;object-fit:cover"><?php endif; ?>
<dl class="admin-detail-list">
<div><dt>Owner</dt><dd><?= admin_escape($selected['owner_name']) ?></dd></div><div><dt>Username</dt><dd><?= admin_escape($selected['username']) ?></dd></div>
<div><dt>Email</dt><dd><?= admin_escape($selected['owner_email']) ?></dd></div><div><dt>Phone</dt><dd><?= admin_escape($selected['owner_phone']) ?></dd></div>
<div><dt>Restaurant name</dt><dd><?= admin_escape($selected['restaurant_name']) ?></dd></div><div><dt>Description</dt><dd><?= admin_escape($selected['description'] ?: 'Not provided') ?></dd></div>
<div><dt>Cuisine</dt><dd><?= admin_escape($selected['cuisine']) ?></dd></div><div><dt>Address</dt><dd><?= admin_escape($selected['address']) ?></dd></div>
<div><dt>City</dt><dd><?= admin_escape($selected['city']) ?></dd></div><div><dt>Restaurant phone</dt><dd><?= admin_escape($selected['restaurant_phone']) ?></dd></div>
<div><dt>Opening hours</dt><dd><?= admin_escape(substr((string) $selected['opens_at'], 0, 5)) ?>–<?= admin_escape(substr((string) $selected['closes_at'], 0, 5)) ?></dd></div><div><dt>Status</dt><dd><?= admin_escape(ucfirst((string) $selected['status'])) ?></dd></div>
</dl>
<label class="admin-reason-box"><span>Reviewer note</span><textarea rows="3" data-admin-reviewer-note placeholder="Required when rejecting an application"><?= admin_escape($selected['reviewer_note'] ?? '') ?></textarea></label>
<div class="admin-detail-actions"><button class="admin-button admin-button--danger" type="button" data-admin-partner-action="reject_restaurant" data-application-id="<?= admin_escape($selected['id']) ?>" data-version="<?= admin_escape($selected['version']) ?>">Reject Application</button><button class="admin-button admin-button--primary" type="button" data-admin-partner-action="approve_restaurant" data-application-id="<?= admin_escape($selected['id']) ?>" data-version="<?= admin_escape($selected['version']) ?>">Approve Restaurant</button></div>
<p class="admin-integrity-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i>Approval creates one active Restaurant account, seven weekly schedules, and publishes the submitted logo.</p>
<?php endif; ?>
</aside></div>
<section class="admin-card admin-card--flush admin-section-block"><header class="admin-card__header admin-card__header--padded"><div><span class="admin-eyebrow">APPROVED NETWORK</span><h2>Active Restaurants</h2></div></header><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Restaurant</th><th>Owner</th><th>City</th><th>Rating</th><th>Accepting</th><th>Status</th></tr></thead><tbody><?php foreach ($data['restaurants'] as $restaurant): ?><tr><td><strong><?= admin_escape($restaurant['name']) ?></strong></td><td><?= admin_escape($restaurant['owner_name']) ?></td><td><?= admin_escape($restaurant['city']) ?></td><td><?= admin_escape($restaurant['rating']) ?></td><td><?= (int) $restaurant['accepting_orders'] === 1 ? 'Yes' : 'Paused' ?></td><td><span class="admin-status admin-status--<?= admin_escape($restaurant['status']) ?>"><i aria-hidden="true"></i><?= admin_escape(ucfirst((string) $restaurant['status'])) ?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
</main>
<?php require_once __DIR__ . '/components/admin_footer.php'; ?>
