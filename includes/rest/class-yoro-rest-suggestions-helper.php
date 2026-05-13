<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_REST_Suggestions_Helper {

	public static function enrich( $user_id, $parsed, $result ) {
		$suggestions = [];

		$should_generate = (
			is_array( $result ) &&
			empty( $result['requires_confirmation'] ) &&
			empty( $result['preview'] )
		);

		if ( ! $should_generate ) {
			return [
				'result'      => $result,
				'suggestions' => $suggestions,
			];
		}

		if ( ! empty( $result['suggestions'] ) && is_array( $result['suggestions'] ) ) {
			$suggestions = $result['suggestions'];
		} else {
			$suggestions = YoRo_Suggestions::suggest(
				$parsed,
				$result,
				YoRo_Conversation_Memory::get_runtime_context( $user_id )
			);

			$result['suggestions'] = $suggestions;
		}

		return [
			'result'      => $result,
			'suggestions' => $suggestions,
		];
	}
}
