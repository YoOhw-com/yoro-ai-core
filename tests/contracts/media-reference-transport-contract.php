<?php
/**
 * Characterization contract for staged connector media ingress.
 *
 * Run from the plugin root with:
 * php tests/contracts/media-reference-transport-contract.php
 */

$runtime_root = sys_get_temp_dir() . '/yoro-media-contract-' . getmypid() . '/';
$include_root = $runtime_root . 'wp-admin/includes/';

if ( ! is_dir( $include_root ) && ! mkdir( $include_root, 0777, true ) && ! is_dir( $include_root ) ) {
	throw new RuntimeException( 'Could not create the media contract runtime directory.' );
}

foreach ( [ 'file.php', 'media.php', 'image.php' ] as $include_file ) {
	file_put_contents( $include_root . $include_file, "<?php\n" );
}

define( 'ABSPATH', $runtime_root );
define( 'MB_IN_BYTES', 1048576 );
define( 'YORO_AI_PROVIDER_YORO_AI_API', 'yoro_ai_api' );

register_shutdown_function(
	static function() use ( $include_root, $runtime_root ) {
		foreach ( [ 'file.php', 'media.php', 'image.php' ] as $include_file ) {
			@unlink( $include_root . $include_file );
		}

		@rmdir( $include_root );
		@rmdir( dirname( untrailingslashit( $include_root ) ) );
		@rmdir( dirname( dirname( untrailingslashit( $include_root ) ) ) );
		@rmdir( $runtime_root );
	}
);

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_Post {
	public $ID = 321;
	public $post_title = 'Uploaded fixture';
	public $post_excerpt = '';
	public $post_content = '';
	public $post_mime_type = 'image/png';
	public $post_modified_gmt = '2026-08-01 00:00:00';
	public $post_status = 'inherit';
}

class YoRo_AI_Settings {
	public static $api_url = 'https://api.example.test/api/v1/conversations/message';
	public static $site_key = 'site-key-fixture-private';
	public static $api_url_reads = 0;
	public static $site_key_reads = 0;

	public static function get_ai_api_url() {
		self::$api_url_reads++;
		return self::$api_url;
	}

	public static function get_ai_api_site_key() {
		self::$site_key_reads++;
		return self::$site_key;
	}
}

class YoRo_WordPress_Actor {
	public static function assertion( $site_key ) {
		return 'site-key-fixture-private' === $site_key ? 'actor-assertion-fixture' : '';
	}
}

class YoRo_AI_Request_Tracker {
	public static $requests = [];

	public static function record( $operation, $duration_ms, $status = 'success', $meta = [] ) {
		self::$requests[] = compact( 'operation', 'duration_ms', 'status', 'meta' );
	}
}

