(() => {
    'use strict';
    const root = document.querySelector('[data-yoro-onboarding]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[data-yoro-tab]'));
    const feedback = root.querySelector('[data-yoro-feedback]');
    const select = (tab) => tabs.forEach((item) => {
        const active = item === tab;
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
        root.querySelector(`#${item.getAttribute('aria-controls')}`).hidden = !active;
    });
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', (event) => {
            const next = { ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1 }[event.key];
            if (next === undefined) return;
            event.preventDefault();
            select(tabs[next]);
            tabs[next].focus();
        });
    });
    const clearVerification = () => {
        const grant = root.querySelector('[data-yoro-grant-status]');
        if (grant) grant.textContent = grant.dataset.unverifiedLabel || 'ChatGPT connection: Not yet verified';
    };
    const invalidate = () => {
        const grant = root.querySelector('[data-yoro-grant-status]');
        const stale = grant?.dataset.expires &&
            (!Number.isFinite(Date.parse(grant.dataset.expires)) || Date.parse(grant.dataset.expires) <= Date.now());
        if (stale) {
            clearVerification();
            feedback.textContent = 'Connection status expired. Sign in from ChatGPT to verify access.';
        }
    };
    invalidate();
    window.setInterval(invalidate, 1000);
    const copyButton = root.querySelector('[data-yoro-copy-url]');
    const copyLabel = copyButton?.textContent;
    let copyResetTimer;
    copyButton?.addEventListener('click', async () => {
        window.clearTimeout(copyResetTimer);
        copyButton.textContent = copyLabel;
        invalidate();
        const input = root.querySelector('#yoro-server-url');
        if (!input?.value) return;
        try {
            await navigator.clipboard.writeText(input.value);
            copyButton.textContent = 'Copied!';
            copyResetTimer = window.setTimeout(() => { copyButton.textContent = copyLabel; }, 2000);
            feedback.textContent = 'Server URL copied.';
        } catch (_) {
            input.focus(); input.select();
            feedback.textContent = 'Select and copy the Server URL.';
        }
    });
})();
