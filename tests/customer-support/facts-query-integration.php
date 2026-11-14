<?php
/** Real WP_Query and WooCommerce CRUD/query regression; only the fresh runner fixture is accepted. */
$root = realpath( $argv[1] ?? '' );
if ( ! $root || is_link( $argv[1] ) || ! is_file( $root . '/.support-disposable' )
	|| 'YR-0214-disposable-wordpress' !== file_get_contents( $root . '/.support-disposable' ) ) {
	throw new RuntimeException( 'A fresh disposable WordPress fixture is required.' );
}
function wp_mail( ...$args ) { return true; }
require $root . '/wp-load.php';
if ( 'yoro_support_disposable' !== DB_NAME || 'https://support-fixture.invalid' !== home_url() || ! DISABLE_WP_CRON ) {
	throw new RuntimeException( 'Refusing a non-disposable database or site.' );
}
WC_Install::install();
require dirname( __DIR__, 2 ) . '/includes/customer-support/class-yoro-support-facts.php';
$assertions = 0;
function verify( $condition, $message ) {
	global $assertions;
	++$assertions;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function page( $title, $body, $fields = [] ) {
	$id = wp_insert_post( $fields + [ 'post_title' => $title, 'post_content' => $body, 'post_status' => 'publish', 'post_type' => 'page' ], true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	return $id;
}
function product( $name, $fields = [] ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( $fields['status'] ?? 'publish' );
	$product->set_regular_price( $fields['regular'] ?? '90' );
	$product->set_sale_price( $fields['sale'] ?? '' );
	$product->set_stock_status( $fields['stock'] ?? 'instock' );
	$product->set_catalog_visibility( $fields['visibility'] ?? 'visible' );
	$product->set_short_description( $fields['description'] ?? 'Public storefront description.' );
	$product->save();
	return $product;
}
function ids( $snapshot ) { return array_column( $snapshot['sources'], 'id' ); }

$selected = [];
for ( $i = 0; $i < 48; ++$i ) { $selected[] = page( 'Reference ' . $i, 'A public reference document.' ); }
$toc = page( 'Testing 2', 'Table of Contents. A good table of contents has a clear heading, well-defined sections with bullets or numbering, scannable descriptive titles, consistent wording and a hierarchy of sections and subsections.' );
$faq = page( 'Testing 3', 'Global Blacklist Pro provides network risk intelligence. Anti-bots protection is part of the separate Premium add-on; Premium is not included in the Global Blacklist Pro subscription. No extra CAPTCHA or verification fields are needed. [private_shortcode]' );
$selected = array_merge( $selected, [ $toc, $faq ] );
$settings = [ 'content_ids' => $selected ];
$unselected = page( 'Confidential unique marker', 'UNSELECTED_MARKER' );
$queries = [
	'faq' => 'According to Testing 3, is Anti-bots protection included in the Global Blacklist Pro subscription, or is Premium a separate add-on? Please cite the source.',
	'toc' => 'Tóm tắt bài Testing 2 về mục lục và nêu các tiêu chí chính của một mục lục tốt.',
	'product' => 'Tôi cần sản phẩm giá dưới 70 USD. Dark Ground có phù hợp không, giá hiện tại và tình trạng hàng là gì?',
];
foreach ( [ 'faq', 'toc' ] as $kind ) {
	$raw = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'post__in' => $selected,
		's' => mb_substr( $queries[ $kind ], 0, 120 ), 'numberposts' => 100, 'suppress_filters' => true ] );
	verify( [] === $raw, 'Original natural sentence reproduces the actual WP AND-search failure: ' . $kind );
}
for ( $i = 0; $i < 110; ++$i ) { product( 'Unrelated fixture ' . $i ); }
$dark = product( 'Dark Ground', [ 'sale' => '62' ] );
$skyler = product( 'Skyler', [ 'regular' => '50', 'stock' => 'onbackorder' ] );
$hidden = product( 'Dark Ground hidden secret', [ 'visibility' => 'hidden' ] );
$draft = product( 'Dark Ground draft secret', [ 'status' => 'draft' ] );
add_filter( 'woocommerce_product_stock_status_options', static fn( $statuses ) => $statuses + [ 'onpreorder' => 'Synthetic custom preorder' ] );
$custom_stock = product( 'Global Blacklist Pro support source', [ 'stock' => 'onpreorder' ] );
verify( 'onpreorder' === wc_get_product( $custom_stock->get_id() )->get_stock_status(), 'Real Woo persistence retains a plugin-defined stock status for the regression.' );
verify( null === YoRo_Support_Facts::product( $custom_stock->get_id() ), 'Stock values outside the unchanged public-facts v1 enum are omitted, never relabeled.' );
$parent = new WC_Product_Variable();
$parent->set_name( 'Jupiter' );
$parent->set_status( 'publish' );
$attributes = [];
foreach ( [ 'Style' => [ 'Dust', 'Cloud' ], 'Color' => [ 'Black', 'White' ] ] as $name => $options ) {
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( $name ); $attribute->set_options( $options ); $attribute->set_visible( true ); $attribute->set_variation( true );
	$attributes[] = $attribute;
}
$parent->set_attributes( $attributes ); $parent->save();
$variations = [];
foreach ( [ [ 'dust', 'black', '19' ], [ 'cloud', 'white', '22' ] ] as $values ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() ); $variation->set_status( 'publish' );
	$variation->set_attributes( [ 'style' => $values[0], 'color' => $values[1] ] ); $variation->set_regular_price( $values[2] );
	$variation->save(); $variations[] = $variation->get_id();
}
WC_Product_Variable::sync( $parent->get_id() );
$raw = wc_get_products( [ 'status' => 'publish', 'visibility' => 'visible', 'limit' => 100, 's' => mb_substr( $queries['product'], 0, 120 ) ] );
verify( [] === $raw, 'Original natural sentence reproduces the real Woo AND-search failure.' );

