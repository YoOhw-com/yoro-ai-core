<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Presenter {

	const PAYMENT_EVIDENCE_LIMITATION = 'WooCommerce order facts do not independently verify gateway capture, settlement, or confirmation.';

	public static function format_payment_context( WC_Order $order ) {
		$facts = [ 'order_id' => $order->get_id(), 'order_number' => $order->get_order_number() ];
		foreach ( [ 'status', 'currency', 'total', 'total_refunded', 'payment_method', 'payment_method_title', 'transaction_id', 'date_created', 'date_paid', 'is_paid', 'needs_payment' ] as $field ) {
			$method = in_array( $field, [ 'is_paid', 'needs_payment' ], true ) ? $field : 'get_' . $field;
			$facts[ $field ] = is_callable( [ $order, $method ] ) ? $order->$method() : null;
		}
		$facts['status_label'] = is_string( $facts['status'] ) && '' !== $facts['status']
			? YoRo_Order_Helper::get_order_status_label( $facts['status'] ) : null;
		foreach ( [ 'date_created', 'date_paid' ] as $field ) {
			$facts[ $field ] = $facts[ $field ] instanceof DateTimeInterface ? $facts[ $field ]->format( DATE_ATOM ) : null;
		}
		return self::normalize_payment_context( $facts );
	}

	/** Project explicit WooCommerce facts only; never read arbitrary metadata or gateway providers. */
	public static function normalize_payment_context( $facts ) {
		if ( ! is_array( $facts ) || ! is_int( $facts['order_id'] ?? null ) || $facts['order_id'] < 1 ) {
			return null;
		}
		$number = self::payment_text( $facts['order_number'] ?? null, 64 );
		if ( null === $number ) {
			return null;
		}
		$data = [ 'order_id' => $facts['order_id'], 'order_number' => $number ];
		foreach ( [ 'status' => 64, 'status_label' => 256, 'currency' => 8, 'payment_method' => 128, 'payment_method_title' => 256, 'transaction_id' => 128, 'date_created' => 40, 'date_paid' => 40 ] as $field => $limit ) {
			$data[ $field ] = self::payment_text( $facts[ $field ] ?? null, $limit );
		}
		foreach ( [ 'total', 'total_refunded' ] as $field ) {
			$value = $facts[ $field ] ?? null;
			$data[ $field ] = ( is_string( $value ) || is_int( $value ) || is_float( $value ) )
				&& preg_match( '/^[0-9]{1,18}(?:\.[0-9]{1,8})?$/D', (string) $value )
				? (string) $value : null;
		}
		$data['remaining_refundable'] = null !== $data['total'] && null !== $data['total_refunded']
			? wc_format_decimal( max( 0, (float) $data['total'] - (float) $data['total_refunded'] ), min( 8, max( 0, (int) wc_get_price_decimals() ) ) ) : null;
		if ( null !== $data['remaining_refundable'] && ! preg_match( '/^[0-9]{1,18}(?:\.[0-9]{1,8})?$/D', $data['remaining_refundable'] ) ) {
			$data['remaining_refundable'] = null;
		}
		foreach ( [ 'is_paid', 'needs_payment' ] as $field ) {
			$data[ $field ] = is_bool( $facts[ $field ] ?? null ) ? $facts[ $field ] : null;
		}
		$data['evidence'] = [ 'source' => 'woocommerce_order', 'gateway_verification' => 'not_observed', 'limitation' => self::PAYMENT_EVIDENCE_LIMITATION ];
		return $data;
	}

	private static function payment_text( $value, $limit ) {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		$value = sanitize_text_field( (string) $value );
		return '' !== $value && strlen( $value ) <= $limit ? $value : null;
	}

	public static function format_order_summary( WC_Order $order ) {
		$date_created = $order->get_date_created();
		$date_paid     = $order->get_date_paid();
		$draft_count  = self::get_order_email_draft_count( $order );

		return [
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'status_label'   => YoRo_Order_Helper::get_order_status_label( $order->get_status() ),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_name'  => trim( $order->get_formatted_billing_full_name() ),
			'billing_email'  => self::mask_email( $order->get_billing_email() ),
			'billing_phone'  => self::mask_phone( $order->get_billing_phone() ),
			'date_created'   => $date_created ? YoRo_Date_Helper::format_wc_datetime( $date_created ) : '',
			'date_created_ts'=> $date_created ? YoRo_Date_Helper::get_wc_timestamp( $date_created ) : 0,
			'date_paid'      => $date_paid ? YoRo_Date_Helper::format_wc_datetime( $date_paid ) : '',
			'age_hours'      => self::get_order_age_hours( $order ),
			'requires_shipping' => self::order_requires_shipping( $order ),
			'tracking_detected' => self::order_has_tracking( $order ),
			'draft_count'    => $draft_count,
			'has_email_drafts' => $draft_count > 0,
			'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
			'version'        => self::order_version( $order ),
		];
	}

	public static function order_version( WC_Order $order ) {
		$date_modified = $order->get_date_modified();

		return hash(
			'sha256',
			wp_json_encode(
				[
					$order->get_id(),
					$date_modified ? $date_modified->getTimestamp() : 0,
					$order->get_status(),
					$order->get_total(),
					$order->get_currency(),
					$order->get_shipping_first_name( 'edit' ),
					$order->get_shipping_last_name( 'edit' ),
					$order->get_shipping_company( 'edit' ),
					$order->get_shipping_address_1( 'edit' ),
					$order->get_shipping_address_2( 'edit' ),
					$order->get_shipping_city( 'edit' ),
					$order->get_shipping_state( 'edit' ),
					$order->get_shipping_postcode( 'edit' ),
					$order->get_shipping_country( 'edit' ),
				]
			)
		);
	}

	public static function format_order_details( WC_Order $order ) {
		$data = self::format_order_summary( $order );

		$data['customer_name']       = trim( $order->get_formatted_billing_full_name() );
		$data['billing_first_name'] = $order->get_billing_first_name();
		$data['billing_last_name']  = $order->get_billing_last_name();
		$data['billing_company']    = $order->get_billing_company();
		$data['billing_address_1']  = $order->get_billing_address_1();
		$data['billing_address_2']  = $order->get_billing_address_2();
		$data['billing_country']    = $order->get_billing_country();
		$data['billing_state']      = $order->get_billing_state();
		$data['billing_city']       = $order->get_billing_city();
		$data['billing_postcode']   = $order->get_billing_postcode();
		$data['shipping_first_name'] = $order->get_shipping_first_name();
		$data['shipping_last_name']  = $order->get_shipping_last_name();
		$data['shipping_company']    = $order->get_shipping_company();
		$data['shipping_address_1']  = $order->get_shipping_address_1();
		$data['shipping_address_2']  = $order->get_shipping_address_2();
		$data['shipping_country']   = $order->get_shipping_country();
		$data['shipping_state']     = $order->get_shipping_state();
		$data['shipping_city']      = $order->get_shipping_city();
		$data['shipping_postcode']  = $order->get_shipping_postcode();
		$data['customer_id']        = $order->get_customer_id();
		$data['payment_method_title'] = $order->get_payment_method_title();
		$data['transaction_id']       = $order->get_transaction_id();
		$date_paid                    = $order->get_date_paid();
		$date_completed               = $order->get_date_completed();
		$data['date_paid']            = $date_paid ? YoRo_Date_Helper::format_wc_datetime( $date_paid ) : '';
		$data['date_paid_ts']         = $date_paid ? YoRo_Date_Helper::get_wc_timestamp( $date_paid ) : 0;
		$data['date_completed']       = $date_completed ? YoRo_Date_Helper::format_wc_datetime( $date_completed ) : '';
		$data['date_completed_ts']    = $date_completed ? YoRo_Date_Helper::get_wc_timestamp( $date_completed ) : 0;
		$data['currency_symbol']      = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $order->get_currency() ) : $order->get_currency();
		$data['item_count']           = (int) $order->get_item_count();
		$data['age_hours']            = self::get_order_age_hours( $order );
		$data['shipping_method']      = self::get_order_shipping_method_label( $order );
		$data['requires_shipping']    = self::order_requires_shipping( $order );
		$data['tracking_detected']    = self::order_has_tracking( $order );
		$data['shipping_complete']    = ! self::order_has_incomplete_shipping_address( $order );
		$data['ready_to_fulfill']     = self::order_is_ready_to_fulfill( $order );
		$data['totals']               = self::format_order_totals( $order );
		$data['line_items']           = self::format_order_line_items( $order );
		$data['customer_note']        = sanitize_textarea_field( (string) $order->get_customer_note() );
		$data['notes']                = self::format_order_notes( $order );
		$data['related_orders']       = self::format_related_orders( $order );

		return $data;
	}

	private static function format_related_orders( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = [
			'limit'   => 10,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		];
		$customer_id = (int) $order->get_customer_id();

		if ( $customer_id > 0 ) {
			$args['customer_id'] = $customer_id;
		} else {
			$email = sanitize_email( $order->get_billing_email() );
			if ( '' === $email ) {
				return [];
			}
			$args['billing_email'] = $email;
		}

		$related = [];
		foreach ( wc_get_orders( $args ) as $candidate ) {
			if ( ! $candidate instanceof WC_Order || $candidate->get_id() === $order->get_id() ) {
				continue;
			}

			$related[] = self::format_order_summary( $candidate );
		}

		return $related;
	}

	private static function format_order_totals( WC_Order $order ) {
		return [
			'subtotal'       => wc_format_decimal( (float) $order->get_subtotal(), wc_get_price_decimals() ),
			'discount_total' => wc_format_decimal( (float) $order->get_discount_total(), wc_get_price_decimals() ),
			'shipping_total' => wc_format_decimal( (float) $order->get_shipping_total(), wc_get_price_decimals() ),
			'tax_total'      => wc_format_decimal( (float) $order->get_total_tax(), wc_get_price_decimals() ),
			'refunded_total' => wc_format_decimal( (float) $order->get_total_refunded(), wc_get_price_decimals() ),
			'remaining_total'=> wc_format_decimal( max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() ), wc_get_price_decimals() ),
		];
	}

	private static function format_order_line_items( WC_Order $order ) {
		$items = [];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			$items[] = [
				'name'         => $item->get_name(),
				'quantity'     => (int) $item->get_quantity(),
				'sku'          => $product instanceof WC_Product ? $product->get_sku() : '',
				'virtual'      => $product instanceof WC_Product ? (bool) $product->is_virtual() : false,
				'downloadable' => $product instanceof WC_Product ? (bool) $product->is_downloadable() : false,
				'total'        => wc_format_decimal( (float) $item->get_total(), wc_get_price_decimals() ),
				'subtotal'     => wc_format_decimal( (float) $item->get_subtotal(), wc_get_price_decimals() ),
			];
		}

		return $items;
	}

	private static function format_order_notes( WC_Order $order ) {
		if ( ! function_exists( 'wc_get_order_notes' ) ) {
			return [];
		}

		$notes = wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 3,
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			]
		);

		$data = [];

		foreach ( $notes as $note ) {
			if ( ! is_object( $note ) ) {
				continue;
			}

			$date_created = ! empty( $note->date_created ) && is_object( $note->date_created ) && method_exists( $note->date_created, 'getTimestamp' )
				? $note->date_created
				: null;

			$data[] = [
				'author'    => sanitize_text_field( $note->added_by ?? '' ),
				'content'   => sanitize_textarea_field( wp_strip_all_tags( $note->content ?? '' ) ),
				'date'      => $date_created
					? YoRo_Date_Helper::format_wc_datetime( $date_created )
					: '',
				'date_ts'   => $date_created ? YoRo_Date_Helper::get_wc_timestamp( $date_created ) : 0,
				'customer'  => ! empty( $note->customer_note ),
				'via_yoro'  => 'chat' === (string) get_comment_meta( absint( $note->id ?? 0 ), '_yoro_note_origin', true ),
			];
		}

		return $data;
	}

	private static function get_order_age_hours( WC_Order $order ) {
		$created = $order->get_date_created();

		if ( ! $created ) {
			return 0;
		}

		$now = current_datetime()->getTimestamp();
		$timestamp = $created->getTimestamp();

		if ( $timestamp <= 0 ) {
			return 0;
		}

		return max( 0, (int) floor( ( $now - $timestamp ) / HOUR_IN_SECONDS ) );
	}

	private static function get_order_email_draft_count( WC_Order $order ) {
		$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );

		return is_array( $drafts ) ? count( $drafts ) : 0;
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
		if ( self::shipping_requirements_disabled() ) {
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
		if ( self::shipping_requirements_disabled() ) {
			return true;
		}

		return '' !== trim( (string) self::get_order_shipping_method_label( $order ) );
	}

	private static function shipping_requirements_disabled() {
		return 'disabled' === sanitize_key( (string) get_option( 'woocommerce_ship_to_countries', '' ) );
	}

	public static function mask_email( $email ) {
		return sanitize_email( $email );
	}

	public static function mask_phone( $phone ) {
		return trim( (string) $phone );
	}
}
