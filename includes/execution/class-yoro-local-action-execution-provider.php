<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Local_Action_Execution_Provider implements YoRo_Action_Execution_Provider {

	public function provider_kind() {
		return 'local';
	}

	public function assess( $action_name, array $action, $params = [], $context = [] ) {
		return [
			'eligible' => true,
			'reason'   => 'local_default',
		];
	}

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] ) {
		$context = is_array( $context ) ? $context : [];

		if (
			! empty( $context['store_context_pack'] ) &&
			is_array( $context['store_context_pack'] ) &&
			class_exists( 'YoRo_Report_Builder' )
		) {
			return YoRo_Report_Builder::with_store_context_pack(
				$context['store_context_pack'],
				static function() use ( $action, $params ) {
					return call_user_func( $action['callback'], $params );
				}
			);
		}

		return call_user_func( $action['callback'], $params );
	}
}
