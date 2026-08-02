<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_WordPress_Content_Actions {

	public static function register() {
		self::register_action(
			'content.search',
			'Search content',
			'read',
			false,
			[ 'query' => 'string', 'post_type' => 'string', 'limit' => 'integer' ],
			[],
			'search_content',
			50,
			'',
			[
				'post_type' => __( 'Use only a post type published by this connector when the user explicitly requests one; omit this optional field for a broad content search.', 'yoro-ai-core' ),
			]
		);
		self::register_action( 'content.get', 'Get content', 'read', false, [ 'content_id' => 'integer' ], [ 'content_id' ], 'get_content' );
		self::register_action( 'content.create_draft', 'Create content draft', 'mutate', true, [ 'title' => 'string', 'content' => 'string', 'post_type' => 'string' ], [ 'title', 'content' ], 'create_draft', 1, 'create_only' );
		self::register_action( 'content.update_draft', 'Update content draft', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string', 'title' => 'string', 'content' => 'string' ], [ 'content_id', 'expected_version' ], 'update_draft' );
		self::register_action( 'content.preview_changes', 'Preview content changes', 'read', false, [ 'content_id' => 'integer', 'expected_version' => 'string', 'title' => 'string', 'content' => 'string' ], [ 'content_id', 'expected_version' ], 'preview_changes' );
		self::register_action( 'content.publish', 'Publish content', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string', 'expected_preview_hash' => 'string' ], [ 'content_id', 'expected_version', 'expected_preview_hash' ], 'publish' );
		self::register_action( 'content.schedule', 'Schedule content', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string', 'publish_at' => 'string' ], [ 'content_id', 'expected_version', 'publish_at' ], 'schedule' );
		self::register_action( 'content.trash', 'Move content to trash', 'delete', true, [ 'content_id' => 'integer', 'expected_version' => 'string' ], [ 'content_id', 'expected_version' ], 'trash' );
		self::register_action( 'content.restore', 'Restore trashed content', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string' ], [ 'content_id', 'expected_version' ], 'restore' );
		self::register_action( 'content.list_revisions', 'List content revisions', 'read', false, [ 'content_id' => 'integer', 'limit' => 'integer' ], [ 'content_id' ], 'list_revisions', 50 );
		self::register_action( 'content.preview_revision', 'Preview a content revision', 'read', false, [ 'content_id' => 'integer', 'revision_id' => 'integer' ], [ 'content_id', 'revision_id' ], 'preview_revision' );
		self::register_action( 'content.restore_revision', 'Restore a content revision', 'mutate', true, [ 'content_id' => 'integer', 'revision_id' => 'integer', 'expected_version' => 'string' ], [ 'content_id', 'revision_id', 'expected_version' ], 'restore_revision' );
		self::register_action( 'content.assign_terms', 'Assign categories or tags to content', 'mutate', true, [ 'content_id' => 'integer', 'taxonomy' => 'string', 'term_ids' => 'array<integer>', 'expected_version' => 'string' ], [ 'content_id', 'taxonomy', 'term_ids', 'expected_version' ], 'assign_terms', 20 );
		self::register_action( 'content.set_featured_image', 'Set a featured image', 'mutate', true, [ 'content_id' => 'integer', 'media_id' => 'integer', 'expected_version' => 'string' ], [ 'content_id', 'media_id', 'expected_version' ], 'set_featured_image' );
		self::register_action( 'content.bulk_update_status', 'Update content status in a bounded batch', 'mutate', true, [ 'items' => 'array<object>', 'status' => 'string' ], [ 'items', 'status' ], 'bulk_update_status', 20, 'per_item_optimistic_version' );
		self::register_action( 'comments.list', 'List comments', 'read', false, [ 'content_id' => 'integer', 'status' => 'string', 'limit' => 'integer' ], [], 'list_comments', 50 );
		self::register_action( 'comments.reply', 'Reply to comment', 'communicate', true, [ 'comment_id' => 'integer', 'expected_version' => 'string', 'content' => 'string' ], [ 'comment_id', 'expected_version', 'content' ], 'reply_comment' );
		self::register_action( 'comments.update_status', 'Update comment status', 'mutate', true, [ 'comment_id' => 'integer', 'expected_version' => 'string', 'status' => 'string' ], [ 'comment_id', 'expected_version', 'status' ], 'update_comment_status' );
		self::register_action( 'media.search', 'Search media', 'read', false, [ 'query' => 'string', 'mime_type' => 'string', 'limit' => 'integer' ], [], 'search_media', 50 );
		self::register_action( 'media.get', 'Get media details', 'read', false, [ 'media_id' => 'integer' ], [ 'media_id' ], 'get_media' );
		self::register_action( 'media.upload', 'Upload media', 'mutate', true, [ 'media_reference_id' => 'string', 'filename' => 'string', 'mime_type' => 'string', 'byte_size' => 'integer', 'sha256' => 'string', 'alt_text' => 'string' ], [ 'media_reference_id', 'filename', 'mime_type', 'byte_size', 'sha256' ], 'upload_media', 1, 'create_only' );
		self::register_action( 'media.update_metadata', 'Update media metadata', 'mutate', true, [ 'media_id' => 'integer', 'expected_version' => 'string', 'title' => 'string', 'caption' => 'string', 'description' => 'string', 'alt_text' => 'string' ], [ 'media_id', 'expected_version' ], 'update_media_metadata' );
	}

	private static function register_action( $name, $label, $effect, $write, $schema, $required, $callback, $max_targets = 1, $concurrency_strategy = '', $param_hints = [] ) {
		YoRo_Action_Registry::register(
			$name,
			[
				'label'                 => __( $label, 'yoro-ai-core' ),
				'description'           => __( $label . ' through the managed connector.', 'yoro-ai-core' ),
				'category'              => 'wordpress_content',
				'callback'              => [ __CLASS__, $callback ],
				'input_schema'          => $schema,
				'output_schema'         => [ 'success' => 'boolean' ],
				'requires_confirmation' => $write,
				'risk_level'            => $write ? 'medium' : 'low',
				'write_action'          => $write,
				'concurrency_strategy'  => $concurrency_strategy,
				'effect'                => $effect,
				'reversibility'         => $write ? 'partial' : 'full',
				'external_side_effect'  => in_array( $effect, [ 'communicate' ], true ),
				'target_type'           => strtok( $name, '.' ),
				'max_targets'           => max( 1, absint( $max_targets ) ),
				'max_items'             => max( 1, absint( $max_targets ) ),
				'ai_exposed'            => true,
				'ai_description'        => __( $label, 'yoro-ai-core' ),
				'required_params'       => $required,
				'param_hints'           => $param_hints,
				'capabilities'          => [ 'wordpress_content', $write ? 'write' : 'read' ],
			]
		);
	}

	public static function search_content( $params ) {
		$post_type = self::post_type( $params['post_type'] ?? '' );
		if ( '' === $post_type ) {
			return new WP_Error( 'yoro_content_type_not_allowed', 'The requested content type is not allowlisted for the connector.' );
		}

		$query = new WP_Query(
			[
				's'              => sanitize_text_field( $params['query'] ?? '' ),
				'post_type'      => $post_type,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			]
		);

		$posts = array_values(
			array_filter(
				$query->posts,
				static function( $post ) {
					return $post instanceof WP_Post && current_user_can( 'read_post', $post->ID );
				}
			)
		);

		return self::success( array_map( [ __CLASS__, 'present_post' ], $posts ), 'Content search completed.' );
	}

	public static function get_content( $params ) {
		$post = self::readable_post( $params['content_id'] ?? 0 );

		return is_wp_error( $post ) ? $post : self::success( self::present_post( $post ), 'Content loaded.' );
	}

	public static function create_draft( $params ) {
		$post_type = self::post_type( $params['post_type'] ?? '' );
		if ( '' === $post_type ) {
			return new WP_Error( 'yoro_content_type_not_allowed', 'The requested content type is not allowlisted for the connector.' );
		}
		$post_type_object = get_post_type_object( $post_type );
		$create_capability = $post_type_object && ! empty( $post_type_object->cap->create_posts )
			? $post_type_object->cap->create_posts
			: 'edit_posts';

		if ( ! current_user_can( $create_capability ) ) {
			return new WP_Error( 'yoro_content_create_forbidden', 'The WordPress actor cannot create this content type.' );
		}

		$post_id = wp_insert_post(
			[
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => sanitize_text_field( $params['title'] ?? '' ),
				'post_content' => wp_kses_post( $params['content'] ?? '' ),
			],
			true
		);

		return is_wp_error( $post_id ) ? $post_id : self::success( self::present_post( get_post( $post_id ) ), 'Draft created.' );
	}

	public static function update_draft( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'draft' !== $post->post_status || ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The draft changed after it was read.' );
		}

		$update = [ 'ID' => $post->ID ];
		if ( array_key_exists( 'title', $params ) ) {
			$update['post_title'] = sanitize_text_field( $params['title'] );
		}
		if ( array_key_exists( 'content', $params ) ) {
			$update['post_content'] = wp_kses_post( $params['content'] );
		}
		$result = wp_update_post( $update, true );

		return is_wp_error( $result ) ? $result : self::success( self::present_post( get_post( $result ) ), 'Draft updated.' );
	}

	public static function preview_changes( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}

		$preview = [
			'content_id'       => $post->ID,
			'expected_version' => self::post_version( $post ),
			'title'            => array_key_exists( 'title', $params ) ? sanitize_text_field( $params['title'] ) : $post->post_title,
			'content'          => array_key_exists( 'content', $params ) ? wp_kses_post( $params['content'] ) : $post->post_content,
		];
		$preview['preview_hash'] = self::hash( $preview );

		return self::success( $preview, 'Content preview generated.' );
	}

	public static function publish( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object || empty( $post_type_object->cap->publish_posts ) || ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return new WP_Error( 'yoro_content_publish_forbidden', 'The WordPress actor cannot publish this content type.' );
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}
		$preview = [
			'content_id'       => $post->ID,
			'expected_version' => self::post_version( $post ),
			'title'            => $post->post_title,
			'content'          => $post->post_content,
		];
		if ( ! hash_equals( self::hash( $preview ), (string) ( $params['expected_preview_hash'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_preview_hash_conflict', 'The approved preview no longer matches the draft.' );
		}
		$result = wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'publish' ], true );

		return is_wp_error( $result ) ? $result : self::success( self::present_post( get_post( $result ) ), 'Content published.' );
	}

	public static function schedule( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}
		$publish_at = strtotime( (string) ( $params['publish_at'] ?? '' ) );
		if ( false === $publish_at || $publish_at <= time() + MINUTE_IN_SECONDS ) {
			return new WP_Error( 'yoro_schedule_time_invalid', 'The publish time must be a valid future date.' );
		}
		$post_type_object = get_post_type_object( $post->post_type );
		if ( ! $post_type_object || empty( $post_type_object->cap->publish_posts ) || ! current_user_can( $post_type_object->cap->publish_posts ) ) {
			return new WP_Error( 'yoro_content_publish_forbidden', 'The WordPress actor cannot schedule this content type.' );
		}
		$result = wp_update_post(
			[
				'ID'            => $post->ID,
				'post_status'   => 'future',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $publish_at ),
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $publish_at ) ),
				'edit_date'     => true,
			],
			true
		);

		return is_wp_error( $result ) ? $result : self::success( self::present_post( get_post( $result ) ), 'Content scheduled.' );
	}

	public static function trash( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}

		$trashed = wp_trash_post( $post->ID );

		return $trashed instanceof WP_Post ? self::success( self::present_post( get_post( $trashed->ID ) ), 'Content moved to trash.' ) : new WP_Error( 'yoro_content_trash_failed', 'The content could not be moved to trash.' );
	}

	public static function restore( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0, true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( 'trash' !== $post->post_status || ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The trashed content changed after it was read.' );
		}

		$restored = wp_untrash_post( $post->ID );

		return $restored instanceof WP_Post ? self::success( self::present_post( get_post( $restored->ID ) ), 'Content restored.' ) : new WP_Error( 'yoro_content_restore_failed', 'The content could not be restored.' );
	}

	public static function list_revisions( $params ) {
		$post = self::readable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$revisions = array_slice( wp_get_post_revisions( $post->ID, [ 'order' => 'DESC', 'orderby' => 'date ID' ] ), 0, min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) ) );

		return self::success(
			array_values(
				array_map(
					static function ( $revision ) {
						return [
							'revision_id' => $revision->ID,
							'content_id'  => $revision->post_parent,
							'title'       => $revision->post_title,
							'modified_at' => mysql_to_rfc3339( $revision->post_modified_gmt ),
							'author_id'   => (int) $revision->post_author,
						];
					},
					$revisions
				)
			),
			'Content revisions loaded.'
		);
	}

	public static function preview_revision( $params ) {
		$post = self::readable_post( $params['content_id'] ?? 0 );
		$revision = wp_get_post_revision( absint( $params['revision_id'] ?? 0 ) );

		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== (int) $post->ID ) {
			return new WP_Error( 'yoro_revision_not_found', 'The requested revision does not belong to this content.' );
		}

		return self::success(
			[
				'revision_id' => $revision->ID,
				'content_id'  => $post->ID,
				'title'       => $revision->post_title,
				'content'     => $revision->post_content,
				'excerpt'     => $revision->post_excerpt,
				'modified_at' => mysql_to_rfc3339( $revision->post_modified_gmt ),
			],
			'Content revision preview loaded.'
		);
	}

	public static function restore_revision( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}
		$revision = wp_get_post_revision( absint( $params['revision_id'] ?? 0 ) );
		if ( ! $revision instanceof WP_Post || (int) $revision->post_parent !== (int) $post->ID ) {
			return new WP_Error( 'yoro_revision_not_found', 'The requested revision does not belong to this content.' );
		}
		$restored_id = wp_restore_post_revision( $revision->ID );

		return $restored_id ? self::success( self::present_post( get_post( $restored_id ) ), 'Content revision restored.' ) : new WP_Error( 'yoro_revision_restore_failed', 'The content revision could not be restored.' );
	}

	public static function assign_terms( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}
		$taxonomy = sanitize_key( $params['taxonomy'] ?? '' );
		$object = get_taxonomy( $taxonomy );
		if ( ! $object || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) || ! current_user_can( $object->cap->assign_terms ) ) {
			return new WP_Error( 'yoro_taxonomy_assignment_forbidden', 'The WordPress actor cannot assign this taxonomy.' );
		}
		$term_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $params['term_ids'] ?? [] ) ) ) ) );
		if ( count( $term_ids ) > 20 ) {
			return new WP_Error( 'yoro_term_assignment_limit', 'At most 20 terms may be assigned in one operation.' );
		}
		$result = wp_set_object_terms( $post->ID, $term_ids, $taxonomy, false );

		return is_wp_error( $result ) ? $result : self::success( [ 'content_id' => $post->ID, 'taxonomy' => $taxonomy, 'term_ids' => array_map( 'absint', $result ) ], 'Content terms assigned.' );
	}

	public static function set_featured_image( $params ) {
		$post = self::editable_post( $params['content_id'] ?? 0 );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_content_version_conflict', 'The content changed after it was read.' );
		}
		$media_id = absint( $params['media_id'] ?? 0 );
		if ( 'attachment' !== get_post_type( $media_id ) || ! wp_attachment_is_image( $media_id ) || ! current_user_can( 'edit_post', $media_id ) ) {
			return new WP_Error( 'yoro_featured_image_invalid', 'The requested media item is not an editable image.' );
		}
		if ( ! set_post_thumbnail( $post->ID, $media_id ) ) {
			return new WP_Error( 'yoro_featured_image_failed', 'The featured image could not be assigned.' );
		}

		return self::success( [ 'content_id' => $post->ID, 'media_id' => $media_id ], 'Featured image assigned.' );
	}

	public static function bulk_update_status( $params ) {
		$items = is_array( $params['items'] ?? null ) ? array_values( $params['items'] ) : [];
		$status = sanitize_key( $params['status'] ?? '' );
		if ( empty( $items ) || count( $items ) > 20 || ! in_array( $status, [ 'draft', 'pending', 'private' ], true ) ) {
			return new WP_Error( 'yoro_content_bulk_invalid', 'A bounded batch of 1 to 20 items and an allowed non-public status is required.' );
		}

		$posts = [];
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return new WP_Error( 'yoro_content_bulk_invalid', 'Every batch item must include content_id and expected_version.' );
			}
			$post = self::editable_post( $item['content_id'] ?? 0 );
			if ( is_wp_error( $post ) || ! hash_equals( self::post_version( $post ), (string) ( $item['expected_version'] ?? '' ) ) ) {
				return new WP_Error( 'yoro_content_version_conflict', 'At least one content item changed after it was read.' );
			}
			$posts[] = $post;
		}

		$results = [];
		foreach ( $posts as $post ) {
			$result = wp_update_post( [ 'ID' => $post->ID, 'post_status' => $status ], true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$results[] = self::present_post( get_post( $result ) );
		}

		return self::success( $results, 'Content batch status updated.' );
	}

	public static function list_comments( $params ) {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return new WP_Error( 'yoro_comments_read_forbidden', 'The WordPress actor cannot moderate comments.' );
		}

		$args = [
			'number'  => min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) ),
			'status'  => sanitize_key( $params['status'] ?? 'all' ),
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		];
		if ( ! empty( $params['content_id'] ) ) {
			$args['post_id'] = absint( $params['content_id'] );
			if ( ! current_user_can( 'read_post', $args['post_id'] ) ) {
				return new WP_Error( 'yoro_comments_read_forbidden', 'The WordPress actor cannot read comments for this content.' );
			}
		}

		$comments = array_values(
			array_filter(
				get_comments( $args ),
				static function( $comment ) {
					return $comment instanceof WP_Comment && current_user_can( 'read_post', $comment->comment_post_ID );
				}
			)
		);

		return self::success( array_map( [ __CLASS__, 'present_comment' ], $comments ), 'Comments loaded.' );
	}

	public static function reply_comment( $params ) {
		$comment = get_comment( absint( $params['comment_id'] ?? 0 ) );
		if ( ! $comment instanceof WP_Comment || ! hash_equals( self::comment_version( $comment ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_comment_version_conflict', 'The comment is missing or changed after it was read.' );
		}
		if ( ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_post', $comment->comment_post_ID ) ) {
			return new WP_Error( 'yoro_comment_reply_forbidden', 'The WordPress actor cannot reply to this comment.' );
		}
		$user = wp_get_current_user();
		$id = wp_insert_comment(
			[
				'comment_post_ID'      => $comment->comment_post_ID,
				'comment_parent'       => $comment->comment_ID,
				'comment_content'      => wp_kses_post( $params['content'] ?? '' ),
				'user_id'              => $user->ID,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_approved'     => 1,
			]
		);

		return $id ? self::success( self::present_comment( get_comment( $id ) ), 'Comment reply posted.' ) : new WP_Error( 'yoro_comment_reply_failed', 'The comment reply could not be created.' );
	}

	public static function update_comment_status( $params ) {
		$comment = get_comment( absint( $params['comment_id'] ?? 0 ) );
		if ( ! $comment instanceof WP_Comment || ! hash_equals( self::comment_version( $comment ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_comment_version_conflict', 'The comment is missing or changed after it was read.' );
		}
		if ( ! current_user_can( 'moderate_comments' ) || ! current_user_can( 'edit_comment', $comment->comment_ID ) ) {
			return new WP_Error( 'yoro_comment_status_forbidden', 'The WordPress actor cannot moderate this comment.' );
		}
		$status = sanitize_key( $params['status'] ?? '' );
		if ( ! in_array( $status, [ 'approve', 'hold', 'spam', 'trash' ], true ) ) {
			return new WP_Error( 'yoro_comment_status_invalid', 'The requested comment status is not allowed.' );
		}
		$result = wp_set_comment_status( $comment->comment_ID, $status, true );

		return is_wp_error( $result ) ? $result : self::success( self::present_comment( get_comment( $comment->comment_ID ) ), 'Comment status updated.' );
	}

	public static function search_media( $params ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'yoro_media_read_forbidden', 'The WordPress actor cannot access the media library.' );
		}

		$query = new WP_Query(
			[
				's'              => sanitize_text_field( $params['query'] ?? '' ),
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => sanitize_mime_type( $params['mime_type'] ?? '' ),
				'posts_per_page' => min( 50, max( 1, absint( $params['limit'] ?? 20 ) ) ),
			]
		);
		$items = array_map(
			static function( $post ) {
				return [
					'media_id'  => $post->ID,
					'title'     => $post->post_title,
					'mime_type' => $post->post_mime_type,
					'url'       => wp_get_attachment_url( $post->ID ),
				];
			},
			$query->posts
		);

		return self::success( $items, 'Media search completed.' );
	}

	public static function get_media( $params ) {
		$post = get_post( absint( $params['media_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! current_user_can( 'read_post', $post->ID ) ) {
			return new WP_Error( 'yoro_media_not_found', 'The requested media item was not found or is not readable.' );
		}

		return self::success( self::present_media( $post ), 'Media details loaded.' );
	}

	public static function upload_media( $params ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'yoro_media_upload_forbidden', 'The WordPress actor cannot upload media.' );
		}

		$allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
		$mime = sanitize_mime_type( $params['mime_type'] ?? '' );
		$filename = sanitize_file_name( $params['filename'] ?? '' );
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp ) {
			return new WP_Error( 'yoro_media_temp_failed', 'The media upload could not be staged.' );
		}

		if ( ! empty( $params['media_reference_id'] ) ) {
			$download = static::connector_media_client()->download_media_reference( $params['media_reference_id'], $tmp );
			if ( is_wp_error( $download ) ) {
				@unlink( $tmp );
				return $download;
			}
			$byte_size = (int) ( $download['byte_size'] ?? 0 );
			$actual_hash = hash_file( 'sha256', $tmp );
			if ( $byte_size !== absint( $params['byte_size'] ?? 0 )
				|| ! is_string( $actual_hash )
				|| ! hash_equals( (string) ( $params['sha256'] ?? '' ), $actual_hash )
				|| ! hash_equals( (string) ( $download['sha256'] ?? '' ), $actual_hash ) ) {
				@unlink( $tmp );
				return new WP_Error( 'yoro_media_reference_mismatch', 'The staged media file failed its integrity check.' );
			}
		} else {
			$bytes = base64_decode( (string) ( $params['content_base64'] ?? '' ), true );
			if ( false === $bytes || false === file_put_contents( $tmp, $bytes ) ) {
				@unlink( $tmp );
				return new WP_Error( 'yoro_media_payload_invalid', 'The legacy media payload is invalid.' );
			}
			$byte_size = strlen( $bytes );
		}

		if ( ! in_array( $mime, $allowed, true ) || $byte_size < 1 || $byte_size > 10 * MB_IN_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'yoro_media_payload_invalid', 'The media payload type or size is not allowed.' );
		}
		$filetype = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $filetype['type'] ) || $filetype['type'] !== $mime ) {
			@unlink( $tmp );
			return new WP_Error( 'yoro_media_type_mismatch', 'The uploaded bytes do not match the declared media type.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$media_id = media_handle_sideload( [ 'name' => $filename, 'type' => $mime, 'tmp_name' => $tmp, 'error' => 0, 'size' => $byte_size ], 0 );
		if ( is_wp_error( $media_id ) ) {
			@unlink( $tmp );
			return $media_id;
		}
		if ( isset( $params['alt_text'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt_text'] ) );
		}

		return self::success( self::present_media( get_post( $media_id ) ), 'Media uploaded.' );
	}

	/**
	 * @return YoRo_Connector_Media_Client_Interface
	 */
	protected static function connector_media_client() {
		return new YoRo_Connector_Media_Client();
	}

	public static function update_media_metadata( $params ) {
		$post = get_post( absint( $params['media_id'] ?? 0 ) );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'yoro_media_edit_forbidden', 'The requested media item is not editable.' );
		}
		if ( ! hash_equals( self::post_version( $post ), (string) ( $params['expected_version'] ?? '' ) ) ) {
			return new WP_Error( 'yoro_media_version_conflict', 'The media item changed after it was read.' );
		}

		$update = [ 'ID' => $post->ID ];
		foreach ( [ 'title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content' ] as $input => $field ) {
			if ( array_key_exists( $input, $params ) ) {
				$update[ $field ] = wp_kses_post( $params[ $input ] );
			}
		}
		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( array_key_exists( 'alt_text', $params ) ) {
			update_post_meta( $post->ID, '_wp_attachment_image_alt', sanitize_text_field( $params['alt_text'] ) );
		}

		return self::success( self::present_media( get_post( $post->ID ) ), 'Media metadata updated.' );
	}

	public static function present_post( $post ) {
		return [
			'content_id' => $post->ID,
			'post_type'  => $post->post_type,
			'status'     => $post->post_status,
			'title'      => $post->post_title,
			'content'    => $post->post_content,
			'version'    => self::post_version( $post ),
			'modified_at' => self::post_modified_at( $post ),
			'url'        => get_permalink( $post ),
		];
	}

	private static function post_modified_at( $post ) {
		$modified_gmt = (string) $post->post_modified_gmt;

		if ( '' === $modified_gmt || 0 === strpos( $modified_gmt, '0000-00-00' ) ) {
			$modified_gmt = get_gmt_from_date( (string) $post->post_modified );
		}

		return mysql_to_rfc3339( $modified_gmt );
	}

	public static function present_comment( $comment ) {
		return [
			'comment_id' => (int) $comment->comment_ID,
			'content_id' => (int) $comment->comment_post_ID,
			'parent_id'  => (int) $comment->comment_parent,
			'author'     => $comment->comment_author,
			'content'    => $comment->comment_content,
			'status'     => wp_get_comment_status( $comment ),
			'version'    => self::comment_version( $comment ),
		];
	}

	private static function present_media( $post ) {
		return [
			'media_id'    => $post->ID,
			'title'       => $post->post_title,
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'alt_text'    => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime_type'   => $post->post_mime_type,
			'url'         => wp_get_attachment_url( $post->ID ),
			'metadata'    => wp_get_attachment_metadata( $post->ID ),
			'version'     => self::post_version( $post ),
		];
	}

	private static function editable_post( $post_id, $allow_trash = false ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! self::is_allowed_post_type( $post->post_type ) || ( ! $allow_trash && 'trash' === $post->post_status ) ) {
			return new WP_Error( 'yoro_content_not_found', 'The requested post or page was not found.' );
		}

		return current_user_can( 'edit_post', $post->ID )
			? $post
			: new WP_Error( 'yoro_content_edit_forbidden', 'The WordPress actor cannot edit this content.' );
	}

	private static function readable_post( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! self::is_allowed_post_type( $post->post_type ) ) {
			return new WP_Error( 'yoro_content_not_found', 'The requested post or page was not found.' );
		}

		return current_user_can( 'read_post', $post->ID )
			? $post
			: new WP_Error( 'yoro_content_read_forbidden', 'The WordPress actor cannot read this content.' );
	}

	private static function post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return 'post';
		}

		return self::is_allowed_post_type( $post_type ) ? $post_type : '';
	}

	public static function allowed_post_types() {
		$allowed = apply_filters( 'yoro_connector_allowed_post_types', [ 'post', 'page' ] );
		$allowed = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $allowed ) ) ) );

		return array_values(
			array_filter(
				$allowed,
				static function ( $post_type ) {
					$object = get_post_type_object( $post_type );

					return $object
						&& ! empty( $object->show_ui )
						&& ! empty( $object->show_in_rest );
				}
			)
		);
	}

	private static function is_allowed_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );

		return in_array( $post_type, self::allowed_post_types(), true );
	}

	private static function post_version( $post ) {
		return self::hash( [ $post->ID, $post->post_modified_gmt, $post->post_status, $post->post_title, $post->post_content ] );
	}

	private static function comment_version( $comment ) {
		return self::hash( [ $comment->comment_ID, $comment->comment_date_gmt, $comment->comment_approved, $comment->comment_content ] );
	}

	private static function hash( $value ) {
		return hash( 'sha256', wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	private static function success( $data, $message ) {
		return [ 'success' => true, 'message' => $message, 'data' => $data ];
	}
}
