<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Rate_Limiter {

	public static function check( $scope = 'run' ) {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return true;
		}

		$limit  = (int) get_option( 'yoro_rate_limit_count', 10 );
		$window = (int) get_option( 'yoro_rate_limit_window', 60 );

		$key = 'yoro_rate_' . $scope . '_' . $user_id;

		$hits = get_transient( $key );

		if ( false === $hits ) {
			set_transient( $key, 1, $window );
			return true;
		}

		if ( (int) $hits >= $limit ) {
			return new WP_Error(
				'yoro_rate_limit_exceeded',
				sprintf(
					__( 'Too many YoRo commands. Please wait %d seconds and try again.', 'yoro-agent-core' ),
					$window
				),
				[ 'status' => 429 ]
			);
		}

		set_transient( $key, (int) $hits + 1, $window );

		return true;
	}
}