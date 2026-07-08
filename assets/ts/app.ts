import '../css/app.css';

const secureForms = document.querySelectorAll<HTMLFormElement>('[data-secure-form]');

for (const form of secureForms) {
  form.addEventListener('submit', () => {
    const submitter = form.querySelector<HTMLButtonElement>('button[type="submit"]');
    if (submitter) {
      submitter.disabled = true;
      submitter.dataset.originalLabel = submitter.textContent ?? '';
      submitter.textContent = 'Working';
    }
  });
}

const mailBodies = document.querySelectorAll<HTMLElement>('[data-mail-body]');

for (const body of mailBodies) {
  for (const link of body.querySelectorAll<HTMLAnchorElement>('a[href]')) {
    link.rel = 'noreferrer noopener';
    link.target = '_blank';
  }
}

const selectAll = document.querySelector<HTMLInputElement>('[data-select-all]');
const messageSelects = Array.from(document.querySelectorAll<HTMLInputElement>('[data-message-select]'));

if (selectAll && messageSelects.length > 0) {
  const syncSelectAll = () => {
    const selected = messageSelects.filter((checkbox) => checkbox.checked).length;
    selectAll.checked = selected === messageSelects.length;
    selectAll.indeterminate = selected > 0 && selected < messageSelects.length;
  };

  selectAll.addEventListener('change', () => {
    for (const checkbox of messageSelects) {
      checkbox.checked = selectAll.checked;
    }
    syncSelectAll();
  });

  for (const checkbox of messageSelects) {
    checkbox.addEventListener('change', syncSelectAll);
  }

  syncSelectAll();
}

const recipientInputs = Array.from(document.querySelectorAll<HTMLInputElement>('[data-recipient-input]'));
const recipientSuggestions = Array.from(
  document.querySelectorAll<HTMLButtonElement>('[data-recipient-suggestion]'),
);
let activeRecipientInput = recipientInputs[0] ?? null;

const currentRecipientToken = (input: HTMLInputElement) => {
  const lastComma = input.value.lastIndexOf(',');
  const lastSemicolon = input.value.lastIndexOf(';');
  const tokenStart = Math.max(lastComma, lastSemicolon) + 1;
  return input.value.slice(tokenStart).trim().toLowerCase();
};

const syncRecipientSuggestions = () => {
  if (!activeRecipientInput || recipientSuggestions.length === 0) {
    return;
  }

  const token = currentRecipientToken(activeRecipientInput);
  let visible = 0;

  for (const suggestion of recipientSuggestions) {
    const haystack = (suggestion.dataset.search ?? suggestion.dataset.email ?? '').toLowerCase();
    const matches = token === '' || haystack.includes(token);
    const show = matches && visible < 12;
    suggestion.hidden = !show;

    if (show) {
      visible += 1;
    }
  }
};

const insertRecipient = (input: HTMLInputElement, email: string) => {
  const value = input.value;
  const lastComma = value.lastIndexOf(',');
  const lastSemicolon = value.lastIndexOf(';');
  const tokenStart = Math.max(lastComma, lastSemicolon) + 1;
  const prefix = tokenStart > 0 ? `${value.slice(0, tokenStart).trimEnd()} ` : '';
  input.value = `${prefix}${email}, `;
  input.focus();
  input.dispatchEvent(new Event('input', { bubbles: true }));
};

if (recipientInputs.length > 0 && recipientSuggestions.length > 0) {
  for (const input of recipientInputs) {
    input.addEventListener('focus', () => {
      activeRecipientInput = input;
      syncRecipientSuggestions();
    });
    input.addEventListener('input', syncRecipientSuggestions);
  }

  for (const suggestion of recipientSuggestions) {
    suggestion.addEventListener('click', () => {
      const email = suggestion.dataset.email ?? '';
      if (activeRecipientInput && email !== '') {
        insertRecipient(activeRecipientInput, email);
      }
    });
  }

  syncRecipientSuggestions();
}

const mailboxShortcuts = document.querySelector<HTMLElement>('[data-mailbox-shortcuts]');

const isEditableTarget = (target: EventTarget | null) => {
  if (!(target instanceof HTMLElement)) {
    return false;
  }

  return Boolean(target.closest('input, textarea, select, button, a[href], [contenteditable="true"]'));
};

