<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only adapter for the exact characterized alternative plugin owners. */
final class YoRo_Cancellation_Return_Service {
	private const FREE = '1.1.14';
	private const PREMIUM = '1.4.0';

	private static function coverage( $kind ) {
		return [
			'free_eligibility' => 'free' === $kind ? 'available' : ( 'premium' === $kind ? 'not_observed' : 'unavailable' ),
			'retained_case_state' => in_array( $kind, [ 'free', 'premium' ], true ) ? 'available' : 'not_observed',
			'premium_eligibility' => 'not_observed', 'omitted_fields' => true,
		];
	}

	private static function identify_owner() {
		$free = class_exists( 'WC_Order_Cancellation_Return', false );
		$premium = class_exists( 'YOWC_Advanced_Order_Manager', false );
		$kind = $free === $premium ? ( $free ? 'incompatible' : 'absent' ) : ( $free ? 'free' : 'premium' );
		$version = '';
		if ( $free !== $premium ) {
			$key = $free ? 'WC_ORDER_CANCELLATION_RETURN_VERSION' : 'YOWC_ADVANCED_ORDER_MANAGER';
			$raw = defined( $key ) ? constant( $key ) : null;
			$version = is_string( $raw ) && strlen( $raw ) <= 32 && preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}$/D', $raw ) ? $raw : '';
			if ( $version !== ( $free ? self::FREE : self::PREMIUM ) ) { $kind = 'incompatible'; }
		}
		if ( 'free' === $kind ) {
			if ( ! class_exists( 'WC_Order_Cancellation_Return_Request_Eligibility', false )
				|| ! is_callable( [ 'WC_Order_Cancellation_Return_Request_Eligibility', 'can_request_cancellation' ] )
				|| ! is_callable( [ 'WC_Order_Cancellation_Return_Request_Eligibility', 'can_request_return' ] ) ) { $kind = 'incompatible'; }
		}
		$available = in_array( $kind, [ 'free', 'premium' ], true );
		return [ 'status' => $available ? 'available' : $kind, 'owner_kind' => $kind, 'version' => $version,
			'available_reads' => $available ? [ 'cancellation_return.get_status', 'cancellation_return.get_order_case' ] : [ 'cancellation_return.get_status' ],
			'coverage' => self::coverage( $kind ) ];
	}

	private static function validate_input( $params, $order_read ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) ) { return new WP_Error( 'cancellation_return_invalid_input', 'Invalid read parameters.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		if ( array_diff( array_keys( $params ), $order_read ? [ 'order_id' ] : [] )
			|| ( $order_read && ( ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) ) ) {
			return new WP_Error( 'cancellation_return_invalid_input', 'Only the exact read parameters are accepted.' );
		}
		return $params;
	}

	private static function response( $action, array $data ) {
		$type = 'cancellation_return.get_status' === $action ? 'cancellation_return_status' : 'cancellation_return_order_case';
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [],
			'display_type' => $type, 'result_type' => $type,
			'message' => 'Loaded bounded Cancellation & Return observations.', 'summary' => 'Loaded bounded Cancellation & Return observations.' ], $data );
	}

	public static function get_status( $params ) {
		$input = self::validate_input( $params, false );
		return is_wp_error( $input ) ? $input : self::response( 'cancellation_return.get_status', self::identify_owner() );
	}

	private static function unobserved_case() {
		return [ 'state' => 'not_observed', 'requested_at' => '', 'approved_at' => '', 'rejected_at' => '', 'completed_at' => '',
			'request_reason_present' => false, 'rejection_reason_present' => false, 'approval_message_present' => false,
			'completion_reason_present' => false, 'resolution_present' => false, 'item_scope_present' => false,
			'eligibility' => [ 'status' => 'not_observed', 'eligible' => false, 'code' => 'not_observed' ] ];
	}

	/** A finite scalar read: duplicate, compound and over-cap evidence is unverifiable. */
	private static function retained_text( WC_Order $order, $key, $limit = 8192 ) {
		$values = $order->get_meta( $key, false, 'edit' );
		if ( ! is_array( $values ) || count( $values ) > 1 ) { throw new RuntimeException( 'Ambiguous retained evidence.' ); }
		$entry = $values ? reset( $values ) : null;
		if ( null !== $entry && ! $entry instanceof WC_Meta_Data ) { throw new RuntimeException( 'Invalid retained entry.' ); }
		$value = $entry ? $entry->value : '';
		if ( ! is_string( $value ) || strlen( $value ) > $limit ) { throw new RuntimeException( 'Invalid retained evidence.' ); }
		return $value;
	}

	/** Owner dates are presentation strings: normalize only round-trippable representations. */
	private static function retained_date( WC_Order $order, $key ) {
		$value = self::retained_text( $order, $key, 80 );
		if ( '' === $value ) { return ''; }
		foreach ( [ 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d g:i a', 'F j, Y g:i a', 'F j, Y H:i', 'Y-m-d\TH:i:sP' ] as $format ) {
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $value, wp_timezone() );
			$error = DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $error || ( 0 === $error['warning_count'] && 0 === $error['error_count'] ) )
				&& $date->format( $format ) === $value && (int) $date->format( 'Y' ) >= 1970 ) { return $date->format( 'c' ); }
		}
		throw new RuntimeException( 'Unsupported retained date.' );
	}

	private static function free_eligibility( WC_Order $order, $kind ) {
		$method = 'can_request_' . $kind;
		$result = WC_Order_Cancellation_Return_Request_Eligibility::$method( $order );
		if ( true === $result ) { return [ 'status' => 'eligible', 'eligible' => true, 'code' => 'eligible' ]; }
		$allowed = [ 'wcocr_invalid_order' ];
		foreach ( [ 'disabled', 'already_requested', 'rejected', 'status_not_allowed', 'time_expired' ] as $suffix ) { $allowed[] = 'wcocr_' . $kind . '_' . $suffix; }
		if ( is_wp_error( $result ) && count( $result->get_error_codes() ) === 1 && in_array( $result->get_error_code(), $allowed, true ) ) {
			return [ 'status' => 'ineligible', 'eligible' => false, 'code' => $result->get_error_code() ];
		}
		throw new RuntimeException( 'Unverifiable owner eligibility.' );
	}

	private static function project_case( WC_Order $order, $kind, $owner ) {
		$case = self::unobserved_case();
		$case['request_reason_present'] = '' !== self::retained_text( $order, 'request_' . $kind . '_reason' );
		$case['rejection_reason_present'] = '' !== self::retained_text( $order, 'order_' . $kind . '_rejection_reason' );
		$case['approval_message_present'] = '' !== self::retained_text( $order, 'request_' . $kind . '_approved' );
		if ( 'premium' === $owner || 'cancellation' === $kind ) { $case['completion_reason_present'] = '' !== self::retained_text( $order, $kind . '_reason' ); }
		if ( 'premium' === $owner ) {
			$request_resolution = self::retained_text( $order, 'request_' . $kind . '_resolution' );
			$completion_resolution = self::retained_text( $order, $kind . '_resolution' );
			$case['resolution_present'] = '' !== $request_resolution || '' !== $completion_resolution;
			if ( 'return' === $kind ) {
				$request_scope = self::retained_text( $order, 'request_return_scope' );
				$completion_scope = self::retained_text( $order, 'return_scope' );
				if ( ! in_array( $request_scope, [ '', 'all', 'specific' ], true ) || ! in_array( $completion_scope, [ '', 'all', 'specific' ], true ) ) { throw new RuntimeException( 'Unverifiable item scope.' ); }
				$case['item_scope_present'] = '' !== $request_scope || '' !== $completion_scope;
			}
			$keys = [ 'requested_at' => 'request_' . $kind . '_date', 'approved_at' => 'request_' . $kind . '_approval_date',
				'rejected_at' => 'order_' . $kind . '_rejection_date', 'completed_at' => $kind . '_date' ];
			foreach ( $keys as $field => $key ) { $case[ $field ] = self::retained_date( $order, $key ); }
		}
		$pending = $order->get_status( 'edit' ) === ( 'cancellation' === $kind ? 'request-cancel' : 'request-return' );
		$count = (int) $case['rejection_reason_present'] + (int) $case['approval_message_present'] + (int) $case['completion_reason_present'];
		if ( $count > 1 || ( $pending && $count ) ) { throw new RuntimeException( 'Conflicting retained states.' ); }
		if ( $case['rejection_reason_present'] ) { $case['state'] = 'rejected'; }
		elseif ( $case['approval_message_present'] ) { $case['state'] = 'approved'; }
		elseif ( $case['completion_reason_present'] ) { $case['state'] = 'completed'; }
		elseif ( $pending && $case['request_reason_present'] ) { $case['state'] = 'pending'; }
		elseif ( ! $pending && ! $case['request_reason_present'] && ! $case['resolution_present'] && ! $case['item_scope_present']
			&& '' === $case['requested_at'] && '' === $case['approved_at'] && '' === $case['rejected_at'] && '' === $case['completed_at'] ) { $case['state'] = 'none'; }
		if ( 'free' === $owner ) { $case['eligibility'] = self::free_eligibility( $order, $kind ); }
		return $case;
	}

	private static function is_exact_order( $order, $id, $blog ) {
		return get_current_blog_id() === $blog && $order instanceof WC_Order && ! $order instanceof WC_Order_Refund
			&& $order->get_id() === $id && 'shop_order' === $order->get_type();
	}

	public static function get_order_case( $params ) {
		$input = self::validate_input( $params, true );
		if ( is_wp_error( $input ) ) { return $input; }
		$blog = get_current_blog_id();
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $input['order_id'] ) : false;
		if ( ! self::is_exact_order( $order, $input['order_id'], $blog ) ) { return new WP_Error( 'cancellation_return_order_unavailable', 'Exact local order is unavailable.' ); }
		$owner = self::identify_owner();
		$data = [ 'order_id' => $input['order_id'], 'status' => 'unavailable', 'owner_kind' => $owner['owner_kind'], 'version' => $owner['version'],
			'order_status' => '', 'cancellation' => self::unobserved_case(), 'return' => self::unobserved_case(), 'coverage' => $owner['coverage'] ];
		try {
			$status = $order->get_status( 'edit' );
			if ( ! is_string( $status ) || ! preg_match( '/^[a-z0-9_-]{1,40}$/D', $status ) ) { throw new RuntimeException( 'Invalid order status.' ); }
			$data['order_status'] = $status;
			if ( 'available' !== $owner['status'] ) { return self::response( 'cancellation_return.get_order_case', $data ); }
			$cancellation = self::project_case( $order, 'cancellation', $owner['owner_kind'] );
			if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site context changed.' ); }
			$return = self::project_case( $order, 'return', $owner['owner_kind'] );
			if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site context changed.' ); }
			$fresh = wc_get_order( $input['order_id'] );
			if ( ! self::is_exact_order( $fresh, $input['order_id'], $blog ) || $fresh->get_status( 'edit' ) !== $status ) { throw new RuntimeException( 'Order context changed.' ); }
			$data['cancellation'] = $cancellation; $data['return'] = $return;
			$data['status'] = in_array( 'not_observed', [ $cancellation['state'], $return['state'] ], true ) ? 'not_observed' : 'observed';
		} catch ( Throwable $exception ) {
			$data['order_status'] = '';
			$data['coverage']['retained_case_state'] = 'not_observed';
			$data['coverage']['free_eligibility'] = 'unavailable';
		}
		return self::response( 'cancellation_return.get_order_case', $data );
	}
}
