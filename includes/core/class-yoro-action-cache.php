<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Cache {

	const DEFAULT_TTL = 30;
	const VERSION_OPTION = 'yoro_action_cache_versions';

	public static function remember( $group, $context, callable $callback, $ttl = self::DEFAULT_TTL ) {
		$group   = sanitize_key( (string) $group );
		$context = is_array( $context ) ? $context : [];
		$ttl     = (int) apply_filters(
			'yoro_action_cache_ttl',
			absint( $ttl ),
			$group,
			$context
		);

		if ( '' === $group || $ttl <= 0 || ! self::is_enabled( $group, $context ) ) {
			return call_user_func( $callback );
		}

		$key    = self::build_key( $group, $context );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && array_key_exists( 'value', $cached ) ) {
			return $cached['value'];
		}

		$result = call_user_func( $callback );

		if ( self::should_store( $result ) ) {
			set_transient(
				$key,
				[
					'value' => $result,
				],
				$ttl
			);
		}

		return $result;
	}

	public static function flush_groups( $groups ) {
		$groups   = is_array( $groups ) ? $groups : [ $groups ];
		$versions = get_option( self::VERSION_OPTION, [] );
		$versions = is_array( $versions ) ? $versions : [];

		foreach ( $groups as $group ) {
			$group = sanitize_key( (string) $group );

			if ( '' === $group ) {
				continue;
			}

			$versions[ $group ] = isset( $versions[ $group ] )
				? absint( $versions[ $group ] ) + 1
				: 1;
		}

		update_option( self::VERSION_OPTION, $versions, false );
	}

	private static function is_enabled( $group, $context ) {
		return (bool) apply_filters( 'yoro_action_cache_enabled', true, $group, $context );
	}

	private static function should_store( $result ) {
		if ( is_wp_error( $result ) || ! is_array( $result ) ) {
			return false;
		}

		if ( ! empty( $result['requires_confirmation'] ) ) {
			return false;
		}

		return true;
	}

	private static function build_key( $group, $context ) {
		$hash = md5(
			wp_json_encode(
				[
					'blog_id' => get_current_blog_id(),
					'group'   => $group,
					'group_version' => self::get_group_version( $group ),
					'context' => self::normalize_value( $context ),
					'version' => YORO_AI_CORE_VERSION,
				]
			)
		);

		return 'yoro_cache_' . $group . '_' . $hash;
	}

	private static function normalize_value( $value ) {
		if ( is_array( $value ) ) {
			if ( self::is_assoc( $value ) ) {
				ksort( $value );
			}

			$normalized = [];

			foreach ( $value as $key => $item ) {
				$normalized[ $key ] = self::normalize_value( $item );
			}

			return $normalized;
		}

		if ( is_bool( $value ) || is_null( $value ) || is_numeric( $value ) ) {
			return $value;
		}

		return (string) $value;
	}

	private static function is_assoc( array $value ) {
		return array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}

	private static function get_group_version( $group ) {
		$versions = get_option( self::VERSION_OPTION, [] );
		$versions = is_array( $versions ) ? $versions : [];

		return absint( $versions[ $group ] ?? 0 );
	}
}
