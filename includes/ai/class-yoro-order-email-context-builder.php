<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Order_Email_Context_Builder {

	const CONTEXT_VERSION = 'order_email_context.v2';

	public static function build( WC_Order $order ) {
		$order_details = YoRo_Order_Presenter::format_order_details( $order );

		$requires_shipping = self::bool_or_null(
			array_key_exists( 'requires_shipping', $order_details )
				? $order_details['requires_shipping']
				: self::order_requires_shipping( $order )
		);
		$tracking_available = self::bool_or_null( $order_details['tracking_detected'] ?? null );
		$shipping_address_complete = self::bool_or_null( $order_details['shipping_complete'] ?? null );
		$shipping_method_available = '' !== trim( (string) ( $order_details['shipping_method'] ?? '' ) );
		$ready_to_fulfill = self::bool_or_null( $order_details['ready_to_fulfill'] ?? null );
		$status = $order->get_status();
		$status_label = YoRo_Order_Helper::get_order_status_label( $status );
		$fulfillment_state = self::fulfillment_state(
			$status,
			$requires_shipping,
			$tracking_available,
			$shipping_address_complete,
			$shipping_method_available,
			$ready_to_fulfill
		);
		$line_items = isset( $order_details['line_items'] ) && is_array( $order_details['line_items'] )
			? array_slice( $order_details['line_items'], 0, 8 )
			: [];
		$constraints = self::communication_constraints(
			$status,
			$status_label,
			$requires_shipping,
			$tracking_available,
			$shipping_address_complete,
			$shipping_method_available,
			$ready_to_fulfill,
			$fulfillment_state
		);
		$tracking_context = self::tracking_context( $order, $tracking_available );
		$customer_history = self::customer_history( $order );
		$store_context    = self::store_email_context();
		$policy_context   = self::policy_context( $store_context );
		$email_pack       = self::email_context_pack(
			$order,
			$status,
			$status_label,
			$line_items,
			$requires_shipping,
			$tracking_available,
			$shipping_address_complete,
			$shipping_method_available,
			$ready_to_fulfill,
			$fulfillment_state,
			$tracking_context,
			$customer_history,
			$store_context,
			$policy_context,
			$constraints,
			$order_details
		);

		return [
			'context_version'    => self::CONTEXT_VERSION,
			'order_number'       => $order->get_order_number(),
			'status'             => $status,
			'status_label'       => $status_label,
			'total'              => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
			'currency'           => $order->get_currency(),
			'customer_name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'payment_method'     => $order->get_payment_method_title(),
			'shipping_name'      => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'shipping_city'      => $order->get_shipping_city(),
			'shipping_state'     => $order->get_shipping_state(),
			'shipping_country'   => $order->get_shipping_country(),
			'shipping_method'    => sanitize_text_field( $order_details['shipping_method'] ?? '' ),
			'item_count'         => isset( $order_details['item_count'] ) ? absint( $order_details['item_count'] ) : (int) $order->get_item_count(),
			'line_items'         => $line_items,
			'requires_shipping'  => $requires_shipping,
			'tracking_detected'  => $tracking_available,
			'shipping_complete'  => $shipping_address_complete,
			'ready_to_fulfill'   => $ready_to_fulfill,
			'fulfillment_state'  => $fulfillment_state,
			'customer_note'      => sanitize_textarea_field( $order_details['customer_note'] ?? '' ),
			'order'              => [
				'id'            => $order->get_id(),
				'number'        => $order->get_order_number(),
				'status'        => $status,
				'status_label'  => $status_label,
				'total'         => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
				'currency'      => $order->get_currency(),
				'created_at'    => sanitize_text_field( $order_details['date_created'] ?? '' ),
				'age_hours'     => isset( $order_details['age_hours'] ) ? absint( $order_details['age_hours'] ) : 0,
				'customer_note' => sanitize_textarea_field( $order_details['customer_note'] ?? '' ),
			],
			'customer'           => [
				'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email' => sanitize_email( $order->get_billing_email() ),
				'phone' => sanitize_text_field( $order->get_billing_phone() ),
			],
			'payment'            => [
				'method'         => sanitize_text_field( $order->get_payment_method() ),
				'method_title'   => sanitize_text_field( $order->get_payment_method_title() ),
				'transaction_id' => sanitize_text_field( $order->get_transaction_id() ),
				'paid_at'        => sanitize_text_field( $order_details['date_paid'] ?? '' ),
			],
			'shipping'           => [
				'requires_shipping'        => $requires_shipping,
				'address_complete'         => $shipping_address_complete,
				'method_available'         => $shipping_method_available,
				'method'                   => sanitize_text_field( $order_details['shipping_method'] ?? '' ),
				'name'                     => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'city'                     => sanitize_text_field( $order->get_shipping_city() ),
				'state'                    => sanitize_text_field( $order->get_shipping_state() ),
				'country'                  => sanitize_text_field( $order->get_shipping_country() ),
				'tracking_available'       => $tracking_available,
				'ready_to_fulfill'         => $ready_to_fulfill,
				'fulfillment_state'        => $fulfillment_state,
			],
			'fulfillment'        => [
				'state'                     => $fulfillment_state,
				'order_status'              => $status,
				'order_status_label'        => $status_label,
				'requires_shipping'         => $requires_shipping,
				'tracking_available'        => $tracking_available,
				'shipping_address_complete' => $shipping_address_complete,
				'shipping_method_available' => $shipping_method_available,
				'ready_to_fulfill'          => $ready_to_fulfill,
			],
			'fulfillment_guidance' => self::store_fulfillment_guidance( $requires_shipping ),
			'tracking'             => $tracking_context,
			'customer_history'     => $customer_history,
			'store_context'        => $store_context,
			'policy_context'       => $policy_context,
			'email_context_pack'   => $email_pack,
			'communication_constraints' => $constraints,
			'supported_claims'          => $constraints['supported_claims'],
			'unsupported_claims'        => $constraints['unsupported_claims'],
			'unknowns'                  => $constraints['unknowns'],
		];
	}

	private static function email_context_pack( WC_Order $order, $status, $status_label, array $line_items, $requires_shipping, $tracking_available, $shipping_address_complete, $shipping_method_available, $ready_to_fulfill, $fulfillment_state, array $tracking_context, array $customer_history, array $store_context, array $policy_context, array $constraints, array $order_details ) {
		return [
			'version'  => 'plugin_email_context_pack.v1',
			'order'    => [
				'id'            => $order->get_id(),
				'number'        => $order->get_order_number(),
				'status'        => $status,
				'status_label'  => $status_label,
				'total'         => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
				'currency'      => $order->get_currency(),
				'created_at'    => sanitize_text_field( $order_details['date_created'] ?? '' ),
				'age_hours'     => isset( $order_details['age_hours'] ) ? absint( $order_details['age_hours'] ) : 0,
				'customer_note' => sanitize_textarea_field( $order_details['customer_note'] ?? '' ),
				'totals'        => is_array( $order_details['totals'] ?? null ) ? $order_details['totals'] : [],
			],
			'customer' => [
				'name'    => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'type'    => sanitize_key( $customer_history['customer_type'] ?? '' ),
				'history' => $customer_history,
			],
			'payment'  => [
				'method'                => sanitize_text_field( $order->get_payment_method() ),
				'method_title'          => sanitize_text_field( $order->get_payment_method_title() ),
				'paid_at'               => sanitize_text_field( $order_details['date_paid'] ?? '' ),
				'transaction_available' => '' !== trim( (string) $order->get_transaction_id() ),
			],
			'items'    => array_slice( $line_items, 0, 8 ),
			'shipping' => [
				'requires_shipping' => $requires_shipping,
				'address_complete'  => $shipping_address_complete,
				'method_available'  => $shipping_method_available,
				'method'            => sanitize_text_field( $order_details['shipping_method'] ?? '' ),
				'name'              => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'city'              => sanitize_text_field( $order->get_shipping_city() ),
				'state'             => sanitize_text_field( $order->get_shipping_state() ),
				'country'           => sanitize_text_field( $order->get_shipping_country() ),
			],
			'fulfillment' => [
				'state'                     => $fulfillment_state,
				'order_status'              => $status,
				'order_status_label'        => $status_label,
				'requires_shipping'         => $requires_shipping,
				'tracking_available'        => $tracking_available,
				'shipping_address_complete' => $shipping_address_complete,
				'shipping_method_available' => $shipping_method_available,
				'ready_to_fulfill'          => $ready_to_fulfill,
				'guidance'                  => self::store_fulfillment_guidance( $requires_shipping ),
			],
			'tracking' => $tracking_context,
			'store'    => $store_context,
			'policies' => $policy_context,
			'communication_contract' => $constraints,
			'capability_boundary'    => [
				'can_compose_customer_email_draft' => true,
				'can_send_email'                   => false,
				'can_save_email_draft'             => false,
				'must_not_claim_email_was_sent_or_saved' => true,
			],
		];
	}

	private static function store_fulfillment_guidance( $requires_shipping ) {
		if ( ! class_exists( 'YoRo_Store_Knowledge_Provider' ) ) {
			return [];
		}

		$knowledge = YoRo_Store_Knowledge_Provider::get_context( 'email' );
		$profiles  = is_array( $knowledge['profiles'] ?? null ) ? $knowledge['profiles'] : [];
		$commerce  = is_array( $profiles['commerce'] ?? null ) ? $profiles['commerce'] : [];
		$operations = is_array( $profiles['operations'] ?? null ) ? $profiles['operations'] : [];
		$model     = sanitize_key( (string) ( $commerce['fulfillment_model_estimate'] ?? '' ) );
		$confidence = max( 0, min( 1, is_numeric( $commerce['fulfillment_model_confidence'] ?? null ) ? (float) $commerce['fulfillment_model_confidence'] : 0 ) );
		$thresholds = [];

		foreach ( is_array( $operations['default_thresholds'] ?? null ) ? $operations['default_thresholds'] : [] as $key => $value ) {
			if ( ! is_scalar( $key ) || ! is_numeric( $value ) ) {
				continue;
			}

			$thresholds[ sanitize_key( (string) $key ) ] = absint( $value );
		}

		$notes = [];

		if ( 'physical_goods' === $model && true === $requires_shipping ) {
			$notes[] = 'Store profile suggests physical goods; shipment claims still require explicit order tracking facts.';
		}

		if ( in_array( $model, [ 'digital_goods', 'subscription_possible' ], true ) || false === $requires_shipping ) {
			$notes[] = 'Avoid physical-shipping language unless the order facts explicitly require shipping.';
		}

		if ( '' === $model && empty( $thresholds ) && empty( $notes ) ) {
			return [];
		}

		return array_filter(
			[
				'basis'                         => 'store_profile_context',
				'profile_signals_are_inferred'  => true,
				'fulfillment_model'             => $model,
				'fulfillment_model_confidence'  => round( $confidence, 3 ),
				'default_thresholds'            => array_filter( $thresholds ),
				'notes'                         => array_values( array_unique( $notes ) ),
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	private static function tracking_context( WC_Order $order, $tracking_available ) {
		$items = [];
		$shipment_items = $order->get_meta( '_wc_shipment_tracking_items', true );

		if ( is_array( $shipment_items ) ) {
			foreach ( $shipment_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$items[] = self::compact_tracking_item(
					$item['tracking_provider'] ?? ( $item['custom_tracking_provider'] ?? '' ),
					$item['tracking_number'] ?? '',
					$item['tracking_link'] ?? '',
					$item['date_shipped'] ?? ''
				);
			}
		}

		$meta_candidates = [
			[ '_tracking_number', '_tracking_provider', '_tracking_link', '_date_shipped' ],
			[ '_aftership_tracking_number', '_aftership_tracking_provider', '_aftership_tracking_url', '_aftership_tracking_ship_date' ],
			[ '_wcast_tracking_number', '_wcast_tracking_company_name', '_wcast_tracking_url', '_wcast_dispatch_date' ],
			[ '_wcst_order_trackno', '_wcst_order_trackname', '_wcst_order_trackurl', '_wcst_order_dispatch_date' ],
			[ '_yith_tracking_code', '_yith_tracking_carrier_name', '_yith_tracking_url', '_yith_tracking_pick_up_date' ],
		];

		foreach ( $meta_candidates as $candidate ) {
			$number = $order->get_meta( $candidate[0], true );

			if ( ! is_scalar( $number ) || '' === trim( (string) $number ) ) {
				continue;
			}

			$items[] = self::compact_tracking_item(
				$order->get_meta( $candidate[1], true ),
				$number,
				$order->get_meta( $candidate[2], true ),
				$order->get_meta( $candidate[3], true )
			);
		}

		$items = array_values(
			array_filter(
				array_map(
					static fn( $item ) => is_array( $item ) ? array_filter( $item, static fn( $value ) => null !== $value && '' !== $value && [] !== $value ) : [],
					$items
				)
			)
		);

		return [
			'available' => self::bool_or_null( $tracking_available ),
			'items'     => array_slice( self::unique_values( $items ), 0, 3 ),
		];
	}

	private static function compact_tracking_item( $provider, $number, $link, $date_shipped ) {
		$date_value = '';

		if ( is_numeric( $date_shipped ) && (int) $date_shipped > 0 ) {
			$date_value = gmdate( 'Y-m-d', (int) $date_shipped );
		} elseif ( is_scalar( $date_shipped ) ) {
			$date_value = sanitize_text_field( (string) $date_shipped );
		}

		return [
			'provider'        => is_scalar( $provider ) ? sanitize_text_field( (string) $provider ) : '',
			'tracking_number' => is_scalar( $number ) ? sanitize_text_field( (string) $number ) : '',
			'tracking_link'   => is_scalar( $link ) ? esc_url_raw( (string) $link ) : '',
			'date_shipped'    => $date_value,
		];
	}

	private static function customer_history( WC_Order $order ) {
		$customer_id = absint( $order->get_customer_id() );
		$email       = sanitize_email( $order->get_billing_email() );
		$type        = $customer_id > 0 ? 'registered' : 'guest';
		$total_spent = '';
		$known_count = null;

		if ( $customer_id > 0 && function_exists( 'wc_get_customer_order_count' ) ) {
			$known_count = max( 0, absint( wc_get_customer_order_count( $customer_id ) ) - 1 );
		}

		if ( $customer_id > 0 && function_exists( 'wc_get_customer_total_spent' ) ) {
			$total_spent = wc_format_decimal( (float) wc_get_customer_total_spent( $customer_id ), wc_get_price_decimals() );
		}

		$recent_orders = self::recent_customer_orders( $order, $customer_id, $email );
		$sample_count  = count( $recent_orders );
		$prior_count   = null !== $known_count ? $known_count : $sample_count;
		$last_order    = $recent_orders[0] ?? null;

		return array_filter(
			[
				'customer_type'             => $type,
				'is_repeat_customer'        => $prior_count > 0,
				'prior_order_count_sample'  => $prior_count,
				'total_spent'               => $total_spent,
				'last_order_date'           => $last_order instanceof WC_Order && $last_order->get_date_created()
					? YoRo_Date_Helper::format_wc_datetime( $last_order->get_date_created() )
					: '',
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	private static function recent_customer_orders( WC_Order $order, $customer_id, $email ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = [
			'limit'   => 6,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
			'status'  => array_keys( wc_get_order_statuses() ),
		];

		if ( $customer_id > 0 ) {
			$args['customer_id'] = $customer_id;
		} elseif ( '' !== $email ) {
			$args['billing_email'] = $email;
		} else {
			return [];
		}

		try {
			$orders = wc_get_orders( $args );
		} catch ( Throwable $exception ) {
			return [];
		}

		return array_values(
			array_filter(
				(array) $orders,
				static fn( $candidate ) => $candidate instanceof WC_Order && $candidate->get_id() !== $order->get_id()
			)
		);
	}

	private static function store_email_context() {
		if ( ! class_exists( 'YoRo_Store_Knowledge_Provider' ) ) {
			return [];
		}

		$knowledge = YoRo_Store_Knowledge_Provider::get_context( 'email' );
		$settings  = self::array_path( $knowledge, 'facts.settings', [] );

		return array_filter(
			[
				'schema_version' => absint( $knowledge['schema_version'] ?? 0 ),
				'identity'       => self::compact_assoc( self::array_path( $knowledge, 'facts.identity', [] ), 8 ),
				'locale'         => self::compact_assoc( self::array_path( $knowledge, 'facts.locale', [] ), 10 ),
				'commerce_profile' => self::compact_assoc( self::array_path( $knowledge, 'profiles.commerce', [] ), 12 ),
				'settings'       => [
					'general'  => [
						'selling_locations'  => self::array_path( $settings, 'general.selling_locations', [] ),
						'shipping_locations' => self::array_path( $settings, 'general.shipping_locations', [] ),
						'taxes_enabled'      => self::array_path( $settings, 'general.taxes_enabled', null ),
						'coupons_enabled'    => self::array_path( $settings, 'general.coupons_enabled', null ),
						'currency'           => self::array_path( $settings, 'general.currency', '' ),
					],
					'shipping' => self::compact_assoc( self::array_path( $settings, 'shipping', [] ), 14 ),
					'payments' => [
						'methods' => array_slice( (array) self::array_path( $settings, 'payments.methods', [] ), 0, 12 ),
					],
					'checkout' => self::compact_assoc( self::array_path( $settings, 'checkout', [] ), 14 ),
					'tax'      => self::compact_assoc( self::array_path( $settings, 'tax', [] ), 12 ),
					'email'    => self::compact_assoc( self::array_path( $settings, 'email', [] ), 14 ),
				],
				'integrations'   => [
					'integrated_adapters' => array_slice( (array) self::array_path( $knowledge, 'facts.integrations.integrated_adapters', [] ), 0, 20 ),
					'integration_boundary' => self::array_path( $knowledge, 'facts.integrations.integration_boundary', [] ),
				],
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	private static function policy_context( array $store_context ) {
		$settings = is_array( $store_context['settings'] ?? null ) ? $store_context['settings'] : [];

		return [
			'configured' => [
				'terms_page_configured' => (bool) self::array_path( $settings, 'checkout.terms_page_configured', false ),
				'privacy_checkout_text_configured' => (bool) self::array_path( $settings, 'checkout.privacy.checkout_privacy_text_configured', false ),
				'email_footer_text_configured' => (bool) self::array_path( $settings, 'email.footer_text_configured', false ),
			],
			'unknown_policy_areas' => [
				'refund or return window',
				'cancellation policy',
				'shipping promise or estimated delivery window',
				'warranty or product-care policy',
			],
			'notes' => [
				'WooCommerce settings can confirm configured pages and email settings, but do not provide policy wording unless a dedicated policy source is integrated.',
			],
		];
	}

	private static function compact_assoc( $values, $limit = 12 ) {
		$output = [];

		foreach ( (array) $values as $key => $value ) {
			if ( count( $output ) >= $limit ) {
				break;
			}

			if ( ! is_scalar( $key ) ) {
				continue;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$output[ $key ] = array_slice( $value, 0, 20 );
				continue;
			}

			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$output[ $key ] = $value;
				continue;
			}

			if ( is_scalar( $value ) ) {
				$output[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		return array_filter( $output, static fn( $value ) => null !== $value && '' !== $value && [] !== $value );
	}

	private static function array_path( $array, $path, $default = null ) {
		$value = $array;

		foreach ( explode( '.', (string) $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default;
			}

			$value = $value[ $segment ];
		}

		return $value;
	}

	private static function communication_constraints( $status, $status_label, $requires_shipping, $tracking_available, $shipping_address_complete, $shipping_method_available, $ready_to_fulfill, $fulfillment_state ) {
		$supported = [
			[ 'key' => 'order_status', 'value' => $status_label ],
		];
		$unsupported = [];
		$unknowns = [];

		if ( true === $requires_shipping ) {
			$supported[] = [ 'key' => 'requires_shipping', 'value' => true ];
		} elseif ( false === $requires_shipping ) {
			$supported[] = [ 'key' => 'requires_shipping', 'value' => false ];
			$unsupported[] = 'physical shipment, carrier handoff, tracking, or delivery progress';
		} else {
			$unknowns[] = 'whether the order requires shipping';
		}

		if ( true === $tracking_available ) {
			$supported[] = [ 'key' => 'tracking_available', 'value' => true ];
		} elseif ( false === $tracking_available ) {
			$supported[] = [ 'key' => 'tracking_available', 'value' => false ];
			$unsupported[] = 'tracking number is available';
			$unsupported[] = 'package has shipped or is on the way';
			$unsupported[] = 'carrier has received the package';
			$unknowns[] = 'tracking number';
			$unknowns[] = 'carrier';
		} else {
			$unknowns[] = 'tracking availability';
		}

		if ( true === $shipping_address_complete ) {
			$supported[] = [ 'key' => 'shipping_address_complete', 'value' => true ];
		} elseif ( false === $shipping_address_complete ) {
			$supported[] = [ 'key' => 'shipping_address_complete', 'value' => false ];
			$unsupported[] = 'shipping can proceed without address review';
		} else {
			$unknowns[] = 'shipping address completeness';
		}

		if ( true === $shipping_method_available ) {
			$supported[] = [ 'key' => 'shipping_method_available', 'value' => true ];
		} elseif ( true === $requires_shipping ) {
			$supported[] = [ 'key' => 'shipping_method_available', 'value' => false ];
			$unknowns[] = 'shipping method';
		}

		if ( true === $ready_to_fulfill ) {
			$supported[] = [ 'key' => 'ready_to_fulfill', 'value' => true ];
		} elseif ( false === $ready_to_fulfill ) {
			$supported[] = [ 'key' => 'ready_to_fulfill', 'value' => false ];
			$unsupported[] = 'order is ready for fulfillment without further review';
		}

		if ( ! in_array( $status, [ 'completed', 'processing' ], true ) ) {
			$unsupported[] = 'order is completed';
		}

		$unknowns[] = 'estimated ship date';
		$unknowns[] = 'delivery date';
		$unknowns[] = 'reason for delay unless provided by the user or order facts';

		return [
			'basis'              => 'Use only provided order facts and the user instruction. Do not invent missing operational details.',
			'fulfillment_state'  => $fulfillment_state,
			'supported_claims'   => self::unique_values( $supported ),
			'unsupported_claims' => self::unique_values( $unsupported ),
			'unknowns'           => self::unique_values( $unknowns ),
		];
	}

	private static function fulfillment_state( $status, $requires_shipping, $tracking_available, $shipping_address_complete, $shipping_method_available, $ready_to_fulfill ) {
		if ( false === $requires_shipping ) {
			return 'no_shipping_required';
		}

		if ( false === $shipping_address_complete ) {
			return 'address_incomplete';
		}

		if ( true === $tracking_available ) {
			return 'tracking_available';
		}

		if ( true === $requires_shipping && false === $shipping_method_available ) {
			return 'shipping_method_missing';
		}

		if ( in_array( $status, [ 'pending', 'on-hold', 'failed', 'cancelled', 'refunded' ], true ) ) {
			return 'not_ready_to_fulfill';
		}

		if ( true === $requires_shipping && false === $tracking_available ) {
			return 'pre_shipment_no_tracking';
		}

		if ( true === $ready_to_fulfill ) {
			return 'ready_to_fulfill';
		}

		return 'unknown';
	}

	private static function unique_values( $values ) {
		$seen = [];
		$unique = [];

		foreach ( (array) $values as $value ) {
			$key = is_array( $value ) ? wp_json_encode( $value ) : (string) $value;

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[] = $value;
		}

		return $unique;
	}

	private static function bool_or_null( $value ) {
		if ( null === $value ) {
			return null;
		}

		return (bool) $value;
	}

	private static function order_requires_shipping( WC_Order $order ) {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product instanceof WC_Product || ! $product->is_virtual() ) {
				return true;
			}
		}

		return false;
	}

	private static function labelize_status( $status ) {
		return ucwords( str_replace( [ 'wc-', '-', '_' ], [ '', ' ', ' ' ], (string) $status ) );
	}
}
