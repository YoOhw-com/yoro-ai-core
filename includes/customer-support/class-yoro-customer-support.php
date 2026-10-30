<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class YoRo_Customer_Support {
	const OPTION = 'yoro_customer_support_v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'widget' ] );
		add_action( 'admin_menu', function () { add_options_page( 'Customer Support', 'Customer Support', 'manage_options', 'yoro-customer-support', [ __CLASS__, 'settings_page' ] ); } );
	}
	public static function settings() {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? array_replace( [ 'enabled' => false, 'revision' => 0, 'content_ids' => [], 'contact' => '' ], $value ) : [ 'enabled' => false, 'revision' => 0, 'content_ids' => [], 'contact' => '' ];
	}
	public static function routes() {
		foreach ( [ 'session', 'turn' ] as $path ) {
			register_rest_route( 'yoro/v1', '/support/' . $path, [ 'methods' => 'POST', 'permission_callback' => [ __CLASS__, 'permission' ], 'callback' => [ __CLASS__, $path ] ] );
		}
	}
	public static function permission( $request ) {
		$origin = $request->get_header( 'Origin' );
		$home = wp_parse_url( home_url() );
		$expected = ( $home['scheme'] ?? '' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( ! is_ssl() || ! self::settings()['enabled'] || ! is_string( $origin ) || ! hash_equals( $expected, $origin ) ) {
			return new WP_Error( 'support_forbidden', 'Support is unavailable.', [ 'status' => 403 ] );
		}
		return true;
	}
	public static function session( $request ) {
		$cookie = (string) ( $_COOKIE[ self::cookie_name() ] ?? '' );
		$session = YoRo_Support_Transport::open( $cookie );
		if ( $session && true === $request->get_param( 'reset' ) ) {
			if ( ! hash_equals( YoRo_Support_Transport::csrf( $cookie ), (string) $request->get_header( 'X-YoRo-Support-CSRF' ) ) ) { return self::unavailable(); }
			$revoked = YoRo_Support_Transport::request( 'DELETE', '/sessions/current', [], $session );
			if ( is_wp_error( $revoked ) && 'support_session_inactive' !== $revoked->get_error_code() ) { return self::unavailable(); }
			$session = [];
		}
		if ( $session ) {
			$validated = YoRo_Support_Transport::request( 'GET', '/sessions/current', [], $session );
			if ( is_wp_error( $validated ) ) {
				if ( 'support_session_inactive' !== $validated->get_error_code() ) { return self::unavailable(); }
				$session = [];
			}
		}
		if ( ! $session ) {
			$session = YoRo_Support_Transport::request( 'POST', '/sessions' );
			if ( is_wp_error( $session ) || ! is_string( $session['session_id'] ?? null ) || ! is_string( $session['credential'] ?? null )
				|| (int) ( $session['revision'] ?? 0 ) !== (int) self::settings()['revision'] ) { return self::unavailable(); }
			$session['expires'] = time() + DAY_IN_SECONDS;
			$cookie = YoRo_Support_Transport::seal( $session );
			if ( '' === $cookie ) { return self::unavailable(); }
			setcookie( self::cookie_name(), $cookie, [ 'expires' => $session['expires'], 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict' ] );
		}
		return self::response( [ 'csrf' => YoRo_Support_Transport::csrf( $cookie ) ] );
	}
	public static function turn( $request ) {
		$started = microtime( true );
		$cookie = (string) ( $_COOKIE[ self::cookie_name() ] ?? '' );
		$session = YoRo_Support_Transport::open( $cookie );
		$id = $request->get_param( 'turn_id' ); $message = $request->get_param( 'message' );
		if ( ! $session || ! hash_equals( YoRo_Support_Transport::csrf( $cookie ), (string) $request->get_header( 'X-YoRo-Support-CSRF' ) )
			|| ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id )
			|| ! is_string( $message ) || '' === trim( $message ) || mb_strlen( $message ) > 2000 ) { return self::unavailable(); }
		$settings = self::settings();
		if ( (int) $settings['revision'] !== (int) $session['revision'] ) { return self::unavailable(); }
		$snapshot = YoRo_Support_Facts::snapshot( $settings, $message );
		$result = YoRo_Support_Transport::request( 'POST', '/turns', [ 'turn_id' => $id, 'message' => $message, 'snapshot' => $snapshot ], $session );
		if ( is_wp_error( $result ) ) { return self::unavailable(); }
		if ( 'facts' === ( $result['status'] ?? '' ) && is_string( $result['query'] ?? null ) && microtime( true ) - $started < 25 ) {
			if ( self::settings() !== $settings ) { return self::cancel( $id, $session ); }
			$snapshot = YoRo_Support_Facts::snapshot( $settings, mb_substr( $result['query'], 0, 120 ) );
			$result = YoRo_Support_Transport::request( 'POST', '/turns/' . $id . '/facts', [ 'snapshot' => $snapshot ], $session );
			if ( is_wp_error( $result ) ) { return self::unavailable(); }
		}
		if ( in_array( $result['status'] ?? '', [ 'validate', 'completed' ], true ) && is_array( $result['snapshot'] ?? null ) ) {
			$previous = $result['snapshot'];
			$current = YoRo_Support_Facts::snapshot( $settings, '', array_column( $previous['sources'] ?? [], 'id' ) );
			if ( self::settings() !== $settings || $current !== $previous || microtime( true ) - $started >= 45 ) { return self::cancel( $id, $session ); }
			if ( 'validate' === $result['status'] ) {
				$result = YoRo_Support_Transport::request( 'POST', '/turns/' . $id . '/complete', [ 'snapshot' => $current ], $session );
			}
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) || self::settings() !== $settings ) { return self::unavailable(); }
			// Revalidate once more immediately before exposing a durable answer (including replay).
			if ( YoRo_Support_Facts::snapshot( $settings, '', array_column( $previous['sources'], 'id' ) ) !== $previous ) { return self::unavailable(); }
			return self::response( [ 'turn_id' => $id, 'answer' => self::safe_answer( $result['answer'] ?? [], $previous ) ] );
		}
		return self::unavailable();
	}
	public static function safe_answer( array $answer, array $snapshot ) {
		$sources = [];
		foreach ( $answer['sources'] ?? [] as $source ) {
			foreach ( $snapshot['sources'] ?? [] as $fact ) {
				if ( ( $source['id'] ?? null ) === $fact['id'] ) { $sources[] = [ 'title' => $fact['title'], 'url' => $fact['url'] ]; }
			}
		}
		return [ 'text' => mb_substr( wp_strip_all_tags( (string) ( $answer['text'] ?? '' ) ), 0, 4000 ), 'sources' => $sources ];
	}
	private static function cancel( $id, array $session ) {
		YoRo_Support_Transport::request( 'POST', '/turns/' . $id . '/cancel', [ 'snapshot' => [] ], $session );
		return self::unavailable();
	}
	private static function cookie_name() { return '__Host-yoro_support_' . get_current_blog_id(); }
	private static function response( array $body ) {
		$response = new WP_REST_Response( $body );
		$response->header( 'Cache-Control', 'no-store, private' );
		return $response;
	}
	private static function unavailable() { return new WP_Error( 'support_unavailable', 'Support is unavailable. Please retry later or use the site contact details.', [ 'status' => 503 ] ); }
	public static function assets() {
		if ( ! self::settings()['enabled'] ) { return; }
		wp_enqueue_style( 'yoro-support', plugins_url( 'assets/css/yoro-support.css', YORO_AI_CORE_FILE ), [], YORO_AI_CORE_VERSION );
		wp_enqueue_script( 'yoro-support', plugins_url( 'assets/js/yoro-support.js', YORO_AI_CORE_FILE ), [], YORO_AI_CORE_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}
	public static function widget() {
		if ( ! self::settings()['enabled'] ) { return; }
		echo '<div id="yoro-support" data-endpoint="' . esc_url( rest_url( 'yoro/v1/support/' ) ) . '"><button type="button" class="yoro-support-launch" aria-expanded="false" aria-controls="yoro-support-panel">' . esc_html__( 'Chat support', 'yoro-ai-core' ) . '</button>';
		echo '<section id="yoro-support-panel" hidden aria-label="' . esc_attr__( 'Chat support', 'yoro-ai-core' ) . '"><header><strong>' . esc_html__( 'Chat support', 'yoro-ai-core' ) . '</strong><button type="button" data-close aria-label="' . esc_attr__( 'Close chat', 'yoro-ai-core' ) . '">×</button></header>';
		echo '<p class="yoro-support-notice">' . esc_html__( 'AI answers use public site information and may be inaccurate. Messages are retained for up to 7 days. Please do not share personal or order details.', 'yoro-ai-core' ) . '</p>';
		echo '<div class="yoro-support-messages" role="log" aria-live="polite" aria-relevant="additions"></div><p data-status role="status"></p><form><label for="yoro-support-message">' . esc_html__( 'Your message', 'yoro-ai-core' ) . '</label><textarea id="yoro-support-message" maxlength="2000" rows="2" required></textarea><button type="submit">' . esc_html__( 'Send', 'yoro-ai-core' ) . '</button><button type="button" data-retry hidden>' . esc_html__( 'Retry', 'yoro-ai-core' ) . '</button><button type="button" data-reset>' . esc_html__( 'New conversation', 'yoro-ai-core' ) . '</button></form>';
		echo '<p class="yoro-support-contact">' . esc_html( self::settings()['contact'] ) . '</p></section></div>';
	}
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'yoro_manage_connection' ) ) { wp_die( esc_html__( 'Permission denied.', 'yoro-ai-core' ) ); }
		$remote = YoRo_Support_Transport::request( 'GET', '/settings', [], [], true );
		$settings = self::settings(); $error = is_wp_error( $remote );
		if ( ! $error && is_array( $remote['settings'] ?? null ) ) { $settings = $remote['settings']; update_option( self::OPTION, $settings, false ); }
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! $error ) {
			check_admin_referer( 'yoro_support_settings' );
			$ids = array_values( array_unique( array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['content_ids'] ?? '' ) ) ) ) ) ) );
			$values = [ 'enabled' => isset( $_POST['enabled'] ), 'revision' => (int) $settings['revision'], 'content_ids' => $ids,
				'contact' => sanitize_textarea_field( wp_unslash( $_POST['contact'] ?? '' ) ) ];
			$updated = YoRo_Support_Transport::request( 'PUT', '/settings', $values, [], true );
			$error = is_wp_error( $updated ) || ! is_array( $updated['settings'] ?? null );
			if ( ! $error ) { $settings = $updated['settings']; update_option( self::OPTION, $settings, false ); }
		}
		echo '<div class="wrap"><h1>Customer Support</h1><p>Read-only public FAQ/content and optional public WooCommerce product advice. Uses the existing Workspace credit balance: 1 credit per completed AI answer. Global pilot and operational policy approval are also required.</p>';
		if ( $error ) { echo '<p role="alert">Settings could not be verified. Support remains unavailable until the current API policy can be verified.</p>'; }
		echo '<form method="post">'; wp_nonce_field( 'yoro_support_settings' );
		echo '<p><label><input type="checkbox" name="enabled" value="1" ' . checked( ! empty( $settings['enabled'] ), true, false ) . '> Enable customer support</label></p>';
		echo '<p><label>Approved public page/post IDs (at most 50, comma separated)<br><input class="regular-text" name="content_ids" value="' . esc_attr( implode( ',', $settings['content_ids'] ?? [] ) ) . '"></label></p>';
		echo '<p><label>Public contact information<br><textarea class="large-text" name="contact" maxlength="500">' . esc_textarea( $settings['contact'] ?? '' ) . '</textarea></label></p>';
		submit_button( 'Save settings' ); echo '</form></div>';
	}
}
