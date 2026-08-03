'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const read = file => fs.readFileSync(file, 'utf8');

test('Restaurant finance and invoices consume server accounting documents', () => {
  const financePage = read('restaurant_finance.php');
  const invoicesPage = read('restaurant_invoices.php');
  const controller = read('js/restaurant_finance.js');
  const endpoint = read('api/finance.php');
  const repository = read('lib/repositories/finance_repository.php');
  const printView = read('restaurant_invoice_print.php');
  assert.match(financePage, /js\/restaurant_finance\.js/);
  assert.match(invoicesPage, /server-generated|server accounting/i);
  assert.match(controller, /api\/finance\.php/);
  assert.match(controller, /serverReport/);
  assert.match(repository, /restaurant_invoice_print\.php/);
  assert.match(printView, /data-server-financial-document/);
  assert.doesNotMatch(controller, /serverOrders/);
  assert.doesNotMatch(controller, /0\.1\)|10% fee estimate|local demo accounting document/i);
  assert.match(endpoint, /finance_repository_report/);
  assert.match(endpoint, /savora_request_actor/);
});
