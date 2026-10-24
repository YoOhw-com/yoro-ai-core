<?php
/** Exercise each destructive smoke contract's actual guard without loading WordPress. */
$root = dirname( __DIR__, 2 );
$fixture = sys_get_temp_dir() . '/yoro-wordpress-guard-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture );
mkdir( $fixture . '/runner' );
mkdir( $fixture . '/runner/wordpress' );
mkdir( $fixture . '/runner-other' );
mkdir( $fixture . '/outside' );
symlink( $fixture . '/outside', $fixture . '/runner/escaped' );
$contracts = [ 'management', 'media-fencing', 'pagination', 'updater', 'visibility-safety' ];
$cases = [
    [ 'yoro-plugin-smoke.test', 'true', '/runner/wordpress', true ],
    [ 'yoro-wordpress-smoke.test', 'true', '/runner/wordpress', true ],
    [ 'yoro-woocommerce-exact-pair.test', 'true', '/runner/wordpress', true ],
    [ 'yoro-wordpress-exact-pair.test', 'true', '/runner/wordpress', true ],
    [ 'fixture.invalid', '', '/runner/wordpress', true ],
    [ 'yoro-woocommerce-exact-pair.test', '', '/runner/wordpress', false ],
    [ 'yoro-woocommerce-exact-pair.test', 'true', '/outside', false ],
    [ 'yoro-woocommerce-exact-pair.test', 'true', '/runner-other', false ],
    [ 'yoro-woocommerce-exact-pair.test', 'true', '/runner/escaped', false ],
    [ 'live.example.com', 'true', '/runner/wordpress', false ],
    [ 'yoro-woocommerce-exact-pair.test.example.com', 'true', '/runner/wordpress', false ],
    [ 'yoro-wordpress-exact-pair.test', '', '/runner/wordpress', false ],
    [ 'yoro-wordpress-exact-pair.test', 'true', '/outside', false ],
];
$passed = 0;
try {
    foreach ( $contracts as $contract ) {
        $path = $root . '/tests/smoke/early-access-wordpress-' . $contract . '-contract.php';
        $source = file_get_contents( $path );
        $guard = substr( $source, 5, strpos( $source, 'require_once' ) - 5 );
        foreach ( $cases as [ $host, $actions, $wordpress, $allowed ] ) {
            $program = 'define("ABSPATH", ' . var_export( $fixture . $wordpress . '/', true ) . ');'
                . 'function home_url() { return ' . var_export( 'http://' . $host, true ) . '; }'
                . 'function wp_parse_url($url, $component) { return parse_url($url, $component); }'
                . 'putenv(' . var_export( 'GITHUB_ACTIONS=' . $actions, true ) . ');'
                . 'putenv(' . var_export( 'RUNNER_TEMP=' . $fixture . '/runner', true ) . ');'
                . 'try {' . $guard . '} catch (RuntimeException $error) { exit(23); }';
            $pipes = [];
            $process = proc_open( [ PHP_BINARY, '-r', $program ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
            $output = stream_get_contents( $pipes[1] ) . stream_get_contents( $pipes[2] );
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            $status = proc_close( $process );
            if ( $status !== ( $allowed ? 0 : 23 ) ) {
                throw new RuntimeException( $contract . ': ' . $host . ' / ' . $actions . ' / ' . $wordpress . ' expected ' . ( $allowed ? 'allow' : 'deny' ) . ', exit ' . $status . ': ' . $output );
            }
            ++$passed;
        }
    }
} finally {
    unlink( $fixture . '/runner/escaped' );
    rmdir( $fixture . '/runner/wordpress' );
    rmdir( $fixture . '/runner' );
    rmdir( $fixture . '/runner-other' );
    rmdir( $fixture . '/outside' );
    rmdir( $fixture );
}
echo 'WordPress management disposable guards passed (' . $passed . " cases).\n";
