<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YORO_AI_PROVIDER_YORO_AI_API' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_AI_API', 'yoro_ai_api' );
}

if ( ! defined( 'YORO_AI_PROVIDER_YORO_CLOUD' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_CLOUD', 'yoro_cloud' );
}

class YoRo_AI_Settings {

	const OPTION_AI_API_URL = 'yoro_ai_api_url';
	const OPTION_AI_API_SITE_KEY = 'yoro_ai_api_site_key';
	const OPTION_AI_API_ENABLED = 'yoro_ai_api_enabled';
	const OPTION_AI_API_ENDPOINT_READY = 'yoro_ai_api_endpoint_ready';
	const OPTION_AI_API_STATUS = 'yoro_ai_api_status';

	const LEGACY_OPTION_CLOUD_API_URL = 'yoro_cloud_api_url';
	const LEGACY_OPTION_CLOUD_SITE_KEY = 'yoro_cloud_site_key';
	const LEGACY_OPTION_CLOUD_ENABLED = 'yoro_cloud_parser_enabled';
	const LEGACY_OPTION_CLOUD_ENDPOINT_READY = 'yoro_cloud_endpoint_ready';
	const LEGACY_OPTION_CLOUD_STATUS = 'yoro_cloud_status';

	public static function get_ai_api_site_key_source() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return 'constant';
		}

		if ( defined( 'YORO_CLOUD_SITE_KEY' ) && YORO_CLOUD_SITE_KEY ) {
			return 'constant';
		}

		$stored = (string) get_option( self::OPTION_AI_API_SITE_KEY, '' );

		if ( '' !== trim( $stored ) ) {
			return 'option';
		}

		$legacy_stored = (string) get_option( self::LEGACY_OPTION_CLOUD_SITE_KEY, '' );

		if ( '' !== trim( $legacy_stored ) ) {
			return 'option';
		}

		return 'missing';
	}

	public static function get_cloud_site_key_source() {
		return self::get_ai_api_site_key_source();
	}

	public static function get_provider_preference() {
		return YORO_AI_PROVIDER_YORO_AI_API;
	}

	public static function get_ai_confidence_threshold() {
		return max( 0, min( (float) get_option( 'yoro_ai_confidence_threshold', 0.75 ), 1 ) );
	}

	public static function get_ai_read_only_confidence_threshold() {
		return max( 0, min( (float) get_option( 'yoro_ai_read_only_confidence_threshold', 0.6 ), 1 ) );
	}

	public static function get_ai_preview_threshold() {
		return max( 0, min( (float) get_option( 'yoro_ai_preview_threshold', 0.8 ), 1 ) );
	}

	public static function get_ai_api_url() {
		if ( defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL ) {
			return esc_url_raw( YORO_AI_API_URL );
		}

		if ( defined( 'YORO_CLOUD_API_URL' ) && YORO_CLOUD_API_URL ) {
			return esc_url_raw( YORO_CLOUD_API_URL );
		}

		return esc_url_raw(
			(string) self::get_option_with_legacy(
				self::OPTION_AI_API_URL,
				self::LEGACY_OPTION_CLOUD_API_URL,
				''
			)
		);
	}

	public static function get_cloud_api_url() {
		return self::get_ai_api_url();
	}

	public static function get_ai_api_site_key() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return YORO_AI_API_SITE_KEY;
		}

		if ( defined( 'YORO_CLOUD_SITE_KEY' ) && YORO_CLOUD_SITE_KEY ) {
			return YORO_CLOUD_SITE_KEY;
		}

		return sanitize_text_field(
			(string) self::get_option_with_legacy(
				self::OPTION_AI_API_SITE_KEY,
				self::LEGACY_OPTION_CLOUD_SITE_KEY,
				''
			)
		);
	}

	public static function get_cloud_site_key() {
		return self::get_ai_api_site_key();
	}

	public static function is_ai_api_enabled() {
		return 'yes' === self::get_option_with_legacy(
			self::OPTION_AI_API_ENABLED,
			self::LEGACY_OPTION_CLOUD_ENABLED,
			'no'
		);
	}

	public static function is_cloud_enabled() {
		return self::is_ai_api_enabled();
	}

	public static function is_ai_api_endpoint_ready() {
		return 'yes' === self::get_option_with_legacy(
			self::OPTION_AI_API_ENDPOINT_READY,
			self::LEGACY_OPTION_CLOUD_ENDPOINT_READY,
			'no'
		);
	}

	public static function is_cloud_endpoint_ready() {
		return self::is_ai_api_endpoint_ready();
	}

	public static function get_ai_api_status() {
		$status = get_option( self::OPTION_AI_API_STATUS, null );

		if ( is_array( $status ) && [] !== $status ) {
			return $status;
		}

		$status = get_option( self::LEGACY_OPTION_CLOUD_STATUS, [] );

		return is_array( $status ) ? $status : [];
	}

	public static function get_cloud_status() {
		return self::get_ai_api_status();
	}

	public static function get_ai_api_entitlements() {
		$status = self::get_ai_api_status();
		$entitlements = $status['entitlements'] ?? [];

		return is_array( $entitlements ) ? $entitlements : [];
	}

	public static function get_cloud_entitlements() {
		return self::get_ai_api_entitlements();
	}

	public static function get_ai_api_usage() {
		$status = self::get_ai_api_status();
		$usage = $status['usage'] ?? [];

		return is_array( $usage ) ? $usage : [];
	}

	public static function get_cloud_usage() {
		return self::get_ai_api_usage();
	}

	public static function ai_api_allows_feature( $feature ) {
		$feature = self::normalize_cloud_feature( $feature );

		if ( '' === $feature ) {
			return false;
		}

		$entitlements = self::get_ai_api_entitlements();

		if ( [] === $entitlements ) {
			return true;
		}

		if ( array_key_exists( $feature, $entitlements ) ) {
			return ! empty( $entitlements[ $feature ] );
		}

		if ( 'command_router' === $feature && array_key_exists( 'ai_router', $entitlements ) ) {
			return ! empty( $entitlements['ai_router'] );
		}

		return false;
	}

	public static function cloud_allows_feature( $feature ) {
		return self::ai_api_allows_feature( $feature );
	}

	public static function ai_api_has_quota_remaining() {
		$usage = self::get_ai_api_usage();

		if ( ! array_key_exists( 'remaining', $usage ) || null === $usage['remaining'] ) {
			return true;
		}

		return absint( $usage['remaining'] ) > 0;
	}

	public static function cloud_has_quota_remaining() {
		return self::ai_api_has_quota_remaining();
	}

	public static function update_ai_api_status( array $status ) {
		update_option( self::OPTION_AI_API_STATUS, self::sanitize_cloud_status_value( $status ), false );
	}

	public static function update_cloud_status( array $status ) {
		self::update_ai_api_status( $status );
	}

	public static function clear_ai_api_status() {
		delete_option( self::OPTION_AI_API_STATUS );
		delete_option( self::LEGACY_OPTION_CLOUD_STATUS );
	}

	public static function clear_cloud_status() {
		self::clear_ai_api_status();
	}

	public static function is_ai_api_url_locked() {
		return ( defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL ) || ( defined( 'YORO_CLOUD_API_URL' ) && YORO_CLOUD_API_URL );
	}

	public static function is_ai_api_site_key_locked() {
		return 'constant' === self::get_ai_api_site_key_source();
	}

	public static function get_ai_api_url_constant_name() {
		if ( defined( 'YORO_AI_API_URL' ) && YORO_AI_API_URL ) {
			return 'YORO_AI_API_URL';
		}

		if ( defined( 'YORO_CLOUD_API_URL' ) && YORO_CLOUD_API_URL ) {
			return 'YORO_CLOUD_API_URL';
		}

		return '';
	}

	public static function get_ai_api_site_key_constant_name() {
		if ( defined( 'YORO_AI_API_SITE_KEY' ) && YORO_AI_API_SITE_KEY ) {
			return 'YORO_AI_API_SITE_KEY';
		}

		if ( defined( 'YORO_CLOUD_SITE_KEY' ) && YORO_CLOUD_SITE_KEY ) {
			return 'YORO_CLOUD_SITE_KEY';
		}

		return '';
	}

	public static function migrate_legacy_ai_api_options( $delete_legacy = false ) {
		self::copy_legacy_option_if_missing( self::OPTION_AI_API_URL, self::LEGACY_OPTION_CLOUD_API_URL, false );
		self::copy_legacy_option_if_missing( self::OPTION_AI_API_SITE_KEY, self::LEGACY_OPTION_CLOUD_SITE_KEY, false );
		self::copy_legacy_option_if_missing( self::OPTION_AI_API_ENABLED, self::LEGACY_OPTION_CLOUD_ENABLED, true );
		self::copy_legacy_option_if_missing( self::OPTION_AI_API_ENDPOINT_READY, self::LEGACY_OPTION_CLOUD_ENDPOINT_READY, true );
		self::copy_legacy_option_if_missing( self::OPTION_AI_API_STATUS, self::LEGACY_OPTION_CLOUD_STATUS, false );

		if ( $delete_legacy ) {
			self::delete_legacy_ai_api_options();
		}
	}

	public static function get_all() {
		self::migrate_legacy_ai_api_options();

		return [
			'provider'                 => self::get_provider_preference(),
			'ai_confidence_threshold'  => self::get_ai_confidence_threshold(),
			'ai_read_only_confidence_threshold' => self::get_ai_read_only_confidence_threshold(),
			'ai_preview_threshold'     => self::get_ai_preview_threshold(),
			'ai_suggestions_enabled'   => get_option( 'yoro_ai_suggestions_enabled', 'no' ),
			'ai_email_compose_enabled' => get_option( 'yoro_ai_email_compose_enabled', 'yes' ),
			'ai_command_router_enabled' => get_option( 'yoro_ai_command_router_enabled', 'yes' ),
			'ai_action_decomposition_enabled' => get_option( 'yoro_ai_action_decomposition_enabled', 'yes' ),
			'ai_dynamic_workflows_enabled' => get_option( 'yoro_ai_dynamic_workflows_enabled', 'no' ),
			'memory_enabled'           => get_option( 'yoro_memory_enabled', 'yes' ),
			'suggestions_enabled'      => get_option( 'yoro_enable_suggestions', 'yes' ),
			'ai_max_suggestions'       => absint( get_option( 'yoro_ai_max_suggestions', 4 ) ),
			'workflow_ttl'             => absint( get_option( 'yoro_workflow_ttl', 1800 ) ),
			'rate_limit_count'         => absint( get_option( 'yoro_rate_limit_count', 10 ) ),
			'rate_limit_window'        => absint( get_option( 'yoro_rate_limit_window', 60 ) ),
			'undo_ttl'                 => absint( get_option( 'yoro_undo_ttl', 60 ) ),
			'max_result_limit'         => absint( get_option( 'yoro_ai_max_result_limit', 20 ) ),
			'ai_api_url'               => self::get_ai_api_url(),
			'ai_api_url_locked'        => self::is_ai_api_url_locked(),
			'ai_api_url_constant'      => self::get_ai_api_url_constant_name(),
			'ai_api_site_key_configured' => '' !== self::get_ai_api_site_key(),
			'ai_api_site_key_source'   => self::get_ai_api_site_key_source(),
			'ai_api_site_key_locked'   => self::is_ai_api_site_key_locked(),
			'ai_api_site_key_constant' => self::get_ai_api_site_key_constant_name(),
			'ai_api_enabled'           => self::is_ai_api_enabled() ? 'yes' : 'no',
			'ai_api_endpoint_ready'    => self::is_ai_api_endpoint_ready() ? 'yes' : 'no',
			'ai_api_status'            => self::get_ai_api_status(),
			'cloud_api_url'            => self::get_ai_api_url(),
			'cloud_site_key_configured' => '' !== self::get_ai_api_site_key(),
			'cloud_site_key_source'    => self::get_ai_api_site_key_source(),
			'cloud_parser_enabled'     => self::is_ai_api_enabled() ? 'yes' : 'no',
			'cloud_endpoint_ready'     => self::is_ai_api_endpoint_ready() ? 'yes' : 'no',
			'cloud_status'             => self::get_ai_api_status(),
		];
	}

	public static function save_admin_settings( array $post ) {
		self::migrate_legacy_ai_api_options();

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
			'cloud_connection_result'       => null,
			'cloud_connection_error'        => null,
			'cloud_credentials_verified'    => false,
			'cloud_credentials_rejected'    => false,
			'cloud_connection_changed'      => false,
		];

		$confidence = isset( $post['yoro_ai_confidence_threshold'] )
			? (float) wp_unslash( $post['yoro_ai_confidence_threshold'] )
			: 0.75;
		$read_only_confidence = isset( $post['yoro_ai_read_only_confidence_threshold'] )
			? (float) wp_unslash( $post['yoro_ai_read_only_confidence_threshold'] )
			: 0.6;
		$preview_threshold = isset( $post['yoro_ai_preview_threshold'] )
			? (float) wp_unslash( $post['yoro_ai_preview_threshold'] )
			: 0.8;
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
		$workflow_ttl = isset( $post['yoro_workflow_ttl'] )
			? absint( $post['yoro_workflow_ttl'] )
			: 1800;
		$ai_max_suggestions = isset( $post['yoro_ai_max_suggestions'] )
			? absint( $post['yoro_ai_max_suggestions'] )
			: 4;
		$posted_ai_api_url = self::get_posted_value(
			$post,
			'yoro_ai_api_url',
			'yoro_cloud_api_url',
			''
		);
		$posted_ai_api_url = esc_url_raw( $posted_ai_api_url );
		$ai_api_site_key_clear = ! $ai_api_site_key_locked && self::is_posted_checkbox_checked(
			$post,
			'yoro_ai_api_site_key_clear',
			'yoro_cloud_site_key_clear'
		);
		$new_ai_api_site_key = '';

		if ( ! $ai_api_site_key_locked ) {
			$new_ai_api_site_key = sanitize_text_field(
				self::get_posted_value(
					$post,
					'yoro_ai_api_site_key',
					'yoro_cloud_site_key',
					''
				)
			);
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
			$connection_result = self::test_cloud_credentials( $target_ai_api_url, $target_ai_api_site_key );

			if ( is_wp_error( $connection_result ) ) {
				$ai_api_connection_can_save                 = false;
				$result['ai_api_connection_error']          = $connection_result;
				$result['ai_api_credentials_rejected']      = true;
				$result['ai_api_connection_changed']        = true;
				$result['cloud_connection_error']           = $connection_result;
				$result['cloud_credentials_rejected']       = true;
				$result['cloud_connection_changed']         = true;
			} else {
				$result['ai_api_connection_result']         = $connection_result;
				$result['ai_api_credentials_verified']      = true;
				$result['ai_api_connection_changed']        = true;
				$result['cloud_connection_result']          = $connection_result;
				$result['cloud_credentials_verified']       = true;
				$result['cloud_connection_changed']         = true;
			}
		}

		update_option( 'yoro_ai_parser_provider', YORO_AI_PROVIDER_YORO_AI_API );
		update_option( 'yoro_ai_confidence_threshold', max( 0, min( $confidence, 1 ) ) );
		update_option( 'yoro_ai_read_only_confidence_threshold', max( 0, min( $read_only_confidence, 1 ) ) );
		update_option( 'yoro_ai_preview_threshold', max( 0, min( $preview_threshold, 1 ) ) );
		update_option( 'yoro_ai_suggestions_enabled', isset( $post['yoro_ai_suggestions_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_enable_suggestions', isset( $post['yoro_enable_suggestions'] ) ? 'yes' : 'no' );
		update_option( 'yoro_ai_max_suggestions', min( max( $ai_max_suggestions, 1 ), 10 ) );
		update_option( 'yoro_ai_email_compose_enabled', isset( $post['yoro_ai_email_compose_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_ai_command_router_enabled', isset( $post['yoro_ai_command_router_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_ai_action_decomposition_enabled', isset( $post['yoro_ai_action_decomposition_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_ai_dynamic_workflows_enabled', isset( $post['yoro_ai_dynamic_workflows_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_memory_enabled', isset( $post['yoro_memory_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_rate_limit_count', max( 1, $rate_limit_count ) );
		update_option( 'yoro_rate_limit_window', max( 10, $rate_limit_window ) );
		update_option( 'yoro_undo_ttl', $undo_ttl );
		update_option( 'yoro_ai_max_result_limit', min( max( $max_result_limit, 1 ), 100 ) );
		update_option( 'yoro_workflow_ttl', max( $workflow_ttl, 30 ) );
		if ( ! $ai_api_url_locked && $ai_api_connection_can_save ) {
			update_option( self::OPTION_AI_API_URL, $target_ai_api_url );
		}
		update_option(
			self::OPTION_AI_API_ENABLED,
			self::is_posted_checkbox_checked( $post, 'yoro_ai_api_enabled', 'yoro_cloud_parser_enabled' ) ? 'yes' : 'no'
		);
		update_option(
			self::OPTION_AI_API_ENDPOINT_READY,
			self::is_posted_checkbox_checked( $post, 'yoro_ai_api_endpoint_ready', 'yoro_cloud_endpoint_ready' ) ? 'yes' : 'no'
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

		if ( $ai_api_connection_can_save ) {
			self::delete_legacy_ai_api_options();
		}

		return $result;
	}

	private static function test_cloud_credentials( $api_url, $site_key ) {
		if ( ! class_exists( 'YoRo_AI_API_Client' ) ) {
			return new WP_Error(
				'yoro_ai_api_client_missing',
				__( 'YoRo AI API client is not available.', 'yoro-ai-core' )
			);
		}

		return ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $site_key, false );
	}

	private static function get_option_with_legacy( $option, $legacy_option, $default = '' ) {
		$value = get_option( $option, null );

		if ( null !== $value ) {
			return $value;
		}

		return get_option( $legacy_option, $default );
	}

	private static function copy_legacy_option_if_missing( $option, $legacy_option, $autoload = null ) {
		if ( null !== get_option( $option, null ) ) {
			return;
		}

		$value = get_option( $legacy_option, null );

		if ( null === $value ) {
			return;
		}

		if ( null === $autoload ) {
			update_option( $option, $value );
			return;
		}

		update_option( $option, $value, $autoload );
	}

	private static function delete_legacy_ai_api_options() {
		delete_option( self::LEGACY_OPTION_CLOUD_API_URL );
		delete_option( self::LEGACY_OPTION_CLOUD_SITE_KEY );
		delete_option( self::LEGACY_OPTION_CLOUD_ENABLED );
		delete_option( self::LEGACY_OPTION_CLOUD_ENDPOINT_READY );
		delete_option( self::LEGACY_OPTION_CLOUD_STATUS );
	}

	private static function get_posted_value( array $post, $name, $legacy_name, $default = '' ) {
		if ( isset( $post[ $name ] ) ) {
			return wp_unslash( $post[ $name ] );
		}

		if ( isset( $post[ $legacy_name ] ) ) {
			return wp_unslash( $post[ $legacy_name ] );
		}

		return $default;
	}

	private static function is_posted_checkbox_checked( array $post, $name, $legacy_name ) {
		return ! empty( $post[ $name ] ) || ! empty( $post[ $legacy_name ] );
	}

	private static function normalize_provider_preference( $provider ) {
		return YORO_AI_PROVIDER_YORO_AI_API;
	}

	private static function sanitize_cloud_status_value( $value ) {
		if ( is_array( $value ) ) {
			$sanitized = [];

			foreach ( $value as $key => $item ) {
				$sanitized[ sanitize_key( (string) $key ) ] = self::sanitize_cloud_status_value( $item );
			}

			return $sanitized;
		}

		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}

		return sanitize_text_field( (string) $value );
	}

	private static function normalize_cloud_feature( $feature ) {
		$feature = sanitize_key( (string) $feature );

		switch ( $feature ) {
			case 'parse':
			case 'intent':
			case 'ai_router':
				return 'command_router';
			case 'workflow':
			case 'workflow_plan':
			case 'action_decomposition':
			case 'dynamic_workflows':
				return 'workflows';
			case 'email':
			case 'email_compose':
				return 'email_composer';
			default:
				return $feature;
		}
	}
}
