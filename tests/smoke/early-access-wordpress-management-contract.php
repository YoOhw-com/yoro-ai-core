<?php
/** Run only in a disposable WordPress installation; native callbacks and durable runner are exercised. */
if ( ! defined( 'ABSPATH' ) ) { throw new RuntimeException( 'A disposable WordPress installation is required.' ); }
$fixture_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$fixture_root = realpath( ABSPATH );
$runner_temp = getenv( 'RUNNER_TEMP' );
$runner_root = is_string( $runner_temp ) && '' !== $runner_temp ? realpath( $runner_temp ) : false;
$ci_disposable = 'true' === getenv( 'GITHUB_ACTIONS' ) && in_array( $fixture_host, [ 'yoro-plugin-smoke.test', 'yoro-wordpress-smoke.test', 'yoro-wordpress-exact-pair.test', 'yoro-woocommerce-exact-pair.test' ], true )
    && false !== $fixture_root && false !== $runner_root && str_starts_with( $fixture_root, rtrim( $runner_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
if ( ! str_ends_with( $fixture_host, '.invalid' ) && ! $ci_disposable ) { throw new RuntimeException( 'An exact disposable test installation is required.' ); }

require_once __DIR__ . '/../contracts/helpers/native-write-runtime.php';
require_once __DIR__ . '/../contracts/helpers/wordpress-management-runtime.php';
$fixture_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( [] === $fixture_admins ) { throw new RuntimeException( 'A disposable administrator fixture is required.' ); }
wp_set_current_user( (int) $fixture_admins[0] );
$assert = static function( $condition, $why ) { if ( ! $condition ) { throw new RuntimeException( $why ); } };
$admin = get_current_user_id();
YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
yoro_native_write_fixture_setup();
add_filter( 'pre_wp_mail', '__return_true' );
$plugin = WP_PLUGIN_DIR . '/g012-fixture/g012-fixture.php';
wp_mkdir_p( dirname( $plugin ) );
file_put_contents( $plugin, <<<'PHP'
<?php
/* Plugin Name: G012 disposable fixture
Version: 1.0
*/
register_activation_hook( __FILE__, static function() {
    update_option( 'g014_activation_setup_count', (int) get_option( 'g014_activation_setup_count', 0 ) + 1, false );
    update_option( 'g014_activation_initialized', 'installed', false );
} );
register_deactivation_hook( __FILE__, static function() {
    update_option( 'g014_deactivation_count', (int) get_option( 'g014_deactivation_count', 0 ) + 1, false );
} );
PHP
);
$theme = WP_CONTENT_DIR . '/themes/g012-fixture'; wp_mkdir_p( $theme ); file_put_contents( $theme . '/style.css', "/* Theme Name: G012 disposable theme\nVersion: 1.0\n*/\n" ); file_put_contents( $theme . '/index.php', '<?php // Disposable theme.' );
register_theme_directory( WP_CONTENT_DIR . '/themes' ); wp_clean_themes_cache();
$target_user = wp_insert_user( [ 'user_login' => 'g012_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'g012-' . wp_generate_password( 8, false ) . '@example.invalid', 'role' => 'administrator' ] );
$term = wp_insert_term( 'G012 ' . wp_generate_password( 8, false ), 'category' );
$assert( ! is_wp_error( $target_user ) && ! is_wp_error( $term ), 'Native user and term fixtures.' );
$make_post = static function( $status = 'draft' ) use ( $admin ) { return wp_insert_post( [ 'post_type' => 'post', 'post_status' => $status, 'post_title' => 'G012 draft', 'post_content' => '<p>G012 exact content.</p>', 'post_author' => $admin ] ); };
$image_path = wp_upload_dir()['path'] . '/g012-image-' . wp_generate_password( 6, false ) . '.png'; wp_mkdir_p( dirname( $image_path ) );
file_put_contents( $image_path, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aY1cAAAAASUVORK5CYII=' ) );
$media = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Fixture image', 'post_status' => 'inherit' ], $image_path );
$base = $make_post( 'private' ); $draft = $make_post();
$comment = wp_insert_comment( [ 'comment_post_ID' => $base, 'comment_author' => 'Fixture reader', 'comment_content' => 'A comment', 'comment_approved' => '1' ] );
$revision = wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $draft, 'post_title' => 'Revision title', 'post_content' => '<p>Revision content.</p>', 'post_author' => $admin ] );
$version = YoRo_WordPress_Content_Actions::management_source( 'content', $draft )['version'];
$intents = [
 'comments.list' => [ 'content_id' => $base, 'limit' => 10 ], 'comments.reply' => [ 'comment_id' => $comment, 'content' => 'Public fixture reply' ], 'comments.update_status' => [ 'comment_id' => $comment, 'status' => 'hold' ],
 'content.assign_terms' => [ 'content_id' => $draft, 'taxonomy' => 'category', 'term_ids' => [ $term['term_id'] ] ], 'content.bulk_update_status' => [ 'content_ids' => [ $draft, $make_post() ], 'status' => 'pending' ],
 'content.create_draft' => [ 'title' => 'New G012 draft', 'content' => '<p>Created exact draft.</p>', 'post_type' => 'post' ],
 'content.list_revisions' => [ 'content_id' => $draft ], 'content.preview_changes' => [ 'content_id' => $draft, 'expected_version' => $version ], 'content.preview_revision' => [ 'content_id' => $draft, 'revision_id' => $revision ],
 'content.publish' => [ 'content_id' => $draft ], 'content.restore' => [ 'content_id' => $make_post( 'trash' ) ], 'content.restore_revision' => [ 'content_id' => $draft, 'revision_id' => $revision ],
 'content.schedule' => [ 'content_id' => $draft, 'publish_at' => gmdate( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) . '+00:00' ], 'content.set_featured_image' => [ 'content_id' => $draft, 'media_id' => $media ], 'content.trash' => [ 'content_id' => $draft ], 'content.update_draft' => [ 'content_id' => $draft, 'title' => 'Changed G012 draft' ],
 'media.update_metadata' => [ 'media_id' => $media, 'alt_text' => 'Accessible fixture image' ],
 'media.upload' => [ 'media_reference_id' => wp_generate_uuid4(), 'filename' => 'fixture.png', 'mime_type' => 'image/png', 'byte_size' => filesize( $image_path ), 'sha256' => hash_file( 'sha256', $image_path ), 'alt_text' => 'Fixture' ],
 'plugins.activate' => [ 'plugin' => 'g012-fixture/g012-fixture.php' ], 'plugins.deactivate' => [ 'plugin' => 'g012-fixture/g012-fixture.php' ], 'plugins.list' => [], 'plugins.update' => [ 'plugin' => 'g012-fixture/g012-fixture.php', 'target_version' => '2.0' ],
 'site.get_update_status' => [], 'site.update_core' => [ 'target_version' => '99.0' ], 'site.update_settings' => [ 'settings' => [ 'blogname' => 'G012 reviewed name' ] ],
 'taxonomies.list' => [], 'terms.list' => [ 'taxonomy' => 'category', 'limit' => 10 ], 'themes.activate' => [ 'theme' => 'g012-fixture' ], 'themes.list' => [], 'themes.update' => [ 'theme' => 'g012-fixture', 'target_version' => '2.0' ],
 'users.list' => [ 'limit' => 10 ], 'users.update_role' => [ 'user_id' => $target_user, 'role' => 'editor' ],
];
// Update offers are local immutable fixtures. Network transport remains prohibited.
set_site_transient( 'update_plugins', (object) [ 'response' => [ 'g012-fixture/g012-fixture.php' => (object) [ 'new_version' => '2.0', 'package' => 'https://packages.example.invalid/plugin.zip', 'slug' => 'g012-fixture', 'plugin' => 'g012-fixture/g012-fixture.php' ] ] ] );
set_site_transient( 'update_themes', (object) [ 'response' => [ 'g012-fixture' => [ 'new_version' => '2.0', 'package' => 'https://packages.example.invalid/theme.zip', 'theme' => 'g012-fixture' ] ] ] );
set_site_transient( 'update_core', (object) [ 'updates' => [ (object) [ 'response' => 'upgrade', 'current' => '99.0', 'locale' => 'en_US', 'download' => 'https://packages.example.invalid/core.zip', 'packages' => (object) [ 'full' => 'https://packages.example.invalid/core.zip' ] ] ], 'last_checked' => time() ] );
$records = [];
foreach ( [ 0, time() - DAY_IN_SECONDS - 1, time() + 60 ] as $backup ) {
 update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, $backup );
 $assert( ! YoRo_WordPress_Admin_Actions::connector_policy_ready(), 'Missing, aged and future backup is not current authority.' );
 $assert( is_wp_error( YoRo_Early_Access_WordPress_Management::prepare( 'wordpress.management.site.update_settings', $intents['site.update_settings'] ) ), 'Backup denial before source.' );
}
update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time() );
YoRo_Early_Access_WordPress_Management::register();
$activation_events = [ 'activate_plugin' => 0, 'activate_g012-fixture/g012-fixture.php' => 0, 'activated_plugin' => 0 ];
foreach ( array_keys( $activation_events ) as $hook ) {
 add_action( $hook, static function( $activated = '' ) use ( &$activation_events, $hook ) {
  if ( 'activate_g012-fixture/g012-fixture.php' === $hook || 'g012-fixture/g012-fixture.php' === $activated ) { ++$activation_events[$hook]; }
 } );
}
deactivate_plugins( 'g012-fixture/g012-fixture.php' );
delete_option( 'g014_activation_setup_count' ); delete_option( 'g014_activation_initialized' );
$managed_activation = YoRo_Action_Registry::get( 'plugins.activate' )['callback'];
$assert( [ YoRo_WordPress_Admin_Actions::class, 'activate_plugin' ] === $managed_activation, 'The managed activation registration remains unchanged.' );
$managed_result = call_user_func( $managed_activation, [ 'plugin' => 'g012-fixture/g012-fixture.php', 'expected_version' => '1.0' ] );
$assert( ! is_wp_error( $managed_result ) && is_plugin_active( 'g012-fixture/g012-fixture.php' ), 'The existing managed callback still activates its exact installed target.' );
$assert( false === get_option( 'g014_activation_initialized', false ) && 0 === array_sum( $activation_events ), 'Managed activation retains its existing silent native-hook behavior.' );
$activation_proof = [ 'managed' => [ 'active' => true, 'initialized' => false, 'native_events' => $activation_events ] ];
deactivate_plugins( 'g012-fixture/g012-fixture.php' );
foreach ( $intents as $public => $intent ) {
 $action = YoRo_Early_Access_WordPress_Management::wire_action( $public );
 $assert( YoRo_Early_Access_WordPress_Management::valid_intent( $action, $intent ), 'Typed intent: ' . $public );
 $assert( ! YoRo_Early_Access_WordPress_Management::valid_intent( $action, $intent + [ 'profile' => 'managed' ] ), 'Closed intent: ' . $public );
 if ( in_array( $action, YoRo_Early_Access_WordPress_Management::ReadActions, true ) ) {
  $result = YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $action, 'params' => $intent ] );
  $assert( ! is_wp_error( $result ), 'Native read ' . $public . ': ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' ) );
  $records[$public] = [ 'action' => $action, 'params' => $intent, 'result' => $result, 'write' => false ];
 } else {
  if ( 'plugins.activate' === $public ) { deactivate_plugins( 'g012-fixture/g012-fixture.php' ); }
  if ( 'plugins.deactivate' === $public ) { activate_plugin( 'g012-fixture/g012-fixture.php' ); }
  if ( 'themes.activate' === $public ) { update_option( 'stylesheet', 'missing-fixture' ); }
  $source = YoRo_Early_Access_WordPress_Management::prepare( $action, $intent );
  $assert( ! is_wp_error( $source ), 'Native preparation ' . $public . ': ' . ( is_wp_error( $source ) ? $source->get_error_message() : '' ) );
  $source_action = YoRo_Early_Access_WordPress_Management::source_action( $action );
  $result = YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $source_action, 'params' => [ 'intent' => $intent ] ] );
  $assert( ! is_wp_error( $result ), 'Source execution: ' . $public );
  $records[$public] = [ 'action' => $source_action, 'params' => [ 'intent' => $intent ], 'result' => $result, 'write' => false, 'intent' => $intent, 'source' => $source ];
 }
}
if ( $path = getenv( 'G012_CORE_RESULTS' ) ) { file_put_contents( $path, wp_json_encode( $records ) ); }
$assert( count( $records ) === 32, 'Exactly 32 native read/source cases.' );
// Actual ordinary write runner: durable journal, lease, native mutation and completed replay.
$acquire = new ReflectionMethod( YoRo_Connector_Runtime::class, 'acquire_lock' ); $release = new ReflectionMethod( YoRo_Connector_Runtime::class, 'release_lock' );
$lock = $acquire->invoke( null ); $assert( null !== $lock, 'Own the existing runtime database lock.' );
try {
 foreach ( [ 'content.create_draft', 'content.update_draft', 'content.publish', 'content.schedule', 'content.assign_terms', 'content.set_featured_image', 'content.restore_revision', 'content.trash', 'content.restore', 'content.bulk_update_status', 'comments.reply', 'comments.update_status', 'media.update_metadata', 'plugins.activate', 'plugins.deactivate', 'themes.activate', 'site.update_settings', 'users.update_role' ] as $public ) {
  $intent = $intents[$public];
  if ( str_starts_with( $public, 'content.' ) && isset( $intent['content_id'] ) ) { $intent['content_id'] = $make_post( 'content.restore' === $public ? 'trash' : 'draft' ); }
  if ( 'content.restore_revision' === $public ) { $intent['revision_id'] = wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $intent['content_id'], 'post_title' => 'Restored fixture', 'post_content' => '<p>Restored.</p>' ] ); }
  if ( 'plugins.activate' === $public ) { deactivate_plugins( 'g012-fixture/g012-fixture.php' ); }
  if ( 'plugins.deactivate' === $public ) { activate_plugin( 'g012-fixture/g012-fixture.php' ); }
  if ( 'themes.activate' === $public ) { update_option( 'stylesheet', 'missing-fixture' ); }
  if ( 'plugins.activate' === $public ) {
   delete_option( 'g014_activation_setup_count' ); delete_option( 'g014_activation_initialized' );
   $activation_events = array_fill_keys( array_keys( $activation_events ), 0 );
  }
  if ( 'plugins.deactivate' === $public ) { delete_option( 'g014_deactivation_count' ); }
  $action = YoRo_Early_Access_WordPress_Management::wire_action( $public ); $source = YoRo_Early_Access_WordPress_Management::prepare( $action, $intent );
  $assert( ! is_wp_error( $source ), 'Write source: ' . $public );
  $client = yoro_native_write_fixture_client(); $client->operation = yoro_native_write_fixture_envelope( $action, $source['params'] + [ 'expected_version' => $source['version'] ] );
  $client->operation['params'] = $source['params'];
  $facts =& $client->operation['extensions']['yoro.mcp_early_access_commerce_write'];
  $facts['expected_version'] = $source['version']; $facts['wordpress_management_version'] = 1; $facts['wordpress_intent'] = $intent; $facts['wordpress_required_scope'] = YoRo_Early_Access_WordPress_Management::definition()['actions'][$public]['scope']; $facts['params_hash'] = YoRo_Early_Access_Product_Read::params_hash( $source['params'] );
  $runtime = new YoRo_Connector_Runtime( $client ); $run = $runtime->run_early_access_write_once();
  $assert( ! is_wp_error( $run ), 'Runner: ' . $public . ' ' . ( is_wp_error( $run ) ? $run->get_error_message() : '' ) );
  $wire = end( $client->results );
  $assert( 'executed' === ( $wire['status'] ?? null ), 'Native mutation completed: ' . $public . ' ' . wp_json_encode( $wire ) );
  if ( 'plugins.activate' === $public ) {
   $assert( 'installed' === get_option( 'g014_activation_initialized' ) && 1 === (int) get_option( 'g014_activation_setup_count' ), 'Ordinary activation must run register_activation_hook initialization exactly once.' );
   $assert( [ 1, 1, 1 ] === array_values( $activation_events ), 'Ordinary activation must run activate_plugin, activate_<plugin> and activated_plugin once.' );
  }
  if ( 'plugins.deactivate' === $public ) { $assert( 1 === (int) get_option( 'g014_deactivation_count' ), 'Ordinary deactivation retains its normal native deactivation hook.' ); }
  $before = $wire; $runtime->run_early_access_write_once(); $replay = end( $client->results ); $assert( wp_json_encode( $replay['result'] ?? null ) === wp_json_encode( $before['result'] ?? null ) && 'executed' === ( $replay['status'] ?? null ), 'Durable exact replay: ' . $public . ' ' . wp_json_encode( $replay ) );
  if ( 'plugins.activate' === $public ) { $assert( 1 === (int) get_option( 'g014_activation_setup_count' ) && [ 1, 1, 1 ] === array_values( $activation_events ), 'Durable activation replay cannot repeat initialization or native hooks.' ); }
  if ( 'plugins.deactivate' === $public ) { $assert( 1 === (int) get_option( 'g014_deactivation_count' ), 'Durable deactivation replay cannot repeat its native hook.' ); }
  if ( 'plugins.activate' === $public ) { $activation_proof['ordinary'] = [ 'active' => is_plugin_active( 'g012-fixture/g012-fixture.php' ), 'initialized' => get_option( 'g014_activation_initialized' ), 'setup_count_after_replay' => (int) get_option( 'g014_activation_setup_count' ), 'native_events_after_replay' => $activation_events ]; }
  if ( 'plugins.deactivate' === $public ) { $activation_proof['deactivation'] = [ 'active' => is_plugin_active( 'g012-fixture/g012-fixture.php' ), 'hook_count_after_replay' => (int) get_option( 'g014_deactivation_count' ) ]; }
  // Export the exact pre-mutation source paired with this actual native result.
  $records[$public]['intent'] = $intent; $records[$public]['source'] = $source;
  $records[$public]['params'] = [ 'intent' => $intent ]; $records[$public]['result']['data'] = $source;
  $records[$public]['executed'] = $before; $records[$public]['executed_params'] = $source['params'];
 }
} finally { $release->invoke( null, $lock ); }
if ( $path = getenv( 'G012_CORE_RESULTS' ) ) { file_put_contents( $path, wp_json_encode( $records ) ); }
echo "WordPress management: 32 native read/source cases and 18 durable native writes passed.\n";
// Custom connector permissions do not substitute for WordPress native permissions.
$deny_native = static function( $caps ) { foreach ( $caps as $cap => $_ ) { if ( ! in_array( $cap, [ 'yoro_view_store_data', 'yoro_execute_actions' ], true ) ) { $caps[$cap] = false; } } return $caps; };
add_filter( 'user_has_cap', $deny_native );
try {
 foreach ( $intents as $public => $intent ) {
  $action = YoRo_Early_Access_WordPress_Management::wire_action( $public );
  $result = in_array( $action, YoRo_Early_Access_WordPress_Management::WriteActions, true ) ? YoRo_Early_Access_WordPress_Management::prepare( $action, $intent ) : YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $action, 'params' => $intent ] );
  $assert( is_wp_error( $result ) || ( 'taxonomies.list' === $public && [] === ( $result['data']['items'] ?? null ) ), 'Native permission denies or projects no eligible taxonomy: ' . $public );
 }
} finally { remove_filter( 'user_has_cap', $deny_native ); }
echo "All 32 native-permission negative cases passed.\n";

