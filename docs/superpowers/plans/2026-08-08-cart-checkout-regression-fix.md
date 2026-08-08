# Cart and Checkout Regression Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure cart images resolve reliably, valid menu option identifiers reach checkout, and the one-restaurant cart rule is communicated instead of silently failing.

**Architecture:** Keep the server as the authority for prices and checkout. Normalize legacy client option identifiers at the cart boundary, submit current catalog option public IDs, and resolve each cart image from either hydrated catalog data or the safe persisted path.

**Tech Stack:** PHP 8, browser JavaScript, Node.js built-in test runner.

## Global Constraints

- Do not trust client-side money or change server-authoritative quote calculations.
- A quote remains scoped to one restaurant because checkout quotes and orders have one `restaurant_id`.
- Preserve existing cart data where it can be safely migrated.

---

### Task 1: Add regressions for cart option migration and product-to-checkout wiring

**Files:**
- Modify: `tests/customer_state.test.js`
- Create: `tests/customer_cart_checkout_regression.test.js`

**Interfaces:**
- Consumes: `SavoraState.normalize(raw)` and the product/cart/checkout page scripts.
- Produces: regressions proving legacy `portion-<choiceId>` values are migrated and current selections use choice public IDs.

- [x] **Step 1: Write the failing tests**

```js
test('migrates a legacy portion-prefixed cart option to its choice public id', () => {
  const state = State.normalize({ cart: [{ id: 'dish', options: [{ id: 'portion-choice-regular', label: 'Regular' }] }] });
  assert.equal(state.cart[0].options[0].id, 'choice-regular');
});

test('product selections use option public ids and the cart can render its persisted image', () => {
  assert.match(read('product_detail.php'), /id:\\s*portion\\.id/);
  assert.match(read('customer_cart.php'), /imageFor\\(catalogProduct \\|\\| line\\)/);
});
```

- [x] **Step 2: Run the tests to verify they fail**

Run: `node --test tests/customer_state.test.js tests/customer_cart_checkout_regression.test.js`

Expected: failure because state preserves `portion-choice-regular`, the product page prefixes choice IDs, and the cart ignores persisted images.

### Task 2: Repair client cart and checkout inputs

**Files:**
- Modify: `js/customer_state.js`
- Modify: `product_detail.php`
- Modify: `customer_cart.php`
- Modify: `js/customer_ui.js`

**Interfaces:**
- Consumes: safe catalog image paths and menu option public IDs.
- Produces: normalized option IDs, correct quote payload IDs, and cart images that work before and after catalog hydration.

- [x] **Step 1: Implement the minimal fixes**

```js
// customer_state.js
const optionId = text(option && option.id).replace(/^portion-/, '');

// product_detail.php
{ id: portion.id, label: portion.label, price: portion.price }

// customer_cart.php and customer_ui.js
window.SavoraCatalog.imageFor(catalogProduct || line)
```

- [x] **Step 2: Load the catalog on the full-cart page and re-render after hydration**

```js
window.SavoraCatalog.hydrate().then(renderFullCart).catch(() => {});
```

- [x] **Step 3: Run the focused tests to verify they pass**

Run: `node --test tests/customer_state.test.js tests/customer_cart_checkout_regression.test.js`

Expected: all focused tests pass.

### Task 3: Expose the one-restaurant boundary in the product UI

**Files:**
- Modify: `product_detail.php`
- Modify: `tests/customer_cart_checkout_regression.test.js`

**Interfaces:**
- Consumes: `SavoraState.addCartLine`, which throws if a second restaurant is added.
- Produces: an accessible toast explaining the checkout boundary, while allowing multiple dishes from the same restaurant.

- [x] **Step 1: Extend the failing page contract test**

```js
assert.match(read('product_detail.php'), /A cart can contain items from one restaurant only/);
assert.match(read('product_detail.php'), /try \\{/);
assert.match(read('product_detail.php'), /catch \\(error\\)/);
```

- [x] **Step 2: Catch the add-to-cart error and announce it**

```js
try {
  const next = SavoraState.addCartLine(/* existing arguments */);
  SavoraState.persist(next);
  SavoraUI.refreshChrome();
} catch (error) {
  SavoraUI.announce(error.message || 'This item could not be added to your cart.');
}
```

- [x] **Step 3: Run focused page and state regressions**

Run: `node --test tests/customer_state.test.js tests/customer_cart_checkout_regression.test.js`

Expected: all focused tests pass.

### Task 4: Verify the complete cart-to-checkout contract

**Files:**
- Test: `tests/customer_state.test.js`
- Test: `tests/customer_cart_checkout_regression.test.js`
- Test: `tests/checkout_contract.test.js`

- [x] **Step 1: Run the client and checkout regression suite**

Run: `node --test tests/customer_state.test.js tests/customer_cart_checkout_regression.test.js tests/checkout_contract.test.js`

Expected: all tests pass with zero failures.

- [x] **Step 2: Run PHP syntax checks on changed PHP pages**

Run: `php -l product_detail.php; php -l customer_cart.php`

Expected: `No syntax errors detected` for both files.

- [x] **Step 3: Commit only the cart/checkout fix files**

```bash
git add js/customer_state.js js/customer_ui.js product_detail.php customer_cart.php tests/customer_state.test.js tests/customer_cart_checkout_regression.test.js docs/superpowers/plans/2026-08-08-cart-checkout-regression-fix.md
git commit -m "fix: restore cart checkout flow"
```
