<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Contract_Validator {

	const OPERATION_TYPE = 'yoro.commerce.operation_envelope';

	const SCHEMA_VERSION = 1;

	public static function validate_operation_envelope( array $operation ) {
		$root_keys = [
			'contract_type',
			'schema_version',
			'envelope_id',
			'workflow_id',
			'operation_id',
			'position',
			'connector_key',
			'connector_connection_id',
			'manifest_id',
			'manifest_revision',
			'idempotency_key',
			'execution_mode',
			'action',
			'params',
			'actor',
			'plan_hash',
			'preview_hash',
			'authorization',
			'user_content_slots',
			'requested_at',
			'expires_at',
			'trace_id',
			'extensions',
		];
		$required_root_keys = [
			'contract_type',
			'schema_version',
			'envelope_id',
			'workflow_id',
			'operation_id',
			'position',
			'connector_key',
			'connector_connection_id',
			'manifest_id',
			'manifest_revision',
			'idempotency_key',
			'execution_mode',
			'action',
			'params',
			'actor',
			'plan_hash',
			'requested_at',
			'expires_at',
			'trace_id',
		];
		$keys_valid = self::validate_exact_keys( $operation, $root_keys, $required_root_keys, 'operation' );

		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		if ( self::OPERATION_TYPE !== $operation['contract_type']
			|| self::SCHEMA_VERSION !== $operation['schema_version']
			|| ! is_int( $operation['position'] )
			|| $operation['position'] < 1
			|| $operation['position'] > 1000
			|| ! is_int( $operation['manifest_revision'] )
			|| $operation['manifest_revision'] < 1
			|| 'remote_agent' !== $operation['execution_mode']
			|| ! is_array( $operation['params'] )
			|| ! self::matches( $operation['connector_key'], '/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/', 80 )
			|| ! self::matches( $operation['action'], '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', 160 )
			|| ! self::matches( $operation['plan_hash'], '/^[a-f0-9]{64}$/', 64 )
			|| ( isset( $operation['preview_hash'] ) && ! self::matches( $operation['preview_hash'], '/^[a-f0-9]{64}$/', 64 ) ) ) {
			return self::invalid( 'operation' );
		}

		foreach ( [ 'envelope_id', 'workflow_id', 'operation_id', 'connector_connection_id', 'manifest_id', 'idempotency_key', 'trace_id' ] as $key ) {
			if ( ! self::identifier_is_valid( $operation[ $key ], 'idempotency_key' === $key || 'trace_id' === $key ? 160 : 120 ) ) {
				return self::invalid( $key );
			}
		}

		$actor_valid = self::validate_actor( $operation['actor'], $operation['connector_connection_id'] );

		if ( is_wp_error( $actor_valid ) ) {
			return $actor_valid;
		}

		$authorization_valid = self::validate_authorization( $operation );

		if ( is_wp_error( $authorization_valid ) ) {
			return $authorization_valid;
		}

		$slots_valid = self::validate_user_content_slots( $operation['user_content_slots'] ?? [] );

		if ( is_wp_error( $slots_valid ) ) {
			return $slots_valid;
		}

		if ( ! self::rfc3339_is_valid( $operation['requested_at'] )
			|| ! self::rfc3339_is_valid( $operation['expires_at'] ) ) {
			return self::invalid( 'timestamps' );
		}

		$requested_at = strtotime( $operation['requested_at'] );
		$expires_at = strtotime( $operation['expires_at'] );

		if ( false === $requested_at || false === $expires_at || $expires_at <= $requested_at || $expires_at <= time() ) {
			return self::invalid( 'expires_at' );
		}

		$extensions_valid = self::validate_extension_namespaces( $operation );

		if ( is_wp_error( $extensions_valid ) ) {
			return $extensions_valid;
		}

		return true;
	}

	private static function validate_actor( $actor, $connector_connection_id ) {
		if ( ! is_array( $actor ) ) {
			return self::invalid( 'actor' );
		}

		$actor_keys = [
			'contract_type',
			'schema_version',
			'actor_type',
			'subject',
			'operator_id',
			'workspace_id',
			'connector_connection_id',
			'role',
			'permissions',
			'authenticated_at',
			'assertion_id',
			'extensions',
		];
		$required_actor_keys = array_values( array_diff( $actor_keys, [ 'extensions' ] ) );
		$keys_valid = self::validate_exact_keys( $actor, $actor_keys, $required_actor_keys, 'actor' );

		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		if ( 'yoro.commerce.actor_context' !== $actor['contract_type']
			|| self::SCHEMA_VERSION !== $actor['schema_version']
			|| ! in_array( $actor['actor_type'], [ 'user', 'connector_identity', 'service' ], true )
			|| ! self::identifier_is_valid( $actor['subject'], 160 )
			|| ! self::identifier_is_valid( $actor['operator_id'] )
			|| ! self::identifier_is_valid( $actor['workspace_id'] )
			|| ! self::identifier_is_valid( $actor['connector_connection_id'] )
			|| $connector_connection_id !== $actor['connector_connection_id']
			|| ! self::matches( $actor['role'], '/^[a-z][a-z0-9_:-]*$/', 80 )
			|| ! self::rfc3339_is_valid( $actor['authenticated_at'] )
			|| ! self::identifier_is_valid( $actor['assertion_id'], 160 )
			|| ! is_array( $actor['permissions'] )
			|| count( $actor['permissions'] ) > 100 ) {
			return self::invalid( 'actor' );
		}

		$permissions = [];

		foreach ( $actor['permissions'] as $permission ) {
			if ( ! self::matches( $permission, '/^[a-z][a-z0-9_]*(?:[.:][a-z0-9_]+)*$/', 120 )
				|| in_array( $permission, $permissions, true ) ) {
				return self::invalid( 'actor.permissions' );
			}

			$permissions[] = $permission;
		}

		return true;
	}

	private static function validate_authorization( array $operation ) {
		if ( ! isset( $operation['authorization'] ) ) {
			return true;
		}

		if ( ! is_array( $operation['authorization'] ) ) {
			return self::invalid( 'authorization' );
		}

		$authorization = $operation['authorization'];
		$keys_valid = self::validate_exact_keys(
			$authorization,
			[ 'confirmation_id', 'confirmed_at', 'execution_permit', 'permit_expires_at' ],
			[],
			'authorization'
		);

		if ( is_wp_error( $keys_valid ) ) {
			return $keys_valid;
		}

		if ( isset( $authorization['confirmation_id'] )
			&& ( ! self::bounded_string( $authorization['confirmation_id'], 1, 160 )
				|| ! isset( $authorization['confirmed_at'] )
				|| ! self::rfc3339_is_valid( $authorization['confirmed_at'] ) ) ) {
			return self::invalid( 'authorization.confirmation_id' );
		}

		if ( isset( $authorization['execution_permit'] )
			&& ( ! self::bounded_string( $authorization['execution_permit'], 40, 200 )
				|| ! isset( $operation['preview_hash'] )
				|| ! isset( $authorization['permit_expires_at'] )
				|| ! self::rfc3339_is_valid( $authorization['permit_expires_at'] )
				|| strtotime( $authorization['permit_expires_at'] ) <= time() ) ) {
			return self::invalid( 'authorization.execution_permit' );
		}

		return true;
	}

	private static function validate_user_content_slots( $slots ) {
		if ( ! is_array( $slots ) || count( $slots ) > 50 ) {
			return self::invalid( 'user_content_slots' );
		}

		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				return self::invalid( 'user_content_slots' );
			}

			$keys_valid = self::validate_exact_keys(
				$slot,
				[ 'field', 'content', 'content_scope', 'provenance' ],
				[ 'field', 'content', 'content_scope', 'provenance' ],
				'user_content_slots'
			);

			if ( is_wp_error( $keys_valid )
				|| ! self::bounded_string( $slot['field'], 1, 80 )
				|| ! self::bounded_string( $slot['content'], 1, 20000 )
				|| ! in_array( $slot['content_scope'], [ 'internal_note', 'customer_email', 'customer_message', 'store_preference', 'public_review_reply' ], true )
				|| ! in_array( $slot['provenance'], [ 'user_literal', 'user_goal' ], true ) ) {
				return self::invalid( 'user_content_slots' );
			}
		}

		return true;
	}

	private static function validate_exact_keys( array $value, array $allowed, array $required, $path ) {
		if ( array_diff( array_keys( $value ), $allowed ) || array_diff( $required, array_keys( $value ) ) ) {
			return self::invalid( $path );
		}

		return true;
	}

	private static function validate_extension_namespaces( array $value ) {
		foreach ( $value as $key => $item ) {
			if ( 'extensions' === $key ) {
				if ( ! is_array( $item ) || count( $item ) > 20 ) {
					return self::invalid( 'extensions' );
				}

				foreach ( array_keys( $item ) as $namespace ) {
					if ( ! self::matches( $namespace, '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', 160 ) ) {
						return self::invalid( 'extensions' );
					}
				}
			}

			if ( is_array( $item ) ) {
				$nested = self::validate_extension_namespaces( $item );

				if ( is_wp_error( $nested ) ) {
					return $nested;
				}
			}
		}

		return true;
	}

	private static function identifier_is_valid( $value, $max = 120 ) {
		return self::matches( $value, '/^[a-zA-Z0-9][a-zA-Z0-9._:\/-]*$/', $max );
	}

	private static function rfc3339_is_valid( $value ) {
		return self::matches(
			$value,
			'/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/',
			40
		) && false !== strtotime( $value );
	}

	private static function matches( $value, $pattern, $max ) {
		return is_string( $value )
			&& '' !== $value
			&& strlen( $value ) <= $max
			&& 1 === preg_match( $pattern, $value );
	}

	private static function bounded_string( $value, $min, $max ) {
		return is_string( $value ) && strlen( $value ) >= $min && strlen( $value ) <= $max;
	}

	private static function invalid( $path ) {
		return new WP_Error(
			'connector_operation_contract_invalid',
			sprintf( 'The connector operation envelope is invalid at %s.', sanitize_key( str_replace( '.', '_', $path ) ) )
		);
	}
}
