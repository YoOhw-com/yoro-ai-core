<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Semantic_Frame_Validator {

	public static function apply_to_decision( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];

		if ( ! class_exists( 'YoRo_Semantic_Frame_Normalizer' ) ) {
			return $decision;
		}

		$frame = YoRo_Semantic_Frame_Normalizer::normalize( $decision['semantic_frame'] ?? [] );
		$decision['semantic_frame'] = $frame;

		if ( ! YoRo_Semantic_Frame_Normalizer::is_informative( $frame ) ) {
			return $decision;
		}

		$changes = isset( $decision['semantic_frame_validator_changes'] ) && is_array( $decision['semantic_frame_validator_changes'] )
			? $decision['semantic_frame_validator_changes']
			: [];

		if ( self::frame_targets_missing_order_write( $frame ) ) {
			$repair = self::force_write_missing_target_clarify( $decision, $frame );
			$decision = $repair['decision'];
			$changes = array_merge( $changes, $repair['changes'] );
		}

		if ( self::frame_targets_explicit_order_write( $frame ) && self::can_override_to_order_write( $decision, $frame ) ) {
			$repair = self::force_explicit_order_write( $decision, $frame );
			$decision = $repair['decision'];
			$changes = array_merge( $changes, $repair['changes'] );
		}

		if ( self::frame_targets_order_search( $frame ) && self::can_override_to_orders_search( $decision ) ) {
			$previous_action = sanitize_text_field( $decision['action'] ?? '' );
			$decision['mode'] = 'task_request';
			$decision['action'] = 'orders.search';
			$decision['intent_family'] = 'search_orders';
			$decision['missing'] = [];
			$decision['ambiguities'] = [];
			$decision['clarification_question'] = '';
			$changes[] = [
				'type'     => 'action_override',
				'param'    => 'action',
				'value'    => 'orders.search',
				'previous' => '' !== $previous_action ? $previous_action : null,
				'reason'   => 'semantic_frame_orders_search',
			];
		}

		if ( self::frame_targets_order_details( $frame ) && self::can_override_to_order_details( $decision ) ) {
			$previous_action = sanitize_text_field( $decision['action'] ?? '' );
			$decision['mode'] = 'task_request';
			$decision['action'] = 'orders.get_details';
			$decision['intent_family'] = 'get_order_details';
			$decision['missing'] = [];
			$decision['ambiguities'] = [];
			$decision['clarification_question'] = '';
			$changes[] = [
				'type'     => 'action_override',
				'param'    => 'action',
				'value'    => 'orders.get_details',
				'previous' => '' !== $previous_action ? $previous_action : null,
				'reason'   => 'semantic_frame_order_details',
			];
		}

		if ( 'orders.search' === ( $decision['action'] ?? '' ) && 'task_request' === ( $decision['mode'] ?? '' ) ) {
			$repair = self::repair_orders_search_params( $decision, $frame );
			$decision = $repair['decision'];
			$changes = array_merge( $changes, $repair['changes'] );
		}

		if ( 'orders.get_details' === ( $decision['action'] ?? '' ) && 'task_request' === ( $decision['mode'] ?? '' ) ) {
			$repair = self::repair_order_details_params( $decision, $frame );
			$decision = $repair['decision'];
			$changes = array_merge( $changes, $repair['changes'] );
		}

		if ( ! empty( $changes ) ) {
			$decision['semantic_frame_validator_changes'] = $changes;
		}

		return $decision;
	}

	private static function repair_orders_search_params( $decision, $frame ) {
		$params      = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$changes     = [];

		$status = sanitize_key( $constraints['status'] ?? '' );
		if (
			'' !== $status &&
			( ! class_exists( 'YoRo_Order_Helper' ) || YoRo_Order_Helper::is_valid_order_status( $status ) )
		) {
			$previous_status = self::first_status_param( $params['status'] ?? null );

			if ( $previous_status !== $status ) {
				$params['status'] = [ $status ];
				$changes[] = self::change(
					'status',
					$status,
					null === $previous_status ? null : $previous_status
				);
			}
		}

		$quantity = isset( $constraints['quantity'] ) && null !== $constraints['quantity'] ? absint( $constraints['quantity'] ) : 0;
		if ( $quantity > 0 && absint( $params['limit'] ?? 0 ) !== $quantity ) {
			$previous_limit = isset( $params['limit'] ) && '' !== $params['limit'] ? absint( $params['limit'] ) : null;
			$params['limit'] = $quantity;
			$changes[] = self::change( 'limit', $quantity, $previous_limit );
		}

		$date = isset( $constraints['date'] ) && is_array( $constraints['date'] ) ? $constraints['date'] : [];
		$date_window = sanitize_key( $date['window'] ?? '' );
		if ( '' !== $date_window && sanitize_key( $params['date_window'] ?? '' ) !== $date_window ) {
			$previous_date_window = ! empty( $params['date_window'] ) ? sanitize_key( $params['date_window'] ) : null;
			$params['date_window'] = $date_window;
			$changes[] = self::change( 'date_window', $date_window, $previous_date_window );
		}

		$min_age_hours = self::resolve_min_age_hours_from_date_constraint( $date );
		if ( $min_age_hours > 0 && absint( $params['min_age_hours'] ?? 0 ) !== $min_age_hours ) {
			$previous_min_age_hours = ! empty( $params['min_age_hours'] ) ? absint( $params['min_age_hours'] ) : null;
			unset( $params['date_window'] );
			$params['min_age_hours'] = $min_age_hours;
			$changes[] = self::change( 'min_age_hours', $min_age_hours, $previous_min_age_hours );
		}

		$money_repair = self::repair_money_params( $params, $constraints['money'] ?? [] );
		$params = $money_repair['params'];
		$changes = array_merge( $changes, $money_repair['changes'] );

		$payment_method = sanitize_key( $constraints['payment_method'] ?? '' );
		if ( '' !== $payment_method && sanitize_key( $params['payment_method'] ?? '' ) !== $payment_method ) {
			$previous_payment_method = ! empty( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : null;
			$params['payment_method'] = $payment_method;
			$changes[] = self::change( 'payment_method', $payment_method, $previous_payment_method );
		}

		$shipping_state = strtoupper( sanitize_text_field( (string) ( $constraints['shipping_state'] ?? '' ) ) );
		if ( '' !== $shipping_state && strtoupper( sanitize_text_field( (string) ( $params['shipping_state'] ?? '' ) ) ) !== $shipping_state ) {
			$previous_shipping_state = ! empty( $params['shipping_state'] ) ? sanitize_text_field( (string) $params['shipping_state'] ) : null;
			$params['shipping_state'] = $shipping_state;
			$changes[] = self::change( 'shipping_state', $shipping_state, $previous_shipping_state );
		}

		$identity_repair = self::repair_identity_params( $params, $constraints['customer_identity'] ?? [] );
		$params = $identity_repair['params'];
		$changes = array_merge( $changes, $identity_repair['changes'] );

		if ( isset( $params['total_min'], $params['total_max'] ) && (float) $params['total_min'] > (float) $params['total_max'] ) {
			$previous_min = (float) $params['total_min'];
			$previous_max = (float) $params['total_max'];
			$params['total_min'] = $previous_max;
			$params['total_max'] = $previous_min;
			$changes[] = [
				'type'     => 'param_repair',
				'param'    => 'total_range',
				'value'    => [
					'total_min' => $params['total_min'],
					'total_max' => $params['total_max'],
				],
				'previous' => [
					'total_min' => $previous_min,
					'total_max' => $previous_max,
				],
				'reason'   => 'semantic_frame_constraint',
			];
		}

		if ( ! empty( $changes ) ) {
			$decision['params'] = $params;
			$decision['raw_param_keys'] = array_values(
				array_unique(
					array_merge(
						isset( $decision['raw_param_keys'] ) && is_array( $decision['raw_param_keys'] )
							? array_values( array_map( 'sanitize_key', $decision['raw_param_keys'] ) )
							: [],
						array_values( array_map( 'sanitize_key', array_keys( $params ) ) )
					)
				)
			);
		}

		return [
			'decision' => $decision,
			'changes'  => $changes,
		];
	}

	private static function first_status_param( $status ) {
		if ( is_array( $status ) ) {
			$status = reset( $status );
		}

		$status = sanitize_key( (string) $status );

		return '' !== $status ? $status : null;
	}

	private static function repair_order_details_params( $decision, $frame ) {
		$params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$order_ids = self::resolve_order_ids_from_frame( $frame );
		$changes = [];

		if ( empty( $order_ids ) ) {
			return [
				'decision' => $decision,
				'changes'  => [],
			];
		}

		$previous_order_ids = isset( $params['order_ids'] ) && is_array( $params['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
			: [];

		if ( $previous_order_ids !== $order_ids ) {
			$new_params = [ 'order_ids' => $order_ids ];

			if ( count( $order_ids ) === 1 ) {
				$new_params['order_id'] = $order_ids[0];
			}

			if ( ! empty( $params['original_command'] ) ) {
				$new_params['original_command'] = sanitize_text_field( $params['original_command'] );
			}

			$decision['params'] = $new_params;
			$decision['raw_param_keys'] = array_values( array_map( 'sanitize_key', array_keys( $new_params ) ) );
			$changes[] = self::change( 'order_ids', $order_ids, $previous_order_ids ?: null );
		}

		return [
			'decision' => $decision,
			'changes'  => $changes,
		];
	}

	private static function force_write_missing_target_clarify( $decision, $frame ) {
		$decision = is_array( $decision ) ? $decision : [];
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$action = self::resolve_missing_write_action( $frame );
		$target_status = sanitize_key( $constraints['status'] ?? '' );
		$quantity = isset( $constraints['quantity'] ) && null !== $constraints['quantity'] ? absint( $constraints['quantity'] ) : 0;
		$previous_mode = sanitize_key( $decision['mode'] ?? '' );
		$previous_action = sanitize_text_field( $decision['action'] ?? '' );

		$params = [];

		if ( 'orders.update_status' === $action && '' !== $target_status ) {
			$params['status'] = $target_status;
		}

		$question = class_exists( 'YoRo_Response_UX_Formatter' )
			? YoRo_Response_UX_Formatter::write_missing_target_question(
				$action,
				$target_status,
				$quantity,
				sanitize_text_field( $decision['language'] ?? 'en' )
			)
			: '';

		if ( '' === $question ) {
			$question = 'orders.hold' === $action
				? __( 'Which order IDs should I put on hold?', 'yoro-ai-core' )
				: __( 'Which order IDs should I update?', 'yoro-ai-core' );
		}

		$decision['mode'] = 'clarify';
		$decision['action'] = $action;
		$decision['intent_family'] = 'orders.hold' === $action ? 'hold_order' : 'update_order_status';
		$decision['params'] = $params;
		$decision['missing'] = [ 'order_ids' ];
		$decision['ambiguities'] = [];
		$decision['clarification_question'] = $question;
		$decision['summary'] = $question;
		$decision['raw_param_keys'] = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );
		$decision['dropped_param_keys'] = [];
		$decision['params_trimmed_count'] = 0;

		$changes = [
			[
				'type'     => 'clarify_override',
				'param'    => 'mode',
				'value'    => 'clarify',
				'previous' => '' !== $previous_mode ? $previous_mode : null,
				'reason'   => 'semantic_frame_write_missing_target',
			],
		];

		if ( $previous_action !== $action ) {
			$changes[] = [
				'type'     => 'action_override',
				'param'    => 'action',
				'value'    => $action,
				'previous' => '' !== $previous_action ? $previous_action : null,
				'reason'   => 'semantic_frame_write_missing_target',
			];
		}

		return [
			'decision' => $decision,
			'changes'  => $changes,
		];
	}

	private static function force_explicit_order_write( $decision, $frame ) {
		$decision = is_array( $decision ) ? $decision : [];
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$order_ids = self::resolve_order_ids_from_frame( $frame );
		$action = self::resolve_order_write_action( $frame );
		$target_status = sanitize_key( $constraints['status'] ?? '' );
		$previous_action = sanitize_text_field( $decision['action'] ?? '' );
		$previous_params = isset( $decision['params'] ) && is_array( $decision['params'] ) ? $decision['params'] : [];
		$params = [ 'order_ids' => $order_ids ];
		$changes = [];

		if ( count( $order_ids ) === 1 ) {
			$params['order_id'] = $order_ids[0];
		}

		if ( 'orders.update_status' === $action ) {
			$params['status'] = $target_status;
		}

		if ( ! empty( $previous_params['original_command'] ) ) {
			$params['original_command'] = sanitize_text_field( $previous_params['original_command'] );
		}

		$decision['mode'] = 'task_request';
		$decision['action'] = $action;
		$decision['intent_family'] = 'orders.hold' === $action ? 'hold_order' : 'update_order_status';
		$decision['params'] = $params;
		$decision['missing'] = [];
		$decision['ambiguities'] = [];
		$decision['clarification_question'] = '';
		$decision['raw_param_keys'] = array_values( array_map( 'sanitize_key', array_keys( $params ) ) );
		$decision['dropped_param_keys'] = [];
		$decision['params_trimmed_count'] = 0;

		if ( $previous_action !== $action ) {
			$changes[] = [
				'type'     => 'action_override',
				'param'    => 'action',
				'value'    => $action,
				'previous' => '' !== $previous_action ? $previous_action : null,
				'reason'   => 'semantic_frame_explicit_order_write',
			];
		}

		$previous_order_ids = isset( $previous_params['order_ids'] ) && is_array( $previous_params['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $previous_params['order_ids'] ) ) )
			: [];

		if ( $previous_order_ids !== $order_ids ) {
			$changes[] = self::change( 'order_ids', $order_ids, $previous_order_ids ?: null );
		}

		if ( 'orders.update_status' === $action && sanitize_key( $previous_params['status'] ?? '' ) !== $target_status ) {
			$changes[] = self::change( 'status', $target_status, ! empty( $previous_params['status'] ) ? sanitize_key( $previous_params['status'] ) : null );
		}

		return [
			'decision' => $decision,
			'changes'  => $changes,
		];
	}

	private static function resolve_order_ids_from_frame( $frame ) {
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$order_ids   = isset( $constraints['order_ids'] ) && is_array( $constraints['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $constraints['order_ids'] ) ) )
			: [];
		$order_id    = ! empty( $constraints['order_id'] ) ? absint( $constraints['order_id'] ) : 0;

		if ( $order_id > 0 ) {
			array_unshift( $order_ids, $order_id );
		}

		return array_values( array_unique( array_filter( $order_ids ) ) );
	}

	private static function frame_targets_missing_order_write( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$surface = sanitize_key( $frame['surface'] ?? '' );
		$target_type = sanitize_key( $frame['target_type'] ?? '' );
		$order_ids = self::resolve_order_ids_from_frame( $frame );

		return 'orders' === $surface &&
			empty( $order_ids ) &&
			in_array( $target_type, [ 'order_write_missing_target', 'order_write', 'order_status_write', 'order_action_missing_target' ], true ) &&
			'' !== self::resolve_missing_write_action( $frame );
	}

	private static function resolve_missing_write_action( $frame ) {
		return self::resolve_order_write_action( $frame );
	}

	private static function frame_targets_explicit_order_write( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$surface = sanitize_key( $frame['surface'] ?? '' );
		$target_type = sanitize_key( $frame['target_type'] ?? '' );
		$order_ids = self::resolve_order_ids_from_frame( $frame );

		return 'orders' === $surface &&
			! empty( $order_ids ) &&
			in_array( $target_type, [ 'order_write', 'order_status_write', 'single_order_write', 'order_action' ], true ) &&
			'' !== self::resolve_order_write_action( $frame );
	}

	private static function resolve_order_write_action( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$operation = sanitize_key( $frame['operation'] ?? '' );
		$constraints = isset( $frame['constraints'] ) && is_array( $frame['constraints'] ) ? $frame['constraints'] : [];
		$status = sanitize_key( $constraints['status'] ?? '' );

		if ( in_array( $operation, [ 'hold', 'put_on_hold', 'on_hold' ], true ) || 'on-hold' === $status ) {
			return 'orders.hold';
		}

		if (
			in_array( $operation, [ 'update_status', 'status_update', 'set_status', 'mark_status', 'change_status', 'complete', 'cancel', 'refund' ], true ) &&
			'' !== $status &&
			( ! class_exists( 'YoRo_Order_Helper' ) || YoRo_Order_Helper::is_valid_order_status( $status ) )
		) {
			return 'orders.update_status';
		}

		return '';
	}

	private static function can_override_to_order_write( $decision, $frame ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode = sanitize_key( $decision['mode'] ?? '' );
		$current_action = sanitize_text_field( $decision['action'] ?? '' );
		$target_action = self::resolve_order_write_action( $frame );

		if ( 'task_request' !== $mode || '' === $target_action ) {
			return false;
		}

		if ( $current_action === $target_action ) {
			return true;
		}

		if ( '' === $current_action ) {
			return true;
		}

		if ( class_exists( 'YoRo_Action_Registry' ) ) {
			$action_def = YoRo_Action_Registry::get( $current_action );

			if ( is_array( $action_def ) && ( ! empty( $action_def['write_action'] ) || ! empty( $action_def['requires_confirmation'] ) ) ) {
				return false;
			}
		}

		return in_array(
			$current_action,
			[
				'orders.search',
				'orders.get_details',
				'orders.find_by_identity',
				'operations.get_summary',
				'operations.get_attention_queue',
			],
			true
		);
	}

	private static function frame_targets_order_search( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$surface = sanitize_key( $frame['surface'] ?? '' );
		$operation = sanitize_key( $frame['operation'] ?? '' );
		$target_type = sanitize_key( $frame['target_type'] ?? '' );

		return 'orders' === $surface &&
			in_array( $operation, [ 'search', 'list', 'find', 'show', 'view' ], true ) &&
			in_array( $target_type, [ 'order_list', 'orders', 'order_search', 'order_collection' ], true );
	}

	private static function frame_targets_order_details( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];
		$surface = sanitize_key( $frame['surface'] ?? '' );
		$operation = sanitize_key( $frame['operation'] ?? '' );
		$target_type = sanitize_key( $frame['target_type'] ?? '' );
		$order_ids = self::resolve_order_ids_from_frame( $frame );

		return 'orders' === $surface &&
			! empty( $order_ids ) &&
			in_array( $operation, [ 'get_details', 'details', 'read', 'show', 'view', 'open', 'inspect' ], true ) &&
			in_array( $target_type, [ 'single_order', 'order', 'order_details', 'order_record' ], true );
	}

	private static function can_override_to_order_details( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode = sanitize_key( $decision['mode'] ?? '' );
		$action = sanitize_text_field( $decision['action'] ?? '' );

		if ( 'task_request' !== $mode || 'orders.get_details' === $action ) {
			return false;
		}

		if ( '' === $action ) {
			return true;
		}

		if ( class_exists( 'YoRo_Action_Registry' ) ) {
			$action_def = YoRo_Action_Registry::get( $action );

			if ( is_array( $action_def ) && ( ! empty( $action_def['write_action'] ) || ! empty( $action_def['requires_confirmation'] ) ) ) {
				return false;
			}
		}

		return ! in_array(
			$action,
			[
				'orders.update_status',
				'orders.bulk_update_status_with_note',
				'orders.hold',
				'orders.add_note',
				'orders.remove_note',
				'orders.refund_preview',
				'orders.compose_customer_email',
				'orders.send_customer_email',
				'orders.save_customer_email_draft',
			],
			true
		);
	}

	private static function can_override_to_orders_search( $decision ) {
		$decision = is_array( $decision ) ? $decision : [];
		$mode = sanitize_key( $decision['mode'] ?? '' );
		$action = sanitize_text_field( $decision['action'] ?? '' );

		if ( 'task_request' !== $mode || 'orders.search' === $action ) {
			return false;
		}

		if ( '' === $action ) {
			return true;
		}

		if ( class_exists( 'YoRo_Action_Registry' ) ) {
			$action_def = YoRo_Action_Registry::get( $action );

			if ( is_array( $action_def ) && ( ! empty( $action_def['write_action'] ) || ! empty( $action_def['requires_confirmation'] ) ) ) {
				return false;
			}
		}

		return ! in_array(
			$action,
			[
				'orders.update_status',
				'orders.bulk_update_status_with_note',
				'orders.hold',
				'orders.add_note',
				'orders.refund_preview',
				'orders.compose_customer_email',
				'orders.send_customer_email',
				'orders.save_customer_email_draft',
			],
			true
		);
	}

	private static function resolve_min_age_hours_from_date_constraint( $date ) {
		$date = is_array( $date ) ? $date : [];
		$operator = sanitize_key( $date['operator'] ?? '' );
		$value = isset( $date['value'] ) && null !== $date['value'] ? absint( $date['value'] ) : 0;
		$unit = sanitize_key( $date['unit'] ?? '' );

		if ( $value <= 0 || ! in_array( $operator, [ 'older_than', 'more_than', 'before' ], true ) ) {
			return 0;
		}

		if ( in_array( $unit, [ 'day', 'days' ], true ) ) {
			return min( $value * 24, 8760 );
		}

		if ( in_array( $unit, [ 'hour', 'hours' ], true ) ) {
			return min( $value, 8760 );
		}

		if ( in_array( $unit, [ 'week', 'weeks' ], true ) ) {
			return min( $value * 168, 8760 );
		}

		return 0;
	}

	private static function repair_money_params( $params, $money ) {
		$params  = is_array( $params ) ? $params : [];
		$money   = is_array( $money ) ? $money : [];
		$changes = [];

		$operator = sanitize_key( $money['operator'] ?? '' );
		$value    = isset( $money['value'] ) && null !== $money['value'] ? (float) $money['value'] : null;
		$min      = isset( $money['min'] ) && null !== $money['min'] ? (float) $money['min'] : null;
		$max      = isset( $money['max'] ) && null !== $money['max'] ? (float) $money['max'] : null;

		if ( in_array( $operator, [ 'above', 'over', 'greater_than', 'greater_than_or_equal', 'min', 'at_least' ], true ) && null !== $value ) {
			$changes = array_merge( $changes, self::set_float_param( $params, 'total_min', $value )['changes'] );
			$params['total_min'] = $value;
			return [
				'params'  => $params,
				'changes' => $changes,
			];
		}

		if ( in_array( $operator, [ 'below', 'under', 'less_than', 'less_than_or_equal', 'max', 'at_most' ], true ) && null !== $value ) {
			$changes = array_merge( $changes, self::set_float_param( $params, 'total_max', $value )['changes'] );
			$params['total_max'] = $value;
			return [
				'params'  => $params,
				'changes' => $changes,
			];
		}

		if ( in_array( $operator, [ 'between', 'range' ], true ) && ( null !== $min || null !== $max ) ) {
			if ( null !== $min ) {
				$repair = self::set_float_param( $params, 'total_min', $min );
				$params = $repair['params'];
				$changes = array_merge( $changes, $repair['changes'] );
			}

			if ( null !== $max ) {
				$repair = self::set_float_param( $params, 'total_max', $max );
				$params = $repair['params'];
				$changes = array_merge( $changes, $repair['changes'] );
			}
		}

		return [
			'params'  => $params,
			'changes' => $changes,
		];
	}

	private static function set_float_param( $params, $key, $value ) {
		$params = is_array( $params ) ? $params : [];
		$key    = sanitize_key( $key );
		$value  = max( 0, (float) $value );

		if ( isset( $params[ $key ] ) && abs( (float) $params[ $key ] - $value ) < 0.0001 ) {
			return [
				'params'  => $params,
				'changes' => [],
			];
		}

		$previous = isset( $params[ $key ] ) && '' !== $params[ $key ] ? (float) $params[ $key ] : null;
		$params[ $key ] = $value;

		return [
			'params'  => $params,
			'changes' => [ self::change( $key, $value, $previous ) ],
		];
	}

	private static function repair_identity_params( $params, $identity ) {
		$params   = is_array( $params ) ? $params : [];
		$identity = is_array( $identity ) ? $identity : [];
		$type     = sanitize_key( $identity['type'] ?? '' );
		$value    = sanitize_text_field( (string) ( $identity['value'] ?? '' ) );
		$changes  = [];

		if ( '' === $type || '' === $value ) {
			return [
				'params'  => $params,
				'changes' => [],
			];
		}

		$param_map = [
			'email'         => 'email',
			'phone'         => 'phone',
			'customer_id'   => 'customer_id',
			'customer_name' => 'customer_name',
			'name'          => 'customer_name',
		];

		if ( empty( $param_map[ $type ] ) ) {
			return [
				'params'  => $params,
				'changes' => [],
			];
		}

		$param = $param_map[ $type ];
		$normalized_value = 'customer_id' === $param ? absint( $value ) : $value;

		if ( (string) ( $params[ $param ] ?? '' ) !== (string) $normalized_value ) {
			$previous = isset( $params[ $param ] ) && '' !== $params[ $param ] ? $params[ $param ] : null;
			$params[ $param ] = $normalized_value;
			$changes[] = self::change( $param, $normalized_value, $previous );
		}

		return [
			'params'  => $params,
			'changes' => $changes,
		];
	}

	private static function change( $param, $value, $previous = null ) {
		return [
			'type'     => 'param_repair',
			'param'    => sanitize_key( $param ),
			'value'    => $value,
			'previous' => $previous,
			'reason'   => 'semantic_frame_constraint',
		];
	}
}
