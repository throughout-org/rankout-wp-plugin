<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'RANKOUT_CONNECTOR_CLIENT_ID', 'rankout-dashboard' );
function add_action() {}
function apply_filters( $name, $value ) { return $value; }
function esc_url_raw( $value ) { return $value; }
function wp_parse_url( $value ) { return parse_url( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ); }
function is_protected_meta( $key ) { return 0 === strpos( $key, '_' ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_post( $id ) { return (object) array( 'ID' => $id, 'post_type' => 99 === $id ? 'product' : 'page' ); }
function update_post_meta() { return true; }
function get_post_meta( $post_id, $key = '', $single = false ) { return $key ? '' : array(); }
class WP_Error { public $code; public function __construct( $code ) { $this->code = $code; } }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
$test_admin = true;
$test_edit = true;
function get_userdata( $id ) { return $id ? (object) array( 'ID' => $id ) : false; }
function user_can( $id, $capability ) { global $test_admin, $test_edit; return 'manage_options' === $capability ? $test_admin : $test_edit; }

require_once dirname( __DIR__ ) . '/includes/class-schema-validator.php';
require_once dirname( __DIR__ ) . '/includes/class-tool-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-consent-screen.php';
require_once dirname( __DIR__ ) . '/includes/class-auth.php';
require_once dirname( __DIR__ ) . '/includes/class-mcp-server.php';
require_once dirname( __DIR__ ) . '/includes/tools/class-tools-content.php';
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

check( throws_message( function () { RankOut_Connector_Tools_Content::get_post_meta( array( 'post_id' => 99 ) ); }, 'limited to WordPress posts and pages' ), 'WooCommerce product meta was exposed by generic tool' );
check( throws_message( function () { RankOut_Connector_Tools_Content::update_post_meta( array( 'post_id' => 1, 'meta' => array( 'total_sales' => 500 ) ) ); }, 'allowlist' ), 'unapproved public meta key accepted' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1 ) ); }, 'at least one supported SEO field' ), 'empty SEO write reported success' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1, 'meta_description' => 'wrong key' ) ); }, 'at least one supported SEO field' ), 'meta_description mismatch reported success' );
check( throws_message( function () { RankOut_Connector_Tools_SEO::yoast_update( array( 'post_id' => 1, 'description' => 'Expected' ) ); }, 'did not persist' ), 'failed SEO read-back reported success' );

if ( $failures ) { exit( 1 ); }
echo "Connector P0 regressions passed.\n";
