<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Parser_Context_Preparer {

	public static function prepare_initial_context( $command, $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		if ( class_exists( 'YoRo_Memory_Context_Resolver' ) ) {
			$client_context = YoRo_Memory_Context_Resolver::resolve( $command, $client_context );
		}

		return self::refresh_command_shape( $command, $client_context );
	}

	public static function prepare_phrase_context( $command, $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		$phrase_order_ids = class_exists( 'YoRo_Entity_Phrase_Resolver' )
			? YoRo_Entity_Phrase_Resolver::resolve( $command, $client_context )
			: [];

		if ( empty( $phrase_order_ids ) ) {
			return $client_context;
		}

		$client_context['resolved_order_ids'] = $phrase_order_ids;

		return self::refresh_command_shape( $command, $client_context );
	}

	private static function refresh_command_shape( $command, $client_context = [] ) {
		$client_context = is_array( $client_context ) ? $client_context : [];

		if ( class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			$client_context['_command_shape'] = YoRo_Command_Shape_Extractor::extract( $command, $client_context );
		}

		return $client_context;
	}
}
