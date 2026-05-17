<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Response_Helper {

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

		$email_clarify = self::maybe_build_email_semantic_clarify_response(
			$trace_id,
			$parsed,
			$plan,
			$error
		);

		if ( is_array( $email_clarify ) ) {
			$email_clarify['risk']   = $risk;
			$email_clarify['policy'] = $policy;
			return $email_clarify;
		}

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

	private static function maybe_build_email_semantic_clarify_response( $trace_id, $parsed, $plan, WP_Error $error ) {
		$step = self::resolve_semantic_step( $parsed, $plan, $error );

		if ( empty( $step ) || ! is_array( $step ) ) {
			return null;
		}

		$action = sanitize_text_field( $step['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.compose_customer_email', 'orders.send_customer_email', 'orders.save_customer_email_draft' ], true ) ) {
			return null;
		}

		$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
		$text   = trim(
			implode(
				' ',
				array_filter(
					[
						sanitize_text_field( $params['instruction'] ?? '' ),
						sanitize_text_field( $params['subject'] ?? '' ),
						sanitize_textarea_field( $params['body'] ?? '' ),
					]
				)
			)
		);

		$order_id = absint( $error->get_error_data()['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			$order_id = absint( $params['order_id'] ?? 0 );
		}

		if ( $order_id <= 0 ) {
			return null;
		}

		$message = '';
		if ( false !== stripos( $error->get_error_message(), 'does not require shipping' ) ) {
			$message = sprintf(
				/* translators: %d: order id */
				__( 'Order #%d does not require shipping. What should the customer email say instead?', 'yoro-agent-core' ),
				$order_id
			);
		} elseif ( self::looks_like_shipping_email_text( $text ) ) {
			$message = sprintf(
				/* translators: %d: order id */
				__( 'That shipping or delivery email does not match order #%d. What should the customer email say instead?', 'yoro-agent-core' ),
				$order_id
			);
		} elseif ( self::looks_like_payment_email_text( $text ) ) {
			$message = sprintf(
				/* translators: %d: order id */
				__( 'That payment-verification email does not match order #%d. What should the customer email say instead?', 'yoro-agent-core' ),
				$order_id
			);
		} elseif ( self::looks_like_refund_email_text( $text ) ) {
			$message = sprintf(
				/* translators: %d: order id */
				__( 'That refund-related email does not match order #%d. What should the customer email say instead?', 'yoro-agent-core' ),
				$order_id
			);
		}

		if ( '' === $message ) {
			return null;
		}

		$clarify_params = self::build_email_semantic_clarify_params( $parsed, $plan, $order_id );

		if ( class_exists( 'YoRo_Conversation_Memory' ) ) {
			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				'orders.compose_customer_email',
				$clarify_params,
				[ 'instruction' ],
				! empty( $clarify_params['_yoro_composed_requested_actions'] ) ? 'composed_actions' : 'email_customer'
			);
		}

		$current_workflow = class_exists( 'YoRo_Conversation_Memory' )
			? YoRo_Conversation_Memory::get_workflow( get_current_user_id() )
			: [];

		return [
			'success' => false,
			'stage'   => 'clarify',
			'trace_id' => $trace_id,
			'parsed'  => [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.compose_customer_email',
				'message'            => $message,
				'params'             => $clarify_params,
				'missing'            => [ 'instruction' ],
				'parser_source'      => sanitize_text_field( $parsed['parser_source'] ?? 'rule' ),
				'parser_rule'        => sanitize_text_field( $parsed['parser_rule'] ?? 'semantic.validation' ) . '.clarify',
				'confidence'         => isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.9,
				'original_command'   => sanitize_textarea_field( $parsed['original_command'] ?? '' ),
				'normalized_command' => sanitize_text_field( $parsed['normalized_command'] ?? '' ),
				'options'            => [
					[
						'label'   => __( 'Cancel', 'yoro-agent-core' ),
						'command' => 'workflow.cancel',
					],
				],
				'current_workflow'   => $current_workflow,
			],
			'current_workflow' => $current_workflow,
			'message' => $message,
		];
	}

	private static function resolve_semantic_step( $parsed, $plan, WP_Error $error ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan = is_array( $plan ) ? $plan : [];
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		if ( 'sequence' === sanitize_key( $plan['type'] ?? '' ) ) {
			$index = isset( $data['step_index'] ) ? absint( $data['step_index'] ) : -1;
			$steps = isset( $plan['steps'] ) && is_array( $plan['steps'] ) ? array_values( $plan['steps'] ) : [];

			if ( isset( $steps[ $index ] ) && is_array( $steps[ $index ] ) ) {
				return $steps[ $index ];
			}
		}

		if ( 'single' === sanitize_key( $plan['type'] ?? '' ) ) {
			return [
				'id'     => 'single_action',
				'action' => sanitize_text_field( $plan['action'] ?? '' ),
				'params' => isset( $plan['params'] ) && is_array( $plan['params'] ) ? $plan['params'] : [],
			];
		}

		$index = isset( $data['step_index'] ) ? absint( $data['step_index'] ) : -1;
		$steps = isset( $parsed['actions'] ) && is_array( $parsed['actions'] ) ? array_values( $parsed['actions'] ) : [];

		if ( isset( $steps[ $index ] ) && is_array( $steps[ $index ] ) ) {
			return $steps[ $index ];
		}

		$parsed_action = sanitize_text_field( $parsed['action'] ?? '' );
		$parsed_params = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];

		if ( '' !== $parsed_action ) {
			return [
				'id'     => 'parsed_action',
				'action' => $parsed_action,
				'params' => $parsed_params,
			];
		}

		return null;
	}

	private static function build_email_semantic_clarify_params( $parsed, $plan, $order_id ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan   = is_array( $plan ) ? $plan : [];
		$params = isset( $parsed['params'] ) && is_array( $parsed['params'] ) ? $parsed['params'] : [];

		$clarify_params = [
			'order_id'          => absint( $order_id ),
			'order_ids'         => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) ) : [ absint( $order_id ) ],
			'instruction'       => '',
			'original_command'  => sanitize_textarea_field( $parsed['original_command'] ?? '' ),
		];

		if ( isset( $params['status'] ) ) {
			$clarify_params['status'] = sanitize_key( $params['status'] );
		}

		if ( 'workflow.composed_actions' === sanitize_text_field( $parsed['action'] ?? '' ) ) {
			$clarify_params['_yoro_composed_requested_actions'] = self::extract_composed_requested_actions( $parsed, $plan );
			$clarify_params['_yoro_composed_unavailable_requests'] = self::extract_composed_unavailable_requests( $parsed );
			$clarify_params['_yoro_composed_original_command'] = sanitize_textarea_field( $parsed['original_command'] ?? '' );
			$clarify_params['_yoro_composed_parser_rule'] = sanitize_text_field( $parsed['parser_rule'] ?? 'semantic.validation' );
			$clarify_params['_yoro_composed_confidence'] = isset( $parsed['confidence'] ) ? (float) $parsed['confidence'] : 0.9;
		}

		return $clarify_params;
	}

	private static function extract_composed_requested_actions( $parsed, $plan ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$plan   = is_array( $plan ) ? $plan : [];

		$requested = $parsed['composition']['requested_actions']
			?? $parsed['current_workflow']['artifacts']['composition']['requested_actions']
			?? null;

		if ( is_array( $requested ) && ! empty( $requested ) ) {
			return $requested;
		}

		$steps = [];
		if ( ! empty( $parsed['actions'] ) && is_array( $parsed['actions'] ) ) {
			$steps = $parsed['actions'];
		} elseif ( ! empty( $plan['steps'] ) && is_array( $plan['steps'] ) ) {
			$steps = $plan['steps'];
		}

		$requested = [];
		foreach ( array_values( $steps ) as $index => $step ) {
			$step   = is_array( $step ) ? $step : [];
			$action = sanitize_text_field( $step['action'] ?? '' );
			$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];
			$kind   = '';
			$label  = sanitize_text_field( $step['label'] ?? $action );

			switch ( $action ) {
				case 'orders.update_status':
				case 'orders.bulk_update_status_with_note':
				case 'orders.hold':
					$kind = 'update_status';
					break;
				case 'orders.add_note':
					$kind = 'add_note';
					break;
				case 'orders.compose_customer_email':
					$kind = 'write_email';
					break;
			}

			if ( '' === $kind ) {
				continue;
			}

			$requested[] = [
				'kind'     => $kind,
				'action'   => $action,
				'label'    => $label,
				'position' => $index,
				'params'   => $params,
			];
		}

		return $requested;
	}

	private static function extract_composed_unavailable_requests( $parsed ) {
		$parsed = is_array( $parsed ) ? $parsed : [];
		$unavailable = $parsed['composition']['unavailable_requests']
			?? $parsed['current_workflow']['artifacts']['composition']['unavailable_requests']
			?? [];

		return is_array( $unavailable ) ? $unavailable : [];
	}

	private static function build_friendly_semantic_error_message( $parsed, $plan, WP_Error $error ) {
		$plan   = is_array( $plan ) ? $plan : [];
		$parsed = is_array( $parsed ) ? $parsed : [];
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : [];
		$action = sanitize_text_field( $data['action'] ?? $parsed['action'] ?? $plan['action'] ?? '' );
		$order_id = absint( $data['order_id'] ?? 0 );

		if ( in_array( $action, [ 'workflow.fulfillment_followup_preview', 'workflow.verify_customer_before_fulfillment' ], true ) ) {
			if ( 'yoro_invalid_action_plan_entity' === $error->get_error_code() ) {
				return $order_id > 0
					? sprintf( __( 'Order #%d is not eligible for fulfillment handling in this workflow.', 'yoro-agent-core' ), $order_id )
					: __( 'This order is not eligible for fulfillment handling in this workflow.', 'yoro-agent-core' );
			}

			return $order_id > 0
				? sprintf( __( 'Order #%d is not in a valid fulfillment state for this workflow.', 'yoro-agent-core' ), $order_id )
				: __( 'This order is not in a valid fulfillment state for this workflow.', 'yoro-agent-core' );
		}

		if ( in_array( $action, [ 'workflow.refund_review', 'orders.refund_preview' ], true ) ) {
			return $order_id > 0
				? sprintf( __( 'Order #%d cannot continue with that refund workflow as requested.', 'yoro-agent-core' ), $order_id )
				: __( 'That refund workflow cannot continue as requested.', 'yoro-agent-core' );
		}

		return $error->get_error_message();
	}

	private static function looks_like_shipping_email_text( $text ) {
		return (bool) preg_match( '/\b(?:shipping|shipment|delivery|delay|delayed|eta|estimated arrival|delivered soon|on the way|tracking|in transit|package ships?)\b/i', (string) $text );
	}

	private static function looks_like_payment_email_text( $text ) {
		return (bool) preg_match( '/\b(?:payment mismatch|payment verification|verify (?:your )?payment|billing verification|card verification|payment details|payment information|confirm(?:ation)? of your order details)\b/i', (string) $text );
	}

	private static function looks_like_refund_email_text( $text ) {
		return (bool) preg_match( '/\b(?:refund|refunded|reimbursement|money back|credit back)\b/i', (string) $text );
	}
}
