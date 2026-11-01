<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-yoro-support-commerce.php';
require_once __DIR__ . '/class-yoro-support-context.php';

class YoRo_Customer_Support {
	const OPTION = 'yoro_customer_support_v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
		add_action( 'wp_footer', [ __CLASS__, 'widget' ] );
		add_action( 'admin_menu', function () { add_options_page( 'Chat Support', 'Chat Support', 'manage_options', 'yoro-customer-support', [ __CLASS__, 'settings_page' ] ); } );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
		add_action( 'wp_ajax_yoro_support_sources', function () { require_once __DIR__ . '/class-yoro-support-admin.php'; YoRo_Support_Admin::search(); } );
	}
	public static function settings() {
		$value = get_option( self::OPTION, [] );
		return is_array( $value ) ? array_replace( [ 'enabled' => false, 'revision' => 0, 'content_ids' => [], 'contact' => '' ], $value ) : [ 'enabled' => false, 'revision' => 0, 'content_ids' => [], 'contact' => '' ];
	}
	public static function routes() {
		foreach ( [ 'session', 'turn', 'verification', 'context' ] as $path ) {
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
		if ( ! self::fields( $request, [ 'reset' ] ) || ( null !== $request->get_param( 'reset' ) && ! is_bool( $request->get_param( 'reset' ) ) ) ) { return self::unavailable(); }
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
		$settings = self::settings();
		if ( self::v2( $settings ) && 2 !== ( $validated['protocol'] ?? $session['protocol'] ?? null ) ) { return self::unavailable(); }
		$state = self::v2( $settings ) ? YoRo_Support_Transport::request( 'GET', '/verification/current', [], $session ) : [ 'verified' => false ];
		if ( is_wp_error( $state ) ) { return $state; }
		return self::response( [ 'csrf' => YoRo_Support_Transport::csrf( $cookie ), 'protocol' => self::v2( $settings ) ? 2 : 1,
			'capabilities' => YoRo_Support_Context::capabilities( $settings ), 'verification' => $state ] );
	}
	public static function turn( $request ) {
		if ( ! self::fields( $request, [ 'turn_id', 'message', 'context_token' ] ) ) { return self::unavailable(); }
		$started = microtime( true );
		$cookie = (string) ( $_COOKIE[ self::cookie_name() ] ?? '' );
		$session = YoRo_Support_Transport::open( $cookie );
		$id = $request->get_param( 'turn_id' ); $message = $request->get_param( 'message' );
		if ( ! $session || ! hash_equals( YoRo_Support_Transport::csrf( $cookie ), (string) $request->get_header( 'X-YoRo-Support-CSRF' ) )
			|| ! is_string( $id ) || ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $id )
			|| ! is_string( $message ) || '' === trim( $message ) || mb_strlen( $message ) > 2000 ) { return self::unavailable(); }
		$settings = self::settings();
		if ( (int) $settings['revision'] !== (int) $session['revision'] ) { return self::unavailable(); }
		$token = $request->get_param( 'context_token' ) ?? '';
		if ( ! is_string( $token ) || strlen( $token ) > 12000 ) { return self::unavailable(); }
		$snapshot = self::snapshot( $settings, $session, $message, null, $token );
		if ( is_wp_error( $snapshot ) ) { return $snapshot; }
		$result = YoRo_Support_Transport::request( 'POST', '/turns', [ 'turn_id' => $id, 'message' => $message, 'snapshot' => $snapshot ], $session );
		if ( is_wp_error( $result ) ) { return $result; }
		if ( 'facts' === ( $result['status'] ?? '' ) && is_string( $result['query'] ?? null ) && microtime( true ) - $started < 25 ) {
			if ( self::settings() !== $settings ) { return self::cancel( $id, $session ); }
			$snapshot = self::snapshot( $settings, $session, mb_substr( $result['query'], 0, 120 ), null, $token );
			if ( is_wp_error( $snapshot ) ) { return self::cancel( $id, $session ); }
			$result = YoRo_Support_Transport::request( 'POST', '/turns/' . $id . '/facts', [ 'snapshot' => $snapshot ], $session );
			if ( is_wp_error( $result ) ) { return $result; }
		}
		if ( 'context' === ( $result['status'] ?? '' ) && self::v2( $settings ) && in_array( $result['context'] ?? '', [ 'orders', 'quote', 'coupons', 'payments' ], true ) ) {
			return self::response( [ 'turn_id' => $id, 'status' => 'context', 'context' => $result['context'] ] );
		}
		if ( in_array( $result['status'] ?? '', [ 'validate', 'completed' ], true ) && is_array( $result['snapshot'] ?? null ) ) {
			$previous = $result['snapshot'];
			$current = self::snapshot( $settings, $session, '', array_column( $previous['sources'] ?? [], 'id' ), $token );
			if ( self::settings() !== $settings || $current !== $previous || microtime( true ) - $started >= 45 ) { return self::cancel( $id, $session ); }
			if ( 'validate' === $result['status'] ) {
				$result = YoRo_Support_Transport::request( 'POST', '/turns/' . $id . '/complete', [ 'snapshot' => $current ], $session );
			}
			if ( is_wp_error( $result ) || 'completed' !== ( $result['status'] ?? '' ) || self::settings() !== $settings ) { return self::unavailable(); }
			// Revalidate once more immediately before exposing a durable answer (including replay).
			if ( self::snapshot( $settings, $session, '', array_column( $previous['sources'], 'id' ), $token ) !== $previous ) { return self::unavailable(); }
			return self::response( [ 'turn_id' => $id, 'status' => 'completed', 'answer' => self::safe_answer( $result['answer'] ?? [], $previous ), 'private' => ! empty( $previous['context']['access_id'] ) ] );
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

	private static function v2( array $settings ) { return 2 === ( $settings['protocol'] ?? null ); }
	private static function snapshot( array $settings, array $session, $query = '', $ids = null, $token = '' ) {
		if ( self::v2( $settings ) ) { return YoRo_Support_Context::snapshot( $settings, $session, $query, $ids, $token ); }
		return '' === $token ? YoRo_Support_Facts::snapshot( $settings, $query, $ids ) : self::unavailable();
	}
	private static function control_session( $request ) {
		$cookie = (string) ( $_COOKIE[ self::cookie_name() ] ?? '' );
		$session = YoRo_Support_Transport::open( $cookie );
		if ( ! $session || ! self::v2( self::settings() ) || (int) $session['revision'] !== (int) self::settings()['revision']
			|| ! hash_equals( YoRo_Support_Transport::csrf( $cookie ), (string) $request->get_header( 'X-YoRo-Support-CSRF' ) ) ) { return self::unavailable(); }
		$valid = YoRo_Support_Transport::request( 'GET', '/sessions/current', [], $session );
		return is_wp_error( $valid ) || 2 !== ( $valid['protocol'] ?? null ) ? self::unavailable() : $session;
	}
	public static function verification( $request ) {
		$session = self::control_session( $request );
		if ( is_wp_error( $session ) ) { return $session; }
		$action = $request->get_param( 'action' );
		$allowed = [ 'send' => [ 'action', 'email' ], 'confirm' => [ 'action', 'challenge_id', 'code' ], 'current' => [ 'action' ], 'revoke' => [ 'action' ] ];
		if ( ! is_string( $action ) || ! isset( $allowed[ $action ] ) || ! self::fields( $request, $allowed[ $action ] ) ) { return self::unavailable(); }
		$body = [];
		if ( 'send' === $action ) {
			$email = $request->get_param( 'email' );
			if ( ! is_string( $email ) || strlen( $email ) > 254 || ! is_email( $email ) ) { return self::unavailable(); }
			$body = [ 'email' => $email ]; $method = 'POST'; $path = '/verification';
		} elseif ( 'confirm' === $action ) {
			$body = [ 'challenge_id' => $request->get_param( 'challenge_id' ), 'code' => $request->get_param( 'code' ) ];
			if ( ! is_string( $body['challenge_id'] ) || ! is_string( $body['code'] ) || ! preg_match( '/^[0-9]{6}$/', $body['code'] ) ) { return self::unavailable(); }
			$method = 'POST'; $path = '/verification/confirm';
		} elseif ( in_array( $action, [ 'current', 'revoke' ], true ) ) {
			$method = 'current' === $action ? 'GET' : 'DELETE'; $path = '/verification/current';
		} else { return self::unavailable(); }
		$result = YoRo_Support_Transport::request( $method, $path, $body, $session );
		return is_wp_error( $result ) ? $result : self::response( $result );
	}
	public static function context( $request ) {
		$session = self::control_session( $request );
		if ( is_wp_error( $session ) ) { return $session; }
		$settings = self::settings(); $kind = $request->get_param( 'kind' );
		$allowed = 'products' === $kind ? [ 'kind', 'query' ] : ( 'orders' === $kind ? [ 'kind', 'page' ] : [ 'kind', 'params' ] );
		if ( ! self::fields( $request, $allowed ) ) { return self::unavailable(); }
		if ( 'products' === $kind ) {
			$capabilities = YoRo_Support_Context::capabilities( $settings );
			if ( ! $capabilities['products'] && ! $capabilities['quote'] && ! $capabilities['coupons'] && ! $capabilities['payments'] ) { return self::unavailable(); }
			$query = $request->get_param( 'query' ) ?? '';
			if ( ! is_string( $query ) || mb_strlen( $query ) > 120 ) { return self::unavailable(); }
			$results = YoRo_Support_Facts::snapshot( $settings, $query );
			return self::response( [ 'products' => array_values( array_map( static function ( $source ) {
				return [ 'id' => (int) substr( $source['id'], 8 ), 'name' => $source['title'], 'kind' => $source['product']['kind'], 'price' => $source['product']['price'], 'options' => $source['product']['options'] ];
			}, array_filter( $results['sources'], static function ( $source ) { return 'product' === $source['type']; } ) ) ) ] );
		}
		if ( 'orders' === $kind ) {
			$proof = YoRo_Support_Context::authorize( $session, 'orders' );
			if ( is_wp_error( $proof ) ) { return $proof; }
			$page = $request->get_param( 'page' ) ?? 1;
			$result = YoRo_Support_Commerce::orders( $proof, $page );
		} else {
			$params = $request->get_param( 'params' );
			if ( ! is_array( $params ) ) { return self::unavailable(); }
			$result = YoRo_Support_Context::create( $settings, $session, $kind, $params );
		}
		return is_wp_error( $result ) ? $result : self::response( $result );
	}

	private static function fields( $request, array $allowed ) {
		return [] === array_diff( array_keys( $request->get_params() ), $allowed );
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
		require_once __DIR__ . '/class-yoro-support-view.php';
		YoRo_Support_View::widget( self::settings() );
	}
	public static function admin_assets( $hook ) {
		if ( 'settings_page_yoro-customer-support' !== $hook ) { return; }
		wp_enqueue_style( 'yoro-support-admin', plugins_url( 'assets/css/yoro-support-admin.css', YORO_AI_CORE_FILE ), [], YORO_AI_CORE_VERSION );
		wp_enqueue_script( 'yoro-support-admin', plugins_url( 'assets/js/yoro-support-admin.js', YORO_AI_CORE_FILE ), [], YORO_AI_CORE_VERSION, [ 'in_footer' => true, 'strategy' => 'defer' ] );
	}
	public static function settings_page() {
		require_once __DIR__ . '/class-yoro-support-admin.php';
		YoRo_Support_Admin::render();
	}
}
