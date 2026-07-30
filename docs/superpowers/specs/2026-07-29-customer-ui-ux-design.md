# Savora Customer UI/UX Redesign

## Goal

Modernize every Customer-facing page into one coherent, elegant Savora experience while keeping the current PHP application architecture and avoiding a new backend dependency.

## Visual references

The approved visual direction is recorded in `docs/design/mockups/`:

- `customer-home.png` — discovery home
- `product-detail.png` — product configuration and add-to-cart
- `customer-cart.png` — cart and order summary
- `customer-checkout.png` — delivery, payment and confirmation
- `customer-history.png` — active tracking and order history
- `customer-favorites.png` — saved restaurants and dishes
- `customer-profile.png` — account settings
- `customer-wallet.png` — Savora Pay and activity

These images establish hierarchy and visual tone; the production UI must remain responsive and accessible rather than copying their desktop geometry literally.

## Visual system

- Palette: deep Savora green as the primary surface/action color; warm ivory page background; muted sage for supporting surfaces; charcoal text; coral for primary purchase actions and attention states.
- Typography: high-contrast display heading paired with a readable sans-serif UI font. Maintain a clear title, section heading, body and metadata scale.
- Layout: 8px spacing rhythm, 16px rounded cards, restrained shadows, image-forward food presentation, ample whitespace.
- Navigation: persistent desktop header with Discover, Orders, Favorites, Wallet and Profile. On small screens the navigation becomes an accessible compact menu; cart remains visible.
- Interaction: every actionable card has a semantic link or button, visible focus styling and an accessible name. Dialogs support Escape, focus management and outside-click close behavior.

## Shared behavior and data boundaries

- The existing PHP session guard remains the authority for the customer role.
- Client-side cart, temporary profile preferences and demo wallet state remain local-only for this UI-focused release. The UI must not imply server-confirmed payment or permanently saved account data that the current backend does not provide.
- All client-side state read from `localStorage` is rendered through safe DOM APIs, never interpolated into `innerHTML` or inline event-handler strings.
- An active order is shown only when a customer-local demo order exists. Otherwise display the designed empty state and a discovery call-to-action.
- Product metadata is defined per product so restaurant, description, dietary information and options do not contradict the item shown.

## Page requirements

### Discovery (`customer_dashboard.php`)

- Provide the shared navigation, location/search controls, category filters, promotions, restaurants and recommended dishes.
- Make search and category filters compose, search both dish and restaurant names, and show a no-results state.
- Replace the always-visible hard-coded active order/map with conditional order tracking or an empty state.

### Product detail (`product_detail.php`)

- Use the selected product’s own restaurant, image, metadata, choices, allergies and add-ons.
- Support quantity, optional choices and special instructions with an accessible add-to-cart CTA.
- Present as one column below the mobile breakpoint.

### Cart (`customer_cart.php`)

- Show editable cart lines, accessible quantity and remove controls, promo input, summary and checkout CTA.
- Show an explicit empty-cart state with a route back to discovery.
- Collapse items and summary into one column on small screens.

### Checkout (`customer_checkout.php`)

- Present delivery address, delivery note, payment choice, promo code and a sticky desktop order summary.
- Require a non-empty delivery address before placement; prevent duplicate placements in a single action.
- In this UI-focused release, label successful order placement as a local demo order and update the local active order/history state consistently.

### Orders (`customer_history.php`)

- Provide filters, a conditional active-order tracking panel, completed/cancelled history cards and a reorder CTA.
- Use text plus icon/color for statuses, not color alone.

### Favorites (`customer_favorites.php`)

- Separate restaurant and dish views with a semantic tab control.
- Allow removing a favorite and show an empty state and discovery CTA when no records exist.

### Profile (`customer_profile.php`)

- Provide labelled and keyboard-accessible account fields; save the supported profile fields to local demo state and confirm the scope to the user.
- Include saved-address and security-entry points, while avoiding false claims that password changes are server-persisted.

### Savora Pay (`customer_wallet.php`)

- Show balance, top-up controls, transaction list and clear debit/credit semantics.
- Update the displayed balance immediately after a local demo top-up and keep the activity list synchronized.
- Avoid a polling loop; use one shared state update path.

## Responsive requirements

- Desktop: use the reference two-column detail/cart/checkout layouts where appropriate.
- Tablet: retain readable card widths and progressively reduce dense navigation.
- Mobile (up to 768px): use a compact menu, single-column cart/checkout/detail, stacked order items and wallet/banner content, full-width primary CTAs and touch targets of at least 44px.

## Accessibility requirements

- `lang` and page titles reflect Savora and the rendered locale.
- Interactive controls must use semantic elements with labels, keyboard operation and visible focus styles.
- Dialogs use `role="dialog"`, `aria-modal="true"`, labelled headings, Escape handling and focus return.
- Status/toast announcements use an appropriate live region.
- Color pairs meet WCAG AA contrast for their text size.

## Acceptance criteria

- Each listed Customer page follows the shared visual system and its corresponding mockup’s information hierarchy.
- No page horizontally overflows at 320px, 768px or 1440px.
- Cart, checkout, profile and wallet display consistent local-demo state without raw HTML interpolation.
- The Customer PHP files pass syntax validation with `D:\Xampp\php\php.exe -l`.
- Automated browserless checks cover state utilities and verify that unsafe dynamic HTML injection is not reintroduced.
