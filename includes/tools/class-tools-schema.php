<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp_get_post_schema / wp_update_post_schema / wp_get_site_schema /
 * wp_update_site_schema — lets RankOut add structured data (schema.org
 * JSON-LD) where none exists, using the `mcp:schema:read`/`mcp:schema:write`
 * scopes `class-scopes.php` has always declared but, until now, had no tool
 * behind (see README's "Deliberately not built yet"). Writing a block here
 * has no front-end effect by itself — `class-schema-renderer.php` is what
 * actually prints it.
 *
 * Organization/WebSite are site-wide identity claims that Yoast, Rank Math,
 * and All in One SEO all generate automatically once active — adding a
 * second, independently-authored one is a real duplicate-entity problem
 * (two different "Organization" nodes for the same site), not just a
 * harmless extra copy, so `wp_update_site_schema` refuses those two types
 * outright when one of those plugins is active. A post-level type (Article,
 * FAQPage, ...) doesn't have that same hard conflict — at worst it's a
 * second JSON-LD block search engines can reasonably merge by @id — so
 * those aren't blocked; `active_seo_plugin` is still reported on every read
 * so the caller can judge whether one is already likely covered.
 */
class RankOut_Connector_Tools_Schema {

	const POST_META_KEY  = '_rankout_connector_schema';
	const SITE_OPTION_KEY = 'rankout_connector_site_schema';

	const POST_TYPES = array( 'Article', 'FAQPage', 'BreadcrumbList', 'Person', 'Product', 'Review', 'HowTo', 'Event', 'VideoObject', 'Recipe' );
	const SITE_TYPES = array( 'Organization', 'WebSite', 'LocalBusiness' );

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		RankOut_Connector_Tool_Registry::register(
			'wp_get_post_schema',
			'Get the custom JSON-LD schema.org blocks RankOut has added to one post/page (Article, FAQPage, BreadcrumbList, Person, Product, Review, HowTo, Event, VideoObject, or Recipe), plus which SEO plugin (if any) is active.',
			array(
				'type'       => 'object',
				'properties' => array( 'post_id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'post_id' ),
			),
			true,
			'mcp:schema:read',
			array( __CLASS__, 'get_post_schema' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_post_schema',
			'Add or replace one schema.org JSON-LD block on a post/page (e.g. Article, FAQPage, BreadcrumbList, Person). schema_type must match json_ld\'s own "@type". Replaces any existing block of the same schema_type on this post; other types are kept.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id'     => array( 'type' => 'integer' ),
					'schema_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES ),
					'json_ld'     => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				'required'   => array( 'post_id', 'schema_type', 'json_ld' ),
			),
			false,
			'mcp:schema:write',
			array( __CLASS__, 'update_post_schema' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_get_site_schema',
			'Get the custom site-wide JSON-LD schema.org blocks RankOut has added (Organization, WebSite, LocalBusiness), plus which SEO plugin (if any) is active and likely already provides these automatically.',
			array( 'type' => 'object', 'properties' => new stdClass() ),
			true,
			'mcp:schema:read',
			array( __CLASS__, 'get_site_schema' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_update_site_schema',
			'Add or replace a site-wide schema.org JSON-LD block (Organization, WebSite, or LocalBusiness), output on every page. schema_type must match json_ld\'s own "@type". Refused for Organization/WebSite when Yoast, Rank Math, or All in One SEO is active — they already generate those automatically, and a second independently-authored one is a duplicate-identity problem, not a harmless extra copy; adjust that plugin\'s own settings instead.',
			array(
				'type'       => 'object',
				'properties' => array(
					'schema_type' => array( 'type' => 'string', 'enum' => self::SITE_TYPES ),
					'json_ld'     => array( 'type' => 'object', 'additionalProperties' => true ),
				),
				'required'   => array( 'schema_type', 'json_ld' ),
			),
			false,
			'mcp:schema:write',
			array( __CLASS__, 'update_site_schema' )
		);
	}

	private static function active_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( 'AIOSEO' ) ) {
			return 'aioseo';
		}
		return null;
	}

	private static function seo_plugin_label( $slug ) {
		$labels = array( 'yoast' => 'Yoast SEO', 'rankmath' => 'Rank Math SEO', 'aioseo' => 'All in One SEO' );
		return $labels[ $slug ] ?? $slug;
	}

	/**
	 * Validates and normalizes one submitted JSON-LD object against its
	 * declared schema_type: defaults a missing "@context"/"@type", but
	 * refuses a "@type" that contradicts schema_type outright rather than
	 * silently overwriting it — a mismatch there means the caller built the
	 * wrong body, and silently "fixing" it would store something the
	 * caller never actually reviewed.
	 */
	private static function normalize_json_ld( $schema_type, $json_ld ) {
		if ( ! is_array( $json_ld ) || array() !== array_filter( array_keys( $json_ld ), 'is_int' ) ) {
			throw new RuntimeException( 'json_ld must be a JSON object, not an array.' );
		}
		if ( isset( $json_ld['@type'] ) && $json_ld['@type'] !== $schema_type ) {
			throw new RuntimeException( sprintf( 'json_ld["@type"] ("%s") does not match schema_type ("%s").', (string) $json_ld['@type'], $schema_type ) );
		}
		return array_merge( array( '@context' => 'https://schema.org' ), $json_ld, array( '@type' => $schema_type ) );
	}

	private static function require_post( $post_id ) {
		if ( ! get_post( $post_id ) ) {
			throw new RuntimeException( sprintf( 'No post found with id %d.', $post_id ) );
		}
	}

	// --- Post-level ---

	public static function get_post_schema( array $args ) {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		self::require_post( $post_id );
		$stored = get_post_meta( $post_id, self::POST_META_KEY, true );
		return array(
			'post_id'          => $post_id,
			'schema'           => is_array( $stored ) ? array_values( $stored ) : array(),
			'active_seo_plugin' => self::active_seo_plugin(),
		);
	}

	public static function update_post_schema( array $args ) {
		$post_id     = (int) ( $args['post_id'] ?? 0 );
		$schema_type = (string) ( $args['schema_type'] ?? '' );
		self::require_post( $post_id );
		if ( ! in_array( $schema_type, self::POST_TYPES, true ) ) {
			throw new RuntimeException( sprintf( 'schema_type must be one of: %s.', implode( ', ', self::POST_TYPES ) ) );
		}
		$normalized = self::normalize_json_ld( $schema_type, $args['json_ld'] ?? null );

		$stored                 = get_post_meta( $post_id, self::POST_META_KEY, true );
		$stored                 = is_array( $stored ) ? $stored : array();
		$stored[ $schema_type ] = $normalized;
		update_post_meta( $post_id, self::POST_META_KEY, $stored );

		$read_back = self::get_post_schema( array( 'post_id' => $post_id ) );
		$persisted = null;
		foreach ( $read_back['schema'] as $block ) {
			if ( ( $block['@type'] ?? null ) === $schema_type ) {
				$persisted = $block;
				break;
			}
		}
		if ( wp_json_encode( $persisted ) !== wp_json_encode( $normalized ) ) {
			throw new RuntimeException( 'WordPress did not persist the requested schema block.' );
		}
		return $read_back;
	}

	// --- Site-wide ---

	public static function get_site_schema( array $args = array() ) {
		$stored = get_option( self::SITE_OPTION_KEY, array() );
		return array(
			'schema'           => is_array( $stored ) ? array_values( $stored ) : array(),
			'active_seo_plugin' => self::active_seo_plugin(),
		);
	}

	public static function update_site_schema( array $args ) {
		$schema_type = (string) ( $args['schema_type'] ?? '' );
		if ( ! in_array( $schema_type, self::SITE_TYPES, true ) ) {
			throw new RuntimeException( sprintf( 'schema_type must be one of: %s.', implode( ', ', self::SITE_TYPES ) ) );
		}
		if ( in_array( $schema_type, array( 'Organization', 'WebSite' ), true ) ) {
			$active = self::active_seo_plugin();
			if ( $active ) {
				throw new RuntimeException( sprintf(
					'%s is active and already generates %s schema automatically. Adjust it in %s\'s own settings instead of adding a second, independently-authored %s block here.',
					self::seo_plugin_label( $active ), $schema_type, self::seo_plugin_label( $active ), $schema_type
				) );
			}
		}
		$normalized = self::normalize_json_ld( $schema_type, $args['json_ld'] ?? null );

		$stored                 = get_option( self::SITE_OPTION_KEY, array() );
		$stored                 = is_array( $stored ) ? $stored : array();
		$stored[ $schema_type ] = $normalized;
		update_option( self::SITE_OPTION_KEY, $stored );

		$read_back = self::get_site_schema();
		$persisted = null;
		foreach ( $read_back['schema'] as $block ) {
			if ( ( $block['@type'] ?? null ) === $schema_type ) {
				$persisted = $block;
				break;
			}
		}
		if ( wp_json_encode( $persisted ) !== wp_json_encode( $normalized ) ) {
			throw new RuntimeException( 'WordPress did not persist the requested schema block.' );
		}
		return $read_back;
	}
}

RankOut_Connector_Tools_Schema::register();
