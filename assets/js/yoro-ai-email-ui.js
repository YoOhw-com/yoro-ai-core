(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
				formatDateTime: helpers.formatDateTime,
				runCommandFromText: helpers.runCommandFromText,
				renderDebugJson: helpers.renderDebugJson,
				resolveOrderId: helpers.resolveOrderId,
			setCurrentOrderId: helpers.setCurrentOrderId,
			executeActionDirect: helpers.executeActionDirect,
			sendMailboxPreviewReply: helpers.sendMailboxPreviewReply,
			addAssistantMessage: helpers.addAssistantMessage,
			addLoadingMessage: helpers.addLoadingMessage,
			updateConversationState: helpers.updateConversationState,
			openModal: helpers.openModal
		};
	}

	function init(helpers) {
		const h = getHelpers(helpers);

		return {
			renderCustomerEmailPreviewResult: function(data) {
				return renderCustomerEmailPreviewResult(data, h);
			},
			renderEmailTemplatesResult: function(data) {
				return renderEmailTemplatesResult(data, h);
			},
			renderCustomerEmailDraftsResult: function(data) {
				return renderCustomerEmailDraftsResult(data, h);
			},
			handleClick: function(event) {
				return handleEmailClick(event, h);
			}
		};
	}

	function formatDateTime(value, timestamp, h) {
		if (typeof h.formatDateTime === 'function') {
			return h.formatDateTime(timestamp, value);
		}

		return String(value || '');
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

		return raw.replace(/^wc-/, '').replace(/[_-]/g, ' ').replace(/\b\w/g, function(char) {
			return char.toUpperCase();
		});
	}

	function orderDisplayStatus(order) {
		order = order || {};

		return order.status_label || orderStatusLabel(order.status || '');
	}

	function formatEditableEmailBody(body, h) {
		const escaped = h.escapeHtml(body || '');
		return escaped.replace(/\n/g, '<br>');
	}

	function bodyTextToParagraphHtml(body, h) {
		const parts = String(body || '').split(/\n{2,}/);

		return parts.map(function (part) {
			const escaped = h.escapeHtml(part || '').replace(/\n/g, '<br>');
			return '<p>' + escaped + '</p>';
		}).join('');
	}

	function normalizeEmailTextForMatch(text) {
		return String(text || '')
			.replace(/\u00a0/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function readEmailEditableValue(node) {
		if (!node) {
			return '';
		}

		if (typeof node.value === 'string') {
			return node.value.trim();
		}

		return String(node.innerText || node.textContent || '').trim();
	}

	function missingTemplateMessage() {
		return 'I could not identify that saved template. Please choose the template again.';
	}

	function missingOrderMessage() {
		return 'Please open an order or mention the order number first.';
	}

	function missingEmailContentMessage() {
		return 'Please make sure the email has both a subject and body.';
	}

	function missingDraftMessage() {
		return 'I could not identify that saved draft. Please reopen the draft from the order.';
	}

	function formatEmailTemplateLabel(template) {
		const raw = String(template || '').trim();
		const key = raw.toLowerCase().replace(/\s+/g, '_').replace(/-/g, '_');

		if (!raw || key === 'custom') {
			return 'Custom email';
		}

		if (key === 'ai_custom' || key === 'yoro_ai' || key === 'yoro_ai_custom') {
			return 'YoRo AI draft';
		}

		return raw
			.replace(/[_-]/g, ' ')
			.replace(/\b\w/g, function(char) {
				return char.toUpperCase();
			});
	}

	function buildEditableWooEmailPreview(email, h) {
		const htmlBody = String(email && email.html_body ? email.html_body : '');
		const subject = String(email && email.subject ? email.subject : '');
		const body = String(email && email.body ? email.body : '');

		if (!htmlBody) {
			return '<div class="yoro-email-preview">' +
				'<div class="yoro-email-live-subject yoro-email-subject-input" contenteditable="true" spellcheck="false" data-placeholder="Email subject">' + h.escapeHtml(subject) + '</div>' +
				'<div class="yoro-email-live-body yoro-email-body-input" contenteditable="true" spellcheck="true" data-placeholder="Email body">' + formatEditableEmailBody(body, h) + '</div>' +
				'</div>';
		}

		const parser = new window.DOMParser();
		const doc = parser.parseFromString(htmlBody, 'text/html');
		const headingNode = doc.body.querySelector('h1, h2, h3');

		if (!headingNode) {
			return '<div class="yoro-email-preview">' +
				htmlBody +
				'</div>';
		}

		const subjectDiv = doc.createElement('div');
		subjectDiv.className = 'yoro-email-live-subject yoro-email-subject-input';
		subjectDiv.setAttribute('contenteditable', 'true');
		subjectDiv.setAttribute('spellcheck', 'false');
		subjectDiv.setAttribute('data-placeholder', 'Email subject');
		subjectDiv.textContent = subject;

		const bodyDiv = doc.createElement('div');
		bodyDiv.className = 'yoro-email-live-body yoro-email-body-input';
		bodyDiv.setAttribute('contenteditable', 'true');
		bodyDiv.setAttribute('spellcheck', 'true');
		bodyDiv.setAttribute('data-placeholder', 'Email body');
		bodyDiv.innerHTML = bodyTextToParagraphHtml(body, h);

		headingNode.replaceWith(subjectDiv);

		const bodyParagraphs = String(body || '')
			.split(/\n{2,}/)
			.map(function (part) {
				return normalizeEmailTextForMatch(part);
			})
			.filter(Boolean);

		let inserted = false;
		const allParagraphs = Array.from(doc.body.querySelectorAll('p'));

		if (bodyParagraphs.length && allParagraphs.length) {
			const firstParagraph = bodyParagraphs[0];
			let startIndex = -1;

			for (let i = 0; i < allParagraphs.length; i += 1) {
				const paragraphText = normalizeEmailTextForMatch(allParagraphs[i].textContent || '');
				if (paragraphText === firstParagraph) {
					startIndex = i;
					break;
				}
			}

			if (startIndex >= 0) {
				const firstNode = allParagraphs[startIndex];
				const parent = firstNode.parentNode;
				const removable = [];

				for (let i = startIndex; i < allParagraphs.length; i += 1) {
					const node = allParagraphs[i];
					if (!node || node.parentNode !== parent) {
						break;
					}

					const paragraphText = normalizeEmailTextForMatch(node.textContent || '');
					if (!paragraphText) {
						removable.push(node);
						continue;
					}

					if (bodyParagraphs.indexOf(paragraphText) !== -1) {
						removable.push(node);
						continue;
					}

					break;
				}

				firstNode.replaceWith(bodyDiv);
				removable.slice(1).forEach(function (node) {
					if (node.parentNode) {
						node.parentNode.removeChild(node);
					}
				});
				inserted = true;
			}
		}

		if (!inserted) {
			const subjectParent = subjectDiv.parentNode;
			if (subjectParent) {
				subjectParent.insertBefore(bodyDiv, subjectDiv.nextSibling);
			}
		}

		return '<div class="yoro-email-preview yoro-email-preview-editable">' + doc.body.innerHTML + '</div>';
	}

	function normalizeMailboxReplyPreview(data) {
		const result = data && data.result ? data.result : {};
		const parsed = data && data.parsed ? data.parsed : {};
		const actionPlan = data && data.action_plan
			? data.action_plan
			: (parsed.action_plan || result.action_plan || {});
		const preview = result.email_preview || data.email_preview || parsed.email_preview || actionPlan.email_preview || {};
		const resultSendPayload = result.send_payload && Object.keys(result.send_payload).length ? result.send_payload : null;
		const previewSendPayload = preview.send_payload && Object.keys(preview.send_payload).length ? preview.send_payload : null;
		const sendPayload = resultSendPayload || previewSendPayload || {};
		const action = result.action || (data && data.action) || parsed.action || preview.action || sendPayload.action || '';
		const sendAction = preview.send_action || sendPayload.action || '';
		const hasPreview = !!preview && Object.keys(preview).length > 0;

		if (!hasPreview || (preview.kind !== 'mailbox_reply' && result.kind !== 'mailbox_reply' && action !== 'email.reply.send' && sendAction !== 'email.reply.send')) {
			return null;
		}

		return {
			threadId: String(sendPayload.thread_id || preview.thread_id || result.thread_id || ''),
			providerThreadId: String(sendPayload.provider_thread_id || preview.provider_thread_id || result.provider_thread_id || ''),
			pendingOperationToken: String(sendPayload.pending_operation_token || (data && data.pending_operation_token) || (data && data.meta && data.meta.pending_operation_token) || ''),
			to: normalizeRecipientList(sendPayload.to || preview.to || []),
			cc: normalizeRecipientList(sendPayload.cc || preview.cc || []),
			from: normalizeRecipient(preview.from || {}),
			subject: String(sendPayload.subject || preview.subject || (result.email && result.email.subject) || ''),
			body: String(sendPayload.body || preview.body || preview.body_text || (result.email && result.email.body) || ''),
			htmlBody: String(sendPayload.html_body || preview.html_body || (preview.email && preview.email.html_body) || (result.email && result.email.html_body) || ''),
			canSend: sendPayload.can_send !== false && preview.can_send !== false,
			canEdit: preview.can_edit !== false
		};
	}

	function normalizeRecipientList(value) {
		if (typeof value === 'string') {
			value = value.split(',').map(function(part) {
				return part.trim();
			}).filter(Boolean);
		}

		if (!Array.isArray(value)) {
			return [];
		}

		return value.map(normalizeRecipient).filter(function(recipient) {
			return !!recipient.email;
		});
	}

	function normalizeRecipient(value) {
		if (!value) {
			return {};
		}

		if (typeof value === 'string') {
			const match = value.match(/<([^>]+)>/);

			if (match) {
				return {
					name: value.replace(/<[^>]+>/, '').trim(),
					email: match[1].trim()
				};
			}

			return {
				email: value.trim()
			};
		}

		return {
			name: String(value.name || '').trim(),
			email: String(value.email || value.address || '').trim()
		};
	}

	function recipientLabel(recipient, h) {
		recipient = normalizeRecipient(recipient);

		if (!recipient.email) {
			return '';
		}

		return recipient.name
			? recipient.name + ' <' + recipient.email + '>'
			: recipient.email;
	}

	function recipientListLabel(recipients, h) {
		const labels = normalizeRecipientList(recipients).map(function(recipient) {
			return recipientLabel(recipient, h);
		}).filter(Boolean);

		return labels.join(', ');
	}

	function jsonDataAttr(value, h) {
		return h.escapeHtml(JSON.stringify(value || []));
	}

	function readJsonDataAttr(target, name) {
		const raw = target.getAttribute(name) || '';

		if (!raw) {
			return [];
		}

		try {
			const parsed = JSON.parse(raw);

			return Array.isArray(parsed) ? parsed : [];
		} catch (error) {
			return [];
		}
	}

	function renderMailboxReplyPreviewResult(data, h, preview) {
		const toLabel = recipientListLabel(preview.to, h) || '—';
		const ccLabel = recipientListLabel(preview.cc, h);
		const fromLabel = recipientLabel(preview.from, h) || 'Connected shop mailbox';
		const threadLabel = preview.threadId ? ('#' + preview.threadId) : '';

		let html = '<div class="yoro-result-summary">';
		html += '<strong>Email reply preview</strong>';
		html += '<span class="yoro-result-subtle">Editable preview</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-card yoro-mailbox-reply-preview-card"';
		html += ' data-mailbox-thread-id="' + h.escapeHtml(preview.threadId) + '"';
		html += ' data-provider-thread-id="' + h.escapeHtml(preview.providerThreadId) + '"';
		html += ' data-pending-operation-token="' + h.escapeHtml(preview.pendingOperationToken) + '">';

		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Reply to shop inbox thread ' + h.escapeHtml(threadLabel) + '</strong>';
		html += '<span class="yoro-current-order-badge">Shop inbox</span>';
		html += '<div class="yoro-result-subtle">Review and edit before sending from the connected mailbox.</div>';
		html += '</div>';
		html += '<span class="yoro-status-badge">Draft</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Recipient</h4>';
		html += '<p><strong>To:</strong> ' + h.escapeHtml(toLabel) + '</p>';
		if (ccLabel) {
			html += '<p><strong>Cc:</strong> ' + h.escapeHtml(ccLabel) + '</p>';
		}
		html += '<p><strong>From:</strong> ' + h.escapeHtml(fromLabel) + '</p>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Store email preview</h4>';
		html += '<div class="yoro-email-live-preview">';
		html += '<div class="yoro-email-live-preview-meta">';
		html += '<span>To: ' + h.escapeHtml(toLabel) + '</span>';
		html += '<span>Source: Shop inbox</span>';
		html += '</div>';
		html += buildEditableWooEmailPreview({
			subject: preview.subject,
			body: preview.body,
			html_body: preview.htmlBody
		}, h);
		html += '<p class="yoro-result-subtle">Edit the subject and body directly inside this email preview, then send.</p>';
		html += '</div>';
		html += '</div>';

		html += '<div class="yoro-email-action-bar">';
		html += '<div class="yoro-email-draft-actions"></div>';
		html += '<div class="yoro-email-send-actions yoro-confirm-actions">';
		html += '<button type="button" class="button yoro-confirm-cancel" data-pending-operation-token="' + h.escapeHtml(preview.pendingOperationToken) + '">Cancel</button>';

		if (preview.canSend) {
			html += '<button type="button" class="button button-primary yoro-send-mailbox-composed-reply"';
			html += ' data-thread-id="' + h.escapeHtml(preview.threadId) + '"';
			html += ' data-provider-thread-id="' + h.escapeHtml(preview.providerThreadId) + '"';
			html += ' data-pending-operation-token="' + h.escapeHtml(preview.pendingOperationToken) + '"';
			html += ' data-to="' + jsonDataAttr(preview.to, h) + '"';
			html += ' data-cc="' + jsonDataAttr(preview.cc, h) + '">';
			html += 'Send reply';
			html += '</button>';
		}

		html += '</div>';
		html += '</div>';
		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function renderCustomerEmailPreviewResult(data, h) {
		const mailboxPreview = normalizeMailboxReplyPreview(data);

		if (mailboxPreview) {
			return renderMailboxReplyPreviewResult(data, h, mailboxPreview);
		}

		const result = data.result || {};
		const email = result.email || {};
		const order = result.order || {};
		const emailFormatLabel = formatEmailTemplateLabel(email.template);

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Customer email preview') + '</strong>';
		html += '<span class="yoro-result-subtle">Editable preview</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-card">';

		html += '<div class="yoro-order-detail-header">';
			html += '<div>';
			html += '<strong class="yoro-order-detail-title">Email preview for order #' + h.escapeHtml(order.number || order.id || '') + '</strong>';
			html += '<span class="yoro-current-order-badge">Current</span>';
			html += '<div class="yoro-result-subtle">Email format: ' + h.escapeHtml(emailFormatLabel) + '</div>';
			html += '</div>';
				html += '<span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order)) + '</span>';
			html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Recipient</h4>';
		html += '<p><strong>Customer:</strong> ' + h.escapeHtml(order.billing_name || '—') + '</p>';
		html += '<p><strong>Email:</strong> ' + h.escapeHtml(email.to || order.billing_email || '—') + '</p>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
			html += '<h4>Store email preview</h4>';
			html += '<div class="yoro-email-live-preview">';
			html += '<div class="yoro-email-live-preview-meta">';
			html += '<span>To: ' + h.escapeHtml(email.to || order.billing_email || '—') + '</span>';
			html += '<span>Format: ' + h.escapeHtml(emailFormatLabel) + '</span>';
			html += '</div>';
		html += buildEditableWooEmailPreview(email, h);
		html += '<p class="yoro-result-subtle">Edit the subject and body directly inside this store email preview, then update or send.</p>';
		html += '</div>';

		html += renderSupportFlowPanel(result, h);

			html += '<div class="yoro-email-action-bar">';

		html += '<div class="yoro-email-draft-actions">';

		html += '<div class="yoro-template-dropdown">';

		html += '<button type="button" class="button yoro-template-toggle">';
		html += 'Templates ▾';
		html += '</button>';

		html += '<div class="yoro-template-menu" data-order-id="' + h.escapeHtml(order.id || '') + '">';
		html += '<button type="button" class="button-link yoro-save-email-template"';
		html += ' data-order-id="' + h.escapeHtml(order.id || '') + '">';
		html += 'Save new template';
		html += '</button>';
		html += '<div class="yoro-template-menu-divider" aria-hidden="true"></div>';
		html += '<div class="yoro-template-loading">Loading...</div>';
		html += '</div>';

		html += '</div>';

		html += '<div class="yoro-draft-dropdown">';

		html += '<button type="button" class="button yoro-draft-toggle">';
		html += 'Drafts ▾';
		html += '</button>';

		html += '<div class="yoro-draft-menu">';

		html += '<button type="button" class="button-link yoro-load-email-drafts"';
		html += ' data-order-id="' + h.escapeHtml(order.id || '') + '">';
		html += 'Load drafts';
		html += '</button>';

		if (result.send_payload && result.send_payload.draft_id) {
			html += '<button type="button" class="button-link yoro-update-email-draft"';
			html += ' data-order-id="' + h.escapeHtml(result.send_payload.order_id || order.id || '') + '"';
			html += ' data-draft-id="' + h.escapeHtml(result.send_payload.draft_id || '') + '">';
			html += 'Update current draft';
			html += '</button>';
		}

		html += '</div>';
		html += '</div>';

		html += '</div>';

		html += '<div class="yoro-email-send-actions">';

		if (order.edit_url) {
			html += '<a class="button" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open order</a>';
		}

			html += '<button type="button" class="button yoro-save-email-draft"';
		html += ' data-order-id="' + h.escapeHtml(order.id || '') + '">';
		html += 'Save new draft';
		html += '</button>';
		if (result.send_payload) {
			html += '<button type="button" class="button button-primary yoro-send-composed-email"';
			html += ' data-order-id="' + h.escapeHtml(result.send_payload.order_id || order.id || '') + '">';
			html += 'Send this email';
			html += '</button>';
		}

		html += '</div>';

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function renderEmailTemplatesResult(data, h) {
		const result = data.result || {};
		const templates = Array.isArray(result.templates) ? result.templates : [];
		const templateCount = templates.length;
		const action = result.action || '';
		const justSaved = action === 'email_templates.save';
		const justDeleted = action === 'email_templates.delete';

		let html = '<div class="yoro-result-summary">';
		html += '<strong>Saved templates</strong>';
		html += '<span class="yoro-result-subtle">' + h.escapeHtml(templateCount + ' reusable template' + (templateCount === 1 ? '' : 's')) + '</span>';
		html += '</div>';

		if (justSaved || justDeleted) {
			html += '<div class="yoro-result-notice yoro-result-success">';
			html += '<strong>' + h.escapeHtml(justDeleted ? 'Template deleted' : 'Template saved') + '</strong>';
			html += '<p>' + h.escapeHtml(justDeleted ? 'I deleted that template.' : 'I saved that template.') + '</p>';
			html += '</div>';
		}

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Reusable email templates</strong>';
		html += '<div class="yoro-result-subtle">Use a saved template when you want a faster starting point for an order email.</div>';
		html += '</div>';
		html += '</div>';

		if (!templates.length) {
			html += '<p>No saved templates yet.</p>';
			html += '<p class="yoro-result-subtle">Save one from any order email preview when you want to reuse it later.</p>';
		} else {
			html += '<div class="yoro-email-templates-list">';

			templates.forEach(function(template) {
				html += '<div class="yoro-email-template-item">';
				html += '<div class="yoro-email-draft-head">';
				html += '<strong>' + h.escapeHtml(template.name || '') + '</strong>';
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(formatDateTime(template.updated_at || template.created_at, template.updated_at_ts || template.created_at_ts, h)) + '</span>';
				html += '</div>';

				html += '<p><strong>Subject:</strong> ' + h.escapeHtml(template.subject || '') + '</p>';
				html += '<pre class="yoro-email-draft-body">' + h.escapeHtml(template.body || '') + '</pre>';

				html += '<div class="yoro-order-detail-actions">';
				html += '<button type="button" class="button button-primary yoro-use-email-template"';
				html += ' data-template-id="' + h.escapeHtml(template.id || '') + '"';
				html += ' data-template-name="' + h.escapeHtml(template.name || '') + '">';
				html += 'Use template';
				html += '</button>';
				html += '<button type="button" class="button yoro-delete-email-template"';
				html += ' data-template-id="' + h.escapeHtml(template.id || '') + '"';
				html += ' data-template-name="' + h.escapeHtml(template.name || '') + '">';
				html += 'Delete template';
				html += '</button>';
				html += '</div>';

				html += '</div>';
			});

			html += '</div>';
		}

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function renderCustomerEmailDraftsResult(data, h) {
		const result = data.result || {};
		const order = result.order || {};

		const drafts = Array.isArray(result.drafts) ? result.drafts : [];

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Customer email drafts') + '</strong>';
		html += '<span class="yoro-result-subtle">Saved drafts</span>';
		html += '</div>';

		if (result.action === 'orders.save_customer_email_draft') {
			html += '<div class="yoro-result-notice yoro-result-success">';
			html += '<strong>Draft saved</strong>';
			html += '<p>I saved the new draft.</p>';
			html += '</div>';
		}

		html += renderSupportFlowPanel(result, h);

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Email drafts for order #' + h.escapeHtml(order.number || result.order_id || '') + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(order.billing_email || '') + '</div>';
		html += '</div>';
			html += '<span class="yoro-status-badge">' + h.escapeHtml(orderDisplayStatus(order)) + '</span>';
		html += '</div>';

		if (!drafts.length) {
			html += '<p>No saved drafts found for this order.</p>';
		} else {
			html += '<div class="yoro-email-drafts-list">';

			drafts.forEach(function(draft) {
				html += '<div class="yoro-email-draft-item">';
				html += '<div class="yoro-email-draft-head">';
				html += '<strong>' + h.escapeHtml(draft.subject || '') + '</strong>';
				html += '<span class="yoro-result-subtle">' + h.escapeHtml(formatDateTime(draft.created_at, draft.created_at_ts, h)) + '</span>';
				html += '</div>';

				html += '<pre class="yoro-email-draft-body">' + h.escapeHtml(draft.body || '') + '</pre>';

				html += '<div class="yoro-order-detail-actions">';
				html += '<button type="button" class="button button-primary yoro-use-email-draft"';
				html += ' data-order-id="' + h.escapeHtml(order.id || result.order_id || '') + '"';
				html += ' data-draft-id="' + h.escapeHtml(draft.id || '') + '"';
				html += ' data-subject="' + h.escapeHtml(draft.subject || '') + '"';
				html += ' data-body="' + h.escapeHtml(draft.body || '') + '">';
				html += 'Use this draft';
				html += '</button>';
				html += '<button type="button" class="button yoro-delete-email-draft"';
				html += ' data-order-id="' + h.escapeHtml(order.id || result.order_id || '') + '"';
				html += ' data-draft-id="' + h.escapeHtml(draft.id || '') + '"';
				html += ' data-subject="' + h.escapeHtml(draft.subject || '') + '">';
				html += 'Delete draft';
				html += '</button>';
				html += '</div>';

				html += '</div>';
			});

			html += '</div>';
		}

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function renderSupportFlowPanel(result, h) {
		return '';
	}

	function handleEmailClick(event, h) {
		const rawTarget = event.target;

		const target =
				rawTarget.closest(
					'.yoro-delete-email-template,' +
				'.yoro-use-email-template,' +
				'.yoro-save-email-template,' +
				'.yoro-template-toggle,' +
				'.yoro-draft-toggle,' +
				'.yoro-save-email-draft,' +
					'.yoro-load-email-drafts,' +
				'.yoro-use-email-draft,' +
					'.yoro-update-email-draft,' +
					'.yoro-delete-email-draft,' +
					'.yoro-send-composed-email,' +
					'.yoro-send-mailbox-composed-reply'
				);

		if (!target) {
			return false;
		}

			if (target.classList.contains('yoro-delete-email-template')) {
			const templateId = target.getAttribute('data-template-id') || '';
			const templateName = target.getAttribute('data-template-name') || '';

				if (!templateId) {
					h.addAssistantMessage({
						success: false,
						message: missingTemplateMessage()
					});
					return;
				}

				h.openModal(
					'Delete this template?',
					'<p>This will delete the reusable email template.</p>' +
					'<p><strong>Template name:</strong> ' + h.escapeHtml(templateName) + '</p>',
					function () {
					const loading = h.addLoadingMessage();

					h.executeActionDirect(
						'email_templates.delete',
						{
							template_id: templateId
						},
						true,
						false
					)
						.then(function(data) {
							loading.remove();

							h.addAssistantMessage(data);
							h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
						})
						.catch(function(error) {
							loading.remove();

							h.addAssistantMessage({
								success: false,
								error: error
							});
						});
				}
			);

			return;
		}

		if (target.classList.contains('yoro-use-email-template')) {
			const templateId = target.getAttribute('data-template-id') || '';
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!templateId) {
					h.addAssistantMessage({
						success: false,
						message: missingTemplateMessage()
					});
					return;
				}

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const dropdown = target.closest('.yoro-template-dropdown');
			if (dropdown) {
				dropdown.classList.remove('open');
			}

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'orders.use_email_template',
				{
					order_id: parseInt(orderId, 10),
					template_id: templateId
				},
				false,
				false
			)
				.then(function(data) {
					loading.remove();

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-save-email-template')) {
			const dropdown = target.closest('.yoro-template-dropdown');
			const card = target.closest('.yoro-order-detail-card');
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			const subjectInput = card ? card.querySelector('.yoro-email-subject-input') : null;
			const bodyInput = card ? card.querySelector('.yoro-email-body-input') : null;
			const subject = readEmailEditableValue(subjectInput);
			const body = readEmailEditableValue(bodyInput);

				if (!subject || !body) {
					h.addAssistantMessage({
						success: false,
						message: 'Please add both a subject and body before saving this template.'
					});
					return;
				}

			const suggestedName = subject
				.replace(/\s+/g, ' ')
				.trim()
				.slice(0, 80);
			const templateName = window.prompt('Template name', suggestedName);

			if (!templateName || !templateName.trim()) {
				return;
			}

			if (dropdown) {
				dropdown.classList.remove('open');
				dropdown.classList.remove('loaded');
			}

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'email_templates.save',
				{
					name: templateName.trim(),
					subject: subject,
					body: body
				},
				true,
				false
			)
				.then(function(data) {
					loading.remove();

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-template-toggle')) {
			const dropdown = target.closest('.yoro-template-dropdown');

			if (!dropdown) return;

			dropdown.classList.toggle('open');

			if (!dropdown.classList.contains('loaded')) {
				const menu = dropdown.querySelector('.yoro-template-menu');
				const orderId = menu.getAttribute('data-order-id');

				h.executeActionDirect('email_templates.list', {}, false, false)
					.then(function(data) {
						const templates = data.result?.templates || [];

						let html = '';

						html += '<button type="button" class="button-link yoro-save-email-template"';
						html += ' data-order-id="' + h.escapeHtml(orderId) + '">';
						html += 'Save new template';
						html += '</button>';
						html += '<div class="yoro-template-menu-divider" aria-hidden="true"></div>';

						if (!templates.length) {
							html += '<p>No saved templates yet.</p>';
						} else {
							templates.forEach(function(tpl) {
								html += '<button type="button" class="button-link yoro-use-email-template"';
								html += ' data-template-id="' + h.escapeHtml(tpl.id) + '"';
								html += ' data-order-id="' + h.escapeHtml(orderId) + '">';
								html += h.escapeHtml(tpl.name);
								html += '</button>';
							});
						}

						menu.innerHTML = html;
						dropdown.classList.add('loaded');
					});
			}

			return;
		}

		if (target.classList.contains('yoro-draft-toggle')) {
			const dropdown = target.closest('.yoro-draft-dropdown');

			if (dropdown) {
				dropdown.classList.toggle('open');
			}

			return;
		}

		if (target.classList.contains('yoro-save-email-draft')) {
			const card = target.closest('.yoro-order-detail-card');
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const subjectInput = card ? card.querySelector('.yoro-email-subject-input') : null;
			const bodyInput = card ? card.querySelector('.yoro-email-body-input') : null;

			const subject = readEmailEditableValue(subjectInput);
			const body = readEmailEditableValue(bodyInput);

				if (!orderId || !subject || !body) {
					h.addAssistantMessage({
						success: false,
						message: missingEmailContentMessage()
					});
					return;
				}

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'orders.save_customer_email_draft',
				{
					order_id: parseInt(orderId, 10),
					subject: subject,
					body: body
				},
				true,
				false
			)
				.then(function(data) {
					loading.remove();

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-load-email-drafts')) {
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'orders.get_customer_email_drafts',
				{
					order_id: parseInt(orderId, 10)
				},
				false,
				false
			)
				.then(function(data) {
					loading.remove();

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-use-email-draft')) {
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const subject = target.getAttribute('data-subject') || '';
			const body = target.getAttribute('data-body') || '';
			const draftId = target.getAttribute('data-draft-id') || '';

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'orders.render_customer_email_preview',
				{
					order_id: parseInt(orderId, 10),
					subject: subject,
					body: body,
					draft_id: draftId,
					preview_source: 'draft'
				},
				false,
				false
			)
				.then(function(data) {
					loading.remove();

					const result = data.result || data;

					if (result.send_payload) {
						result.send_payload.draft_id = draftId;
					}

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-update-email-draft')) {
			const card = target.closest('.yoro-order-detail-card');

			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const subjectInput = card ? card.querySelector('.yoro-email-subject-input') : null;
			const bodyInput = card ? card.querySelector('.yoro-email-body-input') : null;

			const subject = readEmailEditableValue(subjectInput);
			const body = readEmailEditableValue(bodyInput);
			const draftId = target.getAttribute('data-draft-id') || '';

				if (!draftId || !subject || !body) {
					h.addAssistantMessage({
						success: false,
						message: missingDraftMessage() + ' Also make sure it has both a subject and body.'
					});
					return;
				}

			const loading = h.addLoadingMessage();

			h.executeActionDirect(
				'orders.update_customer_email_draft',
				{
					order_id: parseInt(orderId, 10),
					draft_id: draftId,
					subject: subject,
					body: body
				},
				false,
				false
			)
				.then(function(data) {
					loading.remove();

					h.addAssistantMessage(data);
					h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
				})
				.catch(function(error) {
					loading.remove();

					h.addAssistantMessage({
						success: false,
						error: error
					});
				});

			return;
		}

		if (target.classList.contains('yoro-delete-email-draft')) {
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const draftId = target.getAttribute('data-draft-id') || '';
			const subject = target.getAttribute('data-subject') || '';

				if (!orderId || !draftId) {
					h.addAssistantMessage({
						success: false,
						message: missingDraftMessage()
					});
					return;
				}

			h.openModal(
				'Delete this draft?',
				'<p>This will remove the saved draft from the order.</p>' +
				'<p><strong>Subject:</strong> ' + h.escapeHtml(subject) + '</p>',
				function () {
					const loading = h.addLoadingMessage();

					h.executeActionDirect(
						'orders.delete_customer_email_draft',
						{
							order_id: parseInt(orderId, 10),
							draft_id: draftId
						},
						true,
						false
					)
						.then(function(data) {
							loading.remove();

							h.addAssistantMessage(data);
							h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
						})
						.catch(function(error) {
							loading.remove();

							h.addAssistantMessage({
								success: false,
								error: error
							});
						});
				}
			);

			return;
		}

		if (target.classList.contains('yoro-send-mailbox-composed-reply')) {
			const card = target.closest('.yoro-mailbox-reply-preview-card');
			const threadId = target.getAttribute('data-thread-id') || (card ? card.getAttribute('data-mailbox-thread-id') : '');
			const providerThreadId = target.getAttribute('data-provider-thread-id') || (card ? card.getAttribute('data-provider-thread-id') : '');
			const pendingOperationToken = target.getAttribute('data-pending-operation-token') || (card ? card.getAttribute('data-pending-operation-token') : '');
			const subjectInput = card ? card.querySelector('.yoro-email-subject-input') : null;
			const bodyInput = card ? card.querySelector('.yoro-email-body-input') : null;
			const subject = readEmailEditableValue(subjectInput);
			const body = readEmailEditableValue(bodyInput);
			const to = readJsonDataAttr(target, 'data-to');
			const cc = readJsonDataAttr(target, 'data-cc');
			const sendMailboxPreviewReply = typeof h.sendMailboxPreviewReply === 'function'
				? h.sendMailboxPreviewReply
				: null;

			if (!threadId || !subject || !body) {
				h.addAssistantMessage({
					success: false,
					message: missingEmailContentMessage()
				});
				return;
			}

			if (!sendMailboxPreviewReply) {
				h.addAssistantMessage({
					success: false,
					message: 'I cannot send this connected mailbox reply from the current screen.'
				});
				return;
			}

			h.openModal(
				'Send this reply?',
				'<p>This will send the reviewed reply from the connected shop mailbox.</p>' +
				'<p><strong>Subject:</strong> ' + h.escapeHtml(subject) + '</p>',
				function () {
					const loading = h.addLoadingMessage();

					sendMailboxPreviewReply({
						thread_id: parseInt(threadId, 10),
						provider_thread_id: providerThreadId,
						pending_operation_token: pendingOperationToken,
						to: to,
						cc: cc,
						subject: subject,
						body: body
					})
						.then(function(data) {
							loading.remove();

							h.addAssistantMessage(data);
							h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || 'email.reply.send'));
						})
						.catch(function(error) {
							loading.remove();

							h.addAssistantMessage({
								success: false,
								error: error
							});
						});
				}
			);

			return;
		}

		if (target.classList.contains('yoro-send-composed-email')) {
			const card = target.closest('.yoro-order-detail-card');
			let orderId = h.resolveOrderId(target.getAttribute('data-order-id'));

				if (!orderId) {
					h.addAssistantMessage({
						success: false,
						message: missingOrderMessage()
					});
					return;
				}

			h.setCurrentOrderId(orderId);

			const subjectInput = card ? card.querySelector('.yoro-email-subject-input') : null;
			const bodyInput = card ? card.querySelector('.yoro-email-body-input') : null;

			const subject = readEmailEditableValue(subjectInput);
			const body = readEmailEditableValue(bodyInput);

				if (!orderId || !subject || !body) {
					h.addAssistantMessage({
						success: false,
						message: missingEmailContentMessage()
					});
					return;
				}

			h.openModal(
				'Send this email?',
				'<p>This will send the reviewed email to the customer.</p>' +
				'<p><strong>Subject:</strong> ' + h.escapeHtml(subject) + '</p>',
				function () {
					const loading = h.addLoadingMessage();

					h.executeActionDirect(
						'orders.send_customer_email',
						{
							order_id: parseInt(orderId, 10),
							subject: subject,
							body: body
						},
						true,
						false
					)
						.then(function(data) {
							loading.remove();

							h.addAssistantMessage(data);
							h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
						})
						.catch(function(error) {
							loading.remove();

							h.addAssistantMessage({
								success: false,
								error: error
							});
						});
				}
			);

			return;
		}

			return false;
		}

	window.YoRoEmailUI = {
		init: init
	};
})();
