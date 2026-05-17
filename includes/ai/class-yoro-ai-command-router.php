<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Command_Router {

	public static function route( $command, $context = [] ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return new WP_Error(
				'yoro_empty_command',
				__( 'Command cannot be empty.', 'yoro-agent-core' )
			);
		}

		$mock_decision = self::maybe_get_mock_decision( $command, $context );

		if ( null !== $mock_decision ) {
			return $mock_decision;
		}

		if ( ! YoRo_AI_Settings::get_openai_api_key() ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'OpenAI API key is missing.', 'yoro-agent-core' )
			);
		}

		$router_hints = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_router_hints' )
			? YoRo_Action_Catalog_Builder::build_router_hints( $command, $context )
			: [];
		$catalog_scope = sanitize_key( $router_hints['catalog_scope'] ?? 'default' );
		$full_catalog = YoRo_Action_Catalog_Builder::build( $context );
		$catalog = class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_for_command' )
			? YoRo_Action_Catalog_Builder::build_for_command( $command, $context )
			: $full_catalog;
		$payload = YoRo_AI_Command_Router_Prompt::build(
			$command,
			$context,
			$catalog,
			array_merge(
				[
					'catalog_scope' => $catalog_scope,
					'catalog_total_action_count' => count( $full_catalog ),
					'resolved_order_ids' => ! empty( $context['resolved_order_ids'] ) && is_array( $context['resolved_order_ids'] )
						? array_values( array_filter( array_map( 'absint', $context['resolved_order_ids'] ) ) )
						: [],
				],
				is_array( $router_hints ) ? $router_hints : []
			)
		);
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
		$decision['meta'] = class_exists( 'YoRo_AI_Parser' ) && method_exists( 'YoRo_AI_Parser', 'build_meta' )
			? YoRo_AI_Parser::build_meta(
				'command_router',
				[
					'prompt_version' => YoRo_AI_Parser::PROMPT_VERSION_COMMAND_ROUTER,
				]
			)
			: [];

		return $decision;
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

		$catalog = YoRo_Action_Catalog_Builder::build_for_command( $command, $context );
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
