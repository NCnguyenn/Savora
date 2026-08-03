'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');

const NoteState = require('../js/customer_checkout_note.js');

test('saved address details prefill an untouched Checkout note', () => {
  const initial = NoteState.create();
  assert.deepEqual(NoteState.applyAddressDetails(initial, 'Tower B, floor 12'), { value: 'Tower B, floor 12', dirty: false });
});

test('Customer-edited Checkout notes are not overwritten by address refreshes', () => {
  const edited = NoteState.edit(NoteState.create(), 'Call on arrival');
  assert.deepEqual(NoteState.applyAddressDetails(edited, 'New gate'), edited);
});

test('Checkout note state accepts empty and 300-character saved details', () => {
  assert.equal(NoteState.applyAddressDetails(NoteState.create(), '').value, '');
  assert.equal(NoteState.applyAddressDetails(NoteState.create(), 'x'.repeat(300)).value.length, 300);
});
