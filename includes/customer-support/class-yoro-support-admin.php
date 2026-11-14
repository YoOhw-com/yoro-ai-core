<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-yoro-support-view.php';

class YoRo_Support_Admin {
	private static function allowed() { return current_user_can( 'manage_options' ) && current_user_can( 'yoro_manage_connection' ); }
	public static function search() {
		if ( ! self::allowed() ) { wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 ); }
		check_ajax_referer( 'yoro_support_sources' );
		$type = sanitize_key( $_POST['type'] ?? 'content' );
		$query = mb_substr( sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) ), 0, 120 );
		$rows = [];
		if ( 'content' === $type ) {
			$posts = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'has_password' => false, 'numberposts' => 20, 's' => $query, 'suppress_filters' => true ] );
			foreach ( $posts as $post ) {
				if ( is_post_publicly_viewable( $post ) && '' === $post->post_password ) { $rows[] = [ 'id' => $post->ID, 'title' => wp_strip_all_tags( $post->post_title ), 'type' => $post->post_type ]; }
			}
		} elseif ( 'products' === $type && function_exists( 'wc_get_products' ) ) {
			foreach ( wc_get_products( [ 'status' => 'publish', 'limit' => 20, 's' => $query, 'return' => 'ids' ] ) as $id ) {
				$source = YoRo_Support_Facts::product( $id );
				if ( $source ) { $rows[] = [ 'id' => (int) $id, 'title' => $source['title'], 'type' => $source['product']['kind'] ]; }
			}
		}
		wp_send_json_success( [ 'results' => $rows ] );
	}

	private static function values( array $current ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $_POST['content_ids'] ?? [] ) ) ) ) );
		if ( count( $ids ) > 50 ) { return new WP_Error( 'support_settings_invalid', 'Choose at most 50 sources.' ); }
		$mapping = [];
		foreach ( $ids as $id ) {
			$source = YoRo_Support_Facts::content( $id, [ 'content_ids' => $ids ] );
			if ( ! $source ) { return new WP_Error( 'support_settings_invalid', 'Remove sources that are no longer public.' ); }
			$product_ids = array_values( array_unique( array_map( 'absint', (array) ( $_POST['source_products'][ $id ] ?? [] ) ) ) );
			foreach ( $product_ids as $product_id ) { if ( ! YoRo_Support_Facts::product( $product_id ) ) { return new WP_Error( 'support_settings_invalid', 'Choose public products for source mapping.' ); } }
			$mapping[] = [ 'id' => $id, 'kind' => sanitize_key( $_POST['source_kind'][ $id ] ?? 'faq' ), 'product_ids' => $product_ids ];
		}
		$features = [];
		foreach ( [ 'content', 'products', 'quote', 'coupons', 'payments', 'orders' ] as $key ) { $features[ $key ] = isset( $_POST['features'][ $key ] ); }
		$appearance = [];
		foreach ( [ 'name', 'welcome', 'launcher', 'color', 'position' ] as $key ) { $appearance[ $key ] = sanitize_text_field( wp_unslash( $_POST['appearance'][ $key ] ?? '' ) ); }
		$appearance['icon'] = sanitize_text_field( wp_unslash( $_POST['appearance']['icon'] ?? YoRo_Support_Context::preferences( $current )['appearance']['icon'] ) );
		$appearance['starters'] = array_values( array_map( 'sanitize_key', (array) ( $_POST['appearance']['starters'] ?? [] ) ) );
		return [ 'enabled' => isset( $_POST['enabled'] ), 'revision' => filter_var( $_POST['revision'] ?? null, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 0 ] ] ), 'content_ids' => $ids,
			'contact' => sanitize_textarea_field( wp_unslash( $_POST['contact'] ?? '' ) ), 'preferences' => [ 'features' => $features, 'appearance' => $appearance, 'sources' => $mapping ] ];
	}

	public static function render() {
		if ( ! self::allowed() ) { wp_die( esc_html__( 'Permission denied.', 'yoro-ai-core' ) ); }
		$remote = YoRo_Support_Transport::request( 'GET', '/settings', [], [], true );
		$settings = YoRo_Customer_Support::settings(); $error = is_wp_error( $remote ); $saved = false; $message = '';
		if ( ! $error && is_array( $remote['settings'] ?? null ) && 2 === ( $remote['settings']['protocol'] ?? null ) ) {
			$settings = $remote['settings']; update_option( YoRo_Customer_Support::OPTION, $settings, false );
		} else { $error = true; }
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! $error ) {
			check_admin_referer( 'yoro_support_settings' );
			$values = self::values( $settings );
			$updated = is_wp_error( $values ) ? $values : YoRo_Support_Transport::request( 'PUT', '/settings', $values, [], true );
			if ( is_wp_error( $updated ) || ! is_array( $updated['settings'] ?? null ) ) { $error = true; $message = is_wp_error( $updated ) ? $updated->get_error_message() : ''; }
			else { $settings = $updated['settings']; $remote = $updated; update_option( YoRo_Customer_Support::OPTION, $settings, false ); $saved = true; }
		}
		$p = YoRo_Support_Context::preferences( $settings );
		$ready = $settings['readiness']['mail'] ?? false; $woo = function_exists( 'wc_get_product' );
		$kinds = [ 'faq' => 'FAQ', 'store' => 'Store information', 'shipping' => 'Shipping policy', 'returns' => 'Returns & warranty', 'payment' => 'Payment policy', 'size' => 'Size guide', 'guide' => 'Usage, care & compatibility' ];
		echo '<div class="wrap yoro-support-admin"><form method="post" id="yoro-support-settings" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'yoro_support_sources' ) ) . '">';
		wp_nonce_field( 'yoro_support_settings' );
		echo '<input type="hidden" name="revision" value="' . (int) $settings['revision'] . '">';
		echo '<header class="ys-admin-header"><div><p class="ys-eyebrow">YORO · CUSTOMER EXPERIENCE</p><h1>Chat Support</h1><p>' . esc_html__( 'Helpful answers before and after a purchase, grounded in your store.', 'yoro-ai-core' ) . '</p></div><div><button type="button" class="button" data-preview>' . esc_html__( 'Preview', 'yoro-ai-core' ) . '</button> <button class="button button-primary" ' . disabled( $error, true, false ) . '>' . esc_html__( 'Save changes', 'yoro-ai-core' ) . '</button></div></header>';
		if ( $error ) { echo '<div class="notice notice-error" role="alert"><p>' . esc_html( $message ?: __( 'Current settings could not be verified. Save is unavailable until the API is ready.', 'yoro-ai-core' ) ) . '</p></div>'; }
		if ( $saved ) { echo '<div class="notice notice-success" role="status"><p>' . esc_html__( 'Settings saved. Existing access is revalidated against the new revision.', 'yoro-ai-core' ) . '</p></div>'; }
		echo '<div class="ys-status"><span class="ys-dot ' . ( ! $error && $settings['enabled'] ? 'active' : '' ) . '"></span><strong>' . esc_html( $error ? __( 'Unavailable', 'yoro-ai-core' ) : ( $settings['enabled'] ? __( 'Enabled', 'yoro-ai-core' ) : __( 'Disabled', 'yoro-ai-core' ) ) ) . '</strong><span>' . esc_html( $ready ? __( 'Email verification ready', 'yoro-ai-core' ) : __( 'Email verification needs setup', 'yoro-ai-core' ) ) . '</span></div>';
		echo '<nav class="nav-tab-wrapper" role="tablist" aria-label="Chat Support settings">';
		foreach ( [ 'overview' => 'Overview', 'sources' => 'Answer sources', 'verification' => 'Orders & verification', 'appearance' => 'Appearance', 'usage' => 'Data & usage' ] as $key => $label ) { echo '<button type="button" role="tab" class="nav-tab" id="ys-tab-button-' . esc_attr( $key ) . '" data-tab="' . esc_attr( $key ) . '" aria-controls="ys-tab-' . esc_attr( $key ) . '">' . esc_html__( $label, 'yoro-ai-core' ) . '</button>'; }
		echo '</nav><section class="ys-admin-panel" id="ys-tab-overview" role="tabpanel" aria-labelledby="ys-tab-button-overview"><h2>' . esc_html__( 'Support your customers', 'yoro-ai-core' ) . '</h2><p>' . esc_html__( 'Only ready, enabled capabilities are offered to customers.', 'yoro-ai-core' ) . '</p><label class="ys-toggle"><input type="checkbox" name="enabled" ' . checked( $settings['enabled'], true, false ) . '> ' . esc_html__( 'Enable Chat Support', 'yoro-ai-core' ) . '</label><div class="ys-feature-grid">';
		$labels = [ 'content' => 'Approved FAQ & content', 'products' => 'Product advice', 'quote' => 'Shipping, tax & total estimates', 'coupons' => 'Coupon previews', 'payments' => 'Eligible payment methods', 'orders' => 'Verified orders & tracking' ];
		foreach ( $labels as $key => $label ) {
			$available = 'content' === $key || $woo;
			echo '<label class="ys-feature"><input type="checkbox" name="features[' . esc_attr( $key ) . ']" ' . checked( $p['features'][ $key ], true, false ) . ' ' . disabled( ! $available, true, false ) . '><span><strong>' . esc_html__( $label, 'yoro-ai-core' ) . '</strong><small>' . esc_html( $available ? __( 'Available when enabled and compatible', 'yoro-ai-core' ) : __( 'Requires WooCommerce', 'yoro-ai-core' ) ) . '</small></span></label>';
		}
		echo '</div><label class="ys-field">' . esc_html__( 'Public contact information', 'yoro-ai-core' ) . '<textarea name="contact" maxlength="500" rows="3">' . esc_textarea( $settings['contact'] ) . '</textarea></label><p class="ys-info">' . esc_html__( 'Uses available Workspace credits: 1 credit per completed AI answer. Verification and information forms use no AI credits. There is no separate site quota.', 'yoro-ai-core' ) . '</p></section>';
		echo '<section class="ys-admin-panel" id="ys-tab-sources" role="tabpanel" aria-labelledby="ys-tab-button-sources" hidden><h2>' . esc_html__( 'Approved answer sources', 'yoro-ai-core' ) . '</h2><p>' . esc_html__( 'Select up to 50 public pages/posts. Publish permitted manufacturer guidance on your site before selecting it.', 'yoro-ai-core' ) . '</p><label class="ys-field">' . esc_html__( 'Find a page or post', 'yoro-ai-core' ) . '<input type="search" data-source-search placeholder="' . esc_attr__( 'Search published content…', 'yoro-ai-core' ) . '"></label><div data-source-results></div><div data-selected-sources>';
		$maps = [];
		foreach ( $p['sources'] as $map ) { $maps[ $map['id'] ] = $map; }
		foreach ( $settings['content_ids'] as $id ) {
			$post = get_post( $id ); $map = $maps[ $id ] ?? [ 'kind' => 'faq', 'product_ids' => [] ];
			$valid = YoRo_Support_Facts::content( $id, $settings );
			self::source_row( $id, $post ? $post->post_title : '#' . $id, $map, $valid ? __( 'Public · approved', 'yoro-ai-core' ) : __( 'Unavailable — remove or publish', 'yoro-ai-core' ), $kinds );
		}
		echo '</div><p>' . esc_html__( 'Missing or conflicting size, warranty or compatibility information is reported honestly; product facts come from current WooCommerce data.', 'yoro-ai-core' ) . '</p></section>';
		echo '<section class="ys-admin-panel" id="ys-tab-verification" role="tabpanel" aria-labelledby="ys-tab-button-verification" hidden><h2>' . esc_html__( 'Secure order lookup', 'yoro-ai-core' ) . '</h2><p>' . esc_html__( 'Customers verify the billing email inside the chat. A shared inbox can access orders linked to that email; verification is not a WordPress account login.', 'yoro-ai-core' ) . '</p><dl class="ys-facts"><dt>Delivery</dt><dd>' . esc_html( $ready ? 'Verified sender is ready' : 'Needs verified API mail delivery' ) . '</dd><dt>Code</dt><dd>6 digits · 5 minutes · 5 failed attempts</dd><dt>Resend</dt><dd>60 seconds, with email/IP/site limits</dd><dt>Private access</dt><dd>15 minutes maximum, bound to this chat and store</dd><dt>Visible data</dt><dd>Order reference, items, totals, recorded payment/shipment/refund status</dd><dt>Excluded data</dt><dd>Internal notes, full address, phone, payment secrets and admin links</dd></dl><p>' . esc_html__( 'Code delivery is tested through the storefront verification form. Preview never sends mail. Mail credentials and delivery verification are managed by the API operator.', 'yoro-ai-core' ) . '</p></section>';
		echo '<section class="ys-admin-panel" id="ys-tab-appearance" role="tabpanel" aria-labelledby="ys-tab-button-appearance" hidden><h2>' . esc_html__( 'Make it feel at home', 'yoro-ai-core' ) . '</h2><div class="ys-fields">';
		foreach ( [ 'name' => [ 'Assistant name', 60 ], 'welcome' => [ 'Welcome message', 240 ], 'launcher' => [ 'Launcher label', 40 ] ] as $key => $field ) { echo '<label class="ys-field">' . esc_html__( $field[0], 'yoro-ai-core' ) . '<input name="appearance[' . esc_attr( $key ) . ']" maxlength="' . (int) $field[1] . '" value="' . esc_attr( $p['appearance'][ $key ] ) . '" required></label>'; }
		echo '<div class="ys-field ys-accent-field"><label for="ys-accent-hex">' . esc_html__( 'Accent color (hex)', 'yoro-ai-core' ) . '</label><div class="ys-color-controls"><label class="screen-reader-text" for="ys-accent-picker">' . esc_html__( 'Choose accent color', 'yoro-ai-core' ) . '</label><input id="ys-accent-picker" type="color" value="' . esc_attr( preg_match( '/^#[a-f0-9]{6}$/i', $p['appearance']['color'] ) ? $p['appearance']['color'] : '#2b3a20' ) . '"><input id="ys-accent-hex" name="appearance[color]" maxlength="7" pattern="#[a-fA-F0-9]{6}" value="' . esc_attr( $p['appearance']['color'] ) . '" aria-describedby="ys-accent-help" required></div><small id="ys-accent-help">' . esc_html__( 'Use #RRGGBB. Light colors use the accessible YoRo accent in chat.', 'yoro-ai-core' ) . '</small></div>';
		echo '<label class="ys-field">' . esc_html__( 'Chat icon', 'yoro-ai-core' ) . '<select name="appearance[icon]">';
		foreach ( [ 'yoro-animated' => 'YoRo · animated (3 cycles)', 'yoro-static' => 'YoRo · static', 'chat' => 'Chat bubble' ] as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $p['appearance']['icon'], $key, false ) . '>' . esc_html__( $label, 'yoro-ai-core' ) . '</option>'; }
		echo '</select></label>';
		echo '<label class="ys-field">' . esc_html__( 'Position', 'yoro-ai-core' ) . '<select name="appearance[position]"><option value="right" ' . selected( $p['appearance']['position'], 'right', false ) . '>Right</option><option value="left" ' . selected( $p['appearance']['position'], 'left', false ) . '>Left</option></select></label></div><p>' . esc_html__( 'An accessible fallback accent is used if the selected color has insufficient contrast.', 'yoro-ai-core' ) . '</p><fieldset><legend>' . esc_html__( 'Starter actions (up to four)', 'yoro-ai-core' ) . '</legend>';
		foreach ( [ 'products' => 'Find products', 'policies' => 'Shipping & returns', 'quote' => 'Estimate total', 'orders' => 'Order lookup' ] as $key => $label ) { echo '<label class="ys-inline"><input type="checkbox" name="appearance[starters][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $p['appearance']['starters'], true ), true, false ) . '> ' . esc_html__( $label, 'yoro-ai-core' ) . '</label>'; }
		echo '</fieldset></section><section class="ys-admin-panel" id="ys-tab-usage" role="tabpanel" aria-labelledby="ys-tab-button-usage" hidden><h2>' . esc_html__( 'Data & usage', 'yoro-ai-core' ) . '</h2><div class="ys-metrics">';
		foreach ( [ 'completed_count' => 'Completed answers', 'inference_calls' => 'Provider calls', 'available_workspace_credits_milli' => 'Available Workspace credits' ] as $key => $label ) { $value = $remote['usage'][ $key ] ?? null; if ( null !== $value && 'available_workspace_credits_milli' === $key ) { $value /= 1000; } echo '<div><small>' . esc_html__( $label, 'yoro-ai-core' ) . '</small><strong>' . esc_html( null === $value ? '—' : (string) $value ) . '</strong></div>'; }
		echo '</div><p>' . esc_html__( 'Encrypted conversation content: up to 7 days. Body-free metadata: 30 days. Financial history is retained separately. Expired access is denied even before cleanup runs.', 'yoro-ai-core' ) . '</p><p>' . esc_html__( 'Codes are single-use, protected at rest, never sent to AI or stored in chat. Only minimal authorized order facts are sent for the answer. Customers can end private lookup at any time.', 'yoro-ai-core' ) . '</p></section></form>';
		echo '<dialog class="ys-preview"><header><strong>' . esc_html__( 'Customer preview · sample data', 'yoro-ai-core' ) . '</strong><button type="button" data-preview-close aria-label="Close preview">×</button></header><div class="ys-preview-toolbar"><button type="button" data-preview-width="desktop">Desktop</button><button type="button" data-preview-width="mobile">Mobile</button></div><div class="ys-preview-phone"><div class="ys-preview-icons">' ;
		foreach ( [ 'yoro-animated', 'yoro-static', 'chat' ] as $icon ) { echo '<span data-preview-icon="' . esc_attr( $icon ) . '" hidden>' . YoRo_Support_View::icon( $icon ) . '</span>'; }
		echo '</div><p class="ys-eyebrow">AI SHOPPING ASSISTANT</p><h2 data-preview-name></h2><p data-preview-welcome></p><div class="ys-preview-message">Sample product · $49.00 · In stock</div><div class="ys-preview-message">Estimated total · $54.00<br>Checkout recalculates the final amount.</div><div class="ys-preview-message">Verify your email<br><input disabled placeholder="Email"><input disabled placeholder="6-digit code"></div><div class="ys-preview-message">✓ Sample verified order #1024 · Processing</div><button type="button" data-preview-launcher class="ys-preview-launcher"></button><small>Preview uses sample data. No email or AI request is sent.</small></div></dialog></div>';
	}
	private static function source_row( $id, $title, array $map, $status, array $kinds ) {
		echo '<article class="ys-source" data-source-id="' . (int) $id . '"><input type="hidden" name="content_ids[]" value="' . (int) $id . '"><div><strong>' . esc_html( $title ) . '</strong><small>' . esc_html( $status ) . '</small></div><label>Type <select name="source_kind[' . (int) $id . ']">';
		foreach ( $kinds as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $map['kind'], $key, false ) . '>' . esc_html__( $label, 'yoro-ai-core' ) . '</option>'; }
		echo '</select></label><div class="ys-source-products"><label>Applies to products <input type="search" data-product-search placeholder="Search products (empty = store-wide)"></label><div data-product-results></div><div data-mapped-products>';
		foreach ( $map['product_ids'] as $product_id ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null; echo '<label class="ys-chip"><input type="checkbox" name="source_products[' . (int) $id . '][]" value="' . (int) $product_id . '" checked> ' . esc_html( $product ? $product->get_name() : '#' . $product_id ) . '</label>'; }
		echo '</div></div><button type="button" class="button-link-delete" data-remove-source>Remove</button></article>';
	}
}
