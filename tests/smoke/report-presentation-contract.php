<?php
/** Read-only local WordPress bridge/projection checks; optional paired API fixtures. */
if ( ! defined( 'ABSPATH' ) || ! in_array( wp_get_environment_type(), [ 'local', 'development' ], true ) ) {
	throw new RuntimeException( 'Run this contract in local/development WordPress with wp eval-file.' );
}
$root = dirname( __DIR__, 2 );
foreach ( [ 'conversation-action-coordinator', 'chat-message-handler', 'execute-handler' ] as $file ) {
	require_once $root . '/includes/rest/class-yoro-rest-' . $file . '.php';
}
$assert = static function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$calls = 0;
$guard = static function () use ( &$calls ) {
	++$calls;
	return new WP_Error( 'unexpected_report_http', 'Report projection must not make HTTP requests.' );
};
add_filter( 'pre_http_request', $guard, 1, 3 );
try {
	$report = [ 'type' => 'sales_report', 'metrics' => [ [ 'key' => 'gross_sales', 'label' => 'Gross sales', 'value' => '1200', 'unit' => 'USD' ] ], 'window' => [ 'key' => 'today', 'timezone' => 'UTC' ] ];
	$presentation = [ 'version' => 1, 'conversation_id' => 'report-contract-one', 'action_event_id' => 42, 'result_id' => 'report-contract-one:42', 'operation_id' => 'op_report', 'action' => 'store.get_business_summary', 'summary' => 'Gross sales: 1200 USD.', 'mode' => 'summary_first', 'state' => 'complete', 'has_details' => true, 'expanded' => false, 'report' => $report ];
	$responses = [ [ 'report_presentation' => $presentation, 'action_event' => [ 'id' => 42 ] ] ];
	$fixture_path = getenv( 'YORO_REPORT_PRESENTATION_FIXTURE' );
	if ( $fixture_path ) {
		$fixture = json_decode( file_get_contents( $fixture_path ), true, 512, JSON_THROW_ON_ERROR );
		$responses = $fixture['responses'];
		$assert( count( $responses ) >= 4, 'The paired API fixture must exercise all presentation modes.' );
	}
	foreach ( $responses as $response ) {
		$p = $response['report_presentation'];
		$raw = [ 'success' => true, 'action' => $p['action'], 'report' => $report, 'read_only' => true, 'summary' => 'unaccepted local copy' ];
		$accepted = YoRo_REST_Conversation_Action_Coordinator::apply_report_presentation( $raw, $response, $p['conversation_id'], $p['action'], $p['operation_id'] );
		$assert( $accepted['report_presentation'] === $p && $accepted['summary'] === $p['summary'], 'Bridge must use the accepted API result.' );
		$assert( $accepted['report'] === $p['report'], 'Summary and full view must use the same accepted report.' );
		foreach ( [ [ 'conversation_id', 'other' ], [ 'operation_id', 'other' ], [ 'action_event_id', 99 ], [ 'result_id', 'stale' ], [ 'action', 'orders.update_status' ] ] as $bad ) {
			$mismatch = $response;
			$mismatch['report_presentation'][ $bad[0] ] = $bad[1];
			$rejected = YoRo_REST_Conversation_Action_Coordinator::apply_report_presentation( $raw, $mismatch, $p['conversation_id'], $p['action'], $p['operation_id'] );
			$assert( ! isset( $rejected['report'] ) && ! $rejected['report_presentation']['has_details'], 'Mismatched reference must not expose local or remote details.' );
		}
		$mutation = [ 'requires_confirmation' => true, 'action' => 'orders.update_status', 'summary' => 'Confirm status change' ];
		$assert( YoRo_REST_Conversation_Action_Coordinator::apply_report_presentation( $mutation, $response, $p['conversation_id'], 'orders.update_status' ) === $mutation, 'Report projection must not alter mutation confirmation.' );
		$format = new ReflectionMethod( YoRo_REST_Chat_Message_Handler::class, 'format_conversation_show_response' );
		$history = $format->invoke( null, [ 'conversation' => [ 'id' => $p['conversation_id'], 'messages' => [ [ 'id' => 9, 'role' => 'assistant', 'content' => $p['summary'], 'payload' => [ 'stage' => 'action_event', 'status' => 'executed', 'action' => $p['action'], 'action_event_id' => $p['action_event_id'], 'report_presentation' => $p ] ] ] ] ], $p['conversation_id'] );
		$assert( $history['messages'][0]['ui']['result']['report_presentation'] === $p, 'History hydration must reuse the same accepted presentation.' );
	}
	$assert( $calls === 0, 'Bridge/hydration must make zero model or executor requests.' );

	$output = getenv( 'YORO_CORE_REPORT_FIXTURE_OUT' );
	if ( $output ) {
		$assert( str_starts_with( $output, '/' ) && is_dir( dirname( $output ) ) && ! str_starts_with( realpath( dirname( $output ) ), $root ), 'Fixture output must be external to the repository.' );
		$window = [ 'key' => 'today', 'label' => 'Today', 'date_from' => '2026-09-08', 'date_to' => '2026-09-08', 'start' => '2026-09-08 00:00:00', 'end' => '2026-09-08 23:59:59', 'start_ts' => 1788825600, 'end_ts' => 1788911999, 'timezone' => 'UTC' ];
		$signals = new ReflectionMethod( YoRo_Orders_Read_Service::class, 'build_store_insights_report' );
		$signal_report = $signals->invoke( null, $window, 5, 1200, 240, 3, 1, 33.3, 2, 1, 8, 1.6, [ 'out_of_stock' => 1, 'low_stock' => 2, 'on_backorder' => 1 ], [], [], [] );
		$business = new ReflectionMethod( YoRo_Store_Briefing_Service::class, 'build_business_report' );
		$business_report = $business->invoke( null, $window, [ 'order_count' => 5, 'gross_sales' => '1200', 'net_sales' => '1100', 'average_order_value' => '240', 'unique_customers' => 3, 'items_sold' => 8 ], [], 0, 0, [], [], [], [] );
		$assert( in_array( 'unique_customers', array_column( $signal_report['metrics'], 'key' ), true ), 'Actual signals provider must emit unique_customers.' );
		$assert( in_array( 'active_customers', array_column( $business_report['metrics'], 'key' ), true ), 'Actual business provider must emit active_customers.' );
		file_put_contents( $output, wp_json_encode( [ 'reports' => [ 'store.get_insights' => $signal_report, 'store.get_business_summary' => $business_report ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}
	echo "PASS report-presentation-contract: accepted bridge, mismatches, hydration, mutation isolation, zero HTTP calls\n";
} finally {
	remove_filter( 'pre_http_request', $guard, 1 );
}
