# Customer Address and Device Location Design

## Goal

Make the saved delivery address easy to enter or capture from the customer's device without changing Savora's approved Customer UI structure, Google Maps, paid APIs, background tracking, or outbound geocoding requests.

## Experience

Profile remains the address-management surface. The existing delivery-address textarea stays visible for manual entry. A compact secondary button, "Use current location", requests browser location only after the customer presses it. On success it writes a readable local fallback (`Current location (latitude, longitude)`) into the textarea, saves it to the existing local Customer state immediately, refreshes the saved-address card and shared chrome, and reports that the customer should add building or unit details when necessary.

The Discover hero replaces its sample address with the saved local address. Checkout continues to prefill its existing address field from the same profile value; no layout or checkout-flow redesign is required.

## Privacy and Service Boundary

The feature uses only `navigator.geolocation.getCurrentPosition`. It never calls `watchPosition`, never sends coordinates to Google or another map/geocoding endpoint, and does not add a third-party map. Coordinates appear only in the local address text saved in the browser's existing Savora demo state. If browser permission is declined, unsupported, times out, or fails, manual entry remains usable and the status explains the next step.

## Accessibility and Errors

The action is a real button with a clear label, is disabled while one request is pending, and has a live status region. Unsupported, denied, unavailable, and timed-out cases use concise non-blocking messages. Existing form labels, maxlength, and local-save confirmation remain intact.

## Validation and Testing

Tests assert that profile retains an editable manual field and explicit location action, uses one-shot browser geolocation with no map/vendor endpoint or polling, and safely renders the persisted address. Browser QA stubs the browser location callback to prove button-to-profile-to-dashboard-to-checkout persistence without calling external services. PHP lint, Node syntax checks, and existing Customer test suites remain green.
