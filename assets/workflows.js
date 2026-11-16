document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const question = form.dataset.confirm;
    if (question && !window.confirm(question)) {
        event.preventDefault();
        return;
    }
    if (form.method === 'post' && form.closest('.workflow-card, .workflow-details')) {
        if (form.dataset.submitting) {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        if (event.submitter instanceof HTMLButtonElement && !event.submitter.name) {
            event.submitter.textContent = 'Saving…';
        }
    }
});

document.addEventListener('click', (event) => {
    if (event.target.closest('button[data-print]')) window.print();
});

window.addEventListener('pageshow', (event) => {
    if (event.persisted && document.querySelector('[data-submitting]')) window.location.reload();
});

