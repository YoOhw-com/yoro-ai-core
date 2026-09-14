<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only adapter for the characterized Blacklist Manager owner contracts. */
final class YoRo_Blacklist_Manager_Service {
	private static function version( $name ) {
		$value = defined( $name ) ? constant( $name ) : '';
		return is_string( $value ) && strlen( $value ) <= 32 && preg_match( '/^[0-9]+(?:\.[0-9]+){1,3}$/D', $value ) ? $value : '';
	}

	private static function coverage( $assessment = false ) {
		$coverage = [ 'source' => $assessment ? 'retained_owner_order_meta' : 'owner_schema_readiness',
			'premium_facts' => 'not_observed', 'remote_checks' => 'not_observed' ];
		if ( $assessment ) { $coverage['omitted_fields'] = true; }
		return $coverage;
	}

	private static function result( $action, array $data ) {
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [],
			'message' => 'Loaded bounded Blacklist Manager observations.', 'summary' => 'Loaded bounded Blacklist Manager observations.',
			'display_type' => 'blacklist.get_status' === $action ? 'blacklist_status' : 'blacklist_order_assessment',
			'result_type' => 'blacklist.get_status' === $action ? 'blacklist_status' : 'blacklist_order_assessment' ], $data );
	}

	private static function params( $params, array $keys ) {
		if ( ! is_array( $params ) ) { return false; }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		return array_diff( array_keys( $params ), $keys ) ? false : $params;
	}

	/** Never instantiate the owner or invoke refresh/repair/entitlement APIs. */
	private static function owner_status() {
		$free = class_exists( 'WC_Blacklist_Manager', false );
		$premium = class_exists( 'WC_Blacklist_Manager_Premium', false );
		$free_version = self::version( 'WC_BLACKLIST_MANAGER_VERSION' );
		$premium_version = $premium ? self::version( 'WC_BLACKLIST_MANAGER_PREMIUM_VERSION' ) : '';
		$data = [ 'status' => $free ? 'incompatible' : 'absent', 'free_version' => $free ? $free_version : '',
			'premium_state' => $premium ? ( '' !== $premium_version ? 'present' : 'incompatible' ) : 'not_observed',
			'premium_version' => $premium_version, 'schema_status' => 'not_observed', 'schema_ready' => false,
			'available_reads' => [ 'blacklist.get_status' ], 'coverage' => self::coverage() ];
		// Retained metadata has no independently versioned public read API: bind the characterized owner release.
		if ( ! $free || '2.3.1.1' !== $free_version || ! defined( 'WC_BLACKLIST_MANAGER_SCHEMA_CONTRACT_VERSION' )
			|| 1 !== WC_BLACKLIST_MANAGER_SCHEMA_CONTRACT_VERSION || ! class_exists( 'WC_Blacklist_Manager_Schema_Readiness', false )
			|| ! defined( 'WC_Blacklist_Manager_Schema_Readiness::CONTRACT_VERSION' ) || 1 !== WC_Blacklist_Manager_Schema_Readiness::CONTRACT_VERSION
			|| ! is_callable( [ 'WC_Blacklist_Manager_Schema_Readiness', 'get_status' ] ) ) { return $data; }
		try {
			$blog = get_current_blog_id();
			$status = WC_Blacklist_Manager_Schema_Readiness::get_status();
			if ( get_current_blog_id() !== $blog || ! is_array( $status ) || ! is_bool( $status['ready'] ?? null )
				|| ! in_array( $status['status'] ?? null, [ 'ready', 'pending', 'running', 'failed', 'manual_required', 'newer_than_code' ], true )
				|| $status['ready'] !== ( 'ready' === $status['status'] ) ) { throw new RuntimeException( 'Unverifiable owner readiness.' ); }
			$data['status'] = $status['ready'] ? 'available' : 'schema_not_ready';
			$data['schema_status'] = $status['status'];
			$data['schema_ready'] = $status['ready'];
			if ( $status['ready'] ) { $data['available_reads'][] = 'blacklist.get_order_assessment'; }
		} catch ( Throwable $exception ) {
			$data['status'] = 'incompatible';
			$data['schema_status'] = 'unavailable';
		}
		return $data;
	}

	public static function get_status( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( false === self::params( $params, [] ) ) { return new WP_Error( 'blacklist_invalid_input', 'Status accepts no input fields.' ); }
		return self::result( 'blacklist.get_status', self::owner_status() );
	}

	private static function empty_facts() {
		return [ 'decision' => 'not_observed', 'check_status' => 'not_observed', 'tier' => 'not_observed',
			'decided_at' => '', 'reference_present' => false, 'matches' => [] ];
	}

	private static function retained_enum( WC_Order $order, $key, array $allowed ) {
		$value = $order->get_meta( $key, true, 'edit' );
		if ( '' === $value ) { return 'not_observed'; }
		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) { throw new RuntimeException( 'Unverifiable retained value.' ); }
		return $value;
	}

	public static function get_order_assessment( $params ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		$params = self::params( $params, [ 'order_id' ] );
		if ( false === $params || ! is_int( $params['order_id'] ?? null ) || $params['order_id'] < 1 ) {
			return new WP_Error( 'blacklist_invalid_input', 'One exact positive order ID is required.' );
		}
		$blog = get_current_blog_id();
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $params['order_id'] ) : false;
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id']
			|| 'shop_order' !== $order->get_type() || get_current_blog_id() !== $blog ) { return new WP_Error( 'blacklist_order_unavailable', 'Exact local order is unavailable.' ); }
		$data = [ 'order_id' => $params['order_id'], 'status' => 'not_observed', 'facts' => self::empty_facts(), 'coverage' => self::coverage( true ) ];
		$owner = self::owner_status();
		if ( 'available' !== $owner['status'] || get_current_blog_id() !== $blog ) {
			$data['status'] = 'schema_not_ready' === $owner['status'] ? 'schema_not_ready' : 'unavailable';
			return self::result( 'blacklist.get_order_assessment', $data );
		}
		try {
			$order = wc_get_order( $params['order_id'] );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund || $order->get_id() !== $params['order_id']
				|| 'shop_order' !== $order->get_type() || get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Owner order changed.' ); }
			$facts = self::empty_facts();
			$facts['decision'] = self::retained_enum( $order, '_yogb_gbl_decision', [ 'allow', 'challenge', 'block', 'unknown', 'skipped_rate_limit', 'check_failed' ] );
			if ( 'not_observed' !== $facts['decision'] ) {
				$facts['check_status'] = self::retained_enum( $order, '_yogb_gbl_check_status', [ 'pending', 'success', 'failed', 'skipped_rate_limit' ] );
				$facts['tier'] = self::retained_enum( $order, '_yogb_gbl_tier', [ 'free', 'basic', 'pro', 'enterprise' ] );
				$at = $order->get_meta( '_yogb_gbl_decision_at', true, 'edit' );
				if ( '' !== $at ) {
					if ( ( ! is_int( $at ) && ( ! is_string( $at ) || ! preg_match( '/^[1-9][0-9]{0,11}$/D', $at ) ) ) || $at < 1 || $at > 253402300799 ) { throw new RuntimeException( 'Invalid retained timestamp.' ); }
					$facts['decided_at'] = gmdate( 'c', (int) $at );
				}
				$ref = $order->get_meta( '_yogb_gbl_decision_ref', true, 'edit' );
				if ( ! is_string( $ref ) || ( '' !== $ref && ! preg_match( '/^gbl_dec_[a-f0-9]{32}$/D', $ref ) ) ) { throw new RuntimeException( 'Invalid retained reference.' ); }
				$facts['reference_present'] = '' !== $ref;
				$raw = $order->get_meta( '_yogb_gbl_match_summary_v2', true, 'edit' );
				if ( '' !== $raw ) {
					if ( ! is_string( $raw ) || strlen( $raw ) > 8192 ) { throw new RuntimeException( 'Unbounded retained matches.' ); }
					$matches = json_decode( $raw, true, 8, JSON_THROW_ON_ERROR );
					if ( ! is_array( $matches ) || ! array_is_list( $matches ) || count( $matches ) > 8 ) { throw new RuntimeException( 'Invalid retained matches.' ); }
					foreach ( $matches as $match ) {
						if ( ! is_array( $match ) || ! in_array( $match['type'] ?? null, [ 'email', 'phone', 'ip', 'address', 'device', 'name' ], true )
							|| ! in_array( $match['match_mode'] ?? null, [ 'exact', 'probable', 'none' ], true )
							|| ! is_bool( $match['requires_corroboration'] ?? null ) || ! is_bool( $match['direct'] ?? null ) ) { throw new RuntimeException( 'Unverifiable match category.' ); }
						$facts['matches'][] = [ 'type' => $match['type'], 'match_mode' => $match['match_mode'],
							'requires_corroboration' => $match['requires_corroboration'], 'direct' => $match['direct'] ];
					}
				}
				$data['status'] = 'observed';
			}
			if ( get_current_blog_id() !== $blog ) { throw new RuntimeException( 'Site context changed.' ); }
			$data['facts'] = $facts;
		} catch ( Throwable $exception ) {
			$data['status'] = 'unavailable';
			$data['facts'] = self::empty_facts();
		}
		return self::result( 'blacklist.get_order_assessment', $data );
	}
}
