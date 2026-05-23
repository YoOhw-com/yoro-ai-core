<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Catalog_Builder {

	public static function build( $context = [] ) {
		$catalog = [];

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$catalog[] = self::build_action_item( $name, $action, $context );
		}

		return apply_filters(
			'yoro_ai_action_catalog',
			array_values( array_filter( $catalog ) ),
			is_array( $context ) ? $context : []
		);
	}

	public static function build_for_command( $command = '', $context = [] ) {
		$catalog = [];
		$scope   = self::detect_router_scope( $command, $context );
		$profile = self::derive_router_profile( $command, $context, $scope );
		$profile_allowed = self::allowed_actions_for_router_profile( $profile, $scope, $command );

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			if ( ! self::action_is_allowed_for_scope( $name, $scope ) ) {
				continue;
			}

			if ( ! empty( $profile_allowed ) && ! in_array( $name, $profile_allowed, true ) ) {
				continue;
			}

			$catalog[] = self::build_action_item( $name, $action, $context );
		}

		return apply_filters(
			'yoro_ai_action_catalog',
			array_values( array_filter( $catalog ) ),
			$context
		);
	}

	public static function detect_router_scope( $command = '', $context = [] ) {
		$context = is_array( $context ) ? $context : [];
		$command = is_string( $command ) ? trim( $command ) : '';
		$command_shape = self::extract_command_shape( $command, $context );
		$resolved_order_ids = self::resolve_context_order_ids(
			$context,
			self::command_uses_contextual_order_reference( $command )
		);
		$explicit_order_ids = self::extract_explicit_order_ids( $command );
		$memory_reference = isset( $context['memory_context']['reference'] ) && is_array( $context['memory_context']['reference'] )
			? $context['memory_context']['reference']
			: [];
		$plural_context_order_ids = self::resolve_plural_context_order_ids( $command, $context );
		$references_single_order = (
			1 === count( $resolved_order_ids ) ||
			1 === count( $explicit_order_ids ) ||
			(
				! empty( $memory_reference['resolved'] ) &&
				'order' === sanitize_key( $memory_reference['target_type'] ?? '' ) &&
				1 === count( is_array( $memory_reference['target_ids'] ?? null ) ? $memory_reference['target_ids'] : [] )
			)
		);

		if ( class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			if ( YoRo_Command_Shape_Extractor::is_order_query_action_shape( $command_shape ) ) {
				return 'order_query_action_targeted';
			}

			if ( YoRo_Command_Shape_Extractor::is_order_write_missing_target_shape( $command_shape ) ) {
				return 'order_write_missing_targeted';
			}

			if ( YoRo_Command_Shape_Extractor::is_order_list_search_shape( $command_shape ) ) {
				return 'orders_search_targeted';
			}
		}

		if ( self::command_looks_like_customer_targeted_request( $command ) ) {
			return 'customer_targeted';
		}

		if ( self::command_looks_like_email_template_targeted_request( $command ) ) {
			return 'email_template_targeted';
		}

		if ( ! $references_single_order ) {
			if ( count( $plural_context_order_ids ) > 1 ) {
				return 'multi_order_targeted';
			}

			if (
				self::command_looks_like_queue_targeted_request( $command ) &&
				! self::command_shape_has_specific_order_filter( $command_shape )
			) {
				return 'queue_targeted';
			}

			if ( self::command_looks_like_store_summary_targeted_request( $command ) ) {
				return 'store_summary_targeted';
			}

			if ( self::looks_like_orders_search_request( $command ) ) {
				return 'orders_search_targeted';
			}

			if ( self::command_looks_like_queue_targeted_request( $command ) ) {
				return 'queue_targeted';
			}

			return 'default';
		}

		if ( self::command_looks_like_multi_order_request( $command ) ) {
			return 'default';
		}

		if ( self::looks_like_orders_search_request( $command ) ) {
			return 'orders_search_targeted';
		}

		if ( class_exists( 'YoRo_Command_Shape_Extractor' ) && YoRo_Command_Shape_Extractor::is_order_list_search_shape( $command_shape ) ) {
			return 'orders_search_targeted';
		}

		if ( self::command_looks_like_single_order_targeted_request( $command, $context ) ) {
			return 'single_order_targeted';
		}

		return 'single_order_reference';
	}

	public static function build_names( $context = [] ) {
		return array_values(
			array_map(
				function ( $item ) {
					return $item['name'];
				},
				self::build( $context )
			)
		);
	}

	public static function build_router_hints( $command = '', $context = [] ) {
		$context = is_array( $context ) ? $context : [];
		$scope   = self::detect_router_scope( $command, $context );
		$full_catalog = self::build( $context );
		$command_shape = self::extract_command_shape( $command, $context );

		return [
			'catalog_scope'              => $scope,
			'catalog_total_action_count' => count( $full_catalog ),
			'command_shape'              => class_exists( 'YoRo_Command_Shape_Extractor' )
				? YoRo_Command_Shape_Extractor::compact_for_prompt( $command_shape )
				: [],
			'resolved_order_ids'         => self::resolve_context_order_ids(
				$context,
				self::command_uses_contextual_order_reference( $command )
			),
			'router_profile'             => self::derive_router_profile( $command, $context, $scope ),
			'candidate_actions'          => self::derive_candidate_actions( $command, $context, $scope ),
			'candidate_intent_families'  => self::derive_candidate_intent_families( $command, $context, $scope ),
		];
	}

	public static function build_ai_metadata_audit( $context = [] ) {
		$audit = class_exists( 'YoRo_Action_Registry' ) && method_exists( 'YoRo_Action_Registry', 'get_ai_metadata_audit' )
			? YoRo_Action_Registry::get_ai_metadata_audit()
			: [];
		$catalog_names = self::build_names( $context );

		foreach ( $audit as $action_name => &$row ) {
			$row['in_catalog'] = in_array( $action_name, $catalog_names, true );
		}
		unset( $row );

		return $audit;
	}

	private static function extract_command_shape( $command, $context = [] ) {
		$context = is_array( $context ) ? $context : [];

		if ( isset( $context['_command_shape'] ) && is_array( $context['_command_shape'] ) ) {
			return $context['_command_shape'];
		}

		if ( ! class_exists( 'YoRo_Command_Shape_Extractor' ) ) {
			return [];
		}

		return YoRo_Command_Shape_Extractor::extract( $command, $context );
	}

	private static function action_is_allowed_for_scope( $action_name, $scope ) {
		$scope = sanitize_key( $scope );
		$action_name = sanitize_text_field( $action_name );

		if ( 'orders_search_targeted' === $scope ) {
			return 'orders.search' === $action_name;
		}

		if ( in_array( $scope, [ 'order_query_action_targeted', 'order_write_missing_targeted' ], true ) ) {
			return in_array( $action_name, [ 'orders.hold', 'orders.update_status', 'orders.bulk_update_status_with_note' ], true );
		}

		if ( 'customer_targeted' === $scope ) {
			return in_array( $action_name, [ 'customers.get_summary', 'orders.find_by_customer' ], true );
		}

		if ( 'email_template_targeted' === $scope ) {
			return 'email_templates.list' === $action_name;
		}

		if ( ! in_array( $scope, [ 'single_order_reference', 'single_order_targeted' ], true ) ) {
			return true;
		}

		$allowed = [
			'orders.get_details',
			'orders.search',
			'orders.find_by_identity',
			'orders.find_by_customer',
			'orders.refund_preview',
			'orders.email_customer_preview',
			'orders.email_customer_template',
			'orders.use_email_template',
			'orders.compose_customer_email',
			'orders.save_customer_email_draft',
			'orders.get_customer_email_drafts',
			'orders.update_customer_email_draft',
			'orders.delete_customer_email_draft',
			'orders.render_customer_email_preview',
			'orders.send_customer_email',
			'orders.hold',
			'orders.add_note',
			'orders.update_status',
			'orders.bulk_update_status_with_note',
			'workflow.write_email_for_order',
			'workflow.write_and_save_email_draft_for_order',
			'workflow.write_and_send_email_for_order',
			'workflow.write_email_for_orders',
			'workflow.write_and_save_email_drafts_for_orders',
			'workflow.write_and_send_emails_for_orders',
			'workflow.verify_customer_before_fulfillment',
			'workflow.refund_review',
			'workflow.cancel',
		];

		if ( 'single_order_targeted' === $scope ) {
			$allowed = [
				'orders.get_details',
				'orders.find_by_identity',
				'orders.refund_preview',
				'orders.email_customer_preview',
				'orders.email_customer_template',
				'orders.use_email_template',
				'orders.compose_customer_email',
				'orders.save_customer_email_draft',
				'orders.get_customer_email_drafts',
				'orders.update_customer_email_draft',
				'orders.delete_customer_email_draft',
				'orders.render_customer_email_preview',
				'orders.send_customer_email',
				'workflow.write_email_for_order',
				'workflow.write_and_save_email_draft_for_order',
				'workflow.write_and_send_email_for_order',
				'workflow.write_email_for_orders',
				'workflow.write_and_save_email_drafts_for_orders',
				'workflow.write_and_send_emails_for_orders',
				'orders.hold',
				'orders.add_note',
				'orders.update_status',
				'orders.bulk_update_status_with_note',
				'workflow.verify_customer_before_fulfillment',
				'workflow.refund_review',
				'workflow.cancel',
			];
		}

		return in_array( $action_name, $allowed, true );
	}

	private static function command_looks_like_multi_order_request( $command ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return false;
		}

		$normalized = function_exists( 'mb_strtolower' )
			? mb_strtolower( $command, 'UTF-8' )
			: strtolower( $command );

		if ( preg_match( '/\b(all|latest|recent|multiple|many|several)\b/u', $normalized ) ) {
			return true;
		}

		if ( preg_match( '/\b(?:list|show|search|find|open|review)\s+(?:the\s+)?(?:latest\s+|recent\s+|all\s+)?orders\b/u', $normalized ) ) {
			return true;
		}

		return preg_match( '/\b\d+\b/u', $normalized ) > 1;
	}

	private static function command_looks_like_single_order_targeted_request( $command, $context = [] ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		if ( '' === $command ) {
			return false;
		}

		$normalized = function_exists( 'mb_strtolower' )
			? mb_strtolower( $command, 'UTF-8' )
			: strtolower( $command );
		$surface = preg_split( '/[:\n]/u', $normalized, 2 );
		$surface = is_array( $surface ) && isset( $surface[0] ) ? trim( (string) $surface[0] ) : $normalized;

		if ( false !== strpos( $normalized, '@' ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:phone|identity|related|match|same customer|same person)\b/u', $surface ) ) {
			return false;
		}

		$resolved_order_ids = self::resolve_context_order_ids(
			$context,
			self::command_uses_contextual_order_reference( $command )
		);
		$explicit_order_ids = self::extract_explicit_order_ids( $command );

		if ( 1 !== count( $resolved_order_ids ) && 1 !== count( $explicit_order_ids ) ) {
			return false;
		}

		return true;
	}

	private static function command_looks_like_store_summary_targeted_request( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		if ( false !== strpos( $normalized, '@' ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:insight|insights|trend|trends|pattern|patterns)\b/u', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:customer|customers|email|phone)\b/u', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:urgent|attention|follow-up|follow up|reply|replies|restock|inventory|queue)\b/u', $normalized ) ) {
			return false;
		}

		if (
			preg_match( '/\b(?:order|orders|customer|customers|product|products|refund|email|draft|hold|status|queue)\b/u', $normalized ) &&
			! preg_match( '/\b(?:business|store|operations?|brief(?:ing)?|insights?|summary|overview|performance|sales|revenue)\b/u', $normalized )
		) {
			return false;
		}

		return self::looks_like_business_summary_request( $normalized ) || self::looks_like_operations_overview_request( $normalized );
	}

	private static function command_looks_like_customer_targeted_request( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		if ( false === strpos( $normalized, '@' ) && false === strpos( $normalized, 'customer' ) && false === strpos( $normalized, 'phone' ) ) {
			return false;
		}

		return self::looks_like_customer_summary_request( $normalized ) || self::looks_like_customer_orders_request( $normalized );
	}

	private static function command_looks_like_email_template_targeted_request( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		$mentions_templates =
			false !== strpos( $normalized, 'template' ) ||
			false !== strpos( $normalized, 'templates' );

		if ( ! $mentions_templates ) {
			return false;
		}

		$has_list_intent = (bool) preg_match(
			'/\b(show|list|view|open|load|get|see|browse|what)\b/u',
			$normalized
		);

		if ( ! $has_list_intent ) {
			return false;
		}

		if ( preg_match( '/\b(?:save|delete|remove|use|apply|send|write|compose|draft)\b/u', $normalized ) ) {
			return false;
		}

		return true;
	}

	private static function looks_like_customer_summary_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:customer\s+summary|customer\s+profile|customer\s+overview|summary\s+for\s+[^\s]+@[^\s]+|customer\s+record)\b/u',
			$normalized
		);
	}

	private static function looks_like_customer_orders_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:orders?\s+for\s+(?:this|that|same)?\s*customer|this customer(?:\'s| s)?\s+orders|customer\s+orders?|(?:show|find|list|open|review)\s+orders?\s+(?:for|with\s+email)\s+[^\s]+@[^\s]+|orders?\s+with\s+email\s+[^\s]+@[^\s]+)\b/u',
			$normalized
		);
	}

	private static function looks_like_orders_search_request( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		if ( preg_match( '/\b(?:orders?\s+for\s+(?:this|that|same)?\s*customer|this customer(?:\'s| s)?\s+orders|customer\s+orders?)\b/u', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:customer\s+summary|summary\s+for\s+[^\s]+@[^\s]+)\b/u', $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\border\s*#?\s*\d+\b/u', $normalized ) ) {
			return false;
		}

		$is_refunded_order_search = (bool) preg_match(
			'/\b(?:show|get|view|find|list|search|open|review)\b.*\b(?:a|an|one|\d{1,2}|some)?\s*(?:refund|refunded)\s+orders?\b/u',
			$normalized
		);

		if ( preg_match( '/\b(?:update|change|status|hold|refund|note|email|shipment|shipping label|draft|send|save draft|delete)\b/u', $normalized ) && ! $is_refunded_order_search ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:latest|recent|last|newest|lestet|pending|processing|completed|cancelled|canceled|refund|refunded|failed|on[- ]hold|orders?\s+list|show\s+orders|find\s+orders|search\s+orders|list\s+orders|review\s+orders)\b|\b(?:show|get|view|find|list|search|open|review)\s+(?:me\s+)?\d{1,2}\s+(?:refund|refunded)\s+orders?\b|\b\d{1,2}\s+(?:pending|processing|completed|cancelled|canceled|refund|refunded|failed|on[- ]hold)\s+orders?\b/u',
			$normalized
		);
	}

	private static function command_looks_like_queue_targeted_request( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		if ( false !== strpos( $normalized, '@' ) ) {
			return false;
		}

		if ( self::looks_like_business_summary_request( $normalized ) || self::looks_like_operations_overview_request( $normalized ) ) {
			return false;
		}

		if ( preg_match( '/\b(?:insight|insights|trend|trends|pattern|patterns)\b/u', $normalized ) ) {
			return false;
		}

		return self::looks_like_operations_attention_request( $normalized )
			|| self::looks_like_fulfillment_review_request( $normalized )
			|| self::looks_like_customer_service_review_request( $normalized )
			|| self::looks_like_inventory_review_request( $normalized );
	}

	private static function derive_router_profile( $command, $context, $scope ) {
		$scope = sanitize_key( $scope );
		$normalized = self::normalize_router_command( $command );

		if ( 'store_summary_targeted' === $scope ) {
			if ( self::looks_like_business_summary_request( $normalized ) ) {
				return 'business_summary';
			}

			return 'operations_overview';
		}

		if ( 'customer_targeted' === $scope ) {
			if ( self::looks_like_customer_orders_request( $normalized ) ) {
				return 'customer_orders';
			}

			return 'customer_summary';
		}

		if ( 'email_template_targeted' === $scope ) {
			return 'email_template_list';
		}

		if ( 'orders_search_targeted' === $scope ) {
			return 'orders_search';
		}

		if ( 'order_query_action_targeted' === $scope ) {
			return 'order_query_action';
		}

		if ( 'order_write_missing_targeted' === $scope ) {
			$shape = self::extract_command_shape( $command, $context );
			$write_intent = isset( $shape['write_intent'] ) && is_array( $shape['write_intent'] )
				? $shape['write_intent']
				: [];
			$action = sanitize_text_field( $write_intent['action'] ?? '' );

			return 'orders.hold' === $action ? 'order_write_missing_hold' : 'order_write_missing_status';
		}

		if ( 'multi_order_targeted' === $scope ) {
			if ( preg_match( '/\b(?:email|draft|reply|respond|message|write|mail)\b/u', $normalized ) || preg_match( '/\b(?:viết|soạn|gửi)\b/u', $normalized ) ) {
				if ( preg_match( '/\b(?:save|store|keep)\b/u', $normalized ) && preg_match( '/\bdraft\b/u', $normalized ) ) {
					return 'multi_order_email_save_draft';
				}

				if ( preg_match( '/\bsend\b/u', $normalized ) || preg_match( '/\bgửi\b/u', $normalized ) ) {
					return 'multi_order_email_send';
				}

				return 'multi_order_email_preview';
			}

			if ( preg_match( '/\b(?:status|mark|set|move|change|update|on[- ]hold|completed|processing|cancelled|canceled)\b/u', $normalized ) ) {
				return 'multi_order_status';
			}

			if ( preg_match( '/\b(?:hold|pause)\b/u', $normalized ) ) {
				return 'multi_order_hold';
			}

			if ( preg_match( '/\b(?:note|comment)\b/u', $normalized ) ) {
				return 'multi_order_note';
			}

			return 'multi_order_read';
		}

		if ( 'queue_targeted' === $scope ) {
			if ( self::looks_like_operations_attention_request( $normalized ) ) {
				return 'operations_attention';
			}

			if ( self::looks_like_fulfillment_review_request( $normalized ) ) {
				return 'fulfillment_review';
			}

			if ( self::looks_like_customer_service_review_request( $normalized ) ) {
				return 'customer_service_review';
			}

			return 'inventory_review';
		}

		if ( 'single_order_targeted' !== $scope ) {
			return 'default';
		}

		$single_order_shape = self::extract_command_shape( $command, $context );

		if (
			(
				false !== strpos( $normalized, 'email drafts' ) ||
				false !== strpos( $normalized, 'email draft' ) ||
				false !== strpos( $normalized, 'draft emails' ) ||
				false !== strpos( $normalized, 'saved drafts' )
			) &&
			(
				false !== strpos( $normalized, 'open' ) ||
				false !== strpos( $normalized, 'show' ) ||
				false !== strpos( $normalized, 'view' ) ||
				false !== strpos( $normalized, 'load' )
			)
		) {
			return 'order_email_drafts';
		}

		if ( preg_match( '/\b(?:email|draft|reply|respond|message|write|mail)\b/u', $normalized ) || preg_match( '/\b(?:viết|soạn|gửi)\b/u', $normalized ) ) {
			if ( preg_match( '/\b(?:save|store|keep)\b/u', $normalized ) && preg_match( '/\bdraft\b/u', $normalized ) ) {
				return 'order_email_save_draft';
			}

			if ( preg_match( '/\bsend\b/u', $normalized ) || preg_match( '/\bgửi\b/u', $normalized ) ) {
				return 'order_email_send';
			}

			return 'order_email_preview';
		}

		if ( self::command_looks_like_status_note_request( $normalized, $single_order_shape ) ) {
			return 'order_status';
		}

		if ( preg_match( '/\b(?:note|comment)\b/u', $normalized ) || preg_match( '/\b(?:ghi chú|ghichu|nhắn nội bộ)\b/u', $normalized ) ) {
			return 'order_note';
		}

		if ( preg_match( '/\b(?:status|mark|set|move)\b/u', $normalized ) || preg_match( '/\b(?:trạng thái|chuyển|đánh dấu|cap nhat|cập nhật)\b/u', $normalized ) ) {
			return 'order_status';
		}

		if ( preg_match( '/\b(?:hold|pause)\b/u', $normalized ) || preg_match( '/\b(?:tạm giữ|giữ lại|tam giu)\b/u', $normalized ) ) {
			return 'order_hold';
		}

		if ( preg_match( '/\b(?:refund)\b/u', $normalized ) ) {
			return 'order_refund';
		}

		if (
			'unknown' === sanitize_key( $single_order_shape['surface'] ?? '' ) &&
			! empty( $single_order_shape['explicit_order_ids'] ) &&
			is_array( $single_order_shape['explicit_order_ids'] )
		) {
			return 'order_ambiguous';
		}

		return 'order_read';
	}

	private static function derive_candidate_actions( $command, $context, $scope ) {
		$scope = sanitize_key( $scope );

		if ( 'customer_targeted' === $scope ) {
			switch ( self::derive_router_profile( $command, $context, $scope ) ) {
				case 'customer_orders':
					return [
						'orders.find_by_customer',
						'customers.get_summary',
					];
				case 'customer_summary':
				default:
					return [
						'customers.get_summary',
						'orders.find_by_customer',
					];
			}
		}

		if ( 'email_template_targeted' === $scope ) {
			return [
				'email_templates.list',
			];
		}

		if ( 'orders_search_targeted' === $scope ) {
			return [
				'orders.search',
			];
		}

		if ( in_array( $scope, [ 'order_query_action_targeted', 'order_write_missing_targeted' ], true ) ) {
			return self::derive_order_write_candidate_actions_from_shape(
				self::extract_command_shape( $command, $context )
			);
		}

		if ( 'store_summary_targeted' === $scope ) {
			switch ( self::derive_router_profile( $command, $context, $scope ) ) {
				case 'business_summary':
					return [
						'store.get_business_summary',
						'workflow.business_summary_preview',
						'store.get_insights',
						'operations.get_summary',
					];
				case 'operations_overview':
				default:
					return [
						'operations.get_summary',
						'store.get_daily_briefing',
						'store.get_morning_briefing',
					];
			}
		}

		if ( 'queue_targeted' === $scope ) {
			switch ( self::derive_router_profile( $command, $context, $scope ) ) {
				case 'operations_attention':
					return [
						'operations.get_attention_queue',
						'operations.get_summary',
						'customers.get_service_queue',
					];
				case 'fulfillment_review':
					return [
						'operations.get_fulfillment_queue',
						'operations.get_attention_queue',
						'operations.get_summary',
					];
				case 'customer_service_review':
					return [
						'customers.get_service_queue',
						'operations.get_attention_queue',
						'operations.get_summary',
					];
				case 'inventory_review':
				default:
					return [
						'products.get_inventory_queue',
						'operations.get_fulfillment_queue',
						'store.get_business_summary',
					];
			}
		}

		if ( 'multi_order_targeted' === $scope ) {
			switch ( self::derive_router_profile( $command, $context, $scope ) ) {
				case 'multi_order_email_save_draft':
					return [
						'workflow.write_and_save_email_drafts_for_orders',
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_email_send':
					return [
						'workflow.write_and_send_emails_for_orders',
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_email_preview':
					return [
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_status':
					return [
						'orders.update_status',
						'orders.bulk_update_status_with_note',
						'orders.hold',
						'orders.add_note',
					];
				case 'multi_order_hold':
					return [
						'orders.hold',
						'orders.update_status',
						'orders.add_note',
					];
				case 'multi_order_note':
					return [
						'orders.add_note',
						'orders.bulk_update_status_with_note',
						'orders.update_status',
					];
				case 'multi_order_read':
				default:
					return [
						'orders.search',
						'orders.get_details',
					];
			}
		}

		if ( 'single_order_targeted' !== $scope ) {
			return [];
		}

		switch ( self::derive_router_profile( $command, $context, $scope ) ) {
			case 'order_email_save_draft':
				return [
					'workflow.write_and_save_email_draft_for_order',
					'workflow.write_email_for_order',
					'workflow.write_and_save_email_drafts_for_orders',
					'workflow.write_email_for_orders',
					'orders.compose_customer_email',
					'orders.get_customer_email_drafts',
				];
			case 'order_email_drafts':
				return [
					'orders.get_customer_email_drafts',
					'orders.compose_customer_email',
				];
			case 'order_email_send':
				$actions = [
					'workflow.write_and_send_email_for_order',
					'workflow.write_email_for_order',
					'workflow.write_and_send_emails_for_orders',
					'workflow.write_email_for_orders',
					'orders.compose_customer_email',
				];

				if ( self::command_has_explicit_email_template_cue( $command ) ) {
					$actions[] = 'orders.email_customer_template';
					$actions[] = 'orders.use_email_template';
				}

				return $actions;
			case 'order_email_preview':
				$actions = [
					'orders.compose_customer_email',
				];

				if ( self::command_has_explicit_email_template_cue( $command ) ) {
					$actions[] = 'orders.email_customer_preview';
					$actions[] = 'orders.use_email_template';
				}

				return $actions;
			case 'order_note':
				return [
					'orders.add_note',
					'orders.get_details',
				];
			case 'order_status':
				return [
					'orders.update_status',
					'orders.bulk_update_status_with_note',
					'orders.get_details',
				];
			case 'order_hold':
				return [
					'orders.hold',
					'orders.get_details',
				];
			case 'order_refund':
				return [
					'orders.refund_preview',
					'workflow.refund_review',
				];
			case 'order_ambiguous':
				return [
					'orders.get_details',
					'orders.update_status',
					'orders.bulk_update_status_with_note',
					'orders.hold',
					'orders.add_note',
				];
			case 'order_read':
			default:
				return [
					'orders.get_details',
					'orders.find_by_identity',
				];
		}
	}

	private static function allowed_actions_for_router_profile( $profile, $scope, $command = '' ) {
		$scope = sanitize_key( $scope );
		$profile = sanitize_key( $profile );

		if ( 'customer_targeted' === $scope ) {
			if ( 'customer_orders' === $profile ) {
				return [
					'orders.find_by_customer',
					'customers.get_summary',
				];
			}

			return [
				'customers.get_summary',
				'orders.find_by_customer',
			];
		}

		if ( 'email_template_targeted' === $scope ) {
			return [
				'email_templates.list',
			];
		}

		if ( 'orders_search_targeted' === $scope ) {
			return [
				'orders.search',
			];
		}

		if ( in_array( $scope, [ 'order_query_action_targeted', 'order_write_missing_targeted' ], true ) ) {
			return self::derive_order_write_candidate_actions_from_shape(
				self::extract_command_shape( $command )
			);
		}

		if ( 'multi_order_targeted' === $scope ) {
			switch ( $profile ) {
				case 'multi_order_email_save_draft':
					return [
						'workflow.write_and_save_email_drafts_for_orders',
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_email_send':
					return [
						'workflow.write_and_send_emails_for_orders',
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_email_preview':
					return [
						'workflow.write_email_for_orders',
						'orders.compose_customer_email',
						'orders.get_details',
					];
				case 'multi_order_status':
					return [
						'orders.update_status',
						'orders.bulk_update_status_with_note',
						'orders.hold',
						'orders.add_note',
					];
				case 'multi_order_hold':
					return [
						'orders.hold',
						'orders.update_status',
						'orders.add_note',
					];
				case 'multi_order_note':
					return [
						'orders.add_note',
						'orders.bulk_update_status_with_note',
						'orders.update_status',
					];
				case 'multi_order_read':
				default:
					return [
						'orders.search',
						'orders.get_details',
					];
			}
		}

		if ( 'store_summary_targeted' === $scope ) {
			if ( 'business_summary' === $profile ) {
				return [
					'store.get_business_summary',
					'workflow.business_summary_preview',
					'store.get_insights',
					'operations.get_summary',
				];
			}

			if ( 'operations_overview' === $profile ) {
				return [
					'operations.get_summary',
					'store.get_daily_briefing',
					'store.get_morning_briefing',
				];
			}

			return [];
		}

		if ( 'queue_targeted' === $scope ) {
			switch ( $profile ) {
				case 'operations_attention':
					return [
						'operations.get_attention_queue',
						'operations.get_summary',
						'customers.get_service_queue',
					];
				case 'fulfillment_review':
					return [
						'operations.get_fulfillment_queue',
						'operations.get_attention_queue',
						'operations.get_summary',
					];
				case 'customer_service_review':
					return [
						'customers.get_service_queue',
						'operations.get_attention_queue',
						'operations.get_summary',
					];
				case 'inventory_review':
					return [
						'products.get_inventory_queue',
						'operations.get_fulfillment_queue',
						'store.get_business_summary',
					];
				default:
					return [];
			}
		}

		if ( 'single_order_targeted' !== $scope ) {
			return [];
		}

		$base = [
			'workflow.cancel',
			'orders.get_details',
			'orders.find_by_identity',
		];

		switch ( $profile ) {
			case 'order_note':
				return array_values(
					array_unique(
						array_merge(
							$base,
							[
								'orders.add_note',
								'orders.hold',
								'orders.update_status',
							]
						)
					)
				);
			case 'order_status':
				return array_values(
					array_unique(
						array_merge(
							$base,
							[
								'orders.update_status',
								'orders.bulk_update_status_with_note',
								'orders.hold',
								'orders.add_note',
							]
						)
					)
				);
			case 'order_hold':
				return array_values(
					array_unique(
						array_merge(
							$base,
							[
								'orders.hold',
								'orders.update_status',
								'orders.add_note',
							]
						)
					)
				);
			case 'order_refund':
				return [
					'orders.refund_preview',
					'workflow.refund_review',
				];
			case 'order_ambiguous':
				return array_values(
					array_unique(
						array_merge(
							$base,
							[
								'orders.update_status',
								'orders.bulk_update_status_with_note',
								'orders.hold',
								'orders.add_note',
							]
						)
					)
				);
			case 'order_email_save_draft':
				return [
					'workflow.write_and_save_email_draft_for_order',
					'workflow.write_email_for_order',
					'workflow.write_and_save_email_drafts_for_orders',
					'workflow.write_email_for_orders',
					'orders.compose_customer_email',
					'orders.get_customer_email_drafts',
				];
			case 'order_email_drafts':
				return [
					'orders.get_customer_email_drafts',
				];
			case 'order_email_send':
				$actions = [
					'workflow.write_and_send_email_for_order',
					'workflow.write_email_for_order',
					'workflow.write_and_send_emails_for_orders',
					'workflow.write_email_for_orders',
					'orders.compose_customer_email',
				];

				if ( self::command_has_explicit_email_template_cue( $command ) ) {
					$actions[] = 'orders.email_customer_template';
					$actions[] = 'orders.use_email_template';
				}

				return $actions;
			case 'order_email_preview':
				$actions = [
					'orders.compose_customer_email',
				];

				if ( self::command_has_explicit_email_template_cue( $command ) ) {
					$actions[] = 'orders.email_customer_preview';
					$actions[] = 'orders.use_email_template';
				}

				return $actions;
			case 'order_read':
			default:
				return array_values(
					array_unique( $base )
				);
		}
	}

	private static function derive_order_write_candidate_actions_from_shape( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];
		$intent = [];

		if ( isset( $shape['query_action'] ) && is_array( $shape['query_action'] ) && ! empty( $shape['query_action'] ) ) {
			$intent = $shape['query_action'];
		} elseif ( isset( $shape['write_intent'] ) && is_array( $shape['write_intent'] ) && ! empty( $shape['write_intent'] ) ) {
			$intent = $shape['write_intent'];
		}

		$action = sanitize_text_field( $intent['action'] ?? '' );

		if ( ! in_array( $action, [ 'orders.hold', 'orders.update_status', 'orders.bulk_update_status_with_note' ], true ) ) {
			return [
				'orders.update_status',
				'orders.hold',
			];
		}

		return [ $action ];
	}

	private static function derive_candidate_intent_families( $command, $context, $scope ) {
		$families = [];

		foreach ( self::derive_candidate_actions( $command, $context, $scope ) as $action_name ) {
			$family = self::infer_intent_family( $action_name );

			if ( '' !== $family ) {
				$families[] = $family;
			}
		}

		return array_values( array_unique( $families ) );
	}

	private static function normalize_router_command( $command ) {
		$command = is_string( $command ) ? trim( $command ) : '';

		return function_exists( 'mb_strtolower' )
			? mb_strtolower( $command, 'UTF-8' )
			: strtolower( $command );
	}

	private static function resolve_context_order_ids( $context, $include_contextual_fallback = false ) {
		$context = is_array( $context ) ? $context : [];
		$order_ids = [];

		if ( ! empty( $context['resolved_order_ids'] ) && is_array( $context['resolved_order_ids'] ) ) {
			$order_ids = array_values( array_filter( array_map( 'absint', $context['resolved_order_ids'] ) ) );
		}

		if ( $include_contextual_fallback && empty( $order_ids ) && ! empty( $context['current_order_id'] ) ) {
			$order_ids[] = absint( $context['current_order_id'] );
		}

		if ( $include_contextual_fallback && empty( $order_ids ) && ! empty( $context['last_referenced_order_id'] ) ) {
			$order_ids[] = absint( $context['last_referenced_order_id'] );
		}

		if (
			empty( $order_ids ) &&
			! empty( $context['memory_context']['reference'] ) &&
			is_array( $context['memory_context']['reference'] ) &&
			'order' === sanitize_key( $context['memory_context']['reference']['target_type'] ?? '' ) &&
			! empty( $context['memory_context']['reference']['target_ids'] ) &&
			is_array( $context['memory_context']['reference']['target_ids'] )
		) {
			$order_ids = array_values(
				array_filter(
					array_map( 'absint', $context['memory_context']['reference']['target_ids'] )
				)
			);
		}

		return array_values( array_unique( array_filter( $order_ids ) ) );
	}

	private static function extract_explicit_order_ids( $command ) {
		if ( ! class_exists( 'YoRo_Order_Helper' ) ) {
			return [];
		}

		$order_ids = YoRo_Order_Helper::extract_order_ids_from_text( (string) $command );

		return array_values(
			array_unique(
				array_filter(
					array_map( 'absint', is_array( $order_ids ) ? $order_ids : [] )
				)
			)
		);
	}

	private static function resolve_plural_context_order_ids( $command, $context ) {
		if ( ! self::command_looks_like_contextual_plural_order_reference( $command ) ) {
			return [];
		}

		$context = is_array( $context ) ? $context : [];

		if ( ! empty( $context['last_orders'] ) && is_array( $context['last_orders'] ) ) {
			return array_values(
				array_unique(
					array_filter(
						array_map( 'absint', wp_list_pluck( $context['last_orders'], 'id' ) )
					)
				)
			);
		}

		if ( ! empty( $context['last_order_ids'] ) && is_array( $context['last_order_ids'] ) ) {
			return array_values(
				array_unique(
					array_filter(
						array_map( 'absint', $context['last_order_ids'] )
					)
				)
			);
		}

		return [];
	}

	private static function command_looks_like_contextual_plural_order_reference( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		return (
			false !== strpos( $normalized, 'these orders' ) ||
			false !== strpos( $normalized, 'those orders' ) ||
			false !== strpos( $normalized, 'selected orders' ) ||
			false !== strpos( $normalized, 'all these' ) ||
			false !== strpos( $normalized, 'all of them' ) ||
			preg_match( '/\bthem\b/u', $normalized )
		);
	}

	private static function command_uses_contextual_order_reference( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:this order|that order|the order|order\b|đơn này|đơn hàng này|don nay|don hang nay)\b/u',
			$normalized
		);
	}

	private static function command_has_explicit_email_template_cue( $command ) {
		$normalized = self::normalize_router_command( $command );

		if ( '' === $normalized ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:template|verification|verify|needs?\s+verification|payment\s+mismatch|payment\s+verification|shipping\s+delay)\b/u',
			$normalized
		);
	}

	private static function command_looks_like_status_note_request( $normalized, $shape = [] ) {
		$normalized = is_string( $normalized ) ? trim( $normalized ) : '';
		$shape      = is_array( $shape ) ? $shape : [];

		if ( '' === $normalized ) {
			return false;
		}

		$status = sanitize_key( $shape['status'] ?? '' );

		if ( '' === $status ) {
			return false;
		}

		$has_note_cue = (bool) preg_match(
			'/\b(?:with\s+note|add\s+note|note\s*:|note|comment)\b/u',
			$normalized
		);

		if ( ! $has_note_cue ) {
			return false;
		}

		return (bool) (
			preg_match( '/^\s*(?:complete|completed|cancel|cancelled|canceled|refund|refunded|hold)\b/u', $normalized ) ||
			preg_match( '/\b(?:status|mark|set|move|change|update)\b/u', $normalized ) ||
			preg_match( '/\b(?:trạng thái|chuyển|đánh dấu|cap nhat|cập nhật)\b/u', $normalized )
		);
	}

	private static function looks_like_business_summary_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:business|revenue|sales|profit|commercial|performance)\b/u',
			$normalized
		);
	}

	private static function looks_like_operations_overview_request( $normalized ) {
		if ( preg_match( '/\b(?:business|revenue|sales|profit|commercial)\b/u', $normalized ) ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:store|operations?|operational|brief(?:ing)?|overview|summary|update|what\'?s new|what is new|how is the store)\b/u',
			$normalized
		);
	}

	private static function command_shape_has_specific_order_filter( $shape ) {
		$shape = is_array( $shape ) ? $shape : [];

		if (
			! class_exists( 'YoRo_Command_Shape_Extractor' ) ||
			! YoRo_Command_Shape_Extractor::is_order_list_search_shape( $shape )
		) {
			return false;
		}

		$status = sanitize_key( $shape['status'] ?? '' );
		$age_filter = isset( $shape['age_filter'] ) && is_array( $shape['age_filter'] )
			? $shape['age_filter']
			: [];

		return '' !== $status && ! empty( $age_filter['hours'] );
	}

	private static function looks_like_operations_attention_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:urgent|attention|needs?\s+attention|what needs attention|right now|priority|priorities|manual review)\b/u',
			$normalized
		);
	}

	private static function looks_like_fulfillment_review_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:waiting to ship|ready to ship|ship(?:ping)? queue|fulfillment|shipping blockers?|shipping delay|waiting to go out)\b/u',
			$normalized
		);
	}

	private static function looks_like_customer_service_review_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:follow-up|follow up|reply|replies|customer service|customer follow-up|needs reply|who needs follow-up)\b/u',
			$normalized
		);
	}

	private static function looks_like_inventory_review_request( $normalized ) {
		return (bool) preg_match(
			'/\b(?:restock|low stock|inventory|out of stock|backorder|stock pressure|what products need restock)\b/u',
			$normalized
		);
	}

	private static function build_action_item( $name, $action, $context = [] ) {
		$name = sanitize_text_field( $name );

		if ( '' === $name ) {
			return null;
		}

		$input_schema = isset( $action['input_schema'] ) && is_array( $action['input_schema'] )
			? $action['input_schema']
			: [];

		$required_params = ! empty( $action['required_params'] ) && is_array( $action['required_params'] )
			? array_values( array_map( 'sanitize_key', $action['required_params'] ) )
			: self::infer_required_params( $name, $input_schema );
		$intent_family = self::infer_intent_family( $name );

		return [
			'name'                  => $name,
			'label'                 => sanitize_text_field( $action['label'] ?? $name ),
			'description'           => sanitize_text_field( $action['ai_description'] ?: ( $action['description'] ?? '' ) ),
			'category'              => sanitize_key( $action['category'] ?? 'general' ),
			'intent_family'         => $intent_family,
			'family_policy'         => self::family_policy_for_action( $name, $intent_family ),
			'input_schema'          => self::normalize_input_schema( $input_schema ),
			'required_params'       => $required_params,
			'param_hints'           => isset( $action['param_hints'] ) && is_array( $action['param_hints'] ) ? $action['param_hints'] : [],
			'clarification_hints'   => isset( $action['clarification_hints'] ) && is_array( $action['clarification_hints'] ) ? $action['clarification_hints'] : [],
			'examples'              => self::examples_for_action( $name, $action ),
			'write_action'          => ! empty( $action['write_action'] ),
			'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			'risk_level'            => sanitize_key( $action['risk_level'] ?? 'low' ),
			'max_items'             => isset( $action['max_items'] ) ? absint( $action['max_items'] ) : 1,
			'capabilities'          => isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
				? array_values( array_map( 'sanitize_key', $action['capabilities'] ) )
				: [],
		];
	}

	private static function normalize_input_schema( $schema ) {
		$normalized = [];

		foreach ( $schema as $param => $type ) {
			$param = sanitize_key( $param );

			if ( '' === $param ) {
				continue;
			}

			$normalized[ $param ] = sanitize_text_field( $type );
		}

		return $normalized;
	}

	private static function infer_required_params( $action, $input_schema ) {
		$map = [
			'orders.get_details'                   => [ 'order_ids' ],
			'orders.refund_preview'                => [ 'order_id' ],
			'orders.email_customer_preview'        => [ 'order_id', 'template' ],
			'orders.email_customer_template'       => [ 'order_id', 'template' ],
			'orders.use_email_template'            => [ 'order_id', 'template_id' ],
			'orders.compose_customer_email'        => [ 'order_id', 'instruction' ],
			'orders.save_customer_email_draft'     => [ 'order_id', 'subject', 'body' ],
			'orders.get_customer_email_drafts'     => [ 'order_id' ],
			'orders.update_customer_email_draft'   => [ 'order_id', 'draft_id', 'subject', 'body' ],
			'orders.delete_customer_email_draft'   => [ 'order_id', 'draft_id' ],
			'orders.render_customer_email_preview' => [ 'order_id', 'subject', 'body' ],
			'orders.send_customer_email'           => [ 'order_id', 'subject', 'body' ],
			'orders.add_private_note_template'     => [ 'order_ids', 'template' ],
			'orders.hold'                          => [ 'order_ids' ],
			'orders.add_note'                      => [ 'order_ids', 'note' ],
			'orders.remove_note'                   => [ 'note_ids' ],
			'orders.update_status'                 => [ 'order_ids', 'status' ],
			'orders.restore_statuses'              => [ 'statuses' ],
			'orders.bulk_update_status_with_note'  => [ 'order_ids', 'status', 'note' ],
			'email_templates.save'                 => [ 'name', 'subject', 'body' ],
			'email_templates.delete'               => [ 'template_id' ],
		];

		if ( isset( $map[ $action ] ) ) {
			return $map[ $action ];
		}

		return [];
	}

	private static function infer_intent_family( $action ) {
		$action = sanitize_text_field( $action );

		$map = [
			'store.get_business_summary'                    => 'business_summary',
			'store.get_morning_briefing'                    => 'store_briefing',
			'store.get_daily_briefing'                      => 'store_briefing',
			'operations.get_summary'                        => 'operations_overview',
			'operations.get_attention_queue'                => 'operations_attention',
			'operations.get_fulfillment_queue'              => 'fulfillment_review',
			'products.get_inventory_queue'                  => 'inventory_review',
			'customers.get_service_queue'                   => 'customer_service_review',
			'orders.search'                                 => 'search_orders',
			'orders.get_details'                            => 'get_order_details',
			'orders.find_by_customer'                       => 'find_customer_orders',
			'customers.get_summary'                         => 'customer_summary',
			'orders.update_status'                          => 'update_order_status',
			'orders.bulk_update_status_with_note'           => 'update_order_status',
			'orders.hold'                                   => 'hold_order',
			'orders.refund_preview'                         => 'refund_review',
			'orders.add_note'                               => 'add_note',
			'orders.compose_customer_email'                 => 'customer_email',
			'orders.get_customer_email_drafts'              => 'customer_email',
			'orders.save_customer_email_draft'              => 'customer_email',
			'orders.send_customer_email'                    => 'customer_email',
			'workflow.write_email_for_order'                => 'customer_email',
			'workflow.write_and_save_email_draft_for_order' => 'customer_email',
			'workflow.write_and_send_email_for_order'       => 'customer_email',
			'workflow.write_email_for_orders'               => 'customer_email',
			'workflow.write_and_save_email_drafts_for_orders' => 'customer_email',
			'workflow.write_and_send_emails_for_orders'     => 'customer_email',
			'workflow.review_suspicious_order'              => 'review_suspicious_order',
			'workflow.verify_customer_before_fulfillment'   => 'verify_customer_before_fulfillment',
			'workflow.refund_review'                        => 'refund_review',
			'workflow.fulfillment_followup_preview'         => 'fulfillment_review',
			'workflow.customer_followup_preview'            => 'customer_service_review',
			'workflow.operations_attention_preview'         => 'operations_attention',
			'workflow.inventory_pressure_preview'           => 'inventory_review',
			'workflow.business_summary_preview'             => 'business_summary',
			'workflow.composed_actions'                     => 'composed_actions',
			'workflow.cancel'                               => 'workflow_control',
		];

		return sanitize_key( $map[ $action ] ?? '' );
	}

	private static function family_policy_for_action( $action, $intent_family ) {
		$intent_family = sanitize_key( $intent_family );

		$policies = [
			'store_briefing' => 'Use for briefing-style readouts. Prefer this family when the person wants a concise store check-in rather than a named summary or insight surface.',
			'business_summary' => 'Use for business health, business performance, or commercial-overview requests. Prefer overview focus unless the person clearly narrows to a business dimension.',
			'operations_overview' => 'Use for operations-picture or store-activity overview requests. Prefer overview focus unless the person explicitly narrows the operational dimension.',
			'operations_attention' => 'Use for urgent, manual review, or needs-attention queue requests.',
			'fulfillment_review' => 'Use for shipping blockers, fulfillment backlog, or waiting-to-ship queue requests.',
			'inventory_review' => 'Use for stock pressure, low-stock, restock, backorder, or inventory queue requests.',
			'customer_service_review' => 'Use for reply load, follow-up load, or customer-service queue requests.',
			'customer_email' => 'Use for composing, previewing, saving, or sending customer email work. Treat a natural-language goal as instruction when it is already present.',
			'update_order_status' => 'Use for status changes on one or more orders. Clarify only when status or target order is still unresolved.',
			'add_note' => 'Use for adding notes to one or more orders. Clarify only when note content or target order is unresolved.',
			'search_orders' => 'Use for read-only order search, filtering, or listing requests.',
			'get_order_details' => 'Use for inspecting one specific order in detail.',
		];

		if ( ! empty( $policies[ $intent_family ] ) ) {
			return $policies[ $intent_family ];
		}

		if ( 'email_templates.save' === $action ) {
			return 'Use for saving reusable email templates. Collect only the missing template slots instead of guessing.';
		}

		return '';
	}

	private static function examples_for_action( $name, $action ) {
		$examples = isset( $action['ai_examples'] ) && is_array( $action['ai_examples'] )
			? $action['ai_examples']
			: [];

		$defaults = self::default_examples();

		if ( isset( $defaults[ $name ] ) ) {
			$examples = array_merge( $examples, $defaults[ $name ] );
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $example ) {
							return is_scalar( $example ) ? sanitize_text_field( $example ) : '';
						},
						$examples
					)
				)
			)
		);
	}

	private static function default_examples() {
		return [
			'orders.search' => [
				'Show recent orders',
				'Cho tôi xem các đơn gần đây',
			],
			'orders.get_details' => [
				'Show order #123',
				'Xem chi tiết đơn #123',
			],
			'orders.update_status' => [
				'Mark order #123 as completed',
				'Chuyển đơn #123 sang hoàn tất',
			],
			'orders.hold' => [
				'Put order #123 on hold',
				'Giữ đơn #123',
			],
			'orders.add_note' => [
				'Add note to order #123: customer asked for a callback',
				'Thêm ghi chú vào đơn #123: khách muốn gọi lại',
			],
			'orders.compose_customer_email' => [
				'Write an email for order #123 asking for payment confirmation',
				'Viết email cho đơn #123 để xác nhận thanh toán',
			],
			'orders.send_customer_email' => [
				'Send this email to the customer',
				'Gửi email này cho khách',
			],
			'store.get_daily_briefing' => [
				'Show today\'s briefing',
				'Cho tôi xem briefing hôm nay',
			],
			'store.get_business_summary' => [
				'Show business summary this week',
				'Tóm tắt tình hình kinh doanh tuần này',
			],
			'operations.get_attention_queue' => [
				'What needs attention right now?',
				'Có việc gì cần xử lý gấp không?',
			],
			'operations.get_fulfillment_queue' => [
				'What orders are waiting to ship?',
				'Đơn nào đang chờ giao hàng?',
			],
			'products.get_inventory_queue' => [
				'Show low stock products',
				'Sản phẩm nào sắp hết hàng?',
			],
			'customers.get_service_queue' => [
				'Who needs customer follow-up?',
				'Khách nào cần chăm sóc tiếp?',
			],
		];
	}
}
