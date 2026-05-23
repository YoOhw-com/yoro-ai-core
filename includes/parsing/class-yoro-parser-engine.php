<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Engine {

	public static function parse( $command, $client_context = [] ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return new WP_Error(
				'yoro_empty_command',
				__( 'Command cannot be empty.', 'yoro-ai-core' )
			);
		}

		$normalized = self::normalize( $command );

		$client_context = class_exists( 'YoRo_Parser_Context_Preparer' )
			? YoRo_Parser_Context_Preparer::prepare_initial_context( $command, $client_context )
			: $client_context;

		$client_context = class_exists( 'YoRo_Parser_Context_Preparer' )
			? YoRo_Parser_Context_Preparer::prepare_phrase_context( $command, $client_context )
			: $client_context;

		$ai_router_result = class_exists( 'YoRo_AI_Command_Router_Result_Router' )
			? YoRo_AI_Command_Router_Result_Router::parse(
				$command,
				$normalized,
				$client_context,
				self::ai_command_router_result_router_callbacks()
			)
			: null;

		$post_ai_router_result = class_exists( 'YoRo_AI_Post_Command_Router_Result_Router' )
			? YoRo_AI_Post_Command_Router_Result_Router::route(
				$command,
				$normalized,
				$client_context,
				$ai_router_result,
				self::ai_recovery_fallback_callbacks()
			)
			: null;

		if ( is_array( $post_ai_router_result ) ) {
			return $post_ai_router_result;
		}

		return class_exists( 'YoRo_Parser_Safety_Fallback_Router' )
			? YoRo_Parser_Safety_Fallback_Router::build_generic_clarification(
				$command,
				$normalized
			)
			: null;
	}

	private static function normalize( $command ) {
		$command = strtolower( trim( wp_strip_all_tags( $command ) ) );
		$command = str_replace(
			[ '’', '`', '‘', '“', '”' ],
			[ '\'', '\'', '\'', '"', '"' ],
			$command
		);
		$command = preg_replace( '/\s+/', ' ', $command );

		return $command;
	}

	private static function ai_conversation_result_router_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_and_parse( [ self::class, 'parse' ] )
			: [];
	}

	private static function active_workflow_continue_router_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_only()
			: [];
	}

	private static function ai_command_router_result_router_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_and_hydrate()
			: [];
	}

	private static function ai_pre_router_guard_result_router_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_and_hydrate()
			: [];
	}

	private static function ai_recovery_fallback_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_and_hydrate()
			: [];
	}

	private static function parser_tail_router_callbacks() {
		return class_exists( 'YoRo_Parser_Router_Callbacks' )
			? YoRo_Parser_Router_Callbacks::normalize_hydrate_and_parse( [ self::class, 'parse' ] )
			: [];
	}

}
