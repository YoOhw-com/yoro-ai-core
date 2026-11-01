(() => {
    'use strict';
    const root = document.getElementById('yoro-support');
    if (!root) return;
    const vi = (document.documentElement.lang || navigator.language || '').toLowerCase().startsWith('vi');
    const words = {
        'You': 'Bạn', 'Support': 'Hỗ trợ', 'Connecting…': 'Đang kết nối…', 'Thinking…': 'Đang tìm câu trả lời…',
        'Support is unavailable. Please try again or contact the store.': 'Hỗ trợ đang gián đoạn. Vui lòng thử lại hoặc liên hệ cửa hàng.',
        'Verify your ordering email': 'Xác minh email đặt hàng', 'Email used at checkout': 'Email đã dùng khi đặt hàng',
        'Send verification code': 'Gửi mã xác minh', 'Verification code': 'Mã xác minh', 'Verify': 'Xác minh',
        'This opens orders linked to the email. People sharing an inbox may see the same orders.': 'Bước này mở các đơn gắn với email. Người dùng chung hộp thư có thể xem cùng các đơn đó.',
        'Code requested for': 'Đã yêu cầu gửi mã tới', 'Change email': 'Đổi email', 'Resend code': 'Gửi lại mã', 'Resend in': 'Gửi lại sau',
        'Invalid or expired code. Check the code or request a new one.': 'Mã không đúng hoặc đã hết hạn. Kiểm tra mã hoặc yêu cầu mã mới.',
        'Please wait before trying again.': 'Vui lòng chờ trước khi thử lại.', 'Email delivery is not ready. Please contact the store.': 'Cửa hàng chưa sẵn sàng gửi mã qua email. Vui lòng liên hệ cửa hàng.',
        'Email verified': 'Email đã xác minh', 'Private access ended. Verify again to view orders.': 'Tra cứu riêng tư đã kết thúc. Xác minh lại để xem đơn.',
        'Choose an order': 'Chọn đơn cần hỗ trợ', 'No orders match this verified email.': 'Không có đơn phù hợp với email đã xác minh.',
        'Select order': 'Chọn đơn', 'Next orders': 'Đơn tiếp theo', 'Other orders': 'Đơn khác', 'Ask about this order': 'Hỏi về đơn này',
        'Estimate your purchase': 'Ước tính đơn mua hàng', 'Search products': 'Tìm sản phẩm', 'Search': 'Tìm', 'Choose a variation': 'Chọn biến thể',
        'Quantity': 'Số lượng', 'Country code': 'Mã quốc gia', 'State / province code': 'Mã bang / tỉnh', 'Postcode': 'Mã bưu chính', 'City (if needed)': 'Thành phố (nếu cần)',
        'Coupon (optional)': 'Mã giảm giá (nếu có)', 'Calculate estimate': 'Tính chi phí dự kiến', 'Remove': 'Xóa',
        'Choose at least one purchasable product or variation.': 'Chọn ít nhất một sản phẩm hoặc biến thể có thể mua.',
        'No supported shipping rate is available for this destination.': 'Không có phí vận chuyển được hỗ trợ cho địa chỉ này.',
        'This checkout integration needs the store checkout.': 'Tích hợp này cần được kiểm tra tại trang thanh toán.',
        'Refresh the selection or verify your email again.': 'Làm mới lựa chọn hoặc xác minh lại email.',
        'Subtotal': 'Tiền sản phẩm', 'Discount': 'Giảm giá', 'Shipping': 'Vận chuyển', 'Tax': 'Thuế', 'Estimated total': 'Tổng dự kiến',
        'Estimate only. Checkout recalculates the final amount.': 'Đây là ước tính. Trang thanh toán sẽ tính lại số tiền cuối cùng.',
        'Payment methods': 'Phương thức thanh toán', 'Recorded tracking': 'Thông tin vận chuyển đã ghi nhận', 'No tracking has been recorded.': 'Chưa có thông tin vận chuyển được ghi nhận.',
        'Stored information does not verify live carrier delivery or an ETA.': 'Thông tin đã lưu không xác nhận hành trình thực tế hay ngày giao dự kiến.',
        'Recorded refund': 'Hoàn tiền đã ghi nhận', 'Explain this estimate': 'Giải thích chi phí này', 'Verify coupon email': 'Xác minh email cho mã giảm giá',
        'Could not confirm revocation. Retry End private lookup.': 'Chưa xác nhận được việc kết thúc. Hãy thử lại nút Kết thúc tra cứu riêng tư.', 'Send a new message to continue.': 'Gửi tin nhắn mới để tiếp tục.', 'New conversation? Current private access will end.': 'Bắt đầu cuộc trò chuyện mới? Quyền tra cứu riêng tư hiện tại sẽ kết thúc.',
        'Searching…': 'Đang tìm…', 'No matching products. Try a product name.': 'Không có sản phẩm phù hợp. Thử tên sản phẩm khác.',
        'Show product choices': 'Xem lựa chọn sản phẩm', 'What are the shipping and return policies?': 'Chính sách giao hàng và đổi trả như thế nào?',
        'Which products would suit my needs?': 'Sản phẩm nào phù hợp với nhu cầu của tôi?', 'What is the current status of this order?': 'Đơn hàng này đang ở trạng thái nào?',
        'Please explain this estimate, coupon eligibility and payment methods.': 'Hãy giải thích chi phí dự kiến, điều kiện mã giảm giá và phương thức thanh toán.',
        'Payment availability is not enabled.': 'Tra cứu phương thức thanh toán chưa được bật.', 'No payment method is currently available in this context.': 'Chưa có phương thức thanh toán phù hợp với thông tin này.', 'Refresh estimate': 'Tính lại chi phí',
        'Choose a valid destination and quantity.': 'Nhập địa chỉ tính phí và số lượng hợp lệ.', 'Selected context expired. Refresh before asking again.': 'Lựa chọn đã hết hạn. Làm mới trước khi hỏi tiếp.'
    };
    const t = text => vi ? (words[text] || text) : text;
    const panel = root.querySelector('section'), launch = root.querySelector('.yoro-support-launch'), composer = root.querySelector('.yoro-support-composer');
    const input = composer.querySelector('textarea'), send = composer.querySelector('[type=submit]'), retry = root.querySelector('[data-retry]');
    const log = root.querySelector('[role=log]'), status = root.querySelector('[data-status]'), contextArea = root.querySelector('.yoro-support-context');
    const verifiedBar = root.querySelector('.yoro-support-verified');
    let quoteParameters = null, csrf = '', busy = false, pending = null, retryCount = 0, pendingQuestion = '', contextToken = '', contextPrivate = false, contextExpires = 0, verified = false, revoking = false, accessTimer, contextTimer, generation = 0;
    let capabilities = {content: true, products: true, quote: false, coupons: false, payments: false, orders: false};
    const endpoint = root.dataset.endpoint;
    const node = (tag, text = '', className = '') => { const el = document.createElement(tag); if (text) el.textContent = text; if (className) el.className = className; return el; };
    function button(text, action, className = '') { const el = node('button', t(text), className); el.type = 'button'; el.addEventListener('click', action); return el; }
    function field(form, name, label, value = '', attrs = {}) {
        const wrapper = node('label', t(label)), control = node('input'); control.name = name; control.value = value;
        Object.entries(attrs).forEach(([key, val]) => control.setAttribute(key, String(val)));
        wrapper.append(control); form.append(wrapper); return control;
    }
    async function request(path, body) {
        const ownGeneration = generation, controller = new AbortController(), timer = setTimeout(() => controller.abort(), 48000);
        try {
            const response = await fetch(endpoint + path, {method: 'POST', credentials: 'same-origin', cache: 'no-store', headers: {'Content-Type': 'application/json', 'X-YoRo-Support-CSRF': csrf}, body: JSON.stringify(body), signal: controller.signal});
            const data = await response.json();
            if (ownGeneration !== generation) throw {code: 'support_request_superseded'};
            if (!response.ok) { const error = new Error('unavailable'); error.code = data.code || 'support_unavailable'; throw error; }
            return data;
        } finally { clearTimeout(timer); }
    }
    function errorText(error) {
        const values = {support_verification_invalid: 'Invalid or expired code. Check the code or request a new one.', support_verification_retry: 'Please wait before trying again.', support_verification_unavailable: 'Email delivery is not ready. Please contact the store.', support_no_shipping_rate: 'No supported shipping rate is available for this destination.', support_checkout_unsupported: 'This checkout integration needs the store checkout.', support_context_invalid: 'Refresh the selection or verify your email again.', support_destination_required: 'Choose a valid destination and quantity.'};
        return t(values[error.code] || 'Support is unavailable. Please try again or contact the store.');
    }
    async function session() {
        if (csrf) return;
        status.textContent = t('Connecting…'); const result = await request('session', {});
        if (typeof result.csrf !== 'string') throw new Error('unavailable');
        csrf = result.csrf; capabilities = result.capabilities || capabilities; setVerification(result.verification || {verified: false}); status.textContent = '';
        root.querySelectorAll('[data-starter]').forEach(el => { const key = el.dataset.starter; el.hidden = (key === 'orders' && !capabilities.orders) || (key === 'quote' && !capabilities.quote) || (key === 'products' && !capabilities.products); });
    }
    function append(text, role, sources = [], privateEntry = false) {
        const entry = node('div', '', 'yoro-support-entry ' + role); if (privateEntry) entry.dataset.private = 'true';
        const copy = node('p', text); entry.append(node('strong', t(role === 'user' ? 'You' : 'Support')), copy);
        if (Array.isArray(sources)) sources.slice(0, 20).forEach(source => {
            try { const url = new URL(source.url); if (url.protocol !== 'https:' || url.origin !== window.location.origin || url.username || url.password) return;
                const link = node('a', source.title || 'Source', 'yoro-support-source'); link.href = url.href; link.target = '_blank'; link.rel = 'noopener noreferrer'; entry.append(link);
            } catch (_) { /* Discard malformed source links. */ }
        });
        log.append(entry); while (log.children.length > 24) log.firstElementChild.remove(); log.scrollTop = log.scrollHeight; return entry;
    }
    function clearPrivate(keepQuestion = false) {
        clearTimeout(accessTimer); clearTimeout(contextTimer); verified = false; verifiedBar.hidden = true;
        contextToken = ''; contextPrivate = false; contextExpires = 0; quoteParameters = null; pending = null; retry.hidden = true;
        log.querySelectorAll('[data-private]').forEach(el => el.remove()); contextArea.replaceChildren(); if (!keepQuestion) pendingQuestion = '';
    }
    function setVerification(state) {
        clearTimeout(accessTimer); const wasVerified = verified; verified = state.verified === true;
        if (!verified) { if (wasVerified || contextPrivate) clearPrivate(true); else verifiedBar.hidden = true; return; }
        verifiedBar.hidden = false; verifiedBar.querySelector('[data-verified-label]').textContent = t('Email verified') + ' · ' + (state.email_hint || '');
        const remaining = Date.parse(state.expires_at) - Date.now();
        if (!(remaining > 0)) { clearPrivate(); return; }
        accessTimer = setTimeout(() => { generation++; clearPrivate(); busy = false; send.disabled = false; status.textContent = t('Private access ended. Verify again to view orders.'); }, Math.min(remaining, 2147483647));
    }
    async function action(work) {
        if (busy) return; const ownGeneration = generation; busy = true; send.disabled = true;
        try { await session(); status.textContent = ''; await work(); }
        catch (error) { if (error.code === 'support_request_superseded') return; if (error.code === 'support_verification_required') { clearPrivate(true); verificationForm(); } else { status.textContent = errorText(error); } if (pending) retry.hidden = retryCount >= 2; }
        finally { if (ownGeneration === generation) { busy = false; send.disabled = false; } }
    }
    async function sendMessage(message, fromResume = false) {
        if (busy) return;
        await action(async () => {
            if (contextToken && contextExpires <= Date.now()) { contextToken = ''; throw {code: 'support_context_invalid'}; }
            if (!fromResume) append(message, 'user', [], contextPrivate);
            pending = {turn_id: crypto.randomUUID(), message, context_token: contextToken}; retryCount = 0; retry.hidden = true; status.textContent = t('Thinking…');
            const result = await request('turn', pending); handleAnswer(result, message);
        });
    }
    function handleAnswer(result, message) {
        status.textContent = '';
        if (result.status === 'context') { pendingQuestion = message; pending = null; openFlow(result.context); return; }
        if (!result.answer || typeof result.answer.text !== 'string') throw new Error('unavailable');
        append(result.answer.text, 'assistant', result.answer.sources, result.private === true); pending = null; pendingQuestion = ''; input.value = '';
    }
    composer.addEventListener('submit', async event => { event.preventDefault(); const message = input.value.trim(); if (!message || message.length > 2000) return; await sendMessage(message); if (pending) retry.hidden = retryCount >= 2; });
    input.addEventListener('keydown', event => { if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) { event.preventDefault(); composer.requestSubmit(); } });
    retry.addEventListener('click', () => action(async () => { if (!pending || retryCount >= 2) return; retryCount++; const result = await request('turn', pending); handleAnswer(result, pending.message); retry.hidden = !pending || retryCount >= 2; }));
    root.querySelector('[data-reset]').addEventListener('click', () => { if (!window.confirm(t('New conversation? Current private access will end.'))) return; action(async () => { const result = await request('session', {reset: true}); csrf = result.csrf; clearPrivate(); log.replaceChildren(); input.value = ''; }); });
    root.querySelector('[data-end-private]').addEventListener('click', async () => {
        if (revoking) return; revoking = true; generation++; clearPrivate(); busy = true; send.disabled = true;
        try { await request('verification', {action: 'revoke'}); status.textContent = t('Private access ended. Verify again to view orders.'); }
        catch (_) { verifiedBar.hidden = false; verifiedBar.querySelector('[data-verified-label]').textContent = t('Could not confirm revocation. Retry End private lookup.'); status.textContent = t('Could not confirm revocation. Retry End private lookup.'); }
        finally { revoking = false; busy = false; send.disabled = false; }
    });
    function verificationForm(after = () => orders()) {
        contextArea.replaceChildren(); const card = node('div', '', 'yoro-support-flow'); card.append(node('h3', t('Verify your ordering email')), node('p', t('This opens orders linked to the email. People sharing an inbox may see the same orders.')));
        const form = node('form'); const email = field(form, 'email', 'Email used at checkout', '', {type: 'email', required: '', maxlength: '254', autocomplete: 'email'});
        const submit = node('button', t('Send verification code')); submit.type = 'submit'; form.append(submit); card.append(form); contextArea.append(card); email.focus();
        form.addEventListener('submit', event => { event.preventDefault(); action(async () => { clearPrivate(true); const sent = await request('verification', {action: 'send', email: email.value}); email.value = ''; codeForm(sent, after); }); });
    }
    function codeForm(sent, after) {
        contextArea.replaceChildren(); const card = node('div', '', 'yoro-support-flow'), form = node('form');
        card.append(node('h3', t('Verification code')), node('p', t('Code requested for') + ' ' + sent.email_hint));
        const code = field(form, 'code', 'Verification code', '', {inputmode: 'numeric', pattern: '[0-9]{6}', maxlength: '6', autocomplete: 'one-time-code', required: ''});
        const submit = node('button', t('Verify')); submit.type = 'submit'; form.append(submit); card.append(form);
        const change = button('Change email', () => verificationForm(after)); card.append(change);
        const resend = button('Resend code', () => verificationForm(after)); resend.disabled = true; card.append(resend);
        const until = Date.now() + Number(sent.resend_seconds || 60) * 1000, ownGeneration = generation;
        function tick() { if (!card.isConnected || ownGeneration !== generation) return; const seconds = Math.max(0, Math.ceil((until - Date.now()) / 1000)); resend.textContent = seconds ? t('Resend in') + ' ' + seconds + 's' : t('Resend code'); resend.disabled = seconds > 0; if (seconds) setTimeout(tick, 1000); }
        contextArea.append(card); tick(); code.focus();
        form.addEventListener('submit', event => { event.preventDefault(); action(async () => { const state = await request('verification', {action: 'confirm', challenge_id: sent.challenge_id, code: code.value}); code.value = ''; setVerification(state); await after(); }); });
    }
    async function orders(page = 1) {
        const result = await request('context', {kind: 'orders', page}); contextArea.replaceChildren(); const card = node('div', '', 'yoro-support-flow'); card.dataset.private = 'true'; card.append(node('h3', t('Choose an order')));
        if (!result.orders.length) card.append(node('p', t('No orders match this verified email.')));
        result.orders.forEach(order => { const row = node('div', '', 'yoro-support-order'); row.append(node('strong', '#' + order.number), node('p', [order.date, order.status, order.total + ' ' + order.currency].join(' · ')), button('Select order', () => action(async () => {
            const selected = await request('context', {kind: 'order', params: {reference: order.reference}}); selectContext(selected); renderCard(selected.card); contextArea.append(button('Other orders', () => action(() => orders()))); const question = pendingQuestion || t('What is the current status of this order?'); setTimeout(() => sendMessage(question, !!pendingQuestion), 0);
        }))); card.append(row); });
        if (result.has_next) card.append(button('Next orders', () => action(() => orders(page + 1)))); contextArea.append(card);
    }
    function selectContext(result) {
        contextToken = result.context_token; contextPrivate = result.private === true; contextExpires = Date.parse(result.expires_at);
        clearTimeout(contextTimer); const token = contextToken;
        contextTimer = setTimeout(() => { if (contextToken === token) { generation++; contextToken = ''; contextArea.replaceChildren(); pending = null; retry.hidden = true; busy = false; send.disabled = false; status.textContent = t('Selected context expired. Refresh before asking again.'); } }, Math.max(0, contextExpires - Date.now()));
    }
    function renderCard(card) {
        contextArea.replaceChildren(); const box = node('div', '', 'yoro-support-card'); if (contextPrivate) box.dataset.private = 'true'; const data = card.data;
        box.append(node('h3', card.title));
        if (card.kind === 'order') {
            box.append(node('p', data.status + ' · ' + data.payment));
            data.items.forEach(item => box.append(node('p', item.name + ' × ' + item.quantity + ' · ' + item.total + ' ' + data.currency)));
            box.append(node('strong', data.total + ' ' + data.currency), node('p', t('Recorded refund') + ': ' + data.refund), node('h4', t('Recorded tracking')));
            if (!data.tracking.length) box.append(node('p', t('No tracking has been recorded.')));
            data.tracking.forEach(item => box.append(node('p', item.carrier + ' · ' + item.number)));
            box.append(node('small', t('Stored information does not verify live carrier delivery or an ETA.')));
        } else {
            data.items.forEach(item => box.append(node('p', item.name + ' × ' + item.quantity)));
            const list = node('dl'); [['Subtotal', data.subtotal], ['Discount', data.discount], ['Shipping', data.shipping], ['Tax', data.tax], ['Estimated total', data.total]].forEach(([label, value]) => { list.append(node('dt', t(label)), node('dd', value + ' ' + data.currency)); }); box.append(list);
            const packages = new Map();
            (data.shipping_rates || []).forEach(rate => { if (!packages.has(rate.package)) packages.set(rate.package, []); packages.get(rate.package).push(rate); });
            packages.forEach((rates, index) => { if (rates.length < 2 || !quoteParameters) return; const label = node('label', t('Shipping') + ' · ' + (index + 1)), select = node('select'); rates.forEach(rate => { const option = node('option', rate.label + ' · ' + rate.cost + ' ' + data.currency); option.value = rate.id; option.selected = rate.selected === true; select.append(option); }); label.append(select); box.append(label); select.addEventListener('change', () => action(async () => { quoteParameters.params.shipping_rates = quoteParameters.params.shipping_rates || []; quoteParameters.params.shipping_rates[index] = select.value; const result = await request('context', quoteParameters); selectContext(result); renderCard(result.card); })); });
            box.append(node('small', t('Estimate only. Checkout recalculates the final amount.') + ' ' + new Date(data.quoted_at).toLocaleTimeString()), node('p', data.coupon.message), node('h4', t('Payment methods')), node('p', data.payment_state === 'disabled' ? t('Payment availability is not enabled.') : (data.payment_methods.join(', ') || t('No payment method is currently available in this context.'))));
            if (data.coupon.state === 'verification_required') { const heldQuote = quoteParameters; box.append(button('Verify coupon email', () => verificationForm(async () => { if (!heldQuote) { quoteForm(card.kind); return; } quoteParameters = heldQuote; const refreshed = await request('context', heldQuote); selectContext(refreshed); renderCard(refreshed.card); }))); }
            box.append(button('Explain this estimate', () => sendMessage(pendingQuestion || t('Please explain this estimate, coupon eligibility and payment methods.'), !!pendingQuestion)), button('Refresh estimate', () => quoteForm(card.kind)));
        }
        contextArea.append(box);
    }
    function quoteForm(kind = 'quote') {
        contextArea.replaceChildren(); contextToken = ''; contextPrivate = false;
        const box = node('div', '', 'yoro-support-flow'); box.append(node('h3', t('Estimate your purchase')));
        const searchForm = node('form'), search = field(searchForm, 'query', 'Search products', '', {maxlength: '120'}), submitSearch = node('button', t('Search')); submitSearch.type = 'submit'; searchForm.append(submitSearch);
        const results = node('div'), selected = node('div'), items = new Map(), form = node('form');
        box.append(searchForm, results, selected, form); contextArea.append(box);
        function renderItems() { selected.replaceChildren(); items.forEach((item, id) => { const row = node('div', '', 'yoro-support-quote-item'); const quantity = node('input'); quantity.type = 'number'; quantity.min = '1'; quantity.max = '100'; quantity.value = item.quantity; quantity.setAttribute('aria-label', t('Quantity')); quantity.addEventListener('change', () => { item.quantity = Number(quantity.value); }); row.append(node('span', item.name), quantity, button('Remove', () => { items.delete(id); renderItems(); })); selected.append(row); }); }
        searchForm.addEventListener('submit', event => { event.preventDefault(); action(async () => { const result = await request('context', {kind: 'products', query: search.value}); results.replaceChildren(); if (!result.products.length) results.append(node('p', t('No matching products. Try a product name.'))); result.products.forEach(product => { const choice = button(product.name + ' · ' + product.price + (product.kind === 'variable' ? ' · ' + t('Choose a variation') : ''), () => { if (product.kind === 'variable') { search.value = product.name; return; } if (items.size < 10 || items.has(product.id)) { items.set(product.id, {name: product.name, quantity: 1}); renderItems(); } }); results.append(choice); }); }); });
        const country = field(form, 'country', 'Country code', '', {required: '', minlength: '2', maxlength: '2', placeholder: 'US / VN'}), state = field(form, 'state', 'State / province code', '', {maxlength: '100'}), postcode = field(form, 'postcode', 'Postcode', '', {required: '', maxlength: '100'}), city = field(form, 'city', 'City (if needed)', '', {maxlength: '100'}), coupon = field(form, 'coupon', 'Coupon (optional)', '', {maxlength: '100'});
        coupon.parentElement.hidden = !capabilities.coupons; coupon.disabled = !capabilities.coupons;
        const calculate = node('button', t('Calculate estimate')); calculate.type = 'submit'; form.append(calculate);
        form.addEventListener('submit', event => { event.preventDefault(); action(async () => { if (!items.size) { throw {code: 'support_context_invalid'}; } const params = {items: [...items].map(([product_id, item]) => ({product_id, quantity: item.quantity})), destination: {country: country.value.toUpperCase(), state: state.value, postcode: postcode.value, city: city.value}, coupon: coupon.value}; quoteParameters = {kind, params}; const result = await request('context', {kind, params}); selectContext(result); renderCard(result.card); }); });
    }
    function openFlow(purpose) {
        if (purpose === 'orders') { if (!capabilities.orders) return; if (!verified) verificationForm(); else orders().catch(error => { if (error.code === 'support_verification_required') { clearPrivate(true); verificationForm(); } else status.textContent = errorText(error); }); }
        else quoteForm({coupons: 'coupon', payments: 'payment'}[purpose] || 'quote');
    }
    root.querySelectorAll('[data-starter]').forEach(el => el.addEventListener('click', () => {
        const key = el.dataset.starter;
        if (key === 'products' || key === 'policies') sendMessage(t(key === 'products' ? 'Which products would suit my needs?' : 'What are the shipping and return policies?'));
        else action(async () => { openFlow(key); });
    }));
    function close() { panel.hidden = true; launch.setAttribute('aria-expanded', 'false'); launch.focus(); }
    launch.addEventListener('click', () => { if (!panel.hidden) { close(); return; } panel.hidden = false; panel.setAttribute('aria-modal', String(window.matchMedia('(max-width:480px)').matches)); launch.setAttribute('aria-expanded', 'true'); input.focus(); action(async () => {}); });
    root.querySelector('[data-close]').addEventListener('click', close);
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); close(); }
        if (event.key === 'Tab' && window.matchMedia('(max-width:480px)').matches) { const controls = [...panel.querySelectorAll('button,input,textarea,select,summary,a')].filter(el => !el.disabled && el.getClientRects().length); const first = controls[0], last = controls[controls.length - 1]; if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); } else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); } }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden && csrf && !panel.hidden) action(async () => { const state = await request('verification', {action: 'current'}); setVerification(state); }); });
})();
