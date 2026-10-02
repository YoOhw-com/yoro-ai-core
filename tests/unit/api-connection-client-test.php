<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $message ) {
	return $message;
}

function esc_url_raw( $value ) {
	return (string) $value;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
	return (string) ( $response['body'] ?? '' );
}

$GLOBALS['yoro_connection_test_requests'] = [];
$GLOBALS['yoro_connection_test_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => '{"success":true}',
];

function wp_remote_post( $url, $args ) {
	$GLOBALS['yoro_connection_test_requests'][] = [
		'url'  => $url,
		'args' => $args,
	];

	$response = $GLOBALS['yoro_connection_test_response'];
	if ( wp_remote_retrieve_response_code( $response ) >= 300 && wp_remote_retrieve_response_code( $response ) < 400 && 0 !== ( $args['redirection'] ?? 5 ) ) {
		$GLOBALS['yoro_connection_test_requests'][] = [ 'url' => $response['headers']['location'], 'args' => $args ];
		return [ 'response' => [ 'code' => 200 ], 'body' => '{"success":true}' ];
	}
	return $response;
}

function wp_remote_get( $url, $args ) { return wp_remote_post( $url, $args ); }
function wp_remote_request( $url, $args ) { return wp_remote_post( $url, $args ); }
function wp_remote_retrieve_header( $response, $name ) { return $response['headers'][ $name ] ?? ''; }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function absint( $value ) { return abs( (int) $value ); }
function untrailingslashit( $value ) { return rtrim( (string) $value, '/' ); }
function home_url() { return 'https://store.example.test'; }
function get_locale() { return 'en_US'; }
function current_time( $type ) { return '2026-10-02 00:00:00'; }
function apply_filters( $name, $value, ...$args ) { return $value; }

class YoRo_AI_Request_Tracker {
    public static function record( ...$args ) {}
}

class YoRo_AI_Settings {
    public static function ai_api_allows_feature( $feature ) { return true; }
    public static function ai_api_has_quota_remaining() { return true; }
    public static function get_ai_api_entitlements() { return []; }

	public static function get_ai_api_url() {
		return 'https://api.example.test/api/v1';
	}

	public static function get_ai_api_site_key() {
		return 'site-key';
	}

	public static function get_installation_uuid() {
		return '11111111-1111-4111-8111-111111111111';
	}
}