function __( $message, $domain = null ) {
	return $message;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function sanitize_text_field( $value ) {
	$value = strip_tags( (string) $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );
	return trim( $value );
}

function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function sanitize_mime_type( $value ) {
	return preg_replace( '/[^-+.\w\/]/', '', (string) $value );
}

function sanitize_file_name( $value ) {
	$value = preg_replace( '/[^A-Za-z0-9._-]+/', '-', trim( (string) $value ) );
	return trim( $value, '-' );
}

function esc_url_raw( $value ) {
	return trim( (string) $value );
}

function untrailingslashit( $value ) {
	return rtrim( (string) $value, "/\\" );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

function current_user_can( $capability ) {
	return 'upload_files' === $capability;
}

function wp_tempnam( $filename = '' ) {
	$path = tempnam( sys_get_temp_dir(), 'yoro-media-upload-' );
	$GLOBALS['yoro_media_contract_last_temp'] = $path;
	$GLOBALS['yoro_media_contract_temp_filename'] = $filename;
	return $path;
}

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['yoro_media_contract_requests'][] = [
		'method' => 'GET',
		'url'    => $url,
		'args'   => $args,
	];

	$scenario = $GLOBALS['yoro_media_contract_scenario'];
	$destination = $args['filename'] ?? '';

	if ( 'network_failure' === $scenario ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out.' );
	}

	if ( 'destination_write_failure' === $scenario ) {
		return new WP_Error( 'http_request_failed', 'Could not write streamed response to destination.' );
	}

	if ( 'success_without_file' === $scenario ) {
		@unlink( $destination );
		return yoro_media_contract_response( 200 );
	}

	if ( 'http_error' === $scenario ) {
		file_put_contents( $destination, $GLOBALS['yoro_media_contract_error_body'] );
		return yoro_media_contract_response( $GLOBALS['yoro_media_contract_http_status'] );
	}

	if ( null !== $GLOBALS['yoro_media_contract_stream_size'] ) {
		$remaining = (int) $GLOBALS['yoro_media_contract_stream_size'];
		$handle = fopen( $destination, 'wb' );
		$chunk = str_repeat( 'A', 8192 );

		while ( $remaining > 0 ) {
			$write = min( $remaining, strlen( $chunk ) );
			fwrite( $handle, substr( $chunk, 0, $write ) );
			$remaining -= $write;
		}

		fclose( $handle );
	} else {
		file_put_contents( $destination, $GLOBALS['yoro_media_contract_payload'] );
	}

	$sha256 = null !== $GLOBALS['yoro_media_contract_response_sha256']
		? $GLOBALS['yoro_media_contract_response_sha256']
		: hash_file( 'sha256', $destination );

	return yoro_media_contract_response(
		200,
		[
			'x-yoro-content-sha256' => $sha256,
			'content-type'          => $GLOBALS['yoro_media_contract_response_mime'],
		]
	);
}

function yoro_media_contract_response( $status, array $headers = [] ) {
	return [
		'response' => [ 'code' => (int) $status ],
		'headers'  => $headers,
		'body'     => 'response bodies must not be used for streamed media',
	];
}

function wp_remote_retrieve_response_code( $response ) {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_header( $response, $name ) {
	return $response['headers'][ strtolower( $name ) ] ?? '';
}

function wp_check_filetype_and_ext( $path, $filename ) {
	$GLOBALS['yoro_media_contract_filetype_check'] = compact( 'path', 'filename' );
	$type = $GLOBALS['yoro_media_contract_forced_filetype'];

	if ( null === $type ) {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$type = [
			'gif'  => 'image/gif',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'pdf'  => 'application/pdf',
			'png'  => 'image/png',
			'webp' => 'image/webp',
		][ $extension ] ?? '';
	}

	return [ 'ext' => pathinfo( $filename, PATHINFO_EXTENSION ), 'type' => $type ];
}

function media_handle_sideload( $file, $post_id ) {
	$GLOBALS['yoro_media_contract_sideload'] = $file;
	return 321;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['yoro_media_contract_updated_meta'] = compact( 'post_id', 'key', 'value' );
	return true;
}

function get_post( $post_id ) {
	return new WP_Post();
}

function get_post_meta( $post_id, $key, $single = false ) {
	return '';
}

function wp_get_attachment_url( $post_id ) {
	return 'https://wordpress.example.test/uploads/fixture.png';
}

function wp_get_attachment_metadata( $post_id ) {
	return [];
}

function yoro_media_contract_reset( $scenario = 'success', $payload = 'streamed-media-fixture' ) {
	$GLOBALS['yoro_media_contract_scenario'] = $scenario;
	$GLOBALS['yoro_media_contract_payload'] = $payload;
	$GLOBALS['yoro_media_contract_stream_size'] = null;
	$GLOBALS['yoro_media_contract_response_sha256'] = null;
	$GLOBALS['yoro_media_contract_response_mime'] = 'image/png';
	$GLOBALS['yoro_media_contract_http_status'] = 500;
	$GLOBALS['yoro_media_contract_error_body'] = '';
	$GLOBALS['yoro_media_contract_requests'] = [];
	$GLOBALS['yoro_media_contract_last_temp'] = '';
	$GLOBALS['yoro_media_contract_temp_filename'] = '';
	$GLOBALS['yoro_media_contract_filetype_check'] = [];
	$GLOBALS['yoro_media_contract_forced_filetype'] = null;
	$GLOBALS['yoro_media_contract_sideload'] = [];
	$GLOBALS['yoro_media_contract_updated_meta'] = [];
	YoRo_AI_Settings::$api_url = 'https://api.example.test/api/v1/conversations/message';
	YoRo_AI_Settings::$site_key = 'site-key-fixture-private';
	YoRo_AI_Settings::$api_url_reads = 0;
	YoRo_AI_Settings::$site_key_reads = 0;
	YoRo_AI_Request_Tracker::$requests = [];
}

function yoro_media_contract_error_output( WP_Error $error ) {
	return $error->get_error_code() . ' ' . $error->get_error_message() . ' ' . json_encode( $error->get_error_data() );
}

function yoro_media_contract_stream_hash( $size ) {
	$context = hash_init( 'sha256' );
	$remaining = (int) $size;
	$chunk = str_repeat( 'A', 8192 );

	while ( $remaining > 0 ) {
		$write = min( $remaining, strlen( $chunk ) );
		hash_update( $context, substr( $chunk, 0, $write ) );
		$remaining -= $write;
	}

	return hash_final( $context );
}

$plugin_root = dirname( __DIR__, 2 );

require_once $plugin_root . '/includes/ai/interfaces/interface-yoro-ai-client.php';

$media_interface = $plugin_root . '/includes/commerce/interface-yoro-connector-media-client.php';
$media_client = $plugin_root . '/includes/commerce/class-yoro-connector-media-client.php';

if ( file_exists( $media_interface ) ) {
	require_once $media_interface;
}

if ( file_exists( $media_client ) ) {
	require_once $media_client;
}

require_once $plugin_root . '/includes/ai/clients/class-yoro-ai-api-client.php';
require_once $plugin_root . '/includes/actions/class-yoro-wordpress-content-actions.php';

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assert_safe_error = static function ( $error, $context ) use ( $assert ) {
	$output = is_wp_error( $error ) ? yoro_media_contract_error_output( $error ) : json_encode( $error );

	foreach ( [ 'site-key-fixture-private', 'signed-secret-fixture', 'https://signed.example.test/' ] as $secret ) {
		$assert( false === strpos( $output, $secret ), $context . ' must not expose ' . $secret . ' in error output.' );
	}
};

$run_transport_contract = static function ( $label, $client ) use ( $assert, $assert_safe_error ) {
	yoro_media_contract_reset();
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( " staged/ref +?\n", $destination );
	$request = $GLOBALS['yoro_media_contract_requests'][0] ?? [];
	$args = $request['args'] ?? [];

	$assert( ! is_wp_error( $result ), $label . ' must download a valid media reference.' );
	$assert( 'GET' === ( $request['method'] ?? '' ), $label . ' must use HTTP GET.' );
	$assert(
		'https://api.example.test/api/v1/connectors/media-references/staged%2Fref%20%2B%3F' === ( $request['url'] ?? '' ),
		$label . ' must preserve the current endpoint and rawurlencode the sanitized reference id.'
	);
	$assert( 30 === ( $args['timeout'] ?? null ), $label . ' must retain the 30-second timeout.' );
	$assert( 0 === ( $args['redirection'] ?? null ), $label . ' must continue to reject redirects.' );
	$assert( true === ( $args['stream'] ?? null ), $label . ' must stream media instead of buffering response bytes.' );
	$assert( $destination === ( $args['filename'] ?? '' ), $label . ' must stream to the requested destination.' );
	$assert( ( 10 * MB_IN_BYTES ) + 1 === ( $args['limit_response_size'] ?? null ), $label . ' must preserve the bounded response size.' );
	$assert( 'Bearer site-key-fixture-private' === ( $args['headers']['Authorization'] ?? '' ), $label . ' must use the configured site credential as a Bearer token.' );
	$assert( 'application/json' === ( $args['headers']['Accept'] ?? '' ), $label . ' must retain the Accept header.' );
	$assert( 'application/json' === ( $args['headers']['Content-Type'] ?? '' ), $label . ' must retain the Content-Type header.' );
	$assert( 'actor-assertion-fixture' === ( $args['headers']['X-YoRo-Actor-Assertion'] ?? '' ), $label . ' must retain the actor assertion header.' );
	$assert( 1 === YoRo_AI_Settings::$api_url_reads, $label . ' must resolve the base URL from YoRo_AI_Settings.' );
	$assert( 1 === YoRo_AI_Settings::$site_key_reads, $label . ' must resolve the site credential from YoRo_AI_Settings.' );
	$assert( hash( 'sha256', 'streamed-media-fixture' ) === ( $result['sha256'] ?? '' ), $label . ' must return the staged SHA-256 header.' );
	$assert( 'image/png' === ( $result['mime_type'] ?? '' ), $label . ' must return the staged MIME header.' );
	$assert( strlen( 'streamed-media-fixture' ) === ( $result['byte_size'] ?? -1 ), $label . ' must report destination file size.' );
	$assert( [] === YoRo_AI_Request_Tracker::$requests, $label . ' must preserve the current no-request-tracker behavior.' );
	@unlink( $destination );

	yoro_media_contract_reset();
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( " \n\t", $destination );
	$assert( is_wp_error( $result ) && 'yoro_media_reference_missing' === $result->get_error_code(), $label . ' must reject an empty sanitized reference id.' );
	$assert( 'The staged YoRo media reference is unavailable.' === $result->get_error_message(), $label . ' must retain the translated missing-reference message.' );
	$assert( [] === $GLOBALS['yoro_media_contract_requests'], $label . ' must not issue HTTP for an invalid reference id.' );
	$assert_safe_error( $result, $label . ' missing-reference error' );
	@unlink( $destination );

	yoro_media_contract_reset();
	YoRo_AI_Settings::$site_key = '';
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( 'reference', $destination );
	$assert( is_wp_error( $result ) && 'yoro_media_reference_missing' === $result->get_error_code(), $label . ' must reject a missing site credential.' );
	$assert( [] === $GLOBALS['yoro_media_contract_requests'], $label . ' must not issue HTTP without the site credential.' );
	@unlink( $destination );

	foreach ( [ 401 => 'unauthorized credential', 403 => 'revoked credential', 404 => 'missing reference', 503 => 'non-2xx response' ] as $status => $context ) {
		yoro_media_contract_reset( 'http_error' );
		$GLOBALS['yoro_media_contract_http_status'] = $status;
		$GLOBALS['yoro_media_contract_error_body'] = 'site-key-fixture-private https://signed.example.test/?token=signed-secret-fixture';
		$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
		$result = $client->download_media_reference( 'reference', $destination );
		$assert( is_wp_error( $result ) && 'yoro_media_reference_download_failed' === $result->get_error_code(), $label . ' must map ' . $context . ' to the current generic error code.' );
		$assert( 'YoRo could not download the staged media file.' === $result->get_error_message(), $label . ' must retain the generic non-200 error message.' );
		$assert( ! file_exists( $destination ), $label . ' must delete the destination after ' . $context . '.' );
		$assert_safe_error( $result, $label . ' ' . $context . ' error' );
	}

	yoro_media_contract_reset( 'network_failure' );
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( 'reference', $destination );
	$assert( is_wp_error( $result ) && 'http_request_failed' === $result->get_error_code(), $label . ' must pass through the WordPress network error code.' );
	$assert( 'cURL error 28: Operation timed out.' === $result->get_error_message(), $label . ' must preserve the WordPress network error message.' );
	$assert( ! file_exists( $destination ), $label . ' must delete the destination after a network failure.' );
	$assert_safe_error( $result, $label . ' network error' );

	yoro_media_contract_reset( 'destination_write_failure' );
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( 'reference', $destination );
	$assert( is_wp_error( $result ) && 'http_request_failed' === $result->get_error_code(), $label . ' must pass through the WordPress destination-write error code.' );
	$assert( 'Could not write streamed response to destination.' === $result->get_error_message(), $label . ' must preserve the destination-write error message.' );
	$assert( ! file_exists( $destination ), $label . ' must delete the destination after a destination-write failure.' );
	$assert_safe_error( $result, $label . ' destination-write error' );

	yoro_media_contract_reset( 'success_without_file' );
	$destination = tempnam( sys_get_temp_dir(), 'yoro-media-download-' );
	$result = $client->download_media_reference( 'reference', $destination );
	$assert( is_wp_error( $result ) && 'yoro_media_reference_download_failed' === $result->get_error_code(), $label . ' must reject a 200 response that did not create the destination.' );
	$assert_safe_error( $result, $label . ' missing-destination error' );
};

if ( class_exists( 'YoRo_Connector_Media_Client' ) ) {
	$run_transport_contract( 'Media adapter', new YoRo_Connector_Media_Client() );
}

$run_transport_contract( 'AI client compatibility facade', new YoRo_AI_API_Client() );

$valid_payload = 'valid-media-payload';
$valid_hash = hash( 'sha256', $valid_payload );
$valid_params = [
	'media_reference_id' => 'reference',
	'filename'           => 'unsafe image.png',
	'mime_type'          => 'image/png',
	'byte_size'          => strlen( $valid_payload ),
	'sha256'             => $valid_hash,
	'alt_text'           => 'Fixture alt text',
];

yoro_media_contract_reset( 'success', $valid_payload );
$upload = YoRo_WordPress_Content_Actions::upload_media( $valid_params );
$sideload = $GLOBALS['yoro_media_contract_sideload'];
$filetype_check = $GLOBALS['yoro_media_contract_filetype_check'];
$assert( is_array( $upload ) && ! empty( $upload['success'] ), 'media.upload must accept a valid staged reference.' );
$assert( 'unsafe-image.png' === $GLOBALS['yoro_media_contract_temp_filename'], 'media.upload must sanitize the filename before allocating a destination.' );
$assert( 'unsafe-image.png' === ( $filetype_check['filename'] ?? '' ), 'media.upload must validate the sanitized filename against the downloaded bytes.' );
$assert( 'unsafe-image.png' === ( $sideload['name'] ?? '' ), 'media.upload must pass the sanitized filename to WordPress sideloading.' );
$assert( 'image/png' === ( $sideload['type'] ?? '' ), 'media.upload must pass the validated declared MIME type to WordPress sideloading.' );
$assert( strlen( $valid_payload ) === ( $sideload['size'] ?? -1 ), 'media.upload must pass the verified byte size to WordPress sideloading.' );
$assert( $valid_hash === hash_file( 'sha256', $sideload['tmp_name'] ?? '' ), 'media.upload must preserve the verified SHA-256 bytes through sideloading.' );
$assert( 'Fixture alt text' === ( $GLOBALS['yoro_media_contract_updated_meta']['value'] ?? '' ), 'media.upload must retain alt-text handling.' );
@unlink( $sideload['tmp_name'] ?? '' );

$integrity_cases = [
	'byte size' => array_merge( $valid_params, [ 'byte_size' => strlen( $valid_payload ) + 1 ] ),
	'payload SHA-256' => array_merge( $valid_params, [ 'sha256' => str_repeat( '0', 64 ) ] ),
];

foreach ( $integrity_cases as $context => $params ) {
	yoro_media_contract_reset( 'success', $valid_payload );
	$result = YoRo_WordPress_Content_Actions::upload_media( $params );
	$assert( is_wp_error( $result ) && 'yoro_media_reference_mismatch' === $result->get_error_code(), 'media.upload must reject a mismatched ' . $context . '.' );
	$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up after a mismatched ' . $context . '.' );
}

yoro_media_contract_reset( 'success', $valid_payload );
$GLOBALS['yoro_media_contract_response_sha256'] = str_repeat( 'f', 64 );
$result = YoRo_WordPress_Content_Actions::upload_media( $valid_params );
$assert( is_wp_error( $result ) && 'yoro_media_reference_mismatch' === $result->get_error_code(), 'media.upload must reject a mismatched staged-reference SHA-256 header.' );
$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up after a staged-reference SHA-256 mismatch.' );

yoro_media_contract_reset( 'success', '' );
$empty_params = array_merge(
	$valid_params,
	[
		'byte_size' => 0,
		'sha256'    => hash( 'sha256', '' ),
	]
);
$result = YoRo_WordPress_Content_Actions::upload_media( $empty_params );
$assert( is_wp_error( $result ) && 'yoro_media_payload_invalid' === $result->get_error_code(), 'media.upload must reject a zero-byte staged file.' );
$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up a zero-byte staged file.' );

$oversize = ( 10 * MB_IN_BYTES ) + 1;
yoro_media_contract_reset();
$GLOBALS['yoro_media_contract_stream_size'] = $oversize;
$oversize_params = array_merge(
	$valid_params,
	[
		'byte_size' => $oversize,
		'sha256'    => yoro_media_contract_stream_hash( $oversize ),
	]
);
$result = YoRo_WordPress_Content_Actions::upload_media( $oversize_params );
$assert( is_wp_error( $result ) && 'yoro_media_payload_invalid' === $result->get_error_code(), 'media.upload must retain the 10 MiB byte-size ceiling.' );
$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up an oversized staged file.' );

yoro_media_contract_reset( 'success', $valid_payload );
$mime_params = array_merge( $valid_params, [ 'filename' => 'fixture.txt', 'mime_type' => 'text/plain' ] );
$result = YoRo_WordPress_Content_Actions::upload_media( $mime_params );
$assert( is_wp_error( $result ) && 'yoro_media_payload_invalid' === $result->get_error_code(), 'media.upload must retain its MIME allowlist.' );
$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up a disallowed MIME type.' );

yoro_media_contract_reset( 'success', $valid_payload );
$GLOBALS['yoro_media_contract_forced_filetype'] = 'image/jpeg';
$result = YoRo_WordPress_Content_Actions::upload_media( $valid_params );
$assert( is_wp_error( $result ) && 'yoro_media_type_mismatch' === $result->get_error_code(), 'media.upload must reject bytes that do not match the declared MIME type and filename.' );
$assert( ! file_exists( $GLOBALS['yoro_media_contract_last_temp'] ), 'media.upload must clean up after a MIME mismatch.' );

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}

	exit( 1 );
}

echo "YoRo media reference transport and upload integrity contract passed.\n";