// Activation hooks may initialize a plugin and then throw. The durable boundary must prevent repetition.
$throwing_plugin = WP_PLUGIN_DIR . '/g014-activation-throws/g014-activation-throws.php';
wp_mkdir_p( dirname( $throwing_plugin ) );
file_put_contents( $throwing_plugin, <<<'PHP'
<?php
/* Plugin Name: G014 interrupted activation fixture
Version: 1.0
*/
register_activation_hook( __FILE__, static function() {
    update_option( 'g014_interrupted_activation_count', (int) get_option( 'g014_interrupted_activation_count', 0 ) + 1, false );
    throw new RuntimeException( 'Injected interruption after activation initialization.' );
} );
PHP
);
wp_clean_plugins_cache();
delete_option( 'g014_interrupted_activation_count' );
$lock = $acquire->invoke( null ); $assert( null !== $lock, 'Own the existing runtime lock for activation fault tests.' );
try {
 $client = yoro_native_write_fixture_client();
 $client->operation = yoro_wordpress_management_fixture_operation( 'plugins.activate', [ 'plugin' => 'g014-activation-throws/g014-activation-throws.php' ] );
 $runtime = new YoRo_Connector_Runtime( $client ); $runtime->run_early_access_write_once(); $runtime->run_early_access_write_once();
 $unknown = end( $client->results );
 $assert( 1 === (int) get_option( 'g014_interrupted_activation_count' ), 'An interrupted activation hook runs once even when the delivery is replayed.' );
 $assert( 'local_execution_outcome_unknown' === ( $unknown['error']['code'] ?? null ), 'An interrupted activation cannot report executed or offer an automatic retry.' );
 $activation_proof['interrupted'] = [ 'setup_count_after_replay' => (int) get_option( 'g014_interrupted_activation_count' ), 'result' => $unknown ];

 delete_option( 'g014_activation_setup_count' ); delete_option( 'g014_activation_initialized' );
 $activation_events = array_fill_keys( array_keys( $activation_events ), 0 );
 $client = yoro_native_write_fixture_client();
 $client->operation = yoro_wordpress_management_fixture_operation( 'plugins.activate', [ 'plugin' => 'g012-fixture/g012-fixture.php' ] );
 $client->after_lease = static fn() => update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no' );
 ( new YoRo_Connector_Runtime( $client ) )->run_early_access_write_once();
 $revoked = end( $client->results );
 $assert( ! is_plugin_active( 'g012-fixture/g012-fixture.php' ) && 0 === (int) get_option( 'g014_activation_setup_count', 0 ) && 0 === array_sum( $activation_events ), 'Policy revocation at the lease fence prevents activation and every initialization hook.' );
 $assert( 'executed' !== ( $revoked['status'] ?? null ), 'Revoked native policy cannot produce an executed activation.' );
 $activation_proof['revoked_policy'] = [ 'active' => false, 'setup_count' => 0, 'native_events' => $activation_events, 'result' => $revoked ];
} finally { update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); $release->invoke( null, $lock ); }
if ( $path = getenv( 'G014_CORE_ACTIVATION_RESULT' ) ) { file_put_contents( $path, wp_json_encode( $activation_proof ) ); }
echo "Native activation initialization and three lifecycle hooks execute once; deactivation and durable replay preserve hooks, managed silence, policy fences and interrupted-unknown no-repeat.\n";
