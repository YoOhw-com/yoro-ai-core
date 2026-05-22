<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YORO_AI_PROVIDER_YORO_AI_API' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_AI_API', defined( 'YORO_AI_PROVIDER_YORO_CLOUD' ) ? YORO_AI_PROVIDER_YORO_CLOUD : 'yoro_cloud' );
}

class YoRo_AI_Settings {

	public static function get_openai_key_source() {
		if ( defined( 'YORO_OPENAI_API_KEY' ) && YORO_OPENAI_API_KEY ) {
			return 'constant';
		}

		$stored = (string) get_option( 'yoro_openai_api_key', '' );

		if ( '' !== trim( $stored ) ) {
			return 'option';
		}

		return 'missing';
	}

	public static function get_cloud_site_key_source() {
		if ( defined( 'YORO_CLOUD_SITE_KEY' ) && YORO_CLOUD_SITE_KEY ) {
			return 'constant';
		}

		$stored = (string) get_option( 'yoro_cloud_site_key', '' );

		if ( '' !== trim( $stored ) ) {
			return 'option';
		}

		return 'missing';
	}

	public static function get_provider_preference() {
		$provider = get_option( 'yoro_ai_parser_provider', YORO_AI_PROVIDER_OPENAI_DIRECT );

		if ( YORO_AI_PROVIDER_YORO_AI_API === $provider ) {
			return YORO_AI_PROVIDER_YORO_AI_API;
		}

		return YORO_AI_PROVIDER_OPENAI_DIRECT;
	}

	public static function get_openai_model() {
		return sanitize_text_field( get_option( 'yoro_openai_model', 'gpt-5-nano' ) );
	}

