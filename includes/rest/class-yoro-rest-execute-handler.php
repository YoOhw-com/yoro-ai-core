<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Execute_Handler {

	public static function handle( WP_REST_Request $request ) {
		$request_started_at = microtime( true );
		$rate_limit = YoRo_Rate_Limiter::check( 'execute' );

		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		$action  = sanitize_text_field( $request->get_param( 'action' ) );
		$params  = $request->get_param( 'params' );
		$confirm = (bool) $request->get_param( 'confirm' );
		$preview = (bool) $request->get_param( 'preview' );
		$action_state_token = sanitize_text_field(
			$request->get_param( 'action_state_token' )
				?: $request->get_param( 'pending_action_token' )
		);
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		if ( empty( $action ) ) {
			return new WP_Error(
				'yoro_empty_action',
				__( 'Action is required.', 'yoro-agent-core' )
			);
		}

		$registered_action = YoRo_Action_Registry::get( $action );

		if ( ! $registered_action ) {
			return new WP_Error(
				'yoro_invalid_action',
				__( 'Action not found.', 'yoro-agent-core' )
			);
		}

		$user_id = get_current_user_id();
		$trace_id = 'yoro_' . wp_generate_uuid4();

		$parsed = [
			'success' => true,
			'matched' => true,
			'action'  => $action,
			'params'  => $params,
		];

		$plan = [
			'type'   => 'single',
			'action' => $action,
			'params' => $params,
			'parsed' => $parsed,
		];

		if ( $confirm && ! empty( $registered_action['write_action'] ) ) {
			$stored_action_state = YoRo_REST_Workflow_State::get_action(
				$user_id,
				$action_state_token,
				$action,
				$params
			);

			if ( empty( $stored_action_state ) ) {
				return rest_ensure_response(
					self::build_stale_action_confirmation_response( $trace_id )
				);
			}

			$parsed = $stored_action_state['parsed'];
			$plan   = $stored_action_state['plan'];
			$action = sanitize_text_field( $plan['action'] ?? $action );
			$params = isset( $plan['params'] ) && is_array( $plan['params'] )
				? $plan['params']
				: $params;
		}

		$policy_context = [
			'source'  => 'direct',
			'route'   => 'execute',
			'preview' => $preview,
			'confirm' => $confirm,
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
				'execution_source' => 'direct',
				'parser_source'    => 'direct',
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
			$response = YoRo_Runtime_Guard::build_confirmation_response(
				$plan,
				$parsed,
				$runtime
			);

			if ( ! empty( $registered_action['write_action'] ) ) {
				$action_state_token = YoRo_REST_Workflow_State::create_action_token(
					$user_id,
					$action,
					$params,
					$parsed,
					$plan
				);

				$response['action_state_token']  = $action_state_token;
				$response['pending_action_token'] = $action_state_token;
			}

			$response['trace_id'] = $trace_id;
			YoRo_REST_Execute_Log_Helper::log_runtime_confirmation_required(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$runtime
			);

			return rest_ensure_response( $response );
		}

		$result = YoRo_Plan_Executor::execute( $plan, $confirm, $preview );

		if ( is_wp_error( $result ) ) {
			$result = YoRo_REST_Response_Helper::normalize_error_result( $action, $result );
		}

		if ( is_array( $result ) && empty( $result['success'] ) && empty( $result['requires_confirmation'] ) ) {
			YoRo_REST_Execute_Log_Helper::log_error(
				$user_id,
				$action,
				$params,
				$preview,
				$confirm,
				$trace_id,
				$runtime_log_context,
				$result
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
			$response = [
				'success' => true,
				'stage'   => 'preview',
				'trace_id' => $trace_id,
				'parsed'  => $parsed,
				'plan'    => $plan,
				'risk'    => $risk,
				'policy'  => $policy,
				'preview' => $result,
				'result'  => $result,
			];

			if ( ! empty( $registered_action['write_action'] ) ) {
				$action_state_token = YoRo_REST_Workflow_State::create_action_token(
					$user_id,
					$action,
					$params,
					$parsed,
					$plan
				);

				$response['action_state_token']  = $action_state_token;
				$response['pending_action_token'] = $action_state_token;
			}

			YoRo_REST_Execute_Log_Helper::log_preview(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result,
				$runtime
			);

			return rest_ensure_response( $response );
		}

		if ( is_array( $result ) && ! empty( $result['requires_confirmation'] ) ) {
			$result['result_type']  = $result['result_type'] ?? 'confirmation';
			$result['display_type'] = $result['display_type'] ?? 'confirmation';

			if ( ! empty( $registered_action['write_action'] ) ) {
				$action_state_token = YoRo_REST_Workflow_State::create_action_token(
					$user_id,
					$action,
					$params,
					$parsed,
					$plan
				);
			}

			YoRo_REST_Execute_Log_Helper::log_confirmation_required(
				$user_id,
				$action,
				$params,
				$trace_id,
				$runtime_log_context,
				$result,
				$runtime
			);

			return rest_ensure_response(
				[
					'success'          => false,
					'stage'            => 'confirm',
					'trace_id'         => $trace_id,
					'parsed'           => $parsed,
					'plan'             => $plan,
					'confirm_required' => true,
					'action_state_token'  => $action_state_token,
					'pending_action_token' => $action_state_token,
					'message'          => $result['message'] ?? __( 'Confirmation required.', 'yoro-agent-core' ),
					'result'           => $result,
					'risk'             => $risk,
					'policy'           => $policy,
				]
			);
		}

		$log_id = YoRo_REST_Execute_Log_Helper::log_executed(
			$user_id,
			$action,
			$params,
			$confirm,
			$trace_id,
			$runtime_log_context,
			$result,
			$runtime
		);

		YoRo_REST_Memory_Sync::maybe_update( $user_id, 'direct:' . $action, $parsed, $result );

		if ( $confirm && ! empty( $action_state_token ) ) {
			YoRo_REST_Workflow_State::delete( $user_id, $action_state_token );
		}

		return rest_ensure_response(
			[
				'success' => true,
				'stage'   => 'done',
				'trace_id' => $trace_id,
				'parsed'  => $parsed,
				'plan'    => $plan,
				'result'  => $result,
				'log_id'  => $log_id,
				'risk'    => $risk,
				'policy'  => $policy,
			]
		);
	}

	private static function build_stale_action_confirmation_response( $trace_id ) {
		return [
			'success'          => false,
			'stage'            => 'confirm',
			'trace_id'         => $trace_id,
			'confirm_required' => true,
			'message'          => __( 'This action confirmation is stale. Please review the action again before confirming.', 'yoro-agent-core' ),
			'result'           => [
				'success'      => false,
				'summary'      => __( 'This action confirmation is stale. Please review the action again before confirming.', 'yoro-agent-core' ),
				'message'      => __( 'This action confirmation is stale. Please review the action again before confirming.', 'yoro-agent-core' ),
				'read_only'    => true,
				'result_type'  => 'confirmation',
				'display_type' => 'confirmation',
				'errors'       => [
					[
						'code'    => 'yoro_stale_action_confirmation',
						'message' => __( 'This action confirmation is stale. Please review the action again before confirming.', 'yoro-agent-core' ),
					],
				],
			],
		];
	}
}
