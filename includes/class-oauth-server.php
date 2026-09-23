<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The token and revocation endpoints of the plugin's own OAuth 2.1 + PKCE
 * authorization server, plus the authorization-code bookkeeping the
 * consent screen (class-consent-screen.php) calls into after an admin
 * approves. There is no client_secret anywhere in this flow — RankOut is
 * a public PKCE client (RANKOUT_CONNECTOR_CLIENT_ID), so possession of the
 * code_verifier is what proves the token request came from whoever
 * started the authorize redirect.
 */
class RankOut_Connector_OAuth_Server {

	const CODE_TTL_SECONDS           = 5 * MINUTE_IN_SECONDS;
	const ACCESS_TOKEN_TTL_SECONDS   = HOUR_IN_SECONDS;
	const REFRESH_TOKEN_TTL_SECONDS  = 180 * DAY_IN_SECONDS;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			RANKOUT_CONNECTOR_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_token' ),
			)
		);
		register_rest_route(
			RANKOUT_CONNECTOR_NAMESPACE,
			'/oauth/revoke',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_revoke' ),
			)
		);
	}

	private static function error_response( $status, $error, $description ) {
		return new WP_REST_Response(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	private static function param( WP_REST_Request $request, $key ) {
		$value = $request->get_param( $key );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Called by the consent screen once the logged-in admin approves.
	 * Returns the raw, one-time authorization code to redirect back with.
	 */
	public static function issue_authorization_code( $redirect_uri, $code_challenge, array $scope, $wp_user_id, $resource ) {
		global $wpdb;
		$code = RankOut_Connector_Auth::generate_secret();
		$wpdb->insert(
			RankOut_Connector_DB::codes_table(),
			array(
				'code_hash'      => hash( 'sha256', $code ),
				'client_id'      => RANKOUT_CONNECTOR_CLIENT_ID,
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $code_challenge,
				'scope'          => implode( ' ', $scope ),
				'wp_user_id'     => $wp_user_id,
				'resource'       => $resource,
				'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + self::CODE_TTL_SECONDS ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		return $code;
	}

	public static function handle_token( WP_REST_Request $request ) {
		$grant_type = self::param( $request, 'grant_type' );
		$client_id  = self::param( $request, 'client_id' );

		if ( $client_id !== RANKOUT_CONNECTOR_CLIENT_ID ) {
			return self::error_response( 400, 'invalid_client', 'Unknown client_id.' );
		}

		if ( 'authorization_code' === $grant_type ) {
			return self::handle_authorization_code_grant( $request );
		}
		if ( 'refresh_token' === $grant_type ) {
			return self::handle_refresh_token_grant( $request );
		}
		return self::error_response( 400, 'unsupported_grant_type', 'Only authorization_code and refresh_token are supported.' );
	}

	private static function handle_authorization_code_grant( WP_REST_Request $request ) {
		global $wpdb;
		$code          = self::param( $request, 'code' );
		$redirect_uri  = self::param( $request, 'redirect_uri' );
		$code_verifier = self::param( $request, 'code_verifier' );

		if ( '' === $code || '' === $code_verifier ) {
			return self::error_response( 400, 'invalid_request', 'code and code_verifier are required.' );
		}

		$table = RankOut_Connector_DB::codes_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1", hash( 'sha256', $code ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// The code is deleted the moment we've read it, whether or not
		// everything below checks out — a code can never be replayed,
		// same one-time-use posture as the backend's own Redis state store.
		if ( $row ) {
			$wpdb->delete( $table, array( 'id' => $row['id'] ), array( '%d' ) );
		}

		if ( ! $row ) {
			return self::error_response( 400, 'invalid_grant', 'Unknown, expired, or already-used authorization code.' );
		}
		if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return self::error_response( 400, 'invalid_grant', 'This authorization code has expired.' );
		}
		if ( ! hash_equals( $row['redirect_uri'], $redirect_uri ) ) {
			return self::error_response( 400, 'invalid_grant', 'redirect_uri does not match the one used to request this code.' );
		}
		$expected_challenge = rtrim( strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( $row['code_challenge'], $expected_challenge ) ) {
			return self::error_response( 400, 'invalid_grant', 'PKCE code_verifier does not match code_challenge.' );
		}

		$scope = RankOut_Connector_Scopes::parse( $row['scope'] );
		return self::issue_token_response( $scope, (int) $row['wp_user_id'] );
	}

	private static function handle_refresh_token_grant( WP_REST_Request $request ) {
		global $wpdb;
		$refresh_token = self::param( $request, 'refresh_token' );
		if ( '' === $refresh_token ) {
			return self::error_response( 400, 'invalid_request', 'refresh_token is required.' );
		}

		$table = RankOut_Connector_DB::tokens_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE refresh_token_hash = %s LIMIT 1", hash( 'sha256', $refresh_token ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! $row || null !== $row['revoked_at'] ) {
			return self::error_response( 400, 'invalid_grant', 'Unknown or revoked refresh token.' );
		}
		if ( strtotime( $row['refresh_expires_at'] . ' UTC' ) < time() ) {
			return self::error_response( 400, 'invalid_grant', 'This refresh token has expired. Reconnect the site.' );
		}
		if ( ! get_userdata( (int) $row['wp_user_id'] ) ) {
			return self::error_response( 400, 'invalid_grant', 'The WordPress user that authorized this connection no longer exists.' );
		}

		// Rotate: the old row is revoked and a fresh access+refresh pair
		// issued, so a leaked/replayed old refresh token stops working
		// the moment the legitimate client refreshes.
		$wpdb->update( $table, array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row['id'] ), array( '%s' ), array( '%d' ) );

		$scope = RankOut_Connector_Scopes::parse( $row['scope'] );
		return self::issue_token_response( $scope, (int) $row['wp_user_id'] );
	}

	private static function issue_token_response( array $scope, $wp_user_id ) {
		global $wpdb;
		$access_token  = RankOut_Connector_Auth::generate_secret();
		$refresh_token = RankOut_Connector_Auth::generate_secret();
		$now           = time();

		$wpdb->insert(
			RankOut_Connector_DB::tokens_table(),
			array(
				'access_token_hash'  => hash( 'sha256', $access_token ),
				'refresh_token_hash' => hash( 'sha256', $refresh_token ),
				'client_id'          => RANKOUT_CONNECTOR_CLIENT_ID,
				'scope'              => implode( ' ', $scope ),
				'wp_user_id'         => $wp_user_id,
				'access_expires_at'  => gmdate( 'Y-m-d H:i:s', $now + self::ACCESS_TOKEN_TTL_SECONDS ),
				'refresh_expires_at' => gmdate( 'Y-m-d H:i:s', $now + self::REFRESH_TOKEN_TTL_SECONDS ),
				'created_at'         => gmdate( 'Y-m-d H:i:s', $now ),
				'updated_at'         => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);

		return new WP_REST_Response(
			array(
				'access_token'  => $access_token,
				'refresh_token' => $refresh_token,
				'token_type'    => 'Bearer',
				'scope'         => implode( ' ', $scope ),
				'expires_in'    => self::ACCESS_TOKEN_TTL_SECONDS,
				'wp_user_id'    => $wp_user_id,
			),
			200
		);
	}

	public static function handle_revoke( WP_REST_Request $request ) {
		global $wpdb;
		$token = self::param( $request, 'token' );
		if ( '' === $token ) {
			// RFC 7009: an invalid/malformed request still returns 200 so
			// callers can't use this endpoint to probe token validity.
			return new WP_REST_Response( array(), 200 );
		}
		$hash  = hash( 'sha256', $token );
		$table = RankOut_Connector_DB::tokens_table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE (access_token_hash = %s OR refresh_token_hash = %s) AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' ),
				$hash,
				$hash
			)
		);
		return new WP_REST_Response( array(), 200 );
	}
}
