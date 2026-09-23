<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The full scope vocabulary RankOut's backend may ever request (mirrors
 * DEFAULT_SCOPE in wpConnectorOAuth.service.ts). Only a subset has a real
 * tool behind it today — see class-tool-registry.php's REQUIRED_SCOPE map
 * for which scopes are actually load-bearing right now. The rest are
 * declared here so the consent screen and discovery metadata can name
 * them honestly (`scopes_supported`) without granting anything until a
 * matching tool exists.
 */
class RankOut_Connector_Scopes {

	const ALL = array(
		'mcp:posts:read'      => 'Read post and page content',
		'mcp:posts:write'     => 'Edit post and page content',
		'mcp:media:read'      => 'Read media library items',
		'mcp:taxonomies:read' => 'Read categories and tags',
		'mcp:plugins:read'    => 'Read installed plugin and theme info',
		'mcp:settings:read'   => 'Read general site settings',
		'mcp:yoast:read'      => 'Read Yoast SEO metadata',
		'mcp:yoast:write'     => 'Edit Yoast SEO metadata',
		'mcp:aioseo:read'     => 'Read All in One SEO metadata',
		'mcp:aioseo:write'    => 'Edit All in One SEO metadata',
		'mcp:rankmath:read'   => 'Read Rank Math metadata',
		'mcp:rankmath:write'  => 'Edit Rank Math metadata',
		'mcp:history:read'    => 'Read RankOut Connector change history',
		'mcp:site_health:read' => 'Read site health diagnostics',
		'mcp:reporting:read'  => 'Run read-only SEO content audits',
		'mcp:schema:read'     => 'Read structured data (schema.org) markup',
		'mcp:schema:write'    => 'Edit structured data (schema.org) markup',
		'mcp:geo:read'        => 'Read local/GEO business information',
		'mcp:geo:write'       => 'Edit local/GEO business information',
		'mcp:aeo:read'        => 'Read answer-engine optimization content',
		'mcp:aeo:write'       => 'Edit answer-engine optimization content',
		'mcp:eeat:read'       => 'Read author/E-E-A-T signals',
	);

	public static function init() {
		// No hooks to register — this class is a static lookup table.
		// The method exists so bootstrap can treat every subsystem
		// uniformly and so a future release can add filters here without
		// touching rankout-connector.php.
	}

	public static function supported() {
		return array_keys( self::ALL );
	}

	public static function label( $scope ) {
		return isset( self::ALL[ $scope ] ) ? self::ALL[ $scope ] : $scope;
	}

	/**
	 * Parses a space-delimited OAuth scope string into a clean array,
	 * dropping anything not in the known vocabulary — an unrecognized
	 * scope can never be silently granted.
	 */
	public static function parse( $scope_string ) {
		$requested = preg_split( '/\s+/', trim( (string) $scope_string ) );
		return array_values( array_intersect( $requested, self::supported() ) );
	}

	public static function grants( array $granted_scopes, $required_scope ) {
		return in_array( $required_scope, $granted_scopes, true );
	}
}
