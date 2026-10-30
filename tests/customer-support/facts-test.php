<?php
/** Standalone public-fact boundary matrix; run separately with woo=off and woo=on. */
define('ABSPATH', __DIR__);
$woo = ($argv[1] ?? 'off') === 'on';
$GLOBALS['posts'] = [];
$GLOBALS['products'] = [];
$GLOBALS['queries'] = [];
function absint($value) { return abs((int) $value); }
function home_url() { return 'https://public-store.test'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function esc_url_raw($url) { return $url; }
function wp_json_encode($value) { return json_encode($value); }
function get_post($id) { return $GLOBALS['posts'][is_object($id) ? $id->ID : $id] ?? null; }
function get_permalink($post) { return $post->url ?? 'https://public-store.test/content/'.$post->ID; }
function is_post_publicly_viewable($post) { return $post->public ?? true; }
function get_posts($args) { $GLOBALS['queries'][] = $args; return array_values(array_filter($GLOBALS['posts'], fn ($post) => in_array($post->ID, $args['post__in'], true))); }
function strip_shortcodes($text) { return preg_replace('/\[[^\]]*\]/', '', $text); }
function wp_strip_all_tags($text) { return strip_tags($text); }
function do_shortcode($text) { throw new RuntimeException('Shortcodes must never run.'); }
function is_wp_error($value) { return false; }
if ($woo) {
    function wc_get_product($id) { return $GLOBALS['products'][$id] ?? null; }
    function wc_get_products($args) { $GLOBALS['queries'][] = $args; if (isset($args['parent'])) { return array_keys(array_filter($GLOBALS['products'],fn($p)=>$p->get_type()==='variation'&&$p->get_parent_id()===$args['parent'])); } return array_values(array_filter($GLOBALS['products'], fn ($product) => $product->get_type() !== 'variation')); }
    function get_woocommerce_currency() { return 'VND'; }
    function wc_attribute_label($name) { return $name; }
    function wc_get_product_terms($id, $name, $args) { return ['Public term']; }
}
class SyntheticProduct {
    public function __construct(public int $id, public array $fields = []) {}
    public function get_id() { return $this->id; }
    public function get_type() { return $this->fields['type'] ?? 'simple'; }
    public function get_status() { return $this->fields['status'] ?? 'publish'; }
    public function get_catalog_visibility() { return $this->fields['visibility'] ?? 'visible'; }
    public function is_visible() { return $this->fields['visible'] ?? true; }
    public function is_purchasable() { return $this->fields['purchasable'] ?? true; }
    public function get_price() { return $this->fields['price'] ?? '100000'; }
    public function get_price_html() { return $this->fields['price_html'] ?? '<span>100.000 ₫</span>'; }
    public function get_parent_id() { return $this->fields['parent_id'] ?? 0; }
    public function get_children() { return $this->fields['children'] ?? []; }
    public function variation_is_visible() { return $this->fields['variation_visible'] ?? true; }
    public function variation_is_active() { return $this->fields['variation_active'] ?? true; }
    public function get_permalink() { return $this->fields['url'] ?? 'https://public-store.test/product/'.$this->id; }
    public function get_name() { return $this->fields['name'] ?? 'Public product'; }
    public function get_short_description() { return $this->fields['description'] ?? 'Public description'; }
    public function get_attributes() { return $this->fields['attributes'] ?? ['Size' => 'Small']; }
    public function get_stock_status() { return $this->fields['stock'] ?? 'instock'; }
}
require dirname(__DIR__, 2).'/includes/customer-support/class-yoro-support-facts.php';
$count = 0;
function check($condition, $why) { global $count; ++$count; if (!$condition) { throw new RuntimeException($why); } }
function post_fixture(int $id, array $fields = []) { return (object) ($fields + ['ID' => $id, 'post_status' => 'publish', 'post_type' => 'page', 'post_password' => '', 'post_title' => 'Public FAQ', 'post_content' => 'Three-day delivery [private_shortcode] text.', 'public' => true]); }
$settings = ['content_ids' => [11]];
$GLOBALS['posts'][11] = post_fixture(11);
$first = YoRo_Support_Facts::content(11, $settings);
check($first['id'] === 'content:11' && $first['url'] === 'https://public-store.test/content/11', 'Public FAQ has actual permalink.');
check(!str_contains($first['text'], '['), 'No shortcode execution or shortcode payload.');
foreach ([['post_status'=>'draft'], ['post_status'=>'private'], ['post_status'=>'trash'], ['post_password'=>'secret'], ['post_type'=>'attachment'], ['public'=>false], ['url'=>'http://public-store.test/faq'], ['url'=>'https://other.test/faq'], ['url'=>'https://user:secret@public-store.test/faq']] as $mutation) {
    $GLOBALS['posts'][11] = post_fixture(11, $mutation);
    check(YoRo_Support_Facts::content(11, $settings) === null, 'Unpublished/protected/cross-site source denied.');
}
$GLOBALS['posts'][11] = post_fixture(11, ['post_content' => 'New current policy']);
$updated = YoRo_Support_Facts::content(11, $settings);
check($updated['version'] !== $first['version'], 'Current source changes alter version.');
check(YoRo_Support_Facts::content(11, ['content_ids'=>[]]) === null, 'Source selection revocation.');
unset($GLOBALS['posts'][11]); check(YoRo_Support_Facts::content(11, $settings) === null, 'Deletion revokes source.');
$GLOBALS['posts'][11] = post_fixture(11, ['post_title'=>" \u{00A0}Public FAQ\u{00A0} ", 'post_content'=>"&nbsp;  Public fact. &nbsp;"]);
$trimmed = YoRo_Support_Facts::content(11, $settings);
check($trimmed['title']==='Public FAQ'&&$trimmed['text']==='Public fact.','Public text is canonical across HTTP whitespace normalization including NBSP.');
$GLOBALS['posts'][11] = post_fixture(11, ['post_content'=>'']);
check(YoRo_Support_Facts::content(11,$settings)['text']==='','Empty public excerpts remain an explicit empty string.');
$GLOBALS['posts'][11] = post_fixture(11);
$snapshot = YoRo_Support_Facts::snapshot($settings, 'delivery');
check($snapshot['commerce'] === $woo, 'Woo capability reflects actual presence.');
check($GLOBALS['queries'][0]['numberposts'] === 100 && $GLOBALS['queries'][0]['suppress_filters'] === true, 'Bounded unfiltered lookup.');
check(!str_contains(json_encode($snapshot), 'customer_email'), 'No customer context.');
if ($woo) {
    $GLOBALS['posts'][20] = post_fixture(20, ['post_type'=>'product']);
    $GLOBALS['products'][20] = new SyntheticProduct(20, ['secret'=>'INTERNAL_CUSTOMER_SECRET']);
    $product = YoRo_Support_Facts::product(20);
    check($product['product']['price'] === '100.000 ₫' && $product['product']['currency'] === 'VND', 'Public storefront formatted price/currency.');
    check(!str_contains(json_encode($product), 'SECRET'), 'Private fields excluded before model.');
    foreach ([['status'=>'draft'], ['visibility'=>'hidden'], ['visible'=>false], ['purchasable'=>false], ['price'=>''], ['type'=>'grouped'], ['url'=>'https://other.test/p']] as $mutation) {
        $GLOBALS['products'][20] = new SyntheticProduct(20, $mutation);
        check(YoRo_Support_Facts::product(20) === null, 'Invalid/hidden/unpurchasable product omitted.');
    }
    $GLOBALS['products'][20] = new SyntheticProduct(20, ['type'=>'variable','children'=>[21]]);
    $GLOBALS['posts'][21] = post_fixture(21, ['post_type'=>'product_variation']);
    $GLOBALS['products'][21] = new SyntheticProduct(21, ['type'=>'variation','parent_id'=>20,'attributes'=>['Size'=>'Small']]);
    check(YoRo_Support_Facts::product(21)['product']['parent_id'] === 20, 'Valid public variation binds parent.');
    foreach ([['variation_visible'=>false], ['variation_active'=>false], ['parent_id'=>999], ['purchasable'=>false]] as $mutation) {
        $GLOBALS['products'][21] = new SyntheticProduct(21, $mutation + ['type'=>'variation','parent_id'=>20]);
        check(YoRo_Support_Facts::product(21) === null, 'Hidden/inactive/orphan variation denied.');
    }
    $GLOBALS['products'][21] = new SyntheticProduct(21, ['type'=>'variation','parent_id'=>20]);
    $GLOBALS['products'][20]->fields['visibility']='hidden';
    check(YoRo_Support_Facts::product(21) === null, 'Hidden variation parent denied.');
    $GLOBALS['products'][20]->fields['visibility']='visible';
    $GLOBALS['posts'][20]->post_password='password';
    check(YoRo_Support_Facts::product(20) === null && YoRo_Support_Facts::product(21) === null, 'Password protects parent and children.');
}
echo json_encode(['result'=>'passed','woo'=>$woo,'assertions'=>$count], JSON_THROW_ON_ERROR), "\n";
