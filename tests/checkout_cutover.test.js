'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const read = file => fs.readFileSync(file, 'utf8');

test('Customer state keeps only a draft cart and cannot create money or orders locally', () => {
  const state = read('js/customer_state.js');
  assert.doesNotMatch(state, /placeDemoOrder|topUpWallet|walletBalance|walletTransactions/);
  assert.match(state, /addCartLine/);
  assert.match(state, /removeCartLine/);
  assert.match(state, /updateCartQuantity/);
});

test('checkout quotes and places through the focused server endpoint', () => {
  const page = read('customer_checkout.php');
  assert.match(page, /api\/checkout\.php/);
  assert.match(page, /SavoraApi\.post/);
  assert.match(page, /SavoraApi\.intentKey/);
  assert.doesNotMatch(page, /placeDemoOrder|SavoraPlatformBridge\.command\(['"]place_order/);
});

test('wallet renders server data and does not offer a local top-up writer', () => {
  const page = read('customer_wallet.php');
  assert.match(page, /api\/profile\.php/);
  assert.match(page, /SavoraApi\.get/);
  assert.doesNotMatch(page, /topUpWallet|SavoraState\.persist/);
  assert.match(page, /disabled/);
});
