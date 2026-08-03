'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const auth = require('../js/auth_forms.js');

test('password validation and confirmation are deterministic', () => {
  assert.equal(auth.validatePassword('short').ok, false);
  assert.equal(auth.validatePassword('Strong-Pass-123!').ok, true);
  assert.equal(auth.passwordsMatch('Strong-Pass-123!', 'Strong-Pass-123!'), true);
  assert.equal(auth.passwordsMatch('Strong-Pass-123!', 'different'), false);
});

test('form helpers expose safe user-facing messages', () => {
  assert.match(auth.validatePassword('short').message, /10/);
  assert.equal(auth.passwordStrength('Strong-Pass-123!'), 'strong');
  assert.equal(auth.passwordStrength('abcdefghi1'), 'fair');
});
