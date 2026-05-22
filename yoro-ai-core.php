<?php
/**
 * Plugin Name: YoRo AI Core
 * Description: Core runtime and execution layer for YoRo AI.
 * Version: 0.1.5
 * Author: YoOhw
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
require_once YORO_AI_CORE_PATH . 'includes/class-yoro-ai-core.php';

register_activation_hook( YORO_AI_CORE_FILE, [ 'YoRo_DB', 'create_tables' ] );

add_action( 'init', [ 'YoRo_AI_Core', 'init' ] );
