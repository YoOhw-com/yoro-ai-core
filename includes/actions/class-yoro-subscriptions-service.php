<?php
/** Bounded public-owner reads; lifecycle and schedule authority remains with Subscriptions. */
defined( 'ABSPATH' ) || exit;

class YoRo_Subscriptions_Service {
	private static function coverage( $available ) {
		return [ 'order_relations' => $available ? 'available' : 'not_observed', 'schedule_cadence' => $available ? 'available' : 'not_observed', 'omitted_fields' => true ];
	}

	private static function owner() {
		$loaded = class_exists( 'WC_Subscriptions', false );
		$available = false;
		try {
			if ( $loaded && class_exists( 'WC_Subscription', false ) ) {
				$property = new ReflectionProperty( 'WC_Subscriptions', 'version' );
				$available = $property->isPublic() && $property->isStatic() && $property->isInitialized() && '9.2.0' === $property->getValue();
				foreach ( [ 'wcs_get_subscription' => 1, 'wcs_get_subscriptions_for_order' => 2 ] as $function => $arguments ) {
					if ( ! function_exists( $function ) ) { $available = false; break; }
					$reflection = new ReflectionFunction( $function );
					$available = $available && $reflection->getNumberOfParameters() >= $arguments && $reflection->getNumberOfRequiredParameters() <= $arguments;
				}
				foreach ( [ 'get_id' => 0, 'get_type' => 0, 'get_status' => 0, 'get_date' => 2, 'get_billing_period' => 0, 'get_billing_interval' => 0, 'is_manual' => 0 ] as $method => $arguments ) {
					$reflection = new ReflectionMethod( 'WC_Subscription', $method );
					$available = $available && $reflection->isPublic() && ! $reflection->isStatic() && $reflection->getNumberOfRequiredParameters() <= $arguments && $reflection->getNumberOfParameters() >= $arguments;
				}
			}
		} catch ( Throwable $error ) { $available = false; }
		$status = $available ? 'available' : ( $loaded ? 'incompatible' : 'absent' );
		return [ 'status' => $status, 'owner_kind' => $available ? 'subscriptions' : $status, 'version' => $available ? '9.2.0' : '', 'coverage' => self::coverage( $available ) ];
	}

