<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Semantic_Intent_Router {

	public static function route( $command, $normalized, $client_context = [] ) {
		$intent = self::detect_intent( $normalized );

		if ( empty( $intent ) ) {
			return null;
		}

		$params = self::build_params_for_intent( $intent, $normalized, $command );
		$action = self::map_intent_to_action( $intent, $params );

		if ( empty( $action ) ) {
			return null;
		}

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => $action,
			'params'             => $params,
			'parser_rule'        => 'semantic.' . $intent,
			'parser_source'      => 'semantic',
			'confidence'         => self::confidence_for_intent( $intent, $normalized ),
			'original_command'   => $command,
			'normalized_command' => $normalized,
			'intent'             => [
				'name'       => $intent,
				'confidence' => self::confidence_for_intent( $intent, $normalized ),
				'source'     => 'semantic',
			],
		];
	}

	private static function detect_intent( $normalized ) {
		$catalog = self::intent_catalog();

		foreach ( $catalog as $intent => $config ) {
			$phrases = isset( $config['phrases'] ) && is_array( $config['phrases'] )
				? $config['phrases']
				: [];

			foreach ( $phrases as $phrase ) {
				if ( '' !== $phrase && false !== strpos( $normalized, $phrase ) ) {
					return $intent;
				}
			}
		}

		return '';
	}

	private static function build_params_for_intent( $intent, $normalized, $command ) {
		$params = [];

		switch ( $intent ) {
			case 'business_summary':
				$params['window'] = self::detect_window( $normalized, 'last_7_days' );
				$params['focus']  = self::detect_business_focus( $normalized );
				break;

			case 'operations_overview':
				$params['window'] = self::detect_window( $normalized, 'today' );
				$params['focus']  = self::detect_operations_focus( $normalized );
				break;

			case 'operations_attention':
				$params['limit'] = self::detect_limit( $command, 5 );
				break;

			case 'fulfillment_review':
				$params['limit'] = self::detect_limit( $command, 5 );
				break;

			case 'inventory_review':
				$params['limit'] = self::detect_limit( $command, 5 );
				$params['focus'] = self::detect_inventory_focus( $normalized );
				break;

			case 'customer_service_review':
				$params['limit'] = self::detect_limit( $command, 5 );
				break;
		}

		return $params;
	}

	private static function map_intent_to_action( $intent, $params ) {
		switch ( $intent ) {
			case 'business_summary':
				return 'store.get_business_summary';

			case 'operations_overview':
				return 'operations.get_summary';

			case 'operations_attention':
				return 'operations.get_attention_queue';

			case 'fulfillment_review':
				return 'operations.get_fulfillment_queue';

			case 'inventory_review':
				return 'products.get_inventory_queue';

			case 'customer_service_review':
				return 'customers.get_service_queue';
		}

		return '';
	}

	private static function confidence_for_intent( $intent, $normalized ) {
		$confidence = 0.92;

		if ( 'business_summary' === $intent && false !== strpos( $normalized, 'business' ) ) {
			return 0.97;
		}

		if ( 'operations_overview' === $intent && false !== strpos( $normalized, 'what\'s new' ) ) {
			return 0.9;
		}

		return $confidence;
	}

	private static function detect_window( $normalized, $default ) {
		if ( false !== strpos( $normalized, 'today' ) ) {
			return 'today';
		}

		if ( false !== strpos( $normalized, 'yesterday' ) ) {
			return 'yesterday';
		}

		if (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'this month' ) ||
			false !== strpos( $normalized, 'monthly' )
		) {
			return 'last_30_days';
		}

		if (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'this week' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			return 'last_7_days';
		}

		return $default;
	}

	private static function detect_limit( $command, $default ) {
		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			return min( max( absint( $matches[1] ), 1 ), 20 );
		}

		return $default;
	}

	private static function detect_business_focus( $normalized ) {
		if ( false !== strpos( $normalized, 'operations' ) || false !== strpos( $normalized, 'backlog' ) ) {
			return 'operations';
		}

		if ( false !== strpos( $normalized, 'customers' ) || false !== strpos( $normalized, 'customer' ) ) {
			return 'customers';
		}

		if ( false !== strpos( $normalized, 'products' ) || false !== strpos( $normalized, 'inventory' ) ) {
			return 'products';
		}

		return 'overview';
	}

	private static function detect_operations_focus( $normalized ) {
		if ( false !== strpos( $normalized, 'backlog' ) || false !== strpos( $normalized, 'queue' ) ) {
			return 'backlog';
		}

		return 'overview';
	}

	private static function detect_inventory_focus( $normalized ) {
		if ( false !== strpos( $normalized, 'low stock' ) ) {
			return 'low_stock';
		}

		if ( false !== strpos( $normalized, 'out of stock' ) || false !== strpos( $normalized, 'out-of-stock' ) ) {
			return 'out_of_stock';
		}

		if ( false !== strpos( $normalized, 'backorder' ) ) {
			return 'backorder';
		}

		return 'overview';
	}

	private static function intent_catalog() {
		return [
			'business_summary' => [
				'phrases' => [
					'business summary',
					'business overview',
					'business update',
					'business performance',
					'store performance',
					'show me the business',
					'show the business',
					'tell me the business',
					'summary the business',
					'summarize the business',
					'summary of the business',
					'summary of business',
					'the business this week',
					'the business this month',
					'our business this week',
					'our business this month',
					'how is the business doing',
					'how is our business doing',
					'how is our business this week',
					'how is our business this month',
				],
			],
			'operations_overview' => [
				'phrases' => [
					'operations summary',
					'operation summary',
					'operations overview',
					'store summary',
					'store overview',
					'store health',
					'how is the store doing',
					'how is store doing',
					'how are we doing',
					'what\'s new this week',
					'what s new this week',
					'whats new this week',
					'what is new this week',
					'tell me what\'s new this week',
					'tell me what s new this week',
					'what\'s new this month',
					'what s new this month',
					'whats new this month',
					'what is new this month',
					'tell me what\'s new this month',
					'tell me what s new this month',
					'what\'s going on this week',
					'what s going on this week',
					'whats going on this week',
					'what is going on this week',
					'show me what\'s going on this week',
					'show me what s going on this week',
					'what\'s going on this month',
					'what s going on this month',
					'whats going on this month',
					'what is going on this month',
					'what\'s up today',
					'what s up today',
					'whats up today',
					'what is up today',
					'what\'s up this week',
					'what s up this week',
					'whats up this week',
					'what is up this week',
					'what\'s up this month',
					'what s up this month',
					'whats up this month',
					'what is up this month',
					'store picture',
					'store picture for today',
					'store picture for this week',
					'store picture for this month',
					'give me the store picture',
					'give me the store picture for today',
					'give me the store picture for this week',
					'give me the store picture for this month',
					'weekly store picture',
					'monthly store picture',
					'store look like today',
					'store look like this week',
					'store look like this month',
					'what does the store look like',
					'what does the store look like this week',
					'what does the store look like this month',
					'daily summary',
					'daily operations',
					'store update',
				],
			],
			'operations_attention' => [
				'phrases' => [
					'attention queue',
					'needs attention',
					'need attention',
					'anything urgent',
					'what needs attention',
					'urgent right now',
					'what is urgent',
					'what is stuck',
				],
			],
			'fulfillment_review' => [
				'phrases' => [
					'fulfillment queue',
					'shipping queue',
					'shipment queue',
					'shipment blockers',
					'shipping blockers',
					'shipping delays',
					'waiting to ship',
					'pending shipment',
					'pending shipping',
					'stuck in shipping',
					'looks stuck in shipping',
				],
			],
			'inventory_review' => [
				'phrases' => [
					'inventory queue',
					'stock queue',
					'inventory attention',
					'stock problems',
					'inventory problems',
					'need restock',
					'needs restock',
					'need restocking',
					'needs restocking',
					'products need restock',
					'products need restocking',
					'low stock products',
					'out of stock products',
					'out-of-stock products',
					'products on backorder',
					'backorder products',
					'restock first',
					'should we restock',
				],
			],
			'customer_service_review' => [
				'phrases' => [
					'customer service queue',
					'customer support queue',
					'support queue',
					'who needs follow up',
					'who needs follow-up',
					'customers waiting for a response',
					'customers waiting for response',
					'customer issues',
					'customer follow up queue',
					'customer follow-up queue',
					'customers needing follow up',
					'customers needing follow-up',
					'need a reply',
					'needs a reply',
					'need reply',
					'needs reply',
					'reply to first',
					'who should we reply to',
				],
			],
		];
	}
}
