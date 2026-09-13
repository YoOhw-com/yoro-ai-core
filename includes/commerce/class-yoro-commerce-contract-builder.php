<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Commerce_Contract_Builder {

	const CONNECTOR_KEY = 'wordpress';
	const SCHEMA_VERSION = 1;
	const EXECUTION_PAYLOAD_VERSION = 1;
	const CAPABILITY_AUTHORITY_CATALOG_VERSION = 'yoro.capability_authority@v1';
	const CAPABILITY_AUTHORITY_CATALOG_DIGEST = 'sha256:6b9344e7180cca400288c5ed109357cc7174ecbbe0f5409081d51c8d1983a946';
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

	public static function payment_link_schema() {
		$properties = [
			'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'order_number' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 128 ],
			'status' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
			'needs_payment' => [ 'type' => 'boolean' ],
			'payment_link_available' => [ 'type' => 'boolean' ],
			'payment_url' => [ 'type' => [ 'string', 'null' ], 'minLength' => 1, 'maxLength' => 4096 ],
			'evidence' => self::closed_object_schema( [
				'source' => [ 'type' => 'string', 'enum' => [ 'woocommerce_order' ] ],
				'limitation' => [ 'type' => 'string', 'enum' => [ 'Current WooCommerce eligibility and opaque checkout URL only; no payment initiation or independent gateway verification.' ] ],
			], [ 'source', 'limitation' ] ),
		];
		return self::closed_object_schema( $properties, array_keys( $properties ) );
	}

	public static function normalize_payment_link( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['order_id'], $value['order_number'], $value['status'], $value['needs_payment'], $value['payment_link_available'] )
			|| ! is_int( $value['order_id'] ) || $value['order_id'] < 1
			|| ! is_string( $value['order_number'] ) || '' === $value['order_number'] || strlen( $value['order_number'] ) > 128
			|| ! is_string( $value['status'] ) || '' === $value['status'] || strlen( $value['status'] ) > 64
			|| ! is_bool( $value['needs_payment'] ) || ! is_bool( $value['payment_link_available'] )
			|| ! array_key_exists( 'payment_url', $value ) ) {
			return null;
		}
		$number = sanitize_text_field( $value['order_number'] );
		$status = sanitize_key( $value['status'] );
		if ( '' === $number || '' === $status ) {
			return null;
		}
		$url = $value['payment_url'];
		$available = $value['payment_link_available'];
		if ( $available ) {
			if ( ! $value['needs_payment'] || ! is_string( $url ) || strlen( $url ) > 4096
				|| preg_match( '~[\x00-\x20\x7f<>"]~', $url )
				|| ! preg_match( '~^https?://~i', $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return null;
			}
		} elseif ( null !== $url ) {
			return null;
		}
		return [
			'order_id' => $value['order_id'],
			'order_number' => $number,
			'status' => $status,
			'needs_payment' => $value['needs_payment'],
			'payment_link_available' => $available,
			'payment_url' => $url,
			'evidence' => [
				'source' => 'woocommerce_order',
				'limitation' => 'Current WooCommerce eligibility and opaque checkout URL only; no payment initiation or independent gateway verification.',
			],
		];
	}

	/** Validate the closed B5 projection before it can enter the public envelope. */
	private static function email_history_schema_value( $value, $schema, $depth = 0, $coupon_search = false ) {
		if ( $depth > 12 ) { return false; }
		$types = (array) ( $schema['type'] ?? [] );
		if ( null === $value ) { return in_array( 'null', $types, true ); }
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) { return false; }
		if ( in_array( 'object', $types, true ) ) {
			if ( ! is_array( $value ) || array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) || array_diff( $schema['required'], array_keys( $value ) ) ) { return false; }
			foreach ( $value as $key => $child ) {
				if ( ! self::email_history_schema_value( $child, $schema['properties'][ $key ], $depth + 1, $coupon_search ) ) { return false; }
			}
			return true;
		}
		if ( in_array( 'array', $types, true ) ) {
			if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > ( $schema['maxItems'] ?? 501 ) || count( $value ) < ( $schema['minItems'] ?? 0 ) ) { return false; }
			foreach ( $value as $child ) { if ( ! self::email_history_schema_value( $child, $schema['items'], $depth + 1, $coupon_search ) ) { return false; } }
			return empty( $schema['uniqueItems'] ) || count( array_unique( $value, SORT_REGULAR ) ) === count( $value );
		}
		if ( in_array( 'string', $types, true ) ) {
			if ( $coupon_search ) {
				return is_string( $value ) && preg_match( '//u', $value )
					&& mb_strlen( $value, 'UTF-8' ) <= ( $schema['maxLength'] ?? 512 )
					&& mb_strlen( $value, 'UTF-8' ) >= ( $schema['minLength'] ?? 0 )
					&& ( ! isset( $schema['pattern'] ) || preg_match( '~' . $schema['pattern'] . '~D', $value ) );
			}
			return is_string( $value ) && strlen( $value ) <= ( $schema['maxLength'] ?? 512 ) && strlen( $value ) >= ( $schema['minLength'] ?? 0 )
				&& ( ! isset( $schema['pattern'] ) || preg_match( '~' . $schema['pattern'] . '~D', $value ) );
		}
		if ( in_array( 'integer', $types, true ) ) { return is_int( $value ) && $value >= ( $schema['minimum'] ?? 0 ) && $value <= ( $schema['maximum'] ?? PHP_INT_MAX ); }
		if ( $coupon_search && in_array( 'number', $types, true ) ) {
			return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value )
				&& $value >= ( $schema['minimum'] ?? -PHP_FLOAT_MAX ) && $value <= ( $schema['maximum'] ?? PHP_FLOAT_MAX );
		}
		return in_array( 'boolean', $types, true ) && is_bool( $value );
	}

	public static function execution_output_schema( $action_name = '' ) {
		if ( 'coupons.search' === $action_name ) {
			$item = [
				'coupon_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'coupon_code' => [ 'type' => 'string', 'maxLength' => 100 ],
				'discount_type' => [ 'type' => 'string', 'maxLength' => 100 ],
				'discount_type_label' => [ 'type' => 'string', 'maxLength' => 100 ],
				'discount_amount' => [ 'type' => 'number' ],
				'discount_summary' => [ 'type' => 'string', 'maxLength' => 100 ],
				'description' => [ 'type' => 'string', 'maxLength' => 500 ],
				'usage_count' => [ 'type' => 'integer', 'minimum' => 0 ],
				'usage_limit' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ],
				'usage_limit_per_user' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 1 ],
				'individual_use' => [ 'type' => 'boolean' ],
				'free_shipping' => [ 'type' => 'boolean' ],
				'minimum_amount' => [ 'type' => 'string', 'maxLength' => 100 ],
				'maximum_amount' => [ 'type' => 'string', 'maxLength' => 100 ],
				'date_expires' => [ 'type' => 'string', 'maxLength' => 100 ],
				'coupons_enabled' => [ 'type' => 'boolean' ],
			];
			$data = [
				'coupons' => [ 'type' => 'array', 'maxItems' => 50, 'items' => self::closed_object_schema( $item, array_keys( $item ) ) ],
				'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
				'limit' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ],
				'has_more' => [ 'type' => 'boolean' ],
				'next_page' => [ 'type' => [ 'integer', 'null' ], 'minimum' => 2, 'maximum' => 100 ],
				'skipped_count' => [ 'type' => 'integer', 'minimum' => 0, 'maximum' => 50 ],
				'coupons_enabled' => [ 'type' => 'boolean' ],
				'consistency' => [ 'type' => 'string', 'enum' => [ 'live_page' ] ],
			];
			$properties = [
				'success' => [ 'type' => 'boolean' ],
				'action' => [ 'type' => 'string', 'enum' => [ 'coupons.search' ] ],
				'message' => [ 'type' => 'string', 'maxLength' => 256 ],
				'summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'data' => self::closed_object_schema( $data, array_keys( $data ) ),
				'read_only' => [ 'type' => 'boolean', 'enum' => [ true ] ],
				'undoable' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'result_type' => [ 'type' => 'string', 'enum' => [ 'coupon_search' ] ],
				'display_type' => [ 'type' => 'string', 'enum' => [ 'coupon_search' ] ],
				'assistant_summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'extensions' => self::closed_object_schema( [], [] ),
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
		if ( 'orders.get_download_permissions' === $action_name ) {
			return [
				'type' => 'object',
				'properties' => [
					'success' => [
						'type' => 'boolean',
					],
					'action' => [
						'type' => 'string',
						'enum' => [
							'orders.get_download_permissions',
						],
					],
					'message' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'data' => [
						'type' => 'object',
						'properties' => [
							'download_permissions' => [
								'type' => [
									'object',
									'null',
								],
								'properties' => [
									'order_id' => [
										'type' => 'integer',
										'minimum' => 1,
									],
									'permissions' => [
										'type' => 'array',
										'maxItems' => 50,
										'items' => [
											'type' => 'object',
											'properties' => [
												'product_id' => [
													'type' => 'integer',
													'minimum' => 1,
												],
												'download_id' => [
													'type' => 'string',
													'minLength' => 1,
													'maxLength' => 64,
													'pattern' => '^[A-Za-z0-9_-]{1,64}$',
												],
												'downloads_remaining' => [
													'type' => [
														'integer',
														'null',
													],
													'minimum' => 0,
												],
												'unlimited_downloads' => [
													'type' => 'boolean',
												],
												'download_count' => [
													'type' => 'integer',
													'minimum' => 0,
												],
												'access_granted' => [
													'type' => [
														'string',
														'null',
													],
													'minLength' => 20,
													'maxLength' => 20,
													'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
												],
												'access_expires' => [
													'type' => [
														'string',
														'null',
													],
													'minLength' => 20,
													'maxLength' => 20,
													'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$',
												],
											],
											'required' => [
												'product_id',
												'download_id',
												'downloads_remaining',
												'unlimited_downloads',
												'download_count',
												'access_granted',
												'access_expires',
											],
											'additionalProperties' => false,
										],
									],
									'pagination' => [
										'type' => 'object',
										'properties' => [
											'limit' => [
												'type' => 'integer',
												'minimum' => 1,
												'maximum' => 50,
											],
											'page' => [
												'type' => 'integer',
												'minimum' => 1,
												'maximum' => 100,
											],
											'has_more' => [
												'type' => 'boolean',
											],
											'next_page' => [
												'type' => [
													'integer',
													'null',
												],
												'minimum' => 2,
												'maximum' => 100,
											],
										],
										'required' => [
											'limit',
											'page',
											'has_more',
											'next_page',
										],
										'additionalProperties' => false,
									],
									'coverage' => [
										'type' => 'object',
										'properties' => [
											'source' => [
												'type' => 'string',
												'enum' => [
													'woocommerce_customer_download',
												],
											],
											'limitations' => [
												'type' => 'array',
												'items' => [
													'type' => 'string',
													'enum' => [
														'download_permission_not_license',
														'download_url_not_exposed',
														'file_delivery_not_verified',
														'permission_regeneration_not_performed',
														'live_view_not_snapshot',
														'empty_means_no_permission_rows_observed',
														'current_product_file_display_metadata_not_observed',
													],
												],
												'minItems' => 7,
												'maxItems' => 7,
												'uniqueItems' => true,
											],
										],
										'required' => [
											'source',
											'limitations',
										],
										'additionalProperties' => false,
									],
									'regeneration_guard' => [
										'type' => 'object',
										'properties' => [
											'order_id' => [
												'type' => 'integer',
												'minimum' => 1,
											],
											'eligible' => [
												'type' => 'boolean',
											],
											'state_token' => [
												'type' => [
													'string',
													'null',
												],
												'pattern' => '^[a-f0-9]{64}$',
												'minLength' => 64,
												'maxLength' => 64,
											],
											'current_version' => [
												'type' => [
													'string',
													'null',
												],
												'pattern' => '^[a-f0-9]{64}$',
												'minLength' => 64,
												'maxLength' => 64,
											],
											'blocked_reason' => [
												'type' => 'string',
												'enum' => [
													'eligible',
													'ineligible_order',
													'no_source_grants',
													'unsafe_state',
													'permission_cap_exceeded',
													'source_cap_exceeded',
												],
											],
											'permission_count' => [
												'type' => [
													'integer',
													'null',
												],
												'minimum' => 0,
												'maximum' => 500,
											],
											'source_file_grant_count' => [
												'type' => [
													'integer',
													'null',
												],
												'minimum' => 0,
												'maximum' => 200,
											],
										],
										'required' => [
											'order_id',
											'eligible',
											'state_token',
											'current_version',
											'blocked_reason',
											'permission_count',
											'source_file_grant_count',
										],
										'additionalProperties' => false,
									],
								],
								'required' => [
									'order_id',
									'permissions',
									'pagination',
									'coverage',
								],
								'additionalProperties' => false,
							],
						],
						'required' => [
							'download_permissions',
						],
						'additionalProperties' => false,
					],
					'read_only' => [
						'type' => 'boolean',
						'enum' => [
							true,
						],
					],
					'undoable' => [
						'type' => 'boolean',
						'enum' => [
							false,
						],
					],
					'result_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'display_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'assistant_summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'extensions' => [
						'type' => 'object',
						'properties' => [],
						'required' => [],
						'additionalProperties' => false,
					],
				],
				'required' => [
					'success',
					'action',
					'message',
					'summary',
					'data',
					'read_only',
					'undoable',
					'result_type',
					'display_type',
					'assistant_summary',
					'extensions',
				],
				'additionalProperties' => false,
			];
		}
		if ( 'orders.regenerate_download_permissions' === $action_name ) {
			return [
				'type' => 'object',
				'properties' => [
					'success' => [
						'type' => 'boolean',
					],
					'action' => [
						'type' => 'string',
						'enum' => [
							'orders.regenerate_download_permissions',
						],
					],
					'message' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'data' => [
						'type' => 'object',
						'properties' => [
							'download_permissions_regeneration' => [
								'type' => [
									'object',
									'null',
								],
								'properties' => [
									'order_id' => [
										'type' => 'integer',
										'minimum' => 1,
									],
									'status' => [
										'type' => 'string',
										'enum' => [
											'regenerated',
											'reconciliation_required',
										],
									],
									'before_permission_count' => [
										'type' => [
											'integer',
											'null',
										],
										'minimum' => 0,
										'maximum' => 500,
									],
									'after_permission_count' => [
										'type' => [
											'integer',
											'null',
										],
										'minimum' => 0,
										'maximum' => 500,
									],
									'source_file_grant_count' => [
										'type' => [
											'integer',
											'null',
										],
										'minimum' => 0,
										'maximum' => 200,
									],
									'current_version' => [
										'type' => [
											'string',
											'null',
										],
										'pattern' => '^[a-f0-9]{64}$',
										'minLength' => 64,
										'maxLength' => 64,
									],
									'next_action' => [
										'type' => [
											'string',
											'null',
										],
										'enum' => [
											'orders.get_download_permissions',
											null,
										],
									],
									'limitations' => [
										'type' => 'array',
										'items' => [
											'type' => 'string',
											'enum' => [
												'download_permission_not_license',
												'download_url_not_exposed',
												'file_delivery_not_verified',
												'regeneration_uses_current_product_download_configuration',
												'regeneration_resets_permission_consumption_state',
												'partial_failure_requires_fresh_reconciliation',
											],
										],
										'minItems' => 6,
										'maxItems' => 6,
										'uniqueItems' => true,
									],
								],
								'required' => [
									'order_id',
									'status',
									'before_permission_count',
									'after_permission_count',
									'source_file_grant_count',
									'current_version',
									'next_action',
									'limitations',
								],
								'additionalProperties' => false,
							],
						],
						'required' => [
							'download_permissions_regeneration',
						],
						'additionalProperties' => false,
					],
					'read_only' => [
						'type' => 'boolean',
						'enum' => [
							false,
						],
					],
					'undoable' => [
						'type' => 'boolean',
						'enum' => [
							false,
						],
					],
					'result_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'display_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'assistant_summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'extensions' => [
						'type' => 'object',
						'properties' => [],
						'required' => [],
						'additionalProperties' => false,
					],
				],
				'required' => [
					'success',
					'action',
					'message',
					'summary',
					'data',
					'read_only',
					'undoable',
					'result_type',
					'display_type',
					'assistant_summary',
					'extensions',
				],
				'additionalProperties' => false,
			];
		}
		if ( 'orders.get_email_history' === $action_name ) {
			return [
				'type' => 'object',
				'properties' => [
					'success' => [
						'type' => 'boolean',
					],
					'action' => [
						'type' => 'string',
						'enum' => [
							'orders.get_email_history',
						],
					],
					'message' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'data' => [
						'type' => 'object',
						'properties' => [
							'email_history' => [
								'type' => [
									'object',
									'null',
								],
								'properties' => [
									'order_id' => [
										'type' => 'integer',
										'minimum' => 1,
									],
									'events' => [
										'type' => 'array',
										'maxItems' => 50,
										'items' => [
											'type' => 'object',
											'properties' => [
												'log_id' => [
													'type' => 'integer',
													'minimum' => 1,
												],
												'occurred_at' => [
													'type' => 'string',
													'minLength' => 1,
													'maxLength' => 32,
													'pattern' => '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$',
												],
												'action' => [
													'type' => 'string',
													'enum' => [
														'orders.send_customer_email',
														'orders.email_customer_template',
														'orders.send_transactional_email',
													],
												],
												'action_status' => [
													'type' => 'string',
													'enum' => [
														'success',
														'failure',
													],
												],
												'mailer_outcome' => [
													'type' => 'string',
													'enum' => [
														'mailer_accepted',
														'unknown',
														'not_recorded',
													],
												],
												'evidence_quality' => [
													'type' => 'string',
													'enum' => [
														'explicit_mailer_outcome',
														'legacy_action_result',
													],
												],
												'delivery_status' => [
													'type' => 'string',
													'enum' => [
														'not_observed',
													],
												],
											],
											'required' => [
												'log_id',
												'occurred_at',
												'action',
												'action_status',
												'mailer_outcome',
												'evidence_quality',
												'delivery_status',
											],
											'additionalProperties' => false,
										],
									],
									'pagination' => [
										'type' => 'object',
										'properties' => [
											'limit' => [
												'type' => 'integer',
												'minimum' => 1,
												'maximum' => 50,
											],
											'has_more' => [
												'type' => [
													'boolean',
													'null',
												],
											],
											'scan_complete' => [
												'type' => 'boolean',
											],
											'next_cursor' => [
												'type' => [
													'string',
													'null',
												],
												'minLength' => 66,
												'maxLength' => 512,
												'pattern' => '^[A-Za-z0-9_-]+\\.[a-f0-9]{64}$',
											],
											'scanned_rows' => [
												'type' => 'integer',
												'minimum' => 0,
												'maximum' => 501,
											],
										],
										'required' => [
											'limit',
											'has_more',
											'scan_complete',
											'next_cursor',
											'scanned_rows',
										],
										'additionalProperties' => false,
									],
									'coverage' => [
										'type' => 'object',
										'properties' => [
											'source' => [
												'type' => 'string',
												'enum' => [
													'local_yoro_action_log',
												],
											],
											'limitations' => [
												'type' => 'array',
												'minItems' => 4,
												'maxItems' => 4,
												'items' => [
													'type' => 'string',
													'enum' => [
														'local_terminal_actions_only',
														'legacy_details_may_be_missing',
														'remote_execution_receipts_excluded',
														'delivery_not_observed',
													],
												],
											],
											'warnings' => [
												'type' => 'object',
												'properties' => [
													'unsafe_rows_skipped' => [
														'type' => 'integer',
														'minimum' => 0,
														'maximum' => 501,
													],
												],
												'required' => [
													'unsafe_rows_skipped',
												],
												'additionalProperties' => false,
											],
										],
										'required' => [
											'source',
											'limitations',
											'warnings',
										],
										'additionalProperties' => false,
									],
								],
								'required' => [
									'order_id',
									'events',
									'pagination',
									'coverage',
								],
								'additionalProperties' => false,
							],
						],
						'required' => [
							'email_history',
						],
						'additionalProperties' => false,
					],
					'read_only' => [
						'type' => 'boolean',
						'enum' => [
							true,
						],
					],
					'undoable' => [
						'type' => 'boolean',
						'enum' => [
							false,
						],
					],
					'result_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'display_type' => [
						'type' => 'string',
						'enum' => [
							'generic',
						],
					],
					'assistant_summary' => [
						'type' => 'string',
						'maxLength' => 256,
					],
					'extensions' => [
						'type' => 'object',
						'properties' => [],
						'required' => [],
						'additionalProperties' => false,
					],
				],
				'required' => [
					'success',
					'action',
					'message',
					'summary',
					'data',
					'read_only',
					'undoable',
					'result_type',
					'display_type',
					'assistant_summary',
					'extensions',
				],
				'additionalProperties' => false,
			];
		}

		if ( 'orders.send_transactional_email' === $action_name ) {
			$properties = [
				'success' => [ 'type' => 'boolean' ], 'action' => [ 'type' => 'string', 'enum' => [ 'orders.send_transactional_email' ] ],
				'message' => [ 'type' => 'string', 'maxLength' => 256 ], 'summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'data' => self::closed_object_schema( [
					'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'email_type' => [ 'type' => 'string', 'enum' => [ 'customer_processing_order', 'customer_completed_order' ] ],
					'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'minLength' => 64, 'maxLength' => 64 ],
					'outcome' => [ 'type' => 'string', 'enum' => [ 'mailer_accepted' ] ],
					'confirmation_accepted' => [ 'type' => 'boolean', 'enum' => [ true ] ],
					'recipient_masked' => [ 'type' => 'string', 'maxLength' => 254 ],
				], [ 'order_id', 'email_type', 'current_version', 'outcome', 'confirmation_accepted', 'recipient_masked' ] ),
				'read_only' => [ 'type' => 'boolean', 'enum' => [ false ] ], 'undoable' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'result_type' => [ 'type' => 'string' ], 'display_type' => [ 'type' => 'string' ],
				'assistant_summary' => [ 'type' => 'string', 'maxLength' => 256 ], 'extensions' => self::closed_object_schema( [], [] ),
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
		if ( 'orders.update_addresses' === $action_name ) {
			$properties = [
				'success' => [ 'type' => 'boolean' ], 'action' => [ 'type' => 'string', 'enum' => [ 'orders.update_addresses' ] ],
				'message' => [ 'type' => 'string', 'maxLength' => 256 ], 'summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'data' => self::closed_object_schema( [
					'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
					'changed_fields' => [ 'type' => 'array', 'items' => [ 'type' => 'string', 'enum' => [ 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ] ], 'minItems' => 1, 'maxItems' => 9, 'uniqueItems' => true ],
					'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				], [ 'order_id', 'changed_fields', 'current_version' ] ),
				'read_only' => [ 'type' => 'boolean', 'enum' => [ false ] ], 'undoable' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'result_type' => [ 'type' => 'string' ], 'display_type' => [ 'type' => 'string' ],
				'assistant_summary' => [ 'type' => 'string', 'maxLength' => 256 ], 'extensions' => self::closed_object_schema( [], [] ),
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
		if ( 'orders.get_payment_link' === $action_name ) {
			$properties = [
				'success' => [ 'type' => 'boolean' ],
				'action' => [ 'type' => 'string', 'enum' => [ 'orders.get_payment_link' ] ],
				'message' => [ 'type' => 'string', 'maxLength' => 256 ],
				'summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'data' => self::closed_object_schema( [ 'payment_link' => self::payment_link_schema() ], [ 'payment_link' ] ),
				'read_only' => [ 'type' => 'boolean', 'enum' => [ true ] ],
				'undoable' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'result_type' => [ 'type' => 'string', 'enum' => [ 'payment_link' ] ],
				'display_type' => [ 'type' => 'string', 'enum' => [ 'payment_link' ] ],
				'assistant_summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'extensions' => self::closed_object_schema( [], [] ),
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
		if ( 'orders.get_payment_context' === $action_name ) {
			$properties = [
				'success' => [ 'type' => 'boolean' ],
				'action' => [ 'type' => 'string', 'enum' => [ 'orders.get_payment_context' ] ],
				'message' => [ 'type' => 'string', 'maxLength' => 256 ],
				'summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'data' => self::closed_object_schema( [ 'payment_context' => self::payment_context_schema() ], [ 'payment_context' ] ),
				'read_only' => [ 'type' => 'boolean', 'enum' => [ true ] ],
				'undoable' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'result_type' => [ 'type' => 'string', 'enum' => [ 'payment_context' ] ],
				'display_type' => [ 'type' => 'string', 'enum' => [ 'payment_context' ] ],
				'assistant_summary' => [ 'type' => 'string', 'maxLength' => 256 ],
				'extensions' => self::closed_object_schema( [], [] ),
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
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
		if ( 'coupons.search' === ( $action_name ?: ( $execution['action'] ?? '' ) ) ) {
			$data = $execution['data'] ?? null;
			$schema = self::execution_output_schema( 'coupons.search' )['properties']['data'];
			$valid = is_array( $data ) && self::email_history_schema_value( $data, $schema, 0, true );
			if ( $valid ) {
				$valid = count( $data['coupons'] ) + $data['skipped_count'] <= $data['limit']
					&& $data['next_page'] === ( $data['has_more'] && $data['page'] < 100 ? $data['page'] + 1 : null );
			}
			if ( ! $valid ) {
				return new WP_Error( 'invalid_coupon_search_result', 'Coupon search returned an invalid bounded result.' );
			}
			$summary = sprintf( 'Read %d coupons from a bounded live page.', count( $data['coupons'] ) );
			return [
				'success' => true === ( $execution['success'] ?? false ),
				'action' => 'coupons.search', 'message' => $summary, 'summary' => $summary,
				'data' => $data, 'read_only' => true, 'undoable' => false,
				'result_type' => 'coupon_search', 'display_type' => 'coupon_search',
				'assistant_summary' => $summary, 'extensions' => (object) [],
			];
		}
		if ( 'orders.regenerate_download_permissions' === ( $action_name ?: ( $execution['action'] ?? '' ) ) ) {
			$facts = $execution['download_permissions_regeneration'] ?? ( $execution['data']['download_permissions_regeneration'] ?? null );
			$schema = self::execution_output_schema( 'orders.regenerate_download_permissions' )['properties']['data']['properties']['download_permissions_regeneration'];
			$valid = is_array( $facts ) && self::email_history_schema_value( $facts, $schema );
			if ( $valid ) {
				$regenerated = 'regenerated' === $facts['status'];
				$valid = $regenerated ? ( null === $facts['next_action'] && null !== $facts['current_version'] && null !== $facts['before_permission_count']
					&& $facts['after_permission_count'] === $facts['source_file_grant_count'] && $facts['after_permission_count'] > 0 )
					: ( 'orders.get_download_permissions' === $facts['next_action'] && null === $facts['current_version'] && null === $facts['after_permission_count'] );
			}
			$success = true === ( $execution['success'] ?? false ) && $valid && 'regenerated' === $facts['status'];
			$summary = $success ? 'WooCommerce permissions regenerated and verified. No license or file delivery was verified.' : 'Regeneration requires reconciliation. Read orders.get_download_permissions before any new confirmation; do not retry this operation.';
			return [ 'success' => $success, 'action' => 'orders.regenerate_download_permissions', 'message' => $summary, 'summary' => $summary,
				'data' => [ 'download_permissions_regeneration' => $valid && ( $success || 'reconciliation_required' === $facts['status'] ) ? $facts : null ],
				'read_only' => false, 'undoable' => false, 'result_type' => 'generic', 'display_type' => 'generic', 'assistant_summary' => $summary, 'extensions' => (object) [] ];
		}

		if ( 'orders.get_download_permissions' === ( $action_name ?: ( $execution['action'] ?? '' ) ) ) {
			$facts = $execution['download_permissions'] ?? ( $execution['data']['download_permissions'] ?? null );
			$schema = self::execution_output_schema( 'orders.get_download_permissions' )['properties']['data']['properties']['download_permissions'];
			$valid = is_array( $facts ) && self::email_history_schema_value( $facts, $schema );
			if ( $valid ) {
				$page = $facts['pagination'];
				$expected_next = $page['has_more'] && $page['page'] < 100 ? $page['page'] + 1 : null;
				$valid = $page['next_page'] === $expected_next && count( $facts['permissions'] ) <= $page['limit'];
				if ( isset( $facts['regeneration_guard'] ) ) {
					$guard = $facts['regeneration_guard'];
					$valid = $valid && $guard['order_id'] === $facts['order_id']
						&& ( $guard['eligible'] ? ( null !== $guard['state_token'] && null !== $guard['current_version'] && 'eligible' === $guard['blocked_reason']
							&& null !== $guard['permission_count'] && $guard['source_file_grant_count'] > 0 )
							: ( null === $guard['state_token'] && 'eligible' !== $guard['blocked_reason'] ) );
				}
				foreach ( $facts['permissions'] as $permission ) {
					$valid = $valid && $permission['unlimited_downloads'] === ( null === $permission['downloads_remaining'] );
				}
			}
			$success = true === ( $execution['success'] ?? false ) && $valid;
			$summary = $success ? 'Observed WooCommerce download permissions only; not a license or proof of file delivery. No download URL is exposed.' : 'Download permissions are unavailable.';
			return [ 'success' => $success, 'action' => 'orders.get_download_permissions', 'message' => $summary, 'summary' => $summary,
				'data' => [ 'download_permissions' => $success ? $facts : null ], 'read_only' => true, 'undoable' => false,
				'result_type' => 'generic', 'display_type' => 'generic', 'assistant_summary' => $summary, 'extensions' => (object) [] ];
		}

		if ( 'orders.get_email_history' === ( $action_name ?: ( $execution['action'] ?? '' ) ) ) {
			$facts = $execution['email_history'] ?? ( $execution['data']['email_history'] ?? null );
			$schema = self::execution_output_schema( 'orders.get_email_history' )['properties']['data']['properties']['email_history'];
			$valid = is_array( $facts ) && self::email_history_schema_value( $facts, $schema );
			$pagination = is_array( $facts ) ? ( $facts['pagination'] ?? [] ) : [];
			$valid = $valid && ( $pagination['scan_complete'] === ( null !== $pagination['has_more'] ) )
				&& ( ( false === $pagination['has_more'] ) === ( null === $pagination['next_cursor'] ) )
				&& count( $facts['events'] ) <= $pagination['limit'];
			$success = true === ( $execution['success'] ?? false ) && $valid;
			$summary = $success ? 'Local YoRo email observations only; delivery is not observed. An incomplete scan requires continuation and does not prove no email was sent.' : 'Local email history is unavailable.';
			return [ 'success' => $success, 'action' => 'orders.get_email_history', 'message' => $summary, 'summary' => $summary,
				'data' => [ 'email_history' => $success ? $facts : null ], 'read_only' => true, 'undoable' => false,
				'result_type' => 'generic', 'display_type' => 'generic', 'assistant_summary' => $summary, 'extensions' => (object) [] ];
		}

		$action_name = $action_name ?: ( $execution['action'] ?? '' );
		if ( 'orders.send_transactional_email' === $action_name ) {
			$summary = true === ( $execution['success'] ?? false ) && 'mailer_accepted' === ( $execution['outcome'] ?? '' )
				? 'WooCommerce mailer accepted the native email request; delivery is not verified.'
				: 'Native email outcome is not verified. Reconcile before any further attempt.';
			$data = [];
			foreach ( [ 'order_id', 'email_type', 'current_version', 'outcome', 'confirmation_accepted', 'recipient_masked' ] as $field ) {
				$data[ $field ] = $execution[ $field ] ?? null;
			}
			return [
				'success' => true === ( $execution['success'] ?? false ), 'action' => $action_name,
				'message' => $summary, 'summary' => $summary, 'data' => $data,
				'read_only' => false, 'undoable' => false, 'result_type' => 'transactional_email', 'display_type' => 'transactional_email',
				'assistant_summary' => $summary, 'extensions' => (object) [],
			];
		}
		if ( 'orders.update_addresses' === $action_name ) {
			$summary = true === ( $execution['success'] ?? false ) ? 'Order shipping address updated.' : 'Read the order again to reconcile the update.';
			return [
				'success' => true === ( $execution['success'] ?? false ), 'action' => $action_name,
				'message' => $summary, 'summary' => $summary,
				'data' => self::canonical_write_data( $action_name, $execution ),
				'read_only' => false, 'undoable' => false, 'result_type' => 'shipping_address_update', 'display_type' => 'shipping_address_update',
				'assistant_summary' => $summary, 'extensions' => (object) [],
			];
		}
		$action_name = $action_name ?: ( $execution['action'] ?? '' );
		if ( 'orders.get_payment_link' === $action_name ) {
			// Close the complete public envelope before generic legacy-data or extension handling.
			$facts = $execution['payment_link'] ?? ( $execution['data']['payment_link'] ?? null );
			$context = self::normalize_payment_link( $facts );
			$success = true === ( $execution['success'] ?? false ) && null !== $context;
			$summary = $success ? 'Current WooCommerce payment link; no payment was initiated.' : 'The order payment link is unavailable.';
			return [
				'success' => $success,
				'action' => $action_name,
				'message' => $summary,
				'summary' => $summary,
				'data' => [ 'payment_link' => $success ? $context : null ],
				'read_only' => true,
				'undoable' => false,
				'result_type' => 'payment_link',
				'display_type' => 'payment_link',
				'assistant_summary' => $summary,
				'extensions' => (object) [],
			];
		}

		if ( 'orders.get_payment_context' === $action_name ) {
			// Close the complete public envelope before generic legacy-data or extension handling.
			$facts = $execution['payment_context'] ?? ( $execution['data']['payment_context'] ?? null );
			$context = YoRo_Order_Presenter::normalize_payment_context( $facts );
			$success = true === ( $execution['success'] ?? false ) && null !== $context;
			$summary = $success ? 'WooCommerce order payment facts; independent gateway verification is not observed.' : 'The order payment context is unavailable.';
			return [
				'success' => $success,
				'action' => $action_name,
				'message' => $summary,
				'summary' => $summary,
				'data' => [ 'payment_context' => $success ? $context : null ],
				'read_only' => true,
				'undoable' => false,
				'result_type' => 'payment_context',
				'display_type' => 'payment_context',
				'assistant_summary' => $summary,
				'extensions' => (object) [],
			];
		}
		$age_error = self::queue_age_guard( $execution, $action_name );
		if ( is_wp_error( $age_error ) ) {
			$execution = [ 'success' => false, 'action' => $action_name, 'summary' => $age_error->get_error_message(), 'read_only' => true, 'result_type' => 'error', 'display_type' => 'error', 'errors' => [ [ 'code' => $age_error->get_error_code(), 'message' => $age_error->get_error_message() ] ] ];
		}
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
					'undo_plan' => 'products.update_content' === $action_name
						? []
						: ( is_array( $execution['undo_plan'] ?? null ) ? $execution['undo_plan'] : [] ),
					'legacy_data' => self::is_canonical_read_action( $action_name ) ? $legacy_data : [],
				],
			],
		];
	}

	/** Shared guard used before callers choose an executed event and by direct serialization. */
	public static function queue_age_guard( $execution, $action_name ) {
		if ( ! is_array( $execution ) || empty( $execution['success'] ) || ! empty( $execution['preview'] ) || ! empty( $execution['requires_confirmation'] )
			|| ! in_array( $action_name, [ 'operations.get_attention_queue', 'operations.get_fulfillment_queue', 'customers.get_service_queue', 'operations.get_summary', 'store.get_daily_briefing' ], true ) ) {
			return null;
		}
		$containers = [ $execution ];
		if ( is_array( $execution['data'] ?? null ) && ! self::is_list_array( $execution['data'] ) ) {
			$containers[] = $execution['data'];
		}
		if ( 'store.get_daily_briefing' === $action_name ) {
			foreach ( [ 'attention_queue', 'fulfillment_queue', 'customer_service_queue', 'operations_summary' ] as $key ) {
				if ( is_array( $execution[ $key ] ?? null ) ) {
					$containers[] = $execution[ $key ];
				}
			}
		}
		foreach ( $containers as $container ) {
			$lists = [];
			foreach ( [ 'orders', 'items', 'attention_items', 'recent_orders' ] as $key ) {
				if ( isset( $container[ $key ] ) ) {
					$lists[] = $container[ $key ];
				}
			}
			foreach ( (array) ( $container['queues'] ?? [] ) as $queue ) {
				if ( ! is_array( $queue ) ) {
					return YoRo_Orders_Read_Service::queue_age_error();
				}
				$lists[] = $queue['orders'] ?? [];
			}
			foreach ( $lists as $rows ) {
				if ( ! is_array( $rows ) ) {
					return YoRo_Orders_Read_Service::queue_age_error();
				}
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || ! is_int( $row['age_hours'] ?? null ) || $row['age_hours'] < 0 || ! self::queue_created_time_valid( $row ) ) {
						return YoRo_Orders_Read_Service::queue_age_error();
					}
				}
			}
		}
		return null;
	}

	private static function queue_created_time_valid( array $row ) {
		// Presenter text uses the site's date format. Its original timestamp avoids locale/DST ambiguity.
		if ( array_key_exists( 'date_created_ts', $row ) ) {
			$timestamp = $row['date_created_ts'];
			if ( ! is_int( $timestamp ) || $timestamp <= 0 || $timestamp > time() ) {
				return false;
			}
			if ( isset( $row['extensions'] ) && ! is_array( $row['extensions'] ) ) {
				return false;
			}
			$evidence = $row['extensions']['woocommerce.queue_evidence'] ?? null;
			if ( null !== $evidence ) {
				if ( ! is_array( $evidence ) || ! is_string( $evidence['captured_at'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/D', $evidence['captured_at'] ) ) {
					return false;
				}
				$capture = strtotime( $evidence['captured_at'] );
				return false !== $capture && gmdate( 'c', $capture ) === $evidence['captured_at'] && $capture <= time() && $capture >= $timestamp && $row['age_hours'] === (int) floor( ( $capture - $timestamp ) / HOUR_IN_SECONDS );
			}
			return true;
		}
		$created = $row['created_at'] ?? ( $row['date_created'] ?? null );
		if ( ! is_string( $created ) || '' === $created || strlen( $created ) > 120 || false !== strpos( $created, chr( 0 ) ) ) {
			return false;
		}
		foreach ( [ 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s\\Z', 'Y-m-d H:i:s', YoRo_Date_Helper::get_datetime_format() ] as $format ) {
			$timezone = 'Y-m-d\\TH:i:s\\Z' === $format ? new DateTimeZone( 'UTC' ) : wp_timezone();
			$date = DateTimeImmutable::createFromFormat( '!' . $format, $created, $timezone );
			$errors = DateTimeImmutable::getLastErrors();
			if ( $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( $format ) === $created ) {
				return $date->getTimestamp() > 0 && $date->getTimestamp() <= time();
			}
		}
		return false;
	}

	private static function execution_data_schema( $action_name ) {
		$action_name = sanitize_text_field( $action_name );
		if ( 'fulfillment.create' === $action_name ) {
			$properties = [ 'order_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'fulfillment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => self::closed_object_schema( [ 'item_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'qty' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'item_id', 'qty' ] ) ],
				'status' => [ 'type' => 'string', 'enum' => [ 'unfulfilled' ] ], 'is_fulfilled' => [ 'type' => 'boolean', 'enum' => [ false ] ],
				'allocation_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}(?![\\s\\S])' ],
			];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}

		if ( in_array( $action_name, [ 'fulfillment.add_tracking', 'fulfillment.update_tracking' ], true ) ) {
			$properties = [ 'order_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'fulfillment_id' => [ 'type' => 'integer', 'minimum' => 1 ] ];
			foreach ( [ 'tracking_number' => 200, 'shipment_provider' => 100, 'tracking_url' => 2048 ] as $key => $limit ) {
				$properties[ 'previous_' . $key ] = [ 'type' => 'string', 'maxLength' => $limit ];
			}
			$properties['tracking_number'] = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[^\\x00-\\x1f\\x7f<>]+(?![\\s\\S])' ];
			$properties['shipment_provider'] = [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'pattern' => '^[a-zA-Z0-9_-]+(?![\\s\\S])' ];
			$properties['tracking_url'] = [ 'type' => 'string', 'maxLength' => 2048 ];
			$properties['current_version'] = [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}(?![\\s\\S])' ];
			return self::closed_object_schema( $properties, array_keys( $properties ) );
		}
		if ( 'coupons.update' === $action_name ) {
			$amount = [ 'type' => 'string', 'pattern' => '^(?:0|[1-9][0-9]{0,11})(?:\\.[0-9]{1,6})?(?![\\s\\S])' ];
			return self::closed_object_schema( [
				'coupon_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'coupon_code' => [ 'type' => 'string' ],
				'discount_type' => [ 'type' => 'string', 'enum' => [ 'percent', 'fixed_cart', 'fixed_product' ] ],
				'previous_discount_amount' => $amount, 'discount_amount' => $amount,
				'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}(?![\\s\\S])' ],
			], [ 'coupon_id', 'coupon_code', 'discount_type', 'previous_discount_amount', 'discount_amount', 'current_version' ] );
		}

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

		if ( 'products.adjust_stock' === $action_name ) {
			return self::closed_object_schema( [
				'product_id' => [ 'type' => 'integer' ], 'stock_quantity' => [ 'type' => 'integer' ],
				'stock_status' => [ 'type' => 'string' ], 'manage_stock' => [ 'type' => 'boolean' ], 'current_version' => [ 'type' => 'string' ],
				'stock_delta' => [ 'type' => 'integer' ], 'previous_stock_quantity' => [ 'type' => 'integer' ],
			], [ 'product_id', 'stock_quantity', 'stock_status', 'manage_stock', 'current_version', 'stock_delta', 'previous_stock_quantity' ] );
		}

		if ( 'products.update_pricing' === $action_name ) {
			return self::closed_object_schema( [
				'product_id' => [ 'type' => 'integer' ],
				'previous_regular_price' => [ 'type' => 'string' ],
				'regular_price' => [ 'type' => 'string' ],
				'active_price' => [ 'type' => 'string' ],
				'currency' => [ 'type' => 'string' ],
				'current_version' => [ 'type' => 'string' ],
			], [ 'product_id', 'previous_regular_price', 'regular_price', 'active_price', 'currency', 'current_version' ] );
		}

		if ( 'products.update_content' === $action_name ) {
			return self::closed_object_schema( [
				'product_id' => [ 'type' => 'integer' ],
				'field' => [ 'type' => 'string', 'enum' => [ 'name', 'description', 'short_description' ] ],
				'previous_value_fingerprint' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'value_fingerprint' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
				'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			], [ 'product_id', 'field', 'previous_value_fingerprint', 'value_fingerprint', 'current_version' ] );
		}

		if ( 'products.create_draft' === $action_name ) {
			return self::closed_object_schema( [
				'product_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ],
			], [ 'product_id', 'current_version' ] );
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
			$native = [
				'source' => [ 'type' => 'string', 'enum' => [ 'woo_native_fulfillment' ] ],
				'order_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'fulfillment_id' => [ 'type' => 'integer', 'minimum' => 1 ],
				'status' => [ 'type' => 'string', 'maxLength' => 50 ], 'is_fulfilled' => [ 'type' => 'boolean' ], 'locked' => [ 'type' => 'boolean' ],
				'tracking_number' => [ 'type' => 'string', 'maxLength' => 200 ], 'shipment_provider' => [ 'type' => 'string', 'maxLength' => 100 ],
				'tracking_url' => [ 'type' => 'string', 'maxLength' => 2048 ],
				'current_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}(?![\\s\\S])' ],
			];
			return self::closed_object_schema(
				[
					'order_id'  => [ 'type' => [ 'string', 'integer' ] ],
					'status'    => [ 'type' => 'string' ],
					'shipments' => self::array_schema( self::canonical_tracking_shipment_schema() ),
					'count'     => [ 'type' => 'integer', 'minimum' => 0 ],
					'native_status' => [ 'type' => 'string', 'enum' => [ 'available', 'unavailable' ] ],
					'native_fulfillments' => [ 'type' => 'array', 'maxItems' => 100, 'items' => self::closed_object_schema( $native, array_keys( $native ) ) ],
					'pending_items' => [ 'type' => 'array', 'maxItems' => 100, 'items' => self::closed_object_schema( [ 'item_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'qty' => [ 'type' => 'integer', 'minimum' => 1 ] ], [ 'item_id', 'qty' ] ) ],
					'allocation_version' => [ 'type' => 'string', 'pattern' => '^[a-f0-9]{64}(?![\\s\\S])' ],
				],
				[ 'order_id', 'status', 'shipments', 'count' ]
			);
		}

		return [ 'type' => 'object' ];
	}

	private static function payment_context_schema() {
		$properties = [
			'order_id' => [ 'type' => 'integer', 'minimum' => 1 ],
			'order_number' => [ 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ],
		];
		foreach ( [ 'status' => 64, 'status_label' => 256, 'currency' => 8, 'payment_method' => 128, 'payment_method_title' => 256, 'transaction_id' => 128, 'date_created' => 40, 'date_paid' => 40 ] as $field => $limit ) {
			$properties[ $field ] = [ 'type' => [ 'string', 'null' ], 'maxLength' => $limit ];
		}
		foreach ( [ 'total', 'total_refunded', 'remaining_refundable' ] as $field ) {
			$properties[ $field ] = [ 'type' => [ 'string', 'null' ], 'maxLength' => 27, 'pattern' => '^[0-9]{1,18}(?:\\.[0-9]{1,8})?$' ];
		}
		$properties['is_paid'] = [ 'type' => [ 'boolean', 'null' ] ];
		$properties['needs_payment'] = [ 'type' => [ 'boolean', 'null' ] ];
		$properties['evidence'] = self::closed_object_schema(
			[
				'source' => [ 'type' => 'string', 'enum' => [ 'woocommerce_order' ] ],
				'gateway_verification' => [ 'type' => 'string', 'enum' => [ 'not_observed' ] ],
				'limitation' => [ 'type' => 'string', 'enum' => [ YoRo_Order_Presenter::PAYMENT_EVIDENCE_LIMITATION ] ],
			],
			[ 'source', 'gateway_verification', 'limitation' ]
		);
		$schema = self::closed_object_schema( $properties, array_keys( $properties ) );
		$schema['type'] = [ 'object', 'null' ];
		return $schema;
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
			[ 'orders.search', 'orders.get_details', 'orders.find_by_customer', 'customers.lookup', 'store.get_daily_briefing', 'operations.get_attention_queue', 'products.get_inventory_queue', 'content.search', 'content.get', 'comments.list', 'media.search', 'media.get', 'site.get_info', 'site.get_health', 'site.get_update_status', 'products.search', 'products.get_details', 'orders.get_timeline', 'fulfillment.get_tracking' ],
			true
		);
	}

	private static function is_canonical_write_action( $action_name ) {
		return in_array(
			sanitize_text_field( $action_name ),
			[ 'fulfillment.create', 'fulfillment.add_tracking', 'fulfillment.update_tracking', 'coupons.update', 'orders.update_addresses', 'orders.update_status', 'products.create_draft', 'products.update_content', 'products.update_pricing', 'products.update_stock', 'products.adjust_stock', 'products.update_stock_status' ],
			true
		);
	}

	private static function canonical_write_data( $action_name, array $execution ) {
		if ( 'fulfillment.create' === $action_name ) {
			$data = [];
			foreach ( [ 'order_id', 'fulfillment_id', 'items', 'status', 'is_fulfilled', 'allocation_version' ] as $key ) { $data[ $key ] = $execution[ $key ] ?? null; }
			return $data;
		}

		if ( in_array( $action_name, [ 'fulfillment.add_tracking', 'fulfillment.update_tracking' ], true ) ) {
			$data = [];
			foreach ( [ 'order_id', 'fulfillment_id', 'previous_tracking_number', 'previous_shipment_provider', 'previous_tracking_url', 'tracking_number', 'shipment_provider', 'tracking_url', 'current_version' ] as $key ) {
				$data[ $key ] = $execution[ $key ] ?? null;
			}
			return $data;
		}
		if ( 'coupons.update' === $action_name ) {
			$data = [];
			foreach ( [ 'coupon_id', 'coupon_code', 'discount_type', 'previous_discount_amount', 'discount_amount', 'current_version' ] as $field ) {
				$data[ $field ] = $execution[ $field ] ?? null;
			}
			return $data;
		}
		if ( 'orders.update_addresses' === $action_name ) {
			return [ 'order_id' => $execution['order_id'] ?? null, 'changed_fields' => $execution['changed_fields'] ?? null, 'current_version' => $execution['current_version'] ?? null ];
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

		if ( 'products.adjust_stock' === $action_name ) {
			$data = [];
			foreach ( [ 'product_id', 'stock_quantity', 'stock_status', 'manage_stock', 'current_version', 'stock_delta', 'previous_stock_quantity' ] as $key ) {
				if ( array_key_exists( $key, $execution ) ) { $data[ $key ] = $execution[ $key ]; }
			}
			return $data;
		}
		if ( 'products.update_pricing' === $action_name ) {
			$data = [];
			foreach ( [ 'product_id', 'previous_regular_price', 'regular_price', 'active_price', 'currency', 'current_version' ] as $key ) {
				if ( array_key_exists( $key, $execution ) ) {
					$data[ $key ] = $execution[ $key ];
				}
			}

			return $data;
		}
		if ( 'products.update_content' === $action_name ) {
			$data = [];
			foreach ( [ 'product_id', 'field', 'previous_value_fingerprint', 'value_fingerprint', 'current_version' ] as $key ) {
				if ( array_key_exists( $key, $execution ) ) {
					$data[ $key ] = $execution[ $key ];
				}
			}

			return $data;
		}
		if ( 'products.create_draft' === $action_name ) {
			$data = [];
			foreach ( [ 'product_id', 'current_version' ] as $key ) {
				if ( array_key_exists( $key, $execution ) ) {
					$data[ $key ] = $execution[ $key ];
				}
			}

			return $data;
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

	private static function canonical_read_data( $action_name, array $execution ) {
		$raw_data = is_array( $execution['data'] ?? null ) ? $execution['data'] : [];

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
			$data = [
				'order_id'  => $execution['order_id'] ?? ( $raw_data['order_id'] ?? 0 ),
				'status'    => sanitize_key( $execution['status'] ?? ( $raw_data['status'] ?? 'unavailable' ) ),
				'shipments' => array_values( (array) ( $execution['shipments'] ?? ( $raw_data['shipments'] ?? [] ) ) ),
				'count'     => count( (array) ( $execution['shipments'] ?? ( $raw_data['shipments'] ?? [] ) ) ),
			];
			foreach ( [ 'native_status', 'native_fulfillments', 'pending_items', 'allocation_version' ] as $key ) {
				if ( array_key_exists( $key, $execution ) || array_key_exists( $key, $raw_data ) ) {
					$data[ $key ] = $execution[ $key ] ?? $raw_data[ $key ];
				}
			}
			return $data;
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
			'extensions'     => array_replace( [ 'woocommerce.order' => [ 'edit_url' => esc_url_raw( $item['edit_url'] ?? '' ) ] ], is_array( $item['extensions'] ?? null ) ? $item['extensions'] : [] ),
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
