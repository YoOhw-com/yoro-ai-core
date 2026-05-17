<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Date_Helper {

	public static function get_datetime_format() {
		$date_format = trim( (string) get_option( 'date_format', 'Y-m-d' ) );
		$time_format = trim( (string) get_option( 'time_format', 'H:i' ) );
		$format      = trim( $date_format . ' ' . $time_format );

		return '' !== $format ? $format : 'Y-m-d H:i';
	}

	public static function normalize_window_key( $value ) {
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
		];

		return $map[ $value ] ?? '';
	}

	public static function infer_window_key_from_text( $text ) {
		$text = strtolower( sanitize_text_field( (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		$patterns = [
			'last_30_days' => '/(?:\b(?:last|past)\s+30\s+days\b|30\s+ng[aà]y\s+qua)/iu',
			'last_7_days'  => '/(?:\b(?:last|past)\s+7\s+days\b|\bpast\s+week\b|7\s+ng[aà]y\s+qua)/iu',
			'last_month'   => '/(?:\blast\s+month\b|th[aá]ng\s+tr[uư][oớ]c)/iu',
			'this_month'   => '/(?:\bthis\s+month\b|th[aá]ng\s+n[aà]y)/iu',
			'last_week'    => '/(?:\blast\s+week\b|tu[aầ]n\s+tr[uư][oớ]c)/iu',
			'this_week'    => '/(?:\bthis\s+week\b|tu[aầ]n\s+n[aà]y)/iu',
			'yesterday'    => '/(?:\byesterday\b|h[oô]m\s+qua)/iu',
			'today'        => '/(?:\btoday\b|h[oô]m\s+nay)/iu',
		];

		foreach ( $patterns as $key => $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return $key;
			}
		}

		return '';
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

			case 'today':
			default:
				$start = $now->setTime( 0, 0, 0 );
				$end = $now->setTime( 23, 59, 59 );
				break;
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

	public static function get_window_label( $window_key ) {
		$labels = [
			'today'        => __( 'Today', 'yoro-agent-core' ),
			'yesterday'    => __( 'Yesterday', 'yoro-agent-core' ),
			'this_week'    => __( 'This week', 'yoro-agent-core' ),
			'last_week'    => __( 'Last week', 'yoro-agent-core' ),
			'last_7_days'  => __( 'Last 7 days', 'yoro-agent-core' ),
			'this_month'   => __( 'This month', 'yoro-agent-core' ),
			'last_month'   => __( 'Last month', 'yoro-agent-core' ),
			'last_30_days' => __( 'Last 30 days', 'yoro-agent-core' ),
		];

		$window_key = self::normalize_window_key( $window_key );

		return $labels[ $window_key ] ?? sanitize_text_field( $window_key );
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
