# Customer Home and Restaurant Storefront Design

## Goal

Restructure Customer discovery so the Home page provides a clear overview of Savora's restaurants, dishes, and drinks without presenting all 48 catalog items as one undifferentiated list. Selecting a restaurant opens a dedicated Customer restaurant storefront that presents the restaurant's complete brand and menu information.

The design must also use wide desktop screens more effectively, remove the visibly overflowing category strip, preserve responsive behavior, and keep all customer-facing copy and catalog content in English.

## Confirmed problems

- The Home page renders every available item into one product grid, so menu items are not visibly organized by restaurant or type.
- Category labels repeat because category IDs are paired with the first source record found for the restaurant rather than the current item's category label.
- The category control contains too many item-level categories and exposes a desktop horizontal scrollbar.
- Restaurant cards do not show a distinct logo or slogan and currently open a menu modal instead of a dedicated page.
- The shared Customer container is capped at 1180 pixels, which leaves excessive empty space on large screens.
- The catalog response does not expose a stable restaurant public identifier, logo, slogan, phone, opening hours, or item type even though some related restaurant data already exists in the database.

## Navigation decision

Use a dedicated page for each restaurant:

`customer_restaurant.php?restaurant={restaurantPublicId}`

Home restaurant cards and restaurant favorites link to this route. The public identifier is validated server-side and is the only identifier exposed in customer-facing URLs. Invalid, inactive, or unavailable restaurants return a friendly Customer not-found state with a link back to Home.

Product cards continue to link to `product_detail.php?id={menuItemPublicId}`. The product detail page provides a link back to the owning restaurant storefront.

The existing restaurant menu modal is removed from Home and restaurant favorites after all navigation entry points use the dedicated storefront.

## Home information architecture

Home remains an overview rather than a complete catalog dump. Content order:

1. Discovery hero with restaurant, dish, and cuisine search.
2. Compact high-level filters: All, Vietnamese, Japanese, Italian, Food, and Drinks.
3. Featured Restaurants containing all six demo restaurant brands.
4. Popular Dishes containing a curated subset distributed across multiple restaurants.
5. Refreshing Drinks containing a curated beverage subset.
6. Existing active-order information in a compact responsive presentation.

Search applies across restaurant name, cuisine, slogan, dish name, and category. Cuisine filters affect restaurant and product discovery. Food and Drinks filter by a persisted item type, not by guessing from category names.

Item-level categories such as Regional Noodles and Rice Plates remain available inside a restaurant storefront, where their scope is understandable. They are not all rendered as top-level Home pills.

## Home visual layout

The Customer content width increases from 1180 pixels to a responsive maximum in the 1440-1520 pixel range. Full-width background bands and bounded inner content create visual structure without stretching text to unreadable line lengths.

On large desktop screens:

- the header, hero content, and main sections align to the wider content grid;
- restaurant cards use a three-column grid so each brand has enough room for its logo, image, slogan, and metadata;
- popular products use a four-column grid;
- the active-order panel appears as a useful compact rail only when enough width exists, and does not force the primary feed into a narrow column;
- alternating ivory, white, and soft green section backgrounds visually occupy the viewport while preserving Savora's existing palette.

At narrower breakpoints, grids reduce to three, two, and one column. Filter controls wrap normally and never expose a page-level horizontal scrollbar.

## Restaurant cards

Each Home restaurant card contains:

- local cover image;
- unique brand logo;
- restaurant name;
- English slogan;
- cuisine;
- rating and estimated preparation or delivery time;
- a clear `View Restaurant` action;
- favorite control.

The full card title and action link to the restaurant storefront. Favorite controls remain independent and keyboard accessible.

## Restaurant storefront information architecture

The dedicated restaurant page contains:

1. Breadcrumb and back-to-discovery link.
2. Brand hero with cover image, logo, name, slogan, cuisine, rating, delivery estimate, open/closed state, and favorite control.
3. About panel with English description, full fictional address, phone number, and weekly opening hours.
4. Special Offers section when at least one active promotion applies to the restaurant.
5. Menu header with search and filter controls.
6. Menu groups for Food and Drinks, with item-level category filtering within the selected restaurant.
7. Existing active-order summary where relevant.

