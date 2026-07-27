(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
				escapeHtml: helpers.escapeHtml,
				formatDateTime: helpers.formatDateTime,
				shouldShowDebugJson: helpers.shouldShowDebugJson,
				renderWorkflowPreview: helpers.renderWorkflowPreview,
			renderMultiActionResult: helpers.renderMultiActionResult,
			renderOrderDetailsResult: helpers.renderOrderDetailsResult,
			renderOrdersResult: helpers.renderOrdersResult,
			renderCustomerSummaryResult: helpers.renderCustomerSummaryResult,
			renderReportResult: helpers.renderReportResult,
			renderOperationsAttentionQueueResult: helpers.renderOperationsAttentionQueueResult,
			renderFulfillmentQueueResult: helpers.renderFulfillmentQueueResult,
			renderInventoryQueueResult: helpers.renderInventoryQueueResult,
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
			renderEmbeddedResult: function(data) {
				return renderEmbeddedResult(data, h);
			},
			renderAssistantText: function(text) {
				return renderAssistantText(text, h);
			},
			renderJson: function(data) {
				return renderJson(data, h);
			},
				renderDebugJson: function(data) {
					return renderDebugJson(data, h);
				}
			};
	}

	function presentationRendererKeys(data) {
		data = data && typeof data === 'object' ? data : {};

		const result = data.result && typeof data.result === 'object' ? data.result : {};
		const presentation = result.presentation && typeof result.presentation === 'object'
			? result.presentation
			: {};
		const keys = [
			presentation.renderer_key,
			result.display_type || data.display_type,
			result.result_type || data.result_type
		];

		return keys.map(function(key) {
			return String(key || '').trim();
		}).filter(function(key, index, items) {
			return !!key && key !== 'generic' && items.indexOf(key) === index;
		});
	}

	function registeredResultRenderer(data, h) {
		const renderers = {
			orders_list: h.renderOrdersResult,
			customer_summary: h.renderCustomerSummaryResult,
			order_details: h.renderOrderDetailsResult,
			operations_attention_queue: h.renderOperationsAttentionQueueResult,
			fulfillment_queue: h.renderFulfillmentQueueResult,
			inventory_queue: h.renderInventoryQueueResult,
			customer_service_queue: h.renderCustomerServiceQueueResult,
			customer_email_preview: h.renderCustomerEmailPreviewResult,
			refund_preview: h.renderRefundPreviewResult,
			email_templates_list: h.renderEmailTemplatesResult,
			customer_email_drafts: h.renderCustomerEmailDraftsResult,
			product: function(payload) {
				return renderProductResult(payload, h);
			},
			fulfillment_lifecycle: function(payload) {
				return renderFulfillmentLifecycleResult(payload, h);
			},
			tracking: function(payload) {
				return renderTrackingResult(payload, h);
			}
		};
		const keys = presentationRendererKeys(data);

		for (let index = 0; index < keys.length; index++) {
			const renderer = renderers[keys[index]];

			if (typeof renderer === 'function') {
				return renderer(data);
			}
		}

		const displayType = String(
			(data && data.result && data.result.display_type)
			|| (data && data.display_type)
			|| ''
		);

		if (isReportDisplayType(displayType) && typeof h.renderReportResult === 'function') {
			return h.renderReportResult(data);
		}

		return null;
	}

	function renderEmbeddedResult(data, h) {
		const registered = registeredResultRenderer(data, h);

		if (typeof registered === 'string' && registered.trim()) {
			return registered;
		}

		return renderStructuredFallback(data, h);
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

	function humanActionLabel(action) {
		const labels = {
			'orders.search': 'Find orders',
			'orders.get_details': 'Open order details',
			'orders.refund_preview': 'Preview refund',
			'orders.create_refund': 'Create refund',
			'orders.add_tracking': 'Add shipment tracking',
			'orders.email_customer_preview': 'Preview customer email',
			'orders.email_customer_template': 'Send customer email',
			'orders.update_status': 'Update order status',
			'orders.add_note': 'Add internal note',
			'orders.compose_customer_email': 'Draft customer email',
			'orders.render_customer_email_preview': 'Preview customer email',
			'orders.send_customer_email': 'Send customer email',
			'orders.save_customer_email_draft': 'Save email draft',
			'orders.get_customer_email_drafts': 'Load email drafts',
			'orders.update_customer_email_draft': 'Update email draft',
			'orders.delete_customer_email_draft': 'Delete email draft',
			'orders.remove_note': 'Remove note',
			'orders.restore_statuses': 'Restore order status',
			'email_templates.save': 'Save email template',
			'email_templates.delete': 'Delete email template',
			'email.reply.send': 'Send email reply',
			'coupons.lookup': 'Look up coupon',
			'coupons.create': 'Create coupon',
			'coupons.update': 'Update coupon',
			'products.get_details': 'Open product details',
			'products.update_catalog': 'Update product catalog',
			'products.update_catalog_batch': 'Update product catalog batch',
			'store.get_refund_intelligence': 'Get refund intelligence',
			'store_preferences.save': 'Save store preference',
		};

		if (labels[action]) {
			return labels[action];
		}

		return String(action || 'Action')
			.replace(/^orders\./, '')
			.replace(/^pending_action\./, '')
			.replace(/^workflow\./, '')
			.replace(/_/g, ' ')
			.replace(/\b\w/g, function(chr) {
				return chr.toUpperCase();
			});
	}

	function displayLabel(value, options) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.labelFor === 'function') {
			return labels.labelFor(value, options);
		}

		return String(value || '').replace(/[_-]+/g, ' ');
	}

	function orderIdsFromParams(params) {
		params = params || {};
		const ids = Array.isArray(params.order_ids)
			? params.order_ids.slice()
			: [];

		if (Array.isArray(params.processed)) {
			params.processed.forEach(function(id) {
				if (ids.indexOf(id) === -1) {
					ids.push(id);
				}
			});
		}

		if (params.order_id && ids.indexOf(params.order_id) === -1) {
			ids.push(params.order_id);
		}

		return ids.filter(Boolean);
	}

	function orderScopeText(ids) {
		ids = Array.isArray(ids) ? ids.filter(Boolean) : [];

		if (!ids.length) {
			return 'the selected order';
		}

		if (ids.length === 1) {
			return 'order #' + ids[0];
		}

		return ids.length + ' selected orders';
	}

	function firstObjectWithKeys() {
		for (let i = 0; i < arguments.length; i++) {
			const value = arguments[i];

			if (value && typeof value === 'object' && !Array.isArray(value) && Object.keys(value).length) {
				return value;
			}
		}

		return {};
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

	function previewIntro(action, params, preview) {
		params = params || {};
		preview = preview || {};
		const ids = orderIdsFromParams(params);
		const scope = orderScopeText(ids);
		const status = params.status_label || params.status || '';

		if (isProductStockAction(action)) {
			return productApprovalIntro(action, params, preview);
		}

		if (action === 'orders.add_note') {
			return 'I’m ready to add the note to ' + scope + '. Nothing has changed yet.';
		}

		if (action === 'orders.update_status') {
			return 'I’m ready to update ' + scope + ' to ' + (status ? orderStatusLabel(status) : 'the new status') + '. Nothing has changed yet.';
		}

		return preview.description || preview.summary || 'I prepared this for review. Nothing has changed yet.';
	}

	function confirmationMessage(data, result) {
		data = data || {};
		result = result || {};

		const message = String(data.message || result.message || '').trim();
		const lower = message.toLowerCase();

		if (!message || lower.indexOf('requires confirmation') !== -1) {
			return 'Please approve this change when you are ready. Nothing has changed yet.';
		}

		if (lower.indexOf('confirmation has expired') !== -1 || lower.indexOf('preview has expired') !== -1) {
			return 'This approval request expired. Please review the action again before approving.';
		}

		return message;
	}

	function isReportDisplayType(displayType) {
		return [
			'operations_summary',
			'business_summary',
			'morning_briefing',
			'daily_briefing',
			'store_insights'
		].indexOf(String(displayType || '')) !== -1;
	}

	function formatRiskLabel(value) {
		value = formatAssistantValueLabel(value).trim();

		if (!value) {
			return 'Normal';
		}

		return value.charAt(0).toUpperCase() + value.slice(1);
	}

	function collectReviewReasons(data, context) {
		data = data || {};
		context = context || {};

		const rawReasons = []
			.concat(data.risk && Array.isArray(data.risk.reasons) ? data.risk.reasons : [])
			.concat(data.policy && Array.isArray(data.policy.reasons) ? data.policy.reasons : [])
			.concat(data.risk && Array.isArray(data.risk.semantic_warnings) ? data.risk.semantic_warnings : []);

		const parsedWarnings = data.plan &&
			data.plan.validation &&
			data.plan.validation.semantic &&
			Array.isArray(data.plan.validation.semantic.warnings)
			? data.plan.validation.semantic.warnings
			: [];

		parsedWarnings.forEach(function(warning) {
			if (warning && warning.message) {
				rawReasons.push(warning.message);
			}
		});

		const friendly = [];
		const add = function(reason) {
			reason = String(reason || '').trim();

			if (reason && friendly.indexOf(reason) === -1) {
				friendly.push(reason);
			}
		};

		if (context.writeAction || context.requiresConfirmation) {
			add('This will update your store, so I need your approval first.');
		}

		rawReasons.forEach(function(reason) {
			const text = String(reason || '').trim();
			const lower = text.toLowerCase();

			if (!text) {
				return;
			}

			if (lower.indexOf('tracking signal') !== -1 || lower.indexOf('tracking') !== -1 && lower.indexOf('overstate') !== -1) {
				add('There is no tracking signal yet, so I’ll avoid wording that implies the shipment has already been dispatched.');
				return;
			}

			if (lower.indexOf('multiple write') !== -1) {
				add('This includes more than one store change.');
				return;
			}

			if (lower.indexOf('bulk') !== -1 || lower.indexOf('multiple order') !== -1) {
				add('This touches more than one order.');
				return;
			}

			if (lower.indexOf('requires confirmation') !== -1 || lower.indexOf('policy requires') !== -1 || lower.indexOf('changes store data') !== -1) {
				return;
			}

			add(text.replace(/Action\s+"[^"]+"\s+/i, 'This action '));
		});

		return friendly.slice(0, 4);
	}

		function renderResult(data, h) {
		function finish(html) {
			const markup = typeof html === 'string' ? html : '';
			const trimmed = markup.trim();
			const hasAssistantMarkup = trimmed.indexOf('yoro-assistant-message-result') !== -1;
			const assistant = data && data.stage !== 'clarify' && !hasAssistantMarkup && !isConfirmationResult(data)
				? renderAssistantResponse(data, h)
				: '';

			if (!trimmed) {
				return markup;
			}

			if (trimmed.indexOf('class="yoro-result-debug"') !== -1) {
				return assistant + markup;
			}

			if (trimmed.indexOf('<pre class="yoro-json-output"') === 0) {
				if (h.shouldShowDebugJson && h.shouldShowDebugJson()) {
					return '<details class="yoro-result-debug"><summary>Raw JSON</summary>' + markup + '</details>';
				}

				return renderUnknownResult(data, h);
			}

			return assistant + markup + renderDebugJson(data, h);
		}

		if (!data) {
			return '';
		}

		if (isMailboxEmailPreviewResult(data) && typeof h.renderCustomerEmailPreviewResult === 'function') {
			return finish(h.renderCustomerEmailPreviewResult(data));
		}

		if (data.stage === 'confirm' && data.confirm_required) {
			return finish(renderConfirmation(data, h));
		}

		if (data.stage === 'clarify') {
			return finish(renderClarification(data, h));
		}

		if (!data.success) {
			return finish(renderError(data, h));
		}

		if (data.stage === 'preview' && data.preview) {
			return finish(renderPreview(data, h));
		}

		const result = data.result || {};
		const displayType = result.display_type || data.display_type || '';
		const resultType = result.result_type || data.result_type || '';
		const registered = registeredResultRenderer(data, h);

		if (typeof registered === 'string' && registered.trim()) {
			return finish(registered);
		}

		if (result.report && typeof h.renderReportResult === 'function') {
			return finish(h.renderReportResult(data));
		}

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

		if (isReportDisplayType(displayType)) {
			return finish(typeof h.renderReportResult === 'function'
				? h.renderReportResult(data)
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

		if (displayType === 'coupon' || resultType === 'coupon') {
			return finish(renderCouponResult(data, h));
		}

		if (displayType === 'assistant_message') {
			return finish(renderAssistantMessageResult(data, h));
		}

		if (displayType === 'multi_action') {
			return finish(typeof h.renderMultiActionResult === 'function'
				? h.renderMultiActionResult(data)
				: renderJson(data, h));
		}

		if (displayType === 'undo') {
			return finish(renderUndoResult(data, h));
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

				return finish(html);
			}

		return finish(renderUnknownResult(data, h));
	}

	function safeResultHref(value) {
		const href = String(value || '').trim();

		return /^(https?:)?\/\//i.test(href) || href.indexOf('/') === 0
			? href
			: '';
	}

	function renderProductResult(data, h) {
		const result = data && data.result ? data.result : {};
		const product = firstObjectWithKeys(
			result.product,
			Array.isArray(result.products) ? result.products[0] : {}
		);
		const summary = String(result.summary || result.message || 'Product details').trim();
		const currency = String(product.currency || '').trim();
		const currentPrice = String(product.price || product.sale_price || product.regular_price || '').trim();
		const regularPrice = String(product.regular_price || '').trim();
		const stockQuantity = product.stock_quantity === null || typeof product.stock_quantity === 'undefined'
			? 'Not quantity-managed'
			: String(product.stock_quantity);
		const editUrl = safeResultHref(product.edit_url || product.permalink || '');

		let html = '<div class="yoro-result-summary yoro-rich-result-intro">';
		html += '<strong>' + h.escapeHtml(summary) + '</strong>';
		html += '<span class="yoro-result-subtle">Current product snapshot</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-card yoro-rich-result-surface">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(product.name || 'Product') + '</strong>';
		html += '<div class="yoro-result-subtle">Product #' + h.escapeHtml(product.product_id || result.product_id || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-status-badge">' + h.escapeHtml(displayLabel(product.status || '')) + '</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Current price</span><strong>' + h.escapeHtml(currentPrice || '—') + (currency ? ' ' + h.escapeHtml(currency) : '') + '</strong></div>';
		html += '<div><span>Regular price</span><strong>' + h.escapeHtml(regularPrice || '—') + (currency ? ' ' + h.escapeHtml(currency) : '') + '</strong></div>';
		html += '<div><span>Stock</span><strong>' + h.escapeHtml(stockQuantity) + '</strong></div>';
		html += '</div>';
		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Product identity</h4>';
		html += '<p><strong>SKU:</strong> <code>' + h.escapeHtml(product.sku || '—') + '</code></p>';
		html += '<p><strong>Type:</strong> ' + h.escapeHtml(displayLabel(product.type || '—')) + '</p>';
		html += '<p><strong>Stock status:</strong> ' + h.escapeHtml(displayLabel(product.stock_status || '—')) + '</p>';
		html += '<p><strong>Catalog visibility:</strong> ' + h.escapeHtml(displayLabel(product.catalog_visibility || '—')) + '</p>';
		html += '</div>';

		if (editUrl) {
			html += '<div class="yoro-order-actions">';
			html += '<a class="button" href="' + h.escapeHtml(editUrl) + '">Open product</a>';
			html += '</div>';
		}

		html += '</div>';

		return html;
	}

	function renderFulfillmentLifecycleResult(data, h) {
		const result = data && data.result ? data.result : {};
		const order = result.order || {};
		const fulfillment = result.fulfillment || {};
		const history = Array.isArray(fulfillment.history) ? fulfillment.history : [];
		const summary = String(result.summary || result.message || 'Fulfillment details').trim();
		const editUrl = safeResultHref(order.edit_url || '');
		const trackingUrl = safeResultHref(fulfillment.tracking_url || '');

		let html = '<div class="yoro-result-summary yoro-rich-result-intro">';
		html += '<strong>' + h.escapeHtml(summary) + '</strong>';
		html += '<span class="yoro-result-subtle">Fulfillment and shipment snapshot</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-card yoro-rich-result-surface">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(order.number || order.id || result.order_id || '') + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(order.status_label || displayLabel(order.status || '')) + '</div>';
		html += '</div>';
		html += '<span class="yoro-status-badge">' + h.escapeHtml(displayLabel(fulfillment.status || 'unfulfilled')) + '</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Shipment</h4>';
		html += '<p><strong>Shipping method:</strong> ' + h.escapeHtml(fulfillment.shipping_method || '—') + '</p>';
		html += '<p><strong>Carrier:</strong> ' + h.escapeHtml(fulfillment.carrier || '—') + '</p>';
		html += '<p><strong>Tracking number:</strong> <code>' + h.escapeHtml(fulfillment.tracking_number || '—') + '</code></p>';
		html += '<p><strong>Shipped at:</strong> ' + h.escapeHtml(fulfillment.shipped_at || '—') + '</p>';
		html += '</div>';

		if (history.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Lifecycle history</h4>';
			html += '<table class="yoro-orders-table"><thead><tr><th>From</th><th>To</th><th>When</th></tr></thead><tbody>';
			history.slice(-10).forEach(function(item) {
				item = item || {};
				html += '<tr>';
				html += '<td>' + h.escapeHtml(displayLabel(item.from || '—')) + '</td>';
				html += '<td>' + h.escapeHtml(displayLabel(item.to || '—')) + '</td>';
				html += '<td>' + h.escapeHtml(item.occurred_at || '—') + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table></div>';
		}

		if (trackingUrl || editUrl) {
			html += '<div class="yoro-order-actions">';
			if (trackingUrl) {
				html += '<a class="button button-primary" href="' + h.escapeHtml(trackingUrl) + '" target="_blank" rel="noopener noreferrer">Track shipment</a>';
			}
			if (editUrl) {
				html += '<a class="button" href="' + h.escapeHtml(editUrl) + '">Open order</a>';
			}
			html += '</div>';
		}

		html += '</div>';

		return html;
	}

	function renderTrackingResult(data, h) {
		const result = data && data.result ? data.result : {};
		const tracking = result.tracking || {};
		const trackingUrl = safeResultHref(tracking.tracking_url || '');
		const summary = String(result.summary || result.message || 'Shipment tracking').trim();

		let html = '<div class="yoro-result-summary yoro-rich-result-intro">';
		html += '<strong>' + h.escapeHtml(summary) + '</strong>';
		html += '<span class="yoro-result-subtle">Shipment tracking</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-card yoro-rich-result-surface">';
		html += '<div class="yoro-order-detail-header">';
		html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(result.order_number || result.order_id || '') + '</strong>';
		html += '<span class="yoro-status-badge">' + h.escapeHtml(result.success === false ? 'Needs attention' : 'Saved') + '</span>';
		html += '</div>';
		html += '<div class="yoro-order-detail-section">';
		html += '<p><strong>Carrier:</strong> ' + h.escapeHtml(tracking.carrier || '—') + '</p>';
		html += '<p><strong>Tracking number:</strong> <code>' + h.escapeHtml(tracking.tracking_number || '—') + '</code></p>';
		html += '<p><strong>Shipped at:</strong> ' + h.escapeHtml(tracking.shipped_at || '—') + '</p>';
		html += '</div>';

		if (trackingUrl) {
			html += '<div class="yoro-order-actions">';
			html += '<a class="button button-primary" href="' + h.escapeHtml(trackingUrl) + '" target="_blank" rel="noopener noreferrer">Track shipment</a>';
			html += '</div>';
		}

		html += '</div>';

		return html;
	}

	function structuredFallbackFacts(result) {
		const excluded = [
			'action',
			'assistant_summary',
			'count',
			'display_type',
			'errors',
			'message',
			'meta',
			'presentation',
			'processed',
			'read_only',
			'result_type',
			'skipped',
			'success',
			'summary',
			'undo_plan',
			'undoable'
		];
		const primaryKeys = ['product', 'order', 'customer', 'fulfillment', 'coupon', 'email'];
		const facts = [];
		const addFacts = function(source) {
			Object.keys(source || {}).forEach(function(key) {
				const value = source[key];

				if (excluded.indexOf(key) !== -1 || value === '' || value === null || typeof value === 'undefined') {
					return;
				}

				if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
					facts.push({ key: key, value: value });
				}
			});
		};

		primaryKeys.some(function(key) {
			const value = result && result[key];

			if (value && typeof value === 'object' && !Array.isArray(value)) {
				addFacts(value);
				return true;
			}

			return false;
		});

		addFacts(result || {});

		return facts.filter(function(fact, index, items) {
			return items.findIndex(function(candidate) {
				return candidate.key === fact.key;
			}) === index;
		}).slice(0, 12);
	}

	function renderStructuredFallback(data, h) {
		const result = data && data.result ? data.result : {};
		const message =
			(result.summary || result.message || (data && data.message)) ||
			'I completed that request.';
		const facts = structuredFallbackFacts(result);

		let html = '<div class="yoro-single-action-result">';
		html += '<div class="yoro-assistant-message-result">';
		html += '<p>' + h.escapeHtml(message) + '</p>';
		html += '</div>';

		if (facts.length) {
			html += '<div class="yoro-assistant-fact-grid yoro-rich-result-surface">';
			facts.forEach(function(fact) {
				html += '<div class="yoro-assistant-fact-card">';
				html += '<span class="yoro-assistant-fact-label">' + h.escapeHtml(displayLabel(fact.key, { key: true })) + '</span>';
				html += '<span class="yoro-assistant-fact-value">' + h.escapeHtml(String(fact.value)) + '</span>';
				html += '</div>';
			});
			html += '</div>';
		}

		html += '</div>';

		return html;
	}

	function renderUnknownResult(data, h) {
		const result = data && data.result ? data.result : {};
		let html = renderStructuredFallback(data, h);

		if (result && Object.keys(result).length) {
			html += renderDebugJson(data, h);
		}

		return html;
	}

	function getAssistantResponse(data) {
		data = data && typeof data === 'object' ? data : {};
		const parsed = data.parsed && typeof data.parsed === 'object' ? data.parsed : {};
		const result = data.result && typeof data.result === 'object' ? data.result : {};
		const assistant = data.assistant || parsed.assistant || result.assistant || {};

		return assistant && typeof assistant === 'object' ? assistant : {};
	}

	function renderInlineAssistantText(text, h) {
		let html = h.escapeHtml(decodeAssistantDisplayEntities(formatAssistantDisplayText(String(text || ''))));

		html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
		html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

		return html;
	}

	function decodeAssistantDisplayEntities(text) {
		return String(text || '')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#039;/g, "'")
			.replace(/&amp;/g, '&');
	}

	function formatAssistantDisplayText(text) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.formatText === 'function') {
			return labels.formatText(text);
		}

		return String(text || '');
	}

	function formatAssistantValueLabel(text) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.labelFor === 'function') {
			return labels.labelFor(text);
		}

		return String(text || '');
	}

	function formatAssistantFactPart(text) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.formatFactPart === 'function') {
			return labels.formatFactPart(text);
		}

		return formatAssistantDisplayText(text);
	}

	function formatAssistantFactLabel(text) {
		const labels = typeof window !== 'undefined' ? window.YoRoAIDisplayLabels : null;

		if (labels && typeof labels.keyLabel === 'function') {
			return labels.keyLabel(text);
		}

		return humanizeAssistantFactLabel(text);
	}

	function renderAssistantRichText(text, h) {
		const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
		let html = '';
		let paragraph = [];
		let listItems = [];
		let listType = '';
		let factRows = [];

		function flushParagraph() {
			if (!paragraph.length) {
				return;
			}

			html += '<p>' + paragraph.map(function(line) {
				return renderInlineAssistantText(line, h);
			}).join('<br>') + '</p>';
			paragraph = [];
		}

		function flushFacts() {
			if (!factRows.length) {
				return;
			}

			html += '<div class="yoro-assistant-fact-grid">';
			factRows.forEach(function(row) {
				html += '<div class="yoro-assistant-fact-card">';
				html += '<span class="yoro-assistant-fact-label">' + h.escapeHtml(row.label) + '</span>';
				html += '<span class="yoro-assistant-fact-value">' + renderAssistantFactValue(row.value, h) + '</span>';
				html += '</div>';
			});
			html += '</div>';
			factRows = [];
		}

		function flushList() {
			if (!listItems.length) {
				return;
			}

			const tag = listType === 'ol' ? 'ol' : 'ul';
			html += '<' + tag + ' class="yoro-assistant-rich-list yoro-assistant-rich-list-' + tag + '">';
			listItems.forEach(function(item) {
				const isObject = item && typeof item === 'object';
				const itemText = isObject ? String(item.text || '') : String(item || '');
				const checkbox = isObject ? String(item.checkbox || '') : '';
				const classes = checkbox ? ' class="yoro-assistant-rich-checkbox yoro-assistant-rich-checkbox-' + h.escapeHtml(checkbox) + '"' : '';
				const checkboxMarkup = checkbox
					? '<span class="yoro-assistant-rich-checkbox-box" aria-hidden="true"></span>'
					: '';

				html += '<li' + classes + '>' + checkboxMarkup + '<span>' + renderInlineAssistantText(itemText, h) + '</span></li>';
			});
			html += '</' + tag + '>';
			listItems = [];
			listType = '';
		}

		lines.forEach(function(rawLine) {
			const line = String(rawLine || '').trim();
			const lineLooksLikeListItem = /^[-*]\s+/.test(line) || /^\d+[\.)]\s+/.test(line);
			const fact = lineLooksLikeListItem ? null : parseAssistantFactLine(line);
			let match;

			if (!line) {
				flushParagraph();
				flushList();
				flushFacts();
				return;
			}

			match = line.match(/^#{1,3}\s+(.+)$/);
			if (!match) {
				match = line.match(/^\*\*([^*]+)\*\*:?\s*$/);
			}
			if (!match && !/^here\b/i.test(line)) {
				match = line.match(/^(.{2,80}):$/);
			}
			if (match && !shouldRenderAssistantHeading(match[1])) {
				match = null;
			}

			if (match) {
				flushParagraph();
				flushList();
				flushFacts();
				html += '<p class="yoro-assistant-rich-heading">' + renderInlineAssistantText(match[1], h) + '</p>';
				return;
			}

			if (fact) {
				flushParagraph();
				flushList();
				factRows.push(fact);
				return;
			}

			match = line.match(/^(\d+)\)\s+(.+)$/);
			if (match) {
				flushParagraph();
				flushList();
				flushFacts();
				html += '<p class="yoro-assistant-rich-heading yoro-assistant-numbered-heading">';
				html += '<span class="yoro-assistant-heading-number">' + h.escapeHtml(match[1]) + '</span>';
				html += '<span>' + renderInlineAssistantText(match[2], h) + '</span>';
				html += '</p>';
				return;
			}

			match = line.match(/^[-*]\s+(.+)$/);
			if (match) {
				const checkboxItem = parseAssistantCheckboxItem(match[1]);
				const listFact = checkboxItem ? null : parseAssistantFactLine(match[1]);

				flushParagraph();
				if (listType && listType !== 'ul') {
					flushList();
				}

				if (checkboxItem) {
					flushFacts();
					listType = 'ul';
					listItems.push(checkboxItem);
					return;
				}

				if (listFact) {
					flushList();
					factRows.push(listFact);
					return;
				}

				flushFacts();
				listType = 'ul';
				listItems.push(match[1]);
				return;
			}

			match = line.match(/^\d+[\.)]\s+(.+)$/);
			if (match) {
				flushParagraph();
				if (listType && listType !== 'ol') {
					flushList();
				}
				flushFacts();
				listType = 'ol';
				listItems.push(match[1]);
				return;
			}

			flushList();
			flushFacts();
			paragraph.push(line);
		});

		flushParagraph();
		flushList();
		flushFacts();

		return html || '<p>' + h.escapeHtml(String(text || '')) + '</p>';
	}

	function parseAssistantCheckboxItem(text) {
		const match = String(text || '').match(/^\[([ xX])\]\s+(.+)$/);

		if (!match) {
			return null;
		}

		return {
			checkbox: String(match[1] || '').toLowerCase() === 'x' ? 'checked' : 'empty',
			text: String(match[2] || '').trim()
		};
	}

	function normalizeAssistantClassifierText(value) {
		let text = String(value || '');

		if (typeof text.normalize === 'function') {
			text = text.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
		}

		return text
			.replace(/\u0111/g, 'd')
			.replace(/\u0110/g, 'D')
			.replace(/[_-]+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function assistantClassifierTokens(value) {
		return normalizeAssistantClassifierText(value)
			.toLowerCase()
			.replace(/[^a-z0-9]+/g, ' ')
			.replace(/\s+/g, ' ')
			.trim();
	}

	function isAssistantAllCapsLabel(label) {
		const normalized = normalizeAssistantClassifierText(label).replace(/[^A-Za-z0-9]+/g, '');

		return !!normalized && /[A-Z]/.test(normalized) && normalized === normalized.toUpperCase();
	}

	function assistantLabelWordCount(label) {
		const normalized = assistantClassifierTokens(label);

		return normalized ? normalized.split(/\s+/).length : 0;
	}

	function isAssistantKnownTextHeading(label) {
		const normalized = assistantClassifierTokens(label);
		const headings = [
			'answer',
			'context',
			'details',
			'key points',
			'next step',
			'next steps',
			'note',
			'notes',
			'overview',
			'recommendation',
			'recommendations',
			'result',
			'results',
			'summary',
			'takeaway',
			'takeaways',
			'what changed',
			'luu y',
			'tom tat'
		];

		return headings.indexOf(normalized) !== -1;
	}

	function isAssistantOperationalFactLabel(label) {
		const raw = String(label || '').trim();
		const normalized = assistantClassifierTokens(raw);
		const padded = ' ' + normalized + ' ';
		const keywords = [
			'action',
			'address',
			'admin',
			'age',
			'amount',
			'average',
			'backlog',
			'body',
			'category',
			'code',
			'coupon',
			'count',
			'customer',
			'date',
			'discount',
			'draft',
			'email',
			'expires',
			'flag',
			'fulfillment',
			'gross',
			'id',
			'inventory',
			'item',
			'limit',
			'locale',
			'margin',
			'message',
			'method',
			'name',
			'net',
			'note',
			'open',
			'order',
			'payment',
			'phone',
			'plan',
			'price',
			'product',
			'queue',
			'refund',
			'revenue',
			'sales',
			'schema',
			'shipping',
			'sku',
			'source',
			'status',
			'stock',
			'subject',
			'subtotal',
			'tax',
			'template',
			'total',
			'tracking',
			'type',
			'usage',
			'window',
			'aov',
			'ban nhap',
			'chu de',
			'doanh thu',
			'don hang',
			'giam gia',
			'giao hang',
			'gia',
			'han',
			'hoan tien',
			'khach hang',
			'ma',
			'mau',
			'ngay',
			'san pham',
			'so luong',
			'thanh toan',
			'thue',
			'ton kho',
			'tong',
			'trang thai',
			'van chuyen'
		];

		if (!normalized) {
			return false;
		}

		if (/_/.test(raw) || /[a-z][A-Z]/.test(raw)) {
			return true;
		}

		if (/^(ID|SKU|URL|AOV|VAT)$/i.test(raw)) {
			return true;
		}

		return keywords.some(function(keyword) {
			return padded.indexOf(' ' + keyword + ' ') !== -1;
		});
	}

	function isAssistantSpeakerLabel(label) {
		const normalized = assistantClassifierTokens(label);
		const speakers = [
			'barista',
			'cashier',
			'client',
			'customer',
			'employee',
			'guest',
			'manager',
			'owner',
			'seller',
			'shopkeeper',
			'staff',
			'waiter',
			'waitress',
			'chu quan',
			'khach',
			'khach hang',
			'nguoi ban',
			'nguoi mua',
			'nhan vien',
			'ong chu'
		];

		if (!normalized) {
			return false;
		}

		return speakers.some(function(speaker) {
			return normalized === speaker ||
				normalized.indexOf(speaker + ' ') === 0 ||
				normalized.indexOf(' ' + speaker + ' ') !== -1;
		});
	}

	function isAssistantQuotedSpeech(value) {
		return /^[\s"'\u2018\u2019\u201c\u201d]+/.test(String(value || ''));
	}

	function isAssistantMetricLikeValue(value) {
		const text = String(value || '').trim();

		return /^#?\d/.test(text) ||
			/^[+$€£¥₫]/.test(text) ||
			/^(yes|no|true|false|enabled|disabled|none|n\/a)$/i.test(text);
	}

	function shouldRenderAssistantHeading(label) {
		const raw = String(label || '').replace(/:$/, '').trim();

		if (!raw) {
			return false;
		}

		if (isAssistantKnownTextHeading(raw) || isAssistantOperationalFactLabel(raw)) {
			return true;
		}

		if (isAssistantSpeakerLabel(raw)) {
			return false;
		}

		if (isAssistantAllCapsLabel(raw)) {
			return false;
		}

		return assistantLabelWordCount(raw) <= 7;
	}

	function parseAssistantFactLine(line) {
		const match = String(line || '').match(/^(.{2,86}?):\s+(.+)$/);

		if (!match) {
			return null;
		}

		const rawLabel = String(match[1] || '').trim();
		const value = String(match[2] || '').trim();

		if (!rawLabel || !value || rawLabel.indexOf('http') === 0) {
			return null;
		}

		if (/^[\s.,!?;:-]+$/.test(rawLabel) || assistantLabelWordCount(rawLabel) > 7) {
			return null;
		}

		if (isAssistantQuotedSpeech(value) && isAssistantSpeakerLabel(rawLabel)) {
			return null;
		}

		if (/-/.test(rawLabel)) {
			return null;
		}

		if (!isAssistantOperationalFactLabel(rawLabel)) {
			if (/_/.test(rawLabel) || isAssistantSpeakerLabel(rawLabel) || isAssistantQuotedSpeech(value)) {
				return null;
			}

			if (assistantLabelWordCount(rawLabel) > 4 || !isAssistantMetricLikeValue(value)) {
				return null;
			}
		}

		if (isAssistantAllCapsLabel(rawLabel) && !isAssistantOperationalFactLabel(rawLabel)) {
			return null;
		}

		return {
			label: formatAssistantFactLabel(rawLabel),
			value: value
		};
	}

	function humanizeAssistantFactLabel(label) {
		let text = String(label || '')
			.replace(/[_-]+/g, ' ')
			.replace(/([a-z])([A-Z])/g, '$1 $2')
			.replace(/\s+/g, ' ')
			.trim();

		if (!text) {
			return '';
		}

		text = text.replace(/\b\w/g, function(chr) {
			return chr.toUpperCase();
		});

		return text;
	}

	function isBlockedResult(data) {
		const result = data && data.result ? data.result : {};

		return result.blocked === true || !!result.block_reason;
	}

	function isConfirmationResult(data) {
		const result = data && data.result ? data.result : {};
		const displayType = result.display_type || (data ? data.display_type : '') || '';

		return (data && data.stage === 'confirm') || displayType === 'confirmation' || isBlockedResult(data);
	}

	function mailboxEmailPreviewPayload(data) {
		const result = data && data.result ? data.result : {};
		const parsed = data && data.parsed ? data.parsed : {};
		const actionPlan = data && data.action_plan
			? data.action_plan
			: (parsed.action_plan || result.action_plan || {});

		return result.email_preview || data.email_preview || parsed.email_preview || actionPlan.email_preview || {};
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

	function isMailboxEmailPreviewResult(data) {
		if (isTerminalPendingResponse(data)) {
			return false;
		}

		const result = data && data.result ? data.result : {};
		const preview = mailboxEmailPreviewPayload(data);
		const action = result.action || (data && data.action) || (data && data.parsed && data.parsed.action) || preview.action || '';
		const sendAction = preview.send_action || result.send_action || '';
		const hasPreview = !!preview && Object.keys(preview).length > 0;

		return hasPreview
			&& (preview.kind === 'mailbox_reply'
				|| result.kind === 'mailbox_reply'
				|| action === 'email.reply.send'
				|| sendAction === 'email.reply.send');
	}

	function renderAssistantFactValue(value, h) {
		const text = String(value || '').trim();
		const parts = splitAssistantFactValue(text);

		if (parts.length >= 2 && parts.length <= 8) {
			return '<span class="yoro-assistant-fact-chip-list">' + parts.map(function(part) {
				return '<span class="yoro-assistant-fact-chip">' + renderInlineAssistantText(formatAssistantFactPart(part), h) + '</span>';
			}).join('') + '</span>';
		}

		return renderInlineAssistantText(formatAssistantFactPart(text), h);
	}

	function splitAssistantFactValue(value) {
		const text = String(value || '').trim();

		if (!text || text.length > 180 || text.indexOf('://') !== -1 || /[.;]$/.test(text)) {
			return [];
		}

		if (text.indexOf(',') === -1) {
			return [];
		}

		const parts = text.split(',')
			.map(function(part) {
				return part.trim();
			})
			.filter(function(part) {
				return part.length > 0 && part.length <= 42;
			});

		return parts.length >= 2 && parts.length === text.split(',').length ? parts : [];
	}

	function renderAssistantText(text, h) {
		const message = String(text || '').trim();

		if (!message) {
			return '';
		}

		return '<div class="yoro-assistant-message-result yoro-assistant-natural-response">'
			+ renderAssistantRichText(message, h)
			+ '</div>';
	}

	function renderAssistantResponse(data, h) {
		const assistant = getAssistantResponse(data);
		const message = String(assistant.message || '').trim();
		const question = String(assistant.question || '').trim();
		const text = message || question;

		if (!text) {
			return '';
		}

		let html = renderAssistantText(text, h).replace(/<\/div>\s*$/, '');

		if (question && question !== message && message.indexOf(question) === -1) {
			html += '<div class="yoro-result-subtle">' + renderAssistantRichText(question, h) + '</div>';
		}

		html += '</div>';

		return html;
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
				'I could not finish that request.';

		let html = '<div class="yoro-result-notice yoro-result-error">';
		html += '<strong>I couldn’t complete that</strong>';
		html += '<p>' + h.escapeHtml(message) + '</p>';

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
				html += '<div class="yoro-result-examples">';
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

	function renderWhatChangesSection(title, lines, h) {
		const items = Array.isArray(lines) ? lines.filter(Boolean) : [];

		if (!items.length) {
			return '';
		}

		let html = '<div class="yoro-result-section yoro-guided-result-section">';
		html += '<strong>' + h.escapeHtml(title || 'Change details') + '</strong>';
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

	function getPendingOperationToken(data) {
		const parsed = data && data.parsed ? data.parsed : {};
		const result = data && data.result ? data.result : {};
		const preview = data && data.preview ? data.preview : {};
		const meta = parsed.meta || result.meta || preview.meta || (data && data.meta) || {};

		return (data && data.pending_operation_token) ||
			parsed.pending_operation_token ||
			result.pending_operation_token ||
			preview.pending_operation_token ||
			meta.pending_operation_token ||
			meta.yoro_ai_api_pending_operation_token ||
			'';
	}

	function isPendingOperationActive(data) {
		if (!data || data.pending_operation_active === undefined) {
			return true;
		}

		return data.pending_operation_active !== false;
	}

	function renderInactivePendingOperationNotice(data, h) {
		const status = data && data.pending_operation_status
			? formatAssistantValueLabel(data.pending_operation_status)
			: 'no longer pending';

		return ''
			+ '<p class="yoro-friendly-action-note yoro-friendly-action-warning">'
			+ 'This approval is ' + h.escapeHtml(status) + '. Send the request again when you want me to apply it.'
			+ '</p>';
	}

	function canApproveDirectConfirmation(data, action) {
		return !!(
			action &&
			data &&
			data.direct_confirmation_allowed === true
		);
	}

	function buildActionChangeLines(action, params, result, options) {
		params = params || {};
		result = result || {};
		options = options || {};
		const completed = options.completed === true;
		const lines = [];

		if (isCouponAction(action) && (result.blocked === true || result.block_reason)) {
			return couponConfirmationLines(action, params, result).filter(Boolean);
		}

		if (isProductStockAction(action)) {
			return productConfirmationLines(action, params, result);
		}

		if (isCouponAction(action)) {
			return couponConfirmationLines(action, params, result, { completed: completed });
		}

		if (action === 'products.update_catalog_batch') {
			const batchItems = Array.isArray(result.items) ? result.items : (Array.isArray(result.results) ? result.results : []);

			batchItems.slice(0, 20).forEach(function(item) {
				item = item || {};
				const target = item.product_id ? ('Product #' + item.product_id) : ('Batch item ' + (Number(item.index || 0) + 1));
				const fields = Array.isArray(item.changed_fields) ? item.changed_fields.join(', ') : '';
				const state = item.blocked || item.success === false ? (item.message || item.block_reason || 'needs correction') : (completed ? 'updated' : 'will update');
				lines.push(target + ': ' + state + (fields ? ' (' + fields + ')' : '') + '.');
			});

			if (batchItems.length) {
				return lines.filter(Boolean);
			}
		}

		if (result.blocked === true || result.block_reason) {
			const blockReason = String(result.block_reason || '');
			const blockedOrderIds = [];

			if (result.order_exists === false && (result.order_id || params.order_id)) {
				blockedOrderIds.push(result.order_id || params.order_id);
			} else if (blockReason.indexOf('order_not_found') !== -1) {
				orderIdsFromParams(Object.assign({}, params, result)).forEach(function(id) {
					blockedOrderIds.push(id);
				});
			}

			blockedOrderIds.forEach(function(id) {
				lines.push('Order #' + id + ': not found.');
			});

			if (result.draft_exists === false && (result.draft_id || params.draft_id)) {
				lines.push('Email draft ' + (result.draft_id || params.draft_id) + ': not found.');
			}

			if (result.template_exists === false && (result.template_id || params.template_id)) {
				lines.push('Template ' + (result.template_id || params.template_id) + ': not found.');
			} else if (blockReason.indexOf('template_not_found') !== -1 && (result.template || params.template)) {
				lines.push('Template ' + (result.template || params.template) + ': not found.');
			}

			if (!lines.length && (result.message || result.summary)) {
				lines.push(result.message || result.summary);
			}

			if (lines.length) {
				return lines.filter(Boolean);
			}
		}

		if (action === 'orders.update_status') {
			if (Array.isArray(result.orders) && result.orders.length) {
				const targetStatus = result.target_status_label || result.target_status || params.status_label || params.status || '';

				result.orders.forEach(function(order) {
					order = order || {};

					if (order.exists === false) {
						lines.push('Order #' + (order.id || '') + ': not found.');
						return;
					}

					const currentStatus = order.current_status_label || order.current_status || '';
					lines.push('Order #' + (order.id || '') + ': ' + (currentStatus ? formatAssistantValueLabel(currentStatus) : 'current status') + ' -> ' + (targetStatus ? formatAssistantValueLabel(targetStatus) : 'target status') + '.');
				});

				return lines.filter(Boolean);
			}

			const ids = orderIdsFromParams(Object.assign({}, params, result));
			const status = result.status_label || result.status || params.status_label || params.status || '';
			const statusLabel = status ? orderStatusLabel(status) : '';

			ids.forEach(function(id) {
				lines.push('Order #' + id + (completed ? ' moved to ' : ' will move to ') + (statusLabel || 'the new status') + '.');
			});
		}

		if (action === 'orders.add_note') {
			if (Array.isArray(result.orders) && result.orders.length) {
				result.orders.forEach(function(order) {
					order = order || {};

					if (order.exists === false) {
						lines.push('Order #' + (order.id || '') + ': not found.');
						return;
					}

					lines.push('Order #' + (order.id || '') + ' will get this note' + (result.note_preview ? ': "' + result.note_preview + '"' : '.'));
				});

				return lines.filter(Boolean);
			}

			const ids = orderIdsFromParams(Object.assign({}, params, result));
			const note = result.note || params.note || '';

			ids.forEach(function(id) {
				lines.push('Order #' + id + (completed ? ' got this note' : ' will get this note') + (note ? ': "' + note + '"' : '.'));
			});
		}

		if (action === 'orders.remove_note') {
			if (Array.isArray(result.notes) && result.notes.length) {
				result.notes.forEach(function(note) {
					note = note || {};

					if (note.exists === false) {
						lines.push('Note #' + (note.id || '') + ': not found.');
						return;
					}

					if (note.yoro_created === false) {
						lines.push('Note #' + (note.id || '') + ': not removable by YoRo.');
						return;
					}

					lines.push('Note #' + (note.id || '') + (note.order_id ? ' on order #' + note.order_id : '') + ' will be removed.');
				});

				return lines.filter(Boolean);
			}

			const noteIds = Array.isArray(result.note_ids) ? result.note_ids : (Array.isArray(params.note_ids) ? params.note_ids : []);

			noteIds.forEach(function(id) {
				lines.push('Note #' + id + (completed ? ' removed.' : ' will be removed.'));
			});
		}

		if (action === 'orders.restore_statuses') {
			if (Array.isArray(result.orders) && result.orders.length) {
				result.orders.forEach(function(order) {
					order = order || {};

					if (order.exists === false) {
						lines.push('Order #' + (order.id || '') + ': not found.');
						return;
					}

					lines.push('Order #' + (order.id || '') + ' will be restored to ' + (order.target_status_label || orderStatusLabel(order.target_status) || 'the previous status') + '.');
				});

				return lines.filter(Boolean);
			}
		}

		if (action === 'orders.email_customer_template' || action === 'orders.send_customer_email') {
			const email = result.email || {};
			const orderId = result.order_id || (params && params.order_id) || '';

			if (orderId) {
				lines.push((completed ? 'I sent' : 'I will send') + ' the customer email for order #' + orderId + '.');
			}
			if (email.to) {
				lines.push('Recipient: ' + email.to);
			}
			if (email.subject) {
				lines.push('Subject: ' + email.subject);
			}
		}

		if (action === 'email_templates.save') {
			const name = result.name || params.name || '';
			const subject = result.subject || params.subject || '';

			if (name) {
				lines.push('Template: ' + name);
			}

			if (subject) {
				lines.push('Subject: ' + subject);
			}
		}

		if (action === 'email_templates.delete') {
			const template = result.template || {};
			const templateId = result.template_id || params.template_id || '';
			const name = template.name || result.name || params.name || '';

			if (result.template_exists === false) {
				lines.push('Template ' + (templateId ? templateId : 'target') + ': not found.');
			} else if (name) {
				lines.push('Template: ' + name + (templateId ? ' (' + templateId + ')' : ''));
			} else if (templateId) {
				lines.push('Template ID: ' + templateId);
			}
		}

		return lines.filter(Boolean);
	}

	function approvalIntro(action, params, result) {
		params = params || {};
		result = result || {};

		if (isProductStockAction(action)) {
			return productApprovalIntro(action, params, result);
		}

		if (isCouponAction(action)) {
			return result.message || result.summary || 'Please confirm this coupon before I update the store.';
		}

		if (action === 'orders.update_status') {
			return 'Please confirm this order status change before I update the store.';
		}

		if (action === 'orders.add_note') {
			return 'Please confirm this internal note before I update the store.';
		}

		return confirmationMessage({ message: '' }, result);
	}

	function isCouponAction(action) {
		return [
			'coupons.lookup',
			'coupons.create',
			'coupons.update'
		].indexOf(String(action || '')) !== -1;
	}

	function firstCouponFromResult(result) {
		result = result || {};

		if (result.coupon && typeof result.coupon === 'object' && !Array.isArray(result.coupon)) {
			return result.coupon;
		}

		if (result.data && result.data.coupon && typeof result.data.coupon === 'object' && !Array.isArray(result.data.coupon)) {
			return result.data.coupon;
		}

		if (Array.isArray(result.coupons) && result.coupons.length && result.coupons[0] && typeof result.coupons[0] === 'object') {
			return result.coupons[0];
		}

		return {};
	}

	function couponParamValue(coupon, result, params, key) {
		coupon = coupon || {};
		result = result || {};
		params = params || {};

		if (typeof coupon[key] !== 'undefined' && coupon[key] !== null && coupon[key] !== '') {
			return coupon[key];
		}

		if (typeof result[key] !== 'undefined' && result[key] !== null && result[key] !== '') {
			return result[key];
		}

		if (typeof params[key] !== 'undefined' && params[key] !== null && params[key] !== '') {
			return params[key];
		}

		return '';
	}

	function couponDiscountSummary(coupon, result, params) {
		const summary = couponParamValue(coupon, result, params, 'discount_summary');

		if (summary) {
			return summary;
		}

		const discountType = String(couponParamValue(coupon, result, params, 'discount_type') || '');
		const amount = couponParamValue(coupon, result, params, 'discount_amount');

		if (!discountType || amount === '') {
			return '';
		}

		if (discountType === 'percent') {
			return amount + '% off';
		}

		return amount + ' ' + displayLabel(discountType);
	}

	function couponConfirmationLines(action, params, result, options) {
		params = params || {};
		result = result || {};
		options = options || {};

		const coupon = firstCouponFromResult(result);
		const lines = [];
		const code = couponParamValue(coupon, result, params, 'coupon_code') || couponParamValue(coupon, result, params, 'code');
		const couponId = couponParamValue(coupon, result, params, 'coupon_id');
		const discountSummary = couponDiscountSummary(coupon, result, params);
		const discountTypeLabel = couponParamValue(coupon, result, params, 'discount_type_label');
		const discountType = couponParamValue(coupon, result, params, 'discount_type');
		const expiry = couponParamValue(coupon, result, params, 'date_expires') || couponParamValue(coupon, result, params, 'expiry_date');
		const usageCount = couponParamValue(coupon, result, params, 'usage_count');
		const usageLimit = couponParamValue(coupon, result, params, 'usage_limit');
		const usageLimitPerUser = couponParamValue(coupon, result, params, 'usage_limit_per_user');
		const description = couponParamValue(coupon, result, params, 'description');
		const flags = [];

		if (code) {
			lines.push('Coupon code: ' + code);
		}

		if (couponId) {
			lines.push('Coupon ID: ' + couponId);
		}

		if (discountSummary) {
			lines.push('Discount: ' + discountSummary);
		}

		if (discountTypeLabel || discountType) {
			lines.push('Type: ' + (discountTypeLabel || displayLabel(discountType)));
		}

		if (expiry) {
			lines.push('Expires: ' + expiry);
		}

		if (usageCount !== '' || usageLimit !== '') {
			lines.push('Usage: ' + (usageCount !== '' ? usageCount : '0') + (usageLimit !== '' ? ' / ' + usageLimit : ''));
		}

		if (usageLimitPerUser !== '') {
			lines.push('Per customer limit: ' + usageLimitPerUser);
		}

		if (description) {
			lines.push('Description: ' + description);
		}

		if (couponParamValue(coupon, result, params, 'individual_use') === true) {
			flags.push('Individual use');
		}

		if (couponParamValue(coupon, result, params, 'free_shipping') === true) {
			flags.push('Free shipping');
		}

		if (flags.length) {
			lines.push('Flags: ' + flags.join(', '));
		}

		if (!lines.length && (result.message || result.summary)) {
			lines.push(result.message || result.summary);
		}

		return lines.filter(Boolean);
	}

	function isProductStockAction(action) {
		return [
			'products.update_stock',
			'products.update_stock_status'
		].indexOf(String(action || '')) !== -1;
	}

	function firstProductFromResult(result) {
		result = result || {};

		if (Array.isArray(result.products) && result.products.length && result.products[0] && typeof result.products[0] === 'object') {
			return result.products[0];
		}

		return {};
	}

	function productConfirmationLines(action, params, result) {
		params = params || {};
		result = result || {};

		const product = firstProductFromResult(result);
		const productId = product.id || product.product_id || result.product_id || params.product_id || '';
		const productName = product.name || result.product_name || params.product_name || '';
		const sku = product.sku || product.product_sku || result.product_sku || params.product_sku || '';
		const globalUniqueId = product.global_unique_id || result.global_unique_id || params.global_unique_id || '';
		const productType = product.product_type_label || product.type_label || product.product_type || product.type || result.product_type_label || result.product_type || '';
		const parentId = product.parent_id || result.parent_id || '';
		const variationLabel = product.variation_label || result.variation_label || '';
		const variationTargets = Array.isArray(product.variation_targets) ? product.variation_targets : (Array.isArray(result.variation_targets) ? result.variation_targets : []);
		const stockScope = product.stock_scope_label || result.stock_scope_label || stockScopeLabel(product.stock_target_kind || result.stock_target_kind || '');
		const lines = [];

		if (productName) {
			lines.push('Name: ' + productName);
		}

		if (productId) {
			lines.push((String(product.product_type || result.product_type || '').toLowerCase().indexOf('variation') !== -1 ? 'Variation ID: ' : 'Product ID: ') + productId);
		}

		if (productType) {
			lines.push('Type: ' + formatAssistantValueLabel(productType));
		}

		if (parentId) {
			lines.push('Parent ID: ' + parentId);
		}

		if (variationLabel) {
			lines.push('Variation: ' + variationLabel);
		}

		if (variationTargets.length) {
			lines.push('Variations: ' + variationTargets.slice(0, 4).map(function(target) {
				target = target || {};
				const id = target.variation_id || target.id || '';
				const label = target.variation_label || target.name || '';
				return '#' + id + (label ? ' ' + label : '');
			}).join('; ') + (product.variation_targets_truncated || result.variation_targets_truncated ? '; ...' : ''));
		}

		if (sku) {
			lines.push('SKU: ' + sku);
		}

		if (globalUniqueId) {
			lines.push('Global ID: ' + globalUniqueId);
		}

		if (stockScope) {
			lines.push('Stock scope: ' + stockScope);
		}

		if (action === 'products.update_stock_status') {
			const currentStatus = product.current_stock_status_label || product.current_stock_status || result.current_stock_status_label || result.current_stock_status || '';
			const targetStatus = product.target_stock_status_label || product.target_stock_status || result.target_stock_status_label || result.stock_status || params.stock_status || '';

			if (currentStatus || targetStatus) {
				lines.push('Stock status: ' + (currentStatus ? formatAssistantValueLabel(currentStatus) : 'current status') + ' -> ' + (targetStatus ? formatAssistantValueLabel(targetStatus) : 'target status'));
			}
		}

		if (action === 'products.update_stock') {
			const currentQuantity = typeof product.current_stock_quantity !== 'undefined' && product.current_stock_quantity !== null
				? product.current_stock_quantity
				: (typeof result.previous_stock_quantity !== 'undefined' && result.previous_stock_quantity !== null ? result.previous_stock_quantity : '');
			const targetQuantity = typeof product.target_stock_quantity !== 'undefined' && product.target_stock_quantity !== null
				? product.target_stock_quantity
				: (typeof result.stock_quantity !== 'undefined' && result.stock_quantity !== null ? result.stock_quantity : params.stock_quantity);

			if (currentQuantity !== '' || typeof targetQuantity !== 'undefined') {
				lines.push('Stock quantity: ' + (currentQuantity !== '' ? currentQuantity : 'current quantity') + ' -> ' + (typeof targetQuantity !== 'undefined' && targetQuantity !== null ? targetQuantity : 'target quantity'));
			}
		}

		return lines.filter(Boolean);
	}

	function stockScopeLabel(stockTargetKind) {
		switch (String(stockTargetKind || '')) {
			case 'parent_shared_stock':
				return 'Parent shared stock';
			case 'variation_stock':
				return 'Variation stock';
			case 'product_stock':
				return 'Product stock';
			case 'manual_status':
				return 'Manual stock status';
			default:
				return '';
		}
	}

	function productApprovalIntro(action, params, result) {
		if (result && (result.blocked === true || result.block_reason)) {
			return result.message || result.summary || 'This product needs a more specific target before it can be approved.';
		}

		if (action === 'products.update_stock_status') {
			return 'Please confirm this product and target stock status before I update the store.';
		}

		return 'Please confirm this product and target stock quantity before I update the store.';
	}

	function renderFriendlyActionCard(config, h) {
		config = config || {};
		const lines = Array.isArray(config.lines) ? config.lines.filter(Boolean) : [];
		const note = String(config.note || '').trim();
		const state = config.state || 'approval';
		let html = '<div class="yoro-friendly-action-card yoro-friendly-action-card-' + h.escapeHtml(state) + '">';

		html += '<div class="yoro-friendly-action-copy">';
		if (config.kicker) {
			html += '<span class="yoro-friendly-action-kicker">' + h.escapeHtml(config.kicker) + '</span>';
		}
		html += '<strong>' + h.escapeHtml(config.title || 'Review this change') + '</strong>';
		if (config.message) {
			html += '<p>' + h.escapeHtml(config.message) + '</p>';
		}
		html += '</div>';

		if (lines.length) {
			html += '<ul class="yoro-friendly-action-list">';
			lines.slice(0, 10).forEach(function(line) {
				html += '<li>' + h.escapeHtml(line) + '</li>';
			});
			html += '</ul>';
		}

		if (note) {
			html += '<p class="yoro-friendly-action-note">' + h.escapeHtml(note) + '</p>';
		}

		if (config.actionsHtml) {
			html += config.actionsHtml;
		}

		html += '</div>';
		return html;
	}

	function safetyApprovalLines(data) {
		data = data || {};
		const result = data.result || {};
		const safety = result.safety || data.safety || {};
		const groups = Array.isArray(safety.approval_groups) ? safety.approval_groups : [];

		return groups.map(function(group) {
			group = group || {};
			const label = String(group.label || group.id || '').replace(/_/g, ' ').trim();
			const operationCount = parseInt(
				group.operation_count
				|| (Array.isArray(group.operation_ids) ? group.operation_ids.length : 0)
				|| (Array.isArray(group.actions) ? group.actions.length : 0),
				10
			) || 0;
			const targetCount = parseInt(
				group.unique_target_count
				|| group.target_count
				|| 0,
				10
			) || 0;

			if (!label) {
				return '';
			}

			if (operationCount > 0 && targetCount > 0) {
				return 'Approval scope: ' + label + ' — '
					+ operationCount + ' change' + (operationCount === 1 ? '' : 's')
					+ ' on ' + targetCount + ' target' + (targetCount === 1 ? '' : 's') + '.';
			}

			return 'Approval scope: ' + label + (targetCount > 0 ? ' (' + targetCount + ' target' + (targetCount === 1 ? '' : 's') + ')' : '');
		}).filter(Boolean);
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
		const pendingOperationToken = getPendingOperationToken(data);
		const needsApproval = !!(preview.write_action || preview.requires_confirmation);

		let html = '<div class="yoro-preview-result">';

		if (needsApproval) {
			const changeLines = buildActionChangeLines(action, effectiveParams, preview);
			let actionsHtml = '';

			if ((!pendingOperationToken && !canApproveDirectConfirmation(data, action)) || !isPendingOperationActive(data)) {
				actionsHtml = renderInactivePendingOperationNotice(data, h);
			} else {
				actionsHtml = ''
					+ '<div class="yoro-confirm-actions">'
					+ '<button class="button button-primary yoro-confirm-run" data-direct-action="' + h.escapeHtml(action) + '" data-direct-params="' + encodedParams + '" data-pending-operation-token="' + h.escapeHtml(pendingOperationToken) + '">Approve change</button>'
					+ '<button class="button yoro-confirm-cancel">Cancel</button>'
					+ '</div>';
			}

			html += renderFriendlyActionCard({
				state: 'approval',
				kicker: 'Needs your approval',
				title: 'Ready when you are',
				message: previewIntro(action, effectiveParams, preview),
				lines: changeLines,
				note: changeLines.length ? 'Nothing has changed yet.' : '',
				actionsHtml: actionsHtml
			}, h);

			html += '</div>';
			html += renderDebugJson(data, h);

			return html;
		}

		html += '<div class="yoro-result-notice yoro-result-preview">';
		html += '<strong>Ready for review</strong>';
		html += '<p>' + h.escapeHtml(previewIntro(action, effectiveParams, preview)) + '</p>';
		html += '</div>';

		html += '</div>';
		html += renderDebugJson(data, h);

		return html;
	}

	function contextDecodeEntities(value) {
		return String(value || '')
			.replace(/&#(\d+);/g, function(match, code) {
				try {
					return String.fromCodePoint(parseInt(code, 10));
				} catch (error) {
					return match;
				}
			})
			.replace(/&#x([0-9a-f]+);/gi, function(match, code) {
				try {
					return String.fromCodePoint(parseInt(code, 16));
				} catch (error) {
					return match;
				}
			})
			.replace(/&amp;/g, '&')
			.replace(/&lt;/g, '<')
			.replace(/&gt;/g, '>')
			.replace(/&quot;/g, '"')
			.replace(/&#039;/g, "'")
			.replace(/&apos;/g, "'");
	}

	function contextValue(value, fallback) {
		if (value === true) {
			return 'Yes';
		}

		if (value === false) {
			return 'No';
		}

		if (value === null || typeof value === 'undefined' || value === '') {
			return fallback || 'Not set';
		}

		return contextDecodeEntities(value);
	}

	function contextFirstValue() {
		for (let index = 0; index < arguments.length; index++) {
			const value = arguments[index];

			if (value !== null && typeof value !== 'undefined' && value !== '') {
				return value;
			}
		}

		return '';
	}

	function contextNumber(value) {
		const number = Number(value || 0);

		if (!Number.isFinite(number)) {
			return '0';
		}

		return number.toLocaleString();
	}

	function contextHumanKey(key) {
		return String(key || '')
			.replace(/_/g, ' ')
			.replace(/-/g, ' ')
			.replace(/\b\w/g, function(chr) {
				return chr.toUpperCase();
			});
	}

	function contextLine(h, label, value) {
		return '<div class="yoro-context-line"><span>' + h.escapeHtml(label) + '</span><strong>' + h.escapeHtml(contextValue(value)) + '</strong></div>';
	}

	function contextMetric(h, label, value, subtle) {
		let html = '<div class="yoro-context-metric">';
		html += '<span>' + h.escapeHtml(label) + '</span>';
		html += '<strong>' + h.escapeHtml(contextNumber(value)) + '</strong>';

		if (subtle) {
			html += '<small>' + h.escapeHtml(subtle) + '</small>';
		}

		html += '</div>';

		return html;
	}

	function contextPills(h, items, limit) {
		items = Array.isArray(items) ? items : [];
		limit = parseInt(limit || 8, 10) || 8;
		items = items.filter(Boolean).slice(0, limit);

		if (!items.length) {
			return '<span class="yoro-context-empty">None detected</span>';
		}

		return '<div class="yoro-context-pills">' + items.map(function(item) {
			return '<span>' + h.escapeHtml(String(item)) + '</span>';
		}).join('') + '</div>';
	}

	function contextEnabledMethodLabels(methods) {
		methods = Array.isArray(methods) ? methods : [];

		return methods.filter(function(method) {
			const enabled = method && typeof method === 'object' ? method.enabled : '';

			return enabled === true || enabled === 'yes' || enabled === 'enabled' || enabled === '1' || enabled === 1;
		}).map(function(method) {
			return method.title || method.label || method.name || method.method_title || method.id || '';
		}).filter(Boolean);
	}

	function contextMethodLabels(methods) {
		methods = Array.isArray(methods) ? methods : [];

		return methods.map(function(method) {
			return method.title || method.label || method.name || method.method_title || method.id || '';
		}).filter(Boolean);
	}

	function contextStatusPills(h, counts, labels) {
		counts = counts && typeof counts === 'object' ? counts : {};
		labels = labels && typeof labels === 'object' ? labels : {};

		const items = Object.keys(counts).map(function(key) {
			return {
				key: key,
				label: labels[key] || contextHumanKey(key),
				count: Number(counts[key] || 0)
			};
		}).filter(function(item) {
			return item.count > 0;
		}).sort(function(a, b) {
			return b.count - a.count;
		}).slice(0, 8);

		if (!items.length) {
			return '<span class="yoro-context-empty">No active status counts in this snapshot.</span>';
		}

		return '<div class="yoro-context-statuses">' + items.map(function(item) {
			return '<span><strong>' + h.escapeHtml(contextNumber(item.count)) + '</strong>' + h.escapeHtml(item.label) + '</span>';
		}).join('') + '</div>';
	}

	function contextKnownIntegrationLabels(known) {
		known = known && typeof known === 'object' ? known : {};

		return Object.keys(known).map(function(key) {
			const item = known[key] || {};
			const label = item.label || contextHumanKey(key);
			const level = item.yoro_adapter
				? 'integrated'
				: (item.active ? 'presence only' : '');

			return level ? (label + ' (' + level + ')') : label;
		}).filter(Boolean);
	}

	function contextCapabilityAreaLabels(categories) {
		categories = categories && typeof categories === 'object' ? categories : {};

		const labels = {
			assistant: 'YoRo assistance',
			coupons: 'Coupons',
			custom: 'Extensions',
			customers: 'Customers',
			email_templates: 'Email templates',
			operations: 'Operations',
			orders: 'Orders',
			products: 'Products',
			store: 'Store reports'
		};

		return Object.keys(categories).map(function(key) {
			return labels[key] || contextHumanKey(key);
		}).filter(Boolean).sort();
	}

	function renderContext(data, h) {
		const context = data.context || {};
		const knowledge = context.store_knowledge || {};
		const facts = knowledge.facts || {};
		const profiles = knowledge.profiles || {};
		const snapshot = knowledge.live_snapshot || {};
		const commerce = facts.commerce_model || {};
		const locale = facts.locale || {};
		const payments = facts.payments || {};
		const shipping = facts.shipping || {};
		const settings = facts.settings || {};
		const generalSettings = settings.general || {};
		const operations = profiles.operations || {};
		const inventory = profiles.inventory || {};
		const capabilities = knowledge.capabilities || {};
		const capabilitySummary = capabilities.summary || {};
		const integrations = facts.integrations || {};
		const generatedAt = knowledge.generated_at || context.generated_at || '';
		const activePaymentMethods = contextEnabledMethodLabels(payments.methods || (settings.payments && settings.payments.methods) || (context.woocommerce && context.woocommerce.payment_gateways) || []);
		const shippingMethods = contextMethodLabels(shipping.methods || []);

		let html = '<div class="yoro-context-panel">';

		html += '<div class="yoro-result-summary yoro-context-summary">';
		html += '<strong>Store context snapshot</strong>';
		html += '<span class="yoro-result-subtle">Used by YoRo to understand store settings, live queues, and executable capabilities.</span>';
		html += '</div>';

		html += '<div class="yoro-context-metrics">';
		html += contextMetric(h, 'Open orders', snapshot.open_order_count || 0, 'pending, processing, on-hold');
		html += contextMetric(h, 'Older than 48h', snapshot.attention_older_48h || 0, 'open order attention');
		html += contextMetric(h, 'Out of stock', (snapshot.inventory_counts && snapshot.inventory_counts.out_of_stock) || 0, 'products');
		html += contextMetric(h, 'Backorders', (snapshot.inventory_counts && snapshot.inventory_counts.on_backorder) || 0, 'products');
		html += '</div>';

		html += '<div class="yoro-context-grid">';

		html += '<div class="yoro-context-card">';
		html += '<h3>Store Profile</h3>';
		html += contextLine(h, 'Name', (context.store && context.store.name) || (facts.identity && facts.identity.name) || '');
		html += contextLine(h, 'URL', (context.store && context.store.home_url) || (facts.identity && facts.identity.home_url) || '');
		html += contextLine(h, 'Locale', locale.user_locale || locale.site_locale || (context.store && context.store.locale) || '');
		html += contextLine(h, 'Timezone', locale.timezone || (context.store && context.store.timezone) || '');
		html += contextLine(h, 'Currency', [locale.currency, locale.currency_symbol].filter(Boolean).join(' '));
		html += '</div>';

		html += '<div class="yoro-context-card">';
		html += '<h3>Commerce Settings</h3>';
		html += contextLine(h, 'WooCommerce', contextFirstValue(commerce.woocommerce_active, context.woocommerce && context.woocommerce.active));
		html += contextLine(h, 'Version', commerce.woocommerce_version || (context.woocommerce && context.woocommerce.version) || '');
		html += contextLine(h, 'HPOS', contextFirstValue(commerce.hpos_enabled, context.woocommerce && context.woocommerce.hpos_enabled));
		html += contextLine(h, 'Taxes', contextFirstValue(commerce.tax_enabled, generalSettings.taxes_enabled));
		html += contextLine(h, 'Coupons', contextFirstValue(commerce.coupons_enabled, generalSettings.coupons_enabled));
		html += contextLine(h, 'Stock management', commerce.stock_management_enabled);
		html += contextLine(h, 'Low stock threshold', commerce.low_stock_threshold);
		html += '</div>';

		html += '<div class="yoro-context-card yoro-context-card-wide">';
		html += '<h3>Live Order Snapshot</h3>';
		html += contextStatusPills(h, snapshot.order_status_counts || {}, snapshot.order_status_count_labels || {});
		if (operations.age_buckets) {
			html += '<div class="yoro-context-lines yoro-context-lines-compact">';
			html += contextLine(h, 'Pending >24h', operations.age_buckets.pending_older_24h || 0);
			html += contextLine(h, 'Processing >24h', operations.age_buckets.processing_older_24h || 0);
			html += contextLine(h, 'Processing >72h', operations.age_buckets.processing_older_72h || 0);
			html += contextLine(h, 'On hold >48h', operations.age_buckets.on_hold_older_48h || 0);
			html += '</div>';
		}
		html += '</div>';

		html += '<div class="yoro-context-card">';
		html += '<h3>Inventory</h3>';
		html += contextLine(h, 'Stock management', contextFirstValue(inventory.stock_management_enabled, commerce.stock_management_enabled));
		html += contextLine(h, 'Low stock threshold', contextFirstValue(inventory.low_stock_threshold, commerce.low_stock_threshold));
		html += contextLine(h, 'Out of stock', (inventory.counts && inventory.counts.out_of_stock) || (snapshot.inventory_counts && snapshot.inventory_counts.out_of_stock) || 0);
		html += contextLine(h, 'On backorder', (inventory.counts && inventory.counts.on_backorder) || (snapshot.inventory_counts && snapshot.inventory_counts.on_backorder) || 0);
		html += contextLine(h, 'Low stock sample', (inventory.counts && inventory.counts.low_stock_in_sample) || 0);
		html += '</div>';

		html += '<div class="yoro-context-card">';
		html += '<h3>Payments & Shipping</h3>';
		html += '<p class="yoro-context-section-label">Enabled payments</p>';
		html += contextPills(h, activePaymentMethods, 8);
		html += '<p class="yoro-context-section-label">Shipping methods</p>';
		html += contextPills(h, shippingMethods, 8);
		html += contextLine(h, 'Shipping zones', Array.isArray(shipping.zones) ? shipping.zones.length : 0);
		html += '</div>';

		html += '<div class="yoro-context-card yoro-context-card-wide">';
		html += '<h3>YoRo Capabilities</h3>';
		html += '<div class="yoro-context-mini-metrics">';
		html += contextMetric(h, 'Available', capabilitySummary.total || (context.actions && context.actions.total) || 0, 'store actions');
		html += contextMetric(h, 'Read-only', capabilitySummary.read || (context.actions && context.actions.read) || 0, 'can run directly');
		html += contextMetric(h, 'Approval', capabilitySummary.write || (context.actions && context.actions.write) || 0, 'changes need confirm');
		html += '</div>';
		html += '<p class="yoro-context-section-label">Available areas</p>';
		html += contextPills(h, contextCapabilityAreaLabels(capabilitySummary.categories || {}), 10);
		html += '<p class="yoro-context-boundary-note">YoRo plans the work, checks what this store can do, and runs approved WooCommerce actions through the local store connection. Changes require confirmation before anything is modified.</p>';
		html += '</div>';

		html += '<div class="yoro-context-card yoro-context-card-full">';
		html += '<h3>Integrations</h3>';
		html += '<p class="yoro-context-section-label">Known adapters and detected integrations</p>';
		html += contextPills(h, contextKnownIntegrationLabels(integrations.known || {}), 12);
		html += '<div class="yoro-context-lines yoro-context-lines-compact">';
		html += contextLine(h, 'Integrated adapters', Array.isArray(integrations.integrated_adapters) ? integrations.integrated_adapters.join(', ') : '');
		html += contextLine(h, 'Active plugins sampled', Array.isArray(integrations.active_plugins) ? integrations.active_plugins.length : 0);
		html += contextLine(h, 'Generated', generatedAt);
		html += contextLine(h, 'Schema', knowledge.schema_version ? ('v' + knowledge.schema_version) : 'v1');
		html += '</div>';
		html += '</div>';

		html += '</div>';

		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function renderAssistantMessageResult(data, h) {
		const result = data.result || {};
		const assistant = getAssistantResponse(data);
		const summary = String(assistant.message || result.summary || result.message || data.message || '').trim();
		let html = '<div class="yoro-single-action-result">';

		html += '<div class="yoro-assistant-message-result">';
		if (summary) {
			html += renderAssistantRichText(summary, h);
		}

		html += '</div>';
		html += renderDebugJson(data, h);
		html += '</div>';

		return html;
	}

	function renderUndoResult(data, h) {
		const result = data && data.result && !Array.isArray(data.result)
			? data.result
			: (data || {});
		const steps = undoStepsFromResult(result);
		const lines = buildUndoChangeLines(result, steps);

		let html = '<div class="yoro-undo-result">';

		html += renderFriendlyActionCard({
			state: 'success',
			kicker: 'Undone',
			title: 'I undid that change',
			message: result.summary || result.message || 'The saved rollback has been applied.',
			lines: lines
		}, h);

		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function undoStepsFromResult(result) {
		result = result || {};

		if (Array.isArray(result.steps)) {
			return result.steps;
		}

		if (Array.isArray(result.result)) {
			return result.result;
		}

		if (result.result && typeof result.result === 'object') {
			return [
				{
					action: result.action || result.type || result.result.action || 'undo',
					result: result.result
				}
			];
		}

		return [
			{
				action: result.action || result.type || 'undo',
				result: result
			}
		];
	}

	function buildUndoChangeLines(result, steps) {
		result = result || {};
		steps = Array.isArray(steps) ? steps : [];
		const lines = [];

		function add(line) {
			line = String(line || '').trim();

			if (line && lines.indexOf(line) === -1) {
				lines.push(line);
			}
		}

		steps.forEach(function(step) {
			step = step || {};
			const action = step.action || result.action || result.type || '';
			const stepResult = step.result || {};
			const processed = Array.isArray(stepResult.processed)
				? stepResult.processed
				: (Array.isArray(result.processed) ? result.processed : []);
			const statuses = stepResult.statuses && typeof stepResult.statuses === 'object'
				? stepResult.statuses
				: {};
			const statusLabels = stepResult.status_labels && typeof stepResult.status_labels === 'object'
				? stepResult.status_labels
				: {};

			if (action === 'orders.remove_note') {
				processed.forEach(function(id) {
					add('Removed note #' + id + '.');
				});
				return;
			}

			if (action === 'orders.restore_statuses' || action === 'orders.update_status') {
				processed.forEach(function(id) {
					const status = statuses[id] || statuses[String(id)] || '';
					const statusLabel = statusLabels[id] || statusLabels[String(id)] || (status ? orderStatusLabel(status) : '');
					add('Order #' + id + ' was restored' + (statusLabel ? ' to ' + statusLabel : '') + '.');
				});
				return;
			}

			if (action === 'delete_email_draft') {
				add('Removed the saved email draft.');
				return;
			}

			if (action === 'restore_email_draft') {
				add('Restored the previous email draft.');
				return;
			}

			if (action === 'delete_template') {
				add('Removed the saved email template.');
				return;
			}

			if (action === 'restore_template') {
				add('Restored the previous email template.');
				return;
			}

			add(stepResult.summary || stepResult.message || result.summary || result.message);
		});

		if (!lines.length && Array.isArray(result.processed)) {
			result.processed.forEach(function(id) {
				add('Restored item #' + id + '.');
			});
		}

		return lines;
	}

	function renderUndoStep(step, h) {
		const action = step.action || '';
		const stepResult = step.result || {};
		const processed = Array.isArray(stepResult.processed) ? stepResult.processed : [];

		let html = '<div class="yoro-result-section">';

		if (action === 'orders.remove_note') {
			html += '<strong>Removed notes</strong>';
		} else if (action === 'orders.restore_statuses' || action === 'orders.update_status') {
			html += '<strong>Restored order statuses</strong>';
		} else if (action === 'orders.add_note') {
			html += '<strong>Removed the saved note</strong>';
		} else {
			html += '<strong>' + h.escapeHtml(stepResult.summary || action || 'Change undone') + '</strong>';
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
		const params = firstObjectWithKeys(parsed.params, result.params, plan.params);
		const action = parsed.action || result.action || plan.action || '';
		const encodedParams = h.escapeHtml(JSON.stringify(params || {}));
		const pendingOperationToken = getPendingOperationToken(data);
		const useDirectConfirm = (plan.type || '') !== 'sequence' && action !== 'multi.query_and_apply';
		const blocked = result.blocked === true || !!result.block_reason;
		const changeLines = buildActionChangeLines(action, params, result).concat(safetyApprovalLines(data));
		const intro = blocked ? '' : approvalIntro(action, params, result);
		const title = blocked ? blockedConfirmationTitle(action, result) : (isCouponAction(action) ? 'Review coupon details' : 'Please approve this change');
		let actionsHtml = '';

		let html = '<div class="yoro-confirmation-result">';

		if (blocked) {
			actionsHtml = '';
		} else if ((!pendingOperationToken && !canApproveDirectConfirmation(data, action)) || !isPendingOperationActive(data)) {
			actionsHtml = renderInactivePendingOperationNotice(data, h);
		} else {
			actionsHtml += '<div class="yoro-confirm-actions">';
			if (useDirectConfirm) {
				actionsHtml += '<button class="button button-primary yoro-confirm-run" data-direct-action="' + h.escapeHtml(action) + '" data-direct-params="' + encodedParams + '" data-pending-operation-token="' + h.escapeHtml(pendingOperationToken) + '">Approve change</button>';
			} else {
				actionsHtml += '<button class="button button-primary yoro-confirm-run" data-pending-operation-token="' + h.escapeHtml(pendingOperationToken) + '">Approve change</button>';
			}
			actionsHtml += '<button class="button yoro-confirm-cancel">Cancel</button>';
			actionsHtml += '</div>';
		}

		html += renderFriendlyActionCard({
			state: blocked ? 'blocked' : 'approval',
			kicker: blocked ? 'Needs a valid target' : 'Needs your approval',
			title: title,
			message: intro,
			lines: changeLines,
			note: blocked ? '' : (changeLines.length ? 'Nothing has changed yet.' : ''),
			actionsHtml: actionsHtml
		}, h);

		html += '</div>';

		html += renderDebugJson(data, h);

		return html;
	}

	function blockedConfirmationTitle(action, result) {
		result = result || {};

		if (isProductStockAction(action) && String(result.block_reason || '').indexOf('variable_parent') !== -1) {
			return 'Choose a variation first';
		}

		if (isCouponAction(action)) {
			if (String(result.block_reason || '').indexOf('coupon_already_exists') !== -1) {
				return 'Use a different coupon code';
			}

			return 'Fix coupon details first';
		}

		if (String(result.block_reason || '').indexOf('order') !== -1 || Array.isArray(result.orders)) {
			return 'Choose an existing order first';
		}

		if (String(result.block_reason || '').indexOf('note') !== -1 || Array.isArray(result.notes)) {
			return 'Choose an existing YoRo note first';
		}

		if (String(result.block_reason || '').indexOf('draft') !== -1 || result.draft_exists === false) {
			return 'Choose an existing email draft first';
		}

		if (String(result.block_reason || '').indexOf('template') !== -1 || result.template_exists === false) {
			return 'Choose an existing template first';
		}

		return 'Fix this approval first';
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
		const updateStatusStep = steps.find(function(step) {
			return step && step.action === 'orders.update_status';
		}) || {};

		const limit = params.limit || (searchStep.params && searchStep.params.limit) || 0;

		if (limit) {
			meta.scopeLabel = 'latest ' + limit + ' orders';
		}

		if (updateStatusStep.params && updateStatusStep.params.status) {
			meta.targetStatus = updateStatusStep.params.status;
		}

		return meta;
	}

	function renderClarification(data, h) {
		const parsed = data.parsed || {};
		const assistant = getAssistantResponse(data);
		const message = assistant.message || assistant.question || parsed.message || data.message || 'I need a little more detail.';
		const question = assistant.question && assistant.question !== message && String(message).indexOf(assistant.question) === -1
			? assistant.question
			: '';
			const options = shouldRenderClarifyOptions(parsed) && Array.isArray(parsed.options) ? parsed.options : [];

		let html = '<div class="yoro-assistant-message-result yoro-clarification-message-result">';
		html += '<p>' + h.escapeHtml(message) + '</p>';

		if (question) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(question) + '</p>';
		}

			if (options.length) {
			html += '<div class="yoro-clarify-options">';

			options.forEach(function (option) {
				const command = option && option.command ? String(option.command) : '';
				const label = option && option.label ? String(option.label) : command || 'Use this';
				html += '<button type="button" class="button yoro-clarify-option" data-command="' + h.escapeHtml(command) + '">';
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

			const reason = String(parsed.clarify_reason || '');
			const action = String(parsed.action || '');
			const missing = Array.isArray(parsed.missing) ? parsed.missing : [];

				if (
					reason === 'missing_target' &&
					action &&
					missing.length &&
					action.indexOf('orders.') === 0
				) {
				return false;
			}

		return true;
	}

	function renderAssistantNarrative(result, h) {
		if (!result || (!result.assistant_summary && !(result.meta && result.meta.assumption))) {
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

		if (!showSummary && !(assumption && assumption.message)) {
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
		return formatAssistantValueLabel(value);
	}

	function renderCouponResult(data, h) {
		const result = data && data.result && typeof data.result === 'object'
			? data.result
			: (data || {});
		const action = result.action || (data && data.parsed && data.parsed.action) || '';
		const coupon = firstCouponFromResult(result);
		const code = couponParamValue(coupon, result, {}, 'coupon_code') || couponParamValue(coupon, result, {}, 'code');
		const editUrl = couponParamValue(coupon, result, {}, 'edit_url');
		const title = action === 'coupons.create'
			? 'Coupon created'
			: (action === 'coupons.update' ? 'Coupon updated' : (code ? 'Coupon ' + code : 'Coupon details'));
		const message = result.summary || result.message || '';
		let actionsHtml = '';

		if (editUrl) {
			actionsHtml = '<div class="yoro-confirm-actions yoro-coupon-actions">'
				+ '<a class="button" href="' + h.escapeHtml(editUrl) + '">Edit coupon</a>'
				+ '</div>';
		}

		let html = '<div class="yoro-single-action-result yoro-coupon-result">';
		html += renderFriendlyActionCard({
			state: result.read_only === true ? 'read' : 'success',
			kicker: result.read_only === true ? 'Coupon lookup' : 'Done',
			title: title,
			message: message,
			lines: couponConfirmationLines(action || 'coupons.lookup', {}, result, { completed: true }),
			actionsHtml: actionsHtml
		}, h);
		html += '</div>';
		return html;
	}

	function buildSingleActionSuccessCopy(action, result, params) {
		const count = formatActionCount(result, params);
		const orderNo = result.order && result.order.number
			? result.order.number
			: (result.order_id || params.order_id || '');
		const statusLabel = result.status_label || orderStatusLabel(result.status || params.status || '');
		const templateLabel = result.template ? labelizeActionValue(result.template) : '';

		switch (action) {
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
			case 'orders.email_customer_template':
			case 'orders.send_customer_email':
				return {
					title: 'Email sent',
					message: orderNo
						? 'I sent the customer email for order #' + orderNo + '.'
						: 'I sent the customer email.'
				};
			case 'coupons.lookup':
				return {
					title: 'Coupon found',
					message: result.summary || 'I found that coupon.'
				};
			case 'coupons.create':
				return {
					title: 'Coupon created',
					message: result.summary || 'I created that coupon.'
				};
			case 'coupons.update':
				return {
					title: 'Coupon updated',
					message: result.summary || 'I updated that coupon.'
				};
			case 'orders.add_tracking':
				return {
					title: 'Tracking added',
					message: result.summary || 'I added shipment tracking to the order.'
				};
			case 'orders.create_refund':
				return {
					title: 'Refund created',
					message: result.summary || 'I created the order refund.'
				};
			case 'products.update_catalog':
				return {
					title: 'Product updated',
					message: result.summary || 'I updated the product catalog details.'
				};
			case 'products.update_catalog_batch':
				return {
					title: result.idempotent_replay ? 'Catalog batch already applied' : 'Catalog batch completed',
					message: result.summary || 'I completed the product catalog batch.'
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
		const changeLines = buildActionChangeLines(action, params, result, { completed: true });

		let html = '<div class="yoro-single-action-result">';
		html += renderAssistantNarrative(result, h);

		html += '<div class="yoro-single-action-body">';
		html += renderAdminCopilotBoundary(data, h);

		html += renderFriendlyActionCard({
			state: 'success',
			kicker: 'Done',
			title: successCopy.title || 'Completed',
			message: successCopy.message || result.summary || 'I completed that action.',
			lines: changeLines
		}, h);

		if (h.shouldShowDebugJson && h.shouldShowDebugJson() && parsed.parser_rule) {
			html += '<p class="yoro-result-subtle">Decision route: ' + h.escapeHtml(parsed.parser_rule) + '</p>';
		}

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
					label: 'AI decision',
					summary: formatDecisionSummary(parsed, decision),
				detail: formatDecisionDetail(parsed, decision)
			},
			{
				label: 'Requested operations',
				summary: formatRequestedOperationsSummary(parsed, decision),
				detail: formatRequestedOperationsDetail(parsed, decision)
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

	function requestedOperationsFromTrace(parsed, decision) {
		if (Array.isArray(parsed.requested_operations) && parsed.requested_operations.length) {
			return parsed.requested_operations;
		}

		if (Array.isArray(decision.requested_operations) && decision.requested_operations.length) {
			return decision.requested_operations;
		}

		return [];
	}

	function formatRequestedOperationsSummary(parsed, decision) {
		const operations = requestedOperationsFromTrace(parsed, decision);

		if (!operations.length) {
			return '';
		}

		return operations.map(function (operation, index) {
			const action = operation.action || operation.operation || ('operation_' + (index + 1));
			const missing = Array.isArray(operation.missing) && operation.missing.length
				? ' missing=' + formatArray(operation.missing)
				: '';

			return (index + 1) + '. ' + action + missing;
		}).join(' | ');
	}

	function formatRequestedOperationsDetail(parsed, decision) {
		const operations = requestedOperationsFromTrace(parsed, decision);
		const parts = [];

		operations.forEach(function (operation, index) {
			const params = operation.params && typeof operation.params === 'object' ? operation.params : {};
			const detail = [];

			if (operation.operation) {
				detail.push('operation=' + operation.operation);
			}

			if (Object.keys(params).length) {
				detail.push('params=' + compactObject(params));
			}

			if (operation.evidence) {
				detail.push('evidence=' + operation.evidence);
			}

			parts.push((index + 1) + ': ' + detail.join(', '));
		});

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