foreach ( [ 'faq' => $faq, 'toc' => $toc ] as $kind => $id ) {
	$snapshot = YoRo_Support_Facts::snapshot( $settings, $queries[ $kind ] );
	verify( 'content:' . $id === $snapshot['sources'][0]['id'], 'Named content ranks first even after 48 older selected sources: ' . $kind );
	verify( ! in_array( 'content:' . $unselected, ids( $snapshot ), true ), 'Unselected sources never enter candidates.' );
	verify( ! in_array( 'product:' . $custom_stock->get_id(), ids( $snapshot ), true ), 'A plugin-defined stock value cannot poison an unrelated natural-question snapshot.' );
	verify( count( array_filter( $snapshot['sources'], static fn( $source ) => 'content' === $source['type'] ) ) <= 10, 'Content projection cap.' );
}
$snapshot = YoRo_Support_Facts::snapshot( $settings, $queries['product'] );
verify( 'product:' . $dark->get_id() === $snapshot['sources'][0]['id'], 'Named product outranks incidental prose/numeric terms and selected FAQ, beyond the first 100 catalog rows.' );
$fact = array_values( array_filter( $snapshot['sources'], static fn( $source ) => 'product:' . $dark->get_id() === $source['id'] ) )[0];
verify( '90' === wc_get_product( $dark->get_id() )->get_regular_price() && '62' === wc_get_product( $dark->get_id() )->get_price(), 'Native persistence retains the distinct regular and current sale prices.' );
verify( str_contains( $fact['product']['price'], '62' ) && ! str_contains( $fact['product']['price'], '90' ) && 'instock' === $fact['product']['stock'], 'The public DTO carries the current storefront sale price and stock without an unlabeled old/current pair.' );
verify( ! in_array( 'product:' . $hidden->get_id(), ids( $snapshot ), true ) && ! in_array( 'product:' . $draft->get_id(), ids( $snapshot ), true ), 'Hidden and draft products cannot become facts.' );
$comparison = YoRo_Support_Facts::snapshot( $settings, 'Compare Dark Ground and Skyler prices and current stock.' );
verify( in_array( 'product:' . $dark->get_id(), ids( $comparison ), true ) && in_array( 'product:' . $skyler->get_id(), ids( $comparison ), true ), 'OR retrieval finds distinct products without requiring both names in one record.' );
$punctuation = YoRo_Support_Facts::snapshot( $settings, 'DÁRK—GROUND?!' );
verify( 'product:' . $dark->get_id() === $punctuation['sources'][0]['id'], 'Unicode accent/case/punctuation normalization is generic.' );
$escaped = YoRo_Support_Facts::snapshot( $settings, "_%' OR 1=1 -- fictional-zebra" );
verify( '' === $wpdb->last_error && count( $escaped['sources'] ) <= 20, 'Visitor punctuation and SQL-like input cannot alter the prepared fixed query or caps.' );
$jupiter = YoRo_Support_Facts::snapshot( $settings, 'Jupiter Dust White' );
foreach ( [ $parent->get_id(), ...$variations ] as $id ) { verify( in_array( 'product:' . $id, ids( $jupiter ), true ), 'Only real public variant/parent facts are projected.' ); }
verify( 3 === count( array_filter( $jupiter['sources'], static fn( $source ) => 'product' === $source['type'] ) ), 'No invented Dust White variant is constructed from option combinations.' );

