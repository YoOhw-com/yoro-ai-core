<?php
/** Native updaters run only against copied .invalid WordPress files and locally generated packages. */
if ( ! defined( 'ABSPATH' ) ) { throw new RuntimeException( 'A disposable WordPress installation is required.' ); }
$fixture_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$ci_disposable = 'true' === getenv( 'GITHUB_ACTIONS' ) && in_array( $fixture_host, [ 'yoro-plugin-smoke.test', 'yoro-wordpress-smoke.test' ], true )
    && is_string( getenv( 'RUNNER_TEMP' ) ) && '' !== getenv( 'RUNNER_TEMP' ) && str_starts_with( (string) realpath( ABSPATH ), rtrim( (string) realpath( getenv( 'RUNNER_TEMP' ) ), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR );
if ( ! str_ends_with( $fixture_host, '.invalid' ) && ! $ci_disposable ) { throw new RuntimeException( 'An exact disposable test installation is required.' ); }

require_once __DIR__ . '/../contracts/helpers/native-write-runtime.php';
require_once __DIR__ . '/../contracts/helpers/wordpress-management-runtime.php';
$assert = static function( $condition, $why ) { if ( ! $condition ) { throw new RuntimeException( $why ); } };
YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
yoro_native_write_fixture_setup();
update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time() );
YoRo_Early_Access_WordPress_Management::register();
$dir = getenv( 'G012_CORE_PACKAGE_DIR' ) ?: get_temp_dir() . 'g012-updater-' . wp_generate_uuid4(); wp_mkdir_p( $dir );
$zip = static function( $name, array $files ) use ( $dir ) { $path = $dir . '/' . $name . '.zip'; $archive = new ZipArchive(); $archive->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ); foreach ( $files as $key => $value ) { $archive->addFromString( $key, $value ); } $archive->close(); return $path; };
$plugin = WP_PLUGIN_DIR . '/g012-updater/g012-updater.php'; wp_mkdir_p( dirname( $plugin ) ); file_put_contents( $plugin, "<?php\n/* Plugin Name: G012 updater fixture\nVersion: 1.0\n*/\n" ); wp_clean_plugins_cache();
$theme = WP_CONTENT_DIR . '/themes/g012-updater'; wp_mkdir_p( $theme ); file_put_contents( $theme . '/style.css', "/* Theme Name: G012 updater fixture\nVersion: 1.0\n*/\n" ); file_put_contents( $theme . '/index.php', '<?php // Fixture.' ); register_theme_directory( WP_CONTENT_DIR . '/themes' ); wp_clean_themes_cache();
$packages = [
 'https://packages.example.invalid/plugin.zip' => $zip( 'plugin', [ 'g012-updater/g012-updater.php' => "<?php\n/* Plugin Name: G012 updater fixture\nVersion: 2.0\n*/\n" ] ),
 'https://packages.example.invalid/theme.zip' => $zip( 'theme', [ 'g012-updater/style.css' => "/* Theme Name: G012 updater fixture\nVersion: 2.0\n*/\n", 'g012-updater/index.php' => '<?php // Fixture.' ] ),
];
$original_version_file = file_get_contents( ABSPATH . WPINC . '/version.php' );
$core_zip = $dir . '/core.zip'; $archive = new ZipArchive(); $archive->open( $core_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE );
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( ABSPATH, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
 if ( ! $file->isFile() ) { continue; } $relative = substr( $file->getPathname(), strlen( ABSPATH ) );
 if ( str_starts_with( $relative, 'wp-content/' ) ) { continue; }
 if ( 'wp-includes/version.php' === $relative ) { $content = preg_replace( '/\$wp_version\s*=\s*([\'"])[^\'"]+\1/', '$wp_version = \'99.0\'', $original_version_file ); $archive->addFromString( 'wordpress/' . $relative, $content ); }
 else { $archive->addFile( $file->getPathname(), 'wordpress/' . $relative ); }
}
$archive->close(); $packages['https://packages.example.invalid/core.zip'] = $core_zip;
$slow_downloads = 0;
$download = static function( $reply, $url ) use ( &$packages, &$slow_downloads ) { if ( ! isset( $packages[$url] ) ) { return new WP_Error( 'unexpected_package', 'Only exact local test packages are permitted.' ); } if ( str_ends_with( $url, '/slow-plugin.zip' ) ) { $slow_downloads++; usleep( 3000000 ); } $copy = wp_tempnam( 'g012-package.zip' ); copy( $packages[$url], $copy ); return $copy; };
add_filter( 'upgrader_pre_download', $download, 10, 2 ); add_filter( 'filesystem_method', static fn() => 'direct' ); add_filter( 'pre_wp_mail', '__return_true' );
$records = [];
$acquire = new ReflectionMethod( YoRo_Connector_Runtime::class, 'acquire_lock' ); $release = new ReflectionMethod( YoRo_Connector_Runtime::class, 'release_lock' ); $lock = $acquire->invoke( null );
try {
 foreach ( [ 'plugins.update', 'themes.update', 'site.update_core' ] as $public ) {
  set_site_transient( 'update_plugins', (object) [ 'response' => [ 'g012-updater/g012-updater.php' => (object) [ 'new_version' => '2.0', 'package' => 'https://packages.example.invalid/plugin.zip', 'slug' => 'g012-updater', 'plugin' => 'g012-updater/g012-updater.php' ] ] ] );
  set_site_transient( 'update_themes', (object) [ 'response' => [ 'g012-updater' => [ 'new_version' => '2.0', 'package' => 'https://packages.example.invalid/theme.zip', 'theme' => 'g012-updater' ] ] ] );
  set_site_transient( 'update_core', (object) [ 'updates' => [ (object) [ 'response' => 'upgrade', 'current' => '99.0', 'locale' => 'en_US', 'download' => 'https://packages.example.invalid/core.zip', 'packages' => (object) [ 'full' => 'https://packages.example.invalid/core.zip', 'partial' => false, 'new_bundled' => false, 'no_content' => false, 'rollback' => false ] ] ], 'last_checked' => time() ] );
  $intent = 'plugins.update' === $public ? [ 'plugin' => 'g012-updater/g012-updater.php', 'target_version' => '2.0' ] : ( 'themes.update' === $public ? [ 'theme' => 'g012-updater', 'target_version' => '2.0' ] : [ 'target_version' => '99.0' ] );
  $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( $public, $intent );
  $action = YoRo_Early_Access_WordPress_Management::wire_action( $public );
  $source = YoRo_Early_Access_WordPress_Management::prepare( $action, $intent );
  $prepared = YoRo_Early_Access_WordPress_Management::execute( [ 'action' => YoRo_Early_Access_WordPress_Management::source_action( $action ), 'params' => [ 'intent' => $intent ] ] );
  $runtime = new YoRo_Connector_Runtime( $client ); $run = $runtime->run_early_access_write_once(); $result = end( $client->results );
  $assert( ! is_wp_error( $run ) && 'executed' === ( $result['status'] ?? null ), 'Actual native updater ' . $public . ': ' . wp_json_encode( is_wp_error( $run ) ? $run->get_error_message() : $result ) );
  $records[$public] = [ 'action' => YoRo_Early_Access_WordPress_Management::source_action( $action ), 'params' => [ 'intent' => $intent ], 'result' => $prepared, 'write' => false, 'intent' => $intent, 'source' => $source, 'executed' => $result, 'executed_params' => $source['params'] ];
  $runtime->run_early_access_write_once(); $replay = end( $client->results );
  $assert( wp_json_encode( $replay['result'] ?? null ) === wp_json_encode( $result['result'] ), 'No updater replay: ' . $public );
 }
 // A long updater may finish its filesystem change after the permit expires.
 // The API must reconcile that unacknowledged outcome; an expired claim cannot replay the updater.
 $packages['https://packages.example.invalid/slow-plugin.zip'] = $zip( 'slow-plugin', [ 'g012-updater/g012-updater.php' => "<?php\n/* Plugin Name: G012 updater fixture\nVersion: 3.0\n*/\n" ] );
 set_site_transient( 'update_plugins', (object) [ 'response' => [ 'g012-updater/g012-updater.php' => (object) [ 'new_version' => '3.0', 'package' => 'https://packages.example.invalid/slow-plugin.zip', 'slug' => 'g012-updater', 'plugin' => 'g012-updater/g012-updater.php' ] ] ] );
 $client = yoro_native_write_fixture_client(); $client->operation = yoro_wordpress_management_fixture_operation( 'plugins.update', [ 'plugin' => 'g012-updater/g012-updater.php', 'target_version' => '3.0' ] );
 $client->operation['expires_at'] = gmdate( 'c', time() + 2 ); $client->operation['authorization']['permit_expires_at'] = $client->operation['expires_at'];
 $runtime = new YoRo_Connector_Runtime( $client ); $run = $runtime->run_early_access_write_once();
 $assert( is_wp_error( $run ) && [] === $client->results && str_contains( file_get_contents( $plugin ), 'Version: 3.0' ), 'Expired permit cannot report an acknowledged success after the actual updater.' );
 $runtime->run_early_access_write_once();
 $assert( 1 === $slow_downloads && [] === $client->results, 'Long expired updater is never automatically downloaded or executed twice.' );
} finally { $release->invoke( null, $lock ); remove_filter( 'upgrader_pre_download', $download, 10 ); file_put_contents( ABSPATH . WPINC . '/version.php', $original_version_file ); }
if ( $path = getenv( 'G012_CORE_UPDATER_RESULTS' ) ) { file_put_contents( $path, wp_json_encode( $records ) ); }
echo "Three native WordPress updaters, exact offered packages, durable completion and replay passed.\n";
