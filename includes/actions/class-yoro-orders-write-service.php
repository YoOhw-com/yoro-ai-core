<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Write_Service {

	public static function creation_params( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'line_items' ] ) || ! is_array( $params['line_items'] ?? null ) || ! array_is_list( $params['line_items'] ) || count( $params['line_items'] ) < 1 || count( $params['line_items'] ) > 20 ) {
			return new WP_Error( 'order_creation_invalid_input', 'Supply 1–20 exact product IDs and quantities for one pending guest order.' );
		}
		$ids = [];
		foreach ( $params['line_items'] as $item ) {
			if ( ! is_array( $item ) || count( $item ) !== 2 || array_diff( array_keys( $item ), [ 'product_id', 'quantity' ] ) || ! is_int( $item['product_id'] ?? null ) || $item['product_id'] < 1 || $item['product_id'] > 9007199254740991 || ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 1 || $item['quantity'] > 1000 || isset( $ids[ $item['product_id'] ] ) ) {
				return new WP_Error( 'order_creation_invalid_input', 'Exact unique product IDs and integer quantities 1–1000 are required; extra fields are not allowed.' );
			}
			$ids[ $item['product_id'] ] = true;
		}
		return $params;
	}

	public static function preview_create( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		$valid = self::creation_params( $params );
		if ( is_wp_error( $valid ) ) { return $valid; }
		try {
			$decimals = wc_get_price_decimals();
			$currency = get_woocommerce_currency();
			if ( ! is_int( $decimals ) || $decimals < 0 || $decimals > 6 || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) { throw new RuntimeException(); }
			$config = [];
			foreach ( [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_default_country', 'woocommerce_store_postcode', 'woocommerce_store_city', 'woocommerce_tax_round_at_subtotal', 'woocommerce_tax_display_shop', 'woocommerce_default_customer_address' ] as $key ) { $config[ $key ] = get_option( $key, '' ); }
			$lines = [];
			foreach ( $params['line_items'] as $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( ! $product instanceof WC_Product || ! $product->is_type( [ 'simple', 'variation' ] ) || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $item['quantity'] ) || ( $product->is_sold_individually() && 1 !== $item['quantity'] ) ) { throw new RuntimeException(); }
				$price = $product->get_price();
				$estimate = wc_get_price_to_display( $product, [ 'qty' => $item['quantity'] ] );
				if ( ! is_numeric( $price ) || ! is_finite( (float) $price ) || (float) $price < 0 || ! is_numeric( $estimate ) || ! is_finite( (float) $estimate ) || $estimate < 0 ) { throw new RuntimeException(); }
				$modified = $product->get_date_modified();
				$lines[] = [ 'product_id' => $item['product_id'], 'quantity' => $item['quantity'], 'parent_id' => $product->get_parent_id(), 'type' => $product->get_type(),
					'name' => wp_strip_all_tags( $product->get_name() ), 'price' => (string) $price, 'regular_price' => (string) $product->get_regular_price(), 'sale_price' => (string) $product->get_sale_price(),
					'estimated_catalog_amount' => wc_format_decimal( $estimate, $decimals ), 'tax_status' => $product->get_tax_status(), 'tax_class' => $product->get_tax_class(),
					'base_tax_rates' => WC_Tax::get_base_tax_rates( $product->get_tax_class() ), 'modified' => $modified ? $modified->getTimestamp() : null,
					'stock_status' => $product->get_stock_status(), 'stock_quantity' => $product->get_stock_quantity(), 'backorders' => $product->get_backorders(), 'sold_individually' => $product->is_sold_individually() ];
			}
			$message = 'Estimate only, not final order totals. Create one unpaid pending guest order; no money is sent. No billing/shipping identity, shipping, fees, coupons, custom prices or stock action are supplied by YoRo. Native Woo/plugin hooks may run, including native request metadata. An unknown outcome requires reconciliation, never retry or automatic deletion.';
			return [ 'type' => 'write', 'will_execute' => false, 'changes_store' => true, 'blocked' => false, 'estimate' => true, 'final' => false,
				'blog_id' => get_current_blog_id(), 'currency' => $currency, 'decimals' => $decimals, 'tax_enabled' => wc_tax_enabled(), 'prices_include_tax' => wc_prices_include_tax(),
				'rounding_precision' => wc_get_rounding_precision(), 'tax_rounding_mode' => WC_TAX_ROUNDING_MODE, 'config' => $config, 'line_items' => $lines,
				'customer_id' => 0, 'status' => 'pending', 'message' => $message, 'summary' => $message ];
		} catch ( Throwable $error ) { return new WP_Error( 'order_creation_preview_unavailable', 'The exact current catalog estimate is unavailable. Refresh the products and preview; nothing was created.' ); }
	}

	public static function create( $params, $approved = null ) {
		if ( ! is_array( $approved ) || ! is_array( $approved['plan'] ?? null ) || ! is_string( $approved['preview_hash'] ?? null ) ) { return new WP_Error( 'order_creation_permit_required', 'A freshly claimed local displayed-preview approval is required.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		$valid = self::creation_params( $params );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$plan = $approved['plan'];
		if ( 'single' !== ( $plan['type'] ?? '' ) || 'orders.create' !== ( $plan['action'] ?? '' ) || ( $plan['params'] ?? null ) !== $params ) { return new WP_Error( 'order_creation_approval_mismatch', 'The approved order parameters no longer match.' ); }
		$preflight = YoRo_Safety_Contract::preflight( $plan );
		$quote = $preflight['preview']['operations'][0]['preview'] ?? [];
		if ( true !== ( $quote['estimate'] ?? false ) || ! hash_equals( $approved['preview_hash'], $preflight['preview_hash'] ) ) { return new WP_Error( 'order_creation_preview_stale', 'The catalog estimate changed. Review a fresh preview before creating a new operation.' ); }
		$facts = [ 'order_id' => null, 'status' => 'reconciliation_required', 'outcome_unknown' => true, 'retry_allowed' => false, 'payment_initiated' => false, 'customer_id' => 0, 'currency' => $quote['currency'], 'subtotal' => null, 'tax' => null, 'total' => null ];
		try {
			$order = wc_create_order( [ 'status' => 'pending', 'customer_id' => 0 ] );
			if ( is_wp_error( $order ) || ! $order instanceof WC_Order || $order->get_id() < 1 ) { throw new RuntimeException(); }
			$facts['order_id'] = $order->get_id();
			foreach ( $params['line_items'] as $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( ! $product instanceof WC_Product || ! $order->add_product( $product, $item['quantity'] ) ) { throw new RuntimeException(); }
			}
			$order->calculate_totals();
			if ( ! $order->save() ) { throw new RuntimeException(); }
			$reloaded = wc_get_order( $facts['order_id'] );
			if ( ! $reloaded instanceof WC_Order || $reloaded->get_id() !== $facts['order_id'] || wc_get_price_decimals() !== $quote['decimals'] ) { throw new RuntimeException(); }
			// Observe view hooks, then prove they agree with reloaded owner storage facts.
			foreach ( [ 'get_status' => 'pending', 'get_customer_id' => 0, 'get_currency' => $quote['currency'], 'get_payment_method' => '', 'get_transaction_id' => '', 'get_date_paid' => null ] as $getter => $expected ) {
				if ( $reloaded->$getter( 'edit' ) !== $expected || $reloaded->$getter() !== $expected ) { throw new RuntimeException(); }
			}
			$stored_lines = []; $observed_lines = [];
			foreach ( [ 'line_item', 'shipping', 'fee', 'coupon' ] as $type ) {
				$stored = $reloaded->get_data_store()->read_items( $reloaded, $type );
				$observed = $reloaded->get_items( $type );
				if ( ! is_array( $stored ) || array_keys( $stored ) !== array_keys( $observed ) || ( 'line_item' !== $type && $stored ) ) { throw new RuntimeException(); }
				if ( 'line_item' === $type ) { $stored_lines = $stored; $observed_lines = $observed; }
			}
			$actual = []; $subtotal_values = [];
			foreach ( $stored_lines as $id => $line ) {
				$observed = $observed_lines[ $id ];
				foreach ( [ 'get_product_id', 'get_variation_id', 'get_quantity', 'get_subtotal' ] as $getter ) {
					if ( $line->$getter( 'edit' ) !== $observed->$getter() ) { throw new RuntimeException(); }
				}
				$quantity = $line->get_quantity( 'edit' );
				if ( (float) $quantity !== (float) (int) $quantity ) { throw new RuntimeException(); }
				$actual[] = [ 'product_id' => $line->get_variation_id( 'edit' ) ?: $line->get_product_id( 'edit' ), 'quantity' => (int) $quantity ];
				$subtotal_values[] = wc_add_number_precision( (float) $line->get_subtotal( 'edit' ), false );
			}
			if ( $actual !== $params['line_items'] ) { throw new RuntimeException(); }
			// The same public Woo aggregation/rounding used by get_subtotal, over stored line values.
			$stored_subtotal = \Automattic\WooCommerce\Utilities\NumberUtil::round( wc_remove_number_precision( WC_Order::get_rounded_items_total( $subtotal_values ) ), $quote['decimals'] );
			$stored_money = [ 'subtotal' => $stored_subtotal, 'tax' => $reloaded->get_total_tax( 'edit' ), 'total' => $reloaded->get_total( 'edit' ) ];
			$view_money = [ 'subtotal' => $reloaded->get_subtotal(), 'tax' => $reloaded->get_total_tax(), 'total' => $reloaded->get_total() ];
			$verified_money = [];
			foreach ( $stored_money as $key => $amount ) {
				$view = $view_money[ $key ];
				if ( ! is_numeric( $amount ) || ! is_finite( (float) $amount ) || (float) $amount < 0 || ! is_numeric( $view ) || ! is_finite( (float) $view ) || (float) $view < 0 || wc_format_decimal( $amount, $quote['decimals'] ) !== wc_format_decimal( $view, $quote['decimals'] ) ) { throw new RuntimeException(); }
				$verified_money[ $key ] = wc_format_decimal( $amount, $quote['decimals'] );
			}
			$facts = array_merge( $facts, $verified_money );
			$facts['status'] = 'pending'; $facts['outcome_unknown'] = false;
		} catch ( Throwable $error ) { /* A durable identity may already exist; never retry or undo creation. */ }
		$message = $facts['outcome_unknown'] ? 'Order creation outcome is unknown. Reconcile the store before any new confirmation; do not retry or delete automatically. No payment was initiated.' : 'One unpaid pending guest order was created. These are final persisted order amounts, not the earlier estimate. No payment was initiated. Native Woo/plugin hooks may have run.';
		return [ 'success' => ! $facts['outcome_unknown'], 'action' => 'orders.create', 'order_creation' => $facts, 'data' => [ 'order_creation' => $facts ], 'summary' => $message, 'message' => $message, 'read_only' => false, 'undoable' => false, 'result_type' => 'order_creation', 'display_type' => 'order_creation' ];
	}

	public static function update_items_params( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'line_items' ] ) || ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 || $params['order_id'] > 9007199254740991 || ! is_array( $params['line_items'] ?? null ) || ! array_is_list( $params['line_items'] ) || count( $params['line_items'] ) < 1 || count( $params['line_items'] ) > 20 ) { return new WP_Error( 'order_item_update_invalid_input', 'Supply one order ID and 1–20 exact desired product quantities.' ); }
		$ids = [];
		foreach ( $params['line_items'] as $item ) {
			if ( ! is_array( $item ) || count( $item ) !== 2 || array_diff( array_keys( $item ), [ 'product_id', 'quantity' ] ) || ! is_int( $item['product_id'] ?? null ) || $item['product_id'] < 1 || $item['product_id'] > 9007199254740991 || ! is_int( $item['quantity'] ?? null ) || $item['quantity'] < 1 || $item['quantity'] > 1000 || isset( $ids[ $item['product_id'] ] ) ) { return new WP_Error( 'order_item_update_invalid_input', 'Desired products must be unique integer IDs with quantities 1–1000; extra fields are not allowed.' ); }
			$ids[ $item['product_id'] ] = true;
		}
		return $params;
	}

	private static function update_fulfillment_guard( $order_id, $line_map ) {
		$observation = YoRo_Fulfillment_Service::observations( $order_id );
		if ( ! is_array( $observation ) || 'available' !== ( $observation['native_status'] ?? null ) || [] !== ( $observation['native_fulfillments'] ?? null ) || ! is_string( $observation['allocation_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $observation['allocation_version'] ) || ! is_array( $observation['pending_items'] ?? null ) || count( $observation['pending_items'] ) !== count( $line_map ) ) { throw new RuntimeException(); }
		$pending = [];
		foreach ( $observation['pending_items'] as $item ) {
			if ( ! is_array( $item ) || array_keys( $item ) !== [ 'item_id', 'qty' ] || ! is_int( $item['item_id'] ) || $item['item_id'] < 1 || ! is_int( $item['qty'] ) || $item['qty'] < 1 || isset( $pending[ $item['item_id'] ] ) ) { throw new RuntimeException(); }
			$pending[ $item['item_id'] ] = $item['qty'];
		}
		ksort( $pending, SORT_NUMERIC ); ksort( $line_map, SORT_NUMERIC );
		if ( $pending !== $line_map ) { throw new RuntimeException(); }
		return [ 'native_status' => $observation['native_status'], 'native_fulfillments' => $observation['native_fulfillments'], 'allocation_version' => $observation['allocation_version'], 'pending_items' => $observation['pending_items'] ];
	}

	private static function update_fixed_boundary( $order ) {
		return $order instanceof WC_Order && ! $order instanceof WC_Order_Refund && 'pending' === $order->get_status( 'edit' ) && 0 === $order->get_customer_id( 'edit' ) && '' === $order->get_payment_method( 'edit' ) && '' === $order->get_transaction_id( 'edit' ) && null === $order->get_date_paid( 'edit' ) && ! array_filter( (array) $order->get_address( 'billing' ) ) && ! array_filter( (array) $order->get_address( 'shipping' ) ) && ! $order->get_items( 'shipping' ) && ! $order->get_items( 'fee' ) && ! $order->get_items( 'coupon' ) && ! wc_get_order_notes( [ 'order_id' => $order->get_id(), 'type' => 'customer', 'limit' => 1 ] ) && ! $order->get_refunds();
	}

	private static function update_snapshot( $params ) {
		$valid = self::update_items_params( $params ); if ( is_wp_error( $valid ) ) { return $valid; }
		$order = wc_get_order( $params['order_id'] );
		if ( ! self::update_fixed_boundary( $order ) ) { return new WP_Error( 'order_item_update_ineligible', 'Only an unpaid pending guest order without identity, shipping, fees or coupons is eligible.' ); }
		$lines = []; $map = []; $by_product = []; $desired_products = [];
		foreach ( $order->get_items( 'line_item' ) as $id => $item ) {
			$product_id = $item->get_variation_id( 'edit' ) ?: $item->get_product_id( 'edit' ); $qty = $item->get_quantity( 'edit' );
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $product_id ) || $product_id < 1 || ! is_numeric( $qty ) || (int) $qty < 1 || (float) $qty !== (float) (int) $qty || isset( $by_product[ $product_id ] ) || $item->get_meta_data() ) { return new WP_Error( 'order_item_update_ineligible', 'The current order has unsupported line identities or custom item metadata.' ); }
			$product = wc_get_product( $product_id ); $unit = (float) $item->get_subtotal( 'edit' ) / (int) $qty;
			if ( ! $product instanceof WC_Product || ! is_finite( $unit ) || $unit < 0 ) { return new WP_Error( 'order_item_update_unavailable', 'Current order products cannot be verified.' ); }
			$lines[] = [ 'line_item_id' => $id, 'product_id' => $product_id, 'quantity' => (int) $qty, 'subtotal' => (string) $item->get_subtotal( 'edit' ) ]; $map[ $id ] = (int) $qty; $by_product[ $product_id ] = end( $lines );
		}
		if ( ! $lines ) { return new WP_Error( 'order_item_update_ineligible', 'An existing non-empty order is required.' ); }
		$guard = self::update_fulfillment_guard( $order->get_id(), $map ); $changes = [];
		foreach ( $params['line_items'] as $wanted ) {
			$current = $by_product[ $wanted['product_id'] ] ?? null; $product = wc_get_product( $wanted['product_id'] );
			if ( ! $product instanceof WC_Product || ! $product->is_type( [ 'simple', 'variation' ] ) || ! $product->is_purchasable() || ! $product->is_in_stock() || ! $product->has_enough_stock( $wanted['quantity'] ) || ( $product->is_sold_individually() && 1 !== $wanted['quantity'] ) ) { return new WP_Error( 'order_item_update_unavailable', 'A desired product is unavailable for this exact preview.' ); }
			if ( $current && $current['quantity'] !== $wanted['quantity'] && wc_format_decimal( (float) $current['subtotal'] / $current['quantity'], wc_get_price_decimals() ) !== wc_format_decimal( $product->get_price(), wc_get_price_decimals() ) ) { return new WP_Error( 'order_item_update_price_drift', 'A replacement line no longer has catalog/persisted unit-price parity.' ); }
			$estimate = wc_get_price_to_display( $product, [ 'qty' => $wanted['quantity'] ] );
			if ( ! is_numeric( $product->get_price() ) || ! is_finite( (float) $product->get_price() ) || ! is_numeric( $estimate ) || ! is_finite( (float) $estimate ) || (float) $estimate < 0 ) { return new WP_Error( 'order_item_update_unavailable', 'Current product pricing cannot be verified.' ); }
			$modified = $product->get_date_modified();
			$desired_products[] = [ 'product_id' => $wanted['product_id'], 'parent_id' => $product->get_parent_id(), 'type' => $product->get_type(), 'price' => (string) $product->get_price(), 'estimated_catalog_amount' => wc_format_decimal( $estimate, wc_get_price_decimals() ), 'tax_status' => $product->get_tax_status(), 'tax_class' => $product->get_tax_class(), 'modified' => $modified ? $modified->getTimestamp() : null, 'stock_status' => $product->get_stock_status(), 'stock_quantity' => $product->get_stock_quantity(), 'backorders' => $product->get_backorders(), 'sold_individually' => $product->is_sold_individually() ];
			$changes[] = [ 'product_id' => $wanted['product_id'], 'quantity' => $wanted['quantity'], 'kind' => ! $current ? 'added' : ( $current['quantity'] === $wanted['quantity'] ? 'unchanged' : 'quantity_replaced' ), 'old_line_item_id' => $current['line_item_id'] ?? null ];
		}
		$wanted_product_ids = array_column( $params['line_items'], 'product_id' );
		foreach ( $lines as $line ) { if ( ! in_array( $line['product_id'], $wanted_product_ids, true ) ) { $changes[] = [ 'product_id' => $line['product_id'], 'quantity' => 0, 'kind' => 'removed', 'old_line_item_id' => $line['line_item_id'] ]; } }
		$config = []; foreach ( [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_default_country', 'woocommerce_store_postcode', 'woocommerce_store_city', 'woocommerce_tax_round_at_subtotal', 'woocommerce_tax_display_shop', 'woocommerce_default_customer_address' ] as $key ) { $config[ $key ] = get_option( $key, '' ); }
		return [ 'order' => $order, 'lines' => $lines, 'guard' => $guard, 'changes' => $changes, 'desired_products' => $desired_products, 'currency' => $order->get_currency( 'edit' ), 'decimals' => wc_get_price_decimals(), 'tax_enabled' => wc_tax_enabled(), 'prices_include_tax' => wc_prices_include_tax(), 'rounding_precision' => wc_get_rounding_precision(), 'tax_rounding_mode' => WC_TAX_ROUNDING_MODE, 'config' => $config, 'subtotal' => $order->get_subtotal( 'edit' ), 'tax' => $order->get_total_tax( 'edit' ), 'total' => $order->get_total( 'edit' ) ];
	}

	private static function update_preview_payload( $snapshot, $params ) {
		$message = 'This local preview changes one pending guest order only. Quantity changes replace the owner line ID. Native fulfillment must remain wholly pending/unallocated. Final totals are determined by WooCommerce; no payment, fulfillment, stock or customer message is initiated.';
		return [ 'type' => 'write', 'will_execute' => false, 'changes_store' => true, 'blocked' => false, 'estimate' => true, 'final' => false, 'order_id' => $params['order_id'], 'current_line_items' => $snapshot['lines'], 'desired_line_items' => $params['line_items'], 'desired_product_facts' => $snapshot['desired_products'], 'changes' => $snapshot['changes'], 'currency' => $snapshot['currency'], 'decimals' => $snapshot['decimals'], 'tax_enabled' => $snapshot['tax_enabled'], 'prices_include_tax' => $snapshot['prices_include_tax'], 'rounding_precision' => $snapshot['rounding_precision'], 'tax_rounding_mode' => $snapshot['tax_rounding_mode'], 'config' => $snapshot['config'], 'current_subtotal' => $snapshot['subtotal'], 'current_tax' => $snapshot['tax'], 'current_total' => $snapshot['total'], 'fulfillment_observation' => $snapshot['guard'], 'message' => $message, 'summary' => $message ];
	}

	public static function preview_update_items( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		try { $snapshot = self::update_snapshot( $params ); if ( is_wp_error( $snapshot ) ) { return [ 'type' => 'write', 'will_execute' => false, 'changes_store' => false, 'blocked' => true, 'block_reason' => sanitize_key( $snapshot->get_error_code() ), 'message' => $snapshot->get_error_message(), 'summary' => $snapshot->get_error_message() ]; }
			return self::update_preview_payload( $snapshot, $params );
		} catch ( Throwable $e ) { return [ 'type' => 'write', 'will_execute' => false, 'changes_store' => false, 'blocked' => true, 'block_reason' => 'order_item_update_preview_unavailable', 'message' => 'The exact eligible order and native fulfillment state could not be verified. Nothing changed.', 'summary' => 'The exact eligible order and native fulfillment state could not be verified. Nothing changed.' ]; }
	}

	public static function update_items( $params, $approved = null ) {
		if ( ! is_array( $approved ) || ! is_array( $approved['plan'] ?? null ) || ! is_string( $approved['preview_hash'] ?? null ) ) { return new WP_Error( 'order_item_update_permit_required', 'A freshly claimed local displayed-preview approval is required.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] ); $plan = $approved['plan'];
		if ( 'single' !== ( $plan['type'] ?? '' ) || 'orders.update_items' !== ( $plan['action'] ?? '' ) || ( $plan['params'] ?? null ) !== $params ) { return new WP_Error( 'order_item_update_approval_mismatch', 'The approved update parameters no longer match.' ); }
		$preflight = YoRo_Safety_Contract::preflight( $plan ); if ( ! hash_equals( $approved['preview_hash'], $preflight['preview_hash'] ) ) { return new WP_Error( 'order_item_update_preview_stale', 'The displayed preview changed. Review a fresh preview.' ); }
		$facts = [ 'order_id' => $params['order_id'], 'status' => 'reconciliation_required', 'outcome_unknown' => true, 'retry_allowed' => false ]; $durable = false;
		try { $snapshot = self::update_snapshot( $params ); $approved_preview = $preflight['preview']['operations'][0]['preview'] ?? []; if ( is_wp_error( $snapshot ) || ! is_array( $approved_preview ) || YoRo_Safety_Contract::hash( self::update_preview_payload( $snapshot, $params ) ) !== YoRo_Safety_Contract::hash( $approved_preview ) ) { throw new RuntimeException(); }
			$order = $snapshot['order']; foreach ( $snapshot['changes'] as $change ) { if ( in_array( $change['kind'], [ 'removed', 'quantity_replaced' ], true ) ) { $order->remove_item( $change['old_line_item_id'] ); $durable = true; } }
			foreach ( $snapshot['changes'] as $change ) { if ( in_array( $change['kind'], [ 'added', 'quantity_replaced' ], true ) ) { $new_id = $order->add_product( wc_get_product( $change['product_id'] ), $change['quantity'] ); if ( ! $new_id ) { throw new RuntimeException(); } $durable = true; } }
			$order->calculate_totals(); if ( ! $order->save() ) { throw new RuntimeException(); } $reloaded = wc_get_order( $params['order_id'] ); if ( ! $reloaded instanceof WC_Order ) { throw new RuntimeException(); }
			$store = $reloaded->get_data_store(); $stored_items = $store->read_items( $reloaded, 'line_item' ); $view_items = $reloaded->get_items( 'line_item' ); if ( ! is_array( $stored_items ) || ! is_array( $view_items ) || count( $stored_items ) !== count( $view_items ) || $store->read_items( $reloaded, 'shipping' ) || $store->read_items( $reloaded, 'fee' ) || $store->read_items( $reloaded, 'coupon' ) ) { throw new RuntimeException(); }
			$final = []; $map = []; $stored_subtotal = 0.0; foreach ( $stored_items as $id => $item ) { if ( ! isset( $view_items[ $id ] ) ) { throw new RuntimeException(); } $product_id = $item->get_variation_id( 'edit' ) ?: $item->get_product_id( 'edit' ); $qty = (int) $item->get_quantity( 'edit' ); $subtotal = $item->get_subtotal( 'edit' ); $view = $view_items[ $id ]; if ( ! is_numeric( $subtotal ) || ! is_finite( (float) $subtotal ) || (float) $subtotal < 0 || $product_id !== ( $view->get_variation_id( 'edit' ) ?: $view->get_product_id( 'edit' ) ) || $qty !== (int) $view->get_quantity( 'edit' ) || wc_format_decimal( $subtotal ) !== wc_format_decimal( $view->get_subtotal( 'edit' ) ) ) { throw new RuntimeException(); } $final[] = [ 'product_id' => $product_id, 'quantity' => $qty ]; $map[ $id ] = $qty; $stored_subtotal += (float) $subtotal; }
			$stored_money = [ 'subtotal' => wc_format_decimal( $stored_subtotal, wc_get_price_decimals() ), 'tax' => $reloaded->get_total_tax( 'edit' ), 'total' => $reloaded->get_total( 'edit' ) ]; $final_money = [];
			foreach ( $stored_money as $key => $stored ) { $view = $key === 'subtotal' ? $reloaded->get_subtotal() : ( $key === 'tax' ? $reloaded->get_total_tax() : $reloaded->get_total() ); if ( ! is_numeric( $stored ) || ! is_finite( (float) $stored ) || (float) $stored < 0 || ! is_numeric( $view ) || ! is_finite( (float) $view ) || (float) $view < 0 || wc_format_decimal( $stored ) !== wc_format_decimal( $view ) ) { throw new RuntimeException(); } $final_money[ $key ] = wc_format_decimal( $stored ); }
			if ( $final !== $params['line_items'] || ! self::update_fixed_boundary( $reloaded ) ) { throw new RuntimeException(); } self::update_fulfillment_guard( $params['order_id'], $map ); $facts = [ 'order_id' => $params['order_id'], 'status' => 'updated', 'outcome_unknown' => false, 'retry_allowed' => false, 'line_items' => $final, 'currency' => $reloaded->get_currency( 'edit' ), 'subtotal' => $final_money['subtotal'], 'tax' => $final_money['tax'], 'total' => $final_money['total'] ];
		} catch ( Throwable $e ) { if ( ! $durable ) { return new WP_Error( 'order_item_update_not_applied', 'The order update was not applied; review a fresh preview.' ); } }
		$message = $facts['outcome_unknown'] ? 'Order item update outcome is unknown after a durable owner line write. Reconcile the order and native fulfillment state; do not retry, roll back or repair automatically.' : 'Pending guest order items were updated and the final native fulfillment pending map was verified.';
		return [ 'success' => ! $facts['outcome_unknown'], 'action' => 'orders.update_items', 'order_item_update' => $facts, 'data' => [ 'order_item_update' => $facts ], 'summary' => $message, 'message' => $message, 'read_only' => false, 'undoable' => false, 'result_type' => 'order_item_update', 'display_type' => 'order_item_update' ];
	}


	private static function prepare_refund( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'amount', 'reason', 'expected_version', 'expected_refund_state', '_yoro_confirmed', '_yoro_preview' ] ) || ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 || $params['order_id'] > 9007199254740991 ) { return new WP_Error( 'refund_invalid_input', 'One exact order and closed refund parameters are required.' ); }
		foreach ( [ 'expected_version', 'expected_refund_state' ] as $field ) {
			if ( ! is_string( $params[ $field ] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params[ $field ] ) ) { return new WP_Error( 'refund_invalid_input', 'An authenticated fresh refund preview is required.' ); }
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) { return new WP_Error( 'refund_invalid_input', 'Invalid execution flags.' ); }
		}
		if ( ! is_string( $params['amount'] ?? null ) ) { return new WP_Error( 'refund_invalid_input', 'Amount must be an exact decimal string.' ); }
		try {
			$reason = $params['reason'] ?? '';
			if ( ( array_key_exists( 'reason', $params ) && ! is_string( $params['reason'] ) ) || ! mb_check_encoding( $reason, 'UTF-8' ) || mb_strlen( $reason ) > 500 || preg_match( '/[\x00-\x1f\x7f]/', $reason ) || wp_strip_all_tags( $reason ) !== $reason ) { throw new RuntimeException(); }
			$state = YoRo_Orders_Read_Service::refund_execution_state( $params['order_id'] );
			$guard = $state['guard'];
			if ( ! $guard['eligible'] || ! hash_equals( $guard['current_version'], $params['expected_version'] ) || ! hash_equals( $guard['state_token'], $params['expected_refund_state'] ) ) { return new WP_Error( 'refund_guard_conflict', 'Read a fresh eligible refund preview and confirm again.' ); }
			$units = YoRo_Orders_Read_Service::refund_money_units( $params['amount'] ?? null, $state['decimals'] );
			if ( $units < 1 || $units > $state['total'] - $state['refunded'] ) { throw new RuntimeException(); }
			return [ 'state' => $state, 'amount_units' => $units, 'amount' => YoRo_Orders_Read_Service::refund_money_decimal( $units, $state['decimals'] ), 'reason' => trim( $reason ) ];
		} catch ( Throwable $error ) { return new WP_Error( 'refund_invalid_input', 'Supply a bounded positive exact amount within the fresh refundable balance and a plain-text reason.' ); }
	}

	public static function preview_refund( $params ) {
		$prepared = self::prepare_refund( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$s = $prepared['state'];
		$message = 'Send this amount back through the order payment gateway. WooCommerce creates a refund record and may trigger standard refund hooks and customer notifications; a full refund may change the order status to refunded. No stock will be restocked. Reconcile any unknown outcome with refund history and the payment gateway before a new refund; never retry this operation.';
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => false, 'changes_store' => true,
			'order_id' => $s['guard']['order_id'], 'amount' => $prepared['amount'], 'currency' => $s['currency'], 'reason' => $prepared['reason'],
			'total_refunded' => YoRo_Orders_Read_Service::refund_money_decimal( $s['refunded'], $s['decimals'] ),
			'remaining_after_refund' => YoRo_Orders_Read_Service::refund_money_decimal( $s['total'] - $s['refunded'] - $prepared['amount_units'], $s['decimals'] ),
			'payment_method' => $s['payment_method'], 'restock_items' => false, 'message' => $message, 'summary' => $message ];
	}

	public static function refund( $params ) {
		if ( true !== ( $params['_yoro_confirmed'] ?? false ) ) { return new WP_Error( 'confirmation_required', 'Explicit claimed confirmation is required.' ); }
		$prepared = self::prepare_refund( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$s = $prepared['state']; $id = $s['guard']['order_id'];
		$facts = [ 'order_id' => $id, 'refund_id' => null, 'amount' => $prepared['amount'], 'currency' => $s['currency'], 'status' => 'reconciliation_required', 'outcome_unknown' => true, 'refunded_payment' => null, 'restocked' => false, 'retry_allowed' => false, 'current_version' => null, 'next_action' => 'orders.get_refunds' ];
		try {
			$refund = wc_create_refund( [ 'order_id' => $id, 'amount' => $prepared['amount'], 'reason' => $prepared['reason'], 'line_items' => [], 'refund_payment' => true, 'restock_items' => false ] );
			if ( ! $refund instanceof WC_Order_Refund || $refund->get_id() < 1 || get_current_blog_id() !== $s['blog'] ) { throw new RuntimeException(); }
			$refund = wc_get_order( $refund->get_id() );
			$after = YoRo_Orders_Read_Service::refund_execution_state( $id );
			if ( ! $refund instanceof WC_Order_Refund || $refund->get_parent_id() !== $id || true !== $refund->get_refunded_payment() || $refund->get_currency() !== $s['currency']
				|| YoRo_Orders_Read_Service::refund_money_units( $refund->get_amount(), $s['decimals'] ) !== $prepared['amount_units']
				|| ! isset( $after['total'] ) || $after['blog'] !== $s['blog'] || $after['currency'] !== $s['currency'] || $after['decimals'] !== $s['decimals'] || $after['total'] !== $s['total'] || $after['refunded'] !== $s['refunded'] + $prepared['amount_units'] ) { throw new RuntimeException(); }
			$expected = $s['refunds']; $expected[] = [ $refund->get_id(), $prepared['amount_units'], true ];
			$actual = $after['refunds']; sort( $expected ); sort( $actual );
			if ( $expected !== $actual ) { throw new RuntimeException(); }
			$facts['refund_id'] = $refund->get_id(); $facts['status'] = 'refunded'; $facts['outcome_unknown'] = false; $facts['refunded_payment'] = true;
			$facts['current_version'] = $after['guard']['current_version']; $facts['next_action'] = null;
			return [ 'success' => true, 'action' => 'orders.refund', 'refund' => $facts, 'read_only' => false, 'undoable' => false, 'message' => 'WooCommerce gateway refund completed and the local refund record was verified.' ];
		} catch ( Throwable $error ) {
			return [ 'success' => false, 'action' => 'orders.refund', 'refund' => $facts, 'read_only' => false, 'undoable' => false, 'message' => 'Refund outcome is unknown. Do not retry. Reconcile fresh refund history and preview with the payment gateway transaction view before a new confirmation.' ];
		}
	}

	private static function prepare_external_refund( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'amount', 'reason', 'expected_version', 'expected_external_refund_state', '_yoro_confirmed', '_yoro_preview' ] ) || ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 || $params['order_id'] > 9007199254740991 ) { return new WP_Error( 'external_refund_invalid_input', 'One exact order and closed refund parameters are required.' ); }
		foreach ( [ 'expected_version', 'expected_external_refund_state' ] as $field ) {
			if ( ! is_string( $params[ $field ] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params[ $field ] ) ) { return new WP_Error( 'external_refund_invalid_input', 'An authenticated fresh refund preview is required.' ); }
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) { return new WP_Error( 'external_refund_invalid_input', 'Invalid execution flags.' ); }
		}
		if ( ! is_string( $params['amount'] ?? null ) ) { return new WP_Error( 'external_refund_invalid_input', 'Amount must be an exact decimal string.' ); }
		try {
			$reason = $params['reason'] ?? '';
			if ( ( array_key_exists( 'reason', $params ) && ! is_string( $params['reason'] ) ) || ! mb_check_encoding( $reason, 'UTF-8' ) || mb_strlen( $reason ) > 500 || preg_match( '/[\x00-\x1f\x7f]/', $reason ) || wp_strip_all_tags( $reason ) !== $reason ) { throw new RuntimeException(); }
			$state = YoRo_Orders_Read_Service::external_refund_recording_state( $params['order_id'] );
			$guard = $state['guard'];
			if ( ! $guard['eligible'] || ! hash_equals( $guard['current_version'], $params['expected_version'] ) || ! hash_equals( $guard['state_token'], $params['expected_external_refund_state'] ) ) { return new WP_Error( 'external_refund_guard_conflict', 'Read a fresh eligible refund preview and confirm again.' ); }
			$units = YoRo_Orders_Read_Service::refund_money_units( $params['amount'] ?? null, $state['decimals'] );
			if ( $units < 1 || $units > $state['total'] - $state['refunded'] ) { throw new RuntimeException(); }
			return [ 'state' => $state, 'amount_units' => $units, 'amount' => YoRo_Orders_Read_Service::refund_money_decimal( $units, $state['decimals'] ), 'reason' => trim( $reason ) ];
		} catch ( Throwable $error ) { return new WP_Error( 'external_refund_invalid_input', 'Supply a bounded positive exact amount within the fresh refundable balance and a plain-text reason.' ); }
	}

	public static function preview_record_external_refund( $params ) {
		$prepared = self::prepare_external_refund( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$s = $prepared['state'];
		$message = 'Your confirmation asserts the external refund already happened. YoRo sends no money and does not observe or independently verify that transfer. This action creates only a local WooCommerce refund record; standard owner hooks and notifications may run, and a full refund may change order status. No stock or item allocation changes. Reconcile unknown local outcomes before a fresh confirmation; do not retry this operation.';
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => false, 'changes_store' => true,
			'order_id' => $s['guard']['order_id'], 'amount' => $prepared['amount'], 'currency' => $s['currency'], 'reason' => $prepared['reason'],
			'total_refunded' => YoRo_Orders_Read_Service::refund_money_decimal( $s['refunded'], $s['decimals'] ),
			'remaining_after_refund' => YoRo_Orders_Read_Service::refund_money_decimal( $s['total'] - $s['refunded'] - $prepared['amount_units'], $s['decimals'] ),
			'payment_transfer_initiated' => false, 'external_transfer_verification' => 'not_observed', 'restock_items' => false, 'message' => $message, 'summary' => $message ];
	}

	public static function record_external_refund( $params ) {
		if ( true !== ( $params['_yoro_confirmed'] ?? false ) ) { return new WP_Error( 'confirmation_required', 'Explicit claimed confirmation is required.' ); }
		$prepared = self::prepare_external_refund( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$s = $prepared['state']; $id = $s['guard']['order_id'];
		if ( get_current_blog_id() !== $s['blog'] ) { return new WP_Error( 'external_refund_guard_conflict', 'The store context changed before recording. Read a fresh preview.' ); }
		$facts = [ 'order_id' => $id, 'refund_id' => null, 'amount' => $prepared['amount'], 'currency' => $s['currency'], 'status' => 'reconciliation_required', 'outcome_unknown' => true, 'refunded_payment' => null, 'payment_transfer_initiated' => false, 'external_transfer_verification' => 'not_observed', 'restocked' => false, 'retry_allowed' => false, 'current_version' => null, 'next_action' => 'orders.get_refunds' ];
		try {
			$refund = wc_create_refund( [ 'order_id' => $id, 'amount' => $prepared['amount'], 'reason' => $prepared['reason'], 'line_items' => [], 'refund_payment' => false, 'restock_items' => false ] );
			if ( ! $refund instanceof WC_Order_Refund || $refund->get_id() < 1 || get_current_blog_id() !== $s['blog'] ) { throw new RuntimeException(); }
			$refund = wc_get_order( $refund->get_id() );
			$after = YoRo_Orders_Read_Service::external_refund_recording_state( $id );
			if ( ! $refund instanceof WC_Order_Refund || $refund->get_parent_id() !== $id || false !== $refund->get_refunded_payment() || $refund->get_currency() !== $s['currency']
				|| YoRo_Orders_Read_Service::refund_money_units( $refund->get_amount(), $s['decimals'] ) !== $prepared['amount_units']
				|| ! isset( $after['total'] ) || $after['blog'] !== $s['blog'] || $after['currency'] !== $s['currency'] || $after['decimals'] !== $s['decimals'] || $after['total'] !== $s['total'] || $after['refunded'] !== $s['refunded'] + $prepared['amount_units'] ) { throw new RuntimeException(); }
			$expected = $s['refunds']; $expected[] = [ $refund->get_id(), $prepared['amount_units'], false ];
			$actual = $after['refunds']; sort( $expected ); sort( $actual );
			if ( $expected !== $actual ) { throw new RuntimeException(); }
			$facts['refund_id'] = $refund->get_id(); $facts['status'] = 'recorded'; $facts['outcome_unknown'] = false; $facts['refunded_payment'] = false;
			$facts['current_version'] = $after['guard']['current_version']; $facts['next_action'] = null;
			return [ 'success' => true, 'action' => 'orders.record_external_refund', 'external_refund' => $facts, 'read_only' => false, 'undoable' => false, 'message' => 'Local WooCommerce refund record verified. YoRo sent no money; the external transfer was not observed or independently verified.' ];
		} catch ( Throwable $error ) {
			return [ 'success' => false, 'action' => 'orders.record_external_refund', 'external_refund' => $facts, 'read_only' => false, 'undoable' => false, 'message' => 'Local refund recording outcome is unknown. YoRo sent no money and did not verify the external transfer. Reconcile refund history before a fresh confirmation; do not retry this operation.' ];
		}
	}

	const DOWNLOAD_REGENERATION_LIMITATIONS = [ 'download_permission_not_license', 'download_url_not_exposed', 'file_delivery_not_verified', 'regeneration_uses_current_product_download_configuration', 'regeneration_resets_permission_consumption_state', 'partial_failure_requires_fresh_reconciliation' ];

	private static function prepare_download_regeneration( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'expected_version', 'expected_download_permissions_state', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) { return new WP_Error( 'invalid_params', 'Supply exactly one order and its current guards.' ); }
		foreach ( [ 'expected_version', 'expected_download_permissions_state' ] as $field ) {
			if ( ! is_string( $params[ $field ] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params[ $field ] ) ) { return new WP_Error( 'invalid_params', 'Supply the exact current guarded read state.' ); }
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $field ) {
			if ( array_key_exists( $field, $params ) && ! is_bool( $params[ $field ] ) ) { return new WP_Error( 'invalid_params', 'Invalid execution flags.' ); }
		}
		try {
			$order = wc_get_order( $params['order_id'] );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) { return new WP_Error( 'order_not_found', 'The selected order was not found.' ); }
			$state = YoRo_Orders_Read_Service::download_regeneration_state( $order );
			$guard = $state['guard'];
			if ( ! $guard['eligible'] || ! hash_equals( $guard['current_version'], $params['expected_version'] )
				|| ! hash_equals( $guard['state_token'], $params['expected_download_permissions_state'] ) ) { return new WP_Error( 'download_regeneration_guard_conflict', 'A fresh eligible orders.get_download_permissions read is required before confirmation.' ); }
			return [ 'order' => $order, 'state' => $state ];
		} catch ( Throwable $error ) { return new WP_Error( 'download_regeneration_unavailable', 'The current regeneration state is unavailable.' ); }
	}

	public static function preview_regenerate_download_permissions( $params ) {
		$prepared = self::prepare_download_regeneration( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$guard = $prepared['state']['guard'];
		$summary = 'Delete and recreate all order download permissions from current product files. Counters, quota and dates reset; historical grants may disappear. Software licenses are unchanged; file delivery is not verified.';
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => false, 'changes_store' => true, 'order_id' => $guard['order_id'],
			'permission_count' => $guard['permission_count'], 'source_file_grant_count' => $guard['source_file_grant_count'], 'message' => $summary, 'summary' => $summary ];
	}

	public static function regenerate_download_permissions( $params ) {
		if ( true !== ( $params['_yoro_confirmed'] ?? false ) ) { return new WP_Error( 'confirmation_required', 'Explicit claimed confirmation is required.' ); }
		$prepared = self::prepare_download_regeneration( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$before = $prepared['state'];
		$id = $prepared['order']->get_id();
		$facts = [ 'order_id' => $id, 'status' => 'reconciliation_required', 'before_permission_count' => $before['guard']['permission_count'],
			'after_permission_count' => null, 'source_file_grant_count' => $before['guard']['source_file_grant_count'], 'current_version' => null,
			'next_action' => 'orders.get_download_permissions', 'limitations' => self::DOWNLOAD_REGENERATION_LIMITATIONS ];
		try {
			$store = WC_Data_Store::load( 'customer-download' );
			$started = time();
			$store->delete_by_order_id( $id );
			wc_downloadable_product_permissions( $id, true );
			$order = wc_get_order( $id );
			$after = YoRo_Orders_Read_Service::download_regeneration_state( $order );
			if ( ! self::verify_download_regeneration( $before, $after, $started ) ) { throw new UnexpectedValueException(); }
			$facts['status'] = 'regenerated'; $facts['after_permission_count'] = $after['guard']['permission_count'];
			$facts['current_version'] = $after['guard']['current_version']; $facts['next_action'] = null;
			return [ 'success' => true, 'action' => 'orders.regenerate_download_permissions', 'download_permissions_regeneration' => $facts,
				'message' => 'WooCommerce permissions regenerated and verified. No license or file delivery was verified.', 'read_only' => false, 'undoable' => false ];
		} catch ( Throwable $error ) {
			return [ 'success' => false, 'action' => 'orders.regenerate_download_permissions', 'download_permissions_regeneration' => $facts,
				'message' => 'Regeneration outcome requires reconciliation. Read orders.get_download_permissions before any new confirmation; do not retry this operation.', 'read_only' => false, 'undoable' => false ];
		}
	}

	private static function verify_download_regeneration( $before, $after, $started ) {
		if ( empty( $after['guard']['eligible'] ) || $before['sources'] !== $after['sources'] || $before['eligibility'] !== $after['eligibility']
			|| $before['guard']['current_version'] !== $after['guard']['current_version'] || count( $after['permissions'] ) !== count( $before['sources'] ) ) { return false; }
		$expected = [];
		foreach ( $before['sources'] as $source ) {
			$expiry = $source['download_expiry'];
			$from = $before['eligibility']['date_completed'] ?: gmdate( 'Y-m-d H:i:s', $started );
			$expected[] = [ 'product_id' => $source['product_id'], 'download_id' => $source['download_id'],
				'downloads_remaining' => $source['download_limit'] < 0 ? null : $source['download_limit'] * $source['quantity'],
				'unlimited_downloads' => $source['download_limit'] < 0, 'download_count' => 0,
				'access_expires' => $expiry > 0 ? gmdate( 'Y-m-d\T00:00:00\Z', strtotime( $from . ' + ' . $expiry . ' DAY' ) ) : null ];
		}
		$actual = [];
		foreach ( $after['permissions'] as $permission ) {
			if ( ! in_array( $permission['access_granted'], [ gmdate( 'Y-m-d\T00:00:00\Z', $started ), gmdate( 'Y-m-d\T00:00:00\Z' ) ], true ) ) { return false; }
			unset( $permission['access_granted'] ); $actual[] = $permission;
		}
		$encode = static function ( $row ) { ksort( $row ); return wp_json_encode( $row ); };
		$expected = array_map( $encode, $expected ); $actual = array_map( $encode, $actual ); sort( $expected ); sort( $actual );
		return $expected === $actual;
	}

	const YORO_NOTE_META_KEY = '_yoro_note_origin';
	const YORO_NOTE_META_VALUE = 'chat';

	private static function build_yoro_note_content( $note ) {
		return sprintf(
			/* translators: %s: note text */
			__( '[YoRo] %s', 'yoro-ai-core' ),
			$note
		);
	}

	private static function mark_note_as_yoro_created( $note_id ) {
		$note_id = absint( $note_id );

		if ( $note_id <= 0 ) {
			return;
		}

		update_comment_meta(
			$note_id,
			self::YORO_NOTE_META_KEY,
			self::YORO_NOTE_META_VALUE
		);
	}

	private static function is_yoro_created_note( $comment ) {
		if ( ! $comment || ! is_object( $comment ) ) {
			return false;
		}

		$comment_id = absint( $comment->comment_ID ?? 0 );

		if ( $comment_id > 0 ) {
			$origin = (string) get_comment_meta( $comment_id, self::YORO_NOTE_META_KEY, true );

			if ( self::YORO_NOTE_META_VALUE === $origin ) {
				return true;
			}
		}

		$content = (string) $comment->comment_content;

		return false !== strpos( $content, 'YoRo AI note:' )
			|| false !== strpos( $content, '[YoRo]' );
	}

	public static function add_private_note_template( $params ) {
		$template  = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$templates = self::get_private_note_templates();

		if ( empty( $template ) || empty( $templates[ $template ] ) ) {
			return new WP_Error( 'invalid_template', __( 'Invalid private note template.', 'yoro-ai-core' ) );
		}

		$params['note'] = $templates[ $template ];

		$result = self::add_order_note( $params );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$result['action']   = 'orders.add_private_note_template';
		$result['template'] = $template;
		$result['summary']  = sprintf( 'Added %s note to %d orders.', $template, count( $result['processed'] ?? [] ) );

		return $result;
	}

	public static function add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

		if ( empty( $note ) ) {
			return new WP_Error( 'invalid_params', __( 'Please tell me what note to add.', 'yoro-ai-core' ) );
		}

		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		$order_ids = array_values( array_filter( array_unique( $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose an order first.', 'yoro-ai-core' ) );
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];
		$note_ids  = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
					$skipped[] = [
						'id'     => $order_id,
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			$note_id = $order->add_order_note(
				self::build_yoro_note_content( $note ),
				0,
				true
			);

			if ( $note_id ) {
				$note_ids[] = absint( $note_id );
				self::mark_note_as_yoro_created( $note_id );
			}

			$processed[] = $order_id;
		}

		return [
			'success'   => true,
			'action'    => 'orders.add_note',
			'summary'   => sprintf( 'Added notes to %d orders.', count( $processed ) ),
			'order_ids' => $processed,
			'note'      => $note,
			'note_ids'  => $note_ids,
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'count'     => count( $processed ),
		];
	}

	public static function remove_order_note( $params ) {
		$note_ids = ! empty( $params['note_ids'] ) && is_array( $params['note_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['note_ids'] ) ) ) )
			: [];

		if ( empty( $note_ids ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Please choose a note to remove first.', 'yoro-ai-core' )
			);
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $note_ids as $note_id ) {
			$comment = get_comment( $note_id );

			if ( ! $comment ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Note not found',
				];
				continue;
			}

			if ( 'order_note' !== $comment->comment_type ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Not an order note',
				];
				continue;
			}

			if ( ! self::is_yoro_created_note( $comment ) ) {
				$skipped[] = [
					'id'     => $note_id,
					'reason' => 'Not a YoRo-created note',
				];
				continue;
			}

			$deleted = wp_delete_comment( $note_id, true );

			if ( $deleted ) {
				$processed[] = $note_id;
			} else {
				$errors[] = [
					'id'      => $note_id,
					'message' => 'Could not delete note',
				];
			}
		}

		return [
			'success'   => true,
			'action'    => 'orders.remove_note',
			'summary'   => sprintf( 'Removed %d YoRo order notes.', count( $processed ) ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'note_ids'  => $processed,
			'count'     => count( $processed ),
		];
	}

	public static function preview_remove_order_note( $params ) {
		$note_ids = ! empty( $params['note_ids'] ) && is_array( $params['note_ids'] )
			? array_values( array_unique( array_filter( array_map( 'absint', $params['note_ids'] ) ) ) )
			: [];

		$notes = [];
		$block_reason = '';
		$message = __( 'This will remove the selected YoRo-created note after approval.', 'yoro-ai-core' );

		foreach ( $note_ids as $note_id ) {
			$comment = get_comment( $note_id );
			$is_order_note = $comment && 'order_note' === $comment->comment_type;
			$is_yoro_created = $comment && self::is_yoro_created_note( $comment );

			if ( ! $comment && '' === $block_reason ) {
				$block_reason = 'note_not_found';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'I could not find note %d. Choose an existing YoRo-created note before approving this change.', 'yoro-ai-core' ),
					$note_id
				);
			} elseif ( $comment && ! $is_order_note && '' === $block_reason ) {
				$block_reason = 'invalid_note_target';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'Note %d is not an order note, so it cannot be removed by this action.', 'yoro-ai-core' ),
					$note_id
				);
			} elseif ( $comment && ! $is_yoro_created && '' === $block_reason ) {
				$block_reason = 'protected_note';
				$message = sprintf(
					/* translators: %d: note ID */
					__( 'Note %d was not created by YoRo AI, so it cannot be removed by this action.', 'yoro-ai-core' ),
					$note_id
				);
			}

			$notes[] = [
				'id'           => $note_id,
				'exists'       => (bool) $comment,
				'order_id'     => $comment ? absint( $comment->comment_post_ID ) : 0,
				'is_order_note'=> (bool) $is_order_note,
				'yoro_created' => (bool) $is_yoro_created,
			];
		}

		if ( empty( $note_ids ) ) {
			$block_reason = 'missing_note_target';
			$message = __( 'Please choose a YoRo-created note before this can be approved.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Remove YoRo-created order note',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'notes'         => $notes,
			'note_ids'      => $note_ids,
			'count'         => count( $notes ),
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_add_order_note( $params ) {
		$note = isset( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';
		$order_ids = self::order_ids_from_params( $params );
		$orders = [];
		$missing_order_ids = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'            => $order_id,
				'exists'        => (bool) $order,
				'note_preview'  => $note,
			];
		}

		$block_reason = '';
		$message = __( 'This will add an internal note after approval.', 'yoro-ai-core' );

		if ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before approving this change.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		} elseif ( '' === $note ) {
			$block_reason = 'missing_note';
			$message = __( 'Please provide the internal note text before this can be approved.', 'yoro-ai-core' );
		} elseif ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can be approved.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Add internal note to store order(s)',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'count'         => count( $orders ),
			'note_preview'  => $note,
			'changes_store' => '' === $block_reason,
		];
	}

	public static function update_order_status( $params ) {
		$prepared = self::prepare_order_status_write( $params );

		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $prepared['targets'] as $target ) {
			$order_id = $target['order_id'];
			$order = $target['order'];

			if ( ! $order ) {
					$skipped[] = [
						'id'     => $order_id,
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			try {
				$order->update_status( $prepared['status'], $prepared['note'] );
				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return self::build_order_status_write_result( $prepared, $processed, $skipped, $errors );
	}

	public static function prepare_order_status_write( $params, $constraints = [] ) {
		$params = is_array( $params ) ? $params : [];
		$constraints = is_array( $constraints ) ? $constraints : [];
		$order_ids = self::order_ids_from_params( $params );
		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';
		$note = ! empty( $params['note'] )
			? sanitize_textarea_field( $params['note'] )
			: __( 'Order status updated by YoRo AI.', 'yoro-ai-core' );

		if ( empty( $order_ids ) || empty( $status ) ) {
			return new WP_Error( 'invalid_params', __( 'Please choose at least one order and the status to apply.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $constraints['canonical_order_ids_only'] ) && ! self::has_one_canonical_order_id_only( $params ) ) {
			return new WP_Error( 'native_order_target_not_covered', __( 'The native order capability requires one exact order ID.', 'yoro-ai-core' ) );
		}

		if ( ! empty( $constraints['single_target'] ) && 1 !== count( $order_ids ) ) {
			return new WP_Error( 'native_order_target_not_covered', __( 'The native order capability only covers one exact order.', 'yoro-ai-core' ) );
		}

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return new WP_Error(
				'invalid_status',
				sprintf(
					/* translators: %s: order status */
					__( 'I do not recognize the order status "%s".', 'yoro-ai-core' ),
					$status
				)
			);
		}

		$allowed_statuses = isset( $constraints['allowed_statuses'] ) && is_array( $constraints['allowed_statuses'] )
			? array_values( array_unique( array_map( 'sanitize_key', $constraints['allowed_statuses'] ) ) )
			: [];

		if ( ! empty( $allowed_statuses ) && ! in_array( $status, $allowed_statuses, true ) ) {
			return new WP_Error( 'native_order_status_not_covered', __( 'The native order capability does not cover this status.', 'yoro-ai-core' ) );
		}

		$version_validation = self::validate_order_versions( $order_ids, $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		$targets = [];

		foreach ( $order_ids as $order_id ) {
			$order = $version_validation['orders'][ $order_id ] ?? false;

			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

			if ( ! empty( $constraints['status_must_change'] ) && $status === $order->get_status() ) {
				return new WP_Error( 'native_order_status_unchanged', __( 'The native order capability does not cover an unchanged status.', 'yoro-ai-core' ) );
			}

			$targets[] = [
				'order_id'         => $order_id,
				'order'            => $order,
				'previous_status'  => $order->get_status(),
				'expected_version' => $version_validation['version_map'][ $order_id ],
			];
		}

		return [
			'order_ids' => $order_ids,
			'status'    => $status,
			'note'      => $note,
			'targets'   => $targets,
		];
	}

	public static function build_order_status_write_result( array $prepared, array $processed, array $skipped = [], array $errors = [] ) {
		$status = sanitize_key( $prepared['status'] ?? '' );
		$previous_statuses = [];

		foreach ( $prepared['targets'] ?? [] as $target ) {
			if ( ! is_array( $target ) ) {
				continue;
			}

			$previous_statuses[] = [
				'order_id' => absint( $target['order_id'] ?? 0 ),
				'status'   => sanitize_key( $target['previous_status'] ?? '' ),
			];
		}

		$current_versions = [];

		foreach ( $processed as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				$current_versions[] = [
					'order_id' => $order_id,
					'expected_version' => YoRo_Order_Presenter::order_version( $order ),
				];
			}
		}

		return [
			'success'      => true,
			'action'       => 'orders.update_status',
			'summary'      => sprintf(
				'Updated %d orders to %s.',
				count( $processed ),
				YoRo_Order_Helper::get_order_status_label( $status )
			),
			'processed'    => $processed,
			'skipped'      => $skipped,
			'errors'       => $errors,
			'status'       => $status,
			'status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			'count'        => count( $processed ),
			'undo_plan'    => empty( $previous_statuses ) ? [] : [
				[
					'action' => 'orders.restore_statuses',
					'params' => [
						'statuses' => $previous_statuses,
						'expected_versions' => $current_versions,
					],
				],
			],
			'current_versions' => $current_versions,
		];
	}

	public static function restore_order_statuses( $params ) {
		$statuses = self::normalize_restore_statuses( $params['statuses'] ?? [] );

		if ( empty( $statuses ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'I could not find the previous order status to restore.', 'yoro-ai-core' )
			);
		}

		$version_validation = self::validate_order_versions( wp_list_pluck( $statuses, 'order_id' ), $params );

		if ( is_wp_error( $version_validation ) ) {
			return $version_validation;
		}

		$processed = [];
		$skipped   = [];
		$errors    = [];

		foreach ( $statuses as $status_item ) {
			$order_id = absint( $status_item['order_id'] ?? 0 );
			$status   = sanitize_key( $status_item['status'] ?? '' );

			if ( ! $order_id || empty( $status ) ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
					$skipped[] = [
						'id'     => $order_id,
						'reason' => __( 'I could not find that order.', 'yoro-ai-core' ),
					];
				continue;
			}

			try {
				$order->update_status(
					$status,
					__( 'Order status restored by YoRo undo.', 'yoro-ai-core' ),
					true
				);

				$processed[] = $order_id;
			} catch ( Exception $e ) {
				$errors[] = [
					'id'      => $order_id,
					'message' => $e->getMessage(),
				];
			}
		}

		return [
			'success'   => true,
			'action'    => 'orders.restore_statuses',
			'summary'   => sprintf( 'Restored statuses for %d orders.', count( $processed ) ),
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'count'     => count( $processed ),
		];
	}

	public static function preview_restore_order_statuses( $params ) {
		$statuses = self::normalize_restore_statuses( $params['statuses'] ?? [] );

		$orders = [];
		$block_reason = '';
		$message = __( 'This will restore the selected order statuses after approval.', 'yoro-ai-core' );

		foreach ( $statuses as $status_item ) {
			$order_id = absint( $status_item['order_id'] ?? 0 );
			$status = YoRo_Order_Helper::normalize_status_slug( $status_item['status'] ?? '' );

			if ( ! $order_id || '' === $status ) {
				continue;
			}

			$order = wc_get_order( $order_id );

			if ( ! $order && '' === $block_reason ) {
				$block_reason = 'order_not_found';
				$message = sprintf(
					/* translators: %d: order ID */
					__( 'I could not find order %d. Choose an existing order before approving this change.', 'yoro-ai-core' ),
					$order_id
				);
			} elseif ( ! YoRo_Order_Helper::is_valid_order_status( $status ) && '' === $block_reason ) {
				$block_reason = 'invalid_status';
				$message = sprintf(
					/* translators: %s: order status */
					__( 'I do not recognize the order status "%s". Choose a configured order status before approving this change.', 'yoro-ai-core' ),
					$status
				);
			}

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'current_status_label' => $order ? YoRo_Order_Helper::get_order_status_label( $order->get_status() ) : '',
				'target_status'  => $status,
				'target_status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			];
		}

		if ( empty( $orders ) ) {
			$block_reason = 'missing_restore_statuses';
			$message = __( 'I could not find the previous order statuses to restore.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Restore store order statuses',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'statuses'      => $statuses,
			'count'         => count( $orders ),
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_update_order_status( $params ) {
		$order_ids = self::order_ids_from_params( $params );
		$status = ! empty( $params['status'] )
			? YoRo_Order_Helper::normalize_status_slug( $params['status'] )
			: '';

		$orders = [];
		$missing_order_ids = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'             => $order_id,
				'exists'         => (bool) $order,
				'current_status' => $order ? $order->get_status() : '',
				'current_status_label' => $order ? YoRo_Order_Helper::get_order_status_label( $order->get_status() ) : '',
				'target_status'  => $status,
				'target_status_label' => YoRo_Order_Helper::get_order_status_label( $status ),
			];
		}

		$target_status_label = YoRo_Order_Helper::get_order_status_label( $status );
		$block_reason = '';
		$message = sprintf(
			/* translators: %s: target order status */
			__( 'This will update the selected order status to %s after approval.', 'yoro-ai-core' ),
			$target_status_label
		);

		if ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can be approved.', 'yoro-ai-core' );
		} elseif ( '' === $status ) {
			$block_reason = 'missing_status';
			$message = __( 'Please choose a target order status before this can be approved.', 'yoro-ai-core' );
		} elseif ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			$block_reason = 'invalid_status';
			$message = sprintf(
				/* translators: %s: order status */
				__( 'I do not recognize the order status "%s". Choose a configured order status before approving this change.', 'yoro-ai-core' ),
				$status
			);
		} elseif ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before approving this change.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		} elseif ( is_wp_error( self::validate_order_versions( $order_ids, $params ) ) ) {
			$block_reason = 'order_version_conflict';
			$message = __( 'At least one order changed after it was read. Refresh the order details before approving this status update.', 'yoro-ai-core' );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Update store order status',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'orders'        => $orders,
			'count'         => count( $orders ),
			'target_status' => $status,
			'target_status_label' => $target_status_label,
			'changes_store' => '' === $block_reason,
		];
	}

	public static function preview_add_private_note_template( $params ) {
		$template  = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$templates = self::get_private_note_templates();

		$params['note'] = $templates[ $template ] ?? '';

		return self::preview_add_order_note( $params ) + [
			'template' => $template,
		];
	}

	public static function prepare_order_address_write( $params ) {
		$fields = [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ];
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'expected_version', 'shipping' ] )
			|| ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1
			|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] )
			|| ! is_array( $params['shipping'] ?? null ) || ! $params['shipping']
			|| array_diff( array_keys( $params['shipping'] ), $fields ) ) {
			return new WP_Error( 'invalid_address_params', __( 'Supply one order, its current version and supported shipping fields.', 'yoro-ai-core' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_shop_order', $params['order_id'] ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this order.', 'yoro-ai-core' ) );
		}
		$validation = self::validate_order_versions( [ $params['order_id'] ], [ 'expected_versions' => [ [ 'order_id' => $params['order_id'], 'expected_version' => $params['expected_version'] ] ] ] );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}
		$order = $validation['orders'][ $params['order_id'] ];
		$changes = [];
		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $params['shipping'] ) ) {
				continue;
			}
			$raw = $params['shipping'][ $field ];
			if ( ! is_string( $raw ) || mb_strlen( $raw ) > 200 || false !== strpos( $raw, chr( 0 ) ) ) {
				return new WP_Error( 'invalid_shipping_value', __( 'Shipping fields must contain bounded text.', 'yoro-ai-core' ) );
			}
			$value = sanitize_text_field( $raw );
			if ( '' === $value && ! in_array( $field, [ 'company', 'address_2', 'state', 'postcode' ], true ) ) {
				return new WP_Error( 'shipping_field_required', __( 'This shipping field cannot be cleared.', 'yoro-ai-core' ) );
			}
			if ( 'country' === $field ) {
				$value = strtoupper( $value );
				$countries = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : [];
				if ( ! preg_match( '/^[A-Z]{2}$/D', $value ) || ( $countries && ! array_key_exists( $value, $countries ) ) ) {
					return new WP_Error( 'unsupported_shipping_country', __( 'Choose a recognized shipping country.', 'yoro-ai-core' ) );
				}
			}
			$before = (string) $order->{ 'get_shipping_' . $field }( 'edit' );
			if ( mb_strlen( $before ) > 200 ) {
				return new WP_Error( 'shipping_preview_too_long', __( 'This address requires manual review before changing it.', 'yoro-ai-core' ) );
			}
			if ( $before !== $value ) {
				$changes[ $field ] = [ 'before' => $before, 'after' => $value ];
			}
		}
		if ( ! $changes ) {
			return new WP_Error( 'shipping_address_unchanged', __( 'The shipping address already matches the requested values.', 'yoro-ai-core' ) );
		}
		return [ 'order' => $order, 'changes' => $changes, 'expected_version' => $params['expected_version'] ];
	}

	public static function preview_update_order_addresses( $params ) {
		$prepared = self::prepare_order_address_write( $params );
		if ( is_wp_error( $prepared ) ) {
			return [ 'type' => 'write', 'will_execute' => false, 'blocked' => true, 'block_reason' => $prepared->get_error_code(), 'message' => $prepared->get_error_message(), 'changes_store' => false ];
		}
		$changes = [];
		foreach ( $prepared['changes'] as $field => $change ) {
			$changes[ $field ] = [ 'before' => esc_html( mb_substr( $change['before'], 0, 200 ) ), 'after' => esc_html( mb_substr( $change['after'], 0, 200 ) ) ];
		}
		$lines = [ sprintf( __( 'Update shipping address for order #%d:', 'yoro-ai-core' ), $prepared['order']->get_id() ) ];
		foreach ( $prepared['changes'] as $field => $change ) {
			$lines[] = sprintf( '%s: %s → %s', $field, wp_json_encode( $change['before'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), wp_json_encode( $change['after'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
		}
		$lines[] = __( 'Customer profile, billing, totals, tax, shipping lines/method and payment state remain unchanged.', 'yoro-ai-core' );
		// The existing confirmation renderer escapes this plain-text message.
		$message = implode( "\n", $lines );
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => false, 'block_reason' => '', 'changes_store' => true, 'order_id' => $prepared['order']->get_id(), 'changes' => $changes, 'expected_version' => $prepared['expected_version'], 'current_version' => YoRo_Order_Presenter::order_version( $prepared['order'] ), 'summary' => $message, 'message' => $message ];
	}

	public static function update_order_addresses( $params ) {
		$prepared = self::prepare_order_address_write( $params );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}
		$order = $prepared['order'];
		try {
			foreach ( $prepared['changes'] as $field => $change ) {
				$order->{ 'set_shipping_' . $field }( $change['after'] );
			}
			$order->save();
		} catch ( Throwable $error ) {
			return new WP_Error( 'shipping_save_outcome_unknown', __( 'The update could not be verified. Read the order again before any further change; do not retry automatically.', 'yoro-ai-core' ) );
		}
		$persisted = wc_get_order( $order->get_id() );
		if ( ! $persisted instanceof WC_Order ) {
			return new WP_Error( 'shipping_verification_failed', __( 'Read the order again to reconcile the update.', 'yoro-ai-core' ) );
		}
		foreach ( $prepared['changes'] as $field => $change ) {
			if ( (string) $persisted->{ 'get_shipping_' . $field }( 'edit' ) !== $change['after'] ) {
				return new WP_Error( 'shipping_verification_failed', __( 'Read the order again to reconcile the update.', 'yoro-ai-core' ) );
			}
		}
		return [ 'success' => true, 'order_id' => $persisted->get_id(), 'changed_fields' => array_keys( $prepared['changes'] ), 'current_version' => YoRo_Order_Presenter::order_version( $persisted ), 'message' => __( 'Order shipping address updated.', 'yoro-ai-core' ), 'summary' => __( 'Order shipping address updated.', 'yoro-ai-core' ), 'read_only' => false, 'undoable' => false ];
	}

	private static function validate_order_versions( $order_ids, $params ) {
		$order_ids = array_values( array_filter( array_unique( array_map( 'absint', (array) $order_ids ) ) ) );
		$expected_versions = is_array( $params['expected_versions'] ?? null ) ? $params['expected_versions'] : [];
		$version_map = [];

		foreach ( $expected_versions as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$order_id = absint( $item['order_id'] ?? 0 );
			$expected_version = sanitize_text_field( $item['expected_version'] ?? '' );

			if ( $order_id < 1 || '' === $expected_version || isset( $version_map[ $order_id ] ) ) {
				return new WP_Error( 'order_version_contract_invalid', __( 'Every target order must have one unique expected version.', 'yoro-ai-core' ) );
			}

			$version_map[ $order_id ] = $expected_version;
		}

		if ( count( $version_map ) !== count( $order_ids ) || array_diff( $order_ids, array_keys( $version_map ) ) ) {
			return new WP_Error( 'order_version_contract_invalid', __( 'Every target order must have one expected version from the latest read.', 'yoro-ai-core' ) );
		}

		$orders = [];

		foreach ( $order_ids as $order_id ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

			if ( ! $order instanceof WC_Order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

			$current_version = YoRo_Order_Presenter::order_version( $order );

			if ( ! hash_equals( $current_version, $version_map[ $order_id ] ) ) {
				return new WP_Error(
					'order_version_conflict',
					__( 'At least one order changed after it was read. Refresh the order details before trying again.', 'yoro-ai-core' ),
					[ 'order_id' => $order_id, 'current_version' => $current_version ]
				);
			}

			$orders[ $order_id ] = $order;
		}

		return [
			'version_map' => $version_map,
			'orders'      => $orders,
		];
	}

	private static function normalize_restore_statuses( $statuses ) {
		$normalized = [];

		foreach ( is_array( $statuses ) ? $statuses : [] as $key => $item ) {
			if ( is_array( $item ) ) {
				$order_id = absint( $item['order_id'] ?? 0 );
				$status = sanitize_key( $item['status'] ?? '' );
			} else {
				$order_id = absint( $key );
				$status = sanitize_key( $item );
			}

			if ( $order_id > 0 && '' !== $status ) {
				$normalized[] = [
					'order_id' => $order_id,
					'status'   => $status,
				];
			}
		}

		return $normalized;
	}

	private static function get_private_note_templates() {
		return [
			'suspicious'         => __( 'YoRo review: This order has suspicious signals and should be manually reviewed before fulfillment.', 'yoro-ai-core' ),
			'payment_mismatch'   => __( 'YoRo review: Payment information may not match the customer/order identity. Please verify before fulfillment.', 'yoro-ai-core' ),
			'needs_verification' => __( 'YoRo review: Customer verification is required before this order is processed further.', 'yoro-ai-core' ),
		];
	}

	private static function order_ids_from_params( $params ) {
		$order_ids = [];

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_ids = array_map( 'absint', $params['order_ids'] );
		} elseif ( ! empty( $params['order_id'] ) ) {
			$order_ids = [ absint( $params['order_id'] ) ];
		}

		return array_values( array_filter( array_unique( $order_ids ) ) );
	}

	private static function has_one_canonical_order_id_only( $params ) {
		if ( ! is_array( $params ) || ! isset( $params['order_ids'] ) || ! is_array( $params['order_ids'] ) || 1 !== count( $params['order_ids'] ) ) {
			return false;
		}

		if ( ! empty( $params['order_id'] ) ) {
			return false;
		}

		$order_id = reset( $params['order_ids'] );

		return is_int( $order_id ) && $order_id > 0;
	}
}
