<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Connector_Media_Client implements YoRo_Connector_Media_Client_Interface {

	public function download_media_reference( $reference_id, $destination ) {
		$api_url  = YoRo_AI_Settings::get_ai_api_url();
		$site_key = YoRo_AI_Settings::get_ai_api_site_key();
		$reference_id = sanitize_text_field( (string) $reference_id );

		if ( '' === $api_url || '' === $site_key || '' === $reference_id || '' === $destination ) {
			return new WP_Error( 'yoro_media_reference_missing', __( 'The staged YoRo media reference is unavailable.', 'yoro-ai-core' ) );
		}

		$response = wp_remote_get(
			$this->build_media_reference_endpoint_url( $api_url, $reference_id ),
			[
				'timeout'             => 30,
				'redirection'         => 0,
				'headers'             => $this->authenticated_headers( $site_key ),
				'stream'              => true,
				'filename'            => $destination,
				'limit_response_size' => ( 10 * MB_IN_BYTES ) + 1,
			]
		);

		if ( is_wp_error( $response ) ) {
			@unlink( $destination );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! file_exists( $destination ) ) {
			@unlink( $destination );
			return new WP_Error( 'yoro_media_reference_download_failed', __( 'YoRo could not download the staged media file.', 'yoro-ai-core' ) );
		}

		return [
			'sha256'   => sanitize_text_field( wp_remote_retrieve_header( $response, 'x-yoro-content-sha256' ) ),
			'mime_type'=> sanitize_mime_type( wp_remote_retrieve_header( $response, 'content-type' ) ),
			'byte_size'=> (int) filesize( $destination ),
		];
	}

	private function build_media_reference_endpoint_url( $api_url, $reference_id ) {
		$api_url = $this->build_api_base_url( $api_url );

		return $api_url . '/connectors/media-references/' . rawurlencode( $reference_id );
	}

	private function build_api_base_url( $api_url ) {
		$api_url = untrailingslashit( esc_url_raw( $api_url ) );

		return preg_replace(
			'#/(?:sites/(?:handshake|disconnect|credentials/rotate)|conversations(?:/message|/[^/]+)?|actions/(?:execute|confirm|claim)|ai/email/compose|connect/wordpress/(?:intents|exchange)|email/(?:(?:gmail|microsoft)/connect-intents|gmail/disconnect|imap/connect|disconnect|inbox(?:/sync)?|threads/[^/]+(?:/(?:reply|archive|reopen|read|unread|notes|messages/[^/]+/retry))?))$#',
			'',
			$api_url
		);
	}

	private function authenticated_headers( $site_key ) {
		$headers = [
			'Authorization' => 'Bearer ' . $site_key,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		];
		$assertion = class_exists( 'YoRo_WordPress_Actor' ) ? YoRo_WordPress_Actor::assertion( $site_key ) : '';

		if ( '' !== $assertion ) {
			$headers['X-YoRo-Actor-Assertion'] = $assertion;
		}

		return $headers;
	}
}
