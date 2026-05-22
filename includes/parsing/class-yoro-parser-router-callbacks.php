<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Router_Callbacks {

	public static function normalize_only() {
		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			},
		];
	}

	public static function normalize_and_hydrate() {
		return [
			'normalize_parsed_result' => static function( $parsed, $command, $normalized, $client_context = [] ) {
				return self::normalize_parsed_result( $parsed, $command, $normalized, $client_context );
			},
			'hydrate_result_intent_family_from_action' => static function( $result ) {
				return self::hydrate_result_intent_family_from_action( $result );
			},
		];
	}

	public static function normalize_and_parse( $parse_command_callback ) {
		return array_merge(
			self::normalize_only(),
			self::parse_command_callbacks( $parse_command_callback )
		);
	}

	public static function normalize_hydrate_and_parse( $parse_command_callback ) {
		return array_merge(
			self::normalize_and_hydrate(),
			self::parse_command_callbacks( $parse_command_callback )
		);
	}

	private static function parse_command_callbacks( $parse_command_callback ) {
		return [
			'parse_command' => static function( $command, $client_context = [] ) use ( $parse_command_callback ) {
				if ( is_callable( $parse_command_callback ) ) {
					return call_user_func( $parse_command_callback, $command, $client_context );
				}

				return null;
			},
		];
	}

	private static function hydrate_result_intent_family_from_action( $result ) {
		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::hydrate_intent_family_from_action( $result )
			: $result;
	}

	private static function normalize_parsed_result( $parsed, $command, $normalized, $client_context = [] ) {
		return class_exists( 'YoRo_Parser_Result_Normalizer' )
			? YoRo_Parser_Result_Normalizer::normalize( $parsed, $command, $normalized, $client_context )
			: $parsed;
	}
}
