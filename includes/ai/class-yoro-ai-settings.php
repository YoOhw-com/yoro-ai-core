<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Settings {

	const OPTION_AI_API_URL = 'yoro_ai_api_url';
	const OPTION_AI_API_SITE_KEY = 'yoro_ai_api_site_key';
	const OPTION_AI_API_ENABLED = 'yoro_ai_api_enabled';
	const OPTION_AI_API_STATUS = 'yoro_ai_api_status';
	const DEFAULT_AI_API_URL = 'http://127.0.0.1:8010/api/v1';

	public static function get_ai_api_site_key_source() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return 'constant';
		}

		$stored = (string) get_option( self::OPTION_AI_API_SITE_KEY, '' );

		if ( '' !== trim( $stored ) ) {
			return 'option';
		}

		return 'missing';
	}

	public static function get_provider_preference() {
		return YORO_AI_PROVIDER_YORO_AI_API;
	}

	public static function get_ai_api_url() {
		if ( defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL ) {
			return esc_url_raw( YORO_AI_API_URL );
		}

		return esc_url_raw( (string) get_option( self::OPTION_AI_API_URL, self::DEFAULT_AI_API_URL ) );
	}

	public static function get_ai_api_dashboard_url() {
		$api_url = untrailingslashit( self::get_ai_api_url() );

		if ( '' === $api_url ) {
			return '';
		}

		$base_url = preg_replace( '#/api/v[0-9]+(?:/.*)?$#', '', $api_url );

		if ( $base_url === $api_url ) {
			$base_url = preg_replace(
				'#/(?:sites/handshake|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|cancel))$#',
				'',
				$api_url
			);
		}

		$base_url = untrailingslashit( (string) $base_url );

		if ( '' === $base_url ) {
			return '';
		}

		return esc_url_raw( $base_url . '/dashboard' );
	}

	public static function get_ai_api_account_url() {
		$status = self::get_ai_api_status();

		if ( ! empty( $status['account_url'] ) ) {
			return esc_url_raw( $status['account_url'] );
		}

		$base_url = self::get_ai_api_base_url();

		return '' !== $base_url ? esc_url_raw( $base_url . '/account' ) : '';
	}

	public static function get_ai_api_billing_url() {
		$status = self::get_ai_api_status();

		if ( ! empty( $status['billing_url'] ) ) {
			return esc_url_raw( $status['billing_url'] );
		}

		$base_url = self::get_ai_api_base_url();

		return '' !== $base_url ? esc_url_raw( $base_url . '/account/billing' ) : '';
	}

	public static function get_ai_api_conversation_dashboard_base_url() {
		$dashboard_url = untrailingslashit( self::get_ai_api_dashboard_url() );

		if ( '' === $dashboard_url ) {
			return '';
		}

		return esc_url_raw( $dashboard_url . '/conversations/by-uuid' );
	}

	public static function get_ai_api_conversation_dashboard_url( $conversation_id ) {
		$conversation_id = sanitize_text_field( (string) $conversation_id );

		if ( '' === $conversation_id ) {
			return '';
		}

		$base_url = self::get_ai_api_conversation_dashboard_base_url();

		if ( '' === $base_url ) {
			return '';
		}

		return esc_url_raw( untrailingslashit( $base_url ) . '/' . rawurlencode( $conversation_id ) );
	}

	private static function get_ai_api_base_url() {
		$api_url = untrailingslashit( self::get_ai_api_url() );

		if ( '' === $api_url ) {
			return '';
		}

		$base_url = preg_replace( '#/api/v[0-9]+(?:/.*)?$#', '', $api_url );

		if ( $base_url === $api_url ) {
			$base_url = preg_replace(
				'#/(?:sites/handshake|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|cancel)|connect/wordpress/(?:intents|exchange))$#',
				'',
				$api_url
			);
		}

		$base_url = untrailingslashit( (string) $base_url );

		return '' !== $base_url ? esc_url_raw( $base_url ) : '';
	}

	public static function get_ai_api_site_key() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return YORO_AI_API_SITE_KEY;
		}

		return sanitize_text_field( (string) get_option( self::OPTION_AI_API_SITE_KEY, '' ) );
	}

	public static function is_ai_api_enabled() {
		return 'yes' === get_option( self::OPTION_AI_API_ENABLED, 'yes' );
	}

	public static function get_ai_api_status() {
		$status = get_option( self::OPTION_AI_API_STATUS, [] );

		return is_array( $status ) ? self::normalize_ai_api_status( $status ) : [];
	}

	public static function get_ai_api_entitlements() {
		$status = self::get_ai_api_status();
		$entitlements = $status['entitlements'] ?? [];

		return is_array( $entitlements ) ? $entitlements : [];
	}

	public static function get_ai_api_usage() {
		$status = self::get_ai_api_status();
		$usage = $status['usage'] ?? [];

		return is_array( $usage ) ? $usage : [];
	}

	public static function ai_api_allows_feature( $feature ) {
		$feature = self::normalize_ai_api_feature( $feature );

		if ( '' === $feature ) {
			return false;
		}

		$entitlements = self::get_ai_api_entitlements();

		if ( [] === $entitlements ) {
			return true;
		}

		return array_key_exists( $feature, $entitlements ) && ! empty( $entitlements[ $feature ] );
	}

	public static function ai_api_has_quota_remaining() {
		$usage = self::get_ai_api_usage();

		if ( ! array_key_exists( 'remaining', $usage ) || null === $usage['remaining'] ) {
			return true;
		}

		return absint( $usage['remaining'] ) > 0;
	}

	public static function update_ai_api_status( array $status ) {
		update_option( self::OPTION_AI_API_STATUS, self::normalize_ai_api_status( $status ), false );
	}

	public static function clear_ai_api_status() {
		delete_option( self::OPTION_AI_API_STATUS );
	}

	public static function is_ai_api_url_locked() {
		return defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL;
	}

	public static function is_ai_api_site_key_locked() {
		return 'constant' === self::get_ai_api_site_key_source();
	}

	public static function get_ai_api_url_constant_name() {
		if ( defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL ) {
			return 'YORO_AI_API_URL';
		}

		return '';
	}

	public static function get_ai_api_site_key_constant_name() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return 'YORO_AI_API_SITE_KEY';
		}

		return '';
	}

	public static function get_all() {
		return [
			'provider'                   => self::get_provider_preference(),
			'ai_email_compose_enabled'   => get_option( 'yoro_ai_email_compose_enabled', 'yes' ),
			'rate_limit_count'           => absint( get_option( 'yoro_rate_limit_count', 10 ) ),
			'rate_limit_window'          => absint( get_option( 'yoro_rate_limit_window', 60 ) ),
			'undo_ttl'                   => absint( get_option( 'yoro_undo_ttl', 60 ) ),
			'max_result_limit'           => absint( get_option( 'yoro_ai_max_result_limit', 20 ) ),
			'ai_api_url'                 => self::get_ai_api_url(),
			'ai_api_url_locked'          => self::is_ai_api_url_locked(),
			'ai_api_url_constant'        => self::get_ai_api_url_constant_name(),
			'ai_api_site_key_configured' => '' !== self::get_ai_api_site_key(),
			'ai_api_site_key_source'     => self::get_ai_api_site_key_source(),
			'ai_api_site_key_locked'     => self::is_ai_api_site_key_locked(),
			'ai_api_site_key_constant'   => self::get_ai_api_site_key_constant_name(),
			'ai_api_enabled'             => self::is_ai_api_enabled() ? 'yes' : 'no',
			'ai_api_status'              => self::get_ai_api_status(),
			'ai_api_account_url'         => self::get_ai_api_account_url(),
			'ai_api_billing_url'         => self::get_ai_api_billing_url(),
			'ai_api_dashboard_url'       => self::get_ai_api_dashboard_url(),
			'ai_api_conversation_dashboard_base_url' => self::get_ai_api_conversation_dashboard_base_url(),
		];
	}

	public static function save_admin_settings( array $post ) {
		$previous_ai_api_url      = self::get_ai_api_url();
		$previous_ai_api_site_key = self::get_ai_api_site_key();
		$ai_api_url_locked       = self::is_ai_api_url_locked();
		$ai_api_site_key_locked  = self::is_ai_api_site_key_locked();

		$result = [
			'ai_api_connection_result'      => null,
			'ai_api_connection_error'       => null,
			'ai_api_credentials_verified'   => false,
			'ai_api_credentials_rejected'   => false,
			'ai_api_connection_changed'     => false,
		];

		$rate_limit_count = isset( $post['yoro_rate_limit_count'] )
			? absint( $post['yoro_rate_limit_count'] )
			: 10;
		$rate_limit_window = isset( $post['yoro_rate_limit_window'] )
			? absint( $post['yoro_rate_limit_window'] )
			: 60;
		$undo_ttl = isset( $post['yoro_undo_ttl'] )
			? absint( $post['yoro_undo_ttl'] )
			: 60;
		$max_result_limit = isset( $post['yoro_ai_max_result_limit'] )
			? absint( $post['yoro_ai_max_result_limit'] )
			: 20;
		$posted_ai_api_url = isset( $post['yoro_ai_api_url'] )
			? wp_unslash( $post['yoro_ai_api_url'] )
			: self::DEFAULT_AI_API_URL;
		$posted_ai_api_url = esc_url_raw( $posted_ai_api_url );
		if ( '' === $posted_ai_api_url ) {
			$posted_ai_api_url = self::DEFAULT_AI_API_URL;
		}
		$ai_api_site_key_clear = ! $ai_api_site_key_locked && ! empty( $post['yoro_ai_api_site_key_clear'] );
		$new_ai_api_site_key = '';

		if ( ! $ai_api_site_key_locked ) {
			$new_ai_api_site_key = isset( $post['yoro_ai_api_site_key'] )
				? sanitize_text_field( wp_unslash( $post['yoro_ai_api_site_key'] ) )
				: '';
		}

		$target_ai_api_url      = $ai_api_url_locked ? $previous_ai_api_url : $posted_ai_api_url;
		$target_ai_api_site_key = $previous_ai_api_site_key;

		if ( ! $ai_api_site_key_locked ) {
			if ( $ai_api_site_key_clear ) {
				$target_ai_api_site_key = '';
			} elseif ( '' !== $new_ai_api_site_key ) {
				$target_ai_api_site_key = $new_ai_api_site_key;
			}
		}

		$ai_api_connection_changed = (
			$previous_ai_api_url !== $target_ai_api_url ||
			$previous_ai_api_site_key !== $target_ai_api_site_key
		);
		$ai_api_connection_can_save = true;

		if (
			! $ai_api_site_key_clear &&
			$ai_api_connection_changed &&
			'' !== $target_ai_api_url &&
			'' !== $target_ai_api_site_key
		) {
			$connection_result = self::test_ai_api_credentials( $target_ai_api_url, $target_ai_api_site_key );

			if ( is_wp_error( $connection_result ) ) {
				$ai_api_connection_can_save            = false;
				$result['ai_api_connection_error']     = $connection_result;
				$result['ai_api_credentials_rejected'] = true;
				$result['ai_api_connection_changed']   = true;
			} else {
				$result['ai_api_connection_result']       = $connection_result;
				$result['ai_api_credentials_verified']    = true;
				$result['ai_api_connection_changed']      = true;
			}
		}

		update_option( 'yoro_ai_email_compose_enabled', isset( $post['yoro_ai_email_compose_enabled'] ) ? 'yes' : 'no' );
		delete_option( 'yoro_ai_parser_provider' );
		delete_option( 'yoro_ai_confidence_threshold' );
		delete_option( 'yoro_ai_read_only_confidence_threshold' );
		delete_option( 'yoro_ai_preview_threshold' );
		delete_option( 'yoro_ai_command_router_enabled' );
		delete_option( 'yoro_memory_enabled' );
		update_option( 'yoro_rate_limit_count', max( 1, $rate_limit_count ) );
		update_option( 'yoro_rate_limit_window', max( 10, $rate_limit_window ) );
		update_option( 'yoro_undo_ttl', $undo_ttl );
		update_option( 'yoro_ai_max_result_limit', min( max( $max_result_limit, 1 ), 100 ) );
		delete_option( 'yoro_workflow_ttl' );
		if ( ! $ai_api_url_locked && $ai_api_connection_can_save ) {
			update_option( self::OPTION_AI_API_URL, $target_ai_api_url );
		}
		update_option(
			self::OPTION_AI_API_ENABLED,
			! empty( $post['yoro_ai_api_enabled'] ) ? 'yes' : 'no'
		);

		delete_option( 'yoro_' . 'open' . 'ai_api_key' );
		delete_option( 'yoro_' . 'open' . 'ai_model' );

		if ( ! $ai_api_site_key_locked && $ai_api_connection_can_save ) {
			if ( $ai_api_site_key_clear ) {
				update_option( self::OPTION_AI_API_SITE_KEY, '', false );
			} elseif ( '' !== $new_ai_api_site_key ) {
				update_option( self::OPTION_AI_API_SITE_KEY, $new_ai_api_site_key, false );
			}
		}

		if ( $ai_api_connection_can_save && $ai_api_connection_changed ) {
			self::clear_ai_api_status();

			if ( is_array( $result['ai_api_connection_result'] ) ) {
				self::update_ai_api_status( $result['ai_api_connection_result'] );
			}
		}

		return $result;
	}

	private static function test_ai_api_credentials( $api_url, $site_key ) {
		return ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $site_key, false );
	}

	public static function save_connected_ai_api_credentials( $api_url, $site_key ) {
		if ( self::is_ai_api_site_key_locked() ) {
			return new WP_Error(
				'yoro_ai_api_site_key_locked',
				__( 'YoRo AI API site key is configured by constant and cannot be replaced from the plugin UI.', 'yoro-ai-core' )
			);
		}

		$target_ai_api_url = self::is_ai_api_url_locked() ? self::get_ai_api_url() : esc_url_raw( (string) $api_url );
		$target_site_key   = sanitize_text_field( (string) $site_key );

		if ( '' === $target_ai_api_url || '' === $target_site_key ) {
			return new WP_Error(
				'yoro_ai_api_missing_connect_credentials',
				__( 'YoRo did not return a complete connection payload.', 'yoro-ai-core' )
			);
		}

		$connection_result = self::test_ai_api_credentials( $target_ai_api_url, $target_site_key );

		if ( is_wp_error( $connection_result ) ) {
			return $connection_result;
		}

		if ( ! self::is_ai_api_url_locked() ) {
			update_option( self::OPTION_AI_API_URL, $target_ai_api_url );
		}

		update_option( self::OPTION_AI_API_SITE_KEY, $target_site_key, false );
		update_option( self::OPTION_AI_API_ENABLED, 'yes' );
		self::update_ai_api_status( $connection_result );

		return $connection_result;
	}

	private static function sanitize_ai_api_status_value( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = [];

			foreach ( $value as $key => $item ) {
				$sanitized[ sanitize_key( (string) $key ) ] = self::sanitize_ai_api_status_value( $item );
			}

			return $sanitized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	private static function normalize_ai_api_status( array $status ) {
		$status = self::sanitize_ai_api_status_value( $status );

		if ( empty( $status['entitlements'] ) || ! is_array( $status['entitlements'] ) ) {
			return $status;
		}

		$entitlements = $status['entitlements'];

		if (
			empty( $entitlements['conversation'] ) &&
			( ! empty( $entitlements['command_router'] ) || ! empty( $entitlements['ai_router'] ) )
		) {
			$entitlements['conversation'] = true;
		}

		unset( $entitlements['command_router'], $entitlements['ai_router'], $entitlements['workflows'] );

		$status['entitlements'] = $entitlements;

		return $status;
	}

	private static function normalize_ai_api_feature( $feature ) {
		$feature = sanitize_key( (string) $feature );

		switch ( $feature ) {
			case 'conversation':
				return 'conversation';
			case 'email':
			case 'email_compose':
				return 'email_composer';
			default:
				return $feature;
		}
	}
}
