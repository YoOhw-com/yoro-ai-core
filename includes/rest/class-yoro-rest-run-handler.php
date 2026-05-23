<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Run_Handler {

	public static function handle( WP_REST_Request $request ) {
		$request_started_at = microtime( true );
		$timings            = [];

		if ( class_exists( 'YoRo_AI_Request_Tracker' ) ) {
			YoRo_AI_Request_Tracker::reset();
		}

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
				__( 'Command is required.', 'yoro-ai-core' )
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

		$merged_context = self::merge_runtime_context(
			$server_memory,
			$client_context,
			$confirm
		);

		if ( class_exists( 'YoRo_Memory_Context_Resolver' ) ) {
			$merged_context = YoRo_Memory_Context_Resolver::resolve( $command, $merged_context );
		}

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
			return self::attach_telemetry_to_response(
				$stale_response,
				$trace_id,
				[],
				[],
				[],
				$request_started_at,
				$timings
			);
		}

		if ( $stored_workflow_state ) {
			$parsed = $stored_workflow_state['parsed'];
			$plan   = $stored_workflow_state['plan'];
		} else {
			$parse_started_at = microtime( true );
			$parsed = YoRo_Parser_Engine::parse( $command, $merged_context );
			$timings['parse_ms'] = self::duration_ms( $parse_started_at );
		}

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( empty( $parsed['success'] ) || empty( $parsed['matched'] ) ) {
			$promoted_low_confidence_parse = false;

			if (
				'ai_router' === sanitize_key( $parsed['parser_source'] ?? '' ) &&
				'low_confidence' === sanitize_key( $parsed['clarify_reason'] ?? '' ) &&
				! empty( $parsed['action'] ) &&
				! $preview &&
				! $confirm
			) {
				$low_confidence_parsed = $parsed;
				$low_confidence_parsed['success'] = true;
				$low_confidence_parsed['matched'] = true;

				$plan_started_at = microtime( true );
				$low_confidence_plan = YoRo_Action_Planner::build_plan( $low_confidence_parsed );
				$timings['plan_ms'] = self::duration_ms( $plan_started_at );

				if ( ! is_wp_error( $low_confidence_plan ) ) {
					if ( self::should_bypass_low_confidence_ai_confirmation( $low_confidence_parsed, $low_confidence_plan ) ) {
						unset( $low_confidence_parsed['stage'], $low_confidence_parsed['clarify_reason'] );
						$parsed                       = $low_confidence_parsed;
						$plan                         = $low_confidence_plan;
						$promoted_low_confidence_parse = true;
					} else {
					$workflow_state_token = YoRo_REST_Confirmation_Flow::create_pending_state(
						$user_id,
						$command,
						$low_confidence_parsed,
						$low_confidence_plan,
						$low_confidence_parsed['action'],
						$trace_id
					);

					YoRo_REST_Run_Log_Helper::log_low_confidence_confirmation(
						$user_id,
						$command,
						$low_confidence_parsed,
						$trace_id,
						(float) ( $low_confidence_parsed['confidence'] ?? 0 ),
						$low_confidence_parsed['parser_source'] ?? 'ai_router'
					);

					return rest_ensure_response(
						[
							'success'              => false,
							'stage'                => 'confirm',
							'trace_id'             => $trace_id,
							'parsed'               => $low_confidence_parsed,
							'confirm_required'     => true,
							'message'              => __( 'I’m not fully confident I understood this command. Please confirm before I continue.', 'yoro-ai-core' ),
							'confidence'           => (float) ( $low_confidence_parsed['confidence'] ?? 0 ),
							'parser_source'        => $low_confidence_parsed['parser_source'] ?? 'ai_router',
							'workflow_state_token' => $workflow_state_token,
							'pending_plan_token'   => $workflow_state_token,
							'telemetry'            => self::build_response_telemetry(
								$trace_id,
								$low_confidence_parsed,
								$low_confidence_plan,
								[],
								$request_started_at,
								$timings
							),
						]
					);
					}
				}
			}

			if ( ! $promoted_low_confidence_parse && (
				! empty( $parsed['current_workflow'] ) &&
				is_array( $parsed['current_workflow'] ) &&
				class_exists( 'YoRo_Conversation_Memory' )
			) ) {
				YoRo_Conversation_Memory::set_workflow( $user_id, $parsed['current_workflow'] );
			}

			if ( ! $promoted_low_confidence_parse ) {
				return rest_ensure_response(
					[
						'success' => false,
						'stage'   => $parsed['stage'] ?? 'parse',
						'trace_id' => $trace_id,
						'parsed'  => $parsed,
						'current_workflow' => $parsed['current_workflow'] ?? [],
						'telemetry' => self::build_response_telemetry(
							$trace_id,
							$parsed,
							[],
							[],
							$request_started_at,
							$timings
						),
					]
				);
			}
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
					'telemetry' => self::build_response_telemetry(
						$trace_id,
						$parsed,
						[],
						[],
						$request_started_at,
						$timings
					),
				]
			);
		}

		$parsed = YoRo_Intent_Router::resolve_action( $parsed );
		$parsed = self::attach_response_language_to_parsed_params( $parsed );

		if ( empty( $parsed['action'] ) ) {
			return new WP_Error(
				'yoro_missing_action',
				__( 'No action could be resolved from intent.', 'yoro-ai-core' )
			);
		}

		$confidence = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 1.0;
		$source     = $parsed['parser_source'] ?? 'rule';
		$action      = $parsed['action'] ?? '';
		$action_def  = class_exists( 'YoRo_Action_Registry' ) && $action
			? YoRo_Action_Registry::get( $action )
			: null;
		$is_write_action = is_array( $action_def ) && ! empty( $action_def['write_action'] );

		if ( 'ai_router' === $source && ! $preview && ! $confirm ) {
			if ( $confidence < 0.6 ) {
				$low_confidence_plan = YoRo_Action_Planner::build_plan( $parsed );

				if ( is_wp_error( $low_confidence_plan ) ) {
					return $low_confidence_plan;
				}

				if ( self::should_bypass_low_confidence_ai_confirmation( $parsed, $low_confidence_plan ) ) {
					$plan = $low_confidence_plan;
				} else {
					$workflow_state_token = YoRo_REST_Confirmation_Flow::create_pending_state(
						$user_id,
						$command,
						$parsed,
						$low_confidence_plan,
						$action,
						$trace_id
					);

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
							'message'          => __( 'I’m not fully confident I understood this command. Please confirm before I continue.', 'yoro-ai-core' ),
							'confidence'       => $confidence,
							'parser_source'    => $source,
							'workflow_state_token' => $workflow_state_token,
							'pending_plan_token'   => $workflow_state_token,
							'telemetry'        => self::build_response_telemetry(
								$trace_id,
								$parsed,
								$low_confidence_plan,
								[],
								$request_started_at,
								$timings
							),
						]
					);
				}
			}

			$preview_threshold = (float) get_option( 'yoro_ai_preview_threshold', 0.8 );
			$preview_threshold = max( 0, min( $preview_threshold, 1 ) );

			if ( $is_write_action && $confidence < $preview_threshold ) {
				$preview = true;
			}
		}

		$params = $parsed['params'] ?? [];

		if ( ! class_exists( 'YoRo_Action_Planner' ) ) {
			return new WP_Error(
				'yoro_action_planner_missing',
				__( 'YoRo Action Planner is not loaded.', 'yoro-ai-core' )
			);
		}

		if ( empty( $plan ) ) {
			$plan_started_at = microtime( true );
			$plan = YoRo_Action_Planner::build_plan( $parsed );
			$timings['plan_ms'] = self::duration_ms( $plan_started_at );
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

			$semantic_response = YoRo_REST_Response_Helper::maybe_build_semantic_validation_response(
				$trace_id,
				$parsed,
				[],
				[],
				[],
				$plan
			);

			if ( is_array( $semantic_response ) ) {
				$semantic_response['telemetry'] = self::build_response_telemetry(
					$trace_id,
					$parsed,
					[],
					[],
					$request_started_at,
					$timings
				);
				return rest_ensure_response( $semantic_response );
			}

			return $plan;
		}

		$policy_context = [
			'source'        => ! empty( $parsed['parser_source'] ) && 'rule' !== $parsed['parser_source'] ? 'ai' : 'rule',
			'parser_source' => $parsed['parser_source'] ?? 'rule',
			'route'         => 'run',
			'preview'       => $preview,
			'confirm'       => $confirm,
		];

		$runtime_started_at = microtime( true );
		$runtime = YoRo_Runtime_Guard::prepare(
			$plan,
			$policy_context
		);
		$timings['runtime_guard_ms'] = self::duration_ms( $runtime_started_at );

		if ( is_wp_error( $runtime ) ) {
			$semantic_response = YoRo_REST_Response_Helper::maybe_build_semantic_validation_response(
				$trace_id,
				$parsed,
				$plan,
				[],
				[],
				$runtime
			);

			if ( is_array( $semantic_response ) ) {
				$semantic_response['telemetry'] = self::build_response_telemetry(
					$trace_id,
					$parsed,
					$plan,
					[],
					$request_started_at,
					$timings
				);
				return rest_ensure_response( $semantic_response );
			}

			return $runtime;
		}

		$preview = ! empty( $runtime['preview'] );
		$confirm = ! empty( $runtime['confirm'] );

		$risk   = isset( $runtime['risk'] ) && is_array( $runtime['risk'] ) ? $runtime['risk'] : [];
		$policy = isset( $runtime['policy'] ) && is_array( $runtime['policy'] ) ? $runtime['policy'] : [];
		$runtime_log_context = self::build_runtime_log_context( $trace_id, $plan, $runtime, $parsed, $request_started_at, $timings );
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
			$confirmation_response['telemetry']            = self::build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings );
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

		$execute_started_at = microtime( true );
		$result = YoRo_Plan_Executor::execute( $plan, $confirm, $preview );
		$timings['execute_ms'] = self::duration_ms( $execute_started_at );

		if ( is_wp_error( $result ) ) {
			$result = YoRo_REST_Response_Helper::normalize_error_result( $action, $result );
		}

		if ( is_array( $result ) && empty( $result['success'] ) && empty( $result['requires_confirmation'] ) ) {
			$semantic_response = YoRo_REST_Response_Helper::maybe_build_semantic_validation_response_from_result(
				$trace_id,
				$parsed,
				$plan,
				$risk,
				$policy,
				$result
			);

			if ( is_array( $semantic_response ) ) {
				$semantic_response['telemetry'] = self::build_response_telemetry(
					$trace_id,
					$parsed,
					$plan,
					$runtime,
					$request_started_at,
					$timings
				);
				return rest_ensure_response( $semantic_response );
			}

			$runtime_log_context = self::build_runtime_log_context( $trace_id, $plan, $runtime, $parsed, $request_started_at, $timings );
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
					'message' => $result['message'] ?? __( 'I could not complete this action.', 'yoro-ai-core' ),
					'telemetry' => self::build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ),
				]
			);
		}

		if ( $preview ) {
			YoRo_Conversation_Memory::clear_pending_confirmation( $user_id );

			$runtime_log_context = self::build_runtime_log_context( $trace_id, $plan, $runtime, $parsed, $request_started_at, $timings );
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
					'telemetry'            => self::build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ),
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
					'message'          => $result['message'] ?? __( 'Please confirm before I continue.', 'yoro-ai-core' ),
					'result'           => $result,
					'telemetry'        => self::build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ),
				]
			);
		}

		$memory_sync_started_at = microtime( true );
		YoRo_REST_Memory_Sync::maybe_update( $user_id, $command, $parsed, $result, $plan );
		$timings['memory_sync_ms'] = self::duration_ms( $memory_sync_started_at );

		$suggestions_started_at = microtime( true );
		$suggestion_payload = YoRo_REST_Suggestions_Helper::enrich( $user_id, $parsed, $result );
		$result             = $suggestion_payload['result'];
		$suggestions        = $suggestion_payload['suggestions'];
		$timings['suggestions_ms'] = self::duration_ms( $suggestions_started_at );

		$runtime_log_context = self::build_runtime_log_context( $trace_id, $plan, $runtime, $parsed, $request_started_at, $timings );
		$logging_started_at  = microtime( true );
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
		$timings['logging_ms'] = self::duration_ms( $logging_started_at );

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
			'telemetry'   => self::build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ),
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

	private static function build_runtime_log_context( $trace_id, $plan, $runtime, $parsed, $request_started_at, $timings ) {
		$telemetry = self::build_run_telemetry( $timings, $request_started_at );

		return YoRo_REST_Runtime_Log::build_context(
			$trace_id,
			$plan,
			$runtime,
			[
				'execution_source' => 'chat',
				'parser_source'    => $parsed['parser_source'] ?? 'rule',
				'parser_rule'      => $parsed['parser_rule'] ?? '',
				'resolution_path'  => $parsed['resolution_path'] ?? '',
				'router_mode'      => $parsed['command_decision']['mode'] ?? '',
				'catalog_action_count' => $parsed['command_decision']['catalog_action_count'] ?? 0,
				'clarify_reason'   => $parsed['clarify_reason'] ?? '',
				'fallback_reason'  => $parsed['fallback_reason'] ?? '',
				'params_trimmed_count' => $parsed['params_trimmed_count'] ?? 0,
				'intent_name'      => $parsed['intent']['name'] ?? '',
				'intent_family'    => $parsed['intent_family'] ?? ( $parsed['command_decision']['intent_family'] ?? '' ),
				'language'         => $parsed['language'] ?? ( $parsed['command_decision']['language'] ?? '' ),
				'confidence'       => $parsed['confidence'] ?? null,
				'ai_meta'          => isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [],
				'command_shape'    => isset( $parsed['command_shape'] ) && is_array( $parsed['command_shape'] ) ? $parsed['command_shape'] : [],
				'semantic_frame'   => isset( $parsed['semantic_frame'] ) && is_array( $parsed['semantic_frame'] ) ? $parsed['semantic_frame'] : [],
				'semantic_frame_validator_changes' => isset( $parsed['semantic_frame_validator_changes'] ) && is_array( $parsed['semantic_frame_validator_changes'] ) ? $parsed['semantic_frame_validator_changes'] : [],
				'retrieved_examples' => isset( $parsed['retrieved_examples'] ) && is_array( $parsed['retrieved_examples'] ) ? $parsed['retrieved_examples'] : [],
				'route_candidates' => isset( $parsed['route_candidates'] ) && is_array( $parsed['route_candidates'] ) ? $parsed['route_candidates'] : [],
				'request_started_at' => $request_started_at,
				'stage_timings'    => $telemetry['stage_timings'],
				'ai_requests'      => $telemetry['ai']['requests'],
				'ai_total_ms'      => $telemetry['ai']['total_ms'],
			]
		);
	}

	private static function build_response_telemetry( $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ) {
		$telemetry = self::build_run_telemetry( $timings, $request_started_at );
		$parsed    = is_array( $parsed ) ? $parsed : [];
		$plan      = is_array( $plan ) ? $plan : [];
		$runtime   = is_array( $runtime ) ? $runtime : [];

		return YoRo_REST_Runtime_Log::build_context(
			$trace_id,
			$plan,
			$runtime,
			[
				'execution_source' => 'chat',
				'status'           => ! empty( $parsed['success'] ) && ! empty( $parsed['matched'] ) ? 'parsed' : sanitize_key( $parsed['stage'] ?? 'parse' ),
				'action'           => sanitize_text_field( $parsed['action'] ?? ( $plan['action'] ?? '' ) ),
				'parser_source'    => $parsed['parser_source'] ?? 'rule',
				'parser_rule'      => $parsed['parser_rule'] ?? '',
				'resolution_path'  => $parsed['resolution_path'] ?? '',
				'router_mode'      => $parsed['command_decision']['mode'] ?? '',
				'catalog_action_count' => $parsed['command_decision']['catalog_action_count'] ?? 0,
				'clarify_reason'   => $parsed['clarify_reason'] ?? '',
				'fallback_reason'  => $parsed['fallback_reason'] ?? '',
				'params_trimmed_count' => $parsed['params_trimmed_count'] ?? 0,
				'intent_name'      => $parsed['intent']['name'] ?? '',
				'intent_family'    => $parsed['intent_family'] ?? ( $parsed['command_decision']['intent_family'] ?? '' ),
				'language'         => $parsed['language'] ?? ( $parsed['command_decision']['language'] ?? '' ),
				'confidence'       => $parsed['confidence'] ?? null,
				'ai_meta'          => isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [],
				'command_shape'    => isset( $parsed['command_shape'] ) && is_array( $parsed['command_shape'] ) ? $parsed['command_shape'] : [],
				'semantic_frame'   => isset( $parsed['semantic_frame'] ) && is_array( $parsed['semantic_frame'] ) ? $parsed['semantic_frame'] : [],
				'semantic_frame_validator_changes' => isset( $parsed['semantic_frame_validator_changes'] ) && is_array( $parsed['semantic_frame_validator_changes'] ) ? $parsed['semantic_frame_validator_changes'] : [],
				'retrieved_examples' => isset( $parsed['retrieved_examples'] ) && is_array( $parsed['retrieved_examples'] ) ? $parsed['retrieved_examples'] : [],
				'route_candidates' => isset( $parsed['route_candidates'] ) && is_array( $parsed['route_candidates'] ) ? $parsed['route_candidates'] : [],
				'request_started_at' => $request_started_at,
				'stage_timings'    => $telemetry['stage_timings'],
				'ai_requests'      => $telemetry['ai']['requests'],
				'ai_total_ms'      => $telemetry['ai']['total_ms'],
			]
		);
	}

	private static function attach_response_language_to_parsed_params( $parsed ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$language = sanitize_key(
			$parsed['language']
				?? ( $parsed['command_decision']['language'] ?? '' )
		);

		if ( '' === $language ) {
			return $parsed;
		}

		if ( empty( $parsed['params'] ) || ! is_array( $parsed['params'] ) ) {
			$parsed['params'] = [];
		}

		$parsed['params']['response_language'] = $language;

		return $parsed;
	}

	private static function attach_telemetry_to_response( $response, $trace_id, $parsed, $plan, $runtime, $request_started_at, $timings ) {
		$payload = $response instanceof WP_REST_Response ? $response->get_data() : $response;

		if ( ! is_array( $payload ) ) {
			return $response;
		}

		$payload['telemetry'] = self::build_response_telemetry(
			$trace_id,
			$parsed,
			$plan,
			$runtime,
			$request_started_at,
			$timings
		);

		return rest_ensure_response( $payload );
	}

	private static function should_bypass_low_confidence_ai_confirmation( $parsed, $plan = [] ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan   = is_array( $plan ) ? $plan : [];

		if ( 'ai_router' !== sanitize_key( $parsed['parser_source'] ?? '' ) ) {
			return false;
		}

		if ( 'orders.search' !== sanitize_text_field( $parsed['action'] ?? '' ) ) {
			return false;
		}

		if ( ! empty( $parsed['missing'] ) || ! empty( $parsed['ambiguities'] ) ) {
			return false;
		}

		if ( ! self::is_semantically_grounded_read_only_search_plan( $plan ) ) {
			return false;
		}

		$params = isset( $parsed['params'] ) && is_array( $parsed['params'] )
			? $parsed['params']
			: [];

		if ( ! empty( $params ) ) {
			return true;
		}

		return ! empty( $parsed['summary'] ) || ! empty( $parsed['reasoning_summary'] );
	}

	private static function is_semantically_grounded_read_only_search_plan( $plan ) {
		if ( empty( $plan ) || ! is_array( $plan ) ) {
			return false;
		}

		$actions = [];

		if ( ! empty( $plan['action'] ) ) {
			$actions[] = sanitize_text_field( $plan['action'] );
		}

		if ( ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
			foreach ( $plan['steps'] as $step ) {
				if ( ! is_array( $step ) || empty( $step['action'] ) ) {
					continue;
				}

				$actions[] = sanitize_text_field( $step['action'] );
			}
		}

		$actions = array_values( array_unique( array_filter( $actions ) ) );

		if ( [ 'orders.search' ] !== $actions ) {
			return false;
		}

		if ( ! class_exists( 'YoRo_Action_Registry' ) ) {
			return false;
		}

		$action_def = YoRo_Action_Registry::get( 'orders.search' );

		if ( ! is_array( $action_def ) ) {
			return false;
		}

		return empty( $action_def['write_action'] ) && empty( $action_def['requires_confirmation'] );
	}

	private static function build_run_telemetry( $timings, $request_started_at ) {
		$stage_timings = [];

		foreach ( (array) $timings as $stage => $duration_ms ) {
			$stage_timings[ sanitize_key( (string) $stage ) ] = max( 0, (int) $duration_ms );
		}

		$stage_timings['total_ms'] = self::duration_ms( $request_started_at );
		$ai_summary = class_exists( 'YoRo_AI_Request_Tracker' )
			? YoRo_AI_Request_Tracker::summary()
			: [
				'count'    => 0,
				'total_ms' => 0,
				'requests' => [],
			];

		return [
			'stage_timings' => $stage_timings,
			'ai'            => $ai_summary,
		];
	}

	private static function duration_ms( $started_at ) {
		return max( 0, (int) round( ( microtime( true ) - (float) $started_at ) * 1000 ) );
	}

	private static function merge_runtime_context( $server_memory, $client_context, $confirm ) {
		$server_memory  = is_array( $server_memory ) ? $server_memory : [];
		$client_context = is_array( $client_context ) ? $client_context : [];

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

		$server_workflow = isset( $server_memory['current_workflow'] ) && is_array( $server_memory['current_workflow'] )
			? $server_memory['current_workflow']
			: [];

		if ( ! $confirm && self::is_authoritative_pending_workflow( $server_workflow ) ) {
			$merged_context['current_workflow'] = $server_workflow;
		}

		return $merged_context;
	}

	private static function is_authoritative_pending_workflow( $workflow ) {
		if ( empty( $workflow ) || ! is_array( $workflow ) ) {
			return false;
		}

		if ( 'collect_missing_params' !== sanitize_key( $workflow['step'] ?? '' ) ) {
			return false;
		}

		if ( empty( $workflow['action'] ) ) {
			return false;
		}

		$missing = isset( $workflow['missing'] ) && is_array( $workflow['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $workflow['missing'] ) ) )
			: [];

		return ! empty( $missing );
	}
}
