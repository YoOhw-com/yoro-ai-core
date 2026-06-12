<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Email_Composer {

	const PROMPT_VERSION_EMAIL = 'email.v2';

	public static function compose( WC_Order $order, $instruction ) {
		if ( ! YoRo_AI_Provider_Resolver::supports( 'email_compose' ) ) {
			return new WP_Error(
				'yoro_ai_missing_api_key',
				__( 'YoRo AI API is not connected yet.', 'yoro-ai-core' )
			);
		}

		$trace_id      = self::generate_trace_id();
		$order_context = YoRo_Order_Email_Context_Builder::build( $order );
		$payload       = [
			'instruction'    => (string) $instruction,
			'site_url'       => home_url(),
			'plugin_version' => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			'email_context_pack' => isset( $order_context['email_context_pack'] ) && is_array( $order_context['email_context_pack'] )
				? $order_context['email_context_pack']
				: [],
			'order_context'  => $order_context,
		];
		$result        = YoRo_AI_Provider_Resolver::get_client()->compose_email( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$parsed = YoRo_AI_Email_Result_Normalizer::decode( $result );

		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'yoro_ai_invalid_email',
				__( 'I could not prepare a usable email draft from the AI response.', 'yoro-ai-core' )
			);
		}

		return [
			'to'       => sanitize_email( $order->get_billing_email() ),
			'subject'  => $parsed['subject'],
			'body'     => $parsed['body'],
			'template' => 'ai_custom',
			'meta'     => self::build_meta(
				'email_compose',
				[
					'trace_id'       => $trace_id,
					'prompt_version' => self::PROMPT_VERSION_EMAIL,
				]
			),
		];
	}

	private static function build_meta( $feature, array $overrides = [] ) {
		$feature = sanitize_key( $feature );
		$meta    = [
			'feature'        => $feature,
			'provider'       => YoRo_AI_Provider_Resolver::resolve_provider(),
			'model'          => 'yoro-ai-api',
			'prompt_version' => self::PROMPT_VERSION_EMAIL,
			'trace_id'       => self::generate_trace_id(),
		];

		foreach ( $overrides as $key => $value ) {
			$meta[ sanitize_key( $key ) ] = is_string( $value )
				? sanitize_text_field( $value )
				: $value;
		}

		return $meta;
	}

	private static function generate_trace_id() {
		return 'yoro_ai_' . wp_generate_uuid4();
	}

}
