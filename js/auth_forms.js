'use strict';
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.SavoraAuth = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
  function validatePassword(value) {
    const password = String(value || '');
    if (password.length < 10) return { ok: false, message: 'Use at least 10 characters.' };
    if (!/[A-Za-z]/.test(password) || !/\d/.test(password)) return { ok: false, message: 'Include at least one letter and one number.' };
    return { ok: true, message: 'Password meets the minimum requirements.' };
  }

  function passwordsMatch(password, confirmation) {
    return String(password) !== '' && String(password) === String(confirmation);
  }

  function passwordStrength(value) {
    const password = String(value || '');
    if (!validatePassword(password).ok) return 'weak';
    return password.length >= 12 && /[^A-Za-z0-9]/.test(password) && /[A-Z]/.test(password) ? 'strong' : 'fair';
  }

  function enhanceForm(form) {
    const password = form.querySelector('input[name="password"]');
    const confirmation = form.querySelector('[data-password-confirmation]');
    const summary = form.querySelector('[data-form-summary]');
    const strength = form.querySelector('[data-password-strength]');
    const submit = form.querySelector('[data-submit-label]');
    const originalSubmit = submit ? submit.textContent.trim() : '';

    form.querySelectorAll('[data-password-toggle]').forEach(function (button) {
      button.addEventListener('click', function () {
        const input = button.parentElement.querySelector('input');
        if (!input) return;
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        button.setAttribute('aria-pressed', show ? 'true' : 'false');
      });
    });

    if (password && strength) password.addEventListener('input', function () { strength.dataset.strength = passwordStrength(password.value); });
    form.addEventListener('submit', function (event) {
      let message = '';
      if (password && form.dataset.authMode !== 'login') {
        const validation = validatePassword(password.value);
        if (!validation.ok) message = validation.message;
      }
      if (!message && form.dataset.authMode !== 'login' && password && confirmation && !passwordsMatch(password.value, confirmation.value)) message = 'Passwords do not match.';
      if (!form.checkValidity() || message) {
        event.preventDefault();
        if (summary) {
          summary.hidden = false;
          summary.classList.add('auth-summary--error');
          summary.textContent = message || 'Review the highlighted fields and try again.';
          summary.focus();
        }
        form.reportValidity();
        return;
      }
      if (submit) {
        submit.disabled = true;
        submit.textContent = submit.dataset.loadingLabel || 'Submitting...';
        setTimeout(function () { submit.disabled = false; submit.textContent = originalSubmit; }, 8000);
      }
    });

    const logo = form.querySelector('input[name="logo"]');
    const preview = form.querySelector('[data-logo-preview]');
    if (logo && preview) logo.addEventListener('change', function () {
      preview.replaceChildren();
      const file = logo.files && logo.files[0];
      if (!file) { preview.hidden = true; return; }
      const image = document.createElement('img');
      image.alt = 'Selected restaurant logo preview';
      image.src = URL.createObjectURL(file);
      const name = document.createElement('span'); name.textContent = file.name;
      preview.append(image, name); preview.hidden = false;
    });
  }

  function initialize() {
    if (typeof document === 'undefined') return;
    document.querySelectorAll('[data-auth-form]').forEach(enhanceForm);
  }
  if (typeof document !== 'undefined') document.addEventListener('DOMContentLoaded', initialize);
  return { validatePassword, passwordsMatch, passwordStrength, initialize };
});
