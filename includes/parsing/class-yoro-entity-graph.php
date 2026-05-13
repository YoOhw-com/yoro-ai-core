<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Entity_Graph {

	public static function from_order_ids( $order_ids ) {
		$order_ids = is_array( $order_ids )
			? array_values( array_filter( array_unique( array_map( 'absint', $order_ids ) ) ) )
			: [];

		if ( empty( $order_ids ) ) {
			return [];
		}

		$orders    = [];
		$customers = [];
		$emails    = [];
		$phones    = [];

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$email = sanitize_email( $order->get_billing_email() );
			$phone = sanitize_text_field( $order->get_billing_phone() );

			$billing_name = trim(
				$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
			);

			$orders[] = [
				'id'          => $order->get_id(),
				'number'      => $order->get_order_number(),
				'status'      => $order->get_status(),
				'customer_id' => absint( $order->get_customer_id() ),
				'email'       => $email,
				'phone'       => $phone,
				'name'        => $billing_name,
			];

			if ( $email ) {
				$emails[] = $email;
			}

			if ( $phone ) {
				$phones[] = $phone;
			}

			if ( $order->get_customer_id() ) {
				$customers[] = absint( $order->get_customer_id() );
			}
		}

		return [
			'orders'       => $orders,
			'order_ids'    => wp_list_pluck( $orders, 'id' ),
			'customer_ids' => array_values( array_unique( $customers ) ),
			'emails'       => array_values( array_unique( $emails ) ),
			'phones'       => array_values( array_unique( $phones ) ),
		];
	}
}