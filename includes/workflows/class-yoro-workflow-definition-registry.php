<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Definition_Registry {

	public static function all() {
		$definitions = [
			'composed_actions'     => [
				'id'             => 'composed_actions',
				'label'          => __( 'Composed workflow', 'yoro-agent-core' ),
				'description'    => __( 'YoRo assembled this workflow from the supported actions it recognized in the request.', 'yoro-agent-core' ),
				'category'       => 'workflow',
				'surface'        => 'admin_copilot',
				'risk_level'     => 'medium',
				'preview_action' => 'workflow.composed_actions',
				'steps'          => [],
			],
			'fulfillment_followup' => [
				'id'             => 'fulfillment_followup',
				'label'          => __( 'Fulfillment follow-up', 'yoro-agent-core' ),
				'description'    => __( 'Prepare the next fulfillment follow-up and save the reviewed customer email draft after confirmation.', 'yoro-agent-core' ),
				'category'       => 'workflow',
				'surface'        => 'admin_copilot',
				'risk_level'     => 'medium',
				'preview_action' => 'workflow.fulfillment_followup_preview',
				'steps'          => [
					[
						'id'                    => 'preview',
						'label'                 => __( 'Prepare preview', 'yoro-agent-core' ),
						'kind'                  => 'preview',
						'action'                => 'workflow.fulfillment_followup_preview',
						'requires_confirmation' => false,
					],
					[
						'id'                    => 'save_draft',
						'label'                 => __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
						'kind'                  => 'write',
						'action'                => 'orders.save_customer_email_draft',
						'requires_confirmation' => true,
					],
				],
			],
				'customer_followup'    => [
					'id'             => 'customer_followup',
				'label'          => __( 'Customer follow-up', 'yoro-agent-core' ),
				'description'    => __( 'Prepare the next customer follow-up and save or update the reviewed customer email draft after confirmation.', 'yoro-agent-core' ),
				'category'       => 'workflow',
				'surface'        => 'admin_copilot',
				'risk_level'     => 'medium',
				'preview_action' => 'workflow.customer_followup_preview',
				'steps'          => [
					[
						'id'                    => 'preview',
						'label'                 => __( 'Prepare preview', 'yoro-agent-core' ),
						'kind'                  => 'preview',
						'action'                => 'workflow.customer_followup_preview',
						'requires_confirmation' => false,
					],
					[
						'id'                    => 'save_or_update_draft',
						'label'                 => __( 'Save reviewed customer email draft', 'yoro-agent-core' ),
						'kind'                  => 'write',
						'action'                => 'orders.save_customer_email_draft',
						'requires_confirmation' => true,
					],
					],
				],
				'operations_attention' => [
					'id'             => 'operations_attention',
					'label'          => __( 'Operations attention', 'yoro-agent-core' ),
					'description'    => __( 'Select the next operations attention item and prepare a guided review preview.', 'yoro-agent-core' ),
					'category'       => 'workflow',
					'surface'        => 'admin_copilot',
					'risk_level'     => 'low',
					'preview_action' => 'workflow.operations_attention_preview',
					'steps'          => [
						[
							'id'                    => 'preview',
							'label'                 => __( 'Prepare preview', 'yoro-agent-core' ),
							'kind'                  => 'preview',
							'action'                => 'workflow.operations_attention_preview',
							'requires_confirmation' => false,
						],
					],
				],
				'inventory_pressure'   => [
					'id'             => 'inventory_pressure',
					'label'          => __( 'Inventory pressure', 'yoro-agent-core' ),
					'description'    => __( 'Select the next inventory pressure item and prepare a guided review preview.', 'yoro-agent-core' ),
					'category'       => 'workflow',
					'surface'        => 'admin_copilot',
					'risk_level'     => 'low',
					'preview_action' => 'workflow.inventory_pressure_preview',
					'steps'          => [
						[
							'id'                    => 'preview',
							'label'                 => __( 'Prepare preview', 'yoro-agent-core' ),
							'kind'                  => 'preview',
							'action'                => 'workflow.inventory_pressure_preview',
							'requires_confirmation' => false,
						],
					],
				],
				'business_summary'     => [
					'id'             => 'business_summary',
					'label'          => __( 'Business summary', 'yoro-agent-core' ),
					'description'    => __( 'Select the next business signal and prepare a guided review preview.', 'yoro-agent-core' ),
					'category'       => 'workflow',
					'surface'        => 'admin_copilot',
					'risk_level'     => 'low',
					'preview_action' => 'workflow.business_summary_preview',
					'steps'          => [
						[
							'id'                    => 'preview',
							'label'                 => __( 'Prepare preview', 'yoro-agent-core' ),
							'kind'                  => 'preview',
							'action'                => 'workflow.business_summary_preview',
							'requires_confirmation' => false,
						],
					],
				],
			];

		if ( class_exists( 'YoRo_Workflow_Templates' ) ) {
			foreach ( YoRo_Workflow_Templates::all() as $template_id => $template ) {
				$definitions[ sanitize_key( $template_id ) ] = self::from_template( $template_id, $template );
			}
		}

		return apply_filters( 'yoro_workflow_definitions', $definitions );
	}

	public static function get( $definition_id ) {
		$definition_id = sanitize_key( $definition_id );
		$definitions   = self::all();

		return $definitions[ $definition_id ] ?? null;
	}

	private static function from_template( $template_id, $template ) {
		$template_id = sanitize_key( $template_id );
		$template    = is_array( $template ) ? $template : [];
		$steps       = [];

		foreach ( (array) ( $template['steps'] ?? [] ) as $index => $step ) {
			$steps[] = [
				'id'                    => sanitize_key( $step['id'] ?? 'step_' . ( $index + 1 ) ),
				'label'                 => sanitize_text_field( $step['label'] ?? ( $step['action'] ?? __( 'Workflow step', 'yoro-agent-core' ) ) ),
				'kind'                  => sanitize_key( $step['kind'] ?? 'write' ),
				'action'                => sanitize_text_field( $step['action'] ?? '' ),
				'requires_confirmation' => true,
				'params'                => isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [],
				'when'                  => isset( $step['when'] ) && is_array( $step['when'] ) ? $step['when'] : [],
				'capture'               => isset( $step['capture'] ) && is_array( $step['capture'] ) ? $step['capture'] : [],
				'on_error'              => ! empty( $step['on_error'] ) ? sanitize_key( $step['on_error'] ) : 'stop',
				'retry'                 => isset( $step['retry'] ) && is_array( $step['retry'] ) ? $step['retry'] : [ 'max_attempts' => 1 ],
				'rollback'              => isset( $step['rollback'] ) && is_array( $step['rollback'] ) ? $step['rollback'] : [],
				'meta'                  => isset( $step['meta'] ) && is_array( $step['meta'] ) ? $step['meta'] : [],
			];
		}

		return [
			'id'             => $template_id,
			'label'          => sanitize_text_field( $template['label'] ?? $template_id ),
			'description'    => sanitize_text_field( $template['description'] ?? '' ),
			'category'       => sanitize_key( $template['category'] ?? 'workflow' ),
			'surface'        => 'admin_copilot',
			'risk_level'     => sanitize_key( $template['risk_level'] ?? 'low' ),
			'preview_action' => 'workflow.' . $template_id,
			'steps'          => $steps,
		];
	}
}
