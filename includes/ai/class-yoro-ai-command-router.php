<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'YORO_AI_PROVIDER_YORO_AI_API' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_AI_API', 'yoro_ai_api' );
}

class YoRo_AI_Command_Router {

	public static function route( $command, $context = [] ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return new WP_Error(
				'yoro_empty_command',
				__( 'Command cannot be empty.', 'yoro-ai-core' )
			);
		}

		$mock_decision = self::maybe_get_mock_decision( $command, $context );

		if ( null !== $mock_decision ) {
			return $mock_decision;
		}

		if ( ! YoRo_AI_Provider_Resolver::supports( 'intent' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'The AI service is not configured yet.', 'yoro-ai-core' )
			);
		}

		$router_hints = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_router_hints' )
			? YoRo_Action_Catalog_Builder::build_router_hints( $command, $context )
			: [];
		$catalog_scope = sanitize_key( $router_hints['catalog_scope'] ?? 'default' );
		$full_catalog = YoRo_Action_Catalog_Builder::build( $context );
		$catalog = $full_catalog;
		$router_hints = array_merge(
			[
				'catalog_scope' => $catalog_scope,
				'catalog_total_action_count' => count( $full_catalog ),
				'resolved_order_ids' => ! empty( $context['resolved_order_ids'] ) && is_array( $context['resolved_order_ids'] )
					? array_values( array_filter( array_map( 'absint', $context['resolved_order_ids'] ) ) )
					: [],
			],
			is_array( $router_hints ) ? $router_hints : []
		);

		$payload = self::build_yoro_ai_api_route_payload( $command, $context, $catalog, $router_hints );
		$output_text = YoRo_AI_Provider_Resolver::get_client()->parse_intent( $payload );

		if ( is_wp_error( $output_text ) ) {
			return $output_text;
		}

		$decoded = json_decode( (string) $output_text, true );
		$decision = YoRo_Command_Decision_Normalizer::normalize( $decoded, $catalog );

		if ( is_wp_error( $decision ) ) {
			return $decision;
		}

		$decision['parser_rule'] = 'ai.command_router.v2';
		$decision['parser_source'] = 'ai_router';
		$decision['original_command'] = $command;
		$decision['catalog_action_count'] = count( $catalog );
		$decision['catalog_scope'] = sanitize_key( $catalog_scope );
		$decision['catalog_total_action_count'] = count( $full_catalog );
		$decision['router_profile'] = sanitize_key( $router_hints['router_profile'] ?? '' );
		$base_meta = class_exists( 'YoRo_AI_Parser' ) && method_exists( 'YoRo_AI_Parser', 'build_meta' )
			? YoRo_AI_Parser::build_meta(
				'command_router',
				[
					'prompt_version' => YoRo_AI_Parser::PROMPT_VERSION_COMMAND_ROUTER,
				]
			)
			: [];
		$decision['meta'] = array_merge(
			$base_meta,
			self::normalize_provider_meta( $decoded['meta'] ?? [] )
		);

		return $decision;
	}

	private static function build_yoro_ai_api_route_payload( $command, $context, $catalog, $router_hints ) {
		return [
			'feature'        => 'command_router',
			'command'        => (string) $command,
			'site_url'       => home_url(),
			'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			'context'        => self::compact_yoro_ai_api_context( $context ),
			'router_hints'   => self::compact_yoro_ai_api_router_hints( $router_hints ),
			'action_catalog' => is_array( $catalog ) ? array_values( $catalog ) : [],
		];
	}

	private static function compact_yoro_ai_api_context( $context ) {
		$context = is_array( $context ) ? $context : [];
		$memory_context = isset( $context['memory_context'] ) && is_array( $context['memory_context'] )
			? $context['memory_context']
			: [];

		return [
			'current_order_id'     => absint( $context['current_order_id'] ?? 0 ),
			'resolved_order_ids'   => isset( $context['resolved_order_ids'] ) && is_array( $context['resolved_order_ids'] )
				? array_values( array_slice( array_filter( array_map( 'absint', $context['resolved_order_ids'] ) ), 0, 10 ) )
				: [],
			'last_order_ids'       => isset( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] )
				? array_values( array_slice( array_filter( array_map( 'absint', $context['last_order_ids'] ) ), 0, 10 ) )
				: [],
			'last_orders'          => self::compact_yoro_ai_api_order_list_context( $context['last_orders'] ?? [] ),
			'last_result_type'     => sanitize_key( $context['last_result_type'] ?? '' ),
			'active_followup_type' => sanitize_key( $context['active_followup_type'] ?? '' ),
			'has_current_workflow' => ! empty( $context['current_workflow'] ),
			'current_workflow'     => self::compact_yoro_ai_api_workflow( $context['current_workflow'] ?? [] ),
			'memory_context'       => [
				'active_workflow' => self::compact_yoro_ai_api_workflow( $memory_context['active_workflow'] ?? [] ),
				'active_entity'   => isset( $memory_context['active_entity'] ) && is_array( $memory_context['active_entity'] )
					? [
						'type' => sanitize_key( $memory_context['active_entity']['type'] ?? '' ),
						'id'   => absint( $memory_context['active_entity']['id'] ?? 0 ),
					]
					: [],
				'last_result'     => isset( $memory_context['last_result'] ) && is_array( $memory_context['last_result'] )
					? [
						'type'   => sanitize_key( $memory_context['last_result']['type'] ?? '' ),
						'action' => sanitize_text_field( $memory_context['last_result']['action'] ?? '' ),
					]
					: [],
				'reference' => isset( $memory_context['reference'] ) && is_array( $memory_context['reference'] )
					? [
						'resolved'               => ! empty( $memory_context['reference']['resolved'] ),
						'target_type'            => sanitize_key( $memory_context['reference']['target_type'] ?? '' ),
						'target_ids'             => isset( $memory_context['reference']['target_ids'] ) && is_array( $memory_context['reference']['target_ids'] )
							? array_values( array_filter( array_map( 'absint', $memory_context['reference']['target_ids'] ) ) )
							: [],
						'source'                 => sanitize_key( $memory_context['reference']['source'] ?? '' ),
						'confidence'             => isset( $memory_context['reference']['confidence'] ) ? (float) $memory_context['reference']['confidence'] : 0.0,
						'reason'                 => sanitize_text_field( $memory_context['reference']['reason'] ?? '' ),
						'requires_clarification' => ! empty( $memory_context['reference']['requires_clarification'] ),
					]
					: [],
			],
		];
	}

	private static function compact_yoro_ai_api_workflow( $workflow ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return [];
		}

		$params = isset( $workflow['params'] ) && is_array( $workflow['params'] ) ? $workflow['params'] : [];

		return [
			'type'     => sanitize_key( $workflow['type'] ?? '' ),
			'action'   => sanitize_text_field( $workflow['action'] ?? '' ),
			'step'     => sanitize_key( $workflow['step'] ?? '' ),
			'order_id' => absint( $workflow['order_id'] ?? ( $params['order_id'] ?? 0 ) ),
			'params'   => [
				'order_id'  => absint( $params['order_id'] ?? ( $workflow['order_id'] ?? 0 ) ),
				'order_ids' => isset( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [],
				'_yoro_composed_requested_actions' => self::compact_yoro_ai_api_requested_actions( $params['_yoro_composed_requested_actions'] ?? [] ),
			],
			'missing'  => isset( $workflow['missing'] ) && is_array( $workflow['missing'] )
				? array_values( array_filter( array_map( 'sanitize_key', $workflow['missing'] ) ) )
				: [],
		];
	}

	private static function compact_yoro_ai_api_requested_actions( $actions ) {
		if ( empty( $actions ) || ! is_array( $actions ) ) {
			return [];
		}

		$compact = [];

		foreach ( array_slice( array_values( $actions ), 0, 10 ) as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}

			$params = isset( $action['params'] ) && is_array( $action['params'] ) ? $action['params'] : [];

			$compact[] = [
				'kind'     => sanitize_key( $action['kind'] ?? '' ),
				'action'   => sanitize_text_field( $action['action'] ?? '' ),
				'label'    => sanitize_text_field( $action['label'] ?? '' ),
				'position' => isset( $action['position'] ) ? absint( $action['position'] ) : count( $compact ),
				'params'   => [
					'status'        => sanitize_key( $params['status'] ?? '' ),
					'instruction'   => sanitize_text_field( $params['instruction'] ?? '' ),
					'delivery_mode' => sanitize_key( $params['delivery_mode'] ?? '' ),
				],
			];
		}

		return $compact;
	}

	private static function compact_yoro_ai_api_order_list_context( $orders ) {
		if ( empty( $orders ) || ! is_array( $orders ) ) {
			return [];
		}

		$compact = [];

		foreach ( array_slice( array_values( $orders ), 0, 10 ) as $index => $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}

			$order_id = absint( $order['id'] ?? 0 );

			if ( ! $order_id ) {
				continue;
			}

			$compact[] = [
				'position' => absint( $index ) + 1,
				'id'       => $order_id,
				'number'   => sanitize_text_field( $order['number'] ?? (string) $order_id ),
				'status'   => sanitize_key( $order['status'] ?? '' ),
			];
		}

		return $compact;
	}

	private static function compact_yoro_ai_api_router_hints( $router_hints ) {
		$router_hints = is_array( $router_hints ) ? $router_hints : [];

		return [
			'catalog_scope'              => sanitize_key( $router_hints['catalog_scope'] ?? 'default' ),
			'router_profile'             => sanitize_key( $router_hints['router_profile'] ?? '' ),
			'catalog_total_action_count' => absint( $router_hints['catalog_total_action_count'] ?? 0 ),
			'resolved_order_ids'         => isset( $router_hints['resolved_order_ids'] ) && is_array( $router_hints['resolved_order_ids'] )
				? array_values( array_filter( array_map( 'absint', $router_hints['resolved_order_ids'] ) ) )
				: [],
			'candidate_actions'          => isset( $router_hints['candidate_actions'] ) && is_array( $router_hints['candidate_actions'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', $router_hints['candidate_actions'] ) ) )
				: [],
			'candidate_intent_families'  => isset( $router_hints['candidate_intent_families'] ) && is_array( $router_hints['candidate_intent_families'] )
				? array_values( array_filter( array_map( 'sanitize_key', $router_hints['candidate_intent_families'] ) ) )
				: [],
			'command_shape'              => isset( $router_hints['command_shape'] ) && is_array( $router_hints['command_shape'] )
				? $router_hints['command_shape']
				: [],
		];
	}

	private static function normalize_provider_meta( $meta ) {
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
				$normalized[ $key ] = $value;
				continue;
			}

			if ( is_string( $value ) ) {
				$normalized[ $key ] = sanitize_text_field( $value );
			}
		}

		return $normalized;
	}

	private static function maybe_get_mock_decision( $command, $context ) {
		if (
			( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) &&
			( ! defined( 'YORO_AI_ROUTER_TEST_MODE' ) || ! YORO_AI_ROUTER_TEST_MODE )
		) {
			return null;
		}

		$mock = apply_filters(
			'yoro_ai_command_router_mock_decision',
			null,
			$command,
			is_array( $context ) ? $context : []
		);

		if ( null === $mock ) {
			return null;
		}

		if ( is_wp_error( $mock ) ) {
			return $mock;
		}

		if ( ! is_array( $mock ) ) {
			return null;
		}

		$catalog = YoRo_Action_Catalog_Builder::build( $context );
		$router_hints = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_router_hints' )
			? YoRo_Action_Catalog_Builder::build_router_hints( $command, $context )
			: [];
		$decision = YoRo_Command_Decision_Normalizer::normalize( $mock, $catalog );

		if ( is_wp_error( $decision ) ) {
			return $decision;
		}

		$decision['parser_rule'] = 'ai.command_router.mock';
		$decision['parser_source'] = 'ai_router';
		$decision['original_command'] = $command;
		$decision['catalog_action_count'] = count( $catalog );
		$decision['catalog_scope'] = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'detect_router_scope' )
			? YoRo_Action_Catalog_Builder::detect_router_scope( $command, $context )
			: 'default';
		$decision['catalog_total_action_count'] = count( YoRo_Action_Catalog_Builder::build( $context ) );
		$decision['router_profile'] = sanitize_key( $router_hints['router_profile'] ?? '' );
		$decision['meta'] = [
			'feature'        => 'command_router',
			'provider'       => 'mock',
			'model'          => 'fixture',
			'prompt_version' => 'command_router.mock',
			'trace_id'       => 'yoro_ai_mock_' . md5( $command ),
		];

		return $decision;
	}
}
