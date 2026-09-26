<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bearer-token validation shared by every authenticated REST route this
 * plugin registers. Deliberately NOT WordPress's cookie/nonce auth or
 * Application Passwords — RankOut's backend is a server-to-server client
 * holding an opaque OAuth access token this plugin itself issued.
 */
class RankOut_Connector_Auth {

	private static function hash( $secret ) {
		return hash( 'sha256', $secret );
	}

	public static function generate_secret() {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Reads the Authorization header, validates it against a live,
	 * unrevoked, unexpired access token, and returns the token row
	 * (with `scope` decoded to an array) or a WP_Error describing exactly
	 * why authentication failed — never a bare boolean, so REST route
	 * permission_callbacks can surface a real 401 reason.
	 *
	 * @return array|WP_Error
	 */
	public static function authenticate( WP_REST_Request $request ) {
		$header = $request->get_header( 'authorization' );
		if ( ! $header || stripos( $header, 'Bearer ' ) !== 0 ) {
			return new WP_Error( 'rankout_connector_no_token', 'Missing Authorization: Bearer header.', array( 'status' => 401 ) );
		}
		$token = trim( substr( $header, 7 ) );
		if ( '' === $token ) {
			return new WP_Error( 'rankout_connector_no_token', 'Missing bearer token.', array( 'status' => 401 ) );
		}

		global $wpdb;
		$table = RankOut_Connector_DB::tokens_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE access_token_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::hash( $token )
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return new WP_Error( 'rankout_connector_invalid_token', 'Unknown or revoked access token.', array( 'status' => 401 ) );
		}
		if ( null !== $row['revoked_at'] ) {
			return new WP_Error( 'rankout_connector_invalid_token', 'This access token has been revoked.', array( 'status' => 401 ) );
		}
		if ( strtotime( $row['access_expires_at'] . ' UTC' ) < time() ) {
			return new WP_Error( 'rankout_connector_expired_token', 'This access token has expired. Refresh it.', array( 'status' => 401 ) );
		}
		$user_error = self::authorize_token_user( (int) $row['wp_user_id'] );
		if ( is_wp_error( $user_error ) ) {
			return $user_error;
		}

		$row['scope'] = RankOut_Connector_Scopes::parse( $row['scope'] );
		return $row;
	}

	public static function authorize_token_user( $wp_user_id ) {
		if ( ! get_userdata( $wp_user_id ) ) {
			return new WP_Error( 'rankout_connector_invalid_token', 'The WordPress user that authorized this connection no longer exists.', array( 'status' => 401 ) );
		}
		if ( ! user_can( $wp_user_id, 'manage_options' ) ) {
			return new WP_Error( 'rankout_connector_permission_revoked', 'The WordPress user that authorized this connection is no longer a site administrator. Reconnect RankOut with a current administrator.', array( 'status' => 403 ) );
		}
		return true;
	}
}
