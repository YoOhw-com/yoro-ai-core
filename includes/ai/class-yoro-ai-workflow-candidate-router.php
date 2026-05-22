<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Workflow_Candidate_Router {

	public static function maybe_build_validated_direct_ai_workflow_result( $command, $normalized, $client_context, $parsed, $callbacks = [] ) {
		$candidate = self::maybe_build_direct_ai_workflow_result( $command, $normalized, $client_context, $parsed );

		if ( ! is_array( $candidate ) ) {
			return $candidate;
		}

		return self::normalize_resolve_validate_result( $candidate, $command, $normalized, $client_context, $callbacks );
	}

	public static function maybe_build_validated_workflow_candidate( $command, $normalized, $client_context, $parsed, $callbacks = [] ) {
		$candidate = self::maybe_resolve_workflow_candidate( $command, $normalized, $client_context, $parsed );

		if ( ! is_array( $candidate ) ) {
			return $candidate;
		}

		return self::normalize_resolve_validate_result( $candidate, $command, $normalized, $client_context, $callbacks );
	}

	public static function maybe_resolve_workflow_candidate( $command, $normalized, $client_context, $parsed ) {
		if ( ! class_exists( 'YoRo_Workflow_Candidate_Resolver' ) || ! is_array( $parsed ) ) {
			return null;
		}

		if ( self::should_preserve_direct_ai_email_authoring_action( $command, $normalized, $parsed ) ) {
			return null;
		}

		$action = sanitize_text_field( $parsed['action'] ?? '' );

		if ( '' === $action || 0 === strpos( $action, 'workflow.' ) ) {
			return null;
		}

		return YoRo_Workflow_Candidate_Resolver::resolve(
			$command,
			$normalized,
			$client_context,
			[
				'action'        => $action,
				'params'        => isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [],
				'confidence'    => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0,
				'parser_source' => sanitize_text_field( $parsed['parser_source'] ?? 'rule' ),
			]
		);
	}

	public static function maybe_build_direct_ai_workflow_result( $command, $normalized, $client_context, $parsed ) {
		if ( ! class_exists( 'YoRo_Workflow_Templates' ) || ! is_array( $parsed ) ) {
			return null;
		}

		$action        = sanitize_text_field( $parsed['action'] ?? '' );
		$parser_source = sanitize_key( $parsed['parser_source'] ?? '' );
		$params        = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];
		$template_id   = '';

		if ( 'ai_router' !== $parser_source ) {
			return null;
		}

		switch ( $action ) {
			case 'workflow.write_email_for_order':
			case 'workflow.write_and_save_email_draft_for_order':
			case 'workflow.write_and_send_email_for_order':
			case 'workflow.write_email_for_orders':
			case 'workflow.write_and_save_email_drafts_for_orders':
			case 'workflow.write_and_send_emails_for_orders':
				$template_id = str_replace( 'workflow.', '', $action );
				break;
			default:
				return null;
		}

		if ( empty( $params['order_id'] ) && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$params['order_id'] = absint( reset( $params['order_ids'] ) );
		}

		$inferred_instruction = class_exists( 'YoRo_Router_Decision_Validator' )
			? YoRo_Router_Decision_Validator::infer_email_authoring_instruction_from_command( $command, $normalized, $params )
			: '';

		if ( '' !== $inferred_instruction ) {
			$params['instruction'] = $inferred_instruction;
		}

		if ( 'workflow.write_and_save_email_draft_for_order' === $action ) {
			$params['delivery_mode'] = 'save_draft';
		} elseif ( 'workflow.write_and_send_email_for_order' === $action ) {
			$params['delivery_mode'] = 'send';
		} elseif ( 'workflow.write_and_save_email_drafts_for_orders' === $action ) {
			$params['delivery_mode'] = 'save_draft';
		} elseif ( 'workflow.write_and_send_emails_for_orders' === $action ) {
			$params['delivery_mode'] = 'send';
		} elseif ( '' === sanitize_key( $params['delivery_mode'] ?? '' ) ) {
			$params['delivery_mode'] = 'preview';
		}

		$workflow_result = YoRo_Workflow_Templates::build_parsed(
			$template_id,
			$params,
			$command
		);

		if ( ! is_array( $workflow_result ) ) {
			return $workflow_result;
		}

		$workflow_result['parser_source']                      = 'ai_router';
		$workflow_result['parser_rule']                        = 'ai.command_router.v2';
		$workflow_result['confidence']                         = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0;
		$workflow_result['intent_family']                      = sanitize_key( $parsed['intent_family'] ?? 'customer_email' );
		$workflow_result['language']                           = sanitize_text_field( $parsed['language'] ?? '' );
		$workflow_result['summary']                            = sanitize_text_field( $parsed['summary'] ?? '' );
		$workflow_result['reasoning_summary']                  = sanitize_text_field( $parsed['reasoning_summary'] ?? '' );
		$workflow_result['meta']                               = isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [];
		$workflow_result['raw_param_keys']                     = isset( $parsed['raw_param_keys'] ) && is_array( $parsed['raw_param_keys'] ) ? $parsed['raw_param_keys'] : [];
		$workflow_result['dropped_param_keys']                 = isset( $parsed['dropped_param_keys'] ) && is_array( $parsed['dropped_param_keys'] ) ? $parsed['dropped_param_keys'] : [];
		$workflow_result['params_trimmed_count']               = isset( $parsed['params_trimmed_count'] ) ? absint( $parsed['params_trimmed_count'] ) : 0;
		$workflow_result['command_decision']                   = isset( $parsed['command_decision'] ) && is_array( $parsed['command_decision'] ) ? $parsed['command_decision'] : [];
		$workflow_result['resolution_path']                    = sanitize_key( $parsed['resolution_path'] ?? 'ai_primary' );
		$workflow_result['workflow_promotion_source']          = 'ai_router';
		$workflow_result['workflow_promotion_reason']          = 'direct_ai_workflow_action';
		$workflow_result['workflow_promotion_trigger_action']  = $action;

		if ( ! empty( $workflow_result['workflow'] ) && is_array( $workflow_result['workflow'] ) ) {
			$workflow_result['workflow']['promotion'] = [
				'source'         => 'ai_router',
				'reason'         => 'direct_ai_workflow_action',
				'trigger_action' => $action,
			];
		}

		return $workflow_result;
	}

	private static function should_preserve_direct_ai_email_authoring_action( $command, $normalized, $parsed ) {
		$parsed        = is_array( $parsed ) ? $parsed : [];
		$action        = sanitize_text_field( $parsed['action'] ?? '' );
		$parser_source = sanitize_key( $parsed['parser_source'] ?? '' );
		$params        = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];
		$instruction   = sanitize_textarea_field( $params['instruction'] ?? '' );
		$order_id      = absint( $params['order_id'] ?? 0 );

		if ( 'ai_router' !== $parser_source || 'orders.compose_customer_email' !== $action ) {
			return false;
		}

		if ( $order_id <= 0 && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_id = absint( reset( $params['order_ids'] ) );
		}

		if ( $order_id <= 0 || '' === trim( $instruction ) ) {
			return false;
		}

		$normalized = is_string( $normalized ) ? $normalized : self::normalize( (string) $command );

		if ( preg_match( '/\b(?:send|save|draft|template|use template|save as draft)\b/i', $normalized ) ) {
			return false;
		}

		return true;
	}

	private static function normalize_resolve_validate_result( $parsed, $command, $normalized, $client_context, $callbacks = [] ) {
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

	private static function normalize_parsed_result( $parsed, $command, $normalized, $client_context = [], $callbacks = [] ) {
		$normalized_result = self::invoke(
			$callbacks,
			'normalize_parsed_result',
			[ $parsed, $command, $normalized, $client_context ]
		);

		return is_array( $normalized_result ) ? $normalized_result : $parsed;
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

	private static function invoke( $callbacks, $name, $args = [], $default = null ) {
		$callbacks = is_array( $callbacks ) ? $callbacks : [];

		if ( empty( $callbacks[ $name ] ) || ! is_callable( $callbacks[ $name ] ) ) {
			return $default;
		}

		return call_user_func_array( $callbacks[ $name ], $args );
	}
}
