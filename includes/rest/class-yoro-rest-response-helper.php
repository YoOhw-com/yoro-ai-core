<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Response_Helper {

	private static function stale_pending_action_message() {
		return __( 'This approval request expired. Please review the action again before approving.', 'yoro-ai-core' );
	}

	public static function maybe_build_semantic_validation_response_from_result( $trace_id, $parsed, $plan, $risk, $policy, $result ) {
		$result = is_array( $result ) ? $result : [];
		$error  = isset( $result['error'] ) && is_array( $result['error'] ) ? $result['error'] : [];
		$code   = sanitize_key( $error['code'] ?? '' );

		if ( 0 !== strpos( $code, 'yoro_invalid_action_plan_' ) ) {
			return null;
		}

		$wp_error = new WP_Error(
			$code,
			sanitize_text_field( $error['message'] ?? ( $result['message'] ?? '' ) ),
			isset( $error['data'] ) ? $error['data'] : null
		);

		return self::maybe_build_semantic_validation_response(
			$trace_id,
			$parsed,
			$plan,
			$risk,
			$policy,
			$wp_error
		);
	}

	public static function maybe_build_semantic_validation_response( $trace_id, $parsed, $plan, $risk, $policy, WP_Error $error ) {
		$code = sanitize_key( $error->get_error_code() );

		if ( 0 !== strpos( $code, 'yoro_invalid_action_plan_' ) ) {
			return null;
		}

		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan   = is_array( $plan ) ? $plan : [];
		$risk   = is_array( $risk ) ? $risk : [];
		$policy = is_array( $policy ) ? $policy : [];

		$action  = sanitize_text_field( $parsed['action'] ?? ( $plan['action'] ?? '' ) );
		$message = self::build_friendly_semantic_error_message( $parsed, $plan, $error );
		$result  = self::normalize_error_result( $action, $error );
		$result['message'] = $message;
		$result['summary'] = $message;
		$result['errors'][0]['message'] = $message;
		$result['error']['message'] = $message;

		return [
			'success' => false,
			'stage'   => 'error',
			'trace_id' => $trace_id,
			'parsed'  => $parsed,
			'plan'    => $plan,
			'risk'    => $risk,
			'policy'  => $policy,
			'result'  => $result,
			'message' => $message,
		];
	}

	public static function build_stale_pending_action_confirmation_response( $trace_id ) {
		$message = self::stale_pending_action_message();

		return [
			'success' => false,
			'stage'   => 'error',
			'trace_id' => $trace_id,
			'message' => $message,
			'result'  => [
				'success'      => false,
				'action'       => 'action.confirm',
				'summary'      => $message,
				'message'      => $message,
				'data'         => [],
				'meta'         => [],
				'processed'    => [],
				'skipped'      => [],
				'errors'       => [
					[
						'code'    => 'yoro_action_preview_stale',
						'message' => $message,
					],
				],
				'count'        => 0,
				'read_only'    => true,
				'undoable'     => false,
				'undo_plan'    => [],
				'result_type'  => 'error',
				'display_type' => 'error',
				'error'        => [
					'code'    => 'yoro_action_preview_stale',
					'message' => $message,
					'data'    => null,
				],
			],
		];
	}

	public static function normalize_error_result( $action, WP_Error $error ) {
		$message = $error->get_error_message();

		$message = YoRo_Response_UX_Formatter::clean_user_facing_copy( $message );

		return [
			'success'      => false,
			'action'       => $action,
			'summary'      => '',
			'message'      => $message,
			'error'        => [
				'code'    => $error->get_error_code(),
				'message' => $message,
				'data'    => $error->get_error_data(),
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [
				[
					'code'    => $error->get_error_code(),
					'message' => $message,
				],
			],
			'count'        => 0,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'error',
			'display_type' => 'error',
		];
	}

	private static function build_friendly_semantic_error_message( $parsed, $plan, WP_Error $error ) {
		$plan   = is_array( $plan ) ? $plan : [];
		$parsed = is_array( $parsed ) ? $parsed : [];
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : [];
		$action = sanitize_text_field( $data['action'] ?? $parsed['action'] ?? $plan['action'] ?? '' );
		$order_id = absint( $data['order_id'] ?? 0 );

		if ( 'orders.refund_preview' === $action ) {
			return $order_id > 0
				? sprintf( __( 'Order #%d cannot continue with that refund request as requested.', 'yoro-ai-core' ), $order_id )
				: __( 'That refund request cannot continue as requested.', 'yoro-ai-core' );
		}

		return $error->get_error_message();
	}

}
