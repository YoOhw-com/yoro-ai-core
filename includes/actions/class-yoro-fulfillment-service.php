<?php

use Automattic\WooCommerce\Admin\Features\Fulfillments\Fulfillment;
use Automattic\WooCommerce\Admin\Features\Fulfillments\FulfillmentUtils;
use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

class YoRo_Fulfillment_Service {

	public static function creation_available() {
		if ( ! self::available() || ! method_exists( FulfillmentUtils::class, 'get_pending_items' ) ) { return false; }
		foreach ( [ 'set_entity_type', 'set_entity_id', 'set_status', 'set_items', 'save' ] as $method ) {
			if ( ! method_exists( Fulfillment::class, $method ) ) { return false; }
		}
		return true;
	}

	private static function allocation_items( $items, $allow_empty = false ) {
		if ( ! is_array( $items ) || ! array_is_list( $items ) || count( $items ) > 100 || ( ! $allow_empty && [] === $items ) ) {
			throw new RuntimeException( 'Unbounded allocation.' );
		}
		$quantities = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || count( $item ) !== 2 || array_diff( array_keys( $item ), [ 'item_id', 'qty' ] )
				|| ! is_int( $item['item_id'] ?? null ) || $item['item_id'] < 1
				|| ! is_int( $item['qty'] ?? null ) || $item['qty'] < 1 || isset( $quantities[ $item['item_id'] ] ) ) {
				throw new RuntimeException( 'Invalid allocation item.' );
			}
			$quantities[ $item['item_id'] ] = $item['qty'];
		}
		ksort( $quantities, SORT_NUMERIC );
		return $quantities;
	}

	private static function allocation_list( array $quantities ) {
		$items = [];
		foreach ( $quantities as $id => $qty ) { $items[] = [ 'item_id' => $id, 'qty' => $qty ]; }
		return $items;
	}

	private static function allocation_snapshot( $order_id ) {
		if ( ! self::creation_available() ) { throw new RuntimeException( 'Native creation unavailable.' ); }
		$snapshot = self::owner_snapshot( $order_id );
		$order_items = $snapshot['order']->get_items();
		if ( count( $order_items ) > 100 ) { throw new RuntimeException( 'Unbounded order items.' ); }
		$order_state = [];
		foreach ( $order_items as $item ) {
			$id = $item->get_id();
			$quantity = $item->get_quantity();
			$refunded = $snapshot['order']->get_qty_refunded_for_item( $id );
			if ( ! is_int( $id ) || $id < 1 || isset( $order_state[ $id ] )
				|| ! is_numeric( $quantity ) || (int) $quantity != $quantity || $quantity < 0
				|| ! is_numeric( $refunded ) || (int) $refunded != $refunded || $refunded > 0 || $quantity + $refunded < 0 ) {
				throw new RuntimeException( 'Ambiguous order quantity.' );
			}
			$order_state[ $id ] = [ 'qty' => (int) $quantity, 'refunded' => (int) $refunded ];
		}
		ksort( $order_state, SORT_NUMERIC );
		$members = [];
		$allocated = [];
		foreach ( $snapshot['fulfillments'] as $fulfillment ) {
			$state = self::state( $fulfillment );
			if ( [] === $state['items'] ) { throw new RuntimeException( 'Empty existing allocation.' ); }
			foreach ( $state['items'] as $id => $quantity ) {
				if ( ! isset( $order_state[ $id ] ) ) { throw new RuntimeException( 'Foreign allocation item.' ); }
				$allocated[ $id ] = ( $allocated[ $id ] ?? 0 ) + $quantity;
			}
			$members[ $fulfillment->get_id() ] = [ 'state' => $state, 'tracking' => self::tracking( $fulfillment ) ];
		}
		ksort( $members, SORT_NUMERIC );
		$pending = [];
		foreach ( FulfillmentUtils::get_pending_items( $snapshot['order'], $snapshot['fulfillments'] ) as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['item_id'] ?? null ) || ! is_numeric( $item['qty'] ?? null )
				|| (int) $item['qty'] != $item['qty'] || $item['qty'] < 1 ) { throw new RuntimeException( 'Invalid native pending item.' ); }
			$pending[] = [ 'item_id' => $item['item_id'], 'qty' => (int) $item['qty'] ];
		}
		$pending = self::allocation_items( $pending, true );
		$expected = [];
		foreach ( $order_state as $id => $item ) {
			$remaining = $item['qty'] + $item['refunded'] - ( $allocated[ $id ] ?? 0 );
			if ( $remaining < 0 ) { throw new RuntimeException( 'Overallocated order.' ); }
			if ( $remaining > 0 ) { $expected[ $id ] = $remaining; }
		}
		if ( $pending !== $expected ) { throw new RuntimeException( 'Incoherent native pending quantities.' ); }
		$snapshot['allocation_members'] = $members;
		$snapshot['order_items'] = $order_state;
		$snapshot['pending'] = $pending;
		$snapshot['allocation_version'] = hash( 'sha256', wp_json_encode( [ 'order_id' => $order_id,
			'order_status' => $snapshot['order_status'], 'items' => $order_state, 'pending' => $pending,
			'fulfillments' => $members, 'aggregate' => $snapshot['aggregate'] ], JSON_THROW_ON_ERROR ) );
		return $snapshot;
	}

	public static function prepare_create( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Fulfillment creation requires manage_woocommerce.' ); }
		try {
			if ( ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'items', 'expected_version', '_yoro_confirmed', '_yoro_preview' ] )
				|| ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1
				|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] ) ) {
				throw new RuntimeException( 'Invalid creation parameters.' );
			}
			$items = self::allocation_items( $params['items'] ?? null );
			$snapshot = self::allocation_snapshot( $params['order_id'] );
			if ( count( $snapshot['allocation_members'] ) >= 100 ) { throw new RuntimeException( 'Creation exceeds bounded owner set.' ); }
			if ( ! hash_equals( $snapshot['allocation_version'], $params['expected_version'] ) ) {
				return new WP_Error( 'fulfillment_version_conflict', 'Read current pending items and confirm again.' );
			}
			foreach ( $items as $id => $qty ) {
				if ( ! isset( $snapshot['pending'][ $id ] ) || $qty > $snapshot['pending'][ $id ] ) { throw new RuntimeException( 'Allocation exceeds native pending quantity.' ); }
			}
			return [ 'snapshot' => $snapshot, 'items' => $items ];
		} catch ( Throwable $exception ) {
			return new WP_Error( 'fulfillment_creation_unavailable', 'Exact native pending allocation could not be verified.' );
		}
	}

	public static function preview_create( $params ) {
		$prepared = self::prepare_create( $params );
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => is_wp_error( $prepared ),
			'changes_store' => ! is_wp_error( $prepared ),
			'block_reason' => is_wp_error( $prepared ) ? $prepared->get_error_code() : '',
			'message' => is_wp_error( $prepared ) ? $prepared->get_error_message() : 'Create one unfulfilled allocation with exactly the selected items after confirmation.' ];
	}

	public static function create( $params ) {
		$prepared = self::prepare_create( $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		$entered_save = false;
		try {
			$target = new Fulfillment();
			$target->set_entity_type( WC_Order::class );
			$target->set_entity_id( (string) $params['order_id'] );
			$target->set_status( 'unfulfilled' );
			$target->set_items( self::allocation_list( $prepared['items'] ) );
			$state = self::state( $target );
			if ( $target->get_id() !== 0 || $state['entity_type'] !== WC_Order::class || $state['order_id'] !== (string) $params['order_id']
				|| $state['items'] !== $prepared['items'] || $state['status'] !== 'unfulfilled' || $state['is_fulfilled']
				|| $state['locked'] || $state['date_deleted'] || $state['date_fulfilled']
				|| self::tracking( $target ) !== [ 'tracking_number' => '', 'shipment_provider' => '', 'tracking_url' => '' ] ) {
				throw new RuntimeException( 'Native constructor cannot create an empty unfulfilled target.' );
			}
			$before = self::allocation_snapshot( $params['order_id'] );
			if ( ! hash_equals( $before['allocation_version'], $params['expected_version'] ) ) {
				return new WP_Error( 'fulfillment_version_conflict', 'Allocation changed before save; read and confirm again.' );
			}
			$entered_save = true;
			$id = $target->save();
			if ( ! is_int( $id ) || $id < 1 || isset( $before['allocation_members'][ $id ] ) ) { throw new RuntimeException( 'No unique created identity.' ); }
			$current = new Fulfillment( $id );
			$after = self::allocation_snapshot( $params['order_id'] );
			$state['fulfillment_id'] = $id;
			if ( self::state( $current ) !== $state || self::tracking( $current ) !== [ 'tracking_number' => '', 'shipment_provider' => '', 'tracking_url' => '' ]
				|| ! isset( $after['allocation_members'][ $id ] ) || $after['allocation_members'][ $id ] !== [ 'state' => $state, 'tracking' => self::tracking( $current ) ] ) {
				throw new RuntimeException( 'Created fulfillment differs from authorized allocation.' );
			}
			$members = $after['allocation_members'];
			unset( $members[ $id ] );
			$pending = $before['pending'];
			foreach ( $prepared['items'] as $item_id => $qty ) {
				$pending[ $item_id ] -= $qty;
				if ( 0 === $pending[ $item_id ] ) { unset( $pending[ $item_id ] ); }
			}
			if ( $members !== $before['allocation_members'] || $pending !== $after['pending']
				|| $before['order_items'] !== $after['order_items'] || $before['order_status'] !== $after['order_status'] ) {
				throw new RuntimeException( 'Allocation postcondition failed.' );
			}
			return [ 'success' => true, 'action' => 'fulfillment.create', 'order_id' => $params['order_id'],
				'fulfillment_id' => $id, 'items' => self::allocation_list( $prepared['items'] ), 'status' => 'unfulfilled', 'is_fulfilled' => false,
				'allocation_version' => $after['allocation_version'], 'read_only' => false, 'undoable' => false, 'undo_plan' => [],
				'message' => 'Native unfulfilled allocation created.', 'summary' => 'Native unfulfilled allocation created.',
				'result_type' => 'fulfillment_creation', 'display_type' => 'fulfillment_creation' ];
		} catch ( Throwable $exception ) {
			return new WP_Error( $entered_save ? 'fulfillment_creation_reconciliation_required' : 'fulfillment_creation_unavailable',
				$entered_save ? 'Creation outcome is ambiguous. Read and reconcile; do not retry or delete automatically.' : 'Native creation preconditions could not be verified.' );
		}
	}

	public static function available() {
		try {
			return class_exists( Fulfillment::class ) && class_exists( FulfillmentUtils::class )
			&& class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( 'fulfillments' )
			&& method_exists( Fulfillment::class, 'set_tracking_number' )
			&& method_exists( Fulfillment::class, 'set_shipment_provider' )
				&& method_exists( Fulfillment::class, 'set_tracking_url' );
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	private static function text( $value, $max, $empty = false ) {
		return is_string( $value ) && preg_match( '//u', $value ) && trim( $value ) === $value
			&& ( $empty || '' !== $value ) && mb_strlen( $value, 'UTF-8' ) <= $max
			&& ! preg_match( '/[\x00-\x1f\x7f<>]/u', $value );
	}

	private static function tracking( Fulfillment $fulfillment ) {
		$tuple = [
			'tracking_number' => $fulfillment->get_tracking_number() ?? '',
			'shipment_provider' => $fulfillment->get_shipment_provider() ?? '',
			'tracking_url' => $fulfillment->get_tracking_url() ?? '',
		];
		if ( ! self::text( $tuple['tracking_number'], 200, true ) || ! self::text( $tuple['shipment_provider'], 100, true )
			|| ! self::text( $tuple['tracking_url'], 2048, true ) ) {
			throw new RuntimeException( 'Unbounded native tracking.' );
		}
		return $tuple;
	}

	private static function state( Fulfillment $fulfillment ) {
		$items = $fulfillment->get_items();
		if ( count( $items ) > 100 ) {
			throw new RuntimeException( 'Unbounded native fulfillment items.' );
		}
		$bounded = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! is_int( $item['item_id'] ?? null ) || $item['item_id'] < 1
				|| ! is_int( $item['qty'] ?? null ) || $item['qty'] < 1 || isset( $bounded[ $item['item_id'] ] ) ) {
				throw new RuntimeException( 'Invalid native fulfillment items.' );
			}
			$bounded[ $item['item_id'] ] = $item['qty'];
		}
		ksort( $bounded, SORT_NUMERIC );
		if ( ! self::text( $fulfillment->get_status(), 50 ) ) {
			throw new RuntimeException( 'Invalid native fulfillment status.' );
		}
		return [
			'fulfillment_id' => $fulfillment->get_id(), 'entity_type' => $fulfillment->get_entity_type(),
			'order_id' => $fulfillment->get_entity_id(), 'items' => $bounded,
			'status' => $fulfillment->get_status(), 'is_fulfilled' => $fulfillment->get_is_fulfilled(),
			'locked' => $fulfillment->is_locked(), 'date_deleted' => $fulfillment->get_date_deleted(),
			'date_fulfilled' => $fulfillment->get_date_fulfilled(),
		];
	}

	private static function version( Fulfillment $fulfillment ) {
		return hash( 'sha256', wp_json_encode( [ 'state' => self::state( $fulfillment ), 'tracking' => self::tracking( $fulfillment ) ], JSON_THROW_ON_ERROR ) );
	}

	private static function owner_snapshot( $order_id ) {
		if ( ! self::available() ) {
			throw new RuntimeException( 'Native fulfillments unavailable.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || $order->get_id() !== $order_id ) {
			throw new RuntimeException( 'Exact order unavailable.' );
		}
		$order->get_data_store()->read( $order );
		$order->read_meta_data( true );
		$store = WC_Data_Store::load( 'order-fulfillment' );
		$fulfillments = $store->read_fulfillments( WC_Order::class, (string) $order_id );
		if ( ! is_array( $fulfillments ) || count( $fulfillments ) > 100 ) {
			throw new RuntimeException( 'Unbounded native fulfillment set.' );
		}
		$seen = [];
		foreach ( $fulfillments as $fulfillment ) {
			if ( ! $fulfillment instanceof Fulfillment || $fulfillment->get_id() < 1
				|| isset( $seen[ $fulfillment->get_id() ] ) || WC_Order::class !== $fulfillment->get_entity_type()
				|| (string) $order_id !== $fulfillment->get_entity_id() || $fulfillment->get_date_deleted() ) {
				throw new RuntimeException( 'Invalid native fulfillment ownership.' );
			}
			$seen[ $fulfillment->get_id() ] = true;
		}
		$aggregate = FulfillmentUtils::calculate_order_fulfillment_status( $order, $fulfillments );
		$stored = $order->meta_exists( '_fulfillment_status' ) ? $order->get_meta( '_fulfillment_status', true ) : 'no_fulfillments';
		if ( ! is_string( $aggregate ) || $aggregate !== $stored ) {
			throw new RuntimeException( 'Native fulfillment aggregate is incoherent.' );
		}
		return [ 'order' => $order, 'fulfillments' => $fulfillments, 'aggregate' => $aggregate, 'order_status' => $order->get_status( 'edit' ) ];
	}

	public static function observations( $order_id ) {
		try {
			$snapshot = self::owner_snapshot( $order_id );
			$entries = [];
			foreach ( $snapshot['fulfillments'] as $fulfillment ) {
				$state = self::state( $fulfillment );
				$entries[] = array_merge( [
					'source' => 'woo_native_fulfillment', 'order_id' => $order_id,
					'fulfillment_id' => $fulfillment->get_id(), 'status' => $state['status'],
					'is_fulfilled' => $state['is_fulfilled'], 'locked' => $state['locked'],
					'current_version' => self::version( $fulfillment ),
				], self::tracking( $fulfillment ) );
			}
			$result = [ 'native_status' => 'available', 'native_fulfillments' => $entries ];
			try {
				$allocation = self::allocation_snapshot( $order_id );
				$result['pending_items'] = self::allocation_list( $allocation['pending'] );
				$result['allocation_version'] = $allocation['allocation_version'];
			} catch ( Throwable $exception ) {
				// Existing tracking observations remain usable when creation cannot be grounded.
			}
			return $result;
		} catch ( Throwable $exception ) {
			return [ 'native_status' => 'unavailable', 'native_fulfillments' => [] ];
		}
	}

	public static function prepare( $action, $params ) {
		if ( 'fulfillment.create' === $action ) { return self::prepare_create( $params ); }
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error( 'yoro_permission_denied', 'Tracking changes require manage_woocommerce.' );
		}
		if ( ! in_array( $action, [ 'fulfillment.add_tracking', 'fulfillment.update_tracking' ], true )
			|| ! is_array( $params ) || array_diff( array_keys( $params ), [ 'order_id', 'fulfillment_id', 'tracking_number', 'shipment_provider', 'expected_version', '_yoro_confirmed', '_yoro_preview' ] )
			|| ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1
			|| ! is_int( $params['fulfillment_id'] ?? null ) || $params['fulfillment_id'] < 1
			|| ! self::text( $params['tracking_number'] ?? null, 200 )
			|| ! self::text( $params['shipment_provider'] ?? null, 100 )
			|| ! preg_match( '/^[a-zA-Z0-9_-]+$/D', $params['shipment_provider'] )
			|| ! is_string( $params['expected_version'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $params['expected_version'] ) ) {
			return new WP_Error( 'invalid_fulfillment_tracking', 'Use exact order and fulfillment IDs, plain tracking text, current provider key and grounded version.' );
		}
		try {
			$snapshot = self::owner_snapshot( $params['order_id'] );
			$target = new Fulfillment( $params['fulfillment_id'] );
			$state = self::state( $target );
			$members = array_map( static function ( $item ) { return $item->get_id(); }, $snapshot['fulfillments'] );
			if ( $target->get_id() !== $params['fulfillment_id'] || WC_Order::class !== $state['entity_type']
				|| ! in_array( $params['fulfillment_id'], $members, true )
				|| (string) $params['order_id'] !== $state['order_id'] || $state['locked'] || $state['date_deleted']
				|| ( $state['is_fulfilled'] && ! $state['date_fulfilled'] ) ) {
				throw new RuntimeException( 'Native target not writable.' );
			}
			if ( ! hash_equals( self::version( $target ), $params['expected_version'] ) ) {
				return new WP_Error( 'fulfillment_version_conflict', 'Read the current native fulfillment again before confirming.' );
			}
			$previous = self::tracking( $target );
			if ( ( 'fulfillment.add_tracking' === $action ) !== ( '' === $previous['tracking_number'] ) ) {
				return new WP_Error( 'fulfillment_tracking_precondition', 'Add requires no tracking; update requires existing tracking.' );
			}
			$providers = FulfillmentUtils::get_shipping_providers();
			$provider = $providers[ $params['shipment_provider'] ] ?? null;
			if ( ! is_object( $provider ) || ! is_callable( [ $provider, 'get_key' ] )
				|| $provider->get_key() !== $params['shipment_provider'] || ! is_callable( [ $provider, 'get_tracking_url' ] ) ) {
				throw new RuntimeException( 'Native shipping provider unavailable.' );
			}
			$url = $provider->get_tracking_url( $params['tracking_number'] );
			if ( ! self::text( $url, 2048, true ) || ( '' !== $url && ! in_array( wp_parse_url( $url, PHP_URL_SCHEME ), [ 'https', 'http' ], true ) ) ) {
				throw new RuntimeException( 'Unsafe provider URL.' );
			}
			return [ 'target' => $target, 'state' => $state, 'snapshot' => $snapshot, 'previous' => $previous,
				'tracking' => [ 'tracking_number' => $params['tracking_number'], 'shipment_provider' => $params['shipment_provider'], 'tracking_url' => $url ] ];
		} catch ( Throwable $exception ) {
			return new WP_Error( 'fulfillment_tracking_unavailable', 'The native target, aggregate state or provider could not be verified.' );
		}
	}

	public static function add_tracking( $params ) { return self::write( 'fulfillment.add_tracking', $params ); }
	public static function update_tracking( $params ) { return self::write( 'fulfillment.update_tracking', $params ); }
	public static function preview_add_tracking( $params ) { return self::preview( 'fulfillment.add_tracking', $params ); }
	public static function preview_update_tracking( $params ) { return self::preview( 'fulfillment.update_tracking', $params ); }

	private static function preview( $action, $params ) {
		$prepared = self::prepare( $action, $params );
		return [ 'type' => 'write', 'will_execute' => false, 'blocked' => is_wp_error( $prepared ),
			'changes_store' => ! is_wp_error( $prepared ),
			'block_reason' => is_wp_error( $prepared ) ? $prepared->get_error_code() : '',
			'message' => is_wp_error( $prepared ) ? $prepared->get_error_message() : 'Change only native tracking after claimed confirmation; native internal audit and unchanged aggregate projection are preserved.' ];
	}

	private static function write( $action, $params ) {
		$prepared = self::prepare( $action, $params );
		if ( is_wp_error( $prepared ) ) { return $prepared; }
		try {
			$latest = self::owner_snapshot( $params['order_id'] );
			$target = new Fulfillment( $params['fulfillment_id'] );
			$latest_members = array_map( static function ( $item ) { return $item->get_id(); }, $latest['fulfillments'] );
			if ( ! in_array( $params['fulfillment_id'], $latest_members, true )
				|| ! hash_equals( self::version( $target ), $params['expected_version'] )
				|| $latest['order_status'] !== $prepared['snapshot']['order_status']
				|| $latest['aggregate'] !== $prepared['snapshot']['aggregate'] ) {
				return new WP_Error( 'fulfillment_version_conflict', 'Native state changed before save; read and confirm again.' );
			}
			$target->set_tracking_number( $prepared['tracking']['tracking_number'] );
			$target->set_shipment_provider( $prepared['tracking']['shipment_provider'] );
			$target->set_tracking_url( $prepared['tracking']['tracking_url'] );
			$saved = $target->save();
			$current = new Fulfillment( $params['fulfillment_id'] );
			$after = self::owner_snapshot( $params['order_id'] );
			$after_members = array_map( static function ( $item ) { return $item->get_id(); }, $after['fulfillments'] );
			if ( ! in_array( $params['fulfillment_id'], $after_members, true )
				|| $saved !== $params['fulfillment_id'] || self::state( $current ) !== $prepared['state']
				|| self::tracking( $current ) !== $prepared['tracking'] || $after['order_status'] !== $prepared['snapshot']['order_status']
				|| $after['aggregate'] !== $prepared['snapshot']['aggregate'] ) {
				throw new RuntimeException( 'Native tracking postcondition failed.' );
			}
			$result = [ 'success' => true, 'action' => $action, 'order_id' => $params['order_id'],
				'fulfillment_id' => $params['fulfillment_id'], 'current_version' => self::version( $current ),
				'message' => 'Native fulfillment tracking saved.', 'summary' => 'Native fulfillment tracking saved.',
				'read_only' => false, 'undoable' => false, 'undo_plan' => [], 'result_type' => 'fulfillment_tracking', 'display_type' => 'fulfillment_tracking' ];
			foreach ( $prepared['previous'] as $key => $value ) { $result[ 'previous_' . $key ] = $value; }
			return array_merge( $result, $prepared['tracking'] );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'fulfillment_tracking_reconciliation_required', 'The outcome is ambiguous. Read and reconcile; do not retry or roll back this operation.' );
		}
	}
}
