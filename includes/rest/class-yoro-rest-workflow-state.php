<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Workflow_State {

	public static function create_token( $user_id, $command, $parsed, $plan ) {
		$token = wp_generate_uuid4();

		$payload = [
			'user_id'      => absint( $user_id ),
			'command_hash' => wp_hash( (string) $command ),
			'created_at'   => time(),
			'parsed'       => is_array( $parsed ) ? $parsed : [],
			'plan'         => is_array( $plan ) ? $plan : [],
		];

		set_transient(
			self::get_transient_key( $user_id, $token ),
			$payload,
			10 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	public static function create_action_token( $user_id, $action, $params, $parsed, $plan ) {
		$token = wp_generate_uuid4();

		$payload = [
			'user_id'     => absint( $user_id ),
			'action_hash' => self::build_action_hash( $action, $params ),
			'created_at'  => time(),
			'parsed'      => is_array( $parsed ) ? $parsed : [],
			'plan'        => is_array( $plan ) ? $plan : [],
		];

		set_transient(
			self::get_transient_key( $user_id, $token ),
			$payload,
			10 * MINUTE_IN_SECONDS
		);

		return $token;
	}

	public static function get( $user_id, $token, $command ) {
		$token = sanitize_text_field( $token );

		if ( empty( $token ) ) {
			return null;
		}

		$payload = get_transient(
			self::get_transient_key( $user_id, $token )
		);

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return null;
		}

		if ( absint( $payload['user_id'] ?? 0 ) !== absint( $user_id ) ) {
			return null;
		}

		if ( empty( $payload['command_hash'] ) || $payload['command_hash'] !== wp_hash( (string) $command ) ) {
			return null;
		}

		if ( empty( $payload['parsed'] ) || empty( $payload['plan'] ) ) {
			return null;
		}

		return $payload;
	}

	public static function get_action( $user_id, $token, $action, $params ) {
		$token = sanitize_text_field( $token );

		if ( empty( $token ) ) {
			return null;
		}

		$payload = get_transient(
			self::get_transient_key( $user_id, $token )
		);

		if ( empty( $payload ) || ! is_array( $payload ) ) {
			return null;
		}

		if ( absint( $payload['user_id'] ?? 0 ) !== absint( $user_id ) ) {
			return null;
		}

		if ( empty( $payload['action_hash'] ) || $payload['action_hash'] !== self::build_action_hash( $action, $params ) ) {
			return null;
		}

		if ( empty( $payload['parsed'] ) || empty( $payload['plan'] ) ) {
			return null;
		}

		return $payload;
	}

	public static function delete( $user_id, $token ) {
		if ( empty( $token ) ) {
			return;
		}

		delete_transient(
			self::get_transient_key( $user_id, $token )
		);
	}

	private static function get_transient_key( $user_id, $token ) {
		return 'yoro_workflow_state_' . absint( $user_id ) . '_' . sanitize_key( $token );
	}

	private static function build_action_hash( $action, $params ) {
		return wp_hash(
			wp_json_encode(
				[
					'action' => sanitize_text_field( $action ),
					'params' => is_array( $params ) ? $params : [],
				]
			)
		);
	}
}
