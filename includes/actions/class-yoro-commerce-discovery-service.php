<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Discovery_Service {

	public static function search_products( $params ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$limit = min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) );
		$args = [
			'limit'   => $limit,
			'orderby' => 'modified',
			'order'   => 'DESC',
			'return'  => 'objects',
		];
		$query = sanitize_text_field( $params['query'] ?? '' );
		$sku = sanitize_text_field( $params['sku'] ?? '' );

		if ( '' !== $query ) {
			$args['s'] = $query;
		}

		foreach ( [ 'status', 'type', 'stock_status' ] as $field ) {
			$value = sanitize_key( $params[ $field ] ?? '' );

			if ( '' !== $value ) {
				$args[ $field ] = $value;
			}
		}

		if ( '' !== $sku ) {
			$product_id = function_exists( 'wc_get_product_id_by_sku' ) ? absint( wc_get_product_id_by_sku( $sku ) ) : 0;
			$products = $product_id > 0 ? [ wc_get_product( $product_id ) ] : [];
			$products = array_filter(
				$products,
				static function ( $product ) use ( $args ) {
					if ( ! $product instanceof WC_Product ) {
						return false;
					}
					foreach ( [ 'status' => 'get_status', 'type' => 'get_type', 'stock_status' => 'get_stock_status' ] as $field => $getter ) {
						if ( isset( $args[ $field ] ) && $product->$getter() !== $args[ $field ] ) {
							return false;
						}
					}

					return true;
				}
			);
		} else {
			$products = wc_get_products( $args );
		}

		$products = array_values(
			array_filter(
				array_map( [ __CLASS__, 'present_product' ], (array) $products )
			)
		);
		$summary = sprintf(
			/* translators: %d: product count */
			__( 'Found %d matching products.', 'yoro-ai-core' ),
			count( $products )
		);

		return self::read_result( 'products.search', $summary, [
			'products' => $products,
			'page_info' => [ 'has_next_page' => count( $products ) >= $limit, 'next_cursor' => null ],
			'count' => count( $products ),
		] );
	}

	public static function get_product_details( $params ) {
		$product_ids = array_slice(
			array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['product_ids'] ?? [] ) ) ) ) ),
			0,
			20
		);

		if ( [] === $product_ids ) {
			return new WP_Error( 'invalid_params', __( 'Please choose at least one product.', 'yoro-ai-core' ) );
		}

		$products = [];

		foreach ( $product_ids as $product_id ) {
			$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
			$presented = self::present_product( $product );

			if ( is_array( $presented ) ) {
				$products[] = $presented;
			}
		}

		$summary = sprintf(
			/* translators: %d: product count */
			__( 'Loaded details for %d products.', 'yoro-ai-core' ),
			count( $products )
		);

		return self::read_result( 'products.get_details', $summary, [
			'products' => $products,
			'count' => count( $products ),
		] );
	}

	public static function get_order_timeline( $params ) {
		$order_id = absint( $params['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		$events = [];
		self::append_date_event( $events, 'order_created', __( 'Order created.', 'yoro-ai-core' ), $order->get_date_created() );
		self::append_date_event( $events, 'payment_recorded', __( 'Payment recorded.', 'yoro-ai-core' ), $order->get_date_paid() );
		self::append_date_event( $events, 'order_completed', __( 'Order completed.', 'yoro-ai-core' ), $order->get_date_completed() );

		if ( function_exists( 'wc_get_order_notes' ) ) {
			$notes = wc_get_order_notes( [
				'order_id' => $order_id,
				'limit'    => min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) ),
				'orderby'  => 'date_created',
				'order'    => 'DESC',
			] );

			foreach ( $notes as $note ) {
				if ( ! is_object( $note ) ) {
					continue;
				}

				$date = is_object( $note->date_created ?? null ) && method_exists( $note->date_created, 'date' )
					? $note->date_created->date( DATE_RFC3339 )
					: '';
				$events[] = [
					'id'          => 'note_' . absint( $note->id ?? 0 ),
					'type'        => 'order_note',
					'visibility'  => ! empty( $note->customer_note ) ? 'customer' : 'internal',
					'summary'     => sanitize_textarea_field( wp_strip_all_tags( $note->content ?? '' ) ),
					'occurred_at' => $date,
					'extensions'  => [
						'woocommerce.note' => [
							'author' => sanitize_text_field( $note->added_by ?? '' ),
						],
					],
				];
			}
		}

		usort(
			$events,
			static function ( $left, $right ) {
				return strcmp( (string) ( $left['occurred_at'] ?? '' ), (string) ( $right['occurred_at'] ?? '' ) );
			}
		);
		$summary = sprintf(
			/* translators: 1: order id, 2: timeline event count */
			__( 'Loaded %2$d timeline events for order #%1$d.', 'yoro-ai-core' ),
			$order_id,
			count( $events )
		);

		return self::read_result( 'orders.get_timeline', $summary, [
			'order_id' => $order_id,
			'events'   => $events,
			'count'    => count( $events ),
		] );
	}

	public static function get_fulfillment_tracking( $params ) {
		$order_id = absint( $params['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
		}

		$shipments = [];
		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		foreach ( is_array( $shipment_items ) ? $shipment_items : [] as $index => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			self::append_tracking_item(
				$shipments,
				(string) ( $item['tracking_number'] ?? '' ),
				(string) ( $item['tracking_provider'] ?? ( $item['custom_tracking_provider'] ?? '' ) ),
				(string) ( $item['custom_tracking_link'] ?? '' ),
				'shipment_' . ( $index + 1 )
			);
		}

		foreach ( [ '_tracking_number', '_aftership_tracking_number', '_wcast_tracking_number', '_wcst_order_trackno', '_yith_tracking_code' ] as $key ) {
			self::append_tracking_item( $shipments, (string) $order->get_meta( $key, true ), '', '', sanitize_key( ltrim( $key, '_' ) ) );
		}

		$status = [] === $shipments ? 'unavailable' : 'available';
		$summary = [] === $shipments
			? sprintf( __( 'No tracking number is stored for order #%d.', 'yoro-ai-core' ), $order_id )
			: sprintf( __( 'Loaded %1$d tracking record(s) for order #%2$d.', 'yoro-ai-core' ), count( $shipments ), $order_id );

		return self::read_result( 'fulfillment.get_tracking', $summary, [
			'order_id' => $order_id,
			'status' => $status,
			'shipments' => $shipments,
			'count' => count( $shipments ),
		] );
	}

	private static function present_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return null;
		}

		return [
			'id'             => $product->get_id(),
			'name'           => sanitize_text_field( $product->get_name() ),
			'sku'            => sanitize_text_field( $product->get_sku() ),
			'status'         => sanitize_key( $product->get_status() ),
			'type'           => sanitize_key( $product->get_type() ),
			'price'          => (string) $product->get_price(),
			'currency'       => get_woocommerce_currency(),
			'stock_quantity' => is_numeric( $product->get_stock_quantity() ) ? (int) $product->get_stock_quantity() : null,
			'stock_status'   => sanitize_key( $product->get_stock_status() ),
			'version'        => self::product_version( $product ),
			'extensions'     => [
				'woocommerce.product' => [
					'parent_id'        => absint( $product->get_parent_id() ),
					'managing_stock'   => (bool) $product->managing_stock(),
					'global_unique_id' => method_exists( $product, 'get_global_unique_id' ) ? sanitize_text_field( $product->get_global_unique_id() ) : '',
				],
			],
		];
	}

	public static function product_version( WC_Product $product ) {
		$date_modified = $product->get_date_modified();

		return hash(
			'sha256',
			wp_json_encode(
				[
					$product->get_id(),
					$date_modified ? $date_modified->getTimestamp() : 0,
					$product->get_status(),
					$product->get_stock_quantity(),
					$product->get_stock_status(),
					$product->get_price(),
                    YoRo_Product_Edit::facts($product),
                    ($product->get_date_on_sale_from('edit') ? $product->get_date_on_sale_from('edit')->getTimestamp() : null),
                    ($product->get_date_on_sale_to('edit') ? $product->get_date_on_sale_to('edit')->getTimestamp() : null),
				]
			)
		);
	}

	private static function append_date_event( &$events, $type, $summary, $date ) {
		if ( ! is_object( $date ) || ! method_exists( $date, 'date' ) ) {
			return;
		}

		$events[] = [
			'id'          => sanitize_key( $type ),
			'type'        => sanitize_key( $type ),
			'visibility'  => 'system',
			'summary'     => sanitize_text_field( $summary ),
			'occurred_at' => $date->date( DATE_RFC3339 ),
			'extensions'  => [],
		];
	}

	private static function append_tracking_item( &$shipments, $number, $carrier, $tracking_url, $source ) {
		$number = sanitize_text_field( $number );

		if ( '' === $number ) {
			return;
		}

		foreach ( $shipments as $shipment ) {
			if ( $number === ( $shipment['tracking_number'] ?? '' ) ) {
				return;
			}
		}

		$shipments[] = [
			'carrier'        => sanitize_text_field( $carrier ),
			'tracking_number' => $number,
			'tracking_url'   => esc_url_raw( $tracking_url ),
			'status'         => 'available',
			'extensions'     => [
				'woocommerce.tracking' => [ 'source' => sanitize_key( $source ) ],
			],
		];
	}

	private static function read_result( $action, $summary, array $data ) {
		return array_merge(
			[
				'success'           => true,
				'action'            => sanitize_text_field( $action ),
				'summary'           => sanitize_text_field( $summary ),
				'message'           => sanitize_text_field( $summary ),
				'read_only'         => true,
				'undoable'          => false,
				'undo_plan'         => [],
				'result_type'       => 'generic',
				'display_type'      => 'generic',
				'assistant_summary' => sanitize_text_field( $summary ),
			],
			$data
		);
	}
}