class YoRo_WordPress_Actor {
	public static function record_access( $access ) {}
	public static function assertion( $site_key ) {
		return 'actor-for-' . $site_key;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-connection-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/ai/interfaces/interface-yoro-ai-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-client.php';
require_once dirname( __DIR__, 2 ) . '/includes/commerce/class-yoro-connector-client.php';

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$client = new YoRo_AI_API_Connection_Client();

$result = $client->disconnect_site(
	[
		'reason' => 'Unit disconnect',
	]
);
$request = $GLOBALS['yoro_connection_test_requests'][0] ?? [];
$body    = json_decode( $request['args']['body'] ?? '{}', true );

$assert( ! is_wp_error( $result ), 'Disconnect returned an unexpected error.' );
$assert( 'https://api.example.test/api/v1/sites/disconnect' === ( $request['url'] ?? '' ), 'Disconnect URL is incorrect.' );
$assert( 'Bearer site-key' === ( $request['args']['headers']['Authorization'] ?? '' ), 'Disconnect did not use the site credential.' );
$assert( 'actor-for-site-key' === ( $request['args']['headers']['X-YoRo-Actor-Assertion'] ?? '' ), 'Disconnect did not bind the actor assertion.' );
$assert( '11111111-1111-4111-8111-111111111111' === ( $body['installation_uuid'] ?? '' ), 'Disconnect installation identity is missing.' );
$assert( 'Unit disconnect' === ( $body['reason'] ?? '' ), 'Disconnect reason changed.' );

$GLOBALS['yoro_connection_test_response'] = [
	'response' => [ 'code' => 200 ],
	'body'     => '{"connect_url":"https://api.example.test/connect"}',
];
$result = $client->create_wordpress_connect_intent( [ 'state' => 'state-1' ] );
$request = $GLOBALS['yoro_connection_test_requests'][1] ?? [];

$assert( ! is_wp_error( $result ), 'Connect intent returned an unexpected error.' );
$assert( 'https://api.example.test/api/v1/connect/wordpress/intents' === ( $request['url'] ?? '' ), 'Connect intent URL is incorrect.' );
$assert( ! isset( $request['args']['headers']['Authorization'] ), 'Public connect intent must not send a site credential.' );

$GLOBALS['yoro_connection_test_response'] = [
	'response' => [ 'code' => 422 ],
	'body'     => '{"message":"Rejected connection"}',
];
$error = $client->exchange_wordpress_connect_code( [ 'code' => 'bad-code' ] );

$assert( is_wp_error( $error ), 'Failed connect exchange did not return WP_Error.' );
$assert( 'yoro_ai_api_connect_exchange_error' === $error->get_error_code(), 'Connect exchange error code changed.' );
$assert( 'Rejected connection' === $error->get_error_message(), 'Server error message was not preserved.' );

$GLOBALS['yoro_connection_test_response'] = [
	'response' => [ 'code' => 502 ],
	'body'     => '{"message":"OpenAI upstream unavailable"}',
];
$error = $client->disconnect_site();

$assert( is_wp_error( $error ), 'Failed disconnect did not return WP_Error.' );
$assert( 'yoro_ai_api_disconnect_error' === $error->get_error_code(), 'Disconnect error code changed.' );
$assert( 'YoRo AI API upstream unavailable' === $error->get_error_message(), 'Public provider wording was not normalized.' );

$api_client = new YoRo_AI_API_Client();
$connector = new YoRo_Connector_Client();
$transport_cases = [
    'disconnect' => static fn() => $client->disconnect_site(),
    'rotation' => static fn() => $client->rotate_site_credential(),
    'handshake' => static fn() => $api_client->handshake_with_credentials( 'https://api.example.test/api/v1', 'site-key', false ),
    'connector publish' => static fn() => $connector->publish_capability_manifest( [ 'fixture' => true ] ),
    'connector claim' => static fn() => $connector->claim_delivery(),
    'connector lease' => static fn() => $connector->renew_lease( 'delivery', 'lease-token', 2 ),
];
foreach ( [ 'request_conversation_message', 'request_action_confirm', 'request_action_execution', 'request_action_claim', 'request_email_compose' ] as $method ) {
    $transport_cases[ $method ] = static fn() => ( new ReflectionMethod( YoRo_AI_API_Client::class, $method ) )->invoke( $api_client, [ 'fixture' => true ], 'fixture_operation', 'fixture_error', 'Request failed.' );
}
$transport_cases['conversation show'] = static fn() => ( new ReflectionMethod( YoRo_AI_API_Client::class, 'request_conversation_show' ) )->invoke( $api_client, 'conversation-id', 'fixture', 'fixture_error', 'Request failed.' );
foreach ( [ 'GET', 'POST', 'DELETE' ] as $method ) {
    $transport_cases[ 'mailbox ' . $method ] = static fn() => ( new ReflectionMethod( YoRo_AI_API_Client::class, 'request_mailbox_api' ) )->invoke( $api_client, $method, '/email/inbox', [], 'fixture', 'fixture_error', 'Request failed.', false );
}
foreach ( $transport_cases as $label => $send ) {
    foreach ( [ 301, 302, 303, 307, 308, 399 ] as $status ) {
        $GLOBALS['yoro_connection_test_requests'] = [];
        $GLOBALS['yoro_connection_test_response'] = [
            'response' => [ 'code' => $status ],
            'headers' => [ 'location' => 'https://credential-sink.example.test/capture' ],
            'body' => '{"success":true,"site_key":"forged-success"}',
        ];
        $result = $send();
        $requests = $GLOBALS['yoro_connection_test_requests'];
        $request = $requests[0] ?? [];
        $assert( is_wp_error( $result ), $label . ' must reject HTTP ' . $status . ' even with a success body.' );
        $assert( 1 === count( $requests ), $label . ' must never follow Location or send a second credential-bearing request.' );
        $assert( 0 === ( $request['args']['redirection'] ?? null ), $label . ' must explicitly disable redirects.' );
        $assert( 'Bearer site-key' === ( $request['args']['headers']['Authorization'] ?? '' ), $label . ' must preserve the original credential.' );
        $assert( true === ( $request['args']['sslverify'] ?? true ), $label . ' must preserve TLS verification.' );
        $assert( false === strpos( $request['url'] ?? '', 'credential-sink' ), $label . ' must not contact the redirect destination.' );
    }
}
$GLOBALS['yoro_connection_test_response'] = [ 'response' => [ 'code' => 200 ], 'body' => '{"success":true}' ];
$assert( ! is_wp_error( $api_client->handshake_with_credentials( 'https://api.example.test/api/v1', 'site-key', false ) ), 'Valid handshake must remain successful.' );
$assert( ! is_wp_error( $client->rotate_site_credential() ), 'Valid credential rotation must remain successful.' );
$assert( ! is_wp_error( $connector->publish_capability_manifest( [ 'fixture' => true ] ) ), 'Valid connector publication must remain successful.' );
$GLOBALS['yoro_connection_test_response'] = [ 'response' => [ 'code' => 204 ], 'headers' => [ 'retry-after' => '7' ], 'body' => '' ];
$assert( [ 'success' => true, 'empty' => true, 'retry_after' => 7 ] === $connector->claim_delivery(), 'Valid empty connector claim must preserve retry-after behavior.' );

if ( [] !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo API connection client component contract: PASS\n";
