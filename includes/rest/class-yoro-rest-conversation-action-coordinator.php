<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Conversation_Action_Coordinator {

	public static function merge_pending_operation_token( $parsed, $pending_operation_token ) {
		if ( ! is_array( $parsed ) ) {
			return $parsed;
		}

		$pending_operation_token = sanitize_text_field( (string) $pending_operation_token );

		if ( '' === $pending_operation_token ) {
			return $parsed;
		}

		$parsed['meta'] = isset( $parsed['meta'] ) && is_array( $parsed['meta'] )
			? $parsed['meta']
			: [];
		$parsed['meta']['pending_operation_token'] = $pending_operation_token;

		return $parsed;
	}

	public static function confirm_pending_operation( $parsed, $fallback_pending_operation_token = '' ) {
		$conversation_id = self::conversation_id_from_parsed( $parsed );
		$pending_operation_token = self::pending_operation_token_from_parsed(
			$parsed,
			$fallback_pending_operation_token
		);

		if ( '' === $conversation_id || '' === $pending_operation_token ) {
			return true;
		}

		$client = YoRo_AI_Provider_Resolver::get_client();

		$response = $client->confirm_action(
			[
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_operation_token,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( is_array( $response ) && isset( $response['success'] ) && empty( $response['success'] ) ) {
			return new WP_Error(
				'yoro_conversation_confirm_failed',
				sanitize_text_field(
					$response['message'] ?? __( 'I could not confirm that pending operation.', 'yoro-ai-core' )
				)
			);
		}

		return $response;
	}

	public static function get_pending_operation_state( $conversation_id, $pending_operation_token ) {
		$conversation_id = sanitize_text_field( (string) $conversation_id );
		$pending_operation_token = sanitize_text_field( (string) $pending_operation_token );

		if ( '' === $conversation_id || '' === $pending_operation_token ) {
			return null;
		}

		$pending_operation = self::fetch_pending_operation( $conversation_id, $pending_operation_token );

		if ( empty( $pending_operation ) || ! is_array( $pending_operation ) ) {
			return null;
		}

		$action_plan = isset( $pending_operation['action_plan'] ) && is_array( $pending_operation['action_plan'] )
			? $pending_operation['action_plan']
			: [];
		$operation_graph = isset( $pending_operation['operation_graph'] ) && is_array( $pending_operation['operation_graph'] )
			? $pending_operation['operation_graph']
			: [];
		$plan = self::build_plan_from_action_plan( $action_plan, $pending_operation );

		if ( empty( $plan ) ) {
			return null;
		}

		$parsed = [
			'success'         => true,
			'matched'         => true,
			'action'          => sanitize_text_field( $plan['action'] ?? ( $pending_operation['action'] ?? '' ) ),
			'params'          => isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [],
			'parser_source'   => 'laravel_pending_operation',
			'parser_rule'     => 'conversation.pending_operation_graph',
			'confidence'      => 1,
			'action_plan'     => $action_plan,
			'operation_graph' => $operation_graph,
			'meta'            => [
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_operation_token,
				'pending_operation_source' => 'laravel',
			],
		];

		$plan['parsed'] = $parsed;

		return [
			'parsed' => $parsed,
			'plan'   => $plan,
		];
	}

	public static function record_cancelled_action( WP_REST_Request $request ) {
		$conversation_id = sanitize_text_field( (string) $request->get_param( 'conversation_id' ) );
		$pending_operation_token = sanitize_text_field(
			(string) (
				$request->get_param( 'pending_operation_token' )
					?: $request->get_param( 'pending_action_token' )
			)
		);
		$action = sanitize_text_field( (string) $request->get_param( 'action' ) );
		$params = $request->get_param( 'params' );
		$params = is_array( $params ) ? $params : [];
		$trace_id = sanitize_text_field( (string) $request->get_param( 'trace_id' ) );

		if ( '' === $trace_id ) {
			$trace_id = 'yoro_' . wp_generate_uuid4();
		}

		$message = __( 'Action cancelled.', 'yoro-ai-core' );

		if ( '' === $conversation_id || '' === $pending_operation_token ) {
			return rest_ensure_response(
				self::build_cancelled_action_response(
					$conversation_id,
					$pending_operation_token,
					$action,
					$params,
					$trace_id,
					$message
				)
			);
		}

		$client = YoRo_AI_Provider_Resolver::get_client();

		$response = $client->record_action_execution(
			[
				'conversation_id'         => $conversation_id,
				'pending_operation_token' => $pending_operation_token,
				'event_type'              => 'cancelled',
				'status'                  => 'cancelled',
				'action'                  => $action,
				'summary'                 => $message,
				'params'                  => $params,
				'result'                  => [
					'success'      => true,
					'message'      => $message,
					'result_type'  => 'assistant_message',
					'display_type' => 'assistant_message',
				],
				'source'                  => 'plugin',
				'trace_id'                => $trace_id,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return rest_ensure_response(
			self::build_cancelled_action_response(
				$conversation_id,
				$pending_operation_token,
				$action,
				$params,
				$trace_id,
				$message
			)
		);
	}

	public static function conversation_id_from_parsed( $parsed, $fallback = '' ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$meta   = isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [];

		return sanitize_text_field(
			$meta['conversation_id']
				?? ( $meta['yoro_ai_api_conversation_id'] ?? ( $parsed['conversation_id'] ?? $fallback ) )
		);
	}

	public static function pending_operation_token_from_parsed( $parsed, $fallback = '' ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$meta   = isset( $parsed['meta'] ) && is_array( $parsed['meta'] ) ? $parsed['meta'] : [];

		return sanitize_text_field(
			$meta['pending_operation_token']
				?? ( $meta['yoro_ai_api_pending_operation_token'] ?? ( $parsed['pending_operation_token'] ?? $fallback ) )
		);
	}

	public static function build_confirmation_error_response( $trace_id, $error ) {
		$message = is_wp_error( $error )
			? $error->get_error_message()
			: __( 'I could not confirm that pending operation.', 'yoro-ai-core' );

		return [
			'success' => false,
			'stage'   => 'error',
			'trace_id' => sanitize_text_field( (string) $trace_id ),
			'message' => $message,
			'result'  => [
				'success'      => false,
				'summary'      => $message,
				'message'      => $message,
				'read_only'    => true,
				'result_type'  => 'error',
				'display_type' => 'error',
			],
		];
	}

	private static function build_cancelled_action_response( $conversation_id, $pending_operation_token, $action, $params, $trace_id, $message ) {
		$conversation_id = sanitize_text_field( (string) $conversation_id );
		$pending_operation_token = sanitize_text_field( (string) $pending_operation_token );
		$action = sanitize_text_field( (string) $action );

		return [
			'success'                 => true,
			'stage'                   => 'cancelled',
			'conversation_id'         => $conversation_id,
			'pending_operation_token' => $pending_operation_token,
			'trace_id'                => sanitize_text_field( (string) $trace_id ),
			'message'                 => sanitize_text_field( (string) $message ),
			'parsed'                  => [
				'success'       => true,
				'matched'       => true,
				'stage'         => 'cancelled',
				'action'        => $action,
				'params'        => is_array( $params ) ? $params : [],
				'parser_source' => 'conversation_orchestrator',
				'parser_rule'   => 'laravel.conversation.cancelled',
				'meta'          => [
					'conversation_id'         => $conversation_id,
					'pending_operation_token' => $pending_operation_token,
				],
			],
			'result'                  => [
				'success'      => true,
				'action'       => $action,
				'params'       => is_array( $params ) ? $params : [],
				'message'      => sanitize_text_field( (string) $message ),
				'summary'      => sanitize_text_field( (string) $message ),
				'read_only'    => true,
				'result_type'  => 'assistant_message',
				'display_type' => 'assistant_message',
			],
		];
	}

	private static function fetch_pending_operation( $conversation_id, $pending_operation_token ) {
		$client = YoRo_AI_Provider_Resolver::get_client();

		$response = $client->get_conversation( $conversation_id );

		if ( is_wp_error( $response ) || empty( $response['conversation'] ) || ! is_array( $response['conversation'] ) ) {
			return null;
		}

		$pending_operations = isset( $response['conversation']['pending_operation_graphs'] ) && is_array( $response['conversation']['pending_operation_graphs'] )
			? $response['conversation']['pending_operation_graphs']
			: [];

		foreach ( $pending_operations as $pending_operation ) {
			if ( ! is_array( $pending_operation ) ) {
				continue;
			}

			if ( sanitize_text_field( $pending_operation['token'] ?? '' ) === $pending_operation_token ) {
				return $pending_operation;
			}
		}

		return null;
	}

	private static function build_plan_from_action_plan( $action_plan, $pending_operation ) {
		$action_plan = is_array( $action_plan ) ? $action_plan : [];
		$pending_operation = is_array( $pending_operation ) ? $pending_operation : [];
		$execution_context = self::execution_context_from_pending_operation( $pending_operation );
		$type = sanitize_key( $action_plan['type'] ?? '' );
		$steps = isset( $action_plan['steps'] ) && is_array( $action_plan['steps'] )
			? array_values( $action_plan['steps'] )
			: [];

		if ( '' === $type ) {
			$type = count( $steps ) > 1 ? 'sequence' : 'single';
		}

		if ( 'sequence' === $type ) {
			if ( empty( $steps ) ) {
				return [];
			}

			$plan = [
				'type'     => 'sequence',
				'action'   => sanitize_text_field( $action_plan['action'] ?? ( $pending_operation['action'] ?? 'multi_action' ) ),
				'params'   => isset( $action_plan['params'] ) && is_array( $action_plan['params'] ) ? $action_plan['params'] : [],
				'steps'    => $steps,
				'workflow' => [
					'type'   => 'operation_graph',
					'action' => sanitize_text_field( $pending_operation['action'] ?? ( $action_plan['action'] ?? 'multi_action' ) ),
					'step'   => sanitize_key( $pending_operation['status'] ?? 'confirmed' ),
					'params' => [
						'pending_operation_graph' => isset( $pending_operation['operation_graph'] ) && is_array( $pending_operation['operation_graph'] )
							? $pending_operation['operation_graph']
							: [],
					],
				],
			];

			if ( ! empty( $execution_context ) ) {
				$plan['context'] = $execution_context;
			}

			return $plan;
		}

		$first_step = isset( $steps[0] ) && is_array( $steps[0] ) ? $steps[0] : [];
		$action = sanitize_text_field( $action_plan['action'] ?? ( $first_step['action'] ?? ( $pending_operation['action'] ?? '' ) ) );
		$params = isset( $action_plan['params'] ) && is_array( $action_plan['params'] )
			? $action_plan['params']
			: ( isset( $first_step['params'] ) && is_array( $first_step['params'] ) ? $first_step['params'] : [] );

		if ( '' === $action ) {
			return [];
		}

		$plan = [
			'type'   => 'single',
			'action' => $action,
			'params' => $params,
		];

		if ( ! empty( $execution_context ) ) {
			$plan['context'] = $execution_context;
		}

		return $plan;
	}

	private static function execution_context_from_pending_operation( $pending_operation ) {
		$pending_operation = is_array( $pending_operation ) ? $pending_operation : [];
		$profile_intelligence = $pending_operation['context']['router_payload']['context']['store_context_pack']['profile_intelligence'] ?? [];

		if ( ! is_array( $profile_intelligence ) || empty( $profile_intelligence ) ) {
			return [];
		}

		return [
			'store_context_pack' => [
				'profile_intelligence' => $profile_intelligence,
			],
		];
	}
}
