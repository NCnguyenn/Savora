# Task 4 — Menu Management and Add/Edit Menu Item

## Scope delivered

- Added `restaurant_menu.php` with shared Restaurant shell, responsive search/category/availability/sort controls, grid/list display, stock labels, keyboard-operable availability toggles, and real edit routes.
- Added `restaurant_menu_item.php` with labelled semantic fields for the requested menu data, local allowlisted image selection, option groups/add-ons, availability and stock controls, draft/publish actions, live validation/status regions, and a live Customer preview.
- Added `js/restaurant_menu.js`, including safe draft normalization, price validation, safe image fallback, DOM-only rendering, persisted Restaurant state updates, and navigation after save.
- Extended Restaurant menu normalization to retain safe item metadata. Published items, prices, and availability continue into the Customer catalog; drafts stay out of the Customer catalog.

## RED evidence

Command:

```powershell
node --test tests\restaurant_state.test.js tests\restaurant_markup.test.js
```

Observed failure before implementation: `Cannot find module '../js/restaurant_menu.js'` and `restaurant_menu.php must exist`.

An additional focused RED test for editor field rehydration failed with `TypeError: Menu.editorFieldName is not a function`; it exposed the tax-category/prep-time camel-case mapping issue before the minimal mapping helper was added.

## GREEN evidence

Focused GREEN command passed with 17 tests:

```powershell
node --test tests\restaurant_state.test.js tests\restaurant_markup.test.js
```

Required regression verification passed with 69 tests:

```powershell
node --test tests\restaurant_state.test.js tests\restaurant_markup.test.js tests\customer_state.test.js tests\customer_markup.test.js
D:\xampp\php\php.exe -l restaurant_menu.php
D:\xampp\php\php.exe -l restaurant_menu_item.php
git diff --check
```

PHP reported no syntax errors for both new routes and `git diff --check` reported no whitespace errors.

## Self-check

- Shared shell inclusion and one main landmark on both routes: covered.
- Safe DOM rendering: controller has no `innerHTML`; user text is rendered through the shared DOM helper.
- No inline handlers, placeholder links, remote images, or backend claims: checked in route markup and controller.
- Customer catalog bridge: tested for a published item’s price and availability; drafts are deliberately excluded.
- Unrelated untracked Customer Address planning/specification files were not staged or changed.
