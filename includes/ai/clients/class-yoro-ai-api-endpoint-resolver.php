<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves YoRo API endpoint URLs without owning HTTP, auth, or semantic policy.
 */
class YoRo_AI_API_Endpoint_Resolver {

	public function api_base( $api_url ) {
		$api_url = rtrim( (string) $api_url, '/' );

		return (string) preg_replace(
			'#/(?:sites/(?:handshake|disconnect|credentials/rotate)|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|claim)|ai/email/compose|connect/wordpress/(?:intents|exchange)|email/(?:(?:gmail|microsoft)/connect-intents|gmail/disconnect|imap/connect|disconnect|inbox(?:/sync)?|threads/[^/]+(?:/(?:reply|archive|reopen|read|unread|notes|messages/[^/]+/retry))?))$#',
			'',
			$api_url
		);
	}

	public function wordpress_connect_intent( $api_url ) {
		$base = $this->api_base( $api_url );

		return preg_match( '#/connect/wordpress/intents$#', $base )
			? $base
			: $base . '/connect/wordpress/intents';
	}

	public function wordpress_connect_exchange( $api_url ) {
		$base = $this->api_base( $api_url );

		return preg_match( '#/connect/wordpress/exchange$#', $base )
			? $base
			: $base . '/connect/wordpress/exchange';
	}

	public function handshake( $api_url ) {
		$api_url = rtrim( (string) $api_url, '/' );

		return preg_match( '#/sites/handshake$#', $api_url )
			? $api_url
			: $this->api_base( $api_url ) . '/sites/handshake';
	}

	public function site_disconnect( $api_url ) {
		$api_url = rtrim( (string) $api_url, '/' );

		return preg_match( '#/sites/disconnect$#', $api_url )
			? $api_url
			: $this->api_base( $api_url ) . '/sites/disconnect';
	}

	public function site_credential_rotation( $api_url ) {
		$api_url = rtrim( (string) $api_url, '/' );

		return preg_match( '#/sites/credentials/rotate$#', $api_url )
			? $api_url
			: $this->api_base( $api_url ) . '/sites/credentials/rotate';
	}
}
