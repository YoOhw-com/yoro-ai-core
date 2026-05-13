(function () {
	'use strict';

	function getHelpers(helpers) {
		helpers = helpers || {};

		return {
			escapeHtml: helpers.escapeHtml,
			renderDebugJson: helpers.renderDebugJson,
			renderSuggestions: helpers.renderSuggestions,
			getResultSuggestions: helpers.getResultSuggestions,
			getConversationState: helpers.getConversationState,
			addAgentMessage: helpers.addAgentMessage,
			addLoadingMessage: helpers.addLoadingMessage,
			updateConversationState: helpers.updateConversationState,
			runCommandFromText: helpers.runCommandFromText,
			openModal: helpers.openModal,
			openNoteModal: helpers.openNoteModal,
			openStatusModal: helpers.openStatusModal,
			openStatusNoteModal: helpers.openStatusNoteModal,
			executeActionDirect: helpers.executeActionDirect,
			setCurrentOrderId: helpers.setCurrentOrderId,
			getSelectedOrderIds: helpers.getSelectedOrderIds
		};
	}

	function init(helpers) {
		const h = getHelpers(helpers);

		return {
			renderOrderDetailsResult: function(data) {
				return renderOrderDetailsResult(data, h);
			},
			renderOrdersResult: function(data) {
				return renderOrdersResult(data, h);
			},
			renderCustomerSummaryResult: function(data) {
				return renderCustomerSummaryResult(data, h);
			},
			renderOperationsSummaryResult: function(data) {
				return renderOperationsSummaryResult(data, h);
			},
			renderBusinessSummaryResult: function(data) {
				return renderBusinessSummaryResult(data, h);
			},
			renderMorningBriefingResult: function(data) {
				return renderMorningBriefingResult(data, h);
			},
			renderDailyBriefingResult: function(data) {
				return renderDailyBriefingResult(data, h);
			},
			renderOperationsAttentionQueueResult: function(data) {
				return renderOperationsAttentionQueueResult(data, h);
			},
			renderFulfillmentQueueResult: function(data) {
				return renderFulfillmentQueueResult(data, h);
			},
			renderInventoryQueueResult: function(data) {
				return renderInventoryQueueResult(data, h);
			},
			renderStoreInsightsResult: function(data) {
				return renderStoreInsightsResult(data, h);
			},
			renderCustomerServiceQueueResult: function(data) {
				return renderCustomerServiceQueueResult(data, h);
			},
			renderRefundPreviewResult: function(data) {
				return renderRefundPreviewResult(data, h);
			},
			handleClick: function(event) {
				return handleOrderClick(event, h);
			}
		};
	}

	function renderOrderDetailsResult(data, h) {
		const result = data.result || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const parsed = data.parsed || {};

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || ('Order details: ' + orders.length)) + '</strong>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';

		html += renderSupportFlowPanel(result, h);

		html += '<div class="yoro-order-detail-grid">';

		orders.forEach(function(order) {
			const customerName = order.customer_name || [
				order.billing_first_name || '',
				order.billing_last_name || ''
			].join(' ').trim();
			const billingAddress = [
				order.billing_company || '',
				order.billing_address_1 || '',
				order.billing_address_2 || '',
				[order.billing_city, order.billing_state, order.billing_postcode].filter(Boolean).join(', '),
				order.billing_country || ''
			].filter(Boolean);
			const shippingAddress = [
				[order.shipping_first_name, order.shipping_last_name].filter(Boolean).join(' ').trim(),
				order.shipping_company || '',
				order.shipping_address_1 || '',
				order.shipping_address_2 || '',
				[order.shipping_city, order.shipping_state, order.shipping_postcode].filter(Boolean).join(', '),
				order.shipping_country || ''
			].filter(Boolean);
			const totals = order.totals || {};
			const lineItems = Array.isArray(order.line_items) ? order.line_items : [];
			const notes = Array.isArray(order.notes) ? order.notes : [];
			const currency = order.currency || '';
			const signalItems = [
				{ label: 'Requires shipping', value: order.requires_shipping ? 'Yes' : 'No' },
				{ label: 'Tracking detected', value: order.tracking_detected ? 'Yes' : 'No' },
				{ label: 'Shipping complete', value: order.shipping_complete ? 'Yes' : 'No' },
				{ label: 'Ready to fulfill', value: order.ready_to_fulfill ? 'Yes' : 'No' }
			];

			html += '<div class="yoro-order-detail-card">';

			html += '<div class="yoro-order-detail-header">';
			html += '<div>';
			html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(order.number || order.id) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(order.date_created || '') + ' · ' + h.escapeHtml((order.age_hours || 0) + 'h old') + '</div>';
			html += '</div>';
			html += '<span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span>';
			html += '</div>';

			html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
			html += '<div><span>Total</span><strong>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div><span>Items</span><strong>' + h.escapeHtml(order.item_count || 0) + '</strong></div>';
			html += '<div><span>Payment</span><strong>' + h.escapeHtml(order.payment_method_title || order.payment_method || '—') + '</strong></div>';
			html += '<div><span>Shipping</span><strong>' + h.escapeHtml(order.shipping_method || (order.requires_shipping ? 'Missing' : 'Not needed')) + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-flags">';
			signalItems.forEach(function(signal) {
				html += '<span class="yoro-order-detail-flag"><strong>' + h.escapeHtml(signal.label) + ':</strong> ' + h.escapeHtml(signal.value) + '</span>';
			});
			html += '</div>';

			html += '<div class="yoro-order-detail-stack">';
			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Customer</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Name</span><strong>' + h.escapeHtml(customerName || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Email</span><strong>' + h.escapeHtml(order.billing_email || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Phone</span><strong>' + h.escapeHtml(order.billing_phone || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Customer ID</span><strong>' + h.escapeHtml(order.customer_id || 'Guest') + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Payment & fulfillment</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Payment</span><strong>' + h.escapeHtml(order.payment_method_title || order.payment_method || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Transaction</span><strong>' + h.escapeHtml(order.transaction_id || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Shipping method</span><strong>' + h.escapeHtml(order.shipping_method || (order.requires_shipping ? 'Missing' : 'Not needed')) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Paid</span><strong>' + h.escapeHtml(order.date_paid || '—') + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Totals</h4>';
			html += '<div class="yoro-order-detail-kv"><span>Subtotal</span><strong>' + h.escapeHtml(totals.subtotal || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Discount</span><strong>' + h.escapeHtml(totals.discount_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Shipping</span><strong>' + h.escapeHtml(totals.shipping_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Tax</span><strong>' + h.escapeHtml(totals.tax_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Refunded</span><strong>' + h.escapeHtml(totals.refunded_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '<div class="yoro-order-detail-kv"><span>Remaining</span><strong>' + h.escapeHtml(totals.remaining_total || '0') + ' ' + h.escapeHtml(currency) + '</strong></div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-surface">';
			html += '<h4>Addresses</h4>';
			html += '<div class="yoro-order-detail-kv yoro-order-detail-kv-block"><span>Billing</span><strong>' + h.escapeHtml(billingAddress.join(', ') || '—') + '</strong></div>';
			html += '<div class="yoro-order-detail-kv yoro-order-detail-kv-block"><span>Shipping</span><strong>' + h.escapeHtml(shippingAddress.join(', ') || '—') + '</strong></div>';
			html += '</div>';
			html += '</div>';

			html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
			html += '<h4>Line items</h4>';
			if (lineItems.length) {
				html += '<ul class="yoro-order-detail-list">';
				lineItems.forEach(function(item) {
					const itemMeta = [
						item.sku ? ('SKU ' + item.sku) : '',
						(item.quantity || 0) ? ('Qty ' + item.quantity) : '',
						item.virtual ? 'Virtual' : '',
						item.downloadable ? 'Downloadable' : ''
					].filter(Boolean).join(' · ');
					html += '<li><strong>' + h.escapeHtml(item.name || 'Item') + '</strong>';
					html += '<div class="yoro-result-subtle">' + h.escapeHtml(itemMeta || '—') + '</div>';
					html += '<div class="yoro-result-subtle">Line total: ' + h.escapeHtml(item.total || '0') + ' ' + h.escapeHtml(currency) + '</div></li>';
				});
				html += '</ul>';
			} else {
				html += '<p>—</p>';
			}
			html += '</div>';

			if (order.customer_note || notes.length) {
				html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
				html += '<h4>Notes</h4>';
				if (order.customer_note) {
					html += '<p><strong>Customer note:</strong> ' + h.escapeHtml(order.customer_note) + '</p>';
				}
				if (notes.length) {
					html += '<ul class="yoro-order-detail-list">';
					notes.forEach(function(note) {
						const noteMeta = [note.date || '', note.customer ? 'Customer note' : 'Internal note'].filter(Boolean).join(' · ');
						html += '<li><strong>' + h.escapeHtml(noteMeta || 'Note') + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(note.content || '') + '</div></li>';
					});
					html += '</ul>';
				}
				html += '</div>';
			}

			if (order.date_paid || order.date_completed) {
				html += '<div class="yoro-order-detail-section yoro-order-detail-section-spacious">';
				html += '<h4>Milestones</h4>';
				html += '<p><strong>Paid:</strong> ' + h.escapeHtml(order.date_paid || '—') + '</p>';
				html += '<p><strong>Completed:</strong> ' + h.escapeHtml(order.date_completed || '—') + '</p>';
				html += '</div>';
			}

			html += '<div class="yoro-order-detail-actions">';
			html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '">Actions</button> ';

			if (order.edit_url) {
				html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open order</a>';
			}

			html += '</div>';

			html += '</div>';
		});

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}	

	function renderAssistantIntro(result, h) {
		if (!result || (!result.assistant_summary && !result.assistant_followup && !(result.meta && result.meta.assumption))) {
			return '';
		}

		const assumption = result.meta && result.meta.assumption ? result.meta.assumption : null;
		let html = '<div class="yoro-result-notice yoro-result-success">';

		if (result.assistant_summary) {
			html += '<p>' + h.escapeHtml(result.assistant_summary) + '</p>';
		}

		if (assumption && assumption.message) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(assumption.message) + '</p>';
		}

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		html += '</div>';

		return html;
	}

	function renderAdminCopilotBoundaryCard(result, h) {
		return '';
	}

	function renderOrdersResult(data, h) {
		const result = data.result || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const parsed = data.parsed || {};
		const action = parsed.action || result.action || '';
		const firstOrder = orders.length ? orders[0] : null;
		const contextMeta = getOrdersListContextMeta(result, parsed, orders);
		const needsAttentionCount = orders.filter(function(order) {
			return ['pending', 'on-hold', 'failed'].indexOf(String(order && order.status ? order.status : '')) !== -1;
		}).length;
		const combinedTotal = orders.reduce(function(sum, order) {
			return sum + (parseFloat(order && order.total ? order.total : 0) || 0);
		}, 0);
		const averageAge = orders.length
			? Math.round(orders.reduce(function(sum, order) {
				return sum + (parseInt(order && order.age_hours ? order.age_hours : 0, 10) || 0);
			}, 0) / orders.length)
			: 0;

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || contextMeta.summary || ('Found ' + (result.count || orders.length) + ' orders')) + '</strong>';
		html += '<span class="yoro-result-subtle">' + h.escapeHtml(contextMeta.subtle) + '</span>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (typeof parsed.confidence !== 'undefined' && action === 'orders.find_by_identity') {
			html += '<span class="yoro-result-subtle">Confidence: ' + h.escapeHtml(Math.round(parsed.confidence * 100)) + '%</span>';
		}

		html += '</div>';

		html += renderSupportFlowPanel(result, h);

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(contextMeta.title) + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(contextMeta.meta) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml((result.count || orders.length) + ' orders') + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
		html += '<div><span>Orders</span><strong>' + h.escapeHtml(String(result.count || orders.length)) + '</strong><small class="yoro-result-subtle">' + h.escapeHtml(contextMeta.metricSubtle) + '</small></div>';
		html += '<div><span>Needs attention</span><strong>' + h.escapeHtml(String(needsAttentionCount)) + '</strong><small class="yoro-result-subtle">Pending, on-hold, or failed</small></div>';
		html += '<div><span>Combined total</span><strong>' + h.escapeHtml(combinedTotal.toFixed(2) + ' ' + getOrdersListCurrency(orders)) + '</strong><small class="yoro-result-subtle">Across visible results</small></div>';
		html += '<div><span>Average age</span><strong>' + h.escapeHtml(String(averageAge) + 'h') + '</strong><small class="yoro-result-subtle">Based on the current list</small></div>';
		html += '</div>';

		html += '<div class="yoro-bulk-actions">';
		html += '<button type="button" class="button yoro-run-hold">Hold Selected</button>';
		html += '<button type="button" class="button yoro-bulk-details">Details Selected</button>';
		html += '<button type="button" class="button yoro-bulk-add-note">Add Note Selected</button>';
		html += '<button type="button" class="button yoro-bulk-update-status">Update Status Selected</button>';
		html += '<button type="button" class="button yoro-bulk-status-note">Status + Note Selected</button>';
		html += '</div>';

		html += '<table class="yoro-orders-table">';
		html += '<thead>';
		html += '<tr>';
		html += '<th><input type="checkbox" class="yoro-select-all"></th>';
		html += '<th>Order</th>';
		html += '<th>Customer</th>';
		html += '<th>Case</th>';
		html += '<th>Total</th>';
		html += '<th>Age</th>';
		html += '<th>Next action</th>';
		html += '</tr>';
		html += '</thead>';
		html += '<tbody>';

		orders.forEach(function (order) {
			const customerLabel = order.customer_name || order.billing_email || order.billing_phone || 'Customer';
			const caseLabel = buildOrdersListCaseLabel(order, action);
			const nextActionLabel = action === 'orders.find_by_identity' ? 'Compare details' : 'Open details';
			html += '<tr>';
			html += '<td><input type="checkbox" class="yoro-order-checkbox" value="' + h.escapeHtml(order.id) + '"></td>';
			html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
			html += '<td><strong>' + h.escapeHtml(customerLabel) + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(order.billing_email || order.billing_phone || '—') + '</div></td>';
			html += '<td><span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span><div class="yoro-result-subtle">' + h.escapeHtml(caseLabel) + '</div></td>';
			html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
			html += '<td>' + h.escapeHtml(String(parseInt(order.age_hours || 0, 10) || 0) + 'h') + '<div class="yoro-result-subtle">' + h.escapeHtml(order.date_created || '') + '</div></td>';
			html += '<td class="yoro-row-actions">';
			html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">' + h.escapeHtml(nextActionLabel) + '</button> ';
			html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '">Actions</button> ';

			if (order.edit_url) {
				html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
			}

			html += '</td>';
			html += '</tr>';
		});

		html += '</tbody>';
		html += '</table>';
		html += renderQueueActionStrip([
			firstOrder ? { label: 'Show first order', command: 'Show order #' + (firstOrder.number || firstOrder.id) + ' details' } : null,
			action === 'orders.find_by_customer' && (result.email || result.phone)
				? { label: 'Customer summary', command: buildCustomerSummaryCommand(result) }
				: null,
			action === 'orders.find_by_identity' && result.order_id
				? { label: 'Show source order', command: 'Show order #' + result.order_id + ' details' }
				: null,
			{ label: 'Latest 5 orders', command: 'Show latest 5 orders' }
		].filter(Boolean), h, 'What to do next');
		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}

	function getOrdersListContextMeta(result, parsed, orders) {
		const action = parsed.action || result.action || '';
		if (action === 'orders.find_by_customer') {
			const target = result.email || result.phone || 'this customer';
			return {
				title: 'Customer order history',
				meta: 'Orders linked to ' + target,
				summary: result.summary || ('Found ' + orders.length + ' customer orders.'),
				subtle: 'Use this list to review recent customer history before you reply or update anything.',
				metricSubtle: 'Linked to the same customer'
			};
		}
		if (action === 'orders.find_by_identity') {
			return {
				title: 'Related orders by identity',
				meta: 'Compared against order #' + (result.order_id || ''),
				summary: result.summary || ('Found ' + orders.length + ' identity-matched orders.'),
				subtle: 'Use this list to inspect related orders that share email, phone, IP, or device hints.',
				metricSubtle: 'Matched by identity signals'
			};
		}
		return {
			title: 'Orders',
			meta: 'Most recent matching orders',
			summary: result.summary || ('Found ' + orders.length + ' orders.'),
			subtle: 'Use this list when you want a compact order snapshot before opening a specific order.',
			metricSubtle: 'Visible in this list'
		};
	}

	function buildOrdersListCaseLabel(order, action) {
		if (action === 'orders.find_by_identity') {
			const reasons = Array.isArray(order.match_reasons) ? order.match_reasons : [];
			if (reasons.length) {
				return 'Matched by ' + reasons.join(', ');
			}
		}
		return order.payment_method_title || order.payment_method || 'Order review';
	}

	function getOrdersListCurrency(orders) {
		const first = Array.isArray(orders) && orders.length ? orders[0] : null;
		return first && first.currency ? first.currency : '';
	}

	function buildCustomerSummaryCommand(result) {
		if (result && result.email) {
			return 'Show customer summary for ' + result.email;
		}
		if (result && result.phone) {
			return 'Show customer summary for ' + result.phone;
		}
		return 'Show customer summary';
	}

	function renderCustomerSummaryResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const orders = Array.isArray(result.orders) ? result.orders : [];
		const statusCounts = result.status_counts || {};
		const paymentMethods = result.payment_methods || {};
		const latestOrderId = result.latest_order_id || 0;
		const latestOrderNo = result.latest_order_no || latestOrderId;
		const customerLabel = result.customer_name || result.email || result.phone || 'Customer summary';

		function labelize(key) {
			return String(key || '')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
		}

		function renderStatusMix(counts) {
			const entries = Object.keys(counts || {});

			if (!entries.length) {
				return '<p class="yoro-result-subtle">No order status history yet.</p>';
			}

			let html = '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Status</th><th>Orders</th></tr></thead><tbody>';
			entries.forEach(function(key) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
				html += '<td>' + h.escapeHtml(counts[key] || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			return html;
		}

		function renderPaymentMix(methods) {
			const entries = Object.keys(methods || {});

			if (!entries.length) {
				return '<p class="yoro-result-subtle">No payment method history yet.</p>';
			}

			let html = '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Payment method</th><th>Orders</th></tr></thead><tbody>';
			entries.forEach(function(key) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(key || '—') + '</td>';
				html += '<td>' + h.escapeHtml(methods[key] || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			return html;
		}

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || ('Found ' + orders.length + ' orders for this customer.')) + '</strong>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Customer summary</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(customerLabel) + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(result.count || orders.length) + ' orders</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Total spent</span><strong>' + h.escapeHtml(result.total_spent || '0.00') + ' ' + h.escapeHtml(result.currency || '') + '</strong></div>';
		html += '<div><span>Orders</span><strong>' + h.escapeHtml(result.count || orders.length) + '</strong></div>';
		html += '<div><span>First order</span><strong>' + h.escapeHtml(result.first_order_date || '—') + '</strong></div>';
		html += '<div><span>Last order</span><strong>' + h.escapeHtml(result.last_order_date || '—') + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Customer snapshot</h4>';
		html += '<div class="yoro-order-detail-columns">';
		html += '<div>';
		html += '<p><strong>Name:</strong> ' + h.escapeHtml(result.customer_name || '—') + '</p>';
		html += '<p><strong>Email:</strong> ' + h.escapeHtml(result.email || '—') + '</p>';
		html += '</div>';
		html += '<div>';
		html += '<p><strong>Phone:</strong> ' + h.escapeHtml(result.phone || '—') + '</p>';
		html += '<p><strong>Customer ID:</strong> ' + h.escapeHtml(result.customer_id || 'Guest') + '</p>';
		html += '</div>';
		html += '</div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Status mix</h4>';
		html += renderStatusMix(statusCounts);
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Payment methods</h4>';
		html += renderPaymentMix(paymentMethods);
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Recent orders</h4>';

		if (!orders.length) {
			html += '<p class="yoro-result-subtle">No recent orders found for this customer.</p>';
		} else {
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Payment</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
			orders.slice(0, 8).forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.payment_method || '—') + '</td>';
				html += '<td>' + h.escapeHtml(order.date_created || '—') + '</td>';
				html += '<td class="yoro-row-actions">';
				html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">Details</button> ';
				if (order.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}
				html += '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
		}

		html += '</div>';

		html += renderQueueActionStrip([
			latestOrderId ? { label: 'Show latest order', command: 'Show order #' + latestOrderNo + ' details' } : null,
			{ label: 'Customer service', command: 'Show customer service queue' },
			{ label: 'Latest 5 orders', command: 'Show latest 5 orders' }
		].filter(Boolean), h, 'What to do next');

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderSupportFlowPanel(result, h) {
		return '';
	}

	function renderOperationsSummaryResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const windowInfo = result.window || {};
		const recentOrders = Array.isArray(result.recent_orders) ? result.recent_orders : [];
		const windowStatusCounts = result.window_status_counts || {};
		const backlogCounts = result.backlog_counts || {};
		const attentionCounts = result.attention_counts || {};

		function labelize(key) {
			return String(key || '')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
		}

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Operations summary') + '</strong>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Store operations</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(windowInfo.start || '') + ' → ' + h.escapeHtml(windowInfo.end || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(result.focus || 'overview') + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>New orders</span><strong>' + h.escapeHtml(result.window_order_count || 0) + '</strong></div>';
		html += '<div><span>Open backlog</span><strong>' + h.escapeHtml(result.backlog_total || 0) + '</strong></div>';
		html += '<div><span>Needs attention</span><strong>' + h.escapeHtml(result.attention_total || 0) + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Orders in window</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
		Object.keys(windowStatusCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(windowStatusCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Current backlog</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<thead><tr><th>Queue</th><th>Count</th></tr></thead><tbody>';
		Object.keys(backlogCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(backlogCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Aged queues</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<thead><tr><th>Queue</th><th>Count</th></tr></thead><tbody>';
		Object.keys(attentionCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(attentionCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '</div>';

		if (recentOrders.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Recent open orders</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Email</th><th>Date</th><th>Actions</th></tr></thead><tbody>';

			recentOrders.forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.billing_email || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.date_created || '') + '</td>';
				html += '<td class="yoro-row-actions">';
				html += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h.escapeHtml(order.id) + '">Details</button> ';
				html += '<button type="button" class="button button-small yoro-row-action-menu" data-order-id="' + h.escapeHtml(order.id) + '">Actions</button> ';

				if (order.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}

				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

		html += '<div class="yoro-suggestions">';
		html += '<strong>Suggested next checks</strong>';
		html += '<div class="yoro-suggestion-list">';
		html += '<button class="button button-small yoro-suggestion-btn" data-command="Show pending orders">Pending orders</button>';
		html += '<button class="button button-small yoro-suggestion-btn" data-command="Show processing orders">Processing orders</button>';
		html += '<button class="button button-small yoro-suggestion-btn" data-command="Show on-hold orders">On-hold orders</button>';
		html += '</div>';
		html += '</div>';

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderBusinessSummaryResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const windowInfo = result.window || {};
		const metrics = result.business_metrics || {};
		const businessContext = result.business_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const statusCounts = result.order_status_counts || {};
		const topCustomers = Array.isArray(result.top_customers) ? result.top_customers : [];
		const topProducts = Array.isArray(result.top_products) ? result.top_products : [];
		const recentOrders = Array.isArray(result.recent_orders) ? result.recent_orders : [];
		const topPriority = priorityItems.length ? priorityItems[0] : null;
		const mainSignalLabel = humanizeBusinessTopic(businessContext.top_topic || 'overview');
		const startHere = buildBriefingStartHereFromSequence(recommendedSequence);
		const supportingPriorityItems = priorityItems.slice(1, 4);

		function labelize(key) {
			return String(key || '')
				.replace(/_/g, ' ')
				.replace(/\b\w/g, function(char) {
					return char.toUpperCase();
				});
		}

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>Business summary</strong>';
		html += '<span class="yoro-result-subtle">Read-only executive summary. No store data is changed.</span>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Business overview</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(windowInfo.start || '') + ' → ' + h.escapeHtml(windowInfo.end || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(result.focus || 'overview') + '</span>';
		html += '</div>';

		if (topPriority) {
			html += renderQueueWorkbenchHero(topPriority, [
				{ label: 'Orders', value: metrics.order_count || 0 },
				{ label: 'Gross sales', value: metrics.gross_sales || 0 },
				{ label: 'Customers', value: metrics.unique_customers || 0 },
				{ label: 'Attention', value: result.attention_total || 0 },
				{ label: 'Main signal', value: mainSignalLabel }
			], h);
		}

		if (startHere) {
			html += renderQueueStartHereCard(startHere, h);
		}
		html += renderWorklistModeCard(result, h);

		if (supportingPriorityItems.length) {
			html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		}

		html += '<details class="yoro-order-detail-section yoro-briefing-disclosure">';
		html += '<summary><strong>See detail view</strong><span>Open commercial detail, order mix, and top performers</span></summary>';
		html += '<div class="yoro-briefing-disclosure-body">';
		html += renderQueueMetaMetrics([
			{ label: 'Orders', value: metrics.order_count || 0 },
			{ label: 'Gross sales', value: metrics.gross_sales || 0 },
			{ label: 'AOV', value: metrics.average_order_value || 0 },
			{ label: 'Customers', value: metrics.unique_customers || 0 },
			{ label: 'Product lines', value: metrics.product_lines || 0 },
			{ label: 'Backlog', value: result.backlog_total || 0 }
		], h);

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Order mix</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<thead><tr><th>Status</th><th>Count</th></tr></thead><tbody>';
		Object.keys(statusCounts).forEach(function(key) {
			html += '<tr>';
			html += '<td>' + h.escapeHtml(labelize(key)) + '</td>';
			html += '<td>' + h.escapeHtml(statusCounts[key] || 0) + '</td>';
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Operating pressure</h4>';
		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Backlog</span><strong>' + h.escapeHtml(result.backlog_total || 0) + '</strong></div>';
		html += '<div><span>Attention items</span><strong>' + h.escapeHtml(result.attention_total || 0) + '</strong></div>';
		html += '<div><span>Completed sales</span><strong>' + h.escapeHtml(metrics.completed_sales || 0) + '</strong></div>';
		html += '</div>';
		html += '</div>';

		if (topCustomers.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Top customers</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Customer</th><th>Email</th><th>Orders</th><th>Total spent</th></tr></thead><tbody>';
			topCustomers.forEach(function(customer) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(customer.name || '—') + '</td>';
				html += '<td>' + h.escapeHtml(customer.email || '') + '</td>';
				html += '<td>' + h.escapeHtml(customer.order_count || 0) + '</td>';
				html += '<td>' + h.escapeHtml(customer.total_spent || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

		if (topProducts.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Top products</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Product</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
			topProducts.forEach(function(product) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(product.name || ('#' + product.id)) + '</td>';
				html += '<td>' + h.escapeHtml(product.quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(product.total || 0) + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

		if (recentOrders.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Recent orders</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Email</th><th>Date</th></tr></thead><tbody>';
			recentOrders.forEach(function(order) {
				html += '<tr>';
				html += '<td><strong>#' + h.escapeHtml(order.number || order.id) + '</strong></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(order.total || '') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.billing_email || '') + '</td>';
				html += '<td>' + h.escapeHtml(order.date_created || '') + '</td>';
				html += '</tr>';
			});
			html += '</tbody></table>';
			html += '</div>';
		}

		html += '</div>';
		html += '</details>';
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderMorningBriefingResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const yesterdaySnapshot = result.yesterday_snapshot || {};
		const livePressure = result.live_pressure || {};
		const topPriorities = Array.isArray(result.top_priorities) ? result.top_priorities : [];
		const firstAction = result.first_recommended_action || {};
		const priorityDrilldowns = buildMorningDrilldowns(result);
		const visiblePriorityItems = topPriorities.slice(0, 3);
		const queueOverview = buildMorningQueueOverview(result);
		const supportingPriorityItems = visiblePriorityItems.slice(1);
		const compactSnapshotParts = [
			(yesterdaySnapshot.orders || 0) + ' orders yesterday',
			String(yesterdaySnapshot.gross_sales || '0') + ' gross sales',
			(yesterdaySnapshot.customers || 0) + ' active customer' + ((Number(yesterdaySnapshot.customers || 0) === 1) ? '' : 's'),
			'Main pressure: ' + humanizeMorningPressure(livePressure.main_pressure || 'overview')
		];

		let html = '<div class="yoro-result-notice yoro-result-success">';
		html += '<p>' + h.escapeHtml(result.assistant_summary || result.headline || 'Here is your morning briefing.') + '</p>';

		if (result.assistant_followup) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(result.assistant_followup) + '</p>';
		}

		html += '</div>';

		html += '<div class="yoro-result-summary">';
		html += '<strong>Morning briefing</strong>';
		html += '<span class="yoro-result-subtle">Proactive morning brief. No store data is changed.</span>';
		html += '<span class="yoro-result-subtle">Proactive</span>';

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Morning briefing</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(result.time_window_label || 'Yesterday and this morning') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(result.briefing_date || '') + '</span>';
		html += '</div>';

		html += '<div class="yoro-briefing-hero">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml(result.headline || 'Morning briefing') + '</strong>';
		html += '<p>' + h.escapeHtml(yesterdaySnapshot.summary || '') + '</p>';
		html += '<p class="yoro-result-subtle">' + h.escapeHtml(compactSnapshotParts.join(' · ')) + '</p>';
		html += '</div>';
		html += '</div>';

		if (firstAction.command) {
			html += renderControlHomeStartHere(firstAction, result.dismissible, h);
		}
		html += renderWorklistModeCard(result, h);

		if (priorityDrilldowns.length) {
			html += renderControlHomeQueueOverview('Open one of these next', 'These drilldowns take you straight into the queues behind this morning briefing.', priorityDrilldowns, h);
		}

		if (supportingPriorityItems.length) {
			html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		}

		html += '<details class="yoro-order-detail-section yoro-briefing-disclosure">';
		html += '<summary><strong>See detail view</strong><span>Open queue counts and extra checks</span></summary>';
		html += '<div class="yoro-briefing-disclosure-body">';
		html += '<div class="yoro-briefing-metrics">';
		html += '<div><span>Yesterday orders</span><strong>' + h.escapeHtml(yesterdaySnapshot.orders || 0) + '</strong></div>';
		html += '<div><span>Gross sales</span><strong>' + h.escapeHtml(yesterdaySnapshot.gross_sales || '0') + '</strong></div>';
		html += '<div><span>Customers</span><strong>' + h.escapeHtml(yesterdaySnapshot.customers || 0) + '</strong></div>';
		html += '<div><span>Main pressure</span><strong>' + h.escapeHtml(humanizeMorningPressure(livePressure.main_pressure || 'overview')) + '</strong></div>';
		html += '<div><span>Attention</span><strong>' + h.escapeHtml(livePressure.attention_items || 0) + '</strong></div>';
		html += '<div><span>Fulfillment</span><strong>' + h.escapeHtml(livePressure.fulfillment_items || 0) + '</strong></div>';
		html += '<div><span>Customer follow-up</span><strong>' + h.escapeHtml(livePressure.customer_followup_items || 0) + '</strong></div>';
		html += '<div><span>Inventory</span><strong>' + h.escapeHtml(livePressure.inventory_items || livePressure.inventory_pressure_items || 0) + '</strong></div>';
		html += '</div>';

		if (queueOverview.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Queue overview</h4>';
			html += '<p class="yoro-result-subtle">Use this if you want the wider operating picture, not just the next drilldown.</p>';
			html += '<div class="yoro-briefing-sections-grid">';
			queueOverview.forEach(function(queue) {
				html += '<div class="yoro-briefing-section-card yoro-control-home-queue-card' + (queue.recommended ? ' is-recommended' : '') + '">';
				html += '<div class="yoro-briefing-section-head">';
				html += '<strong>' + h.escapeHtml(queue.label || 'Queue') + '</strong>';
				html += '<span>' + h.escapeHtml(queue.count || 0) + '</span>';
				html += '</div>';
				if (queue.summary) {
					html += '<p>' + h.escapeHtml(queue.summary) + '</p>';
				}
				html += '</div>';
			});
			html += '</div>';
			html += '</div>';
		}

		html += '</div>';
		html += '</details>';

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function buildMorningDrilldowns(result) {
		const items = Array.isArray(result && result.priority_drilldowns) ? result.priority_drilldowns : [];

		return items.slice(0, 3).map(function (item) {
			const count = parseInt(item && item.count ? item.count : 0, 10);

			return {
				key: item && item.topic ? item.topic : '',
				label: item && item.label ? item.label : 'Drilldown',
				count: Number.isNaN(count) ? 0 : count,
				summary: item && item.summary ? item.summary : '',
				command: item && item.command ? item.command : '',
				commandLabel: item && item.command_label ? item.command_label : (item && item.label ? item.label : 'Open queue'),
				recommended: !!(item && item.recommended)
			};
		}).filter(function (item) {
			return item.command;
		});
	}

	function humanizeMorningPressure(topic) {
		switch (String(topic || '')) {
			case 'attention':
			case 'operations_attention':
				return 'Operations attention';
			case 'fulfillment':
				return 'Fulfillment';
			case 'customer_service':
				return 'Customer follow-up';
			case 'inventory':
				return 'Inventory';
			case 'business':
				return 'Business';
			default:
				return 'Store overview';
		}
	}

	function buildMorningQueueOverview(result) {
		const livePressure = result && result.live_pressure ? result.live_pressure : {};
		const firstAction = result && result.first_recommended_action ? result.first_recommended_action : {};
		const queues = [
			{
				key: 'attention',
				label: 'Operations',
				count: parseInt(livePressure.attention_items || 0, 10),
				summary: 'Review orders that need operational attention first.',
				command: 'Show operations attention queue'
			},
			{
				key: 'fulfillment',
				label: 'Fulfillment',
				count: parseInt(livePressure.fulfillment_items || 0, 10),
				summary: 'Check shipping and processing pressure.',
				command: 'Show fulfillment queue'
			},
			{
				key: 'customer_service',
				label: 'Customer follow-up',
				count: parseInt(livePressure.customer_followup_items || 0, 10),
				summary: 'Review customer-facing follow-up work and drafts.',
				command: 'Show customer service queue'
			}
		];

		if ((livePressure.main_pressure || '') === 'inventory') {
			queues.push({
				key: 'inventory',
				label: 'Inventory',
				count: parseInt(livePressure.inventory_items || livePressure.inventory_pressure_items || 0, 10),
				summary: 'Inspect inventory pressure before it spreads into fulfillment.',
				command: 'Show inventory queue'
			});
		}

		if (firstAction.command) {
			queues.forEach(function (queue) {
				if (queue.command === firstAction.command) {
					queue.recommended = true;
				}
			});
		}

		return queues.filter(function (queue) {
			return queue.count > 0 || queue.recommended;
		});
	}

	function buildDailyQueueOverview(sections, priorityDrilldowns, briefingActions) {
		const drilldownMap = {};
		const actionMap = {};

		(priorityDrilldowns || []).forEach(function (item) {
			if (item && item.topic) {
				drilldownMap[String(item.topic)] = item;
			}
		});

		(briefingActions || []).forEach(function (item) {
			if (item && item.topic) {
				actionMap[String(item.topic)] = item;
			}
		});

		return (sections || []).map(function (section) {
			const key = String(section && section.key ? section.key : '');
			const drilldown = drilldownMap[key] || null;
			const action = actionMap[key] || null;

			return {
				key: key,
				label: section && section.label ? section.label : 'Queue',
				count: parseInt(section && section.count ? section.count : 0, 10),
				summary: section && section.summary ? section.summary : '',
				command: (drilldown && drilldown.command) || (action && action.command) || getDefaultQueueCommand(key),
				commandLabel: (drilldown && drilldown.label) || (action && action.label) || 'Open queue',
				recommended: !!drilldown
			};
		});
	}

	function getDefaultQueueCommand(key) {
		switch (String(key || '')) {
			case 'operations':
			case 'attention':
				return 'Show operations attention queue';
			case 'fulfillment':
				return 'Show fulfillment queue';
			case 'customer_service':
				return 'Show customer service queue';
			case 'inventory':
				return 'Show inventory queue';
			case 'business':
				return 'Show business summary for this week';
			default:
				return '';
		}
	}

	function renderControlHomeStartHere(firstAction, dismissible, h) {
		let html = '<div class="yoro-control-home-primary">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Start here</span>';
		html += '<strong>' + h.escapeHtml(firstAction.label || firstAction.command || 'Open the top queue') + '</strong>';
		if (firstAction.reason) {
			html += '<p>' + h.escapeHtml(firstAction.reason) + '</p>';
		}
		html += '</div>';
		html += '<div class="yoro-suggestion-list">';
		if (firstAction.command) {
			html += '<button class="button button-primary yoro-suggestion-btn" data-command="' + h.escapeHtml(firstAction.command) + '">' + h.escapeHtml(firstAction.label || firstAction.command) + '</button>';
		}
		html += '<button class="button button-small yoro-suggestion-btn" data-command="Continue with the first recommendation">Continue with the first recommendation</button>';
		if (dismissible) {
			html += '<button class="button button-small yoro-dismiss-morning-briefing">Dismiss for today</button>';
		}
		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderControlHomeQueueOverview(title, description, queues, h) {
		if (!Array.isArray(queues) || !queues.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title) + '</h4>';
		if (description) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(description) + '</p>';
		}
		html += '<div class="yoro-briefing-sections-grid">';

		queues.forEach(function (queue) {
			if (!queue) {
				return;
			}

			html += '<div class="yoro-briefing-section-card yoro-control-home-queue-card' + (queue.recommended ? ' is-recommended' : '') + '">';
			html += '<div class="yoro-briefing-section-head">';
			html += '<strong>' + h.escapeHtml(queue.label || 'Queue') + '</strong>';
			html += '<span>' + h.escapeHtml(queue.count || 0) + '</span>';
			html += '</div>';
			if (queue.summary) {
				html += '<p>' + h.escapeHtml(queue.summary) + '</p>';
			}
			if (queue.command) {
				html += '<div class="yoro-suggestion-list">';
				html += '<button class="button button-small yoro-suggestion-btn" data-command="' + h.escapeHtml(queue.command) + '">';
				html += h.escapeHtml(queue.commandLabel || 'Open queue');
				html += '</button>';
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderDailyBriefingResult(data, h) {
		const result = data.result || {};
		const windowInfo = result.window || {};
		const briefingLabel = result.briefing_label || 'Daily briefing';
		const sections = Array.isArray(result.sections) ? result.sections : [];
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const priorityDrilldowns = Array.isArray(result.priority_drilldowns) ? result.priority_drilldowns : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const briefingContext = result.briefing_context || {};
		const topTopic = briefingContext.top_topic || 'overview';
		const state = briefingContext.state || 'steady_watch';
		const windowOrders = briefingContext.window_orders || 0;
		const grossSales = briefingContext.gross_sales || 0;
		const activeCustomers = briefingContext.active_customers || 0;
		const topPriority = priorityItems.length ? priorityItems[0] : null;
		const secondaryPriority = priorityItems.length > 1 ? priorityItems[1] : null;
		const topTopicLabel = String(topTopic || 'overview').replace(/_/g, ' ');
		const queueOverview = buildDailyQueueOverview(sections, priorityDrilldowns, []);
		const startHere = buildBriefingStartHereFromSequence(recommendedSequence);
		const supportingPriorityItems = priorityItems.slice(1, 4);

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-briefing-shell yoro-briefing-state-' + h.escapeHtml(state) + '">';
		html += '<div class="yoro-result-summary yoro-briefing-summary">';
		html += '<strong>' + h.escapeHtml(briefingLabel) + '</strong>';
		html += '<span class="yoro-result-subtle">Executive snapshot across the store\'s main operating queues.</span>';
		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}
		html += '</div>';

		html += '<div class="yoro-order-detail-card yoro-briefing-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(briefingLabel) + '</strong>';
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(windowInfo.start || '') + ' → ' + h.escapeHtml(windowInfo.end || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(topTopic.replace(/_/g, ' ')) + '</span>';
		html += '</div>';

		html += '<div class="yoro-briefing-hero">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml((topPriority && topPriority.title) || 'Store snapshot') + '</strong>';
		if (secondaryPriority) {
			html += '<p>' + h.escapeHtml(getBriefingSecondaryLine(secondaryPriority)) + '</p>';
		} else {
			html += '<p>' + h.escapeHtml('The main operating queues are summarized below.') + '</p>';
		}
		html += '</div>';
		html += '<div class="yoro-briefing-metrics">';
		html += '<div><span>New orders</span><strong>' + h.escapeHtml(windowOrders) + '</strong></div>';
		html += '<div><span>Gross sales</span><strong>' + h.escapeHtml(grossSales) + '</strong></div>';
		html += '<div><span>Active customers</span><strong>' + h.escapeHtml(activeCustomers) + '</strong></div>';
		html += '<div><span>Main pressure</span><strong>' + h.escapeHtml(topTopicLabel) + '</strong></div>';
		html += '<div><span>Queues tracked</span><strong>' + h.escapeHtml(sections.length) + '</strong></div>';
		html += '</div>';
		html += '</div>';

		if (startHere) {
			html += renderQueueStartHereCard(startHere, h);
		}
		html += renderWorklistModeCard(result, h);

		if (queueOverview.length) {
			html += renderControlHomeQueueOverview(
				'Open one of these next',
				'Jump directly into the work surface that matches today\'s pressure.',
				queueOverview,
				h
			);
		}

		if (supportingPriorityItems.length) {
			html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		}

		html += '</div>';
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

function getBriefingSecondaryLine(priorityItem) {
		const topic = priorityItem && priorityItem.topic ? String(priorityItem.topic) : '';

		switch (topic) {
			case 'fulfillment':
				return 'Secondary pressure is building in fulfillment and still needs active review.';
			case 'customer_service':
				return 'Customer follow-up is the next pressure point worth reviewing after the main queue.';
			case 'inventory':
				return 'Inventory risk is present in the background and should stay on the watchlist.';
			case 'business':
				return 'Commercial activity is worth a quick read alongside the operating queues.';
			case 'operations_attention':
				return 'Another operational queue is also showing meaningful pressure right now.';
			default:
				if (priorityItem && priorityItem.message) {
					return String(priorityItem.message);
				}

				return 'The main operating queues are summarized below.';
	}
}

function buildBriefingStartHereFromSequence(sequence) {
	if (!Array.isArray(sequence) || !sequence.length) {
		return null;
	}

	const first = sequence[0];

	if (!first || !first.command) {
		return null;
	}

	return {
		title: first.label || first.command || 'Recommended next step',
		meta: first.kind && first.kind !== 'view' ? first.kind : '',
		message: first.reason || '',
		reason: '',
		actions: [
			{
				label: first.label || first.command,
				command: first.command
			}
		]
	};
}

function renderQueueWorkbenchIntro(config, h) {
		let html = '';
		const cardClassName = config && config.cardClassName ? String(config.cardClassName) : '';

		if (config.showAssistantIntro) {
			html += renderAssistantIntro(config.result || {}, h);
		}

		if (config.showBoundary) {
			html += renderAdminCopilotBoundaryCard(config.result || {}, h);
		}

		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(config.summary || 'Queue') + '</strong>';
		if (config.subtle) {
			html += '<span class="yoro-result-subtle">' + h.escapeHtml(config.subtle) + '</span>';
		}
		if (config.parserRule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(config.parserRule) + '</span>';
		}
		html += '</div>';

		html += '<div class="yoro-order-detail-card yoro-queue-workbench-card' + (cardClassName ? ' ' + h.escapeHtml(cardClassName) : '') + '">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">' + h.escapeHtml(config.title || 'Queue') + '</strong>';
		if (config.contextLine) {
			html += '<div class="yoro-result-subtle">' + h.escapeHtml(config.contextLine) + '</div>';
		}
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(config.badge || '') + '</span>';
		html += '</div>';

		return html;
	}

	function renderQueueWorkbenchHero(topPriority, metrics, h, options) {
		if (!topPriority) {
			return '';
		}

		const config = options && typeof options === 'object' ? options : {};
		const heroClassName = config.className ? String(config.className) : '';
		let html = '<div class="yoro-briefing-hero' + (heroClassName ? ' ' + h.escapeHtml(heroClassName) : '') + '">';
		html += '<div class="yoro-briefing-hero-copy">';
		html += '<strong>' + h.escapeHtml(topPriority.title || 'Queue snapshot') + '</strong>';
		html += '<p>' + h.escapeHtml(topPriority.message || '') + '</p>';
		html += '</div>';
		html += '<div class="yoro-briefing-metrics">';

		(metrics || []).forEach(function (metric) {
			if (!metric) {
				return;
			}

			html += '<div><span>' + h.escapeHtml(metric.label || '') + '</span><strong>' + h.escapeHtml(metric.value || 0) + '</strong></div>';
		});

		html += '</div>';
		html += '</div>';

		return html;
	}

	function renderQueueRecommendedSequence(items, h, title, options) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		const config = options && typeof options === 'object' ? options : {};
		const showActions = config.showActions !== false;
		const limit = typeof config.limit === 'number' ? config.limit : 4;

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title || 'Recommended sequence') + '</h4>';
		html += '<div class="yoro-briefing-sections-grid">';

		items.slice(0, limit).forEach(function (item, index) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-briefing-section-card">';
			html += '<div class="yoro-briefing-section-head">';
			html += '<strong>' + h.escapeHtml((index + 1) + '. ' + (item.label || item.command || 'Step')) + '</strong>';
			html += '<span>' + h.escapeHtml(item.kind || 'step') + '</span>';
			html += '</div>';
			html += '<p>' + h.escapeHtml(item.reason || '') + '</p>';
			if (showActions && item.command) {
				html += '<div class="yoro-suggestion-list">';
				html += '<button class="button button-small yoro-suggestion-btn" data-command="' + h.escapeHtml(item.command) + '">';
				html += h.escapeHtml(item.label || item.command);
				html += '</button>';
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueActionStrip(items, h, title) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-suggestions yoro-briefing-suggestions">';
		html += '<strong>' + h.escapeHtml(title || 'Next actions') + '</strong>';
		html += '<div class="yoro-suggestion-list">';

		items.slice(0, 4).forEach(function (item) {
			if (!item || !item.command) {
				return;
			}

			html += '<button class="button button-small yoro-suggestion-btn" data-command="' + h.escapeHtml(item.command) + '">';
			html += h.escapeHtml(item.label || item.command);
			html += '</button>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueStartHereCard(item, h, options) {
		if (!item || !Array.isArray(item.actions) || !item.actions.length) {
			return '';
		}

	const config = options && typeof options === 'object' ? options : {};
	const hiddenCommands = Array.isArray(config.hideCommands) ? config.hideCommands : [];
	const visibleActions = item.actions.filter(function (action) {
		if (!action) {
			return false;
		}
		if (action.command) {
			return hiddenCommands.indexOf(String(action.command)) === -1;
		}
		return !!action.href;
	});

		if (!visibleActions.length) {
			return '';
		}

		let html = '<div class="yoro-control-home-primary">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Start here</span>';
		html += '<strong>' + h.escapeHtml(item.title || 'Recommended next case') + '</strong>';
		if (item.meta) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(item.meta) + '</p>';
		}
		if (item.message) {
			html += '<p>' + h.escapeHtml(item.message) + '</p>';
		}
		if (item.reason) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(item.reason) + '</p>';
		}
		html += '</div>';
	html += '<div class="yoro-suggestion-list">';
	visibleActions.slice(0, 3).forEach(function (action, index) {
		if (!action || (!action.command && !action.href)) {
			return;
		}
		if (action.href) {
			html += '<a class="button ' + (index === 0 ? 'button-primary' : 'button-small') + '" href="' + h.escapeHtml(action.href) + '" target="_blank" rel="noopener noreferrer">';
			html += h.escapeHtml(action.label || action.href);
			html += '</a>';
		} else {
			html += '<button class="button ' + (index === 0 ? 'button-primary' : 'button-small') + ' yoro-suggestion-btn" data-command="' + h.escapeHtml(action.command) + '">';
			html += h.escapeHtml(action.label || action.command);
			html += '</button>';
		}
	});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderWorklistModeCard(result, h) {
		const worklist = buildWorklistState(result, h);

		if (!worklist) {
			return '';
		}

		let html = '<div class="yoro-control-home-primary yoro-worklist-mode-card">';
		html += '<div class="yoro-control-home-primary-copy">';
		html += '<span class="yoro-control-home-kicker">Worklist mode</span>';
		html += '<strong>' + h.escapeHtml(worklist.title) + '</strong>';
		html += '<p>' + h.escapeHtml(worklist.message) + '</p>';
		html += '<p class="yoro-result-subtle">' + h.escapeHtml(worklist.detail) + '</p>';
		html += '</div>';
		html += '<div class="yoro-suggestion-list">';
		worklist.actions.forEach(function (action, index) {
			if (!action || !action.command) {
				return;
			}
			html += '<button class="button ' + (index === 0 ? 'button-primary' : 'button-small') + ' yoro-suggestion-btn" data-command="' + h.escapeHtml(action.command) + '">';
			html += h.escapeHtml(action.label || action.command);
			html += '</button>';
		});
		html += '</div>';
		html += '</div>';
		return html;
	}

	function buildWorklistState(result, h) {
		const sequence = Array.isArray(result && result.recommended_sequence) ? result.recommended_sequence : [];

		if (!sequence.length) {
			return null;
		}

		const state = typeof h.getConversationState === 'function' ? (h.getConversationState() || {}) : {};
		const followup = getRelevantFollowupState(String(result && result.result_type ? result.result_type : ''), state);
		const cursor = followup && typeof followup.recommendation_cursor === 'number'
			? followup.recommendation_cursor
			: -1;
		const normalizedCursor = cursor >= 0 ? cursor : -1;

		if (normalizedCursor < 0) {
			return null;
		}

		const refreshCommand = getWorklistRefreshCommand(result && result.result_type ? result.result_type : '');
		const actions = [
			{
				label: 'Continue plan',
				command: 'Continue the plan'
			}
		];

		if (sequence.length > 1) {
			actions.push({
				label: 'Next step',
				command: 'Do the next recommendation'
			});
		}

		if (refreshCommand) {
			actions.push({
				label: 'Refresh queue',
				command: refreshCommand
			});
		}

		return {
			title: 'Continue from where YoRo left off',
			message: 'YoRo can keep advancing the current follow-up path without making you re-open each queue manually.',
			detail: 'Step ' + (Math.max(0, normalizedCursor) + 1) + ' of ' + sequence.length + ' in the current recommendation path.',
			actions: actions
		};
	}

	function getRelevantFollowupState(resultType, state) {
		switch (String(resultType || '')) {
			case 'morning_briefing':
			case 'daily_briefing':
				return state.last_briefing || state.last_result || {};
			case 'business_summary':
				return state.last_business_followup || state.last_result || {};
			case 'operations_attention_queue':
				return state.last_attention_followup || state.last_result || {};
			case 'customer_service_queue':
				return state.last_service_followup || state.last_result || {};
			case 'fulfillment_queue':
				return state.last_fulfillment_followup || state.last_result || {};
			case 'inventory_queue':
				return state.last_inventory_followup || state.last_result || {};
			default:
				return state.last_result || {};
		}
	}

	function getWorklistRefreshCommand(resultType) {
		switch (String(resultType || '')) {
			case 'morning_briefing':
			case 'daily_briefing':
				return 'Show daily briefing for today';
			case 'business_summary':
				return 'Show business summary for this week';
			case 'operations_attention_queue':
				return 'Show operations attention queue';
			case 'customer_service_queue':
				return 'Show customer service queue';
			case 'fulfillment_queue':
				return 'Show fulfillment queue';
			case 'inventory_queue':
				return 'Show inventory queue';
			default:
				return '';
		}
	}

	function renderQueuePriorityCards(items, h, title) {
		if (!Array.isArray(items) || !items.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml(title || 'Top priorities') + '</h4>';
		html += '<div class="yoro-insight-list">';

		items.forEach(function (item) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-insight-card yoro-insight-card-' + h.escapeHtml(item.tone || 'neutral') + '">';
			html += '<strong>' + h.escapeHtml(item.title || 'Priority') + '</strong>';
			html += '<p>' + h.escapeHtml(item.message || '') + '</p>';
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderQueueMetaMetrics(metrics, h) {
		if (!Array.isArray(metrics) || !metrics.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-metrics">';

		metrics.forEach(function (metric) {
			if (!metric) {
				return;
			}

			html += '<div><span>' + h.escapeHtml(metric.label || '') + '</span><strong>' + h.escapeHtml(metric.value || 0) + '</strong></div>';
		});

		html += '</div>';
		return html;
	}

	function renderGuidedCaseDeck(config, h) {
		const cases = Array.isArray(config && config.items) ? config.items : [];

		if (!cases.length) {
			return '';
		}

		let html = '<div class="yoro-order-detail-section">';
		html += '<h4>' + h.escapeHtml((config && config.title) || 'Guided cases') + '</h4>';
		if (config && config.description) {
			html += '<p class="yoro-result-subtle">' + h.escapeHtml(config.description) + '</p>';
		}
		html += '<div class="yoro-guided-case-grid">';

		cases.slice(0, 3).forEach(function (item) {
			if (!item) {
				return;
			}

			html += '<div class="yoro-guided-case-card">';
			html += '<div class="yoro-guided-case-head">';
			html += '<div>';
			html += '<strong>' + h.escapeHtml(item.title || 'Case') + '</strong>';
			if (item.meta) {
				html += '<div class="yoro-result-subtle">' + h.escapeHtml(item.meta) + '</div>';
			}
			html += '</div>';
			if (item.badge) {
				html += '<span class="yoro-guided-case-badge">' + h.escapeHtml(item.badge) + '</span>';
			}
			html += '</div>';
			if (item.message) {
				html += '<p>' + h.escapeHtml(item.message) + '</p>';
			}
			if (Array.isArray(item.actions) && item.actions.length) {
				html += '<div class="yoro-suggestion-list">';
				item.actions.slice(0, 3).forEach(function (action) {
					if (!action || (!action.command && !action.href)) {
						return;
					}
					if (action.href) {
						html += '<a class="button button-small" href="' + h.escapeHtml(action.href) + '" target="_blank" rel="noopener noreferrer">';
						html += h.escapeHtml(action.label || action.href);
						html += '</a>';
					} else {
						html += '<button class="button button-small yoro-suggestion-btn" data-command="' + h.escapeHtml(action.command) + '">';
						html += h.escapeHtml(action.label || action.command);
						html += '</button>';
					}
				});
				html += '</div>';
			}
			html += '</div>';
		});

		html += '</div>';
		html += '</div>';
		return html;
	}

	function renderBatchDraftPreparation(config, h) {
		const items = Array.isArray(config && config.items) ? config.items : [];

		if (!items.length) {
			return '';
		}

		return renderGuidedCaseDeck({
			title: (config && config.title) || 'Batch draft preparation',
			description: (config && config.description) || 'Review these candidate drafts next. Nothing is sent automatically.',
			items: items
		}, h);
	}

	function renderOrderQueueSections(queues, tableConfig, h) {
		let html = '';
		const config = tableConfig && typeof tableConfig === 'object' ? tableConfig : {};
		const maxRows = typeof config.maxRows === 'number' ? config.maxRows : 0;

		(queues || []).forEach(function (queue) {
			const orders = Array.isArray(queue.orders) ? queue.orders : [];
			const visibleOrders = maxRows > 0 ? orders.slice(0, maxRows) : orders;
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!orders.length) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(tableConfig.emptyMessage || 'No matching orders in this queue.') + '</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr>';
			(config.columns || []).forEach(function (column) {
				html += '<th>' + h.escapeHtml(column.label || '') + '</th>';
			});
			html += '</tr></thead><tbody>';

			visibleOrders.forEach(function (order) {
				html += config.renderRow(order, queue, h);
			});

			html += '</tbody></table>';
			if (maxRows > 0 && orders.length > visibleOrders.length) {
				html += '<p class="yoro-result-subtle">Showing the top ' + h.escapeHtml(visibleOrders.length) + ' cases in this queue.</p>';
			}
			html += '</div>';
		});

		return html;
	}

	function renderProductQueueSections(queues, h) {
		let html = '';

		(queues || []).forEach(function (queue) {
			const products = Array.isArray(queue.products) ? queue.products : [];
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!products.length) {
				html += '<p class="yoro-result-subtle">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Product</th><th>SKU</th><th>Stock</th><th>Status</th><th>Backorders</th><th>Actions</th></tr></thead><tbody>';

			products.forEach(function (product) {
				const stock = product.stock_quantity === '' || product.stock_quantity === null
					? '—'
					: product.stock_quantity;

				html += '<tr>';
				html += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong></td>';
				html += '<td>' + h.escapeHtml(product.sku || '—') + '</td>';
				html += '<td>' + h.escapeHtml(stock) + ' <span class="yoro-result-subtle">/ low ' + h.escapeHtml(product.low_stock_threshold || 0) + '</span></td>';
				html += '<td><span class="yoro-status-badge">' + h.escapeHtml(product.stock_status || '') + '</span></td>';
				html += '<td>' + h.escapeHtml(product.backorders_allowed ? 'Allowed' : 'No') + '</td>';
				html += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open</a>';
				}
				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		});

		return html;
	}

	function renderInventoryProductQueueSections(queues, h, options) {
		let html = '';
		const config = options && typeof options === 'object' ? options : {};
		const maxRows = typeof config.maxRows === 'number' ? config.maxRows : 0;
		const stockManagementEnabled = !!config.stockManagementEnabled;
		const inventoryMode = String(config.inventoryMode || '');

		(queues || []).forEach(function (queue) {
			const products = Array.isArray(queue.products) ? queue.products : [];
			const visibleProducts = maxRows > 0 ? products.slice(0, maxRows) : products;
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(queue.label || queue.key || 'Queue') + ' <span class="yoro-result-subtle">(' + h.escapeHtml(queue.count || 0) + ')</span></h4>';

			if (queue.description) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(queue.description) + '</p>';
			}

			if (!products.length) {
				html += '<p class="yoro-result-subtle">No matching products in this queue.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Product</th><th>Case</th><th>Stock</th><th>Next action</th></tr></thead><tbody>';

			visibleProducts.forEach(function (product) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const productMeta = [product.sku || '', product.stock_status || ''].filter(Boolean).join(' · ');
				const caseTitle = [
					humanizeInventoryTopic(queueKey || ''),
					product.stock_status || ''
				].filter(Boolean).join(' · ');

				html += '<tr>';
				html += '<td><strong>' + h.escapeHtml(product.name || ('#' + product.id)) + '</strong>';
				if (productMeta) {
					html += '<div class="yoro-result-subtle">' + h.escapeHtml(productMeta) + '</div>';
				}
				html += '</td>';
				html += '<td><strong>' + h.escapeHtml(caseTitle || 'Inventory case') + '</strong><div class="yoro-result-subtle">' + h.escapeHtml(buildInventoryRowCaseMessage(queueKey, queue.description || '', product)) + '</div></td>';
				html += '<td>' + h.escapeHtml(formatInventoryRowStock(product, stockManagementEnabled, inventoryMode)) + '</td>';
				html += '<td class="yoro-row-actions">';
				if (product.edit_url) {
					html += '<a class="button button-small" href="' + h.escapeHtml(product.edit_url) + '" target="_blank" rel="noopener noreferrer">Open product</a>';
				} else {
					html += '<button type="button" class="button button-small yoro-suggestion-btn" data-command="Prepare next inventory review">Prepare review</button>';
				}
				html += '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			if (maxRows > 0 && products.length > visibleProducts.length) {
				html += '<p class="yoro-result-subtle">Showing the top ' + h.escapeHtml(visibleProducts.length) + ' products in this queue.</p>';
			}
			html += '</div>';
		});

		return html;
	}

	function renderOperationsAttentionQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const attentionContext = result.attention_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildOperationsAttentionHeroItem(attentionContext, priorityItems, result);
		const supportingPriorityItems = filterOperationsAttentionSupportingPriorities(priorityItems, attentionContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
			showAssistantIntro: true,
			showBoundary: true,
			summary: result.summary || 'Operations attention queue',
			subtle: 'Read-only operations queue. No fraud scoring applied.',
			parserRule: parsed.parser_rule,
			title: 'Operations attention cases',
			contextLine: 'Showing up to ' + (result.limit_per_queue || 5) + ' cases per queue.',
			badge: (result.count || 0) + ' items'
		}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Attention items', value: attentionContext.total_items || result.count || 0 },
			{ label: 'Pending payment', value: attentionContext.pending_count || 0 },
			{ label: 'Aging processing', value: attentionContext.processing_count || 0 },
			{ label: 'High-value open', value: attentionContext.high_value_count || 0 }
		], h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show operations attention queue']
		});
		html += renderBatchDraftPreparation({
			title: 'Review these queue cases next',
			description: 'Use this shortlist when you want to move through several operations checks in one pass.',
			items: batchDraftCandidates
		}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'High-value threshold', value: result.high_value_threshold || 0 }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const customerMeta = order.customer_name || order.billing_email || '';
				const caseMeta = [
					humanizeAttentionTopic(queueKey || ''),
					order.status || '',
					order.total ? ((order.total || '') + ' ' + (order.currency || '')) : ''
				].filter(Boolean).join(' · ');
				const primaryCommand = getOperationsAttentionRowCommand(queueKey, order);
				const primaryLabel = getOperationsAttentionRowActionLabel(queueKey);
				const detailsCommand = 'Show order ' + order.id + ' details';
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta || (order.status || 'Case')) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(customerMeta || queue.description || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(order.age_hours || 0) + 'h</td>';
				row += '<td class="yoro-row-actions">';
				row += '<button type="button" class="button button-small yoro-suggestion-btn" data-command="' + h2.escapeHtml(primaryCommand) + '">' + h2.escapeHtml(primaryLabel) + '</button> ';
				if (primaryCommand !== detailsCommand) {
					row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				}
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show operations attention queue' },
			{ label: 'Operations summary', command: 'Show operations summary for today' },
			{ label: 'Customer service', command: 'Show customer service queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderFulfillmentQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const fulfillmentContext = result.fulfillment_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildFulfillmentHeroItem(fulfillmentContext, priorityItems, result);
		const supportingPriorityItems = filterFulfillmentSupportingPriorities(priorityItems, fulfillmentContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
			showAssistantIntro: true,
			showBoundary: true,
			summary: result.summary || 'Fulfillment queue',
			subtle: 'Read-only queue. No tracking, status, or email changes are made.',
			parserRule: parsed.parser_rule,
			title: 'Fulfillment candidates',
			contextLine: 'Showing up to ' + (result.limit_per_queue || 5) + ' cases per queue.',
			badge: (result.count || 0) + ' fulfillment items'
		}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Queue items', value: fulfillmentContext.total_items || result.count || 0 },
			{ label: 'Missing tracking', value: fulfillmentContext.tracking_count || 0 },
			{ label: 'Aging orders', value: fulfillmentContext.aging_count || 0 },
			{ label: 'Tracked ready', value: fulfillmentContext.ready_count || 0 }
		], h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show fulfillment queue']
		});
		html += renderBatchDraftPreparation({
			title: 'Review these fulfillment cases next',
			description: 'Use this shortlist when you want to move through several shipping follow-ups in one pass.',
			items: batchDraftCandidates
		}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Store changes', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const shipTo = [order.shipping_city || '', order.shipping_country || ''].filter(Boolean).join(', ');
				const queueKey = String(queue && queue.key ? queue.key : '');
				const primaryCommand = order.suggested_command || ('Preview shipping delay email for order ' + order.id);
				const primaryLabel = getFulfillmentRowActionLabel(queueKey);
				const caseMeta = [
					humanizeFulfillmentTopic(queueKey || ''),
					order.shipping_method || '',
					shipTo || ''
				].filter(Boolean).join(' · ');
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta || (order.status || 'Case')) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(order.fulfillment_reason || queue.description || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(order.age_hours || 0) + 'h</td>';
				row += '<td class="yoro-row-actions">';
				row += '<button type="button" class="button button-small yoro-suggestion-btn" data-command="' + h2.escapeHtml(primaryCommand) + '">' + h2.escapeHtml(primaryLabel) + '</button> ';
				row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show fulfillment queue' },
			{ label: 'Processing orders', command: 'Show processing orders' },
			{ label: 'Customer service', command: 'Show customer service queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderInventoryQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const inventoryContext = result.inventory_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildInventoryHeroItem(inventoryContext, priorityItems, result);
		const supportingPriorityItems = filterInventorySupportingPriorities(priorityItems, inventoryContext);
		const stockManagementEnabled = !!inventoryContext.stock_management_enabled;
		const inventoryMode = String(inventoryContext.inventory_mode || result.inventory_mode || '');
		const inventorySubtle = stockManagementEnabled
			? 'Read-only queue. No stock or product changes are made.'
			: 'Status-only inventory view. Stock quantity tracking is off, so quantity-based queues are hidden.';
		const heroMetrics = [
			{ label: 'Queue items', value: inventoryContext.total_items || result.count || 0 },
			{ label: 'Out of stock', value: inventoryContext.out_of_stock_count || 0 },
			{ label: 'Backorders', value: inventoryContext.backorder_count || 0 },
			{ label: stockManagementEnabled ? 'Low stock' : 'Mode', value: stockManagementEnabled ? (inventoryContext.low_stock_count || 0) : 'Status only' }
		];

		let html = renderQueueWorkbenchIntro({
			result: result,
			showAssistantIntro: true,
			showBoundary: false,
			summary: result.summary || 'Inventory queue',
			subtle: inventorySubtle,
			parserRule: parsed.parser_rule,
			title: 'Inventory attention',
			contextLine: 'Showing up to ' + (result.limit_per_queue || 5) + ' products per queue.' + (inventoryMode === 'status_only' ? ' Status-only mode.' : ''),
			badge: (result.count || 0) + ' items'
		}, h);
		html += renderQueueWorkbenchHero(topPriority, heroMetrics, h);
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show inventory queue']
		});
		html += renderBatchDraftPreparation({
			title: stockManagementEnabled ? 'Review these inventory items next' : 'Review these stock-status items next',
			description: stockManagementEnabled
				? 'Use this shortlist when you want to move through several inventory risks in one pass.'
				: 'Use this shortlist when you want to move through the clearest stock-status exceptions first.',
			items: batchDraftCandidates
		}, h);
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Stock tracking', value: stockManagementEnabled ? 'Enabled' : 'Off' }
		], h);
		html += renderInventoryProductQueueSections(queues, h, {
			maxRows: 3,
			stockManagementEnabled: stockManagementEnabled,
			inventoryMode: inventoryMode
		});
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show inventory queue' },
			{ label: stockManagementEnabled ? 'Fulfillment' : 'Store summary', command: stockManagementEnabled ? 'Show fulfillment queue' : 'Show business summary for this week' },
			{ label: 'Operations', command: 'Show operations attention queue' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function renderStoreInsightsResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const windowInfo = result.window || {};
		const insights = Array.isArray(result.insights) ? result.insights : [];
		const ordersSection = insights.find(function(section) { return section && section.key === 'orders'; }) || {};
		const customersSection = insights.find(function(section) { return section && section.key === 'customers'; }) || {};
		const productsSection = insights.find(function(section) { return section && section.key === 'products'; }) || {};
		const productMetrics = productsSection.metrics || {};
		const inventoryRiskCount = (parseInt(productMetrics.out_of_stock || 0, 10) || 0)
			+ (parseInt(productMetrics.low_stock || 0, 10) || 0)
			+ (parseInt(productMetrics.on_backorder || 0, 10) || 0);
		const topMetrics = [
			{
				label: 'Orders',
				value: ((ordersSection.metrics || {}).order_count || 0) + '',
				subtle: windowInfo.label || ''
			},
			{
				label: 'Gross sales',
				value: ((ordersSection.metrics || {}).gross_sales || '0') + ' ' + ((result.meta || {}).currency || ''),
				subtle: 'Across this window'
			},
			{
				label: 'Customers',
				value: ((customersSection.metrics || {}).unique_customers || 0) + '',
				subtle: 'Unique buyers'
			},
			{
				label: 'Inventory risk',
				value: inventoryRiskCount + '',
				subtle: 'Out of stock, low stock, and backorders'
			}
		];

		let html = renderAssistantIntro(result, h);
		html += '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Grouped store snapshot') + '</strong>';
		html += '<span class="yoro-result-subtle">Use this grouped snapshot when you want a deeper read across orders, customers, and products.</span>';

		if (parsed.parser_rule) {
			html += '<span class="yoro-result-subtle">Parser: ' + h.escapeHtml(parsed.parser_rule) + '</span>';
		}

		if (windowInfo.label) {
			html += '<span class="yoro-result-subtle">Window: ' + h.escapeHtml(windowInfo.label) + '</span>';
		}

		html += '</div>';

		html += '<div class="yoro-order-detail-card">';
		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Grouped store snapshot</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(windowInfo.start || '') + ' → ' + h.escapeHtml(windowInfo.end || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-current-order-badge">' + h.escapeHtml(windowInfo.label || (insights.length + ' groups')) + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics yoro-order-detail-metrics-compact">';
		topMetrics.forEach(function(metric) {
			html += '<div>';
			html += '<span>' + h.escapeHtml(metric.label || '') + '</span>';
			html += '<strong>' + h.escapeHtml(metric.value || '') + '</strong>';
			if (metric.subtle) {
				html += '<small class="yoro-result-subtle">' + h.escapeHtml(metric.subtle) + '</small>';
			}
			html += '</div>';
		});
		html += '</div>';

		insights.forEach(function(section) {
			const metrics = section.metrics || {};
			const items = Array.isArray(section.items) ? section.items : [];
			const sectionMeta = getStoreInsightsSectionMeta(section.key);

			html += '<div class="yoro-order-detail-section">';
			html += '<h4>' + h.escapeHtml(sectionMeta.title || section.label || section.key || 'Insights') + '</h4>';
			if (sectionMeta.summary) {
				html += '<p class="yoro-result-subtle">' + h.escapeHtml(sectionMeta.summary) + '</p>';
			}

			html += '<div class="yoro-order-detail-metrics">';
			Object.keys(metrics).forEach(function(key) {
				html += '<div><span>' + h.escapeHtml(humanizeStoreInsightsMetricLabel(key)) + '</span><strong>' + h.escapeHtml(metrics[key]) + '</strong></div>';
			});
			html += '</div>';

			if (!items.length) {
				html += '<p class="yoro-result-subtle">Nothing notable in this group for the selected window.</p>';
				html += '</div>';
				return;
			}

			html += '<table class="yoro-orders-table">';

			if (section.key === 'customers') {
				html += '<thead><tr><th>Customer</th><th>Email</th><th>Orders</th><th>Total spent</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.name || '—') + '</td>';
					html += '<td>' + h.escapeHtml(item.email || '') + '</td>';
					html += '<td>' + h.escapeHtml(item.order_count || 0) + '</td>';
					html += '<td>' + h.escapeHtml(item.total_spent || 0) + '</td>';
					html += '</tr>';
				});
			} else if (section.key === 'products') {
				html += '<thead><tr><th>Product</th><th>SKU</th><th>Qty</th><th>Total</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.name || ('#' + item.id)) + '</td>';
					html += '<td>' + h.escapeHtml(item.sku || '—') + '</td>';
					html += '<td>' + h.escapeHtml(item.quantity || 0) + '</td>';
					html += '<td>' + h.escapeHtml(item.total || 0) + '</td>';
					html += '</tr>';
				});
			} else {
				html += '<thead><tr><th>Item</th><th>Count</th></tr></thead><tbody>';
				items.forEach(function(item) {
					html += '<tr>';
					html += '<td>' + h.escapeHtml(item.label || item.key || '') + '</td>';
					html += '<td>' + h.escapeHtml(item.count || 0) + '</td>';
					html += '</tr>';
				});
			}

			html += '</tbody></table>';
			html += '</div>';
		});

		html += renderQueueActionStrip([
			{ label: 'Business summary', command: 'Show business summary for this week' },
			{ label: 'Operations', command: 'Show operations summary for today' },
			{ label: 'Inventory', command: 'Show inventory queue' }
		], h, 'Other views');

		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function getStoreInsightsSectionMeta(key) {
		switch (String(key || '')) {
			case 'orders':
				return {
					title: 'Order mix',
					summary: 'Use this group to see how order volume and status mix are behaving in the selected window.'
				};
			case 'customers':
				return {
					title: 'Top customers',
					summary: 'This group highlights who spent the most and how concentrated customer value looks right now.'
				};
			case 'products':
				return {
					title: 'Product demand and inventory pressure',
					summary: 'This group combines top-selling items with the current stock signals that may affect fulfillment.'
				};
			default:
				return {
					title: 'Insights group',
					summary: ''
				};
		}
	}

	function humanizeStoreInsightsMetricLabel(key) {
		switch (String(key || '')) {
			case 'order_count':
				return 'Orders';
			case 'gross_sales':
				return 'Gross sales';
			case 'average_order_value':
				return 'Average order value';
			case 'unique_customers':
				return 'Unique customers';
			case 'out_of_stock':
				return 'Out of stock';
			case 'low_stock':
				return 'Low stock';
			case 'on_backorder':
				return 'On backorder';
			default:
				return String(key || '').replace(/_/g, ' ');
		}
	}

	function renderCustomerServiceQueueResult(data, h) {
		const result = data.result || {};
		const parsed = data.parsed || {};
		const queues = Array.isArray(result.queues) ? result.queues : [];
		const serviceContext = result.service_context || {};
		const priorityItems = Array.isArray(result.priority_items) ? result.priority_items : [];
		const recommendedSequence = Array.isArray(result.recommended_sequence) ? result.recommended_sequence : [];
		const nextRecommendedCase = result.next_recommended_case || null;
		const batchDraftCandidates = Array.isArray(result.batch_draft_candidates) ? result.batch_draft_candidates : [];
		const topPriority = buildCustomerServiceHeroItem(serviceContext, priorityItems, result);
		const supportingPriorityItems = filterCustomerServiceSupportingPriorities(priorityItems, serviceContext);

		let html = renderQueueWorkbenchIntro({
			result: result,
			showAssistantIntro: true,
			showBoundary: true,
			summary: result.summary || 'Customer service queue',
			subtle: 'Read-only queue. No emails are sent automatically.',
			parserRule: parsed.parser_rule,
			title: 'Customer follow-up candidates',
			contextLine: 'Showing up to ' + (result.limit_per_queue || 5) + ' cases per queue.',
			badge: (result.count || 0) + ' follow-ups',
			cardClassName: 'yoro-queue-workbench-card-customer-service'
		}, h);
		html += renderQueueWorkbenchHero(topPriority, [
			{ label: 'Follow-up items', value: serviceContext.total_items || result.count || 0 },
			{ label: 'On-hold', value: serviceContext.on_hold_count || 0 },
			{ label: 'Pending payment', value: serviceContext.payment_count || 0 },
			{ label: 'Saved drafts', value: serviceContext.draft_count || 0 }
		], h, {
			className: 'yoro-briefing-hero-compact'
		});
		html += renderWorklistModeCard(result, h);
		html += renderQueueStartHereCard(nextRecommendedCase, h, {
			hideCommands: ['Show customer service queue']
		});
		html += renderBatchDraftPreparation({
			title: 'Prepare these reply drafts next',
			description: 'Use this shortlist to finish several likely replies in one pass.',
			items: batchDraftCandidates
		}, h);
		html += renderQueueRecommendedSequence(recommendedSequence, h, 'What comes after this', {
			showActions: false,
			limit: 3
		});
		html += renderQueuePriorityCards(supportingPriorityItems, h, 'Other pressures to watch');
		html += renderQueueMetaMetrics([
			{ label: 'Queues', value: queues.length },
			{ label: 'Total items', value: result.count || 0 },
			{ label: 'Auto-send', value: 'No' }
		], h);
		html += renderOrderQueueSections(queues, {
			emptyMessage: 'No matching orders in this queue.',
			maxRows: 3,
			columns: [
				{ label: 'Order' },
				{ label: 'Case' },
				{ label: 'Age' },
				{ label: 'Next action' }
			],
			renderRow: function(order, queue, h2) {
				const queueKey = String(queue && queue.key ? queue.key : '');
				const rowActionLabel = getCustomerServiceRowActionLabel(queueKey);
				const rowActionCommand = getCustomerServiceRowActionCommand(queueKey, order);
				const caseMeta = [
					order.customer_name || order.billing_email || 'Customer',
					humanizeServiceTopic(queueKey || ''),
					order.status || ''
				].filter(Boolean).join(' · ');
				let row = '<tr>';
				row += '<td><strong>#' + h2.escapeHtml(order.number || order.id) + '</strong></td>';
				row += '<td><strong>' + h2.escapeHtml(caseMeta) + '</strong><div class="yoro-result-subtle">' + h2.escapeHtml(order.followup_reason || '') + '</div></td>';
				row += '<td>' + h2.escapeHtml(order.age_hours || 0) + 'h</td>';
				row += '<td class="yoro-row-actions">';
				row += '<button type="button" class="button button-small yoro-suggestion-btn" data-command="' + h2.escapeHtml(rowActionCommand) + '">' + h2.escapeHtml(rowActionLabel) + '</button> ';
				row += '<button type="button" class="button button-small yoro-row-details" data-order-id="' + h2.escapeHtml(order.id) + '">Details</button> ';
				row += '</td>';
				row += '</tr>';
				return row;
			}
		}, h);
		html += renderQueueActionStrip([
			{ label: 'Refresh queue', command: 'Show customer service queue' },
			{ label: 'Operations', command: 'Show operations attention queue' },
			{ label: 'Pending orders', command: 'Show pending orders' }
		], h, 'Other views');
		html += '</div>';
		html += h.renderDebugJson(data);

		return html;
	}

	function humanizeServiceTopic(topic) {
		switch (String(topic || '')) {
			case 'on_hold_followup':
				return 'On-hold follow-up';
			case 'pending_payment_followup':
				return 'Pending payment';
			case 'saved_email_drafts':
				return 'Saved drafts';
			case 'failed_checkout_followup':
				return 'Failed checkout';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function buildCustomerServiceHeroItem(serviceContext, priorityItems, result) {
		const topTopic = String(serviceContext && serviceContext.top_topic ? serviceContext.top_topic : '');
		const totalItems = serviceContext && serviceContext.total_items ? serviceContext.total_items : (result && result.count ? result.count : 0);
		const onHold = serviceContext && serviceContext.on_hold_count ? serviceContext.on_hold_count : 0;
		const drafts = serviceContext && serviceContext.draft_count ? serviceContext.draft_count : 0;
		const payments = serviceContext && serviceContext.payment_count ? serviceContext.payment_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Customer replies need attention across the queue.';
		let message = 'Use Start here for the fastest next case, then work through the shortlist below.';

		if (topTopic === 'on_hold_followup') {
			title = 'Most customer reply pressure is sitting in on-hold orders.';
			message = 'There are ' + onHold + ' on-hold follow-ups carrying most of the live backlog, plus ' + drafts + ' saved drafts you can finish quickly.';
		} else if (topTopic === 'saved_email_drafts') {
			title = 'Saved drafts give you the fastest route to completed replies.';
			message = 'There are ' + drafts + ' saved drafts ready now, while the wider queue still holds ' + totalItems + ' customer follow-up items.';
		} else if (topTopic === 'pending_payment_followup') {
			title = 'Payment follow-ups are the clearest active customer pressure.';
			message = 'There are ' + payments + ' pending payment cases to clear, plus ' + drafts + ' saved drafts you can finish quickly.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterCustomerServiceSupportingPriorities(priorityItems, serviceContext) {
		const topTopic = String(serviceContext && serviceContext.top_topic ? serviceContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function getCustomerServiceRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'saved_email_drafts':
				return 'Open draft';
			case 'pending_payment_followup':
				return 'Draft payment follow-up';
			case 'on_hold_followup':
				return 'Draft on-hold reply';
			case 'failed_checkout_followup':
				return 'Draft support reply';
			default:
				return 'Draft reply';
		}
	}

	function getCustomerServiceRowActionCommand(queueKey, order) {
		const orderId = order && order.id ? order.id : '';

		if (String(queueKey || '') === 'saved_email_drafts') {
			return 'Load email drafts for order #' + orderId;
		}

		return (order && order.suggested_command) || ('Write email for order #' + orderId);
	}

	function humanizeAttentionTopic(topic) {
		switch (String(topic || '')) {
			case 'pending_payment_older_than_24h':
				return 'Pending payment';
			case 'processing_older_than_72h':
				return 'Aging processing';
			case 'on_hold_older_than_48h':
				return 'On-hold';
			case 'recent_failed_orders':
				return 'Failed orders';
			case 'high_value_open_orders':
				return 'High-value open';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function getOperationsAttentionRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'pending_payment_older_than_24h':
				return 'Draft payment follow-up';
			case 'processing_older_than_72h':
				return 'Preview delay email';
			case 'on_hold_older_than_48h':
				return 'Draft on-hold reply';
			case 'recent_failed_orders':
				return 'Draft support reply';
			case 'high_value_open_orders':
				return 'Open order details';
			default:
				return 'Review order';
		}
	}

	function getOperationsAttentionRowCommand(queueKey, order) {
		const orderId = parseInt(order && order.id ? order.id : 0, 10);
		if (!orderId) {
			return '';
		}

		switch (String(queueKey || '')) {
			case 'pending_payment_older_than_24h':
				return 'Write email for order #' + orderId + ' about: Payment follow-up may be useful.';
			case 'processing_older_than_72h':
				return 'Preview shipping delay email for order ' + orderId;
			case 'on_hold_older_than_48h':
				return 'Write email for order #' + orderId + ' about: Order is still on hold and may need clarification.';
			case 'recent_failed_orders':
				return 'Write email for order #' + orderId + ' about: Failed checkout may need support follow-up.';
			case 'high_value_open_orders':
				return 'Show order ' + orderId + ' details';
			default:
				return 'Show order ' + orderId + ' details';
		}
	}

	function buildOperationsAttentionHeroItem(attentionContext, priorityItems, result) {
		const topTopic = String(attentionContext && attentionContext.top_topic ? attentionContext.top_topic : '');
		const pending = attentionContext && attentionContext.pending_count ? attentionContext.pending_count : 0;
		const processing = attentionContext && attentionContext.processing_count ? attentionContext.processing_count : 0;
		const onHold = attentionContext && attentionContext.on_hold_count ? attentionContext.on_hold_count : 0;
		const highValue = attentionContext && attentionContext.high_value_count ? attentionContext.high_value_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Operations attention still needs a direct pass.';
		let message = 'Use Start here for the clearest next case, then move through the shortlist below.';

		if (topTopic === 'pending_payment_older_than_24h') {
			title = 'Pending payment is carrying most of the operations pressure.';
			message = 'There are ' + pending + ' payment-delay cases, while ' + processing + ' processing orders are still aging behind them.';
		} else if (topTopic === 'processing_older_than_72h') {
			title = 'Aging processing orders now need the next manual check.';
			message = 'There are ' + processing + ' older processing orders to review, plus ' + pending + ' payment-delay cases still waiting.';
		} else if (topTopic === 'on_hold_older_than_48h') {
			title = 'On-hold orders are waiting for a clear decision.';
			message = 'There are ' + onHold + ' older on-hold orders still sitting in backlog, with ' + pending + ' payment-delay cases also active.';
		} else if (topTopic === 'high_value_open_orders') {
			title = 'High-value open orders deserve the next direct check.';
			message = 'There are ' + highValue + ' higher-value open orders still unresolved, alongside ' + processing + ' aging processing cases.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterOperationsAttentionSupportingPriorities(priorityItems, attentionContext) {
		const topTopic = String(attentionContext && attentionContext.top_topic ? attentionContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function humanizeBusinessTopic(topic) {
		switch (String(topic || '')) {
			case 'state_summary':
				return 'Commercial health';
			case 'operations_pressure':
				return 'Operational pressure';
			case 'sales':
				return 'Sales';
			case 'fulfillment_velocity':
				return 'Fulfillment velocity';
			case 'open_order_mix':
				return 'Open order mix';
			case 'top_customers':
				return 'Customer mix';
			case 'top_products':
				return 'Product demand';
			case 'sales_gap':
				return 'Sales gap';
			default:
				return String(topic || 'overview').replace(/_/g, ' ');
		}
	}

	function humanizeFulfillmentTopic(topic) {
		switch (String(topic || '')) {
			case 'processing_without_tracking':
				return 'Missing tracking';
			case 'processing_older_than_48h':
				return 'Aging shippable order';
			case 'missing_shipping_address':
				return 'Address gap';
			case 'recent_ready_to_fulfill':
				return 'Tracked ready order';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function getFulfillmentRowActionLabel(queueKey) {
		switch (String(queueKey || '')) {
			case 'processing_without_tracking':
				return 'Preview delay email';
			case 'missing_shipping_address':
				return 'Check address issue';
			case 'processing_older_than_48h':
				return 'Review aging order';
			case 'recent_ready_to_fulfill':
				return 'Preview fulfillment check';
			default:
				return 'Preview follow-up';
		}
	}

	function buildFulfillmentHeroItem(fulfillmentContext, priorityItems, result) {
		const topTopic = String(fulfillmentContext && fulfillmentContext.top_topic ? fulfillmentContext.top_topic : '');
		const tracking = fulfillmentContext && fulfillmentContext.tracking_count ? fulfillmentContext.tracking_count : 0;
		const aging = fulfillmentContext && fulfillmentContext.aging_count ? fulfillmentContext.aging_count : 0;
		const address = fulfillmentContext && fulfillmentContext.address_count ? fulfillmentContext.address_count : 0;
		const ready = fulfillmentContext && fulfillmentContext.ready_count ? fulfillmentContext.ready_count : 0;
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		let title = 'Shippable fulfillment cases still need active review.';
		let message = 'Use Start here for the clearest next shipping case, then work through the shortlist below.';

		if (topTopic === 'processing_without_tracking') {
			title = 'Missing tracking is carrying most of the shipping risk.';
			message = 'There are ' + tracking + ' shippable processing orders without tracking signals, while ' + aging + ' more are aging deeper into the queue.';
		} else if (topTopic === 'missing_shipping_address') {
			title = 'Address gaps are blocking otherwise normal shipment work.';
			message = 'There are ' + address + ' shippable orders with incomplete shipping details, while ' + ready + ' tracked orders are otherwise ready to move.';
		} else if (topTopic === 'processing_older_than_48h') {
			title = 'Older shippable processing orders now need the next manual check.';
			message = 'There are ' + aging + ' older shippable orders to review, plus ' + tracking + ' missing-tracking cases that may need outbound follow-up.';
		} else if (topTopic === 'recent_ready_to_fulfill') {
			title = 'Tracked orders are ready for the next fulfillment pass.';
			message = 'There are ' + ready + ' recent tracked orders with complete shipping details ready to move through the next step.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterFulfillmentSupportingPriorities(priorityItems, fulfillmentContext) {
		const topTopic = String(fulfillmentContext && fulfillmentContext.top_topic ? fulfillmentContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function humanizeInventoryTopic(topic) {
		switch (String(topic || '')) {
			case 'out_of_stock':
				return 'Stockouts';
			case 'low_stock':
				return 'Low stock';
			case 'on_backorder':
				return 'Backorders';
			case 'unmanaged_stock_selling':
				return 'Unmanaged stock';
			case 'low_stock_in_processing_orders':
				return 'Processing demand';
			default:
				return String(topic || 'queue').replace(/_/g, ' ');
		}
	}

	function buildInventoryHeroItem(inventoryContext, priorityItems) {
		const topTopic = String(inventoryContext && inventoryContext.top_topic ? inventoryContext.top_topic : '');
		const topPriority = Array.isArray(priorityItems) && priorityItems.length ? priorityItems[0] : null;
		const stockouts = inventoryContext && inventoryContext.out_of_stock_count ? inventoryContext.out_of_stock_count : 0;
		const backorders = inventoryContext && inventoryContext.backorder_count ? inventoryContext.backorder_count : 0;
		const lowStock = inventoryContext && inventoryContext.low_stock_count ? inventoryContext.low_stock_count : 0;
		const processingDemand = inventoryContext && inventoryContext.recent_processing_count ? inventoryContext.recent_processing_count : 0;
		const statusOnly = String(inventoryContext && inventoryContext.inventory_mode ? inventoryContext.inventory_mode : '') === 'status_only';
		let title = statusOnly
			? 'Stock-status exceptions are the clearest inventory signal right now.'
			: 'Inventory pressure is concentrating in a few products.';
		let message = statusOnly
			? 'Use Start here for the cleanest stock-status exception, then work through the shortlist below.'
			: 'Use Start here for the cleanest stock-risk case, then move through the shortlist below.';

		if (topTopic === 'out_of_stock') {
			title = statusOnly
				? 'Out-of-stock products are the clearest stock-status exception right now.'
				: 'Stockouts are the clearest inventory risk right now.';
			message = 'There are ' + stockouts + ' out-of-stock products to review' + (backorders ? ', with ' + backorders + ' more already on backorder.' : '.');
		} else if (topTopic === 'on_backorder') {
			title = 'Backorders are still building behind active demand.';
			message = 'There are ' + backorders + ' backordered products, and ' + stockouts + ' more are already out of stock.';
		} else if (topTopic === 'low_stock_in_processing_orders') {
			title = 'Recent order demand is now consuming the remaining stock.';
			message = 'There are ' + processingDemand + ' products already under live processing demand, alongside ' + lowStock + ' other low-stock items.';
		} else if (topTopic === 'low_stock') {
			title = 'Low-stock items should be queued for replenishment now.';
			message = 'There are ' + lowStock + ' low-stock products to review before they turn into stockouts.';
		} else if (topPriority && topPriority.message) {
			title = topPriority.title || title;
			message = topPriority.message;
		}

		return {
			title: title,
			message: message
		};
	}

	function filterInventorySupportingPriorities(priorityItems, inventoryContext) {
		const topTopic = String(inventoryContext && inventoryContext.top_topic ? inventoryContext.top_topic : '');
		const items = Array.isArray(priorityItems) ? priorityItems.filter(function (item) {
			return item && String(item.topic || '') !== topTopic;
		}) : [];

		return items.slice(0, 2);
	}

	function formatInventoryRowStock(product, stockManagementEnabled, inventoryMode) {
		const quantity = product && Object.prototype.hasOwnProperty.call(product, 'stock_quantity') ? product.stock_quantity : '';
		const threshold = product && product.low_stock_threshold ? product.low_stock_threshold : 0;
		const stockStatus = String(product && product.stock_status ? product.stock_status : '');

		if (quantity === '' || quantity === null) {
			return inventoryMode === 'status_only'
				? stockStatus.replace(/_/g, ' ')
				: 'Quantity not tracked';
		}

		let text = String(quantity);
		if (stockManagementEnabled && threshold) {
			text += ' / low ' + String(threshold);
		}
		return text;
	}

	function buildInventoryRowCaseMessage(queueKey, fallbackDescription, product) {
		const openOrderCount = parseInt(product && product.open_order_count ? product.open_order_count : 0, 10) || 0;

		switch (String(queueKey || '')) {
			case 'out_of_stock':
				return 'This product is already out of stock and should be reviewed first.';
			case 'on_backorder':
				return 'This product is already on backorder and may now be feeding directly into customer or fulfillment pressure.';
			case 'low_stock_in_processing_orders':
				return openOrderCount > 0
					? 'Recent processing orders already depend on this item (' + openOrderCount + ').'
					: 'Recent processing demand is already pulling on the remaining stock for this item.';
			case 'low_stock':
				return 'Stock is already at or below the low-stock threshold.';
			case 'unmanaged_stock_selling':
				return 'This product is selling without quantity tracking.';
			default:
				return fallbackDescription || 'Review this product inside the current inventory queue.';
		}
	}

	function renderRefundPreviewResult(data, h) {
		const result = data.result || {};
		const order = result.order || {};

		const totals = result.totals || {};
		const items = Array.isArray(result.items) ? result.items : [];
		const refunds = Array.isArray(result.refunds) ? result.refunds : [];

		let html = '<div class="yoro-result-summary">';
		html += '<strong>' + h.escapeHtml(result.summary || 'Refund preview') + '</strong>';
		html += '<span class="yoro-result-subtle">Read-only preview</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-card">';

		html += '<div class="yoro-order-detail-header">';
		html += '<div>';
		html += '<strong class="yoro-order-detail-title">Order #' + h.escapeHtml(order.number || order.id || '') + '</strong>';
		html += '<div class="yoro-result-subtle">' + h.escapeHtml(order.date_created || '') + '</div>';
		html += '</div>';
		html += '<span class="yoro-status-badge">' + h.escapeHtml(order.status || '') + '</span>';
		html += '</div>';

		html += '<div class="yoro-order-detail-metrics">';
		html += '<div><span>Order total</span><strong>' + h.escapeHtml(order.total || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '<div><span>Refunded</span><strong>' + h.escapeHtml(order.total_refunded || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '<div><span>Remaining</span><strong>' + h.escapeHtml(order.remaining || '0') + ' ' + h.escapeHtml(order.currency || '') + '</strong></div>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Payment</h4>';
		html += '<p><strong>Method:</strong> ' + h.escapeHtml(order.payment_title || order.payment_method || '—') + '</p>';
		html += '<p><strong>Transaction ID:</strong> ' + h.escapeHtml(order.transaction_id || '—') + '</p>';
		html += '<p><strong>Can refund:</strong> ' + h.escapeHtml(result.can_refund ? 'Yes' : 'No') + '</p>';
		html += '</div>';

		html += '<div class="yoro-order-detail-section">';
		html += '<h4>Totals</h4>';
		html += '<table class="yoro-orders-table">';
		html += '<tbody>';
		Object.keys(totals).forEach(function(key) {
			html += '<tr>';
			html += '<th>' + h.escapeHtml(key.replace(/_/g, ' ')) + '</th>';
			html += '<td>' + h.escapeHtml(totals[key]) + ' ' + h.escapeHtml(order.currency || '') + '</td>';
			html += '</tr>';
		});
		html += '</tbody>';
		html += '</table>';
		html += '</div>';

		if (items.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Refundable line items</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Refunded Qty</th><th>Remaining Qty</th><th>Line Total</th><th>Remaining</th></tr></thead>';
			html += '<tbody>';

			items.forEach(function(item) {
				html += '<tr>';
				html += '<td>' + h.escapeHtml(item.product_name || '') + '</td>';
				html += '<td>' + h.escapeHtml(item.sku || '') + '</td>';
				html += '<td>' + h.escapeHtml(item.quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.refunded_quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.remaining_quantity || 0) + '</td>';
				html += '<td>' + h.escapeHtml(item.line_total || '0') + '</td>';
				html += '<td>' + h.escapeHtml(item.remaining_total || '0') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

		if (refunds.length) {
			html += '<div class="yoro-order-detail-section">';
			html += '<h4>Existing refunds</h4>';
			html += '<table class="yoro-orders-table">';
			html += '<thead><tr><th>Refund</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead>';
			html += '<tbody>';

			refunds.forEach(function(refund) {
				html += '<tr>';
				html += '<td>#' + h.escapeHtml(refund.id || '') + '</td>';
				html += '<td>' + h.escapeHtml(refund.amount || '0') + ' ' + h.escapeHtml(order.currency || '') + '</td>';
				html += '<td>' + h.escapeHtml(refund.reason || '—') + '</td>';
				html += '<td>' + h.escapeHtml(refund.date || '') + '</td>';
				html += '</tr>';
			});

			html += '</tbody></table>';
			html += '</div>';
		}

		html += '<div class="yoro-order-detail-actions">';
		if (order.edit_url) {
			html += '<a class="button button-small" href="' + h.escapeHtml(order.edit_url) + '" target="_blank" rel="noopener noreferrer">Open order</a>';
		}
		html += '</div>';

		html += '</div>';

		html += h.renderDebugJson(data);

		return html;
	}	
		
	function handleOrderClick(event, h) {
		const rawTarget = event.target;

		const target =
			rawTarget.closest(
				'.yoro-select-all,' +
				'.yoro-run-hold,' +
				'.yoro-row-preview-hold,' +
				'.yoro-row-run-hold,' +
				'.yoro-row-details,' +
				'.yoro-row-add-note,' +
				'.yoro-row-update-status,' +
				'.yoro-bulk-details,' +
				'.yoro-bulk-add-note,' +
				'.yoro-bulk-update-status,' +
				'.yoro-bulk-status-note,' +
				'.yoro-row-action-menu,' +
				'.yoro-modal-action'
			);

		if (!target) {
			return false;
		}

		if (target.classList.contains('yoro-select-all')) {
			const checked = target.checked;
			const table = target.closest('table');

			if (table) {
				table.querySelectorAll('.yoro-order-checkbox').forEach(function (cb) {
					cb.checked = checked;
				});
			}

			return true;
		}

		if (target.classList.contains('yoro-run-hold')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				h.addAgentMessage({
					success: false,
					message: 'Please select at least one order.'
				});
				return true;
			}

			h.runCommandFromText('Hold orders ' + ids.join(', '), false, false);
			return true;
		}

		if (target.classList.contains('yoro-row-preview-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				h.runCommandFromText('Hold order ' + orderId, false, true);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-run-hold')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				h.runCommandFromText('Hold order ' + orderId, false, false);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-details')) {
			const orderId = target.getAttribute('data-order-id');

			if (orderId) {
				h.setCurrentOrderId(orderId);
				h.runCommandFromText('Show order ' + orderId + ' details', false, false);
			}

			return true;
		}

		if (target.classList.contains('yoro-row-add-note')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openNoteModal(function(note) {
				h.runCommandFromText('Add note to order ' + orderId + ': ' + note, false, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-row-update-status')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openStatusModal(function(status) {
				h.runCommandFromText('Change order ' + orderId + ' to ' + status, false, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-details')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				h.addAgentMessage({
					success: false,
					message: 'Please select at least one order.'
				});
				return true;
			}

			h.runCommandFromText('Show details for orders ' + ids.join(', '), false, false);
			return true;
		}

		if (target.classList.contains('yoro-bulk-add-note')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				h.addAgentMessage({
					success: false,
					message: 'Please select at least one order.'
				});
				return true;
			}

			h.openNoteModal(function(note) {
				h.runCommandFromText('Add note to orders ' + ids.join(', ') + ': ' + note, false, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-update-status')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				h.addAgentMessage({
					success: false,
					message: 'Please select at least one order.'
				});
				return true;
			}

			h.openStatusModal(function(status) {
				h.runCommandFromText('Change orders ' + ids.join(', ') + ' to ' + status, false, false);
			});

			return true;
		}

		if (target.classList.contains('yoro-bulk-status-note')) {
			const ids = h.getSelectedOrderIds();

			if (!ids.length) {
				h.addAgentMessage({
					success: false,
					message: 'Please select at least one order.'
				});
				return true;
			}

			h.openStatusNoteModal(function(status, note) {
				h.runCommandFromText(
					'Change orders ' + ids.join(', ') + ' to ' + status + ' and add note: ' + note,
					false,
					false
				);
			});

			return true;
		}

		if (target.classList.contains('yoro-row-action-menu')) {
			const orderId = target.getAttribute('data-order-id');

			if (!orderId) {
				return true;
			}

			h.setCurrentOrderId(orderId);

			h.openModal(
				'Order #' + orderId + ' actions',
				`
				<div class="yoro-modal-action-list">
					<button type="button" class="button yoro-modal-action" data-action="hold">Hold</button>
					<button type="button" class="button yoro-modal-action" data-action="note">Add Note</button>
					<button type="button" class="button yoro-modal-action" data-action="status">Change Status</button>
					<button type="button" class="button yoro-modal-action" data-action="identity">Find by Identity</button>
					<button type="button" class="button yoro-modal-action" data-action="refund_preview">Refund Preview</button>
					<button type="button" class="button yoro-modal-action" data-action="email_verification">Preview Verification Email</button>
					<button type="button" class="button yoro-modal-action" data-action="email_payment">Preview Payment Email</button>
					<button type="button" class="button yoro-modal-action" data-action="email_shipping_delay">Preview Shipping Delay Email</button>
					<button type="button" class="button yoro-modal-action" data-action="send_email_verification">Send Verification Email</button>
					<button type="button" class="button yoro-modal-action" data-action="send_email_payment">Send Payment Email</button>
					<button type="button" class="button yoro-modal-action" data-action="load_email_drafts">Load Email Drafts</button>
				</div>
				`,
				null
			);

			document.querySelectorAll('.yoro-modal-action').forEach(function(btn) {
				btn.onclick = function() {
					const action = btn.getAttribute('data-action');

					h.setCurrentOrderId(orderId);

					const modal = btn.closest('.yoro-modal');
					if (modal) {
						modal.classList.add('hidden');
					}

					if (action === 'hold') {
						h.runCommandFromText('Hold order ' + orderId, false, false);
					}

					if (action === 'note') {
						h.openNoteModal(function(note) {
							h.runCommandFromText('Add note to order ' + orderId + ': ' + note, false, false);
						});
					}

					if (action === 'status') {
						h.openStatusModal(function(status) {
							h.runCommandFromText('Change order ' + orderId + ' to ' + status, false, false);
						});
					}

					if (action === 'identity') {
						h.runCommandFromText('Find orders by identity for order ' + orderId, false, false);
					}

					if (action === 'refund_preview') {
						h.runCommandFromText('Preview refund for order ' + orderId, false, false);
					}

					if (action === 'email_verification') {
						h.runCommandFromText('Preview verification email for order ' + orderId, false, false);
					}

					if (action === 'email_payment') {
						h.runCommandFromText('Preview payment mismatch email for order ' + orderId, false, false);
					}

					if (action === 'email_shipping_delay') {
						h.runCommandFromText('Preview shipping delay email for order ' + orderId, false, false);
					}

					if (action === 'send_email_verification') {
						h.runCommandFromText('Send verification email for order ' + orderId, false, false);
					}

					if (action === 'send_email_payment') {
						h.runCommandFromText('Send payment mismatch email for order ' + orderId, false, false);
					}

					if (action === 'load_email_drafts') {
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

								h.addAgentMessage(data);
								h.updateConversationState(data, 'direct:' + ((data.parsed && data.parsed.action) || ''));
							})
							.catch(function(error) {
								loading.remove();

								h.addAgentMessage({
									success: false,
									error: error
								});
							});
					}
				};
			});

			return true;
		}

		return false;
	}

	window.YoRoOrderUI = {
		init: init
	};
})();
