<?php include 'components/customer_header.php'; ?>

<main class="customer-shell profile-page">
    <header class="page-title-block profile-title-block">
        <p class="eyebrow">Your Savora account</p>
        <h1>Profile</h1>
        <p>Keep the contact details used by this local demo up to date.</p>
    </header>

    <section class="surface-card profile-summary" aria-labelledby="profile-summary-title">
        <div class="profile-avatar-large" aria-hidden="true" data-profile-avatar><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
        <div class="profile-summary-copy">
            <h2 id="profile-summary-title" data-profile-name><?php echo htmlspecialchars($full_name, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p><i class="fa-regular fa-envelope" aria-hidden="true"></i><span data-profile-email><?php echo htmlspecialchars($username . '@savora.com', ENT_QUOTES, 'UTF-8'); ?></span></p>
            <span class="status-chip"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>Local demo profile</span>
        </div>
        <div class="profile-membership-note">
            <i class="fa-solid fa-leaf" aria-hidden="true"></i>
            <div><strong>Savora member</strong><span>Your preferences stay on this device.</span></div>
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
                        <textarea id="profile-address" name="address" autocomplete="street-address" rows="3" maxlength="300" placeholder="Street, building and delivery notes"></textarea>
                        <div class="profile-location-actions">
                            <button class="secondary-action" type="button" data-customer-use-gps><i class="fa-solid fa-crosshairs" aria-hidden="true"></i>Use current location</button>
                            <small class="form-help">Powered by Geoapify for GPS-assisted addresses.</small>
                        </div>
                    </div>
                </div>
                <p class="form-help" id="profile-local-help" data-customer-location-status aria-live="polite">Saved locally on this device and synced to your Savora profile. You can enter an address manually or use your current location.</p>
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
                <p class="saved-address-copy" id="saved-address-copy">No local demo address saved yet.</p>
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
                <p>Password changes are unavailable in this UI-only demo because there is no account backend to update securely.</p>
                <button class="secondary-action" type="button" disabled aria-describedby="password-unavailable-note">Password changes unavailable</button>
                <p class="form-help" id="password-unavailable-note">Use the production account service when backend security support is available.</p>
            </section>
        </div>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('profile-form');
        const fullNameInput = document.getElementById('profile-full-name');
        const emailInput = document.getElementById('profile-email');
        const phoneInput = document.getElementById('profile-phone');
        const addressInput = document.getElementById('profile-address');
        const saveStatus = document.getElementById('profile-save-status');
        const addressCopy = document.getElementById('saved-address-copy');
        const summaryName = document.querySelector('[data-profile-name]');
        const summaryEmail = document.querySelector('[data-profile-email]');
        const summaryAvatar = document.querySelector('[data-profile-avatar]');

        function renderProfile(profile) {
            const fullName = typeof profile.fullName === 'string' && profile.fullName.trim() ? profile.fullName : fullNameInput.value;
            const email = typeof profile.email === 'string' && profile.email.trim() ? profile.email : emailInput.value;
            fullNameInput.value = fullName;
            emailInput.value = email;
            phoneInput.value = typeof profile.phone === 'string' ? profile.phone : '';
            addressInput.value = typeof profile.address === 'string' ? profile.address : '';
            summaryName.textContent = fullName;
            summaryEmail.textContent = email;
            summaryAvatar.textContent = fullName.trim().charAt(0).toUpperCase() || 'S';
            addressCopy.textContent = addressInput.value.trim() || 'No local demo address saved yet.';
        }

        renderProfile(SavoraState.load().profile || {});

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (!form.reportValidity()) return;
            try {
                const location = window.SavoraCustomerLocation
                    ? await window.SavoraCustomerLocation.saveManualAddress(addressInput.value)
                    : { address: addressInput.value.trim(), locationMethod: 'manual', latitude: null, longitude: null };
                const state = SavoraState.setProfile(SavoraState.load(), {
                    fullName: fullNameInput.value.trim(),
                    email: emailInput.value.trim(),
                    phone: phoneInput.value.trim(),
                    address: location.address,
                    latitude: location.latitude,
                    longitude: location.longitude,
                    locationMethod: location.locationMethod,
                    locationUpdatedAt: location.locationUpdatedAt
                });
                SavoraState.persist(state);
                SavoraUI.refreshChrome();
                renderProfile(state.profile);
                saveStatus.textContent = 'Profile settings saved.';
                SavoraUI.showToast('Profile settings saved.');
            } catch (error) {
                saveStatus.textContent = error.message || 'Profile settings could not be saved.';
                SavoraUI.showToast(saveStatus.textContent, 'error');
            }
        });
    });
</script>

<?php include 'components/customer_footer.php'; ?>
