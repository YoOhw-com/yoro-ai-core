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
		$this->table_name = YoRo_DB::logs_table_name();
	}

	public function get_columns() {
		return [
			'created_at' => __( 'Time', 'yoro-ai-core' ),
			'command'    => __( 'Action', 'yoro-ai-core' ),
			'result'     => __( 'What happened', 'yoro-ai-core' ),
			'status'     => __( 'State', 'yoro-ai-core' ),
			'undo'       => __( 'Undo', 'yoro-ai-core' ),
		];
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( $item['created_at'] );

			case 'command':
				$action = isset( $item['action'] ) ? (string) $item['action'] : '';

				if ( '' === $action ) {
					$command = isset( $item['command'] ) ? (string) $item['command'] : '';
					$action = preg_replace( '/^(direct|action|proactive):/', '', $command );
				}

				return esc_html( $this->action_label( $action ) );

			case 'result':
				return esc_html( $this->build_result_summary( $item ) );

			case 'status':
				return '<span class="yoro-history-status yoro-history-status-' . esc_attr( $item['status'] ) . '">' .
					$this->highlight( $this->status_label( $item['status'] ) ) .
				'</span>';

			case 'undo':
				return $this->render_undo_column( $item );
		}

		return '';
	}

	protected function get_bulk_actions() {
		return [];
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

	private function render_undo_column( $item ) {
		$can_undo = (
			'executed' === $item['status'] &&
			! empty( $item['undo_data'] ) &&
			empty( $item['undone'] )
		);

		if ( ! empty( $item['undone'] ) ) {
			return __( 'Undone', 'yoro-ai-core' );
		}

		if ( ! $can_undo ) {
			return '—';
		}

		return sprintf(
			'<button class="button button-small yoro-history-undo" data-log-id="%d">%s</button>',
			absint( $item['id'] ),
			__( 'Undo', 'yoro-ai-core' )
		);
	}

	private function build_result_summary( $item ) {
		$result = [];

		if ( ! empty( $item['result'] ) && is_string( $item['result'] ) ) {
			$decoded = maybe_unserialize( $item['result'] );

			if ( ! is_array( $decoded ) ) {
				$decoded = json_decode( $item['result'], true );
			}

			if ( is_array( $decoded ) ) {
				$result = $decoded;
			}
		}

		foreach ( [ 'message', 'summary', 'assistant_summary' ] as $key ) {
			if ( ! empty( $result[ $key ] ) && is_string( $result[ $key ] ) ) {
				return wp_trim_words( sanitize_text_field( $result[ $key ] ), 20 );
			}
		}

		$action = isset( $item['action'] ) ? str_replace( 'direct:', '', (string) $item['action'] ) : '';
		$status = sanitize_key( (string) ( $item['status'] ?? '' ) );

		$count = isset( $result['count'] ) ? absint( $result['count'] ) : 0;

		if ( $count > 0 && ! empty( $result['result_type'] ) ) {
			$result_type = sanitize_key( $result['result_type'] );

			if ( 'orders' === $result_type ) {
				return sprintf(
					/* translators: %d: order count */
					_n( 'Found %d order.', 'Found %d orders.', $count, 'yoro-ai-core' ),
					$count
				);
			}

			if ( 'customer_email' === $result_type ) {
				return __( 'Prepared a customer email draft.', 'yoro-ai-core' );
			}
		}

		return '' !== $action ? $this->action_label( $action ) : __( 'No summary available', 'yoro-ai-core' );
	}

	private function status_label( $status ) {
		$status = sanitize_key( (string) $status );

		$labels = [
			'executed'         => __( 'Done', 'yoro-ai-core' ),
			'success'          => __( 'Done', 'yoro-ai-core' ),
			'error'            => __( 'Needs attention', 'yoro-ai-core' ),
			'failed'           => __( 'Needs attention', 'yoro-ai-core' ),
		];

		return $labels[ $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
	}

	private function action_label( $action ) {
		$action = sanitize_text_field( (string) $action );

		$labels = [
			'orders.search'                 => __( 'Find orders', 'yoro-ai-core' ),
			'orders.get_details'            => __( 'Open order details', 'yoro-ai-core' ),
			'orders.update_status'          => __( 'Update order status', 'yoro-ai-core' ),
			'orders.add_note'               => __( 'Add internal note', 'yoro-ai-core' ),
			'orders.compose_customer_email' => __( 'Draft customer email', 'yoro-ai-core' ),
			'orders.send_customer_email'    => __( 'Send customer email', 'yoro-ai-core' ),
			'workflow.composed_actions'     => __( 'Multi-step request', 'yoro-ai-core' ),
		];

		return $labels[ $action ] ?? ucwords( str_replace( [ 'orders.', 'workflow.', '_' ], [ '', '', ' ' ], $action ) );
	}

	protected function extra_tablenav( $which ) {

		if ( 'top' !== $which ) {
			return;
		}

		$status = isset( $_REQUEST['filter_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter_status'] ) ) : '';
		$action = isset( $_REQUEST['filter_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) ) : '';

		?>
			<div class="alignleft actions yoro-history-filter-bar">

				<select name="filter_status">
					<option value=""><?php esc_html_e( 'All statuses', 'yoro-ai-core' ); ?></option>
					<option value="executed" <?php selected( $status, 'executed' ); ?>><?php esc_html_e( 'Done', 'yoro-ai-core' ); ?></option>
					<option value="error" <?php selected( $status, 'error' ); ?>><?php esc_html_e( 'Needs attention', 'yoro-ai-core' ); ?></option>
			</select>

				<select name="filter_action">
					<option value=""><?php esc_html_e( 'All task types', 'yoro-ai-core' ); ?></option>
					<option value="orders.search" <?php selected( $action, 'orders.search' ); ?>><?php esc_html_e( 'Find orders', 'yoro-ai-core' ); ?></option>
					<option value="orders.update_status" <?php selected( $action, 'orders.update_status' ); ?>><?php esc_html_e( 'Update status', 'yoro-ai-core' ); ?></option>
					<option value="orders.add_note" <?php selected( $action, 'orders.add_note' ); ?>><?php esc_html_e( 'Add note', 'yoro-ai-core' ); ?></option>
					<option value="orders.compose_customer_email" <?php selected( $action, 'orders.compose_customer_email' ); ?>><?php esc_html_e( 'Draft email', 'yoro-ai-core' ); ?></option>
				</select>

				<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'yoro-ai-core' ); ?>">

			</div>
			<?php
	}

	public function prepare_items() {
		global $wpdb;

		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = [];

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$allowed_statuses = [ 'executed', 'success', 'error', 'failed' ];
		$where = "WHERE action <> '' AND status IN ('" . implode( "','", array_map( 'esc_sql', $allowed_statuses ) ) . "')";
		$params = [];

		// Filter status
		if ( ! empty( $_REQUEST['filter_status'] ) ) {
			$filter_status = sanitize_key( wp_unslash( $_REQUEST['filter_status'] ) );

			if ( 'error' === $filter_status ) {
				$where .= " AND status IN ('error', 'failed')";
			} else {
				$where   .= ' AND status = %s';
				$params[] = $filter_status;
			}
		}

		// Filter action
		if ( ! empty( $_REQUEST['filter_action'] ) ) {
			$where   .= ' AND action = %s';
			$params[] = sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) );
		}

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		if ( $search ) {
			$keywords = $this->extract_keywords( $search );

			$search_parts = [];

			foreach ( $keywords as $kw ) {
				$search_parts[] = '(action LIKE %s OR result LIKE %s)';

				$like = '%' . $wpdb->esc_like( $kw ) . '%';

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
			echo '<tr>';
			$this->single_row_columns( $item );
			echo '</tr>';
		}
	}

	public function no_items() {
		esc_html_e( 'No YoRo action history found.', 'yoro-ai-core' );
	}
}
