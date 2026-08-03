(function (root) {
  'use strict';
  const form = root.document && root.document.querySelector('[data-partner-application-form]');
  if (!form) return;
  form.addEventListener('submit', async event => {
    event.preventDefault(); const message = form.querySelector('[data-application-message]'); const formData = new FormData(form); formData.set('action', 'submit_application'); formData.set('type', form.dataset.applicationType);
    try { const response = await fetch('api/partner_applications.php', { method: 'POST', credentials: 'same-origin', body: formData }); const data = await response.json(); if (!response.ok || !data.ok) throw new Error(data.message || 'Application was not submitted.'); message.textContent = `${data.message} Reference: ${data.data.referenceCode}`; form.reset(); } catch (error) { message.textContent = error.message; }
  });
}(typeof window === 'undefined' ? null : window));
