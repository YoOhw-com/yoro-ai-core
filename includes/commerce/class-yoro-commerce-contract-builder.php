<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Contract_Builder {

	const CONNECTOR_KEY = 'wordpress';
	const SCHEMA_VERSION = 1;
	const EXECUTION_PAYLOAD_VERSION = 1;
	const CAPABILITY_AUTHORITY_CATALOG_VERSION = 'yoro.capability_authority@v1';
	const CAPABILITY_AUTHORITY_CATALOG_DIGEST = 'sha256:d66c7dbad96abbc0ff98a2a4ff55e0bd38e0e42050623e142337b16185103719';
	const MANIFEST_TTL = 30 * DAY_IN_SECONDS;
	const CONTEXT_TTL = 20 * MINUTE_IN_SECONDS;

	public static function build_capability_manifest( array $runtime, $revision ) {
		$connection_id = self::connection_id( $runtime );

		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		$captured_at = time();
		$actions = [];

		foreach ( YoRo_Action_Registry::all() as $name => $action ) {
			if ( ! is_array( $action ) || empty( $action['ai_exposed'] ) ) {
				continue;
			}

			if ( 'wordpress_admin' === sanitize_key( $action['category'] ?? '' ) ) {
				continue;
			}

			$canonical = self::canonical_action( $name, $action );

			if ( is_array( $canonical ) ) {
				$actions[] = $canonical;
			}
		}

		usort(
			$actions,
			static function ( $left, $right ) {
				return strcmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
			}
		);

		$connector_key = self::connector_key( $runtime );
		$extensions = [
			'wordpress.runtime' => [
				'plugin_version'     => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
				'execution_boundary' => 'plugin_executor',
				'modules'            => YoRo_WordPress_Site_Actions::modules(),
				'admin_actions_ready' => false,
			],
		];
		if ( yoro_ai_core_has_woocommerce() ) {
			$extensions['yoro.early_access_commerce_writes'] = YoRo_Early_Access_Commerce_Write::manifest();
            $extensions['yoro.early_access_product_edit'] = YoRo_Product_Edit::manifest();
            $extensions[YoRo_Product_Reviews::ManifestExtension] = YoRo_Product_Reviews::manifest();
            $extensions[YoRo_Customer_History_Report::ManifestExtension] = YoRo_Customer_History_Report::manifest();
            $extensions[YoRo_Product_Lifecycle::ManifestExtension] = YoRo_Product_Lifecycle::manifest();
            $extensions['yoro.early_access_product_batch'] = YoRo_Product_Batch::manifest();
            $extensions[YoRo_Order_Management::ManifestExtension] = YoRo_Order_Management::manifest();
            $extensions['yoro.early_access_order_batch'] = YoRo_Order_Batch::manifest();
            $extensions[YoRo_Coupon_Management::ManifestExtension] = YoRo_Coupon_Management::manifest();
            $extensions['yoro.early_access_catalog_options'] = YoRo_Product_Catalog_Options::manifest();
            $extensions[YoRo_Variable_Product::Extension] = YoRo_Variable_Product::manifest();
            $extensions['yoro.early_access_customer_service'] = YoRo_Early_Access_Customer_Service_Read::manifest();
            $extensions['yoro.early_access_wordpress_read'] = YoRo_Early_Access_WordPress_Read::manifest();
            $extensions['yoro.early_access_order_reads'] = [ 'version' => 1, 'actions' => YoRo_Early_Access_Order_Read::ACTIONS, 'max_items' => 20,
                'customer_data' => 'redacted', 'pagination' => 'page_v1', 'timeline' => 'milestones_only' ];
            $extensions['yoro.early_access_order_details'] = YoRo_Early_Access_Order_Read::details_manifest();
            $extensions['yoro.early_access_order_search'] = YoRo_Early_Access_Order_Search::manifest();
            $extensions['yoro.early_access_order_phone'] = [ 'version' => 1, 'semantics' => 'region_bound_number_v1', 'max_groups' => 32 ];
			$extensions['yoro.early_access_order_queues'] = [ 'version' => 1, 'actions' => YoRo_Early_Access_Order_Queue_Read::ACTIONS, 'max_items' => 20,
				'scan_limit' => 100, 'customer_data' => 'redacted', 'semantics' => 'oldest_candidate_snapshot_v1' ];
			$extensions['yoro.early_access_order_tracking'] = [ 'version' => 1, 'actions' => YoRo_Early_Access_Order_Tracking_Read::ACTIONS, 'max_shipments' => 5,
				'source_items' => 20, 'custom_links' => false, 'provider_verified' => false, 'customer_data' => 'redacted' ];
            $extensions['yoro.early_access_store_overview'] = YoRo_Complete_Store_Report::manifest();
            $extensions['yoro.early_access_product_reads'] = [
                'version' => 1, 'actions' => YoRo_Early_Access_Product_Read::ACTIONS, 'max_items' => 50,
            ];
            $extensions['woocommerce.runtime'] = [
				'active' => true,
			];
		}
		$extensions['yoro.capability_authority'] = self::capability_authority_binding( $actions );

		return [
			'contract_type'          => 'yoro.commerce.capability_manifest',
			'schema_version'         => self::SCHEMA_VERSION,
			'manifest_id'            => self::manifest_id( $connection_id ),
			'revision'               => max( 1, absint( $revision ) ),
			'connector_key'          => $connector_key,
			'connector_connection_id' => $connection_id,
			'generated_at'           => gmdate( 'c', $captured_at ),
			'expires_at'             => gmdate( 'c', $captured_at + self::MANIFEST_TTL ),
			'actions'                => $actions,
			'extensions'             => $extensions,
		];
	}

	public static function capability_authority_binding( array $actions ) {
		$support = [];

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) || '' === sanitize_text_field( $action['name'] ?? '' ) ) {
				continue;
			}

			$support[] = [
				'action_id' => sanitize_text_field( $action['name'] ),
				'support'   => 'supported',
				'coverage'  => 'full',
			];
		}

		usort(
			$support,
			static function ( $left, $right ) {
				return strcmp( (string) ( $left['action_id'] ?? '' ), (string) ( $right['action_id'] ?? '' ) );
			}
		);

		return [
			'catalog_version' => self::CAPABILITY_AUTHORITY_CATALOG_VERSION,
			'catalog_digest'  => self::CAPABILITY_AUTHORITY_CATALOG_DIGEST,
			'binding'         => 'exact',
			'support'         => $support,
		];
	}

	public static function build_context_pack( array $runtime, array $manifest, $revision ) {
		$connection_id = self::connection_id( $runtime );

		if ( is_wp_error( $connection_id ) ) {
			return $connection_id;
		}

		$captured_at = time();
		$context = YoRo_Context_Provider::get_context();
		$store = is_array( $context['store'] ?? null ) ? $context['store'] : [];
		$woocommerce = is_array( $context['woocommerce'] ?? null ) ? $context['woocommerce'] : [];
		$wordpress = is_array( $context['wordpress'] ?? null ) ? $context['wordpress'] : [];

		$connector_key = self::connector_key( $runtime );
		$store_data = [
			'name'            => sanitize_text_field( $store['name'] ?? get_bloginfo( 'name' ) ),
			'timezone'        => sanitize_text_field( $store['timezone'] ?? wp_timezone_string() ),
			'locale'          => sanitize_text_field( $store['locale'] ?? get_locale() ),
			'order_statuses'  => self::safe_list( $woocommerce['order_statuses'] ?? [] ),
			'payment_methods' => self::safe_list( $woocommerce['payment_gateways'] ?? [] ),
		];
		$currency = sanitize_text_field( $woocommerce['currency'] ?? '' );
		$currency_symbol = sanitize_text_field( $woocommerce['currency_symbol'] ?? '' );

		if ( '' !== $currency ) {
			$store_data['currency'] = $currency;
		}

		if ( '' !== $currency_symbol ) {
			$store_data['currency_symbol'] = $currency_symbol;
		}

		$integration_data = [
			'connector_version'        => defined( 'YORO_AI_CORE_VERSION' ) ? YORO_AI_CORE_VERSION : '',
			'platform_version'         => sanitize_text_field( $wordpress['version'] ?? '' ),
			'commerce_platform_active' => ! empty( $woocommerce['active'] ),
		];
		$commerce_platform_version = sanitize_text_field( $woocommerce['version'] ?? '' );

		if ( '' !== $commerce_platform_version ) {
			$integration_data['commerce_platform_version'] = $commerce_platform_version;
		}

		return [
			'contract_type'          => 'yoro.commerce.context_pack',
			'schema_version'         => self::SCHEMA_VERSION,
			'context_pack_id'        => self::context_pack_id( $connection_id ),
			'revision'               => max( 1, absint( $revision ) ),
			'connector_key'          => $connector_key,
			'connector_connection_id' => $connection_id,
			'manifest_id'            => sanitize_text_field( $manifest['manifest_id'] ?? '' ),
			'manifest_revision'      => max( 1, absint( $manifest['revision'] ?? 0 ) ),
			'captured_at'            => gmdate( 'c', $captured_at ),
			'expires_at'             => gmdate( 'c', $captured_at + self::CONTEXT_TTL ),
			'domains'                => [
				[
					'name'        => 'store',
					'source'      => ! empty( $woocommerce['active'] ) ? 'wordpress_with_woocommerce_settings' : 'wordpress_live_settings',
					'captured_at' => gmdate( 'c', $captured_at ),
					'data'        => $store_data,
					'extensions'  => [
						'woocommerce.settings' => [
							'source_version' => 1,
							'hpos_enabled'   => ! empty( $woocommerce['hpos_enabled'] ),
						],
					],
				],
				[
					'name'        => 'integrations',
					'source'      => 'connector_runtime',
					'captured_at' => gmdate( 'c', $captured_at ),
					'data'        => $integration_data,
					'extensions'  => [
						'wordpress.runtime' => [
							'installation_uuid' => YoRo_AI_Settings::get_installation_uuid(),
						],
						'woocommerce.runtime' => [
							'hpos_enabled' => ! empty( $woocommerce['hpos_enabled'] ),
						],
					],
				],
			],
		];
	}

	public static function manifest_id( $connection_id ) {
		return 'manifest_wordpress_' . substr( hash( 'sha256', (string) $connection_id ), 0, 40 );
	}

	public static function context_pack_id( $connection_id ) {
		return 'context_wordpress_' . substr( hash( 'sha256', (string) $connection_id ), 0, 40 );
	}

	private static function canonical_action( $name, array $action ) {
		$name = sanitize_text_field( $name );
		$catalog_input_schema = is_array( $action['canonical_input_schema'] ?? null )
			? $action['canonical_input_schema']
			: ( $action['input_schema'] ?? [] );

		if ( 'store.get_daily_briefing' === $name ) {
			$catalog_input_schema = [
				'window'       => [ 'type' => 'string', 'maxLength' => 120 ],
                ...array_fill_keys( array_values( array_diff( YoRo_Store_Report_Period::Fields, [ 'window' ] ) ), [ 'type' => 'string' ] ),
				'time_window'  => self::resolved_time_window_input_schema(),
				'answer_shape' => [ 'type' => 'string', 'enum' => [ 'business_overview' ] ],
				'limit'        => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 10 ],
			];
		}

		if ( 'operations.get_attention_queue' === $name ) {
			$catalog_input_schema = [
				'limit'                => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ],
				'high_value_threshold' => [ 'type' => 'number', 'minimum' => 0 ],
				'answer_shape'         => [ 'type' => 'string', 'enum' => [ 'attention_queue' ] ],
			];
		}

		if ( 'content.search' === $name && array_key_exists( 'post_type', $catalog_input_schema ) ) {
			$catalog_input_schema['post_type'] = [
				'type' => 'string',
				'enum' => YoRo_WordPress_Content_Actions::allowed_post_types(),
			];
		}

		$input_schema = self::object_schema(
			$catalog_input_schema,
			$action['required_params'] ?? []
		);

		if ( '' === $name || is_wp_error( $input_schema ) ) {
			return null;
		}

		$effect = sanitize_key( $action['effect'] ?? ( ! empty( $action['write_action'] ) ? 'mutate' : 'read' ) );
		$risk_level = sanitize_key( $action['risk_level'] ?? 'low' );
		$reversibility = sanitize_key( $action['reversibility'] ?? ( ! empty( $action['write_action'] ) ? 'partial' : 'full' ) );
		$permission = sanitize_key( $action['permission'] ?? '' );
		$is_write = ! empty( $action['write_action'] );
		$idempotency_strategy = sanitize_key( $action['idempotency_strategy'] ?? '' );
		$concurrency_strategy = sanitize_key( $action['concurrency_strategy'] ?? '' );
		$conversation_semantics = YoRo_Action_Catalog_Builder::semantic_contract( $name, $action );

		if ( ! in_array( $effect, [ 'read', 'draft', 'mutate', 'communicate', 'delete', 'financial' ], true )
			|| ! in_array( $risk_level, [ 'low', 'medium', 'high', 'critical' ], true )
			|| ! in_array( $reversibility, [ 'full', 'partial', 'none' ], true )
			|| '' === $permission ) {
			return null;
		}

		if ( $is_write
			&& ( 'operation_journal' !== $idempotency_strategy
				|| ! in_array( $concurrency_strategy, [ 'optimistic_version', 'per_item_optimistic_version', 'create_only', 'append_only', 'external_delivery', 'idempotent_set' ], true ) ) ) {
			return null;
		}

		return [
			'name'                  => $name,
			'label'                 => sanitize_text_field( $action['label'] ?? $name ) ?: $name,
			'description'           => sanitize_text_field( $action['ai_description'] ?? ( $action['description'] ?? $name ) ) ?: $name,
			'effect'                => $effect,
			'risk_level'            => $risk_level,
			'reversibility'         => $reversibility,
			'external_side_effect'  => ! empty( $action['external_side_effect'] ),
			'write_action'          => $is_write,
			'requires_confirmation' => ! empty( $action['requires_confirmation'] ) || ! empty( $action['write_action'] ),
			'max_targets'           => max( 1, absint( $action['max_targets'] ?? ( $action['max_items'] ?? 1 ) ) ),
			'input_schema'          => $input_schema,
			'output_schema'         => self::execution_output_schema( $name ),
			'required_params'       => $input_schema['required'],
			'required_permissions'  => [ $permission ],
			'extensions'            => [
				'wordpress.action' => [
					'registry_action' => $name,
					'idempotency_strategy' => $is_write ? $idempotency_strategy : 'none',
					'concurrency_strategy' => $is_write ? $concurrency_strategy : 'none',
				],
				'yoro.contracts' => self::execution_contract_extension( $name ),
				'yoro.conversation' => $conversation_semantics,
			],
		];
	}

	public static function execution_contract_extension( $action_name = '' ) {
		return [
			'execution_payload'              => 'canonical_action_result',
			'execution_payload_version'      => self::EXECUTION_PAYLOAD_VERSION,
			'fixture_revision'               => 3,
			'strict_output_validation_ready' => self::strict_output_validation_ready( $action_name ),
		];
	}

	public static function strict_output_validation_ready( $action_name ) {
		$output_schema = self::execution_output_schema( $action_name );
		$data_schema   = $output_schema['properties']['data'] ?? null;

		return 'object' === ( $data_schema['type'] ?? null )
			&& self::is_strict_schema_node( $output_schema );
	}

	public static function execution_output_schema( $action_name = '' ) {
		return [
			'type'                 => 'object',
			'properties'           => [
				'success'           => [ 'type' => 'boolean' ],
				'action'            => [ 'type' => 'string' ],
				'message'           => [ 'type' => 'string' ],
				'summary'           => [ 'type' => 'string' ],
				'data'              => self::execution_data_schema( $action_name ),
				'read_only'         => [ 'type' => 'boolean' ],
				'undoable'          => [ 'type' => 'boolean' ],
				'result_type'       => [ 'type' => 'string' ],
				'display_type'      => [ 'type' => 'string' ],
				'assistant_summary' => [ 'type' => 'string' ],
				'extensions'        => [ 'type' => 'object' ],
			],
			'required'             => [ 'success', 'action', 'message', 'summary', 'data', 'read_only', 'undoable', 'result_type', 'display_type', 'assistant_summary', 'extensions' ],
			'additionalProperties' => false,
		];
	}

	public static function canonical_execution_payload( array $execution, $action_name = '' ) {
		$stable_keys = [ 'success', 'action', 'message', 'summary', 'data', 'read_only', 'undoable', 'result_type', 'display_type', 'assistant_summary', 'meta', 'processed', 'skipped', 'errors', 'count', 'undo_plan', 'extensions' ];
		$action_name = sanitize_text_field( $action_name ?: ( $execution['action'] ?? '' ) );
		$data = is_array( $execution['data'] ?? null ) ? $execution['data'] : [];

		if ( self::is_list_array( $data ) && [] !== $data ) {
			$data = [ 'items' => $data ];
		}

		foreach ( array_diff_key( $execution, array_flip( $stable_keys ) ) as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' !== $key ) {
				$data[ $key ] = $value;
			}
		}

		$legacy_data = $data;

		if ( self::is_canonical_read_action( $action_name ) ) {
			$data = self::canonical_read_data( $action_name, $execution );
		} elseif ( self::is_canonical_write_action( $action_name ) ) {
			$data = self::canonical_write_data( $action_name, $execution );
		}

		$summary = sanitize_text_field( $execution['summary'] ?? ( $execution['message'] ?? '' ) );
		$message = sanitize_text_field( $execution['message'] ?? $summary );

		return [
			'success'           => ! empty( $execution['success'] ),
			'action'            => $action_name,
			'message'           => $message,
			'summary'           => $summary,
			'data'              => $data,
			'read_only'         => ! empty( $execution['read_only'] ),
			'undoable'          => ! empty( $execution['undoable'] ),
			'result_type'       => sanitize_key( $execution['result_type'] ?? 'raw' ) ?: 'raw',
			'display_type'      => sanitize_key( $execution['display_type'] ?? 'raw' ) ?: 'raw',
			'assistant_summary' => sanitize_text_field( $execution['assistant_summary'] ?? $summary ),
			'extensions'        => [
				'wordpress.execution' => [
					'meta'      => is_array( $execution['meta'] ?? null ) ? $execution['meta'] : [],
					'processed' => is_array( $execution['processed'] ?? null ) ? $execution['processed'] : [],
					'skipped'   => is_array( $execution['skipped'] ?? null ) ? $execution['skipped'] : [],
					'errors'    => is_array( $execution['errors'] ?? null ) ? $execution['errors'] : [],
					'count'     => absint( $execution['count'] ?? 0 ),
					'undo_plan' => is_array( $execution['undo_plan'] ?? null ) ? $execution['undo_plan'] : [],
					'legacy_data' => self::is_canonical_read_action( $action_name ) ? $legacy_data : [],
				],
			],
		];
	}

	private static function execution_data_schema( $action_name ) {
		$action_name = sanitize_text_field( $action_name );

		if ( 'orders.update_status' === $action_name ) {
			return self::closed_object_schema(
				[
					'processed' => self::array_schema( [ 'type' => 'integer' ] ),
					'status' => [ 'type' => 'string' ],
					'status_label' => [ 'type' => 'string' ],
					'current_versions' => self::array_schema(
						self::closed_object_schema(
							[
								'order_id' => [ 'type' => 'integer' ],
								'expected_version' => [ 'type' => 'string' ],
							],
							[ 'order_id', 'expected_version' ]
						)
					),
				],
				[ 'processed', 'status', 'status_label', 'current_versions' ]
			);
		}

        if (class_exists('YoRo_Product_Reviews') && in_array($action_name,[...YoRo_Product_Reviews::ReadActions,...YoRo_Product_Reviews::WriteActions],true)) { return YoRo_Product_Reviews::result_schema($action_name); }
        if (class_exists('YoRo_Customer_History_Report') && in_array($action_name,YoRo_Customer_History_Report::ReadActions,true)) { return YoRo_Customer_History_Report::result_schema($action_name); }
        if (class_exists('YoRo_Product_Lifecycle') && in_array($action_name,[...YoRo_Product_Lifecycle::ReadActions,...YoRo_Product_Lifecycle::WriteActions],true)) { return YoRo_Product_Lifecycle::result_schema($action_name); }
        if (class_exists('YoRo_Order_Batch') && $action_name === YoRo_Order_Batch::Action) { return YoRo_Order_Batch::result_schema(); }
        if (class_exists('YoRo_Order_Management') && in_array($action_name,[...YoRo_Order_Management::ReadActions,...YoRo_Order_Management::WriteActions],true)) { return YoRo_Order_Management::result_schema($action_name); }
        if (class_exists('YoRo_Product_Batch') && $action_name === YoRo_Product_Batch::Action) { return YoRo_Product_Batch::result_schema(); }
        if (class_exists('YoRo_Coupon_Management') && in_array($action_name,array_merge(YoRo_Coupon_Management::ReadActions,YoRo_Coupon_Management::WriteActions),true)) {
            return YoRo_Coupon_Management::result_schema($action_name);
        }
        if (in_array($action_name, ['products.update_attributes', 'products.create_variation'], true)) {
            $fields=['product_id'=>['type'=>'integer'],'changes'=>self::closed_object_schema(YoRo_Variable_Product::properties($action_name), []),'current_version'=>['type'=>'string']];
            if($action_name==='products.create_variation'){$fields['variation_id']=['type'=>'integer'];$fields['variation_version']=['type'=>'string'];}
            return self::closed_object_schema($fields,array_keys($fields));
        }
        if ($action_name==='products.get_variable_details') {
            return self::closed_object_schema(['products'=>self::array_schema(self::canonical_product_schema()),'variations'=>self::array_schema(self::canonical_product_schema()),
                'variable'=>YoRo_Variable_Product::factsSchema(),'count'=>['type'=>'integer'],
                'page_info'=>self::closed_object_schema(['page'=>['type'=>'integer'],'limit'=>['type'=>'integer'],'total'=>['type'=>'integer'],'has_more'=>['type'=>'boolean']],['page','limit','total','has_more'])],
                ['products','variations','variable','count','page_info']);
        }
        if ('products.update_details' === $action_name) {
            return self::closed_object_schema(['product_id'=>['type'=>'integer'],
                'changes'=>self::closed_object_schema(YoRo_Product_Edit::properties(), []),
                'current_version'=>['type'=>'string']], ['product_id','changes','current_version']);
        }
		if ( 'products.update_stock' === $action_name ) {
			return self::closed_object_schema(
				[
					'product_id' => [ 'type' => 'integer' ],
					'stock_quantity' => [ 'type' => 'integer' ],
					'stock_status' => [ 'type' => 'string' ],
					'manage_stock' => [ 'type' => 'boolean' ],
					'current_version' => [ 'type' => 'string' ],
				],
				[ 'product_id', 'stock_quantity', 'stock_status', 'manage_stock', 'current_version' ]
			);
		}

		if ( 'products.update_stock_status' === $action_name ) {
			return self::closed_object_schema(
				[
					'product_id' => [ 'type' => 'integer' ],
					'stock_status' => [ 'type' => 'string' ],
					'manage_stock' => [ 'type' => 'boolean' ],
					'current_version' => [ 'type' => 'string' ],
				],
				[ 'product_id', 'stock_status', 'manage_stock', 'current_version' ]
			);
		}

		if ( in_array( $action_name, [ 'orders.search', 'orders.find_by_customer' ], true ) ) {
			return self::closed_object_schema(
				[
					'orders'    => self::array_schema( self::canonical_order_schema() ),
					'page_info' => self::canonical_page_info_schema(),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'orders', 'page_info', 'count' ]
			);
		}

		if ( 'orders.get_details' === $action_name ) {
			return self::closed_object_schema(
				[
					'orders' => self::array_schema( self::canonical_order_schema( true ) ),
					'count'  => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'orders', 'count' ]
			);
		}

		if ( 'customers.lookup' === $action_name ) {
			return self::closed_object_schema(
				[
					'customers' => self::array_schema( self::canonical_customer_schema() ),
					'page_info' => self::canonical_page_info_schema(),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'customers', 'page_info', 'count' ]
			);
		}

		if ( 'store.get_daily_briefing' === $action_name ) {
			return self::closed_object_schema(
				[
					'window'          => self::canonical_reporting_window_schema(),
					'metrics'         => self::canonical_briefing_metrics_schema(),
					'comparison'      => self::canonical_briefing_comparison_schema(),
					'counts'          => self::canonical_briefing_counts_schema(),
					'attention_items' => self::array_schema( self::canonical_attention_item_schema() ),
					'inventory_items' => self::array_schema( self::canonical_inventory_item_schema() ),
					'priorities'      => self::array_schema( self::canonical_briefing_priority_schema() ),
				],
				[ 'window', 'metrics', 'comparison', 'counts', 'attention_items', 'inventory_items', 'priorities' ]
			);
		}

		if ( 'operations.get_attention_queue' === $action_name ) {
			return self::closed_object_schema(
				[
					'items'         => self::array_schema( self::canonical_attention_item_schema() ),
					'page_info'     => self::canonical_page_info_schema(),
					'count'         => [ 'type' => 'integer', 'minimum' => 0 ],
					'total_matches' => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'items', 'page_info', 'count', 'total_matches' ]
			);
		}

		if ( 'products.get_inventory_queue' === $action_name ) {
			return self::closed_object_schema(
				[
					'items'     => self::array_schema( self::canonical_inventory_item_schema() ),
					'page_info' => self::canonical_page_info_schema(),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'items', 'page_info', 'count' ]
			);
		}

		if ( 'content.search' === $action_name ) {
			return self::closed_object_schema(
				[
					'items' => self::array_schema( self::canonical_content_item_schema() ),
					'count' => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'items', 'count' ]
			);
		}

		if ( 'content.get' === $action_name ) {
			return self::closed_object_schema(
				[ 'item' => self::canonical_content_item_schema() ],
				[ 'item' ]
			);
		}

		if ( 'comments.list' === $action_name ) {
			return self::closed_object_schema(
				[
					'items' => self::array_schema( self::canonical_comment_schema() ),
					'count' => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'items', 'count' ]
			);
		}

		if ( 'media.search' === $action_name ) {
			return self::closed_object_schema(
				[
					'items' => self::array_schema( self::canonical_media_item_schema() ),
					'count' => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'items', 'count' ]
			);
		}

		if ( 'media.get' === $action_name ) {
			return self::closed_object_schema(
				[ 'item' => self::canonical_media_item_schema() ],
				[ 'item' ]
			);
		}

		if ( 'site.get_info' === $action_name ) {
			return self::closed_object_schema(
				[ 'site' => self::canonical_site_info_schema() ],
				[ 'site' ]
			);
		}

		if ( 'site.get_health' === $action_name ) {
			return self::closed_object_schema(
				[ 'health' => self::canonical_site_health_schema() ],
				[ 'health' ]
			);
		}

		if ( 'site.get_update_status' === $action_name ) {
			return self::closed_object_schema(
				[ 'updates' => self::canonical_site_update_status_schema() ],
				[ 'updates' ]
			);
		}

		if ( 'products.search' === $action_name ) {
			return self::closed_object_schema(
				[
					'products'  => self::array_schema( self::canonical_product_schema() ),
					'page_info' => self::canonical_page_info_schema(),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'products', 'page_info', 'count' ]
			);
		}

        if (class_exists('YoRo_Product_Catalog_Options') && YoRo_Product_Catalog_Options::Action === $action_name) {
            return self::closed_object_schema(['kind' => ['type' => 'string', 'enum' => ['categories','tags','images']],
                'items' => ['type' => 'array', 'maxItems' => 20, 'items' => self::closed_object_schema([
                    'id' => ['type' => 'integer', 'minimum' => 1], 'name' => ['type' => 'string', 'maxLength' => 200]], ['id','name'])],
                'count' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20],
                'page_info' => self::closed_object_schema(['page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                    'per_page' => ['type' => 'integer', 'enum' => [20]], 'has_next_page' => ['type' => 'boolean'],
                    'next_page' => ['type' => ['integer','null'], 'minimum' => 2, 'maximum' => 1000]], ['page','per_page','has_next_page','next_page'])],
                ['kind','items','count','page_info']);
        }

		if ( 'products.get_details' === $action_name ) {
			return self::closed_object_schema(
				[
					'products' => self::array_schema( self::canonical_product_schema() ),
					'count'    => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'products', 'count' ]
			);
		}

		if ( 'orders.get_timeline' === $action_name ) {
			return self::closed_object_schema(
				[
					'order_id' => [ 'type' => [ 'string', 'integer' ] ],
					'events'   => self::array_schema( self::canonical_timeline_event_schema() ),
					'count'    => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'order_id', 'events', 'count' ]
			);
		}

		if ( 'fulfillment.get_tracking' === $action_name ) {
			return self::closed_object_schema(
				[
					'order_id'  => [ 'type' => [ 'string', 'integer' ] ],
					'status'    => [ 'type' => 'string' ],
					'shipments' => self::array_schema( self::canonical_tracking_shipment_schema() ),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
				],
				[ 'order_id', 'status', 'shipments', 'count' ]
			);
		}

		return [ 'type' => 'object' ];
	}

	private static function canonical_order_schema( $include_details = false ) {
		$properties = [
				'id'           => [ 'type' => [ 'string', 'integer' ] ],
				'number'       => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
				'status_label' => [ 'type' => 'string' ],
				'total'        => [ 'type' => [ 'string', 'number' ] ],
				'currency'     => [ 'type' => 'string' ],
				'created_at'   => [ 'type' => 'string' ],
				'version'      => [ 'type' => 'string' ],
				'payment'      => self::canonical_payment_schema(),
				'fulfillment'  => self::canonical_fulfillment_schema(),
				'signals'      => self::array_schema( self::canonical_signal_schema() ),
				'suggested_next_steps' => self::array_schema( self::canonical_suggested_next_step_schema() ),
				'extensions'   => [ 'type' => 'object' ],
			];
		$required = [ 'id', 'number', 'status', 'status_label', 'total', 'currency', 'created_at', 'version', 'payment', 'fulfillment', 'signals', 'suggested_next_steps', 'extensions' ];

		if ( $include_details ) {
			$properties['customer'] = self::closed_object_schema(
				[
					'id'    => [ 'type' => [ 'string', 'integer', 'null' ] ],
					'name'  => [ 'type' => 'string' ],
					'email' => [ 'type' => 'string' ],
					'phone' => [ 'type' => 'string' ],
				],
				[ 'id', 'name', 'email', 'phone' ]
			);
			$properties['line_items'] = self::array_schema(
				self::closed_object_schema(
					[
						'name'     => [ 'type' => 'string' ],
						'quantity' => [ 'type' => 'integer' ],
						'sku'      => [ 'type' => 'string' ],
						'total'    => [ 'type' => [ 'string', 'number' ] ],
					],
					[ 'name', 'quantity', 'sku', 'total' ]
				)
			);
			$properties['timeline'] = self::array_schema( self::canonical_investigation_timeline_event_schema() );
			$properties['related_orders'] = self::array_schema( self::canonical_related_order_schema() );
			$properties['support_context'] = self::canonical_support_context_schema();
			$required = array_merge( $required, [ 'customer', 'line_items', 'timeline', 'related_orders', 'support_context' ] );
		}

		return self::closed_object_schema( $properties, $required );
	}

	private static function canonical_payment_schema() {
		return self::closed_object_schema(
			[
				'status'   => [ 'type' => 'string' ],
				'paid'     => [ 'type' => 'boolean' ],
				'failed'   => [ 'type' => 'boolean' ],
				'refunded' => [ 'type' => 'boolean' ],
				'amount'   => [ 'type' => [ 'string', 'number' ] ],
				'currency' => [ 'type' => 'string' ],
			],
			[ 'status', 'paid', 'failed', 'refunded', 'amount', 'currency' ]
		);
	}

	private static function canonical_fulfillment_schema() {
		return self::closed_object_schema(
			[
				'status'            => [ 'type' => 'string' ],
				'fulfilled'         => [ 'type' => 'boolean' ],
				'requires_shipping' => [ 'type' => 'boolean' ],
				'tracking_count'    => [ 'type' => 'integer', 'minimum' => 0 ],
			],
			[ 'status', 'fulfilled', 'requires_shipping', 'tracking_count' ]
		);
	}

	private static function canonical_signal_schema() {
		return self::closed_object_schema(
			[
				'code'     => [ 'type' => 'string' ],
				'severity' => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high', 'critical' ] ],
				'summary'  => [ 'type' => 'string' ],
				'evidence' => [ 'type' => 'string' ],
			],
			[ 'code', 'severity', 'summary', 'evidence' ]
		);
	}

	private static function canonical_suggested_next_step_schema() {
		return self::closed_object_schema(
			[
				'code'           => [ 'type' => 'string' ],
				'summary'        => [ 'type' => 'string' ],
				'reason'         => [ 'type' => 'string' ],
				'requires_write' => [ 'type' => 'boolean' ],
				'risk_level'     => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high', 'critical' ] ],
			],
			[ 'code', 'summary', 'reason', 'requires_write', 'risk_level' ]
		);
	}

	private static function canonical_related_order_schema() {
		return self::closed_object_schema(
			[
				'id'           => [ 'type' => [ 'string', 'integer' ] ],
				'number'       => [ 'type' => 'string' ],
				'status'       => [ 'type' => 'string' ],
				'status_label' => [ 'type' => 'string' ],
				'total'        => [ 'type' => [ 'string', 'number' ] ],
				'currency'     => [ 'type' => 'string' ],
				'created_at'   => [ 'type' => 'string' ],
				'version'      => [ 'type' => 'string' ],
			],
			[ 'id', 'number', 'status', 'status_label', 'total', 'currency', 'created_at', 'version' ]
		);
	}

	private static function canonical_investigation_timeline_event_schema() {
		return self::closed_object_schema(
			[
				'type'        => [ 'type' => 'string' ],
				'occurred_at' => [ 'type' => 'string' ],
				'summary'     => [ 'type' => 'string' ],
				'visibility'  => [ 'type' => 'string', 'enum' => [ 'system', 'internal', 'customer' ] ],
				'extensions'  => [ 'type' => 'object' ],
			],
			[ 'type', 'occurred_at', 'summary', 'visibility', 'extensions' ]
		);
	}

	private static function canonical_support_context_schema() {
		return self::closed_object_schema(
			[
				'available'           => [ 'type' => 'boolean' ],
				'interaction_count'   => [ 'type' => 'integer', 'minimum' => 0 ],
				'last_interaction_at' => [ 'type' => 'string' ],
				'summary'             => [ 'type' => 'string' ],
			],
			[ 'available', 'interaction_count', 'last_interaction_at', 'summary' ]
		);
	}

	private static function canonical_customer_schema() {
		return self::closed_object_schema(
			[
				'id'             => [ 'type' => [ 'string', 'integer' ] ],
				'name'           => [ 'type' => 'string' ],
				'email'          => [ 'type' => 'string' ],
				'phone'          => [ 'type' => 'string' ],
				'status'         => [ 'type' => 'string' ],
				'orders_count'   => [ 'type' => 'integer', 'minimum' => 0 ],
				'lifetime_value' => [ 'type' => [ 'string', 'number' ] ],
				'currency'       => [ 'type' => 'string' ],
				'created_at'     => [ 'type' => 'string' ],
				'last_order_at'  => [ 'type' => 'string' ],
				'segments'       => self::array_schema( [ 'type' => 'string' ] ),
				'risk_flags'     => self::array_schema( [ 'type' => 'string' ] ),
				'related_orders' => self::array_schema( self::canonical_related_order_schema() ),
				'signals'        => self::array_schema( self::canonical_signal_schema() ),
				'support_context' => self::canonical_support_context_schema(),
				'suggested_next_steps' => self::array_schema( self::canonical_suggested_next_step_schema() ),
				'extensions'     => [ 'type' => 'object' ],
			],
			[ 'id', 'name', 'email', 'phone', 'status', 'orders_count', 'lifetime_value', 'currency', 'created_at', 'last_order_at', 'segments', 'risk_flags', 'related_orders', 'signals', 'support_context', 'suggested_next_steps', 'extensions' ]
		);
	}

	private static function canonical_inventory_item_schema() {
		return self::closed_object_schema(
			[
				'id'             => [ 'type' => [ 'string', 'integer' ] ],
				'product_id'     => [ 'type' => [ 'string', 'integer' ] ],
				'name'           => [ 'type' => 'string' ],
				'sku'            => [ 'type' => 'string' ],
				'stock_quantity' => [ 'type' => [ 'integer', 'null' ] ],
				'stock_status'   => [ 'type' => 'string' ],
				'version'        => [ 'type' => 'string' ],
				'extensions'     => [ 'type' => 'object' ],
			],
			[ 'id', 'product_id', 'name', 'sku', 'stock_quantity', 'stock_status', 'version' ]
		);
	}

	private static function canonical_reporting_window_schema() {
		return self::closed_object_schema(
			[
				'key'      => [ 'type' => 'string' ],
				'label'    => [ 'type' => 'string' ],
				'start'    => [ 'type' => 'string' ],
				'end'      => [ 'type' => 'string' ],
				'timezone' => [ 'type' => 'string' ],
			],
			[ 'key', 'label', 'start', 'end', 'timezone' ]
		);
	}

	private static function canonical_briefing_metrics_schema() {
		return self::closed_object_schema(
			[
				'order_count'         => [ 'type' => 'integer', 'minimum' => 0 ],
				'gross_sales'         => [ 'type' => [ 'string', 'number' ] ],
				'average_order_value' => [ 'type' => [ 'string', 'number' ] ],
				'currency'            => [ 'type' => 'string' ],
				'is_complete'         => [ 'type' => 'boolean' ],
			],
			[ 'order_count', 'gross_sales', 'average_order_value', 'currency', 'is_complete' ]
		);
	}

	private static function canonical_briefing_comparison_schema() {
		$metric = self::closed_object_schema(
			[
				'current'  => [ 'type' => [ 'string', 'number' ] ],
				'previous' => [ 'type' => [ 'string', 'number' ] ],
				'trend'    => [ 'type' => 'string', 'enum' => [ 'up', 'down', 'flat' ] ],
				'delta'    => [ 'type' => 'string' ],
			],
			[ 'current', 'previous', 'trend', 'delta' ]
		);

		return self::closed_object_schema(
			[
				'window'  => self::canonical_reporting_window_schema(),
				'metrics' => self::closed_object_schema(
					[
						'order_count'         => $metric,
						'gross_sales'         => $metric,
						'average_order_value' => $metric,
					],
					[ 'order_count', 'gross_sales', 'average_order_value' ]
				),
			],
			[ 'window', 'metrics' ]
		);
	}

	private static function canonical_briefing_counts_schema() {
		return self::closed_object_schema(
			[
				'attention'          => [ 'type' => 'integer', 'minimum' => 0 ],
				'fulfillment'        => [ 'type' => 'integer', 'minimum' => 0 ],
				'customer_followups' => [ 'type' => 'integer', 'minimum' => 0 ],
				'inventory'          => [ 'type' => 'integer', 'minimum' => 0 ],
			],
			[ 'attention', 'fulfillment', 'customer_followups', 'inventory' ]
		);
	}

	private static function canonical_attention_item_schema() {
		return self::closed_object_schema(
			[
				'order_id'       => [ 'type' => [ 'string', 'integer' ] ],
				'order_number'   => [ 'type' => 'string' ],
				'status'         => [ 'type' => 'string' ],
				'status_label'   => [ 'type' => 'string' ],
				'total'          => [ 'type' => [ 'string', 'number' ] ],
				'currency'       => [ 'type' => 'string' ],
				'created_at'     => [ 'type' => 'string' ],
				'age_hours'      => [ 'type' => 'integer', 'minimum' => 0 ],
				'reason'         => [ 'type' => 'string' ],
				'severity'       => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high', 'critical' ] ],
				'priority_score' => [ 'type' => 'number', 'minimum' => 0 ],
				'version'        => [ 'type' => 'string' ],
				'extensions'     => [ 'type' => 'object' ],
			],
			[ 'order_id', 'order_number', 'status', 'status_label', 'total', 'currency', 'created_at', 'age_hours', 'reason', 'severity', 'priority_score', 'version', 'extensions' ]
		);
	}

	private static function canonical_briefing_priority_schema() {
		return self::closed_object_schema(
			[
				'domain'   => [ 'type' => 'string' ],
				'title'    => [ 'type' => 'string' ],
				'reason'   => [ 'type' => 'string' ],
				'severity' => [ 'type' => 'string', 'enum' => [ 'low', 'medium', 'high', 'critical' ] ],
				'score'    => [ 'type' => 'number', 'minimum' => 0 ],
			],
			[ 'domain', 'title', 'reason', 'severity', 'score' ]
		);
	}

	private static function canonical_content_item_schema() {
		return self::closed_object_schema(
			[
				'content_id' => [ 'type' => 'integer' ],
				'post_type'  => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'title'      => [ 'type' => 'string' ],
				'content'    => [ 'type' => 'string' ],
				'version'    => [ 'type' => 'string' ],
				'modified_at' => [ 'type' => 'string' ],
				'url'        => [ 'type' => 'string' ],
				'extensions' => [ 'type' => 'object' ],
			],
			[ 'content_id', 'post_type', 'status', 'title', 'content', 'version', 'modified_at', 'url' ]
		);
	}

	private static function canonical_comment_schema() {
		return self::closed_object_schema(
			[
				'comment_id' => [ 'type' => 'integer' ],
				'content_id' => [ 'type' => 'integer' ],
				'parent_id'  => [ 'type' => 'integer' ],
				'author'     => [ 'type' => 'string' ],
				'content'    => [ 'type' => 'string' ],
				'status'     => [ 'type' => 'string' ],
				'version'    => [ 'type' => 'string' ],
				'extensions' => [ 'type' => 'object' ],
			],
			[ 'comment_id', 'content_id', 'parent_id', 'author', 'content', 'status', 'version' ]
		);
	}

	private static function canonical_media_item_schema() {
		return self::closed_object_schema(
			[
				'media_id'    => [ 'type' => 'integer' ],
				'title'       => [ 'type' => 'string' ],
				'caption'     => [ 'type' => 'string' ],
				'description' => [ 'type' => 'string' ],
				'alt_text'    => [ 'type' => 'string' ],
				'mime_type'   => [ 'type' => 'string' ],
				'url'         => [ 'type' => [ 'string', 'boolean' ] ],
				'version'     => [ 'type' => 'string' ],
				'extensions'  => [ 'type' => 'object' ],
			],
			[ 'media_id', 'title', 'mime_type', 'url' ]
		);
	}

	private static function canonical_site_info_schema() {
		return self::closed_object_schema(
			[
				'name'                => [ 'type' => 'string' ],
				'description'         => [ 'type' => 'string' ],
				'home_url'            => [ 'type' => 'string' ],
				'site_url'            => [ 'type' => 'string' ],
				'wordpress_version'   => [ 'type' => 'string' ],
				'php_version'         => [ 'type' => 'string' ],
				'locale'              => [ 'type' => 'string' ],
				'timezone'            => [ 'type' => 'string' ],
				'environment'         => [ 'type' => 'string' ],
				'is_multisite'        => [ 'type' => 'boolean' ],
				'active_theme'        => [ 'type' => 'string' ],
				'permalink_structure' => [ 'type' => 'string' ],
				'modules'             => self::array_schema( [ 'type' => 'string' ] ),
				'extensions'          => [ 'type' => 'object' ],
			],
			[ 'name', 'description', 'home_url', 'site_url', 'wordpress_version', 'php_version', 'locale', 'timezone', 'environment', 'is_multisite', 'active_theme', 'permalink_structure', 'modules' ]
		);
	}

	private static function canonical_site_health_schema() {
		return self::closed_object_schema(
			[
				'status'     => [ 'type' => 'string' ],
				'checks'     => self::array_schema(
					self::closed_object_schema(
						[
							'id'      => [ 'type' => 'string' ],
							'status'  => [ 'type' => 'string' ],
							'summary' => [ 'type' => 'string' ],
						],
						[ 'id', 'status', 'summary' ]
					)
				),
				'extensions' => [ 'type' => 'object' ],
			],
			[ 'status', 'checks' ]
		);
	}

	private static function canonical_site_update_status_schema() {
		return self::closed_object_schema(
			[
				'core'       => self::array_schema(
					self::closed_object_schema(
						[
							'version'  => [ 'type' => 'string' ],
							'locale'   => [ 'type' => 'string' ],
							'response' => [ 'type' => 'string' ],
						],
						[ 'version', 'locale', 'response' ]
					)
				),
				'plugins'    => self::array_schema(
					self::closed_object_schema(
						[
							'plugin'      => [ 'type' => 'string' ],
							'new_version' => [ 'type' => 'string' ],
						],
						[ 'plugin', 'new_version' ]
					)
				),
				'themes'     => self::array_schema(
					self::closed_object_schema(
						[
							'theme'       => [ 'type' => 'string' ],
							'new_version' => [ 'type' => 'string' ],
						],
						[ 'theme', 'new_version' ]
					)
				),
				'checked_at' => [ 'type' => 'string' ],
				'extensions' => [ 'type' => 'object' ],
			],
			[ 'core', 'plugins', 'themes', 'checked_at' ]
		);
	}

	private static function canonical_product_schema() {
		return self::closed_object_schema(
			[
				'id'             => [ 'type' => [ 'string', 'integer' ] ],
				'name'           => [ 'type' => 'string' ],
				'sku'            => [ 'type' => 'string' ],
				'status'         => [ 'type' => 'string' ],
				'type'           => [ 'type' => 'string' ],
				'price'          => [ 'type' => [ 'string', 'number' ] ],
				'currency'       => [ 'type' => 'string' ],
				'stock_quantity' => [ 'type' => [ 'integer', 'null' ] ],
				'stock_status'   => [ 'type' => 'string' ],
				'version'        => [ 'type' => 'string' ],
				'extensions'     => [ 'type' => 'object' ],
			],
			[ 'id', 'name', 'sku', 'status', 'type', 'price', 'currency', 'stock_quantity', 'stock_status', 'version' ]
		);
	}

	private static function canonical_timeline_event_schema() {
		return self::closed_object_schema(
			[
				'id'          => [ 'type' => 'string' ],
				'type'        => [ 'type' => 'string' ],
				'visibility'  => [ 'type' => 'string', 'enum' => [ 'system', 'internal', 'customer' ] ],
				'summary'     => [ 'type' => 'string' ],
				'occurred_at' => [ 'type' => 'string' ],
				'extensions'  => [ 'type' => 'object' ],
			],
			[ 'id', 'type', 'visibility', 'summary', 'occurred_at', 'extensions' ]
		);
	}

	private static function canonical_tracking_shipment_schema() {
		return self::closed_object_schema(
			[
				'carrier'         => [ 'type' => 'string' ],
				'tracking_number' => [ 'type' => 'string' ],
				'tracking_url'    => [ 'type' => 'string' ],
				'status'          => [ 'type' => 'string' ],
				'extensions'      => [ 'type' => 'object' ],
			],
			[ 'carrier', 'tracking_number', 'tracking_url', 'status', 'extensions' ]
		);
	}

	private static function canonical_page_info_schema() {
		return self::closed_object_schema(
			[
				'has_next_page' => [ 'type' => 'boolean' ],
				'next_cursor'   => [ 'type' => [ 'string', 'null' ] ],
			],
			[ 'has_next_page', 'next_cursor' ]
		);
	}

	private static function resolved_time_window_input_schema() {
		$comparison = self::closed_object_schema(
			[
				'kind'          => [ 'type' => 'string' ],
				'canonical_key' => [ 'type' => 'string' ],
				'start'         => [ 'type' => 'string' ],
				'end'           => [ 'type' => 'string' ],
				'start_date'    => [ 'type' => 'string' ],
				'end_date'      => [ 'type' => 'string' ],
				'timezone'      => [ 'type' => 'string' ],
			],
			[]
		);
		$debug = self::closed_object_schema(
			[
				'source_text'                          => [ 'type' => 'string' ],
				'input_key'                            => [ 'type' => 'string' ],
				'reconciled_from_key'                  => [ 'type' => 'string' ],
				'structured_parse_applied'             => [ 'type' => 'boolean' ],
				'structured_parse_key'                 => [ 'type' => 'string' ],
				'command_anchor_applied'               => [ 'type' => 'boolean' ],
				'command_anchor_key'                   => [ 'type' => 'string' ],
				'command_anchor_source_text'           => [ 'type' => 'string' ],
				'command_anchor_reconciled_from_key'   => [ 'type' => 'string' ],
				'canonical_key'                        => [ 'type' => 'string' ],
				'timezone'                             => [ 'type' => 'string' ],
				'start'                                => [ 'type' => 'string' ],
				'end'                                  => [ 'type' => 'string' ],
				'comparison_canonical_key'             => [ 'type' => 'string' ],
				'comparison_start'                     => [ 'type' => 'string' ],
				'comparison_end'                       => [ 'type' => 'string' ],
			],
			[]
		);
		$calendar_parse = self::closed_object_schema(
			[
				'source_language'  => [ 'type' => 'string' ],
				'calendar_intent'  => [ 'type' => 'string' ],
				'start_date'       => [ 'type' => 'string' ],
				'end_date'         => [ 'type' => 'string' ],
				'year'             => [ 'type' => 'integer' ],
				'month'            => [ 'type' => 'integer' ],
				'quarter'          => [ 'type' => 'integer' ],
				'relative_anchor'  => [ 'type' => 'string' ],
				'confidence'       => [ 'type' => 'number' ],
			],
			[]
		);
		$calendar_parse['type'] = [ 'object', 'null' ];

		return [
			'type'                 => 'object|null',
			'properties'           => [
				'kind'                => [ 'type' => 'string' ],
				'canonical_key'       => [ 'type' => 'string' ],
				'key'                 => [ 'type' => 'string' ],
				'label'               => [ 'type' => 'string' ],
				'start'               => [ 'type' => 'string' ],
				'end'                 => [ 'type' => 'string' ],
				'start_date'          => [ 'type' => 'string' ],
				'end_date'            => [ 'type' => 'string' ],
				'timezone'            => [ 'type' => 'string' ],
				'granularity'         => [ 'type' => 'string' ],
				'date_field'          => [ 'type' => 'string' ],
				'surface'             => [ 'type' => 'string' ],
				'source_text'         => [ 'type' => 'string' ],
				'confidence'          => [ 'type' => 'number' ],
				'needs_clarification' => [ 'type' => 'boolean' ],
				'calendar_parse'      => $calendar_parse,
				'comparison'          => $comparison,
				'debug'               => $debug,
			],
			'required'             => [],
			'additionalProperties' => false,
		];
	}

	private static function closed_object_schema( array $properties, array $required ) {
		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		];
	}

	private static function array_schema( array $items ) {
		return [
			'type'  => 'array',
			'items' => $items,
		];
	}

	private static function is_strict_schema_node( $schema, $property_name = '' ) {
		if ( ! is_array( $schema ) ) {
			return false;
		}

		$types = is_array( $schema['type'] ?? null )
			? array_values( $schema['type'] )
			: [ $schema['type'] ?? '' ];

		if ( empty( array_filter( $types, 'is_string' ) ) ) {
			return false;
		}

		if ( 'extensions' === $property_name ) {
			return in_array( 'object', $types, true );
		}

		if ( in_array( 'object', $types, true ) ) {
			$properties = $schema['properties'] ?? null;
			$required   = $schema['required'] ?? null;

			if ( ! is_array( $properties )
				|| ! is_array( $required )
				|| false !== ( $schema['additionalProperties'] ?? null )
				|| ! empty( array_diff( $required, array_keys( $properties ) ) ) ) {
				return false;
			}

			foreach ( $properties as $name => $definition ) {
				if ( ! self::is_strict_schema_node( $definition, sanitize_key( $name ) ) ) {
					return false;
				}
			}
		}

		if ( in_array( 'array', $types, true )
			&& ! self::is_strict_schema_node( $schema['items'] ?? null ) ) {
			return false;
		}

		return true;
	}

	private static function is_canonical_read_action( $action_name ) {
		return in_array(
			sanitize_text_field( $action_name ),
			[ 'products.search_reviews', 'products.get_review', 'customers.get_order_history', 'customers.get_period_summary', 'products.get_creation_context', 'orders.get_refunds', 'coupons.search', 'coupons.get_details', 'products.get_catalog_options', 'products.get_variable_details', 'orders.search', 'orders.get_details', 'orders.find_by_customer', 'customers.lookup', 'store.get_daily_briefing', 'operations.get_attention_queue', 'products.get_inventory_queue', 'content.search', 'content.get', 'comments.list', 'media.search', 'media.get', 'site.get_info', 'site.get_health', 'site.get_update_status', 'products.search', 'products.get_details', 'orders.get_timeline', 'fulfillment.get_tracking' ],
			true
		);
	}

	private static function is_canonical_write_action( $action_name ) {
		$actions = [ 'products.moderate_review', 'products.reply_review', 'orders.batch_update_status', 'orders.update_contacts', 'orders.update_customer_note', 'fulfillment.set_tracking', 'fulfillment.remove_tracking', 'products.update_attributes', 'products.create_variation', 'orders.update_status', 'products.update_stock', 'products.update_stock_status', 'products.update_details' ];
        if (class_exists('YoRo_Product_Lifecycle')) { $actions = array_merge($actions,YoRo_Product_Lifecycle::WriteActions); }
		if ( class_exists( 'YoRo_Product_Batch' ) ) {
			$actions[] = YoRo_Product_Batch::Action;
		}
		if ( class_exists( 'YoRo_Coupon_Management' ) ) {
			$actions = array_merge( $actions, YoRo_Coupon_Management::WriteActions );
		}
		return in_array(
			sanitize_text_field( $action_name ),
			$actions,
			true
		);
	}

	private static function canonical_write_data( $action_name, array $execution ) {
        if (class_exists('YoRo_Product_Reviews') && in_array($action_name,YoRo_Product_Reviews::WriteActions,true)) { return self::canonical_data_fields($action_name,$execution); }
        if (class_exists('YoRo_Product_Lifecycle') && in_array($action_name,YoRo_Product_Lifecycle::WriteActions,true)) { return self::canonical_data_fields($action_name,$execution); }
        if ((class_exists('YoRo_Order_Batch') && $action_name === YoRo_Order_Batch::Action) || (class_exists('YoRo_Order_Management') && in_array($action_name,YoRo_Order_Management::WriteActions,true))) { return self::canonical_data_fields($action_name,$execution); }
        if ((class_exists('YoRo_Product_Batch') && $action_name === YoRo_Product_Batch::Action) || (class_exists('YoRo_Coupon_Management') && in_array($action_name,YoRo_Coupon_Management::WriteActions,true))) { return self::canonical_data_fields($action_name,$execution); }
        if (in_array($action_name,['products.update_attributes', 'products.create_variation'],true)){
            return is_array($execution['data']??null) ? $execution['data'] : array_intersect_key($execution,array_flip(
                $action_name==='products.create_variation' ? ['product_id','changes','current_version','variation_id','variation_version'] : ['product_id','changes','current_version']));
        }
        if ('products.update_details' === $action_name) {
            return ['product_id'=>$execution['product_id'], 'changes'=>$execution['changes'], 'current_version'=>$execution['current_version']];
        }
		if ( 'orders.update_status' === $action_name ) {
			$current_versions = [];

			foreach ( (array) ( $execution['current_versions'] ?? [] ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$current_versions[] = [
					'order_id' => absint( $item['order_id'] ?? 0 ),
					'expected_version' => sanitize_text_field( $item['expected_version'] ?? '' ),
				];
			}

			return [
				'processed' => array_values( array_map( 'absint', (array) ( $execution['processed'] ?? [] ) ) ),
				'status' => sanitize_key( $execution['status'] ?? '' ),
				'status_label' => sanitize_text_field( $execution['status_label'] ?? '' ),
				'current_versions' => $current_versions,
			];
		}

		$data = [
			'product_id' => absint( $execution['product_id'] ?? 0 ),
			'stock_status' => sanitize_key( $execution['stock_status'] ?? '' ),
			'manage_stock' => (bool) ( $execution['manage_stock'] ?? false ),
			'current_version' => sanitize_text_field( $execution['current_version'] ?? '' ),
		];

		if ( 'products.update_stock' === $action_name ) {
			return [
				'product_id' => $data['product_id'],
				'stock_quantity' => (int) ( $execution['stock_quantity'] ?? 0 ),
				'stock_status' => $data['stock_status'],
				'manage_stock' => $data['manage_stock'],
				'current_version' => $data['current_version'],
			];
		}

		return $data;
	}

    /** Normalize the same closed data fields from local flat or canonical nested results. */
    private static function canonical_data_fields(string $action_name, array $execution): array
    {
        $source = is_array($execution['data'] ?? null) ? $execution['data'] : $execution;
        $data = [];
        foreach (self::execution_data_schema($action_name)['required'] ?? [] as $field) {
            if (array_key_exists($field,$source)) { $data[$field] = $source[$field]; }
        }
        return $data;
    }

	private static function canonical_read_data( $action_name, array $execution ) {
		$raw_data = is_array( $execution['data'] ?? null ) ? $execution['data'] : [];
        if((class_exists('YoRo_Product_Reviews') && in_array($action_name,YoRo_Product_Reviews::ReadActions,true)) || (class_exists('YoRo_Customer_History_Report') && in_array($action_name,YoRo_Customer_History_Report::ReadActions,true))){return self::canonical_data_fields($action_name,$execution);}
        if(class_exists('YoRo_Product_Lifecycle') && in_array($action_name,YoRo_Product_Lifecycle::ReadActions,true)){return self::canonical_data_fields($action_name,$execution);}
        if(class_exists('YoRo_Order_Management') && in_array($action_name,YoRo_Order_Management::ReadActions,true)){return self::canonical_data_fields($action_name,$execution);}
        if(class_exists('YoRo_Coupon_Management') && in_array($action_name,YoRo_Coupon_Management::ReadActions,true)){return self::canonical_data_fields($action_name,$execution);}
        if(class_exists('YoRo_Product_Catalog_Options') && $action_name===YoRo_Product_Catalog_Options::Action){return self::canonical_data_fields($action_name,$execution);}
        if($action_name==='products.get_variable_details'){return $raw_data ?: array_intersect_key($execution,array_flip(['products','variations','variable','count','page_info']));}

		if ( in_array( $action_name, [ 'content.search', 'comments.list', 'media.search' ], true ) ) {
			$items = self::is_list_array( $raw_data )
				? array_values( $raw_data )
				: array_values( (array) ( $raw_data['items'] ?? ( $execution['items'] ?? [] ) ) );

			return [
				'items' => $items,
				'count' => count( $items ),
			];
		}

		if ( in_array( $action_name, [ 'content.get', 'media.get' ], true ) ) {
			return [ 'item' => is_array( $raw_data['item'] ?? null ) ? $raw_data['item'] : ( is_array( $execution['item'] ?? null ) ? $execution['item'] : $raw_data ) ];
		}

		if ( 'site.get_info' === $action_name ) {
			return [ 'site' => is_array( $raw_data['site'] ?? null ) ? $raw_data['site'] : ( is_array( $execution['site'] ?? null ) ? $execution['site'] : $raw_data ) ];
		}

		if ( 'site.get_health' === $action_name ) {
			return [ 'health' => is_array( $raw_data['health'] ?? null ) ? $raw_data['health'] : ( is_array( $execution['health'] ?? null ) ? $execution['health'] : $raw_data ) ];
		}

		if ( 'site.get_update_status' === $action_name ) {
			return [ 'updates' => is_array( $raw_data['updates'] ?? null ) ? $raw_data['updates'] : ( is_array( $execution['updates'] ?? null ) ? $execution['updates'] : $raw_data ) ];
		}

		if ( in_array( $action_name, [ 'products.search', 'products.get_details' ], true ) ) {
			$products = array_values( (array) ( $execution['products'] ?? ( $raw_data['products'] ?? [] ) ) );

			if ( 'products.get_details' === $action_name ) {
				return [
					'products' => $products,
					'count'    => count( $products ),
				];
			}

			return [
				'products'  => $products,
				'page_info' => self::canonical_page_info( $execution['page_info'] ?? ( $raw_data['page_info'] ?? [] ) ),
				'count'     => count( $products ),
			];
		}

		if ( 'orders.get_timeline' === $action_name ) {
			return [
				'order_id' => $execution['order_id'] ?? ( $raw_data['order_id'] ?? 0 ),
				'events'   => array_values( (array) ( $execution['events'] ?? ( $raw_data['events'] ?? [] ) ) ),
				'count'    => count( (array) ( $execution['events'] ?? ( $raw_data['events'] ?? [] ) ) ),
			];
		}

		if ( 'fulfillment.get_tracking' === $action_name ) {
			return [
				'order_id'  => $execution['order_id'] ?? ( $raw_data['order_id'] ?? 0 ),
				'status'    => sanitize_key( $execution['status'] ?? ( $raw_data['status'] ?? 'unavailable' ) ),
				'shipments' => array_values( (array) ( $execution['shipments'] ?? ( $raw_data['shipments'] ?? [] ) ) ),
				'count'     => count( (array) ( $execution['shipments'] ?? ( $raw_data['shipments'] ?? [] ) ) ),
			];
		}

		if ( in_array( $action_name, [ 'orders.search', 'orders.get_details', 'orders.find_by_customer' ], true ) ) {
			$orders = self::canonical_orders( $execution['orders'] ?? [] );

			if ( 'orders.get_details' === $action_name ) {
				return [
					'orders' => $orders,
					'count'  => count( $orders ),
				];
			}

			return [
				'orders'    => $orders,
				'page_info' => self::canonical_page_info( $execution['page_info'] ?? [] ),
				'count'     => count( $orders ),
			];
		}

		if ( 'customers.lookup' === $action_name ) {
			$customers = [];
			$raw_customers = is_array( $execution['customers'] ?? null ) ? $execution['customers'] : [];

			if ( empty( $raw_customers ) && is_array( $execution['customer'] ?? null ) ) {
				$raw_customers = [ $execution['customer'] ];
			}

			foreach ( $raw_customers as $customer ) {
				if ( is_array( $customer ) ) {
					$customers[] = self::canonical_customer(
						$customer,
						$execution['related']['recent_orders'] ?? ( $execution['orders'] ?? [] ),
						$execution['related']['recent_notes'] ?? []
					);
				}
			}

			return [
				'customers' => $customers,
				'page_info' => self::canonical_page_info( $execution['page_info'] ?? [] ),
				'count'     => count( $customers ),
			];
		}

		if ( 'store.get_daily_briefing' === $action_name ) {
			return self::canonical_daily_briefing_data( $execution );
		}

		if ( 'operations.get_attention_queue' === $action_name ) {
			return self::canonical_attention_queue_data( $execution );
		}

		$items = [];

		foreach ( (array) ( $execution['products'] ?? ( $execution['items'] ?? [] ) ) as $item ) {
			if ( is_array( $item ) ) {
				$items[] = self::canonical_inventory_item( $item );
			}
		}

		return [
			'items'     => $items,
			'page_info' => self::canonical_page_info( $execution['page_info'] ?? [] ),
			'count'     => count( $items ),
		];
	}

	private static function is_list_array( array $value ) {
		return [] === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	private static function canonical_orders( $orders ) {
		$canonical = [];

		foreach ( (array) $orders as $order ) {
			if ( ! is_array( $order ) ) {
				continue;
			}

			$item = [
				'id'           => is_numeric( $order['id'] ?? null ) ? absint( $order['id'] ) : sanitize_text_field( $order['id'] ?? '' ),
				'number'       => sanitize_text_field( $order['number'] ?? ( $order['id'] ?? '' ) ),
				'status'       => sanitize_key( $order['status'] ?? '' ),
				'status_label' => sanitize_text_field( $order['status_label'] ?? ( $order['status'] ?? '' ) ),
				'total'        => is_scalar( $order['total'] ?? null ) ? (string) $order['total'] : '0',
				'currency'     => sanitize_text_field( $order['currency'] ?? '' ),
				'created_at'   => sanitize_text_field( $order['created_at'] ?? ( $order['date_created'] ?? '' ) ),
				'version'      => sanitize_text_field( $order['version'] ?? '' ),
				'extensions'   => [
					'woocommerce.order' => [
						'edit_url' => esc_url_raw( $order['edit_url'] ?? '' ),
					],
				],
			];
			$item['payment'] = self::canonical_order_payment( $order, $item );
			$item['fulfillment'] = self::canonical_order_fulfillment( $order );
			$item['signals'] = self::canonical_order_signals( $item, $order );
			$item['suggested_next_steps'] = self::canonical_suggested_next_steps( $item['signals'] );

			if ( array_key_exists( 'line_items', $order ) ) {
				$item['customer'] = [
					'id'    => ! empty( $order['customer_id'] ) ? absint( $order['customer_id'] ) : null,
					'name'  => sanitize_text_field( $order['customer_name'] ?? '' ),
					'email' => sanitize_text_field( $order['billing_email'] ?? '' ),
					'phone' => sanitize_text_field( $order['billing_phone'] ?? '' ),
				];
				$item['line_items'] = [];

				foreach ( (array) $order['line_items'] as $line_item ) {
					if ( is_array( $line_item ) ) {
						$item['line_items'][] = [
							'name'     => sanitize_text_field( $line_item['name'] ?? '' ),
							'quantity' => (int) ( $line_item['quantity'] ?? 0 ),
							'sku'      => sanitize_text_field( $line_item['sku'] ?? '' ),
							'total'    => is_scalar( $line_item['total'] ?? null ) ? (string) $line_item['total'] : '0',
						];
					}
				}

				$item['timeline'] = self::canonical_order_investigation_timeline( $order );
				$item['related_orders'] = self::canonical_related_orders( $order['related_orders'] ?? [], $item['id'] );
				$item['support_context'] = self::canonical_support_context( $order['notes'] ?? [] );
			}

			$canonical[] = $item;
		}

		return $canonical;
	}

	private static function canonical_order_payment( array $order, array $canonical_order ) {
		$status = sanitize_key( $order['payment_status'] ?? ( $canonical_order['status'] ?? 'unknown' ) );

		return [
			'status'   => $status ?: 'unknown',
			'paid'     => ! empty( $order['date_paid'] ) || in_array( $status, [ 'processing', 'completed' ], true ),
			'failed'   => 'failed' === $status,
			'refunded' => 'refunded' === $status || (float) ( $order['totals']['refunded_total'] ?? 0 ) > 0,
			'amount'   => $canonical_order['total'],
			'currency' => $canonical_order['currency'],
		];
	}

	private static function canonical_order_fulfillment( array $order ) {
		$status = sanitize_key( $order['status'] ?? 'unknown' );

		return [
			'status'            => $status ?: 'unknown',
			'fulfilled'         => 'completed' === $status,
			'requires_shipping' => ! empty( $order['requires_shipping'] ),
			'tracking_count'    => ! empty( $order['tracking_detected'] ) ? 1 : 0,
		];
	}

	private static function canonical_order_signals( array $order, array $raw_order ) {
		$signals = [];

		if ( ! empty( $order['payment']['failed'] ) ) {
			$signals[] = self::canonical_signal( 'failed_payment', 'high', 'Payment failed', 'The connector reports a failed order payment status.' );
		}

		if ( ! empty( $order['payment']['refunded'] ) ) {
			$signals[] = self::canonical_signal( 'refunded_order', 'medium', 'Order was refunded', 'The connector reports a refunded order payment status.' );
		}

		if ( ! empty( $order['fulfillment']['requires_shipping'] ) && empty( $order['fulfillment']['fulfilled'] ) && absint( $raw_order['age_hours'] ?? 0 ) >= 72 ) {
			$signals[] = self::canonical_signal( 'delayed_fulfillment', 'high', 'Fulfillment is delayed', 'A shippable order has remained unfulfilled for at least 72 hours.' );
		}

		$related = self::canonical_related_orders( $raw_order['related_orders'] ?? [], $order['id'] );
		if ( self::has_duplicate_order_candidate( $order, $related ) ) {
			$signals[] = self::canonical_signal( 'duplicate_order_candidate', 'high', 'Possible duplicate order', 'Another customer order has the same total and currency within 15 minutes.' );
		}

		if ( self::cancelled_order_count( $related ) >= 2 ) {
			$signals[] = self::canonical_signal( 'repeated_cancellation', 'medium', 'Repeated cancellations detected', 'At least two related customer orders are cancelled.' );
		}

		return $signals;
	}

	private static function canonical_signal( $code, $severity, $summary, $evidence ) {
		return compact( 'code', 'severity', 'summary', 'evidence' );
	}

	private static function canonical_suggested_next_steps( array $signals ) {
		$steps = [
			'failed_payment' => [ 'review_payment', 'Review the payment failure', 'Confirm the provider status and payment evidence before contacting the customer.' ],
			'failed_payment_history' => [ 'review_payment_history', 'Review prior payment failures', 'Compare failed orders before choosing a support response.' ],
			'refunded_order' => [ 'review_refund_context', 'Review refund context', 'Verify the refund and customer history before taking another action.' ],
			'delayed_fulfillment' => [ 'review_fulfillment', 'Review fulfillment status', 'Check fulfillment and tracking evidence before escalating.' ],
			'duplicate_order_candidate' => [ 'compare_duplicate_orders', 'Compare possible duplicate orders', 'Confirm products, totals, and timestamps before changing either order.' ],
			'repeated_cancellation' => [ 'review_cancellation_history', 'Review cancellation history', 'Inspect related cancelled orders before deciding the next support step.' ],
			'repeat_customer' => [ 'review_customer_history', 'Review customer history', 'Use the related order history to prepare a consistent response.' ],
		];
		$result = [];

		foreach ( $signals as $signal ) {
			$definition = $steps[ $signal['code'] ?? '' ] ?? null;
			if ( ! is_array( $definition ) ) {
				continue;
			}

			$result[ $definition[0] ] = [
				'code'           => $definition[0],
				'summary'        => $definition[1],
				'reason'         => $definition[2],
				'requires_write' => false,
				'risk_level'     => 'low',
			];
		}

		return array_values( $result );
	}

	private static function canonical_related_orders( $orders, $exclude_id = '' ) {
		$result = [];

		foreach ( (array) $orders as $order ) {
			if ( ! is_array( $order ) || (string) ( $order['id'] ?? '' ) === (string) $exclude_id ) {
				continue;
			}

			$result[] = [
				'id'           => is_numeric( $order['id'] ?? null ) ? absint( $order['id'] ) : sanitize_text_field( $order['id'] ?? '' ),
				'number'       => sanitize_text_field( $order['number'] ?? ( $order['id'] ?? '' ) ),
				'status'       => sanitize_key( $order['status'] ?? '' ),
				'status_label' => sanitize_text_field( $order['status_label'] ?? ( $order['status'] ?? '' ) ),
				'total'        => is_scalar( $order['total'] ?? null ) ? (string) $order['total'] : '0',
				'currency'     => sanitize_text_field( $order['currency'] ?? '' ),
				'created_at'   => sanitize_text_field( $order['created_at'] ?? ( $order['date_created'] ?? '' ) ),
				'version'      => sanitize_text_field( $order['version'] ?? '' ),
			];
		}

		return $result;
	}

	private static function canonical_order_investigation_timeline( array $order ) {
		$events = [];
		foreach ( [
			[ 'order_created', $order['date_created'] ?? '', 'Order created' ],
			[ 'payment_recorded', $order['date_paid'] ?? '', 'Payment recorded' ],
			[ 'order_completed', $order['date_completed'] ?? '', 'Order completed' ],
		] as $definition ) {
			if ( '' !== trim( (string) $definition[1] ) ) {
				$events[] = self::canonical_timeline_item( $definition[0], $definition[1], $definition[2], 'system' );
			}
		}

		foreach ( (array) ( $order['notes'] ?? [] ) as $note ) {
			if ( ! is_array( $note ) || '' === trim( (string) ( $note['date'] ?? '' ) ) ) {
				continue;
			}
			$customer_visible = ! empty( $note['customer'] );
			$events[] = self::canonical_timeline_item(
				'order_note',
				$note['date'],
				$customer_visible ? 'Customer-visible order note added' : 'Internal order note added',
				$customer_visible ? 'customer' : 'internal'
			);
		}

		usort( $events, static function ( $left, $right ) {
			return strcmp( $left['occurred_at'], $right['occurred_at'] );
		} );

		return $events;
	}

	private static function canonical_timeline_item( $type, $occurred_at, $summary, $visibility ) {
		return [
			'type'        => sanitize_key( $type ),
			'occurred_at' => sanitize_text_field( $occurred_at ),
			'summary'     => sanitize_text_field( $summary ),
			'visibility'  => sanitize_key( $visibility ),
			'extensions'  => [ 'woocommerce.timeline' => [] ],
		];
	}

	private static function canonical_support_context( $notes ) {
		$notes = array_values( array_filter( (array) $notes, 'is_array' ) );
		$last = reset( $notes );
		$customer_visible = count( array_filter( $notes, static function ( $note ) {
			return ! empty( $note['customer'] );
		} ) );

		return [
			'available'           => ! empty( $notes ),
			'interaction_count'   => count( $notes ),
			'last_interaction_at' => is_array( $last ) ? sanitize_text_field( $last['date'] ?? '' ) : '',
			'summary'             => empty( $notes )
				? 'No support history is available from this connector.'
				: sprintf( '%1$d order notes are available; %2$d are customer-visible.', count( $notes ), $customer_visible ),
		];
	}

	private static function cancelled_order_count( array $orders ) {
		return count( array_filter( $orders, static function ( $order ) {
			return in_array( sanitize_key( $order['status'] ?? '' ), [ 'cancelled', 'canceled' ], true );
		} ) );
	}

	private static function has_duplicate_order_candidate( array $order, array $related_orders ) {
		$current_timestamp = strtotime( (string) ( $order['created_at'] ?? '' ) );
		if ( false === $current_timestamp ) {
			return false;
		}

		foreach ( $related_orders as $candidate ) {
			$candidate_timestamp = strtotime( (string) ( $candidate['created_at'] ?? '' ) );
			if ( false !== $candidate_timestamp
				&& (string) $candidate['total'] === (string) $order['total']
				&& (string) $candidate['currency'] === (string) $order['currency']
				&& abs( $candidate_timestamp - $current_timestamp ) <= 900
			) {
				return true;
			}
		}

		return false;
	}

	private static function canonical_customer( array $customer, array $related_orders = [], array $support_items = [] ) {
		$raw_related_orders = $related_orders;
		$related_orders = self::canonical_related_orders( $related_orders );
		$signals = [];
		if ( absint( $customer['orders_count'] ?? count( $related_orders ) ) > 1 ) {
			$signals[] = self::canonical_signal( 'repeat_customer', 'low', 'Repeat customer', 'The customer has more than one order.' );
		}
		if ( self::cancelled_order_count( $related_orders ) >= 2 ) {
			$signals[] = self::canonical_signal( 'repeated_cancellation', 'medium', 'Repeated cancellations detected', 'At least two related customer orders are cancelled.' );
		}
		foreach ( $raw_related_orders as $related_order ) {
			if ( is_array( $related_order ) && 'failed' === sanitize_key( $related_order['status'] ?? '' ) ) {
				$signals[] = self::canonical_signal( 'failed_payment_history', 'medium', 'Failed payment in order history', 'A related order has a failed payment status.' );
				break;
			}
		}

		return [
			'id'             => is_numeric( $customer['id'] ?? null ) ? absint( $customer['id'] ) : sanitize_text_field( $customer['id'] ?? '' ),
			'name'           => sanitize_text_field( $customer['name'] ?? '' ),
			'email'          => sanitize_text_field( $customer['email'] ?? '' ),
			'phone'          => sanitize_text_field( $customer['phone'] ?? '' ),
			'status'         => sanitize_key( $customer['status'] ?? 'unknown' ),
			'orders_count'   => absint( $customer['orders_count'] ?? 0 ),
			'lifetime_value' => is_scalar( $customer['lifetime_value'] ?? null ) ? (string) $customer['lifetime_value'] : '0',
			'currency'       => sanitize_text_field( $customer['currency'] ?? '' ),
			'created_at'     => sanitize_text_field( $customer['created_at'] ?? ( $customer['first_order_at'] ?? '' ) ),
			'last_order_at'  => sanitize_text_field( $customer['last_order_at'] ?? '' ),
			'segments'       => array_values( array_map( 'sanitize_key', (array) ( $customer['segments'] ?? [] ) ) ),
			'risk_flags'     => array_values( array_map( 'sanitize_key', (array) ( $customer['risk_flags'] ?? [] ) ) ),
			'related_orders' => $related_orders,
			'signals'        => $signals,
			'support_context' => self::canonical_support_context( $support_items ),
			'suggested_next_steps' => self::canonical_suggested_next_steps( $signals ),
			'extensions'     => [
				'woocommerce.customer' => [
					'meta' => is_array( $customer['meta'] ?? null ) ? $customer['meta'] : [],
				],
			],
		];
	}

	private static function canonical_inventory_item( array $item ) {
		$stock_quantity = $item['stock_quantity'] ?? null;
		$product_id = is_numeric( $item['product_id'] ?? null ) ? absint( $item['product_id'] ) : ( is_numeric( $item['id'] ?? null ) ? absint( $item['id'] ) : 0 );
		$version = sanitize_text_field( $item['version'] ?? '' );

		if ( '' === $version && $product_id > 0 && class_exists( 'YoRo_Commerce_Discovery_Service' ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			$version = $product instanceof WC_Product ? YoRo_Commerce_Discovery_Service::product_version( $product ) : '';
		}

		return [
			'id'             => is_numeric( $item['id'] ?? null ) ? absint( $item['id'] ) : sanitize_text_field( $item['id'] ?? '' ),
			'product_id'     => $product_id > 0 ? $product_id : sanitize_text_field( $item['id'] ?? '' ),
			'name'           => sanitize_text_field( $item['name'] ?? ( $item['title'] ?? '' ) ),
			'sku'            => sanitize_text_field( $item['sku'] ?? '' ),
			'stock_quantity' => is_numeric( $stock_quantity ) ? (int) $stock_quantity : null,
			'stock_status'   => sanitize_key( $item['stock_status'] ?? 'unknown' ),
			'version'        => $version,
			'extensions'     => [
				'woocommerce.inventory' => [
					'edit_url' => esc_url_raw( $item['edit_url'] ?? '' ),
				],
			],
		];
	}

	private static function canonical_daily_briefing_data( array $execution ) {
		$business        = is_array( $execution['business_summary'] ?? null ) ? $execution['business_summary'] : [];
		$business_metrics = is_array( $execution['metrics'] ?? null )
			? $execution['metrics']
			: ( is_array( $business['business_metrics'] ?? null ) ? $business['business_metrics'] : [] );
		$comparison      = is_array( $execution['comparison'] ?? null )
			? $execution['comparison']
			: ( is_array( $business['comparison'] ?? null ) ? $business['comparison'] : [] );
		$comparison_metrics = is_array( $comparison['metrics'] ?? null ) ? $comparison['metrics'] : [];
		$attention       = is_array( $execution['attention_queue'] ?? null ) ? $execution['attention_queue'] : [];
		$inventory       = is_array( $execution['inventory_queue'] ?? null ) ? $execution['inventory_queue'] : [];
		$attention_items = self::canonical_attention_items(
			$execution['attention_items'] ?? ( $attention['items'] ?? ( $attention['orders'] ?? [] ) )
		);
		$inventory_items = [];

		foreach ( (array) ( $execution['inventory_items'] ?? ( $inventory['items'] ?? ( $inventory['products'] ?? [] ) ) ) as $item ) {
			if ( is_array( $item ) ) {
				$inventory_items[] = self::canonical_inventory_item( $item );
			}
		}

		$order_count         = absint( $business_metrics['order_count'] ?? ( $execution['briefing_context']['window_orders'] ?? 0 ) );
		$gross_sales         = is_scalar( $business_metrics['gross_sales'] ?? null ) ? $business_metrics['gross_sales'] : '0';
		$average_order_value = is_scalar( $business_metrics['average_order_value'] ?? null )
			? $business_metrics['average_order_value']
			: ( $order_count > 0 ? (float) $gross_sales / $order_count : 0 );
		$priorities          = [];

		foreach ( (array) ( $execution['priorities'] ?? ( $execution['priority_items'] ?? [] ) ) as $priority ) {
			if ( is_array( $priority ) ) {
				$priorities[] = self::canonical_briefing_priority( $priority );
			}
		}

		$counts = is_array( $execution['counts'] ?? null ) ? $execution['counts'] : [];

		return [
			'window'          => self::canonical_reporting_window( $execution['window'] ?? [], __( 'Current period', 'yoro-ai-core' ) ),
			'metrics'         => [
				'order_count'         => $order_count,
				'gross_sales'         => is_scalar( $gross_sales ) ? $gross_sales : '0',
				'average_order_value' => is_scalar( $average_order_value ) ? $average_order_value : '0',
				'currency'            => sanitize_text_field( $business_metrics['currency'] ?? ( function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ) ),
				'is_complete'         => array_key_exists( 'is_complete', $business_metrics ) ? (bool) $business_metrics['is_complete'] : true,
			],
			'comparison'      => [
				'window'  => self::canonical_reporting_window( $comparison['window'] ?? [], __( 'Previous period', 'yoro-ai-core' ), 'previous_equivalent_window' ),
				'metrics' => [
					'order_count'         => self::canonical_metric_comparison( $comparison_metrics['order_count'] ?? [], $order_count ),
					'gross_sales'         => self::canonical_metric_comparison( $comparison_metrics['gross_sales'] ?? [], $gross_sales ),
					'average_order_value' => self::canonical_metric_comparison( $comparison_metrics['average_order_value'] ?? [], $average_order_value ),
				],
			],
			'counts'          => [
				'attention'          => absint( $counts['attention'] ?? ( $attention['queue_item_count'] ?? ( $attention['count'] ?? count( $attention_items ) ) ) ),
				'fulfillment'        => absint( $counts['fulfillment'] ?? ( $execution['fulfillment_queue']['count'] ?? 0 ) ),
				'customer_followups' => absint( $counts['customer_followups'] ?? ( $execution['customer_service_queue']['count'] ?? 0 ) ),
				'inventory'          => absint( $counts['inventory'] ?? ( $inventory['count'] ?? count( $inventory_items ) ) ),
			],
			'attention_items' => $attention_items,
			'inventory_items' => $inventory_items,
			'priorities'      => array_values( array_filter( $priorities ) ),
		];
	}

	private static function canonical_attention_queue_data( array $execution ) {
		$items = self::canonical_attention_items( $execution['items'] ?? ( $execution['orders'] ?? [] ) );

		return [
			'items'         => $items,
			'page_info'     => self::canonical_page_info( $execution['page_info'] ?? [] ),
			'count'         => count( $items ),
			'total_matches' => absint( $execution['total_matches'] ?? ( $execution['queue_item_count'] ?? ( $execution['count'] ?? count( $items ) ) ) ),
		];
	}

	private static function canonical_attention_items( $items ) {
		$canonical = [];

		foreach ( (array) $items as $item ) {
			if ( is_array( $item ) ) {
				$canonical[] = self::canonical_attention_item( $item );
			}
		}

		usort(
			$canonical,
			static function( $left, $right ) {
				return (float) ( $right['priority_score'] ?? 0 ) <=> (float) ( $left['priority_score'] ?? 0 );
			}
		);

		return $canonical;
	}

	private static function canonical_attention_item( array $item ) {
		$reason   = sanitize_key( $item['reason'] ?? ( $item['queue_topic'] ?? 'open_order_review' ) );
		$severity = sanitize_key( $item['severity'] ?? ( $item['tone'] ?? '' ) );

		if ( ! in_array( $severity, [ 'low', 'medium', 'high', 'critical' ], true ) ) {
			$severity = in_array( $reason, [ 'pending_payment_older_than_24h', 'recent_failed_orders' ], true )
				? 'high'
				: ( 'open_order_review' === $reason ? 'low' : 'medium' );
		}

		return [
			'order_id'       => is_numeric( $item['order_id'] ?? ( $item['id'] ?? null ) )
				? absint( $item['order_id'] ?? $item['id'] )
				: sanitize_text_field( $item['order_id'] ?? ( $item['id'] ?? '' ) ),
			'order_number'   => sanitize_text_field( $item['order_number'] ?? ( $item['number'] ?? ( $item['id'] ?? '' ) ) ),
			'status'         => sanitize_key( $item['status'] ?? '' ),
			'status_label'   => sanitize_text_field( $item['status_label'] ?? ( $item['status'] ?? '' ) ),
			'total'          => is_scalar( $item['total'] ?? null ) ? $item['total'] : '0',
			'currency'       => sanitize_text_field( $item['currency'] ?? '' ),
			'created_at'     => sanitize_text_field( $item['created_at'] ?? ( $item['date_created'] ?? '' ) ),
			'age_hours'      => absint( $item['age_hours'] ?? 0 ),
			'reason'         => $reason,
			'severity'       => $severity,
			'priority_score' => (float) ( $item['priority_score'] ?? ( $item['case_priority_score'] ?? 0 ) ),
			'version'        => sanitize_text_field( $item['version'] ?? '' ),
			'extensions'     => is_array( $item['extensions'] ?? null )
				? $item['extensions']
				: [ 'woocommerce.order' => [ 'edit_url' => esc_url_raw( $item['edit_url'] ?? '' ) ] ],
		];
	}

	private static function canonical_briefing_priority( array $priority ) {
		$topic    = sanitize_key( $priority['topic'] ?? ( $priority['domain'] ?? 'orders' ) );
		$severity = sanitize_key( $priority['severity'] ?? ( $priority['tone'] ?? '' ) );

		if ( ! in_array( $severity, [ 'low', 'medium', 'high', 'critical' ], true ) ) {
			$severity = 'warning' === $severity ? 'high' : ( 'watch' === $severity ? 'medium' : 'low' );
		}

		$domain = sanitize_key( $priority['domain'] ?? '' );

		if ( '' === $domain ) {
			$domain = false !== strpos( $topic, 'inventory' )
				? 'inventory'
				: ( false !== strpos( $topic, 'business' ) || false !== strpos( $topic, 'sales' ) ? 'business' : 'orders' );
		}

		return [
			'domain'   => $domain,
			'title'    => sanitize_text_field( $priority['title'] ?? '' ),
			'reason'   => sanitize_text_field( $priority['reason'] ?? ( $priority['message'] ?? '' ) ),
			'severity' => $severity,
			'score'    => (float) ( $priority['score'] ?? 0 ),
		];
	}

	private static function canonical_reporting_window( $window, $fallback_label, $fallback_key = 'current_period' ) {
		$window = is_array( $window ) ? $window : [];

		return [
			'key'      => sanitize_text_field( $window['canonical_key'] ?? ( $window['key'] ?? $fallback_key ) ),
			'label'    => sanitize_text_field( $window['label'] ?? $fallback_label ),
			'start'    => sanitize_text_field( $window['start'] ?? '' ),
			'end'      => sanitize_text_field( $window['end'] ?? '' ),
			'timezone' => sanitize_text_field( $window['timezone'] ?? ( function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : 'UTC' ) ),
		];
	}

	private static function canonical_metric_comparison( $metric, $current ) {
		$metric   = is_array( $metric ) ? $metric : [];
		$current  = is_scalar( $metric['current'] ?? null ) ? $metric['current'] : ( is_scalar( $current ) ? $current : 0 );
		$previous = is_scalar( $metric['previous'] ?? null ) ? $metric['previous'] : 0;
		$trend    = sanitize_key( $metric['trend'] ?? '' );

		if ( ! in_array( $trend, [ 'up', 'down', 'flat' ], true ) ) {
			$difference = (float) $current - (float) $previous;
			$trend      = abs( $difference ) < 0.00001 ? 'flat' : ( $difference > 0 ? 'up' : 'down' );
		}

		$delta = sanitize_text_field( $metric['delta'] ?? '' );

		if ( '' === $delta ) {
			$previous_number = (float) $previous;
			$percentage      = abs( $previous_number ) < 0.00001
				? ( (float) $current > 0 ? null : 0.0 )
				: ( ( (float) $current - $previous_number ) / abs( $previous_number ) ) * 100;
			$delta           = null === $percentage ? 'new vs previous' : sprintf( '%+.1f%% vs previous', $percentage );
		}

		return [
			'current'  => $current,
			'previous' => $previous,
			'trend'    => $trend,
			'delta'    => $delta,
		];
	}

	private static function canonical_page_info( $page_info ) {
		$page_info = is_array( $page_info ) ? $page_info : [];
		$has_next_page = ! empty( $page_info['has_next_page'] ) || ! empty( $page_info['hasNextPage'] );
		$next_cursor = sanitize_text_field( $page_info['next_cursor'] ?? ( $page_info['endCursor'] ?? '' ) );

		return [
			'has_next_page' => $has_next_page,
			'next_cursor'   => $has_next_page && '' !== $next_cursor ? $next_cursor : null,
		];
	}

	private static function object_schema( $schema, $required ) {
		$properties = [];

		foreach ( (array) $schema as $field => $definition ) {
			$field = sanitize_key( $field );

			if ( '' === $field ) {
				continue;
			}

			if ( is_array( $definition ) ) {
				$type = $definition['type'] ?? 'string';
				$properties[ $field ] = array_merge( $definition, self::schema_definition( $type ) );
			} else {
				$properties[ $field ] = self::schema_definition( $definition );
			}
		}

		$required = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $required ) ) ) );

		if ( array_diff( $required, array_keys( $properties ) ) ) {
			return new WP_Error( 'yoro_connector_invalid_action_schema', 'Required action parameters must exist in the input schema.' );
		}

		return [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		];
	}

	private static function schema_definition( $legacy_type ) {
		if ( is_array( $legacy_type ) ) { $legacy_type = implode( '|', array_filter( $legacy_type, 'is_string' ) ); }
		$legacy_type = strtolower( trim( (string) $legacy_type ) );
		$types = array_values( array_filter( array_map( 'trim', explode( '|', $legacy_type ) ) ) );
		$canonical_types = [];
		$array_item_type = '';

		foreach ( $types as $type ) {
			if ( preg_match( '/^array(?:<|:)([a-z]+)>?$/', $type, $matches ) ) {
				$canonical_types[] = 'array';
				$array_item_type = self::canonical_schema_type( $matches[1] );
				continue;
			}

			$canonical_types[] = self::canonical_schema_type( $type );
		}

		$canonical_types = array_values( array_unique( array_filter( $canonical_types ) ) );

		if ( empty( $canonical_types ) ) {
			$canonical_types = [ 'string' ];
		}

		$definition = [
			'type' => 1 === count( $canonical_types ) ? $canonical_types[0] : $canonical_types,
		];

		if ( 'array' === ( $definition['type'] ?? '' ) && '' !== $array_item_type ) {
			$definition['items'] = [ 'type' => $array_item_type ];
		}

		return $definition;
	}

	private static function canonical_schema_type( $type ) {
		$type = sanitize_key( (string) $type );

		if ( in_array( $type, [ 'float', 'decimal', 'double' ], true ) ) {
			return 'number';
		}

		if ( 'bool' === $type ) {
			return 'boolean';
		}

		if ( 'int' === $type ) {
			return 'integer';
		}

		return in_array( $type, [ 'string', 'integer', 'number', 'boolean', 'array', 'object', 'null' ], true )
			? $type
			: 'string';
	}

	private static function connection_id( array $runtime ) {
		$connection_id = sanitize_text_field( $runtime['connector_connection_id'] ?? '' );

		if ( ! in_array( self::connector_key( $runtime ), [ 'wordpress', 'woocommerce' ], true )
			|| 'remote_agent' !== sanitize_key( $runtime['execution_mode'] ?? '' )
			|| 1 !== absint( $runtime['protocol_version'] ?? 0 )
			|| '' === $connection_id ) {
			return new WP_Error( 'yoro_connector_runtime_unavailable', 'The YoRo connector runtime identity is unavailable.' );
		}

		return $connection_id;
	}

	private static function connector_key( array $runtime ) {
		$connector_key = sanitize_key( $runtime['connector_key'] ?? '' );

		return in_array( $connector_key, [ 'wordpress', 'woocommerce' ], true ) ? $connector_key : self::CONNECTOR_KEY;
	}

	private static function safe_list( $items ) {
		return json_decode( wp_json_encode( is_array( $items ) ? array_values( $items ) : [] ), true );
	}
}
