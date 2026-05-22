<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_API {

	const REST_NAMESPACE        = 'yoro-ai-core/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
	}

	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/actions',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_actions' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/context',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_context' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/parse',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'parse_command' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/execute',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'execute_action' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/run',
				[
					'methods'             => 'POST',
					'callback'            => [ __CLASS__, 'run' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/memory',
			[
				'methods'  => 'GET',
				'callback' => [ __CLASS__, 'get_memory' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/proactive/morning-briefing',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_morning_briefing' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/proactive/morning-briefing-dismiss',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'dismiss_morning_briefing' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/undo',
			[
				'methods'  => 'POST',
				'callback' => [ __CLASS__, 'undo_last_action' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/workflow-templates',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_workflow_templates' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-api/status',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_ai_api_status' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/ai-api/handshake',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'test_ai_api_handshake' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/parser-test',
				[
					'methods'  => 'GET',
					'callback' => [ __CLASS__, 'parser_test' ],
					'args'     => [
						'group' => [
							'required' => false,
							'type'     => 'string',
						],
						'pack' => [
							'required' => false,
							'type'     => 'string',
						],
					],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				]
			);

			register_rest_route(
				self::REST_NAMESPACE,
				'/real-query-pack',
				[
					'methods'  => 'GET',
					'callback' => [ __CLASS__, 'real_query_pack' ],
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				]
			);
		}
	}

	public static function permission_check() {
		return current_user_can( 'manage_woocommerce' );
	}

	public static function settings_permission_check() {
		return current_user_can( 'manage_options' );
	}

	public static function get_actions() {
		return rest_ensure_response(
			[
				'success' => true,
				'actions' => YoRo_Action_Registry::all(),
			]
		);
	}

	public static function get_context() {
		return rest_ensure_response(
			[
				'success' => true,
				'context' => YoRo_Context_Provider::get_context(),
			]
		);
	}

	public static function parse_command( WP_REST_Request $request ) {
		$command = sanitize_textarea_field( $request->get_param( 'command' ) );

		$result = YoRo_Parser_Engine::parse( $command );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			[
				'success' => true,
				'parsed'  => $result,
			]
		);
	}

	public static function execute_action( WP_REST_Request $request ) {
		return YoRo_REST_Execute_Handler::handle( $request );
	}

	public static function run( WP_REST_Request $request ) {
		return YoRo_REST_Run_Handler::handle( $request );
	}

	public static function get_memory() {
		$user_id = get_current_user_id();

		$memory           = YoRo_Conversation_Memory::get( $user_id );
		$structured_state = YoRo_Conversation_Memory::get_structured_state( $user_id );
		$runtime_context  = YoRo_Conversation_Memory::get_runtime_context( $user_id );

		return rest_ensure_response(
			[
				'success'          => true,
				'memory'           => $memory,
				'structured_state' => $structured_state,
				'runtime_context'  => $runtime_context,
			]
		);
	}

	public static function get_morning_briefing( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$now = self::resolve_client_datetime( $request );
		$today_key = $now->format( 'Y-m-d' );
		$test_hour = $request->get_param( 'test_hour' );
		$hour = is_numeric( $test_hour ) ? (int) $test_hour : (int) $now->format( 'G' );
		$force_window = (bool) $request->get_param( 'force_window' );
		$reset_state = (bool) $request->get_param( 'reset_state' );

		if ( $reset_state ) {
			YoRo_Conversation_Memory::update(
				$user_id,
				[
					'conversation' => [
						'morning_briefing_state' => [],
					],
				]
			);
		}

		$state = YoRo_Conversation_Memory::get_morning_briefing_state( $user_id );

		if ( ! $force_window && ( $hour < 6 || $hour >= 11 ) ) {
			return rest_ensure_response(
				[
					'success'     => true,
					'should_show' => false,
					'reason'      => 'outside_morning_window',
				]
			);
		}

		if ( sanitize_text_field( $state['last_dismissed_date'] ?? '' ) === $today_key ) {
			return rest_ensure_response(
				[
					'success'     => true,
					'should_show' => false,
					'reason'      => 'dismissed_today',
				]
			);
		}

		if ( sanitize_text_field( $state['last_shown_date'] ?? '' ) === $today_key ) {
			return rest_ensure_response(
				[
					'success'     => true,
					'should_show' => false,
					'reason'      => 'already_shown_today',
				]
			);
		}

		$result = YoRo_Orders_Actions::get_morning_briefing(
			[
				'window'           => 'today',
				'yesterday_window' => 'yesterday',
				'limit'            => 5,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$signature = sanitize_text_field( $result['signature'] ?? '' );
		YoRo_Conversation_Memory::mark_morning_briefing_shown( $user_id, $today_key, $signature );

		YoRo_REST_Memory_Sync::maybe_update(
			$user_id,
			'proactive:morning_briefing',
			[
				'success'       => true,
				'matched'       => true,
				'action'        => 'store.get_morning_briefing',
				'params'        => [
					'window'           => 'today',
					'yesterday_window' => 'yesterday',
					'limit'            => 5,
				],
				'parser_source' => 'proactive',
				'parser_rule'   => 'proactive.morning_briefing.auto',
				'confidence'    => 1,
				'entities'      => [],
			],
			$result
		);

		return rest_ensure_response(
			[
				'success'     => true,
				'should_show' => true,
				'stage'       => 'done',
				'proactive'   => true,
				'parsed'      => [
					'success'       => true,
					'matched'       => true,
					'action'        => 'store.get_morning_briefing',
					'parser_source' => 'proactive',
					'parser_rule'   => 'proactive.morning_briefing.auto',
					'confidence'    => 1,
				],
				'plan'        => [
					'type'   => 'single',
					'action' => 'store.get_morning_briefing',
					'params' => [
						'window'           => 'today',
						'yesterday_window' => 'yesterday',
						'limit'            => 5,
					],
				],
				'result'      => $result,
			]
		);
	}

	public static function dismiss_morning_briefing( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$today_key = self::resolve_client_datetime( $request )->format( 'Y-m-d' );

		YoRo_Conversation_Memory::dismiss_morning_briefing( $user_id, $today_key );

		return rest_ensure_response(
			[
				'success'   => true,
				'dismissed' => true,
				'date'      => $today_key,
			]
		);
	}

	public static function undo_last_action( WP_REST_Request $request ) {
		return YoRo_REST_Undo_Handler::handle( $request );
	}

	public static function get_workflow_templates() {
		return rest_ensure_response(
			[
				'success'   => true,
				'templates' => class_exists( 'YoRo_Workflow_Templates' )
					? YoRo_Workflow_Templates::to_public_array()
					: [],
			]
		);
	}

	public static function get_ai_api_status() {
		return rest_ensure_response( self::build_ai_api_status_response() );
	}

	public static function test_ai_api_handshake() {
		if ( ! class_exists( 'YoRo_AI_API_Client' ) ) {
			return new WP_Error(
				'yoro_ai_api_client_missing',
				__( 'YoRo AI API client is not available.', 'yoro-ai-core' ),
				[ 'status' => 500 ]
			);
		}

		$result = ( new YoRo_AI_API_Client() )->handshake();

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				[
					'status' => 502,
					'ai_api_status' => self::build_ai_api_status_response(),
				]
			);
		}

		return rest_ensure_response( self::build_ai_api_status_response( $result ) );
	}

	private static function build_ai_api_status_response( $handshake_result = null ) {
		$status       = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_status() : [];
		$entitlements = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_entitlements() : [];
		$usage        = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_usage() : [];

		if ( is_array( $handshake_result ) ) {
			$status       = $handshake_result;
			$entitlements = isset( $handshake_result['entitlements'] ) && is_array( $handshake_result['entitlements'] ) ? $handshake_result['entitlements'] : [];
			$usage        = isset( $handshake_result['usage'] ) && is_array( $handshake_result['usage'] ) ? $handshake_result['usage'] : [];
		}

		$api_url = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_url() : '';

		return [
			'success'             => true,
			'service'             => 'yoro-ai-api',
			'provider_preference' => class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_provider_preference() : '',
			'resolved_provider'   => class_exists( 'YoRo_AI_Provider_Resolver' ) ? YoRo_AI_Provider_Resolver::resolve_provider() : '',
			'configured'          => [
				'api_url'          => esc_url_raw( $api_url ),
				'api_url_present'  => '' !== $api_url,
				'site_key_present' => class_exists( 'YoRo_AI_Settings' ) ? '' !== YoRo_AI_Settings::get_ai_api_site_key() : false,
				'site_key_source'  => class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_ai_api_site_key_source() : 'missing',
				'enabled'          => class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::is_ai_api_enabled() : false,
			],
			'connection'          => [
				'connected'  => ! empty( $status['connected'] ),
				'checked_at' => sanitize_text_field( $status['checked_at'] ?? '' ),
				'message'    => sanitize_text_field( $status['message'] ?? '' ),
			],
			'site'                => isset( $status['site'] ) && is_array( $status['site'] ) ? $status['site'] : [],
			'entitlements'        => $entitlements,
			'usage'               => $usage,
			'capabilities'        => [
				'command_router' => class_exists( 'YoRo_AI_Provider_Resolver' ) ? YoRo_AI_Provider_Resolver::supports( 'intent' ) : false,
				'email_compose'  => class_exists( 'YoRo_AI_Provider_Resolver' ) ? YoRo_AI_Provider_Resolver::supports( 'email_compose' ) : false,
				'workflow_plan'  => class_exists( 'YoRo_AI_Provider_Resolver' ) ? YoRo_AI_Provider_Resolver::supports( 'workflow_plan' ) : false,
				'suggestions'    => class_exists( 'YoRo_AI_Provider_Resolver' ) ? YoRo_AI_Provider_Resolver::supports( 'suggestions' ) : false,
			],
		];
	}
	
	public static function get_last_executed( $user_id ) {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE user_id = %d AND status = 'executed' ORDER BY id DESC LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
	}

	public static function parser_test( WP_REST_Request $request ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return new WP_Error(
				'yoro_disabled',
				'Parser test only available in debug mode'
			);
		}

		if ( ! class_exists( 'YoRo_Parser_Test_Suite' ) ) {
			return new WP_Error(
				'yoro_parser_test_suite_missing',
				'YoRo parser test suite is not loaded.'
			);
		}

		$group = sanitize_key( (string) $request->get_param( 'group' ) );
		$pack  = sanitize_key( (string) $request->get_param( 'pack' ) );

		switch ( $group ) {
			case 'evaluation':
				$result = YoRo_Parser_Test_Suite::run_evaluation_tests( $pack ?: 'all' );
				break;
			case 'real_traffic':
				$result = YoRo_Parser_Test_Suite::run_real_traffic_eval_tests();
				break;
			case 'hardening':
				$result = YoRo_Parser_Test_Suite::run_hardening_tests();
				break;
			case 'parser':
				$result = YoRo_Parser_Test_Suite::run_parser_tests();
				break;
			default:
				$result = YoRo_Parser_Test_Suite::run();
				break;
		}

		return rest_ensure_response(
			$result
		);
	}

	public static function real_query_pack() {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return new WP_Error(
				'yoro_disabled',
				'Real-query pack only available in debug mode'
			);
		}

		return rest_ensure_response(
			YoRo_Logger::build_real_query_evaluation_pack()
		);
	}

	private static function resolve_client_datetime( WP_REST_Request $request ) {
		$browser_timezone = sanitize_text_field( (string) $request->get_param( 'browser_timezone' ) );

		if ( '' !== $browser_timezone ) {
			try {
				return new DateTimeImmutable( 'now', new DateTimeZone( $browser_timezone ) );
			} catch ( Exception $e ) {
				// Fall back to the WordPress site timezone when the browser timezone is invalid.
			}
		}

		return current_datetime();
	}
}
