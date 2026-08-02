    <footer class="customer-footer">
        <div class="footer-top customer-shell">
            <section class="footer-col">
                <a class="brand" href="customer_dashboard.php"><i class="fa-solid fa-utensils" aria-hidden="true"></i>Savora</a>
                <p>Thoughtful local food, delivered simply.</p>
            </section>
            <section class="footer-col" aria-labelledby="footer-explore">
                <h2 id="footer-explore">Explore</h2>
                <a href="customer_dashboard.php">Discover food</a>
                <a href="customer_history.php">Your orders</a>
            </section>
            <section class="footer-col" aria-labelledby="footer-account">
                <h2 id="footer-account">Account</h2>
                <a href="customer_wallet.php">Savora Pay</a>
                <a href="customer_profile.php">Profile</a>
            </section>
        </div>
        <div class="footer-bottom">&copy; 2026 Savora. Local demo experience.</div>
    </footer>

    <div id="toast-container" class="toast-container" aria-live="polite" aria-atomic="true"></div>

    <aside id="cart-overlay" class="dialog drawer" role="dialog" aria-modal="true" aria-labelledby="cart-title" hidden>
        <div class="dialog-scrim" data-close-dialog="cart-overlay"></div>
        <div class="dialog-panel cart-drawer" role="document">
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

    <section id="menu-modal" class="dialog" role="dialog" aria-modal="true" aria-labelledby="modal-rest-name" hidden>
        <div class="dialog-scrim" data-close-dialog="menu-modal"></div>
        <div class="dialog-panel menu-dialog" role="document">
            <header class="modal-header"><h2 id="modal-rest-name">Restaurant menu</h2><button class="icon-button" type="button" aria-label="Close restaurant menu" data-close-dialog="menu-modal"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></header>
            <div class="modal-body"><div class="grid-4-col" id="modal-food-grid"></div></div>
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

    <script src="js/customer_catalog.js"></script>
    <script src="js/restaurant_state.js"></script>
<script src="js/customer_state.js"></script>
<script src="js/location_client.js"></script>
    <script src="js/driver_state.js"></script>
    <script src="js/platform_bridge.js"></script>
    <script src="js/customer_ui.js"></script>
    <script src="js/customer_location.js"></script>
    <script src="assets/vendor/leaflet/leaflet.js"></script>
</body>
</html>
