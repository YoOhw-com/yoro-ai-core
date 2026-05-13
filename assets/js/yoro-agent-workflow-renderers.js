(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml || function(value) {
				return String(value === null || value === undefined ? '' : value);
			},
			renderDebugJson: helpers.renderDebugJson || function() {
				return '';
			},
			renderJson: helpers.renderJson || function(data) {
				return '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
			},
			renderCustomerEmailPreviewResult: helpers.renderCustomerEmailPreviewResult || null
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

	function renderWorkflowStateCard(state, h) {
		state = state || {};
		const label = state.label || '';
		const message = state.message || '';
		const next = Array.isArray(state.next_actions) ? state.next_actions : [];

		if (!label && !message && !next.length) {
			return '';
		}

		let html = '<div class="yoro-workflow-state-card">';
		if (label) {
			html += '<strong>' + h.escapeHtml(label) + '</strong>';
		}
		if (message) {
			html += '<p>' + h.escapeHtml(message) + '</p>';
		}
		if (next.length) {
			html += '<div class="yoro-suggestion-list">';
			next.slice(0, 3).forEach(function (item) {
				if (!item || !item.action) {
					return;
				}
				html += '<button class="button button-small yoro-suggestion-btn"';
				html += ' data-action="' + h.escapeHtml(item.action) + '"';
				html += ' data-params="' + h.escapeHtml(JSON.stringify(item.params || {})) + '"';
				html += ' data-confirm="' + (item.confirm ? '1' : '0') + '"';
				html += ' data-preview="' + (item.preview ? '1' : '0') + '"';
				html += '>';
				html += h.escapeHtml(item.label || item.action);
				html += '</button>';
			});
			html += '</div>';
		}
		html += '</div>';
		return html;
	}

	function shouldRenderWorkflowResultStep(item, hasPendingEmail) {
		item = item || {};

		const result = item.result || {};
		const action = item.action || result.action || '';
		const skipped = !!item.skipped || result.skipped === true;
		const failed = !!item.failed || result.success === false;

		if (skipped) {
			return false;
		}

		if (failed) {
			return true;
		}

		if (hasPendingEmail) {
			return [
				'orders.compose_customer_email',
				'orders.render_customer_email_preview'
			].indexOf(action) !== -1;
		}

		return [
			'orders.hold',
			'orders.add_note',
			'orders.add_private_note_template',
			'orders.update_status',
			'orders.bulk_update_status_with_note',
			'orders.save_customer_email_draft',
			'orders.send_customer_email',
			'orders.refund_preview',
			'orders.compose_customer_email',
			'orders.render_customer_email_preview'
		].indexOf(action) !== -1;
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
				label: 'Confirm store change',
				message: 'I prepared ' + actionCount + ' step' + (actionCount === 1 ? '' : 's') + ' and I will wait before changing store data.',
				next_actions: [
					{ label: 'Confirm', command: 'Confirm' },
					{ label: 'Cancel', command: 'Cancel workflow' }
				]
			};
		}

		return {
			label: parsed && parsed.workflow_label ? parsed.workflow_label : 'Preview ready',
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
				message: 'I finished the flow and left you at the email review step.',
				next_actions: [
					{ label: 'Open customer service queue', command: 'Show customer service queue' }
				]
			};
		}

		return {
			label: 'Done',
			message: 'I finished that flow.',
			next_actions: [
				{ label: 'Continue', command: 'Continue previous workflow' }
			]
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

		html += '<div class="yoro-result-notice yoro-result-preview">';

		if (requiresConfirmation) {
			html += '<strong>Confirm store change</strong>';
			html += '<p>This flow will change store data after you confirm it.</p>';
		} else {
			html += '<strong>Preview ready</strong>';
			html += '<p>I prepared this flow below.</p>';
		}

		html += '</div>';

		html += '<div class="yoro-workflow-preview-body">';
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
				<button class="button button-primary yoro-confirm-run">Confirm</button>
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
		html += '<strong>' + h.escapeHtml(action) + '</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';
		const ids = Array.isArray(params.order_ids) && params.order_ids.length
			? params.order_ids
			: (params.order_id ? [params.order_id] : []);

		if (ids.length) {
			html += renderOrderLabel(ids, h);
		}

		html += '<p>I will run this step if you continue.</p>';
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
		html += '<strong>Render customer email preview</strong>';
		html += '</div>';

		html += '<div class="yoro-workflow-step-body">';

		if (orderId) {
			html += renderOrderLabel([orderId], h);
		}

		html += '<p>I will render the email preview from the steps above.</p>';

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

		html += '<p>I will save the generated customer email as a draft.</p>';

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
			html += '<p><strong>Instruction:</strong> ' + h.escapeHtml(instruction) + '</p>';
		} else {
			html += '<p>I will draft the customer email for this flow.</p>';
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
			if (ids.length) {
				html += renderOrderLabel(ids, h);
			}

			html += '</ul>';
		} else {
			html += '<p>YoRo will load selected order details.</p>';
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

	function renderWorkflowHoldStep(stepResult, item, h) {
		const ids = stepResult.order_ids || stepResult.processed || [];

		if (!ids.length) {
			return '';
		}

		let content = '<ul>';

		ids.forEach(function(id) {
			content += '<li>#' + h.escapeHtml(id) + ' → moved to on-hold</li>';
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
			let html = '';

			html += renderWorkflowStepCard(
				'Customer email drafted',
				'<p>Please review the email below before sending it.</p>',
				'success',
				h
			);

			html += '<div class="yoro-workflow-email-preview">';
			html += h.renderCustomerEmailPreviewResult({
				success: true,
				stage: 'done',
				parsed: {
					action: stepResult.action || 'orders.compose_customer_email'
				},
				result: stepResult
			});
			html += '</div>';

			return html;
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
			content += h.escapeHtml(order.status || '');
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
		content += '<p><strong>Status:</strong> ' + h.escapeHtml(order.status || '') + '</p>';
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

		const hasPendingEmail = actions.some(function(item) {
			const stepResult = item && item.result ? item.result : {};

			return stepResult.display_type === 'customer_email_preview' &&
				stepResult.send_payload;
		});
		const emailPreviewResult = hasPendingEmail
			? findBestWorkflowEmailPreview(actions)
			: null;

		let html = '<div class="yoro-workflow-result">';
		html += '<div class="yoro-result-notice yoro-result-success">';
		html += '<strong>' + (hasPendingEmail ? 'Email draft ready for review' : 'Completed') + '</strong>';

		if (hasPendingEmail) {
			html += '<p>I prepared the draft below. Review it before sending.</p>';
		} else {
			html += '<p>I finished that workflow.</p>';
		}

		html += '</div>';

		html += '<div class="yoro-workflow-result-body">';
		if (emailPreviewResult && typeof h.renderCustomerEmailPreviewResult === 'function') {
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

				html += renderWorkflowStepCard(
					action,
					'<pre class="yoro-json-output">' + h.escapeHtml(JSON.stringify(stepResult, null, 2)) + '</pre>',
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
		const label = item.label || action;
		const attempts = parseInt(item.attempts || 1, 10);
		const onError = item.on_error || 'stop';

		let status = 'completed';
		let statusLabel = 'Completed';

		if (item.skipped || result.skipped) {
			status = 'skipped';
			statusLabel = 'Skipped';
		} else if (item.failed || result.success === false) {
			status = 'failed';
			statusLabel = 'Failed';
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
		html += '<span>Action: ' + h.escapeHtml(action) + '</span>';

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
			html += '<strong>' + h.escapeHtml(action || 'Rollback step') + '</strong>';

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
				'orders.hold',
				'orders.update_status',
				'orders.bulk_update_status_with_note',
				'orders.add_note',
				'orders.add_private_note_template',
				'orders.compose_customer_email',
				'orders.send_customer_email',
				'orders.save_customer_email_draft',
				'orders.delete_customer_email_draft',
				'email_templates.delete'
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
		'orders.hold': {
			preview: renderWorkflowPreviewHold,
			result: renderWorkflowHoldStep
		},
		'orders.add_note': {
			result: renderWorkflowAddNoteStep
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
		}
	};

	window.YoRoWorkflowRenderers = {
		renderWorkflowPreview: renderWorkflowPreview,
		renderMultiActionResult: renderMultiActionResult
	};
})();
