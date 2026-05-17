<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Email_Command_Parser {

	public static function parse_priority_workflow_continue( $command, $normalized, $client_context = [] ) {
		if ( ! self::should_prioritize_workflow_continue( $client_context ) ) {
			return null;
		}

		$parsed = self::parse_workflow_continue( $command, $normalized, $client_context );

		if ( is_array( $parsed ) ) {
			return $parsed;
		}

		return self::parse_priority_workflow_control_follow_up( $command, $normalized, $client_context );
	}

	public static function parse( $command, $normalized, $client_context = [] ) {
		$parsers = [
			'parse_email_templates_save',
			'parse_orders_get_customer_email_drafts',
			'parse_orders_compose_customer_email',
			'parse_orders_email_customer_template',
			'parse_orders_email_customer_preview',
			'parse_follow_up_save_current_email_draft',
			'parse_follow_up_save_current_email_as_template',
			'parse_follow_up_delete_current_email_draft',
			'parse_follow_up_send_current_email',
			'parse_workflow_continue',
		];

		foreach ( $parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( $parsed ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function should_prioritize_workflow_continue( $client_context = [] ) {
		$workflow = self::get_workflow_from_context( $client_context );

		if ( empty( $workflow ) || empty( $workflow['action'] ) || empty( $workflow['step'] ) ) {
			return false;
		}

		if ( 'collect_missing_params' !== sanitize_key( $workflow['step'] ) ) {
			return false;
		}

		return in_array(
			sanitize_text_field( $workflow['action'] ),
			[
				'orders.add_note',
				'orders.update_status',
				'orders.compose_customer_email',
				'email_templates.save',
			],
			true
		);
	}

	private static function parse_priority_workflow_control_follow_up( $command, $normalized, $client_context = [] ) {
		$priority_follow_up_parsers = [
			'parse_follow_up_save_current_email_draft',
			'parse_follow_up_save_current_email_as_template',
			'parse_follow_up_delete_current_email_draft',
			'parse_follow_up_send_current_email',
		];

		foreach ( $priority_follow_up_parsers as $parser ) {
			$parsed = self::$parser( $command, $normalized, $client_context );

			if ( is_array( $parsed ) ) {
				return $parsed;
			}
		}

		return null;
	}

	private static function parse_workflow_continue( $command, $normalized, $client_context = [] ) {
		$workflow = self::get_workflow_from_context( $client_context );

		if ( empty( $workflow ) || empty( $workflow['action'] ) || empty( $workflow['step'] ) ) {
			return null;
		}

		/*
		* Confirmation replay is handled by the REST confirmation flow via
		* workflow_state_token. Do not auto-replay ready_to_execute here, or a
		* stale workflow can hijack a brand-new command that happens to match the
		* previous last_command.
		*/
		if ( 'ready_to_execute' === $workflow['step'] ) {
			return null;
		}

		if ( 'collect_missing_params' !== $workflow['step'] ) {
			return null;
		}

		if (
			false !== strpos( $normalized, 'save' ) &&
			false !== strpos( $normalized, 'template' ) &&
			false !== strpos( $normalized, 'named' )
		) {
			return null;
		}

		$ttl = self::get_workflow_ttl();

		if ( ! empty( $workflow['updated_at'] ) ) {
			$ts = strtotime( $workflow['updated_at'] );

			if ( $ts && ( time() - $ts ) > $ttl ) {
				YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

				return [
					'success'            => false,
					'matched'            => false,
					'stage'              => 'clarify',
					'message'            => __( 'This workflow has expired. Please start again.', 'yoro-agent-core' ),
					'clarify_reason'     => 'workflow_expired',
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}
		}

		$action = sanitize_text_field( $workflow['action'] );

		$params = isset( $workflow['params'] ) && is_array( $workflow['params'] )
			? $workflow['params']
			: [];

		$missing = isset( $workflow['missing'] ) && is_array( $workflow['missing'] )
			? array_values( array_filter( array_map( 'sanitize_key', $workflow['missing'] ) ) )
			: [];

		if ( empty( $missing ) ) {
			return null;
		}

		if ( self::should_defer_workflow_reply_to_follow_up_or_standalone_parser( $workflow, $action, $missing, $command, $normalized ) ) {
			return null;
		}

		$inferred_params = self::infer_missing_workflow_params_from_command(
			$action,
			$params,
			$missing,
			$command,
			$normalized,
			$client_context
		);

		if ( is_array( $inferred_params ) ) {
			$params = array_merge( $params, $inferred_params );
			$missing = self::get_missing_workflow_params( $action, $params, $missing );

			if ( empty( $missing ) ) {
				$resumed_composed = self::maybe_resume_composed_actions_workflow( $workflow, $params, $command, $normalized );

				if ( is_array( $resumed_composed ) ) {
					return $resumed_composed;
				}

				YoRo_Conversation_Memory::set_workflow(
					get_current_user_id(),
					[
						'type'         => $workflow['type'] ?? 'generic',
						'action'       => $action,
						'step'         => 'ready_to_execute',
						'params'       => $params,
						'missing'      => [],
						'last_command' => $command,
						'updated_at'   => current_time( 'mysql' ),
					]
				);

				return self::parsed_action(
					$action,
					$params,
					$command,
					$normalized,
					'rule.workflow.continue.inferred'
				);
			}
		}

		if ( self::should_capture_missing_workflow_param_raw( $action, $missing, $command, $normalized ) ) {
			foreach ( $missing as $field ) {
				if ( ! empty( $params[ $field ] ) ) {
					continue;
				}

				$params[ $field ] = self::sanitize_workflow_param( $field, $command );
				break;
			}

			$new_missing = self::get_missing_workflow_params( $action, $params, $missing );

			if ( ! empty( $new_missing ) ) {
				YoRo_Conversation_Memory::start_workflow(
					get_current_user_id(),
					$action,
					$params,
					$new_missing,
					$workflow['type'] ?? 'generic'
				);

				return [
					'success'            => false,
					'matched'            => true,
					'stage'              => 'clarify',
					'action'             => $action,
					'params'             => $params,
					'missing'            => $new_missing,
					'message'            => self::build_workflow_missing_message( $action, $new_missing ),
					'clarify_reason'     => self::infer_workflow_clarify_reason( $action, $new_missing ),
					'parser_source'      => 'rule',
					'parser_rule'        => 'rule.workflow.continue',
					'confidence'         => 1.0,
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}

			YoRo_Conversation_Memory::set_workflow(
				get_current_user_id(),
				[
					'type'         => $workflow['type'] ?? 'generic',
					'action'       => $action,
					'step'         => 'ready_to_execute',
					'params'       => $params,
					'missing'      => [],
					'last_command' => $command,
					'updated_at'   => current_time( 'mysql' ),
				]
			);

			return self::parsed_action(
				$action,
				$params,
				$command,
				$normalized,
				'rule.workflow.continue'
			);
		}

		if ( self::supports_state_driven_slot_filling( $action ) || self::looks_like_standalone_non_workflow_request( $command, $normalized ) ) {
			return null;
		}

		foreach ( $missing as $field ) {
			if ( ! empty( $params[ $field ] ) ) {
				continue;
			}

			if ( preg_match( '/\b' . preg_quote( $field, '/' ) . '\s+"([^"]+)"/i', $command, $m ) ) {
				$params[ $field ] = self::sanitize_workflow_param( $field, $m[1] );
				break;
			}

			$params[ $field ] = self::sanitize_workflow_param( $field, $command );
			break;
		}

		$new_missing = self::get_missing_workflow_params( $action, $params, $missing );

		if ( ! empty( $new_missing ) ) {
			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				$action,
				$params,
				$new_missing,
				$workflow['type'] ?? 'generic'
			);

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => $action,
				'params'             => $params,
				'missing'            => $new_missing,
				'message'            => self::build_workflow_missing_message( $action, $new_missing ),
				'clarify_reason'     => self::infer_workflow_clarify_reason( $action, $new_missing ),
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.workflow.continue',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		/*
		* Do NOT clear workflow here.
		* Save it as ready_to_execute so confirmation can replay the exact same action/params.
		*/
		YoRo_Conversation_Memory::set_workflow(
			get_current_user_id(),
			[
				'type'         => $workflow['type'] ?? 'generic',
				'action'       => $action,
				'step'         => 'ready_to_execute',
				'params'       => $params,
				'missing'      => [],
				'last_command' => $command,
				'updated_at'   => current_time( 'mysql' ),
			]
		);

		return self::parsed_action(
			$action,
			$params,
			$command,
			$normalized,
			'rule.workflow.continue'
		);
	}

	private static function should_defer_workflow_reply_to_follow_up_or_standalone_parser( $workflow, $action, $missing, $command, $normalized ) {
		if ( self::is_workflow_control_command( $workflow, $action, $normalized ) ) {
			return true;
		}

		if ( self::looks_like_standalone_non_workflow_request( $command, $normalized ) ) {
			return true;
		}

		return false;
	}

	private static function supports_state_driven_slot_filling( $action ) {
		return in_array(
			sanitize_text_field( $action ),
			[
				'orders.add_note',
				'orders.update_status',
				'orders.compose_customer_email',
				'email_templates.save',
			],
			true
		);
	}

	private static function is_workflow_control_command( $workflow, $action, $normalized ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$normalized = strtolower( trim( (string) $normalized ) );
		$action = sanitize_text_field( $action );
		$workflow_type = sanitize_key( $workflow['type'] ?? '' );

		if ( '' === $normalized ) {
			return false;
		}

		if ( preg_match( '/^\s*(cancel|discard|stop)\b/i', $normalized ) ) {
			return true;
		}

		if (
			'customer_email' === $workflow_type ||
			'orders.compose_customer_email' === $action
		) {
			$email_control_commands = [
				'save draft',
				'save this draft',
				'save the draft',
				'save current draft',
				'save email draft',
				'save this email draft',
				'save the email draft',
				'send email',
				'send the email',
				'send this email',
				'send current email',
				'send reviewed email',
			];

			if ( in_array( $normalized, $email_control_commands, true ) ) {
				return true;
			}

			if (
				false !== strpos( $normalized, 'save' ) &&
				false !== strpos( $normalized, 'template' )
			) {
				return true;
			}

			if (
				preg_match( '/\b(delete|remove)\b/i', $normalized ) &&
				false !== strpos( $normalized, 'draft' )
			) {
				return true;
			}
		}

		if (
			'email_templates.save' === $action &&
			preg_match( '/\btemplate\b/i', $normalized ) &&
			preg_match( '/\b(save|create|add)\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function should_capture_missing_workflow_param_raw( $action, $missing, $command, $normalized ) {
		$missing = array_values( array_filter( array_map( 'sanitize_key', (array) $missing ) ) );

		if ( empty( $missing ) ) {
			return false;
		}

		if (
			'orders.add_note' === $action &&
			[ 'note' ] === $missing &&
			! self::looks_like_new_add_note_command( $command, $normalized )
		) {
			return true;
		}

		if (
			'orders.update_status' === $action &&
			[ 'status' ] === $missing &&
			! self::looks_like_new_update_status_command( $command, $normalized )
		) {
			return true;
		}

		if (
			'orders.compose_customer_email' === $action &&
			[ 'instruction' ] === $missing
		) {
			return true;
		}

		if (
			'email_templates.save' === $action &&
			! empty( array_intersect( $missing, [ 'name', 'subject', 'body' ] ) )
		) {
			return true;
		}

		return false;
	}

	private static function infer_missing_workflow_params_from_command( $action, $params, $missing, $command, $normalized, $client_context = [] ) {
		$params = is_array( $params ) ? $params : [];
		$missing = array_values( array_filter( array_map( 'sanitize_key', (array) $missing ) ) );
		$inferred = [];

		if ( empty( $missing ) ) {
			return $inferred;
		}

		if ( 'orders.update_status' === $action && in_array( 'status', $missing, true ) ) {
			$status = YoRo_Order_Helper::detect_status_from_text( $command );

			if ( empty( $status ) ) {
				$status = YoRo_Order_Helper::normalize_status_slug( $command );
			}

			if ( ! empty( $status ) ) {
				$inferred['status'] = sanitize_key( $status );
			}
		}

		if ( 'orders.add_note' === $action && in_array( 'note', $missing, true ) ) {
			if ( ! self::looks_like_new_add_note_command( $command, $normalized ) ) {
				$inferred['note'] = sanitize_textarea_field( $command );
			}
		}

		if ( 'orders.compose_customer_email' === $action && in_array( 'instruction', $missing, true ) ) {
			$inferred['instruction'] = sanitize_textarea_field( $command );
		}

		if ( 'email_templates.save' === $action ) {
			if ( in_array( 'name', $missing, true ) && preg_match( '/\b(?:named|name)\s+"([^"]+)"/i', $command, $matches ) ) {
				$inferred['name'] = sanitize_text_field( trim( $matches[1] ) );
			}

			if ( in_array( 'subject', $missing, true ) && preg_match( '/\bsubject\s+"([^"]+)"/i', $command, $matches ) ) {
				$inferred['subject'] = sanitize_text_field( trim( $matches[1] ) );
			}

			if ( in_array( 'body', $missing, true ) && preg_match( '/\bbody\s+"([\s\S]+)"/i', $command, $matches ) ) {
				$inferred['body'] = sanitize_textarea_field( trim( $matches[1] ) );
			}
		}

		return $inferred;
	}

	private static function looks_like_new_add_note_command( $command, $normalized ) {
		if ( ! preg_match( '/\b(?:add\s+(?:a\s+)?)?note\b/i', $normalized ) ) {
			return false;
		}

		if (
			preg_match( '/\border\s*#?\s*\d+\b/i', $command ) ||
			preg_match( '/\b(this|that|the|same)\s+order\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function maybe_resume_composed_actions_workflow( $workflow, $params, $command, $normalized ) {
		$workflow = is_array( $workflow ) ? $workflow : [];
		$params   = is_array( $params ) ? $params : [];

		$workflow_action = sanitize_text_field( $workflow['action'] ?? '' );

		if (
			'composed_actions' !== sanitize_key( $workflow['type'] ?? '' ) ||
			empty( $params['_yoro_composed_requested_actions'] ) ||
			! class_exists( 'YoRo_Action_Composition_Planner' )
		) {
			return null;
		}

		$workflow['params'] = $params;

		if ( 'orders.add_note' === $workflow_action ) {
			return YoRo_Action_Composition_Planner::resume_from_missing_note(
				$workflow,
				$command,
				$normalized
			);
		}

		if ( 'orders.compose_customer_email' === $workflow_action ) {
			return YoRo_Action_Composition_Planner::resume_from_missing_instruction(
				$workflow,
				$command,
				$normalized
			);
		}

		return null;
	}

	private static function looks_like_new_update_status_command( $command, $normalized ) {
		if ( ! preg_match( '/\border(?:s)?\b/i', $normalized ) ) {
			return false;
		}

		$has_status_update_verb = preg_match( '/\b(change|mark|move|set|update)\b/i', $normalized );
		$has_status_connector   = preg_match( '/\b(as|to|status)\b/i', $normalized );

		if ( ! $has_status_update_verb || ! $has_status_connector ) {
			return false;
		}

		if (
			preg_match( '/\border\s*#?\s*\d+\b/i', $command ) ||
			preg_match( '/\b(this|that|the|same)\s+order\b/i', $normalized )
		) {
			return true;
		}

		return false;
	}

	private static function parse_follow_up_save_current_email_draft( $command, $normalized, $client_context = [] ) {

		$is_save_current_draft = in_array(
			$normalized,
			[
				'save draft',
				'save this draft',
				'save the draft',
				'save current draft',
				'save email draft',
				'save this email draft',
				'save the email draft',
			],
			true
		);

		if ( ! $is_save_current_draft ) {
			return null;
		}

		$workflow = self::get_workflow_from_context( $client_context );

		$ttl = self::get_workflow_ttl();

		if ( ! empty( $workflow['updated_at'] ) ) {
			$ts = strtotime( $workflow['updated_at'] );

			if ( $ts && ( time() - $ts ) > $ttl ) {
				YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

				return [
					'success'            => false,
					'matched'            => false,
					'stage'              => 'clarify',
					'message'            => __( 'This email workflow has expired. Please compose the email again.', 'yoro-agent-core' ),
					'clarify_reason'     => 'workflow_expired',
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}
		}

		if (
			empty( $workflow ) ||
			empty( $workflow['type'] ) ||
			'customer_email' !== $workflow['type']
		) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'No email draft is ready to save. Please compose or preview an email first.', 'yoro-agent-core' ),
				'clarify_reason'     => 'missing_instruction',
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$order_id = ! empty( $workflow['order_id'] ) ? absint( $workflow['order_id'] ) : 0;
		$subject  = ! empty( $workflow['subject'] ) ? sanitize_text_field( $workflow['subject'] ) : '';
		$body     = ! empty( $workflow['body'] ) ? sanitize_textarea_field( $workflow['body'] ) : '';

		if ( ! $order_id || empty( $subject ) || empty( $body ) ) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'The current email draft is missing order, subject, or body. Please preview the email again before saving.', 'yoro-agent-core' ),
				'clarify_reason'     => 'missing_instruction',
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.save_customer_email_draft',
			[
				'order_id' => $order_id,
				'subject'  => $subject,
				'body'     => $body,
			],
			$command,
			$normalized,
			'rule.follow_up.save_current_email_draft.workflow'
		);
	}

	private static function parse_follow_up_save_current_email_as_template( $command, $normalized, $client_context = [] ) {
		if (
			false === strpos( $normalized, 'save' ) ||
			false === strpos( $normalized, 'template' )
		) {
			return null;
		}

		$workflow = self::get_workflow_from_context( $client_context );

		if (
			empty( $workflow ) ||
			empty( $workflow['type'] ) ||
			'customer_email' !== $workflow['type'] ||
			empty( $workflow['subject'] ) ||
			empty( $workflow['body'] )
		) {
			return null;
		}

		$name = self::extract_template_name_from_followup_command( $command );

		if ( '' === $name ) {
			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'email_templates.save',
				'message'            => __( 'What should I name this template?', 'yoro-agent-core' ),
				'clarify_reason'     => 'missing_target',
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'email_templates.save',
			[
				'name'    => $name,
				'subject' => sanitize_text_field( $workflow['subject'] ),
				'body'    => sanitize_textarea_field( $workflow['body'] ),
			],
			$command,
			$normalized,
			'rule.follow_up.save_current_email_as_template'
		);
	}

	private static function parse_follow_up_delete_current_email_draft( $command, $normalized, $client_context = [] ) {
		if (
			! preg_match( '/\b(delete|remove)\b/i', $normalized ) ||
			false === strpos( $normalized, 'draft' )
		) {
			return null;
		}

		$workflow = self::get_workflow_from_context( $client_context );

		if (
			empty( $workflow ) ||
			empty( $workflow['type'] ) ||
			'customer_email' !== $workflow['type'] ||
			empty( $workflow['order_id'] ) ||
			empty( $workflow['draft_id'] )
		) {
			return [
				'success'            => false,
				'matched'            => false,
				'stage'              => 'clarify',
				'message'            => __( 'No saved draft is ready to delete. Please save or open a draft first.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.delete_customer_email_draft',
			[
				'order_id' => absint( $workflow['order_id'] ),
				'draft_id' => sanitize_key( $workflow['draft_id'] ),
			],
			$command,
			$normalized,
			'rule.follow_up.delete_current_email_draft'
		);
	}

	private static function looks_like_standalone_non_workflow_request( $command, $normalized ) {
		$normalized = strtolower( trim( (string) $normalized ) );

		if ( '' === $normalized ) {
			return false;
		}

		if (
			preg_match( '/\border\s*#?\s*\d+\b/i', $command ) ||
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'same order' )
		) {
			return true;
		}

		if (
			false !== strpos( $normalized, 'customer summary' ) ||
			false !== strpos( $normalized, 'customer profile' ) ||
			false !== strpos( $normalized, 'customer record' ) ||
			false !== strpos( $normalized, 'customer overview' ) ||
			false !== strpos( $normalized, 'this customer' ) ||
			false !== strpos( $normalized, 'that customer' ) ||
			false !== strpos( $normalized, 'same customer' ) ||
			preg_match( '/\borders?\s+for\b/i', $command ) ||
			preg_match( '/\b[a-zA-Z][a-zA-Z\s.\'\-]{1,80}[\'’]s\s+orders?\b/i', $command )
		) {
			return true;
		}

		if (
			false !== strpos( $normalized, 'briefing' ) ||
			false !== strpos( $normalized, 'queue' ) ||
			false !== strpos( $normalized, 'insights' ) ||
			false !== strpos( $normalized, 'summary' ) ||
			false !== strpos( $normalized, 'overview' ) ||
			false !== strpos( $normalized, 'fulfillment' ) ||
			false !== strpos( $normalized, 'inventory' ) ||
			false !== strpos( $normalized, 'attention' ) ||
			false !== strpos( $normalized, 'customer service' ) ||
			false !== strpos( $normalized, 'customer support' )
		) {
			return true;
		}

		if (
			preg_match( '/^\s*(?:do|does|can|could|would|will|is|are)\b/i', $command ) &&
			(
				false !== strpos( $normalized, 'support' ) ||
				false !== strpos( $normalized, 'compatible' ) ||
				false !== strpos( $normalized, 'work with' ) ||
				false !== strpos( $normalized, 'connect to' ) ||
				false !== strpos( $normalized, 'integrate with' )
			)
		) {
			return true;
		}

		return false;
	}

	private static function parse_email_templates_save( $command, $normalized, $client_context = [] ) {

		if (
			! preg_match( '/\btemplate\b/i', $normalized ) ||
			! preg_match( '/\b(save|create|add)\b/i', $normalized )
		) {
			return null;
		}

		$full_pattern = '/(?:email\s+)?template\s+named\s+"([^"]+)"\s+with\s+subject\s+"([^"]+)"\s+and\s+body\s+"([\s\S]+)"$/i';

		if ( preg_match( $full_pattern, $command, $matches ) ) {
			$name    = sanitize_text_field( $matches[1] );
			$subject = sanitize_text_field( $matches[2] );
			$body    = sanitize_textarea_field( $matches[3] );

			if ( empty( $name ) || empty( $subject ) || empty( $body ) ) {
				return null;
			}

			YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

			return self::parsed_action(
				'email_templates.save',
				[
					'name'    => $name,
					'subject' => $subject,
					'body'    => $body,
				],
				$command,
				$normalized,
				'rule.email_templates.save'
			);
		}

		if ( preg_match( '/(?:email\s+)?template\s+named\s+"([^"]+)"/i', $command, $partial ) ) {
			$name = sanitize_text_field( $partial[1] );

			if ( empty( $name ) ) {
				return null;
			}

			$params = [
				'name'    => $name,
				'subject' => '',
				'body'    => '',
			];

			$missing = [ 'subject', 'body' ];

			YoRo_Conversation_Memory::start_workflow(
				get_current_user_id(),
				'email_templates.save',
				$params,
				$missing,
				'email_template'
			);

			return [
				'success'            => false,
				'matched'            => true,
				'stage'              => 'clarify',
				'action'             => 'email_templates.save',
				'params'             => $params,
				'missing'            => $missing,
				'message'            => self::build_template_missing_message( $missing ),
				'parser_source'      => 'rule',
				'parser_rule'        => 'rule.email_templates.save.partial',
				'confidence'         => 1.0,
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return null;
	}

	private static function parse_orders_get_customer_email_drafts( $command, $normalized, $client_context = [] ) {
		$order_ids = self::extract_order_ids( $command );
		$has_contextual_order_anchor = ! empty( self::resolve_current_order_ids_from_context( $client_context ) );

		if ( ! self::looks_like_customer_email_drafts_anchor_request( $normalized, ! empty( $order_ids ), $has_contextual_order_anchor ) ) {
			return null;
		}

		if ( empty( $order_ids ) ) {
			$order_ids = self::resolve_current_order_ids_from_context( $client_context );
		}

		if ( empty( $order_ids ) ) {
			return [
				'success'            => false,
				'matched'            => true,
				'action'             => 'orders.get_customer_email_drafts',
				'message'            => __( 'Please provide an order ID or open an order first to load email drafts.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.get_customer_email_drafts',
			[
				'order_id' => absint( reset( $order_ids ) ),
			],
			$command,
			$normalized,
			'rule.orders.get_customer_email_drafts'
		);
	}

	private static function parse_orders_compose_customer_email( $command, $normalized, $client_context = [] ) {
		if ( false === strpos( $normalized, 'email' ) ) {
			return null;
		}

		if (
			false === strpos( $normalized, 'write' ) &&
			false === strpos( $normalized, 'compose' ) &&
			false === strpos( $normalized, 'draft' ) &&
			false === strpos( $normalized, 'prepare' )
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
				'action'             => 'orders.compose_customer_email',
				'message'            => __( 'Please provide an order ID or open an order first for the email draft.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		$instruction = sanitize_textarea_field( $command );

		return self::parsed_action(
			'orders.compose_customer_email',
			[
				'order_id'    => absint( reset( $order_ids ) ),
				'instruction' => $instruction,
			],
			$command,
			$normalized,
			'rule.orders.compose_customer_email'
		);
	}

	private static function parse_follow_up_send_current_email( $command, $normalized, $client_context = [] ) {

		$is_send_current_email = in_array(
			$normalized,
			[
				'send email',
				'send the email',
				'send this email',
				'send current email',
				'send reviewed email',
			],
			true
		);

		if ( ! $is_send_current_email ) {
			return null;
		}

		$workflow = self::get_workflow_from_context( $client_context );

		$ttl = self::get_workflow_ttl();

		if ( ! empty( $workflow['updated_at'] ) ) {
			$ts = strtotime( $workflow['updated_at'] );

			if ( $ts && ( time() - $ts ) > $ttl ) {

				YoRo_Conversation_Memory::clear_workflow( get_current_user_id() );

				return [
					'success'            => false,
					'matched'            => false,
					'stage'              => 'clarify',
					'message'            => __( 'This email workflow has expired. Please compose again.', 'yoro-agent-core' ),
					'original_command'   => $command,
					'normalized_command' => $normalized,
				];
			}
		}

		if (
			! empty( $workflow ) &&
			isset( $workflow['type'] ) &&
			'customer_email' === $workflow['type'] &&
			! empty( $workflow['order_id'] ) &&
			! empty( $workflow['subject'] ) &&
			! empty( $workflow['body'] )
		) {
			return self::parsed_action(
				'orders.send_customer_email',
				[
					'order_id' => absint( $workflow['order_id'] ),
					'subject'  => sanitize_text_field( $workflow['subject'] ),
					'body'     => sanitize_textarea_field( $workflow['body'] ),
				],
				$command,
				$normalized,
				'rule.follow_up.send_current_email.workflow'
			);
		}

		return [
			'success'            => false,
			'matched'            => false,
			'stage'              => 'clarify',
			'message'            => __( 'No email is ready to send. Please compose or preview an email first.', 'yoro-agent-core' ),
			'original_command'   => $command,
			'normalized_command' => $normalized,
		];
	}

	private static function parse_orders_email_customer_template( $command, $normalized, $client_context = [] ) {
		if ( false === strpos( $normalized, 'email' ) ) {
			return null;
		}

		if (
			false !== strpos( $normalized, 'preview' ) ||
			false !== strpos( $normalized, 'prepare' ) ||
			false !== strpos( $normalized, 'draft' )
		) {
			return null;
		}

		if (
			false === strpos( $normalized, 'send' ) &&
			false === strpos( $normalized, 'mail customer' ) &&
			false === strpos( $normalized, 'email customer' )
		) {
			return null;
		}

		$template = self::detect_customer_email_template( $normalized );

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
				'action'             => 'orders.email_customer_template',
				'message'            => __( 'Please provide an order ID or open an order first for the customer email.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.email_customer_template',
			[
				'order_id' => absint( reset( $order_ids ) ),
				'template' => $template,
			],
			$command,
			$normalized,
			'rule.orders.email_customer_template'
		);
	}

	private static function parse_orders_email_customer_preview( $command, $normalized, $client_context = [] ) {
		if (
			false !== strpos( $normalized, 'send' ) ||
			false !== strpos( $normalized, 'sent' ) ||
			false !== strpos( $normalized, 'mail customer' ) ||
			false !== strpos( $normalized, 'email customer' )
		) {
			return null;
		}

		if ( false === strpos( $normalized, 'email' ) ) {
			return null;
		}

		$template = self::detect_customer_email_template( $normalized );

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
				'action'             => 'orders.email_customer_preview',
				'message'            => __( 'Please provide an order ID or open an order first for the email preview.', 'yoro-agent-core' ),
				'original_command'   => $command,
				'normalized_command' => $normalized,
			];
		}

		return self::parsed_action(
			'orders.email_customer_preview',
			[
				'order_id' => absint( reset( $order_ids ) ),
				'template' => $template,
			],
			$command,
			$normalized,
			'rule.orders.email_customer_preview'
		);
	}

	private static function normalize_workflow_command( $command ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', (string) $command ) ) );
	}

	private static function extract_template_name_from_followup_command( $command ) {
		$patterns = [
			'/\btemplate\s+named\s+"([^"]+)"/i',
			'/\bas\s+template\s+"([^"]+)"/i',
			'/\btemplate\s+named\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i',
			'/\bas\s+template\s+([a-zA-Z0-9][a-zA-Z0-9\s._\-]{1,80})$/i',
		];

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $command, $matches ) ) {
				$name = sanitize_text_field( trim( $matches[1] ?? '' ) );

				if ( '' !== $name ) {
					return $name;
				}
			}
		}

		return '';
	}

	private static function sanitize_workflow_param( $field, $value ) {
		$field = sanitize_key( $field );

		if ( 'status' === $field ) {
			$status = YoRo_Order_Helper::detect_status_from_text( $value );

			if ( empty( $status ) ) {
				$status = YoRo_Order_Helper::normalize_status_slug( $value );
			}

			return sanitize_key( $status );
		}

		if ( in_array( $field, [ 'body', 'note', 'instruction' ], true ) ) {
			return sanitize_textarea_field( $value );
		}

		if ( in_array( $field, [ 'order_id' ], true ) ) {
			return absint( $value );
		}

		if ( in_array( $field, [ 'order_ids' ], true ) ) {
			return array_values(
				array_filter(
					array_unique(
						array_map( 'absint', (array) $value )
					)
				)
			);
		}

		return sanitize_text_field( $value );
	}

	private static function get_missing_workflow_params( $action, $params, $fallback_missing = [] ) {
		$required = [];

		switch ( $action ) {
			case 'email_templates.save':
				$required = [ 'name', 'subject', 'body' ];
				break;

			case 'orders.add_note':
				$required = [ 'order_ids', 'note' ];
				break;

			case 'orders.update_status':
				$required = [ 'order_ids', 'status' ];
				break;

			default:
				$required = $fallback_missing;
				break;
		}

		$missing = [];

		foreach ( $required as $field ) {
			if ( empty( $params[ $field ] ) ) {
				$missing[] = $field;
			}
		}

		return $missing;
	}

	private static function build_workflow_missing_message( $action, $missing ) {
		$first = is_array( $missing ) && ! empty( $missing[0] )
			? $missing[0]
			: '';

		if ( 'email_templates.save' === $action ) {
			return self::build_template_missing_message( $missing );
		}

		if ( 'orders.add_note' === $action ) {
			return __( 'What note should I add?', 'yoro-agent-core' );
		}

		if ( 'orders.update_status' === $action ) {
			return __( 'What status should I update to?', 'yoro-agent-core' );
		}

		if ( 'orders.compose_customer_email' === $action ) {
			return __( 'What should the customer email be about?', 'yoro-agent-core' );
		}

		return sprintf(
			/* translators: %s: missing field name */
			__( 'Please provide %s.', 'yoro-agent-core' ),
			$first
		);
	}

	private static function infer_workflow_clarify_reason( $action, $missing ) {
		$action = sanitize_text_field( $action );
		$missing = is_array( $missing ) ? array_values( array_filter( array_map( 'sanitize_key', $missing ) ) ) : [];
		$first = $missing[0] ?? '';

		if ( 'orders.update_status' === $action || 'status' === $first ) {
			return 'missing_status';
		}

		if ( 'orders.add_note' === $action || 'note' === $first ) {
			return 'missing_note';
		}

		if (
			'orders.compose_customer_email' === $action ||
			in_array( $first, [ 'instruction', 'subject', 'body' ], true )
		) {
			return 'missing_instruction';
		}

		if ( in_array( $first, [ 'name', 'template_id' ], true ) ) {
			return 'missing_target';
		}

		return 'details';
	}

	private static function detect_customer_email_template( $normalized ) {
		if (
			false !== strpos( $normalized, 'needs verification' ) ||
			false !== strpos( $normalized, 'need verification' ) ||
			false !== strpos( $normalized, 'verification' ) ||
			false !== strpos( $normalized, 'verify' )
		) {
			return 'needs_verification';
		}

		if (
			false !== strpos( $normalized, 'payment mismatch' ) ||
			false !== strpos( $normalized, 'payment verification' ) ||
			false !== strpos( $normalized, 'payment' )
		) {
			return 'payment_mismatch';
		}

		if (
			false !== strpos( $normalized, 'shipping delay' ) ||
			false !== strpos( $normalized, 'delay' )
		) {
			return 'shipping_delay';
		}

		if (
			false !== strpos( $normalized, 'followup' ) ||
			false !== strpos( $normalized, 'follow-up' ) ||
			false !== strpos( $normalized, 'follow up' )
		) {
			return 'order_followup';
		}

		return '';
	}

	private static function extract_order_ids( $command ) {
		$order_ids = [];

		if ( preg_match_all( '/#?(\d+)/', $command, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$id = absint( $id );

				if ( $id > 0 ) {
					$order_ids[] = $id;
				}
			}
		}

		return array_values( array_unique( $order_ids ) );
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
		];

		return $map[ $action ] ?? sanitize_key( str_replace( '.', '_', $action ) );
	}

	private static function build_template_missing_message( $missing ) {
		$missing = is_array( $missing ) ? $missing : [];

		if ( in_array( 'subject', $missing, true ) && in_array( 'body', $missing, true ) ) {
			return __( 'Please provide the subject for this email template.', 'yoro-agent-core' );
		}

		if ( in_array( 'subject', $missing, true ) ) {
			return __( 'Please provide the subject for this email template.', 'yoro-agent-core' );
		}

		if ( in_array( 'body', $missing, true ) ) {
			return __( 'Please provide the body for this email template.', 'yoro-agent-core' );
		}

		return __( 'Please provide the missing template details.', 'yoro-agent-core' );
	}

	private static function get_workflow_from_context( $client_context = [] ) {
		if (
			isset( $client_context['current_workflow'] ) &&
			is_array( $client_context['current_workflow'] )
		) {
			return $client_context['current_workflow'];
		}

		return YoRo_Conversation_Memory::get_workflow( get_current_user_id() );
	}

	private static function get_workflow_ttl() {
		$ttl = absint( get_option( 'yoro_workflow_ttl', 1800 ) );

		return $ttl > 0 ? $ttl : 1800;
	}

	private static function looks_like_customer_email_drafts_anchor_request( $normalized, $has_explicit_order_ids = false, $has_contextual_order_anchor = false ) {
		$has_draft_anchor = (
			false !== strpos( $normalized, 'draft' ) ||
			false !== strpos( $normalized, 'drafts' )
		);

		if ( ! $has_draft_anchor ) {
			return false;
		}

		$has_email_anchor = (
			false !== strpos( $normalized, 'email' ) ||
			false !== strpos( $normalized, 'customer email' )
		);

		$has_order_draft_anchor = (
			false !== strpos( $normalized, 'order' ) ||
			false !== strpos( $normalized, 'this order' ) ||
			false !== strpos( $normalized, 'that order' ) ||
			false !== strpos( $normalized, 'same order' )
		);

		if ( ! $has_email_anchor && ! $has_order_draft_anchor ) {
			return false;
		}

		return $has_explicit_order_ids || $has_contextual_order_anchor;
	}
}
