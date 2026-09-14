<?php
/** Retained Loyalty UI facts only; the owner retains all points semantics and mutations. */
defined( 'ABSPATH' ) || exit;

class YoRo_Loyalty_Service {
	private static function owner() {
		$free = class_exists( 'YOSWC_Loyalty', false );
		$premium = class_exists( 'YOWCL_Loyalty', false );
		$status = $free && $premium ? 'ambiguous' : ( $free || $premium ? 'incompatible' : 'absent' );
		$constant = $free ? 'YOSWC_LOYALTY_VERSION' : 'WC_LOYALTY_VERSION';
		if ( $free !== $premium && defined( $constant ) && '1.2.2' === constant( $constant ) ) {
			return [ 'status' => 'available', 'owner_kind' => $free ? 'free' : 'premium', 'version' => '1.2.2' ];
		}
		return [ 'status' => $status, 'owner_kind' => $status, 'version' => '' ];
	}

	private static function input( $params, $target ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { return new WP_Error( 'yoro_permission_denied', 'Store management permission is required.' ); }
		if ( ! is_array( $params ) ) { return new WP_Error( 'loyalty_invalid_input', 'Invalid read parameters.' ); }
		unset( $params['_yoro_confirmed'], $params['_yoro_preview'] );
		if ( array_diff( array_keys( $params ), $target ? [ 'customer_id' ] : [] ) || ( $target && ( ! is_int( $params['customer_id'] ?? null ) || $params['customer_id'] < 1 || $params['customer_id'] > 9007199254740991 ) ) ) {
			return new WP_Error( 'loyalty_invalid_input', 'Only the exact read parameters are accepted.' );
		}
		return $params;
	}

	private static function response( $action, array $data ) {
		$type = 'loyalty.get_status' === $action ? 'loyalty_status' : 'loyalty_customer_balance';
		return array_merge( [ 'success' => true, 'action' => $action, 'read_only' => true, 'undoable' => false, 'undo_plan' => [], 'display_type' => $type, 'result_type' => $type, 'message' => 'Loaded bounded Loyalty observations.', 'summary' => 'Loaded bounded Loyalty observations.' ], $data );
	}

	public static function get_status( $params ) {
		$input = self::input( $params, false );
		return is_wp_error( $input ) ? $input : self::response( 'loyalty.get_status', self::owner() );
	}

	private static function points( $id, $key ) {
		$values = get_user_meta( $id, $key, false );
		if ( [] === $values ) { return 0; }
		if ( ! is_array( $values ) || 1 !== count( $values ) ) { throw new RuntimeException( 'Invalid retained points.' ); }
		$value = reset( $values );
		if ( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]{0,15})$/D', $value ) && $value === (string) (int) $value ) { $value = (int) $value; }
		if ( ! is_int( $value ) || $value < 0 || $value > 9007199254740991 ) { throw new RuntimeException( 'Invalid retained points.' ); }
		return $value;
	}

	public static function get_customer_balance( $params ) {
		$input = self::input( $params, true );
		if ( is_wp_error( $input ) ) { return $input; }
		$blog = get_current_blog_id();
		$id = $input['customer_id'];
		try {
			// Exact ID inclusion and ID-only projection avoid fetching customer profile fields.
			$ids = get_users( [ 'include' => [ $id ], 'blog_id' => $blog, 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID', 'count_total' => false ] );
			if ( 1 !== count( $ids ) || (string) $id !== (string) reset( $ids ) || get_current_blog_id() !== $blog ) {
				return new WP_Error( 'loyalty_invalid_customer', 'An exact registered current-site customer is required.' );
			}
			$owner = self::owner();
			$data = array_merge( $owner, [ 'customer_id' => $id, 'status' => 'unavailable', 'current_points' => 0, 'earning_points' => 0 ] );
			if ( 'available' !== $owner['status'] ) { return self::response( 'loyalty.get_customer_balance', $data ); }
			$data['current_points'] = self::points( $id, 'user_points' );
			$data['earning_points'] = self::points( $id, 'user_earning_points' );
			if ( get_current_blog_id() !== $blog || self::owner() !== $owner ) { throw new RuntimeException( 'Context changed.' ); }
			$data['status'] = 'observed';
			return self::response( 'loyalty.get_customer_balance', $data );
		} catch ( Throwable $error ) {
			return new WP_Error( 'loyalty_invalid_observation', 'Loyalty observations are unavailable.' );
		}
	}
}
