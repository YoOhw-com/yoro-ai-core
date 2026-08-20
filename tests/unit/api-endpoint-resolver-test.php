<?php

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

require_once dirname( __DIR__, 2 ) . '/includes/ai/clients/class-yoro-ai-api-endpoint-resolver.php';

$failures = [];
$assert = static function ( $condition, $message ) use ( &$failures ) {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$resolver = new YoRo_AI_API_Endpoint_Resolver();
$base = 'https://api.example.test/api/v1';

$assert( $base === $resolver->api_base( $base ), 'Canonical API base changed.' );
$assert( $base === $resolver->api_base( $base . '/sites/handshake' ), 'Handshake tail was not normalized.' );
$assert( $base === $resolver->api_base( $base . '/connect/wordpress/intents' ), 'Connect-intent tail was not normalized.' );
$assert( $base === $resolver->api_base( $base . '/connect/wordpress/exchange' ), 'Connect-exchange tail was not normalized.' );
$assert( $base === $resolver->api_base( $base . '/sites/credentials/rotate' ), 'Credential-rotation tail was not normalized.' );

$assert(
	$base . '/connect/wordpress/intents' === $resolver->wordpress_connect_intent( $base ),
	'Connect intent endpoint is incorrect.'
);
$assert(
	$base . '/connect/wordpress/exchange' === $resolver->wordpress_connect_exchange( $base . '/sites/handshake' ),
	'Connect exchange endpoint did not normalize a legacy endpoint URL.'
);
$assert(
	$base . '/sites/handshake' === $resolver->handshake( $base ),
	'Handshake endpoint is incorrect.'
);
$assert(
	$base . '/sites/disconnect' === $resolver->site_disconnect( $base . '/actions/confirm' ),
	'Disconnect endpoint did not normalize an action URL.'
);
$assert(
	$base . '/sites/credentials/rotate' === $resolver->site_credential_rotation( $base . '/sites/disconnect' ),
	'Credential rotation endpoint did not normalize a disconnect URL.'
);

if ( [] !== $failures ) {
	fwrite( STDERR, implode( "\n", $failures ) . "\n" );
	exit( 1 );
}

echo "YoRo API endpoint resolver unit contract: PASS\n";
