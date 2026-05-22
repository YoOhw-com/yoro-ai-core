<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Real_Command_Eval_Pack {

	private static $fixture_map = null;

	public static function run( $options = [] ) {
		$options = is_array( $options ) ? $options : [];
		$cases   = self::cases();
		$results = [];

		if ( empty( $options['live_ai'] ) ) {
			self::enable_router_fixtures( $cases );
		}

		try {
			foreach ( $cases as $case ) {
				$results[] = self::run_case( $case );
			}
		} finally {
			if ( empty( $options['live_ai'] ) ) {
				self::disable_router_fixtures();
			}
		}

		$total  = count( $results );
		$passed = count(
			array_filter(
				$results,
				static function( $result ) {
					return ! empty( $result['passed'] );
				}
			)
		);

		return [
			'results' => $results,
			'summary' => [
				'total'  => $total,
				'passed' => $passed,
				'failed' => $total - $passed,
			],
		];
	}

	public static function mock_command_router_decision( $decision, $command, $context ) {
		if ( null === self::$fixture_map ) {
			self::$fixture_map = self::build_fixture_map( self::cases() );
		}

		$key = self::fixture_key( $command );

		return self::$fixture_map[ $key ] ?? $decision;
	}

	public static function cases() {
		return [
			[
				'name'    => 'Aged pending orders stays a filtered order search',
				'command' => 'Are there any orders pending for more than 3 days?',
				'mode'    => 'execute',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10, 'status' => [ 'pending' ], 'min_age_hours' => 72 ], 0.85 ),
				'expect'  => [
					'parsed.action'                         => 'orders.search',
					'parsed.intent_family'                  => 'search_orders',
					'parsed.params.status.0'                => 'pending',
					'parsed.params.min_age_hours'           => 72,
					'parsed.command_shape.age_filter.hours' => 72,
						'result.display_type'                   => 'orders_list',
						'result.min_age_hours'                  => 72,
						'result.age_filter_label'               => '3 days',
						'result.summary'                        => 'I found 10 pending orders older than 3 days.',
					],
					'expect_contains' => [
						'result.summary' => [
							'I found',
							'older than 3 days',
						],
					],
					'expect_not_contains' => [
						'result.summary' => [
							'You query indicates',
							'Decision route',
							'Found 10',
						],
						'result.assistant_followup' => [
							'If you want',
							'You query indicates',
							'Decision route',
						],
					],
				],
				[
					'name'    => 'Semantic frame overrides attention queue misroute to order search',
					'command' => 'What needs attention right now?',
					'fixture' => self::fixture(
						'task_request',
						'operations.get_attention_queue',
						[ 'limit' => 5 ],
						0.82,
						[],
						'',
						[],
						[
							'semantic_frame' => [
								'version' => 1,
								'surface' => 'orders',
								'operation' => 'search',
								'target_type' => 'order_list',
								'constraints' => [
									'quantity' => 10,
									'status' => 'pending',
									'date' => [
										'window' => null,
										'operator' => 'older_than',
										'value' => 3,
										'unit' => 'days',
										'text' => 'more than 3 days',
									],
									'money' => [
										'operator' => null,
										'value' => null,
										'min' => null,
										'max' => null,
										'currency' => null,
										'text' => null,
									],
									'customer_identity' => [
										'type' => null,
										'value' => null,
									],
									'payment_method' => null,
									'shipping_state' => null,
								],
								'evidence' => [
									'surface' => 'orders',
									'operation' => 'Are there any',
									'target' => 'orders',
									'quantity' => null,
									'status' => 'pending',
									'date' => 'more than 3 days',
									'money' => null,
									'customer' => null,
									'payment' => null,
									'shipping' => null,
								],
								'confidence' => 0.82,
							],
						]
					),
					'expect'  => [
						'parsed.action'                                      => 'orders.search',
						'parsed.intent_family'                               => 'search_orders',
						'parsed.params.limit'                                => 10,
						'parsed.params.status.0'                             => 'pending',
						'parsed.params.min_age_hours'                        => 72,
						'parsed.semantic_frame_validator_changes.0.type'     => 'action_override',
						'parsed.semantic_frame_validator_changes.0.previous' => 'operations.get_attention_queue',
						'parsed.semantic_frame_validator_changes.1.param'    => 'status',
						'parsed.semantic_frame_validator_changes.2.param'    => 'limit',
						'parsed.semantic_frame_validator_changes.3.param'    => 'min_age_hours',
					],
				],
				[
					'name'    => 'Operations attention stays a broad queue',
					'command' => 'Show 10 orders needing attention',
					'fixture' => self::fixture( 'task_request', 'operations.get_attention_queue', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'       => 'operations.get_attention_queue',
					'parsed.intent_family'=> 'operations_attention',
					'parsed.params.limit' => 10,
					],
				],
				[
					'name'    => 'Operations attention assistant copy avoids old phrasing',
					'command' => 'Show operations attention queue',
					'mode'    => 'execute',
					'fixture' => self::fixture( 'task_request', 'operations.get_attention_queue', [], 0.9 ),
					'expect'  => [
						'parsed.action'       => 'operations.get_attention_queue',
						'result.display_type' => 'operations_attention_queue',
					],
					'expect_contains' => [
						'result.assistant_followup' => [
							'Next,',
						],
					],
				'expect_not_contains' => [
					'result.assistant_summary' => [
						'You query indicates',
						'Decision route',
					],
					'result.assistant_followup' => [
						'If you want',
						'You query indicates',
						'Decision route',
					],
				],
			],
			[
				'name'    => 'Unsupported developer handoff copy avoids old phrasing',
				'command' => 'Can you create shipping labels with ShipStation for me?',
				'fixture' => array_merge(
					self::fixture( 'unsupported_request', '', [], 0.93 ),
					[
						'summary'                => 'That shipping-label workflow is not supported here yet.',
						'clarification_question' => 'If you want, I can pass that request to the developers.',
						'reasoning_summary'      => 'You query indicates an unsupported shipping integration request.',
					]
				),
				'expect'  => [
					'parsed.response_only'                 => true,
					'parsed.result.conversation_topic'     => 'unsupported_request',
					'parsed.result.developer_handoff_offer' => true,
					'parsed.result.suggestions.0.action'   => 'assistant.submit_developer_request',
				],
				'expect_contains' => [
					'parsed.result.assistant_followup' => [
						'Next,',
					],
				],
				'expect_not_contains' => [
					'parsed.result.summary' => [
						'You query indicates',
						'Decision route',
					],
					'parsed.result.assistant_followup' => [
						'If you want',
						'You query indicates',
						'Decision route',
					],
					'parsed.reasoning_summary' => [
						'You query indicates',
						'Decision route',
					],
				],
			],
			[
				'name'    => 'Natural attention phrasing uses attention queue',
				'command' => 'What needs attention today?',
				'fixture' => self::fixture( 'task_request', 'operations.get_attention_queue', [ 'limit' => 5 ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'operations.get_attention_queue',
					'parsed.intent_family' => 'operations_attention',
				],
			],
			[
				'name'    => 'On-hold orders are a status search',
				'command' => 'Show on-hold orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'status' => [ 'on-hold' ], 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'          => 'orders.search',
					'parsed.params.status.0' => 'on-hold',
				],
			],
			[
				'name'    => 'Latest order list remains read-only search',
				'command' => 'Show latest 5 orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 5 ], 0.9 ),
				'expect'  => [
					'parsed.action'                             => 'orders.search',
					'parsed.params.limit'                       => 5,
					'parsed.command_shape.quantity_scope.scope' => 'latest',
					'parsed.command_shape.quantity_scope.value' => 5,
				],
			],
			[
				'name'    => 'Broken completed order count is a list search',
				'command' => '3 completed order',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 3, 'status' => [ 'completed' ] ], 0.86 ),
				'expect'  => [
					'parsed.action'                             => 'orders.search',
					'parsed.params.limit'                       => 3,
					'parsed.params.status.0'                    => 'completed',
					'parsed.command_shape.quantity_scope.scope' => 'status_count',
					'parsed.command_shape.quantity_scope.value' => 3,
				],
			],
			[
				'name'    => 'All pending orders carries all quantity scope',
				'command' => 'Show all pending orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'status' => [ 'pending' ] ], 0.9 ),
				'expect'  => [
					'parsed.action'                             => 'orders.search',
					'parsed.params.limit'                       => 50,
					'parsed.params.status.0'                    => 'pending',
					'parsed.command_shape.quantity_scope.scope' => 'all',
					'parsed.command_shape.quantity_scope.value' => 50,
				],
			],
			[
				'name'    => 'Refunded order count is not a refund write',
				'command' => 'Open 3 refund orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 3, 'status' => [ 'refunded' ] ], 0.86 ),
				'expect'  => [
					'parsed.action'                             => 'orders.search',
					'parsed.params.limit'                       => 3,
					'parsed.params.status.0'                    => 'refunded',
					'parsed.command_shape.quantity_scope.scope' => 'status_count',
					'parsed.command_shape.quantity_scope.value' => 3,
				],
			],
			[
				'name'    => 'Shorthand refund orders means refunded search',
				'command' => '3 refund orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 3, 'status' => [ 'refunded' ] ], 0.84 ),
				'expect'  => [
					'parsed.action'          => 'orders.search',
					'parsed.params.limit'    => 3,
					'parsed.params.status.0' => 'refunded',
				],
			],
			[
				'name'    => 'Refund write without target clarifies',
				'command' => 'Refund 3 orders',
				'fixture' => self::fixture( 'clarify', 'orders.update_status', [ 'status' => 'refunded' ], 0.72, [ 'order_ids' ], 'Which 3 order IDs should I refund?' ),
				'expect'  => [
					'parsed.stage'                                      => 'clarify',
					'parsed.action'                                     => 'orders.update_status',
					'parsed.params.status'                              => 'refunded',
					'parsed.message'                                    => 'Which 3 order IDs should I refund?',
					'parsed.missing.0'                                  => 'order_ids',
					'parsed.options'                                    => [],
					'parsed.command_shape.write_missing_target.action'  => 'orders.update_status',
					'parsed.command_shape.write_missing_target.quantity'=> 3,
					'parsed.command_shape.write_missing_target.reason'  => 'missing_explicit_order_ids',
				],
			],
			[
				'name'    => 'Explicit multi-order refund preserves every order id',
				'command' => 'Refund orders 6847, 6683, and 6681',
				'fixture' => self::fixture( 'task_request', 'orders.update_status', [ 'order_ids' => [ 6847, 6683, 6681 ], 'status' => 'refunded' ], 0.92 ),
				'expect'  => [
					'parsed.action'                        => 'orders.update_status',
					'parsed.params.status'                 => 'refunded',
					'parsed.params.order_ids.0'            => 6847,
					'parsed.params.order_ids.1'            => 6683,
					'parsed.params.order_ids.2'            => 6681,
					'parsed.command_shape.explicit_order_ids.0' => 6847,
					'parsed.command_shape.explicit_order_ids.1' => 6683,
					'parsed.command_shape.explicit_order_ids.2' => 6681,
				],
			],
			[
				'name'    => 'Hold orders without target clarifies',
				'command' => 'Hold orders',
				'fixture' => self::fixture( 'clarify', 'orders.hold', [], 0.72, [ 'order_ids' ], 'Which order IDs should I put on hold?' ),
				'expect'  => [
					'parsed.stage'                                      => 'clarify',
					'parsed.action'                                     => 'orders.hold',
					'parsed.missing.0'                                  => 'order_ids',
					'parsed.options'                                    => [],
					'parsed.command_shape.write_missing_target.action'  => 'orders.hold',
					'parsed.command_shape.write_missing_target.reason'  => 'missing_explicit_order_ids',
				],
			],
			[
				'name'    => 'Complete pending orders without explicit target clarifies',
				'command' => 'Complete pending orders',
				'fixture' => self::fixture( 'clarify', 'orders.update_status', [ 'status' => 'completed' ], 0.72, [ 'order_ids' ], 'Which order IDs should I mark as completed?' ),
				'expect'  => [
					'parsed.stage'                                             => 'clarify',
					'parsed.action'                                            => 'orders.update_status',
					'parsed.params.status'                                      => 'completed',
					'parsed.message'                                            => 'Which order IDs should I mark as completed?',
					'parsed.missing.0'                                          => 'order_ids',
					'parsed.options'                                            => [],
					'parsed.command_shape.write_missing_target.action'          => 'orders.update_status',
					'parsed.command_shape.write_missing_target.target_status'   => 'completed',
					'parsed.command_shape.write_missing_target.reason'          => 'missing_explicit_order_ids',
				],
			],
			[
				'name'    => 'Semantic frame catches Vietnamese status write missing target',
				'command' => 'Chuyển 3 đơn hàng sang hoàn tất',
				'fixture' => self::fixture(
					'task_request',
					'orders.search',
					[ 'limit' => 3 ],
					0.86,
					[],
					'',
					[],
					[
						'language' => 'vi',
						'semantic_frame' => [
							'version' => 1,
							'surface' => 'orders',
							'operation' => 'update_status',
							'target_type' => 'order_write_missing_target',
							'constraints' => [
								'quantity' => 3,
								'order_id' => null,
								'order_ids' => [],
								'status' => 'completed',
								'date' => [
									'window' => null,
									'operator' => null,
									'value' => null,
									'unit' => null,
									'text' => null,
								],
								'money' => [
									'operator' => null,
									'value' => null,
									'min' => null,
									'max' => null,
									'currency' => null,
									'text' => null,
								],
								'customer_identity' => [
									'type' => null,
									'value' => null,
								],
								'payment_method' => null,
								'shipping_state' => null,
							],
							'evidence' => [
								'surface' => 'đơn hàng',
								'operation' => 'Chuyển',
								'target' => '3 đơn hàng',
								'order_id' => null,
								'quantity' => '3',
								'status' => 'hoàn tất',
								'date' => null,
								'money' => null,
								'customer' => null,
								'payment' => null,
								'shipping' => null,
							],
							'confidence' => 0.86,
						],
					]
				),
				'expect'  => [
					'parsed.stage'                                           => 'clarify',
					'parsed.action'                                          => 'orders.update_status',
					'parsed.intent_family'                                   => 'update_order_status',
					'parsed.params.status'                                   => 'completed',
					'parsed.missing.0'                                       => 'order_ids',
					'parsed.semantic_frame_validator_changes.0.type'         => 'clarify_override',
					'parsed.semantic_frame_validator_changes.0.previous'     => 'task_request',
					'parsed.semantic_frame_validator_changes.1.type'         => 'action_override',
					'parsed.semantic_frame_validator_changes.1.previous'     => 'orders.search',
				],
				'expect_contains' => [
					'parsed.message' => [
						'Vui lòng cung cấp order ID',
					],
				],
			],
			[
				'name'    => 'Hold latest orders becomes query and apply',
				'command' => 'Hold latest 5 orders',
				'fixture' => self::fixture(
					'task_request',
					'orders.hold',
					[],
					0.88,
					[]
				),
				'expect'  => [
					'parsed.action'                        => 'multi.query_and_apply',
					'parsed.command_shape.target_type'     => 'order_query_selection',
					'parsed.command_shape.write_missing_target' => [],
					'parsed.actions.0.action'              => 'orders.search',
					'parsed.actions.1.action'              => 'orders.hold',
				],
			],
			[
				'name'    => 'Hold count without target clarifies',
				'command' => 'Hold 3 orders',
				'fixture' => self::fixture( 'clarify', 'orders.hold', [], 0.78, [ 'order_ids' ], 'Which 3 order IDs should I put on hold?' ),
				'expect'  => [
					'parsed.stage'     => 'clarify',
					'parsed.action'    => 'orders.hold',
					'parsed.missing.0' => 'order_ids',
				],
			],
			[
				'name'    => 'Semantic frame routes Vietnamese explicit status write',
				'command' => 'Chuyển đơn hàng 6847 sang hoàn tất',
				'fixture' => self::fixture(
					'task_request',
					'orders.search',
					[ 'limit' => 10 ],
					0.9,
					[],
					'',
					[],
					[
						'language' => 'vi',
						'semantic_frame' => [
							'version' => 1,
							'surface' => 'orders',
							'operation' => 'update_status',
							'target_type' => 'order_status_write',
							'constraints' => [
								'quantity' => null,
								'order_id' => 6847,
								'order_ids' => [ 6847 ],
								'status' => 'completed',
								'date' => [
									'window' => null,
									'operator' => null,
									'value' => null,
									'unit' => null,
									'text' => null,
								],
								'money' => [
									'operator' => null,
									'value' => null,
									'min' => null,
									'max' => null,
									'currency' => null,
									'text' => null,
								],
								'customer_identity' => [
									'type' => null,
									'value' => null,
								],
								'payment_method' => null,
								'shipping_state' => null,
							],
							'evidence' => [
								'surface' => 'đơn hàng',
								'operation' => 'Chuyển',
								'target' => 'đơn hàng 6847',
								'order_id' => '6847',
								'quantity' => null,
								'status' => 'hoàn tất',
								'date' => null,
								'money' => null,
								'customer' => null,
								'payment' => null,
								'shipping' => null,
							],
							'confidence' => 0.9,
						],
					]
				),
				'expect'  => [
					'parsed.action'                                          => 'orders.update_status',
					'parsed.intent_family'                                   => 'update_order_status',
					'parsed.params.order_ids.0'                              => 6847,
					'parsed.params.order_id'                                 => 6847,
					'parsed.params.status'                                   => 'completed',
					'parsed.semantic_frame_validator_changes.0.type'         => 'action_override',
					'parsed.semantic_frame_validator_changes.0.previous'     => 'orders.search',
					'parsed.semantic_frame_validator_changes.1.param'        => 'order_ids',
					'parsed.semantic_frame_validator_changes.2.param'        => 'status',
				],
			],
			[
				'name'    => 'Explicit cancel order is status update',
				'command' => 'Mark order #6847 as cancelled',
				'fixture' => self::fixture( 'task_request', 'orders.update_status', [ 'order_id' => 6847, 'order_ids' => [ 6847 ], 'status' => 'cancelled' ], 0.91 ),
				'expect'  => [
					'parsed.action'        => 'orders.update_status',
					'parsed.params.order_id'=> 6847,
					'parsed.params.status' => 'cancelled',
				],
			],
			[
				'name'    => 'Explicit note routes to add note',
				'command' => 'Add note to order #123: customer asked for a callback',
				'fixture' => self::fixture( 'task_request', 'orders.add_note', [ 'order_id' => 123, 'order_ids' => [ 123 ], 'note' => 'customer asked for a callback' ], 0.92 ),
				'expect'  => [
					'parsed.action'         => 'orders.add_note',
					'parsed.params.order_id'=> 123,
					'parsed.params.note'    => 'customer asked for a callback',
				],
			],
			[
				'name'    => 'Specific order email authoring keeps instruction',
				'command' => 'Write an email for order #123 asking for payment confirmation',
				'fixture' => self::fixture( 'task_request', 'orders.compose_customer_email', [ 'order_id' => 123, 'order_ids' => [ 123 ], 'instruction' => 'asking for payment confirmation' ], 0.9 ),
				'expect'  => [
					'parsed.action'              => 'orders.compose_customer_email',
					'parsed.params.order_id'     => 123,
					'parsed.params.instruction'  => 'asking for payment confirmation',
				],
			],
			[
				'name'    => 'Business summary keeps business surface',
				'command' => 'How is our business doing this month?',
				'fixture' => self::fixture( 'task_request', 'store.get_business_summary', [ 'window' => 'this_month', 'focus' => 'overview' ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'store.get_business_summary',
					'parsed.params.window' => 'this_month',
				],
			],
			[
				'name'    => 'Operations today uses operations summary',
				'command' => 'What should we do today?',
				'fixture' => self::fixture( 'task_request', 'operations.get_summary', [ 'window' => 'today', 'focus' => 'overview' ], 0.88 ),
				'expect'  => [
					'parsed.action'        => 'operations.get_summary',
					'parsed.params.window' => 'today',
				],
			],
			[
				'name'    => 'Fulfillment phrasing uses fulfillment queue',
				'command' => 'What orders are waiting to ship?',
				'fixture' => self::fixture( 'task_request', 'operations.get_fulfillment_queue', [ 'limit' => 5 ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'operations.get_fulfillment_queue',
					'parsed.intent_family' => 'fulfillment_review',
				],
			],
			[
				'name'    => 'Inventory phrasing uses inventory queue',
				'command' => 'Show low stock products',
				'fixture' => self::fixture( 'task_request', 'products.get_inventory_queue', [ 'limit' => 5 ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'products.get_inventory_queue',
					'parsed.intent_family' => 'inventory_review',
				],
			],
			[
				'name'    => 'Customer follow-up uses service queue',
				'command' => 'Who needs customer follow-up?',
				'fixture' => self::fixture( 'task_request', 'customers.get_service_queue', [ 'limit' => 5 ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'customers.get_service_queue',
					'parsed.intent_family' => 'customer_service_review',
				],
			],
			[
				'name'    => 'Vietnamese pending orders this week searches orders',
				'command' => 'Tuần này có đơn nào đang pending không?',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'status' => [ 'pending' ], 'date_window' => 'this_week', 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'             => 'orders.search',
					'parsed.params.status.0'    => 'pending',
					'parsed.params.date_window' => 'this_week',
					'parsed.command_shape.date_filter.window_key' => 'this_week',
				],
			],
			[
				'name'    => 'Vietnamese completed recent orders preserves semantic status',
				'command' => 'Cho tôi xem 5 đơn hàng đã hoàn thành gần đây',
				'fixture' => self::fixture(
					'task_request',
					'orders.search',
					[ 'limit' => 5, 'date_window' => 'last_7_days' ],
					0.9,
					[],
					'',
					[],
					[
						'language' => 'vi',
						'semantic_frame' => [
							'version' => 1,
							'surface' => 'orders',
							'operation' => 'search',
							'target_type' => 'order_list',
							'constraints' => [
								'quantity' => 5,
								'status' => 'completed',
								'date' => [
									'window' => 'last_7_days',
									'operator' => null,
									'value' => null,
									'unit' => null,
									'text' => 'gần đây',
								],
								'money' => [
									'operator' => null,
									'value' => null,
									'min' => null,
									'max' => null,
									'currency' => null,
									'text' => null,
								],
								'customer_identity' => [
									'type' => null,
									'value' => null,
								],
								'payment_method' => null,
								'shipping_state' => null,
							],
							'evidence' => [
								'surface' => 'đơn hàng',
								'operation' => 'xem',
								'target' => 'đơn hàng',
								'quantity' => '5',
								'status' => 'đã hoàn thành',
								'date' => 'gần đây',
								'money' => null,
								'customer' => null,
								'payment' => null,
								'shipping' => null,
							],
							'confidence' => 0.9,
						],
					]
				),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.limit'                        => 5,
					'parsed.params.status.0'                     => 'completed',
					'parsed.params.date_window'                  => 'last_7_days',
					'parsed.semantic_frame.surface'              => 'orders',
					'parsed.semantic_frame.operation'            => 'search',
					'parsed.semantic_frame.constraints.quantity'  => 5,
					'parsed.semantic_frame.constraints.status'    => 'completed',
					'parsed.semantic_frame.constraints.date.window' => 'last_7_days',
				],
			],
			[
				'name'    => 'Semantic frame routes Vietnamese single order read to details',
				'command' => 'Xem chi tiết đơn hàng 6847',
				'fixture' => self::fixture(
					'task_request',
					'orders.search',
					[ 'limit' => 10 ],
					0.88,
					[],
					'',
					[],
					[
						'language' => 'vi',
						'semantic_frame' => [
							'version' => 1,
							'surface' => 'orders',
							'operation' => 'details',
							'target_type' => 'single_order',
							'constraints' => [
								'quantity' => null,
								'order_id' => 6847,
								'order_ids' => [ 6847 ],
								'status' => null,
								'date' => [
									'window' => null,
									'operator' => null,
									'value' => null,
									'unit' => null,
									'text' => null,
								],
								'money' => [
									'operator' => null,
									'value' => null,
									'min' => null,
									'max' => null,
									'currency' => null,
									'text' => null,
								],
								'customer_identity' => [
									'type' => null,
									'value' => null,
								],
								'payment_method' => null,
								'shipping_state' => null,
							],
							'evidence' => [
								'surface' => 'đơn hàng',
								'operation' => 'chi tiết',
								'target' => 'đơn hàng 6847',
								'order_id' => '6847',
								'quantity' => null,
								'status' => null,
								'date' => null,
								'money' => null,
								'customer' => null,
								'payment' => null,
								'shipping' => null,
							],
							'confidence' => 0.88,
						],
					]
				),
				'expect'  => [
					'parsed.action'                                      => 'orders.get_details',
					'parsed.intent_family'                               => 'get_order_details',
					'parsed.params.order_ids.0'                          => 6847,
					'parsed.params.order_id'                             => 6847,
					'parsed.semantic_frame.constraints.order_ids.0'       => 6847,
					'parsed.semantic_frame_validator_changes.0.type'     => 'action_override',
					'parsed.semantic_frame_validator_changes.0.previous' => 'orders.search',
					'parsed.semantic_frame_validator_changes.1.param'    => 'order_ids',
				],
			],
			[
				'name'    => 'Semantic frame repairs conflicting Vietnamese order search params',
				'command' => 'Cho tôi xem 5 đơn hàng đã hoàn thành gần đây trên $300',
				'fixture' => self::fixture(
					'task_request',
					'orders.search',
					[
						'limit'       => 10,
						'status'      => [ 'processing' ],
						'date_window' => 'this_month',
						'total_min'   => 100,
					],
					0.9,
					[],
					'',
					[],
					[
						'language' => 'vi',
						'semantic_frame' => [
							'version' => 1,
							'surface' => 'orders',
							'operation' => 'search',
							'target_type' => 'order_list',
							'constraints' => [
								'quantity' => 5,
								'status' => 'completed',
								'date' => [
									'window' => 'last_7_days',
									'operator' => null,
									'value' => null,
									'unit' => null,
									'text' => 'gần đây',
								],
								'money' => [
									'operator' => 'above',
									'value' => 300,
									'min' => null,
									'max' => null,
									'currency' => 'USD',
									'text' => 'trên $300',
								],
								'customer_identity' => [
									'type' => null,
									'value' => null,
								],
								'payment_method' => null,
								'shipping_state' => null,
							],
							'evidence' => [
								'surface' => 'đơn hàng',
								'operation' => 'xem',
								'target' => 'đơn hàng',
								'quantity' => '5',
								'status' => 'đã hoàn thành',
								'date' => 'gần đây',
								'money' => 'trên $300',
								'customer' => null,
								'payment' => null,
								'shipping' => null,
							],
							'confidence' => 0.9,
						],
					]
				),
				'expect'  => [
					'parsed.action'                                      => 'orders.search',
					'parsed.params.limit'                                => 5,
					'parsed.params.status.0'                             => 'completed',
					'parsed.params.date_window'                          => 'last_7_days',
					'parsed.params.total_min'                            => 300.0,
					'parsed.semantic_frame_validator_changes.0.param'    => 'status',
					'parsed.semantic_frame_validator_changes.1.param'    => 'limit',
					'parsed.semantic_frame_validator_changes.2.param'    => 'date_window',
					'parsed.semantic_frame_validator_changes.3.param'    => 'total_min',
				],
			],
			[
				'name'    => 'Pending orders this week carries date filter shape',
				'command' => 'Show pending orders this week',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'status' => [ 'pending' ], 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.status.0'                     => 'pending',
					'parsed.params.date_window'                  => 'this_week',
						'parsed.summary'                             => 'Here are 10 pending orders this week.',
					'parsed.command_shape.date_filter.window_key' => 'this_week',
				],
			],
			[
				'name'    => 'Orders from yesterday carries date filter shape',
				'command' => 'Show orders from yesterday',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.date_window'                  => 'yesterday',
					'parsed.command_shape.date_filter.window_key' => 'yesterday',
				],
			],
			[
				'name'    => 'Completed orders last 7 days carries date filter shape',
				'command' => 'Show completed orders in the last 7 days',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'status' => [ 'completed' ], 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.status.0'                     => 'completed',
					'parsed.params.limit'                        => 10,
					'parsed.params.date_window'                  => 'last_7_days',
					'parsed.command_shape.date_filter.window_key' => 'last_7_days',
				],
			],
			[
				'name'    => 'Orders over amount carries money filter shape',
				'command' => 'Show orders over $200',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.total_min'                    => 200.0,
						'parsed.summary'                             => 'Here are the latest 10 orders over $200.',
					'parsed.command_shape.money_filter.operator' => 'above',
					'parsed.command_shape.money_filter.value'    => 200.0,
				],
			],
			[
				'name'    => 'Newest high value orders scans beyond first page limit',
				'command' => 'Show me 3 newest orders over $300',
				'mode'    => 'execute',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 3, 'total_min' => 300 ], 0.9 ),
				'expect'  => [
					'parsed.action'                    => 'orders.search',
					'parsed.params.limit'              => 3,
					'parsed.params.total_min'          => 300.0,
					'result.display_type'              => 'orders_list',
					'result.count'                     => 3,
					'result.post_filter_scan.applied'  => true,
					'result.post_filter_scan.filters.0'=> 'order_total',
				],
			],
			[
				'name'    => 'No matching order search returns empty state',
				'command' => 'Show me 3 newest orders over $999999',
				'mode'    => 'execute',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 3, 'total_min' => 999999 ], 0.9 ),
				'expect'  => [
					'parsed.action'                  => 'orders.search',
					'parsed.params.limit'            => 3,
					'parsed.params.total_min'        => 999999.0,
					'result.display_type'            => 'orders_list',
					'result.count'                   => 0,
					'result.orders'                  => [],
					'result.empty_state.title'       => 'No matching orders found.',
					'result.empty_state.actions.0.command' => 'Show latest 5 orders',
				],
			],
			[
				'name'    => 'Orders under amount carries money filter shape',
				'command' => 'Show orders under 100 USD',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.total_max'                    => 100.0,
					'parsed.command_shape.money_filter.operator' => 'below',
					'parsed.command_shape.money_filter.value'    => 100.0,
				],
			],
			[
				'name'    => 'Orders between amounts carries money filter shape',
				'command' => 'Show orders between $50 and $150',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.total_min'                    => 50.0,
					'parsed.params.total_max'                    => 150.0,
					'parsed.command_shape.money_filter.operator' => 'between',
					'parsed.command_shape.money_filter.min'      => 50.0,
					'parsed.command_shape.money_filter.max'      => 150.0,
				],
			],
			[
				'name'    => 'Orders for email carries customer identity shape',
				'command' => 'Show orders for jane89@mail.com',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                                => 'orders.search',
					'parsed.params.email'                          => 'jane89@mail.com',
					'parsed.command_shape.customer_identity.type'   => 'email',
					'parsed.command_shape.customer_identity.email'  => 'jane89@mail.com',
				],
			],
			[
				'name'    => 'Orders for phone carries customer identity shape',
				'command' => 'Show orders for phone +1245456565',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                                => 'orders.search',
					'parsed.params.phone'                          => '+1245456565',
					'parsed.command_shape.customer_identity.type'  => 'phone',
					'parsed.command_shape.customer_identity.phone' => '+1245456565',
				],
			],
			[
				'name'    => 'Orders for customer id carries customer identity shape',
				'command' => 'Show orders for customer id 168',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                                      => 'orders.search',
					'parsed.params.customer_id'                          => 168,
					'parsed.command_shape.customer_identity.type'         => 'customer_id',
					'parsed.command_shape.customer_identity.customer_id'  => 168,
				],
			],
			[
				'name'    => 'Orders for customer name carries customer identity shape',
				'command' => 'Show orders for Jane Doe',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                                         => 'orders.search',
					'parsed.params.customer_name'                           => 'Jane Doe',
					'parsed.command_shape.customer_identity.type'            => 'customer_name',
					'parsed.command_shape.customer_identity.customer_name'   => 'Jane Doe',
				],
			],
			[
				'name'    => 'COD orders carry payment method shape',
				'command' => 'Show COD orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                               => 'orders.search',
					'parsed.params.payment_method'                => 'cod',
						'parsed.summary'                              => 'Here are the latest 10 orders paid by COD.',
					'parsed.command_shape.payment_method.method'  => 'cod',
				],
			],
			[
				'name'    => 'Stripe orders carry payment method shape',
				'command' => 'Show Stripe orders',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                               => 'orders.search',
					'parsed.params.payment_method'                => 'stripe',
					'parsed.command_shape.payment_method.method'  => 'stripe',
				],
			],
			[
				'name'    => 'Orders paid by PayPal carry payment method shape',
				'command' => 'Show orders paid by PayPal',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                               => 'orders.search',
					'parsed.params.payment_method'                => 'paypal',
					'parsed.command_shape.payment_method.method'  => 'paypal',
				],
			],
			[
				'name'    => 'Orders shipping to Oregon carry shipping state shape',
				'command' => 'Show orders shipping to Oregon',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.shipping_state'               => 'OR',
						'parsed.summary'                             => 'Here are the latest 10 orders shipping to OR.',
					'parsed.command_shape.shipping_state.state'   => 'OR',
				],
			],
			[
				'name'    => 'Shipping state abbreviation carries shipping state shape',
				'command' => 'Show orders shipping state OR',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.shipping_state'               => 'OR',
					'parsed.command_shape.shipping_state.state'   => 'OR',
				],
			],
			[
				'name'    => 'Orders in state abbreviation carries shipping state shape',
				'command' => 'Show orders in CA',
				'fixture' => self::fixture( 'task_request', 'orders.search', [ 'limit' => 10 ], 0.9 ),
				'expect'  => [
					'parsed.action'                              => 'orders.search',
					'parsed.params.shipping_state'               => 'CA',
					'parsed.command_shape.shipping_state.state'   => 'CA',
				],
			],
			[
				'name'    => 'Vietnamese operations overview routes to operations summary',
				'command' => 'Tình hình vận hành hôm nay thế nào?',
				'fixture' => self::fixture( 'task_request', 'operations.get_summary', [ 'window' => 'today', 'focus' => 'overview' ], 0.9 ),
				'expect'  => [
					'parsed.action'        => 'operations.get_summary',
					'parsed.params.window' => 'today',
				],
			],
		];
	}

	private static function run_case( $case ) {
		$case    = is_array( $case ) ? $case : [];
		$command = sanitize_text_field( $case['command'] ?? '' );
		$context = isset( $case['context'] ) && is_array( $case['context'] ) ? $case['context'] : [];
		$mode    = sanitize_key( $case['mode'] ?? 'parse' );
		$actual  = [
			'parsed' => null,
			'plan'   => null,
			'result' => null,
		];

		$parsed = YoRo_Parser_Engine::parse( $command, $context );
		$plan   = null;
		$result = null;

		if ( is_array( $parsed ) && ! empty( $parsed['success'] ) && ! empty( $parsed['matched'] ) && ! empty( $parsed['action'] ) ) {
			$plan = YoRo_Action_Planner::build_plan( $parsed );

			if ( 'execute' === $mode && ! is_wp_error( $plan ) ) {
				self::ensure_capable_user();
				$result = YoRo_Plan_Executor::execute( $plan, false, false );
			}
		}

		$actual['parsed'] = self::normalize_value( $parsed );
		$actual['plan']   = self::normalize_value( $plan );
		$actual['result'] = self::normalize_value( $result );

		$assertions = self::build_assertions(
			$actual,
			$case['expect'] ?? [],
			$case['expect_contains'] ?? [],
			self::merge_expect_not_contains(
				self::default_user_facing_copy_exclusions(),
				$case['expect_not_contains'] ?? []
			)
		);
		$passed     = self::assertions_passed( $assertions );
		$output     = [
			'name'       => sanitize_text_field( $case['name'] ?? $command ),
			'command'    => $command,
			'passed'     => $passed,
			'assertions' => $assertions,
			'actual'     => $actual,
		];

		$output['trace_review'] = class_exists( 'YoRo_Trace_Review_Formatter' )
			? YoRo_Trace_Review_Formatter::format_eval_case( $output )
			: '';

		return $output;
	}

	private static function enable_router_fixtures( $cases ) {
		self::$fixture_map = self::build_fixture_map( $cases );
		add_filter( 'yoro_ai_command_router_mock_decision', [ __CLASS__, 'mock_command_router_decision' ], 10, 3 );
	}

	private static function disable_router_fixtures() {
		remove_filter( 'yoro_ai_command_router_mock_decision', [ __CLASS__, 'mock_command_router_decision' ], 10 );
		self::$fixture_map = null;
	}

	private static function build_fixture_map( $cases ) {
		$map = [];

		foreach ( is_array( $cases ) ? $cases : [] as $case ) {
			if ( empty( $case['command'] ) || empty( $case['fixture'] ) || ! is_array( $case['fixture'] ) ) {
				continue;
			}

			$map[ self::fixture_key( $case['command'] ) ] = $case['fixture'];
		}

		return $map;
	}

	private static function fixture_key( $command ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $command ) ) );
	}

	private static function fixture( $mode, $action, $params = [], $confidence = 0.9, $missing = [], $clarification_question = '', $actions = [], $overrides = [] ) {
		$intent_family_map = [
			'multi.query_and_apply'               => 'query_and_apply',
			'orders.search'                       => 'search_orders',
			'orders.get_details'                  => 'get_order_details',
			'orders.update_status'                => 'update_order_status',
			'orders.hold'                         => 'hold_order',
			'orders.add_note'                     => 'add_note',
			'orders.compose_customer_email'       => 'customer_email',
			'store.get_business_summary'          => 'business_summary',
			'operations.get_summary'              => 'operations_overview',
			'operations.get_attention_queue'      => 'operations_attention',
			'operations.get_fulfillment_queue'    => 'fulfillment_review',
			'products.get_inventory_queue'        => 'inventory_review',
			'customers.get_service_queue'         => 'customer_service_review',
		];

		$fixture = [
			'mode'                   => sanitize_key( $mode ),
			'language'               => 'en',
			'intent_family'          => sanitize_key( $intent_family_map[ $action ] ?? '' ),
			'action'                 => sanitize_text_field( $action ),
			'params'                 => is_array( $params ) ? $params : [],
			'confidence'             => (float) $confidence,
			'missing'                => is_array( $missing ) ? $missing : [],
			'ambiguities'            => [],
			'clarification_question' => sanitize_text_field( $clarification_question ),
			'summary'                => 'Real command eval fixture decision.',
			'reasoning_summary'      => 'Deterministic fixture for real-command eval coverage.',
		];

		if ( ! empty( $actions ) && is_array( $actions ) ) {
			$fixture['actions'] = $actions;
		}

		return array_merge( $fixture, is_array( $overrides ) ? $overrides : [] );
	}

	private static function ensure_capable_user() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$users = get_users(
			[
				'role__in' => [ 'administrator', 'shop_manager' ],
				'number'   => 1,
				'fields'   => [ 'ID' ],
			]
		);

		if ( empty( $users ) || empty( $users[0]->ID ) ) {
			return;
		}

		wp_set_current_user( absint( $users[0]->ID ) );
	}

	private static function build_assertions( $actual, $expect, $expect_contains = [], $expect_not_contains = [] ) {
		$assertions = [];

		foreach ( is_array( $expect ) ? $expect : [] as $path => $expected ) {
			$actual_value = self::get_path( $actual, (string) $path );

			$assertions[ $path ] = [
				'expected' => $expected,
				'actual'   => $actual_value,
				'passed'   => self::values_equal( $expected, $actual_value ),
			];
		}

		foreach ( is_array( $expect_contains ) ? $expect_contains : [] as $path => $needles ) {
			$actual_value = self::get_path( $actual, (string) $path, '' );

			foreach ( self::normalize_needles( $needles ) as $needle ) {
				$key = $path . ' contains "' . $needle . '"';
				$assertions[ $key ] = [
					'expected' => $needle,
					'actual'   => $actual_value,
					'passed'   => self::string_contains( $actual_value, $needle ),
				];
			}
		}

		foreach ( is_array( $expect_not_contains ) ? $expect_not_contains : [] as $path => $needles ) {
			$actual_value = self::get_path( $actual, (string) $path, '' );

			foreach ( self::normalize_needles( $needles ) as $needle ) {
				$key = $path . ' excludes "' . $needle . '"';
				$assertions[ $key ] = [
					'expected' => 'not ' . $needle,
					'actual'   => $actual_value,
					'passed'   => ! self::string_contains( $actual_value, $needle ),
				];
			}
		}

		return $assertions;
	}

	private static function default_user_facing_copy_exclusions() {
		$needles = [
			'Action failed',
			'AI parser',
			'AI selected',
			'AI-interpreted',
			'Confirmation required',
			'Decision route',
			'If you want',
			'third-party API key',
			'The user wants',
			'User asked',
			'Workflow completed with errors',
			'Workflow step failed',
			'Workflow stopped after a failed step',
			'You query indicates',
			'[object Object]',
			'invalid JSON',
			'is stale',
			'preview is stale',
		];

		$paths = [
			'parsed.message',
			'parsed.summary',
			'parsed.reasoning_summary',
			'parsed.clarification_question',
			'parsed.result.summary',
			'parsed.result.message',
			'parsed.result.assistant_summary',
			'parsed.result.assistant_followup',
			'result.summary',
			'result.message',
			'result.assistant_summary',
			'result.assistant_followup',
		];

		$exclusions = [];

		foreach ( $paths as $path ) {
			$exclusions[ $path ] = $needles;
		}

		return $exclusions;
	}

	private static function merge_expect_not_contains( $base, $extra ) {
		$output = is_array( $base ) ? $base : [];

		foreach ( is_array( $extra ) ? $extra : [] as $path => $needles ) {
			$existing = isset( $output[ $path ] ) ? self::normalize_needles( $output[ $path ] ) : [];
			$output[ $path ] = array_values(
				array_unique(
					array_merge(
						$existing,
						self::normalize_needles( $needles )
					)
				)
			);
		}

		return $output;
	}

	private static function normalize_needles( $needles ) {
		$needles = is_array( $needles ) ? $needles : [ $needles ];

		return array_values(
			array_filter(
				array_map(
					static function( $needle ) {
						return trim( (string) $needle );
					},
					$needles
				),
				static function( $needle ) {
					return '' !== $needle;
				}
			)
		);
	}

	private static function string_contains( $actual, $needle ) {
		if ( is_array( $actual ) || is_object( $actual ) ) {
			$actual = wp_json_encode( $actual );
		}

		return false !== strpos( (string) $actual, (string) $needle );
	}

	private static function get_path( $source, $path, $default = null ) {
		$cursor = $source;

		foreach ( explode( '.', (string) $path ) as $part ) {
			if ( is_array( $cursor ) && array_key_exists( $part, $cursor ) ) {
				$cursor = $cursor[ $part ];
				continue;
			}

			if ( is_array( $cursor ) && ctype_digit( $part ) && array_key_exists( (int) $part, $cursor ) ) {
				$cursor = $cursor[ (int) $part ];
				continue;
			}

			return $default;
		}

		return $cursor;
	}

	private static function values_equal( $expected, $actual ) {
		if ( is_int( $expected ) || is_float( $expected ) ) {
			return (float) $expected === (float) $actual;
		}

		if ( is_bool( $expected ) ) {
			return (bool) $expected === (bool) $actual;
		}

		return $expected === $actual;
	}

	private static function assertions_passed( $assertions ) {
		if ( empty( $assertions ) ) {
			return false;
		}

		foreach ( $assertions as $assertion ) {
			if ( empty( $assertion['passed'] ) ) {
				return false;
			}
		}

		return true;
	}

	private static function normalize_value( $value ) {
		if ( is_wp_error( $value ) ) {
			return [
				'success' => false,
				'error'   => [
					'code'    => $value->get_error_code(),
					'message' => $value->get_error_message(),
					'data'    => $value->get_error_data(),
				],
			];
		}

		return $value;
	}
}
