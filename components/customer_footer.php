    <footer class="customer-footer">
        <div class="footer-top customer-shell">
            <section class="footer-col">
                <a class="brand" href="customer_dashboard.php"><i class="fa-solid fa-utensils" aria-hidden="true"></i>Savora</a>
                <p>Thoughtful local food, delivered simply.</p>
            </section>
            <section class="footer-col" aria-labelledby="footer-explore">
                <h2 id="footer-explore">Explore</h2>
                <a href="<?= htmlspecialchars($customer_link('customer_dashboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Discover food</a>
                <a href="<?= htmlspecialchars($customer_link('customer_history.php'), ENT_QUOTES, 'UTF-8'); ?>">Your orders</a>
            </section>
            <section class="footer-col" aria-labelledby="footer-account">
                <h2 id="footer-account">Account</h2>
            <a href="<?= htmlspecialchars($customer_link('customer_wallet.php'), ENT_QUOTES, 'UTF-8'); ?>">Savora Pay</a>
            <a href="<?= htmlspecialchars($customer_link('customer_profile.php'), ENT_QUOTES, 'UTF-8'); ?>">Profile</a>
            </section>
        </div>
        <div class="footer-bottom">&copy; 2026 Savora. Local demo experience.</div>
    </footer>

    <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

    <section id="customer-location-dialog" class="dialog" role="dialog" aria-modal="true" aria-labelledby="customer-location-title" hidden>
        <div class="dialog-scrim" data-customer-location-close></div>
        <div class="dialog-panel customer-location-dialog-panel" role="document">
            <header class="modal-header">
                <div><p class="eyebrow">Delivery location</p><h2 id="customer-location-title">Where should we deliver?</h2></div>
                <button class="icon-button" type="button" aria-label="Close delivery location" data-customer-location-close><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <form data-customer-location-form>
                <div class="customer-location-mode" role="group" aria-label="Address entry mode">
                    <button class="secondary-action" type="button" data-customer-location-manual>Enter address manually</button>
                    <button class="secondary-action" type="button" data-customer-use-gps><i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use current location</button>
                </div>
                <label class="form-field" for="customer-location-address">Delivery address
                    <textarea id="customer-location-address" data-customer-location-input rows="3" maxlength="500" autocomplete="street-address" placeholder="Street, building and area"></textarea>
                </label>
                <div class="customer-location-preview" data-customer-location-preview hidden aria-labelledby="customer-location-preview-title">
                    <p class="eyebrow">Detected address</p>
                    <p id="customer-location-preview-title" data-customer-location-preview-address></p>
                    <button class="secondary-action" type="button" data-customer-location-retry>Try GPS again</button>
                </div>
                <label class="form-field" for="customer-delivery-details">Delivery details (optional)
                    <textarea id="customer-delivery-details" data-customer-delivery-details rows="2" maxlength="300" placeholder="Apartment, floor, gate, or landmark"></textarea>
                </label>
                <p class="form-help" data-customer-location-status aria-live="polite" aria-atomic="true"></p>
                <div class="dialog-actions customer-location-actions">
                    <button class="primary-action" type="submit" data-customer-location-save disabled>Save address</button>
                    <button class="secondary-action" type="button" data-customer-location-skip>Skip</button>
                </div>
                <small class="form-help">Powered by Geoapify for GPS-assisted addresses.</small>
            </form>
        </div>
    </section>

    <aside id="cart-overlay" class="dialog drawer" role="dialog" aria-modal="true" aria-labelledby="cart-title" hidden>
        <div class="dialog-scrim" data-close-dialog="cart-overlay"></div>
        <div class="dialog-panel" role="document">
            <header class="cart-header">
                <h2 id="cart-title"><i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>Your cart</h2>
                <button class="icon-button" type="button" aria-label="Close cart" data-close-dialog="cart-overlay"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div class="cart-body" id="cart-items-container"></div>
            <footer class="cart-footer">
                <div class="cart-summary-row"><span>Subtotal</span><span id="cart-subtotal">$0.00</span></div>
                <div class="cart-summary-row"><span>Delivery fee</span><span id="cart-delivery">$0.00</span></div>
                <div class="cart-summary-row total"><span>Total</span><span id="cart-total">$0.00</span></div>
                <a class="primary-action" href="customer_cart.php">View full cart</a>
            </footer>
        </div>
    </aside>

    <section id="customize-modal" class="dialog" role="dialog" aria-modal="true" aria-labelledby="pdetail-name" hidden>
        <div class="dialog-scrim" data-close-dialog="customize-modal"></div>
        <div class="dialog-panel detail-modal-card" role="document">
            <header class="detail-header-banner" id="pdetail-banner">
                <button class="icon-button detail-close-btn" type="button" aria-label="Close product details" data-close-dialog="customize-modal"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                <div class="detail-title-row"><h2 id="pdetail-name">Product details</h2><span class="detail-price-main" id="pdetail-price">$0.00</span></div>
            </header>
            <div class="detail-modal-body">
                <p id="pdetail-desc"></p>
                <dl class="product-facts"><div><dt>Prep</dt><dd id="pdetail-preptime">15 min</dd></div><div><dt>Calories</dt><dd id="pdetail-calories">0 kcal</dd></div></dl>
                <div class="dialog-actions">
                    <div class="qty-control" role="group" aria-label="Quantity">
                        <button class="qty-btn" type="button" aria-label="Decrease quantity" data-custom-quantity="-1">−</button>
                        <span id="cust-qty" aria-live="polite">1</span>
                        <button class="qty-btn" type="button" aria-label="Increase quantity" data-custom-quantity="1">+</button>
                    </div>
                    <button class="primary-action" type="button" id="confirm-custom-cart">Add to cart <span id="cust-calculated-total">$0.00</span></button>
                </div>
            </div>
        </div>
    </section>

    <section id="topup-modal" class="dialog" role="dialog" aria-modal="true" aria-labelledby="topup-title" hidden>
        <div class="dialog-scrim" data-close-dialog="topup-modal"></div>
        <div class="dialog-panel topup-dialog" role="document">
            <header class="modal-header"><h2 id="topup-title">Top up Savora Pay</h2><button class="icon-button" type="button" aria-label="Close top up" data-close-dialog="topup-modal"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="modal-body topup-options">
                <button class="secondary-action" type="button" data-topup="20">Add $20</button>
                <button class="secondary-action" type="button" data-topup="50">Add $50</button>
                <button class="secondary-action" type="button" data-topup="100">Add $100</button>
                <button class="primary-action" type="button" data-topup="200">Add $200</button>
            </div>
        </div>
    </section>

    <?php
    $customer_asset_version = static function (string $path): string {
        $mtime = @filemtime(__DIR__ . '/../' . $path);
        return $mtime === false ? (string) time() : (string) $mtime;
    };
    ?>
    <script src="js/customer_catalog.js?v=<?php echo $customer_asset_version('js/customer_catalog.js'); ?>"></script>
    <script src="js/restaurant_state.js?v=<?php echo $customer_asset_version('js/restaurant_state.js'); ?>"></script>
    <script src="js/customer_state.js?v=<?php echo $customer_asset_version('js/customer_state.js'); ?>"></script>
    <script src="js/driver_state.js?v=<?php echo $customer_asset_version('js/driver_state.js'); ?>"></script>
    <script src="js/api_client.js?v=<?php echo $customer_asset_version('js/api_client.js'); ?>"></script>
    <script src="js/location_client.js?v=<?php echo $customer_asset_version('js/location_client.js'); ?>"></script>
    <script src="js/customer_location_state.js?v=<?php echo $customer_asset_version('js/customer_location_state.js'); ?>"></script>
    <script src="js/customer_checkout_note.js?v=<?php echo $customer_asset_version('js/customer_checkout_note.js'); ?>"></script>
    <script src="js/customer_location.js?v=<?php echo $customer_asset_version('js/customer_location.js'); ?>"></script>
    <script src="js/customer_ui.js?v=<?php echo $customer_asset_version('js/customer_ui.js'); ?>"></script>
    <script src="js/notifications.js?v=<?php echo $customer_asset_version('js/notifications.js'); ?>"></script>
    <script src="assets/vendor/leaflet/leaflet.js?v=<?php echo $customer_asset_version('assets/vendor/leaflet/leaflet.js'); ?>"></script>
    <?php foreach (($customer_page_scripts ?? []) as $script): ?>
        <script src="<?php echo htmlspecialchars((string) $script, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo time(); ?>"></script>
    <?php endforeach; ?>
    <?php $sessionHeartbeatCsrfToken = (string) ($_SESSION['admin_csrf'] ?? ''); ?>
    <script>
    window.SavoraCsrfToken = <?php echo json_encode($sessionHeartbeatCsrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    (function () {
        if (!window.SavoraCustomerAuthenticated) return;
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
