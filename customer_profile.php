<?php include 'components/customer_header.php'; ?>

<main class="customer-shell profile-page">
    <header class="page-title-block profile-title-block">
        <p class="eyebrow">Your Savora account</p>
        <h1>Profile</h1>
        <p>Keep your server-backed contact and delivery details up to date.</p>
    </header>

    <section class="surface-card profile-summary" aria-labelledby="profile-summary-title">
        <div class="profile-avatar-large" aria-hidden="true" data-profile-avatar><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="profile-summary-copy">
            <h2 id="profile-summary-title" data-profile-name><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p><i class="fa-regular fa-envelope" aria-hidden="true"></i><span data-profile-email><?php echo htmlspecialchars($username . '@savora.com', ENT_QUOTES, 'UTF-8'); ?></span></p>
            <span class="status-chip"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Saved to your account</span>
        </div>
        <div class="profile-membership-note">
            <i class="fa-solid fa-leaf" aria-hidden="true"></i>
            <div><strong>Savora member</strong><span>Your profile follows your authenticated account.</span></div>
        </div>
    </section>

    <div class="profile-layout">
        <section class="surface-card profile-account-card" aria-labelledby="profile-account-title">
            <div class="section-heading-row">
                <div>
                    <p class="eyebrow">Account details</p>
                    <h2 id="profile-account-title">Contact information</h2>
                </div>
                <i class="fa-regular fa-pen-to-square section-heading-icon" aria-hidden="true"></i>
            </div>

            <form id="profile-form">
                <div class="profile-form-grid">
                    <div class="form-field">
                        <label for="profile-full-name">Full name</label>
                        <input id="profile-full-name" name="fullName" type="text" autocomplete="name" value="<?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?>" required maxlength="120">
                    </div>
                    <div class="form-field">
                        <label for="profile-email">Email address</label>
                        <input id="profile-email" name="email" type="email" autocomplete="email" value="<?php echo htmlspecialchars($username . '@savora.com', ENT_QUOTES, 'UTF-8'); ?>" required maxlength="160">
                    </div>
                    <div class="form-field">
                        <label for="profile-phone">Phone number</label>
                        <input id="profile-phone" name="phone" type="tel" autocomplete="tel" inputmode="tel" maxlength="40" placeholder="e.g. +65 9123 4567">
                    </div>
                    <div class="form-field profile-address-field">
                        <label for="profile-address">Delivery address</label>
                        <textarea id="profile-address" name="address" autocomplete="street-address" rows="3" maxlength="200" placeholder="Street and building"></textarea>
                        <button class="secondary-action" type="button" data-customer-use-gps data-customer-location-trigger aria-controls="customer-location-dialog" aria-expanded="false"><i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use current location</button>
                        <small class="form-help">Powered by Geoapify for GPS-assisted addresses.</small>
                        <p class="form-help" data-customer-location-status aria-live="polite"></p>
                    </div>
                    <div class="form-field profile-address-field">
                        <label for="profile-delivery-details">Delivery details (optional)</label>
                        <textarea id="profile-delivery-details" name="deliveryDetails" rows="2" maxlength="300" placeholder="Apartment, floor, gate, or landmark"></textarea>
                    </div>
                    <div class="form-field"><label for="profile-address-label">Address label</label><input id="profile-address-label" name="addressLabel" maxlength="80" value="Home"></div>
                    <div class="form-field"><label for="profile-city">City</label><input id="profile-city" name="city" maxlength="100" autocomplete="address-level2"></div>
                    <div class="form-field"><label for="profile-latitude">Latitude</label><input id="profile-latitude" name="latitude" type="number" min="-90" max="90" step="0.0000001"></div>
                    <div class="form-field"><label for="profile-longitude">Longitude</label><input id="profile-longitude" name="longitude" type="number" min="-180" max="180" step="0.0000001"></div>
                </div>
                <p class="form-help" id="profile-local-help">GPS coordinates are optional for manual addresses and are kept only for a confirmed GPS location.</p>
                <div class="profile-form-actions">
                    <button class="primary-action" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i>Save profile</button>
                    <p id="profile-save-status" class="profile-save-status" role="status" aria-live="polite" aria-atomic="true"></p>
                </div>
            </form>
        </section>

        <div class="profile-side-stack">
            <section class="surface-card" aria-labelledby="saved-address-title">
                <div class="section-heading-row">
                    <div>
                        <p class="eyebrow">Saved address</p>
                        <h2 id="saved-address-title">Delivery location</h2>
                    </div>
                    <span class="profile-card-icon"><i class="fa-solid fa-house" aria-hidden="true"></i></span>
                </div>
                <p class="saved-address-copy" id="saved-address-copy">No saved delivery address yet.</p>
                <a class="secondary-action" href="#profile-address">Edit address</a>
            </section>

            <section class="surface-card security-card" aria-labelledby="profile-security-title">
                <div class="section-heading-row">
                    <div>
                        <p class="eyebrow">Password &amp; security</p>
                        <h2 id="profile-security-title">Account security</h2>
                    </div>
                    <span class="profile-card-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></span>
                </div>
                <p>Password changes remain outside this profile endpoint and require the dedicated account security flow.</p>
                <button class="secondary-action" type="button" disabled aria-describedby="password-unavailable-note">Password changes unavailable</button>
                <p class="form-help" id="password-unavailable-note">Use the production account service when backend security support is available.</p>
            </section>
        </div>
    </div>
