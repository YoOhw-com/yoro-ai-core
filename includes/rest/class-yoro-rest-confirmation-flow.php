<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Confirmation_Flow {

	public static function get_stored_workflow_state( $user_id, $workflow_state_token, $command, $confirm ) {
		if ( ! $confirm || empty( $workflow_state_token ) ) {
			return null;
		}

		return YoRo_REST_Workflow_State::get(
			$user_id,
			$workflow_state_token,
			$command
		);
	}

	public static function maybe_build_stale_response( $confirm, $workflow_state_token, $pending_confirmation, $stored_workflow_state, $trace_id ) {
		if (
			! $confirm ||
			empty( $pending_confirmation['required'] ) ||
			empty( $pending_confirmation['action'] )
		) {
			return null;
		}

		if ( empty( $workflow_state_token ) ) {
			return rest_ensure_response(
				YoRo_REST_Response_Helper::build_stale_workflow_confirmation_response( $trace_id )
			);
		}

		if (
			empty( $stored_workflow_state ) &&
			! empty( $pending_confirmation['token'] ) &&
			sanitize_text_field( $pending_confirmation['token'] ) !== $workflow_state_token
		) {
			return rest_ensure_response(
				YoRo_REST_Response_Helper::build_stale_workflow_confirmation_response( $trace_id )
			);
		}

		if ( empty( $stored_workflow_state ) ) {
			return rest_ensure_response(
				YoRo_REST_Response_Helper::build_stale_workflow_confirmation_response( $trace_id )
			);
		}

		return null;
	}

	public static function create_pending_state( $user_id, $command, $parsed, $plan, $action, $trace_id ) {
		$workflow_state_token = YoRo_REST_Workflow_State::create_token(
			$user_id,
			$command,
			$parsed,
			$plan
		);

		YoRo_Conversation_Memory::set_pending_confirmation(
			$user_id,
			[
				'token'         => $workflow_state_token,
				'command'       => $command,
				'action'        => $action,
				'parser_source' => $parsed['parser_source'] ?? 'rule',
				'trace_id'      => $trace_id,
				'created_at'    => current_time( 'mysql' ),
				'expires_at'    => wp_date( 'Y-m-d H:i:s', time() + YoRo_REST_Workflow_State::get_ttl(), wp_timezone() ),
				'required'      => true,
			]
		);

		return $workflow_state_token;
	}

	public static function clear_pending_state( $user_id, $confirm, $workflow_state_token ) {
		if ( $confirm && ! empty( $workflow_state_token ) ) {
			YoRo_REST_Workflow_State::delete( $user_id, $workflow_state_token );
			YoRo_Conversation_Memory::clear_pending_confirmation( $user_id, $workflow_state_token );
			return;
		}

		YoRo_Conversation_Memory::clear_pending_confirmation( $user_id );
	}
}
