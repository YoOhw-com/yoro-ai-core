<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Execution_Journal {

	const TTL = 7 * DAY_IN_SECONDS;

	public static function begin( array $operation ) {
		$key = self::key( $operation );
		$existing = get_option( $key, [] );

		if ( is_array( $existing )
			&& absint( $existing['expires_at'] ?? 0 ) > time()
			&& in_array( $existing['state'] ?? '', [ 'executing', 'completed' ], true ) ) {
			return $existing;
		}

		$entry = [
			'state'       => 'executing',
			'envelope_id' => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'begun_at'    => gmdate( 'c' ),
			'expires_at'  => time() + self::TTL,
		];
		global $wpdb;
		// INSERT IGNORE is deliberately scoped to this existing journal option only.
		// WordPress add_option uses an upsert and cannot arbitrate ownership.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
			$key, maybe_serialize( $entry ), 'off'
		) );
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 1 !== $inserted ) {
			// A losing contender must never replace the winner or claim acquisition.
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$winner = get_option( $key, [] );
			if ( is_array( $winner ) && absint( $winner['expires_at'] ?? 0 ) > time()
				&& in_array( $winner['state'] ?? '', [ 'executing', 'completed' ], true ) ) { return $winner; }
			// Missing, expired or malformed contention is unknown, never execution authority.
			return [ 'state' => 'executing' ];
		}

		return [ 'state' => 'new' ];
	}

	public static function complete( array $operation, array $result ) {
		$entry = [
			'state'       => 'completed',
			'envelope_id' => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'result'      => $result,
			'completed_at' => gmdate( 'c' ),
			'expires_at'  => time() + self::TTL,
		];
		update_option( self::key( $operation ), $entry, false );

		return $entry;
	}

	public static function clear( array $operation ) {
		delete_option( self::key( $operation ) );
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
