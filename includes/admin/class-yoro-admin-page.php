<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Admin_Page {

	const MENU_SLUG = 'yoro-ai-core';
	const SUPPORT_DIAGNOSTICS_ACTION = 'yoro_download_support_diagnostics';
	const SUPPORT_DIAGNOSTICS_NONCE_ACTION = 'yoro_download_support_diagnostics';

	private static $admin_screen_ids = [];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_policy_save' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_ai_api_connect_callback' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_settings_save' ] );
		add_action( 'admin_post_' . self::SUPPORT_DIAGNOSTICS_ACTION, [ __CLASS__, 'handle_support_diagnostics_download' ] );
		add_action( 'admin_head', [ __CLASS__, 'print_menu_icon_styles' ] );
	}

	public static function add_menu_page() {
		self::$admin_screen_ids = [];

		self::$admin_screen_ids[] = add_menu_page(
			__( 'YoRo AI', 'yoro-ai-core' ),
			__( 'YoRo AI', 'yoro-ai-core' ),
			YoRo_Capabilities::USE_AI,
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			self::get_menu_icon(),
			2
		);
	}

	private static function get_menu_icon() {
		return 'none';
	}

	public static function print_menu_icon_styles() {
		$icon_uri = self::get_menu_icon_mask_uri();
		?>
		<style id="yoro-ai-admin-menu-icon">
			#adminmenu #toplevel_page_yoro-ai-core .wp-menu-image br {
				display: none;
			}

			#adminmenu #toplevel_page_yoro-ai-core .wp-menu-image::before {
				content: "";
				display: block;
				width: 20px;
				height: 20px;
				margin: 7px auto 0;
				padding: 0;
				background-color: currentColor;
				-webkit-mask-image: url("<?php echo esc_attr( $icon_uri ); ?>");
				mask-image: url("<?php echo esc_attr( $icon_uri ); ?>");
				-webkit-mask-repeat: no-repeat;
				mask-repeat: no-repeat;
				-webkit-mask-position: center;
				mask-position: center;
				-webkit-mask-size: 20px 20px;
				mask-size: 20px 20px;
			}
		</style>
		<?php
	}

	private static function get_menu_icon_mask_uri() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#000" fill-rule="evenodd" d="M12 1.55c5.77 0 10.45 4.68 10.45 10.45S17.77 22.45 12 22.45 1.55 17.77 1.55 12 6.23 1.55 12 1.55Zm0 1.65a8.8 8.8 0 1 0 0 17.6 8.8 8.8 0 0 0 0-17.6Z"/><path fill="#000" d="M5.85 20.65c-.35-4.28.45-8.14 2.32-11.12 2.1-3.36 5.46-5.56 10.34-5.76-3.85.87-6.57 3.2-7.5 6.02-.83 2.5-.04 4.45 1.8 5.25-2.5-.66-5.31.36-6.96 5.61Z"/><path fill="#000" d="M8.8 21.08c1.1-3.04 3.15-5.1 5.32-6.74 2.74-2.06 3.42-4.44 1.96-6.05 2.32.95 3.6 3.18 3.28 5.71-.4 3.2-3.35 5.97-10.56 7.08Z"/></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	private static function get_admin_screen_ids() {
		$fallbacks = [
			'toplevel_page_' . self::MENU_SLUG,
		];

		return array_values(
			array_unique(
				array_filter(
					array_merge( self::$admin_screen_ids, $fallbacks )
				)
			)
		);
	}

	private static function asset_version( $relative_path ) {
		$path = YORO_AI_CORE_PATH . ltrim( $relative_path, '/' );

		return is_readable( $path ) ? (string) filemtime( $path ) : YORO_AI_CORE_VERSION;
	}

	private static function get_woocommerce_email_from_address() {
		return sanitize_email( get_option( 'woocommerce_email_from_address', get_option( 'admin_email', '' ) ) );
	}

	private static function get_woocommerce_email_from_name() {
		return sanitize_text_field( get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) ) );
	}

	private static function get_mailbox_assignee_options() {
		$users = get_users(
			[
				'role__in' => [ 'administrator', 'shop_manager' ],
				'number'   => 50,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
			]
		);

		return array_values(
			array_map(
				static function ( $user ) {
					return [
						'id'    => (string) absint( $user->ID ),
						'name'  => sanitize_text_field( $user->display_name ),
						'email' => sanitize_email( $user->user_email ),
						'role'  => sanitize_key( (string) ( $user->roles[0] ?? 'staff' ) ),
					];
				},
				is_array( $users ) ? $users : []
			)
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::get_admin_screen_ids(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'yoro-ai-admin',
			YORO_AI_CORE_URL . 'assets/css/yoro-ai-admin.css',
			[],
			self::asset_version( 'assets/css/yoro-ai-admin.css' )
		);

		wp_enqueue_script(
			'yoro-ai-display-labels',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-display-labels.js',
			[],
			self::asset_version( 'assets/js/yoro-ai-display-labels.js' ),
			true
		);

		wp_enqueue_script(
			'yoro-ai-workflow-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-workflow-renderers.js',
			[ 'yoro-ai-display-labels' ],
			self::asset_version( 'assets/js/yoro-ai-workflow-renderers.js' ),
			true
		);

		wp_enqueue_script(
			'yoro-ai-email-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-email-ui.js',
			[ 'yoro-ai-display-labels' ],
			self::asset_version( 'assets/js/yoro-ai-email-ui.js' ),
			true
		);

		wp_enqueue_script(
			'yoro-ai-order-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-order-ui.js',
			[ 'yoro-ai-display-labels' ],
			self::asset_version( 'assets/js/yoro-ai-order-ui.js' ),
			true
		);

		wp_enqueue_script(
			'yoro-ai-result-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-result-renderers.js',
			[ 'yoro-ai-display-labels' ],
			self::asset_version( 'assets/js/yoro-ai-result-renderers.js' ),
			true
		);

		wp_enqueue_script(
			'yoro-ai-admin',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-admin.js',
			[
				'wp-api-fetch',
				'yoro-ai-workflow-renderers',
				'yoro-ai-email-ui',
				'yoro-ai-order-ui',
				'yoro-ai-result-renderers',
			],
			self::asset_version( 'assets/js/yoro-ai-admin.js' ),
			true
		);

		$order_statuses = array_map(
			function( $status ) {
				return [
					'slug'  => isset( $status['slug'] ) ? sanitize_key( $status['slug'] ) : '',
					'label' => isset( $status['label'] ) ? sanitize_text_field( $status['label'] ) : '',
				];
			},
			array_values( YoRo_Order_Helper::get_order_status_options() )
		);

		$conversation_storage_key = 'yoro_ai_conversation_id_' . md5(
			untrailingslashit( home_url() ) . '|' . get_current_user_id()
		);
		$current_user             = wp_get_current_user();
		$current_user_payload     = $current_user instanceof WP_User && $current_user->ID
			? [
				'id'    => (string) absint( $current_user->ID ),
				'name'  => sanitize_text_field( $current_user->display_name ),
				'email' => sanitize_email( $current_user->user_email ),
				'role'  => sanitize_key( (string) ( $current_user->roles[0] ?? 'admin' ) ),
			]
			: [];

		wp_localize_script(
			'yoro-ai-admin',
			'YoRoAIAdmin',
			[
				'debug'                                 => false,
				'conversationUrl'                       => esc_url_raw( rest_url( 'yoro-ai-core/v1/chat/conversation' ) ),
				'chatUrl'                               => esc_url_raw( rest_url( 'yoro-ai-core/v1/chat/message' ) ),
				'executeUrl'                            => esc_url_raw( rest_url( 'yoro-ai-core/v1/execute' ) ),
				'cancelActionUrl'                       => esc_url_raw( rest_url( 'yoro-ai-core/v1/actions/cancel' ) ),
				'contextUrl'                            => esc_url_raw( rest_url( 'yoro-ai-core/v1/context' ) ),
				'actionsUrl'                            => esc_url_raw( rest_url( 'yoro-ai-core/v1/actions' ) ),
				'undoUrl'                               => esc_url_raw( rest_url( 'yoro-ai-core/v1/undo' ) ),
				'aiApiUrl' => esc_url_raw( YoRo_AI_Settings::get_ai_api_url() ),
				'aiApiStatusUrl'                        => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/status' ) ),
				'aiApiHandshakeUrl'                     => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/handshake' ) ),
				'emailInboxUrl'                         => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/inbox' ) ),
				'emailInboxSyncUrl'                     => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/inbox/sync' ) ),
				'emailThreadBaseUrl'                    => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/threads' ) ),
				'emailGmailConnectIntentUrl'            => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/gmail/connect-intents' ) ),
				'emailGmailDisconnectUrl'               => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/gmail/disconnect' ) ),
				'emailMicrosoftConnectIntentUrl'        => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/microsoft/connect-intents' ) ),
				'emailImapConnectUrl'                   => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/imap/connect' ) ),
				'emailMailboxDisconnectUrl'             => esc_url_raw( rest_url( 'yoro-ai-core/v1/email/disconnect' ) ),
				'woocommerceEmailFromAddress'           => self::get_woocommerce_email_from_address(),
				'woocommerceEmailFromName'              => self::get_woocommerce_email_from_name(),
				'mailboxAssignees'                      => self::get_mailbox_assignee_options(),
				'aiApiAccountUrl'                       => esc_url_raw( YoRo_AI_Settings::get_ai_api_account_url() ),
				'aiApiBillingUrl'                       => esc_url_raw( YoRo_AI_Settings::get_ai_api_billing_url() ),
				'aiApiDashboardUrl'                     => esc_url_raw( YoRo_AI_Settings::get_ai_api_dashboard_url() ),
					'aiApiConversationDashboardBaseUrl'     => esc_url_raw( YoRo_AI_Settings::get_ai_api_conversation_dashboard_base_url() ),
					'accountConnection'                     => YoRo_AI_Settings::get_ai_api_account_connection(),
					'canManageAccountConnection'            => current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ),
					'currentUserCapabilities'               => [
						'use_ai'            => current_user_can( YoRo_Capabilities::USE_AI ),
						'view_store_data'   => current_user_can( YoRo_Capabilities::VIEW_STORE_DATA ),
						'execute_actions'   => current_user_can( YoRo_Capabilities::EXECUTE_ACTIONS ),
						'manage_mailbox'    => current_user_can( YoRo_Capabilities::MANAGE_MAILBOX ),
						'view_audit'        => current_user_can( YoRo_Capabilities::VIEW_AUDIT ),
						'manage_connection' => current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ),
						'manage_access'     => current_user_can( YoRo_Capabilities::MANAGE_ACCESS ),
						'manage_policies'   => current_user_can( YoRo_Capabilities::MANAGE_POLICIES ),
					],
					'conversationStorageKey'                => $conversation_storage_key,
				'nonce'                                 => wp_create_nonce( 'wp_rest' ),
				'orderStatuses'                         => $order_statuses,
				'currentUser'                           => $current_user_payload,
				'dateFormat'                            => get_option( 'date_format', 'Y-m-d' ),
				'timeFormat'                            => get_option( 'time_format', 'H:i' ),
				'i18n'                                  => [
					'emptyCommand'         => __( 'Please enter a request.', 'yoro-ai-core' ),
					'running'              => __( 'Thinking...', 'yoro-ai-core' ),
					'askYoRo'              => __( 'Send', 'yoro-ai-core' ),
					'error'                => __( 'I could not finish that request.', 'yoro-ai-core' ),
				],
			]
		);
	}

	public static function render_page() {
		$current_user = wp_get_current_user();
		$user_name    = $current_user instanceof WP_User ? sanitize_text_field( $current_user->display_name ) : '';
		$intro_text   = sprintf(
			/* translators: %s: current user display name. */
			__( 'Hi %s, what should YoRo handle today? I’ll ask before making store changes.', 'yoro-ai-core' ),
			'' !== $user_name ? $user_name : __( 'there', 'yoro-ai-core' )
		);
		$account_connection = YoRo_AI_Settings::get_ai_api_account_connection();
		$account_connected  = ! empty( $account_connection['connected'] );
		?>
		<div class="yoro-chat-shell">
			<?php if ( current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) : ?>
				<div class="yoro-ai-api-status" data-yoro-ai-api-status>
					<button type="button" class="yoro-ai-api-status-toggle is-loading" data-yoro-ai-api-status-toggle aria-expanded="false" aria-controls="yoro-ai-api-status-panel">
						<span class="yoro-ai-api-status-dot" aria-hidden="true"></span>
						<span data-yoro-ai-api-status-label><?php esc_html_e( 'YoRo AI', 'yoro-ai-core' ); ?></span>
					</button>

					<div class="yoro-ai-api-status-panel" id="yoro-ai-api-status-panel" data-yoro-ai-api-status-panel hidden>
						<div class="yoro-ai-api-status-panel-head">
							<strong><?php esc_html_e( 'YoRo AI', 'yoro-ai-core' ); ?></strong>
							<button type="button" class="button button-small" data-yoro-ai-api-status-refresh><?php esc_html_e( 'Refresh', 'yoro-ai-core' ); ?></button>
						</div>
						<div class="yoro-ai-api-status-panel-body" data-yoro-ai-api-status-body>
							<p><?php esc_html_e( 'Checking connection...', 'yoro-ai-core' ); ?></p>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<section class="yoro-account-gate<?php echo $account_connected ? ' is-hidden' : ''; ?>" data-yoro-account-gate aria-live="polite" <?php echo $account_connected ? 'hidden' : ''; ?>>
				<div class="yoro-account-gate-icon" aria-hidden="true">
					<span class="dashicons dashicons-lock"></span>
				</div>
				<div class="yoro-account-gate-copy">
					<span class="yoro-account-gate-eyebrow" data-yoro-account-gate-eyebrow><?php esc_html_e( 'Account connection required', 'yoro-ai-core' ); ?></span>
					<h2 data-yoro-account-gate-title><?php esc_html_e( 'Connect YoRo before opening the AI workspace', 'yoro-ai-core' ); ?></h2>
					<p data-yoro-account-gate-description><?php esc_html_e( 'Link this store to your YoRo account and Organization. This securely assigns the store, plan, usage, and team access before YoRo can read or act on store data.', 'yoro-ai-core' ); ?></p>
					<div class="yoro-account-gate-meta">
						<span><span class="dashicons dashicons-shield-alt" aria-hidden="true"></span><?php esc_html_e( 'Secure site credential', 'yoro-ai-core' ); ?></span>
						<span><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e( 'Organization ownership', 'yoro-ai-core' ); ?></span>
						<span><span class="dashicons dashicons-chart-area" aria-hidden="true"></span><?php esc_html_e( 'Plan and usage sync', 'yoro-ai-core' ); ?></span>
					</div>
				</div>
				<div class="yoro-account-gate-action" data-yoro-account-gate-action>
					<?php if ( current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) && ! YoRo_AI_Settings::is_ai_api_site_key_locked() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
							<input type="hidden" name="yoro_admin_panel" value="settings" />
							<?php wp_nonce_field( 'yoro_ai_save_settings', 'yoro_ai_settings_nonce' ); ?>
							<button type="submit" class="button button-primary yoro-account-gate-button" name="yoro_ai_api_connect" value="1">
								<span><?php esc_html_e( 'Connect YoRo Account', 'yoro-ai-core' ); ?></span>
								<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
							</button>
						</form>
						<button type="button" class="button yoro-account-gate-settings" id="yoro-account-gate-settings"><?php esc_html_e( 'Connection settings', 'yoro-ai-core' ); ?></button>
					<?php elseif ( current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) : ?>
						<p><?php esc_html_e( 'The site credential is locked by server configuration. Update the configured credential or remove the constant before connecting.', 'yoro-ai-core' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Ask a WordPress administrator to connect this store to a YoRo account.', 'yoro-ai-core' ); ?></p>
					<?php endif; ?>
				</div>
			</section>

			<div id="yoro-chat-thread" class="yoro-chat-thread">
				<div class="yoro-chat-message yoro-chat-message-assistant" data-yoro-initial-greeting="1">
					<div class="yoro-chat-avatar" aria-hidden="true"></div>
					<div class="yoro-chat-bubble">
						<p><?php echo esc_html( $intro_text ); ?></p>
					</div>
				</div>
			</div>

			<div class="yoro-chat-composer">
				<textarea
					id="yoro-ai-command"
					class="yoro-chat-input"
					rows="2"
					placeholder="<?php esc_attr_e( 'Ask naturally, e.g. Show latest 5 pending orders, draft an email for order #7199, or add a note to an order', 'yoro-ai-core' ); ?>"
				></textarea>

				<div class="yoro-chat-actions">
					<button type="button" class="button button-primary" id="yoro-ai-run">
						<span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Send', 'yoro-ai-core' ); ?></span>
					</button>

					<button type="button" class="button" id="yoro-ai-clear">
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<span><?php esc_html_e( 'New Conversation', 'yoro-ai-core' ); ?></span>
					</button>

					<?php if ( current_user_can( YoRo_Capabilities::VIEW_STORE_DATA ) ) : ?>
					<button type="button" class="button" id="yoro-ai-load-context">
						<span class="dashicons dashicons-database" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Context', 'yoro-ai-core' ); ?></span>
					</button>
					<?php endif; ?>

					<span class="yoro-chat-actions-spacer" aria-hidden="true"></span>

					<?php if ( current_user_can( YoRo_Capabilities::VIEW_AUDIT ) || current_user_can( YoRo_Capabilities::MANAGE_POLICIES ) || current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) : ?>
					<button type="button" class="button yoro-chat-admin-button" id="yoro-ai-open-admin-panels">
						<span class="dashicons dashicons-admin-generic" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Admin', 'yoro-ai-core' ); ?></span>
					</button>
					<?php endif; ?>
				</div>

				<?php if ( current_user_can( YoRo_Capabilities::VIEW_STORE_DATA ) ) : ?>
				<div class="yoro-chat-workflows" aria-label="<?php esc_attr_e( 'Store operations workflows', 'yoro-ai-core' ); ?>">
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="store.get_daily_briefing" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'today', 'limit' => 1 ] ) ); ?>">
						<span class="dashicons dashicons-clipboard" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Briefing', 'yoro-ai-core' ); ?></span>
					</button>
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="operations.get_attention_queue" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>">
						<span class="dashicons dashicons-warning" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Attention', 'yoro-ai-core' ); ?></span>
					</button>
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="operations.get_fulfillment_queue" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>">
						<span class="dashicons dashicons-archive" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Fulfillment', 'yoro-ai-core' ); ?></span>
					</button>
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="customers.get_service_queue" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>">
						<span class="dashicons dashicons-groups" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Customers', 'yoro-ai-core' ); ?></span>
					</button>
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="products.get_inventory_queue" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8, 'focus' => 'attention' ] ) ); ?>">
						<span class="dashicons dashicons-products" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Inventory', 'yoro-ai-core' ); ?></span>
					</button>
					<button type="button" class="yoro-chat-workflow-chip" data-yoro-workflow-action="store.get_business_summary" data-yoro-workflow-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'this_month', 'focus' => 'overview' ] ) ); ?>">
						<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
						<span><?php esc_html_e( 'Reports', 'yoro-ai-core' ); ?></span>
					</button>
				</div>
				<?php endif; ?>
			</div>
		</div>
		<?php self::render_admin_panel_modal_source(); ?>
		<?php
	}

	private static function render_admin_panel_modal_source() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin panel selection for initial modal state.
		$initial_panel = isset( $_REQUEST['yoro_admin_panel'] )
			? sanitize_key( wp_unslash( $_REQUEST['yoro_admin_panel'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$can_view_history      = current_user_can( YoRo_Capabilities::VIEW_AUDIT );
		$can_manage_policies   = current_user_can( YoRo_Capabilities::MANAGE_POLICIES );
		$can_manage_connection = current_user_can( YoRo_Capabilities::MANAGE_CONNECTION );
		$available_panels      = array_keys(
			array_filter(
				[
					'history'  => $can_view_history,
					'safety'   => $can_manage_policies,
					'settings' => $can_manage_connection,
				]
			)
		);

		if ( empty( $available_panels ) ) {
			return;
		}

		if ( ! in_array( $initial_panel, $available_panels, true ) ) {
			$initial_panel = '';
		}

		$active_panel = '' !== $initial_panel ? $initial_panel : $available_panels[0];
		$history_active = 'history' === $active_panel;
		$safety_active = 'safety' === $active_panel;
		$settings_active = 'settings' === $active_panel;
		?>
		<div class="yoro-admin-panel-source" data-yoro-admin-panels data-yoro-admin-initial-panel="<?php echo esc_attr( $initial_panel ); ?>" hidden>
			<div class="yoro-admin-panel-modal" data-yoro-admin-modal>
				<div class="yoro-admin-panel-tabs" role="tablist" aria-label="<?php esc_attr_e( 'YoRo AI admin panels', 'yoro-ai-core' ); ?>">
					<?php if ( $can_view_history ) : ?>
					<button
						type="button"
						class="button yoro-admin-panel-tab<?php echo $history_active ? ' is-active' : ''; ?>"
						data-yoro-admin-tab="history"
						role="tab"
						aria-selected="<?php echo $history_active ? 'true' : 'false'; ?>"
					>
						<?php esc_html_e( 'History', 'yoro-ai-core' ); ?>
					</button>
					<?php endif; ?>

					<?php if ( $can_manage_policies ) : ?>
						<button
							type="button"
							class="button yoro-admin-panel-tab<?php echo $safety_active ? ' is-active' : ''; ?>"
							data-yoro-admin-tab="safety"
							role="tab"
							aria-selected="<?php echo $safety_active ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'Safety', 'yoro-ai-core' ); ?>
						</button>
					<?php endif; ?>

					<?php if ( $can_manage_connection ) : ?>
						<button
							type="button"
							class="button yoro-admin-panel-tab<?php echo $settings_active ? ' is-active' : ''; ?>"
							data-yoro-admin-tab="settings"
							role="tab"
							aria-selected="<?php echo $settings_active ? 'true' : 'false'; ?>"
						>
							<?php esc_html_e( 'Settings', 'yoro-ai-core' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<?php if ( $can_view_history ) : ?>
				<div
					class="yoro-admin-panel-section<?php echo $history_active ? ' is-active' : ''; ?>"
					data-yoro-admin-panel="history"
					role="tabpanel"
				>
					<?php self::render_history_page(); ?>
				</div>
				<?php endif; ?>

				<?php if ( $can_manage_policies ) : ?>
					<div
						class="yoro-admin-panel-section<?php echo $safety_active ? ' is-active' : ''; ?>"
						data-yoro-admin-panel="safety"
						role="tabpanel"
					>
						<?php self::render_policies_page(); ?>
					</div>
				<?php endif; ?>

				<?php if ( $can_manage_connection ) : ?>
					<div
						class="yoro-admin-panel-section<?php echo $settings_active ? ' is-active' : ''; ?>"
						data-yoro-admin-panel="settings"
						role="tabpanel"
					>
						<?php self::render_settings_page(); ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public static function render_history_page() {

		require_once YORO_AI_CORE_PATH . 'includes/admin/class-yoro-history-table.php';

		$dashboard_url = YoRo_AI_Settings::get_ai_api_dashboard_url();
		$table = new YoRo_History_Table();
		$table->prepare_items();
			?>

			<div class="wrap yoro-ai-wrap">
				<h1><?php esc_html_e( 'YoRo AI Action History', 'yoro-ai-core' ); ?></h1>
				<p class="description">
					<?php esc_html_e( 'A simple timeline of store action events. Chat transcripts and debug traces live in the YoRo dashboard.', 'yoro-ai-core' ); ?>
					<?php if ( '' !== $dashboard_url ) : ?>
						<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open YoRo dashboard', 'yoro-ai-core' ); ?></a>
					<?php endif; ?>
				</p>

				<?php if ( current_user_can( YoRo_Capabilities::VIEW_AUDIT ) ) : ?>
					<p>
						<a
							class="button"
							href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::SUPPORT_DIAGNOSTICS_ACTION ), self::SUPPORT_DIAGNOSTICS_NONCE_ACTION ) ); ?>"
						>
							<?php esc_html_e( 'Download support diagnostics', 'yoro-ai-core' ); ?>
						</a>
					</p>
				<?php endif; ?>

				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="yoro_admin_panel" value="history" />

					<?php
				$table->search_box( __( 'Search actions', 'yoro-ai-core' ), 'yoro-search' );
				$table->display();
				?>
			</form>
		</div>

		<?php
	}

	public static function validate_support_diagnostics_download( $nonce ) {
		if ( ! current_user_can( YoRo_Capabilities::VIEW_AUDIT ) ) {
			return new WP_Error(
				'yoro_support_diagnostics_forbidden',
				__( 'You do not have permission to download YoRo support diagnostics.', 'yoro-ai-core' ),
				[ 'status' => 403 ]
			);
		}

		if ( ! wp_verify_nonce( sanitize_text_field( (string) $nonce ), self::SUPPORT_DIAGNOSTICS_NONCE_ACTION ) ) {
			return new WP_Error(
				'yoro_support_diagnostics_invalid_nonce',
				__( 'The support diagnostics download link is invalid or expired.', 'yoro-ai-core' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public static function build_support_diagnostics_payload() {
		$diagnostics = YoRo_Logger::get_support_diagnostics();
		$trace_ids   = [];

		foreach ( $diagnostics as $diagnostic ) {
			$trace_id = sanitize_text_field( (string) ( $diagnostic['trace_id'] ?? '' ) );

			if ( '' !== $trace_id ) {
				$trace_ids[ $trace_id ] = true;
			}
		}

		return [
			'generated_at' => gmdate( 'c' ),
			'correlation'  => [
				'join_key'     => 'trace_id',
				'semantics'    => 'diagnostic_only',
				'authoritative' => false,
				'trace_ids'    => array_slice( array_keys( $trace_ids ), 0, YoRo_Logger::SUPPORT_DIAGNOSTIC_LIMIT ),
			],
			'diagnostics'  => $diagnostics,
		];
	}

	public static function handle_support_diagnostics_download() {
		$nonce = isset( $_GET['_wpnonce'] )
			? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) )
			: '';
		$validation = self::validate_support_diagnostics_download( $nonce );

		if ( is_wp_error( $validation ) ) {
			$status = absint( $validation->get_error_data()['status'] ?? 403 );

			wp_die(
				esc_html( $validation->get_error_message() ),
				esc_html__( 'Support diagnostics unavailable', 'yoro-ai-core' ),
				[ 'response' => $status ]
			);
		}

		$payload = self::build_support_diagnostics_payload();
		$filename = 'yoro-support-diagnostics-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		exit;
	}

	public static function render_policies_page() {
		global $wpdb;

		$table = YoRo_DB::policies_table_name();

		$policy_mode      = YoRo_Safety_Contract::profile();
		$policy_version   = YoRo_Safety_Contract::policy_version();
		$server_managed   = YoRo_Safety_Contract::is_server_authoritative();
		$management_url   = YoRo_Safety_Contract::management_url();
		$advanced_enabled = 'no';

		$allowed_modes = [ 'advisory', 'assisted', 'delegated' ];

		if ( ! in_array( $policy_mode, $allowed_modes, true ) ) {
			$policy_mode = 'assisted';
		}

		$show_advanced = (
			'custom' === $policy_mode &&
			'yes' === $advanced_enabled
		);

		$rows = [];

		if ( $show_advanced ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,PluginCheck.Security.DirectDB -- Reads plugin-owned custom policy rows for the advanced settings table.
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY action ASC" );
		}

		?>
		<div class="wrap yoro-ai-wrap">
			<h1><?php esc_html_e( 'YoRo Safety', 'yoro-ai-core' ); ?></h1>

			<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice flag. ?>
			<?php if ( isset( $_GET['policy_saved'] ) ) : ?>
				<?php if ( '1' === sanitize_text_field( wp_unslash( $_GET['policy_saved'] ) ) ) : ?>
					<div class="notice notice-success is-dismissible" data-yoro-admin-notice-panel="safety">
						<p><?php esc_html_e( 'Safety profile saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php elseif ( 'centralized' === sanitize_key( wp_unslash( $_GET['policy_saved'] ) ) ) : ?>
					<div class="notice notice-info is-dismissible" data-yoro-admin-notice-panel="safety">
						<p><?php esc_html_e( 'This connected site uses the versioned Safety policy from the YoRo account.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible" data-yoro-admin-notice-panel="safety">
						<p><?php esc_html_e( 'Safety profile could not be saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>
			<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Safety Profile', 'yoro-ai-core' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Choose the boundary between advice, reviewed execution, and explicitly delegated automation. Assisted is recommended for most stores.', 'yoro-ai-core' ); ?>
				</p>

				<?php if ( $server_managed ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: Safety profile name, 2: policy version. */
									__( 'YoRo AI is the policy authority for this connected site. Active profile: %1$s, version %2$d.', 'yoro-ai-core' ),
									ucfirst( $policy_mode ),
									$policy_version
								)
							);
							?>
						</p>
						<?php if ( '' !== $management_url ) : ?>
							<p><a class="button button-primary" href="<?php echo esc_url( $management_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage versioned Safety policy', 'yoro-ai-core' ); ?></a></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'yoro_save_policy_mode', 'yoro_policy_mode_nonce' ); ?>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="yoro_admin_panel" value="safety" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Profile', 'yoro-ai-core' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="yoro_safety_profile" value="advisory" <?php checked( $policy_mode, 'advisory' ); ?> />
										<strong><?php esc_html_e( 'Advisory', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'YoRo may inspect and draft, but side effects are blocked.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_safety_profile" value="assisted" <?php checked( $policy_mode, 'assisted' ); ?> />
										<strong><?php esc_html_e( 'Assisted — Recommended', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'YoRo proposes actions and requires approval of the exact preview before AI-originated changes.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_safety_profile" value="delegated" <?php checked( $policy_mode, 'delegated' ); ?> />
										<strong><?php esc_html_e( 'Delegated', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Automation may skip confirmation only when a versioned YoRo AI policy rule explicitly matches.', 'yoro-ai-core' ); ?>
									</p>

								</fieldset>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save safety profile', 'yoro-ai-core' ) ); ?>
				</form>
				<?php endif; ?>
			</div>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Current Safety Profile', 'yoro-ai-core' ); ?></h2>

				<?php if ( 'advisory' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo can advise and draft, but cannot change store or mailbox data.', 'yoro-ai-core' ); ?></p>
				<?php elseif ( 'delegated' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo AI uses explicit, versioned rules for delegated automation and falls back to review when no rule matches.', 'yoro-ai-core' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'YoRo will require review of the exact preview before AI-originated store changes.', 'yoro-ai-core' ); ?></p>
				<?php endif; ?>
			</div>

				<?php if ( false ) : ?>
				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Advanced Policy Rules', 'yoro-ai-core' ); ?></h2>

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Action', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Role', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Allow', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Max Items', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'AI', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Direct', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Write', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Preview', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Confirm', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Risk Levels', 'yoro-ai-core' ); ?></th>
								<th><?php esc_html_e( 'Save', 'yoro-ai-core' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr>
									<td colspan="11">
										<?php esc_html_e( 'No policies found.', 'yoro-ai-core' ); ?>
									</td>
								</tr>
							<?php else : ?>
								<?php foreach ( $rows as $row ) : ?>
									<?php
									$policy_id = absint( $row->id );
									$form_id   = 'yoro-policy-form-' . $policy_id;
									?>

									<tr>
										<td><?php echo esc_html( $row->action ); ?></td>
										<td><?php echo esc_html( $row->role ); ?></td>

										<td>
											<select name="allow" form="<?php echo esc_attr( $form_id ); ?>">
												<option value="1" <?php selected( (int) $row->allow, 1 ); ?>>
													<?php esc_html_e( 'Allow', 'yoro-ai-core' ); ?>
												</option>
												<option value="0" <?php selected( (int) $row->allow, 0 ); ?>>
													<?php esc_html_e( 'Block', 'yoro-ai-core' ); ?>
												</option>
											</select>
										</td>

										<td>
											<input
												type="number"
												min="0"
												name="max_items"
												form="<?php echo esc_attr( $form_id ); ?>"
												value="<?php echo esc_attr( $row->max_items ); ?>"
											/>
											<p class="description">
												<?php esc_html_e( 'Use 0 for no item limit.', 'yoro-ai-core' ); ?>
											</p>
										</td>

										<td>
											<label>
												<input
													type="checkbox"
													name="allow_ai"
													value="1"
													form="<?php echo esc_attr( $form_id ); ?>"
													<?php checked( isset( $row->allow_ai ) ? (int) $row->allow_ai : 1, 1 ); ?>
												/>
											</label>
										</td>

										<td>
											<label>
												<input
													type="checkbox"
													name="allow_direct"
													value="1"
													form="<?php echo esc_attr( $form_id ); ?>"
													<?php checked( isset( $row->allow_direct ) ? (int) $row->allow_direct : 1, 1 ); ?>
												/>
											</label>
										</td>

										<td>
											<label>
												<input
													type="checkbox"
													name="allow_write"
													value="1"
													form="<?php echo esc_attr( $form_id ); ?>"
													<?php checked( isset( $row->allow_write ) ? (int) $row->allow_write : 1, 1 ); ?>
												/>
											</label>
										</td>

										<td>
											<label>
												<input
													type="checkbox"
													name="requires_preview"
													value="1"
													form="<?php echo esc_attr( $form_id ); ?>"
													<?php checked( isset( $row->requires_preview ) ? (int) $row->requires_preview : 0, 1 ); ?>
												/>
											</label>
										</td>

										<td>
											<label>
												<input
													type="checkbox"
													name="requires_confirmation"
													value="1"
													form="<?php echo esc_attr( $form_id ); ?>"
													<?php checked( isset( $row->requires_confirmation ) ? (int) $row->requires_confirmation : 0, 1 ); ?>
												/>
											</label>
										</td>

										<td>
											<?php
											$risk_levels = [];

											if ( ! empty( $row->allowed_risk_levels ) ) {
												$decoded = json_decode( $row->allowed_risk_levels, true );

												if ( is_array( $decoded ) ) {
													$risk_levels = array_map( 'sanitize_key', $decoded );
												}
											}

											if ( empty( $risk_levels ) ) {
												$risk_levels = [ 'low', 'medium', 'high' ];
											}
											?>

											<label>
												<input type="checkbox" name="allowed_risk_levels[]" value="low" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( in_array( 'low', $risk_levels, true ) ); ?> />
												<?php esc_html_e( 'Low', 'yoro-ai-core' ); ?>
											</label><br>

											<label>
												<input type="checkbox" name="allowed_risk_levels[]" value="medium" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( in_array( 'medium', $risk_levels, true ) ); ?> />
												<?php esc_html_e( 'Medium', 'yoro-ai-core' ); ?>
											</label><br>

											<label>
												<input type="checkbox" name="allowed_risk_levels[]" value="high" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( in_array( 'high', $risk_levels, true ) ); ?> />
												<?php esc_html_e( 'High', 'yoro-ai-core' ); ?>
											</label>
										</td>

										<td>
											<form id="<?php echo esc_attr( $form_id ); ?>" method="post">
												<input type="hidden" name="policy_id" value="<?php echo esc_attr( $policy_id ); ?>" />
												<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
												<input type="hidden" name="yoro_admin_panel" value="safety" />
												<?php wp_nonce_field( 'yoro_save_policy_' . $policy_id, 'yoro_policy_nonce' ); ?>
												<?php submit_button( __( 'Save', 'yoro-ai-core' ), 'primary small', '', false ); ?>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_settings_page() {
		$settings = YoRo_AI_Settings::get_all();
		$rate_limit_count = absint( $settings['rate_limit_count'] ?? 10 );
		$rate_limit_window = absint( $settings['rate_limit_window'] ?? 60 );
		$undo_ttl = absint( $settings['undo_ttl'] ?? 60 );
		$max_result_limit = absint( $settings['max_result_limit'] ?? 20 );
		$ai_api_url = $settings['ai_api_url'] ?? '';
		$ai_api_url_locked = ! empty( $settings['ai_api_url_locked'] );
		$ai_api_url_constant = $settings['ai_api_url_constant'] ?? '';
		$ai_api_site_key_source = $settings['ai_api_site_key_source'] ?? 'missing';
		$ai_api_site_key_constant = $settings['ai_api_site_key_constant'] ?? '';
		$ai_api_enabled = $settings['ai_api_enabled'] ?? 'no';
		$ai_api_status = isset( $settings['ai_api_status'] ) && is_array( $settings['ai_api_status'] ) ? $settings['ai_api_status'] : [];
		$ai_api_account_connection = isset( $settings['ai_api_account_connection'] ) && is_array( $settings['ai_api_account_connection'] ) ? $settings['ai_api_account_connection'] : YoRo_AI_Settings::get_ai_api_account_connection();
		$ai_api_account_url = $settings['ai_api_account_url'] ?? YoRo_AI_Settings::get_ai_api_account_url();
		$ai_api_billing_url = $settings['ai_api_billing_url'] ?? YoRo_AI_Settings::get_ai_api_billing_url();
		$ai_api_site = isset( $ai_api_status['site'] ) && is_array( $ai_api_status['site'] ) ? $ai_api_status['site'] : [];
		$ai_api_organization = isset( $ai_api_status['organization'] ) && is_array( $ai_api_status['organization'] ) ? $ai_api_status['organization'] : [];
		$ai_api_connection = isset( $ai_api_status['connection'] ) && is_array( $ai_api_status['connection'] ) ? $ai_api_status['connection'] : [];
		$ai_api_credential = isset( $ai_api_status['credential'] ) && is_array( $ai_api_status['credential'] ) ? $ai_api_status['credential'] : [];
		$ai_api_entitlements = isset( $ai_api_status['entitlements'] ) && is_array( $ai_api_status['entitlements'] ) ? $ai_api_status['entitlements'] : [];
		$ai_api_usage = isset( $ai_api_status['usage'] ) && is_array( $ai_api_status['usage'] ) ? $ai_api_status['usage'] : [];
		$ai_api_plan_label = $ai_api_entitlements['plan_label'] ?? ( $ai_api_entitlements['usage_plan'] ?? ( $ai_api_site['plan'] ?? '' ) );
		$ai_api_subscription_status = $ai_api_entitlements['subscription_status'] ?? '';
		$show_ai_api_url_editor = ! $ai_api_url_locked && defined( 'WP_DEBUG' ) && WP_DEBUG;
		$connector_admin_actions_enabled = 'yes' === get_option( YoRo_WordPress_Admin_Actions::POLICY_OPTION, 'no' );
		$connector_backup_attested_at = absint( get_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, 0 ) );

		?>
		<div class="wrap yoro-ai-wrap">
			<h1><?php esc_html_e( 'YoRo AI Settings', 'yoro-ai-core' ); ?></h1>
			<p class="description"><?php esc_html_e( 'WordPress handles approved store actions locally. YoRo AI API handles understanding, routing, and developer diagnostics.', 'yoro-ai-core' ); ?></p>
			<?php self::render_ai_api_connect_notice(); ?>

			<form method="post" class="yoro-ai-settings-form">
				<?php wp_nonce_field( 'yoro_ai_save_settings', 'yoro_ai_settings_nonce' ); ?>
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<input type="hidden" name="yoro_admin_panel" value="settings" />

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Connection', 'yoro-ai-core' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Connect to YoRo AI API', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_api_enabled" value="yes" <?php checked( $ai_api_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Let YoRo AI API understand requests, then execute approved store actions locally.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_api_url"><?php esc_html_e( 'API URL', 'yoro-ai-core' ); ?></label></th>
							<td>
								<?php if ( $show_ai_api_url_editor ) : ?>
									<input type="url" name="yoro_ai_api_url" id="yoro_ai_api_url" value="<?php echo esc_attr( $ai_api_url ); ?>" class="regular-text" placeholder="http://127.0.0.1:8010/api/v1" />
								<?php else : ?>
									<code><?php echo esc_html( '' !== $ai_api_url ? $ai_api_url : __( 'Not configured in this build', 'yoro-ai-core' ) ); ?></code>
									<?php if ( ! $ai_api_url_locked ) : ?>
										<input type="hidden" name="yoro_ai_api_url" value="<?php echo esc_attr( $ai_api_url ); ?>" />
									<?php endif; ?>
								<?php endif; ?>
								<?php if ( '' !== $ai_api_url_constant ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: constant name */
											esc_html__( 'Configured by %s constant.', 'yoro-ai-core' ),
											esc_html( $ai_api_url_constant )
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php echo esc_html( $show_ai_api_url_editor ? __( 'Development override. Production builds use the canonical YoRo service endpoint.', 'yoro-ai-core' ) : __( 'The production service endpoint is controlled by the YoRo build or YORO_AI_API_URL constant.', 'yoro-ai-core' ) ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Site credential', 'yoro-ai-core' ); ?></th>
							<td>
								<?php if ( 'constant' === $ai_api_site_key_source ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
									<?php
									printf(
										/* translators: %s: constant name */
										esc_html__( 'Configured by %s constant.', 'yoro-ai-core' ),
										esc_html( '' !== $ai_api_site_key_constant ? $ai_api_site_key_constant : 'YORO_AI_API_SITE_KEY' )
									);
									?>
								<?php elseif ( 'option' === $ai_api_site_key_source ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
									<?php esc_html_e( 'Configured.', 'yoro-ai-core' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
									<?php esc_html_e( 'Not configured.', 'yoro-ai-core' ); ?>
								<?php endif; ?>

								<p class="description"><?php esc_html_e( 'The credential is issued automatically, encrypted in this WordPress database, and never displayed in the browser.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'YoRo account', 'yoro-ai-core' ); ?></th>
							<td>
								<?php if ( 'constant' === $ai_api_site_key_source ) : ?>
									<p class="description"><?php esc_html_e( 'Hosted connect is disabled because the site key is locked by a constant.', 'yoro-ai-core' ); ?></p>
								<?php else : ?>
									<?php submit_button( 'option' === $ai_api_site_key_source ? __( 'Reconnect YoRo Account', 'yoro-ai-core' ) : __( 'Connect YoRo Account', 'yoro-ai-core' ), 'secondary', 'yoro_ai_api_connect', false ); ?>
									<?php if ( 'option' === $ai_api_site_key_source ) : ?>
										<?php submit_button( __( 'Disconnect', 'yoro-ai-core' ), 'secondary', 'yoro_ai_api_disconnect', false, [ 'onclick' => "return window.confirm('" . esc_js( __( 'Disconnect YoRo from this WordPress site? The saved site key will be removed.', 'yoro-ai-core' ) ) . "');" ] ); ?>
									<?php endif; ?>
									<p class="description"><?php esc_html_e( 'Opens YoRo to create or link an account, generate a site key, and return it to WordPress securely.', 'yoro-ai-core' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<?php if ( current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) : ?>
							<?php $early_access = YoRo_AI_Settings::get_early_access_onboarding(); ?>
							<tr><th scope="row"><?php esc_html_e( 'Early Access', 'yoro-ai-core' ); ?></th><td class="yoro-early-access-onboarding">
								<p><?php echo esc_html( [ 'unknown' => __( 'Unknown — refresh connection', 'yoro-ai-core' ), 'available' => __( 'Available to request', 'yoro-ai-core' ), 'not_available' => __( 'Not available', 'yoro-ai-core' ), 'requested_pending' => __( 'Request pending activation', 'yoro-ai-core' ), 'withdrawn' => __( 'Request withdrawn', 'yoro-ai-core' ) ][ $early_access['status'] ] ?? __( 'Unknown', 'yoro-ai-core' ) ); ?></p>
								<p><?php esc_html_e( 'Runtime disabled. This endpoint is not currently connectable. A request records interest only.', 'yoro-ai-core' ); ?></p>
								<?php if ( ! empty( $early_access['future_endpoint'] ) ) : ?><p><?php esc_html_e( 'Future Custom MCP endpoint:', 'yoro-ai-core' ); ?> <code><?php echo esc_html( $early_access['future_endpoint'] ); ?></code></p><?php endif; ?>
								<p><?php esc_html_e( 'After separately approved activation, complete ChatGPT Custom MCP or Personal Plugin setup through the YoRo account.', 'yoro-ai-core' ); ?></p>
								<?php if ( ! empty( $early_access['future_endpoint'] ) ) : ?><a class="button" href="<?php echo esc_url( preg_replace( '#/mcp/early-access$#', '/app/stores', $early_access['future_endpoint'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage request in YoRo account', 'yoro-ai-core' ); ?></a><?php endif; ?>
							</td></tr>
						<?php endif; ?>

						<tr>
							<th scope="row"><?php esc_html_e( 'Connection status', 'yoro-ai-core' ); ?></th>
							<td>
								<?php if ( ! empty( $ai_api_status['checked_at'] ) && ! empty( $ai_api_account_connection['connected'] ) ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
									<?php esc_html_e( 'Connected.', 'yoro-ai-core' ); ?>
								<?php elseif ( ! empty( $ai_api_status['checked_at'] ) ) : ?>
									<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
									<?php esc_html_e( 'Attention needed:', 'yoro-ai-core' ); ?>
									<?php echo esc_html( $ai_api_status['message'] ?? __( 'I could not verify the connection.', 'yoro-ai-core' ) ); ?>
								<?php else : ?>
									<?php echo esc_html( 'missing' === $ai_api_site_key_source ? __( 'Disconnected.', 'yoro-ai-core' ) : __( 'Not checked yet.', 'yoro-ai-core' ) ); ?>
								<?php endif; ?>

								<p style="margin-top:12px;">
									<?php submit_button( __( 'Test connection', 'yoro-ai-core' ), 'secondary', 'yoro_ai_api_test_connection', false ); ?>
								</p>

								<?php if ( ! empty( $ai_api_account_connection['connected'] ) ) : ?>
									<div style="margin-top:14px;display:grid;gap:10px;max-width:680px;">
										<div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;">
											<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px;background:#fff;">
												<strong><?php esc_html_e( 'Store', 'yoro-ai-core' ); ?></strong><br>
												<span><?php echo esc_html( $ai_api_site['name'] ?? get_bloginfo( 'name' ) ); ?></span><br>
												<span class="description"><?php echo esc_html( $ai_api_site['url'] ?? home_url() ); ?></span>
											</div>
											<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px;background:#fff;">
												<strong><?php esc_html_e( 'Organization', 'yoro-ai-core' ); ?></strong><br>
												<span><?php echo esc_html( $ai_api_organization['name'] ?? __( 'Unknown', 'yoro-ai-core' ) ); ?></span><br>
												<span class="description"><?php echo esc_html( $ai_api_connection['status'] ?? __( 'Connected', 'yoro-ai-core' ) ); ?></span>
											</div>
											<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px;background:#fff;">
												<strong><?php esc_html_e( 'Plan', 'yoro-ai-core' ); ?></strong><br>
												<span><?php echo esc_html( '' !== (string) $ai_api_plan_label ? $ai_api_plan_label : __( 'Unknown', 'yoro-ai-core' ) ); ?></span><br>
												<?php if ( '' !== (string) $ai_api_subscription_status ) : ?>
													<span class="description"><?php echo esc_html( $ai_api_subscription_status ); ?></span>
												<?php endif; ?>
											</div>
											<div style="border:1px solid #dcdcde;border-radius:6px;padding:10px;background:#fff;">
												<strong><?php esc_html_e( 'Usage', 'yoro-ai-core' ); ?></strong><br>
												<?php if ( array_key_exists( 'limit', $ai_api_usage ) && null !== $ai_api_usage['limit'] ) : ?>
													<span><?php echo esc_html( number_format_i18n( absint( $ai_api_usage['used'] ?? 0 ) ) ); ?> / <?php echo esc_html( number_format_i18n( absint( $ai_api_usage['limit'] ) ) ); ?></span><br>
													<span class="description">
														<?php
														printf(
															/* translators: %s: remaining usage */
															esc_html__( '%s remaining', 'yoro-ai-core' ),
															esc_html( number_format_i18n( absint( $ai_api_usage['remaining'] ?? 0 ) ) )
														);
														?>
													</span>
												<?php else : ?>
													<span><?php echo esc_html( number_format_i18n( absint( $ai_api_usage['used'] ?? 0 ) ) ); ?></span><br>
													<span class="description"><?php esc_html_e( 'Unlimited or not reported', 'yoro-ai-core' ); ?></span>
												<?php endif; ?>
											</div>
											<?php if ( ! empty( $ai_api_credential['expires_at'] ) ) : ?>
												<p class="description" style="grid-column:1/-1;margin:0;"><?php echo esc_html( sprintf( __( 'Credential expires: %s', 'yoro-ai-core' ), $ai_api_credential['expires_at'] ) ); ?></p>
											<?php endif; ?>
										</div>

										<p style="margin:0;display:flex;gap:8px;flex-wrap:wrap;">
											<?php if ( '' !== $ai_api_account_url ) : ?>
												<a class="button button-secondary" href="<?php echo esc_url( $ai_api_account_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Manage YoRo Account', 'yoro-ai-core' ); ?></a>
											<?php endif; ?>
											<?php if ( '' !== $ai_api_billing_url ) : ?>
												<a class="button button-secondary" href="<?php echo esc_url( $ai_api_billing_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Billing / Upgrade', 'yoro-ai-core' ); ?></a>
											<?php endif; ?>
										</p>
									</div>
								<?php endif; ?>
							</td>
						</tr>
					</table>
				</div>

				<?php if ( current_user_can( YoRo_Capabilities::MANAGE_POLICIES ) ) : ?>
					<div class="yoro-ai-card">
						<h2><?php esc_html_e( 'WordPress Administration Safety', 'yoro-ai-core' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'Administration actions', 'yoro-ai-core' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="yoro_connector_admin_actions_enabled" value="yes" <?php checked( $connector_admin_actions_enabled ); ?> />
										<?php esc_html_e( 'Allow confirmed plugin, theme, core, settings, and bounded user-role actions.', 'yoro-ai-core' ); ?>
									</label>
									<p class="description"><?php esc_html_e( 'These actions remain blocked unless the acting WordPress administrator has the native capability and a backup was attested in the last 24 hours.', 'yoro-ai-core' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Backup attestation', 'yoro-ai-core' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="yoro_connector_backup_attested_now" value="yes" />
										<?php esc_html_e( 'I confirm that a restorable backup of this site completed successfully now.', 'yoro-ai-core' ); ?>
									</label>
									<p class="description">
										<?php
										echo esc_html(
											$connector_backup_attested_at > 0
												? sprintf( __( 'Last attested: %s', 'yoro-ai-core' ), wp_date( 'Y-m-d H:i:s T', $connector_backup_attested_at ) )
												: __( 'No backup has been attested.', 'yoro-ai-core' )
										);
										?>
									</p>
								</td>
							</tr>
						</table>
					</div>
				<?php endif; ?>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Local Store Controls', 'yoro-ai-core' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_ai_max_result_limit"><?php esc_html_e( 'Maximum results to show', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="1" max="100" name="yoro_ai_max_result_limit" id="yoro_ai_max_result_limit" value="<?php echo esc_attr( $max_result_limit ); ?>" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_rate_limit_count"><?php esc_html_e( 'Usage limit', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="1" name="yoro_rate_limit_count" id="yoro_rate_limit_count" value="<?php echo esc_attr( $rate_limit_count ); ?>" />
								<input type="number" min="10" name="yoro_rate_limit_window" id="yoro_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" />
								<p class="description"><?php esc_html_e( 'First field is request count. Second field is the time window in seconds.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_undo_ttl"><?php esc_html_e( 'Undo window', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="0" name="yoro_undo_ttl" id="yoro_undo_ttl" value="<?php echo esc_attr( $undo_ttl ); ?>" />
								<p class="description"><?php esc_html_e( 'How many seconds YoRo should keep undo available after supported changes.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<input type="hidden" name="yoro_ai_email_compose_enabled" value="yes" />

				<?php submit_button( __( 'Save settings', 'yoro-ai-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_policy_save() {

		if ( ! current_user_can( YoRo_Capabilities::MANAGE_POLICIES ) ) {
			return;
		}

		/*
		* Save simple policy mode.
		*/
		if ( ! empty( $_POST['yoro_policy_mode_nonce'] ) ) {
			if (
				! wp_verify_nonce(
					sanitize_text_field( wp_unslash( $_POST['yoro_policy_mode_nonce'] ) ),
					'yoro_save_policy_mode'
				)
			) {
				return;
			}

			if ( YoRo_Safety_Contract::is_server_authoritative() ) {
				wp_safe_redirect(
					add_query_arg(
						[
							'page'             => self::MENU_SLUG,
							'yoro_admin_panel' => 'safety',
							'policy_saved'     => 'centralized',
						],
						admin_url( 'admin.php' )
					)
				);
				exit;
			}

			$mode = isset( $_POST['yoro_safety_profile'] )
				? sanitize_key( wp_unslash( $_POST['yoro_safety_profile'] ) )
				: 'assisted';

			$allowed_modes = [
				'advisory',
				'assisted',
				'delegated',
			];

			if ( ! in_array( $mode, $allowed_modes, true ) ) {
				$mode = 'assisted';
			}

			update_option( YoRo_Safety_Contract::PROFILE_OPTION, $mode );
			update_option( YoRo_Safety_Contract::POLICY_VERSION_OPTION, YoRo_Safety_Contract::policy_version() + 1 );

			wp_safe_redirect(
				add_query_arg(
					[
						'page'             => self::MENU_SLUG,
						'yoro_admin_panel' => 'safety',
						'policy_saved'     => '1',
					],
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		/*
		* Save advanced per-action policy row.
		*/
		if ( empty( $_POST['policy_id'] ) ) {
			return;
		}

		$policy_id = absint( $_POST['policy_id'] );

		if ( ! $policy_id ) {
			return;
		}

		if (
			empty( $_POST['yoro_policy_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['yoro_policy_nonce'] ) ),
				'yoro_save_policy_' . $policy_id
			)
		) {
			return;
		}

		global $wpdb;

		$table = YoRo_DB::policies_table_name();

		$allow = isset( $_POST['allow'] )
			? absint( $_POST['allow'] )
			: 1;

		$allow = $allow ? 1 : 0;

		$max_items = isset( $_POST['max_items'] )
			? absint( $_POST['max_items'] )
			: 0;

		$requires_preview = isset( $_POST['requires_preview'] ) ? 1 : 0;

		$requires_confirmation = isset( $_POST['requires_confirmation'] ) ? 1 : 0;

		$allow_ai = isset( $_POST['allow_ai'] ) ? 1 : 0;

		$allow_direct = isset( $_POST['allow_direct'] ) ? 1 : 0;

		$allow_write = isset( $_POST['allow_write'] ) ? 1 : 0;

		$allowed_risk_levels = isset( $_POST['allowed_risk_levels'] ) && is_array( $_POST['allowed_risk_levels'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['allowed_risk_levels'] ) )
			: [];

		$allowed_risk_levels = array_values(
			array_intersect(
				$allowed_risk_levels,
				[ 'low', 'medium', 'high' ]
			)
		);

		if ( empty( $allowed_risk_levels ) ) {
			$allowed_risk_levels = [ 'low', 'medium', 'high' ];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB -- Updates one plugin-owned custom policy row after nonce and capability checks.
		$updated = $wpdb->update(
			$table,
			[
				'allow'                 => $allow,
				'max_items'             => $max_items,
				'requires_preview'      => $requires_preview,
				'requires_confirmation' => $requires_confirmation,
				'allow_ai'              => $allow_ai,
				'allow_direct'          => $allow_direct,
				'allow_write'           => $allow_write,
				'allowed_risk_levels'   => wp_json_encode( $allowed_risk_levels ),
				'updated_at'            => current_time( 'mysql' ),
			],
			[
				'id' => $policy_id,
			],
			[
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%d',
				'%s',
				'%s',
			],
			[
				'%d',
			]
		);

		$redirect_url = add_query_arg(
			[
				'page'             => self::MENU_SLUG,
				'yoro_admin_panel' => 'safety',
				'policy_saved'     => false === $updated ? '0' : '1',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function handle_ai_api_connect_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Hosted-connect callback is verified with the transient state token below.
		if ( empty( $_GET['page'] ) || self::MENU_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		if ( empty( $_GET['yoro_connect_callback'] ) ) {
			return;
		}

		if ( ! current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) {
			wp_die( esc_html__( 'You do not have permission to connect YoRo.', 'yoro-ai-core' ) );
		}

		$code    = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$session = get_transient( self::ai_api_connect_state_key() );

		if ( '' === $code || '' === $state || ! is_array( $session ) || empty( $session['state'] ) || ! hash_equals( (string) $session['state'], $state ) ) {
			delete_transient( self::ai_api_connect_state_key() );
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'YoRo connection could not be verified. Please start the connection again from WordPress.', 'yoro-ai-core' )
			);
		}

		$api_url = esc_url_raw( $session['api_url'] ?? YoRo_AI_Settings::get_ai_api_url() );
		$code_verifier = sanitize_text_field( $session['code_verifier'] ?? '' );
		$installation_uuid = sanitize_text_field( $session['installation_uuid'] ?? '' );

		if ( '' === $code_verifier || '' === $installation_uuid || ! hash_equals( YoRo_AI_Settings::get_installation_uuid(), $installation_uuid ) ) {
			delete_transient( self::ai_api_connect_state_key() );
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'YoRo connection proof is missing or belongs to another WordPress installation.', 'yoro-ai-core' )
			);
		}

		$result  = ( new YoRo_AI_API_Client() )->exchange_wordpress_connect_code(
			[
				'code'           => $code,
				'state'          => $state,
				'site_url'       => home_url(),
				'installation_uuid' => $installation_uuid,
				'code_verifier'  => $code_verifier,
				'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			],
			$api_url
		);

		delete_transient( self::ai_api_connect_state_key() );

		if ( is_wp_error( $result ) ) {
			self::redirect_to_settings_with_connect_notice( 'error', $result->get_error_message() );
		}

		$connected_api_url = esc_url_raw( $result['api_url'] ?? $api_url );
		$site_key          = sanitize_text_field( $result['site_key'] ?? '' );
		$save_result       = YoRo_AI_Settings::save_connected_ai_api_credentials( $connected_api_url, $site_key );

		if ( is_wp_error( $save_result ) ) {
			self::redirect_to_settings_with_connect_notice( 'error', $save_result->get_error_message() );
		}

		self::redirect_to_settings_with_connect_notice(
			'success',
			__( 'YoRo account connected. The new site key was verified and saved.', 'yoro-ai-core' )
		);
	}

	private static function start_ai_api_connect() {
		if ( YoRo_AI_Settings::is_ai_api_site_key_locked() ) {
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'Hosted connect is disabled because the YoRo site key is configured by constant.', 'yoro-ai-core' )
			);
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- handle_settings_save() verifies the settings form nonce before this private method is called.
		$api_url = YoRo_AI_Settings::is_ai_api_url_locked()
			? YoRo_AI_Settings::get_ai_api_url()
			: ( isset( $_POST['yoro_ai_api_url'] ) ? esc_url_raw( wp_unslash( $_POST['yoro_ai_api_url'] ) ) : YoRo_AI_Settings::get_ai_api_url() );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $api_url ) {
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'Enter the YoRo AI API URL before connecting your account.', 'yoro-ai-core' )
			);
		}

		$connect_proof     = YoRo_AI_Settings::create_wordpress_connect_proof();
		$state             = $connect_proof['state'];
		$code_verifier     = $connect_proof['code_verifier'];
		$code_challenge    = $connect_proof['code_challenge'];
		$installation_uuid = $connect_proof['installation_uuid'];
		set_transient(
			self::ai_api_connect_state_key(),
			[
				'state'             => $state,
				'api_url'           => $api_url,
				'code_verifier'     => $code_verifier,
				'installation_uuid' => $installation_uuid,
			],
			15 * MINUTE_IN_SECONDS
		);

		$result = ( new YoRo_AI_API_Client() )->create_wordpress_connect_intent(
			[
				'site_name'        => get_bloginfo( 'name' ),
				'site_url'         => home_url(),
				'return_url'       => self::settings_url(
					[
						'yoro_connect_callback' => '1',
					]
				),
				'state'            => $state,
				'installation_uuid' => $installation_uuid,
				'code_challenge'    => $code_challenge,
				'code_challenge_method' => 'S256',
				'environment'      => YoRo_AI_Settings::get_ai_api_environment(),
				'plugin_version'   => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'wordpress_actor'  => YoRo_WordPress_Actor::current_payload(),
				'language_context' => [
					'site_locale'        => get_locale(),
					'user_locale'        => get_user_locale(),
					'timezone'           => wp_timezone_string(),
					'preferred_language' => substr( get_user_locale(), 0, 2 ),
				],
			],
			$api_url
		);

		if ( is_wp_error( $result ) ) {
			delete_transient( self::ai_api_connect_state_key() );
			self::redirect_to_settings_with_connect_notice( 'error', $result->get_error_message() );
		}

		$connect_url = esc_url_raw( $result['connect_url'] ?? '' );

		if ( '' === $connect_url ) {
			delete_transient( self::ai_api_connect_state_key() );
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'YoRo did not return a connection URL. Please try again.', 'yoro-ai-core' )
			);
		}

		self::safe_redirect_to_ai_api_url( $connect_url, $api_url );
		exit;
	}

	private static function safe_redirect_to_ai_api_url( $redirect_url, $api_url ) {
		$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
		$api_host      = wp_parse_url( $api_url, PHP_URL_HOST );
		$redirect_scheme = strtolower( (string) wp_parse_url( $redirect_url, PHP_URL_SCHEME ) );
		$api_scheme      = strtolower( (string) wp_parse_url( $api_url, PHP_URL_SCHEME ) );
		$redirect_port   = wp_parse_url( $redirect_url, PHP_URL_PORT );
		$api_port        = wp_parse_url( $api_url, PHP_URL_PORT );
		$redirect_port   = $redirect_port ? absint( $redirect_port ) : ( 'https' === $redirect_scheme ? 443 : 80 );
		$api_port        = $api_port ? absint( $api_port ) : ( 'https' === $api_scheme ? 443 : 80 );

		if (
			empty( $redirect_host ) ||
			empty( $api_host ) ||
			strtolower( $redirect_host ) !== strtolower( $api_host ) ||
			$redirect_scheme !== $api_scheme ||
			$redirect_port !== $api_port
		) {
			self::redirect_to_settings_with_connect_notice(
				'error',
				__( 'YoRo returned a connection URL that does not match the configured API host.', 'yoro-ai-core' )
			);
		}

		$allow_ai_api_host = static function ( $hosts ) use ( $api_host ) {
			$hosts[] = strtolower( $api_host );

			return array_values( array_unique( $hosts ) );
		};

		add_filter( 'allowed_redirect_hosts', $allow_ai_api_host );
		wp_safe_redirect( $redirect_url );
		remove_filter( 'allowed_redirect_hosts', $allow_ai_api_host );
	}

	private static function render_ai_api_connect_notice() {
		$notice = get_transient( self::ai_api_connect_notice_key() );

		if ( ! is_array( $notice ) ) {
			return;
		}

		delete_transient( self::ai_api_connect_notice_key() );

		$type    = 'success' === ( $notice['type'] ?? '' ) ? 'success' : 'error';
		$message = sanitize_text_field( $notice['message'] ?? '' );

		if ( '' === $message ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible" data-yoro-admin-notice-panel="settings"><p>' . esc_html( $message ) . '</p></div>';
	}

	private static function redirect_to_settings_with_connect_notice( $type, $message ) {
		set_transient(
			self::ai_api_connect_notice_key(),
			[
				'type'    => sanitize_key( $type ),
				'message' => sanitize_text_field( $message ),
			],
			5 * MINUTE_IN_SECONDS
		);

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	private static function settings_url( array $args = [] ) {
		return add_query_arg(
			array_merge(
				[
					'page'             => self::MENU_SLUG,
					'yoro_admin_panel' => 'settings',
				],
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private static function ai_api_connect_state_key() {
		return 'yoro_ai_connect_state_' . get_current_user_id();
	}

	private static function ai_api_connect_notice_key() {
		return 'yoro_ai_connect_notice_' . get_current_user_id();
	}

	public static function handle_settings_save() {
		if ( empty( $_POST['yoro_ai_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yoro_ai_settings_nonce'] ) ), 'yoro_ai_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( YoRo_Capabilities::MANAGE_CONNECTION ) ) {
			return;
		}

		if ( ! empty( $_POST['yoro_ai_api_connect'] ) ) {
			self::start_ai_api_connect();
		}

		if ( ! empty( $_POST['yoro_ai_api_disconnect'] ) ) {
			$disconnect_result = YoRo_AI_Settings::disconnect_ai_api();

			if ( is_wp_error( $disconnect_result ) ) {
				self::redirect_to_settings_with_connect_notice( 'error', $disconnect_result->get_error_message() );
			}

			self::redirect_to_settings_with_connect_notice(
				'success',
				__( 'YoRo account disconnected from this WordPress site.', 'yoro-ai-core' )
			);
		}

		$settings_result = YoRo_AI_Settings::save_admin_settings( $_POST );

		if ( current_user_can( YoRo_Capabilities::MANAGE_POLICIES ) ) {
			update_option(
				YoRo_WordPress_Admin_Actions::POLICY_OPTION,
				! empty( $_POST['yoro_connector_admin_actions_enabled'] ) ? 'yes' : 'no',
				false
			);

			if ( ! empty( $_POST['yoro_connector_backup_attested_now'] ) ) {
				update_option( YoRo_WordPress_Admin_Actions::BACKUP_ATTESTED_OPTION, time(), false );
			}
		}

		$connection_result = is_array( $settings_result )
			? ( $settings_result['ai_api_connection_result'] ?? null )
			: null;
		$connection_error = is_array( $settings_result )
			? ( $settings_result['ai_api_connection_error'] ?? null )
			: null;
		$credentials_verified = is_array( $settings_result ) && ! empty( $settings_result['ai_api_credentials_verified'] );

		if (
			! empty( $_POST['yoro_ai_api_test_connection'] ) &&
			! is_wp_error( $connection_error ) &&
			! $credentials_verified
		) {
			$connection_result = ( new YoRo_AI_API_Client() )->handshake();
		}

		if ( is_wp_error( $connection_error ) ) {
			self::redirect_to_settings_with_connect_notice(
				'error',
				sprintf(
					/* translators: %s: connection error message */
					__( 'YoRo settings saved, but the new YoRo AI API credentials were not saved: %s', 'yoro-ai-core' ),
					$connection_error->get_error_message()
				)
			);
		}

		if ( is_wp_error( $connection_result ) ) {
			self::redirect_to_settings_with_connect_notice( 'error', $connection_result->get_error_message() );
		}

		if ( is_array( $connection_result ) ) {
			self::redirect_to_settings_with_connect_notice(
				'success',
				$credentials_verified
					? __( 'YoRo settings saved. New YoRo AI API credentials verified and saved.', 'yoro-ai-core' )
					: __( 'YoRo settings saved. YoRo AI API connection verified.', 'yoro-ai-core' )
			);
		}

		self::redirect_to_settings_with_connect_notice(
			'success',
			__( 'YoRo settings saved.', 'yoro-ai-core' )
		);
	}

}
