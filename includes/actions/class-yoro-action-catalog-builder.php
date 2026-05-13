<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Catalog_Builder {

	public static function build( $context = [] ) {
		$catalog = [];

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$catalog[] = self::build_action_item( $name, $action, $context );
		}

		return apply_filters(
			'yoro_ai_action_catalog',
			array_values( array_filter( $catalog ) ),
			$context
		);
	}

	public static function build_names( $context = [] ) {
		return array_values(
			array_map(
				function ( $item ) {
					return $item['name'];
				},
				self::build( $context )
			)
		);
	}

	private static function build_action_item( $name, $action, $context = [] ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return null;
		}

		$input_schema = isset( $action['input_schema'] ) && is_array( $action['input_schema'] )
			? $action['input_schema']
			: [];

		$required_params = ! empty( $action['required_params'] ) && is_array( $action['required_params'] )
			? array_values( array_map( 'sanitize_key', $action['required_params'] ) )
			: self::infer_required_params( $name, $input_schema );

		return [
			'name'                  => $name,
			'label'                 => sanitize_text_field( $action['label'] ?? $name ),
			'description'           => sanitize_text_field( $action['ai_description'] ?: ( $action['description'] ?? '' ) ),
			'category'              => sanitize_key( $action['category'] ?? 'general' ),
			'input_schema'          => self::normalize_input_schema( $input_schema ),
			'required_params'       => $required_params,
			'param_hints'           => isset( $action['param_hints'] ) && is_array( $action['param_hints'] ) ? $action['param_hints'] : [],
			'clarification_hints'   => isset( $action['clarification_hints'] ) && is_array( $action['clarification_hints'] ) ? $action['clarification_hints'] : [],
			'examples'              => self::examples_for_action( $name, $action ),
			'write_action'          => ! empty( $action['write_action'] ),
			'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			'risk_level'            => sanitize_key( $action['risk_level'] ?? 'low' ),
			'max_items'             => isset( $action['max_items'] ) ? absint( $action['max_items'] ) : 1,
			'capabilities'          => isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
				? array_values( array_map( 'sanitize_key', $action['capabilities'] ) )
				: [],
		];
	}

	private static function normalize_input_schema( $schema ) {
		$normalized = [];

		foreach ( $schema as $param => $type ) {
			$param = sanitize_key( $param );

			if ( '' === $param ) {
				continue;
			}

			$normalized[ $param ] = sanitize_text_field( $type );
		}

		return $normalized;
	}

	private static function infer_required_params( $action, $input_schema ) {
		$map = [
			'orders.get_details'                   => [ 'order_ids' ],
			'orders.refund_preview'                => [ 'order_id' ],
			'orders.email_customer_preview'        => [ 'order_id', 'template' ],
			'orders.email_customer_template'       => [ 'order_id', 'template' ],
			'orders.use_email_template'            => [ 'order_id', 'template_id' ],
			'orders.compose_customer_email'        => [ 'order_id', 'instruction' ],
			'orders.save_customer_email_draft'     => [ 'order_id', 'subject', 'body' ],
			'orders.get_customer_email_drafts'     => [ 'order_id' ],
			'orders.update_customer_email_draft'   => [ 'order_id', 'draft_id', 'subject', 'body' ],
			'orders.delete_customer_email_draft'   => [ 'order_id', 'draft_id' ],
			'orders.render_customer_email_preview' => [ 'order_id', 'subject', 'body' ],
			'orders.send_customer_email'           => [ 'order_id', 'subject', 'body' ],
			'orders.add_private_note_template'     => [ 'order_ids', 'template' ],
			'orders.hold'                          => [ 'order_ids' ],
			'orders.add_note'                      => [ 'order_ids', 'note' ],
			'orders.remove_note'                   => [ 'note_ids' ],
			'orders.update_status'                 => [ 'order_ids', 'status' ],
			'orders.restore_statuses'              => [ 'statuses' ],
			'orders.bulk_update_status_with_note'  => [ 'order_ids', 'status', 'note' ],
			'email_templates.save'                 => [ 'name', 'subject', 'body' ],
			'email_templates.delete'               => [ 'template_id' ],
		];

		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}

		return [];
	}

	private static function examples_for_action( $name, $action ) {
		$examples = isset( $action['ai_examples'] ) && is_array( $action['ai_examples'] )
			? $action['ai_examples']
			: [];

		$defaults = self::default_examples();

		if ( isset( $defaults[ $name ] ) ) {
			$examples = array_merge( $examples, $defaults[ $name ] );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $example ) {
							return is_scalar( $example ) ? sanitize_text_field( $example ) : '';
						},
						$examples
					)
				)
			)
		);
	}

	private static function default_examples() {
		return [
			'orders.search' => [
				'Show recent orders',
				'Cho tôi xem các đơn gần đây',
			],
			'orders.get_details' => [
				'Show order #123',
				'Xem chi tiết đơn #123',
			],
			'orders.update_status' => [
				'Mark order #123 as completed',
				'Chuyển đơn #123 sang hoàn tất',
			],
			'orders.hold' => [
				'Put order #123 on hold',
				'Giữ đơn #123',
			],
			'orders.add_note' => [
				'Add note to order #123: customer asked for a callback',
				'Thêm ghi chú vào đơn #123: khách muốn gọi lại',
			],
			'orders.compose_customer_email' => [
				'Write an email for order #123 asking for payment confirmation',
				'Viết email cho đơn #123 để xác nhận thanh toán',
			],
			'orders.send_customer_email' => [
				'Send this email to the customer',
				'Gửi email này cho khách',
			],
			'store.get_daily_briefing' => [
				'Show today\'s briefing',
				'Cho tôi xem briefing hôm nay',
			],
			'store.get_business_summary' => [
				'Show business summary this week',
				'Tóm tắt tình hình kinh doanh tuần này',
			],
			'operations.get_attention_queue' => [
				'What needs attention right now?',
				'Có việc gì cần xử lý gấp không?',
			],
			'operations.get_fulfillment_queue' => [
				'What orders are waiting to ship?',
				'Đơn nào đang chờ giao hàng?',
			],
			'products.get_inventory_queue' => [
				'Show low stock products',
				'Sản phẩm nào sắp hết hàng?',
			],
			'customers.get_service_queue' => [
				'Who needs customer follow-up?',
				'Khách nào cần chăm sóc tiếp?',
			],
		];
	}
}
