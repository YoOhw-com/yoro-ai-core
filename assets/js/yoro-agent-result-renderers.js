(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
			shouldShowDebugJson: helpers.shouldShowDebugJson,
			getResultSuggestions: helpers.getResultSuggestions,
			renderWorkflowPreview: helpers.renderWorkflowPreview,
			renderMultiActionResult: helpers.renderMultiActionResult,
			renderOrderDetailsResult: helpers.renderOrderDetailsResult,
			renderOrdersResult: helpers.renderOrdersResult,
			renderCustomerSummaryResult: helpers.renderCustomerSummaryResult,
			renderOperationsSummaryResult: helpers.renderOperationsSummaryResult,
			renderBusinessSummaryResult: helpers.renderBusinessSummaryResult,
			renderMorningBriefingResult: helpers.renderMorningBriefingResult,
			renderDailyBriefingResult: helpers.renderDailyBriefingResult,
			renderOperationsAttentionQueueResult: helpers.renderOperationsAttentionQueueResult,
			renderFulfillmentQueueResult: helpers.renderFulfillmentQueueResult,
			renderInventoryQueueResult: helpers.renderInventoryQueueResult,
			renderStoreInsightsResult: helpers.renderStoreInsightsResult,
			renderCustomerServiceQueueResult: helpers.renderCustomerServiceQueueResult,
			renderRefundPreviewResult: helpers.renderRefundPreviewResult,
			renderCustomerEmailPreviewResult: helpers.renderCustomerEmailPreviewResult,
			renderEmailTemplatesResult: helpers.renderEmailTemplatesResult,
			renderCustomerEmailDraftsResult: helpers.renderCustomerEmailDraftsResult
		};
	}

	function init(helpers) {
		const h = getHelpers(helpers);

		return {
			renderResult: function(data) {
				return renderResult(data, h);
			},
			renderJson: function(data) {
				return renderJson(data, h);
			},
			renderDebugJson: function(data) {
				return renderDebugJson(data, h);
			},
			renderSuggestions: function(suggestions) {
				return renderSuggestions(suggestions, h);
			}
		};
	}

	function renderResult(data, h) {
		if (!data) {
			return '';
		}

		if (data.stage === 'confirm' && data.confirm_required) {
			return renderConfirmation(data, h);
		}

		if (!data.success) {
			if (data.stage === 'clarify') {
				return renderClarification(data, h);
			}

			return renderError(data, h);
		}

		if (data.stage === 'preview' && data.preview) {
			return renderPreview(data, h);
		}

		const result = data.result || {};
		const displayType = result.display_type || '';

		if (displayType === 'orders_list') {
			return typeof h.renderOrdersResult === 'function'
				? h.renderOrdersResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'customer_summary') {
			return typeof h.renderCustomerSummaryResult === 'function'
				? h.renderCustomerSummaryResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'order_details') {
			return typeof h.renderOrderDetailsResult === 'function'
				? h.renderOrderDetailsResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'operations_summary') {
			return typeof h.renderOperationsSummaryResult === 'function'
				? h.renderOperationsSummaryResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'business_summary') {
			return typeof h.renderBusinessSummaryResult === 'function'
				? h.renderBusinessSummaryResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'morning_briefing') {
			return typeof h.renderMorningBriefingResult === 'function'
				? h.renderMorningBriefingResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'daily_briefing') {
			return typeof h.renderDailyBriefingResult === 'function'
				? h.renderDailyBriefingResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'operations_attention_queue') {
			return typeof h.renderOperationsAttentionQueueResult === 'function'
				? h.renderOperationsAttentionQueueResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'fulfillment_queue') {
			return typeof h.renderFulfillmentQueueResult === 'function'
				? h.renderFulfillmentQueueResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'inventory_queue') {
			return typeof h.renderInventoryQueueResult === 'function'
				? h.renderInventoryQueueResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'store_insights') {
			return typeof h.renderStoreInsightsResult === 'function'
				? h.renderStoreInsightsResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'customer_service_queue') {
			return typeof h.renderCustomerServiceQueueResult === 'function'
				? h.renderCustomerServiceQueueResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'customer_email_preview') {
			return typeof h.renderCustomerEmailPreviewResult === 'function'
				? h.renderCustomerEmailPreviewResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'fulfillment_followup_workflow_preview') {
			return renderFulfillmentFollowupWorkflowPreview(data, h);
		}

		if (displayType === 'customer_followup_workflow_preview') {
			return renderCustomerFollowupWorkflowPreview(data, h);
		}

		if (displayType === 'operations_attention_workflow_preview') {
			return renderOperationsAttentionWorkflowPreview(data, h);
		}

		if (displayType === 'inventory_pressure_workflow_preview') {
			return renderInventoryPressureWorkflowPreview(data, h);
		}

		if (displayType === 'business_summary_workflow_preview') {
			return renderBusinessSummaryWorkflowPreview(data, h);
		}

		if (displayType === 'refund_preview') {
			return typeof h.renderRefundPreviewResult === 'function'
				? h.renderRefundPreviewResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'email_templates_list') {
			return typeof h.renderEmailTemplatesResult === 'function'
				? h.renderEmailTemplatesResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'customer_email_drafts') {
			return typeof h.renderCustomerEmailDraftsResult === 'function'
				? h.renderCustomerEmailDraftsResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'assistant_message') {
			return renderAssistantMessageResult(data, h);
		}

		if (displayType === 'multi_action') {
			return typeof h.renderMultiActionResult === 'function'
				? h.renderMultiActionResult(data)
				: renderJson(data, h);
		}

		if (displayType === 'confirmation') {
			return renderConfirmation(data, h);
		}

		if (displayType === 'error') {
			return renderError(data, h);
		}

		if (data.context) {
			return renderContext(data, h);
		}

		if (
			data.success &&
			result &&
			(
				data.message === 'Undo completed.' ||
				data.message === 'Undo completed' ||
				data.stage === 'undo'
			)
		) {
			return renderUndoResult(data, h);
		}

		if (data.stage === 'done' && data.parsed && data.parsed.action) {
			let html = renderSingleActionResult(data, h);

			html += renderSuggestions(h.getResultSuggestions(data), h);

			return html;
		}

		return renderJson(data, h);
	}

	function renderError(data, h) {
		const error = data.error || {};

		const message =
			(data.parsed && data.parsed.message) ||
			data.message ||
			error.message ||
			(error.data && error.data.message) ||
			error.code ||
			(typeof error === 'string' ? error : '') ||
			'Request failed.';

		let html = '<div class="yoro-result-notice yoro-result-error">';
		html += '<strong>YoRo could not complete this request</strong>';
		html += '<p>' + h.escapeHtml(message) + '</p>';

		if (data.parsed && data.parsed.suggestion) {
			html += '<p><strong>Suggestion:</strong> ' + h.escapeHtml(data.parsed.suggestion) + '</p>';
		}

		if (data.parsed && Array.isArray(data.parsed.options) && data.parsed.options.length) {
			html += '<div class="yoro-clarify-options">';

			data.parsed.options.forEach(function (option) {
				html += '<button type="button" class="button yoro-clarify-option" data-command="' + h.escapeHtml(option.command || '') + '">';
				html += h.escapeHtml(option.label || option.command || 'Use this');
				html += '</button>';
			});

			html += '</div>';
		}

		if (data.parsed && data.parsed.supported_examples) {
			html += '<div class="yoro-result-suggestions">';
			html += '<strong>Try:</strong>';
			html += '<ul>';

			data.parsed.supported_examples.forEach(function (example) {
				html += '<li><button type="button" class="button-link yoro-insert-command" data-command="' + h.escapeHtml(example) + '">' + h.escapeHtml(example) + '</button></li>';
			});

			html += '</ul>';
			html += '</div>';
		}

		html += '</div>';
		html += renderDebugJson(data, h);

		return html;
	}

	function renderFulfillmentFollowupWorkflowPreview(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const draftSummary = result.draft_summary || {};
		const riskReasons = Array.isArray(result.risk_reasons) ? result.risk_reasons : [];

		let html = '<div class="yoro-workflow-preview yoro-workflow-guided-preview">';

		html += '<div class="yoro-result-notice yoro-result-preview">';
		html += '<strong>Fulfillment follow-up preview</strong>';
		html += '<p>' + h.escapeHtml(result.message || 'I prepared the next fulfillment follow-up preview below.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-workflow-preview-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += '<div class="yoro-result-section">';
		html += '<strong>What I am focusing on</strong>';
		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Order:</strong> #' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</span>';
		html += '<span><strong>Status:</strong> ' + h.escapeHtml(selectedItem.status || '—') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Age:</strong> ' + h.escapeHtml(selectedItem.age_hours || 0) + 'h</span>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
		}

		if (selectedItem.reason) {
			html += '<p><strong>Current queue signal:</strong> ' + h.escapeHtml(selectedItem.reason) + '</p>';
		}

		html += '</div>';

		html += '<div class="yoro-result-section">';
		html += '<strong>What I prepared</strong>';
		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Template:</strong> ' + h.escapeHtml(draftSummary.template || 'shipping_delay') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		if (draftSummary.subject) {
			html += '<p><strong>Subject:</strong> ' + h.escapeHtml(draftSummary.subject) + '</p>';
		}

		if (draftSummary.body_preview) {
			html += '<p><strong>Body preview:</strong> ' + h.escapeHtml(draftSummary.body_preview) + '</p>';
		}

		html += '</div>';

		html += '<div class="yoro-result-section">';
		html += '<strong>Before you continue</strong>';
		html += '<ul>';
		html += '<li>No store data has been changed yet.</li>';
		html += '<li>I will still need confirmation before saving any draft.</li>';

		riskReasons.forEach(function(reason) {
			html += '<li>' + h.escapeHtml(reason) + '</li>';
		});

		html += '</ul>';
		html += '</div>';
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderCustomerFollowupWorkflowPreview(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const draftSummary = result.draft_summary || {};
		const riskReasons = Array.isArray(result.risk_reasons) ? result.risk_reasons : [];

		let html = '<div class="yoro-workflow-preview-card">';
		html += '<div class="yoro-workflow-preview-header">';
		html += '<h4>Customer follow-up preview</h4>';
		html += '<span class="yoro-workflow-preview-badge">preview</span>';
		html += '</div>';
		html += '<p class="yoro-workflow-preview-summary">' + h.escapeHtml(result.message || '') + '</p>';
		html += renderAdminCopilotBoundary(data, h);

		html += '<div class="yoro-workflow-preview-grid">';
		html += '<div><strong>Order</strong><div>#' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</div></div>';
		html += '<div><strong>Queue</strong><div>' + h.escapeHtml(queueSource.label || queueSource.key || '') + '</div></div>';
		html += '<div><strong>Next step</strong><div>' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</div></div>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<div class="yoro-workflow-preview-section"><strong>Why this is recommended</strong><p>' + h.escapeHtml(result.selection_reason) + '</p></div>';
		}

		if (draftSummary.subject || draftSummary.body_preview) {
			html += '<div class="yoro-workflow-preview-section"><strong>What I prepared</strong>';
			if (draftSummary.subject) {
				html += '<p><strong>Subject:</strong> ' + h.escapeHtml(draftSummary.subject) + '</p>';
			}
			if (draftSummary.body_preview) {
				html += '<p><strong>Body preview:</strong> ' + h.escapeHtml(draftSummary.body_preview) + '</p>';
			}
			html += '</div>';
		}

		if (riskReasons.length) {
			html += '<div class="yoro-workflow-preview-section"><strong>Before you continue</strong><ul>';
			riskReasons.forEach(function (reason) {
				html += '<li>' + h.escapeHtml(reason) + '</li>';
			});
			html += '</ul></div>';
		}

		html += '</div>';
		html += renderDebugJson(data, h);

		return html;
	}

	function renderOperationsAttentionWorkflowPreview(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const nextStep = result.next_without_confirm || {};

		let html = '<div class="yoro-result-card yoro-workflow-preview">';
		html += '<div class="yoro-card-header">';
		html += '<div><strong>Operations attention preview</strong></div>';
		html += '<span class="yoro-badge yoro-badge-preview">Preview</span>';
		html += '</div>';

		if (result.summary) {
			html += '<p class="yoro-card-summary">' + h.escapeHtml(result.summary) + '</p>';
		}
		html += renderAdminCopilotBoundary(data, h);

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Order:</strong> #' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why this is recommended</strong>';
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
			html += '</div>';
		}

		if (result.preview_summary) {
			html += '<div class="yoro-result-section">';
			html += '<strong>What to do next</strong>';
			html += '<p>' + h.escapeHtml(result.preview_summary) + '</p>';
			html += '</div>';
		}

		if (nextStep.command || nextStep.action) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Next command</strong>';
			html += '<p>' + h.escapeHtml(nextStep.command || nextStep.action || '') + '</p>';
			html += '</div>';
		}

		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function renderInventoryPressureWorkflowPreview(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const nextStep = result.next_without_confirm || {};

		let html = '<div class="yoro-result-card yoro-workflow-preview">';
		html += '<div class="yoro-card-header">';
		html += '<div><strong>Inventory pressure preview</strong></div>';
		html += '<span class="yoro-badge yoro-badge-preview">Preview</span>';
		html += '</div>';

		if (result.summary) {
			html += '<p class="yoro-card-summary">' + h.escapeHtml(result.summary) + '</p>';
		}

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Product:</strong> ' + h.escapeHtml(selectedItem.name || '—') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>SKU:</strong> ' + h.escapeHtml(selectedItem.sku || '—') + '</span>';
		html += '<span><strong>Stock:</strong> ' + h.escapeHtml(selectedItem.stock_quantity || 0) + '</span>';
		html += '<span><strong>Status:</strong> ' + h.escapeHtml(selectedItem.stock_status || '—') + '</span>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why this is recommended</strong>';
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
			html += '</div>';
		}

		if (result.preview_summary) {
			html += '<div class="yoro-result-section">';
			html += '<strong>What to do next</strong>';
			html += '<p>' + h.escapeHtml(result.preview_summary) + '</p>';
			html += '</div>';
		}

		if (nextStep.command || nextStep.action) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Next command</strong>';
			html += '<p>' + h.escapeHtml(nextStep.command || nextStep.action || '') + '</p>';
			html += '</div>';
		}

		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function renderBusinessSummaryWorkflowPreview(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const proposedAction = result.proposed_action || {};
		const nextStep = result.next_without_confirm || {};

		let html = '<div class="yoro-result-card yoro-workflow-preview">';
		html += '<div class="yoro-card-header">';
		html += '<div><strong>Business summary preview</strong></div>';
		html += '<span class="yoro-badge yoro-badge-preview">Preview</span>';
		html += '</div>';

		if (result.summary) {
			html += '<p class="yoro-card-summary">' + h.escapeHtml(result.summary) + '</p>';
		}

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Signal:</strong> ' + h.escapeHtml(selectedItem.title || '—') + '</span>';
		html += '<span><strong>Window:</strong> ' + h.escapeHtml(selectedItem.window_key || '—') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		if (selectedItem.message) {
			html += '<div class="yoro-result-section">';
			html += '<strong>What this means</strong>';
			html += '<p>' + h.escapeHtml(selectedItem.message) + '</p>';
			html += '</div>';
		}

		if (result.selection_reason) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why this is recommended</strong>';
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
			html += '</div>';
		}

		if (result.preview_summary) {
			html += '<div class="yoro-result-section">';
			html += '<strong>What to do next</strong>';
			html += '<p>' + h.escapeHtml(result.preview_summary) + '</p>';
			html += '</div>';
		}

		if (nextStep.command || nextStep.action) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Next command</strong>';
			html += '<p>' + h.escapeHtml(nextStep.command || nextStep.action || '') + '</p>';
			html += '</div>';
		}

		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function buildNextStepSuggestions(action, result, params) {
		const suggestions = [];
		const orderScopedActions = [
			'orders.get_details',
			'orders.hold',
			'orders.update_status',
			'orders.add_note',
			'orders.bulk_update_status_with_note',
			'orders.email_customer_template',
			'orders.send_customer_email',
			'orders.render_customer_email_preview',
			'orders.save_customer_email_draft'
		];
		const isOrderScopedAction = orderScopedActions.indexOf(String(action || '')) !== -1;
		const firstOrderId = isOrderScopedAction
			? (
				Array.isArray(params.order_ids) && params.order_ids.length
					? params.order_ids[0]
					: (params.order_id || result.order_id || '')
			)
			: '';

		if (firstOrderId) {
			suggestions.push({
				label: 'Open order details',
				command: 'Show order ' + firstOrderId + ' details'
			});
		}

		if (action === 'store.get_daily_briefing' || action === 'store.get_morning_briefing') {
			suggestions.push({
				label: 'Open attention queue',
				command: 'Show operations attention queue'
			});
			suggestions.push({
				label: 'Open fulfillment queue',
				command: 'Show fulfillment queue'
			});
			suggestions.push({
				label: 'Open inventory queue',
				command: 'Show inventory queue'
			});
		}

		if (action === 'orders.hold' || action === 'orders.update_status' || action === 'orders.add_note' || action === 'orders.bulk_update_status_with_note') {
			suggestions.push({
				label: 'Open operations queue',
				command: 'Show operations attention queue'
			});
		}

		if (action === 'orders.email_customer_template') {
			suggestions.push({
				label: 'Open customer service queue',
				command: 'Show customer service queue'
			});
		}

		return suggestions;
	}

	function renderWhatChangesSection(title, lines, h) {
		const items = Array.isArray(lines) ? lines.filter(Boolean) : [];

		if (!items.length) {
			return '';
		}

		let html = '<div class="yoro-result-section yoro-guided-result-section">';
		html += '<strong>' + h.escapeHtml(title || 'What will change') + '</strong>';
		html += '<ul>';
		items.forEach(function(line) {
			html += '<li>' + h.escapeHtml(line) + '</li>';
		});
		html += '</ul>';
		html += '</div>';
		return html;
	}

	function renderWhySection(title, reasons, h) {
		const items = Array.isArray(reasons) ? reasons.filter(Boolean) : [];

		if (!items.length) {
			return '';
		}

		let html = '<div class="yoro-result-section yoro-guided-result-section">';
		html += '<strong>' + h.escapeHtml(title || 'Why this is recommended') + '</strong>';
		html += '<ul>';
		items.forEach(function(reason) {
			html += '<li>' + h.escapeHtml(reason) + '</li>';
		});
		html += '</ul>';
		html += '</div>';
		return html;
	}

	function renderNextStepsSection(items, h, title) {
		const steps = Array.isArray(items) ? items.filter(function(item) {
			return item && item.command;
		}) : [];

		if (!steps.length) {
			return '';
		}

		let html = '<div class="yoro-result-section yoro-guided-result-section">';
		html += '<strong>' + h.escapeHtml(title || 'What to do next') + '</strong>';
		html += '<div class="yoro-suggestion-list">';
		steps.slice(0, 3).forEach(function(item) {
			html += '<button class="button button-small yoro-suggestion-btn" data-command="' + h.escapeHtml(item.command) + '">';
			html += h.escapeHtml(item.label || item.command);
			html += '</button>';
		});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderPreview(data, h) {
		const preview = data.preview || {};

		if (
			preview.display_type === 'multi_action' ||
			preview.result_type === 'multi_action_preview' ||
			Array.isArray(preview.actions)
		) {
			if (typeof h.renderWorkflowPreview === 'function') {
				return h.renderWorkflowPreview(data);
			}

			return renderJson(data, h);
		}

		const plan = data.plan || preview.plan || {};
		const parsed = data.parsed || {};
		const params = parsed.params || {};
		const planParams =
			(plan && plan.params) ||
			(preview.plan && preview.plan.params) ||
			preview.params ||
			{};
		const action = preview.action || parsed.action || '';

		const effectiveParams = Object.assign({}, params, planParams);
		const encodedParams = h.escapeHtml(JSON.stringify(effectiveParams || {}));
		const actionStateToken = data.action_state_token || data.pending_action_token || '';

		let html = '<div class="yoro-preview-result">';

		html += '<div class="yoro-result-notice yoro-result-preview">';
		html += '<strong>AI action preview</strong>';

		if (action === 'orders.add_note') {
			const ids = Array.isArray(effectiveParams.order_ids) ? effectiveParams.order_ids : [];
			html += '<p>' + h.escapeHtml('Add internal note to ' + ids.length + ' order' + (ids.length === 1 ? '.' : 's.')) + '</p>';
		} else if (action === 'orders.update_status') {
			const ids = Array.isArray(effectiveParams.order_ids) ? effectiveParams.order_ids : [];
			html += '<p>' + h.escapeHtml('Update status for ' + ids.length + ' order' + (ids.length === 1 ? '.' : 's.')) + '</p>';
		} else {
			html += '<p>' + h.escapeHtml(preview.description || preview.summary || 'I mapped your command to the action below. No store data has changed yet.') + '</p>';
		}

		html += '</div>';
		html += '<div class="yoro-preview-body">';
		html += renderAdminCopilotBoundary(data, h);

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Mapped action:</strong> ' + h.escapeHtml(action) + '</span>';
		html += '<span><strong>Risk:</strong> ' + h.escapeHtml(preview.risk_level || '') + '</span>';
		html += '<span><strong>Needs approval:</strong> ' + h.escapeHtml(preview.requires_confirmation ? 'Yes' : 'No') + '</span>';
		html += '</div>';

		const riskReasons = data.risk && Array.isArray(data.risk.reasons)
			? data.risk.reasons
			: [];

		const policyReasons = data.policy && Array.isArray(data.policy.reasons)
			? data.policy.reasons
			: [];

		const reasons = riskReasons.concat(policyReasons);
		const changeLines = [];

		if (action === 'orders.add_note') {
			const ids = Array.isArray(effectiveParams.order_ids) ? effectiveParams.order_ids : [];
			const note = effectiveParams.note || '';

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> note: ' + (note || '—'));
				});
			}
		}

		if (action === 'orders.update_status' || action === 'orders.hold') {
			const ids = Array.isArray(effectiveParams.order_ids) ? effectiveParams.order_ids : [];
			const status = action === 'orders.hold'
				? 'on-hold'
				: (effectiveParams.status || '');

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> ' + (status || '—'));
				});
			}
		}

		if (action === 'orders.bulk_update_status_with_note') {
			const ids = Array.isArray(effectiveParams.order_ids) ? effectiveParams.order_ids : [];
			const status = effectiveParams.status || '';
			const note = effectiveParams.note || '';

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> ' + (status || '—') + ' — note: ' + (note || '—'));
				});
			}
		}

		html += renderWhatChangesSection('What will change', changeLines, h);
		html += renderWhySection('Why this is recommended', reasons, h);

		if (plan.orders && Array.isArray(plan.orders)) {
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Exists</th><th>Current status</th><th>Target status</th></tr></thead>';
			html += '<tbody>';

			plan.orders.forEach(function (order) {
				html += '<tr>';
				html += '<td>#' + h.escapeHtml(order.id) + '</td>';
				html += '<td>' + h.escapeHtml(order.exists ? 'Yes' : 'No') + '</td>';
				html += '<td>' + h.escapeHtml(order.current_status || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.target_status || '') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
		}

		html += renderNextStepsSection(buildNextStepSuggestions(action, preview, effectiveParams), h);

		if (preview.write_action || preview.requires_confirmation) {
			html += `
			<div class="yoro-confirm-actions">
				<button class="button button-primary yoro-confirm-run" data-direct-action="` + h.escapeHtml(action) + `" data-direct-params="` + encodedParams + `" data-action-state-token="` + h.escapeHtml(actionStateToken) + `">Confirm</button>
				<button class="button yoro-confirm-cancel">Cancel</button>
			</div>
			`;
		}

		html += '</div>';
		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function renderContext(data, h) {
		const context = data.context || {};
		let html = '<div class="yoro-result-summary">';
		html += '<strong>Store context loaded</strong>';
		html += '</div>';

		html += '<div class="yoro-context-grid">';

		if (context.store) {
			html += '<div class="yoro-context-card">';
			html += '<h3>Store</h3>';
			html += '<p><strong>Name:</strong> ' + h.escapeHtml(context.store.name || '') + '</p>';
			html += '<p><strong>URL:</strong> ' + h.escapeHtml(context.store.home_url || '') + '</p>';
			html += '<p><strong>Locale:</strong> ' + h.escapeHtml(context.store.locale || '') + '</p>';
			html += '</div>';
		}

		if (context.woocommerce) {
			html += '<div class="yoro-context-card">';
			html += '<h3>WooCommerce</h3>';
			html += '<p><strong>Active:</strong> ' + h.escapeHtml(context.woocommerce.active ? 'Yes' : 'No') + '</p>';
			html += '<p><strong>Version:</strong> ' + h.escapeHtml(context.woocommerce.version || '') + '</p>';
			html += '<p><strong>Currency:</strong> ' + h.escapeHtml(context.woocommerce.currency || '') + '</p>';
			html += '</div>';
		}

		if (context.actions) {
			html += '<div class="yoro-context-card">';
			html += '<h3>Actions</h3>';
			html += '<p><strong>Total:</strong> ' + h.escapeHtml(context.actions.total || 0) + '</p>';
			html += '<p><strong>Read:</strong> ' + h.escapeHtml(context.actions.read || 0) + '</p>';
			html += '<p><strong>Write:</strong> ' + h.escapeHtml(context.actions.write || 0) + '</p>';
			html += '</div>';
		}

		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function renderAssistantMessageResult(data, h) {
		const result = data.result || {};
		let html = '<div class="yoro-single-action-result">';

		html += '<div class="yoro-assistant-message-result">';
		if (result.summary) {
			html += '<p>' + h.escapeHtml(result.summary) + '</p>';
		}

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		html += '</div>';
		html += renderSuggestions(h.getResultSuggestions(data), h);
		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function renderUndoResult(data, h) {
		const result = data.result || {};
		const steps = Array.isArray(result.result) ? result.result : [];

		let html = '<div class="yoro-undo-result">';

		html += '<div class="yoro-result-notice yoro-result-success">';
		html += '<strong>Undo completed</strong>';
		html += '</div>';

		html += '<div class="yoro-undo-body">';

		if (steps.length) {
			html += '<div class="yoro-result-block">';

			steps.forEach(function (step) {
				html += renderUndoStep(step, h);
			});

			html += '</div>';
		} else {
			html += '<div class="yoro-result-block">';
			html += renderUndoStep({
				action: result.action || result.type || 'undo',
				result: result
			}, h);
			html += '</div>';
		}

		html += '</div>';
		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function renderUndoStep(step, h) {
		const action = step.action || '';
		const stepResult = step.result || {};
		const processed = Array.isArray(stepResult.processed) ? stepResult.processed : [];

		let html = '<div class="yoro-result-section">';

		if (action === 'orders.remove_note') {
			html += '<strong>Notes removed</strong>';
		} else if (action === 'orders.restore_statuses' || action === 'orders.update_status') {
			html += '<strong>Order statuses restored</strong>';
		} else if (action === 'orders.add_note') {
			html += '<strong>Order note undone</strong>';
		} else {
			html += '<strong>' + h.escapeHtml(stepResult.summary || action || 'Undo completed') + '</strong>';
		}

		if (processed.length) {
			html += '<ul>';

			processed.forEach(function (id) {
				if (action === 'orders.remove_note') {
					html += '<li>Note #' + h.escapeHtml(id) + ' removed</li>';
				} else {
					html += '<li>Order #' + h.escapeHtml(id) + ' restored</li>';
				}
			});

			html += '</ul>';
		} else if (stepResult.summary) {
			html += '<p>' + h.escapeHtml(stepResult.summary) + '</p>';
		} else if (stepResult.message) {
			html += '<p>' + h.escapeHtml(stepResult.message) + '</p>';
		}

		html += '</div>';

		return html;
	}

	function renderConfirmation(data, h) {
		const parsed = data.parsed || {};
		const result = data.result || {};
		const plan = data.plan || {};
		const params = parsed.params || result.params || plan.params || {};
		const action = parsed.action || result.action || plan.action || '';
		const encodedParams = h.escapeHtml(JSON.stringify(params || {}));
		const actionStateToken = data.action_state_token || data.pending_action_token || '';
		const useDirectConfirm = (plan.type || '') !== 'sequence' && action !== 'multi.query_and_apply';
		const confirmationMeta = buildConfirmationMeta(action, params, plan);

		if ((result.display_type || '') === 'fulfillment_followup_workflow_preview') {
			return renderFulfillmentFollowupWorkflowConfirmation(data, h);
		}

		if ((result.display_type || '') === 'customer_followup_workflow_preview') {
			return renderCustomerFollowupWorkflowConfirmation(data, h);
		}

		let html = '<div class="yoro-confirmation-result">';

		html += '<div class="yoro-result-notice yoro-result-confirm">';
		html += '<strong>Confirm store change</strong>';
		html += '<p>' + h.escapeHtml(data.message || result.message || 'YoRo will only change store data after you approve this action.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Action:</strong> ' + h.escapeHtml(action) + '</span>';

		if (confirmationMeta.scopeLabel) {
			html += '<span><strong>Orders:</strong> ' + h.escapeHtml(confirmationMeta.scopeLabel) + '</span>';
		} else if (Array.isArray(params.order_ids) && params.order_ids.length) {
			html += '<span><strong>Orders:</strong> #' + params.order_ids.map(function(id) {
				return h.escapeHtml(id);
			}).join(', #') + '</span>';
		} else if (params.order_id) {
			html += '<span><strong>Order:</strong> #' + h.escapeHtml(params.order_id) + '</span>';
		}

		if (confirmationMeta.targetStatus) {
			html += '<span><strong>Target status:</strong> ' + h.escapeHtml(confirmationMeta.targetStatus) + '</span>';
		} else if (params.status) {
			html += '<span><strong>Target status:</strong> ' + h.escapeHtml(params.status) + '</span>';
		}

		html += '</div>';

		const riskReasons = data.risk && Array.isArray(data.risk.reasons)
			? data.risk.reasons
			: [];

		const policyReasons = data.policy && Array.isArray(data.policy.reasons)
			? data.policy.reasons
			: [];

		const reasons = riskReasons.concat(policyReasons);
		const changeLines = [];

		if (action === 'orders.update_status' || action === 'orders.hold') {
			const ids = Array.isArray(params.order_ids) ? params.order_ids : (params.order_id ? [params.order_id] : []);
			const status = action === 'orders.hold' ? 'on-hold' : (params.status || '');

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> ' + (status || '—'));
				});
			}
		}

		if (action === 'orders.add_note') {
			const ids = Array.isArray(params.order_ids) ? params.order_ids : (params.order_id ? [params.order_id] : []);
			const note = params.note || '';

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> note: ' + (note || '—'));
				});
			}
		}

		if (action === 'orders.bulk_update_status_with_note') {
			const ids = Array.isArray(params.order_ids) ? params.order_ids : [];
			const status = params.status || '';
			const note = params.note || '';

			if (ids.length) {
				ids.forEach(function(id) {
					changeLines.push('#' + id + ' -> ' + (status || '—') + ' — note: ' + (note || '—'));
				});
			}
		}

		html += renderWhatChangesSection('What will change after confirmation', changeLines, h);
		html += renderWhySection('Why confirmation is needed', reasons, h);

		html += '<div class="yoro-confirm-actions">';
		if (useDirectConfirm) {
			html += '<button class="button button-primary yoro-confirm-run" data-direct-action="' + h.escapeHtml(action) + '" data-direct-params="' + encodedParams + '" data-action-state-token="' + h.escapeHtml(actionStateToken) + '">Confirm</button>';
		} else {
			html += '<button class="button button-primary yoro-confirm-run">Confirm</button>';
		}
		html += '<button class="button yoro-confirm-cancel">Cancel</button>';
		html += '</div>';

		html += '</div>';
		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function buildConfirmationMeta(action, params, plan) {
		const meta = {
			scopeLabel: '',
			targetStatus: ''
		};

		if (action !== 'multi.query_and_apply') {
			return meta;
		}

		const steps = Array.isArray(plan.steps) ? plan.steps : [];
		const searchStep = steps.find(function(step) {
			return step && step.action === 'orders.search';
		}) || {};
		const holdStep = steps.find(function(step) {
			return step && step.action === 'orders.hold';
		}) || {};
		const bulkUpdateStep = steps.find(function(step) {
			return step && step.action === 'orders.bulk_update_status_with_note';
		}) || {};

		const limit = params.limit || (searchStep.params && searchStep.params.limit) || 0;

		if (limit) {
			meta.scopeLabel = 'latest ' + limit + ' orders';
		}

		if (bulkUpdateStep.params && bulkUpdateStep.params.status) {
			meta.targetStatus = bulkUpdateStep.params.status;
		} else if (holdStep.action === 'orders.hold') {
			meta.targetStatus = 'on-hold';
		}

		return meta;
	}

	function renderFulfillmentFollowupWorkflowConfirmation(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const draftSummary = result.draft_summary || {};
		const riskReasons = Array.isArray(result.risk_reasons) ? result.risk_reasons : [];

		let html = '<div class="yoro-confirmation-result">';

		html += '<div class="yoro-result-notice yoro-result-confirm">';
		html += '<strong>Confirm store change</strong>';
		html += '<p>' + h.escapeHtml(data.message || result.confirmation_scope || 'Review this fulfillment follow-up before saving the draft.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Flow:</strong> Fulfillment follow-up</span>';
		html += '<span><strong>Order:</strong> #' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why I picked this order</strong>';
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
			html += '</div>';
		}

		html += '<div class="yoro-result-section">';
		html += '<strong>Draft I prepared</strong>';
		html += '<p><strong>Subject:</strong> ' + h.escapeHtml(draftSummary.subject || '') + '</p>';
		html += '<p><strong>Body preview:</strong> ' + h.escapeHtml(draftSummary.body_preview || '') + '</p>';
		html += '</div>';

		if (riskReasons.length) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why confirmation is needed</strong>';
			html += '<ul>';

			riskReasons.forEach(function(reason) {
				html += '<li>' + h.escapeHtml(reason) + '</li>';
			});

			html += '</ul>';
			html += '</div>';
		}

		html += `
		<div class="yoro-confirm-actions">
			<button class="button button-primary yoro-confirm-run">Confirm</button>
			<button class="button yoro-confirm-cancel">Cancel</button>
		</div>
		`;
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderCustomerFollowupWorkflowConfirmation(data, h) {
		const result = data.result || {};
		const selectedItem = result.selected_item || {};
		const queueSource = result.queue_source || {};
		const proposedAction = result.proposed_action || {};
		const draftSummary = result.draft_summary || {};
		const riskReasons = Array.isArray(result.risk_reasons) ? result.risk_reasons : [];

		let html = '<div class="yoro-confirmation-result">';

		html += '<div class="yoro-result-notice yoro-result-confirm">';
		html += '<strong>Confirm store change</strong>';
		html += '<p>' + h.escapeHtml(data.message || result.confirmation_scope || 'Review this customer follow-up before saving the draft.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Flow:</strong> Customer follow-up</span>';
		html += '<span><strong>Order:</strong> #' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Next step:</strong> ' + h.escapeHtml(proposedAction.label || proposedAction.action || '') + '</span>';
		html += '</div>';

		if (result.selection_reason) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why I picked this order</strong>';
			html += '<p>' + h.escapeHtml(result.selection_reason) + '</p>';
			html += '</div>';
		}

		html += '<div class="yoro-result-section">';
		html += '<strong>Draft I prepared</strong>';
		html += '<p><strong>Subject:</strong> ' + h.escapeHtml(draftSummary.subject || '') + '</p>';
		html += '<p><strong>Body preview:</strong> ' + h.escapeHtml(draftSummary.body_preview || '') + '</p>';
		html += '</div>';

		if (riskReasons.length) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Why confirmation is needed</strong>';
			html += '<ul>';

			riskReasons.forEach(function(reason) {
				html += '<li>' + h.escapeHtml(reason) + '</li>';
			});

			html += '</ul>';
			html += '</div>';
		}

		html += `
		<div class="yoro-confirm-actions">
			<button class="button button-primary yoro-confirm-run">Confirm</button>
			<button class="button yoro-confirm-cancel">Cancel</button>
		</div>
		`;
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderClarification(data, h) {
		const parsed = data.parsed || {};
		const message = parsed.message || data.message || 'I need a little more detail.';
		const suggestion = parsed.suggestion || data.suggestion || '';

		let html = '<div class="yoro-assistant-message-result yoro-clarification-message-result">';
		html += '<p>' + h.escapeHtml(message) + '</p>';

		if (suggestion) {
			html += '<p class="yoro-result-subtle">Best guess: ' + h.escapeHtml(suggestion) + '</p>';
		}

		html += '</div>';

		return html;
	}

	function renderAssistantNarrative(result, h) {
		if (!result || (!result.assistant_summary && !result.assistant_followup && !(result.meta && result.meta.assumption))) {
			return '';
		}

		const assumption = result.meta && result.meta.assumption ? result.meta.assumption : null;
		const assistantSummary = result.assistant_summary ? String(result.assistant_summary).trim() : '';
		const resultSummary = result.summary ? String(result.summary).trim() : '';
		const showSummary = assistantSummary && assistantSummary !== resultSummary;
		let html = '<div class="yoro-assistant-message-result yoro-assistant-narrative-result">';

		if (showSummary) {
			html += '<p>' + h.escapeHtml(assistantSummary) + '</p>';
		}

		if (assumption && assumption.message) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(assumption.message) + '</p>';
		}

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		if (!showSummary && !(assumption && assumption.message) && !result.assistant_followup) {
			return '';
		}

		html += '</div>';

		return html;
	}

	function formatActionCount(result, params) {
		if (typeof result.count !== 'undefined' && result.count !== null) {
			return Number(result.count) || 0;
		}

		if (Array.isArray(result.processed)) {
			return result.processed.length;
		}

		if (Array.isArray(params.order_ids)) {
			return params.order_ids.length;
		}

		if (params.order_id || result.order_id) {
			return 1;
		}

		return 0;
	}

	function labelizeActionValue(value) {
		return String(value || '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function(char) {
				return char.toUpperCase();
			});
	}

	function buildSingleActionSuccessCopy(action, result, params) {
		const count = formatActionCount(result, params);
		const orderNo = result.order && result.order.number
			? result.order.number
			: (result.order_id || params.order_id || '');
		const statusLabel = result.status_label || labelizeActionValue(result.status || params.status || '');
		const templateLabel = result.template ? labelizeActionValue(result.template) : '';

		switch (action) {
			case 'orders.hold':
				return {
					title: count === 1 ? 'Order put on hold' : 'Orders put on hold',
					message: count === 1
						? 'I moved that order to on-hold.'
						: 'I moved ' + count + ' orders to on-hold.'
				};
			case 'orders.add_note':
				return {
					title: count === 1 ? 'Note added' : 'Notes added',
					message: count === 1
						? 'I added the internal note.'
						: 'I added the internal note to ' + count + ' orders.'
				};
			case 'orders.add_private_note_template':
				return {
					title: 'Template note added',
					message: templateLabel
						? 'I added the ' + templateLabel.toLowerCase() + ' note.'
						: 'I added the internal note template.'
				};
			case 'orders.remove_note':
				return {
					title: count === 1 ? 'Note removed' : 'Notes removed',
					message: count === 1
						? 'I removed the YoRo note.'
						: 'I removed ' + count + ' YoRo notes.'
				};
			case 'orders.update_status':
				return {
					title: 'Status updated',
					message: count === 1
						? 'I updated the order status to ' + (statusLabel || 'the new status') + '.'
						: 'I updated ' + count + ' orders to ' + (statusLabel || 'the new status') + '.'
				};
			case 'orders.restore_statuses':
				return {
					title: 'Statuses restored',
					message: count === 1
						? 'I restored the previous order status.'
						: 'I restored the previous statuses for ' + count + ' orders.'
				};
			case 'orders.bulk_update_status_with_note':
				return {
					title: 'Status and note updated',
					message: count === 1
						? 'I updated the order to ' + (statusLabel || 'the new status') + ' and added the note.'
						: 'I updated ' + count + ' orders to ' + (statusLabel || 'the new status') + ' and added the note.'
				};
			case 'orders.email_customer_template':
			case 'orders.send_customer_email':
				return {
					title: 'Email sent',
					message: orderNo
						? 'I sent the customer email for order #' + orderNo + '.'
						: 'I sent the customer email.'
				};
			case 'assistant.submit_developer_request':
				return {
					title: 'Request sent',
					message: 'I sent that request to the developers.'
				};
			case 'workflow.cancel':
				return {
					title: 'Workflow cancelled',
					message: 'I stopped that workflow.'
				};
			default:
				return {
					title: 'Completed',
					message: result.summary || 'I completed that action.'
				};
		}
	}

	function renderSingleActionResult(data, h) {
		const parsed = data.parsed || {};
		const result = data.result || {};
		const params = parsed.params || {};
		const action = parsed.action || result.action || '';
		const successCopy = buildSingleActionSuccessCopy(action, result, params);

		let html = '<div class="yoro-single-action-result">';
		html += renderAssistantNarrative(result, h);

		html += '<div class="yoro-result-notice yoro-result-success">';
		html += '<strong>' + h.escapeHtml(successCopy.title || 'Completed') + '</strong>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '<p>' + h.escapeHtml(successCopy.message || result.summary || 'I completed that action.') + '</p>';

		html += '</div>';
		html += '<div class="yoro-single-action-body">';
		html += renderAdminCopilotBoundary(data, h);
		const changeLines = [];

		if (action === 'orders.hold') {
			const ids = result.order_ids || result.processed || params.order_ids || [];

			ids.forEach(function (id) {
				changeLines.push('#' + id + ' -> moved to on-hold');
			});
		}

		if (action === 'orders.add_note') {
			const ids = result.order_ids || (result.order_id ? [result.order_id] : []);
			const note = result.note || params.note || '';

			ids.forEach(function (id) {
				changeLines.push('#' + id + ' -> note: ' + note);
			});
		}

		if (action === 'orders.update_status' || action === 'orders.bulk_update_status_with_note') {
			const ids = result.processed || params.order_ids || [];
			const status = result.status || params.status || '';
			const note = result.note || params.note || '';

			ids.forEach(function (id) {
				let line = '#' + id + ' -> ' + status;
				if (note) {
					line += ' — note: ' + note;
				}
				changeLines.push(line);
			});
		}

		if (action === 'orders.email_customer_template') {
			const email = result.email || {};
			changeLines.push('Order #' + (result.order_id || ''));
			changeLines.push('Template: ' + (result.template || ''));
			changeLines.push('Recipient: ' + (email.to || ''));
			changeLines.push('Subject: ' + (email.subject || ''));
		}

		if (action === 'orders.send_customer_email') {
			const email = result.email || {};
			changeLines.push('Order #' + (result.order && result.order.number ? result.order.number : (result.order_id || '')));
			changeLines.push('Recipient: ' + (email.to || ''));
			changeLines.push('Subject: ' + (email.subject || ''));
		}

		if (action === 'assistant.submit_developer_request') {
			const request = result.data && result.data.developer_request
				? result.data.developer_request
				: {};
			if (request.request_text) {
				changeLines.push('Requested capability: ' + request.request_text);
			}
			if (request.context_order_id) {
				changeLines.push('Order context: #' + request.context_order_id);
			}
			if (request.submitted_to) {
				changeLines.push('Sent to: ' + request.submitted_to);
			}
		}

		html += renderWhatChangesSection('What changed', changeLines, h);
		html += renderNextStepsSection(buildNextStepSuggestions(action, result, params), h);

		if (Array.isArray(result.skipped) && result.skipped.length) {
			html += '<div class="yoro-result-section">';
			html += '<strong>Skipped</strong>';
			html += '<ul>';

			result.skipped.forEach(function (item) {
				html += '<li>#' + h.escapeHtml(item.id || '') + ' — ' + h.escapeHtml(item.reason || '') + '</li>';
			});

			html += '</ul>';
			html += '</div>';
		}

		if (Array.isArray(result.errors) && result.errors.length) {
			html += '<div class="yoro-result-section yoro-result-errors">';
			html += '<strong>Errors</strong>';
			html += '<ul>';

			result.errors.forEach(function (item) {
				html += '<li>#' + h.escapeHtml(item.id || '') + ' — ' + h.escapeHtml(item.message || '') + '</li>';
			});

			html += '</ul>';
			html += '</div>';
		}

		const undoPlan = Array.isArray(result.undo_plan) ? result.undo_plan : [];
		const logId = data.log_id || result.log_id || '';

		const undoable =
			result.undoable === true ||
			undoPlan.length > 0;

		if (logId && result.read_only !== true && undoable) {
			html += '<div class="yoro-undo-action">';
			html += '<button type="button" class="button yoro-undo-last" data-log-id="' + h.escapeHtml(logId) + '">Undo</button>';
			html += '</div>';
		}

		html += '</div>';
		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function renderAdminCopilotBoundary(data, h) {
		return '';
	}

	function renderSuggestions(suggestions, h) {
		if (!Array.isArray(suggestions) || !suggestions.length) {
			return '';
		}

		let html = '<div class="yoro-suggestions">';
		html += '<strong>Suggestions:</strong>';

		html += '<div class="yoro-suggestion-list">';

		suggestions.forEach(function (item) {
			html += '<button class="button button-small yoro-suggestion-btn"';

			html += ' data-command="' + h.escapeHtml(item.value) + '"';

			if (item.requires_input) {
				html += ' data-requires-input="1"';
			}

			html += '>';

			html += h.escapeHtml(item.label);
			html += '</button>';
		});

		html += '</div></div>';

		return html;
	}

	function renderJson(data, h) {
		return '<pre class="yoro-json-output">' + h.escapeHtml(JSON.stringify(data, null, 2)) + '</pre>';
	}

	function renderDebugJson(data, h) {
		if (!h.shouldShowDebugJson || !h.shouldShowDebugJson()) {
			return '';
		}

		let html = '<details class="yoro-result-debug">';
		html += '<summary>Raw JSON</summary>';

		const parsed = data && data.parsed ? data.parsed : {};
		const intent = parsed && parsed.intent ? parsed.intent : {};
		const traceItems = [];

		if (intent.name) {
			traceItems.push('<span><strong>Intent:</strong> ' + h.escapeHtml(intent.name) + '</span>');
		}

		if (parsed.action) {
			traceItems.push('<span><strong>Action:</strong> ' + h.escapeHtml(parsed.action) + '</span>');
		}

		if (parsed.parser_source) {
			traceItems.push('<span><strong>Router source:</strong> ' + h.escapeHtml(parsed.parser_source) + '</span>');
		}

		if (parsed.parser_rule) {
			traceItems.push('<span><strong>Decision route:</strong> ' + h.escapeHtml(parsed.parser_rule) + '</span>');
		}

		if (typeof parsed.confidence !== 'undefined') {
			traceItems.push('<span><strong>Confidence:</strong> ' + h.escapeHtml(Math.round(Number(parsed.confidence) * 100)) + '%</span>');
		}

		if (traceItems.length) {
			html += '<div class="yoro-result-meta">' + traceItems.join('') + '</div>';
		}

		const retrievedExamples = Array.isArray(parsed.retrieved_examples) ? parsed.retrieved_examples : [];
		const routeCandidates = parsed && parsed.route_candidates ? parsed.route_candidates : {};
		const candidateIntents = Array.isArray(routeCandidates.intents) ? routeCandidates.intents : [];
		const candidateActions = Array.isArray(routeCandidates.actions) ? routeCandidates.actions : [];

		if (retrievedExamples.length) {
			html += '<div class="yoro-result-debug-examples">';
			html += '<strong>Retrieved examples:</strong>';
			html += '<ul>';

			retrievedExamples.slice(0, 5).forEach(function (item) {
				if (!item || !item.command) {
					return;
				}

				const parts = [];

				if (item.intent) {
					parts.push('intent: ' + item.intent);
				}

				if (item.action) {
					parts.push('action: ' + item.action);
				}

				if (item.source) {
					parts.push('source: ' + item.source);
				}

				if (typeof item.score !== 'undefined' && item.score !== null && !Number.isNaN(Number(item.score))) {
					parts.push('score: ' + Number(item.score).toFixed(2));
				}

				html += '<li><span>' + h.escapeHtml(item.command) + '</span>';

				if (parts.length) {
					html += '<br><small>' + h.escapeHtml(parts.join(' | ')) + '</small>';
				}

				html += '</li>';
			});

			html += '</ul></div>';
		}

		if (candidateIntents.length || candidateActions.length) {
			html += '<div class="yoro-result-debug-examples">';
			html += '<strong>Route candidates:</strong>';

			if (candidateIntents.length) {
				html += '<div><small><strong>Intents</strong></small><ul>';
				candidateIntents.slice(0, 5).forEach(function (item) {
					if (!item || !item.name) {
						return;
					}

					const parts = [];

					if (typeof item.aggregate_score !== 'undefined' && item.aggregate_score !== null && !Number.isNaN(Number(item.aggregate_score))) {
						parts.push('aggregate_score: ' + Number(item.aggregate_score).toFixed(2));
					}

					if (typeof item.top_match_score !== 'undefined' && item.top_match_score !== null && !Number.isNaN(Number(item.top_match_score))) {
						parts.push('top_match_score: ' + Number(item.top_match_score).toFixed(2));
					}

					if (typeof item.match_count !== 'undefined' && item.match_count !== null && !Number.isNaN(Number(item.match_count))) {
						parts.push('match_count: ' + Number(item.match_count));
					}

					if (Array.isArray(item.sources) && item.sources.length) {
						parts.push('sources: ' + item.sources.join(', '));
					}

					html += '<li><span>' + h.escapeHtml(item.name) + '</span>';

					if (parts.length) {
						html += '<br><small>' + h.escapeHtml(parts.join(' | ')) + '</small>';
					}

					html += '</li>';
				});
				html += '</ul></div>';
			}

			if (candidateActions.length) {
				html += '<div><small><strong>Actions</strong></small><ul>';
				candidateActions.slice(0, 5).forEach(function (item) {
					if (!item || !item.name) {
						return;
					}

					const parts = [];

					if (item.intent) {
						parts.push('intent: ' + item.intent);
					}

					if (typeof item.aggregate_score !== 'undefined' && item.aggregate_score !== null && !Number.isNaN(Number(item.aggregate_score))) {
						parts.push('aggregate_score: ' + Number(item.aggregate_score).toFixed(2));
					}

					if (typeof item.top_match_score !== 'undefined' && item.top_match_score !== null && !Number.isNaN(Number(item.top_match_score))) {
						parts.push('top_match_score: ' + Number(item.top_match_score).toFixed(2));
					}

					if (typeof item.match_count !== 'undefined' && item.match_count !== null && !Number.isNaN(Number(item.match_count))) {
						parts.push('match_count: ' + Number(item.match_count));
					}

					if (Array.isArray(item.sources) && item.sources.length) {
						parts.push('sources: ' + item.sources.join(', '));
					}

					html += '<li><span>' + h.escapeHtml(item.name) + '</span>';

					if (parts.length) {
						html += '<br><small>' + h.escapeHtml(parts.join(' | ')) + '</small>';
					}

					html += '</li>';
				});
				html += '</ul></div>';
			}

			html += '</div>';
		}

		html += renderJson(data, h);
		html += '</details>';

		return html;
	}

	window.YoRoResultRenderers = {
		init: init
	};
})();
