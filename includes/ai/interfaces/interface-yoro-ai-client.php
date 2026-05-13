<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YoRo_AI_Client_Interface {

	public function parse_action( array $payload );

	public function parse_intent( array $payload );

	public function plan_workflow( array $payload );

	public function generate_suggestions( array $payload );

	public function compose_email( array $payload );
}
