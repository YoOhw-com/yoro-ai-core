<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Intent_Parser {

	public static function parse( $command, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_AI_Parser' ) || ! method_exists( 'YoRo_AI_Parser', 'parse_intent' ) ) {
			return null;
		}

		$result = YoRo_AI_Parser::parse_intent( $command, $client_context );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return $result;
	}
}