<?php
/** Isolation guard negatives run without WordPress, a connection or native DDL. */
define('ABSPATH', __DIR__.'/');
define('DB_NAME', 'wordpress');
define('DB_HOST', '127.0.0.1:3306');
function untrailingslashit($value) { return rtrim($value, '/'); }
function home_url() { return $GLOBALS['fixture_home']; }
$wpdb = new class {
    public $prefix = 'woo_'; public $options = 'woo_options'; public $last_error = ''; public $present = 0; public $marker_queries = 0;
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_var($query) {
        if (str_starts_with($query[0], 'SHOW TABLES')) {
            return $this->present === 2 || ($this->present === 1 && str_ends_with($query[1][0], 'fulfillments')) ? $query[1][0] : null;
        }
        $this->marker_queries++;
        return 1; // Never admit an initializer in this no-database unit contract.
    }
};
require dirname(__DIR__).'/contracts/helpers/native-fulfillment-ci-storage.php';
$old_github = getenv('GITHUB_ACTIONS'); $old_runner = getenv('RUNNER_TEMP');
try {
    putenv('GITHUB_ACTIONS=true'); putenv('RUNNER_TEMP='.dirname(__DIR__));
    $GLOBALS['fixture_home'] = 'http://yoro-woocommerce-exact-pair.test';
    foreach (['valid_context', 'wrong_home', 'not_hosted', 'outside_runner', 'partial_storage'] as $case) {
        $wpdb->marker_queries = 0; $wpdb->present = $case === 'partial_storage' ? 1 : 0;
        $GLOBALS['fixture_home'] = $case === 'wrong_home' ? 'https://wp-canary-us.yorohq.com' : 'http://yoro-woocommerce-exact-pair.test';
        putenv('GITHUB_ACTIONS='.($case === 'not_hosted' ? 'false' : 'true'));
        putenv('RUNNER_TEMP='.($case === 'outside_runner' ? dirname(__DIR__).'/contracts' : dirname(__DIR__)));
        try { yoro_native_fulfillment_ci_storage(); throw new LogicException('Unsafe initialization admitted: '.$case); }
        catch (RuntimeException $error) {
            $valid = $case === 'valid_context';
            if ($wpdb->marker_queries !== ($valid ? 1 : 0) || ($valid && $error->getMessage() !== 'Existing native test configuration must not be initialized.') || (!$valid && !str_contains($error->getMessage(), 'Isolation: '))) { throw $error; }
        }
    }
    echo "Native fulfillment storage guard: valid context reaches marker guard; home, host context, path and partial-storage negatives deny initialization.\n";
} finally {
    putenv($old_github === false ? 'GITHUB_ACTIONS' : 'GITHUB_ACTIONS='.$old_github);
    putenv($old_runner === false ? 'RUNNER_TEMP' : 'RUNNER_TEMP='.$old_runner);
}
