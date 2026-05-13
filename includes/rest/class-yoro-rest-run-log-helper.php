<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Run_Log_Helper {

	public static function log_low_confidence_confirmation( $user_id, $command, $parsed, $trace_id, $confidence, $source ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $parsed['action'] ?? '',
				'params'  => $parsed['params'] ?? [],
				'preview' => false,
				'confirm' => false,
				'status'  => 'confirm_required',
				'trace_id' => $trace_id,
				'result'  => [
					'reason'     => 'low_ai_router_confidence',
					'confidence' => $confidence,
					'source'     => $source,
				],
			]
		);
	}

	public static function log_plan_error( $user_id, $command, $action, $params, $trace_id, WP_Error $plan ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $action,
				'params'  => $params,
				'status'  => 'error',
				'trace_id' => $trace_id,
				'error'   => $plan->get_error_message(),
				'result'  => [
					'error_code' => $plan->get_error_code(),
					'error_data' => $plan->get_error_data(),
				],
			]
		);
	}

	public static function log_confirmation_required( $user_id, $command, $action, $params, $trace_id, $runtime_log_context, $result ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $action,
				'params'  => $params,
				'preview' => false,
				'confirm' => false,
				'status'  => 'confirm_required',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => $result,
			]
		);
	}

	public static function log_runtime_confirmation_required( $user_id, $command, $action, $params, $trace_id, $runtime_log_context, $runtime ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $action,
				'params'  => $params,
				'preview' => false,
				'confirm' => false,
				'status'  => 'confirm_required',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => [
					'reason'  => $runtime['reason'] ?? 'runtime_guard',
					'risk'    => $runtime['risk'] ?? [],
					'policy'  => $runtime['policy'] ?? [],
				],
			]
		);
	}

	public static function log_error( $user_id, $command, $action, $params, $preview, $confirm, $trace_id, $runtime_log_context, $result, $risk, $policy ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $action,
				'params'  => $params,
				'preview' => $preview,
				'confirm' => $confirm,
				'status'  => 'error',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => $result,
				'error'   => $result['message'] ?? '',
				'risk'    => $risk,
				'policy'  => $policy,
			]
		);
	}

	public static function log_preview( $user_id, $command, $action, $params, $trace_id, $runtime_log_context, $result, $risk, $policy ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => $command,
				'action'  => $action,
				'params'  => $params,
				'preview' => true,
				'confirm' => false,
				'status'  => 'preview',
				'trace_id' => $trace_id,
				'runtime' => $runtime_log_context,
				'result'  => $result,
				'risk'    => $risk,
				'policy'  => $policy,
			]
		);
	}

	public static function log_executed( $user_id, $command, $action, $params, $confirm, $trace_id, $runtime_log_context, $result, $risk, $policy ) {
		return YoRo_Logger::log(
			[
				'user_id'   => $user_id,
				'command'   => $command,
				'action'    => $action,
				'params'    => $params,
				'preview'   => false,
				'confirm'   => $confirm,
				'status'    => 'executed',
				'trace_id'  => $trace_id,
				'runtime'   => $runtime_log_context,
				'result'    => $result,
				'risk'      => $risk,
				'policy'    => $policy,
				'undo_data' => is_array( $result ) ? ( $result['undo_plan'] ?? [] ) : [],
			]
		);
	}
}
