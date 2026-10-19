<?php
/** Isolation guard negatives run without WordPress, a connection or native DDL. */
define('ABSPATH', __DIR__.'/');
define('DB_NAME', 'wordpress');
define('DB_HOST', '127.0.0.1:3306');
function untrailingslashit($value) { return rtrim($value, '/'); }
function home_url() { return 'https://request-context.example.invalid'; }
function get_option($name, $default = false) { return $name === 'home' ? 'https://option-filter.example.invalid' : $default; }
$wpdb = new class {
    public $prefix = 'woo_'; public $options = 'woo_options'; public $last_error = ''; public $present = 0; public $marker_queries = 0;
    public function esc_like($value) { return $value; }
    public function prepare($sql, ...$args) { return [$sql, $args]; }
    public function get_var($query) {
        if (($query[1][0] ?? null) === 'home') { return $GLOBALS['fixture_home']; }
        if (str_starts_with($query[0], 'SHOW TABLES')) {
            return $this->present === 2 || ($this->present === 1 && str_ends_with($query[1][0], 'fulfillments')) ? $query[1][0] : null;
        }
        $this->marker_queries++;
        return 1; // Never admit an initializer in this no-database unit contract.
    }
};
require dirname(__DIR__).'/contracts/helpers/native-fulfillment-ci-storage.php';
$old_github = getenv('GITHUB_ACTIONS'); $old_runner = getenv('RUNNER_TEMP');
$old_canary_env = [];
foreach (['GITHUB_WORKSPACE', 'GITHUB_REPOSITORY', 'GITHUB_WORKFLOW_REF', 'GITHUB_EVENT_NAME'] as $key) { $old_canary_env[$key] = getenv($key); }
try {
    putenv('GITHUB_ACTIONS=true'); putenv('RUNNER_TEMP='.dirname(__DIR__));
    $GLOBALS['fixture_home'] = 'http://yoro-woocommerce-exact-pair.test';
    foreach (['valid_context', 'valid_core_path', 'valid_pair_path', 'wrong_home', 'wrong_path', 'not_hosted', 'outside_runner', 'partial_storage'] as $case) {
        $wpdb->marker_queries = 0; $wpdb->present = $case === 'partial_storage' ? 1 : 0;
        $GLOBALS['fixture_home'] = match ($case) {
            'valid_core_path' => 'http://yoro-plugin-smoke.test/wordpress',
            'valid_pair_path' => 'http://yoro-woocommerce-exact-pair.test/wordpress-woo',
            'wrong_home' => 'https://wp-canary-us.yorohq.com',
            'wrong_path' => 'http://yoro-woocommerce-exact-pair.test/unowned-directory',
            default => 'http://yoro-woocommerce-exact-pair.test',
        };
        putenv('GITHUB_ACTIONS='.($case === 'not_hosted' ? 'false' : 'true'));
        putenv('RUNNER_TEMP='.($case === 'outside_runner' ? dirname(__DIR__).'/contracts' : dirname(__DIR__)));
        try { yoro_native_fulfillment_ci_storage(); throw new LogicException('Unsafe initialization admitted: '.$case); }
        catch (RuntimeException $error) {
            $valid = in_array($case, ['valid_context', 'valid_core_path', 'valid_pair_path'], true);
            if ($wpdb->marker_queries !== ($valid ? 1 : 0) || ($valid && $error->getMessage() !== 'Existing native test configuration must not be initialized.') || (!$valid && !str_contains($error->getMessage(), 'Isolation: '))) { throw $error; }
        }
    }
    $workspace = dirname(__DIR__);
    putenv('GITHUB_WORKSPACE='.$workspace); putenv('GITHUB_REPOSITORY=YoOhw-com/yoro-ai-api');
    putenv('GITHUB_WORKFLOW_REF=YoOhw-com/yoro-ai-api/.github/workflows/release-readiness.yml@refs/heads/main'); putenv('GITHUB_EVENT_NAME=push');
    if (!yoro_native_fulfillment_ci_path_allowed(false, $workspace.'/wordpress', 'http://yoro-smoke.test/wordpress')) { throw new LogicException('Exact API canary root denied.'); }
    if (yoro_native_fulfillment_ci_path_allowed(false, $workspace.'/other-wordpress', 'http://yoro-smoke.test/wordpress')) { throw new LogicException('Unowned workspace root admitted.'); }
    putenv('GITHUB_WORKFLOW_REF=YoOhw-com/yoro-ai-api/.github/workflows/other.yml@refs/heads/main');
    if (yoro_native_fulfillment_ci_path_allowed(false, $workspace.'/wordpress', 'http://yoro-smoke.test/wordpress')) { throw new LogicException('Wrong workflow admitted.'); }
    echo "Native fulfillment storage guard: eight storage/context cases and exact API canary root/workflow boundaries passed.\n";
} finally {
    putenv($old_github === false ? 'GITHUB_ACTIONS' : 'GITHUB_ACTIONS='.$old_github);
    putenv($old_runner === false ? 'RUNNER_TEMP' : 'RUNNER_TEMP='.$old_runner);
    foreach ($old_canary_env as $key => $value) { putenv($value === false ? $key : $key.'='.$value); }
}
