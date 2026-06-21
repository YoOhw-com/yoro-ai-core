<?php
/**
 * YoRo mailbox inbox REST bridge smoke contract.
 *
 * Run from the WordPress root with:
 * wp eval-file wp-content/plugins/yoro-ai-core/tests/smoke/mailbox-inbox-rest-contract.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this smoke contract through WordPress, for example with wp eval-file.\n" );
	exit( 1 );
}

$plugin_root = dirname( __DIR__, 2 );

if ( ! class_exists( 'YoRo_AI_Core' ) && file_exists( $plugin_root . '/yoro-ai-core.php' ) ) {
	require_once $plugin_root . '/yoro-ai-core.php';
}

if (
	! class_exists( 'YoRo_REST_API' ) &&
	class_exists( 'YoRo_AI_Core' ) &&
	function_exists( 'yoro_ai_core_has_woocommerce' ) &&
	yoro_ai_core_has_woocommerce()
) {
	YoRo_AI_Core::init();
}

if ( function_exists( 'rest_get_server' ) && ! did_action( 'rest_api_init' ) ) {
	do_action( 'rest_api_init' );
}

$failures = [];

$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert( class_exists( 'YoRo_REST_API' ), 'YoRo_REST_API is not loaded.' );
$assert( class_exists( 'YoRo_AI_API_Client' ), 'YoRo_AI_API_Client is not loaded.' );
$assert( class_exists( 'WP_REST_Request' ), 'WP_REST_Request is not available.' );

$admin_ids = get_users(
	[
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	]
);
$admin_id = ! empty( $admin_ids ) ? absint( $admin_ids[0] ) : 0;

$assert( $admin_id > 0, 'A WordPress administrator user is required for mailbox REST permission smoke checks.' );

if ( $admin_id > 0 ) {
	wp_set_current_user( $admin_id );
}

$option_names = [
	'yoro_ai_api_url',
	'yoro_ai_api_site_key',
	'yoro_ai_api_enabled',
	'yoro_ai_api_status',
	'woocommerce_email_from_address',
];
$missing_option_value = '__yoro_missing_option__';
$original_options = [];

foreach ( $option_names as $option_name ) {
	$value = get_option( $option_name, $missing_option_value );
	$original_options[ $option_name ] = [
		'exists' => $missing_option_value !== $value,
		'value'  => $value,
	];
}

$restore_options = static function () use ( $original_options ) {
	foreach ( $original_options as $option_name => $snapshot ) {
		if ( ! empty( $snapshot['exists'] ) ) {
			update_option( $option_name, $snapshot['value'], false );
			continue;
		}

		delete_option( $option_name );
	}
};

register_shutdown_function( $restore_options );

update_option( 'yoro_ai_api_url', 'http://yoro-ai-api.test/api/v1', false );
update_option( 'yoro_ai_api_site_key', 'site-key-test', false );
update_option( 'yoro_ai_api_enabled', 'yes', false );
update_option( 'woocommerce_email_from_address', 'support@example.test', false );
YoRo_AI_Settings::update_ai_api_status(
	[
		'connected'    => true,
		'entitlements' => [
			'conversation'    => true,
			'email_composer' => true,
			'email_inbox'    => true,
		],
		'usage'        => [
			'remaining' => 100,
		],
	]
);

$requests = [];

$http_filter = static function ( $preempt, $args, $url ) use ( &$requests ) {
	$requests[] = [
		'url'     => $url,
		'method'  => strtoupper( (string) ( $args['method'] ?? ( ! empty( $args['body'] ) ? 'POST' : 'GET' ) ) ),
		'headers' => (array) ( $args['headers'] ?? [] ),
		'body'    => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
	];

	$payload = [ 'success' => true ];

	if ( false !== strpos( $url, '/email/gmail/connect-intents' ) ) {
		$payload['authorization_url'] = 'https://accounts.google.com/o/oauth2/v2/auth?state=test';
	} elseif ( false !== strpos( $url, '/email/microsoft/connect-intents' ) ) {
		$payload['authorization_url'] = 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?state=test';
	} elseif ( false !== strpos( $url, '/email/imap/connect' ) ) {
		$payload['connection'] = [
			'provider'      => 'imap',
			'mailbox_email' => 'support@example.test',
		];
		$payload['sync'] = [
			'messages_synced' => 1,
		];
	} elseif ( false !== strpos( $url, '/email/gmail/disconnect' ) ) {
		$payload['disconnected'] = true;
		$payload['revoked']      = true;
	} elseif ( false !== strpos( $url, '/email/disconnect' ) ) {
		$payload['disconnected'] = true;
		$payload['provider']     = 'imap';
	} elseif ( false !== strpos( $url, '/email/inbox/sync' ) ) {
		$payload['messages_synced'] = 1;
	} elseif ( preg_match( '#/email/threads/77/reply$#', $url ) ) {
		$payload['status'] = 'sent';
	} elseif ( preg_match( '#/email/threads/77$#', $url ) ) {
		$payload['thread'] = [
			'id'      => 77,
			'subject' => 'Shipping question',
		];
		$payload['messages'] = [];
	} elseif ( false !== strpos( $url, '/email/inbox' ) ) {
		$payload['connected'] = true;
		$payload['connection'] = [
			'mailbox_email' => 'support@example.test',
		];
		$payload['threads'] = [];
	}

	return [
		'headers'  => [],
		'body'     => wp_json_encode( $payload ),
		'response' => [
			'code'    => 200,
			'message' => 'OK',
		],
		'cookies'  => [],
	];
};

add_filter( 'pre_http_request', $http_filter, 10, 3 );

$dispatch_rest = static function ( $method, $route, $params = [] ) {
	$request = new WP_REST_Request( $method, $route );

	foreach ( (array) $params as $key => $value ) {
		$request->set_param( $key, $value );
	}

	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [
			'status' => (int) ( $response->get_error_data()['status'] ?? 500 ),
			'data'   => [
				'success' => false,
				'code'    => $response->get_error_code(),
				'message' => $response->get_error_message(),
			],
		];
	}

	return [
		'status' => $response->get_status(),
		'data'   => $response->get_data(),
	];
};

$connect_response = $dispatch_rest( 'POST', '/yoro-ai-core/v1/email/gmail/connect-intents' );
$microsoft_connect_response = $dispatch_rest( 'POST', '/yoro-ai-core/v1/email/microsoft/connect-intents' );
$imap_connect_response = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/email/imap/connect',
	[
		'mailbox_email'   => 'support@example.test',
		'display_name'    => 'Shop Support',
		'imap_host'       => 'imap.example.test',
		'imap_port'       => 993,
		'imap_encryption' => 'ssl',
		'imap_username'   => 'support@example.test',
		'imap_password'   => 'secret',
		'smtp_host'       => 'smtp.example.test',
		'smtp_port'       => 587,
		'smtp_encryption' => 'tls',
		'smtp_username'   => 'support@example.test',
		'smtp_password'   => 'secret',
	]
);
$disconnect_response = $dispatch_rest( 'POST', '/yoro-ai-core/v1/email/gmail/disconnect' );
$generic_disconnect_response = $dispatch_rest( 'POST', '/yoro-ai-core/v1/email/disconnect' );
$inbox_response   = $dispatch_rest( 'GET', '/yoro-ai-core/v1/email/inbox', [ 'limit' => 25 ] );
$sync_response    = $dispatch_rest( 'POST', '/yoro-ai-core/v1/email/inbox/sync', [ 'limit' => 10 ] );
$thread_response  = $dispatch_rest( 'GET', '/yoro-ai-core/v1/email/threads/77' );
$reply_response   = $dispatch_rest(
	'POST',
	'/yoro-ai-core/v1/email/threads/77/reply',
	[
		'subject' => 'Shipping question',
		'body'    => 'Thanks, we will follow up today.',
	]
);

remove_filter( 'pre_http_request', $http_filter, 10 );

$assert( 200 === (int) $connect_response['status'], 'Gmail connect intent route must return HTTP 200.' );
$assert( ! empty( $connect_response['data']['authorization_url'] ), 'Gmail connect intent must return authorization_url.' );
$assert( 200 === (int) $microsoft_connect_response['status'], 'Microsoft connect intent route must return HTTP 200.' );
$assert( ! empty( $microsoft_connect_response['data']['authorization_url'] ), 'Microsoft connect intent must return authorization_url.' );
$assert( 200 === (int) $imap_connect_response['status'], 'IMAP connect route must return HTTP 200.' );
$assert( 'imap' === ( $imap_connect_response['data']['connection']['provider'] ?? '' ), 'IMAP connect route must proxy connection provider.' );
$assert( 200 === (int) $disconnect_response['status'], 'Gmail disconnect route must return HTTP 200.' );
$assert( true === (bool) ( $disconnect_response['data']['disconnected'] ?? false ), 'Gmail disconnect route must proxy disconnected=true.' );
$assert( 200 === (int) $generic_disconnect_response['status'], 'Generic mailbox disconnect route must return HTTP 200.' );
$assert( true === (bool) ( $generic_disconnect_response['data']['disconnected'] ?? false ), 'Generic mailbox disconnect route must proxy disconnected=true.' );
$assert( 200 === (int) $inbox_response['status'], 'Mailbox inbox route must return HTTP 200.' );
$assert( true === (bool) ( $inbox_response['data']['connected'] ?? false ), 'Mailbox inbox route must proxy connected=true.' );
$assert( 200 === (int) $sync_response['status'], 'Mailbox sync route must return HTTP 200.' );
$assert( 1 === (int) ( $sync_response['data']['messages_synced'] ?? 0 ), 'Mailbox sync route must proxy sync results.' );
$assert( 200 === (int) $thread_response['status'], 'Mailbox thread route must return HTTP 200.' );
$assert( 77 === (int) ( $thread_response['data']['thread']['id'] ?? 0 ), 'Mailbox thread route must proxy the requested thread.' );
$assert( 200 === (int) $reply_response['status'], 'Mailbox reply route must return HTTP 200.' );
$assert( 'sent' === ( $reply_response['data']['status'] ?? '' ), 'Mailbox reply route must proxy sent status.' );

$urls = array_map(
	static function ( $request ) {
		return (string) $request['url'];
	},
	$requests
);

$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/gmail/connect-intents', $urls, true ), 'Gmail connect intent must proxy to Laravel /email/gmail/connect-intents.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/microsoft/connect-intents', $urls, true ), 'Microsoft connect intent must proxy to Laravel /email/microsoft/connect-intents.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/imap/connect', $urls, true ), 'IMAP connect must proxy to Laravel /email/imap/connect.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/gmail/disconnect', $urls, true ), 'Gmail disconnect must proxy to Laravel /email/gmail/disconnect.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/disconnect', $urls, true ), 'Generic disconnect must proxy to Laravel /email/disconnect.' );
$assert( count( array_filter( $urls, static fn ( $url ) => false !== strpos( $url, '/email/inbox' ) ) ) >= 2, 'Inbox and sync requests must proxy to Laravel /email/inbox endpoints.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/threads/77', $urls, true ), 'Thread request must proxy to Laravel /email/threads/{thread}.' );
$assert( in_array( 'http://yoro-ai-api.test/api/v1/email/threads/77/reply', $urls, true ), 'Reply request must proxy to Laravel /email/threads/{thread}/reply.' );

$reply_requests = array_values(
	array_filter(
		$requests,
		static fn ( $request ) => false !== strpos( (string) $request['url'], '/email/threads/77/reply' )
	)
);
$reply_body     = (array) ( $reply_requests[0]['body'] ?? [] );

$assert( 'Shipping question' === ( $reply_body['subject'] ?? '' ), 'Mailbox reply must proxy the subject for WooCommerce email heading rendering.' );
$assert( 'wordpress_woocommerce_mailer' === ( $reply_body['html_body_source'] ?? '' ), 'Mailbox reply must include WooCommerce-rendered html_body_source.' );
$assert( false !== strpos( (string) ( $reply_body['html_body'] ?? '' ), 'Thanks, we will follow up today.' ), 'Mailbox reply must include WooCommerce-rendered html_body.' );

foreach ( $requests as $request ) {
	$assert( 'Bearer site-key-test' === ( $request['headers']['Authorization'] ?? '' ), 'Mailbox proxy requests must include the YoRo site key authorization header.' );
}

$connect_requests = array_values(
	array_filter(
		$requests,
		static fn ( $request ) => false !== strpos( (string) $request['url'], '/email/gmail/connect-intents' )
	)
);
$connect_body     = (array) ( $connect_requests[0]['body'] ?? [] );

$assert( 'support@example.test' === ( $connect_body['suggested_mailbox_email'] ?? '' ), 'Gmail connect intent must include WooCommerce From email as suggested_mailbox_email.' );
$assert( false !== strpos( (string) ( $connect_body['return_url'] ?? '' ), 'yoro_admin_panel=settings' ), 'Gmail connect return_url must reopen the Settings panel.' );

$microsoft_connect_requests = array_values(
	array_filter(
		$requests,
		static fn ( $request ) => false !== strpos( (string) $request['url'], '/email/microsoft/connect-intents' )
	)
);
$microsoft_connect_body     = (array) ( $microsoft_connect_requests[0]['body'] ?? [] );

$assert( 'support@example.test' === ( $microsoft_connect_body['suggested_mailbox_email'] ?? '' ), 'Microsoft connect intent must include WooCommerce From email as suggested_mailbox_email.' );
$assert( false !== strpos( (string) ( $microsoft_connect_body['return_url'] ?? '' ), 'yoro_admin_panel=settings' ), 'Microsoft connect return_url must reopen the Settings panel.' );

$imap_connect_requests = array_values(
	array_filter(
		$requests,
		static fn ( $request ) => false !== strpos( (string) $request['url'], '/email/imap/connect' )
	)
);
$imap_connect_body     = (array) ( $imap_connect_requests[0]['body'] ?? [] );

$assert( 'support@example.test' === ( $imap_connect_body['mailbox_email'] ?? '' ), 'IMAP connect must include mailbox_email.' );
$assert( 'imap.example.test' === ( $imap_connect_body['imap_host'] ?? '' ), 'IMAP connect must include imap_host.' );
$assert( 'smtp.example.test' === ( $imap_connect_body['smtp_host'] ?? '' ), 'IMAP connect must include smtp_host.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL: {$failure}\n" );
	}

	exit( 1 );
}

echo "YoRo mailbox inbox REST bridge smoke contract passed.\n";
