<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Suggestions {

	public static function get( $action, $result = [], $context = [] ) {
		$parsed = [
			'action' => $action,
		];

		return self::suggest( $parsed, $result, $context );
	}

	public static function suggest( $parsed, $result = [], $context = [] ) {
		$action = isset( $parsed['action'] ) ? sanitize_text_field( $parsed['action'] ) : '';

		if ( 'yes' !== get_option( 'yoro_enable_suggestions', 'yes' ) ) {
			return [];
		}

		$suggestions = self::rule_suggestions( $action, $result, $parsed, $context );

		$suggestions = array_merge(
			$suggestions,
			self::context_suggestions( $action, $result, $parsed, $context )
		);

		if ( self::should_use_ai() ) {
			$suggestions = array_merge(
				$suggestions,
				self::ai_suggestions( $action, $result, $parsed, $context )
			);
		}

		$max = absint( get_option( 'yoro_ai_max_suggestions', 4 ) );

		if ( $max <= 0 ) {
			$max = 4;
		}

		$max = min( $max, 10 );

		return array_slice(
			self::normalize_suggestions( $suggestions ),
			0,
			$max
		);
	}

	private static function rule_suggestions( $action, $result = [], $parsed = [], $context = [] ) {
		switch ( $action ) {
			case 'store.get_daily_briefing':
				return self::for_daily_briefing( $result );

			case 'orders.search':
				return self::for_orders_search( $result );

			case 'orders.get_details':
				return self::for_order_details( $result );

			case 'orders.update_status':
			case 'orders.hold':
				return self::after_order_status_change( $result );

			case 'orders.add_note':
				return self::after_order_note_added( $result );

			case 'assistant.submit_developer_request':
				return self::after_developer_request_submitted( $result );
		}

		return self::capability_suggestions( $action, $result, $parsed, $context );
	}

	private static function for_daily_briefing( $result ) {
		$briefing_context = isset( $result['briefing_context'] ) && is_array( $result['briefing_context'] )
			? $result['briefing_context']
			: [];
		$top_topic = sanitize_key( $briefing_context['top_topic'] ?? '' );

		switch ( $top_topic ) {
			case 'operations_attention':
				return [
					self::action_suggestion( __( 'Open attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' ),
					self::action_suggestion( __( 'Check fulfillment pressure', 'yoro-agent-core' ), 'operations.get_fulfillment_queue' ),
					self::action_suggestion( __( 'Check customer follow-up', 'yoro-agent-core' ), 'customers.get_service_queue' ),
					self::action_suggestion( __( 'Review operations summary', 'yoro-agent-core' ), 'operations.get_summary', [ 'window' => 'today' ] ),
				];

			case 'fulfillment':
				return [
					self::action_suggestion( __( 'Open fulfillment queue', 'yoro-agent-core' ), 'operations.get_fulfillment_queue' ),
					self::action_suggestion( __( 'Check attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' ),
					self::action_suggestion( __( 'Check customer follow-up', 'yoro-agent-core' ), 'customers.get_service_queue' ),
				];

			case 'customer_service':
				return [
					self::action_suggestion( __( 'Open customer follow-up', 'yoro-agent-core' ), 'customers.get_service_queue' ),
					self::action_suggestion( __( 'Check attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' ),
					self::action_suggestion( __( 'Check fulfillment queue', 'yoro-agent-core' ), 'operations.get_fulfillment_queue' ),
				];

			case 'inventory':
				return [
					self::action_suggestion( __( 'Open inventory queue', 'yoro-agent-core' ), 'products.get_inventory_queue' ),
					self::action_suggestion( __( 'Check attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' ),
					self::action_suggestion( __( 'Check fulfillment queue', 'yoro-agent-core' ), 'operations.get_fulfillment_queue' ),
				];
		}

		return [
			self::action_suggestion( __( 'Open attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' ),
			self::action_suggestion( __( 'Open fulfillment queue', 'yoro-agent-core' ), 'operations.get_fulfillment_queue' ),
			self::action_suggestion( __( 'Open customer follow-up', 'yoro-agent-core' ), 'customers.get_service_queue' ),
			self::action_suggestion( __( 'Open inventory queue', 'yoro-agent-core' ), 'products.get_inventory_queue' ),
		];
	}

	private static function capability_suggestions( $action, $result = [], $parsed = [], $context = [] ) {
		$suggestions = [];

		if ( YoRo_Action_Registry::has_capability( $action, 'orders' ) ) {
			$order_ids = self::extract_order_ids_from_result_or_context( $result, $context );

			if ( ! empty( $order_ids ) ) {
				$id = absint( reset( $order_ids ) );

				$suggestions[] = self::open_order_suggestion( __( 'Open order details', 'yoro-agent-core' ), $id );
			}
		}

		if (
			YoRo_Action_Registry::has_capability( $action, 'customer_communication' ) &&
			YoRo_Action_Registry::has_capability( $action, 'write' )
		) {
			$order_ids = self::extract_order_ids_from_result_or_context( $result, $context );

			if ( ! empty( $order_ids ) ) {
				$id = absint( reset( $order_ids ) );

				$suggestions[] = self::open_order_suggestion( __( 'Open emailed order', 'yoro-agent-core' ), $id );
			}
		}

		if ( YoRo_Action_Registry::has_capability( $action, 'draft' ) ) {
			$order_ids = self::extract_order_ids_from_result_or_context( $result, $context );

			if ( ! empty( $order_ids ) ) {
				$id = absint( reset( $order_ids ) );

				$suggestions[] = [
					'type'  => 'command',
					'label' => __( 'View drafts', 'yoro-agent-core' ),
					'value' => 'Show email drafts for order #' . $id,
				];
			}
		}

		return $suggestions;
	}

	private static function after_developer_request_submitted( $result ) {
		$request  = isset( $result['data']['developer_request'] ) && is_array( $result['data']['developer_request'] )
			? $result['data']['developer_request']
			: [];
		$order_id = absint( $request['context_order_id'] ?? 0 );
		$suggestions = [];

		if ( $order_id > 0 ) {
			$suggestions[] = self::open_order_suggestion(
				sprintf( __( 'Open order #%d details', 'yoro-agent-core' ), $order_id ),
				$order_id
			);
		}

		$suggestions[] = self::action_suggestion( __( 'Open morning briefing', 'yoro-agent-core' ), 'store.get_morning_briefing' );
		$suggestions[] = self::action_suggestion( __( 'Open operations attention queue', 'yoro-agent-core' ), 'operations.get_attention_queue' );

		return $suggestions;
	}

	private static function extract_order_ids_from_result_or_context( $result = [], $context = [] ) {
		$order_ids = [];

		if ( ! empty( $result['order_id'] ) ) {
			$order_ids[] = absint( $result['order_id'] );
		}

		if ( ! empty( $result['order_ids'] ) && is_array( $result['order_ids'] ) ) {
			$order_ids = array_merge( $order_ids, array_map( 'absint', $result['order_ids'] ) );
		}

		if ( ! empty( $result['processed'] ) && is_array( $result['processed'] ) ) {
			$order_ids = array_merge( $order_ids, array_map( 'absint', $result['processed'] ) );
		}

		if ( ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
			foreach ( $result['orders'] as $order ) {
				if ( ! empty( $order['id'] ) ) {
					$order_ids[] = absint( $order['id'] );
				}
			}
		}

		$context_order_ids = self::context_value( $context, 'last_order_ids', [], 'entity_context' );

		if ( empty( $order_ids ) && is_array( $context_order_ids ) ) {
			$order_ids = array_merge( $order_ids, array_map( 'absint', $context_order_ids ) );
		}

		return array_values(
			array_filter(
				array_unique( $order_ids )
			)
		);
	}

	private static function for_orders_search( $result ) {
		$orders = isset( $result['orders'] ) && is_array( $result['orders'] )
			? $result['orders']
			: [];

		if ( empty( $orders ) ) {
			return [];
		}

		$first  = reset( $orders );
		$id     = absint( $first['id'] ?? 0 );
		$status = sanitize_key( $first['status'] ?? '' );

		if ( ! $id ) {
			return [];
		}

		$suggestions = [
			self::open_order_suggestion( __( 'Open first order', 'yoro-agent-core' ), $id ),
		];

		if ( 'on-hold' !== $status ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Put first order on hold',
				'value' => 'Hold the first one',
			];
		}

		if ( in_array( $status, [ 'pending', 'on-hold' ], true ) ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Mark first as processing',
				'value' => 'Mark the first one as processing',
			];
		}

		if ( in_array( $status, [ 'processing', 'on-hold' ], true ) ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Mark first as completed',
				'value' => 'Mark the first one as completed',
			];
		}

		return array_slice( $suggestions, 0, 4 );
	}

	private static function for_order_details( $result ) {
		$orders = isset( $result['orders'] ) && is_array( $result['orders'] )
			? $result['orders']
			: [];

		if ( empty( $orders ) ) {
			return [];
		}

		$order  = reset( $orders );
		$id     = absint( $order['id'] ?? 0 );
		$status = sanitize_key( $order['status'] ?? '' );

		if ( ! $id ) {
			return [];
		}

		$suggestions = [
			self::open_order_suggestion( __( 'Open order details', 'yoro-agent-core' ), $id ),
		];

		if ( 'on-hold' !== $status ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Put on hold',
				'value' => 'Hold this order',
			];
		}

		if ( 'on-hold' === $status && self::status_exists( 'processing' ) ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Mark processing',
				'value' => 'Mark this order as processing',
			];
		}

		if ( in_array( $status, [ 'processing', 'on-hold' ], true ) && self::status_exists( 'completed' ) ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Mark completed',
				'value' => 'Mark this order as completed',
			];
		}

		$suggestions = array_merge(
			$suggestions,
			self::get_extra_status_suggestions( $status, 'this order' )
		);

		return array_slice( $suggestions, 0, 4 );
	}

	private static function after_order_status_change( $result ) {
		$processed = isset( $result['processed'] ) && is_array( $result['processed'] )
			? array_values( array_filter( array_map( 'absint', $result['processed'] ) ) )
			: [];

		if ( empty( $processed ) ) {
			return [];
		}

		$id = reset( $processed );

		return [
			self::open_order_suggestion( __( 'Open updated order', 'yoro-agent-core' ), $id ),
		];
	}

	private static function after_order_note_added( $result ) {
		$order_ids = isset( $result['order_ids'] ) && is_array( $result['order_ids'] )
			? array_values( array_filter( array_map( 'absint', $result['order_ids'] ) ) )
			: [];

		if ( empty( $order_ids ) && ! empty( $result['processed'] ) && is_array( $result['processed'] ) ) {
			$order_ids = array_values( array_filter( array_map( 'absint', $result['processed'] ) ) );
		}

		if ( empty( $order_ids ) ) {
			return [];
		}

		$id = reset( $order_ids );

		return [
			self::open_order_suggestion( __( 'Open order details', 'yoro-agent-core' ), $id ),
		];
	}

	private static function context_suggestions( $action, $result = [], $parsed = [], $context = [] ) {
		$suggestions = [];

		$workflow = self::context_value( $context, 'current_workflow', [], 'workflow_state' );
		$workflow = is_array( $workflow ) ? $workflow : [];

		$last_order_ids = self::context_value( $context, 'last_order_ids', [], 'entity_context' );
		$last_order_ids = is_array( $last_order_ids )
			? array_values( array_filter( array_map( 'absint', $last_order_ids ) ) )
			: [];
		$last_result_type = sanitize_key(
			(string) self::context_value( $context, 'last_result_type', '', 'conversation' )
		);
		$last_email_subject = trim(
			(string) self::context_value( $context, 'last_email_subject', '', 'conversation' )
		);
		$last_email_body = trim(
			(string) self::context_value( $context, 'last_email_body', '', 'conversation' )
		);
		$last_failed_step = self::context_value( $context, 'last_failed_step', [], 'conversation' );
		$last_failed_step = is_array( $last_failed_step ) ? $last_failed_step : [];

		/*
		* 1. Existing contextual suggestion.
		*/
		if (
			$last_result_type &&
			'orders' === $last_result_type &&
			! empty( $last_order_ids )
		) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => __( 'Find same customer orders', 'yoro-agent-core' ),
				'value' => 'Find other orders from this customer',
			];
		}

		if (
			$last_result_type &&
			'briefing' === $last_result_type
		) {
			$last_result = self::context_value( $context, 'last_briefing', [], 'conversation' );

			if ( empty( $last_result ) || ! is_array( $last_result ) ) {
				$last_result = self::context_value( $context, 'last_result', [], 'conversation' );
			}

			$last_result = is_array( $last_result ) ? $last_result : [];
			$briefing_drilldowns = isset( $last_result['priority_drilldowns'] ) && is_array( $last_result['priority_drilldowns'] )
				? $last_result['priority_drilldowns']
				: [];

			if ( ! empty( $briefing_drilldowns[0]['label'] ) && ! empty( $briefing_drilldowns[0]['command'] ) ) {
				$suggestions[] = [
					'type'  => 'command',
					'label' => sprintf(
						/* translators: %s: drilldown label */
						__( 'Open top briefing view: %s', 'yoro-agent-core' ),
						sanitize_text_field( $briefing_drilldowns[0]['label'] )
					),
					'value' => sanitize_text_field( $briefing_drilldowns[0]['command'] ),
				];
			}

			if ( ! empty( $briefing_drilldowns[1]['label'] ) && ! empty( $briefing_drilldowns[1]['command'] ) ) {
				$suggestions[] = [
					'type'  => 'command',
					'label' => sprintf(
						/* translators: %s: drilldown label */
						__( 'Open next briefing view: %s', 'yoro-agent-core' ),
						sanitize_text_field( $briefing_drilldowns[1]['label'] )
					),
					'value' => sanitize_text_field( $briefing_drilldowns[1]['command'] ),
				];
			}
		}

		/*
		* 2. After composing or saving an email draft.
		*/
		$has_email_draft_context = (
			(
				! empty( $workflow['type'] ) &&
				'customer_email' === $workflow['type'] &&
				! empty( $workflow['subject'] ) &&
				! empty( $workflow['body'] )
			) ||
			(
				$last_email_subject &&
				$last_email_body
			)
		);

		if ( $has_email_draft_context ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => __( 'Send that draft', 'yoro-agent-core' ),
				'value' => 'Send that draft',
			];
		}

		/*
		* 3. After a failed workflow step.
		*/
		if (
			! empty( $last_failed_step ) &&
			! empty( $last_failed_step['action'] )
		) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => __( 'Retry failed step', 'yoro-agent-core' ),
				'value' => 'Retry failed step',
			];
		}

		/*
		* 4. After a workflow is active but not finished.
		*/
		if (
			! empty( $workflow ) &&
			! empty( $workflow['action'] ) &&
			! empty( $workflow['params'] ) &&
			empty( $workflow['completed'] )
		) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => __( 'Continue workflow', 'yoro-agent-core' ),
				'value' => 'Continue previous workflow',
			];
		}

		/*
		* 5. After a multi-order search/list context.
		*/
		if ( count( $last_order_ids ) > 1 ) {
			$suggestions[] = [
				'type'  => 'command',
				'label' => __( 'Do same for other orders', 'yoro-agent-core' ),
				'value' => 'Do the same for the other orders',
			];
		}

		return $suggestions;
	}

	private static function ai_suggestions( $action, $result = [], $parsed = [], $context = [] ) {

		if ( ! class_exists( 'YoRo_AI_Parser' ) ) {
			return [];
		}

		$prompt = self::build_ai_suggestion_prompt( $action, $result, $parsed, $context );

		$response = YoRo_AI_Parser::parse_freeform( $prompt );

		if ( is_wp_error( $response ) || empty( $response ) ) {
			return [];
		}

		$data = json_decode( $response, true );

		if ( ! is_array( $data ) ) {
			return [];
		}

		$suggestions = [];

		foreach ( $data as $item ) {

			if ( empty( $item['label'] ) || empty( $item['command'] ) ) {
				continue;
			}

			$command = self::normalize_ai_command( $item['command'] );
			$command = self::inject_context_into_command( $command, $parsed, $result, $context );
			$label = self::inject_context_into_command( $item['label'], $parsed, $result, $context );

			if ( ! self::is_valid_user_command( $command ) ) {
				continue;
			}

			$suggestions[] = [
				'type'  => 'command',
				'label' => sanitize_text_field( $label ),
				'value' => sanitize_text_field( $command ),
			];
		}

		return $suggestions;
	}

	private static function is_valid_user_command( $command ) {

		// reject CLI-style
		if ( strpos( $command, '--' ) !== false ) {
			return false;
		}

		if ( preg_match( '/^[a-z0-9_.]+\s*--/i', $command ) ) {
			return false;
		}

		return true;
	}

	private static function should_use_ai() {

		if ( 'yes' !== get_option( 'yoro_ai_suggestions_enabled', 'no' ) ) {
			return false;
		}

		if ( ! class_exists( 'YoRo_AI_Parser' ) ) {
			return false;
		}

		return class_exists( 'YoRo_AI_Provider_Resolver' )
			? YoRo_AI_Provider_Resolver::supports( 'suggestions' )
			: false;
	}

	private static function build_ai_suggestion_prompt( $action, $result, $parsed, $context ) {
		return YoRo_AI_Suggestions_Prompt::build( $action );
	}

	private static function normalize_suggestions( $suggestions ) {
		$normalized = [];

		foreach ( $suggestions as $suggestion ) {
			if ( empty( $suggestion['label'] ) ) {
				continue;
			}

			$type = sanitize_key( $suggestion['type'] ?? 'action' );

			if ( ! in_array( $type, [ 'action', 'direct_action' ], true ) ) {
				continue;
			}

			$action = sanitize_text_field( $suggestion['action'] ?? $suggestion['value'] ?? '' );

			if ( '' === $action || ! class_exists( 'YoRo_Action_Registry' ) || ! YoRo_Action_Registry::get( $action ) ) {
				continue;
			}

			$params = isset( $suggestion['params'] ) && is_array( $suggestion['params'] )
				? $suggestion['params']
				: [];
			$key = sanitize_key( $suggestion['label'] . '_' . $action . '_' . wp_json_encode( $params ) );

			$normalized[ $key ] = [
				'type'           => 'action',
				'label'          => sanitize_text_field( $suggestion['label'] ),
				'value'          => $action,
				'action'         => $action,
				'params'         => self::sanitize_action_params( $params ),
				'confirm'        => ! empty( $suggestion['confirm'] ),
				'preview'        => ! empty( $suggestion['preview'] ),
			];
		}

		return array_slice( array_values( $normalized ), 0, 6 );
	}

	private static function action_suggestion( $label, $action, $params = [], $confirm = false, $preview = false ) {
		return [
			'type'    => 'action',
			'label'   => sanitize_text_field( $label ),
			'value'   => sanitize_text_field( $action ),
			'action'  => sanitize_text_field( $action ),
			'params'  => is_array( $params ) ? $params : [],
			'confirm' => ! empty( $confirm ),
			'preview' => ! empty( $preview ),
		];
	}

	private static function open_order_suggestion( $label, $order_id ) {
		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			return [];
		}

		return self::action_suggestion(
			$label,
			'orders.get_details',
			[
				'order_id'  => $order_id,
				'order_ids' => [ $order_id ],
			]
		);
	}

	private static function sanitize_action_params( $params ) {
		if ( ! is_array( $params ) ) {
			return [];
		}

		$sanitized = [];

		foreach ( $params as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$sanitized[ $key ] = array_values(
					array_map(
						function( $item ) {
							return is_numeric( $item ) ? absint( $item ) : sanitize_text_field( (string) $item );
						},
						$value
					)
				);
				continue;
			}

			$sanitized[ $key ] = is_numeric( $value ) ? absint( $value ) : sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}

	private static function normalize_ai_command( $command ) {

		// orders.list --status=on-hold
		if ( preg_match( '/orders\.list.*status=([a-z-]+)/i', $command, $m ) ) {
			return 'Show orders that are ' . $m[1];
		}

		// customers.contact
		if ( strpos( $command, 'customers.contact' ) !== false ) {
			return 'Email this customer';
		}

		return $command;
	}

	private static function inject_context_into_command( $command, $parsed = [], $result = [], $context = [] ) {

		$order_ids = self::resolve_context_order_ids( $parsed, $result, $context );
		$order_id  = ! empty( $order_ids[0] ) ? absint( $order_ids[0] ) : 0;

		$email = self::resolve_context_email( $parsed, $result, $context );

		// Multi-order references.
		if ( ! empty( $order_ids ) ) {
			$order_refs = implode(
				', ',
				array_map(
					function( $id ) {
						return '#' . absint( $id );
					},
					$order_ids
				)
			);

			$command = preg_replace(
				'/\b(those|these)\s+orders\b/i',
				'orders ' . $order_refs,
				$command
			);

			$command = preg_replace(
				'/\ball\s+(of\s+)?(those|these|them)\b/i',
				'orders ' . $order_refs,
				$command
			);
		}

		// Single order references.
		if ( $order_id ) {
			$command = preg_replace(
				'/\b(this|that)\s+order\b/i',
				'order #' . $order_id,
				$command
			);
		}

		// Customer references.
		if ( $email ) {
			$command = preg_replace(
				'/\b(this|that|same)\s+customer\b/i',
				'customer ' . $email,
				$command
			);

			$command = preg_replace(
				'/\b(this|that|same)\s+buyer\b/i',
				'customer ' . $email,
				$command
			);
		} elseif ( $order_id ) {
			$command = preg_replace(
				'/\b(this|that|same)\s+customer\b/i',
				'customer of order #' . $order_id,
				$command
			);

			$command = preg_replace(
				'/\b(this|that|same)\s+buyer\b/i',
				'customer of order #' . $order_id,
				$command
			);
		}

		return $command;
	}

	private static function resolve_context_order_ids( $parsed = [], $result = [], $context = [] ) {
		$order_ids = [];

		if ( ! empty( $parsed['params']['order_ids'] ) && is_array( $parsed['params']['order_ids'] ) ) {
			$order_ids = $parsed['params']['order_ids'];
		} elseif ( ! empty( $parsed['params']['order_id'] ) ) {
			$order_ids = [ $parsed['params']['order_id'] ];
		}

		if ( empty( $order_ids ) && ! empty( $result['orders'] ) && is_array( $result['orders'] ) ) {
			$order_ids = wp_list_pluck( $result['orders'], 'id' );
		}

		if ( empty( $order_ids ) && ! empty( $result['processed'] ) && is_array( $result['processed'] ) ) {
			$order_ids = $result['processed'];
		}

		$context_order_ids = self::context_value( $context, 'last_order_ids', [], 'entity_context' );
		$context_order_id  = absint(
			self::context_value( $context, 'last_referenced_order_id', 0, 'entity_context' )
		);

		if ( empty( $order_ids ) && is_array( $context_order_ids ) ) {
			$order_ids = $context_order_ids;
		}

		if ( empty( $order_ids ) && $context_order_id ) {
			$order_ids = [ $context_order_id ];
		}

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);
	}

	private static function resolve_context_email( $parsed = [], $result = [], $context = [] ) {
		if ( ! empty( $parsed['entities']['email'] ) && is_email( $parsed['entities']['email'] ) ) {
			return sanitize_email( $parsed['entities']['email'] );
		}

		if ( ! empty( $parsed['entities']['graph']['emails'][0] ) && is_email( $parsed['entities']['graph']['emails'][0] ) ) {
			return sanitize_email( $parsed['entities']['graph']['emails'][0] );
		}

		$last_entity_graph = self::context_value( $context, 'last_entity_graph', [], 'entity_context' );

		if ( ! empty( $last_entity_graph['emails'][0] ) && is_email( $last_entity_graph['emails'][0] ) ) {
			return sanitize_email( $last_entity_graph['emails'][0] );
		}

		if ( ! empty( $result['order']['billing_email'] ) && is_email( $result['order']['billing_email'] ) ) {
			return sanitize_email( $result['order']['billing_email'] );
		}

		if ( ! empty( $result['orders'][0]['billing_email'] ) && is_email( $result['orders'][0]['billing_email'] ) ) {
			return sanitize_email( $result['orders'][0]['billing_email'] );
		}

		return '';
	}

	private static function status_exists( $status ) {
		return class_exists( 'YoRo_Order_Helper' )
			&& YoRo_Order_Helper::is_valid_order_status( $status );
	}

	private static function get_extra_status_suggestions( $current_status, $order_ref = 'this order' ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return [];
		}

		$suggestions = [];
		$options = YoRo_Order_Helper::get_order_status_options();

		$preferred = [
			'processing',
			'completed',
			'on-hold',
			'cancelled',
		];

		foreach ( $options as $slug => $data ) {
			if ( $slug === $current_status ) {
				continue;
			}

			if ( in_array( $slug, $preferred, true ) ) {
				continue;
			}

			$suggestions[] = [
				'type'  => 'command',
				'label' => 'Mark ' . $data['label'],
				'value' => 'Mark ' . $order_ref . ' as ' . $data['label'],
			];

			if ( count( $suggestions ) >= 2 ) {
				break;
			}
		}

		return $suggestions;
	}

	private static function context_value( $context, $key, $default = null, $section = '' ) {
		if (
			$section &&
			isset( $context[ $section ] ) &&
			is_array( $context[ $section ] ) &&
			array_key_exists( $key, $context[ $section ] )
		) {
			$section_value = $context[ $section ][ $key ];

			if (
				self::has_context_value( $section_value ) ||
				! is_array( $context ) ||
				! array_key_exists( $key, $context )
			) {
				return $section_value;
			}
		}

		if ( is_array( $context ) && array_key_exists( $key, $context ) ) {
			return $context[ $key ];
		}

		return $default;
	}

	private static function has_context_value( $value ) {
		if ( is_array( $value ) ) {
			return ! empty( $value );
		}

		if ( is_bool( $value ) ) {
			return true === $value;
		}

		if ( is_numeric( $value ) ) {
			return (float) $value !== 0.0;
		}

		return '' !== trim( (string) $value );
	}
}
