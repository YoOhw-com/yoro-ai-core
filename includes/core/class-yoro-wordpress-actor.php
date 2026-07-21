<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_WordPress_Actor {

	const ASSERTION_VERSION = 1;
	const ACCESS_META_KEY   = '_yoro_ai_actor_access';
	const SUBJECT_META_KEY  = '_yoro_ai_actor_subject';

	public static function current_payload() {
		$user = wp_get_current_user();

		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return [];
		}

		$installation_uuid = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_installation_uuid() : '';

		if ( '' === $installation_uuid ) {
			return [];
		}

		$capabilities = [];

		foreach ( YoRo_Capabilities::all() as $capability ) {
			if ( current_user_can( $capability ) ) {
				$capabilities[] = $capability;
			}
		}

		return [
			'version'           => self::ASSERTION_VERSION,
			'subject'           => self::subject_for_user_id( $user->ID, $installation_uuid ),
			'installation_uuid' => sanitize_text_field( $installation_uuid ),
			'display_name'      => sanitize_text_field( $user->display_name ),
			'email'             => sanitize_email( $user->user_email ),
			'roles'             => array_values( array_map( 'sanitize_key', (array) $user->roles ) ),
			'capabilities'      => $capabilities,
			'issued_at'         => time(),
			'nonce'             => wp_generate_uuid4(),
		];
	}

	public static function assertion( $site_key ) {
		$payload = self::current_payload();
		$site_key = (string) $site_key;

		if ( empty( $payload ) || '' === $site_key ) {
			return '';
		}

		$encoded_payload = self::base64_url_encode( wp_json_encode( $payload ) );
		$signature       = hash_hmac( 'sha256', $encoded_payload, $site_key, true );

		return $encoded_payload . '.' . self::base64_url_encode( $signature );
	}

	public static function record_access( array $access ) {
		$user_id = get_current_user_id();

		if ( $user_id < 1 ) {
			return;
		}

		update_user_meta(
			$user_id,
			self::ACCESS_META_KEY,
			[
				'assigned'    => ! empty( $access['assigned'] ),
				'status'      => sanitize_key( $access['status'] ?? 'missing' ),
				'role'        => sanitize_key( $access['role'] ?? '' ),
				'permissions' => array_values( array_filter( array_map( 'sanitize_key', is_array( $access['permissions'] ?? null ) ? $access['permissions'] : [] ) ) ),
				'checked_at'  => time(),
			]
		);

		$payload = self::current_payload();
		$subject = sanitize_text_field( $payload['subject'] ?? '' );

		if ( preg_match( '/^[a-f0-9]{64}$/', $subject ) ) {
			update_user_meta( $user_id, self::SUBJECT_META_KEY, $subject );
		}
	}

	public static function user_id_for_subject( $subject ) {
		$subject = sanitize_text_field( $subject );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $subject ) ) {
			return 0;
		}

		$user_ids = get_users(
			[
				'meta_key'   => self::SUBJECT_META_KEY,
				'meta_value' => $subject,
				'number'     => 2,
				'fields'     => 'ID',
			]
		);

		if ( 1 === count( $user_ids ) ) {
			return absint( $user_ids[0] );
		}

		if ( count( $user_ids ) > 1 ) {
			return 0;
		}

		$installation_uuid = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_installation_uuid() : '';

		if ( '' === $installation_uuid ) {
			return 0;
		}

		$matched_user_id = 0;

		foreach ( get_users( [ 'fields' => 'ID' ] ) as $candidate_user_id ) {
			$candidate_user_id = absint( $candidate_user_id );

			if ( $candidate_user_id < 1
				|| ! hash_equals( self::subject_for_user_id( $candidate_user_id, $installation_uuid ), $subject ) ) {
				continue;
			}

			if ( $matched_user_id > 0 ) {
				return 0;
			}

			$matched_user_id = $candidate_user_id;
		}

		if ( $matched_user_id > 0 ) {
			update_user_meta( $matched_user_id, self::SUBJECT_META_KEY, $subject );
		}

		return $matched_user_id;
	}

	public static function current_access() {
		$user_id = get_current_user_id();
		$access  = $user_id > 0 ? get_user_meta( $user_id, self::ACCESS_META_KEY, true ) : [];

		if ( ! is_array( $access ) || absint( $access['checked_at'] ?? 0 ) < time() - ( 10 * MINUTE_IN_SECONDS ) ) {
			return [];
		}

		return $access;
	}

	private static function base64_url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' );
	}

	private static function subject_for_user_id( $user_id, $installation_uuid ) {
		return hash_hmac(
			'sha256',
			sanitize_text_field( $installation_uuid ) . '|' . absint( $user_id ),
			wp_salt( 'auth' )
		);
	}
}
