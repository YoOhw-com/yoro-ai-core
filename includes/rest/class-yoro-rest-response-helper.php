<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Response_Helper {

	public static function build_stale_workflow_confirmation_response( $trace_id ) {
		return [
			'success' => false,
			'stage'   => 'error',
			'trace_id' => $trace_id,
			'message' => __( 'This workflow confirmation is stale. Please generate a new preview before confirming.', 'yoro-agent-core' ),
			'result'  => [
				'success'      => false,
				'action'       => 'workflow.confirm',
				'summary'      => __( 'This workflow confirmation is stale. Please generate a new preview before confirming.', 'yoro-agent-core' ),
				'message'      => __( 'This workflow confirmation is stale. Please generate a new preview before confirming.', 'yoro-agent-core' ),
				'data'         => [],
				'meta'         => [],
				'processed'    => [],
				'skipped'      => [],
				'errors'       => [
					[
						'code'    => 'yoro_workflow_stale',
						'message' => __( 'This workflow confirmation is stale. Please generate a new preview before confirming.', 'yoro-agent-core' ),
					],
				],
				'count'        => 0,
				'read_only'    => true,
				'undoable'     => false,
				'undo_plan'    => [],
				'result_type'  => 'error',
				'display_type' => 'error',
				'error'        => [
					'code'    => 'yoro_workflow_stale',
					'message' => __( 'This workflow confirmation is stale. Please generate a new preview before confirming.', 'yoro-agent-core' ),
					'data'    => null,
				],
			],
		];
	}

	public static function normalize_error_result( $action, WP_Error $error ) {
		return [
			'success'      => false,
			'action'       => $action,
			'summary'      => '',
			'message'      => $error->get_error_message(),
			'error'        => [
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => $error->get_error_data(),
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [
				[
					'code'    => $error->get_error_code(),
					'message' => $error->get_error_message(),
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
}
