<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Action_Composition_Planner {

	const COMPOSITION_SOURCE_GUARD = 'guard_action_composition';
	const PARSER_RULE_GUARD        = 'guard.action_composition';

	public static function maybe_build_parsed( $command, $normalized, $client_context = [], $hint = [] ) {
		if ( ! class_exists( 'YoRo_Action_Intent_Extractor' ) ) {
			return null;
		}

		$command        = is_string( $command ) ? trim( $command ) : '';
		$normalized     = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';
		$client_context = is_array( $client_context ) ? $client_context : [];
		$hint           = is_array( $hint ) ? $hint : [];
		$extracted      = self::resolve_composition_source( $command, $normalized, $client_context, $hint );

		if ( empty( $extracted['matched'] ) || empty( $extracted['should_compose'] ) ) {
			return null;
		}

		$order_id    = absint( $extracted['order_id'] ?? 0 );
		$requested   = isset( $extracted['requested_actions'] ) && is_array( $extracted['requested_actions'] ) ? $extracted['requested_actions'] : [];
		$unavailable = isset( $extracted['unavailable_requests'] ) && is_array( $extracted['unavailable_requests'] ) ? $extracted['unavailable_requests'] : [];
		$missing     = isset( $extracted['missing_params'] ) && is_array( $extracted['missing_params'] ) ? array_values( array_filter( array_map( 'sanitize_key', $extracted['missing_params'] ) ) ) : [];

		if (
			in_array( 'note', $missing, true ) &&
			$order_id > 0 &&
			! self::requested_actions_contain_concrete_note( $requested )
		) {
			return self::build_missing_note_clarification( $command, $normalized, $order_id, $requested, $unavailable, $extracted );
		}

		return self::finalize_composed_result(
			$command,
			$normalized,
			$order_id,
			$requested,
			$unavailable,
			$extracted,
			$hint
		);
	}

	private static function finalize_composed_result( $command, $normalized, $order_id, $requested, $unavailable, $extracted, $hint ) {
		$email_clarification = self::build_invalid_email_instruction_clarification(
			$command,
			$normalized,
			$order_id,
			$requested,
			$unavailable,
			$extracted
		);

		if ( is_array( $email_clarification ) ) {
			return $email_clarification;
		}

		return self::build_composed_parsed( $command, $normalized, $order_id, $requested, $unavailable, $extracted, $hint );
	}

	public static function resume_from_missing_instruction( $workflow, $command, $normalized ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$params   = isset( $workflow['params'] ) && is_array( $workflow['params'] ) ? $workflow['params'] : [];
		$order_id = absint( $workflow['order_id'] ?? ( $params['order_id'] ?? 0 ) );

		if ( $order_id <= 0 && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_id = absint( reset( $params['order_ids'] ) );
		}

		$requested = isset( $params['_yoro_composed_requested_actions'] ) && is_array( $params['_yoro_composed_requested_actions'] )
			? $params['_yoro_composed_requested_actions']
			: [];
		$unavailable = isset( $params['_yoro_composed_unavailable_requests'] ) && is_array( $params['_yoro_composed_unavailable_requests'] )
			? $params['_yoro_composed_unavailable_requests']
			: [];
		$instruction = sanitize_textarea_field( $params['instruction'] ?? '' );

		if ( $order_id <= 0 || '' === $instruction || empty( $requested ) ) {
			return null;
		}

		$requested = self::replace_requested_email_instruction( $requested, $instruction );

		return self::finalize_composed_result(
			sanitize_textarea_field( $params['_yoro_composed_original_command'] ?? $command ),
			is_string( $normalized ) ? $normalized : strtolower( trim( (string) $command ) ),
			$order_id,
			$requested,
			$unavailable,
			[
				'parser_rule'        => sanitize_text_field( $params['_yoro_composed_parser_rule'] ?? self::PARSER_RULE_GUARD ),
				'confidence'         => (float) ( $params['_yoro_composed_confidence'] ?? 0.85 ),
				'composition_source' => sanitize_key(
					$params['_yoro_composed_source'] ??
					self::default_composition_source_for_parser_rule( $params['_yoro_composed_parser_rule'] ?? self::PARSER_RULE_GUARD )
				),
				'parser_source'      => sanitize_text_field( $params['_yoro_composed_parser_source'] ?? 'guard' ),
			],
			[]
		);
	}

	public static function resume_from_missing_note( $workflow, $command, $normalized ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$params   = isset( $workflow['params'] ) && is_array( $workflow['params'] ) ? $workflow['params'] : [];
		$order_id = absint( $workflow['order_id'] ?? ( $params['order_id'] ?? 0 ) );

		if ( $order_id <= 0 && ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) ) {
			$order_id = absint( reset( $params['order_ids'] ) );
		}

		$requested = isset( $params['_yoro_composed_requested_actions'] ) && is_array( $params['_yoro_composed_requested_actions'] )
			? $params['_yoro_composed_requested_actions']
			: [];
		$unavailable = isset( $params['_yoro_composed_unavailable_requests'] ) && is_array( $params['_yoro_composed_unavailable_requests'] )
			? $params['_yoro_composed_unavailable_requests']
			: [];
		$note = sanitize_textarea_field( $params['note'] ?? '' );

		if ( $order_id <= 0 || '' === $note || empty( $requested ) ) {
			return null;
		}

		$requested = self::inject_missing_note_action( $requested, $note );

		return self::finalize_composed_result(
			sanitize_textarea_field( $params['_yoro_composed_original_command'] ?? $command ),
			is_string( $normalized ) ? $normalized : strtolower( trim( (string) $command ) ),
			$order_id,
			$requested,
			$unavailable,
			[
				'parser_rule'        => sanitize_text_field( $params['_yoro_composed_parser_rule'] ?? self::PARSER_RULE_GUARD ),
				'confidence'         => (float) ( $params['_yoro_composed_confidence'] ?? 0.85 ),
				'composition_source' => sanitize_key(
					$params['_yoro_composed_source'] ??
					self::default_composition_source_for_parser_rule( $params['_yoro_composed_parser_rule'] ?? self::PARSER_RULE_GUARD )
				),
				'parser_source'      => sanitize_text_field( $params['_yoro_composed_parser_source'] ?? 'guard' ),
			],
			[]
		);
	}

	private static function build_composed_parsed( $command, $normalized, $order_id, $requested, $unavailable, $extracted, $hint ) {
		$steps = [];

		if ( $order_id > 0 && self::should_load_order_first( $requested ) ) {
			$steps[] = [
				'id'     => 'load_order',
				'label'  => __( 'Load order details', 'yoro-ai-core' ),
				'action' => 'orders.get_details',
				'params' => [
					'order_ids' => [ $order_id ],
				],
			];
		}

		foreach ( $requested as $item ) {
			$item_kind = sanitize_key( $item['kind'] ?? self::infer_kind_from_action( $item['action'] ?? '' ) );
			$item_params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			switch ( $item_kind ) {
				case 'hold':
					$steps[] = [
						'id'     => 'hold_order',
						'label'  => __( 'Put order on hold', 'yoro-ai-core' ),
						'action' => 'orders.hold',
						'params' => [
							'order_ids' => [ $order_id ],
						],
					];
					break;

				case 'add_note':
					$steps[] = [
						'id'     => 'add_note',
						'label'  => __( 'Add internal note', 'yoro-ai-core' ),
						'action' => 'orders.add_note',
						'params' => [
							'order_ids' => [ $order_id ],
							'note'      => sanitize_textarea_field( $item_params['note'] ?? '' ),
						],
					];
					break;

				case 'update_status':
					$steps[] = [
						'id'     => 'update_status',
						'label'  => __( 'Update order status', 'yoro-ai-core' ),
						'action' => 'orders.update_status',
						'params' => [
							'order_ids' => [ $order_id ],
							'status'    => sanitize_key( $item_params['status'] ?? '' ),
						],
					];
					break;

				case 'status_with_note':
					$steps[] = [
						'id'     => 'update_status_with_note',
						'label'  => __( 'Update order status with note', 'yoro-ai-core' ),
						'action' => 'orders.bulk_update_status_with_note',
						'params' => [
							'order_ids' => [ $order_id ],
							'status'    => sanitize_key( $item_params['status'] ?? '' ),
							'note'      => sanitize_textarea_field( $item_params['note'] ?? '' ),
						],
					];
					break;

				case 'write_email':
					$instruction = sanitize_textarea_field( $item_params['instruction'] ?? '' );
					$delivery    = sanitize_key( $item_params['delivery_mode'] ?? 'preview' );

					$steps[] = [
						'id'      => 'compose_email',
						'label'   => __( 'Compose customer email', 'yoro-ai-core' ),
						'action'  => 'orders.compose_customer_email',
						'params'  => [
							'order_id'    => $order_id,
							'instruction' => $instruction,
						],
						'capture' => [
							'email_subject' => 'result.email.subject',
							'email_body'    => 'result.email.body',
						],
					];

					if ( 'save_draft' === $delivery ) {
						$steps[] = [
							'id'     => 'save_draft',
							'label'  => __( 'Save email draft', 'yoro-ai-core' ),
							'action' => 'orders.save_customer_email_draft',
							'params' => [
								'order_id' => $order_id,
								'subject'  => '{{context.email_subject}}',
								'body'     => '{{context.email_body}}',
							],
						];
					} elseif ( 'send' === $delivery ) {
						$steps[] = [
							'id'     => 'send_email',
							'label'  => __( 'Send customer email', 'yoro-ai-core' ),
							'action' => 'orders.send_customer_email',
							'params' => [
								'order_id' => $order_id,
								'subject'  => '{{context.email_subject}}',
								'body'     => '{{context.email_body}}',
							],
						];
					}
					break;
			}
		}

		$steps = array_values(
			array_filter(
				$steps,
				static function( $step ) {
					return ! empty( $step['action'] );
				}
			)
		);

		if ( empty( $steps ) ) {
			return null;
		}

		$params = [
			'order_id'  => $order_id,
			'order_ids' => $order_id > 0 ? [ $order_id ] : [],
		];

		$artifacts = [
			'composition' => [
				'requested_actions'    => self::compact_requested_actions( $requested ),
				'unavailable_requests' => self::compact_unavailable_requests( $unavailable ),
				'partial_support'      => ! empty( $unavailable ),
			],
		];

		$current_workflow = class_exists( 'YoRo_Workflow_Runner' )
			? YoRo_Workflow_Runner::build_sequence_workflow(
				'composed_actions',
				$steps,
				$params,
				$command,
				[
					'label'       => self::build_workflow_label( $requested, $unavailable ),
					'description' => __( 'YoRo assembled this workflow from the actions it recognized in your request.', 'yoro-ai-core' ),
					'artifacts'   => $artifacts,
				]
			)
			: [];

		return [
			'success'           => true,
			'matched'           => true,
			'action'            => 'workflow.composed_actions',
			'workflow_template' => 'composed_actions',
			'workflow_label'    => self::build_workflow_label( $requested, $unavailable ),
			'workflow'          => [
				'id'            => 'composed_actions',
				'definition_id' => 'composed_actions',
				'label'         => self::build_workflow_label( $requested, $unavailable ),
				'description'   => __( 'YoRo assembled this workflow from the actions it recognized in your request.', 'yoro-ai-core' ),
				'category'      => 'workflow',
				'risk_level'    => 'medium',
				'artifacts'     => $artifacts,
			],
			'params'            => $params,
			'actions'           => $steps,
			'current_workflow'  => $current_workflow,
			'composition'       => $artifacts['composition'],
			'composition_source'=> sanitize_key( $extracted['composition_source'] ?? self::COMPOSITION_SOURCE_GUARD ),
			'parser_source'     => sanitize_text_field( $extracted['parser_source'] ?? ( $hint['parser_source'] ?? 'guard' ) ),
			'parser_rule'       => sanitize_text_field( $extracted['parser_rule'] ?? self::PARSER_RULE_GUARD ),
			'confidence'        => max( 0.85, (float) ( $extracted['confidence'] ?? $hint['confidence'] ?? 0 ) ),
			'original_command'  => $command,
			'normalized_command'=> $normalized,
		];
	}

	private static function resolve_composition_source( $command, $normalized, $client_context, $hint ) {
		$hint = is_array( $hint ) ? $hint : [];
		$rule_result = YoRo_Action_Intent_Extractor::extract( $command, $normalized, $client_context, $hint );
		$ai_result = (
			self::should_attempt_ai_decomposition( $command, $normalized, $rule_result, $hint ) &&
			class_exists( 'YoRo_AI_Action_Decomposer' )
		)
			? YoRo_AI_Action_Decomposer::decompose( $command, $client_context )
			: null;

		if ( self::is_usable_ai_decomposition( $ai_result ) ) {
			$merged_missing = self::merge_missing_params(
				$ai_result['missing_params'] ?? [],
				$rule_result['missing_params'] ?? []
			);
			$merged_requested = self::merge_requested_actions(
				self::normalize_ai_requested_actions( $ai_result['requested_actions'] ?? [] ),
				$rule_result['requested_actions'] ?? [],
				false
			);
			$merged_unsupported = self::merge_unavailable_requests(
				self::filter_spurious_ai_unsupported_requests(
					self::normalize_ai_unsupported_requests( $ai_result['unsupported_requests'] ?? [] ),
					$rule_result['unavailable_requests'] ?? []
				),
				$rule_result['unavailable_requests'] ?? []
			);
			$merged_missing = self::filter_missing_params_from_requested_actions( $merged_missing, $merged_requested, $command );
			$note_repair = self::repair_requested_note_params(
				$merged_requested,
				$merged_missing,
				$command
			);
			$merged_requested = $note_repair['requested'];
			$merged_missing   = $note_repair['missing'];
			$merged_requested = self::collapse_conflicting_requested_actions(
				self::sanitize_requested_actions_for_missing_params(
					$merged_requested,
					$merged_missing
				),
				$merged_missing
			);

			return [
				'matched'              => true,
				'should_compose'       => self::should_compose_from_requested_actions(
					$merged_requested,
					$merged_unsupported,
					$merged_missing
				),
				'requested_actions'    => $merged_requested,
				'unavailable_requests' => $merged_unsupported,
				'missing_params'       => $merged_missing,
				'order_id'             => self::resolve_order_id_from_ai_actions(
					$command,
					$client_context,
					$hint,
					$merged_requested
				),
				'confidence'           => (float) ( $ai_result['confidence'] ?? 0 ),
				'parser_rule'          => 'ai.action_decomposition',
				'composition_source'   => 'ai_decomposition',
				'parser_source'        => sanitize_text_field( $hint['parser_source'] ?? 'guard' ),
			];
		}

		if ( is_array( $rule_result ) && ! empty( $rule_result['matched'] ) ) {
			$rule_result['composition_source'] = self::COMPOSITION_SOURCE_GUARD;
			$rule_result['parser_rule']        = self::PARSER_RULE_GUARD;
			$rule_result['parser_source']      = sanitize_text_field( $hint['parser_source'] ?? 'guard' );
		}

		return $rule_result;
	}

	private static function should_attempt_ai_decomposition( $command, $normalized, $rule_result, $hint ) {
		$hint = is_array( $hint ) ? $hint : [];
		$rule_result = is_array( $rule_result ) ? $rule_result : [];
		$hint_action = sanitize_text_field( $hint['action'] ?? '' );
		$requested = isset( $rule_result['requested_actions'] ) && is_array( $rule_result['requested_actions'] ) ? $rule_result['requested_actions'] : [];
		$unsupported = isset( $rule_result['unavailable_requests'] ) && is_array( $rule_result['unavailable_requests'] ) ? $rule_result['unavailable_requests'] : [];
		$missing = isset( $rule_result['missing_params'] ) && is_array( $rule_result['missing_params'] ) ? $rule_result['missing_params'] : [];

		if ( self::should_compose_from_requested_actions( $requested, $unsupported, $missing ) ) {
			return true;
		}

		$has_multi_step_cues = self::command_has_composition_cues( $command, $normalized );

		if ( '' !== $hint_action ) {
			return $has_multi_step_cues && self::is_composition_eligible_action( $hint_action );
		}

		return $has_multi_step_cues;
	}

	private static function composition_parser_source( $extracted ) {
		$extracted = is_array( $extracted ) ? $extracted : [];
		$parser_source = sanitize_text_field( $extracted['parser_source'] ?? '' );

		return '' !== $parser_source ? $parser_source : 'guard';
	}

	private static function default_composition_source_for_parser_rule( $parser_rule ) {
		$parser_rule = sanitize_text_field( (string) $parser_rule );

		if ( 0 === strpos( $parser_rule, 'ai.action_decomposition' ) ) {
			return 'ai_decomposition';
		}

		return self::COMPOSITION_SOURCE_GUARD;
	}

	private static function is_composition_eligible_action( $action ) {
		$action = sanitize_text_field( $action );
		$eligible_actions = [
			'orders.hold',
			'orders.add_note',
			'orders.update_status',
			'orders.bulk_update_status_with_note',
			'orders.compose_customer_email',
		];

		return '' !== $action && in_array( $action, $eligible_actions, true );
	}

	private static function command_has_composition_cues( $command, $normalized ) {
		$command = is_string( $command ) ? trim( $command ) : '';
		$normalized = is_string( $normalized ) ? strtolower( trim( $normalized ) ) : '';
		$haystack = '' !== $normalized ? $normalized : strtolower( $command );

		if ( '' === $haystack ) {
			return false;
		}

		if ( preg_match( '/\b(?:and then|then|and also|also|plus)\b/u', $haystack ) ) {
			return true;
		}

		if ( preg_match( '/[,;]\s*(?:then|and|also)\b/u', $haystack ) ) {
			return true;
		}

		return false;
	}

	private static function is_usable_ai_decomposition( $ai_result ) {
		if ( ! is_array( $ai_result ) || empty( $ai_result['matched'] ) ) {
			return false;
		}

		$requested = isset( $ai_result['requested_actions'] ) && is_array( $ai_result['requested_actions'] ) ? $ai_result['requested_actions'] : [];
		$unsupported = isset( $ai_result['unsupported_requests'] ) && is_array( $ai_result['unsupported_requests'] ) ? $ai_result['unsupported_requests'] : [];

		return ! empty( $requested ) || ! empty( $unsupported );
	}

	private static function should_compose_from_requested_actions( $requested, $unsupported, $missing = [] ) {
		$requested_count = is_array( $requested ) ? count( $requested ) : 0;
		$unsupported_count = is_array( $unsupported ) ? count( $unsupported ) : 0;
		$missing = is_array( $missing ) ? $missing : [];

		if ( in_array( 'note', $missing, true ) && $requested_count >= 2 ) {
			return true;
		}

		return $requested_count >= 2 || ( $requested_count >= 1 && $unsupported_count > 0 );
	}

	private static function normalize_ai_requested_actions( $items ) {
		$items = is_array( $items ) ? $items : [];
		$normalized = [];

		foreach ( $items as $index => $item ) {
			$action = sanitize_text_field( $item['action'] ?? '' );

			if ( '' === $action ) {
				continue;
			}

			$normalized[] = [
				'kind'     => self::infer_kind_from_action( $action ),
				'action'   => $action,
				'label'    => sanitize_text_field( $item['label'] ?? $action ),
				'position' => $index,
				'params'   => isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [],
			];
		}

		return $normalized;
	}

	private static function merge_requested_actions( $primary, $fallback, $allow_new_fallback_actions = true ) {
		$primary = is_array( $primary ) ? array_values( $primary ) : [];
		$fallback = is_array( $fallback ) ? array_values( $fallback ) : [];

		foreach ( $fallback as $fallback_item ) {
			$fallback_kind = sanitize_key( $fallback_item['kind'] ?? '' );

			if ( 'status_with_note' === $fallback_kind ) {
				foreach ( $primary as $index => $primary_item ) {
					if ( 'update_status' === sanitize_key( $primary_item['kind'] ?? '' ) ) {
						$primary[ $index ] = self::merge_requested_action_item( $fallback_item, $primary_item );
						continue 2;
					}
				}
			}

			$exists = false;

			foreach ( $primary as $index => $primary_item ) {
				if ( sanitize_key( $primary_item['kind'] ?? '' ) === $fallback_kind ) {
					$primary[ $index ] = self::merge_requested_action_item( $primary_item, $fallback_item );
					$exists = true;
					break;
				}
			}

			if ( ! $exists && $allow_new_fallback_actions ) {
				$primary[] = $fallback_item;
			}
		}

		usort(
			$primary,
			static function( $left, $right ) {
				return (int) ( $left['position'] ?? 0 ) <=> (int) ( $right['position'] ?? 0 );
			}
		);

		return $primary;
	}

	private static function sanitize_requested_actions_for_missing_params( $requested, $missing ) {
		$requested = is_array( $requested ) ? array_values( $requested ) : [];
		$missing = is_array( $missing ) ? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) ) : [];

		if ( ! in_array( 'note', $missing, true ) ) {
			return $requested;
		}

		foreach ( $requested as $index => $item ) {
			$kind = sanitize_key( $item['kind'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			if ( 'status_with_note' === $kind ) {
				$requested[ $index ] = [
					'kind'     => 'update_status',
					'action'   => 'orders.update_status',
					'label'    => __( 'Update order status', 'yoro-ai-core' ),
					'position' => $item['position'] ?? $index,
					'params'   => [
						'status' => sanitize_key( $params['status'] ?? '' ),
					],
				];
				continue;
			}

			if ( 'add_note' === $kind && empty( $params['note'] ) ) {
				unset( $requested[ $index ] );
			}
		}

		return array_values( $requested );
	}

	private static function repair_requested_note_params( $requested, $missing, $command ) {
		$requested = is_array( $requested ) ? array_values( $requested ) : [];
		$missing   = is_array( $missing ) ? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) ) : [];
		$note      = self::infer_note_from_command_context( $command, $requested );
		$needs_note = false;

		foreach ( $requested as $index => $item ) {
			$kind   = sanitize_key( $item['kind'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			if ( ! in_array( $kind, [ 'add_note', 'status_with_note' ], true ) ) {
				continue;
			}

			$current_note = sanitize_textarea_field( $params['note'] ?? '' );

			if ( '' !== $current_note && ! self::is_placeholder_note_text( $current_note, $params['status'] ?? '' ) ) {
				continue;
			}

			if ( '' !== $note ) {
				$params['note'] = $note;
				$requested[ $index ]['params'] = $params;
				continue;
			}

			$needs_note = true;
		}

		if ( $needs_note && ! in_array( 'note', $missing, true ) ) {
			$missing[] = 'note';
		}

		if ( ! $needs_note && '' !== $note ) {
			$missing = array_values( array_diff( $missing, [ 'note' ] ) );
		}

		return [
			'requested' => $requested,
			'missing'   => array_values( array_unique( $missing ) ),
		];
	}

	private static function collapse_conflicting_requested_actions( $requested, $missing = [] ) {
		$requested = is_array( $requested ) ? array_values( $requested ) : [];
		$missing = is_array( $missing ) ? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) ) : [];
		$has_status_with_note = false;
		$collapsed = [];
		$seen_keys = [];

		foreach ( $requested as $item ) {
			if ( 'status_with_note' === sanitize_key( $item['kind'] ?? '' ) ) {
				$has_status_with_note = true;
				break;
			}
		}

		foreach ( $requested as $item ) {
			$kind = sanitize_key( $item['kind'] ?? '' );

			if ( $has_status_with_note && ! in_array( 'note', $missing, true ) && 'update_status' === $kind ) {
				continue;
			}

			$signature = $kind;

			if ( 'update_status' === $kind ) {
				$status = sanitize_key( $item['params']['status'] ?? '' );
				$signature .= '|' . $status;
			}

			if ( isset( $seen_keys[ $signature ] ) ) {
				continue;
			}

			$seen_keys[ $signature ] = true;

			$collapsed[] = $item;
		}

		return array_values( $collapsed );
	}

	private static function merge_requested_action_item( $primary_item, $fallback_item ) {
		$primary_item = is_array( $primary_item ) ? $primary_item : [];
		$fallback_item = is_array( $fallback_item ) ? $fallback_item : [];

		$primary_params = isset( $primary_item['params'] ) && is_array( $primary_item['params'] ) ? $primary_item['params'] : [];
		$fallback_params = isset( $fallback_item['params'] ) && is_array( $fallback_item['params'] ) ? $fallback_item['params'] : [];

		foreach ( $fallback_params as $key => $value ) {
			if ( self::is_missing_merge_value( $primary_params[ $key ] ?? null ) ) {
				$primary_params[ $key ] = $value;
				continue;
			}

			if (
				is_array( $value ) &&
				isset( $primary_params[ $key ] ) &&
				is_array( $primary_params[ $key ] )
			) {
				$primary_params[ $key ] = array_replace_recursive( $value, $primary_params[ $key ] );
			}
		}

		$primary_item['params'] = $primary_params;

		if ( self::is_missing_merge_value( $primary_item['label'] ?? '' ) && ! empty( $fallback_item['label'] ) ) {
			$primary_item['label'] = $fallback_item['label'];
		}

		if ( self::is_missing_merge_value( $primary_item['position'] ?? null ) && isset( $fallback_item['position'] ) ) {
			$primary_item['position'] = $fallback_item['position'];
		}

		return $primary_item;
	}

	private static function is_missing_merge_value( $value ) {
		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		if ( is_array( $value ) ) {
			return [] === $value;
		}

		return false;
	}

	private static function normalize_ai_unsupported_requests( $items ) {
		$items = is_array( $items ) ? $items : [];
		$normalized = [];

		foreach ( $items as $item ) {
			$normalized[] = [
				'key'     => sanitize_key( $item['key'] ?? '' ),
				'label'   => sanitize_text_field( $item['label'] ?? '' ),
				'message' => sanitize_text_field( $item['reason'] ?? __( 'Not available yet in YoRo.', 'yoro-ai-core' ) ),
			];
		}

		return array_values( array_filter( $normalized, static function( $item ) {
			return ! empty( $item['key'] );
		} ) );
	}

	private static function filter_spurious_ai_unsupported_requests( $primary, $fallback ) {
		$primary = is_array( $primary ) ? array_values( $primary ) : [];
		$fallback_keys = [];

		foreach ( (array) $fallback as $item ) {
			$key = sanitize_key( $item['key'] ?? '' );

			if ( '' !== $key ) {
				$fallback_keys[ $key ] = true;
			}
		}

		return array_values(
			array_filter(
				$primary,
				static function( $item ) use ( $fallback_keys ) {
					$key = sanitize_key( $item['key'] ?? '' );
					$message = strtolower( sanitize_text_field( $item['message'] ?? '' ) );

					if ( isset( $fallback_keys[ $key ] ) ) {
						return true;
					}

					if ( false !== strpos( $message, 'not requested' ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	private static function merge_missing_params( $primary, $fallback ) {
		$primary = is_array( $primary ) ? $primary : [];
		$fallback = is_array( $fallback ) ? $fallback : [];

		return array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						array_merge( $primary, $fallback )
					)
				)
			)
		);
	}

	private static function filter_missing_params_from_requested_actions( $missing, $requested, $command = '' ) {
		$missing = is_array( $missing ) ? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) ) : [];
		$requested = is_array( $requested ) ? $requested : [];
		$command = (string) $command;

		if ( ! in_array( 'note', $missing, true ) ) {
			return $missing;
		}

		if ( self::command_contains_explicit_note_content( $command ) ) {
			return array_values( array_diff( $missing, [ 'note' ] ) );
		}

		foreach ( $requested as $item ) {
			$kind = sanitize_key( $item['kind'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];
			$note = sanitize_textarea_field( $params['note'] ?? '' );
			$status = sanitize_key( $params['status'] ?? '' );

			if ( in_array( $kind, [ 'add_note', 'status_with_note' ], true ) && '' !== $note && ! self::is_placeholder_note_text( $note, $status ) ) {
				return array_values( array_diff( $missing, [ 'note' ] ) );
			}
		}

		return $missing;
	}

	private static function command_contains_explicit_note_content( $command ) {
		return '' !== self::infer_note_from_command_context( $command, [] );
	}

	private static function infer_note_from_command_context( $command, $requested ) {
		$texts = [ (string) $command ];
		$requested = is_array( $requested ) ? $requested : [];

		foreach ( $requested as $item ) {
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			foreach ( [ 'note', 'instruction' ] as $key ) {
				if ( ! empty( $params[ $key ] ) && is_string( $params[ $key ] ) ) {
					$texts[] = $params[ $key ];
				}
			}
		}

		foreach ( $texts as $text ) {
			$note = self::extract_note_topic_from_text( $text );

			if ( '' !== $note ) {
				return $note;
			}
		}

		return '';
	}

	private static function extract_note_topic_from_text( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );

		if ( '' === $text ) {
			return '';
		}

		if ( preg_match( '/\b(?:about|regarding|re:)\s+([^,.;!?\\n]+)/i', $text, $matches ) ) {
			return self::clean_inferred_note_topic( $matches[1] );
		}

		if ( preg_match( '/\b(payment\s+confirmation|payment\s+confirmed|payment\s+received|address\s+confirmation|refund\s+follow[- ]?up|shipping\s+delay)\b/i', $text, $matches ) ) {
			return self::clean_inferred_note_topic( $matches[1] );
		}

		return '';
	}

	private static function clean_inferred_note_topic( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/\s+(?:and|then|also)\s+(?:write|draft|compose|prepare|create|save|send)\b.*$/i', '', $text );
		$text = preg_replace( '/\s+to\s+the\s+customer\b.*$/i', '', (string) $text );
		$text = trim( preg_replace( '/[\s,;:.-]+$/', '', (string) $text ) );

		if ( '' === $text || preg_match( '/^(?:it|this|that|the order)$/i', $text ) ) {
			return '';
		}

		$text = substr( $text, 0, 180 );

		return ucfirst( sanitize_textarea_field( $text ) );
	}

	private static function is_placeholder_note_text( $note, $status = '' ) {
		$note = strtolower( trim( sanitize_textarea_field( $note ) ) );
		$status = strtolower( trim( sanitize_key( $status ) ) );

		if ( '' === $note ) {
			return true;
		}

		if ( '' !== $status && $note === 'status updated to ' . str_replace( '-', ' ', $status ) ) {
			return true;
		}

		return (bool) preg_match( '/^status (?:updated|changed) to [a-z0-9 _-]+(?:;?\s*added note per user request\.?)?$/i', $note )
			|| (bool) preg_match( '/^added note per user request\.?$/i', $note );
	}

	private static function merge_unavailable_requests( $primary, $fallback ) {
		$primary = is_array( $primary ) ? array_values( $primary ) : [];
		$fallback = is_array( $fallback ) ? array_values( $fallback ) : [];
		$seen = [];
		$merged = [];

		foreach ( array_merge( $primary, $fallback ) as $item ) {
			$key = sanitize_key( $item['key'] ?? '' );

			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$merged[] = [
				'key'     => $key,
				'label'   => sanitize_text_field( $item['label'] ?? $key ),
				'message' => sanitize_text_field( $item['message'] ?? '' ),
			];
		}

		return $merged;
	}

	private static function resolve_order_id_from_ai_actions( $command, $client_context, $hint, $requested ) {
		$requested = is_array( $requested ) ? $requested : [];

		foreach ( $requested as $item ) {
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			if ( ! empty( $params['order_id'] ) ) {
				return absint( $params['order_id'] );
			}

			if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) && ! empty( $params['order_ids'][0] ) ) {
				return absint( $params['order_ids'][0] );
			}
		}

		$params = isset( $hint['params'] ) && is_array( $hint['params'] ) ? $hint['params'] : [];

		return self::resolve_order_id_from_context( $command, $client_context, $params );
	}

	private static function resolve_order_id_from_context( $command, $client_context, $params ) {
		$order_ids = class_exists( 'YoRo_Order_Helper' )
			? YoRo_Order_Helper::extract_order_ids_from_text( $command )
			: [];

		if ( ! empty( $order_ids[0] ) ) {
			return absint( $order_ids[0] );
		}

		if ( ! empty( $params['order_id'] ) ) {
			return absint( $params['order_id'] );
		}

		if ( ! empty( $params['order_ids'] ) && is_array( $params['order_ids'] ) && ! empty( $params['order_ids'][0] ) ) {
			return absint( $params['order_ids'][0] );
		}

		if ( ! empty( $client_context['current_order_id'] ) ) {
			return absint( $client_context['current_order_id'] );
		}

		if ( ! empty( $client_context['last_referenced_order_id'] ) ) {
			return absint( $client_context['last_referenced_order_id'] );
		}

		return 0;
	}

	private static function infer_kind_from_action( $action ) {
		switch ( sanitize_text_field( $action ) ) {
			case 'orders.hold':
				return 'hold';
			case 'orders.add_note':
				return 'add_note';
			case 'orders.update_status':
				return 'update_status';
			case 'orders.bulk_update_status_with_note':
				return 'status_with_note';
			case 'orders.compose_customer_email':
				return 'write_email';
			case 'orders.save_customer_email_draft':
				return 'save_draft';
			case 'orders.send_customer_email':
				return 'send_email';
			default:
				return sanitize_key( str_replace( '.', '_', (string) $action ) );
		}
	}

	private static function should_load_order_first( $requested ) {
		$requested = is_array( $requested ) ? $requested : [];

		foreach ( $requested as $item ) {
			if ( 'write_email' === sanitize_key( $item['kind'] ?? '' ) ) {
				return true;
			}
		}

		return count( $requested ) > 1;
	}

	private static function compact_requested_actions( $requested ) {
		$requested = is_array( $requested ) ? $requested : [];
		$compact   = [];

		foreach ( $requested as $item ) {
			$compact[] = [
				'kind'   => sanitize_key( $item['kind'] ?? '' ),
				'action' => sanitize_text_field( $item['action'] ?? '' ),
				'label'  => sanitize_text_field( $item['label'] ?? ( $item['action'] ?? '' ) ),
			];
		}

		return $compact;
	}

	private static function compact_unavailable_requests( $unavailable ) {
		$unavailable = is_array( $unavailable ) ? $unavailable : [];
		$compact     = [];

		foreach ( $unavailable as $item ) {
			$compact[] = [
				'key'     => sanitize_key( $item['key'] ?? '' ),
				'label'   => sanitize_text_field( $item['label'] ?? '' ),
				'message' => sanitize_text_field( $item['message'] ?? '' ),
			];
		}

		return $compact;
	}

	private static function build_missing_note_clarification( $command, $normalized, $order_id, $requested, $unavailable, $extracted ) {
		$params = [
			'order_id'                            => $order_id,
			'order_ids'                           => [ $order_id ],
			'note'                                => '',
			'_yoro_composed_requested_actions'    => $requested,
			'_yoro_composed_unavailable_requests' => $unavailable,
			'_yoro_composed_original_command'     => $command,
			'_yoro_composed_parser_rule'          => sanitize_text_field( $extracted['parser_rule'] ?? self::PARSER_RULE_GUARD ),
			'_yoro_composed_parser_source'        => self::composition_parser_source( $extracted ),
			'_yoro_composed_confidence'           => (float) ( $extracted['confidence'] ?? 0.85 ),
			'_yoro_composed_source'               => sanitize_key( $extracted['composition_source'] ?? self::COMPOSITION_SOURCE_GUARD ),
		];

		YoRo_Conversation_Memory::start_workflow(
			get_current_user_id(),
			'orders.add_note',
			$params,
			[ 'note' ],
			'composed_actions'
		);

		return [
			'success'            => false,
			'matched'            => true,
			'stage'              => 'clarify',
			'action'             => 'orders.add_note',
			'message'            => __( 'What note should I add?', 'yoro-ai-core' ),
			'params'             => $params,
			'missing'            => [ 'note' ],
			'clarify_reason'     => 'missing_note',
			'composition_source' => sanitize_key( $extracted['composition_source'] ?? self::COMPOSITION_SOURCE_GUARD ),
			'parser_source'      => self::composition_parser_source( $extracted ),
			'parser_rule'        => sanitize_text_field( $extracted['parser_rule'] ?? self::PARSER_RULE_GUARD ) . '.missing_note',
			'confidence'         => max( 0.85, (float) ( $extracted['confidence'] ?? 0.85 ) ),
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function build_invalid_email_instruction_clarification( $command, $normalized, $order_id, $requested, $unavailable, $extracted ) {
		$requested = is_array( $requested ) ? $requested : [];
		$order_id  = absint( $order_id );

		if ( $order_id <= 0 || ! self::order_requires_no_shipping( $order_id ) ) {
			return null;
		}

		foreach ( $requested as $item ) {
			if ( 'write_email' !== sanitize_key( $item['kind'] ?? '' ) ) {
				continue;
			}

			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];
			$instruction = sanitize_textarea_field( $params['instruction'] ?? '' );

			if ( ! self::looks_like_shipping_customer_messaging( $instruction ) ) {
				continue;
			}

			$clarify_params = [
				'order_id'                            => $order_id,
				'order_ids'                           => [ $order_id ],
				'instruction'                         => '',
				'_yoro_composed_requested_actions'    => $requested,
				'_yoro_composed_unavailable_requests' => $unavailable,
				'_yoro_composed_original_command'     => $command,
				'_yoro_composed_parser_rule'          => sanitize_text_field( $extracted['parser_rule'] ?? self::PARSER_RULE_GUARD ),
				'_yoro_composed_parser_source'        => self::composition_parser_source( $extracted ),
				'_yoro_composed_confidence'           => (float) ( $extracted['confidence'] ?? 0.85 ),
				'_yoro_composed_source'               => sanitize_key( $extracted['composition_source'] ?? self::COMPOSITION_SOURCE_GUARD ),
			];

			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				'orders.compose_customer_email',
				$clarify_params,
				[ 'instruction' ],
				'composed_actions'
			);

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'orders.compose_customer_email',
				'message'            => sprintf(
					/* translators: %d: order id */
					__( 'Order #%d does not require shipping. What should the customer email say instead?', 'yoro-ai-core' ),
					$order_id
				),
				'params'             => $clarify_params,
				'missing'            => [ 'instruction' ],
				'clarify_reason'     => 'missing_instruction',
				'composition_source' => sanitize_key( $extracted['composition_source'] ?? self::COMPOSITION_SOURCE_GUARD ),
				'parser_source'      => self::composition_parser_source( $extracted ),
				'parser_rule'        => sanitize_text_field( $extracted['parser_rule'] ?? self::PARSER_RULE_GUARD ) . '.invalid_email_instruction',
				'confidence'         => max( 0.85, (float) ( $extracted['confidence'] ?? 0.85 ) ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return null;
	}

	private static function inject_missing_note_action( $requested, $note ) {
		$requested = is_array( $requested ) ? array_values( $requested ) : [];
		$note = sanitize_textarea_field( $note );
		$insert_at = count( $requested );

		foreach ( $requested as $index => $item ) {
			$kind   = sanitize_key( $item['kind'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];

			if ( in_array( $kind, [ 'add_note', 'status_with_note' ], true ) ) {
				$current_note = sanitize_textarea_field( $params['note'] ?? '' );

				if ( '' === $current_note || self::is_placeholder_note_text( $current_note, sanitize_key( $params['status'] ?? '' ) ) ) {
					$params['note'] = $note;
					$requested[ $index ]['params'] = $params;

					return $requested;
				}
			}

			if ( in_array( $kind, [ 'hold', 'update_status', 'status_with_note' ], true ) ) {
				$insert_at = $index + 1;
			}
		}

		array_splice(
			$requested,
			$insert_at,
			0,
			[
				[
					'kind'     => 'add_note',
					'action'   => 'orders.add_note',
					'label'    => __( 'Add internal note', 'yoro-ai-core' ),
					'position' => $insert_at,
					'params'   => [
						'note' => $note,
					],
				],
			]
		);

		foreach ( $requested as $index => $item ) {
			$requested[ $index ]['position'] = $index;
		}

		return $requested;
	}

	private static function replace_requested_email_instruction( $requested, $instruction ) {
		$requested   = is_array( $requested ) ? array_values( $requested ) : [];
		$instruction = sanitize_textarea_field( $instruction );

		foreach ( $requested as $index => $item ) {
			if ( 'write_email' !== sanitize_key( $item['kind'] ?? '' ) ) {
				continue;
			}

			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];
			$params['instruction'] = $instruction;
			$requested[ $index ]['params'] = $params;
			break;
		}

		return $requested;
	}

	private static function requested_actions_contain_concrete_note( $requested ) {
		$requested = is_array( $requested ) ? $requested : [];

		foreach ( $requested as $item ) {
			$kind = sanitize_key( $item['kind'] ?? '' );
			$params = isset( $item['params'] ) && is_array( $item['params'] ) ? $item['params'] : [];
			$note = sanitize_textarea_field( $params['note'] ?? '' );
			$status = sanitize_key( $params['status'] ?? '' );

			if ( in_array( $kind, [ 'add_note', 'status_with_note' ], true ) && '' !== $note && ! self::is_placeholder_note_text( $note, $status ) ) {
				return true;
			}
		}

		return false;
	}

	private static function order_requires_no_shipping( $order_id ) {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || ! method_exists( $order, 'needs_shipping_address' ) ) {
			return false;
		}

		return ! $order->needs_shipping_address();
	}

	private static function looks_like_shipping_customer_messaging( $text ) {
		$text = strtolower( trim( (string) $text ) );

		if ( '' === $text ) {
			return false;
		}

		return (bool) preg_match(
			'/\b(?:shipping|shipment|delivery|delivered soon|on the way|tracking|in transit|package ships?)\b/i',
			$text
		);
	}

	private static function build_workflow_label( $requested, $unavailable ) {
		$requested_count   = is_array( $requested ) ? count( $requested ) : 0;
		$unavailable_count = is_array( $unavailable ) ? count( $unavailable ) : 0;

		if ( $requested_count >= 3 && $unavailable_count > 0 ) {
			return __( 'Composed workflow with partial support', 'yoro-ai-core' );
		}

		if ( $requested_count >= 3 ) {
			return __( 'Composed workflow', 'yoro-ai-core' );
		}

		if ( $unavailable_count > 0 ) {
			return __( 'Partial workflow plan', 'yoro-ai-core' );
		}

		return __( 'Assembled workflow', 'yoro-ai-core' );
	}
}
