(function () {
	'use strict';

	const VALUE_LABELS = {
		physical_goods: 'Physical goods',
		digital_goods: 'Digital goods',
		mixed_goods: 'Mixed goods',
		subscription_possible: 'Subscriptions possible',
		likely_b2c: 'Likely B2C',
		likely_b2b: 'Likely B2B',
		b2c: 'B2C',
		b2b: 'B2B',
		out_of_stock: 'Out of stock',
		outofstock: 'Out of stock',
		on_backorder: 'On backorder',
		onbackorder: 'On backorder',
		in_stock: 'In stock',
		instock: 'In stock',
		status_only: 'Status only',
		quantity_tracked: 'Quantity tracked',
		unmanaged_stock: 'Unmanaged stock',
		pending: 'Pending payment',
		processing: 'Processing',
		'on-hold': 'On hold',
		on_hold: 'On hold',
		completed: 'Completed',
		cancelled: 'Cancelled',
		canceled: 'Cancelled',
		refunded: 'Refunded',
		failed: 'Failed',
		publish: 'Published',
		published: 'Published',
		total: 'Total',
		draft: 'Draft',
		private: 'Private',
		simple: 'Simple',
		variable: 'Variable',
		grouped: 'Grouped',
		external: 'External',
		woocommerce: 'WooCommerce',
		woocommerce_products: 'Store products',
		woocommerce_orders: 'Store orders',
		woocommerce_orders_sampled_by_revenue_status: 'Store orders sampled by revenue status',
		woocommerce_order_sample_without_customer_pii: 'Store order sample without customer PII'
	};

	const KEY_LABELS = {
		attention_order_age_hours: 'Attention order age',
		fulfillment_processing_age_hours: 'Fulfillment processing age',
		stale_customer_followup_hours: 'Stale customer follow-up',
		primary_product_groups: 'Primary product groups',
		top_categories_90d: 'Top categories (last 90 days)',
		top_products_90d: 'Top products (last 90 days)',
		product_counts: 'Product counts',
		product_types: 'Product types',
		fulfillment_model: 'Fulfillment model',
		customer_model: 'Customer model',
		commerce_mix: 'Commerce mix',
		data_sources: 'Data sources',
		stock_status: 'Stock status',
		out_of_stock: 'Out of stock',
		on_backorder: 'On backorder',
		low_stock: 'Low stock',
		open_order_count: 'Open orders',
		order_status_counts: 'Order status counts',
		age_buckets: 'Age buckets',
		default_thresholds: 'Default thresholds',
		site_locale: 'Site locale',
		admin_locale: 'Admin locale'
	};

	const LOCALE_LABELS = {
		en_US: 'English (US)',
		en_GB: 'English (UK)',
		vi: 'Vietnamese',
		vi_VN: 'Vietnamese (Vietnam)'
	};

	const COMPACT_TOKEN_LABELS = {
		outofstock: 'Out of stock',
		onbackorder: 'On backorder',
		instock: 'In stock',
		b2c: 'B2C',
		b2b: 'B2B',
		woocommerce: 'WooCommerce'
	};

	function normalizeToken(value) {
		return String(value || '')
			.trim()
			.replace(/^wc-/, '')
			.replace(/-/g, '_')
			.toLowerCase();
	}

	function titleCase(value) {
		const smallWords = { and: true, by: true, for: true, in: true, of: true, on: true, or: true, the: true, to: true, with: true };

		return String(value || '')
			.replace(/[_-]+/g, ' ')
			.replace(/([a-z])([A-Z])/g, '$1 $2')
			.replace(/\s+/g, ' ')
			.trim()
			.toLowerCase()
			.replace(/\b([a-z][a-z0-9]*)\b/g, function(word, token, offset) {
				if (offset > 0 && smallWords[token]) {
					return token;
				}
				if (/^b2[bc]$/.test(token)) {
					return token.toUpperCase();
				}
				return token.charAt(0).toUpperCase() + token.slice(1);
			});
	}

	function labelFor(value, options) {
		const raw = String(value || '').trim();
		const config = options && typeof options === 'object' ? options : {};
		const normalized = normalizeToken(raw);

		if (!raw) {
			return '';
		}

		if (Object.prototype.hasOwnProperty.call(LOCALE_LABELS, raw)) {
			return LOCALE_LABELS[raw];
		}

		if (Object.prototype.hasOwnProperty.call(VALUE_LABELS, raw)) {
			return VALUE_LABELS[raw];
		}

		if (Object.prototype.hasOwnProperty.call(VALUE_LABELS, normalized)) {
			return VALUE_LABELS[normalized];
		}

		if (config.key && Object.prototype.hasOwnProperty.call(KEY_LABELS, normalized)) {
			return KEY_LABELS[normalized];
		}

		if (/^[A-Z]{2,5}$/.test(raw)) {
			return raw;
		}

		if (/^[A-Za-z]+\/[A-Za-z_]+$/.test(raw)) {
			return raw.replace(/_/g, ' ');
		}

		if (/[_-]/.test(raw)) {
			return titleCase(raw);
		}

		return raw;
	}

	function keyLabel(value) {
		return labelFor(value, { key: true });
	}

	function formatText(value) {
		let text = String(value || '');

		if (!text) {
			return '';
		}

		const directLabel = /\s/.test(text) ? text : labelFor(text);
		if (directLabel !== text) {
			return directLabel;
		}

		Object.keys(LOCALE_LABELS).forEach(function(locale) {
			text = text.replace(new RegExp('\\b' + escapeRegExp(locale) + '\\b', 'g'), LOCALE_LABELS[locale]);
		});

		Object.keys(COMPACT_TOKEN_LABELS).forEach(function(token) {
			text = text.replace(new RegExp('\\b' + escapeRegExp(token) + '\\b', 'gi'), COMPACT_TOKEN_LABELS[token]);
		});

		text = text.replace(/\b[A-Za-z]+\/[A-Za-z_]+\b/g, function(match) {
			return labelFor(match);
		});

		text = text.replace(/\b[a-z][a-z0-9]{1,}(?:[_-][a-z0-9]{2,})+\b/gi, function(match) {
			return labelFor(match);
		});

		return text;
	}

	function formatFactPart(value) {
		const text = String(value || '').trim();
		const match = text.match(/^([A-Za-z][A-Za-z0-9_-]*)\s+(.+)$/);

		if (match) {
			const key = String(match[1] || '').trim();
			const rest = String(match[2] || '').trim();
			const normalized = normalizeToken(key);
			const shouldPromoteKey = /[_-]/.test(key)
				|| Object.prototype.hasOwnProperty.call(VALUE_LABELS, normalized)
				|| Object.prototype.hasOwnProperty.call(KEY_LABELS, normalized);

			if (shouldPromoteKey && rest) {
				return keyLabel(key) + ': ' + formatText(rest);
			}
		}

		return formatText(text);
	}

	function escapeRegExp(value) {
		return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	}

	window.YoRoAIDisplayLabels = {
		formatText: formatText,
		formatFactPart: formatFactPart,
		keyLabel: keyLabel,
		labelFor: labelFor
	};
})();
