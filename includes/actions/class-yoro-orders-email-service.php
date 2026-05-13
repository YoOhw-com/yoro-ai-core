<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_Orders_Email_Service {

	public static function email_customer_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$template = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';

		if ( ! $order_id || ! $template ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID and template are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$email = self::build_customer_email_from_template( $order, $template );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		return [
			'success'      => true,
			'action'       => 'orders.email_customer_preview',
			'summary'      => sprintf(
				'Email preview generated for order #%s.',
				$order->get_order_number()
			),
			'message'      => sprintf(
				'Email preview generated for order #%s.',
				$order->get_order_number()
			),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'email'        => [
				'template'  => $email['template'],
				'to'        => $email['to'],
				'subject'   => $email['subject'],
				'body'      => $email['body'],
				'html_body' => self::wrap_customer_email_with_woocommerce_template(
					$email['subject'],
					$email['body']
				),
			],
			'result_type'  => 'customer_email',
			'display_type' => 'customer_email_preview',
			'order'        => self::build_order_context( $order ),
		];
	}

	public static function email_customer_template( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$template = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';

		if ( ! $order_id || ! $template ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID and template are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$email = self::build_customer_email_from_template( $order, $template );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		if ( empty( $email['to'] ) || ! is_email( $email['to'] ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'Order billing email is missing or invalid.', 'yoro-agent-core' )
			);
		}

		$mailer  = WC()->mailer();
		$message = self::wrap_customer_email_with_woocommerce_template(
			$email['subject'],
			$email['body']
		);

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
		];

		$sent = $mailer->send(
			$email['to'],
			$email['subject'],
			$message,
			$headers
		);

		if ( ! $sent ) {
			return new WP_Error(
				'email_send_failed',
				__( 'The email could not be sent.', 'yoro-agent-core' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: template key, 2: recipient email */
				__( 'YoRo Agent sent customer email template "%1$s" to %2$s.', 'yoro-agent-core' ),
				$template,
				$email['to']
			)
		);

		return [
			'success'      => true,
			'action'       => 'orders.email_customer_template',
			'summary'      => sprintf(
				'Sent %s email to customer for order #%s.',
				$template,
				$order->get_order_number()
			),
			'message'      => sprintf(
				'Sent %s email to customer for order #%s.',
				$template,
				$order->get_order_number()
			),
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'template'     => $template,
			'order_id'     => $order->get_id(),
			'order'        => self::build_order_context( $order ),
			'email'        => [
				'template' => $email['template'],
				'to'       => YoRo_Order_Presenter::mask_email( $email['to'] ),
				'subject'  => $email['subject'],
			],
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'customer_email',
			'display_type' => 'single_action',
		];
	}

	public static function use_email_template( $params ) {
		$order_id    = absint( $params['order_id'] ?? 0 );
		$template_id = sanitize_key( $params['template_id'] ?? '' );

		if ( ! $order_id || empty( $template_id ) ) {
			return new WP_Error( 'invalid_params', 'Order ID and template ID required.' );
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error( 'order_not_found', 'Order not found.' );
		}

		$templates = self::get_email_templates();
		$template  = null;

		foreach ( $templates as $tpl ) {
			if ( ! empty( $tpl['id'] ) && $tpl['id'] === $template_id ) {
				$template = $tpl;
				break;
			}
		}

		if ( ! $template ) {
			return new WP_Error( 'template_not_found', 'Template not found.' );
		}

		$subject = self::replace_template_placeholders( $template['subject'], $order );
		$body    = self::replace_template_placeholders( $template['body'], $order );

		return self::render_customer_email_preview(
			[
				'order_id' => $order_id,
				'subject'  => $subject,
				'body'     => $body,
			]
		);
	}

	public static function compose_customer_email( $params ) {
		$order_id    = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$instruction = ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '';

		if ( ! $order_id || empty( $instruction ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID and instruction are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$email = self::compose_customer_email_with_ai( $order, $instruction );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		$email['body'] = self::apply_custom_email_signature( $email['body'] );

		$email['html_body'] = self::wrap_customer_email_with_woocommerce_template(
			$email['subject'],
			$email['body']
		);

		$workflow = [
			'id'         => 'wf_' . wp_generate_uuid4(),
			'type'       => 'customer_email',
			'step'       => 'composed',
			'order_id'   => $order->get_id(),
			'subject'    => $email['subject'],
			'body'       => $email['body'],
			'draft_id'   => '',
			'updated_at' => current_time( 'mysql' ),
		];

		YoRo_Conversation_Memory::set_workflow( get_current_user_id(), $workflow );

		return [
			'success'      => true,
			'action'       => 'orders.compose_customer_email',
			'summary'      => sprintf(
				'Email draft composed for order #%s.',
				$order->get_order_number()
			),
			'message'      => sprintf(
				'Email draft composed for order #%s.',
				$order->get_order_number()
			),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => isset( $email['meta'] ) && is_array( $email['meta'] ) ? $email['meta'] : [],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'email'        => $email,
			'result_type'  => 'customer_email',
			'display_type' => 'customer_email_preview',
			'workflow'     => $workflow,
			'order'        => self::build_order_context( $order ),
			'send_payload' => [
				'order_id' => $order->get_id(),
				'subject'  => $email['subject'],
				'body'     => $email['body'],
			],
			'suggestions'  => self::get_email_suggestions(
				$order,
				$email['subject'],
				$email['body']
			),
		];
	}

	public static function save_email_template( $params ) {
		$name    = ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body    = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';

		if ( empty( $name ) || empty( $subject ) || empty( $body ) ) {
			return new WP_Error( 'invalid_params', __( 'Name, subject, and body are required.', 'yoro-agent-core' ) );
		}

		$templates = self::get_email_templates();

		$template = [
			'id'         => 'tpl_' . wp_generate_uuid4(),
			'name'       => $name,
			'subject'    => $subject,
			'body'       => $body,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		array_unshift( $templates, $template );
		$templates = array_slice( $templates, 0, 50 );

		self::save_email_templates( $templates );

		$current_workflow = YoRo_Conversation_Memory::get_workflow( get_current_user_id() );

		if ( ! empty( $current_workflow ) && is_array( $current_workflow ) && 'customer_email' === sanitize_key( $current_workflow['type'] ?? '' ) ) {
			YoRo_Conversation_Memory::update_workflow(
				get_current_user_id(),
				[
					'step'        => sanitize_key( $current_workflow['step'] ?? 'previewed' ),
					'template_id' => sanitize_key( $template['id'] ),
					'subject'     => $template['subject'],
					'body'        => $template['body'],
				]
			);
		}

		return [
			'success'      => true,
			'action'       => 'email_templates.save',
			'summary'      => 'Saved templates updated.',
			'message'      => 'Saved templates updated.',
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'template'     => $template,
			'templates'    => $templates,
			'processed'    => [ $template['id'] ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'email_templates',
			'display_type' => 'email_templates_list',
		];
	}

	public static function list_email_templates() {
		$templates = self::get_email_templates();

		return [
			'success'      => true,
			'action'       => 'email_templates.list',
			'summary'      => sprintf( 'Saved templates (%d)', count( $templates ) ),
			'message'      => sprintf( 'Saved templates (%d)', count( $templates ) ),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'templates'    => $templates,
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'result_type'  => 'email_templates',
			'display_type' => 'email_templates_list',
			'count'        => count( $templates ),
		];
	}

	public static function delete_email_template( $params ) {
		$template_id = ! empty( $params['template_id'] ) ? sanitize_key( $params['template_id'] ) : '';

		if ( empty( $template_id ) ) {
			return new WP_Error( 'invalid_params', __( 'Template ID is required.', 'yoro-agent-core' ) );
		}

		$templates = self::get_email_templates();
		$remaining = [];
		$deleted   = null;

		foreach ( $templates as $tpl ) {
			if ( ! empty( $tpl['id'] ) && $tpl['id'] === $template_id ) {
				$deleted = $tpl;
				continue;
			}

			$remaining[] = $tpl;
		}

		if ( ! $deleted ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'yoro-agent-core' ) );
		}

		self::save_email_templates( $remaining );

		$current_workflow = YoRo_Conversation_Memory::get_workflow( get_current_user_id() );

		if (
			! empty( $current_workflow ) &&
			is_array( $current_workflow ) &&
			'customer_email' === sanitize_key( $current_workflow['type'] ?? '' ) &&
			sanitize_key( $current_workflow['template_id'] ?? '' ) === $template_id
		) {
			YoRo_Conversation_Memory::update_workflow(
				get_current_user_id(),
				[
					'template_id' => '',
				]
			);
		}

		return [
			'success'      => true,
			'action'       => 'email_templates.delete',
			'summary'      => 'Saved templates updated.',
			'message'      => 'Saved templates updated.',
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'template_id'  => $template_id,
			'deleted'      => $deleted,
			'templates'    => $remaining,
			'processed'    => [ $template_id ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'email_templates',
			'display_type' => 'email_templates_list',
		];
	}

	public static function save_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$subject  = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body     = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';

		if ( ! $order_id || empty( $subject ) || empty( $body ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID, subject, and body are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$drafts = self::get_order_email_drafts( $order );

		$draft = [
			'id'         => 'draft_' . wp_generate_uuid4(),
			'subject'    => $subject,
			'body'       => $body,
			'created_by' => get_current_user_id(),
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
		];

		array_unshift( $drafts, $draft );
		$drafts = array_slice( $drafts, 0, 20 );

		$order->update_meta_data( '_yoro_customer_email_drafts', $drafts );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: email subject */
				__( 'YoRo Agent saved a customer email draft. Subject: %s', 'yoro-agent-core' ),
				$subject
			)
		);

		YoRo_Conversation_Memory::set_workflow(
			get_current_user_id(),
			[
				'id'       => 'wf_' . wp_generate_uuid4(),
				'type'     => 'customer_email',
				'step'     => 'draft_saved',
				'order_id' => $order->get_id(),
				'subject'  => $subject,
				'body'     => $body,
				'draft_id' => $draft['id'],
			]
		);

		return [
			'success'      => true,
			'action'       => 'orders.save_customer_email_draft',
			'summary'      => sprintf( 'Saved email draft for order #%s.', $order->get_order_number() ),
			'message'      => sprintf( 'Saved email draft for order #%s.', $order->get_order_number() ),
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'order_id'     => $order->get_id(),
			'order'        => self::build_order_context( $order ),
			'draft'        => $draft,
			'drafts'       => $drafts,
			'result_type'  => 'customer_email_drafts',
			'display_type' => 'customer_email_drafts',
			'workflow'     => [
				'type'     => 'customer_email',
				'step'     => 'draft_saved',
				'order_id' => $order->get_id(),
				'subject'  => $subject,
				'body'     => $body,
				'draft_id' => $draft['id'],
			],
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
		];
	}

	public static function get_customer_email_drafts( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;

		if ( ! $order_id ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID is required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$drafts = self::get_order_email_drafts( $order );

		return [
			'success'      => true,
			'action'       => 'orders.get_customer_email_drafts',
			'summary'      => sprintf( 'Found %d email drafts for order #%s.', count( $drafts ), $order->get_order_number() ),
			'message'      => sprintf( 'Found %d email drafts for order #%s.', count( $drafts ), $order->get_order_number() ),
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'order'        => self::build_order_context( $order ),
			'drafts'       => $drafts,
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'result_type'  => 'customer_email_drafts',
			'display_type' => 'customer_email_drafts',
			'count'        => count( $drafts ),
		];
	}

	public static function update_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$draft_id = ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '';
		$subject  = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body     = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';

		if ( ! $order_id || empty( $draft_id ) || empty( $subject ) || empty( $body ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID, draft ID, subject, and body are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$drafts  = self::get_order_email_drafts( $order );
		$updated = null;

		foreach ( $drafts as &$draft ) {
			if ( ! empty( $draft['id'] ) && $draft['id'] === $draft_id ) {
				$draft['subject']    = $subject;
				$draft['body']       = $body;
				$draft['updated_at'] = current_time( 'mysql' );
				$updated             = $draft;
				break;
			}
		}
		unset( $draft );

		if ( ! $updated ) {
			return new WP_Error(
				'draft_not_found',
				__( 'Email draft not found.', 'yoro-agent-core' )
			);
		}

		$order->update_meta_data( '_yoro_customer_email_drafts', $drafts );
		$order->save();

		$order->add_order_note(
			sprintf(
				__( 'YoRo Agent updated a customer email draft. Subject: %s', 'yoro-agent-core' ),
				$subject
			)
		);

		YoRo_Conversation_Memory::update_workflow(
			get_current_user_id(),
			[
				'type'     => 'customer_email',
				'step'     => 'draft_saved',
				'order_id' => $order->get_id(),
				'subject'  => $subject,
				'body'     => $body,
				'draft_id' => $draft_id,
			]
		);

		return [
			'success'      => true,
			'action'       => 'orders.update_customer_email_draft',
			'summary'      => sprintf( 'Updated email draft for order #%s.', $order->get_order_number() ),
			'message'      => sprintf( 'Updated email draft for order #%s.', $order->get_order_number() ),
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'order_id'     => $order->get_id(),
			'order'        => self::build_order_context( $order ),
			'draft'        => $updated,
			'drafts'       => $drafts,
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'customer_email_drafts',
			'display_type' => 'customer_email_drafts',
		];
	}

	public static function delete_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$draft_id = ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '';

		if ( ! $order_id || empty( $draft_id ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID and draft ID are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$drafts    = self::get_order_email_drafts( $order );
		$deleted   = null;
		$remaining = [];

		foreach ( $drafts as $draft ) {
			if ( ! empty( $draft['id'] ) && $draft['id'] === $draft_id ) {
				$deleted = $draft;
				continue;
			}

			$remaining[] = $draft;
		}

		if ( ! $deleted ) {
			return new WP_Error(
				'draft_not_found',
				__( 'Email draft not found.', 'yoro-agent-core' )
			);
		}

		$order->update_meta_data( '_yoro_customer_email_drafts', $remaining );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: email subject */
				__( 'YoRo Agent deleted a customer email draft. Subject: %s', 'yoro-agent-core' ),
				$deleted['subject']
			)
		);

		$current_workflow = YoRo_Conversation_Memory::get_workflow( get_current_user_id() );

		if (
			! empty( $current_workflow ) &&
			is_array( $current_workflow ) &&
			'customer_email' === sanitize_key( $current_workflow['type'] ?? '' ) &&
			sanitize_key( $current_workflow['draft_id'] ?? '' ) === $draft_id
		) {
			YoRo_Conversation_Memory::update_workflow(
				get_current_user_id(),
				[
					'draft_id' => '',
					'step'     => 'previewed',
				]
			);
		}

		return [
			'success'      => true,
			'action'       => 'orders.delete_customer_email_draft',
			'summary'      => sprintf( 'Deleted email draft from order #%s.', $order->get_order_number() ),
			'message'      => sprintf( 'Deleted email draft from order #%s.', $order->get_order_number() ),
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'order_id'     => $order->get_id(),
			'order'        => self::build_order_context( $order ),
			'draft_id'     => $draft_id,
			'deleted'      => $deleted,
			'drafts'       => $remaining,
			'result_type'  => 'customer_email_drafts',
			'display_type' => 'customer_email_drafts',
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
		];
	}

	public static function render_customer_email_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$subject  = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body     = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$preview_source = ! empty( $params['preview_source'] ) ? sanitize_key( $params['preview_source'] ) : 'initial';

		if ( ! $order_id || empty( $subject ) || empty( $body ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID, subject, and body are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$to = sanitize_email( $order->get_billing_email() );

		if ( empty( $to ) || ! is_email( $to ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'Order billing email is missing or invalid.', 'yoro-agent-core' )
			);
		}

		$user_id          = get_current_user_id();
		$current_workflow = YoRo_Conversation_Memory::get_workflow( $user_id );

		if (
			! empty( $current_workflow ) &&
			isset( $current_workflow['type'] ) &&
			'customer_email' === $current_workflow['type']
		) {
			$workflow = array_merge(
				$current_workflow,
				[
					'step'     => 'previewed',
					'order_id' => $order->get_id(),
					'subject'  => $subject,
					'body'     => $body,
				]
			);

			YoRo_Conversation_Memory::update_workflow( $user_id, $workflow );
		} else {
			$workflow = [
				'id'         => 'wf_' . wp_generate_uuid4(),
				'type'       => 'customer_email',
				'step'       => 'previewed',
				'order_id'   => $order->get_id(),
				'subject'    => $subject,
				'body'       => $body,
				'draft_id'   => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
				'updated_at' => current_time( 'mysql' ),
			];

			YoRo_Conversation_Memory::set_workflow( $user_id, $workflow );
		}

		return [
			'success'      => true,
			'action'       => 'orders.render_customer_email_preview',
			'summary'      => sprintf(
				'draft' === $preview_source ? 'Loaded saved draft for order #%s.' : 'Customer email preview ready for order #%s.',
				$order->get_order_number()
			),
			'message'      => sprintf(
				'draft' === $preview_source ? 'Loaded saved draft for order #%s.' : 'Customer email preview ready for order #%s.',
				$order->get_order_number()
			),
			'preview_source' => $preview_source,
			'read_only'    => true,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'processed'    => [],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'customer_email',
			'display_type' => 'customer_email_preview',
			'workflow'     => $workflow,
			'email'        => [
				'template'  => 'ai_custom',
				'to'        => $to,
				'subject'   => $subject,
				'body'      => $body,
				'html_body' => self::wrap_customer_email_with_woocommerce_template( $subject, $body ),
			],
			'order'        => self::build_order_context( $order ),
			'send_payload' => [
				'order_id' => $order->get_id(),
				'subject'  => $subject,
				'body'     => $body,
				'draft_id' => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
			],
		];
	}

	public static function send_customer_email( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$subject  = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body     = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';

		if ( ! $order_id || empty( $subject ) || empty( $body ) ) {
			return new WP_Error(
				'invalid_params',
				__( 'Order ID, subject, and body are required.', 'yoro-agent-core' )
			);
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'order_not_found',
				__( 'Order not found.', 'yoro-agent-core' )
			);
		}

		$to = sanitize_email( $order->get_billing_email() );

		if ( empty( $to ) || ! is_email( $to ) ) {
			return new WP_Error(
				'invalid_recipient',
				__( 'Order billing email is missing or invalid.', 'yoro-agent-core' )
			);
		}

		$message = self::wrap_customer_email_with_woocommerce_template( $subject, $body );

		$sent = WC()->mailer()->send(
			$to,
			$subject,
			$message,
			[ 'Content-Type: text/html; charset=UTF-8' ]
		);

		if ( ! $sent ) {
			return new WP_Error(
				'email_send_failed',
				__( 'The email could not be sent.', 'yoro-agent-core' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: recipient email, 2: subject */
				__( 'YoRo Agent sent a custom customer email to %1$s. Subject: %2$s', 'yoro-agent-core' ),
				$to,
				$subject
			)
		);

		$user_id = get_current_user_id();

		YoRo_Conversation_Memory::update_workflow(
			$user_id,
			[
				'step'     => 'sent',
				'order_id' => $order->get_id(),
				'subject'  => $subject,
				'body'     => $body,
			]
		);

		YoRo_Conversation_Memory::clear_workflow( $user_id );

		return [
			'success'      => true,
			'action'       => 'orders.send_customer_email',
			'summary'      => sprintf( 'Sent customer email for order #%s.', $order->get_order_number() ),
			'message'      => sprintf( 'Sent customer email for order #%s.', $order->get_order_number() ),
			'read_only'    => false,
			'undoable'     => false,
			'undo_plan'    => [],
			'data'         => [],
			'meta'         => [],
			'order_id'     => $order->get_id(),
			'order'        => self::build_order_context( $order ),
			'email'        => [
				'to'      => YoRo_Order_Presenter::mask_email( $to ),
				'subject' => $subject,
			],
			'processed'    => [ $order->get_id() ],
			'skipped'      => [],
			'errors'       => [],
			'count'        => 1,
			'result_type'  => 'customer_email',
			'display_type' => 'single_action',
		];
	}

	public static function preview_email_customer_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Preview customer email from template',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'template'      => ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '',
			'changes_store' => false,
		];
	}

	public static function preview_email_customer_template( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$template = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;
		$email = null;

		if ( $order && $template ) {
			$email = self::build_customer_email_from_template( $order, $template );
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Send customer email from template',
			'order_id'      => $order_id,
			'template'      => $template,
			'recipient'     => $order ? YoRo_Order_Presenter::mask_email( $order->get_billing_email() ) : '',
			'subject'       => ( is_array( $email ) && ! empty( $email['subject'] ) ) ? $email['subject'] : '',
			'changes_store' => true,
		];
	}

	public static function preview_compose_customer_email( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Compose customer email draft',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'instruction'   => ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '',
			'changes_store' => false,
		];
	}

	public static function preview_save_customer_email_draft( $params ) {
		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Save customer email draft',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'subject'       => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
			'body_preview'  => ! empty( $params['body'] ) ? wp_trim_words( sanitize_textarea_field( $params['body'] ), 40 ) : '',
			'changes_store' => true,
		];
	}

	public static function preview_get_customer_email_drafts( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Load customer email drafts',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'changes_store' => false,
		];
	}

	public static function preview_update_customer_email_draft( $params ) {
		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Update customer email draft',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'draft_id'      => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
			'subject'       => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
			'body_preview'  => ! empty( $params['body'] ) ? wp_trim_words( sanitize_textarea_field( $params['body'] ), 40 ) : '',
			'changes_store' => true,
		];
	}

	public static function preview_delete_customer_email_draft( $params ) {
		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Delete customer email draft',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'draft_id'      => ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '',
			'changes_store' => true,
		];
	}

	public static function preview_render_customer_email_preview( $params ) {
		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Render WooCommerce customer email preview',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'subject'       => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
			'body_preview'  => ! empty( $params['body'] ) ? wp_trim_words( sanitize_textarea_field( $params['body'] ), 40 ) : '',
			'changes_store' => false,
		];
	}

	public static function preview_send_customer_email( $params ) {
		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Send reviewed customer email',
			'order_id'      => ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0,
			'subject'       => ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '',
			'body_preview'  => ! empty( $params['body'] ) ? wp_trim_words( sanitize_textarea_field( $params['body'] ), 40 ) : '',
			'changes_store' => true,
		];
	}

	public static function get_email_templates() {
		$templates = get_option( 'yoro_customer_email_templates', [] );

		return is_array( $templates ) ? $templates : [];
	}

	public static function save_email_templates( $templates ) {
		update_option( 'yoro_customer_email_templates', array_values( $templates ) );
	}

	private static function build_order_context( WC_Order $order ) {
		return [
			'id'            => $order->get_id(),
			'number'        => $order->get_order_number(),
			'status'        => $order->get_status(),
			'billing_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email' => YoRo_Order_Presenter::mask_email( $order->get_billing_email() ),
			'edit_url'      => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
		];
	}

	private static function get_email_suggestions( WC_Order $order, $subject, $body ) {
		$templates   = self::get_email_templates();
		$suggestions = [];

		if ( empty( $templates ) ) {
			return [];
		}

		$haystack = strtolower( $subject . ' ' . $body );

		$intent_keywords = [
			'verification' => [ 'verify', 'verification', 'confirm', 'confirmation', 'review' ],
			'address'      => [ 'address', 'shipping address', 'billing address' ],
			'payment'      => [ 'payment', 'transaction', 'card', 'paypal', 'mismatch' ],
			'shipping'     => [ 'shipping', 'delivery', 'shipment', 'delay', 'delayed' ],
			'followup'     => [ 'follow up', 'follow-up', 'update', 'check' ],
			'apology'      => [ 'sorry', 'apologize', 'apology', 'inconvenience' ],
		];

		$detected_intents = [];

		foreach ( $intent_keywords as $intent => $keywords ) {
			foreach ( $keywords as $kw ) {
				if ( false !== strpos( $haystack, $kw ) ) {
					$detected_intents[] = $intent;
					break;
				}
			}
		}

		if ( empty( $detected_intents ) ) {
			return [];
		}

		foreach ( $templates as $tpl ) {
			$template_text = strtolower(
				( $tpl['name'] ?? '' ) . ' ' .
				( $tpl['subject'] ?? '' ) . ' ' .
				( $tpl['body'] ?? '' )
			);

			$score           = 0;
			$matched_intents = [];

			foreach ( $detected_intents as $intent ) {
				foreach ( $intent_keywords[ $intent ] as $kw ) {
					if ( false !== strpos( $template_text, $kw ) ) {
						$score++;
						$matched_intents[] = $intent;
						break;
					}
				}
			}

			$confidence = min( 1, round( $score / max( count( $detected_intents ), 1 ), 2 ) );

			if ( $score > 0 && $confidence >= 0.5 ) {
				$suggestions[] = [
					'type'        => 'use_template',
					'label'       => 'Use template: ' . sanitize_text_field( $tpl['name'] ?? '' ),
					'template_id' => sanitize_key( $tpl['id'] ?? '' ),
					'confidence'  => $confidence,
					'reason'      => 'Matched intent: ' . implode( ', ', array_unique( $matched_intents ) ),
					'intents'     => array_values( array_unique( $matched_intents ) ),
					'score'       => $score,
				];
			}
		}

		usort(
			$suggestions,
			function( $a, $b ) {
				return ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
			}
		);

		$suggestions = array_values(
			array_filter(
				$suggestions,
				function( $suggestion ) {
					return ! empty( $suggestion['template_id'] )
						&& ! empty( $suggestion['label'] )
						&& isset( $suggestion['confidence'] )
						&& (float) $suggestion['confidence'] >= 0.5;
				}
			)
		);

		return array_slice( $suggestions, 0, 3 );
	}

	private static function wrap_customer_email_with_woocommerce_template( $heading, $body ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return nl2br( esc_html( $body ) );
		}

		$mailer = WC()->mailer();

		ob_start();

		do_action( 'woocommerce_email_header', $heading, null );

		echo wp_kses_post( wpautop( esc_html( $body ) ) );

		do_action( 'woocommerce_email_footer', null );

		$message = ob_get_clean();

		$emails = $mailer->get_emails();

		if ( ! empty( $emails ) && is_array( $emails ) ) {
			$email = reset( $emails );

			if ( $email && method_exists( $email, 'style_inline' ) ) {
				return $email->style_inline( $message );
			}
		}

		return $message;
	}

	private static function replace_template_placeholders( $text, WC_Order $order ) {
		$map = [
			'{order_number}'  => $order->get_order_number(),
			'{customer_name}' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'{order_total}'   => wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ),
			'{order_status}'  => wc_get_order_status_name( $order->get_status() ),
		];

		return strtr( $text, $map );
	}

	private static function build_customer_email_from_template( WC_Order $order, $template ) {
		$templates = self::get_customer_email_templates();

		if ( empty( $templates[ $template ] ) ) {
			return new WP_Error(
				'invalid_template',
				__( 'Invalid email template.', 'yoro-agent-core' )
			);
		}

		$customer_name = trim( $order->get_billing_first_name() );

		if ( '' === $customer_name ) {
			$customer_name = __( 'there', 'yoro-agent-core' );
		}

		$replacements = [
			'{customer_name}' => $customer_name,
			'{order_number}'  => $order->get_order_number(),
			'{store_name}'    => get_bloginfo( 'name' ),
			'{order_total}'   => html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ) ) ),
		];

		$template_data = $templates[ $template ];

		return [
			'template' => $template,
			'to'       => sanitize_email( $order->get_billing_email() ),
			'subject'  => strtr( $template_data['subject'], $replacements ),
			'body'     => strtr( $template_data['body'], $replacements ),
		];
	}

	private static function get_customer_email_templates() {
		return [
			'needs_verification' => [
				'subject' => __( 'Action required for your order #{order_number}', 'yoro-agent-core' ),
				'body'    => __( "Hi {customer_name},\n\nThank you for your order #{order_number} at {store_name}.\n\nBefore we continue processing your order, we need to verify a few details. Please reply to this email so our team can complete the review.\n\nOrder total: {order_total}\n\nThank you.", 'yoro-agent-core' ),
			],
			'payment_mismatch' => [
				'subject' => __( 'Payment verification needed for order #{order_number}', 'yoro-agent-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are reviewing your order #{order_number} at {store_name}.\n\nSome payment details require confirmation before we can continue processing the order. Please reply to this email with confirmation of your order details.\n\nOrder total: {order_total}\n\nThank you.", 'yoro-agent-core' ),
			],
			'shipping_delay' => [
				'subject' => __( 'Shipping update for order #{order_number}', 'yoro-agent-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are contacting you about order #{order_number} from {store_name}.\n\nThere may be a short delay in shipping your order. Our team is reviewing it and will update you as soon as possible.\n\nThank you for your patience.", 'yoro-agent-core' ),
			],
			'order_followup' => [
				'subject' => __( 'Follow-up about your order #{order_number}', 'yoro-agent-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are following up about your order #{order_number} at {store_name}.\n\nPlease reply to this email if you have any questions or need to update any order details.\n\nThank you.", 'yoro-agent-core' ),
			],
		];
	}

	private static function compose_customer_email_with_ai( WC_Order $order, $instruction ) {
		if ( 'yes' !== get_option( 'yoro_ai_email_compose_enabled', 'yes' ) ) {
			return new WP_Error(
				'yoro_ai_email_disabled',
				__( 'AI customer email composing is disabled.', 'yoro-agent-core' )
			);
		}

		return YoRo_AI_Parser::compose_email( $order, $instruction );
	}

	private static function apply_custom_email_signature( $body ) {
		$body = self::strip_generated_email_signature( $body );

		$signature_lines = self::get_custom_email_signature_lines();

		if ( empty( $signature_lines ) ) {
			return trim( (string) $body );
		}

		return trim( (string) $body ) . "\n\n" . implode( "\n", $signature_lines );
	}

	private static function get_custom_email_signature_lines() {
		$user       = wp_get_current_user();
		$site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$user_name  = $user instanceof WP_User ? trim( (string) $user->display_name ) : '';
		$site_name  = trim( (string) $site_name );
		$signature  = [ __( 'Best regards,', 'yoro-agent-core' ) ];

		if ( '' !== $user_name ) {
			$signature[] = $user_name;
		}

		if ( '' !== $site_name ) {
			$signature[] = $site_name;
		}

		return array_values(
			array_filter(
				$signature,
				static function( $line ) {
					return '' !== trim( (string) $line );
				}
			)
		);
	}

	private static function strip_generated_email_signature( $body ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return '';
		}

		$patterns = [
			'/\n{2,}(best regards|regards|kind regards|thanks|thank you|sincerely|cheers),?\s*\n.*$/is',
			'/\n{2,}.*\b(shop support|store support|support team|customer support|customer care)\b.*$/is',
		];

		foreach ( $patterns as $pattern ) {
			$updated = preg_replace( $pattern, '', $body );

			if ( is_string( $updated ) && $updated !== $body ) {
				$body = trim( $updated );
				break;
			}
		}

		return $body;
	}

	private static function get_order_email_drafts( WC_Order $order ) {
		$drafts = $order->get_meta( '_yoro_customer_email_drafts', true );

		if ( ! is_array( $drafts ) ) {
			return [];
		}

		$clean = [];

		foreach ( $drafts as $draft ) {
			if ( ! is_array( $draft ) ) {
				continue;
			}

			$id      = ! empty( $draft['id'] ) ? sanitize_key( $draft['id'] ) : '';
			$subject = ! empty( $draft['subject'] ) ? sanitize_text_field( $draft['subject'] ) : '';
			$body    = ! empty( $draft['body'] ) ? sanitize_textarea_field( $draft['body'] ) : '';

			if ( empty( $id ) || empty( $subject ) || empty( $body ) ) {
				continue;
			}

			$clean[] = [
				'id'         => $id,
				'subject'    => $subject,
				'body'       => $body,
				'created_by' => ! empty( $draft['created_by'] ) ? absint( $draft['created_by'] ) : 0,
				'created_at' => ! empty( $draft['created_at'] ) ? sanitize_text_field( $draft['created_at'] ) : '',
				'updated_at' => ! empty( $draft['updated_at'] ) ? sanitize_text_field( $draft['updated_at'] ) : '',
			];
		}

		return $clean;
	}
}
