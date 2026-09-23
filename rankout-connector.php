<?php
/**
 * Plugin Name:       RankOut Connector
 * Plugin URI:         https://rankout.app
 * Description:        Connects this WordPress site to RankOut so approved SEO/AEO/GEO fixes can be reviewed and applied automatically. Exposes an OAuth 2.1 + PKCE authorization server and an MCP tool endpoint scoped to exactly what RankOut is granted.
 * Version:             1.0.0
 * Requires at least:  6.0
 * Requires PHP:        7.4
 * Author:              RankOut
 * License:             GPL-2.0-or-later
 * Text Domain:         rankout-connector
 * Update URI:          https://github.com/throughout-org/rankout-wp-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Several hosts run a cache layer that ignores WordPress's own REST API
// Cache-Control: no-cache headers and wraps the raw PHP response in its
// own envelope anyway (observed on Bluehost's Endurance Cache: it turns a
// clean JSON-RPC body into `{"data": <body>, "headers": [...], "status":
// 200}`, corrupting every OAuth and MCP response). DONOTCACHEPAGE/
// DONOTCACHEOBJECT/DONOTCACHEDB are the de facto opt-out constants most WP
// caching layers (Endurance Cache included) check — must be defined this
// early, before such a plugin's own bootstrap reads them.
if ( isset( $_SERVER['REQUEST_URI'] ) && (
	false !== strpos( $_SERVER['REQUEST_URI'], '/rankout-connector/v1/' ) ||
	false !== strpos( $_SERVER['REQUEST_URI'], 'rankout_connector_oauth' )
) ) {
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
		define( 'DONOTCACHEOBJECT', true );
	}
	if ( ! defined( 'DONOTCACHEDB' ) ) {
		define( 'DONOTCACHEDB', true );
	}
}

define( 'RANKOUT_CONNECTOR_VERSION', '1.0.0' );
define( 'RANKOUT_CONNECTOR_FILE', __FILE__ );
define( 'RANKOUT_CONNECTOR_DIR', plugin_dir_path( __FILE__ ) );
// The fixed REST namespace every discovery document, OAuth endpoint, and
// the MCP endpoint hang off. Must match CONNECTOR_NAMESPACE in RankOut's
// backend wpConnectorOAuth.service.ts exactly.
define( 'RANKOUT_CONNECTOR_NAMESPACE', 'rankout-connector/v1' );
// The fixed, non-secret public PKCE client id RankOut's backend always
// authenticates as (see WORDPRESS_CONNECTOR_CLIENT_ID in backend/.env).
define( 'RANKOUT_CONNECTOR_CLIENT_ID', 'rankout-dashboard' );

require_once RANKOUT_CONNECTOR_DIR . 'includes/class-db.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-scopes.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-auth.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-oauth-discovery.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-oauth-server.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-consent-screen.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-tool-registry.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-mcp-server.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-admin-page.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/class-updater.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/tools/class-tools-content.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/tools/class-tools-seo.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/tools/class-tools-site-health.php';
require_once RANKOUT_CONNECTOR_DIR . 'includes/tools/class-tools-history.php';

register_activation_hook( __FILE__, array( 'RankOut_Connector_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RankOut_Connector_DB', 'deactivate' ) );

/**
 * Defensive measure for hosts whose caching/monitoring layer wraps REST
 * API responses in its own envelope regardless of Cache-Control headers
 * or the DONOTCACHE* constants above (observed live on Bluehost: a clean
 * JSON-RPC body came back as `{"data": <body>, "headers": [...],
 * "status": 200}` — confirmed NOT a stale-cache replay, something
 * rewrites every live response). Hooked at priority 0 so it runs before
 * any later-registered `rest_pre_serve_request` callback (the usual place
 * such wrapping happens): serves the response ourselves and marks it
 * served, so nothing downstream gets a chance to touch it.
 */
add_filter(
	'rest_pre_serve_request',
	function ( $served, $result, $request, $server ) {
		if ( $served || 0 !== strpos( $request->get_route(), '/' . RANKOUT_CONNECTOR_NAMESPACE . '/' ) ) {
			return $served;
		}
		status_header( $result->get_status() );
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		foreach ( $result->get_headers() as $key => $value ) {
			header( "{$key}: {$value}" );
		}
		echo wp_json_encode( $result->get_data() );
		return true;
	},
	0,
	4
);

/**
 * Boots every subsystem. Order matters only in that the tool registry
 * must exist before the MCP server's rest routes are registered, and both
 * must exist before the admin page (which reads registered scopes for
 * display) — everything else is independent.
 */
function rankout_connector_bootstrap() {
	RankOut_Connector_Scopes::init();
	RankOut_Connector_Tool_Registry::init();
	RankOut_Connector_OAuth_Discovery::init();
	RankOut_Connector_OAuth_Server::init();
	RankOut_Connector_Consent_Screen::init();
	RankOut_Connector_MCP_Server::init();
	RankOut_Connector_Admin_Page::init();
	RankOut_Connector_Updater::init();
}
add_action( 'plugins_loaded', 'rankout_connector_bootstrap' );

/**
 * Runs the DB schema check on every load (cheap: one dbDelta call, WP's
 * own idiom for "create if missing, alter if changed") so an update that
 * ships a new column never requires a manual re-activation step.
 */
add_action( 'plugins_loaded', array( 'RankOut_Connector_DB', 'maybe_upgrade' ), 5 );
