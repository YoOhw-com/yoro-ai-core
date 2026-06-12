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
			'/execute',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'execute_action' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/actions/cancel',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'cancel_conversation_action' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat/message',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'chat_message' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/chat/conversation',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_chat_conversation' ],
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

	public static function execute_action( WP_REST_Request $request ) {
		return YoRo_REST_Execute_Handler::handle( $request );
	}

	public static function cancel_conversation_action( WP_REST_Request $request ) {
		return YoRo_REST_Conversation_Action_Coordinator::record_cancelled_action( $request );
	}

	public static function chat_message( WP_REST_Request $request ) {
		return YoRo_REST_Chat_Message_Handler::handle( $request );
	}

	public static function get_chat_conversation( WP_REST_Request $request ) {
		return YoRo_REST_Chat_Message_Handler::get_conversation( $request );
	}

	public static function undo_last_action( WP_REST_Request $request ) {
		return YoRo_REST_Undo_Handler::handle( $request );
	}

	public static function get_ai_api_status() {
		return rest_ensure_response( self::build_ai_api_status_response() );
	}

	public static function test_ai_api_handshake() {
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
		$status       = YoRo_AI_Settings::get_ai_api_status();
		$entitlements = YoRo_AI_Settings::get_ai_api_entitlements();
		$usage        = YoRo_AI_Settings::get_ai_api_usage();

		if ( is_array( $handshake_result ) ) {
			$status       = $handshake_result;
			$entitlements = isset( $handshake_result['entitlements'] ) && is_array( $handshake_result['entitlements'] ) ? $handshake_result['entitlements'] : [];
			$usage        = isset( $handshake_result['usage'] ) && is_array( $handshake_result['usage'] ) ? $handshake_result['usage'] : [];
		}

		$api_url = YoRo_AI_Settings::get_ai_api_url();

		return [
			'success'             => true,
			'service'             => 'yoro-ai-api',
			'provider_preference' => YoRo_AI_Settings::get_provider_preference(),
			'resolved_provider'   => YoRo_AI_Provider_Resolver::resolve_provider(),
			'dashboard_url'       => YoRo_AI_Settings::get_ai_api_dashboard_url(),
			'conversation_dashboard_base_url' => YoRo_AI_Settings::get_ai_api_conversation_dashboard_base_url(),
			'configured'          => [
				'api_url'          => esc_url_raw( $api_url ),
				'api_url_present'  => '' !== $api_url,
				'site_key_present' => '' !== YoRo_AI_Settings::get_ai_api_site_key(),
				'site_key_source'  => YoRo_AI_Settings::get_ai_api_site_key_source(),
				'enabled'          => YoRo_AI_Settings::is_ai_api_enabled(),
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
				'conversation'   => YoRo_AI_Provider_Resolver::supports( 'conversation' ),
				'email_compose'  => YoRo_AI_Provider_Resolver::supports( 'email_compose' ),
			],
		];
	}

}
