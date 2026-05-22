<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Admin_Page {

	const MENU_SLUG     = 'yoro-ai-core';
	const HISTORY_SLUG  = 'yoro-ai-core-history';
	const POLICIES_SLUG = 'yoro-ai-core-policies';
	const SETTINGS_SLUG = 'yoro-ai-core-settings';

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

		self::$admin_screen_ids[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'History', 'yoro-ai-core' ),
			__( 'History', 'yoro-ai-core' ),
			'manage_woocommerce',
			self::HISTORY_SLUG,
			[ __CLASS__, 'render_history_page' ]
		);

		self::$admin_screen_ids[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Policies', 'yoro-ai-core' ),
			__( 'Policies', 'yoro-ai-core' ),
			'manage_options',
			self::POLICIES_SLUG,
			[ __CLASS__, 'render_policies_page' ]
		);

		self::$admin_screen_ids[] = add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'yoro-ai-core' ),
			__( 'Settings', 'yoro-ai-core' ),
			'manage_options',
			self::SETTINGS_SLUG,
			[ __CLASS__, 'render_settings_page' ]
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
			'yoro-ai_page_' . self::HISTORY_SLUG,
			'yoro-ai_page_' . self::SETTINGS_SLUG,
			'yoro-ai_page_' . self::POLICIES_SLUG,
			self::MENU_SLUG . '_page_' . self::HISTORY_SLUG,
			self::MENU_SLUG . '_page_' . self::SETTINGS_SLUG,
			self::MENU_SLUG . '_page_' . self::POLICIES_SLUG,
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
			'yoro-ai-workflow-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-workflow-renderers.js',
			[],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-email-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-email-ui.js',
			[],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-order-ui',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-order-ui.js',
			[],
			YORO_AI_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-ai-result-renderers',
			YORO_AI_CORE_URL . 'assets/js/yoro-ai-result-renderers.js',
			[],
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

		$order_statuses = [];

		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			$order_statuses = array_map(
				function( $status ) {
					return [
						'slug'  => isset( $status['slug'] ) ? sanitize_key( $status['slug'] ) : '',
						'label' => isset( $status['label'] ) ? sanitize_text_field( $status['label'] ) : '',
					];
				},
				array_values( YoRo_Order_Helper::get_order_status_options() )
			);
		}

		$current_user = wp_get_current_user();
		$user_name    = $current_user instanceof WP_User ? sanitize_text_field( $current_user->display_name ) : '';

		wp_localize_script(
			'yoro-ai-admin',
			'YoRoAIAdmin',
			[
				'debug'         => true,
				'runUrl'        => esc_url_raw( rest_url( 'yoro-ai-core/v1/run' ) ),
				'executeUrl'    => esc_url_raw( rest_url( 'yoro-ai-core/v1/execute' ) ),
				'contextUrl'    => esc_url_raw( rest_url( 'yoro-ai-core/v1/context' ) ),
				'memoryUrl'     => esc_url_raw( rest_url( 'yoro-ai-core/v1/memory' ) ),
				'morningBriefingUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/proactive/morning-briefing' ) ),
				'morningBriefingDismissUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/proactive/morning-briefing-dismiss' ) ),
				'parserTestUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/parser-test' ) ),
				'realQueryPackUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/real-query-pack' ) ),
				'actionsUrl'    => esc_url_raw( rest_url( 'yoro-ai-core/v1/actions' ) ),
				'undoUrl'       => esc_url_raw( rest_url( 'yoro-ai-core/v1/undo' ) ),
				'aiApiStatusUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/status' ) ),
				'aiApiHandshakeUrl' => esc_url_raw( rest_url( 'yoro-ai-core/v1/ai-api/handshake' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'orderStatuses' => $order_statuses,
				'dateFormat'    => get_option( 'date_format', 'Y-m-d' ),
				'timeFormat'    => get_option( 'time_format', 'H:i' ),
				'i18n'          => [
					'emptyCommand' => __( 'Please enter a command.', 'yoro-ai-core' ),
					'running'      => __( 'Thinking...', 'yoro-ai-core' ),
					'askYoRo'      => __( 'Ask YoRo', 'yoro-ai-core' ),
					'confirmRun'   => __( 'This store-changing action requires your confirmation. Run it now?', 'yoro-ai-core' ),
					'morningBriefingIntro' => sprintf(
						/* translators: %s: current user display name. */
						__( 'Good morning %s, here’s your store briefing for today.', 'yoro-ai-core' ),
						'' !== $user_name ? $user_name : __( 'there', 'yoro-ai-core' )
					),
					'error'        => __( 'Something went wrong.', 'yoro-ai-core' ),
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
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'this_week', 'limit' => 1 ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Weekly brief', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Read-only weekly store brief', 'yoro-ai-core' ); ?></span>
								</button>
								<button
									type="button"
									class="yoro-chat-quick-preset"
									data-yoro-quick-action="store.get_business_summary"
									data-yoro-quick-params="<?php echo esc_attr( wp_json_encode( [ 'window' => 'this_week' ] ) ); ?>"
								>
									<span class="yoro-chat-quick-preset-title"><?php esc_html_e( 'Business', 'yoro-ai-core' ); ?></span>
									<span class="yoro-chat-quick-preset-meta"><?php esc_html_e( 'Revenue and order summary', 'yoro-ai-core' ); ?></span>
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
				<div class="yoro-chat-message yoro-chat-message-agent" data-yoro-initial-greeting="1">
					<div class="yoro-chat-avatar" aria-hidden="true"></div>
					<div class="yoro-chat-bubble">
						<strong><?php esc_html_e( 'YoRo', 'yoro-ai-core' ); ?></strong>
						<p><?php echo esc_html( $intro_text ); ?></p>
					</div>
				</div>
			</div>

			<div class="yoro-chat-composer">
				<textarea
					id="yoro-ai-command"
					class="yoro-chat-input"
					rows="2"
					placeholder="<?php esc_attr_e( 'Ask naturally, e.g. Show me the latest 5 orders, or Prepare the next fulfillment follow-up', 'yoro-ai-core' ); ?>"
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
				</div>

				<div class="yoro-ai-examples">
					<strong><?php esc_html_e( 'Try:', 'yoro-ai-core' ); ?></strong>
					<button type="button" class="button-link yoro-ai-example" data-command="Show me the latest 5 orders">
						<?php esc_html_e( 'Show latest 5 orders', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="How is the store doing this week?">
						<?php esc_html_e( 'Store performance this week', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="Brief me this week">
						<?php esc_html_e( 'Open weekly brief', 'yoro-ai-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-ai-example" data-command="Show customer service queue">
						<?php esc_html_e( 'Open customer service queue', 'yoro-ai-core' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_history_page() {

		require_once YORO_AI_CORE_PATH . 'includes/admin/class-yoro-history-table.php';

		$telemetry_summary = self::get_telemetry_summary();
		$real_traffic_eval = self::get_real_traffic_eval_summary();
		$multilingual_eval = self::get_eval_pack_summary( 'multilingual_representative', 8 );
		$trace_view = self::get_ai_trace_view();
		$table = new YoRo_History_Table();
		$table->prepare_items();
			?>

			<div class="wrap yoro-ai-wrap">
				<h1><?php esc_html_e( 'YoRo AI History', 'yoro-ai-core' ); ?></h1>

				<?php self::render_telemetry_summary( $telemetry_summary ); ?>
				<?php self::render_ai_trace_view( $trace_view ); ?>
				<?php if ( ! empty( $real_traffic_eval['available'] ) || ! empty( $multilingual_eval['available'] ) ) : ?>
					<details class="yoro-history-details yoro-history-dev-details">
						<summary><?php esc_html_e( 'Eval and Debug Packs', 'yoro-ai-core' ); ?></summary>
						<div class="yoro-history-dev-body">
							<?php self::render_real_traffic_eval_summary( $real_traffic_eval ); ?>
							<?php self::render_eval_pack_summary( $multilingual_eval, __( 'Multilingual Eval', 'yoro-ai-core' ), __( 'Representative contract pack across language buckets.', 'yoro-ai-core' ) ); ?>
						</div>
					</details>
				<?php endif; ?>

				<form method="post">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::HISTORY_SLUG ); ?>" />
					<input type="hidden" name="s" value="<?php echo esc_attr( $_REQUEST['s'] ?? '' ); ?>">

					<?php
				$table->search_box( __( 'Search logs', 'yoro-ai-core' ), 'yoro-search' );
				$table->display();
				?>
			</form>
		</div>

		<?php
	}

	private static function get_ai_trace_view() {
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		if ( '' === $search || 0 !== strpos( $search, 'yoro_ai_' ) ) {
			return [];
		}

		if ( ! class_exists( 'YoRo_Logger' ) || ! method_exists( 'YoRo_Logger', 'get_logs_by_ai_trace_id' ) ) {
			return [];
		}

		$logs = YoRo_Logger::get_logs_by_ai_trace_id( $search, 25 );

		if ( empty( $logs ) ) {
			return [];
		}

		return [
			'ai_trace_id' => $search,
			'logs'        => $logs,
		];
	}

	private static function render_ai_trace_view( $trace_view ) {
		if ( empty( $trace_view['ai_trace_id'] ) || empty( $trace_view['logs'] ) || ! is_array( $trace_view['logs'] ) ) {
			return;
		}

		$logs = $trace_view['logs'];
		$latest = $logs[0];
		?>
		<div class="yoro-ai-trace-panel">
			<div class="yoro-ai-trace-head">
				<h2><?php esc_html_e( 'AI Trace View', 'yoro-ai-core' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Trace ID:', 'yoro-ai-core' ); ?></strong>
					<code><?php echo esc_html( $trace_view['ai_trace_id'] ); ?></code>
				</p>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: log count */
							__( '%d related log(s) found.', 'yoro-ai-core' ),
							count( $logs )
						)
					);
					?>
				</p>
			</div>
			<div class="yoro-ai-trace-meta">
				<span class="yoro-history-badge yoro-history-badge-feature"><?php echo esc_html( $latest['ai_feature'] ?: 'unknown' ); ?></span>
				<?php if ( ! empty( $latest['ai_model'] ) ) : ?>
					<span class="yoro-history-badge yoro-history-badge-model"><?php echo esc_html( $latest['ai_model'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $latest['prompt_version'] ) ) : ?>
					<span class="yoro-history-badge yoro-history-badge-prompt"><?php echo esc_html( $latest['prompt_version'] ); ?></span>
				<?php endif; ?>
			</div>
			<div class="yoro-ai-trace-list">
				<?php foreach ( $logs as $log ) : ?>
					<div class="yoro-ai-trace-item">
						<div class="yoro-ai-trace-row">
							<strong><?php echo esc_html( $log['created_at'] ); ?></strong>
							<span class="yoro-history-status yoro-history-status-<?php echo esc_attr( $log['status'] ); ?>">
								<?php echo esc_html( $log['status'] ); ?>
							</span>
							<?php if ( ! empty( $log['confidence'] ) || 0.0 === $log['confidence'] ) : ?>
								<span><?php echo esc_html( number_format_i18n( (float) $log['confidence'] * 100, 0 ) ); ?>%</span>
							<?php endif; ?>
						</div>
						<div class="yoro-ai-trace-row">
							<code><?php echo esc_html( $log['command'] ); ?></code>
						</div>
						<div class="yoro-ai-trace-row">
							<span><strong><?php esc_html_e( 'Action:', 'yoro-ai-core' ); ?></strong> <code><?php echo esc_html( $log['action'] ?: '—' ); ?></code></span>
							<span><strong><?php esc_html_e( 'Router source:', 'yoro-ai-core' ); ?></strong> <code><?php echo esc_html( $log['parser_source'] ?: '—' ); ?></code></span>
							<span><strong><?php esc_html_e( 'Log Trace:', 'yoro-ai-core' ); ?></strong> <code><?php echo esc_html( $log['trace_id'] ?: '—' ); ?></code></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function get_telemetry_summary() {
		global $wpdb;

		$table = YoRo_DB::logs_table_name();

		$columns = $wpdb->get_col( "DESC {$table}", 0 );

		if ( ! is_array( $columns ) || ! in_array( 'telemetry', $columns, true ) ) {
			return [
				'available' => false,
			];
		}

		$rows = $wpdb->get_results(
			"SELECT action, status, telemetry, created_at FROM {$table} ORDER BY id DESC LIMIT 200",
			ARRAY_A
		);

		$summary = [
			'available'             => true,
			'total'                 => 0,
			'executed'              => 0,
			'preview'               => 0,
			'confirm_required'      => 0,
			'error'                 => 0,
			'undoable'              => 0,
			'parser_sources'        => [],
			'ai_features'          => [],
			'ai_models'            => [],
			'prompt_versions'      => [],
			'ai_providers'         => [],
			'intents'               => [],
			'intent_families'      => [],
			'languages'            => [],
			'task_ai_primary_languages' => [],
			'task_fallback_languages'   => [],
			'task_clarify_languages'    => [],
			'top_actions'           => [],
			'top_errors'            => [],
			'resolution_paths'      => [],
			'task_resolution_paths' => [],
			'clarify_reasons'       => [],
			'fallback_reasons'      => [],
			'task_fallback_reasons' => [],
			'task_fallback_actions' => [],
			'task_unclassified_actions' => [],
			'inferred_resolution_paths' => 0,
			'inferred_fallback_reasons' => 0,
			'top_trimmed_actions'   => [],
			'avg_duration_ms'       => 0,
			'confirmation_rate'     => 0,
			'error_rate'            => 0,
			'undoable_rate'         => 0,
			'ai_primary_route_rate' => 0,
			'fallback_rate'         => 0,
			'ai_task_route_rate'    => 0,
			'task_fallback_rate'    => 0,
			'task_unclassified_rate' => 0,
			'clarification_rate'    => 0,
			'trimmed_param_rate'    => 0,
			'avg_catalog_action_count' => 0,
			'ai_primary_count'      => 0,
			'fallback_count'        => 0,
			'task_total'            => 0,
			'task_ai_primary_count' => 0,
			'task_fallback_count'   => 0,
			'task_unclassified_count' => 0,
			'clarification_count'   => 0,
			'trimmed_param_count'   => 0,
			'duration_sample_count' => 0,
			'catalog_action_count_total' => 0,
			'catalog_action_count_samples' => 0,
			'dominant_task_fallback_reason' => '',
			'dominant_task_fallback_count'  => 0,
			'dominant_task_fallback_share'  => 0,
			'dominant_task_fallback_action' => '',
			'router_health_insight'         => [],
			'ai_catalog_audit'      => [
				'available'          => false,
				'total'              => 0,
				'passed'             => 0,
				'failed'             => 0,
				'missing_fields'     => [],
				'failing_actions'    => [],
			],
		];

		$total_duration = 0;

		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$summary['total']++;

			$status = sanitize_key( $row['status'] ?? '' );

			if ( isset( $summary[ $status ] ) ) {
				$summary[ $status ]++;
			}

			$action = sanitize_text_field( $row['action'] ?? '' );

			if ( $action ) {
				if ( ! isset( $summary['top_actions'][ $action ] ) ) {
					$summary['top_actions'][ $action ] = 0;
				}

				$summary['top_actions'][ $action ]++;
			}

			$telemetry = [];

			if ( ! empty( $row['telemetry'] ) ) {
				$decoded = json_decode( $row['telemetry'], true );

				if ( is_array( $decoded ) ) {
					$telemetry = $decoded;
				}
			}

			$parser_source = sanitize_key( $telemetry['parser_source'] ?? '' );
			$intent_name   = sanitize_key( $telemetry['intent_name'] ?? '' );
			$intent_family = sanitize_key( $telemetry['intent_family'] ?? '' );
			$language      = sanitize_key( $telemetry['language'] ?? '' );
			$ai_feature    = sanitize_key( $telemetry['ai_feature'] ?? '' );
			$ai_model      = sanitize_text_field( $telemetry['ai_model'] ?? '' );
			$prompt_version = sanitize_text_field( $telemetry['prompt_version'] ?? '' );
			$ai_provider   = sanitize_key( $telemetry['ai_provider'] ?? '' );
			$resolution_path = sanitize_key( $telemetry['resolution_path'] ?? '' );
			$clarify_reason = sanitize_key( $telemetry['clarify_reason'] ?? '' );
			$fallback_reason = sanitize_key( $telemetry['fallback_reason'] ?? '' );
			$params_trimmed_count = absint( $telemetry['params_trimmed_count'] ?? 0 );
			$catalog_action_count = absint( $telemetry['catalog_action_count'] ?? 0 );
			$result_type = sanitize_key( $telemetry['result_type'] ?? '' );

			if ( '' === $resolution_path ) {
				$inferred_resolution_path = self::infer_resolution_path_from_telemetry( $telemetry, $action, $status );

				if ( '' !== $inferred_resolution_path ) {
					$resolution_path = $inferred_resolution_path;
					$summary['inferred_resolution_paths']++;
				}
			}

			if ( '' === $fallback_reason ) {
				$inferred_fallback_reason = self::infer_fallback_reason_from_telemetry( $telemetry, $action, $status, $resolution_path );

				if ( '' !== $inferred_fallback_reason ) {
					$fallback_reason = $inferred_fallback_reason;
					$summary['inferred_fallback_reasons']++;
				}
			}

			if ( $parser_source ) {
				if ( ! isset( $summary['parser_sources'][ $parser_source ] ) ) {
					$summary['parser_sources'][ $parser_source ] = 0;
				}

				$summary['parser_sources'][ $parser_source ]++;
			}

			if ( $ai_feature ) {
				if ( ! isset( $summary['ai_features'][ $ai_feature ] ) ) {
					$summary['ai_features'][ $ai_feature ] = 0;
				}

				$summary['ai_features'][ $ai_feature ]++;
			}

			if ( $ai_model ) {
				if ( ! isset( $summary['ai_models'][ $ai_model ] ) ) {
					$summary['ai_models'][ $ai_model ] = 0;
				}

				$summary['ai_models'][ $ai_model ]++;
			}

			if ( $prompt_version ) {
				if ( ! isset( $summary['prompt_versions'][ $prompt_version ] ) ) {
					$summary['prompt_versions'][ $prompt_version ] = 0;
				}

				$summary['prompt_versions'][ $prompt_version ]++;
			}

			if ( $ai_provider ) {
				if ( ! isset( $summary['ai_providers'][ $ai_provider ] ) ) {
					$summary['ai_providers'][ $ai_provider ] = 0;
				}

				$summary['ai_providers'][ $ai_provider ]++;
			}

			if ( $intent_name ) {
				if ( ! isset( $summary['intents'][ $intent_name ] ) ) {
					$summary['intents'][ $intent_name ] = 0;
				}

				$summary['intents'][ $intent_name ]++;
			}

			if ( $intent_family ) {
				if ( ! isset( $summary['intent_families'][ $intent_family ] ) ) {
					$summary['intent_families'][ $intent_family ] = 0;
				}

				$summary['intent_families'][ $intent_family ]++;
			}

			if ( $language ) {
				if ( ! isset( $summary['languages'][ $language ] ) ) {
					$summary['languages'][ $language ] = 0;
				}

				$summary['languages'][ $language ]++;
			}

			if ( $resolution_path ) {
				if ( ! isset( $summary['resolution_paths'][ $resolution_path ] ) ) {
					$summary['resolution_paths'][ $resolution_path ] = 0;
				}

				$summary['resolution_paths'][ $resolution_path ]++;

				if ( 'ai_primary' === $resolution_path ) {
					$summary['ai_primary_count']++;
				} else {
					$summary['fallback_count']++;
				}
			}

			$is_task_like = ( $action || $intent_name ) && 'assistant_message' !== $result_type;

			if ( $is_task_like ) {
				$summary['task_total']++;

				if ( $resolution_path ) {
					if ( ! isset( $summary['task_resolution_paths'][ $resolution_path ] ) ) {
						$summary['task_resolution_paths'][ $resolution_path ] = 0;
					}

					$summary['task_resolution_paths'][ $resolution_path ]++;
				}

				if ( 'ai_primary' === $resolution_path ) {
					$summary['task_ai_primary_count']++;

					if ( $language ) {
						if ( ! isset( $summary['task_ai_primary_languages'][ $language ] ) ) {
							$summary['task_ai_primary_languages'][ $language ] = 0;
						}

						$summary['task_ai_primary_languages'][ $language ]++;
					}
				} elseif ( $resolution_path ) {
					$summary['task_fallback_count']++;

					if ( $language ) {
						if ( ! isset( $summary['task_fallback_languages'][ $language ] ) ) {
							$summary['task_fallback_languages'][ $language ] = 0;
						}

						$summary['task_fallback_languages'][ $language ]++;
					}
				} else {
					$summary['task_unclassified_count']++;

					$action_key = $action ? $action : 'unknown_action';

					if ( ! isset( $summary['task_unclassified_actions'][ $action_key ] ) ) {
						$summary['task_unclassified_actions'][ $action_key ] = 0;
					}

					$summary['task_unclassified_actions'][ $action_key ]++;
				}
			}

			if ( $clarify_reason ) {
				if ( ! isset( $summary['clarify_reasons'][ $clarify_reason ] ) ) {
					$summary['clarify_reasons'][ $clarify_reason ] = 0;
				}

				$summary['clarify_reasons'][ $clarify_reason ]++;
			}

			if ( $fallback_reason ) {
				if ( ! isset( $summary['fallback_reasons'][ $fallback_reason ] ) ) {
					$summary['fallback_reasons'][ $fallback_reason ] = 0;
				}

				$summary['fallback_reasons'][ $fallback_reason ]++;

				if ( $is_task_like ) {
					if ( ! isset( $summary['task_fallback_reasons'][ $fallback_reason ] ) ) {
						$summary['task_fallback_reasons'][ $fallback_reason ] = 0;
					}

					$summary['task_fallback_reasons'][ $fallback_reason ]++;

					if ( ! isset( $summary['task_fallback_actions'][ $fallback_reason ] ) ) {
						$summary['task_fallback_actions'][ $fallback_reason ] = [];
					}

					$action_key = $action ? $action : 'unknown_action';

					if ( ! isset( $summary['task_fallback_actions'][ $fallback_reason ][ $action_key ] ) ) {
						$summary['task_fallback_actions'][ $fallback_reason ][ $action_key ] = 0;
					}

					$summary['task_fallback_actions'][ $fallback_reason ][ $action_key ]++;
				}
			}

			if ( 'clarify' === $status || 'clarify' === sanitize_key( $telemetry['result_type'] ?? '' ) ) {
				$summary['clarification_count']++;

				if ( $is_task_like && $language ) {
					if ( ! isset( $summary['task_clarify_languages'][ $language ] ) ) {
						$summary['task_clarify_languages'][ $language ] = 0;
					}

					$summary['task_clarify_languages'][ $language ]++;
				}
			}

			if ( $params_trimmed_count > 0 ) {
				$summary['trimmed_param_count']++;

				if ( $action ) {
					if ( ! isset( $summary['top_trimmed_actions'][ $action ] ) ) {
						$summary['top_trimmed_actions'][ $action ] = 0;
					}

					$summary['top_trimmed_actions'][ $action ] += $params_trimmed_count;
				}
			}

			if ( $catalog_action_count > 0 ) {
				$summary['catalog_action_count_total'] += $catalog_action_count;
				$summary['catalog_action_count_samples']++;
			}

			if ( ! empty( $telemetry['undoable'] ) ) {
				$summary['undoable']++;
			}

			$error_code = sanitize_key( $telemetry['error_code'] ?? '' );

			if ( ! $error_code && 'error' === $status ) {
				$error_code = 'unknown_error';
			}

			if ( $error_code ) {
				if ( ! isset( $summary['top_errors'][ $error_code ] ) ) {
					$summary['top_errors'][ $error_code ] = 0;
				}

				$summary['top_errors'][ $error_code ]++;
			}

			if ( isset( $telemetry['duration_ms'] ) && is_numeric( $telemetry['duration_ms'] ) ) {
				$total_duration += max( 0, (int) $telemetry['duration_ms'] );
				$summary['duration_sample_count']++;
			}
		}

		arsort( $summary['parser_sources'] );
		arsort( $summary['ai_features'] );
		arsort( $summary['ai_models'] );
		arsort( $summary['prompt_versions'] );
		arsort( $summary['ai_providers'] );
		arsort( $summary['intents'] );
		arsort( $summary['intent_families'] );
		arsort( $summary['languages'] );
		arsort( $summary['task_ai_primary_languages'] );
		arsort( $summary['task_fallback_languages'] );
		arsort( $summary['task_clarify_languages'] );
		arsort( $summary['top_actions'] );
		arsort( $summary['top_errors'] );
		arsort( $summary['resolution_paths'] );
		arsort( $summary['task_resolution_paths'] );
		arsort( $summary['clarify_reasons'] );
		arsort( $summary['fallback_reasons'] );
		arsort( $summary['task_fallback_reasons'] );
		arsort( $summary['task_unclassified_actions'] );
		arsort( $summary['top_trimmed_actions'] );

		$summary['parser_sources'] = array_slice( $summary['parser_sources'], 0, 6, true );
		$summary['ai_features']    = array_slice( $summary['ai_features'], 0, 6, true );
		$summary['ai_models']      = array_slice( $summary['ai_models'], 0, 6, true );
		$summary['prompt_versions'] = array_slice( $summary['prompt_versions'], 0, 6, true );
		$summary['ai_providers']   = array_slice( $summary['ai_providers'], 0, 6, true );
		$summary['intents']        = array_slice( $summary['intents'], 0, 6, true );
		$summary['intent_families'] = array_slice( $summary['intent_families'], 0, 6, true );
		$summary['languages']      = array_slice( $summary['languages'], 0, 6, true );
		$summary['task_ai_primary_languages'] = array_slice( $summary['task_ai_primary_languages'], 0, 6, true );
		$summary['task_fallback_languages'] = array_slice( $summary['task_fallback_languages'], 0, 6, true );
		$summary['task_clarify_languages'] = array_slice( $summary['task_clarify_languages'], 0, 6, true );
		$summary['top_actions']    = array_slice( $summary['top_actions'], 0, 6, true );
		$summary['top_errors']     = array_slice( $summary['top_errors'], 0, 6, true );
		$summary['resolution_paths'] = array_slice( $summary['resolution_paths'], 0, 6, true );
		$summary['task_resolution_paths'] = array_slice( $summary['task_resolution_paths'], 0, 6, true );
		$summary['clarify_reasons'] = array_slice( $summary['clarify_reasons'], 0, 6, true );
		$summary['fallback_reasons'] = array_slice( $summary['fallback_reasons'], 0, 6, true );
		$summary['task_fallback_reasons'] = array_slice( $summary['task_fallback_reasons'], 0, 6, true );
		$summary['task_unclassified_actions'] = array_slice( $summary['task_unclassified_actions'], 0, 6, true );
		$summary['top_trimmed_actions'] = array_slice( $summary['top_trimmed_actions'], 0, 6, true );

		foreach ( $summary['task_fallback_actions'] as $fallback_reason => $actions ) {
			if ( ! is_array( $actions ) ) {
				unset( $summary['task_fallback_actions'][ $fallback_reason ] );
				continue;
			}

			arsort( $actions );
			$summary['task_fallback_actions'][ $fallback_reason ] = array_slice( $actions, 0, 3, true );
		}

		if ( $summary['duration_sample_count'] > 0 ) {
			$summary['avg_duration_ms'] = (int) round( $total_duration / $summary['duration_sample_count'] );
		}

		if ( $summary['catalog_action_count_samples'] > 0 ) {
			$summary['avg_catalog_action_count'] = (int) round( $summary['catalog_action_count_total'] / $summary['catalog_action_count_samples'] );
		}

		if ( $summary['total'] > 0 ) {
			$summary['confirmation_rate'] = round( ( $summary['confirm_required'] / $summary['total'] ) * 100, 1 );
			$summary['error_rate']        = round( ( $summary['error'] / $summary['total'] ) * 100, 1 );
			$summary['undoable_rate']     = round( ( $summary['undoable'] / $summary['total'] ) * 100, 1 );
			$summary['ai_primary_route_rate'] = round( ( $summary['ai_primary_count'] / $summary['total'] ) * 100, 1 );
			$summary['fallback_rate']         = round( ( $summary['fallback_count'] / $summary['total'] ) * 100, 1 );
			$summary['clarification_rate']    = round( ( $summary['clarification_count'] / $summary['total'] ) * 100, 1 );
			$summary['trimmed_param_rate']    = round( ( $summary['trimmed_param_count'] / $summary['total'] ) * 100, 1 );
		}

		if ( $summary['task_total'] > 0 ) {
			$summary['ai_task_route_rate'] = round( ( $summary['task_ai_primary_count'] / $summary['task_total'] ) * 100, 1 );
			$summary['task_fallback_rate'] = round( ( $summary['task_fallback_count'] / $summary['task_total'] ) * 100, 1 );
			$summary['task_unclassified_rate'] = round( ( $summary['task_unclassified_count'] / $summary['task_total'] ) * 100, 1 );
		}

		if ( ! empty( $summary['task_fallback_reasons'] ) ) {
			$fallback_reasons = $summary['task_fallback_reasons'];
			$dominant_reason = array_key_first( $fallback_reasons );
			$dominant_count  = absint( $fallback_reasons[ $dominant_reason ] ?? 0 );

			$summary['dominant_task_fallback_reason'] = sanitize_key( (string) $dominant_reason );
			$summary['dominant_task_fallback_count']  = $dominant_count;
			$summary['dominant_task_fallback_share']  = $summary['task_fallback_count'] > 0
				? round( ( $dominant_count / $summary['task_fallback_count'] ) * 100, 1 )
				: 0;

			$dominant_actions = $summary['task_fallback_actions'][ $dominant_reason ] ?? [];

			if ( is_array( $dominant_actions ) && ! empty( $dominant_actions ) ) {
				$summary['dominant_task_fallback_action'] = sanitize_text_field( (string) array_key_first( $dominant_actions ) );
			}
		}

		$summary['router_health_insight'] = self::build_router_health_insight( $summary );

		if ( class_exists( 'YoRo_Action_Catalog_Builder' ) && method_exists( 'YoRo_Action_Catalog_Builder', 'build_ai_metadata_audit' ) ) {
			$audit_rows = YoRo_Action_Catalog_Builder::build_ai_metadata_audit();
			$summary['ai_catalog_audit']['available'] = true;

			foreach ( $audit_rows as $action_name => $row ) {
				$summary['ai_catalog_audit']['total']++;

				if ( ! empty( $row['passed'] ) ) {
					$summary['ai_catalog_audit']['passed']++;
					continue;
				}

				$summary['ai_catalog_audit']['failed']++;
				$missing_fields = isset( $row['missing_fields'] ) && is_array( $row['missing_fields'] )
					? $row['missing_fields']
					: [];

				foreach ( $missing_fields as $field ) {
					$field = sanitize_key( $field );

					if ( '' === $field ) {
						continue;
					}

					if ( ! isset( $summary['ai_catalog_audit']['missing_fields'][ $field ] ) ) {
						$summary['ai_catalog_audit']['missing_fields'][ $field ] = 0;
					}

					$summary['ai_catalog_audit']['missing_fields'][ $field ]++;
				}

				$summary['ai_catalog_audit']['failing_actions'][ $action_name ] = implode( ', ', array_map( 'sanitize_key', $missing_fields ) );
			}

			arsort( $summary['ai_catalog_audit']['missing_fields'] );
			$summary['ai_catalog_audit']['missing_fields'] = array_slice( $summary['ai_catalog_audit']['missing_fields'], 0, 8, true );
			$summary['ai_catalog_audit']['failing_actions'] = array_slice( $summary['ai_catalog_audit']['failing_actions'], 0, 12, true );
		}

		return $summary;
	}

	private static function render_telemetry_summary( $summary ) {
		if ( empty( $summary['available'] ) ) {
			return;
		}

		$catalog_total   = absint( $summary['ai_catalog_audit']['total'] ?? 0 );
		$catalog_failed  = absint( $summary['ai_catalog_audit']['failed'] ?? 0 );

		?>
		<div class="yoro-telemetry-summary yoro-history-summary">
			<?php self::render_router_health_insight( $summary['router_health_insight'] ?? [] ); ?>
				<div class="yoro-history-metric-grid">
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'AI handled', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['ai_task_route_rate'] ?? 0 ), 1 ) ); ?>%</strong>
					</div>
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'Guard or recovery', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['task_fallback_rate'] ?? 0 ), 1 ) ); ?>%</strong>
					</div>
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'Recent tasks', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( absint( $summary['task_total'] ?? 0 ) ) ); ?></strong>
					</div>
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'Classified routes', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( 100 - (float) ( $summary['task_unclassified_rate'] ?? 0 ), 1 ) ); ?>%</strong>
					</div>
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'Asked for clarity', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['clarification_rate'] ?? 0 ), 1 ) ); ?>%</strong>
					</div>
					<div class="yoro-telemetry-metric">
						<span><?php esc_html_e( 'Error rate', 'yoro-ai-core' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['error_rate'] ?? 0 ), 1 ) ); ?>%</strong>
					</div>
				</div>

				<details class="yoro-history-details">
					<summary><?php esc_html_e( 'Routing and Quality Details', 'yoro-ai-core' ); ?></summary>
					<div class="yoro-history-section-grid yoro-history-details-section-grid">
						<section class="yoro-history-section-card">
							<div class="yoro-history-section-head">
								<h3><?php esc_html_e( 'Routing Focus', 'yoro-ai-core' ); ?></h3>
								<p><?php esc_html_e( 'Use this block to decide the next hardening target.', 'yoro-ai-core' ); ?></p>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Task resolution paths', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['task_resolution_paths'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Task fallback reasons', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['task_fallback_reasons'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Task fallback actions', 'yoro-ai-core' ); ?></span>
								<?php self::render_task_fallback_actions( $summary['task_fallback_actions'] ?? [] ); ?>
							</div>
						</section>

						<section class="yoro-history-section-card">
							<div class="yoro-history-section-head">
								<h3><?php esc_html_e( 'Quality Signals', 'yoro-ai-core' ); ?></h3>
								<p><?php esc_html_e( 'These are the fastest indicators of route quality regressions.', 'yoro-ai-core' ); ?></p>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Top clarify reasons', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['clarify_reasons'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Top trimmed actions', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['top_trimmed_actions'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Top errors', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['top_errors'] ?? [] ); ?>
							</div>
						</section>

						<section class="yoro-history-section-card">
							<div class="yoro-history-section-head">
								<h3><?php esc_html_e( 'Coverage', 'yoro-ai-core' ); ?></h3>
								<p><?php esc_html_e( 'Check whether the sample is trustworthy before acting on it.', 'yoro-ai-core' ); ?></p>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Task AI by language', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['task_ai_primary_languages'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Task fallback by language', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['task_fallback_languages'] ?? [] ); ?>
							</div>
							<div class="yoro-telemetry-list">
								<span><?php esc_html_e( 'Observed languages', 'yoro-ai-core' ); ?></span>
								<?php self::render_compact_counts( $summary['languages'] ?? [] ); ?>
							</div>
							<?php if ( ! empty( $summary['ai_catalog_audit']['available'] ) ) : ?>
								<div class="yoro-telemetry-list">
									<span><?php esc_html_e( 'AI catalog audit', 'yoro-ai-core' ); ?></span>
									<?php self::render_compact_counts( [ 'actions' => $catalog_total, 'failing' => $catalog_failed ] ); ?>
								</div>
							<?php endif; ?>
						</section>
					</div>
				</details>

			<details class="yoro-history-details">
				<summary><?php esc_html_e( 'Advanced Diagnostics', 'yoro-ai-core' ); ?></summary>
				<div class="yoro-history-details-grid">
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Router sources', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['parser_sources'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Resolution paths', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['resolution_paths'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Top fallback reasons', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['fallback_reasons'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'AI models', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['ai_models'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Prompt versions', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['prompt_versions'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'AI providers', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['ai_providers'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Top intents', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['intents'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Top actions', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['top_actions'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'AI features', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $summary['ai_features'] ?? [] ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Operational metrics', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( [
							'logs' => absint( $summary['total'] ?? 0 ),
							'confirm_rate' => number_format_i18n( (float) ( $summary['confirmation_rate'] ?? 0 ), 1 ) . '%',
							'undoable_rate' => number_format_i18n( (float) ( $summary['undoable_rate'] ?? 0 ), 1 ) . '%',
							'avg_duration_ms' => absint( $summary['avg_duration_ms'] ?? 0 ),
							'avg_catalog_actions' => absint( $summary['avg_catalog_action_count'] ?? 0 ),
						] ); ?>
					</div>
					<?php if ( ! empty( $summary['ai_catalog_audit']['available'] ) ) : ?>
						<div class="yoro-telemetry-list">
							<span><?php esc_html_e( 'Missing AI metadata fields', 'yoro-ai-core' ); ?></span>
							<?php self::render_compact_counts( $summary['ai_catalog_audit']['missing_fields'] ?? [] ); ?>
						</div>
						<div class="yoro-telemetry-list">
							<span><?php esc_html_e( 'Actions failing AI audit', 'yoro-ai-core' ); ?></span>
							<?php self::render_compact_counts( $summary['ai_catalog_audit']['failing_actions'] ?? [] ); ?>
						</div>
					<?php endif; ?>
				</div>
			</details>
		</div>
		<?php
	}

	private static function get_real_traffic_eval_summary() {
		return self::get_eval_pack_summary( 'real_traffic', 6 );
	}

	private static function get_eval_pack_summary( $pack = 'real_traffic', $failed_limit = 6 ) {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return [
				'available' => false,
			];
		}

		if ( ! class_exists( 'YoRo_Parser_Test_Suite' ) || ! method_exists( 'YoRo_Parser_Test_Suite', 'get_evaluation_pack_snapshot' ) ) {
			return [
				'available' => false,
			];
		}

		$pack = sanitize_key( (string) $pack );
		$failed_limit = absint( $failed_limit );
		$cache_key = 'yoro_eval_pack_summary_' . $pack . '_v2';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$snapshot = YoRo_Parser_Test_Suite::get_evaluation_pack_snapshot( $pack, $failed_limit );
		$summary  = isset( $snapshot['summary'] ) && is_array( $snapshot['summary'] ) ? $snapshot['summary'] : [];

		$data = [
			'available'         => true,
			'pack'              => sanitize_key( $snapshot['pack'] ?? $pack ),
			'total'             => absint( $summary['total'] ?? 0 ),
			'passed'            => absint( $summary['passed'] ?? 0 ),
			'failed'            => absint( $summary['failed'] ?? 0 ),
			'clarify_rate'      => isset( $summary['clarify_rate'] ) ? (float) $summary['clarify_rate'] : 0,
			'wrong_action_rate' => isset( $summary['wrong_action_rate'] ) ? (float) $summary['wrong_action_rate'] : 0,
			'wrong_intent_rate' => isset( $summary['wrong_intent_rate'] ) ? (float) $summary['wrong_intent_rate'] : 0,
			'by_domain'         => isset( $summary['by_domain'] ) && is_array( $summary['by_domain'] ) ? $summary['by_domain'] : [],
			'by_family'         => isset( $summary['by_family'] ) && is_array( $summary['by_family'] ) ? $summary['by_family'] : [],
			'by_language'       => isset( $summary['by_language'] ) && is_array( $summary['by_language'] ) ? $summary['by_language'] : [],
			'by_source'         => isset( $summary['by_source'] ) && is_array( $summary['by_source'] ) ? $summary['by_source'] : [],
			'by_outcome'        => isset( $summary['by_outcome'] ) && is_array( $summary['by_outcome'] ) ? $summary['by_outcome'] : [],
			'failed_cases'      => isset( $snapshot['failed'] ) && is_array( $snapshot['failed'] ) ? $snapshot['failed'] : [],
		];

		set_transient( $cache_key, $data, 5 * MINUTE_IN_SECONDS );

		return $data;
	}

	private static function render_real_traffic_eval_summary( $summary ) {
		self::render_eval_pack_summary(
			$summary,
			__( 'Real Traffic Eval', 'yoro-ai-core' ),
			__( 'This pack is the repeatable regression baseline for traffic-like commands.', 'yoro-ai-core' )
		);
	}

	private static function render_eval_pack_summary( $summary, $title, $description ) {
		if ( empty( $summary['available'] ) ) {
			return;
		}

		$by_domain = self::flatten_eval_bucket_counts( $summary['by_domain'] ?? [] );
		$by_family = self::flatten_eval_bucket_counts( $summary['by_family'] ?? [] );
		$by_language = self::flatten_eval_bucket_counts( $summary['by_language'] ?? [] );
		$by_source = self::flatten_eval_bucket_counts( $summary['by_source'] ?? [] );
		$by_outcome = self::flatten_eval_bucket_counts( $summary['by_outcome'] ?? [] );
		?>
		<div class="yoro-telemetry-summary yoro-history-summary">
			<div class="yoro-history-section-head">
				<h3><?php echo esc_html( $title ); ?></h3>
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<div class="yoro-history-metric-grid">
				<div class="yoro-telemetry-metric">
					<span><?php esc_html_e( 'Pass rate', 'yoro-ai-core' ); ?></span>
					<strong><?php echo esc_html( (string) absint( $summary['passed'] ?? 0 ) ); ?>/<?php echo esc_html( (string) absint( $summary['total'] ?? 0 ) ); ?></strong>
				</div>
				<div class="yoro-telemetry-metric">
					<span><?php esc_html_e( 'Failed', 'yoro-ai-core' ); ?></span>
					<strong><?php echo esc_html( (string) absint( $summary['failed'] ?? 0 ) ); ?></strong>
				</div>
				<div class="yoro-telemetry-metric">
					<span><?php esc_html_e( 'Clarify rate', 'yoro-ai-core' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['clarify_rate'] ?? 0 ), 1 ) ); ?>%</strong>
				</div>
				<div class="yoro-telemetry-metric">
					<span><?php esc_html_e( 'Wrong action', 'yoro-ai-core' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['wrong_action_rate'] ?? 0 ), 1 ) ); ?>%</strong>
				</div>
				<div class="yoro-telemetry-metric">
					<span><?php esc_html_e( 'Wrong intent', 'yoro-ai-core' ); ?></span>
					<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['wrong_intent_rate'] ?? 0 ), 1 ) ); ?>%</strong>
				</div>
			</div>
			<div class="yoro-history-section-grid">
				<section class="yoro-history-section-card">
					<div class="yoro-history-section-head">
						<h3><?php esc_html_e( 'Coverage', 'yoro-ai-core' ); ?></h3>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Eval by domain', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $by_domain ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Eval by family', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $by_family ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Eval by language', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $by_language ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Eval by source', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $by_source ); ?>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Eval outcomes', 'yoro-ai-core' ); ?></span>
						<?php self::render_compact_counts( $by_outcome ); ?>
					</div>
				</section>
				<section class="yoro-history-section-card">
					<div class="yoro-history-section-head">
						<h3><?php esc_html_e( 'Failures', 'yoro-ai-core' ); ?></h3>
					</div>
					<div class="yoro-telemetry-list">
						<span><?php esc_html_e( 'Failed eval cases', 'yoro-ai-core' ); ?></span>
						<?php self::render_eval_case_list( $summary['failed_cases'] ?? [] ); ?>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	private static function flatten_eval_bucket_counts( $items ) {
		$flattened = [];

		foreach ( (array) $items as $label => $row ) {
			$label = sanitize_key( (string) $label );

			if ( '' === $label || ! is_array( $row ) ) {
				continue;
			}

			$flattened[ $label ] = absint( $row['passed'] ?? 0 ) . '/' . absint( $row['total'] ?? 0 );
		}

		return $flattened;
	}

	private static function render_eval_case_list( $cases ) {
		if ( empty( $cases ) || ! is_array( $cases ) ) {
			echo '<em>' . esc_html__( 'No failures', 'yoro-ai-core' ) . '</em>';
			return;
		}

		echo '<ul>';

		foreach ( $cases as $case ) {
			if ( ! is_array( $case ) ) {
				continue;
			}

			$name = sanitize_text_field( $case['name'] ?? '' );
			$outcome = sanitize_key( $case['outcome'] ?? 'unknown' );

			if ( '' === $name ) {
				continue;
			}

			echo '<li><strong>' . esc_html( $name ) . '</strong> <code>' . esc_html( $outcome ) . '</code></li>';
		}

		echo '</ul>';
	}

	private static function infer_resolution_path_from_telemetry( $telemetry, $action, $status ) {
		$telemetry = is_array( $telemetry ) ? $telemetry : [];
		$action = sanitize_text_field( (string) $action );
		$status = sanitize_key( (string) $status );
		$parser_source = sanitize_key( $telemetry['parser_source'] ?? '' );
		$fallback_reason = sanitize_key( $telemetry['fallback_reason'] ?? '' );
		$result_type = sanitize_key( $telemetry['result_type'] ?? '' );
		$parser_rule = sanitize_text_field( $telemetry['parser_rule'] ?? '' );

		if ( 'ai_router' === $parser_source ) {
			return 'ai_primary';
		}

		if ( 'assistant_message' === $result_type ) {
			return 'safety_guard';
		}

		if (
			in_array(
				$fallback_reason,
				[
					'active_workflow',
					'current_email_delivery',
					'explicit_refund_guard',
					'explicit_verification_guard',
					'guided_followup_guard',
					'contextual_order_write_guard',
					'followup_reference_guard',
					'action_composition_guard',
				],
				true
			)
		) {
			return 'deterministic_override';
		}

		if (
			in_array(
				$fallback_reason,
				[
					'pre_router_composition',
					'command_shape_order_search',
					'command_shape_explicit_order_write',
					'command_shape_write_missing_target',
					'command_shape_query_action',
					'action_composition',
					'workflow_candidate',
					'semantic_read_only_fallback',
				],
				true
			)
		) {
			return 'recovery_fallback';
		}

		if (
			in_array(
				$fallback_reason,
				[
					'conversation_route',
					'hardening_guard',
					'ambiguous_command',
					'generic_clarification',
					'unsupported_request',
					'non_task_mode',
					'developer_handoff_without_context',
				],
				true
			)
		) {
			return 'safety_guard';
		}

		if ( 0 === strpos( $parser_rule, 'rule.chat.' ) || 0 === strpos( $parser_rule, 'rule.greeting.' ) ) {
			return 'safety_guard';
		}

		if ( in_array( $action, [ 'orders.get_details', 'orders.get_customer_email_drafts' ], true ) ) {
			return 'deterministic_override';
		}

		if ( 'clarify' === $status || 'confirm_required' === $status ) {
			return 'safety_guard';
		}

		if ( $action || $parser_source ) {
			return 'recovery_fallback';
		}

		return '';
	}

	private static function infer_fallback_reason_from_telemetry( $telemetry, $action, $status, $resolution_path = '' ) {
		$telemetry = is_array( $telemetry ) ? $telemetry : [];
		$action = sanitize_text_field( (string) $action );
		$status = sanitize_key( (string) $status );
		$resolution_path = sanitize_key( (string) $resolution_path );
		$parser_rule = sanitize_text_field( $telemetry['parser_rule'] ?? '' );
		$parser_source = sanitize_key( $telemetry['parser_source'] ?? '' );
		$result_type = sanitize_key( $telemetry['result_type'] ?? '' );

		if ( 'ai_router' === $parser_source ) {
			if ( 'assistant_message' === $result_type ) {
				return 'non_task_mode';
			}

			return '';
		}

		if ( 'shape.query_action' === $parser_rule ) {
			return 'command_shape_query_action';
		}

		if ( in_array( $parser_rule, [ 'rule.action_composition', 'guard.action_composition' ], true ) ) {
			return 'action_composition';
		}

		if ( 0 === strpos( $parser_rule, 'rule.workflow_template.' ) || 0 === strpos( $parser_rule, 'guard.workflow_template.' ) ) {
			return 'workflow_candidate';
		}

		if ( 0 === strpos( $parser_rule, 'rule.chat.' ) || 0 === strpos( $parser_rule, 'rule.greeting.' ) ) {
			return 'conversation_route';
		}

		if ( in_array( $action, [ 'orders.get_details', 'orders.get_customer_email_drafts' ], true ) ) {
			return 'exact_guard_match';
		}

		if ( 'clarify' === $status ) {
			return 'missing_params';
		}

		if ( 'recovery_fallback' === $resolution_path ) {
			return 'recovery_guard';
		}

		if ( 'safety_guard' === $resolution_path ) {
			return 'generic_clarification';
		}

		return '';
	}

	private static function build_router_health_insight( $summary ) {
		$summary = is_array( $summary ) ? $summary : [];
		$task_total = absint( $summary['task_total'] ?? 0 );
		$ai_task_route_rate = (float) ( $summary['ai_task_route_rate'] ?? 0 );
		$task_fallback_rate = (float) ( $summary['task_fallback_rate'] ?? 0 );
		$task_unclassified_rate = (float) ( $summary['task_unclassified_rate'] ?? 0 );
		$dominant_reason = sanitize_key( $summary['dominant_task_fallback_reason'] ?? '' );
		$dominant_action = sanitize_text_field( $summary['dominant_task_fallback_action'] ?? '' );
		$dominant_share = (float) ( $summary['dominant_task_fallback_share'] ?? 0 );
		$inferred_paths = absint( $summary['inferred_resolution_paths'] ?? 0 );
		$inferred_reasons = absint( $summary['inferred_fallback_reasons'] ?? 0 );

		if ( $task_total <= 0 ) {
			return [
				'status'  => 'no_data',
				'title'   => __( 'No task routing data yet', 'yoro-ai-core' ),
				'message' => __( 'Run a few store tasks to evaluate whether AI routing is becoming the dominant path.', 'yoro-ai-core' ),
			];
		}

		if ( $task_unclassified_rate > 5 ) {
			return [
				'status'  => 'observe',
				'title'   => __( 'Legacy logs still limit confidence', 'yoro-ai-core' ),
				'message' => sprintf(
					/* translators: 1: unclassified rate, 2: inferred count */
					__( 'About %1$s%% of task logs remain unclassified. The dashboard inferred %2$d guard or recovery paths, but you still need fresher traffic before calling this phase complete.', 'yoro-ai-core' ),
					number_format_i18n( $task_unclassified_rate, 1 ),
					$inferred_paths
				),
			];
		}

		if ( $task_fallback_rate <= 20 && $ai_task_route_rate >= 70 ) {
			return [
				'status'  => 'healthy',
				'title'   => __( 'AI routing is close to dominant', 'yoro-ai-core' ),
				'message' => sprintf(
					/* translators: 1: AI task route rate, 2: task fallback rate */
					__( 'AI primary currently handles %1$s%% of task requests, while non-AI fallbacks are down to %2$s%%. Remaining work is now mostly traffic-specific tuning.', 'yoro-ai-core' ),
					number_format_i18n( $ai_task_route_rate, 1 ),
					number_format_i18n( $task_fallback_rate, 1 )
				),
			];
		}

		if ( '' !== $dominant_reason ) {
			$target = '' !== $dominant_action ? $dominant_action : __( 'unknown action', 'yoro-ai-core' );

			return [
				'status'  => 'focus',
				'title'   => __( 'Next hardening target is clear', 'yoro-ai-core' ),
				'message' => sprintf(
					/* translators: 1: dominant fallback reason, 2: dominant share, 3: dominant action, 4: inferred reasons */
					__( 'The dominant non-AI task bucket is %1$s at %2$s%% of task fallbacks, led by %3$s. Use this as the next optimization target. %4$d fallback reasons were inferred from guard or recovery telemetry.', 'yoro-ai-core' ),
					$dominant_reason,
					number_format_i18n( $dominant_share, 1 ),
					$target,
					$inferred_reasons
				),
			];
		}

		return [
			'status'  => 'observe',
			'title'   => __( 'Routing health is measurable now', 'yoro-ai-core' ),
			'message' => __( 'Telemetry is now detailed enough to drive the next routing iteration from real traffic instead of guesses.', 'yoro-ai-core' ),
		];
	}

	private static function render_router_health_insight( $insight ) {
		$insight = is_array( $insight ) ? $insight : [];
		$title   = sanitize_text_field( $insight['title'] ?? '' );
		$message = sanitize_text_field( $insight['message'] ?? '' );
		$status  = sanitize_key( $insight['status'] ?? 'observe' );

		if ( '' === $title && '' === $message ) {
			return;
		}

		echo '<div class="yoro-telemetry-list">';
		echo '<span>' . esc_html__( 'Router health insight', 'yoro-ai-core' ) . '</span>';
		echo '<p><strong><code>' . esc_html( $status ) . '</code> ' . esc_html( $title ) . '</strong></p>';
		echo '<p>' . esc_html( $message ) . '</p>';
		echo '</div>';
	}

	private static function render_task_fallback_actions( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			echo '<em>' . esc_html__( 'No data yet', 'yoro-ai-core' ) . '</em>';
			return;
		}

		echo '<ul>';

		foreach ( $items as $reason => $actions ) {
			$reason = sanitize_key( (string) $reason );

			if ( '' === $reason || ! is_array( $actions ) || empty( $actions ) ) {
				continue;
			}

			$parts = [];

			foreach ( $actions as $action => $count ) {
				$parts[] = sanitize_text_field( (string) $action ) . ' (' . absint( $count ) . ')';
			}

			echo '<li><code>' . esc_html( $reason ) . '</code> <strong>' . esc_html( implode( ', ', $parts ) ) . '</strong></li>';
		}

		echo '</ul>';
	}

	private static function render_compact_counts( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			echo '<em>' . esc_html__( 'No data yet', 'yoro-ai-core' ) . '</em>';
			return;
		}

		echo '<ul>';

		foreach ( $items as $label => $count ) {
			if ( is_numeric( $count ) ) {
				echo '<li><code>' . esc_html( (string) $label ) . '</code> <strong>' . esc_html( (string) absint( $count ) ) . '</strong></li>';
				continue;
			}

			echo '<li><code>' . esc_html( (string) $label ) . '</code> <strong>' . esc_html( (string) $count ) . '</strong></li>';
		}

		echo '</ul>';
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
			<h1><?php esc_html_e( 'YoRo Policies', 'yoro-ai-core' ); ?></h1>

			<?php if ( isset( $_GET['policy_saved'] ) ) : ?>
				<?php if ( '1' === sanitize_text_field( wp_unslash( $_GET['policy_saved'] ) ) ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'YoRo policy saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'YoRo policy could not be saved.', 'yoro-ai-core' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Policy Mode', 'yoro-ai-core' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Choose how strictly YoRo should control actions. Balanced is recommended for most stores.', 'yoro-ai-core' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'yoro_save_policy_mode', 'yoro_policy_mode_nonce' ); ?>

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
										<?php esc_html_e( 'Best for testing. Allows most actions while still relying on action-level confirmations for dangerous operations.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="balanced" <?php checked( $policy_mode, 'balanced' ); ?> />
										<strong><?php esc_html_e( 'Balanced — Recommended', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Recommended for most stores. Allows common actions while protecting write, risky, and bulk operations.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="strict" <?php checked( $policy_mode, 'strict' ); ?> />
										<strong><?php esc_html_e( 'Strict', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Safer for larger teams. Uses tighter bulk limits and more preview/confirmation requirements.', 'yoro-ai-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="custom" <?php checked( $policy_mode, 'custom' ); ?> />
										<strong><?php esc_html_e( 'Custom', 'yoro-ai-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Use detailed per-action and per-role policy rules.', 'yoro-ai-core' ); ?>
									</p>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Advanced rules', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_policy_advanced_enabled" value="yes" <?php checked( $advanced_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Show advanced policy rules when Custom mode is selected.', 'yoro-ai-core' ); ?>
								</label>

								<p class="description">
									<?php esc_html_e( 'Most stores should keep this disabled and use Balanced mode.', 'yoro-ai-core' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Policy Mode', 'yoro-ai-core' ) ); ?>
				</form>
			</div>

			<div class="yoro-ai-card">
				<h2><?php esc_html_e( 'Current Policy Behavior', 'yoro-ai-core' ); ?></h2>

				<?php if ( 'permissive' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will allow most actions. Risky actions still use their built-in confirmation rules.', 'yoro-ai-core' ); ?></p>
				<?php elseif ( 'strict' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use stricter safety controls, tighter bulk limits, and more preview/confirmation requirements.', 'yoro-ai-core' ); ?></p>
				<?php elseif ( 'custom' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use custom per-role policy rules from the advanced policy table.', 'yoro-ai-core' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'YoRo will use the recommended balanced policy controls for normal store management.', 'yoro-ai-core' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! $show_advanced ) : ?>
				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Advanced Policies', 'yoro-ai-core' ); ?></h2>

					<p>
						<?php esc_html_e( 'Advanced rules are hidden. Select Custom mode and enable advanced rules if you need to manage policies per action and role.', 'yoro-ai-core' ); ?>
					</p>
				</div>
			<?php else : ?>
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
		$settings = class_exists( 'YoRo_AI_Settings' ) ? YoRo_AI_Settings::get_all() : [];
		$confidence = $settings['ai_confidence_threshold'] ?? 0.75;
		$read_only_confidence = $settings['ai_read_only_confidence_threshold'] ?? 0.6;
		$preview_threshold = $settings['ai_preview_threshold'] ?? 0.8;
		$workflow_ttl = absint( $settings['workflow_ttl'] ?? 1800 );
		$suggestions_enabled = $settings['suggestions_enabled'] ?? 'yes';
		$ai_max_suggestions = absint( $settings['ai_max_suggestions'] ?? 4 );
		$ai_suggestions_enabled = $settings['ai_suggestions_enabled'] ?? 'no';
		$ai_email_compose_enabled = $settings['ai_email_compose_enabled'] ?? 'yes';
		$ai_command_router_enabled = $settings['ai_command_router_enabled'] ?? 'yes';
		$ai_action_decomposition_enabled = $settings['ai_action_decomposition_enabled'] ?? 'yes';
		$ai_dynamic_workflows_enabled = $settings['ai_dynamic_workflows_enabled'] ?? 'no';
		$memory_enabled = $settings['memory_enabled'] ?? 'yes';
		$rate_limit_count = absint( $settings['rate_limit_count'] ?? 10 );
		$rate_limit_window = absint( $settings['rate_limit_window'] ?? 60 );
		$undo_ttl = absint( $settings['undo_ttl'] ?? 60 );
		$max_result_limit = absint( $settings['max_result_limit'] ?? 20 );
		$ai_api_url = $settings['ai_api_url'] ?? ( $settings['cloud_api_url'] ?? '' );
		$ai_api_url_locked = ! empty( $settings['ai_api_url_locked'] );
		$ai_api_url_constant = $settings['ai_api_url_constant'] ?? '';
		$ai_api_site_key_source = $settings['ai_api_site_key_source'] ?? ( $settings['cloud_site_key_source'] ?? 'missing' );
		$ai_api_site_key_constant = $settings['ai_api_site_key_constant'] ?? '';
		$ai_api_enabled = $settings['ai_api_enabled'] ?? ( $settings['cloud_parser_enabled'] ?? 'no' );
		$ai_api_endpoint_ready = $settings['ai_api_endpoint_ready'] ?? ( $settings['cloud_endpoint_ready'] ?? 'no' );
		$ai_api_status = isset( $settings['ai_api_status'] ) && is_array( $settings['ai_api_status'] )
			? $settings['ai_api_status']
			: ( isset( $settings['cloud_status'] ) && is_array( $settings['cloud_status'] ) ? $settings['cloud_status'] : [] );

		?>
		<div class="wrap yoro-ai-wrap">
			<h1><?php esc_html_e( 'YoRo AI Settings', 'yoro-ai-core' ); ?></h1>

			<form method="post" class="yoro-ai-settings-form">
				<?php wp_nonce_field( 'yoro_ai_save_settings', 'yoro_ai_settings_nonce' ); ?>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'General', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Conversation memory', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_memory_enabled" value="yes" <?php checked( $memory_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Remember recent orders, workflows, and context per admin user.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_ai_max_result_limit"><?php esc_html_e( 'Default max result limit', 'yoro-ai-core' ); ?></label>
							</th>
							<td>
								<input type="number" min="1" max="100" name="yoro_ai_max_result_limit" id="yoro_ai_max_result_limit" value="<?php echo esc_attr( $max_result_limit ); ?>" />
								<p class="description"><?php esc_html_e( 'Global safety cap for result-heavy actions. Action-level limits may still be stricter.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_workflow_ttl"><?php esc_html_e( 'Workflow timeout seconds', 'yoro-ai-core' ); ?></label>
							</th>
							<td>
								<input type="number" min="30" name="yoro_workflow_ttl" id="yoro_workflow_ttl" value="<?php echo esc_attr( $workflow_ttl ); ?>" />
								<p class="description"><?php esc_html_e( 'How long YoRo keeps incomplete workflows active before expiring them. Default: 1800 seconds.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'AI Routing', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Provider', 'yoro-ai-core' ); ?></th>
							<td>
								<strong><?php esc_html_e( 'YoRo AI API', 'yoro-ai-core' ); ?></strong>
								<p class="description"><?php esc_html_e( 'All AI requests are routed through YoRo AI API. Direct third-party API keys are no longer accepted in the plugin.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_confidence_threshold"><?php esc_html_e( 'Confidence threshold', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" step="0.01" min="0" max="1" name="yoro_ai_confidence_threshold" id="yoro_ai_confidence_threshold" value="<?php echo esc_attr( $confidence ); ?>" />
								<p class="description"><?php esc_html_e( 'AI command router results below this confidence will ask for clarification.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_read_only_confidence_threshold"><?php esc_html_e( 'Read-only confidence threshold', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" step="0.01" min="0" max="1" name="yoro_ai_read_only_confidence_threshold" id="yoro_ai_read_only_confidence_threshold" value="<?php echo esc_attr( $read_only_confidence ); ?>" />
								<p class="description"><?php esc_html_e( 'Lower confidence floor used for read-only actions before YoRo falls back to clarification.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_ai_preview_threshold"><?php esc_html_e( 'Auto-preview threshold', 'yoro-ai-core' ); ?></label>
							</th>
							<td>
								<input type="number" step="0.01" min="0" max="1" name="yoro_ai_preview_threshold" id="yoro_ai_preview_threshold" value="<?php echo esc_attr( $preview_threshold ); ?>" />
								<p class="description"><?php esc_html_e( 'AI-routed action results below this confidence will run as preview instead of executing immediately.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI command router', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_command_router_enabled" value="yes" <?php checked( $ai_command_router_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Route user commands through the AI action catalog with deterministic guardrails for recovery and safety.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'AI Features', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Suggestions', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_enable_suggestions" value="yes" <?php checked( $suggestions_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Show next-step suggestions after YoRo actions.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI suggestions', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_suggestions_enabled" value="yes" <?php checked( $ai_suggestions_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Generate extra AI-powered next-step suggestions after actions.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI customer email composer', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_email_compose_enabled" value="yes" <?php checked( $ai_email_compose_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Allow YoRo to draft customer emails with AI.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI action decomposition', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_action_decomposition_enabled" value="yes" <?php checked( $ai_action_decomposition_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Let YoRo split certain compound commands into multiple supported write actions before execution.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Dynamic AI workflows', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_dynamic_workflows_enabled" value="yes" <?php checked( $ai_dynamic_workflows_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Enable experimental AI-generated workflow plans for multi-step store tasks.', 'yoro-ai-core' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'This is more flexible but also more experimental than the standard command router path.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Rate Limit', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_rate_limit_count"><?php esc_html_e( 'Max commands', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="1" name="yoro_rate_limit_count" id="yoro_rate_limit_count" value="<?php echo esc_attr( $rate_limit_count ); ?>" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_rate_limit_window"><?php esc_html_e( 'Window seconds', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="10" name="yoro_rate_limit_window" id="yoro_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" />
								<p class="description"><?php esc_html_e( 'YoRo allows up to the max command count inside this moving time window.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'Safety', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_undo_ttl"><?php esc_html_e( 'Undo window seconds', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="number" min="0" name="yoro_undo_ttl" id="yoro_undo_ttl" value="<?php echo esc_attr( $undo_ttl ); ?>" />
								<p class="description"><?php esc_html_e( 'Set 0 to disable expiry.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-ai-card">
					<h2><?php esc_html_e( 'YoRo AI API', 'yoro-ai-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'API command router', 'yoro-ai-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_api_enabled" value="yes" <?php checked( $ai_api_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Enable YoRo AI API command routing when provider is set to YoRo AI API.', 'yoro-ai-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_api_url"><?php esc_html_e( 'API base URL', 'yoro-ai-core' ); ?></label></th>
							<td>
								<input type="url" name="yoro_ai_api_url" id="yoro_ai_api_url" value="<?php echo esc_attr( $ai_api_url ); ?>" class="regular-text" placeholder="https://api.yoro.ai/api/v1" <?php disabled( $ai_api_url_locked ); ?> />
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
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_api_site_key"><?php esc_html_e( 'Site API key', 'yoro-ai-core' ); ?></label></th>
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
									<?php esc_html_e( 'Configured in plugin settings.', 'yoro-ai-core' ); ?>
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
										placeholder="<?php esc_attr_e( 'Paste a new site API key to save', 'yoro-ai-core' ); ?>"
										<?php disabled( 'constant' === $ai_api_site_key_source ); ?>
									/>
								</p>

								<?php if ( 'constant' !== $ai_api_site_key_source ) : ?>
									<p>
										<label>
											<input type="checkbox" name="yoro_ai_api_site_key_clear" value="yes" />
											<?php esc_html_e( 'Clear saved site API key', 'yoro-ai-core' ); ?>
										</label>
									</p>
								<?php endif; ?>

								<p class="description"><?php esc_html_e( 'Leave blank to keep the current saved key unchanged.', 'yoro-ai-core' ); ?></p>
							</td>
						</tr>

						<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'API endpoint ready', 'yoro-ai-core' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="yoro_ai_api_endpoint_ready" value="yes" <?php checked( $ai_api_endpoint_ready, 'yes' ); ?> />
										<?php esc_html_e( 'Developer only. Enable only when the YoRo AI API command routing endpoint is actually available.', 'yoro-ai-core' ); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>

						<tr>
							<th scope="row"><?php esc_html_e( 'Connection status', 'yoro-ai-core' ); ?></th>
							<td>
								<?php if ( ! empty( $ai_api_status['checked_at'] ) ) : ?>
									<?php if ( ! empty( $ai_api_status['connected'] ) ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
										<?php esc_html_e( 'Connected.', 'yoro-ai-core' ); ?>
									<?php else : ?>
										<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
										<?php echo esc_html( $ai_api_status['message'] ?? __( 'Connection test failed.', 'yoro-ai-core' ) ); ?>
									<?php endif; ?>

									<?php if ( ! empty( $ai_api_status['site']['plan'] ) ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: 1: plan name, 2: site status */
												esc_html__( 'Plan: %1$s. Site status: %2$s.', 'yoro-ai-core' ),
												esc_html( $ai_api_status['site']['plan'] ),
												esc_html( $ai_api_status['site']['status'] ?? 'unknown' )
											);
											?>
										</p>
									<?php endif; ?>

									<?php if ( ! empty( $ai_api_status['entitlements'] ) && is_array( $ai_api_status['entitlements'] ) ) : ?>
										<p class="description">
											<?php
											$ai_api_feature_states = [];
											$ai_api_feature_labels = [
												'command_router' => __( 'Command router', 'yoro-ai-core' ),
												'email_composer' => __( 'Email composer', 'yoro-ai-core' ),
												'workflows'      => __( 'Workflows', 'yoro-ai-core' ),
											];

											foreach ( $ai_api_feature_labels as $feature_key => $feature_label ) {
												if ( ! array_key_exists( $feature_key, $ai_api_status['entitlements'] ) ) {
													continue;
												}

												$ai_api_feature_states[] = sprintf(
													'%s: %s',
													$feature_label,
													! empty( $ai_api_status['entitlements'][ $feature_key ] )
														? __( 'on', 'yoro-ai-core' )
														: __( 'off', 'yoro-ai-core' )
												);
											}

											if ( [] !== $ai_api_feature_states ) {
												echo esc_html(
													sprintf(
														/* translators: %s: comma-separated feature states */
														__( 'Features: %s.', 'yoro-ai-core' ),
														implode( ', ', $ai_api_feature_states )
													)
												);
											}
											?>
										</p>
									<?php endif; ?>

									<?php if ( isset( $ai_api_status['usage']['limit'], $ai_api_status['usage']['used'], $ai_api_status['usage']['remaining'] ) ) : ?>
										<p class="description">
											<?php
											printf(
												/* translators: 1: used requests, 2: request limit, 3: remaining requests, 4: usage period */
												esc_html__( 'Usage: %1$s / %2$s requests used, %3$s remaining for %4$s.', 'yoro-ai-core' ),
												esc_html( number_format_i18n( absint( $ai_api_status['usage']['used'] ) ) ),
												esc_html( number_format_i18n( absint( $ai_api_status['usage']['limit'] ) ) ),
												esc_html( number_format_i18n( absint( $ai_api_status['usage']['remaining'] ) ) ),
												esc_html( $ai_api_status['usage']['period'] ?? '' )
											);
											?>
										</p>
									<?php endif; ?>

									<p class="description">
										<?php
										printf(
											/* translators: %s: timestamp */
											esc_html__( 'Last checked: %s.', 'yoro-ai-core' ),
											esc_html( $ai_api_status['checked_at'] )
										);
										?>
									</p>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'Not checked yet.', 'yoro-ai-core' ); ?></p>
								<?php endif; ?>

								<?php submit_button( __( 'Test YoRo AI API Connection', 'yoro-ai-core' ), 'secondary', 'yoro_ai_api_test_connection', false ); ?>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save Settings', 'yoro-ai-core' ) ); ?>
			</form>

			<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) : ?>
				<div class="yoro-ai-card">
					<h2>Developer Tools</h2>

					<p>
						<button type="button" class="button button-primary" id="yoro-run-parser-tests">
							Run Router Tests
						</button>
						<button type="button" class="button" id="yoro-build-real-query-pack">
							Build Command Pack
						</button>
					</p>

					<div id="yoro-parser-test-results" class="yoro-parser-test-results" aria-live="polite"></div>
					<div id="yoro-real-query-pack-results" class="yoro-parser-test-results" aria-live="polite"></div>

					<p>
						<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'yoro-ai-core/v1/memory' ) ) ); ?>">
							View Conversation Memory
						</a>
					</p>
					
					<p>
						<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'yoro-ai-core/v1/actions' ) ) ); ?>">
							View Registered Actions
						</a>
					</p>
				</div>
			<?php endif; ?>
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
						'page'         => self::POLICIES_SLUG,
						'policy_saved' => '1',
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
				'page'         => self::POLICIES_SLUG,
				'policy_saved' => false === $updated ? '0' : '1',
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

		$settings_result = [];

		if ( class_exists( 'YoRo_AI_Settings' ) ) {
			$settings_result = YoRo_AI_Settings::save_admin_settings( $_POST );
		}

		$connection_result = is_array( $settings_result )
			? ( $settings_result['ai_api_connection_result'] ?? ( $settings_result['cloud_connection_result'] ?? null ) )
			: null;
		$connection_error = is_array( $settings_result )
			? ( $settings_result['ai_api_connection_error'] ?? ( $settings_result['cloud_connection_error'] ?? null ) )
			: null;
		$credentials_verified = is_array( $settings_result ) && (
			! empty( $settings_result['ai_api_credentials_verified'] ) ||
			! empty( $settings_result['cloud_credentials_verified'] )
		);

		if (
			( ! empty( $_POST['yoro_ai_api_test_connection'] ) || ! empty( $_POST['yoro_cloud_test_connection'] ) ) &&
			class_exists( 'YoRo_AI_API_Client' ) &&
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

		// YoRo AI submenus: personalized footer.
		if ( in_array( $screen->id, self::get_admin_screen_ids(), true ) && 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return sprintf(
				esc_html__( 'Powered by %s — built for smarter WooCommerce store management.', 'yoro-ai-core' ),
				'<a href="https://yoohw.com" target="_blank"><strong>YoOhw Studio</strong></a>'
			);
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

		// YoRo AI submenus: personalized version text.
		if ( in_array( $screen->id, self::get_admin_screen_ids(), true ) && 'toplevel_page_' . self::MENU_SLUG !== $screen->id ) {
			return sprintf(
				esc_html__( 'YoRo AI Core v%s', 'yoro-ai-core' ),
				esc_html( YORO_AI_CORE_VERSION )
			);
		}

		return $text;
	}
}
