<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the JSON-LD blocks `class-tools-schema.php`'s write tools store —
 * writing one to post meta or the site-schema option has zero front-end
 * effect on its own; this is what actually puts it on the rendered page.
 * Hooked on `wp_footer` (any renderer position is schema.org-valid; footer
 * avoids competing with whatever the active SEO plugin is doing in `wp_head`)
 * on every front-end request, admin requests excluded.
 */
class RankOut_Connector_Schema_Renderer {

	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	public static function render() {
		$blocks = array();

		$site_schema = get_option( RankOut_Connector_Tools_Schema::SITE_OPTION_KEY, array() );
		if ( is_array( $site_schema ) ) {
			$blocks = array_merge( $blocks, array_values( $site_schema ) );
		}

		if ( is_singular() ) {
			$post_schema = get_post_meta( get_queried_object_id(), RankOut_Connector_Tools_Schema::POST_META_KEY, true );
			if ( is_array( $post_schema ) ) {
				$blocks = array_merge( $blocks, array_values( $post_schema ) );
			}
		}

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			// Defense in depth against a literal "</script>" sequence inside
			// a string value breaking out of the script tag early — the same
			// escaping technique WordPress core/Yoast use for inline JSON.
			$json = str_replace( '</script>', '<\/script>', wp_json_encode( $block ) );
			echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
