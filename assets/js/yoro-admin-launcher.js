(function () {
	'use strict';

	function initializeIcon(launcher, restored) {
		const image = launcher.querySelector('.yoro-admin-launcher__icon img');
		if (!image) return;
		const source = image.getAttribute('data-animation-src');
		const staticSource = image.getAttribute('src');
		const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
		let cachedBlob = null;
		let objectURL = null;
		let controller = null;
		let deadline = null;
		let generation = 0;
		let removeImageEvents = function () {};

		function stop() {
			++generation;
			if (controller) controller.abort();
			controller = null;
			window.clearTimeout(deadline);
			removeImageEvents();
			if (objectURL) window.URL.revokeObjectURL(objectURL);
			objectURL = null;
		}

		function play() {
			stop();
			if (motion.matches || !source) {
				image.setAttribute('src', staticSource);
				image.style.visibility = '';
				return;
			}
			const current = generation;
			image.style.visibility = 'hidden';
			image.removeAttribute('src');
			function show(url, fallback) {
				if (generation !== current) return;
				removeImageEvents();
				const loaded = function () { image.style.visibility = ''; removeImageEvents(); };
				const failed = function () { removeImageEvents(); if (fallback) fallback(); else loaded(); };
				removeImageEvents = function () {
					image.removeEventListener('load', loaded);
					image.removeEventListener('error', failed);
				};
				image.addEventListener('load', loaded);
				image.addEventListener('error', failed);
				image.setAttribute('src', url);
			}
			function fallback() {
				if (generation !== current) return;
				if (objectURL) window.URL.revokeObjectURL(objectURL);
				objectURL = null;
				const url = new window.URL(source, document.baseURI);
				if (url.origin !== window.location.origin) { show(staticSource); return; }
				url.searchParams.set('yoro_play', Date.now() + '-' + Math.random().toString(36).slice(2));
				show(url.href, function () { show(staticSource); });
			}
			async function prepare() {
				try {
					const url = new window.URL(source, document.baseURI);
					if (url.origin !== window.location.origin) { show(staticSource); return; }
					if (!cachedBlob) {
						controller = new window.AbortController();
						deadline = window.setTimeout(function () { if (controller) controller.abort(); }, 10000);
						const response = await window.fetch(url.href, { cache: 'force-cache', mode: 'same-origin', redirect: 'error', signal: controller.signal });
						if (!response.ok) throw new Error('SVG unavailable');
						const blob = await response.blob();
						if (generation !== current) return;
						cachedBlob = blob;
					}
					if (generation !== current) return;
					window.clearTimeout(deadline);
					controller = null;
					// A fresh image document resets SMIL while the SVG bytes stay browser-cached.
					objectURL = window.URL.createObjectURL(cachedBlob);
					show(objectURL, fallback);
				} catch (_) {
					if (generation !== current) return;
					window.clearTimeout(deadline);
					controller = null;
					fallback();
				}
			}
			prepare();
		}

		function pageShown(event) { if (event.persisted) { restored(); play(); } }
		function pageHidden(event) {
			stop();
			image.style.visibility = 'hidden';
			if (event.persisted) return;
			cachedBlob = null;
			window.removeEventListener('pageshow', pageShown);
			window.removeEventListener('pagehide', pageHidden);
			motion.removeEventListener('change', play);
		}
		window.addEventListener('pageshow', pageShown);
		window.addEventListener('pagehide', pageHidden);
		motion.addEventListener('change', play);
		play();
	}

	function initialize() {
		const template = document.getElementById('yoro-admin-launcher-template');
		if (!template || document.getElementById('yoro-admin-launcher')) return;
		const contents = template.content.cloneNode(true);
		const launcher = contents.querySelector('#yoro-admin-launcher');
		if (!launcher) return;
		const message = launcher.querySelector('.yoro-admin-launcher__message');
		const storageKey = message && message.getAttribute('data-dismiss-key');
		const dismissDuration = 60 * 60 * 1000;
		let completeTyping = function () {};
		function hideDismissedMessage() {
			if (!storageKey) return;
			try {
				const stored = window.localStorage.getItem(storageKey);
				const expiry = Number(stored);
				const now = Date.now();
				if (Number.isSafeInteger(expiry) && expiry > now && expiry <= now + dismissDuration) { completeTyping(); message.remove(); }
				else if (stored !== null) window.localStorage.removeItem(storageKey);
			} catch (_) { /* Storage can be unavailable; keep the page usable. */ }
		}
		hideDismissedMessage();
		initializeIcon(launcher, hideDismissedMessage);
		document.body.appendChild(contents);
		template.remove();

		const hint = launcher.querySelector('.yoro-admin-launcher__hint');
		if (!hint) return;
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
