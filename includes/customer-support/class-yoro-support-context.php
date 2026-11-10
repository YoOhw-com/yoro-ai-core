<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Server-held context authority; browser tokens are encrypted inputs, never permissions. */
class YoRo_Support_Context {
	public static function capabilities( array $settings ) {
		$features = self::preferences( $settings )['features'];
		$woo = function_exists( 'wc_get_product' );
		$quote = $woo && ! is_wp_error( YoRo_Support_Commerce::profile() );
		return [ 'content' => $features['content'], 'products' => $woo && $features['products'],
			'quote' => $quote && $features['quote'], 'coupons' => $quote && $features['coupons'],
			'payments' => $quote && $features['payments'], 'orders' => $woo && $features['orders'] ];
	}

	public static function preferences( array $settings ) {
		$values = is_array( $settings['preferences'] ?? null ) ? $settings['preferences'] : [];
		return [ 'features' => array_replace( [ 'content' => true, 'products' => true, 'quote' => false, 'coupons' => false, 'payments' => false, 'orders' => false ], $values['features'] ?? [] ),
			'appearance' => array_replace( [ 'name' => 'YoRo', 'welcome' => 'How can we help you today?', 'launcher' => 'Chat support', 'color' => '#2b3a20', 'icon' => 'yoro-animated', 'position' => 'right', 'starters' => [ 'products', 'policies', 'orders' ] ], $values['appearance'] ?? [] ),
			'sources' => $values['sources'] ?? [] ];
	}

	public static function authorize( array $session, $purpose, $access_id = null ) {
		$body = [ 'purpose' => $purpose ];
		if ( null !== $access_id ) { $body['access_id'] = $access_id; }
		$proof = YoRo_Support_Transport::request( 'POST', '/context/authorize', $body, $session );
		if ( is_wp_error( $proof ) ) { return $proof; }
		if ( 2 !== ( $proof['protocol'] ?? null ) || $purpose !== ( $proof['purpose'] ?? '' )
			|| ! hash_equals( $session['session_id'], (string) ( $proof['session_id'] ?? '' ) )
			|| (int) ( $proof['revision'] ?? 0 ) !== (int) $session['revision'] ) { return self::invalid(); }
		return $proof;
	}

	public static function create( array $settings, array $session, $kind, array $params ) {
		if ( ! is_string( $kind ) ) { return self::invalid(); }
		$purpose = [ 'order' => 'orders', 'quote' => 'quote', 'coupon' => 'coupons', 'payment' => 'payments' ][ $kind ] ?? '';
		if ( ! $purpose || ! self::capabilities( $settings )[ $purpose ] ) { return self::invalid(); }
		$proof = self::authorize( $session, $purpose );
		if ( is_wp_error( $proof ) ) { return $proof; }
		$expires = min( time() + 300, (int) $session['expires'] );
		if ( ! empty( $proof['access_id'] ) ) { $expires = min( $expires, strtotime( $proof['expires_at'] ) ); }
		$context = [ 'kind' => $kind, 'access_id' => $proof['access_id'] ?? null,
			'reference' => hash( 'sha256', wp_json_encode( $params ) ), 'expires_at' => gmdate( 'c', $expires ) ];
		if ( 'order' === $kind ) {
			if ( array_diff( array_keys( $params ), [ 'reference' ] ) ) { return self::invalid(); }
			$card = YoRo_Support_Commerce::order( $proof, $params['reference'] ?? null );
		} else {
			if ( ! empty( $params['coupon'] ) && ! self::capabilities( $settings )['coupons'] ) { return self::invalid(); }
			$params['kind'] = $kind;
			$params['quoted_at'] = gmdate( 'c' );
			$card = YoRo_Support_Commerce::quote( $params, $proof );
		}
		if ( is_wp_error( $card ) ) { return $card; }
		$card = self::project( $card, $settings );
		$token = YoRo_Support_Transport::seal( [ 'session_id' => $session['session_id'], 'credential_binding' => YoRo_Support_Transport::binding( $session['credential'] ), 'revision' => $session['revision'], 'expires' => $expires, 'context' => $context, 'params' => $params ], 'context' );
		if ( '' === $token ) { return self::invalid(); }
		return [ 'context_token' => $token, 'card' => $card, 'private' => null !== $context['access_id'], 'expires_at' => $context['expires_at'] ];
	}

