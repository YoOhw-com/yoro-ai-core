<?php
/** Pure local artifact integrity/resource tests; no WordPress/database or host readiness claim. */
define( 'ABSPATH', __DIR__ );
define( 'YORO_AI_CORE_PATH', dirname( __DIR__, 2 ) . '/' );
define( 'YORO_AI_CORE_URL', 'https://store.test/wp-content/plugins/yoro-ai-core/' );
require_once dirname( __DIR__, 2 ) . '/includes/admin/class-yoro-preview-download.php';

$directory = sys_get_temp_dir() . '/yr0198-preview-download-' . bin2hex( random_bytes( 8 ) );
mkdir( $directory, 0700 );
$path = $directory . '/' . YoRo_Preview_Download::FILENAME;
$assert = static function( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } };
$resource = YoRo_Preview_Download::RESOURCE;
$base = 'https://store.test/wp-content/plugins/yoro-ai-core/assets/downloads/';
try {
    $assert( [] === YoRo_Preview_Download::current( $resource ), 'No verified release artifact is shipped; real UI must use URL fallback.' );
    $artifact = getenv( 'YORO_PUBLIC_PREVIEW_ZIP' );
    $assert( is_string( $artifact ) && is_file( $artifact ) && hash_file( 'sha256', $artifact ) === YoRo_Preview_Download::SHA256, 'Set YORO_PUBLIC_PREVIEW_ZIP to the exact API public export for this pair.' );
    copy( $artifact, $path );
    $metadata = [ 'version' => '0.2.0', 'resource' => $resource, 'filename' => YoRo_Preview_Download::FILENAME, 'sha256' => hash_file( 'sha256', $path ) ];
    $ready = YoRo_Preview_Download::validate( $metadata, $resource, $directory, $base );
    $assert( $ready['url'] === $base . YoRo_Preview_Download::FILENAME && $ready['sha256'] === $metadata['sha256'], 'Matching local bytes and configured resource must yield the contained download URL.' );
    foreach ( [ null, [], [ 'version' => '0.2.0' ], array_merge( $metadata, [ 'apps' => 'private' ] ), array_merge( $metadata, [ 'filename' => '../private.zip' ] ), array_merge( $metadata, [ 'sha256' => str_repeat( '0', 64 ) ] ), array_merge( $metadata, [ 'version' => '0.1.3' ] ), array_merge( $metadata, [ 'resource' => 'https://api.yorohq.com/mcp/early-access' ] ) ] as $bad ) {
        $assert( [] === YoRo_Preview_Download::validate( $bad, $resource, $directory, $base ), 'Missing, unapproved or mismatched artifact metadata must fail closed.' );
    }
    foreach ( [ '', 'https://api.yorohq.com/mcp/early-access', 'https://foreign.test/mcp/early-access' ] as $foreign ) {
        $assert( [] === YoRo_Preview_Download::validate( $metadata, $foreign, $directory, $base ), 'R2 archive must not be offered for another configured API resource.' );
    }
    foreach ( [ 'http://store.test/downloads/', 'https://user:secret@store.test/downloads/', 'https://store.test/downloads/?token=x', 'https://store.test/downloads/#fragment', 'javascript:alert(1)' ] as $unsafe ) {
        $assert( [] === YoRo_Preview_Download::validate( $metadata, $resource, $directory, $unsafe ), 'Unsafe download locations must fail closed.' );
    }
    unlink( $path );
    $assert( [] === YoRo_Preview_Download::validate( $metadata, $resource, $directory, $base ), 'Missing artifact cannot become a download.' );
    symlink( __FILE__, $path );
    $assert( [] === YoRo_Preview_Download::validate( $metadata, $resource, $directory, $base ), 'A symlink cannot substitute a release artifact.' );
    echo "Preview public download integrity/resource guards passed. Real public artifact and WordPress UI NOT_VERIFIED.\n";
} finally {
    if ( is_link( $path ) || file_exists( $path ) ) { unlink( $path ); }
    rmdir( $directory );
}
