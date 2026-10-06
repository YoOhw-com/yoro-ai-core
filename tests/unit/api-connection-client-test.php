<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
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

	return $GLOBALS['yoro_connection_test_response'];
}

class YoRo_AI_Settings {
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
	public static function assertion( $site_key ) {
		return 'actor-for-' . $site_key;
	}
}

require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';
require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-connection-client.php';

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

if ( [] !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo API connection client component contract: PASS\n";
