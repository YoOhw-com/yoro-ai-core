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
$fixture_admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
if ( [] === $fixture_admins ) { throw new RuntimeException( 'A disposable administrator fixture is required.' ); }
wp_set_current_user( (int) $fixture_admins[0] );
$assert = static function( $condition, $why ) { if ( ! $condition ) { throw new RuntimeException( $why ); } };
YoRo_WordPress_Actor::record_access( [ 'assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => [ 'yoro_view_store_data', 'yoro_execute_actions' ] ] );
yoro_native_write_fixture_setup();
update_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'yes' ); update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time() );
YoRo_Early_Access_WordPress_Management::register(); add_filter( 'pre_wp_mail', '__return_true' );
$post = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'private', 'post_title' => 'Pagination private source', 'post_author' => get_current_user_id() ] );
$prefix = 'g012-page-' . wp_generate_password( 8, false );
for ( $index = 0; $index < 25; $index++ ) {
 wp_insert_comment( [ 'comment_post_ID' => $post, 'comment_content' => 'Page fixture', 'comment_author_email' => 'private@example.invalid', 'comment_approved' => '1' ] );
 wp_insert_term( $prefix . '-' . $index, 'category' );
 wp_insert_user( [ 'user_login' => $prefix . '-' . $index, 'user_pass' => wp_generate_password( 32 ), 'user_email' => $prefix . '-' . $index . '@example.invalid', 'role' => 'subscriber' ] );
 wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post, 'post_title' => 'Revision ' . $index, 'post_content' => '<p>Bounded fixture.</p>' ] );
}
foreach ( [
 'comments.list' => [ [ 'content_id' => $post ], 'comment_id' ],
 'terms.list' => [ [ 'taxonomy' => 'category' ], 'term_id' ],
 'users.list' => [ [], 'user_id' ],
 'content.list_revisions' => [ [ 'content_id' => $post ], 'revision_id' ],
] as $public => [ $params, $id_key ] ) {
 $action = YoRo_Early_Access_WordPress_Management::wire_action( $public );
 $first = YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $action, 'params' => $params + [ 'limit' => 20 ] ] );
 $assert( ! is_wp_error( $first ), 'First bounded page: ' . $public ); $first = $first['data'];
 $assert( 20 === $first['count'] && true === $first['has_next_page'] && $first['next_cursor'] === end( $first['items'] )[$id_key], 'Explicit next cursor: ' . $public );
 $second = YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $action, 'params' => $params + [ 'limit' => 20, 'before_id' => $first['next_cursor'] ] ] );
 $assert( ! is_wp_error( $second ), 'Second bounded page: ' . $public ); $second = $second['data'];
 $assert( count( $second['items'] ) <= 20 && ! array_intersect( array_column( $first['items'], $id_key ), array_column( $second['items'], $id_key ) ), 'Pages have no repeated target: ' . $public );
 $assert( ! str_contains( wp_json_encode( [ $first, $second ] ), '@example.invalid' ), 'No email or private account fields in projection: ' . $public );
 $assert( is_wp_error( YoRo_Early_Access_WordPress_Management::execute( [ 'action' => $action, 'params' => $params + [ 'limit' => 21 ] ] ) ), 'Oversized page rejected: ' . $public );
}
foreach ( [
 [ 'content.schedule', [ 'content_id' => $post, 'publish_at' => '2027-02-30T10:00:00+00:00' ] ],
 [ 'content.bulk_update_status', [ 'content_ids' => [ $post, $post ], 'status' => 'draft' ] ],
 [ 'plugins.activate', [ 'plugin' => '../outside.php' ] ],
 [ 'themes.activate', [ 'theme' => '../outside' ] ],
] as [ $public, $intent ] ) {
 $assert( is_wp_error( YoRo_Early_Access_WordPress_Management::prepare( YoRo_Early_Access_WordPress_Management::wire_action( $public ), $intent ) ), 'Invalid source denied: ' . $public );
}
echo "Native keyset pagination, bounded pages, private-field projection and invalid source denials passed.\n";