	private static function input( $params, $target ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) ) { return new WP_Error( 'subscriptions_invalid_input', 'Invalid read parameters.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		if ( array_diff( array_keys( $params ), $target ? [ 'order_id' ] : [] ) || ( $target && ( ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) ) ) {
			return new WP_Error( 'subscriptions_invalid_input', 'Only the exact read parameters are accepted.' );
		}
		return $params;
	}

	private static function response( $action, array $data ) {
		$type = 'subscriptions.get_status' === $action ? 'subscriptions_status' : 'subscriptions_order_context';
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [], 'display_type' => $type, 'result_type' => $type, 'message' => 'Loaded bounded subscription observations.', 'summary' => 'Loaded bounded subscription observations.' ], $data );
	}

	public static function get_status( $params ) {
		$input = self::input( $params, false );
		return is_wp_error( $input ) ? $input : self::response( 'subscriptions.get_status', self::owner() );
	}

	private static function date( $value ) {
		if ( '' === $value || '0' === $value || 0 === $value ) { return ''; }
		if ( ! is_string( $value ) || 19 !== strlen( $value ) ) { throw new RuntimeException( 'Invalid schedule.' ); }
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		$errors = DateTimeImmutable::getLastErrors();
		if ( ! $date || ( false !== $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i:s' ) !== $value || (int) $date->format( 'Y' ) < 1970 ) { throw new RuntimeException( 'Invalid schedule.' ); }
		return $date->format( 'c' );
	}

	private static function subscription( $subscription, $id, $blog ) {
		if ( get_current_blog_id() !== $blog || ! $subscription instanceof WC_Subscription || $subscription->get_id() !== $id || 'shop_subscription' !== $subscription->get_type() ) { throw new RuntimeException( 'Invalid subscription identity.' ); }
		$status = $subscription->get_status();
		$period = $subscription->get_billing_period();
		$interval = $subscription->get_billing_interval();
		if ( is_string( $interval ) && preg_match( '/^[1-9][0-9]{0,2}$/D', $interval ) ) { $interval = (int) $interval; }
		$manual = $subscription->is_manual();
		if ( ! in_array( $status, [ 'pending', 'active', 'on-hold', 'pending-cancel', 'cancelled', 'switched', 'expired' ], true ) || ! in_array( $period, [ 'day', 'week', 'month', 'year' ], true ) || ! is_int( $interval ) || $interval < 1 || $interval > 365 || ! is_bool( $manual ) ) { throw new RuntimeException( 'Invalid subscription facts.' ); }
		$schedule = [];
		foreach ( [ 'start', 'trial_end', 'next_payment', 'end' ] as $key ) { $schedule[ $key ] = self::date( $subscription->get_date( $key, 'gmt' ) ); }
		if ( '' === $schedule['start'] ) { throw new RuntimeException( 'Missing start.' ); }
		foreach ( [ 'trial_end', 'next_payment', 'end' ] as $key ) {
			if ( '' !== $schedule[ $key ] && $schedule[ $key ] < $schedule['start'] ) { throw new RuntimeException( 'Invalid schedule order.' ); }
		}
		if ( get_current_blog_id() !== $blog || $subscription->get_id() !== $id ) { throw new RuntimeException( 'Context changed.' ); }
		return [ 'subscription_id' => $id, 'status' => $status, 'billing_period' => $period, 'billing_interval' => $interval, 'renewal_mode' => $manual ? 'manual' : 'automatic', 'schedule' => $schedule ];
	}

	public static function get_order_context( $params ) {
		$input = self::input( $params, true );
		if ( is_wp_error( $input ) ) { return $input; }
		$blog = get_current_blog_id();
		$id = $input['order_id'];
		$order = wc_get_order( $id );
		if ( get_current_blog_id() !== $blog || ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $id || 'shop_order' !== $order->get_type() ) { return new WP_Error( 'subscriptions_invalid_order', 'An exact current-site order is required.' ); }
		$owner = self::owner();
		$data = array_merge( $owner, [ 'order_id' => $id, 'order_status' => '', 'status' => 'unavailable', 'subscriptions' => [] ] );
		if ( 'available' !== $owner['status'] ) { return self::response( 'subscriptions.get_order_context', $data ); }
		try {
			$order_status = $order->get_status();
			if ( ! is_string( $order_status ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $order_status ) ) { throw new RuntimeException( 'Invalid order status.' ); }
			$related = wcs_get_subscriptions_for_order( $id, [ 'order_type' => 'any' ] );
			if ( get_current_blog_id() !== $blog || ! is_array( $related ) || count( $related ) > 20 ) { throw new RuntimeException( 'Invalid relations.' ); }
			$items = [];
			foreach ( $related as $key => $subscription ) {
				$subscription_id = $subscription instanceof WC_Subscription ? $subscription->get_id() : null;
				if ( ! is_int( $subscription_id ) || $subscription_id < 1 || $key !== $subscription_id || isset( $items[ $subscription_id ] ) ) { throw new RuntimeException( 'Invalid relation identity.' ); }
				$reloaded = wcs_get_subscription( $subscription_id );
				$items[ $subscription_id ] = self::subscription( $reloaded, $subscription_id, $blog );
			}
			if ( get_current_blog_id() !== $blog || self::owner() !== $owner ) { throw new RuntimeException( 'Owner context changed.' ); }
			ksort( $items, SORT_NUMERIC );
			$data['subscriptions'] = array_values( $items );
			$data['order_status'] = $order_status;
			$data['status'] = 'observed';
		} catch ( Throwable $error ) {
			$data['subscriptions'] = [];
			$data['order_status'] = '';
			$data['coverage'] = self::coverage( false );
		}
		return self::response( 'subscriptions.get_order_context', $data );
	}
}
