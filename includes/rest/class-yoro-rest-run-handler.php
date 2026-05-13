<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Run_Handler {

	public static function handle( WP_REST_Request $request ) {
		$request_started_at = microtime( true );

		$rate_limit = YoRo_Rate_Limiter::check( 'run' );

		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$client_context = $request->get_param( 'client_context' );

		if ( ! is_array( $client_context ) ) {
			$client_context = [];
		}

		$command = sanitize_textarea_field( $request->get_param( 'command' ) );
		$confirm = (bool) $request->get_param( 'confirm' );
		$preview = (bool) $request->get_param( 'preview' );

		$workflow_state_token = sanitize_text_field(
			$request->get_param( 'workflow_state_token' )
		);

		if ( empty( $command ) ) {
			return new WP_Error(
				'yoro_empty_command',
				__( 'Command is required.', 'yoro-agent-core' )
			);
		}

		$user_id = get_current_user_id();
		$trace_id = 'yoro_' . wp_generate_uuid4();

		$server_memory = (
			'yes' === get_option( 'yoro_memory_enabled', 'yes' )
		)
			? YoRo_Conversation_Memory::get_runtime_context( $user_id )
			: [];
		$pending_confirmation = YoRo_Conversation_Memory::get_pending_confirmation( $user_id );

		if (
			isset( $client_context['current_workflow'] ) &&
			empty( $client_context['current_workflow'] ) &&
			! empty( $server_memory['current_workflow'] )
		) {
			unset( $client_context['current_workflow'] );
		}

		$merged_context = array_merge(
			$server_memory,
			$client_context
		);

		if (
			! $confirm &&
			! empty( $merged_context['current_workflow'] ) &&
			is_array( $merged_context['current_workflow'] ) &&
			'ready_to_execute' === sanitize_key( $merged_context['current_workflow']['step'] ?? '' )
		) {
			YoRo_Conversation_Memory::clear_workflow( $user_id );
			unset( $merged_context['current_workflow'] );
		}

		$stored_workflow_state = YoRo_REST_Confirmation_Flow::get_stored_workflow_state(
			$user_id,
			$workflow_state_token,
			$command,
			$confirm
		);
		$plan = null;

		$stale_response = YoRo_REST_Confirmation_Flow::maybe_build_stale_response(
			$confirm,
			$workflow_state_token,
			$pending_confirmation,
			$stored_workflow_state,
			$trace_id
		);

		if ( null !== $stale_response ) {
			return $stale_response;
		}

		if ( $stored_workflow_state ) {
			$parsed = $stored_workflow_state['parsed'];
			$plan   = $stored_workflow_state['plan'];
		} else {
			$parsed = YoRo_Parser_Engine::parse( $command, $merged_context );
		}

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['success'] ) || empty( $parsed['matched'] ) ) {
			return rest_ensure_response(
				[
					'success' => false,
					'stage'   => $parsed['stage'] ?? 'parse',
					'trace_id' => $trace_id,
					'parsed'  => $parsed,
				]
			);
		}

		if ( ! empty( $parsed['response_only'] ) && ! empty( $parsed['result'] ) && is_array( $parsed['result'] ) ) {
			if ( class_exists( 'YoRo_REST_Memory_Sync' ) ) {
				YoRo_REST_Memory_Sync::maybe_update(
					$user_id,
					$command,
					$parsed,
					$parsed['result'],
					[]
				);
			}

			return rest_ensure_response(
				[
					'success'  => true,
					'stage'    => 'done',
					'trace_id' => $trace_id,
					'parsed'   => $parsed,
					'result'   => $parsed['result'],
				]
			);
		}

		$parsed = YoRo_Intent_Router::resolve_action( $parsed );

		if ( empty( $parsed['action'] ) ) {
			return new WP_Error(
				'yoro_missing_action',
				__( 'No action could be resolved from intent.', 'yoro-agent-core' )
			);
		}

		$confidence = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 1.0;
		$source     = $parsed['parser_source'] ?? 'rule';

		if ( 'ai_router' === $source && ! $preview && ! $confirm ) {
			if ( $confidence < 0.6 ) {
				YoRo_REST_Run_Log_Helper::log_low_confidence_confirmation(
					$user_id,
					$command,
					$parsed,
					$trace_id,
					$confidence,
					$source
				);

				return rest_ensure_response(
					[
						'success'          => false,
						'stage'            => 'confirm',
						'trace_id'         => $trace_id,
						'parsed'           => $parsed,
						'confirm_required' => true,
						'message'          => __( 'I am not fully confident about this AI-interpreted command. Please confirm before I continue.', 'yoro-agent-core' ),
						'confidence'       => $confidence,
						'parser_source'    => $source,
					]
				);
			}

			$preview_threshold = (float) get_option( 'yoro_ai_preview_threshold', 0.8 );
			$preview_threshold = max( 0, min( $preview_threshold, 1 ) );

			if ( $confidence < $preview_threshold ) {
				$preview = true;
			}
		}

		$action = $parsed['action'] ?? '';
		$params = $parsed['params'] ?? [];

		if ( ! class_exists( 'YoRo_Action_Planner' ) ) {
			return new WP_Error(
				'yoro_action_planner_missing',
				__( 'YoRo Action Planner is not loaded.', 'yoro-agent-core' )
			);
		}

		if ( empty( $plan ) ) {
			$plan = YoRo_Action_Planner::build_plan( $parsed );
		}

		if ( is_wp_error( $plan ) ) {
			YoRo_REST_Run_Log_Helper::log_plan_error(
				$user_id,
				$command,
				$action,
				$params,
				$trace_id,
				$plan
			);

			return $plan;
		}

		$policy_context = [
			'source'        => ! empty( $parsed['parser_source'] ) && 'rule' !== $parsed['parser_source'] ? 'ai' : 'rule',
			'parser_source' => $parsed['parser_source'] ?? 'rule',
			'route'         => 'run',
			'preview'       => $preview,
			'confirm'       => $confirm,
		];

		$runtime = YoRo_Runtime_Guard::prepare(
			$plan,
			$policy_context
		);

		if ( is_wp_error( $runtime ) ) {
			return $runtime;
		}

		$runtime_log_context = YoRo_REST_Runtime_Log::build_context(
			$trace_id,
			$plan,
			$runtime,
			[
				'execution_source' => 'chat',
				'parser_source'    => $parsed['parser_source'] ?? 'rule',
				'parser_rule'      => $parsed['parser_rule'] ?? '',
				'intent_name'      => $parsed['intent']['name'] ?? '',
				'confidence'       => $parsed['confidence'] ?? null,
				'ai_meta'          => isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [],
				'retrieved_examples' => isset( $parsed['retrieved_examples'] ) && is_array( $parsed['retrieved_examples'] ) ? $parsed['retrieved_examples'] : [],
				'route_candidates' => isset( $parsed['route_candidates'] ) && is_array( $parsed['route_candidates'] ) ? $parsed['route_candidates'] : [],
				'request_started_at' => $request_started_at,
			]
		);

		$preview = ! empty( $runtime['preview'] );
		$confirm = ! empty( $runtime['confirm'] );

		$risk   = isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [];
		$policy = isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [];
		if ( ! empty( $runtime['requires_confirmation'] ) ) {
			$confirmation_response = YoRo_Runtime_Guard::build_confirmation_response(
				$plan,
				$parsed,
				$runtime
			);

			$workflow_state_token = YoRo_REST_Confirmation_Flow::create_pending_state(
				$user_id,
				$command,
				$parsed,
				$plan,
				$action,
				$trace_id
			);

			$confirmation_response['trace_id']             = $trace_id;
			$confirmation_response['workflow_state_token'] = $workflow_state_token;
			$confirmation_response['pending_plan_token']   = $workflow_state_token;
			YoRo_REST_Run_Log_Helper::log_runtime_confirmation_required(
				$user_id,
				$command,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$runtime
			);

			return rest_ensure_response( $confirmation_response );
		}

		$result = YoRo_Plan_Executor::execute( $plan, $confirm, $preview );

		if ( is_wp_error( $result ) ) {
			$result = YoRo_REST_Response_Helper::normalize_error_result( $action, $result );
		}

		if ( is_array( $result ) && empty( $result['success'] ) && empty( $result['requires_confirmation'] ) ) {
			YoRo_REST_Run_Log_Helper::log_error(
				$user_id,
				$command,
				$action,
				$params,
				$preview,
				$confirm,
				$trace_id,
				$runtime_log_context,
				$result,
				$risk,
				$policy
			);

			return rest_ensure_response(
				[
					'success' => false,
					'stage'   => 'error',
					'trace_id' => $trace_id,
					'parsed'  => $parsed,
					'plan'    => $plan,
					'risk'    => $risk,
					'policy'  => $policy,
					'result'  => $result,
					'message' => $result['message'] ?? __( 'Action failed.', 'yoro-agent-core' ),
				]
			);
		}

		if ( $preview ) {
			YoRo_Conversation_Memory::clear_pending_confirmation( $user_id );

			YoRo_REST_Run_Log_Helper::log_preview(
				$user_id,
				$command,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result,
				$risk,
				$policy
			);

			$workflow_state_token = YoRo_REST_Confirmation_Flow::create_pending_state(
				$user_id,
				$command,
				$parsed,
				$plan,
				$action,
				$trace_id
			);

			return rest_ensure_response(
				[
					'success'              => true,
					'stage'                => 'preview',
					'trace_id'             => $trace_id,
					'workflow_state_token' => $workflow_state_token,
					'parsed'               => $parsed,
					'plan'                 => $plan,
					'risk'                 => $risk,
					'policy'               => $policy,
					'preview'              => $result,
					'result'               => $result,
				]
			);
		}

		if ( is_array( $result ) && ! empty( $result['requires_confirmation'] ) ) {
			$result['result_type']  = $result['result_type'] ?? 'confirmation';
			$result['display_type'] = $result['display_type'] ?? 'confirmation';

			$workflow_state_token = YoRo_REST_Confirmation_Flow::create_pending_state(
				$user_id,
				$command,
				$parsed,
				$plan,
				$action,
				$trace_id
			);

			YoRo_REST_Run_Log_Helper::log_confirmation_required(
				$user_id,
				$command,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result + [
					'risk'   => $risk,
					'policy' => $policy,
				]
			);

			return rest_ensure_response(
				[
					'success'          => false,
					'stage'            => 'confirm',
					'trace_id'         => $trace_id,
					'parsed'           => $parsed,
					'plan'             => $plan,
					'confirm_required' => true,
					'workflow_state_token' => $workflow_state_token,
					'pending_plan_token'   => $workflow_state_token,
					'risk'             => $risk,
					'policy'           => $policy,
					'message'          => $result['message'] ?? __( 'Confirmation required.', 'yoro-agent-core' ),
					'result'           => $result,
				]
			);
		}

		$log_id = YoRo_REST_Run_Log_Helper::log_executed(
			$user_id,
			$command,
			$action,
			$params,
			$confirm,
			$trace_id,
			$runtime_log_context,
			$result,
			$risk,
			$policy
		);

		YoRo_REST_Memory_Sync::maybe_update( $user_id, $command, $parsed, $result, $plan );

		$suggestion_payload = YoRo_REST_Suggestions_Helper::enrich( $user_id, $parsed, $result );
		$result             = $suggestion_payload['result'];
		$suggestions        = $suggestion_payload['suggestions'];

		$response = [
			'success'     => true,
			'stage'       => 'done',
			'trace_id'    => $trace_id,
			'parsed'      => $parsed,
			'plan'        => $plan,
			'risk'        => $risk,
			'policy'      => $policy,
			'result'      => $result,
			'log_id'      => $log_id,
			'suggestions' => $suggestions,
		];

		$current_workflow = YoRo_Conversation_Memory::get_workflow( $user_id );

		if (
			! empty( $current_workflow['step'] ) &&
			'ready_to_execute' === $current_workflow['step'] &&
			! empty( $current_workflow['action'] ) &&
			$current_workflow['action'] === $action &&
			is_array( $result ) &&
			! empty( $result['success'] )
		) {
			YoRo_Conversation_Memory::clear_workflow( $user_id );
		}

		YoRo_REST_Confirmation_Flow::clear_pending_state(
			$user_id,
			$confirm,
			$workflow_state_token
		);

		return rest_ensure_response( $response );
	}
}
