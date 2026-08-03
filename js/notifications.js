(function (root) {
  'use strict';
  if (!root || !root.SavoraApi) return;
  const badge = root.document && root.document.querySelector('.notification-badge, .admin-dot');
  const ui = root.SavoraUI || root.SavoraDriverUI || root.SavoraRestaurantUI || root.SavoraAdminUI;
  root.SavoraApi.get('api/notifications.php').then(snapshot => {
    const count = Math.max(0, Number(snapshot && snapshot.unreadCount) || 0);
    if (badge) {
      badge.textContent = count > 0 ? String(Math.min(99, count)) : '';
      badge.hidden = count === 0;
      if (badge.classList.contains('admin-dot')) badge.setAttribute('aria-label', `${count} unread notifications`);
    }
    if (count > 0 && ui && typeof ui.showToast === 'function') ui.showToast(`${count} server notification${count === 1 ? '' : 's'} available.`);
  }).catch(() => {});
}(typeof window === 'undefined' ? null : window));
