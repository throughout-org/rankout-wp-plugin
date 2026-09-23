<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central catalog of every MCP tool this plugin exposes. Each concrete
 * tools/class-tools-*.php file registers its tools here via the
 * `rankout_connector_register_tools` action — nothing calls WordPress
 * directly from class-mcp-server.php, so the JSON-RPC transport layer
 * stays fully decoupled from what the tools actually do.
 *
 * `read_only` gates two independent things on the RankOut side: which
 * tools implementation.service.ts's Claude loop may call while just
 * exploring (see explore_readonly in that file), and which scope suffix
 * (":read" vs ":write") this plugin requires for the call.
 */
class RankOut_Connector_Tool_Registry {

	private static $tools = array();

	public static function init() {
		self::$tools = array();
		/**
		 * Fires once, after every tool file has been require_once'd by
		 * rankout-connector.php but before any MCP request is served.
		 * Each tools/class-tools-*.php hooks this to call
		 * RankOut_Connector_Tool_Registry::register(...) for its tools.
		 */
		do_action( 'rankout_connector_register_tools' );
	}

	/**
	 * @param string   $name           MCP tool name, e.g. "wp_get_post". Must match
	 *                                 the exact name RankOut's backend calls by —
	 *                                 see implementation.service.ts's VALIDATION map.
	 * @param string   $description
	 * @param array    $input_schema   JSON Schema object for the tool's arguments.
	 * @param bool     $read_only
	 * @param string   $required_scope One of RankOut_Connector_Scopes::ALL's keys.
	 * @param callable $handler        function(array $args, int $wp_user_id): array
	 *                                 Returns a plain array to be JSON-encoded as the
	 *                                 tool's text result. Throw a RuntimeException
	 *                                 (or any Exception) to report a tool error —
	 *                                 the message becomes the MCP error text.
	 */
	public static function register( $name, $description, array $input_schema, $read_only, $required_scope, callable $handler ) {
		self::$tools[ $name ] = array(
			'name'           => $name,
			'description'    => $description,
			'input_schema'   => $input_schema,
			'read_only'      => (bool) $read_only,
			'required_scope' => $required_scope,
			'handler'        => $handler,
		);
	}

	/** @return array<string,array> every registered tool, keyed by name. */
	public static function all() {
		return self::$tools;
	}

	/** @return array<string,array> tools whose required_scope is in $granted_scopes. */
	public static function for_scopes( array $granted_scopes ) {
		return array_filter(
			self::$tools,
			function ( $tool ) use ( $granted_scopes ) {
				return RankOut_Connector_Scopes::grants( $granted_scopes, $tool['required_scope'] );
			}
		);
	}

	public static function get( $name ) {
		return isset( self::$tools[ $name ] ) ? self::$tools[ $name ] : null;
	}
}
