# Rich Savora Catalog Design

## Goal

Populate Savora with a production-ready English-language demo catalog containing six active restaurants and 48 available menu items. Four restaurants will represent Vietnamese cuisine and two will represent other international cuisines. Every restaurant will contain eight menu items, including food and beverages.

The catalog must be usable by the existing Customer home, product detail, cart, checkout, Restaurant menu, and Admin views without relying on browser-local catalog state or external image URLs.

## Content direction

All user-facing catalog content is English-only, including Vietnamese restaurants and dishes. No Vietnamese-language copy or diacritics will be used in visible catalog fields. Vietnamese identity is preserved through English restaurant names, English dish names such as “Rare Beef Pho,” cuisine labels, and English descriptions.

Restaurants:

1. Lotus Kitchen — Vietnamese comfort food
2. Saigon Ember Grill — Vietnamese grill and street food
3. Hoi An Garden — Central Vietnamese and vegetarian food
4. Mekong Bowl & Tea — modern Vietnamese bowls, coffee, and tea
5. Tokyo Kumo — Japanese cuisine
6. Roma Verde — contemporary Italian cuisine

Each restaurant receives a complete profile: English name, cuisine, description, fictional Central City address, phone, operating hours, rating, active status, accepting-orders status, and a signature image derived from its first featured menu item.

Each restaurant receives eight menu items, targeted as six food items and two beverages. Every item receives an English name, description, price in the existing USD display convention, category, prep time, calories, ingredients, allergen information, dietary tags, availability, sort order, and a local image path.

## Image direction

Generate one unique food-photography image per menu item (48 total). Images should be 4K-ready landscape catalog assets with a consistent premium editorial style, natural appetizing light, clear food presentation, no text, no logos, and no watermark. Images will be stored in `assets/images/catalog/` and referenced only through validated local paths. The first featured image for each restaurant will be used as its Customer discovery card image.

## Data model changes

Extend the existing MySQL catalog contract with rich content fields while preserving current columns and API compatibility.

Restaurant additions:

- `description`
- `hero_image`

Menu item additions:

- `description`
- `image_path`
- `category`
- `prep_time_minutes`
- `calories`
- `dietary_tags`
- `allergens`
- `ingredients`
- `sort_order`

The fields are bounded strings or numeric values with safe defaults. Existing rows remain valid and fall back to the current placeholder/“Prepared to order” presentation when rich content is absent.

## Data flow

The explicit CLI seed creates or updates only the known Savora demo records. The normal web request path remains read-only with respect to schema and seed operations.

`api/catalog.php` will continue to be the Customer read boundary. Its repository query will return the new item and restaurant fields. `js/customer_catalog.js` will map them into the existing Customer model, use local image paths, and derive restaurant cards from the enriched item records. Product detail will render the description, preparation time, calories, ingredients, allergens, and dietary tags when present.

## Seed behavior

The seed must be repeatable and safe to run more than once. It will create six demo restaurant owner accounts when missing, link each restaurant to its owner, create/update the six restaurant profiles, create/update the 48 menu items, and keep all demo restaurants active with all demo items available. Stable public IDs and deterministic slugs will prevent duplicates.

The seed will not delete unrelated restaurants, users, or menu items. It will run only from the CLI in development/test environments with `SAVORA_SEED_DEMO=1`.

## Validation

Before completion, verify:

- the schema migration applies cleanly and is idempotent;
- the seed creates exactly six demo restaurants and eight menu items per demo restaurant;
- every demo restaurant has an active owner and accepting orders enabled;
- every demo menu item is available, has a non-empty English description, and references an existing local image;
- Customer catalog API output contains all 48 items and enriched fields;
- Customer discovery shows restaurants, dishes, categories, prices, and images;
- product detail shows the enriched information;
- PHP lint and focused catalog tests pass;
- no image path points outside the validated local catalog asset directory.

## Scope boundaries

This change does not introduce external image hosting, a new frontend framework, real-world restaurant claims, payments, or a separate restaurant-cover image set. It uses fictional demo restaurant data suitable for local Savora development.
