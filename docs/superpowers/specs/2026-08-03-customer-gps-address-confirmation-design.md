# Customer GPS Address Confirmation Design

## Goal

Upgrade the Customer location workflow so pressing **Use current location** produces a readable address preview before anything is saved. The Customer can review the detected address, add optional delivery details, and explicitly confirm the save. Guests receive the same preview, keep the confirmed location locally, and synchronize it after signing in.

All customer-facing copy remains English. The feature is limited to Customer Home, Customer Profile, and Customer Checkout. It does not change Driver or Restaurant location behavior.

## Approved Experience

The GPS workflow has two separate operations:

1. **Preview:** obtain one browser position and reverse-geocode it into a readable address without updating the Customer profile or checkout address.
2. **Confirm:** save the previewed coordinates, server-resolved address, and optional delivery details only after the Customer presses **Save address**.

The location dialog exposes these controls and states:

- **Use current location** starts the one-shot GPS request.
- **Detected address** displays the normalized address returned by the server.
- **Delivery details (optional)** accepts apartment, floor, gate, landmark, or delivery instructions, up to 300 characters.
- **Save address** remains disabled until a valid GPS preview or non-empty manual address exists.
- **Try GPS again** replaces the unsaved preview with a newly resolved result.
- **Enter address manually** switches to the existing editable manual-address workflow.
- Status messages progress through **Requesting your location…**, **Finding your address…**, **Address found. Please review it before saving.**, and **Address saved.**

No GPS result is persisted merely because it was detected. Closing or skipping the dialog discards the unsaved preview and leaves the previously saved address unchanged.

## Delivery Details Model

`delivery_details` is separate from the formatted address and from provider-derived `address_line2`. Migration `019_customer_gps_confirmation` adds nullable `VARCHAR(300)` columns to:

- `customer_profiles.delivery_details`, used by the shared Customer location read model.
- `customer_addresses.delivery_details`, used by the authoritative checkout address record.

The location API returns this value as `deliveryDetails`. An empty string is valid and is normalized to an empty response value. It is always treated as untrusted text and rendered through DOM text APIs.

A successful GPS save writes the same normalized delivery details to the Customer profile and the default or most-recent Customer address synchronized by the location service. A manual address save accepts the same optional field, records `location_method = manual`, and clears stale latitude and longitude. Delivery details remain whatever the Customer supplied.

## Public GPS Preview Boundary

Create `api/location_preview.php` as a public, POST-only reverse-geocoding boundary. It is available to guests and authenticated Customers but performs no profile or address mutation.

The endpoint:

- Starts or resumes the normal Savora session but does not require an authenticated actor.
- Accepts JSON only and rejects request bodies larger than 4 KiB.
- Accepts only finite latitude and longitude values in their valid ranges.
- Reuses `savora_reverse_geocode` so the Geoapify key remains server-side.
- Uses the existing database-backed rate limiter with the remote address as the actor, action `customer_location_preview`, ten attempts per ten-minute window.
- Rejects the request when `Sec-Fetch-Site` is present and is not `same-origin`; when an `Origin` header is present, its scheme and host must match the current request origin. Requests from test/CLI clients may omit both headers.
- Returns only Savora's normalized address fields and the submitted coordinate pair.
- Never returns the Geoapify key, provider URL, raw provider payload, or upstream error details.

The normalized success payload is:

```json
{
  "address": "Readable formatted address",
  "addressLine1": "Street address",
  "addressLine2": "Provider-derived locality detail",
  "city": "City",
  "state": "State",
  "postalCode": "Postal code",
  "country": "Country",
  "latitude": 13.7563,
  "longitude": 100.5018
}
```

Previewing does not create an idempotency record because it is a rate-limited read from the provider and does not mutate Customer-owned state.

## Confirmed Save Boundary

Authenticated confirmation continues through `api/location.php` with `save_gps_location`. The payload adds optional `deliveryDetails`. The server validates the coordinates, reverse-geocodes them again, normalizes the delivery details, and atomically updates the Customer profile and checkout address.

Repeating reverse geocoding at confirmation is intentional: the authenticated save does not trust the guest or browser preview text. Existing CSRF, actor ownership, transaction, and idempotency protections remain authoritative.

Manual confirmation continues through `save_manual_location` and adds `deliveryDetails`. Manual saves require a non-empty address and clear GPS coordinates exactly as the current location contract requires.

## Guest Storage and Sign-In Synchronization

The existing `savora_guest_location_v1` local-storage object is extended with:

