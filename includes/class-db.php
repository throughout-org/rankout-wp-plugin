<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the plugin's three custom tables. Nothing here is a WordPress
 * "option" or postmeta — authorization codes and tokens are bearer
 * secrets and get their own tables so they can be indexed, expired, and
 * wiped independently of anything else in the DB.
 */
class RankOut_Connector_DB {

	const DB_VERSION_OPTION = 'rankout_connector_db_version';
	const DB_VERSION        = '1';

	public static function codes_table() {
		global $wpdb;
		return $wpdb->prefix . 'rankout_connector_codes';
	}

	public static function tokens_table() {
		global $wpdb;
		return $wpdb->prefix . 'rankout_connector_tokens';
	}

	public static function history_table() {
		global $wpdb;
		return $wpdb->prefix . 'rankout_connector_history';
	}

	public static function activate() {
		self::run_migrations();
	}

	public static function deactivate() {
		// Deliberately leaves tables and data in place — deactivating is
		// not the same as uninstalling. uninstall.php handles teardown.
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::run_migrations();
		}
	}

	private static function run_migrations() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$codes_table     = self::codes_table();
		$tokens_table    = self::tokens_table();
		$history_table   = self::history_table();

		// code_hash/access_token_hash/refresh_token_hash store sha256 hex
		// digests, never the raw secret — same posture as WP's own
		// Application Passwords feature.
		$sql = "CREATE TABLE {$codes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_hash CHAR(64) NOT NULL,
			client_id VARCHAR(191) NOT NULL,
			redirect_uri TEXT NOT NULL,
			code_challenge VARCHAR(191) NOT NULL,
			scope TEXT NOT NULL,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			resource TEXT NULL,
			expires_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY code_hash (code_hash)
		) {$charset_collate};

		CREATE TABLE {$tokens_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			access_token_hash CHAR(64) NOT NULL,
			refresh_token_hash CHAR(64) NOT NULL,
			client_id VARCHAR(191) NOT NULL,
			scope TEXT NOT NULL,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			access_expires_at DATETIME NOT NULL,
			refresh_expires_at DATETIME NOT NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY access_token_hash (access_token_hash),
			UNIQUE KEY refresh_token_hash (refresh_token_hash)
		) {$charset_collate};

		CREATE TABLE {$history_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			tool_name VARCHAR(191) NOT NULL,
			args_json LONGTEXT NOT NULL,
			before_json LONGTEXT NOT NULL,
			after_json LONGTEXT NOT NULL,
			revision_id BIGINT UNSIGNED NULL,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public static function drop_all() {
		global $wpdb;
		foreach ( array( self::codes_table(), self::tokens_table(), self::history_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		delete_option( self::DB_VERSION_OPTION );
	}
}
