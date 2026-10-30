<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Public projections only. No filters, shortcode execution, admin context or natural-language routing. */
class YoRo_Support_Facts {
	public static function snapshot( array $settings, $query = '', $ids = null ) {
		$commerce = function_exists( 'wc_get_product' ) && function_exists( 'wc_get_products' );
		$sources = [];
		if ( is_array( $ids ) ) {
			foreach ( array_slice( $ids, 0, 20 ) as $id ) {
				$parts = explode( ':', (string) $id );
				$source = 'content' === ( $parts[0] ?? '' ) ? self::content( (int) ( $parts[1] ?? 0 ), $settings )
					: ( $commerce && 'product' === ( $parts[0] ?? '' ) ? self::product( (int) ( $parts[1] ?? 0 ) ) : null );
				if ( $source ) { $sources[] = $source; }
			}
		} else {
			$content_ids = array_slice( array_map( 'absint', $settings['content_ids'] ?? [] ), 0, 50 );
			if ( $content_ids ) {
				$posts = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'post__in' => $content_ids,
					's' => mb_substr( (string) $query, 0, 120 ), 'numberposts' => 100, 'has_password' => false,
					'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
				foreach ( $posts as $post ) {
					$source = self::content( $post->ID, $settings );
					if ( $source ) { $sources[] = $source; }
					if ( count( $sources ) >= 10 ) { break; }
				}
			}
			if ( $commerce ) {
				$products = wc_get_products( [ 'status' => 'publish', 'visibility' => 'visible', 'limit' => 100,
					'orderby' => 'ID', 'order' => 'ASC', 's' => mb_substr( (string) $query, 0, 120 ) ] );
				$count = 0; $candidates = 0;
				foreach ( $products as $product ) {
					if ( ++$candidates > 100 ) { break; }
					$source = self::product( $product->get_id() );
					if ( $source ) { $sources[] = $source; ++$count; }
					if ( $source && 'variable' === $product->get_type() && $count < 10 && $candidates < 100 ) {
						$children = wc_get_products( [ 'type' => 'variation', 'parent' => $product->get_id(), 'status' => 'publish', 'limit' => 100 - $candidates, 'return' => 'ids' ] );
						foreach ( $children as $child ) {
							++$candidates;
							$variation = self::product( $child );
							if ( $variation ) { $sources[] = $variation; ++$count; }
							if ( $count >= 10 ) { break; }
						}
					}
					if ( $count >= 10 ) { break; }
				}
			}
		}
		return [ 'contract_type' => 'yoro.customer_support.public_facts', 'schema_version' => 1, 'commerce' => $commerce, 'sources' => $sources ];
	}

	public static function content( $id, array $settings ) {
		if ( ! in_array( (int) $id, array_map( 'intval', $settings['content_ids'] ?? [] ), true ) ) { return null; }
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, [ 'post', 'page' ], true )
			|| '' !== $post->post_password || ! is_post_publicly_viewable( $post ) ) { return null; }
		$url = self::url( get_permalink( $post ) );
		if ( ! $url ) { return null; }
		return self::version( [ 'id' => 'content:' . $post->ID, 'type' => 'content', 'url' => $url,
			'title' => self::plain( $post->post_title, 200 ), 'text' => self::plain( $post->post_content, 1000 ) ] );
	}

	public static function product( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) { return null; }
		$product = wc_get_product( $id );
		if ( ! $product || ! in_array( $product->get_type(), [ 'simple', 'variable', 'variation' ], true )
			|| ! self::public_product( $product ) || ! $product->is_purchasable() || '' === (string) $product->get_price() ) { return null; }
		$parent = null;
		if ( 'variation' === $product->get_type() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( ! $parent || 'variable' !== $parent->get_type() || ! self::public_product( $parent )
				|| ! $parent->is_purchasable() || ! $product->variation_is_visible() || ! $product->variation_is_active() ) { return null; }
		}
		$url = self::url( $product->get_permalink() );
		if ( ! $url ) { return null; }
		$options = [];
		foreach ( $product->get_attributes() as $name => $attribute ) {
			if ( is_object( $attribute ) ) {
				if ( ! $attribute->get_visible() && ! $attribute->get_variation() ) { continue; }
				$values = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] ) : $attribute->get_options();
				if ( is_wp_error( $values ) ) { continue; }
				$value = implode( ', ', array_map( 'strval', $values ) );
				$name = wc_attribute_label( $attribute->get_name() );
			} else { $value = (string) $attribute; $name = wc_attribute_label( $name ); }
			$options[] = self::plain( $name . ': ' . $value, 160 );
			if ( count( $options ) >= 20 ) { break; }
		}
		return self::version( [ 'id' => 'product:' . $product->get_id(), 'type' => 'product', 'url' => $url,
			'title' => self::plain( $product->get_name(), 200 ), 'text' => self::plain( $product->get_short_description(), 1000 ),
			'product' => [ 'kind' => $product->get_type(), 'price' => self::plain( $product->get_price_html(), 80 ),
				'currency' => get_woocommerce_currency(), 'stock' => $product->get_stock_status(),
				'options' => $options, 'parent_id' => $parent ? $parent->get_id() : null ] ] );
	}

	private static function public_product( $product ) {
		$post = get_post( $product->get_id() );
		return $post && 'publish' === $post->post_status && '' === $post->post_password
			&& 'publish' === $product->get_status() && 'hidden' !== $product->get_catalog_visibility() && $product->is_visible();
	}
	private static function plain( $text, $limit ) {
		return mb_substr( html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 0, $limit );
	}
	private static function url( $url ) {
		return 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && wp_parse_url( $url, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST )
			&& ! wp_parse_url( $url, PHP_URL_USER ) && ! wp_parse_url( $url, PHP_URL_PASS ) ? esc_url_raw( $url ) : null;
	}
	private static function version( array $source ) {
		$source['version'] = hash( 'sha256', wp_json_encode( $source ) );
		return $source;
	}
}
