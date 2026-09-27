<?php
/**
 * Standalone, framework-free test for editor detection + safe Gutenberg
 * editing — this plugin has no PHPUnit/WP-test-suite harness, so this
 * stubs the small set of real WordPress functions the classes under test
 * actually call (has_blocks/parse_blocks/serialize_blocks/get_post/
 * get_post_meta/wp_update_post), closely enough to exercise the real
 * logic in class-editor-detection.php and class-tools-editor.php against
 * realistic Gutenberg/Elementor/Divi fixtures. It is NOT a reimplementation
 * of WordPress's block grammar for every case (nested blocks, void/self-
 * closing blocks, JSON attrs) — only what paragraph/heading blocks need.
 * A live check against a real WordPress install is still the final word;
 * see the report for what was additionally verified there.
 *
 * Run: php tests/test-editor-detection.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['__posts']     = array();
$GLOBALS['__post_meta'] = array();

function get_post( $id ) {
	return $GLOBALS['__posts'][ $id ] ?? null;
}

function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['__post_meta'][ $id ][ $key ] ?? '';
}

// Real WP's own check: a literal "<!-- wp:" marker anywhere in the content.
function has_blocks( $content ) {
	return false !== strpos( (string) $content, '<!-- wp:' );
}

// Minimal parser for the one grammar shape this plugin's block-text tool
// supports: `<!-- wp:name {attrs}? -->\nHTML\n<!-- /wp:name -->`, with
// plain-text runs between blocks parsed as blockName=null entries — the
// same shape real WordPress emits for these, which is what
// real_block_keys()'s "only count non-null blockName" logic depends on.
function parse_blocks( $content ) {
	$pattern = '/<!--\s+wp:([a-z0-9\/-]+)(?:\s+(\{.*?\}))?\s+-->(.*?)<!--\s+\/wp:\1\s+-->/s';
	$blocks  = array();
	$cursor  = 0;
	if ( preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $matches[0] as $i => $whole ) {
			$start = $whole[1];
			if ( $start > $cursor ) {
				$blocks[] = array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => substr( $content, $cursor, $start - $cursor ), 'innerContent' => array( substr( $content, $cursor, $start - $cursor ) ) );
			}
			$inner_html = trim( $matches[3][ $i ][0] );
			$name       = $matches[1][ $i ][0];
			// Real WordPress's parser namespaces an unprefixed delimiter name
			// (e.g. "wp:paragraph") to "core/paragraph" — the comment form
			// omits "core/" for core blocks but parse_blocks() always returns
			// the fully-namespaced blockName. Must match here for
			// SUPPORTED_BLOCK_TYPES ('core/paragraph', 'core/heading') checks
			// to behave the same as against a real site.
			if ( false === strpos( $name, '/' ) ) {
				$name = 'core/' . $name;
			}
			$blocks[]   = array(
				'blockName'    => $name,
				'attrs'        => isset( $matches[2][ $i ][0] ) && '' !== $matches[2][ $i ][0] ? json_decode( $matches[2][ $i ][0], true ) : array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $inner_html,
				'innerContent' => array( $inner_html ),
			);
			$cursor = $start + strlen( $whole[0] );
		}
	}
	if ( $cursor < strlen( $content ) ) {
		$blocks[] = array( 'blockName' => null, 'attrs' => array(), 'innerBlocks' => array(), 'innerHTML' => substr( $content, $cursor ), 'innerContent' => array( substr( $content, $cursor ) ) );
	}
	return $blocks;
}

function serialize_blocks( array $blocks ) {
	$out = '';
	foreach ( $blocks as $block ) {
		if ( empty( $block['blockName'] ) ) {
			$out .= $block['innerHTML'];
			continue;
		}
		$attrs = ! empty( $block['attrs'] ) ? ' ' . wp_json_encode( $block['attrs'] ) : '';
		$out  .= "<!-- wp:{$block['blockName']}{$attrs} -->\n{$block['innerHTML']}\n<!-- /wp:{$block['blockName']} -->";
	}
	return $out;
}

function wp_json_encode( $data ) {
	return json_encode( $data );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	public $message;
	public function __construct( $message = '' ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function wp_update_post( array $data, $wp_error = false ) {
	$id = $data['ID'];
	if ( array_key_exists( 'post_content', $data ) ) {
		$GLOBALS['__posts'][ $id ]->post_content = $data['post_content'];
	}
	if ( array_key_exists( 'post_title', $data ) ) {
		$GLOBALS['__posts'][ $id ]->post_title = $data['post_title'];
	}
	return $id;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}

// Not under test here (registration wiring is exercised via a live check
// against a real WordPress site instead) — a no-op so requiring the tool
// files' top-level ::register() calls doesn't fatal.
class RankOut_Connector_Tool_Registry {
	public static function register( ...$args ) {}
}

function wp_strip_all_tags( $s ) {
	return strip_tags( $s );
}
function esc_html( $s ) {
	return htmlspecialchars( $s, ENT_QUOTES );
}
function get_permalink( $post ) {
	return 'https://example.test/?p=' . $post->ID;
}
function mysql2date( $format, $date, $translate = true ) {
	return $date;
}

require_once __DIR__ . '/../includes/class-editor-detection.php';
require_once __DIR__ . '/../includes/tools/class-tools-editor.php';

// --- fixtures -----------------------------------------------------------

function make_post( $id, $content, $title = 'A post' ) {
	$post                     = new stdClass();
	$post->ID                 = $id;
	$post->post_type          = 'post';
	$post->post_content       = $content;
	$post->post_title         = $title;
	$post->post_excerpt       = '';
	$post->post_status        = 'publish';
	$post->post_name          = 'a-post-' . $id;
	$post->post_modified_gmt  = '2026-01-01 00:00:00';
	$GLOBALS['__posts'][ $id ] = $post;
}

// A columns block with real nested blocks (wp:column, and a paragraph
// nested inside THAT) — proves an edit elsewhere on the page leaves a
// nested block structure completely untouched, not just a flat list of
// unrelated sibling blocks.
$NESTED_COLUMNS = "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\"><!-- wp:paragraph -->\n<p>Nested column paragraph.</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->";

// The heading carries a real block attrs blob ({"level":3}) — proves an
// edit to a DIFFERENT block doesn't drop or corrupt this block's own
// attributes even though it's never touched by that edit.
$GUTENBERG_CONTENT = "<!-- wp:heading {\"level\":3} -->\n<h2>Original heading</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>First paragraph.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Second paragraph, untouched.</p>\n<!-- /wp:paragraph -->\n\n{$NESTED_COLUMNS}";

make_post( 1, $GUTENBERG_CONTENT );                                    // Gutenberg
make_post( 2, '<p>Plain classic HTML, no block markers.</p>' );        // Classic
make_post( 3, '<p>Elementor fallback markup.</p>' );                   // Elementor
$GLOBALS['__post_meta'][3]['_elementor_edit_mode'] = 'builder';
$GLOBALS['__post_meta'][3]['_elementor_data']      = '[{"id":"abc"}]';
make_post( 4, '<p>Divi fallback markup.</p>' );                        // Divi
$GLOBALS['__post_meta'][4]['_et_pb_use_builder'] = 'on';
$GUTENBERG_LIST = "<!-- wp:paragraph -->\n<p>Editable.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:list -->\n<ul><li>One</li></ul>\n<!-- /wp:list -->";
make_post( 5, $GUTENBERG_LIST );                                       // Gutenberg w/ unsupported block type

// --- assertion helpers ----------------------------------------------------

$failures = 0;
$passed   = 0;
function check( $label, $condition ) {
	global $failures, $passed;
	if ( $condition ) {
		$passed++;
	} else {
		$failures++;
		fwrite( STDERR, "FAIL: {$label}\n" );
	}
}
function expect_throws( $label, callable $fn ) {
	try {
		$fn();
		check( $label, false );
	} catch ( Throwable $e ) {
		check( $label, true );
	}
}

// 1) Detection — Classic / Gutenberg / Elementor / Divi ---------------------

check( 'classic post detected as classic, content editing allowed', get_post_content_editable_via_detection( 2, true ) );
check( 'gutenberg post detected as gutenberg, content editing allowed (via block tool, not raw rewrite)', get_post_content_editable_via_detection( 1, true, 'gutenberg' ) );
check( 'elementor post detected, canSafelyEditContent is false', get_post_content_editable_via_detection( 3, false, 'elementor' ) );
check( 'divi post detected, canSafelyEditContent is false', get_post_content_editable_via_detection( 4, false, 'divi' ) );

function get_post_content_editable_via_detection( $post_id, $expected, $expected_editor = null ) {
	$c = RankOut_Connector_Editor_Detection::detect( $post_id );
	if ( $expected_editor && $c['editor'] !== $expected_editor ) {
		fwrite( STDERR, "  (editor mismatch: got {$c['editor']}, wanted {$expected_editor})\n" );
		return false;
	}
	return $c['canSafelyEditContent'] === $expected;
}

// Title and SEO metadata stay available even on a builder page.
foreach ( array( 3, 4 ) as $builder_post_id ) {
	$c = RankOut_Connector_Editor_Detection::detect( $builder_post_id );
	check( "post {$builder_post_id}: canUpdateTitle stays true on a builder page", true === $c['canUpdateTitle'] );
	check( "post {$builder_post_id}: canUpdateSeoMetadata stays true on a builder page", true === $c['canUpdateSeoMetadata'] );
	check( "post {$builder_post_id}: reasonIfUnsupported names the detected builder", false !== stripos( $c['reasonIfUnsupported'], $c['editor'] ) );
}

// 2) Safe changes succeed: editing one Gutenberg block -----------------------

$result = RankOut_Connector_Tools_Editor::update_block_text( array( 'post_id' => 1, 'block_index' => 1, 'new_text' => 'Updated first paragraph.' ) );
check( 'block edit reports the new text', false !== strpos( $result['new_text'], 'Updated first paragraph.' ) );
check( 'block edit reports block_type paragraph', 'core/paragraph' === $result['block_type'] );

$after_blocks = parse_blocks( $GLOBALS['__posts'][1]->post_content );
$real         = array_values( array_filter( $after_blocks, fn( $b ) => ! empty( $b['blockName'] ) ) );
check( 'block count unchanged after edit (4 real blocks)', 4 === count( $real ) );
check( 'edited block (index 1) now has the new text', false !== strpos( $real[1]['innerHTML'], 'Updated first paragraph.' ) );
check( 'heading (index 0) is byte-identical — unrelated content untouched', '<h2>Original heading</h2>' === $real[0]['innerHTML'] );
check( 'heading (index 0) block attrs are preserved untouched by an edit to a different block', array( 'level' => 3 ) === $real[0]['attrs'] );
check( 'third block (index 2) is byte-identical — unrelated content untouched', '<p>Second paragraph, untouched.</p>' === $real[2]['innerHTML'] );
check(
	'nested columns block (index 3) — including its nested wp:column and nested paragraph — is byte-identical after an edit elsewhere on the page',
	trim( $NESTED_COLUMNS ) === "<!-- wp:columns -->\n{$real[3]['innerHTML']}\n<!-- /wp:columns -->"
);
check( 'still detected as a valid, editable Gutenberg post after the edit', has_blocks( $GLOBALS['__posts'][1]->post_content ) );

// 3) Unsupported changes are rejected ---------------------------------------

expect_throws( 'editing an unsupported block type (core/list) is rejected, not silently rewritten', function () {
	RankOut_Connector_Tools_Editor::update_block_text( array( 'post_id' => 5, 'block_index' => 1, 'new_text' => 'x' ) );
} );
expect_throws( 'out-of-range block_index is rejected', function () {
	RankOut_Connector_Tools_Editor::update_block_text( array( 'post_id' => 1, 'block_index' => 99, 'new_text' => 'x' ) );
} );
expect_throws( 'calling the block-text tool on a non-Gutenberg (classic) post is rejected', function () {
	RankOut_Connector_Tools_Editor::update_block_text( array( 'post_id' => 2, 'block_index' => 0, 'new_text' => 'x' ) );
} );

// 4) Direct MCP calls cannot bypass restrictions -----------------------------
// Simulates an external MCP client calling wp_update_post directly (no AI
// orchestration, no prior wp_detect_editor call) on a page-builder post —
// via reflection into the REAL, private update_content(), the exact method
// class-tool-registry.php wires wp_update_post/wp_update_page's handler to,
// so this proves the actual registered tool refuses it, not a copy of it.
require_once __DIR__ . '/../includes/tools/class-tools-content.php';
function call_update_content( array $args, $expected_type ) {
	$method = new ReflectionMethod( 'RankOut_Connector_Tools_Content', 'update_content' );
	return $method->invoke( null, $args, $expected_type );
}

expect_throws( 'a direct wp_update_post content edit on an Elementor page is refused server-side', function () {
	call_update_content( array( 'post_id' => 3, 'content' => '<p>Bypass attempt.</p>' ), 'post' );
} );
expect_throws( 'a direct wp_update_post content edit on a Divi page is refused server-side', function () {
	call_update_content( array( 'post_id' => 4, 'content' => '<p>Bypass attempt.</p>' ), 'post' );
} );
$title_only = call_update_content( array( 'post_id' => 3, 'title' => 'New title, no content change' ), 'post' );
check( 'title-only update on an Elementor page still succeeds (not unnecessarily blocked)', 'New title, no content change' === $title_only['title'] );

echo $failures === 0 ? "OK — {$passed} checks passed\n" : "FAILED — {$failures} of " . ( $failures + $passed ) . " checks failed\n";
exit( $failures === 0 ? 0 : 1 );
