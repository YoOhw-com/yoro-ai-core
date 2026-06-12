<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Date_Helper {
	const MAX_RELATIVE_HOURS_WINDOW  = 8760;
	const MAX_RELATIVE_DAYS_WINDOW   = 3650;
	const MAX_RELATIVE_WEEKS_WINDOW  = 520;
	const MAX_RELATIVE_MONTHS_WINDOW = 120;
	const MAX_RELATIVE_YEARS_WINDOW  = 10;

	public static function get_datetime_format() {
		$date_format = trim( (string) get_option( 'date_format', 'Y-m-d' ) );
		$time_format = trim( (string) get_option( 'time_format', 'H:i' ) );
		$format      = trim( $date_format . ' ' . $time_format );

		return '' !== $format ? $format : 'Y-m-d H:i';
	}

	public static function normalize_window_key( $value ) {
		if ( is_array( $value ) ) {
			$value = $value['canonical_key'] ?? $value['key'] ?? $value['window'] ?? '';
		}

		$value = strtolower( sanitize_text_field( is_scalar( $value ) ? $value : '' ) );
		$value = trim( str_replace( [ '-', ' ' ], '_', $value ) );

		if ( '' === $value ) {
			return '';
		}

		$map = [
			'today'        => 'today',
			'this_day'     => 'today',
			'hom_nay'      => 'today',
			'hôm_nay'      => 'today',
			'yesterday'    => 'yesterday',
			'hom_qua'      => 'yesterday',
			'hôm_qua'      => 'yesterday',
			'last_24_hours' => 'last_24_hours',
			'past_24_hours' => 'last_24_hours',
			'24_hours'     => 'last_24_hours',
			'24h'          => 'last_24_hours',
			'24_gio_qua'   => 'last_24_hours',
			'24_giờ_qua'   => 'last_24_hours',
			'this_week'    => 'this_week',
			'weekly'       => 'last_7_days',
			'tuan_nay'     => 'this_week',
			'tuần_này'     => 'this_week',
			'last_week'    => 'last_week',
			'tuan_truoc'   => 'last_week',
			'tuần_trước'   => 'last_week',
			'last_7_days'  => 'last_7_days',
			'past_week'    => 'last_7_days',
			'past_7_days'  => 'last_7_days',
			'7_days'       => 'last_7_days',
			'7_ngay_qua'   => 'last_7_days',
			'7_ngày_qua'   => 'last_7_days',
			'this_month'   => 'this_month',
			'monthly'      => 'last_30_days',
			'thang_nay'    => 'this_month',
			'tháng_này'    => 'this_month',
			'last_month'   => 'last_month',
			'thang_truoc'  => 'last_month',
			'tháng_trước'  => 'last_month',
			'last_30_days' => 'last_30_days',
			'past_month'   => 'last_30_days',
			'past_30_days' => 'last_30_days',
			'30_days'      => 'last_30_days',
			'30_ngay_qua'  => 'last_30_days',
			'30_ngày_qua'  => 'last_30_days',
			'this_quarter' => 'this_quarter',
			'qtd'          => 'this_quarter',
			'quy_nay'      => 'this_quarter',
			'quý_này'      => 'this_quarter',
			'last_quarter' => 'last_quarter',
			'previous_quarter' => 'last_quarter',
			'quy_truoc'    => 'last_quarter',
			'quý_trước'    => 'last_quarter',
			'this_year'    => 'this_year',
			'year_to_date' => 'this_year',
			'ytd'          => 'this_year',
			'nam_nay'      => 'this_year',
			'năm_nay'      => 'this_year',
			'last_year'    => 'last_year',
			'previous_year' => 'last_year',
			'past_year'    => 'last_year',
			'nam_ngoai'    => 'last_year',
			'năm_ngoái'    => 'last_year',
			'nam_truoc'    => 'last_year',
			'năm_trước'    => 'last_year',
		];

		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}

		return self::normalize_flexible_window_key( $value );
	}

	public static function window_key_from_params( $params, $primary_key = 'window', $default = '' ) {
		$params = is_array( $params ) ? $params : [];

		if ( ! empty( $params['time_window'] ) && is_array( $params['time_window'] ) ) {
			$window_key = self::normalize_window_key( $params['time_window'] );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		if ( '' !== $primary_key && ! empty( $params[ $primary_key ] ) ) {
			$window_key = self::normalize_window_key( $params[ $primary_key ] );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		foreach ( [ 'window', 'date_window' ] as $key ) {
			if ( $key === $primary_key || empty( $params[ $key ] ) ) {
				continue;
			}

			$window_key = self::normalize_window_key( $params[ $key ] );

			if ( '' !== $window_key ) {
				return $window_key;
			}
		}

		return self::normalize_window_key( $default );
	}

	public static function resolve_window( $window_key, $now = null ) {
		$window_key = self::normalize_window_key( $window_key );

		if ( '' === $window_key ) {
			return [];
		}

		$now = $now instanceof DateTimeInterface ? DateTimeImmutable::createFromInterface( $now ) : current_datetime();
		$timezone = wp_timezone();
		$now = $now->setTimezone( $timezone );
		$start = $now;
		$end = $now;
		$relative_window = self::get_relative_window_from_window_key( $window_key );
		$calendar_window = self::get_calendar_window_from_key( $window_key, $now, $timezone );

		if ( ! empty( $relative_window ) ) {
			$start = $now->modify( '-' . $relative_window['value'] . ' ' . $relative_window['unit'] );
			$end   = $now;
		} elseif ( ! empty( $calendar_window ) ) {
			$start = $calendar_window['start'];
			$end   = $calendar_window['end'];
		} else {
			switch ( $window_key ) {
			case 'yesterday':
				$start = $now->modify( '-1 day' )->setTime( 0, 0, 0 );
				$end = $start->setTime( 23, 59, 59 );
				break;

			case 'this_week':
				$start = self::get_calendar_week_start( $now );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'last_week':
				$this_week_start = self::get_calendar_week_start( $now );
				$start = $this_week_start->modify( '-7 days' );
				$end = $this_week_start->modify( '-1 second' );
				break;

			case 'last_24_hours':
				$start = $now->modify( '-24 hours' );
				$end = $now;
				break;

			case 'last_7_days':
				$start = $now->modify( '-6 days' )->setTime( 0, 0, 0 );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'this_month':
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'last_month':
				$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
				$end = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
				break;

			case 'last_30_days':
				$start = $now->modify( '-29 days' )->setTime( 0, 0, 0 );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'this_quarter':
				$start = self::get_calendar_quarter_start( $now );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'last_quarter':
				$this_quarter_start = self::get_calendar_quarter_start( $now );
				$start = $this_quarter_start->modify( '-3 months' );
				$end = $this_quarter_start->modify( '-1 second' );
				break;

			case 'this_year':
				$start = new DateTimeImmutable( $now->format( 'Y' ) . '-01-01 00:00:00', $timezone );
				$end = $now->setTime( 23, 59, 59 );
				break;

			case 'last_year':
				$year  = (int) $now->format( 'Y' ) - 1;
				$start = new DateTimeImmutable( $year . '-01-01 00:00:00', $timezone );
				$end   = new DateTimeImmutable( $year . '-12-31 23:59:59', $timezone );
				break;

			case 'today':
			default:
				$start = $now->setTime( 0, 0, 0 );
				$end = $now->setTime( 23, 59, 59 );
				break;
			}
		}

		return [
			'key'            => $window_key,
			'label'          => self::get_window_label( $window_key ),
			'date_from'      => $start->format( 'Y-m-d' ),
			'date_to'        => $end->format( 'Y-m-d' ),
			'start'          => $start->format( 'Y-m-d H:i:s' ),
			'end'            => $end->format( 'Y-m-d H:i:s' ),
			'start_ts'       => (int) $start->getTimestamp(),
			'end_ts'         => (int) $end->getTimestamp(),
			'query'          => $start->format( 'Y-m-d H:i:s' ) . '...' . $end->format( 'Y-m-d H:i:s' ),
			'timezone'       => wp_timezone_string(),
			'week_starts_on' => absint( get_option( 'start_of_week', 1 ) ),
		];
	}

	private static function get_calendar_week_start( DateTimeImmutable $now ) {
		$week_starts_on = absint( get_option( 'start_of_week', 1 ) ) % 7;
		$current_day = (int) $now->format( 'w' );
		$days_since_start = ( $current_day - $week_starts_on + 7 ) % 7;

		return $now->modify( '-' . $days_since_start . ' days' )->setTime( 0, 0, 0 );
	}

	private static function get_calendar_quarter_start( DateTimeImmutable $now ) {
		$quarter_start_month = ( (int) floor( ( (int) $now->format( 'n' ) - 1 ) / 3 ) * 3 ) + 1;

		return new DateTimeImmutable( $now->format( 'Y' ) . '-' . sprintf( '%02d', $quarter_start_month ) . '-01 00:00:00', $now->getTimezone() );
	}

	public static function get_window_label( $window_key, $language = '' ) {
		$window_key = self::normalize_window_key( $window_key );
		$language   = strtolower( sanitize_key( $language ) );

		$labels = [
			'en' => [
				'today'        => __( 'Today', 'yoro-ai-core' ),
				'yesterday'    => __( 'Yesterday', 'yoro-ai-core' ),
				'last_24_hours' => __( 'Last 24 hours', 'yoro-ai-core' ),
				'this_week'    => __( 'This week', 'yoro-ai-core' ),
				'last_week'    => __( 'Last week', 'yoro-ai-core' ),
				'last_7_days'  => __( 'Last 7 days', 'yoro-ai-core' ),
				'this_month'   => __( 'This month', 'yoro-ai-core' ),
				'last_month'   => __( 'Last month', 'yoro-ai-core' ),
				'last_30_days' => __( 'Last 30 days', 'yoro-ai-core' ),
				'this_quarter' => __( 'This quarter', 'yoro-ai-core' ),
				'last_quarter' => __( 'Last quarter', 'yoro-ai-core' ),
				'this_year'    => __( 'This year', 'yoro-ai-core' ),
				'last_year'    => __( 'Last year', 'yoro-ai-core' ),
			],
			'vi' => [
				'today'        => 'hôm nay',
				'yesterday'    => 'hôm qua',
				'last_24_hours' => '24 giờ qua',
				'this_week'    => 'tuần này',
				'last_week'    => 'tuần trước',
				'last_7_days'  => '7 ngày qua',
				'this_month'   => 'tháng này',
				'last_month'   => 'tháng trước',
				'last_30_days' => '30 ngày qua',
				'this_quarter' => 'quý này',
				'last_quarter' => 'quý trước',
				'this_year'    => 'năm nay',
				'last_year'    => 'năm trước',
			],
		];

		if ( isset( $labels[ $language ][ $window_key ] ) ) {
			return $labels[ $language ][ $window_key ];
		}

		$calendar_label = self::get_calendar_window_label( $window_key, $language );

		if ( '' !== $calendar_label ) {
			return $calendar_label;
		}

		$relative_window = self::get_relative_window_from_window_key( $window_key );

		if ( ! empty( $relative_window ) ) {
			return self::get_relative_window_label( $relative_window, $language );
		}

		return $labels['en'][ $window_key ] ?? sanitize_text_field( $window_key );
	}

	private static function normalize_flexible_window_key( $value ) {
		$value = function_exists( 'remove_accents' ) ? remove_accents( $value ) : $value;
		$value = strtolower( (string) $value );
		$value = trim( preg_replace( '/[^a-z0-9]+/', '_', $value ), '_' );

		if ( '' === $value ) {
			return '';
		}

		$calendar_window = self::normalize_calendar_window_key( $value );

		if ( '' !== $calendar_window ) {
			return $calendar_window;
		}

		$patterns = [
			'/^(?:last|past|previous|over)_([1-9][0-9]{0,4})_(hours?|hrs?|h|days?|weeks?|months?|years?|gio|ngay|tuan|thang|nam)$/',
			'/^([1-9][0-9]{0,4})_(hours?|hrs?|h|days?|weeks?|months?|years?|gio|ngay|tuan|thang|nam)$/',
			'/^([1-9][0-9]{0,4})_(hours?|hrs?|h|days?|weeks?|months?|years?|gio|ngay|tuan|thang|nam)_(?:qua|gan_day)$/',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $value, $matches ) ) {
				return self::build_relative_window_key( (int) $matches[1], $matches[2] ?? 'days' );
			}
		}

		return '';
	}

	private static function build_relative_window_key( $value, $unit ) {
		$value = absint( $value );
		$unit  = self::normalize_relative_unit( $unit );

		if ( '' === $unit || $value < 1 || $value > self::max_relative_window_value( $unit ) ) {
			return '';
		}

		if ( 'days' === $unit && 1 === $value ) {
			return 'last_24_hours';
		}

		if ( 'hours' === $unit && 24 === $value ) {
			return 'last_24_hours';
		}

		return 'last_' . $value . '_' . $unit;
	}

	private static function normalize_relative_unit( $unit ) {
		$unit = function_exists( 'remove_accents' ) ? remove_accents( $unit ) : $unit;
		$unit = strtolower( trim( (string) $unit ) );

		$map = [
			'h'      => 'hours',
			'hr'     => 'hours',
			'hrs'    => 'hours',
			'hour'   => 'hours',
			'hours'  => 'hours',
			'gio'    => 'hours',
			'day'    => 'days',
			'days'   => 'days',
			'ngay'   => 'days',
			'week'   => 'weeks',
			'weeks'  => 'weeks',
			'tuan'   => 'weeks',
			'month'  => 'months',
			'months' => 'months',
			'thang'  => 'months',
			'year'   => 'years',
			'years'  => 'years',
			'nam'    => 'years',
		];

		return $map[ $unit ] ?? '';
	}

	private static function max_relative_window_value( $unit ) {
		switch ( $unit ) {
			case 'hours':
				return self::MAX_RELATIVE_HOURS_WINDOW;
			case 'days':
				return self::MAX_RELATIVE_DAYS_WINDOW;
			case 'weeks':
				return self::MAX_RELATIVE_WEEKS_WINDOW;
			case 'months':
				return self::MAX_RELATIVE_MONTHS_WINDOW;
			case 'years':
				return self::MAX_RELATIVE_YEARS_WINDOW;
			default:
				return 0;
		}
	}

	public static function get_relative_window_from_window_key( $window_key ) {
		$window_key = sanitize_key( $window_key );

		if ( ! preg_match( '/^last_([1-9][0-9]{0,4})_(hours|days|weeks|months|years)$/', $window_key, $matches ) ) {
			return [];
		}

		$value = absint( $matches[1] );
		$unit  = $matches[2];

		if ( $value < 1 || $value > self::max_relative_window_value( $unit ) ) {
			return [];
		}

		if ( 'days' === $unit && 1 === $value ) {
			return [];
		}

		return [
			'value' => $value,
			'unit'  => $unit,
		];
	}

	private static function get_relative_window_label( $relative_window, $language ) {
		$value = absint( $relative_window['value'] ?? 0 );
		$unit  = sanitize_key( $relative_window['unit'] ?? '' );

		if ( $value < 1 || '' === $unit ) {
			return '';
		}

		if ( 'vi' === $language ) {
			$units = [
				'hours'  => 'giờ',
				'days'   => 'ngày',
				'weeks'  => 'tuần',
				'months' => 'tháng',
				'years'  => 'năm',
			];

			return sprintf( '%d %s qua', $value, $units[ $unit ] ?? str_replace( '_', ' ', $unit ) );
		}

		switch ( $unit ) {
			case 'hours':
				return sprintf( _n( 'Last %d hour', 'Last %d hours', $value, 'yoro-ai-core' ), $value );
			case 'weeks':
				return sprintf( _n( 'Last %d week', 'Last %d weeks', $value, 'yoro-ai-core' ), $value );
			case 'months':
				return sprintf( _n( 'Last %d month', 'Last %d months', $value, 'yoro-ai-core' ), $value );
			case 'years':
				return sprintf( _n( 'Last %d year', 'Last %d years', $value, 'yoro-ai-core' ), $value );
			case 'days':
			default:
				return sprintf( _n( 'Last %d day', 'Last %d days', $value, 'yoro-ai-core' ), $value );
		}
	}

	private static function normalize_calendar_window_key( $value ) {
		$range_window = self::normalize_range_window_key( $value );

		if ( '' !== $range_window ) {
			return $range_window;
		}

		if ( preg_match( '/^(first|second|third|fourth|last|final|1st|2nd|3rd|4th|q[1-4])_quarter(?:_of)?_(this|last|previous)_year$/', $value, $matches ) ) {
			return self::build_quarter_window_key( self::relative_year_from_token( $matches[2], current_datetime() ), self::quarter_number_from_token( $matches[1] ) );
		}

		if ( preg_match( '/^(first|second|third|fourth|last|final|1st|2nd|3rd|4th|q[1-4])_quarter(?:_of)?_([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[2], self::quarter_number_from_token( $matches[1] ) );
		}

		if ( preg_match( '/^quarter_([1-4])(?:_of)?_(this|last|previous)_year$/', $value, $matches ) ) {
			return self::build_quarter_window_key( self::relative_year_from_token( $matches[2], current_datetime() ), (int) $matches[1] );
		}

		if ( preg_match( '/^quarter_([1-4])(?:_of)?_([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[2], (int) $matches[1] );
		}

		if ( preg_match( '/^([12][0-9]{3})_(first|second|third|fourth|last|final|1st|2nd|3rd|4th)_quarter$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[1], self::quarter_number_from_token( $matches[2] ) );
		}

		if ( preg_match( '/^(first|second|1st|2nd)_half(?:_of)?_(this|last|previous)_year$/', $value, $matches ) ) {
			$year = self::relative_year_from_token( $matches[2], current_datetime() );

			return in_array( $matches[1], [ 'second', '2nd' ], true )
				? self::build_month_range_window_key( $year, 7, $year, 12 )
				: self::build_month_range_window_key( $year, 1, $year, 6 );
		}

		if ( preg_match( '/^(first|second|1st|2nd)_half(?:_of)?_([12][0-9]{3})$/', $value, $matches ) ) {
			return in_array( $matches[1], [ 'second', '2nd' ], true )
				? self::build_month_range_window_key( (int) $matches[2], 7, (int) $matches[2], 12 )
				: self::build_month_range_window_key( (int) $matches[2], 1, (int) $matches[2], 6 );
		}

		if ( preg_match( '/^(jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)_(this|last|previous)_year$/', $value, $matches ) ) {
			return self::build_month_window_key( self::relative_year_from_token( $matches[2], current_datetime() ), self::month_number_from_token( $matches[1] ) );
		}

		if ( preg_match( '/^(jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)_to_(jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)_(this|last|previous)_year$/', $value, $matches ) ) {
			$year = self::relative_year_from_token( $matches[3], current_datetime() );

			return self::build_month_range_window_key( $year, self::month_number_from_token( $matches[1] ), $year, self::month_number_from_token( $matches[2] ) );
		}

		if ( preg_match( '/^(?:month_)?([12][0-9]{3})_([0-1]?[0-9])$/', $value, $matches ) ) {
			return self::build_month_window_key( (int) $matches[1], (int) $matches[2] );
		}

		if ( preg_match( '/^(?:month_)?([0-1]?[0-9])_(?:nam_)?([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_month_window_key( (int) $matches[2], (int) $matches[1] );
		}

		if ( preg_match( '/^(jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)_([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_month_window_key( (int) $matches[2], self::month_number_from_token( $matches[1] ) );
		}

		if ( preg_match( '/^([12][0-9]{3})_(jan|january|feb|february|mar|march|apr|april|may|jun|june|jul|july|aug|august|sep|sept|september|oct|october|nov|november|dec|december)$/', $value, $matches ) ) {
			return self::build_month_window_key( (int) $matches[1], self::month_number_from_token( $matches[2] ) );
		}

		if ( preg_match( '/^(?:q|quarter_|quy_)([1-4])_([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[2], (int) $matches[1] );
		}

		if ( preg_match( '/^quarter_([12][0-9]{3})_q([1-4])$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[1], (int) $matches[2] );
		}

		if ( preg_match( '/^([12][0-9]{3})_(?:q|quarter_|quy_)([1-4])$/', $value, $matches ) ) {
			return self::build_quarter_window_key( (int) $matches[1], (int) $matches[2] );
		}

		if ( preg_match( '/^(?:year_|nam_)?([12][0-9]{3})$/', $value, $matches ) ) {
			return self::build_year_window_key( (int) $matches[1] );
		}

		return '';
	}

	private static function normalize_range_window_key( $value ) {
		if ( preg_match( '/^range_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])_to_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])$/', $value, $matches ) ) {
			return self::build_range_window_key(
				sprintf( '%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3] ),
				sprintf( '%04d-%02d-%02d', (int) $matches[4], (int) $matches[5], (int) $matches[6] )
			);
		}

		return '';
	}

	private static function month_number_from_token( $token ) {
		$token = substr( strtolower( (string) $token ), 0, 3 );
		$map   = [
			'jan' => 1,
			'feb' => 2,
			'mar' => 3,
			'apr' => 4,
			'may' => 5,
			'jun' => 6,
			'jul' => 7,
			'aug' => 8,
			'sep' => 9,
			'oct' => 10,
			'nov' => 11,
			'dec' => 12,
		];

		return $map[ $token ] ?? 0;
	}

	private static function quarter_number_from_token( $token ) {
		$token = strtolower( trim( (string) $token ) );
		$map   = [
			'q1'     => 1,
			'1st'    => 1,
			'first'  => 1,
			'q2'     => 2,
			'2nd'    => 2,
			'second' => 2,
			'q3'     => 3,
			'3rd'    => 3,
			'third'  => 3,
			'q4'     => 4,
			'4th'    => 4,
			'fourth' => 4,
			'last'   => 4,
			'final'  => 4,
		];

		return $map[ $token ] ?? 0;
	}

	private static function relative_year_from_token( $token, DateTimeImmutable $now ) {
		$token = strtolower( trim( (string) $token ) );
		$year  = (int) $now->format( 'Y' );

		if ( preg_match( '/^[12][0-9]{3}$/', $token ) ) {
			return (int) $token;
		}

		if ( in_array( $token, [ 'last', 'previous', 'ngoai', 'truoc' ], true ) ) {
			return $year - 1;
		}

		return $year;
	}

	private static function build_range_window_key( $start_date, $end_date ) {
		$timezone = wp_timezone();
		$start    = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $start_date, $timezone );
		$end      = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $end_date, $timezone );

		if ( ! $start || ! $end ) {
			return '';
		}

		if ( $start->format( 'Y-m-d' ) !== (string) $start_date || $end->format( 'Y-m-d' ) !== (string) $end_date ) {
			return '';
		}

		if ( $start > $end ) {
			$tmp   = $start;
			$start = $end;
			$end   = $tmp;
		}

		return sprintf( 'range_%s_to_%s', $start->format( 'Y_m_d' ), $end->format( 'Y_m_d' ) );
	}

	private static function build_month_range_window_key( $start_year, $start_month, $end_year, $end_month ) {
		$start_year  = absint( $start_year );
		$start_month = absint( $start_month );
		$end_year    = absint( $end_year );
		$end_month   = absint( $end_month );

		if ( $start_year < 1970 || $start_year > 2100 || $end_year < 1970 || $end_year > 2100 || $start_month < 1 || $start_month > 12 || $end_month < 1 || $end_month > 12 ) {
			return '';
		}

		$start = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $start_year, $start_month ), wp_timezone() );
		$end   = ( new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $end_year, $end_month ), wp_timezone() ) )->modify( 'last day of this month' );

		return self::build_range_window_key( $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) );
	}

	private static function build_quarter_range_window_key( $start_year, $start_quarter, $end_year, $end_quarter ) {
		$start_month = ( absint( $start_quarter ) - 1 ) * 3 + 1;
		$end_month   = ( absint( $end_quarter ) - 1 ) * 3 + 3;

		if ( $start_month < 1 || $start_month > 10 || $end_month < 3 || $end_month > 12 ) {
			return '';
		}

		return self::build_month_range_window_key( $start_year, $start_month, $end_year, $end_month );
	}

	private static function build_month_window_key( $year, $month ) {
		$year  = absint( $year );
		$month = absint( $month );

		if ( $year < 1970 || $year > 2100 || $month < 1 || $month > 12 ) {
			return '';
		}

		return sprintf( 'month_%04d_%02d', $year, $month );
	}

	private static function build_quarter_window_key( $year, $quarter ) {
		$year    = absint( $year );
		$quarter = absint( $quarter );

		if ( $year < 1970 || $year > 2100 || $quarter < 1 || $quarter > 4 ) {
			return '';
		}

		return sprintf( 'quarter_%04d_q%d', $year, $quarter );
	}

	private static function build_year_window_key( $year ) {
		$year = absint( $year );

		if ( $year < 1970 || $year > 2100 ) {
			return '';
		}

		return sprintf( 'year_%04d', $year );
	}

	private static function get_calendar_window_from_key( $window_key, DateTimeImmutable $now, DateTimeZone $timezone ) {
		$window_key = sanitize_key( $window_key );

		if ( preg_match( '/^range_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])_to_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])$/', $window_key, $matches ) ) {
			$start = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3] ), $timezone );
			$end   = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%04d-%02d-%02d', (int) $matches[4], (int) $matches[5], (int) $matches[6] ), $timezone );

			if ( $start && $end ) {
				if ( $start > $end ) {
					$tmp   = $start;
					$start = $end;
					$end   = $tmp;
				}

				return [
					'start' => $start->setTime( 0, 0, 0 ),
					'end'   => $end->setTime( 23, 59, 59 ),
				];
			}
		}

		if ( preg_match( '/^month_([12][0-9]{3})_([0-1][0-9])$/', $window_key, $matches ) ) {
			$year  = (int) $matches[1];
			$month = (int) $matches[2];

			if ( $month >= 1 && $month <= 12 ) {
				$start = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );

				return [
					'start' => $start,
					'end'   => $start->modify( 'first day of next month' )->modify( '-1 second' ),
				];
			}
		}

		if ( preg_match( '/^quarter_([12][0-9]{3})_q([1-4])$/', $window_key, $matches ) ) {
			$year  = (int) $matches[1];
			$month = ( (int) $matches[2] - 1 ) * 3 + 1;
			$start = new DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $timezone );

			return [
				'start' => $start,
				'end'   => $start->modify( '+3 months' )->modify( '-1 second' ),
			];
		}

		if ( preg_match( '/^year_([12][0-9]{3})$/', $window_key, $matches ) ) {
			$year = (int) $matches[1];

			return [
				'start' => new DateTimeImmutable( $year . '-01-01 00:00:00', $timezone ),
				'end'   => new DateTimeImmutable( $year . '-12-31 23:59:59', $timezone ),
			];
		}

		return [];
	}

	private static function get_calendar_window_label( $window_key, $language ) {
		$window_key = sanitize_key( $window_key );

		if ( preg_match( '/^range_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])_to_([12][0-9]{3})_([0-1][0-9])_([0-3][0-9])$/', $window_key, $matches ) ) {
			$start = sprintf( '%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3] );
			$end   = sprintf( '%04d-%02d-%02d', (int) $matches[4], (int) $matches[5], (int) $matches[6] );

			return 'vi' === $language
				? sprintf( '%s đến %s', date_i18n( 'd/m/Y', strtotime( $start ) ), date_i18n( 'd/m/Y', strtotime( $end ) ) )
				: sprintf( '%s to %s', date_i18n( 'M j, Y', strtotime( $start ) ), date_i18n( 'M j, Y', strtotime( $end ) ) );
		}

		if ( preg_match( '/^month_([12][0-9]{3})_([0-1][0-9])$/', $window_key, $matches ) ) {
			$year  = (int) $matches[1];
			$month = (int) $matches[2];

			if ( $month < 1 || $month > 12 ) {
				return '';
			}

			return 'vi' === $language
				? sprintf( 'tháng %d/%d', $month, $year )
				: date_i18n( 'F Y', strtotime( sprintf( '%04d-%02d-01', $year, $month ) ) );
		}

		if ( preg_match( '/^quarter_([12][0-9]{3})_q([1-4])$/', $window_key, $matches ) ) {
			return 'vi' === $language
				? sprintf( 'quý %d/%d', (int) $matches[2], (int) $matches[1] )
				: sprintf( 'Q%d %d', (int) $matches[2], (int) $matches[1] );
		}

		if ( preg_match( '/^year_([12][0-9]{3})$/', $window_key, $matches ) ) {
			return (string) (int) $matches[1];
		}

		return '';
	}

	public static function get_relative_days_from_window_key( $window_key ) {
		$window_key = sanitize_key( $window_key );

		if ( ! preg_match( '/^last_([1-9][0-9]{0,4})_days$/', $window_key, $matches ) ) {
			return 0;
		}

		$days = absint( $matches[1] );

		return $days > 1 && $days <= self::MAX_RELATIVE_DAYS_WINDOW ? $days : 0;
	}

	public static function build_window_payload( $window ) {
		if ( empty( $window ) || ! is_array( $window ) ) {
			return [];
		}

		$window_key = self::normalize_window_key( $window['key'] ?? '' );
		$label      = ! empty( $window['label'] ) ? sanitize_text_field( $window['label'] ) : self::get_window_label( $window_key );

		return [
			'key'            => $window_key,
			'label'          => $label,
			'date_from'      => sanitize_text_field( $window['date_from'] ?? '' ),
			'date_to'        => sanitize_text_field( $window['date_to'] ?? '' ),
			'start'          => sanitize_text_field( $window['start'] ?? '' ),
			'end'            => sanitize_text_field( $window['end'] ?? '' ),
			'start_ts'       => isset( $window['start_ts'] ) ? (int) $window['start_ts'] : 0,
			'end_ts'         => isset( $window['end_ts'] ) ? (int) $window['end_ts'] : 0,
			'query'          => sanitize_text_field( $window['query'] ?? '' ),
			'timezone'       => sanitize_text_field( $window['timezone'] ?? wp_timezone_string() ),
			'week_starts_on' => isset( $window['week_starts_on'] ) ? absint( $window['week_starts_on'] ) : absint( get_option( 'start_of_week', 1 ) ),
		];
	}

	public static function build_previous_window( $window ) {
		if ( empty( $window ) || ! is_array( $window ) ) {
			return [];
		}

		$start_ts = isset( $window['start_ts'] ) ? (int) $window['start_ts'] : 0;
		$end_ts   = isset( $window['end_ts'] ) ? (int) $window['end_ts'] : 0;

		if ( $start_ts <= 0 || $end_ts <= 0 || $end_ts < $start_ts ) {
			return [];
		}

		$duration          = max( 1, $end_ts - $start_ts + 1 );
		$previous_end_ts   = $start_ts - 1;
		$previous_start_ts = $previous_end_ts - $duration + 1;
		$timezone          = wp_timezone();
		$previous_start    = ( new DateTimeImmutable( '@' . $previous_start_ts ) )->setTimezone( $timezone );
		$previous_end      = ( new DateTimeImmutable( '@' . $previous_end_ts ) )->setTimezone( $timezone );
		$window_key        = self::normalize_window_key( $window['key'] ?? '' );

		return [
			'key'            => $window_key ? $window_key : 'previous_period',
			'label'          => __( 'Previous period', 'yoro-ai-core' ),
			'date_from'      => $previous_start->format( 'Y-m-d' ),
			'date_to'        => $previous_end->format( 'Y-m-d' ),
			'start'          => $previous_start->format( 'Y-m-d H:i:s' ),
			'end'            => $previous_end->format( 'Y-m-d H:i:s' ),
			'start_ts'       => $previous_start_ts,
			'end_ts'         => $previous_end_ts,
			'query'          => $previous_start->format( 'Y-m-d H:i:s' ) . '...' . $previous_end->format( 'Y-m-d H:i:s' ),
			'timezone'       => wp_timezone_string(),
			'week_starts_on' => absint( get_option( 'start_of_week', 1 ) ),
		];
	}

	public static function format_timestamp( $timestamp ) {
		$timestamp = is_numeric( $timestamp ) ? (int) $timestamp : 0;

		if ( $timestamp <= 0 ) {
			return '';
		}

		return wp_date( self::get_datetime_format(), $timestamp, wp_timezone() );
	}

	public static function format_mysql( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		try {
			$datetime = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $e ) {
			$timestamp = strtotime( $value );

			return $timestamp ? self::format_timestamp( $timestamp ) : $value;
		}

		return wp_date( self::get_datetime_format(), $datetime->getTimestamp(), wp_timezone() );
	}

	public static function format_wc_datetime( $datetime ) {
		if ( ! is_object( $datetime ) || ! method_exists( $datetime, 'getTimestamp' ) ) {
			return '';
		}

		return self::format_timestamp( $datetime->getTimestamp() );
	}

	public static function get_mysql_timestamp( $value ) {
		$value = sanitize_text_field( (string) $value );

		if ( '' === $value ) {
			return 0;
		}

		try {
			$datetime = new DateTimeImmutable( $value, wp_timezone() );
			return (int) $datetime->getTimestamp();
		} catch ( Exception $e ) {
			$timestamp = strtotime( $value );
			return $timestamp ? (int) $timestamp : 0;
		}
	}

	public static function get_wc_timestamp( $datetime ) {
		if ( ! is_object( $datetime ) || ! method_exists( $datetime, 'getTimestamp' ) ) {
			return 0;
		}

		return (int) $datetime->getTimestamp();
	}
}
