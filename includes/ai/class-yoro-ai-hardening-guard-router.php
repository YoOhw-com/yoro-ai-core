<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Hardening_Guard_Router {

	public static function parse_email_template_list( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if ( ! self::is_email_template_list_request( $normalized ) ) {
			return null;
		}

		$parsed = [
			'success'            => true,
			'matched'            => true,
			'action'             => 'email_templates.list',
			'params'             => [],
			'parser_rule'        => 'guard.email_templates.list',
			'parser_source'      => 'guard',
			'confidence'         => 0.97,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'intent'             => [
				'name'       => 'email_templates_list',
				'confidence' => 0.97,
				'source'     => 'guard',
			],
		];

		return self::normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks );
	}

	public static function parse_operations_hardening( $command, $normalized, $client_context = [], $callbacks = [] ) {
		if (
			class_exists( 'YoRo_AI_Semantic_Retrieval_Helper' ) &&
			YoRo_AI_Semantic_Retrieval_Helper::is_unsupported_analytics_request( $normalized )
		) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'I can’t answer that analytics question directly yet. I can help with store summary, operations, attention, fulfillment, inventory, or customer follow-up.', 'yoro-ai-core' )
			);
		}

		return null;
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized_result = self::normalize_parsed_result( $parsed, $command, $normalized, $client_context, $callbacks );

		if (
			class_exists( 'YoRo_Entity_Resolver' ) &&
			class_exists( 'YoRo_Intent_Router' ) &&
			class_exists( 'YoRo_Entity_Validator' )
		) {
			$resolved_result = YoRo_Entity_Resolver::resolve( $normalized_result, $client_context );
			$resolved_result = YoRo_Intent_Router::resolve_action( $resolved_result );

			return YoRo_Entity_Validator::validate( $resolved_result );
		}

		return $normalized_result;
	}

	private static function normalize_parsed_result( $result, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( ! empty( $callbacks['normalize_parsed_result'] ) && is_callable( $callbacks['normalize_parsed_result'] ) ) {
			$normalized_result = call_user_func(
				$callbacks['normalize_parsed_result'],
				$result,
				$command,
				$normalized,
				$client_context
			);

			return is_array( $normalized_result ) ? $normalized_result : $result;
		}

		return $result;
	}

	private static function clarification_response( $command, $normalized, $message, $clarify_reason = 'details' ) {
		$options = class_exists( 'YoRo_AI_Conversation_Response_Helper' )
			? YoRo_AI_Conversation_Response_Helper::get_clarification_options( $normalized )
			: [];

		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => $options,
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'clarify_reason'     => sanitize_key( $clarify_reason ),
		];
	}

	private static function is_email_template_list_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		$mentions_templates =
			false !== strpos( $normalized, 'template' ) ||
			false !== strpos( $normalized, 'templates' );

		if ( ! $mentions_templates ) {
			return false;
		}

		$has_email_context =
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'reusable' ) ||
			false !== strpos( $normalized, 'saved' ) ||
			false !== strpos( $normalized, 'customer' );

		if ( ! $has_email_context ) {
			return false;
		}

		$has_list_intent =
			preg_match( '/\b(show|list|view|open|load|get|see|browse)\b/i', $normalized ) ||
			preg_match( '/\bwhat\b.*\btemplates?\b/i', $normalized ) ||
			preg_match( '/\btemplates?\b.*\b(?:available|saved|reusable)\b/i', $normalized );

		if ( ! $has_list_intent ) {
			return false;
		}

		return true;
	}
}
