(function attachAdminUI(root, factory) {
    const api = factory(root);
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.SavoraAdminUI = api;
}(typeof window !== 'undefined' ? window : null, function createAdminUI(root) {
    'use strict';

    let previousFocus = null;
    let pendingAction = null;

    function focusableElements(container) {
        if (!container) return [];
        return Array.from(container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
    }

    function revealOverlay(overlay) {
        if (!overlay) return;
        previousFocus = root && root.document ? root.document.activeElement : null;
        overlay.hidden = false;
        overlay.classList.add('is-open');
        const target = overlay.querySelector('[tabindex="-1"], button, input, textarea');
        if (target) target.focus();
    }

    function concealOverlay(overlay) {
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.hidden = true;
        if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        previousFocus = null;
    }

    function openDrawer(title, contentNode) {
        const drawer = root.document.querySelector('[data-admin-drawer]');
        const titleNode = drawer && drawer.querySelector('[data-admin-drawer-title]');
        const content = drawer && drawer.querySelector('[data-admin-drawer-content]');
        if (titleNode) titleNode.textContent = title || 'Record details';
        if (content) {
            content.replaceChildren();
            if (contentNode) content.append(contentNode);
        }
        revealOverlay(drawer);
        return drawer;
    }

    function closeDrawer() {
        concealOverlay(root.document.querySelector('[data-admin-drawer]'));
    }

    function openDialog(options) {
        const dialog = root.document.querySelector('[data-admin-confirmation]');
        const config = options || {};
        const title = dialog && dialog.querySelector('[data-admin-dialog-title]');
        const message = dialog && dialog.querySelector('[data-admin-dialog-message]');
        const confirm = dialog && dialog.querySelector('[data-admin-confirm]');
        const reasonField = dialog && dialog.querySelector('[data-admin-reason-field]');
        if (title) title.textContent = config.title || 'Confirm action';
        if (message) message.textContent = config.message || 'Please review the impact before continuing.';
        if (confirm) confirm.textContent = config.confirmLabel || 'Confirm';
        if (reasonField) reasonField.hidden = config.requireReason === false;
        pendingAction = typeof config.onConfirm === 'function' ? config.onConfirm : null;
        revealOverlay(dialog);
        return dialog;
    }

    function closeDialog() {
        pendingAction = null;
        concealOverlay(root.document.querySelector('[data-admin-confirmation]'));
    }

    function showToast(message, tone) {
        const region = root.document.querySelector('[data-admin-toast-region]');
        if (!region) return null;
        const toast = root.document.createElement('div');
        toast.className = 'admin-toast admin-toast--' + (tone || 'success');
        toast.setAttribute('role', 'status');
        const icon = root.document.createElement('i');
        icon.className = tone === 'error' ? 'fa-solid fa-circle-exclamation' : 'fa-solid fa-circle-check';
        icon.setAttribute('aria-hidden', 'true');
        const copy = root.document.createElement('span');
        copy.textContent = message;
        toast.append(icon, copy);
        region.append(toast);
        root.setTimeout(function removeToast() { toast.remove(); }, 4200);
        return toast;
    }

    function applyTableFilter(table, filters) {
        if (!table) return 0;
        const query = String((filters && filters.query) || '').trim().toLowerCase();
        const status = String((filters && filters.status) || '').trim().toLowerCase();
        let visible = 0;
        table.querySelectorAll('tbody tr').forEach(function filterRow(row) {
            const rowText = (row.dataset.search || row.textContent).toLowerCase();
            const rowStatus = String(row.dataset.status || '').toLowerCase();
            const matches = (!query || rowText.includes(query)) && (!status || status === 'all' || rowStatus === status);
            row.hidden = !matches;
            if (matches) visible += 1;
        });
        return visible;
    }

    function actionHeaders(idempotencyKey) {
        const token = root.document.querySelector('meta[name="admin-csrf-token"]');
        return {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token ? token.content : '',
            'Idempotency-Key': idempotencyKey || ('adm-' + Date.now() + '-' + Math.random().toString(16).slice(2))
        };
    }

    async function requestAction(action, payload, idempotencyKey) {
        const response = await fetch('admin_action.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: actionHeaders(idempotencyKey),
            body: JSON.stringify({ action: action, payload: payload || {} })
        });
        const data = await response.json();
        if (!response.ok || data.ok === false) {
            const error = new Error(data.message || 'The action could not be completed.');
            error.details = data;
            throw error;
        }
        return data;
    }

    function formatMoney(value, currency) {
        return new Intl.NumberFormat('en-US', { style: 'currency', currency: currency || 'USD' }).format(Number(value || 0));
    }

    function syncFilters(form) {
        const params = new URLSearchParams(root.location.search);
        const data = new root.FormData(form);
        data.forEach(function updateParam(value, key) {
            if (String(value).trim()) params.set(key, String(value));
            else params.delete(key);
        });
        const query = params.toString();
        root.history.replaceState({}, '', root.location.pathname + (query ? '?' + query : ''));
        const table = root.document.querySelector('[data-admin-table]');
        applyTableFilter(table, { query: data.get('q'), status: data.get('status') });
    }

    function formPayload(form) {
        const payload = {};
        const data = new root.FormData(form);
        data.forEach(function assignField(value, key) { payload[key] = value; });
        form.querySelectorAll('input[type="checkbox"]').forEach(function assignCheckbox(input) {
            payload[input.name] = input.checked;
        });
        return payload;
    }

    async function submitActionForm(form) {
        const submit = form.querySelector('[type="submit"]');
        const errorNode = form.querySelector('[data-admin-field-error]');
        if (errorNode) errorNode.textContent = '';
        if (submit) submit.disabled = true;
        try {
            const result = await requestAction(form.dataset.adminAction, formPayload(form));
            const version = form.querySelector('[name="version"]');
            if (version && result.data && result.data.version) version.value = String(result.data.version);
            showToast(result.message || 'Changes saved.', 'success');
        } catch (error) {
            const details = error.details || {};
            const errors = details.errors || {};
            if (errorNode) errorNode.textContent = Object.values(errors)[0] || error.message;
            showToast(error.message, 'error');
        } finally {
            if (submit) submit.disabled = false;
        }
    }

    function trapFocus(event, overlay) {
        if (event.key !== 'Tab') return;
        const items = focusableElements(overlay);
        if (!items.length) return;
        const first = items[0];
        const last = items[items.length - 1];
        if (event.shiftKey && root.document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && root.document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function closeTarget(target) {
        if (!target) return;
        if (target.matches('[data-admin-drawer]')) closeDrawer();
        else if (target.matches('[data-admin-confirmation]')) closeDialog();
        else if (target.matches('dialog') && typeof target.close === 'function') target.close();
    }

    function initialize() {
        root.document.addEventListener('click', function handleClick(event) {
            const closeControl = event.target.closest('[data-admin-close]');
            if (closeControl) {
                const target = closeControl.closest('[data-admin-drawer], [data-admin-confirmation], dialog');
                closeTarget(target);
                return;
            }
            const opener = event.target.closest('[data-admin-open]');
            if (opener && opener.dataset.adminOpen === 'mobile-navigation') {
                const mobile = root.document.querySelector('[data-admin-mobile-navigation]');
                if (mobile && typeof mobile.showModal === 'function') mobile.showModal();
            }
        });

        root.document.addEventListener('keydown', function handleKeys(event) {
            const overlay = event.target.closest('[data-admin-drawer], [data-admin-confirmation], dialog[open]');
            if (event.key === 'Escape' && overlay) {
                event.preventDefault();
                closeTarget(overlay);
            }
            if (event.key === 'Tab' && overlay) trapFocus(event, overlay);
        });

        root.document.querySelectorAll('[data-admin-filter]').forEach(function bindFilter(form) {
            form.addEventListener('input', function filterInput() { syncFilters(form); });
            form.addEventListener('change', function filterChange() { syncFilters(form); });
        });

        root.document.querySelectorAll('form[data-admin-action]').forEach(function bindActionForm(form) {
            form.addEventListener('submit', function actionSubmit(event) {
                event.preventDefault();
                submitActionForm(form);
            });
        });

        root.document.querySelectorAll('[data-admin-print]').forEach(function bindPrint(button) {
            button.addEventListener('click', function printReport() { root.print(); });
        });

        const confirmButton = root.document.querySelector('[data-admin-confirm]');
        if (confirmButton) confirmButton.addEventListener('click', async function confirmAction() {
            const dialog = confirmButton.closest('[data-admin-confirmation]');
            const reason = dialog.querySelector('[data-admin-reason]');
            const error = dialog.querySelector('[data-admin-field-error]');
            if (!reason.closest('[data-admin-reason-field]').hidden && !reason.value.trim()) {
                error.textContent = 'Please provide an audit reason.';
                reason.focus();
                return;
            }
            error.textContent = '';
            if (pendingAction) await pendingAction(reason.value.trim());
            closeDialog();
        });
    }

    if (root && root.document) {
        if (root.document.readyState === 'loading') root.document.addEventListener('DOMContentLoaded', initialize);
        else initialize();
    }

    return { openDrawer, closeDrawer, openDialog, closeDialog, showToast, applyTableFilter, requestAction, formatMoney };
}));
