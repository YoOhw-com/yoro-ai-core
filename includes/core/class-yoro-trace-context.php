<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Correlates WordPress outbound requests to the configured YoRo API.
 *
 * Trace IDs are diagnostic only. They never grant authority, idempotency, or
 * permission and must not contain user/customer information.
 */
class YoRo_Trace_Context {

	const HEADER = 'X-YoRo-Trace-Id';

	private static $registered = false;
	private static $trace_id   = '';

	public static function register() {
		if ( self::$registered ) {
			return;
		}

		add_filter( 'http_request_args', [ __CLASS__, 'attach_to_yoro_api_request' ], 10, 2 );
		self::$registered = true;
	}

	public static function attach_to_yoro_api_request( $args, $url ) {
		if ( ! is_array( $args ) || ! self::is_yoro_api_url( $url ) ) {
			return $args;
		}

		$headers = isset( $args['headers'] ) && is_array( $args['headers'] )
			? $args['headers']
			: [];
		$incoming = self::existing_header_value( $headers );
		$trace_id = self::normalize_trace_id( $incoming );

		if ( '' !== $trace_id ) {
			self::$trace_id = $trace_id;
		} else {
			$trace_id = self::current();
		}

		foreach ( array_keys( $headers ) as $key ) {
			if ( 0 === strcasecmp( (string) $key, self::HEADER ) && self::HEADER !== $key ) {
				unset( $headers[ $key ] );
			}
		}

		$headers[ self::HEADER ] = $trace_id;
		$args['headers']         = $headers;

		return $args;
	}

	public static function current() {
		$trace_id = self::normalize_trace_id( self::$trace_id );

		if ( '' !== $trace_id ) {
			return $trace_id;
		}

		$trace_id = function_exists( 'wp_generate_uuid4' )
			? strtolower( (string) wp_generate_uuid4() )
			: self::fallback_uuid4();

		self::$trace_id = self::normalize_trace_id( $trace_id );

		return self::$trace_id;
	}

	private static function existing_header_value( array $headers ) {
		foreach ( $headers as $key => $value ) {
			if ( 0 === strcasecmp( (string) $key, self::HEADER ) ) {
				return is_scalar( $value ) ? (string) $value : '';
			}
		}

		return '';
	}

	private static function is_yoro_api_url( $url ) {
		if ( ! class_exists( 'YoRo_AI_Settings' ) ) {
			return false;
		}

		$base_url = (string) YoRo_AI_Settings::get_ai_api_url();
		$request  = parse_url( (string) $url );
		$base     = parse_url( $base_url );

		if ( ! is_array( $request ) || ! is_array( $base ) ) {
			return false;
		}

		if (
			strtolower( (string) ( $request['scheme'] ?? '' ) ) !== strtolower( (string) ( $base['scheme'] ?? '' ) )
			|| strtolower( (string) ( $request['host'] ?? '' ) ) !== strtolower( (string) ( $base['host'] ?? '' ) )
			|| (int) ( $request['port'] ?? 0 ) !== (int) ( $base['port'] ?? 0 )
		) {
			return false;
		}

		$base_path    = '/' . ltrim( rtrim( (string) ( $base['path'] ?? '' ), '/' ), '/' );
		$request_path = '/' . ltrim( (string) ( $request['path'] ?? '' ), '/' );

		if ( '/' === $base_path ) {
			return true;
		}

		return $request_path === $base_path || 0 === strpos( $request_path, $base_path . '/' );
	}

	private static function normalize_trace_id( $value ) {
		$value = strtolower( trim( (string) $value ) );

		return preg_match(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$value
		) ? $value : '';
	}

	private static function fallback_uuid4() {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );

		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
