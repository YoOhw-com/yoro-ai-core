<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Bounded observations from the characterized owner's retained read contracts. */
final class YoRo_Order_Splitter_Service {
	private const STATUSES = [ 'started', 'committed', 'completed', 'failed', 'recovery_required', 'compensating', 'compensated', 'manual_reconciliation', 'manual_reconciled' ];
	private const STAGES = [ 'bulk_return_row_completed', 'child_persisted', 'compensated', 'compensating', 'compensation_child_removed', 'compensation_source_restored', 'completed', 'copy_context_captured', 'failed', 'manual_reconciled', 'manual_reconciliation', 'manual_reconciliation_evidence', 'merge_commercial_verified', 'merge_compensation_source_restored', 'merge_compensation_target_restored', 'merge_recovery_component_checkpoint', 'merge_relations_completed', 'merge_service_checkpoint', 'recovery_required', 'resumed', 'return_active_split_relation_cleaned', 'return_child_relation_partial', 'return_child_relation_persisted', 'return_compensation_child_restored', 'return_compensation_original_restored', 'return_pair_verified', 'return_recovery_component_checkpoint', 'return_relations_completed', 'return_service_checkpoint', 'source_commit_planned', 'source_committed', 'source_persisted', 'started', 'stock_flags_synchronized', 'target_persisted' ];

	private static function coverage( $available ) {
		return [ 'split_lineage' => $available ? 'available' : 'not_observed', 'retained_activity' => $available ? 'available' : 'not_observed', 'omitted_fields' => true ];
	}

	private static function owner() {
		$loaded = class_exists( 'WooCommerce_Order_Splitter', false );
		$available = $loaded && defined( 'WC_ORDER_SPLITTER_VERSION' ) && '1.5.0' === WC_ORDER_SPLITTER_VERSION
			&& class_exists( 'WCOS_Order_Relation_Repository', false ) && is_callable( [ 'WCOS_Order_Relation_Repository', 'find' ] )
			&& class_exists( 'WCOS_Operation_Journal', false ) && class_exists( 'WCOS_Split_Order_Service', false )
			&& defined( 'WCOS_Operation_Journal::SUMMARY_META_KEY' ) && '_wcos_operation_journal' === WCOS_Operation_Journal::SUMMARY_META_KEY
			&& defined( 'WCOS_Operation_Journal::MAX_SUMMARY_ENTRIES' ) && 20 === WCOS_Operation_Journal::MAX_SUMMARY_ENTRIES
			&& defined( 'WCOS_Split_Order_Service::RELATION_PARENT_META' ) && '_wcos_parent_order_id' === WCOS_Split_Order_Service::RELATION_PARENT_META
			&& defined( 'WCOS_Split_Order_Service::RELATION_CHILDREN_META' ) && '_wcos_child_order_ids' === WCOS_Split_Order_Service::RELATION_CHILDREN_META
			&& false !== has_filter( 'woocommerce_order_query_args', [ 'WCOS_Order_Relation_Repository', 'tokenize_legacy_query' ] )
			&& false !== has_filter( 'woocommerce_order_data_store_cpt_get_orders_query', [ 'WCOS_Order_Relation_Repository', 'inject_legacy_query' ] );
		$status = $available ? 'available' : ( $loaded ? 'incompatible' : 'absent' );
		return [ 'status' => $status, 'owner_kind' => $available ? 'order_splitter' : $status, 'version' => $available ? '1.5.0' : '', 'coverage' => self::coverage( $available ) ];
	}

