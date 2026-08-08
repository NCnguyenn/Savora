'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const Notifications = require('../js/notifications.js');

function memoryStorage() {
  const values = new Map();
  return {
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); }
  };
}

test('announces each unread server notification only once per browser session', () => {
  const storage = memoryStorage();
  const snapshot = {
    unreadCount: 1,
    notifications: [{ id: 42, readAt: null, version: 1, title: 'Payment confirmed', message: 'Payment confirmed.' }]
  };
  assert.deepEqual(Notifications.announcement(snapshot, storage), { unreadCount: 1, freshCount: 1 });
  assert.deepEqual(Notifications.announcement(snapshot, storage), { unreadCount: 1, freshCount: 0 });
});

test('new notification ids announce while already-seen ids remain quiet', () => {
  const storage = memoryStorage();
  const first = { unreadCount: 1, notifications: [{ id: 7, readAt: null, version: 1 }] };
  const second = { unreadCount: 2, notifications: [
    { id: 8, readAt: null, version: 1 },
    { id: 7, readAt: null, version: 1 }
  ] };
  Notifications.announcement(first, storage);
  assert.deepEqual(Notifications.announcement(second, storage), { unreadCount: 2, freshCount: 1 });
});
