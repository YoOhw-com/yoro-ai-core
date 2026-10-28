<?php

if (!defined('ABSPATH')) { exit; }

/** Private, default-disabled YR-0196 verification. Never an action or transport hook. */
final class YoRo_YR0196_Canary_Context
{
    public const Extension = 'yr0196_canary';
    public const Marker = '_yoro_yr0196_fixture';
    private array $current;
    private ?array $armed = null;

    private function __construct(private array $parent, private array $config, private Closure $fence)
    {
        $this->current = $parent;
    }

    public static function config(): array
    {
        return defined('YORO_YR0196_CANARY') && is_array(YORO_YR0196_CANARY) ? YORO_YR0196_CANARY : [];
    }

    public static function operation_hash(array $operation): string
    {
        unset($operation['extensions']['yoro.mcp_early_access_commerce_write'][self::Extension]);
        return YoRo_Early_Access_Product_Read::params_hash($operation);
    }

    private static function targets(array $operation): array
    {
        $params = $operation['params'] ?? [];
        return isset($params['order_id']) ? [$params['order_id']] : ($params['order_ids'] ?? array_column($params['items'] ?? [], 'order_id'));
    }

    public static function owned(array $operation): bool
    {
        foreach (self::targets($operation) as $id) {
            $order = wc_get_order($id);
            if ($order && $order->get_meta(self::Marker, true) !== '') { return true; }
        }
        return isset($operation['extensions']['yoro.mcp_early_access_commerce_write'][self::Extension]);
    }

    /** Only the runner retains this object, after its ordinary admission and new journal marker. */
    public static function admit(array $operation, int $userId, Closure $fence): ?self
    {
        if (!self::owned($operation)) { return null; }
        $config = self::config();
        if (($config['version'] ?? null) !== 1 || ($config['enabled'] ?? null) !== true || ($config['task_id'] ?? null) !== 'YR-0196'
            || !is_string($config['run_id'] ?? null) || !preg_match('/\A[a-f0-9]{32}\z/D', $config['run_id'])
            || !is_int($config['expires_at'] ?? null) || $config['expires_at'] <= time()
            || $config['expires_at'] > time() + 3600 || !is_array($config['authority'] ?? null)
            || !is_array($config['permits'] ?? null) || count($config['permits']) < 1 || count($config['permits']) > 12
            || $userId !== get_current_user_id() || !$fence()) { self::deny(); }
        $context = new self($operation, $config, $fence);
        $context->permit($operation);
        return $context;
    }

