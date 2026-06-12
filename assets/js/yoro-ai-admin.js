(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
		} else {
			document.addEventListener('DOMContentLoaded', fn);
		}
	}

	function escapeHtml(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function getBrowserDateTimeFormatter() {
		const config = window.YoRoAIAdmin || {};
		const dateFormat = String(config.dateFormat || 'Y-m-d');
		const timeFormat = String(config.timeFormat || 'H:i');
		const combinedFormat = [dateFormat, timeFormat].filter(Boolean).join(' ').trim() || 'Y-m-d H:i';
		const formatterCache = {};

		function getFormatter(cacheKey, options) {
			if (!formatterCache[cacheKey]) {
				formatterCache[cacheKey] = new Intl.DateTimeFormat(undefined, options);
			}

			return formatterCache[cacheKey];
		}

		function getPart(cacheKey, options, type, date) {
			const parts = getFormatter(cacheKey, options).formatToParts(date);
			const part = parts.find(function(item) {
				return item.type === type;
			});

			return part ? String(part.value || '') : '';
		}

		function getDayPeriod(date, uppercase) {
			const value = getPart('dayPeriod', {
				hour: 'numeric',
				hour12: true
			}, 'dayPeriod', date);

			return uppercase ? value.toUpperCase() : value.toLowerCase();
		}

		function getOrdinalSuffix(date) {
			const day = date.getDate();

			if (day >= 11 && day <= 13) {
				return 'th';
			}

			switch (day % 10) {
				case 1:
					return 'st';
				case 2:
					return 'nd';
				case 3:
					return 'rd';
				default:
					return 'th';
			}
		}

		function padDatePart(value) {
			return String(value).padStart(2, '0');
		}

		function formatToken(token, date) {
			switch (token) {
				case 'd':
					return padDatePart(date.getDate());
				case 'j':
					return String(date.getDate());
				case 'm':
					return padDatePart(date.getMonth() + 1);
				case 'n':
					return String(date.getMonth() + 1);
				case 'F':
					return getPart('month-long', { month: 'long' }, 'month', date);
				case 'M':
					return getPart('month-short', { month: 'short' }, 'month', date);
				case 'Y':
					return String(date.getFullYear());
				case 'y':
					return padDatePart(date.getFullYear() % 100);
				case 'H':
					return padDatePart(date.getHours());
				case 'G':
					return String(date.getHours());
				case 'h':
					return padDatePart((date.getHours() % 12) || 12);
				case 'g':
					return String((date.getHours() % 12) || 12);
				case 'i':
					return padDatePart(date.getMinutes());
				case 's':
					return padDatePart(date.getSeconds());
				case 'a':
					return getDayPeriod(date, false);
				case 'A':
					return getDayPeriod(date, true);
				case 'D':
					return getPart('weekday-short', { weekday: 'short' }, 'weekday', date);
				case 'l':
					return getPart('weekday-long', { weekday: 'long' }, 'weekday', date);
				case 'S':
					return getOrdinalSuffix(date);
				default:
					return token;
			}
		}

		return {
			format: function(date) {
				let output = '';
				let escaped = false;

				for (let i = 0; i < combinedFormat.length; i += 1) {
					const char = combinedFormat.charAt(i);

					if (escaped) {
						output += char;
						escaped = false;
						continue;
					}

					if (char === '\\') {
						escaped = true;
						continue;
					}

					output += formatToken(char, date);
			}

				return output;
			}
		};
	}

	ready(function () {
		const browserDateTimeFormatter = getBrowserDateTimeFormatter();
		const commandField = document.getElementById('yoro-ai-command');
		const runButton = document.getElementById('yoro-ai-run');
		const clearButton = document.getElementById('yoro-ai-clear');
		const contextButton = document.getElementById('yoro-ai-load-context');
		const adminPanelsButton = document.getElementById('yoro-ai-open-admin-panels');
		const adminPanelsSource = document.querySelector('[data-yoro-admin-panels]');
		const chatThread = document.getElementById('yoro-chat-thread');
		const exampleButtons = document.querySelectorAll('.yoro-ai-example');
		const quickActionsRoot = document.querySelector('[data-yoro-quick-actions]');
		const quickActionsPanel = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-panel]') : null;
		const quickActionsToggle = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-toggle]') : null;
		const quickSearchField = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-search]') : null;
		const quickSearchResults = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-results]') : null;
		const aiApiStatusRoot = document.querySelector('[data-yoro-ai-api-status]');
		const aiApiStatusToggle = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-toggle]') : null;
		const aiApiStatusPanel = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-panel]') : null;
		const aiApiStatusLabel = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-label]') : null;
		const aiApiStatusBody = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-body]') : null;
		const aiApiStatusRefresh = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-refresh]') : null;
		let aiApiStatusData = null;

		registerHistoryHandlers();
		registerAiApiStatus();

		if (!commandField || !runButton || !chatThread) {
			return;
		}

		let protectedWorkCount = 0;
		let unloadGuardEnabled = false;
		let conversationHydrationInFlight = false;
		let conversationHydrationDirty = false;

		function hasVisibleConfirmationActions() {
			return Array.prototype.some.call(
				chatThread.querySelectorAll('.yoro-confirm-actions'),
				function(actions) {
					return actions.style.display !== 'none' && !!actions.querySelector('.yoro-confirm-run');
				}
			);
		}

		function shouldWarnBeforeUnload() {
			return (
				protectedWorkCount > 0 ||
				!!conversationState.pending_operation_token ||
				hasVisibleConfirmationActions() ||
				commandField.value.trim() !== ''
			);
		}

		function handleBeforeUnload(event) {
			if (!shouldWarnBeforeUnload()) {
				return;
			}

			event.preventDefault();
			event.returnValue = '';
		}

		function syncBeforeUnloadGuard() {
			const shouldEnable = shouldWarnBeforeUnload();

			if (shouldEnable && !unloadGuardEnabled) {
				window.addEventListener('beforeunload', handleBeforeUnload);
				unloadGuardEnabled = true;
				return;
			}

			if (!shouldEnable && unloadGuardEnabled) {
				window.removeEventListener('beforeunload', handleBeforeUnload);
				unloadGuardEnabled = false;
			}
		}

		function beginProtectedWork() {
			protectedWorkCount += 1;
			syncBeforeUnloadGuard();

			return function finishProtectedWork() {
				protectedWorkCount = Math.max(0, protectedWorkCount - 1);
				syncBeforeUnloadGuard();
			};
		}

		function parseQuickActionParams(raw) {
			if (!raw) {
				return {};
			}

			try {
				return JSON.parse(raw);
			} catch (error) {
				return {};
			}
		}

		function getQuickSearchIntent(query) {
			const raw = String(query || '').trim();
			const normalized = raw.toLowerCase();

			if (!raw) {
				return null;
			}

			if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(raw)) {
				return {
					label: 'Find customer orders',
					description: 'Search store orders for ' + raw + '.',
					action: 'orders.find_by_customer',
					params: {
						email: raw,
						limit: 10
					}
				};
			}

			const digitsOnly = raw.replace(/\D+/g, '');
			const digitCount = raw.replace(/\D+/g, '').length;
			const phoneLike = /^[+()\-\s\d]{7,}$/.test(raw);
			const hasPhoneFormatting = /[+()\-\s]/.test(raw);
			const explicitOrderMatch = raw.match(/(?:^|\b)(?:order\s*#?\s*|#)(\d{3,})(?:\b|$)/i);
			const plainNumericOrderMatch = /^\d{3,7}$/.test(raw) ? raw.match(/^(\d{3,7})$/) : null;

			if (explicitOrderMatch) {
				const orderId = parseInt(explicitOrderMatch[1], 10);

				if (orderId > 0) {
					return {
						label: 'Open order #' + orderId,
						description: 'Open the full order details view directly.',
						action: 'orders.get_details',
						params: {
							order_id: orderId,
							order_ids: [orderId]
						}
					};
				}
			}

			if (phoneLike && (hasPhoneFormatting || digitCount >= 8 || normalized.indexOf('phone') !== -1)) {
				return {
					label: 'Find customer orders',
					description: 'Search store orders for phone ' + raw + '.',
					action: 'orders.find_by_customer',
					params: {
						phone: digitsOnly,
						limit: 10
					},
					intentType: 'phone_search'
				};
			}

			if (plainNumericOrderMatch) {
				const orderId = parseInt(plainNumericOrderMatch[1], 10);

				if (orderId > 0) {
					return {
						label: 'Open order #' + orderId,
						description: 'Open the full order details view directly.',
						action: 'orders.get_details',
						params: {
							order_id: orderId,
							order_ids: [orderId]
						},
						intentType: 'order_lookup',
						secondaryAction: 'orders.find_by_customer',
						secondaryParams: {
							phone: digitsOnly,
							limit: 10
						}
					};
				}
			}

			if (phoneLike && digitCount >= 7) {
				return {
					label: 'Find customer orders',
					description: 'Search store orders for phone ' + raw + '.',
					action: 'orders.find_by_customer',
					params: {
						phone: digitsOnly,
						limit: 10
					},
					intentType: 'phone_search'
				};
			}

			return null;
		}

		function getResponseErrorCode(data) {
			if (!data || typeof data !== 'object') {
				return '';
			}

			if (data.result && data.result.error && data.result.error.code) {
				return String(data.result.error.code || '');
			}

			if (data.error && data.error.code) {
				return String(data.error.code || '');
			}

			return '';
		}

		function renderQuickSearchState(query) {
			if (!quickSearchResults) {
				return;
			}

			const intent = getQuickSearchIntent(query);

			if (!String(query || '').trim()) {
				quickSearchResults.innerHTML = '<p class="yoro-chat-quick-hint">Search by order number, customer email, or phone number.</p>';
				return;
			}

			if (!intent) {
				quickSearchResults.innerHTML = '<p class="yoro-chat-quick-hint">Try an order number like <code>6864</code>, an email like <code>john@example.com</code>, or a phone number.</p>';
				return;
			}

			if (intent.intentType === 'order_lookup' && intent.secondaryAction) {
				quickSearchResults.innerHTML = ''
					+ '<div class="yoro-chat-quick-search-card yoro-chat-quick-search-card-ambiguous">'
					+   '<div class="yoro-chat-quick-search-copy">'
					+     '<p class="yoro-chat-quick-search-label">' + escapeHtml(intent.label) + '</p>'
					+     '<p class="yoro-chat-quick-search-meta">This looks like an order ID, but it could also be part of a customer phone number.</p>'
					+   '</div>'
					+   '<div class="yoro-chat-quick-search-actions">'
					+     '<button type="button" class="button button-primary yoro-chat-quick-search-button"'
					+       ' data-yoro-quick-run="1"'
					+       ' data-action="' + escapeHtml(intent.action) + '"'
					+       ' data-params="' + escapeHtml(JSON.stringify(intent.params || {})) + '"'
					+       ' data-secondary-action="' + escapeHtml(intent.secondaryAction) + '"'
					+       ' data-secondary-params="' + escapeHtml(JSON.stringify(intent.secondaryParams || {})) + '">Open order</button>'
					+     '<button type="button" class="button yoro-chat-quick-search-button-secondary"'
					+       ' data-yoro-quick-run="1"'
					+       ' data-action="' + escapeHtml(intent.secondaryAction) + '"'
					+       ' data-params="' + escapeHtml(JSON.stringify(intent.secondaryParams || {})) + '">Search phones</button>'
					+   '</div>'
					+ '</div>';
				return;
			}

			quickSearchResults.innerHTML = ''
				+ '<div class="yoro-chat-quick-search-card">'
				+   '<div class="yoro-chat-quick-search-copy">'
				+     '<p class="yoro-chat-quick-search-label">' + escapeHtml(intent.label) + '</p>'
				+     '<p class="yoro-chat-quick-search-meta">' + escapeHtml(intent.description) + '</p>'
				+   '</div>'
				+   '<button type="button" class="button button-primary yoro-chat-quick-search-button"'
				+     ' data-yoro-quick-run="1"'
				+     ' data-action="' + escapeHtml(intent.action) + '"'
				+     ' data-params="' + escapeHtml(JSON.stringify(intent.params || {})) + '">Open</button>'
				+ '</div>';
		}

		function setQuickActionsOpen(isOpen) {
			if (!quickActionsPanel || !quickActionsToggle) {
				return;
			}

			quickActionsPanel.hidden = !isOpen;
			quickActionsToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');

			if (isOpen && quickSearchField) {
				renderQuickSearchState(quickSearchField.value);
				window.setTimeout(function() {
					quickSearchField.focus();
					quickSearchField.select();
				}, 20);
			}
		}

		function runQuickAction(action, params, options) {
			if (!action) {
				return;
			}

			setQuickActionsOpen(false);
			options = options && typeof options === 'object' ? options : {};

			const loading = addLoadingMessage();
			setLoading(true);

			executeActionDirect(action, params || {}, false, false, 'quick-actions')
				.then(function(data) {
					const errorCode = getResponseErrorCode(data);
					const secondaryAction = options.secondaryAction || '';
					const secondaryParams = options.secondaryParams || {};

					if (
						secondaryAction &&
						errorCode === 'order_not_found'
					) {
						return executeActionDirect(secondaryAction, secondaryParams, false, false, 'quick-actions')
							.then(function(secondaryData) {
								loading.remove();
								addAssistantMessage(secondaryData);
								updateConversationState(secondaryData, secondaryAction);
								return null;
							})
							.catch(function(error) {
								loading.remove();
								addAssistantMessage({
									success: false,
									error: error || YoRoAIAdmin.i18n.error
								});
								return null;
							});
					}

					loading.remove();
					addAssistantMessage(data);
					updateConversationState(data, action);
					return null;
				})
				.catch(function(error) {
					loading.remove();
					addAssistantMessage({
						success: false,
						error: error || YoRoAIAdmin.i18n.error
					});
				})
				.finally(function() {
					setLoading(false);
				});
		}

		function formatDateTimeForChat(timestamp, fallback) {
			const numeric = Number(timestamp || 0);

			if (!Number.isFinite(numeric) || numeric <= 0) {
				return String(fallback || '');
			}

			try {
				return browserDateTimeFormatter.format(new Date(numeric * 1000));
			} catch (error) {
				return String(fallback || '');
			}
		}

		function registerAiApiStatus() {
			if (!aiApiStatusRoot || !aiApiStatusToggle || !aiApiStatusPanel || !aiApiStatusBody || !window.YoRoAIAdmin || !YoRoAIAdmin.aiApiStatusUrl) {
				return;
			}

			aiApiStatusToggle.addEventListener('click', function () {
				const isOpen = aiApiStatusPanel.hidden;
				aiApiStatusPanel.hidden = !isOpen;
				aiApiStatusToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
			});

			if (aiApiStatusRefresh && YoRoAIAdmin.aiApiHandshakeUrl) {
				aiApiStatusRefresh.addEventListener('click', function () {
					loadAiApiStatus(true);
				});
			}

			document.addEventListener('click', function (event) {
				if (aiApiStatusPanel.hidden || aiApiStatusRoot.contains(event.target)) {
					return;
				}

				aiApiStatusPanel.hidden = true;
				aiApiStatusToggle.setAttribute('aria-expanded', 'false');
			});

			loadAiApiStatus(false);
		}

		function loadAiApiStatus(forceHandshake) {
			const url = forceHandshake ? YoRoAIAdmin.aiApiHandshakeUrl : YoRoAIAdmin.aiApiStatusUrl;
			const method = forceHandshake ? 'POST' : 'GET';

			if (!url) {
				return;
			}

			setAiApiStatusLoading(forceHandshake ? 'Refreshing...' : 'Checking...');

			requestJson(url, {
				method: method
			})
				.then(function (data) {
					renderAiApiStatus(data);
				})
				.catch(function (error) {
					renderAiApiStatusError(error);
				});
		}

		function setAiApiStatusLoading(label) {
			if (!aiApiStatusToggle || !aiApiStatusBody) {
				return;
			}

			aiApiStatusToggle.classList.remove('is-connected', 'is-warning', 'is-error');
			aiApiStatusToggle.classList.add('is-loading');

			if (aiApiStatusLabel) {
				aiApiStatusLabel.textContent = label || 'Checking...';
			}

			aiApiStatusBody.innerHTML = '<p>Checking YoRo AI API status...</p>';
		}

		function renderAiApiStatus(data) {
			if (!aiApiStatusToggle || !aiApiStatusBody) {
				return;
			}

			aiApiStatusData = data || {};

			const connection = data && data.connection ? data.connection : {};
			const site = data && data.site ? data.site : {};
			const usage = data && data.usage ? data.usage : {};
			const entitlements = data && data.entitlements ? data.entitlements : {};
			const capabilities = data && data.capabilities ? data.capabilities : {};
			const configured = data && data.configured ? data.configured : {};
			const connected = !!connection.connected;
			const plan = site.plan || entitlements.usage_plan || '';
			const remaining = usage.remaining;
			const hasQuota = remaining === null || remaining === undefined || Number(remaining) > 0;
			const stateClass = connected && hasQuota ? 'is-connected' : (connected ? 'is-warning' : 'is-error');
			const provider = data && data.resolved_provider ? data.resolved_provider : '';
			const dashboardUrl = (data && data.dashboard_url) || YoRoAIAdmin.aiApiDashboardUrl || '';
			const conversationDashboardUrl = getConversationDashboardUrl(conversationState.conversation_id, data);

			aiApiStatusToggle.classList.remove('is-loading', 'is-connected', 'is-warning', 'is-error');
			aiApiStatusToggle.classList.add(stateClass);

			if (aiApiStatusLabel) {
				aiApiStatusLabel.textContent = connected
					? ('YoRo AI API' + (plan ? ' · ' + plan : ''))
					: 'YoRo AI API · offline';
			}

			let html = '';
			html += '<dl class="yoro-ai-api-status-grid">';
			html += renderAiApiStatusRow('Connection', connected ? 'Connected' : (connection.message || 'Not connected'));
			html += renderAiApiStatusRow('Provider', provider || 'unknown');
			html += renderAiApiStatusRow('Plan', plan || 'unknown');
			html += renderAiApiStatusRow('Subscription', entitlements.subscription_status || 'unknown');
			html += renderAiApiStatusRow('Usage', renderAiApiUsageText(usage));
			html += renderAiApiStatusRow('Last checked', connection.checked_at || 'not checked');
			html += '</dl>';
			html += '<div class="yoro-ai-api-status-features">';
			html += renderAiApiFeatureBadge('Conversation AI', capabilities.conversation, entitlements.conversation);
			html += renderAiApiFeatureBadge('Email compose', capabilities.email_compose, entitlements.email_composer);
			html += '</div>';

			if (dashboardUrl) {
				html += '<p class="yoro-ai-api-status-note"><a class="button button-small" href="' + escapeHtml(dashboardUrl) + '" target="_blank" rel="noopener noreferrer">Open Laravel dashboard</a></p>';
			}

			if (conversationDashboardUrl) {
				html += '<p class="yoro-ai-api-status-note"><a class="button button-small" href="' + escapeHtml(conversationDashboardUrl) + '" target="_blank" rel="noopener noreferrer">Open current conversation</a></p>';
			}

			if (!configured.enabled) {
				html += '<p class="yoro-ai-api-status-note">YoRo AI API is configured but disabled in plugin settings.</p>';
			}

			aiApiStatusBody.innerHTML = html;
		}

		function getConversationDashboardUrl(conversationId, data) {
			conversationId = String(conversationId || '').trim();

			if (!conversationId) {
				return '';
			}

			const baseUrl =
				(data && data.conversation_dashboard_base_url) ||
				YoRoAIAdmin.aiApiConversationDashboardBaseUrl ||
				'';

			if (!baseUrl) {
				return '';
			}

			return String(baseUrl).replace(/\/+$/, '') + '/' + encodeURIComponent(conversationId);
		}

		function refreshAiApiStatusConversationLink() {
			if (!aiApiStatusData || !aiApiStatusBody) {
				return;
			}

			renderAiApiStatus(aiApiStatusData);
		}

			function renderAiApiStatusError(error) {
				const message = (error && error.message)
					|| (error && error.data && error.data.message)
					|| 'I could not check the YoRo AI API connection right now.';

			if (aiApiStatusToggle) {
				aiApiStatusToggle.classList.remove('is-loading', 'is-connected', 'is-warning');
				aiApiStatusToggle.classList.add('is-error');
			}

			if (aiApiStatusLabel) {
				aiApiStatusLabel.textContent = 'YoRo AI API · error';
			}

			if (aiApiStatusBody) {
				aiApiStatusBody.innerHTML = '<p class="yoro-ai-api-status-error">' + escapeHtml(message) + '</p>';
			}
		}

		function renderAiApiStatusRow(label, value) {
			return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd></div>';
		}

		function renderAiApiUsageText(usage) {
			if (!usage || usage.limit === undefined || usage.used === undefined || usage.limit === null || usage.used === null) {
				return 'not available';
			}

			const remaining = usage.remaining === undefined || usage.remaining === null
				? ''
				: ', ' + Number(usage.remaining || 0).toLocaleString() + ' remaining';

			return Number(usage.used || 0).toLocaleString()
				+ ' / '
				+ Number(usage.limit || 0).toLocaleString()
				+ remaining;
		}

		function renderAiApiFeatureBadge(label, capabilityValue, entitlementValue) {
			const isOn = !!capabilityValue || !!entitlementValue;

			return '<span class="yoro-ai-api-feature ' + (isOn ? 'is-on' : 'is-off') + '">'
				+ '<span aria-hidden="true"></span>'
				+ escapeHtml(label)
				+ '</span>';
		}

		let isComposerComposing = false;
		let submitAfterCompositionEnd = false;

		function submitComposerCommand() {
			if (isComposerComposing) {
				submitAfterCompositionEnd = true;
				return;
			}

			runCommand(false);
		}

			commandField.addEventListener('input', function () {
				if (
					conversationState.next_scoped_command &&
					commandField.value.trim() !== conversationState.next_scoped_command
				) {
					clearNextActionCatalogScope();
				}

				if (commandField.value.trim() === '') {
					clearCommandGhostHint();
				}

				syncRunButtonState();
				syncBeforeUnloadGuard();
			});

		commandField.addEventListener('compositionstart', function () {
			isComposerComposing = true;
		});

		commandField.addEventListener('compositionend', function () {
			window.requestAnimationFrame(function () {
				isComposerComposing = false;

				if (!submitAfterCompositionEnd) {
					return;
				}

				submitAfterCompositionEnd = false;
				submitComposerCommand();
			});
		});

		commandField.addEventListener('blur', function () {
			isComposerComposing = false;
			submitAfterCompositionEnd = false;
		});

		const ghostHint = document.createElement('div');
		ghostHint.className = 'yoro-command-ghost-hint';
		ghostHint.style.display = 'none';

		if (commandField && commandField.parentNode) {
			commandField.parentNode.insertBefore(ghostHint, commandField.nextSibling);
		}

		const modal = document.createElement('div');
		modal.className = 'yoro-modal hidden';
		modal.innerHTML = `
			<div class="yoro-modal-overlay"></div>
			<div class="yoro-modal-content">
				<h3 class="yoro-modal-title"></h3>
				<div class="yoro-modal-body"></div>
				<div class="yoro-modal-actions">
					<button class="button yoro-modal-cancel">Cancel</button>
					<button class="button button-primary yoro-modal-confirm">Confirm</button>
				</div>
			</div>
		`;
		document.body.appendChild(modal);

		function openModal(title, bodyHtml, onConfirm, options) {
			const contentEl = modal.querySelector('.yoro-modal-content');
			const titleEl = modal.querySelector('.yoro-modal-title');
			const bodyEl = modal.querySelector('.yoro-modal-body');
			const actionsEl = modal.querySelector('.yoro-modal-actions');
			const cancelBtn = modal.querySelector('.yoro-modal-cancel');
			const confirmBtn = modal.querySelector('.yoro-modal-confirm');
			const modalOptions = options || {};

			titleEl.textContent = title;
			bodyEl.innerHTML = bodyHtml;
			actionsEl.style.display = modalOptions.hideActions ? 'none' : '';
			confirmBtn.style.display = modalOptions.hideConfirm ? 'none' : '';
			cancelBtn.textContent = modalOptions.cancelText || 'Cancel';
			confirmBtn.textContent = modalOptions.confirmText || 'Confirm';
			contentEl.classList.toggle('yoro-modal-content-wide', !!modalOptions.wide);

			modal.classList.remove('hidden');

			function close() {
				modal.classList.add('hidden');
				confirmBtn.onclick = null;
				actionsEl.style.display = '';
				confirmBtn.style.display = '';
				cancelBtn.textContent = 'Cancel';
				confirmBtn.textContent = 'Confirm';
				contentEl.classList.remove('yoro-modal-content-wide');
			}

			cancelBtn.onclick = close;
			modal.querySelector('.yoro-modal-overlay').onclick = close;

			confirmBtn.onclick = function () {
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
				close();
			};
		}

		function activateAdminPanel(root, panel) {
			const modalRoot = root || null;

			if (!modalRoot) {
				return;
			}

			const tabs = modalRoot.querySelectorAll('[data-yoro-admin-tab]');
			let selectedPanel = panel || 'history';
			let hasSelectedPanel = false;

			tabs.forEach(function(tab) {
				if (tab.getAttribute('data-yoro-admin-tab') === selectedPanel) {
					hasSelectedPanel = true;
				}
			});

			if (!hasSelectedPanel) {
				selectedPanel = 'history';
			}

			tabs.forEach(function(tab) {
				const active = tab.getAttribute('data-yoro-admin-tab') === selectedPanel;

				tab.classList.toggle('is-active', active);
				tab.setAttribute('aria-selected', active ? 'true' : 'false');
			});

			modalRoot.querySelectorAll('[data-yoro-admin-panel]').forEach(function(section) {
				section.classList.toggle(
					'is-active',
					section.getAttribute('data-yoro-admin-panel') === selectedPanel
				);
			});
		}

		function openAdminPanels(panel) {
			if (!adminPanelsSource) {
				return;
			}

			openModal(
				'YoRo AI Admin',
				adminPanelsSource.innerHTML,
				null,
				{
					hideConfirm: true,
					cancelText: 'Close',
					wide: true
				}
			);

			activateAdminPanel(
				modal.querySelector('[data-yoro-admin-modal]'),
				panel || 'history'
			);
		}

		function registerAdminPanelModal() {
			if (adminPanelsButton && adminPanelsSource) {
				adminPanelsButton.addEventListener('click', function() {
					openAdminPanels('history');
				});
			}

			document.addEventListener('click', function(event) {
				const tab = event.target.closest('[data-yoro-admin-tab]');

				if (!tab || !modal.contains(tab)) {
					return;
				}

				activateAdminPanel(
					tab.closest('[data-yoro-admin-modal]'),
					tab.getAttribute('data-yoro-admin-tab') || 'history'
				);
			});

			const params = new URLSearchParams(window.location.search);
			const requestedPanel = params.get('yoro_admin_panel')
				|| (adminPanelsSource ? adminPanelsSource.getAttribute('data-yoro-admin-initial-panel') : '');

			if (requestedPanel && adminPanelsSource) {
				window.setTimeout(function() {
					openAdminPanels(requestedPanel);
				}, 0);
			}
		}

		registerAdminPanelModal();

		function getConversationStorageKey() {
			const key = window.YoRoAIAdmin && YoRoAIAdmin.conversationStorageKey
				? String(YoRoAIAdmin.conversationStorageKey)
				: '';

			return key.length <= 120 ? key : '';
		}

		function normalizeStoredConversationId(value) {
			const conversationId = String(value || '').trim();

			if (!conversationId || conversationId.length > 120) {
				return '';
			}

			return /^[A-Za-z0-9._:-]+$/.test(conversationId) ? conversationId : '';
		}

		function loadStoredConversationId() {
			const storageKey = getConversationStorageKey();

			if (!storageKey) {
				return '';
			}

			try {
				return normalizeStoredConversationId(window.localStorage.getItem(storageKey));
			} catch (error) {
				return '';
			}
		}

		function saveStoredConversationId(conversationId) {
			const storageKey = getConversationStorageKey();
			const storedConversationId = normalizeStoredConversationId(conversationId);

			if (!storageKey || !storedConversationId) {
				return;
			}

			try {
				window.localStorage.setItem(storageKey, storedConversationId);
			} catch (error) {
				// Ignore private browsing or blocked storage; Laravel remains the source of truth.
			}
		}

		function clearStoredConversationId() {
			const storageKey = getConversationStorageKey();

			if (!storageKey) {
				return;
			}

			try {
				window.localStorage.removeItem(storageKey);
			} catch (error) {
				// Ignore private browsing or blocked storage; the in-memory state is still cleared.
			}
		}

			let conversationState = {
				conversation_id: loadStoredConversationId(),
				pending_operation_token: '',
				last_order_ids: [],
				last_orders: [],
				last_referenced_order_id: 0,
				current_order_id: 0,
				pending_confirm_command: '',
				next_action_catalog_scope: '',
				next_report_topic_selection: '',
				next_report_topic: '',
				next_scoped_command: ''
			};

		function registerHistoryHandlers() {
			document.addEventListener('click', function (event) {
				const target = event.target;

				if (!target.classList.contains('yoro-expand-row')) {
					return;
				}

				const row = target.closest('tr');
				const next = row ? row.nextElementSibling : null;

				if (!next || !next.classList.contains('yoro-row-expanded')) {
					return;
				}

				if (next.style.display === 'none') {
					next.style.display = 'table-row';
					target.textContent = '-';
				} else {
					next.style.display = 'none';
					target.textContent = '+';
				}
			});

			document.addEventListener('click', function (event) {
				const target = event.target;

				if (!target.classList.contains('yoro-history-undo')) {
					return;
				}

				const logId = target.getAttribute('data-log-id') || '';

				if (!logId || !window.YoRoAIAdmin || !YoRoAIAdmin.undoUrl) {
					return;
				}

				target.disabled = true;
				target.textContent = 'Undoing...';

				undoLog(logId)
					.then(function () {
						target.textContent = 'Undone';
						const undoCell = target.closest('td');
						if (undoCell) {
							undoCell.textContent = 'Undone';
						}
					})
					.catch(function (error) {
						target.disabled = false;
						target.textContent = 'Undo';

							alert(
								(error && error.message) ||
								(error && error.data && error.data.message) ||
								'I could not undo that change.'
							);
					});
			});
		}

		function showCommandGhostHint(text) {
			if (!ghostHint) {
				return;
			}

			if (!text) {
				ghostHint.style.display = 'none';
				ghostHint.innerHTML = '';
				return;
			}

			ghostHint.innerHTML = escapeHtml(text);
			ghostHint.style.display = 'block';
		}

		function clearCommandGhostHint() {
			showCommandGhostHint('');
		}

		function scrollToBottom() {
			chatThread.scrollTop = chatThread.scrollHeight;
		}

		function scrollMessageToTop(wrapper) {
			if (!wrapper || typeof wrapper.scrollIntoView !== 'function') {
				return;
			}

			window.requestAnimationFrame(function () {
				wrapper.scrollIntoView({
					block: 'start',
					behavior: 'smooth'
				});
			});
		}

		function stripAssistantNameTextPrefix(text) {
			return String(text || '').replace(/^\s*yoro\s*(?:\r?\n)+/i, '').trimStart();
		}

		function stripAssistantNameHeader(html) {
			const template = document.createElement('template');

			template.innerHTML = String(html || '').trim();

			let firstElement = template.content.firstElementChild;
			if (
				firstElement
				&& firstElement.tagName === 'STRONG'
				&& firstElement.textContent.trim().toLowerCase() === 'yoro'
			) {
				firstElement.remove();
				firstElement = template.content.firstElementChild;
			}

			if (
				firstElement
				&& firstElement.parentNode
				&& firstElement.tagName === 'P'
				&& firstElement.textContent.trim().toLowerCase() === 'yoro'
			) {
				firstElement.remove();
			}

			if (firstElement && firstElement.parentNode && firstElement.tagName === 'P') {
				const strippedText = stripAssistantNameTextPrefix(firstElement.textContent);

				if (strippedText !== firstElement.textContent) {
					firstElement.textContent = strippedText;
				}
			}

			return template.innerHTML.trim();
		}

		function addMessage(type, html, options) {
			const isUser = type === 'user';
			const messageOptions = options && typeof options === 'object' ? options : {};
			const messageHtml = isUser ? String(html || '') : stripAssistantNameHeader(html);

			if (conversationHydrationInFlight && !messageOptions.hydrating) {
				conversationHydrationDirty = true;
			}

			const wrapper = document.createElement('div');
			wrapper.className = 'yoro-chat-message ' + (isUser ? 'yoro-chat-message-user' : 'yoro-chat-message-assistant');

			wrapper.innerHTML = `
				<div class="yoro-chat-avatar" aria-hidden="true">${isUser ? 'U' : ''}</div>
				<div class="yoro-chat-bubble">
					${messageHtml}
				</div>
			`;

			chatThread.appendChild(wrapper);

			if (messageOptions.scroll === false) {
				return wrapper;
			}

			if (isUser) {
				scrollToBottom();
			} else {
				scrollMessageToTop(wrapper);
			}

			return wrapper;
		}

		function addUserMessage(command) {
			addMessage('user', '<p>' + escapeHtml(command) + '</p>');
		}

		let resultUI = null;

		function hideSupersededAssistantMessages(wrapper, data) {
			const payload = data && data.result && data.result.display_type ? data.result : (data || {});
			const displayType = payload && payload.display_type ? String(payload.display_type) : '';
			const orderId = parseInt(
				(payload && payload.order_id)
				|| (payload && payload.order && payload.order.id)
				|| 0,
				10
			);

			if (!wrapper) {
				return;
			}

			if (displayType === 'customer_email_drafts' && orderId) {
				wrapper.setAttribute('data-yoro-display-type', displayType);
				wrapper.setAttribute('data-yoro-order-id', String(orderId));

				const previousDraftMessages = chatThread.querySelectorAll(
					'.yoro-chat-message-assistant[data-yoro-display-type="customer_email_drafts"][data-yoro-order-id="' + orderId + '"]'
				);

				previousDraftMessages.forEach(function (message) {
					if (message === wrapper) {
						return;
					}

					const bubble = message.querySelector('.yoro-chat-bubble');
					if (bubble) {
						bubble.innerHTML = '<div class="yoro-result-notice yoro-result-subtle-notice"><p>Draft list for order #' + escapeHtml(String(orderId)) + ' was updated below.</p></div>';
					}
					message.setAttribute('data-yoro-superseded', '1');
				});

				return;
			}

			if (displayType === 'email_templates_list') {
				wrapper.setAttribute('data-yoro-display-type', displayType);

				const previousTemplateMessages = chatThread.querySelectorAll(
					'.yoro-chat-message-assistant[data-yoro-display-type="email_templates_list"]'
				);

				previousTemplateMessages.forEach(function (message) {
					if (message === wrapper) {
						return;
					}

					const bubble = message.querySelector('.yoro-chat-bubble');
					if (bubble) {
						bubble.innerHTML = '<div class="yoro-result-notice yoro-result-subtle-notice"><p>Saved template list was updated below.</p></div>';
					}
					message.setAttribute('data-yoro-superseded', '1');
				});
			}
		}

		function addAssistantMessage(data, options) {
			const messageOptions = options && typeof options === 'object' ? options : {};
			const wrapper = addMessage(
				'assistant',
				resultUI
					? resultUI.renderResult(data)
					: renderJsonFallback(data),
				messageOptions
			);
			const result = data && data.result ? data.result : {};
			const displayType = String(result.display_type || '');

			if (displayType) {
				wrapper.setAttribute('data-yoro-display-type', displayType);
			}

			hideSupersededAssistantMessages(wrapper, data);
			syncBeforeUnloadGuard();
		}

		function buildConversationLoadUrl(conversationId) {
			const endpoint = window.YoRoAIAdmin && YoRoAIAdmin.conversationUrl
				? String(YoRoAIAdmin.conversationUrl)
				: '';
			const normalizedConversationId = normalizeStoredConversationId(conversationId);

			if (!endpoint || !normalizedConversationId) {
				return '';
			}

			return endpoint
				+ (endpoint.indexOf('?') === -1 ? '?' : '&')
				+ 'conversation_id='
				+ encodeURIComponent(normalizedConversationId);
		}

		function renderHydratedConversation(data) {
			const messages = data && Array.isArray(data.messages) ? data.messages : [];

			if (!messages.length || conversationHydrationDirty) {
				return;
			}

			chatThread.innerHTML = '';

			messages.forEach(function(message) {
				const role = String(message && message.role ? message.role : '');
				const content = String(message && message.content ? message.content : '');

				if (role === 'user') {
					addMessage('user', '<p>' + escapeHtml(content) + '</p>', { scroll: false, hydrating: true });
					return;
				}

				if (role !== 'assistant') {
					return;
				}

				if (message.ui && typeof message.ui === 'object') {
					addAssistantMessage(message.ui, { scroll: false, hydrating: true });
					return;
				}

				addMessage(
					'assistant',
					resultUI && typeof resultUI.renderAssistantText === 'function'
						? resultUI.renderAssistantText(stripAssistantNameTextPrefix(content))
						: '<p>' + escapeHtml(stripAssistantNameTextPrefix(content)) + '</p>',
					{ scroll: false, hydrating: true }
				);
			});

			updateConversationState(data, '');
			syncBeforeUnloadGuard();
			scrollToBottom();
		}

		function hydrateStoredConversation() {
			const conversationId = normalizeStoredConversationId(conversationState.conversation_id);
			const url = buildConversationLoadUrl(conversationId);

			if (!url) {
				return;
			}

			conversationHydrationInFlight = true;
			conversationHydrationDirty = false;

			requestJson(url, {
				method: 'GET'
			})
				.then(function(data) {
					renderHydratedConversation(data);
				})
				.catch(function(error) {
					const code = getResponseErrorCode(error);

					if (
						code === 'yoro_ai_api_conversation_show_error' ||
						code === 'rest_no_route' ||
						code === 'rest_forbidden'
					) {
						conversationState.conversation_id = '';
						conversationState.pending_operation_token = '';
						clearStoredConversationId();
						refreshAiApiStatusConversationLink();
					}
				})
				.finally(function() {
					conversationHydrationInFlight = false;
				});
		}

		function addLoadingMessage() {
			return addMessage(
				'assistant',
				'<div class="yoro-typing"><span></span><span></span><span></span></div>'
			);
		}

		function hideConfirmationActions(target) {
			const actions = target && typeof target.closest === 'function'
				? target.closest('.yoro-confirm-actions')
				: null;

			if (actions) {
				actions.style.display = 'none';
				syncBeforeUnloadGuard();
			}
		}

		function hideClarifyOptions(target) {
			const actions = target && typeof target.closest === 'function'
				? target.closest('.yoro-clarify-options')
				: null;

			if (actions) {
				actions.style.display = 'none';
			}
		}

		function hideUndoAction(target) {
			if (!target) {
				return null;
			}

			const actions = typeof target.closest === 'function'
				? target.closest('.yoro-result-actions, .yoro-confirm-actions')
				: null;

			if (actions) {
				actions.style.display = 'none';
				return {
					element: actions,
					display: ''
				};
			}

			const previousDisplay = target.style.display;
			target.style.display = 'none';

			return {
				element: target,
				display: previousDisplay
			};
		}

		function restoreHiddenAction(hiddenAction) {
			if (!hiddenAction || !hiddenAction.element) {
				return;
			}

			hiddenAction.element.style.display = hiddenAction.display;
		}

			function syncRunButtonState() {
			runButton.disabled = isSending || commandField.value.trim() === '';
		}

		function setLoading(isLoading) {
			syncRunButtonState();

			if (contextButton) {
				contextButton.disabled = isLoading;
			}

			if (adminPanelsButton) {
				adminPanelsButton.disabled = isLoading;
			}

			runButton.textContent = isLoading ? YoRoAIAdmin.i18n.running : (YoRoAIAdmin.i18n.askYoRo || 'Ask YoRo');
		}

		function requestJson(url, options) {
			const requestOptions = Object.assign({
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': YoRoAIAdmin.nonce
				}
			}, options || {});

			return fetch(url, requestOptions).then(function (response) {
				return response.json().then(function (data) {
					if (!response.ok) {
						throw data;
					}

					return data;
				});
			});
		}

		function undoLog(logId) {
			return requestJson(YoRoAIAdmin.undoUrl, {
				method: 'POST',
				body: JSON.stringify({
					log_id: logId
				})
			});
		}

		function loadContext() {
			const command = 'Load store context';
			addUserMessage(command);
			const loading = addLoadingMessage();
			const finishProtectedWork = beginProtectedWork();

			setLoading(true);

			requestJson(YoRoAIAdmin.contextUrl, {
				method: 'GET'
			})
				.then(function (data) {
					loading.remove();
					addAssistantMessage(data);
				})
				.catch(function (error) {
					loading.remove();
					addAssistantMessage({
						success: false,
						error: error || YoRoAIAdmin.i18n.error
					});
					})
					.finally(function () {
						finishProtectedWork();
						setLoading(false);
					});
		}

		function isOrdersListResult(data, result) {
			const parsed = data && data.parsed ? data.parsed : {};
			const action = String(result.action || data.action || parsed.action || '');
			const displayType = String(result.display_type || data.display_type || '');
			const resultType = String(result.result_type || data.result_type || '');

			if (displayType === 'order_details') {
				return false;
			}

			return displayType === 'orders_list' ||
				action === 'orders.search' ||
				action === 'orders.find_by_customer' ||
				action === 'orders.find_by_identity' ||
				(resultType === 'orders' && displayType !== 'order_details');
		}

		function compactOrderListContext(orders) {
			if (!Array.isArray(orders)) {
				return [];
			}

			return orders.map(function(order, index) {
				const id = parseInt(order && order.id ? order.id : 0, 10);

				if (!id) {
					return null;
				}

				const item = {
					position: index + 1,
					id: id
				};

				if (order.number) {
					item.number = String(order.number);
				}

				if (order.status) {
					item.status = String(order.status);
				}

				return item;
			}).filter(function(order) {
				return !!order;
			});
		}

		function updateConversationState(data, command) {
			if (!data) {
				return;
			}

			const result = data.result || {};
			const parsed = data.parsed || {};
			const meta = parsed.meta || result.meta || data.meta || {};
			const adminCopilot = data.admin_copilot || (result.meta && result.meta.admin_copilot) || null;
			const conversationId =
				data.conversation_id ||
				parsed.conversation_id ||
				result.conversation_id ||
				meta.conversation_id ||
				meta.yoro_ai_api_conversation_id ||
				'';
			const pendingOperationToken =
				data.pending_operation_token ||
				parsed.pending_operation_token ||
				result.pending_operation_token ||
				meta.pending_operation_token ||
				meta.yoro_ai_api_pending_operation_token ||
				'';

			if (conversationId) {
				conversationState.conversation_id = String(conversationId);
				saveStoredConversationId(conversationState.conversation_id);
				refreshAiApiStatusConversationLink();
			}

				if (pendingOperationToken) {
					conversationState.pending_operation_token = String(pendingOperationToken);
				}

				if (data.pending_operation_active === false || result.blocked === true) {
					conversationState.pending_operation_token = '';
				}

					if (adminCopilot && typeof adminCopilot === 'object') {
				conversationState.surface = 'admin_copilot';
				conversationState.admin_copilot = adminCopilot;
				}

				if (Array.isArray(result.orders) && isOrdersListResult(data, result)) {
					conversationState.last_order_ids = result.orders
						.map(function (order) {
							return parseInt(order.id || 0, 10);
						})
					.filter(function (id) {
						return id > 0;
					});
					conversationState.last_orders = compactOrderListContext(result.orders);

					if (conversationState.last_order_ids.length) {
						conversationState.last_referenced_order_id = parseInt(conversationState.last_order_ids[0], 10);
						conversationState.current_order_id = parseInt(conversationState.last_order_ids[0], 10);
					}
				}

				if (result.order && result.order.id) {
				conversationState.current_order_id = parseInt(result.order.id, 10);
				conversationState.last_referenced_order_id = parseInt(result.order.id, 10);
			}

			if (
				data.stage === 'done' ||
				data.stage === 'cancelled'
			) {
				conversationState.pending_operation_token = '';
			}

			syncBeforeUnloadGuard();
		}

		let lastSubmitAt = 0;

		function canSubmit() {
			const now = Date.now();
			if (now - lastSubmitAt < 500) {
				return false;
			}
			lastSubmitAt = now;
			return true;
			}

			function buildClientContext() {
				const context = {
					conversation_id: conversationState.conversation_id || '',
					pending_operation_token: conversationState.pending_operation_token || '',
				surface: conversationState.surface || 'admin_copilot',
				current_order_id: parseInt(conversationState.current_order_id || 0, 10) || 0,
				last_referenced_order_id: parseInt(conversationState.last_referenced_order_id || 0, 10) || 0,
				last_order_ids: Array.isArray(conversationState.last_order_ids)
					? conversationState.last_order_ids.map(function(id) {
						return parseInt(id || 0, 10);
					}).filter(function(id) {
							return id > 0;
						}).slice(0, 20)
						: [],
				last_orders: Array.isArray(conversationState.last_orders)
					? conversationState.last_orders.slice(0, 20)
					: []
				};

					if (conversationState.next_action_catalog_scope) {
						context.action_catalog_scope = conversationState.next_action_catalog_scope;
					}

					if (conversationState.next_report_topic_selection) {
						context.report_topic_selection = conversationState.next_report_topic_selection;
					}

					if (conversationState.next_report_topic) {
						context.report_topic = conversationState.next_report_topic;
					}

					return context;
			}

			function setNextActionCatalogScope(command, scope, reportTopic) {
				const normalizedScope = String(scope || '').trim();
				const normalizedReportTopic = String(reportTopic || '').trim();

				if (normalizedScope !== 'report_provider_catalog') {
					clearNextActionCatalogScope();
					return;
				}

				conversationState.next_action_catalog_scope = 'report_provider_catalog';
				conversationState.next_report_topic_selection = 'provider_catalog';
				conversationState.next_report_topic = ['overview', 'sales', 'operations', 'signals'].indexOf(normalizedReportTopic) !== -1
					? normalizedReportTopic
					: '';
				conversationState.next_scoped_command = String(command || '').trim();
			}

			function clearNextActionCatalogScope() {
				conversationState.next_action_catalog_scope = '';
				conversationState.next_report_topic_selection = '';
				conversationState.next_report_topic = '';
				conversationState.next_scoped_command = '';
			}

		function shouldAutoExecuteConversationAction(data, confirm) {
			return !!(
				!confirm &&
				data &&
				data.stage === 'action_plan' &&
				data.execution_required &&
				!data.confirm_required &&
				!data.requires_confirmation &&
				(data.pending_operation_token || conversationState.pending_operation_token)
			);
		}

		function executeConversationActionPlan(data, command) {
			const pendingOperationToken =
				(data && data.pending_operation_token) ||
				conversationState.pending_operation_token ||
				'';

			if (!pendingOperationToken) {
				return Promise.resolve(null);
			}

			return requestJson(YoRoAIAdmin.executeUrl, {
				method: 'POST',
				body: JSON.stringify({
					action: (data && data.action) || (data && data.parsed && data.parsed.action) || '',
					params: (data && data.params) || (data && data.parsed && data.parsed.params) || {},
					confirm: true,
					pending_operation_token: pendingOperationToken,
					conversation_id: conversationState.conversation_id || '',
					client_context: buildClientContext()
				})
			});
		}

		let isSending = false;

		function runCommand(confirm, onConfirmSuccess, commandOverride) {
			if (isSending) {
				return;
			}

			const command = String(typeof commandOverride === 'string' ? commandOverride : commandField.value).trim();
			clearCommandGhostHint();

			if (!command && !(confirm && conversationState.pending_operation_token)) {
				addAssistantMessage({
					success: false,
					message: YoRoAIAdmin.i18n.emptyCommand
				});
				return;
			}

			if (!canSubmit()) {
				return;
			}

			isSending = true;
			conversationState.pending_confirm_command = command;
			const finishProtectedWork = beginProtectedWork();

			if (!confirm) {
				addUserMessage(command);
				commandField.value = '';
				syncRunButtonState();
				syncBeforeUnloadGuard();
			}

			const endpointUrl = confirm ? YoRoAIAdmin.executeUrl : YoRoAIAdmin.chatUrl;

			if (!endpointUrl) {
				isSending = false;
				finishProtectedWork();
				addAssistantMessage({
					success: false,
					message: 'The conversation endpoint is not configured.'
				});
				setLoading(false);
				return;
			}

			const loading = addLoadingMessage();

			setLoading(true);

			const confirmRequestBody = {
				action: '',
				params: {},
				confirm: true,
				pending_operation_token: conversationState.pending_operation_token || '',
				conversation_id: conversationState.conversation_id || '',
				client_context: buildClientContext()
			};

				const requestBody = confirm
					? confirmRequestBody
					: {
						message: command,
						command: command,
						conversation_id: conversationState.conversation_id || '',
						client_context: buildClientContext()
					};

				if (!confirm) {
					clearNextActionCatalogScope();
				}

				requestJson(endpointUrl, {
				method: 'POST',
				body: JSON.stringify(requestBody)
			})
			.then(function (data) {
				loading.remove();

				if (!data || data.stage !== 'confirm') {
					conversationState.pending_confirm_command = '';
				}

				addAssistantMessage(data);
				updateConversationState(data, command);

				if (shouldAutoExecuteConversationAction(data, confirm)) {
					const executeLoading = addLoadingMessage();

					return executeConversationActionPlan(data, command)
						.then(function(executeData) {
							executeLoading.remove();

							if (!executeData) {
								return;
							}

							addAssistantMessage(executeData);
							updateConversationState(executeData, command);

							if (executeData.stage === 'done') {
								conversationState.pending_operation_token = '';
							}
						})
						.catch(function(error) {
							executeLoading.remove();
							addAssistantMessage({
								success: false,
								error: error || YoRoAIAdmin.i18n.error
							});
						});
				}

				if (data && data.stage === 'done') {
					conversationState.pending_operation_token = '';
					if (confirm && typeof onConfirmSuccess === 'function') {
						onConfirmSuccess(data);
					}
				}
				syncBeforeUnloadGuard();
			})
			.catch(function (error) {
				loading.remove();

				addAssistantMessage({
					success: false,
					error: error || YoRoAIAdmin.i18n.error
				});
			})
			.finally(function () {
				isSending = false;
				finishProtectedWork();
				setLoading(false);
			});
		}

		function getSelectedOrderIds() {
			const ids = [];

			chatThread.querySelectorAll('.yoro-order-checkbox:checked').forEach(function (cb) {
				ids.push(cb.value);
			});

			return ids;
		}

		function openNoteModal(callback) {
			openTextModal('Add internal note', 'Enter note...', callback);
		}

		function openTextModal(title, placeholder, callback) {
			openModal(
				title,
				'<textarea class="yoro-modal-textarea" placeholder="' + escapeHtml(placeholder || '') + '" rows="4"></textarea>',
				function () {
					const value = modal.querySelector('.yoro-modal-textarea').value.trim();
					if (value && typeof callback === 'function') {
						callback(value);
					}
				}
			);
		}

		function getAvailableOrderStatuses() {
			const statuses =
				(window.YoRoAIAdmin && Array.isArray(YoRoAIAdmin.orderStatuses))
					? YoRoAIAdmin.orderStatuses
					: [];

			if (statuses.length) {
				return statuses;
			}

			return [
				{ slug: 'pending', label: 'Pending payment' },
				{ slug: 'processing', label: 'Processing' },
				{ slug: 'on-hold', label: 'On hold' },
				{ slug: 'completed', label: 'Completed' },
				{ slug: 'cancelled', label: 'Cancelled' },
				{ slug: 'refunded', label: 'Refunded' },
				{ slug: 'failed', label: 'Failed' }
			];
		}

		function renderStatusSelectHtml() {
			let html = '<select class="yoro-modal-select">';
			html += '<option value="">Select status</option>';

			getAvailableOrderStatuses().forEach(function (status) {
				const value = status.slug || status.value || '';
				const label = status.label || value;

				if (!value) {
					return;
				}

				html += '<option value="' + escapeHtml(value) + '">' + escapeHtml(label) + '</option>';
			});

			html += '</select>';

			return html;
		}

		function openStatusModal(callback) {
			openModal(
				'Change order status',
				renderStatusSelectHtml(),
				function () {
					const value = modal.querySelector('.yoro-modal-select').value;
					if (value && typeof callback === 'function') {
						callback(value);
					}
				}
			);
		}

		function runCommandFromText(command, confirm) {
			commandField.value = command;
			syncRunButtonState();
			syncBeforeUnloadGuard();
			runCommand(confirm || false);
		}

		function getCurrentOrderId() {
			return parseInt(
				conversationState.current_order_id ||
				conversationState.last_referenced_order_id ||
				0,
				10
			);
		}

		function resolveOrderId(explicitOrderId) {
			const explicit = parseInt(explicitOrderId || 0, 10);

			if (explicit > 0) {
				return explicit;
			}

			const ctx = getCurrentOrderId();

			if (ctx > 0) {
				return ctx;
			}

			return 0;
		}

		function executeActionDirect(action, params, confirm, preview, surface, pendingOperationToken) {
			const hasExplicitPendingOperationToken = arguments.length >= 6;
			const payload = {
				action: action,
				params: params || {},
				confirm: confirm || false,
				preview: preview || false,
				surface: surface || conversationState.surface || '',
				conversation_id: conversationState.conversation_id || '',
				pending_operation_token: hasExplicitPendingOperationToken
					? (pendingOperationToken || '')
					: (conversationState.pending_operation_token || '')
			};

			const finishProtectedWork = beginProtectedWork();

			return requestJson(YoRoAIAdmin.executeUrl, {
				method: 'POST',
				body: JSON.stringify(payload)
			}).finally(function() {
				finishProtectedWork();
			});
		}

			function getConfirmationActionContext(target) {
			const actions = target && typeof target.closest === 'function'
				? target.closest('.yoro-confirm-actions')
				: null;
			const approve = actions ? actions.querySelector('.yoro-confirm-run') : null;
			let params = {};

			if (approve && approve.getAttribute('data-direct-params')) {
				try {
					params = JSON.parse(approve.getAttribute('data-direct-params') || '{}') || {};
				} catch (error) {
					params = {};
				}
			}

			return {
				action: approve ? (approve.getAttribute('data-direct-action') || '') : '',
				params: params,
				pending_operation_token: approve
					? (approve.getAttribute('data-pending-operation-token') || conversationState.pending_operation_token || '')
					: (conversationState.pending_operation_token || '')
			};
		}

		function recordCancelledAction(target) {
			const actionContext = getConfirmationActionContext(target);
			const conversationId = conversationState.conversation_id || '';
			const pendingOperationToken = actionContext.pending_operation_token || '';

			if (!window.YoRoAIAdmin || !YoRoAIAdmin.cancelActionUrl || !conversationId || !pendingOperationToken) {
				return Promise.resolve(null);
			}

			return requestJson(YoRoAIAdmin.cancelActionUrl, {
				method: 'POST',
				body: JSON.stringify({
					conversation_id: conversationId,
					pending_operation_token: pendingOperationToken,
					action: actionContext.action || '',
					params: actionContext.params || {}
				})
			}).catch(function () {
				return null;
			});
		}

		function setCurrentOrderId(orderId) {
			orderId = parseInt(orderId || 0, 10);

			if (orderId > 0) {
				conversationState.current_order_id = orderId;
				conversationState.last_referenced_order_id = orderId;
			}
		}

		function shouldShowDebugJson() {
			return !!(window.YoRoAIAdmin && YoRoAIAdmin.debug);
		}

		function renderJsonFallback(data) {
			return '<pre class="yoro-json-output">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';
		}

		function renderDebugJsonProxy(data) {
			return resultUI ? resultUI.renderDebugJson(data) : '';
		}

			function renderJsonProxy(data) {
			return resultUI
				? resultUI.renderJson(data)
				: renderJsonFallback(data);
		}

		if (quickActionsToggle && quickActionsPanel) {
			quickActionsToggle.addEventListener('click', function () {
				setQuickActionsOpen(quickActionsPanel.hidden);
			});
		}

		if (quickSearchField) {
			quickSearchField.addEventListener('input', function () {
				renderQuickSearchState(quickSearchField.value);
			});

			quickSearchField.addEventListener('keydown', function(event) {
				if (event.key === 'Enter') {
					const intent = getQuickSearchIntent(quickSearchField.value);

					if (intent && intent.action) {
						event.preventDefault();
						runQuickAction(intent.action, intent.params || {}, {
							secondaryAction: intent.secondaryAction || '',
							secondaryParams: intent.secondaryParams || {}
						});
					}
				}
			});
		}

		runButton.addEventListener('click', function () {
			submitComposerCommand();
		});

		commandField.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey) {
				if (event.repeat) {
					return;
				}

				if (isComposerComposing || event.isComposing || event.keyCode === 229 || event.which === 229) {
					event.preventDefault();
					submitAfterCompositionEnd = true;
					return;
				}

				event.preventDefault();
				submitComposerCommand();
			}
		});

		if (contextButton) {
			contextButton.addEventListener('click', function () {
				loadContext();
			});
		}

		if (clearButton) {
			clearButton.addEventListener('click', function () {
				chatThread.innerHTML = '';
				addMessage(
					'assistant',
					'<p>Chat cleared. How can I help manage your store?</p>'
				);
				commandField.value = '';
				syncRunButtonState();
				syncBeforeUnloadGuard();
					conversationState = {
						conversation_id: '',
						pending_operation_token: '',
						surface: '',
						admin_copilot: null,
						last_order_ids: [],
						last_orders: [],
						last_referenced_order_id: 0,
						current_order_id: 0,
						pending_confirm_command: '',
						next_action_catalog_scope: '',
						next_report_topic_selection: '',
						next_report_topic: '',
						next_scoped_command: ''
					};
				clearStoredConversationId();
				refreshAiApiStatusConversationLink();

				clearCommandGhostHint();
				syncBeforeUnloadGuard();
				commandField.focus();
			});
		}

		const emailUI = window.YoRoEmailUI
			? window.YoRoEmailUI.init({
					escapeHtml: escapeHtml,
					formatDateTime: formatDateTimeForChat,
					renderDebugJson: renderDebugJsonProxy,
					resolveOrderId: resolveOrderId,
				setCurrentOrderId: setCurrentOrderId,
				executeActionDirect: executeActionDirect,
				addAssistantMessage: addAssistantMessage,
				addLoadingMessage: addLoadingMessage,
				updateConversationState: updateConversationState,
				runCommandFromText: runCommandFromText,
				openModal: openModal
			})
			: null;

			const orderUI = window.YoRoOrderUI
				? window.YoRoOrderUI.init({
						escapeHtml: escapeHtml,
						formatDateTime: formatDateTimeForChat,
						renderDebugJson: renderDebugJsonProxy,
						shouldShowDebugJson: shouldShowDebugJson,
						getConversationState: function() {
						return conversationState;
				},
				addAssistantMessage: addAssistantMessage,
				addLoadingMessage: addLoadingMessage,
				updateConversationState: updateConversationState,
				runCommandFromText: runCommandFromText,
				openModal: openModal,
				openTextModal: openTextModal,
				openNoteModal: openNoteModal,
				openStatusModal: openStatusModal,
				executeActionDirect: executeActionDirect,
				setCurrentOrderId: setCurrentOrderId,
				getSelectedOrderIds: getSelectedOrderIds
			})
			: null;

			resultUI = window.YoRoResultRenderers
				? window.YoRoResultRenderers.init({
					escapeHtml: escapeHtml,
					formatDateTime: formatDateTimeForChat,
					shouldShowDebugJson: shouldShowDebugJson,
					renderWorkflowPreview: window.YoRoWorkflowRenderers
					? function(data) {
						return window.YoRoWorkflowRenderers.renderWorkflowPreview(data, {
							escapeHtml: escapeHtml,
							renderDebugJson: renderDebugJsonProxy,
							renderJson: renderJsonProxy,
							renderCustomerEmailPreviewResult: emailUI
								? emailUI.renderCustomerEmailPreviewResult
								: null
						});
					}
					: null,
				renderMultiActionResult: window.YoRoWorkflowRenderers
					? function(data) {
						return window.YoRoWorkflowRenderers.renderMultiActionResult(data, {
							escapeHtml: escapeHtml,
							renderDebugJson: renderDebugJsonProxy,
							renderJson: renderJsonProxy,
							renderCustomerEmailPreviewResult: emailUI
								? emailUI.renderCustomerEmailPreviewResult
								: null
						});
					}
					: null,
				renderOrderDetailsResult: orderUI
					? orderUI.renderOrderDetailsResult
					: null,
				renderOrdersResult: orderUI
					? orderUI.renderOrdersResult
					: null,
				renderCustomerSummaryResult: orderUI
					? orderUI.renderCustomerSummaryResult
					: null,
				renderReportResult: orderUI
					? orderUI.renderReportResult
					: null,
				renderOperationsAttentionQueueResult: orderUI
					? orderUI.renderOperationsAttentionQueueResult
					: null,
				renderFulfillmentQueueResult: orderUI
					? orderUI.renderFulfillmentQueueResult
					: null,
				renderInventoryQueueResult: orderUI
					? orderUI.renderInventoryQueueResult
					: null,
				renderCustomerServiceQueueResult: orderUI
					? orderUI.renderCustomerServiceQueueResult
					: null,
				renderRefundPreviewResult: orderUI
					? orderUI.renderRefundPreviewResult
					: null,
				renderCustomerEmailPreviewResult: emailUI
					? emailUI.renderCustomerEmailPreviewResult
					: null,
				renderEmailTemplatesResult: emailUI
					? emailUI.renderEmailTemplatesResult
					: null,
				renderCustomerEmailDraftsResult: emailUI
					? emailUI.renderCustomerEmailDraftsResult
					: null
				})
				: null;

			hydrateStoredConversation();

			chatThread.addEventListener('click', function (event) {
				const target = event.target;
				const clarifyOption = target.closest('.yoro-clarify-option');
				const approveButton = target.closest('.yoro-confirm-run');
				const confirmCancel = target.closest('.yoro-confirm-cancel');
				const undoLast = target.closest('.yoro-undo-last');
				const insertCommand = target.closest('.yoro-insert-command');

				if (clarifyOption) {
					event.preventDefault();

					const command = clarifyOption.getAttribute('data-command') || '';

					if (command) {
						commandField.value = command;
						syncBeforeUnloadGuard();
						runCommand(false);
					}

					return;
				}

				if (approveButton) {
					event.preventDefault();

					const directAction = approveButton.getAttribute('data-direct-action') || '';
					const directParamsRaw = approveButton.getAttribute('data-direct-params') || '';
					const pendingOperationTokenAttr = approveButton.getAttribute('data-pending-operation-token') || '';
					const directPendingOperationToken = pendingOperationTokenAttr || (directAction ? '' : (conversationState.pending_operation_token || ''));

					if (!directAction && !directPendingOperationToken) {
						hideConfirmationActions(approveButton);
						addAssistantMessage({
							success: true,
							stage: 'done',
							message: 'That approval is no longer active. Please start a new request if you want me to apply it.',
							result: {
								success: true,
								summary: 'That approval is no longer active. Please start a new request if you want me to apply it.',
								read_only: true,
								result_type: 'assistant_message',
								display_type: 'assistant_message'
							}
						});
						syncBeforeUnloadGuard();
						return;
					}

					if (directAction) {
						let directParams = {};

						if (directParamsRaw) {
							try {
								directParams = JSON.parse(directParamsRaw);
							} catch (error) {
								addAssistantMessage({
									success: false,
									message: 'I could not approve that saved action. Please review it again.'
								});
								return;
							}
						}

						executeActionDirect(directAction, directParams, true, false, '', directPendingOperationToken)
							.then(function(data) {
								hideConfirmationActions(approveButton);
								addAssistantMessage(data);
								updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || directAction));
							})
							.catch(function(error) {
								addAssistantMessage({
									success: false,
									error: error
								});
							});

						return;
					}

					if (directPendingOperationToken) {
						conversationState.pending_operation_token = directPendingOperationToken;
					}

					runCommand(true, function() {
						hideConfirmationActions(approveButton);
					}, conversationState.pending_confirm_command || commandField.value.trim());
					conversationState.pending_confirm_command = '';

					return;
				}

				if (confirmCancel) {
					event.preventDefault();
					hideConfirmationActions(confirmCancel);

					recordCancelledAction(confirmCancel).then(function(data) {
						if (data) {
							updateConversationState(data, 'cancelled');
						}
					});

					conversationState.pending_confirm_command = '';
					conversationState.pending_operation_token = '';
					addAssistantMessage({
						success: true,
						stage: 'cancelled',
						message: 'Action cancelled.',
						result: {
							success: true,
							message: 'Action cancelled.',
							summary: 'Action cancelled.',
							read_only: true,
							result_type: 'assistant_message',
							display_type: 'assistant_message'
						}
					});
					syncBeforeUnloadGuard();

					return;
				}

				if (undoLast) {
					event.preventDefault();
					const hiddenUndoAction = hideUndoAction(undoLast);
					const logId = undoLast.getAttribute('data-log-id') || '';

					if (!logId) {
						restoreHiddenAction(hiddenUndoAction);
						addAssistantMessage({
							success: false,
							message: 'I could not find the saved change to undo.'
						});
						return;
					}

					undoLog(logId)
						.then(function (data) {
							addAssistantMessage({
								success: true,
								message: data.message || 'I undid that change.',
								stage: 'undo',
								result: data
							});
						})
						.catch(function (error) {
							restoreHiddenAction(hiddenUndoAction);
							addAssistantMessage({
								success: false,
								error: error
							});
						});

					return;
				}

				if (insertCommand) {
					event.preventDefault();

					commandField.value = insertCommand.getAttribute('data-command') || '';
					syncRunButtonState();
					syncBeforeUnloadGuard();
					commandField.focus();

					return;
				}

				/*
				 * Let specialized UI modules handle their own buttons after core chat buttons.
				 */
				if (emailUI && emailUI.handleClick(event)) {
					return;
				}

				if (orderUI && orderUI.handleClick(event)) {
					return;
				}
			});

		document.addEventListener('click', function(event) {
			const quickPreset = event.target.closest('.yoro-chat-quick-preset');
			const quickSearchRun = event.target.closest('[data-yoro-quick-run="1"]');

			if (quickPreset) {
				const action = quickPreset.getAttribute('data-yoro-quick-action') || '';
				const params = parseQuickActionParams(quickPreset.getAttribute('data-yoro-quick-params') || '');
				runQuickAction(action, params);
				return;
			}

			if (quickSearchRun) {
				const action = quickSearchRun.getAttribute('data-action') || '';
				const params = parseQuickActionParams(quickSearchRun.getAttribute('data-params') || '');
				runQuickAction(action, params, {
					secondaryAction: quickSearchRun.getAttribute('data-secondary-action') || '',
					secondaryParams: parseQuickActionParams(quickSearchRun.getAttribute('data-secondary-params') || '')
				});
				return;
			}

			if (
				quickActionsRoot &&
				quickActionsPanel &&
				!quickActionsPanel.hidden &&
				!quickActionsRoot.contains(event.target)
			) {
				setQuickActionsOpen(false);
			}
		});

		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape' && quickActionsPanel && !quickActionsPanel.hidden) {
				setQuickActionsOpen(false);
			}
		});

			exampleButtons.forEach(function (button) {
				button.addEventListener('click', function () {
					const command = button.getAttribute('data-command') || '';
					commandField.value = command;
					setNextActionCatalogScope(
						command,
						button.getAttribute('data-action-catalog-scope') || '',
						button.getAttribute('data-report-topic') || ''
					);
					syncRunButtonState();
					syncBeforeUnloadGuard();
					commandField.focus();
			});
		});

		syncRunButtonState();
	});
})();
