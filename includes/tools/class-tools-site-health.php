<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_get_site_health / wp_seo_audit_site — the two read-only tools
 * wp.service.ts and easyMcp.service.ts already call by these exact names
 * (see RawSiteHealth/RawSiteAudit in wpConnector.types.ts, which this
 * output is shaped to match field-for-field).
 */
class RankOut_Connector_Tools_Site_Health {

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		RankOut_Connector_Tool_Registry::register(
			'wp_get_site_health',
			'Get WordPress core/theme/plugin update status, PHP version, permalink structure, HTTPS, cron health, sitemap/robots.txt reachability, active SEO plugin, and JSON-LD presence.',
			array( 'type' => 'object', 'properties' => new stdClass() ),
			true,
			'mcp:site_health:read',
			array( __CLASS__, 'get_site_health' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_seo_audit_site',
			'Run a read-only on-page SEO audit over the site\'s posts or pages: titles, meta descriptions, content length, and image alt-text coverage.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_type' => array( 'type' => 'string', 'enum' => array( 'post', 'page' ) ),
					'limit'     => array( 'type' => 'integer', 'default' => 200 ),
				),
				'required'   => array( 'post_type' ),
			),
			true,
			'mcp:reporting:read',
			array( __CLASS__, 'seo_audit_site' )
		);
	}

	private static function ensure_update_apis() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	public static function get_site_health( array $args ) {
		self::ensure_update_apis();

		return array(
			'core'                => self::core_status(),
			'php_version'         => PHP_VERSION,
			'theme'               => self::theme_status(),
			'plugins'             => self::plugins_status(),
			'permalink_structure' => get_option( 'permalink_structure' ) ?: null,
			'https'               => is_ssl() || 0 === strpos( home_url(), 'https://' ),
			'cron'                => self::cron_status(),
			'sitemap'             => self::remote_reachability( home_url( '/wp-sitemap.xml' ) ),
			'robots_txt'          => self::robots_status(),
			'seo_plugin'          => self::detected_seo_plugin(),
			'has_json_ld'         => self::homepage_has_json_ld(),
		);
	}

	private static function core_status() {
		$updates = get_core_updates();
		$latest  = is_array( $updates ) && ! empty( $updates ) ? $updates[0] : null;
		$available = $latest && isset( $latest->response ) && 'upgrade' === $latest->response;
		return array(
			'version'          => get_bloginfo( 'version' ),
			'update_available' => (bool) $available,
			'latest_version'   => $available ? $latest->current : get_bloginfo( 'version' ),
		);
	}

	private static function theme_status() {
		$theme   = wp_get_theme();
		$updates = get_site_transient( 'update_themes' );
		$slug    = $theme->get_stylesheet();
		$available = is_object( $updates ) && ! empty( $updates->response[ $slug ] );
		return array(
			'name'             => $theme->get( 'Name' ),
			'version'          => $theme->get( 'Version' ),
			'update_available' => (bool) $available,
		);
	}

	private static function plugins_status() {
		$all    = get_plugins();
		$active = get_option( 'active_plugins', array() );
		$updates = get_site_transient( 'update_plugins' );
		$outdated = is_object( $updates ) && ! empty( $updates->response ) ? array_keys( $updates->response ) : array();

		$plugins = array();
		foreach ( $all as $file => $data ) {
			$plugins[] = array(
				'file'             => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => in_array( $file, $active, true ),
				'update_available' => in_array( $file, $outdated, true ),
			);
		}

		return array(
			'total'    => count( $all ),
			'active'   => count( $active ),
			'outdated' => count( $outdated ),
			'plugins'  => $plugins,
		);
	}

	private static function cron_status() {
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next     = wp_next_scheduled( 'wp_version_check' );
		// "Overdue" means WordPress's own housekeeping cron hasn't fired
		// in the last day even though something should have scheduled it —
		// a generous grace window since cron only runs on real traffic.
		$overdue  = false === $next || $next < ( time() - DAY_IN_SECONDS );
		return array( 'disabled' => (bool) $disabled, 'next_run_overdue' => (bool) $overdue );
	}

	private static function remote_reachability( $url ) {
		$response = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'reachable' => false, 'status' => null );
		}
		$status = wp_remote_retrieve_response_code( $response );
		return array( 'reachable' => $status >= 200 && $status < 400, 'status' => (int) $status );
	}

	private static function robots_status() {
		$response = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'reachable' => false, 'blocks_all_crawlers' => null );
		}
		$status = wp_remote_retrieve_response_code( $response );
		$reachable = $status >= 200 && $status < 300;
		if ( ! $reachable ) {
			return array( 'reachable' => false, 'blocks_all_crawlers' => null );
		}
		$body   = wp_remote_retrieve_body( $response );
		$blocks = (bool) preg_match( '/User-agent:\s*\*\s*\r?\nDisallow:\s*\/\s*$/mi', $body );
		return array( 'reachable' => true, 'blocks_all_crawlers' => $blocks );
	}

	private static function detected_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO' ) ) {
			return 'aioseo';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		return null;
	}

	private static function homepage_has_json_ld() {
		$response = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $response );
		return (bool) preg_match( '/<script[^>]+type=["\']application\/ld\+json["\']/i', $body );
	}

	// --- wp_seo_audit_site ---

	public static function seo_audit_site( array $args ) {
		$post_type = in_array( $args['post_type'] ?? '', array( 'post', 'page' ), true ) ? $args['post_type'] : 'post';
		$limit     = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 200;

		$posts = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		$issues_by_post = array();
		$counts         = array(
			'missing_title'       => 0,
			'missing_description' => 0,
			'title_too_long'      => 0,
			'thin_content'        => 0,
			'missing_alt_text'    => 0,
		);

		foreach ( $posts as $post ) {
			$found = array();
			$title = get_the_title( $post );
			if ( '' === trim( $title ) ) {
				$found[] = 'missing_title';
			} elseif ( mb_strlen( $title ) > 60 ) {
				$found[] = 'title_too_long';
			}

			if ( '' === trim( self::meta_description_for( $post ) ) ) {
				$found[] = 'missing_description';
			}

			$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
			if ( $word_count < 300 ) {
				$found[] = 'thin_content';
			}

			if ( self::has_image_missing_alt( $post->post_content ) ) {
				$found[] = 'missing_alt_text';
			}

			foreach ( $found as $issue ) {
				$counts[ $issue ]++;
			}
			if ( ! empty( $found ) ) {
				$issues_by_post[] = array(
					'post_id' => $post->ID,
					'title'   => $title,
					'link'    => get_permalink( $post ),
					'issues'  => $found,
					'severity' => count( $found ),
				);
			}
		}

		usort( $issues_by_post, function ( $a, $b ) {
			return $b['severity'] <=> $a['severity'];
		} );

		return array(
			'postType'      => $post_type,
			'summary'       => array(
				'scanned'      => count( $posts ),
				'with_issues'  => count( $issues_by_post ),
				'issue_counts' => $counts,
			),
			'distributions' => array( 'issue_counts' => $counts ),
			'topIssues'     => array_slice( $issues_by_post, 0, 20 ),
			'fixPriority'   => array_map(
				function ( $entry ) {
					return array( 'post_id' => $entry['post_id'], 'title' => $entry['title'], 'severity' => $entry['severity'] );
				},
				array_slice( $issues_by_post, 0, 50 )
			),
		);
	}

	private static function meta_description_for( $post ) {
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description' ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		global $wpdb;
		$aioseo_description = $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d", $post->ID ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( is_string( $aioseo_description ) && '' !== trim( $aioseo_description ) ) {
			return $aioseo_description;
		}
		return $post->post_excerpt;
	}

	private static function has_image_missing_alt( $content ) {
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
			return false;
		}
		foreach ( $matches[0] as $tag ) {
			if ( ! preg_match( '/\balt\s*=\s*["\'][^"\']+["\']/i', $tag ) ) {
				return true;
			}
		}
		return false;
	}
}

RankOut_Connector_Tools_Site_Health::register();
