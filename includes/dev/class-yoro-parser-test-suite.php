<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Test_Suite {

	private static $action_decomposition_fixture = false;

	private static $router_fixture_map = null;

	public static function run() {
		$group_runs = [
			'parser'   => self::run_parser_tests(),
			'pipeline' => self::run_pipeline_tests(),
			'real_command_eval' => self::run_real_command_eval_tests(),
			'evaluation' => self::run_evaluation_tests(),
			'hardening' => self::run_hardening_tests(),
			'planner'  => self::run_planner_tests(),
			'preview'  => self::run_preview_tests(),
			'execute'  => self::run_execute_tests(),
			'undo'     => self::run_undo_tests(),
		];

		$summary = [
			'total'  => 0,
			'passed' => 0,
			'failed' => 0,
			'groups' => [],
		];

		$groups     = [];
		$group_meta = [];

		foreach ( $group_runs as $group_name => $group_data ) {
			$results = isset( $group_data['results'] ) && is_array( $group_data['results'] )
				? $group_data['results']
				: $group_data;
			$total  = count( $results );
			$passed = count(
				array_filter(
					$results,
					function( $item ) {
						return ! empty( $item['passed'] );
					}
				)
			);

			$summary['groups'][ $group_name ] = [
				'total'  => $total,
				'passed' => $passed,
				'failed' => $total - $passed,
			];

			$summary['total']  += $total;
			$summary['passed'] += $passed;
			$summary['failed'] += ( $total - $passed );

			$groups[ $group_name ] = $results;

			if ( isset( $group_data['summary'] ) && is_array( $group_data['summary'] ) ) {
				$group_meta[ $group_name ] = $group_data['summary'];
			}
		}

		return [
			'success'    => 0 === $summary['failed'],
			'summary'    => $summary,
			'groups'     => $groups,
			'group_meta' => $group_meta,
		];
	}

	public static function run_parser_tests() {
		$results = [];

		self::enable_router_fixtures( self::parser_cases() );

		try {
			foreach ( self::parser_cases() as $test ) {
				$results[] = self::run_parser_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
		}

		return $results;
	}

	public static function mock_command_router_decision( $decision, $command, $context ) {
		if ( null === self::$router_fixture_map ) {
			self::$router_fixture_map = self::build_router_fixture_map();
		}

		$key = self::router_fixture_key( $command );

		if ( isset( self::$router_fixture_map[ $key ] ) ) {
			return self::$router_fixture_map[ $key ];
		}

		$dynamic_fixture = self::build_dynamic_router_fixture_decision( $command );

		return null !== $dynamic_fixture ? $dynamic_fixture : $decision;
	}

	private static function enable_router_fixtures( $cases = null ) {
		self::$router_fixture_map = self::build_router_fixture_map( $cases );
		self::$action_decomposition_fixture = false;
		add_filter(
			'yoro_ai_command_router_mock_decision',
			[ __CLASS__, 'mock_command_router_decision' ],
			10,
			3
		);
		add_filter(
			'yoro_ai_action_decomposition_mock_result',
			[ __CLASS__, 'mock_action_decomposition_result' ],
			10,
			3
		);
	}

	private static function disable_router_fixtures() {
		remove_filter(
			'yoro_ai_command_router_mock_decision',
			[ __CLASS__, 'mock_command_router_decision' ],
			10
		);
		remove_filter(
			'yoro_ai_action_decomposition_mock_result',
			[ __CLASS__, 'mock_action_decomposition_result' ],
			10
		);
		self::$router_fixture_map = null;
		self::$action_decomposition_fixture = false;
	}

	public static function mock_action_decomposition_result( $result, $command, $context ) {
		return self::$action_decomposition_fixture;
	}

	private static function build_router_fixture_map( $cases = null ) {
		$map = [];
		$cases = is_array( $cases ) ? $cases : self::parser_cases();

		foreach ( $cases as $test ) {
			if ( empty( $test['command'] ) ) {
				continue;
			}

			$key = self::router_fixture_key( $test['command'] );
			$fixture = self::build_router_fixture_decision_for_case( $test );

			if (
				isset( $map[ $key ] ) &&
				self::router_fixture_has_params( $map[ $key ] ) &&
				! self::router_fixture_has_params( $fixture )
			) {
				continue;
			}

			$map[ $key ] = $fixture;
		}

		foreach ( self::router_fixture_command_overrides() as $command => $fixture ) {
			$map[ self::router_fixture_key( $command ) ] = $fixture;
		}

		return $map;
	}

	private static function router_fixture_key( $command ) {
		return strtolower( trim( (string) $command ) );
	}

	private static function build_dynamic_router_fixture_decision( $command ) {
		$command = trim( (string) $command );

		if ( '' === $command ) {
			return null;
		}

		if (
			preg_match( '/\bhold\s+order\s+#?\d+\b/i', $command ) &&
			preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $command )
		) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'AI router fixture intentionally falls back to deterministic guardrails for multi-step order tests.', 'yoro-ai-core' )
			);
		}

		$email_workflow_fixture = self::build_dynamic_email_workflow_fixture( $command );

		if ( is_array( $email_workflow_fixture ) ) {
			return $email_workflow_fixture;
		}

		if (
			(
				preg_match( '/\breview\b/i', $command ) &&
				preg_match( '/\brefund\b/i', $command )
			) ||
			(
				preg_match( '/\bhold\s+order\s+#?\d+\b/i', $command ) &&
				preg_match( '/\bverify\b/i', $command ) &&
				preg_match( '/\b(?:email|address|shipping)\b/i', $command )
			)
		) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'AI router fixture intentionally falls back to deterministic workflow guardrails for workflow branch tests.', 'yoro-ai-core' )
			);
		}

		if ( preg_match( '/\bmark\s+order\s+#?(\d+)\s+as\s+([a-z0-9_-]+)/i', $command, $matches ) ) {
			$order_id = absint( $matches[1] ?? 0 );
			$status = sanitize_key( $matches[2] ?? '' );

			if ( $order_id && '' !== $status ) {
				return self::base_router_fixture_decision(
					'task_request',
					'orders.update_status',
					[
						'order_id'  => $order_id,
						'order_ids' => [ $order_id ],
						'status'    => $status,
					],
					0.95
				);
			}
		}

		if ( preg_match( '/\badd\s+note\s+to\s+order\s+#?(\d+)\s*:?\s*(.+)$/i', $command, $matches ) ) {
			$order_id = absint( $matches[1] ?? 0 );
			$note = sanitize_textarea_field( trim( $matches[2] ?? '' ) );

			if ( $order_id && '' !== $note ) {
				return self::base_router_fixture_decision(
					'task_request',
					'orders.add_note',
					[
						'order_id'  => $order_id,
						'order_ids' => [ $order_id ],
						'note'      => $note,
					],
					0.95
				);
			}
		}

		return null;
	}

	private static function build_dynamic_email_workflow_fixture( $command ) {
		$command = trim( (string) $command );

		if (
			'' === $command ||
			! preg_match( '/\bwrite\b/i', $command ) ||
			! preg_match( '/\bemail\b/i', $command ) ||
			! preg_match( '/\border\s+#?(\d+)\b/i', $command, $matches )
		) {
			return null;
		}

		$order_id = absint( $matches[1] ?? 0 );

		if ( ! $order_id ) {
			return null;
		}

		$normalized = strtolower( $command );
		$action = '';
		$delivery_mode = '';

		if ( preg_match( '/\b(?:save\s+as\s+draft|save\s+(?:the\s+)?(?:email\s+)?(?:as\s+)?draft|draft)\b/i', $normalized ) ) {
			$action = 'workflow.write_and_save_email_draft_for_order';
			$delivery_mode = 'save_draft';
		} elseif ( preg_match( '/\b(?:send\s+it|and\s+send|send\s+(?:the\s+)?email)\b/i', $normalized ) ) {
			$action = 'workflow.write_and_send_email_for_order';
			$delivery_mode = 'send';
		}

		if ( '' === $action ) {
			return null;
		}

		$instruction = preg_replace( '/^\s*write\s+(?:a\s+)?/i', '', $command );
		$instruction = preg_replace( '/\bemail\s+for\s+order\s+#?\d+\b/i', 'email', (string) $instruction );
		$instruction = preg_replace( '/\b(?:and\s+)?(?:save\s+as\s+draft|save\s+(?:the\s+)?(?:email\s+)?(?:as\s+)?draft|send\s+it|and\s+send|send\s+(?:the\s+)?email)\b.*$/i', '', (string) $instruction );
		$instruction = trim( preg_replace( '/\s+/', ' ', (string) $instruction ) );

		return self::base_router_fixture_decision(
			'task_request',
			$action,
			[
				'order_id'      => $order_id,
				'order_ids'     => [ $order_id ],
				'instruction'   => '' !== $instruction ? $instruction : 'customer follow-up',
				'delivery_mode' => $delivery_mode,
			],
			0.95
		);
	}

	private static function router_fixture_has_params( $fixture ) {
		return is_array( $fixture ) && ! empty( $fixture['params'] ) && is_array( $fixture['params'] );
	}

	private static function build_router_fixture_decision_for_case( $test ) {
		if ( isset( $test['router_fixture'] ) ) {
			return is_callable( $test['router_fixture'] )
				? call_user_func( $test['router_fixture'], $test )
				: $test['router_fixture'];
		}

		if ( self::should_router_fixture_use_deterministic_fallback( $test ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
			);
		}

		$expectations = isset( $test['expect'] ) && is_array( $test['expect'] )
			? $test['expect']
			: [];
		$action = isset( $test['expected_action'] ) ? sanitize_text_field( $test['expected_action'] ) : '';

		if ( '' === $action && ! empty( $expectations['parsed.action'] ) ) {
			$action = sanitize_text_field( $expectations['parsed.action'] );
		}

		if ( '' === $action && ! empty( $expectations['action'] ) ) {
			$action = sanitize_text_field( $expectations['action'] );
		}

		$params = self::extract_router_fixture_params( $test['expect'] ?? [] );
		$params = self::enrich_router_fixture_params_from_command( $action, $params, $test['command'] ?? '' );
		$params = self::enrich_router_fixture_params_from_context( $action, $params, $test['context'] ?? [] );
		$stage = sanitize_key( $test['expect_stage'] ?? '' );

		if ( empty( $action ) || ( 'clarify' === $stage && ! empty( $test['expect_unmatched'] ) ) ) {
			return self::base_router_fixture_decision(
				'clarify',
				'',
				[],
				0.9,
				[ 'details' ],
				__( 'I need a little more detail before I can run that action.', 'yoro-ai-core' )
			);
		}

		return self::base_router_fixture_decision(
			'task_request',
			$action,
			$params,
			0.95,
			[],
			''
		);
	}

	private static function should_router_fixture_use_deterministic_fallback( $test ) {
		if ( 'rule' === sanitize_key( $test['expected_source'] ?? '' ) ) {
			return true;
		}

		$expectations = isset( $test['expect'] ) && is_array( $test['expect'] )
			? $test['expect']
			: [];
		$expected_action = sanitize_text_field( $test['expected_action'] ?? ( $expectations['parsed.action'] ?? ( $expectations['action'] ?? '' ) ) );

		if ( 0 === strpos( $expected_action, 'multi.' ) ) {
			return true;
		}

		foreach ( $expectations as $path => $expected ) {
			$path = (string) $path;

			if ( 'parser_rule' === $path && is_string( $expected ) && 0 === strpos( $expected, 'rule.' ) ) {
				return true;
			}

			if ( 'parsed.action' === $path && ( ! empty( $test['expect_unmatched'] ) || 'clarify' === sanitize_key( $test['expect_stage'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function enrich_router_fixture_params_from_context( $action, $params, $context ) {
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		if ( 'orders.send_customer_email' !== $action || empty( $context['current_workflow'] ) || ! is_array( $context['current_workflow'] ) ) {
			return $params;
		}

		$workflow = $context['current_workflow'];

		foreach ( [ 'order_id', 'subject', 'body', 'draft_id' ] as $key ) {
			if ( isset( $workflow[ $key ] ) && ! isset( $params[ $key ] ) ) {
				$params[ $key ] = $workflow[ $key ];
			}
		}

		return $params;
	}

	private static function enrich_router_fixture_params_from_command( $action, $params, $command ) {
		if ( ! is_array( $params ) ) {
			$params = [];
		}

		if ( 'store.get_daily_briefing' !== $action ) {
			return $params;
		}

		$normalized = strtolower( trim( (string) $command ) );

		if ( false !== strpos( $normalized, 'this month' ) ) {
			$params['window'] = 'this_month';
		} elseif ( false !== strpos( $normalized, 'monthly' ) ) {
			$params['window'] = 'last_30_days';
		} elseif ( false !== strpos( $normalized, 'this week' ) ) {
			$params['window'] = 'this_week';
		} elseif ( false !== strpos( $normalized, 'weekly' ) ) {
			$params['window'] = 'last_7_days';
		} elseif ( ! isset( $params['window'] ) ) {
			$params['window'] = 'today';
		}

		return $params;
	}

	private static function router_fixture_command_overrides() {
		return [
			'Hello'                                       => array_merge(
				self::base_router_fixture_decision(
					'chat_only',
					'',
					[],
					0.95
				),
				[
					'summary' => 'Chat mode activated for casual conversation. I can help with WooCommerce store operations.',
				]
			),
			'Do you know who am I?'                      => array_merge(
				self::base_router_fixture_decision(
					'chat_only',
					'',
					[],
					0.95
				),
				[
					'summary' => 'User asked for identity information; no WooCommerce task.',
				]
			),
			'Can you give me a brief today?'             => self::base_router_fixture_decision(
				'task_request',
				'store.get_daily_briefing',
				[
					'window' => 'today',
					'limit'  => 1,
				],
				0.72
			),
			'Write an email for order 6848'              => self::base_router_fixture_decision(
				'clarify',
				'orders.compose_customer_email',
				[
					'order_id' => 6848,
				],
				0.82,
				[ 'instruction' ],
				'Please provide the instruction for the email body you want to compose for order #6848.'
			),
			'Write an email for order 6865'              => self::base_router_fixture_decision(
				'clarify',
				'orders.compose_customer_email',
				[
					'order_id' => 6865,
				],
				0.82,
				[ 'email_body' ],
				'What should the customer email be about?'
			),
			'Write me an email for order 6864 about shipping delay' => self::base_router_fixture_decision(
				'clarify',
				'orders.compose_customer_email',
				[
					'order_id' => 6864,
				],
				0.84,
				[ 'instruction' ],
				'What exact instruction should I use to compose the email for order 6864?'
			),
			'Write an email about shipping on the way for orders 7140, 7139, 7138' => array_merge(
				self::base_router_fixture_decision(
					'task_request',
					'workflow.write_email_for_orders',
					[
						'order_ids'       => [ 7140, 7139, 7138 ],
						'instruction'     => 'shipping on the way',
						'delivery_mode'   => 'preview',
					],
					0.88
				),
				[
					'evidence' => [
						'referenced_context' => [ 'recent_order_list' ],
					],
				]
			),
			'Write email about shipping on the way to them' => array_merge(
				self::base_router_fixture_decision(
					'task_request',
					'workflow.write_email_for_orders',
					[
						'order_ids'     => [ 7140, 7139, 7138 ],
						'instruction'   => 'about shipping on the way to them',
						'delivery_mode' => 'preview',
					],
					0.88
				),
				[
					'evidence' => [
						'referenced_context' => [ 'recent_order_list' ],
					],
				]
			),
			'Write email about shipping update for these six orders' => array_merge(
				self::base_router_fixture_decision(
					'task_request',
					'workflow.write_email_for_orders',
					[
						'order_ids'     => [ 7140, 7139, 7138, 7137, 7136, 7135 ],
						'instruction'   => 'shipping update',
						'delivery_mode' => 'preview',
					],
					0.88
				),
				[
					'evidence' => [
						'referenced_context' => [ 'recent_order_list' ],
					],
				]
			),
			'Add a note to order 6864'                   => self::base_router_fixture_decision(
				'clarify',
				'orders.add_note',
				[
					'order_ids' => [ 6864 ],
				],
				0.86,
				[ 'note' ],
				'Please provide the note text you want to add to order 6864.'
			),
			'Add a note to order 6865'                   => self::base_router_fixture_decision(
				'clarify',
				'orders.add_note',
				[
					'order_ids' => [ 6865 ],
				],
				0.86,
				[ 'note_text' ],
				'What note would you like to add to order 6865?'
			),
			'Update order 6865 status'                   => self::base_router_fixture_decision(
				'clarify',
				'orders.update_status',
				[
					'order_ids' => [ 6865 ],
				],
				0.86,
				[ 'order_status' ],
				'What status should I update order 6865 to?'
			),
			'Add note to this order: Check address'      => self::base_router_fixture_decision(
				'task_request',
				'orders.add_note',
				[
					'note' => 'Check address',
				],
				0.86
			),
			'Show pending orders'                         => self::base_router_fixture_decision(
				'task_request',
				'orders.search',
				[
					'status' => [ 'pending' ],
				],
				0.95
			),
			'Tuần này có đơn nào đang pending không?'    => array_merge(
				self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'status'      => [ 'pending' ],
						'date_window' => 'this_week',
					],
					0.95
				),
				[
					'language' => 'vi',
				]
			),
			'Search pending orders'                       => self::base_router_fixture_decision(
				'task_request',
				'orders.search',
				[
					'status' => [ 'pending' ],
				],
				0.95
			),
			'Show processing orders'                      => self::base_router_fixture_decision(
				'task_request',
				'orders.search',
				[
					'status' => [ 'processing' ],
				],
				0.95
			),
			'Show 5 processing orders'                    => self::base_router_fixture_decision(
				'task_request',
				'orders.search',
				[
					'limit'  => 5,
					'status' => [ 'processing' ],
				],
				0.95
			),
			'Show operations summary for today'           => self::base_router_fixture_decision(
				'task_request',
				'operations.get_summary',
				[
					'window' => 'today',
					'focus'  => 'overview',
				],
				0.95
			),
			'Show operations summary for this week'       => self::base_router_fixture_decision(
				'task_request',
				'operations.get_summary',
				[
					'window' => 'this_week',
					'focus'  => 'overview',
				],
				0.95
			),
			'Show morning briefing'                       => self::base_router_fixture_decision(
				'task_request',
				'store.get_morning_briefing',
				[],
				0.95
			),
			'Show today\'s briefing'                      => self::base_router_fixture_decision(
				'task_request',
				'store.get_daily_briefing',
				[
					'window' => 'today',
				],
				0.95
			),
			'What should we do today?'                    => self::base_router_fixture_decision(
				'task_request',
				'operations.get_summary',
				[
					'window' => 'today',
				],
				0.95
			),
			'Move order #123 to on hold'                  => self::base_router_fixture_decision(
				'task_request',
				'orders.update_status',
				[
					'order_ids' => [ 123 ],
					'status'    => 'on-hold',
				],
				0.95
			),
			'Mark order #123 as completed with note: packed and verified' => self::base_router_fixture_decision(
				'task_request',
				'orders.bulk_update_status_with_note',
				[
					'order_ids' => [ 123 ],
					'status'    => 'completed',
					'note'      => 'packed and verified',
				],
				0.95
			),
			'What seems blocked in fulfillment?'          => self::base_router_fixture_decision(
				'task_request',
				'operations.get_fulfillment_queue',
				[
					'limit' => 5,
				],
				0.95
			),
			'What should we restock first?'               => self::base_router_fixture_decision(
				'task_request',
				'products.get_inventory_queue',
				[
					'limit' => 5,
					'focus' => 'low_stock',
				],
				0.95
			),
			'Can you give me a monthly store picture?'    => self::base_router_fixture_decision(
				'task_request',
				'operations.get_summary',
				[
					'window' => 'last_30_days',
					'focus'  => 'overview',
				],
				0.95
			),
		];
	}

	private static function base_router_fixture_decision( $mode, $action, $params, $confidence, $missing = [], $clarification_question = '' ) {
		$intent_family_map = [
			'store.get_business_summary'      => 'business_summary',
			'store.get_morning_briefing'      => 'store_briefing',
			'store.get_daily_briefing'        => 'store_briefing',
			'operations.get_summary'          => 'operations_overview',
			'operations.get_attention_queue'  => 'operations_attention',
			'operations.get_fulfillment_queue'=> 'fulfillment_review',
			'products.get_inventory_queue'    => 'inventory_review',
			'customers.get_service_queue'     => 'customer_service_review',
			'orders.search'                   => 'search_orders',
			'orders.get_details'              => 'get_order_details',
			'orders.find_by_customer'         => 'find_customer_orders',
			'orders.update_status'            => 'update_order_status',
			'orders.bulk_update_status_with_note' => 'update_order_status',
			'orders.hold'                     => 'hold_order',
			'orders.add_note'                 => 'add_note',
			'orders.compose_customer_email'   => 'customer_email',
		];

		return [
			'mode'                   => $mode,
			'language'               => 'en',
			'intent_family'          => sanitize_key( $intent_family_map[ $action ] ?? '' ),
			'action'                 => $action,
			'params'                 => is_array( $params ) ? $params : [],
			'confidence'             => (float) $confidence,
			'missing'                => is_array( $missing ) ? $missing : [],
			'ambiguities'            => [],
			'evidence'               => [
				'matched_terms'       => [],
				'referenced_context'  => [],
				'notes'               => 'Parser test fixture.',
			],
			'clarification_question' => $clarification_question,
			'summary'                => 'Parser test fixture decision.',
			'reasoning_summary'      => 'Deterministic fixture for parser regression tests.',
			'suggestions'            => [],
		];
	}

	private static function multilingual_router_fixture( $language, $mode, $action, $params, $intent_family, $confidence, $missing = [], $clarification_question = '', $semantic_frame = [] ) {
		$decision = self::base_router_fixture_decision( $mode, $action, $params, $confidence, $missing, $clarification_question );
		$decision['language'] = sanitize_key( (string) $language );
		$decision['intent_family'] = sanitize_key( (string) $intent_family );

		if ( is_array( $semantic_frame ) && ! empty( $semantic_frame ) ) {
			$decision['semantic_frame'] = $semantic_frame;
		}

		return $decision;
	}

	private static function extract_router_fixture_params( $expectations ) {
		$params = [];

		if ( ! is_array( $expectations ) ) {
			return $params;
		}

		foreach ( $expectations as $path => $value ) {
			$path = (string) $path;

			if ( 0 !== strpos( $path, 'params.' ) ) {
				continue;
			}

			self::set_router_fixture_param_path(
				$params,
				substr( $path, 7 ),
				$value
			);
		}

		return $params;
	}

	private static function set_router_fixture_param_path( &$params, $path, $value ) {
		$parts = array_values( array_filter( explode( '.', (string) $path ), 'strlen' ) );

		if ( empty( $parts ) ) {
			return;
		}

		$cursor =& $params;

		foreach ( $parts as $index => $part ) {
			$is_last = ( count( $parts ) - 1 ) === $index;
			$key = ctype_digit( $part ) ? (int) $part : sanitize_key( $part );

			if ( $is_last ) {
				$cursor[ $key ] = $value;
				return;
			}

			if ( ! isset( $cursor[ $key ] ) || ! is_array( $cursor[ $key ] ) ) {
				$cursor[ $key ] = [];
			}

			$cursor =& $cursor[ $key ];
		}
	}

	public static function run_pipeline_tests() {
		$results = [];
		$cases   = self::pipeline_cases();

		self::enable_router_fixtures( array_merge( self::parser_cases(), $cases ) );

		try {
			foreach ( $cases as $test ) {
				$results[] = self::run_pipeline_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
		}

		return $results;
	}

	public static function run_real_command_eval_tests() {
		if ( ! class_exists( 'YoRo_Real_Command_Eval_Pack' ) ) {
			return [
				[
					'name'       => 'Real command eval pack unavailable',
					'passed'     => false,
					'assertions' => [
						'class_exists' => [
							'expected' => true,
							'actual'   => false,
							'passed'   => false,
						],
					],
				],
			];
		}

		$eval = YoRo_Real_Command_Eval_Pack::run();

		return isset( $eval['results'] ) && is_array( $eval['results'] )
			? $eval['results']
			: [];
	}

	public static function run_planner_tests() {
		$results = [];

		foreach ( self::planner_cases() as $test ) {
			$results[] = self::run_planner_case( $test );
		}

		return $results;
	}

	public static function run_preview_tests() {
		$results = [];
		$cases   = self::preview_cases();
		$previous_user_id = get_current_user_id();

		self::enable_router_fixtures( array_merge( self::parser_cases(), self::pipeline_cases(), $cases ) );
		self::set_test_admin_user();

		try {
			foreach ( $cases as $test ) {
				$results[] = self::run_preview_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
			wp_set_current_user( $previous_user_id );
		}

		return $results;
	}

	private static function set_test_admin_user() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$users = get_users(
			[
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			]
		);

		if ( ! empty( $users[0] ) ) {
			wp_set_current_user( absint( $users[0] ) );
		}
	}

	public static function run_execute_tests() {
		$results = [];
		$cases   = self::execution_cases();
		$previous_user_id = get_current_user_id();

		self::enable_router_fixtures( array_merge( self::parser_cases(), self::pipeline_cases(), self::preview_cases(), $cases ) );
		self::set_test_admin_user();

		try {
			foreach ( $cases as $test ) {
				$results[] = self::run_execute_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
			wp_set_current_user( $previous_user_id );
		}

		return $results;
	}

	public static function run_undo_tests() {
		$results = [];
		$cases   = self::undo_cases();
		$previous_user_id = get_current_user_id();

		self::enable_router_fixtures( array_merge( self::parser_cases(), self::pipeline_cases(), self::preview_cases(), self::execution_cases(), $cases ) );
		self::set_test_admin_user();

		try {
			foreach ( $cases as $test ) {
				$results[] = self::run_undo_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
			wp_set_current_user( $previous_user_id );
		}

		return $results;
	}

	public static function run_evaluation_tests( $pack = 'all' ) {
		$cases = self::get_evaluation_cases_for_pack( $pack );

		return self::run_evaluation_case_collection( $cases );
	}

	public static function run_real_traffic_eval_tests() {
		return self::run_evaluation_tests( 'real_traffic' );
	}

	public static function run_multilingual_eval_tests() {
		return self::run_evaluation_tests( 'multilingual_representative' );
	}

	public static function get_evaluation_pack_snapshot( $pack = 'all', $failed_limit = 8 ) {
		$group   = self::run_evaluation_tests( $pack );
		$results = isset( $group['results'] ) && is_array( $group['results'] ) ? $group['results'] : [];
		$summary = isset( $group['summary'] ) && is_array( $group['summary'] ) ? $group['summary'] : [];
		$failed  = [];

		foreach ( $results as $result ) {
			if ( ! empty( $result['passed'] ) ) {
				continue;
			}

			$failed[] = [
				'name'    => sanitize_text_field( $result['name'] ?? '' ),
				'command' => sanitize_text_field( $result['command'] ?? '' ),
				'domain'  => sanitize_key( $result['domain'] ?? 'general' ),
				'outcome' => sanitize_key( $result['outcome'] ?? 'unknown' ),
				'trace'   => isset( $result['trace'] ) && is_array( $result['trace'] ) ? $result['trace'] : [],
			];

			if ( count( $failed ) >= absint( $failed_limit ) ) {
				break;
			}
		}

		return [
			'pack'        => sanitize_key( (string) $pack ),
			'summary'     => $summary,
			'failed'      => $failed,
			'report_text' => self::format_evaluation_pack_snapshot_report( sanitize_key( (string) $pack ), $summary, $failed ),
		];
	}

	public static function run_hardening_tests() {
		return self::run_evaluation_case_collection( self::real_query_hardening_cases() );
	}

	private static function run_evaluation_case_collection( $cases ) {
		$results = [];
		$cases   = is_array( $cases ) ? array_values( $cases ) : [];

		self::enable_router_fixtures( $cases );

		try {
			foreach ( $cases as $test ) {
				$results[] = self::run_evaluation_case( $test );
			}
		} finally {
			self::disable_router_fixtures();
		}

		return [
			'results' => $results,
			'summary' => self::summarize_evaluation_results( $results ),
		];
	}

	private static function get_evaluation_cases_for_pack( $pack = 'all' ) {
		$all_cases = array_merge( self::evaluation_cases(), self::real_traffic_eval_cases(), self::multilingual_representative_eval_cases() );
		$pack      = sanitize_key( (string) $pack );

		if ( '' === $pack || 'all' === $pack ) {
			return $all_cases;
		}

		return array_values(
			array_filter(
				$all_cases,
				function( $test ) use ( $pack ) {
					return sanitize_key( $test['pack'] ?? 'baseline' ) === $pack;
				}
			)
		);
	}

	private static function format_evaluation_pack_snapshot_report( $pack, $summary, $failed ) {
		$pack    = sanitize_key( (string) $pack );
		$summary = is_array( $summary ) ? $summary : [];
		$failed  = is_array( $failed ) ? $failed : [];
		$lines   = [
			'Eval pack: ' . ( '' !== $pack ? $pack : 'all' ),
			'Summary: total=' . absint( $summary['total'] ?? 0 ) .
				' passed=' . absint( $summary['passed'] ?? 0 ) .
				' failed=' . absint( $summary['failed'] ?? 0 ) .
				' clarify_rate=' . sanitize_text_field( (string) ( $summary['clarify_rate'] ?? '0.0%' ) ) .
				' wrong_action_rate=' . sanitize_text_field( (string) ( $summary['wrong_action_rate'] ?? '0.0%' ) ) .
				' wrong_intent_rate=' . sanitize_text_field( (string) ( $summary['wrong_intent_rate'] ?? '0.0%' ) ),
			'Semantic frame: cases=' . absint( $summary['semantic_frame_cases'] ?? 0 ) .
				' repaired=' . absint( $summary['semantic_frame_repaired'] ?? 0 ) .
				' repair_rate=' . sanitize_text_field( (string) ( $summary['semantic_frame_repair_rate'] ?? '0.0%' ) ),
		];

		$language_lines = self::format_evaluation_language_bucket_lines( $summary['by_language'] ?? [] );

		if ( ! empty( $language_lines ) ) {
			$lines[] = 'Languages:';
			$lines = array_merge( $lines, $language_lines );
		}

		if ( empty( $failed ) ) {
			$lines[] = 'Failures: none';
		} else {
			$lines[] = 'Failures:';

			foreach ( $failed as $case ) {
				$trace = isset( $case['trace'] ) && is_array( $case['trace'] ) ? $case['trace'] : [];
				$lines[] = '- ' . sanitize_text_field( $case['name'] ?? '' ) .
					' [' . sanitize_key( $case['outcome'] ?? 'unknown' ) . ']' .
					' command="' . sanitize_text_field( $case['command'] ?? '' ) . '"' .
					' action=' . sanitize_text_field( $trace['action'] ?? '' ) .
					' source=' . sanitize_key( $trace['source'] ?? '' ) .
					' language=' . sanitize_key( $trace['language'] ?? '' );
			}
		}

		return implode( "\n", $lines );
	}

	private static function format_evaluation_language_bucket_lines( $by_language ) {
		$by_language = is_array( $by_language ) ? $by_language : [];
		$lines       = [];

		ksort( $by_language );

		foreach ( $by_language as $language => $bucket ) {
			$bucket = is_array( $bucket ) ? $bucket : [];
			$lines[] = '- ' . sanitize_key( $language ) .
				': total=' . absint( $bucket['total'] ?? 0 ) .
				' passed=' . absint( $bucket['passed'] ?? 0 ) .
				' failed=' . absint( $bucket['failed'] ?? 0 ) .
				' frame=' . absint( $bucket['semantic_frame_cases'] ?? 0 ) .
				' repaired=' . absint( $bucket['semantic_frame_repaired'] ?? 0 ) .
				' repair_events=' . absint( $bucket['semantic_frame_repair_events'] ?? 0 ) .
				' repair_rate=' . sanitize_text_field( (string) ( $bucket['semantic_frame_repair_rate'] ?? '0.0%' ) );
		}

		return $lines;
	}

	private static function run_parser_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		$setup = $test['setup'] ?? null;
		$teardown = $test['teardown'] ?? null;

		if ( is_callable( $setup ) ) {
			call_user_func( $setup );
		}

		try {
			$parsed = YoRo_Parser_Engine::parse(
				$test['command'],
				$context
			);
		} finally {
			if ( is_callable( $teardown ) ) {
				call_user_func( $teardown );
			}
		}

		$actual = self::normalize_result_for_assertion( $parsed );

		$assertions = [];

		if ( array_key_exists( 'expected_action', $test ) ) {
			$actual_action = is_array( $actual )
				? ( $actual['action'] ?? ( $actual['parsed']['action'] ?? null ) )
				: null;

			$assertions['action'] = [
				'expected' => $test['expected_action'],
				'actual'   => $actual_action,
				'passed'   => is_array( $actual ) && ( $actual_action === $test['expected_action'] ),
			];
		}

		if ( ! empty( $test['expect'] ) && is_array( $test['expect'] ) ) {
			foreach ( $test['expect'] as $path => $expected ) {
				$actual_value = self::get_path( $actual, $path );

				$assertions[ $path ] = [
					'expected' => $expected,
					'actual'   => $actual_value,
					'passed'   => self::values_equal( $expected, $actual_value ),
				];
			}
		}

		if ( ! empty( $test['expect_unmatched'] ) ) {
			$matched = is_array( $actual ) ? (bool) ( $actual['matched'] ?? false ) : false;

			$assertions['matched'] = [
				'expected' => false,
				'actual'   => $matched,
				'passed'   => false === $matched,
			];
		}

		if ( ! empty( $test['expect_stage'] ) ) {
			$stage = is_array( $actual ) ? ( $actual['stage'] ?? '' ) : '';

			$assertions['stage'] = [
				'expected' => $test['expect_stage'],
				'actual'   => $stage,
				'passed'   => $stage === $test['expect_stage'],
			];
		}

		$passed = self::assertions_passed( $assertions );

		return [
			'type'       => 'parser',
			'name'       => $test['name'] ?? $test['command'],
			'command'    => $test['command'],
			'passed'     => $passed,
			'assertions' => $assertions,
			'parsed'     => $actual,
		];
	}

	private static function run_pipeline_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

		try {
			$parsed = self::run_full_pipeline(
				$test['command'],
				$context
			);
		} finally {
			YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );
		}

		$actual = self::normalize_result_for_assertion( $parsed );

		$assertions = [];

		if ( array_key_exists( 'expected_action', $test ) ) {
			$actual_action = is_array( $actual )
				? ( $actual['action'] ?? ( $actual['parsed']['action'] ?? null ) )
				: null;

			$assertions['action'] = [
				'expected' => $test['expected_action'],
				'actual'   => $actual_action,
				'passed'   => $actual_action === $test['expected_action'],
			];
		}

		if ( ! empty( $test['expect'] ) && is_array( $test['expect'] ) ) {
			foreach ( $test['expect'] as $path => $expected ) {
				$actual_value = self::get_path( $actual, $path );

				$assertions[ $path ] = [
					'expected' => $expected,
					'actual'   => $actual_value,
					'passed'   => self::values_equal( $expected, $actual_value ),
				];
			}
		}

		if ( ! empty( $test['expect_unmatched'] ) ) {
			$matched = is_array( $actual ) ? (bool) ( $actual['matched'] ?? false ) : false;

			$assertions['matched'] = [
				'expected' => false,
				'actual'   => $matched,
				'passed'   => false === $matched,
			];
		}

		if ( ! empty( $test['expect_stage'] ) ) {
			$stage = is_array( $actual ) ? ( $actual['stage'] ?? '' ) : '';

			$assertions['stage'] = [
				'expected' => $test['expect_stage'],
				'actual'   => $stage,
				'passed'   => $stage === $test['expect_stage'],
			];
		}

		$passed = self::assertions_passed( $assertions );

		return [
			'type'       => 'pipeline',
			'name'       => $test['name'] ?? $test['command'],
			'command'    => $test['command'],
			'passed'     => $passed,
			'assertions' => $assertions,
			'parsed'     => $actual,
		];
	}

	private static function run_evaluation_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		$parsed  = self::run_full_pipeline(
			$test['command'],
			$context
		);
		$actual  = self::normalize_result_for_assertion( $parsed );
		$actual  = self::decorate_evaluation_result( $actual );

		$assertions = [];
		$expect     = [
			'action'        => $test['expected_action'] ?? null,
			'intent.name'   => $test['expected_intent'] ?? null,
			'intent_family' => $test['expected_intent_family'] ?? null,
			'language'      => $test['expected_language'] ?? null,
			'parser_source' => $test['expected_source'] ?? null,
		];

		if ( array_key_exists( 'expected_stage', $test ) ) {
			$expect['stage'] = $test['expected_stage'];
		}

		foreach ( $expect as $path => $expected ) {
			if ( null === $expected ) {
				continue;
			}

			$actual_value = self::get_path( $actual, $path );

			$assertions[ $path ] = [
				'expected' => $expected,
				'actual'   => $actual_value,
				'passed'   => self::values_equal( $expected, $actual_value ),
			];
		}

		if ( ! empty( $test['expect'] ) && is_array( $test['expect'] ) ) {
			foreach ( $test['expect'] as $path => $expected ) {
				$actual_value = self::get_path( $actual, $path );

				$assertions[ $path ] = [
					'expected' => $expected,
					'actual'   => $actual_value,
					'passed'   => self::values_equal( $expected, $actual_value ),
				];
			}
		}

		$passed = self::assertions_passed( $assertions );
		$intent = self::get_path( $actual, 'intent.name', '' );
		$intent_family = self::get_path( $actual, 'intent_family', '' );
		$source = self::get_path( $actual, 'parser_source', '' );
		$stage  = self::get_path( $actual, 'stage', '' );
		$action = self::get_path( $actual, 'action', '' );
		$semantic_frame_validator_changes = self::get_path( $actual, 'semantic_frame_validator_changes', [] );
		$semantic_frame_validator_changes = is_array( $semantic_frame_validator_changes ) ? $semantic_frame_validator_changes : [];

		return [
			'type'          => 'evaluation',
			'name'          => $test['name'] ?? $test['command'],
			'command'       => $test['command'],
			'pack'          => $test['pack'] ?? 'baseline',
			'domain'        => $test['domain'] ?? 'general',
			'expected_intent' => $test['expected_intent'] ?? '',
			'expected_intent_family' => $test['expected_intent_family'] ?? '',
			'expected_action' => $test['expected_action'] ?? '',
			'passed'        => $passed,
			'outcome'       => self::classify_evaluation_outcome( $test, $actual ),
			'assertions'    => $assertions,
			'parsed'        => $actual,
			'trace'         => [
				'intent'                      => $intent,
				'intent_family'               => $intent_family,
				'language'                    => self::get_path( $actual, 'language', '' ),
				'action'                      => $action,
				'source'                      => $source,
				'stage'                       => $stage,
				'confidence'                  => self::get_path( $actual, 'confidence', null ),
				'semantic_frame_present'      => self::evaluation_semantic_frame_is_informative( self::get_path( $actual, 'semantic_frame', [] ) ),
				'semantic_frame_repair_count' => count( $semantic_frame_validator_changes ),
			],
		];
	}

	private static function run_planner_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		$parsed  = null;

		if ( array_key_exists( 'parsed_override', $test ) ) {
			$parsed = is_callable( $test['parsed_override'] )
				? call_user_func( $test['parsed_override'], $context, $test )
				: $test['parsed_override'];
		} else {
			$parsed = self::run_full_pipeline(
				$test['command'],
				$context
			);
		}

		$plan = null;
		$risk = null;
		$policy = true;

		if ( is_array( $parsed ) && ! empty( $parsed['success'] ) && ! empty( $parsed['matched'] ) && ! empty( $parsed['action'] ) ) {
			$plan = YoRo_Action_Planner::build_plan( $parsed );

			if ( ! is_wp_error( $plan ) ) {
				$policy = YoRo_Policy_Engine::evaluate_plan( $plan );
				$risk   = YoRo_Plan_Risk_Engine::evaluate( $plan );
			}
		}

		$actual = [
			'parsed' => self::normalize_result_for_assertion( $parsed ),
			'plan'   => self::normalize_result_for_assertion( $plan ),
			'policy' => self::normalize_result_for_assertion( $policy ),
			'risk'   => self::normalize_result_for_assertion( $risk ),
		];

		$assertions = [];

		if ( ! empty( $test['expect'] ) && is_array( $test['expect'] ) ) {
			foreach ( $test['expect'] as $path => $expected ) {
				$actual_value = self::get_path( $actual, $path );

				$assertions[ $path ] = [
					'expected' => $expected,
					'actual'   => $actual_value,
					'passed'   => self::values_equal( $expected, $actual_value ),
				];
			}
		}

		$passed = self::assertions_passed( $assertions );

		return [
			'type'       => 'planner',
			'name'       => $test['name'] ?? $test['command'],
			'command'    => $test['command'],
			'passed'     => $passed,
			'assertions' => $assertions,
			'actual'     => $actual,
		];
	}

	private static function run_preview_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

		try {
			$parsed = self::run_full_pipeline(
				$test['command'],
				$context
			);

			$plan = null;
			$result = null;

			if ( is_array( $parsed ) && ! empty( $parsed['success'] ) && ! empty( $parsed['matched'] ) && ! empty( $parsed['action'] ) ) {
				$plan = YoRo_Action_Planner::build_plan( $parsed );

				if ( ! is_wp_error( $plan ) ) {
					$result = YoRo_Plan_Executor::execute(
						$plan,
						false,
						true
					);
				}
			}
		} finally {
			YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );
		}

		$actual = [
			'parsed' => self::normalize_result_for_assertion( $parsed ),
			'plan'   => self::normalize_result_for_assertion( $plan ),
			'result' => self::normalize_result_for_assertion( $result ),
		];

		$assertions = [];

		if ( ! empty( $test['expect'] ) && is_array( $test['expect'] ) ) {
			foreach ( $test['expect'] as $path => $expected ) {
				$actual_value = self::get_path( $actual, $path );

				$assertions[ $path ] = [
					'expected' => $expected,
					'actual'   => $actual_value,
					'passed'   => self::values_equal( $expected, $actual_value ),
				];
			}
		}

		$passed = self::assertions_passed( $assertions );

		return [
			'type'       => 'preview',
			'name'       => $test['name'] ?? $test['command'],
			'command'    => $test['command'],
			'passed'     => $passed,
			'assertions' => $assertions,
			'actual'     => $actual,
		];
	}

	private static function run_execute_case( $test ) {
		$fixture    = [];
		$actual     = [
			'parsed' => null,
			'plan'   => null,
			'result' => null,
		];
		$assertions = [];

		try {
			YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );
			$fixture = self::run_case_setup( $test );

			if ( is_wp_error( $fixture ) ) {
				$actual['setup'] = self::normalize_result_for_assertion( $fixture );
			} else {
				$actual = self::execute_test_case(
					$test,
					$fixture
				);
			}

			$assertions = self::build_expectation_assertions( $actual, $test['expect'] ?? [] );
			$assertions = self::merge_custom_assertions( $assertions, $test, $actual, $fixture );
		} catch ( Throwable $e ) {
			$assertions['exception'] = [
				'expected' => 'no exception',
				'actual'   => $e->getMessage(),
				'passed'   => false,
			];
		} finally {
			self::run_case_cleanup( $test, $fixture );
		}

		return [
			'type'       => 'execute',
			'name'       => $test['name'] ?? ( $test['command'] ?? ( $test['action'] ?? 'execute' ) ),
			'command'    => $test['command'] ?? '',
			'passed'     => self::assertions_passed( $assertions ),
			'assertions' => $assertions,
			'actual'     => $actual,
		];
	}

	private static function run_undo_case( $test ) {
		$fixture    = [];
		$actual     = [
			'parsed' => null,
			'plan'   => null,
			'result' => null,
			'undo'   => [],
			'state'  => [],
		];
		$assertions = [];

		try {
			YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );
			$fixture = self::run_case_setup( $test );

			if ( is_wp_error( $fixture ) ) {
				$actual['setup'] = self::normalize_result_for_assertion( $fixture );
			} else {
				$order_id = absint( $fixture['order_id'] ?? 0 );

				if ( $order_id ) {
					$actual['state']['before'] = self::capture_test_order_state( $order_id );
				}

				$execution = self::execute_test_case(
					$test,
					$fixture
				);

				$actual['parsed'] = $execution['parsed'] ?? null;
				$actual['plan']   = $execution['plan'] ?? null;
				$actual['result'] = $execution['result'] ?? null;

				if ( $order_id ) {
					$actual['state']['after_execute'] = self::capture_test_order_state( $order_id );
				}

				$undo_plan = self::get_path( $actual, 'result.undo_plan', [] );

				if ( is_array( $undo_plan ) && ! empty( $undo_plan ) ) {
					$actual['undo'] = self::execute_undo_plan( $undo_plan );
				}

				if ( $order_id ) {
					$actual['state']['after_undo'] = self::capture_test_order_state( $order_id );
				}
			}

			$assertions = self::build_expectation_assertions( $actual, $test['expect'] ?? [] );
			$assertions = self::merge_custom_assertions( $assertions, $test, $actual, $fixture );
		} catch ( Throwable $e ) {
			$assertions['exception'] = [
				'expected' => 'no exception',
				'actual'   => $e->getMessage(),
				'passed'   => false,
			];
		} finally {
			self::run_case_cleanup( $test, $fixture );
		}

		return [
			'type'       => 'undo',
			'name'       => $test['name'] ?? ( $test['command'] ?? ( $test['action'] ?? 'undo' ) ),
			'command'    => $test['command'] ?? '',
			'passed'     => self::assertions_passed( $assertions ),
			'assertions' => $assertions,
			'actual'     => $actual,
		];
	}

	private static function run_full_pipeline( $command, $client_context = [] ) {
		$parsed = YoRo_Parser_Engine::parse(
			$command,
			$client_context
		);

		if ( is_wp_error( $parsed ) || ! is_array( $parsed ) ) {
			return $parsed;
		}

		if ( 'clarify' === sanitize_key( $parsed['stage'] ?? '' ) ) {
			return $parsed;
		}

		/*
		 * YoRo_Parser_Engine already resolves entities, routes intents,
		 * and validates deterministic/AI results. These calls are intentional
		 * as a regression guard: every layer must stay idempotent.
		 */
		$parsed = YoRo_Entity_Resolver::resolve( $parsed, $client_context );
		$parsed = YoRo_Intent_Router::resolve_action( $parsed );
		$parsed = YoRo_Entity_Validator::validate( $parsed );

		return $parsed;
	}

	private static function execute_test_case( $test, $fixture ) {
		$context = self::normalize_context( $test['context'] ?? [] );
		$preview = ! empty( $test['preview'] );
		$confirm = array_key_exists( 'confirm', $test ) ? (bool) $test['confirm'] : true;
		$actual  = [
			'parsed' => null,
			'plan'   => null,
			'result' => null,
		];

		if ( isset( $test['action'] ) ) {
			$params = self::resolve_case_value( $test['params'] ?? [], $fixture );

			if ( isset( $test['params_builder'] ) && is_callable( $test['params_builder'] ) ) {
				$params = call_user_func( $test['params_builder'], $fixture );
			}

			$actual['result'] = self::normalize_result_for_assertion(
				YoRo_Action_Executor::execute(
					sanitize_text_field( $test['action'] ),
					is_array( $params ) ? $params : [],
					$confirm,
					$preview,
					$test['execute_context'] ?? []
				)
			);

			return $actual;
		}

		if ( ! empty( $test['rest_callback'] ) && is_callable( $test['rest_callback'] ) ) {
			$rest_method = sanitize_text_field( $test['rest_method'] ?? 'GET' );
			$request = new WP_REST_Request( $rest_method, '/yoro-ai-core/v1/test' );
			$rest_params = self::resolve_case_value( $test['rest_params'] ?? [], $fixture );

			if ( is_array( $rest_params ) ) {
				foreach ( $rest_params as $key => $value ) {
					$request->set_param( $key, $value );
				}
			}

			$actual['result'] = self::normalize_result_for_assertion(
				call_user_func( $test['rest_callback'], $request )
			);

			return $actual;
		}

		$command = self::resolve_case_value( $test['command'] ?? '', $fixture );

		if ( isset( $test['command_builder'] ) && is_callable( $test['command_builder'] ) ) {
			$command = call_user_func( $test['command_builder'], $fixture );
		}

		$parsed = self::run_full_pipeline( $command, $context );
		$plan   = null;
		$result = null;

		if ( is_array( $parsed ) && ! empty( $parsed['success'] ) && ! empty( $parsed['matched'] ) && ! empty( $parsed['action'] ) ) {
			$plan = YoRo_Action_Planner::build_plan( $parsed );

			if ( ! is_wp_error( $plan ) ) {
				$result = YoRo_Plan_Executor::execute( $plan, $confirm, $preview );
			} else {
				$result = $plan;
			}
		}

		$actual['parsed'] = self::normalize_result_for_assertion( $parsed );
		$actual['plan']   = self::normalize_result_for_assertion( $plan );
		$actual['result'] = self::normalize_result_for_assertion( $result );

		return $actual;
	}

	private static function build_expectation_assertions( $actual, $expect ) {
		$assertions = [];

		if ( empty( $expect ) || ! is_array( $expect ) ) {
			return $assertions;
		}

		foreach ( $expect as $path => $expected ) {
			$actual_value = self::get_path( $actual, $path );

			$assertions[ $path ] = [
				'expected' => $expected,
				'actual'   => $actual_value,
				'passed'   => self::values_equal( $expected, $actual_value ),
			];
		}

		return $assertions;
	}

	private static function merge_custom_assertions( $assertions, $test, $actual, $fixture ) {
		if ( empty( $test['assert'] ) || ! is_callable( $test['assert'] ) ) {
			return $assertions;
		}

		$extra = call_user_func( $test['assert'], $actual, $fixture );

		if ( ! is_array( $extra ) ) {
			return $assertions;
		}

		return array_merge( $assertions, $extra );
	}

	private static function run_case_setup( $test ) {
		if ( empty( $test['setup'] ) || ! is_callable( $test['setup'] ) ) {
			return [];
		}

		$fixture = call_user_func( $test['setup'] );

		return is_array( $fixture ) || is_wp_error( $fixture )
			? $fixture
			: [];
	}

	private static function run_case_cleanup( $test, $fixture ) {
		YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

		if ( empty( $test['cleanup'] ) || ! is_callable( $test['cleanup'] ) ) {
			return;
		}

		call_user_func( $test['cleanup'], is_array( $fixture ) ? $fixture : [] );
	}

	private static function resolve_case_value( $value, $fixture ) {
		if ( is_callable( $value ) ) {
			return call_user_func( $value, $fixture );
		}

		return $value;
	}

	private static function execute_undo_plan( $undo_plan ) {
		$results = [];

		if ( empty( $undo_plan ) || ! is_array( $undo_plan ) ) {
			return $results;
		}

		foreach ( array_reverse( $undo_plan ) as $step ) {
			$action = sanitize_text_field( $step['action'] ?? '' );
			$params = isset( $step['params'] ) && is_array( $step['params'] ) ? $step['params'] : [];

			if ( empty( $action ) ) {
				continue;
			}

			$results[] = [
				'action' => $action,
				'params' => $params,
				'result' => self::normalize_result_for_assertion(
					YoRo_Action_Executor::execute(
						$action,
						$params,
						true,
						false,
						[
							'is_undo' => true,
						]
					)
				),
			];
		}

		return $results;
	}

	private static function parser_cases() {
		$cases = [

			// ===== CASUAL CHAT / OUT-OF-SCOPE COPY =====
			[
				'domain'          => 'casual_chat',
				'name'            => 'Greeting returns natural assistant copy',
				'command'         => 'Hello',
				'expected_source' => 'ai_router',
				'expect'          => [
					'response_only'    => true,
					'resolution_path'  => 'ai_primary',
					'result.summary'   => 'Hi. I can help with WooCommerce store operations.',
					'result.suggestions' => [],
				],
			],
			[
				'domain'          => 'casual_chat',
				'name'            => 'Greeting fallback stays a safety guard when AI router is unavailable',
				'command'         => 'Hey',
				'expected_source' => 'rule',
				'expect'          => [
					'response_only'    => true,
					'parser_source'    => 'rule',
					'resolution_path'  => 'safety_guard',
					'fallback_reason'  => 'conversation_route',
					'result.summary'   => 'Hello. I can help you review the store, open the main queues, or inspect a specific order.',
				],
			],
			[
				'domain'          => 'casual_chat',
				'name'            => 'Identity question does not expose classifier wording',
				'command'         => 'Do you know who am I?',
				'expected_source' => 'ai_router',
				'expect'          => [
					'response_only'    => true,
					'resolution_path'  => 'ai_primary',
					'result.summary'   => 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.',
					'result.suggestions' => [],
				],
			],
			[
				'domain'          => 'casual_chat',
				'name'            => 'Unsupported request keeps direct developer handoff action',
				'command'         => 'Can you create shipping labels with ShipStation for me?',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'unsupported_request',
						'',
						[],
						0.93
					),
					[
						'summary'                => 'That shipping-label workflow is not supported here yet.',
						'clarification_question' => 'If you want, I can pass that request to the developers.',
						'language'               => 'en',
					]
				),
				'expect'          => [
					'response_only'                 => true,
					'resolution_path'               => 'ai_primary',
					'result.conversation_topic'     => 'unsupported_request',
					'result.developer_handoff_offer' => true,
					'result.suggestions.0.action'   => 'assistant.submit_developer_request',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Daily briefing ignores active order context',
				'command'         => 'Can you give me a brief today?',
				'expected_action' => 'store.get_daily_briefing',
				'expected_source' => 'ai_router',
				'context'         => [
					'current_order_id' => 6848,
				],
				'expect'          => [
					'resolution_path' => 'ai_primary',
					'params.window'    => 'today',
					'params.limit'     => 1,
					'params.order_id'  => null,
					'params.order_ids' => null,
				],
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router reports trimmed params that do not belong to selected action',
				'command'         => 'List pending orders for trimming test',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'status' => [ 'pending' ],
						'note'   => 'should_be_trimmed',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'             => 'ai_primary',
					'params.status.0'             => 'pending',
					'params.note'                 => null,
					'intent_family'               => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'params_trimmed_count'        => 1,
					'command_decision.params_trimmed_count' => 1,
					'command_decision.dropped_param_keys.0' => 'note',
				],
			],
			[
				'domain'          => 'date_windows',
				'name'            => 'Vietnamese this week order search uses canonical date window',
				'command'         => 'Tuần này có đơn nào đang pending không?',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path'             => 'ai_primary',
					'params.status.0'             => 'pending',
					'params.date_window'          => 'this_week',
					'language'                    => 'vi',
					'command_decision.params_trimmed_count' => 0,
				],
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router confirmation-like clarify is promoted to write task request',
				'command'         => 'Thêm ghi chú cho đơn 6864: Khách yêu cầu gọi lại',
				'expected_action' => 'orders.add_note',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'clarify',
						'orders.add_note',
						[
							'order_ids'        => [ 6864 ],
							'note'             => 'Khách yêu cầu gọi lại',
							'context_order_id' => 6864,
						],
						0.82
					),
					[
						'language'               => 'vi',
						'clarification_question' => 'Bạn có đồng ý thêm ghi chú cho đơn 6864 với nội dung “Khách yêu cầu gọi lại” không?',
					]
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'parser_rule'                    => 'ai.command_router.v2',
					'params.order_ids.0'             => 6864,
					'params.note'                    => 'Khách yêu cầu gọi lại',
					'command_decision.params_trimmed_count' => 0,
				],
				'assert'          => function( $actual ) {
					return [
						'promoted_out_of_clarify_stage' => [
							'expected' => true,
							'actual'   => empty( $actual['stage'] ),
							'passed'   => empty( $actual['stage'] ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router low-confidence write task with complete params bypasses fake clarify',
				'command'         => 'Thêm ghi chú cho đơn 6864: Khách yêu cầu gọi lại',
				'expected_action' => 'orders.add_note',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'clarify',
						'orders.add_note',
						[
							'order_ids' => [ 6864 ],
							'note'      => 'Khách yêu cầu gọi lại',
						],
						0.0
					),
					[
						'language'               => 'vi',
						'clarification_question' => 'I am not confident enough to run this yet. What did you mean?',
					]
				),
				'expect'          => [
					'resolution_path'    => 'ai_primary',
					'parser_rule'        => 'ai.command_router.v2',
					'params.order_ids.0' => 6864,
					'params.note'        => 'Khách yêu cầu gọi lại',
					'confidence'         => 0.0,
				],
				'assert'          => function( $actual ) {
					return [
						'promoted_out_of_low_confidence_clarify_stage' => [
							'expected' => true,
							'actual'   => empty( $actual['stage'] ),
							'passed'   => empty( $actual['stage'] ),
						],
						'no_generic_clarify_options' => [
							'expected' => true,
							'actual'   => empty( self::get_path( $actual, 'options', [] ) ),
							'passed'   => empty( self::get_path( $actual, 'options', [] ) ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router low-confidence email save-draft request promotes into workflow instead of clarify',
				'command'         => 'Write a payment follow-up email for order 6864 and save as draft',
				'expected_action' => 'workflow.write_and_save_email_draft_for_order',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'task_request',
						'orders.save_customer_email_draft',
						[
							'order_id' => 6864,
						],
						0.72
					),
					[
						'language' => 'en',
					]
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'parser_rule'                    => 'ai.command_router.v2',
					'params.order_id'                => 6864,
					'params.delivery_mode'           => 'save_draft',
					'workflow_promotion_trigger_action' => 'workflow.write_and_save_email_draft_for_order',
				],
				'assert'          => function( $actual ) {
					return [
						'not_stuck_in_low_confidence_clarify' => [
							'expected' => true,
							'actual'   => empty( $actual['stage'] ) || 'clarify' !== $actual['stage'],
							'passed'   => empty( $actual['stage'] ) || 'clarify' !== $actual['stage'],
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router keeps operations summary params minimal when action metadata is sufficient',
				'command'         => 'Operations summary minimal params test',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'operations.get_summary',
					[
						'window' => 'today',
					],
					0.94
				),
				'expect'          => [
					'resolution_path'                    => 'ai_primary',
					'intent_family'                      => 'operations_overview',
					'command_decision.intent_family'     => 'operations_overview',
					'params.window'                      => 'today',
					'params.focus'                       => 'overview',
					'params_trimmed_count'               => 0,
					'command_decision.params_trimmed_count' => 0,
					'command_decision.raw_param_keys.0'  => 'window',
				],
				'assert'          => function( $actual ) {
					$dropped = self::get_path( $actual, 'command_decision.dropped_param_keys', [] );
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'operations_summary_dropped_keys_empty' => [
							'expected' => [],
							'actual'   => is_array( $dropped ) ? $dropped : [],
							'passed'   => empty( $dropped ),
						],
						'catalog_action_count_available' => [
							'expected' => true,
							'actual'   => $catalog_action_count > 0,
							'passed'   => $catalog_action_count > 0,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows business summary profile catalog',
				'command'         => 'How is our business doing this month?',
				'expected_action' => 'store.get_business_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'store.get_business_summary',
					[
						'window' => 'this_month',
						'focus'  => 'overview',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'business_summary',
					'command_decision.intent_family' => 'business_summary',
					'params.window'                  => 'this_month',
					'params.focus'                   => 'overview',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'business_summary_profile_narrowed_catalog_count' => [
							'expected' => '< 6',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 6,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows operations overview profile catalog',
				'command'         => 'How is the store doing this week?',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'operations.get_summary',
					[
						'window' => 'this_week',
						'focus'  => 'overview',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'operations_overview',
					'command_decision.intent_family' => 'operations_overview',
					'params.window'                  => 'this_week',
					'params.focus'                   => 'overview',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'operations_overview_profile_narrowed_catalog_count' => [
							'expected' => '< 6',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 6,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows operations attention queue profile catalog',
				'command'         => 'Anything urgent right now?',
				'expected_action' => 'operations.get_attention_queue',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'operations.get_attention_queue',
					[
						'limit' => 5,
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'operations_attention',
					'command_decision.intent_family' => 'operations_attention',
					'params.limit'                   => 5,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'operations_attention_profile_narrowed_catalog_count' => [
							'expected' => '< 4',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 4,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows customer service queue profile catalog',
				'command'         => 'Who needs follow-up?',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'customers.get_service_queue',
					[
						'limit' => 5,
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'customer_service_review',
					'command_decision.intent_family' => 'customer_service_review',
					'params.limit'                   => 5,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'customer_service_profile_narrowed_catalog_count' => [
							'expected' => '< 4',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 4,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows inventory queue profile catalog',
				'command'         => 'What products need restock?',
				'expected_action' => 'products.get_inventory_queue',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'products.get_inventory_queue',
					[
						'limit' => 5,
						'focus' => 'overview',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'inventory_review',
					'command_decision.intent_family' => 'inventory_review',
					'params.limit'                   => 5,
					'params.focus'                   => 'overview',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'inventory_profile_narrowed_catalog_count' => [
							'expected' => '< 4',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 4,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows order read profile catalog',
				'command'         => 'Show order 6864',
				'expected_action' => 'orders.get_details',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.get_details',
					[
						'order_ids' => [ 6864 ],
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'get_order_details',
					'command_decision.intent_family' => 'get_order_details',
					'params.order_ids.0'             => 6864,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'order_read_profile_narrowed_catalog_count' => [
							'expected' => '< 4',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 4,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows orders search profile catalog',
				'command'         => 'Show me the latest 5 orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'limit' => 5,
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'params.limit'                   => 5,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'orders_search_profile_narrowed_catalog_count' => [
							'expected' => '< 3',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 3,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router drops unsourced zero total filters from latest orders',
				'command'         => 'Show the latest 5 orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'limit'     => 5,
						'total_min' => 0,
						'total_max' => 0,
					],
					0.92
				),
				'expect'          => [
					'resolution_path'      => 'ai_primary',
					'intent_family'        => 'search_orders',
					'params.limit'         => 5,
					'params.total_min'     => null,
					'params.total_max'     => null,
					'dropped_param_keys.0' => 'total_min',
					'dropped_param_keys.1' => 'total_max',
					'params_trimmed_count' => 2,
					'summary'              => 'Here are the latest 5 orders.',
				],
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router direct hold latest selection normalizes to query and apply',
				'command'         => 'Hold latest 5 orders',
				'expected_action' => 'multi.query_and_apply',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.hold',
					[],
					0.92
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'parser_source'                  => 'ai_router',
					'intent_family'                  => 'query_and_apply',
					'command_shape.target_type'      => 'order_query_selection',
					'command_shape.operation_hint'   => 'query_and_apply',
					'command_decision.action'        => 'multi.query_and_apply',
					'command_decision.catalog_action_count' => 1,
					'params.limit'                   => 5,
					'actions.0.action'               => 'orders.search',
					'actions.0.params.limit'         => 5,
					'actions.0.params.status.0'      => 'pending',
					'actions.1.action'               => 'orders.hold',
				],
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router explicit-details misread is normalized into fresh orders search',
				'command'         => 'Get me 2 new order',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.get_details',
					[
						'order_ids' => [ 68470 ],
					],
					0.80
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 2,
				],
				'assert'          => function( $actual ) {
					$status = self::get_path( $actual, 'params.status', null );
					$order_ids = self::get_path( $actual, 'params.order_ids', null );
					$summary = (string) self::get_path( $actual, 'summary', '' );
					$reasoning_summary = (string) self::get_path( $actual, 'reasoning_summary', '' );
					$raw_param_keys = self::get_path( $actual, 'raw_param_keys', [] );

					return [
						'fresh_orders_misread_drops_fake_order_ids' => [
							'expected' => 'no explicit order ids',
							'actual'   => $order_ids,
							'passed'   => empty( $order_ids ),
						],
						'fresh_orders_misread_has_no_status_filter' => [
							'expected' => 'no status filter',
							'actual'   => $status,
							'passed'   => empty( $status ),
						],
						'fresh_orders_misread_summary_matches_search' => [
							'expected' => 'summary mentions latest 2 orders',
							'actual'   => $summary,
							'passed'   => false !== stripos( $summary, 'latest 2 orders' ),
						],
						'fresh_orders_misread_reasoning_matches_search' => [
							'expected' => 'reasoning mentions order search',
							'actual'   => $reasoning_summary,
							'passed'   => false !== stripos( $reasoning_summary, 'order search' ),
						],
						'fresh_orders_misread_raw_param_keys_match_search' => [
							'expected' => [ 'limit' ],
							'actual'   => $raw_param_keys,
							'passed'   => [ 'limit' ] === $raw_param_keys,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router low-confidence fresh orders search bypasses fake clarify',
				'command'         => 'Get 2 newest orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'clarify',
						'orders.search',
						[
							'limit' => 2,
						],
						0.0
					),
					[
						'intent_family'          => 'search_orders',
						'clarification_question' => 'I am not confident enough to run this yet. What did you mean?',
					]
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'parser_rule'                    => 'ai.command_router.v2',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'params.limit'                   => 2,
					'confidence'                     => 0.0,
				],
				'assert'          => function( $actual ) {
					return [
						'fresh_orders_search_not_stuck_in_clarify' => [
							'expected' => true,
							'actual'   => empty( $actual['stage'] ),
							'passed'   => empty( $actual['stage'] ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router fresh orders search strips inferred pending status',
				'command'         => 'Get 2 new orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'status' => 'pending',
						'limit'  => 2,
					],
					0.80
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 2,
				],
				'assert'          => function( $actual ) {
					$status = self::get_path( $actual, 'params.status', null );
					$summary = (string) self::get_path( $actual, 'summary', '' );
					$reasoning_summary = (string) self::get_path( $actual, 'reasoning_summary', '' );
					$raw_param_keys = self::get_path( $actual, 'raw_param_keys', [] );

					return [
						'fresh_orders_search_strips_inferred_pending_status' => [
							'expected' => 'no status filter',
							'actual'   => $status,
							'passed'   => empty( $status ),
						],
						'fresh_orders_search_summary_mentions_latest_orders' => [
							'expected' => 'summary mentions latest 2 orders',
							'actual'   => $summary,
							'passed'   => false !== stripos( $summary, 'latest 2 orders' ),
						],
						'fresh_orders_search_reasoning_mentions_freshness' => [
							'expected' => 'reasoning mentions freshness search',
							'actual'   => $reasoning_summary,
							'passed'   => false !== stripos( $reasoning_summary, 'freshness' ) || false !== stripos( $reasoning_summary, 'recent order list' ),
						],
						'fresh_orders_search_raw_param_keys_drop_status' => [
							'expected' => [ 'limit' ],
							'actual'   => $raw_param_keys,
							'passed'   => [ 'limit' ] === $raw_param_keys,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router search grounds status from semantic summary evidence',
				'command'         => 'Show 2 refund orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => array_merge(
					self::base_router_fixture_decision(
						'task_request',
						'orders.search',
						[
							'limit' => 2,
						],
						0.80
					),
					[
						'summary' => 'Show 2 refunded orders',
					]
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 2,
					'params.status.0'                => 'refunded',
				],
				'assert'          => function( $actual ) {
					$entities_status = self::get_path( $actual, 'entities.status', null );

					return [
						'semantic_status_grounding_updates_entities' => [
							'expected' => 'refunded',
							'actual'   => $entities_status,
							'passed'   => 'refunded' === $entities_status,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router refund preview clarify rewrites to refunded order search',
				'command'         => 'Open 3 refund orders',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'orders.refund_preview',
					[],
					0.60,
					[ 'order_id' ],
					'Which order should I preview a refund for?'
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 3,
					'params.status.0'                => 'refunded',
				],
				'assert'          => function( $actual ) {
					$missing = self::get_path( $actual, 'command_decision.missing', null );
					$stage = self::get_path( $actual, 'stage', '' );

					return [
						'refund_order_search_does_not_clarify_for_order_id' => [
							'expected' => 'task request without missing order_id',
							'actual'   => [
								'stage'   => $stage,
								'missing' => $missing,
							],
							'passed'   => empty( $stage ) && empty( $missing ),
						],
					];
				},
				],
				[
					'domain'          => 'router_hardening',
					'name'            => 'AI router refund status-write clarify rewrites quantity status phrase to search',
					'command'         => '3 refund orders',
					'expected_action' => 'orders.search',
					'expected_source' => 'ai_router',
					'router_fixture'  => self::base_router_fixture_decision(
						'clarify',
						'orders.update_status',
						[
							'status' => 'refunded',
						],
						0.60,
						[ 'order_ids' ],
						'Which order IDs should be refunded?'
					),
					'expect'          => [
						'resolution_path'                => 'ai_primary',
						'intent_family'                  => 'search_orders',
						'command_shape.target_type'      => 'order_list',
						'command_shape.operation_hint'   => 'search',
						'command_decision.intent_family' => 'search_orders',
						'command_decision.action'        => 'orders.search',
						'command_decision.catalog_action_count' => 1,
						'params.limit'                   => 3,
						'params.status.0'                => 'refunded',
					],
					'assert'          => function( $actual ) {
						$missing = self::get_path( $actual, 'command_decision.missing', null );
						$stage = self::get_path( $actual, 'stage', '' );

						return [
							'quantity_refund_orders_does_not_clarify_for_order_ids' => [
								'expected' => 'task request without missing order_ids',
								'actual'   => [
									'stage'   => $stage,
									'missing' => $missing,
								],
								'passed'   => empty( $stage ) && empty( $missing ),
							],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router verb-first refund count clarifies for missing order ids',
				'command'         => 'refund 3 orders',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'expect_stage'    => 'clarify',
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'orders.update_status',
					[
						'status' => 'refunded',
					],
					0.90,
					[ 'order_ids' ],
					'Which order IDs should I refund?'
				),
				'expect'          => [
					'stage'                              => 'clarify',
					'action'                             => 'orders.update_status',
					'params.status'                      => 'refunded',
					'missing.0'                          => 'order_ids',
					'command_shape.target_type'          => 'order_write_missing_target',
					'command_shape.operation_hint'       => 'write_missing_target',
					'command_shape.write_intent.action'  => 'orders.update_status',
					'command_shape.write_intent.quantity' => 3,
					'command_decision.action'            => 'orders.update_status',
					'command_decision.catalog_action_count' => 1,
					'command_decision.missing.0'         => 'order_ids',
					'options'                            => [],
				],
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router verb-first complete count clarifies for missing order ids',
				'command'         => 'complete 3 orders',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'expect_stage'    => 'clarify',
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'orders.update_status',
					[
						'status' => 'completed',
					],
					0.90,
					[ 'order_ids' ],
					'Which order IDs should I complete?'
				),
				'expect'          => [
					'stage'                              => 'clarify',
					'action'                             => 'orders.update_status',
					'params.status'                      => 'completed',
					'missing.0'                          => 'order_ids',
					'command_shape.target_type'          => 'order_write_missing_target',
					'command_shape.operation_hint'       => 'write_missing_target',
					'command_shape.write_intent.action'  => 'orders.update_status',
					'command_shape.write_intent.quantity' => 3,
					'command_decision.action'            => 'orders.update_status',
					'command_decision.catalog_action_count' => 1,
					'command_decision.missing.0'         => 'order_ids',
					'options'                            => [],
				],
			],
				[
					'domain'          => 'router_hardening',
					'name'            => 'AI router status qualified generic order rewrites details into search',
				'command'         => 'Show a refunded order',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'context'         => [
					'current_order_id' => 7133,
				],
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.get_details',
					[
						'order_ids' => [ 7133 ],
						'order_id'  => 7133,
						'status'    => 'refunded',
					],
					0.92
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 1,
					'params.status.0'                => 'refunded',
				],
				'assert'          => function( $actual ) {
					$order_ids = self::get_path( $actual, 'params.order_ids', null );
					$order_id = self::get_path( $actual, 'params.order_id', null );
					$summary = (string) self::get_path( $actual, 'summary', '' );

					return [
						'status_qualified_generic_order_search_drops_grounded_order_ids' => [
							'expected' => 'no grounded order ids',
							'actual'   => $order_ids,
							'passed'   => empty( $order_ids ) && empty( $order_id ),
						],
						'status_qualified_generic_order_search_summary_mentions_refunded' => [
							'expected' => 'summary mentions refunded order',
							'actual'   => $summary,
							'passed'   => false !== stripos( $summary, 'refunded order' ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router quantity status order without verb routes as search',
				'command'         => '3 completed order',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'context'         => [
					'resolved_order_ids' => [ 6954 ],
				],
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'limit'  => 3,
						'status' => [ 'completed' ],
					],
					0.92
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'command_decision.action'        => 'orders.search',
					'params.limit'                   => 3,
					'params.status.0'                => 'completed',
				],
				'assert'          => function( $actual ) {
					$order_ids = self::get_path( $actual, 'params.order_ids', null );
					$order_id = self::get_path( $actual, 'params.order_id', null );

					return [
						'quantity_status_order_search_drops_context_order' => [
							'expected' => 'no grounded order id',
							'actual'   => $order_ids ?: $order_id,
							'passed'   => empty( $order_id ) && empty( $order_ids ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows customer summary profile catalog',
				'command'         => 'Show customer summary for john@example.com',
				'expected_action' => 'customers.get_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'customers.get_summary',
					[
						'email' => 'john@example.com',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'customer_summary',
					'command_decision.intent_family' => 'customer_summary',
					'params.email'                   => 'john@example.com',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'customer_summary_profile_narrowed_catalog_count' => [
							'expected' => '< 3',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 3,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows customer order search catalog',
				'command'         => 'Show orders for john@example.com',
				'expected_action' => 'orders.search',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.search',
					[
						'email' => 'john@example.com',
					],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'intent_family'                  => 'search_orders',
					'command_decision.intent_family' => 'search_orders',
					'params.email'                   => 'john@example.com',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'customer_orders_profile_narrowed_catalog_count' => [
							'expected' => '< 3',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 3,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router narrows email template list profile catalog',
				'command'         => 'Show saved email templates',
				'expected_action' => 'email_templates.list',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'email_templates.list',
					[],
					0.95
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'params_trimmed_count'           => 0,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'email_template_list_profile_narrowed_catalog_count' => [
							'expected' => '< 2',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 2,
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router ignores null placeholder params when tracking trimmed keys',
				'command'         => 'Operations summary null placeholder trimming test',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'operations.get_summary',
					[
						'window'    => 'today',
						'order_ids' => null,
						'note'      => null,
						'body'      => null,
					],
					0.94
				),
				'expect'          => [
					'resolution_path'                       => 'ai_primary',
					'params.window'                         => 'today',
					'params.focus'                          => 'overview',
					'params_trimmed_count'                  => 0,
					'command_decision.params_trimmed_count' => 0,
				],
				'assert'          => function( $actual ) {
					return [
						'null_placeholder_raw_keys_are_ignored' => [
							'expected' => [ 'window' ],
							'actual'   => self::get_path( $actual, 'command_decision.raw_param_keys', [] ),
							'passed'   => [ 'window' ] === self::get_path( $actual, 'command_decision.raw_param_keys', [] ),
						],
						'null_placeholder_dropped_keys_are_ignored' => [
							'expected' => [],
							'actual'   => self::get_path( $actual, 'command_decision.dropped_param_keys', [] ),
							'passed'   => [] === self::get_path( $actual, 'command_decision.dropped_param_keys', [] ),
						],
					];
				},
			],
			[
				'domain'          => 'router_hardening',
				'name'            => 'AI router trims cross action params from operations summary route',
				'command'         => 'Operations summary trimming regression test',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'operations.get_summary',
					[
						'window'    => 'today',
						'order_ids' => [ 6865 ],
						'note'      => 'should_be_trimmed',
						'body'      => 'should_also_be_trimmed',
					],
					0.94
				),
				'expect'          => [
					'resolution_path'                    => 'ai_primary',
					'params.window'                      => 'today',
					'params.focus'                       => 'overview',
					'params.order_ids'                   => null,
					'params.note'                        => null,
					'params.body'                        => null,
					'params_trimmed_count'               => 3,
					'command_decision.params_trimmed_count' => 3,
				],
				'assert'          => function( $actual ) {
					$raw_keys     = self::get_path( $actual, 'command_decision.raw_param_keys', [] );
					$dropped_keys = self::get_path( $actual, 'command_decision.dropped_param_keys', [] );

					sort( $raw_keys );
					sort( $dropped_keys );

					return [
						'operations_summary_trimmed_keys_match' => [
							'expected' => [ 'body', 'note', 'order_ids' ],
							'actual'   => $dropped_keys,
							'passed'   => [ 'body', 'note', 'order_ids' ] === $dropped_keys,
						],
						'operations_summary_raw_keys_include_cross_action_noise' => [
							'expected' => [ 'body', 'note', 'order_ids', 'window' ],
							'actual'   => $raw_keys,
							'passed'   => [ 'body', 'note', 'order_ids', 'window' ] === $raw_keys,
						],
					];
				},
			],
			[
				'domain'         => 'email_followup',
				'name'           => 'Email compose clarification stores pending instruction workflow',
				'command'        => 'Write an email for order 6848',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'expect'         => [
					'resolution_path'           => 'ai_primary',
					'clarify_reason'            => 'missing_instruction',
					'intent_family'             => 'customer_email',
					'command_decision.intent_family' => 'customer_email',
					'action'                    => 'orders.compose_customer_email',
					'params.order_id'           => 6848,
					'missing.0'                 => 'instruction',
					'current_workflow.type'     => 'customer_email',
					'current_workflow.step'     => 'collect_missing_params',
					'current_workflow.action'   => 'orders.compose_customer_email',
					'current_workflow.order_id' => 6848,
				],
				'assert'         => function( $actual ) {
					$commands = array_values(
						array_filter(
							array_map(
								static function( $option ) {
									return is_array( $option ) ? sanitize_text_field( $option['command'] ?? '' ) : '';
								},
								self::get_path( $actual, 'options', [] )
							)
						)
					);

					return [
						'clarify_options_include_cancel' => [
							'expected' => true,
							'actual'   => in_array( 'workflow.cancel', $commands, true ),
							'passed'   => in_array( 'workflow.cancel', $commands, true ),
						],
						'clarify_options_only_cancel' => [
							'expected' => [ 'workflow.cancel' ],
							'actual'   => $commands,
							'passed'   => [ 'workflow.cancel' ] === $commands,
						],
					];
				},
			],
			[
				'domain'         => 'email_followup',
				'name'           => 'Email compose clarification normalizes AI missing alias',
				'command'        => 'Write an email for order 6865',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'expect'         => [
					'clarify_reason'             => 'missing_instruction',
					'action'                     => 'orders.compose_customer_email',
					'params.order_id'            => 6865,
					'missing.0'                  => 'instruction',
					'current_workflow.type'      => 'customer_email',
					'current_workflow.step'      => 'collect_missing_params',
					'current_workflow.action'    => 'orders.compose_customer_email',
					'current_workflow.order_id'  => 6865,
					'current_workflow.missing.0' => 'instruction',
				],
			],
			[
				'domain'          => 'email_followup',
				'name'            => 'Short email topic continues pending compose workflow',
				'command'         => 'About shipping delay',
				'expected_action' => 'orders.compose_customer_email',
				'context'         => [
					'current_workflow' => [
						'type'     => 'customer_email',
						'action'   => 'orders.compose_customer_email',
						'step'     => 'collect_missing_params',
						'order_id' => 6848,
						'params'   => [
							'order_id' => 6848,
						],
						'missing'  => [ 'instruction' ],
					],
				],
				'expect'          => [
					'resolution_path'       => 'deterministic_override',
					'params.order_id'    => 6848,
					'params.instruction' => 'About shipping delay',
				],
			],
			[
				'domain'          => 'email_followup',
				'name'            => 'Vietnamese email topic continues pending compose workflow',
				'command'         => 'Xin lỗi khách về việc giao hàng chậm',
				'expected_action' => 'orders.compose_customer_email',
				'context'         => [
					'current_workflow' => [
						'type'     => 'customer_email',
						'action'   => 'orders.compose_customer_email',
						'step'     => 'collect_missing_params',
						'order_id' => 6848,
						'params'   => [
							'order_id' => 6848,
						],
						'missing'  => [ 'instruction' ],
					],
				],
				'expect'          => [
					'resolution_path'    => 'deterministic_override',
					'params.order_id'    => 6848,
					'params.instruction' => 'Xin lỗi khách về việc giao hàng chậm',
				],
			],
			[
				'domain'         => 'email_followup',
				'name'           => 'Save draft is not swallowed as pending email instruction',
				'command'        => 'save draft',
				'expected_stage' => 'clarify',
				'context'        => [
					'current_workflow' => [
						'type'     => 'customer_email',
						'action'   => 'orders.compose_customer_email',
						'step'     => 'collect_missing_params',
						'order_id' => 6848,
						'params'   => [
							'order_id' => 6848,
						],
						'missing'  => [ 'instruction' ],
					],
				],
				'expect'         => [
					'resolution_path' => 'deterministic_override',
					'clarify_reason'  => 'missing_instruction',
					'message'         => 'The current email draft is missing order, subject, or body. Please preview the email again before saving.',
				],
			],
			[
				'domain'         => 'write_followup',
				'name'           => 'Add note clarification stores target order workflow',
				'command'        => 'Add a note to order 6864',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'context'        => [
					'current_order_id' => 6848,
				],
				'expect'         => [
					'current_workflow.type'         => 'order_note',
					'current_workflow.step'         => 'collect_missing_params',
					'current_workflow.action'       => 'orders.add_note',
					'current_workflow.order_id'     => 6864,
					'current_workflow.params.order_ids.0' => 6864,
				],
			],
			[
				'domain'         => 'write_followup',
				'name'           => 'Add note clarification normalizes AI missing alias',
				'command'        => 'Add a note to order 6865',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'context'        => [
					'current_order_id' => 6848,
				],
				'expect'         => [
					'current_workflow.type'         => 'order_note',
					'current_workflow.step'         => 'collect_missing_params',
					'current_workflow.action'       => 'orders.add_note',
					'current_workflow.order_id'     => 6865,
					'current_workflow.params.order_ids.0' => 6865,
					'current_workflow.missing.0'    => 'note',
				],
				'assert'         => function( $actual ) {
					$commands = array_values(
						array_filter(
							array_map(
								static function( $option ) {
									return is_array( $option ) ? sanitize_text_field( $option['command'] ?? '' ) : '';
								},
								self::get_path( $actual, 'options', [] )
							)
						)
					);

					return [
						'clarify_options_include_cancel' => [
							'expected' => true,
							'actual'   => in_array( 'workflow.cancel', $commands, true ),
							'passed'   => in_array( 'workflow.cancel', $commands, true ),
						],
						'clarify_options_only_cancel' => [
							'expected' => [ 'workflow.cancel' ],
							'actual'   => $commands,
							'passed'   => [ 'workflow.cancel' ] === $commands,
						],
					];
				},
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Add note to them reuses last order list and only asks for note',
				'command'         => 'Add note to them',
				'expected_stage'  => 'clarify',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::base_router_fixture_decision(
						'clarify',
						'orders.add_note',
						[],
						0.9,
						[ 'order_ids', 'note' ],
						'What note should I save, and which orders should receive it?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'action'                              => 'orders.add_note',
					'params.order_ids.0'                  => 6847,
					'params.order_ids.1'                  => 6682,
					'params.order_ids.2'                  => 6681,
					'params.order_id'                     => 6847,
					'missing.0'                           => 'note',
					'current_workflow.type'               => 'order_note',
					'current_workflow.action'             => 'orders.add_note',
					'current_workflow.order_id'           => 6847,
					'current_workflow.params.order_ids.0' => 6847,
					'current_workflow.params.order_ids.1' => 6682,
					'current_workflow.params.order_ids.2' => 6681,
					'current_workflow.missing.0'          => 'note',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Vietnamese add note follow-up reuses recent order list',
				'command'         => 'Thêm ghi chú cho các đơn đó',
				'expected_stage'  => 'clarify',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::multilingual_router_fixture(
						'vi',
						'clarify',
						'orders.add_note',
						[],
						'add_note',
						0.9,
						[ 'order_ids', 'note' ],
						'Bạn muốn lưu ghi chú gì cho các đơn đó?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'language'                            => 'vi',
					'action'                              => 'orders.add_note',
					'params.order_ids.0'                  => 6847,
					'params.order_ids.1'                  => 6682,
					'params.order_ids.2'                  => 6681,
					'missing.0'                           => 'note',
					'current_workflow.params.order_ids.0' => 6847,
					'current_workflow.params.order_ids.1' => 6682,
					'current_workflow.params.order_ids.2' => 6681,
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Vietnamese cancel-them follow-up reuses recent order list',
				'command'         => 'Chuyển chúng sang hủy',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::multilingual_router_fixture(
						'vi',
						'task_request',
						'orders.update_status',
						[
							'status' => 'cancelled',
						],
						'update_order_status',
						0.92
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];
					$decision['summary'] = 'Chuyển các đơn hàng gần đây sang trạng thái hủy.';
					$decision['reasoning_summary'] = 'Tham chiếu số nhiều bám vào danh sách đơn gần đây trong ngữ cảnh.';

					return $decision;
				},
				'context'         => [
					'current_order_id'         => 6843,
					'last_referenced_order_id' => 6843,
					'last_orders'    => [
						[ 'id' => 6843, 'number' => '6843' ],
						[ 'id' => 6842, 'number' => '6842' ],
						[ 'id' => 6841, 'number' => '6841' ],
					],
					'last_order_ids' => [ 6843, 6842, 6841 ],
				],
				'expect'          => [
					'language'           => 'vi',
					'params.order_ids.0' => 6843,
					'params.order_ids.1' => 6842,
					'params.order_ids.2' => 6841,
					'params.status'      => 'cancelled',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Short note text continues pending add note workflow',
				'command'         => 'VIP customer',
				'expected_action' => 'orders.add_note',
				'context'         => [
					'current_order_id' => 6848,
					'current_workflow' => [
						'type'     => 'generic',
						'action'   => 'orders.add_note',
						'step'     => 'collect_missing_params',
						'order_id' => 6864,
						'params'   => [
							'order_ids' => [ 6864 ],
						],
						'missing'  => [ 'note' ],
					],
				],
				'expect'          => [
					'params.order_ids.0' => 6864,
					'params.order_id'    => 6864,
					'params.note'        => 'VIP customer',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Vietnamese note text continues pending add note workflow',
				'command'         => 'Khách VIP cần ưu tiên gọi lại',
				'expected_action' => 'orders.add_note',
				'context'         => [
					'current_order_id' => 6848,
					'current_workflow' => [
						'type'     => 'generic',
						'action'   => 'orders.add_note',
						'step'     => 'collect_missing_params',
						'order_id' => 6864,
						'params'   => [
							'order_ids' => [ 6864 ],
						],
						'missing'  => [ 'note' ],
					],
				],
				'expect'          => [
					'resolution_path'    => 'deterministic_override',
					'params.order_ids.0' => 6864,
					'params.order_id'    => 6864,
					'params.note'        => 'Khách VIP cần ưu tiên gọi lại',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Short status text continues pending update status workflow',
				'command'         => 'Completed',
				'expected_action' => 'orders.update_status',
				'context'         => [
					'current_order_id' => 6848,
					'current_workflow' => [
						'type'     => 'generic',
						'action'   => 'orders.update_status',
						'step'     => 'collect_missing_params',
						'order_id' => 6864,
						'params'   => [
							'order_ids' => [ 6864 ],
						],
						'missing'  => [ 'status' ],
					],
				],
				'expect'          => [
					'params.order_ids.0' => 6864,
					'params.order_id'    => 6864,
					'params.status'      => 'completed',
				],
			],
			[
				'domain'         => 'write_followup',
				'name'           => 'Update status clarification normalizes AI missing alias',
				'command'        => 'Update order 6865 status',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'context'        => [
					'current_order_id' => 6848,
				],
				'expect'         => [
					'action'                         => 'orders.update_status',
					'params.order_ids.0'             => 6865,
					'missing.0'                      => 'status',
					'current_workflow.type'         => 'order_status_update',
					'current_workflow.step'         => 'collect_missing_params',
					'current_workflow.action'       => 'orders.update_status',
					'current_workflow.order_id'     => 6865,
					'current_workflow.params.order_ids.0' => 6865,
					'current_workflow.missing.0'    => 'status',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Mark them as completed reuses last order list without extra clarify',
				'command'         => 'Mark them as completed',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::base_router_fixture_decision(
						'clarify',
						'orders.update_status',
						[
							'status' => 'completed',
						],
						0.9,
						[ 'order_ids' ],
						'Which orders should I update to completed?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'params.order_ids.0' => 6847,
					'params.order_ids.1' => 6682,
					'params.order_ids.2' => 6681,
					'params.status'      => 'completed',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Portuguese status follow-up reuses recent order list',
				'command'         => 'Marque elas como concluídas',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::multilingual_router_fixture(
						'pt',
						'clarify',
						'orders.update_status',
						[
							'status' => 'completed',
						],
						'update_order_status',
						0.9,
						[ 'order_ids' ],
						'Quais pedidos você quer marcar como concluídos?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'language'           => 'pt',
					'params.order_ids.0' => 6847,
					'params.order_ids.1' => 6682,
					'params.order_ids.2' => 6681,
					'params.status'      => 'completed',
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Put them on hold reuses last order list without extra clarify',
				'command'         => 'Put them on hold',
				'expected_action' => 'orders.hold',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::base_router_fixture_decision(
						'clarify',
						'orders.hold',
						[],
						0.9,
						[ 'order_ids' ],
						'Which orders should I put on hold?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'params.order_ids.0' => 6847,
					'params.order_ids.1' => 6682,
					'params.order_ids.2' => 6681,
				],
			],
			[
				'domain'          => 'write_followup',
				'name'            => 'Indonesian hold follow-up reuses recent order list',
				'command'         => 'Tahan pesanan-pesanan itu',
				'expected_action' => 'orders.hold',
				'expected_source' => 'ai_router',
				'router_fixture'  => static function() {
					$decision = self::multilingual_router_fixture(
						'id',
						'clarify',
						'orders.hold',
						[],
						'hold_order',
						0.9,
						[ 'order_ids' ],
						'Pesanan mana yang ingin Anda tahan?'
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'context'         => [
					'last_orders'    => [
						[ 'id' => 6847, 'number' => '6847' ],
						[ 'id' => 6682, 'number' => '6682' ],
						[ 'id' => 6681, 'number' => '6681' ],
					],
					'last_order_ids' => [ 6847, 6682, 6681 ],
				],
				'expect'          => [
					'language'           => 'id',
					'params.order_ids.0' => 6847,
					'params.order_ids.1' => 6682,
					'params.order_ids.2' => 6681,
				],
			],
			[
				'domain'         => 'email_followup',
				'name'           => 'Japanese subject continues pending email template workflow',
				'command'        => '配送遅延のお詫び',
				'expected_stage' => 'clarify',
				'context'        => [
					'current_workflow' => [
						'type'   => 'email_template',
						'action' => 'email_templates.save',
						'step'   => 'collect_missing_params',
						'params' => [
							'name' => 'Delay apology',
							'body' => '',
						],
						'missing' => [ 'subject', 'body' ],
					],
				],
				'expect'         => [
					'resolution_path' => 'deterministic_override',
					'action'          => 'email_templates.save',
					'params.name'     => 'Delay apology',
					'params.subject'  => '配送遅延のお詫び',
					'missing.0'       => 'body',
					'clarify_reason'  => 'missing_instruction',
				],
			],
			[
				'domain'          => 'email_followup',
				'name'            => 'Vietnamese body completes pending email template workflow',
				'command'         => 'Chúng tôi xin lỗi vì đơn hàng của bạn bị giao chậm và đang ưu tiên xử lý ngay.',
				'expected_action' => 'email_templates.save',
				'context'         => [
					'current_workflow' => [
						'type'   => 'email_template',
						'action' => 'email_templates.save',
						'step'   => 'collect_missing_params',
						'params' => [
							'name'    => 'Delay apology',
							'subject' => 'Shipping delay update',
						],
						'missing' => [ 'body' ],
					],
				],
				'expect'          => [
					'resolution_path' => 'deterministic_override',
					'params.name'     => 'Delay apology',
					'params.subject'  => 'Shipping delay update',
					'params.body'     => 'Chúng tôi xin lỗi vì đơn hàng của bạn bị giao chậm và đang ưu tiên xử lý ngay.',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades suspicious order review request',
				'command'         => 'Review suspicious order 6864',
				'expected_action' => 'workflow.review_suspicious_order',
				'expected_source' => 'guard',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.get_details',
					[
						'order_ids' => [ 6864 ],
					],
					0.92
				),
				'expect'          => [
					'resolution_path'                => 'ai_primary',
					'workflow_promotion_source'      => 'ai_router',
					'workflow_promotion_reason'      => 'post_router_workflow_candidate',
					'workflow_promotion_trigger_action' => 'orders.get_details',
					'params.order_id'                  => 6864,
					'current_workflow.definition_id'   => 'review_suspicious_order',
					'current_workflow.current_step_id' => 'step_1',
					'current_workflow.status'          => 'ready_to_execute',
					'parser_rule'                      => 'guard.workflow_template.review_suspicious_order',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades refund review request',
				'command'         => 'Review refund for order 6864',
				'expected_action' => 'orders.refund_preview',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                  => 'deterministic_override',
					'params.order_id'                    => 6864,
					'params.order_ids.0'                 => 6864,
					'intent_family'                      => 'refund_review',
					'parser_rule'                        => 'guard.orders.refund_preview.explicit_order',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'orders.refund_preview',
					'command_shape.workflow_intent.operation_hint' => 'refund_preview',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Explicit refund command defaults to preview action',
				'command'         => 'Refund order 6864',
				'expected_action' => 'orders.refund_preview',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'    => 'deterministic_override',
					'params.order_id'    => 6864,
					'params.order_ids.0' => 6864,
					'intent_family'      => 'refund_review',
					'parser_rule'        => 'guard.orders.refund_preview.explicit_order',
					'command_shape.target_type' => 'order_workflow_explicit',
					'command_shape.operation_hint' => 'refund_preview',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'orders.refund_preview',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Explicit multi-order refund command preserves all targets',
				'command'         => 'Refund orders 6847, 6683, and 6681',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                         => 'deterministic_override',
					'intent_family'                           => 'update_order_status',
					'params.status'                           => 'refunded',
					'params.order_ids.0'                      => 6847,
					'params.order_ids.1'                      => 6683,
					'params.order_ids.2'                      => 6681,
					'command_shape.explicit_order_ids.0'      => 6847,
					'command_shape.explicit_order_ids.1'      => 6683,
					'command_shape.explicit_order_ids.2'      => 6681,
					'command_shape.operation_hint'            => 'explicit_refund_write',
					'command_shape.workflow_intent.kind'      => 'refund_review',
					'command_shape.workflow_intent.action'    => 'orders.update_status',
					'command_shape.workflow_intent.target_status' => 'refunded',
					'parser_rule'                             => 'guard.orders.update_status.explicit_refund_orders',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades verify before fulfillment request',
				'command'         => 'Hold order 6864 and ask the customer to verify their address before shipping',
				'expected_action' => 'workflow.verify_customer_before_fulfillment',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                  => 'deterministic_override',
					'fallback_reason'                  => 'explicit_verification_guard',
					'params.order_id'                    => 6864,
					'params.email_delivery_mode'         => 'preview',
					'current_workflow.definition_id'     => 'verify_customer_before_fulfillment',
					'current_workflow.current_step_id'   => 'step_1',
					'current_workflow.status'            => 'ready_to_execute',
					'parser_rule'                        => 'guard.workflow.verify_customer_before_fulfillment.explicit_order',
					'command_shape.workflow_intent.kind' => 'verify_customer_before_fulfillment',
					'command_shape.workflow_intent.action' => 'workflow.verify_customer_before_fulfillment',
					'command_shape.workflow_intent.email_delivery_mode' => 'preview',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades refund review with saved draft branch',
				'command'         => 'Review refund for order 6864 and save a refund follow-up email as draft',
				'expected_action' => 'workflow.refund_review',
				'expected_source' => 'guard',
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.delivery_mode'             => 'save_draft',
					'current_workflow.definition_id'   => 'refund_review',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.2.id'      => 'compose_email',
					'current_workflow.steps.3.id'      => 'save_draft',
					'parser_rule'                      => 'guard.workflow.refund_review.explicit_order',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'workflow.refund_review',
					'command_shape.workflow_intent.delivery_mode' => 'save_draft',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades refund review with send branch',
				'command'         => 'Review refund for order 6864 and send a refund follow-up email',
				'expected_action' => 'workflow.refund_review',
				'expected_source' => 'guard',
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.delivery_mode'             => 'send',
					'current_workflow.definition_id'   => 'refund_review',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.2.id'      => 'compose_email',
					'current_workflow.steps.3.id'      => 'send_email',
					'parser_rule'                      => 'guard.workflow.refund_review.explicit_order',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'workflow.refund_review',
					'command_shape.workflow_intent.delivery_mode' => 'send',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades refund review with email preview branch',
				'command'         => 'Review refund for order 6864 and write an email for it',
				'expected_action' => 'workflow.refund_review',
				'expected_source' => 'guard',
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.delivery_mode'             => 'preview',
					'params.instruction'               => 'Explain the refund review outcome and next steps to the customer. Keep it clear and concise.',
					'current_workflow.definition_id'   => 'refund_review',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.2.id'      => 'compose_email',
					'parser_rule'                      => 'guard.workflow.refund_review.explicit_order',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'workflow.refund_review',
					'command_shape.workflow_intent.delivery_mode' => 'preview',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Refund email preview guard wins before pre-router composition',
				'command'         => 'Review refund for order 6864, and write an email for it',
				'expected_action' => 'workflow.refund_review',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                => 'deterministic_override',
					'fallback_reason'                => 'explicit_refund_guard',
					'params.order_id'                => 6864,
					'params.delivery_mode'           => 'preview',
					'current_workflow.definition_id' => 'refund_review',
					'current_workflow.steps.2.id'    => 'compose_email',
					'parser_rule'                    => 'guard.workflow.refund_review.explicit_order',
					'command_shape.workflow_intent.kind' => 'refund_review',
					'command_shape.workflow_intent.action' => 'workflow.refund_review',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades verification flow with saved draft branch',
				'command'         => 'Hold order 6864, ask the customer to verify their address, and save the verification email as draft',
				'expected_action' => 'workflow.verify_customer_before_fulfillment',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                  => 'deterministic_override',
					'fallback_reason'                  => 'explicit_verification_guard',
					'params.order_id'                    => 6864,
					'params.email_delivery_mode'         => 'save_draft',
					'current_workflow.definition_id'     => 'verify_customer_before_fulfillment',
					'current_workflow.current_step_id'   => 'step_1',
					'current_workflow.status'            => 'ready_to_execute',
					'current_workflow.steps.3.id'        => 'save_draft',
					'parser_rule'                        => 'guard.workflow.verify_customer_before_fulfillment.explicit_order',
					'command_shape.workflow_intent.kind' => 'verify_customer_before_fulfillment',
					'command_shape.workflow_intent.email_delivery_mode' => 'save_draft',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades verification flow with send branch',
				'command'         => 'Hold order 6864, ask the customer to verify their address, and send the verification email',
				'expected_action' => 'workflow.verify_customer_before_fulfillment',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                  => 'deterministic_override',
					'fallback_reason'                  => 'explicit_verification_guard',
					'params.order_id'                    => 6864,
					'params.email_delivery_mode'         => 'send',
					'current_workflow.definition_id'     => 'verify_customer_before_fulfillment',
					'current_workflow.current_step_id'   => 'step_1',
					'current_workflow.status'            => 'ready_to_execute',
					'current_workflow.steps.3.id'        => 'send_email',
					'parser_rule'                        => 'guard.workflow.verify_customer_before_fulfillment.explicit_order',
					'command_shape.workflow_intent.kind' => 'verify_customer_before_fulfillment',
					'command_shape.workflow_intent.email_delivery_mode' => 'send',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Action composition upgrades hold and note request',
				'command'         => 'Hold order 6864 and add note: Customer requested callback',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'expect'          => [
					'resolution_path'                => 'deterministic_override',
					'fallback_reason'                => 'action_composition_guard',
					'composition_source'             => 'guard_action_composition',
					'params.order_id'                  => 6864,
					'current_workflow.definition_id'   => 'composed_actions',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.1.id'      => 'hold_order',
					'current_workflow.steps.2.id'      => 'add_note',
					'current_workflow.steps.2.params.note' => 'Customer requested callback',
					'parser_rule'                      => 'guard.action_composition',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Status with note stays a direct write action',
				'command'         => 'Mark order 6864 as completed with note: Packed and verified',
				'expected_action' => 'orders.bulk_update_status_with_note',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.add_note',
					[
						'order_ids' => [ 6864 ],
						'status'    => 'completed',
						'note'      => 'Packed and verified',
					],
					0.95,
					[],
					'',
					'add_note'
				),
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.order_ids.0'               => 6864,
					'params.status'                    => 'completed',
					'params.note'                      => 'Packed and verified',
					'parser_rule'                      => 'ai.command_router.v2',
					'intent_family'                    => 'update_order_status',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Direct email authoring request stays on compose action',
				'command'         => 'Write a payment follow-up email for order 6864',
				'expected_action' => 'orders.compose_customer_email',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'    => 6864,
					'params.instruction' => 'payment follow-up',
					'parser_rule'        => 'ai.command_router.v2',
					'resolution_path'    => 'ai_primary',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades write and save draft request',
				'command'         => 'Write a payment follow-up email for order 6864 and save as draft',
				'expected_action' => 'workflow.write_and_save_email_draft_for_order',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.instruction'               => 'payment follow-up',
					'params.delivery_mode'             => 'save_draft',
					'current_workflow.definition_id'   => 'write_and_save_email_draft_for_order',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.1.capture.email_subject' => 'result.email.subject',
					'current_workflow.steps.1.capture.email_body'    => 'result.email.body',
					'current_workflow.steps.2.id'      => 'save_draft',
					'parser_rule'                      => 'ai.command_router.v2',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'catalog_action_count_save_draft_profile' => [
							'expected' => '< 7',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 7,
						],
					];
				},
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Workflow candidate upgrades write and send request',
				'command'         => 'Write a payment follow-up email for order 6864 and send it',
				'expected_action' => 'workflow.write_and_send_email_for_order',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'                  => 6864,
					'params.instruction'               => 'payment follow-up',
					'params.delivery_mode'             => 'send',
					'current_workflow.definition_id'   => 'write_and_send_email_for_order',
					'current_workflow.current_step_id' => 'load_order',
					'current_workflow.status'          => 'ready_to_execute',
					'current_workflow.steps.1.capture.email_subject' => 'result.email.subject',
					'current_workflow.steps.1.capture.email_body'    => 'result.email.body',
					'current_workflow.steps.2.id'      => 'send_email',
					'parser_rule'                      => 'ai.command_router.v2',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'catalog_action_count_send_profile' => [
							'expected' => '< 5',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 5,
						],
					];
				},
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Explicit multi-order email request resolves to multi-order workflow',
				'command'         => 'Write an email about shipping on the way for orders 7140, 7139, 7138',
				'expected_action' => 'workflow.write_email_for_orders',
				'context'         => [
					'last_orders'        => [
						[ 'id' => 7140, 'number' => '7140' ],
						[ 'id' => 7139, 'number' => '7139' ],
						[ 'id' => 7138, 'number' => '7138' ],
					],
					'last_order_ids'     => [ 7140, 7139, 7138 ],
					'resolved_order_ids' => [ 7140, 7139, 7138 ],
					'current_order_id'   => 7140,
				],
				'router_fixture'  => static function() {
					$decision = self::base_router_fixture_decision(
						'task_request',
						'workflow.write_email_for_orders',
						[
							'order_ids'     => [ 7140, 7139, 7138 ],
							'instruction'   => 'shipping on the way',
							'delivery_mode' => 'preview',
						],
						0.88
					);
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'expect'          => [
					'params.order_ids.0'                        => 7140,
					'params.order_ids.1'                        => 7139,
					'params.order_ids.2'                        => 7138,
					'params.instruction'                        => 'shipping on the way',
					'params.delivery_mode'                      => 'preview',
					'current_workflow.definition_id'            => 'write_email_for_orders',
					'current_workflow.current_step_id'          => 'load_order_7140',
					'current_workflow.status'                   => 'ready_to_execute',
					'current_workflow.steps.0.id'               => 'load_order_7140',
					'current_workflow.steps.1.id'               => 'compose_email_7140',
					'parser_rule'                               => 'ai.command_router.v2',
				],
				'assert'          => function( $actual ) {
					$instruction = (string) self::get_path( $actual, 'current_workflow.steps.1.params.instruction', '' );

					return [
						'multi_order_step_instruction_mentions_current_order_only' => [
							'expected' => 'instruction scoped to order 7140',
							'actual'   => $instruction,
							'passed'   => false !== strpos( $instruction, 'order #7140' ) && false !== strpos( $instruction, 'shipping on the way' ),
						],
					];
				},
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Multi-order send-email follow-up upgrades from order details to send workflow',
				'command'         => 'Send them the email about shipping on the way',
				'expected_action' => 'workflow.write_and_send_emails_for_orders',
				'expected_source' => 'ai_router',
				'context'         => [
					'last_orders'        => [
						[ 'id' => 7140, 'number' => '7140' ],
						[ 'id' => 7139, 'number' => '7139' ],
						[ 'id' => 7138, 'number' => '7138' ],
					],
					'last_order_ids'     => [ 7140, 7139, 7138 ],
					'resolved_order_ids' => [ 7140, 7139, 7138 ],
					'current_order_id'   => 7140,
				],
				'router_fixture'  => static function() {
					$decision = self::base_router_fixture_decision(
						'task_request',
						'orders.get_details',
						[
							'order_ids' => [ 7140, 7139, 7138 ],
						],
						0.92
					);
					$decision['summary'] = 'Fetching details for orders 7140, 7139, and 7138 to prepare an email about shipping on the way.';
					$decision['reasoning_summary'] = 'The best matching available action is to retrieve details for the referenced orders before sending the message.';
					$decision['intent_family'] = 'get_order_details';
					$decision['intent'] = [
						'name'       => 'get_order_details',
						'action'     => 'orders.get_details',
						'confidence' => 0.92,
						'source'     => 'ai_router',
					];
					$decision['entities'] = [
						'order_ids' => [ 7140, 7139, 7138 ],
					];
					$decision['evidence']['referenced_context'] = [ 'recent_order_list' ];

					return $decision;
				},
				'expect'          => [
					'params.order_ids.0'                        => 7140,
					'params.order_ids.1'                        => 7139,
					'params.order_ids.2'                        => 7138,
					'params.instruction'                        => 'shipping on the way',
					'params.delivery_mode'                      => 'send',
					'current_workflow.definition_id'            => 'write_and_send_emails_for_orders',
					'current_workflow.steps.0.id'               => 'load_order_7140',
					'current_workflow.steps.1.id'               => 'compose_email_7140',
					'current_workflow.steps.2.id'               => 'send_email_7140',
					'parser_rule'                               => 'guard.workflow_template.write_and_send_emails_for_orders',
				],
			],
			[
				'domain'          => 'workflow_candidates',
				'name'            => 'Multi-order send-email request with mixed recipients clarifies instead of batching',
				'command'         => 'Send email to ask about payment verification for them',
				'expected_action' => 'workflow.write_and_send_emails_for_orders',
				'expected_source' => 'guard',
				'expected_stage'  => 'clarify',
				'context'         => [
					'last_orders'        => [
						[ 'id' => 7133, 'number' => '7133' ],
						[ 'id' => 6864, 'number' => '6864' ],
					],
					'last_order_ids'     => [ 7133, 6864 ],
					'resolved_order_ids' => [ 7133, 6864 ],
					'current_order_id'   => 7133,
				],
				'router_fixture'  => static function() {
					return self::base_router_fixture_decision(
						'task_request',
						'workflow.write_and_send_emails_for_orders',
						[
							'order_ids'     => [ 7133, 6864 ],
							'instruction'   => 'Ask the customer to verify payment for orders 7133 and 6864.',
							'delivery_mode' => 'send',
						],
						0.92
					);
				},
				'expect'          => [
					'message'     => 'I can only prepare customer emails in one batch when all selected orders belong to the same recipient email. These orders belong to different recipients, so please split the request by customer.',
					'parser_rule' => 'ai.command_router.v2',
				],
			],
			[
				'domain'          => 'workflow_composition',
				'name'            => 'Action composition builds a composed workflow from hold note and email draft request',
				'command'         => 'Hold order 6864, add note: Customer requested address confirmation, then write and save a payment follow-up email',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'expect'          => [
					'current_workflow.definition_id'                              => 'composed_actions',
					'current_workflow.status'                                     => 'ready_to_execute',
					'current_workflow.steps.0.id'                                 => 'load_order',
					'current_workflow.steps.1.id'                                 => 'hold_order',
					'current_workflow.steps.2.id'                                 => 'add_note',
					'current_workflow.steps.3.id'                                 => 'compose_email',
					'current_workflow.steps.4.id'                                 => 'save_draft',
					'current_workflow.artifacts.composition.requested_actions.2.kind' => 'write_email',
					'parser_rule'                                                 => 'guard.action_composition',
				],
			],
			[
				'domain'          => 'workflow_composition',
				'name'            => 'Action composition keeps unsupported action as partial-support notice',
				'command'         => 'Move order 6864 to pending with note: Awaiting payment confirmation, then create shipment label',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'expect'          => [
					'current_workflow.definition_id'                                   => 'composed_actions',
					'current_workflow.steps.0.action'                                  => 'orders.bulk_update_status_with_note',
					'current_workflow.artifacts.composition.partial_support'           => true,
					'current_workflow.artifacts.composition.unavailable_requests.0.key'=> 'shipping_label',
					'parser_rule'                                                      => 'guard.action_composition',
				],
			],
			[
				'domain'          => 'workflow_composition_ai',
				'name'            => 'AI action decomposition composes mixed workflow safely',
				'command'         => 'Hold order 6864, add note: Customer requested address confirmation, then write and save a payment follow-up email',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'setup' => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.93,
							'reason' => 'Detected three supported actions in one request.',
							'requested_actions' => [
								[
									'id' => 'hold_order',
									'label' => 'Put order on hold',
									'action' => 'orders.hold',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ] ],
								],
								[
									'id' => 'add_note',
									'label' => 'Add internal note',
									'action' => 'orders.add_note',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ], 'note' => 'Customer requested address confirmation' ],
								],
								[
									'id' => 'compose_email',
									'label' => 'Compose customer email',
									'action' => 'orders.compose_customer_email',
									'confidence' => 0.93,
									'params' => [ 'order_id' => 6864, 'instruction' => 'payment follow-up', 'delivery_mode' => 'save_draft' ],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown' => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'current_workflow.definition_id'   => 'composed_actions',
					'current_workflow.steps.0.id'      => 'load_order',
					'current_workflow.steps.1.id'      => 'hold_order',
					'current_workflow.steps.2.id'      => 'add_note',
					'current_workflow.steps.3.id'      => 'compose_email',
					'current_workflow.steps.4.id'      => 'save_draft',
					'parser_rule'                      => 'ai.action_decomposition',
				],
			],
				[
					'domain'          => 'workflow_composition_ai',
					'name'            => 'AI action decomposition merges fallback note and email delivery details',
					'command'         => 'Update order 6864 to pending and note: Need to update payment, and send an email about it',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'setup' => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.85,
							'requested_actions' => [
								[
									'id' => 'update_status',
									'label' => 'Update order status to pending',
									'action' => 'orders.update_status',
									'confidence' => 0.9,
									'params' => [
										'order_ids' => [ 6864 ],
										'status' => 'pending',
									],
								],
								[
									'id' => 'compose_email',
									'label' => 'Compose customer email for order 6864',
									'action' => 'orders.compose_customer_email',
									'confidence' => 0.88,
									'params' => [
										'order_id' => 6864,
									],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown' => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'current_workflow.definition_id'                                            => 'composed_actions',
					'current_workflow.steps.1.id'                                                => 'update_status_with_note',
					'current_workflow.steps.1.action'                                            => 'orders.bulk_update_status_with_note',
					'current_workflow.steps.1.params.note'                                       => 'Need to update payment',
					'current_workflow.steps.2.id'                                                => 'compose_email',
					'current_workflow.steps.2.params.instruction'                                => 'Notify the customer that the order is now pending and explain: Need to update payment.',
					'current_workflow.steps.3.id'                                                => 'send_email',
					'current_workflow.artifacts.composition.requested_actions.0.kind'            => 'status_with_note',
					'current_workflow.artifacts.composition.requested_actions.1.kind'            => 'write_email',
						'parser_rule'                                                                => 'ai.action_decomposition',
					],
				],
				[
					'domain'          => 'workflow_composition_ai',
					'name'            => 'AI action decomposition repairs empty note from email topic',
					'command'         => 'Change order 123 status to on hold, add note and send an email about payment confirmation',
					'expected_action' => 'workflow.composed_actions',
					'expected_source' => 'guard',
					'setup' => function() {
						self::enable_mock_action_decomposition(
							[
								'matched' => true,
								'confidence' => 0.85,
								'requested_actions' => [
									[
										'id' => 'hold_order',
										'label' => 'Put order on hold',
										'action' => 'orders.hold',
										'confidence' => 0.9,
										'params' => [
											'order_ids' => [ 123 ],
										],
									],
									[
										'id' => 'add_note',
										'label' => 'Add internal note',
										'action' => 'orders.add_note',
										'confidence' => 0.86,
										'params' => [
											'order_ids' => [ 123 ],
										],
									],
									[
										'id' => 'compose_email',
										'label' => 'Compose customer email',
										'action' => 'orders.compose_customer_email',
										'confidence' => 0.88,
										'params' => [
											'order_id' => 123,
											'instruction' => 'payment confirmation',
											'delivery_mode' => 'send',
										],
									],
								],
								'unsupported_requests' => [],
								'missing_params' => [],
							]
						);
					},
					'teardown' => function() {
						self::disable_mock_action_decomposition();
					},
					'expect'          => [
						'current_workflow.definition_id'                  => 'composed_actions',
						'current_workflow.steps.2.id'                     => 'add_note',
						'current_workflow.steps.2.params.note'            => 'Payment confirmation',
						'current_workflow.steps.3.action'                 => 'orders.compose_customer_email',
						'current_workflow.steps.4.action'                 => 'orders.send_customer_email',
						'parser_rule'                                     => 'ai.action_decomposition',
					],
				],
				[
					'domain'         => 'workflow_composition_ai',
					'name'           => 'AI action decomposition asks for missing note instead of inventing one',
					'command'        => 'Update order 6864 to processing and add note, and send an email to tell that the shipment will be delivery soon',
				'expected_stage' => 'clarify',
				'expected_source' => 'guard',
				'setup' => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.87,
							'requested_actions' => [
								[
									'id' => 'update_status_with_note',
									'label' => 'Bulk update status with note',
									'action' => 'orders.bulk_update_status_with_note',
									'confidence' => 0.91,
									'params' => [
										'order_ids' => [ 6864 ],
										'status' => 'processing',
										'note' => 'Status updated to processing',
									],
								],
								[
									'id' => 'update_status',
									'label' => 'Update order status',
									'action' => 'orders.update_status',
									'confidence' => 0.89,
									'params' => [
										'order_ids' => [ 6864 ],
										'status' => 'processing',
									],
								],
								[
									'id' => 'compose_email',
									'label' => 'Compose customer email',
									'action' => 'orders.compose_customer_email',
									'confidence' => 0.87,
									'params' => [
										'order_id' => 6864,
										'instruction' => 'Notify the customer that their shipment will be delivered soon.',
										'delivery_mode' => 'send',
									],
								],
							],
							'unsupported_requests' => [
								[
									'key' => 'shipping_label',
									'label' => 'Shipping label or shipment booking',
									'reason' => 'Not requested in user command',
								],
							],
							'missing_params' => [],
						]
					);
				},
				'teardown' => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'         => [
					'message'                                     => 'What note should I add?',
					'parsed.action'                               => 'orders.add_note',
					'parsed.clarify_reason'                       => 'missing_note',
					'parsed.composition_source'                   => 'ai_decomposition',
					'parsed.params.order_ids.0'                   => 6864,
					'parsed.params._yoro_composed_requested_actions.0.kind' => 'update_status',
					'parsed.params._yoro_composed_requested_actions.1.kind' => 'write_email',
					'parsed.missing.0'                            => 'note',
					'parsed.parser_rule'                          => 'ai.action_decomposition.missing_note',
				],
			],
			[
				'domain'          => 'workflow_composition_ai',
				'name'            => 'Missing note follow-up can chain into email clarify for non-shippable order',
				'command'         => 'Customer requested address confirmation',
				'expected_stage'  => 'clarify',
				'expected_source' => 'guard',
				'context'         => [
					'current_workflow' => [
						'type'     => 'composed_actions',
						'action'   => 'orders.add_note',
						'step'     => 'collect_missing_params',
						'order_id' => 6864,
						'params'   => [
							'order_id' => 6864,
							'order_ids' => [ 6864 ],
							'note' => '',
							'_yoro_composed_requested_actions' => [
								[
									'kind' => 'update_status',
									'action' => 'orders.update_status',
									'label' => 'Update order status',
									'position' => 0,
									'params' => [
										'status' => 'processing',
									],
								],
								[
									'kind' => 'write_email',
									'action' => 'orders.compose_customer_email',
									'label' => 'Compose customer email',
									'position' => 1,
									'params' => [
										'instruction' => 'Notify the customer that their shipment will be delivered soon.',
										'delivery_mode' => 'send',
									],
								],
							],
							'_yoro_composed_unavailable_requests' => [],
							'_yoro_composed_original_command' => 'Update order 6864 to processing and add note, and send an email to tell that the shipment will be delivery soon',
							'_yoro_composed_parser_rule' => 'ai.action_decomposition',
							'_yoro_composed_confidence' => 0.87,
						],
						'missing'  => [ 'note' ],
					],
				],
				'expect'          => [
					'message'                          => 'Order #6864 does not require shipping. What should the customer email say instead?',
					'action'                           => 'orders.compose_customer_email',
					'clarify_reason'                   => 'missing_instruction',
					'composition_source'               => 'ai_decomposition',
					'params.order_id'                  => 6864,
					'params.instruction'               => '',
					'params._yoro_composed_requested_actions.2.kind' => 'write_email',
					'params._yoro_composed_requested_actions.2.params.instruction' => 'Notify the customer that their shipment will be delivered soon.',
					'missing.0'                        => 'instruction',
					'parser_rule'                      => 'ai.action_decomposition.invalid_email_instruction',
				],
			],
			[
				'domain'          => 'workflow_composition_ai',
				'name'            => 'Missing email follow-up resumes composed workflow with replacement email instruction',
				'command'         => 'Notify the customer that the order is now processing and their download will be available soon.',
				'expected_action' => 'workflow.composed_actions',
				'expected_source' => 'guard',
				'context'         => [
					'current_workflow' => [
						'type'     => 'composed_actions',
						'action'   => 'orders.compose_customer_email',
						'step'     => 'collect_missing_params',
						'order_id' => 6864,
						'params'   => [
							'order_id' => 6864,
							'order_ids' => [ 6864 ],
							'instruction' => '',
							'_yoro_composed_requested_actions' => [
								[
									'kind' => 'update_status',
									'action' => 'orders.update_status',
									'label' => 'Update order status',
									'position' => 0,
									'params' => [
										'status' => 'processing',
									],
								],
								[
									'kind' => 'add_note',
									'action' => 'orders.add_note',
									'label' => 'Add internal note',
									'position' => 1,
									'params' => [
										'note' => 'Customer requested address confirmation',
									],
								],
								[
									'kind' => 'write_email',
									'action' => 'orders.compose_customer_email',
									'label' => 'Compose customer email',
									'position' => 2,
									'params' => [
										'instruction' => 'Notify the customer that their shipment will be delivered soon.',
										'delivery_mode' => 'send',
									],
								],
							],
							'_yoro_composed_unavailable_requests' => [],
							'_yoro_composed_original_command' => 'Update order 6864 to processing and add note, and send an email to tell that the shipment will be delivery soon',
							'_yoro_composed_parser_rule' => 'ai.action_decomposition',
							'_yoro_composed_confidence' => 0.87,
						],
						'missing'  => [ 'instruction' ],
					],
				],
				'expect'          => [
					'current_workflow.definition_id'   => 'composed_actions',
					'current_workflow.steps.1.id'      => 'update_status',
					'current_workflow.steps.2.id'      => 'add_note',
					'current_workflow.steps.3.id'      => 'compose_email',
					'current_workflow.steps.3.params.instruction' => 'Notify the customer that the order is now processing and their download will be available soon.',
					'current_workflow.steps.4.id'      => 'send_email',
					'parser_rule'                      => 'ai.action_decomposition',
				],
			],
			[
				'domain'          => 'memory_reference',
				'name'            => 'Contextual order reference prefers entity focus over current order',
				'command'         => 'Add note to this order: Check address',
				'expected_action' => 'orders.add_note',
				'context'         => [
					'current_order_id'         => 6848,
					'last_referenced_order_id' => 6848,
					'entity_focus'             => [
						'type' => 'order',
						'id'   => 6864,
					],
				],
				'expect'          => [
					'params.order_ids.0' => 6864,
					'params.order_id'    => 6864,
					'params.note'        => 'Check address',
				],
			],

			// ===== ORDER SEARCH =====
			[
				'name'            => 'Search latest completed orders',
				'command'         => 'Show me the latest 5 completed orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 5,
					'params.status.0' => 'completed',
				],
			],
			[
				'name'            => 'Search recent pending orders',
				'command'         => 'Show recent pending orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.status.0' => 'pending',
				],
			],
			[
				'name'            => 'Search latest orders keeps no default status filter',
				'command'         => 'Show me the latest 5 orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit' => 5,
				],
				'assert'          => function( $actual ) {
					$status = self::get_path( $actual, 'params.status', null );

					return [
						'latest_orders_search_has_no_status_filter' => [
							'expected' => 'no status filter',
							'actual'   => $status,
							'passed'   => empty( $status ),
						],
					];
				},
			],
			[
				'name'            => 'Search new orders does not imply pending status',
				'command'         => 'Show me 2 new orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit' => 2,
				],
				'assert'          => function( $actual ) {
					$status = self::get_path( $actual, 'params.status', null );

					return [
						'new_orders_search_has_no_status_filter' => [
							'expected' => 'no status filter',
							'actual'   => $status,
							'passed'   => empty( $status ),
						],
					];
				},
			],
			[
				'name'            => 'Status-qualified singular order request searches instead of opening current order',
				'command'         => 'Show a refunded order',
				'expected_action' => 'orders.search',
				'context'         => [
					'current_order_id' => 7133,
				],
				'expect'          => [
					'params.limit'    => 1,
					'params.status.0' => 'refunded',
				],
				'assert'          => function( $actual ) {
					$order_id = self::get_path( $actual, 'params.order_id', null );
					$order_ids = self::get_path( $actual, 'params.order_ids', null );

					return [
						'status_singular_search_does_not_reuse_current_order' => [
							'expected' => 'no grounded order id',
							'actual'   => $order_ids ?: $order_id,
							'passed'   => empty( $order_id ) && empty( $order_ids ),
						],
					];
				},
			],
			[
				'name'            => 'Refund order quantity request searches refunded orders',
				'command'         => 'Open 3 refund orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 3,
					'params.status.0' => 'refunded',
					'entities.status' => 'refunded',
				],
			],
			[
				'name'            => 'Bare quantity completed order request searches completed orders',
				'command'         => '4 completed order',
				'expected_action' => 'orders.search',
				'context'         => [
					'current_order_id' => 6954,
				],
				'expect'          => [
					'params.limit'    => 4,
					'params.status.0' => 'completed',
					'entities.status' => 'completed',
				],
				'assert'          => function( $actual ) {
					$order_id = self::get_path( $actual, 'params.order_id', null );
					$order_ids = self::get_path( $actual, 'params.order_ids', null );

					return [
						'bare_quantity_status_search_does_not_reuse_current_order' => [
							'expected' => 'no grounded order id',
							'actual'   => $order_ids ?: $order_id,
							'passed'   => empty( $order_id ) && empty( $order_ids ),
						],
					];
				},
			],
			[
				'name'            => 'Failed orders search preserves status entity metadata',
				'command'         => 'Show 2 failed orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 2,
					'params.status.0' => 'failed',
					'entities.status' => 'failed',
				],
			],
			[
				'name'            => 'Operations summary routes to operations action',
				'command'         => 'Show me the operations summary for today',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'today',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Backlog summary routes with backlog focus',
				'command'         => 'Show backlog summary for this week',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_week',
					'params.focus'  => 'backlog',
				],
			],
			[
				'name'            => 'Operations attention queue routes to operations action',
				'command'         => 'Show operations attention queue',
				'expected_action' => 'operations.get_attention_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Orders needing attention accepts limit',
				'command'         => 'Show 10 orders needing attention',
				'expected_action' => 'operations.get_attention_queue',
				'expect'          => [
					'params.limit' => 10,
				],
			],
			[
				'name'            => 'Fulfillment queue routes to operations action',
				'command'         => 'Show fulfillment queue',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Shipping queue accepts limit',
				'command'         => 'Show 7 shipping queue items',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expect'          => [
					'params.limit' => 7,
				],
			],
			[
				'name'            => 'Inventory queue routes to products action',
				'command'         => 'Show inventory queue',
				'expected_action' => 'products.get_inventory_queue',
				'expect'          => [
					'params.limit' => 5,
					'params.focus' => 'overview',
				],
			],
			[
				'name'            => 'Low stock products routes with focus',
				'command'         => 'Show 8 low stock products',
				'expected_action' => 'products.get_inventory_queue',
				'expect'          => [
					'params.limit' => 8,
					'params.focus' => 'low_stock',
				],
			],
			[
				'name'            => 'Store insights routes to store action',
				'command'         => 'Show store insights for this week',
				'expected_action' => 'store.get_insights',
				'expect'          => [
					'params.window' => 'this_week',
					'params.limit'  => 5,
				],
			],
			[
				'name'            => 'Store insights accepts monthly window and limit',
				'command'         => 'Show 8 store insights for this month',
				'expected_action' => 'store.get_insights',
				'expect'          => [
					'params.window' => 'this_month',
					'params.limit'  => 8,
				],
			],
			[
				'name'            => 'Business summary routes to business summary action',
				'command'         => 'Show me summary of the business of this month',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_month',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'How is our business doing routes to business summary',
				'command'         => 'How is our business doing this month?',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_month',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'How is our business this week routes to business summary',
				'command'         => 'How is our business this week?',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Tell me about our business this week routes to business summary',
				'command'         => 'Tell me about our business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'      => 'business_summary',
					'params.window'    => 'this_week',
					'params.focus'     => 'overview',
					'resolution_path'  => 'ai_primary',
				],
			],
			[
				'name'            => 'Tell me about the business this week routes to business summary',
				'command'         => 'Tell me about the business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Get me the business details this week routes to business summary',
				'command'         => 'Get me the business details this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Business performance routes to business summary',
				'command'         => 'Show business performance this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Business operations focus routes to business summary',
				'command'         => 'Show business summary for this week with operations focus',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
					'params.focus'  => 'operations',
				],
			],
			[
				'name'            => 'Show me the business routes to business summary',
				'command'         => 'Show me the business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Summary the business routes to business summary',
				'command'         => 'Summary the business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Whats new this week routes to operations summary',
				'command'         => 'Tell me what\'s new this week?',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'intent.name'   => 'operations_overview',
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Need restock routes through semantic inventory intent',
				'command'         => 'What products need restock?',
				'expected_action' => 'products.get_inventory_queue',
				'expect'          => [
					'intent.name'    => 'inventory_review',
					'params.limit'   => 5,
					'params.focus'   => 'overview',
				],
			],
			[
				'name'            => 'Who needs follow-up routes through semantic customer intent',
				'command'         => 'Who needs follow-up?',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'intent.name'    => 'customer_service_review',
					'params.limit'   => 5,
				],
			],
			[
				'name'            => 'How is the store doing routes to operations summary',
				'command'         => 'How is the store doing this week?',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_week',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Anything urgent routes to attention queue',
				'command'         => 'Anything urgent right now?',
				'expected_action' => 'operations.get_attention_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Customer service queue routes to customers action',
				'command'         => 'Show customer service queue',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Customer follow-up queue accepts limit',
				'command'         => 'Show 8 customers needing follow-up',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'params.limit' => 8,
				],
			],
			[
				'name'            => 'Who needs follow-up routes to customer service queue',
				'command'         => 'Who needs follow-up?',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Orders waiting to ship routes to fulfillment queue',
				'command'         => 'What orders are waiting to ship?',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expect'          => [
					'params.limit' => 5,
				],
			],
			[
				'name'            => 'Products needing restock route to inventory queue',
				'command'         => 'What products need restock?',
				'expected_action' => 'products.get_inventory_queue',
				'expect'          => [
					'params.limit' => 5,
					'params.focus' => 'overview',
				],
			],
			[
				'name'            => 'Find orders by customer email',
				'command'         => 'Find orders with email john@example.com',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.email' => 'john@example.com',
				],
			],
			[
				'name'            => 'Get customer summary by email',
				'command'         => 'Show customer summary for john@example.com',
				'expected_action' => 'customers.get_summary',
				'expect'          => [
					'params.email' => 'john@example.com',
				],
			],
			[
				'name'            => 'Find related orders by identity',
				'command'         => 'Find related orders for order #123',
				'expected_action' => 'orders.find_by_identity',
				'expect'          => [
					'params.order_id' => 123,
				],
			],
			[
				'name'            => 'Refund preview routes correctly',
				'command'         => 'Refund preview for order #123',
				'expected_action' => 'orders.refund_preview',
				'expect'          => [
					'params.order_id' => 123,
					'intent_family'   => 'refund_review',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'refund_profile_narrowed_catalog_count' => [
							'expected' => '< 4',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 4,
						],
					];
				},
			],
			[
				'name'            => 'Search on-hold orders with hyphen',
				'command'         => 'Show latest 5 on-hold orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 5,
					'params.status.0' => 'on-hold',
				],
			],
			[
				'name'            => 'Search on hold orders without hyphen',
				'command'         => 'Latest 5 order on hold?',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 5,
					'params.status.0' => 'on-hold',
				],
			],

			// ===== ORDER DETAILS =====
			[
				'name'            => 'Get order details by number',
				'command'         => 'Show order #123',
				'expected_action' => 'orders.get_details',
				'expect'          => [
					'params.order_id'    => 123,
					'params.order_ids.0' => 123,
				],
			],
			[
				'name'            => 'Get order details by plain ID',
				'command'         => 'Show order 123',
				'expected_action' => 'orders.get_details',
				'expect'          => [
					'params.order_id'    => 123,
					'params.order_ids.0' => 123,
				],
			],
			[
				'name'            => 'Single order details skips AI action decomposition',
				'command'         => 'Open order 6864',
				'expected_action' => 'orders.get_details',
				'setup'           => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.95,
							'requested_actions' => [
								[
									'id' => 'hold_order',
									'label' => 'Put order on hold',
									'action' => 'orders.hold',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ] ],
								],
								[
									'id' => 'add_note',
									'label' => 'Add internal note',
									'action' => 'orders.add_note',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ], 'note' => 'This should never be used for a single read-only lookup.' ],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown'        => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'parser_source'       => 'ai_router',
					'resolution_path'     => 'ai_primary',
					'params.order_id'     => 6864,
					'params.order_ids.0'  => 6864,
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'narrowed_catalog_count' => [
							'expected' => '< 20',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 20,
						],
					];
				},
			],
			[
				'name'            => 'Single order note skips AI action decomposition',
				'command'         => 'Add note to order 6864: Customer requested callback',
				'expected_action' => 'orders.add_note',
				'setup'           => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.95,
							'requested_actions' => [
								[
									'id' => 'hold_order',
									'label' => 'Put order on hold',
									'action' => 'orders.hold',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ] ],
								],
								[
									'id' => 'add_note',
									'label' => 'Add internal note',
									'action' => 'orders.add_note',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ], 'note' => 'This should never be used for a single note action.' ],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown'        => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'parser_source'       => 'ai_router',
					'resolution_path'     => 'ai_primary',
					'params.order_id'     => 6864,
					'params.order_ids.0'  => 6864,
					'params.note'         => 'Customer requested callback',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'note_profile_narrowed_catalog_count' => [
							'expected' => '< 10',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 10,
						],
					];
				},
			],
			[
				'name'            => 'Single order status update keeps targeted catalog profile',
				'command'         => 'Mark order 6864 as completed',
				'expected_action' => 'orders.update_status',
				'setup'           => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.95,
							'requested_actions' => [
								[
									'id' => 'hold_order',
									'label' => 'Put order on hold',
									'action' => 'orders.hold',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ] ],
								],
								[
									'id' => 'status_update',
									'label' => 'Update status',
									'action' => 'orders.update_status',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ], 'status' => 'completed' ],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown'        => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'parser_source'       => 'ai_router',
					'resolution_path'     => 'ai_primary',
					'params.order_id'     => 6864,
					'params.order_ids.0'  => 6864,
					'params.status'       => 'completed',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );

					return [
						'status_profile_narrowed_catalog_count' => [
							'expected' => '< 10',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 10,
						],
					];
				},
			],
			[
				'name'            => 'Single order email authoring skips AI action decomposition',
				'command'         => 'Write an email for order 6864 asking for payment confirmation',
				'expected_action' => 'orders.compose_customer_email',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.compose_customer_email',
					[
						'order_id'    => 6864,
						'order_ids'   => [ 6864 ],
						'instruction' => 'Ask the customer to confirm the payment.',
					],
					0.95
				),
				'setup'           => function() {
					self::enable_mock_action_decomposition(
						[
							'matched' => true,
							'confidence' => 0.95,
							'requested_actions' => [
								[
									'id' => 'hold_order',
									'label' => 'Put order on hold',
									'action' => 'orders.hold',
									'confidence' => 0.95,
									'params' => [ 'order_ids' => [ 6864 ] ],
								],
								[
									'id' => 'compose_email',
									'label' => 'Compose customer email',
									'action' => 'orders.compose_customer_email',
									'confidence' => 0.95,
									'params' => [ 'order_id' => 6864, 'instruction' => 'This should never replace the single email workflow route.' ],
								],
							],
							'unsupported_requests' => [],
							'missing_params' => [],
						]
					);
				},
				'teardown'        => function() {
					self::disable_mock_action_decomposition();
				},
				'expect'          => [
					'parser_source'       => 'ai_router',
					'parser_rule'         => 'ai.command_router.v2',
					'resolution_path'     => 'ai_primary',
					'params.order_id'     => 6864,
					'params.instruction'  => 'Ask the customer to confirm the payment.',
				],
			],

			// ===== STATUS UPDATE =====
			[
				'name'            => 'Update order status to completed',
				'command'         => 'Mark order #123 as completed',
				'expected_action' => 'orders.update_status',
				'expect'          => [
					'params.order_id'    => 123,
					'params.order_ids.0' => 123,
					'params.status'      => 'completed',
				],
			],
			[
				'name'            => 'Update order status to on-hold',
				'command'         => 'Move order #123 to on hold',
				'expected_action' => 'orders.update_status',
				'expect'          => [
					'params.status' => 'on-hold',
				],
			],
			[
				'name'            => 'Set order status using status word',
				'command'         => 'Set order #123 status to processing',
				'expected_action' => 'orders.update_status',
				'expect'          => [
					'params.order_id' => 123,
					'params.status'   => 'processing',
				],
			],
			[
				'name'            => 'AI/rule priority keeps completed as update_status',
				'command'         => 'Mark order #123 as completed',
				'expected_action' => 'orders.update_status',
				'expect'          => [
					'params.status' => 'completed',
				],
			],
			[
				'name'            => 'AI/rule correction keeps move to on hold as update_status',
				'command'         => 'Move order #123 to on hold',
				'expected_action' => 'orders.update_status',
				'expect'          => [
					'params.status' => 'on-hold',
				],
			],
			[
				'name'            => 'AI/rule priority keeps on-hold search as search',
				'command'         => 'Show latest 5 on-hold orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit'    => 5,
					'params.status.0' => 'on-hold',
				],
			],
			[
				'name'            => 'Bulk update status with note',
				'command'         => 'Update order #123 to on hold with note: Customer needs verification',
				'expected_action' => 'orders.bulk_update_status_with_note',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'    => 123,
					'params.order_ids.0' => 123,
					'params.status'      => 'on-hold',
					'params.note'        => 'Customer needs verification',
				],
			],

			// ===== ORDER NOTE =====
			[
				'name'            => 'Add note to explicit order',
				'command'         => 'Add note to order #123: test note',
				'expected_action' => 'orders.add_note',
				'expect'          => [
					'params.order_id'    => 123,
					'params.order_ids.0' => 123,
					'params.note'        => 'test note',
				],
			],

			// ===== EMAIL =====
			[
				'name'            => 'Compose customer email',
				'command'         => 'Write email for order #123 asking for confirmation',
				'expected_action' => 'orders.compose_customer_email',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'    => 123,
					'params.instruction' => 'asking for confirmation',
				],
			],
			[
				'name'            => 'AI router email clarify self-heals when instruction is already explicit',
				'command'         => 'Write me an email for order 6864 about shipping delay',
				'expected_action' => 'orders.compose_customer_email',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'    => 6864,
					'params.instruction' => 'shipping delay',
				],
				'assert'          => function( $actual ) {
					$catalog_action_count = absint( self::get_path( $actual, 'command_decision.catalog_action_count', 0 ) );
					$params_trimmed_count = absint( self::get_path( $actual, 'command_decision.params_trimmed_count', 0 ) );
					$dropped_param_keys   = self::get_path( $actual, 'command_decision.dropped_param_keys', [] );

					return [
						'email_preview_profile_narrowed_catalog_count' => [
							'expected' => '< 5',
							'actual'   => $catalog_action_count,
							'passed'   => $catalog_action_count > 0 && $catalog_action_count < 5,
						],
						'email_preview_profile_no_trimmed_template_noise' => [
							'expected' => 0,
							'actual'   => $params_trimmed_count,
							'passed'   => 0 === $params_trimmed_count,
						],
						'email_preview_profile_no_dropped_param_keys' => [
							'expected' => [],
							'actual'   => $dropped_param_keys,
							'passed'   => empty( $dropped_param_keys ),
						],
					];
				},
			],
			[
				'name'            => 'Preview verification email',
				'command'         => 'Preview verification email for order #123',
				'expected_action' => 'orders.email_customer_preview',
				'expect'          => [
					'params.order_id' => 123,
					'params.template' => 'needs_verification',
				],
			],
			[
				'name'            => 'Send verification email template',
				'command'         => 'Send verification email to customer for order #123',
				'expected_action' => 'orders.email_customer_template',
				'expect'          => [
					'params.order_id' => 123,
					'params.template' => 'needs_verification',
				],
			],

			// ===== TEMPLATE =====
			[
				'name'            => 'Create email template',
				'command'         => 'Create template named "Test" with subject "Hello" and body "Body"',
				'expected_action' => 'email_templates.save',
				'expect'          => [
					'params.name'    => 'Test',
					'params.subject' => 'Hello',
					'params.body'    => 'Body',
				],
			],

			// ===== EDGE / FALLBACK =====
			[
				'name'            => 'Latest 5 shorthand uses semantic shape search fallback',
				'command'         => 'latest 5',
				'expected_action' => 'orders.search',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_order_search',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.orders.search',
					'params.limit'    => 5,
					'command_shape.quantity_scope.scope' => 'latest',
					'command_shape.quantity_scope.value' => 5,
					'command_shape.signals.0' => 'quantity',
				],
				'assert'          => function( $actual ) {
					$status = self::get_path( $actual, 'params.status', null );

					return [
						'latest_shape_fallback_has_no_status_filter' => [
							'expected' => 'no status filter',
							'actual'   => $status,
							'passed'   => empty( $status ),
						],
					];
				},
			],
			[
				'name'            => 'Recent 3 shorthand uses semantic shape search fallback',
				'command'         => 'recent 3',
				'expected_action' => 'orders.search',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_order_search',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.orders.search',
					'params.limit'    => 3,
					'command_shape.quantity_scope.scope' => 'latest',
					'command_shape.quantity_scope.value' => 3,
				],
			],
			[
				'name'            => 'Aged pending orders without AI uses semantic shape search fallback',
				'command'         => 'Are there any orders pending for more than 3 days?',
				'expected_action' => 'orders.search',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_order_search',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.orders.search',
					'params.status.0' => 'pending',
					'params.min_age_hours' => 72,
					'command_shape.age_filter.hours' => 72,
				],
			],
			[
				'name'            => 'Operations overview without AI uses semantic read-only recovery',
				'command'         => 'What should we do now?',
				'expected_action' => 'operations.get_summary',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'semantic_read_only_fallback',
					'parser_source'   => 'semantic_recovery',
					'parser_rule'     => 'semantic.operations_overview',
					'params.window'   => 'today',
					'params.focus'    => 'overview',
				],
			],
			[
				'name'            => 'Store update without AI uses semantic read-only recovery',
				'command'         => 'Give me the store update for this week',
				'expected_action' => 'operations.get_summary',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'semantic_read_only_fallback',
					'parser_source'   => 'semantic_recovery',
					'parser_rule'     => 'semantic.operations_overview',
					'params.window'   => 'this_week',
					'params.focus'    => 'overview',
				],
			],
			[
				'name'            => 'Explicit status update without AI uses command shape write fallback',
				'command'         => 'Mark order #321 as completed',
				'expected_action' => 'orders.update_status',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_explicit_order_write',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.explicit_order_write',
					'params.order_ids.0' => 321,
					'params.status'   => 'completed',
				],
			],
			[
				'name'            => 'Explicit hold without AI uses command shape write fallback',
				'command'         => 'Hold order #321',
				'expected_action' => 'orders.hold',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_explicit_order_write',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.explicit_order_write',
					'params.order_ids.0' => 321,
					'params.order_id' => 321,
				],
			],
			[
				'name'            => 'Explicit add note without AI uses command shape write fallback',
				'command'         => 'Add note to order #321: test note',
				'expected_action' => 'orders.add_note',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_explicit_order_write',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.explicit_order_write',
					'params.order_ids.0' => 321,
					'params.order_id' => 321,
					'params.note'     => 'test note',
				],
			],
			[
				'name'            => 'Explicit add note missing content without AI uses command shape clarification',
				'command'         => 'Add note to order #321',
				'expected_action' => 'orders.add_note',
				'expect_stage'    => 'clarify',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_explicit_order_write',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.explicit_order_write',
					'missing.0'       => 'note',
					'current_workflow.type' => 'order_note',
					'current_workflow.order_id' => 321,
				],
			],
			[
				'name'            => 'Hold orders without AI uses semantic shape clarification',
				'command'         => 'Hold orders',
				'expected_action' => 'orders.hold',
				'expect_stage'    => 'clarify',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_write_missing_target',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.write_missing_target',
					'missing.0'       => 'order_ids',
					'command_shape.operation_hint' => 'write_missing_target',
					'command_shape.write_missing_target.action' => 'orders.hold',
				],
			],
			[
				'name'            => 'Add note without AI uses semantic shape clarification',
				'command'         => 'Add note to this order',
				'expected_action' => 'orders.add_note',
				'expect_stage'    => 'clarify',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally falls back to deterministic guardrails for this test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'command_shape_write_missing_target',
					'parser_source'   => 'semantic_shape',
					'parser_rule'     => 'shape.write_missing_target',
					'missing.0'       => 'order_ids',
					'missing.1'       => 'note',
					'command_shape.operation_hint' => 'write_missing_target',
					'command_shape.write_missing_target.action' => 'orders.add_note',
				],
			],

			// ===== NEGATIVE / CLARIFY =====
			[
				'name'             => 'Invalid custom status should clarify',
				'command'          => 'Move order #123 to yoro-not-existing-status',
				'expected_action'  => 'orders.update_status',
				'expect_stage'     => 'clarify',
			],
			[
				'name'             => 'Add note without note body should clarify',
				'command'          => 'Add note to order #123',
				'expected_action'  => 'orders.add_note',
				'expect_unmatched' => true,
				'expect_stage'     => 'clarify',
			],
		];

		return array_merge(
			$cases,
			self::custom_status_parser_cases()
		);
	}

	private static function evaluation_cases() {
		return [
			[
				'domain'          => 'business',
				'name'            => 'Weekly business health phrasing',
				'command'         => 'How is our business this week?',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Weekly business performance phrasing',
				'command'         => 'Show business performance this week',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Monthly business summary phrasing',
				'command'         => 'Show me summary of the business of this month',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_month',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Business update phrasing',
				'command'         => 'Give me a business update for this week',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Store performance monthly phrasing',
				'command'         => 'How is store performance this month?',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'this_month',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Brief me weekly phrasing',
				'command'         => 'Brief me this week',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Store snapshot weekly phrasing',
				'command'         => 'Give me a store snapshot for this week',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Store readout monthly phrasing',
				'command'         => 'I need the store readout for this month',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'this_month',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Weekly brief phrasing',
				'command'         => 'Give me the weekly brief',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Whats new weekly phrasing',
				'command'         => 'Tell me what\'s new this week?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Store doing weekly phrasing',
				'command'         => 'How is the store doing this week?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Relative weekly refresh clarifies safely when AI router is unavailable without context',
				'command'         => 'this week again please',
				'expected_source' => 'guard',
				'expected_stage'  => 'clarify',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally simulates unavailable AI for this guard fallback test.', 'yoro-ai-core' )
				),
			],
			[
				'domain'          => 'operations',
				'name'            => 'Operations summary refresh falls back from context when AI router is unavailable',
				'command'         => 'this week again',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type' => 'operations',
				],
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally simulates unavailable AI for this guard fallback test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'semantic_read_only_fallback',
					'params.window'   => 'this_week',
					'params.focus'    => 'overview',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Store look monthly phrasing',
				'command'         => 'What does the store look like this month?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_month',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Monthly store picture paraphrase',
				'command'         => 'Can you give me a monthly store picture?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Weekly store update phrasing',
				'command'         => 'Give me the store update for this week',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'this_week',
				],
			],
			[
				'domain'          => 'attention',
				'name'            => 'Urgent now phrasing',
				'command'         => 'Anything urgent right now?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'domain'          => 'attention',
				'name'            => 'Needs attention phrasing',
				'command'         => 'Show 10 orders needing attention',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
				'expect'          => [
					'params.limit' => 10,
				],
			],
			[
				'domain'          => 'attention',
				'name'            => 'Attention queue natural phrasing',
				'command'         => 'What needs attention today?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
			],
			[
				'domain'          => 'fulfillment',
				'name'            => 'Waiting to ship phrasing',
				'command'         => 'What orders are waiting to ship?',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'domain'          => 'fulfillment',
				'name'            => 'Shipping queue phrasing',
				'command'         => 'Show 7 shipping queue items',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expect'          => [
					'params.limit' => 7,
				],
			],
			[
				'domain'          => 'fulfillment',
				'name'            => 'Shipment blockers phrasing',
				'command'         => 'Show me shipment blockers',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
			],
			[
				'domain'          => 'fulfillment',
				'name'            => 'Stuck shipping phrasing',
				'command'         => 'What looks stuck in shipping?',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
			],
			[
				'domain'          => 'fulfillment',
				'name'            => 'Blocked fulfillment paraphrase',
				'command'         => 'What seems blocked in fulfillment?',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
			],
			[
				'domain'          => 'inventory',
				'name'            => 'Need restock phrasing',
				'command'         => 'What products need restock?',
				'expected_intent' => 'inventory_review',
				'expected_action' => 'products.get_inventory_queue',
			],
			[
				'domain'          => 'inventory',
				'name'            => 'Low stock phrasing',
				'command'         => 'Show 8 low stock products',
				'expected_intent' => 'inventory_review',
				'expected_action' => 'products.get_inventory_queue',
				'expect'          => [
					'params.limit' => 8,
				],
			],
			[
				'domain'          => 'inventory',
				'name'            => 'Restock list phrasing',
				'command'         => 'What should we restock first?',
				'expected_intent' => 'inventory_review',
				'expected_action' => 'products.get_inventory_queue',
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Follow-up phrasing',
				'command'         => 'Who needs follow-up?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Customer follow-up list phrasing',
				'command'         => 'Show 8 customers needing follow-up',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'params.limit' => 8,
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Customer issues phrasing',
				'command'         => 'Which customers need a reply?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Reply priority phrasing',
				'command'         => 'Who should we reply to first?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Answer priority paraphrase',
				'command'         => 'Who should we answer first today?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
			],
			[
				'domain'          => 'orders',
				'name'            => 'Recent pending orders search',
				'command'         => 'Show recent pending orders',
				'expected_action' => 'orders.search',
			],
			[
				'domain'          => 'orders',
				'name'            => 'Order details direct lookup',
				'command'         => 'Show order #123',
				'expected_action' => 'orders.get_details',
			],
			[
				'domain'          => 'orders_followup',
				'name'            => 'Follow-up first order after list context',
				'command'         => 'Show the first one',
				'expected_action' => 'orders.get_details',
				'context'         => [
					'last_orders'    => [
						[ 'id' => 101, 'number' => '101' ],
						[ 'id' => 102, 'number' => '102' ],
					],
					'last_order_ids' => [ 101, 102 ],
				],
				'expect'          => [
					'params.order_id'    => 101,
					'params.order_ids.0' => 101,
				],
			],
			[
				'domain'          => 'orders_followup',
				'name'            => 'Follow-up third order after list context',
				'command'         => 'Open the third one',
				'expected_action' => 'orders.get_details',
				'context'         => [
					'last_orders'    => [
						[ 'id' => 101, 'number' => '101' ],
						[ 'id' => 102, 'number' => '102' ],
						[ 'id' => 103, 'number' => '103' ],
					],
					'last_order_ids' => [ 101, 102, 103 ],
				],
				'expect'          => [
					'params.order_id'    => 103,
					'params.order_ids.0' => 103,
				],
			],
			[
				'domain'          => 'orders',
				'name'            => 'Open email drafts for order routes to drafts action',
				'command'         => 'Open email drafts for order 6864',
				'expected_action' => 'orders.get_customer_email_drafts',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'task_request',
					'orders.get_customer_email_drafts',
					[
						'order_id' => 6864,
					],
					0.95
				),
				'expect'          => [
					'params.order_id'                    => 6864,
					'params.order_ids.0'                 => 6864,
					'intent_family'                      => 'customer_email',
					'command_decision.catalog_action_count' => 1,
				],
			],
			[
				'domain'          => 'customers',
				'name'            => 'Customer summary by email',
				'command'         => 'Show customer summary for john@example.com',
				'expected_action' => 'customers.get_summary',
			],
			[
				'domain'          => 'write_actions',
				'name'            => 'Write action with direct order status change',
				'command'         => 'Mark order #123 as completed',
				'expected_action' => 'orders.update_status',
			],
				[
					'domain'          => 'write_actions',
					'name'            => 'Contextual write follow-up',
					'command'         => 'Hold the first one',
					'expected_action' => 'orders.hold',
					'expected_source' => 'guard',
					'context'         => [
						'last_orders'    => [
							[ 'id' => 301 ],
							[ 'id' => 302 ],
						],
						'last_order_ids' => [ 301, 302 ],
					],
					'expect'          => [
						'params.order_id' => 301,
						'parser_rule'     => 'guard.follow_up.orders.hold',
					],
				],
			[
				'domain'          => 'clarify',
				'name'            => 'Missing note body should clarify',
				'command'         => 'Add note to order #123',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'clarify',
				'name'            => 'Missing context hold should clarify',
				'command'         => 'Hold this order',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
		];
	}

	private static function real_query_hardening_cases() {
		return [
			[
				'domain'          => 'unsupported_analytics',
				'name'            => 'VIP customers by spend should not fake business summary',
				'command'         => 'Show me VIP customers who spent more than $1,000.',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'unsupported_analytics',
				'name'            => 'Website conversion rate should not fake business summary',
				'command'         => 'Show me the website conversion rate for the past 30 days.',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'orders',
				'name'            => 'Aged pending orders should route to filtered order search',
				'command'         => 'Are there any orders pending for more than 3 days?',
				'expected_intent' => 'search_orders',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.status.0'      => 'pending',
					'params.min_age_hours' => 72,
				],
			],
			[
				'domain'          => 'unsupported_analytics',
				'name'            => 'Best-selling products should not fake fulfillment review',
				'command'         => 'What are my best-selling products in the last 7 days?',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'operations',
				'name'            => 'What should we do today should route to operations overview',
				'command'         => 'What should we do today?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Broken English what should we do today should still route to operations overview',
				'command'         => 'What\'s we should do today?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'domain'          => 'orders',
				'name'            => 'On-hold orders should be order search not order details',
				'command'         => 'Show on-hold orders',
				'expected_intent' => 'search_orders',
				'expected_action' => 'orders.search',
			],
			[
				'domain'          => 'operations',
				'name'            => 'Whats going on today should not fall into workflow',
				'command'         => 'What\'s going on today?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'domain'          => 'unsupported_analytics',
				'name'            => 'Missing confirmation email audit should not fall into workflow',
				'command'         => 'Did any customers complete checkout but not receive a confirmation email?',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Monthly brief alias should route to store briefing',
				'command'         => 'Show me the brief of this month',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'this_month',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Weekly store snapshot should route to store briefing',
				'command'         => 'Give me a weekly store snapshot',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Weekly readout should route to store briefing',
				'command'         => 'What is the store readout for this week?',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Brief me this week should route to store briefing',
				'command'         => 'Brief me this week',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Brief me this week clarifies safely when AI router is unavailable without context',
				'command'         => 'this week please',
				'expected_source' => 'guard',
				'expected_stage'  => 'clarify',
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally simulates unavailable AI for this guard fallback test.', 'yoro-ai-core' )
				),
			],
			[
				'domain'          => 'briefing',
				'name'            => 'Briefing refresh falls back from context when AI router is unavailable',
				'command'         => 'this month',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type' => 'briefing',
				],
				'router_fixture'  => new WP_Error(
					'yoro_ai_missing_api_key',
					__( 'AI router fixture intentionally simulates unavailable AI for this guard fallback test.', 'yoro-ai-core' )
				),
				'expect'          => [
					'resolution_path' => 'recovery_fallback',
					'fallback_reason' => 'semantic_read_only_fallback',
					'params.window'   => 'this_month',
				],
			],
			[
				'domain'          => 'orchestrator_briefing',
				'name'            => 'Briefing continue plan should stay inside briefing sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'      => 'briefing',
					'active_followup_type'  => 'briefing',
					'last_briefing'         => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
							[
								'label'   => 'Check customer follow-up',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.briefing.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_customer_service',
				'name'            => 'Customer service continue plan should stay inside service sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'customer_service',
					'active_followup_type'    => 'customer_service',
					'last_service_followup'   => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the oldest on-hold follow-ups',
								'command' => 'Show customer service queue',
							],
							[
								'label'   => 'Review pending payment follow-ups',
								'command' => 'Show pending orders',
							],
							[
								'label'   => 'Review broader operations summary',
								'command' => 'Show business summary for this week',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.service.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_fulfillment',
				'name'            => 'Fulfillment continue plan should stay inside fulfillment sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'active_followup_type'      => 'fulfillment',
					'last_fulfillment_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Check missing tracking first',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review aging processing orders',
								'command' => 'Show processing orders',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.fulfillment.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_inventory',
				'name'            => 'Inventory continue plan should stay inside inventory sequence after fulfillment step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'active_followup_type'    => 'inventory',
					'last_inventory_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the top restock priorities',
								'command' => 'What should we restock first?',
							],
							[
								'label'   => 'Check fulfillment pressure next',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review the broader store picture',
								'command' => 'Can you give me a monthly store picture?',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.inventory.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_operations_attention',
				'name'            => 'Operations attention continue plan should stay inside attention sequence after fulfillment step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'active_followup_type'     => 'operations_attention',
					'last_attention_followup'  => [
						'recommended_sequence' => [
							[
								'label'   => 'Review pending payment orders',
								'command' => 'Search pending orders',
							],
							[
								'label'   => 'Check aging processing orders',
								'command' => 'Show 5 processing orders',
							],
							[
								'label'   => 'Check customer follow-up next',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.attention.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_business',
				'name'            => 'Business continue plan should stay inside business sequence after inventory step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'inventory',
					'active_followup_type'    => 'business',
					'last_business_followup'  => [
						'recommended_sequence' => [
							[
								'label'   => 'Review weekly operations summary',
								'command' => 'Show operations summary for this week',
							],
							[
								'label'   => 'Check inventory pressure',
								'command' => 'Show inventory queue',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.business.recommendation',
				],
			],
				[
					'domain'          => 'phase4_fulfillment_workflow',
					'name'            => 'Prepare next fulfillment follow-up should route to workflow preview when fulfillment context is present',
					'command'         => 'Prepare the next fulfillment follow-up',
					'expected_action' => 'workflow.fulfillment_followup_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'last_fulfillment_followup' => [
						'queues' => [
							[
								'key'    => 'processing_without_tracking',
								'label'  => 'Processing without tracking',
								'orders' => [
									[
										'id'                => 6685,
										'number'            => '6685',
										'status'            => 'processing',
										'billing_email'     => 'j***n@mail.com',
										'shipping_name'     => 'John Doe',
										'tracking_detected' => false,
									],
								],
							],
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.fulfillment_followup_preview',
					],
				],
			[
				'domain'          => 'phase4_fulfillment_workflow',
				'name'            => 'Prepare next fulfillment follow-up should clarify when fulfillment context is missing',
				'command'         => 'Prepare the next fulfillment follow-up',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'phase4_fulfillment_workflow',
				'name'            => 'AI router fulfillment clarify should auto-route when fulfillment context is present',
				'command'         => 'Prepare the next fulfillment follow-up via AI router',
				'expected_action' => 'workflow.fulfillment_followup_preview',
				'expected_source' => 'ai_router',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'active_followup_type'      => 'fulfillment',
					'last_fulfillment_followup' => [
						'queues' => [
							[
								'key'    => 'processing_without_tracking',
								'label'  => 'Processing without tracking',
								'orders' => [
									[
										'id'                => 6685,
										'number'            => '6685',
										'status'            => 'processing',
										'billing_email'     => 'j***n@mail.com',
										'shipping_name'     => 'John Doe',
										'tracking_detected' => false,
									],
								],
							],
						],
					],
				],
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'workflow.fulfillment_followup_preview',
					[],
					0.6,
					[ 'queue_key' ],
					'What is the fulfillment queue_key for the next follow-up you want me to prepare?'
				),
				'expect'          => [
					'parser_rule'                              => 'ai.command_router.v2',
					'params.original_command'                 => 'Prepare the next fulfillment follow-up via AI router',
					'command_decision.mode'                   => 'task_request',
					'command_decision.missing'                => [],
					'command_decision.clarification_question' => '',
				],
			],
			[
				'domain'          => 'phase4_fulfillment_workflow',
				'name'            => 'Fulfillment follow-up preview should not inherit current order context',
				'command'         => 'Prepare the next fulfillment follow-up without leaking current order',
				'expected_action' => 'workflow.fulfillment_followup_preview',
				'expected_source' => 'ai_router',
				'context'         => [
					'current_order_id'          => 6860,
					'last_referenced_order_id'  => 6860,
					'last_result_type'          => 'fulfillment',
					'active_followup_type'      => 'fulfillment',
					'last_fulfillment_followup' => [
						'queues' => [
							[
								'key'    => 'processing_without_tracking',
								'label'  => 'Processing without tracking',
								'orders' => [
									[
										'id'                => 6685,
										'number'            => '6685',
										'status'            => 'processing',
										'billing_email'     => 'j***n@mail.com',
										'shipping_name'     => 'John Doe',
										'tracking_detected' => false,
									],
								],
							],
						],
					],
				],
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'workflow.fulfillment_followup_preview',
					[],
					0.6,
					[ 'queue_key' ],
					'What is the fulfillment queue_key for the next follow-up you want me to prepare?'
				),
				'expect'          => [
					'params.order_id'   => null,
					'params.order_ids'  => null,
					'entities.order_id' => null,
				],
			],
			[
				'domain'          => 'phase4_fulfillment_workflow',
				'name'            => 'AI router fulfillment clarify should rewrite internal queue key prompt when context is missing',
				'command'         => 'Prepare the next fulfillment follow-up via AI router with no context',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'workflow.fulfillment_followup_preview',
					[],
					0.6,
					[ 'queue_key' ],
					'What is the fulfillment queue_key for the next follow-up you want me to prepare?'
				),
				'expect'          => [
					'message'                                => 'Open the fulfillment queue first so I can choose the right fulfillment item before preparing a follow-up.',
					'command_decision.mode'                  => 'clarify',
					'command_decision.missing'               => [],
					'command_decision.clarification_question' => 'Open the fulfillment queue first so I can choose the right fulfillment item before preparing a follow-up.',
				],
			],
				[
					'domain'          => 'phase4_customer_followup_workflow',
					'name'            => 'Prepare next customer follow-up should route to workflow preview when service context is present',
					'command'         => 'Prepare the next customer follow-up',
					'expected_action' => 'workflow.customer_followup_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'customer_service',
					'last_service_followup'   => [
						'queues' => [
							[
								'key'    => 'saved_email_drafts',
								'label'  => 'Saved drafts',
								'orders' => [
									[
										'id'            => 6458,
										'number'        => '6458',
										'status'        => 'processing',
										'billing_email' => 'j***x@mail.com',
										'customer_name' => 'Jane Doe',
									],
								],
							],
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.customer_followup_preview',
					],
				],
			[
				'domain'          => 'phase4_customer_followup_workflow',
				'name'            => 'Prepare next customer follow-up should clarify when service context is missing',
				'command'         => 'Prepare the next customer follow-up',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
				[
					'domain'          => 'phase4_operations_attention_workflow',
					'name'            => 'Prepare next attention follow-up should route to workflow preview when attention context is present',
					'command'         => 'Prepare the next attention follow-up',
					'expected_action' => 'workflow.operations_attention_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'         => 'operations_attention',
					'last_attention_followup'  => [
						'queues' => [
							[
								'key'    => 'pending_payment_older_than_24h',
								'label'  => 'Pending payment older than 24h',
								'orders' => [
									[
										'id'            => 6683,
										'number'        => '6683',
										'status'        => 'pending',
										'billing_email' => 'j***n@mail.com',
									],
								],
							],
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.operations_attention_preview',
					],
				],
			[
				'domain'          => 'phase4_operations_attention_workflow',
				'name'            => 'Prepare next attention follow-up should clarify when attention context is missing',
				'command'         => 'Prepare the next attention follow-up',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
				[
					'domain'          => 'phase4_inventory_pressure_workflow',
					'name'            => 'Prepare next inventory review should route to workflow preview when inventory context is present',
					'command'         => 'Prepare the next inventory review',
					'expected_action' => 'workflow.inventory_pressure_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'inventory',
					'last_inventory_followup' => [
						'queues' => [
							[
								'key'      => 'out_of_stock',
								'label'    => 'Out of stock',
								'products' => [
									[
										'id'             => 101,
										'name'           => 'Demo Product',
										'sku'            => 'SKU-101',
										'stock_status'   => 'outofstock',
										'stock_quantity' => 0,
									],
								],
							],
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.inventory_pressure_preview',
					],
				],
			[
				'domain'          => 'phase4_inventory_pressure_workflow',
				'name'            => 'Prepare next inventory review should clarify when inventory context is missing',
				'command'         => 'Prepare the next inventory review',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
			[
				'domain'          => 'phase4_inventory_pressure_workflow',
				'name'            => 'AI router inventory clarify should rewrite internal topic key prompt when context is missing',
				'command'         => 'Prepare the next inventory review via AI router with no context',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
				'expected_source' => 'ai_router',
				'router_fixture'  => self::base_router_fixture_decision(
					'clarify',
					'workflow.inventory_pressure_preview',
					[],
					0.6,
					[ 'topic_key' ],
					'What is the inventory topic_key for the next review you want me to prepare?'
				),
				'expect'          => [
					'message'                                => 'Open the inventory queue first so I can choose the right stock issue before preparing the next review step.',
					'command_decision.mode'                  => 'clarify',
					'command_decision.missing'               => [],
					'command_decision.clarification_question' => 'Open the inventory queue first so I can choose the right stock issue before preparing the next review step.',
				],
			],
				[
					'domain'          => 'phase4_inventory_pressure_workflow',
					'name'            => 'Prepare next inventory review alias should route to inventory pressure preview when inventory context is present',
					'command'         => 'Review the next inventory recommendation',
					'expected_action' => 'workflow.inventory_pressure_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'inventory',
					'last_inventory_followup' => [
						'queues' => [
							[
								'key'      => 'out_of_stock',
								'label'    => 'Out of stock',
								'products' => [
									[
										'id'             => 101,
										'name'           => 'Demo Product',
										'sku'            => 'SKU-101',
										'stock_status'   => 'outofstock',
										'stock_quantity' => 0,
									],
								],
							],
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.inventory_pressure_preview',
					],
				],
			[
				'domain'          => 'phase4_inventory_pressure_workflow',
				'name'            => 'Prepare next inventory review alias should clarify when inventory context is missing',
				'command'         => 'Review the next inventory recommendation',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
				[
					'domain'          => 'phase4_business_summary_workflow',
					'name'            => 'Prepare next business review should route to business summary preview when business context is present',
					'command'         => 'Prepare the next business review',
					'expected_action' => 'workflow.business_summary_preview',
					'expected_source' => 'guard',
				'context'         => [
					'last_result_type'        => 'business',
					'last_business_followup'  => [
						'priority_items' => [
							[
								'topic'   => 'operations_pressure',
								'title'   => 'Operational pressure is building',
								'message' => 'Backlog and attention load are rising.',
								'tone'    => 'warning',
							],
						],
						'recommended_sequence' => [
							[
								'topic'   => 'operations_attention',
								'label'   => 'Open the attention queue first',
								'command' => 'Show operations attention queue',
								'reason'  => 'Start here when the live queue is overshadowing the commercial picture.',
							],
						],
						'business_context' => [
							'top_topic'   => 'operations_pressure',
							'window_key'  => 'last_7_days',
							'focus'       => 'overview',
							'order_count' => 25,
						],
					],
					],
					'expect'          => [
						'parser_rule' => 'guard.workflow.business_summary_preview',
					],
				],
			[
				'domain'          => 'phase4_business_summary_workflow',
				'name'            => 'Prepare next business review should clarify when business context is missing',
				'command'         => 'Prepare the next business review',
				'expected_action' => null,
				'expected_stage'  => 'clarify',
			],
		];
	}

	private static function real_traffic_eval_cases() {
		return [
			[
				'pack'            => 'real_traffic',
				'domain'          => 'briefing',
				'name'            => 'Morning briefing request stays on briefing surface',
				'command'         => 'Show morning briefing',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_morning_briefing',
				'expected_source' => 'ai_router',
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'briefing',
				'name'            => 'Daily briefing request keeps briefing contract',
				'command'         => 'Show today\'s briefing',
				'expected_intent' => 'store_briefing',
				'expected_action' => 'store.get_daily_briefing',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'operations',
				'name'            => 'Operations summary request stays minimal',
				'command'         => 'Show operations summary for today',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'operations',
				'name'            => 'What should we do today maps to operations summary',
				'command'         => 'What should we do today?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.window' => 'today',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'attention',
				'name'            => 'Urgent now queue request stays on AI primary attention route',
				'command'         => 'Anything urgent right now?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'attention',
				'name'            => 'Manual review paraphrase stays on AI primary attention route',
				'command'         => 'Which orders need manual review first?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'fulfillment',
				'name'            => 'Waiting to ship request stays on AI primary fulfillment route',
				'command'         => 'What orders are waiting to ship?',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'fulfillment',
				'name'            => 'Shipment blockers request stays on AI primary fulfillment route',
				'command'         => 'Show me shipment blockers',
				'expected_intent' => 'fulfillment_review',
				'expected_action' => 'operations.get_fulfillment_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'customer_service',
				'name'            => 'Follow-up request stays on AI primary customer service route',
				'command'         => 'Who needs follow-up?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'customer_service',
				'name'            => 'Need a reply phrasing stays on AI primary customer service route',
				'command'         => 'Which customers need a reply?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'customer_service',
				'name'            => 'Answer first phrasing stays on AI primary customer service route',
				'command'         => 'Who should we answer first today?',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'customer_service',
				'name'            => 'Reply queue request keeps AI primary with explicit shortlist',
				'command'         => 'Show 8 customers needing follow-up',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.limit'    => 8,
					'resolution_path' => 'ai_primary',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'email',
				'name'            => 'Compose customer email from explicit goal',
				'command'         => 'Write me an email for order 6864 about shipping delay',
				'expected_intent' => 'customer_email',
				'expected_action' => 'orders.compose_customer_email',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.order_id'    => 6864,
					'params.instruction' => 'shipping delay',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'email',
				'name'            => 'Email compose clarify asks only for missing instruction',
				'command'         => 'Write an email for order 6865',
				'expected_action' => 'orders.compose_customer_email',
				'expected_source' => 'ai_router',
				'expected_stage'  => 'clarify',
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'status_update',
				'name'            => 'Completed status update keeps direct action',
				'command'         => 'Mark order #123 as completed',
				'expected_intent' => 'update_order_status',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.status' => 'completed',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'status_update',
				'name'            => 'On hold request keeps direct action',
				'command'         => 'Move order #123 to on hold',
				'expected_intent' => 'update_order_status',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.status' => 'on-hold',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'status_update',
				'name'            => 'Status and note request keeps combined action',
				'command'         => 'Mark order #123 as completed with note: packed and verified',
				'expected_intent' => 'update_order_status',
				'expected_action' => 'orders.bulk_update_status_with_note',
				'expected_source' => 'ai_router',
				'expect'          => [
					'params.status' => 'completed',
					'params.note'   => 'packed and verified',
				],
			],
			[
				'pack'            => 'real_traffic',
				'domain'          => 'status_update',
				'name'            => 'Missing status clarifies instead of guessing',
				'command'         => 'Update order 6865 status',
				'expected_action' => 'orders.update_status',
				'expected_source' => 'ai_router',
				'expected_stage'  => 'clarify',
			],
		];
	}

	private static function multilingual_representative_eval_cases() {
		return [
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'briefing',
				'name'                   => 'Vietnamese briefing contract',
				'command'                => 'Cho tôi xem briefing hôm nay',
				'expected_language'      => 'vi',
				'expected_intent'        => 'store_briefing',
				'expected_intent_family' => 'store_briefing',
				'expected_action'        => 'store.get_daily_briefing',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'vi', 'task_request', 'store.get_daily_briefing', [ 'window' => 'today' ], 'store_briefing', 0.96 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'business',
				'name'                   => 'Spanish business summary contract',
				'command'                => 'Muéstrame el resumen del negocio de esta semana',
				'expected_language'      => 'es',
				'expected_intent'        => 'business_summary',
				'expected_intent_family' => 'business_summary',
				'expected_action'        => 'store.get_business_summary',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'es', 'task_request', 'store.get_business_summary', [ 'window' => 'this_week', 'focus' => 'overview' ], 'business_summary', 0.96 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'business',
				'name'                   => 'Vietnamese business health contract',
				'command'                => 'Tình hình kinh doanh dạo này như thế nào?',
				'expected_language'      => 'vi',
				'expected_intent'        => 'business_summary',
				'expected_intent_family' => 'business_summary',
				'expected_action'        => 'store.get_business_summary',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'vi', 'task_request', 'store.get_business_summary', [ 'window' => 'last_7_days', 'focus' => 'overview' ], 'business_summary', 0.96 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'orders',
				'name'                   => 'Vietnamese completed order search semantic frame contract',
				'command'                => 'Cho tôi xem 5 đơn hàng đã hoàn thành gần đây',
				'expected_language'      => 'vi',
				'expected_intent'        => 'search_orders',
				'expected_intent_family' => 'search_orders',
				'expected_action'        => 'orders.search',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture(
					'vi',
					'task_request',
					'orders.search',
					[
						'limit'       => 5,
						'date_window' => 'last_7_days',
					],
					'search_orders',
					0.9,
					[],
					'',
					[
						'version' => 1,
						'surface' => 'orders',
						'operation' => 'search',
						'target_type' => 'order_list',
						'constraints' => [
							'quantity' => 5,
							'order_id' => null,
							'order_ids' => [],
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
							'order_id' => null,
							'quantity' => '5',
							'status' => 'đã hoàn thành',
							'date' => 'gần đây',
							'money' => null,
							'customer' => null,
							'payment' => null,
							'shipping' => null,
						],
						'confidence' => 0.9,
					]
				),
				'expect'                 => [
					'params.status.0'                             => 'completed',
					'params.limit'                                => 5,
					'params.date_window'                          => 'last_7_days',
					'semantic_frame.surface'                      => 'orders',
					'semantic_frame.operation'                    => 'search',
					'semantic_frame.constraints.quantity'          => 5,
					'semantic_frame.constraints.status'            => 'completed',
					'semantic_frame.constraints.date.window'       => 'last_7_days',
					'semantic_frame_validator_changes.0.param'    => 'status',
				],
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'business',
				'name'                   => 'Spanish business health question contract',
				'command'                => '¿Cómo va el negocio esta semana?',
				'expected_language'      => 'es',
				'expected_intent'        => 'business_summary',
				'expected_intent_family' => 'business_summary',
				'expected_action'        => 'store.get_business_summary',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'es', 'task_request', 'store.get_business_summary', [ 'window' => 'this_week', 'focus' => 'overview' ], 'business_summary', 0.96 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'operations',
				'name'                   => 'French operations overview contract',
				'command'                => 'Montre-moi le résumé des opérations pour aujourd’hui',
				'expected_language'      => 'fr',
				'expected_intent'        => 'operations_overview',
				'expected_intent_family' => 'operations_overview',
				'expected_action'        => 'operations.get_summary',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'fr', 'task_request', 'operations.get_summary', [ 'window' => 'today' ], 'operations_overview', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'attention',
				'name'                   => 'German attention queue contract',
				'command'                => 'Welche Bestellungen brauchen sofort Aufmerksamkeit?',
				'expected_language'      => 'de',
				'expected_intent'        => 'operations_attention',
				'expected_intent_family' => 'operations_attention',
				'expected_action'        => 'operations.get_attention_queue',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'de', 'task_request', 'operations.get_attention_queue', [ 'limit' => 5 ], 'operations_attention', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'customer_service',
				'name'                   => 'Japanese customer service queue contract',
				'command'                => '返信が必要なお客様を見せてください',
				'expected_language'      => 'ja',
				'expected_intent'        => 'customer_service_review',
				'expected_intent_family' => 'customer_service_review',
				'expected_action'        => 'customers.get_service_queue',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'ja', 'task_request', 'customers.get_service_queue', [ 'limit' => 5 ], 'customer_service_review', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'fulfillment',
				'name'                   => 'Arabic fulfillment queue contract',
				'command'                => 'اعرض الطلبات التي تنتظر الشحن',
				'expected_language'      => 'ar',
				'expected_intent'        => 'fulfillment_review',
				'expected_intent_family' => 'fulfillment_review',
				'expected_action'        => 'operations.get_fulfillment_queue',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'ar', 'task_request', 'operations.get_fulfillment_queue', [ 'limit' => 5 ], 'fulfillment_review', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'inventory',
				'name'                   => 'Thai inventory queue contract',
				'command'                => 'สินค้าตัวไหนต้องรีสต็อกก่อน',
				'expected_language'      => 'th',
				'expected_intent'        => 'inventory_review',
				'expected_intent_family' => 'inventory_review',
				'expected_action'        => 'products.get_inventory_queue',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'th', 'task_request', 'products.get_inventory_queue', [ 'limit' => 5, 'focus' => 'overview' ], 'inventory_review', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'status_update',
				'name'                   => 'Portuguese status update contract',
				'command'                => 'Marque o pedido #123 como concluído',
				'expected_language'      => 'pt',
				'expected_intent'        => 'update_order_status',
				'expected_intent_family' => 'update_order_status',
				'expected_action'        => 'orders.update_status',
				'expected_source'        => 'ai_router',
				'router_fixture'         => self::multilingual_router_fixture( 'pt', 'task_request', 'orders.update_status', [ 'order_ids' => [ 123 ], 'status' => 'completed' ], 'update_order_status', 0.95 ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'email',
				'name'                   => 'Indonesian customer email clarify contract',
				'command'                => 'Tulis email untuk pesanan 6865',
				'expected_language'      => 'id',
				'expected_action'        => 'orders.compose_customer_email',
				'expected_source'        => 'ai_router',
				'expected_stage'         => 'clarify',
				'router_fixture'         => self::multilingual_router_fixture( 'id', 'clarify', 'orders.compose_customer_email', [ 'order_id' => 6865 ], 'customer_email', 0.93, [ 'instruction' ], 'Apa tujuan email untuk pelanggan ini?' ),
			],
			[
				'pack'                   => 'multilingual_representative',
				'domain'                 => 'email',
				'name'                   => 'Multi-order email limit clarify contract',
				'command'                => 'Write email about shipping update for these six orders',
				'expected_action'        => 'workflow.write_email_for_orders',
				'expected_source'        => 'ai_router',
				'expected_stage'         => 'clarify',
				'context'                => [
					'last_order_ids' => [ 7140, 7139, 7138, 7137, 7136, 7135 ],
				],
				'router_fixture'         => self::base_router_fixture_decision(
					'task_request',
					'workflow.write_email_for_orders',
					[
						'order_ids'     => [ 7140, 7139, 7138, 7137, 7136, 7135 ],
						'instruction'   => 'shipping update',
						'delivery_mode' => 'preview',
					],
					0.88
				),
			],
		];
	}

	private static function pipeline_cases() {
		return [
				[
					'name'            => 'Follow-up first one resolves to first last_order_id',
					'command'         => 'Hold the first one',
					'expected_action' => 'orders.hold',
					'context'         => [
						'last_orders'    => [
							[ 'id' => 101 ],
							[ 'id' => 102 ],
						],
						'last_order_ids' => [ 101, 102 ],
					],
					'expect'          => [
						'params.order_id'    => 101,
						'params.order_ids.0' => 101,
						'parser_rule'        => 'guard.follow_up.orders.hold',
					],
				],
			[
				'name'            => 'Follow-up add note to first one',
				'command'         => 'Add note to the first one: test note',
				'expected_action' => 'orders.add_note',
				'context'         => [
					'last_orders'    => [
						[ 'id' => 101 ],
						[ 'id' => 102 ],
					],
					'last_order_ids' => [ 101, 102 ],
				],
				'expect'          => [
					'params.order_id'    => 101,
					'params.order_ids.0' => 101,
					'params.note'        => 'test note',
				],
			],
			[
				'name'            => 'Follow-up all of them resolves all last orders',
				'command'         => 'Mark all of them on hold',
				'expected_action' => 'orders.update_status',
				'context'         => [
					'last_orders'    => [
						[ 'id' => 101 ],
						[ 'id' => 102 ],
					],
					'last_order_ids' => [ 101, 102 ],
				],
				'expect'          => [
					'params.order_ids.0' => 101,
					'params.order_ids.1' => 102,
					'params.status'      => 'on-hold',
				],
			],
			[
				'name'            => 'Follow-up mark all as completed',
				'command'         => 'Mark all of them as completed',
				'expected_action' => 'orders.update_status',
				'context'         => [
					'last_orders'    => [
						[ 'id' => 101 ],
						[ 'id' => 102 ],
					],
					'last_order_ids' => [ 101, 102 ],
				],
				'expect'          => [
					'params.order_ids.0' => 101,
					'params.order_ids.1' => 102,
					'params.status'      => 'completed',
				],
			],
			[
				'name'            => 'This order resolves current order ID',
				'command'         => 'Add note to this order: checked',
				'expected_action' => 'orders.add_note',
				'context'         => [
					'current_order_id'         => 456,
					'last_referenced_order_id' => 456,
				],
				'expect'          => [
					'params.order_id'    => 456,
					'params.order_ids.0' => 456,
					'params.note'        => 'checked',
				],
			],
			[
				'name'            => 'Move this order to on hold resolves current order ID',
				'command'         => 'Move this order to on hold',
				'expected_action' => 'orders.update_status',
				'context'         => [
					'current_order_id'         => 456,
					'last_referenced_order_id' => 456,
				],
				'expect'          => [
					'params.order_id'    => 456,
					'params.order_ids.0' => 456,
					'params.status'      => 'on-hold',
				],
			],
			[
				'name'            => 'Do same for other orders falls back to recent order history',
				'command'         => 'Do the same for the other orders',
				'expected_action' => 'orders.update_status',
				'context'         => [
					'current_order_id'         => 6546,
					'last_referenced_order_id' => 6546,
					'last_order_ids'           => [ 6546 ],
					'recent_order_ids'         => [ 6546, 6543 ],
					'last_result'              => [
						'action' => 'orders.update_status',
					],
					'last_action_params'       => [
						'order_id'  => 6546,
						'order_ids' => [ 6546 ],
						'status'    => 'completed',
					],
				],
				'expect'          => [
					'params.order_ids.0' => 6543,
					'params.status'      => 'completed',
				],
			],
			[
				'name'            => 'Briefing follow-up open first one resolves top drilldown',
				'command'         => 'Open the first one',
				'expected_action' => 'operations.get_attention_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'priority_drilldowns' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.briefing.drilldown',
				],
			],
			[
				'name'            => 'Briefing follow-up open second one resolves next drilldown',
				'command'         => 'Show the second one',
				'expected_action' => 'operations.get_fulfillment_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'priority_drilldowns' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.briefing.drilldown',
				],
			],
			[
				'name'            => 'Briefing follow-up do first recommendation resolves first sequence item',
				'command'         => 'Do the first recommendation',
				'expected_action' => 'operations.get_attention_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.briefing.recommendation',
				],
			],
			[
				'name'            => 'Briefing follow-up run second recommendation resolves next sequence item',
				'command'         => 'Run the second recommendation',
				'expected_action' => 'operations.get_fulfillment_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule' => 'guard.follow_up.briefing.recommendation',
				],
			],
			[
				'name'            => 'Briefing follow-up next recommendation advances from cursor',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'operations.get_fulfillment_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
							[
								'label'   => 'Check customer follow-up',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 0,
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.briefing.recommendation',
					'briefing_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Briefing follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'operations.get_fulfillment_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
							[
								'label'   => 'Check customer follow-up',
								'command' => 'Show customer service queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.briefing.recommendation',
					'briefing_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Briefing follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'customers.get_service_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
							[
								'label'   => 'Check customer follow-up',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.briefing.recommendation',
					'briefing_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Briefing follow-up continue plan starts from first item when no cursor exists',
				'command'         => 'Continue the plan',
				'expected_action' => 'operations.get_attention_queue',
				'context'         => [
					'last_result_type' => 'briefing',
					'last_result'      => [
						'recommended_sequence' => [
							[
								'label'   => 'Open attention queue',
								'command' => 'Show operations attention queue',
							],
							[
								'label'   => 'Check fulfillment pressure',
								'command' => 'Show fulfillment queue',
							],
							[
								'label'   => 'Check customer follow-up',
								'command' => 'Show customer service queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.briefing.recommendation',
					'briefing_sequence_index' => 0,
				],
			],
			[
				'name'            => 'Customer service follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'orders.search',
				'context'         => [
					'last_result_type'      => 'customer_service',
					'last_service_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the oldest on-hold follow-ups',
								'command' => 'Show customer service queue',
							],
							[
								'label'   => 'Review pending payment follow-ups',
								'command' => 'Show pending orders',
							],
							[
								'label'   => 'Review broader operations summary',
								'command' => 'Show operations summary for today',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'           => 'guard.follow_up.service.recommendation',
					'service_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Customer service follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'operations.get_summary',
				'context'         => [
					'last_result_type'      => 'customer_service',
					'last_service_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the oldest on-hold follow-ups',
								'command' => 'Show customer service queue',
							],
							[
								'label'   => 'Review pending payment follow-ups',
								'command' => 'Show pending orders',
							],
							[
								'label'   => 'Review broader operations summary',
								'command' => 'Show operations summary for today',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'           => 'guard.follow_up.service.recommendation',
					'service_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Fulfillment follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'orders.search',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'last_fulfillment_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Check missing tracking first',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review aging processing orders',
								'command' => 'Show processing orders',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'                => 'guard.follow_up.fulfillment.recommendation',
					'fulfillment_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Fulfillment follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'customers.get_service_queue',
				'context'         => [
					'last_result_type'          => 'fulfillment',
					'last_fulfillment_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Check missing tracking first',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review aging processing orders',
								'command' => 'Show processing orders',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'                => 'guard.follow_up.fulfillment.recommendation',
					'fulfillment_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Inventory follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'operations.get_fulfillment_queue',
				'context'         => [
					'last_result_type'        => 'inventory',
					'last_inventory_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the top restock priorities',
								'command' => 'What should we restock first?',
							],
							[
								'label'   => 'Check fulfillment pressure next',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review the broader store picture',
								'command' => 'Can you give me a monthly store picture?',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'              => 'guard.follow_up.inventory.recommendation',
					'inventory_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Inventory follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'operations.get_summary',
				'context'         => [
					'last_result_type'        => 'inventory',
					'last_inventory_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review the top restock priorities',
								'command' => 'What should we restock first?',
							],
							[
								'label'   => 'Check fulfillment pressure next',
								'command' => 'What seems blocked in fulfillment?',
							],
							[
								'label'   => 'Review the broader store picture',
								'command' => 'Can you give me a monthly store picture?',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'              => 'guard.follow_up.inventory.recommendation',
					'inventory_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Operations attention follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'orders.search',
				'context'         => [
					'last_result_type'         => 'operations_attention',
					'last_attention_followup'  => [
						'recommended_sequence' => [
							[
								'label'   => 'Review pending payment orders',
								'command' => 'Search pending orders',
							],
							[
								'label'   => 'Check aging processing orders',
								'command' => 'Show 5 processing orders',
							],
							[
								'label'   => 'Check customer follow-up next',
								'command' => 'Show customer service queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.attention.recommendation',
					'attention_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Operations attention follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'customers.get_service_queue',
				'context'         => [
					'last_result_type'         => 'operations_attention',
					'last_attention_followup'  => [
						'recommended_sequence' => [
							[
								'label'   => 'Review pending payment orders',
								'command' => 'Search pending orders',
							],
							[
								'label'   => 'Check aging processing orders',
								'command' => 'Show 5 processing orders',
							],
							[
								'label'   => 'Check customer follow-up next',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'             => 'guard.follow_up.attention.recommendation',
					'attention_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Business follow-up next recommendation skips to second item when no cursor exists',
				'command'         => 'Do the next recommendation',
				'expected_action' => 'products.get_inventory_queue',
				'context'         => [
					'last_result_type'       => 'business',
					'last_business_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review weekly operations summary',
								'command' => 'Show operations summary for this week',
							],
							[
								'label'   => 'Check inventory pressure',
								'command' => 'Show inventory queue',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
					],
				],
				'expect'          => [
					'parser_rule'            => 'guard.follow_up.business.recommendation',
					'business_sequence_index' => 1,
				],
			],
			[
				'name'            => 'Business follow-up continue plan advances from cursor',
				'command'         => 'Continue the plan',
				'expected_action' => 'customers.get_service_queue',
				'context'         => [
					'last_result_type'       => 'business',
					'last_business_followup' => [
						'recommended_sequence' => [
							[
								'label'   => 'Review weekly operations summary',
								'command' => 'Show operations summary for this week',
							],
							[
								'label'   => 'Check inventory pressure',
								'command' => 'Show inventory queue',
							],
							[
								'label'   => 'Check customer follow-up load',
								'command' => 'Show customer service queue',
							],
						],
						'recommendation_cursor' => 1,
					],
				],
				'expect'          => [
					'parser_rule'            => 'guard.follow_up.business.recommendation',
					'business_sequence_index' => 2,
				],
			],
			[
				'name'            => 'Send current workflow email',
				'command'         => 'Send this email',
				'expected_action' => 'orders.send_customer_email',
				'context'         => [
					'current_workflow' => [
						'id'         => 'wf_test',
						'type'       => 'customer_email',
						'step'       => 'previewed',
						'order_id'   => 123,
						'subject'    => 'Test',
						'body'       => 'Test body',
						'draft_id'   => '',
						'updated_at' => current_time( 'mysql' ),
					],
				],
				'expect'          => [
					'params.order_id' => 123,
					'params.subject'  => 'Test',
					'params.body'     => 'Test body',
				],
			],
			[
				'name'             => 'Missing order context should clarify hold command',
				'command'          => 'Hold this order',
				'context'          => [],
				'expect'           => [
					'action' => 'orders.hold',
				],
				'expect_unmatched' => true,
				'expect_stage'     => 'clarify',
			],
			[
				'name'             => 'Missing note should clarify',
				'command'          => 'Add note to order #123',
				'expected_action'  => 'orders.add_note',
				'expect_unmatched' => true,
				'expect_stage'     => 'clarify',
			],
			[
				'name'             => 'Move this order without context should clarify',
				'command'          => 'Move this order to completed',
				'context'          => [],
				'expect_unmatched' => true,
				'expect_stage'     => 'clarify',
			],
		];
	}

	private static function planner_cases() {
		return [
			[
				'name'    => 'Read action should be low risk',
				'command' => 'Show order #123',
				'expect'  => [
					'parsed.action'              => 'orders.get_details',
					'plan.type'                  => 'single',
					'plan.action'                => 'orders.get_details',
					'risk.risk_level'            => 'low',
					'risk.requires_confirmation' => false,
				],
			],
			[
				'name'    => 'Status update should require confirmation',
				'command' => 'Mark order #123 as completed',
				'expect'  => [
					'parsed.action'              => 'orders.update_status',
					'plan.type'                  => 'single',
					'plan.action'                => 'orders.update_status',
					'risk.requires_confirmation' => true,
				],
			],
			[
				'name'    => 'Send current email should require confirmation',
				'command' => 'Send this email',
				'context' => [
					'current_workflow' => [
						'id'         => 'wf_test',
						'type'       => 'customer_email',
						'step'       => 'previewed',
						'order_id'   => 123,
						'subject'    => 'Test',
						'body'       => 'Test body',
						'draft_id'   => '',
						'updated_at' => current_time( 'mysql' ),
					],
				],
				'expect'  => [
					'parsed.action'              => 'orders.send_customer_email',
					'plan.type'                  => 'single',
					'plan.action'                => 'orders.send_customer_email',
					'risk.risk_level'            => 'medium',
					'risk.requires_confirmation' => true,
				],
			],
			[
				'name'    => 'Bulk completed follow-up should require confirmation',
				'command' => 'Mark all of them as completed',
				'context' => [
					'last_orders'    => [
						[ 'id' => 101 ],
						[ 'id' => 102 ],
					],
					'last_order_ids' => [ 101, 102 ],
				],
				'expect'  => [
					'parsed.action'              => 'orders.update_status',
					'plan.type'                  => 'single',
					'plan.action'                => 'orders.update_status',
					'risk.requires_confirmation' => true,
				],
			],
			[
				'name'    => 'Hold latest 5 orders should require confirmation',
				'command' => 'Hold latest 5 orders',
				'expect'  => [
						'parsed.action'              => 'multi.query_and_apply',
						'parsed.resolution_path'     => 'ai_primary',
						'parsed.parser_rule'         => 'ai.command_router.v2',
						'parsed.command_shape.target_type' => 'order_query_selection',
						'plan.type'                  => 'sequence',
						'plan.steps.0.action'        => 'orders.search',
					'plan.steps.1.action'        => 'orders.hold',
					'risk.requires_confirmation' => true,
				],
			],
			[
				'name'    => 'Multi action hold and note builds sequence',
				'command' => 'Hold order #123 and add note: Need review',
				'expect'  => [
					'parsed.action'              => 'workflow.composed_actions',
					'plan.type'                  => 'sequence',
					'plan.steps.0.action'        => 'orders.get_details',
					'plan.steps.1.action'        => 'orders.hold',
					'plan.steps.2.action'        => 'orders.add_note',
					'plan.validation.semantic.passed' => true,
					'risk.requires_confirmation' => true,
				],
			],
			[
				'name' => 'Semantic validator rejects unresolved capture dependency in sequence plan',
				'command' => 'synthetic invalid workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'send_email',
							'label'  => 'Send customer email',
							'action' => 'orders.send_customer_email',
							'params' => [
								'order_id' => 6864,
								'subject'  => '{{context.email_subject}}',
								'body'     => '{{context.email_body}}',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'     => true,
					'plan.code'            => 'yoro_invalid_action_plan_dependency',
					'plan.data.placeholder'=> 'email_subject',
				],
			],
			[
				'name' => 'Semantic validator rejects conflicting status updates in one workflow',
				'command' => 'synthetic conflicting status workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'update_status_pending',
							'label'  => 'Update order status',
							'action' => 'orders.update_status',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'pending',
							],
						],
						[
							'id'     => 'update_status_completed',
							'label'  => 'Update order status again',
							'action' => 'orders.update_status',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'completed',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'            => true,
					'plan.code'                   => 'yoro_invalid_action_plan_contradiction',
					'plan.data.previous_status'   => 'pending',
					'plan.data.status'            => 'completed',
					'plan.data.previous_step_id'  => 'update_status_pending',
				],
			],
			[
				'name' => 'Semantic validator rejects redundant repeated status change in one workflow',
				'command' => 'synthetic redundant status workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'update_status_with_note',
							'label'  => 'Update status with note',
							'action' => 'orders.bulk_update_status_with_note',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'processing',
								'note'      => 'Payment verified',
							],
						],
						[
							'id'     => 'update_status',
							'label'  => 'Update order status again',
							'action' => 'orders.update_status',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'processing',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'            => true,
					'plan.code'                   => 'yoro_invalid_action_plan_contradiction',
					'plan.data.status'            => 'processing',
					'plan.data.previous_step_id'  => 'update_status_with_note',
				],
			],
			[
				'name' => 'Semantic validator warns when reopening a terminal order',
				'command' => 'synthetic reopen completed order workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'update_status',
							'label'  => 'Update order status',
							'action' => 'orders.update_status',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'processing',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'completed',
									'email' => 'dev@example.com',
									'requires_shipping' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.type'                                => 'sequence',
					'plan.validation.semantic.passed'          => true,
					'plan.validation.semantic.warnings.0.code' => 'reopening_terminal_order',
					'risk.semantic_warnings.0'                 => 'Order #6864 is currently "completed" but this workflow changes it to "processing". Review whether reopening a terminal order is intentional.',
				],
			],
			[
				'name' => 'Semantic validator rejects shipping email when workflow status intent is pending',
				'command' => 'synthetic pending plus shipping email workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'update_status',
							'label'  => 'Update order status',
							'action' => 'orders.update_status',
							'params' => [
								'order_ids' => [ 6864 ],
								'status'    => 'pending',
							],
						],
						[
							'id'      => 'compose_email',
							'label'   => 'Compose customer email',
							'action'  => 'orders.compose_customer_email',
							'params'  => [
								'order_id'    => 6864,
								'instruction' => 'Notify the customer that the shipment is on the way and will be delivered soon.',
							],
							'capture' => [
								'email_subject' => 'result.email.subject',
								'email_body'    => 'result.email.body',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'tracking_detected' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'          => true,
					'plan.code'                 => 'yoro_invalid_action_plan_contradiction',
					'plan.data.status_intent'   => 'pending',
				],
			],
			[
				'name' => 'Semantic validator rejects shipping email for non shippable order',
				'command' => 'synthetic non shippable delivery email workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'      => 'compose_email',
							'label'   => 'Compose customer email',
							'action'  => 'orders.compose_customer_email',
							'params'  => [
								'order_id'    => 6864,
								'instruction' => 'Tell the customer the package is in transit and will be delivered soon.',
							],
							'capture' => [
								'email_subject' => 'result.email.subject',
								'email_body'    => 'result.email.body',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => false,
									'tracking_detected' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'        => true,
					'plan.code'               => 'yoro_invalid_action_plan_entity',
					'plan.data.order_id'      => 6864,
				],
			],
			[
				'name' => 'Semantic validator warns on payment verification email for paid active order',
				'command' => 'synthetic payment verification email on paid order',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'      => 'compose_email',
							'label'   => 'Compose customer email',
							'action'  => 'orders.compose_customer_email',
							'params'  => [
								'order_id'    => 6864,
								'instruction' => 'Ask the customer for payment verification and confirmation of their payment details before we continue.',
							],
							'capture' => [
								'email_subject' => 'result.email.subject',
								'email_body'    => 'result.email.body',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'tracking_detected' => false,
									'is_paid' => true,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.type'                                => 'sequence',
					'plan.validation.semantic.passed'          => true,
					'plan.validation.semantic.warnings.0.code' => 'payment_review_on_paid_order',
				],
			],
			[
				'name' => 'Semantic validator rejects fulfillment preview for cancelled order',
				'command' => 'synthetic cancelled fulfillment preview',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.fulfillment_followup_preview',
					'params'  => [
						'order_id' => 6864,
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'cancelled',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'tracking_detected' => false,
									'shipping_method' => 'Flat rate',
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error' => true,
					'plan.code'        => 'yoro_invalid_action_plan_contradiction',
					'plan.data.status' => 'cancelled',
					'plan.data.order_id' => 6864,
				],
			],
			[
				'name' => 'Semantic validator rejects verify before fulfillment for non shippable order',
				'command' => 'synthetic verify before fulfillment on digital order',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.verify_customer_before_fulfillment',
					'params'  => [
						'order_id' => 6864,
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error' => true,
					'plan.code'        => 'yoro_invalid_action_plan_entity',
					'plan.data.order_id' => 6864,
				],
			],
			[
				'name' => 'Semantic validator warns on refund review for unpaid pending order',
				'command' => 'synthetic refund review on unpaid order',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.refund_review',
					'params'  => [
						'order_id' => 6864,
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'pending',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'is_paid' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.type'                                => 'single',
					'plan.validation.semantic.passed'          => true,
					'plan.validation.semantic.warnings.0.code' => 'refund_review_on_unpaid_order',
				],
			],
			[
				'name' => 'Semantic validator warns on refund review with no remaining refundable amount',
				'command' => 'synthetic refund review on fully refunded order',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.refund_review',
					'params'  => [
						'order_id' => 6864,
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'refunded',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'is_paid' => true,
									'remaining_refund' => 0,
									'total_refunded' => 59,
									'refund_count' => 1,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.type'                                => 'single',
					'plan.validation.semantic.passed'          => true,
					'plan.validation.semantic.warnings.0.code' => 'refund_review_without_remaining_refund',
				],
			],
			[
				'name' => 'Semantic validator warns on fulfillment workflow with incomplete shipping address blocker',
				'command' => 'synthetic fulfillment preview with incomplete shipping address',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.fulfillment_followup_preview',
					'params'  => [
						'order_id' => 6864,
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'tracking_detected' => true,
									'shipping_method' => 'Flat rate',
									'shipping_complete' => false,
									'ready_to_fulfill' => false,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.type'                                => 'single',
					'plan.validation.semantic.passed'          => true,
					'plan.validation.semantic.warnings.0.code' => 'fulfillment_with_incomplete_shipping_address',
				],
			],
			[
				'name' => 'Semantic validator rejects refund and fulfillment goals mixed in one workflow',
				'command' => 'synthetic refund plus fulfillment workflow',
				'parsed_override' => [
					'success' => true,
					'matched' => true,
					'action'  => 'workflow.composed_actions',
					'actions' => [
						[
							'id'     => 'review_refund',
							'label'  => 'Review refundable amount',
							'action' => 'orders.refund_preview',
							'params' => [
								'order_id' => 6864,
							],
						],
						[
							'id'      => 'compose_email',
							'label'   => 'Compose customer email',
							'action'  => 'orders.compose_customer_email',
							'params'  => [
								'order_id'    => 6864,
								'instruction' => 'Tell the customer the shipment is on the way and will be delivered soon.',
							],
							'capture' => [
								'email_subject' => 'result.email.subject',
								'email_body'    => 'result.email.body',
							],
						],
					],
					'params' => [
						'order_id'  => 6864,
						'order_ids' => [ 6864 ],
					],
					'entities' => [
						'graph' => [
							'orders' => [
								[
									'id' => 6864,
									'status' => 'processing',
									'email' => 'dev@example.com',
									'requires_shipping' => true,
									'tracking_detected' => true,
									'shipping_method' => 'Flat rate',
									'is_paid' => true,
									'remaining_refund' => 59,
								],
							],
						],
					],
					'parser_rule'   => 'test.synthetic',
					'parser_source' => 'rule',
					'confidence'    => 1,
				],
				'expect' => [
					'plan.is_wp_error'            => true,
					'plan.code'                   => 'yoro_invalid_action_plan_contradiction',
					'plan.data.order_id'          => 6864,
					'plan.data.refund_step_id'    => 'review_refund',
					'plan.data.fulfillment_step_id' => 'compose_email',
				],
			],
		];
	}

	private static function preview_cases() {
		return [
			[
				'name'    => 'Preview order details has standard contract',
				'command' => 'Show order #123',
				'expect'  => [
					'parsed.action'       => 'orders.get_details',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'orders.get_details',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
				],
			],
			[
				'name'    => 'Preview operations summary has standard contract',
				'command' => 'Show me the operations summary for today',
				'expect'  => [
					'parsed.action'       => 'operations.get_summary',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'operations.get_summary',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get store operations summary',
					'result.plan.window'  => 'Today',
				],
			],
			[
				'name'    => 'Preview operations attention queue has standard contract',
				'command' => 'Show operations attention queue',
				'expect'  => [
					'parsed.action'       => 'operations.get_attention_queue',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'operations.get_attention_queue',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get operations attention queue',
				],
			],
			[
				'name'    => 'Preview fulfillment queue has standard contract',
				'command' => 'Show fulfillment queue',
				'expect'  => [
					'parsed.action'       => 'operations.get_fulfillment_queue',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'operations.get_fulfillment_queue',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get fulfillment queue',
				],
			],
			[
				'name'    => 'Preview inventory queue has standard contract',
				'command' => 'Show inventory queue',
				'expect'  => [
					'parsed.action'       => 'products.get_inventory_queue',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'products.get_inventory_queue',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get inventory queue',
				],
			],
			[
				'name'    => 'Preview store insights has standard contract',
				'command' => 'Show store insights for this week',
				'expect'  => [
					'parsed.action'       => 'store.get_insights',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'store.get_insights',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get store insights',
				],
			],
			[
				'name'    => 'Preview customer service queue has standard contract',
				'command' => 'Show customer service queue',
				'expect'  => [
					'parsed.action'       => 'customers.get_service_queue',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'customers.get_service_queue',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
					'result.plan.action'  => 'Get customer service queue',
				],
			],
			[
				'name'    => 'Preview status update has standard contract',
				'command' => 'Mark order #123 as completed',
				'expect'  => [
					'parsed.action'       => 'orders.update_status',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'orders.update_status',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
				],
			],
			[
				'name'    => 'Preview refund context has standard contract',
				'command' => 'Refund preview for order #123',
				'expect'  => [
					'parsed.action'       => 'orders.refund_preview',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'orders.refund_preview',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
				],
			],
			[
				'name'    => 'Preview move to on hold stays update_status',
				'command' => 'Move order #123 to on hold',
				'expect'  => [
					'parsed.action'              => 'orders.update_status',
					'result.success'             => true,
					'result.preview'             => true,
					'result.action'              => 'orders.update_status',
					'result.plan.target_status'  => 'on-hold',
					'result.display_type'        => 'preview',
				],
			],
			[
				'name'    => 'Preview send current email has standard contract',
				'command' => 'Send this email',
				'context' => [
					'current_workflow' => [
						'id'         => 'wf_test',
						'type'       => 'customer_email',
						'step'       => 'previewed',
						'order_id'   => 123,
						'subject'    => 'Test',
						'body'       => 'Test body',
						'draft_id'   => '',
						'updated_at' => current_time( 'mysql' ),
					],
				],
				'expect'  => [
					'parsed.action'       => 'orders.send_customer_email',
					'result.success'      => true,
					'result.preview'      => true,
					'result.action'       => 'orders.send_customer_email',
					'result.result_type'  => 'preview',
					'result.display_type' => 'preview',
					'result.read_only'    => true,
				],
			],
			[
				'name'    => 'Preview hold latest 5 sequence has standard contract',
				'command' => 'Hold latest 5 orders',
				'expect'  => [
					'parsed.action'       => 'multi.query_and_apply',
					'plan.type'           => 'sequence',
					'result.success'      => true,
					'result.preview'      => true,
					'result.multi_action' => true,
					'result.display_type' => 'multi_action',
				],
			],
				[
					'name'    => 'Preview multi action sequence has standard contract',
					'command' => 'Hold order #123 and add note: Need review',
					'router_fixture' => self::base_router_fixture_decision(
						'task_request',
						'orders.hold',
						[
							'order_ids' => [ 123 ],
						],
						0.92
					),
					'expect'  => [
						'parsed.action'       => 'workflow.composed_actions',
						'plan.type'           => 'sequence',
					'result.success'      => true,
					'result.preview'      => true,
					'result.multi_action' => true,
					'result.display_type' => 'multi_action',
				],
			],
		];
	}

	private static function execution_cases() {
		return [
			[
				'name'   => 'Execute order search resolves Vietnamese this week through WP date settings',
				'action' => 'orders.search',
				'params' => [
					'status'           => 'pending',
					'original_command' => 'Tuần này có đơn nào đang pending không?',
				],
				'expect' => [
					'result.success'         => true,
					'result.action'          => 'orders.search',
					'result.date_window.key' => 'this_week',
				],
				'assert' => function( $actual ) {
					$expected_window = YoRo_Date_Helper::resolve_window( 'this_week' );

					return [
						'date_window_uses_wp_start_of_week' => [
							'expected' => $expected_window['date_from'] ?? '',
							'actual'   => self::get_path( $actual, 'result.date_window.date_from', '' ),
							'passed'   => ( $expected_window['date_from'] ?? '' ) === self::get_path( $actual, 'result.date_window.date_from', '' ),
						],
						'date_window_uses_wp_timezone' => [
							'expected' => wp_timezone_string(),
							'actual'   => self::get_path( $actual, 'result.date_window.timezone', '' ),
							'passed'   => wp_timezone_string() === self::get_path( $actual, 'result.date_window.timezone', '' ),
						],
						'vietnamese_summary_localized' => [
							'expected' => true,
							'actual'   => false !== strpos( (string) self::get_path( $actual, 'result.summary', '' ), 'Tìm thấy' ),
							'passed'   => false !== strpos( (string) self::get_path( $actual, 'result.summary', '' ), 'Tìm thấy' ),
						],
					];
				},
			],
			[
				'name'   => 'Execute operations summary resolves calendar this week window',
				'action' => 'operations.get_summary',
				'params' => [
					'window' => 'this_week',
					'focus'  => 'overview',
				],
				'expect' => [
					'result.success'    => true,
					'result.action'     => 'operations.get_summary',
					'result.window.key' => 'this_week',
				],
				'assert' => function( $actual ) {
					$expected_window = YoRo_Date_Helper::resolve_window( 'this_week' );

					return [
						'operations_window_uses_wp_start_of_week' => [
							'expected' => $expected_window['start'] ?? '',
							'actual'   => self::get_path( $actual, 'result.window.start', '' ),
							'passed'   => ( $expected_window['start'] ?? '' ) === self::get_path( $actual, 'result.window.start', '' ),
						],
						'operations_window_uses_wp_timezone' => [
							'expected' => wp_timezone_string(),
							'actual'   => self::get_path( $actual, 'result.window.timezone', '' ),
							'passed'   => wp_timezone_string() === self::get_path( $actual, 'result.window.timezone', '' ),
						],
					];
				},
			],
			[
				'name'   => 'Execute business summary resolves calendar this week window',
				'action' => 'store.get_business_summary',
				'params' => [
					'window' => 'this_week',
					'focus'  => 'overview',
				],
				'expect' => [
					'result.success'    => true,
					'result.action'     => 'store.get_business_summary',
					'result.window.key' => 'this_week',
				],
				'assert' => function( $actual ) {
					$expected_window = YoRo_Date_Helper::resolve_window( 'this_week' );

					return [
						'business_window_uses_wp_start_of_week' => [
							'expected' => $expected_window['start'] ?? '',
							'actual'   => self::get_path( $actual, 'result.window.start', '' ),
							'passed'   => ( $expected_window['start'] ?? '' ) === self::get_path( $actual, 'result.window.start', '' ),
						],
						'business_window_uses_wp_timezone' => [
							'expected' => wp_timezone_string(),
							'actual'   => self::get_path( $actual, 'result.window.timezone', '' ),
							'passed'   => wp_timezone_string() === self::get_path( $actual, 'result.window.timezone', '' ),
						],
					];
				},
			],
			[
				'name'   => 'Execute store insights returns shared date window payload',
				'action' => 'store.get_insights',
				'params' => [
					'window' => 'this_week',
					'limit'  => 3,
				],
				'expect' => [
					'result.success'    => true,
					'result.action'     => 'store.get_insights',
					'result.window.key' => 'this_week',
				],
				'assert' => function( $actual ) {
					$expected_window = YoRo_Date_Helper::resolve_window( 'this_week' );

					return [
						'store_insights_window_has_date_from' => [
							'expected' => $expected_window['date_from'] ?? '',
							'actual'   => self::get_path( $actual, 'result.window.date_from', '' ),
							'passed'   => ( $expected_window['date_from'] ?? '' ) === self::get_path( $actual, 'result.window.date_from', '' ),
						],
						'store_insights_window_uses_wp_timezone' => [
							'expected' => wp_timezone_string(),
							'actual'   => self::get_path( $actual, 'result.window.timezone', '' ),
							'passed'   => wp_timezone_string() === self::get_path( $actual, 'result.window.timezone', '' ),
						],
						'store_insights_window_uses_wp_start_of_week' => [
							'expected' => absint( get_option( 'start_of_week', 1 ) ),
							'actual'   => self::get_path( $actual, 'result.window.week_starts_on', null ),
							'passed'   => absint( get_option( 'start_of_week', 1 ) ) === self::get_path( $actual, 'result.window.week_starts_on', null ),
						],
					];
				},
			],
			[
				'name'          => 'REST run low confidence AI confirmation returns pending token',
				'command'       => 'Maybe open order 123 for a quick look',
				'rest_method'   => 'POST',
				'rest_callback' => [ 'YoRo_REST_Run_Handler', 'handle' ],
				'rest_params'   => [
					'command' => 'Maybe open order 123 for a quick look',
					'confirm' => false,
				],
				'router_fixture'=> function() {
					return self::base_router_fixture_decision(
						'task_request',
						'orders.get_details',
						[
							'order_id'  => 123,
							'order_ids' => [ 123 ],
						],
						0.55
					);
				},
				'expect'        => [
					'result.success'          => false,
					'result.stage'            => 'confirm',
					'result.confirm_required' => true,
					'result.parsed.action'    => 'orders.get_details',
					'result.parser_source'    => 'ai_router',
				],
				'assert'        => function( $actual ) {
					$token   = self::get_path( $actual, 'result.workflow_state_token', '' );
					$pending = YoRo_Conversation_Memory::get_pending_confirmation( get_current_user_id() );
					$stored  = '' !== $token
						? YoRo_REST_Workflow_State::get( get_current_user_id(), $token, 'Maybe open order 123 for a quick look' )
						: null;

					return [
						'workflow_state_token_present' => [
							'expected' => true,
							'actual'   => is_string( $token ) && '' !== $token,
							'passed'   => is_string( $token ) && '' !== $token,
						],
						'pending_plan_token_matches' => [
							'expected' => $token,
							'actual'   => self::get_path( $actual, 'result.pending_plan_token', '' ),
							'passed'   => '' !== $token && $token === self::get_path( $actual, 'result.pending_plan_token', '' ),
						],
						'pending_confirmation_matches_token' => [
							'expected' => $token,
							'actual'   => is_array( $pending ) ? ( $pending['token'] ?? '' ) : '',
							'passed'   => '' !== $token && is_array( $pending ) && $token === ( $pending['token'] ?? '' ),
						],
						'stored_workflow_state_present' => [
							'expected' => true,
							'actual'   => is_array( $stored ) && ! empty( $stored['plan'] ),
							'passed'   => is_array( $stored ) && ! empty( $stored['plan'] ),
						],
					];
				},
				'cleanup'       => function() {
					$pending = YoRo_Conversation_Memory::get_pending_confirmation( get_current_user_id() );
					$token   = is_array( $pending ) ? sanitize_text_field( $pending['token'] ?? '' ) : '';

					if ( '' !== $token ) {
						YoRo_REST_Workflow_State::delete( get_current_user_id(), $token );
					}

					YoRo_Conversation_Memory::clear_pending_confirmation( get_current_user_id() );
				},
			],
			[
				'name'          => 'REST run low confidence AI orders search bypasses confirmation',
				'command'       => 'get 2 new orders',
				'rest_method'   => 'POST',
				'rest_callback' => [ 'YoRo_REST_Run_Handler', 'handle' ],
				'rest_params'   => [
					'command' => 'get 2 new orders',
					'confirm' => false,
				],
				'router_fixture'=> function() {
					$decision = self::base_router_fixture_decision(
						'task_request',
						'orders.search',
						[
							'limit' => 2,
						],
						0.0
					);
					$decision['summary'] = 'Showing latest 2 orders.';
					$decision['reasoning_summary'] = 'The command asks for a recent order list, so it should search by freshness rather than assume a status filter.';

					return $decision;
				},
				'expect'        => [
					'result.success'             => true,
					'result.stage'               => 'done',
					'result.parsed.action'       => 'orders.search',
					'result.parsed.confidence'   => 0.0,
					'result.result.success'      => true,
					'result.result.read_only'    => true,
					'result.result.action'       => 'orders.search',
				],
				'assert'        => function( $actual ) {
					$pending = YoRo_Conversation_Memory::get_pending_confirmation( get_current_user_id() );

					return [
						'low_confidence_orders_search_has_no_confirm_gate' => [
							'expected' => false,
							'actual'   => self::get_path( $actual, 'result.confirm_required', false ),
							'passed'   => empty( self::get_path( $actual, 'result.confirm_required', false ) ),
						],
						'low_confidence_orders_search_has_no_pending_token' => [
							'expected' => '',
							'actual'   => self::get_path( $actual, 'result.workflow_state_token', '' ),
							'passed'   => '' === self::get_path( $actual, 'result.workflow_state_token', '' ),
						],
						'low_confidence_orders_search_does_not_store_pending_confirmation' => [
							'expected' => false,
							'actual'   => is_array( $pending ) && ! empty( $pending['token'] ),
							'passed'   => ! is_array( $pending ) || empty( $pending['token'] ),
						],
					];
				},
				'cleanup'       => function() {
					YoRo_Conversation_Memory::clear_pending_confirmation( get_current_user_id() );
				},
			],
			[
				'name'          => 'Workflow state TTL uses configured option',
				'rest_method'   => 'GET',
				'rest_callback' => function() {
					return rest_ensure_response(
						[
							'success' => true,
							'ttl'     => YoRo_REST_Workflow_State::get_ttl(),
						]
					);
				},
				'setup'         => function() {
					$previous = get_option( 'yoro_workflow_ttl', null );
					update_option( 'yoro_workflow_ttl', 45 );

					return [
						'previous_workflow_ttl' => $previous,
					];
				},
				'expect'        => [
					'result.success' => true,
					'result.ttl'     => 45,
				],
				'cleanup'       => function( $fixture ) {
					if ( array_key_exists( 'previous_workflow_ttl', (array) $fixture ) && null !== $fixture['previous_workflow_ttl'] ) {
						update_option( 'yoro_workflow_ttl', $fixture['previous_workflow_ttl'] );
						return;
					}

					delete_option( 'yoro_workflow_ttl' );
				},
			],
			[
				'name'    => 'Execute operations summary returns read-only result',
				'command' => 'Show me the operations summary for today',
				'expect'  => [
					'parsed.action'       => 'operations.get_summary',
					'plan.action'         => 'operations.get_summary',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'operations_summary',
					'result.display_type' => 'operations_summary',
				],
			],
			[
				'name'    => 'Execute operations attention queue returns read-only result',
				'command' => 'Show operations attention queue',
				'expect'  => [
					'parsed.action'       => 'operations.get_attention_queue',
					'plan.action'         => 'operations.get_attention_queue',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'operations_attention_queue',
					'result.display_type' => 'operations_attention_queue',
				],
				'assert'  => function( $actual ) {
					$context = self::get_path( $actual, 'result.attention_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );
					$actions = self::get_path( $actual, 'result.attention_actions', [] );

					if ( empty( $actions ) ) {
						$actions = self::get_path( $actual, 'result.recommended_sequence', [] );
					}

					return [
						'attention_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['top_topic'] ),
						],
						'attention_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'attention_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
						'attention_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
					];
				},
			],
			[
				'name'    => 'Execute fulfillment queue returns read-only result',
				'command' => 'Show fulfillment queue',
				'expect'  => [
					'parsed.action'       => 'operations.get_fulfillment_queue',
					'plan.action'         => 'operations.get_fulfillment_queue',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'fulfillment_queue',
					'result.display_type' => 'fulfillment_queue',
				],
				'assert'  => function( $actual ) {
					$context = self::get_path( $actual, 'result.fulfillment_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );
					$actions = self::get_path( $actual, 'result.fulfillment_actions', [] );

					if ( empty( $actions ) ) {
						$actions = self::get_path( $actual, 'result.recommended_sequence', [] );
					}

					return [
						'fulfillment_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['top_topic'] ),
						],
						'fulfillment_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'fulfillment_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
						'fulfillment_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
					];
				},
			],
			[
				'name'    => 'Execute fulfillment follow-up command routes to guided preview confirmation',
				'command' => 'Prepare the next fulfillment follow-up',
				'confirm' => false,
				'context' => [
					'last_fulfillment_followup' => [
						'queues' => [
							[
								'key'    => 'processing_without_tracking',
								'label'  => 'Processing without tracking',
								'orders' => [
									[
										'id'                => 6685,
										'number'            => '6685',
										'status'            => 'processing',
										'billing_email'     => 'j***n@mail.com',
										'shipping_name'     => 'John Doe',
										'tracking_detected' => false,
									],
								],
							],
						],
					],
				],
				'setup'   => function() {
					$result = YoRo_Orders_Read_Service::get_fulfillment_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'operations.get_fulfillment_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [];
				},
				'expect'  => [
					'parsed.action'                           => 'workflow.fulfillment_followup_preview',
					'plan.action'                             => 'workflow.fulfillment_followup_preview',
					'result.requires_confirmation'            => true,
					'result.display_type'                     => 'fulfillment_followup_workflow_preview',
					'result.proposed_action.action'           => 'orders.save_customer_email_draft',
					'result.next_after_confirm.action'        => 'orders.save_customer_email_draft',
				],
			],
			[
				'name'   => 'Execute fulfillment follow-up workflow preview returns Phase 4 preview contract',
				'action' => 'workflow.fulfillment_followup_preview',
				'confirm'=> false,
				'params' => [
					'original_command' => 'Prepare the next fulfillment follow-up',
				],
				'setup'  => function() {
					$result = YoRo_Orders_Read_Service::get_fulfillment_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'operations.get_fulfillment_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [
						'fulfillment_result' => $result,
					];
				},
				'expect' => [
					'result.success'                                  => true,
					'result.read_only'                                => true,
					'result.result_type'                              => 'fulfillment_followup_workflow_preview',
					'result.display_type'                             => 'fulfillment_followup_workflow_preview',
					'result.preview_ready'                            => true,
					'result.changes_store'                            => false,
					'result.confirmation_required_for_execute'        => true,
					'result.proposed_action.action'                   => 'orders.save_customer_email_draft',
					'result.next_after_confirm.action'                => 'orders.save_customer_email_draft',
					'result.workflow.step'                            => 'preview_ready',
					'result.workflow.type'                            => 'fulfillment_followup',
				],
				'assert' => function( $actual ) {
					$selected_item   = self::get_path( $actual, 'result.selected_item', [] );
					$queue_source    = self::get_path( $actual, 'result.queue_source', [] );
					$selection_reason = self::get_path( $actual, 'result.selection_reason', '' );
					$draft_summary   = self::get_path( $actual, 'result.draft_summary', [] );
					$email_preview   = self::get_path( $actual, 'result.email_preview', [] );

					return [
						'fulfillment_workflow_selected_item_present' => [
							'expected' => true,
							'actual'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
							'passed'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
						],
						'fulfillment_workflow_queue_source_present' => [
							'expected' => true,
							'actual'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
							'passed'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
						],
						'fulfillment_workflow_selection_reason_present' => [
							'expected' => true,
							'actual'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
							'passed'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
						],
						'fulfillment_workflow_draft_summary_present' => [
							'expected' => true,
							'actual'   => is_array( $draft_summary ) && ! empty( $draft_summary['subject'] ) && ! empty( $draft_summary['body_preview'] ),
							'passed'   => is_array( $draft_summary ) && ! empty( $draft_summary['subject'] ) && ! empty( $draft_summary['body_preview'] ),
						],
						'fulfillment_workflow_email_preview_present' => [
							'expected' => true,
							'actual'   => is_array( $email_preview ) && ! empty( $email_preview['subject'] ) && ! empty( $email_preview['html_body'] ),
							'passed'   => is_array( $email_preview ) && ! empty( $email_preview['subject'] ) && ! empty( $email_preview['html_body'] ),
						],
					];
				},
			],
			[
				'name'   => 'Execute fulfillment follow-up confirmation saves draft after preview',
				'action' => 'workflow.fulfillment_followup_preview',
				'confirm'=> true,
				'setup'  => function() {
					$preview = YoRo_Orders_Read_Service::get_fulfillment_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $preview ) ) {
						return $preview;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'operations.get_fulfillment_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$preview
					);

					$prepared = YoRo_Workflow_Actions::prepare_fulfillment_followup_preview(
						[
							'original_command' => 'Prepare the next fulfillment follow-up',
							'_yoro_confirmed' => false,
						]
					);

					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}

					YoRo_Conversation_Memory::set_pending_confirmation(
						get_current_user_id(),
						[
							'token'    => 'test-token',
							'command'  => 'Prepare the next fulfillment follow-up',
							'action'   => 'workflow.fulfillment_followup_preview',
							'required' => true,
						]
					);

					return [
						'prepared' => $prepared,
					];
				},
				'params'  => [
					'original_command' => 'Prepare the next fulfillment follow-up',
				],
				'expect'  => [
					'result.success'          => true,
					'result.display_type'     => 'customer_email_drafts',
					'result.result_type'      => 'customer_email_drafts',
					'result.workflow_executed'=> true,
				],
				'assert'  => function( $actual ) {
					$draft = self::get_path( $actual, 'result.draft', [] );
					$workflow = self::get_path( $actual, 'result.workflow', [] );

					return [
						'fulfillment_workflow_saved_draft_present' => [
							'expected' => true,
							'actual'   => is_array( $draft ) && ! empty( $draft['id'] ) && ! empty( $draft['subject'] ),
							'passed'   => is_array( $draft ) && ! empty( $draft['id'] ) && ! empty( $draft['subject'] ),
						],
						'fulfillment_workflow_step_executed' => [
							'expected' => 'executed',
							'actual'   => is_array( $workflow ) ? ( $workflow['step'] ?? null ) : null,
							'passed'   => is_array( $workflow ) && ( ( $workflow['step'] ?? null ) === 'executed' ),
						],
					];
				},
			],
			[
				'name'   => 'Execute stale fulfillment follow-up confirmation fails safely without saving draft',
				'action' => 'workflow.fulfillment_followup_preview',
				'confirm'=> true,
				'setup'  => function() {
					YoRo_Conversation_Memory::set_workflow(
						get_current_user_id(),
						[
							'id'         => 'wf_stale_test',
							'type'       => 'fulfillment_followup',
							'action'     => 'workflow.fulfillment_followup_preview',
							'step'       => 'preview_ready',
							'updated_at' => current_time( 'mysql' ),
							'params'     => [
								'selected_item'    => [
									'order_id'     => 6685,
									'order_number' => '6685',
									'queue_key'    => 'processing_without_tracking',
								],
								'selection_reason' => 'Stale preview test fixture.',
								'next_after_confirm' => [
									'action' => 'orders.save_customer_email_draft',
									'label'  => 'Save reviewed customer email draft',
									'params' => [
										'order_id' => 6685,
										'subject'  => '',
										'body'     => '',
									],
								],
							],
						]
					);

					return [];
				},
				'params'  => [
					'original_command' => 'Prepare the next fulfillment follow-up',
				],
				'expect'  => [
					'result.success'    => false,
					'result.error.code' => 'yoro_workflow_stale',
					'result.result_type'=> 'error',
				],
			],
			[
				'name'   => 'Execute fulfillment confirmation without active workflow fails safely',
				'action' => 'workflow.fulfillment_followup_preview',
				'confirm'=> true,
				'params'  => [
					'original_command' => 'Prepare the next fulfillment follow-up',
				],
				'expect'  => [
					'result.success'    => false,
					'result.error.code' => 'yoro_workflow_stale',
					'result.result_type'=> 'error',
				],
			],
			[
				'name'   => 'Execute fulfillment confirmation with mismatched next action fails safely',
				'action' => 'workflow.fulfillment_followup_preview',
				'confirm'=> true,
				'setup'  => function() {
					YoRo_Conversation_Memory::set_workflow(
						get_current_user_id(),
						[
							'id'         => 'wf_bad_action_test',
							'type'       => 'fulfillment_followup',
							'action'     => 'workflow.fulfillment_followup_preview',
							'step'       => 'preview_ready',
							'updated_at' => current_time( 'mysql' ),
							'params'     => [
								'selected_item'    => [
									'order_id'     => 6685,
									'order_number' => '6685',
									'queue_key'    => 'processing_without_tracking',
								],
								'selection_reason' => 'Bad action test fixture.',
								'next_after_confirm' => [
									'action' => 'orders.update_status',
									'label'  => 'Unexpected action',
									'params' => [
										'order_id' => 6685,
										'subject'  => 'Subject',
										'body'     => 'Body',
									],
								],
							],
						]
					);

					return [];
				},
				'params'  => [
					'original_command' => 'Prepare the next fulfillment follow-up',
				],
				'expect'  => [
					'result.success'    => false,
					'result.error.code' => 'yoro_workflow_stale',
					'result.result_type'=> 'error',
				],
			],
			[
				'name'   => 'Execute customer follow-up workflow preview returns Phase 4 preview contract',
				'action' => 'workflow.customer_followup_preview',
				'confirm'=> false,
				'params' => [
					'original_command' => 'Prepare the next customer follow-up',
				],
				'setup'  => function() {
					$result = YoRo_Orders_Read_Service::get_customer_service_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'customers.get_service_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [
						'service_result' => $result,
					];
				},
				'expect' => [
					'result.success'                           => true,
					'result.read_only'                         => true,
					'result.result_type'                       => 'customer_followup_workflow_preview',
					'result.display_type'                      => 'customer_followup_workflow_preview',
					'result.preview_ready'                     => true,
					'result.changes_store'                     => false,
					'result.confirmation_required_for_execute' => true,
					'result.workflow.step'                     => 'preview_ready',
					'result.workflow.type'                     => 'customer_followup',
				],
				'assert' => function( $actual ) {
					$selected_item    = self::get_path( $actual, 'result.selected_item', [] );
					$queue_source     = self::get_path( $actual, 'result.queue_source', [] );
					$selection_reason = self::get_path( $actual, 'result.selection_reason', '' );
					$draft_summary    = self::get_path( $actual, 'result.draft_summary', [] );
					$email_preview    = self::get_path( $actual, 'result.email_preview', [] );
					$proposed_action  = self::get_path( $actual, 'result.proposed_action.action', '' );

					return [
						'customer_followup_workflow_selected_item_present' => [
							'expected' => true,
							'actual'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
							'passed'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
						],
						'customer_followup_workflow_queue_source_present' => [
							'expected' => true,
							'actual'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
							'passed'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
						],
						'customer_followup_workflow_selection_reason_present' => [
							'expected' => true,
							'actual'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
							'passed'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
						],
						'customer_followup_workflow_draft_summary_present' => [
							'expected' => true,
							'actual'   => is_array( $draft_summary ) && ! empty( $draft_summary['subject'] ) && ! empty( $draft_summary['body_preview'] ),
							'passed'   => is_array( $draft_summary ) && ! empty( $draft_summary['subject'] ) && ! empty( $draft_summary['body_preview'] ),
						],
						'customer_followup_workflow_email_preview_present' => [
							'expected' => true,
							'actual'   => is_array( $email_preview ) && ! empty( $email_preview['subject'] ) && ! empty( $email_preview['html_body'] ),
							'passed'   => is_array( $email_preview ) && ! empty( $email_preview['subject'] ) && ! empty( $email_preview['html_body'] ),
						],
						'customer_followup_workflow_action_present' => [
							'expected' => true,
							'actual'   => in_array( $proposed_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
							'passed'   => in_array( $proposed_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
						],
					];
				},
			],
			[
				'name'    => 'Execute customer follow-up command routes to guided preview confirmation',
				'command' => 'Prepare the next customer follow-up',
				'confirm' => false,
				'context' => [
					'last_service_followup' => [
						'queues' => [
							[
								'key'    => 'saved_email_drafts',
								'label'  => 'Saved drafts',
								'orders' => [
									[
										'id'             => 6546,
										'number'         => '6546',
										'status'         => 'on-hold',
										'billing_email'  => 'j***x@mail.com',
										'customer_name'  => 'Jane Doe',
										'followup_reason'=> 'Saved email draft is waiting for review.',
									],
								],
							],
						],
					],
				],
				'setup'   => function() {
					$result = YoRo_Orders_Read_Service::get_customer_service_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'customers.get_service_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [];
				},
				'expect'  => [
					'parsed.action'                    => 'workflow.customer_followup_preview',
					'plan.action'                      => 'workflow.customer_followup_preview',
					'result.requires_confirmation'     => true,
					'result.display_type'              => 'customer_followup_workflow_preview',
				],
				'assert'  => function( $actual ) {
					$proposed_action = self::get_path( $actual, 'result.proposed_action.action', '' );
					$next_action     = self::get_path( $actual, 'result.next_after_confirm.action', '' );

					return [
						'customer_followup_command_action_present' => [
							'expected' => true,
							'actual'   => in_array( $proposed_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
							'passed'   => in_array( $proposed_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
						],
						'customer_followup_command_next_action_present' => [
							'expected' => true,
							'actual'   => in_array( $next_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
							'passed'   => in_array( $next_action, [ 'orders.save_customer_email_draft', 'orders.update_customer_email_draft' ], true ),
						],
					];
				},
			],
			[
				'name'   => 'Execute customer follow-up confirmation saves or updates draft after preview',
				'action' => 'workflow.customer_followup_preview',
				'confirm'=> true,
				'setup'  => function() {
					$preview = YoRo_Orders_Read_Service::get_customer_service_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $preview ) ) {
						return $preview;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'customers.get_service_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$preview
					);

					$prepared = YoRo_Workflow_Actions::prepare_customer_followup_preview(
						[
							'original_command' => 'Prepare the next customer follow-up',
							'_yoro_confirmed' => false,
						]
					);

					if ( is_wp_error( $prepared ) ) {
						return $prepared;
					}

					YoRo_Conversation_Memory::set_pending_confirmation(
						get_current_user_id(),
						[
							'token'    => 'customer-test-token',
							'command'  => 'Prepare the next customer follow-up',
							'action'   => 'workflow.customer_followup_preview',
							'required' => true,
						]
					);

					return [
						'prepared' => $prepared,
					];
				},
				'params'  => [
					'original_command' => 'Prepare the next customer follow-up',
				],
				'expect'  => [
					'result.success'           => true,
					'result.display_type'      => 'customer_email_drafts',
					'result.result_type'       => 'customer_email_drafts',
					'result.workflow_executed' => true,
				],
				'assert'  => function( $actual ) {
					$draft    = self::get_path( $actual, 'result.draft', [] );
					$workflow = self::get_path( $actual, 'result.workflow', [] );

					return [
						'customer_followup_workflow_saved_draft_present' => [
							'expected' => true,
							'actual'   => is_array( $draft ) && ! empty( $draft['id'] ) && ! empty( $draft['subject'] ),
							'passed'   => is_array( $draft ) && ! empty( $draft['id'] ) && ! empty( $draft['subject'] ),
						],
						'customer_followup_workflow_step_executed' => [
							'expected' => 'executed',
							'actual'   => is_array( $workflow ) ? ( $workflow['step'] ?? null ) : null,
							'passed'   => is_array( $workflow ) && ( ( $workflow['step'] ?? null ) === 'executed' ),
						],
					];
				},
			],
			[
				'name'   => 'Execute customer confirmation without active workflow fails safely',
				'action' => 'workflow.customer_followup_preview',
				'confirm'=> true,
				'params'  => [
					'original_command' => 'Prepare the next customer follow-up',
				],
				'expect'  => [
					'result.success'    => false,
					'result.error.code' => 'yoro_workflow_stale',
					'result.result_type'=> 'error',
				],
			],
			[
				'name'   => 'Execute customer confirmation with mismatched next action fails safely',
				'action' => 'workflow.customer_followup_preview',
				'confirm'=> true,
				'setup'  => function() {
					YoRo_Conversation_Memory::set_workflow(
						get_current_user_id(),
						[
							'id'         => 'wf_bad_customer_action_test',
							'type'       => 'customer_followup',
							'action'     => 'workflow.customer_followup_preview',
							'step'       => 'preview_ready',
							'updated_at' => current_time( 'mysql' ),
							'params'     => [
								'queue_key'       => 'saved_email_drafts',
								'selected_item'   => [
									'order_id'     => 6458,
									'order_number' => '6458',
									'queue_key'    => 'saved_email_drafts',
								],
								'selection_reason' => 'Bad action test fixture.',
								'next_after_confirm' => [
									'action' => 'orders.send_customer_email',
									'label'  => 'Unexpected action',
									'params' => [
										'order_id' => 6458,
										'subject'  => 'Subject',
										'body'     => 'Body',
									],
								],
							],
						]
					);

					return [];
				},
				'params'  => [
					'original_command' => 'Prepare the next customer follow-up',
				],
				'expect'  => [
					'result.success'    => false,
					'result.error.code' => 'yoro_workflow_stale',
					'result.result_type'=> 'error',
				],
			],
			[
				'name'   => 'Execute operations attention workflow preview returns Phase 4 preview contract',
				'action' => 'workflow.operations_attention_preview',
				'confirm'=> false,
				'params' => [
					'original_command' => 'Prepare the next attention follow-up',
				],
				'setup'  => function() {
					$result = YoRo_Orders_Read_Service::get_operations_attention_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'operations.get_attention_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [
						'attention_result' => $result,
					];
				},
				'expect' => [
					'result.success'         => true,
					'result.read_only'       => true,
					'result.result_type'     => 'operations_attention_workflow_preview',
					'result.display_type'    => 'operations_attention_workflow_preview',
					'result.preview_ready'   => true,
					'result.changes_store'   => false,
					'result.workflow.step'   => 'preview_ready',
					'result.workflow.type'   => 'operations_attention',
				],
				'assert' => function( $actual ) {
					$selected_item     = self::get_path( $actual, 'result.selected_item', [] );
					$queue_source      = self::get_path( $actual, 'result.queue_source', [] );
					$selection_reason  = self::get_path( $actual, 'result.selection_reason', '' );
					$proposed_action   = self::get_path( $actual, 'result.proposed_action.action', '' );
					$next_without_confirm = self::get_path( $actual, 'result.next_without_confirm', [] );

					return [
						'operations_attention_workflow_selected_item_present' => [
							'expected' => true,
							'actual'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
							'passed'   => is_array( $selected_item ) && ! empty( $selected_item['order_id'] ) && ! empty( $selected_item['queue_key'] ),
						],
						'operations_attention_workflow_queue_source_present' => [
							'expected' => true,
							'actual'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
							'passed'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
						],
						'operations_attention_workflow_selection_reason_present' => [
							'expected' => true,
							'actual'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
							'passed'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
						],
						'operations_attention_workflow_action_present' => [
							'expected' => true,
							'actual'   => in_array( $proposed_action, [ 'customers.get_service_queue', 'operations.get_fulfillment_queue', 'operations.get_summary' ], true ),
							'passed'   => in_array( $proposed_action, [ 'customers.get_service_queue', 'operations.get_fulfillment_queue', 'operations.get_summary' ], true ),
						],
						'operations_attention_workflow_next_step_present' => [
							'expected' => true,
							'actual'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
							'passed'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
						],
					];
				},
			],
			[
				'name'    => 'Execute operations attention command routes to guided preview',
				'command' => 'Prepare the next attention follow-up',
				'context' => [
					'last_attention_followup' => [
						'queues' => [
							[
								'key'    => 'pending_payment_older_than_24h',
								'label'  => 'Pending payment older than 24h',
								'orders' => [
									[
										'id'            => 6683,
										'number'        => '6683',
										'status'        => 'pending',
										'billing_email' => 'j***n@mail.com',
									],
								],
							],
						],
					],
				],
				'setup'   => function() {
					$result = YoRo_Orders_Read_Service::get_operations_attention_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'operations.get_attention_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [];
				},
				'expect'  => [
					'parsed.action'     => 'workflow.operations_attention_preview',
					'plan.action'       => 'workflow.operations_attention_preview',
					'result.display_type' => 'operations_attention_workflow_preview',
					'result.read_only'  => true,
				],
				'assert'  => function( $actual ) {
					$next_action = self::get_path( $actual, 'result.next_without_confirm.action', '' );
					$next_command = self::get_path( $actual, 'result.next_without_confirm.command', '' );

					return [
						'operations_attention_command_next_action_present' => [
							'expected' => true,
							'actual'   => in_array( $next_action, [ 'customers.get_service_queue', 'operations.get_fulfillment_queue', 'operations.get_summary' ], true ),
							'passed'   => in_array( $next_action, [ 'customers.get_service_queue', 'operations.get_fulfillment_queue', 'operations.get_summary' ], true ),
						],
						'operations_attention_command_next_command_present' => [
							'expected' => true,
							'actual'   => is_string( $next_command ) && '' !== trim( $next_command ),
							'passed'   => is_string( $next_command ) && '' !== trim( $next_command ),
						],
					];
				},
			],
			[
				'name'    => 'Execute inventory queue returns read-only result',
				'command' => 'Show inventory queue',
				'expect'  => [
					'parsed.action'       => 'products.get_inventory_queue',
					'plan.action'         => 'products.get_inventory_queue',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'inventory_queue',
					'result.display_type' => 'inventory_queue',
				],
				'assert'  => function( $actual ) {
					$context = self::get_path( $actual, 'result.inventory_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );
					$actions = self::get_path( $actual, 'result.inventory_actions', [] );

					return [
						'inventory_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['top_topic'] ),
						],
						'inventory_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'inventory_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
						'inventory_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
					];
				},
			],
			[
				'name'   => 'Execute inventory pressure workflow preview returns Phase 4 preview contract',
				'action' => 'workflow.inventory_pressure_preview',
				'confirm'=> false,
				'params' => [
					'original_command' => 'Prepare the next inventory review',
				],
				'setup'  => function() {
					$result = YoRo_Orders_Read_Service::get_inventory_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'products.get_inventory_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [
						'inventory_result' => $result,
					];
				},
				'expect' => [
					'result.success'         => true,
					'result.read_only'       => true,
					'result.result_type'     => 'inventory_pressure_workflow_preview',
					'result.display_type'    => 'inventory_pressure_workflow_preview',
					'result.preview_ready'   => true,
					'result.changes_store'   => false,
					'result.workflow.step'   => 'preview_ready',
					'result.workflow.type'   => 'inventory_pressure',
				],
				'assert' => function( $actual ) {
					$selected_item = self::get_path( $actual, 'result.selected_item', [] );
					$queue_source = self::get_path( $actual, 'result.queue_source', [] );
					$selection_reason = self::get_path( $actual, 'result.selection_reason', '' );
					$proposed_action = self::get_path( $actual, 'result.proposed_action.action', '' );
					$next_without_confirm = self::get_path( $actual, 'result.next_without_confirm', [] );

					return [
						'inventory_pressure_workflow_selected_item_present' => [
							'expected' => true,
							'actual'   => is_array( $selected_item ) && ! empty( $selected_item['product_id'] ) && ! empty( $selected_item['topic_key'] ),
							'passed'   => is_array( $selected_item ) && ! empty( $selected_item['product_id'] ) && ! empty( $selected_item['topic_key'] ),
						],
						'inventory_pressure_workflow_queue_source_present' => [
							'expected' => true,
							'actual'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
							'passed'   => is_array( $queue_source ) && ! empty( $queue_source['key'] ) && ! empty( $queue_source['label'] ),
						],
						'inventory_pressure_workflow_selection_reason_present' => [
							'expected' => true,
							'actual'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
							'passed'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
						],
						'inventory_pressure_workflow_action_present' => [
							'expected' => true,
							'actual'   => in_array( $proposed_action, [ 'products.get_inventory_queue', 'operations.get_fulfillment_queue', 'store.get_business_summary' ], true ),
							'passed'   => in_array( $proposed_action, [ 'products.get_inventory_queue', 'operations.get_fulfillment_queue', 'store.get_business_summary' ], true ),
						],
						'inventory_pressure_workflow_next_step_present' => [
							'expected' => true,
							'actual'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
							'passed'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
						],
					];
				},
			],
			[
				'name'    => 'Execute inventory command routes to guided preview',
				'command' => 'Prepare the next inventory review',
				'context' => [
					'last_inventory_followup' => [
						'queues' => [
							[
								'key'      => 'out_of_stock',
								'label'    => 'Out of stock',
								'products' => [
									[
										'id'             => 101,
										'name'           => 'Demo Product',
										'sku'            => 'SKU-101',
										'stock_status'   => 'outofstock',
										'stock_quantity' => 0,
									],
								],
							],
						],
					],
				],
				'setup'   => function() {
					$result = YoRo_Orders_Read_Service::get_inventory_queue(
						[
							'limit' => 5,
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'products.get_inventory_queue',
							'params' => [
								'limit' => 5,
							],
						],
						$result
					);

					return [];
				},
				'expect'  => [
					'parsed.action'       => 'workflow.inventory_pressure_preview',
					'plan.action'         => 'workflow.inventory_pressure_preview',
					'result.display_type' => 'inventory_pressure_workflow_preview',
					'result.read_only'    => true,
				],
				'assert'  => function( $actual ) {
					$next_action = self::get_path( $actual, 'result.next_without_confirm.action', '' );
					$next_command = self::get_path( $actual, 'result.next_without_confirm.command', '' );

					return [
						'inventory_pressure_command_next_action_present' => [
							'expected' => true,
							'actual'   => in_array( $next_action, [ 'products.get_inventory_queue', 'operations.get_fulfillment_queue', 'store.get_business_summary' ], true ),
							'passed'   => in_array( $next_action, [ 'products.get_inventory_queue', 'operations.get_fulfillment_queue', 'store.get_business_summary' ], true ),
						],
						'inventory_pressure_command_next_command_present' => [
							'expected' => true,
							'actual'   => is_string( $next_command ) && '' !== trim( $next_command ),
							'passed'   => is_string( $next_command ) && '' !== trim( $next_command ),
						],
					];
				},
			],
			[
				'name'   => 'Execute business summary workflow preview returns Phase 4 preview contract',
				'action' => 'workflow.business_summary_preview',
				'confirm'=> false,
				'params' => [
					'original_command' => 'Prepare the next business review',
				],
				'setup'  => function() {
					$result = YoRo_Orders_Read_Service::get_business_summary(
						[
							'window' => 'last_7_days',
							'focus'  => 'overview',
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'store.get_business_summary',
							'params' => [
								'window' => 'last_7_days',
								'focus'  => 'overview',
							],
						],
						$result
					);

					return [
						'business_result' => $result,
					];
				},
				'expect' => [
					'result.success'         => true,
					'result.read_only'       => true,
					'result.result_type'     => 'business_summary_workflow_preview',
					'result.display_type'    => 'business_summary_workflow_preview',
					'result.preview_ready'   => true,
					'result.changes_store'   => false,
					'result.workflow.step'   => 'preview_ready',
					'result.workflow.type'   => 'business_summary',
				],
				'assert' => function( $actual ) {
					$selected_item = self::get_path( $actual, 'result.selected_item', [] );
					$selection_reason = self::get_path( $actual, 'result.selection_reason', '' );
					$proposed_action = self::get_path( $actual, 'result.proposed_action.action', '' );
					$next_without_confirm = self::get_path( $actual, 'result.next_without_confirm', [] );

					return [
						'business_summary_workflow_selected_item_present' => [
							'expected' => true,
							'actual'   => is_array( $selected_item ) && ! empty( $selected_item['topic_key'] ) && ! empty( $selected_item['title'] ),
							'passed'   => is_array( $selected_item ) && ! empty( $selected_item['topic_key'] ) && ! empty( $selected_item['title'] ),
						],
						'business_summary_workflow_selection_reason_present' => [
							'expected' => true,
							'actual'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
							'passed'   => is_string( $selection_reason ) && '' !== trim( $selection_reason ),
						],
						'business_summary_workflow_action_present' => [
							'expected' => true,
							'actual'   => in_array( $proposed_action, [ 'operations.get_attention_queue', 'operations.get_fulfillment_queue', 'products.get_inventory_queue', 'customers.get_service_queue', 'operations.get_summary', 'store.get_business_summary' ], true ),
							'passed'   => in_array( $proposed_action, [ 'operations.get_attention_queue', 'operations.get_fulfillment_queue', 'products.get_inventory_queue', 'customers.get_service_queue', 'operations.get_summary', 'store.get_business_summary' ], true ),
						],
						'business_summary_workflow_next_step_present' => [
							'expected' => true,
							'actual'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
							'passed'   => is_array( $next_without_confirm ) && ! empty( $next_without_confirm['command'] ) && ! empty( $next_without_confirm['action'] ),
						],
					];
				},
			],
			[
				'name'    => 'Execute business review command routes to guided preview',
				'command' => 'Prepare the next business review',
				'context' => [
					'last_business_followup' => [
						'priority_items' => [
							[
								'topic'   => 'operations_pressure',
								'title'   => 'Operational pressure is building',
								'message' => 'Backlog and attention load are rising.',
								'tone'    => 'warning',
							],
						],
						'recommended_sequence' => [
							[
								'topic'   => 'operations_attention',
								'label'   => 'Open the attention queue first',
								'command' => 'Show operations attention queue',
								'reason'  => 'Start here when the live queue is overshadowing the commercial picture.',
							],
						],
						'business_context' => [
							'top_topic'  => 'operations_pressure',
							'window_key' => 'last_7_days',
							'focus'      => 'overview',
						],
					],
				],
				'setup'   => function() {
					$result = YoRo_Orders_Read_Service::get_business_summary(
						[
							'window' => 'last_7_days',
							'focus'  => 'overview',
						]
					);

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					YoRo_Conversation_Memory::remember_result(
						get_current_user_id(),
						[
							'action' => 'store.get_business_summary',
							'params' => [
								'window' => 'last_7_days',
								'focus'  => 'overview',
							],
						],
						$result
					);

					return [];
				},
				'expect'  => [
					'parsed.action'       => 'workflow.business_summary_preview',
					'plan.action'         => 'workflow.business_summary_preview',
					'result.display_type' => 'business_summary_workflow_preview',
					'result.read_only'    => true,
				],
			],
			[
				'name'    => 'Execute store insights returns read-only result',
				'command' => 'Show store insights for this week',
				'expect'  => [
					'parsed.action'       => 'store.get_insights',
					'plan.action'         => 'store.get_insights',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'store_insights',
					'result.display_type' => 'store_insights',
				],
			],
			[
				'name'    => 'Execute customer service queue returns read-only result',
				'command' => 'Show customer service queue',
				'expect'  => [
					'parsed.action'       => 'customers.get_service_queue',
					'plan.action'         => 'customers.get_service_queue',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'customer_service_queue',
					'result.display_type' => 'customer_service_queue',
				],
				'assert'  => function( $actual ) {
					$context = self::get_path( $actual, 'result.service_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );
					$actions = self::get_path( $actual, 'result.service_actions', [] );

					if ( empty( $actions ) ) {
						$actions = self::get_path( $actual, 'result.recommended_sequence', [] );
					}

					return [
						'customer_service_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['top_topic'] ),
						],
						'customer_service_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'customer_service_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
						'customer_service_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
					];
				},
			],
			[
				'name'    => 'Execute daily briefing returns read-only aggregated result',
				'command' => 'Show me today\'s briefing',
				'expect'  => [
					'parsed.action'       => 'store.get_daily_briefing',
					'plan.action'         => 'store.get_daily_briefing',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'daily_briefing',
					'result.display_type' => 'daily_briefing',
				],
				'assert'  => function( $actual ) {
					$sections = self::get_path( $actual, 'result.sections', [] );
					$summary  = self::get_path( $actual, 'result.assistant_summary', '' );
					$context  = self::get_path( $actual, 'result.briefing_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$drilldowns = self::get_path( $actual, 'result.priority_drilldowns', [] );
					$actions = self::get_path( $actual, 'result.briefing_actions', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );

					return [
						'daily_briefing_sections_present' => [
							'expected' => true,
							'actual'   => is_array( $sections ) && count( $sections ) >= 4,
							'passed'   => is_array( $sections ) && count( $sections ) >= 4,
						],
						'daily_briefing_narrative_present' => [
							'expected' => true,
							'actual'   => is_string( $summary ) && '' !== trim( $summary ),
							'passed'   => is_string( $summary ) && '' !== trim( $summary ),
						],
						'daily_briefing_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['state'] ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['state'] ) && ! empty( $context['top_topic'] ),
						],
						'daily_briefing_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'daily_briefing_drilldowns_present' => [
							'expected' => true,
							'actual'   => is_array( $drilldowns ) && ! empty( $drilldowns ),
							'passed'   => is_array( $drilldowns ) && ! empty( $drilldowns ),
						],
						'daily_briefing_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
						'daily_briefing_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
					];
				},
			],
			[
				'name'          => 'Execute memory clear removes pending confirmation state',
				'rest_callback' => [ 'YoRo_REST_API', 'get_memory' ],
				'rest_method'   => 'GET',
				'setup'         => function() {
					YoRo_Conversation_Memory::set_pending_confirmation(
						get_current_user_id(),
						[
							'token'    => 'memory-clear-test-token',
							'command'  => 'Update order status',
							'action'   => 'orders.update_status',
							'required' => true,
						]
					);

					YoRo_Conversation_Memory::clear_pending_confirmation( get_current_user_id() );

					return [];
				},
				'cleanup'       => function() {
					YoRo_Conversation_Memory::clear_pending_confirmation( get_current_user_id() );
				},
				'expect'        => [
					'result.success'                                       => true,
					'result.runtime_context.pending_confirmation.required' => false,
					'result.runtime_context.pending_confirmation.action'   => '',
					'result.runtime_context.pending_confirmation.token'    => '',
				],
			],
			[
				'name'          => 'Execute morning briefing suppresses outside morning window',
				'rest_callback' => [ 'YoRo_REST_API', 'get_morning_briefing' ],
				'rest_method'   => 'GET',
				'rest_params'   => [
					'test_hour' => 12,
				],
				'cleanup'       => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);
				},
				'expect'        => [
					'result.success'     => true,
					'result.should_show' => false,
					'result.reason'      => 'outside_morning_window',
				],
			],
			[
				'name'          => 'Execute morning briefing returns proactive card when forced into morning window',
				'rest_callback' => [ 'YoRo_REST_API', 'get_morning_briefing' ],
				'rest_method'   => 'GET',
				'rest_params'   => [
					'force_window' => 1,
					'test_hour'    => 8,
				],
				'setup'         => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);

					return [];
				},
				'cleanup'       => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);
				},
				'expect'        => [
					'result.success'                  => true,
					'result.should_show'              => true,
					'result.result.display_type'      => 'morning_briefing',
					'result.result.result_type'       => 'morning_briefing',
					'result.result.dismissible'       => true,
					'result.result.read_only'         => true,
				],
				'assert'        => function( $actual ) {
					$priorities = self::get_path( $actual, 'result.result.top_priorities', [] );
					$first_action = self::get_path( $actual, 'result.result.first_recommended_action', [] );
					$suggested_commands = self::get_path( $actual, 'result.result.suggested_commands', [] );

					return [
						'morning_briefing_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priorities ) && ! empty( $priorities ),
							'passed'   => is_array( $priorities ) && ! empty( $priorities ),
						],
						'morning_briefing_first_action_present' => [
							'expected' => true,
							'actual'   => is_array( $first_action ) && ! empty( $first_action['command'] ) && ! empty( $first_action['label'] ),
							'passed'   => is_array( $first_action ) && ! empty( $first_action['command'] ) && ! empty( $first_action['label'] ),
						],
						'morning_briefing_suggested_commands_present' => [
							'expected' => true,
							'actual'   => is_array( $suggested_commands ) && ! empty( $suggested_commands ),
							'passed'   => is_array( $suggested_commands ) && ! empty( $suggested_commands ),
						],
					];
				},
			],
			[
				'name'          => 'Execute morning briefing suppresses after already shown today',
				'rest_callback' => [ 'YoRo_REST_API', 'get_morning_briefing' ],
				'rest_method'   => 'GET',
				'rest_params'   => [
					'force_window' => 1,
					'test_hour'    => 8,
				],
				'setup'         => function() {
					YoRo_Conversation_Memory::mark_morning_briefing_shown(
						get_current_user_id(),
						current_datetime()->format( 'Y-m-d' ),
						'test-signature'
					);

					return [];
				},
				'cleanup'       => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);
				},
				'expect'        => [
					'result.success'     => true,
					'result.should_show' => false,
					'result.reason'      => 'already_shown_today',
				],
			],
			[
				'name'          => 'Execute morning briefing dismiss endpoint records dismissal for today',
				'rest_callback' => [ 'YoRo_REST_API', 'dismiss_morning_briefing' ],
				'rest_method'   => 'POST',
				'cleanup'       => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);
				},
				'expect'        => [
					'result.success'   => true,
					'result.dismissed' => true,
				],
			],
			[
				'name'          => 'Execute morning briefing suppresses after dismissal today',
				'rest_callback' => [ 'YoRo_REST_API', 'get_morning_briefing' ],
				'rest_method'   => 'GET',
				'rest_params'   => [
					'force_window' => 1,
					'test_hour'    => 8,
				],
				'setup'         => function() {
					YoRo_Conversation_Memory::dismiss_morning_briefing(
						get_current_user_id(),
						current_datetime()->format( 'Y-m-d' )
					);

					return [];
				},
				'cleanup'       => function() {
					YoRo_Conversation_Memory::update(
						get_current_user_id(),
						[
							'conversation' => [
								'morning_briefing_state' => [],
							],
						]
					);
				},
				'expect'        => [
					'result.success'     => true,
					'result.should_show' => false,
					'result.reason'      => 'dismissed_today',
				],
			],
			[
				'name'    => 'Execute weekly briefing returns weekly window and label',
				'command' => 'Show me the weekly briefing',
				'expect'  => [
					'parsed.action'               => 'store.get_daily_briefing',
					'plan.action'                 => 'store.get_daily_briefing',
					'result.success'              => true,
					'result.read_only'            => true,
					'result.result_type'          => 'daily_briefing',
					'result.display_type'         => 'daily_briefing',
					'result.window.key'           => 'last_7_days',
					'result.briefing_label'       => 'Weekly briefing',
				],
			],
			[
				'name'    => 'Execute monthly briefing returns monthly window and label',
				'command' => 'Give me the monthly briefing',
				'expect'  => [
					'parsed.action'               => 'store.get_daily_briefing',
					'plan.action'                 => 'store.get_daily_briefing',
					'result.success'              => true,
					'result.read_only'            => true,
					'result.result_type'          => 'daily_briefing',
					'result.display_type'         => 'daily_briefing',
					'result.window.key'           => 'last_30_days',
					'result.briefing_label'       => 'Monthly briefing',
				],
			],
			[
				'name'    => 'Execute monthly brief alias routes to monthly briefing',
				'command' => 'Show me the brief of this month',
				'expect'  => [
					'parsed.action'               => 'store.get_daily_briefing',
					'plan.action'                 => 'store.get_daily_briefing',
					'result.success'              => true,
					'result.read_only'            => true,
					'result.result_type'          => 'daily_briefing',
					'result.display_type'         => 'daily_briefing',
					'result.window.key'           => 'this_month',
					'result.briefing_label'       => 'Monthly briefing',
				],
			],
			[
				'name'    => 'Execute business summary returns headline insights',
				'command' => 'Show me the business this week',
				'expect'  => [
					'parsed.action'       => 'store.get_business_summary',
					'plan.action'         => 'store.get_business_summary',
					'result.success'      => true,
					'result.read_only'    => true,
					'result.result_type'  => 'business_summary',
					'result.display_type' => 'business_summary',
				],
				'assert'  => function( $actual ) {
					$insights = self::get_path( $actual, 'result.headline_insights', [] );
					$summary  = self::get_path( $actual, 'result.assistant_summary', '' );
					$context  = self::get_path( $actual, 'result.business_context', [] );
					$priority = self::get_path( $actual, 'result.priority_items', [] );
					$sequence = self::get_path( $actual, 'result.recommended_sequence', [] );
					$actions  = self::get_path( $actual, 'result.business_actions', [] );

					return [
						'business_insights_present' => [
							'expected' => true,
							'actual'   => is_array( $insights ) && ! empty( $insights ),
							'passed'   => is_array( $insights ) && ! empty( $insights ),
						],
						'business_summary_narrative_present' => [
							'expected' => true,
							'actual'   => is_string( $summary ) && '' !== trim( $summary ),
							'passed'   => is_string( $summary ) && '' !== trim( $summary ),
						],
						'business_context_present' => [
							'expected' => true,
							'actual'   => is_array( $context ) && ! empty( $context['top_topic'] ),
							'passed'   => is_array( $context ) && ! empty( $context['top_topic'] ),
						],
						'business_priorities_present' => [
							'expected' => true,
							'actual'   => is_array( $priority ) && ! empty( $priority ),
							'passed'   => is_array( $priority ) && ! empty( $priority ),
						],
						'business_sequence_present' => [
							'expected' => true,
							'actual'   => is_array( $sequence ) && ! empty( $sequence ),
							'passed'   => is_array( $sequence ) && ! empty( $sequence ),
						],
						'business_actions_present' => [
							'expected' => true,
							'actual'   => is_array( $actions ) && ! empty( $actions ),
							'passed'   => is_array( $actions ) && ! empty( $actions ),
						],
					];
				},
			],
			[
				'name'   => 'Execute missing order details keeps read error contract',
				'action' => 'orders.get_details',
				'params' => [
					'order_id'  => 999999999,
					'order_ids' => [ 999999999 ],
				],
				'expect' => [
					'result.success'      => false,
					'result.read_only'    => true,
					'result.result_type'  => 'orders',
					'result.display_type' => 'error',
				],
			],
			[
				'name'  => 'Execute status update changes order and returns undo plan',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'pending',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id'       => $order->get_id(),
						'initial_status' => $order->get_status(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf( 'Mark order #%d as completed', absint( $fixture['order_id'] ?? 0 ) );
				},
				'expect'          => [
					'parsed.action'            => 'orders.update_status',
					'plan.action'              => 'orders.update_status',
					'result.success'           => true,
					'result.read_only'         => false,
					'result.undoable'          => true,
					'result.undo_plan.0.action'=> 'orders.restore_statuses',
				],
				'assert'          => function( $actual, $fixture ) {
					$order = wc_get_order( $fixture['order_id'] ?? 0 );

					return [
						'status_after_execute' => [
							'expected' => 'completed',
							'actual'   => $order ? $order->get_status() : null,
							'passed'   => $order && 'completed' === $order->get_status(),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute add note creates note and remove_note undo step',
				'setup' => function() {
					$order = self::create_test_order();

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id'          => $order->get_id(),
						'initial_yoro_note_ids' => self::get_yoro_order_note_ids( $order->get_id() ),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf( 'Add note to order #%d: Parser suite note', absint( $fixture['order_id'] ?? 0 ) );
				},
				'expect'          => [
					'parsed.action'            => 'orders.add_note',
					'plan.action'              => 'orders.add_note',
					'result.success'           => true,
					'result.read_only'         => false,
					'result.undoable'          => true,
					'result.undo_plan.0.action'=> 'orders.remove_note',
				],
				'assert'          => function( $actual, $fixture ) {
					$note_id = absint( self::get_path( $actual, 'result.note_ids.0', 0 ) );
					$comment = $note_id ? get_comment( $note_id ) : null;

					return [
						'note_created' => [
							'expected' => true,
							'actual'   => (bool) $comment,
							'passed'   => (bool) $comment,
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute save customer email draft stores draft metadata',
				'setup' => function() {
					$order = self::create_test_order();

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id' => $order->get_id(),
					];
				},
				'action'         => 'orders.save_customer_email_draft',
				'params_builder' => function( $fixture ) {
					return [
						'order_id' => absint( $fixture['order_id'] ?? 0 ),
						'subject'  => 'Parser Test Draft',
						'body'     => 'Parser test draft body',
					];
				},
				'expect'         => [
					'result.success'      => true,
					'result.read_only'    => false,
					'result.result_type'  => 'customer_email_drafts',
					'result.display_type' => 'customer_email_drafts',
					'result.count'        => 1,
				],
				'assert'         => function( $actual, $fixture ) {
					$order  = wc_get_order( $fixture['order_id'] ?? 0 );
					$drafts = $order ? $order->get_meta( '_yoro_customer_email_drafts', true ) : [];

					return [
						'draft_persisted' => [
							'expected' => true,
							'actual'   => ! empty( $drafts ) && is_array( $drafts ),
							'passed'   => ! empty( $drafts ) && is_array( $drafts ),
						],
					];
				},
				'cleanup'        => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute write email workflow save draft branch persists captured draft',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'processing',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					self::enable_mock_email_compose( 'Workflow Draft', 'Workflow draft body' );

					return [
						'order_id' => $order->get_id(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf(
						'Write a payment follow-up email for order #%d and save as draft',
						absint( $fixture['order_id'] ?? 0 )
					);
				},
				'expect'          => [
					'parsed.action'                        => 'workflow.write_and_save_email_draft_for_order',
					'plan.type'                            => 'sequence',
					'result.success'                       => true,
					'result.display_type'                  => 'multi_action',
					'result.workflow.definition_id'        => 'write_and_save_email_draft_for_order',
					'result.workflow.steps.2.id'           => 'save_draft',
				],
				'assert'          => function( $actual, $fixture ) {
					$order  = wc_get_order( $fixture['order_id'] ?? 0 );
					$drafts = $order ? $order->get_meta( '_yoro_customer_email_drafts', true ) : [];
					$draft  = is_array( $drafts ) && ! empty( $drafts[0] ) ? $drafts[0] : [];

					return [
						'workflow_draft_saved' => [
							'expected' => true,
							'actual'   => is_array( $drafts ) && ! empty( $drafts ),
							'passed'   => is_array( $drafts ) && ! empty( $drafts ),
						],
						'workflow_draft_subject_resolved' => [
							'expected' => true,
							'actual'   => is_array( $draft ) && ! empty( $draft['subject'] ) && false === strpos( (string) $draft['subject'], '{{' ),
							'passed'   => is_array( $draft ) && ! empty( $draft['subject'] ) && false === strpos( (string) $draft['subject'], '{{' ),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute write email workflow send branch leaves email for manual send',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'processing',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					self::enable_mock_email_compose( 'Workflow Send', 'Workflow send body' );
					self::enable_mock_email_send( true );

					return [
						'order_id' => $order->get_id(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf(
						'Write a payment follow-up email for order #%d and send it',
						absint( $fixture['order_id'] ?? 0 )
					);
				},
				'expect'          => [
					'parsed.action'                        => 'workflow.write_and_send_email_for_order',
					'plan.type'                            => 'sequence',
					'result.success'                       => true,
					'result.display_type'                  => 'multi_action',
					'result.workflow.definition_id'        => 'write_and_send_email_for_order',
					'result.workflow.steps.2.id'           => 'send_email',
				],
				'assert'          => function( $actual, $fixture ) {
					$notes = wc_get_order_notes(
						[
							'order_id' => absint( $fixture['order_id'] ?? 0 ),
							'limit'    => 20,
						]
					);
					$found = false;

					foreach ( $notes as $note ) {
						$content = (string) $note->content;

						if ( false !== stripos( $content, 'YoRo AI sent a custom customer email' ) ) {
							$found = true;
							break;
						}
					}

					return [
						'workflow_send_step_present' => [
							'expected' => 'orders.send_customer_email',
							'actual'   => self::get_path( $actual, 'result.actions.2.action', '' ),
							'passed'   => 'orders.send_customer_email' === self::get_path( $actual, 'result.actions.2.action', '' ),
						],
						'workflow_send_step_deferred_for_manual_send' => [
							'expected' => true,
							'actual'   => self::get_path( $actual, 'result.actions.2.manual_action_required', false ),
							'passed'   => true === self::get_path( $actual, 'result.actions.2.manual_action_required', false ),
						],
						'workflow_send_step_did_not_send_email' => [
							'expected' => false,
							'actual'   => $found,
							'passed'   => ! $found,
						],
						'workflow_send_result_keeps_send_payload' => [
							'expected' => true,
							'actual'   => ! empty( self::get_path( $actual, 'result.actions.2.result.send_payload.subject', '' ) ),
							'passed'   => ! empty( self::get_path( $actual, 'result.actions.2.result.send_payload.subject', '' ) ),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute verification workflow save draft branch holds order and saves draft',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'processing',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					self::enable_mock_email_compose( 'Verification Draft', 'Verification draft body' );

					return [
						'order_id' => $order->get_id(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf(
						'Hold order #%d, ask the customer to verify their address, and save the verification email as draft',
						absint( $fixture['order_id'] ?? 0 )
					);
				},
				'expect'          => [
					'parsed.action'                              => 'workflow.verify_customer_before_fulfillment',
					'plan.type'                                  => 'sequence',
					'result.success'                             => true,
					'result.display_type'                        => 'multi_action',
					'result.workflow.definition_id'              => 'verify_customer_before_fulfillment',
					'result.workflow.branch.email_delivery_mode' => 'save_draft',
					'result.workflow.steps.3.id'                 => 'save_draft',
				],
				'assert'          => function( $actual, $fixture ) {
					$order  = wc_get_order( $fixture['order_id'] ?? 0 );
					$drafts = $order ? $order->get_meta( '_yoro_customer_email_drafts', true ) : [];

					return [
						'verification_order_held' => [
							'expected' => 'on-hold',
							'actual'   => $order ? $order->get_status() : null,
							'passed'   => $order && 'on-hold' === $order->get_status(),
						],
						'verification_draft_saved' => [
							'expected' => true,
							'actual'   => is_array( $drafts ) && ! empty( $drafts ),
							'passed'   => is_array( $drafts ) && ! empty( $drafts ),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute refund review save draft branch saves refund follow-up draft',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'completed',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					self::enable_mock_email_compose( 'Refund Draft', 'Refund draft body' );

					return [
						'order_id' => $order->get_id(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf(
						'Review refund for order #%d and save a refund follow-up email as draft',
						absint( $fixture['order_id'] ?? 0 )
					);
				},
				'expect'          => [
					'parsed.action'                        => 'workflow.refund_review',
					'plan.type'                            => 'sequence',
					'result.success'                       => true,
					'result.display_type'                  => 'multi_action',
					'result.workflow.definition_id'        => 'refund_review',
					'result.workflow.branch.delivery_mode' => 'save_draft',
					'result.workflow.steps.3.id'           => 'save_draft',
				],
				'assert'          => function( $actual, $fixture ) {
					$order  = wc_get_order( $fixture['order_id'] ?? 0 );
					$drafts = $order ? $order->get_meta( '_yoro_customer_email_drafts', true ) : [];

					return [
						'refund_review_step_present' => [
							'expected' => 'orders.refund_preview',
							'actual'   => self::get_path( $actual, 'result.actions.1.action', '' ),
							'passed'   => 'orders.refund_preview' === self::get_path( $actual, 'result.actions.1.action', '' ),
						],
						'refund_draft_saved' => [
							'expected' => true,
							'actual'   => is_array( $drafts ) && ! empty( $drafts ),
							'passed'   => is_array( $drafts ) && ! empty( $drafts ),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute delete customer email draft removes draft metadata',
				'setup' => function() {
					$order = self::create_test_order();

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					$draft = [
						'id'         => 'draft_' . wp_generate_uuid4(),
						'subject'    => 'Existing Draft',
						'body'       => 'Existing body',
						'created_by' => get_current_user_id(),
						'created_at' => current_time( 'mysql' ),
						'updated_at' => current_time( 'mysql' ),
					];

					$order->update_meta_data( '_yoro_customer_email_drafts', [ $draft ] );
					$order->save();

					return [
						'order_id' => $order->get_id(),
						'draft_id' => $draft['id'],
					];
				},
				'action'         => 'orders.delete_customer_email_draft',
				'params_builder' => function( $fixture ) {
					return [
						'order_id' => absint( $fixture['order_id'] ?? 0 ),
						'draft_id' => sanitize_key( $fixture['draft_id'] ?? '' ),
					];
				},
				'expect'         => [
					'result.success'      => true,
					'result.read_only'    => false,
					'result.result_type'  => 'customer_email_drafts',
					'result.display_type' => 'customer_email_drafts',
					'result.count'        => 1,
				],
				'assert'         => function( $actual, $fixture ) {
					$order  = wc_get_order( $fixture['order_id'] ?? 0 );
					$drafts = $order ? $order->get_meta( '_yoro_customer_email_drafts', true ) : [];

					return [
						'draft_removed' => [
							'expected' => true,
							'actual'   => empty( $drafts ),
							'passed'   => empty( $drafts ),
						],
					];
				},
				'cleanup'        => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute save email template persists template list result',
				'setup' => function() {
					return [
						'previous_templates' => YoRo_Orders_Email_Service::get_email_templates(),
					];
				},
				'action' => 'email_templates.save',
				'params' => [
					'name'    => 'Parser Test Template',
					'subject' => 'Hello',
					'body'    => 'Body',
				],
				'expect' => [
					'result.success'      => true,
					'result.read_only'    => false,
					'result.result_type'  => 'email_templates',
					'result.display_type' => 'email_templates_list',
					'result.count'        => 1,
				],
				'assert' => function( $actual ) {
					$template_id = self::get_path( $actual, 'result.template.id', '' );
					$templates   = YoRo_Orders_Email_Service::get_email_templates();
					$found       = false;

					foreach ( $templates as $template ) {
						if ( ! empty( $template['id'] ) && $template['id'] === $template_id ) {
							$found = true;
							break;
						}
					}

					return [
						'template_persisted' => [
							'expected' => true,
							'actual'   => $found,
							'passed'   => $found,
						],
					];
				},
				'cleanup' => function( $fixture ) {
					self::restore_email_templates_fixture( $fixture );
				},
			],
			[
				'name'  => 'Execute delete email template removes template from store',
				'setup' => function() {
					$previous_templates = YoRo_Orders_Email_Service::get_email_templates();
					$template           = [
						'id'         => 'tpl_' . wp_generate_uuid4(),
						'name'       => 'Delete Me',
						'subject'    => 'Bye',
						'body'       => 'Body',
						'created_by' => get_current_user_id(),
						'created_at' => current_time( 'mysql' ),
						'updated_at' => current_time( 'mysql' ),
					];

					YoRo_Orders_Email_Service::save_email_templates( array_merge( [ $template ], $previous_templates ) );

					return [
						'previous_templates' => $previous_templates,
						'template_id'        => $template['id'],
					];
				},
				'action'         => 'email_templates.delete',
				'params_builder' => function( $fixture ) {
					return [
						'template_id' => sanitize_key( $fixture['template_id'] ?? '' ),
					];
				},
				'expect'         => [
					'result.success'      => true,
					'result.read_only'    => false,
					'result.result_type'  => 'email_templates',
					'result.display_type' => 'email_templates_list',
					'result.count'        => 1,
				],
				'assert'         => function( $actual, $fixture ) {
					$template_id = sanitize_key( $fixture['template_id'] ?? '' );
					$templates   = YoRo_Orders_Email_Service::get_email_templates();
					$found       = false;

					foreach ( $templates as $template ) {
						if ( ! empty( $template['id'] ) && $template['id'] === $template_id ) {
							$found = true;
							break;
						}
					}

					return [
						'template_removed' => [
							'expected' => false,
							'actual'   => $found,
							'passed'   => ! $found,
						],
					];
				},
				'cleanup'        => function( $fixture ) {
					self::restore_email_templates_fixture( $fixture );
				},
			],
		];
	}

	private static function undo_cases() {
		return [
			[
				'name'  => 'Undo status update restores previous status',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'pending',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id'       => $order->get_id(),
						'initial_status' => $order->get_status(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf( 'Mark order #%d as completed', absint( $fixture['order_id'] ?? 0 ) );
				},
				'expect'          => [
					'parsed.action'               => 'orders.update_status',
					'result.success'              => true,
					'result.undoable'             => true,
					'state.after_execute.status'  => 'completed',
					'state.after_undo.status'     => 'pending',
					'undo.0.action'               => 'orders.restore_statuses',
					'undo.0.result.success'       => true,
				],
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Undo add note removes YoRo note',
				'setup' => function() {
					$order = self::create_test_order();

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id' => $order->get_id(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf( 'Add note to order #%d: Undo me', absint( $fixture['order_id'] ?? 0 ) );
				},
				'expect'          => [
					'parsed.action'          => 'orders.add_note',
					'result.success'         => true,
					'result.undoable'        => true,
					'undo.0.action'          => 'orders.remove_note',
					'undo.0.result.success'  => true,
				],
				'assert'          => function( $actual ) {
					$note_id = absint( self::get_path( $actual, 'result.note_ids.0', 0 ) );

					return [
						'note_removed_after_undo' => [
							'expected' => false,
							'actual'   => (bool) get_comment( $note_id ),
							'passed'   => ! get_comment( $note_id ),
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
			[
				'name'  => 'Undo multi-step hold and note restores status and removes note',
				'setup' => function() {
					$order = self::create_test_order(
						[
							'status' => 'processing',
						]
					);

					if ( is_wp_error( $order ) ) {
						return $order;
					}

					return [
						'order_id'       => $order->get_id(),
						'initial_status' => $order->get_status(),
					];
				},
				'command_builder' => function( $fixture ) {
					return sprintf( 'Hold order #%d and add note: Need review', absint( $fixture['order_id'] ?? 0 ) );
				},
				'expect'          => [
					'parsed.action'              => 'workflow.composed_actions',
					'plan.type'                  => 'sequence',
					'result.success'             => true,
					'result.undoable'            => true,
					'state.after_execute.status' => 'on-hold',
					'state.after_undo.status'    => 'processing',
				],
				'assert'          => function( $actual ) {
					$undo_count = is_array( self::get_path( $actual, 'undo', [] ) ) ? count( self::get_path( $actual, 'undo', [] ) ) : 0;

					return [
						'multi_step_undo_count' => [
							'expected' => true,
							'actual'   => $undo_count >= 2,
							'passed'   => $undo_count >= 2,
						],
					];
				},
				'cleanup'         => function( $fixture ) {
					self::cleanup_test_order_fixture( $fixture );
				},
			],
		];
	}

	private static function custom_status_parser_cases() {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return [];
		}

		$core_statuses = [
			'pending',
			'processing',
			'on-hold',
			'completed',
			'cancelled',
			'refunded',
			'failed',
		];

		$options = YoRo_Order_Helper::get_order_status_options();

		foreach ( $options as $slug => $data ) {
			if ( in_array( $slug, $core_statuses, true ) ) {
				continue;
			}

			$blocked_custom_status_slugs = [
				'checkout-draft',
			];

			$blocked_custom_status_labels = [
				'draft',
			];

			$label = ! empty( $data['label'] ) ? $data['label'] : ucwords( str_replace( '-', ' ', $slug ) );
			$label_lower = strtolower( $label );

			if (
				in_array( $slug, $blocked_custom_status_slugs, true ) ||
				in_array( $label_lower, $blocked_custom_status_labels, true )
			) {
				continue;
			}

			return [
				[
					'name'            => 'Custom status update by label',
					'command'         => 'Mark order #123 as ' . $label,
					'expected_action' => 'orders.update_status',
					'expect'          => [
						'params.order_id' => 123,
						'params.status'   => $slug,
					],
				],
				[
					'name'            => 'Custom status search by label',
					'command'         => 'Show latest 5 ' . $label . ' orders',
					'expected_action' => 'orders.search',
					'expect'          => [
						'params.limit'    => 5,
						'params.status.0' => $slug,
					],
				],
				[
					'name'            => 'Custom status update this order by label',
					'command'         => 'Move this order to ' . $label,
					'expected_action' => 'orders.update_status',
					'context'         => [
						'current_order_id'         => 456,
						'last_referenced_order_id' => 456,
					],
					'expect'          => [
						'params.order_id'    => 456,
						'params.order_ids.0' => 456,
						'params.status'      => $slug,
					],
				],
			];
		}

		return [];
	}

	private static function create_test_order( $args = [] ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error(
				'yoro_test_woocommerce_missing',
				__( 'WooCommerce test helpers are unavailable.', 'yoro-ai-core' )
			);
		}

		$status = ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : 'pending';
		$email  = ! empty( $args['billing_email'] )
			? sanitize_email( $args['billing_email'] )
			: 'parser-test+' . wp_generate_password( 10, false ) . '@example.com';
		$phone  = ! empty( $args['billing_phone'] )
			? sanitize_text_field( $args['billing_phone'] )
			: '+1555000' . wp_rand( 1000, 9999 );

		$order = wc_create_order(
			[
				'status' => $status,
			]
		);

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'yoro_test_order_create_failed',
				__( 'Could not create parser test order.', 'yoro-ai-core' )
			);
		}

		$order->set_billing_first_name( 'Parser' );
		$order->set_billing_last_name( 'Suite' );
		$order->set_billing_email( $email );
		$order->set_billing_phone( $phone );
		$order->set_currency( get_woocommerce_currency() );
		$order->set_total( isset( $args['total'] ) ? (float) $args['total'] : 59.00 );
		$order->update_meta_data( '_yoro_parser_test_order', 1 );
		$order->save();

		return $order;
	}

	private static function enable_mock_email_compose( $subject = 'Mock Subject', $body = 'Mock body' ) {
		add_filter(
			'yoro_ai_compose_email_mock_result',
			static function( $result, $order, $instruction ) use ( $subject, $body ) {
				$order_number = $order instanceof WC_Order ? $order->get_order_number() : '';

				return [
					'to'       => $order instanceof WC_Order ? sanitize_email( $order->get_billing_email() ) : '',
					'subject'  => sprintf( '%s #%s', $subject, $order_number ),
					'body'     => $body . "\n\nInstruction: " . sanitize_textarea_field( (string) $instruction ),
					'template' => 'ai_custom',
					'meta'     => [
						'feature' => 'email_compose_mock',
					],
				];
			},
			10,
			3
		);
	}

	private static function disable_mock_email_compose() {
		remove_all_filters( 'yoro_ai_compose_email_mock_result' );
	}

	private static function enable_mock_email_send( $sent = true ) {
		add_filter(
			'yoro_send_customer_email_mock_result',
			static function() use ( $sent ) {
				return (bool) $sent;
			},
			10,
			2
		);
	}

	private static function disable_mock_email_send() {
		remove_all_filters( 'yoro_send_customer_email_mock_result' );
	}

	private static function enable_mock_action_decomposition( $result ) {
		self::$action_decomposition_fixture = $result;
	}

	private static function disable_mock_action_decomposition() {
		self::$action_decomposition_fixture = false;
	}

	private static function cleanup_test_order_fixture( $fixture ) {
		self::disable_mock_email_compose();
		self::disable_mock_email_send();
		self::disable_mock_action_decomposition();

		$order_id = absint( $fixture['order_id'] ?? 0 );

		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( $order && is_callable( [ $order, 'delete' ] ) ) {
			$order->delete( true );
		}
	}

	private static function restore_email_templates_fixture( $fixture ) {
		$templates = isset( $fixture['previous_templates'] ) && is_array( $fixture['previous_templates'] )
			? $fixture['previous_templates']
			: [];

		YoRo_Orders_Email_Service::save_email_templates( $templates );
	}

	private static function capture_test_order_state( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return [
				'exists'         => false,
				'status'         => '',
				'yoro_note_ids'  => [],
				'yoro_note_count'=> 0,
			];
		}

		$yoro_note_ids = self::get_yoro_order_note_ids( $order_id );

		return [
			'exists'          => true,
			'status'          => $order->get_status(),
			'yoro_note_ids'   => $yoro_note_ids,
			'yoro_note_count' => count( $yoro_note_ids ),
		];
	}

	private static function get_yoro_order_note_ids( $order_id ) {
		$comments = get_comments(
			[
				'post_id' => absint( $order_id ),
				'type'    => 'order_note',
				'status'  => 'approve',
				'number'  => 0,
			]
		);

		if ( empty( $comments ) || ! is_array( $comments ) ) {
			return [];
		}

		$note_ids = [];

		foreach ( $comments as $comment ) {
			$content = (string) $comment->comment_content;

			if ( false === strpos( $content, 'YoRo AI' ) ) {
				continue;
			}

			$note_ids[] = absint( $comment->comment_ID );
		}

		return array_values( array_filter( array_unique( $note_ids ) ) );
	}

	private static function normalize_context( $context ) {
		if ( ! is_array( $context ) ) {
			$context = [];
		}

		if ( empty( $context['last_order_ids'] ) && ! empty( $context['last_orders'] ) && is_array( $context['last_orders'] ) ) {
			$ids = [];

			foreach ( $context['last_orders'] as $order ) {
				if ( is_array( $order ) && ! empty( $order['id'] ) ) {
					$ids[] = absint( $order['id'] );
				}
			}

			$context['last_order_ids'] = array_values(
				array_filter(
					array_unique( $ids )
				)
			);
		}

		if ( empty( $context['last_referenced_order_id'] ) && ! empty( $context['current_order_id'] ) ) {
			$context['last_referenced_order_id'] = absint( $context['current_order_id'] );
		}

		return $context;
	}

	private static function summarize_evaluation_results( $results ) {
		$summary = [
			'total'             => count( $results ),
			'passed'            => 0,
			'failed'            => 0,
			'clarify_rate'      => 0,
			'wrong_action_rate' => 0,
			'wrong_intent_rate' => 0,
			'semantic_frame_cases' => 0,
			'semantic_frame_repaired' => 0,
			'semantic_frame_repair_rate' => '0.0%',
			'by_pack'           => [],
			'by_domain'         => [],
			'by_intent'         => [],
			'by_family'         => [],
			'by_language'       => [],
			'by_source'         => [],
			'by_outcome'        => [],
		];

		foreach ( $results as $result ) {
			$passed  = ! empty( $result['passed'] );
			$pack    = sanitize_key( $result['pack'] ?? 'baseline' );
			$domain  = sanitize_key( $result['domain'] ?? 'general' );
			$intent  = sanitize_key( $result['expected_intent'] ?? 'unknown' );
			$family  = sanitize_key( $result['expected_intent_family'] ?? $result['trace']['intent_family'] ?? $intent ?: 'unknown' );
			$language = sanitize_key( $result['expected_language'] ?? $result['trace']['language'] ?? 'unknown' );
			$source  = sanitize_key( $result['trace']['source'] ?? 'unknown' );
			$outcome = sanitize_key( $result['outcome'] ?? 'unknown' );
			$semantic_frame_present = ! empty( $result['trace']['semantic_frame_present'] );
			$semantic_frame_repair_count = absint( $result['trace']['semantic_frame_repair_count'] ?? 0 );

			if ( $passed ) {
				++$summary['passed'];
			} else {
				++$summary['failed'];
			}

			self::bump_evaluation_bucket( $summary['by_pack'], $pack, $passed );
			self::bump_evaluation_bucket( $summary['by_domain'], $domain, $passed );
			self::bump_evaluation_bucket( $summary['by_intent'], $intent ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_family'], $family ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_language'], $language ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_source'], $source ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_outcome'], $outcome ?: 'unknown', $passed );
			self::annotate_evaluation_semantic_metrics( $summary['by_language'][ $language ?: 'none' ], $semantic_frame_present, $semantic_frame_repair_count );

			if ( $semantic_frame_present ) {
				++$summary['semantic_frame_cases'];
			}

			if ( $semantic_frame_repair_count > 0 ) {
				++$summary['semantic_frame_repaired'];
			}
		}

		if ( $summary['total'] > 0 ) {
			$summary['clarify_rate']      = self::format_rate( $summary['by_outcome']['clarify']['total'] ?? 0, $summary['total'] );
			$summary['wrong_action_rate'] = self::format_rate( $summary['by_outcome']['wrong_action']['total'] ?? 0, $summary['total'] );
			$summary['wrong_intent_rate'] = self::format_rate( $summary['by_outcome']['wrong_intent']['total'] ?? 0, $summary['total'] );
			$summary['semantic_frame_repair_rate'] = self::format_rate( $summary['semantic_frame_repaired'], $summary['total'] );
		}

		foreach ( $summary['by_language'] as $language => $bucket ) {
			$summary['by_language'][ $language ]['semantic_frame_rate'] = self::format_rate( $bucket['semantic_frame_cases'] ?? 0, $bucket['total'] ?? 0 );
			$summary['by_language'][ $language ]['semantic_frame_repair_rate'] = self::format_rate( $bucket['semantic_frame_repaired'] ?? 0, $bucket['total'] ?? 0 );
		}

		return $summary;
	}

	private static function normalize_result_for_assertion( $value ) {
		if ( is_wp_error( $value ) ) {
			return [
				'is_wp_error' => true,
				'code'        => $value->get_error_code(),
				'message'     => $value->get_error_message(),
				'data'        => $value->get_error_data(),
			];
		}

		if ( true === $value ) {
			return [
				'success' => true,
			];
		}

		if ( false === $value || null === $value ) {
			return $value;
		}

		if ( $value instanceof WP_REST_Response ) {
			return $value->get_data();
		}

		return $value;
	}

	private static function decorate_evaluation_result( $actual ) {
		if ( ! is_array( $actual ) ) {
			return $actual;
		}

		if ( empty( $actual['action'] ) && ! empty( $actual['command_decision']['action'] ) ) {
			$actual['action'] = sanitize_text_field( $actual['command_decision']['action'] );
		}

		if ( empty( $actual['action'] ) && ! empty( $actual['current_workflow']['action'] ) ) {
			$actual['action'] = sanitize_text_field( $actual['current_workflow']['action'] );
		}

		if ( empty( $actual['parser_source'] ) && ! empty( $actual['parser_rule'] ) ) {
			$parser_rule = sanitize_text_field( $actual['parser_rule'] );

			if ( 0 === strpos( $parser_rule, 'ai.' ) ) {
				$actual['parser_source'] = 'ai_router';
			} elseif ( 0 === strpos( $parser_rule, 'guard.' ) ) {
				$actual['parser_source'] = 'guard';
			} elseif ( 0 === strpos( $parser_rule, 'rule.' ) ) {
				$actual['parser_source'] = 'rule';
			}
		}

		$canonical_intent = self::canonicalize_evaluation_intent_name(
			$actual['intent']['name'] ?? '',
			$actual['action'] ?? ''
		);
		$actual['language'] = sanitize_key( $actual['language'] ?? $actual['command_decision']['language'] ?? '' );
		$actual['intent_family'] = sanitize_key(
			$actual['intent_family'] ?? $actual['command_decision']['intent_family'] ?? $canonical_intent
		);

		if ( $canonical_intent ) {
			$actual['intent'] = [
				'name'   => $canonical_intent,
				'source' => empty( $actual['intent']['name'] ) ? 'derived_from_action' : 'canonicalized_for_evaluation',
			];
		}

		if ( empty( $actual['intent']['name'] ) ) {
			$inferred_intent = self::infer_intent_name_from_action( $actual['action'] ?? '' );

			if ( $inferred_intent ) {
				$actual['intent'] = [
					'name'   => $inferred_intent,
					'source' => 'derived_from_action',
				];
			}
		}

		if ( empty( $actual['stage'] ) && ! empty( $actual['action'] ) ) {
			$actual['stage'] = 'execute';
		}

		return $actual;
	}

	private static function canonicalize_evaluation_intent_name( $intent_name, $action = '' ) {
		$intent_name = sanitize_key( $intent_name );
		$action      = sanitize_text_field( $action );

		$action_mapped_intent = self::infer_intent_name_from_action( $action );

		if ( $action_mapped_intent ) {
			return $action_mapped_intent;
		}

		return $intent_name;
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

	private static function classify_evaluation_outcome( $test, $actual ) {
		$expected_stage  = $test['expected_stage'] ?? '';
		$actual_stage    = self::get_path( $actual, 'stage', '' );
		$expected_action = $test['expected_action'] ?? null;
		$actual_action   = self::get_path( $actual, 'action', null );
		$expected_intent = $test['expected_intent'] ?? null;
		$actual_intent   = self::get_path( $actual, 'intent.name', null );

		if ( 'clarify' === $actual_stage && 'clarify' !== $expected_stage ) {
			return 'clarify';
		}

		if ( null !== $expected_action && $expected_action !== $actual_action ) {
			return 'wrong_action';
		}

		if ( null !== $expected_intent && $expected_intent !== $actual_intent ) {
			return 'wrong_intent';
		}

		if ( 'clarify' === $expected_stage && 'clarify' !== $actual_stage ) {
			return 'missed_clarify';
		}

		return 'passed';
	}

	private static function infer_intent_name_from_action( $action ) {
		$action = sanitize_text_field( $action );

		if ( ! $action ) {
			return '';
		}

		$map = [
			'store.get_business_summary'      => 'business_summary',
			'store.get_morning_briefing'      => 'store_briefing',
			'store.get_daily_briefing'        => 'store_briefing',
			'operations.get_summary'          => 'operations_overview',
			'operations.get_attention_queue'  => 'operations_attention',
			'operations.get_fulfillment_queue'=> 'fulfillment_review',
			'products.get_inventory_queue'    => 'inventory_review',
			'customers.get_service_queue'     => 'customer_service_review',
			'orders.search'                   => 'search_orders',
			'orders.get_details'              => 'get_order_details',
			'orders.find_by_customer'         => 'find_customer_orders',
			'orders.update_status'            => 'update_order_status',
			'orders.bulk_update_status_with_note' => 'update_order_status',
				'orders.hold'                     => 'hold_order',
				'orders.add_note'                 => 'add_note',
				'orders.compose_customer_email'   => 'customer_email',
				'orders.get_customer_email_drafts' => 'customer_email',
				'orders.save_customer_email_draft' => 'customer_email',
				'orders.update_customer_email_draft' => 'customer_email',
				'orders.delete_customer_email_draft' => 'customer_email',
				'orders.render_customer_email_preview' => 'customer_email',
				'orders.send_customer_email'      => 'customer_email',
				'workflow.write_email_for_order'  => 'customer_email',
				'workflow.write_and_save_email_draft_for_order' => 'customer_email',
			'workflow.write_and_send_email_for_order' => 'customer_email',
			'workflow.write_email_for_orders' => 'customer_email',
			'workflow.write_and_save_email_drafts_for_orders' => 'customer_email',
			'workflow.write_and_send_emails_for_orders' => 'customer_email',
			'customers.get_summary'           => 'customer_summary',
		];

		return $map[ $action ] ?? '';
	}

	private static function bump_evaluation_bucket( &$bucket, $key, $passed ) {
		if ( ! isset( $bucket[ $key ] ) ) {
			$bucket[ $key ] = [
				'total'  => 0,
				'passed' => 0,
				'failed' => 0,
			];
		}

		++$bucket[ $key ]['total'];

		if ( $passed ) {
			++$bucket[ $key ]['passed'];
		} else {
			++$bucket[ $key ]['failed'];
		}
	}

	private static function annotate_evaluation_semantic_metrics( &$bucket, $semantic_frame_present, $semantic_frame_repair_count ) {
		if ( ! is_array( $bucket ) ) {
			$bucket = [];
		}

		foreach ( [ 'semantic_frame_cases', 'semantic_frame_repaired', 'semantic_frame_repair_events' ] as $key ) {
			if ( ! isset( $bucket[ $key ] ) ) {
				$bucket[ $key ] = 0;
			}
		}

		if ( $semantic_frame_present ) {
			++$bucket['semantic_frame_cases'];
		}

		if ( $semantic_frame_repair_count > 0 ) {
			++$bucket['semantic_frame_repaired'];
			$bucket['semantic_frame_repair_events'] += absint( $semantic_frame_repair_count );
		}
	}

	private static function evaluation_semantic_frame_is_informative( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];

		if ( class_exists( 'YoRo_Semantic_Frame_Normalizer' ) ) {
			return YoRo_Semantic_Frame_Normalizer::is_informative( $frame );
		}

		return ! empty( $frame ) && (
			'unknown' !== sanitize_key( $frame['surface'] ?? 'unknown' ) ||
			'unknown' !== sanitize_key( $frame['operation'] ?? 'unknown' ) ||
			'unknown' !== sanitize_key( $frame['target_type'] ?? 'unknown' )
		);
	}

	private static function format_rate( $count, $total ) {
		if ( $total <= 0 ) {
			return '0.0%';
		}

		return number_format_i18n( ( $count / $total ) * 100, 1 ) . '%';
	}

	private static function values_equal( $expected, $actual ) {
		if ( is_int( $expected ) ) {
			return absint( $actual ) === $expected;
		}

		if ( is_float( $expected ) ) {
			return (float) $actual === $expected;
		}

		if ( is_bool( $expected ) ) {
			return (bool) $actual === $expected;
		}

		if ( is_array( $expected ) ) {
			return $expected === $actual;
		}

		if ( null === $expected ) {
			return null === $actual || '' === $actual;
		}

		return (string) $actual === (string) $expected;
	}

	private static function get_path( $array, $path, $default = null ) {
		if ( ! is_array( $array ) ) {
			return $default;
		}

		$segments = explode( '.', $path );
		$current  = $array;

		foreach ( $segments as $segment ) {
			if ( is_array( $current ) && array_key_exists( $segment, $current ) ) {
				$current = $current[ $segment ];
				continue;
			}

			if ( is_array( $current ) && is_numeric( $segment ) ) {
				$index = (int) $segment;

				if ( array_key_exists( $index, $current ) ) {
					$current = $current[ $index ];
					continue;
				}
			}

			return $default;
		}

		return $current;
	}
}
