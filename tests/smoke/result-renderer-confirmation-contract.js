#!/usr/bin/env node
/**
 * YoRo result renderer confirmation smoke contract.
 *
 * Run from the plugin root with:
 * node tests/smoke/result-renderer-confirmation-contract.js
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const pluginRoot = path.resolve(__dirname, '..', '..');
const rendererPath = path.join(pluginRoot, 'assets/js/yoro-ai-result-renderers.js');
const code = fs.readFileSync(rendererPath, 'utf8');
const failures = [];

function assert(condition, message) {
	if (!condition) {
		failures.push(message);
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

const sandbox = {
	window: {},
	console
};

vm.createContext(sandbox);
vm.runInContext(code, sandbox, { filename: rendererPath });

const resultRenderers = sandbox.window.YoRoResultRenderers || {};
const instance = typeof resultRenderers.init === 'function'
	? resultRenderers.init({
		escapeHtml,
		shouldShowDebugJson: () => false
	})
	: {};
const mailboxPreviewInstance = typeof resultRenderers.init === 'function'
	? resultRenderers.init({
		escapeHtml,
		shouldShowDebugJson: () => false,
		renderCustomerEmailPreviewResult: () => '<div class="mailbox-reply-preview-rendered">MAILBOX_REPLY_PREVIEW_RENDERED</div>'
	})
	: {};

assert(typeof resultRenderers.init === 'function', 'Result renderer init must be exported.');
assert(typeof instance.renderResult === 'function', 'Result renderer must expose renderResult.');

if (typeof mailboxPreviewInstance.renderResult === 'function') {
	const mailboxPreviewHtml = mailboxPreviewInstance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-mailbox-reply',
		parsed: {
			action: 'email.reply.send'
		},
		email_preview: {
			kind: 'mailbox_reply',
			action: 'email.reply.send',
			send_action: 'email.reply.send',
			thread_id: 28,
			subject: 'Shipping Status Inquiry',
			body: 'Thanks for reaching out.'
		},
		result: {
			display_type: 'confirmation',
			action: 'email.reply.send',
			requires_confirmation: true
		}
	});

	assert(mailboxPreviewHtml.includes('MAILBOX_REPLY_PREVIEW_RENDERED'), 'Mailbox reply confirmation must render the email preview renderer.');
	assert(!mailboxPreviewHtml.includes('Please approve this change'), 'Mailbox reply confirmation must not render generic approval copy.');
	assert(!mailboxPreviewHtml.includes('Approve change'), 'Mailbox reply confirmation must not render generic approval button.');

	const terminalMailboxReplyHtml = mailboxPreviewInstance.renderResult({
		success: true,
		stage: 'done',
		pending_operation_active: false,
		pending_operation_status: 'executed',
		parsed: {
			action: 'email.reply.send',
			action_plan: {
				email_preview: {
					kind: 'mailbox_reply',
					action: 'email.reply.send',
					send_action: 'email.reply.send',
					thread_id: 28,
					subject: 'Shipping Status Inquiry',
					body: 'Thanks for reaching out.'
				}
			}
		},
		result: {
			success: true,
			message: 'Email reply sent.',
			summary: 'Email reply sent.',
			display_type: 'assistant_message',
			result_type: 'assistant_message',
			internal_execution: true
		}
	});

	assert(terminalMailboxReplyHtml.includes('Email reply sent.'), 'Terminal mailbox reply response must render the sent confirmation.');
	assert(!terminalMailboxReplyHtml.includes('MAILBOX_REPLY_PREVIEW_RENDERED'), 'Terminal mailbox reply response must not re-render the email preview.');
}

if (typeof instance.renderResult === 'function') {
	const blockedHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: false,
		parsed: {
			action: 'orders.update_status',
			params: {
				order_ids: [124],
				status: 'completed'
			}
		},
		assistant: {
			message: 'I can update order #124 to Completed. Because this will update your store, I’ll wait for your approval first.'
		},
		result: {
			display_type: 'confirmation',
			action: 'orders.update_status',
			params: {
				order_ids: [124],
				status: 'completed'
			},
			blocked: true,
			block_reason: 'order_not_found',
			message: 'Order #124: not found.',
			orders: [
				{
					id: 124,
					exists: false
				}
			],
			execution_required: false,
			requires_confirmation: false,
			confirm_required: false,
			pending_operation_active: false
		}
	});

	assert(blockedHtml.includes('Needs a valid target'), 'Blocked confirmation must show blocked state.');
	assert(blockedHtml.includes('Choose an existing order first'), 'Blocked order confirmation must show order target title.');
	assert(blockedHtml.includes('Order #124: not found.'), 'Blocked order confirmation must show structured not-found line.');
	assert(!blockedHtml.includes('Please approve this change'), 'Blocked confirmation must not show approval title.');
	assert(!blockedHtml.includes('Nothing has changed'), 'Blocked confirmation must not show no-change approval note.');
	assert(!blockedHtml.includes('Approve change'), 'Blocked confirmation must not render approve button.');
	assert(!blockedHtml.includes('I can update order #124 to Completed. Because this will update your store'), 'Blocked confirmation must not repeat approval prose.');

	const productHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-product-stock-status',
		parsed: {
			action: 'products.update_stock_status',
			params: {
				global_unique_id: '012345678912',
				stock_status: 'onbackorder'
			}
		},
		assistant: {
			message: 'Got it. I’ll update stock status for product global ID 012345678912 to On backorder.'
		},
		result: {
			display_type: 'confirmation',
			action: 'products.update_stock_status',
			params: {
				global_unique_id: '012345678912',
				stock_status: 'onbackorder'
			},
			requires_confirmation: true,
			products: [
				{
					id: 47,
					name: 'Blue Hoodie',
					sku: 'HOODIE-BLUE-M',
					global_unique_id: '012345678912',
					product_type: 'simple',
					current_stock_status_label: 'In stock',
					target_stock_status_label: 'On backorder'
				}
			]
		}
	});

	assert(productHtml.includes('Please confirm this product and target stock status before I update the store.'), 'Product stock status confirmation must use concise structured intro.');
	assert(productHtml.includes('Name: Blue Hoodie'), 'Product confirmation must show product name.');
	assert(productHtml.includes('Product ID: 47'), 'Product confirmation must show product id.');
	assert(productHtml.includes('SKU: HOODIE-BLUE-M'), 'Product confirmation must show product SKU.');
	assert(productHtml.includes('Global ID: 012345678912'), 'Product confirmation must show global unique id.');
	assert(productHtml.includes('Stock status: In stock -&gt; On backorder'), 'Product confirmation must show current and target stock status.');
	assert(productHtml.includes('Approve change'), 'Product confirmation must render approve action.');
	assert(!productHtml.includes('Got it. I’ll update stock status for product global ID 012345678912'), 'Product confirmation must not repeat assistant prose above the structured card.');

	const orderStatusHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-order-status',
		parsed: {
			action: 'orders.update_status',
			params: {
				order_ids: [7188],
				status: 'completed'
			}
		},
		assistant: {
			message: 'I can update order #7188 to Completed. Because this will update your store, I’ll wait for your approval first.'
		},
		result: {
			display_type: 'confirmation',
			action: 'orders.update_status',
			params: {
				order_ids: [7188],
				status: 'completed'
			},
			requires_confirmation: true,
			target_status_label: 'Completed',
			orders: [
				{
					id: 7188,
					exists: true,
					current_status_label: 'Processing'
				}
			]
		}
	});

	assert(orderStatusHtml.includes('Order #7188: Processing -&gt; Completed.'), 'Order status confirmation must show current and target status.');
	assert(orderStatusHtml.includes('Approve change'), 'Order status confirmation must render approve action.');
	assert(!orderStatusHtml.includes('I can update order #7188 to Completed. Because this will update your store'), 'Order status confirmation must not repeat assistant prose above the structured card.');

	const orderNoteHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-order-note',
		parsed: {
			action: 'orders.add_note',
			params: {
				order_ids: [7188],
				note: 'Shipment was delayed'
			}
		},
		assistant: {
			message: 'I can add that note to order #7188.'
		},
		result: {
			display_type: 'confirmation',
			action: 'orders.add_note',
			params: {
				order_ids: [7188],
				note: 'Shipment was delayed'
			},
			requires_confirmation: true,
			note_preview: 'Shipment was delayed',
			orders: [
				{
					id: 7188,
					exists: true
				}
			]
		}
	});

	assert(orderNoteHtml.includes('Order #7188 will get this note'), 'Order note confirmation must show target order.');
	assert(orderNoteHtml.includes('Shipment was delayed'), 'Order note confirmation must show note preview.');
	assert(orderNoteHtml.includes('Approve change'), 'Order note confirmation must render approve action.');
	assert(!orderNoteHtml.includes('I can add that note to order #7188.'), 'Order note confirmation must not repeat assistant prose above the structured card.');

	const templateSaveHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-template-save',
		parsed: {
			action: 'email_templates.save',
			params: {
				name: 'Shipping update',
				subject: 'Shipping update',
				body: 'Your order is ready to ship.'
			}
		},
		assistant: {
			message: 'I’ll save that email template after approval.'
		},
		result: {
			display_type: 'confirmation',
			action: 'email_templates.save',
			params: {
				name: 'Shipping update',
				subject: 'Shipping update',
				body: 'Your order is ready to ship.'
			},
			requires_confirmation: true
		}
	});

	assert(templateSaveHtml.includes('Template: Shipping update'), 'Email template save confirmation must show template name.');
	assert(templateSaveHtml.includes('Subject: Shipping update'), 'Email template save confirmation must show subject.');
	assert(templateSaveHtml.includes('Approve change'), 'Email template save confirmation must render approve action.');
	assert(!templateSaveHtml.includes('I’ll save that email template after approval.'), 'Email template save confirmation must not repeat assistant prose above the structured card.');

	const couponCreateHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-coupon-create',
		parsed: {
			action: 'coupons.create',
			params: {
				coupon_code: 'SUMMER10',
				discount_type: 'percent',
				discount_amount: 10
			}
		},
		assistant: {
			message: 'Got it. I’ll create coupon SUMMER10 for 10 percent off after approval.'
		},
		result: {
			display_type: 'confirmation',
			action: 'coupons.create',
			params: {
				coupon_code: 'SUMMER10',
				discount_type: 'percent',
				discount_amount: 10
			},
			requires_confirmation: true,
			coupon: {
				coupon_code: 'SUMMER10',
				discount_type: 'percent',
				discount_type_label: 'Percentage discount',
				discount_amount: 10,
				discount_summary: '10.00% off',
				date_expires: '2026-07-01'
			}
		}
	});

	assert(couponCreateHtml.includes('Review coupon details'), 'Coupon confirmation must use coupon-specific title.');
	assert(couponCreateHtml.includes('Coupon code: SUMMER10'), 'Coupon confirmation must show coupon code.');
	assert(couponCreateHtml.includes('Discount: 10.00% off'), 'Coupon confirmation must show discount summary.');
	assert(couponCreateHtml.includes('Type: Percentage discount'), 'Coupon confirmation must show discount type.');
	assert(couponCreateHtml.includes('Expires: 2026-07-01'), 'Coupon confirmation must show expiry date.');
	assert(couponCreateHtml.includes('Approve change'), 'Coupon confirmation must render approve action.');
	assert(!couponCreateHtml.includes('Got it. I’ll create coupon SUMMER10'), 'Coupon confirmation must not repeat assistant prose above the structured card.');

	const sparseCouponCreateHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-sparse-coupon-create',
		parsed: {
			action: 'coupons.create',
			params: {
				coupon_code: 'YORO-QA-01',
				discount_type: 'percent',
				discount_amount: 10,
				expiry_date: '2026-07-30'
			}
		},
		result: {
			display_type: 'confirmation',
			action: 'coupons.create',
			params: {
				coupon_code: 'YORO-QA-01',
				discount_type: 'percent',
				discount_amount: 10,
				expiry_date: '2026-07-30'
			},
			requires_confirmation: true
		}
	});

	assert(sparseCouponCreateHtml.includes('Coupon code: YORO-QA-01'), 'Sparse coupon confirmation must render without optional display labels.');
	assert(sparseCouponCreateHtml.includes('Discount: 10% off'), 'Sparse coupon confirmation must preserve the percentage.');
	assert(sparseCouponCreateHtml.includes('Type: percent'), 'Sparse coupon confirmation must derive a safe fallback label.');

	const couponResultHtml = instance.renderResult({
		success: true,
		stage: 'done',
		result: {
			success: true,
			action: 'coupons.lookup',
			result_type: 'coupon',
			display_type: 'coupon',
			summary: 'Found coupon SUMMER10: 10.00% off.',
			read_only: true,
			coupon: {
				coupon_id: 321,
				coupon_code: 'SUMMER10',
				edit_url: 'https://example.test/wp-admin/post.php?post=321&action=edit',
				discount_type: 'percent',
				discount_type_label: 'Percentage discount',
				discount_amount: 10,
				discount_summary: '10.00% off',
				usage_count: 2,
				usage_limit: 50,
				usage_limit_per_user: 1,
				free_shipping: true
			}
		}
	});

	assert(couponResultHtml.includes('Coupon SUMMER10'), 'Coupon result must render a coupon-specific card title.');
	assert(couponResultHtml.includes('Coupon ID: 321'), 'Coupon result must show coupon id.');
	assert(couponResultHtml.includes('Discount: 10.00% off'), 'Coupon result must show discount summary.');
	assert(couponResultHtml.includes('Usage: 2 / 50'), 'Coupon result must show usage count and limit.');
	assert(couponResultHtml.includes('Per customer limit: 1'), 'Coupon result must show per-customer usage limit.');
	assert(couponResultHtml.includes('Flags: Free shipping'), 'Coupon result must show coupon flags.');
	assert(couponResultHtml.includes('Edit coupon'), 'Coupon result must render edit coupon action when edit_url is available.');

	const templateDeleteHtml = instance.renderResult({
		success: true,
		stage: 'confirm',
		confirm_required: true,
		requires_confirmation: true,
		pending_operation_active: true,
		pending_operation_token: 'pending-template-delete',
		parsed: {
			action: 'email_templates.delete',
			params: {
				template_id: 'shipping_update'
			}
		},
		assistant: {
			message: 'I’ll delete the email template after approval.'
		},
		result: {
			display_type: 'confirmation',
			action: 'email_templates.delete',
			params: {
				template_id: 'shipping_update'
			},
			requires_confirmation: true,
			template_id: 'shipping_update',
			template: {
				name: 'Shipping update'
			}
		}
	});

	assert(templateDeleteHtml.includes('Template: Shipping update (shipping_update)'), 'Email template delete confirmation must show template name and id.');
	assert(templateDeleteHtml.includes('Approve change'), 'Email template delete confirmation must render approve action.');
	assert(!templateDeleteHtml.includes('I’ll delete the email template after approval.'), 'Email template delete confirmation must not repeat assistant prose above the structured card.');
}

if (failures.length) {
	failures.forEach((failure) => {
		console.error(`FAIL: ${failure}`);
	});
	process.exit(1);
}

console.log('YoRo result renderer confirmation smoke contract passed.');
