<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Request_Tracker {

	private static $requests = [];

	public static function reset() {
		self::$requests = [];
	}

	public static function record( $operation, $duration_ms, $status = 'success', $meta = [] ) {
		self::$requests[] = [
			'operation'   => sanitize_key( (string) $operation ),
			'duration_ms' => max( 0, (int) $duration_ms ),
			'status'      => sanitize_key( (string) $status ),
			'meta'        => is_array( $meta ) ? $meta : [],
		];
	}

	public static function summary() {
		$requests = [];
		$total_ms = 0;

		foreach ( self::$requests as $request ) {
			if ( ! is_array( $request ) ) {
				continue;
			}

			$duration_ms = max( 0, (int) ( $request['duration_ms'] ?? 0 ) );
			$total_ms   += $duration_ms;
			$requests[]  = [
				'operation'   => sanitize_key( $request['operation'] ?? '' ),
				'duration_ms' => $duration_ms,
				'status'      => sanitize_key( $request['status'] ?? '' ),
			];
		}

		return [
			'count'     => count( $requests ),
			'total_ms'  => $total_ms,
			'requests'  => array_slice( $requests, 0, 10 ),
		];
	}
}
