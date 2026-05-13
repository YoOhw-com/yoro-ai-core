<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Workflow_Command_Parser {

	public static function parse( $command, $normalized, $client_context = [] ) {
		if ( ! class_exists( 'YoRo_Workflow_Templates' ) ) {
			return null;
		}

		$template_id = self::detect_template_id( $normalized );

		if ( ! $template_id ) {
			return null;
		}

		$params = [];

		$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( $command );

		if ( ! empty( $order_ids[0] ) ) {
			$params['order_id'] = absint( $order_ids[0] );
		}

		if ( empty( $params['order_id'] ) && ! empty( $client_context['current_order_id'] ) ) {
			$params['order_id'] = absint( $client_context['current_order_id'] );
		}

		if ( empty( $params['order_id'] ) && ! empty( $client_context['last_referenced_order_id'] ) ) {
			$params['order_id'] = absint( $client_context['last_referenced_order_id'] );
		}

		return YoRo_Workflow_Templates::build_parsed(
			$template_id,
			$params,
			$command
		);
	}

	private static function detect_template_id( $normalized ) {
		$normalized = strtolower( (string) $normalized );

		if ( in_array( $normalized, [ 'review suspicious order', 'suspicious order workflow', 'review risk order' ], true ) ) {
			return 'review_suspicious_order';
		}

		if ( in_array( $normalized, [ 'verify customer before fulfillment', 'customer verification workflow', 'verify this customer' ], true ) ) {
			return 'verify_customer_before_fulfillment';
		}

		if ( in_array( $normalized, [ 'refund review', 'review refund', 'refund workflow' ], true ) ) {
			return 'refund_review';
		}

		return '';
	}
}
