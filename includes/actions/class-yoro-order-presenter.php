<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Presenter {

	public static function format_order_summary( WC_Order $order ) {
		return [
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'total'          => $order->get_total(),
			'currency'       => $order->get_currency(),
			'payment_method' => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'customer_name'  => trim( $order->get_formatted_billing_full_name() ),
			'billing_email'  => self::mask_email( $order->get_billing_email() ),
			'billing_phone'  => self::mask_phone( $order->get_billing_phone() ),
			'date_created'   => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'Y-m-d H:i:s' ) : '',
			'age_hours'      => self::get_order_age_hours( $order ),
			'edit_url'       => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
		];
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
		$data['date_paid']            = $order->get_date_paid() ? $order->get_date_paid()->date_i18n( 'Y-m-d H:i:s' ) : '';
		$data['date_completed']       = $order->get_date_completed() ? $order->get_date_completed()->date_i18n( 'Y-m-d H:i:s' ) : '';
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

		return $data;
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

			$data[] = [
				'author'    => sanitize_text_field( $note->added_by ?? '' ),
				'content'   => sanitize_textarea_field( wp_strip_all_tags( $note->content ?? '' ) ),
				'date'      => ! empty( $note->date_created ) && is_object( $note->date_created ) && method_exists( $note->date_created, 'date_i18n' )
					? $note->date_created->date_i18n( 'Y-m-d H:i:s' )
					: '',
				'customer'  => ! empty( $note->customer_note ),
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
