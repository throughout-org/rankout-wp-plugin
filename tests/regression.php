<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'RANKOUT_CONNECTOR_CLIENT_ID', 'rankout-dashboard' );
define( 'RANKOUT_CONNECTOR_VERSION', '1.0.4' );
function add_action() {}
function apply_filters( $name, $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ); }
function is_protected_meta( $key ) { return 0 === strpos( $key, '_' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
$test_posts = array();
$test_meta = array();
function get_post( $id ) { global $test_posts; if ( isset( $test_posts[ $id ] ) ) { return $test_posts[ $id ]; } return (object) array( 'ID' => $id, 'post_type' => 99 === $id ? 'product' : 'page', 'post_title' => 'Existing', 'post_content' => 'Body', 'post_excerpt' => 'Excerpt' ); }
function wp_get_post_revision( &$id ) { return (object) array( 'post_parent' => 1 ); }
function wp_save_post_revision() { return 123; }
function update_post_meta( $post_id, $key, $value ) { global $test_meta; $test_meta[ $post_id ][ $key ] = $value; return true; }
$test_options = array();
function get_option( $key, $default = false ) { global $test_options; return array_key_exists( $key, $test_options ) ? $test_options[ $key ] : $default; }
function update_option( $key, $value ) { global $test_options; $test_options[ $key ] = $value; return true; }
function get_post_meta( $post_id, $key = '', $single = false ) { global $test_meta; if ( $key ) { return $test_meta[ $post_id ][ $key ] ?? ''; } return array(); }
function sanitize_title( $value ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' ); }
function wp_slash( $value ) { return $value; }
function get_permalink( $post ) { return 'https://example.com/?p=' . ( is_object( $post ) ? $post->ID : $post ); }
function admin_url( $path ) { return 'https://example.com/wp-admin/' . $path; }
$test_slug_matches = array();
function get_posts( $args ) { global $test_slug_matches; return $test_slug_matches; }
$test_inserted = null;
$test_insert_filter = null;
function wp_insert_post( $data ) {
	global $test_inserted, $test_posts, $test_meta, $test_insert_filter;
	$test_inserted = $data;
	$id = 500;
	$content = $test_insert_filter ? call_user_func( $test_insert_filter, $data['post_content'] ) : $data['post_content'];
	$test_posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => $data['post_type'], 'post_status' => $data['post_status'], 'post_title' => $data['post_title'], 'post_name' => $data['post_name'], 'post_content' => $content, 'post_excerpt' => $data['post_excerpt'], 'post_parent' => $data['post_parent'], 'post_author' => $data['post_author'] );
	$test_meta[ $id ] = $data['meta_input'];
	return $id;
}
$test_deleted = array();
function wp_delete_post( $id ) { global $test_deleted, $test_posts; $test_deleted[] = $id; unset( $test_posts[ $id ] ); return true; }
$test_trashed = array();
function wp_trash_post( $id ) { global $test_trashed; $test_trashed[] = $id; return true; }
class WP_Error { public $code; public function __construct( $code ) { $this->code = $code; } }
class WP_REST_Response {
	private $data;
	private $status;
	public function __construct( $data = null, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}
class WP_REST_Request {
	private $body;
	private $params = array();
	public function __construct( $body = '' ) { $this->body = $body; }
	public function get_body() { return $this->body; }
	public function get_param( $name ) { return $this->params[ $name ] ?? null; }
	public function set_param( $name, $value ) { $this->params[ $name ] = $value; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$test_admin = true;
$test_edit = true;
function get_userdata( $id ) { return $id ? (object) array( 'ID' => $id ) : false; }
$test_denied_caps = array();
function user_can( $id, $capability ) { global $test_admin, $test_edit, $test_denied_caps; if ( in_array( $capability, $test_denied_caps, true ) ) { return false; } return 'manage_options' === $capability ? $test_admin : $test_edit; }

require_once dirname( __DIR__ ) . '/includes/class-schema-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-tool-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-screen.php';
require_once dirname( __DIR__ ) . '/includes/class-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcp-server.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-content.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-create.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-history.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-schema.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-seo.php';

$failures = 0;
function check( $condition, $message ) {
	global $failures;
	if ( ! $condition ) { ++$failures; fwrite( STDERR, "FAIL: {$message}\n" ); }
}
function throws_message( callable $fn, $contains ) {
	try { $fn(); } catch ( Throwable $error ) { return false !== strpos( $error->getMessage(), $contains ); }
	return false;
}

$schema = array(
	'type' => 'object',
	'properties' => array( 'post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page' ) ), 'limit' => array( 'type' => 'integer' ) ),
	'required' => array( 'post_type' ),
);
check( '' === RankOut_Connector_Schema_Validator::validate( array( 'post_type' => 'page', 'limit' => 10 ), $schema ), 'valid schema input rejected' );
check( false !== strpos( RankOut_Connector_Schema_Validator::validate( array( 'post_type' => 'product' ), $schema ), 'one of' ), 'invalid post_type enum accepted' );
check( false !== strpos( RankOut_Connector_Schema_Validator::validate( array( 'post_type' => 'post', 'extra' => true ), $schema ), 'not a supported field' ), 'unknown property accepted' );
check( false !== strpos( RankOut_Connector_Schema_Validator::validate( array( 'limit' => 10 ), $schema ), 'required' ), 'missing required property accepted' );
check( false !== strpos( RankOut_Connector_Schema_Validator::validate( array( 'post_type' => 'post', 'limit' => '10' ), $schema ), 'integer' ), 'wrong scalar type accepted' );

$allowed = RankOut_Connector_Consent_Screen::allowed_redirect_uris();
check( in_array( 'https://api.rankout.app/api/wordpress-connector/callback', $allowed, true ), 'production OAuth callback missing' );
check( ! in_array( 'https://attacker.example/callback', $allowed, true ), 'arbitrary OAuth callback accepted' );
$oauth = array( 'client_id' => 'rankout-dashboard', 'redirect_uri' => 'https://attacker.example/callback', 'response_type' => 'code', 'code_challenge_method' => 'S256', 'code_challenge' => 'challenge', 'state' => 'state' );
check( false !== strpos( RankOut_Connector_Consent_Screen::validate_params( $oauth ), 'not registered' ), 'authorization accepted an unregistered callback' );
$oauth['redirect_uri'] = 'https://api.rankout.app/api/wordpress-connector/callback';
check( '' === RankOut_Connector_Consent_Screen::validate_params( $oauth ), 'registered production callback was rejected' );

$test_admin = false;
check( is_wp_error( RankOut_Connector_Auth::authorize_token_user( 7 ) ), 'token remained authorized after administrator capability removal' );
$test_admin = true;
$test_edit = false;
check( false !== strpos( RankOut_Connector_MCP_Server::authorize_object( 'wp_update_page', array( 'post_id' => 1 ), array( 'read_only' => false ), 7 ), 'not allowed' ), 'object edit capability was not enforced' );
$test_edit = true;
check( '' === RankOut_Connector_MCP_Server::authorize_object( 'wp_restore_revision', array( 'revision_id' => 123 ), array( 'read_only' => false ), 7 ), 'revision authorization failed for an editable parent post' );
check( null === RankOut_Connector_Tools_History::capture_pre_write_revision( 'wp_update_page', array( 'post_id' => 1, 'title' => 'Existing' ) ), 'identical retry created a pre-write revision' );
check( 123 === RankOut_Connector_Tools_History::capture_pre_write_revision( 'wp_update_page', array( 'post_id' => 1, 'title' => 'Changed' ) ), 'real content change did not create a pre-write revision' );

$initialize = new WP_REST_Request( wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array() ) ) );
$initialize->set_param( '_rankout_token_row', array( 'scope' => array(), 'wp_user_id' => 7 ) );
$initialize_response = RankOut_Connector_MCP_Server::handle_post( $initialize );
check( $initialize_response instanceof WP_REST_Response, 'MCP initialize did not return a REST response' );
check( isset( $initialize_response->get_data()['jsonrpc'] ), 'MCP JSON-RPC envelope was double-wrapped below a data property' );
check( '1.0.4' === $initialize_response->get_data()['result']['serverInfo']['version'], 'MCP initialize reported the wrong connector version' );

check( throws_message( function () { RankOut_Connector_Tools_Content::get_post_meta( array( 'post_id' => 99 ) ); }, 'limited to WordPress posts and pages' ), 'WooCommerce product meta was exposed by generic tool' );
check( throws_message( function () { RankOut_Connector_Tools_Content::update_post_meta( array( 'post_id' => 1, 'meta' => array( 'total_sales' => 500 ) ) ); }, 'allowlist' ), 'unapproved public meta key accepted' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1 ) ); }, 'at least one supported SEO field' ), 'empty SEO write reported success' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1, 'meta_description' => 'wrong key' ) ); }, 'at least one supported SEO field' ), 'meta_description mismatch reported success' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1, 'description' => 'Expected' ) ); }, 'did not persist' ), 'failed SEO read-back reported success' );

