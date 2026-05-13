<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Execute_Log_Helper {

	public static function log_runtime_confirmation_required( $user_id, $action, $params, $trace_id, $runtime_log_context, $runtime ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => 'direct:' . $action,
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

	public static function log_error( $user_id, $action, $params, $preview, $confirm, $trace_id, $runtime_log_context, $result ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => 'direct:' . $action,
				'action'  => $action,
				'params'  => $params,
				'preview' => $preview,
				'confirm' => $confirm,
				'status'  => 'error',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => $result,
				'error'   => $result['message'] ?? '',
			]
		);
	}

	public static function log_preview( $user_id, $action, $params, $trace_id, $runtime_log_context, $result, $runtime ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => 'direct:' . $action,
				'action'  => $action,
				'params'  => $params,
				'preview' => true,
				'confirm' => false,
				'status'  => 'preview',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => $result,
				'risk'    => $runtime['risk'] ?? [],
				'policy'  => $runtime['policy'] ?? [],
			]
		);
	}

	public static function log_confirmation_required( $user_id, $action, $params, $trace_id, $runtime_log_context, $result, $runtime ) {
		YoRo_Logger::log(
			[
				'user_id' => $user_id,
				'command' => 'direct:' . $action,
				'action'  => $action,
				'params'  => $params,
				'preview' => false,
				'confirm' => false,
				'status'  => 'confirm_required',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'  => $result,
				'risk'    => $runtime['risk'] ?? [],
				'policy'  => $runtime['policy'] ?? [],
			]
		);
	}

	public static function log_executed( $user_id, $action, $params, $confirm, $trace_id, $runtime_log_context, $result, $runtime ) {
		return YoRo_Logger::log(
			[
				'user_id'   => $user_id,
				'command'   => 'direct:' . $action,
				'action'    => $action,
				'params'    => $params,
				'preview'   => false,
				'confirm'   => $confirm,
				'status'    => 'executed',
				'trace_id' => $trace_id,
				'runtime'  => $runtime_log_context,
				'result'    => $result,
				'risk'      => $runtime['risk'] ?? [],
				'policy'    => $runtime['policy'] ?? [],
				'undo_data' => is_array( $result ) ? ( $result['undo_plan'] ?? [] ) : [],
			]
		);
	}
}
