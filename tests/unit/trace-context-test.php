<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

$GLOBALS['yoro_trace_filters'] = [];

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['yoro_trace_filters'][] = [
		'hook'          => $hook,
		'callback'      => $callback,
		'priority'      => $priority,
		'accepted_args' => $accepted_args,
	];

	return true;
}

function wp_generate_uuid4() {
	return '5d29d418-7f5d-4c18-aabd-6faf7f01a9b1';
}

class YoRo_AI_Settings {
	public static function get_ai_api_url() {
		return 'https://api.example.test/api/v1';
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/core/class-yoro-trace-context.php';

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

YoRo_Trace_Context::register();
$assert( 1 === count( $GLOBALS['yoro_trace_filters'] ), 'Trace transport filter was not registered exactly once.' );
$assert( 'http_request_args' === ( $GLOBALS['yoro_trace_filters'][0]['hook'] ?? '' ), 'Trace filter uses the wrong WordPress hook.' );
$assert( 2 === ( $GLOBALS['yoro_trace_filters'][0]['accepted_args'] ?? 0 ), 'Trace filter must receive the request URL.' );

$args = YoRo_Trace_Context::attach_to_yoro_api_request(
	[
		'headers' => [
			'Authorization' => 'Bearer site-key',
		],
	],
	'https://api.example.test/api/v1/conversations/message'
);

$assert(
	'5d29d418-7f5d-4c18-aabd-6faf7f01a9b1' === ( $args['headers']['X-YoRo-Trace-Id'] ?? '' ),
	'YoRo API request did not receive a canonical trace ID.'
);
$assert( 'Bearer site-key' === ( $args['headers']['Authorization'] ?? '' ), 'Trace propagation changed authorization material.' );

$second = YoRo_Trace_Context::attach_to_yoro_api_request(
	[ 'headers' => [] ],
	'https://api.example.test/api/v1/sites/handshake'
);
$assert(
	( $second['headers']['X-YoRo-Trace-Id'] ?? '' ) === ( $args['headers']['X-YoRo-Trace-Id'] ?? '' ),
	'Outbound requests in one WordPress request did not share the same trace ID.'
);

$outside = YoRo_Trace_Context::attach_to_yoro_api_request(
	[ 'headers' => [ 'Accept' => 'application/json' ] ],
	'https://api.example.test/unrelated'
);
$assert( ! isset( $outside['headers']['X-YoRo-Trace-Id'] ), 'Trace ID leaked outside the configured API base path.' );

$other_host = YoRo_Trace_Context::attach_to_yoro_api_request(
	[ 'headers' => [] ],
	'https://example.org/api/v1/health'
);
$assert( ! isset( $other_host['headers']['X-YoRo-Trace-Id'] ), 'Trace ID leaked to an unrelated host.' );

$incoming = '7bc6afe5-12c6-4dda-8f80-1fbda8b45797';
$preserved = YoRo_Trace_Context::attach_to_yoro_api_request(
	[
		'headers' => [
			'x-yoro-trace-id' => strtoupper( $incoming ),
		],
	],
	'https://api.example.test/api/v1/health'
);
$assert( $incoming === ( $preserved['headers']['X-YoRo-Trace-Id'] ?? '' ), 'Valid caller trace ID was not normalized and preserved.' );
$assert( ! isset( $preserved['headers']['x-yoro-trace-id'] ), 'Duplicate case-variant trace header was not removed.' );

if ( [] !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo trace context unit contract: PASS\n";
