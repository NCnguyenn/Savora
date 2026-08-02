# Three-Role GPS Address Design

## Goal

Add an explicit, one-shot “Use current location” workflow for Customer, Driver, and Restaurant users. The workflow converts browser GPS coordinates into a readable address with Geoapify, saves the result on the server, and keeps manual address entry available at all times. Admin users do not have a GPS action; authenticated Admin pages can read the saved location details for the other three roles.

## Scope

The feature covers Customer delivery addresses, Driver current/service-area addresses, and Restaurant storefront addresses. It also adds read-only location visibility to the corresponding Admin Customer, Driver, and Restaurant views.

The feature does not add background tracking, continuous location polling, automatic permission prompts, an Admin GPS address, route calculation, or a new map provider. Existing manual forms and existing map previews remain available.

## Location Data Model

Each role-owned profile stores the following server-authoritative values:

- `address`: the current readable address.
- `latitude`: a nullable decimal latitude.
- `longitude`: a nullable decimal longitude.
- `location_method`: `gps` or `manual`.
- `location_updated_at`: the time the saved location was last changed.

Restaurant structured address fields remain the editable source for its readable address. A successful GPS lookup maps Geoapify’s structured result into address line 1, address line 2 when available, city, state or province, postal code, and country, then derives the existing combined Restaurant address.

A valid GPS location requires both coordinates. If a user manually changes and saves the address, the server records `location_method = manual` and clears both coordinates. This prevents Admin from interpreting stale coordinates as the user’s current GPS location. Existing saved data without GPS metadata is treated as manual.

## Geoapify Boundary

Browser code calls `navigator.geolocation.getCurrentPosition` only after the user presses a location button. The browser sends the resulting latitude and longitude to an authenticated same-origin PHP endpoint. That endpoint validates the active session, CSRF token, allowed role, coordinate ranges, and request size before calling Geoapify Reverse Geocoding.

The Geoapify API key is read only from the server environment variable `GEOAPIFY_API_KEY`. It is never rendered into HTML, returned in JSON, stored in browser state, or committed to the repository. The request prefers Vietnamese results and accepts Geoapify’s structured address plus formatted display address. Savora displays the required “Powered by Geoapify” attribution beside GPS-assisted address controls.

The server normalizes the provider response into a small internal location object. Client controllers consume that object and do not depend directly on Geoapify’s full response schema. This keeps the provider boundary isolated and testable.

## Save Flow

The GPS request and profile save use server-authoritative role checks. A successful reverse-geocode result is persisted for the currently authenticated role and user; a client cannot choose another user or role. The response returns only the normalized saved location.

Manual saves use the same role-owned profile path and clear GPS metadata when the address changed. Repeated GPS submissions use an idempotency key so retries do not produce conflicting profile state.

Local Customer, Driver, and Restaurant state remains synchronized as a UI cache for the current demo, but server data is authoritative for cross-role Admin visibility. On page initialization, saved server location data replaces sample or stale local address values.

## Customer Experience

The existing “Deliver to” area on Customer Home becomes a real button rather than displaying the sample `123 Tech Park, Block C` address.

- When an address is already saved, Home displays it immediately.
- When no address is saved, Home displays “Choose delivery address.”
- Pressing the control opens an accessible address dialog with the existing editable manual field, a “Use current location” action, Save, and Close/Skip actions.
- Closing or skipping the dialog does not block browsing restaurants or dishes and does not request location permission.
- GPS runs only after “Use current location” is pressed. On success, it fills and immediately saves the resolved address.
- The Customer Profile and Checkout address sections expose the same GPS action and retain editable manual textareas.
- A change made on Home, Profile, or Checkout is reflected on the other Customer surfaces. Checkout continues to require an address only when placing an order.

## Driver Experience

The existing GPS actions on Driver Dashboard and Driver Profile remain in place. Their current coordinate-only behavior is upgraded so a successful lookup fills and saves a resolved readable address instead of retaining the old manual text or using “Current GPS location.”

