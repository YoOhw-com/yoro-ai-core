<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Email_Result_Normalizer {

	public static function decode( $output_text ) {
		$parsed = json_decode( (string) $output_text, true );

		if ( ! is_array( $parsed ) ) {
			return null;
		}

		if ( empty( $parsed['subject'] ) || empty( $parsed['body'] ) ) {
			return null;
		}

		return [
			'subject' => sanitize_text_field( $parsed['subject'] ),
			'body'    => sanitize_textarea_field( $parsed['body'] ),
		];
	}
}
