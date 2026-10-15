<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Execution_Journal {

	const TTL = 7 * DAY_IN_SECONDS;

	public static function begin( array $operation ) {
		if ( isset( $operation['extensions']['yoro.mcp_early_access_commerce_write'] ) ) {
			return self::begin_confirmed_write( $operation );
		}
		$key = self::key( $operation );
		$existing = get_option( $key, [] );
		$r2_hash = isset( $operation['extensions']['yoro.mcp_r2_product_read'] ) ? hash( 'sha256', wp_json_encode( $operation ) ) : null;
		if ( null !== $r2_hash && is_array( $existing ) && ! empty( $existing )
			&& ( $existing['r2_envelope_hash'] ?? null ) !== $r2_hash ) {
			return [ 'state' => 'conflict' ];
		}

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
		if ( null !== $r2_hash ) {
			$entry['r2_envelope_hash'] = $r2_hash;
		}
		if ( ! add_option( $key, $entry, '', false ) ) {
			update_option( $key, $entry, false );
		}

		return [ 'state' => 'new' ];
	}

    /** Writes require a database-proven marker; an option-cache hit is not durable evidence. */
    private static function begin_confirmed_write( array $operation ) {
        $key = self::key( $operation );
        $hash = hash( 'sha256', wp_json_encode( $operation ) );
        $raw = self::durable_value( $key );
        if ( false === $raw ) { return [ 'state' => 'unavailable' ]; }
        if ( null !== $raw ) {
            $existing = maybe_unserialize( $raw );
            if ( ! is_array( $existing ) || ( $existing['write_envelope_hash'] ?? null ) !== $hash
                || ! in_array( $existing['state'] ?? '', [ 'executing', 'completed' ], true ) ) {
                return [ 'state' => 'conflict' ];
            }
            // An expired marker still forbids another mutation, including after an interrupted note.
            return $existing;
        }
        $entry = [ 'state' => 'executing', 'envelope_id' => $operation['envelope_id'],
            'write_envelope_hash' => $hash, 'marker_id' => wp_generate_uuid4(),
            'begun_at' => gmdate( 'c' ), 'expires_at' => time() + self::TTL ];
        if ( ! add_option( $key, $entry, '', false ) || self::durable_value( $key ) !== maybe_serialize( $entry ) ) {
            return [ 'state' => 'unavailable' ];
        }
        return [ 'state' => 'new' ];
    }

    private static function durable_value( $key ) {
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
        return '' !== $wpdb->last_error ? false : $value;
    }

	public static function complete( array $operation, array $result ) {
		$entry = [
			'state'       => 'completed',
			'envelope_id' => sanitize_text_field( $operation['envelope_id'] ?? '' ),
			'result'      => $result,
			'completed_at' => gmdate( 'c' ),
			'expires_at'  => time() + self::TTL,
		];
		if ( isset( $operation['extensions']['yoro.mcp_r2_product_read'] ) ) {
			$entry['r2_envelope_hash'] = hash( 'sha256', wp_json_encode( $operation ) );
		}
        if ( isset( $operation['extensions']['yoro.mcp_early_access_commerce_write'] ) ) {
            $entry['write_envelope_hash'] = hash( 'sha256', wp_json_encode( $operation ) );
        }
		update_option( self::key( $operation ), $entry, false );
        if ( isset( $entry['write_envelope_hash'] ) && self::durable_value( self::key( $operation ) ) !== maybe_serialize( $entry ) ) {
            return [ 'state' => 'unavailable' ];
        }

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
