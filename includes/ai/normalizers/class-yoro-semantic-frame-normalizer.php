<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Semantic_Frame_Normalizer {

	public static function schema() {
		return [
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => [
				'version' => [ 'type' => [ 'integer', 'null' ] ],
				'surface' => [ 'type' => 'string' ],
				'operation' => [ 'type' => 'string' ],
				'target_type' => [ 'type' => 'string' ],
				'constraints' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
							'quantity' => [ 'type' => [ 'integer', 'null' ] ],
							'order_id' => [ 'type' => [ 'integer', 'null' ] ],
							'order_ids' => [
								'type'  => [ 'array', 'null' ],
								'items' => [ 'type' => 'integer' ],
							],
						'status' => [ 'type' => [ 'string', 'null' ] ],
						'date' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'window'   => [ 'type' => [ 'string', 'null' ] ],
								'operator' => [ 'type' => [ 'string', 'null' ] ],
								'value'    => [ 'type' => [ 'integer', 'null' ] ],
								'unit'     => [ 'type' => [ 'string', 'null' ] ],
								'text'     => [ 'type' => [ 'string', 'null' ] ],
							],
							'required'             => [ 'window', 'operator', 'value', 'unit', 'text' ],
						],
						'money' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'operator' => [ 'type' => [ 'string', 'null' ] ],
								'value'    => [ 'type' => [ 'number', 'null' ] ],
								'min'      => [ 'type' => [ 'number', 'null' ] ],
								'max'      => [ 'type' => [ 'number', 'null' ] ],
								'currency' => [ 'type' => [ 'string', 'null' ] ],
								'text'     => [ 'type' => [ 'string', 'null' ] ],
							],
							'required'             => [ 'operator', 'value', 'min', 'max', 'currency', 'text' ],
						],
						'customer_identity' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'type'  => [ 'type' => [ 'string', 'null' ] ],
								'value' => [ 'type' => [ 'string', 'null' ] ],
							],
							'required'             => [ 'type', 'value' ],
						],
						'payment_method' => [ 'type' => [ 'string', 'null' ] ],
						'shipping_state' => [ 'type' => [ 'string', 'null' ] ],
					],
						'required'             => [ 'quantity', 'order_id', 'order_ids', 'status', 'date', 'money', 'customer_identity', 'payment_method', 'shipping_state' ],
				],
				'evidence' => [
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => [
						'surface'    => [ 'type' => [ 'string', 'null' ] ],
						'operation'  => [ 'type' => [ 'string', 'null' ] ],
						'target'     => [ 'type' => [ 'string', 'null' ] ],
							'order_id'   => [ 'type' => [ 'string', 'null' ] ],
							'quantity'   => [ 'type' => [ 'string', 'null' ] ],
						'status'     => [ 'type' => [ 'string', 'null' ] ],
						'date'       => [ 'type' => [ 'string', 'null' ] ],
						'money'      => [ 'type' => [ 'string', 'null' ] ],
						'customer'   => [ 'type' => [ 'string', 'null' ] ],
						'payment'    => [ 'type' => [ 'string', 'null' ] ],
						'shipping'   => [ 'type' => [ 'string', 'null' ] ],
					],
						'required'             => [ 'surface', 'operation', 'target', 'order_id', 'quantity', 'status', 'date', 'money', 'customer', 'payment', 'shipping' ],
				],
				'confidence' => [ 'type' => 'number' ],
			],
			'required'             => [ 'version', 'surface', 'operation', 'target_type', 'constraints', 'evidence', 'confidence' ],
		];
	}

	public static function normalize( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];

		return [
			'version'      => 1,
			'surface'      => self::normalize_key( $frame['surface'] ?? 'unknown', 'unknown' ),
			'operation'    => self::normalize_key( $frame['operation'] ?? 'unknown', 'unknown' ),
			'target_type'  => self::normalize_key( $frame['target_type'] ?? 'unknown', 'unknown' ),
			'constraints'  => self::normalize_constraints( $frame['constraints'] ?? [] ),
			'evidence'     => self::normalize_evidence( $frame['evidence'] ?? [] ),
			'confidence'   => self::normalize_confidence( $frame['confidence'] ?? 0 ),
		];
	}

	public static function is_informative( $frame ) {
		$frame = is_array( $frame ) ? $frame : [];

		return ! empty( $frame ) && (
			'unknown' !== ( $frame['surface'] ?? 'unknown' ) ||
			'unknown' !== ( $frame['operation'] ?? 'unknown' ) ||
			'unknown' !== ( $frame['target_type'] ?? 'unknown' ) ||
			self::constraints_have_values( $frame['constraints'] ?? [] )
		);
	}

	private static function normalize_constraints( $constraints ) {
		$constraints = is_array( $constraints ) ? $constraints : [];

		return [
			'quantity'          => isset( $constraints['quantity'] ) && null !== $constraints['quantity'] ? max( 0, absint( $constraints['quantity'] ) ) : null,
			'order_id'          => isset( $constraints['order_id'] ) && null !== $constraints['order_id'] ? absint( $constraints['order_id'] ) : null,
			'order_ids'         => self::normalize_order_ids( $constraints['order_ids'] ?? [] ),
			'status'            => self::normalize_status( $constraints['status'] ?? '' ),
			'date'              => self::normalize_date_constraint( $constraints['date'] ?? [] ),
			'money'             => self::normalize_money_constraint( $constraints['money'] ?? [] ),
			'customer_identity' => self::normalize_identity_constraint( $constraints['customer_identity'] ?? [] ),
			'payment_method'    => self::normalize_nullable_key( $constraints['payment_method'] ?? null ),
			'shipping_state'    => self::normalize_nullable_text( $constraints['shipping_state'] ?? null ),
		];
	}

	private static function normalize_date_constraint( $date ) {
		$date = is_array( $date ) ? $date : [];

		return [
			'window'   => self::normalize_nullable_key( $date['window'] ?? null ),
			'operator' => self::normalize_nullable_key( $date['operator'] ?? null ),
			'value'    => isset( $date['value'] ) && null !== $date['value'] ? absint( $date['value'] ) : null,
			'unit'     => self::normalize_nullable_key( $date['unit'] ?? null ),
			'text'     => self::normalize_nullable_text( $date['text'] ?? null ),
		];
	}

	private static function normalize_money_constraint( $money ) {
		$money = is_array( $money ) ? $money : [];

		return [
			'operator' => self::normalize_nullable_key( $money['operator'] ?? null ),
			'value'    => isset( $money['value'] ) && null !== $money['value'] ? (float) $money['value'] : null,
			'min'      => isset( $money['min'] ) && null !== $money['min'] ? (float) $money['min'] : null,
			'max'      => isset( $money['max'] ) && null !== $money['max'] ? (float) $money['max'] : null,
			'currency' => self::normalize_nullable_text( $money['currency'] ?? null ),
			'text'     => self::normalize_nullable_text( $money['text'] ?? null ),
		];
	}

	private static function normalize_identity_constraint( $identity ) {
		$identity = is_array( $identity ) ? $identity : [];

		return [
			'type'  => self::normalize_nullable_key( $identity['type'] ?? null ),
			'value' => self::normalize_nullable_text( $identity['value'] ?? null ),
		];
	}

	private static function normalize_order_ids( $order_ids ) {
		$order_ids = is_array( $order_ids ) ? $order_ids : [];

		return array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);
	}

	private static function normalize_evidence( $evidence ) {
		$evidence = is_array( $evidence ) ? $evidence : [];
		$keys     = [ 'surface', 'operation', 'target', 'order_id', 'quantity', 'status', 'date', 'money', 'customer', 'payment', 'shipping' ];
		$out      = [];

		foreach ( $keys as $key ) {
			$out[ $key ] = self::normalize_nullable_text( $evidence[ $key ] ?? null );
		}

		return $out;
	}

	private static function normalize_status( $status ) {
		$status = self::normalize_nullable_key( $status );

		if ( null === $status ) {
			return null;
		}

		if ( class_exists( 'YoRo_Order_Helper' ) && YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return $status;
		}

		return $status;
	}

	private static function normalize_nullable_key( $value ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		return sanitize_key( (string) $value );
	}

	private static function normalize_key( $value, $fallback ) {
		$value = sanitize_key( (string) $value );

		return '' !== $value ? $value : $fallback;
	}

	private static function normalize_nullable_text( $value ) {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		return sanitize_text_field( (string) $value );
	}

	private static function normalize_confidence( $confidence ) {
		$confidence = (float) $confidence;

		if ( $confidence < 0 ) {
			return 0.0;
		}

		if ( $confidence > 1 ) {
			return 1.0;
		}

		return $confidence;
	}

	private static function constraints_have_values( $constraints ) {
		$constraints = is_array( $constraints ) ? $constraints : [];

		if (
			null !== ( $constraints['quantity'] ?? null ) ||
			null !== ( $constraints['order_id'] ?? null ) ||
			! empty( $constraints['order_ids'] ) ||
			null !== ( $constraints['status'] ?? null )
		) {
			return true;
		}

		foreach ( [ 'date', 'money', 'customer_identity' ] as $key ) {
			if ( ! empty( $constraints[ $key ] ) && is_array( $constraints[ $key ] ) ) {
				foreach ( $constraints[ $key ] as $value ) {
					if ( null !== $value && '' !== $value ) {
						return true;
					}
				}
			}
		}

		return null !== ( $constraints['payment_method'] ?? null ) || null !== ( $constraints['shipping_state'] ?? null );
	}
}
