<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Response_UX_Formatter {

	public static function clean_user_facing_copy( $text, $fallback = '' ) {
		$text     = sanitize_text_field( (string) $text );
		$fallback = sanitize_text_field( (string) $fallback );

		if ( '' === $text ) {
			return $fallback;
		}

		$patterns = [
			'/^You query indicates\b[:\s-]*/i' => 'I understood this as ',
			'/^Your query indicates\b[:\s-]*/i' => 'I understood this as ',
			'/^You asked for\b[:\s-]*/i'      => 'You asked for ',
			'/^User asked\b[:\s-]*/i'         => 'You asked ',
			'/^The user asked\b[:\s-]*/i'     => 'You asked ',
			'/^User asks\b[:\s-]*/i'          => 'You asked ',
			'/^The user asks\b[:\s-]*/i'      => 'You asked ',
			'/^User wants\b[:\s-]*/i'         => 'You want ',
			'/^The user wants\b[:\s-]*/i'     => 'You want ',
			'/^User requested\b[:\s-]*/i'     => 'You asked me to ',
			'/^The user requested\b[:\s-]*/i' => 'You asked me to ',
			'/^User requests\b[:\s-]*/i'      => 'You asked me to ',
			'/^The user requests\b[:\s-]*/i'  => 'You asked me to ',
			'/^User is asking\b[:\s-]*/i'     => 'You asked ',
			'/^The user is asking\b[:\s-]*/i' => 'You asked ',
			'/\bthe user\b/i'                 => 'you',
			'/\bUser\b/'                      => 'You',
			'/\bDecision route:\s*[a-z0-9_.-]+\.?/i' => '',
			'/\bparser_rule:\s*[a-z0-9_.-]+\.?/i' => '',
			'/^Command cannot be empty\.?$/i'      => 'Please enter a request first.',
			'/^No action could be resolved from intent\.?$/i' => 'I could not find a safe action for that request.',
			'/^Invalid runtime plan\.?$/i'         => 'I could not prepare this action safely.',
			'/^(The AI service|YoRo AI API) is not configured yet\.?$/i' => 'YoRo AI API is not connected yet.',
			'/^Action\s+"[^"]+"\s+requires confirmation\.?$/i' => 'This will update your store, so I need your approval first.',
			'/^Action\s+"[^"]+"\s+changes store data\.?$/i' => 'This will update your store.',
			'/^Action\s+"[^"]+"\s+affects\s+([0-9]+)\s+items\.?$/i' => 'This touches $1 items.',
			'/^Action\s+"[^"]+"\s+changes financial information\.?$/i' => 'This changes financial information.',
			'/^Action\s+"[^"]+"\s+reads financial information\.?$/i' => 'This includes financial information.',
			'/^Action\s+"[^"]+"\s+may remove or delete data\.?$/i' => 'This may remove store information.',
			'/^Action\s+"[^"]+"\s+communicates with a customer\.?$/i' => 'This contacts a customer.',
			'/^Policy requires preview for action\s+"[^"]+"\.?$/i' => 'I prepared this for review before anything changes.',
			'/^Policy requires confirmation for action\s+"[^"]+"\.?$/i' => 'I need your approval before I update your store.',
		];

		foreach ( $patterns as $pattern => $replacement ) {
			$text = preg_replace( $pattern, $replacement, $text );
		}

		$text = trim( preg_replace( '/\s+/', ' ', $text ) );
		$text = trim( $text, " \t\n\r\0\x0B|" );

		return '' !== $text ? sanitize_text_field( $text ) : $fallback;
	}

	public static function order_search_summary( $mode, $count, $params = [], $resolved_window = [], $source = [], $locale = 'en' ) {
		$params = is_array( $params ) ? $params : [];
		$count  = absint( $count );
		$locale = self::normalize_response_locale( $locale ?: 'en' );

		$status = self::order_status_label( $params, $locale );
		$subject = '' !== $status
			? trim( $status . ' orders' )
			: 'orders';
		$filters = self::order_search_filter_phrases( $params, $resolved_window, $source, $locale );
		$filter_text = ! empty( $filters ) ? ' ' . implode( ' ', $filters ) : '';

		if ( 'vi' === $locale ) {
			$status_text = '' !== $status ? ' ' . $status : '';

			return 'showing' === sanitize_key( $mode )
				? sprintf( 'Hiển thị %1$d đơn%2$s%3$s.', $count, $status_text, $filter_text )
				: sprintf( 'Tìm thấy %1$d đơn%2$s%3$s.', $count, $status_text, $filter_text );
		}

		$localized_summary = self::localized_order_search_summary(
			$mode,
			$count,
			$status,
			$filter_text,
			$locale
		);

		if ( '' !== $localized_summary ) {
			return $localized_summary;
		}

		if ( 0 === $count ) {
			return '' !== $status
				? sprintf( 'I couldn\'t find any %1$s orders%2$s.', $status, $filter_text )
				: sprintf( 'I couldn\'t find any orders%1$s.', $filter_text );
		}

		if ( 'showing' === sanitize_key( $mode ) ) {
			if ( '' !== $status && 1 === $count ) {
				return sprintf( 'Here is one %s order%s.', $status, $filter_text );
			}

			if ( '' !== $status ) {
				return sprintf( 'Here are %1$d %2$s%3$s.', $count, $subject, $filter_text );
			}

			return sprintf( 'Here are the latest %1$d orders%2$s.', $count, $filter_text );
		}

		if ( '' !== $status && 1 === $count ) {
			return sprintf( 'I found one %1$s order%2$s.', $status, $filter_text );
		}

		if ( 1 === $count ) {
			return sprintf( 'I found one order%1$s.', $filter_text );
		}

		return sprintf( 'I found %1$d %2$s%3$s.', $count, $subject, $filter_text );
	}

	private static function normalize_response_locale( $locale ) {
		$locale = strtolower( sanitize_key( (string) $locale ) );
		$parts  = preg_split( '/[_-]/', $locale );

		return ! empty( $parts[0] ) ? $parts[0] : 'en';
	}

	private static function localized_order_search_summary( $mode, $count, $status, $filter_text, $locale ) {
		$locale = self::normalize_response_locale( $locale );

		if ( '' !== trim( (string) $filter_text ) ) {
			return '';
		}

		$templates = [
			'es' => [
				'orders'       => 'pedidos',
				'status_order' => 'pedidos %s',
				'found'        => 'Encontré %1$d %2$s.',
				'showing'      => 'Aquí tienes %1$d %2$s.',
				'none'         => 'No encontré %s.',
			],
			'fr' => [
				'orders'       => 'commandes',
				'status_order' => 'commandes %s',
				'found'        => 'J’ai trouvé %1$d %2$s.',
				'showing'      => 'Voici %1$d %2$s.',
				'none'         => 'Je n’ai trouvé aucune %s.',
			],
			'de' => [
				'orders'       => 'Bestellungen',
				'status_order' => '%s Bestellungen',
				'found'        => 'Ich habe %1$d %2$s gefunden.',
				'showing'      => 'Hier sind %1$d %2$s.',
				'none'         => 'Ich habe keine %s gefunden.',
			],
			'ja' => [
				'orders'       => '注文',
				'status_order' => '%sの注文',
				'found'        => '%2$sが%1$d件見つかりました。',
				'showing'      => '%2$sを%1$d件表示します。',
				'none'         => '%sは見つかりませんでした。',
			],
			'th' => [
				'orders'       => 'คำสั่งซื้อ',
				'status_order' => 'คำสั่งซื้อ%s',
				'found'        => 'พบ%2$s %1$d รายการ',
				'showing'      => 'แสดง%2$s %1$d รายการ',
				'none'         => 'ไม่พบ%s',
			],
			'id' => [
				'orders'       => 'pesanan',
				'status_order' => 'pesanan %s',
				'found'        => 'Saya menemukan %1$d %2$s.',
				'showing'      => 'Berikut %1$d %2$s.',
				'none'         => 'Saya tidak menemukan %s.',
			],
			'pt' => [
				'orders'       => 'pedidos',
				'status_order' => 'pedidos %s',
				'found'        => 'Encontrei %1$d %2$s.',
				'showing'      => 'Aqui estão %1$d %2$s.',
				'none'         => 'Não encontrei %s.',
			],
			'ar' => [
				'orders'       => 'طلبات',
				'status_order' => 'طلبات %s',
				'found'        => 'عثرت على %1$d %2$s.',
				'showing'      => 'إليك %1$d %2$s.',
				'none'         => 'لم أجد أي %s.',
			],
		];

		if ( empty( $templates[ $locale ] ) ) {
			return '';
		}

		$template = $templates[ $locale ];
		$subject  = '' !== $status
			? sprintf( $template['status_order'], $status )
			: $template['orders'];

		if ( 0 === $count ) {
			return sprintf( $template['none'], $subject );
		}

		$key = 'showing' === sanitize_key( $mode ) ? 'showing' : 'found';

		return sprintf( $template[ $key ], $count, $subject );
	}

	public static function order_search_filter_phrases( $params = [], $resolved_window = [], $source = [], $locale = 'en' ) {
		$params = is_array( $params ) ? $params : [];
		$source = is_array( $source ) ? $source : [];
		$phrases = [];
		$locale = self::normalize_response_locale( $locale ?: 'en' );
		$min_age_hours = isset( $params['min_age_hours'] ) ? absint( $params['min_age_hours'] ) : 0;

		if ( $min_age_hours > 0 ) {
			$age_phrase = self::age_threshold_phrase( $min_age_hours, $source, $locale );

			if ( '' !== $age_phrase ) {
				$phrases[] = $age_phrase;
			}
		} else {
			$date_phrase = self::date_window_phrase( $params, $resolved_window, $locale );

			if ( '' !== $date_phrase ) {
				$phrases[] = $date_phrase;
			}
		}

		$money_phrase = self::money_filter_phrase( $params, $source, $locale );

		if ( '' !== $money_phrase ) {
			$phrases[] = $money_phrase;
		}

		$payment_phrase = self::payment_method_phrase( $params, $source, $locale );

		if ( '' !== $payment_phrase ) {
			$phrases[] = $payment_phrase;
		}

		$shipping_phrase = self::shipping_state_phrase( $params, $source, $locale );

		if ( '' !== $shipping_phrase ) {
			$phrases[] = $shipping_phrase;
		}

		$customer_phrase = self::customer_identity_phrase( $params, $source, $locale );

		if ( '' !== $customer_phrase ) {
			$phrases[] = $customer_phrase;
		}

		return $phrases;
	}

	public static function age_threshold_label( $min_age_hours, $source = [], $locale = 'en' ) {
		$min_age_hours = absint( $min_age_hours );

		if ( $min_age_hours <= 0 ) {
			return '';
		}

		$source = is_array( $source ) ? $source : [];
		$locale = self::normalize_response_locale( $locale ?: 'en' );
		$shape = isset( $source['shape'] ) && is_array( $source['shape'] ) ? $source['shape'] : [];
		$shape_label = self::age_threshold_label_from_shape( $shape, $min_age_hours, $locale );

		if ( '' !== $shape_label ) {
			return $shape_label;
		}

		return self::age_threshold_label_from_hours( $min_age_hours, $locale );
	}

	public static function age_threshold_phrase( $min_age_hours, $source = [], $locale = 'en' ) {
		$label = self::age_threshold_label( $min_age_hours, $source, $locale );

		if ( '' === $label ) {
			return '';
		}

		return 'vi' === self::normalize_response_locale( $locale )
			? sprintf( 'cũ hơn %s', $label )
			: sprintf( 'older than %s', $label );
	}

	private static function order_status_label( $params, $locale = 'en' ) {
		$params = is_array( $params ) ? $params : [];
		$locale = self::normalize_response_locale( $locale ?: 'en' );

		if ( empty( $params['status'] ) ) {
			return '';
		}

		$status_values = is_array( $params['status'] ) ? $params['status'] : [ $params['status'] ];
		$status = sanitize_key( reset( $status_values ) );

		$labels = [
			'en' => [
				'pending'    => 'Pending payment',
				'processing' => 'Processing',
				'completed'  => 'Completed',
				'on-hold'    => 'On hold',
				'cancelled'  => 'Cancelled',
				'refunded'   => 'Refunded',
				'failed'     => 'Failed',
			],
				'vi' => [
					'pending'    => 'đang chờ xử lý',
					'processing' => 'đang xử lý',
					'completed'  => 'hoàn tất',
					'on-hold'    => 'tạm giữ',
					'cancelled'  => 'đã hủy',
					'refunded'   => 'đã hoàn tiền',
					'failed'     => 'thất bại',
				],
				'es' => [
					'pending'    => 'pendientes',
					'processing' => 'en proceso',
					'completed'  => 'completados',
					'on-hold'    => 'en espera',
					'cancelled'  => 'cancelados',
					'refunded'   => 'reembolsados',
					'failed'     => 'fallidos',
				],
				'fr' => [
					'pending'    => 'en attente',
					'processing' => 'en traitement',
					'completed'  => 'terminées',
					'on-hold'    => 'en pause',
					'cancelled'  => 'annulées',
					'refunded'   => 'remboursées',
					'failed'     => 'échouées',
				],
				'de' => [
					'pending'    => 'ausstehende',
					'processing' => 'in Bearbeitung',
					'completed'  => 'abgeschlossene',
					'on-hold'    => 'zurückgestellte',
					'cancelled'  => 'stornierte',
					'refunded'   => 'erstattete',
					'failed'     => 'fehlgeschlagene',
				],
				'ja' => [
					'pending'    => '保留中',
					'processing' => '処理中',
					'completed'  => '完了済み',
					'on-hold'    => '保留',
					'cancelled'  => 'キャンセル済み',
					'refunded'   => '返金済み',
					'failed'     => '失敗',
				],
				'th' => [
					'pending'    => 'ที่รอดำเนินการ',
					'processing' => 'ที่กำลังประมวลผล',
					'completed'  => 'ที่เสร็จสมบูรณ์',
					'on-hold'    => 'ที่พักไว้',
					'cancelled'  => 'ที่ยกเลิกแล้ว',
					'refunded'   => 'ที่คืนเงินแล้ว',
					'failed'     => 'ที่ล้มเหลว',
				],
				'id' => [
					'pending'    => 'tertunda',
					'processing' => 'sedang diproses',
					'completed'  => 'selesai',
					'on-hold'    => 'ditahan',
					'cancelled'  => 'dibatalkan',
					'refunded'   => 'dikembalikan',
					'failed'     => 'gagal',
				],
				'pt' => [
					'pending'    => 'pendentes',
					'processing' => 'em processamento',
					'completed'  => 'concluídos',
					'on-hold'    => 'em espera',
					'cancelled'  => 'cancelados',
					'refunded'   => 'reembolsados',
					'failed'     => 'falhados',
				],
				'ar' => [
					'pending'    => 'معلقة',
					'processing' => 'قيد المعالجة',
					'completed'  => 'مكتملة',
					'on-hold'    => 'قيد الانتظار',
					'cancelled'  => 'ملغاة',
					'refunded'   => 'مستردة',
					'failed'     => 'فاشلة',
				],
			];

		if ( isset( $labels[ $locale ][ $status ] ) ) {
			return $labels[ $locale ][ $status ];
		}

		return ucwords( trim( preg_replace( '/\s+/', ' ', str_replace( [ '_', '-' ], ' ', $status ) ) ) );
	}

	private static function order_status_action_label( $status, $locale ) {
		$status = sanitize_key( $status );

		if ( 'vi' === sanitize_key( $locale ) ) {
			$labels = [
				'refunded'   => 'hoàn tiền',
				'completed'  => 'hoàn tất',
				'cancelled'  => 'hủy',
				'processing' => 'chuyển sang đang xử lý',
				'pending'    => 'chuyển sang đang chờ xử lý',
				'on-hold'    => 'tạm giữ',
			];

			return $labels[ $status ] ?? sprintf( 'chuyển sang %s', str_replace( '-', ' ', $status ) );
		}

		$labels = [
			'refunded'   => 'refund',
			'completed'  => 'mark as completed',
			'cancelled'  => 'cancel',
			'processing' => 'mark as processing',
			'pending'    => 'mark as pending',
			'on-hold'    => 'put on hold',
		];

		return $labels[ $status ] ?? sprintf( 'update to %s', str_replace( '-', ' ', $status ) );
	}

	private static function date_window_phrase( $params, $resolved_window, $locale ) {
		$params = is_array( $params ) ? $params : [];
		$resolved_window = is_array( $resolved_window ) ? $resolved_window : [];
		$window_key = sanitize_key( $resolved_window['key'] ?? ( $params['date_window'] ?? '' ) );

		if ( '' === $window_key ) {
			return '';
		}

		$label = strtolower( YoRo_Date_Helper::get_window_label( $window_key, $locale ) );

		if ( '' === $label ) {
			return '';
		}

		if ( in_array( $window_key, [ 'today', 'yesterday', 'this_week', 'this_month' ], true ) ) {
			return $label;
		}

		return 'vi' === sanitize_key( $locale )
			? 'trong ' . $label
			: 'in ' . $label;
	}

	private static function money_filter_phrase( $params, $source, $locale ) {
		$params = is_array( $params ) ? $params : [];
		$source = is_array( $source ) ? $source : [];
		$shape = isset( $source['shape'] ) && is_array( $source['shape'] ) ? $source['shape'] : [];
		$money_filter = isset( $shape['money_filter'] ) && is_array( $shape['money_filter'] )
			? $shape['money_filter']
			: [];
		$total_min = array_key_exists( 'total_min', $params ) && '' !== (string) $params['total_min']
			? (float) $params['total_min']
			: null;
		$total_max = array_key_exists( 'total_max', $params ) && '' !== (string) $params['total_max']
			? (float) $params['total_max']
			: null;
		$currency = sanitize_text_field( $money_filter['currency'] ?? 'USD' );

		if ( null !== $total_min && null !== $total_max ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf(
					'từ %1$s đến %2$s',
					self::format_money_label( $total_min, $currency, $locale ),
					self::format_money_label( $total_max, $currency, $locale )
				)
				: sprintf(
					'between %1$s and %2$s',
					self::format_money_label( $total_min, $currency, $locale ),
					self::format_money_label( $total_max, $currency, $locale )
				);
		}

		if ( null !== $total_min ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'trên %s', self::format_money_label( $total_min, $currency, $locale ) )
				: sprintf( 'over %s', self::format_money_label( $total_min, $currency, $locale ) );
		}

		if ( null !== $total_max ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'dưới %s', self::format_money_label( $total_max, $currency, $locale ) )
				: sprintf( 'under %s', self::format_money_label( $total_max, $currency, $locale ) );
		}

		return '';
	}

	private static function payment_method_phrase( $params, $source, $locale ) {
		$params = is_array( $params ) ? $params : [];
		$method = ! empty( $params['payment_method'] ) ? sanitize_key( $params['payment_method'] ) : '';

		if ( '' === $method ) {
			return '';
		}

		return 'vi' === sanitize_key( $locale )
			? sprintf( 'thanh toán bằng %s', self::payment_method_label( $method ) )
			: sprintf( 'paid by %s', self::payment_method_label( $method ) );
	}

	private static function shipping_state_phrase( $params, $source, $locale ) {
		$params = is_array( $params ) ? $params : [];
		$state = ! empty( $params['shipping_state'] )
			? strtoupper( sanitize_text_field( $params['shipping_state'] ) )
			: '';

		if ( '' === $state ) {
			return '';
		}

		return 'vi' === sanitize_key( $locale )
			? sprintf( 'giao đến %s', $state )
			: sprintf( 'shipping to %s', $state );
	}

	private static function customer_identity_phrase( $params, $source, $locale ) {
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['email'] ) ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'cho email %s', sanitize_email( $params['email'] ) )
				: sprintf( 'for %s', sanitize_email( $params['email'] ) );
		}

		if ( ! empty( $params['phone'] ) ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'cho số điện thoại %s', sanitize_text_field( $params['phone'] ) )
				: sprintf( 'for phone %s', sanitize_text_field( $params['phone'] ) );
		}

		if ( ! empty( $params['customer_id'] ) ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'cho khách hàng #%d', absint( $params['customer_id'] ) )
				: sprintf( 'for customer #%d', absint( $params['customer_id'] ) );
		}

		if ( ! empty( $params['customer_name'] ) ) {
			return 'vi' === sanitize_key( $locale )
				? sprintf( 'cho %s', sanitize_text_field( $params['customer_name'] ) )
				: sprintf( 'for %s', sanitize_text_field( $params['customer_name'] ) );
		}

		return '';
	}

	private static function payment_method_label( $method ) {
		$method = sanitize_key( $method );

		$labels = [
			'cod'       => 'COD',
			'stripe'    => 'Stripe',
			'stripe_cc' => 'Stripe',
			'paypal'    => 'PayPal',
		];

		return $labels[ $method ] ?? strtoupper( str_replace( [ '-', '_' ], ' ', $method ) );
	}

	private static function age_threshold_label_from_shape( $shape, $min_age_hours, $locale ) {
		$age_filter = isset( $shape['age_filter'] ) && is_array( $shape['age_filter'] )
			? $shape['age_filter']
			: [];

		$value = isset( $age_filter['value'] ) ? absint( $age_filter['value'] ) : 0;
		$unit  = sanitize_key( $age_filter['unit'] ?? '' );
		$hours = isset( $age_filter['hours'] ) ? absint( $age_filter['hours'] ) : 0;

		if ( $value <= 0 || '' === $unit || $hours !== $min_age_hours ) {
			return '';
		}

		return self::format_duration_label( $value, $unit, $locale );
	}

	private static function age_threshold_label_from_hours( $hours, $locale ) {
		$hours = absint( $hours );

		if ( $hours >= 168 && 0 === $hours % 168 ) {
			return self::format_duration_label( (int) ( $hours / 168 ), 'weeks', $locale );
		}

		if ( $hours >= 24 && 0 === $hours % 24 ) {
			return self::format_duration_label( (int) ( $hours / 24 ), 'days', $locale );
		}

		return self::format_duration_label( $hours, 'hours', $locale );
	}

	private static function format_duration_label( $value, $unit, $locale ) {
		$value = absint( $value );
		$unit  = sanitize_key( $unit );

		if ( $value <= 0 ) {
			return '';
		}

		if ( 'vi' === sanitize_key( $locale ) ) {
			if ( in_array( $unit, [ 'week', 'weeks', 'w', 'tuan', 'tuần' ], true ) ) {
				return sprintf( '%d tuần', $value );
			}

			if ( in_array( $unit, [ 'day', 'days', 'd', 'ngay', 'ngày' ], true ) ) {
				return sprintf( '%d ngày', $value );
			}

			return sprintf( '%d giờ', $value );
		}

		if ( in_array( $unit, [ 'week', 'weeks', 'w', 'tuan', 'tuần' ], true ) ) {
			return sprintf( '%d %s', $value, 1 === $value ? 'week' : 'weeks' );
		}

		if ( in_array( $unit, [ 'day', 'days', 'd', 'ngay', 'ngày' ], true ) ) {
			return sprintf( '%d %s', $value, 1 === $value ? 'day' : 'days' );
		}

		return sprintf( '%d %s', $value, 1 === $value ? 'hour' : 'hours' );
	}

	private static function format_money_label( $amount, $currency, $locale ) {
		$amount = (float) $amount;
		$currency = strtoupper( sanitize_text_field( $currency ?: 'USD' ) );
		$formatted = number_format( $amount, 2, '.', ',' );
		$formatted = preg_replace( '/\.00$/', '', $formatted );

		if ( 'USD' === $currency ) {
			return '$' . $formatted;
		}

		return trim( $formatted . ' ' . $currency );
	}
}