// Draft creation (class-tools-create.php).
check( throws_message( function () { RankOut_Connector_Tools_Create::create( array( 'title' => 'T', 'slug' => '!!!' ), 'page', 7 ); }, 'slug must contain' ), 'empty slug accepted' );
check( throws_message( function () { RankOut_Connector_Tools_Create::create( array( 'title' => '  ', 'slug' => 'a' ), 'page', 7 ); }, 'title must not be empty' ), 'empty title accepted' );
$created = RankOut_Connector_Tools_Create::create( array( 'title' => 'Best Curly Hair Products', 'slug' => 'Best Curly Hair Products', 'content' => '<p>Body</p>' ), 'page', 7 );
check( 'draft' === $test_inserted['post_status'], 'create did not force draft status' );
check( 7 === $test_inserted['post_author'], 'create did not attribute the draft to the authorizing user' );
check( 'best-curly-hair-products' === $test_inserted['post_name'], 'create did not sanitize the slug' );
check( ! empty( $test_inserted['meta_input'][ RankOut_Connector_Tools_Create::CREATED_META_KEY ] ), 'create did not mark the draft as RankOut-created' );
check( 500 === $created['post_id'] && false === $created['already_existed'] && 'draft' === $created['status'], 'create returned the wrong result' );
check( false !== strpos( $created['edit_link'], 'post=500' ), 'create did not return an edit link' );