    private function permit(array $operation): array
    {
        if (!($this->fence)() || $this->config['expires_at'] <= time()) { self::deny(); }
        $facts = $operation['extensions']['yoro.mcp_early_access_commerce_write'] ?? [];
        $fields = ['profile','resource','binding_version','oauth_client_id','grant_id','grant_version','token_id','user_id','site_id','wordpress_identity_id','connection_id','installation_uuid','site_key_id','site_key_hash','manifest_id','manifest_revision','manifest_hash','actor_role','actor_capabilities_hash','resource_scopes_hash'];
        if (count($this->config['authority']) !== count($fields)) { self::deny(); }
        foreach ($fields as $field) {
            if (!array_key_exists($field, $this->config['authority']) || ($facts[$field] ?? null) !== $this->config['authority'][$field]) { self::deny(); }
        }
        if (($facts['profile'] ?? null) !== 'early_access' || ($facts['binding_version'] ?? null) !== 2
            || !in_array($operation['action'] ?? '', ['orders.send_authored_email','orders.send_native_email','orders.update_status','orders.batch_update_status'], true)
            || !is_string($facts['source_operation_id'] ?? null) || !preg_match('/\Aenvelope_[a-f0-9]{64}\z/D', $facts['source_operation_id'])
            || !is_string($facts['source_result_hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $facts['source_result_hash'])
            || empty($operation['preview_hash']) || empty($operation['authorization']['execution_permit'])) { self::deny(); }
        $hash = self::operation_hash($operation);
        $matches = array_values(array_filter($this->config['permits'], static fn($permit) => is_array($permit) && ($permit['operation_hash'] ?? null) === $hash));
        if (count($matches) !== 1) { self::deny(); }
        $permit = $matches[0];
        $binding = ['version'=>1,'task_id'=>'YR-0196','run_id'=>$this->config['run_id'],'permit_id'=>$permit['id'] ?? null,
            'operation_hash'=>$hash,'mode'=>$permit['mode'] ?? null,'expires_at'=>$this->config['expires_at'],'permit_hash'=>YoRo_Early_Access_Product_Read::params_hash($permit),
            'envelope_id'=>$operation['envelope_id'],'params_hash'=>YoRo_Early_Access_Product_Read::params_hash($operation['params'])];
        // Children retain the parent binding on the wire; derive their identity only from the admitted parent.
        $child = ($operation['extensions']['yoro.local_order_batch'] ?? null) !== null;
        $parentBinding = $this->parent['extensions']['yoro.mcp_early_access_commerce_write'][self::Extension] ?? null;
        if (($facts[self::Extension] ?? null) !== ($child ? $parentBinding : $binding)
            || !is_string($permit['id'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $permit['id'])
            || !in_array($permit['mode'] ?? null, ['capture','fault','observe','batch'], true)) { self::deny(); }
        if ($child) {
            $index = $operation['extensions']['yoro.local_order_batch']['index'] ?? null;
            if (!is_int($index) || !isset($this->parent['params']['items'][$index])
                || $operation !== YoRo_Order_Batch::child_operation($this->parent, $index)) { self::deny(); }
        } elseif ($operation !== $this->parent) { self::deny(); }
        foreach (self::targets($operation) as $id) {
            $order = wc_get_order($id);
            $marker = $order ? $order->get_meta(self::Marker, true) : null;
            if ($marker !== ['task_id'=>'YR-0196','run_id'=>$this->config['run_id'],'owner_user_id'=>$facts['user_id'],
                'site_id'=>$facts['site_id'],'wordpress_identity_id'=>$facts['wordpress_identity_id']]) { self::deny(); }
        }
        if (self::targets($operation) !== ($permit['target_ids'] ?? null)) { self::deny(); }
        $mail = in_array($operation['action'], ['orders.send_authored_email','orders.send_native_email'], true);
        if (($permit['mode'] === 'capture') !== $mail || ($permit['mode'] === 'batch') !== ($operation['action'] === 'orders.batch_update_status')) { self::deny(); }
        return $permit;
    }

    public function before(array $operation): void
    {
        $permit = $this->permit($operation);
        $this->current = $operation;
        $this->armed = null;
        if (in_array($permit['mode'], ['fault','observe'], true)) {
            self::consume($permit, ['version'=>1,'task_id'=>'YR-0196','run_id'=>$this->config['run_id'],'operation_hash'=>self::operation_hash($operation),'mode'=>$permit['mode']]);
            $this->armed = $permit;
        }
    }

    public function after(array $operation, array $result): void
    {
        if ($operation !== $this->current || !($this->fence)()) { self::deny(); }
        if (($this->armed['mode'] ?? null) === 'fault' && ($result['success'] ?? null) === true) {
            $this->armed = null;
            throw new RuntimeException('YR-0196 one-shot interruption after the owned effect; reconcile before any retry.');
        }
    }

    public function capture(array $params, string $action, array $prepared): array
    {
        $permit = $this->permit($this->current);
        if (($permit['mode'] ?? null) !== 'capture' || $action !== $this->current['action'] || $params !== $this->current['params']) { self::deny(); }
        $source = $prepared['source'];
        $native = $action === 'orders.send_native_email';
        $mail = ['recipient'=>$source['recipient'],'subject'=>$native ? $params['subject'] : $params['changes']['subject'],
            'body'=>$native ? $source['html_body'] : $prepared['authored']['html_body'],
            'from_address'=>$source['settings']['from_address'],'from_name'=>$source['settings']['from_name'],'content_type'=>'text/html'];
        $expected = ['recipient'=>$mail['recipient'],'subject_hash'=>hash('sha256',$mail['subject']),'body_hash'=>hash('sha256',$mail['body']),
            'from_address'=>$mail['from_address'],'from_name_hash'=>hash('sha256',$mail['from_name']),'content_type'=>'text/html'];
        if (($permit['mail'] ?? null) !== $expected || !str_ends_with(strtolower($mail['recipient']), '@example.invalid')
            || strlen($mail['body']) > 24000 || strlen($mail['subject']) > 200) { self::deny(); }
        $id = 'capture_'.substr($permit['id'], 0, 64);
        self::consume($permit, ['version'=>1,'task_id'=>'YR-0196','run_id'=>$this->config['run_id'],'operation_hash'=>self::operation_hash($this->current),
            'mode'=>'capture','record_id'=>$id,'envelope_id'=>$this->current['envelope_id'],'operation_id'=>$this->current['operation_id'],
            'action'=>$action,'order_id'=>$params['order_id'],'content_hash'=>YoRo_Order_Email::content_hash($params,$action),'mail'=>$mail,'occurred_at'=>gmdate('c')]);
        return ['order_id'=>$params['order_id'],'record_id'=>$id,'outcome'=>'captured_not_delivered',
            'content_hash'=>YoRo_Order_Email::content_hash($params,$action),'current_version'=>$source['version'],'inbox_delivery_verified'=>false];
    }

    /** One unique database insert stores consumption and capture together. No cache, update, expiry reuse or clear. */
    private static function consume(array $permit, array $record): void
    {
        global $wpdb;
        $key = 'yoro_yr0196_permit_'.$permit['id'];
        $value = wp_json_encode($record);
        if (!is_string($value) || strlen($value) > 40000 || !isset($wpdb->options)) { self::deny(); }
        $suppressed = $wpdb->suppress_errors(true);
        try {
            if ((string)$wpdb->get_var('SELECT @@session.autocommit') !== '1' || $wpdb->last_error !== ''
                || (string)$wpdb->get_var('SELECT @@global.innodb_flush_log_at_trx_commit') !== '1' || $wpdb->last_error !== ''
                || $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $wpdb->options)) !== 'InnoDB'
                || $wpdb->last_error !== '') { self::deny(); }
            // SAVEPOINT/RELEASE changes neither commit mode nor caller data. A live outer
            // transaction retains the savepoint; autocommit has no savepoint to release.
            $probe = 'yoro_yr0196_'.bin2hex(random_bytes(16));
            $created = $wpdb->query('SAVEPOINT '.$probe);
            if ($created === false || $wpdb->last_error !== '') { self::deny(); }
            $released = $wpdb->query('RELEASE SAVEPOINT '.$probe);
            if ($released !== false) { self::deny(); }
            $warning = $wpdb->get_row('SHOW WARNINGS', ARRAY_A);
            if ((int)($warning['Code'] ?? 0) !== 1305 || ($warning['Message'] ?? null) !== 'SAVEPOINT '.$probe.' does not exist') { self::deny(); }
            $inserted = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, $value));
            if ($inserted !== 1 || $wpdb->last_error !== '') { self::deny(); }
            $saved = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
            if ($wpdb->last_error !== '' || $saved !== $value) { self::deny(); }
        } finally { $wpdb->suppress_errors($suppressed); }
    }

    private static function deny(): never
    {
        throw new RuntimeException('YR-0196 private task binding or durable permit is unavailable; no effect is permitted.');
    }
}
