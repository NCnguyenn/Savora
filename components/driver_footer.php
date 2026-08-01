            <footer class="driver-footer">
                <span>&copy; 2026 Savora</span>
                <span>Driver portal &middot; Local demo experience</span>
            </footer>
        </div>
    </div>

    <div id="driver-toast-container" class="driver-toast-container" aria-live="polite" aria-atomic="true"></div>
    <p id="driver-live-status" class="driver-sr-only" aria-live="polite" aria-atomic="true"></p>

    <section id="driver-support-dialog" class="driver-dialog" role="dialog" aria-modal="true" aria-labelledby="driver-support-title" hidden>
        <div class="driver-dialog-scrim" data-close-driver-dialog="driver-support-dialog"></div>
        <div class="driver-dialog-card" role="document">
            <header>
                <div><p class="driver-eyebrow">Support</p><h2 id="driver-support-title">How can we help?</h2></div>
                <button type="button" class="driver-icon-button" aria-label="Close support" data-close-driver-dialog="driver-support-dialog">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <p>For an active delivery issue, use the contextual “Report an issue” action. For account help, contact Savora support.</p>
            <div class="driver-dialog-actions">
                <a class="driver-primary-action" href="mailto:support@savora.local">Email support</a>
                <button class="driver-secondary-action" type="button" data-close-driver-dialog="driver-support-dialog">Close</button>
            </div>
        </div>
    </section>

    <script src="js/customer_state.js"></script>
    <script src="js/restaurant_state.js"></script>
    <script src="js/driver_state.js"></script>
    <script src="js/platform_bridge.js"></script>
    <script src="js/driver_ui.js"></script>
    <?php
    $driver_page_scripts = [
        'driver_dashboard.php' => 'js/driver_dashboard.js',
        'driver_delivery.php' => 'js/driver_delivery.js',
        'driver_history.php' => 'js/driver_history.js',
        'driver_earnings.php' => 'js/driver_earnings.js',
        'driver_profile.php' => 'js/driver_profile.js'
    ];
    if (isset($driver_page_scripts[$driver_current_page])) {
        $driver_script = $driver_page_scripts[$driver_current_page];
        if (file_exists(__DIR__ . '/../' . $driver_script)) {
            echo '<script src="' . htmlspecialchars($driver_script, ENT_QUOTES, 'UTF-8') . '"></script>';
        }
    }
    ?>
</body>
</html>
