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

	ready(function () {
		const commandField = document.getElementById('yoro-agent-command');
		const runButton = document.getElementById('yoro-agent-run');
		const previewButton = document.getElementById('yoro-agent-preview');
		const clearButton = document.getElementById('yoro-agent-clear');
		const contextButton = document.getElementById('yoro-agent-load-context');
		const chatThread = document.getElementById('yoro-chat-thread');
		const exampleButtons = document.querySelectorAll('.yoro-agent-example');
		const parserTestButton = document.getElementById('yoro-run-parser-tests');
		const parserTestResults = document.getElementById('yoro-parser-test-results');
		const realQueryPackButton = document.getElementById('yoro-build-real-query-pack');
		const realQueryPackResults = document.getElementById('yoro-real-query-pack-results');

		registerHistoryHandlers();
		registerDeveloperTools();

		if (!commandField || !runButton || !chatThread) {
			return;
		}

		function registerDeveloperTools() {
			if (parserTestButton && parserTestResults && window.YoRoAgentAdmin && YoRoAgentAdmin.parserTestUrl) {
				parserTestButton.addEventListener('click', function () {
					runParserTests();
				});
			}

			if (realQueryPackButton && realQueryPackResults && window.YoRoAgentAdmin && YoRoAgentAdmin.realQueryPackUrl) {
				realQueryPackButton.addEventListener('click', function () {
					buildRealQueryPack();
				});
			}

			document.addEventListener('click', function (event) {
				const target = event.target;

				if (
					!target.classList.contains('yoro-parser-copy-json')
					&& !target.classList.contains('yoro-parser-copy-hardening')
					&& !target.classList.contains('yoro-parser-copy-execute')
					&& !target.classList.contains('yoro-real-pack-copy-json')
				) {
					return;
				}

				const payload = target.getAttribute('data-copy-payload') || '';

				if (!payload) {
					return;
				}

				copyParserPayload(payload, target);
			});
		}

		function runParserTests() {
			parserTestButton.disabled = true;
			parserTestButton.textContent = 'Running...';
			parserTestResults.innerHTML = '<div class="yoro-parser-test-loading">Running parser benchmark...</div>';

			requestJson(YoRoAgentAdmin.parserTestUrl, {
				method: 'GET'
			})
				.then(function (data) {
					parserTestResults.innerHTML = renderParserTestResults(data);
				})
				.catch(function (error) {
					parserTestResults.innerHTML = renderParserTestError(error);
				})
				.finally(function () {
					parserTestButton.disabled = false;
					parserTestButton.textContent = 'Run Parser Tests';
				});
		}

		function renderParserTestError(error) {
			const message = (error && error.message)
				|| (error && error.data && error.data.message)
				|| 'Parser benchmark failed.';

			return '<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>';
		}

		function renderParserTestResults(data) {
			const summary = data && data.summary ? data.summary : {};
			const groupMeta = data && data.group_meta ? data.group_meta : {};
			const evaluation = groupMeta.evaluation || {};
			const byDomain = evaluation.by_domain || {};
			const byOutcome = evaluation.by_outcome || {};
			const bySource = evaluation.by_source || {};
			const topDomains = Object.keys(byDomain);
			const topOutcomes = Object.keys(byOutcome);
			const topSources = Object.keys(bySource);
			const optimizedPayload = escapeHtml(JSON.stringify(buildOptimizedParserPayload(data), null, 2));
			const hardeningPayload = escapeHtml(JSON.stringify(buildHardeningFailurePayload(data), null, 2));
			const executePayload = escapeHtml(JSON.stringify(buildExecuteFailurePayload(data), null, 2));

			let html = '<div class="yoro-parser-test-panel">';
			html += '<div class="yoro-parser-test-head">';
			html += '<h3>Parser Benchmark</h3>';
			html += '<p>Current regression and evaluation snapshot for YoRo parsing quality.</p>';
			html += '<div class="yoro-parser-test-toolbar">';
			html += '<button type="button" class="button button-secondary yoro-parser-copy-json" data-copy-payload="' + optimizedPayload + '">Copy debug JSON</button>';
			html += '<button type="button" class="button button-secondary yoro-parser-copy-hardening" data-copy-payload="' + hardeningPayload + '">Copy hardening failures</button>';
			html += '<button type="button" class="button button-secondary yoro-parser-copy-execute" data-copy-payload="' + executePayload + '">Copy execute failures</button>';
			html += '<span class="yoro-parser-test-toolbar-note">Debug JSON: summary, evaluation meta, failed evaluation cases. Hardening copy: hardening summary and failed hardening cases only. Execute copy: execute summary and failed execute cases only.</span>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-parser-test-metrics">';
			html += renderMetricCard('Total tests', summary.total || 0, '');
			html += renderMetricCard('Passed', summary.passed || 0, '');
			html += renderMetricCard('Failed', summary.failed || 0, '');
			html += renderMetricCard('Clarify rate', evaluation.clarify_rate || '0.0%', 'Evaluation only');
			html += renderMetricCard('Wrong action', evaluation.wrong_action_rate || '0.0%', 'Evaluation only');
			html += renderMetricCard('Wrong intent', evaluation.wrong_intent_rate || '0.0%', 'Evaluation only');
			html += '</div>';

			if (topDomains.length) {
				html += '<div class="yoro-parser-test-section">';
				html += '<h4>By domain</h4>';
				html += '<table class="widefat striped yoro-parser-test-table"><thead><tr><th>Domain</th><th>Total</th><th>Passed</th><th>Failed</th></tr></thead><tbody>';
				topDomains.forEach(function (key) {
					const row = byDomain[key] || {};
					html += '<tr><td>' + escapeHtml(key) + '</td><td>' + escapeHtml(row.total || 0) + '</td><td>' + escapeHtml(row.passed || 0) + '</td><td>' + escapeHtml(row.failed || 0) + '</td></tr>';
				});
				html += '</tbody></table></div>';
			}

			if (topOutcomes.length || topSources.length) {
				html += '<div class="yoro-parser-test-grid">';

				if (topOutcomes.length) {
					html += '<div class="yoro-parser-test-section">';
					html += '<h4>Outcomes</h4>';
					html += '<table class="widefat striped yoro-parser-test-table"><thead><tr><th>Outcome</th><th>Total</th><th>Passed</th><th>Failed</th></tr></thead><tbody>';
					topOutcomes.forEach(function (key) {
						const row = byOutcome[key] || {};
						html += '<tr><td>' + escapeHtml(key) + '</td><td>' + escapeHtml(row.total || 0) + '</td><td>' + escapeHtml(row.passed || 0) + '</td><td>' + escapeHtml(row.failed || 0) + '</td></tr>';
					});
					html += '</tbody></table></div>';
				}

				if (topSources.length) {
					html += '<div class="yoro-parser-test-section">';
					html += '<h4>Sources</h4>';
					html += '<table class="widefat striped yoro-parser-test-table"><thead><tr><th>Source</th><th>Total</th><th>Passed</th><th>Failed</th></tr></thead><tbody>';
					topSources.forEach(function (key) {
						const row = bySource[key] || {};
						html += '<tr><td>' + escapeHtml(key) + '</td><td>' + escapeHtml(row.total || 0) + '</td><td>' + escapeHtml(row.passed || 0) + '</td><td>' + escapeHtml(row.failed || 0) + '</td></tr>';
					});
					html += '</tbody></table></div>';
				}

				html += '</div>';
			}

			html += '<details class="yoro-parser-test-raw"><summary>Raw test response</summary>';
			html += '<pre class="yoro-json-output">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';
			html += '</details>';
			html += '</div>';

			return html;
		}

		function buildRealQueryPack() {
			realQueryPackButton.disabled = true;
			realQueryPackButton.textContent = 'Building...';
			realQueryPackResults.innerHTML = '<div class="yoro-parser-test-loading">Building real-query evaluation pack...</div>';

			requestJson(YoRoAgentAdmin.realQueryPackUrl, {
				method: 'GET'
			})
				.then(function (data) {
					realQueryPackResults.innerHTML = renderRealQueryPackResults(data);
				})
				.catch(function (error) {
					realQueryPackResults.innerHTML = renderParserTestError(error);
				})
				.finally(function () {
					realQueryPackButton.disabled = false;
					realQueryPackButton.textContent = 'Build Real-Query Pack';
				});
		}

		function renderRealQueryPackResults(data) {
			const byIntent = data && data.by_intent ? data.by_intent : {};
			const bySource = data && data.by_source ? data.by_source : {};
			const byStatus = data && data.by_status ? data.by_status : {};
			const topQueries = data && Array.isArray(data.queries) ? data.queries : [];
			const optimizedPayload = escapeHtml(JSON.stringify({
				sample_size: data && data.sample_size ? data.sample_size : 0,
				deduped_size: data && data.deduped_size ? data.deduped_size : 0,
				by_intent: byIntent,
				by_source: bySource,
				by_status: byStatus,
				queries: topQueries,
				suggested_cases: data && Array.isArray(data.suggested_cases) ? data.suggested_cases : []
			}, null, 2));

			let html = '<div class="yoro-parser-test-panel">';
			html += '<div class="yoro-parser-test-head">';
			html += '<h3>Real-Query Evaluation Pack</h3>';
			html += '<p>Recent deduplicated user commands harvested from YoRo history for benchmark expansion.</p>';
			html += '<div class="yoro-parser-test-toolbar">';
			html += '<button type="button" class="button button-secondary yoro-real-pack-copy-json" data-copy-payload="' + optimizedPayload + '">Copy real-query JSON</button>';
			html += '<span class="yoro-parser-test-toolbar-note">Includes deduped commands, source mix, intent mix, and suggested test cases.</span>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-parser-test-metrics">';
			html += renderMetricCard('Samples scanned', data && data.sample_size ? data.sample_size : 0, '');
			html += renderMetricCard('Deduped queries', data && data.deduped_size ? data.deduped_size : 0, '');
			html += renderMetricCard('Top intents', Object.keys(byIntent).length, '');
			html += renderMetricCard('Top sources', Object.keys(bySource).length, '');
			html += '</div>';

			html += '<div class="yoro-parser-test-grid">';
			html += renderCompactCountTable('Intent mix', byIntent, 'Intent');
			html += renderCompactCountTable('Source mix', bySource, 'Source');
			html += renderCompactCountTable('Status mix', byStatus, 'Status');
			html += '</div>';

			if (topQueries.length) {
				html += '<div class="yoro-parser-test-section">';
				html += '<h4>Recent deduped commands</h4>';
				html += '<table class="widefat striped yoro-parser-test-table"><thead><tr><th>Command</th><th>Intent</th><th>Action</th><th>Source</th><th>Status</th></tr></thead><tbody>';
				topQueries.slice(0, 15).forEach(function (row) {
					html += '<tr>';
					html += '<td><code>' + escapeHtml(row.command || '') + '</code></td>';
					html += '<td>' + escapeHtml(row.intent || '—') + '</td>';
					html += '<td>' + escapeHtml(row.action || '—') + '</td>';
					html += '<td>' + escapeHtml(row.parser_source || '—') + '</td>';
					html += '<td>' + escapeHtml(row.status || '—') + '</td>';
					html += '</tr>';
				});
				html += '</tbody></table></div>';
			}

			html += '</div>';
			return html;
		}

		function renderCompactCountTable(title, counts, firstColumnLabel) {
			const keys = Object.keys(counts || {});
			let html = '<div class="yoro-parser-test-section">';
			html += '<h4>' + escapeHtml(title) + '</h4>';
			html += '<table class="widefat striped yoro-parser-test-table"><thead><tr><th>' + escapeHtml(firstColumnLabel) + '</th><th>Total</th></tr></thead><tbody>';

			keys.forEach(function (key) {
				html += '<tr><td>' + escapeHtml(key) + '</td><td>' + escapeHtml(counts[key] || 0) + '</td></tr>';
			});

			if (!keys.length) {
				html += '<tr><td colspan="2">No data</td></tr>';
			}

			html += '</tbody></table></div>';
			return html;
		}

		function buildOptimizedParserPayload(data) {
			const evaluationResults = data && data.groups && Array.isArray(data.groups.evaluation)
				? data.groups.evaluation
				: [];

			return {
				summary: data && data.summary ? data.summary : {},
				group_meta: {
					evaluation: data && data.group_meta && data.group_meta.evaluation
						? data.group_meta.evaluation
						: {}
				},
				evaluation_failures: evaluationResults
					.filter(function (item) {
						return item && item.passed === false;
					})
					.map(function (item) {
						return {
							name: item.name || '',
							command: item.command || '',
							domain: item.domain || '',
							outcome: item.outcome || '',
							expected_intent: item.expected_intent || '',
							expected_action: item.expected_action || '',
							trace: item.trace || {},
							assertions: item.assertions || {}
						};
					})
			};
		}

		function buildHardeningFailurePayload(data) {
			const hardeningResults = data && data.groups && Array.isArray(data.groups.hardening)
				? data.groups.hardening
				: [];
			const hardeningSummary = data && data.summary && data.summary.groups && data.summary.groups.hardening
				? data.summary.groups.hardening
				: {};
			const hardeningMeta = data && data.group_meta && data.group_meta.hardening
				? data.group_meta.hardening
				: {};

			return {
				summary: {
					hardening: hardeningSummary
				},
				group_meta: {
					hardening: hardeningMeta
				},
				hardening_failures: hardeningResults
					.filter(function (item) {
						return item && item.passed === false;
					})
					.map(function (item) {
						return {
							name: item.name || '',
							command: item.command || '',
							domain: item.domain || '',
							outcome: item.outcome || '',
							expected_intent: item.expected_intent || '',
							expected_action: item.expected_action || '',
							trace: item.trace || {},
							assertions: item.assertions || {}
						};
					})
			};
		}

		function buildExecuteFailurePayload(data) {
			const executeResults = data && data.groups && Array.isArray(data.groups.execute)
				? data.groups.execute
				: [];
			const executeSummary = data && data.summary && data.summary.groups && data.summary.groups.execute
				? data.summary.groups.execute
				: {};

			return {
				summary: {
					execute: executeSummary
				},
				execute_failures: executeResults
					.filter(function (item) {
						return item && item.passed === false;
					})
					.map(function (item) {
						return {
							name: item.name || '',
							command: item.command || '',
							assertions: item.assertions || {},
							actual: {
								parsed: item.actual && item.actual.parsed ? item.actual.parsed : null,
								plan: item.actual && item.actual.plan ? item.actual.plan : null,
								result: item.actual && item.actual.result ? item.actual.result : null
							}
						};
					})
			};
		}

		function copyParserPayload(payload, button) {
			const onSuccess = function () {
				const previous = button.textContent;
				button.textContent = 'Copied';
				button.disabled = true;

				window.setTimeout(function () {
					button.textContent = previous;
					button.disabled = false;
				}, 1400);
			};

			if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
				navigator.clipboard.writeText(payload).then(onSuccess).catch(function () {
					fallbackCopyText(payload, onSuccess);
				});
				return;
			}

			fallbackCopyText(payload, onSuccess);
		}

		function fallbackCopyText(payload, onSuccess) {
			const textarea = document.createElement('textarea');
			textarea.value = payload;
			textarea.setAttribute('readonly', 'readonly');
			textarea.style.position = 'absolute';
			textarea.style.left = '-9999px';
			document.body.appendChild(textarea);
			textarea.select();

			try {
				document.execCommand('copy');
				onSuccess();
			} finally {
				document.body.removeChild(textarea);
			}
		}

		function renderMetricCard(label, value, note) {
			let html = '<div class="yoro-parser-test-metric">';
			html += '<strong>' + escapeHtml(value) + '</strong>';
			html += '<span>' + escapeHtml(label) + '</span>';
			if (note) {
				html += '<em>' + escapeHtml(note) + '</em>';
			}
			html += '</div>';
			return html;
		}

		commandField.addEventListener('input', function () {
			if (commandField.value.trim() === '') {
				clearCommandGhostHint();
			}
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

		function openModal(title, bodyHtml, onConfirm) {
			const titleEl = modal.querySelector('.yoro-modal-title');
			const bodyEl = modal.querySelector('.yoro-modal-body');
			const confirmBtn = modal.querySelector('.yoro-modal-confirm');

			titleEl.textContent = title;
			bodyEl.innerHTML = bodyHtml;

			modal.classList.remove('hidden');

			function close() {
				modal.classList.add('hidden');
				confirmBtn.onclick = null;
			}

			modal.querySelector('.yoro-modal-cancel').onclick = close;
			modal.querySelector('.yoro-modal-overlay').onclick = close;

			confirmBtn.onclick = function () {
				if (typeof onConfirm === 'function') {
					onConfirm();
				}
				close();
			};
		}

		let conversationState = {
			last_orders: [],
			last_order_ids: [],
			last_command: '',
			last_action: '',
			last_result_type: '',
			active_followup_type: '',
			last_result: {},
			last_briefing: {},
			last_business_followup: {},
			last_attention_followup: {},
			last_service_followup: {},
			last_fulfillment_followup: {},
			last_inventory_followup: {},
			morning_briefing_state: {},
			last_referenced_order_id: 0,
			current_order_id: 0,
			current_workflow: {},
			pending_confirm_command: '',
			pending_plan_token: ''
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

				if (!logId || !window.YoRoAgentAdmin || !YoRoAgentAdmin.undoUrl) {
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
							'Undo failed.'
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

		function addMessage(type, html) {
			const isUser = type === 'user';

			const wrapper = document.createElement('div');
			wrapper.className = 'yoro-chat-message ' + (isUser ? 'yoro-chat-message-user' : 'yoro-chat-message-agent');

			wrapper.innerHTML = `
				<div class="yoro-chat-avatar">${isUser ? 'U' : 'Y'}</div>
				<div class="yoro-chat-bubble">
					${html}
				</div>
			`;

			chatThread.appendChild(wrapper);

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

		function hideSupersededAgentMessages(wrapper, data) {
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
					'.yoro-chat-message-agent[data-yoro-display-type="customer_email_drafts"][data-yoro-order-id="' + orderId + '"]'
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
					'.yoro-chat-message-agent[data-yoro-display-type="email_templates_list"]'
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

		function addAgentMessage(data) {
			const wrapper = addMessage(
				'agent',
				resultUI
					? resultUI.renderResult(data)
					: renderJsonFallback(data)
			);

			hideSupersededAgentMessages(wrapper, data);
		}

		function addLoadingMessage() {
			return addMessage(
				'agent',
				'<div class="yoro-typing"><span></span><span></span><span></span></div>'
			);
		}

		function getResultSuggestions(data) {
			const result = data && data.result ? data.result : {};
			const parsed = data && data.parsed ? data.parsed : {};
			const action = result.action || parsed.action || '';
			if (result.display_type === 'single_action') {
				return [];
			}

			if (
				data &&
				data.stage === 'done' &&
				result &&
				result.read_only === false
			) {
				return [];
			}

			if (Array.isArray(result.suggestions)) {
				return result.suggestions;
			}

			if (Array.isArray(data.suggestions)) {
				return data.suggestions;
			}

			return [];
		}

		function setLoading(isLoading) {
			runButton.disabled = isLoading;

			if (contextButton) {
				contextButton.disabled = isLoading;
			}

			if (previewButton) {
				previewButton.disabled = isLoading;
			}

			runButton.textContent = isLoading ? YoRoAgentAdmin.i18n.running : 'Send';
		}

		function requestJson(url, options) {
			const requestOptions = Object.assign({
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': YoRoAgentAdmin.nonce
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
			return requestJson(YoRoAgentAdmin.undoUrl, {
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

			setLoading(true);

			requestJson(YoRoAgentAdmin.contextUrl, {
				method: 'GET'
			})
				.then(function (data) {
					loading.remove();
					addAgentMessage(data);
				})
				.catch(function (error) {
					loading.remove();
					addAgentMessage({
						success: false,
						error: error || YoRoAgentAdmin.i18n.error
					});
				})
				.finally(function () {
					setLoading(false);
				});
		}

		function loadServerMemory() {
			return requestJson(YoRoAgentAdmin.memoryUrl, {
				method: 'GET'
			}).then(function (data) {
				const runtimeContext = data && data.success
					? (data.runtime_context || data.memory || null)
					: null;

				if (runtimeContext) {
					conversationState = Object.assign(conversationState, runtimeContext);
					return runtimeContext;
				}
			});
		}

		function loadMorningBriefing() {
			if (!window.YoRoAgentAdmin || !YoRoAgentAdmin.morningBriefingUrl) {
				return Promise.resolve();
			}

			const params = new URLSearchParams(window.location.search || '');
			const forceWindow = params.get('yoro_force_morning_window') === '1';
			const resetState = params.get('yoro_reset_morning_briefing') === '1';
			const requestParams = new URLSearchParams();

			if (forceWindow) {
				requestParams.set('force_window', '1');
			}

			if (resetState) {
				requestParams.set('reset_state', '1');
			}

			const url = requestParams.toString()
				? (YoRoAgentAdmin.morningBriefingUrl + '?' + requestParams.toString())
				: YoRoAgentAdmin.morningBriefingUrl;

			return requestJson(url, {
				method: 'GET'
			}).then(function (data) {
				if (!data || !data.success || !data.should_show) {
					return data;
				}

				addAgentMessage(data);
				updateConversationState(data, 'Morning briefing');

				return data;
			}).catch(function () {
				return null;
			});
		}


		function dismissMorningBriefing() {
			if (!window.YoRoAgentAdmin || !YoRoAgentAdmin.morningBriefingDismissUrl) {
				return Promise.resolve();
			}

			return requestJson(YoRoAgentAdmin.morningBriefingDismissUrl, {
				method: 'POST',
				body: JSON.stringify({})
			}).then(function (data) {
				if (data && data.success) {
					conversationState.morning_briefing_state = Object.assign({}, conversationState.morning_briefing_state || {}, {
						last_dismissed_date: data.date || ''
					});
				}

				return data;
			});
		}

		function updateConversationState(data, command) {
			if (!data) {
				return;
			}

			const result = data.result || {};
			const parsed = data.parsed || {};
			const adminCopilot = data.admin_copilot || (result.meta && result.meta.admin_copilot) || null;

			conversationState.last_command = command || '';
			conversationState.last_action = parsed.action || result.action || '';

			if (adminCopilot && typeof adminCopilot === 'object') {
				conversationState.surface = 'admin_copilot';
				conversationState.admin_copilot = adminCopilot;
			}

			if (Array.isArray(result.orders)) {
				conversationState.last_orders = result.orders.map(function (order) {
					return {
						id: parseInt(order.id, 10),
						number: order.number || String(order.id),
						status: order.status || ''
					};
				});

				conversationState.last_order_ids = conversationState.last_orders
					.map(function (order) {
						return parseInt(order.id || 0, 10);
					})
					.filter(function (id) {
						return id > 0;
					});

				if (conversationState.last_orders.length && conversationState.last_orders[0].id) {
					conversationState.last_referenced_order_id = parseInt(conversationState.last_orders[0].id, 10);
					conversationState.current_order_id = parseInt(conversationState.last_orders[0].id, 10);
				}

				conversationState.last_result_type = 'orders';
			}

			if (result.result_type === 'daily_briefing') {
				conversationState.last_result_type = 'briefing';
				conversationState.active_followup_type = 'briefing';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					briefing_label: result.briefing_label || '',
					briefing_context: result.briefing_context || {},
					priority_drilldowns: Array.isArray(result.priority_drilldowns) ? result.priority_drilldowns : [],
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					briefing_actions: Array.isArray(result.briefing_actions) ? result.briefing_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_briefing = conversationState.last_result;
			}

			if (result.result_type === 'business_summary') {
				conversationState.last_result_type = 'business';
				conversationState.active_followup_type = 'business';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					business_context: result.business_context || {},
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					business_actions: Array.isArray(result.business_actions) ? result.business_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_business_followup = conversationState.last_result;
			}

			if (result.result_type === 'operations_attention_queue') {
				conversationState.last_result_type = 'operations_attention';
				conversationState.active_followup_type = 'operations_attention';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					attention_context: result.attention_context || {},
					queues: Array.isArray(result.queues) ? result.queues : [],
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					attention_actions: Array.isArray(result.attention_actions) ? result.attention_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_attention_followup = conversationState.last_result;
			}

			if (result.result_type === 'customer_service_queue') {
				conversationState.last_result_type = 'customer_service';
				conversationState.active_followup_type = 'customer_service';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					service_context: result.service_context || {},
					queues: Array.isArray(result.queues) ? result.queues : [],
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					service_actions: Array.isArray(result.service_actions) ? result.service_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_service_followup = conversationState.last_result;
			}

			if (result.result_type === 'fulfillment_queue') {
				conversationState.last_result_type = 'fulfillment';
				conversationState.active_followup_type = 'fulfillment';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					fulfillment_context: result.fulfillment_context || {},
					queues: Array.isArray(result.queues) ? result.queues : [],
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					fulfillment_actions: Array.isArray(result.fulfillment_actions) ? result.fulfillment_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_fulfillment_followup = conversationState.last_result;
			}

			if (result.result_type === 'inventory_queue') {
				conversationState.last_result_type = 'inventory';
				conversationState.active_followup_type = 'inventory';
				conversationState.last_result = {
					result_type: result.result_type || '',
					display_type: result.display_type || '',
					inventory_context: result.inventory_context || {},
					queues: Array.isArray(result.queues) ? result.queues : [],
					recommended_sequence: Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [],
					inventory_actions: Array.isArray(result.inventory_actions) ? result.inventory_actions : [],
					priority_items: Array.isArray(result.priority_items) ? result.priority_items : [],
					recommendation_cursor: -1,
					last_recommendation_action: '',
					last_recommendation_command: ''
				};
				conversationState.last_inventory_followup = conversationState.last_result;
			}

			if (result.result_type === 'morning_briefing') {
				conversationState.morning_briefing_state = Object.assign({}, conversationState.morning_briefing_state || {}, {
					last_shown_date: result.briefing_date || '',
					last_signature: result.signature || ''
				});
			}

			if (
				parsed.parser_rule === 'rule.follow_up.briefing.recommendation' &&
				conversationState.last_briefing &&
				typeof conversationState.last_briefing === 'object'
			) {
				let sequenceIndex = parseInt(parsed.briefing_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_briefing.recommended_sequence)
						? conversationState.last_briefing.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_briefing.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'briefing'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_briefing.last_recommendation_action = parsed.action || '';
				conversationState.last_briefing.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'briefing';
			}

			if (
				parsed.parser_rule === 'rule.follow_up.business.recommendation' &&
				conversationState.last_business_followup &&
				typeof conversationState.last_business_followup === 'object'
			) {
				let sequenceIndex = parseInt(parsed.business_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_business_followup.recommended_sequence)
						? conversationState.last_business_followup.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_business_followup.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'business'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_business_followup.last_recommendation_action = parsed.action || '';
				conversationState.last_business_followup.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'business';
			}

			if (
				parsed.parser_rule === 'rule.follow_up.attention.recommendation' &&
				conversationState.last_attention_followup &&
				typeof conversationState.last_attention_followup === 'object'
			) {
				let sequenceIndex = parseInt(parsed.attention_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_attention_followup.recommended_sequence)
						? conversationState.last_attention_followup.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_attention_followup.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'operations_attention'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_attention_followup.last_recommendation_action = parsed.action || '';
				conversationState.last_attention_followup.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'operations_attention';
			}

			if (
				parsed.parser_rule === 'rule.follow_up.service.recommendation' &&
				conversationState.last_service_followup &&
				typeof conversationState.last_service_followup === 'object'
			) {
				let sequenceIndex = parseInt(parsed.service_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_service_followup.recommended_sequence)
						? conversationState.last_service_followup.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_service_followup.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'customer_service'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_service_followup.last_recommendation_action = parsed.action || '';
				conversationState.last_service_followup.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'customer_service';
			}

			if (
				parsed.parser_rule === 'rule.follow_up.fulfillment.recommendation' &&
				conversationState.last_fulfillment_followup &&
				typeof conversationState.last_fulfillment_followup === 'object'
			) {
				let sequenceIndex = parseInt(parsed.fulfillment_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_fulfillment_followup.recommended_sequence)
						? conversationState.last_fulfillment_followup.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_fulfillment_followup.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'fulfillment'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_fulfillment_followup.last_recommendation_action = parsed.action || '';
				conversationState.last_fulfillment_followup.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'fulfillment';
			}

			if (
				parsed.parser_rule === 'rule.follow_up.inventory.recommendation' &&
				conversationState.last_inventory_followup &&
				typeof conversationState.last_inventory_followup === 'object'
			) {
				let sequenceIndex = parseInt(parsed.inventory_sequence_index, 10);

				if (Number.isNaN(sequenceIndex)) {
					const resolvedCommand = String(parsed.resolved_followup_command || '').toLowerCase();
					const sequence = Array.isArray(conversationState.last_inventory_followup.recommended_sequence)
						? conversationState.last_inventory_followup.recommended_sequence
						: [];

					if (resolvedCommand && sequence.length) {
						const foundIndex = sequence.findIndex(function (item) {
							return item && String(item.command || '').toLowerCase() === resolvedCommand;
						});

						if (foundIndex >= 0) {
							sequenceIndex = foundIndex;
						}
					}
				}

				if (!Number.isNaN(sequenceIndex) && sequenceIndex >= 0) {
					conversationState.last_inventory_followup.recommendation_cursor = sequenceIndex;

					if (
						conversationState.last_result &&
						typeof conversationState.last_result === 'object' &&
						conversationState.last_result_type === 'inventory'
					) {
						conversationState.last_result.recommendation_cursor = sequenceIndex;
					}
				}

				conversationState.last_inventory_followup.last_recommendation_action = parsed.action || '';
				conversationState.last_inventory_followup.last_recommendation_command = parsed.resolved_followup_command || '';
				conversationState.active_followup_type = 'inventory';
			}

			if (result.order && result.order.id) {
				conversationState.current_order_id = parseInt(result.order.id, 10);
				conversationState.last_referenced_order_id = parseInt(result.order.id, 10);
			}

			const hasWorkflowState =
				(result.workflow && typeof result.workflow === 'object') ||
				(data.current_workflow && typeof data.current_workflow === 'object') ||
				(parsed.current_workflow && typeof parsed.current_workflow === 'object');

			if (result.workflow) {
				conversationState.current_workflow = result.workflow;
			}

			if (data.current_workflow) {
				conversationState.current_workflow = data.current_workflow;
			}

			if (parsed.current_workflow) {
				conversationState.current_workflow = parsed.current_workflow;
			}

			if (
				result.action === 'orders.send_customer_email' &&
				result.success
			) {
				conversationState.current_workflow = {};
			}

			if (
				result.action === 'workflow.cancel' &&
				result.success
			) {
				conversationState.current_workflow = {};
			}

			if (
				data.stage === 'done' &&
				!hasWorkflowState
			) {
				conversationState.current_workflow = {};
			}
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

		let isSending = false;

		function runCommand(confirm, preview) {
			if (isSending) {
				return;
			}

			const command = commandField.value.trim();
			clearCommandGhostHint();

			if (!command) {
				addAgentMessage({
					success: false,
					message: YoRoAgentAdmin.i18n.emptyCommand
				});
				return;
			}

			if (!canSubmit()) {
				return;
			}

			isSending = true;
			conversationState.pending_confirm_command = command;

			if (!confirm) {
				addUserMessage(command);

				// Clear composer immediately after the message is sent.
				if (!preview) {
					commandField.value = '';
				}
			}

			const loading = addLoadingMessage();

			setLoading(true);

			requestJson(YoRoAgentAdmin.runUrl, {
				method: 'POST',
				body: JSON.stringify({
					command: command,
					confirm: confirm || false,
					preview: preview || false,
					workflow_state_token: confirm ? (conversationState.pending_plan_token || '') : '',
					client_context: conversationState
				})
			})
			.then(function (data) {
				loading.remove();

				if (data && data.workflow_state_token) {
					conversationState.pending_plan_token = data.workflow_state_token;
				}

				addAgentMessage(data);
				updateConversationState(data, command);

				if (data && data.stage === 'done') {
					conversationState.pending_plan_token = '';
				}
			})
			.catch(function (error) {
				loading.remove();

				addAgentMessage({
					success: false,
					error: error || YoRoAgentAdmin.i18n.error
				});
			})
			.finally(function () {
				setLoading(false);
				isSending = false;
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
			openModal(
				'Add internal note',
				'<textarea class="yoro-modal-textarea" placeholder="Enter note..." rows="4"></textarea>',
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
				(window.YoRoAgentAdmin && Array.isArray(YoRoAgentAdmin.orderStatuses))
					? YoRoAgentAdmin.orderStatuses
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

		function openStatusNoteModal(callback) {
			openModal(
				'Change status and add note',
				renderStatusSelectHtml() +
				'<textarea class="yoro-modal-textarea" placeholder="Enter internal note..." rows="4"></textarea>',
				function () {
					const status = modal.querySelector('.yoro-modal-select').value;
					const note = modal.querySelector('.yoro-modal-textarea').value.trim();

					if (status && note && typeof callback === 'function') {
						callback(status, note);
					}
				}
			);
		}

		function runCommandFromText(command, confirm, preview) {
			commandField.value = command;
			runCommand(confirm || false, preview || false);
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

		function executeActionDirect(action, params, confirm, preview, surface, actionStateToken) {
			if (confirm && !preview && !actionStateToken) {
				return executeActionDirect(action, params, false, false, surface)
					.then(function(data) {
						const token = data && (data.action_state_token || data.pending_action_token);

						if (data && data.stage === 'confirm' && token) {
							return executeActionDirect(action, params, true, false, surface, token);
						}

						return data;
					});
			}

			const payload = {
				action: action,
				params: params || {},
				confirm: confirm || false,
				preview: preview || false,
				surface: surface || conversationState.surface || ''
			};

			if (actionStateToken) {
				payload.action_state_token = actionStateToken;
			}

			return requestJson(YoRoAgentAdmin.executeUrl, {
				method: 'POST',
				body: JSON.stringify(payload)
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
			return !!(window.YoRoAgentAdmin && YoRoAgentAdmin.debug);
		}

		function renderJsonFallback(data) {
			return '<pre class="yoro-json-output">' + escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';
		}

		function renderDebugJsonProxy(data) {
			return resultUI ? resultUI.renderDebugJson(data) : '';
		}

		function renderSuggestionsProxy(suggestions) {
			return resultUI ? resultUI.renderSuggestions(suggestions) : '';
		}

		function renderJsonProxy(data) {
			return resultUI
				? resultUI.renderJson(data)
				: renderJsonFallback(data);
		}

		runButton.addEventListener('click', function () {
			runCommand(false, false);
		});

		commandField.addEventListener('keydown', function (event) {
			if (event.key === 'Enter' && !event.shiftKey) {

				if (event.repeat) {
					return;
				}
				
				event.preventDefault();
				runCommand(false, false);
			}
		});

		if (previewButton) {
			previewButton.addEventListener('click', function () {
				runCommand(false, true);
			});
		}

		if (contextButton) {
			contextButton.addEventListener('click', function () {
				loadContext();
			});
		}

		clearButton.addEventListener('click', function () {
			chatThread.innerHTML = '';
			addMessage(
				'agent',
				'<strong>YoRo</strong><p>Chat cleared. How can I help manage your store?</p>'
			);
			commandField.value = '';
				conversationState = {
					surface: '',
					admin_copilot: null,
					last_orders: [],
					last_order_ids: [],
					last_command: '',
					last_action: '',
					last_result_type: '',
					active_followup_type: '',
					last_result: {},
					last_briefing: {},
					last_business_followup: {},
					last_attention_followup: {},
					last_service_followup: {},
					last_fulfillment_followup: {},
					last_inventory_followup: {},
					morning_briefing_state: {},
					last_referenced_order_id: 0,
					current_order_id: 0,
					current_workflow: {},
					pending_confirm_command: '',
					pending_plan_token: ''
				};

			clearCommandGhostHint();
			commandField.focus();
		});

		const emailUI = window.YoRoEmailUI
			? window.YoRoEmailUI.init({
				escapeHtml: escapeHtml,
				renderDebugJson: renderDebugJsonProxy,
				getResultSuggestions: getResultSuggestions,
				resolveOrderId: resolveOrderId,
				setCurrentOrderId: setCurrentOrderId,
				executeActionDirect: executeActionDirect,
				addAgentMessage: addAgentMessage,
				addLoadingMessage: addLoadingMessage,
				updateConversationState: updateConversationState,
				runCommandFromText: runCommandFromText,
				openModal: openModal
			})
			: null;

		const orderUI = window.YoRoOrderUI
			? window.YoRoOrderUI.init({
				escapeHtml: escapeHtml,
				renderDebugJson: renderDebugJsonProxy,
				renderSuggestions: renderSuggestionsProxy,
				getResultSuggestions: getResultSuggestions,
				getConversationState: function() {
					return conversationState;
				},
				addAgentMessage: addAgentMessage,
				addLoadingMessage: addLoadingMessage,
				updateConversationState: updateConversationState,
				runCommandFromText: runCommandFromText,
				openModal: openModal,
				openNoteModal: openNoteModal,
				openStatusModal: openStatusModal,
				openStatusNoteModal: openStatusNoteModal,
				executeActionDirect: executeActionDirect,
				setCurrentOrderId: setCurrentOrderId,
				getSelectedOrderIds: getSelectedOrderIds
			})
			: null;

		resultUI = window.YoRoResultRenderers
			? window.YoRoResultRenderers.init({
				escapeHtml: escapeHtml,
				shouldShowDebugJson: shouldShowDebugJson,
				getResultSuggestions: getResultSuggestions,
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
				renderOperationsSummaryResult: orderUI
					? orderUI.renderOperationsSummaryResult
					: null,
				renderBusinessSummaryResult: orderUI
					? orderUI.renderBusinessSummaryResult
					: null,
				renderMorningBriefingResult: orderUI
					? orderUI.renderMorningBriefingResult
					: null,
				renderDailyBriefingResult: orderUI
					? orderUI.renderDailyBriefingResult
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
				renderStoreInsightsResult: orderUI
					? orderUI.renderStoreInsightsResult
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
			
		chatThread.addEventListener('click', function (event) {
			const target = event.target;

			const suggestionBtn = target.closest('.yoro-suggestion-btn');
			const clarifyOption = target.closest('.yoro-clarify-option');
			const confirmRun = target.closest('.yoro-confirm-run');
			const confirmCancel = target.closest('.yoro-confirm-cancel');
			const undoLast = target.closest('.yoro-undo-last');
			const insertCommand = target.closest('.yoro-insert-command');
			const dismissMorningBriefingBtn = target.closest('.yoro-dismiss-morning-briefing');

			/*
			* Handle core chat buttons first.
			* This prevents nested elements inside buttons from being missed
			* and avoids email/order UI handlers swallowing suggestion clicks.
			*/
			if (suggestionBtn) {
				event.preventDefault();
				event.stopPropagation();

				const command = suggestionBtn.getAttribute('data-command') || '';
				const requiresInput = suggestionBtn.getAttribute('data-requires-input') === '1';

				if (!command) {
					return;
				}

				if (requiresInput) {
					commandField.value = command;
					commandField.focus();

					if (commandField.setSelectionRange) {
						commandField.setSelectionRange(command.length, command.length);
					}

					showCommandGhostHint('Type the note after the colon, then press Send.');
					return;
				}

				runCommandFromText(command, false, false);
				return;
			}

			if (clarifyOption) {
				event.preventDefault();

				const command = clarifyOption.getAttribute('data-command') || '';

				if (command) {
					commandField.value = command;
					runCommand(false, false);
				}

				return;
			}

			if (confirmRun) {
				event.preventDefault();

				const directAction = confirmRun.getAttribute('data-direct-action') || '';
				const directParamsRaw = confirmRun.getAttribute('data-direct-params') || '';
				const directActionStateToken = confirmRun.getAttribute('data-action-state-token') || '';

				if (directAction) {
					let directParams = {};

					if (directParamsRaw) {
						try {
							directParams = JSON.parse(directParamsRaw);
						} catch (error) {
							addAgentMessage({
								success: false,
								message: 'Could not confirm this action because the saved action payload is invalid.'
							});
							return;
						}
					}

					executeActionDirect(directAction, directParams, true, false, '', directActionStateToken)
						.then(function(data) {
							addAgentMessage(data);
							updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || directAction));
						})
						.catch(function(error) {
							addAgentMessage({
								success: false,
								error: error
							});
						});

					return;
				}

				const command = conversationState.pending_confirm_command || commandField.value.trim();

				if (command) {
					commandField.value = command;
					runCommand(true, false);
					conversationState.pending_confirm_command = '';
				}

				return;
			}

			if (confirmCancel) {
				event.preventDefault();

				conversationState.pending_confirm_command = '';
				conversationState.pending_plan_token = '';
				addMessage('agent', '<p>Action cancelled.</p>');

				return;
			}

			if (undoLast) {
				event.preventDefault();

				const logId = undoLast.getAttribute('data-log-id') || '';

				if (!logId) {
					addAgentMessage({
						success: false,
						message: 'Undo log ID is missing.'
					});
					return;
				}

				undoLog(logId)
					.then(function (data) {
						addAgentMessage({
							success: true,
							message: data.message || 'Undo completed',
							stage: 'undo',
							result: data
						});
					})
					.catch(function (error) {
						addAgentMessage({
							success: false,
							error: error
						});
					});

				return;
			}

			if (insertCommand) {
				event.preventDefault();

				commandField.value = insertCommand.getAttribute('data-command') || '';
				commandField.focus();

				return;
			}

			if (dismissMorningBriefingBtn) {
				event.preventDefault();

				dismissMorningBriefing()
					.then(function () {
						addMessage('agent', '<p>Morning briefing dismissed for today.</p>');
					})
					.catch(function () {
						addAgentMessage({
							success: false,
							message: 'Could not dismiss the morning briefing.'
						});
					});

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

		exampleButtons.forEach(function (button) {
			button.addEventListener('click', function () {
				commandField.value = button.getAttribute('data-command') || '';
				commandField.focus();
			});
		});

		loadServerMemory()
			.then(function () {
				return loadMorningBriefing();
			})
			.catch(function () {
				// Silent fail.
			});
	});
})();
