<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_yoast_get_post_seo / wp_yoast_update_post_seo,
 * wp_aioseo_get_post_seo / wp_aioseo_update_post_seo,
 * wp_rm_get_post_seo / wp_rm_update_post_seo — the exact tool names
 * implementation.service.ts's validationReadFor() map references. Each
 * pair only registers itself if that SEO plugin is actually active
 * (detected at rankout_connector_register_tools time, same request
 * every plugin is loaded on) — if none are active, none of these six
 * tools appear in tools/list at all, so Claude never even sees them as
 * an option.
 *
 * AIOSEO's field mapping below covers the columns confirmed stable
 * across All in One SEO v4.x (`aioseo_posts.title/description/
 * canonical_url/robots_noindex/robots_nofollow`) — verify against the
 * exact installed AIOSEO version before relying on it for anything
 * beyond these five fields; other columns have moved across releases.
 */
class RankOut_Connector_Tools_SEO {

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	private static function post_seo_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'post_id'        => array( 'type' => 'integer' ),
				'title'          => array( 'type' => 'string' ),
				'description'    => array( 'type' => 'string' ),
				'focus_keyword'  => array( 'type' => 'string' ),
				'canonical_url'  => array( 'type' => 'string' ),
				'noindex'        => array( 'type' => 'boolean' ),
				'nofollow'       => array( 'type' => 'boolean' ),
			),
			'required'   => array( 'post_id' ),
		);
	}

	private static function get_schema() {
		return array(
			'type'       => 'object',
			'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'post_id' ),
		);
	}

	public static function register_tools() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			RankOut_Connector_Tool_Registry::register(
				'wp_yoast_get_post_seo',
				'Get a post\'s Yoast SEO title, meta description, focus keyword, canonical URL, and robots settings.',
				self::get_schema(),
				true,
				'mcp:yoast:read',
				array( __CLASS__, 'yoast_get' )
			);
			RankOut_Connector_Tool_Registry::register(
				'wp_yoast_update_post_seo',
				'Set a post\'s Yoast SEO title, meta description, focus keyword, canonical URL, and/or robots settings. Only fields provided are changed.',
				self::post_seo_schema(),
				false,
				'mcp:yoast:write',
				array( __CLASS__, 'yoast_update' )
			);
		}

		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO' ) ) {
			RankOut_Connector_Tool_Registry::register(
				'wp_aioseo_get_post_seo',
				'Get a post\'s All in One SEO title, meta description, canonical URL, and robots settings.',
				self::get_schema(),
				true,
				'mcp:aioseo:read',
				array( __CLASS__, 'aioseo_get' )
			);
			RankOut_Connector_Tool_Registry::register(
				'wp_aioseo_update_post_seo',
				'Set a post\'s All in One SEO title, meta description, canonical URL, and/or robots settings. Only fields provided are changed.',
				self::post_seo_schema(),
				false,
				'mcp:aioseo:write',
				array( __CLASS__, 'aioseo_update' )
			);
		}

		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			RankOut_Connector_Tool_Registry::register(
				'wp_rm_get_post_seo',
				'Get a post\'s Rank Math title, meta description, focus keyword, canonical URL, and robots settings.',
				self::get_schema(),
				true,
				'mcp:rankmath:read',
				array( __CLASS__, 'rankmath_get' )
			);
			RankOut_Connector_Tool_Registry::register(
				'wp_rm_update_post_seo',
				'Set a post\'s Rank Math title, meta description, focus keyword, canonical URL, and/or robots settings. Only fields provided are changed.',
				self::post_seo_schema(),
				false,
				'mcp:rankmath:write',
				array( __CLASS__, 'rankmath_update' )
			);
		}
	}

	private static function require_post( $post_id ) {
		if ( ! get_post( $post_id ) ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
	}

	// --- Yoast SEO: standard postmeta keys, unchanged since Yoast 1.x ---

	public static function yoast_get( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		return array(
			'post_id'       => $post_id,
			'title'         => get_post_meta( $post_id, '_yoast_wpseo_title', true ) ?: null,
			'description'   => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ) ?: null,
			'focus_keyword' => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ) ?: null,
			'canonical_url' => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ) ?: null,
			'noindex'       => '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true ),
			'nofollow'      => '1' === get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ),
		);
	}

	public static function yoast_update( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$map = array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
			'canonical_url' => '_yoast_wpseo_canonical',
		);
		foreach ( $map as $arg_key => $meta_key ) {
			if ( array_key_exists( $arg_key, $args ) ) {
				update_post_meta( $post_id, $meta_key, (string) $args[ $arg_key ] );
			}
		}
		if ( array_key_exists( 'noindex', $args ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $args['noindex'] ? '1' : '2' );
		}
		if ( array_key_exists( 'nofollow', $args ) ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $args['nofollow'] ? '1' : '0' );
		}
		return self::yoast_get( array( 'post_id' => $post_id ) );
	}

	// --- Rank Math: standard postmeta keys, stable since Rank Math 1.x ---

	public static function rankmath_get( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		$robots = is_array( $robots ) ? $robots : array();
		return array(
			'post_id'       => $post_id,
			'title'         => get_post_meta( $post_id, 'rank_math_title', true ) ?: null,
			'description'   => get_post_meta( $post_id, 'rank_math_description', true ) ?: null,
			'focus_keyword' => get_post_meta( $post_id, 'rank_math_focus_keyword', true ) ?: null,
			'canonical_url' => get_post_meta( $post_id, 'rank_math_canonical_url', true ) ?: null,
			'noindex'       => in_array( 'noindex', $robots, true ),
			'nofollow'      => in_array( 'nofollow', $robots, true ),
		);
	}

	public static function rankmath_update( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$map = array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
			'canonical_url' => 'rank_math_canonical_url',
		);
		foreach ( $map as $arg_key => $meta_key ) {
			if ( array_key_exists( $arg_key, $args ) ) {
				update_post_meta( $post_id, $meta_key, (string) $args[ $arg_key ] );
			}
		}
		if ( array_key_exists( 'noindex', $args ) || array_key_exists( 'nofollow', $args ) ) {
			$current = self::rankmath_get( array( 'post_id' => $post_id ) );
			$noindex = array_key_exists( 'noindex', $args ) ? (bool) $args['noindex'] : $current['noindex'];
			$nofollow = array_key_exists( 'nofollow', $args ) ? (bool) $args['nofollow'] : $current['nofollow'];
			$robots  = array();
			if ( $noindex ) {
				$robots[] = 'noindex';
			}
			if ( $nofollow ) {
				$robots[] = 'nofollow';
			}
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}
		return self::rankmath_get( array( 'post_id' => $post_id ) );
	}

	// --- All in One SEO v4: dedicated aioseo_posts table, not postmeta ---

	private static function aioseo_row( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function aioseo_get( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$row = self::aioseo_row( $post_id );
		return array(
			'post_id'       => $post_id,
			'title'         => $row['title'] ?? null,
			'description'   => $row['description'] ?? null,
			'canonical_url' => $row['canonical_url'] ?? null,
			'noindex'       => ! empty( $row['robots_noindex'] ),
			'nofollow'      => ! empty( $row['robots_nofollow'] ),
		);
	}

	public static function aioseo_update( array $args ) {
		global $wpdb;
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$table = $wpdb->prefix . 'aioseo_posts';

		$existing = self::aioseo_row( $post_id );
		$data     = array( 'post_id' => $post_id );
		$format   = array( '%d' );

		foreach ( array( 'title' => '%s', 'description' => '%s', 'canonical_url' => '%s' ) as $field => $fmt ) {
			if ( array_key_exists( $field, $args ) ) {
				$data[ $field ] = (string) $args[ $field ];
				$format[]       = $fmt;
			}
		}
		if ( array_key_exists( 'noindex', $args ) ) {
			$data['robots_noindex']  = $args['noindex'] ? 1 : 0;
			$data['robots_default']  = 0;
			$format[]                = '%d';
			$format[]                = '%d';
		}
		if ( array_key_exists( 'nofollow', $args ) ) {
			$data['robots_nofollow'] = $args['nofollow'] ? 1 : 0;
			$data['robots_default']  = 0;
			$format[]                = '%d';
			if ( ! array_key_exists( 'noindex', $args ) ) {
				$format[] = '%d';
			}
		}

		if ( $existing ) {
			$wpdb->update( $table, $data, array( 'post_id' => $post_id ), $format, array( '%d' ) );
		} else {
			$wpdb->insert( $table, $data, $format );
		}
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'All in One SEO table write failed: ' . $wpdb->last_error . ' — verify the aioseo_posts schema for your installed AIOSEO version.' );
		}
		return self::aioseo_get( array( 'post_id' => $post_id ) );
	}
}

RankOut_Connector_Tools_SEO::register();
