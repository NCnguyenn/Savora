        <footer class="admin-footer">
            <span>Savora operations console</span>
            <span>Secure session · <a href="admin_settings.php">View audit trail</a></span>
        </footer>
    </div>
</div>

<section class="admin-overlay admin-drawer" role="dialog" aria-modal="true" aria-labelledby="admin-drawer-title"
         data-admin-drawer hidden>
    <button class="admin-overlay__backdrop" type="button" data-admin-close aria-label="Close details"></button>
    <div class="admin-drawer__panel" tabindex="-1">
        <header class="admin-overlay__header">
            <div><span class="admin-eyebrow">DETAIL VIEW</span><h2 id="admin-drawer-title" data-admin-drawer-title>Record details</h2></div>
            <button class="admin-icon-button" type="button" data-admin-close aria-label="Close details"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </header>
        <div class="admin-drawer__content" data-admin-drawer-content></div>
    </div>
</section>

<section class="admin-overlay admin-confirmation" role="dialog" aria-modal="true"
         aria-labelledby="admin-confirmation-title" data-admin-confirmation hidden>
    <button class="admin-overlay__backdrop" type="button" data-admin-close aria-label="Cancel action"></button>
    <div class="admin-confirmation__panel" tabindex="-1">
        <span class="admin-confirmation__icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
        <h2 id="admin-confirmation-title" data-admin-dialog-title>Confirm action</h2>
        <p data-admin-dialog-message>Please review the impact before continuing.</p>
        <label class="admin-field" data-admin-reason-field>
            <span>Reason <em>Required for audit</em></span>
            <textarea data-admin-reason rows="3" placeholder="Explain why this intervention is needed"></textarea>
            <small data-admin-field-error aria-live="polite"></small>
        </label>
        <div class="admin-confirmation__actions">
            <button class="admin-button admin-button--ghost" type="button" data-admin-close>Cancel</button>
            <button class="admin-button admin-button--danger" type="button" data-admin-confirm>Confirm</button>
        </div>
    </div>
</section>

<div class="admin-toast-region" aria-live="polite" aria-atomic="true" data-admin-toast-region></div>

<script src="js/api_client.js"></script>
<script src="js/admin_ui.js"></script>
<script src="js/notifications.js"></script>
<?php $sessionHeartbeatCsrfToken = (string) ($_SESSION['admin_csrf'] ?? ''); ?>
<script>
(function () {
    const csrfToken = <?php echo json_encode($sessionHeartbeatCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const intervalMs = 5 * 60 * 1000;
    let lastHeartbeatAt = 0;
    const heartbeat = () => {
        if (document.visibilityState !== 'visible' || Date.now() - lastHeartbeatAt < intervalMs) return;
        lastHeartbeatAt = Date.now();
        fetch('api/session_heartbeat.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrfToken } }).catch(() => {});
    };
    heartbeat();
    document.addEventListener('visibilitychange', heartbeat);
    const scheduleHeartbeat = () => window.setTimeout(() => { heartbeat(); scheduleHeartbeat(); }, intervalMs);
    scheduleHeartbeat();
}());
</script>
</body>
</html>
