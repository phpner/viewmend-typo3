document.querySelectorAll('form[data-viewmend-submit-once]').forEach((form) => {
    const status = form.querySelector('[data-unsaved-status]');
    const snapshot = () => JSON.stringify(Array.from(new FormData(form).entries()));
    const initial = snapshot();
    const update = () => {
        if (status) status.textContent = snapshot() === initial ? 'All changes are saved.' : 'You have unsaved changes.';
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = 'true';
        form.setAttribute('aria-busy', 'true');
        form.querySelectorAll('button[type="submit"]').forEach((button) => button.setAttribute('aria-disabled', 'true'));
    });
    window.addEventListener('pageshow', () => {
        delete form.dataset.submitting;
        form.removeAttribute('aria-busy');
        form.querySelectorAll('button[type="submit"]').forEach((button) => button.removeAttribute('aria-disabled'));
        update();
    });
});
