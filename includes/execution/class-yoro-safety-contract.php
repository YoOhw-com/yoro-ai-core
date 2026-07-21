<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Safety_Contract {

	const VERSION = 2;
	const PROFILE_OPTION = 'yoro_safety_profile';
	const POLICY_VERSION_OPTION = 'yoro_safety_policy_version';

	public static function maybe_migrate_legacy_profile() {
		if ( get_option( self::PROFILE_OPTION, '' ) ) {
			return;
		}

		$legacy = sanitize_key( get_option( 'yoro_policy_mode', 'balanced' ) );
		$profile = 'advisory';

		if ( in_array( $legacy, [ 'balanced', 'permissive', 'custom' ], true ) ) {
			$profile = 'assisted';
		}

		update_option( self::PROFILE_OPTION, $profile );
		update_option( self::POLICY_VERSION_OPTION, 1 );
		update_option( 'yoro_safety_legacy_migration', $legacy . ':' . $profile );
	}

	public static function profile() {
		self::maybe_migrate_legacy_profile();

		$status         = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_status() : [];
		$server_profile = self::is_server_authoritative() && is_array( $status )
			? sanitize_key( $status['safety']['profile'] ?? '' )
			: '';
		$profile = in_array( $server_profile, [ 'advisory', 'assisted', 'delegated' ], true )
			? $server_profile
			: sanitize_key( get_option( self::PROFILE_OPTION, 'assisted' ) );

		return in_array( $profile, [ 'advisory', 'assisted', 'delegated' ], true )
			? $profile
			: 'assisted';
	}

	public static function policy_version() {
		$status         = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_status() : [];
		$server_version = self::is_server_authoritative() && is_array( $status )
			? absint( $status['safety']['version'] ?? 0 )
			: 0;

		return max( 1, $server_version ?: absint( get_option( self::POLICY_VERSION_OPTION, 1 ) ) );
	}

	public static function is_server_authoritative() {
		if ( ! class_exists( 'YoRo_AI_Settings' ) ) {
			return false;
		}

		$status  = YoRo_AI_Settings::get_ai_api_status();
		$profile = is_array( $status ) ? sanitize_key( $status['safety']['profile'] ?? '' ) : '';

		return YoRo_AI_Settings::is_ai_api_account_connected( $status )
			&& ! empty( $status['safety']['server_provided'] )
			&& in_array( $profile, [ 'advisory', 'assisted', 'delegated' ], true );
	}

	public static function management_url() {
		if ( ! class_exists( 'YoRo_AI_Settings' ) ) {
			return '';
		}

		$status = YoRo_AI_Settings::get_ai_api_status();
		$url    = is_array( $status ) ? esc_url_raw( $status['safety']['management_url'] ?? '' ) : '';

		return '' !== $url ? $url : YoRo_AI_Settings::get_ai_api_account_url();
	}

	public static function trusted_origin( $pending_operation_token = '', $route = '' ) {
		$route = sanitize_key( $route );

		if ( 'undo' === $route ) {
			return 'system_rollback';
		}

		if ( '' !== sanitize_text_field( (string) $pending_operation_token ) ) {
			return 'ai_chat';
		}

		return 'direct_ui';
	}

	public static function action_metadata( $action_name ) {
		$action_name = sanitize_text_field( (string) $action_name );
		$action = YoRo_Action_Registry::get( $action_name );

		if ( ! is_array( $action ) ) {
			return [
				'effect'               => 'mutate',
				'reversibility'        => 'none',
				'external_side_effect' => true,
				'risk_level'           => 'high',
				'max_targets'          => 1,
			];
		}

		return [
			'effect'               => sanitize_key( $action['effect'] ?? ( ! empty( $action['write_action'] ) ? 'mutate' : 'read' ) ),
			'reversibility'        => sanitize_key( $action['reversibility'] ?? ( ! empty( $action['write_action'] ) ? 'partial' : 'full' ) ),
			'external_side_effect' => ! empty( $action['external_side_effect'] ),
			'risk_level'           => sanitize_key( $action['risk_level'] ?? 'low' ),
			'target_type'          => sanitize_key( $action['target_type'] ?? '' ),
			'supports_bulk'        => ! empty( $action['supports_bulk'] ),
			'max_targets'          => absint( $action['max_targets'] ?? ( $action['max_items'] ?? 1 ) ),
		];
	}

	public static function preflight( $plan, $pending_operation = [] ) {
		$plan = is_array( $plan ) ? $plan : [];
		$pending_operation = is_array( $pending_operation ) ? $pending_operation : [];
		$items = self::plan_items( $plan );
		$previews = [];
		$effects = [];
		$target_count = 0;
		$max_risk = 'low';

		foreach ( $items as $index => $item ) {
			$action_name = sanitize_text_field( $item['action'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];
			$metadata = self::action_metadata( $action_name );
			$count = self::count_targets( $params );
			$target_count += $count;
			$effects[] = $metadata['effect'];
			$max_risk = self::max_risk( $max_risk, $metadata['risk_level'] );
			$preview = self::preview_action( $action_name, $params );

			$previews[] = [
				'operation_id' => sanitize_text_field( $item['id'] ?? ( 'operation_' . ( $index + 1 ) ) ),
				'action'       => $action_name,
				'params'       => $params,
				'preview'      => $preview,
				'effect'       => $metadata['effect'],
				'target_count' => $count,
			];
		}

		$resolved_impact = [
			'target_count'             => max( 1, $target_count ),
			'step_count'               => count( $items ),
			'effects'                  => array_values( array_unique( array_filter( $effects ) ) ),
			'max_risk'                 => $max_risk,
			'has_external_side_effect' => self::has_external_side_effect( $items ),
		];
		$preview_payload = [
			'contract_version' => self::VERSION,
			'operations'       => $previews,
			'resolved_impact'  => $resolved_impact,
		];
		$server_groups = isset( $pending_operation['approval_groups'] ) && is_array( $pending_operation['approval_groups'] )
			? $pending_operation['approval_groups']
			: [];

		return [
			'contract_version' => self::VERSION,
			'plan_hash'        => sanitize_text_field( $pending_operation['plan_hash'] ?? '' ),
			'preview_hash'     => self::hash( $preview_payload ),
			'resolved_impact'  => $resolved_impact,
			'approved_groups'  => array_values(
				array_filter(
					array_map(
						static function ( $group ) {
							return is_array( $group ) ? sanitize_key( $group['id'] ?? '' ) : '';
						},
						$server_groups
					)
				)
			),
			'preview'          => $preview_payload,
		];
	}

	public static function hash( $value ) {
		$canonical = self::canonicalize( $value );
		$json = wp_json_encode( $canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		return hash( 'sha256', false === $json ? '' : $json );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( self::is_list( $value ) ) {
			return array_map( [ __CLASS__, 'canonicalize' ], $value );
		}

		ksort( $value, SORT_STRING );

		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}

		return $value;
	}

	private static function is_list( $value ) {
		if ( [] === $value ) {
			return true;
		}

		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function plan_items( $plan ) {
		if ( 'sequence' === ( $plan['type'] ?? '' ) ) {
			return isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? array_values( $plan['steps'] ) : [];
		}

		return [
			[
				'id'     => 'operation_1',
				'action' => sanitize_text_field( $plan['action'] ?? '' ),
				'params' => isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [],
			],
		];
	}

	private static function preview_action( $action_name, $params ) {
		$action = YoRo_Action_Registry::get( $action_name );

		if ( ! is_array( $action ) || empty( $action['preview_callback'] ) || ! is_callable( $action['preview_callback'] ) ) {
			return [ 'params' => $params ];
		}

		$preview = call_user_func( $action['preview_callback'], $params );

		if ( is_wp_error( $preview ) ) {
			return [
				'success' => false,
				'code'    => sanitize_key( $preview->get_error_code() ),
				'message' => sanitize_text_field( $preview->get_error_message() ),
			];
		}

		return is_array( $preview ) ? $preview : [];
	}

	private static function count_targets( $params ) {
		foreach ( [ 'order_ids', 'product_ids', 'customer_ids', 'thread_ids', 'ids', 'items' ] as $key ) {
			if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
				return max( 1, count( $params[ $key ] ) );
			}
		}

		return 1;
	}

	private static function has_external_side_effect( $items ) {
		foreach ( $items as $item ) {
			$metadata = self::action_metadata( $item['action'] ?? '' );

			if ( ! empty( $metadata['external_side_effect'] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function max_risk( $current, $candidate ) {
		$rank = [ 'low' => 1, 'medium' => 2, 'high' => 3, 'unknown' => 4 ];

		return ( $rank[ $candidate ] ?? 4 ) > ( $rank[ $current ] ?? 4 ) ? $candidate : $current;
	}
}
