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

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/gmail/connect-intents',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_gmail_mailbox_connect_intent' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/gmail/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'disconnect_gmail_mailbox' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/microsoft/connect-intents',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'create_microsoft_mailbox_connect_intent' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/imap/connect',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'connect_imap_mailbox' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/disconnect',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'disconnect_mailbox' ],
				'permission_callback' => [ __CLASS__, 'settings_permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/inbox',
			[
				'methods'             => 'GET',
				'callback'            => [ __CLASS__, 'get_mailbox_inbox' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/inbox/sync',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'sync_mailbox_inbox' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)',
			[
				[
					'methods'             => 'GET',
					'callback'            => [ __CLASS__, 'get_mailbox_thread' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
				[
					'methods'             => 'PATCH',
					'callback'            => [ __CLASS__, 'update_mailbox_thread' ],
					'permission_callback' => [ __CLASS__, 'permission_check' ],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/reply',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'reply_mailbox_thread' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/archive',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'archive_mailbox_thread' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/reopen',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'reopen_mailbox_thread' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/read',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'mark_mailbox_thread_read' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/unread',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'mark_mailbox_thread_unread' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/notes',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'add_mailbox_thread_note' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/email/threads/(?P<thread_id>\d+)/messages/(?P<message_id>\d+)/retry',
			[
				'methods'             => 'POST',
				'callback'            => [ __CLASS__, 'retry_mailbox_thread_reply' ],
				'permission_callback' => [ __CLASS__, 'permission_check' ],
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

	public static function create_gmail_mailbox_connect_intent( WP_REST_Request $request ) {
		$return_url              = self::mailbox_return_url();
		$suggested_mailbox_email = sanitize_email(
			(string) (
				$request->get_param( 'suggested_mailbox_email' )
				?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) )
			)
		);
		$result                  = ( new YoRo_AI_API_Client() )->create_gmail_mailbox_connect_intent(
			[
				'return_url'               => $return_url,
				'plugin_version'           => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'suggested_mailbox_email' => $suggested_mailbox_email,
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function disconnect_gmail_mailbox() {
		$result = ( new YoRo_AI_API_Client() )->disconnect_gmail_mailbox();

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function create_microsoft_mailbox_connect_intent( WP_REST_Request $request ) {
		$return_url              = self::mailbox_return_url();
		$suggested_mailbox_email = sanitize_email(
			(string) (
				$request->get_param( 'suggested_mailbox_email' )
				?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) )
			)
		);
		$result                  = ( new YoRo_AI_API_Client() )->create_microsoft_mailbox_connect_intent(
			[
				'return_url'               => $return_url,
				'plugin_version'           => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'suggested_mailbox_email' => $suggested_mailbox_email,
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function connect_imap_mailbox( WP_REST_Request $request ) {
		$suggested_mailbox_email = sanitize_email(
			(string) (
				$request->get_param( 'suggested_mailbox_email' )
				?: get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) )
			)
		);
		$result                  = ( new YoRo_AI_API_Client() )->connect_imap_mailbox(
			[
				'mailbox_email'           => sanitize_email( (string) $request->get_param( 'mailbox_email' ) ),
				'display_name'            => sanitize_text_field( (string) $request->get_param( 'display_name' ) ),
				'imap_host'               => sanitize_text_field( (string) $request->get_param( 'imap_host' ) ),
				'imap_port'               => absint( $request->get_param( 'imap_port' ) ?: 993 ),
				'imap_encryption'         => sanitize_key( (string) ( $request->get_param( 'imap_encryption' ) ?: 'ssl' ) ),
				'imap_username'           => sanitize_text_field( (string) $request->get_param( 'imap_username' ) ),
				'imap_password'           => (string) $request->get_param( 'imap_password' ),
				'smtp_host'               => sanitize_text_field( (string) $request->get_param( 'smtp_host' ) ),
				'smtp_port'               => absint( $request->get_param( 'smtp_port' ) ?: 587 ),
				'smtp_encryption'         => sanitize_key( (string) ( $request->get_param( 'smtp_encryption' ) ?: 'tls' ) ),
				'smtp_username'           => sanitize_text_field( (string) $request->get_param( 'smtp_username' ) ),
				'smtp_password'           => (string) $request->get_param( 'smtp_password' ),
				'plugin_version'          => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'suggested_mailbox_email' => $suggested_mailbox_email,
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function disconnect_mailbox() {
		$result = ( new YoRo_AI_API_Client() )->disconnect_mailbox();

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function get_mailbox_inbox( WP_REST_Request $request ) {
		$result = ( new YoRo_AI_API_Client() )->get_mailbox_inbox(
			[
				'limit'  => absint( $request->get_param( 'limit' ) ?: 25 ),
				'status' => sanitize_key( (string) $request->get_param( 'status' ) ),
				'unread' => rest_sanitize_boolean( $request->get_param( 'unread' ) ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function sync_mailbox_inbox( WP_REST_Request $request ) {
		$result = ( new YoRo_AI_API_Client() )->sync_mailbox_inbox(
			[
				'limit' => absint( $request->get_param( 'limit' ) ?: 10 ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function get_mailbox_thread( WP_REST_Request $request ) {
		$thread_id = absint( $request->get_param( 'thread_id' ) );
		$client    = new YoRo_AI_API_Client();
		$result    = $client->get_mailbox_thread(
			$thread_id,
			[
				'allow_ai' => false,
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		$store_context = self::mailbox_thread_store_context( $result );
		$enriched_payload = [
			'allow_ai' => true,
		];

		if ( ! empty( $store_context ) ) {
			$enriched_payload['store_context'] = $store_context;
		}

		$enriched = $client->get_mailbox_thread( $thread_id, $enriched_payload );

		if ( ! is_wp_error( $enriched ) ) {
			$result = $enriched;
		}

		return rest_ensure_response( $result );
	}

	public static function update_mailbox_thread( WP_REST_Request $request ) {
		$params = [
			'actor' => self::sanitize_mailbox_person( $request->get_param( 'actor' ) ),
		];

		if ( $request->has_param( 'status' ) ) {
			$params['status'] = sanitize_key( (string) $request->get_param( 'status' ) );
		}

		if ( $request->has_param( 'unread' ) ) {
			$params['unread'] = rest_sanitize_boolean( $request->get_param( 'unread' ) );
		}

		if ( $request->has_param( 'assignee' ) ) {
			$params['assignee'] = self::sanitize_mailbox_person( $request->get_param( 'assignee' ) );
		}

		if ( $request->has_param( 'follow_up_reminder' ) ) {
			$params['follow_up_reminder'] = self::sanitize_mailbox_follow_up_reminder( $request->get_param( 'follow_up_reminder' ) );
		}

		$result = ( new YoRo_AI_API_Client() )->update_mailbox_thread(
			absint( $request->get_param( 'thread_id' ) ),
			$params
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function archive_mailbox_thread( WP_REST_Request $request ) {
		return self::mailbox_thread_command( $request, 'archive' );
	}

	public static function reopen_mailbox_thread( WP_REST_Request $request ) {
		return self::mailbox_thread_command( $request, 'reopen' );
	}

	public static function mark_mailbox_thread_read( WP_REST_Request $request ) {
		return self::mailbox_thread_command( $request, 'read' );
	}

	public static function mark_mailbox_thread_unread( WP_REST_Request $request ) {
		return self::mailbox_thread_command( $request, 'unread' );
	}

	public static function add_mailbox_thread_note( WP_REST_Request $request ) {
		$result = ( new YoRo_AI_API_Client() )->add_mailbox_thread_note(
			absint( $request->get_param( 'thread_id' ) ),
			[
				'body'  => sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
				'actor' => self::sanitize_mailbox_person( $request->get_param( 'actor' ) ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function retry_mailbox_thread_reply( WP_REST_Request $request ) {
		$result = ( new YoRo_AI_API_Client() )->retry_mailbox_thread_reply(
			absint( $request->get_param( 'thread_id' ) ),
			absint( $request->get_param( 'message_id' ) ),
			[
				'actor' => self::sanitize_mailbox_person( $request->get_param( 'actor' ) ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	public static function reply_mailbox_thread( WP_REST_Request $request ) {
		$params = [
			'subject' => sanitize_text_field( (string) $request->get_param( 'subject' ) ),
			'body'    => sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
			'cc'      => self::sanitize_mailbox_address_list( $request->get_param( 'cc' ) ),
		];

		if ( class_exists( 'YoRo_REST_Execute_Handler' ) ) {
			$params = YoRo_REST_Execute_Handler::with_mailbox_reply_woocommerce_html( $params );
		}

		$result = ( new YoRo_AI_API_Client() )->reply_mailbox_thread(
			absint( $request->get_param( 'thread_id' ) ),
			$params
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	private static function mailbox_thread_command( WP_REST_Request $request, $command ) {
		$result = ( new YoRo_AI_API_Client() )->command_mailbox_thread(
			absint( $request->get_param( 'thread_id' ) ),
			sanitize_key( (string) $command ),
			[
				'actor' => self::sanitize_mailbox_person( $request->get_param( 'actor' ) ),
			]
		);

		if ( is_wp_error( $result ) ) {
			return self::mailbox_wp_error_response( $result );
		}

		return rest_ensure_response( $result );
	}

	private static function mailbox_return_url() {
		return add_query_arg(
			[
				'page'                => 'yoro-ai-core',
				'yoro_admin_panel'    => 'settings',
				'yoro_email_callback' => '1',
			],
			admin_url( 'admin.php' )
		);
	}

	private static function mailbox_wp_error_response( WP_Error $error ) {
		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			[
				'status' => 502,
			]
		);
	}

	private static function sanitize_mailbox_address_list( $items ) {
		if ( ! is_array( $items ) ) {
			return [];
		}

		$addresses = [];

		foreach ( array_values( $items ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$email = sanitize_email( $item['email'] ?? '' );

			if ( '' === $email ) {
				continue;
			}

			$addresses[] = [
				'email' => $email,
				'name'  => sanitize_text_field( $item['name'] ?? '' ),
			];
		}

		return $addresses;
	}

	private static function sanitize_mailbox_person( $person ) {
		if ( ! is_array( $person ) ) {
			return null;
		}

		$payload = [
			'id'    => sanitize_text_field( (string) ( $person['id'] ?? '' ) ),
			'name'  => sanitize_text_field( (string) ( $person['name'] ?? '' ) ),
			'email' => sanitize_email( (string) ( $person['email'] ?? '' ) ),
			'role'  => sanitize_key( (string) ( $person['role'] ?? '' ) ),
		];

		return '' !== implode( '', $payload ) ? $payload : null;
	}

	private static function sanitize_mailbox_follow_up_reminder( $reminder ) {
		if ( ! is_array( $reminder ) ) {
			return null;
		}

		$payload = [];

		if ( array_key_exists( 'status', $reminder ) ) {
			$payload['status'] = sanitize_key( (string) $reminder['status'] );
		}

		if ( array_key_exists( 'after_hours', $reminder ) ) {
			$payload['after_hours'] = absint( $reminder['after_hours'] );
		}

		if ( array_key_exists( 'due_at', $reminder ) ) {
			$payload['due_at'] = sanitize_text_field( (string) $reminder['due_at'] );
		}

		if ( array_key_exists( 'reason', $reminder ) ) {
			$payload['reason'] = sanitize_text_field( (string) $reminder['reason'] );
		}

		return $payload;
	}

	private static function mailbox_thread_store_context( $thread_payload ) {
		if ( ! is_array( $thread_payload ) ) {
			return [];
		}

		$customer         = self::mailbox_thread_customer_from_payload( $thread_payload );
		$email            = sanitize_email( $customer['email'] ?? '' );
		$order_candidates = self::mailbox_order_candidates_for_email( $email, 5 );
		$product_candidates = self::mailbox_product_candidates_for_thread( $thread_payload, 5 );
		$store_context    = [];

		if ( '' !== $email || ! empty( $order_candidates ) ) {
			$store_context['customer_context'] = array_filter(
				[
					'email'            => $email,
					'name'             => self::mailbox_compact_text( $customer['name'] ?? '', 120 ),
					'status'           => ! empty( $order_candidates ) ? 'matched_order_customer' : 'unknown',
					'order_candidates' => $order_candidates,
				],
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);
			$store_context['commerce_context'] = array_filter(
				[
					'customer'         => array_filter(
						[
							'email'  => $email,
							'name'   => self::mailbox_compact_text( $customer['name'] ?? '', 120 ),
							'status' => ! empty( $order_candidates ) ? 'matched_order_customer' : 'unknown',
						]
					),
					'order_candidates' => $order_candidates,
				],
				static function ( $value ) {
					return null !== $value && '' !== $value && [] !== $value;
				}
			);
		}

		if ( ! empty( $product_candidates ) ) {
			$store_context['product_candidates'] = $product_candidates;
		}

		return array_filter(
			$store_context,
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function mailbox_thread_customer_from_payload( $thread_payload ) {
		$mailbox_email = sanitize_email( $thread_payload['connection']['mailbox_email'] ?? '' );
		$messages      = is_array( $thread_payload['messages'] ?? null ) ? array_values( $thread_payload['messages'] ) : [];

		for ( $index = count( $messages ) - 1; $index >= 0; $index-- ) {
			$message   = is_array( $messages[ $index ] ?? null ) ? $messages[ $index ] : [];
			$direction = sanitize_key( $message['direction'] ?? '' );
			$email     = sanitize_email( $message['from_email'] ?? '' );

			if ( '' === $email || ( '' !== $mailbox_email && strtolower( $email ) === strtolower( $mailbox_email ) ) ) {
				continue;
			}

			if ( '' !== $direction && 'inbound' !== $direction ) {
				continue;
			}

			return [
				'email' => $email,
				'name'  => self::mailbox_compact_text( $message['from_name'] ?? '', 120 ),
			];
		}

		return [];
	}

	private static function mailbox_order_candidates_for_email( $email, $limit ) {
		$email = sanitize_email( $email );

		if ( '' === $email || ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = [
			'billing_email' => $email,
			'limit'         => max( 1, min( 10, absint( $limit ) ) ),
			'orderby'       => 'date',
			'order'         => 'DESC',
			'return'        => 'objects',
		];

		if ( function_exists( 'wc_get_order_statuses' ) ) {
			$args['status'] = array_keys( wc_get_order_statuses() );
		}

		$orders = wc_get_orders( $args );

		if ( ! is_array( $orders ) ) {
			return [];
		}

		return array_values(
			array_filter(
				array_map(
					[ __CLASS__, 'mailbox_compact_order_candidate' ],
					$orders
				)
			)
		);
	}

	private static function mailbox_compact_order_candidate( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return [];
		}

		$tracking = self::mailbox_order_tracking_context( $order );

		return array_filter(
			[
				'id'                 => absint( $order->get_id() ),
				'number'             => sanitize_text_field( $order->get_order_number() ),
				'status'             => sanitize_key( $order->get_status() ),
				'payment_status'     => $order->is_paid() ? 'paid' : 'unpaid',
				'fulfillment_status' => self::mailbox_order_fulfillment_status( $order, $tracking ),
				'shipping_status'    => self::mailbox_order_shipping_status( $order, $tracking ),
				'tracking_status'    => $tracking['status'] ?? '',
				'tracking_number'    => $tracking['number'] ?? '',
				'total'              => function_exists( 'wc_format_decimal' ) ? wc_format_decimal( $order->get_total(), 2 ) : sanitize_text_field( (string) $order->get_total() ),
				'currency'           => sanitize_text_field( $order->get_currency() ),
				'created_at'         => $order->get_date_created() ? $order->get_date_created()->date( DATE_ATOM ) : '',
				'customer_email'     => sanitize_email( $order->get_billing_email() ),
				'customer_name'      => self::mailbox_compact_text( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ), 120 ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function mailbox_order_tracking_context( WC_Order $order ) {
		foreach ( [ '_tracking_number', '_aftership_tracking_number', '_wcast_tracking_number', '_yith_tracking_code' ] as $key ) {
			$value = trim( (string) $order->get_meta( $key, true ) );

			if ( '' !== $value ) {
				return [
					'status' => 'available',
					'number' => sanitize_text_field( $value ),
				];
			}
		}

		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( is_array( $shipment_items ) && ! empty( $shipment_items ) ) {
			$first = is_array( $shipment_items[0] ?? null ) ? $shipment_items[0] : [];
			$value = trim( (string) ( $first['tracking_number'] ?? '' ) );

			if ( '' !== $value ) {
				return [
					'status' => 'available',
					'number' => sanitize_text_field( $value ),
				];
			}
		}

		return [
			'status' => 'not_available',
			'number' => '',
		];
	}

	private static function mailbox_order_shipping_status( WC_Order $order, array $tracking ) {
		if ( ! $order->get_items( 'shipping' ) ) {
			return 'not_required';
		}

		if ( 'available' === ( $tracking['status'] ?? '' ) ) {
			return 'tracking_available';
		}

		if ( in_array( $order->get_status(), [ 'completed' ], true ) ) {
			return 'completed_no_tracking';
		}

		return 'not_shipped';
	}

	private static function mailbox_order_fulfillment_status( WC_Order $order, array $tracking ) {
		if ( 'available' === ( $tracking['status'] ?? '' ) ) {
			return 'tracking_available';
		}

		if ( in_array( $order->get_status(), [ 'completed' ], true ) ) {
			return 'fulfilled';
		}

		if ( in_array( $order->get_status(), [ 'processing', 'on-hold' ], true ) ) {
			return 'open';
		}

		return sanitize_key( $order->get_status() );
	}

	private static function mailbox_product_candidates_for_thread( $thread_payload, $limit ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return [];
		}

		$terms      = self::mailbox_product_search_terms( $thread_payload );
		$products   = [];
		$seen       = [];
		$max_items  = max( 1, min( 10, absint( $limit ) ) );

		self::mailbox_add_product_candidates_by_terms( $terms, $products, $seen, $max_items );

		foreach ( $terms as $term ) {
			if ( count( $products ) >= $max_items ) {
				break;
			}

			$matches = wc_get_products(
				[
					'limit'   => $max_items * 2,
					'status'  => [ 'publish', 'private' ],
					'search'  => $term,
					'orderby' => 'date',
					'order'   => 'DESC',
					'return'  => 'objects',
				]
			);

			if ( ! is_array( $matches ) ) {
				continue;
			}

			foreach ( $matches as $product ) {
				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				if ( ! self::mailbox_product_matches_search_term( $product, $term ) ) {
					continue;
				}

				if ( self::mailbox_add_product_candidate( $products, $seen, $product, $max_items ) ) {
					break 2;
				}
			}
		}

		return array_values( array_filter( $products ) );
	}

	private static function mailbox_add_product_candidates_by_terms( array $terms, array &$products, array &$seen, $max_items ) {
		if ( empty( $terms ) || ! isset( $GLOBALS['wpdb'] ) ) {
			return;
		}

		$wpdb = $GLOBALS['wpdb'];

		foreach ( $terms as $term ) {
			if ( count( $products ) >= $max_items ) {
				break;
			}

			$term = self::mailbox_compact_text( $term, 120 );

			if ( '' === $term ) {
				continue;
			}

			$ids = [];

			if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
				$sku_id = absint( wc_get_product_id_by_sku( $term ) );

				if ( $sku_id > 0 ) {
					$ids[] = $sku_id;
				}
			}

			$like   = '%' . $wpdb->esc_like( $term ) . '%';
			$prefix = $wpdb->esc_like( $term ) . '%';
			$sql    = $wpdb->prepare(
				"SELECT DISTINCT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} sku
					ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				WHERE p.post_type IN ('product', 'product_variation')
					AND p.post_status IN ('publish', 'private')
					AND (p.post_title LIKE %s OR sku.meta_value LIKE %s)
				ORDER BY
					CASE
						WHEN p.post_title = %s THEN 0
						WHEN p.post_title LIKE %s THEN 1
						ELSE 2
					END,
					p.ID DESC
				LIMIT %d",
				$like,
				$like,
				$term,
				$prefix,
				max( 1, absint( $max_items ) * 3 )
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL -- $sql is prepared immediately above with all dynamic values passed as placeholders.
			$ids = array_merge( $ids, array_map( 'absint', (array) $wpdb->get_col( $sql ) ) );

			foreach ( array_values( array_unique( array_filter( $ids ) ) ) as $id ) {
				$product = wc_get_product( $id );

				if ( ! $product instanceof WC_Product ) {
					continue;
				}

				if ( ! self::mailbox_product_matches_search_term( $product, $term ) ) {
					continue;
				}

				if ( self::mailbox_add_product_candidate( $products, $seen, $product, $max_items ) ) {
					break 2;
				}
			}
		}
	}

	private static function mailbox_add_product_candidate( array &$products, array &$seen, WC_Product $product, $max_items ) {
		$id = absint( $product->get_id() );

		if ( $id <= 0 || isset( $seen[ $id ] ) ) {
			return count( $products ) >= $max_items;
		}

		$seen[ $id ] = true;
		$products[] = self::mailbox_compact_product_candidate( $product );

		return count( $products ) >= $max_items;
	}

	private static function mailbox_product_matches_search_term( WC_Product $product, $term ) {
		$term = self::mailbox_normalize_match_text( $term );

		if ( '' === $term ) {
			return false;
		}

		$haystack = self::mailbox_normalize_match_text(
			implode(
				' ',
				[
					$product->get_name(),
					$product->get_sku(),
				]
			)
		);

		if ( '' === $haystack ) {
			return false;
		}

		if ( false !== strpos( $haystack, $term ) ) {
			return true;
		}

		$words = array_values(
			array_filter(
				explode( ' ', $term ),
				static function ( $word ) {
					return mb_strlen( $word ) >= 3;
				}
			)
		);

		if ( count( $words ) <= 1 ) {
			return false;
		}

		foreach ( $words as $word ) {
			if ( false === strpos( $haystack, $word ) ) {
				return false;
			}
		}

		return true;
	}

	private static function mailbox_normalize_match_text( $value ) {
		$text = wp_strip_all_tags( (string) $value );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );

		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}

		$text = mb_strtolower( $text, 'UTF-8' );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );

		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	private static function mailbox_product_search_terms( $thread_payload ) {
		$text  = self::mailbox_thread_text( $thread_payload );
		$terms = [];

		if ( preg_match_all( '/[\'"“”‘’]([^\'"“”‘’]{3,80})[\'"“”‘’]/u', $text, $matches ) ) {
			foreach ( $matches[1] as $match ) {
				$terms[] = self::mailbox_compact_text( $match, 80 );
			}
		}

		$reference_candidates = $thread_payload['intelligence']['signals']['product_reference_candidates'] ?? [];

		if ( is_array( $reference_candidates ) ) {
			foreach ( $reference_candidates as $candidate ) {
				$terms[] = self::mailbox_compact_text( $candidate, 80 );
			}
		}

		$thread = is_array( $thread_payload['thread'] ?? null ) ? $thread_payload['thread'] : [];

		foreach ( [ $thread['subject'] ?? '', $thread['snippet'] ?? '' ] as $candidate ) {
			$candidate = preg_replace( '/\b(?:re|fw|fwd)\s*:\s*/i', '', (string) $candidate );
			$candidate = preg_replace( '/\b(?:inquiry|question|about|product|item|availability|available|price|stock)\b/i', ' ', (string) $candidate );
			$candidate = self::mailbox_compact_text( $candidate, 80 );

			if ( '' !== $candidate && mb_strlen( $candidate ) >= 4 ) {
				$terms[] = $candidate;
			}
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $term ) {
							$term = trim( preg_replace( '/\s+/u', ' ', (string) $term ) );

							return mb_strlen( $term ) >= 3 ? $term : '';
						},
						$terms
					)
				)
			)
		);
	}

	private static function mailbox_thread_text( $thread_payload ) {
		$parts  = [];
		$thread = is_array( $thread_payload['thread'] ?? null ) ? $thread_payload['thread'] : [];

		$parts[] = (string) ( $thread['subject'] ?? '' );
		$parts[] = (string) ( $thread['snippet'] ?? '' );

		foreach ( is_array( $thread_payload['messages'] ?? null ) ? $thread_payload['messages'] : [] as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$parts[] = (string) ( $message['subject'] ?? '' );
			$parts[] = (string) ( $message['snippet'] ?? '' );
			$parts[] = (string) ( $message['body_text'] ?? '' );
		}

		return self::mailbox_compact_text( implode( "\n", $parts ), 12000, true );
	}

	private static function mailbox_compact_product_candidate( WC_Product $product ) {
		$attributes = [];

		foreach ( $product->get_attributes() as $attribute ) {
			if ( count( $attributes ) >= 8 ) {
				break;
			}

			if ( is_object( $attribute ) && method_exists( $attribute, 'get_name' ) ) {
				$label = wc_attribute_label( $attribute->get_name(), $product );
				$value = method_exists( $attribute, 'get_options' ) ? implode( ', ', array_map( 'strval', (array) $attribute->get_options() ) ) : '';
				$attributes[] = self::mailbox_compact_text( trim( $label . ( '' !== $value ? ': ' . $value : '' ) ), 120 );
			}
		}

		return array_filter(
			[
				'id'             => absint( $product->get_id() ),
				'name'           => self::mailbox_compact_text( $product->get_name(), 180 ),
				'sku'            => self::mailbox_compact_text( $product->get_sku(), 120 ),
				'status'         => sanitize_key( $product->get_status() ),
				'stock_status'   => sanitize_key( $product->get_stock_status() ),
				'stock_quantity' => null !== $product->get_stock_quantity() ? (int) $product->get_stock_quantity() : null,
				'price'          => self::mailbox_compact_text( $product->get_price(), 80 ),
				'attributes'     => array_values( array_filter( $attributes ) ),
			],
			static function ( $value ) {
				return null !== $value && '' !== $value && [] !== $value;
			}
		);
	}

	private static function mailbox_compact_text( $value, $limit, $preserve_newlines = false ) {
		$text = wp_strip_all_tags( (string) $value );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
		$text = $preserve_newlines
			? preg_replace( "/[ \t\r]+/u", ' ', $text )
			: preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );
		$limit = max( 1, absint( $limit ) );

		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( mb_substr( $text, 0, max( 1, $limit - 1 ) ) ) . '…';
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
			'account_url'         => YoRo_AI_Settings::get_ai_api_account_url(),
			'billing_url'         => YoRo_AI_Settings::get_ai_api_billing_url(),
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
				'email_inbox'    => YoRo_AI_Provider_Resolver::supports( 'email_inbox' ),
			],
		];
	}

}
