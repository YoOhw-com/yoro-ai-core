<?php
/** Disposable WordPress/WooCommerce only: catalog content, classification and existing media. */
if (!defined('ABSPATH')) { exit(1); }
if (!class_exists('YoRo_Product_Catalog_Options')) { YoRo_AI_Core::init(); }
if (!class_exists('WooCommerce')) { echo "Catalog content unavailable without WooCommerce.\n"; return; }
if (str_ends_with((string) wp_parse_url(home_url(), PHP_URL_HOST), 'yorohq.com')) { throw new RuntimeException('Never run write fixtures on canary or production.'); }
$assert = static function($ok, $why) { if (!$ok) { throw new RuntimeException($why); } };
$original = get_current_user_id();
$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
add_filter('pre_wp_mail', '__return_true');
global $wpdb; $wpdb->query('START TRANSACTION');
try {
    wp_set_current_user((int) $admins[0]);
    YoRo_WordPress_Actor::record_access(['assigned' => true, 'status' => 'active', 'role' => 'store_admin', 'permissions' => ['yoro_view_store_data','yoro_execute_actions']]);
    $key = 'YR0178-catalog-' . wp_generate_uuid4();
    $product = new WC_Product_Simple(); $product->set_name($key); $product->set_status('publish'); $product->save();
    $id = $product->get_id();
    $patch = static function($id, $changes) {
        $version = YoRo_Commerce_Discovery_Service::product_version(wc_get_product($id));
        return ['action' => 'products.update_details', 'params' => ['product_id' => $id, 'changes' => $changes, 'expected_version' => $version],
            'workflow_id' => wp_generate_uuid4(), 'operation_id' => 'operation_1', 'authorization' => ['execution_permit' => 'disposable'],
            'extensions' => ['yoro.mcp_early_access_commerce_write' => ['source_operation_id' => 'envelope_' . str_repeat('a',64),
                'source_result_hash' => str_repeat('b',64), 'expected_version' => $version]]];
    };
    $read = static fn($params) => YoRo_Early_Access_Product_Read::execute(['action' => 'products.get_catalog_options', 'params' => $params,
        'workflow_id' => wp_generate_uuid4(), 'operation_id' => 'operation_1']);
    $categories = [];
    for ($n = 0; $n < 22; $n++) { $term = wp_insert_term($key . '-category-' . $n, 'product_cat'); $assert(!is_wp_error($term), 'Category fixtures exist.'); $categories[] = (int) $term['term_id']; }
    $tag = wp_insert_term($key . '-tag', 'product_tag'); $tag = (int) $tag['term_id'];
    $image = wp_insert_attachment(['post_title' => $key . '-image', 'post_mime_type' => 'image/png', 'post_status' => 'inherit']);
    $other_image = wp_insert_attachment(['post_title' => $key . '-gallery', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit']);
    update_post_meta($image, '_wp_attached_file', $key . '-image.png');
    update_post_meta($other_image, '_wp_attached_file', $key . '-gallery.jpg');
    $pdf = wp_insert_attachment(['post_title' => $key . '-pdf', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit']);
    $trashed = wp_insert_attachment(['post_title' => $key . '-trashed', 'post_mime_type' => 'image/png', 'post_status' => 'trash']);
    update_post_meta($trashed, '_wp_attached_file', $key . '-trashed.png');
    $changes = ['description' => "Plain product content\nSecond line", 'short_description' => 'Short content',
        'category_ids' => [$categories[1],$categories[0]], 'tag_ids' => [$tag], 'image_id' => $image, 'gallery_image_ids' => [$other_image,$image]];
    $operation = $patch($id,$changes); $preview = YoRo_Product_Edit::preview($operation['params']);
    $assert(!is_wp_error($preview) && $preview['after']['category_ids'] === [$categories[0],$categories[1]], 'Preview canonicalizes taxonomy sets, retains gallery order: '.wp_json_encode($preview));
    $assert(wc_get_product($id)->get_description() === '', 'Preview never saves content.');
    $result = YoRo_Early_Access_Commerce_Write::execute($operation);
    $assert(!is_wp_error($result), 'One confirmed content/classification/media patch succeeds: '.(is_wp_error($result)?$result->get_error_message():''));
    $saved = wc_get_product($id);
    $assert($saved->get_description('edit') === $changes['description'] && $saved->get_short_description('edit') === 'Short content'
        && $saved->get_category_ids() === [$categories[0],$categories[1]] && $saved->get_tag_ids() === [$tag]
        && (int) $saved->get_image_id() === $image && $saved->get_gallery_image_ids() === [$other_image,$image], 'Every confirmed field matches persisted WooCommerce CRUD values.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($operation)), 'The same content patch cannot replay with an old product version.');
    $facts = YoRo_Early_Access_Product_Read::product($saved,true)['extensions']['woocommerce.product'];
    $assert($facts['categories'][0]['id'] === $categories[0] && $facts['tags'][0]['id'] === $tag && $facts['description'] === $changes['description'], 'Details include bounded classification labels and separate literal source content.');
    $invalids = [['description' => '<script>alert(1)</script>'], ['description' => str_repeat('é',2001)], ['short_description' => str_repeat('é',501)],
        ['description' => "bad\0text"], ['category_ids' => [$tag]], ['tag_ids' => [$categories[0]]], ['category_ids' => [$categories[0],$categories[0]]],
        ['category_ids' => array_slice($categories,0,21)], ['tag_ids' => ['1']], ['image_id' => $pdf], ['image_id' => $trashed],
        ['image_id' => 'https://example.test/image.png'], ['gallery_image_ids' => [$image,$image]], ['gallery_image_ids' => range(1,11)]];
    foreach ($invalids as $invalid) {
        $bad = $patch($id,['name' => 'Must not persist', ...$invalid]);
        $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($bad)) && wc_get_product($id)->get_name() === $key, 'Invalid content/IDs reject atomically before any save.');
    }
    $denied = static function($caps,$cap,$user,$args) use ($image) {
        return ($cap === 'read_post' && ($args[0] ?? null) === $image) || $cap === 'assign_term' ? ['do_not_allow'] : $caps;
    };
    add_filter('map_meta_cap',$denied,10,4);
    $assert(is_wp_error(YoRo_Product_Edit::preview($patch($id,['image_id' => $other_image, 'gallery_image_ids' => [$image]])['params'])), 'An inaccessible gallery image denies the whole preview.');
    $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($patch($id,['category_ids' => [$categories[2]]]))), 'Per-term assignment permissions are enforced at execution.');
    $images = $read(['kind' => 'images', 'query' => $key]);
    $assert(!is_wp_error($images) && !in_array($image,array_column($images['data']['items'],'id'),true)
        && !in_array($pdf,array_column($images['data']['items'],'id'),true) && !in_array($trashed,array_column($images['data']['items'],'id'),true), 'Image discovery excludes hidden, nonimage and trashed attachments.');
    remove_filter('map_meta_cap',$denied,10);
    $first = $read(['kind' => 'categories','query' => $key]); $second = $read(['kind' => 'categories','query' => $key,'page' => 2]);
    $assert(!is_wp_error($first) && $first['data']['count'] === 20 && $first['data']['page_info'] === ['page'=>1,'per_page'=>20,'has_next_page'=>true,'next_page'=>2]
        && $second['data']['count'] === 2 && !$second['data']['page_info']['has_next_page'], 'Selectors page existing term IDs at twenty with explicit next-page metadata.');
    foreach ([['kind'=>'other'],['kind'=>'images','page'=>0],['kind'=>'images','page'=>1001],['kind'=>'images','query'=>str_repeat('é',51)],['kind'=>'images','url'=>'https://example.test']] as $bad) {
        $assert(is_wp_error($read($bad)), 'Unknown or unbounded catalog selectors fail closed.');
    }
    $saved->set_description('<p>Existing HTML remains literal.</p>'); $saved->set_short_description('<b>Existing summary</b>'); $saved->save();
    $details = YoRo_Early_Access_Product_Read::product(wc_get_product($id),true)['extensions']['woocommerce.product'];
    $assert($details['edit_fields']['description'] === '<p>Existing HTML remains literal.</p>', 'Existing HTML source is carried literally for an escaping renderer.');
    $rename = $patch($id,['name' => $key.'-renamed']);
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($rename)) && wc_get_product($id)->get_description('edit') === '<p>Existing HTML remains literal.</p>', 'Omitted HTML fields survive unrelated edits exactly.');
    $saved = wc_get_product($id); $saved->set_description(str_repeat('é',2000).'tail-a'); $saved->set_category_ids($categories); $saved->save();
    $saved = wc_get_product($id); $version = YoRo_Commerce_Discovery_Service::product_version($saved); $truncated = YoRo_Product_Edit::facts($saved);
    $assert(strlen($truncated['edit_fields']['description']) === 4000 && $truncated['content_truncated'] === ['description','category_ids']
        && !in_array('description',$truncated['editable_fields'],true) && !in_array('category_ids',$truncated['editable_fields'],true), 'Oversized source fields are bounded and explicitly noneditable.');
    $saved->set_description(str_repeat('é',2000).'tail-b');
    $assert($version !== YoRo_Commerce_Discovery_Service::product_version($saved), 'Changes after the truncated prefix still change the optimistic product hash.');
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($patch($id,['short_description'=>'Other content']))), 'A bounded unrelated field remains editable with oversized source content.');
    $parent = new WC_Product_Variable(); $parent->set_name($key.'-parent'); $parent->set_status('publish'); $parent->save();
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($patch($parent->get_id(),['description'=>'Parent content','category_ids'=>[$categories[0]],'image_id'=>$image]))), 'Variable parents support the new catalog content fields.');
    $variation = new WC_Product_Variation(); $variation->set_parent_id($parent->get_id()); $variation->set_status('publish'); $variation->save();
    $assert(!is_wp_error(YoRo_Early_Access_Commerce_Write::execute($patch($variation->get_id(),['description'=>'Variation content','image_id'=>$other_image]))), 'Variations support their own description and image.');
    foreach (['short_description'=>'No', 'category_ids'=>[$categories[0]], 'tag_ids'=>[$tag], 'gallery_image_ids'=>[$image]] as $field=>$value) {
        $assert(is_wp_error(YoRo_Early_Access_Commerce_Write::execute($patch($variation->get_id(),[$field=>$value]))), 'Variation patches reject parent-owned classification/gallery/short description.');
    }
    $clear = YoRo_Early_Access_Commerce_Write::execute($patch($parent->get_id(),['description'=>'','short_description'=>'','tag_ids'=>[],'image_id'=>0,'gallery_image_ids'=>[]]));
    $assert(!is_wp_error($clear) && (int) wc_get_product($parent->get_id())->get_image_id() === 0 && wc_get_product($parent->get_id())->get_description() === '', 'Explicit empty content/media selections clear only those fields.');
    $manifest = YoRo_Commerce_Contract_Builder::build_capability_manifest(['connector_connection_id'=>'catalog-fixture','execution_mode'=>'remote_agent','protocol_version'=>1],1);
    $assert(!is_wp_error($manifest), 'The manifest builds.');
    $assert(($manifest['extensions']['yoro.early_access_catalog_options'] ?? null) === YoRo_Product_Catalog_Options::manifest(), 'Selector capability is explicitly negotiated.');
    $actions = array_column($manifest['actions'],null,'name'); $selector = $actions['products.get_catalog_options'] ?? [];
    $assert(($selector['extensions']['yoro.contracts']['strict_output_validation_ready'] ?? false) === true
        && ($selector['input_schema']['properties']['page']['maximum'] ?? null) === 1000
        && ($selector['output_schema']['properties']['data']['additionalProperties'] ?? null) === false, 'Selector input/output contract is bounded and closed.');
    wp_set_current_user(0); $assert(is_wp_error($read(['kind'=>'categories'])), 'Anonymous actors cannot discover catalog IDs.');
    echo "Product catalog content contract passed: content/IDs/media, preview/version, permissions, pagination and truncation.\n";
} finally {
    $wpdb->query('ROLLBACK'); wp_cache_flush(); wp_set_current_user($original); remove_filter('pre_wp_mail','__return_true');
}
