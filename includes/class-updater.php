<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gives this plugin a normal wp-admin "update available" notice and
 * one-click "Update now" even though it's not on WordPress.org — checks
 * GitHub Releases on https://github.com/throughout-org/rankout-wp-plugin
 * instead. Pushing a commit alone does nothing here; a release (tag)
 * with a version higher than the installed plugin's `Version:` header is
 * what wp-admin picks up, on its normal update-check schedule (or
 * immediately via Dashboard → Updates → "Check again").
 *
 * For the update to install cleanly as an in-place upgrade of the SAME
 * active plugin (not a second, inactive copy), the release should attach
 * a zip asset named exactly "rankout-connector.zip" whose single
 * top-level folder is "rankout-connector" — the same shape
 * `zip -r rankout-connector.zip rankout-connector` already produces. If a
 * release has no such asset, this falls back to GitHub's auto-generated
 * source zip and renames its (mismatched) extracted folder itself.
 */
class RankOut_Connector_Updater {

	const GITHUB_REPO = 'throughout-org/rankout-wp-plugin';
	const SLUG         = 'rankout-connector';
	const CACHE_KEY    = 'rankout_connector_latest_release';
	const CACHE_TTL     = 6 * HOUR_IN_SECONDS;
	const CACHE_TTL_FAILURE = 15 * MINUTE_IN_SECONDS;

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'add_row_meta' ), 10, 2 );
	}

	private static function plugin_basename() {
		return plugin_basename( RANKOUT_CONNECTOR_FILE );
	}

	/**
	 * @return array|null Decoded GitHub "latest release" API response, or
	 *                     null if none exists yet or GitHub couldn't be
	 *                     reached — callers treat null as "nothing to
	 *                     report", never as an error surfaced to the admin.
	 */
	private static function fetch_latest_release() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			array(
				'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'RankOut-Connector-WP-Plugin' ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, false, self::CACHE_TTL_FAILURE );
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, false, self::CACHE_TTL_FAILURE );
			return null;
		}

		set_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	private static function download_url( array $release ) {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && self::SLUG . '.zip' === $asset['name'] ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return isset( $release['zipball_url'] ) ? $release['zipball_url'] : null;
	}

	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );
		$basename        = self::plugin_basename();

		if ( version_compare( $latest_version, RANKOUT_CONNECTOR_VERSION, '<=' ) ) {
			// Explicitly mark up to date so wp-admin doesn't show a stale
			// "update available" notice from a previous, larger version
			// left in cache after a rollback.
			$transient->no_update[ $basename ] = self::info_object( $latest_version, $release );
			return $transient;
		}

		$package = self::download_url( $release );
		if ( ! $package ) {
			return $transient;
		}

		$item                              = self::info_object( $latest_version, $release );
		$item->package                     = $package;
		$transient->response[ $basename ]  = $item;
		unset( $transient->no_update[ $basename ] );
		return $transient;
	}

	private static function info_object( $version, array $release ) {
		return (object) array(
			'id'            => 'github.com/' . self::GITHUB_REPO,
			'slug'          => self::SLUG,
			'plugin'        => self::plugin_basename(),
			'new_version'   => $version,
			'url'           => 'https://github.com/' . self::GITHUB_REPO,
			'package'       => self::download_url( $release ),
			'icons'         => array(),
			'banners'       => array(),
			'tested'        => '',
			'requires_php'  => '7.4',
		);
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::fetch_latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'RankOut Connector',
			'slug'          => self::SLUG,
			'version'       => ltrim( $release['tag_name'], 'v' ),
			'author'        => '<a href="https://rankout.app">RankOut</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'download_link' => self::download_url( $release ),
			'sections'      => array(
				'description' => 'Connects this WordPress site to RankOut so approved SEO/AEO/GEO fixes can be reviewed and applied automatically.',
				'changelog'   => wpautop( wp_kses_post( $release['body'] ?? '' ) ),
			),
		);
	}

	/**
	 * Only relevant on the zipball fallback (no rankout-connector.zip
	 * release asset) — GitHub's auto zip extracts to
	 * "throughout-org-rankout-wp-plugin-<sha>", which WordPress would
	 * otherwise install as a second, inactive plugin folder instead of
	 * overwriting this one.
	 */
	public static function fix_source_dir( $source, $remote_source, $upgrader, $args ) {
		if ( empty( $args['plugin'] ) || self::plugin_basename() !== $args['plugin'] ) {
			return $source;
		}
		$folder = basename( untrailingslashit( $source ) );
		if ( self::SLUG === $folder ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}
		$corrected = trailingslashit( dirname( $source ) ) . self::SLUG . '/';
		if ( $wp_filesystem->move( $source, $corrected, true ) ) {
			return $corrected;
		}
		return $source;
	}

	public static function add_row_meta( $links, $file ) {
		if ( self::plugin_basename() === $file ) {
			$links[] = '<a href="https://github.com/' . self::GITHUB_REPO . '" target="_blank" rel="noopener">' . esc_html__( 'GitHub', 'rankout-connector' ) . '</a>';
		}
		return $links;
	}
}
