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
    const invalidate = () => {
        const box = root.querySelector('[data-yoro-server-url]');
        if (box && (!Number.isFinite(Date.parse(box.dataset.expires)) || Date.parse(box.dataset.expires) <= Date.now())) {
            box.querySelector('input').value = '';
            box.querySelector('button').disabled = true;
            feedback.textContent = 'Connection status expired. Refresh before copying the Server URL.';
        }
    };
    invalidate();
    window.setInterval(invalidate, 1000);
    root.querySelector('[data-yoro-copy-url]')?.addEventListener('click', async () => {
        invalidate();
        const input = root.querySelector('#yoro-server-url');
        if (!input?.value) return;
        try {
            await navigator.clipboard.writeText(input.value);
            feedback.textContent = 'Server URL copied.';
        } catch (_) {
            input.focus(); input.select();
            feedback.textContent = 'Select and copy the Server URL.';
        }
    });
    root.querySelector('[data-yoro-refresh]')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        button.disabled = true;
        try {
            const response = await fetch(YoRoOnboarding.handshakeUrl, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': YoRoOnboarding.nonce, Accept: 'application/json' } });
            if (!response.ok) throw new Error('Connection could not be verified.');
            window.location.reload();
        } catch (_) {
            const box = root.querySelector('[data-yoro-server-url]');
            if (box) { box.querySelector('input').value = ''; box.querySelector('button').disabled = true; }
            feedback.textContent = 'Connection could not be verified. Try refreshing again.';
            button.disabled = false;
        }
    });
})();