	private static function input( $params, $target ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) ) { return new WP_Error( 'order_splitter_invalid_input', 'Invalid read parameters.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		if ( array_diff( array_keys( $params ), $target ? [ 'order_id' ] : [] ) || ( $target && ( ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) ) ) {
			return new WP_Error( 'order_splitter_invalid_input', 'Only the exact read parameters are accepted.' );
		}
		return $params;
	}

	private static function response( $action, array $data ) {
		$type = 'order_splitter.get_status' === $action ? 'order_splitter_status' : 'order_splitter_order_activity';
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [],
			'display_type' => $type, 'result_type' => $type, 'message' => 'Loaded bounded Order Splitter observations.', 'summary' => 'Loaded bounded Order Splitter observations.' ], $data );
	}

	public static function get_status( $params ) {
		$input = self::input( $params, false );
		return is_wp_error( $input ) ? $input : self::response( 'order_splitter.get_status', self::owner() );
	}

	private static function exact_order( $order, $id, $blog ) {
		return get_current_blog_id() === $blog && $order instanceof WC_Order && ! $order instanceof WC_Order_Refund
			&& $order->get_id() === $id && 'shop_order' === $order->get_type();
	}

	/** Reject duplicate rows rather than selecting one arbitrary metadata value. */
	private static function meta( WC_Order $order, $key, $default ) {
		$rows = $order->get_meta( $key, false, 'edit' );
		if ( ! is_array( $rows ) || count( $rows ) > 1 ) { throw new RuntimeException( 'Ambiguous evidence.' ); }
		if ( ! $rows ) { return $default; }
		$row = reset( $rows );
		if ( ! $row instanceof WC_Meta_Data ) { throw new RuntimeException( 'Invalid evidence.' ); }
		return $row->value;
	}

	private static function stored_id( $value ) {
		if ( is_int( $value ) && $value > 0 ) { return $value; }
		if ( is_string( $value ) && preg_match( '/^[1-9][0-9]{0,18}$/D', $value ) && (string) (int) $value === $value ) { return (int) $value; }
		throw new RuntimeException( 'Invalid relation identity.' );
	}

	private static function ids( $values ) {
		if ( ! is_array( $values ) || array_keys( $values ) !== array_keys( array_values( $values ) ) || count( $values ) > 50 ) { throw new RuntimeException( 'Invalid relation list.' ); }
		$ids = array_map( [ __CLASS__, 'stored_id' ], $values );
		if ( count( array_unique( $ids ) ) !== count( $ids ) ) { throw new RuntimeException( 'Duplicate relation.' ); }
		sort( $ids, SORT_NUMERIC );
		return $ids;
	}

	private static function lineage( WC_Order $order, $blog ) {
		$parent_value = self::meta( $order, WCOS_Split_Order_Service::RELATION_PARENT_META, null );
		$parent = null === $parent_value ? 0 : self::stored_id( $parent_value );
		$declared = self::ids( self::meta( $order, WCOS_Split_Order_Service::RELATION_CHILDREN_META, [] ) );
		// One extra observation detects overflow; at most 50 identities can be emitted.
		$children = WCOS_Order_Relation_Repository::find( [ [ 'key' => WCOS_Split_Order_Service::RELATION_PARENT_META, 'value' => $order->get_id(), 'type' => 'NUMERIC' ] ], 51 );
		if ( get_current_blog_id() !== $blog || ! is_array( $children ) || count( $children ) > 50 ) { throw new RuntimeException( 'Unverifiable relations.' ); }
		$ids = [];
		foreach ( $children as $child ) {
			$id = $child instanceof WC_Order ? $child->get_id() : 0;
			if ( ! is_int( $id ) || $id < 1 || $id === $order->get_id() || ! self::exact_order( $child, $id, $blog )
				|| self::stored_id( self::meta( $child, WCOS_Split_Order_Service::RELATION_PARENT_META, null ) ) !== $order->get_id() ) { throw new RuntimeException( 'Invalid child relation.' ); }
			$ids[] = $id;
		}
		$ids = self::ids( $ids );
		if ( $ids !== $declared || $parent === $order->get_id() || ( $parent && $ids ) ) { throw new RuntimeException( 'Contradictory relation.' ); }
		if ( $parent ) {
			$source = wc_get_order( $parent );
			if ( ! self::exact_order( $source, $parent, $blog ) || ! in_array( $order->get_id(), self::ids( self::meta( $source, WCOS_Split_Order_Service::RELATION_CHILDREN_META, [] ) ), true ) ) { throw new RuntimeException( 'Unverifiable parent.' ); }
		}
		return [ 'role' => $parent ? 'child' : ( $ids ? 'source' : 'none' ), 'parent_order_id' => $parent, 'child_order_ids' => $ids ];
	}

	private static function timestamp( $value, $optional = false ) {
		if ( $optional && null === $value ) { return ''; }
		if ( ! is_string( $value ) || strlen( $value ) > 40 ) { throw new RuntimeException( 'Invalid date.' ); }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:sP', $value );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'c' ) !== $value || (int) $date->format( 'Y' ) < 1970 ) { throw new RuntimeException( 'Invalid date.' ); }
		return $value;
	}

	private static function activity( WC_Order $order ) {
		$entries = self::meta( $order, WCOS_Operation_Journal::SUMMARY_META_KEY, [] );
		if ( ! is_array( $entries ) || count( $entries ) > 20 || array_keys( $entries ) !== array_keys( array_values( $entries ) ) ) { throw new RuntimeException( 'Invalid summary.' ); }
		$result = [];
		$seen = [];
		foreach ( $entries as $entry ) {
			$keys = [ 'operation_id', 'fingerprint', 'type', 'status', 'stage', 'revision', 'started_at', 'updated_at', 'completed_at' ];
			if ( ! is_array( $entry ) || array_diff( array_keys( $entry ), $keys ) || array_diff( $keys, array_keys( $entry ) )
				|| ! in_array( $entry['type'], [ 'split', 'duplicate', 'merge', 'return' ], true ) || ! in_array( $entry['status'], self::STATUSES, true )
				|| ! in_array( $entry['stage'], self::STAGES, true ) || ! is_int( $entry['revision'] ) || $entry['revision'] < 1 ) { throw new RuntimeException( 'Invalid summary entry.' ); }
			if ( ! is_string( $entry['operation_id'] ) || '' === $entry['operation_id'] || strlen( $entry['operation_id'] ) > 128 || isset( $seen[ $entry['operation_id'] ] )
				|| ! is_string( $entry['fingerprint'] ) || '' === $entry['fingerprint'] || strlen( $entry['fingerprint'] ) > 128 ) { throw new RuntimeException( 'Malformed summary identity.' ); }
			$seen[ $entry['operation_id'] ] = true;
			$projected = array_intersect_key( $entry, array_flip( [ 'type', 'status', 'stage', 'revision' ] ) );
			foreach ( [ 'started_at', 'updated_at', 'completed_at' ] as $field ) { $projected[ $field ] = self::timestamp( $entry[ $field ], 'completed_at' === $field ); }
			if ( strtotime( $projected['updated_at'] ) < strtotime( $projected['started_at'] ) || ( '' !== $projected['completed_at'] && strtotime( $projected['completed_at'] ) < strtotime( $projected['started_at'] ) ) ) { throw new RuntimeException( 'Contradictory summary time.' ); }
			$result[] = $projected;
		}
		return $result;
	}

	public static function get_order_activity( $params ) {
		$input = self::input( $params, true );
		if ( is_wp_error( $input ) ) { return $input; }
		$blog = get_current_blog_id();
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $input['order_id'] ) : false;
		if ( ! self::exact_order( $order, $input['order_id'], $blog ) ) { return new WP_Error( 'order_splitter_order_unavailable', 'Exact local order is unavailable.' ); }
		$owner = self::owner();
		$blank = [ 'order_id' => $input['order_id'], 'status' => 'unavailable', 'owner_kind' => $owner['owner_kind'], 'version' => $owner['version'], 'order_status' => '',
			'lineage' => [ 'role' => 'ambiguous', 'parent_order_id' => 0, 'child_order_ids' => [] ], 'activity' => [], 'manual_reconciliation' => 'not_observed', 'coverage' => self::coverage( false ) ];
		$data = $blank;
		try {
			if ( 'available' === $owner['status'] ) {
				$status = $order->get_status( 'edit' );
				if ( ! is_string( $status ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $status ) ) { throw new RuntimeException( 'Invalid status.' ); }
				$lineage = self::lineage( $order, $blog );
				if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site changed.' ); }
				$activity = self::activity( $order );
				$fresh = wc_get_order( $input['order_id'] );
				if ( ! self::exact_order( $fresh, $input['order_id'], $blog ) || $fresh->get_status( 'edit' ) !== $status || self::owner() !== $owner ) { throw new RuntimeException( 'Context changed.' ); }
				$data = array_replace( $data, [ 'status' => 'observed', 'order_status' => $status, 'lineage' => $lineage, 'activity' => $activity, 'coverage' => self::coverage( true ),
					'manual_reconciliation' => in_array( 'manual_reconciliation', array_column( $activity, 'status' ), true ) ? 'present' : 'not_observed' ] );
			}
		} catch ( Throwable $exception ) { $data = $blank; }
		return self::response( 'order_splitter.get_order_activity', $data );
	}
}