Dashboard, Profile, service-area presentation, and Admin views read the same saved Driver location. When a saved location exists, it appears automatically on later page loads. GPS is not requested again until the Driver explicitly presses the action. The current manual-address dialog and profile field remain available.

## Restaurant Experience

The existing “Use current location” and “Enter address manually” actions on Restaurant Profile remain. On GPS success, Geoapify’s normalized structured result populates the Restaurant address fields and persists them with the coordinate pair and `gps` method. The existing map preview uses the saved coordinates.

When a saved Restaurant address exists, Profile and storefront surfaces display it on later page loads without requesting GPS again. Restaurant users can edit any structured field and save manually, which clears stale GPS coordinates.

## Admin Read-Only Visibility

Admin does not receive a GPS action. Authenticated Admin Customer, Driver, and Restaurant pages display the role-owned saved location with:

- Readable address.
- GPS or Manual source.
- Latitude and longitude when the source is GPS.
- Last-updated time.

The values are retrieved from server tables, escaped before rendering, and never accepted from Admin query parameters. Location visibility is added to existing active-profile tables or detail panels without changing Admin approval responsibilities.

## Interaction and Accessibility

Location actions are real buttons. While locating and reverse geocoding, the pressed button is disabled and exposes a clear busy label. Status and error text is announced through an `aria-live` region. Dialog focus is moved inside when opened and restored to the trigger when closed.

The UI never claims success before both geolocation and reverse geocoding finish. Existing address text is not overwritten when a request fails. Manual entry stays enabled during unsupported-browser, permission-denied, timeout, provider, quota, configuration, and network failures.

## Error Handling

Browser geolocation uses a one-shot request with high accuracy enabled, a 10-second timeout, and a short cached-position allowance. Unsupported, denied, unavailable, and timed-out results receive distinct user-facing messages.

The PHP provider client uses HTTPS, a short connection/request timeout, a bounded response size, and strict JSON parsing. A missing API key, invalid provider response, no address result, rate limit, or upstream failure returns a non-sensitive error and leaves the previous address unchanged. Provider credentials, raw upstream errors, and request URLs containing the key are never sent to the browser or written to user-visible output.

## Security and Privacy

Only Customer, Driver, and Restaurant sessions can create or change their own locations. Existing session and CSRF protections apply. Coordinates must be finite and remain inside latitude `-90..90` and longitude `-180..180`. All address components are length-limited and treated as untrusted text.

Savora does not call `watchPosition`, request GPS on page load, track a user in the background, or store location history. Only the latest saved location is retained. Admin access follows existing authenticated Admin authorization and is read-only for this feature.

## Testing and Verification

Automated tests use an injected/fake reverse-geocoding transport and never call Geoapify over the network. Coverage proves:

- Provider request validation, normalization, timeouts, and safe failure behavior.
- Role ownership, authentication, CSRF checks, and coordinate validation.
- The API key is absent from HTML, JavaScript, and JSON responses.
- Customer Home, Profile, and Checkout share the saved address and retain manual entry.
- Home can be skipped without requesting GPS or blocking discovery.
- Driver Dashboard and Profile replace coordinate-only fallback text with the resolved address.
- Restaurant GPS fills structured address fields and its saved map coordinates.
- Manual saves clear stale GPS coordinates for every role.
- Admin reads the correct address, method, coordinates, and updated time for each role and cannot invoke GPS.
- Provider and permission failures preserve the previous address.

PHP lint, Node syntax checks, the existing role-specific test suites, and focused browser QA must pass. Browser QA stubs `navigator.geolocation` and the same-origin reverse-geocode response, verifies busy/error states, and confirms persistence across reloads without making external requests.

## Deployment Configuration

The runtime environment must define `GEOAPIFY_API_KEY` before GPS-to-address lookup can succeed. Deployments without the variable continue to support manual entry and display a configuration-safe failure message. Geoapify quota and error responses are handled as temporary failures; no automatic request loop is used.
