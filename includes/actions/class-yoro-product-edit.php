<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded product patch values; omitted fields are never changed. */
final class YoRo_Product_Edit
{
    public const Action = 'products.update_details';

    public const Extension = 'yoro.early_access_product_edit';

    public const Fields = ['name', 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'sku', 'global_unique_id', 'manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount'];

    public static function manifest(): array
    {
        return ['version' => 1, 'action' => self::Action, 'fields' => self::Fields, 'max_targets' => 1,
            'requires_source_read' => true, 'requires_confirmation' => true, 'result_version' => 1];
    }

    public static function properties(): array
    {
        $price = ['type' => 'string', 'pattern' => '^(?:|(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?)$'];

        return ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'date_on_sale_from' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
            'date_on_sale_to' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],
            'regular_price' => $price, 'sale_price' => $price, 'sku' => ['type' => 'string', 'maxLength' => 100],
            'global_unique_id' => ['type' => 'string', 'maxLength' => 100], 'manage_stock' => ['type' => 'boolean'],
            'stock_quantity' => ['type' => ['integer', 'null'], 'minimum' => -1000000000, 'maximum' => 1000000000],
            'stock_status' => ['type' => 'string', 'enum' => ['instock', 'outofstock', 'onbackorder']],
            'backorders' => ['type' => 'string', 'enum' => ['no', 'notify', 'yes']],
            'low_stock_amount' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 1000000000]];
    }

    public static function changes($changes): array
    {
        if (! is_array($changes) || $changes === [] || array_is_list($changes) || array_diff(array_keys($changes), self::Fields) !== []) {
            self::invalid();
        }
        foreach ($changes as $field => $value) {
            $valid = false;
            if (in_array($field, ['name','sku','global_unique_id'], true)) {
                $valid = is_string($value) && mb_strlen($value) <= ($field === 'name' ? 255 : 100)
                    && ($field !== 'name' || $value !== '') && trim($value) === $value && preg_match('//u', $value) === 1
                    && preg_match('/[\x00-\x1f\x7f<>]|%[a-f0-9]{2}/i', $value) !== 1
                    && ($field !== 'global_unique_id' || preg_match('/\A[0-9-]*[0-9Xx]?\z/D', $value) === 1);
            } elseif (in_array($field, ['regular_price','sale_price'], true)) {
                $valid = is_string($value) && preg_match('/\A(?:|(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?)\z/D', $value) === 1;
            } elseif (in_array($field, ['date_on_sale_from','date_on_sale_to'], true)) { $valid = self::validDate($value); }
            elseif ($field === 'manage_stock') { $valid = is_bool($value); }
            elseif (in_array($field, ['stock_quantity','low_stock_amount'], true)) { $valid = $value === null || (is_int($value) && $value >= ($field === 'stock_quantity' ? -1000000000 : 0) && $value <= 1000000000); }
            elseif ($field === 'stock_status') { $valid = in_array($value, ['instock','outofstock','onbackorder'], true); }
            elseif ($field === 'backorders') { $valid = in_array($value, ['no','notify','yes'], true); }
            if (! $valid) {
                self::invalid();
            }
        }

        return array_replace(array_intersect_key(array_fill_keys(self::Fields, null), $changes), $changes);
    }

    public static function validDate($value): bool
    {
        if ($value === null) { return true; }
        if (!is_string($value) || !preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/D', $value, $parts)) { return false; }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    public static function normalizedPrice(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    public static function effective(array $changes, array $row): array
    {
        $meta = $row['extensions']['woocommerce.product'] ?? [];
        $before = $meta['edit_fields'] ?? null;
        if (! is_array($before) || array_diff(self::Fields, array_keys($before)) !== []) {
            self::invalid();
        }
        if (($row['type'] === 'variable' && array_intersect(array_keys($changes), ['regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to']) !== [])
            || ($row['type'] === 'variation' && array_key_exists('name', $changes))) {
            self::invalid();
        }
        if (array_diff(array_keys($changes), $meta['editable_fields'] ?? []) !== []) {
            self::invalid();
        }
        $after = array_replace($before, $changes);
        foreach (['regular_price', 'sale_price'] as $field) {
            $after[$field] = self::normalizedPrice($after[$field]);
            if (array_key_exists($field, $changes)) {
                $changes[$field] = $after[$field];
            }
        }
        if ($row['type'] !== 'variable' && $after['sale_price'] !== '') {
            $regular = explode('.', $after['regular_price']);
            $sale = explode('.', $after['sale_price']);
            if ($after['regular_price'] === '' || strcmp(str_pad($sale[0], 12, '0', STR_PAD_LEFT).str_pad($sale[1] ?? '', 6, '0'), str_pad($regular[0], 12, '0', STR_PAD_LEFT).str_pad($regular[1] ?? '', 6, '0')) >= 0) {
                self::invalid();
            }
        }
        if (array_intersect(array_keys($changes), ['date_on_sale_from', 'date_on_sale_to']) !== []
            && $after['date_on_sale_from'] !== null && $after['date_on_sale_to'] !== null
            && strcmp($after['date_on_sale_from'], $after['date_on_sale_to']) > 0) {
            self::invalid();
        }
        $stock = array_intersect(array_keys($changes), ['manage_stock', 'stock_quantity', 'stock_status', 'backorders', 'low_stock_amount']);
        if ($stock !== []) {
            if (($meta['stock_management_enabled'] ?? null) !== true
                || (($meta['stock_managed_by_parent'] ?? false) && $after['manage_stock'] !== true)) {
                self::invalid();
            }
            if ($after['manage_stock']) {
                if (! is_int($after['stock_quantity'])) {
                    self::invalid();
                }
                $threshold = $meta['no_stock_amount'] ?? 0;
                $derived = $after['stock_quantity'] > $threshold ? 'instock' : ($after['backorders'] === 'no' ? 'outofstock' : 'onbackorder');
                if (isset($changes['stock_status']) && $changes['stock_status'] !== $derived) {
                    self::invalid();
                }
                $changes['stock_status'] = $derived;
            } else {
                if ((array_key_exists('stock_quantity', $changes) && $changes['stock_quantity'] !== null)
                    || (isset($changes['backorders']) && $changes['backorders'] !== 'no')
                    || (array_key_exists('low_stock_amount', $changes) && $changes['low_stock_amount'] !== null)) {
                    self::invalid();
                }
                if (array_key_exists('manage_stock', $changes)) {
                    $changes = [...$changes, 'stock_quantity' => null, 'backorders' => 'no', 'low_stock_amount' => null];
                }
            }
        }
        if ($row['type'] === 'variable' && !$after['manage_stock'] && array_key_exists('stock_status',$changes)) { self::invalid(); }
        $changes = self::changes($changes);
        $different = false;
        foreach ($changes as $field => $value) { $different = $different || $value !== $before[$field]; }
        if (! $different) {
            self::invalid();
        }

        return $changes;
    }
    private static function invalid()
    {
        throw new InvalidArgumentException('Use supported product fields, valid prices and a consistent stock configuration.');
    }

    public static function facts(WC_Product $product): array
    {
        $fields = [];
        foreach (self::Fields as $field) {
            $getter = 'get_' . $field;
            $value = method_exists($product, $getter) ? $product->$getter('edit') : '';
            $fields[$field] = in_array($field, ['date_on_sale_from','date_on_sale_to'], true)
                ? ($value ? wp_date('Y-m-d', $value->getTimestamp(), wp_timezone()) : null) : $value;
        }
        foreach (['regular_price','sale_price'] as $field) { $fields[$field] = self::normalizedPrice((string) $fields[$field]); }
        foreach (['stock_quantity','low_stock_amount'] as $field) { $fields[$field] = '' === $fields[$field] || null === $fields[$field] ? null : (int) $fields[$field]; }
        $fields['manage_stock'] = true === $fields['manage_stock'];
        $editable = self::Fields;
        if ('variable' === $product->get_type()) { $editable = array_diff($editable, ['regular_price','sale_price','date_on_sale_from','date_on_sale_to']); }
        if ('variation' === $product->get_type()) { $editable = array_diff($editable, ['name']); }
        if (!method_exists($product, 'set_global_unique_id')) { $editable = array_diff($editable, ['global_unique_id']); }
        $enabled = 'yes' === get_option('woocommerce_manage_stock');
        if (!$enabled) { $editable = array_diff($editable, ['manage_stock','stock_quantity','stock_status','backorders','low_stock_amount']); }
        return ['sale_timezone' => wp_timezone_string(), 'edit_fields' => $fields, 'editable_fields' => array_values($editable),
            'stock_management_enabled' => $enabled, 'stock_managed_by_parent' => 'parent' === $product->get_manage_stock(),
            'no_stock_amount' => max(0, (int) get_option('woocommerce_notify_no_stock_amount', 0))];
    }

    public static function prepare(array $params)
    {
        try {
            if (array_diff(array_keys($params), ['product_id','changes','expected_version','_yoro_confirmed','_yoro_preview'])
                || !is_int($params['product_id'] ?? null) || $params['product_id'] < 1
                || !is_string($params['expected_version'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $params['expected_version'])
                || !current_user_can('yoro_execute_actions') || !current_user_can('edit_post', $params['product_id'])) { self::invalid(); }
            $product = wc_get_product($params['product_id']);
            if (!$product || 'publish' !== $product->get_status() || !in_array($product->get_type(), ['simple','variable','variation'], true)) { self::invalid(); }
            if ('variation' === $product->get_type()) {
                $parent = wc_get_product($product->get_parent_id());
                if (!$parent || 'publish' !== $parent->get_status()) { self::invalid(); }
            }
            if (!hash_equals(YoRo_Commerce_Discovery_Service::product_version($product), $params['expected_version'])) {
                return new WP_Error('product_version_conflict', 'The product changed after its preview. Read it again before preparing another change.');
            }
            $facts = self::facts($product);
            $changes = self::effective(self::changes($params['changes'] ?? null), ['type'=>$product->get_type(),'extensions'=>['woocommerce.product'=>$facts]]);
            self::apply_changes($product, $changes);
            return ['product'=>$product,'changes'=>$changes,'before'=>$facts['edit_fields']];
        } catch (Throwable $error) {
            return new WP_Error('product_edit_invalid', 'The product change is invalid or an identifier is already in use. Check the fields and read the product again.');
        }
    }

    /** Validate setters on an in-memory product before its first save. */
    public static function apply_changes(WC_Product $product, array $changes): void
    {
            foreach ($changes as $field=>$value) {
                $setter = 'set_'.$field;
                if (in_array($field, ['date_on_sale_from','date_on_sale_to'], true)) {
                    if ($value !== null) {
                        $date = new DateTimeImmutable($value . ('date_on_sale_from' === $field ? ' 00:00:00' : ' 23:59:59'), wp_timezone());
                        if ($date->format('Y-m-d') !== $value) { self::invalid(); }
                        $value = $date->getTimestamp();
                    }
                    $product->$setter($value);
                } else { $product->$setter(null === $value ? '' : $value); }
            }
            // Compare exact timestamps too, including a retained opposite boundary.
            if (array_intersect(array_keys($changes), ['date_on_sale_from','date_on_sale_to']) !== []
                && $product->get_date_on_sale_from('edit') && $product->get_date_on_sale_to('edit')
                && $product->get_date_on_sale_from('edit')->getTimestamp() > $product->get_date_on_sale_to('edit')->getTimestamp()) { self::invalid(); }
            $product->validate_props();
    }

    public static function execute($params)
    {
        $prepared = self::prepare((array) $params);
        if (is_wp_error($prepared)) { return $prepared; }
        $product = $prepared['product'];
        try { $product->save(); }
        catch (Throwable $error) { return new WP_Error('local_execution_outcome_unknown', 'The save outcome is unknown. Read the product again; do not retry this change.'); }
        clean_post_cache($product->get_id());
        $current = wc_get_product($product->get_id());
        if (!$current) { return new WP_Error('local_execution_outcome_unknown','Read the product again; do not retry this change.'); }
        $actual = self::facts($current)['edit_fields'];
        foreach ($prepared['changes'] as $field=>$value) {
            if (($actual[$field] ?? null) !== $value) { return new WP_Error('local_execution_outcome_unknown','The saved fields could not be verified. Read the product again; do not retry this change.'); }
        }
        return ['success'=>true,'product_id'=>$product->get_id(),'changes'=>array_intersect_key($actual,$prepared['changes']),
            'current_version'=>YoRo_Commerce_Discovery_Service::product_version($current),'message'=>'Product updated.',
            'read_only'=>false,'undoable'=>false,'data'=>['product_id'=>$product->get_id(),'changes'=>array_intersect_key($actual,$prepared['changes']),
                'current_version'=>YoRo_Commerce_Discovery_Service::product_version($current)]];
    }

    public static function preview($params)
    {
        $prepared = self::prepare((array) $params);
        if (is_wp_error($prepared)) { return $prepared; }
        return ['success'=>true,'read_only'=>true,'undoable'=>false,'message'=>'Review this product change before confirming.',
            'product_id'=>$prepared['product']->get_id(),'before'=>array_intersect_key($prepared['before'],$prepared['changes']),
            'after'=>$prepared['changes']];
    }
}
