<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Workflow_Result_Normalizer {

	public static function decode( $output_text ) {
		$parsed = json_decode( (string) $output_text, true );

		return is_array( $parsed ) ? $parsed : null;
	}
}
