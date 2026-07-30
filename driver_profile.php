<?php require_once __DIR__ . '/components/driver_header.php'; ?>
<main id="driver-main" class="driver-main" data-driver-page="profile">
    <header class="driver-page-heading driver-profile-heading">
        <div>
            <p class="driver-eyebrow">Driver account</p>
            <h1>Profile &amp; settings</h1>
            <p>Manage your account, vehicle, documents, and work preferences.</p>
        </div>
        <div class="driver-profile-identity">
            <span class="driver-avatar" data-profile-initial>MS</span>
            <span><strong data-profile-display-name>Mike Smith</strong><small data-profile-driver-id>Driver ID driver</small></span>
            <span class="driver-chip"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Verified</span>
        </div>
    </header>

    <form data-driver-profile-form novalidate>
        <div class="driver-profile-grid">
            <div class="driver-profile-column">
                <section class="driver-card driver-settings-card" data-personal-information>
                    <header><span><i class="fa-regular fa-user" aria-hidden="true"></i></span><h2>Personal information</h2></header>
                    <div class="driver-settings-fields">
                        <label class="driver-field" for="driver-profile-name"><span>Full name</span><input id="driver-profile-name" name="fullName" type="text" autocomplete="name" required></label>
                        <label class="driver-field" for="driver-profile-phone"><span>Phone number</span><input id="driver-profile-phone" name="phone" type="tel" autocomplete="tel"></label>
                        <label class="driver-field" for="driver-profile-email"><span>Email address</span><input id="driver-profile-email" name="email" type="email" autocomplete="email"></label>
                    </div>
                </section>

                <section class="driver-card driver-settings-card" data-driver-vehicle>
                    <header><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></span><h2>Vehicle</h2></header>
                    <div class="driver-settings-fields is-two-column">
                        <label class="driver-field" for="driver-vehicle-type"><span>Vehicle type</span><select id="driver-vehicle-type" name="vehicleType"><option>Motorcycle</option><option>Bicycle</option><option>Car</option></select></label>
                        <label class="driver-field" for="driver-vehicle-model"><span>Vehicle model</span><input id="driver-vehicle-model" name="vehicleModel" type="text"></label>
                        <label class="driver-field" for="driver-license-plate"><span>License plate</span><input id="driver-license-plate" name="licensePlate" type="text"></label>
                        <label class="driver-field" for="driver-vehicle-color"><span>Vehicle color</span><input id="driver-vehicle-color" name="vehicleColor" type="text"></label>
                    </div>
                </section>

                <section class="driver-card driver-settings-card" data-driver-documents>
                    <header><span><i class="fa-regular fa-file-lines" aria-hidden="true"></i></span><h2>Documents</h2></header>
                    <ul class="driver-document-list">
                        <li><span><i class="fa-regular fa-id-card" aria-hidden="true"></i>Driver's license</span><strong data-document-license>Verified</strong></li>
                        <li><span><i class="fa-regular fa-file-lines" aria-hidden="true"></i>Vehicle registration</span><strong data-document-registration>Verified</strong></li>
                        <li><span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Insurance</span><strong class="is-warning" data-document-insurance>Expires Sep 18</strong></li>
                    </ul>
                </section>
            </div>

            <div class="driver-profile-column">
                <section class="driver-card driver-settings-card driver-location-settings" data-driver-location-settings>
                    <header><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i></span><h2>Location &amp; service area</h2></header>
                    <div class="driver-location-settings-grid">
                        <div>
                            <label class="driver-field" for="driver-current-address">
                                <span>Current address</span>
                                <input id="driver-current-address" name="currentAddress" type="text" autocomplete="street-address" data-profile-manual-address required>
                            </label>
                            <div class="driver-location-settings-actions">
                                <button class="driver-coral-action" type="button" data-profile-use-gps><i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use current GPS location</button>
                            </div>
                            <p class="driver-location-enabled" data-location-access><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Manual location enabled</p>
                        </div>
                        <div class="driver-profile-map" role="img" aria-label="Driver service area preview">
                            <span aria-hidden="true"></span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        </div>
                    </div>
                    <label class="driver-field" for="driver-service-radius">
                        <span>Service area</span>
                        <select id="driver-service-radius" name="serviceRadiusKm">
                            <option value="3">Nearby · 3 km radius</option>
                            <option value="5">Local · 5 km radius</option>
                            <option value="8">Downtown · 8 km radius</option>
                            <option value="12">Extended · 12 km radius</option>
                            <option value="20">Wide · 20 km radius</option>
                        </select>
                    </label>
                </section>

                <section class="driver-card driver-settings-card" data-driver-preferences>
                    <header><span><i class="fa-solid fa-sliders" aria-hidden="true"></i></span><h2>Delivery preferences</h2></header>
                    <div class="driver-preference-list">
                        <label><span><strong>New delivery offers</strong><small>Receive eligible nearby offers</small></span><input name="newOffers" type="checkbox" role="switch"></label>
                        <label><span><strong>Sound alerts</strong><small>Play a sound for new offers</small></span><input name="soundAlerts" type="checkbox" role="switch"></label>
                        <label><span><strong>Cash on delivery</strong><small>Receive orders that require cash collection</small></span><input name="cashOnDelivery" type="checkbox" role="switch"></label>
                        <label><span><strong>Avoid highways</strong><small>Prefer local-road navigation</small></span><input name="avoidHighways" type="checkbox" role="switch"></label>
                    </div>
                </section>

                <section class="driver-card driver-settings-card driver-account-settings">
                    <header><span><i class="fa-solid fa-lock" aria-hidden="true"></i></span><h2>Account</h2></header>
                    <p>Manage your account security and session.</p>
                    <div><button class="driver-secondary-action" type="button" data-change-password>Change password</button><a class="driver-danger-action" href="logout.php">Sign out</a></div>
                </section>
            </div>
        </div>
        <p class="driver-field-error driver-profile-error" data-profile-error aria-live="polite"></p>
        <button class="driver-primary-action driver-save-profile" type="submit" data-profile-save><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>Save changes</button>
    </form>
</main>
<?php require_once __DIR__ . '/components/driver_footer.php'; ?>
