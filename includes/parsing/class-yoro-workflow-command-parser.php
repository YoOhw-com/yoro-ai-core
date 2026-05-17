<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Command_Parser {

	public static function parse( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_Workflow_Candidate_Resolver' ) ) {
			return null;
		}

		return YoRo_Workflow_Candidate_Resolver::resolve(
			$command,
			$normalized,
			$client_context
		);
	}
}
