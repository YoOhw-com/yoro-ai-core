<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Provider_Resolver {

	public static function resolve_provider() {
		if (
			YoRo_AI_Settings::is_ai_api_enabled() &&
			YoRo_AI_Settings::get_ai_api_url() &&
			YoRo_AI_Settings::get_ai_api_site_key()
		) {
			return YORO_AI_PROVIDER_YORO_AI_API;
		}

		return '';
	}

	public static function supports( $capability ) {
		$capability = sanitize_key( $capability );
		$provider   = self::resolve_provider();

		if ( YORO_AI_PROVIDER_YORO_AI_API !== $provider ) {
			return false;
		}

		$supported_capabilities = [ 'conversation', 'email_compose' ];

		if ( ! in_array( $capability, $supported_capabilities, true ) ) {
			return false;
		}

		return YoRo_AI_Settings::ai_api_allows_feature( $capability ) &&
			YoRo_AI_Settings::ai_api_has_quota_remaining();
	}

	public static function get_client() {
		return new YoRo_AI_API_Client();
	}
}
