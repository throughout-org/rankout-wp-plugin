<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves both discovery documents RankOut's backend tries, in the order
 * it tries them (see wpConnectorOAuth.service.ts's discover()):
 *
 *  1. RFC 8414 authorization-server metadata at the SITE ROOT
 *     `/.well-known/oauth-authorization-server` — many hosts shadow this
 *     with their own static file server, so it's registered directly
 *     against the raw request path rather than as a REST route (a REST
 *     route can never live outside `/wp-json/`).
 *  2. RFC 9728 protected-resource metadata under this plugin's own REST
 *     namespace, which always reaches PHP even when #1 is shadowed.
 */
class RankOut_Connector_OAuth_Discovery {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_root_metadata' ), 0 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function authorization_server_metadata() {
		return array(
			'issuer'                                => home_url( '/' ),
			'authorization_endpoint'                 => home_url( '/?rankout_connector_oauth=authorize' ),
			'token_endpoint'                          => rest_url( RANKOUT_CONNECTOR_NAMESPACE . '/oauth/token' ),
			'revocation_endpoint'                     => rest_url( RANKOUT_CONNECTOR_NAMESPACE . '/oauth/revoke' ),
			'response_types_supported'                => array( 'code' ),
			'grant_types_supported'                   => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'        => array( 'S256' ),
			'token_endpoint_auth_methods_supported'   => array( 'none' ),
			'scopes_supported'                        => RankOut_Connector_Scopes::supported(),
		);
	}

	public static function maybe_serve_root_metadata() {
		$path = wp_parse_url( home_url( '/.well-known/oauth-authorization-server' ), PHP_URL_PATH );
		$requested = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( $requested !== $path ) {
			return;
		}
		wp_send_json( self::authorization_server_metadata(), 200 );
	}

	public static function register_routes() {
		register_rest_route(
			RANKOUT_CONNECTOR_NAMESPACE,
			'/.well-known/oauth-protected-resource',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'protected_resource_metadata' ),
			)
		);
		// Some clients probe RFC 8414 under the namespace too, when the
		// root path is shadowed by the host — cheap to serve the same
		// document here as well.
		register_rest_route(
			RANKOUT_CONNECTOR_NAMESPACE,
			'/.well-known/oauth-authorization-server',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => function () {
					return new WP_REST_Response( self::authorization_server_metadata(), 200 );
				},
			)
		);
	}

	public static function protected_resource_metadata() {
		return new WP_REST_Response(
			array(
				'resource'              => rest_url( RANKOUT_CONNECTOR_NAMESPACE . '/mcp' ),
				'authorization_servers' => array( home_url( '/' ) ),
				'scopes_supported'      => RankOut_Connector_Scopes::supported(),
			),
			200
		);
	}
}
