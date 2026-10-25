<?php

if ( ! defined('ABSPATH') ) { exit; }

final class YoRo_Early_Access_Settings_Read {
    const ACTIONS = ['site.get_settings', 'site.get_settings_schema', 'commerce.settings.get', 'commerce.settings.get_schema'];
    /** API-owned v1 contract projection; no local scope expansion. */
    public static function definition() { return json_decode(file_get_contents(__DIR__.'/settings-read-v1.json'), true, 512, JSON_THROW_ON_ERROR); }

    public static function manifest() {
        $d = self::definition();
        return [ 'version' => 1, 'actions' => self::ACTIONS, 'wordpress_keys' => $d['wordpress_keys'], 'woocommerce_keys' => $d['woocommerce_keys'], 'semantics' => 'option_api_snapshot_not_effective_behavior' ];
    }

    public static function register() {
        foreach ( self::ACTIONS as $action ) {
            if ( str_starts_with($action, 'commerce.') && ! yoro_ai_core_has_woocommerce() ) { continue; }
            YoRo_Action_Registry::register($action, [ 'label' => 'Selected settings snapshot', 'description' => 'Read only a closed allowlist of settings or its schema. Does not prove effective storefront behavior.',
                'category' => 'site', 'permission' => 'yoro_view_store_data', 'callback' => static function($params) use ($action) { return self::execute(['action' => $action, 'params' => $params]); },
                'canonical_input_schema' => [],
                'input_schema' => [], 'output_schema' => self::definition()['output_schema'], 'effect'=>'read', 'write_action'=>false, 'max_targets'=>1 ]);
        }
    }

    public static function valid_params($action, $params) { return in_array($action, self::ACTIONS, true) && is_array($params) && [] === $params; }

    public static function execute(array $operation) {
        $action = $operation['action'] ?? ''; $params = $operation['params'] ?? [];
        $woo = str_starts_with($action, 'commerce.');
        if (!self::valid_params($action, $params) || !current_user_can('yoro_view_store_data') || !current_user_can('read')
            || (!$woo && !current_user_can('manage_options')) || ($woo && (yoro_ai_core_has_woocommerce() ? !current_user_can('manage_woocommerce') : !current_user_can('manage_options')))) { return new WP_Error('settings_read_denied', 'Current native settings permission is required.'); }
        if ($woo && yoro_ai_core_has_woocommerce() && (!function_exists('wc_rest_check_manager_permissions') || !wc_rest_check_manager_permissions('settings', 'read'))) { return new WP_Error('settings_read_denied', 'Native WooCommerce settings permission is required.'); }
        $definition = self::definition(); $kind = str_contains($action, 'schema') ? 'schema' : 'values';
        $state = $woo && !yoro_ai_core_has_woocommerce() ? 'not_installed' : 'supported'; $rows = [];
        $keys = $definition[$woo ? 'woocommerce_keys' : 'wordpress_keys'];
        $defaults = ['blogname'=>'', 'blogdescription'=>'', 'timezone_string'=>'', 'date_format'=>'F j, Y', 'time_format'=>'g:i a', 'start_of_week'=>1, 'posts_per_page'=>10, 'default_comment_status'=>'open',
            'woocommerce_currency_pos'=>'left','woocommerce_price_thousand_sep'=>',','woocommerce_price_decimal_sep'=>'.','woocommerce_currency'=>'USD','woocommerce_price_num_decimals'=>2];
        if ($state === 'supported') {
            foreach ($keys as $key) {
                [$label,$type,$length,$minimum,$maximum,$enum] = $definition['fields'][$key];
                $value = null;
                if ($kind === 'values') {
                    $value = get_option($key, $defaults[$key]);
                    if ($type === 'integer' && is_string($value) && preg_match('/\A[0-9]{1,6}\z/D',$value)) { $value = (int)$value; }
                    if (($type === 'integer' && (!is_int($value) || $value < $minimum || $value > $maximum))
                        || ($type === 'string' && (!is_string($value) || 1 !== preg_match('//u',$value) || strlen($value) > $length || preg_match('/[\x00-\x1f\x7f<>]/',$value)))
                        || ($enum && !in_array($value,$enum,true)) || ($key === 'woocommerce_currency' && !preg_match('/\A[A-Z]{3}\z/D',$value))
                        || ($key === 'timezone_string' && $value !== '' && !in_array($value, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC),true))) { $state='unsupported'; $rows=[]; break; }
                }
                $rows[] = ['key'=>$key,'label'=>$label,'type'=>$type,'value'=>$value,'provenance'=>$kind === 'schema'?'schema_only':'wordpress_option_api',
                    'enum'=>$enum,'minimum'=>$minimum,'maximum'=>$maximum,'max_length'=>$length,'read_only'=>true];
            }
        }
        global $wp_version;
        $component = $woo ? (defined('WC_VERSION') ? WC_VERSION : '') : (string)$wp_version;
        $component = is_string($component) && preg_match('/\A[0-9A-Za-z.+_-]{1,80}\z/D',$component) ? $component : '';
        if ($state === 'supported' && $component === '') { $state='unsupported'; $rows=[]; }
        $data = ['version'=>1,'provider'=>$woo?'woocommerce':'wordpress','kind'=>$kind,'status'=>$state,'settings'=>$rows,'component_version'=>$component,'captured_at'=>gmdate('c'),
            'snapshot_version'=>hash('sha256',wp_json_encode([$woo?'woocommerce':'wordpress',$kind,$state,$rows,$component]))];
        $summary = 'Read-only option API snapshot for the selected settings allowlist. Values can include native defaults and filters; effective checkout, payment and shipping behavior is not verified.';
        return ['success'=>true,'action'=>$action,'message'=>$summary,'summary'=>$summary,'data'=>$data,'read_only'=>true,'undoable'=>false,'result_type'=>'settings_read','display_type'=>'settings_read','assistant_summary'=>$summary,'extensions'=>[]];
    }
}
