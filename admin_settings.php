<?php
declare(strict_types=1);

$admin_page_title = 'Settings & Audit Log';
require_once __DIR__ . '/components/admin_header.php';
$settingsData = admin_page_data($conn, 'settings', ['q' => $_GET['q'] ?? '']);
$settings = [];
foreach ($settingsData['settings'] as $setting) {
    $settings[$setting['setting_key']] = $setting;
}
function admin_setting_record(array $settings, string $key): array
{
    return $settings[$key] ?? ['setting_key' => $key, 'setting_value' => '', 'version' => 1, 'updated_at' => null];
}
?>
<main class="admin-main" id="admin-main" tabindex="-1">
    <header class="admin-page-heading"><div><p class="admin-eyebrow">PLATFORM GOVERNANCE</p><h1>Settings &amp; Audit Log</h1><p>Configure guarded platform rules and inspect the immutable history of Admin interventions.</p></div><div class="admin-page-heading__actions"><span class="admin-security-badge"><i class="fa-solid fa-lock" aria-hidden="true"></i> Changes are versioned and audited</span></div></header>

    <nav class="admin-tabs" aria-label="Settings sections"><a class="is-active" href="#platform-settings">Platform Settings</a><a href="#notification-templates">Notification Templates</a><a href="#security-controls">Security</a><a href="#audit-log">Audit Log</a></nav>

    <section id="platform-settings" class="admin-section-block" aria-labelledby="platform-settings-title">
        <header class="admin-section-heading"><div><span class="admin-eyebrow">OPERATING RULES</span><h2 id="platform-settings-title">Platform Settings</h2><p>Updates apply to all four roles after validation.</p></div></header>
        <div class="admin-settings-grid">
            <?php $setting = admin_setting_record($settings, 'restaurant_acceptance_minutes'); ?>
            <form class="admin-setting-card" data-admin-action="update_setting"><header><span class="admin-setting-card__icon"><i class="fa-solid fa-store" aria-hidden="true"></i></span><div><h3>Order Timeouts</h3><p>Restaurant acceptance window</p></div></header><input type="hidden" name="setting_key" value="restaurant_acceptance_minutes"><input type="hidden" name="version" value="<?= admin_escape($setting['version']) ?>"><label><span>Accept within</span><span class="admin-input-unit"><input type="number" name="setting_value" min="1" max="30" value="<?= admin_escape($setting['setting_value']) ?>"><em>minutes</em></span></label><small data-admin-field-error aria-live="polite"></small><footer><span>Updated <?= admin_escape($setting['updated_at'] ? date('M j, H:i', strtotime((string) $setting['updated_at'])) : 'never') ?></span><button class="admin-button admin-button--ghost" type="submit">Save</button></footer></form>

            <?php $setting = admin_setting_record($settings, 'dispatch_offer_seconds'); ?>
            <form class="admin-setting-card" data-admin-action="update_setting"><header><span class="admin-setting-card__icon admin-setting-card__icon--blue"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></span><div><h3>Dispatch Rules</h3><p>Exclusive Driver offer duration</p></div></header><input type="hidden" name="setting_key" value="dispatch_offer_seconds"><input type="hidden" name="version" value="<?= admin_escape($setting['version']) ?>"><label><span>Offer expires after</span><span class="admin-input-unit"><input type="number" name="setting_value" min="10" max="120" value="<?= admin_escape($setting['setting_value']) ?>"><em>seconds</em></span></label><small data-admin-field-error aria-live="polite"></small><footer><span>Version <?= admin_escape($setting['version']) ?></span><button class="admin-button admin-button--ghost" type="submit">Save</button></footer></form>

            <?php $setting = admin_setting_record($settings, 'support_critical_minutes'); ?>
            <form class="admin-setting-card" data-admin-action="update_setting"><header><span class="admin-setting-card__icon admin-setting-card__icon--coral"><i class="fa-solid fa-life-ring" aria-hidden="true"></i></span><div><h3>Support SLA</h3><p>Urgent case response target</p></div></header><input type="hidden" name="setting_key" value="support_critical_minutes"><input type="hidden" name="version" value="<?= admin_escape($setting['version']) ?>"><label><span>Respond within</span><span class="admin-input-unit"><input type="number" name="setting_value" min="5" max="240" value="<?= admin_escape($setting['setting_value']) ?>"><em>minutes</em></span></label><small data-admin-field-error aria-live="polite"></small><footer><span>Version <?= admin_escape($setting['version']) ?></span><button class="admin-button admin-button--ghost" type="submit">Save</button></footer></form>

            <?php $setting = admin_setting_record($settings, 'maintenance_mode'); ?>
            <form class="admin-setting-card admin-setting-card--warning" data-admin-action="update_setting"><header><span class="admin-setting-card__icon admin-setting-card__icon--coral"><i class="fa-solid fa-screwdriver-wrench" aria-hidden="true"></i></span><div><h3>Maintenance Mode</h3><p>Pause new Customer orders safely</p></div></header><input type="hidden" name="setting_key" value="maintenance_mode"><input type="hidden" name="version" value="<?= admin_escape($setting['version']) ?>"><label class="admin-switch"><input type="checkbox" name="setting_value" value="1" <?= $setting['setting_value'] === '1' ? 'checked' : '' ?>><span aria-hidden="true"></span><b><?= $setting['setting_value'] === '1' ? 'Enabled' : 'Disabled' ?></b></label><small data-admin-field-error aria-live="polite"></small><footer><span>Requires confirmation</span><button class="admin-button admin-button--danger" type="submit">Update mode</button></footer></form>
        </div>
    </section>

    <section id="notification-templates" class="admin-section-block" aria-labelledby="notification-title">
        <header class="admin-section-heading"><div><span class="admin-eyebrow">CROSS-ROLE MESSAGING</span><h2 id="notification-title">Notification Templates</h2><p>Customer, Restaurant Owner and Driver updates use the same versioned templates.</p></div></header>
        <div class="admin-template-grid">
        <?php foreach ($settingsData['templates'] as $template): ?>
            <form class="admin-template-card" data-admin-action="update_notification_template">
                <input type="hidden" name="template_key" value="<?= admin_escape($template['template_key']) ?>"><input type="hidden" name="version" value="<?= admin_escape($template['version']) ?>">
                <header><div><span class="admin-eyebrow"><?= admin_escape(strtoupper((string) $template['audience'])) ?></span><h3><?= admin_escape($template['event_name']) ?></h3></div><label class="admin-switch admin-switch--compact"><input type="checkbox" name="enabled" value="1" <?= (int) $template['enabled'] === 1 ? 'checked' : '' ?>><span aria-hidden="true"></span><b class="sr-only">Enabled</b></label></header>
                <div class="admin-form-grid"><label><span>Channel</span><select name="channel"><option value="in_app" <?= $template['channel'] === 'in_app' ? 'selected' : '' ?>>In-app</option><option value="email" <?= $template['channel'] === 'email' ? 'selected' : '' ?>>Email</option><option value="sms" <?= $template['channel'] === 'sms' ? 'selected' : '' ?>>SMS</option></select></label><label class="admin-form-grid__wide"><span>Subject</span><input name="subject" maxlength="200" value="<?= admin_escape($template['subject']) ?>"></label><label class="admin-form-grid__wide"><span>Message template</span><textarea name="message_template" rows="3"><?= admin_escape($template['message_template']) ?></textarea></label></div>
                <small data-admin-field-error aria-live="polite"></small><footer><span>Version <?= admin_escape($template['version']) ?></span><button class="admin-button admin-button--ghost" type="submit">Save template</button></footer>
            </form>
        <?php endforeach; ?>
        </div>
    </section>

    <section id="security-controls" class="admin-section-block" aria-labelledby="security-title">
        <header class="admin-section-heading"><div><span class="admin-eyebrow">ACCESS PROTECTION</span><h2 id="security-title">Security</h2><p>One full-access Admin account with enforced operational safeguards.</p></div></header>
        <div class="admin-security-grid"><article class="admin-card"><span class="admin-setting-card__icon"><i class="fa-solid fa-key" aria-hidden="true"></i></span><div><h3>Administrator access</h3><p>Single full-access account. Sensitive actions require CSRF, idempotency and an audit reason.</p></div><span class="admin-status admin-status--active"><i aria-hidden="true"></i>Protected</span></article><article class="admin-card"><span class="admin-setting-card__icon admin-setting-card__icon--blue"><i class="fa-solid fa-laptop-file" aria-hidden="true"></i></span><div><h3>Active sessions</h3><p><?= admin_escape((int) ($settingsData['security']['active_sessions'] ?? 0)) ?> tracked sessions across <?= admin_escape((int) ($settingsData['security']['active_users'] ?? 0)) ?> users.</p></div><a class="admin-button admin-button--ghost" href="admin_accounts.php?tab=sessions">Review sessions</a></article><article class="admin-card"><span class="admin-setting-card__icon admin-setting-card__icon--coral"><i class="fa-solid fa-database" aria-hidden="true"></i></span><div><h3>Data integrity</h3><p>Financial ledger and audit records are append-only in the Admin interface.</p></div><button class="admin-button admin-button--ghost" type="button" disabled title="Immutable records cannot be edited">Immutable</button></article></div>
    </section>

    <section id="audit-log" class="admin-section-block" aria-labelledby="audit-title">
        <header class="admin-section-heading admin-section-heading--actions"><div><span class="admin-eyebrow">APPEND-ONLY HISTORY</span><h2 id="audit-title">Immutable Audit Log</h2><p>Every controlled intervention records actor, reason, result and a reference ID.</p></div><form class="admin-inline-search" method="get"><label class="sr-only" for="audit-search">Search audit records</label><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i><input id="audit-search" type="search" name="q" value="<?= admin_escape($_GET['q'] ?? '') ?>" placeholder="Search audit records"><button class="admin-button admin-button--primary" type="submit">Search</button></form></header>
        <div class="admin-card admin-card--flush"><div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Entity</th><th>Before</th><th>After</th><th>Reason</th><th>Reference ID</th><th>Result</th></tr></thead><tbody>
        <?php foreach ($settingsData['audit'] as $record): ?><tr><td><time><?= admin_escape(date('M j, Y H:i', strtotime((string) $record['created_at']))) ?></time></td><td><strong><?= admin_escape($record['actor_name']) ?></strong><small class="admin-cell-note"><?= admin_escape($record['ip_address']) ?></small></td><td><?= admin_escape(ucwords(str_replace('_', ' ', (string) $record['action']))) ?></td><td><?= admin_escape($record['entity_type']) ?><?= $record['entity_id'] ? ' #' . admin_escape($record['entity_id']) : '' ?></td><td><span class="admin-json-preview"><?= admin_escape($record['before_summary'] ?: '—') ?></span></td><td><span class="admin-json-preview"><?= admin_escape($record['after_summary'] ?: '—') ?></span></td><td><?= admin_escape($record['reason'] ?: '—') ?></td><td><code><?= admin_escape($record['reference_id']) ?></code></td><td><span class="admin-status admin-status--<?= admin_escape($record['result']) ?>"><i aria-hidden="true"></i><?= admin_escape(ucfirst((string) $record['result'])) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div></div>
    </section>
</main>
<?php require_once __DIR__ . '/components/admin_footer.php'; ?>