	public static function get_openai_api_key() {
		if ( defined( 'YORO_OPENAI_API_KEY' ) && YORO_OPENAI_API_KEY ) {
			return YORO_OPENAI_API_KEY;
		}

		return sanitize_text_field( (string) get_option( 'yoro_openai_api_key', '' ) );
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

	public static function get_cloud_api_url() {
		if ( defined( 'YORO_CLOUD_API_URL' ) && YORO_CLOUD_API_URL ) {
			return esc_url_raw( YORO_CLOUD_API_URL );
		}

		return esc_url_raw( (string) get_option( 'yoro_cloud_api_url', '' ) );
	}

	public static function get_cloud_site_key() {
		if ( defined( 'YORO_CLOUD_SITE_KEY' ) && YORO_CLOUD_SITE_KEY ) {
			return YORO_CLOUD_SITE_KEY;
		}

		return sanitize_text_field( (string) get_option( 'yoro_cloud_site_key', '' ) );
	}

	public static function is_cloud_enabled() {
		return 'yes' === get_option( 'yoro_cloud_parser_enabled', 'no' );
	}

	public static function is_cloud_endpoint_ready() {
		return 'yes' === get_option( 'yoro_cloud_endpoint_ready', 'no' );
	}

	public static function get_cloud_status() {
		$status = get_option( 'yoro_cloud_status', [] );

		return is_array( $status ) ? $status : [];
	}

	public static function get_cloud_entitlements() {
		$status = self::get_cloud_status();
		$entitlements = $status['entitlements'] ?? [];

		return is_array( $entitlements ) ? $entitlements : [];
	}

	public static function get_cloud_usage() {
		$status = self::get_cloud_status();
		$usage = $status['usage'] ?? [];

		return is_array( $usage ) ? $usage : [];
	}

	public static function cloud_allows_feature( $feature ) {
		$feature = self::normalize_cloud_feature( $feature );

		if ( '' === $feature ) {
			return false;
		}

		$entitlements = self::get_cloud_entitlements();

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

	public static function cloud_has_quota_remaining() {
		$usage = self::get_cloud_usage();

		if ( ! array_key_exists( 'remaining', $usage ) || null === $usage['remaining'] ) {
			return true;
		}

		return absint( $usage['remaining'] ) > 0;
	}

	public static function update_cloud_status( array $status ) {
		update_option( 'yoro_cloud_status', self::sanitize_cloud_status_value( $status ), false );
	}

	public static function clear_cloud_status() {
		delete_option( 'yoro_cloud_status' );
	}

	public static function has_openai_direct_access() {
		return '' !== self::get_openai_api_key();
	}

	public static function get_all() {
		$key_source = self::get_openai_key_source();

		return [
			'provider'                 => self::get_provider_preference(),
			'openai_model'             => self::get_openai_model(),
			'openai_key_configured'    => self::has_openai_direct_access(),
			'openai_key_source'        => $key_source,
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
			'cloud_api_url'            => self::get_cloud_api_url(),
			'cloud_site_key_configured' => '' !== self::get_cloud_site_key(),
			'cloud_site_key_source'    => self::get_cloud_site_key_source(),
			'cloud_parser_enabled'     => self::is_cloud_enabled() ? 'yes' : 'no',
			'cloud_endpoint_ready'     => self::is_cloud_endpoint_ready() ? 'yes' : 'no',
			'cloud_status'             => self::get_cloud_status(),
		];
	}

	public static function save_admin_settings( array $post ) {
		$previous_cloud_api_url  = self::get_cloud_api_url();
		$previous_cloud_site_key = self::get_cloud_site_key();
		$cloud_api_url_locked    = defined( 'YORO_CLOUD_API_URL' ) && YORO_CLOUD_API_URL;
		$cloud_site_key_locked   = 'constant' === self::get_cloud_site_key_source();

		$result = [
			'cloud_connection_result'      => null,
			'cloud_connection_error'       => null,
			'cloud_credentials_verified'   => false,
			'cloud_credentials_rejected'   => false,
			'cloud_connection_changed'     => false,
		];

		$provider = isset( $post['yoro_ai_parser_provider'] )
			? sanitize_text_field( wp_unslash( $post['yoro_ai_parser_provider'] ) )
			: YORO_AI_PROVIDER_OPENAI_DIRECT;

		if ( ! in_array( $provider, [ YORO_AI_PROVIDER_OPENAI_DIRECT, YORO_AI_PROVIDER_YORO_AI_API ], true ) ) {
			$provider = YORO_AI_PROVIDER_OPENAI_DIRECT;
		}

		$model = isset( $post['yoro_openai_model'] )
			? sanitize_text_field( wp_unslash( $post['yoro_openai_model'] ) )
			: 'gpt-5-nano';

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
		$cloud_api_url = isset( $post['yoro_cloud_api_url'] )
			? esc_url_raw( wp_unslash( $post['yoro_cloud_api_url'] ) )
			: '';
		$cloud_site_key_clear = ! $cloud_site_key_locked && ! empty( $post['yoro_cloud_site_key_clear'] );
		$new_cloud_site_key   = '';

		if ( ! $cloud_site_key_locked && isset( $post['yoro_cloud_site_key'] ) ) {
			$new_cloud_site_key = sanitize_text_field( wp_unslash( $post['yoro_cloud_site_key'] ) );
		}

		$target_cloud_api_url  = $cloud_api_url_locked ? $previous_cloud_api_url : $cloud_api_url;
		$target_cloud_site_key = $previous_cloud_site_key;

		if ( ! $cloud_site_key_locked ) {
			if ( $cloud_site_key_clear ) {
				$target_cloud_site_key = '';
			} elseif ( '' !== $new_cloud_site_key ) {
				$target_cloud_site_key = $new_cloud_site_key;
			}
		}

		$cloud_connection_changed = (
			$previous_cloud_api_url !== $target_cloud_api_url ||
			$previous_cloud_site_key !== $target_cloud_site_key
		);
		$cloud_connection_can_save = true;

		if (
			! $cloud_site_key_clear &&
			$cloud_connection_changed &&
			'' !== $target_cloud_api_url &&
			'' !== $target_cloud_site_key
		) {
			$connection_result = self::test_cloud_credentials( $target_cloud_api_url, $target_cloud_site_key );

			if ( is_wp_error( $connection_result ) ) {
				$cloud_connection_can_save              = false;
				$result['cloud_connection_error']       = $connection_result;
				$result['cloud_credentials_rejected']   = true;
				$result['cloud_connection_changed']     = true;
			} else {
				$result['cloud_connection_result']      = $connection_result;
				$result['cloud_credentials_verified']   = true;
				$result['cloud_connection_changed']     = true;
			}
		}

		update_option( 'yoro_ai_parser_provider', $provider );
		update_option( 'yoro_openai_model', $model );
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
		if ( ! $cloud_api_url_locked && $cloud_connection_can_save ) {
			update_option( 'yoro_cloud_api_url', $target_cloud_api_url );
		}
		update_option( 'yoro_cloud_parser_enabled', isset( $post['yoro_cloud_parser_enabled'] ) ? 'yes' : 'no' );
		update_option( 'yoro_cloud_endpoint_ready', isset( $post['yoro_cloud_endpoint_ready'] ) ? 'yes' : 'no' );

		if ( 'constant' !== self::get_openai_key_source() ) {
			if ( ! empty( $post['yoro_openai_api_key_clear'] ) ) {
				update_option( 'yoro_openai_api_key', '', false );
			} elseif ( isset( $post['yoro_openai_api_key'] ) ) {
				$api_key = sanitize_text_field( wp_unslash( $post['yoro_openai_api_key'] ) );

				if ( '' !== $api_key ) {
					update_option( 'yoro_openai_api_key', $api_key, false );
				}
			}
		}

		if ( ! $cloud_site_key_locked && $cloud_connection_can_save ) {
			if ( $cloud_site_key_clear ) {
				update_option( 'yoro_cloud_site_key', '', false );
			} elseif ( '' !== $new_cloud_site_key ) {
				update_option( 'yoro_cloud_site_key', $new_cloud_site_key, false );
			}
		}

		if ( $cloud_connection_can_save && $cloud_connection_changed ) {
			self::clear_cloud_status();

			if ( is_array( $result['cloud_connection_result'] ) ) {
				self::update_cloud_status( $result['cloud_connection_result'] );
			}
		}

		return $result;
	}

	private static function test_cloud_credentials( $api_url, $site_key ) {
		if ( ! class_exists( 'YoRo_AI_API_Client' ) ) {
			return new WP_Error(
				'yoro_cloud_client_missing',
				__( 'YoRo AI API client is not available.', 'yoro-ai-core' )
			);
		}

		return ( new YoRo_AI_API_Client() )->handshake_with_credentials( $api_url, $site_key, false );
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
