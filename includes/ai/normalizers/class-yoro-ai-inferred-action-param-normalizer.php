<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Inferred_Action_Param_Normalizer {

	public static function infer_read_only_action_from_surface( $surface, $normalized, $params = [] ) {
		$surface = sanitize_key( (string) $surface );
		$normalized = strtolower( trim( (string) $normalized ) );
		$params = is_array( $params ) ? $params : [];

		if ( '' === $surface || 'none' === $surface ) {
			return null;
		}

		$map = [
			'morning_briefing'       => 'store.get_morning_briefing',
			'operations_summary'     => 'operations.get_summary',
			'operations_attention'   => 'operations.get_attention_queue',
			'customer_service_queue' => 'customers.get_service_queue',
			'fulfillment_queue'      => 'operations.get_fulfillment_queue',
			'inventory_queue'        => 'products.get_inventory_queue',
			'business_summary'       => 'store.get_business_summary',
			'latest_orders'          => 'orders.search',
			'order_details'          => 'orders.get_details',
		];

		$action = $map[ $surface ] ?? '';

		if ( '' === $action ) {
			return null;
		}

		if (
			'store.get_daily_briefing' !== $action &&
			preg_match( '/\b(?:daily briefing|daily brief|weekly briefing|weekly brief|monthly briefing|monthly brief|today(?:\'s| s)? briefing|this week briefing|this month briefing)\b/i', $normalized )
		) {
			$action = 'store.get_daily_briefing';
		}

		if (
			'store.get_insights' !== $action &&
			preg_match( '/\b(?:insight|insights|signals|patterns|trend|trends)\b/i', $normalized )
		) {
			$action = 'store.get_insights';
		}

		return [
			'action'     => $action,
			'params'     => $params,
			'confidence' => 0.86,
		];
	}

	public static function infer_read_only_action_from_request_shape( $command, $normalized, $action, $params, $customer_lookup_params = [], $target_object = '' ) {
		$params = is_array( $params ) ? $params : [];
		$customer_lookup_params = is_array( $customer_lookup_params ) ? $customer_lookup_params : [];
		$target_object = sanitize_text_field( (string) $target_object );

		if ( self::looks_like_customer_summary_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			return [
				'action'     => 'customers.get_summary',
				'params'     => array_merge( $customer_lookup_params, $params ),
				'confidence' => 0.91,
			];
		}

		if ( self::looks_like_customer_orders_request( $normalized ) && ! empty( $customer_lookup_params ) ) {
			return [
				'action'     => 'orders.find_by_customer',
				'params'     => array_merge( $customer_lookup_params, $params ),
				'confidence' => 0.91,
			];
		}

		if ( self::looks_like_orders_search_request( $normalized ) ) {
			return [
				'action'     => 'orders.search',
				'params'     => $params,
				'confidence' => 'orders.search' === $action ? 0.90 : 0.87,
			];
		}

		if ( 'customers.get_summary' === $action && empty( $customer_lookup_params ) && '' !== $target_object ) {
			$target_name = self::sanitize_customer_lookup_name( $target_object );

			if ( '' !== $target_name ) {
				$params['customer_name'] = $target_name;

				return [
					'action'     => 'customers.get_summary',
					'params'     => $params,
					'confidence' => 0.87,
				];
			}
		}

		return null;
	}

	public static function normalize( $action, $params, $command, $normalized, $client_context = [] ) {
		$action = sanitize_text_field( $action );
		$params = is_array( $params ) ? $params : [];

		$normalize_limit = static function( $value, $default = 5, $max = 50 ) {
			$value = absint( $value );
			if ( $value <= 0 ) {
				$value = absint( $default );
			}
			return min( max( $value, 1 ), absint( $max ) );
		};

		switch ( $action ) {
			case 'orders.search':
				$normalized_params = [];
				$normalized_params['limit'] = self::extract_requested_limit_from_command(
					$command,
					$normalize_limit( $params['limit'] ?? 10, 10, 50 ),
					50
				);
				$detected_status_from_command = class_exists( 'YoRo_Order_Helper' )
					? YoRo_Order_Helper::detect_status_from_text( $normalized )
					: '';

				if ( isset( $params['status'] ) ) {
					$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
					$status_values = array_values( array_filter( array_map( [ 'YoRo_Order_Helper', 'normalize_status_slug' ], $status_values ) ) );
					$status_values = array_values( array_filter( $status_values, [ 'YoRo_Order_Helper', 'is_valid_order_status' ] ) );

					if ( ! empty( $status_values ) ) {
						$normalized_params['status'] = $status_values;
					}
				}

				if ( empty( $detected_status_from_command ) ) {
					unset( $normalized_params['status'] );
				}

				if ( empty( $normalized_params['status'] ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$detected_status = $detected_status_from_command;

					if ( ! empty( $detected_status ) ) {
						$detected_status = YoRo_Order_Helper::normalize_status_slug( $detected_status );

						if ( YoRo_Order_Helper::is_valid_order_status( $detected_status ) ) {
							$normalized_params['status'] = [ $detected_status ];
						}
					}
				}

				foreach ( [ 'email', 'phone', 'payment_method', 'customer_name', 'billing_country', 'date_window', 'date_from', 'date_to' ] as $key ) {
					if ( empty( $params[ $key ] ) ) {
						continue;
					}

					if ( 'email' === $key ) {
						$normalized_params[ $key ] = sanitize_email( $params[ $key ] );
					} else {
						$normalized_params[ $key ] = sanitize_text_field( $params[ $key ] );
					}
				}

				foreach ( [ 'total_min', 'total_max' ] as $key ) {
					if ( isset( $params[ $key ] ) && '' !== (string) $params[ $key ] ) {
						$normalized_params[ $key ] = (float) $params[ $key ];
					}
				}

				if ( empty( $normalized_params['email'] ) && preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
					$normalized_params['email'] = sanitize_email( $matches[0] );
				}

				if ( empty( $normalized_params['phone'] ) && preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
					$normalized_params['phone'] = sanitize_text_field( trim( $matches[1] ) );
				}

				if ( empty( $normalized_params['payment_method'] ) && preg_match( '/\b(?:payment|payment method|gateway|paid by|pay by|via)\s*:?\s*([a-zA-Z0-9_\-\s]+)/i', $command, $matches ) ) {
					$normalized_params['payment_method'] = self::normalize_payment_method( $matches[1] );
				}

				if ( empty( $normalized_params['customer_name'] ) && preg_match( '/\b(?:customer|customer name|name)\s*:?\s*([a-zA-Z\s.\'-]{2,})/i', $command, $matches ) ) {
					$normalized_params['customer_name'] = sanitize_text_field( trim( $matches[1] ) );
				}

				if ( empty( $normalized_params['billing_country'] ) && preg_match( '/\b(?:billing country|country)\s*:?\s*([a-zA-Z]{2})\b/i', $command, $matches ) ) {
					$normalized_params['billing_country'] = strtoupper( sanitize_text_field( $matches[1] ) );
				}

				if ( empty( $normalized_params['date_from'] ) && empty( $normalized_params['date_to'] ) ) {
					if ( preg_match( '/\bfrom\s+(\d{4}-\d{2}-\d{2})\s+(?:to|until)\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_from'] = sanitize_text_field( $matches[1] );
						$normalized_params['date_to']   = sanitize_text_field( $matches[2] );
					} elseif ( preg_match( '/\bafter\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_from'] = sanitize_text_field( $matches[1] );
					} elseif ( preg_match( '/\bbefore\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
						$normalized_params['date_to'] = sanitize_text_field( $matches[1] );
					}
				}

				if ( ! isset( $normalized_params['total_min'] ) && ! isset( $normalized_params['total_max'] ) ) {
					if ( preg_match( '/\b(?:total|amount)\s+(?:from|between)\s+([\d.,]+)\s+(?:to|and)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_min'] = (float) str_replace( ',', '', $matches[1] );
						$normalized_params['total_max'] = (float) str_replace( ',', '', $matches[2] );
					} elseif ( preg_match( '/\b(?:total|amount)\s+(?:over|above|greater than|more than)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_min'] = (float) str_replace( ',', '', $matches[1] );
					} elseif ( preg_match( '/\b(?:total|amount)\s+(?:under|below|less than)\s+([\d.,]+)/i', $command, $matches ) ) {
						$normalized_params['total_max'] = (float) str_replace( ',', '', $matches[1] );
					}
				}

				return $normalized_params;

			case 'customers.get_summary':
				$lookup_params = self::extract_customer_lookup_params( $command, $normalized );
				return array_filter(
					[
						'limit'         => self::extract_requested_limit_from_command( $command, $normalize_limit( $params['limit'] ?? 20, 20, 50 ), 50 ),
						'email'         => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : ( ! empty( $lookup_params['email'] ) ? sanitize_email( $lookup_params['email'] ) : '' ),
						'phone'         => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : ( ! empty( $lookup_params['phone'] ) ? sanitize_text_field( $lookup_params['phone'] ) : '' ),
						'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : ( ! empty( $lookup_params['customer_name'] ) ? sanitize_text_field( $lookup_params['customer_name'] ) : '' ),
						'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : ( ! empty( $lookup_params['order_id'] ) ? absint( $lookup_params['order_id'] ) : 0 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.find_by_customer':
				$lookup_params = self::extract_customer_lookup_params( $command, $normalized );
				return array_filter(
					[
						'limit'         => self::extract_requested_limit_from_command( $command, $normalize_limit( $params['limit'] ?? 20, 20, 50 ), 50 ),
						'email'         => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : ( ! empty( $lookup_params['email'] ) ? sanitize_email( $lookup_params['email'] ) : '' ),
						'phone'         => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : ( ! empty( $lookup_params['phone'] ) ? sanitize_text_field( $lookup_params['phone'] ) : '' ),
						'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : ( ! empty( $lookup_params['customer_id'] ) ? absint( $lookup_params['customer_id'] ) : 0 ),
						'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : ( ! empty( $lookup_params['customer_name'] ) ? sanitize_text_field( $lookup_params['customer_name'] ) : '' ),
						'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : ( ! empty( $lookup_params['order_id'] ) ? absint( $lookup_params['order_id'] ) : 0 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.find_by_identity':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'limit'    => $normalize_limit( $params['limit'] ?? 20, 20, 50 ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'store.get_daily_briefing':
				return [
					'window' => self::normalize_window_param( $params['window'] ?? 'today', 'today' ),
					'limit'  => $normalize_limit( $params['limit'] ?? 1, 1, 20 ),
				];

			case 'store.get_business_summary':
				return [
					'window' => self::normalize_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'focus'  => self::normalize_focus_param( 'business', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'store.get_insights':
				return [
					'window' => self::normalize_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'limit'  => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
				];

			case 'operations.get_summary':
				return [
					'window' => self::normalize_window_param( $params['window'] ?? 'last_7_days', 'last_7_days' ),
					'focus'  => self::normalize_focus_param( 'operations', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'products.get_inventory_queue':
				return [
					'limit' => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
					'focus' => self::normalize_focus_param( 'inventory', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'operations.get_fulfillment_queue':
			case 'operations.get_attention_queue':
			case 'customers.get_service_queue':
				return [
					'limit' => $normalize_limit( $params['limit'] ?? 5, 5, 20 ),
					'focus' => self::normalize_focus_param( 'queue', $params['focus'] ?? 'overview', 'overview' ),
				];

			case 'orders.get_customer_email_drafts':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.fulfillment_followup_preview':
			case 'workflow.customer_followup_preview':
			case 'workflow.operations_attention_preview':
			case 'workflow.inventory_pressure_preview':
			case 'workflow.business_summary_preview':
				$normalized_params = [
					'original_command' => $command,
				];

				if ( ! empty( $params['reference_selector'] ) ) {
					$normalized_params['reference_selector'] = sanitize_key( $params['reference_selector'] );
				}

				if ( isset( $params['reference_index'] ) && (int) $params['reference_index'] >= 0 ) {
					$normalized_params['reference_index'] = (int) $params['reference_index'];
				}

				return $normalized_params;

			case 'workflow.refund_review':
				$delivery_mode = ! empty( $params['delivery_mode'] ) ? sanitize_key( $params['delivery_mode'] ) : 'preview';

				return array_filter(
					[
						'order_id'         => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'delivery_mode'    => $delivery_mode,
						'instruction'      => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '',
						'original_command' => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.review_suspicious_order':
				return array_filter(
					[
						'order_id'         => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'note'             => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
						'original_command' => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.verify_customer_before_fulfillment':
				return array_filter(
					[
						'order_id'            => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'email_delivery_mode' => ! empty( $params['email_delivery_mode'] ) ? sanitize_key( $params['email_delivery_mode'] ) : 'preview',
						'note'                => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
						'original_command'    => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.hold_and_add_note':
				return array_filter(
					[
						'order_id'         => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'note'             => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
						'original_command' => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.update_status_with_note':
				return array_filter(
					[
						'order_id'         => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'status'           => ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '',
						'note'             => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '',
						'original_command' => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.write_email_for_order':
			case 'workflow.write_and_save_email_draft_for_order':
			case 'workflow.write_and_send_email_for_order':
			case 'workflow.write_email_for_orders':
			case 'workflow.write_and_save_email_drafts_for_orders':
			case 'workflow.write_and_send_emails_for_orders':
				$delivery_mode = ! empty( $params['delivery_mode'] ) ? sanitize_key( $params['delivery_mode'] ) : '';

				if ( '' === $delivery_mode ) {
					if ( 'workflow.write_and_save_email_draft_for_order' === $action ) {
						$delivery_mode = 'save_draft';
					} elseif ( 'workflow.write_and_send_email_for_order' === $action ) {
						$delivery_mode = 'send';
					} elseif ( 'workflow.write_and_save_email_drafts_for_orders' === $action ) {
						$delivery_mode = 'save_draft';
					} elseif ( 'workflow.write_and_send_emails_for_orders' === $action ) {
						$delivery_mode = 'send';
					} else {
						$delivery_mode = 'preview';
					}
				}

				return array_filter(
					[
						'order_id'         => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
						'order_ids'        => ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
							? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
							: [],
						'instruction'      => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '',
						'delivery_mode'    => $delivery_mode,
						'original_command' => $command,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'workflow.cancel':
				return [
					'original_command' => $command,
				];

			case 'orders.update_status':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				$status = ! empty( $params['status'] ) ? sanitize_key( $params['status'] ) : '';

				if ( '' === $status && class_exists( 'YoRo_Order_Helper' ) ) {
					$status = YoRo_Order_Helper::detect_status_from_text( strtolower( trim( $command . ' ' . $normalized ) ) );

					if ( empty( $status ) ) {
						$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
					}

					if ( ! empty( $status ) ) {
						$status = YoRo_Order_Helper::normalize_status_slug( $status );
					}
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
						'order_id'  => ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0,
						'status'    => $status,
						'note'      => ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : __( 'Order status updated by YoRo AI.', 'yoro-ai-core' ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.hold':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.add_note':
				$order_ids = ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] )
					? array_values( array_filter( array_map( 'absint', $params['order_ids'] ) ) )
					: [];

				if ( empty( $order_ids ) && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
				}

				if ( empty( $order_ids ) && ! empty( $client_context['current_order_id'] ) ) {
					$order_ids = [ absint( $client_context['current_order_id'] ) ];
				}

				$note = ! empty( $params['note'] ) ? sanitize_textarea_field( $params['note'] ) : '';

				if ( '' === $note && preg_match( '/:\s*(.+)$/', $command, $matches ) ) {
					$note = sanitize_textarea_field( trim( $matches[1] ) );
				}

				return array_filter(
					[
						'order_ids' => $order_ids,
						'order_id'  => ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0,
						'note'      => $note,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.compose_customer_email':
				$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

				if ( ! $order_id && class_exists( 'YoRo_Order_Helper' ) ) {
					$order_ids = array_values( array_filter( array_map( 'absint', YoRo_Order_Helper::extract_order_ids_from_text( $command ) ) ) );
					$order_id = ! empty( $order_ids ) ? absint( reset( $order_ids ) ) : 0;
				}

				if ( ! $order_id && ! empty( $client_context['current_order_id'] ) ) {
					$order_id = absint( $client_context['current_order_id'] );
				}

				return array_filter(
					[
						'order_id'    => $order_id,
						'instruction' => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : sanitize_textarea_field( $command ),
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.send_customer_email':
			case 'orders.save_customer_email_draft':
			case 'orders.update_customer_email_draft':
			case 'orders.delete_customer_email_draft':
			case 'email_templates.save':
			case 'email_templates.delete':
				return self::normalize_email_workflow_action_params( $action, $params, $command, $normalized, $client_context );
		}

		return $params;
	}

	public static function normalize_window_param( $value, $default = 'last_7_days' ) {
		$value = sanitize_key( str_replace( '-', '_', (string) $value ) );

		$map = [
			'today'         => 'today',
			'yesterday'     => 'yesterday',
			'this_week'     => 'this_week',
			'last_week'     => 'last_week',
			'weekly'        => 'last_7_days',
			'last_7_days'   => 'last_7_days',
			'this_month'    => 'this_month',
			'last_month'    => 'last_month',
			'monthly'       => 'last_30_days',
			'last_30_days'  => 'last_30_days',
		];

		return $map[ $value ] ?? $default;
	}

	public static function normalize_focus_param( $family, $value, $default = 'overview' ) {
		$value = sanitize_key( str_replace( '-', '_', (string) $value ) );

		$maps = [
			'business' => [ 'overview', 'operations', 'customers', 'products' ],
			'operations' => [ 'overview', 'orders', 'backlog', 'shipping' ],
			'inventory' => [ 'overview', 'low_stock', 'out_of_stock', 'backorder' ],
			'queue' => [ 'overview', 'pending', 'processing', 'backlog', 'needs_reply' ],
		];

		$allowed = $maps[ $family ] ?? [ $default ];

		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	public static function extract_template_name_from_command( $command ) {
		if ( preg_match( '/\btemplate\s+named\s+"([^"]+)"/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\btemplate\s+named\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\bas\s+template\s+"([^"]+)"/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\bas\s+template\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	public static function looks_like_customer_summary_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:customer\s+(?:profile|summary|record|overview)|profile\s+for\s+(?:this|that|same)?\s*customer|summary\s+for\s+(?:this|that|same)?\s*customer)\b/i',
			$normalized
		);
	}

	public static function looks_like_customer_orders_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:orders?\s+for\s+(?:this|that|same)?\s*customer|this customer(?:\'s| s)?\s+orders|customer\s+orders?)\b/i',
			$normalized
		);
	}

	public static function looks_like_orders_search_request( $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if ( self::looks_like_customer_orders_request( $normalized ) || self::looks_like_customer_summary_request( $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\border\s*#?\s*\d+\b/i', $normalized ) ) {
			return false;
		}

		$is_refunded_order_search = (bool) preg_match(
			'/\b(?:show|get|view|find|list|search|open|review)\b.*\b(?:a|an|one|\d{1,2}|some)?\s*(?:refund|refunded)\s+orders?\b/i',
			$normalized
		);

		if ( preg_match( '/\b(?:update|change|status|hold|refund|note|email|shipment|shipping label|draft|send|save draft|delete)\b/i', $normalized ) && ! $is_refunded_order_search ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:latest|recent|last|newest|lestet|pending|processing|completed|cancelled|canceled|refund|refunded|failed|on[- ]hold|orders?\s+list|show\s+orders|find\s+orders|search\s+orders|list\s+orders|review\s+orders)\b|\b(?:show|get|view|find|list|search|review)\s+(?:me\s+)?\d{1,2}\s+orders?\b|\b(?:show|get|view|find|open|review)\s+(?:me\s+)?(?:a|an|one)\s+(?:pending|processing|completed|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+order\b|\b\d{1,2}\s+(?:pending|processing|completed|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			$normalized
		);
	}

	public static function extract_customer_lookup_params( $command, $normalized, $target_object = '' ) {
		$params = [];

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$params['email'] = sanitize_email( $matches[0] );
		}

		if ( preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
			$params['phone'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( empty( $params['phone'] ) ) {
			$bare_phone = self::extract_customer_lookup_phone_from_command( $command, $normalized );

			if ( '' !== $bare_phone ) {
				$params['phone'] = $bare_phone;
			}
		}

		if ( preg_match( '/\bcustomer\s*(?:id)?\s*#?:?\s*(\d+)\b/i', $command, $matches ) ) {
			$params['customer_id'] = absint( $matches[1] );
		}

		if ( empty( $params['email'] ) && empty( $params['phone'] ) && empty( $params['customer_id'] ) ) {
			$name = self::extract_customer_lookup_name_from_command( $command, $normalized, $target_object );

			if ( '' !== $name ) {
				$params['customer_name'] = $name;
			}
		}

		return $params;
	}

	private static function extract_customer_lookup_name_from_command( $command, $normalized, $target_object = '' ) {
		$patterns = [
			'/\b(?:customer\s+(?:profile|summary|record|overview)\s+(?:for|of)\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
			'/\b(?:show|open|view|get|load|summarize)\s+(?:me\s+)?([a-zA-Z][a-zA-Z\s.\'\-]{1,80})[\'’]s\s+customer\s+(?:profile|summary|record|overview)\b/i',
			'/\b(?:orders?\s+for\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				$name = self::sanitize_customer_lookup_name( $matches[1] ?? '' );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		$target_object = self::sanitize_customer_lookup_name( $target_object );

		if ( '' !== $target_object && ! preg_match( '/\b(?:customer|this|that|same|profile|summary|record|overview)\b/i', strtolower( $target_object ) ) ) {
			return $target_object;
		}

		if (
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'that customer' ) ||
			false !== strpos( $normalized, 'same customer' )
		) {
			return '';
		}

		return '';
	}

	private static function extract_customer_lookup_phone_from_command( $command, $normalized ) {
		if ( ! self::looks_like_customer_summary_request( $normalized ) && ! self::looks_like_customer_orders_request( $normalized ) ) {
			return '';
		}

		if ( preg_match( '/(?:for|customer|summary|profile|record|overview)\s+([+\d][\d\s().\-]{7,})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/([+\d][\d\s().\-]{7,})$/', trim( $command ), $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	public static function sanitize_customer_lookup_name( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

		$blocked = [
			'customer',
			'this customer',
			'that customer',
			'same customer',
			'profile',
			'summary',
			'record',
			'overview',
		];

		return in_array( strtolower( $value ), $blocked, true ) ? '' : $value;
	}

	public static function extract_requested_limit_from_command( $command, $default = 10, $max = 50 ) {
		$default = max( 1, absint( $default ) );
		$max     = max( $default, absint( $max ) );

		$patterns = [
			'/\b(?:limit|top|latest|recent|last|newest|lestet)\s+(\d{1,2})\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:new|latest|recent|last|newest|lestet)\s+orders?\b/i',
			'/\b(\d{1,2})\s+(?:new|latest|recent|last|newest|lestet)\s+orders?\b/i',
			'/\b(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(?:me\s+)?(\d{1,2})\s+(?:pending|processing|completed|complete|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/i',
			'/\bfirst\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), $max );
			}
		}

		return min( $default, $max );
	}

	public static function normalize_payment_method( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );

		$map = [
			'stripe'               => 'stripe',
			'credit card'          => 'stripe',
			'card'                 => 'stripe',
			'visa'                 => 'stripe',
			'mastercard'           => 'stripe',
			'paypal'               => 'paypal',
			'pay pal'              => 'paypal',
			'cod'                  => 'cod',
			'cash on delivery'     => 'cod',
			'cash'                 => 'cod',
			'bacs'                 => 'bacs',
			'bank transfer'        => 'bacs',
			'direct bank transfer' => 'bacs',
			'wire transfer'        => 'bacs',
			'cheque'               => 'cheque',
			'check'                => 'cheque',
		];

		return $map[ $value ] ?? sanitize_key( $value );
	}

	public static function normalize_email_workflow_action_params( $action, $params, $command, $normalized, $client_context = [] ) {
		$params = is_array( $params ) ? $params : [];
		$workflow = isset( $client_context['current_workflow'] ) && is_array( $client_context['current_workflow'] )
			? $client_context['current_workflow']
			: [];

		$workflow_order_id = absint( $workflow['order_id'] ?? 0 );
		$workflow_subject  = sanitize_text_field( $workflow['subject'] ?? '' );
		$workflow_body     = sanitize_textarea_field( $workflow['body'] ?? '' );
		$workflow_draft_id = sanitize_key( $workflow['draft_id'] ?? '' );

		switch ( $action ) {
			case 'orders.send_customer_email':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.save_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.update_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : $workflow_draft_id,
						'subject'  => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'     => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'orders.delete_customer_email_draft':
				return array_filter(
					[
						'order_id' => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : $workflow_order_id,
						'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : $workflow_draft_id,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'email_templates.save':
				return array_filter(
					[
						'name'    => ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : self::extract_template_name_from_command( $command ),
						'subject' => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : $workflow_subject,
						'body'    => ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : $workflow_body,
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);

			case 'email_templates.delete':
				return array_filter(
					[
						'template_id' => ! empty( $params['template_id'] ) ? sanitize_key( $params['template_id'] ) : '',
					],
					static function( $value ) {
						return ! ( '' === $value || 0 === $value || [] === $value );
					}
				);
		}

		return $params;
	}
}
