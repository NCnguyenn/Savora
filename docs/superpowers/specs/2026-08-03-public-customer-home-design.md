# Public Customer Home and Guest Cart Design

**Status:** Approved
**Date:** 2026-08-03
**Product:** Savora food delivery demo

## Goal

When a visitor opens Savora, the first page must be the Customer Home/Discover experience, not the sign-in page. Visitors may browse the catalog and add items to a local cart without an account; sign-in is required when they continue to Checkout or open account-specific features.

## Approved UX

The existing customer navigation must remain visually and structurally consistent with the approved design:

- Savora brand
- Discover
- Orders
- Favorites
- Wallet
- Profile
- Log out for authenticated users
- Cart icon
- User avatar for authenticated users

For a guest, the same navigation layout remains in place. `Discover`, `Orders`, `Favorites`, `Wallet`, `Profile`, and the cart icon remain visible. Account-specific links show the sign-in gate when activated. The authenticated avatar/logout controls are replaced by a `Sign in` control; `Create account` remains available from the sign-in/register flow. All visible UI copy is English only.

## User Flows

### First visit

1. `GET /Savora/` renders the public Customer Home.
2. The page loads the read-only public catalog.
3. The visitor can search/filter dishes, open product details, and add one restaurant's items to a browser-local cart.
4. The cart badge and cart drawer work without a server session.

### Guest account actions

1. Guest clicks Orders, Favorites, Wallet, or Profile.
2. The server redirects to the sign-in page with a safe internal return route and a clear English notice.
3. After successful sign-in, the visitor returns to the requested route.

### Guest checkout

1. Guest opens the cart and clicks Checkout.
2. The server redirects to sign-in with `Please sign in to continue to checkout.` and a safe return route.
3. The cart remains in browser storage during sign-in.
4. After successful sign-in, the visitor returns to Checkout.
5. Checkout continues to require a server-backed customer address and server quote before placing an order.

### Authenticated customer

1. Login succeeds through the existing server authentication flow.
2. The user is redirected to the public Customer Home unless a validated return route is present.
3. The existing authenticated navigation, avatar, profile, orders, favorites, wallet, logout, and server-authoritative checkout behavior remain available.

### Logout

1. Logout revokes/ends the authenticated session using the existing flow.
2. The browser returns to the public Customer Home.
3. The local cart is not silently converted into an authenticated server cart; existing local cart behavior remains explicit and predictable.

## Architecture

Use a public customer shell for pages that must work without a session, while retaining the existing authenticated shell for protected customer pages. The public shell reuses the current navigation markup and CSS classes so visual design does not fork. The public Home and product/cart pages treat profile/order/favorite calls as optional; write APIs and checkout remain server-authenticated.

The existing `GET api/catalog.php` customer catalog path is already read-only and does not require authentication. No database schema change or migration is required. The existing `js/customer_state.js` local-storage cart is the guest cart source of truth until checkout authentication succeeds.

## Route and security requirements

- `index.php` becomes the public Customer Home entry point.
- The current sign-in form moves to a dedicated `login.php` route or an equivalent explicit login route; all auth redirects and links must target that route.
- `auth.php` must accept only safe internal `return_to` destinations. It must reject absolute URLs, protocol-relative URLs, and routes outside the Savora application.
- Protected customer pages must preserve server-side authentication and role checks.
- `api/profile.php`, `api/orders.php`, `api/checkout.php`, and all mutating APIs remain authenticated.
- Guest catalog access must never expose private profile, order, wallet, or session data.
- Guest cart data must remain client-side and must not be trusted for final prices, delivery fees, promotions, address ownership, or order authorization.
- Existing CSRF, session validation, rate limiting, idempotency, and server-authoritative checkout controls remain unchanged unless a route redirect needs to integrate with them.

## Error and empty states

- Guest account gate: `Please sign in to continue.`
- Guest checkout gate: `Please sign in to continue to checkout.`
- Public catalog failure: `The catalog is temporarily unavailable.`
- Empty guest cart: `Your cart is empty.`
- Authenticated profile/order API failures continue to use the existing server messages.

## Accessibility and language

- Keep the current navigation landmarks, labels, keyboard behavior, focus states, mobile menu, and cart dialog behavior.
- Sign-in redirects must set focus on the notice or form summary when practical.
- All product, navigation, authentication, error, and helper copy introduced by this feature is English 100%.
- Do not replace the current navigation with a new menu or redesign its visual hierarchy.

## Acceptance criteria

- Opening `http://localhost:8085/Savora/` shows Customer Home immediately without requiring login.
- A guest can view the public catalog and add items to the existing local cart.
- The current navigation appearance remains intact.
- A guest can open the cart and see the locally stored items.
- A guest is redirected to login when attempting Profile, Orders, Favorites, Wallet, or Checkout.
- After login, the user returns to the requested internal destination.
- Checkout still requires a valid authenticated customer session, saved address, server quote, and server validation.
- Authenticated customer pages continue to work.
- Restaurant, driver, and admin login redirects remain unchanged.
- No database schema changes are introduced.
- Automated tests and manual browser checks cover guest, authenticated customer, and non-customer role behavior.
