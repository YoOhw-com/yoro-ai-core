<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Entity_Validator {

	public static function validate( $parsed ) {
		if ( ! is_array( $parsed ) || empty( $parsed['action'] ) ) {
			return $parsed;
		}

		$action = $parsed['action'];
		$params = $parsed['params'] ?? [];

		// Validate theo từng action
		switch ( $action ) {

			case 'orders.update_status':
				return self::validate_orders_update_status( $parsed, $params );

			case 'orders.hold':
				return self::validate_orders_hold( $parsed, $params );

			case 'orders.add_note':
				return self::validate_orders_add_note( $parsed, $params );

			case 'orders.search':
				return self::validate_orders_search( $parsed, $params );

			case 'email_templates.save':
				return self::validate_email_templates_save( $parsed, $params );
		}

		return $parsed;
	}

	private static function validate_orders_update_status( $parsed, $params ) {
		$params = YoRo_Order_Helper::apply_order_ids_to_params( $params );
		$params = YoRo_Order_Helper::apply_status_to_params(
			$params,
			$parsed['normalized_command'] ?? ''
		);

		$parsed['params'] = $params;

		if ( empty( $params['order_ids'] ) ) {
			return self::error(
				$parsed,
				__( 'Which orders should I update?', 'yoro-agent-core' )
			);
		}

		if ( empty( $params['status'] ) ) {
			return self::error(
				$parsed,
				__( 'What status should I update to?', 'yoro-agent-core' )
			);
		}

		if ( ! YoRo_Order_Helper::is_valid_order_status( $params['status'] ) ) {
			return self::error(
				$parsed,
				sprintf(
					/* translators: %s: order status */
					__( 'The order status "%s" is not available on this store.', 'yoro-agent-core' ),
					$params['status']
				)
			);
		}

		return $parsed;
	}

	private static function validate_orders_hold( $parsed, $params ) {
		$params = YoRo_Order_Helper::apply_order_ids_to_params( $params );
		$parsed['params'] = $params;

		if ( empty( $params['order_ids'] ) ) {
			return self::error(
				$parsed,
				__( 'Which orders should I put on hold?', 'yoro-agent-core' )
			);
		}

		return $parsed;
	}

	private static function validate_orders_add_note( $parsed, $params ) {
		$params = YoRo_Order_Helper::apply_order_ids_to_params( $params );

		if ( isset( $params['note'] ) ) {
			$params['note'] = trim( (string) $params['note'] );
		}

		$parsed['params'] = $params;

		if ( empty( $params['order_ids'] ) ) {
			return self::error(
				$parsed,
				__( 'Which order should I add the note to?', 'yoro-agent-core' )
			);
		}

		if ( empty( $params['note'] ) ) {
			return self::error(
				$parsed,
				__( 'What note should I add?', 'yoro-agent-core' )
			);
		}

		return $parsed;
	}

	private static function validate_orders_search( $parsed, $params ) {

		$normalized = isset( $parsed['normalized_command'] )
			? (string) $parsed['normalized_command']
			: '';

		$params = YoRo_Order_Helper::apply_status_to_params( $params, $normalized );

		if ( ! empty( $params['status'] ) ) {
			$statuses = (array) $params['status'];

			foreach ( $statuses as $status ) {
				if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
					return self::error(
						$parsed,
						sprintf(
							/* translators: %s: order status */
							__( 'The order status "%s" is not available on this store.', 'yoro-agent-core' ),
							$status
						)
					);
				}
			}
		}

		if ( isset( $params['limit'] ) ) {
			$params['limit'] = min( max( absint( $params['limit'] ), 1 ), 50 );
		}

		$parsed['params'] = $params;

		return $parsed;
	}

	private static function validate_email_templates_save( $parsed, $params ) {
		$name    = isset( $params['name'] ) ? trim( (string) $params['name'] ) : '';
		$subject = isset( $params['subject'] ) ? trim( (string) $params['subject'] ) : '';
		$body    = isset( $params['body'] ) ? trim( (string) $params['body'] ) : '';

		if ( empty( $name ) ) {
			return self::error(
				$parsed,
				__( 'Please provide a name for this email template.', 'yoro-agent-core' )
			);
		}

		if ( empty( $subject ) ) {
			return self::error(
				$parsed,
				__( 'Please provide the subject for this email template.', 'yoro-agent-core' )
			);
		}

		if ( empty( $body ) ) {
			return self::error(
				$parsed,
				__( 'Please provide the body for this email template.', 'yoro-agent-core' )
			);
		}

		$params['name']    = sanitize_text_field( $name );
		$params['subject'] = sanitize_text_field( $subject );
		$params['body']    = sanitize_textarea_field( $body );

		$parsed['params'] = $params;

		return $parsed;
	}

	private static function error( $parsed, $message ) {
		return [
			'success' => false,
			'matched' => false,
			'stage'   => 'clarify',
			'message' => $message,
			'parsed'  => $parsed,
		];
	}
}