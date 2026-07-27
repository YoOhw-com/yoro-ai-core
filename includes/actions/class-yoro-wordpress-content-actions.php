<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoRo_WordPress_Content_Actions {

	public static function register() {
		self::register_action( 'content.search', 'Search content', 'read', false, [ 'query' => 'string', 'post_type' => 'string', 'limit' => 'integer' ], [], 'search_content', 50 );
		self::register_action( 'content.get', 'Get content', 'read', false, [ 'content_id' => 'integer' ], [ 'content_id' ], 'get_content' );
		self::register_action( 'content.create_draft', 'Create content draft', 'draft', true, [ 'title' => 'string', 'content' => 'string', 'post_type' => 'string' ], [ 'title', 'content' ], 'create_draft' );
		self::register_action( 'content.update_draft', 'Update content draft', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string', 'title' => 'string', 'content' => 'string' ], [ 'content_id', 'expected_version' ], 'update_draft' );
		self::register_action( 'content.preview_changes', 'Preview content changes', 'read', false, [ 'content_id' => 'integer', 'expected_version' => 'string', 'title' => 'string', 'content' => 'string' ], [ 'content_id', 'expected_version' ], 'preview_changes' );
		self::register_action( 'content.publish', 'Publish content', 'mutate', true, [ 'content_id' => 'integer', 'expected_version' => 'string', 'expected_preview_hash' => 'string' ], [ 'content_id', 'expected_version', 'expected_preview_hash' ], 'publish' );
		self::register_action( 'comments.list', 'List comments', 'read', false, [ 'content_id' => 'integer', 'status' => 'string', 'limit' => 'integer' ], [], 'list_comments', 50 );
		self::register_action( 'comments.reply', 'Reply to comment', 'communicate', true, [ 'comment_id' => 'integer', 'expected_version' => 'string', 'content' => 'string' ], [ 'comment_id', 'expected_version', 'content' ], 'reply_comment' );
		self::register_action( 'comments.update_status', 'Update comment status', 'mutate', true, [ 'comment_id' => 'integer', 'expected_version' => 'string', 'status' => 'string' ], [ 'comment_id', 'expected_version', 'status' ], 'update_comment_status' );
		self::register_action( 'media.search', 'Search media', 'read', false, [ 'query' => 'string', 'mime_type' => 'string', 'limit' => 'integer' ], [], 'search_media', 50 );
		self::register_action( 'media.upload', 'Upload media', 'mutate', true, [ 'filename' => 'string', 'mime_type' => 'string', 'content_base64' => 'string', 'alt_text' => 'string' ], [ 'filename', 'mime_type', 'content_base64' ], 'upload_media' );
	}

	private static function register_action( $name, $label, $effect, $write, $schema, $required, $callback, $max_targets = 1 ) {
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
				'effect'                => $effect,
				'reversibility'         => $write ? 'partial' : 'full',
				'external_side_effect'  => in_array( $effect, [ 'communicate' ], true ),
				'target_type'           => strtok( $name, '.' ),
				'max_targets'           => max( 1, absint( $max_targets ) ),
				'max_items'             => max( 1, absint( $max_targets ) ),
				'ai_exposed'            => true,
				'ai_description'        => __( $label, 'yoro-ai-core' ),
				'required_params'       => $required,
				'capabilities'          => [ 'wordpress_content', $write ? 'write' : 'read' ],
			]
		);
	}

	public static function search_content( $params ) {
		$query = new WP_Query(
			[
				's'              => sanitize_text_field( $params['query'] ?? '' ),
				'post_type'      => self::post_type( $params['post_type'] ?? 'post' ),
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
		$post_type = self::post_type( $params['post_type'] ?? 'post' );
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

	public static function upload_media( $params ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'yoro_media_upload_forbidden', 'The WordPress actor cannot upload media.' );
		}

		$allowed = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf' ];
		$mime = sanitize_mime_type( $params['mime_type'] ?? '' );
		$bytes = base64_decode( (string) ( $params['content_base64'] ?? '' ), true );
		if ( ! in_array( $mime, $allowed, true ) || false === $bytes || strlen( $bytes ) > 10 * MB_IN_BYTES ) {
			return new WP_Error( 'yoro_media_payload_invalid', 'The media payload type or size is not allowed.' );
		}
		$filename = sanitize_file_name( $params['filename'] ?? '' );
		$tmp = wp_tempnam( $filename );
		if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) {
			return new WP_Error( 'yoro_media_temp_failed', 'The media upload could not be staged.' );
		}
		$filetype = wp_check_filetype_and_ext( $tmp, $filename );
		if ( empty( $filetype['type'] ) || $filetype['type'] !== $mime ) {
			@unlink( $tmp );
			return new WP_Error( 'yoro_media_type_mismatch', 'The uploaded bytes do not match the declared media type.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$media_id = media_handle_sideload( [ 'name' => $filename, 'type' => $mime, 'tmp_name' => $tmp, 'error' => 0, 'size' => strlen( $bytes ) ], 0 );
		if ( is_wp_error( $media_id ) ) {
			@unlink( $tmp );
			return $media_id;
		}
		if ( isset( $params['alt_text'] ) ) {
			update_post_meta( $media_id, '_wp_attachment_image_alt', sanitize_text_field( $params['alt_text'] ) );
		}

		return self::success( [ 'media_id' => $media_id, 'url' => wp_get_attachment_url( $media_id ), 'mime_type' => get_post_mime_type( $media_id ) ], 'Media uploaded.' );
	}

	public static function present_post( $post ) {
		return [
			'content_id' => $post->ID,
			'post_type'  => $post->post_type,
			'status'     => $post->post_status,
			'title'      => $post->post_title,
			'content'    => $post->post_content,
			'version'    => self::post_version( $post ),
			'modified_at'=> mysql_to_rfc3339( $post->post_modified_gmt ),
			'url'        => get_permalink( $post ),
		];
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

	private static function editable_post( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'yoro_content_not_found', 'The requested post or page was not found.' );
		}

		return current_user_can( 'edit_post', $post->ID )
			? $post
			: new WP_Error( 'yoro_content_edit_forbidden', 'The WordPress actor cannot edit this content.' );
	}

	private static function readable_post( $post_id ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, [ 'post', 'page' ], true ) ) {
			return new WP_Error( 'yoro_content_not_found', 'The requested post or page was not found.' );
		}

		return current_user_can( 'read_post', $post->ID )
			? $post
			: new WP_Error( 'yoro_content_read_forbidden', 'The WordPress actor cannot read this content.' );
	}

	private static function post_type( $post_type ) {
		return in_array( sanitize_key( $post_type ), [ 'post', 'page' ], true ) ? sanitize_key( $post_type ) : 'post';
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
