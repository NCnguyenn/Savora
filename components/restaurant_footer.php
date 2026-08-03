        <footer class="restaurant-footer"><span>© 2026 Savora</span><span>Restaurant portal · Local demo experience</span></footer>
    </div>
    <div id="restaurant-toast-container" class="restaurant-toast-container" aria-live="polite" aria-atomic="true"></div>
    <script src="js/restaurant_state.js"></script>
    <script src="js/api_client.js"></script>
    <script src="js/restaurant_ui.js"></script>
    <script src="js/notifications.js"></script>
    <?php $sessionHeartbeatCsrfToken = (string) ($_SESSION['admin_csrf'] ?? ''); ?>
    <script>
    window.SavoraCsrfToken = <?php echo json_encode($sessionHeartbeatCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
