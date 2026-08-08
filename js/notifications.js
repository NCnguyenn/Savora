(function attachNotifications(root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (root && root.document) api.start(root);
}(typeof window === 'undefined' ? null : window, function createNotifications() {
  'use strict';

  const seenStorageKey = 'savora_seen_notification_ids_v1';

  function readSeenIds(storage) {
    if (!storage || typeof storage.getItem !== 'function') return new Set();
    try {
      const values = JSON.parse(storage.getItem(seenStorageKey) || '[]');
      return new Set(Array.isArray(values) ? values.map(value => String(value)) : []);
    } catch (_) {
      return new Set();
    }
  }

  function announcement(snapshot, storage) {
    const notifications = Array.isArray(snapshot && snapshot.notifications) ? snapshot.notifications : [];
    const seen = readSeenIds(storage);
    const fresh = notifications.filter(notification => {
      const id = notification && notification.id;
      return notification && notification.readAt === null && id !== undefined && id !== null && !seen.has(String(id));
    });
    fresh.forEach(notification => seen.add(String(notification.id)));
    if (storage && typeof storage.setItem === 'function') {
      try { storage.setItem(seenStorageKey, JSON.stringify([...seen].slice(-200))); } catch (_) {}
    }
    return {
      unreadCount: Math.max(0, Number(snapshot && snapshot.unreadCount) || 0),
      freshCount: fresh.length
    };
  }

  function start(root) {
    if (!root || !root.SavoraApi) return;
    const badge = root.document && root.document.querySelector('.notification-badge, .admin-dot');
    const ui = root.SavoraUI || root.SavoraDriverUI || root.SavoraRestaurantUI || root.SavoraAdminUI;
    let storage = null;
    try { storage = root.sessionStorage; } catch (_) {}
    root.SavoraApi.get('api/notifications.php').then(snapshot => {
      const result = announcement(snapshot, storage);
      if (badge) {
        badge.textContent = result.unreadCount > 0 ? String(Math.min(99, result.unreadCount)) : '';
        badge.hidden = result.unreadCount === 0;
        if (badge.classList.contains('admin-dot')) badge.setAttribute('aria-label', `${result.unreadCount} unread notifications`);
      }
      if (result.freshCount > 0 && ui && typeof ui.showToast === 'function') {
        ui.showToast(`${result.freshCount} new server notification${result.freshCount === 1 ? '' : 's'} available.`);
      }
    }).catch(() => {});
  }

  return { announcement, start };
}));
