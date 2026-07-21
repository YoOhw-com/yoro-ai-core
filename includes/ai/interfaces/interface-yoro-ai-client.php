<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YoRo_AI_Client_Interface {

	public function send_conversation_message( array $payload );

	public function get_conversation( $conversation_id );

	public function confirm_action( array $payload );

	public function claim_action( array $payload );

	public function record_action_execution( array $payload );

	public function compose_email( array $payload );
}