$test_slug_matches = array( $test_posts[500] );
$retry = RankOut_Connector_Tools_Create::create( array( 'title' => 'Best Curly Hair Products', 'slug' => 'best-curly-hair-products', 'content' => '<p>Body</p>' ), 'page', 7 );
check( true === $retry['already_existed'] && 500 === $retry['post_id'], 'identical retry was not treated as a no-op' );
check( throws_message( function () { RankOut_Connector_Tools_Create::create( array( 'title' => 'Different', 'slug' => 'best-curly-hair-products' ), 'page', 7 ); }, 'already used' ), 'create duplicated an existing slug' );
$test_slug_matches = array( (object) array( 'ID' => 42, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Human page', 'post_content' => '', 'post_excerpt' => '', 'post_parent' => 0 ) );
check( throws_message( function () { RankOut_Connector_Tools_Create::create( array( 'title' => 'Human page', 'slug' => 'human-page' ), 'page', 7 ); }, 'already used by page 42' ), 'create reused a human-made page slug' );
$test_slug_matches = array();

$test_insert_filter = function ( $content ) { return str_replace( '<script>x</script>', '', $content ); };
$test_posts = array();
check( throws_message( function () { RankOut_Connector_Tools_Create::create( array( 'title' => 'Filtered', 'slug' => 'filtered', 'content' => '<p>a</p><script>x</script>' ), 'post', 7 ); }, 'was not kept' ), 'filtered content was reported as created' );
check( in_array( 500, $test_deleted, true ), 'a draft whose content was filtered was left behind' );
$test_insert_filter = null;

$test_posts[600] = (object) array( 'ID' => 600, 'post_type' => 'page', 'post_status' => 'draft' );
check( throws_message( function () { RankOut_Connector_Tools_Create::trash_created( array( 'post_id' => 600 ) ); }, 'not created by RankOut' ), 'trash accepted content RankOut did not create' );
$test_meta[600] = array( RankOut_Connector_Tools_Create::CREATED_META_KEY => '2026-10-07T00:00:00+00:00' );
$test_posts[600]->post_status = 'publish';
check( throws_message( function () { RankOut_Connector_Tools_Create::trash_created( array( 'post_id' => 600 ) ); }, 'not a draft' ), 'trash removed a published page' );
$test_posts[600]->post_status = 'draft';
$trashed = RankOut_Connector_Tools_Create::trash_created( array( 'post_id' => 600 ) );
check( true === $trashed['trashed'] && in_array( 600, $test_trashed, true ), 'trash did not trash a RankOut-created draft' );

$test_denied_caps = array( 'edit_pages' );
check( false !== strpos( RankOut_Connector_MCP_Server::authorize_object( 'wp_create_page', array( 'title' => 'T', 'slug' => 't' ), array( 'read_only' => false ), 7 ), 'not allowed to create pages' ), 'page create capability was not enforced' );
check( '' === RankOut_Connector_MCP_Server::authorize_object( 'wp_create_post', array( 'title' => 'T', 'slug' => 't' ), array( 'read_only' => false ), 7 ), 'post create was blocked by an unrelated page capability' );
$test_denied_caps = array( 'delete_post' );
check( false !== strpos( RankOut_Connector_MCP_Server::authorize_object( 'wp_trash_created_post', array( 'post_id' => 600 ), array( 'read_only' => false ), 7 ), 'not allowed to delete' ), 'trash delete capability was not enforced' );
$test_denied_caps = array();

// Structured data (class-tools-schema.php).
check( throws_message( function () { RankOut_Connector_Tools_Schema::update_post_schema( array( 'post_id' => 1, 'schema_type' => 'Article', 'json_ld' => array( '@type' => 'FAQPage', 'headline' => 'x' ) ) ); }, 'does not match schema_type' ), 'a json_ld @type contradicting schema_type was accepted' );
check( throws_message( function () { RankOut_Connector_Tools_Schema::update_post_schema( array( 'post_id' => 1, 'schema_type' => 'Organization', 'json_ld' => array( 'name' => 'x' ) ) ); }, 'schema_type must be one of' ), 'a site-only schema_type was accepted on a post' );
check( throws_message( function () { RankOut_Connector_Tools_Schema::update_site_schema( array( 'schema_type' => 'Article', 'json_ld' => array( 'headline' => 'x' ) ) ); }, 'schema_type must be one of' ), 'a post-only schema_type was accepted site-wide' );

$article = RankOut_Connector_Tools_Schema::update_post_schema( array( 'post_id' => 1, 'schema_type' => 'Article', 'json_ld' => array( 'headline' => 'A post about curly hair' ) ) );
check( 1 === count( $article['schema'] ) && 'https://schema.org' === $article['schema'][0]['@context'] && 'Article' === $article['schema'][0]['@type'], 'Article schema was not stored with a normalized @context/@type' );
$faq = RankOut_Connector_Tools_Schema::update_post_schema( array( 'post_id' => 1, 'schema_type' => 'FAQPage', 'json_ld' => array( 'mainEntity' => array() ) ) );
check( 2 === count( $faq['schema'] ), 'adding a second schema_type on the same post replaced the first instead of keeping both' );
$replaced = RankOut_Connector_Tools_Schema::update_post_schema( array( 'post_id' => 1, 'schema_type' => 'Article', 'json_ld' => array( 'headline' => 'Updated headline' ) ) );
check( 2 === count( $replaced['schema'] ), 're-adding the same schema_type appended instead of replacing it' );
$headlines = array_column( $replaced['schema'], 'headline' );
check( in_array( 'Updated headline', $headlines, true ) && ! in_array( 'A post about curly hair', $headlines, true ), 'replacing a schema_type kept the stale block' );

$org = RankOut_Connector_Tools_Schema::update_site_schema( array( 'schema_type' => 'Organization', 'json_ld' => array( 'name' => 'Mullwood' ) ) );
check( 1 === count( $org['schema'] ) && null === $org['active_seo_plugin'], 'site Organization schema was rejected with no SEO plugin active' );

define( 'RANK_MATH_VERSION', '1.0' );
check( throws_message( function () { RankOut_Connector_Tools_Schema::update_site_schema( array( 'schema_type' => 'Organization', 'json_ld' => array( 'name' => 'Mullwood' ) ) ); }, 'Rank Math SEO is active' ), 'site Organization schema was accepted while Rank Math is active' );
check( throws_message( function () { RankOut_Connector_Tools_Schema::update_site_schema( array( 'schema_type' => 'WebSite', 'json_ld' => array( 'name' => 'Mullwood' ) ) ); }, 'Rank Math SEO is active' ), 'site WebSite schema was accepted while Rank Math is active' );
$local = RankOut_Connector_Tools_Schema::update_site_schema( array( 'schema_type' => 'LocalBusiness', 'json_ld' => array( 'name' => 'Mullwood' ) ) );
check( 2 === count( $local['schema'] ) && 'rankmath' === $local['active_seo_plugin'], 'LocalBusiness schema was blocked even though only Organization/WebSite should be' );
check( 'rankmath' === RankOut_Connector_Tools_Schema::get_post_schema( array( 'post_id' => 1 ) )['active_seo_plugin'], 'get_post_schema did not report the active SEO plugin' );

$test_admin = false;
check( '' !== RankOut_Connector_MCP_Server::authorize_object( 'wp_update_site_schema', array( 'schema_type' => 'LocalBusiness' ), array( 'read_only' => false ), 7 ), 'site schema write was authorized for a non-admin' );
$test_admin = true;
check( '' === RankOut_Connector_MCP_Server::authorize_object( 'wp_update_site_schema', array( 'schema_type' => 'LocalBusiness' ), array( 'read_only' => false ), 7 ), 'site schema write was refused for an admin' );

if ( $failures ) { exit( 1 ); }
echo "Connector P0 regressions passed.\n";
