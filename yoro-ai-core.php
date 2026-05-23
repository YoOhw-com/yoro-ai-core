<?php
/**
 * Plugin Name: YoRo AI Core
 * Description: AI operations assistant runtime for WooCommerce stores.
 * Version: 0.1.5
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
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

function yoro_ai_core_has_woocommerce() {
	return class_exists( 'WooCommerce' ) && function_exists( 'WC' );
}

function yoro_ai_core_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	?>
	<div class="notice notice-error">
		<p>
			<?php esc_html_e( 'YoRo AI Core requires WooCommerce to be installed and active.', 'yoro-ai-core' ); ?>
		</p>
	</div>
	<?php
}

function yoro_ai_core_boot() {
	if ( ! yoro_ai_core_has_woocommerce() ) {
		add_action( 'admin_notices', 'yoro_ai_core_missing_woocommerce_notice' );
		return;
	}

	YoRo_AI_Core::init();
}

add_action( 'init', 'yoro_ai_core_boot' );
