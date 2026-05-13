<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Cloud_Client implements YoRo_AI_Client_Interface {

	public function parse_action( array $payload ) {
		return $this->not_ready( 'yoro_cloud_not_ready', __( 'YoRo Cloud parser endpoint is reserved for the next phase.', 'yoro-agent-core' ) );
	}

	public function parse_intent( array $payload ) {
		return $this->not_ready( 'yoro_cloud_intent_not_ready', __( 'YoRo Cloud intent parser endpoint is reserved for the next phase.', 'yoro-agent-core' ) );
	}

	public function plan_workflow( array $payload ) {
		return $this->not_ready( 'yoro_cloud_workflow_not_ready', __( 'YoRo Cloud workflow planner is not ready.', 'yoro-agent-core' ) );
	}

	public function generate_suggestions( array $payload ) {
		return $this->not_ready( 'yoro_cloud_suggestions_not_ready', __( 'YoRo Cloud suggestions endpoint is reserved for the next phase.', 'yoro-agent-core' ) );
	}

	public function compose_email( array $payload ) {
		return $this->not_ready( 'yoro_cloud_email_not_ready', __( 'YoRo Cloud email compose endpoint is reserved for the next phase.', 'yoro-agent-core' ) );
	}

	private function not_ready( $code, $message ) {
		return new WP_Error( $code, $message );
	}
}
