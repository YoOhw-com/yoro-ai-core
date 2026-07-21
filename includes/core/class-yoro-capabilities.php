<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Capabilities {

	const VERSION = '1';
	const VERSION_OPTION = 'yoro_ai_capabilities_version';

	const USE_AI = 'yoro_use_ai';
	const VIEW_STORE_DATA = 'yoro_view_store_data';
	const EXECUTE_ACTIONS = 'yoro_execute_actions';
	const MANAGE_MAILBOX = 'yoro_manage_mailbox';
	const VIEW_AUDIT = 'yoro_view_audit';
	const MANAGE_CONNECTION = 'yoro_manage_connection';
	const MANAGE_ACCESS = 'yoro_manage_access';
	const MANAGE_POLICIES = 'yoro_manage_policies';

	public static function all() {
		return [
			self::USE_AI,
			self::VIEW_STORE_DATA,
			self::EXECUTE_ACTIONS,
			self::MANAGE_MAILBOX,
			self::VIEW_AUDIT,
			self::MANAGE_CONNECTION,
			self::MANAGE_ACCESS,
			self::MANAGE_POLICIES,
		];
	}

	public static function shop_manager() {
		return [
			self::USE_AI,
			self::VIEW_STORE_DATA,
			self::EXECUTE_ACTIONS,
			self::MANAGE_MAILBOX,
			self::VIEW_AUDIT,
		];
	}

	public static function install() {
		self::grant_to_role( 'administrator', self::all() );
		self::grant_to_role( 'shop_manager', self::shop_manager() );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	public static function maybe_install() {
		if ( self::VERSION !== get_option( self::VERSION_OPTION, '' ) ) {
			self::install();
		}
	}

	public static function permission_for_action( $action ) {
		return ! empty( $action['write_action'] ) ? self::EXECUTE_ACTIONS : self::VIEW_STORE_DATA;
	}

	private static function grant_to_role( $role_name, $capabilities ) {
		$role = get_role( $role_name );

		if ( ! $role instanceof WP_Role ) {
			return;
		}

		foreach ( $capabilities as $capability ) {
			$role->add_cap( $capability );
		}
	}
}
