<?php

if (!defined('ABSPATH')) { exit; }

/** Read only existing catalog identifiers; never creates terms or imports media. */
final class YoRo_Product_Catalog_Options
{
    public const Action = 'products.get_catalog_options';

    public static function manifest(): array
    {
        return ['version' => 1, 'action' => self::Action, 'max_items' => 20, 'result_version' => 1];
    }

    public static function properties(): array
    {
        return ['kind' => ['type' => 'string', 'enum' => ['categories', 'tags', 'images']],
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
            'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000]];
    }

    public static function valid_params($params): bool
    {
        return is_array($params) && !array_diff(array_keys($params), ['kind', 'query', 'page'])
            && in_array($params['kind'] ?? null, ['categories', 'tags', 'images'], true)
            && (!array_key_exists('page', $params) || (is_int($params['page']) && $params['page'] >= 1 && $params['page'] <= 1000))
            && (!array_key_exists('query', $params) || (is_string($params['query']) && trim($params['query']) !== ''
                && strlen($params['query']) <= 100 && preg_match('//u', $params['query']) === 1
                && preg_match('/[\x00-\x1f\x7f<>]/', $params['query']) !== 1));
    }

    public static function image_visible(int $id): bool
    {
        $post = get_post($id);
        return $post instanceof WP_Post && $post->post_type === 'attachment'
            && in_array($post->post_status, ['inherit', 'publish', 'private'], true) && wp_attachment_is_image($id)
            && current_user_can('read_post', $id) && current_user_can('edit_post', $id)
            && (!(int) $post->post_parent || current_user_can('read_post', (int) $post->post_parent));
    }

    public static function terms(array $ids, string $taxonomy): array
    {
        $rows = [];
        foreach (array_slice($ids, 0, 20) as $id) {
            $term = get_term($id, $taxonomy);
            if ($term instanceof WP_Term) { $rows[] = ['id' => (int) $id, 'name' => self::label($term->name, ($taxonomy === 'product_cat' ? 'Category #' : 'Tag #') . (int) $id)]; }
        }
        return $rows;
    }

    private static function label(string $value, string $fallback): string
    {
        $label = mb_strcut(sanitize_text_field($value), 0, 200, 'UTF-8');
        return preg_match('/[^\s\p{Z}]/u', $label) === 1 ? $label : $fallback;
    }

    public static function execute($params)
    {
        // Executor confirmation flags are internal and never selector fields.
        $params = array_diff_key((array) $params, array_flip(['_yoro_confirmed', '_yoro_preview']));
        if (!self::valid_params($params) || !current_user_can('manage_woocommerce')) {
            return new WP_Error('catalog_options_invalid', 'The catalog selectors or permissions are unavailable.');
        }
        $page = $params['page'] ?? 1;
        $kind = $params['kind'];
        $items = [];
        $has_more = false;
        if ($kind === 'images') {
            if (!current_user_can('upload_files')) { return new WP_Error('catalog_options_denied', 'Media access is unavailable.'); }
            $args = ['post_type' => 'attachment', 'post_status' => ['inherit', 'publish', 'private'], 'post_mime_type' => 'image',
                'posts_per_page' => 20, 'paged' => $page, 'orderby' => 'ID', 'order' => 'ASC', 's' => $params['query'] ?? ''];
            // Match the media-library ownership boundary before pagination as well as per item.
            if (!current_user_can('edit_others_posts')) { $args['author'] = get_current_user_id(); }
            $query = new WP_Query($args);
            foreach ($query->posts as $post) {
                if (self::image_visible((int) $post->ID)) { $items[] = ['id' => (int) $post->ID, 'name' => self::label($post->post_title, 'Image #' . (int) $post->ID)]; }
            }
            $has_more = $page < (int) $query->max_num_pages;
        } else {
            $taxonomy = $kind === 'categories' ? 'product_cat' : 'product_tag';
            $definition = get_taxonomy($taxonomy);
            if (!$definition || !current_user_can($definition->cap->assign_terms)) { return new WP_Error('catalog_options_denied', 'Classification access is unavailable.'); }
            $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 21, 'offset' => ($page - 1) * 20,
                'orderby' => 'term_id', 'order' => 'ASC', 'search' => $params['query'] ?? '']);
            if (is_wp_error($terms)) { return new WP_Error('catalog_options_unavailable', 'Catalog options could not be read.'); }
            $has_more = count($terms) > 20;
            foreach (array_slice($terms, 0, 20) as $term) {
                if ($term instanceof WP_Term && current_user_can('assign_term', (int) $term->term_id)) {
                    $items[] = ['id' => (int) $term->term_id, 'name' => self::label($term->name, ($kind === 'categories' ? 'Category #' : 'Tag #') . (int) $term->term_id)];
                }
            }
        }
        $data = ['kind' => $kind, 'items' => $items, 'count' => count($items), 'page_info' => ['page' => $page,
            'per_page' => 20, 'has_next_page' => $has_more, 'next_page' => $has_more && $page < 1000 ? $page + 1 : null]];
        if ($page === 1000) { $data['page_info']['has_next_page'] = false; }
        return ['success' => true, 'action' => self::Action, 'message' => 'Catalog options read completed.',
            'data' => $data, 'read_only' => true, 'undoable' => false];
    }
}
