<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the `home_url('?rankout_connector_oauth=authorize')` consent
 * screen (the "authorization_endpoint" every discovery document points
 * at) and turns an admin's approval into a one-time authorization code.
 * Requires an is_user_logged_in() WordPress admin session — this is the
 * one step in the whole flow that is NOT a server-to-server call, it's a
 * real browser tab the site's own admin is looking at, same as approving
 * any other third-party OAuth app.
 */
class RankOut_Connector_Consent_Screen {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function maybe_handle() {
		if ( ! isset( $_GET['rankout_connector_oauth'] ) || 'authorize' !== $_GET['rankout_connector_oauth'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! is_user_logged_in() ) {
			auth_redirect();
			exit;
		}

		$params = self::read_request_params();
		$error  = self::validate_params( $params );

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			self::handle_decision( $params, $error );
			exit;
		}

		self::render( $params, $error );
		exit;
	}

	private static function read_request_params() {
		return array(
			'client_id'             => isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'redirect_uri'          => isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'response_type'         => isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'scope'                 => isset( $_GET['scope'] ) ? sanitize_text_field( wp_unslash( $_GET['scope'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'state'                 => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'code_challenge'        => isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'code_challenge_method' => isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'resource'              => isset( $_GET['resource'] ) ? esc_url_raw( wp_unslash( $_GET['resource'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		);
	}

	private static function validate_params( array $params ) {
		if ( RANKOUT_CONNECTOR_CLIENT_ID !== $params['client_id'] ) {
			return 'Unknown client_id. This authorization link was not issued for this site\'s RankOut Connector.';
		}
		if ( 'code' !== $params['response_type'] ) {
			return 'Unsupported response_type — only "code" is supported.';
		}
		// Deliberately NOT wp_http_validate_url() — that function is WordPress's
		// outbound-request SSRF guard and rejects localhost/private hosts and
		// non-standard ports on purpose. redirect_uri here is never fetched by
		// this server; it's only handed to the admin's own browser in a 302,
		// and RankOut's backend (the thing actually listening on it) is
		// legitimately on a different host/port than this WordPress site —
		// including localhost during development. The real protection against
		// an attacker-supplied redirect_uri is the exact-match check against
		// the value stored with the authorization code at token-exchange time
		// (see class-oauth-server.php), not a same-origin/allowlist check here.
		$parsed_redirect = wp_parse_url( $params['redirect_uri'] );
		if ( '' === $params['redirect_uri'] || ! is_array( $parsed_redirect ) || empty( $parsed_redirect['host'] ) || ! in_array( strtolower( $parsed_redirect['scheme'] ?? '' ), array( 'http', 'https' ), true ) ) {
			return 'Missing or invalid redirect_uri.';
		}
		if ( 'S256' !== $params['code_challenge_method'] || '' === $params['code_challenge'] ) {
			return 'This request is missing required PKCE parameters (code_challenge_method=S256).';
		}
		if ( '' === $params['state'] ) {
			return 'Missing state parameter.';
		}
		return '';
	}

	private static function handle_decision( array $params, $error ) {
		check_admin_referer( 'rankout_connector_oauth_consent' );

		if ( $error ) {
			wp_die( esc_html( $error ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You must be a site administrator to connect RankOut.', 'rankout-connector' ) );
		}

		$decision = isset( $_POST['rankout_connector_decision'] ) ? sanitize_text_field( wp_unslash( $_POST['rankout_connector_decision'] ) ) : '';

		if ( 'deny' === $decision ) {
			self::redirect_with(
				$params['redirect_uri'],
				array(
					'error' => 'access_denied',
					'state' => $params['state'],
				)
			);
			return;
		}

		$granted_scope = RankOut_Connector_Scopes::parse( $params['scope'] );
		$code          = RankOut_Connector_OAuth_Server::issue_authorization_code(
			$params['redirect_uri'],
			$params['code_challenge'],
			$granted_scope,
			get_current_user_id(),
			$params['resource']
		);

		self::redirect_with(
			$params['redirect_uri'],
			array(
				'code'  => $code,
				'state' => $params['state'],
			)
		);
	}

	private static function redirect_with( $redirect_uri, array $query ) {
		$separator = ( false === strpos( $redirect_uri, '?' ) ) ? '?' : '&';
		wp_redirect( $redirect_uri . $separator . http_build_query( $query ) ); // phpcs:ignore WordPress.Security.SafeRedirect
	}

	private static function render( array $params, $error ) {
		$scopes = RankOut_Connector_Scopes::parse( $params['scope'] );
		nocache_headers();
		?>
<!doctype html>
<html>
<head>
	<meta charset="utf-8">
	<title><?php esc_html_e( 'Connect RankOut', 'rankout-connector' ); ?></title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f0f0f1; margin:0; padding:2.5rem 1rem; }
		.card { max-width:30rem; margin:0 auto; background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:2rem; }
		h1 { font-size:1.25rem; margin-top:0; }
		ul { padding-left:1.1rem; }
		li { margin-bottom:.35rem; font-size:.9rem; color:#3c434a; }
		.actions { margin-top:1.5rem; display:flex; gap:.75rem; }
		button { font-size:.95rem; padding:.55rem 1.1rem; border-radius:4px; border:1px solid transparent; cursor:pointer; }
		.approve { background:#2271b1; color:#fff; }
		.deny { background:#fff; border-color:#c3c4c7; color:#3c434a; }
		.error { color:#b32d2e; font-size:.9rem; }
	</style>
</head>
<body>
	<div class="card">
		<h1><?php esc_html_e( 'RankOut wants to connect to this site', 'rankout-connector' ); ?></h1>
		<?php if ( $error ) : ?>
			<p class="error"><?php echo esc_html( $error ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'This will let RankOut do the following on your behalf, only for the permissions listed below:', 'rankout-connector' ); ?></p>
			<ul>
				<?php foreach ( $scopes as $scope ) : ?>
					<li><?php echo esc_html( RankOut_Connector_Scopes::label( $scope ) ); ?></li>
				<?php endforeach; ?>
				<?php if ( empty( $scopes ) ) : ?>
					<li><?php esc_html_e( 'No recognized permissions were requested.', 'rankout-connector' ); ?></li>
				<?php endif; ?>
			</ul>
			<form method="post">
				<?php wp_nonce_field( 'rankout_connector_oauth_consent' ); ?>
				<input type="hidden" name="client_id" value="<?php echo esc_attr( $params['client_id'] ); ?>">
				<div class="actions">
					<button class="approve" type="submit" name="rankout_connector_decision" value="approve"><?php esc_html_e( 'Approve', 'rankout-connector' ); ?></button>
					<button class="deny" type="submit" name="rankout_connector_decision" value="deny"><?php esc_html_e( 'Deny', 'rankout-connector' ); ?></button>
				</div>
			</form>
		<?php endif; ?>
	</div>
</body>
</html>
		<?php
	}
}
