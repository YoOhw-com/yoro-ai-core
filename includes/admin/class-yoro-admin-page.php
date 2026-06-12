<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Admin_Page {

	const MENU_SLUG = 'yoro-ai-core';

	private static $admin_screen_ids = [];

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_policy_save' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_settings_save' ] );

		add_filter( 'admin_footer_text', [ __CLASS__, 'filter_admin_footer_text' ], 20 );
		add_filter( 'update_footer', [ __CLASS__, 'filter_update_footer' ], 20 );
	}

	public static function add_menu_page() {
		self::$admin_screen_ids = [];

		self::$admin_screen_ids[] = add_menu_page(
			__( 'YoRo AI', 'yoro-ai-core' ),
			__( 'YoRo AI', 'yoro-ai-core' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			self::get_menu_icon(),
			2
		);
	}

	private static function get_menu_icon() {
		$icon_path = YORO_AI_CORE_PATH . 'assets/images/yoro-menu-icon.svg';
		$svg       = is_readable( $icon_path ) ? file_get_contents( $icon_path ) : '';

		if ( ! is_string( $svg ) || '' === trim( $svg ) ) {
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10" fill="black"/><path fill="#b8d421" d="M5.8 20.8c-.5-4.2.3-8.5 2.1-11.5 2.1-3.4 5.4-5.7 10.6-5.8-4.1.8-6.7 3.3-7.6 6.1-.8 2.4 0 4.3 1.7 5.1-2.4-.8-5.3.2-6.8 6.1Z"/><path fill="#b8d421" d="M8.8 21.1c1.1-3 3.2-5.1 5.5-6.8 2.5-1.9 3.2-4.4 1.8-6 2.3 1.1 3.6 3.3 3.2 5.9-.4 3.3-3.4 6-10.5 6.9Z"/></svg>';
		}

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

	public static function enqueue_assets( $hook ) {
		if ( ! in_array( $hook, self::get_admin_screen_ids(), true ) ) {
			return;
		}

		wp_enqueue_style(
			'yoro-ai-admin',
			YORO_AI_CORE_URL . 'assets/css/yoro-ai-admin.css',
			[],
			YORO_AI_CORE_VERSION
		);

		wp_enqueue_script(
			'yoro-ai-display-labels',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-display-labels.js',
			[],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-workflow-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-workflow-renderers.js',
			[ 'yoro-ai-display-labels' ],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-email-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-email-ui.js',
			[ 'yoro-ai-display-labels' ],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-order-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-order-ui.js',
			[ 'yoro-ai-display-labels' ],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-result-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-result-renderers.js',
			[ 'yoro-ai-display-labels' ],
			YORO_AI_CORE_VERSION,
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
			YORO_AI_CORE_VERSION,
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
				'aiApiStatusUrl'                        => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/status' ) ),
				'aiApiHandshakeUrl'                     => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/handshake' ) ),
				'aiApiDashboardUrl'                     => esc_url_raw( YoRo_AI_Settings::get_ai_api_dashboard_url() ),
				'aiApiConversationDashboardBaseUrl'     => esc_url_raw( YoRo_AI_Settings::get_ai_api_conversation_dashboard_base_url() ),
				'conversationStorageKey'                => $conversation_storage_key,
				'nonce'                                 => wp_create_nonce( 'wp_rest' ),
				'orderStatuses'                         => $order_statuses,
				'dateFormat'                            => get_option( 'date_format', 'Y-m-d' ),
				'timeFormat'                            => get_option( 'time_format', 'H:i' ),
				'i18n'                                  => [
					'emptyCommand'         => __( 'Please enter a request.', 'yoro-ai-core' ),
					'running'              => __( 'Thinking...', 'yoro-ai-core' ),
					'askYoRo'              => __( 'Ask YoRo', 'yoro-ai-core' ),
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
		?>
		<div class="yoro-chat-shell">
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<div class="yoro-ai-api-status" data-yoro-ai-api-status>
					<button type="button" class="yoro-ai-api-status-toggle is-loading" data-yoro-ai-api-status-toggle aria-expanded="false" aria-controls="yoro-ai-api-status-panel">
						<span class="yoro-ai-api-status-dot" aria-hidden="true"></span>
						<span data-yoro-ai-api-status-label><?php esc_html_e( 'YoRo AI API', 'yoro-ai-core' ); ?></span>
					</button>

					<div class="yoro-ai-api-status-panel" id="yoro-ai-api-status-panel" data-yoro-ai-api-status-panel hidden>
						<div class="yoro-ai-api-status-panel-head">
							<strong><?php esc_html_e( 'YoRo AI API', 'yoro-ai-core' ); ?></strong>
							<button type="button" class="button button-small" data-yoro-ai-api-status-refresh><?php esc_html_e( 'Refresh', 'yoro-ai-core' ); ?></button>
						</div>
						<div class="yoro-ai-api-status-panel-body" data-yoro-ai-api-status-body>
							<p><?php esc_html_e( 'Checking connection...', 'yoro-ai-core' ); ?></p>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<div class="yoro-chat-quick-actions" data-yoro-quick-actions>
				<div class="yoro-chat-quick-tray">
					<div class="yoro-chat-quick-panel" id="yoro-chat-quick-panel" hidden data-yoro-quick-panel>
						<div class="yoro-chat-quick-search-wrap">
							<input
								type="search"
								class="yoro-chat-quick-search"
								placeholder="<?php esc_attr_e( 'Search order #, customer email, or phone', 'yoro-ai-core' ); ?>"
								aria-label="<?php esc_attr_e( 'Quick search orders and customers', 'yoro-ai-core' ); ?>"
								data-yoro-quick-search
							/>
						</div>

						<div class="yoro-chat-quick-search-results" data-yoro-quick-results>
							<p class="yoro-chat-quick-hint"><?php esc_html_e( 'Search by order number, customer email, or phone number.', 'yoro-ai-core' ); ?></p>
						</div>

						<div class="yoro-chat-quick-presets">
							<div class="yoro-chat-quick-presets-label"><?php esc_html_e( 'Open fast', 'yoro-ai-core' ); ?></div>
							<div class="yoro-chat-quick-presets-grid">
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="store.get_daily_briefing"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'last_7_days', 'limit' => 1 ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Store report', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Last 7 days across sales and operations', 'yoro-ai-core' ); ?></span>
								</button>
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="store.get_business_summary"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'last_7_days' ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Sales report', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Revenue, orders, customers, and products', 'yoro-ai-core' ); ?></span>
								</button>
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="customers.get_service_queue"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Customer service', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Orders needing follow-up', 'yoro-ai-core' ); ?></span>
								</button>
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="operations.get_attention_queue"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Attention queue', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Priority operational issues', 'yoro-ai-core' ); ?></span>
								</button>
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="operations.get_fulfillment_queue"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'limit' => 8 ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Fulfillment', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'What needs shipping next', 'yoro-ai-core' ); ?></span>
								</button>
							</div>
						</div>
					</div>
					<button type="button" class="yoro-chat-quick-bubble" data-yoro-quick-toggle aria-expanded="false" aria-controls="yoro-chat-quick-panel">
						<span class="yoro-chat-quick-bubble-icon">+</span>
						<span class="yoro-chat-quick-bubble-label"><?php esc_html_e( 'Quick actions', 'yoro-ai-core' ); ?></span>
					</button>
				</div>
			</div>

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
						<?php esc_html_e( 'Ask YoRo', 'yoro-ai-core' ); ?>
					</button>

					<button type="button" class="button" id="yoro-ai-clear">
						<?php esc_html_e( 'Clear Chat', 'yoro-ai-core' ); ?>
					</button>

					<button type="button" class="button" id="yoro-ai-load-context">
						<?php esc_html_e( 'Store Context', 'yoro-ai-core' ); ?>
					</button>

					<span class="yoro-chat-actions-spacer" aria-hidden="true"></span>

					<button type="button" class="button yoro-chat-admin-button" id="yoro-ai-open-admin-panels">
						<?php esc_html_e( 'Admin', 'yoro-ai-core' ); ?>
					</button>
				</div>

				<div class="yoro-ai-examples">
					<strong><?php esc_html_e( 'Try:', 'yoro-ai-core' ); ?></strong>
					<button type="button" class="button-link yoro-ai-example" data-command="Show me the latest 5 orders">
						<?php esc_html_e( 'Show latest 5 orders', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="How is the store doing over the last 7 days?" data-action-catalog-scope="report_provider_catalog" data-report-topic="overview">
						<?php esc_html_e( 'Store performance last 7 days', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="Show store report for the last 3 days" data-action-catalog-scope="report_provider_catalog" data-report-topic="overview">
						<?php esc_html_e( 'Store report for 3 days', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="Show customer service queue">
						<?php esc_html_e( 'Open customer service queue', 'yoro-ai-core' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php self::render_admin_panel_modal_source(); ?>
		<?php
	}

	private static function render_admin_panel_modal_source() {
		$initial_panel = isset( $_REQUEST['yoro_admin_panel'] )
			? sanitize_key( wp_unslash( $_REQUEST['yoro_admin_panel'] ) )
			: '';

		if ( ! in_array( $initial_panel, [ 'history', 'safety', 'settings' ], true ) ) {
			$initial_panel = '';
		}
		?>
		<div class="yoro-admin-panel-source" data-yoro-admin-panels data-yoro-admin-initial-panel="<?php echo esc_attr( $initial_panel ); ?>" hidden>
			<div class="yoro-admin-panel-modal" data-yoro-admin-modal>
				<div class="yoro-admin-panel-tabs" role="tablist" aria-label="<?php esc_attr_e( 'YoRo AI admin panels', 'yoro-ai-core' ); ?>">
					<button type="button" class="button yoro-admin-panel-tab is-active" data-yoro-admin-tab="history" role="tab" aria-selected="true">
						<?php esc_html_e( 'History', 'yoro-ai-core' ); ?>
					</button>

					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<button type="button" class="button yoro-admin-panel-tab" data-yoro-admin-tab="safety" role="tab" aria-selected="false">
							<?php esc_html_e( 'Safety', 'yoro-ai-core' ); ?>
						</button>

						<button type="button" class="button yoro-admin-panel-tab" data-yoro-admin-tab="settings" role="tab" aria-selected="false">
							<?php esc_html_e( 'Settings', 'yoro-ai-core' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="yoro-admin-panel-section is-active" data-yoro-admin-panel="history" role="tabpanel">
					<?php self::render_history_page(); ?>
				</div>

				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<div class="yoro-admin-panel-section" data-yoro-admin-panel="safety" role="tabpanel">
						<?php self::render_policies_page(); ?>
					</div>

					<div class="yoro-admin-panel-section" data-yoro-admin-panel="settings" role="tabpanel">
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
					<?php esc_html_e( 'A simple timeline of store action events. Chat transcripts and debug traces live in the Laravel dashboard.', 'yoro-ai-core' ); ?>
					<?php if ( '' !== $dashboard_url ) : ?>
						<a href="<?php echo esc_url( $dashboard_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Laravel dashboard', 'yoro-ai-core' ); ?></a>
					<?php endif; ?>
				</p>

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

	public static function render_policies_page() {
		global $wpdb;

		$table = YoRo_DB::policies_table_name();

		$policy_mode      = get_option( 'yoro_policy_mode', 'balanced' );
		$advanced_enabled = get_option( 'yoro_policy_advanced_enabled', 'no' );

		$allowed_modes = [ 'permissive', 'balanced', 'strict', 'custom' ];

		if ( ! in_array( $policy_mode, $allowed_modes, true ) ) {
			$policy_mode = 'balanced';
		}

		$show_advanced = (
			'custom' === $policy_mode &&
			'yes' === $advanced_enabled
		);

		$rows = [];

		if ( $show_advanced ) {
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY action ASC" );
		}

		?>
		<div class="wrap yoro-ai-wrap">
			<h1><?php esc_html_e( 'YoRo Safety', 'yoro-ai-core' ); ?></h1>

			<?php if ( isset( $_GET['policy_saved'] ) ) : ?>
				<?php if ( '1' === sanitize_text_field( wp_unslash( $_GET['policy_saved'] ) ) ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Safety mode saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'Safety mode could not be saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Safety Mode', 'yoro-ai-core' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Choose how often YoRo should pause for review before store changes. Balanced is recommended for most stores.', 'yoro-ai-core' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'yoro_save_policy_mode', 'yoro_policy_mode_nonce' ); ?>
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
					<input type="hidden" name="yoro_admin_panel" value="safety" />

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode', 'yoro-ai-core' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="yoro_policy_mode" value="permissive" <?php checked( $policy_mode, 'permissive' ); ?> />
										<strong><?php esc_html_e( 'Permissive', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Best for testing. YoRo asks less often, but still pauses before sensitive changes.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="balanced" <?php checked( $policy_mode, 'balanced' ); ?> />
										<strong><?php esc_html_e( 'Balanced — Recommended', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Recommended for most stores. YoRo keeps everyday work moving and asks before meaningful changes.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="strict" <?php checked( $policy_mode, 'strict' ); ?> />
										<strong><?php esc_html_e( 'Strict', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Safer for larger teams. YoRo asks for review more often and keeps bulk changes tighter.', 'yoro-ai-core' ); ?>
									</p>

								</fieldset>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save safety mode', 'yoro-ai-core' ) ); ?>
				</form>
			</div>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Current Safety Mode', 'yoro-ai-core' ); ?></h2>

				<?php if ( 'permissive' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will allow common requests and still ask before risky store changes.', 'yoro-ai-core' ); ?></p>
				<?php elseif ( 'strict' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use tighter bulk limits and ask for approval more often.', 'yoro-ai-core' ); ?></p>
				<?php elseif ( 'custom' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use your custom role-based safety rules.', 'yoro-ai-core' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'YoRo will use balanced safety controls for normal store management.', 'yoro-ai-core' ); ?></p>
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

		?>
		<div class="wrap yoro-ai-wrap">
			<h1><?php esc_html_e( 'YoRo AI Settings', 'yoro-ai-core' ); ?></h1>
			<p class="description"><?php esc_html_e( 'WordPress handles approved store actions locally. YoRo AI API handles understanding, routing, and developer diagnostics.', 'yoro-ai-core' ); ?></p>

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
								<input type="url" name="yoro_ai_api_url" id="yoro_ai_api_url" value="<?php echo esc_attr( $ai_api_url ); ?>" class="regular-text" placeholder="<?php echo esc_attr( YoRo_AI_Settings::DEFAULT_AI_API_URL ); ?>" <?php disabled( $ai_api_url_locked ); ?> />
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
									<p class="description"><?php esc_html_e( 'Local development default: http://127.0.0.1:8010/api/v1.', 'yoro-ai-core' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_api_site_key"><?php esc_html_e( 'Site key', 'yoro-ai-core' ); ?></label></th>
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

								<p style="margin-top:12px;">
									<input
										type="password"
										name="yoro_ai_api_site_key"
										id="yoro_ai_api_site_key"
										value=""
										class="regular-text"
										autocomplete="new-password"
										placeholder="<?php esc_attr_e( 'Paste a new site key to replace the current one', 'yoro-ai-core' ); ?>"
										<?php disabled( 'constant' === $ai_api_site_key_source ); ?>
									/>
								</p>

								<?php if ( 'constant' !== $ai_api_site_key_source ) : ?>
									<p>
										<label>
											<input type="checkbox" name="yoro_ai_api_site_key_clear" value="yes" />
											<?php esc_html_e( 'Clear saved site key', 'yoro-ai-core' ); ?>
										</label>
									</p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Connection status', 'yoro-ai-core' ); ?></th>
							<td>
								<?php if ( ! empty( $ai_api_status['checked_at'] ) && ! empty( $ai_api_status['connected'] ) ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
									<?php esc_html_e( 'Connected.', 'yoro-ai-core' ); ?>
								<?php elseif ( ! empty( $ai_api_status['checked_at'] ) ) : ?>
									<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
										<?php echo esc_html( $ai_api_status['message'] ?? __( 'I could not verify the connection.', 'yoro-ai-core' ) ); ?>
								<?php else : ?>
									<?php esc_html_e( 'Not checked yet.', 'yoro-ai-core' ); ?>
								<?php endif; ?>

								<p style="margin-top:12px;">
									<?php submit_button( __( 'Test connection', 'yoro-ai-core' ), 'secondary', 'yoro_ai_api_test_connection', false ); ?>
								</p>
							</td>
						</tr>
					</table>
				</div>

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

		if ( ! current_user_can( 'manage_options' ) ) {
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

			$mode = isset( $_POST['yoro_policy_mode'] )
				? sanitize_key( wp_unslash( $_POST['yoro_policy_mode'] ) )
				: 'balanced';

			$allowed_modes = [
				'permissive',
				'balanced',
				'strict',
				'custom',
			];

			if ( ! in_array( $mode, $allowed_modes, true ) ) {
				$mode = 'balanced';
			}

			update_option( 'yoro_policy_mode', $mode );
			update_option(
				'yoro_policy_advanced_enabled',
				isset( $_POST['yoro_policy_advanced_enabled'] ) ? 'yes' : 'no'
			);

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

	public static function handle_settings_save() {
		if ( empty( $_POST['yoro_ai_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yoro_ai_settings_nonce'] ) ), 'yoro_ai_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings_result = YoRo_AI_Settings::save_admin_settings( $_POST );

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

		add_action(
			'admin_notices',
			function() use ( $connection_result, $connection_error, $settings_result, $credentials_verified ) {
				if ( is_wp_error( $connection_error ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' .
						esc_html(
							sprintf(
								/* translators: %s: connection error message */
								__( 'YoRo settings saved, but the new YoRo AI API credentials were not saved: %s', 'yoro-ai-core' ),
								$connection_error->get_error_message()
							)
						) .
						'</p></div>';

					return;
				}

				if ( is_wp_error( $connection_result ) ) {
					echo '<div class="notice notice-error is-dismissible"><p>' .
						esc_html( $connection_result->get_error_message() ) .
						'</p></div>';

					return;
				}

				if ( is_array( $connection_result ) ) {
					$message = $credentials_verified
						? __( 'YoRo settings saved. New YoRo AI API credentials verified and saved.', 'yoro-ai-core' )
						: __( 'YoRo settings saved. YoRo AI API connection verified.', 'yoro-ai-core' );

					echo '<div class="notice notice-success is-dismissible"><p>' .
						esc_html( $message ) .
						'</p></div>';

					return;
				}

				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'YoRo settings saved.', 'yoro-ai-core' ) .
					'</p></div>';
			}
		);
	}

	public static function filter_admin_footer_text( $text ) {
		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return $text;
		}

		// Main YoRo AI page: remove footer text completely.
		if ( 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return '';
		}

		return $text;
	}	

	public static function filter_update_footer( $text ) {
		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return $text;
		}

		// Main YoRo AI page: remove WordPress version.
		if ( 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return '';
		}

		return $text;
	}
}
