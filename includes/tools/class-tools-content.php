<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_get_post / wp_get_page / wp_update_post / wp_update_page /
 * wp_get_post_meta / wp_update_post_meta — the exact tool names
 * implementation.service.ts's validationReadFor() map references, and
 * the ones a RankOut implementation proposal actually mutates content
 * through. Also wp_find_post: implementation.service.ts's own system
 * prompt already assumes a "locate this page by its slug or URL" tool
 * exists ("If the task gives a targetUrl, locate that exact page first
 * ... instead of browsing the site") — every other tool here needs a
 * post_id up front, so without this one nothing could ever resolve a
 * task's targetUrl to an id in the first place.
 */
class RankOut_Connector_Tools_Content {

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		$post_id_schema = array(
			'type'       => 'object',
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'post_id' ),
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_find_post',
			'Resolve a live front-end URL (e.g. https://example.com/blog/) to its post/page id, type, and title. Works for the site\'s posts-page/blog index too, not just individual posts.',
			array(
				'type'       => 'object',
				'properties' => array( 'url' => array( 'type' => 'string' ) ),
				'required'   => array( 'url' ),
			),
			true,
			'mcp:posts:read',
			array( __CLASS__, 'find_post' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_get_post',
			'Get one post\'s current title, content, excerpt, status, and slug by id.',
			$post_id_schema,
			true,
			'mcp:posts:read',
			function ( array $args ) {
				return self::get_content( (int) ( $args['post_id'] ?? 0 ), 'post' );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_get_page',
			'Get one page\'s current title, content, excerpt, status, and slug by id.',
			$post_id_schema,
			true,
			'mcp:posts:read',
			function ( array $args ) {
				return self::get_content( (int) ( $args['post_id'] ?? 0 ), 'page' );
			}
		);

		$update_schema = array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer' ),
				'title'   => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
				'excerpt' => array( 'type' => 'string' ),
			),
			'required'   => array( 'post_id' ),
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_post',
			'Update one post\'s title, content, and/or excerpt. Only the fields provided are changed.',
			$update_schema,
			false,
			'mcp:posts:write',
			function ( array $args ) {
				return self::update_content( $args, 'post' );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_page',
			'Update one page\'s title, content, and/or excerpt. Only the fields provided are changed.',
			$update_schema,
			false,
			'mcp:posts:write',
			function ( array $args ) {
				return self::update_content( $args, 'page' );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_get_post_meta',
			'Get a post\'s public custom fields (protected/internal meta keys starting with "_" are never exposed here).',
			$post_id_schema,
			true,
			'mcp:posts:read',
			array( __CLASS__, 'get_post_meta' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_post_meta',
			'Set one or more of a post\'s public custom fields. Protected/internal meta keys (starting with "_") are refused — use the dedicated SEO tools for SEO plugin fields.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'meta'    => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				'required'   => array( 'post_id', 'meta' ),
			),
			false,
			'mcp:posts:write',
			array( __CLASS__, 'update_post_meta' )
		);
	}

	public static function find_post( array $args ) {
		$url = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
		if ( '' === $url ) {
			throw new RuntimeException( 'url is required.' );
		}
		// WordPress core's url_to_postid() has a documented blind spot: the
		// static page assigned as Settings → Reading → "Posts page" (very
		// commonly a /blog/ index) parses to the blog ARCHIVE query, not a
		// singular post/page match, so it returns 0 even though the page
		// is completely real — check that specific case explicitly first,
		// by comparing paths rather than full URLs so it's also immune to
		// any scheme/host mismatch between what RankOut was given and how
		// this site is actually configured.
		$given_path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$post_id    = 0;

		$posts_page_id = (int) get_option( 'page_for_posts' );
		if ( $posts_page_id && '' !== $given_path ) {
			$posts_page_path = untrailingslashit( (string) wp_parse_url( get_permalink( $posts_page_id ), PHP_URL_PATH ) );
			if ( $given_path === $posts_page_path ) {
				$post_id = $posts_page_id;
			}
		}

		if ( ! $post_id ) {
			$post_id = url_to_postid( $url );
		}
		if ( ! $post_id ) {
			// A www vs non-www or http vs https mismatch between the given
			// URL and this site's configured home_url() is separately
			// enough to make url_to_postid() return 0 — retry once against
			// the same path rebuilt onto this site's own home_url().
			$normalized = self::normalize_to_home_url( $url );
			if ( $normalized && $normalized !== $url ) {
				$post_id = url_to_postid( $normalized );
			}
		}
		if ( ! $post_id ) {
			throw new RuntimeException( sprintf( 'No post or page found for "%s".', $url ) );
		}
		$post = get_post( $post_id );
		return array(
			'post_id'   => $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'slug'      => $post->post_name,
			'link'      => get_permalink( $post ),
		);
	}

	private static function normalize_to_home_url( $url ) {
		$home  = wp_parse_url( home_url( '/' ) );
		$given = wp_parse_url( $url );
		if ( ! $home || ! $given || empty( $home['host'] ) || empty( $given['path'] ) ) {
			return null;
		}
		$rebuilt = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . $given['path'];
		if ( ! empty( $given['query'] ) ) {
			$rebuilt .= '?' . $given['query'];
		}
		return $rebuilt;
	}

	private static function require_post( $post_id, $expected_type ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
		if ( $post->post_type !== $expected_type ) {
			throw new RuntimeException( sprintf( 'Post %d is a "%s", not a "%s".', $post_id, $post->post_type, $expected_type ) );
		}
		return $post;
	}

	private static function get_content( $post_id, $expected_type ) {
		$post = self::require_post( $post_id, $expected_type );
		return array(
			'post_id' => $post->ID,
			// The raw stored value, deliberately NOT get_the_title() — that
			// applies the `the_title` filter chain (wptexturize() etc.),
			// which rewrites e.g. a plain " - " into an HTML-entity en dash
			// and "&" into "&amp;". wp_update_post() writes the raw string,
			// so reading back the filtered version makes RankOut's own
			// post-write validation spuriously fail on any title containing
			// those characters, even though the write genuinely succeeded
			// (found live: title "...Home - Airmate" read back as
			// "...Home &#8211; Airmate", flagged FAILED for a write that
			// was actually correct).
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'excerpt' => $post->post_excerpt,
			'status'  => $post->post_status,
			'slug'    => $post->post_name,
			'link'    => get_permalink( $post ),
			'modified' => mysql2date( 'c', $post->post_modified_gmt, false ),
		);
	}

	private static function update_content( array $args, $expected_type ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id, $expected_type );

		$update = array( 'ID' => $post_id );
		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $arg_key => $field ) {
			if ( array_key_exists( $arg_key, $args ) ) {
				$update[ $field ] = (string) $args[ $arg_key ];
			}
		}
		if ( count( $update ) === 1 ) {
			throw new RuntimeException( 'Provide at least one of title, content, or excerpt to update.' );
		}

		$result = wp_update_post( $update, true );
		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( $result->get_error_message() );
		}
		$read_back = self::get_content( $post_id, $expected_type );
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			if ( array_key_exists( $field, $args ) && (string) $args[ $field ] !== $read_back[ $field ] ) {
				throw new RuntimeException( sprintf( 'WordPress did not persist the requested %s value.', $field ) );
			}
		}
		return $read_back;
	}

	private static function require_meta_object( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			throw new RuntimeException( 'Generic metadata is limited to WordPress posts and pages; use a dedicated integration for other object types.' );
		}
		return $post;
	}

	private static function allowed_meta_keys() {
		$keys = array();
		if ( defined( 'RANKOUT_CONNECTOR_ALLOWED_PUBLIC_META_KEYS' ) ) {
			$keys = is_array( RANKOUT_CONNECTOR_ALLOWED_PUBLIC_META_KEYS )
				? RANKOUT_CONNECTOR_ALLOWED_PUBLIC_META_KEYS
				: preg_split( '/[\s,]+/', (string) RANKOUT_CONNECTOR_ALLOWED_PUBLIC_META_KEYS, -1, PREG_SPLIT_NO_EMPTY );
		}
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters( 'rankout_connector_allowed_public_meta_keys', $keys ) ) ) );
	}

	public static function get_post_meta( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_meta_object( $post_id );
		$all  = get_post_meta( $post_id );
		$meta = array();
		foreach ( $all as $key => $values ) {
			if ( is_protected_meta( $key, 'post' ) ) {
				continue;
			}
			$meta[ $key ] = count( $values ) === 1 ? maybe_unserialize( $values[0] ) : array_map( 'maybe_unserialize', $values );
		}
		return array( 'post_id' => $post_id, 'meta' => $meta );
	}

	public static function update_post_meta( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$meta    = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();
		self::require_meta_object( $post_id );
		if ( empty( $meta ) ) {
			throw new RuntimeException( 'meta must contain at least one key/value pair.' );
		}
		$allowed = self::allowed_meta_keys();
		foreach ( $meta as $key => $value ) {
			if ( is_protected_meta( $key, 'post' ) ) {
				throw new RuntimeException( sprintf( '"%s" is a protected meta key and cannot be set through this tool.', $key ) );
			}
			if ( ! in_array( $key, $allowed, true ) ) {
				throw new RuntimeException( sprintf( '"%s" is not in this site\'s RankOut public-meta allowlist.', $key ) );
			}
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		$read_back = self::get_post_meta( array( 'post_id' => $post_id ) );
		foreach ( $meta as $key => $value ) {
			if ( ! array_key_exists( $key, $read_back['meta'] ) || wp_json_encode( $read_back['meta'][ $key ] ) !== wp_json_encode( $value ) ) {
				throw new RuntimeException( sprintf( 'WordPress did not persist metadata key "%s".', $key ) );
			}
		}
		return $read_back;
	}
}

RankOut_Connector_Tools_Content::register();
