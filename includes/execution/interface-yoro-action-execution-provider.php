<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YoRo_Action_Execution_Provider {

	public function provider_kind();

	public function assess( $action_name, array $action, $params = [], $context = [] );

	public function execute( $action_name, array $action, $params = [], $context = [], array $decision = [] );
}