if (mailboxShortcuts) {
  const messageRows = Array.from(mailboxShortcuts.querySelectorAll<HTMLElement>('[data-message-row]'));
  const searchInput = mailboxShortcuts.querySelector<HTMLInputElement>('[data-mailbox-search]');
  const composeLink = mailboxShortcuts.querySelector<HTMLAnchorElement>('[data-compose-link]');
  const folderDropTargets = Array.from(document.querySelectorAll<HTMLElement>('[data-folder-drop-target]'));
  const dragMoveForm = document.querySelector<HTMLFormElement>('[data-drag-move-form]');
  const dragMessageIdInput = dragMoveForm?.querySelector<HTMLInputElement>('[data-drag-message-id]');
  const dragTargetFolderInput = dragMoveForm?.querySelector<HTMLInputElement>('[data-drag-target-folder]');
  const firstTabbableRow = messageRows.findIndex((row) => row.tabIndex === 0);
  let activeMessageIndex = firstTabbableRow >= 0 ? firstTabbableRow : messageRows.length > 0 ? 0 : -1;

  const setActiveMessage = (index: number) => {
    if (messageRows.length === 0) {
      return;
    }

    activeMessageIndex = Math.min(Math.max(index, 0), messageRows.length - 1);
    for (const [rowIndex, row] of messageRows.entries()) {
      row.tabIndex = rowIndex === activeMessageIndex ? 0 : -1;
    }
  };

  const focusMessage = (index: number) => {
    setActiveMessage(index);
    messageRows[activeMessageIndex].focus();
  };

  const openFocusedMessage = () => {
    const row = messageRows[activeMessageIndex];
    const link = row?.querySelector<HTMLAnchorElement>('[data-message-link]');
    link?.click();
  };

  const replyToFocusedMessage = () => {
    const row = messageRows[activeMessageIndex];
    const replyUrl = row?.dataset.replyUrl;
    if (replyUrl) {
      window.location.href = replyUrl;
    }
  };

  const toggleFocusedMessageSelection = () => {
    const row = messageRows[activeMessageIndex];
    const checkbox = row?.querySelector<HTMLInputElement>('[data-message-select]');
    if (!checkbox || checkbox.disabled) {
      return;
    }

    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event('change', { bubbles: true }));
  };

  setActiveMessage(activeMessageIndex);

  for (const [index, row] of messageRows.entries()) {
    row.addEventListener('focus', () => {
      setActiveMessage(index);
    });

    row.addEventListener('dragstart', (event) => {
      const messageId = row.dataset.messageId ?? '';
      if (!event.dataTransfer || !messageId) {
        event.preventDefault();
        return;
      }

      row.classList.add('is-dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', messageId);
    });

    row.addEventListener('dragend', () => {
      row.classList.remove('is-dragging');
      for (const target of folderDropTargets) {
        target.classList.remove('is-drop-active');
      }
    });
  }

  for (const target of folderDropTargets) {
    target.addEventListener('dragover', (event) => {
      if (!dragMoveForm || !target.dataset.targetFolder) {
        return;
      }

      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
      }
      target.classList.add('is-drop-active');
    });

    target.addEventListener('dragleave', () => {
      target.classList.remove('is-drop-active');
    });

    target.addEventListener('drop', (event) => {
      event.preventDefault();
      target.classList.remove('is-drop-active');

      const messageId = event.dataTransfer?.getData('text/plain') ?? '';
      const targetFolder = target.dataset.targetFolder ?? '';
      if (!dragMoveForm || !dragMessageIdInput || !dragTargetFolderInput || !messageId || !targetFolder) {
        return;
      }

      dragMessageIdInput.value = messageId;
      dragTargetFolderInput.value = targetFolder;
      dragMoveForm.submit();
    });
  }

  document.addEventListener('keydown', (event) => {
    if (isEditableTarget(event.target)) {
      return;
    }

    if (event.key === '/') {
      event.preventDefault();
      searchInput?.focus();
      searchInput?.select();
      return;
    }

    if (event.key === 'c' && composeLink) {
      event.preventDefault();
      composeLink.click();
      return;
    }

    if (messageRows.length > 0 && (event.key === 'j' || event.key === 'ArrowDown')) {
      event.preventDefault();
      focusMessage(activeMessageIndex + 1);
      return;
    }

    if (messageRows.length > 0 && (event.key === 'k' || event.key === 'ArrowUp')) {
      event.preventDefault();
      focusMessage(activeMessageIndex - 1);
      return;
    }

    if (messageRows.length > 0 && event.key === 'Home') {
      event.preventDefault();
      focusMessage(0);
      return;
    }

    if (messageRows.length > 0 && event.key === 'End') {
      event.preventDefault();
      focusMessage(messageRows.length - 1);
      return;
    }

    if (messageRows.length > 0 && (event.key === ' ' || event.key.toLowerCase() === 'x')) {
      event.preventDefault();
      toggleFocusedMessageSelection();
      return;
    }

    if (messageRows.length > 0 && event.key === 'Enter') {
      event.preventDefault();
      openFocusedMessage();
      return;
    }

    if (messageRows.length > 0 && event.key === 'r') {
      event.preventDefault();
      replyToFocusedMessage();
    }
  });
}
