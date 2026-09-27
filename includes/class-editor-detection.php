<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single source of truth for "what can safely be done to this post's
 * body content" — called by both wp_detect_editor (so the AI can decide
 * what to attempt) AND class-tools-content.php's write handlers (so the
 * restriction is enforced server-side regardless of what called it,
 * including a direct external MCP client that never asked the AI's
 * permission first). One function, two callers, no drift between what the
 * AI is told and what the plugin actually allows.
 *
 * Detection uses WordPress's own APIs, not string guessing:
 * has_blocks()/parse_blocks() are core functions (wp-includes/blocks.php),
 * and the page-builder checks read each builder's own real postmeta flag
 * rather than sniffing post_content for builder-shaped HTML.
 */
class RankOut_Connector_Editor_Detection {

	// Elementor sets _elementor_data (the builder's serialized page tree)
	// whenever a post has ever been edited with it, and flips
	// _elementor_edit_mode to 'builder' while it's the active renderer for
	// that post (a site can have Elementor installed without every post
	// using it — post_content still holds the real fallback markup then).
	// Divi flips _et_pb_use_builder to 'on' the same way.
	const BUILDER_META = array(
		'elementor' => array( 'meta_key' => '_elementor_data', 'mode_key' => '_elementor_edit_mode', 'mode_value' => 'builder' ),
		'divi'      => array( 'meta_key' => '_et_pb_use_builder', 'mode_key' => '_et_pb_use_builder', 'mode_value' => 'on' ),
	);

	/**
	 * @return array{
	 *   post_id:int, editor:string, canReadContent:bool, canUpdateTitle:bool,
	 *   canUpdateSeoMetadata:bool, canSafelyEditContent:bool,
	 *   requiresBuilderSpecificAdapter:bool, reasonIfUnsupported:?string,
	 *   blockCount:?int
	 * }
	 */
	public static function detect( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}

		foreach ( self::BUILDER_META as $builder => $keys ) {
			$mode_active = get_post_meta( $post_id, $keys['mode_key'], true ) === $keys['mode_value'];
			$has_data    = '' !== (string) get_post_meta( $post_id, $keys['meta_key'], true );
			if ( $mode_active || $has_data ) {
				return self::result(
					$post_id,
					$builder,
					false,
					true,
					sprintf(
						'This page is built with %s — its body content is stored and rendered in %s\'s own data, not post_content, so editing post_content would not change what visitors see. Title and SEO metadata are still safe to update.',
						ucfirst( $builder ),
						ucfirst( $builder )
					),
					null
				);
			}
		}

		if ( function_exists( 'has_blocks' ) && has_blocks( $post->post_content ) ) {
			$blocks = function_exists( 'parse_blocks' ) ? parse_blocks( $post->post_content ) : array();
			return self::result( $post_id, 'gutenberg', true, false, null, self::count_blocks( $blocks ) );
		}

		return self::result( $post_id, 'classic', true, false, null, null );
	}

	private static function count_blocks( array $blocks ) {
		$count = 0;
		foreach ( $blocks as $block ) {
			// parse_blocks() emits an empty-name block for the whitespace
			// between real blocks — not a real editable block, don't count it.
			if ( ! empty( $block['blockName'] ) ) {
				$count++;
			}
		}
		return $count;
	}

	private static function result( $post_id, $editor, $can_safely_edit_content, $requires_adapter, $reason, $block_count ) {
		return array(
			'post_id'                       => $post_id,
			'editor'                         => $editor,
			// Reading the stored title/content is always possible regardless
			// of what renders it — it's a plain column read.
			'canReadContent'                 => true,
			// Title and SEO-plugin metadata are independent WP-core/postmeta
			// writes; a page builder overriding body rendering doesn't stop
			// either from being real and effective.
			'canUpdateTitle'                 => true,
			'canUpdateSeoMetadata'           => true,
			'canSafelyEditContent'           => $can_safely_edit_content,
			'requiresBuilderSpecificAdapter' => $requires_adapter,
			'reasonIfUnsupported'            => $reason,
			'blockCount'                     => $block_count,
		);
	}
}
