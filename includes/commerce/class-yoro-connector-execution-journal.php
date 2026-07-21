<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Execution_Journal {

	const TTL = DAY_IN_SECONDS;

	public static function begin( array $operation ) {
		$key = self::key( $operation );
		$existing = get_transient( $key );

		if ( is_array( $existing ) && in_array( $existing['state'] ?? '', [ 'executing', 'completed' ], true ) ) {
			return $existing;
		}

		$entry = [
			'state'       => 'executing',
			'envelope_id' => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'begun_at'    => gmdate( 'c' ),
		];
		set_transient( $key, $entry, self::TTL );

		return [ 'state' => 'new' ];
	}

	public static function complete( array $operation, array $result ) {
		$entry = [
			'state'       => 'completed',
			'envelope_id' => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'result'      => $result,
			'completed_at' => gmdate( 'c' ),
		];
		set_transient( self::key( $operation ), $entry, self::TTL );

		return $entry;
	}

	public static function clear( array $operation ) {
		delete_transient( self::key( $operation ) );
	}

	private static function key( array $operation ) {
		return 'yoro_connector_journal_' . substr(
			hash(
				'sha256',
				implode(
					'|',
					[
						$operation['envelope_id'] ?? '',
						$operation['idempotency_key'] ?? '',
					]
				)
			),
			0,
			40
		);
	}
}
