<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Report_Builder {

	private static $store_context_pack_stack = [];

	public static function build( $type, $window, $health, $headline, $metrics = [], $insights = [], $alerts = [], $recommended_actions = [], $drilldowns = [], $breakdowns = [] ) {
		$window = is_array( $window ) ? YoRo_Date_Helper::build_window_payload( $window ) : [];
		$metrics = array_values( array_filter( array_map( [ __CLASS__, 'normalize_metric' ], is_array( $metrics ) ? $metrics : [] ) ) );
		$insights = self::sort_report_items( array_values( array_filter( array_map( [ __CLASS__, 'normalize_insight' ], is_array( $insights ) ? $insights : [] ) ) ) );
		$alerts = self::sort_report_items( array_values( array_filter( array_map( [ __CLASS__, 'normalize_alert' ], is_array( $alerts ) ? $alerts : [] ) ) ) );
		$recommended_actions = self::sort_report_items( array_values( array_filter( array_map( [ __CLASS__, 'normalize_action' ], is_array( $recommended_actions ) ? $recommended_actions : [] ) ) ) );
		$drilldowns = self::sort_report_items( array_values( array_filter( array_map( [ __CLASS__, 'normalize_action' ], is_array( $drilldowns ) ? $drilldowns : [] ) ) ) );
		$breakdowns = self::sort_report_sections( array_values( array_filter( array_map( [ __CLASS__, 'normalize_breakdown' ], is_array( $breakdowns ) ? $breakdowns : [] ) ) ) );

		return [
			'type'                => sanitize_key( $type ),
			'window'              => $window,
			'health'              => self::normalize_health( $health ),
			'headline'            => sanitize_text_field( $headline ),
			'metrics'             => $metrics,
			'metric_groups'       => self::build_metric_groups( $metrics ),
			'insights'            => $insights,
			'alerts'              => $alerts,
			'recommended_actions' => $recommended_actions,
			'drilldowns'          => $drilldowns,
			'breakdowns'          => $breakdowns,
		];
	}

	public static function with_store_context_pack( $store_context_pack, callable $callback ) {
		self::$store_context_pack_stack[] = is_array( $store_context_pack ) ? $store_context_pack : [];

		try {
			return $callback();
		} finally {
			array_pop( self::$store_context_pack_stack );
		}
	}

	public static function metric( $key, $label, $value, $unit = '', $format = 'number', $severity = 'neutral', $trend = '', $delta = null, $source = 'store', $domain = '', $description = '', $priority = 50 ) {
		$key = sanitize_key( $key );

		return [
			'key'         => $key,
			'label'       => sanitize_text_field( $label ),
			'value'       => is_scalar( $value ) ? (string) $value : '',
			'unit'        => sanitize_text_field( $unit ),
			'format'      => sanitize_key( $format ),
			'severity'    => self::normalize_severity( $severity ),
			'trend'       => sanitize_key( $trend ),
			'delta'       => null === $delta || is_scalar( $delta ) ? $delta : null,
			'source'      => sanitize_key( $source ),
			'domain'      => self::normalize_metric_domain( $domain, $key ),
			'description' => sanitize_text_field( $description ),
			'priority'    => max( 0, min( 100, absint( $priority ) ) ),
		];
	}

	public static function insight( $title, $message, $topic = 'overview', $severity = 'neutral', $domain = '', $priority = 50, $kind = 'insight' ) {
		$topic = sanitize_key( $topic );

		return [
			'title'    => sanitize_text_field( $title ),
			'message'  => sanitize_text_field( $message ),
			'topic'    => $topic,
			'severity' => self::normalize_severity( $severity ),
			'domain'   => self::normalize_report_domain( $domain, $topic, $title . ' ' . $message ),
			'priority' => max( 0, min( 100, absint( $priority ) ) ),
			'kind'     => sanitize_key( $kind ),
		];
	}

	public static function alert( $title, $message, $severity = 'watch', $topic = 'risk', $domain = '', $priority = 20 ) {
		return self::insight( $title, $message, $topic, $severity, $domain, $priority, 'alert' );
	}

	public static function action( $label, $command, $kind = 'view', $domain = '', $priority = 50, $reason = '' ) {
		return [
			'label'    => sanitize_text_field( $label ),
			'command'  => sanitize_text_field( $command ),
			'kind'     => sanitize_key( $kind ),
			'domain'   => self::normalize_report_domain( $domain, '', $label . ' ' . $command . ' ' . $reason ),
			'priority' => max( 0, min( 100, absint( $priority ) ) ),
			'reason'   => sanitize_text_field( $reason ),
		];
	}

	public static function breakdown( $key, $label, $items = [], $domain = '', $priority = 50, $description = '' ) {
		$key   = sanitize_key( $key );
		$label = sanitize_text_field( $label );

		if ( '' === $key || '' === $label ) {
			return null;
		}

		$items = array_values( array_filter( array_map( [ __CLASS__, 'normalize_breakdown_item' ], is_array( $items ) ? $items : [] ) ) );

		if ( empty( $items ) ) {
			return null;
		}

		return [
			'key'         => $key,
			'label'       => $label,
			'description' => sanitize_text_field( $description ),
			'domain'      => self::normalize_report_domain( $domain, $key, $label . ' ' . $description ),
			'priority'    => max( 0, min( 100, absint( $priority ) ) ),
			'items'       => $items,
		];
	}

	public static function breakdown_item( $label, $value, $unit = '', $subvalue = '', $share = '', $meta = [] ) {
		return [
			'label'    => sanitize_text_field( $label ),
			'value'    => is_scalar( $value ) ? (string) $value : '',
			'unit'     => sanitize_text_field( $unit ),
			'subvalue' => sanitize_text_field( $subvalue ),
			'share'    => sanitize_text_field( $share ),
			'meta'     => self::sanitize_meta_map( is_array( $meta ) ? $meta : [] ),
		];
	}

	public static function store_profile_context( $domains = [] ) {
		if ( ! class_exists( 'YoRo_Store_Knowledge_Provider' ) ) {
			return [];
		}

		$domains   = array_values( array_filter( array_map( 'sanitize_key', is_array( $domains ) ? $domains : [] ) ) );
		$knowledge = YoRo_Store_Knowledge_Provider::get_context( 'report' );

		if ( ! is_array( $knowledge ) ) {
			return [];
		}

		$profiles = isset( $knowledge['profiles'] ) && is_array( $knowledge['profiles'] ) ? $knowledge['profiles'] : [];
		$facts    = isset( $knowledge['facts'] ) && is_array( $knowledge['facts'] ) ? $knowledge['facts'] : [];
		$settings = isset( $facts['settings'] ) && is_array( $facts['settings'] ) ? $facts['settings'] : [];

		if ( empty( $profiles ) ) {
			return [];
		}

		$commerce  = isset( $profiles['commerce'] ) && is_array( $profiles['commerce'] ) ? $profiles['commerce'] : [];
		$customers = isset( $profiles['customers'] ) && is_array( $profiles['customers'] ) ? $profiles['customers'] : [];
		$catalog   = isset( $profiles['catalog'] ) && is_array( $profiles['catalog'] ) ? $profiles['catalog'] : [];
		$sales     = isset( $profiles['sales_baselines'] ) && is_array( $profiles['sales_baselines'] ) ? $profiles['sales_baselines'] : [];
		$operations = isset( $profiles['operations'] ) && is_array( $profiles['operations'] ) ? $profiles['operations'] : [];
		$inventory = isset( $profiles['inventory'] ) && is_array( $profiles['inventory'] ) ? $profiles['inventory'] : [];

		$context = [
			'schema_version' => 1,
			'source_schema_version' => absint( $knowledge['schema_version'] ?? 0 ),
			'generated_at' => sanitize_text_field( $knowledge['generated_at'] ?? '' ),
			'policy' => [
				'profile_signals_are_auto_collected_or_inferred' => true,
				'confirmed_preferences_take_precedence' => true,
			],
			'inferred' => array_filter(
				[
					'fulfillment_model' => self::profile_signal(
						$commerce['fulfillment_model_estimate'] ?? '',
						$commerce['fulfillment_model_confidence'] ?? 0,
						$commerce['source'] ?? ''
					),
					'customer_model' => self::profile_signal(
						$customers['customer_model_estimate'] ?? '',
						$customers['customer_model_confidence'] ?? 0,
						$customers['source'] ?? ''
					),
				]
			),
			'key_context' => array_filter(
				[
					'primary_product_groups' => self::profile_text_list( $catalog['primary_product_groups'] ?? [], 5 ),
					'commerce_mix' => array_filter(
						[
							'physical_order_ratio' => self::profile_number_or_null( $commerce['physical_order_ratio'] ?? null ),
							'digital_product_ratio' => self::profile_number_or_null( $commerce['digital_product_ratio'] ?? null ),
							'subscription_product_ratio' => self::profile_number_or_null( $commerce['subscription_product_ratio'] ?? null ),
							'subscription_signal' => ! empty( $commerce['subscription_signal'] ),
						],
						static fn( $value ) => null !== $value && '' !== $value && [] !== $value
					),
				],
				static fn( $value ) => null !== $value && '' !== $value && [] !== $value
			),
			'gaps' => self::profile_gaps( $profiles, $domains ),
			'sources' => self::profile_sources( $profiles ),
		];

		$applicability = self::settings_applicability_context( $settings['applicability'] ?? [] );

		if ( ! empty( $applicability ) ) {
			$context['settings_applicability'] = $applicability;
		}

		if ( self::profile_includes_domain( $domains, [ 'sales', 'reporting' ] ) ) {
			$context['baselines'] = self::profile_sales_baselines( $sales );
		}

		if ( self::profile_includes_domain( $domains, [ 'operations', 'fulfillment' ] ) ) {
			$context['key_context']['operations_age_buckets'] = self::profile_number_map( $operations['age_buckets'] ?? [], 12 );
			$context['key_context']['operations_default_thresholds'] = self::profile_number_map( $operations['default_thresholds'] ?? [], 12 );
		}

		if ( self::profile_includes_domain( $domains, [ 'inventory' ] ) ) {
			$context['key_context']['inventory_counts'] = self::profile_number_map( $inventory['counts'] ?? [], 12 );
		}

		$context = self::merge_context_pack_profile_intelligence(
			$context,
			self::current_store_context_pack()
		);

		return array_filter(
			$context,
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	public static function attach_profile_context( $report, $profile_context ) {
		$report = is_array( $report ) ? $report : [];
		$profile_context = is_array( $profile_context ) ? $profile_context : [];

		if ( ! empty( $profile_context ) ) {
			$report['profile_context'] = $profile_context;
			$applicability = self::settings_applicability_context( $profile_context['settings_applicability'] ?? [] );

			if ( ! empty( $applicability ) ) {
				$report['applicability'] = $applicability;
			}

			$followups = self::profile_followups( $profile_context );

			if ( ! empty( $followups ) ) {
				$report['profile_followups'] = $followups;
			}
		}

		return $report;
	}

	public static function business_profile_insights( $profile_context, $business_metrics, $window = [] ) {
		$profile_context  = is_array( $profile_context ) ? $profile_context : [];
		$business_metrics = is_array( $business_metrics ) ? $business_metrics : [];
		$insights         = [];
		$currency         = get_woocommerce_currency();
		$current_aov      = self::profile_number_or_null( $business_metrics['average_order_value'] ?? null );
		$baseline         = self::preferred_sales_baseline( $profile_context, $window );
		$baseline_aov     = self::profile_number_or_null( $baseline['average_order_value'] ?? null );

		if ( null !== $current_aov && $current_aov > 0 && null !== $baseline_aov && $baseline_aov > 0 ) {
			$diff = ( ( $current_aov - $baseline_aov ) / $baseline_aov ) * 100;
			$baseline_label = sanitize_text_field( $baseline['label'] ?? __( 'store baseline', 'yoro-ai-core' ) );
			$insights[] = self::insight(
				__( 'AOV versus store baseline', 'yoro-ai-core' ),
				sprintf(
					__( 'Average order value is %1$s%% %2$s the %3$s baseline of %4$s %5$s.', 'yoro-ai-core' ),
					wc_format_decimal( abs( $diff ), 1 ),
					$diff >= 0 ? __( 'above', 'yoro-ai-core' ) : __( 'below', 'yoro-ai-core' ),
					$baseline_label,
					wc_format_decimal( $baseline_aov, 2 ),
					$currency
				),
				'sales',
				$diff >= 0 ? 'positive' : 'watch',
				'sales',
				22,
				'profile_signal'
			);
		}

		$product_groups = is_array( $profile_context['key_context']['primary_product_groups'] ?? null )
			? array_values( array_filter( $profile_context['key_context']['primary_product_groups'] ) )
			: [];

		if ( ! empty( $product_groups ) ) {
			$insights[] = self::insight(
				__( 'Store product context', 'yoro-ai-core' ),
				sprintf(
					__( 'The store profile currently points to %s as core product groups, so product mix should be read against that context.', 'yoro-ai-core' ),
					implode( ', ', array_slice( array_map( 'sanitize_text_field', $product_groups ), 0, 3 ) )
				),
				'products',
				'neutral',
				'products',
				48,
				'profile_context'
			);
		}

		$gaps = self::important_profile_gaps( $profile_context, [ 'primary_product_groups', 'fulfillment_model_confirmation', 'customer_model_confirmation' ] );

		if ( ! empty( $gaps ) ) {
			$insights[] = self::insight(
				__( 'Store profile needs confirmation', 'yoro-ai-core' ),
				sanitize_text_field( $gaps[0]['suggested_question'] ?? $gaps[0]['reason'] ?? __( 'A store profile detail needs confirmation.', 'yoro-ai-core' ) ),
				'profile',
				'neutral',
				'overview',
				75,
				'profile_gap'
			);
		}

		return $insights;
	}

	public static function operations_profile_insights( $profile_context ) {
		$profile_context = is_array( $profile_context ) ? $profile_context : [];
		$insights        = [];
		$fulfillment     = isset( $profile_context['inferred']['fulfillment_model'] ) && is_array( $profile_context['inferred']['fulfillment_model'] )
			? $profile_context['inferred']['fulfillment_model']
			: [];

		if ( self::profile_applicability_gate_applies( $profile_context, 'shipping' ) && 'physical_goods' === sanitize_key( $fulfillment['value'] ?? '' ) && (float) ( $fulfillment['confidence'] ?? 0 ) >= 0.7 ) {
			$insights[] = self::insight(
				__( 'Physical goods context', 'yoro-ai-core' ),
				__( 'The store profile indicates mainly physical goods, so aged processing and fulfillment backlog can directly affect customer delivery experience.', 'yoro-ai-core' ),
				'fulfillment',
				'neutral',
				'operations',
				35,
				'profile_context'
			);
		}

		$confirmed_thresholds = is_array( $profile_context['key_context']['operations_confirmed_thresholds'] ?? null )
			? $profile_context['key_context']['operations_confirmed_thresholds']
			: [];
		$thresholds = is_array( $profile_context['key_context']['operations_effective_thresholds'] ?? null )
			? $profile_context['key_context']['operations_effective_thresholds']
			: ( is_array( $profile_context['key_context']['operations_default_thresholds'] ?? null ) ? $profile_context['key_context']['operations_default_thresholds'] : [] );

		if ( ! empty( $thresholds['attention_order_age_hours'] ) ) {
			$has_confirmed_attention_threshold = array_key_exists( 'attention_order_age_hours', $confirmed_thresholds );
			$insights[] = self::insight(
				__( 'Attention threshold context', 'yoro-ai-core' ),
				$has_confirmed_attention_threshold
					? sprintf(
						__( 'A confirmed store preference treats open orders around %d hours old as attention candidates.', 'yoro-ai-core' ),
						absint( $thresholds['attention_order_age_hours'] )
					)
					: sprintf(
						__( 'The current profile default treats open orders around %d hours old as attention candidates unless a confirmed store preference overrides it.', 'yoro-ai-core' ),
						absint( $thresholds['attention_order_age_hours'] )
					),
				'operations',
				'neutral',
				'operations',
				65,
				'profile_context'
			);
		}

		return $insights;
	}

	public static function inventory_profile_insights( $profile_context ) {
		$profile_context = is_array( $profile_context ) ? $profile_context : [];

		if ( ! self::profile_applicability_gate_applies( $profile_context, 'inventory' ) ) {
			return [];
		}

		$counts          = is_array( $profile_context['key_context']['inventory_counts'] ?? null )
			? $profile_context['key_context']['inventory_counts']
			: [];
		$risk_count      = absint( $counts['out_of_stock'] ?? 0 )
			+ absint( $counts['on_backorder'] ?? 0 )
			+ absint( $counts['low_stock_in_sample'] ?? 0 );

		if ( $risk_count <= 0 ) {
			return [];
		}

		return [
			self::insight(
				__( 'Inventory profile context', 'yoro-ai-core' ),
				sprintf(
					__( 'The store profile currently tracks %d stock-risk signals, so product demand should be read together with availability.', 'yoro-ai-core' ),
					$risk_count
				),
				'inventory',
				'watch',
				'inventory',
				42,
				'profile_context'
			),
		];
	}

	public static function metric_comparison( $current, $previous ) {
		$current  = is_numeric( $current ) ? (float) $current : 0.0;
		$previous = is_numeric( $previous ) ? (float) $previous : 0.0;
		$diff     = $current - $previous;

		if ( abs( $diff ) < 0.00001 ) {
			return [
				'trend' => 'flat',
				'delta' => __( '0.0% vs previous', 'yoro-ai-core' ),
			];
		}

		$trend = $diff > 0 ? 'up' : 'down';

		if ( abs( $previous ) < 0.00001 ) {
			return [
				'trend' => $trend,
				'delta' => $current > 0
					? __( 'new vs previous', 'yoro-ai-core' )
					: __( '-100.0% vs previous', 'yoro-ai-core' ),
			];
		}

		$percent = ( $diff / abs( $previous ) ) * 100;

		return [
			'trend' => $trend,
			'delta' => sprintf( '%+0.1f%% %s', $percent, __( 'vs previous', 'yoro-ai-core' ) ),
		];
	}

	private static function profile_signal( $value, $confidence, $source = '' ) {
		$value = sanitize_key( (string) $value );

		if ( '' === $value ) {
			return [];
		}

		$confidence = self::profile_ratio( $confidence );

		return array_filter(
			[
				'value'            => $value,
				'confidence'       => $confidence,
				'confidence_level' => self::profile_confidence_level( $confidence ),
				'source'           => sanitize_key( (string) $source ),
			],
			static fn( $item ) => null !== $item && '' !== $item && [] !== $item
		);
	}

	private static function current_store_context_pack() {
		if ( empty( self::$store_context_pack_stack ) ) {
			return [];
		}

		$current = end( self::$store_context_pack_stack );

		return is_array( $current ) ? $current : [];
	}

	private static function merge_context_pack_profile_intelligence( $context, $store_context_pack ) {
		$context            = is_array( $context ) ? $context : [];
		$store_context_pack = is_array( $store_context_pack ) ? $store_context_pack : [];
		$profile            = isset( $store_context_pack['profile_intelligence'] ) && is_array( $store_context_pack['profile_intelligence'] )
			? $store_context_pack['profile_intelligence']
			: [];

		if ( empty( $profile ) ) {
			return $context;
		}

		$confirmed = isset( $profile['confirmed'] ) && is_array( $profile['confirmed'] )
			? self::sanitize_confirmed_profile_context( $profile['confirmed'] )
			: [];

		if ( ! empty( $confirmed ) ) {
			$context['confirmed'] = $confirmed;
		}

		$effective = isset( $confirmed['effective_context'] ) && is_array( $confirmed['effective_context'] )
			? $confirmed['effective_context']
			: [];

		$key_context = isset( $profile['key_context'] ) && is_array( $profile['key_context'] )
			? self::sanitize_profile_key_context( $profile['key_context'] )
			: [];

		if ( ! empty( $key_context ) ) {
			$context['key_context'] = array_replace_recursive(
				is_array( $context['key_context'] ?? null ) ? $context['key_context'] : [],
				$key_context
			);
		}

		$product_groups = self::profile_text_list( $effective['primary_product_groups'] ?? [], 8 );

		if ( ! empty( $product_groups ) ) {
			$context['key_context']['primary_product_groups'] = $product_groups;
		}

		$fulfillment = self::profile_effective_signal( $effective['fulfillment_model'] ?? null );

		if ( ! empty( $fulfillment ) ) {
			$context['inferred']['fulfillment_model'] = $fulfillment;
		}

		$customer_model = self::profile_effective_signal( $effective['customer_model'] ?? null );

		if ( ! empty( $customer_model ) ) {
			$context['inferred']['customer_model'] = $customer_model;
		}

		$thresholds = self::profile_number_map( $effective['operations_thresholds'] ?? [], 12 );

		if ( ! empty( $thresholds ) ) {
			$defaults = is_array( $context['key_context']['operations_default_thresholds'] ?? null )
				? $context['key_context']['operations_default_thresholds']
				: [];
			$context['key_context']['operations_confirmed_thresholds'] = $thresholds;
			$context['key_context']['operations_effective_thresholds'] = array_replace( $defaults, $thresholds );
		}

		if ( ! empty( $effective['reporting_defaults'] ) && is_array( $effective['reporting_defaults'] ) ) {
			$context['key_context']['reporting_defaults'] = self::sanitize_meta_map( $effective['reporting_defaults'] );
		}

		if ( ! empty( $context['gaps'] ) ) {
			$context['gaps'] = self::filter_confirmed_profile_gaps( $context['gaps'], $effective );
		}

		return $context;
	}

	private static function settings_applicability_context( $gates ) {
		$output = [];

		foreach ( is_array( $gates ) ? $gates : [] as $domain => $gate ) {
			$domain = sanitize_key( (string) $domain );

			if ( '' === $domain || ! is_array( $gate ) ) {
				continue;
			}

			$entry = array_filter(
				[
					'applicable' => (bool) ( $gate['applicable'] ?? false ),
					'reason'     => sanitize_key( (string) ( $gate['reason'] ?? '' ) ),
					'meta'       => self::sanitize_meta_map( is_array( $gate['meta'] ?? null ) ? $gate['meta'] : [] ),
				],
				static fn( $value ) => null !== $value && '' !== $value && [] !== $value
			);

			if ( ! empty( $entry ) ) {
				$output[ $domain ] = $entry;
			}
		}

		return $output;
	}

	private static function profile_applicability_gate_applies( $profile_context, $domain ) {
		$profile_context = is_array( $profile_context ) ? $profile_context : [];
		$domain = sanitize_key( (string) $domain );

		if ( '' === $domain ) {
			return true;
		}

		$gate = $profile_context['settings_applicability'][ $domain ] ?? null;

		if ( ! is_array( $gate ) || ! array_key_exists( 'applicable', $gate ) ) {
			return true;
		}

		return (bool) $gate['applicable'];
	}

	private static function sanitize_confirmed_profile_context( $confirmed ) {
		if ( ! is_array( $confirmed ) ) {
			return [];
		}

		$clean = array_filter(
			[
				'schema_version' => absint( $confirmed['schema_version'] ?? 0 ),
				'policy'         => sanitize_text_field( $confirmed['policy'] ?? '' ),
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value && 0 !== $value
		);

		if ( isset( $confirmed['by_domain'] ) && is_array( $confirmed['by_domain'] ) ) {
			$by_domain = [];

			foreach ( $confirmed['by_domain'] as $domain => $items ) {
				$domain = sanitize_key( (string) $domain );

				if ( '' === $domain || ! is_array( $items ) ) {
					continue;
				}

				foreach ( array_slice( $items, 0, 12 ) as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$entry = array_filter(
						[
							'key'          => sanitize_key( $item['key'] ?? '' ),
							'value'        => self::sanitize_profile_value( $item['value'] ?? null ),
							'value_type'   => sanitize_key( $item['value_type'] ?? '' ),
							'source'       => sanitize_key( $item['source'] ?? '' ),
							'confirmed_at' => sanitize_text_field( $item['confirmed_at'] ?? '' ),
						],
						static fn( $value ) => null !== $value && '' !== $value && [] !== $value
					);

					if ( ! empty( $entry ) ) {
						$by_domain[ $domain ][] = $entry;
					}
				}
			}

			if ( ! empty( $by_domain ) ) {
				$clean['by_domain'] = $by_domain;
			}
		}

		if ( isset( $confirmed['effective_context'] ) && is_array( $confirmed['effective_context'] ) ) {
			$effective = self::sanitize_effective_profile_context( $confirmed['effective_context'] );

			if ( ! empty( $effective ) ) {
				$clean['effective_context'] = $effective;
			}
		}

		return $clean;
	}

	private static function sanitize_effective_profile_context( $effective ) {
		$effective = is_array( $effective ) ? $effective : [];
		$clean     = [];

		$product_groups = self::profile_text_list( $effective['primary_product_groups'] ?? [], 8 );

		if ( ! empty( $product_groups ) ) {
			$clean['primary_product_groups'] = $product_groups;
		}

		foreach ( [ 'fulfillment_model', 'customer_model' ] as $key ) {
			$signal = self::profile_effective_signal( $effective[ $key ] ?? null );

			if ( ! empty( $signal ) ) {
				$clean[ $key ] = $signal;
			}
		}

		$thresholds = self::profile_number_map( $effective['operations_thresholds'] ?? [], 12 );

		if ( ! empty( $thresholds ) ) {
			$clean['operations_thresholds'] = $thresholds;
		}

		if ( ! empty( $effective['reporting_defaults'] ) && is_array( $effective['reporting_defaults'] ) ) {
			$clean['reporting_defaults'] = self::sanitize_meta_map( $effective['reporting_defaults'] );
		}

		if ( ! empty( $effective['seasonality'] ) && is_array( $effective['seasonality'] ) ) {
			$clean['seasonality'] = self::sanitize_meta_map( $effective['seasonality'] );
		}

		return $clean;
	}

	private static function profile_effective_signal( $value ) {
		if ( is_array( $value ) ) {
			$signal_value = sanitize_key( (string) ( $value['value'] ?? '' ) );

			if ( '' === $signal_value ) {
				return [];
			}

			return array_filter(
				[
					'value'            => $signal_value,
					'confidence'       => 1,
					'confidence_level' => 'confirmed',
					'source'           => sanitize_key( (string) ( $value['source'] ?? 'user_confirmed' ) ),
				],
				static fn( $item ) => null !== $item && '' !== $item && [] !== $item
			);
		}

		$signal_value = sanitize_key( is_scalar( $value ) ? (string) $value : '' );

		if ( '' === $signal_value ) {
			return [];
		}

		return [
			'value'            => $signal_value,
			'confidence'       => 1,
			'confidence_level' => 'confirmed',
			'source'           => 'user_confirmed',
		];
	}

	private static function sanitize_profile_key_context( $key_context ) {
		$key_context = is_array( $key_context ) ? $key_context : [];
		$clean       = [];

		if ( isset( $key_context['primary_product_groups'] ) ) {
			$clean['primary_product_groups'] = self::profile_text_list( $key_context['primary_product_groups'], 8 );
		}

		foreach ( [ 'operations_age_buckets', 'operations_default_thresholds', 'operations_confirmed_thresholds', 'operations_effective_thresholds', 'inventory_counts' ] as $key ) {
			$map = self::profile_number_map( $key_context[ $key ] ?? [], 12 );

			if ( ! empty( $map ) ) {
				$clean[ $key ] = $map;
			}
		}

		if ( ! empty( $key_context['reporting_defaults'] ) && is_array( $key_context['reporting_defaults'] ) ) {
			$clean['reporting_defaults'] = self::sanitize_meta_map( $key_context['reporting_defaults'] );
		}

		return array_filter( $clean );
	}

	private static function sanitize_profile_value( $value ) {
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_numeric( $value ) ) {
			return self::profile_number_or_null( $value );
		}

		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}

		if ( ! is_array( $value ) ) {
			return null;
		}

		$items = [];

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = is_numeric( $item ) ? self::profile_number_or_null( $item ) : sanitize_text_field( (string) $item );

			if ( null !== $item && '' !== $item ) {
				$items[] = $item;
			}
		}

		return array_slice( $items, 0, 12 );
	}

	private static function filter_confirmed_profile_gaps( $gaps, $effective ) {
		$effective = is_array( $effective ) ? $effective : [];
		$output    = [];

		foreach ( is_array( $gaps ) ? $gaps : [] as $gap ) {
			if ( ! is_array( $gap ) ) {
				continue;
			}

			$key = sanitize_key( $gap['key'] ?? '' );

			if ( 'primary_product_groups' === $key && ! empty( $effective['primary_product_groups'] ) ) {
				continue;
			}

			if ( 'fulfillment_model_confirmation' === $key && ! empty( $effective['fulfillment_model'] ) ) {
				continue;
			}

			if ( 'customer_model_confirmation' === $key && ! empty( $effective['customer_model'] ) ) {
				continue;
			}

			if ( 'sales_baseline' === $key && ! empty( $effective['reporting_defaults']['default_window'] ) ) {
				continue;
			}

			$output[] = $gap;
		}

		return array_values( $output );
	}

	private static function profile_sales_baselines( $sales_profile ) {
		$sales_profile = is_array( $sales_profile ) ? $sales_profile : [];
		$windows       = [];

		foreach ( is_array( $sales_profile['windows'] ?? null ) ? $sales_profile['windows'] : [] as $window => $metrics ) {
			if ( ! is_array( $metrics ) ) {
				continue;
			}

			$window = sanitize_key( (string) $window );

			if ( '' === $window ) {
				continue;
			}

			$windows[ $window ] = self::profile_number_map( $metrics, 20 );
		}

		return array_filter(
			[
				'currency' => sanitize_key( (string) ( $sales_profile['currency'] ?? '' ) ),
				'windows'  => array_filter( $windows ),
				'source'   => sanitize_key( (string) ( $sales_profile['source'] ?? '' ) ),
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);
	}

	private static function preferred_sales_baseline( $profile_context, $window = [] ) {
		$profile_context = is_array( $profile_context ) ? $profile_context : [];
		$current_key     = sanitize_key( $window['key'] ?? '' );
		$windows         = is_array( $profile_context['baselines']['windows'] ?? null ) ? $profile_context['baselines']['windows'] : [];

		foreach ( [ 'last_90_days', 'last_30_days', 'last_7_days' ] as $key ) {
			if ( $key === $current_key || empty( $windows[ $key ] ) || ! is_array( $windows[ $key ] ) ) {
				continue;
			}

			return array_merge(
				$windows[ $key ],
				[
					'key'   => $key,
					'label' => str_replace( '_', ' ', $key ),
				]
			);
		}

		return [];
	}

	private static function profile_gaps( $profiles, $domains ) {
		$profiles  = is_array( $profiles ) ? $profiles : [];
		$catalog   = isset( $profiles['catalog'] ) && is_array( $profiles['catalog'] ) ? $profiles['catalog'] : [];
		$commerce  = isset( $profiles['commerce'] ) && is_array( $profiles['commerce'] ) ? $profiles['commerce'] : [];
		$customers = isset( $profiles['customers'] ) && is_array( $profiles['customers'] ) ? $profiles['customers'] : [];
		$sales     = isset( $profiles['sales_baselines'] ) && is_array( $profiles['sales_baselines'] ) ? $profiles['sales_baselines'] : [];
		$gaps      = [];

		if ( self::profile_includes_domain( $domains, [ 'sales', 'reporting', 'inventory' ] ) && empty( $catalog['primary_product_groups'] ) ) {
			$gaps[] = self::profile_gap(
				'primary_product_groups',
				'catalog',
				__( 'Store product groups are not clear yet.', 'yoro-ai-core' ),
				__( 'What are the main product groups this store sells?', 'yoro-ai-core' )
			);
		}

		$fulfillment_confidence = self::profile_ratio( $commerce['fulfillment_model_confidence'] ?? 0 );
		$fulfillment_model      = sanitize_key( (string) ( $commerce['fulfillment_model_estimate'] ?? '' ) );

		if (
			self::profile_includes_domain( $domains, [ 'sales', 'reporting', 'operations', 'fulfillment', 'inventory' ] ) &&
			( 'unknown' === $fulfillment_model || ( $fulfillment_confidence > 0 && $fulfillment_confidence < 0.7 ) )
		) {
			$gaps[] = self::profile_gap(
				'fulfillment_model_confirmation',
				'commerce',
				__( 'The physical, digital, or subscription model is not confident enough.', 'yoro-ai-core' ),
				__( 'Does this store mainly sell physical products, digital products, subscriptions, or a mix?', 'yoro-ai-core' )
			);
		}

		$customer_confidence = self::profile_ratio( $customers['customer_model_confidence'] ?? 0 );
		$customer_model      = sanitize_key( (string) ( $customers['customer_model_estimate'] ?? '' ) );

		if (
			self::profile_includes_domain( $domains, [ 'sales', 'reporting', 'customers', 'customer_service' ] ) &&
			( 'unknown' === $customer_model || ( $customer_confidence > 0 && $customer_confidence < 0.7 ) )
		) {
			$gaps[] = self::profile_gap(
				'customer_model_confirmation',
				'customers',
				__( 'The B2C/B2B customer model is not confident enough.', 'yoro-ai-core' ),
				__( 'Is this store mainly B2C, B2B, or a mix?', 'yoro-ai-core' )
			);
		}

		if ( self::profile_includes_domain( $domains, [ 'sales', 'reporting' ] ) && empty( $sales['windows']['last_30_days']['average_order_value'] ) ) {
			$gaps[] = self::profile_gap(
				'sales_baseline',
				'sales',
				__( 'Sales baselines are not available yet.', 'yoro-ai-core' ),
				__( 'Should I use a default reporting window until there is enough sales history?', 'yoro-ai-core' )
			);
		}

		return array_slice( $gaps, 0, 5 );
	}

	private static function profile_gap( $key, $domain, $reason, $question ) {
		$gap = [
			'key'                => sanitize_key( $key ),
			'domain'             => sanitize_key( $domain ),
			'status'             => 'missing_or_low_confidence',
			'reason'             => sanitize_text_field( $reason ),
			'suggested_question' => sanitize_text_field( $question ),
		];

		$save_hint = self::profile_gap_save_hint( $key );

		if ( ! empty( $save_hint ) ) {
			$gap['save_hint'] = $save_hint;
		}

		return $gap;
	}

	private static function profile_gap_save_hint( $key ) {
		$key = sanitize_key( $key );

		$hints = [
			'primary_product_groups'        => [
				'action'         => 'store_preferences.save',
				'domain'         => 'catalog',
				'key'            => 'primary_product_groups',
				'value_type'     => 'array',
				'expected_value' => __( 'A short list of the store main product groups.', 'yoro-ai-core' ),
			],
			'fulfillment_model_confirmation' => [
				'action'         => 'store_preferences.save',
				'domain'         => 'commerce',
				'key'            => 'fulfillment_model',
				'value_type'     => 'string',
				'allowed_values' => [ 'physical_goods', 'digital_goods', 'subscription_possible', 'mixed_goods' ],
				'expected_value' => __( 'The store commerce or fulfillment model.', 'yoro-ai-core' ),
			],
			'customer_model_confirmation'  => [
				'action'         => 'store_preferences.save',
				'domain'         => 'customers',
				'key'            => 'customer_model',
				'value_type'     => 'string',
				'allowed_values' => [ 'likely_b2c', 'likely_b2b', 'mixed' ],
				'expected_value' => __( 'The store buyer model.', 'yoro-ai-core' ),
			],
			'sales_baseline'               => [
				'action'         => 'store_preferences.save',
				'domain'         => 'reporting',
				'key'            => 'default_window',
				'value_type'     => 'string',
				'expected_value' => __( 'The default reporting window to use when sales history is sparse.', 'yoro-ai-core' ),
			],
		];

		return isset( $hints[ $key ] ) ? self::sanitize_profile_save_hint( $hints[ $key ] ) : [];
	}

	private static function sanitize_profile_save_hint( $save_hint ) {
		if ( ! is_array( $save_hint ) ) {
			return [];
		}

		$hint = array_filter(
			[
				'action'         => sanitize_text_field( $save_hint['action'] ?? 'store_preferences.save' ),
				'domain'         => sanitize_key( $save_hint['domain'] ?? '' ),
				'key'            => sanitize_key( $save_hint['key'] ?? '' ),
				'value_type'     => sanitize_key( $save_hint['value_type'] ?? '' ),
				'expected_value' => sanitize_text_field( $save_hint['expected_value'] ?? '' ),
			],
			static fn( $value ) => null !== $value && '' !== $value && [] !== $value
		);

		if ( ! empty( $save_hint['allowed_values'] ) && is_array( $save_hint['allowed_values'] ) ) {
			$allowed = array_values(
				array_filter(
					array_map(
						static fn( $value ) => sanitize_key( is_scalar( $value ) ? (string) $value : '' ),
						$save_hint['allowed_values']
					)
				)
			);

			if ( ! empty( $allowed ) ) {
				$hint['allowed_values'] = array_slice( array_values( array_unique( $allowed ) ), 0, 12 );
			}
		}

		return $hint;
	}

	private static function profile_followups( $profile_context ) {
		$followups = [];

		foreach ( is_array( $profile_context['gaps'] ?? null ) ? $profile_context['gaps'] : [] as $gap ) {
			if ( ! is_array( $gap ) ) {
				continue;
			}

			$key      = sanitize_key( $gap['key'] ?? '' );
			$question = sanitize_text_field( $gap['suggested_question'] ?? '' );

			if ( '' === $key || '' === $question ) {
				continue;
			}

			$followup = array_filter(
				[
					'key'      => $key,
					'domain'   => sanitize_key( $gap['domain'] ?? '' ),
					'question' => $question,
					'reason'   => sanitize_text_field( $gap['reason'] ?? '' ),
				],
				static fn( $value ) => null !== $value && '' !== $value && [] !== $value
			);

			$save_hint = self::sanitize_profile_save_hint( $gap['save_hint'] ?? [] );

			if ( ! empty( $save_hint ) ) {
				$followup['save_hint'] = $save_hint;
			}

			$followups[] = $followup;
		}

		return array_slice( $followups, 0, 3 );
	}

	private static function important_profile_gaps( $profile_context, $keys ) {
		$profile_context = is_array( $profile_context ) ? $profile_context : [];
		$keys            = array_values( array_filter( array_map( 'sanitize_key', is_array( $keys ) ? $keys : [] ) ) );
		$gaps            = [];

		foreach ( is_array( $profile_context['gaps'] ?? null ) ? $profile_context['gaps'] : [] as $gap ) {
			if ( ! is_array( $gap ) || ! in_array( sanitize_key( $gap['key'] ?? '' ), $keys, true ) ) {
				continue;
			}

			$gaps[] = $gap;
		}

		return $gaps;
	}

	private static function profile_sources( $profiles ) {
		$sources = [];

		foreach ( is_array( $profiles ) ? $profiles : [] as $domain => $profile ) {
			if ( ! is_array( $profile ) || empty( $profile['source'] ) ) {
				continue;
			}

			$sources[ sanitize_key( (string) $domain ) ] = sanitize_key( (string) $profile['source'] );
		}

		return array_filter( $sources );
	}

	private static function profile_text_list( $items, $limit ) {
		$output = [];

		foreach ( is_array( $items ) ? $items : [] as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}

			$item = sanitize_text_field( (string) $item );

			if ( '' !== $item ) {
				$output[] = $item;
			}
		}

		return array_values( array_slice( array_unique( $output ), 0, absint( $limit ) ) );
	}

	private static function profile_number_map( $map, $limit ) {
		$output = [];

		foreach ( is_array( $map ) ? $map : [] as $key => $value ) {
			if ( ! is_scalar( $key ) || ! is_numeric( $value ) ) {
				continue;
			}

			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			$output[ $key ] = self::profile_number_or_null( $value );

			if ( count( $output ) >= absint( $limit ) ) {
				break;
			}
		}

		return array_filter( $output, static fn( $value ) => null !== $value );
	}

	private static function profile_number_or_null( $value ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = round( (float) $value, 3 );

		return floor( $number ) === $number ? (int) $number : $number;
	}

	private static function profile_ratio( $value ) {
		return round( max( 0, min( 1, is_numeric( $value ) ? (float) $value : 0 ) ), 3 );
	}

	private static function profile_confidence_level( $value ) {
		$value = self::profile_ratio( $value );

		if ( $value >= 0.8 ) {
			return 'high';
		}

		if ( $value >= 0.6 ) {
			return 'medium';
		}

		return $value > 0 ? 'low' : 'unknown';
	}

	private static function profile_includes_domain( $domains, $needles ) {
		$domains = array_values( array_filter( array_map( 'sanitize_key', is_array( $domains ) ? $domains : [] ) ) );
		$needles = array_values( array_filter( array_map( 'sanitize_key', is_array( $needles ) ? $needles : [] ) ) );

		return [] !== array_intersect( $domains, $needles );
	}

	private static function normalize_metric( $metric ) {
		if ( ! is_array( $metric ) ) {
			return null;
		}

		$key   = sanitize_key( $metric['key'] ?? '' );
		$label = sanitize_text_field( $metric['label'] ?? '' );

		if ( '' === $key || '' === $label ) {
			return null;
		}

		return self::metric(
			$key,
			$label,
			$metric['value'] ?? '',
			$metric['unit'] ?? '',
			$metric['format'] ?? 'number',
			$metric['severity'] ?? 'neutral',
			$metric['trend'] ?? '',
			$metric['delta'] ?? null,
			$metric['source'] ?? 'store',
			$metric['domain'] ?? '',
			$metric['description'] ?? '',
			$metric['priority'] ?? 50
		);
	}

	private static function build_metric_groups( $metrics ) {
		if ( empty( $metrics ) || ! is_array( $metrics ) ) {
			return [];
		}

		$groups = [];

		foreach ( $metrics as $metric ) {
			if ( ! is_array( $metric ) ) {
				continue;
			}

			$domain = self::normalize_metric_domain( $metric['domain'] ?? '', $metric['key'] ?? '' );

			if ( ! isset( $groups[ $domain ] ) ) {
				$groups[ $domain ] = self::metric_domain_definition( $domain );
			}

			$groups[ $domain ]['metric_count']++;
		}

		uasort(
			$groups,
			function( $a, $b ) {
				return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
			}
		);

		return array_values( $groups );
	}

	private static function normalize_metric_domain( $domain, $key = '' ) {
		$domain = sanitize_key( $domain );

		if ( '' === $domain ) {
			$domain = self::infer_metric_domain_from_key( $key );
		}

		$allowed = [ 'sales', 'orders', 'customers', 'products', 'operations', 'inventory', 'overview' ];

		return in_array( $domain, $allowed, true ) ? $domain : 'overview';
	}

	private static function infer_metric_domain_from_key( $key ) {
		$key = sanitize_key( $key );

		if ( preg_match( '/(?:sales|revenue|average_order_value|gross|net|completed_sales|refund|discount|tax|shipping)/', $key ) ) {
			return 'sales';
		}

		if ( preg_match( '/(?:order|orders)/', $key ) ) {
			return 'orders';
		}

		if ( preg_match( '/(?:customer|customers)/', $key ) ) {
			return 'customers';
		}

		if ( preg_match( '/(?:inventory|stock|backorder)/', $key ) ) {
			return 'inventory';
		}

		if ( preg_match( '/(?:backlog|attention|queue|pressure|fulfillment|pending|processing|on_hold)/', $key ) ) {
			return 'operations';
		}

		if ( preg_match( '/(?:product|products|item|items)/', $key ) ) {
			return 'products';
		}

		return 'overview';
	}

	private static function metric_domain_definition( $domain ) {
		$definitions = [
			'sales'      => [
				'label'       => __( 'Sales', 'yoro-ai-core' ),
				'description' => __( 'Revenue and order-value signals for the selected window.', 'yoro-ai-core' ),
				'priority'    => 10,
			],
			'orders'     => [
				'label'       => __( 'Orders', 'yoro-ai-core' ),
				'description' => __( 'Order volume and order-state movement.', 'yoro-ai-core' ),
				'priority'    => 20,
			],
			'customers'  => [
				'label'       => __( 'Customers', 'yoro-ai-core' ),
				'description' => __( 'Buyer activity and customer concentration.', 'yoro-ai-core' ),
				'priority'    => 30,
			],
			'products'   => [
				'label'       => __( 'Products', 'yoro-ai-core' ),
				'description' => __( 'Product demand and product-line activity.', 'yoro-ai-core' ),
				'priority'    => 40,
			],
			'operations' => [
				'label'       => __( 'Operations', 'yoro-ai-core' ),
				'description' => __( 'Backlog, workload, attention, and fulfillment pressure.', 'yoro-ai-core' ),
				'priority'    => 50,
			],
			'inventory'  => [
				'label'       => __( 'Inventory', 'yoro-ai-core' ),
				'description' => __( 'Stock pressure that can affect sales or fulfillment.', 'yoro-ai-core' ),
				'priority'    => 60,
			],
			'overview'   => [
				'label'       => __( 'Overview', 'yoro-ai-core' ),
				'description' => __( 'General store-health signals.', 'yoro-ai-core' ),
				'priority'    => 90,
			],
		];

		$definition = $definitions[ $domain ] ?? $definitions['overview'];

		return [
			'domain'       => $domain,
			'label'        => sanitize_text_field( $definition['label'] ),
			'description'  => sanitize_text_field( $definition['description'] ),
			'priority'     => absint( $definition['priority'] ),
			'metric_count' => 0,
		];
	}

	private static function normalize_insight( $insight ) {
		if ( ! is_array( $insight ) ) {
			return null;
		}

		$title   = sanitize_text_field( $insight['title'] ?? '' );
		$message = sanitize_text_field( $insight['message'] ?? '' );

		if ( '' === $title && '' === $message ) {
			return null;
		}

		return self::insight(
			$title,
			$message,
			$insight['topic'] ?? 'overview',
			$insight['severity'] ?? 'neutral',
			$insight['domain'] ?? '',
			$insight['priority'] ?? self::default_priority_for_severity( $insight['severity'] ?? 'neutral' ),
			$insight['kind'] ?? 'insight'
		);
	}

	private static function normalize_alert( $alert ) {
		if ( ! is_array( $alert ) ) {
			return null;
		}

		return self::alert(
			$alert['title'] ?? '',
			$alert['message'] ?? '',
			$alert['severity'] ?? 'watch',
			$alert['topic'] ?? 'risk',
			$alert['domain'] ?? '',
			$alert['priority'] ?? self::default_priority_for_severity( $alert['severity'] ?? 'watch' )
		);
	}

	private static function normalize_action( $action ) {
		if ( ! is_array( $action ) ) {
			return null;
		}

		$label   = sanitize_text_field( $action['label'] ?? '' );
		$command = sanitize_text_field( $action['command'] ?? '' );

		if ( '' === $label || '' === $command ) {
			return null;
		}

		return self::action(
			$label,
			$command,
			$action['kind'] ?? 'view',
			$action['domain'] ?? $action['topic'] ?? '',
			$action['priority'] ?? 50,
			$action['reason'] ?? ''
		);
	}

	private static function normalize_breakdown( $breakdown ) {
		if ( ! is_array( $breakdown ) ) {
			return null;
		}

		return self::breakdown(
			$breakdown['key'] ?? '',
			$breakdown['label'] ?? '',
			$breakdown['items'] ?? [],
			$breakdown['domain'] ?? '',
			$breakdown['priority'] ?? 50,
			$breakdown['description'] ?? ''
		);
	}

	private static function normalize_breakdown_item( $item ) {
		if ( ! is_array( $item ) ) {
			return null;
		}

		$label = sanitize_text_field( $item['label'] ?? $item['name'] ?? '' );

		if ( '' === $label ) {
			return null;
		}

		return self::breakdown_item(
			$label,
			$item['value'] ?? $item['quantity'] ?? '',
			$item['unit'] ?? '',
			$item['subvalue'] ?? '',
			$item['share'] ?? '',
			$item['meta'] ?? []
		);
	}

	private static function sanitize_meta_map( $meta ) {
		$clean = [];

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( $key );

			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = array_values(
					array_filter(
						array_map(
							function( $item ) {
								return is_scalar( $item ) ? sanitize_text_field( $item ) : '';
							},
							$value
						),
						function( $item ) {
							return '' !== $item;
						}
					)
				);
				continue;
			}

			if ( is_scalar( $value ) ) {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}

		return $clean;
	}

	private static function sort_report_items( $items ) {
		usort(
			$items,
			function( $a, $b ) {
				$priority_compare = ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );

				if ( 0 !== $priority_compare ) {
					return $priority_compare;
				}

				return self::severity_weight( $a['severity'] ?? 'neutral' ) <=> self::severity_weight( $b['severity'] ?? 'neutral' );
			}
		);

		return $items;
	}

	private static function sort_report_sections( $items ) {
		usort(
			$items,
			function( $a, $b ) {
				return ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 );
			}
		);

		return $items;
	}

	private static function default_priority_for_severity( $severity ) {
		switch ( self::normalize_severity( $severity ) ) {
			case 'risk':
				return 10;
			case 'watch':
				return 20;
			case 'positive':
				return 40;
			default:
				return 50;
		}
	}

	private static function severity_weight( $severity ) {
		switch ( self::normalize_severity( $severity ) ) {
			case 'risk':
				return 10;
			case 'watch':
				return 20;
			case 'positive':
				return 30;
			default:
				return 40;
		}
	}

	private static function normalize_report_domain( $domain, $topic = '', $text = '' ) {
		$domain = sanitize_key( $domain );
		$allowed = [ 'sales', 'orders', 'customers', 'products', 'operations', 'inventory', 'overview' ];

		return in_array( $domain, $allowed, true ) ? $domain : 'overview';
	}

	private static function normalize_health( $health ) {
		$health = sanitize_key( $health );

		return in_array( $health, [ 'good', 'watch', 'risk' ], true ) ? $health : 'watch';
	}

	private static function normalize_severity( $severity ) {
		$severity = sanitize_key( $severity );

		if ( 'warning' === $severity ) {
			return 'risk';
		}

		return in_array( $severity, [ 'positive', 'neutral', 'watch', 'risk' ], true ) ? $severity : 'neutral';
	}
}
