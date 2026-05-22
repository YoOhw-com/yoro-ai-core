(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
			formatDateTime: helpers.formatDateTime,
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

	function formatAgeHours(value) {
		const hours = parseInt(value || 0, 10) || 0;

		if (hours <= 0) {
			return '<1h';
		}

		if (hours < 24) {
			return hours + 'h';
		}

		const days = Math.floor(hours / 24);
		const remainingHours = hours % 24;

		if (days < 7) {
			return remainingHours ? (days + 'd ' + remainingHours + 'h') : (days + 'd');
		}

		const weeks = Math.floor(days / 7);
		const remainingDays = days % 7;

		if (weeks < 5) {
			return remainingDays ? (weeks + 'w ' + remainingDays + 'd') : (weeks + 'w');
		}

		const months = Math.floor(days / 30);
		const remainingMonthDays = days % 30;

		if (months > 0) {
			return remainingMonthDays ? (months + 'mo ' + remainingMonthDays + 'd') : (months + 'mo');
		}

		return days + 'd';
	}

		function renderResult(data, h) {
		function finish(html) {
			const markup = typeof html === 'string' ? html : '';
			const trimmed = markup.trim();

			if (!trimmed) {
				return markup;
			}

			if (trimmed.indexOf('class="yoro-result-debug"') !== -1) {
				return markup;
			}

			if (trimmed.indexOf('<pre class="yoro-json-output"') === 0) {
				if (h.shouldShowDebugJson && h.shouldShowDebugJson()) {
					return '<details class="yoro-result-debug"><summary>Raw JSON</summary>' + markup + '</details>';
				}

				return markup;
			}

			return markup + renderDebugJson(data, h);
		}

		if (!data) {
			return '';
		}

		if (data.stage === 'confirm' && data.confirm_required) {
			return finish(renderConfirmation(data, h));
		}

		if (!data.success) {
			if (data.stage === 'clarify') {
				return finish(renderClarification(data, h));
			}

			return finish(renderError(data, h));
		}

		if (data.stage === 'preview' && data.preview) {
			return finish(renderPreview(data, h));
		}

		const result = data.result || {};
		const displayType = result.display_type || '';

		if (displayType === 'orders_list') {
			return finish(typeof h.renderOrdersResult === 'function'
				? h.renderOrdersResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'customer_summary') {
			return finish(typeof h.renderCustomerSummaryResult === 'function'
				? h.renderCustomerSummaryResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'order_details') {
			return finish(typeof h.renderOrderDetailsResult === 'function'
				? h.renderOrderDetailsResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'operations_summary') {
			return finish(typeof h.renderOperationsSummaryResult === 'function'
				? h.renderOperationsSummaryResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'business_summary') {
			return finish(typeof h.renderBusinessSummaryResult === 'function'
				? h.renderBusinessSummaryResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'morning_briefing') {
			return finish(typeof h.renderMorningBriefingResult === 'function'
				? h.renderMorningBriefingResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'daily_briefing') {
			return finish(typeof h.renderDailyBriefingResult === 'function'
				? h.renderDailyBriefingResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'operations_attention_queue') {
			return finish(typeof h.renderOperationsAttentionQueueResult === 'function'
				? h.renderOperationsAttentionQueueResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'fulfillment_queue') {
			return finish(typeof h.renderFulfillmentQueueResult === 'function'
				? h.renderFulfillmentQueueResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'inventory_queue') {
			return finish(typeof h.renderInventoryQueueResult === 'function'
				? h.renderInventoryQueueResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'store_insights') {
			return finish(typeof h.renderStoreInsightsResult === 'function'
				? h.renderStoreInsightsResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'customer_service_queue') {
			return finish(typeof h.renderCustomerServiceQueueResult === 'function'
				? h.renderCustomerServiceQueueResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'customer_email_preview') {
			return finish(typeof h.renderCustomerEmailPreviewResult === 'function'
				? h.renderCustomerEmailPreviewResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'fulfillment_followup_workflow_preview') {
			return finish(renderFulfillmentFollowupWorkflowPreview(data, h));
		}

		if (displayType === 'customer_followup_workflow_preview') {
			return finish(renderCustomerFollowupWorkflowPreview(data, h));
		}

		if (displayType === 'operations_attention_workflow_preview') {
			return finish(renderOperationsAttentionWorkflowPreview(data, h));
		}

		if (displayType === 'inventory_pressure_workflow_preview') {
			return finish(renderInventoryPressureWorkflowPreview(data, h));
		}

		if (displayType === 'business_summary_workflow_preview') {
			return finish(renderBusinessSummaryWorkflowPreview(data, h));
		}

		if (displayType === 'refund_preview') {
			return finish(typeof h.renderRefundPreviewResult === 'function'
				? h.renderRefundPreviewResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'email_templates_list') {
			return finish(typeof h.renderEmailTemplatesResult === 'function'
				? h.renderEmailTemplatesResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'customer_email_drafts') {
			return finish(typeof h.renderCustomerEmailDraftsResult === 'function'
				? h.renderCustomerEmailDraftsResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'assistant_message') {
			return finish(renderAssistantMessageResult(data, h));
		}

		if (displayType === 'multi_action') {
			return finish(typeof h.renderMultiActionResult === 'function'
				? h.renderMultiActionResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'confirmation') {
			return finish(renderConfirmation(data, h));
		}

		if (displayType === 'error') {
			return finish(renderError(data, h));
		}

		if (data.context) {
			return finish(renderContext(data, h));
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
			return finish(renderUndoResult(data, h));
		}

		if (data.stage === 'done' && data.parsed && data.parsed.action) {
			let html = renderSingleActionResult(data, h);

			html += renderSuggestions(h.getResultSuggestions(data), h);

			return finish(html);
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
		html += '<strong>I could not complete this request</strong>';
		html += '<p>' + h.escapeHtml(message) + '</p>';

		if (data.parsed && data.parsed.suggestion) {
			html += '<p><strong>Suggestion:</strong> ' + h.escapeHtml(data.parsed.suggestion) + '</p>';
		}

		if (data.parsed && shouldRenderClarifyOptions(data.parsed) && Array.isArray(data.parsed.options) && data.parsed.options.length) {
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

	function toWorkflowStatusLabel(status) {
		status = String(status || '').replace(/_/g, ' ').trim();

		if (!status) {
			return 'Unknown';
		}

		return status.charAt(0).toUpperCase() + status.slice(1);
	}

	function getWorkflowBranchMeta(workflow) {
		workflow = workflow && typeof workflow === 'object' ? workflow : {};

		const definitionId = workflow.definition_id || workflow.type || '';
		const branch = workflow.branch && typeof workflow.branch === 'object' ? workflow.branch : {};
		const deliveryMode = branch.delivery_mode || '';
		const emailDeliveryMode = branch.email_delivery_mode || '';

		if (definitionId === 'write_email_for_order' || definitionId === 'write_email_for_orders' || definitionId === 'write_and_save_email_drafts_for_orders' || definitionId === 'write_and_send_emails_for_orders') {
			const plural = definitionId === 'write_email_for_orders' || definitionId === 'write_and_save_email_drafts_for_orders' || definitionId === 'write_and_send_emails_for_orders';
			if (deliveryMode === 'save_draft') {
				return { label: 'Save as draft', outcome: plural ? 'Drafts will be saved on each order' : 'Draft will be saved on the order' };
			}
			if (deliveryMode === 'send') {
				return { label: 'Send email', outcome: plural ? 'Each email will be sent to its customer' : 'Email will be sent to the customer' };
			}
			return { label: 'Preview email', outcome: plural ? 'Email drafts will stay in review state' : 'Email draft will stay in review state' };
		}

		if (definitionId === 'verify_customer_before_fulfillment') {
			if (emailDeliveryMode === 'save_draft') {
				return { label: 'Save verification draft', outcome: 'Verification email draft will be saved after the hold and note' };
			}
			if (emailDeliveryMode === 'send') {
				return { label: 'Send verification email', outcome: 'Verification email will be sent after the hold and note' };
			}
			return { label: 'Preview verification email', outcome: 'Verification email will stay in review state after the hold and note' };
		}

		if (definitionId === 'refund_review') {
			if (deliveryMode === 'save_draft') {
				return { label: 'Save refund follow-up draft', outcome: 'Refund follow-up draft will be saved after review' };
			}
			if (deliveryMode === 'send') {
				return { label: 'Send refund follow-up email', outcome: 'Refund follow-up email will be sent after review' };
			}
			return { label: 'Review only', outcome: 'No follow-up email will be sent yet' };
		}

		return { label: '', outcome: '' };
	}

	function getWorkflowFromData(data) {
		const result = data && data.result ? data.result : {};
		const plan = data && data.plan ? data.plan : {};

		if (result.workflow && typeof result.workflow === 'object') {
			return result.workflow;
		}

		if (plan.workflow && typeof plan.workflow === 'object') {
			return plan.workflow;
		}

		return {};
	}

	function renderWorkflowStatePanel(workflow, h) {
		workflow = workflow && typeof workflow === 'object' ? workflow : {};

		const steps = Array.isArray(workflow.steps) ? workflow.steps : [];
		const status = workflow.status || workflow.step || '';
		const currentStepId = workflow.current_step_id || '';
		const definitionId = workflow.definition_id || workflow.type || '';

		if (!status && !currentStepId && !definitionId && !steps.length) {
			return '';
		}

		let html = '<div class="yoro-workflow-state-panel">';
		html += '<div class="yoro-workflow-state-panel-header">';
		html += '<strong>Workflow state</strong>';
		html += '<span class="yoro-workflow-state-badge">' + h.escapeHtml(toWorkflowStatusLabel(status || 'unknown')) + '</span>';
		html += '</div>';

		html += '<div class="yoro-workflow-state-meta">';

		if (definitionId) {
			html += '<span><strong>Workflow:</strong> ' + h.escapeHtml(definitionId) + '</span>';
		}

		if (currentStepId) {
			html += '<span><strong>Current step:</strong> ' + h.escapeHtml(currentStepId) + '</span>';
		}

		if (typeof workflow.current_step_index === 'number' && workflow.current_step_index >= 0) {
			html += '<span><strong>Step index:</strong> ' + h.escapeHtml(workflow.current_step_index + 1) + '</span>';
		}

		const branchMeta = getWorkflowBranchMeta(workflow);

		if (branchMeta.label) {
			html += '<span><strong>Branch:</strong> ' + h.escapeHtml(branchMeta.label) + '</span>';
		}

		html += '</div>';

		if (branchMeta.outcome) {
			html += '<p class="yoro-workflow-state-branch-outcome">' + h.escapeHtml(branchMeta.outcome) + '</p>';
		}

		if (steps.length) {
			html += '<div class="yoro-workflow-timeline">';

			steps.forEach(function(step, index) {
				step = step || {};
				const stepStatus = step.status || 'pending';
				const isCurrent = currentStepId && step.id === currentStepId;

				html += '<div class="yoro-workflow-timeline-step yoro-workflow-timeline-step-' + h.escapeHtml(stepStatus) + (isCurrent ? ' is-current' : '') + '">';
				html += '<div class="yoro-workflow-timeline-step-main">';
				html += '<span class="yoro-workflow-timeline-step-index">' + h.escapeHtml(index + 1) + '</span>';
				html += '<span class="yoro-workflow-timeline-step-label">' + h.escapeHtml(step.label || step.id || step.action || ('Step ' + (index + 1))) + '</span>';
				html += '<span class="yoro-workflow-timeline-step-status">' + h.escapeHtml(toWorkflowStatusLabel(stepStatus)) + '</span>';
				html += '</div>';
				html += '<div class="yoro-workflow-timeline-step-meta">';

				if (step.id) {
					html += '<span>ID: ' + h.escapeHtml(step.id) + '</span>';
				}

				if (step.action) {
					html += '<span>Action: ' + h.escapeHtml(step.action) + '</span>';
				}

				html += '</div>';
				html += '</div>';
			});

			html += '</div>';
		}

		html += '</div>';
		return html;
	}

	function formatWorkflowTimestamp(value, timestamp, h) {
		if (typeof h.formatDateTime === 'function') {
			return h.formatDateTime(timestamp, value);
		}

		return String(value || '');
	}

	function isWorkflowStatusActive(status) {
		return ['collect_missing_params', 'preview_ready', 'ready_to_execute', 'executing'].indexOf(String(status || '')) !== -1;
	}

	function buildWorkflowHistoryItems(workflowContext) {
		const context = workflowContext && typeof workflowContext === 'object' ? workflowContext : {};
		const items = [];
		const seen = {};
		const current = context.current_workflow && typeof context.current_workflow === 'object' ? context.current_workflow : {};
		const history = Array.isArray(context.workflow_history) ? context.workflow_history : [];

		if (current.id) {
			items.push({
				id: current.id,
				source: 'current',
				label: current.label || current.definition_id || current.type || 'Workflow',
				definition_id: current.definition_id || current.type || '',
				status: current.status || current.step || '',
				current_step_id: current.current_step_id || '',
				current_step_index: typeof current.current_step_index === 'number' ? current.current_step_index : -1,
				updated_at: current.updated_at || '',
				updated_at_ts: current.updated_at_ts || 0
			});
			seen[current.id] = true;
		}

		history.forEach(function(entry) {
			entry = entry && typeof entry === 'object' ? entry : {};

			if (!entry.id || seen[entry.id]) {
				return;
			}

			items.push({
				id: entry.id,
				source: 'history',
				label: entry.label || entry.definition_id || entry.type || 'Workflow',
				definition_id: entry.definition_id || entry.type || '',
				status: entry.status || '',
				current_step_id: entry.current_step_id || '',
				current_step_index: typeof entry.current_step_index === 'number' ? entry.current_step_index : -1,
				updated_at: entry.updated_at || '',
				updated_at_ts: entry.updated_at_ts || 0
			});
			seen[entry.id] = true;
		});

		return items;
	}

	function renderWorkflowHistoryPanel(workflowContext, h) {
		const context = workflowContext && typeof workflowContext === 'object' ? workflowContext : {};
		const current = context.current_workflow && typeof context.current_workflow === 'object' ? context.current_workflow : {};
		const lastResult = context.last_workflow_result && typeof context.last_workflow_result === 'object'
			? context.last_workflow_result
			: {};
		const items = buildWorkflowHistoryItems(context);
		const hasCurrent = !!current.id;
		const hasLastResult = !!lastResult.id;
		const counts = {
			all: items.length,
			active: 0,
			failed: 0,
			executed: 0,
			cancelled: 0
		};

		items.forEach(function(item) {
			const status = String(item.status || '');

			if (isWorkflowStatusActive(status)) {
				counts.active += 1;
			}

			if (status === 'failed') {
				counts.failed += 1;
			}

			if (status === 'executed') {
				counts.executed += 1;
			}

			if (status === 'cancelled') {
				counts.cancelled += 1;
			}
		});

		let html = '<div class="yoro-context-card yoro-context-card-full yoro-workflow-history-panel">';
		html += '<div class="yoro-workflow-history-header">';
		html += '<div>';
		html += '<h3>Workflows</h3>';
		html += '<p class="yoro-result-subtle">Current workflow, recent outcomes, and quick recovery actions.</p>';
		html += '</div>';
		html += '<div class="yoro-workflow-history-actions">';

		if (context.can_continue) {
			html += '<button type="button" class="button button-small yoro-insert-command" data-command="Continue previous workflow">Continue</button>';
		}

		if (context.can_retry_failed_step) {
			html += '<button type="button" class="button button-small yoro-insert-command" data-command="Retry failed step">Retry failed step</button>';
		}

		html += '</div>';
		html += '</div>';

		html += '<div class="yoro-workflow-history-filters">';
		html += '<button type="button" class="button button-small yoro-workflow-filter-btn is-active" data-status-filter="all">All (' + h.escapeHtml(counts.all) + ')</button>';
		html += '<button type="button" class="button button-small yoro-workflow-filter-btn" data-status-filter="active">Active (' + h.escapeHtml(counts.active) + ')</button>';
		html += '<button type="button" class="button button-small yoro-workflow-filter-btn" data-status-filter="failed">Failed (' + h.escapeHtml(counts.failed) + ')</button>';
		html += '<button type="button" class="button button-small yoro-workflow-filter-btn" data-status-filter="executed">Executed (' + h.escapeHtml(counts.executed) + ')</button>';
		html += '<button type="button" class="button button-small yoro-workflow-filter-btn" data-status-filter="cancelled">Cancelled (' + h.escapeHtml(counts.cancelled) + ')</button>';
		html += '</div>';

		if (items.length) {
			html += '<div class="yoro-workflow-history-list">';

			items.forEach(function(item) {
				const status = String(item.status || '');
				const isCurrent = item.source === 'current';
				const showRetry = status === 'failed' && context.can_retry_failed_step;
				const showContinue = isCurrent && context.can_continue;

				html += '<div class="yoro-workflow-history-item' + (isCurrent ? ' is-current' : '') + '" data-workflow-status="' + h.escapeHtml(status) + '">';
				html += '<div class="yoro-workflow-history-item-main">';
				html += '<div class="yoro-workflow-history-item-title">';
				html += '<strong>' + h.escapeHtml(item.label || 'Workflow') + '</strong>';
				html += '<span class="yoro-workflow-state-badge">' + h.escapeHtml(toWorkflowStatusLabel(status || 'unknown')) + '</span>';
				html += '</div>';
				html += '<div class="yoro-workflow-history-item-meta">';

				if (item.definition_id) {
					html += '<span><strong>ID:</strong> ' + h.escapeHtml(item.definition_id) + '</span>';
				}

				if (item.current_step_id) {
					html += '<span><strong>Step:</strong> ' + h.escapeHtml(item.current_step_id) + '</span>';
				}

				if (typeof item.current_step_index === 'number' && item.current_step_index >= 0) {
					html += '<span><strong>Index:</strong> ' + h.escapeHtml(item.current_step_index + 1) + '</span>';
				}

				if (item.updated_at || item.updated_at_ts) {
					html += '<span><strong>Updated:</strong> ' + h.escapeHtml(formatWorkflowTimestamp(item.updated_at, item.updated_at_ts, h)) + '</span>';
				}

				html += '</div>';
				html += '</div>';
				html += '<div class="yoro-workflow-history-item-actions">';

				if (showContinue) {
					html += '<button type="button" class="button button-small yoro-insert-command" data-command="Continue previous workflow">Continue</button>';
				}

				if (showRetry) {
					html += '<button type="button" class="button button-small yoro-insert-command" data-command="Retry failed step">Retry</button>';
				}

				html += '</div>';
				html += '</div>';
			});

			html += '</div>';
			html += '<p class="yoro-workflow-history-empty" hidden>No workflows match this filter.</p>';
		} else {
			html += '<p class="yoro-result-subtle">No workflow history yet.</p>';
		}

		if (hasCurrent) {
			html += '<div class="yoro-workflow-history-detail">';
			html += '<strong>Current workflow</strong>';
			html += renderWorkflowStatePanel(current, h);
			html += '</div>';
		}

		if (hasLastResult && (!hasCurrent || lastResult.id !== current.id)) {
			html += '<div class="yoro-workflow-history-detail">';
			html += '<strong>Last completed workflow</strong>';
			html += renderWorkflowStatePanel(lastResult, h);
			html += '</div>';
		}

		html += '</div>';

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
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);
		html += '<div class="yoro-result-section">';
		html += '<strong>What I am focusing on</strong>';
		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Order:</strong> #' + h.escapeHtml(selectedItem.order_number || selectedItem.order_id || '') + '</span>';
		html += '<span><strong>Status:</strong> ' + h.escapeHtml(selectedItem.status || '—') + '</span>';
		html += '<span><strong>Queue:</strong> ' + h.escapeHtml(queueSource.label || queueSource.key || '—') + '</span>';
		html += '<span><strong>Age:</strong> ' + h.escapeHtml(formatAgeHours(selectedItem.age_hours)) + '</span>';
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
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);

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
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);

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
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);

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
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);

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

		const firstOrderIdNum = parseInt(firstOrderId, 10);

		if (firstOrderIdNum > 0) {
			suggestions.push({
				label: 'Open order details',
				action: 'orders.get_details',
				params: {
					order_id: firstOrderIdNum,
					order_ids: [firstOrderIdNum]
				}
			});
		}

		if (action === 'store.get_daily_briefing' || action === 'store.get_morning_briefing') {
			suggestions.push({
				label: 'Open attention queue',
				action: 'operations.get_attention_queue',
				params: {}
			});
			suggestions.push({
				label: 'Open fulfillment queue',
				action: 'operations.get_fulfillment_queue',
				params: {}
			});
			suggestions.push({
				label: 'Open inventory queue',
				action: 'products.get_inventory_queue',
				params: {}
			});
		}

		if (action === 'orders.hold' || action === 'orders.update_status' || action === 'orders.add_note' || action === 'orders.bulk_update_status_with_note') {
			suggestions.push({
				label: 'Open operations queue',
				action: 'operations.get_attention_queue',
				params: {}
			});
		}

		if (action === 'orders.email_customer_template') {
			suggestions.push({
				label: 'Open customer service queue',
				action: 'customers.get_service_queue',
				params: {}
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
			return item && item.action;
		}) : [];

		if (!steps.length) {
			return '';
		}

		let html = '<div class="yoro-result-section yoro-guided-result-section">';
		html += '<strong>' + h.escapeHtml(title || 'What to do next') + '</strong>';
		html += '<div class="yoro-suggestion-list">';
		steps.slice(0, 3).forEach(function(item) {
			html += '<button class="button button-small yoro-suggestion-btn"';
			html += ' data-action="' + h.escapeHtml(item.action) + '"';
			html += ' data-params="' + h.escapeHtml(JSON.stringify(item.params || {})) + '"';
			html += ' data-confirm="0" data-preview="0"';
			html += '>';
			html += h.escapeHtml(item.label || item.action);
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

		if (context.workflow) {
			html += renderWorkflowHistoryPanel(context.workflow, h);
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
		const workflow = getWorkflowFromData(data);
		const branchMeta = getWorkflowBranchMeta(workflow);

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
		if (branchMeta.outcome) {
			html += '<p>' + h.escapeHtml(branchMeta.outcome) + '</p>';
		}
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += renderWorkflowCompositionNotice(workflow, h);
		html += renderWorkflowStatePanel(workflow, h);

		html += '<div class="yoro-result-meta">';
		html += '<span><strong>Action:</strong> ' + h.escapeHtml(action) + '</span>';

		if (action !== 'assistant.submit_developer_request' && confirmationMeta.scopeLabel) {
			html += '<span><strong>Orders:</strong> ' + h.escapeHtml(confirmationMeta.scopeLabel) + '</span>';
		} else if (action !== 'assistant.submit_developer_request' && Array.isArray(params.order_ids) && params.order_ids.length) {
			html += '<span><strong>Orders:</strong> #' + params.order_ids.map(function(id) {
				return h.escapeHtml(id);
			}).join(', #') + '</span>';
		} else if (action !== 'assistant.submit_developer_request' && params.order_id) {
			html += '<span><strong>Order:</strong> #' + h.escapeHtml(params.order_id) + '</span>';
		}

		if (confirmationMeta.targetStatus) {
			html += '<span><strong>Target status:</strong> ' + h.escapeHtml(confirmationMeta.targetStatus) + '</span>';
		} else if (params.status) {
			html += '<span><strong>Target status:</strong> ' + h.escapeHtml(params.status) + '</span>';
		}

		if (branchMeta.label) {
			html += '<span><strong>Branch:</strong> ' + h.escapeHtml(branchMeta.label) + '</span>';
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

		if (
			(
				workflow.definition_id === 'write_email_for_order' ||
				workflow.definition_id === 'write_email_for_orders' ||
				workflow.definition_id === 'write_and_save_email_drafts_for_orders' ||
				workflow.definition_id === 'write_and_send_emails_for_orders'
			) &&
			branchMeta.outcome
		) {
			changeLines.push(branchMeta.outcome);
		}

		if (workflow.definition_id === 'verify_customer_before_fulfillment' && branchMeta.outcome) {
			changeLines.push(branchMeta.outcome);
		}

		if (workflow.definition_id === 'refund_review' && branchMeta.outcome) {
			changeLines.push(branchMeta.outcome);
		}

		html += renderWhatChangesSection('What will change after confirmation', changeLines, h);

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

	function renderWorkflowCompositionNotice(workflow, h) {
		workflow = workflow || {};

		const artifacts = workflow.artifacts && typeof workflow.artifacts === 'object' ? workflow.artifacts : {};
		const composition = artifacts.composition && typeof artifacts.composition === 'object' ? artifacts.composition : {};
		const unavailable = Array.isArray(composition.unavailable_requests) ? composition.unavailable_requests : [];

		if (!unavailable.length) {
			return '';
		}

		let html = '<div class="yoro-result-notice yoro-result-preview">';
		html += '<strong>Some requested actions are not available yet</strong>';
		html += '<ul>';

		unavailable.forEach(function(item) {
			if (!item) {
				return;
			}

			html += '<li>' + h.escapeHtml(item.label || item.key || 'Unavailable action');

			if (item.message) {
				html += ' — ' + h.escapeHtml(item.message);
			}

			html += '</li>';
		});

		html += '</ul>';
		html += '</div>';

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
		let html = '<div class="yoro-confirmation-result">';

		html += '<div class="yoro-result-notice yoro-result-confirm">';
		html += '<strong>Confirm store change</strong>';
		html += '<p>' + h.escapeHtml(data.message || result.confirmation_scope || 'Review this fulfillment follow-up before saving the draft.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);
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
		let html = '<div class="yoro-confirmation-result">';

		html += '<div class="yoro-result-notice yoro-result-confirm">';
		html += '<strong>Confirm store change</strong>';
		html += '<p>' + h.escapeHtml(data.message || result.confirmation_scope || 'Review this customer follow-up before saving the draft.') + '</p>';
		html += '</div>';

		html += '<div class="yoro-confirmation-body">';
		html += renderAdminCopilotBoundary(data, h);
		html += renderWorkflowStatePanel(getWorkflowFromData(data), h);
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
		const options = shouldRenderClarifyOptions(parsed) && Array.isArray(parsed.options) ? parsed.options : [];

		let html = '<div class="yoro-assistant-message-result yoro-clarification-message-result">';
		html += '<p>' + h.escapeHtml(message) + '</p>';

		if (suggestion) {
			html += '<p class="yoro-result-subtle">Best guess: ' + h.escapeHtml(suggestion) + '</p>';
		}

		if (options.length) {
			html += '<div class="yoro-clarify-options">';

			options.forEach(function (option) {
				const command = option && option.command ? String(option.command) : '';
				const label = option && option.label ? String(option.label) : command || 'Use this';
				const cancelClass = command === 'workflow.cancel' ? ' yoro-clarify-option-cancel' : '';

				html += '<button type="button" class="button yoro-clarify-option' + cancelClass + '" data-command="' + h.escapeHtml(command) + '">';
				html += h.escapeHtml(label);
				html += '</button>';
			});

			html += '</div>';
		}

		html += '</div>';

		return html;
	}

	function shouldRenderClarifyOptions(parsed) {
		parsed = parsed && typeof parsed === 'object' ? parsed : {};

		if (parsed.current_workflow) {
			return true;
		}

		const reason = String(parsed.clarify_reason || '');
		const action = String(parsed.action || '');
		const missing = Array.isArray(parsed.missing) ? parsed.missing : [];
		const shape = parsed.command_shape && typeof parsed.command_shape === 'object' ? parsed.command_shape : {};
		const operationHint = String(shape.operation_hint || '');

		if (
			reason === 'missing_target' &&
			action &&
			missing.length &&
			(operationHint === 'write_missing_target' || action.indexOf('orders.') === 0 || action.indexOf('workflow.') === 0)
		) {
			return false;
		}

		return true;
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

		if (h.shouldShowDebugJson && h.shouldShowDebugJson() && parsed.parser_rule) {
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
		suggestions = Array.isArray(suggestions)
			? suggestions.filter(function(item) {
				return item && (item.action || item.command || item.value);
			})
			: [];

		if (!suggestions.length) {
			return '';
		}

		let html = '<div class="yoro-suggestions">';
		html += '<strong>Suggestions:</strong>';

		html += '<div class="yoro-suggestion-list">';

		suggestions.forEach(function (item) {
			html += '<button class="button button-small yoro-suggestion-btn"';
			if (item.action) {
				html += ' data-action="' + h.escapeHtml(item.action) + '"';
				html += ' data-params="' + h.escapeHtml(JSON.stringify(item.params || {})) + '"';
				html += ' data-confirm="' + (item.confirm ? '1' : '0') + '"';
				html += ' data-preview="' + (item.preview ? '1' : '0') + '"';
			}

			if (item.command || item.value) {
				html += ' data-command="' + h.escapeHtml(item.command || item.value) + '"';
			}

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

		html += renderTraceReview(data, h);

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

	function renderTraceReview(data, h) {
		const review = buildTraceReview(data);

		if (!review.length) {
			return '';
		}

		let html = '<div class="yoro-trace-review">';
		html += '<div class="yoro-trace-review-title">Trace Review</div>';
		html += '<ol class="yoro-trace-review-steps">';

		review.forEach(function (step) {
			const detail = step.detail ? '<small>' + h.escapeHtml(step.detail) + '</small>' : '';
			html += '<li>';
			html += '<strong>' + h.escapeHtml(step.label) + '</strong>';
			html += '<span>' + h.escapeHtml(step.summary || '-') + '</span>';
			html += detail;
			html += '</li>';
		});

		html += '</ol></div>';

		return html;
	}

	function buildTraceReview(data) {
		data = data && typeof data === 'object' ? data : {};
		const parsed = data.parsed && typeof data.parsed === 'object' ? data.parsed : {};
		const plan = data.plan && typeof data.plan === 'object' ? data.plan : {};
		const result = data.result && typeof data.result === 'object' ? data.result : {};
		const telemetry = data.telemetry && typeof data.telemetry === 'object' ? data.telemetry : {};
		const runtime = telemetry.runtime_snapshot && typeof telemetry.runtime_snapshot === 'object' ? telemetry.runtime_snapshot : {};
		const shape = parsed.command_shape && typeof parsed.command_shape === 'object'
			? parsed.command_shape
			: (telemetry.command_shape && typeof telemetry.command_shape === 'object' ? telemetry.command_shape : {});
		const decision = parsed.command_decision && typeof parsed.command_decision === 'object' ? parsed.command_decision : {};
		const command = parsed.original_command ||
			(parsed.params && parsed.params.original_command) ||
			(plan.params && plan.params.original_command) ||
			'';

		const steps = [
			{
				label: 'Command',
				summary: command,
				detail: parsed.normalized_command && parsed.normalized_command !== command
					? 'normalized: ' + parsed.normalized_command
					: ''
			},
			{
				label: 'Command shape',
				summary: formatShapeSummary(shape),
				detail: formatShapeDetail(shape)
			},
			{
				label: 'AI decision',
				summary: formatDecisionSummary(parsed, decision),
				detail: formatDecisionDetail(parsed, decision)
			},
			{
				label: 'Validator changes',
				summary: formatValidatorSummary(parsed, plan),
				detail: formatValidatorDetail(parsed, plan)
			},
			{
				label: 'Final action',
				summary: formatFinalAction(parsed, plan, runtime),
				detail: formatRuntimeDetail(runtime)
			},
			{
				label: 'Result',
				summary: formatResultSummary(data, result),
				detail: formatResultDetail(result)
			}
		];

		return steps.filter(function (step) {
			return !!(step.summary || step.detail);
		});
	}

	function formatShapeSummary(shape) {
		if (!shape || typeof shape !== 'object') {
			return '';
		}

		const parts = [];

		['surface', 'target_type', 'operation_hint'].forEach(function (key) {
			if (shape[key]) {
				parts.push(key + '=' + shape[key]);
			}
		});

		if (shape.status) {
			parts.push('status=' + shape.status);
		}

		if (shape.quantity) {
			parts.push('quantity=' + shape.quantity);
		}

		if (shape.age_filter && typeof shape.age_filter === 'object' && shape.age_filter.operator) {
			parts.push('age=' + shape.age_filter.operator + ' ' + (shape.age_filter.value || '') + ' ' + (shape.age_filter.unit || ''));
		}

		if (shape.money_filter && typeof shape.money_filter === 'object' && shape.money_filter.operator) {
			parts.push('money=' + shape.money_filter.operator + ' ' + (shape.money_filter.value || ''));
		}

		return parts.join(' | ');
	}

	function formatShapeDetail(shape) {
		if (!shape || typeof shape !== 'object') {
			return '';
		}

		const parts = [];

		if (Array.isArray(shape.explicit_order_ids) && shape.explicit_order_ids.length) {
			parts.push('order_ids: ' + shape.explicit_order_ids.join(', '));
		}

		if (shape.quantity_scope && typeof shape.quantity_scope === 'object' && shape.quantity_scope.scope) {
			parts.push('quantity_scope: ' + shape.quantity_scope.scope);
		}

		if (shape.customer_identity && typeof shape.customer_identity === 'object' && Object.keys(shape.customer_identity).length) {
			parts.push('customer_identity: ' + compactObject(shape.customer_identity));
		}

		if (shape.payment_method && typeof shape.payment_method === 'object' && Object.keys(shape.payment_method).length) {
			parts.push('payment_method: ' + compactObject(shape.payment_method));
		}

		if (shape.shipping_state && typeof shape.shipping_state === 'object' && Object.keys(shape.shipping_state).length) {
			parts.push('shipping_state: ' + compactObject(shape.shipping_state));
		}

		if (shape.write_missing_target && typeof shape.write_missing_target === 'object' && Object.keys(shape.write_missing_target).length) {
			parts.push('write_missing_target: ' + compactObject(shape.write_missing_target));
		}

		if (Array.isArray(shape.signals) && shape.signals.length) {
			parts.push('signals: ' + shape.signals.slice(0, 8).join(', '));
		}

		return parts.join(' | ');
	}

	function formatDecisionSummary(parsed, decision) {
		const parts = [];
		const confidence = typeof decision.confidence !== 'undefined' ? decision.confidence : parsed.confidence;

		if (decision.mode) {
			parts.push('mode=' + decision.mode);
		}

		if (decision.intent_family || parsed.intent_family) {
			parts.push('intent=' + (decision.intent_family || parsed.intent_family));
		}

		if (decision.action || parsed.action) {
			parts.push('action=' + (decision.action || parsed.action));
		}

		if (typeof confidence !== 'undefined' && confidence !== null && !Number.isNaN(Number(confidence))) {
			parts.push('confidence=' + Math.round(Number(confidence) * 100) + '%');
		}

		return parts.join(' | ');
	}

	function formatDecisionDetail(parsed, decision) {
		const parts = [];

		if (parsed.parser_source) {
			parts.push('source: ' + parsed.parser_source);
		}

		if (parsed.parser_rule) {
			parts.push('rule: ' + parsed.parser_rule);
		}

		if (parsed.resolution_path) {
			parts.push('resolution: ' + parsed.resolution_path);
		}

		if (typeof decision.catalog_action_count !== 'undefined') {
			parts.push('catalog_actions: ' + decision.catalog_action_count);
		}

		return parts.join(' | ');
	}

	function formatValidatorSummary(parsed, plan) {
		const semantic = plan.validation && plan.validation.semantic ? plan.validation.semantic : {};
		const parts = [];

		parts.push('missing=' + formatArray(parsed.missing));
		parts.push('ambiguities=' + formatArray(parsed.ambiguities));
		parts.push('dropped=' + formatArray(parsed.dropped_param_keys));

		if (typeof parsed.params_trimmed_count !== 'undefined') {
			parts.push('trimmed=' + parsed.params_trimmed_count);
		}

		if (typeof semantic.passed !== 'undefined') {
			parts.push('semantic=' + (semantic.passed ? 'passed' : 'failed'));
		}

		return parts.join(' | ');
	}

	function formatValidatorDetail(parsed, plan) {
		const semantic = plan.validation && plan.validation.semantic ? plan.validation.semantic : {};
		const parts = [];

		if (Array.isArray(parsed.raw_param_keys) && parsed.raw_param_keys.length) {
			parts.push('raw_params: ' + parsed.raw_param_keys.join(', '));
		}

		if (Array.isArray(semantic.warnings) && semantic.warnings.length) {
			parts.push('semantic_warnings: ' + semantic.warnings.map(formatTraceValue).join(' | '));
		}

		if (parsed.params && typeof parsed.params === 'object') {
			const params = Object.assign({}, parsed.params);
			delete params.original_command;
			if (Object.keys(params).length) {
				parts.push('final_params: ' + compactObject(params));
			}
		}

		return parts.join(' | ');
	}

	function formatFinalAction(parsed, plan, runtime) {
		const parts = [];

		if (plan.type) {
			parts.push('plan=' + plan.type);
		}

		if (plan.action || parsed.action) {
			parts.push('action=' + (plan.action || parsed.action));
		}

		return parts.join(' | ');
	}

	function formatRuntimeDetail(runtime) {
		const flags = [];

		['preview', 'confirm', 'requires_confirmation', 'policy_requires_confirmation', 'risk_requires_confirmation'].forEach(function (key) {
			if (runtime[key]) {
				flags.push(key);
			}
		});

		if (runtime.reason) {
			flags.push('reason: ' + runtime.reason);
		}

		return flags.length ? 'runtime: ' + flags.join(', ') : '';
	}

	function formatResultSummary(data, result) {
		const parts = [];

		if (data.stage) {
			parts.push('stage=' + data.stage);
		}

		if (typeof data.success !== 'undefined') {
			parts.push(data.success ? 'success' : 'failed');
		}

		if (result.display_type) {
			parts.push('display=' + result.display_type);
		}

		if (typeof result.count !== 'undefined') {
			parts.push('count=' + result.count);
		}

		if (result.summary || result.message || data.message) {
			parts.push(result.summary || result.message || data.message);
		}

		return parts.join(' | ');
	}

	function formatResultDetail(result) {
		const parts = [];

		if (result.result_type) {
			parts.push('result_type: ' + result.result_type);
		}

		if (typeof result.read_only !== 'undefined') {
			parts.push('read_only: ' + (result.read_only ? 'yes' : 'no'));
		}

		if (typeof result.undoable !== 'undefined') {
			parts.push('undoable: ' + (result.undoable ? 'yes' : 'no'));
		}

		if (Array.isArray(result.errors) && result.errors.length) {
			parts.push('errors: ' + result.errors.length);
		}

		return parts.join(' | ');
	}

	function formatArray(items) {
		return Array.isArray(items) && items.length ? '[' + items.map(formatTraceValue).join(', ') + ']' : '[]';
	}

	function compactObject(value) {
		if (!value || typeof value !== 'object') {
			return '';
		}

		return Object.keys(value).slice(0, 8).map(function (key) {
			const item = value[key];

			if (Array.isArray(item)) {
				return key + '=[' + item.map(formatTraceValue).join(',') + ']';
			}

			if (item && typeof item === 'object') {
				return key + '={' + compactObject(item) + '}';
			}

			return key + '=' + String(item);
		}).join(', ');
	}

	function formatTraceValue(value) {
		if (value === null || typeof value === 'undefined') {
			return '';
		}

		if (Array.isArray(value)) {
			return '[' + value.map(formatTraceValue).join(',') + ']';
		}

		if (typeof value === 'object') {
			if (value.message) {
				return String(value.message);
			}

			if (value.code) {
				return String(value.code);
			}

			return compactObject(value);
		}

		return String(value);
	}

	window.YoRoResultRenderers = {
		init: init
	};
})();
