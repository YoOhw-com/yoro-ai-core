(function () {
	'use strict';

	function initialize() {
		const template = document.getElementById('yoro-admin-launcher-template');
		if (!template || document.getElementById('yoro-admin-launcher')) return;
		const contents = template.content.cloneNode(true);
		const launcher = contents.querySelector('#yoro-admin-launcher');
		if (!launcher) return;
		const message = launcher.querySelector('.yoro-admin-launcher__message');
		const storageKey = message && message.getAttribute('data-dismiss-key');
		const dismissDuration = 60 * 60 * 1000;
		if (storageKey) {
			try {
				const stored = window.localStorage.getItem(storageKey);
				const expiry = Number(stored);
				const now = Date.now();
				if (Number.isSafeInteger(expiry) && expiry > now && expiry <= now + dismissDuration) message.remove();
				else if (stored !== null) window.localStorage.removeItem(storageKey);
			} catch (_) { /* Storage can be unavailable; keep the page usable. */ }
		}
		document.body.appendChild(contents);
		template.remove();

		const hint = launcher.querySelector('.yoro-admin-launcher__hint');
		if (!hint) return;
		let completeTyping = function () {};
		const dismiss = launcher.querySelector('.yoro-admin-launcher__dismiss');
		if (dismiss && message) dismiss.addEventListener('click', function (event) {
			event.preventDefault();
			event.stopPropagation();
			completeTyping();
			const restoreFocus = message.contains(document.activeElement);
			try {
				if (storageKey) window.localStorage.setItem(storageKey, String(Date.now() + dismissDuration));
			} catch (_) { /* Current-page dismissal still works without persistence. */ }
			message.remove();
			if (restoreFocus) launcher.querySelector('.yoro-admin-launcher__icon').focus();
		});
		const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
		if (motion.matches || window.getComputedStyle(hint).animationName !== 'yoro-launcher-speak') return;

		const fullText = hint.textContent;
		const copy = document.createElement('span');
		const typed = document.createElement('span');
		copy.className = 'yoro-admin-launcher__copy';
		typed.className = 'yoro-admin-launcher__typed';
		copy.textContent = fullText;
		copy.setAttribute('aria-hidden', 'true');
		typed.setAttribute('aria-hidden', 'true');
		hint.setAttribute('aria-label', fullText);
		hint.replaceChildren(copy, typed);

		const characters = Array.from(fullText);
		let timer = null;
		let started = false;
		let stopped = false;
		let index = 0;

		function finish() {
			stopped = true;
			window.clearTimeout(timer);
			typed.textContent = fullText;
			hint.removeEventListener('animationend', start);
			hint.removeEventListener('focus', finish);
			if (dismiss) dismiss.removeEventListener('focus', finish);
			launcher.removeEventListener('click', finish);
			window.removeEventListener('pagehide', finish);
			motion.removeEventListener('change', motionChanged);
		}

		function typeNext() {
			if (stopped) return;
			if (!launcher.isConnected || index >= characters.length) { finish(); return; }
			typed.textContent += characters[index++];
			if (index === characters.length) { finish(); return; }
			const delay = /[.!?,]/.test(characters[index - 1]) ? 220 : 28 + (index * 17 % 35);
			timer = window.setTimeout(typeNext, delay);
		}

		function start(event) {
			if (event.target !== hint || event.animationName !== 'yoro-launcher-speak' || started || stopped) return;
			started = true;
			typeNext();
		}

		function motionChanged(event) {
			if (event.matches) finish();
		}

		completeTyping = finish;
		if (dismiss) dismiss.addEventListener('focus', finish);
		hint.addEventListener('animationend', start);
		hint.addEventListener('focus', finish);
		launcher.addEventListener('click', finish);
		window.addEventListener('pagehide', finish);
		motion.addEventListener('change', motionChanged);
	}

	if (document.readyState === 'complete') initialize();
	else window.addEventListener('load', initialize, { once: true });
})();
