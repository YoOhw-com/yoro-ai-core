<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Catalog_Builder {

	public static function build( $context = [] ) {
		$catalog = [];
		$context = is_array( $context ) ? $context : [];

		foreach ( YoRo_Action_Registry::available_for_current_user() as $name => $action ) {
			if ( empty( $action['ai_exposed'] ) ) {
				continue;
			}

			$catalog[] = self::build_action_item( $name, $action, $context );
		}

		return apply_filters(
			'yoro_ai_action_catalog',
			self::apply_catalog_scope( array_values( array_filter( $catalog ) ), $context ),
			$context
		);
	}

	public static function semantic_contract( $name, $action ) {
		$item = is_array( $action ) ? self::build_action_item( $name, $action ) : null;

		if ( ! is_array( $item ) ) {
			return [];
		}

		return [
			'version'                  => 1,
			'intent_family'            => self::canonical_intent_family( $name, $item['intent_family'] ?? '' ),
			'slot_contract'            => is_array( $item['slot_contract'] ?? null ) ? $item['slot_contract'] : [],
			'product_identifier_types' => is_array( $item['product_identifier_types'] ?? null ) ? $item['product_identifier_types'] : [],
			'value_catalogs'            => is_array( $item['value_catalogs'] ?? null ) ? $item['value_catalogs'] : [],
			'param_hints'               => is_array( $item['param_hints'] ?? null ) ? $item['param_hints'] : [],
			'reporting_contract'        => is_array( $item['reporting_contract'] ?? null ) ? $item['reporting_contract'] : [],
			'target_type'              => sanitize_key( $item['target_type'] ?? '' ),
			'supports_bulk'            => ! empty( $item['supports_bulk'] ),
		];
	}

	private static function canonical_intent_family( $action, $fallback ) {
		$families = [
			'orders.search'                  => 'orders_search',
			'orders.get_details'             => 'order_details',
			'orders.regenerate_download_permissions' => 'order_download_permissions_regeneration',
			'orders.get_download_permissions' => 'order_download_permissions',
			'orders.get_email_history' => 'order_email_history',
			'orders.get_payment_context'     => 'order_payment_context',
			'orders.get_payment_link'     => 'order_payment_link',
			'orders.refund_preview'           => 'order_refund',
			'customers.lookup'                => 'customer_lookup',
			'orders.find_by_customer'         => 'customer_orders',
			'coupons.search'                  => 'coupon_search',
			'coupons.lookup'                  => 'coupon_lookup',
			'coupons.create'                  => 'coupon_create',
			'coupons.update'                  => 'update_coupon',
			'fulfillment.create'              => 'create_fulfillment',
			'fulfillment.add_tracking'        => 'add_tracking',
			'fulfillment.update_tracking'     => 'update_tracking',
			'orders.update_addresses'         => 'update_order_addresses',
			'orders.update_status'            => 'update_order_status',
			'products.adjust_stock'           => 'adjust_product_stock',
			'products.create_draft'           => 'create_product_draft',
			'products.update_content'         => 'update_product_content',
			'products.update_pricing'         => 'update_product_pricing',
			'products.update_stock'           => 'update_product_stock',
			'products.update_stock_status'    => 'update_product_stock_status',
			'orders.add_note'                 => 'add_note',
			'orders.remove_note'              => 'remove_note',
			'orders.restore_statuses'         => 'update_order_status',
			'operations.get_summary'          => 'operations_report',
			'store.get_business_summary'      => 'sales_report',
			'store.get_daily_briefing'        => 'store_report',
			'store.get_insights'              => 'store_signals',
			'operations.get_attention_queue'  => 'operations_attention',
			'operations.get_fulfillment_queue' => 'fulfillment_review',
			'customers.get_service_queue'     => 'customer_service_review',
			'blacklist.get_status' => 'blacklist_status',
			'blacklist.get_order_assessment' => 'blacklist_order_assessment',
			'customers.get_timeline' => 'customer_timeline',
			'customers.get_follow_up_queue' => 'customer_service_review',
			'products.get_inventory_queue'    => 'inventory_review',
			'products.search'                 => 'product_search',
			'products.get_details'            => 'product_read',
			'orders.get_timeline'             => 'order_timeline',
			'fulfillment.get_tracking'        => 'fulfillment_tracking',
			'content.search'                  => 'content_search',
			'content.get'                     => 'content_read',
			'content.create_draft'            => 'content_draft',
			'content.update_draft'            => 'content_draft',
			'content.preview_changes'         => 'content_draft',
			'comments.list'                   => 'comments_read',
			'media.search'                    => 'media_search',
			'media.get'                       => 'media_read',
			'site.get_info'                   => 'site_diagnostics',
			'site.get_health'                 => 'site_diagnostics',
			'site.get_update_status'          => 'site_diagnostics',
		];
		$action = sanitize_text_field( $action );

		return sanitize_key( $families[ $action ] ?? $fallback );
	}

	public static function build_router_hints( $command = '', $context = [], $catalog = null ) {
		$context = is_array( $context ) ? $context : [];
		$catalog = is_array( $catalog ) ? array_values( $catalog ) : self::build( $context );
		$scope = self::catalog_scope_from_context( $context );
		$report_provider_catalog = 'report_provider_catalog' === $scope && self::is_report_provider_catalog( $catalog );

		$hints = [
			'catalog_scope'              => 'full_catalog',
			'catalog_total_action_count' => count( $catalog ),
			'resolved_order_ids'         => self::resolve_context_order_ids( $context ),
			'router_profile'             => 'full_catalog',
			'candidate_actions'          => [],
			'candidate_intent_families'  => [],
		];

		if ( $report_provider_catalog ) {
			$hints['catalog_scope'] = 'report_provider_catalog';
			$hints['report_topic_selection'] = 'provider_catalog';
			$hints['report_provider_catalog'] = true;
			$hints['candidate_actions'] = self::catalog_action_names( $catalog );
			$hints['candidate_intent_families'] = self::catalog_intent_families( $catalog );
			$report_topic = self::report_topic_from_context( $context );

			if ( '' !== $report_topic ) {
				$hints['report_topic'] = $report_topic;
			}
		}

		return $hints;
	}

	private static function apply_catalog_scope( $catalog, $context ) {
		$catalog = is_array( $catalog ) ? array_values( $catalog ) : [];
		$scope = self::catalog_scope_from_context( $context );

		if ( 'report_provider_catalog' !== $scope ) {
			return $catalog;
		}

		return array_values(
			array_filter(
				$catalog,
				function ( $item ) {
					return is_array( $item ) && self::is_store_report_provider( $item );
				}
			)
		);
	}

	private static function catalog_scope_from_context( $context ) {
		$scope = sanitize_key( $context['action_catalog_scope'] ?? '' );

		return in_array( $scope, [ 'report_provider_catalog' ], true ) ? $scope : 'full_catalog';
	}

	private static function report_topic_from_context( $context ) {
		$topic = sanitize_key( $context['report_topic'] ?? '' );

		return in_array( $topic, [ 'overview', 'sales', 'operations', 'signals' ], true ) ? $topic : '';
	}

	private static function is_report_provider_catalog( $catalog ) {
		$catalog = is_array( $catalog ) ? array_values( $catalog ) : [];

		if ( count( $catalog ) < 2 ) {
			return false;
		}

		foreach ( $catalog as $item ) {
			if ( ! is_array( $item ) || ! self::is_store_report_provider( $item ) ) {
				return false;
			}
		}

		return true;
	}

	private static function is_store_report_provider( $item ) {
		$contract = isset( $item['reporting_contract'] ) && is_array( $item['reporting_contract'] )
			? $item['reporting_contract']
			: [];

		return 'store_report' === sanitize_key( $contract['user_surface'] ?? '' )
			&& '' !== sanitize_key( $contract['topic'] ?? '' );
	}

	private static function catalog_action_names( $catalog ) {
		return array_values(
			array_filter(
				array_map(
					function ( $item ) {
						return is_array( $item ) ? sanitize_text_field( $item['name'] ?? '' ) : '';
					},
					is_array( $catalog ) ? $catalog : []
				)
			)
		);
	}

	private static function catalog_intent_families( $catalog ) {
		return array_values(
			array_unique(
				array_filter(
					array_map(
						function ( $item ) {
							return is_array( $item ) ? sanitize_key( $item['intent_family'] ?? '' ) : '';
						},
						is_array( $catalog ) ? $catalog : []
					)
				)
			)
		);
	}

	private static function resolve_context_order_ids( $context ) {
		$context = is_array( $context ) ? $context : [];
		$order_ids = [];

		if ( ! empty( $context['resolved_order_ids'] ) && is_array( $context['resolved_order_ids'] ) ) {
			$order_ids = array_values( array_filter( array_map( 'absint', $context['resolved_order_ids'] ) ) );
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
		$slot_contract = isset( $action['slot_contract'] ) && is_array( $action['slot_contract'] )
			? self::normalize_slot_contract( $action['slot_contract'] )
			: self::infer_slot_contract( $name, $input_schema, $required_params );

		return [
			'name'                  => $name,
			'label'                 => sanitize_text_field( $action['label'] ?? $name ),
			'description'           => sanitize_text_field( $action['ai_description'] ?: ( $action['description'] ?? '' ) ),
			'category'              => sanitize_key( $action['category'] ?? 'general' ),
			'intent_family'         => $intent_family,
			'family_policy'         => self::family_policy_for_action( $name, $intent_family ),
			'input_schema'          => self::normalize_input_schema( $input_schema ),
			'output_schema'         => YoRo_Commerce_Contract_Builder::execution_output_schema( $name ),
			'required_params'       => $required_params,
			'required_permissions'  => [ sanitize_key( $action['permission'] ?? '' ) ],
			'slot_contract'         => $slot_contract,
			'product_identifier_types' => self::normalize_product_identifier_types( $action['product_identifier_types'] ?? [] ),
			'param_hints'           => isset( $action['param_hints'] ) && is_array( $action['param_hints'] ) ? $action['param_hints'] : [],
			'value_catalogs'        => self::value_catalogs_for_action( $name ),
			'clarification_hints'   => isset( $action['clarification_hints'] ) && is_array( $action['clarification_hints'] ) ? $action['clarification_hints'] : [],
			'reporting_contract'    => self::reporting_contract_for_action( $name ),
			'examples'              => self::examples_for_action( $name, $action ),
			'write_action'          => ! empty( $action['write_action'] ),
			'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			'risk_level'            => sanitize_key( $action['risk_level'] ?? 'low' ),
			'effect'                => sanitize_key( $action['effect'] ?? ( ! empty( $action['write_action'] ) ? 'mutate' : 'read' ) ),
			'reversibility'         => sanitize_key( $action['reversibility'] ?? ( ! empty( $action['write_action'] ) ? 'partial' : 'full' ) ),
			'external_side_effect'  => ! empty( $action['external_side_effect'] ),
			'target_type'           => sanitize_key( $action['target_type'] ?? '' ),
			'supports_bulk'         => ! empty( $action['supports_bulk'] ),
			'max_targets'           => isset( $action['max_targets'] ) ? absint( $action['max_targets'] ) : ( isset( $action['max_items'] ) ? absint( $action['max_items'] ) : 1 ),
			'safety_contract_version' => YoRo_Safety_Contract::VERSION,
			'max_items'             => isset( $action['max_items'] ) ? absint( $action['max_items'] ) : 1,
			'capabilities'          => isset( $action['capabilities'] ) && is_array( $action['capabilities'] )
				? array_values( array_map( 'sanitize_key', $action['capabilities'] ) )
				: [],
			'extensions'            => [
				'wordpress.action' => [
					'registry_action' => $name,
				],
				'yoro.contracts' => YoRo_Commerce_Contract_Builder::execution_contract_extension( $name ),
			],
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

	private static function value_catalogs_for_action( $action ) {
		$action = sanitize_text_field( $action );

		if ( 'products.update_stock_status' !== $action || ! class_exists( 'YoRo_Product_Helper' ) ) {
			return [];
		}

		$statuses = [];

		foreach ( YoRo_Product_Helper::get_stock_status_options() as $slug => $option ) {
			$slug = YoRo_Product_Helper::normalize_stock_status_slug( $slug );

			if ( '' === $slug ) {
				continue;
			}

			$statuses[] = [
				'key'            => $slug,
				'slug'           => $slug,
				'label'          => sanitize_text_field( $option['label'] ?? $slug ),
				'core'           => in_array( $slug, [ 'instock', 'outofstock', 'onbackorder' ], true ),
				'manual_allowed' => true,
			];
		}

		return [
			'stock_statuses' => array_values( $statuses ),
		];
	}

	private static function normalize_slot_contract( $contract ) {
		if ( ! is_array( $contract ) ) {
			return [];
		}

		$target_slots = [];
		foreach ( (array) ( $contract['target_slots'] ?? [] ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$field = sanitize_key( $slot['field'] ?? '' );
			if ( '' === $field ) {
				continue;
			}

			$target_slots[] = [
				'field'   => $field,
				'aliases' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $slot['aliases'] ?? [] ) ) ) ),
				'kind'    => sanitize_key( $slot['kind'] ?? 'target' ),
			];
		}

		$user_content_slots = [];
		foreach ( (array) ( $contract['user_content_slots'] ?? [] ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$field = sanitize_key( $slot['field'] ?? '' );
			if ( '' === $field ) {
				continue;
			}

			$user_content_slots[] = [
				'field'                     => $field,
				'kind'                      => sanitize_key( $slot['kind'] ?? 'user_content' ),
				'source'                    => sanitize_key( $slot['source'] ?? 'user_literal' ),
				'prompt_priority'           => sanitize_key( $slot['prompt_priority'] ?? 'content_first' ),
				'placeholder_policy'        => sanitize_key( $slot['placeholder_policy'] ?? 'reject_placeholders' ),
				'max_length'                => absint( $slot['max_length'] ?? 2000 ),
				'question'                  => self::sanitize_language_map( (array) ( $slot['question'] ?? [] ) ),
				'question_with_target'      => self::sanitize_language_map( (array) ( $slot['question_with_target'] ?? [] ) ),
				'target_question'           => self::sanitize_language_map( (array) ( $slot['target_question'] ?? [] ) ),
				'combined_question'         => self::sanitize_language_map( (array) ( $slot['combined_question'] ?? [] ) ),
			];
		}

		return array_filter(
			[
				'target_slots'       => $target_slots,
				'user_content_slots' => $user_content_slots,
			],
			static fn( $value ) => ! empty( $value )
		);
	}

	private static function normalize_product_identifier_types( $types ) {
		$normalized = [];

		foreach ( (array) $types as $type ) {
			if ( ! is_array( $type ) ) {
				continue;
			}

			$key   = sanitize_key( $type['key'] ?? '' );
			$field = sanitize_key( $type['field'] ?? $key );

			if ( '' === $key || ! in_array( $field, [ 'product_id', 'product_sku', 'global_unique_id' ], true ) ) {
				continue;
			}

			$aliases = [];

			foreach ( (array) ( $type['aliases'] ?? [] ) as $alias ) {
				$alias = sanitize_text_field( (string) $alias );

				if ( '' !== $alias ) {
					$aliases[] = $alias;
				}
			}

			$normalized[] = [
				'key'        => $key,
				'field'      => $field,
				'value_type' => sanitize_key( $type['value_type'] ?? 'string' ),
				'aliases'    => array_values( array_unique( $aliases ) ),
			];
		}

		return $normalized;
	}

	private static function sanitize_language_map( $map ) {
		$normalized = [];

		foreach ( $map as $language => $text ) {
			$language = sanitize_key( $language );
			$text     = sanitize_text_field( $text );

			if ( '' !== $language && '' !== $text ) {
				$normalized[ $language ] = $text;
			}
		}

		return $normalized;
	}

	private static function infer_slot_contract( $action, $input_schema, $required_params ) {
		$input_schema    = is_array( $input_schema ) ? $input_schema : [];
		$required_params = array_values( array_map( 'sanitize_key', (array) $required_params ) );
		$target_slots    = [];

		if ( in_array( 'order_ids', $required_params, true ) || array_key_exists( 'order_ids', $input_schema ) ) {
			$target_slots[] = [
				'field'   => 'order_ids',
				'aliases' => [ 'order_id' ],
				'kind'    => 'order_target',
			];
		} elseif ( in_array( 'order_id', $required_params, true ) || array_key_exists( 'order_id', $input_schema ) ) {
			$target_slots[] = [
				'field'   => 'order_id',
				'aliases' => [ 'order_ids' ],
				'kind'    => 'order_target',
			];
		} elseif ( in_array( 'product_target', $required_params, true )
			|| in_array( 'product_id', $required_params, true )
			|| array_key_exists( 'product_id', $input_schema )
			|| array_key_exists( 'product_sku', $input_schema )
			|| array_key_exists( 'global_unique_id', $input_schema )
		) {
			$target_slots[] = [
				'field'   => 'product_id',
				'aliases' => [ 'product_sku', 'global_unique_id' ],
				'kind'    => 'product_target',
			];
		}

		$user_content_slots = [];

		if ( in_array( 'note', $required_params, true ) ) {
			$user_content_slots[] = [
				'field'                => 'note',
				'kind'                 => 'internal_note',
				'source'               => 'user_literal',
				'prompt_priority'      => 'content_first',
				'placeholder_policy'   => 'reject_request_placeholders',
				'max_length'           => 2000,
				'question'             => [
					'en' => 'What should I write in the note?',
					'vi' => 'Bạn muốn thêm nội dung ghi chú nào?',
				],
				'question_with_target' => [
					'en' => 'What should I write in the note for order #{order_id}?',
					'vi' => 'Bạn muốn thêm nội dung ghi chú nào cho đơn #{order_id}?',
				],
				'target_question'      => [
					'en' => 'Which order should I add this note to?',
					'vi' => 'Bạn muốn thêm ghi chú này vào đơn nào?',
				],
			];
		}

		if ( in_array( 'instruction', $required_params, true ) ) {
			$user_content_slots[] = [
				'field'                => 'instruction',
				'kind'                 => 'customer_email_instruction',
				'source'               => 'user_goal',
				'prompt_priority'      => 'target_first',
				'placeholder_policy'   => 'reject_request_placeholders',
				'max_length'           => 2000,
				'question'             => [
					'en' => 'What should the customer email say?',
					'vi' => 'Email gửi khách nên nói nội dung gì?',
				],
				'question_with_target' => [
					'en' => 'What should the email to the customer say for order #{order_id}?',
					'vi' => 'Email gửi khách cho đơn #{order_id} nên nói nội dung gì?',
				],
				'target_question'      => [
					'en' => 'Which order is this email for?',
					'vi' => 'Email này dành cho đơn nào?',
				],
				'combined_question'    => [
					'en' => 'Which order is this for, and what should the customer email say?',
					'vi' => 'Email này dành cho đơn nào, và bạn muốn email nói nội dung gì?',
				],
			];
		}

		return self::normalize_slot_contract(
			[
				'target_slots'       => $target_slots,
				'user_content_slots' => $user_content_slots,
			]
		);
	}

	private static function infer_required_params( $action, $input_schema ) {
		$map = [
			'orders.get_details'                   => [ 'order_ids' ],
			'orders.regenerate_download_permissions' => [ 'order_id', 'expected_version', 'expected_download_permissions_state' ],
			'orders.get_download_permissions' => [ 'order_id' ],
			'orders.get_email_history' => [ 'order_id' ],
			'orders.get_payment_context'           => [ 'order_id' ],
			'orders.get_payment_link'           => [ 'order_id' ],
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
			'orders.send_transactional_email'      => [ 'order_id', 'expected_version', 'email_type' ],
			'orders.add_private_note_template'     => [ 'order_ids', 'template' ],
			'orders.add_note'                      => [ 'order_ids', 'note' ],
			'orders.remove_note'                   => [ 'note_ids' ],
			'orders.update_status'                 => [ 'order_ids', 'status' ],
			'orders.restore_statuses'              => [ 'statuses' ],
			'products.adjust_stock'                => [ 'product_id', 'stock_delta', 'expected_version' ],
			'products.create_draft'                => [ 'name' ],
			'products.update_content'              => [ 'product_id', 'field', 'value', 'expected_version' ],
			'products.update_pricing'              => [ 'product_id', 'regular_price', 'expected_version' ],
			'products.update_stock'                => [ 'product_id', 'stock_quantity' ],
			'products.update_stock_status'         => [ 'product_id', 'stock_status' ],
			'coupons.search'                       => [],
			'coupons.lookup'                       => [ 'coupon_code' ],
			'coupons.create'                       => [ 'coupon_code', 'discount_type', 'discount_amount' ],
			'coupons.update'                       => [ 'coupon_id', 'discount_amount', 'expected_version' ],
			'fulfillment.create'                   => [ 'order_id', 'items', 'expected_version' ],
			'fulfillment.add_tracking'             => [ 'order_id', 'fulfillment_id', 'tracking_number', 'shipment_provider', 'expected_version' ],
			'fulfillment.update_tracking'          => [ 'order_id', 'fulfillment_id', 'tracking_number', 'shipment_provider', 'expected_version' ],
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
			'store.get_business_summary'                    => 'sales_report',
			'store.get_daily_briefing'                      => 'store_report',
			'operations.get_summary'                        => 'operations_report',
			'store.get_insights'                            => 'store_signals',
			'operations.get_attention_queue'                => 'operations_attention',
			'operations.get_fulfillment_queue'              => 'fulfillment_review',
			'products.get_inventory_queue'                  => 'inventory_review',
			'products.search'                               => 'product_search',
			'products.get_details'                          => 'product_read',
			'orders.get_timeline'                           => 'order_timeline',
			'fulfillment.get_tracking'                      => 'fulfillment_tracking',
			'content.search'                                => 'content_search',
			'content.get'                                   => 'content_read',
			'content.create_draft'                          => 'content_draft',
			'content.update_draft'                          => 'content_draft',
			'content.preview_changes'                       => 'content_draft',
			'comments.list'                                 => 'comments_read',
			'media.search'                                  => 'media_search',
			'media.get'                                     => 'media_read',
			'site.get_info'                                 => 'site_diagnostics',
			'site.get_health'                               => 'site_diagnostics',
			'site.get_update_status'                        => 'site_diagnostics',
			'products.adjust_stock'                         => 'adjust_product_stock',
			'products.create_draft'                         => 'create_product_draft',
			'products.update_content'                       => 'update_product_content',
			'products.update_pricing'                       => 'update_product_pricing',
			'products.update_stock'                         => 'update_product_stock',
			'products.update_stock_status'                  => 'update_product_stock_status',
			'coupons.search'                                => 'coupon_search',
			'coupons.lookup'                                => 'coupon_lookup',
			'coupons.create'                                => 'coupon_create',
			'coupons.update'                                => 'update_coupon',
			'fulfillment.create'                            => 'create_fulfillment',
			'fulfillment.add_tracking'                      => 'add_tracking',
			'fulfillment.update_tracking'                   => 'update_tracking',
			'customers.get_service_queue'                   => 'customer_service_review',
			'blacklist.get_status' => 'blacklist_status',
			'blacklist.get_order_assessment' => 'blacklist_order_assessment',
			'customers.get_timeline' => 'customer_timeline',
			'customers.get_follow_up_queue' => 'customer_service_review',
			'orders.search'                                 => 'search_orders',
			'orders.get_details'                            => 'get_order_details',
			'orders.regenerate_download_permissions' => 'order_download_permissions_regeneration',
			'orders.get_download_permissions' => 'order_download_permissions',
			'orders.get_email_history' => 'order_email_history',
			'orders.get_payment_context'                    => 'order_payment_context',
			'orders.get_payment_link'                    => 'order_payment_link',
			'orders.find_by_customer'                       => 'find_customer_orders',
			'customers.lookup'                              => 'customer_lookup',
			'orders.update_status'                          => 'update_order_status',
			'orders.refund_preview'                         => 'refund_review',
			'orders.add_note'                               => 'add_note',
			'orders.compose_customer_email'                 => 'customer_email',
			'orders.get_customer_email_drafts'              => 'customer_email',
			'orders.save_customer_email_draft'              => 'customer_email',
			'orders.send_customer_email'                    => 'customer_email',
		];

		return sanitize_key( $map[ $action ] ?? '' );
	}

	private static function family_policy_for_action( $action, $intent_family ) {
		$intent_family = sanitize_key( $intent_family );

		$policies = [
			'store_report' => 'Use for the overview part of the store report. Prefer this family when the person wants a concise broad store check-in instead of a specific sales, operations, or signal slice.',
			'sales_report' => 'Use for the sales part of the store report: revenue, order value, customer activity, product demand, or commercial performance.',
			'operations_report' => 'Use for the operations part of the store report: order flow, backlog, aged queues, workload, or execution pressure.',
			'store_signals' => 'Use for the signals part of the store report: patterns, trends, grouped signals, or cross-domain reads across orders, customers, products, and inventory.',
			'operations_attention' => 'Use for urgent, manual review, or needs-attention queue requests.',
			'fulfillment_review' => 'Use for shipping blockers, fulfillment backlog, or waiting-to-ship queue requests.',
			'inventory_review' => 'Use for stock pressure, low-stock, restock, backorder, or inventory queue requests.',
			'product_search' => 'Use for read-only product discovery and catalog search.',
			'product_read' => 'Use for reading exact product or variation details.',
			'order_timeline' => 'Use for reading one order timeline while preserving internal versus customer-visible note scope.',
			'fulfillment_tracking' => 'Use for reading shipment tracking already stored for one order.',
			'content_search' => 'Use for read-only search and listing of connected-site content.',
			'content_read' => 'Use for reading one specific connected-site content item.',
			'content_draft' => 'Use for creating, updating, or previewing connected-site content drafts. Persisted changes require confirmation.',
			'comments_read' => 'Use for read-only comment and community review.',
			'media_search' => 'Use for read-only media-library search and browsing.',
			'media_read' => 'Use for reading one specific media-library item by its catalog-declared identifier.',
			'site_diagnostics' => 'Use for read-only connected-site information, health, and update diagnostics.',
			'update_product_stock' => 'Use for changing one specific product stock quantity. This is a write action and requires confirmation.',
			'update_product_stock_status' => 'Use for changing one specific product manual stock status to one of the store configured stock statuses. This is a write action and requires confirmation.',
			'coupon_search' => 'Use for a bounded newest-first live coupon listing or text search over coupon codes. This is read-only, not exact lookup, mutation or customer usage history.',
			'coupon_lookup' => 'Use for looking up one specific coupon by coupon code or coupon ID. This is read-only.',
			'update_coupon' => 'Change only one exact coupon discount amount after an authenticated lookup and confirmation. Preserve code, type and restrictions; no bulk or usage history.',
			'coupon_create' => 'Use for creating one new WooCommerce coupon with a code, discount type, and discount amount. This is a write action and requires confirmation.',
			'customer_service_review' => 'Use for reply load, follow-up load, or customer-service queue requests.',
			'customer_email' => 'Use for composing, previewing, saving, or sending customer email work. Treat a natural-language goal as instruction when it is already present.',
			'update_order_status' => 'Use for status changes on one or more orders. Clarify only when status or target order is still unresolved.',
			'add_note' => 'Use for adding notes to one or more orders. Clarify only when note content or target order is unresolved.',
			'search_orders' => 'Use for read-only order search, filtering, or listing requests.',
			'get_order_details' => 'Use for inspecting one specific order in detail.',
			'order_payment_context' => 'Use for payment-status or payment-evidence questions about one grounded order. WooCommerce facts do not independently verify gateway capture, settlement, or confirmation. Clarify missing or ambiguous targets; never substitute a search, refund, payment link, or communication.',
		];

		if ( ! empty( $policies[ $intent_family ] ) ) {
			return $policies[ $intent_family ];
		}

		if ( 'email_templates.save' === $action ) {
			return 'Use for saving reusable email templates. Collect only the missing template slots instead of guessing.';
		}

		return '';
	}

	private static function reporting_contract_for_action( $name ) {
		$name = sanitize_text_field( $name );

		$contracts = [
			'store.get_daily_briefing'   => [
				'user_surface'   => 'store_report',
				'report_type'    => 'store_overview',
				'topic'          => 'overview',
				'role'           => 'Best for quick check-ins and broad store-health snapshots.',
				'metric_domains' => [ 'orders', 'revenue', 'customers', 'operations', 'inventory' ],
			],
			'store.get_business_summary' => [
				'user_surface'   => 'store_report',
				'report_type'    => 'business',
				'topic'          => 'sales',
				'role'           => 'Best for revenue, sales, customer activity, product demand, and commercial performance.',
				'metric_domains' => [ 'revenue', 'orders', 'customers', 'products' ],
			],
			'operations.get_summary'     => [
				'user_surface'   => 'store_report',
				'report_type'    => 'operations',
				'topic'          => 'operations',
				'role'           => 'Best for order flow, backlog, aged queues, workload, and execution pressure.',
				'metric_domains' => [ 'orders', 'backlog', 'attention', 'fulfillment' ],
			],
			'store.get_insights'         => [
				'user_surface'   => 'store_report',
				'report_type'    => 'store_insights',
				'topic'          => 'signals',
				'role'           => 'Best for patterns, signals, trends, and grouped reads across orders, customers, and products.',
				'metric_domains' => [ 'orders', 'customers', 'products', 'inventory' ],
			],
		];

		return $contracts[ $name ] ?? [];
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
				'Show today\'s store report',
				'Give me a store report for the last 3 days',
				'Cho tôi xem báo cáo cửa hàng hôm nay',
				'Báo cáo cửa hàng 3 ngày qua',
			],
			'store.get_business_summary' => [
				'Show sales report this week',
				'Show the sales part of the store report for the last 4 days',
				'Cho tôi xem báo cáo doanh thu tuần này',
				'Cho tôi xem phần doanh thu tuần này',
			],
			'operations.get_summary' => [
				'Show operations report for this week',
				'Show the operations part of the store report for the last 5 days',
				'Cho tôi xem báo cáo vận hành hôm nay',
			],
			'store.get_insights' => [
				'Show store signals for this week',
				'What patterns do you see in the store report?',
				'Cho tôi xem tín hiệu cửa hàng tuần này',
			],
			'operations.get_attention_queue' => [
				'Show operations attention queue',
				'What needs attention right now?',
				'Có việc gì cần xử lý gấp không?',
			],
			'operations.get_fulfillment_queue' => [
				'Show fulfillment queue',
				'What orders are waiting to ship?',
				'Đơn nào đang chờ giao hàng?',
			],
			'products.get_inventory_queue' => [
				'Show inventory queue',
				'Show low stock products',
				'Sản phẩm nào sắp hết hàng?',
			],
			'products.update_stock' => [
				'Set product #123 stock to 8',
				'Set SKU HOODIE-BLUE stock to 12',
				'Set GTIN 00012345678905 stock to 0',
				'Update stock for product 123 to 0',
				'Cập nhật tồn kho sản phẩm #123 thành 8',
			],
			'products.update_pricing' => [
				'Set product #123 regular price to 19.95',
				'Set variation #456 regular price to 0',
				'Cập nhật giá thường của sản phẩm #123 thành 19.95',
			],
			'products.update_content' => [
				'Change product #123 name to Summer Hoodie',
				'Clear the description of product #123',
				'Cập nhật mô tả ngắn của sản phẩm #123 thành Áo khoác nhẹ',
			],
			'products.create_draft' => [
				'Create a product draft named Summer Hoodie',
				'Prepare an unpublished simple product called Trail Jacket',
				'Tạo sản phẩm nháp tên Áo khoác nhẹ',
			],
			'products.update_stock_status' => [
				'Set product #123 stock status to out of stock',
				'Set SKU HOODIE-BLUE stock status to out of stock',
				'Set GTIN 00012345678905 stock status to on backorder',
				'Mark product 123 as on backorder',
				'Cập nhật trạng thái tồn kho sản phẩm #123 thành hết hàng',
			],
			'customers.get_service_queue' => [
				'Open customer service queue',
				'Who needs customer follow-up?',
				'Khách nào cần chăm sóc tiếp?',
			],
		];
	}
}
