<?php

/** Declaration doubles only: no licensed owner bootstrap, storage or gateway execution. */
$ownerDeclarations = <<<'PHP'
class WC_Subscriptions { public static $version = OWNER_VERSION; }
class WC_Subscription {
    public function get_date($date_type, $timezone = 'gmt', $exclude_statuses = []) {}
    public function get_billing_period($context = 'view') {}
    public function get_billing_interval($context = 'view') {}
    public function get_requires_manual_renewal($context = 'view') {}
    public function get_parent_id($context = 'view') {}
    public function get_items($types = 'line_item') {}
    public function get_date_created($context = 'view') {}
}
class WC_Subscriptions_Product {
    public static function get_period($product) {}
    public static function get_interval($product) {}
    public static function get_length($product) {}
    public static function get_trial_length($product) {}
    public static function get_trial_period($product) {}
    public static function get_sign_up_fee($product) {}
}
function wcs_get_subscription($the_subscription) {}
function wcs_get_subscriptions($args) {}
function wcs_get_subscription_ids_for_order($order, $order_types = ['any']) {}
function wc_get_orders($args) {}
PHP;
$admissionCases = ['native declarations' => [$ownerDeclarations, true]];
foreach (['8.8.0', '9.2.0', '9.3.1', '8.8.1-dev', '9.3.0-dev', '', 930] as $unsupportedVersion) {
    $admissionCases['unsupported version '.json_encode($unsupportedVersion)] = [str_replace('OWNER_VERSION', var_export($unsupportedVersion, true), $ownerDeclarations), false];
}
$admissionCases['absent or inactive owner'] = [str_replace('class WC_Subscriptions { public static $version = OWNER_VERSION; }', '', $ownerDeclarations), false];
foreach (['WC_Subscription', 'WC_Subscriptions_Product'] as $class) {
    $admissionCases['missing '.$class] = [str_replace('class '.$class.' {', 'class Unavailable_'.$class.' {', $ownerDeclarations), false];
}
foreach ([
    'wcs_get_subscription' => '$the_subscription',
    'wcs_get_subscriptions' => '$args',
    'wcs_get_subscription_ids_for_order' => '$order, $order_types = [\'any\']',
    'wc_get_orders' => '$args',
] as $function => $parameters) {
    $declaration = 'function '.$function.'('.$parameters.') {}';
    $admissionCases['missing '.$function] = [str_replace($declaration, '', $ownerDeclarations), false];
    $admissionCases['extra parameter '.$function] = [str_replace($declaration, 'function '.$function.'('.$parameters.', $extra = null) {}', $ownerDeclarations), false];
    $optional = $function === 'wcs_get_subscription_ids_for_order' ? '$order = null, $order_types = [\'any\']' : $parameters.' = null';
    $admissionCases['optional required parameter '.$function] = [str_replace($declaration, 'function '.$function.'('.$optional.') {}', $ownerDeclarations), false];
}
foreach ([
    'get_date' => '$date_type, $timezone = \'gmt\', $exclude_statuses = []',
    'get_billing_period' => '$context = \'view\'',
    'get_billing_interval' => '$context = \'view\'',
    'get_requires_manual_renewal' => '$context = \'view\'',
    'get_parent_id' => '$context = \'view\'',
    'get_items' => '$types = \'line_item\'',
    'get_date_created' => '$context = \'view\'',
] as $method => $parameters) {
    $declaration = 'public function '.$method.'('.$parameters.') {}';
    foreach (['private', 'protected', 'public static'] as $modifier) {
        $admissionCases[$modifier.' '.$method] = [str_replace($declaration, $modifier.' function '.$method.'('.$parameters.') {}', $ownerDeclarations), false];
    }
    $admissionCases['missing '.$method] = [str_replace($declaration, '', $ownerDeclarations), false];
    $admissionCases['extra parameter '.$method] = [str_replace($declaration, 'public function '.$method.'('.$parameters.', $extra = null) {}', $ownerDeclarations), false];
    $changedRequired = $method === 'get_date' ? '$date_type = null, $timezone = \'gmt\', $exclude_statuses = []' : preg_replace('/ = .+/', '', $parameters);
    $admissionCases['wrong required arity '.$method] = [str_replace($declaration, 'public function '.$method.'('.$changedRequired.') {}', $ownerDeclarations), false];
}
foreach (['get_period', 'get_interval', 'get_length', 'get_trial_length', 'get_trial_period', 'get_sign_up_fee'] as $method) {
    $declaration = 'public static function '.$method.'($product) {}';
    foreach (['private static', 'protected static', 'public'] as $modifier) {
        $admissionCases[$modifier.' product '.$method] = [str_replace($declaration, $modifier.' function '.$method.'($product) {}', $ownerDeclarations), false];
    }
    $admissionCases['missing product '.$method] = [str_replace($declaration, '', $ownerDeclarations), false];
    $admissionCases['optional product '.$method] = [str_replace($declaration, 'public static function '.$method.'($product = null) {}', $ownerDeclarations), false];
    $admissionCases['extra parameter product '.$method] = [str_replace($declaration, 'public static function '.$method.'($product, $extra = null) {}', $ownerDeclarations), false];
}
$admissionCases['magic callability is not a declared product API'] = [str_replace('public static function get_period($product) {}', 'public static function __callStatic($name, $arguments) {}', $ownerDeclarations), false];
$admissionActions = ['subscriptions.get_status', 'subscriptions.search', 'subscriptions.get_details', 'subscriptions.get_order_context', 'subscriptions.get_related_orders', 'subscriptions.get_summary', 'subscriptions.get_renewal_report'];
$admissionCount = 0;
foreach (['8.8.1', '9.3.0'] as $admissionVersion) {
    foreach ($admissionCases as $case => [$declarations, $expected]) {
        $code = "define('ABSPATH','/disposable/');".str_replace('OWNER_VERSION', var_export($admissionVersion, true), $declarations)
            .'require '.var_export(realpath(__DIR__.'/../../includes/commerce/class-yoro-subscriptions-read.php'), true).';'
            .'echo json_encode([YoRo_Subscriptions_Read::supported(),YoRo_Subscriptions_Read::manifest()],JSON_THROW_ON_ERROR);';
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Owner admission process unavailable.'); }
        $output = stream_get_contents($pipes[1]);$errors = stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit = proc_close($process);
        if ($exit !== 0 || $errors !== '') { throw new RuntimeException($admissionVersion.' '.$case.' crashed: '.$errors); }
        [$supported, $manifest] = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        if ($supported !== $expected || $manifest['owner_versions'] !== ['8.8.1', '9.3.0'] || $manifest['actions'] !== ($expected ? $admissionActions : [$admissionActions[0]])) {
            throw new RuntimeException($admissionVersion.' '.$case.' did not fail closed or retain exact read negotiation.');
        }
        $admissionCount++;
    }
}
echo 'Subscriptions declaration admission: '.$admissionCount." isolated version/signature cases passed.\n";
