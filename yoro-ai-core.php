<?php
/**
 * Plugin Name: YoRo Connector for WordPress
 * Description: Securely connect WordPress to YoRo and supported AI clients.
 * Version: 0.2.4
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: YoOhw
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yoro-ai-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yoro_ai_core_plugin_data = get_file_data(
	__FILE__,
	[
		'Version' => 'Version',
	],
	false
);

define(
	'YORO_AI_CORE_VERSION',
	! empty( $yoro_ai_core_plugin_data['Version'] )
		? $yoro_ai_core_plugin_data['Version']
		: '0.1.0'
);

define( 'YORO_AI_CORE_FILE', __FILE__ );
define( 'YORO_AI_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'YORO_AI_CORE_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'YORO_AI_PROVIDER_YORO_AI_API' ) ) {
	define( 'YORO_AI_PROVIDER_YORO_AI_API', 'yoro_ai_api' );
}

require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-db.php';
require_once YORO_AI_CORE_PATH . 'includes/core/class-yoro-capabilities.php';
require_once YORO_AI_CORE_PATH . 'includes/class-yoro-ai-core.php';

function yoro_ai_core_activate() {
	YoRo_DB::create_tables();
	YoRo_Capabilities::install();
}

register_activation_hook( YORO_AI_CORE_FILE, 'yoro_ai_core_activate' );

function yoro_ai_core_deactivate() {
	wp_clear_scheduled_hook( 'yoro_connector_runtime_tick' );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'yoro_connector_runtime_tick', [], 'yoro-ai-core' );
	}
}

register_deactivation_hook( YORO_AI_CORE_FILE, 'yoro_ai_core_deactivate' );

function yoro_ai_core_has_woocommerce() {
	return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
}

function yoro_ai_core_boot() {
	YoRo_AI_Core::init();
}

add_action( 'init', 'yoro_ai_core_boot' );