// A natural question also matches incidental catalog prose. Ten parent facts must
// not consume every result slot before a strongly matching parent's real variants.
for ( $i = 0; $i < 10; ++$i ) { product( 'Incidental catalog reference ' . $i, [ 'description' => 'Store size, price, stock and status reference.' ] ); }
$shirt = new WC_Product_Variable();
$shirt->set_name( 'YR0217 Field Shirt V1' ); $shirt->set_sku( 'YR0217-G056-SHIRT-V1' ); $shirt->set_status( 'publish' );
$size = new WC_Product_Attribute();
$size->set_name( 'Size' ); $size->set_options( [ 'S', 'L' ] ); $size->set_visible( true ); $size->set_variation( true );
$shirt->set_attributes( [ $size ] ); $shirt->save();
$shirt_variations = [];
foreach ( [ 's' => '30', 'l' => '40' ] as $value => $price ) {
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $shirt->get_id() ); $variation->set_status( 'publish' );
	$variation->set_attributes( [ 'size' => $value ] ); $variation->set_sku( 'YR0217-G056-SHIRT-V1-' . strtoupper( $value ) );
	$variation->set_regular_price( $price ); $variation->save(); $shirt_variations[ $value ] = $variation;
}
$large = $shirt_variations['l'];
$large->set_sale_price( '32' ); $large->set_manage_stock( true ); $large->set_backorders( 'yes' );
$large->set_stock_quantity( 0 ); $large->set_stock_status( 'onbackorder' ); $large->save();
WC_Product_Variable::sync( $shirt->get_id() );
$large_fact = YoRo_Support_Facts::product( $large->get_id() );
verify( str_contains( $large_fact['product']['price'], '32' ) && 'onbackorder' === $large_fact['product']['stock'], 'Native sale/backorder variation facts exist before retrieval.' );
$variation_query = 'What are the current price and stock status of YR0217 Field Shirt V1 in size L, SKU YR0217-G056-SHIRT-V1-L?';
$variation_snapshot = YoRo_Support_Facts::snapshot( $settings, $variation_query );
verify( in_array( 'product:' . $large->get_id(), ids( $variation_snapshot ), true ), 'Relevant current variation survives ten matching parent products in a natural question.' );
verify( count( array_filter( $variation_snapshot['sources'], static fn( $source ) => 'product' === $source['type'] ) ) <= 10, 'Variation expansion preserves the public product projection cap.' );

$changed = YoRo_Support_Facts::content( $faq, $settings );
wp_update_post( [ 'ID' => $faq, 'post_content' => 'Updated public FAQ' ] );
verify( $changed['version'] !== YoRo_Support_Facts::content( $faq, $settings )['version'], 'Real updated source changes its immutable projection version.' );
foreach ( [ [ 'post_status' => 'draft' ], [ 'post_status' => 'private' ], [ 'post_password' => 'test-only' ] ] as $change ) {
	wp_update_post( array_merge( [ 'ID' => $faq, 'post_status' => 'publish', 'post_password' => '' ], $change ) );
	verify( null === YoRo_Support_Facts::content( $faq, $settings ), 'Real content status/password revocation is enforced.' );
}
wp_update_post( [ 'ID' => $faq, 'post_status' => 'publish', 'post_password' => '' ] );
verify( null === YoRo_Support_Facts::content( $faq, [ 'content_ids' => [] ] ), 'Real source selection revocation is enforced.' );
$parent->set_catalog_visibility( 'hidden' ); $parent->save();
verify( null === YoRo_Support_Facts::product( $variations[0] ), 'Real hidden parent revokes a variation.' );
$parent->set_catalog_visibility( 'visible' ); $parent->save();
wp_update_post( [ 'ID' => $parent->get_id(), 'post_password' => 'protected' ] );
verify( null === YoRo_Support_Facts::product( $variations[0] ), 'Real protected parent revokes a variation.' );

global $wpdb, $wp_version;
$candidate_queries = array_values( array_filter( $wpdb->queries, static fn( $entry ) => str_starts_with( $entry[0], 'SELECT ID, (' ) && str_contains( $entry[0], 'AS support_rank' ) ) );
verify( count( $candidate_queries ) >= 4, 'Tests exercised actual prepared candidate SQL, not query-ignoring stubs.' );
foreach ( $candidate_queries as $entry ) {
	verify( (bool) preg_match( '/LIMIT (?:[1-9]|[1-4][0-9]|50)\z/', $entry[0] ), 'All 50 approved contents share the total 100-candidate budget with at most 50 product candidates.' );
	verify( str_contains( $entry[0], "post_type = 'product' AND post_status = 'publish' AND post_password = ''" ), 'Candidate SQL is a fixed public-only product projection.' );
}
echo wp_json_encode( [ 'result' => 'passed', 'assertions' => $assertions, 'wordpress' => $wp_version, 'woocommerce' => WC_VERSION,
	'catalog_rows' => 131, 'approved_contents' => count( $selected ), 'candidate_queries' => count( $candidate_queries ),
	'original_failures_reproduced' => 3, 'provider_requests' => 0, 'database' => 'fresh-disposable-socket-only' ] ), "\n";
