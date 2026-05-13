<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class YoRo_History_Table extends WP_List_Table {

	private $table_name;

	public function __construct() {
		parent::__construct( [
			'singular' => 'yoro_log',
			'plural'   => 'yoro_logs',
			'ajax'     => false,
		] );

		global $wpdb;
		$this->table_name = $wpdb->prefix . 'yoro_agent_logs';
	}

	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />',
			'expand'    => '',
			'created_at' => __( 'Time', 'yoro-agent-core' ),
			'command'    => __( 'Command', 'yoro-agent-core' ),
			'intent'     => __( 'Intent', 'yoro-agent-core' ),
			'action'     => __( 'Action', 'yoro-agent-core' ),
			'source'     => __( 'Source', 'yoro-agent-core' ),
			'ai'         => __( 'AI', 'yoro-agent-core' ),
			'confidence' => __( 'Confidence', 'yoro-agent-core' ),
			'status'     => __( 'Status', 'yoro-agent-core' ),
			'undo'       => __( 'Undo', 'yoro-agent-core' ),
		];
	}

	protected function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="log_ids[]" value="%d" />',
			absint( $item['id'] )
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item['created_at'] );

			case 'command':
				$command = isset( $item['command'] ) ? (string) $item['command'] : '';
				$command = str_replace( 'direct:', '', $command );

				return '<code>' . $this->highlight( wp_trim_words( $command, 12 ) ) . '</code>';

			case 'action':
				$action = isset( $item['action'] ) ? (string) $item['action'] : '';
				$action = str_replace( 'direct:', '', $action );

				return $this->highlight( $action );

			case 'intent':
				return $this->highlight( $item['intent'] ?? '—' );

			case 'source':
				return $this->highlight( $item['source'] ?? '—' );

			case 'ai':
				$parts = [];

				if ( ! empty( $item['ai_feature'] ) ) {
					$parts[] = '<span class="yoro-history-badge yoro-history-badge-feature">' . $this->highlight( $item['ai_feature'] ) . '</span>';
				}

				if ( ! empty( $item['ai_model'] ) ) {
					$parts[] = '<span class="yoro-history-badge yoro-history-badge-model">' . $this->highlight( $item['ai_model'] ) . '</span>';
				}

				if ( empty( $parts ) ) {
					return '—';
				}

				$output = implode( '<br>', $parts );

				if ( ! empty( $item['prompt_version'] ) ) {
					$output .= '<br><span class="yoro-history-badge yoro-history-badge-prompt">' . $this->highlight( $item['prompt_version'] ) . '</span>';
				}

				if ( ! empty( $item['ai_trace_id'] ) ) {
					$trace_query = add_query_arg(
						[
							'page' => 'yoro-agent-history',
							's'    => $item['ai_trace_id'],
						],
						admin_url( 'admin.php' )
					);

					$output .= '<br><small><a href="' . esc_url( $trace_query ) . '">' . esc_html__( 'Trace', 'yoro-agent-core' ) . '</a></small>';
				}

				return $output;

			case 'confidence':
				if ( ! isset( $item['confidence'] ) || '' === $item['confidence'] || null === $item['confidence'] ) {
					return '—';
				}

				return esc_html( number_format_i18n( (float) $item['confidence'] * 100, 0 ) ) . '%';

			case 'status':
				return '<span class="yoro-history-status yoro-history-status-' . esc_attr( $item['status'] ) . '">' .
					$this->highlight( $item['status'] ) .
				'</span>';

			case 'expand':
				return '<button type="button" class="button button-small yoro-expand-row">+</button>';

			case 'undo':
				return $this->render_undo_column( $item );
		}

		return '';
	}

	protected function get_bulk_actions() {
		return [
			'bulk_undo' => __( 'Undo selected', 'yoro-agent-core' ),
		];
	}

	private function process_bulk_action() {

		if ( 'bulk_undo' !== $this->current_action() ) {
			return;
		}

		if ( empty( $_POST['log_ids'] ) || ! is_array( $_POST['log_ids'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		$log_ids = array_map( 'absint', $_POST['log_ids'] );

		foreach ( $log_ids as $log_id ) {
			$request = new WP_REST_Request( 'POST' );
			$request->set_param( 'log_id', $log_id );

			YoRo_REST_API::undo_last_action( $request );
		}

		// redirect tránh resubmit form
		wp_safe_redirect( add_query_arg( 'bulk_undone', count( $log_ids ) ) );
		exit;
	}

	private function highlight( $text ) {
		if ( empty( $_REQUEST['s'] ) ) {
			return esc_html( $text );
		}

		$keywords = $this->extract_keywords( $_REQUEST['s'] );

		$text_safe = esc_html( $text );

		foreach ( $keywords as $kw ) {
			$pattern = '/' . preg_quote( $kw, '/' ) . '/i';
			$text_safe = preg_replace( $pattern, '<mark>$0</mark>', $text_safe );
		}

		return $text_safe;
	}

	private function extract_keywords( $search ) {
		$search = sanitize_text_field( $search );
		$parts  = preg_split( '/\s+/', $search );

		$aliases = [
			'hodl' => 'hold',
		];

		$keywords = [];

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' === $part ) {
				continue;
			}

			$keywords[] = $part;

			if ( isset( $aliases[ strtolower( $part ) ] ) ) {
				$keywords[] = $aliases[ strtolower( $part ) ];
			}
		}

		return array_values( array_unique( $keywords ) );
	}

	private function fuzzy_keywords( $keywords ) {
		$expanded = [];

		foreach ( $keywords as $kw ) {
			$expanded[] = $kw;

			// đơn giản: bỏ 1 ký tự cuối (hodl → hod)
			if ( strlen( $kw ) > 3 ) {
				$expanded[] = substr( $kw, 0, -1 );
			}
		}

		return array_unique( $expanded );
	}

	private function render_undo_column( $item ) {
		$can_undo = (
			'executed' === $item['status'] &&
			! empty( $item['undo_data'] ) &&
			empty( $item['undone'] )
		);

		if ( ! empty( $item['undone'] ) ) {
			return __( 'Undone', 'yoro-agent-core' );
		}

		if ( ! $can_undo ) {
			return '—';
		}

		return sprintf(
			'<button class="button button-small yoro-history-undo" data-log-id="%d">%s</button>',
			absint( $item['id'] ),
			__( 'Undo', 'yoro-agent-core' )
		);
	}

	protected function extra_tablenav( $which ) {

		if ( 'top' !== $which ) {
			return;
		}

		$status = isset( $_REQUEST['filter_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter_status'] ) ) : '';
		$action = isset( $_REQUEST['filter_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) ) : '';
		$parser_source = isset( $_REQUEST['filter_parser_source'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter_parser_source'] ) ) : '';
		$risk_level = isset( $_REQUEST['filter_risk_level'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter_risk_level'] ) ) : '';
		$ai_feature = isset( $_REQUEST['filter_ai_feature'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter_ai_feature'] ) ) : '';
		$ai_model = isset( $_REQUEST['filter_ai_model'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_ai_model'] ) ) : '';
		$prompt_version = isset( $_REQUEST['filter_prompt_version'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_prompt_version'] ) ) : '';

		?>
		<div class="alignleft actions">

			<select name="filter_status">
				<option value=""><?php esc_html_e( 'All statuses', 'yoro-agent-core' ); ?></option>
				<option value="executed" <?php selected( $status, 'executed' ); ?>>Executed</option>
				<option value="preview" <?php selected( $status, 'preview' ); ?>>Preview</option>
				<option value="error" <?php selected( $status, 'error' ); ?>>Error</option>
			</select>

			<select name="filter_action">
				<option value=""><?php esc_html_e( 'All actions', 'yoro-agent-core' ); ?></option>
				<option value="orders.hold" <?php selected( $action, 'orders.hold' ); ?>>Hold</option>
				<option value="orders.add_note" <?php selected( $action, 'orders.add_note' ); ?>>Add note</option>
			</select>

			<select name="filter_parser_source">
				<option value=""><?php esc_html_e( 'All router sources', 'yoro-agent-core' ); ?></option>
				<option value="rule" <?php selected( $parser_source, 'rule' ); ?>>Rule</option>
				<option value="ai_router" <?php selected( $parser_source, 'ai_router' ); ?>>AI router</option>
				<option value="guard" <?php selected( $parser_source, 'guard' ); ?>>Guard</option>
				<option value="memory" <?php selected( $parser_source, 'memory' ); ?>>Memory</option>
				<option value="direct" <?php selected( $parser_source, 'direct' ); ?>>Direct</option>
			</select>

			<select name="filter_risk_level">
				<option value=""><?php esc_html_e( 'All risk levels', 'yoro-agent-core' ); ?></option>
				<option value="low" <?php selected( $risk_level, 'low' ); ?>>Low</option>
				<option value="medium" <?php selected( $risk_level, 'medium' ); ?>>Medium</option>
				<option value="high" <?php selected( $risk_level, 'high' ); ?>>High</option>
			</select>

			<select name="filter_ai_feature">
				<option value=""><?php esc_html_e( 'All AI features', 'yoro-agent-core' ); ?></option>
				<option value="parse" <?php selected( $ai_feature, 'parse' ); ?>>Parse</option>
				<option value="intent" <?php selected( $ai_feature, 'intent' ); ?>>Intent</option>
				<option value="workflow_plan" <?php selected( $ai_feature, 'workflow_plan' ); ?>>Workflow</option>
				<option value="email_compose" <?php selected( $ai_feature, 'email_compose' ); ?>>Email compose</option>
				<option value="suggestions" <?php selected( $ai_feature, 'suggestions' ); ?>>Suggestions</option>
			</select>

			<input
				type="text"
				name="filter_ai_model"
				value="<?php echo esc_attr( $ai_model ); ?>"
				placeholder="<?php esc_attr_e( 'AI model', 'yoro-agent-core' ); ?>"
			/>

			<input
				type="text"
				name="filter_prompt_version"
				value="<?php echo esc_attr( $prompt_version ); ?>"
				placeholder="<?php esc_attr_e( 'Prompt version', 'yoro-agent-core' ); ?>"
			/>

			<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'yoro-agent-core' ); ?>">

		</div>
		<?php
	}

	public function prepare_items() {
		global $wpdb;

		$this->process_bulk_action();

		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = [];

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$where = 'WHERE 1=1';
		$params = [];

		// Filter status
		if ( ! empty( $_REQUEST['filter_status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = sanitize_key( wp_unslash( $_REQUEST['filter_status'] ) );
		}

		// Filter action
		if ( ! empty( $_REQUEST['filter_action'] ) ) {
			$where   .= ' AND action = %s';
			$params[] = sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) );
		}

		if ( ! empty( $_REQUEST['filter_parser_source'] ) ) {
			$parser_source = sanitize_key( wp_unslash( $_REQUEST['filter_parser_source'] ) );
			$where .= ' AND telemetry LIKE %s';
			$params[] = '%"parser_source":"' . $wpdb->esc_like( $parser_source ) . '"%';
		}

		if ( ! empty( $_REQUEST['filter_risk_level'] ) ) {
			$risk_level = sanitize_key( wp_unslash( $_REQUEST['filter_risk_level'] ) );
			$where .= ' AND telemetry LIKE %s';
			$params[] = '%"risk_level":"' . $wpdb->esc_like( $risk_level ) . '"%';
		}

		if ( ! empty( $_REQUEST['filter_ai_feature'] ) ) {
			$ai_feature = sanitize_key( wp_unslash( $_REQUEST['filter_ai_feature'] ) );
			$where .= ' AND telemetry LIKE %s';
			$params[] = '%"ai_feature":"' . $wpdb->esc_like( $ai_feature ) . '"%';
		}

		if ( ! empty( $_REQUEST['filter_ai_model'] ) ) {
			$ai_model = sanitize_text_field( wp_unslash( $_REQUEST['filter_ai_model'] ) );
			$where .= ' AND telemetry LIKE %s';
			$params[] = '%"ai_model":"' . $wpdb->esc_like( $ai_model ) . '"%';
		}

		if ( ! empty( $_REQUEST['filter_prompt_version'] ) ) {
			$prompt_version = sanitize_text_field( wp_unslash( $_REQUEST['filter_prompt_version'] ) );
			$where .= ' AND telemetry LIKE %s';
			$params[] = '%"prompt_version":"' . $wpdb->esc_like( $prompt_version ) . '"%';
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		if ( $search ) {
			$keywords = $this->extract_keywords( $search );

			$search_parts = [];

			foreach ( $keywords as $kw ) {
				$search_parts[] = '(command LIKE %s OR action LIKE %s OR result LIKE %s OR params LIKE %s OR telemetry LIKE %s OR trace_id LIKE %s)';

				$like = '%' . $wpdb->esc_like( $kw ) . '%';

				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}

			if ( ! empty( $search_parts ) ) {
				$where .= ' AND (' . implode( ' OR ', $search_parts ) . ')';
			}
		}

		// COUNT
		$count_query = "SELECT COUNT(*) FROM {$this->table_name} {$where}";

		if ( ! empty( $params ) ) {
			$total_items = (int) $wpdb->get_var(
				$wpdb->prepare( $count_query, $params )
			);
		} else {
			$total_items = (int) $wpdb->get_var( $count_query );
		}

		// MAIN QUERY
		$query = "SELECT * FROM {$this->table_name} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

		$params_with_limit = array_merge( $params, [ $per_page, $offset ] );

		$items = $wpdb->get_results(
			$wpdb->prepare( $query, $params_with_limit ),
			ARRAY_A
		);

		$this->items = is_array( $items ) ? $items : [];

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			]
		);
	}

	public function display_rows() {
		$columns_count = count( $this->get_columns() );

		foreach ( $this->items as $item ) {
			$telemetry = [];

			if ( ! empty( $item['telemetry'] ) && is_string( $item['telemetry'] ) ) {
				$decoded = json_decode( $item['telemetry'], true );

				if ( is_array( $decoded ) ) {
					$telemetry = $decoded;
				}
			}

			$item['intent'] = sanitize_key( $telemetry['intent_name'] ?? '' );
			$item['source'] = sanitize_key( $telemetry['parser_source'] ?? '' );
			$item['confidence'] = isset( $telemetry['confidence'] ) ? (float) $telemetry['confidence'] : null;
			$item['ai_feature'] = sanitize_key( $telemetry['ai_feature'] ?? '' );
			$item['ai_model'] = sanitize_text_field( $telemetry['ai_model'] ?? '' );
			$item['prompt_version'] = sanitize_text_field( $telemetry['prompt_version'] ?? '' );
			$item['ai_provider'] = sanitize_key( $telemetry['ai_provider'] ?? '' );
			$item['ai_trace_id'] = sanitize_text_field( $telemetry['ai_trace_id'] ?? '' );

			echo '<tr>';
			$this->single_row_columns( $item );
			echo '</tr>';

			$undo = [];

			if ( ! empty( $item['undo_data'] ) && is_string( $item['undo_data'] ) ) {
				$decoded = json_decode( $item['undo_data'], true );

				if ( is_array( $decoded ) ) {
					$undo = $decoded;
				}
			}

			echo '<tr class="yoro-row-expanded" style="display:none;">';
			echo '<td colspan="' . esc_attr( $columns_count ) . '">';
			if ( ! empty( $item['ai_trace_id'] ) ) {
				$trace_query = add_query_arg(
					[
						'page' => 'yoro-agent-history',
						's'    => $item['ai_trace_id'],
					],
					admin_url( 'admin.php' )
				);

				echo '<p class="yoro-history-trace-links">';
				echo '<strong>' . esc_html__( 'AI Trace ID:', 'yoro-agent-core' ) . '</strong> ';
				echo '<code>' . esc_html( $item['ai_trace_id'] ) . '</code> ';
				echo '<a class="button button-small" href="' . esc_url( $trace_query ) . '">' . esc_html__( 'Search this trace', 'yoro-agent-core' ) . '</a>';
				echo '</p>';
			}
			echo '<pre class="yoro-json">';
			echo esc_html(
				wp_json_encode(
					[
						'trace_id'  => $item['trace_id'] ?? '',
						'telemetry' => $telemetry,
						'params'    => maybe_unserialize( $item['params'] ),
						'result'    => maybe_unserialize( $item['result'] ),
						'undo'      => $undo,
					],
					JSON_PRETTY_PRINT
				)
			);
			echo '</pre>';
			echo '</td>';
			echo '</tr>';
		}
	}

	public function no_items() {
		esc_html_e( 'No YoRo history found.', 'yoro-agent-core' );
	}
}