The storefront renders every available item owned by the selected restaurant. With the current demo catalog, this means all eight items per restaurant. Product cards retain image, name, short description, price, category, preparation time, dietary information when useful, favorite control, and product-detail navigation.

If no active promotion applies, Special Offers is omitted rather than showing an empty placeholder. Promotion messaging is derived only from active, in-date promotion records whose scope is all restaurants or the selected restaurant. Checkout remains the authoritative place that validates eligibility and calculates the discount.

## Brand content

Add one unique scalable local logo and one English slogan for each demo restaurant. Logos use SVG so they remain sharp at card and hero sizes without adding large raster downloads. Existing 4K menu photography remains the source for restaurant cover imagery.

Approved brand direction:

- Lotus Kitchen: `Vietnamese comfort, thoughtfully served.`
- Saigon Ember Grill: `Fire, fragrance, and Saigon spirit.`
- Hoi An Garden: `Regional flavors in full bloom.`
- Mekong Bowl & Tea: `Bright bowls, slow sips.`
- Tokyo Kumo: `Tokyo craft, light as a cloud.`
- Roma Verde: `Italian warmth, fresh by nature.`

All visible names, slogans, descriptions, labels, addresses, category names, and promotional text remain English-only.

## Data model and API contract

Extend the catalog model with stable brand and classification fields while preserving existing rows:

Restaurant additions or exposure:

- stable `public_id` for customer URLs;
- `slogan`;
- local `logo_path` or an equivalent resolved media path;
- existing description, hero image, rating, address, city, phone, accepting-orders state, and weekly hours.

Menu item addition:

- `item_type`, constrained to `food` or `drink` with a safe default of `food` for existing rows.

The rich demo seed updates the six known restaurants and 48 known items idempotently. It does not delete or overwrite unrelated user-created restaurants.

The Customer catalog boundary returns the additional restaurant fields and item type. A restaurant-specific read accepts a validated restaurant public ID and returns one active restaurant, its available menu, weekly hours, and applicable active promotions. Repository queries perform the ownership and active-status filtering; browser code does not reconstruct sensitive relationships from arbitrary input.

## Client rendering and state

Fix category mapping so each mapped item carries its own category label. Restaurant aggregation deduplicates category IDs without looking up the first record for the restaurant.

Home and storefront rendering use shared catalog mapping and card helpers where practical. Page-specific code owns only page layout, filtering, and navigation behavior.

Loading, empty, and failure states are explicit:

- skeleton or concise loading status while catalog data is requested;
- filtered-empty message with a clear reset action;
- unavailable restaurant state with a Home link;
- image fallback for missing local assets;
- no horizontal overflow at supported breakpoints.

## Accessibility

- Use semantic links for navigation and buttons only for in-page actions.
- Preserve visible keyboard focus and independent favorite controls.
- Use heading hierarchy to identify Featured Restaurants, Popular Dishes, Drinks, About, Offers, Food, and Drinks sections.
- Associate search and filter controls with visible or screen-reader labels.
- Expose selected filters with `aria-pressed` or appropriate tab semantics.
- Supply meaningful logo alternatives while treating decorative cover images appropriately.
- Announce result counts and empty states without moving keyboard focus unexpectedly.

## Validation

Focused validation must cover:

- category IDs retain their correct labels and are not duplicated incorrectly;
- Home no longer renders all 48 items into one undifferentiated grid;
- six restaurant cards expose logo, slogan, and dedicated storefront URLs;
- each valid storefront returns only the selected restaurant's items;
- Food and Drinks filters use `item_type` and show correct results;
- inactive or invalid restaurant IDs do not expose storefront data;
- promotions appear only when active, in date, and applicable to the selected restaurant;
- all six local logo assets exist and are safely referenced;
- desktop, tablet, and mobile layouts have no unintended horizontal overflow;
- PHP lint, JavaScript tests, focused integration tests, and browser smoke checks pass.

## Scope boundaries

This work does not add real merchant locations, real brand claims, restaurant self-service logo editing, a promotion-authoring redesign, a frontend framework, or an external image service. It reuses Savora's existing Customer shell, catalog, favorites, product detail, pricing, and promotion domains while adding the dedicated Customer restaurant storefront and the minimum data fields needed to support it.
