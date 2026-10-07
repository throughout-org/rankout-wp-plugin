<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_search_posts / wp_create_post / wp_create_page /
 * wp_trash_created_post — lets RankOut add NEW content (e.g. a pillar
 * page) instead of only editing what already exists, and gives it the
 * read access it needs to plan one (does this slug already exist? which
 * live pages should the new one link to?).
 *
 * Safety rules, enforced here rather than trusted to the caller:
 * - A created post/page is ALWAYS a draft. Publishing stays a human
 *   decision in wp-admin; nothing here can make content go live.
 * - `slug` is required and must be unused by any non-trashed post of the
 *   same type, so a create is deterministic: the backend can check "is
 *   this slug still free?" before a human approves it, and a retried
 *   create can't silently add a second copy (an identical retry returns
 *   the draft it already made instead).
 * - Every post this plugin creates is marked with CREATED_META_KEY, and
 *   wp_trash_created_post (the undo for a create) refuses anything without
 *   that marker or that is no longer a draft — RankOut can never trash
 *   content it didn't create, or content a human has since published.
 */
class RankOut_Connector_Tools_Create {

	const CREATED_META_KEY = '_rankout_connector_created';

	const MAX_TITLE_LENGTH = 200;

	const MAX_SEARCH_RESULTS = 50;

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		RankOut_Connector_Tool_Registry::register(
			'wp_search_posts',
			'Search posts and/or pages by keyword (title and content), or list them when no query is given. Returns id, type, title, slug, status, and link for each match — use it to check whether a slug is already taken before proposing a new page, or to find existing pages to link to.',
			array(
				'type'       => 'object',
				'properties' => array(
					'query'     => array( 'type' => 'string' ),
					'post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page', 'any' ) ),
					'status'    => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'any' ) ),
					'limit'     => array( 'type' => 'integer' ),
				),
			),
			true,
			'mcp:posts:read',
			array( __CLASS__, 'search_posts' )
		);

		$create_schema = array(
			'type'       => 'object',
			'properties' => array(
				'title'   => array( 'type' => 'string' ),
				'slug'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
			),
			'required'   => array( 'title', 'slug' ),
		);
		$page_schema = $create_schema;
		$page_schema['properties']['parent_id'] = array( 'type' => 'integer' );

		RankOut_Connector_Tool_Registry::register(
			'wp_create_post',
			'Create a new blog post as a DRAFT (never published — a human publishes it from wp-admin). slug is required and must not already be used by another post. Returns the new post_id, link, and edit_link.',
			$create_schema,
			false,
			'mcp:posts:write',
			function ( array $args, $wp_user_id = 0 ) {
				return self::create( $args, 'post', (int) $wp_user_id );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_create_page',
			'Create a new page as a DRAFT (never published — a human publishes it from wp-admin). slug is required and must not already be used by another page; parent_id optionally nests it under an existing page. Returns the new post_id, link, and edit_link.',
			$page_schema,
			false,
			'mcp:posts:write',
			function ( array $args, $wp_user_id = 0 ) {
				return self::create( $args, 'page', (int) $wp_user_id );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_trash_created_post',
			'Undo a wp_create_post / wp_create_page by moving that draft to the trash. Only works on content RankOut created that is still unpublished.',
			array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'post_id' ),
			),
			false,
			'mcp:posts:write',
			array( __CLASS__, 'trash_created' )
		);
	}

	public static function search_posts( array $args ) {
		$post_type = $args['post_type'] ?? 'any';
		$status    = $args['status'] ?? 'publish';
		$limit     = isset( $args['limit'] ) ? max( 1, min( self::MAX_SEARCH_RESULTS, (int) $args['limit'] ) ) : 20;
		$query     = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';

		$query_args = array(
			'post_type'              => 'any' === $post_type ? array( 'post', 'page' ) : $post_type,
			'post_status'            => 'any' === $status ? array( 'publish', 'draft', 'pending', 'future', 'private' ) : $status,
			'posts_per_page'         => $limit,
			'orderby'                => '' === $query ? 'modified' : 'relevance',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'suppress_filters'       => false,
		);
		if ( '' !== $query ) {
			$query_args['s'] = $query;
		}

		$results = array();
		foreach ( get_posts( $query_args ) as $post ) {
			$results[] = array(
				'post_id'   => (int) $post->ID,
				'post_type' => $post->post_type,
				'title'     => $post->post_title,
				'slug'      => $post->post_name,
				'status'    => $post->post_status,
				'link'      => get_permalink( $post ),
			);
		}
		return array( 'results' => $results, 'count' => count( $results ) );
	}

	public static function create( array $args, $post_type, $wp_user_id ) {
		$title   = trim( (string) ( $args['title'] ?? '' ) );
		$slug    = sanitize_title( (string) ( $args['slug'] ?? '' ) );
		$content = array_key_exists( 'content', $args ) ? (string) $args['content'] : '';
		$excerpt = array_key_exists( 'excerpt', $args ) ? (string) $args['excerpt'] : '';

		if ( '' === $title ) {
			throw new RuntimeException( 'title must not be empty.' );
		}
		if ( strlen( $title ) > self::MAX_TITLE_LENGTH ) {
			throw new RuntimeException( sprintf( 'title must be at most %d characters.', self::MAX_TITLE_LENGTH ) );
		}
		if ( '' === $slug ) {
			throw new RuntimeException( 'slug must contain at least one letter or number.' );
		}

		$parent_id = 0;
		if ( 'page' === $post_type && ! empty( $args['parent_id'] ) ) {
			$parent_id = (int) $args['parent_id'];
			$parent    = get_post( $parent_id );
			if ( ! $parent || 'page' !== $parent->post_type || 'trash' === $parent->post_status ) {
				throw new RuntimeException( sprintf( 'parent_id %d is not an existing page.', $parent_id ) );
			}
		}

		$existing = self::find_by_slug( $slug, $post_type );
		if ( $existing ) {
			// An identical retry (same RankOut-created draft, same fields) is
			// a successful no-op — the backend may retry a create after a
			// timeout without knowing whether the first attempt landed.
			if ( self::is_identical_created_draft( $existing, $title, $content, $excerpt, $parent_id ) ) {
				$result                   = self::describe( $existing );
				$result['already_existed'] = true;
				return $result;
			}
			throw new RuntimeException( sprintf( 'The slug "%s" is already used by %s %d ("%s"). Choose a different slug, or update that %s instead.', $slug, $existing->post_type, $existing->ID, $existing->post_title, $existing->post_type ) );
		}

		// wp_insert_post() expects slashed input and unslashes it — without
		// wp_slash() any backslash in the content would be silently lost.
		$post_id = wp_insert_post(
			wp_slash( array(
				'post_type'    => $post_type,
				'post_status'  => 'draft',
				'post_title'   => $title,
				'post_name'    => $slug,
				'post_content' => $content,
				'post_excerpt' => $excerpt,
				'post_parent'  => $parent_id,
				'post_author'  => $wp_user_id,
				'meta_input'   => array( self::CREATED_META_KEY => gmdate( 'c' ) ),
			) ),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}
		if ( ! $post_id ) {
			throw new RuntimeException( 'WordPress did not create the draft.' );
		}

		$post   = get_post( $post_id );
		$failed = '';
		if ( ! $post || 'draft' !== $post->post_status ) {
			$failed = 'status';
		} elseif ( $post->post_title !== $title ) {
			$failed = 'title';
		} elseif ( $post->post_content !== $content ) {
			$failed = 'content';
		} elseif ( $post->post_excerpt !== $excerpt ) {
			$failed = 'excerpt';
		} elseif ( $post->post_name !== $slug ) {
			$failed = 'slug';
		}
		if ( '' !== $failed ) {
			// Don't leave a half-right draft behind for a create that
			// reports failure — the caller would otherwise retry into a
			// "slug already used" error against its own leftover.
			wp_delete_post( $post_id, true );
			throw new RuntimeException( sprintf( 'WordPress did not persist the requested %s value (it may have been filtered for security), so the draft was not kept.', $failed ) );
		}

		$result                    = self::describe( $post );
		$result['already_existed'] = false;
		return $result;
	}

	public static function trash_created( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
		if ( ! get_post_meta( $post_id, self::CREATED_META_KEY, true ) ) {
			throw new RuntimeException( sprintf( 'Post %d was not created by RankOut, so RankOut will not trash it.', $post_id ) );
		}
		if ( 'trash' === $post->post_status ) {
			return array( 'post_id' => $post_id, 'trashed' => true, 'already_trashed' => true );
		}
		if ( ! in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft' ), true ) ) {
			throw new RuntimeException( sprintf( 'Post %d is "%s", not a draft — it has been published or scheduled since RankOut created it, so a human must remove it from wp-admin.', $post_id, $post->post_status ) );
		}
		if ( ! wp_trash_post( $post_id ) ) {
			throw new RuntimeException( sprintf( 'WordPress could not move post %d to the trash.', $post_id ) );
		}
		return array( 'post_id' => $post_id, 'trashed' => true, 'already_trashed' => false );
	}

	public static function is_create_tool( $tool_name ) {
		return in_array( $tool_name, array( 'wp_create_post', 'wp_create_page' ), true );
	}

	private static function find_by_slug( $slug, $post_type ) {
		$matches = get_posts(
			array(
				'name'                   => $slug,
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);
		return $matches ? $matches[0] : null;
	}

	private static function is_identical_created_draft( $post, $title, $content, $excerpt, $parent_id ) {
		return 'draft' === $post->post_status
			&& get_post_meta( $post->ID, self::CREATED_META_KEY, true )
			&& $post->post_title === $title
			&& $post->post_content === $content
			&& $post->post_excerpt === $excerpt
			&& (int) $post->post_parent === $parent_id;
	}

	private static function describe( $post ) {
		return array(
			'post_id'   => (int) $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'content'   => $post->post_content,
			'excerpt'   => $post->post_excerpt,
			'status'    => $post->post_status,
			'parent_id' => (int) $post->post_parent,
			// A draft has no public permalink yet; get_permalink() returns
			// the ?p=/?page_id= preview form, which is what a reviewer needs.
			'link'      => get_permalink( $post ),
			'edit_link' => admin_url( sprintf( 'post.php?post=%d&action=edit', $post->ID ) ),
		);
	}
}

RankOut_Connector_Tools_Create::register();
