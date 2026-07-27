(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml || function(value) {
				return String(value === null || value === undefined ? '' : value);
			},
			shouldShowDebugJson: helpers.shouldShowDebugJson || function() {
				return false;
			},
			renderDebugJson: helpers.renderDebugJson || function() {
				return '';
			},
			renderJson: helpers.renderJson || function(data) {
				return '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
			},
			renderCustomerEmailPreviewResult: helpers.renderCustomerEmailPreviewResult || null,
			renderStepResult: helpers.renderStepResult || null
		};
	}

	function renderOrderLabel(ids, h) {
		ids = Array.isArray(ids)
			? ids.filter(Boolean)
			: [];

		if (!ids.length) {
			return '';
		}

		if (ids.length === 1) {
			return '<p><strong>Order:</strong> #' + h.escapeHtml(ids[0]) + '</p>';
		}

		return '<p><strong>Orders:</strong> ' + ids.map(function(id) {
			return '#' + h.escapeHtml(id);
		}).join(', ') + '</p>';
	}

	function displayLabel(value, options) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.labelFor === 'function') {
			return labels.labelFor(value, options);
		}

		return String(value || '').replace(/[_-]+/g, ' ');
	}

	function humanWorkflowActionLabel(action) {
		const labels = {
			'orders.search': 'Find orders',
			'orders.get_details': 'Load order details',
			'orders.update_status': 'Update order status',
			'orders.add_note': 'Add internal note',
			'orders.compose_customer_email': 'Draft customer email',
			'orders.render_customer_email_preview': 'Preview customer email',
			'orders.save_customer_email_draft': 'Save email draft',
			'orders.send_customer_email': 'Send customer email',
			'orders.remove_note': 'Remove note',
			'orders.restore_statuses': 'Restore status',
			'products.update_stock': 'Update product stock',
			'products.update_stock_status': 'Update product stock status',
			'store_preferences.save': 'Save store preference'
		};

		if (labels[action]) {
			return labels[action];
		}

		return String(action || 'Step')
			.replace(/^orders\./, '')
			.replace(/^workflow\./, '')
			.replace(/^pending_action\./, '')
			.split('.')
			.map(function(part) {
				return displayLabel(part);
			})
			.join(' ');
	}

	function orderStatusLabel(value) {
		const raw = String(value || '').trim();
		const key = raw
			.toLowerCase()
			.replace(/^wc-/, '')
			.replace(/_/g, '-')
			.replace(/\s+/g, '-');
		const labels = {
			pending: 'Pending payment',
			processing: 'Processing',
			completed: 'Completed',
			'on-hold': 'On hold',
			cancelled: 'Cancelled',
			canceled: 'Cancelled',
			refunded: 'Refunded',
			failed: 'Failed'
		};

		if (labels[key]) {
			return labels[key];
		}

		return raw.replace(/^wc-/, '').replace(/[_-]/g, ' ').replace(/\b\w/g, function(chr) {
			return chr.toUpperCase();
		});
	}

	function renderWorkflowStateCard(state, h) {
		state = state || {};
		const label = state.label || '';
		const message = state.message || '';

			if (!label && !message) {
				return '';
			}

		let html = '<div class="yoro-plan-state-card">';
		if (label) {
			html += '<strong>' + h.escapeHtml(label) + '</strong>';
		}
		if (message) {
			html += '<p>' + h.escapeHtml(message) + '</p>';
		}
			html += '</div>';
		return html;
	}

	function toWorkflowStatusLabel(status) {
		const rawStatus = String(status || '').trim();
		const labels = {
			collect_missing_params: 'Needs your reply',
			waiting_for_approval: 'Waiting for approval',
			confirm_required: 'Waiting for approval',
			confirmation_required: 'Waiting for approval',
			pending_confirmation: 'Waiting for approval',
			composed: 'Ready for review',
			preview: 'Ready for review',
			running: 'Running',
			completed: 'Done',
			executed: 'Done',
			failed: 'Needs attention',
			error: 'Needs attention'
		};

		if (labels[rawStatus]) {
			return labels[rawStatus];
		}

		status = displayLabel(rawStatus).trim();

		if (!status) {
			return 'Unknown';
		}

		return status.charAt(0).toUpperCase() + status.slice(1);
	}

	function getWorkflowBranchMeta(workflow) {
		workflow = workflow || {};

		const definitionId = workflow.definition_id || workflow.type || '';
		const branch = workflow.branch && typeof workflow.branch === 'object' ? workflow.branch : {};
		const deliveryMode = branch.delivery_mode || '';
		const emailDeliveryMode = branch.email_delivery_mode || '';

		if (definitionId === 'write_email_for_order' || definitionId === 'write_email_for_orders' || definitionId === 'write_and_save_email_drafts_for_orders' || definitionId === 'write_and_send_emails_for_orders') {
			const plural = definitionId === 'write_email_for_orders' || definitionId === 'write_and_save_email_drafts_for_orders' || definitionId === 'write_and_send_emails_for_orders';
			if (deliveryMode === 'save_draft') {
				return { label: 'Save as draft', successTitle: plural ? 'Email drafts saved' : 'Email draft saved', successMessage: plural ? 'I wrote the emails and saved drafts on each order.' : 'I wrote the email and saved the draft on the order.' };
			}
			if (deliveryMode === 'send') {
				return { label: 'Send email', successTitle: plural ? 'Emails sent' : 'Email sent', successMessage: plural ? 'I wrote the emails and sent them to the customers.' : 'I wrote the email and sent it to the customer.' };
			}
			return { label: 'Preview email', successTitle: plural ? 'Emails ready for review' : 'Email ready for review', successMessage: plural ? 'I prepared the emails below for review.' : 'I prepared the email below for review.' };
		}

		if (definitionId === 'verify_customer_before_fulfillment') {
			if (emailDeliveryMode === 'save_draft') {
				return { label: 'Save verification draft', successTitle: 'Verification draft saved', successMessage: 'I held the order, added the verification note, and saved the verification draft.' };
			}
			if (emailDeliveryMode === 'send') {
				return { label: 'Send verification email', successTitle: 'Verification email sent', successMessage: 'I held the order, added the verification note, and sent the verification email.' };
			}
			return { label: 'Preview verification email', successTitle: 'Verification ready', successMessage: 'I held the order, added the verification note, and prepared the email for review.' };
		}

		if (definitionId === 'refund_review') {
			if (deliveryMode === 'save_draft') {
				return { label: 'Save refund follow-up draft', successTitle: 'Refund draft saved', successMessage: 'I reviewed the refund and saved the follow-up draft.' };
			}
			if (deliveryMode === 'send') {
				return { label: 'Send refund follow-up email', successTitle: 'Refund email sent', successMessage: 'I reviewed the refund and sent the follow-up email.' };
			}
			return { label: 'Review only', successTitle: 'Refund review completed', successMessage: 'I reviewed the refundable amount and order details.' };
		}

		return { label: '', successTitle: 'Completed', successMessage: 'I finished those steps.' };
	}

	function workflowStateNeedsApproval(status) {
		const key = String(status || '').trim();

		return [
			'waiting_for_approval',
			'confirm_required',
			'confirmation_required',
			'pending_confirmation'
		].indexOf(key) !== -1;
	}

	function workflowStateIsTerminal(status) {
		const key = String(status || '').trim();

		return [
			'completed',
			'completed_with_issues',
			'executed',
			'failed',
			'cancelled'
		].indexOf(key) !== -1;
	}

	function workflowStateIsPostApproval(status) {
		const key = String(status || '').trim();

		return workflowStateIsTerminal(key) || [
			'confirmed',
			'running',
			'preview',
			'email_preview_ready'
		].indexOf(key) !== -1;
	}

	function workflowStatePanelTitle(status) {
		if (workflowStateNeedsApproval(status)) {
			return 'Review plan';
		}

		if (workflowStateIsTerminal(status) || String(status || '').trim() === 'email_preview_ready') {
			return 'Execution summary';
		}

		return 'Plan';
	}

	function workflowStatePanelSummary(stepCount, needsApproval, status) {
		const key = String(status || '').trim();

		if (stepCount > 0 && needsApproval) {
			return stepCount + (stepCount === 1 ? ' step is ready. Nothing changes until you approve.' : ' steps are ready. Nothing changes until you approve.');
		}

		if (stepCount > 0 && key === 'email_preview_ready') {
			return stepCount + (stepCount === 1 ? ' step completed. Review the email below before sending.' : ' steps completed. Review the email below before sending.');
		}

		if (stepCount > 0 && workflowStateIsTerminal(key)) {
			return stepCount + (stepCount === 1 ? ' step completed.' : ' steps completed.');
		}

		if (stepCount > 0) {
			return stepCount + (stepCount === 1 ? ' step in this plan.' : ' steps in this plan.');
		}

		if (needsApproval) {
			return 'Review the pending action before it changes the store.';
		}

		return '';
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

	function renderWorkflowStatePanel(workflow, h, plannedActions) {
		workflow = workflow || {};
		plannedActions = Array.isArray(plannedActions) ? plannedActions : [];

		const steps = Array.isArray(workflow.steps) ? workflow.steps : [];
		const status = workflow.status || workflow.step || '';
		const currentStepId = workflow.current_step_id || '';
		const definitionId = workflow.definition_id || workflow.type || '';
		const plannedStepCount = steps.length || plannedActions.length;
		const needsApproval = workflowStateNeedsApproval(status) ||
			(!workflowStateIsPostApproval(status) && workflowActionsRequireConfirmation(plannedActions));

		if (!status && !currentStepId && !definitionId && !steps.length && !plannedActions.length) {
			return '';
		}

		let html = '<div class="yoro-plan-state-panel">';
		html += '<div class="yoro-plan-state-panel-header">';
		html += '<div class="yoro-plan-state-heading">';
		html += '<strong>' + h.escapeHtml(workflowStatePanelTitle(status)) + '</strong>';
		const summary = workflowStatePanelSummary(plannedStepCount, needsApproval, status);
		if (summary) {
			html += '<p>' + h.escapeHtml(summary) + '</p>';
		}
		html += '</div>';
		html += '<span class="yoro-plan-state-badge">' + h.escapeHtml(toWorkflowStatusLabel(status || (needsApproval ? 'confirm_required' : 'unknown'))) + '</span>';
		html += '</div>';
		html += '<div class="yoro-plan-state-meta">';

		if (typeof workflow.current_step_index === 'number' && workflow.current_step_index >= 0) {
			html += '<span><strong>Now:</strong> step ' + h.escapeHtml(workflow.current_step_index + 1) + '</span>';
		}

		const branchMeta = getWorkflowBranchMeta(workflow);

		if (branchMeta.label) {
			html += '<span><strong>Email handling:</strong> ' + h.escapeHtml(branchMeta.label) + '</span>';
		}

		if (h.shouldShowDebugJson && h.shouldShowDebugJson()) {
			if (definitionId) {
				html += '<span><strong>Flow:</strong> ' + h.escapeHtml(definitionId) + '</span>';
			}

			if (currentStepId) {
				html += '<span><strong>Step id:</strong> ' + h.escapeHtml(currentStepId) + '</span>';
			}
		}

		html += '</div>';

		if (!steps.length && plannedActions.length) {
			html += '<div class="yoro-plan-state-actions">';
			plannedActions.slice(0, 4).forEach(function(step, index) {
				step = step || {};
				html += '<span class="yoro-plan-state-action">';
				html += '<span class="yoro-plan-state-action-index">' + h.escapeHtml(index + 1) + '</span>';
				html += '<span>' + h.escapeHtml(step.label || humanWorkflowActionLabel(step.action) || ('Step ' + (index + 1))) + '</span>';
				html += '</span>';
			});

			if (plannedActions.length > 4) {
				html += '<span class="yoro-plan-state-action yoro-plan-state-action-more">+' + h.escapeHtml(plannedActions.length - 4) + ' more</span>';
			}
			html += '</div>';
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
				html += '<span class="yoro-workflow-timeline-step-label">' + h.escapeHtml(step.label || humanWorkflowActionLabel(step.action) || ('Step ' + (index + 1))) + '</span>';
				html += '<span class="yoro-workflow-timeline-step-status">' + h.escapeHtml(toWorkflowStatusLabel(stepStatus)) + '</span>';
				html += '</div>';
				html += '<div class="yoro-workflow-timeline-step-meta">';

				if (h.shouldShowDebugJson && h.shouldShowDebugJson() && step.id) {
					html += '<span>ID: ' + h.escapeHtml(step.id) + '</span>';
				}

				if (h.shouldShowDebugJson && h.shouldShowDebugJson() && step.action) {
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

	function shouldRenderWorkflowResultStep(item, hasPendingEmail) {
		item = item || {};

		const result = item.result || {};
		const skipped = !!item.skipped || result.skipped === true;

		if (skipped) {
			return false;
		}

		return true;
	}

	function containsWorkflowPlaceholder(value) {
		return typeof value === 'string' && /{{\s*[^}]+\s*}}/.test(value);
	}

	function isResolvedCustomerEmailPreviewResult(stepResult) {
		stepResult = stepResult || {};

		if (stepResult.display_type !== 'customer_email_preview') {
			return false;
		}

		const email = stepResult.email || {};
		const subject = email.subject || '';
		const body = email.body || '';

		if (!subject || !body) {
			return false;
		}

		return !containsWorkflowPlaceholder(subject) && !containsWorkflowPlaceholder(body);
	}

	function buildWorkflowEmailPreviewFromDraftResult(item) {
		item = item || {};

		const stepResult = item.result || {};
		const draft = stepResult.draft || {};
		const order = stepResult.order || {};
		const workflow = stepResult.workflow || {};

		if (!draft.subject || !draft.body) {
			return null;
		}

		if (containsWorkflowPlaceholder(draft.subject) || containsWorkflowPlaceholder(draft.body)) {
			return null;
		}

		return {
			success: true,
			action: 'orders.render_customer_email_preview',
			summary: stepResult.summary || '',
			message: stepResult.message || '',
			read_only: true,
			display_type: 'customer_email_preview',
			result_type: 'customer_email',
			workflow: workflow,
			order: order,
			email: {
				template: 'ai_custom',
				to: order.billing_email || '',
				subject: draft.subject,
				body: draft.body,
				html_body: ''
			},
			send_payload: {
				order_id: stepResult.order_id || order.id || workflow.order_id || '',
				subject: draft.subject,
				body: draft.body,
				draft_id: draft.id || ''
			}
		};
	}

	function findBestWorkflowEmailPreview(actions) {
		actions = Array.isArray(actions) ? actions : [];

		const preferredActions = [
			'orders.compose_customer_email',
			'orders.render_customer_email_preview'
		];

		for (let i = 0; i < preferredActions.length; i++) {
			const preferredAction = preferredActions[i];
			const match = actions.find(function(item) {
				const stepResult = item && item.result ? item.result : {};

				return item &&
					item.action === preferredAction &&
					isResolvedCustomerEmailPreviewResult(stepResult);
			});

			if (match) {
				return match.result;
			}
		}

		const draftCarrier = actions.find(function(item) {
			return item && [
				'orders.save_customer_email_draft',
				'orders.update_customer_email_draft'
			].indexOf(item.action || '') !== -1;
		});

		if (draftCarrier) {
			return buildWorkflowEmailPreviewFromDraftResult(draftCarrier);
		}

		return null;
	}

	function buildWorkflowPreviewState(requiresConfirmation, parsed, actions) {
		const actionCount = Array.isArray(actions) ? actions.length : 0;

		if (requiresConfirmation) {
			return {
				label: 'Please approve these changes',
				message: 'I prepared ' + actionCount + ' step' + (actionCount === 1 ? '' : 's') + '. I’ll wait for your approval before I update your store.',
				next_actions: [
					{ label: 'Approve changes', command: 'Confirm' }
				]
			};
		}

		return {
			label: parsed && parsed.workflow_label ? parsed.workflow_label : 'Plan ready',
			message: 'I prepared the steps below so you can review them before continuing.',
			next_actions: []
		};
	}

	function buildWorkflowResultState(actions) {
		const items = Array.isArray(actions) ? actions : [];
		const hasEmailDraft = items.some(function (item) {
			const stepResult = item && item.result ? item.result : {};
			return stepResult.display_type === 'customer_email_preview' || stepResult.send_payload;
		});

		if (hasEmailDraft) {
			return {
				label: 'Email draft ready for review',
				message: 'I finished the steps and left you at the email review step.',
				next_actions: [
					{ label: 'Open customer service queue', command: 'Show customer service queue' }
				]
			};
		}

			return {
				label: 'Done',
				message: 'I finished those steps.',
				next_actions: []
			};
		}

	function renderWorkflowPreview(data, helpers) {
		const h = getHelpers(helpers);
		const preview = data.preview || {};
		const parsed = data.parsed || {};
		const result = data.result || {};
		const plan = data.plan || {};

		const actions = Array.isArray(preview.actions)
			? preview.actions
			: (
				Array.isArray(result.actions)
					? result.actions
					: (
						Array.isArray(plan.steps)
							? plan.steps
							: []
					)
			);

		const requiresConfirmation =
			data.confirm_required === true ||
			data.stage === 'confirm' ||
			preview.requires_confirmation === true ||
			preview.confirm_required === true ||
			data.requires_confirmation === true ||
			(data.result && data.result.requires_confirmation === true) ||
			workflowActionsRequireConfirmation(actions);

		let html = '';
		html += '<div class="yoro-workflow-preview">';

		if (requiresConfirmation) {
			html += '<div class="yoro-friendly-action-card yoro-friendly-action-card-approval">';
			html += '<div class="yoro-friendly-action-copy">';
			html += '<span class="yoro-friendly-action-kicker">Needs your approval</span>';
			html += '<strong>Please approve these changes</strong>';
			html += '<p>I prepared the steps below. I’ll wait for your approval before I update your store.</p>';
			html += '</div>';
			html += '<p class="yoro-friendly-action-note">Nothing has changed yet.</p>';
			html += '</div>';
		} else {
			html += '<div class="yoro-result-notice yoro-result-preview">';
			html += '<strong>Plan ready</strong>';
			html += '<p>I prepared the steps below.</p>';
			html += '</div>';
		}

		html += '<div class="yoro-workflow-preview-body">';
		html += renderWorkflowCompositionNotice(result.workflow || plan.workflow || {}, h);
		html += renderWorkflowStatePanel(result.workflow || plan.workflow || {}, h, actions);
		if (parsed.workflow_label) {
			html += '<div class="yoro-workflow-title">';
			html += '<strong>' + h.escapeHtml(parsed.workflow_label) + '</strong>';
			html += '</div>';
		}

		html += '<div class="yoro-workflow-steps">';

		actions.forEach(function(step, index) {
			step = step || {};

			const action = step.action || '';
			const params = step.params || {};
			const renderer = workflowRenderers[action] && workflowRenderers[action].preview;

			if (typeof renderer === 'function') {
				html += renderer(step, index, data, h);
				return;
			}

			html += renderWorkflowPreviewGenericStep(action, params, index, h);
		});

		html += '</div>';

		if (requiresConfirmation) {
			html += `
			<div class="yoro-confirm-actions">
				<button class="button button-primary yoro-confirm-run">Approve changes</button>
				<button class="button yoro-confirm-cancel">Cancel</button>
			</div>
			`;
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewGenericStep(action, params, index, h) {
		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>' + h.escapeHtml(humanWorkflowActionLabel(action)) + '</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';
		const ids = Array.isArray(params.order_ids) && params.order_ids.length
			? params.order_ids
			: (params.order_id ? [params.order_id] : []);

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		}

		html += '<p>I’ll include this step when you approve the plan.</p>';
		html += '</div>';
		html += '</div>';

		return html;
	}

	function getProductStockPreview(data) {
		data = data || {};

		const result = data.result && typeof data.result === 'object' ? data.result : {};
		const preview = data.preview && typeof data.preview === 'object' ? data.preview : {};
		const candidates = [
			result.preview,
			result.plan,
			preview.plan,
			preview
		];

		for (let i = 0; i < candidates.length; i += 1) {
			if (candidates[i] && typeof candidates[i] === 'object' && Array.isArray(candidates[i].products)) {
				return candidates[i];
			}
		}

		return {};
	}

	function renderWorkflowPreviewProductStockStatus(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const preview = getProductStockPreview(data);
		const product = Array.isArray(preview.products) && preview.products.length
			? preview.products[0]
			: {};
		const productId = product.id || params.product_id || '';
		const targetStatus = product.target_stock_status_label || product.target_stock_status || params.stock_status || '';
		const currentStatus = product.current_stock_status_label || product.current_stock_status || '';
		const message = preview.message || preview.summary || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Update product stock status</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';

		if (message) {
			html += '<p>' + h.escapeHtml(message) + '</p>';
		}

		if (productId) {
			html += '<p><strong>Product:</strong> #' + h.escapeHtml(productId);

			if (product.name) {
				html += ' · ' + h.escapeHtml(product.name);
			}

			html += '</p>';
		}

		if (targetStatus) {
			html += '<p><strong>Target status:</strong> ' + h.escapeHtml(targetStatus) + '</p>';
		}

		if (currentStatus) {
			html += '<p><strong>Current status:</strong> ' + h.escapeHtml(currentStatus) + '</p>';
		}

		if (preview.blocked) {
			html += '<p class="yoro-result-subtle"><strong>Blocked:</strong> ' + h.escapeHtml(preview.block_reason || 'stock_status_managed_by_quantity') + '</p>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewProductStock(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const preview = getProductStockPreview(data);
		const product = Array.isArray(preview.products) && preview.products.length
			? preview.products[0]
			: {};
		const productId = product.id || params.product_id || '';
		const targetStock = product.target_stock_quantity !== undefined && product.target_stock_quantity !== null
			? product.target_stock_quantity
			: params.stock_quantity;
		const message = preview.message || preview.summary || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Update product stock</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';

		if (message) {
			html += '<p>' + h.escapeHtml(message) + '</p>';
		}

		if (productId) {
			html += '<p><strong>Product:</strong> #' + h.escapeHtml(productId);

			if (product.name) {
				html += ' · ' + h.escapeHtml(product.name);
			}

			html += '</p>';
		}

		if (targetStock !== undefined && targetStock !== null && targetStock !== '') {
			html += '<p><strong>Target stock:</strong> ' + h.escapeHtml(targetStock) + '</p>';
		}

		if (product.current_stock_quantity !== undefined && product.current_stock_quantity !== null) {
			html += '<p><strong>Current stock:</strong> ' + h.escapeHtml(product.current_stock_quantity) + '</p>';
		}

		if (preview.blocked) {
			html += '<p class="yoro-result-subtle"><strong>Blocked:</strong> ' + h.escapeHtml(preview.block_reason || 'stock_management_disabled') + '</p>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewHold(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const ids = Array.isArray(params.order_ids) ? params.order_ids : [];

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Put orders on hold</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewNote(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const ids = Array.isArray(params.order_ids) ? params.order_ids : [];

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Add internal note</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewAddNote(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const ids = Array.isArray(params.order_ids)
			? params.order_ids
			: (params.order_id ? [params.order_id] : []);

		const note = params.note || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Add internal note</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		}

		if (note) {
			html += '<p><strong>Note:</strong> ' + h.escapeHtml(note) + '</p>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewEmailPreview(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const orderId = params.order_id || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Prepare email preview</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';

		if (orderId) {
			html += renderOrderLabel([orderId], h);
		}

		html += '<p>I’ll prepare the email preview from the steps above.</p>';

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewSaveEmailDraft(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const orderId = params.order_id || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Save customer email draft</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';

		if (orderId) {
			html += renderOrderLabel([orderId], h);
		}

		html += '<p>I’ll save the generated customer email as a draft.</p>';

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowSaveEmailDraftStep(result, item, h) {
		result = result || {};

		let content = '';

		content += '<p>' + h.escapeHtml(result.message || result.summary || 'Customer email draft saved.') + '</p>';

		if (result.draft && result.draft.subject) {
			content += '<p><strong>Subject:</strong> ' + h.escapeHtml(result.draft.subject) + '</p>';
		}

		return renderWorkflowStepCard('Save customer email draft', content, 'success', h);
	}

	function renderWorkflowPreviewComposeCustomerEmail(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const orderId = params.order_id || '';
		const instruction = params.instruction || '';

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Draft customer email</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';

		if (orderId) {
			html += renderOrderLabel([orderId], h);
		}

		if (instruction) {
			html += '<p><strong>Email goal:</strong> ' + h.escapeHtml(instruction) + '</p>';
		} else {
			html += '<p>I’ll draft the customer email for this request.</p>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewGetDetails(step, index, data, h) {
		const params = step && step.params ? step.params : {};
		const ids = Array.isArray(params.order_ids) ? params.order_ids : [];

		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Load order details</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		} else {
			html += '<p>I’ll load the selected order details.</p>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowPreviewRefund(step, index, data, h) {
		let html = '';

		html += '<div class="yoro-workflow-step yoro-workflow-preview-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-number">' + (index + 1) + '</span>';
		html += '<strong>Review refundable amount</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';
		html += '<p>YoRo will analyze the refundable remaining amount for this order.</p>';
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowStepCard(title, content, status, h) {
		status = status || 'success';

		let html = '';

		html += '<div class="yoro-workflow-step">';
		html += '<div class="yoro-workflow-step-header">';
		html += '<span class="yoro-workflow-step-status yoro-workflow-step-status-' + h.escapeHtml(status) + '"></span>';
		html += '<strong>' + h.escapeHtml(title) + '</strong>';
		html += '</div>';
		html += '<div class="yoro-workflow-step-body">';
		html += content;
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowRawJsonDetails(data, h) {
		if (!h.shouldShowDebugJson || !h.shouldShowDebugJson()) {
			return '';
		}

		return '<details class="yoro-result-debug"><summary>Raw JSON</summary><pre class="yoro-json-output">' +
			h.escapeHtml(JSON.stringify(data || {}, null, 2)) +
			'</pre></details>';
	}

	function renderWorkflowHoldStep(stepResult, item, h) {
		const ids = stepResult.order_ids || stepResult.processed || [];

		if (!ids.length) {
			return '';
		}

			let content = '<ul>';

			ids.forEach(function(id) {
				content += '<li>#' + h.escapeHtml(id) + ' → moved to ' + h.escapeHtml(orderStatusLabel('on-hold')) + '</li>';
			});

		content += '</ul>';

		return renderWorkflowStepCard('Orders updated', content, 'success', h);
	}

	function renderWorkflowAddNoteStep(stepResult, item, h) {
		const ids = stepResult.order_ids || stepResult.processed || [];
		const note = stepResult.note || '';

		if (!ids.length) {
			return '';
		}

		let content = '<ul>';

		ids.forEach(function(id) {
			content += '<li>#' + h.escapeHtml(id) + ' → note: ' + h.escapeHtml(note) + '</li>';
		});

		content += '</ul>';

		return renderWorkflowStepCard('Notes added', content, 'success', h);
	}

	function renderWorkflowUpdateStatusWithNoteStep(stepResult, item, h) {
		const ids = stepResult.order_ids || stepResult.processed || [];
		const statusLabel = orderStatusLabel(stepResult.status_label || stepResult.status || '');
		const note = stepResult.note || '';

		if (!ids.length) {
			return '';
		}

		let content = '<ul>';

		ids.forEach(function(id) {
			content += '<li>#' + h.escapeHtml(id);

			if (statusLabel) {
				content += ' → ' + h.escapeHtml(statusLabel);
			}

			if (note) {
				content += '<br><span class="yoro-result-subtle">' + h.escapeHtml(note) + '</span>';
			}

			content += '</li>';
		});

		content += '</ul>';

		return renderWorkflowStepCard('Status and note updated', content, 'success', h);
	}

	function renderWorkflowPrivateNoteTemplateStep(stepResult, item, h) {
		const ids = stepResult.order_ids || stepResult.processed || [];
		const note = stepResult.note || '';
		const template = stepResult.template || '';

		if (!ids.length) {
			return '';
		}

		let content = '<ul>';

		ids.forEach(function(id) {
			content += '<li>#' + h.escapeHtml(id);

			if (template) {
				content += ' — template: ' + h.escapeHtml(template);
			}

			if (note) {
				content += '<br><span class="yoro-result-subtle">' + h.escapeHtml(note) + '</span>';
			}

			content += '</li>';
		});

		content += '</ul>';

		return renderWorkflowStepCard(
			'Verification note added',
			content,
			'success',
			h
		);
	}

	function renderWorkflowComposeCustomerEmailStep(stepResult, item, h) {
		if (
			stepResult.display_type === 'customer_email_preview' &&
			typeof h.renderCustomerEmailPreviewResult === 'function'
		) {
			return '<div class="yoro-workflow-email-preview">' + h.renderCustomerEmailPreviewResult({
				success: true,
				stage: 'done',
				parsed: {
					action: stepResult.action || 'orders.compose_customer_email'
				},
				result: stepResult
			}) + '</div>';
		}

		const email = stepResult.email || {};
		const order = stepResult.order || {};
		const workflow = stepResult.workflow || {};
		const sendPayload = stepResult.send_payload || {};

		let content = '';

		content += '<p><strong>Order:</strong> #' + h.escapeHtml(order.number || order.id || workflow.order_id || sendPayload.order_id || '') + '</p>';

		if (email.to) {
			content += '<p><strong>To:</strong> ' + h.escapeHtml(email.to) + '</p>';
		}

		if (email.subject) {
			content += '<p><strong>Subject:</strong> ' + h.escapeHtml(email.subject) + '</p>';
		}

		if (email.body) {
			content += '<div class="yoro-order-detail-section">';
			content += '<h4>Email body</h4>';
			content += '<pre class="yoro-email-draft-body">' + h.escapeHtml(email.body) + '</pre>';
			content += '</div>';
		}

		if (sendPayload.order_id && sendPayload.subject && sendPayload.body) {
			content += '<div class="yoro-email-action-bar">';
			content += '<div class="yoro-email-send-actions">';
			content += '<button type="button" class="button button-primary yoro-send-composed-email"';
			content += ' data-order-id="' + h.escapeHtml(sendPayload.order_id) + '">';
			content += 'Send this email';
			content += '</button>';
			content += '</div>';
			content += '</div>';
		}

		return renderWorkflowStepCard(
			'Customer email drafted',
			content,
			'success',
			h
		);
	}

	function renderWorkflowGetDetailsStep(stepResult, item, h) {
		const orders = Array.isArray(stepResult.orders) ? stepResult.orders : [];

		if (!orders.length) {
			return '';
		}

		let content = '<ul>';

			orders.forEach(function(order) {
				content += '<li>';
				content += '#' + h.escapeHtml(order.number || order.id);
				content += ' — ';
				content += h.escapeHtml(order.status_label || orderStatusLabel(order.status || ''));
				content += ' — ';
				content += h.escapeHtml(order.total || '');
			content += ' ' + h.escapeHtml(order.currency || '');
			content += '</li>';
		});

		content += '</ul>';

		return renderWorkflowStepCard('Order details loaded', content, 'success', h);
	}

	function renderWorkflowRefundPreviewStep(stepResult, item, h) {
		const order = stepResult.order || {};
		const remaining = order.remaining || stepResult.remaining || '0';

		let content = '';

		content += '<p><strong>Order:</strong> #' + h.escapeHtml(order.number || order.id || '') + '</p>';
		content += '<p><strong>Status:</strong> ' + h.escapeHtml(order.status_label || orderStatusLabel(order.status || '')) + '</p>';
		content += '<p><strong>Payment:</strong> ' + h.escapeHtml(order.payment_title || order.payment_method || '—') + '</p>';
		content += '<p><strong>Refundable remaining:</strong> ';
		content += h.escapeHtml(remaining) + ' ';
		content += h.escapeHtml(order.currency || '');
		content += '</p>';

		return renderWorkflowStepCard('Refund review', content, 'success', h);
	}

	function renderMultiActionResult(data, helpers) {
		const h = getHelpers(helpers);
		const result = data.result || {};
		const actions = Array.isArray(result.actions) ? result.actions : [];
		const workflow = result.workflow || {};
		const isMultiOrderEmailWorkflow = [
			'write_email_for_orders',
			'write_and_save_email_drafts_for_orders',
			'write_and_send_emails_for_orders'
		].indexOf(workflow.definition_id || workflow.type || '') !== -1;

		const hasPendingEmail = actions.some(function(item) {
			const stepResult = item && item.result ? item.result : {};

			return stepResult.display_type === 'customer_email_preview' &&
				stepResult.send_payload;
		});
		const emailPreviewResult = hasPendingEmail
			? findBestWorkflowEmailPreview(actions)
			: null;
		const branchMeta = getWorkflowBranchMeta(workflow);
		const displayWorkflow = Object.assign({}, workflow, {
			status: hasPendingEmail ? 'email_preview_ready' : 'completed',
			step: hasPendingEmail ? 'email_preview_ready' : 'completed'
		});

		let html = '<div class="yoro-workflow-result">';
		html += '<div class="yoro-result-notice yoro-result-success">';
		html += '<strong>' + h.escapeHtml(hasPendingEmail ? branchMeta.successTitle : (branchMeta.successTitle || 'Completed')) + '</strong>';

		if (hasPendingEmail) {
			html += '<p>' + h.escapeHtml(branchMeta.successMessage || 'I prepared the draft below. Review it before sending.') + '</p>';
		} else {
			html += '<p>' + h.escapeHtml(branchMeta.successMessage || 'I finished those steps.') + '</p>';
		}

		html += '</div>';

		html += '<div class="yoro-workflow-result-body">';
		html += renderWorkflowCompositionNotice(workflow, h);
		html += renderWorkflowStatePanel(displayWorkflow, h, actions);
		if (isMultiOrderEmailWorkflow) {
			actions.forEach(function(item) {
				const action = item.action || '';
				const stepResult = item.result || {};

				if (!shouldRenderWorkflowResultStep(item, hasPendingEmail)) {
					return;
				}

				const renderer = workflowRenderers[action] && workflowRenderers[action].result;

				if (typeof renderer === 'function') {
					html += renderer(stepResult, item, h);
					return;
				}

				if (typeof h.renderStepResult === 'function') {
					const renderedStep = h.renderStepResult(action, stepResult, item);

					if (renderedStep) {
						html += '<div class="yoro-workflow-embedded-result">' + renderedStep + '</div>';
						return;
					}
				}

				html += renderWorkflowStepCard(
					humanWorkflowActionLabel(action),
					renderWorkflowRawJsonDetails(stepResult, h),
					'neutral',
					h
				);
			});
		} else if (emailPreviewResult && typeof h.renderCustomerEmailPreviewResult === 'function') {
			html += '<div class="yoro-workflow-email-preview">';
			html += h.renderCustomerEmailPreviewResult({
				success: true,
				stage: 'done',
				parsed: {
					action: emailPreviewResult.action || 'orders.render_customer_email_preview'
				},
				result: emailPreviewResult
			});
			html += '</div>';
		} else {
			actions.forEach(function(item) {
				const action = item.action || '';
				const stepResult = item.result || {};

				if (!shouldRenderWorkflowResultStep(item, hasPendingEmail)) {
					return;
				}

				const renderer = workflowRenderers[action] && workflowRenderers[action].result;

				if (typeof renderer === 'function') {
					html += renderer(stepResult, item, h);
					return;
				}

				if (typeof h.renderStepResult === 'function') {
					const renderedStep = h.renderStepResult(action, stepResult, item);

					if (renderedStep) {
						html += '<div class="yoro-workflow-embedded-result">' + renderedStep + '</div>';
						return;
					}
				}

				html += renderWorkflowStepCard(
					humanWorkflowActionLabel(action),
					renderWorkflowRawJsonDetails(stepResult, h),
					'neutral',
					h
				);
			});
		}

		html += '</div>';

		if (Array.isArray(result.rollback) && result.rollback.length) {
			html += renderWorkflowRollbackTrace(result.rollback, h);
		}

		const undoPlan = Array.isArray(result.undo_plan) ? result.undo_plan : [];
		const logId = data.log_id || result.log_id || '';
		const undoable = result.undoable === true || undoPlan.length > 0;

		if (logId && result.read_only !== true && undoable) {
			html += '<div class="yoro-undo-action">';
			html += '<button type="button" class="button yoro-undo-last" data-log-id="' + h.escapeHtml(logId) + '">Undo</button>';
			html += '</div>';
		}

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderWorkflowStepTrace(item, h) {
		item = item || {};

		const result = item.result || {};
		const action = item.action || result.action || '';
		const label = item.label || humanWorkflowActionLabel(action);
		const attempts = parseInt(item.attempts || 1, 10);
		const onError = item.on_error || 'stop';

		let status = 'completed';
		let statusLabel = 'Completed';

		if (item.skipped || result.skipped) {
			status = 'skipped';
			statusLabel = 'Skipped';
		} else if (item.failed || result.success === false) {
			status = 'failed';
			statusLabel = 'Needs attention';
		} else if (attempts > 1) {
			status = 'retried';
			statusLabel = 'Completed after retry';
		}

		let html = '';

		html += '<div class="yoro-workflow-step-trace yoro-workflow-step-trace-' + h.escapeHtml(status) + '">';
		html += '<div class="yoro-workflow-step-trace-main">';
		html += '<strong>' + h.escapeHtml(label) + '</strong>';
		html += '<span class="yoro-workflow-step-status">' + h.escapeHtml(statusLabel) + '</span>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-trace-meta">';
		if (h.shouldShowDebugJson && h.shouldShowDebugJson()) {
			html += '<span>Action: ' + h.escapeHtml(action) + '</span>';
		}

		if (attempts > 1) {
			html += '<span>Attempts: ' + h.escapeHtml(attempts) + '</span>';
		}

		if (onError && onError !== 'stop') {
			html += '<span>On error: ' + h.escapeHtml(onError) + '</span>';
		}

		if (item.reason) {
			html += '<span>' + h.escapeHtml(item.reason) + '</span>';
		} else if (result.message && (status === 'failed' || status === 'skipped')) {
			html += '<span>' + h.escapeHtml(result.message) + '</span>';
		}

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderWorkflowRollbackTrace(rollback, h) {
		if (!Array.isArray(rollback) || !rollback.length) {
			return '';
		}

		let html = '';

		html += '<div class="yoro-workflow-rollback-trace">';
		html += '<strong>Rollback executed</strong>';

		rollback.forEach(function(item) {
			item = item || {};

			const action = item.action || '';
			const result = item.result || item;
			const success = result.success !== false;

			html += '<div class="yoro-workflow-rollback-item ' + (success ? 'is-success' : 'is-error') + '">';
			html += '<span class="yoro-workflow-rollback-status">' + (success ? '✓' : '⚠') + '</span>';
			html += '<div>';
			html += '<strong>' + h.escapeHtml(humanWorkflowActionLabel(action) || 'Rollback step') + '</strong>';

			if (result.message || result.summary) {
				html += '<p>' + h.escapeHtml(result.message || result.summary) + '</p>';
			}

			html += '</div>';
			html += '</div>';
		});

		html += '</div>';

		return html;
	}

	function workflowActionsRequireConfirmation(actions) {
		if (!Array.isArray(actions)) {
			return false;
		}

		return actions.some(function(step) {
			step = step || {};

			const action = step.action || '';

				return [
					'orders.update_status',
				'orders.add_note',
				'orders.add_private_note_template',
				'orders.compose_customer_email',
				'orders.send_customer_email',
				'orders.save_customer_email_draft',
				'orders.delete_customer_email_draft',
				'email_templates.delete',
				'products.update_stock',
				'products.update_stock_status'
			].indexOf(action) !== -1;
		});
	}

	const workflowRenderers = {
		'orders.get_details': {
			preview: renderWorkflowPreviewGetDetails,
			result: renderWorkflowGetDetailsStep
		},
		'orders.refund_preview': {
			preview: renderWorkflowPreviewRefund,
			result: renderWorkflowRefundPreviewStep
		},
			'orders.add_note': {
			result: renderWorkflowAddNoteStep
		},
		'orders.update_status': {
			result: renderWorkflowUpdateStatusWithNoteStep
		},
		'orders.add_private_note_template': {
			preview: renderWorkflowPreviewNote,
			result: renderWorkflowPrivateNoteTemplateStep
		},
		'orders.compose_customer_email': {
			preview: renderWorkflowPreviewComposeCustomerEmail,
			result: renderWorkflowComposeCustomerEmailStep
		},
		'orders.add_note': {
			preview: renderWorkflowPreviewAddNote,
			result: renderWorkflowAddNoteStep
		},
		'orders.render_customer_email_preview': {
			preview: renderWorkflowPreviewEmailPreview,
			result: renderWorkflowComposeCustomerEmailStep
		},
		'orders.save_customer_email_draft': {
			preview: renderWorkflowPreviewSaveEmailDraft,
			result: renderWorkflowSaveEmailDraftStep
		},
		'products.update_stock': {
			preview: renderWorkflowPreviewProductStock
		},
		'products.update_stock_status': {
			preview: renderWorkflowPreviewProductStockStatus
		}
	};

	window.YoRoWorkflowRenderers = {
		renderWorkflowPreview: renderWorkflowPreview,
		renderMultiActionResult: renderMultiActionResult
	};
})();
