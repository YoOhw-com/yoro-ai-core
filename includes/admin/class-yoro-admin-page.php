<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Admin_Page {

	const MENU_SLUG = 'yoro-agent';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_policy_save' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_settings_save' ] );

		add_filter( 'admin_footer_text', [ __CLASS__, 'filter_admin_footer_text' ], 20 );
		add_filter( 'update_footer', [ __CLASS__, 'filter_update_footer' ], 20 );
	}

	public static function add_menu_page() {
		add_menu_page(
			__( 'YoRo Agent', 'yoro-agent-core' ),
			__( 'YoRo Agent', 'yoro-agent-core' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			[ __CLASS__, 'render_page' ],
			'dashicons-superhero',
			2
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'History', 'yoro-agent-core' ),
			__( 'History', 'yoro-agent-core' ),
			'manage_woocommerce',
			'yoro-agent-history',
			[ __CLASS__, 'render_history_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Policies', 'yoro-agent-core' ),
			__( 'Policies', 'yoro-agent-core' ),
			'manage_options',
			'yoro-agent-policies',
			[ __CLASS__, 'render_policies_page' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'yoro-agent-core' ),
			__( 'Settings', 'yoro-agent-core' ),
			'manage_options',
			'yoro-agent-settings',
			[ __CLASS__, 'render_settings_page' ]
		);
	}

	public static function enqueue_assets( $hook ) {
		$allowed_hooks = [
			'toplevel_page_' . self::MENU_SLUG,
			'yoro-agent_page_yoro-agent-history',
			'yoro-agent_page_yoro-agent-settings',
			'yoro-agent_page_yoro-agent-policies',
		];

		if ( ! in_array( $hook, $allowed_hooks, true ) ) {
			return;
		}

		wp_enqueue_style(
			'yoro-agent-admin',
			YORO_AGENT_CORE_URL . 'assets/css/yoro-agent-admin.css',
			[],
			YORO_AGENT_CORE_VERSION
		);

		wp_enqueue_script(
			'yoro-agent-workflow-renderers',
			YORO_AGENT_CORE_URL . 'assets/js/yoro-agent-workflow-renderers.js',
			[],
			YORO_AGENT_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-agent-email-ui',
			YORO_AGENT_CORE_URL . 'assets/js/yoro-agent-email-ui.js',
			[],
			YORO_AGENT_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-agent-order-ui',
			YORO_AGENT_CORE_URL . 'assets/js/yoro-agent-order-ui.js',
			[],
			YORO_AGENT_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-agent-result-renderers',
			YORO_AGENT_CORE_URL . 'assets/js/yoro-agent-result-renderers.js',
			[],
			YORO_AGENT_CORE_VERSION,
			true
		);

		wp_enqueue_script(
			'yoro-agent-admin',
			YORO_AGENT_CORE_URL . 'assets/js/yoro-agent-admin.js',
			[
				'wp-api-fetch',
				'yoro-agent-workflow-renderers',
				'yoro-agent-email-ui',
				'yoro-agent-order-ui',
				'yoro-agent-result-renderers',
			],
			YORO_AGENT_CORE_VERSION,
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

		wp_localize_script(
			'yoro-agent-admin',
			'YoRoAgentAdmin',
			[
				'debug'         => true,
				'runUrl'        => esc_url_raw( rest_url( 'yoro-agent/v1/run' ) ),
				'executeUrl'    => esc_url_raw( rest_url( 'yoro-agent/v1/execute' ) ),
				'contextUrl'    => esc_url_raw( rest_url( 'yoro-agent/v1/context' ) ),
				'memoryUrl'     => esc_url_raw( rest_url( 'yoro-agent/v1/memory' ) ),
				'morningBriefingUrl' => esc_url_raw( rest_url( 'yoro-agent/v1/proactive/morning-briefing' ) ),
				'morningBriefingDismissUrl' => esc_url_raw( rest_url( 'yoro-agent/v1/proactive/morning-briefing-dismiss' ) ),
				'parserTestUrl' => esc_url_raw( rest_url( 'yoro-agent/v1/parser-test' ) ),
				'realQueryPackUrl' => esc_url_raw( rest_url( 'yoro-agent/v1/real-query-pack' ) ),
				'actionsUrl'    => esc_url_raw( rest_url( 'yoro-agent/v1/actions' ) ),
				'undoUrl'       => esc_url_raw( rest_url( 'yoro-agent/v1/undo' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'orderStatuses' => $order_statuses,
				'i18n'          => [
					'emptyCommand' => __( 'Please enter a command.', 'yoro-agent-core' ),
					'running'      => __( 'Thinking...', 'yoro-agent-core' ),
					'askYoRo'      => __( 'Ask YoRo', 'yoro-agent-core' ),
					'confirmRun'   => __( 'This store-changing action requires your confirmation. Run it now?', 'yoro-agent-core' ),
					'error'        => __( 'Something went wrong.', 'yoro-agent-core' ),
				],
			]
		);
	}

	public static function render_page() {
		?>
		<div class="yoro-chat-shell">
			<div class="yoro-agent-statusbar" aria-label="<?php esc_attr_e( 'YoRo AI command workflow', 'yoro-agent-core' ); ?>">
				<div class="yoro-agent-status-item">
					<span><?php esc_html_e( 'AI routing', 'yoro-agent-core' ); ?></span>
					<strong><?php esc_html_e( 'Natural language to action', 'yoro-agent-core' ); ?></strong>
				</div>
				<div class="yoro-agent-status-item">
					<span><?php esc_html_e( 'Safety', 'yoro-agent-core' ); ?></span>
					<strong><?php esc_html_e( 'Preview and confirm writes', 'yoro-agent-core' ); ?></strong>
				</div>
				<div class="yoro-agent-status-item">
					<span><?php esc_html_e( 'Context', 'yoro-agent-core' ); ?></span>
					<strong><?php esc_html_e( 'Understands active store work', 'yoro-agent-core' ); ?></strong>
				</div>
			</div>

			<div id="yoro-chat-thread" class="yoro-chat-thread">
				<div class="yoro-chat-message yoro-chat-message-agent">
					<div class="yoro-chat-avatar">Y</div>
					<div class="yoro-chat-bubble">
						<strong><?php esc_html_e( 'YoRo', 'yoro-agent-core' ); ?></strong>
						<p><?php esc_html_e( 'Tell me what you want in natural language. I will map it to a store action, preview sensitive changes, and ask before changing store data.', 'yoro-agent-core' ); ?></p>
					</div>
				</div>
			</div>

			<div class="yoro-chat-composer">
				<textarea
					id="yoro-agent-command"
					class="yoro-chat-input"
					rows="2"
					placeholder="<?php esc_attr_e( 'Ask naturally, e.g. Show me the latest 5 orders, or Prepare the next fulfillment follow-up', 'yoro-agent-core' ); ?>"
				></textarea>

				<div class="yoro-chat-actions">
					<button type="button" class="button button-primary" id="yoro-agent-run">
						<?php esc_html_e( 'Ask YoRo', 'yoro-agent-core' ); ?>
					</button>

					<button type="button" class="button" id="yoro-agent-preview">
						<?php esc_html_e( 'Preview Plan', 'yoro-agent-core' ); ?>
					</button>

					<button type="button" class="button" id="yoro-agent-clear">
						<?php esc_html_e( 'Clear Chat', 'yoro-agent-core' ); ?>
					</button>

					<button type="button" class="button" id="yoro-agent-load-context">
						<?php esc_html_e( 'Store Context', 'yoro-agent-core' ); ?>
					</button>
				</div>

				<div class="yoro-agent-examples">
					<strong><?php esc_html_e( 'Try:', 'yoro-agent-core' ); ?></strong>
					<button type="button" class="button-link yoro-agent-example" data-command="Show me the latest 5 orders">
						<?php esc_html_e( 'Show latest 5 orders', 'yoro-agent-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-agent-example" data-command="How is the store doing this week?">
						<?php esc_html_e( 'Store performance this week', 'yoro-agent-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-agent-example" data-command="Prepare the next fulfillment follow-up">
						<?php esc_html_e( 'Prepare fulfillment follow-up', 'yoro-agent-core' ); ?>
					</button>
					<button type="button" class="button-link yoro-agent-example" data-command="Update order 123 to on hold with note: Customer needs verification">
						<?php esc_html_e( 'Update order with note', 'yoro-agent-core' ); ?>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	public static function render_history_page() {

		require_once YORO_AGENT_CORE_PATH . 'includes/admin/class-yoro-history-table.php';

		$telemetry_summary = self::get_telemetry_summary();
		$trace_view = self::get_ai_trace_view();
		$table = new YoRo_History_Table();
		$table->prepare_items();
		?>

		<div class="wrap yoro-agent-wrap">
			<h1><?php esc_html_e( 'YoRo Agent History', 'yoro-agent-core' ); ?></h1>

			<?php self::render_telemetry_summary( $telemetry_summary ); ?>
			<?php self::render_ai_trace_view( $trace_view ); ?>

			<form method="post">
				<input type="hidden" name="page" value="yoro-agent-history" />
				<input type="hidden" name="s" value="<?php echo esc_attr( $_REQUEST['s'] ?? '' ); ?>">

				<?php wp_nonce_field( 'bulk-yoro_logs' ); ?>

				<?php
				$table->search_box( __( 'Search logs', 'yoro-agent-core' ), 'yoro-search' );
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
				<h2><?php esc_html_e( 'AI Trace View', 'yoro-agent-core' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Trace ID:', 'yoro-agent-core' ); ?></strong>
					<code><?php echo esc_html( $trace_view['ai_trace_id'] ); ?></code>
				</p>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: log count */
							__( '%d related log(s) found.', 'yoro-agent-core' ),
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
							<span><strong><?php esc_html_e( 'Action:', 'yoro-agent-core' ); ?></strong> <code><?php echo esc_html( $log['action'] ?: '—' ); ?></code></span>
							<span><strong><?php esc_html_e( 'Router source:', 'yoro-agent-core' ); ?></strong> <code><?php echo esc_html( $log['parser_source'] ?: '—' ); ?></code></span>
							<span><strong><?php esc_html_e( 'Log Trace:', 'yoro-agent-core' ); ?></strong> <code><?php echo esc_html( $log['trace_id'] ?: '—' ); ?></code></span>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private static function get_telemetry_summary() {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_logs';

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
			'top_actions'           => [],
			'top_errors'            => [],
			'avg_duration_ms'       => 0,
			'confirmation_rate'     => 0,
			'error_rate'            => 0,
			'undoable_rate'         => 0,
			'duration_sample_count' => 0,
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
			$ai_feature    = sanitize_key( $telemetry['ai_feature'] ?? '' );
			$ai_model      = sanitize_text_field( $telemetry['ai_model'] ?? '' );
			$prompt_version = sanitize_text_field( $telemetry['prompt_version'] ?? '' );
			$ai_provider   = sanitize_key( $telemetry['ai_provider'] ?? '' );

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
		arsort( $summary['top_actions'] );
		arsort( $summary['top_errors'] );

		$summary['parser_sources'] = array_slice( $summary['parser_sources'], 0, 6, true );
		$summary['ai_features']    = array_slice( $summary['ai_features'], 0, 6, true );
		$summary['ai_models']      = array_slice( $summary['ai_models'], 0, 6, true );
		$summary['prompt_versions'] = array_slice( $summary['prompt_versions'], 0, 6, true );
		$summary['ai_providers']   = array_slice( $summary['ai_providers'], 0, 6, true );
		$summary['intents']        = array_slice( $summary['intents'], 0, 6, true );
		$summary['top_actions']    = array_slice( $summary['top_actions'], 0, 6, true );
		$summary['top_errors']     = array_slice( $summary['top_errors'], 0, 6, true );

		if ( $summary['duration_sample_count'] > 0 ) {
			$summary['avg_duration_ms'] = (int) round( $total_duration / $summary['duration_sample_count'] );
		}

		if ( $summary['total'] > 0 ) {
			$summary['confirmation_rate'] = round( ( $summary['confirm_required'] / $summary['total'] ) * 100, 1 );
			$summary['error_rate']        = round( ( $summary['error'] / $summary['total'] ) * 100, 1 );
			$summary['undoable_rate']     = round( ( $summary['undoable'] / $summary['total'] ) * 100, 1 );
		}

		return $summary;
	}

	private static function render_telemetry_summary( $summary ) {
		if ( empty( $summary['available'] ) ) {
			return;
		}

		?>
		<div class="yoro-telemetry-summary">
			<div class="yoro-telemetry-metric">
				<span><?php esc_html_e( 'Logs', 'yoro-agent-core' ); ?></span>
				<strong><?php echo esc_html( (string) absint( $summary['total'] ?? 0 ) ); ?></strong>
			</div>
			<div class="yoro-telemetry-metric">
				<span><?php esc_html_e( 'Confirm rate', 'yoro-agent-core' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['confirmation_rate'] ?? 0 ), 1 ) ); ?>%</strong>
			</div>
			<div class="yoro-telemetry-metric">
				<span><?php esc_html_e( 'Error rate', 'yoro-agent-core' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['error_rate'] ?? 0 ), 1 ) ); ?>%</strong>
			</div>
			<div class="yoro-telemetry-metric">
				<span><?php esc_html_e( 'Undoable', 'yoro-agent-core' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( (float) ( $summary['undoable_rate'] ?? 0 ), 1 ) ); ?>%</strong>
			</div>
			<div class="yoro-telemetry-metric">
				<span><?php esc_html_e( 'Avg duration', 'yoro-agent-core' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( absint( $summary['avg_duration_ms'] ?? 0 ) ) ); ?>ms</strong>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'Router sources', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['parser_sources'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'AI features', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['ai_features'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'AI models', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['ai_models'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'Prompt versions', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['prompt_versions'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'AI providers', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['ai_providers'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'Top intents', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['intents'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'Top actions', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['top_actions'] ?? [] ); ?>
			</div>
			<div class="yoro-telemetry-list">
				<span><?php esc_html_e( 'Top errors', 'yoro-agent-core' ); ?></span>
				<?php self::render_compact_counts( $summary['top_errors'] ?? [] ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_compact_counts( $items ) {
		if ( empty( $items ) || ! is_array( $items ) ) {
			echo '<em>' . esc_html__( 'No data yet', 'yoro-agent-core' ) . '</em>';
			return;
		}

		echo '<ul>';

		foreach ( $items as $label => $count ) {
			echo '<li><code>' . esc_html( (string) $label ) . '</code> <strong>' . esc_html( (string) absint( $count ) ) . '</strong></li>';
		}

		echo '</ul>';
	}

	public static function render_policies_page() {
		global $wpdb;

		$table = $wpdb->prefix . 'yoro_agent_policies';

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
		<div class="wrap yoro-agent-wrap">
			<h1><?php esc_html_e( 'YoRo Policies', 'yoro-agent-core' ); ?></h1>

			<?php if ( isset( $_GET['policy_saved'] ) ) : ?>
				<?php if ( '1' === sanitize_text_field( wp_unslash( $_GET['policy_saved'] ) ) ) : ?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'YoRo policy saved.', 'yoro-agent-core' ); ?></p>
					</div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'YoRo policy could not be saved.', 'yoro-agent-core' ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<div class="yoro-agent-card">
				<h2><?php esc_html_e( 'Policy Mode', 'yoro-agent-core' ); ?></h2>

				<p class="description">
					<?php esc_html_e( 'Choose how strictly YoRo should control actions. Balanced is recommended for most stores.', 'yoro-agent-core' ); ?>
				</p>

				<form method="post">
					<?php wp_nonce_field( 'yoro_save_policy_mode', 'yoro_policy_mode_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Mode', 'yoro-agent-core' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="radio" name="yoro_policy_mode" value="permissive" <?php checked( $policy_mode, 'permissive' ); ?> />
										<strong><?php esc_html_e( 'Permissive', 'yoro-agent-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Best for testing. Allows most actions while still relying on action-level confirmations for dangerous operations.', 'yoro-agent-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="balanced" <?php checked( $policy_mode, 'balanced' ); ?> />
										<strong><?php esc_html_e( 'Balanced — Recommended', 'yoro-agent-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Recommended for most stores. Allows common actions while protecting write, risky, and bulk operations.', 'yoro-agent-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="strict" <?php checked( $policy_mode, 'strict' ); ?> />
										<strong><?php esc_html_e( 'Strict', 'yoro-agent-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Safer for larger teams. Uses tighter bulk limits and more preview/confirmation requirements.', 'yoro-agent-core' ); ?>
									</p>

									<br>

									<label>
										<input type="radio" name="yoro_policy_mode" value="custom" <?php checked( $policy_mode, 'custom' ); ?> />
										<strong><?php esc_html_e( 'Custom', 'yoro-agent-core' ); ?></strong>
									</label>
									<p class="description">
										<?php esc_html_e( 'Use detailed per-action and per-role policy rules.', 'yoro-agent-core' ); ?>
									</p>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Advanced rules', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_policy_advanced_enabled" value="yes" <?php checked( $advanced_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Show advanced policy rules when Custom mode is selected.', 'yoro-agent-core' ); ?>
								</label>

								<p class="description">
									<?php esc_html_e( 'Most stores should keep this disabled and use Balanced mode.', 'yoro-agent-core' ); ?>
								</p>
							</td>
						</tr>
					</table>

					<?php submit_button( __( 'Save Policy Mode', 'yoro-agent-core' ) ); ?>
				</form>
			</div>

			<div class="yoro-agent-card">
				<h2><?php esc_html_e( 'Current Policy Behavior', 'yoro-agent-core' ); ?></h2>

				<?php if ( 'permissive' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will allow most actions. Risky actions still use their built-in confirmation rules.', 'yoro-agent-core' ); ?></p>
				<?php elseif ( 'strict' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use stricter safety controls, tighter bulk limits, and more preview/confirmation requirements.', 'yoro-agent-core' ); ?></p>
				<?php elseif ( 'custom' === $policy_mode ) : ?>
					<p><?php esc_html_e( 'YoRo will use custom per-role policy rules from the advanced policy table.', 'yoro-agent-core' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'YoRo will use the recommended balanced policy controls for normal store management.', 'yoro-agent-core' ); ?></p>
				<?php endif; ?>
			</div>

			<?php if ( ! $show_advanced ) : ?>
				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'Advanced Policies', 'yoro-agent-core' ); ?></h2>

					<p>
						<?php esc_html_e( 'Advanced rules are hidden. Select Custom mode and enable advanced rules if you need to manage policies per action and role.', 'yoro-agent-core' ); ?>
					</p>
				</div>
			<?php else : ?>
				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'Advanced Policy Rules', 'yoro-agent-core' ); ?></h2>

					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Action', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Role', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Allow', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Max Items', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'AI', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Direct', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Write', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Preview', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Confirm', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Risk Levels', 'yoro-agent-core' ); ?></th>
								<th><?php esc_html_e( 'Save', 'yoro-agent-core' ); ?></th>
							</tr>
						</thead>

						<tbody>
							<?php if ( empty( $rows ) ) : ?>
								<tr>
									<td colspan="11">
										<?php esc_html_e( 'No policies found.', 'yoro-agent-core' ); ?>
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
													<?php esc_html_e( 'Allow', 'yoro-agent-core' ); ?>
												</option>
												<option value="0" <?php selected( (int) $row->allow, 0 ); ?>>
													<?php esc_html_e( 'Block', 'yoro-agent-core' ); ?>
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
												<?php esc_html_e( 'Use 0 for no item limit.', 'yoro-agent-core' ); ?>
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
												<?php esc_html_e( 'Low', 'yoro-agent-core' ); ?>
											</label><br>

											<label>
												<input type="checkbox" name="allowed_risk_levels[]" value="medium" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( in_array( 'medium', $risk_levels, true ) ); ?> />
												<?php esc_html_e( 'Medium', 'yoro-agent-core' ); ?>
											</label><br>

											<label>
												<input type="checkbox" name="allowed_risk_levels[]" value="high" form="<?php echo esc_attr( $form_id ); ?>" <?php checked( in_array( 'high', $risk_levels, true ) ); ?> />
												<?php esc_html_e( 'High', 'yoro-agent-core' ); ?>
											</label>
										</td>

										<td>
											<form id="<?php echo esc_attr( $form_id ); ?>" method="post">
												<input type="hidden" name="policy_id" value="<?php echo esc_attr( $policy_id ); ?>" />
												<?php wp_nonce_field( 'yoro_save_policy_' . $policy_id, 'yoro_policy_nonce' ); ?>
												<?php submit_button( __( 'Save', 'yoro-agent-core' ), 'primary small', '', false ); ?>
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
		$provider = $settings['provider'] ?? YORO_AI_PROVIDER_OPENAI_DIRECT;
		$model = $settings['openai_model'] ?? 'gpt-5-nano';
		$confidence = $settings['ai_confidence_threshold'] ?? 0.75;
		$preview_threshold = $settings['ai_preview_threshold'] ?? 0.8;
		$workflow_ttl = absint( $settings['workflow_ttl'] ?? 1800 );
		$suggestions_enabled = $settings['suggestions_enabled'] ?? 'yes';
		$ai_max_suggestions = absint( $settings['ai_max_suggestions'] ?? 4 );
		$ai_suggestions_enabled = $settings['ai_suggestions_enabled'] ?? 'no';
		$ai_email_compose_enabled = $settings['ai_email_compose_enabled'] ?? 'yes';
		$ai_command_router_enabled = $settings['ai_command_router_enabled'] ?? 'yes';
		$memory_enabled = $settings['memory_enabled'] ?? 'yes';
		$rate_limit_count = absint( $settings['rate_limit_count'] ?? 10 );
		$rate_limit_window = absint( $settings['rate_limit_window'] ?? 60 );
		$undo_ttl = absint( $settings['undo_ttl'] ?? 60 );
		$max_result_limit = absint( $settings['max_result_limit'] ?? 20 );
		$cloud_api_url = $settings['cloud_api_url'] ?? '';
		$cloud_site_key = $settings['cloud_site_key'] ?? '';
		$cloud_parser_enabled = $settings['cloud_parser_enabled'] ?? 'no';
		$cloud_endpoint_ready = $settings['cloud_endpoint_ready'] ?? 'no';

		?>
		<div class="wrap yoro-agent-wrap">
			<h1><?php esc_html_e( 'YoRo Agent Settings', 'yoro-agent-core' ); ?></h1>

			<form method="post" class="yoro-agent-settings-form">
				<?php wp_nonce_field( 'yoro_agent_save_settings', 'yoro_agent_settings_nonce' ); ?>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'General', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Conversation memory', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_memory_enabled" value="yes" <?php checked( $memory_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Remember recent orders, workflows, and context per admin user.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_ai_max_result_limit"><?php esc_html_e( 'Default max result limit', 'yoro-agent-core' ); ?></label>
							</th>
							<td>
								<input type="number" min="1" max="100" name="yoro_ai_max_result_limit" id="yoro_ai_max_result_limit" value="<?php echo esc_attr( $max_result_limit ); ?>" />
								<p class="description"><?php esc_html_e( 'Global safety cap for result-heavy actions. Action-level limits may still be stricter.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_workflow_ttl"><?php esc_html_e( 'Workflow timeout seconds', 'yoro-agent-core' ); ?></label>
							</th>
							<td>
								<input type="number" min="30" name="yoro_workflow_ttl" id="yoro_workflow_ttl" value="<?php echo esc_attr( $workflow_ttl ); ?>" />
								<p class="description"><?php esc_html_e( 'How long YoRo keeps incomplete workflows active before expiring them. Default: 1800 seconds.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'AI Provider', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_ai_parser_provider"><?php esc_html_e( 'AI routing provider', 'yoro-agent-core' ); ?></label></th>
							<td>
								<select name="yoro_ai_parser_provider" id="yoro_ai_parser_provider">
									<option value="<?php echo esc_attr( YORO_AI_PROVIDER_OPENAI_DIRECT ); ?>" <?php selected( $provider, YORO_AI_PROVIDER_OPENAI_DIRECT ); ?>>
										<?php esc_html_e( 'OpenAI Direct', 'yoro-agent-core' ); ?>
									</option>
									<option value="<?php echo esc_attr( YORO_AI_PROVIDER_YORO_CLOUD ); ?>" <?php selected( $provider, YORO_AI_PROVIDER_YORO_CLOUD ); ?>>
										<?php esc_html_e( 'YoRo Cloud', 'yoro-agent-core' ); ?>
									</option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_openai_model"><?php esc_html_e( 'OpenAI model', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="text" name="yoro_openai_model" id="yoro_openai_model" value="<?php echo esc_attr( $model ); ?>" class="regular-text" />
								<p class="description"><?php esc_html_e( 'Recommended: gpt-5-nano.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'OpenAI direct key', 'yoro-agent-core' ); ?></th>
							<td>
								<?php if ( ! empty( $settings['openai_key_configured'] ) ) : ?>
									<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
									<?php esc_html_e( 'Configured by constant.', 'yoro-agent-core' ); ?>
								<?php else : ?>
									<span class="dashicons dashicons-warning" style="color:#dba617;"></span>
									<?php esc_html_e( 'Not configured. Direct OpenAI mode will not run.', 'yoro-agent-core' ); ?>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_ai_confidence_threshold"><?php esc_html_e( 'Confidence threshold', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="number" step="0.01" min="0" max="1" name="yoro_ai_confidence_threshold" id="yoro_ai_confidence_threshold" value="<?php echo esc_attr( $confidence ); ?>" />
								<p class="description"><?php esc_html_e( 'AI command router results below this confidence will ask for clarification.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="yoro_ai_preview_threshold"><?php esc_html_e( 'Auto-preview threshold', 'yoro-agent-core' ); ?></label>
							</th>
							<td>
								<input type="number" step="0.01" min="0" max="1" name="yoro_ai_preview_threshold" id="yoro_ai_preview_threshold" value="<?php echo esc_attr( $preview_threshold ); ?>" />
								<p class="description"><?php esc_html_e( 'AI-routed action results below this confidence will run as preview instead of executing immediately.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI command router', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_command_router_enabled" value="yes" <?php checked( $ai_command_router_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Route user commands through the AI action catalog before falling back to deterministic rules.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'AI Features', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Suggestions', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_enable_suggestions" value="yes" <?php checked( $suggestions_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Show next-step suggestions after YoRo actions.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI suggestions', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_suggestions_enabled" value="yes" <?php checked( $ai_suggestions_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Generate extra AI-powered next-step suggestions after actions.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'AI customer email composer', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_ai_email_compose_enabled" value="yes" <?php checked( $ai_email_compose_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Allow YoRo to draft customer emails with AI.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'Rate Limit', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_rate_limit_count"><?php esc_html_e( 'Max commands', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="number" min="1" name="yoro_rate_limit_count" id="yoro_rate_limit_count" value="<?php echo esc_attr( $rate_limit_count ); ?>" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_rate_limit_window"><?php esc_html_e( 'Window seconds', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="number" min="10" name="yoro_rate_limit_window" id="yoro_rate_limit_window" value="<?php echo esc_attr( $rate_limit_window ); ?>" />
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'Safety', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="yoro_undo_ttl"><?php esc_html_e( 'Undo window seconds', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="number" min="0" name="yoro_undo_ttl" id="yoro_undo_ttl" value="<?php echo esc_attr( $undo_ttl ); ?>" />
								<p class="description"><?php esc_html_e( 'Set 0 to disable expiry.', 'yoro-agent-core' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="yoro-agent-card">
					<h2><?php esc_html_e( 'YoRo Cloud', 'yoro-agent-core' ); ?></h2>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Cloud command router', 'yoro-agent-core' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="yoro_cloud_parser_enabled" value="yes" <?php checked( $cloud_parser_enabled, 'yes' ); ?> />
									<?php esc_html_e( 'Enable YoRo Cloud command routing when provider is set to YoRo Cloud.', 'yoro-agent-core' ); ?>
								</label>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_cloud_api_url"><?php esc_html_e( 'Cloud API URL', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="url" name="yoro_cloud_api_url" id="yoro_cloud_api_url" value="<?php echo esc_attr( $cloud_api_url ); ?>" class="regular-text" placeholder="https://api.yoro.ai/v1/parse" />
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="yoro_cloud_site_key"><?php esc_html_e( 'Cloud site key', 'yoro-agent-core' ); ?></label></th>
							<td>
								<input type="password" name="yoro_cloud_site_key" id="yoro_cloud_site_key" value="<?php echo esc_attr( $cloud_site_key ); ?>" class="regular-text" autocomplete="off" />
							</td>
						</tr>

						<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Cloud endpoint ready', 'yoro-agent-core' ); ?></th>
								<td>
									<label>
										<input type="checkbox" name="yoro_cloud_endpoint_ready" value="yes" <?php checked( $cloud_endpoint_ready, 'yes' ); ?> />
										<?php esc_html_e( 'Developer only. Enable only when the YoRo Cloud command routing endpoint is actually available.', 'yoro-agent-core' ); ?>
									</label>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>

				<?php submit_button( __( 'Save Settings', 'yoro-agent-core' ) ); ?>
			</form>

			<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) : ?>
				<div class="yoro-agent-card">
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
						<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'yoro-agent/v1/memory' ) ) ); ?>">
							View Conversation Memory
						</a>
					</p>
					
					<p>
						<a class="button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'yoro-agent/v1/actions' ) ) ); ?>">
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
						'page'         => 'yoro-agent-policies',
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

		$table = $wpdb->prefix . 'yoro_agent_policies';

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
				'page'         => 'yoro-agent-policies',
				'policy_saved' => false === $updated ? '0' : '1',
			],
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function handle_settings_save() {
		if ( empty( $_POST['yoro_agent_settings_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yoro_agent_settings_nonce'] ) ), 'yoro_agent_save_settings' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( class_exists( 'YoRo_AI_Settings' ) ) {
			YoRo_AI_Settings::save_admin_settings( $_POST );
		}

		add_action(
			'admin_notices',
			function() {
				echo '<div class="notice notice-success is-dismissible"><p>' .
					esc_html__( 'YoRo settings saved.', 'yoro-agent-core' ) .
					'</p></div>';
			}
		);
	}

	public static function filter_admin_footer_text( $text ) {
		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return $text;
		}

		// Main YoRo Agent page: remove footer text completely.
		if ( 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return '';
		}

		// YoRo Agent submenus: personalized footer.
		$yoro_screens = [
			'yoro-agent_page_yoro-agent-history',
			'yoro-agent_page_yoro-agent-policies',
			'yoro-agent_page_yoro-agent-settings',
		];

		if ( in_array( $screen->id, $yoro_screens, true ) ) {
			return sprintf(
				esc_html__( 'Powered by %s — built for smarter WooCommerce store management.', 'yoro-agent-core' ),
				'<strong>YoRo Agent</strong>'
			);
		}

		return $text;
	}	

	public static function filter_update_footer( $text ) {
		$screen = get_current_screen();

		if ( ! $screen || empty( $screen->id ) ) {
			return $text;
		}

		// Main YoRo Agent page: remove WordPress version.
		if ( 'toplevel_page_' . self::MENU_SLUG === $screen->id ) {
			return '';
		}

		// YoRo Agent submenus: personalized version text.
		$yoro_screens = [
			'yoro-agent_page_yoro-agent-history',
			'yoro-agent_page_yoro-agent-policies',
			'yoro-agent_page_yoro-agent-settings',
		];

		if ( in_array( $screen->id, $yoro_screens, true ) ) {
			return sprintf(
				esc_html__( 'YoRo Agent Core v%s', 'yoro-agent-core' ),
				esc_html( YORO_AGENT_CORE_VERSION )
			);
		}

		return $text;
	}
}
