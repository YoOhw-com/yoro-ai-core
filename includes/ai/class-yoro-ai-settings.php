<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Settings {

	const OPTION_AI_API_URL = 'yoro_ai_api_url';
	const OPTION_AI_API_SITE_KEY = 'yoro_ai_api_site_key';
	const OPTION_AI_API_ENABLED = 'yoro_ai_api_enabled';
	const OPTION_AI_API_STATUS = 'yoro_ai_api_status';
	const OPTION_INSTALLATION_UUID = 'yoro_ai_installation_uuid';
	const DEFAULT_AI_API_URL = '';

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

	public static function get_ai_api_environment() {
		$environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

		if ( 'local' === $environment ) {
			return 'development';
		}

		return in_array( $environment, [ 'production', 'staging', 'development' ], true )
			? $environment
			: 'production';
	}

	public static function get_ai_api_dashboard_url() {
		$api_url = untrailingslashit( self::get_ai_api_url() );

		if ( '' === $api_url ) {
			return '';
		}

		$base_url = preg_replace( '#/api/v[0-9]+(?:/.*)?$#', '', $api_url );

		if ( $base_url === $api_url ) {
			$base_url = preg_replace(
				'#/(?:sites/handshake|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|cancel)|email/(?:gmail/(?:connect-intents|disconnect)|inbox(?:/sync)?|threads/[^/]+(?:/reply)?))$#',
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
				'#/(?:sites/handshake|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|cancel)|connect/wordpress/(?:intents|exchange)|email/(?:gmail/(?:connect-intents|disconnect)|inbox(?:/sync)?|threads/[^/]+(?:/reply)?))$#',
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

		return self::decrypt_ai_api_site_key( (string) get_option( self::OPTION_AI_API_SITE_KEY, '' ) );
	}

	public static function get_installation_uuid() {
		$uuid = sanitize_text_field( (string) get_option( self::OPTION_INSTALLATION_UUID, '' ) );

		if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid ) ) {
			return strtolower( $uuid );
		}

		$uuid = wp_generate_uuid4();
		update_option( self::OPTION_INSTALLATION_UUID, $uuid, false );

		return $uuid;
	}

	public static function create_wordpress_connect_proof() {
		$state         = wp_generate_password( 48, false, false );
		$code_verifier = wp_generate_password( 64, false, false );

		return [
			'state'             => $state,
			'code_verifier'     => $code_verifier,
			'code_challenge'    => rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' ),
			'installation_uuid' => self::get_installation_uuid(),
		];
	}

	public static function is_ai_api_enabled() {
		return 'yes' === get_option( self::OPTION_AI_API_ENABLED, 'yes' );
	}

	public static function get_ai_api_status() {
		$status = get_option( self::OPTION_AI_API_STATUS, [] );

		return is_array( $status ) ? self::normalize_ai_api_status( $status ) : [];
	}

	public static function get_early_access_onboarding( $status = null ) {
		$status = is_array( $status ) ? $status : self::get_ai_api_status();
		return self::normalize_early_access_onboarding( $status['early_access_onboarding'] ?? null, $status );
	}

	/** Validate a read-only API projection; this never grants runtime access. */
	public static function normalize_early_access_onboarding( $input, array $context, $api_url = null, $site_key = null ) {
		$unknown = [ 'status' => 'unknown', 'source' => 'api_handshake', 'connection_healthy' => false,
			'compatible' => false, 'checked_at' => '', 'expires_at' => '', 'future_endpoint' => '', 'runtime_enabled' => false ];
		$keys = [ 'status', 'source', 'site_id', 'installation_uuid', 'connection_healthy', 'compatible', 'checked_at', 'expires_at', 'future_endpoint', 'runtime_enabled', 'server_url', 'grant_status' ];
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) || array_diff( array_diff( $keys, [ 'server_url', 'grant_status' ] ), array_keys( $input ) ) ) {
			return $unknown;
		}
		$api_url = null === $api_url ? self::get_ai_api_url() : $api_url;
		$site_key = null === $site_key ? self::get_ai_api_site_key() : $site_key;
		$connection = $context['connection'] ?? [];
		$site = $context['site'] ?? [];
		$credential = $context['credential'] ?? [];
		$manifest = $context['connector_runtime']['capability_manifest'] ?? [];
		$checked = is_string( $input['checked_at'] ) ? strtotime( $input['checked_at'] ) : false;
		$expires = is_string( $input['expires_at'] ) ? strtotime( $input['expires_at'] ) : false;
		$base = preg_replace( '#/api/v[0-9]+(?:/.*)?$#', '', untrailingslashit( (string) $api_url ) );
		$endpoint = is_string( $input['future_endpoint'] ) ? wp_parse_url( $input['future_endpoint'] ) : false;
		$valid_endpoint = '' === $input['future_endpoint'] || ( is_array( $endpoint ) && 'https' === ( $endpoint['scheme'] ?? '' )
			&& ! isset( $endpoint['user'] ) && ! isset( $endpoint['pass'] ) && ! isset( $endpoint['query'] ) && ! isset( $endpoint['fragment'] )
			&& $input['future_endpoint'] === $base . '/mcp/early-access' );
		if ( ! in_array( $input['status'], [ 'unknown', 'available', 'not_available', 'requested_pending', 'withdrawn' ], true )
			|| 'api_handshake' !== $input['source'] || ! is_bool( $input['runtime_enabled'] )
            || ( true === $input['runtime_enabled'] && ( ! in_array( $input['status'], [ 'available', 'requested_pending', 'withdrawn' ], true )
                || ! is_string( $input['server_url'] ?? null ) || '' === $input['server_url'] || $input['server_url'] !== $input['future_endpoint'] ) )
            || ( isset( $input['server_url'] ) && ( ! is_string( $input['server_url'] ) || ( false === $input['runtime_enabled'] && '' !== $input['server_url'] ) ) )
            || ( isset( $input['grant_status'] ) && ! in_array( $input['grant_status'], [ 'unknown', 'not_granted', 'granted', 'revoked' ], true ) )
			|| ! is_bool( $input['connection_healthy'] ) || ! is_bool( $input['compatible'] )
			|| ! is_int( $input['site_id'] ) || $input['site_id'] <= 0 || $input['site_id'] !== ( $site['id'] ?? null )
			|| ! is_string( $input['installation_uuid'] ) || ! hash_equals( self::get_installation_uuid(), $input['installation_uuid'] )
			|| $input['installation_uuid'] !== ( $connection['installation_uuid'] ?? '' )
			|| rtrim( strtolower( (string) ( $site['url'] ?? '' ) ), '/' ) !== rtrim( strtolower( home_url() ), '/' )
			|| empty( $context['account_connection']['connected'] ) || 'active' !== ( $connection['status'] ?? '' )
			|| 'wordpress_connection' !== ( $credential['type'] ?? '' ) || empty( $credential['id'] )
			|| ( $credential['site_connection_id'] ?? 0 ) !== ( $connection['id'] ?? 0 )
			|| ( ! empty( $credential['expires_at'] ) && strtotime( $credential['expires_at'] ) <= time() )
			|| ( isset( $context['service_available'] ) && false === $context['service_available'] )
			|| ! is_string( $site_key ) || '' === $site_key || ! hash_equals( hash( 'sha256', $site_key ), (string) ( $context['early_access_credential_fingerprint'] ?? '' ) )
			|| false === $checked || false === $expires || $checked > time() + 30 || $checked < time() - 300 || $expires <= time() || $expires > $checked + 300
			|| ! $valid_endpoint ) {
			return $unknown;
		}
		if ( in_array( $input['status'], [ 'available', 'requested_pending', 'withdrawn' ], true )
			&& ( true !== $input['connection_healthy'] || true !== $input['compatible'] || empty( $manifest['current'] )
				|| empty( $manifest['manifest_id'] ) || empty( $manifest['revision'] ) || strtotime( $manifest['expires_at'] ?? '' ) < $expires
				|| ! defined( 'YORO_AI_CORE_VERSION' ) || ( $connection['plugin_version'] ?? '' ) !== YORO_AI_CORE_VERSION
				|| ( $context['connector_runtime']['connector_version'] ?? '' ) !== YORO_AI_CORE_VERSION
				|| 1 !== ( $context['connector_runtime']['protocol_version'] ?? null ) || '' === $input['future_endpoint'] ) ) {
			return $unknown;
		}
		return array_intersect_key( $input, array_flip( $keys ) );
	}

	public static function get_ai_api_account_connection( $status = null ) {
		$status = is_array( $status ) ? self::normalize_ai_api_status( $status ) : self::get_ai_api_status();
		$account_connection = isset( $status['account_connection'] ) && is_array( $status['account_connection'] )
			? $status['account_connection']
			: [];
		$organization = isset( $status['organization'] ) && is_array( $status['organization'] ) ? $status['organization'] : [];
		$connection   = isset( $status['connection'] ) && is_array( $status['connection'] ) ? $status['connection'] : [];
		$credential   = isset( $status['credential'] ) && is_array( $status['credential'] ) ? $status['credential'] : [];
		$site         = isset( $status['site'] ) && is_array( $status['site'] ) ? $status['site'] : [];
		$connection_id = absint( $connection['id'] ?? 0 );
		$installation_uuid = sanitize_text_field( $connection['installation_uuid'] ?? '' );
		$local_installation_uuid = self::get_installation_uuid();
		$credential_expires_at = sanitize_text_field( $credential['expires_at'] ?? '' );
		$credential_expires = '' !== $credential_expires_at ? strtotime( $credential_expires_at ) : false;
		$credential_active = false === $credential_expires || $credential_expires > time();

		$connected = ! empty( $account_connection['connected'] )
			&& 'active' === sanitize_key( $account_connection['status'] ?? '' )
			&& absint( $organization['id'] ?? 0 ) > 0
			&& absint( $site['id'] ?? 0 ) > 0
			&& $connection_id > 0
			&& 'active' === sanitize_key( $connection['status'] ?? '' )
			&& '' !== $installation_uuid
			&& hash_equals( $local_installation_uuid, $installation_uuid )
			&& absint( $credential['id'] ?? 0 ) > 0
			&& $credential_active
			&& 'wordpress_connection' === sanitize_key( $credential['type'] ?? '' )
			&& $connection_id === absint( $credential['site_connection_id'] ?? 0 );

		return [
			'required'          => true,
			'connected'         => $connected,
			'status'            => $connected ? 'active' : sanitize_key( $account_connection['status'] ?? 'account_required' ),
			'message'           => sanitize_text_field( $account_connection['message'] ?? ( $connected ? '' : __( 'Connect this site to a YoRo account to use YoRo AI.', 'yoro-ai-core' ) ) ),
			'service_available' => ! array_key_exists( 'service_available', $status ) || ! empty( $status['service_available'] ),
		];
	}

	public static function is_ai_api_account_connected( $status = null ) {
		$account_connection = self::get_ai_api_account_connection( $status );

		return ! empty( $account_connection['connected'] );
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

		if ( ! self::is_ai_api_account_connected() ) {
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

	public static function invalidate_early_access_onboarding() {
		$status = get_option( self::OPTION_AI_API_STATUS, [] );
		if ( is_array( $status ) ) {
			unset( $status['early_access_onboarding'], $status['early_access_credential_fingerprint'] );
			self::update_ai_api_status( $status );
		}
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
			'ai_api_account_connection'  => self::get_ai_api_account_connection(),
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
				self::invalidate_early_access_onboarding();
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
				$encrypted_site_key = self::encrypt_ai_api_site_key( $new_ai_api_site_key );

				if ( is_wp_error( $encrypted_site_key ) ) {
					$ai_api_connection_can_save = false;
					$result['ai_api_connection_error'] = $encrypted_site_key;
					self::invalidate_early_access_onboarding();
				$result['ai_api_credentials_rejected'] = true;
				} else {
					update_option( self::OPTION_AI_API_SITE_KEY, $encrypted_site_key, false );
				}
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
		$result = ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $site_key, false );

		if ( is_wp_error( $result ) || self::is_ai_api_account_connected( $result ) ) {
			return $result;
		}

		return new WP_Error(
			'yoro_ai_account_connection_required',
			__( 'This credential is not linked to an active YoRo account connection. Use Connect YoRo Account instead.', 'yoro-ai-core' )
		);
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
			self::invalidate_early_access_onboarding();
			return new WP_Error(
				'yoro_ai_api_missing_connect_credentials',
				__( 'YoRo did not return a complete connection payload.', 'yoro-ai-core' )
			);
		}

		$connection_result = self::test_ai_api_credentials( $target_ai_api_url, $target_site_key );

		if ( is_wp_error( $connection_result ) ) {
			self::invalidate_early_access_onboarding();
			return $connection_result;
		}

		if ( ! self::is_ai_api_url_locked() ) {
			update_option( self::OPTION_AI_API_URL, $target_ai_api_url );
		}

		$encrypted_site_key = self::encrypt_ai_api_site_key( $target_site_key );

		if ( is_wp_error( $encrypted_site_key ) ) {
			self::invalidate_early_access_onboarding();
			return $encrypted_site_key;
		}

		update_option( self::OPTION_AI_API_SITE_KEY, $encrypted_site_key, false );
		update_option( self::OPTION_AI_API_ENABLED, 'yes' );
		if ( ! hash_equals( $target_site_key, (string) self::get_ai_api_site_key() )
			|| $target_ai_api_url !== self::get_ai_api_url() || ! self::is_ai_api_enabled() ) {
			unset( $connection_result['early_access_onboarding'], $connection_result['early_access_credential_fingerprint'] );
			self::invalidate_early_access_onboarding();
		}
		self::update_ai_api_status( $connection_result );

		return $connection_result;
	}

	public static function disconnect_ai_api() {
		if ( self::is_ai_api_site_key_locked() ) {
			return new WP_Error(
				'yoro_ai_api_site_key_locked',
				__( 'YoRo AI API site key is configured by constant and cannot be disconnected from the plugin UI.', 'yoro-ai-core' )
			);
		}

		$site_key = self::get_ai_api_site_key();

		if ( '' !== $site_key ) {
			$disconnect_result = ( new YoRo_AI_API_Connection_Client() )->disconnect_site(
				[
					'installation_uuid' => self::get_installation_uuid(),
					'reason'            => 'Disconnected by a WordPress administrator.',
				]
			);

			if ( is_wp_error( $disconnect_result ) ) {
				return $disconnect_result;
			}
		}

		update_option( self::OPTION_AI_API_SITE_KEY, '', false );
		update_option( self::OPTION_AI_API_ENABLED, 'no' );
		self::clear_ai_api_status();

		return true;
	}

	private static function encrypt_ai_api_site_key( $site_key ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error(
				'yoro_ai_api_encryption_unavailable',
				__( 'The server cannot securely store the YoRo site credential. Enable OpenSSL and reconnect.', 'yoro-ai-core' )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Exception $exception ) {
			return new WP_Error( 'yoro_ai_api_encryption_failed', __( 'YoRo could not create secure credential storage.', 'yoro-ai-core' ) );
		}

		$tag        = '';
		$key        = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
		$ciphertext = openssl_encrypt( (string) $site_key, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return new WP_Error( 'yoro_ai_api_encryption_failed', __( 'YoRo could not securely store the site credential.', 'yoro-ai-core' ) );
		}

		return 'yoro_enc_v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	private static function decrypt_ai_api_site_key( $stored ) {
		$prefix = 'yoro_enc_v1:';

		if ( 0 !== strpos( (string) $stored, $prefix ) ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG ? sanitize_text_field( (string) $stored ) : '';
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$payload = base64_decode( substr( (string) $stored, strlen( $prefix ) ), true );

		if ( false === $payload || strlen( $payload ) < 29 ) {
			return '';
		}

		$iv         = substr( $payload, 0, 12 );
		$tag        = substr( $payload, 12, 16 );
		$ciphertext = substr( $payload, 28 );
		$key        = hash( 'sha256', wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
		$plaintext  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : sanitize_text_field( $plaintext );
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
		$status['early_access_onboarding'] = self::normalize_early_access_onboarding( $status['early_access_onboarding'] ?? null, $status );
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
