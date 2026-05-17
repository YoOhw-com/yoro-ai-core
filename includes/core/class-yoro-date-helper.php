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
