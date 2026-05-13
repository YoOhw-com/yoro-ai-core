<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Test_Suite {

	private static $router_fixture_map = null;

	public static function run() {
		$group_runs = [
			'parser'   => self::run_parser_tests(),
			'pipeline' => self::run_pipeline_tests(),
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
		add_filter(
			'yoro_ai_command_router_mock_decision',
			[ __CLASS__, 'mock_command_router_decision' ],
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
		self::$router_fixture_map = null;
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
				__( 'AI router fixture intentionally falls back to deterministic rules for multi-step order tests.', 'yoro-agent-core' )
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

	private static function router_fixture_has_params( $fixture ) {
		return is_array( $fixture ) && ! empty( $fixture['params'] ) && is_array( $fixture['params'] );
	}

	private static function build_router_fixture_decision_for_case( $test ) {
		if ( self::should_router_fixture_fallback_to_rules( $test ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'AI router fixture intentionally falls back to deterministic rules for this test.', 'yoro-agent-core' )
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
				__( 'I need a little more detail before I can run that action.', 'yoro-agent-core' )
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

	private static function should_router_fixture_fallback_to_rules( $test ) {
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

		if ( false !== strpos( $normalized, 'monthly' ) || false !== strpos( $normalized, 'this month' ) ) {
			$params['window'] = 'last_30_days';
		} elseif ( false !== strpos( $normalized, 'weekly' ) || false !== strpos( $normalized, 'this week' ) ) {
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
			'Show pending orders'                         => self::base_router_fixture_decision(
				'task_request',
				'orders.search',
				[
					'status' => [ 'pending' ],
				],
				0.95
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
					'window' => 'last_7_days',
					'focus'  => 'overview',
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
		return [
			'mode'                   => $mode,
			'language'               => 'en',
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

	public static function run_evaluation_tests() {
		$results = [];

		foreach ( self::evaluation_cases() as $test ) {
			$results[] = self::run_evaluation_case( $test );
		}

		return [
			'results' => $results,
			'summary' => self::summarize_evaluation_results( $results ),
		];
	}

	public static function run_hardening_tests() {
		$results = [];

		foreach ( self::real_query_hardening_cases() as $test ) {
			$results[] = self::run_evaluation_case( $test );
		}

		return [
			'results' => $results,
			'summary' => self::summarize_evaluation_results( $results ),
		];
	}

	private static function run_parser_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );

		$parsed = YoRo_Parser_Engine::parse(
			$test['command'],
			$context
		);

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
		$source = self::get_path( $actual, 'parser_source', '' );
		$stage  = self::get_path( $actual, 'stage', '' );
		$action = self::get_path( $actual, 'action', '' );

		return [
			'type'          => 'evaluation',
			'name'          => $test['name'] ?? $test['command'],
			'command'       => $test['command'],
			'domain'        => $test['domain'] ?? 'general',
			'expected_intent' => $test['expected_intent'] ?? '',
			'expected_action' => $test['expected_action'] ?? '',
			'passed'        => $passed,
			'outcome'       => self::classify_evaluation_outcome( $test, $actual ),
			'assertions'    => $assertions,
			'parsed'        => $actual,
			'trace'         => [
				'intent'     => $intent,
				'action'     => $action,
				'source'     => $source,
				'stage'      => $stage,
				'confidence' => self::get_path( $actual, 'confidence', null ),
			],
		];
	}

	private static function run_planner_case( $test ) {
		$context = self::normalize_context( $test['context'] ?? [] );

		$parsed = self::run_full_pipeline(
			$test['command'],
			$context
		);

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
			$request = new WP_REST_Request( $rest_method, '/yoro-agent/v1/test' );
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
					'response_only'  => true,
					'result.summary' => 'Hi. I can help with WooCommerce store operations.',
				],
			],
			[
				'domain'          => 'casual_chat',
				'name'            => 'Identity question does not expose classifier wording',
				'command'         => 'Do you know who am I?',
				'expected_source' => 'ai_router',
				'expect'          => [
					'response_only'  => true,
					'result.summary' => 'I don’t know who you are from this chat alone. I can use store context when you ask about orders, customers, or operations.',
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
					'params.window'    => 'today',
					'params.limit'     => 1,
					'params.order_id'  => null,
					'params.order_ids' => null,
				],
			],
			[
				'domain'         => 'email_followup',
				'name'           => 'Email compose clarification stores pending instruction workflow',
				'command'        => 'Write an email for order 6848',
				'expected_stage' => 'clarify',
				'expected_source' => 'ai_router',
				'expect'         => [
					'current_workflow.type'     => 'customer_email',
					'current_workflow.step'     => 'collect_missing_params',
					'current_workflow.action'   => 'orders.compose_customer_email',
					'current_workflow.order_id' => 6848,
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
					'params.order_id'    => 6848,
					'params.instruction' => 'About shipping delay',
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
					'params.window' => 'last_7_days',
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
					'params.window' => 'last_7_days',
					'params.limit'  => 5,
				],
			],
			[
				'name'            => 'Store insights accepts monthly window and limit',
				'command'         => 'Show 8 store insights for this month',
				'expected_action' => 'store.get_insights',
				'expect'          => [
					'params.window' => 'last_30_days',
					'params.limit'  => 8,
				],
			],
			[
				'name'            => 'Business summary routes to business summary action',
				'command'         => 'Show me summary of the business of this month',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'How is our business doing routes to business summary',
				'command'         => 'How is our business doing this month?',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'How is our business this week routes to business summary',
				'command'         => 'How is our business this week?',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'last_7_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Business performance routes to business summary',
				'command'         => 'Show business performance this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Business operations focus routes to business summary',
				'command'         => 'Show business summary for this week with operations focus',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
					'params.focus'  => 'operations',
				],
			],
			[
				'name'            => 'Show me the business routes to business summary',
				'command'         => 'Show me the business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'intent.name'   => 'business_summary',
					'params.window' => 'last_7_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Summary the business routes to business summary',
				'command'         => 'Summary the business this week',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
					'params.focus'  => 'overview',
				],
			],
			[
				'name'            => 'Whats new this week routes to operations summary',
				'command'         => 'Tell me what\'s new this week?',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'intent.name'   => 'operations_overview',
					'params.window' => 'last_7_days',
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
					'params.window' => 'last_7_days',
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
				'expected_action' => 'orders.find_by_customer',
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
				],
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
				'expect'          => [
					'params.order_id' => 123,
				],
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
				'name'            => 'Latest 5 fallback searches orders',
				'command'         => 'latest 5',
				'expected_action' => 'orders.search',
				'expect'          => [
					'params.limit' => 5,
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
				'expected_action'  => null,
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
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Weekly business performance phrasing',
				'command'         => 'Show business performance this week',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Monthly business summary phrasing',
				'command'         => 'Show me summary of the business of this month',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Business update phrasing',
				'command'         => 'Give me a business update for this week',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'business',
				'name'            => 'Store performance monthly phrasing',
				'command'         => 'How is store performance this month?',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Whats new weekly phrasing',
				'command'         => 'Tell me what\'s new this week?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Store doing weekly phrasing',
				'command'         => 'How is the store doing this week?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'operations',
				'name'            => 'Store look monthly phrasing',
				'command'         => 'What does the store look like this month?',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expect'          => [
					'params.window' => 'last_30_days',
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
					'params.window' => 'last_7_days',
				],
			],
			[
				'domain'          => 'attention',
				'name'            => 'Urgent now phrasing',
				'command'         => 'Anything urgent right now?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
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
			],
			[
				'domain'          => 'customer_service',
				'name'            => 'Customer follow-up list phrasing',
				'command'         => 'Show 8 customers needing follow-up',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expect'          => [
					'params.limit' => 8,
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
				'context'         => [
					'last_orders'    => [
						[ 'id' => 301 ],
						[ 'id' => 302 ],
					],
					'last_order_ids' => [ 301, 302 ],
				],
				'expect'          => [
					'params.order_id' => 301,
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
				'name'            => 'Aged pending orders should route to attention review',
				'command'         => 'Are there any orders pending for more than 3 days?',
				'expected_intent' => 'operations_attention',
				'expected_action' => 'operations.get_attention_queue',
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
					'params.window' => 'last_30_days',
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
				'domain'          => 'orchestrator_briefing',
				'name'            => 'Briefing continue plan should stay inside briefing sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.follow_up.briefing.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_customer_service',
				'name'            => 'Customer service continue plan should stay inside service sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'business_summary',
				'expected_action' => 'store.get_business_summary',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.follow_up.service.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_fulfillment',
				'name'            => 'Fulfillment continue plan should stay inside fulfillment sequence',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.follow_up.fulfillment.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_inventory',
				'name'            => 'Inventory continue plan should stay inside inventory sequence after fulfillment step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'operations_overview',
				'expected_action' => 'operations.get_summary',
				'expected_source' => 'rule',
				'context'         => [
					'last_result_type'        => 'fulfillment',
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
					'parser_rule' => 'rule.follow_up.inventory.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_operations_attention',
				'name'            => 'Operations attention continue plan should stay inside attention sequence after fulfillment step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'rule',
				'context'         => [
					'last_result_type'         => 'fulfillment',
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
					'parser_rule' => 'rule.follow_up.attention.recommendation',
				],
			],
			[
				'domain'          => 'orchestrator_business',
				'name'            => 'Business continue plan should stay inside business sequence after inventory step',
				'command'         => 'Continue the plan',
				'expected_intent' => 'customer_service_review',
				'expected_action' => 'customers.get_service_queue',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.follow_up.business.recommendation',
				],
			],
			[
				'domain'          => 'phase4_fulfillment_workflow',
				'name'            => 'Prepare next fulfillment follow-up should route to workflow preview when fulfillment context is present',
				'command'         => 'Prepare the next fulfillment follow-up',
				'expected_action' => 'workflow.fulfillment_followup_preview',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.fulfillment_followup_preview',
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
				'domain'          => 'phase4_customer_followup_workflow',
				'name'            => 'Prepare next customer follow-up should route to workflow preview when service context is present',
				'command'         => 'Prepare the next customer follow-up',
				'expected_action' => 'workflow.customer_followup_preview',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.customer_followup_preview',
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
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.operations_attention_preview',
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
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.inventory_pressure_preview',
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
				'name'            => 'Prepare next inventory review alias should route to inventory pressure preview when inventory context is present',
				'command'         => 'Review the next inventory recommendation',
				'expected_action' => 'workflow.inventory_pressure_preview',
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.inventory_pressure_preview',
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
				'expected_source' => 'rule',
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
					'parser_rule' => 'rule.workflow.business_summary_preview',
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
					'parser_rule' => 'rule.follow_up.briefing.drilldown',
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
					'parser_rule' => 'rule.follow_up.briefing.drilldown',
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
					'parser_rule' => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule' => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule'             => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule'             => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule'             => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule'             => 'rule.follow_up.briefing.recommendation',
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
					'parser_rule'           => 'rule.follow_up.service.recommendation',
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
					'parser_rule'           => 'rule.follow_up.service.recommendation',
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
					'parser_rule'                => 'rule.follow_up.fulfillment.recommendation',
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
					'parser_rule'                => 'rule.follow_up.fulfillment.recommendation',
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
					'parser_rule'              => 'rule.follow_up.inventory.recommendation',
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
					'parser_rule'              => 'rule.follow_up.inventory.recommendation',
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
					'parser_rule'             => 'rule.follow_up.attention.recommendation',
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
					'parser_rule'             => 'rule.follow_up.attention.recommendation',
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
					'parser_rule'            => 'rule.follow_up.business.recommendation',
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
					'parser_rule'            => 'rule.follow_up.business.recommendation',
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
					'parsed.action' => 'orders.hold',
				],
				'expect_unmatched' => true,
				'expect_stage'     => 'clarify',
			],
			[
				'name'             => 'Missing note should clarify',
				'command'          => 'Add note to order #123',
				'expected_action'  => null,
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
					'parsed.action'              => 'multi.hold_and_note',
					'plan.type'                  => 'sequence',
					'plan.steps.0.action'        => 'orders.hold',
					'plan.steps.1.action'        => 'orders.add_note',
					'risk.requires_confirmation' => true,
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
				'expect'  => [
					'parsed.action'       => 'multi.hold_and_note',
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
					'result.window.key'           => 'last_30_days',
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
					'parsed.action'              => 'multi.hold_and_note',
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
				__( 'WooCommerce test helpers are unavailable.', 'yoro-agent-core' )
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
				__( 'Could not create parser test order.', 'yoro-agent-core' )
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

	private static function cleanup_test_order_fixture( $fixture ) {
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
			if ( false === strpos( (string) $comment->comment_content, 'YoRo Agent' ) ) {
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
			'by_domain'         => [],
			'by_intent'         => [],
			'by_source'         => [],
			'by_outcome'        => [],
		];

		foreach ( $results as $result ) {
			$passed  = ! empty( $result['passed'] );
			$domain  = sanitize_key( $result['domain'] ?? 'general' );
			$intent  = sanitize_key( $result['expected_intent'] ?? 'unknown' );
			$source  = sanitize_key( $result['trace']['source'] ?? 'unknown' );
			$outcome = sanitize_key( $result['outcome'] ?? 'unknown' );

			if ( $passed ) {
				++$summary['passed'];
			} else {
				++$summary['failed'];
			}

			self::bump_evaluation_bucket( $summary['by_domain'], $domain, $passed );
			self::bump_evaluation_bucket( $summary['by_intent'], $intent ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_source'], $source ?: 'none', $passed );
			self::bump_evaluation_bucket( $summary['by_outcome'], $outcome ?: 'unknown', $passed );
		}

		if ( $summary['total'] > 0 ) {
			$summary['clarify_rate']      = self::format_rate( $summary['by_outcome']['clarify']['total'] ?? 0, $summary['total'] );
			$summary['wrong_action_rate'] = self::format_rate( $summary['by_outcome']['wrong_action']['total'] ?? 0, $summary['total'] );
			$summary['wrong_intent_rate'] = self::format_rate( $summary['by_outcome']['wrong_intent']['total'] ?? 0, $summary['total'] );
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
			'operations.get_summary'          => 'operations_overview',
			'operations.get_attention_queue'  => 'operations_attention',
			'operations.get_fulfillment_queue'=> 'fulfillment_review',
			'products.get_inventory_queue'    => 'inventory_review',
			'customers.get_service_queue'     => 'customer_service_review',
			'orders.search'                   => 'search_orders',
			'orders.get_details'              => 'get_order_details',
			'orders.find_by_customer'         => 'find_customer_orders',
			'orders.update_status'            => 'update_order_status',
			'orders.hold'                     => 'hold_order',
			'orders.add_note'                 => 'add_note',
			'orders.compose_customer_email'   => 'email_customer',
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
