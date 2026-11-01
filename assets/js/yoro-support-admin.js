(() => {
    'use strict';
    const form = document.getElementById('yoro-support-settings');
    if (!form) return;
    let dirty = false, searchGeneration = 0;
    const panels = [...document.querySelectorAll('.ys-admin-panel')];
    const tabs = [...form.querySelectorAll('[data-tab]')];
    function selectTab(tab) {
        tabs.forEach(item => { const active = item === tab; item.classList.toggle('nav-tab-active', active); item.setAttribute('aria-selected', String(active)); item.tabIndex = active ? 0 : -1; });
        panels.forEach(panel => { panel.hidden = panel.id !== 'ys-tab-' + tab.dataset.tab; });
    }
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => selectTab(tab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            selectTab(tabs[next]); tabs[next].focus();
        });
    });
    tabs[0] && selectTab(tabs[0]);
    form.addEventListener('input', () => { dirty = true; });
    form.addEventListener('submit', () => { dirty = false; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    async function search(type, query) {
        const body = new URLSearchParams({action: 'yoro_support_sources', _ajax_nonce: form.dataset.nonce, type, query});
        const response = await fetch(form.dataset.ajax, {method: 'POST', credentials: 'same-origin', cache: 'no-store', body});
        const result = await response.json();
        if (!response.ok || !result.success || !Array.isArray(result.data.results)) throw new Error('Search unavailable.');
        return result.data.results;
    }
    const selected = form.querySelector('[data-selected-sources]');
    const kinds = {faq: 'FAQ', store: 'Store information', shipping: 'Shipping policy', returns: 'Returns & warranty', payment: 'Payment policy', size: 'Size guide', guide: 'Usage, care & compatibility'};
    const node = (tag, text, className) => { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; };
    function addSource(source) {
        if (selected.children.length >= 50 || selected.querySelector('[data-source-id="' + Number(source.id) + '"]')) return;
        const row = node('article', '', 'ys-source'); row.dataset.sourceId = String(Number(source.id));
        const id = node('input'); id.type = 'hidden'; id.name = 'content_ids[]'; id.value = row.dataset.sourceId;
        const title = node('div'); title.append(node('strong', source.title), node('small', 'Public · approved'));
        const typeLabel = node('label', 'Type '), select = node('select'); select.name = 'source_kind[' + row.dataset.sourceId + ']';
        Object.entries(kinds).forEach(([key, value]) => { const option = node('option', value); option.value = key; select.append(option); });
        typeLabel.append(select);
        const products = node('div', '', 'ys-source-products'), label = node('label', 'Applies to products '), input = node('input'); input.type = 'search'; input.dataset.productSearch = ''; input.placeholder = 'Search products (empty = store-wide)'; label.append(input);
        const results = node('div'); results.dataset.productResults = ''; const mapped = node('div'); mapped.dataset.mappedProducts = '';
        products.append(label, results, mapped);
        const remove = node('button', 'Remove', 'button-link-delete'); remove.type = 'button'; remove.dataset.removeSource = '';
        row.append(id, title, typeLabel, products, remove); selected.append(row); dirty = true;
    }
    let timer;
    form.querySelector('[data-source-search]').addEventListener('input', event => {
        clearTimeout(timer); const generation = ++searchGeneration;
        timer = setTimeout(async () => {
            const container = form.querySelector('[data-source-results]');
            try {
                const results = await search('content', event.target.value);
                if (generation !== searchGeneration) return;
                container.replaceChildren();
                results.forEach(result => { const button = node('button', result.title + ' · ' + result.type, 'button'); button.type = 'button'; button.addEventListener('click', () => addSource(result)); container.append(button); });
            } catch (_) { container.textContent = 'Sources could not be loaded. Try again.'; }
        }, 250);
    });
    form.addEventListener('click', event => { const button = event.target.closest('[data-remove-source]'); if (button) { button.closest('.ys-source').remove(); dirty = true; } });
    form.addEventListener('input', event => {
        if (!event.target.matches('[data-product-search]')) return;
        const input = event.target, row = input.closest('.ys-source');
        clearTimeout(input.searchTimer); const query = input.value;
        input.searchTimer = setTimeout(async () => {
            const container = row.querySelector('[data-product-results]');
            try {
                const results = await search('products', query); if (input.value !== query) return;
                container.replaceChildren();
                results.forEach(result => {
                    const button = node('button', result.title, 'button'); button.type = 'button';
                    button.addEventListener('click', () => {
                        const mapped = row.querySelector('[data-mapped-products]');
                        if (mapped.querySelector('[value="' + Number(result.id) + '"]') || mapped.children.length >= 30) return;
                        const label = node('label', '', 'ys-chip'), box = node('input'); box.type = 'checkbox'; box.checked = true; box.value = String(Number(result.id)); box.name = 'source_products[' + row.dataset.sourceId + '][]'; label.append(box, document.createTextNode(' ' + result.title)); mapped.append(label); dirty = true;
                    }); container.append(button);
                });
            } catch (_) { container.textContent = 'Products could not be loaded.'; }
        }, 250);
    });
    const preview = document.querySelector('.ys-preview');
    // Each bundled animation loads once. Reduced motion permanently switches this page to static.
    const iconMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const iconImages = [...preview.querySelectorAll('[data-support-icon]')];
    const staticIcons = () => iconImages.forEach(image => { if (image.getAttribute('src') !== image.dataset.static) image.setAttribute('src', image.dataset.static); });
    iconImages.forEach(image => {
        let failedStatic = false;
        image.addEventListener('error', () => {
            if (!failedStatic && image.getAttribute('src') !== image.dataset.static) { failedStatic = true; image.setAttribute('src', image.dataset.static); }
            else { image.hidden = true; image.parentElement.querySelector('[data-icon-fallback]').hidden = false; }
        });
        if (image.complete && image.naturalWidth === 0) image.dispatchEvent(new Event('error'));
    });
    if (iconMotion) {
        if (iconMotion.matches) staticIcons();
        const reduceIcons = event => { if (event.matches) staticIcons(); };
        if (iconMotion.addEventListener) iconMotion.addEventListener('change', reduceIcons);
        else if (iconMotion.addListener) iconMotion.addListener(reduceIcons);
    }
    const hex = form.elements['appearance[color]'], picker = document.getElementById('ys-accent-picker');
    const validColor = value => /^#[a-f0-9]{6}$/i.test(value);
    function effectiveColor(value) {
        const channels = [1, 3, 5].map(offset => parseInt(value.slice(offset, offset + 2), 16) / 255).map(channel => channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4);
        return 1.05 / (.2126 * channels[0] + .7152 * channels[1] + .0722 * channels[2] + .05) >= 4.5 ? value : '#2b3a20';
    }
    let lastColor = validColor(hex.value) ? hex.value : '#2b3a20';
    function updatePreview() {
        preview.querySelector('[data-preview-name]').textContent = form.elements['appearance[name]'].value;
        preview.querySelector('[data-preview-welcome]').textContent = form.elements['appearance[welcome]'].value;
        preview.querySelector('[data-preview-launcher]').textContent = form.elements['appearance[launcher]'].value;
        preview.style.setProperty('--ys-preview-accent', effectiveColor(lastColor));
        preview.querySelectorAll('[data-preview-icon]').forEach(icon => { icon.hidden = icon.dataset.previewIcon !== form.elements['appearance[icon]'].value; });
    }
    function hexChanged() {
        const valid = validColor(hex.value);
        hex.setCustomValidity(valid ? '' : 'Enter a color in #RRGGBB format.');
        hex.setAttribute('aria-invalid', String(!valid));
        if (valid) { lastColor = hex.value; picker.value = hex.value; }
        updatePreview();
    }
    function pickerChanged() { hex.value = picker.value; hexChanged(); }
    ['input', 'change'].forEach(event => { hex.addEventListener(event, hexChanged); picker.addEventListener(event, pickerChanged); });
    form.addEventListener('input', updatePreview);
    form.addEventListener('change', updatePreview);
    hexChanged();
    form.querySelector('[data-preview]').addEventListener('click', () => { updatePreview(); preview.showModal(); });
    preview.querySelector('[data-preview-close]').addEventListener('click', () => preview.close());
    preview.querySelectorAll('[data-preview-width]').forEach(button => button.addEventListener('click', () => { preview.classList.toggle('ys-mobile', button.dataset.previewWidth === 'mobile'); }));
})();
