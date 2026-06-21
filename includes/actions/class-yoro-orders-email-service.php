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
					__( 'Please choose an order and an email template first.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
			'send_payload' => [
				'order_id' => $order->get_id(),
				'subject'  => $email['subject'],
				'body'     => $email['body'],
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
					__( 'Please choose an order and an email template first.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
				);
			}

		$email = self::build_customer_email_from_template( $order, $template );

		if ( is_wp_error( $email ) ) {
			return $email;
		}

		if ( empty( $email['to'] ) || ! is_email( $email['to'] ) ) {
			return new WP_Error(
					'invalid_recipient',
					__( 'That order does not have a valid customer email address.', 'yoro-ai-core' )
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
					__( 'I could not send the email. Please try again.', 'yoro-ai-core' )
				);
			}

		$order->add_order_note(
			sprintf(
				/* translators: 1: template key, 2: recipient email */
				__( 'YoRo AI sent customer email template "%1$s" to %2$s.', 'yoro-ai-core' ),
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
				return new WP_Error( 'invalid_params', __( 'Please choose an order and an email template first.', 'yoro-ai-core' ) );
			}

		$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return new WP_Error( 'order_not_found', __( 'I could not find that order.', 'yoro-ai-core' ) );
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
				return new WP_Error( 'template_not_found', __( 'I could not find that saved template.', 'yoro-ai-core' ) );
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
					__( 'Please choose an order and describe what the email should say.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
			];
		}

	public static function save_email_template( $params ) {
		$name    = ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body    = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';

			if ( empty( $name ) || empty( $subject ) || empty( $body ) ) {
				return new WP_Error( 'invalid_params', __( 'Please name the template and make sure it has both a subject and body.', 'yoro-ai-core' ) );
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
			'template'     => self::format_email_template_record( $template ),
			'templates'    => self::format_email_template_records( $templates ),
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
			'templates'    => self::format_email_template_records( $templates ),
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
				return new WP_Error( 'invalid_params', __( 'I could not identify that saved template. Please choose it again.', 'yoro-ai-core' ) );
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
				return new WP_Error( 'not_found', __( 'I could not find that saved template.', 'yoro-ai-core' ) );
			}

		self::save_email_templates( $remaining );

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
					__( 'Please open an order and make sure the email has both a subject and body.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
				__( 'YoRo AI saved a customer email draft. Subject: %s', 'yoro-ai-core' ),
				$subject
			)
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
			'draft'        => self::format_email_draft_record( $draft ),
			'drafts'       => self::format_email_draft_records( $drafts ),
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
					__( 'Please open an order first.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
			'drafts'       => self::format_email_draft_records( $drafts ),
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
					__( 'Please reopen the saved draft and make sure it has both a subject and body.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
					__( 'I could not find that saved draft.', 'yoro-ai-core' )
				);
			}

		$order->update_meta_data( '_yoro_customer_email_drafts', $drafts );
		$order->save();

		$order->add_order_note(
			sprintf(
				__( 'YoRo AI updated a customer email draft. Subject: %s', 'yoro-ai-core' ),
				$subject
			)
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
			'draft'        => self::format_email_draft_record( $updated ),
			'drafts'       => self::format_email_draft_records( $drafts ),
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
					__( 'Please reopen the saved draft from the order.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
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
				__( 'Email draft not found.', 'yoro-ai-core' )
			);
		}

		$order->update_meta_data( '_yoro_customer_email_drafts', $remaining );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: %s: email subject */
				__( 'YoRo AI deleted a customer email draft. Subject: %s', 'yoro-ai-core' ),
				$deleted['subject']
			)
		);

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
			'deleted'      => self::format_email_draft_record( $deleted ),
			'drafts'       => self::format_email_draft_records( $remaining ),
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
					__( 'Please open an order and make sure the email has both a subject and body.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
				);
			}

		$to = sanitize_email( $order->get_billing_email() );

		if ( empty( $to ) || ! is_email( $to ) ) {
			return new WP_Error(
					'invalid_recipient',
					__( 'That order does not have a valid customer email address.', 'yoro-ai-core' )
				);
			}

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
					__( 'Please open an order and make sure the email has both a subject and body.', 'yoro-ai-core' )
				);
			}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
					'order_not_found',
					__( 'I could not find that order.', 'yoro-ai-core' )
				);
			}

		$to = sanitize_email( $order->get_billing_email() );

		if ( empty( $to ) || ! is_email( $to ) ) {
			return new WP_Error(
					'invalid_recipient',
					__( 'That order does not have a valid customer email address.', 'yoro-ai-core' )
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
				__( 'The email could not be sent.', 'yoro-ai-core' )
			);
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: recipient email, 2: subject */
				__( 'YoRo AI sent a custom customer email to %1$s. Subject: %2$s', 'yoro-ai-core' ),
				$to,
				$subject
			)
		);

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
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$template = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$email = null;
		$block = self::build_order_write_preview_block(
			$order_id,
			$order,
			__( 'This customer email preview is ready.', 'yoro-ai-core' )
		);

		if ( $order && $template ) {
			$email = self::build_customer_email_from_template( $order, $template );

			if ( is_wp_error( $email ) ) {
				$block = self::preview_block(
					'template_not_found',
					__( 'I could not find that email template. Choose an existing template before continuing.', 'yoro-ai-core' )
				);
				$email = null;
			}
		}

		if ( '' === $block['block_reason'] && '' === $template ) {
			$block = self::preview_block(
				'missing_template',
				__( 'Please choose an email template before this can continue.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Preview customer email from template',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'template'      => $template,
			'subject'       => ( is_array( $email ) && ! empty( $email['subject'] ) ) ? $email['subject'] : '',
			'changes_store' => false,
		];
	}

	public static function preview_email_customer_template( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$template = ! empty( $params['template'] ) ? sanitize_key( $params['template'] ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;
		$email = null;
		$block = self::build_order_write_preview_block(
			$order_id,
			$order,
			__( 'This customer email is ready for approval.', 'yoro-ai-core' )
		);

		if ( $order && $template ) {
			$email = self::build_customer_email_from_template( $order, $template );

			if ( is_wp_error( $email ) ) {
				$block = self::preview_block(
					'template_not_found',
					__( 'I could not find that email template. Choose an existing template before approving this change.', 'yoro-ai-core' )
				);
				$email = null;
			}
		}

		if ( '' === $block['block_reason'] && '' === $template ) {
			$block = self::preview_block(
				'missing_template',
				__( 'Please choose an email template before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && is_array( $email ) && ! is_email( $email['to'] ?? '' ) ) {
			$block = self::preview_block(
				'invalid_recipient',
				__( 'This order does not have a valid customer email address, so the email cannot be approved for sending.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Send customer email from template',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'template'      => $template,
			'recipient'     => $order ? YoRo_Order_Presenter::mask_email( $order->get_billing_email() ) : '',
			'subject'       => ( is_array( $email ) && ! empty( $email['subject'] ) ) ? $email['subject'] : '',
			'changes_store' => '' === $block['block_reason'],
		];
	}

	public static function preview_compose_customer_email( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$instruction = ! empty( $params['instruction'] ) ? sanitize_textarea_field( $params['instruction'] ) : '';
		$block = self::build_order_write_preview_block(
			$order_id,
			$order,
			__( 'This customer email draft request is ready.', 'yoro-ai-core' )
		);

		if ( '' === $block['block_reason'] && '' === $instruction ) {
			$block = self::preview_block(
				'missing_instruction',
				__( 'Please describe what the customer email should say before this can continue.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Compose customer email draft',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'instruction'   => $instruction,
			'changes_store' => false,
		];
	}

	public static function preview_save_email_template( $params ) {
		$name    = ! empty( $params['name'] ) ? sanitize_text_field( $params['name'] ) : '';
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body    = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$block   = self::preview_block(
			'',
			__( 'This email template is ready to save.', 'yoro-ai-core' )
		);

		if ( '' === $name ) {
			$block = self::preview_block(
				'missing_template_name',
				__( 'Please provide a template name before this can be saved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $subject ) {
			$block = self::preview_block(
				'missing_subject',
				__( 'Please provide the template subject before this can be saved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $body ) {
			$block = self::preview_block(
				'missing_body',
				__( 'Please provide the template body before this can be saved.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Save email template',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'name'          => $name,
			'subject'       => $subject,
			'body_preview'  => '' !== $body ? wp_trim_words( $body, 40 ) : '',
			'changes_store' => '' === $block['block_reason'],
		];
	}

	public static function preview_delete_email_template( $params ) {
		$template_id = ! empty( $params['template_id'] ) ? sanitize_key( $params['template_id'] ) : '';
		$template    = null;
		$block       = self::preview_block(
			'',
			__( 'This email template deletion is ready for approval.', 'yoro-ai-core' )
		);

		if ( '' === $template_id ) {
			$block = self::preview_block(
				'missing_template_id',
				__( 'Please choose an email template before this can be approved.', 'yoro-ai-core' )
			);
		} else {
			foreach ( self::get_email_templates() as $candidate ) {
				if ( is_array( $candidate ) && ! empty( $candidate['id'] ) && $candidate['id'] === $template_id ) {
					$template = $candidate;
					break;
				}
			}

			if ( ! $template ) {
				$block = self::preview_block(
					'template_not_found',
					__( 'I could not find that saved email template. Choose an existing template before approving this change.', 'yoro-ai-core' )
				);
			}
		}

		return [
			'type'            => 'write',
			'will_execute'    => false,
			'action'          => 'Delete email template',
			'summary'         => $block['message'],
			'message'         => $block['message'],
			'blocked'         => '' !== $block['block_reason'],
			'block_reason'    => $block['block_reason'],
			'template_id'     => $template_id,
			'template_exists' => (bool) $template,
			'template'        => $template ? self::format_email_template_record( $template ) : [],
			'changes_store'   => '' === $block['block_reason'],
		];
	}

	public static function preview_save_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$block = self::build_order_write_preview_block( $order_id, $order );

		if ( '' === $block['block_reason'] && '' === $subject ) {
			$block = self::preview_block(
				'missing_subject',
				__( 'Please provide the email subject before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && '' === $body ) {
			$block = self::preview_block(
				'missing_body',
				__( 'Please provide the email body before this can be approved.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Save customer email draft',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'subject'       => $subject,
			'body_preview'  => '' !== $body ? wp_trim_words( $body, 40 ) : '',
			'changes_store' => '' === $block['block_reason'],
		];
	}

	public static function preview_get_customer_email_drafts( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$block = self::build_order_write_preview_block(
			$order_id,
			$order,
			__( 'This email draft lookup is ready.', 'yoro-ai-core' )
		);

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Load customer email drafts',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'changes_store' => false,
		];
	}

	public static function preview_update_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$draft_id = ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '';
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$block = self::build_order_write_preview_block( $order_id, $order );

		if ( '' === $block['block_reason'] && '' === $draft_id ) {
			$block = self::preview_block(
				'missing_draft_id',
				__( 'Please choose an email draft before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && $order && ! self::find_order_email_draft( $order, $draft_id ) ) {
			$block = self::preview_block(
				'draft_not_found',
				__( 'I could not find that saved email draft. Choose an existing draft before continuing.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && '' === $subject ) {
			$block = self::preview_block(
				'missing_subject',
				__( 'Please provide the email subject before this can continue.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && '' === $body ) {
			$block = self::preview_block(
				'missing_body',
				__( 'Please provide the email body before this can continue.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Update customer email draft',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'draft_id'      => $draft_id,
			'draft_exists'  => $order && '' !== $draft_id ? (bool) self::find_order_email_draft( $order, $draft_id ) : false,
			'subject'       => $subject,
			'body_preview'  => '' !== $body ? wp_trim_words( $body, 40 ) : '',
			'changes_store' => '' === $block['block_reason'],
		];
	}

	public static function preview_delete_customer_email_draft( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$draft_id = ! empty( $params['draft_id'] ) ? sanitize_key( $params['draft_id'] ) : '';
		$block = self::build_order_write_preview_block( $order_id, $order );

		if ( '' === $block['block_reason'] && '' === $draft_id ) {
			$block = self::preview_block(
				'missing_draft_id',
				__( 'Please choose an email draft before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && $order && ! self::find_order_email_draft( $order, $draft_id ) ) {
			$block = self::preview_block(
				'draft_not_found',
				__( 'I could not find that saved email draft. Choose an existing draft before approving this change.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Delete customer email draft',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'draft_id'      => $draft_id,
			'draft_exists'  => $order && '' !== $draft_id ? (bool) self::find_order_email_draft( $order, $draft_id ) : false,
			'changes_store' => '' === $block['block_reason'],
		];
	}

	public static function preview_render_customer_email_preview( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$block = self::build_order_write_preview_block(
			$order_id,
			$order,
			__( 'This customer email preview is ready.', 'yoro-ai-core' )
		);

		if ( '' === $block['block_reason'] && '' === $subject ) {
			$block = self::preview_block(
				'missing_subject',
				__( 'Please provide the email subject before this can continue.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && '' === $body ) {
			$block = self::preview_block(
				'missing_body',
				__( 'Please provide the email body before this can continue.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && $order && ! is_email( $order->get_billing_email() ) ) {
			$block = self::preview_block(
				'invalid_recipient',
				__( 'This order does not have a valid customer email address, so the preview cannot continue.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'read',
			'will_execute'  => false,
			'action'        => 'Render store customer email preview',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'subject'       => $subject,
			'body_preview'  => '' !== $body ? wp_trim_words( $body, 40 ) : '',
			'changes_store' => false,
		];
	}

	public static function preview_send_customer_email( $params ) {
		$order_id = ! empty( $params['order_id'] ) ? absint( $params['order_id'] ) : 0;
		$order = $order_id ? wc_get_order( $order_id ) : false;
		$subject = ! empty( $params['subject'] ) ? sanitize_text_field( $params['subject'] ) : '';
		$body = ! empty( $params['body'] ) ? sanitize_textarea_field( $params['body'] ) : '';
		$block = self::build_order_write_preview_block( $order_id, $order );

		if ( '' === $block['block_reason'] && '' === $subject ) {
			$block = self::preview_block(
				'missing_subject',
				__( 'Please provide the email subject before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && '' === $body ) {
			$block = self::preview_block(
				'missing_body',
				__( 'Please provide the email body before this can be approved.', 'yoro-ai-core' )
			);
		} elseif ( '' === $block['block_reason'] && $order && ! is_email( $order->get_billing_email() ) ) {
			$block = self::preview_block(
				'missing_recipient',
				__( 'This order does not have a customer email address, so the email cannot be approved for sending.', 'yoro-ai-core' )
			);
		}

		return [
			'type'          => 'write',
			'will_execute'  => false,
			'action'        => 'Send reviewed customer email',
			'summary'       => $block['message'],
			'message'       => $block['message'],
			'blocked'       => '' !== $block['block_reason'],
			'block_reason'  => $block['block_reason'],
			'order_id'      => $order_id,
			'order_exists'  => (bool) $order,
			'recipient'     => $order ? YoRo_Order_Presenter::mask_email( $order->get_billing_email() ) : '',
			'subject'       => $subject,
			'body_preview'  => '' !== $body ? wp_trim_words( $body, 40 ) : '',
			'changes_store' => '' === $block['block_reason'],
		];
	}

	private static function build_order_write_preview_block( $order_id, $order, $ready_message = '' ) {
		$order_id = absint( $order_id );

		if ( $order_id <= 0 ) {
			return self::preview_block(
				'missing_order_target',
				__( 'Please choose an order before this can continue.', 'yoro-ai-core' )
			);
		}

		if ( ! $order ) {
			return self::preview_block(
				'order_not_found',
				sprintf(
					/* translators: %d: order ID */
					__( 'I could not find order %d. Choose an existing order before continuing.', 'yoro-ai-core' ),
					$order_id
				)
			);
		}

		return self::preview_block(
			'',
			'' !== $ready_message ? $ready_message : __( 'This customer email change is ready for approval.', 'yoro-ai-core' )
		);
	}

	private static function preview_block( $reason, $message ) {
		return [
			'block_reason' => sanitize_key( $reason ),
			'message'      => sanitize_textarea_field( $message ),
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
			'status_label'  => YoRo_Order_Helper::get_order_status_label( $order->get_status() ),
			'billing_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email' => YoRo_Order_Presenter::mask_email( $order->get_billing_email() ),
			'edit_url'      => method_exists( $order, 'get_edit_order_url' ) ? $order->get_edit_order_url() : admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' ),
		];
	}

	public static function render_with_woocommerce_email_template( $heading, $body ) {
		return self::wrap_customer_email_with_woocommerce_template(
			sanitize_text_field( (string) $heading ),
			sanitize_textarea_field( (string) $body )
		);
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
				__( 'Invalid email template.', 'yoro-ai-core' )
			);
		}

		$customer_name = trim( $order->get_billing_first_name() );

		if ( '' === $customer_name ) {
			$customer_name = __( 'there', 'yoro-ai-core' );
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
				'subject' => __( 'Action required for your order #{order_number}', 'yoro-ai-core' ),
				'body'    => __( "Hi {customer_name},\n\nThank you for your order #{order_number} at {store_name}.\n\nBefore we continue processing your order, we need to verify a few details. Please reply to this email so our team can complete the review.\n\nOrder total: {order_total}\n\nThank you.", 'yoro-ai-core' ),
			],
			'payment_mismatch' => [
				'subject' => __( 'Payment verification needed for order #{order_number}', 'yoro-ai-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are reviewing your order #{order_number} at {store_name}.\n\nSome payment details require confirmation before we can continue processing the order. Please reply to this email with confirmation of your order details.\n\nOrder total: {order_total}\n\nThank you.", 'yoro-ai-core' ),
			],
			'shipping_delay' => [
				'subject' => __( 'Shipping update for order #{order_number}', 'yoro-ai-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are contacting you about order #{order_number} from {store_name}.\n\nThere may be a short delay in shipping your order. Our team is reviewing it and will update you as soon as possible.\n\nThank you for your patience.", 'yoro-ai-core' ),
			],
			'order_followup' => [
				'subject' => __( 'Follow-up about your order #{order_number}', 'yoro-ai-core' ),
				'body'    => __( "Hi {customer_name},\n\nWe are following up about your order #{order_number} at {store_name}.\n\nPlease reply to this email if you have any questions or need to update any order details.\n\nThank you.", 'yoro-ai-core' ),
			],
		];
	}

	private static function compose_customer_email_with_ai( WC_Order $order, $instruction ) {
		if ( 'yes' !== get_option( 'yoro_ai_email_compose_enabled', 'yes' ) ) {
			return new WP_Error(
				'yoro_ai_email_disabled',
				__( 'AI email drafting is currently disabled.', 'yoro-ai-core' )
			);
		}

		return YoRo_AI_Email_Composer::compose( $order, $instruction );
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
		$signature  = [];

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
			'/(?:\r?\n){1,}(best regards|regards|kind regards|thanks|thank you|sincerely|cheers),?\s*(?:\r?\n)+.*$/is',
			'/(?:\r?\n){1,}(best regards|regards|kind regards|thanks|thank you|sincerely|cheers),?\s*$/is',
			'/(?:\r?\n){1,}.*\b(shop support|store support|support team|customer support|customer care)\b.*$/is',
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

	private static function find_order_email_draft( WC_Order $order, $draft_id ) {
		$draft_id = sanitize_key( $draft_id );

		if ( '' === $draft_id ) {
			return null;
		}

		foreach ( self::get_order_email_drafts( $order ) as $draft ) {
			if ( is_array( $draft ) && ! empty( $draft['id'] ) && $draft['id'] === $draft_id ) {
				return $draft;
			}
		}

		return null;
	}

	private static function format_email_template_records( $templates ) {
		$formatted = [];

		foreach ( (array) $templates as $template ) {
			$formatted[] = self::format_email_template_record( $template );
		}

		return $formatted;
	}

	private static function format_email_template_record( $template ) {
		if ( ! is_array( $template ) ) {
			return [];
		}

		if ( ! empty( $template['created_at'] ) ) {
			$template['created_at_ts'] = YoRo_Date_Helper::get_mysql_timestamp( $template['created_at'] );
			$template['created_at'] = YoRo_Date_Helper::format_mysql( $template['created_at'] );
		}

		if ( ! empty( $template['updated_at'] ) ) {
			$template['updated_at_ts'] = YoRo_Date_Helper::get_mysql_timestamp( $template['updated_at'] );
			$template['updated_at'] = YoRo_Date_Helper::format_mysql( $template['updated_at'] );
		}

		return $template;
	}

	private static function format_email_draft_records( $drafts ) {
		$formatted = [];

		foreach ( (array) $drafts as $draft ) {
			$formatted[] = self::format_email_draft_record( $draft );
		}

		return $formatted;
	}

	private static function format_email_draft_record( $draft ) {
		if ( ! is_array( $draft ) ) {
			return [];
		}

		if ( ! empty( $draft['created_at'] ) ) {
			$draft['created_at_ts'] = YoRo_Date_Helper::get_mysql_timestamp( $draft['created_at'] );
			$draft['created_at'] = YoRo_Date_Helper::format_mysql( $draft['created_at'] );
		}

		if ( ! empty( $draft['updated_at'] ) ) {
			$draft['updated_at_ts'] = YoRo_Date_Helper::get_mysql_timestamp( $draft['updated_at'] );
			$draft['updated_at'] = YoRo_Date_Helper::format_mysql( $draft['updated_at'] );
		}

		return $draft;
	}
}