	public static function snapshot( array $settings, array $session, $query = '', $ids = null, $token = '' ) {
		$public = YoRo_Support_Facts::snapshot( $settings, $query, $ids );
		$features = self::preferences( $settings )['features'];
		$public['sources'] = array_values( array_filter( $public['sources'], static function ( $source ) use ( $features ) {
			return $features[ 'content' === $source['type'] ? 'content' : 'products' ];
		} ) );
		foreach ( $public['sources'] as &$source ) {
			if ( 'content' === $source['type'] ) {
				$source['guidance'] = [ 'kind' => 'faq', 'product_ids' => [] ];
				foreach ( self::preferences( $settings )['sources'] as $mapping ) {
					if ( 'content:' . $mapping['id'] === $source['id'] ) { $source['guidance'] = [ 'kind' => $mapping['kind'], 'product_ids' => $mapping['product_ids'] ]; break; }
				}
			}
			if ( 'product' === $source['type'] ) {
				$product = wc_get_product( (int) substr( $source['id'], 8 ) );
				if ( ! $product ) { return self::invalid(); }
				$source['product']['sku'] = mb_substr( wp_strip_all_tags( $product->get_sku() ), 0, 100 );
			}
			$source['version'] = hash( 'sha256', wp_json_encode( $source ) );
		}
		unset( $source );
		$snapshot = [ 'contract_type' => 'yoro.customer_support.facts', 'schema_version' => 2, 'commerce' => $public['commerce'],
			'sources' => $public['sources'], 'capabilities' => self::capabilities( $settings ), 'context' => null, 'cards' => [] ];
		if ( '' === $token ) { return $snapshot; }
		$held = YoRo_Support_Transport::open( $token, 'context' );
		if ( ! $held || ! isset( $held['context'], $held['params'] ) || ! is_array( $held['params'] )
			|| ! hash_equals( $session['session_id'], $held['session_id'] ) || ! hash_equals( YoRo_Support_Transport::binding( $session['credential'] ), (string) ( $held['credential_binding'] ?? '' ) )
			|| (int) ( $held['revision'] ?? 0 ) !== (int) $settings['revision'] ) { return self::invalid(); }
		$kind = $held['context']['kind'];
		$purpose = [ 'order' => 'orders', 'quote' => 'quote', 'coupon' => 'coupons', 'payment' => 'payments' ][ $kind ] ?? '';
		if ( ! $purpose || ! self::capabilities( $settings )[ $purpose ] ) { return self::invalid(); }
		$proof = self::authorize( $session, $purpose, $held['context']['access_id'] ?? null );
		if ( is_wp_error( $proof ) ) { return $proof; }
		if ( ( $held['context']['access_id'] ?? null ) !== ( $proof['access_id'] ?? null ) ) { return self::invalid(); }
		$card = 'order' === $kind ? YoRo_Support_Commerce::order( $proof, $held['params']['reference'] ?? null ) : YoRo_Support_Commerce::quote( $held['params'], $proof );
		if ( is_wp_error( $card ) ) { return $card; }
		$card = self::project( $card, $settings );
		$snapshot['sources'] = array_slice( $snapshot['sources'], 0, 19 );
		$snapshot['context'] = $held['context']; $snapshot['cards'] = [ $card ];
		return $snapshot;
	}

	private static function project( array $card, array $settings ) {
		if ( 'order' === $card['kind'] ) { return $card; }
		$card['data']['payment_state'] = self::capabilities( $settings )['payments'] ? 'available' : 'disabled';
		if ( 'disabled' === $card['data']['payment_state'] ) { $card['data']['payment_methods'] = []; }
		return YoRo_Support_Commerce::card( $card['kind'], $card['data'], $card['title'] );
	}

	private static function invalid() { return new WP_Error( 'support_context_invalid', 'This information has expired or is unavailable. Refresh the selection or verify again.', [ 'status' => 403 ] ); }
}