- `address` and normalized display fields from the preview.
- `latitude` and `longitude`.
- `deliveryDetails`.
- `method: "gps"` or `method: "manual"`.
- `pendingSync: true` after a guest confirms the address.

The guest draft is saved only after **Save address** is pressed. Home immediately renders the confirmed address and displays **Saved on this device. Sign in to sync it with your account.**

After authentication, the Customer location controller checks for `pendingSync`. A GPS draft is synchronized by sending its coordinate pair and delivery details to the authenticated location API, which resolves the address again before saving. A manual draft sends its manual address and delivery details. The local draft is cleared only after the server save and authoritative reload both succeed. A failed synchronization preserves the draft and shows a non-destructive retry message.

The confirmed guest draft represents the Customer's latest explicit delivery-location choice and replaces the account's default location after a successful sign-in synchronization.

## Shared Customer Surfaces

### Home

The **Deliver to** control displays only the main saved address so the hero layout remains compact. Opening the dialog restores the saved address and delivery details. Unsaved preview values exist only inside the open dialog state.

### Profile

Customer Profile adds a labelled **Delivery details (optional)** textarea next to the delivery-address controls. GPS preview and confirmation use the shared controller. Manual Profile saves persist the address and details together.

### Checkout

Checkout continues to show the authoritative saved address as read-only. When the selected address contains `deliveryDetails`, the value pre-fills **Delivery note**.

The checkout controller tracks whether the Customer has edited the note. A location refresh may update the prefill only while the note remains untouched. Once the Customer types or changes the note, subsequent quote or location refreshes do not overwrite it. Editing the per-order note never changes the saved address delivery details.

## Client Component Boundaries

- `js/location_client.js` owns geolocation input normalization and API calls. It adds `previewGps(api, coordinates)` while preserving `getPosition`, `saveGps`, and `saveManual`.
- `js/customer_location.js` owns dialog state, guest persistence, confirmation, sign-in synchronization, and the `savora:customer-location-changed` event.
- Customer PHP pages provide semantic fields and status containers but contain no location persistence logic.
- `lib/location_service.php` remains the provider adapter and normalized-address boundary.
- `lib/profile_locations.php` owns delivery-details normalization and atomic persistence to Customer profile/address records.

Each unit exposes data objects rather than provider-specific response structures.

## Accessibility and Responsive Behavior

- GPS and mode-switch actions are real buttons with visible focus styles.
- The active GPS button is disabled and exposes `aria-busy="true"` while locating or reverse-geocoding.
- Preview, success, and error status changes are announced through the existing `aria-live` region.
- The detected address is labelled and readable by assistive technology.
- Dialog focus enters the first relevant field and returns to its trigger when closed.
- The optional delivery-details textarea remains usable at 390 px without horizontal scrolling.
- Manual entry remains available after unsupported-browser, permission, timeout, provider, quota, configuration, rate-limit, and network failures.

## Error and Privacy Rules

- A preview or synchronization failure never overwrites the previously saved address or delivery details.
- Permission-denied, unavailable-position, and timeout errors keep their distinct current messages.
- HTTP 429 displays a retry-later message without retrying automatically.
- Provider/configuration failures expose only the existing safe address-lookup message.
- Savora does not request GPS on page load, call `watchPosition`, track in the background, or retain location history.
- Only the latest confirmed location is stored.

## Testing and Verification

Implementation follows test-driven development. Coverage must prove:

- GPS preview returns a readable normalized address and performs no profile/address write.
- Preview rejects non-POST, oversized, malformed, cross-site, invalid-coordinate, and rate-limited requests.
- Geoapify credentials and raw payloads are absent from HTML, JavaScript, and JSON responses.
- Pressing GPS alone does not save; **Save address** is required.
- Optional delivery details accept empty input and enforce the 300-character limit.
- Authenticated GPS and manual saves persist `deliveryDetails` to both Customer location records.
- Guest confirmation persists locally, survives reload, and synchronizes after sign-in.
- Failed guest synchronization preserves the pending local draft.
- Home and Profile restore the same address and delivery details.
- Checkout pre-fills an untouched note and never overwrites a Customer-edited note.
- Manual address saves clear stale GPS coordinates.
- Existing Customer guest browsing, checkout authentication, profile, location, and order tests remain green.
- PHP lint, Node tests, database integration tests, endpoint smoke tests, and responsive browser QA pass.

## Out of Scope

- Maps inside the confirmation dialog.
- Multiple saved-address selection or address-book redesign.
- Driver and Restaurant GPS changes.
- Background tracking, route calculation, or automatic GPS permission prompts.
- Updating saved delivery details when the Customer edits a one-time Checkout note.
