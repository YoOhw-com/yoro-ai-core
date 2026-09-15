<?php
/** Bounded local order creation: owner preview, receipt, one-use execution and reconciliation. */
if ( ! defined( 'ABSPATH' ) || ! class_exists( 'WooCommerce' ) ) { throw new RuntimeException( 'Disposable WordPress/WooCommerce fixture required.' ); }
$assertions = 0;
$assert = static function ( $value, $message ) use ( &$assertions ) { ++$assertions; if ( ! $value ) { throw new RuntimeException( $message ); } };
$before_user = get_current_user_id();
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( $admins[0] );
$actor_meta = [];
foreach ( [ YoRo_WordPress_Actor::ACCESS_META_KEY, YoRo_WordPress_Actor::SUBJECT_META_KEY ] as $key ) { $actor_meta[ $key ] = get_user_meta( $admins[0], $key, false ); }
YoRo_Orders_Actions::register();
$products = []; $orders = []; $options = []; $tax_rates = []; $tax_class = null;
$created = static function ( $id ) use ( &$orders ) { $orders[] = $id; };
$unexpected_mail = 0; $unexpected_effect = 0; $privacy_reads = 0;
$effect_probe = static function () use ( &$unexpected_effect ) { ++$unexpected_effect; };
$privacy_probe = static function ( $value ) use ( &$privacy_reads ) { ++$privacy_reads; return $value; };
$effect_hooks = [ 'woocommerce_payment_complete', 'woocommerce_reduce_order_stock', 'woocommerce_restore_order_stock' ];
$privacy_hooks = [ 'woocommerce_order_get_customer_ip_address', 'woocommerce_order_get_customer_user_agent' ];
foreach ( $effect_hooks as $hook ) { add_action( $hook, $effect_probe ); }
foreach ( $privacy_hooks as $hook ) { add_filter( $hook, $privacy_probe ); }
$no_mail = static function () use ( &$unexpected_mail ) { ++$unexpected_mail; return false; };
$writes = 0;
$deny_write = static function ( $query ) use ( &$writes ) { if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE)\b/i', $query ) ) { ++$writes; throw new RuntimeException( 'Preview attempted persistence.' ); } return $query; };
$restore_options = [ 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'woocommerce_tax_round_at_subtotal', 'woocommerce_price_num_decimals', 'woocommerce_tax_based_on', 'woocommerce_default_country' ];
foreach ( $restore_options as $key ) { $options[ $key ] = get_option( $key ); }
add_action( 'woocommerce_new_order', $created );
add_filter( 'pre_wp_mail', $no_mail );
try {
    $product = new WC_Product_Simple(); $product->set_name( 'Bounded fixture' ); $product->set_regular_price( '12.345' ); $product->set_status( 'publish' ); $product->save(); $products[] = $product;
    $parent = new WC_Product_Variable(); $parent->set_name( 'Variation parent' ); $parent->set_status( 'publish' ); $parent->save(); $products[] = $parent;
    $variation = new WC_Product_Variation(); $variation->set_parent_id( $parent->get_id() ); $variation->set_regular_price( '3.333' ); $variation->set_status( 'publish' ); $variation->save(); $products[] = $variation;
    update_option( 'woocommerce_default_country', 'US:CA' );
    $tax_class = WC_Tax::create_tax_class( 'YR0084 fixture ' . wp_generate_uuid4() );
    $assert( is_array( $tax_class ) && isset( $tax_class['slug'] ), 'Owner creates isolated tax class fixture.' );
    foreach ( [ '' => '7.25', $tax_class['slug'] => '3.50' ] as $class => $rate ) {
        $tax_rates[] = WC_Tax::_insert_tax_rate( [ 'tax_rate_country' => 'US', 'tax_rate_state' => 'CA', 'tax_rate' => $rate, 'tax_rate_name' => 'Fixture tax', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 1, 'tax_rate_class' => $class ] );
    }
    $variation->set_tax_class( $tax_class['slug'] ); $variation->save();
    $params = [ 'line_items' => [ [ 'product_id' => $product->get_id(), 'quantity' => 3 ], [ 'product_id' => $variation->get_id(), 'quantity' => 2 ] ] ];
    $plan = [ 'type' => 'single', 'action' => 'orders.create', 'params' => $params ];
    $parsed = [ 'meta' => [ 'pending_operation_token' => 'fixture-pending', 'conversation_id' => 'fixture-conversation' ] ];
    foreach ( [ 'yes', 'no' ] as $taxes ) {
        foreach ( [ 'yes', 'no' ] as $included ) {
            foreach ( [ 'yes', 'no' ] as $round_subtotal ) {
                foreach ( [ 0, 2, 6 ] as $decimals ) {
                    update_option( 'woocommerce_calc_taxes', $taxes ); update_option( 'woocommerce_prices_include_tax', $included ); update_option( 'woocommerce_tax_round_at_subtotal', $round_subtotal ); update_option( 'woocommerce_price_num_decimals', $decimals ); update_option( 'woocommerce_tax_based_on', 'base' );
                    add_filter( 'query', $deny_write );
                    try {
                        $preflight = YoRo_Safety_Contract::preflight( $plan );
                        $quote = $preflight['preview']['operations'][0]['preview'];
                        $assert( true === ( $quote['estimate'] ?? null ) && false === $quote['final'], 'Owner preview is explicitly non-final.' );
                        $assert( $quote['line_items'][0]['estimated_catalog_amount'] === wc_format_decimal( wc_get_price_to_display( $product, [ 'qty' => 3 ] ), $decimals ), 'Catalog amount comes from the Woo owner.' );
                        $receipt = YoRo_Runtime_Guard::creation_receipt( $plan, $parsed, $preflight );
                        $assert( YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $plan, $parsed, $preflight ), 'Current displayed receipt verifies.' );
                        $assert( 0 === $writes, 'Preview and receipt perform no persistent writes.' );
                    } finally { remove_filter( 'query', $deny_write ); }
                    $matrix_result = YoRo_Orders_Write_Service::create( $params, [ 'plan' => $plan, 'preview_hash' => $preflight['preview_hash'] ] );
                    $assert( is_array( $matrix_result ) && $matrix_result['success'], 'Owner lifecycle succeeds across tax/include/rounding/decimal matrix.' );
                    $matrix_order = wc_get_order( $matrix_result['order_creation']['order_id'] );
                    $assert( $matrix_result['order_creation']['total'] === wc_format_decimal( $matrix_order->get_total(), $decimals ), 'Reported total is the reloaded Woo owner total.' );
                    $assert( 'yes' === $taxes ? (float) $matrix_order->get_total_tax() > 0 : (float) $matrix_order->get_total_tax() === 0.0, 'Tax enabled/disabled uses actual owner rates from multiple classes.' );

                }
            }
        }
    }
    $preflight = YoRo_Safety_Contract::preflight( $plan );
    $receipt = YoRo_Runtime_Guard::creation_receipt( $plan, $parsed, $preflight );
    foreach ( [ null, '', $receipt . 'x', substr( $receipt, 1 ), 'a.b.c' ] as $invalid ) { $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $invalid, $plan, $parsed, $preflight ), 'Malformed/tampered receipt rejected.' ); }
    $other = $parsed; $other['meta']['conversation_id'] = 'other';
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $plan, $other, $preflight ), 'Cross-conversation receipt rejected.' );
    $other = $parsed; $other['meta']['pending_operation_token'] = 'other';
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $plan, $other, $preflight ), 'Cross-pending receipt rejected.' );
    $changed = $plan; $changed['params']['line_items'][0]['quantity'] = 4;
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $changed, $parsed, $preflight ), 'Changed quantity rejected.' );
    $parts = explode( '.', $receipt ); $body = json_decode( base64_decode( $parts[0] ), true ); $body['issued'] = time() - 301; $body['expires'] = time() - 1;
    $encoded = base64_encode( wp_json_encode( $body, JSON_UNESCAPED_SLASHES ) );
    $expired = $encoded . '.' . hash_hmac( 'sha256', $encoded, wp_salt( 'auth' ) );
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $expired, $plan, $parsed, $preflight ), 'Correctly signed expired receipt rejected.' );
    foreach ( [ 'user', 'blog' ] as $binding ) {
        $wrong_body = json_decode( base64_decode( $parts[0] ), true );
        $wrong_body[ $binding ] += 1;
        $wrong_encoded = base64_encode( wp_json_encode( $wrong_body, JSON_UNESCAPED_SLASHES ) );
        $wrong_receipt = $wrong_encoded . '.' . hash_hmac( 'sha256', $wrong_encoded, wp_salt( 'auth' ) );
        $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $wrong_receipt, $plan, $parsed, $preflight ), 'Signed cross-user/blog receipt is rejected.' );
    }
    $changed_action = $plan; $changed_action['action'] = 'orders.refund';
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $changed_action, $parsed, $preflight ), 'Receipt cannot cross action boundaries.' );
    $product->set_regular_price( '13.00' ); $product->save();
    $fresh = YoRo_Safety_Contract::preflight( $plan );
    $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $receipt, $plan, $parsed, $fresh ), 'Price drift after display rejects receipt.' );
    $count = count( $orders );
    $stale = YoRo_Orders_Write_Service::create( $params, [ 'plan' => $plan, 'preview_hash' => $preflight['preview_hash'] ] );
    $assert( is_wp_error( $stale ) && count( $orders ) === $count, 'Pre-write drift rejects before allocating an order.' );
    $assert( is_wp_error( YoRo_Action_Executor::execute( 'orders.create', $params, true ) ), 'Bare confirm is not authority.' );
    $assert( is_wp_error( YoRo_Action_Executor::execute( 'orders.create', $params, true, false, [ 'connector_remote_agent' => true ] ) ), 'Remote execution rejected.' );
    foreach ( [ [], [ 'line_items' => [] ], $params + [ 'status' => 'pending' ], [ 'line_items' => [ [ 'product_id' => (string) $product->get_id(), 'quantity' => 1 ] ] ], [ 'line_items' => [ $params['line_items'][0], $params['line_items'][0] ] ] ] as $invalid ) { $assert( is_wp_error( YoRo_Orders_Write_Service::creation_params( $invalid ) ), 'Closed parameters reject coercion/duplicates/extra fields.' ); }
    foreach ( [ 0, -1, 1001, '2', 2.5, null, true ] as $quantity ) {
        $invalid = [ 'line_items' => [ [ 'product_id' => $product->get_id(), 'quantity' => $quantity ] ] ];
        $assert( is_wp_error( YoRo_Orders_Write_Service::creation_params( $invalid ) ), 'Quantity bounds and exact integer type are enforced.' );
    }
    foreach ( [ $parent->get_id(), 9007199254740991 ] as $id ) {
        $assert( is_wp_error( YoRo_Orders_Write_Service::preview_create( [ 'line_items' => [ [ 'product_id' => $id, 'quantity' => 1 ] ] ] ) ), 'Variable parent and nonexistent product cannot become order lines.' );
    }
    $product->set_stock_status( 'outofstock' ); $product->save();
    $assert( is_wp_error( YoRo_Orders_Write_Service::preview_create( $params ) ), 'Unavailable product blocks preview.' );
    $product->set_stock_status( 'instock' ); $product->set_price( '' ); $product->set_regular_price( '' ); $product->save();
    $assert( is_wp_error( YoRo_Orders_Write_Service::preview_create( $params ) ), 'Unpurchasable product blocks preview.' );
    $product->set_regular_price( '13.00' ); $product->save();
    foreach ( [ 'woocommerce_price_num_decimals' => 2, 'woocommerce_tax_round_at_subtotal' => 'yes', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_default_country' => 'US:NY' ] as $key => $value ) {
        $original = get_option( $key );
        $before_change = YoRo_Safety_Contract::preflight( $plan );
        $before_receipt = YoRo_Runtime_Guard::creation_receipt( $plan, $parsed, $before_change );
        update_option( $key, $value );
        $after_change = YoRo_Safety_Contract::preflight( $plan );
        $assert( ! YoRo_Runtime_Guard::verify_creation_receipt( $before_receipt, $plan, $parsed, $after_change ), 'Changed tax/configuration invalidates displayed approval: ' . $key );
        update_option( $key, $original );
    }
    $fresh = YoRo_Safety_Contract::preflight( $plan );
    $used = false;
    $claim = static function ( $action, $input ) use ( &$used, $params, $plan, $fresh ) { if ( $used || 'orders.create' !== $action || $input !== $params ) { return false; } $used = true; return [ 'plan' => $plan, 'preview_hash' => $fresh['preview_hash'] ]; };
    $result = YoRo_Action_Executor::execute( 'orders.create', $params, true, false, [], $claim );
    $assert( ! is_wp_error( $result ) && true === $result['success'], 'Claimed execution succeeds: ' . wp_json_encode( $result ) );
    $assert( count( $orders ) === $count + 1, 'Exactly one durable order allocated.' );
    $wire = YoRo_Commerce_Contract_Builder::canonical_execution_payload( $result, 'orders.create' );
    $assert( true === $wire['success'] && false === $wire['data']['order_creation']['outcome_unknown'], 'Canonical final facts verified.' );
    $assert( is_wp_error( YoRo_Action_Executor::execute( 'orders.create', $params, true, false, [], $claim ) ) && count( $orders ) === $count + 1, 'Consumed closure never creates a second order.' );
    $failure = static function () { throw new RuntimeException( 'Fixture item lifecycle failure' ); };
    add_action( 'woocommerce_new_order_item', $failure );
    try { $partial = YoRo_Orders_Write_Service::create( $params, [ 'plan' => $plan, 'preview_hash' => YoRo_Safety_Contract::preflight( $plan )['preview_hash'] ] ); }
    finally { remove_action( 'woocommerce_new_order_item', $failure ); }
    $assert( false === $partial['success'] && true === $partial['order_creation']['outcome_unknown'] && $partial['order_creation']['order_id'] > 0 && null === $partial['order_creation']['total'], 'Partial creation preserves safely known ID but no final-money claim.' );
    $assert( 0 === $unexpected_mail, 'No real mail attempted by the admitted pending lifecycle.' );
    $id_hook = static function ( $and_taxes, $order ) {
        if ( $order->get_id() > 0 ) {
            foreach ( $order->get_items() as $item ) { $item->set_total( 1 ); $item->set_subtotal( 1 ); }
        }
    };
    add_action( 'woocommerce_order_before_calculate_totals', $id_hook, 10, 2 );
    try {
        $hook_preview = YoRo_Safety_Contract::preflight( $plan );
        $hook_result = YoRo_Orders_Write_Service::create( $params, [ 'plan' => $plan, 'preview_hash' => $hook_preview['preview_hash'] ] );
        $assert( $hook_result['success'] && (float) $hook_result['order_creation']['subtotal'] === 2.0, 'ID-dependent owner hook runs during actual calculation, not preview.' );
        $assert( (float) $hook_preview['preview']['operations'][0]['preview']['line_items'][0]['estimated_catalog_amount'] > 2.0, 'Counterexample proves catalog estimate is not promised as final subtotal.' );
    } finally { remove_action( 'woocommerce_order_before_calculate_totals', $id_hook, 10 ); }
    // Actual REST ingress; only the test transport is replaced, never the owner execution.
    $rest_options = [];
    foreach ( [ 'yoro_rate_limit_count', 'yoro_safety_profile', YoRo_AI_Settings::OPTION_AI_API_URL, YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, YoRo_AI_Settings::OPTION_AI_API_ENABLED, YoRo_AI_Settings::OPTION_AI_API_STATUS ] as $key ) { $rest_options[ $key ] = get_option( $key, null ); }
    update_option( 'yoro_rate_limit_count', 1000 ); update_option( 'yoro_safety_profile', 'assisted' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_URL, 'https://fixture.example.invalid/api/yoro-ai' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_SITE_KEY, 'fixture-local-only' );
    update_option( YoRo_AI_Settings::OPTION_AI_API_ENABLED, 'yes' );
    YoRo_AI_Settings::update_ai_api_status( [
        'account_connection' => [ 'connected' => true, 'status' => 'active' ],
        'organization' => [ 'id' => 1, 'name' => 'Fixture' ],
        'site' => [ 'id' => 1, 'name' => 'Fixture', 'url' => home_url(), 'status' => 'active', 'plan' => 'test' ],
        'connection' => [ 'id' => 1, 'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(), 'status' => 'active', 'plugin_version' => YORO_AI_CORE_VERSION ],
        'credential' => [ 'id' => 1, 'type' => 'wordpress_connection', 'site_connection_id' => 1, 'expires_at' => gmdate( 'c', time() + HOUR_IN_SECONDS ) ],
    ] );
    $rest_consumed = false; $confirm_calls = 0; $claim_calls = 0; $material = []; $rest_mode = 'success';
    $http = static function ( $pre, $args, $url ) use ( &$rest_consumed, &$confirm_calls, &$claim_calls, &$material, &$rest_mode, $plan ) {
        if ( ! str_starts_with( $url, 'https://fixture.example.invalid/' ) ) { return $pre; }
        $body = json_decode( $args['body'] ?? '{}', true );
        $response = array_merge( (array) get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, [] ), [ 'success' => true, 'actor_access' => [ 'assigned' => true, 'status' => 'active', 'role' => 'owner', 'permissions' => YoRo_Capabilities::all() ] ] );
        if ( str_contains( $url, '/actions/confirm' ) ) {
            ++$confirm_calls;
            $material = [ 'token' => str_repeat( 'p', 64 ), 'plan_hash' => $body['plan_hash'], 'preview_hash' => $body['preview_hash'], 'policy_version' => 1 ];
            if ( 'wrong_hash' === $rest_mode ) { $material['preview_hash'] = str_repeat( '0', 64 ); }
            $response['execution_permit'] = $material;
        } elseif ( str_contains( $url, '/actions/claim' ) ) {
            ++$claim_calls;
            $response += [ 'stage' => 'claimed', 'pending_operation_status' => 'executing', 'conversation_id' => 'creation-rest', 'pending_operation_token' => 'creation-rest-pending', 'plan_hash' => $material['plan_hash'], 'preview_hash' => $material['preview_hash'], 'policy_version' => 1 ];
            if ( $rest_consumed ) { $response = [ 'success' => false, 'message' => 'Consumed fixture operation.' ]; }
            $rest_consumed = true;
        } elseif ( 'GET' === ( $args['method'] ?? 'GET' ) ) {
            $response['conversation'] = [ 'pending_operation_graphs' => [ [ 'token' => 'creation-rest-pending', 'status' => $rest_consumed ? 'executing' : 'confirmation_required', 'action' => 'orders.create', 'action_plan' => $plan, 'plan_hash' => str_repeat( 'a', 64 ), 'approval_groups' => [] ] ] ];
        }
        return [ 'headers' => [], 'body' => wp_json_encode( $response ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
    };
    $rest = static function ( $confirm, $receipt = null ) {
        $request = new WP_REST_Request( 'POST', '/yoro/v1/execute' );
        foreach ( [ 'action' => 'orders.create', 'params' => [], 'confirm' => $confirm, 'conversation_id' => 'creation-rest', 'pending_operation_token' => 'creation-rest-pending', 'displayed_preview_receipt' => $receipt ] as $key => $value ) { $request->set_param( $key, $value ); }
        $result = YoRo_REST_Execute_Handler::handle( $request );
        return $result instanceof WP_REST_Response ? $result->get_data() : $result;
    };
    add_filter( 'pre_http_request', $http, 100, 3 );
    try {
        $status = get_option( YoRo_AI_Settings::OPTION_AI_API_STATUS );
        YoRo_AI_Settings::save_connected_ai_api_credentials( 'https://fixture.example.invalid/api/yoro-ai', 'fixture-local-only' );
        update_option( YoRo_AI_Settings::OPTION_AI_API_STATUS, $status );
        $display = $rest( false );
        $assert( is_array( $display ) && ! empty( $display['displayed_preview_receipt'] ) && true === $display['result']['preview']['estimate'], 'REST displays a signed owner estimate: ' . wp_json_encode( $display ) );
        $before = count( $orders );
        $assert( is_wp_error( $rest( true ) ) && 0 === $confirm_calls && 0 === $claim_calls, 'Missing receipt stops before API confirm/claim.' );
        $assert( is_wp_error( $rest( true, $display['displayed_preview_receipt'] . 'x' ) ) && 0 === $confirm_calls && 0 === $claim_calls, 'Tampered receipt stops before API confirm/claim.' );
        $product->set_regular_price( '14.00' ); $product->save();
        $assert( is_wp_error( $rest( true, $display['displayed_preview_receipt'] ) ) && 0 === $confirm_calls && count( $orders ) === $before, 'REST price drift stops before confirm and owner creation.' );
        $display = $rest( false );
        $success = $rest( true, $display['displayed_preview_receipt'] );
        $assert( is_array( $success ) && true === $success['success'] && 1 === $confirm_calls && 1 === $claim_calls && count( $orders ) === $before + 1, 'Real REST receipt/confirm/claim invokes owner exactly once: ' . wp_json_encode( $success ) );
        $repeat = $rest( true, $display['displayed_preview_receipt'] );
        $assert( ( is_wp_error( $repeat ) || empty( $repeat['success'] ) ) && count( $orders ) === $before + 1 && 1 === $claim_calls, 'REST replay never claims or creates a second order.' );
        // A separately initialized mock pending operation exercises contradictory API material.
        $rest_consumed = false; $rest_mode = 'wrong_hash';
        $display = $rest( false ); $before = count( $orders );
        $wrong = $rest( true, $display['displayed_preview_receipt'] );
        $assert( ( is_wp_error( $wrong ) || ( is_array( $wrong ) && false === ( $wrong['success'] ?? null ) ) ) && count( $orders ) === $before, 'A claimed API preview hash differing from the displayed local hash never reaches owner creation: ' . wp_json_encode( $wrong ) );

    } finally {
        remove_filter( 'pre_http_request', $http, 100 );
        foreach ( $rest_options as $key => $value ) { if ( null === $value ) { delete_option( $key ); } else { update_option( $key, $value ); } }
    }

    $ui_test = <<<'JS'
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const root = process.argv[1];
const display = JSON.parse(process.argv[2]);
const admin = fs.readFileSync(root + '/assets/js/yoro-ai-admin.js', 'utf8');
const extract = name => {
    const start = admin.indexOf('function ' + name + '(');
    assert(start >= 0);
    const end = admin.indexOf('\n\t\t}', start);
    assert(end > start);
    return admin.slice(start, end + 4);
};
const sent = [];
const sandbox = { conversationState: {}, YoRoAIAdmin: { executeUrl: 'fixture' },
    saveStoredConversationId() {}, refreshAiApiStatusConversationLink() {}, syncBeforeUnloadGuard() {},
    beginProtectedWork() { return () => {}; },
    requestJson(url, options) { sent.push(JSON.parse(options.body)); return Promise.resolve({}); } };
vm.createContext(sandbox);
vm.runInContext(extract('updateConversationState') + '\n' + extract('executeActionDirect'), sandbox);
sandbox.updateConversationState(display, 'fixture');
assert.strictEqual(sandbox.conversationState.order_creation_receipt.value, display.displayed_preview_receipt);
sandbox.executeActionDirect('orders.create', {}, true, false, '', display.pending_operation_token);
assert.strictEqual(sent[0].displayed_preview_receipt, display.displayed_preview_receipt);
assert(!('preview_hash' in sent[0]));
assert(!('expected_order_create_state' in sent[0]));
assert.strictEqual(sandbox.conversationState.order_creation_receipt, null);
sandbox.executeActionDirect('orders.create', {}, true, false, '', display.pending_operation_token);
assert(!('displayed_preview_receipt' in sent[1]));
sandbox.updateConversationState(display, 'fixture');
sandbox.updateConversationState({conversation_id: 'other', pending_operation_token: 'other'}, 'fixture');
assert.strictEqual(sandbox.conversationState.order_creation_receipt, null);
const renderer = {window: {}};
vm.createContext(renderer);
vm.runInContext(fs.readFileSync(root + '/assets/js/yoro-ai-result-renderers.js', 'utf8'), renderer);
const escapeHtml = value => String(value ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
const instance = renderer.window.YoRoResultRenderers.init({escapeHtml, shouldShowDebugJson: () => false});
display.result.preview.line_items[0].name = '<script>fixture</script>';
const html = instance.renderResult(display);
assert(html.includes('Estimate only'));
assert(html.includes('not final order'));
assert(html.includes('Approve change'));
assert(!html.includes('<script>fixture'));
assert(!html.includes(display.displayed_preview_receipt));
const missing = JSON.parse(JSON.stringify(display)); delete missing.displayed_preview_receipt;
assert(!instance.renderResult(missing).includes('Approve change'));
console.log('UI receipt store/echo/replay/cross-conversation and escaped estimate rendering PASS');
JS;
    $pipes = [];
    $process = proc_open( [ 'node', '-e', $ui_test, rtrim( YORO_AI_CORE_PATH, '/' ), wp_json_encode( $display ) ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
    $assert( is_resource( $process ), 'Node is required for the actual admin UI receipt round trip.' );
    fclose( $pipes[0] ); $ui_output = stream_get_contents( $pipes[1] ); $ui_error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
    $assert( 0 === proc_close( $process ), 'Admin UI behavior: ' . $ui_output . $ui_error );
    echo $ui_output;

    $assert( 0 === $unexpected_effect && 0 === $unexpected_mail, 'No payment, stock reduction/restoration or mail effect was invoked.' );
    $assert( 0 === $privacy_reads, 'Creation does not read owner IP/user-agent view getters.' );
    $source = file_get_contents( YORO_AI_CORE_PATH . 'includes/actions/class-yoro-orders-write-service.php' );
    $creation_source = substr( $source, 0, strpos( $source, 'private static function prepare_refund' ) );
    $assert( ! preg_match( '/get_customer_(ip_address|user_agent)|HTTP_USER_AGENT|REMOTE_ADDR|set_customer_(ip_address|user_agent)/', $creation_source ), 'YoRo creation has no IP/user-agent access or copy path, including edit-context getters.' );
    echo "Order creation contracts passed: {$assertions} assertions; WooCommerce " . WC_VERSION . "; storage=" . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? "HPOS" : "CPT" ) . ".\n";
} finally {
    foreach ( $effect_hooks as $hook ) { remove_action( $hook, $effect_probe ); }
    foreach ( $privacy_hooks as $hook ) { remove_filter( $hook, $privacy_probe ); }
    remove_filter( 'query', $deny_write ); remove_action( 'woocommerce_new_order', $created ); remove_filter( 'pre_wp_mail', $no_mail );
    foreach ( array_unique( $orders ) as $id ) { $order = wc_get_order( $id ); if ( $order ) { $order->delete( true ); } }
    foreach ( array_reverse( $products ) as $product ) { $product->delete( true ); }
    foreach ( $tax_rates as $id ) { WC_Tax::_delete_tax_rate( $id ); }
    if ( is_array( $tax_class ) ) { WC_Tax::delete_tax_class_by( 'slug', $tax_class['slug'] ); }
    foreach ( $options as $key => $value ) { update_option( $key, $value ); }
    foreach ( $actor_meta as $key => $values ) { delete_user_meta( $admins[0], $key ); foreach ( $values as $value ) { add_user_meta( $admins[0], $key, $value ); } }
    wp_set_current_user( $before_user );
}
