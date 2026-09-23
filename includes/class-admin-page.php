<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings → RankOut Connector: shows whether RankOut currently has an
 * active grant, exactly which permissions it was given, and lets the
 * site admin revoke access without needing to go back to RankOut. Also
 * surfaces the most recent change-history entries for transparency —
 * every write this plugin makes on RankOut's behalf is logged here, not
 * silent.
 */
class RankOut_Connector_Admin_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_rankout_connector_revoke', array( __CLASS__, 'handle_revoke' ) );
	}

	public static function register_menu() {
		add_options_page(
			__( 'RankOut Connector', 'rankout-connector' ),
			__( 'RankOut Connector', 'rankout-connector' ),
			'manage_options',
			'rankout-connector',
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rankout-connector' ) );
		}
		check_admin_referer( 'rankout_connector_revoke' );

		$id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
		if ( $id ) {
			global $wpdb;
			$wpdb->update(
				RankOut_Connector_DB::tokens_table(),
				array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=rankout-connector&revoked=1' ) );
		exit;
	}

	private static function active_connections() {
		global $wpdb;
		$table = RankOut_Connector_DB::tokens_table();
		return $wpdb->get_results(
			"SELECT * FROM {$table} WHERE revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL
			ARRAY_A
		);
	}

	private static function recent_history( $limit = 20 ) {
		global $wpdb;
		$table = RankOut_Connector_DB::history_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$connections = self::active_connections();
		$history     = self::recent_history();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RankOut Connector', 'rankout-connector' ); ?></h1>

			<?php if ( isset( $_GET['revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Access revoked.', 'rankout-connector' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Active connections', 'rankout-connector' ); ?></h2>
			<?php if ( empty( $connections ) ) : ?>
				<p><?php esc_html_e( 'RankOut is not currently connected to this site. Start a connection from your client\'s Data Sources page in RankOut.', 'rankout-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Authorized by', 'rankout-connector' ); ?></th>
							<th><?php esc_html_e( 'Permissions', 'rankout-connector' ); ?></th>
							<th><?php esc_html_e( 'Connected', 'rankout-connector' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $connections as $connection ) :
						$user  = get_userdata( (int) $connection['wp_user_id'] );
						$scope = RankOut_Connector_Scopes::parse( $connection['scope'] );
						?>
						<tr>
							<td><?php echo esc_html( $user ? $user->display_name : sprintf( '#%d', (int) $connection['wp_user_id'] ) ); ?></td>
							<td>
								<?php foreach ( $scope as $item ) : ?>
									<code style="display:inline-block;margin:1px 4px 1px 0;"><?php echo esc_html( $item ); ?></code>
								<?php endforeach; ?>
							</td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connection['created_at'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'rankout_connector_revoke' ); ?>
									<input type="hidden" name="action" value="rankout_connector_revoke">
									<input type="hidden" name="token_id" value="<?php echo esc_attr( $connection['id'] ); ?>">
									<button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js( __( 'Revoke RankOut\'s access to this site?', 'rankout-connector' ) ); ?>');"><?php esc_html_e( 'Revoke', 'rankout-connector' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top:2rem;"><?php esc_html_e( 'Recent changes', 'rankout-connector' ); ?></h2>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No changes have been made through RankOut yet.', 'rankout-connector' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'When', 'rankout-connector' ); ?></th>
							<th><?php esc_html_e( 'Post', 'rankout-connector' ); ?></th>
							<th><?php esc_html_e( 'Change', 'rankout-connector' ); ?></th>
							<th><?php esc_html_e( 'Revision', 'rankout-connector' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $history as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['created_at'] ) ); ?></td>
							<td><?php echo esc_html( get_the_title( (int) $entry['post_id'] ) ?: sprintf( '#%d', (int) $entry['post_id'] ) ); ?></td>
							<td><code><?php echo esc_html( $entry['tool_name'] ); ?></code></td>
							<td><?php echo $entry['revision_id'] ? esc_html( '#' . (int) $entry['revision_id'] ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
