<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_AI_Provider_Resolver {

	public static function resolve_provider() {
		$preferred = YoRo_AI_Settings::get_provider_preference();

		if (
			YORO_AI_PROVIDER_YORO_CLOUD === $preferred &&
			YoRo_AI_Settings::is_cloud_enabled() &&
			YoRo_AI_Settings::is_cloud_endpoint_ready() &&
			YoRo_AI_Settings::get_cloud_api_url() &&
			YoRo_AI_Settings::get_cloud_site_key()
		) {
			return YORO_AI_PROVIDER_YORO_CLOUD;
		}

		return YORO_AI_PROVIDER_OPENAI_DIRECT;
	}

	public static function supports( $capability ) {
		$capability = sanitize_key( $capability );
		$provider   = self::resolve_provider();

		if ( YORO_AI_PROVIDER_YORO_CLOUD === $provider ) {
			return in_array(
				$capability,
				[ 'parse', 'intent', 'workflow_plan', 'suggestions', 'email_compose' ],
				true
			);
		}

		if ( ! YoRo_AI_Settings::has_openai_direct_access() ) {
			return false;
		}

		return in_array(
			$capability,
			[ 'parse', 'intent', 'workflow_plan', 'suggestions', 'email_compose' ],
			true
		);
	}

	public static function get_client() {
		$provider = self::resolve_provider();

		if ( YORO_AI_PROVIDER_YORO_CLOUD === $provider ) {
			return new YoRo_Cloud_Client();
		}

		return new YoRo_OpenAI_Client();
	}
}
