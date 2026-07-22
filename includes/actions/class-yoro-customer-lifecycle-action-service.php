<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Customer_Lifecycle_Action_Service {

	const FOLLOWUP_META = '_yoro_customer_followup_tasks';

	public static function get_segments( $params ) {
		$params    = is_array( $params ) ? $params : [];
		$lifecycle = YoRo_Commerce_Intelligence_Service::get_customer_lifecycle( $params );

		if ( is_wp_error( $lifecycle ) ) {
			return $lifecycle;
		}

		$high_value_min = isset( $params['high_value_min'] ) && is_numeric( $params['high_value_min'] )
			? max( 0.0, (float) $params['high_value_min'] )
			: null;
		$segments       = [
			'single_order_in_window' => [],
			'repeat_in_window'       => [],
			'high_value_in_window'   => [],
		];

		foreach ( (array) ( $lifecycle['customers'] ?? [] ) as $customer ) {
			if ( ! is_array( $customer ) ) {
				continue;
			}

			$key = absint( $customer['order_count'] ?? 0 ) > 1 ? 'repeat_in_window' : 'single_order_in_window';
			$segments[ $key ][] = $customer;

			if ( null !== $high_value_min && (float) ( $customer['total'] ?? 0 ) >= $high_value_min ) {
				$segments['high_value_in_window'][] = $customer;
			}
		}

		return [
			'success'       => true,
			'action'        => 'customers.get_segments',
			'window'        => $lifecycle['window'] ?? [],
			'segments'      => $segments,
			'coverage_contract' => $lifecycle['coverage_contract'] ?? [],
			'classification_contract' => [
				'schema_version'       => 1,
				'window_scoped'        => true,
				'lifetime_claims'      => false,
				'high_value_threshold' => $high_value_min,
			],
			'count'         => count( (array) ( $lifecycle['customers'] ?? [] ) ),
			'read_only'     => true,
			'changes_store' => false,
		];
	}

	public static function create_followup_task( $params ) {
		$params          = is_array( $params ) ? $params : [];
		$customer_id     = absint( $params['customer_id'] ?? 0 );
		$instruction     = sanitize_textarea_field( $params['instruction'] ?? '' );
		$idempotency_key = sanitize_text_field( $params['idempotency_key'] ?? '' );
		$due_at          = sanitize_text_field( $params['due_at'] ?? '' );

		if ( $customer_id <= 0 || ! get_user_by( 'id', $customer_id ) ) {
			return new WP_Error( 'customer_not_found', __( 'I could not find that registered customer.', 'yoro-ai-core' ) );
		}

		if ( '' === $instruction || '' === $idempotency_key ) {
			return new WP_Error( 'invalid_customer_followup_task', __( 'A follow-up instruction and idempotency key are required.', 'yoro-ai-core' ) );
		}

		if ( '' !== $due_at ) {
			try {
				$due_at = ( new DateTimeImmutable( $due_at, wp_timezone() ) )->format( DATE_ATOM );
			} catch ( Exception $exception ) {
				return new WP_Error( 'invalid_customer_followup_due_at', __( 'Please provide a valid follow-up date.', 'yoro-ai-core' ) );
			}
		}

		$tasks   = get_user_meta( $customer_id, self::FOLLOWUP_META, true );
		$tasks   = is_array( $tasks ) ? $tasks : [];
		$task_id = 'followup_' . substr( hash( 'sha256', $customer_id . '|' . $idempotency_key ), 0, 24 );

		foreach ( $tasks as $task ) {
			if ( is_array( $task ) && ( $task['task_id'] ?? '' ) === $task_id ) {
				return [
					'success'       => true,
					'action'        => 'customers.create_followup_task',
					'task'          => $task,
					'idempotent'    => true,
					'count'         => 1,
					'changes_store' => false,
				];
			}
		}

		$task = [
			'task_id'      => $task_id,
			'customer_id'  => $customer_id,
			'instruction'  => $instruction,
			'due_at'       => $due_at,
			'status'       => 'open',
			'created_at'   => gmdate( DATE_ATOM ),
			'created_by'   => get_current_user_id(),
		];
		$tasks[] = $task;
		update_user_meta( $customer_id, self::FOLLOWUP_META, array_slice( $tasks, -100 ) );

		return [
			'success'       => true,
			'action'        => 'customers.create_followup_task',
			'task'          => $task,
			'idempotent'    => false,
			'count'         => 1,
			'read_only'     => false,
			'changes_store' => true,
		];
	}

	public static function preview_followup_task( $params ) {
		$params      = is_array( $params ) ? $params : [];
		$customer_id = absint( $params['customer_id'] ?? 0 );
		$blocked     = $customer_id <= 0
			|| ! get_user_by( 'id', $customer_id )
			|| '' === trim( (string) ( $params['instruction'] ?? '' ) )
			|| '' === trim( (string) ( $params['idempotency_key'] ?? '' ) );

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'customers.create_followup_task',
			'blocked'       => $blocked,
			'block_reason'  => $blocked ? 'invalid_customer_followup_task' : '',
			'customer_id'   => $customer_id,
			'changes_store' => ! $blocked,
		];
	}
}
