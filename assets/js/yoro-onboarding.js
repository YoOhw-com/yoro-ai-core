(() => {
    'use strict';
    const root = document.querySelector('[data-yoro-onboarding]');
    if (!root) return;
    const tabs = Array.from(root.querySelectorAll('[data-yoro-tab]'));
    root.querySelectorAll('[data-yoro-notice-dismiss]').forEach((button) => {
        button.hidden = false;
        button.addEventListener('click', () => {
            button.closest('[data-yoro-notice]').remove();
            root.querySelector('[data-yoro-tab][aria-selected="true"]')?.focus();
        });
    });
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
    const configNode = root.querySelector('[data-yoro-refresh-config]');
    let config = {};
    try { config = JSON.parse(configNode?.textContent || '{}'); } catch (_) { /* Unverified without config. */ }
    if (!config || typeof config !== 'object' || Array.isArray(config)) config = {};
    const labels = config.labels || {};
    const grant = root.querySelector('[data-yoro-grant-status]');
    const signIn = root.querySelector('[data-yoro-sign-in]');
    const storeUnverified = root.querySelector('[data-yoro-store-unverified]');
    const context = config.context;
    const MIN_RETRY = 30000;
    const MAX_RETRY = 120000;
    const LEAD = 30000;
    let projection = null;
    let timer;
    let flight = null;
    let sequence = 0;
    let retry = MIN_RETRY;
    let due = Date.now();
    let stopped = false;
    let attempted = false;
    const text = (node, value) => { if (node && node.textContent !== value) node.textContent = value; };
    const valid = (value) => {
        if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
        const keys = ['status', 'grant_status', 'runtime_enabled', 'connection_healthy', 'compatible', 'checked_at', 'expires_at'];
        if (Object.keys(value).some(key => !keys.includes(key)) ||
            !['available', 'not_available', 'requested_pending', 'withdrawn', 'unknown'].includes(value.status) ||
            !['granted', 'refresh_needed', 'reconnect_required', 'not_granted', 'revoked', 'unknown'].includes(value.grant_status ?? 'unknown') ||
            ['runtime_enabled', 'connection_healthy', 'compatible'].some(key => typeof value[key] !== 'boolean') ||
            typeof value.checked_at !== 'string' || typeof value.expires_at !== 'string') return false;
        const checked = Date.parse(value.checked_at); const expires = Date.parse(value.expires_at);
        if (!Number.isFinite(checked) || !Number.isFinite(expires) || checked > Date.now() + 30000 ||
            checked < Date.now() - 300000 || expires <= Date.now() || expires > checked + 300000) return false;
        return !['granted', 'refresh_needed'].includes(value.grant_status) || (value.runtime_enabled && value.connection_healthy && value.compatible &&
            ['available', 'requested_pending', 'withdrawn'].includes(value.status));
    };
    const render = (state = 'unverified') => {
        const fresh = valid(projection);
        const status = fresh ? projection.grant_status || 'unknown' : 'unknown';
        text(grant, (fresh ? labels[status] || labels.unknown : labels[state] || labels.unknown) ||
            grant?.dataset.unverifiedLabel || 'ChatGPT connection: Not yet verified');
        if (signIn) signIn.hidden = !fresh || !['not_granted', 'revoked', 'reconnect_required'].includes(status);
        if (storeUnverified) storeUnverified.hidden = fresh && ['granted', 'refresh_needed'].includes(status) && projection.runtime_enabled;

    };
    const schedule = () => {
        window.clearTimeout(timer);
        if (document.hidden) return;
        const expires = valid(projection) ? Date.parse(projection.expires_at) : Infinity;
        // One wake-up timer covers expiry and the next network request; no per-second polling.
        const next = Math.min(flight || stopped || !config.url || !config.nonce ? Infinity : due, expires);
        if (Number.isFinite(next)) timer = window.setTimeout(tick, Math.max(1, next - Date.now()));
    };
    const refresh = async () => {
        if (flight || document.hidden || stopped || !config.url || !config.nonce) return;
        const request = { id: ++sequence, controller: new AbortController(), timedOut: false };
        flight = request;
        if (!valid(projection) && !attempted) render('checking');
        attempted = true;
        const timeout = window.setTimeout(() => {
            request.timedOut = true;
            request.controller.abort();
            // Finish even if a transport ignores AbortSignal. Its eventual response remains inert.
            finish(false, 'unavailable');
        }, 20000);
        const finish = (success, state) => {
            if (flight !== request) return;
            window.clearTimeout(timeout);
            flight = null;
            if (!success) projection = null;
            due = success ? Math.max(Date.now() + MIN_RETRY, Date.parse(projection.expires_at) - LEAD) : Date.now() + retry;
            retry = success ? MIN_RETRY : Math.min(MAX_RETRY, retry * 2);
            render(state);
            schedule();
        };
        try {
            const response = await fetch(config.url, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', signal: request.controller.signal,
                headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
                body: JSON.stringify({ context })
            });
            if (flight !== request || request.timedOut) return;
            if (root.dataset.refreshContext !== context) { stopped = true; finish(false, 'wordpress'); return; }
            if (!response.ok) { finish(false, [401, 403].includes(response.status) ? 'wordpress' : 'unavailable'); return; }
            const data = await response.json();
            if (flight !== request || request.timedOut) return;
            if (root.dataset.refreshContext !== context || data.context !== context || data.state === 'connection_changed') {
                stopped = true; finish(false, 'wordpress'); return;
            }
            if (data.state === 'connection_required') { finish(false, 'wordpress'); return; }
            if (data.state !== 'unverified' || !valid(data.projection)) { finish(false, 'unavailable'); return; }
            if (valid(projection) && Date.parse(data.projection.checked_at) < Date.parse(projection.checked_at)) {
                due = Date.now() + MIN_RETRY; flight = null; window.clearTimeout(timeout); schedule(); return;
            }
            projection = data.projection;
            finish(true);
        } catch (_) { finish(false, 'unavailable'); }
    };
    const tick = () => {
        if (projection && !valid(projection)) { projection = null; render(); }
        if (Date.now() >= due) refresh();
        schedule();
    };
    if (valid(config.projection)) {
        projection = config.projection;
        due = Math.max(Date.now(), Date.parse(projection.expires_at) - LEAD);
    }
    render();
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) window.clearTimeout(timer);
        else tick();
    });
    tick();
    const copyButton = root.querySelector('[data-yoro-copy-url]');
    const copyLabel = copyButton?.textContent;
    let copyResetTimer;
    let copyAttempt = 0;
    copyButton?.addEventListener('click', async () => {
        const attempt = ++copyAttempt;
        window.clearTimeout(copyResetTimer);
        copyButton.textContent = copyLabel;
        const input = root.querySelector('#yoro-server-url');
        if (!input?.value) return;
        try {
            await navigator.clipboard.writeText(input.value);
            if (attempt !== copyAttempt) return;
            copyButton.textContent = labels.copied || 'Copied!';
            copyResetTimer = window.setTimeout(() => { copyButton.textContent = copyLabel; }, 2000);
        } catch (_) {
            if (attempt !== copyAttempt) return;
            input.focus(); input.select();
            copyButton.textContent = labels.copy || 'Select and copy';
        }
    });
})();
