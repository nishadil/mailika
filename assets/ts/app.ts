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
