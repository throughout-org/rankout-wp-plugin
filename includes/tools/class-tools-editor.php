<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_detect_editor (read-only capability report) and
 * wp_update_post_block_text (the safe, scoped Gutenberg edit primitive —
 * see its own doc comment for why this exists instead of a raw
 * post_content string replace).
 */
class RankOut_Connector_Tools_Editor {

	const SUPPORTED_BLOCK_TYPES = array( 'core/paragraph', 'core/heading' );

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		RankOut_Connector_Tool_Registry::register(
			'wp_detect_editor',
			'Reports which editor/page-builder renders a post\'s body content and exactly what can safely be changed: canReadContent, canUpdateTitle, canUpdateSeoMetadata, canSafelyEditContent, requiresBuilderSpecificAdapter, reasonIfUnsupported. Call this before attempting a content edit — canSafelyEditContent is false on Elementor/Divi pages even though title and SEO metadata remain safe to update.',
			array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'post_id' ),
			),
			true,
			'mcp:posts:read',
			function ( array $args ) {
				return RankOut_Connector_Editor_Detection::detect( (int) ( $args['post_id'] ?? 0 ) );
			}
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_post_block_text',
			'Replaces the text of ONE existing Gutenberg block (paragraph or heading only) by its position among the post\'s blocks (0 = first block). Every other block, its attributes, and the surrounding structure are left untouched — this is the safe alternative to rewriting a whole post\'s content, and the only content-edit tool available for a Gutenberg post. Call wp_get_post first and wp_detect_editor to confirm the post is Gutenberg (canSafelyEditContent) before calling this.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'      => array( 'type' => 'integer' ),
					'block_index'  => array( 'type' => 'integer', 'description' => '0-based position among the post\'s real blocks (whitespace between blocks is not counted).' ),
					'new_text'     => array( 'type' => 'string', 'description' => 'The new inner HTML for this block, e.g. plain text or with simple inline tags like <strong>.' ),
				),
				'required'   => array( 'post_id', 'block_index', 'new_text' ),
			),
			false,
			'mcp:posts:write',
			array( __CLASS__, 'update_block_text' )
		);
	}

	private static function real_block_keys( array $blocks ) {
		$keys = array();
		foreach ( $blocks as $key => $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$keys[] = $key;
			}
		}
		return $keys;
	}

	// Replaces only the text BETWEEN a leaf block's own opening/closing tag,
	// preserving whatever attributes WordPress rendered onto that tag (a
	// heading's anchor id, a paragraph's alignment class, etc.) — the same
	// reasoning class-tools-content.php's get_content() already documents
	// for why raw stored values matter: we're not regenerating markup from
	// scratch, only substituting the one thing that changed.
	private static function replace_inner_html( $html, $new_text ) {
		if ( ! preg_match( '/^(<[a-z0-9]+\b[^>]*>)(.*)(<\/[a-z0-9]+>)\s*$/is', trim( $html ), $matches ) ) {
			return null;
		}
		return $matches[1] . $new_text . $matches[3];
	}

	public static function update_block_text( array $args ) {
		$post_id     = (int) ( $args['post_id'] ?? 0 );
		$block_index = (int) ( $args['block_index'] ?? -1 );
		$new_text    = isset( $args['new_text'] ) ? (string) $args['new_text'] : '';

		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
		if ( ! function_exists( 'has_blocks' ) || ! has_blocks( $post->post_content ) ) {
			throw new RuntimeException( 'This post is not built from Gutenberg blocks — use wp_update_post\'s content field instead.' );
		}
		if ( '' === trim( $new_text ) ) {
			throw new RuntimeException( 'new_text must not be empty.' );
		}

		$blocks     = parse_blocks( $post->post_content );
		$real_keys  = self::real_block_keys( $blocks );
		if ( $block_index < 0 || $block_index >= count( $real_keys ) ) {
			throw new RuntimeException( sprintf( 'block_index %d is out of range — this post has %d block(s).', $block_index, count( $real_keys ) ) );
		}
		$key   = $real_keys[ $block_index ];
		$block = $blocks[ $key ];

		if ( ! in_array( $block['blockName'], self::SUPPORTED_BLOCK_TYPES, true ) ) {
			throw new RuntimeException(
				sprintf( 'Block %d is a "%s" — only %s can be edited through this tool today. Reject this operation rather than attempting a raw content rewrite.', $block_index, $block['blockName'], implode( ', ', self::SUPPORTED_BLOCK_TYPES ) )
			);
		}

		$new_html = self::replace_inner_html( $block['innerHTML'], $new_text );
		if ( null === $new_html ) {
			throw new RuntimeException( sprintf( 'Block %d\'s markup is not in the expected single-open/close-tag shape — refusing rather than guessing.', $block_index ) );
		}

		$blocks[ $key ]['innerHTML']    = $new_html;
		$blocks[ $key ]['innerContent'] = array( $new_html );

		$serialized = serialize_blocks( $blocks );
		$updated    = wp_update_post( array( 'ID' => $post_id, 'post_content' => $serialized ), true );
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( $updated->get_error_message() );
		}

		// Read back and verify only the one block changed as expected —
		// same read-back-and-compare discipline every other write tool
		// in this plugin already follows.
		$after        = get_post( $post_id );
		$after_blocks = parse_blocks( $after->post_content );
		$after_key    = self::real_block_keys( $after_blocks )[ $block_index ] ?? null;
		$persisted    = null !== $after_key ? ( $after_blocks[ $after_key ]['innerHTML'] ?? null ) : null;
		if ( trim( (string) $persisted ) !== trim( $new_html ) ) {
			throw new RuntimeException( 'WordPress did not persist the requested block text.' );
		}

		return array(
			'post_id'     => $post_id,
			'block_index' => $block_index,
			'block_type'  => $block['blockName'],
			'new_text'    => $new_html,
			'block_count' => count( self::real_block_keys( $after_blocks ) ),
		);
	}
}

RankOut_Connector_Tools_Editor::register();
