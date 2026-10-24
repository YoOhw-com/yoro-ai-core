<?php
/** Actual native media callback and runner fault injection, only in a disposable .invalid site. */
if ( ! defined( 'ABSPATH' ) ) { throw new RuntimeException( 'A disposable WordPress installation is required.' ); }
$fixture_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$ci_disposable = 'true' === getenv( 'GITHUB_ACTIONS' ) && in_array( $fixture_host, [ 'yoro-plugin-smoke.test', 'yoro-wordpress-smoke.test' ], true )
    && is_string( getenv( 'RUNNER_TEMP' ) ) && '' !== getenv( 'RUNNER_TEMP' ) && str_starts_with( (string) realpath( ABSPATH ), rtrim( (string) realpath( getenv( 'RUNNER_TEMP' ) ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
if ( ! str_ends_with( $fixture_host, '.invalid' ) && ! $ci_disposable ) { throw new RuntimeException( 'An exact disposable test installation is required.' ); }

require_once __DIR__ . '/../contracts/helpers/native-write-runtime.php';
require_once __DIR__ . '/../contracts/helpers/wordpress-management-runtime.php';
$assert = static function( $condition, $why ) { if ( ! $condition ) { throw new RuntimeException( $why ); } };
YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] ); yoro_native_write_fixture_setup();
update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time() ); YoRo_Early_Access_WordPress_Management::register();
add_filter( 'pre_wp_mail', '__return_true' );
$make_post = static fn() => wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Before', 'post_content' => 'Exact source' ] );
$acquire = new ReflectionMethod( YoRo_Connector_Runtime::class, 'acquire_lock' ); $release = new ReflectionMethod( YoRo_Connector_Runtime::class, 'release_lock' ); $lock = $acquire->invoke( null );
try {
 $bytes = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY1cAAAAASUVORK5CYII=' ); $reference = wp_generate_uuid4();
 $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'media.upload', [ 'media_reference_id' => $reference, 'filename' => 'fixture.png', 'mime_type' => 'image/png', 'byte_size' => strlen( $bytes ), 'sha256' => hash( 'sha256', $bytes ), 'alt_text' => 'Accessible fixture image' ] );
 $url = 'https://r2-api.yorohq.com/api/v1/connectors/media-references/' . $reference . '?expires=' . ( time() + 300 ) . '&signature=' . str_repeat( 'a', 64 );
 $client->operation['extensions']['yoro.mcp_early_access_commerce_write']['media_download_url'] = $url;
 $network_calls = 0;
 $download = static function( $pre, $args, $requested ) use ( $url, $bytes, &$network_calls, $assert ) { if ( $url !== $requested ) { return $pre; } $assert( 0 === $args['redirection'], 'No media redirects.' ); $network_calls++; file_put_contents( $args['filename'], $bytes ); return [ 'response' => [ 'code' => 200 ], 'headers' => [ 'content-type' => 'image/png', 'x-yoro-content-sha256' => hash( 'sha256', $bytes ) ], 'body' => '', 'cookies' => [] ]; };
 add_filter( 'pre_http_request', $download, 5, 3 );
 try {
  $runtime = new YoRo_Connector_Runtime( $client ); $run = $runtime->run_early_access_write_once(); $wire = end( $client->results );
  $assert( ! is_wp_error( $run ) && 'executed' === ( $wire['status'] ?? null ) && 1 === $network_calls, 'Actual signed native media upload: ' . wp_json_encode( $wire ) );
  if ( $path = getenv( 'G013_CORE_MEDIA_RESULT' ) ) { file_put_contents( $path, wp_json_encode( [ 'params' => $client->operation['params'], 'executed' => $wire, 'downloads' => $network_calls ] ) ); }
  $assert( 'Accessible fixture image' === get_post_meta( $wire['result']['data']['new_target_id'], '_wp_attachment_image_alt', true ), 'Observed native attachment metadata.' );
  $runtime->run_early_access_write_once(); $assert( 1 === $network_calls, 'Completed media never downloads or uploads again.' );
 } finally { remove_filter( 'pre_http_request', $download, 5 ); }
 // The namespace alone and a caller profile field cannot create ordinary authority.
 $denied = $client->operation; unset( $denied['extensions'] ); $denied['profile'] = 'early_access';
 $assert( ! YoRo_Early_Access_WordPress_Management::ordinary_operation( $denied ), 'Managed/generic profile hints cannot admit the alias.' );
 $validate = new ReflectionMethod( YoRo_Connector_Runtime::class, 'validate_operation' );
 $assert( is_wp_error( $validate->invoke( new YoRo_Connector_Runtime( $client ), $denied ) ), 'Actual generic validation rejects the alias before its callback.' );
 // Every editor snapshot remains distinct from published-only ordinary reads.
 $private = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Private exact editor text' ] );
 $assert( is_wp_error( YoRo_Early_Access_WordPress_Read::execute( [ 'action' => 'content.get', 'params' => [ 'content_id' => $private ] ] ) ), 'Public reader cannot borrow editor access.' );
 // Policy revoked in the lease response is checked before the administration callback.
 $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'site.update_settings', [ 'settings' => [ 'blogname' => 'Forbidden after revoke' ] ] ); $before = get_option( 'blogname' );
 $client->after_lease = static fn() => update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no' );
 $runtime = new YoRo_Connector_Runtime( $client ); $runtime->run_early_access_write_once();
 $assert( $before === get_option( 'blogname' ) && 'executed' !== ( end( $client->results )['status'] ?? null ), 'Policy revocation after lease blocks the native action.' ); update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' );
 // Stale preview: changed bytes cannot reach the confirmed native update.
 $id = $make_post(); $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'content.update_draft', [ 'content_id' => $id, 'title' => 'Confirmed title' ] ); wp_update_post( [ 'ID' => $id, 'post_title' => 'Concurrent edit' ] );
 ( new YoRo_Connector_Runtime( $client ) )->run_early_access_write_once(); $assert( 'Concurrent edit' === get_post( $id )->post_title && 'executed' !== ( end( $client->results )['status'] ?? null ), 'Stale exact source is denied.' );
 // An interrupted native hook occurs after a durable marker: no callback or side effect repeats.
 $id = $make_post(); $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'content.update_draft', [ 'content_id' => $id, 'title' => 'Changed before interruption' ] ); $calls = 0;
 $interrupt = static function( $post_id ) use ( $id, &$calls ) { if ( $post_id === $id ) { $calls++; throw new RuntimeException( 'Injected after-side-effect interruption.' ); } }; add_action( 'save_post_post', $interrupt );
 try { $runtime = new YoRo_Connector_Runtime( $client ); $runtime->run_early_access_write_once(); $runtime->run_early_access_write_once(); }
 finally { remove_action( 'save_post_post', $interrupt ); }
 $assert( 1 === $calls && 'Changed before interruption' === get_post( $id )->post_title && 'local_execution_outcome_unknown' === ( end( $client->results )['error']['code'] ?? null ), 'Unknown mutation is never automatically replayed.' );
 // Non-atomic batch: first unknown stops later targets and preserves distinct durable outcomes.
 $first = $make_post(); $second = $make_post(); $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'content.bulk_update_status', [ 'content_ids' => [ $first, $second ], 'status' => 'pending' ] ); $calls = 0;
 $interrupt = static function( $post_id ) use ( $first, &$calls ) { if ( $post_id === $first ) { $calls++; throw new RuntimeException( 'Injected batch interruption.' ); } }; add_action( 'save_post_post', $interrupt );
 try { $runtime = new YoRo_Connector_Runtime( $client ); $runtime->run_early_access_write_once(); $runtime->run_early_access_write_once(); }
 finally { remove_action( 'save_post_post', $interrupt ); }
 $wire = end( $client->results ); $assert( [ 'unknown', 'not_attempted' ] === array_column( $wire['result']['data']['outcomes'] ?? [], 'status' ) && 'draft' === get_post_status( $second ) && 1 === $calls, 'Per-target unknown/not-attempted, no batch replay.' );
 // Durable marker persistence failures are pre-mutation; completion failure is unknown on recovery.
 foreach ( [ 'insert', 'complete' ] as $fault_kind ) {
  $id = $make_post(); $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'content.update_draft', [ 'content_id' => $id, 'title' => 'Persisted mutation' ] );
  $fault = static fn( $sql ) => str_contains( $sql, 'yoro_connector_journal_' ) && str_starts_with( $sql, 'insert' === $fault_kind ? 'INSERT INTO' : 'UPDATE' ) ? 'SELECT 1 WHERE 1 = 0' : $sql;
  add_filter( 'query', $fault ); try { $runtime = new YoRo_Connector_Runtime( $client ); $run = $runtime->run_early_access_write_once(); } finally { remove_filter( 'query', $fault ); }
  $assert( is_wp_error( $run ) && 'yoro_connector_journal_unavailable' === $run->get_error_code(), 'Durable journal fault is reported.' );
  $assert( ( 'insert' === $fault_kind ? 'Before' : 'Persisted mutation' ) === get_post( $id )->post_title, 'Correct side-effect boundary for journal fault.' );
  if ( 'complete' === $fault_kind ) { $runtime->run_early_access_write_once(); $assert( 'local_execution_outcome_unknown' === ( end( $client->results )['error']['code'] ?? null ), 'Completion fault recovers as unknown.' ); }
 }
 // Lost native runner lease stops before the next mutator.
 $id = $make_post(); $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'content.update_draft', [ 'content_id' => $id, 'title' => 'Must not change' ] );
 $client->after_lease = static function() { global $wpdb; $name = new ReflectionProperty( YoRo_Connector_Runtime::class, 'held_lock_name' ); $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name->getValue() ) ); };
 $run = ( new YoRo_Connector_Runtime( $client ) )->run_early_access_write_once(); $assert( is_wp_error( $run ) && 'yoro_connector_runner_lock_lost' === $run->get_error_code() && 'Before' === get_post( $id )->post_title, 'Lost lease never reaches mutation.' );
} finally { $release->invoke( null, $lock ); }
echo "Ordinary native media, exact ownership transport, policy/stale/lease denial, interrupted writes, non-atomic outcomes and journal faults passed.\n";
