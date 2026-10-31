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
			$terms = self::terms( mb_substr( (string) $query, 0, 120 ) );
			$candidates = 0;
			$content_ids = array_slice( array_map( 'absint', $settings['content_ids'] ?? [] ), 0, 50 );
			if ( $content_ids ) {
				$posts = get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'publish', 'post__in' => $content_ids,
					'numberposts' => 50, 'has_password' => false,
					'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ] );
				foreach ( $posts as $post ) {
					if ( ++$candidates > 50 ) { break; }
					$source = self::content( $post->ID, $settings );
					if ( $source ) { $sources[] = $source; }
				}
				$sources = array_slice( self::rank( $sources, $terms ), 0, 10 );
			}
			if ( $commerce ) {
				$count = 0; $parents = [];
				foreach ( self::product_ids( $terms, 100 - $candidates ) as $id ) {
					if ( ++$candidates > 100 ) { break; }
					$source = self::product( $id );
					if ( $source ) { $sources[] = $source; ++$count; }
					if ( $source && 'variable' === $source['product']['kind'] ) { $parents[] = (int) $id; }
					if ( $count >= 10 ) { break; }
				}
				foreach ( $parents as $id ) {
					if ( $count < 10 && $candidates < 100 ) {
						$children = wc_get_products( [ 'type' => 'variation', 'parent' => (int) $id, 'status' => 'publish', 'limit' => 100 - $candidates,
							'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids' ] );
						foreach ( $children as $child ) {
							if ( ++$candidates > 100 ) { break; }
							$variation = self::product( $child );
							if ( $variation ) { $sources[] = $variation; ++$count; }
							if ( $count >= 10 ) { break; }
						}
					}
					if ( $count >= 10 ) { break; }
				}
			}
			$sources = self::rank( $sources, $terms );
		}
		return [ 'contract_type' => 'yoro.customer_support.public_facts', 'schema_version' => 1, 'commerce' => $commerce, 'sources' => $sources ];
	}

	/** Lexical retrieval is a candidate hint, never interpretation or permission. */
	private static function terms( $text ) {
		$text = mb_strtolower( remove_accents( (string) $text ) );
		preg_match_all( '/[\p{L}\p{N}]+/u', $text, $matches );
		return array_values( array_unique( $matches[0] ) );
	}

	private static function score( array $source, array $terms ) {
		$title = ' ' . implode( ' ', self::terms( $source['title'] ) ) . ' ';
		$text = ' ' . implode( ' ', self::terms( $source['text'] ) ) . ' ';
		$score = 0;
		foreach ( $terms as $term ) {
			$weight = min( 12, mb_strlen( $term ) );
			$score += ( false !== strpos( $title, ' ' . $term . ' ' ) ? 8 : 0 ) * $weight;
			$score += ( false !== strpos( $text, ' ' . $term . ' ' ) ? 1 : 0 ) * $weight;
		}
		return $score;
	}

	private static function rank( array $sources, array $terms ) {
		usort( $sources, static function ( $left, $right ) use ( $terms ) {
			return self::score( $right, $terms ) <=> self::score( $left, $terms );
		} );
		return $sources;
	}

	/** One bounded OR query avoids requiring every word of a sentence in one product. */
	private static function product_ids( array $terms, $limit ) {
		global $wpdb;
		if ( $limit <= 0 ) { return []; }
		$matches = []; $scores = []; $parameters = [];
		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$weight = min( 12, mb_strlen( $term ) );
			$matches[] = '(post_title LIKE %s OR post_excerpt LIKE %s)';
			$scores[] = '(CASE WHEN post_title LIKE %s THEN %d ELSE 0 END + CASE WHEN post_excerpt LIKE %s THEN %d ELSE 0 END)';
			$parameters[] = $like; $parameters[] = 8 * $weight; $parameters[] = $like; $parameters[] = $weight;
		}
		$where = $matches ? ' AND (' . implode( ' OR ', $matches ) . ')' : '';
		foreach ( $terms as $term ) {
			$like = '%' . $wpdb->esc_like( $term ) . '%';
			$parameters[] = $like; $parameters[] = $like;
		}
		$visibility = wc_get_product_visibility_term_ids();
		$hidden = array_filter( [ (int) ( $visibility['exclude-from-catalog'] ?? 0 ), (int) ( $visibility['exclude-from-search'] ?? 0 ) ] );
		if ( $hidden ) {
			$placeholders = implode( ', ', array_fill( 0, count( $hidden ), '%d' ) );
			$where .= " AND NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = {$wpdb->posts}.ID AND tt.taxonomy = 'product_visibility' AND tt.term_id IN ({$placeholders}))";
			$parameters = array_merge( $parameters, array_values( $hidden ) );
		}
		// SELECT order places relevance parameters before WHERE parameters.
		$rank = $scores ? '(' . implode( ' + ', $scores ) . ') AS support_rank' : '0 AS support_rank';
		$parameters[] = min( 100, (int) $limit );
		return $wpdb->get_col( $wpdb->prepare( "SELECT ID, {$rank} FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_password = ''{$where} ORDER BY support_rank DESC, ID ASC LIMIT %d", $parameters ) );
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
		$plain = html_entity_decode( wp_strip_all_tags( strip_shortcodes( (string) $text ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$plain = preg_replace( '/^[\s\x{FEFF}\x{200B}]+|[\s\x{FEFF}\x{200B}]+$/u', '', $plain ) ?? trim( $plain );
		$plain = mb_substr( $plain, 0, $limit );
		return preg_replace( '/^[\s\x{FEFF}\x{200B}]+|[\s\x{FEFF}\x{200B}]+$/u', '', $plain ) ?? trim( $plain );
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