</main>

<script src="js/api_client.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', async function () {
        const form = document.getElementById('profile-form');
        const fullNameInput = document.getElementById('profile-full-name');
        const emailInput = document.getElementById('profile-email');
        const phoneInput = document.getElementById('profile-phone');
        const addressInput = document.getElementById('profile-address');
        const addressLabelInput = document.getElementById('profile-address-label');
        const deliveryDetailsInput = document.getElementById('profile-delivery-details');
        const cityInput = document.getElementById('profile-city');
        const latitudeInput = document.getElementById('profile-latitude');
        const longitudeInput = document.getElementById('profile-longitude');
        const saveStatus = document.getElementById('profile-save-status');
        const addressCopy = document.getElementById('saved-address-copy');
        const summaryName = document.querySelector('[data-profile-name]');
        const summaryEmail = document.querySelector('[data-profile-email]');
        const summaryAvatar = document.querySelector('[data-profile-avatar]');

        let snapshot = null;
        let addressPublicId = '';

        function renderSnapshot(data) {
            snapshot = data;
            const profile = data.profile || {};
            const address = (data.addresses || []).find(item => item.isDefault) || (data.addresses || [])[0] || {};
            const fullName = typeof profile.fullName === 'string' ? profile.fullName : '';
            const email = typeof profile.email === 'string' ? profile.email : '';
            fullNameInput.value = fullName;
            emailInput.value = email;
            phoneInput.value = typeof profile.phone === 'string' ? profile.phone : '';
            addressInput.value = address.addressLine1 || '';
            deliveryDetailsInput.value = address.deliveryDetails || '';
            addressLabelInput.value = address.label || 'Home';
            cityInput.value = address.city || '';
            latitudeInput.value = address.latitude === null || address.latitude === undefined || address.latitude === '' ? '' : address.latitude;
            longitudeInput.value = address.longitude === null || address.longitude === undefined || address.longitude === '' ? '' : address.longitude;
            addressPublicId = address.publicId || addressPublicId || `address-${Date.now().toString(36)}`;
            summaryName.textContent = fullName;
            summaryEmail.textContent = email;
            summaryAvatar.textContent = fullName.trim().charAt(0).toUpperCase() || 'S';
            addressCopy.textContent = [address.addressLine1, address.city].filter(Boolean).join(', ') || 'No saved delivery address yet.';
        }

        async function hydrate() {
            renderSnapshot(await SavoraApi.get('api/profile.php'));
        }

        document.addEventListener('savora:customer-location-changed', () => {
            hydrate().catch(error => { saveStatus.textContent = error.message || 'Profile location was not refreshed.'; });
        });

        try { await hydrate(); }
        catch (error) { saveStatus.textContent = error.message || 'Profile is unavailable.'; form.querySelector('button[type="submit"]').disabled = true; return; }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            const profileScope = 'customer-profile';
            const addressScope = `customer-address-${addressPublicId}`;
            const locationScope = 'customer-profile-location';
            const latitude = latitudeInput.value.trim() === '' ? null : Number(latitudeInput.value);
            const longitude = longitudeInput.value.trim() === '' ? null : Number(longitudeInput.value);
            try {
                await SavoraApi.post('api/profile.php', { action: 'update_profile', payload: {
                    fullName: fullNameInput.value.trim(), email: emailInput.value.trim(), phone: phoneInput.value.trim(), version: snapshot.profile.version
                } }, SavoraApi.intentKey(profileScope));
                await SavoraApi.post('api/profile.php', { action: 'save_address', payload: {
                    publicId: addressPublicId, label: addressLabelInput.value.trim(), recipientName: fullNameInput.value.trim(), phone: phoneInput.value.trim(),
                    addressLine1: addressInput.value.trim(), city: cityInput.value.trim(), deliveryDetails: deliveryDetailsInput.value.trim(), latitude, longitude,
                    isDefault: true, version: ((snapshot.addresses || []).find(item => item.publicId === addressPublicId) || {}).version || 0
                } }, SavoraApi.intentKey(addressScope));
                if (window.SavoraLocationClient) {
                    if (latitude === null && longitude === null) {
                        await window.SavoraLocationClient.saveManual(SavoraApi, { address: addressInput.value.trim(), deliveryDetails: deliveryDetailsInput.value.trim() }, SavoraApi.intentKey(locationScope));
                    } else {
                        await window.SavoraLocationClient.saveGps(SavoraApi, { latitude, longitude }, SavoraApi.intentKey(locationScope), deliveryDetailsInput.value.trim());
                    }
                }
                await hydrate();
                SavoraApi.clearIntentKey(profileScope); SavoraApi.clearIntentKey(addressScope); SavoraApi.clearIntentKey(locationScope);
                saveStatus.textContent = 'Profile and delivery address refreshed from the server.';
                SavoraUI.showToast('Profile and address saved.');
            } catch (error) { saveStatus.textContent = error.message || 'Profile was not saved.'; }
        });
    });
</script>

<?php include 'components/customer_footer.php'; ?>
