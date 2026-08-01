(function (root) {
  'use strict';

  function draftKey(intentKey) {
    return 'savora_checkout_draft_' + intentKey;
  }

  function intentFor(storage, randomUUID) {
    const existing = storage.getItem('savora_checkout_intent');
    if (existing) return existing;
    const created = 'role-' + randomUUID();
    storage.setItem('savora_checkout_intent', created);
    return created;
  }

  async function submit(options) {
    const storage = options.storage;
    const intentKey = intentFor(storage, options.randomUUID);
    const storedDraft = storage.getItem(draftKey(intentKey));
    let draft;
    if (storedDraft === null) {
      draft = options.buildOrder();
      storage.setItem(draftKey(intentKey), JSON.stringify(draft));
    } else {
      draft = JSON.parse(storedDraft);
    }

    const response = await options.command(draft.order, intentKey);
    storage.removeItem('savora_checkout_intent');
    storage.removeItem(draftKey(intentKey));
    return Object.assign({ intentKey, response }, draft);
  }

  function cancel(options) {
    const intentKey = options.storage.getItem('savora_checkout_intent');
    options.storage.removeItem('savora_checkout_intent');
    if (intentKey) options.storage.removeItem(draftKey(intentKey));
  }

  root.SavoraCheckoutIntent = { submit, cancel };
}(window));
