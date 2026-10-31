(() => {
    'use strict';
    const root = document.getElementById('yoro-support');
    if (!root) return;
    const launch = root.querySelector('.yoro-support-launch');
    const panel = root.querySelector('section');
    const form = root.querySelector('form');
    const input = root.querySelector('textarea');
    const send = form.querySelector('[type="submit"]');
    const retry = root.querySelector('[data-retry]');
    const reset = root.querySelector('[data-reset]');
    const log = root.querySelector('[role="log"]');
    const status = root.querySelector('[data-status]');
    let csrf = '', busy = false, pending = null, retryCount = 0;
    const endpoint = root.dataset.endpoint;
    async function request(path, body) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 48000);
        try {
            const response = await fetch(endpoint + path, {method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: {'Content-Type': 'application/json', 'X-YoRo-Support-CSRF': csrf}, body: JSON.stringify(body), signal: controller.signal});
            if (!response.ok) throw new Error('unavailable');
            return await response.json();
        } finally { clearTimeout(timer); }
    }
    async function session() {
        if (csrf) return;
        const result = await request('session', {});
        if (typeof result.csrf !== 'string') throw new Error('unavailable');
        csrf = result.csrf;
    }
    function append(text, role, sources = []) {
        const entry = document.createElement('div');
        entry.className = 'yoro-support-entry ' + role;
        const label = document.createElement('strong');
        label.textContent = role === 'user' ? 'You' : 'Support';
        const copy = document.createElement('p'); copy.textContent = text;
        entry.append(label, copy);
        if (Array.isArray(sources)) sources.slice(0, 20).forEach(source => {
            try {
                const url = new URL(source.url);
                if (url.protocol !== 'https:' || url.origin !== window.location.origin || url.username || url.password) return;
                const link = document.createElement('a'); link.href = url.href; link.textContent = source.title || 'Source';
                link.target = '_blank'; link.rel = 'noopener noreferrer'; entry.append(link);
            } catch (_) { /* Ignore invalid server DTO links. */ }
        });
        log.append(entry);
        // Browser transcript is memory-only and bounded independently from API retention.
        while (log.children.length > 24) log.firstElementChild.remove();
        log.scrollTop = log.scrollHeight;
    }
    function state(value) {
        busy = value; send.disabled = value; reset.disabled = value; input.disabled = value;
        retry.disabled = value; panel.setAttribute('aria-busy', value ? 'true' : 'false');
    }
    async function submit() {
        if (busy || !pending) return;
        state(true); retry.hidden = true; status.textContent = 'Preparing an answer…';
        try {
            await session();
            const result = await request('turn', pending);
            if (!result.answer || typeof result.answer.text !== 'string' || !result.answer.text) throw new Error('unavailable');
            append(result.answer.text, 'assistant', result.answer.sources);
            pending = null; retryCount = 0; status.textContent = ''; input.value = '';
        } catch (_) {
            status.textContent = 'Support is unavailable. Retry this same message or use the site contact details.';
            retry.hidden = retryCount >= 2;
        } finally { state(false); input.focus(); }
    }
    launch.addEventListener('click', () => {
        panel.hidden = !panel.hidden; launch.setAttribute('aria-expanded', String(!panel.hidden));
        if (!panel.hidden) input.focus();
    });
    root.querySelector('[data-close]').addEventListener('click', () => {
        panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); launch.focus();
    });
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { root.querySelector('[data-close]').click(); }
    });
    form.addEventListener('submit', event => {
        event.preventDefault(); if (busy || !input.value.trim()) return;
        if (pending) { status.textContent = 'Retry the pending message or start a new conversation.'; return; }
        pending = {turn_id: crypto.randomUUID(), message: input.value.trim()}; retryCount = 0;
        append(pending.message, 'user'); submit();
    });
    retry.addEventListener('click', () => { if (retryCount < 2) { ++retryCount; submit(); } });
    reset.addEventListener('click', async () => {
        if (busy) return; state(true);
        try {
            await session(); const result = await request('session', {reset: true}); csrf = result.csrf;
            pending = null; retryCount = 0; log.replaceChildren(); input.value = ''; retry.hidden = true; status.textContent = '';
        } catch (_) { status.textContent = 'A new conversation could not be started. Please retry later.'; }
        finally { state(false); input.focus(); }
    });
})();
