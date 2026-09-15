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
		const chatShell = document.querySelector('.yoro-chat-shell');
		const chatComposer = document.querySelector('.yoro-chat-composer');
		const chatThread = document.getElementById('yoro-chat-thread');
		const inboxButton = document.getElementById('yoro-ai-open-inbox');
		const workflowButtons = document.querySelectorAll('[data-yoro-workflow-action]');
		const quickActionsRoot = document.querySelector('[data-yoro-quick-actions]');
		const quickActionsPanel = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-panel]') : null;
		const quickActionsToggle = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-toggle]') : null;
		const quickSearchField = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-search]') : null;
		const quickSearchResults = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-results]') : null;
		const quickInboxBody = quickActionsRoot ? quickActionsRoot.querySelector('[data-yoro-quick-inbox-body]') : null;
		const aiApiStatusRoot = document.querySelector('[data-yoro-ai-api-status]');
		const aiApiStatusToggle = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-toggle]') : null;
		const aiApiStatusPanel = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-panel]') : null;
		const aiApiStatusLabel = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-label]') : null;
		const aiApiStatusBody = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-body]') : null;
		const aiApiStatusRefresh = aiApiStatusRoot ? aiApiStatusRoot.querySelector('[data-yoro-ai-api-status-refresh]') : null;
		const accountGate = document.querySelector('[data-yoro-account-gate]');
		const accountGateSettings = document.getElementById('yoro-account-gate-settings');
		const accountGateEyebrow = document.querySelector('[data-yoro-account-gate-eyebrow]');
		const accountGateTitle = document.querySelector('[data-yoro-account-gate-title]');
		const accountGateDescription = document.querySelector('[data-yoro-account-gate-description]');
		const accountGateAction = document.querySelector('[data-yoro-account-gate-action]');
		const accountGateDefaultAction = accountGateAction ? accountGateAction.innerHTML : '';
		let aiApiStatusData = null;
		let accountConnectionState = normalizeAccountConnection(
			window.YoRoAIAdmin && YoRoAIAdmin.accountConnection ? YoRoAIAdmin.accountConnection : {}
		);
		let actorAccessState = normalizeActorAccess({
			status: accountConnectionState.connected ? 'checking' : 'account_required',
			assigned: false
		});
		let accessHydrationPending = accountConnectionState.connected === true;
		let entitlementAccessState = {};
		let usageAccessState = {};

		if (!commandField || !runButton || !chatThread) {
			registerMailboxSettings();
			hydrateMailboxSettings(document);
			return;
		}

		let protectedWorkCount = 0;
		let unloadGuardEnabled = false;
		let conversationHydrationInFlight = false;
		let conversationHydrationDirty = false;
		let latestJumpPending = false;
		let isSending = false;
		const mailboxThreadContextCache = {};
		let nextMailboxThreadContext = null;

		const jumpLatestButton = document.createElement('button');
		jumpLatestButton.type = 'button';
		jumpLatestButton.className = 'yoro-chat-jump-latest';
		jumpLatestButton.hidden = true;
		jumpLatestButton.innerHTML = '<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span>Jump to latest</span>';

		if (chatShell) {
			chatShell.appendChild(jumpLatestButton);
		}

		jumpLatestButton.addEventListener('click', function () {
			scrollToBottom({ behavior: 'smooth', force: true });
		});

		chatThread.addEventListener('scroll', function () {
			if (isChatNearBottom()) {
				setJumpLatestVisible(false);
			}
		});

		window.addEventListener('resize', function () {
			updateJumpLatestOffset();
		});

		if (window.ResizeObserver && chatComposer) {
			const chatComposerResizeObserver = new ResizeObserver(function () {
				updateJumpLatestOffset();
			});

			chatComposerResizeObserver.observe(chatComposer);
		}

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
				loadQuickInbox(true);
				window.setTimeout(function() {
					quickSearchField.focus();
					quickSearchField.select();
				}, 20);
			}
		}

		function openQuickInboxPanel() {
			if (!requireAccountConnection()) {
				return false;
			}

			if (!quickActionsPanel || !quickActionsToggle || !quickInboxBody) {
				return false;
			}

			setQuickActionsOpen(true);

			return true;
		}

		function registerQuickActions() {
			if (quickActionsToggle && quickActionsPanel) {
				quickActionsToggle.addEventListener('click', function () {
					if (!requireAccountConnection()) {
						return;
					}

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

			document.addEventListener('click', function(event) {
				const quickSearchRun = event.target.closest('[data-yoro-quick-run="1"]');

				if (quickSearchRun) {
					const action = quickSearchRun.getAttribute('data-action') || '';
					const params = parseQuickActionParams(quickSearchRun.getAttribute('data-params') || '');
					runQuickAction(action, params, {
						secondaryAction: quickSearchRun.getAttribute('data-secondary-action') || '',
						secondaryParams: parseQuickActionParams(quickSearchRun.getAttribute('data-secondary-params') || '')
					});
					return;
				}

				if (handleQuickInboxClick(event)) {
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

			workflowButtons.forEach(function (button) {
				button.addEventListener('click', function () {
					const action = button.getAttribute('data-yoro-workflow-action') || '';
					const params = parseQuickActionParams(button.getAttribute('data-yoro-workflow-params') || '');
					const label = button.textContent ? button.textContent.trim() : action;

					runQuickAction(action, params, {
						surface: 'workflow-chip',
						userMessage: label
					});
				});
			});
		}

		function runQuickAction(action, params, options) {
			if (!action || isSending || !requireAccountConnection()) {
				return;
			}

			isSending = true;
			setQuickActionsOpen(false);
			options = options && typeof options === 'object' ? options : {};

			if (options.userMessage) {
				addUserMessage(String(options.userMessage));
			}

			const loading = addLoadingMessage();
			setLoading(true);

			executeActionDirect(action, params || {}, false, false, options.surface || 'quick-actions')
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
					isSending = false;
					setLoading(false);
				});
		}

		function handleQuickInboxClick(event) {
			if (!quickActionsRoot || !quickActionsRoot.contains(event.target)) {
				return false;
			}

			const target = event.target.closest(
				'.yoro-quick-inbox-refresh,' +
				'.yoro-quick-inbox-sync,' +
				'.yoro-quick-inbox-connect,' +
				'.yoro-mailbox-imap-submit,' +
				'.yoro-quick-inbox-thread,' +
				'.yoro-quick-inbox-back,' +
				'.yoro-quick-inbox-status-save,' +
				'.yoro-quick-inbox-assignee-save,' +
				'.yoro-quick-inbox-assignee-mine,' +
				'.yoro-quick-inbox-assignee-clear,' +
				'.yoro-quick-inbox-note-save,' +
				'.yoro-quick-inbox-mark-read,' +
				'.yoro-quick-inbox-mark-unread,' +
				'.yoro-quick-inbox-archive,' +
				'.yoro-quick-inbox-reopen,' +
				'.yoro-quick-inbox-retry,' +
				'.yoro-quick-inbox-draft-reply,' +
				'.yoro-quick-inbox-reply-suggestion,' +
				'.yoro-quick-inbox-follow-up-set,' +
				'.yoro-quick-inbox-follow-up-clear,' +
				'.yoro-quick-inbox-reply'
			);

			if (!target) {
				return false;
			}

			event.preventDefault();

			if (target.classList.contains('yoro-quick-inbox-refresh')) {
				loadQuickInbox(false);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-sync')) {
				loadQuickInbox(true);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-connect')) {
				startMailboxProviderConnect(target.getAttribute('data-yoro-mailbox-provider') || 'gmail', {
					button: target,
					quickInbox: true
				});
				return true;
			}

			if (target.classList.contains('yoro-mailbox-imap-submit')) {
				connectImapMailbox(target, {
					quickInbox: true
				});
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-thread')) {
				loadQuickInboxThread(target.getAttribute('data-thread-id') || '');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-back')) {
				loadQuickInbox(false);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-status-save')) {
				saveMailboxThreadStatus(target);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-assignee-save')) {
				saveMailboxThreadAssignee(target, 'form');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-assignee-mine')) {
				saveMailboxThreadAssignee(target, 'current');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-assignee-clear')) {
				saveMailboxThreadAssignee(target, 'clear');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-note-save')) {
				addMailboxThreadNote(target);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-mark-read')) {
				runMailboxThreadCommand(target, 'read');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-mark-unread')) {
				runMailboxThreadCommand(target, 'unread');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-archive')) {
				runMailboxThreadCommand(target, 'archive');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-reopen')) {
				runMailboxThreadCommand(target, 'reopen');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-retry')) {
				retryMailboxThreadReply(target);
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-draft-reply')) {
				askYoroToDraftMailboxReply(target, {
					quickInbox: true
				});
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-reply-suggestion')) {
				applyMailboxReplySuggestion(target, {
					quickInbox: true
				});
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-follow-up-set')) {
				saveMailboxThreadFollowUp(target, 'set');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-follow-up-clear')) {
				saveMailboxThreadFollowUp(target, 'clear');
				return true;
			}

			if (target.classList.contains('yoro-quick-inbox-reply')) {
				sendMailboxThreadReply(target, {
					quickInbox: true
				});
				return true;
			}

			return false;
		}

		function setQuickInboxHtml(html) {
			if (quickInboxBody) {
				quickInboxBody.innerHTML = html;
			}
		}

		function renderQuickInboxLoading(message) {
			setQuickInboxHtml(
				'<div class="yoro-quick-inbox-state is-loading">' +
				'<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
				'<p>' + escapeHtml(message || 'Loading inbox...') + '</p>' +
				'</div>'
			);
		}

		function loadQuickInbox(syncFirst) {
			if (!quickInboxBody || !window.YoRoAIAdmin || !YoRoAIAdmin.emailInboxUrl) {
				return;
			}

			renderQuickInboxLoading(syncFirst ? 'Syncing inbox...' : 'Loading inbox...');

			const inboxRequest = function() {
				return requestJson(YoRoAIAdmin.emailInboxUrl + '?limit=8', {
					method: 'GET'
				});
			};
			const request = syncFirst && YoRoAIAdmin.emailInboxSyncUrl
				? requestJson(YoRoAIAdmin.emailInboxSyncUrl, {
					method: 'POST',
					body: JSON.stringify({ limit: 10 })
				}).catch(function() {
					return null;
				}).then(inboxRequest)
				: inboxRequest();

			request
				.then(function(data) {
					renderQuickInbox(data || {});
				})
				.catch(function(error) {
					renderQuickInboxError(error, 'I could not load the shop inbox.');
				});
		}

		function renderQuickInbox(data) {
			data = data || {};

			if (!data.connected) {
				setQuickInboxHtml(renderQuickInboxDisconnected(data));
				return;
			}

			const connection = data.connection || {};
			const threads = Array.isArray(data.threads) ? data.threads : [];
			let html = '<div class="yoro-quick-inbox-summary">';
			html += '<span>' + escapeHtml(connection.mailbox_email || 'Connected mailbox') + '</span>';
			html += '<strong>' + escapeHtml(data.unread_count || 0) + ' unread</strong>';
			html += '<small>' + escapeHtml(data.open_thread_count || 0) + ' open · synced ' + escapeHtml(formatMailboxDateTime(connection.last_synced_at) || 'never') + '</small>';
			html += '</div>';
			html += renderMailboxAlignmentNotice(connection.mailbox_email, 'yoro-quick-inbox-warning');

			if (!threads.length) {
				html += '<div class="yoro-quick-inbox-state"><p>No recent inbox threads found.</p></div>';
				setQuickInboxHtml(html);
				return;
			}

			html += '<div class="yoro-quick-inbox-list">';
			threads.forEach(function(thread) {
				const latest = thread.latest_message || {};
				html += '<button type="button" class="yoro-quick-inbox-thread' + (thread.unread ? ' is-unread' : '') + '" data-thread-id="' + escapeHtml(thread.id || '') + '">';
				html += '<span>';
				html += '<strong>' + escapeHtml(thread.subject || '(No subject)') + '</strong>';
				html += '<em>' + escapeHtml(latest.from_email || mailboxParticipantsLabel(thread) || 'Unknown sender') + '</em>';
				html += '<small>' + escapeHtml(thread.snippet || latest.snippet || '') + '</small>';
				html += '</span>';
				html += '<time>' + escapeHtml(formatMailboxDateTime(thread.last_message_at)) + '</time>';
				html += '</button>';
			});
			html += '</div>';

			setQuickInboxHtml(html);
		}

		function renderQuickInboxDisconnected(data) {
			const message = data && data.message ? data.message : 'Connect an email provider so YoRo can show and reply to customer emails from the shop inbox.';
			let html = '<div class="yoro-quick-inbox-connect-card">';
			html += '<div class="yoro-quick-inbox-state">';
			html += '<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>';
			html += '<p>' + escapeHtml(message) + '</p>';
			html += '</div>';
			html += renderMailboxRecommendation('yoro-quick-inbox-recommendation');
			html += renderMailboxProviderOptions('yoro-quick-inbox-providers', 'yoro-quick-inbox-connect');
			html += '</div>';

			return html;
		}

		function renderQuickInboxError(error, fallback) {
			setQuickInboxHtml(
				'<div class="yoro-quick-inbox-state is-error">' +
				'<span class="dashicons dashicons-warning" aria-hidden="true"></span>' +
				'<p>' + escapeHtml(mailboxErrorMessage(error, fallback)) + '</p>' +
				'</div>'
			);
		}

		function loadQuickInboxThread(threadId) {
			if (!threadId) {
				renderQuickInboxError(null, 'I could not identify that email thread.');
				return;
			}

			renderQuickInboxLoading('Loading thread...');

			requestJson(mailboxThreadUrl(threadId), {
				method: 'GET'
			})
				.then(function(data) {
					renderQuickInboxThread(data || {});
				})
				.catch(function(error) {
					renderQuickInboxError(error, 'I could not load that email thread.');
				});
		}

		function renderQuickInboxThread(data) {
			data = data || {};
			rememberMailboxThreadContext(data);

			const thread = data.thread || {};
			const messages = Array.isArray(data.messages) ? data.messages : [];
			let html = '<div class="yoro-quick-inbox-thread-view" data-yoro-mailbox-thread="' + escapeHtml(thread.id || '') + '" data-yoro-quick-inbox-thread="' + escapeHtml(thread.id || '') + '" data-yoro-thread-subject="' + escapeHtml(thread.subject || '') + '">';
			html += '<div class="yoro-quick-inbox-thread-head">';
			html += '<button type="button" class="button button-small yoro-quick-inbox-back"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><span>Inbox</span></button>';
			html += '<div><strong>' + escapeHtml(thread.subject || '(No subject)') + '</strong><span>' + escapeHtml(mailboxParticipantsLabel(thread)) + '</span>' + renderMailboxThreadBadges(data, 'yoro-quick-inbox') + '</div>';
			html += '</div>';
			html += '<div class="yoro-quick-inbox-messages">';
			messages.forEach(function(message) {
				if (message.direction === 'internal') {
					return;
				}
				const isOutbound = message.direction === 'outbound';
				html += '<div class="yoro-quick-inbox-message ' + (isOutbound ? 'is-outbound' : 'is-inbound') + '">';
				html += '<div><strong>' + escapeHtml(isOutbound ? 'You' : (message.from_name || message.from_email || 'Customer')) + '</strong><time>' + escapeHtml(formatMailboxDateTime(message.sent_at || message.received_at || message.created_at)) + '</time></div>';
				html += '<div class="yoro-quick-inbox-message-body">' + mailboxMessageBodyHtml(message) + '</div>';
				html += '</div>';
			});
			html += '</div>';
			html += '<div class="yoro-quick-inbox-reply-box">';
			html += '<textarea rows="3" class="yoro-quick-inbox-reply-input" placeholder="Reply from the shop inbox"></textarea>';
			html += '<div class="yoro-quick-inbox-reply-actions">';
			html += '<button type="button" class="button yoro-quick-inbox-draft-reply" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span><span>Draft with YoRo</span></button>';
			html += '<button type="button" class="button button-primary yoro-quick-inbox-reply" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>Send reply</span></button>';
			html += '</div>';
			html += '</div>';
			html += '</div>';

			setQuickInboxHtml(html);
		}

		function sendMailboxThreadReply(target, options) {
			options = options || {};

			const threadId = mailboxThreadIdFromTarget(target);
			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');
			const input = panel ? panel.querySelector('.yoro-quick-inbox-reply-input, .yoro-mailbox-reply-input') : null;
			const subject = panel ? (panel.getAttribute('data-yoro-thread-subject') || '') : '';
			const body = input ? input.value.trim() : '';

			if (!threadId || !body) {
				renderMailboxThreadSurfaceError(target, null, 'Please enter a reply before sending.');
				return;
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId, '/reply'), {
				method: 'POST',
				body: JSON.stringify({
					subject: subject,
					body: body
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not send the email reply.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function mailboxThreadIdFromTarget(target) {
			if (!target) {
				return '';
			}

			const direct = String(target.getAttribute('data-thread-id') || '').trim();

			if (direct) {
				return direct;
			}

			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');

			return panel
				? String(panel.getAttribute('data-yoro-mailbox-thread') || panel.getAttribute('data-yoro-quick-inbox-thread') || '').trim()
				: '';
		}

		function mailboxActorPayload() {
			const user = window.YoRoAIAdmin && YoRoAIAdmin.currentUser ? YoRoAIAdmin.currentUser : {};

			return {
				id: String(user.id || ''),
				name: String(user.name || ''),
				email: String(user.email || ''),
				role: String(user.role || 'admin')
			};
		}

		function mailboxAssigneeOptions() {
			const options = window.YoRoAIAdmin && Array.isArray(YoRoAIAdmin.mailboxAssignees)
				? YoRoAIAdmin.mailboxAssignees
				: [];

			return options.map(function(option) {
				return {
					id: String(option.id || ''),
					name: String(option.name || ''),
					email: String(option.email || ''),
					role: String(option.role || 'staff')
				};
			}).filter(function(option) {
				return !!(option.id || option.name || option.email);
			});
		}

		function mailboxAssigneeOptionValue(option) {
			return String(option.id || option.email || option.name || '').trim();
		}

		function mailboxAssigneeFromValue(value) {
			value = String(value || '').trim();

			if (!value) {
				return null;
			}

			return mailboxAssigneeOptions().find(function(option) {
				return mailboxAssigneeOptionValue(option) === value;
			}) || null;
		}

		function saveMailboxThreadStatus(target) {
			const threadId = mailboxThreadIdFromTarget(target);
			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');
			const select = panel ? panel.querySelector('.yoro-quick-inbox-status, .yoro-mailbox-status') : null;
			const status = select ? String(select.value || '').trim() : '';

			if (!threadId || !status) {
				renderMailboxThreadSurfaceError(target, null, 'Please choose a status for this email thread.');
				return;
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId), {
				method: 'PATCH',
				body: JSON.stringify({
					status: status,
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not update the email thread status.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function saveMailboxThreadAssignee(target, mode) {
			const threadId = mailboxThreadIdFromTarget(target);
			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');
			const picker = panel ? panel.querySelector('.yoro-quick-inbox-assignee-picker, .yoro-mailbox-assignee-picker') : null;
			let assignee = null;

			if (!threadId) {
				renderMailboxThreadSurfaceError(target, null, 'I could not identify that email thread.');
				return;
			}

			if (mode === 'current') {
				assignee = mailboxActorPayload();
			} else if (mode !== 'clear') {
				assignee = picker ? mailboxAssigneeFromValue(picker.value) : null;

				if (!assignee) {
					renderMailboxThreadSurfaceError(target, null, 'Choose an owner before saving.');
					return;
				}
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId), {
				method: 'PATCH',
				body: JSON.stringify({
					assignee: assignee,
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not update the email thread owner.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function saveMailboxThreadFollowUp(target, mode) {
			const threadId = mailboxThreadIdFromTarget(target);
			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');
			const picker = panel ? panel.querySelector('.yoro-quick-inbox-follow-up-picker, .yoro-mailbox-follow-up-picker') : null;
			const hours = picker ? parseInt(picker.value || '0', 10) : 0;
			let reminder = null;

			if (!threadId) {
				renderMailboxThreadSurfaceError(target, null, 'I could not identify that email thread.');
				return;
			}

			if (mode === 'set') {
				if (!hours || hours < 1) {
					renderMailboxThreadSurfaceError(target, null, 'Choose when YoRo should remind you.');
					return;
				}

				reminder = {
					after_hours: hours,
					reason: 'Follow up if this email thread has not been answered.'
				};
			} else {
				reminder = {
					status: 'clear'
				};
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId), {
				method: 'PATCH',
				body: JSON.stringify({
					follow_up_reminder: reminder,
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not update the follow-up reminder.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function applyMailboxReplySuggestion(target, options) {
			options = options || {};

			const instruction = String(target.getAttribute('data-reply-instruction') || '').trim();
			const template = mailboxSelectedTemplate(target);

			if (!instruction) {
				renderMailboxThreadSurfaceError(target, null, 'I could not identify that reply suggestion.');
				return;
			}

			askYoroToDraftMailboxReply(target, {
				quickInbox: !!options.quickInbox,
				settingsRoot: options.settingsRoot || null,
				instruction: instruction,
				template: template
			});
		}

		function mailboxSelectedTemplate(target) {
			return 'WooCommerce store email template';
		}

		function addMailboxThreadNote(target) {
			const threadId = mailboxThreadIdFromTarget(target);
			const panel = target.closest('[data-yoro-mailbox-thread], [data-yoro-quick-inbox-thread]');
			const input = panel ? panel.querySelector('.yoro-quick-inbox-note-input, .yoro-mailbox-note-input') : null;
			const body = input ? String(input.value || '').trim() : '';

			if (!threadId || !body) {
				renderMailboxThreadSurfaceError(target, null, 'Write an internal note before saving.');
				return;
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId, '/notes'), {
				method: 'POST',
				body: JSON.stringify({
					body: body,
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					if (input) {
						input.value = '';
					}
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not save the internal note.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function runMailboxThreadCommand(target, command) {
			const threadId = mailboxThreadIdFromTarget(target);

			if (!threadId) {
				renderMailboxThreadSurfaceError(target, null, 'I could not identify that email thread.');
				return;
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId, '/' + command), {
				method: 'POST',
				body: JSON.stringify({
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not update that email thread.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function retryMailboxThreadReply(target) {
			const threadId = mailboxThreadIdFromTarget(target);
			const messageId = String(target.getAttribute('data-message-id') || '').trim();

			if (!threadId || !messageId) {
				renderMailboxThreadSurfaceError(target, null, 'I could not identify that failed reply.');
				return;
			}

			target.disabled = true;

			requestJson(mailboxThreadUrl(threadId, '/messages/' + encodeURIComponent(messageId) + '/retry'), {
				method: 'POST',
				body: JSON.stringify({
					actor: mailboxActorPayload()
				})
			})
				.then(function() {
					reloadMailboxThreadSurface(target, threadId);
				})
				.catch(function(error) {
					renderMailboxThreadSurfaceError(target, error, 'I could not retry the failed reply.');
				})
				.finally(function() {
					target.disabled = false;
				});
		}

		function reloadMailboxThreadSurface(target, threadId) {
			const settingsRoot = target ? target.closest('[data-yoro-mailbox-settings]') : null;

			if (settingsRoot) {
				loadMailboxSettingsThread(settingsRoot, threadId);
				return;
			}

			loadQuickInboxThread(threadId);
		}

		function renderMailboxThreadSurfaceError(target, error, fallback) {
			const settingsRoot = target ? target.closest('[data-yoro-mailbox-settings]') : null;

			if (settingsRoot) {
				renderMailboxSettingsError(settingsRoot, error, fallback);
				return;
			}

			renderQuickInboxError(error, fallback);
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
			if (!window.YoRoAIAdmin || !YoRoAIAdmin.aiApiHandshakeUrl) {
				return;
			}

			if (aiApiStatusRoot && aiApiStatusToggle && aiApiStatusPanel && aiApiStatusBody) {
				aiApiStatusToggle.addEventListener('click', function () {
					const isOpen = aiApiStatusPanel.hidden;
					aiApiStatusPanel.hidden = !isOpen;
					aiApiStatusToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
				});

				if (aiApiStatusRefresh) {
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
			}

			loadAiApiStatus(true);
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

			aiApiStatusBody.innerHTML = '<p>Checking YoRo AI status...</p>';
		}

		function renderAiApiStatus(data) {
			aiApiStatusData = data || {};

			const connection = data && data.connection ? data.connection : {};
			const accountConnection = data && data.account_connection ? data.account_connection : {};
			const actorAccess = data && data.actor_access ? data.actor_access : {};
			const site = data && data.site ? data.site : {};
			const usage = data && data.usage ? data.usage : {};
			const entitlements = data && data.entitlements ? data.entitlements : {};
			const capabilities = data && data.capabilities ? data.capabilities : {};
			const configured = data && data.configured ? data.configured : {};
			const connected = !!accountConnection.connected;
			const serviceAvailable = connection.service_available !== false && accountConnection.service_available !== false;
			const plan = site.plan || entitlements.usage_plan || '';
			const remaining = usage.remaining;
			const hasQuota = remaining === null || remaining === undefined || Number(remaining) > 0;
			accessHydrationPending = false;
			applyAccountConnectionState(accountConnection, actorAccess, entitlements, usage);
			const accessActive = isYoRoAccessActive();
			const stateClass = accessActive && hasQuota && serviceAvailable ? 'is-connected' : (connected ? 'is-warning' : 'is-error');
			const provider = data && data.resolved_provider ? data.resolved_provider : '';
			const accountUrl = (data && data.account_url) || YoRoAIAdmin.aiApiAccountUrl || '';
			const billingUrl = (data && data.billing_url) || YoRoAIAdmin.aiApiBillingUrl || '';
			const dashboardUrl = (data && data.dashboard_url) || YoRoAIAdmin.aiApiDashboardUrl || '';
			const conversationDashboardUrl = getConversationDashboardUrl(conversationState.conversation_id, data);
			const actionLinks = [];

			if (!aiApiStatusToggle || !aiApiStatusBody) {
				return;
			}

			aiApiStatusToggle.classList.remove('is-loading', 'is-connected', 'is-warning', 'is-error');
			aiApiStatusToggle.classList.add(stateClass);

			if (aiApiStatusLabel) {
				aiApiStatusLabel.textContent = connected
					? ('YoRo AI' + (serviceAvailable ? (plan ? ' · ' + plan : '') : ' · temporarily unavailable'))
					: 'YoRo AI · connect account';
			}

			let html = '';
			html += '<dl class="yoro-ai-api-status-grid">';
			html += renderAiApiStatusRow('Account', connected ? 'Connected' : (accountConnection.message || connection.message || 'Connection required'));
			html += renderAiApiStatusRow('Operator', actorAccess.assigned ? (actorAccess.role || 'Assigned') : (actorAccess.message || actorAccess.status || 'Seat required'));
			html += renderAiApiStatusRow('Service', serviceAvailable ? formatAiApiProviderName(provider) : 'Temporarily unavailable');
			html += renderAiApiStatusRow('Plan', plan || 'unknown');
			html += renderAiApiStatusRow('Subscription', entitlements.subscription_status || 'unknown');
			html += renderAiApiStatusRow('Usage', renderAiApiUsageText(usage));
			html += renderAiApiStatusRow('Last checked', connection.checked_at || 'not checked');
			html += '</dl>';
			html += '<div class="yoro-ai-api-status-features">';
			html += renderAiApiFeatureBadge('Conversation AI', capabilities.conversation, entitlements.conversation);
			html += renderAiApiFeatureBadge('Email compose', capabilities.email_compose, entitlements.email_composer);
			html += '</div>';

			if (accountUrl) {
				actionLinks.push(renderAiApiStatusAction(accountUrl, 'Open account'));
			}

			if (billingUrl) {
				actionLinks.push(renderAiApiStatusAction(billingUrl, 'Billing / Upgrade'));
			}

			if (dashboardUrl) {
				actionLinks.push(renderAiApiStatusAction(dashboardUrl, 'Diagnostics'));
			}

			if (conversationDashboardUrl) {
				actionLinks.push(renderAiApiStatusAction(conversationDashboardUrl, 'Current conversation'));
			}

			if (actionLinks.length) {
				html += '<div class="yoro-ai-api-status-actions">' + actionLinks.join('') + '</div>';
			}

			if (!configured.enabled) {
				html += '<p class="yoro-ai-api-status-note">YoRo AI is configured but disabled in plugin settings.</p>';
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
				|| 'I could not check the YoRo AI connection right now.';

			accessHydrationPending = false;

			if (isAccountConnectionActive()) {
				applyAccountConnectionState(accountConnectionState, {
					required: true,
					assigned: false,
					status: 'unavailable',
					message: message
				});
			}

			if (aiApiStatusToggle) {
				aiApiStatusToggle.classList.remove('is-loading', 'is-connected', 'is-warning');
				aiApiStatusToggle.classList.add(isAccountConnectionActive() ? 'is-warning' : 'is-error');
			}

			if (aiApiStatusLabel) {
				aiApiStatusLabel.textContent = isAccountConnectionActive() ? 'YoRo AI · temporarily unavailable' : 'YoRo AI · connect account';
			}

			if (aiApiStatusBody) {
				aiApiStatusBody.innerHTML = '<p class="yoro-ai-api-status-error">' + escapeHtml(message) + '</p>';
			}
		}

		function renderAiApiStatusRow(label, value) {
			return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd></div>';
		}

		function renderAiApiStatusAction(url, label) {
			return '<a class="button button-small yoro-ai-api-status-action" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label) + '</a>';
		}

		function formatAiApiProviderName(provider) {
			return String(provider || '').trim() === 'yoro_ai_api' ? 'YoRo AI' : (provider || 'unknown');
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

		const adminCover = document.createElement('div');
		adminCover.className = 'yoro-admin-cover hidden';
		adminCover.innerHTML = `
			<div class="yoro-admin-cover-head">
				<h3>YoRo AI Admin</h3>
				<button type="button" class="button yoro-admin-cover-close">Close</button>
			</div>
			<div class="yoro-admin-cover-body"></div>
		`;

		if (chatShell) {
			chatShell.appendChild(adminCover);
		}

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
			const panelRoot = root || null;

			if (!panelRoot) {
				return;
			}

			const tabs = panelRoot.querySelectorAll('[data-yoro-admin-tab]');
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

			panelRoot.querySelectorAll('[data-yoro-admin-panel]').forEach(function(section) {
				section.classList.toggle(
					'is-active',
					section.getAttribute('data-yoro-admin-panel') === selectedPanel
				);
			});

			relocateAdminPanelNotices(panelRoot);

			if (selectedPanel === 'settings') {
				hydrateMailboxSettings(panelRoot);
			}
		}

		function isValidAdminPanel(panel) {
			return ['history', 'safety', 'settings'].indexOf(panel) !== -1;
		}

		function getRequestedAdminPanel() {
			const params = new URLSearchParams(window.location.search);
			const requestedPanel = params.get('yoro_admin_panel')
				|| (adminPanelsSource ? adminPanelsSource.getAttribute('data-yoro-admin-initial-panel') : '');

			return isValidAdminPanel(requestedPanel) ? requestedPanel : '';
		}

		function relocateAdminPanelNotices(root) {
			const panelRoot = root || null;

			if (!panelRoot) {
				return;
			}

			panelRoot.querySelectorAll('[data-yoro-admin-notice-panel]').forEach(function(notice) {
				const targetPanel = notice.getAttribute('data-yoro-admin-notice-panel') || '';

				if (!isValidAdminPanel(targetPanel)) {
					return;
				}

				const targetSection = panelRoot.querySelector('[data-yoro-admin-panel="' + targetPanel + '"]');

				if (!targetSection || targetSection.contains(notice)) {
					return;
				}

				const targetWrap = targetSection.querySelector('.wrap') || targetSection;
				const heading = targetWrap.querySelector('h1');

				if (heading && heading.nextSibling) {
					targetWrap.insertBefore(notice, heading.nextSibling);
					return;
				}

				targetWrap.insertBefore(notice, targetWrap.firstChild);
			});
		}

		function normalizeAdminHistoryUrl(url) {
			let parsedUrl;

			try {
				parsedUrl = new URL(url, window.location.href);
			} catch (error) {
				return url;
			}

			if (
				parsedUrl.origin !== window.location.origin ||
				parsedUrl.searchParams.get('page') !== 'yoro-ai-core'
			) {
				return url;
			}

			parsedUrl.searchParams.set('yoro_admin_panel', 'history');

			return parsedUrl.toString();
		}

		function prepareAdminPanelNavigation(root) {
			const panelRoot = root || null;

			if (!panelRoot) {
				return;
			}

			panelRoot.querySelectorAll('[data-yoro-admin-panel="history"] form[method="get"]').forEach(function(form) {
				let panelInput = form.querySelector('input[name="yoro_admin_panel"]');

				if (!panelInput) {
					panelInput = document.createElement('input');
					panelInput.type = 'hidden';
					panelInput.name = 'yoro_admin_panel';
					form.appendChild(panelInput);
				}

				panelInput.value = 'history';
			});

			panelRoot.querySelectorAll('[data-yoro-admin-panel="history"] a[href]').forEach(function(link) {
				const currentHref = link.getAttribute('href') || '';
				const normalizedHref = normalizeAdminHistoryUrl(currentHref);

				if (normalizedHref !== currentHref) {
					link.setAttribute('href', normalizedHref);
				}
			});
		}

		function openAdminPanels(panel) {
			if (!adminPanelsSource || !chatShell) {
				return;
			}

			const body = adminCover.querySelector('.yoro-admin-cover-body');

			if (!body) {
				return;
			}

			body.innerHTML = adminPanelsSource.innerHTML;

			const adminPanelRoot = adminCover.querySelector('[data-yoro-admin-modal]');
			relocateAdminPanelNotices(adminPanelRoot);
			prepareAdminPanelNavigation(adminPanelRoot);
			activateAdminPanel(adminPanelRoot, panel || 'history');
			adminCover.classList.remove('hidden');

			if (adminPanelsButton) {
				adminPanelsButton.setAttribute('aria-expanded', 'true');
			}
		}

		function closeAdminPanels() {
			adminCover.classList.add('hidden');

			if (adminPanelsButton) {
				adminPanelsButton.setAttribute('aria-expanded', 'false');
			}
		}

		function registerAdminPanelCover() {
			if (adminPanelsButton && adminPanelsSource) {
				adminPanelsButton.setAttribute('aria-expanded', 'false');
				adminPanelsButton.addEventListener('click', function() {
					openAdminPanels(getRequestedAdminPanel() || 'history');
				});
			}

			adminCover.addEventListener('click', function(event) {
				if (event.target.closest('.yoro-admin-cover-close')) {
					event.preventDefault();
					closeAdminPanels();
				}
			});

			document.addEventListener('click', function(event) {
				const tab = event.target.closest('[data-yoro-admin-tab]');
				const historyLink = event.target.closest('[data-yoro-admin-panel="history"] a[href]');

				if (historyLink && adminCover.contains(historyLink)) {
					const href = historyLink.getAttribute('href') || '';
					const normalizedHref = normalizeAdminHistoryUrl(href);

					if (normalizedHref !== href) {
						event.preventDefault();
						window.location.href = normalizedHref;
					}

					return;
				}

				if (!tab || !adminCover.contains(tab)) {
					return;
				}

				activateAdminPanel(
					tab.closest('[data-yoro-admin-modal]'),
					tab.getAttribute('data-yoro-admin-tab') || 'history'
				);
			});

			document.addEventListener('keydown', function(event) {
				if (event.key === 'Escape' && !adminCover.classList.contains('hidden')) {
					closeAdminPanels();
				}
			});

			const requestedPanel = getRequestedAdminPanel();

			if (requestedPanel && adminPanelsSource) {
				window.setTimeout(function() {
					openAdminPanels(requestedPanel);
				}, 0);
			}
		}

		registerAdminPanelCover();

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
				// Ignore private browsing or blocked storage; YoRo remains the source of truth.
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

		applyAccountConnectionState(accountConnectionState);

		registerHistoryHandlers();
		registerPrimaryControls();
		registerQuickActions();
		registerAiApiStatus();
		registerMailboxInbox();
		registerMailboxSettings();

		function registerPrimaryControls() {
			if (accountGateSettings) {
				accountGateSettings.addEventListener('click', function () {
					openAdminPanels('settings');
				});
			}

			if (accountGate) {
				accountGate.addEventListener('click', function (event) {
					const target = event.target && event.target.closest
						? event.target.closest('[data-yoro-operator-access-check]')
						: null;

					if (!target || !accountGate.contains(target)) {
						return;
					}

					event.preventDefault();

					if (!window.YoRoAIAdmin || !YoRoAIAdmin.aiApiHandshakeUrl) {
						return;
					}

					accessHydrationPending = true;
					actorAccessState = normalizeActorAccess({
						status: 'checking',
						assigned: false
					});
					applyAccountConnectionState(
						accountConnectionState,
						actorAccessState,
						entitlementAccessState,
						usageAccessState
					);
					loadAiApiStatus(true);
				});
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
					resetConversationView();
				});
			}
		}

		function resetConversationView() {
			chatThread.innerHTML = '';
			addMessage(
				'assistant',
				'<p>New conversation started. What should YoRo handle next?</p>'
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
		}

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

		function updateJumpLatestOffset() {
			if (!jumpLatestButton || !chatComposer) {
				return;
			}

			jumpLatestButton.style.setProperty(
				'--yoro-chat-jump-bottom',
				(chatComposer.offsetHeight + 16) + 'px'
			);
		}

		function setJumpLatestVisible(isVisible) {
			latestJumpPending = !!isVisible;
			updateJumpLatestOffset();

			if (!jumpLatestButton) {
				return;
			}

			jumpLatestButton.hidden = !latestJumpPending;
			jumpLatestButton.classList.toggle('is-visible', latestJumpPending);
		}

		function isChatNearBottom() {
			return chatThread.scrollHeight - chatThread.scrollTop - chatThread.clientHeight <= 96;
		}

		function shouldAutoScrollForNewMessage(options) {
			const scrollOptions = options && typeof options === 'object' ? options : {};

			return scrollOptions.forceScroll === true || isChatNearBottom();
		}

		function markLatestAvailable() {
			setJumpLatestVisible(true);
		}

		function scrollToBottom(options) {
			const scrollOptions = options && typeof options === 'object' ? options : {};
			const targetTop = chatThread.scrollHeight;

			if (typeof chatThread.scrollTo === 'function') {
				chatThread.scrollTo({
					top: targetTop,
					behavior: scrollOptions.behavior || 'auto'
				});
			} else {
				chatThread.scrollTop = targetTop;
			}

			setJumpLatestVisible(false);
		}

		function getChatThreadTopOffset() {
			const computedStyle = window.getComputedStyle
				? window.getComputedStyle(chatThread)
				: null;
			const paddingTop = computedStyle ? parseFloat(computedStyle.paddingTop) || 0 : 0;
			const threadRect = chatThread.getBoundingClientRect();
			let overlayOffset = 0;

			[aiApiStatusRoot, quickActionsRoot].forEach(function (element) {
				if (!element || !element.getClientRects || !element.getClientRects().length) {
					return;
				}

				const rect = element.getBoundingClientRect();
				const coveredTop = rect.bottom - threadRect.top;

				if (coveredTop > overlayOffset) {
					overlayOffset = coveredTop;
				}
			});

			return Math.max(76, paddingTop + 12, overlayOffset + 16);
		}

		function scrollMessageToTop(wrapper, options) {
			if (!wrapper) {
				return;
			}

			const scrollOptions = options && typeof options === 'object' ? options : {};
			const targetRect = wrapper.getBoundingClientRect();
			const threadRect = chatThread.getBoundingClientRect();
			const topOffset = typeof scrollOptions.offset === 'number'
				? scrollOptions.offset
				: getChatThreadTopOffset();
			const targetTop = chatThread.scrollTop + targetRect.top - threadRect.top - topOffset;

			if (typeof chatThread.scrollTo === 'function') {
				chatThread.scrollTo({
					top: Math.max(0, targetTop),
					behavior: scrollOptions.behavior || 'smooth'
				});

				setJumpLatestVisible(false);
				return;
			}

			window.requestAnimationFrame(function () {
				chatThread.scrollTop = Math.max(0, targetTop);
				setJumpLatestVisible(false);
			});
		}

		function stabilizeScrollOnTarget(target) {
			if (!target) {
				return;
			}

			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () {
					scrollMessageToTop(target, { behavior: 'auto' });
				});
			});

			window.setTimeout(function () {
				scrollMessageToTop(target, { behavior: 'auto' });
			}, 180);
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

		let lastAssistantTextAnchor = null;
		let lastAssistantTypingCompletion = null;

		function getAssistantTypingRoot(wrapper, options) {
			const messageOptions = options && typeof options === 'object' ? options : {};

			if (!wrapper || messageOptions.hydrating || messageOptions.typing === false) {
				return null;
			}

			const bubble = wrapper.querySelector('.yoro-chat-bubble');

			if (!bubble) {
				return null;
			}

			return bubble;
		}

		function getNonTypingSelector() {
			return [
				'table',
				'.wp-list-table',
				'.yoro-queue-table-scroll',
				'.yoro-order-detail-card',
				'.yoro-order-detail-grid',
				'.yoro-report-card',
				'.yoro-workflow-card',
				'.yoro-result-notice',
				'.yoro-guided-result-section',
				'.yoro-assistant-fact-grid',
				'.yoro-friendly-action-card',
				'.yoro-context-summary',
				'.yoro-context-card',
				'.yoro-telemetry-summary',
				'.yoro-orders-table',
				'.yoro-queue-table',
				'.yoro-clarify-options',
				'.yoro-confirm-actions',
				'.yoro-result-debug',
				'.yoro-typing',
				'button',
				'input',
				'select',
				'textarea'
			].join(', ');
		}

		function getRichResultSelector() {
			return [
				'table',
				'.wp-list-table',
				'.yoro-queue-table-scroll',
				'.yoro-order-detail-card',
				'.yoro-order-detail-grid',
				'.yoro-report-card',
				'.yoro-report-section',
				'.yoro-workflow-card',
				'.yoro-workflow-step',
				'.yoro-plan-state-card',
				'.yoro-plan-state-panel',
				'.yoro-guided-result-section',
				'.yoro-assistant-fact-grid',
				'.yoro-friendly-action-card',
				'.yoro-context-summary',
				'.yoro-context-card',
				'.yoro-telemetry-summary',
				'.yoro-single-action-body',
				'.yoro-confirm-actions',
				'.yoro-clarify-options',
				'.yoro-result-notice'
			].join(', ');
		}

		function collectTypingTextNodes(root) {
			const nodes = [];

			if (!root || typeof document.createTreeWalker !== 'function') {
				return nodes;
			}

			const textFilter = window.NodeFilter || {
				SHOW_TEXT: 4,
				FILTER_ACCEPT: 1,
				FILTER_REJECT: 2
			};
			const nonTypingSelector = getNonTypingSelector();
			const simpleTextTags = ['P', 'UL', 'OL'];
			const directChildren = Array.prototype.slice.call(root.children);
			const isSimpleTextBubble = root.classList.contains('yoro-chat-bubble')
				&& directChildren.length
				&& directChildren.every(function (child) {
					return simpleTextTags.indexOf(child.tagName) !== -1;
				});
			const walker = document.createTreeWalker(
				root,
				textFilter.SHOW_TEXT,
				{
					acceptNode: function (node) {
						const parent = node.parentElement;
						const isText = node.nodeValue && node.nodeValue.trim().length;

						if (!isText || !parent || parent.closest(nonTypingSelector)) {
							return textFilter.FILTER_REJECT;
						}

						if (parent.closest('.yoro-assistant-natural-response, .yoro-assistant-message-result, .yoro-result-summary')) {
							return textFilter.FILTER_ACCEPT;
						}

						if (isSimpleTextBubble && parent.closest('p, li')) {
							return textFilter.FILTER_ACCEPT;
						}

						return textFilter.FILTER_REJECT;
					}
				}
			);
			let node = walker.nextNode();

			while (node) {
				nodes.push(node);
				node = walker.nextNode();
			}

			return nodes;
		}

		function collectRichResultElements(root, segments) {
			if (!root) {
				return [];
			}

			const richResultSelector = getRichResultSelector();
			const elements = Array.prototype.slice.call(root.querySelectorAll(richResultSelector));

			return elements.filter(function (element) {
				const richResultAncestor = element.parentElement
					? element.parentElement.closest(richResultSelector)
					: null;

				if (richResultAncestor && root.contains(richResultAncestor)) {
					return false;
				}

				return !segments.some(function (segment) {
					return segment.node && element.contains(segment.node);
				});
			});
		}

		function classifyAssistantRichMessage(wrapper) {
			const root = wrapper ? wrapper.querySelector('.yoro-chat-bubble') : null;

			if (!root) {
				return {
					hasRichElements: false
				};
			}

			const richElements = collectRichResultElements(root, []);

			if (!richElements.length) {
				return {
					hasRichElements: false
				};
			}

			richElements.forEach(function (element) {
				element.classList.add('yoro-rich-result-surface');
			});

			wrapper.classList.add('has-rich-result');
			root.classList.add('has-rich-result');

			const intro = root.querySelector('.yoro-assistant-natural-response, .yoro-assistant-message-result, .yoro-result-summary');

			if (intro) {
				intro.classList.add('yoro-rich-result-intro');
			}

			return {
				hasRichElements: true
			};
		}

		function getTypingCaretContainer(root, segments) {
			const lastSegment = segments[segments.length - 1];
			const parent = lastSegment && lastSegment.node ? lastSegment.node.parentElement : null;

			if (parent && root.contains(parent)) {
				return parent;
			}

			const listText = root.querySelector('li:last-child > span:last-child');

			if (listText) {
				return listText;
			}

			const paragraphs = root.querySelectorAll('p');

			return paragraphs.length ? paragraphs[paragraphs.length - 1] : root;
		}

		function getTypingScrollTarget(root, segments) {
			const firstSegment = segments[0];
			const parent = firstSegment && firstSegment.node ? firstSegment.node.parentElement : null;

			if (!parent || !root.contains(parent)) {
				return root;
			}

			return parent.closest('.yoro-assistant-natural-response, .yoro-assistant-message-result, .yoro-result-summary, p, li') || parent;
		}

		function getPreviousAssistantMessage(wrapper) {
			let node = wrapper && wrapper.previousElementSibling ? wrapper.previousElementSibling : null;

			while (node) {
				if (
					node.classList
					&& node.classList.contains('yoro-chat-message-assistant')
				) {
					return node;
				}

				if (
					node.classList
					&& node.classList.contains('yoro-chat-message-user')
				) {
					return null;
				}

				node = node.previousElementSibling;
			}

			return null;
		}

		function getPreviousAssistantTextAnchor(wrapper) {
			if (
				!lastAssistantTextAnchor
				|| !lastAssistantTextAnchor.wrapper
				|| !lastAssistantTextAnchor.scrollTarget
			) {
				return null;
			}

			return lastAssistantTextAnchor.wrapper === getPreviousAssistantMessage(wrapper)
				? lastAssistantTextAnchor.scrollTarget
				: null;
		}

		function rememberAssistantTypingCompletion(wrapper, typingState) {
			if (
				!wrapper
				|| !typingState
				|| !typingState.started
				|| !typingState.completion
			) {
				return;
			}

			const typingCompletion = {
				wrapper: wrapper,
				promise: typingState.completion,
				done: false
			};

			lastAssistantTypingCompletion = typingCompletion;

			typingState.completion.then(function () {
				typingCompletion.done = true;
			});
		}

		function getPreviousAssistantTypingCompletion(wrapper) {
			if (
				!lastAssistantTypingCompletion
				|| lastAssistantTypingCompletion.done
				|| !lastAssistantTypingCompletion.wrapper
				|| !lastAssistantTypingCompletion.promise
			) {
				return null;
			}

			return lastAssistantTypingCompletion.wrapper === getPreviousAssistantMessage(wrapper)
				? lastAssistantTypingCompletion.promise
				: null;
		}

		function runAssistantTypingEffect(wrapper, options) {
			const root = getAssistantTypingRoot(wrapper, options);

			if (!root) {
				return {
					started: false,
					hasRichElements: false,
					scrollTarget: null,
					completion: Promise.resolve()
				};
			}

			const segments = collectTypingTextNodes(root).map(function (node) {
				return {
					node,
					text: node.nodeValue
				};
			}).filter(function (segment) {
				return segment.text.length > 0;
			});
			const totalChars = segments.reduce(function (total, segment) {
				return total + segment.text.length;
			}, 0);
			const richElements = collectRichResultElements(root, segments);

			if (!totalChars) {
				const previousTextAnchor = richElements.length ? getPreviousAssistantTextAnchor(wrapper) : null;

				return {
					started: false,
					hasRichElements: richElements.length > 0,
					scrollTarget: previousTextAnchor,
					completion: Promise.resolve()
				};
			}

			const scrollTarget = getTypingScrollTarget(root, segments);
			const previousTextAnchor = richElements.length ? getPreviousAssistantTextAnchor(wrapper) : null;

			if (richElements.length) {
				return {
					started: false,
					hasRichElements: true,
					scrollTarget: previousTextAnchor || scrollTarget,
					completion: Promise.resolve()
				};
			}

			lastAssistantTextAnchor = {
				wrapper: wrapper,
				scrollTarget: scrollTarget
			};

			if (
				window.matchMedia
				&& window.matchMedia('(prefers-reduced-motion: reduce)').matches
			) {
				return {
					started: false,
					hasRichElements: false,
					scrollTarget: scrollTarget,
					completion: Promise.resolve()
				};
			}

			segments.forEach(function (segment) {
				segment.node.nodeValue = '';
			});

			const caret = document.createElement('span');
			caret.className = 'yoro-typing-caret';
			caret.setAttribute('aria-hidden', 'true');
			getTypingCaretContainer(root, segments).appendChild(caret);
			root.classList.add('is-typing-response');

			let segmentIndex = 0;
			let offset = 0;
			let resolveTypingCompletion = function () {};
			const typingCompletion = new Promise(function (resolve) {
				resolveTypingCompletion = resolve;
			});

			function finishTyping() {
				segments.forEach(function (segment) {
					segment.node.nodeValue = segment.text;
				});
				if (caret.parentNode) {
					caret.parentNode.removeChild(caret);
				}
				root.classList.remove('is-typing-response');
				resolveTypingCompletion();
			}

			function getCurrentTypingChar() {
				const segment = segments[segmentIndex];

				return segment ? segment.text.charAt(offset) : '';
			}

			function getNaturalTypingBatchSize(nextChar) {
				if (!nextChar) {
					return 1;
				}

				if (/[\s.,!?;:]/.test(nextChar)) {
					return 1;
				}

				const random = Math.random();

				if (totalChars > 320) {
					return random < 0.45 ? 3 : 2;
				}

				if (totalChars > 140) {
					return random < 0.38 ? 2 : 1;
				}

				return random < 0.08 ? 2 : 1;
			}

			function getNaturalTypingDelay(lastChar) {
				const baseDelay = totalChars > 320 ? 15 : (totalChars > 140 ? 20 : 27);

				if (!lastChar) {
					return 90 + Math.floor(Math.random() * 60);
				}

				if (/[\r\n]/.test(lastChar)) {
					return baseDelay + 190 + Math.floor(Math.random() * 90);
				}

				if (/[.!?]/.test(lastChar)) {
					return baseDelay + 165 + Math.floor(Math.random() * 115);
				}

				if (/[,;:]/.test(lastChar)) {
					return baseDelay + 75 + Math.floor(Math.random() * 70);
				}

				if (/\s/.test(lastChar)) {
					const occasionalPause = Math.random() < 0.06
						? 85 + Math.floor(Math.random() * 80)
						: 0;

					return baseDelay + 18 + Math.floor(Math.random() * 40) + occasionalPause;
				}

				return Math.max(12, baseDelay - 8 + Math.floor(Math.random() * 22));
			}

			function revealTypingBatch(batchSize) {
				let remaining = batchSize;
				let lastChar = '';

				while (remaining > 0 && segmentIndex < segments.length) {
					const segment = segments[segmentIndex];
					const charsToReveal = Math.min(segment.text.length - offset, remaining);
					const nextOffset = offset + charsToReveal;

					segment.node.nodeValue = segment.text.slice(0, nextOffset);
					lastChar = segment.text.charAt(nextOffset - 1) || lastChar;
					remaining -= charsToReveal;
					offset = nextOffset;

					if (offset >= segment.text.length) {
						segmentIndex += 1;
						offset = 0;
					}
				}

				return lastChar;
			}

			function tick() {
				const lastChar = revealTypingBatch(
					getNaturalTypingBatchSize(getCurrentTypingChar())
				);

				if (segmentIndex >= segments.length) {
					finishTyping();
					return;
				}

				window.setTimeout(tick, getNaturalTypingDelay(lastChar));
			}

			window.setTimeout(tick, getNaturalTypingDelay(''));

			return {
				started: true,
				hasRichElements: richElements.length > 0,
				scrollTarget: scrollTarget,
				completion: typingCompletion
			};
		}

		function revealAssistantRichMessage(wrapper, typingState, options) {
			const messageOptions = options && typeof options === 'object' ? options : {};
			const richScrollTarget = typingState && typingState.scrollTarget
				? typingState.scrollTarget
				: wrapper;

			wrapper.classList.remove('is-awaiting-previous-typing');

			startAssistantRichEnterAnimation(wrapper, messageOptions);

			if (messageOptions.scroll === false) {
				return;
			}

			if (shouldAutoScrollForNewMessage(messageOptions)) {
				scrollMessageToTop(
					richScrollTarget,
					{ behavior: 'auto' }
				);

				stabilizeScrollOnTarget(richScrollTarget);
				return;
			}

			markLatestAvailable();
		}

		function startAssistantRichEnterAnimation(wrapper, options) {
			const messageOptions = options && typeof options === 'object' ? options : {};

			if (!wrapper || messageOptions.hydrating) {
				return;
			}

			wrapper.classList.add('is-rich-result-entering');

			window.setTimeout(function () {
				wrapper.classList.remove('is-rich-result-entering');
			}, 220);
		}

		function addMessage(type, html, options) {
			const isUser = type === 'user';
			const messageOptions = options && typeof options === 'object' ? options : {};
			const messageHtml = isUser ? String(html || '') : stripAssistantNameHeader(html);
			const shouldAutoScroll = messageOptions.scroll !== false
				&& shouldAutoScrollForNewMessage(messageOptions);

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

			const richMessageState = !isUser
				? classifyAssistantRichMessage(wrapper)
				: { hasRichElements: false };

			const typingState = !isUser
				? runAssistantTypingEffect(wrapper, messageOptions)
				: null;

			if (!isUser) {
				rememberAssistantTypingCompletion(wrapper, typingState);
			}

			const previousTypingCompletion = (
				!isUser
				&& richMessageState.hasRichElements
				&& !messageOptions.hydrating
				&& messageOptions.waitForPreviousTyping !== false
			)
				? getPreviousAssistantTypingCompletion(wrapper)
				: null;

			if (previousTypingCompletion) {
				wrapper.classList.add('is-awaiting-previous-typing');
				previousTypingCompletion.then(function () {
					revealAssistantRichMessage(wrapper, typingState, messageOptions);
				});

				return wrapper;
			}

			if (messageOptions.scroll === false) {
				return wrapper;
			}

			if (isUser) {
				lastAssistantTextAnchor = null;
				lastAssistantTypingCompletion = null;
				if (shouldAutoScroll) {
					scrollToBottom();
				} else {
					markLatestAvailable();
				}
			} else if (typingState && typingState.hasRichElements) {
				revealAssistantRichMessage(wrapper, typingState, messageOptions);
			} else {
				if (shouldAutoScroll) {
					scrollMessageToTop(wrapper);
				} else {
					markLatestAvailable();
				}
			}

			return wrapper;
		}

		function addUserMessage(command) {
			addMessage('user', '<p>' + escapeHtml(command) + '</p>');
		}

		let resultUI = null;

		function mailboxReplyPreviewPayload(data) {
			const result = data && data.result ? data.result : {};
			const parsed = data && data.parsed ? data.parsed : {};
			const actionPlan = data && data.action_plan
				? data.action_plan
				: (parsed.action_plan || result.action_plan || {});

			return result.email_preview || data.email_preview || parsed.email_preview || actionPlan.email_preview || {};
		}

		function isMailboxReplyPreviewData(data) {
			if (isTerminalPendingResponse(data)) {
				return false;
			}

			const result = data && data.result ? data.result : {};
			const parsed = data && data.parsed ? data.parsed : {};
			const preview = mailboxReplyPreviewPayload(data);
			const displayType = String(result.display_type || (data && data.display_type) || '');
			const action = String(result.action || (data && data.action) || (parsed && parsed.action) || preview.action || '');
			const sendAction = String(preview.send_action || result.send_action || '');

			return displayType === 'customer_email_preview' && (
				preview.kind === 'mailbox_reply' ||
				action === 'email.reply.send' ||
				sendAction === 'email.reply.send'
			);
		}

		function isTerminalPendingResponse(data) {
			const result = data && data.result ? data.result : {};
			const parsed = data && data.parsed ? data.parsed : {};
			const meta = parsed.meta || result.meta || (data && data.meta) || {};
			const stage = String((data && data.stage) || result.stage || '').toLowerCase();
			const pendingStatus = String(
				(data && data.pending_operation_status)
				|| result.pending_operation_status
				|| meta.pending_operation_status
				|| ''
			).toLowerCase();

			return (data && data.pending_operation_active === false)
				|| result.pending_operation_active === false
				|| ['done', 'executed', 'cancelled', 'failed', 'error'].indexOf(stage) !== -1
				|| ['executed', 'cancelled', 'failed'].indexOf(pendingStatus) !== -1;
		}

		function mailboxReplyPreviewIntroText(data) {
			const parsed = data && data.parsed ? data.parsed : {};
			const result = data && data.result ? data.result : {};
			const assistant = (data && data.assistant) || parsed.assistant || result.assistant || {};

			return String(assistant && assistant.message ? assistant.message : '').trim();
		}

		function withoutAssistantIntro(data) {
			const clone = JSON.parse(JSON.stringify(data || {}));

			[clone, clone.parsed || {}, clone.result || {}].forEach(function(container) {
				if (container.assistant && typeof container.assistant === 'object') {
					container.assistant.message = '';
					container.assistant.question = '';
				}
			});

			return clone;
		}

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
			const shouldSplitMailboxReplyPreview = isMailboxReplyPreviewData(data);
			const mailboxPreviewIntro = shouldSplitMailboxReplyPreview ? mailboxReplyPreviewIntroText(data) : '';
			const renderData = mailboxPreviewIntro ? withoutAssistantIntro(data) : data;

			if (mailboxPreviewIntro) {
				addMessage(
					'assistant',
					resultUI && typeof resultUI.renderAssistantText === 'function'
						? resultUI.renderAssistantText(stripAssistantNameTextPrefix(mailboxPreviewIntro))
						: '<p>' + escapeHtml(stripAssistantNameTextPrefix(mailboxPreviewIntro)) + '</p>',
					Object.assign({}, messageOptions, { scroll: false })
				);
			}

			const wrapper = addMessage(
				'assistant',
				resultUI
					? resultUI.renderResult(renderData)
					: renderJsonFallback(renderData),
				messageOptions
			);
			const result = renderData && renderData.result ? renderData.result : {};
			const displayType = String(result.display_type || '');

			if (displayType) {
				wrapper.setAttribute('data-yoro-display-type', displayType);
			}

			hideSupersededAssistantMessages(wrapper, renderData);
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
			if (!isYoRoAccessActive()) {
				return;
			}

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

		function addLoadingMessage(context) {
			const shouldAutoScroll = shouldAutoScrollForNewMessage(context);
			const wrapper = addMessage(
				'assistant',
				'<div class="yoro-typing"><span></span><span></span><span></span></div>',
				{
					scroll: false,
					typing: false
				}
			);

			if (shouldAutoScroll) {
				scrollToBottom();
			} else {
				markLatestAvailable();
			}

			return wrapper;
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
			runButton.disabled = !isYoRoAccessActive() || isSending || commandField.value.trim() === '';
		}

		function normalizeAccountConnection(connection) {
			connection = connection && typeof connection === 'object' ? connection : {};

			return {
				required: connection.required !== false,
				connected: connection.connected === true,
				status: String(connection.status || (connection.connected ? 'active' : 'account_required')),
				message: String(connection.message || ''),
				service_available: connection.service_available !== false
			};
		}

		function isAccountConnectionActive() {
			return accountConnectionState.connected === true && accountConnectionState.status === 'active';
		}

		function isYoRoAccessChecking() {
			return accessHydrationPending
				&& isAccountConnectionActive()
				&& actorAccessState.status === 'checking';
		}

		function normalizeActorAccess(access) {
			access = access && typeof access === 'object' ? access : {};

			return {
				required: access.required !== false,
				assigned: access.assigned === true,
				status: String(access.status || 'missing'),
				message: String(access.message || ''),
				role: String(access.role || ''),
				permissions: Array.isArray(access.permissions) ? access.permissions.map(String) : []
			};
		}

		function isYoRoAccessActive() {
			const subscriptionActive = entitlementAccessState.access_state !== 'blocked';
			const hasCredits = usageAccessState.hard_limit_reached !== true;
			const canUseAi = actorAccessState.permissions.indexOf('yoro_use_ai') !== -1;

			return isAccountConnectionActive()
				&& actorAccessState.assigned === true
				&& actorAccessState.status === 'active'
				&& canUseAi
				&& subscriptionActive
				&& hasCredits;
		}

		function requireAccountConnection() {
			if (isYoRoAccessActive()) {
				return true;
			}

			if (accountGate) {
				accountGate.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}

			return false;
		}

		function applyAccountConnectionState(connection, actorAccess, entitlements, usage) {
			accountConnectionState = normalizeAccountConnection(connection);
			if (actorAccess !== undefined) {
				actorAccessState = normalizeActorAccess(actorAccess);
			}
			if (entitlements && typeof entitlements === 'object') {
				entitlementAccessState = entitlements;
			}
			if (usage && typeof usage === 'object') {
				usageAccessState = usage;
			}
			const accessible = isYoRoAccessActive();
			const checking = isYoRoAccessChecking();
			const gateHidden = accessible || checking;

			if (accountGate) {
				accountGate.hidden = gateHidden;
				accountGate.classList.toggle('is-hidden', gateHidden);
			}
			renderAccountAccessGate();

			if (chatShell) {
				chatShell.classList.toggle('is-account-locked', !accessible && !checking);
				chatShell.classList.toggle('is-account-checking', checking);
				chatShell.setAttribute('aria-busy', checking ? 'true' : 'false');
			}

			commandField.disabled = !accessible;
			commandField.setAttribute('aria-disabled', accessible ? 'false' : 'true');
			contextButton && (contextButton.disabled = !accessible || isSending);
			clearButton && (clearButton.disabled = !accessible || isSending);
			inboxButton && (inboxButton.disabled = !accessible || isSending);
			quickActionsToggle && (quickActionsToggle.disabled = !accessible || isSending);

			workflowButtons.forEach(function(button) {
				button.disabled = !accessible || isSending;
			});

			syncRunButtonState();
		}

		function renderAccountAccessGate() {
			if (!accountGateEyebrow || !accountGateTitle || !accountGateDescription || !accountGateAction) {
				return;
			}

			let eyebrow = 'Account connection required';
			let title = 'Connect YoRo before opening the AI workspace';
			let description = 'Link this store to your YoRo account and Organization before YoRo can read or act on store data.';
			let action = accountGateDefaultAction;

			if (isAccountConnectionActive() && entitlementAccessState.access_state === 'blocked') {
				eyebrow = 'Subscription action required';
				title = 'Reactivate your YoRo plan';
				description = 'This Organization subscription is inactive. Renew or select a plan to reopen the AI workspace.';
				action = accessGateLink((YoRoAIAdmin && YoRoAIAdmin.aiApiBillingUrl) || '', 'Open billing');
			} else if (isAccountConnectionActive() && usageAccessState.hard_limit_reached === true) {
				eyebrow = 'Monthly AI credits used';
				title = 'Add credits or upgrade your plan';
				description = 'The Organization has reached its current AI Agent credit limit. Store data remains unchanged.';
				action = accessGateLink((YoRoAIAdmin && YoRoAIAdmin.aiApiBillingUrl) || '', 'Billing / Upgrade');
			} else if (isAccountConnectionActive() && actorAccessState.status === 'checking') {
				eyebrow = 'Verifying operator access';
				title = 'Checking your YoRo seat';
				description = 'YoRo is securely matching this WordPress user to the Organization access list.';
				action = '<p>Please wait a moment.</p>';
			} else if (isAccountConnectionActive() && actorAccessState.status === 'unavailable') {
				eyebrow = 'Access check unavailable';
				title = 'YoRo access could not be verified';
				description = actorAccessState.message || 'YoRo could not verify this WordPress user right now.';
				action = accessGateCheckAction('Try again', 'YoRo will verify your current WordPress account.');
			} else if (isAccountConnectionActive() && actorAccessState.status === 'seat_required') {
				eyebrow = 'Access request sent';
				title = 'Waiting for Organization approval';
				description = 'Your current WordPress account has requested YoRo access. An Organization Owner or Admin must assign you an operator seat before you can continue.';
				action = accessGateCheckAction('Check access', 'No separate YoRo sign-in is required.');
			} else if (isAccountConnectionActive() && actorAccessState.status === 'revoked') {
				eyebrow = 'Operator access revoked';
				title = 'Ask an Organization Owner or Admin to restore access';
				description = 'Your WordPress account is recognized, but its YoRo operator seat is no longer active.';
				action = accessGateCheckAction('Check access', 'Use this after an Owner or Admin restores your seat.');
			} else if (isAccountConnectionActive() && !isYoRoAccessActive()) {
				eyebrow = 'Operator access required';
				title = 'Request access with your WordPress account';
				description = 'YoRo will identify this signed-in WordPress user and send an access request to the connected Organization.';
				action = accessGateCheckAction('Request access', 'You do not need a YoRo account or a separate password.');
			}

			accountGateEyebrow.textContent = eyebrow;
			accountGateTitle.textContent = title;
			accountGateDescription.textContent = description;
			accountGateAction.innerHTML = action;
		}

		function accessGateLink(url, label) {
			if (!url) {
				return '<p>Ask your Organization owner or administrator for access.</p>';
			}

			return '<a class="button button-primary yoro-account-gate-button" href="' + escapeHtml(url) + '">' + escapeHtml(label) + '</a>';
		}

		function accessGateCheckAction(label, note) {
			return '<button type="button" class="button button-primary yoro-account-gate-button" data-yoro-operator-access-check>'
				+ escapeHtml(label)
				+ '</button><p class="yoro-account-gate-action-note">'
				+ escapeHtml(note)
				+ '</p>';
		}

		function setButtonLabel(button, label) {
			if (!button) {
				return;
			}

			const labelNode = button.querySelector('span:not(.dashicons)');

			if (labelNode) {
				labelNode.textContent = label;
				return;
			}

			button.textContent = label;
		}

		function setLoading(isLoading) {
			syncRunButtonState();
			const accountLocked = !isYoRoAccessActive();

			if (contextButton) {
				contextButton.disabled = accountLocked || isLoading;
			}

			if (adminPanelsButton) {
				adminPanelsButton.disabled = isLoading;
			}

			if (inboxButton) {
				inboxButton.disabled = accountLocked || isLoading;
			}

			if (clearButton) {
				clearButton.disabled = accountLocked || isLoading;
			}

			if (quickActionsToggle) {
				quickActionsToggle.disabled = accountLocked || isLoading;
			}

			workflowButtons.forEach(function(button) {
				button.disabled = accountLocked || isLoading;
			});

			setButtonLabel(runButton, isLoading ? YoRoAIAdmin.i18n.running : (YoRoAIAdmin.i18n.askYoRo || 'Send'));
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

		function registerMailboxInbox() {
			if (!window.YoRoAIAdmin || !YoRoAIAdmin.emailInboxUrl) {
				return;
			}

			if (inboxButton) {
				inboxButton.addEventListener('click', function () {
					openQuickInboxPanel();
				});
			}

			handleMailboxCallbackNotice();
		}

		function registerMailboxSettings() {
			if (!window.YoRoAIAdmin || !YoRoAIAdmin.emailInboxUrl) {
				return;
			}

			document.addEventListener('click', function (event) {
				const target = event.target.closest(
					'.yoro-mailbox-settings-connect,' +
					'.yoro-mailbox-imap-submit,' +
					'.yoro-mailbox-settings-disconnect,' +
					'.yoro-mailbox-settings-sync,' +
					'.yoro-mailbox-settings-inbox-sync,' +
					'.yoro-mailbox-settings-refresh,' +
					'.yoro-mailbox-settings-open,' +
					'.yoro-mailbox-settings-inbox-back,' +
					'.yoro-mailbox-settings-back,' +
					'.yoro-mailbox-thread-open,' +
					'.yoro-mailbox-status-save,' +
					'.yoro-mailbox-assignee-save,' +
					'.yoro-mailbox-assignee-mine,' +
					'.yoro-mailbox-assignee-clear,' +
					'.yoro-mailbox-note-save,' +
					'.yoro-mailbox-mark-read,' +
					'.yoro-mailbox-mark-unread,' +
					'.yoro-mailbox-archive,' +
					'.yoro-mailbox-reopen,' +
					'.yoro-mailbox-retry,' +
					'.yoro-mailbox-draft-reply,' +
					'.yoro-mailbox-reply-suggestion,' +
					'.yoro-mailbox-follow-up-set,' +
					'.yoro-mailbox-follow-up-clear,' +
					'.yoro-mailbox-send-reply'
				);

				if (!target) {
					return;
				}

				const settingsRoot = target.closest('[data-yoro-mailbox-settings]');

				if (!settingsRoot) {
					return;
				}

				event.preventDefault();

				if (target.classList.contains('yoro-mailbox-settings-connect')) {
					startMailboxProviderConnect(target.getAttribute('data-yoro-mailbox-provider') || 'gmail', {
						button: target,
						settingsRoot: settingsRoot
					});
					return;
				}

				if (target.classList.contains('yoro-mailbox-imap-submit')) {
					connectImapMailbox(target, {
						settingsRoot: settingsRoot
					});
					return;
				}

				if (target.classList.contains('yoro-mailbox-settings-open')) {
					openMailboxFromSettings(settingsRoot);
					return;
				}

				if (target.classList.contains('yoro-mailbox-settings-inbox-sync')) {
					loadMailboxSettingsInbox(settingsRoot, true);
					return;
				}

				if (target.classList.contains('yoro-mailbox-settings-back')) {
					loadMailboxSettings(settingsRoot, false);
					return;
				}

				if (target.classList.contains('yoro-mailbox-settings-inbox-back')) {
					loadMailboxSettingsInbox(settingsRoot, false);
					return;
				}

				if (target.classList.contains('yoro-mailbox-thread-open')) {
					loadMailboxSettingsThread(settingsRoot, target.getAttribute('data-thread-id') || '');
					return;
				}

				if (target.classList.contains('yoro-mailbox-status-save')) {
					saveMailboxThreadStatus(target);
					return;
				}

				if (target.classList.contains('yoro-mailbox-assignee-save')) {
					saveMailboxThreadAssignee(target, 'form');
					return;
				}

				if (target.classList.contains('yoro-mailbox-assignee-mine')) {
					saveMailboxThreadAssignee(target, 'current');
					return;
				}

				if (target.classList.contains('yoro-mailbox-assignee-clear')) {
					saveMailboxThreadAssignee(target, 'clear');
					return;
				}

				if (target.classList.contains('yoro-mailbox-note-save')) {
					addMailboxThreadNote(target);
					return;
				}

				if (target.classList.contains('yoro-mailbox-mark-read')) {
					runMailboxThreadCommand(target, 'read');
					return;
				}

				if (target.classList.contains('yoro-mailbox-mark-unread')) {
					runMailboxThreadCommand(target, 'unread');
					return;
				}

				if (target.classList.contains('yoro-mailbox-archive')) {
					runMailboxThreadCommand(target, 'archive');
					return;
				}

				if (target.classList.contains('yoro-mailbox-reopen')) {
					runMailboxThreadCommand(target, 'reopen');
					return;
				}

				if (target.classList.contains('yoro-mailbox-retry')) {
					retryMailboxThreadReply(target);
					return;
				}

				if (target.classList.contains('yoro-mailbox-draft-reply')) {
					askYoroToDraftMailboxReply(target, {
						settingsRoot: settingsRoot
					});
					return;
				}

				if (target.classList.contains('yoro-mailbox-reply-suggestion')) {
					applyMailboxReplySuggestion(target, {
						settingsRoot: settingsRoot
					});
					return;
				}

				if (target.classList.contains('yoro-mailbox-follow-up-set')) {
					saveMailboxThreadFollowUp(target, 'set');
					return;
				}

				if (target.classList.contains('yoro-mailbox-follow-up-clear')) {
					saveMailboxThreadFollowUp(target, 'clear');
					return;
				}

				if (target.classList.contains('yoro-mailbox-send-reply')) {
					sendMailboxThreadReply(target, {
						settingsRoot: settingsRoot
					});
					return;
				}

				if (target.classList.contains('yoro-mailbox-settings-disconnect')) {
					disconnectMailboxConnection(settingsRoot);
					return;
				}

				loadMailboxSettings(settingsRoot, target.classList.contains('yoro-mailbox-settings-sync'));
			});
		}

		function hydrateMailboxSettings(root) {
			const panelRoot = root || document;

			panelRoot.querySelectorAll('[data-yoro-mailbox-settings]').forEach(function (settingsRoot) {
				if (settingsRoot.dataset.yoroMailboxHydrated === '1') {
					return;
				}

				settingsRoot.dataset.yoroMailboxHydrated = '1';
				loadMailboxSettings(settingsRoot, false);
			});
		}

		function handleMailboxCallbackNotice() {
			const params = new URLSearchParams(window.location.search);
			const result = params.get('yoro_email') || '';

			if (!result) {
				return;
			}

			if (result === 'success') {
				if (getRequestedAdminPanel() === 'settings') {
					return;
				}

				openQuickInboxPanel();
				return;
			}

			if (openQuickInboxPanel()) {
				renderQuickInboxError({
					message: params.get('error') || 'The shop inbox could not be connected.'
				});
			}
		}

		function mailboxErrorMessage(error, fallback) {
			if (error && typeof error.message === 'string' && error.message) {
				return error.message;
			}

			if (error && error.data && typeof error.data.message === 'string' && error.data.message) {
				return error.data.message;
			}

			return fallback || 'The shop inbox request failed.';
		}

		function addMailboxErrorMessage(error, fallback) {
			addMessage(
				'assistant',
				'<div class="yoro-result-notice yoro-result-error"><strong>Inbox unavailable</strong><p>' +
				escapeHtml(mailboxErrorMessage(error, fallback)) +
				'</p></div>'
			);
		}

		function mailboxThreadUrl(threadId, suffix) {
			const base = String(YoRoAIAdmin.emailThreadBaseUrl || '').replace(/\/+$/, '');

			return base + '/' + encodeURIComponent(String(threadId || '')) + (suffix || '');
		}

		function formatMailboxDateTime(value) {
			const raw = String(value || '');
			const timestamp = Date.parse(raw);

			if (!Number.isFinite(timestamp)) {
				return raw;
			}

			try {
				return browserDateTimeFormatter.format(new Date(timestamp));
			} catch (error) {
				return raw;
			}
		}

		function mailboxAddressLabel(address) {
			address = address || {};

			const name = String(address.name || '').trim();
			const email = String(address.email || '').trim();

			if (name && email) {
				return name + ' <' + email + '>';
			}

			return name || email;
		}

		function mailboxParticipantsLabel(thread) {
			const participants = Array.isArray(thread && thread.participants) ? thread.participants : [];
			const labels = participants.map(mailboxAddressLabel).filter(Boolean);

			return labels.slice(0, 3).join(', ');
		}

		function mailboxWorkflowStatuses(data) {
			const statuses = Array.isArray(data && data.workflow_statuses) ? data.workflow_statuses : [];

			if (statuses.length) {
				return statuses;
			}

			return [
				{ value: 'open', label: 'Open' },
				{ value: 'waiting_shop', label: 'Waiting shop' },
				{ value: 'waiting_customer', label: 'Waiting customer' },
				{ value: 'resolved', label: 'Resolved' },
				{ value: 'ignored', label: 'Ignored / spam' },
				{ value: 'archived', label: 'Archived' }
			];
		}

		function mailboxStatusLabel(data, value) {
			const normalized = String(value || '').trim();
			const match = mailboxWorkflowStatuses(data).find(function(status) {
				return String(status.value || '') === normalized;
			});

			return match ? String(match.label || match.value || '') : normalized;
		}

		function mailboxPersonLabel(person, fallback) {
			person = person || {};

			const name = String(person.name || '').trim();
			const email = String(person.email || '').trim();

			if (name && email) {
				return name + ' <' + email + '>';
			}

			return name || email || fallback || '';
		}

		function renderMailboxThreadBadges(data, prefix) {
			const thread = data && data.thread ? data.thread : {};
			const badges = [];
			const status = String(thread.workflow_status || thread.status || '').trim();
			const assignee = mailboxPersonLabel(thread.assigned_to, '');

			if (status) {
				badges.push(mailboxStatusLabel(data, status));
			}

			if (thread.unread) {
				badges.push('Unread');
			}

			if (assignee) {
				badges.push('Owner: ' + assignee);
			}

			if (!badges.length) {
				return '';
			}

			return '<span class="yoro-mailbox-thread-badges ' + escapeHtml(prefix || '') + '-badges">' +
				badges.map(function(label) {
					return '<em>' + escapeHtml(label) + '</em>';
				}).join('') +
				'</span>';
		}

		function mailboxThreadIntelligence(data) {
			data = data || {};

			if (data.intelligence && typeof data.intelligence === 'object') {
				return data.intelligence;
			}

			if (data.thread && data.thread.intelligence && typeof data.thread.intelligence === 'object') {
				return data.thread.intelligence;
			}

			return {};
		}

		function renderMailboxThreadIntelligence(data, prefix) {
			const thread = data && data.thread ? data.thread : {};
			const intelligence = mailboxThreadIntelligence(data);

			if (!intelligence || !Object.keys(intelligence).length) {
				return '';
			}

			const classification = intelligence.classification || {};
			const priority = intelligence.priority || {};
			const contextPanel = intelligence.context_panel || {};
			const commerce = intelligence.commerce_context || {};
			const product = intelligence.product_context || {};
			const suggestions = Array.isArray(intelligence.reply_suggestions) ? intelligence.reply_suggestions : [];
			const followUp = intelligence.follow_up || {};
			const bucketLabel = classification.label || (contextPanel.classification && contextPanel.classification.label) || mailboxClassificationLabel(classification.bucket || classification.category || '');
			const priorityLabel = priority.level ? mailboxPriorityLabel(priority.level) : mailboxPriorityLabel(classification.priority || 'normal');
			const priorityScore = typeof priority.score === 'number' ? ' · ' + priority.score + '/100' : '';
			let html = '<div class="yoro-mailbox-thread-section yoro-mailbox-intelligence">';

			html += '<div class="yoro-mailbox-thread-section-head">';
			html += '<strong>AI triage</strong>';
			html += '<span class="yoro-mailbox-thread-section-tags">';
			html += '<em>' + escapeHtml(bucketLabel) + '</em>';
			html += '<em>' + escapeHtml(priorityLabel + priorityScore) + '</em>';
			html += '</span>';
			html += '</div>';
			html += '<div class="yoro-mailbox-intelligence-layout">';
			html += '<div class="yoro-mailbox-intelligence-main">';
			html += '<dl class="yoro-mailbox-context-list">';
			html += renderMailboxContextItem('Handling', mailboxHandlingLabel(classification.suggested_handling || 'manual_review'));
			html += renderMailboxContextItem('Customer', mailboxCustomerContextLabel(commerce.customer || contextPanel.customer || {}));
			html += renderMailboxContextItem('Order', mailboxOrderContextLabel(commerce.order || contextPanel.order || {}, commerce.order_match || contextPanel.order_match || {}));
			html += renderMailboxContextItem('Product', mailboxProductContextLabel(product.products || contextPanel.products || [], product.match || contextPanel.product_match || {}));
			html += renderMailboxContextItem('Language', mailboxLanguageLabel(intelligence.reply_language || {}));
			html += '</dl>';
			html += '</div>';
			html += '<div class="yoro-mailbox-intelligence-aside">';

			if (suggestions.length) {
				html += '<div class="yoro-mailbox-suggestions">';
				html += '<span class="yoro-mailbox-workflow-label">Smart replies</span>';
				html += '<div>';
				suggestions.forEach(function(suggestion) {
					const label = suggestion.label || suggestion.id || 'Draft reply';
					const instruction = suggestion.instruction || label;
					html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-reply-suggestion" data-thread-id="' + escapeHtml(thread.id || '') + '" data-reply-instruction="' + escapeHtml(instruction) + '"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span><span>' + escapeHtml(label) + '</span></button>';
				});
				html += '</div>';
				html += '</div>';
			}

			html += renderMailboxFollowUpControl(followUp, thread, prefix);
			html += '</div>';
			html += '</div>';
			html += '</div>';

			return html;
		}

		function renderMailboxContextItem(label, value) {
			return '<div><dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value || 'No match yet') + '</dd></div>';
		}

		function renderMailboxFollowUpControl(followUp, thread, prefix) {
			followUp = followUp || {};
			const recommended = parseInt(followUp.recommended_after_hours || '24', 10) || 24;
			const values = [2, 6, 24, 48, 72];

			if (recommended > 0 && values.indexOf(recommended) === -1) {
				values.unshift(recommended);
			}

			let html = '<div class="yoro-mailbox-follow-up yoro-mailbox-select-field">';
			html += '<span class="yoro-mailbox-workflow-label">Follow-up reminder</span>';

			if (followUp.due_at) {
				html += '<small>Scheduled for ' + escapeHtml(formatMailboxDateTime(followUp.due_at) || followUp.due_at) + '</small>';
			} else if (followUp.recommended_after_hours > 0) {
				html += '<small>Suggested if no answer after ' + escapeHtml(String(followUp.recommended_after_hours)) + 'h.</small>';
			} else {
				html += '<small>No reminder suggested for this classification.</small>';
			}

			html += '<div class="yoro-mailbox-follow-up-actions">';
			html += '<select class="' + escapeHtml(prefix) + '-follow-up-picker yoro-mailbox-follow-up-picker yoro-mailbox-control-select">';
			values.forEach(function(value) {
				html += '<option value="' + escapeHtml(value) + '"' + (value === recommended ? ' selected' : '') + '>In ' + escapeHtml(value) + 'h</option>';
			});
			html += '</select>';
			html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-follow-up-set" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-clock" aria-hidden="true"></span><span>Remind</span></button>';
			if (followUp.due_at) {
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-follow-up-clear" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span>Clear</span></button>';
			}
			html += '</div>';
			html += '</div>';

			return html;
		}

		function mailboxClassificationLabel(value) {
			const labels = {
				customer_support: 'Customer support',
				order_support: 'Order support',
				product_question: 'Product question',
				pre_sales: 'Product question',
				refund: 'Refund / return',
				shipping: 'Shipping',
				marketing: 'Marketing',
				marketing_or_ads: 'Marketing',
				system_notification: 'System notification',
				spam: 'Spam / phishing',
				spam_or_phishing: 'Spam / phishing',
				unknown: 'Unclear',
				unrelated: 'Unrelated'
			};

			return labels[String(value || '').trim()] || 'Unclear';
		}

		function mailboxPriorityLabel(value) {
			const priority = String(value || 'normal').trim();

			return priority.charAt(0).toUpperCase() + priority.slice(1);
		}

		function mailboxHandlingLabel(value) {
			const labels = {
				draft_reply: 'Draft reply',
				ask_for_missing_info: 'Ask for missing info',
				archive_or_ignore: 'Archive / ignore',
				manual_review: 'Manual review'
			};

			return labels[String(value || '').trim()] || 'Manual review';
		}

		function mailboxCustomerContextLabel(customer) {
			customer = customer || {};

			if (customer.email || customer.name) {
				return [customer.name || '', customer.email || '', mailboxStatusText(customer.status || '')].filter(Boolean).join(' · ');
			}

			return '';
		}

		function mailboxOrderContextLabel(order, match) {
			order = order || {};
			match = match || {};

			if (order.id || order.number) {
				return [
					'#' + (order.number || order.id),
					mailboxStatusText(order.status || ''),
					mailboxStatusText(order.shipping_status || ''),
					order.tracking_number ? 'Tracking ' + order.tracking_number : ''
				].filter(Boolean).join(' · ');
			}

			return mailboxStatusText(match.status || '');
		}

		function mailboxProductContextLabel(products, match) {
			products = Array.isArray(products) ? products : [];
			match = match || {};

			if (products.length) {
				const product = products[0] || {};
				return [product.name || product.sku || '', mailboxStatusText(product.stock_status || ''), product.price || ''].filter(Boolean).join(' · ');
			}

			return mailboxStatusText(match.status || '');
		}

		function mailboxLanguageLabel(language) {
			language = language || {};

			return String(language.language || '').toUpperCase() || 'Auto';
		}

		function mailboxStatusText(value) {
			return String(value || '').replace(/[_-]+/g, ' ').trim();
		}

		function renderMailboxThreadWorkflow(data, prefix) {
			const thread = data && data.thread ? data.thread : {};
			const statuses = mailboxWorkflowStatuses(data);
			const currentStatus = String(thread.workflow_status || thread.status || 'open');
			const assignedTo = thread.assigned_to || {};
			const assignedBy = mailboxPersonLabel(thread.assigned_by, '');
			const assigneeOptions = mailboxAssigneeOptions();
			const statusChangedBy = mailboxPersonLabel(thread.status_changed_by, '');
			let html = '<div class="yoro-mailbox-workflow ' + escapeHtml(prefix) + '-workflow">';

			html += '<div class="yoro-mailbox-thread-section-head">';
			html += '<strong>Thread management</strong>';
			html += '<span>' + escapeHtml(mailboxStatusLabel(data, currentStatus)) + '</span>';
			html += '</div>';
			html += '<div class="yoro-mailbox-management-layout">';
			html += '<div class="yoro-mailbox-management-control is-status">';
			html += '<div class="yoro-mailbox-management-control-head"><span>Status</span><em>' + escapeHtml(mailboxStatusLabel(data, currentStatus)) + '</em></div>';
			html += '<select class="' + escapeHtml(prefix) + '-status yoro-mailbox-control-select">';
			statuses.forEach(function(status) {
				const value = String(status.value || '');
				html += '<option value="' + escapeHtml(value) + '"' + (value === currentStatus ? ' selected' : '') + '>' + escapeHtml(status.label || value) + '</option>';
			});
			html += '</select>';
			html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-status-save" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span>Update status</span></button>';
			html += '</div>';

			html += '<div class="yoro-mailbox-management-control is-owner">';
			html += '<div class="yoro-mailbox-management-control-head"><span>Owner</span><em>' + escapeHtml(mailboxPersonLabel(assignedTo, 'Unassigned')) + '</em></div>';
			if (assigneeOptions.length) {
				html += '<select class="' + escapeHtml(prefix) + '-assignee-picker yoro-mailbox-control-select">';
				html += '<option value="">Select owner</option>';
				assigneeOptions.forEach(function(option) {
					const value = mailboxAssigneeOptionValue(option);
					const selected = (
						(option.id && String(option.id) === String(assignedTo.id || '')) ||
						(option.email && String(option.email).toLowerCase() === String(assignedTo.email || '').toLowerCase()) ||
						(option.name && String(option.name) === String(assignedTo.name || ''))
					) ? ' selected' : '';
					html += '<option value="' + escapeHtml(value) + '"' + selected + '>' + escapeHtml(mailboxPersonLabel(option, 'Staff')) + '</option>';
				});
				html += '</select>';
				html += '<div class="yoro-mailbox-management-inline-actions">';
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-assignee-save" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span>Save owner</span></button>';
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-assignee-mine" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><span>Assign to me</span></button>';
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-assignee-clear" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span>Clear</span></button>';
				html += '</div>';
			} else {
				html += '<small>No eligible WordPress users found.</small>';
			}
			html += '</div>';

			html += '<div class="yoro-mailbox-management-control is-actions">';
			html += '<div class="yoro-mailbox-management-control-head"><span>Inbox actions</span><em>' + escapeHtml(thread.unread ? 'Unread' : 'Read') + '</em></div>';
			html += '<div class="yoro-mailbox-management-inline-actions">';
			if (thread.unread) {
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-mark-read" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-visibility" aria-hidden="true"></span><span>Mark read</span></button>';
			} else {
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-mark-unread" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-hidden" aria-hidden="true"></span><span>Mark unread</span></button>';
			}
			if (currentStatus !== 'archived') {
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-archive" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-archive" aria-hidden="true"></span><span>Archive</span></button>';
			}
			if (currentStatus !== 'open') {
				html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-reopen" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-update-alt" aria-hidden="true"></span><span>Reopen</span></button>';
			}
			html += '</div>';
			html += '</div>';
			html += '</div>';

			const meta = [];
			if (statusChangedBy || thread.status_changed_at) {
				meta.push('Status ' + (formatMailboxDateTime(thread.status_changed_at) || '') + (statusChangedBy ? ' by ' + statusChangedBy : ''));
			}
			if (assignedBy || thread.assigned_at) {
				meta.push('Owner ' + (formatMailboxDateTime(thread.assigned_at) || '') + (assignedBy ? ' by ' + assignedBy : ''));
			}
			if (meta.length) {
				html += '<small class="yoro-mailbox-management-meta">' + escapeHtml(meta.join(' · ')) + '</small>';
			}

			if (thread.last_error) {
				html += '<div class="yoro-mailbox-thread-error"><strong>Provider action failed</strong><span>' + escapeHtml(mailboxCompactError(thread.last_error)) + '</span></div>';
			}

			html += '</div>';

			return html;
		}

		function mailboxCompactError(message) {
			let text = String(message || '').trim();

			if (!text) {
				return '';
			}

			text = text.replace(/\s+/g, ' ');

			if (/insufficient authentication scopes/i.test(text)) {
				return 'This mailbox needs updated permissions. Reconnect it from Settings > Email Inbox, then retry.';
			}

			if (text.length > 180) {
				return text.slice(0, 177).trim() + '...';
			}

			return text;
		}

		function renderMailboxInternalNotes(data, prefix) {
			const thread = data && data.thread ? data.thread : {};
			const notes = Array.isArray(data && data.internal_notes) ? data.internal_notes : [];
			let html = '<div class="yoro-mailbox-thread-section yoro-mailbox-notes">';
			html += '<div class="yoro-mailbox-thread-section-head"><strong>Internal notes</strong><span>Only visible to store staff.</span></div>';

			if (notes.length) {
				html += '<div class="yoro-mailbox-note-list">';
				notes.forEach(function(note) {
					const actor = mailboxPersonLabel(note.sent_by, 'Staff');
					html += '<div class="yoro-mailbox-note">';
					html += '<div><strong>' + escapeHtml(actor) + '</strong><time>' + escapeHtml(formatMailboxDateTime(note.created_at)) + '</time></div>';
					html += '<p>' + mailboxMessageBodyHtml(note) + '</p>';
					html += '</div>';
				});
				html += '</div>';
			} else {
				html += '<p class="yoro-mailbox-empty-inline">No internal notes yet.</p>';
			}

			html += '<textarea rows="2" class="' + escapeHtml(prefix) + '-note-input yoro-mailbox-note-input" placeholder="Add an internal note"></textarea>';
			html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-note-save" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-saved" aria-hidden="true"></span><span>Save internal note</span></button>';
			html += '</div>';

			return html;
		}

		function renderMailboxReplyHistory(data, prefix) {
			const thread = data && data.thread ? data.thread : {};
			const replies = Array.isArray(data && data.reply_history) ? data.reply_history : [];
			let html = '<div class="yoro-mailbox-thread-section yoro-mailbox-reply-history">';
			html += '<div class="yoro-mailbox-thread-section-head"><strong>Reply history</strong><span>Drafts, sent replies, failed attempts, and retry actions.</span></div>';

			if (!replies.length) {
				html += '<p class="yoro-mailbox-empty-inline">No replies have been sent yet.</p>';
				html += '</div>';

				return html;
			}

			html += '<div class="yoro-mailbox-reply-history-list">';
			replies.forEach(function(reply) {
				const status = String(reply.status || '').trim() || 'draft';
				const actor = mailboxPersonLabel(reply.sent_by, 'YoRo');
				const mailbox = reply.mailbox && reply.mailbox.mailbox_email ? reply.mailbox.mailbox_email : '';
				const sentAt = formatMailboxDateTime(reply.sent_at || reply.failed_at || reply.created_at);
				html += '<div class="yoro-mailbox-reply-history-item is-' + escapeHtml(status) + '">';
				html += '<div class="yoro-mailbox-reply-history-meta">';
				html += '<strong>' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</strong>';
				html += '<span>' + escapeHtml(actor) + (mailbox ? ' · ' + escapeHtml(mailbox) : '') + (sentAt ? ' · ' + escapeHtml(sentAt) : '') + '</span>';
				html += '</div>';
				html += '<p>' + escapeHtml(compactMailboxContextText(normalizeMailboxMessageText(reply), 260, true)) + '</p>';
				if (reply.last_error) {
					html += '<small>' + escapeHtml(reply.last_error) + '</small>';
				}
				if (status === 'failed') {
					html += '<button type="button" class="button button-small ' + escapeHtml(prefix) + '-retry" data-thread-id="' + escapeHtml(thread.id || '') + '" data-message-id="' + escapeHtml(reply.id || '') + '"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Retry</span></button>';
				}
				html += '</div>';
			});
			html += '</div>';
			html += '</div>';

			return html;
		}

		function rememberMailboxThreadContext(data) {
			const context = buildMailboxThreadContext(data || {});
			const threadId = String(context && context.thread ? context.thread.id || '' : '').trim();

			if (threadId) {
				mailboxThreadContextCache[threadId] = context;
			}

			return context;
		}

		function buildMailboxThreadContext(data) {
			data = data || {};

			const connection = data.connection || {};
			const thread = data.thread || {};
			const messages = Array.isArray(data.messages) ? data.messages : [];

			return {
				intent: 'draft_reply',
				source: 'selected_mailbox_thread',
				intelligence: data.intelligence || (thread.intelligence || {}),
				connection: {
					provider: compactMailboxContextText(connection.provider, 40),
					mailbox_email: compactMailboxContextText(connection.mailbox_email, 160),
					display_name: compactMailboxContextText(connection.display_name, 120)
				},
				thread: {
					id: parseInt(thread.id || 0, 10) || compactMailboxContextText(thread.id, 80),
					provider_thread_id: compactMailboxContextText(thread.provider_thread_id, 120),
					subject: compactMailboxContextText(thread.subject || '(No subject)', 200),
					participants: compactMailboxAddressList(thread.participants, 8),
					snippet: compactMailboxContextText(thread.snippet, 500, true),
					status: compactMailboxContextText(thread.status, 40),
					unread: !!thread.unread,
					last_message_at: compactMailboxContextText(thread.last_message_at, 80),
					last_inbound_at: compactMailboxContextText(thread.last_inbound_at, 80),
					last_outbound_at: compactMailboxContextText(thread.last_outbound_at, 80)
				},
				messages: messages.slice(-6).map(function(message, index) {
					message = message || {};

					return {
						position: index + 1,
						id: parseInt(message.id || 0, 10) || '',
						direction: compactMailboxContextText(message.direction, 40),
						status: compactMailboxContextText(message.status, 40),
						from_email: compactMailboxContextText(message.from_email, 160),
						from_name: compactMailboxContextText(message.from_name, 120),
						to: compactMailboxAddressList(message.to, 6),
						cc: compactMailboxAddressList(message.cc, 6),
						subject: compactMailboxContextText(message.subject || thread.subject || '', 200),
						snippet: compactMailboxContextText(message.snippet, 500, true),
						body_text: compactMailboxContextText(normalizeMailboxMessageText(message), 1800, true),
						received_at: compactMailboxContextText(message.received_at, 80),
						sent_at: compactMailboxContextText(message.sent_at, 80),
						created_at: compactMailboxContextText(message.created_at, 80)
					};
				})
			};
		}

		function compactMailboxAddressList(addresses, limit) {
			if (!Array.isArray(addresses)) {
				return [];
			}

			return addresses.map(function(address) {
				address = address || {};

				return {
					name: compactMailboxContextText(address.name, 120),
					email: compactMailboxContextText(address.email, 160)
				};
			}).filter(function(address) {
				return !!(address.name || address.email);
			}).slice(0, Math.max(1, limit || 1));
		}

		function compactMailboxContextText(value, limit, preserveLines) {
			let text = String(value === null || value === undefined ? '' : value).trim();

			if (!text) {
				return '';
			}

			text = text
				.replace(/\r\n/g, '\n')
				.replace(/\r/g, '\n')
				.replace(/[ \t]+/g, ' ');

			if (preserveLines) {
				text = text.replace(/\n{3,}/g, '\n\n');
			} else {
				text = text.replace(/\s+/g, ' ');
			}

			return text.trim().slice(0, Math.max(1, limit || 1));
		}

		function mailboxMessageBodyHtml(message) {
			const text = normalizeMailboxMessageText(message || {});

			return text ? escapeHtml(text) : '<em>No message body.</em>';
		}

		function normalizeMailboxMessageText(message) {
			let text = String(message.body_text || '').trim();

			if (!text && message.body_html) {
				text = htmlToMailboxText(String(message.body_html || ''));
			}

			if (!text) {
				text = String(message.snippet || '').trim();
			}

			text = decodeQuotedPrintableText(text);

			if (looksLikeHtml(text)) {
				text = htmlToMailboxText(text);
			}

			return stripMailboxRawArtifacts(text)
				.replace(/\r\n/g, '\n')
				.replace(/\r/g, '\n')
				.replace(/[ \t]+\n/g, '\n')
				.replace(/\n{4,}/g, '\n\n\n')
				.trim();
		}

		function looksLikeHtml(value) {
			return /<\/?(?:html|body|div|p|br|table|tr|td|span|a)\b/i.test(String(value || ''));
		}

		function htmlToMailboxText(html) {
			const container = document.createElement('div');
			container.innerHTML = String(html || '')
				.replace(/<\s*br\s*\/?>/gi, '\n')
				.replace(/<\s*\/p\s*>/gi, '\n\n')
				.replace(/<\s*\/div\s*>/gi, '\n')
				.replace(/<\s*\/tr\s*>/gi, '\n')
				.replace(/<\s*\/li\s*>/gi, '\n');

			container.querySelectorAll('script, style, noscript').forEach(function(node) {
				node.remove();
			});

			return String(container.textContent || '').replace(/\u00a0/g, ' ');
		}

		function decodeQuotedPrintableText(value) {
			let text = String(value || '');

			if (!/(=[0-9A-F]{2}|=\r?\n)/i.test(text)) {
				return text;
			}

			text = text.replace(/=\r?\n/g, '');

			return text.replace(/(?:=[0-9A-F]{2})+/gi, function(sequence) {
				const bytes = sequence.match(/=([0-9A-F]{2})/gi).map(function(hex) {
					return parseInt(hex.slice(1), 16);
				});

				if (window.TextDecoder) {
					return new TextDecoder('utf-8').decode(new Uint8Array(bytes));
				}

				return String.fromCharCode.apply(null, bytes);
			});
		}

		function stripMailboxRawArtifacts(value) {
			return String(value || '')
				.replace(/[\u034f\u00ad\u200b-\u200d\ufeff]/g, '')
				.replace(/[ \t]*\(\s*https?:\/\/[^)\n]+?\s*\)/gi, '')
				.replace(/^\s*https?:\/\/\S+\s*$/gmi, '')
				.replace(/[ \t]*\(\s*#\s*\)/g, '')
				.replace(/^\s*--[A-Za-z0-9'()+_,.\/:=?-]{8,}--?\s*$/gm, '')
				.replace(/^\s*(?:Content-Type|Content-Transfer-Encoding|Content-Disposition|MIME-Version|DKIM-Signature|Received):.*$/gmi, '')
				.replace(/^\s*charset="?[^"\n]+"?\s*$/gmi, '')
				.replace(/^\s*boundary="?[^"\n]+"?\s*$/gmi, '')
				.replace(/^\s*\*{3,}\s*$/gm, '')
				.replace(/^\*([^*\n]+):\*$/gm, '$1:')
				.replace(/\*([^*\n]+)\*/g, '$1')
				.replace(/[ \t]+([.,;:!?])/g, '$1')
				.replace(/\(\s*\)/g, '');
		}

		function recommendedMailboxEmail() {
			return String(
				window.YoRoAIAdmin && YoRoAIAdmin.woocommerceEmailFromAddress
					? YoRoAIAdmin.woocommerceEmailFromAddress
					: ''
			).trim();
		}

		function recommendedMailboxName() {
			return String(
				window.YoRoAIAdmin && YoRoAIAdmin.woocommerceEmailFromName
					? YoRoAIAdmin.woocommerceEmailFromName
					: ''
			).trim();
		}

		function renderMailboxRecommendation(className) {
			const email = recommendedMailboxEmail();

			if (!email) {
				return '';
			}

			const name = recommendedMailboxName();
			const label = name ? name + ' <' + email + '>' : email;
			let html = '<div class="' + escapeHtml(className || 'yoro-email-connection-recommendation') + '">';
			html += '<span>Recommended mailbox</span>';
			html += '<strong>' + escapeHtml(label) + '</strong>';
			html += '<p>Use the same address as WooCommerce &gt; Settings &gt; Emails &gt; From email so store emails and customer replies stay together.</p>';
			html += '</div>';

			return html;
		}

		function renderMailboxAlignmentNotice(mailboxEmail, className) {
			const recommended = recommendedMailboxEmail();
			const connected = String(mailboxEmail || '').trim();

			if (!recommended || !connected || recommended.toLowerCase() === connected.toLowerCase()) {
				return '';
			}

			let html = '<div class="' + escapeHtml(className || 'yoro-email-connection-warning') + '">';
			html += '<strong>Different from WooCommerce From email</strong>';
			html += '<p>WooCommerce sends system emails from ' + escapeHtml(recommended) + ', while YoRo is connected to ' + escapeHtml(connected) + '. Connect the same mailbox if you want sent emails and customer replies in one place.</p>';
			html += '</div>';

			return html;
		}

		function mailboxProviderOptions() {
			return [
				{
					key: 'gmail',
					label: 'Gmail',
					description: 'For Gmail and Google Workspace mailboxes.',
					icon: 'dashicons-email-alt',
					enabled: !!(window.YoRoAIAdmin && YoRoAIAdmin.emailGmailConnectIntentUrl),
					badge: 'Available'
				},
				{
					key: 'microsoft',
					label: 'Microsoft 365 / Outlook',
					description: 'For Outlook.com, Microsoft 365, and Exchange Online mailboxes.',
					icon: 'dashicons-cloud',
					enabled: !!(window.YoRoAIAdmin && YoRoAIAdmin.emailMicrosoftConnectIntentUrl),
					badge: 'Available'
				},
				{
					key: 'imap',
					label: 'Other mailbox',
					description: 'For IMAP/SMTP mailboxes from other email providers.',
					icon: 'dashicons-admin-generic',
					enabled: !!(window.YoRoAIAdmin && YoRoAIAdmin.emailImapConnectUrl),
					badge: 'Available'
				}
			];
		}

		function renderMailboxProviderOptions(className, connectButtonClass) {
			let html = '<div class="' + escapeHtml(className || 'yoro-mailbox-provider-options') + '">';

			mailboxProviderOptions().forEach(function(provider) {
				const isEnabled = !!provider.enabled;
				const iconClass = provider.icon || 'dashicons-email-alt';
				html += '<div class="yoro-mailbox-provider-option' + (isEnabled ? ' is-enabled' : ' is-disabled') + '">';
				html += '<span class="dashicons ' + escapeHtml(iconClass) + '" aria-hidden="true"></span>';
				html += '<div>';
				html += '<strong>' + escapeHtml(provider.label) + '</strong>';
				html += '<p>' + escapeHtml(provider.description) + '</p>';
				html += '</div>';
				html += '<em>' + escapeHtml(provider.badge || '') + '</em>';

				if (isEnabled) {
					html += '<button type="button" class="button button-primary ' + escapeHtml(connectButtonClass || 'yoro-mailbox-connect') + '" data-yoro-mailbox-provider="' + escapeHtml(provider.key) + '"><span class="dashicons ' + escapeHtml(iconClass) + '" aria-hidden="true"></span><span>' + escapeHtml(provider.key === 'imap' ? 'Set up mailbox' : 'Connect ' + provider.label) + '</span></button>';
					if (provider.key === 'imap') {
						html += renderImapMailboxForm();
					}
				} else {
					html += '<button type="button" class="button" disabled><span class="dashicons dashicons-lock" aria-hidden="true"></span><span>Not available yet</span></button>';
				}

				html += '</div>';
			});

			html += '</div>';

			return html;
		}

		function renderImapMailboxForm() {
			const email = recommendedMailboxEmail();
			const name = recommendedMailboxName();
			let html = '<div class="yoro-mailbox-imap-form" data-yoro-imap-form hidden>';
			html += '<div class="yoro-mailbox-imap-grid">';
			html += '<label><span>Mailbox email</span><input type="email" name="mailbox_email" value="' + escapeHtml(email) + '" autocomplete="email"></label>';
			html += '<label><span>Display name</span><input type="text" name="display_name" value="' + escapeHtml(name) + '" autocomplete="name"></label>';
			html += '<label><span>IMAP host</span><input type="text" name="imap_host" placeholder="imap.example.com" autocomplete="off"></label>';
			html += '<label><span>IMAP port</span><input type="number" name="imap_port" value="993" min="1" max="65535"></label>';
			html += '<label><span>IMAP security</span><select name="imap_encryption"><option value="ssl">SSL</option><option value="tls">STARTTLS</option><option value="none">None</option></select></label>';
			html += '<label><span>IMAP username</span><input type="text" name="imap_username" value="' + escapeHtml(email) + '" autocomplete="username"></label>';
			html += '<label><span>IMAP password</span><input type="password" name="imap_password" autocomplete="current-password"></label>';
			html += '<label><span>SMTP host</span><input type="text" name="smtp_host" placeholder="smtp.example.com" autocomplete="off"></label>';
			html += '<label><span>SMTP port</span><input type="number" name="smtp_port" value="587" min="1" max="65535"></label>';
			html += '<label><span>SMTP security</span><select name="smtp_encryption"><option value="tls">STARTTLS</option><option value="ssl">SSL</option><option value="none">None</option></select></label>';
			html += '<label><span>SMTP username</span><input type="text" name="smtp_username" value="' + escapeHtml(email) + '" autocomplete="username"></label>';
			html += '<label><span>SMTP password</span><input type="password" name="smtp_password" autocomplete="current-password" placeholder="Leave blank to reuse IMAP password"></label>';
			html += '</div>';
			html += '<div class="yoro-mailbox-imap-actions">';
			html += '<button type="button" class="button button-primary yoro-mailbox-imap-submit"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span>Connect mailbox</span></button>';
			html += '<p>Credentials are stored encrypted in YoRo API.</p>';
			html += '</div>';
			html += '</div>';

			return html;
		}

		function startMailboxProviderConnect(provider, options) {
			provider = String(provider || 'gmail').trim();
			options = options || {};

			if (provider === 'imap') {
				toggleImapMailboxForm(options.button || null);
				return;
			}

			startOAuthMailboxConnect(provider, options);
		}

		function startGmailMailboxConnect(options) {
			startOAuthMailboxConnect('gmail', options || {});
		}

		function startOAuthMailboxConnect(provider, options) {
			options = options || {};

			const settingsRoot = options.settingsRoot || null;
			const providerConfig = mailboxOAuthProviderConfig(provider);

			if (!providerConfig.url) {
				if (settingsRoot) {
					renderMailboxSettingsError(settingsRoot, null, 'The ' + providerConfig.label + ' connection endpoint is not configured.');
					return;
				}

				addMailboxErrorMessage(null, 'The ' + providerConfig.label + ' connection endpoint is not configured.');
				return;
			}

			const loading = settingsRoot ? null : addLoadingMessage();
			const finishProtectedWork = settingsRoot ? function () {} : beginProtectedWork();

			if (settingsRoot) {
				renderMailboxSettingsLoading(settingsRoot, 'Starting ' + providerConfig.label + ' connection...');
			} else {
				setLoading(true);
			}

			requestJson(providerConfig.url, {
				method: 'POST',
				body: JSON.stringify({
					suggested_mailbox_email: recommendedMailboxEmail()
				})
			})
				.then(function (data) {
					const authorizationUrl = String(data && data.authorization_url ? data.authorization_url : '');

					if (!authorizationUrl) {
						throw {
							message: 'YoRo did not return a ' + providerConfig.label + ' authorization URL.'
						};
					}

					if (loading) {
						loading.remove();
					}
					finishProtectedWork();
					if (!settingsRoot) {
						setLoading(false);
					}
					window.location.href = authorizationUrl;
				})
				.catch(function (error) {
					if (loading) {
						loading.remove();
					}

					if (settingsRoot) {
						renderMailboxSettingsError(settingsRoot, error, 'I could not start the ' + providerConfig.label + ' inbox connection.');
					} else {
						addMailboxErrorMessage(error, 'I could not start the ' + providerConfig.label + ' inbox connection.');
					}
					finishProtectedWork();
					if (!settingsRoot) {
						setLoading(false);
					}
				});
		}

		function mailboxOAuthProviderConfig(provider) {
			if (provider === 'microsoft') {
				return {
					label: 'Microsoft',
					url: window.YoRoAIAdmin ? YoRoAIAdmin.emailMicrosoftConnectIntentUrl : ''
				};
			}

			return {
				label: 'Gmail',
				url: window.YoRoAIAdmin ? YoRoAIAdmin.emailGmailConnectIntentUrl : ''
			};
		}

		function toggleImapMailboxForm(button) {
			const card = button ? button.closest('.yoro-mailbox-provider-option') : null;
			const form = card ? card.querySelector('[data-yoro-imap-form]') : null;

			if (!form) {
				addMailboxErrorMessage(null, 'The IMAP setup form is not available.');
				return;
			}

			form.hidden = !form.hidden;
		}

		function connectImapMailbox(target, options) {
			options = options || {};

			const settingsRoot = options.settingsRoot || (target ? target.closest('[data-yoro-mailbox-settings]') : null);
			const form = target ? target.closest('[data-yoro-imap-form]') : null;

			if (!form) {
				if (settingsRoot) {
					renderMailboxSettingsError(settingsRoot, null, 'The IMAP setup form is not available.');
				} else {
					addMailboxErrorMessage(null, 'The IMAP setup form is not available.');
				}
				return;
			}

			if (!YoRoAIAdmin.emailImapConnectUrl) {
				if (settingsRoot) {
					renderMailboxSettingsError(settingsRoot, null, 'The IMAP connection endpoint is not configured.');
				} else {
					addMailboxErrorMessage(null, 'The IMAP connection endpoint is not configured.');
				}
				return;
			}

			const payload = collectImapMailboxForm(form);

			if (!payload.mailbox_email || !payload.imap_host || !payload.imap_username || !payload.imap_password || !payload.smtp_host) {
				const message = 'Mailbox email, IMAP host, IMAP username, IMAP password, and SMTP host are required.';
				if (settingsRoot) {
					renderMailboxSettingsError(settingsRoot, null, message);
				} else if (options.quickInbox) {
					renderQuickInboxError(null, message);
				} else {
					addMailboxErrorMessage(null, message);
				}
				return;
			}

			target.disabled = true;

			if (settingsRoot) {
				renderMailboxSettingsLoading(settingsRoot, 'Connecting IMAP mailbox...');
			} else if (options.quickInbox) {
				renderQuickInboxLoading('Connecting mailbox...');
			} else {
				setLoading(true);
			}

			requestJson(YoRoAIAdmin.emailImapConnectUrl, {
				method: 'POST',
				body: JSON.stringify(payload)
			})
				.then(function() {
					if (settingsRoot) {
						loadMailboxSettings(settingsRoot, true);
						return;
					}

					if (options.quickInbox) {
						loadQuickInbox(true);
						return;
					}

					openQuickInboxPanel();
				})
				.catch(function(error) {
					if (settingsRoot) {
						renderMailboxSettingsError(settingsRoot, error, 'I could not connect the IMAP inbox.');
					} else if (options.quickInbox) {
						renderQuickInboxError(error, 'I could not connect the IMAP inbox.');
					} else if (openQuickInboxPanel()) {
						renderQuickInboxError(error, 'I could not connect the IMAP inbox.');
					} else {
						addMailboxErrorMessage(error, 'I could not connect the IMAP inbox.');
					}
				})
				.finally(function() {
					target.disabled = false;
					if (!settingsRoot && !options.quickInbox) {
						setLoading(false);
					}
				});
		}

		function collectImapMailboxForm(form) {
			const value = function(name) {
				const field = form.querySelector('[name="' + name + '"]');

				return field ? String(field.value || '').trim() : '';
			};

			return {
				mailbox_email: value('mailbox_email'),
				display_name: value('display_name'),
				imap_host: value('imap_host'),
				imap_port: Number(value('imap_port') || 993),
				imap_encryption: value('imap_encryption') || 'ssl',
				imap_username: value('imap_username'),
				imap_password: value('imap_password'),
				smtp_host: value('smtp_host'),
				smtp_port: Number(value('smtp_port') || 587),
				smtp_encryption: value('smtp_encryption') || 'tls',
				smtp_username: value('smtp_username'),
				smtp_password: value('smtp_password'),
				suggested_mailbox_email: recommendedMailboxEmail()
			};
		}

		function loadMailboxSettings(settingsRoot, syncFirst) {
			if (!settingsRoot) {
				return;
			}

			if (!YoRoAIAdmin.emailInboxUrl) {
				renderMailboxSettingsError(settingsRoot, null, 'The shop inbox endpoint is not configured.');
				return;
			}

			renderMailboxSettingsLoading(settingsRoot, syncFirst ? 'Syncing email inbox...' : 'Checking email connection...');

			const request = syncFirst && YoRoAIAdmin.emailInboxSyncUrl
				? requestJson(YoRoAIAdmin.emailInboxSyncUrl, {
					method: 'POST',
					body: JSON.stringify({ limit: 10 })
				}).then(function () {
					return requestJson(YoRoAIAdmin.emailInboxUrl + '?limit=1', {
						method: 'GET'
					});
				})
				: requestJson(YoRoAIAdmin.emailInboxUrl + '?limit=1', {
					method: 'GET'
				});

			request
				.then(function (data) {
					renderMailboxSettings(settingsRoot, data || {});
				})
				.catch(function (error) {
					renderMailboxSettingsError(settingsRoot, error, 'I could not load the email inbox connection.');
				});
		}

		function openMailboxFromSettings(settingsRoot) {
			if (chatThread) {
				closeAdminPanels();
				openQuickInboxPanel();
				return;
			}

			loadMailboxSettingsInbox(settingsRoot, false);
		}

		function loadMailboxSettingsInbox(settingsRoot, syncFirst) {
			if (!settingsRoot) {
				return;
			}

			if (!YoRoAIAdmin.emailInboxUrl) {
				renderMailboxSettingsError(settingsRoot, null, 'The shop inbox endpoint is not configured.');
				return;
			}

			renderMailboxSettingsLoading(settingsRoot, syncFirst ? 'Syncing email inbox...' : 'Opening email inbox...');

			const request = syncFirst && YoRoAIAdmin.emailInboxSyncUrl
				? requestJson(YoRoAIAdmin.emailInboxSyncUrl, {
					method: 'POST',
					body: JSON.stringify({ limit: 10 })
				}).then(function () {
					return requestJson(YoRoAIAdmin.emailInboxUrl + '?limit=10', {
						method: 'GET'
					});
				})
				: requestJson(YoRoAIAdmin.emailInboxUrl + '?limit=10', {
					method: 'GET'
				});

			request
				.then(function (data) {
					renderMailboxSettingsInbox(settingsRoot, data || {});
				})
				.catch(function (error) {
					renderMailboxSettingsError(settingsRoot, error, 'I could not open the email inbox.');
				});
		}

		function loadMailboxSettingsThread(settingsRoot, threadId) {
			if (!settingsRoot) {
				return;
			}

			if (!threadId) {
				renderMailboxSettingsError(settingsRoot, null, 'I could not identify that email thread.');
				return;
			}

			renderMailboxSettingsLoading(settingsRoot, 'Loading email thread...');

			requestJson(mailboxThreadUrl(threadId), {
				method: 'GET'
			})
				.then(function(data) {
					renderMailboxSettingsThread(settingsRoot, data || {});
				})
				.catch(function(error) {
					renderMailboxSettingsError(settingsRoot, error, 'I could not load that email thread.');
				});
		}

		function disconnectMailboxConnection(settingsRoot) {
			if (!settingsRoot) {
				return;
			}

			const disconnectUrl = YoRoAIAdmin.emailMailboxDisconnectUrl || YoRoAIAdmin.emailGmailDisconnectUrl;

			if (!disconnectUrl) {
				renderMailboxSettingsError(settingsRoot, null, 'The mailbox disconnect endpoint is not configured.');
				return;
			}

			if (!window.confirm('Disconnect this mailbox from YoRo? YoRo will stop syncing and sending replies from it.')) {
				return;
			}

			renderMailboxSettingsLoading(settingsRoot, 'Disconnecting shop inbox...');

			requestJson(disconnectUrl, {
				method: 'POST',
				body: JSON.stringify({})
			})
				.then(function (data) {
					const warning = data && data.revoke_error
						? 'The inbox was disconnected locally, but the provider revoke returned: ' + data.revoke_error
						: 'The shop inbox is disconnected.';

					renderMailboxSettings(settingsRoot, {
						connected: false,
						message: warning
					});
				})
				.catch(function (error) {
					renderMailboxSettingsError(settingsRoot, error, 'I could not disconnect the shop inbox.');
				});
		}

		function mailboxSettingsBody(settingsRoot) {
			return settingsRoot ? settingsRoot.querySelector('[data-yoro-mailbox-settings-body]') : null;
		}

		function renderMailboxSettingsLoading(settingsRoot, message) {
			const body = mailboxSettingsBody(settingsRoot);

			if (!body) {
				return;
			}

			body.innerHTML = '<div class="yoro-email-connection-summary is-loading">' +
				'<span class="dashicons dashicons-update" aria-hidden="true"></span>' +
				'<div>' +
				'<strong>' + escapeHtml(message || 'Checking email connection...') + '</strong>' +
				'<p>' + escapeHtml('YoRo is checking the connected shop inbox.') + '</p>' +
				'</div>' +
				'</div>';
		}

		function renderMailboxSettings(settingsRoot, data) {
			const body = mailboxSettingsBody(settingsRoot);

			if (!body) {
				return;
			}

			data = data || {};

			if (!data.connected) {
				body.innerHTML = '<div class="yoro-email-connection-summary is-disconnected">' +
					'<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>' +
					'<div>' +
					'<strong>Not connected</strong>' +
					'<p>' + escapeHtml(data.message || 'Connect an email provider so YoRo can show and reply to support emails.') + '</p>' +
					'</div>' +
					'</div>' +
					renderMailboxRecommendation('yoro-email-connection-recommendation') +
					renderMailboxProviderOptions('yoro-mailbox-provider-options', 'yoro-mailbox-settings-connect') +
					'<div class="yoro-email-connection-actions">' +
					'<button type="button" class="button yoro-mailbox-settings-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Refresh</span></button>' +
					'</div>';
				return;
			}

			const connection = data.connection || {};
			const provider = String(connection.provider || 'gmail').trim() || 'gmail';
			const lastSyncedAt = formatMailboxDateTime(connection.last_synced_at) || 'Not synced yet';
			const lastError = String(connection.last_error || '').trim();

			let html = '<div class="yoro-email-connection-summary is-connected">' +
				'<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' +
				'<div>' +
				'<strong>Connected</strong>' +
				'<p>' + escapeHtml(connection.mailbox_email || 'Connected mailbox') + '</p>' +
				'</div>' +
				'</div>';

			html += '<div class="yoro-email-connection-grid">';
			html += '<div><span>Provider</span><strong>' + escapeHtml(mailboxProviderLabel(connection.provider)) + '</strong></div>';
			html += '<div><span>Mailbox</span><strong>' + escapeHtml(connection.mailbox_email || 'Unknown') + '</strong></div>';
			html += '<div><span>Last sync</span><strong>' + escapeHtml(lastSyncedAt) + '</strong></div>';
			html += '<div><span>Unread</span><strong>' + escapeHtml(data.unread_count || 0) + '</strong></div>';
			html += '<div><span>Open threads</span><strong>' + escapeHtml(data.open_thread_count || 0) + '</strong></div>';
			html += '<div><span>Status</span><strong>' + escapeHtml(connection.status || 'active') + '</strong></div>';
			html += '</div>';

			if (lastError) {
				html += '<div class="yoro-email-connection-error"><strong>Last error</strong><p>' + escapeHtml(lastError) + '</p></div>';
			}

			html += renderMailboxAlignmentNotice(connection.mailbox_email, 'yoro-email-connection-warning');

			html += '<div class="yoro-email-connection-actions">';
			html += '<button type="button" class="button button-primary yoro-mailbox-settings-open"><span class="dashicons dashicons-email" aria-hidden="true"></span><span>Open inbox</span></button>';
			html += '<button type="button" class="button yoro-mailbox-settings-sync"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Sync now</span></button>';
			html += '<button type="button" class="button yoro-mailbox-settings-connect" data-yoro-mailbox-provider="' + escapeHtml(provider) + '"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span>Reconnect</span></button>';
			html += '<button type="button" class="button yoro-mailbox-settings-disconnect"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span><span>Disconnect</span></button>';
			html += '</div>';

			body.innerHTML = html;
		}

		function renderMailboxSettingsInbox(settingsRoot, data) {
			const body = mailboxSettingsBody(settingsRoot);

			if (!body) {
				return;
			}

			if (!data || !data.connected) {
				renderMailboxSettings(settingsRoot, data || {});
				return;
			}

			const connection = data.connection || {};
			const threads = Array.isArray(data.threads) ? data.threads : [];
			let html = '<div class="yoro-mailbox-panel yoro-mailbox-settings-panel">';

			html += '<div class="yoro-mailbox-head">';
			html += '<div>';
			html += '<strong>Shop inbox</strong>';
			html += '<span>' + escapeHtml(connection.mailbox_email || 'Connected mailbox') + '</span>';
			html += '</div>';
			html += '<div class="yoro-email-connection-actions">';
			html += '<button type="button" class="button yoro-mailbox-settings-back"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><span>Settings</span></button>';
			html += '<button type="button" class="button yoro-mailbox-settings-inbox-sync"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Sync now</span></button>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-mailbox-metrics">';
			html += '<div><span>Unread</span><strong>' + escapeHtml(data.unread_count || 0) + '</strong></div>';
			html += '<div><span>Open</span><strong>' + escapeHtml(data.open_thread_count || 0) + '</strong></div>';
			html += '<div><span>Synced</span><strong>' + escapeHtml(formatMailboxDateTime(connection.last_synced_at) || '-') + '</strong></div>';
			html += '</div>';

			if (!threads.length) {
				html += '<div class="yoro-mailbox-empty">No inbox threads found.</div>';
			} else {
				html += '<div class="yoro-mailbox-thread-list">';
				threads.forEach(function (thread) {
					const latest = thread.latest_message || {};
					html += '<button type="button" class="yoro-mailbox-thread-open' + (thread.unread ? ' is-unread' : '') + '" data-thread-id="' + escapeHtml(thread.id || '') + '">';
					html += '<span class="yoro-mailbox-thread-main">';
					html += '<strong>' + escapeHtml(thread.subject || '(No subject)') + '</strong>';
					html += '<span>' + escapeHtml(latest.from_email || mailboxParticipantsLabel(thread) || 'Unknown sender') + '</span>';
					html += '<small>' + escapeHtml(thread.snippet || latest.snippet || '') + '</small>';
					html += '</span>';
					html += '<span class="yoro-mailbox-thread-meta">';
					html += '<span>' + escapeHtml(formatMailboxDateTime(thread.last_message_at)) + '</span>';
					html += '<em>' + escapeHtml(mailboxStatusLabel(data, thread.workflow_status || thread.status || 'open')) + '</em>';
					if (thread.unread) {
						html += '<em>Unread</em>';
					}
					html += '</span>';
					html += '</button>';
				});
				html += '</div>';
			}

			html += '</div>';

			body.innerHTML = html;
		}

		function renderMailboxSettingsThread(settingsRoot, data) {
			const body = mailboxSettingsBody(settingsRoot);

			if (!body) {
				return;
			}

			data = data || {};
			rememberMailboxThreadContext(data);

			const connection = data.connection || {};
			const thread = data.thread || {};
			const messages = Array.isArray(data.messages) ? data.messages : [];
			let html = '<div class="yoro-mailbox-panel yoro-mailbox-thread-detail" data-yoro-mailbox-thread="' + escapeHtml(thread.id || '') + '" data-yoro-thread-subject="' + escapeHtml(thread.subject || '') + '">';

			html += '<div class="yoro-mailbox-head">';
			html += '<div>';
			html += '<strong>' + escapeHtml(thread.subject || '(No subject)') + '</strong>';
			html += '<span>' + escapeHtml(connection.mailbox_email || mailboxParticipantsLabel(thread) || 'Connected mailbox') + '</span>';
			html += renderMailboxThreadBadges(data, 'yoro-mailbox');
			html += '</div>';
			html += '<div class="yoro-email-connection-actions">';
			html += '<button type="button" class="button yoro-mailbox-settings-inbox-sync"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Sync now</span></button>';
			html += '<button type="button" class="button yoro-mailbox-settings-inbox-back"><span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span><span>Inbox</span></button>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-mailbox-message-list">';
			messages.forEach(function(message) {
				if (message.direction === 'internal') {
					return;
				}

				const isOutbound = message.direction === 'outbound';
				html += '<div class="yoro-quick-inbox-message yoro-mailbox-message ' + (isOutbound ? 'is-outbound' : 'is-inbound') + '">';
				html += '<div><strong>' + escapeHtml(isOutbound ? 'You' : (message.from_name || message.from_email || 'Customer')) + '</strong><time>' + escapeHtml(formatMailboxDateTime(message.sent_at || message.received_at || message.created_at)) + '</time></div>';
				html += '<div class="yoro-quick-inbox-message-body">' + mailboxMessageBodyHtml(message) + '</div>';
				html += '</div>';
			});
			html += '</div>';

			html += '<div class="yoro-quick-inbox-reply-box yoro-mailbox-reply-box">';
			html += '<textarea rows="3" class="yoro-mailbox-reply-input" placeholder="Reply from the shop inbox"></textarea>';
			html += '<div class="yoro-quick-inbox-reply-actions yoro-mailbox-reply-actions">';
			html += '<button type="button" class="button yoro-mailbox-draft-reply" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-welcome-write-blog" aria-hidden="true"></span><span>Draft with YoRo</span></button>';
			html += '<button type="button" class="button button-primary yoro-mailbox-send-reply" data-thread-id="' + escapeHtml(thread.id || '') + '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span><span>Send reply</span></button>';
			html += '</div>';
			html += '</div>';
			html += '</div>';

			body.innerHTML = html;
		}

		function renderMailboxSettingsError(settingsRoot, error, fallback) {
			const body = mailboxSettingsBody(settingsRoot);

			if (!body) {
				return;
			}

			body.innerHTML = '<div class="yoro-email-connection-summary is-error">' +
				'<span class="dashicons dashicons-warning" aria-hidden="true"></span>' +
				'<div>' +
				'<strong>Inbox unavailable</strong>' +
				'<p>' + escapeHtml(mailboxErrorMessage(error, fallback)) + '</p>' +
				'</div>' +
				'</div>' +
				'<div class="yoro-email-connection-actions">' +
				'<button type="button" class="button yoro-mailbox-settings-refresh"><span class="dashicons dashicons-update" aria-hidden="true"></span><span>Retry</span></button>' +
				'</div>';
		}

		function mailboxProviderLabel(provider) {
			const value = String(provider || '').trim();

			if (value === 'gmail') {
				return 'Gmail';
			}

			if (value === 'microsoft') {
				return 'Microsoft 365 / Outlook';
			}

			if (value === 'imap') {
				return 'IMAP';
			}

			return value || 'Email';
		}

		function askYoroToDraftMailboxReply(target, options) {
			options = options || {};

			const threadId = String(target ? target.getAttribute('data-thread-id') || '' : '').trim();
			const context = threadId ? mailboxThreadContextCache[threadId] : null;

			if (!options.template) {
				options.template = mailboxSelectedTemplate(target);
			}

			if (!threadId) {
				if (options.quickInbox) {
					renderQuickInboxError(null, 'I could not identify that email thread.');
				} else {
					addMailboxErrorMessage(null, 'I could not identify that email thread.');
				}
				return;
			}

			if (!context) {
				target.disabled = true;
				requestJson(mailboxThreadUrl(threadId), {
					method: 'GET'
				})
					.then(function(data) {
						startMailboxReplyDraft(threadId, rememberMailboxThreadContext(data || {}), options);
					})
					.catch(function(error) {
						if (options.quickInbox) {
							renderQuickInboxError(error, 'I could not load that email thread.');
						} else {
							addMailboxErrorMessage(error, 'I could not load that email thread.');
						}
					})
					.finally(function() {
						target.disabled = false;
					});
				return;
			}

			startMailboxReplyDraft(threadId, context, options);
		}

		function startMailboxReplyDraft(threadId, context, options) {
			options = options || {};

			if (!context || !context.thread) {
				if (options.quickInbox) {
					renderQuickInboxError(null, 'I could not identify that email thread.');
				} else {
					addMailboxErrorMessage(null, 'I could not identify that email thread.');
				}
				return;
			}

			nextMailboxThreadContext = context;

			if (options.quickInbox) {
				setQuickActionsOpen(false);
			}

			if (options.settingsRoot) {
				closeAdminPanels();
			}

			const subject = compactMailboxContextText(context.thread.subject || '', 140);
			const instruction = compactMailboxContextText(options.instruction || '', 260);
			const template = compactMailboxContextText(options.template || '', 120);
			let command = 'Draft a reply to shop inbox email thread #' + threadId + (subject ? ' "' + subject + '"' : '') + '. Use the selected email thread context, prepare the reply for my review, and do not send it until I confirm.';

			if (instruction) {
				command += ' Reply direction: ' + instruction;
			}

			if (template) {
				command += ' Email template preference: ' + template + '.';
			}

			commandField.value = command;
			syncRunButtonState();
			syncBeforeUnloadGuard();
			commandField.focus();
			scrollToBottom({
				behavior: 'smooth',
				force: true
			});
			runCommand(false, null, command);
		}

		function sendMailboxPreviewReply(payload) {
			payload = payload || {};

			const threadId = String(payload.thread_id || payload.mailbox_thread_id || '');
			const pendingOperationToken = String(payload.pending_operation_token || conversationState.pending_operation_token || '');
			const params = {
				thread_id: parseInt(threadId, 10),
				provider_thread_id: payload.provider_thread_id || '',
				to: Array.isArray(payload.to) ? payload.to : [],
				cc: Array.isArray(payload.cc) ? payload.cc : [],
				subject: payload.subject || '',
				body: payload.body || ''
			};

			if (pendingOperationToken) {
				return executeActionDirect('email.reply.send', params, true, false, '', pendingOperationToken);
			}

			return requestJson(mailboxThreadUrl(threadId, '/reply'), {
				method: 'POST',
				body: JSON.stringify(params)
			}).then(function(data) {
				return {
					success: true,
					stage: 'done',
					message: 'Email reply sent.',
					result: {
						success: true,
						summary: 'Email reply sent.',
						message: 'Email reply sent.',
						read_only: true,
						result_type: 'assistant_message',
						display_type: 'assistant_message',
						raw: data
					}
				};
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
			if (!requireAccountConnection()) {
				return;
			}

			const command = 'Context';
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
			const pendingOperationStatus = String(
				data.pending_operation_status ||
				result.pending_operation_status ||
				meta.pending_operation_status ||
				''
			).toLowerCase();

			if (conversationId) {
				conversationState.conversation_id = String(conversationId);
				saveStoredConversationId(conversationState.conversation_id);
				refreshAiApiStatusConversationLink();
			}

			if (conversationState.order_creation_receipt && (conversationState.order_creation_receipt.pending !== String(pendingOperationToken || '') || conversationState.order_creation_receipt.conversation !== String(conversationId || conversationState.conversation_id || ''))) {
				conversationState.order_creation_receipt = null;
			}
			if (data.stage === 'confirm' && (result.action || parsed.action) === 'orders.create' && typeof data.displayed_preview_receipt === 'string' && data.displayed_preview_receipt.length > 0 && data.displayed_preview_receipt.length <= 4096 && pendingOperationToken && conversationId) {
				conversationState.order_creation_receipt = { pending: String(pendingOperationToken), conversation: String(conversationId), value: data.displayed_preview_receipt };
			}
				if (pendingOperationToken) {
					conversationState.pending_operation_token = String(pendingOperationToken);
				}

				if (
					data.pending_operation_active === false ||
					result.pending_operation_active === false ||
					result.blocked === true ||
					['executed', 'failed', 'cancelled'].indexOf(pendingOperationStatus) !== -1
				) {
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

					if (nextMailboxThreadContext) {
						context.mailbox_thread_context = nextMailboxThreadContext;
						context.mailbox_thread_id = parseInt(
							nextMailboxThreadContext.thread && nextMailboxThreadContext.thread.id
								? nextMailboxThreadContext.thread.id
								: 0,
							10
						) || 0;
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

		function runCommand(confirm, onConfirmSuccess, commandOverride) {
			if (isSending || !requireAccountConnection()) {
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
			const clientContext = buildClientContext();

			const confirmRequestBody = {
				action: '',
				params: {},
				confirm: true,
				pending_operation_token: conversationState.pending_operation_token || '',
				conversation_id: conversationState.conversation_id || '',
				client_context: clientContext
			};

			if (confirm && conversationState.order_creation_receipt && conversationState.order_creation_receipt.pending === confirmRequestBody.pending_operation_token && conversationState.order_creation_receipt.conversation === confirmRequestBody.conversation_id) {
				confirmRequestBody.displayed_preview_receipt = conversationState.order_creation_receipt.value;
				conversationState.order_creation_receipt = null;
			}

				const requestBody = confirm
					? confirmRequestBody
					: {
						message: command,
						command: command,
						conversation_id: conversationState.conversation_id || '',
						client_context: clientContext
					};

				if (!confirm) {
					clearNextActionCatalogScope();
					nextMailboxThreadContext = null;
				}

				requestJson(endpointUrl, {
				method: 'POST',
				body: JSON.stringify(requestBody)
			})
			.then(function (data) {
				const action = data && (data.action || (data.parsed && data.parsed.action) || (data.result && data.result.action));
				if (!confirm && action === 'orders.create' && data.stage === 'confirm') {
					updateConversationState(data, command);
					conversationState.order_creation_receipt = null;
					return requestJson(YoRoAIAdmin.executeUrl, { method: 'POST', body: JSON.stringify({ action: 'orders.create', params: {}, confirm: false, pending_operation_token: conversationState.pending_operation_token || '', conversation_id: conversationState.conversation_id || '' }) });
				}
				return data;
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

			if (action === 'orders.create' && confirm && conversationState.order_creation_receipt
				&& conversationState.order_creation_receipt.pending === payload.pending_operation_token
				&& conversationState.order_creation_receipt.conversation === payload.conversation_id) {
				payload.displayed_preview_receipt = conversationState.order_creation_receipt.value;
				conversationState.order_creation_receipt = null;
			}

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

		const emailUI = window.YoRoEmailUI
			? window.YoRoEmailUI.init({
					escapeHtml: escapeHtml,
					formatDateTime: formatDateTimeForChat,
					renderDebugJson: renderDebugJsonProxy,
					resolveOrderId: resolveOrderId,
				setCurrentOrderId: setCurrentOrderId,
				executeActionDirect: executeActionDirect,
				sendMailboxPreviewReply: sendMailboxPreviewReply,
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
				sendMailboxPreviewReply: sendMailboxPreviewReply,
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

				if ((clarifyOption || approveButton) && !requireAccountConnection()) {
					event.preventDefault();
					return;
				}

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

		syncRunButtonState();
	});
})();
