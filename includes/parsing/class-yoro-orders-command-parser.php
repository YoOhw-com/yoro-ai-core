<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Command_Parser {

	public static function parse_early( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_orders_update_status_missing_status',
			'parse_orders_bulk_update_status_with_note',
			'parse_multi_action_hold_and_note',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	public static function parse_write( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_orders_update_status',
			'parse_orders_hold',
			'parse_orders_add_private_note_template',
			'parse_orders_add_note',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	public static function parse_read( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_orders_get_details_by_id',
			'parse_orders_find_by_identity',
			'parse_customers_get_summary',
			'parse_orders_refund_preview',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	public static function parse_surface_fallback( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_store_get_daily_briefing',
			'parse_store_get_business_summary',
			'parse_store_get_insights',
			'parse_workflow_business_summary_preview',
			'parse_workflow_fulfillment_followup_preview',
			'parse_workflow_customer_followup_preview',
			'parse_workflow_operations_attention_preview',
			'parse_workflow_inventory_pressure_preview',
			'parse_products_get_inventory_queue',
			'parse_operations_get_fulfillment_queue',
			'parse_operations_get_attention_queue',
			'parse_operations_get_summary',
			'parse_customers_get_service_queue',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_store_get_daily_briefing( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_daily_briefing_anchor_request( $normalized ) ) {
			return null;
		}

		$has_daily_briefing_intent = (
			false !== strpos( $normalized, 'daily briefing' ) ||
			false !== strpos( $normalized, 'weekly briefing' ) ||
			false !== strpos( $normalized, 'monthly briefing' ) ||
			false !== strpos( $normalized, 'daily brief' ) ||
			false !== strpos( $normalized, 'weekly brief' ) ||
			false !== strpos( $normalized, 'monthly brief' ) ||
			false !== strpos( $normalized, 'today briefing' ) ||
			false !== strpos( $normalized, 'this week briefing' ) ||
			false !== strpos( $normalized, 'this month briefing' ) ||
			false !== strpos( $normalized, 'today brief' ) ||
			false !== strpos( $normalized, 'this week brief' ) ||
			false !== strpos( $normalized, 'this month brief' ) ||
			false !== strpos( $normalized, 'brief of this week' ) ||
			false !== strpos( $normalized, 'brief of this month' ) ||
			false !== strpos( $normalized, 'brief for this week' ) ||
			false !== strpos( $normalized, 'brief for this month' ) ||
			false !== strpos( $normalized, 'today s briefing' ) ||
			false !== strpos( $normalized, 'today\'s briefing' ) ||
			false !== strpos( $normalized, 'store briefing' ) ||
			false !== strpos( $normalized, 'store brief' ) ||
			false !== strpos( $normalized, 'daily store briefing' ) ||
			false !== strpos( $normalized, 'weekly store briefing' ) ||
			false !== strpos( $normalized, 'monthly store briefing' ) ||
			false !== strpos( $normalized, 'daily store brief' ) ||
			false !== strpos( $normalized, 'weekly store brief' ) ||
			false !== strpos( $normalized, 'monthly store brief' ) ||
			false !== strpos( $normalized, 'give me today\'s briefing' ) ||
			false !== strpos( $normalized, 'give me today s briefing' ) ||
			false !== strpos( $normalized, 'show me today\'s briefing' ) ||
			false !== strpos( $normalized, 'show me today s briefing' ) ||
			false !== strpos( $normalized, 'give me the daily briefing' ) ||
			false !== strpos( $normalized, 'show me the daily briefing' ) ||
			false !== strpos( $normalized, 'give me the weekly briefing' ) ||
			false !== strpos( $normalized, 'show me the weekly briefing' ) ||
			false !== strpos( $normalized, 'give me the monthly briefing' ) ||
			false !== strpos( $normalized, 'show me the monthly briefing' ) ||
			false !== strpos( $normalized, 'give me the weekly brief' ) ||
			false !== strpos( $normalized, 'show me the weekly brief' ) ||
			false !== strpos( $normalized, 'give me the monthly brief' ) ||
			false !== strpos( $normalized, 'show me the monthly brief' ) ||
			false !== strpos( $normalized, 'show me the brief of this week' ) ||
			false !== strpos( $normalized, 'show me the brief of this month' ) ||
			false !== strpos( $normalized, 'show me the brief for this week' ) ||
			false !== strpos( $normalized, 'show me the brief for this month' )
		);

		if ( ! $has_daily_briefing_intent ) {
			return null;
		}

		$params = [
			'window' => 'today',
			'limit'  => 1,
		];

		if ( false !== strpos( $normalized, 'this week' ) ) {
			$params['window'] = 'this_week';
		} elseif ( false !== strpos( $normalized, 'weekly' ) ) {
			$params['window'] = 'last_7_days';
		} elseif ( false !== strpos( $normalized, 'this month' ) ) {
			$params['window'] = 'this_month';
		} elseif ( false !== strpos( $normalized, 'monthly' ) ) {
			$params['window'] = 'last_30_days';
		}

		return self::parsed_action(
			'store.get_daily_briefing',
			$params,
			$command,
			$normalized,
			'rule.store.get_daily_briefing'
		);
	}

	private static function parse_store_get_business_summary( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_business_summary_anchor_request( $normalized ) ) {
			return null;
		}

		$has_business_summary_intent = (
			false !== strpos( $normalized, 'business summary' ) ||
			false !== strpos( $normalized, 'business overview' ) ||
			false !== strpos( $normalized, 'business performance' ) ||
			false !== strpos( $normalized, 'store performance' ) ||
			false !== strpos( $normalized, 'show me the business' ) ||
			false !== strpos( $normalized, 'show the business' ) ||
			false !== strpos( $normalized, 'tell me the business' ) ||
			false !== strpos( $normalized, 'summary the business' ) ||
			false !== strpos( $normalized, 'summarize the business' ) ||
			false !== strpos( $normalized, 'summary of the business' ) ||
			false !== strpos( $normalized, 'summary of business' ) ||
			false !== strpos( $normalized, 'the business this week' ) ||
			false !== strpos( $normalized, 'the business this month' ) ||
			false !== strpos( $normalized, 'how is the business doing' ) ||
			false !== strpos( $normalized, 'how is our business doing' )
		);

		if ( ! $has_business_summary_intent ) {
			return null;
		}

		$params = [
			'window' => 'last_7_days',
			'focus'  => 'overview',
		];

		if ( false !== strpos( $normalized, 'today' ) ) {
			$params['window'] = 'today';
		} elseif ( false !== strpos( $normalized, 'yesterday' ) ) {
			$params['window'] = 'yesterday';
		} elseif (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'monthly' )
		) {
			$params['window'] = 'last_30_days';
		} elseif ( false !== strpos( $normalized, 'this month' ) ) {
			$params['window'] = 'this_month';
		} elseif (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			$params['window'] = 'last_7_days';
		} elseif ( false !== strpos( $normalized, 'this week' ) ) {
			$params['window'] = 'this_week';
		}

		if (
			false !== strpos( $normalized, 'operations' ) ||
			false !== strpos( $normalized, 'backlog' )
		) {
			$params['focus'] = 'operations';
		} elseif (
			false !== strpos( $normalized, 'customers' ) ||
			false !== strpos( $normalized, 'customer' )
		) {
			$params['focus'] = 'customers';
		} elseif (
			false !== strpos( $normalized, 'products' ) ||
			false !== strpos( $normalized, 'inventory' )
		) {
			$params['focus'] = 'products';
		}

		return self::parsed_action(
			'store.get_business_summary',
			$params,
			$command,
			$normalized,
			'rule.store.get_business_summary'
		);
	}

	private static function parse_store_get_insights( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_store_insights_anchor_request( $normalized ) ) {
			return null;
		}

		$has_insights_intent = (
			false !== strpos( $normalized, 'store insights' ) ||
			false !== strpos( $normalized, 'shop insights' ) ||
			false !== strpos( $normalized, 'business insights' ) ||
			false !== strpos( $normalized, 'order customer product insights' ) ||
			false !== strpos( $normalized, 'orders customers products insight' ) ||
			false !== strpos( $normalized, 'customer product order insights' )
		);

		if ( ! $has_insights_intent ) {
			return null;
		}

		$params = [
			'window' => 'last_7_days',
			'limit'  => 5,
		];

		if ( false !== strpos( $normalized, 'today' ) ) {
			$params['window'] = 'today';
		} elseif ( false !== strpos( $normalized, 'yesterday' ) ) {
			$params['window'] = 'yesterday';
		} elseif (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'this month' ) ||
			false !== strpos( $normalized, 'monthly' )
		) {
			$params['window'] = 'last_30_days';
		} elseif (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'this week' ) ||
			false !== strpos( $normalized, 'weekly' )
		) {
			$params['window'] = 'last_7_days';
		}

		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			$params['limit'] = min( max( absint( $matches[1] ), 1 ), 20 );
		}

		return self::parsed_action(
			'store.get_insights',
			$params,
			$command,
			$normalized,
			'rule.store.get_insights'
		);
	}

	private static function parse_products_get_inventory_queue( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_inventory_queue_anchor_request( $normalized ) ) {
			return null;
		}

		$has_inventory_intent = (
			false !== strpos( $normalized, 'inventory queue' ) ||
			false !== strpos( $normalized, 'stock queue' ) ||
			false !== strpos( $normalized, 'inventory attention' ) ||
			false !== strpos( $normalized, 'stock problems' ) ||
			false !== strpos( $normalized, 'inventory problems' ) ||
			false !== strpos( $normalized, 'need restock' ) ||
			false !== strpos( $normalized, 'needs restock' ) ||
			false !== strpos( $normalized, 'need restocking' ) ||
			false !== strpos( $normalized, 'needs restocking' ) ||
			false !== strpos( $normalized, 'products need restock' ) ||
			false !== strpos( $normalized, 'products need restocking' ) ||
			false !== strpos( $normalized, 'low stock products' ) ||
			false !== strpos( $normalized, 'out of stock products' ) ||
			false !== strpos( $normalized, 'out-of-stock products' ) ||
			false !== strpos( $normalized, 'products on backorder' ) ||
			false !== strpos( $normalized, 'backorder products' )
		);

		if ( ! $has_inventory_intent ) {
			return null;
		}

		$params = [
			'limit' => 5,
			'focus' => 'overview',
		];

		if ( false !== strpos( $normalized, 'low stock' ) ) {
			$params['focus'] = 'low_stock';
		} elseif (
			false !== strpos( $normalized, 'out of stock' ) ||
			false !== strpos( $normalized, 'out-of-stock' )
		) {
			$params['focus'] = 'out_of_stock';
		} elseif ( false !== strpos( $normalized, 'backorder' ) ) {
			$params['focus'] = 'backorder';
		}

		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			$params['limit'] = min( max( absint( $matches[1] ), 1 ), 20 );
		}

		return self::parsed_action(
			'products.get_inventory_queue',
			$params,
			$command,
			$normalized,
			'rule.products.get_inventory_queue'
		);
	}

	private static function parse_workflow_business_summary_preview( $command, $normalized, $client_context = [] ) {
		$has_guided_intent = (
			false !== strpos( $normalized, 'prepare the next business review' ) ||
			false !== strpos( $normalized, 'show me the next business signal to review' ) ||
			false !== strpos( $normalized, 'review the next business recommendation' ) ||
			false !== strpos( $normalized, 'prepare the next business follow up' ) ||
			false !== strpos( $normalized, 'prepare the next business follow-up' ) ||
			false !== strpos( $normalized, 'prepare next business review' )
		);

		if ( ! $has_guided_intent ) {
			return null;
		}

		$last_followup = isset( $client_context['last_business_followup'] ) && is_array( $client_context['last_business_followup'] )
			? $client_context['last_business_followup']
			: [];

		if ( empty( $last_followup['recommended_sequence'] ) || ! is_array( $last_followup['recommended_sequence'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'action'             => 'workflow.business_summary_preview',
				'message'            => __( 'Open the business summary first so I can choose the right business signal before preparing the next review step.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'parser_rule'        => 'rule.workflow.business_summary_preview',
				'parser_source'      => 'rule',
				'confidence'         => 0.92,
			];
		}

		return self::parsed_action(
			'workflow.business_summary_preview',
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.workflow.business_summary_preview'
		);
	}

	private static function parse_workflow_fulfillment_followup_preview( $command, $normalized, $client_context = [] ) {
		$has_guided_intent = (
			false !== strpos( $normalized, 'prepare the next fulfillment follow up' ) ||
			false !== strpos( $normalized, 'prepare the next fulfillment follow-up' ) ||
			false !== strpos( $normalized, 'prepare the next delay email' ) ||
			false !== strpos( $normalized, 'draft the next delay email' ) ||
			false !== strpos( $normalized, 'draft next delay email' ) ||
			false !== strpos( $normalized, 'prepare next delay email' ) ||
			false !== strpos( $normalized, 'handle the first fulfillment issue' ) ||
			false !== strpos( $normalized, 'handle the next fulfillment recommendation' ) ||
			false !== strpos( $normalized, 'prepare the next fulfillment recommendation' )
		);

		if ( ! $has_guided_intent ) {
			return null;
		}

		$last_followup = isset( $client_context['last_fulfillment_followup'] ) && is_array( $client_context['last_fulfillment_followup'] )
			? $client_context['last_fulfillment_followup']
			: [];

		if ( empty( $last_followup['queues'] ) || ! is_array( $last_followup['queues'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'action'             => 'workflow.fulfillment_followup_preview',
				'message'            => __( 'Open the fulfillment queue first so I can choose the right fulfillment item before preparing a follow-up.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'parser_rule'        => 'rule.workflow.fulfillment_followup_preview',
				'parser_source'      => 'rule',
				'confidence'         => 0.92,
			];
		}

		return self::parsed_action(
			'workflow.fulfillment_followup_preview',
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.workflow.fulfillment_followup_preview'
		);
	}

	private static function parse_workflow_customer_followup_preview( $command, $normalized, $client_context = [] ) {
		$has_guided_intent = (
			false !== strpos( $normalized, 'prepare the next customer follow up' ) ||
			false !== strpos( $normalized, 'prepare the next customer follow-up' ) ||
			false !== strpos( $normalized, 'draft the next customer follow up' ) ||
			false !== strpos( $normalized, 'draft the next customer follow-up' ) ||
			false !== strpos( $normalized, 'draft the next customer reply' ) ||
			false !== strpos( $normalized, 'prepare the next customer reply' ) ||
			false !== strpos( $normalized, 'prepare the next support reply' ) ||
			false !== strpos( $normalized, 'prepare next support reply' ) ||
			false !== strpos( $normalized, 'handle the next customer recommendation' ) ||
			false !== strpos( $normalized, 'prepare the next customer recommendation' )
		);

		if ( ! $has_guided_intent ) {
			return null;
		}

		$last_followup = isset( $client_context['last_service_followup'] ) && is_array( $client_context['last_service_followup'] )
			? $client_context['last_service_followup']
			: [];

		if ( empty( $last_followup['queues'] ) || ! is_array( $last_followup['queues'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'action'             => 'workflow.customer_followup_preview',
				'message'            => __( 'Open the customer service queue first so I can choose the right follow-up item before preparing a reply.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'parser_rule'        => 'rule.workflow.customer_followup_preview',
				'parser_source'      => 'rule',
				'confidence'         => 0.92,
			];
		}

		return self::parsed_action(
			'workflow.customer_followup_preview',
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.workflow.customer_followup_preview'
		);
	}

	private static function parse_workflow_operations_attention_preview( $command, $normalized, $client_context = [] ) {
		$has_guided_intent = (
			false !== strpos( $normalized, 'prepare the next attention follow up' ) ||
			false !== strpos( $normalized, 'prepare the next attention follow-up' ) ||
			false !== strpos( $normalized, 'handle the next urgent operations issue' ) ||
			false !== strpos( $normalized, 'review the next attention item' ) ||
			false !== strpos( $normalized, 'prepare the next attention recommendation' ) ||
			false !== strpos( $normalized, 'prepare next attention item' )
		);

		if ( ! $has_guided_intent ) {
			return null;
		}

		$last_followup = isset( $client_context['last_attention_followup'] ) && is_array( $client_context['last_attention_followup'] )
			? $client_context['last_attention_followup']
			: [];

		if ( empty( $last_followup['queues'] ) || ! is_array( $last_followup['queues'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'action'             => 'workflow.operations_attention_preview',
				'message'            => __( 'Open the operations attention queue first so I can choose the right attention item before preparing the next review step.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'parser_rule'        => 'rule.workflow.operations_attention_preview',
				'parser_source'      => 'rule',
				'confidence'         => 0.92,
			];
		}

		return self::parsed_action(
			'workflow.operations_attention_preview',
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.workflow.operations_attention_preview'
		);
	}

	private static function parse_workflow_inventory_pressure_preview( $command, $normalized, $client_context = [] ) {
		$has_guided_intent = (
			false !== strpos( $normalized, 'prepare the next inventory review' ) ||
			false !== strpos( $normalized, 'show me the next stock issue to handle' ) ||
			false !== strpos( $normalized, 'review the next inventory recommendation' ) ||
			false !== strpos( $normalized, 'prepare the next inventory follow up' ) ||
			false !== strpos( $normalized, 'prepare the next inventory follow-up' ) ||
			false !== strpos( $normalized, 'prepare next inventory review' )
		);

		if ( ! $has_guided_intent ) {
			return null;
		}

		$last_followup = isset( $client_context['last_inventory_followup'] ) && is_array( $client_context['last_inventory_followup'] )
			? $client_context['last_inventory_followup']
			: [];

		if ( empty( $last_followup['queues'] ) || ! is_array( $last_followup['queues'] ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'action'             => 'workflow.inventory_pressure_preview',
				'message'            => __( 'Open the inventory queue first so I can choose the right stock issue before preparing the next review step.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
				'parser_rule'        => 'rule.workflow.inventory_pressure_preview',
				'parser_source'      => 'rule',
				'confidence'         => 0.92,
			];
		}

		return self::parsed_action(
			'workflow.inventory_pressure_preview',
			[
				'original_command' => $command,
			],
			$command,
			$normalized,
			'rule.workflow.inventory_pressure_preview'
		);
	}

	private static function parse_operations_get_fulfillment_queue( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_fulfillment_queue_anchor_request( $normalized ) ) {
			return null;
		}

		$has_fulfillment_intent = (
			false !== strpos( $normalized, 'fulfillment queue' ) ||
			false !== strpos( $normalized, 'shipping queue' ) ||
			false !== strpos( $normalized, 'shipment queue' ) ||
			false !== strpos( $normalized, 'shipping delays' ) ||
			false !== strpos( $normalized, 'waiting to ship' ) ||
			false !== strpos( $normalized, 'pending shipment' ) ||
			false !== strpos( $normalized, 'pending shipping' ) ||
			false !== strpos( $normalized, 'orders ready to fulfill' ) ||
			false !== strpos( $normalized, 'orders ready for fulfillment' ) ||
			false !== strpos( $normalized, 'processing orders without tracking' ) ||
			false !== strpos( $normalized, 'processing orders missing tracking' ) ||
			false !== strpos( $normalized, 'shipping attention queue' )
		);

		if ( ! $has_fulfillment_intent ) {
			return null;
		}

		$params = [
			'limit' => 5,
		];

		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			$params['limit'] = min( max( absint( $matches[1] ), 1 ), 20 );
		}

		return self::parsed_action(
			'operations.get_fulfillment_queue',
			$params,
			$command,
			$normalized,
			'rule.operations.get_fulfillment_queue'
		);
	}

	private static function parse_operations_get_attention_queue( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_attention_queue_anchor_request( $normalized ) ) {
			return null;
		}

		$has_attention_intent = (
			false !== strpos( $normalized, 'attention queue' ) ||
			false !== strpos( $normalized, 'needs attention' ) ||
			false !== strpos( $normalized, 'need attention' ) ||
			false !== strpos( $normalized, 'anything urgent' ) ||
			false !== strpos( $normalized, 'what needs attention' ) ||
			false !== strpos( $normalized, 'urgent right now' ) ||
			false !== strpos( $normalized, 'what is urgent' ) ||
			false !== strpos( $normalized, 'what is stuck' ) ||
			false !== strpos( $normalized, 'orders needing attention' ) ||
			false !== strpos( $normalized, 'operational queue' ) ||
			false !== strpos( $normalized, 'operations queue' ) ||
			false !== strpos( $normalized, 'stale processing orders' ) ||
			false !== strpos( $normalized, 'overdue on-hold orders' ) ||
			false !== strpos( $normalized, 'overdue on hold orders' )
		);

		if ( ! $has_attention_intent ) {
			return null;
		}

		$params = [
			'limit' => 5,
		];

		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			$params['limit'] = min( max( absint( $matches[1] ), 1 ), 20 );
		}

		if ( preg_match( '/(?:high value|high-value).*(?:over|above|from|>=?)\s*\$?([0-9]+(?:\.[0-9]+)?)/i', $command, $matches ) ) {
			$params['high_value_threshold'] = (float) $matches[1];
		}

		return self::parsed_action(
			'operations.get_attention_queue',
			$params,
			$command,
			$normalized,
			'rule.operations.get_attention_queue'
		);
	}

	private static function parse_customers_get_service_queue( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_customer_service_queue_anchor_request( $normalized ) ) {
			return null;
		}

		$has_service_queue_intent = (
			false !== strpos( $normalized, 'customer service queue' ) ||
			false !== strpos( $normalized, 'customer support queue' ) ||
			false !== strpos( $normalized, 'support queue' ) ||
			false !== strpos( $normalized, 'who needs follow up' ) ||
			false !== strpos( $normalized, 'who needs follow-up' ) ||
			false !== strpos( $normalized, 'customers waiting for a response' ) ||
			false !== strpos( $normalized, 'customers waiting for response' ) ||
			false !== strpos( $normalized, 'customer issues' ) ||
			false !== strpos( $normalized, 'customer follow up queue' ) ||
			false !== strpos( $normalized, 'customer follow-up queue' ) ||
			false !== strpos( $normalized, 'customers needing follow up' ) ||
			false !== strpos( $normalized, 'customers needing follow-up' ) ||
			false !== strpos( $normalized, 'orders needing customer follow up' ) ||
			false !== strpos( $normalized, 'orders needing customer follow-up' )
		);

		if ( ! $has_service_queue_intent ) {
			return null;
		}

		$params = [
			'limit' => 5,
		];

		if ( preg_match( '/(?:top|first|latest|show)\s+(\d{1,2})/i', $command, $matches ) ) {
			$params['limit'] = min( max( absint( $matches[1] ), 1 ), 20 );
		}

		return self::parsed_action(
			'customers.get_service_queue',
			$params,
			$command,
			$normalized,
			'rule.customers.get_service_queue'
		);
	}

	private static function parse_operations_get_summary( $command, $normalized, $client_context = [] ) {
		if ( ! self::looks_like_operations_summary_anchor_request( $normalized ) ) {
			return null;
		}

		$has_operations_intent = (
			false !== strpos( $normalized, 'operations summary' ) ||
			false !== strpos( $normalized, 'operation summary' ) ||
			false !== strpos( $normalized, 'operations overview' ) ||
			false !== strpos( $normalized, 'store summary' ) ||
			false !== strpos( $normalized, 'store overview' ) ||
			false !== strpos( $normalized, 'store update' ) ||
			false !== strpos( $normalized, 'store health' ) ||
			false !== strpos( $normalized, 'how is the store doing' ) ||
			false !== strpos( $normalized, 'how is store doing' ) ||
			false !== strpos( $normalized, 'how are we doing' ) ||
			false !== strpos( $normalized, 'today s store overview' ) ||
			false !== strpos( $normalized, 'todays store overview' ) ||
			false !== strpos( $normalized, 'what\'s new this week' ) ||
			false !== strpos( $normalized, 'what s new this week' ) ||
			false !== strpos( $normalized, 'whats new this week' ) ||
			false !== strpos( $normalized, 'what is new this week' ) ||
			false !== strpos( $normalized, 'tell me what\'s new this week' ) ||
			false !== strpos( $normalized, 'tell me what s new this week' ) ||
			false !== strpos( $normalized, 'what\'s new this month' ) ||
			false !== strpos( $normalized, 'what s new this month' ) ||
			false !== strpos( $normalized, 'whats new this month' ) ||
			false !== strpos( $normalized, 'what is new this month' ) ||
			false !== strpos( $normalized, 'tell me what\'s new this month' ) ||
			false !== strpos( $normalized, 'tell me what s new this month' ) ||
			false !== strpos( $normalized, 'daily summary' ) ||
			false !== strpos( $normalized, 'daily operations' ) ||
			false !== strpos( $normalized, 'backlog summary' ) ||
			false !== strpos( $normalized, 'queue summary' ) ||
			(
				false !== strpos( $normalized, 'summary' ) &&
				(
					false !== strpos( $normalized, 'operations' ) ||
					false !== strpos( $normalized, 'backlog' ) ||
					false !== strpos( $normalized, 'queue' ) ||
					false !== strpos( $normalized, 'store' )
				)
			)
		);

		if ( ! $has_operations_intent ) {
			return null;
		}

		$params = [
			'window' => 'today',
			'focus'  => 'overview',
		];

		if (
			false !== strpos( $normalized, 'backlog' ) ||
			false !== strpos( $normalized, 'queue' )
		) {
			$params['focus'] = 'backlog';
		}

		if ( false !== strpos( $normalized, 'yesterday' ) ) {
			$params['window'] = 'yesterday';
		} elseif (
			false !== strpos( $normalized, 'last 30 days' ) ||
			false !== strpos( $normalized, 'last thirty days' ) ||
			false !== strpos( $normalized, 'monthly' ) ||
			false !== strpos( $normalized, 'month summary' )
		) {
			$params['window'] = 'last_30_days';
		} elseif ( false !== strpos( $normalized, 'this month' ) ) {
			$params['window'] = 'this_month';
		} elseif (
			false !== strpos( $normalized, 'last 7 days' ) ||
			false !== strpos( $normalized, 'last seven days' ) ||
			false !== strpos( $normalized, 'weekly' ) ||
			false !== strpos( $normalized, 'week summary' )
		) {
			$params['window'] = 'last_7_days';
		} elseif ( false !== strpos( $normalized, 'this week' ) ) {
			$params['window'] = 'this_week';
		} elseif (
			false !== strpos( $normalized, 'today' ) ||
			false !== strpos( $normalized, 'daily' )
		) {
			$params['window'] = 'today';
		}

		return self::parsed_action(
			'operations.get_summary',
			$params,
			$command,
			$normalized,
			'rule.operations.get_summary'
		);
	}

	public static function parse_search( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_orders_find_by_customer',
			'parse_orders_search',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_orders_update_status_missing_status( $command, $normalized, $client_context = [] ) {
		$text_for_match = strtolower( trim( $command . ' ' . $normalized ) );

		$contextual_order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );
		$has_contextual_order_reference = ! empty( $contextual_order_ids );

		if (
			! preg_match( '/\b(change|mark|move|set|update)\b/i', $text_for_match ) ||
			! preg_match( '/\bstatus\b/i', $text_for_match ) ||
			( ! preg_match( '/\border(?:s)?\b/i', $text_for_match ) && ! $has_contextual_order_reference )
		) {
			return null;
		}

		if (
			preg_match( '/\b(show|list|search|find|get|latest|recent|view)\b/i', $text_for_match )
		) {
			return null;
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $text_for_match );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		if ( ! empty( $status ) ) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = ! empty( $contextual_order_ids )
				? $contextual_order_ids
				: self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.update_status',
				'message'            => __( 'Which order should I update?', 'yoro-agent-core' ),
				'params'             => [
					'status' => '',
				],
				'missing'            => [ 'order_ids', 'status' ],
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.orders.update_status.missing_status',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$params = YoRo_Order_Helper::apply_order_ids_to_params(
			[
				'order_ids' => $order_ids,
				'status'    => '',
				'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
			]
		);

		$missing = [ 'status' ];

		YoRo_Conversation_Memory::start_workflow(
			get_current_user_id(),
			'orders.update_status',
			$params,
			$missing,
			'order_status'
		);

		return [
			'success'            => false,
			'matched'            => true,
			'stage'              => 'clarify',
			'action'             => 'orders.update_status',
			'message'            => __( 'What status should I update to?', 'yoro-agent-core' ),
			'params'             => $params,
			'missing'            => $missing,
			'parser_source'      => 'rule',
			'parser_rule'        => 'rule.orders.update_status.missing_status',
			'confidence'         => 1.0,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_orders_bulk_update_status_with_note( $command, $normalized, $client_context = [] ) {
		if (
			false === strpos( $normalized, 'note' ) ||
			(
				false === strpos( $normalized, 'change' ) &&
				false === strpos( $normalized, 'mark' ) &&
				false === strpos( $normalized, 'update' ) &&
				false === strpos( $normalized, 'move' ) &&
				false === strpos( $normalized, 'set' )
			)
		) {
			return null;
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		if ( empty( $status ) ) {
			return null;
		}

		$status = YoRo_Order_Helper::normalize_status_slug( $status );

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return null;
		}

		$note = '';

		if ( preg_match( '/(?:add\s+note|with\s+note|note)\s*:?\s*(.+)$/i', $command, $matches ) ) {
			$note = sanitize_textarea_field( trim( $matches[1] ) );
		}

		if ( empty( $note ) ) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if (
			empty( $order_ids ) &&
			preg_match( '/\b(?:latest|recent)\s+\d+\s+orders?\b/i', $normalized )
		) {
			return null;
		}

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );
		}

		if ( empty( $order_ids ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'Which orders should I update and add the note to?', 'yoro-agent-core' ),
				self::build_order_reference_options( $client_context, $note )
			);
		}

		return self::parsed_action(
			'orders.bulk_update_status_with_note',
			[
				'order_ids' => $order_ids,
				'status'    => $status,
				'note'      => $note,
			],
			$command,
			$normalized,
			'rule.orders.bulk_update_status_with_note'
		);
	}

	private static function parse_multi_action_hold_and_note( $command, $normalized, $client_context ) {
		if (
			false !== strpos( $normalized, 'change' ) ||
			false !== strpos( $normalized, 'mark' ) ||
			false !== strpos( $normalized, 'update' ) ||
			false !== strpos( $normalized, 'move' ) ||
			false !== strpos( $normalized, 'on-hold' ) ||
			false !== strpos( $normalized, 'on hold' )
		) {
			return null;
		}

		if (
			false === strpos( $normalized, 'hold' ) ||
			false === strpos( $normalized, 'note' )
		) {
			return null;
		}

		if ( ! preg_match( '/\b(?:add\s+note|note)\s*:?\s*(.+)$/i', $command, $matches ) ) {
			return null;
		}

		$note = sanitize_textarea_field( trim( $matches[1] ) );

		if ( '' === $note ) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );
		}

		$order_ids = array_values(
			array_filter(
				array_unique(
					array_map( 'absint', $order_ids )
				)
			)
		);

		if ( empty( $order_ids ) ) {
			return self::clarification_response(
				$command,
				$normalized,
				__( 'Which order should I hold and add the note to?', 'yoro-agent-core' ),
				self::build_order_reference_options( $client_context, $note )
			);
		}

		$params = YoRo_Order_Helper::apply_order_ids_to_params(
			[
				'order_ids' => $order_ids,
				'note'      => $note,
			]
		);

		return [
			'success'            => true,
			'matched'            => true,
			'action'             => 'multi.hold_and_note',
			'actions'            => [
				[
					'action' => 'orders.hold',
					'params' => [
						'order_ids' => $params['order_ids'],
					],
				],
				[
					'action' => 'orders.add_note',
					'params' => [
						'order_ids' => $params['order_ids'],
						'note'      => $params['note'],
					],
				],
			],
			'params'             => $params,
			'parser_rule'        => 'rule.multi.orders.hold_and_note',
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_orders_update_status( $command, $normalized, $client_context = [] ) {

		$text_for_match = strtolower( trim( $command . ' ' . $normalized ) );

		/*
		* Do not treat read/search commands as status updates.
		*/
		if (
			preg_match( '/\b(show|list|search|find|get|latest|recent|view)\b/i', $text_for_match ) &&
			preg_match( '/\border(?:s)?\b/i', $text_for_match )
		) {
			return null;
		}

		$contextual_order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );
		$has_contextual_order_reference = ! empty( $contextual_order_ids );

		if ( ! preg_match( '/\border(?:s)?\b/i', $text_for_match ) && ! $has_contextual_order_reference ) {
			return null;
		}

		$has_status_update_verb = preg_match(
			'/\b(change|mark|move|set|update)\b/i',
			$text_for_match
		);

		$has_status_connector = preg_match(
			'/\b(as|to|status|on[- ]hold)\b/i',
			$text_for_match
		);

		if ( ! $has_status_update_verb || ! $has_status_connector ) {
			return null;
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $text_for_match );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = ! empty( $contextual_order_ids )
				? $contextual_order_ids
				: self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $status ) ) {
			if ( ! empty( $order_ids ) ) {
				$params = YoRo_Order_Helper::apply_order_ids_to_params(
					[
						'order_ids' => $order_ids,
						'status'    => '',
						'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
					]
				);

				$missing = [ 'status' ];

				YoRo_Conversation_Memory::start_workflow(
					get_current_user_id(),
					'orders.update_status',
					$params,
					$missing,
					'order_status'
				);

				return [
					'success'            => false,
					'matched'            => true,
					'stage'              => 'clarify',
					'action'             => 'orders.update_status',
					'message'            => __( 'What status should I update to?', 'yoro-agent-core' ),
					'params'             => $params,
					'missing'            => $missing,
					'parser_source'      => 'rule',
					'parser_rule'        => 'rule.orders.update_status.partial',
					'confidence'         => 1.0,
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.update_status',
				'message'            => __( 'Which order should I update?', 'yoro-agent-core' ),
				'params'             => [
					'status' => '',
				],
				'missing'            => [ 'order_ids', 'status' ],
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$status = YoRo_Order_Helper::normalize_status_slug( $status );

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.update_status',
				'message'            => sprintf(
					__( 'The order status "%s" is not available on this store.', 'yoro-agent-core' ),
					$status
				),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.update_status',
				'message'            => __( 'Please provide at least one order ID or open an order first.', 'yoro-agent-core' ),
				'params'             => [
					'status' => $status,
				],
				'missing'            => [ 'order_ids' ],
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.update_status',
			[
				'order_ids' => $order_ids,
				'status'    => $status,
				'note'      => __( 'Order status updated by YoRo Agent.', 'yoro-agent-core' ),
			],
			$command,
			$normalized,
			'rule.orders.update_status'
		);
	}

	private static function parse_orders_hold( $command, $normalized, $client_context = [] ) {
		$contextual_order_ids = self::resolve_order_ids_from_context( $normalized, $client_context );
		$has_contextual_order_reference = ! empty( $contextual_order_ids );

		if ( false === strpos( $normalized, 'hold' ) || ( false === strpos( $normalized, 'order' ) && ! $has_contextual_order_reference ) ) {
			return null;
		}

		// Do not treat search/list commands for on-hold orders as a hold action.
		if (
			false !== strpos( $normalized, 'on-hold orders' ) ||
			false !== strpos( $normalized, 'on hold orders' ) ||
			false !== strpos( $normalized, 'on-hold order' ) ||
			false !== strpos( $normalized, 'on hold order' )
		) {
			return null;
		}

		if (
			preg_match( '/\b(show|list|search|find|get|latest|recent)\b/i', $normalized )
		) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = ! empty( $contextual_order_ids )
				? $contextual_order_ids
				: self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'action'             => 'orders.hold',
				'message'            => __( 'Please provide at least one order ID to hold or open an order first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.hold',
			[
				'order_ids' => $order_ids,
			],
			$command,
			$normalized,
			'rule.orders.hold'
		);
	}

	private static function parse_orders_add_private_note_template( $command, $normalized, $client_context = [] ) {
		if ( false === strpos( $normalized, 'order' ) || false === strpos( $normalized, 'note' ) ) {
			return null;
		}

		$template = '';

		if ( false !== strpos( $normalized, 'suspicious' ) ) {
			$template = 'suspicious';
		} elseif ( false !== strpos( $normalized, 'payment mismatch' ) || false !== strpos( $normalized, 'payment_mismatch' ) ) {
			$template = 'payment_mismatch';
		} elseif (
			false !== strpos( $normalized, 'needs verification' ) ||
			false !== strpos( $normalized, 'need verification' ) ||
			false !== strpos( $normalized, 'verify' )
		) {
			$template = 'needs_verification';
		}

		if ( empty( $template ) ) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'action'             => 'orders.add_private_note_template',
				'message'            => __( 'Please provide at least one order ID or open an order first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$params = YoRo_Order_Helper::apply_order_ids_to_params(
			[
				'order_ids' => $order_ids,
				'template'  => $template,
			]
		);

		return self::parsed_action(
			'orders.add_private_note_template',
			$params,
			$command,
			$normalized,
			'rule.orders.add_private_note_template'
		);
	}

	private static function parse_orders_add_note( $command, $normalized, $client_context = [] ) {

		if ( false === strpos( $normalized, 'note' ) || false === strpos( $normalized, 'order' ) ) {
			return null;
		}

		if ( self::looks_like_bulk_status_note_mutation( $command, $normalized ) ) {
			return null;
		}

		if (
			preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $normalized ) &&
			preg_match( '/\border\s*#?\s*(\d+)\b/i', $command, $explicit_order_match ) &&
			! preg_match( '/:\s*(.+)$/', $command )
		) {
			$order_ids = [ absint( $explicit_order_match[1] ) ];

			$params = YoRo_Order_Helper::apply_order_ids_to_params(
				[
					'order_ids' => $order_ids,
					'note'      => '',
				]
			);

			$missing = [ 'note' ];

			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				'orders.add_note',
				$params,
				$missing,
				'order_note'
			);

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.add_note',
				'message'            => __( 'What note should I add?', 'yoro-agent-core' ),
				'params'             => $params,
				'missing'            => $missing,
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.orders.add_note.partial.explicit_order',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		if (
			preg_match( '/\b(add\s+note|note)\b/i', $normalized ) &&
			preg_match( '/\b(this|that|the)\s+order\b/i', $normalized ) &&
			! preg_match( '/:\s*(.+)$/', $command )
		) {

			$order_ids = self::resolve_current_order_ids_from_context( $client_context );

			if ( empty( $order_ids ) ) {
				return [
					'success'            => false,
					'matched'            => true,
					'stage'              => 'clarify',
					'action'             => 'orders.add_note',
					'message'            => __( 'Which order should I add the note to?', 'yoro-agent-core' ),
					'params'             => [
						'note' => '',
					],
					'missing'            => [ 'order_ids', 'note' ],
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}

			$params = YoRo_Order_Helper::apply_order_ids_to_params(
				[
					'order_ids' => $order_ids,
					'note'      => '',
				]
			);

			$missing = [ 'note' ];

			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				'orders.add_note',
				$params,
				$missing,
				'order_note'
			);

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.add_note',
				'message'            => __( 'What note should I add?', 'yoro-agent-core' ),
				'params'             => $params,
				'missing'            => $missing,
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.orders.add_note.partial',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		if ( ! preg_match( '/add\s+note\s+to\s+orders?(?:\s+(.+?))?\s*:\s*(.+)$/i', $command, $matches ) ) {
			return null;
		}

		$order_part = isset( $matches[1] ) ? trim( $matches[1] ) : '';
		$note       = sanitize_textarea_field( $matches[2] );

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) && '' !== $order_part ) {
			$order_ids = self::extract_order_ids( $order_part );
		}

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) || '' === $note ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.add_note',
				'message'            => __( 'Please provide valid order IDs and note, or open an order first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$params = YoRo_Order_Helper::apply_order_ids_to_params(
			[
				'order_ids' => $order_ids,
				'note'      => $note,
			]
		);

		return self::parsed_action(
			'orders.add_note',
			$params,
			$command,
			$normalized,
			'rule.orders.add_note'
		);
	}

	private static function parse_orders_find_by_identity( $command, $normalized, $client_context = [] ) {
		if ( false === strpos( $normalized, 'order' ) ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'identity' ) &&
			false === strpos( $normalized, 'same email' ) &&
			false === strpos( $normalized, 'same phone' ) &&
			false === strpos( $normalized, 'same ip' ) &&
			false === strpos( $normalized, 'same device' ) &&
			false === strpos( $normalized, 'related' ) &&
			false === strpos( $normalized, 'similar' )
		) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return null;
		}

		$limit = self::extract_requested_limit_from_command( $command, 20, 50 );

		return self::parsed_action(
			'orders.find_by_identity',
			[
				'order_id' => absint( reset( $order_ids ) ),
				'limit'    => $limit,
			],
			$command,
			$normalized,
			'rule.orders.find_by_identity'
		);
	}

	private static function parse_customers_get_summary( $command, $normalized, $client_context = [] ) {
		$params = [
			'limit' => 20,
		];

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$params['email'] = sanitize_email( $matches[0] );
		}

		if ( preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
			$params['phone'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( empty( $params['phone'] ) ) {
			$bare_phone = self::extract_customer_lookup_phone( $command, $normalized );

			if ( '' !== $bare_phone ) {
				$params['phone'] = $bare_phone;
			}
		}

		if ( empty( $params['email'] ) && empty( $params['phone'] ) ) {
			$customer_name = self::extract_customer_name_for_lookup( $command, $normalized );

			if ( '' !== $customer_name ) {
				$params['customer_name'] = $customer_name;
			}
		}

		if (
			empty( $params['email'] ) &&
			empty( $params['phone'] ) &&
			empty( $params['customer_name'] ) &&
			(
				false !== strpos( $normalized, 'this customer' ) ||
				false !== strpos( $normalized, 'that customer' ) ||
				false !== strpos( $normalized, 'same customer' )
			) &&
			! empty( $client_context['current_order_id'] )
		) {
			$params['order_id'] = absint( $client_context['current_order_id'] );
		}

		if (
			! self::looks_like_customer_summary_anchor_request(
				$normalized,
				! empty( $params['email'] ),
				! empty( $params['phone'] ),
				! empty( $params['customer_name'] ),
				! empty( $params['order_id'] )
			)
		) {
			return null;
		}

		$params['limit'] = self::extract_requested_limit_from_command( $command, $params['limit'], 50 );

		if ( empty( $params['email'] ) && empty( $params['phone'] ) && empty( $params['customer_name'] ) && empty( $params['order_id'] ) ) {
			return null;
		}

		return self::parsed_action(
			'customers.get_summary',
			$params,
			$command,
			$normalized,
			'rule.customers.get_summary'
		);
	}

	private static function parse_orders_refund_preview( $command, $normalized, $client_context = [] ) {
		if ( false === strpos( $normalized, 'order' ) ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'refund' ) &&
			false === strpos( $normalized, 'refundable' )
		) {
			return null;
		}

		$order_ids = self::extract_order_ids( $command );

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'action'             => 'orders.refund_preview',
				'message'            => __( 'Please provide an order ID or open an order first for refund preview.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.refund_preview',
			[
				'order_id' => absint( reset( $order_ids ) ),
			],
			$command,
			$normalized,
			'rule.orders.refund_preview'
		);
	}

	private static function parse_orders_get_details_by_id( $command, $normalized, $client_context = [] ) {
		$order_ids = self::extract_order_ids( $command );
		$has_contextual_order_anchor = (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'same order' )
		);

		if ( ! self::looks_like_order_details_anchor_request( $normalized, ! empty( $order_ids ), $has_contextual_order_anchor ) ) {
			return null;
		}

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return null;
		}

		return self::parsed_action(
			'orders.get_details',
			[
				'order_ids' => $order_ids,
			],
			$command,
			$normalized,
			'rule.orders.get_details.by_id'
		);
	}

	private static function parse_orders_find_by_customer( $command, $normalized, $client_context = [] ) {
		$params = [
			'limit' => 20,
		];

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$params['email'] = sanitize_email( $matches[0] );
		}

		if ( preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
			$params['phone'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( empty( $params['phone'] ) ) {
			$bare_phone = self::extract_customer_lookup_phone( $command, $normalized );

			if ( '' !== $bare_phone ) {
				$params['phone'] = $bare_phone;
			}
		}

		if ( preg_match( '/\bcustomer\s*(?:id)?\s*#?:?\s*(\d+)\b/i', $command, $matches ) ) {
			$params['customer_id'] = absint( $matches[1] );
		}

		if (
			empty( $params['email'] ) &&
			empty( $params['phone'] ) &&
			empty( $params['customer_id'] )
		) {
			$customer_name = self::extract_customer_name_for_order_lookup( $command, $normalized );

			if ( '' !== $customer_name ) {
				$params['customer_name'] = $customer_name;
			}
		}

		if (
			empty( $params['email'] ) &&
			empty( $params['phone'] ) &&
			empty( $params['customer_id'] ) &&
			empty( $params['customer_name'] ) &&
			(
				false !== strpos( $normalized, 'this customer' ) ||
				false !== strpos( $normalized, 'that customer' ) ||
				false !== strpos( $normalized, 'same customer' )
			) &&
			! empty( $client_context['current_order_id'] )
		) {
			$params['order_id'] = absint( $client_context['current_order_id'] );
		}

		if (
			! self::looks_like_customer_orders_anchor_request(
				$normalized,
				! empty( $params['email'] ),
				! empty( $params['phone'] ),
				! empty( $params['customer_id'] ),
				! empty( $params['customer_name'] ),
				! empty( $params['order_id'] )
			)
		) {
			return null;
		}

		$params['limit'] = self::extract_requested_limit_from_command( $command, $params['limit'], 50 );

		if (
			empty( $params['email'] ) &&
			empty( $params['phone'] ) &&
			empty( $params['customer_id'] ) &&
			empty( $params['customer_name'] ) &&
			empty( $params['order_id'] )
		) {
			return null;
		}

		return self::parsed_action(
			'orders.find_by_customer',
			$params,
			$command,
			$normalized,
			'rule.orders.find_by_customer'
		);
	}

	private static function parse_orders_search( $command, $normalized, $client_context = [] ) {
		if (
			false === strpos( $normalized, 'search' ) &&
			false === strpos( $normalized, 'show' ) &&
			false === strpos( $normalized, 'find' ) &&
			false === strpos( $normalized, 'list' ) &&
			false === strpos( $normalized, 'latest' ) &&
			false === strpos( $normalized, 'recent' ) &&
			false === strpos( $normalized, 'last' )
		) {
			return null;
		}

		if (
			false === strpos( $normalized, 'order' ) &&
			false === strpos( $normalized, 'orders' )
		) {
			return null;
		}

		$status = [ 'pending', 'processing', 'on-hold' ];

		if ( false !== strpos( $normalized, 'all status' ) || false !== strpos( $normalized, 'all orders' ) ) {
			$status = [];
		} else {
			$detected_status = YoRo_Order_Helper::detect_status_from_text( $normalized );

			if ( ! empty( $detected_status ) ) {
				$status = [ $detected_status ];
			}
		}

		$limit = self::extract_requested_limit_from_command( $command, 10, 50 );

		$params = [
			'status' => $status,
			'limit'  => min( max( $limit, 1 ), 50 ),
		];

		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $command, $matches ) ) {
			$params['email'] = sanitize_email( $matches[0] );
		}

		if ( preg_match( '/\b(?:phone|tel|mobile)\s*:?\s*([+\d][\d\s().\-]{5,})/i', $command, $matches ) ) {
			$params['phone'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\b(?:payment|payment method|gateway|paid by|pay by|via)\s*:?\s*([a-zA-Z0-9_\-\s]+)/i', $command, $matches ) ) {
			$params['payment_method'] = self::normalize_payment_method( $matches[1] );
		}

		if ( preg_match( '/\bfrom\s+(\d{4}-\d{2}-\d{2})\s+(?:to|until)\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
			$params['date_from'] = sanitize_text_field( $matches[1] );
			$params['date_to']   = sanitize_text_field( $matches[2] );
		} elseif ( preg_match( '/\bafter\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
			$params['date_from'] = sanitize_text_field( $matches[1] );
		} elseif ( preg_match( '/\bbefore\s+(\d{4}-\d{2}-\d{2})/i', $command, $matches ) ) {
			$params['date_to'] = sanitize_text_field( $matches[1] );
		}

		if ( preg_match( '/\b(?:customer|customer name|name)\s*:?\s*([a-zA-Z\s.\'-]{2,})/i', $command, $matches ) ) {
			$params['customer_name'] = sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/\b(?:billing country|country)\s*:?\s*([a-zA-Z]{2})\b/i', $command, $matches ) ) {
			$params['billing_country'] = strtoupper( sanitize_text_field( $matches[1] ) );
		}

		if ( preg_match( '/\b(?:total|amount)\s+(?:from|between)\s+([\d.,]+)\s+(?:to|and)\s+([\d.,]+)/i', $command, $matches ) ) {
			$params['total_min'] = (float) str_replace( ',', '', $matches[1] );
			$params['total_max'] = (float) str_replace( ',', '', $matches[2] );
		} elseif ( preg_match( '/\b(?:total|amount)\s+(?:over|above|greater than|more than)\s+([\d.,]+)/i', $command, $matches ) ) {
			$params['total_min'] = (float) str_replace( ',', '', $matches[1] );
		} elseif ( preg_match( '/\b(?:total|amount)\s+(?:under|below|less than)\s+([\d.,]+)/i', $command, $matches ) ) {
			$params['total_max'] = (float) str_replace( ',', '', $matches[1] );
		}

		return self::parsed_action(
			'orders.search',
			$params,
			$command,
			$normalized,
			'rule.orders.search.advanced'
		);
	}

	private static function normalize_payment_method( $value ) {
		$value = strtolower( trim( wp_strip_all_tags( (string) $value ) ) );

		$map = [
			'stripe'              => 'stripe',
			'credit card'         => 'stripe',
			'card'                => 'stripe',
			'visa'                => 'stripe',
			'mastercard'          => 'stripe',

			'paypal'              => 'paypal',
			'pay pal'             => 'paypal',

			'cod'                 => 'cod',
			'cash on delivery'    => 'cod',
			'cash'                => 'cod',

			'bacs'                => 'bacs',
			'bank transfer'       => 'bacs',
			'direct bank transfer'=> 'bacs',
			'wire transfer'       => 'bacs',

			'cheque'              => 'cheque',
			'check'               => 'cheque',
		];

		return $map[ $value ] ?? sanitize_key( $value );
	}

	private static function extract_requested_limit_from_command( $command, $default = 10, $max = 50 ) {
		$default = max( 1, absint( $default ) );
		$max     = max( $default, absint( $max ) );

		$patterns = [
			'/\b(?:limit|top|latest|recent|last|newest|lestet)\s+(\d{1,2})\b/i',
			'/\b(?:show|get|open|list|find|search|load|browse|review)\s+(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\b(\d{1,2})\s+(?:latest|recent|last|newest|lestet)\b/i',
			'/\bfirst\s+(\d{1,2})\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				return min( max( absint( $matches[1] ), 1 ), $max );
			}
		}

		return min( $default, $max );
	}

	private static function resolve_current_order_ids_from_context( $client_context ) {
		if ( ! empty( $client_context['current_order_id'] ) ) {
			$order_id = absint( $client_context['current_order_id'] );

			if ( $order_id > 0 ) {
				return [ $order_id ];
			}
		}

		if ( ! empty( $client_context['last_referenced_order_id'] ) ) {
			$order_id = absint( $client_context['last_referenced_order_id'] );

			if ( $order_id > 0 ) {
				return [ $order_id ];
			}
		}

		return [];
	}

	private static function resolve_order_ids_from_context( $normalized, $client_context ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? $client_context['last_orders']
			: [];

		$last_order_ids = isset( $client_context['last_order_ids'] ) && is_array( $client_context['last_order_ids'] )
			? array_map( 'absint', $client_context['last_order_ids'] )
			: [];

		$current_order_ids = self::resolve_current_order_ids_from_context( $client_context );

		if (
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'the order' )
		) {
			if ( ! empty( $current_order_ids ) ) {
				return $current_order_ids;
			}
		}

		if (
			false !== strpos( $normalized, 'all of them' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'them' )
		) {
			if ( ! empty( $last_orders ) ) {
				return array_values( array_filter( array_map( 'absint', wp_list_pluck( $last_orders, 'id' ) ) ) );
			}

			return array_values( array_filter( $last_order_ids ) );
		}

		if ( preg_match( '/\b(?:latest|recent)\s+(\d{1,2})\s+orders?\b/i', $normalized, $matches ) ) {
			$limit = min( max( absint( $matches[1] ), 1 ), 50 );
			$order_ids = [];

			if ( ! empty( $last_orders ) ) {
				$order_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( array_slice( $last_orders, 0, $limit ), 'id' ) ) ) );
			} elseif ( ! empty( $last_order_ids ) ) {
				$order_ids = array_values( array_filter( array_slice( $last_order_ids, 0, $limit ) ) );
			}

			if ( ! empty( $order_ids ) ) {
				return $order_ids;
			}
		}

		return [];
	}

	private static function looks_like_bulk_status_note_mutation( $command, $normalized ) {
		if ( false === strpos( $normalized, 'note' ) ) {
			return false;
		}

		if (
			false === strpos( $normalized, 'change' ) &&
			false === strpos( $normalized, 'mark' ) &&
			false === strpos( $normalized, 'update' ) &&
			false === strpos( $normalized, 'move' ) &&
			false === strpos( $normalized, 'set' )
		) {
			return false;
		}

		$status = YoRo_Order_Helper::detect_status_from_text( $normalized );

		if ( empty( $status ) ) {
			$status = YoRo_Order_Helper::extract_status_phrase_from_command( $command );
		}

		if ( empty( $status ) ) {
			return false;
		}

		$status = YoRo_Order_Helper::normalize_status_slug( $status );

		if ( ! YoRo_Order_Helper::is_valid_order_status( $status ) ) {
			return false;
		}

		return (
			false !== strpos( $normalized, 'orders' ) ||
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all of them' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			1 === preg_match( '/\b(?:latest|recent)\s+\d{1,2}\s+orders?\b/i', $normalized )
		);
	}

	private static function build_order_reference_options( $client_context, $note = '' ) {
		$last_orders = isset( $client_context['last_orders'] ) && is_array( $client_context['last_orders'] )
			? array_slice( $client_context['last_orders'], 0, 5 )
			: [];

		$options = [];

		foreach ( $last_orders as $order ) {
			if ( empty( $order['id'] ) ) {
				continue;
			}

			$label = '#' . absint( $order['id'] );

			if ( ! empty( $order['status'] ) ) {
				$label .= ' — ' . sanitize_text_field( $order['status'] );
			}

			$options[] = [
				'label'   => $label,
				'command' => 'Add note to order ' . absint( $order['id'] ) . ': ' . $note,
			];
		}

		return $options;
	}

	private static function extract_order_ids( $command ) {
		if ( class_exists( 'YoRo_Order_Helper' ) ) {
			return YoRo_Order_Helper::extract_order_ids_from_text( $command );
		}

		$order_ids = [];

		if ( preg_match_all( '/(?:orders?|order\s+ids?)\s*(?:#?\d+(?:\s*(?:,|and|&)\s*#?\d+)*)/i', $command, $groups ) ) {
			foreach ( $groups[0] as $group ) {
				if ( preg_match_all( '/#?(\d+)\b/', $group, $matches ) ) {
					foreach ( $matches[1] as $id ) {
						$order_ids[] = absint( $id );
					}
				}
			}
		}

		if ( preg_match_all( '/#(\d+)\b/', $command, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$order_ids[] = absint( $id );
			}
		}

		return array_values(
			array_filter(
				array_unique( $order_ids )
			)
		);
	}

	private static function parsed_action( $action, $params, $command, $normalized, $parser_rule ) {
		$registered_action = YoRo_Action_Registry::get( $action );

		return [
			'success'              => true,
			'matched'              => true,
			'action'               => $action,
			'intent'             => [
				'name'   => self::intent_name_from_action( $action ),
				'action' => $action,
			],
			'params'               => $params,
			'parser_rule'          => $parser_rule,
			'action_meta'          => [
				'label'                 => $registered_action['label'] ?? '',
				'risk_level'            => $registered_action['risk_level'] ?? 'low',
				'requires_confirmation' => ! empty( $registered_action['requires_confirmation'] ),
				'write_action'          => ! empty( $registered_action['write_action'] ),
			],
			'original_command'     => $command,
			'normalized_command'   => $normalized,
		];
	}

	private static function intent_name_from_action( $action ) {
		$map = [
			'orders.compose_customer_email' => 'email_customer',
			'orders.add_note'               => 'add_note',
			'orders.hold'                   => 'hold_order',
			'orders.update_status'          => 'update_order_status',
			'orders.search'                 => 'search_orders',
			'orders.get_details'            => 'get_order_details',
			'orders.find_by_customer'       => 'find_customer_orders',
			'operations.get_summary'        => 'operations_summary',
			'operations.get_attention_queue' => 'operations_attention_queue',
			'operations.get_fulfillment_queue' => 'fulfillment_queue',
			'customers.get_service_queue'   => 'customer_service_queue',
			'products.get_inventory_queue'  => 'inventory_queue',
			'store.get_insights'            => 'store_insights',
		];

		return $map[ $action ] ?? sanitize_key( str_replace( '.', '_', $action ) );
	}

	private static function clarification_response( $command, $normalized, $message, $options = [] ) {
		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => $message,
			'options'            => $options,
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function extract_customer_name_for_lookup( $command, $normalized ) {
		$patterns = [
			'/\b(?:customer\s+(?:profile|summary|record|overview)\s+(?:for|of)\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
			'/\b(?:show|open|view|get|load|summarize)\s+(?:me\s+)?([a-zA-Z][a-zA-Z\s.\'\-]{1,80})[\'’]s\s+customer\s+(?:profile|summary|record|overview)\b/i',
			'/\b(?:customer|name)\s*:?\s*([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				$name = self::sanitize_customer_lookup_name( $matches[1] ?? '' );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		if (
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'that customer' ) ||
			false !== strpos( $normalized, 'same customer' )
		) {
			return '';
		}

		return '';
	}

	private static function sanitize_customer_lookup_name( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value, " \t\n\r\0\x0B'’\"" );

		if ( '' === $value ) {
			return '';
		}

		$blocked = [
			'customer',
			'this customer',
			'that customer',
			'same customer',
			'profile',
			'summary',
			'record',
			'overview',
		];

		return in_array( strtolower( $value ), $blocked, true ) ? '' : $value;
	}

	private static function extract_customer_lookup_phone( $command, $normalized ) {
		$has_customer_lookup_anchor = (
			false !== strpos( $normalized, 'customer summary' ) ||
			false !== strpos( $normalized, 'customer profile' ) ||
			false !== strpos( $normalized, 'customer record' ) ||
			false !== strpos( $normalized, 'customer overview' ) ||
			false !== strpos( $normalized, 'orders for' ) ||
			false !== strpos( $normalized, 'customer orders' )
		);

		if ( ! $has_customer_lookup_anchor ) {
			return '';
		}

		if ( preg_match( '/(?:for|customer|summary|profile|record|overview)\s+([+\d][\d\s().\-]{7,})$/i', $command, $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		if ( preg_match( '/([+\d][\d\s().\-]{7,})$/', trim( $command ), $matches ) ) {
			return sanitize_text_field( trim( $matches[1] ) );
		}

		return '';
	}

	private static function extract_customer_name_for_order_lookup( $command, $normalized ) {
		$patterns = [
			'/\b(?:orders?\s+for\s+)([a-zA-Z][a-zA-Z\s.\'\-]{1,80})$/i',
			'/\b([a-zA-Z][a-zA-Z\s.\'\-]{1,80})[\'’]s\s+orders?\b/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				$name = self::sanitize_customer_lookup_name( $matches[1] ?? '' );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		if (
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'that customer' ) ||
			false !== strpos( $normalized, 'same customer' )
		) {
			return '';
		}

		return '';
	}

	private static function looks_like_order_details_anchor_request( $normalized, $has_explicit_order_ids = false, $has_contextual_order_anchor = false ) {
		if (
			false !== strpos( $normalized, 'latest' ) ||
			false !== strpos( $normalized, 'recent' ) ||
			false !== strpos( $normalized, 'last ' ) ||
			false !== strpos( $normalized, 'completed orders' ) ||
			false !== strpos( $normalized, 'pending orders' ) ||
			false !== strpos( $normalized, 'processing orders' ) ||
			false !== strpos( $normalized, 'cancelled orders' ) ||
			false !== strpos( $normalized, 'refunded orders' ) ||
			false !== strpos( $normalized, 'failed orders' )
		) {
			return false;
		}

		if (
			false !== strpos( $normalized, 'orders for ' ) ||
			false !== strpos( $normalized, 'customer orders' ) ||
			false !== strpos( $normalized, 'this customer s orders' ) ||
			false !== strpos( $normalized, 'this customer\'s orders' )
		) {
			return false;
		}

		if (
			false !== strpos( $normalized, 'shipping label' ) ||
			false !== strpos( $normalized, 'shipment' ) ||
			false !== strpos( $normalized, 'packing slip' ) ||
			false !== strpos( $normalized, 'refund' ) ||
			false !== strpos( $normalized, 'refundable' ) ||
			false !== strpos( $normalized, 'update status' ) ||
			false !== strpos( $normalized, 'status to' ) ||
			false !== strpos( $normalized, 'add note' ) ||
			false !== strpos( $normalized, 'remove note' ) ||
			false !== strpos( $normalized, 'compose email' ) ||
			false !== strpos( $normalized, 'email customer' ) ||
			false !== strpos( $normalized, 'send email' ) ||
			false !== strpos( $normalized, 'save email draft' ) ||
			false !== strpos( $normalized, 'sync ' ) ||
			false !== strpos( $normalized, 'connect ' )
		) {
			return false;
		}

		if ( false === strpos( $normalized, 'order' ) && ! $has_contextual_order_anchor ) {
			return false;
		}

		return $has_explicit_order_ids || $has_contextual_order_anchor;
	}

	private static function looks_like_customer_summary_anchor_request( $normalized, $has_email = false, $has_phone = false, $has_customer_name = false, $has_contextual_customer_anchor = false ) {
		if (
			false !== strpos( $normalized, 'customer service') ||
			false !== strpos( $normalized, 'service queue' ) ||
			false !== strpos( $normalized, 'customer orders' ) ||
			false !== strpos( $normalized, 'orders for ' ) ||
			false !== strpos( $normalized, 'this customer s orders' ) ||
			false !== strpos( $normalized, 'this customer\'s orders' )
		) {
			return false;
		}

		$has_summary_signal = (
			false !== strpos( $normalized, 'customer summary' ) ||
			false !== strpos( $normalized, 'customer profile' ) ||
			false !== strpos( $normalized, 'customer record' ) ||
			false !== strpos( $normalized, 'customer overview' ) ||
			false !== strpos( $normalized, 'profile for' ) ||
			false !== strpos( $normalized, 'summary for' ) ||
			false !== strpos( $normalized, 'history' )
		);

		return $has_summary_signal && ( $has_email || $has_phone || $has_customer_name || $has_contextual_customer_anchor );
	}

	private static function looks_like_customer_orders_anchor_request( $normalized, $has_email = false, $has_phone = false, $has_customer_id = false, $has_customer_name = false, $has_contextual_customer_anchor = false ) {
		if (
			false === strpos( $normalized, 'order' ) &&
			false === strpos( $normalized, 'orders' )
		) {
			return false;
		}

		if (
			false !== strpos( $normalized, 'customer summary' ) ||
			false !== strpos( $normalized, 'customer profile' ) ||
			false !== strpos( $normalized, 'customer record' ) ||
			false !== strpos( $normalized, 'customer overview' )
		) {
			return false;
		}

		return $has_email || $has_phone || $has_customer_id || $has_customer_name || $has_contextual_customer_anchor;
	}

	private static function looks_like_daily_briefing_anchor_request( $normalized ) {
		$has_briefing_anchor = (
			false !== strpos( $normalized, 'briefing' ) ||
			false !== strpos( $normalized, 'brief' )
		);

		if ( ! $has_briefing_anchor ) {
			return false;
		}

		$has_window_or_scope_anchor = (
			false !== strpos( $normalized, 'daily' ) ||
			false !== strpos( $normalized, 'weekly' ) ||
			false !== strpos( $normalized, 'monthly' ) ||
			false !== strpos( $normalized, 'today' ) ||
			false !== strpos( $normalized, 'this week' ) ||
			false !== strpos( $normalized, 'this month' ) ||
			false !== strpos( $normalized, 'store' )
		);

		return $has_window_or_scope_anchor;
	}

	private static function looks_like_business_summary_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'business summary' ) ||
			false !== strpos( $normalized, 'business overview' ) ||
			false !== strpos( $normalized, 'business performance' ) ||
			false !== strpos( $normalized, 'store performance' ) ||
			(
				false !== strpos( $normalized, 'business' ) &&
				false !== strpos( $normalized, 'summary' )
			)
		);
	}

	private static function looks_like_store_insights_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'store insights' ) ||
			false !== strpos( $normalized, 'shop insights' ) ||
			false !== strpos( $normalized, 'business insights' ) ||
			false !== strpos( $normalized, 'insights' )
		);
	}

	private static function looks_like_inventory_queue_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'inventory' ) ||
			false !== strpos( $normalized, 'stock' ) ||
			false !== strpos( $normalized, 'backorder' ) ||
			false !== strpos( $normalized, 'restock' )
		);
	}

	private static function looks_like_fulfillment_queue_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'fulfillment' ) ||
			false !== strpos( $normalized, 'shipping queue' ) ||
			false !== strpos( $normalized, 'shipment queue' ) ||
			false !== strpos( $normalized, 'ship' ) ||
			false !== strpos( $normalized, 'tracking' )
		);
	}

	private static function looks_like_attention_queue_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'attention' ) ||
			false !== strpos( $normalized, 'urgent' ) ||
			false !== strpos( $normalized, 'stuck' ) ||
			false !== strpos( $normalized, 'operational queue' ) ||
			false !== strpos( $normalized, 'operations queue' )
		);
	}

	private static function looks_like_customer_service_queue_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'customer service' ) ||
			false !== strpos( $normalized, 'customer support' ) ||
			false !== strpos( $normalized, 'support queue' ) ||
			false !== strpos( $normalized, 'follow up' ) ||
			false !== strpos( $normalized, 'follow-up' ) ||
			false !== strpos( $normalized, 'customers waiting' ) ||
			false !== strpos( $normalized, 'customer issues' )
		);
	}

	private static function looks_like_operations_summary_anchor_request( $normalized ) {
		return (
			false !== strpos( $normalized, 'operations summary' ) ||
			false !== strpos( $normalized, 'operation summary' ) ||
			false !== strpos( $normalized, 'operations overview' ) ||
			false !== strpos( $normalized, 'store summary' ) ||
			false !== strpos( $normalized, 'store overview' ) ||
			false !== strpos( $normalized, 'store update' ) ||
			false !== strpos( $normalized, 'store health' ) ||
			(
				false !== strpos( $normalized, 'summary' ) &&
				(
					false !== strpos( $normalized, 'operations' ) ||
					false !== strpos( $normalized, 'store' ) ||
					false !== strpos( $normalized, 'backlog' ) ||
					false !== strpos( $normalized, 'queue' )
				)
			)
		);
	}
}
