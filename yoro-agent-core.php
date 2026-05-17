<?php
/**
 * Plugin Name: YoRo Agent Core
 * Description: Core runtime and execution layer for YoRo AI.
 * Version: 0.1.5
 * Author: YoOhw
 * Text Domain: yoro-agent-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$yoro_agent_core_plugin_data = get_file_data(
	__FILE__,
	[
		'Version' => 'Version',
	],
	false
);

define(
	'YORO_AGENT_CORE_VERSION',
	! empty( $yoro_agent_core_plugin_data['Version'] )
		? $yoro_agent_core_plugin_data['Version']
		: '0.1.0'
);

define( 'YORO_AGENT_CORE_FILE', __FILE__ );
define( 'YORO_AGENT_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'YORO_AGENT_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'YORO_AI_PROVIDER_OPENAI_DIRECT', 'openai_direct' );
define( 'YORO_AI_PROVIDER_YORO_CLOUD', 'yoro_cloud' );

require_once YORO_AGENT_CORE_PATH . 'includes/core/class-yoro-db.php';
require_once YORO_AGENT_CORE_PATH . 'includes/class-yoro-agent-core.php';

register_activation_hook( YORO_AGENT_CORE_FILE, [ 'YoRo_DB', 'create_tables' ] );

add_action( 'init', [ 'YoRo_Agent_Core', 'init' ] );
