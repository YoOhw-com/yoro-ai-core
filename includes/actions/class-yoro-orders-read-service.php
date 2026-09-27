<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Read_Service {

	/** Complete safe state for concurrency binding. No permission IDs or secret-bearing getters. */
	public static function download_regeneration_state( WC_Order $order ) {
		$guard = [ 'order_id' => $order->get_id(), 'eligible' => false, 'state_token' => null, 'current_version' => null,
			'blocked_reason' => 'unsafe_state', 'permission_count' => null, 'source_file_grant_count' => null ];
		$result = [ 'guard' => $guard, 'permissions' => [], 'sources' => [] ];
		try {
			if ( $order instanceof WC_Order_Refund || $order->get_id() < 1 ) { return $result; }
			$guard['current_version'] = YoRo_Order_Presenter::order_version( $order );
			$rows = WC_Data_Store::load( 'customer-download' )->get_downloads( [ 'order_id' => $order->get_id(), 'orderby' => 'permission_id', 'order' => 'ASC', 'return' => 'objects', 'limit' => 501, 'page' => 1 ] );
			if ( ! is_array( $rows ) ) { throw new UnexpectedValueException(); }
			if ( count( $rows ) > 500 ) { $guard['blocked_reason'] = 'permission_cap_exceeded'; return [ 'guard' => $guard ] + $result; }
			$permissions = [];
			$previous_id = 0;
			foreach ( $rows as $row ) {
				// Row IDs are used only to reject duplicate/out-of-order owner responses, never token material or output.
				if ( ! $row instanceof WC_Customer_Download || $row->get_id() <= $previous_id ) { throw new UnexpectedValueException(); }
				$previous_id = $row->get_id();
				$fact = YoRo_Order_Presenter::format_download_permission( $row, $order->get_id() );
				if ( null === $fact ) { throw new UnexpectedValueException(); }
				$permissions[] = $fact;
			}
			$guard['permission_count'] = count( $permissions );
			$items = $order->get_items( 'line_item' );
			if ( ! is_array( $items ) || count( $items ) > 200 ) { throw new UnexpectedValueException(); }
			$sources = [];
			foreach ( $items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) { throw new UnexpectedValueException(); }
				$product = $item->get_product();
				if ( ! $product || ! $product->exists() ) { throw new UnexpectedValueException(); }
				if ( ! $product->is_downloadable() ) { continue; }
				$quantity = $item->get_quantity();
				$limit = $product->get_download_limit();
				$expiry = $product->get_download_expiry();
				if ( ( ! is_int( $quantity ) && ! is_float( $quantity ) ) || ( is_float( $quantity ) && ! is_finite( $quantity ) )
					|| $quantity < 1 || $quantity > 1000000 || ( is_float( $quantity ) && $quantity !== (float) (int) $quantity )
					|| ! is_int( $limit ) || $limit < -1 || $limit > 1000000
					|| ! is_int( $expiry ) || $expiry < -1 || $expiry > 36500 ) { throw new UnexpectedValueException(); }
				$quantity = (int) $quantity;
				$files = $product->get_downloads();
				if ( ! is_array( $files ) ) { throw new UnexpectedValueException(); }
				foreach ( $files as $download_id => $file ) {
					if ( count( $sources ) === 200 ) { $guard['blocked_reason'] = 'source_cap_exceeded'; return [ 'guard' => $guard ] + $result; }
					if ( ! is_string( $download_id ) || ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/D', $download_id ) || ! $file instanceof WC_Product_Download || $file->get_id() !== $download_id ) { throw new UnexpectedValueException(); }
					$sources[] = [ 'item_id' => $item->get_id(), 'product_id' => $product->get_id(), 'variation_id' => $item->get_variation_id(),
						'quantity' => $quantity, 'download_id' => $download_id, 'download_limit' => $limit, 'download_expiry' => $expiry ];
				}
			}
			$guard['source_file_grant_count'] = count( $sources );
			$grant_setting = get_option( 'woocommerce_downloads_grant_access_after_payment' );
			$eligible = $order->is_download_permitted() && ( $order->has_status( 'completed' ) || ( $order->has_status( 'processing' ) && 'yes' === $grant_setting ) );
			$guard['blocked_reason'] = ! $eligible ? 'ineligible_order' : ( ! $sources ? 'no_source_grants' : 'eligible' );
			$guard['eligible'] = $eligible && (bool) $sources;
			$completed = $order->get_date_completed();
			$eligibility = [ 'status' => $order->get_status(), 'grant_after_payment' => 'yes' === $grant_setting, 'date_completed' => $completed ? $completed->format( 'Y-m-d' ) : null ];
			if ( $guard['eligible'] ) {
				$material = [ 'protocol' => 'yoro-download-regeneration-v1', 'blog_id' => get_current_blog_id(), 'order_id' => $order->get_id(),
					'version' => $guard['current_version'], 'eligibility' => $eligibility, 'permissions' => $permissions, 'sources' => $sources ];
				$guard['state_token'] = hash_hmac( 'sha256', wp_json_encode( $material ), wp_salt( 'auth' ) );
			}
			return [ 'guard' => $guard, 'permissions' => $permissions, 'sources' => $sources, 'eligibility' => $eligibility ];
		} catch ( Throwable $error ) {
			$guard['eligible'] = false; $guard['state_token'] = null; $guard['blocked_reason'] = 'unsafe_state';
			return [ 'guard' => $guard ] + $result;
		}
	}

	/** Read the owner store only; page and one-row lookahead keep offsets independent. */
	public static function get_download_permissions( $params ) {
		if ( ! current_user_can( 'yoro_view_store_data' ) ) {
			return new WP_Error( 'yoro_permission_denied', 'Permission to read store data is required.' );
		}
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'limit', 'page', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! isset( $params['order_id'] ) || ! is_int( $params['order_id'] ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'invalid_params', 'Choose exactly one positive integer order ID.' );
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) {
				return new WP_Error( 'invalid_params', 'Invalid execution flags.' );
			}
		}
		$limit = array_key_exists( 'limit', $params ) ? $params['limit'] : 20;
		$page = array_key_exists( 'page', $params ) ? $params['page'] : 1;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 50 || ! is_int( $page ) || $page < 1 || $page > 100 ) {
			return new WP_Error( 'invalid_params', 'Limit must be 1..50 and page 1..100, both integers.' );
		}
		if ( ! function_exists( 'wc_get_order' ) || ! class_exists( 'WC_Data_Store' ) ) {
			return new WP_Error( 'woocommerce_missing', 'Store integration is not active.' );
		}
		try {
			$order = wc_get_order( $params['order_id'] );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) {
				return new WP_Error( 'order_not_found', 'The selected order was not found.' );
			}
			$store = WC_Data_Store::load( 'customer-download' );
			$args = [ 'order_id' => $params['order_id'], 'orderby' => 'permission_id', 'order' => 'ASC', 'return' => 'objects' ];
			$rows = $store->get_downloads( $args + [ 'limit' => $limit, 'page' => $page ] );
			// WooCommerce calculates offset as limit*(page-1); limit+1 would skip rows.
			$probe = $store->get_downloads( $args + [ 'limit' => 1, 'page' => $limit * $page + 1 ] );
			if ( ! is_array( $rows ) || count( $rows ) > $limit || ! is_array( $probe ) || count( $probe ) > 1 ) {
				throw new UnexpectedValueException( 'Invalid owner response.' );
			}
			$permissions = [];
			foreach ( array_merge( array_values( $rows ), array_values( $probe ) ) as $index => $row ) {
				$permission = YoRo_Order_Presenter::format_download_permission( $row, $params['order_id'] );
				if ( null === $permission ) {
					throw new UnexpectedValueException( 'Unsafe owner row.' );
				}
				if ( $index < count( $rows ) ) { $permissions[] = $permission; }
			}
			$has_more = 1 === count( $probe );
			$facts = [ 'order_id' => $params['order_id'], 'permissions' => $permissions,
				'regeneration_guard' => self::download_regeneration_state( $order )['guard'],
				'pagination' => [ 'limit' => $limit, 'page' => $page, 'has_more' => $has_more, 'next_page' => $has_more && $page < 100 ? $page + 1 : null ],
				'coverage' => [ 'source' => 'woocommerce_customer_download', 'limitations' => YoRo_Order_Presenter::DOWNLOAD_PERMISSION_LIMITATIONS ],
			];
			return [ 'success' => true, 'action' => 'orders.get_download_permissions', 'download_permissions' => $facts,
				'read_only' => true, 'result_type' => 'generic', 'display_type' => 'generic' ];
		} catch ( Throwable $error ) {
			// Owner exceptions can contain SQL or secrets; never reflect their messages.
			return new WP_Error( 'download_permissions_unavailable', 'Download permissions are unavailable.' );
		}
	}

	/** Bounded primary-key traversal; unrelated rows consume the same scan budget. */
	public static function get_email_history( $params ) {
		if ( ! current_user_can( 'yoro_view_store_data' ) ) {
			return new WP_Error( 'yoro_permission_denied', 'Permission to read store data is required.' );
		}
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'limit', 'cursor', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! isset( $params['order_id'] ) || ! is_int( $params['order_id'] ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'invalid_params', 'Choose exactly one positive integer order ID.' );
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) {
				return new WP_Error( 'invalid_params', 'Invalid execution flags.' );
			}
		}
		$limit = $params['limit'] ?? 20;
		if ( ! is_int( $limit ) || $limit < 1 || $limit > 50 || ( array_key_exists( 'limit', $params ) && null === $params['limit'] ) ) {
			return new WP_Error( 'invalid_params', 'Limit must be an integer between 1 and 50.' );
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', 'Store integration is not active.' );
		}
		$order = wc_get_order( $params['order_id'] );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) {
			return new WP_Error( 'order_not_found', 'The selected order was not found.' );
		}
		global $wpdb;
		$table = YoRo_DB::logs_table_name();
		if ( array_key_exists( 'cursor', $params ) ) {
			$cursor = self::email_history_decode_cursor( $params['cursor'], $params['order_id'], $limit );
			if ( null === $cursor ) {
				return new WP_Error( 'invalid_cursor', 'The history cursor is invalid for this order and limit.' );
			}
			$snapshot = $cursor['snapshot'];
			$before = $cursor['before'];
		} else {
			// MAX(primary key) is bounded. Its eligible subset equals the eligible-row maximum's subset.
			$snapshot = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
			if ( $wpdb->last_error ) {
				return new WP_Error( 'email_history_unavailable', 'Local history is unavailable.' );
			}
			$before = $snapshot;
		}
		// LEFT bounds transfer and decoding even for legacy LONGTEXT; no serialized predicate or OFFSET.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, action, status, preview, created_at, LEFT(params,16385) AS params, LEFT(result,16385) AS result FROM {$table} WHERE id <= %d AND id <= %d ORDER BY id DESC LIMIT 501",
			$snapshot, $before
		), ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'email_history_unavailable', 'Local history is unavailable.' );
		}
		$events = [];
		$unsafe = 0;
		$scanned = 0;
		$has_more = count( $rows ) > 500 ? null : false;
		foreach ( array_slice( $rows, 0, 500 ) as $row ) {
			$scanned++;
			$event = self::email_history_event( $row, $params['order_id'], $unsafe );
			if ( null !== $event && count( $events ) === $limit ) {
				$has_more = true;
				// Do not consume the extra match: the next page must return this very row.
				$before = (int) $row['id'];
				break;
			}
			$before = max( 0, (int) $row['id'] - 1 );
			if ( null !== $event ) {
				$events[] = $event;
			}
		}
		$history = [
			'order_id' => $params['order_id'], 'events' => $events,
			'pagination' => [ 'limit' => $limit, 'has_more' => $has_more, 'scan_complete' => null !== $has_more,
				'next_cursor' => false === $has_more ? null : self::email_history_encode_cursor( $params['order_id'], $limit, $snapshot, $before ),
				'scanned_rows' => $scanned ],
			'coverage' => [ 'source' => 'local_yoro_action_log',
				'limitations' => [ 'local_terminal_actions_only', 'legacy_details_may_be_missing', 'remote_execution_receipts_excluded', 'delivery_not_observed' ],
				'warnings' => [ 'unsafe_rows_skipped' => $unsafe ] ],
		];
		return [ 'success' => true, 'action' => 'orders.get_email_history', 'email_history' => $history,
			'read_only' => true, 'result_type' => 'generic', 'display_type' => 'generic' ];
	}

	private static function email_history_encode_cursor( $order_id, $limit, $snapshot, $before ) {
		$payload = rtrim( strtr( base64_encode( wp_json_encode( [ 'v' => 1, 'site' => get_current_blog_id(), 'order' => $order_id, 'limit' => $limit, 'snapshot' => $snapshot, 'before' => $before ] ) ), '+/', '-_' ), '=' );
		return $payload . '.' . hash_hmac( 'sha256', 'yoro-email-history|' . $payload, wp_salt( 'auth' ) );
	}

	private static function email_history_decode_cursor( $cursor, $order_id, $limit ) {
		if ( ! is_string( $cursor ) || strlen( $cursor ) > 512 || ! preg_match( '/^[A-Za-z0-9_-]+\.[a-f0-9]{64}$/D', $cursor ) ) {
			return null;
		}
		list( $payload, $mac ) = explode( '.', $cursor, 2 );
		if ( ! hash_equals( hash_hmac( 'sha256', 'yoro-email-history|' . $payload, wp_salt( 'auth' ) ), $mac ) ) {
			return null;
		}
		$value = json_decode( base64_decode( strtr( $payload, '-_', '+/' ), true ), true, 4 );
		if ( ! is_array( $value ) || array_keys( $value ) !== [ 'v', 'site', 'order', 'limit', 'snapshot', 'before' ]
			|| 1 !== $value['v'] || get_current_blog_id() !== $value['site'] || $order_id !== $value['order'] || $limit !== $value['limit']
			|| ! is_int( $value['snapshot'] ) || ! is_int( $value['before'] ) || $value['snapshot'] < 0 || $value['before'] < 0 || $value['before'] > $value['snapshot'] ) {
			return null;
		}
		return $value;
	}

	/** Never use maybe_unserialize: disallow class instantiation and bound bytes, depth and nodes. */
	private static function email_history_decode_row( $raw ) {
		if ( ! is_string( $raw ) || strlen( $raw ) > 16384 || ! preg_match( '/^a:\d+:\{/', $raw ) ) {
			return null;
		}
		$value = @unserialize( $raw, [ 'allowed_classes' => false, 'max_depth' => 6 ] );
		if ( ! is_array( $value ) ) {
			return null;
		}
		$nodes = 0;
		$queue = [ [ $value, 0 ] ];
		while ( $queue ) {
			list( $part, $depth ) = array_pop( $queue );
			if ( ++$nodes > 256 || $depth > 6 || is_object( $part ) || is_resource( $part ) ) {
				return null;
			}
			if ( is_array( $part ) ) {
				foreach ( $part as $child ) {
					$queue[] = [ $child, $depth + 1 ];
				}
			}
		}
		return serialize( $value ) === $raw ? $value : null;
	}

	private static function email_history_event( $row, $order_id, &$unsafe ) {
		if ( ! in_array( $row['action'], [ 'orders.send_customer_email', 'orders.email_customer_template', 'orders.send_transactional_email' ], true )
			|| ! in_array( $row['status'], [ 'executed', 'error', 'failed' ], true ) || ! empty( $row['preview'] ) ) {
			return null;
		}
		$params = self::email_history_decode_row( $row['params'] );
		if ( null === $params ) {
			$unsafe++;
			return null;
		}
		// Legacy email entrypoints accepted canonical decimal strings; match without absint coercion.
		foreach ( [ 'order_id', 'order_ids' ] as $key ) {
			if ( ! array_key_exists( $key, $params ) ) { continue; }
			$values = 'order_id' === $key ? [ $params[ $key ] ] : $params[ $key ];
			if ( ! is_array( $values ) || count( $values ) !== 1 ) { return null; }
			$value = reset( $values );
			if ( is_string( $value ) && ctype_digit( $value ) && (string) (int) $value === $value ) { $value = (int) $value; }
			if ( ! is_int( $value ) || $value < 1 ) { return null; }
			$params[ $key ] = 'order_id' === $key ? $value : [ $value ];
		}
		$ids = array_key_exists( 'order_id', $params ) ? [ $params['order_id'] ] : ( $params['order_ids'] ?? [] );
		if ( ! is_array( $ids ) || array_values( $ids ) !== [ $order_id ]
			|| ( array_key_exists( 'order_ids', $params ) && ( ! is_array( $params['order_ids'] ) || array_values( $params['order_ids'] ) !== [ $order_id ] ) ) ) {
			return null;
		}
		$result = null === $row['result'] || '' === $row['result'] ? [] : self::email_history_decode_row( $row['result'] );
		if ( null === $result ) {
			$unsafe++;
			return null;
		}
		$outcome = $result['email_observation']['mailer_outcome'] ?? ( $result['outcome'] ?? ( $result['data']['outcome'] ?? 'not_recorded' ) );
		if ( 'transactional_email_outcome_unknown' === ( $result['error']['code'] ?? null ) || 'transactional_email_outcome_unknown' === ( $result['errors'][0]['code'] ?? null ) ) {
			$outcome = 'unknown';
		}
		if ( ! in_array( $outcome, [ 'mailer_accepted', 'unknown' ], true ) ) {
			$outcome = 'not_recorded';
		}
		if ( ! is_string( $row['created_at'] ) || ! preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $row['created_at'] ) ) {
			$unsafe++;
			return null;
		}
		return [ 'log_id' => (int) $row['id'], 'occurred_at' => $row['created_at'],
			'action' => $row['action'], 'action_status' => 'executed' === $row['status'] && false !== ( $result['success'] ?? null ) ? 'success' : 'failure',
			'mailer_outcome' => $outcome, 'evidence_quality' => 'not_recorded' === $outcome ? 'legacy_action_result' : 'explicit_mailer_outcome', 'delivery_status' => 'not_observed' ];
	}

	public static function get_search_result_limit( $params ) {
		$params = is_array( $params ) ? $params : [];
		$limit = isset( $params['limit'] )
			? absint( $params['limit'] )
			: 10;

		$global_limit = absint( get_option( 'yoro_ai_max_result_limit', 20 ) );

		if ( $global_limit <= 0 ) {
			$global_limit = 20;
		}

		return min( max( $limit, 1 ), $global_limit, 50 );
	}

	public static function search_orders( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$status = $params['status'] ?? [];

		if ( is_string( $status ) ) {
			$status = '' !== trim( $status ) ? [ $status ] : [];
		}

		$status = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $status )
			)
		);

		$result_limit = self::get_search_result_limit( $params );

		$args = [
			'limit' => $result_limit,
			'type' => 'shop_order',
			'orderby' => 'date',
			'order'   => 'DESC',
		];

		if ( ! empty( $status ) ) {
			$args['status'] = array_map( 'sanitize_key', (array) $status );
		}

		if ( ! empty( $params['order_id'] ) ) {
			$args['include'] = [ absint( $params['order_id'] ) ];
			$args['limit']   = 1;
			$result_limit    = 1;
			unset( $args['status'] );
		}

		if ( ! empty( $params['email'] ) ) {
			$args['billing_email'] = sanitize_email( $params['email'] );
		}

		if ( ! empty( $params['customer_id'] ) ) {
			$args['customer_id'] = absint( $params['customer_id'] );
		}

		if ( ! empty( $params['payment_method'] ) ) {
			$args['payment_method'] = sanitize_key( $params['payment_method'] );
		}

		if ( ! empty( $params['billing_country'] ) ) {
			$args['billing_country'] = strtoupper( sanitize_text_field( $params['billing_country'] ) );
		}

		if ( ! empty( $params['shipping_state'] ) ) {
			$args['shipping_state'] = strtoupper( sanitize_text_field( $params['shipping_state'] ) );
		}

		$min_age_hours = ! empty( $params['min_age_hours'] )
			? min( max( absint( $params['min_age_hours'] ), 1 ), 8760 )
			: 0;
		$date_window = YoRo_Date_Helper::window_key_from_params( $params, 'date_window', '' );
		$resolved_window = $date_window ? YoRo_Date_Helper::resolve_window( $date_window ) : [];

		if ( $min_age_hours > 0 ) {
			$args['date_created'] = '<=' . self::build_relative_cutoff( '-' . $min_age_hours . ' hours' );
			$resolved_window = [];
		} elseif ( ! empty( $resolved_window ) ) {
			$args['date_created'] = $resolved_window['query'];
		} elseif ( ! empty( $params['date_from'] ) || ! empty( $params['date_to'] ) ) {
			$date_from = ! empty( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : '';
			$date_to   = ! empty( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : '';

			if ( $date_from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
				$date_from .= ' 00:00:00';
			}

			if ( $date_to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
				$date_to .= ' 23:59:59';
			}

			if ( $date_from && $date_to ) {
				$args['date_created'] = $date_from . '...' . $date_to;
			} elseif ( $date_from ) {
				$args['date_created'] = '>=' . $date_from;
			} elseif ( $date_to ) {
				$args['date_created'] = '<=' . $date_to;
			}
		}

		$post_filter_scan = [];
		$orders = self::query_filtered_orders( $args, $params, $result_limit, $post_filter_scan );

		return self::format_search_result( $orders, $params, $resolved_window, $min_age_hours, $post_filter_scan );
	}

	public static function format_search_result( $orders, $params, $resolved_window = [], $min_age_hours = 0, $post_filter_scan = [] ) {
		$orders = is_array( $orders ) ? $orders : [];
		$params = is_array( $params ) ? $params : [];
		$resolved_window = is_array( $resolved_window ) ? $resolved_window : [];
		$min_age_hours = absint( $min_age_hours );
		$post_filter_scan = is_array( $post_filter_scan ) ? $post_filter_scan : [];
		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		$response = [
			'success'      => true,
			'action'       => 'orders.search',
			'summary'      => self::build_search_summary( count( $data ), $params, $resolved_window ),
			'count'        => count( $data ),
			'read_only'    => true,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];

		if ( ! empty( $post_filter_scan ) ) {
			$response['post_filter_scan'] = $post_filter_scan;
		}

		if ( empty( $data ) ) {
			$response['empty_state'] = self::build_orders_search_empty_state( $params, $resolved_window );
		}

		if ( ! empty( $resolved_window ) ) {
			$response['date_window'] = YoRo_Date_Helper::build_window_payload( $resolved_window );
		}

		if ( $min_age_hours > 0 ) {
			$locale = sanitize_key( $params['response_language'] ?? '' ) ?: 'en';
			$response['min_age_hours'] = $min_age_hours;
			$response['age_filter_label'] = YoRo_Response_UX_Formatter::age_threshold_label(
				$min_age_hours,
				[
					'command' => $params['original_command'] ?? '',
				],
				$locale
			);
		}

		return $response;
	}

	private static function query_filtered_orders( $args, $params, $result_limit, &$scan_meta = [] ) {
		$args         = is_array( $args ) ? $args : [];
		$params       = is_array( $params ) ? $params : [];
		$result_limit = min( max( absint( $result_limit ), 1 ), 50 );
		$scan_meta    = [];

		if ( ! self::has_post_query_filters( $params ) || ! empty( $args['include'] ) ) {
			$orders = wc_get_orders( $args );
			$orders = self::apply_post_query_filters( $orders, $params );

			return array_slice( array_values( $orders ), 0, $result_limit );
		}

		$page_size  = min( 50, max( $result_limit * 3, 20 ) );
		$scan_limit = self::get_post_filter_scan_limit( $result_limit );
		$matched    = [];
		$seen_ids   = [];
		$scanned    = 0;
		$page       = 1;
		$base_args  = $args;

		unset( $base_args['offset'] );

		while ( $scanned < $scan_limit && count( $matched ) < $result_limit ) {
			$query_args = $base_args;
			$query_args['limit']  = min( $page_size, $scan_limit - $scanned );
			$query_args['offset'] = ( $page - 1 ) * $page_size;

			if ( $query_args['limit'] <= 0 ) {
				break;
			}

			$page_orders = wc_get_orders( $query_args );
			$page_orders = is_array( $page_orders ) ? $page_orders : [];
			$page_count  = count( $page_orders );

			if ( 0 === $page_count ) {
				break;
			}

			$scanned += $page_count;

			foreach ( self::apply_post_query_filters( $page_orders, $params ) as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}

				$order_id = $order->get_id();

				if ( isset( $seen_ids[ $order_id ] ) ) {
					continue;
				}

				$seen_ids[ $order_id ] = true;
				$matched[] = $order;

				if ( count( $matched ) >= $result_limit ) {
					break;
				}
			}

			if ( $page_count < $page_size ) {
				break;
			}

			$page++;
		}

		$scan_meta = [
			'applied'       => true,
			'scanned'       => $scanned,
			'limit'         => $scan_limit,
			'matched'       => count( $matched ),
			'result_limit'  => $result_limit,
			'filters'       => self::post_query_filter_keys( $params ),
		];

		return array_slice( array_values( $matched ), 0, $result_limit );
	}

	private static function has_post_query_filters( $params ) {
		return ! empty( self::post_query_filter_keys( $params ) );
	}

	private static function post_query_filter_keys( $params ) {
		$params = is_array( $params ) ? $params : [];
		$keys   = [];

		if ( ! empty( $params['shipping_state'] ) ) {
			$keys[] = 'shipping_state';
		}

		if ( ! empty( $params['phone'] ) ) {
			$keys[] = 'phone';
		}

		if ( ! empty( $params['customer_name'] ) ) {
			$keys[] = 'customer_name';
		}

		if ( isset( $params['total_min'] ) || isset( $params['total_max'] ) ) {
			$keys[] = 'order_total';
		}

		return $keys;
	}

	private static function get_post_filter_scan_limit( $result_limit ) {
		$configured = absint( get_option( 'yoro_order_search_post_filter_scan_limit', 500 ) );

		if ( $configured <= 0 ) {
			$configured = 500;
		}

		return min( max( $configured, max( absint( $result_limit ) * 20, 100 ) ), 1000 );
	}

	private static function apply_post_query_filters( $orders, $params ) {
		$orders = is_array( $orders ) ? $orders : [];
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['shipping_state'] ) ) {
			$shipping_state = strtoupper( sanitize_text_field( $params['shipping_state'] ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $shipping_state ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					return strtoupper( (string) $order->get_shipping_state() ) === $shipping_state;
				}
			);
		}

		if ( ! empty( $params['phone'] ) ) {
			$needle = preg_replace( '/\D+/', '', (string) $params['phone'] );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$phone = preg_replace( '/\D+/', '', (string) $order->get_billing_phone() );

					return $needle && false !== strpos( $phone, $needle );
				}
			);
		}

		if ( ! empty( $params['customer_name'] ) ) {
			$name_needle = strtolower( trim( sanitize_text_field( $params['customer_name'] ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						false !== strpos( $billing_name, $name_needle ) ||
						false !== strpos( $shipping_name, $name_needle )
					);
				}
			);
		}

		if ( isset( $params['total_min'] ) || isset( $params['total_max'] ) ) {
			$total_min = isset( $params['total_min'] ) && '' !== $params['total_min']
				? (float) $params['total_min']
				: null;

			$total_max = isset( $params['total_max'] ) && '' !== $params['total_max']
				? (float) $params['total_max']
				: null;

			$orders = array_filter(
				$orders,
				function( $order ) use ( $total_min, $total_max ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$total = (float) $order->get_total();

					if ( null !== $total_min && $total < $total_min ) {
						return false;
					}

					if ( null !== $total_max && $total > $total_max ) {
						return false;
					}

					return true;
				}
			);
		}

		return array_values( $orders );
	}

	private static function build_orders_search_empty_state( $params, $resolved_window = [] ) {
		$params = is_array( $params ) ? $params : [];
		$locale = sanitize_key( $params['response_language'] ?? '' ) ?: 'en';
		$is_vietnamese = 'vi' === $locale;
		$phrases = YoRo_Response_UX_Formatter::order_search_filter_phrases(
			$params,
			$resolved_window,
			[
				'command' => $params['original_command'] ?? '',
			],
			$locale
		);
		$criteria = ! empty( $phrases ) ? implode( ', ', $phrases ) : '';

		if ( $is_vietnamese ) {
			return [
				'title'       => __( 'Không tìm thấy đơn hàng phù hợp.', 'yoro-ai-core' ),
				'description' => '' !== $criteria
					/* translators: %s: Value generated from store data. */
					? sprintf( __( 'Không có đơn hàng nào khớp với bộ lọc: %s.', 'yoro-ai-core' ), $criteria )
					: __( 'Không có đơn hàng nào khớp với yêu cầu này.', 'yoro-ai-core' ),
				'actions'     => [
					[
						'label'   => __( 'Xem 5 đơn mới nhất', 'yoro-ai-core' ),
						'command' => 'Show latest 5 orders',
					],
				],
			];
		}

		return [
			'title'       => __( 'No matching orders found.', 'yoro-ai-core' ),
			'description' => '' !== $criteria
				/* translators: %s: Value generated from store data. */
				? sprintf( __( 'No orders matched these filters: %s.', 'yoro-ai-core' ), $criteria )
				: __( 'No orders matched this request.', 'yoro-ai-core' ),
			'actions'     => [
				[
					'label'   => __( 'Show latest 5 orders', 'yoro-ai-core' ),
					'command' => 'Show latest 5 orders',
				],
			],
		];
	}

	private static function build_search_summary( $count, $params, $resolved_window = [] ) {
		$count = absint( $count );
		$response_language = sanitize_key( $params['response_language'] ?? '' );
		$locale = $response_language ?: 'en';

		return YoRo_Response_UX_Formatter::order_search_summary(
			'found',
			$count,
			$params,
			$resolved_window,
			[
				'command' => $params['original_command'] ?? '',
			],
			$locale
		);
	}

	private static function first_status_filter( $params ) {
		$params = is_array( $params ) ? $params : [];

		if ( empty( $params['status'] ) ) {
			return '';
		}

		$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
		$status = reset( $status_values );

		return sanitize_key( $status );
	}

	public static function get_payment_context( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! isset( $params['order_id'] ) || ! is_int( $params['order_id'] ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'invalid_params', __( 'Choose exactly one positive integer order ID.', 'yoro-ai-core' ) );
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) {
				return new WP_Error( 'invalid_params', __( 'Invalid execution flags.', 'yoro-ai-core' ) );
			}
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}
		$order = wc_get_order( $params['order_id'] );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}
		$payment_context = YoRo_Order_Presenter::format_payment_context( $order );
		if ( null === $payment_context ) {
			return new WP_Error( 'payment_context_unavailable', __( 'The order payment context is unavailable.', 'yoro-ai-core' ) );
		}
		return [
			'success' => true,
			'action' => 'orders.get_payment_context',
			'summary' => 'WooCommerce order payment facts; independent gateway verification is not observed.',
			'payment_context' => $payment_context,
			'read_only' => true,
			'result_type' => 'payment_context',
			'display_type' => 'payment_context',
		];
	}

	public static function get_payment_link( $params ) {
		if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! isset( $params['order_id'] ) || ! is_int( $params['order_id'] ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'invalid_params', __( 'Choose exactly one positive integer order ID.', 'yoro-ai-core' ) );
		}
		foreach ( [ '_yoro_confirmed', '_yoro_preview' ] as $flag ) {
			if ( array_key_exists( $flag, $params ) && ! is_bool( $params[ $flag ] ) ) {
				return new WP_Error( 'invalid_params', __( 'Invalid execution flags.', 'yoro-ai-core' ) );
			}
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}
		$order = wc_get_order( $params['order_id'] );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id'] ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}
		$needs_payment = (bool) $order->needs_payment();
		$url = $needs_payment ? $order->get_checkout_payment_url() : null;
		$link = YoRo_Commerce_Contract_Builder::normalize_payment_link( [
			'order_id' => $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
			'status' => (string) $order->get_status(),
			'needs_payment' => $needs_payment,
			'payment_link_available' => $needs_payment && is_string( $url ) && '' !== $url,
			'payment_url' => $url,
		] );
		if ( null === $link ) {
			return new WP_Error( 'payment_link_unavailable', __( 'The order payment link is unavailable.', 'yoro-ai-core' ) );
		}
		return [
			'success' => true,
			'action' => 'orders.get_payment_link',
			'payment_link' => $link,
			'read_only' => true,
		];
	}


	public static function get_order_details( $params ) {
		$order_ids = YoRo_Order_Helper::normalize_order_ids( $params );

			if ( empty( $order_ids ) ) {
				return new WP_Error(
					'invalid_params',
					__( 'Please choose an order first.', 'yoro-ai-core' )
				);
			}

		$data = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$data[] = YoRo_Order_Presenter::format_order_details( $order );
		}

			if ( empty( $data ) ) {
				return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
				);
			}

		$focused_response = self::focused_order_details_response( $params, $data );

		if ( ! empty( $focused_response ) ) {
			return $focused_response;
		}

		return [
			'success'      => true,
			'action'       => 'orders.get_details',
			'summary'      => sprintf( 'Order details: %d', count( $data ) ),
			'count'        => count( $data ),
			'read_only'    => true,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'order_details',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	private static function focused_order_details_response( $params, array $orders ) {
		$response_focus = sanitize_key( $params['response_focus'] ?? '' );

		if ( 'order_status' !== $response_focus || 1 !== count( $orders ) ) {
			return [];
		}

		$order = reset( $orders );

		if ( ! is_array( $order ) ) {
			return [];
		}

		$order_id = absint( $order['id'] ?? ( $params['order_id'] ?? 0 ) );
		$status   = sanitize_text_field( $order['status_label'] ?? '' );

		if ( '' === $status ) {
			$status = self::humanize_status_key( $order['status'] ?? '' );
		}

		if ( ! $order_id || '' === $status ) {
			return [];
		}

		$message = self::focused_order_status_message(
			$order_id,
			$status,
			sanitize_key( $params['response_language'] ?? '' )
		);

		return [
			'success'          => true,
			'action'           => 'orders.get_details',
			'summary'          => $message,
			'message'          => $message,
			'count'            => 1,
			'read_only'        => true,
			'order'            => $order,
			'orders'           => $orders,
			'response_focus'   => 'order_status',
			'requested_fields' => is_array( $params['requested_fields'] ?? null )
				? array_values( array_map( 'sanitize_key', $params['requested_fields'] ) )
				: [ 'status', 'status_label' ],
			'result_type'      => 'assistant_message',
			'display_type'     => 'assistant_message',
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
		];
	}

	private static function focused_order_status_message( $order_id, $status, $language ) {
		if ( 0 === strpos( strtolower( (string) $language ), 'vi' ) ) {
			return sprintf( 'Đơn #%d đang ở trạng thái %s.', $order_id, $status );
		}

		return sprintf( 'Order #%d is currently %s.', $order_id, $status );
	}

	private static function humanize_status_key( $status ) {
		$status = sanitize_key( $status );
		$status = preg_replace( '/^wc-/', '', $status );
		$status = str_replace( [ '-', '_' ], ' ', $status );

		return ucwords( trim( $status ) );
	}

	public static function get_operations_summary( $params ) {
		return YoRo_Store_Operations_Service::get_operations_summary( $params );
	}

	public static function get_operations_attention_queue( $params ) {
		return YoRo_Store_Operations_Service::get_operations_attention_queue( $params );
	}

	public static function get_fulfillment_queue( $params ) {
		return YoRo_Store_Fulfillment_Service::get_fulfillment_queue( $params );
	}

	public static function get_inventory_queue( $params ) {
		return YoRo_Store_Inventory_Service::get_inventory_queue( $params );
	}

	public static function get_store_insights( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );
		$limit      = isset( $params['limit'] ) ? absint( $params['limit'] ) : 5;
		$limit      = min( max( $limit, 1 ), 20 );

		$orders = self::report_query_orders(
			[
				'status'       => self::get_revenue_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$order_status_counts = [];
		$customer_map        = [];
		$product_map         = [];
		$category_map        = [];
		$category_cache      = [];
		$order_total         = 0;
		$order_count         = 0;
		$items_sold          = 0.0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$order_count++;
			$order_total += (float) $order->get_total();

			$status = $order->get_status();
			$order_status_counts[ $status ] = isset( $order_status_counts[ $status ] )
				? $order_status_counts[ $status ] + 1
				: 1;

			$email = strtolower( sanitize_email( $order->get_billing_email() ) );

			if ( $email ) {
				if ( ! isset( $customer_map[ $email ] ) ) {
					$customer_map[ $email ] = [
						'email'       => YoRo_Order_Presenter::mask_email( $email ),
						'name'        => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
						'order_count' => 0,
						'total_spent' => 0,
					];
				}

				$customer_map[ $email ]['order_count']++;
				$customer_map[ $email ]['total_spent'] += (float) $order->get_total();
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$key     = $product instanceof WC_Product ? $product->get_id() : $item->get_name();
				$product_id = $item->get_product_id();
				$category_context = self::get_product_category_context( $product_id, $category_cache );

				if ( ! isset( $product_map[ $key ] ) ) {
					$product_map[ $key ] = [
						'id'         => $product instanceof WC_Product ? $product->get_id() : 0,
						'name'       => $item->get_name(),
						'sku'        => $product instanceof WC_Product ? $product->get_sku() : '',
						'category'   => $category_context['primary_name'] ?? '',
						'categories' => array_map(
							function( $category ) {
								return sanitize_text_field( $category['name'] ?? '' );
							},
							is_array( $category_context['categories'] ?? null ) ? $category_context['categories'] : []
						),
						'quantity'   => 0,
						'total'      => 0,
					];
				}

				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$items_sold += $quantity;
				$product_map[ $key ]['quantity'] += $quantity;
				$product_map[ $key ]['total'] += $line_total;
				self::record_category_mix( $category_map, $category_context, $key, $quantity, $line_total );
			}
		}

		$top_customers = array_values( $customer_map );
		usort(
			$top_customers,
			function( $a, $b ) {
				return ( $b['total_spent'] ?? 0 ) <=> ( $a['total_spent'] ?? 0 );
			}
		);
		$top_customers = array_slice( $top_customers, 0, $limit );

		foreach ( $top_customers as &$customer ) {
			$customer['total_spent'] = wc_format_decimal( $customer['total_spent'], 2 );
		}
		unset( $customer );

		$top_products = array_values( $product_map );
		usort(
			$top_products,
			function( $a, $b ) {
				return ( $b['quantity'] ?? 0 ) <=> ( $a['quantity'] ?? 0 );
			}
		);
		$top_products = array_slice( $top_products, 0, $limit );

		foreach ( $top_products as &$product ) {
			$product['total'] = wc_format_decimal( $product['total'], 2 );
			$product['categories'] = array_values( array_filter( is_array( $product['categories'] ?? null ) ? $product['categories'] : [] ) );
		}
		unset( $product );
		$top_categories = self::format_category_mix( $category_map, $limit );

		$inventory_counts = [
			'out_of_stock' => self::count_products_by_stock_status( 'outofstock' ),
			'low_stock'    => self::count_low_stock_products(),
			'on_backorder' => self::count_products_by_stock_status( 'onbackorder' ),
		];

		$average_order_value = $order_count > 0 ? $order_total / $order_count : 0;
		$repeat_customer_count = count(
			array_filter(
				$customer_map,
				function( $customer ) {
					return is_array( $customer ) && absint( $customer['order_count'] ?? 0 ) > 1;
				}
			)
		);
		$repeat_customer_rate = count( $customer_map ) > 0 ? ( $repeat_customer_count / count( $customer_map ) ) * 100 : 0;
		$items_per_order      = $order_count > 0 ? $items_sold / $order_count : 0;
		$previous_window = YoRo_Date_Helper::build_previous_window( $window );
		$previous_signal_metrics = self::summarize_store_signal_window( $previous_window );
		$comparison = self::build_store_signal_comparison(
			[
				'orders'              => $order_count,
				'gross_sales'         => $order_total,
				'average_order_value' => $average_order_value,
				'unique_customers'    => count( $customer_map ),
				'repeat_customers'    => $repeat_customer_count,
				'repeat_customer_rate' => $repeat_customer_rate,
				'items_sold'          => $items_sold,
				'items_per_order'     => $items_per_order,
				'product_lines'       => count( $product_map ),
				'product_categories'  => count( $category_map ),
			],
			$previous_signal_metrics,
			$previous_window
		);

		$insights = [
			[
				'key'     => 'orders',
				'label'   => __( 'Orders', 'yoro-ai-core' ),
				'metrics' => [
					'order_count'         => $order_count,
					'gross_sales'         => wc_format_decimal( $order_total, 2 ),
					'average_order_value' => wc_format_decimal( $average_order_value, 2 ),
				],
				'items'   => self::map_counts_to_items( $order_status_counts ),
			],
			[
				'key'     => 'customers',
				'label'   => __( 'Customers', 'yoro-ai-core' ),
				'metrics' => [
					'unique_customers' => count( $customer_map ),
					'repeat_customers' => $repeat_customer_count,
					'repeat_customer_rate' => wc_format_decimal( $repeat_customer_rate, 1 ),
				],
				'items'   => $top_customers,
			],
			[
				'key'     => 'products',
				'label'   => __( 'Products', 'yoro-ai-core' ),
				'metrics' => $inventory_counts,
				'category_mix' => $top_categories,
				'items'   => $top_products,
			],
		];
		$report = self::build_store_insights_report(
			$window,
			$order_count,
			$order_total,
			$average_order_value,
			count( $customer_map ),
			$repeat_customer_count,
			$repeat_customer_rate,
			count( $product_map ),
			count( $category_map ),
			$items_sold,
			$items_per_order,
			$inventory_counts,
			$top_customers,
			$top_products,
			$top_categories,
			$comparison
		);

		return [
			'success'      => true,
			'action'       => 'store.get_insights',
			'summary'      => sprintf(
				'Store signal report for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
				$window['label'],
				$order_count,
				count( $customer_map ),
				count( $product_map )
			),
			'message'      => sprintf(
				'Store signal report for %1$s: %2$d orders, %3$d customers, %4$d product lines.',
				$window['label'],
				$order_count,
				count( $customer_map ),
				count( $product_map )
			),
			'count'        => $order_count,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [
				'window'          => $window,
				'limit_per_group' => $limit,
				'currency'        => get_woocommerce_currency(),
				'changes_store'   => false,
			],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'window'       => YoRo_Date_Helper::build_window_payload( $window ),
			'insights'     => $insights,
			'top_products' => $top_products,
			'top_categories' => $top_categories,
			'comparison'   => $comparison,
			'report'       => $report,
			'result_type'  => 'store_insights',
			'display_type' => 'store_insights',
		];
	}

	private static function summarize_store_signal_window( $window ) {
		$summary = [
			'orders'              => 0,
			'gross_sales'         => 0.0,
			'average_order_value' => 0.0,
			'unique_customers'    => 0,
			'repeat_customers'    => 0,
			'repeat_customer_rate' => 0.0,
			'items_sold'          => 0.0,
			'items_per_order'     => 0.0,
			'product_lines'       => 0,
			'product_categories'  => 0,
		];

		if ( empty( $window['query'] ) || ! function_exists( 'wc_get_orders' ) ) {
			return $summary;
		}

		$customer_map = [];
		$product_map  = [];
		$category_map = [];
		$category_cache = [];
		$orders       = wc_get_orders(
			[
				'limit'        => 100,
				'status'       => self::get_revenue_order_status_slugs(),
				'date_created' => $window['query'],
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$summary['orders']++;
			$summary['gross_sales'] += (float) $order->get_total();

			$email = strtolower( sanitize_email( $order->get_billing_email() ) );
			if ( $email ) {
				if ( ! isset( $customer_map[ $email ] ) ) {
					$customer_map[ $email ] = 0;
				}

				$customer_map[ $email ]++;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				$key     = $product instanceof WC_Product ? $product->get_id() : $item->get_name();
				$product_id = $item->get_product_id();
				$category_context = self::get_product_category_context( $product_id, $category_cache );
				$quantity = (float) $item->get_quantity();
				$line_total = (float) $item->get_total();
				$product_map[ $key ] = true;
				$summary['items_sold'] += $quantity;
				self::record_category_mix( $category_map, $category_context, $key, $quantity, $line_total );
			}
		}

		$summary['average_order_value'] = $summary['orders'] > 0 ? $summary['gross_sales'] / $summary['orders'] : 0.0;
		$summary['unique_customers'] = count( $customer_map );
		$summary['repeat_customers'] = count(
			array_filter(
				$customer_map,
				function( $order_count ) {
					return absint( $order_count ) > 1;
				}
			)
		);
		$summary['repeat_customer_rate'] = $summary['unique_customers'] > 0 ? ( $summary['repeat_customers'] / $summary['unique_customers'] ) * 100 : 0.0;
		$summary['product_lines'] = count( $product_map );
		$summary['product_categories'] = count( $category_map );
		$summary['items_per_order'] = $summary['orders'] > 0 ? $summary['items_sold'] / $summary['orders'] : 0.0;

		return $summary;
	}

	private static function build_store_signal_comparison( $current_metrics, $previous_metrics, $previous_window ) {
		$keys = [ 'orders', 'gross_sales', 'average_order_value', 'unique_customers', 'repeat_customers', 'repeat_customer_rate', 'items_sold', 'items_per_order', 'product_lines', 'product_categories' ];
		$comparison = [
			'window'  => YoRo_Date_Helper::build_window_payload( is_array( $previous_window ) ? $previous_window : [] ),
			'metrics' => [],
		];

		foreach ( $keys as $key ) {
			$current = (float) ( $current_metrics[ $key ] ?? 0 );
			$previous = (float) ( $previous_metrics[ $key ] ?? 0 );
			$comparison['metrics'][ $key ] = YoRo_Report_Builder::metric_comparison( $current, $previous );
			$comparison['metrics'][ $key ]['previous'] = wc_format_decimal( $previous, in_array( $key, [ 'gross_sales', 'average_order_value', 'items_per_order' ], true ) ? 2 : ( 'repeat_customer_rate' === $key ? 1 : 0 ) );
		}

		return $comparison;
	}

	private static function report_comparison_for_metric( $comparison, $key ) {
		return is_array( $comparison ) && ! empty( $comparison['metrics'][ $key ] ) && is_array( $comparison['metrics'][ $key ] )
			? $comparison['metrics'][ $key ]
			: [];
	}

	private static function build_store_insights_report( $window, $order_count, $order_total, $average_order_value, $customer_count, $repeat_customer_count, $repeat_customer_rate, $product_line_count, $product_category_count, $items_sold, $items_per_order, $inventory_counts, $top_customers, $top_products, $top_categories, $comparison = [] ) {
		$window_label         = $window['label'] ?? __( 'selected window', 'yoro-ai-core' );
		$currency             = get_woocommerce_currency();
		$inventory_risk_count = absint( $inventory_counts['out_of_stock'] ?? 0 )
			+ absint( $inventory_counts['low_stock'] ?? 0 )
			+ absint( $inventory_counts['on_backorder'] ?? 0 );
		$health               = $inventory_risk_count > 0 ? 'watch' : ( $order_count > 0 ? 'good' : 'watch' );
		$headline             = sprintf(
			/* translators: Placeholder values are generated from store data for this message. */
			__( 'Store signals for %1$s: %2$d orders, %3$d customers, %4$d product lines.', 'yoro-ai-core' ),
			$window_label,
			$order_count,
			$customer_count,
			$product_line_count
		);
		$order_comparison = self::report_comparison_for_metric( $comparison, 'orders' );
		$gross_comparison = self::report_comparison_for_metric( $comparison, 'gross_sales' );
		$aov_comparison = self::report_comparison_for_metric( $comparison, 'average_order_value' );
		$customer_comparison = self::report_comparison_for_metric( $comparison, 'unique_customers' );
		$repeat_customer_comparison = self::report_comparison_for_metric( $comparison, 'repeat_customers' );
		$repeat_customer_rate_comparison = self::report_comparison_for_metric( $comparison, 'repeat_customer_rate' );
		$item_comparison = self::report_comparison_for_metric( $comparison, 'items_sold' );
		$items_per_order_comparison = self::report_comparison_for_metric( $comparison, 'items_per_order' );
		$product_line_comparison = self::report_comparison_for_metric( $comparison, 'product_lines' );
		$product_category_comparison = self::report_comparison_for_metric( $comparison, 'product_categories' );
		$profile_context = YoRo_Report_Builder::store_profile_context( [ 'reporting', 'sales', 'inventory', 'customers' ] );
		$profile_insights = array_merge(
			YoRo_Report_Builder::business_profile_insights(
				$profile_context,
				[
					'average_order_value' => $average_order_value,
				],
				$window
			),
			YoRo_Report_Builder::inventory_profile_insights( $profile_context )
		);

		$metrics = [
			YoRo_Report_Builder::metric( 'orders', __( 'Orders', 'yoro-ai-core' ), $order_count, 'orders', 'number', 'neutral', $order_comparison['trend'] ?? '', $order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'gross_sales', __( 'Gross sales', 'yoro-ai-core' ), wc_format_decimal( $order_total, 2 ), $currency, 'currency', $order_total > 0 ? 'positive' : 'neutral', $gross_comparison['trend'] ?? '', $gross_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'average_order_value', __( 'Average order value', 'yoro-ai-core' ), wc_format_decimal( $average_order_value, 2 ), $currency, 'currency', 'neutral', $aov_comparison['trend'] ?? '', $aov_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'unique_customers', __( 'Customers', 'yoro-ai-core' ), $customer_count, 'customers', 'number', 'neutral', $customer_comparison['trend'] ?? '', $customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customers', __( 'Repeat customers', 'yoro-ai-core' ), $repeat_customer_count, 'customers', 'number', 'neutral', $repeat_customer_comparison['trend'] ?? '', $repeat_customer_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'repeat_customer_rate', __( 'Repeat customer rate', 'yoro-ai-core' ), wc_format_decimal( $repeat_customer_rate, 1 ), '%', 'percent', $repeat_customer_count > 0 ? 'positive' : 'neutral', $repeat_customer_rate_comparison['trend'] ?? '', $repeat_customer_rate_comparison['delta'] ?? null, 'store', 'customers', __( 'Share of active customers with more than one order in this window.', 'yoro-ai-core' ), 35 ),
			YoRo_Report_Builder::metric( 'items_sold', __( 'Items sold', 'yoro-ai-core' ), wc_format_decimal( $items_sold, 0 ), 'items', 'number', 'neutral', $item_comparison['trend'] ?? '', $item_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'items_per_order', __( 'Items per order', 'yoro-ai-core' ), wc_format_decimal( $items_per_order, 2 ), 'items', 'number', 'neutral', $items_per_order_comparison['trend'] ?? '', $items_per_order_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_lines', __( 'Product lines', 'yoro-ai-core' ), $product_line_count, 'products', 'number', 'neutral', $product_line_comparison['trend'] ?? '', $product_line_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'product_categories', __( 'Categories sold', 'yoro-ai-core' ), $product_category_count, 'categories', 'number', 'neutral', $product_category_comparison['trend'] ?? '', $product_category_comparison['delta'] ?? null ),
			YoRo_Report_Builder::metric( 'inventory_risk', __( 'Inventory risk', 'yoro-ai-core' ), $inventory_risk_count, 'items', 'number', $inventory_risk_count > 0 ? 'watch' : 'positive' ),
		];

		$insights = [];

		if ( $order_count > 0 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Demand snapshot', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( 'The selected window contains %1$d orders with %2$s %3$s in gross sales.', 'yoro-ai-core' ), $order_count, wc_format_decimal( $order_total, 2 ), $currency ),
				'orders',
				'positive'
			);
		} else {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'No order activity', 'yoro-ai-core' ),
				__( 'No orders were found in the selected window, so customer and product signals may be sparse.', 'yoro-ai-core' ),
				'orders',
				'watch'
			);
		}

		if ( $repeat_customer_count > 0 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Repeat buyer signal', 'yoro-ai-core' ),
				sprintf(
					/* translators: Placeholder values are generated from store data for this message. */
					__( '%1$d customers ordered more than once, giving this window a %2$s%% repeat customer rate.', 'yoro-ai-core' ),
					$repeat_customer_count,
					wc_format_decimal( $repeat_customer_rate, 1 )
				),
				'customers',
				$repeat_customer_rate >= 25 ? 'positive' : 'neutral'
			);
		}

		if ( $items_per_order >= 2 ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Basket size signal', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( 'Customers are buying %s items per order on average in this window.', 'yoro-ai-core' ), wc_format_decimal( $items_per_order, 2 ) ),
				'orders',
				'positive'
			);
		}

		$top_customer = is_array( $top_customers ) && ! empty( $top_customers ) ? $top_customers[0] : null;
		if ( is_array( $top_customer ) ) {
			$customer_label = trim( (string) ( $top_customer['name'] ?? '' ) );
			if ( '' === $customer_label ) {
				$customer_label = (string) ( $top_customer['email'] ?? __( 'Top customer', 'yoro-ai-core' ) );
			}
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Customer concentration', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the highest-value customer in this window with %2$s %3$s tracked.', 'yoro-ai-core' ), $customer_label, $top_customer['total_spent'] ?? '0.00', $currency ),
				'customers',
				'neutral'
			);
		}

		$top_product = is_array( $top_products ) && ! empty( $top_products ) ? $top_products[0] : null;
		if ( is_array( $top_product ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Top product signal', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the strongest product signal with %2$s units sold.', 'yoro-ai-core' ), $top_product['name'] ?? __( 'Top product', 'yoro-ai-core' ), $top_product['quantity'] ?? 0 ),
				'products',
				'neutral'
			);
		}

		$top_category = is_array( $top_categories ) && ! empty( $top_categories ) ? $top_categories[0] : null;
		if ( is_array( $top_category ) ) {
			$insights[] = YoRo_Report_Builder::insight(
				__( 'Top category signal', 'yoro-ai-core' ),
				/* translators: Placeholder values are generated from store data for this message. */
				sprintf( __( '%1$s is the strongest category signal with %2$s units sold across %3$d product lines.', 'yoro-ai-core' ), $top_category['name'] ?? __( 'Top category', 'yoro-ai-core' ), $top_category['quantity'] ?? 0, absint( $top_category['product_lines'] ?? 0 ) ),
				'products',
				'neutral',
				'products',
				46
			);
		}

		$insights = array_merge( $insights, $profile_insights );

		$alerts = [];

		if ( $inventory_risk_count > 0 ) {
			$alerts[] = YoRo_Report_Builder::alert(
				__( 'Inventory needs review', 'yoro-ai-core' ),
				/* translators: %s: Value generated from store data. */
				sprintf( __( '%d products are out of stock, low stock, or on backorder.', 'yoro-ai-core' ), $inventory_risk_count ),
				'watch',
				'inventory',
				'inventory',
				20
			);
		}

		$window_command = $window['label'] ?? 'this week';
		$actions        = [
			YoRo_Report_Builder::action( __( 'Sales report', 'yoro-ai-core' ), 'Show sales report for ' . $window_command, 'view', 'sales', 30, __( 'Open the revenue and sales readout for the same window.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Operations report', 'yoro-ai-core' ), 'Show operations report for ' . $window_command, 'view', 'operations', 40, __( 'Check whether the signal is creating order-work pressure.', 'yoro-ai-core' ) ),
			YoRo_Report_Builder::action( __( 'Inventory queue', 'yoro-ai-core' ), 'Show inventory queue', 'view', 'inventory', 50, __( 'Review stock pressure behind the product signals.', 'yoro-ai-core' ) ),
		];

		$breakdowns = YoRo_Store_Briefing_Service::report_build_product_mix_breakdowns( $top_products, $top_categories, $currency, (float) $order_total );
		$report     = YoRo_Report_Builder::build( 'store_insights', $window, $health, $headline, $metrics, $insights, $alerts, $actions, $actions, $breakdowns );
		$report     = YoRo_Report_Builder::attach_profile_context( $report, $profile_context );

		if ( ! empty( $comparison ) ) {
			$report['comparison'] = $comparison;
		}

		return $report;
	}

	public static function get_business_summary( $params ) {
		return YoRo_Store_Briefing_Service::get_business_summary( $params );
	}

	public static function get_daily_briefing( $params ) {
		return YoRo_Store_Briefing_Service::get_daily_briefing( $params );
	}

	private static function refund_read_input( $params, $history ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) ) { return new WP_Error( 'refund_invalid_input', 'Invalid refund read parameters.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		if ( array_diff( array_keys( $params ), $history ? [ 'order_id', 'limit', 'page' ] : [ 'order_id' ] ) || ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 || $params['order_id'] > 9007199254740991 ) { return new WP_Error( 'refund_invalid_input', 'An exact order ID and only read parameters are required.' ); }
		foreach ( [ 'limit' => 50, 'page' => 100 ] as $key => $max ) {
			if ( array_key_exists( $key, $params ) && ( ! is_int( $params[ $key ] ) || $params[ $key ] < 1 || $params[ $key ] > $max ) ) { return new WP_Error( 'refund_invalid_input', 'Invalid pagination.' ); }
		}
		return $params;
	}

	private static function refund_number( $value, $signed = false ) {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! ( is_string( $value ) && strlen( $value ) <= 32 && preg_match( $signed ? '/^-?[0-9]+(?:\.[0-9]+)?$/D' : '/^[0-9]+(?:\.[0-9]+)?$/D', $value ) ) ) { throw new RuntimeException( 'Invalid numeric owner value.' ); }
		$value = (float) $value;
		if ( ! is_finite( $value ) || abs( $value ) > 9007199254740991 || ( ! $signed && $value < 0 ) ) { throw new RuntimeException( 'Unsafe numeric owner value.' ); }
		return $value;
	}

	private static function refund_text( $value, $limit ) {
		if ( ! is_string( $value ) || ! mb_check_encoding( $value, 'UTF-8' ) || strlen( $value ) > $limit * 8 || preg_match( '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value ) ) { throw new RuntimeException( 'Invalid owner text.' ); }
		$value = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
		if ( ! mb_check_encoding( $value, 'UTF-8' ) || mb_strlen( $value ) > $limit ) { throw new RuntimeException( 'Owner text exceeds bound.' ); }
		return $value;
	}

	private static function refund_order( $id, $blog ) {
		$order = wc_get_order( $id );
		if ( get_current_blog_id() !== $blog || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $id || 'shop_order' !== $order->get_type() ) { throw new RuntimeException( 'Exact current-site order required.' ); }
		return $order;
	}

	private static function refund_page( $order_id, $limit, $page, $complete, $blog ) {
		$ids = wc_get_orders( [ 'type' => 'shop_order_refund', 'parent' => $order_id, 'limit' => $limit + 1, 'offset' => ( $page - 1 ) * $limit, 'orderby' => [ 'date' => 'DESC', 'ID' => 'DESC' ], 'order' => 'DESC', 'return' => 'ids', 'paginate' => false ] );
		if ( ! is_array( $ids ) || count( $ids ) > $limit + 1 || ( $complete && count( $ids ) > $limit ) || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Refund history exceeds the admitted bound.' ); }
		$seen = []; $rows = []; $objects = []; $previous = null;
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 || $id > 9007199254740991 || isset( $seen[ $id ] ) ) { throw new RuntimeException( 'Invalid refund identity.' ); }
			$seen[ $id ] = true; $refund = wc_get_order( $id );
			if ( ! $refund instanceof WC_Order_Refund || $refund->get_id() !== $id || 'shop_order_refund' !== $refund->get_type() || $refund->get_parent_id() !== $order_id || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Invalid refund owner.' ); }
			$date = $refund->get_date_created();
			if ( ! $date instanceof WC_DateTime || $date->getTimestamp() < 1 || $date->getTimestamp() > 253402300799 ) { throw new RuntimeException( 'Invalid refund date.' ); }
			$key = [ $date->getTimestamp(), $id ];
			if ( null !== $previous && $key >= $previous ) { throw new RuntimeException( 'Refund ordering changed.' ); }
			$previous = $key;
			$paid = $refund->get_refunded_payment();
			if ( ! is_bool( $paid ) ) { throw new RuntimeException( 'Invalid payment observation.' ); }
			$rows[] = [ 'refund_id' => $id, 'amount' => wc_format_decimal( self::refund_number( $refund->get_amount() ), 2 ), 'reason' => self::refund_text( $refund->get_reason(), 500 ), 'created_at' => gmdate( 'c', $date->getTimestamp() ), 'refunded_payment' => $paid ];
			$objects[] = $refund;
		}
		if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site changed.' ); }
		return [ 'refunds' => array_slice( $rows, 0, $limit ), 'objects' => array_slice( $objects, 0, $limit ), 'has_more' => count( $ids ) > $limit ];
	}

	public static function get_refunds( $params ) {
		$input = self::refund_read_input( $params, true ); if ( is_wp_error( $input ) ) { return $input; }
		try {
			$blog = get_current_blog_id(); $id = $input['order_id']; $order = self::refund_order( $id, $blog );
			$limit = $input['limit'] ?? 20; $page = $input['page'] ?? 1; $observed = self::refund_page( $id, $limit, $page, false, $blog );
			$total = self::refund_number( $order->get_total() ); $refunded = self::refund_number( $order->get_total_refunded() );
			$currency = $order->get_currency();
			if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Invalid order context.' ); }
			return [ 'success' => true, 'action' => 'orders.get_refunds', 'message' => 'Loaded bounded refund observations.', 'summary' => 'Loaded bounded refund observations; payment transfer is not verified.', 'read_only' => true, 'undoable' => false, 'undo_plan' => [], 'result_type' => 'order_refund_history', 'display_type' => 'order_refund_history', 'order_id' => $id, 'currency' => $currency, 'total_refunded' => wc_format_decimal( $refunded, 2 ), 'remaining_refundable' => wc_format_decimal( max( 0, $total - $refunded ), 2 ), 'refunds' => $observed['refunds'], 'pagination' => [ 'limit' => $limit, 'page' => $page, 'has_more' => $observed['has_more'], 'snapshot_consistent' => false ] ];
		} catch ( Throwable $error ) { return new WP_Error( 'refund_observation_unavailable', 'Refund observations are unavailable or exceed the safe bound.' ); }
	}

	/** Fresh owner observations used by the refund guard and immediate execution check. */
	public static function refund_execution_state( $order_id ) {
		$guard = [ 'order_id' => $order_id, 'eligible' => false, 'blocked_reason' => 'refund_state_unavailable', 'current_version' => null, 'state_token' => null, 'remaining_refundable' => null, 'gateway_refunds_supported' => false ];
		try {
			$blog = get_current_blog_id();
			$order = self::refund_order( $order_id, $blog );
			$observed = self::refund_page( $order_id, 50, 1, true, $blog );
			$decimals = wc_get_price_decimals();
			if ( ! is_int( $decimals ) || $decimals < 0 || $decimals > 6 ) { throw new RuntimeException( 'Unsupported money precision.' ); }
			$total = self::refund_money_units( $order->get_total(), $decimals );
			$owner_refunded = $order->get_total_refunded();
			// WooCommerce returns a float aggregate. Compare it to exact bounded owner rows; never round caller money.
			if ( ! is_numeric( $owner_refunded ) || ! is_finite( (float) $owner_refunded ) || $owner_refunded < 0 ) { throw new RuntimeException( 'Invalid owner aggregate.' ); }
			$refunded = self::refund_money_units( wc_format_decimal( $owner_refunded, $decimals ), $decimals );
			$currency = $order->get_currency();
			$version = YoRo_Order_Presenter::order_version( $order );
			if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version ) || $refunded > $total ) { throw new RuntimeException( 'Invalid owner state.' ); }
			$refunds = []; $sum = 0;
			foreach ( $observed['objects'] as $refund ) {
				$units = self::refund_money_units( $refund->get_amount(), $decimals );
				if ( $refund->get_currency() !== $currency ) { throw new RuntimeException( 'Refund currency changed.' ); }
				$refunds[] = [ $refund->get_id(), $units, $refund->get_refunded_payment() ];
				$sum += $units;
			}
			if ( $sum !== $refunded || abs( (float) $owner_refunded - (float) self::refund_money_decimal( $sum, $decimals ) ) > 0.00000001 ) { throw new RuntimeException( 'Refund totals disagree.' ); }
			$method = $order->get_payment_method(); $transaction = $order->get_transaction_id();
			if ( ! is_string( $method ) || ! preg_match( '/^[a-zA-Z0-9_-]{1,100}$/D', $method ) || ! is_string( $transaction ) || strlen( $transaction ) > 512 || preg_match( '/[\x00-\x1f\x7f]/', $transaction ) ) { throw new RuntimeException( 'Invalid gateway context.' ); }
			$gateway = wc_get_payment_gateway_by_order( $order );
			$supported = $gateway instanceof WC_Payment_Gateway && $gateway->id === $method && true === $gateway->supports( 'refunds' );
			$guard['current_version'] = $version;
			$guard['remaining_refundable'] = self::refund_money_decimal( $total - $refunded, $decimals );
			$guard['gateway_refunds_supported'] = $supported;
			$material = [ 'yoro-gateway-refund-v1', $blog, $order_id, $version, $currency, $decimals, $total, $refunded, $refunds, $method, $transaction, $supported ];
			$key = wp_salt( 'auth' );
			if ( ! is_string( $key ) || strlen( $key ) < 32 || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Unsafe state key or site.' ); }
			$guard['blocked_reason'] = ! $supported ? 'gateway_refund_unsupported' : ( $total === $refunded ? 'nothing_refundable' : ( ! $order->is_paid() || '' === $transaction ? 'order_not_paid' : '' ) );
			$guard['eligible'] = '' === $guard['blocked_reason'];
			if ( $guard['eligible'] ) { $guard['state_token'] = hash_hmac( 'sha256', wp_json_encode( $material, JSON_THROW_ON_ERROR ), $key ); }
			return [ 'guard' => $guard, 'order' => $order, 'blog' => $blog, 'currency' => $currency, 'decimals' => $decimals, 'total' => $total, 'refunded' => $refunded, 'refunds' => $refunds, 'payment_method' => $method ];
		} catch ( Throwable $error ) { return [ 'guard' => $guard ]; }
	}

	/** Exact local refund observations; an external transfer is never queried or verified here. */
	public static function external_refund_recording_state( $order_id ) {
		$guard = [ 'order_id' => $order_id, 'eligible' => false, 'blocked_reason' => 'refund_state_unavailable', 'current_version' => null, 'state_token' => null, 'remaining_refundable' => null ];
		try {
			$blog = get_current_blog_id();
			$order = self::refund_order( $order_id, $blog );
			$observed = self::refund_page( $order_id, 50, 1, true, $blog );
			$decimals = wc_get_price_decimals();
			$total = self::refund_money_units( $order->get_total(), $decimals );
			$owner_refunded = $order->get_total_refunded();
			if ( ! is_numeric( $owner_refunded ) || ! is_finite( (float) $owner_refunded ) || $owner_refunded < 0 ) { throw new RuntimeException( 'Invalid owner aggregate.' ); }
			$refunded = self::refund_money_units( wc_format_decimal( $owner_refunded, $decimals ), $decimals );
			$currency = $order->get_currency();
			$version = YoRo_Order_Presenter::order_version( $order );
			$status = $order->get_status();
			if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) || ! is_string( $version ) || ! preg_match( '/^[a-f0-9]{64}$/D', $version )
				|| ! is_string( $status ) || ! preg_match( '/^[a-z0-9_-]{1,32}$/D', $status ) || $refunded > $total ) { throw new RuntimeException( 'Invalid owner state.' ); }
			$refunds = []; $sum = 0;
			foreach ( $observed['objects'] as $refund ) {
				$units = self::refund_money_units( $refund->get_amount(), $decimals );
				if ( $refund->get_currency() !== $currency ) { throw new RuntimeException( 'Refund currency changed.' ); }
				$refunds[] = [ $refund->get_id(), $units, $refund->get_refunded_payment() ];
				$sum += $units;
			}
			if ( $sum !== $refunded || abs( (float) $owner_refunded - (float) self::refund_money_decimal( $sum, $decimals ) ) > 0.00000001 ) { throw new RuntimeException( 'Refund totals disagree.' ); }
			$paid = $order->is_paid();
			if ( ! is_bool( $paid ) ) { throw new RuntimeException( 'Invalid owner paid state.' ); }
			$key = wp_salt( 'auth' );
			if ( ! is_string( $key ) || strlen( $key ) < 32 || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Unsafe state key or site.' ); }
			$guard['current_version'] = $version;
			$guard['remaining_refundable'] = self::refund_money_decimal( $total - $refunded, $decimals );
			$guard['eligible'] = $total > $refunded && $paid;
			$guard['blocked_reason'] = $total === $refunded ? 'nothing_refundable' : ( $paid ? '' : 'order_not_paid' );
			if ( $guard['eligible'] ) {
				$material = [ 'yoro-external-refund-recording-v1', $blog, $order_id, $status, $version, $currency, $decimals, $total, $refunded, $refunds ];
				$guard['state_token'] = hash_hmac( 'sha256', wp_json_encode( $material, JSON_THROW_ON_ERROR ), $key );
			}
			return [ 'guard' => $guard, 'blog' => $blog, 'currency' => $currency, 'decimals' => $decimals, 'total' => $total, 'refunded' => $refunded, 'refunds' => $refunds ];
		} catch ( Throwable $error ) { return [ 'guard' => $guard ]; }
	}

	/** Convert bounded owner decimal money to exact minor units, without rounding an input. */
	public static function refund_money_units( $value, $decimals ) {
		if ( ! is_int( $decimals ) || $decimals < 0 || $decimals > 6 ) { throw new RuntimeException( 'Unsupported money precision.' ); }
		if ( is_int( $value ) ) { $value = (string) $value; }
		if ( ! is_string( $value ) || strlen( $value ) > 24 || ! preg_match( '/^(0|[1-9][0-9]{0,8})(?:\.([0-9]{1,6}))?$/D', $value, $parts ) ) { throw new RuntimeException( 'Invalid bounded decimal.' ); }
		$fraction = $parts[2] ?? '';
		if ( strlen( $fraction ) > $decimals && trim( substr( $fraction, $decimals ), '0' ) !== '' ) { throw new RuntimeException( 'Money precision would be lost.' ); }
		return (int) $parts[1] * ( 10 ** $decimals ) + (int) str_pad( substr( $fraction, 0, $decimals ), $decimals, '0' );
	}

	public static function refund_money_decimal( $units, $decimals ) {
		if ( 0 === $decimals ) { return (string) $units; }
		return (string) intdiv( $units, 10 ** $decimals ) . '.' . str_pad( (string) ( $units % ( 10 ** $decimals ) ), $decimals, '0', STR_PAD_LEFT );
	}

	public static function refund_preview( $params ) {
		$input = self::refund_read_input( $params, false ); if ( is_wp_error( $input ) ) { return $input; }
		try {
		$blog = get_current_blog_id(); $order_id = $input['order_id']; $order = self::refund_order( $order_id, $blog );
		$observed = self::refund_page( $order_id, 50, 1, true, $blog );
		$line_items = $order->get_items( 'line_item' );
		if ( ! is_array( $line_items ) || count( $line_items ) > 200 ) { throw new RuntimeException( 'Line items exceed the safe bound.' ); }
		$scanned = count( $line_items ); $line_refunds = [];
		foreach ( $observed['objects'] as $refund ) {
			$refund_items = $refund->get_items( 'line_item' );
			if ( ! is_array( $refund_items ) || $scanned + count( $refund_items ) > 200 ) { throw new RuntimeException( 'Refund items exceed the safe bound.' ); }
			$scanned += count( $refund_items );
			foreach ( $refund_items as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product || $item->get_order_id() !== $refund->get_id() ) { throw new RuntimeException( 'Invalid refund item.' ); }
				// WooCommerce's own per-item refund methods use this exact owner-owned relation.
				$original = $item->get_meta( '_refunded_item_id' );
				if ( is_string( $original ) && preg_match( '/^[1-9][0-9]{0,15}$/D', $original ) ) { $original = (int) $original; }
				if ( ! is_int( $original ) || ! isset( $line_items[ $original ] ) ) { throw new RuntimeException( 'Invalid refund item parent.' ); }
				$line_refunds[ $original ]['quantity'] = ( $line_refunds[ $original ]['quantity'] ?? 0 ) + self::refund_number( $item->get_quantity(), true );
				self::refund_number( $line_refunds[ $original ]['quantity'], true );
				$line_refunds[ $original ]['total'] = ( $line_refunds[ $original ]['total'] ?? 0 ) + self::refund_number( $item->get_total(), true );
				self::refund_number( $line_refunds[ $original ]['total'], true );
			}
		}
		$total          = (float) self::refund_number( $order->get_total() );
		$total_refunded = (float) self::refund_number( $order->get_total_refunded() );
		$remaining      = max( 0, $total - $total_refunded );

		$items = [];

		foreach ( $line_items as $item_id => $item ) {
			if ( ! is_int( $item_id ) || $item_id < 1 || $item_id > 9007199254740991 || ! $item instanceof WC_Order_Item_Product || $item->get_id() !== $item_id || $item->get_order_id() !== $order_id ) {
				throw new RuntimeException( 'Invalid line item.' );
			}

			$product = $item->get_product();

			$qty           = (float) self::refund_number( $item->get_quantity() );
			$refunded_qty  = abs( (float) ( $line_refunds[ $item_id ]['quantity'] ?? 0 ) );
			$remaining_qty = max( 0, $qty - $refunded_qty );

			$item_total          = (float) self::refund_number( $item->get_total() );
			$item_total_refunded = abs( (float) ( $line_refunds[ $item_id ]['total'] ?? 0 ) );
			$item_remaining      = max( 0, $item_total - $item_total_refunded );

			$items[] = [
				'item_id'            => absint( $item_id ),
				'product_id'         => $product ? absint( $product->get_id() ) : 0,
				'product_name'       => self::refund_text( $item->get_name(), 500 ),
				'sku'                => $product ? self::refund_text( $product->get_sku(), 200 ) : '',
				'quantity'           => $qty,
				'refunded_quantity'  => $refunded_qty,
				'remaining_quantity' => $remaining_qty,
				'line_subtotal'      => wc_format_decimal( self::refund_number( $item->get_subtotal() ), 2 ),
				'line_total'         => wc_format_decimal( $item_total, 2 ),
				'refunded_total'     => wc_format_decimal( $item_total_refunded, 2 ),
				'remaining_total'    => wc_format_decimal( $item_remaining, 2 ),
			];
		}

		$refunds = [];

		foreach ( $observed['objects'] as $index => $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}

			$refund_date = new WC_DateTime( $observed['refunds'][ $index ]['created_at'] );

			$refunds[] = [
				'id'         => $refund->get_id(),
				'amount'     => $observed['refunds'][ $index ]['amount'],
				'reason'     => $observed['refunds'][ $index ]['reason'],
				'date'       => $refund_date ? YoRo_Date_Helper::format_wc_datetime( $refund_date ) : '',
				'date_ts'    => $refund_date ? YoRo_Date_Helper::get_wc_timestamp( $refund_date ) : 0,
				'created_by' => 0,
			];
		}

		if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site changed.' ); }
		$order_date_created = $order->get_date_created();
		if ( null !== $order_date_created && ( ! $order_date_created instanceof WC_DateTime || $order_date_created->getTimestamp() < 1 || $order_date_created->getTimestamp() > 253402300799 ) ) { throw new RuntimeException( 'Invalid order date.' ); }
		$currency = $order->get_currency();
		if ( ! is_string( $currency ) || ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) { throw new RuntimeException( 'Invalid currency.' ); }
		$fees = $order->get_items( 'fee' );
		if ( ! is_array( $fees ) || $scanned + count( $fees ) > 200 ) { throw new RuntimeException( 'Fee items exceed the safe bound.' ); }
		$fee_total = 0;
		foreach ( $fees as $fee ) {
			if ( ! $fee instanceof WC_Order_Item_Fee || $fee->get_order_id() !== $order_id ) { throw new RuntimeException( 'Invalid fee.' ); }
			$fee_total = self::refund_number( $fee_total + self::refund_number( $fee->get_total(), true ), true );
		}


		$result = [
			'success' => true,
			'action'  => 'orders.refund_preview',
			'summary' => sprintf(
				'Order #%s has %s %s remaining refundable.',
				self::refund_text( (string) $order->get_order_number(), 100 ),
				wc_format_decimal( $remaining, 2 ),
				$order->get_currency()
			),

			'result_type'  => 'refund',
			'display_type' => 'refund_preview',

			'order' => [
				'id'             => $order->get_id(),
				'number'         => self::refund_text( (string) $order->get_order_number(), 100 ),
				'status'         => self::refund_text( $order->get_status(), 40 ),
				'status_label'   => self::refund_text( YoRo_Order_Helper::get_order_status_label( $order->get_status() ), 100 ),
				'currency'       => $order->get_currency(),
				'total'          => wc_format_decimal( $total, 2 ),
				'total_refunded' => wc_format_decimal( $total_refunded, 2 ),
				'remaining'      => wc_format_decimal( $remaining, 2 ),
				'payment_method' => self::refund_text( $order->get_payment_method(), 100 ),
				'payment_title'  => self::refund_text( $order->get_payment_method_title(), 200 ),
				'transaction_id' => '',
				'date_created'   => $order_date_created ? YoRo_Date_Helper::format_wc_datetime( $order_date_created ) : '',
				'date_created_ts'=> $order_date_created ? YoRo_Date_Helper::get_wc_timestamp( $order_date_created ) : 0,
				'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			],

			'totals' => [
				'items_total'      => wc_format_decimal( self::refund_number( $order->get_subtotal() ), 2 ),
				'discount_total'   => wc_format_decimal( self::refund_number( $order->get_discount_total() ), 2 ),
				'shipping_total'   => wc_format_decimal( self::refund_number( $order->get_shipping_total() ), 2 ),
				'fee_total'        => wc_format_decimal( $fee_total, 2 ),
				'tax_total'        => wc_format_decimal( self::refund_number( $order->get_total_tax() ), 2 ),
				'order_total'      => wc_format_decimal( $total, 2 ),
				'total_refunded'   => wc_format_decimal( $total_refunded, 2 ),
				'remaining_refund' => wc_format_decimal( $remaining, 2 ),
			],

			'items'      => $items,
			'refunds'    => $refunds,
			'can_refund' => $remaining > 0,
			'execution_guard' => self::refund_execution_state( $order_id )['guard'],
			'external_record_guard' => self::external_refund_recording_state( $order_id )['guard'],
			'read_only'  => true,
		];
		if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site changed.' ); }
		return $result;
		} catch ( Throwable $error ) { return new WP_Error( 'refund_observation_unavailable', 'Refund preview is unavailable or exceeds the safe bound.' ); }
	}

	public static function customer_360_email( $email ) {
		if ( ! is_string( $email ) || strlen( $email ) > 254 || preg_match( '/[\x00-\x1f\x7f]/', $email ) ) { return null; }
		$normalized = strtolower( trim( $email ) );
		return is_email( $normalized ) && sanitize_email( $normalized ) === $normalized ? $normalized : null;
	}

	public static function customer_360_mask_email( $email ) {
		$parts = explode( '@', $email, 2 );
		return ( strlen( $parts[0] ) > 1 ? substr( $parts[0], 0, 1 ) . str_repeat( '*', min( 3, strlen( $parts[0] ) - 1 ) ) : '*' ) . '@' . $parts[1];
	}

	public static function customer_360_order_identity( WC_Order $order ) {
		$id = $order->get_customer_id();
		if ( ! is_int( $id ) || $id < 0 ) { throw new RuntimeException( 'Invalid customer identity.' ); }
		if ( $id > 0 ) { return [ 'basis' => 'customer_id', 'customer_id' => $id, 'email_masked' => '' ]; }
		$email = self::customer_360_email( $order->get_billing_email() );
		return [ 'basis' => null === $email ? 'order' : 'email', 'customer_id' => 0,
			'email_masked' => null === $email ? '' : self::customer_360_mask_email( $email ) ];
	}

	public static function customer_360_result( $action, array $data ) {
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [],
			'message' => 'Loaded bounded Customer 360 observations.', 'summary' => 'Loaded bounded Customer 360 observations.',
			'result_type' => 'customers.get_timeline' === $action ? 'customer_timeline' : 'customer_follow_up_queue',
			'display_type' => 'customers.get_timeline' === $action ? 'customer_timeline' : 'customer_follow_up_queue' ], $data );
	}

	public static function get_customer_timeline( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Customer timeline requires store management permission.' ); }
		try {
			if ( ! function_exists( 'wc_get_orders' ) || ! is_array( $params ) ) { throw new RuntimeException( 'Customer source unavailable.' ); }
			unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
			if ( array_diff( array_keys( $params ), [ 'customer_id', 'email', 'limit' ] )
				|| array_key_exists( 'customer_id', $params ) === array_key_exists( 'email', $params ) ) { throw new RuntimeException( 'One exact identity is required.' ); }
			$limit = $params['limit'] ?? 20;
			if ( ! is_int( $limit ) || $limit < 1 || $limit > 50 ) { throw new RuntimeException( 'Invalid event bound.' ); }
			$identity = [ 'basis' => 'customer_id', 'customer_id' => 0, 'email_masked' => '' ];
			$query = [ 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'limit' => 21, 'orderby' => 'ID', 'order' => 'DESC', 'return' => 'objects' ];
			$email = null;
			if ( array_key_exists( 'customer_id', $params ) ) {
				$id = $params['customer_id'];
				if ( ! is_int( $id ) || $id < 1 || ! get_userdata( $id ) ) { throw new RuntimeException( 'Invalid registered customer.' ); }
				$identity['customer_id'] = $id;
				$query['customer_id'] = $id;
			} else {
				$email = self::customer_360_email( $params['email'] );
				if ( null === $email ) { throw new RuntimeException( 'Invalid exact email.' ); }
				$identity = [ 'basis' => 'email', 'customer_id' => 0, 'email_masked' => self::customer_360_mask_email( $email ) ];
				$query['billing_email'] = $email;
			}
			$blog_id = get_current_blog_id();
			$orders = wc_get_orders( $query );
			if ( ! is_array( $orders ) || ! array_is_list( $orders ) || count( $orders ) > 21 ) { throw new RuntimeException( 'Unbounded owner query.' ); }
			$truncated = count( $orders ) > 20;
			$events = [];
			$seen = [];
			foreach ( array_slice( $orders, 0, 20 ) as $candidate ) {
				if ( ! $candidate instanceof WC_Order || get_current_blog_id() !== $blog_id ) { throw new RuntimeException( 'Invalid owner source.' ); }
				$order = wc_get_order( $candidate->get_id() );
				if ( ! $order instanceof WC_Order || $order->get_id() !== $candidate->get_id() || isset( $seen[ $order->get_id() ] )
					|| ( 'customer_id' === $identity['basis'] ? $order->get_customer_id() !== $identity['customer_id'] : self::customer_360_email( $order->get_billing_email() ) !== $email ) ) {
					throw new RuntimeException( 'Cross-identity order.' );
				}
				$seen[ $order->get_id() ] = true;
				$timeline = YoRo_Commerce_Discovery_Service::get_order_timeline( [ 'order_id' => $order->get_id(), 'limit' => 50 ] );
				if ( is_wp_error( $timeline ) || empty( $timeline['success'] ) || ! is_array( $timeline['events'] ?? null ) || count( $timeline['events'] ) > 53 ) { throw new RuntimeException( 'Invalid timeline source.' ); }
				$notes = 0;
				foreach ( $timeline['events'] as $event ) {
					$type = $event['type'] ?? null;
					$visibility = $event['visibility'] ?? null;
					$date = $event['occurred_at'] ?? null;
					$id = $event['id'] ?? null;
					if ( ! in_array( $type, [ 'order_created', 'payment_recorded', 'order_completed', 'order_note' ], true )
						|| ! in_array( $visibility, [ 'system', 'internal', 'customer' ], true ) || ! is_string( $date ) || strlen( $date ) > 40
						|| ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $date ) || false === strtotime( $date )
						|| ! is_string( $id ) || ! preg_match( '/^(?:order_created|payment_recorded|order_completed|note_[1-9][0-9]*)$/D', $id ) ) { throw new RuntimeException( 'Invalid event source.' ); }
					if ( 'order_note' === $type ) { ++$notes; }
					$summary = 'order_note' === $type ? ( 'customer' === $visibility ? 'Customer-visible order note recorded.' : 'Internal order note recorded.' )
						: [ 'order_created' => 'Order created.', 'payment_recorded' => 'Payment recorded.', 'order_completed' => 'Order completed.' ][ $type ];
					$event_id = $order->get_id() . ':' . $id;
					if ( isset( $events[ $event_id ] ) ) { throw new RuntimeException( 'Duplicate timeline event.' ); }
					$events[ $event_id ] = [ 'id' => $event_id, 'order_id' => $order->get_id(), 'type' => $type,
						'occurred_at' => gmdate( 'c', strtotime( $date ) ), 'summary' => $summary, 'visibility' => $visibility,
						'source' => 'order_note' === $type ? 'woocommerce.order_note' : 'woocommerce.order' ];
				}
				$truncated = $truncated || $notes >= 50;
			}
			if ( get_current_blog_id() !== $blog_id ) { throw new RuntimeException( 'Site context changed.' ); }
			$events = array_values( $events );
			usort( $events, static function ( $a, $b ) { return strcmp( $b['occurred_at'], $a['occurred_at'] ) ?: ( $a['order_id'] <=> $b['order_id'] ) ?: strcmp( $a['id'], $b['id'] ); } );
			$truncated = $truncated || count( $events ) > $limit;
			$events = array_slice( $events, 0, $limit );
			return self::customer_360_result( 'customers.get_timeline', [ 'identity' => $identity, 'events' => $events, 'count' => count( $events ),
				'coverage' => [ 'orders_scanned' => count( $seen ), 'order_limit' => 20, 'event_limit' => $limit, 'truncated' => $truncated, 'external_activity' => 'unobserved' ] ] );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'customer_360_unavailable', 'A bounded exact customer identity and verifiable local order timeline are required.' );
		}
	}

	public static function get_customer_summary( $params ) {
		$email         = ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$phone         = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$customer_id   = ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;
		$customer_name = ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$order_id      = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit         = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( $customer_id ) {
			$customer = get_userdata( $customer_id );

			if ( $customer instanceof WP_User ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $customer->user_email );
				}

				if ( empty( $customer_name ) ) {
					$customer_name = sanitize_text_field( $customer->display_name );
				}
			}
		}

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $order->get_billing_email() );
				}

				if ( empty( $phone ) ) {
					$phone = sanitize_text_field( $order->get_billing_phone() );
				}

				if ( empty( $customer_id ) ) {
					$customer_id = (int) $order->get_customer_id();
				}

				if ( empty( $customer_name ) ) {
					$customer_name = trim( $order->get_formatted_billing_full_name() );
				}
			}
		}

		if ( empty( $email ) && empty( $phone ) && empty( $customer_id ) && empty( $customer_name ) ) {
			return new WP_Error( 'invalid_params', __( 'Please provide a customer email, phone number, ID, or name first.', 'yoro-ai-core' ) );
		}

		$orders           = self::get_customer_orders( $email, $phone, $limit, $customer_name, $customer_id );
		$total_spent      = 0;
		$status_counts    = [];
		$payment_methods  = [];
		$first_order_timestamp = 0;
		$last_order_timestamp  = 0;
		$resolved_customer_name = '';
		$latest_order_id  = 0;
		$latest_order_no  = '';
		$data             = [];
		$recent_notes     = [];

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$total_spent += (float) $order->get_total();

			$status = $order->get_status();
			$status_counts[ $status ] = isset( $status_counts[ $status ] ) ? $status_counts[ $status ] + 1 : 1;

			$payment = $order->get_payment_method_title();

			if ( $payment ) {
				$payment_methods[ $payment ] = isset( $payment_methods[ $payment ] ) ? $payment_methods[ $payment ] + 1 : 1;
			}

			$date_created = $order->get_date_created();
			$date_ts      = $date_created ? (int) $date_created->getTimestamp() : 0;

			if ( $date_ts > 0 && ( 0 === $last_order_timestamp || $date_ts > $last_order_timestamp ) ) {
				$last_order_timestamp = $date_ts;
			}

			if ( $date_ts > 0 && ( 0 === $first_order_timestamp || $date_ts < $first_order_timestamp ) ) {
				$first_order_timestamp = $date_ts;
			}

			if ( '' === $resolved_customer_name ) {
				$resolved_customer_name = trim( $order->get_formatted_billing_full_name() );
			}

			if ( empty( $email ) ) {
				$email = sanitize_email( $order->get_billing_email() );
			}

			if ( empty( $phone ) ) {
				$phone = sanitize_text_field( $order->get_billing_phone() );
			}

			if ( 0 === $customer_id ) {
				$customer_id = (int) $order->get_customer_id();
			}

			if ( 0 === $latest_order_id ) {
				$latest_order_id = $order->get_id();
				$latest_order_no = $order->get_order_number();
			}

			$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			$recent_notes = array_merge( $recent_notes, self::customer_order_note_context( $order ) );
		}

		$masked_email = YoRo_Order_Presenter::mask_email( $email );
		$masked_phone = YoRo_Order_Presenter::mask_phone( $phone );
		$resolved_name = '' !== $resolved_customer_name ? $resolved_customer_name : $customer_name;
		$currency = get_woocommerce_currency();
		$total_spent_decimal = wc_format_decimal( $total_spent, 2 );
		$first_order_date = $first_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $first_order_timestamp ) : '';
		$last_order_date = $last_order_timestamp > 0 ? YoRo_Date_Helper::format_timestamp( $last_order_timestamp ) : '';

		$summary = '' !== $resolved_name
			? sprintf(
				'Found customer profile for %1$s with %2$d related orders.',
				$resolved_name,
				count( $data )
			)
			: sprintf(
				'Found this customer profile with %d related orders.',
				count( $data )
			);

		return [
			'success'          => true,
			'action'           => 'customers.lookup',
			'summary'          => $summary,
			'count'            => count( $data ),
			'customer'         => [
				'id'             => $customer_id,
				'edit_url'       => $customer_id > 0 && current_user_can( 'edit_user', $customer_id )
					? esc_url_raw( admin_url( 'user-edit.php?user_id=' . $customer_id ) )
					: '',
				'email'          => $masked_email,
				'phone'          => $masked_phone,
				'name'           => $resolved_name,
				'status'         => count( $data ) > 0 ? 'active' : 'unknown',
				'orders_count'   => count( $data ),
				'lifetime_value' => $total_spent_decimal,
				'currency'       => $currency,
				'first_order_at' => $first_order_date,
				'last_order_at'  => $last_order_date,
				'segments'       => count( $data ) > 1 ? [ 'repeat_customer' ] : [],
				'risk_flags'     => [],
				'meta'           => [
					'status_counts'   => $status_counts,
					'payment_methods' => $payment_methods,
				],
			],
			'related'          => [
				'recent_orders' => $data,
				'open_orders'   => [],
				'recent_notes'  => array_slice( $recent_notes, 0, 10 ),
				'draft_emails'  => [],
			],
			'customer_name'    => $resolved_name,
			'customer_id'      => $customer_id,
			'read_only'        => true,
			'email'            => $masked_email,
			'phone'            => $masked_phone,
			'total_spent'      => $total_spent_decimal,
			'currency'         => $currency,
			'status_counts'    => $status_counts,
			'status_count_labels' => self::order_status_count_labels( $status_counts ),
			'payment_methods'  => $payment_methods,
			'first_order_date' => $first_order_date,
			'first_order_date_ts' => $first_order_timestamp,
			'last_order_date'  => $last_order_date,
			'last_order_date_ts' => $last_order_timestamp,
			'latest_order_id'  => $latest_order_id,
			'latest_order_no'  => $latest_order_no,
			'orders'           => $data,
			'result_type'      => 'customer_summary',
			'display_type'     => 'customer_summary',
			'processed'        => [],
			'skipped'          => [],
			'errors'           => [],
		];
	}

	private static function customer_order_note_context( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return [];
		}

		$context = [];
		foreach ( wc_get_order_notes( [
			'order_id' => $order->get_id(),
			'limit'    => 2,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		] ) as $note ) {
			if ( ! is_object( $note ) ) {
				continue;
			}

			$date_created = ! empty( $note->date_created ) && is_object( $note->date_created )
				? $note->date_created
				: null;
			$context[] = [
				'order_id' => $order->get_id(),
				'date'     => $date_created ? YoRo_Date_Helper::format_wc_datetime( $date_created ) : '',
				'customer' => ! empty( $note->customer_note ),
			];
		}

		return $context;
	}

	public static function get_customer_service_queue( $params ) {
		return YoRo_Store_Customer_Service::get_customer_service_queue( $params );
	}

	public static function find_orders_by_customer( $params ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$email       = ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$phone       = ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$customer_id = ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0;
		$customer_name = ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '';
		$order_id    = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit       = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

		if ( $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order instanceof WC_Order ) {
				if ( empty( $email ) ) {
					$email = sanitize_email( $order->get_billing_email() );
				}

				if ( empty( $phone ) ) {
					$phone = sanitize_text_field( $order->get_billing_phone() );
				}

				if ( empty( $customer_id ) ) {
					$customer_id = (int) $order->get_customer_id();
				}

				if ( empty( $customer_name ) ) {
					$customer_name = trim( $order->get_formatted_billing_full_name() );
				}
			}
		}

			if ( empty( $email ) && empty( $phone ) && empty( $customer_id ) && empty( $customer_name ) ) {
				return new WP_Error(
					'invalid_params',
					__( 'Please provide a customer email, phone number, ID, or name first.', 'yoro-ai-core' )
				);
			}

		$args = [
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => self::get_all_order_status_slugs(),
		];

		if ( $email ) {
			$args['billing_email'] = $email;
		}

		if ( $customer_id ) {
			$args['customer_id'] = $customer_id;
		}

		$orders = wc_get_orders( $args );

		if ( $phone ) {
			$needle = self::normalize_phone_digits( $phone );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$order_phone = self::normalize_phone_digits( $order->get_billing_phone() );

					return $needle && false !== strpos( $order_phone, $needle );
				}
			);
		}

		if ( $customer_name ) {
			$name_needle = strtolower( trim( sanitize_text_field( $customer_name ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						'' !== $name_needle &&
						(
							false !== strpos( $billing_name, $name_needle ) ||
							false !== strpos( $shipping_name, $name_needle )
						)
					);
				}
			);
		}

		$data = [];

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$data[] = YoRo_Order_Presenter::format_order_summary( $order );
			}
		}

		return [
			'success'      => true,
			'action'       => 'orders.find_by_customer',
			'summary'      => sprintf( 'Found %d customer orders.', count( $data ) ),
			'count'        => count( $data ),
			'read_only'    => true,
			'email'        => YoRo_Order_Presenter::mask_email( $email ),
			'phone'        => YoRo_Order_Presenter::mask_phone( $phone ),
			'customer_id'  => $customer_id,
			'customer_name'=> $customer_name,
			'orders'       => $data,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	public static function find_orders_by_identity( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$limit    = ! empty( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 50 ) : 20;

			if ( ! $order_id ) {
				return new WP_Error( 'invalid_params', __( 'Please choose an order first.', 'yoro-ai-core' ) );
			}

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
			}

		$identity = self::build_order_identity( $order );

		// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Excludes the seed order while finding related customer orders.
		$candidates = wc_get_orders(
			[
				'limit'   => $limit + 20,
				'orderby' => 'date',
				'order'   => 'DESC',
				'exclude' => [ $order_id ],
				'status'  => self::get_all_order_status_slugs(),
			]
		);
		// phpcs:enable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude

		$matches = [];

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate instanceof WC_Order ) {
				continue;
			}

			$candidate_identity = self::build_order_identity( $candidate );
			$reasons            = [];

			if ( $identity['email_raw'] && $identity['email_raw'] === $candidate_identity['email_raw'] ) {
				$reasons[] = 'email';
			}

			if ( $identity['phone_digits'] && $identity['phone_digits'] === $candidate_identity['phone_digits'] ) {
				$reasons[] = 'phone';
			}

			if ( $identity['ip'] && $identity['ip'] === $candidate_identity['ip'] ) {
				$reasons[] = 'ip';
			}

			if ( $identity['device_id'] && $identity['device_id'] === $candidate_identity['device_id'] ) {
				$reasons[] = 'device';
			}

			if ( empty( $reasons ) ) {
				continue;
			}

			$item                  = YoRo_Order_Presenter::format_order_summary( $candidate );
			$item['match_reasons'] = $reasons;
			$item['match_score']   = count( $reasons );

			$matches[] = $item;
		}

		usort(
			$matches,
			function( $a, $b ) {
				return ( $b['match_score'] ?? 0 ) <=> ( $a['match_score'] ?? 0 );
			}
		);

		$matches = array_slice( $matches, 0, $limit );

		return [
			'success'      => true,
			'action'       => 'orders.find_by_identity',
			'summary'      => sprintf( 'Found %d identity-matched orders.', count( $matches ) ),
			'count'        => count( $matches ),
			'read_only'    => true,
			'order_id'     => $order_id,
			'identity'     => $identity,
			'orders'       => $matches,
			'result_type'  => 'orders',
			'display_type' => 'orders_list',
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
		];
	}

	public static function preview_search_orders( $params ) {
		$limit  = isset( $params['limit'] ) ? absint( $params['limit'] ) : 10;
		$status = $params['status'] ?? [];

		if ( is_string( $status ) ) {
			$status = '' !== trim( $status ) ? [ $status ] : [];
		}

		$status = array_values(
			array_filter(
				array_map( 'sanitize_key', (array) $status )
			)
		);

		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Search store orders',
			'status'          => $status,
			'limit'           => min( max( $limit, 1 ), 50 ),
			'order_id'        => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'email'           => ! empty( $params['email'] ) ? sanitize_email( $params['email'] ) : '',
			'phone'           => ! empty( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '',
			'payment_method'  => ! empty( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : '',
			'date_window'     => YoRo_Date_Helper::window_key_from_params( $params, 'date_window', '' ),
			'date_from'       => ! empty( $params['date_from'] ) ? sanitize_text_field( $params['date_from'] ) : '',
			'date_to'         => ! empty( $params['date_to'] ) ? sanitize_text_field( $params['date_to'] ) : '',
			'min_age_hours'   => ! empty( $params['min_age_hours'] ) ? min( max( absint( $params['min_age_hours'] ), 1 ), 8760 ) : 0,
			'changes_store'   => false,
			'customer_name'   => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '',
			'billing_country' => ! empty( $params['billing_country'] ) ? strtoupper( sanitize_text_field( $params['billing_country'] ) ) : '',
			'total_min'       => isset( $params['total_min'] ) ? (float) $params['total_min'] : '',
			'total_max'       => isset( $params['total_max'] ) ? (float) $params['total_max'] : '',
		];
	}

	public static function preview_get_order_details( $params ) {
		$order_ids = YoRo_Order_Helper::normalize_order_ids( $params );
		$orders = [];
		$missing_order_ids = [];
		$block_reason = '';
		$message = __( 'This order detail lookup is ready.', 'yoro-ai-core' );

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				$missing_order_ids[] = $order_id;
			}

			$orders[] = [
				'id'     => $order_id,
				'exists' => (bool) $order,
			];
		}

		if ( empty( $order_ids ) ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can continue.', 'yoro-ai-core' );
		} elseif ( ! empty( $missing_order_ids ) ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %s: comma-separated order IDs */
				__( 'I could not find order %s. Choose an existing order before continuing.', 'yoro-ai-core' ),
				implode( ', ', $missing_order_ids )
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store order details',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'order_ids'     => $order_ids,
			'orders'        => $orders,
			'count'         => count( $order_ids ),
			'changes_store' => false,
		];
	}

	public static function preview_get_operations_summary( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$focus      = ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview';
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get operations report',
			'window'        => $window['label'],
			'focus'         => $focus,
			'changes_store' => false,
		];
	}

	public static function preview_get_operations_attention_queue( $params ) {
		return [
			'type'                 => 'read',
			'will_execute'         => false,
			'action'               => 'Get operations attention queue',
			'limit_per_queue'      => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'high_value_threshold' => isset( $params['high_value_threshold'] ) ? max( 0, (float) $params['high_value_threshold'] ) : 200,
			'changes_store'        => false,
		];
	}

	public static function preview_get_fulfillment_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get fulfillment queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'changes_store'   => false,
		];
	}

	public static function preview_get_inventory_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get inventory queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'focus'           => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
			'changes_store'   => false,
		];
	}

	public static function preview_get_store_insights( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store signal report',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_get_business_summary( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'last_7_days' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get sales report',
			'window'        => $window['label'],
			'focus'         => ! empty( $params['focus'] ) ? sanitize_key( $params['focus'] ) : 'overview',
			'changes_store' => false,
		];
	}

	public static function preview_get_daily_briefing( $params ) {
		$window_key = self::normalize_operations_window_key( YoRo_Date_Helper::window_key_from_params( $params, 'window', 'today' ) );
		$window     = self::get_operations_window( $window_key );

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Get store report',
			'window'        => $window['label'],
			'limit'         => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 10 ) : 5,
			'changes_store' => false,
		];
	}

	public static function preview_refund_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$block_reason = '';
		$message = __( 'This refund preview lookup is ready.', 'yoro-ai-core' );

		if ( $order_id <= 0 ) {
			$block_reason = 'missing_order_target';
			$message = __( 'Please choose an order before this can continue.', 'yoro-ai-core' );
		} elseif ( ! $order ) {
			$block_reason = 'order_not_found';
			$message = sprintf(
				/* translators: %d: order ID */
				__( 'I could not find order %d. Choose an existing order before continuing.', 'yoro-ai-core' ),
				$order_id
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Preview store refund context',
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => '' !== $block_reason,
			'block_reason'  => $block_reason,
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'changes_store' => false,
		];
	}

	public static function preview_get_customer_summary( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Look up customer',
			'email'         => ! empty( $params['email'] ) ? YoRo_Order_Presenter::mask_email( $params['email'] ) : '',
			'phone'         => ! empty( $params['phone'] ) ? YoRo_Order_Presenter::mask_phone( $params['phone'] ) : '',
			'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0,
			'customer_name' => ! empty( $params['customer_name'] ) ? sanitize_text_field( $params['customer_name'] ) : '',
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	public static function preview_get_customer_service_queue( $params ) {
		return [
			'type'            => 'read',
			'will_execute'    => false,
			'action'          => 'Get customer service queue',
			'limit_per_queue' => isset( $params['limit'] ) ? min( max( absint( $params['limit'] ), 1 ), 20 ) : 5,
			'auto_send'       => false,
			'changes_store'   => false,
		];
	}

	public static function preview_find_orders_by_customer( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Find store orders by customer',
			'email'         => ! empty( $params['email'] ) ? YoRo_Order_Presenter::mask_email( $params['email'] ) : '',
			'phone'         => ! empty( $params['phone'] ) ? YoRo_Order_Presenter::mask_phone( $params['phone'] ) : '',
			'customer_id'   => ! empty( $params['customer_id'] ) ? absint( $params['customer_id'] ) : 0,
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	public static function preview_find_orders_by_identity( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Find store orders by shared identity',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'limit'         => ! empty( $params['limit'] ) ? absint( $params['limit'] ) : 20,
			'changes_store' => false,
		];
	}

	// Transitional bridge layer for extracted domain services.
	// These wrappers keep low-level query utilities in one place while
	// orchestration has moved into dedicated service classes.
	public static function briefing_normalize_operations_window_key( $window ) {
		return self::normalize_operations_window_key( $window );
	}

	public static function briefing_get_operations_window( $window_key ) {
		return self::get_operations_window( $window_key );
	}

	public static function briefing_get_all_order_status_slugs() {
		return self::get_all_order_status_slugs();
	}

	public static function briefing_get_revenue_order_status_slugs() {
		return self::get_revenue_order_status_slugs();
	}

	public static function briefing_is_revenue_order_status( $status ) {
		return self::is_revenue_order_status( $status );
	}

	public static function briefing_count_orders( $args ) {
		return self::count_orders( $args );
	}

	public static function briefing_build_relative_cutoff( $modifier ) {
		return self::build_relative_cutoff( $modifier );
	}

	public static function report_query_orders( $args, $max_orders = 10000, $page_size = 250 ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args       = is_array( $args ) ? $args : [];
		$max_orders = min( max( absint( $max_orders ), 1 ), 20000 );
		$page_size  = min( max( absint( $page_size ), 1 ), 500 );
		$orders     = [];
		$page       = 1;

		unset( $args['offset'], $args['page'], $args['paged'], $args['paginate'] );

		while ( count( $orders ) < $max_orders ) {
			$query_args = $args;
			$query_args['limit']    = min( $page_size, $max_orders - count( $orders ) );
			$query_args['page']     = $page;
			$query_args['paginate'] = true;

			if ( $query_args['limit'] <= 0 ) {
				break;
			}

			$result      = wc_get_orders( $query_args );
			$page_orders = is_object( $result ) && isset( $result->orders ) ? $result->orders : $result;
			$page_orders = is_array( $page_orders ) ? $page_orders : [];
			$page_count  = count( $page_orders );
			$max_pages   = is_object( $result ) && isset( $result->max_num_pages ) ? absint( $result->max_num_pages ) : 0;

			if ( 0 === $page_count ) {
				break;
			}

			foreach ( $page_orders as $order ) {
				if ( $order instanceof WC_Order ) {
					$orders[] = $order;
				}
			}

			if ( $max_pages > 0 && $page >= $max_pages ) {
				break;
			}

			if ( 0 === $max_pages && $page_count < $query_args['limit'] ) {
				break;
			}

			$page++;
		}

		return $orders;
	}

	public static function operations_build_attention_queue( $key, $label, $description, $query_args, $limit ) {
		return self::build_attention_queue( $key, $label, $description, $query_args, $limit );
	}

	/** Created-age evidence for the bounded operational queues, never status duration. */
	public static function queue_order_age_hours( WC_Order $order, $observed_at = null ) {
		try {
			$created = $order->get_date_created();
			$timestamp = $created instanceof DateTimeInterface ? $created->getTimestamp() : null;
			$now = null === $observed_at ? current_datetime()->getTimestamp() : $observed_at;
		} catch ( Throwable $error ) {
			return self::queue_age_error();
		}
		if ( ! is_int( $timestamp ) || $timestamp <= 0 || $timestamp > $now ) {
			return self::queue_age_error();
		}
		return (int) floor( ( $now - $timestamp ) / HOUR_IN_SECONDS );
	}

	public static function queue_age_error() {
		return new WP_Error( 'yoro_queue_age_unavailable', __( 'The queue could not be read because a required order creation time is unavailable or invalid.', 'yoro-ai-core' ) );
	}

	/** Query the existing bounded slice without treating a query failure as an empty store. */
	public static function queue_query_orders( $args ) {
		try {
			$result = wc_get_orders( $args );
		} catch ( Throwable $error ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( ! empty( $args['paginate'] ) ) {
			return is_object( $result ) && isset( $result->total ) && is_numeric( $result->total ) && $result->total >= 0
				? $result : new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		if ( ! is_array( $result ) || count( array_filter( $result, static function( $row ) { return $row instanceof WC_Order; } ) ) !== count( $result ) ) {
			return new WP_Error( 'yoro_queue_query_failed', __( 'The queue data could not be read.', 'yoro-ai-core' ) );
		}
		return $result;
	}

	public static function queue_count_orders( $args ) {
		$result = self::queue_query_orders( wp_parse_args( $args, [ 'limit' => 1, 'paginate' => true, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC' ] ) );
		return is_wp_error( $result ) ? $result : (int) $result->total;
	}

	/** Internal observation, moved to wordpress.execution.meta before returning the queue. */
	private static function queue_observation( $count, $visible, $limit, $sample_size = null ) {
		return [
			'captured_at' => gmdate( 'c' ),
			'age_basis' => 'date_created',
			'status_duration' => 'unknown',
			'count_basis' => null === $sample_size ? 'query_matches' : 'sample_matches',
			'match_count' => $count,
			'visible_memberships' => $visible,
			'limit' => $limit,
			'sample_size' => $sample_size,
			'candidate_limit' => null === $sample_size ? null : 50,
			'complete' => null === $sample_size || $sample_size < 50,
			'visible_truncated' => $count > $visible,
		];
	}

	public static function queue_evidence( &$queues, $orders ) {
		$evidence = [ 'version' => 1, 'captured_at' => gmdate( 'c' ), 'visible_memberships' => array_sum( array_map( static function( $queue ) { return count( $queue['orders'] ); }, $queues ) ), 'age_basis' => 'date_created', 'status_duration' => 'unknown', 'count_basis' => 'queue_memberships', 'unique_count_scope' => 'visible_sample', 'visible_unique_orders' => count( $orders ), 'queues' => [] ];
		foreach ( $queues as &$queue ) {
			$evidence['queues'][ $queue['key'] ] = $queue['_evidence'];
			unset( $queue['_evidence'] );
		}
		unset( $queue );
		return $evidence;
	}

	/** One order identity per result; keep the strongest existing score and all observed reasons. */
	public static function queue_case_rankings( $rankings ) {
		usort( $rankings, static function( $a, $b ) {
			return ( (float) $b['score'] <=> (float) $a['score'] )
				?: ( (int) $a['order']['id'] <=> (int) $b['order']['id'] )
				?: strcmp( $a['topic'], $b['topic'] );
		} );
		$cases = [];
		foreach ( $rankings as $case ) {
			$id = (int) $case['order']['id'];
			if ( ! isset( $cases[ $id ] ) ) {
				$case['order']['case_priority_score'] = $case['score'];
				$case['order']['queue_topic'] = $case['topic'];
				$case['order']['extensions']['woocommerce.queue_evidence']['reasons'] = [];
				$cases[ $id ] = $case;
			}
			$cases[ $id ]['order']['extensions']['woocommerce.queue_evidence']['reasons'][ $case['topic'] ] ??= [ 'topic' => $case['topic'], 'label' => $case['queue_label'], 'description' => $case['queue_description'], 'score' => $case['score'] ];
		}
		foreach ( $cases as &$case ) {
			ksort( $case['order']['extensions']['woocommerce.queue_evidence']['reasons'] );
			$case['order']['extensions']['woocommerce.queue_evidence']['reasons'] = array_values( $case['order']['extensions']['woocommerce.queue_evidence']['reasons'] );
		}
		unset( $case );
		return array_values( $cases );
	}

	public static function operations_build_high_value_open_queue( $limit, $threshold ) {
		return self::build_high_value_open_queue( $limit, $threshold );
	}

	public static function customer_service_build_queue( $key, $label, $description, $query_args, $limit, $reason ) {
		return self::build_customer_service_queue( $key, $label, $description, $query_args, $limit, $reason );
	}

	public static function customer_service_build_saved_email_drafts_queue( $limit ) {
		return self::build_saved_email_drafts_queue( $limit );
	}

	public static function fulfillment_build_filter_queue( $key, $label, $description, $limit, $filter, $reason ) {
		return self::build_fulfillment_filter_queue( $key, $label, $description, $limit, $filter, $reason );
	}

	public static function fulfillment_get_processing_candidates( $limit = 50 ) {
		return self::get_processing_fulfillment_candidates( $limit );
	}

	public static function fulfillment_build_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason ) {
		return self::build_fulfillment_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason );
	}

	public static function fulfillment_order_has_tracking( WC_Order $order ) {
		return self::order_has_tracking( $order );
	}

	public static function fulfillment_order_has_incomplete_shipping_address( WC_Order $order ) {
		return self::order_has_incomplete_shipping_address( $order );
	}

	public static function fulfillment_order_is_ready_to_fulfill( WC_Order $order ) {
		return self::order_is_ready_to_fulfill( $order );
	}

	public static function fulfillment_order_requires_shipping( WC_Order $order ) {
		return self::order_requires_shipping( $order );
	}

	public static function fulfillment_order_is_older_than_hours( WC_Order $order, $hours ) {
		return self::get_order_age_hours( $order ) > max( 0, (int) $hours );
	}

	public static function inventory_build_query_queue( $key, $label, $description, $query_args, $limit, $filter = null ) {
		return self::build_inventory_query_queue( $key, $label, $description, $query_args, $limit, $filter );
	}

	public static function inventory_build_recent_processing_queue( $limit ) {
		return self::build_inventory_recent_processing_queue( $limit );
	}

	public static function inventory_product_is_low_stock( WC_Product $product ) {
		return self::product_is_low_stock( $product );
	}

	public static function report_get_product_category_context( $product_id, &$category_cache = [] ) {
		return self::get_product_category_context( $product_id, $category_cache );
	}

	public static function report_record_category_mix( &$category_map, $category_context, $product_key, $quantity, $total ) {
		self::record_category_mix( $category_map, $category_context, $product_key, $quantity, $total );
	}

	public static function report_format_category_mix( $category_map, $limit = 5 ) {
		return self::format_category_mix( $category_map, $limit );
	}

	private static function get_product_category_context( $product_id, &$category_cache = [] ) {
		$product_id = absint( $product_id );

		if ( $product_id <= 0 || ! function_exists( 'taxonomy_exists' ) || ! taxonomy_exists( 'product_cat' ) ) {
			return self::default_product_category_context();
		}

		if ( isset( $category_cache[ $product_id ] ) ) {
			return $category_cache[ $product_id ];
		}

		$terms = wp_get_post_terms(
			$product_id,
			'product_cat',
			[
				'orderby' => 'name',
				'order'   => 'ASC',
			]
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			$category_cache[ $product_id ] = self::default_product_category_context();
			return $category_cache[ $product_id ];
		}

		$categories = [];

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$categories[] = [
				'id'   => absint( $term->term_id ),
				'name' => sanitize_text_field( $term->name ),
				'slug' => sanitize_key( $term->slug ),
			];
		}

		if ( empty( $categories ) ) {
			$category_cache[ $product_id ] = self::default_product_category_context();
			return $category_cache[ $product_id ];
		}

		$primary = $categories[0];

		$category_cache[ $product_id ] = [
			'primary_id'   => absint( $primary['id'] ?? 0 ),
			'primary_name' => sanitize_text_field( $primary['name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) ),
			'categories'   => $categories,
		];

		return $category_cache[ $product_id ];
	}

	private static function default_product_category_context() {
		return [
			'primary_id'   => 0,
			'primary_name' => __( 'Uncategorized', 'yoro-ai-core' ),
			'categories'   => [],
		];
	}

	private static function record_category_mix( &$category_map, $category_context, $product_key, $quantity, $total ) {
		$category_context = is_array( $category_context ) ? $category_context : self::default_product_category_context();
		$category_id      = absint( $category_context['primary_id'] ?? 0 );
		$category_name    = sanitize_text_field( $category_context['primary_name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) );
		$category_key     = $category_id > 0 ? 'category:' . $category_id : 'category:uncategorized';

		if ( ! isset( $category_map[ $category_key ] ) ) {
			$category_map[ $category_key ] = [
				'id'           => $category_id,
				'name'         => $category_name,
				'quantity'     => 0.0,
				'total'        => 0.0,
				'product_keys' => [],
			];
		}

		$category_map[ $category_key ]['quantity'] += (float) $quantity;
		$category_map[ $category_key ]['total']    += (float) $total;

		$product_key = sanitize_text_field( $product_key );

		if ( '' !== $product_key ) {
			$category_map[ $category_key ]['product_keys'][ $product_key ] = true;
		}
	}

	private static function format_category_mix( $category_map, $limit = 5 ) {
		$categories = array_values( is_array( $category_map ) ? $category_map : [] );

		usort(
			$categories,
			function( $a, $b ) {
				return ( $b['total'] ?? 0 ) <=> ( $a['total'] ?? 0 );
			}
		);

		$categories = array_slice( $categories, 0, min( max( absint( $limit ), 1 ), 20 ) );

		return array_map(
			function( $category ) {
				return [
					'id'            => absint( $category['id'] ?? 0 ),
					'name'          => sanitize_text_field( $category['name'] ?? __( 'Uncategorized', 'yoro-ai-core' ) ),
					'quantity'      => wc_format_decimal( $category['quantity'] ?? 0, 0 ),
					'total'         => wc_format_decimal( $category['total'] ?? 0, 2 ),
					'product_lines' => count( is_array( $category['product_keys'] ?? null ) ? $category['product_keys'] : [] ),
				];
			},
			$categories
		);
	}

	private static function get_customer_orders( $email = '', $phone = '', $limit = 20, $customer_name = '', $customer_id = 0 ) {
		$args = [
			'limit'   => min( max( absint( $limit ), 1 ), 50 ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => self::get_all_order_status_slugs(),
		];

		if ( $email ) {
			$args['billing_email'] = sanitize_email( $email );
		}

		if ( $customer_id ) {
			$args['customer_id'] = absint( $customer_id );
		}

		$orders = wc_get_orders( $args );

		if ( $phone ) {
			$needle = self::normalize_phone_digits( $phone );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $needle ) {
					return $order instanceof WC_Order
						&& $needle
						&& false !== strpos( self::normalize_phone_digits( $order->get_billing_phone() ), $needle );
				}
			);
		}

		if ( $customer_name ) {
			$name_needle = strtolower( trim( sanitize_text_field( $customer_name ) ) );

			$orders = array_filter(
				$orders,
				function( $order ) use ( $name_needle ) {
					if ( ! $order instanceof WC_Order ) {
						return false;
					}

					$billing_name = strtolower(
						trim(
							$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
						)
					);

					$shipping_name = strtolower(
						trim(
							$order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()
						)
					);

					return (
						'' !== $name_needle &&
						(
							false !== strpos( $billing_name, $name_needle ) ||
							false !== strpos( $shipping_name, $name_needle )
						)
					);
				}
			);
		}

		return array_values( $orders );
	}

	private static function normalize_operations_window_key( $window ) {
		$window_key = YoRo_Date_Helper::normalize_window_key( $window );

		return '' !== $window_key ? $window_key : 'today';
	}

	private static function get_operations_window( $window_key ) {
		$window = YoRo_Date_Helper::resolve_window( $window_key );

		if ( empty( $window ) ) {
			$window = YoRo_Date_Helper::resolve_window( 'today' );
		}

		return $window;
	}

	private static function build_relative_cutoff( $modifier ) {
		$cutoff = current_datetime();
		$cutoff = $cutoff->modify( $modifier );

		return $cutoff->format( 'Y-m-d H:i:s' );
	}

	private static function build_attention_queue( $key, $label, $description, $query_args, $limit ) {
		$count  = self::queue_count_orders( $query_args );
		$orders = self::query_attention_orders( $query_args, $limit );

		if ( is_wp_error( $count ) || is_wp_error( $orders ) ) {
			return is_wp_error( $count ) ? $count : $orders;
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit ),
		];
	}

	private static function build_customer_service_queue( $key, $label, $description, $query_args, $limit, $reason ) {
		$count  = self::queue_count_orders( $query_args );
		$orders = self::query_customer_service_orders( $query_args, $limit, $reason );

		if ( is_wp_error( $count ) || is_wp_error( $orders ) ) {
			return is_wp_error( $count ) ? $count : $orders;
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit ),
		];
	}

	private static function build_fulfillment_filter_queue( $key, $label, $description, $limit, $filter, $reason ) {
		return self::build_fulfillment_candidate_queue(
			$key,
			$label,
			$description,
			self::get_processing_fulfillment_candidates( 50 ),
			$limit,
			$filter,
			$reason
		);
	}

	private static function get_processing_fulfillment_candidates( $limit = 50 ) {
		return self::queue_query_orders(
			[
				'limit'   => min( max( absint( $limit ), 1 ), 200 ),
				'status'  => [ 'processing' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);
	}

	private static function build_fulfillment_candidate_queue( $key, $label, $description, $candidates, $limit, $filter, $reason ) {
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$candidates = is_array( $candidates ) ? $candidates : [];
		$orders     = [];
		$count      = 0;

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || ! is_callable( $filter ) || ! call_user_func( $filter, $order ) ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item = self::format_fulfillment_order( $order, $reason );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$orders[] = $item;
			}
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
		];
	}

	private static function build_inventory_query_queue( $key, $label, $description, $query_args, $limit, $filter = null ) {
		$products = wc_get_products(
			wp_parse_args(
				$query_args,
				[
					'limit'   => 50,
					'status'  => 'publish',
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data  = [];
		$count = 0;

		foreach ( $products as $product ) {
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			if ( is_callable( $filter ) && ! call_user_func( $filter, $product ) ) {
				continue;
			}

			$count++;

			if ( count( $data ) < $limit ) {
				$data[] = self::format_inventory_product( $product );
			}
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => $description,
			'count'       => $count,
			'products'    => $data,
		];
	}

	private static function build_inventory_recent_processing_queue( $limit ) {
		$orders = wc_get_orders(
			[
				'limit'        => 25,
				'status'       => [ 'processing' ],
				'date_created' => self::build_relative_cutoff( '-14 days' ) . '...' . self::build_relative_cutoff( 'now' ),
				'orderby'      => 'date',
				'order'        => 'DESC',
			]
		);

		$products = [];
		$count    = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			foreach ( $order->get_items( 'line_item' ) as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();

				if ( ! $product instanceof WC_Product || ! self::product_is_low_stock( $product ) ) {
					continue;
				}

				$product_id = $product->get_id();

				if ( isset( $products[ $product_id ] ) ) {
					$products[ $product_id ]['open_order_count']++;
					continue;
				}

				$count++;

				if ( count( $products ) < $limit ) {
					$data                     = self::format_inventory_product( $product );
					$data['open_order_count'] = 1;
					$products[ $product_id ]  = $data;
				}
			}
		}

		return [
			'key'         => 'low_stock_in_processing_orders',
			'label'       => __( 'Low stock in processing orders', 'yoro-ai-core' ),
			'description' => __( 'Low-stock products that appear in recent processing orders.', 'yoro-ai-core' ),
			'count'       => $count,
			'products'    => array_values( $products ),
		];
	}

	private static function map_counts_to_items( $counts ) {
		$items = [];

		foreach ( $counts as $key => $count ) {
			$items[] = [
				'key'   => $key,
				'label' => YoRo_Order_Helper::get_order_status_label( $key ),
				'count' => absint( $count ),
			];
		}

		usort(
			$items,
			function( $a, $b ) {
				return ( $b['count'] ?? 0 ) <=> ( $a['count'] ?? 0 );
			}
		);

		return $items;
	}

	private static function order_status_count_labels( $counts ) {
		$labels = [];

		foreach ( is_array( $counts ) ? $counts : [] as $status => $count ) {
			$labels[ $status ] = YoRo_Order_Helper::get_order_status_label( $status );
		}

		return $labels;
	}

	private static function count_products_by_stock_status( $stock_status ) {
		$products = wc_get_products(
			[
				'limit'        => -1,
				'status'       => 'publish',
				'stock_status' => sanitize_key( $stock_status ),
				'return'       => 'ids',
			]
		);

		return is_array( $products ) ? count( $products ) : 0;
	}

	private static function count_low_stock_products() {
		$products = wc_get_products(
			[
				'limit'        => 100,
				'status'       => 'publish',
				'manage_stock' => true,
				'return'       => 'objects',
			]
		);

		$count = 0;

		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product && self::product_is_low_stock( $product ) ) {
				$count++;
			}
		}

		return $count;
	}

	private static function build_saved_email_drafts_queue( $limit ) {
		$candidates = self::queue_query_orders(
			[
				'limit'   => 50,
				'status'  => self::get_all_order_status_slugs(),
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );

			if ( ! is_array( $drafts ) || empty( $drafts ) ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item                = self::format_customer_service_order(
					$order,
					__( 'Saved email draft is waiting for review or send.', 'yoro-ai-core' )
				);
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$item['draft_count'] = count( $drafts );
				$orders[]            = $item;
			}
		}

		return [
			'key'         => 'saved_email_drafts',
			'label'       => __( 'Saved email drafts', 'yoro-ai-core' ),
			'description' => __( 'Orders with saved customer email drafts that have not been sent by YoRo.', 'yoro-ai-core' ),
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
		];
	}

	private static function build_high_value_open_queue( $limit, $threshold ) {
		$candidates = self::queue_query_orders(
			[
				'limit'   => 50,
				'status'  => [ 'pending', 'processing', 'on-hold' ],
				'orderby' => 'date',
				'order'   => 'DESC',
			]
		);

		$orders = [];
		$count  = 0;

		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		foreach ( $candidates as $order ) {
			if ( ! $order instanceof WC_Order || (float) $order->get_total() < $threshold ) {
				continue;
			}

			$count++;

			if ( count( $orders ) < $limit ) {
				$item = self::format_attention_order( $order );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$orders[] = $item;
			}
		}

		return [
			'key'         => 'high_value_open_orders',
			'label'       => __( 'High-value open orders', 'yoro-ai-core' ),
			'description' => sprintf(
				/* translators: %s: order total threshold. */
				__( 'Open orders with total greater than or equal to %s. This is an operations threshold, not fraud scoring.', 'yoro-ai-core' ),
				get_woocommerce_currency_symbol() . wc_format_decimal( $threshold, wc_get_price_decimals() )
			),
			'count'       => $count,
			'orders'      => $orders,
			'_evidence'   => self::queue_observation( $count, count( $orders ), $limit, count( $candidates ) ),
		];
	}

	private static function query_attention_orders( $query_args, $limit ) {
		$orders = self::queue_query_orders(
			wp_parse_args(
				$query_args,
				[
					'limit'   => $limit,
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data = [];

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$item = self::format_attention_order( $order );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$data[] = $item;
			}
		}

		return $data;
	}

	private static function format_inventory_product( WC_Product $product ) {
		$stock_quantity = $product->get_stock_quantity();
		$low_threshold  = self::get_product_low_stock_threshold( $product );
		$product_id     = $product->get_id();
		$edit_url       = get_edit_post_link( $product_id, 'raw' );

		if ( ! $edit_url ) {
			$edit_url = admin_url( 'post.php?post=' . absint( $product_id ) . '&action=edit' );
		}

		$inventory_target_meta = class_exists( 'YoRo_Store_Inventory_Service' )
			? YoRo_Store_Inventory_Service::product_inventory_target_meta( $product )
			: [];

		return array_merge(
			[
			'id'                  => $product_id,
			'name'                => $product->get_name(),
			'sku'                 => $product->get_sku(),
			'type'                => $product->get_type(),
			'type_label'          => YoRo_Product_Helper::get_product_type_label( $product->get_type() ),
			'status'              => $product->get_status(),
			'status_label'        => YoRo_Product_Helper::get_product_status_label( $product->get_status() ),
			'stock_status'        => $product->get_stock_status(),
			'stock_status_label'  => YoRo_Product_Helper::get_stock_status_label( $product->get_stock_status() ),
			'manage_stock'        => $product->managing_stock(),
			'stock_quantity'      => null === $stock_quantity ? '' : $stock_quantity,
			'low_stock_threshold' => $low_threshold,
			'backorders_allowed'  => $product->backorders_allowed(),
			'price'               => $product->get_price(),
			'edit_url'            => $edit_url,
			],
			$inventory_target_meta
		);
	}

	private static function product_is_low_stock( WC_Product $product ) {
		if ( ! $product->managing_stock() ) {
			return false;
		}

		$quantity  = $product->get_stock_quantity();
		$threshold = self::get_product_low_stock_threshold( $product );

		return null !== $quantity && $quantity <= $threshold;
	}

	private static function get_product_low_stock_threshold( WC_Product $product ) {
		$threshold = $product->get_low_stock_amount();

		if ( '' === $threshold || null === $threshold ) {
			$threshold = get_option( 'woocommerce_notify_low_stock_amount', 2 );
		}

		return max( 0, (int) $threshold );
	}

	private static function query_customer_service_orders( $query_args, $limit, $reason ) {
		$orders = self::queue_query_orders(
			wp_parse_args(
				$query_args,
				[
					'limit'   => $limit,
					'orderby' => 'date',
					'order'   => 'DESC',
				]
			)
		);

		$data = [];

		if ( is_wp_error( $orders ) ) {
			return $orders;
		}

		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$item = self::format_customer_service_order( $order, $reason );
				if ( is_wp_error( $item ) ) {
					return $item;
				}
				$data[] = $item;
			}
		}

		return $data;
	}

	private static function format_attention_order( WC_Order $order ) {
		$captured_at = time();
		$age = self::queue_order_age_hours( $order, $captured_at );
		if ( is_wp_error( $age ) ) {
			return $age;
		}
		$data = YoRo_Order_Presenter::format_order_summary( $order );

		$data['age_hours'] = $age;
		$data['extensions']['woocommerce.queue_evidence'] = [ 'age_basis' => 'date_created', 'status_duration' => 'unknown', 'captured_at' => gmdate( 'c', $captured_at ) ];

		return $data;
	}

	private static function format_customer_service_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['customer_name']      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$data['followup_reason']    = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Show order ' . $order->get_id() . ' details';
		$data['customer_id']        = $order->get_customer_id();

		return $data;
	}

	private static function format_fulfillment_order( WC_Order $order, $reason ) {
		$data = self::format_attention_order( $order );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$data['shipping_name']      = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		$data['shipping_city']      = $order->get_shipping_city();
		$data['shipping_country']   = $order->get_shipping_country();
		$data['shipping_method']    = self::get_order_shipping_method_label( $order );
		$data['tracking_detected']  = self::order_has_tracking( $order );
		$data['fulfillment_reason'] = sanitize_text_field( $reason );
		$data['suggested_command']  = 'Show order ' . $order->get_id() . ' details';

		return $data;
	}

	private static function order_has_tracking( WC_Order $order ) {
		$tracking_meta_keys = [
			'_tracking_number',
			'_wc_shipment_tracking_items',
			'_aftership_tracking_number',
			'_aftership_tracking_items',
			'_wcast_tracking_number',
			'_wcst_order_trackno',
			'_yith_tracking_code',
		];

		foreach ( $tracking_meta_keys as $key ) {
			$value = $order->get_meta( $key, true );

			if ( is_array( $value ) && ! empty( $value ) ) {
				return true;
			}

			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true;
			}
		}

		return false;
	}

	private static function order_has_incomplete_shipping_address( WC_Order $order ) {
		if ( self::fulfillment_shipping_requirements_disabled() ) {
			return false;
		}

		$country  = trim( (string) $order->get_shipping_country() );
		$city     = trim( (string) $order->get_shipping_city() );
		$address1 = trim( (string) $order->get_shipping_address_1() );

		if ( '' === $country && '' === $city && '' === $address1 ) {
			$country  = trim( (string) $order->get_billing_country() );
			$city     = trim( (string) $order->get_billing_city() );
			$address1 = trim( (string) $order->get_billing_address_1() );
		}

		return '' === $country || '' === $city || '' === $address1;
	}

	private static function order_is_ready_to_fulfill( WC_Order $order ) {
		if ( 'processing' !== $order->get_status() ) {
			return false;
		}

		if ( ! self::order_requires_shipping( $order ) ) {
			return false;
		}

		if ( ! self::order_has_tracking( $order ) ) {
			return false;
		}

		if ( self::order_has_incomplete_shipping_address( $order ) ) {
			return false;
		}

		if ( self::get_order_age_hours( $order ) > 48 ) {
			return false;
		}

		if ( ! self::order_has_valid_shipping_method( $order ) ) {
			return false;
		}

		return true;
	}

	private static function order_requires_shipping( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product ) {
				return true;
			}

			if ( ! $product->is_virtual() ) {
				return true;
			}
		}

		return false;
	}

	private static function order_has_valid_shipping_method( WC_Order $order ) {
		if ( self::fulfillment_shipping_requirements_disabled() ) {
			return true;
		}

		return '' !== trim( (string) self::get_order_shipping_method_label( $order ) );
	}

	private static function fulfillment_shipping_requirements_disabled() {
		return 'disabled' === sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', '' ) );
	}

	private static function get_order_shipping_method_label( WC_Order $order ) {
		$methods = [];

		foreach ( $order->get_shipping_methods() as $method ) {
			if ( is_object( $method ) && method_exists( $method, 'get_name' ) ) {
				$methods[] = $method->get_name();
			}
		}

		return implode( ', ', array_filter( $methods ) );
	}

	private static function get_order_age_hours( WC_Order $order ) {
		$date_created = $order->get_date_created();

		if ( ! $date_created ) {
			return 0;
		}

		$created = $date_created->getTimestamp();
		$now     = current_datetime()->getTimestamp();

		return max( 0, (int) floor( ( $now - $created ) / HOUR_IN_SECONDS ) );
	}

	private static function count_orders( $args ) {
		$query_args = wp_parse_args(
			$args,
			[
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
				'orderby'  => 'date',
				'order'    => 'DESC',
			]
		);

		$result = wc_get_orders( $query_args );

		if ( is_object( $result ) && isset( $result->total ) ) {
			return absint( $result->total );
		}

		if ( is_array( $result ) ) {
			return count( $result );
		}

		return 0;
	}

	private static function get_all_order_status_slugs() {
		return array_map(
			function( $key ) {
				return str_replace( 'wc-', '', $key );
			},
			array_keys( wc_get_order_statuses() )
		);
	}

	private static function get_revenue_order_status_slugs() {
		$statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : [];

		if ( empty( $statuses ) || ! is_array( $statuses ) ) {
			$statuses = [ 'processing', 'completed' ];
		}

		// WooCommerce reports commonly treat on-hold orders as reportable sales activity.
		$statuses[] = 'on-hold';
		$statuses   = apply_filters( 'yoro_ai_sales_report_order_statuses', $statuses );
		$statuses   = is_array( $statuses ) ? $statuses : [];
		$normalized = [];

		foreach ( $statuses as $status ) {
			$status = self::normalize_order_status_slug( $status );

			if ( '' !== $status ) {
				$normalized[] = $status;
			}
		}

		$available  = self::get_all_order_status_slugs();
		$normalized = array_values( array_unique( array_intersect( $normalized, $available ) ) );

		if ( ! empty( $normalized ) ) {
			return $normalized;
		}

		return array_values( array_intersect( [ 'processing', 'completed', 'on-hold' ], $available ) );
	}

	private static function is_revenue_order_status( $status ) {
		return in_array( self::normalize_order_status_slug( $status ), self::get_revenue_order_status_slugs(), true );
	}

	private static function normalize_order_status_slug( $status ) {
		return sanitize_key( preg_replace( '/^wc-/', '', (string) $status ) );
	}

	private static function build_order_identity( WC_Order $order ) {
		$email = strtolower( sanitize_email( $order->get_billing_email() ) );
		$phone = $order->get_billing_phone();

		return [
			'email'        => YoRo_Order_Presenter::mask_email( $email ),
			'email_raw'    => $email,
			'phone'        => YoRo_Order_Presenter::mask_phone( $phone ),
			'phone_digits' => self::normalize_phone_digits( $phone ),
			'ip'           => sanitize_text_field( (string) $order->get_customer_ip_address() ),
			'user_agent'   => sanitize_text_field( (string) $order->get_customer_user_agent() ),
			'device_id'    => sanitize_text_field( (string) $order->get_meta( '_yoro_device_id', true ) ),
		];
	}

	private static function get_order_fee_total( WC_Order $order ) {
		$total = 0;

		foreach ( $order->get_items( 'fee' ) as $fee ) {
			if ( $fee instanceof WC_Order_Item_Fee ) {
				$total += (float) $fee->get_total();
			}
		}

		return $total;
	}

	private static function normalize_phone_digits( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}
}
