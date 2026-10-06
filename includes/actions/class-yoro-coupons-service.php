<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Coupons_Service {

	public static function lookup_coupon( $params ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params = is_array( $params ) ? $params : [];
		$target = self::resolve_coupon_target( $params );

		if ( empty( $target['coupon_id'] ) ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'I could not find that coupon.', 'yoro-ai-core' )
			);
		}

		$coupon = self::get_coupon( $target['coupon_id'] );

		if ( ! $coupon ) {
			return new WP_Error(
				'coupon_not_found',
				__( 'I could not find that coupon.', 'yoro-ai-core' )
			);
		}

		$item = self::coupon_result_item( $coupon );
		$summary = sprintf(
			/* translators: 1: coupon code, 2: discount summary */
			__( 'Found coupon %1$s: %2$s.', 'yoro-ai-core' ),
			$item['coupon_code'],
			$item['discount_summary']
		);

		return [
			'success'      => true,
			'action'       => 'coupons.lookup',
			'summary'      => $summary,
			'message'      => $summary,
			'coupon_id'    => $item['coupon_id'],
			'coupon_code'  => $item['coupon_code'],
			'coupon'       => $item,
			'coupons'      => [ $item ],
			'data'         => [
				'coupon' => $item,
			],
			'processed'    => [ $item['coupon_id'] ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'coupon',
			'display_type' => 'coupon',
			'meta'         => [
				'coupon_id'       => $item['coupon_id'],
				'coupon_code'     => $item['coupon_code'],
				'coupons_enabled' => self::coupons_enabled(),
			],
		];
	}

	public static function create_coupon( $params ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return new WP_Error( 'woocommerce_missing', __( 'Store integration is not active.', 'yoro-ai-core' ) );
		}

		$params = is_array( $params ) ? $params : [];
		$contract = self::normalize_coupon_create_params( $params );
		$error = self::validate_coupon_create_contract( $contract );

		if ( is_wp_error( $error ) ) {
			return $error;
		}

		if ( ! self::coupons_enabled() ) {
			return new WP_Error(
				'coupons_disabled',
				__( 'Coupons are disabled for the store. Enable WooCommerce coupons before creating a coupon.', 'yoro-ai-core' )
			);
		}

		$existing_id = self::coupon_id_by_code( $contract['coupon_code'] );

		if ( $existing_id > 0 ) {
			return new WP_Error(
				'coupon_already_exists',
				sprintf(
					/* translators: %s: coupon code */
					__( 'Coupon %s already exists.', 'yoro-ai-core' ),
					$contract['coupon_code']
				)
			);
		}

		try {
			$coupon = new WC_Coupon();
			$coupon->set_code( $contract['coupon_code'] );
			$coupon->set_discount_type( $contract['discount_type'] );
			$coupon->set_amount( $contract['discount_amount'] );

			if ( '' !== $contract['description'] && method_exists( $coupon, 'set_description' ) ) {
				$coupon->set_description( $contract['description'] );
			}

			if ( null !== $contract['date_expires'] && method_exists( $coupon, 'set_date_expires' ) ) {
				$coupon->set_date_expires( $contract['date_expires'] );
			}

			if ( null !== $contract['usage_limit'] && method_exists( $coupon, 'set_usage_limit' ) ) {
				$coupon->set_usage_limit( $contract['usage_limit'] );
			}

			if ( null !== $contract['usage_limit_per_user'] && method_exists( $coupon, 'set_usage_limit_per_user' ) ) {
				$coupon->set_usage_limit_per_user( $contract['usage_limit_per_user'] );
			}

			if ( method_exists( $coupon, 'set_individual_use' ) ) {
				$coupon->set_individual_use( $contract['individual_use'] );
			}

			if ( method_exists( $coupon, 'set_free_shipping' ) ) {
				$coupon->set_free_shipping( $contract['free_shipping'] );
			}

			$coupon_id = $coupon->save();
		} catch ( Exception $exception ) {
			return new WP_Error( 'coupon_create_failed', $exception->getMessage() );
		}

		$coupon = self::get_coupon( $coupon_id );

		if ( ! $coupon ) {
			return new WP_Error(
				'coupon_create_failed',
				__( 'The coupon was saved, but I could not read it back from WooCommerce.', 'yoro-ai-core' )
			);
		}

		$item = self::coupon_result_item( $coupon );
		$summary = sprintf(
			/* translators: 1: coupon code, 2: discount summary */
			__( 'Created coupon %1$s for %2$s.', 'yoro-ai-core' ),
			$item['coupon_code'],
			$item['discount_summary']
		);

		return [
			'success'      => true,
			'action'       => 'coupons.create',
			'summary'      => $summary,
			'message'      => $summary,
			'coupon_id'    => $item['coupon_id'],
			'coupon_code'  => $item['coupon_code'],
			'coupon'       => $item,
			'coupons'      => [ $item ],
			'data'         => [
				'coupon' => $item,
			],
			'processed'    => [ $item['coupon_id'] ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'result_type'  => 'coupon',
			'display_type' => 'coupon',
			'meta'         => [
				'changes_store'     => true,
				'coupon_id'         => $item['coupon_id'],
				'coupon_code'       => $item['coupon_code'],
				'discount_type'     => $item['discount_type'],
				'discount_amount'   => $item['discount_amount'],
				'coupons_enabled'   => self::coupons_enabled(),
			],
		];
	}

	public static function preview_lookup_coupon( $params ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return self::preview_block(
				'woocommerce_missing',
				__( 'Store integration is not active.', 'yoro-ai-core' ),
				'read'
			);
		}

		$params = is_array( $params ) ? $params : [];
		$target = self::resolve_coupon_target( $params );

		if ( empty( $target['coupon_id'] ) ) {
			return self::preview_block(
				'coupon_not_found',
				__( 'I could not find that coupon.', 'yoro-ai-core' ),
				'read'
			);
		}

		$coupon = self::get_coupon( $target['coupon_id'] );

		if ( ! $coupon ) {
			return self::preview_block(
				'coupon_not_found',
				__( 'I could not find that coupon.', 'yoro-ai-core' ),
				'read'
			);
		}

		$item = self::coupon_result_item( $coupon );
		$message = sprintf(
			/* translators: 1: coupon code, 2: discount summary */
			__( 'This will look up coupon %1$s: %2$s.', 'yoro-ai-core' ),
			$item['coupon_code'],
			$item['discount_summary']
		);

		return [
			'type'          => 'read',
			'will_execute'  => true,
			'action'        => __( 'Look up coupon', 'yoro-ai-core' ),
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => false,
			'block_reason'  => '',
			'coupon'        => $item,
			'coupons'       => [ $item ],
			'count'         => 1,
			'changes_store' => false,
		];
	}

	public static function preview_create_coupon( $params ) {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			return self::preview_block(
				'woocommerce_missing',
				__( 'Store integration is not active.', 'yoro-ai-core' ),
				'write'
			);
		}

		$params = is_array( $params ) ? $params : [];
		$contract = self::normalize_coupon_create_params( $params );
		$error = self::validate_coupon_create_contract( $contract );
		$block_reason = '';
		$message = '';

		if ( is_wp_error( $error ) ) {
			$block_reason = $error->get_error_code();
			$message = $error->get_error_message();
		} elseif ( ! self::coupons_enabled() ) {
			$block_reason = 'coupons_disabled';
			$message = __( 'Coupons are disabled for the store. Enable WooCommerce coupons before creating a coupon.', 'yoro-ai-core' );
		} elseif ( self::coupon_id_by_code( $contract['coupon_code'] ) > 0 ) {
			$block_reason = 'coupon_already_exists';
			$message = sprintf(
				/* translators: %s: coupon code */
				__( 'Coupon %s already exists.', 'yoro-ai-core' ),
				$contract['coupon_code']
			);
		} else {
			$message = sprintf(
				/* translators: 1: coupon code, 2: discount summary */
				__( 'This will create coupon %1$s for %2$s after approval.', 'yoro-ai-core' ),
				$contract['coupon_code'],
				self::format_discount_summary( $contract['discount_type'], $contract['discount_amount'] )
			);
		}

		return [
			'type'            => 'write',
			'will_execute'    => false,
			'action'          => __( 'Create coupon', 'yoro-ai-core' ),
			'summary'         => $message,
			'message'         => $message,
			'blocked'         => '' !== $block_reason,
			'block_reason'    => $block_reason,
			'coupon'          => [
				'coupon_id'       => null,
				'coupon_code'     => $contract['coupon_code'],
				'discount_type'   => $contract['discount_type'],
				'discount_amount' => $contract['discount_amount'],
				'discount_summary' => '' !== $contract['discount_type'] && $contract['discount_amount'] > 0
					? self::format_discount_summary( $contract['discount_type'], $contract['discount_amount'] )
					: '',
				'description'     => $contract['description'],
				'date_expires'    => $contract['date_expires_label'],
			],
			'count'           => '' === $block_reason ? 1 : 0,
			'changes_store'   => '' === $block_reason,
			'coupons_enabled' => self::coupons_enabled(),
		];
	}

	private static function resolve_coupon_target( $params ) {
		$params = is_array( $params ) ? $params : [];
		$coupon_id = absint( $params['coupon_id'] ?? 0 );
		$coupon_code = self::normalize_coupon_code( $params['coupon_code'] ?? ( $params['code'] ?? '' ) );

		if ( $coupon_id <= 0 && '' !== $coupon_code ) {
			$coupon_id = self::coupon_id_by_code( $coupon_code );
		}

		if ( $coupon_id > 0 && '' === $coupon_code ) {
			$coupon = self::get_coupon( $coupon_id );
			$coupon_code = $coupon && method_exists( $coupon, 'get_code' )
				? self::normalize_coupon_code( $coupon->get_code() )
				: '';
		}

		return [
			'coupon_id'    => $coupon_id,
			'coupon_code'  => $coupon_code,
			'target_type'  => $coupon_id > 0 || '' !== $coupon_code ? 'coupon' : '',
			'target_value' => '' !== $coupon_code ? $coupon_code : ( $coupon_id > 0 ? (string) $coupon_id : '' ),
		];
	}

	private static function normalize_coupon_create_params( $params ) {
		$params = is_array( $params ) ? $params : [];
		$discount_type = sanitize_key( $params['discount_type'] ?? '' );
		$discount_amount = isset( $params['discount_amount'] ) && is_numeric( $params['discount_amount'] )
			? round( (float) $params['discount_amount'], 4 )
			: 0.0;
		$date_expires = self::normalize_expiry_date( $params['expiry_date'] ?? ( $params['date_expires'] ?? '' ) );

		return [
			'coupon_code'          => self::normalize_coupon_code( $params['coupon_code'] ?? ( $params['code'] ?? '' ) ),
			'discount_type'        => $discount_type,
			'discount_amount'      => $discount_amount,
			'description'          => sanitize_textarea_field( $params['description'] ?? '' ),
			'date_expires'         => $date_expires['value'],
			'date_expires_label'   => $date_expires['label'],
			'usage_limit'          => self::nullable_positive_int( $params['usage_limit'] ?? null ),
			'usage_limit_per_user' => self::nullable_positive_int( $params['usage_limit_per_user'] ?? null ),
			'individual_use'       => self::normalize_boolean( $params['individual_use'] ?? false ),
			'free_shipping'        => self::normalize_boolean( $params['free_shipping'] ?? false ),
		];
	}

	private static function validate_coupon_create_contract( $contract ) {
		$contract = is_array( $contract ) ? $contract : [];
		$code = (string) ( $contract['coupon_code'] ?? '' );
		$discount_type = sanitize_key( $contract['discount_type'] ?? '' );
		$discount_amount = isset( $contract['discount_amount'] ) ? (float) $contract['discount_amount'] : 0.0;

		if ( '' === $code ) {
			return new WP_Error(
				'invalid_coupon_code',
				__( 'Please choose a coupon code to create.', 'yoro-ai-core' )
			);
		}

		if ( ! in_array( $discount_type, self::discount_types(), true ) ) {
			return new WP_Error(
				'invalid_discount_type',
				__( 'Please choose a valid discount type: percent, fixed cart, or fixed product.', 'yoro-ai-core' )
			);
		}

		if ( $discount_amount <= 0 ) {
			return new WP_Error(
				'invalid_discount_amount',
				__( 'Please choose a discount amount greater than zero.', 'yoro-ai-core' )
			);
		}

		if ( 'percent' === $discount_type && $discount_amount > 100 ) {
			return new WP_Error(
				'invalid_discount_amount',
				__( 'Percentage coupons cannot be greater than 100%.', 'yoro-ai-core' )
			);
		}

		return true;
	}

	private static function get_coupon( $coupon_id ) {
		$coupon_id = absint( $coupon_id );

		if ( $coupon_id <= 0 || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}

		try {
			$coupon = new WC_Coupon( $coupon_id );
		} catch ( Exception $exception ) {
			return null;
		}

		return $coupon && method_exists( $coupon, 'get_id' ) && absint( $coupon->get_id() ) > 0
			? $coupon
			: null;
	}

	private static function coupon_result_item( WC_Coupon $coupon ) {
		$coupon_id = method_exists( $coupon, 'get_id' ) ? absint( $coupon->get_id() ) : 0;
		$code = method_exists( $coupon, 'get_code' ) ? self::normalize_coupon_code( $coupon->get_code() ) : '';
		$discount_type = method_exists( $coupon, 'get_discount_type' ) ? sanitize_key( $coupon->get_discount_type() ) : '';
		$amount = method_exists( $coupon, 'get_amount' ) ? (float) $coupon->get_amount() : 0.0;
		$date_expires = method_exists( $coupon, 'get_date_expires' ) ? $coupon->get_date_expires() : null;

		return [
			'coupon_id'               => $coupon_id,
			'coupon_code'             => $code,
			'edit_url'                => $coupon_id > 0 ? admin_url( 'post.php?post=' . $coupon_id . '&action=edit' ) : '',
			'discount_type'           => $discount_type,
			'discount_type_label'     => self::discount_type_label( $discount_type ),
			'discount_amount'         => $amount,
			'discount_summary'        => self::format_discount_summary( $discount_type, $amount ),
			'description'             => method_exists( $coupon, 'get_description' ) ? sanitize_textarea_field( $coupon->get_description() ) : '',
			'usage_count'             => method_exists( $coupon, 'get_usage_count' ) ? absint( $coupon->get_usage_count() ) : 0,
			'usage_limit'             => method_exists( $coupon, 'get_usage_limit' ) ? self::nullable_absint( $coupon->get_usage_limit() ) : null,
			'usage_limit_per_user'    => method_exists( $coupon, 'get_usage_limit_per_user' ) ? self::nullable_absint( $coupon->get_usage_limit_per_user() ) : null,
			'individual_use'          => method_exists( $coupon, 'get_individual_use' ) ? (bool) $coupon->get_individual_use() : false,
			'free_shipping'           => method_exists( $coupon, 'get_free_shipping' ) ? (bool) $coupon->get_free_shipping() : false,
			'minimum_amount'          => method_exists( $coupon, 'get_minimum_amount' ) ? sanitize_text_field( $coupon->get_minimum_amount() ) : '',
			'maximum_amount'          => method_exists( $coupon, 'get_maximum_amount' ) ? sanitize_text_field( $coupon->get_maximum_amount() ) : '',
			'date_expires'            => self::format_date( $date_expires ),
			'coupons_enabled'         => self::coupons_enabled(),
		];
	}

	private static function coupon_id_by_code( $code ) {
		$code = self::normalize_coupon_code( $code );

		if ( '' === $code || ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
			return 0;
		}

		return absint( wc_get_coupon_id_by_code( $code ) );
	}

	private static function normalize_coupon_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		$code = trim( $code );

		return '' !== $code && function_exists( 'wc_format_coupon_code' )
			? wc_format_coupon_code( $code )
			: $code;
	}

	private static function discount_types() {
		return [ 'percent', 'fixed_cart', 'fixed_product' ];
	}

	private static function discount_type_label( $discount_type ) {
		$discount_type = sanitize_key( $discount_type );

		$labels = [
			'percent'       => __( 'Percentage discount', 'yoro-ai-core' ),
			'fixed_cart'    => __( 'Fixed cart discount', 'yoro-ai-core' ),
			'fixed_product' => __( 'Fixed product discount', 'yoro-ai-core' ),
		];

		return $labels[ $discount_type ] ?? self::humanize_key( $discount_type );
	}

	private static function format_discount_summary( $discount_type, $amount ) {
		$discount_type = sanitize_key( $discount_type );
		$amount = (float) $amount;

		if ( 'percent' === $discount_type ) {
			return sprintf(
				/* translators: %s: discount percent */
				__( '%s%% off', 'yoro-ai-core' ),
				wc_format_decimal( $amount, 2 )
			);
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		$formatted = function_exists( 'wc_format_decimal' )
			? wc_format_decimal( $amount, 2 )
			: number_format_i18n( $amount, 2 );

		if ( 'fixed_cart' === $discount_type ) {
			return sprintf(
				/* translators: 1: amount, 2: currency */
				__( '%1$s %2$s off cart', 'yoro-ai-core' ),
				$formatted,
				$currency
			);
		}

		if ( 'fixed_product' === $discount_type ) {
			return sprintf(
				/* translators: 1: amount, 2: currency */
				__( '%1$s %2$s off product', 'yoro-ai-core' ),
				$formatted,
				$currency
			);
		}

		return self::humanize_key( $discount_type );
	}

	private static function normalize_expiry_date( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return [
				'value' => null,
				'label' => '',
			];
		}

		try {
			if ( function_exists( 'wc_string_to_datetime' ) ) {
				$date = wc_string_to_datetime( $value );
			} else {
				$timestamp = strtotime( $value );
				$date = false !== $timestamp ? new WC_DateTime( '@' . $timestamp ) : null;
			}
		} catch ( Exception $exception ) {
			$date = null;
		}

		return [
			'value' => $date,
			'label' => $date ? self::format_date( $date ) : '',
		];
	}

	private static function format_date( $date ) {
		if ( ! $date ) {
			return '';
		}

		if ( is_object( $date ) && method_exists( $date, 'date_i18n' ) ) {
			return sanitize_text_field( $date->date_i18n( 'Y-m-d' ) );
		}

		if ( is_object( $date ) && method_exists( $date, 'format' ) ) {
			return sanitize_text_field( $date->format( 'Y-m-d' ) );
		}

		return sanitize_text_field( (string) $date );
	}

	private static function nullable_positive_int( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$value = absint( $value );

		return $value > 0 ? $value : null;
	}

	private static function nullable_absint( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$value = absint( $value );

		return $value > 0 ? $value : null;
	}

	private static function normalize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		return in_array( sanitize_key( (string) $value ), [ '1', 'true', 'yes', 'on' ], true );
	}

	private static function coupons_enabled() {
		if ( function_exists( 'wc_coupons_enabled' ) ) {
			return (bool) wc_coupons_enabled();
		}

		return 'yes' === get_option( 'woocommerce_enable_coupons', 'yes' );
	}

	private static function humanize_key( $value ) {
		$value = sanitize_key( $value );

		return '' === $value ? '' : ucwords( str_replace( '_', ' ', $value ) );
	}

	private static function preview_block( $reason, $message, $type = 'write' ) {
		return [
			'type'          => sanitize_key( $type ),
			'will_execute'  => false,
			'action'        => 'write' === $type ? __( 'Create coupon', 'yoro-ai-core' ) : __( 'Look up coupon', 'yoro-ai-core' ),
			'summary'       => $message,
			'message'       => $message,
			'blocked'       => true,
			'block_reason'  => sanitize_key( $reason ),
			'coupon'        => [],
			'coupons'       => [],
			'count'         => 0,
			'changes_store' => false,
		];
	}
}
