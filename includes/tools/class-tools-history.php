<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers wp_history_list / wp_history_get / wp_history_diff /
 * wp_restore_revision — the exact tool names wpConnector.service.ts's
 * listHistory/getHistoryEntry/diffHistoryEntry/restoreHistoryEntry call
 * by name — and provides the before/after snapshot capture every other
 * write tool uses via RankOut_Connector_Tools_History::capture_snapshot()
 * / ::record(), called from class-mcp-server.php around each write.
 *
 * A history entry's `revision_id` is only ever set when the write it
 * logged went through wp_update_post()/wp_update_page() AND WordPress
 * itself decided to save a core post revision for it (Settings > Writing
 * → "Keep X revisions" must not be 0). Meta/SEO-field writes have no WP
 * revision to restore — restoreHistoryEntry on the backend already
 * expects and handles that (`revision_id <= 0` → a clean isError result,
 * not a crash).
 */
class RankOut_Connector_Tools_History {

	// Mirrors implementation.service.ts's `map` in validationReadFor()
	// exactly — the backend uses this same pairing to snapshot state
	// before letting a human approve a proposed write.
	const WRITE_TO_READ = array(
		'wp_update_post_meta'      => 'wp_get_post_meta',
		'wp_yoast_update_post_seo'  => 'wp_yoast_get_post_seo',
		'wp_aioseo_update_post_seo' => 'wp_aioseo_get_post_seo',
		'wp_rm_update_post_seo'     => 'wp_rm_get_post_seo',
		'wp_update_post'            => 'wp_get_post',
		'wp_update_page'            => 'wp_get_page',
	);

	public static function register() {
		add_action( 'rankout_connector_register_tools', array( __CLASS__, 'register_tools' ) );
	}

	public static function register_tools() {
		RankOut_Connector_Tool_Registry::register(
			'wp_history_list',
			'List recent RankOut Connector change history entries, optionally filtered by post_id.',
			array(
				'type'       => 'object',
				'properties' => array(
					'post_id' => array( 'type' => 'integer' ),
					'limit'   => array( 'type' => 'integer', 'default' => 20 ),
				),
			),
			true,
			'mcp:history:read',
			array( __CLASS__, 'tool_history_list' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_history_get',
			'Get one RankOut Connector change history entry by id.',
			array(
				'type'       => 'object',
				'properties' => array( 'id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id' ),
			),
			true,
			'mcp:history:read',
			array( __CLASS__, 'tool_history_get' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_history_diff',
			'Show the before/after field-level diff for one change history entry.',
			array(
				'type'       => 'object',
				'properties' => array( 'id_a' => array( 'type' => 'integer' ) ),
				'required'   => array( 'id_a' ),
			),
			true,
			'mcp:history:read',
			array( __CLASS__, 'tool_history_diff' )
		);

		RankOut_Connector_Tool_Registry::register(
			'wp_restore_revision',
			'Restore a WordPress post to a specific core revision id, undoing a previous content change.',
			array(
				'type'       => 'object',
				'properties' => array( 'revision_id' => array( 'type' => 'integer' ) ),
				'required'   => array( 'revision_id' ),
			),
			false,
			'mcp:posts:write',
			array( __CLASS__, 'tool_restore_revision' )
		);
	}

	public static function tool_history_list( array $args ) {
		global $wpdb;
		$table = RankOut_Connector_DB::history_table();
		$limit = isset( $args['limit'] ) ? max( 1, min( 100, (int) $args['limit'] ) ) : 20;

		if ( ! empty( $args['post_id'] ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $args['post_id'], $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
		}

		return array( 'entries' => array_map( array( __CLASS__, 'format_row' ), $rows ?: array() ) );
	}

	public static function tool_history_get( array $args ) {
		$row = self::get_row( (int) ( $args['id'] ?? 0 ) );
		if ( ! $row ) {
			throw new RuntimeException( 'Unknown history entry id.' );
		}
		return self::format_row( $row );
	}

	public static function tool_history_diff( array $args ) {
		$row = self::get_row( (int) ( $args['id_a'] ?? 0 ) );
		if ( ! $row ) {
			throw new RuntimeException( 'Unknown history entry id.' );
		}
		$before = json_decode( $row['before_json'], true ) ?: array();
		$after  = json_decode( $row['after_json'], true ) ?: array();
		$fields = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );

		$diff = array();
		foreach ( $fields as $field ) {
			$before_value = $before[ $field ] ?? null;
			$after_value  = $after[ $field ] ?? null;
			if ( $before_value === $after_value ) {
				continue;
			}
			$diff[] = array( 'field' => $field, 'before' => $before_value, 'after' => $after_value );
		}

		return array( 'id' => (int) $row['id'], 'tool_name' => $row['tool_name'], 'changed_fields' => $diff );
	}

	public static function tool_restore_revision( array $args ) {
		$revision_id = (int) ( $args['revision_id'] ?? 0 );
		$revision    = $revision_id ? get_post( $revision_id ) : null;
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			throw new RuntimeException( 'Unknown revision id.' );
		}
		$restored = wp_restore_post_revision( $revision_id );
		if ( ! $restored || is_wp_error( $restored ) ) {
			throw new RuntimeException( is_wp_error( $restored ) ? $restored->get_error_message() : 'Failed to restore this revision.' );
		}
		return array( 'restored' => true, 'post_id' => (int) $revision->post_parent, 'revision_id' => $revision_id );
	}

	private static function get_row( $id ) {
		if ( ! $id ) {
			return null;
		}
		global $wpdb;
		$table = RankOut_Connector_DB::history_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function format_row( array $row ) {
		return array(
			'id'          => (int) $row['id'],
			'post_id'     => (int) $row['post_id'],
			'tool_name'   => $row['tool_name'],
			'revision_id' => null !== $row['revision_id'] ? (int) $row['revision_id'] : null,
			'wp_user_id'  => (int) $row['wp_user_id'],
			'created_at'  => mysql2date( 'c', $row['created_at'], false ),
		);
	}

	/**
	 * Called by class-mcp-server.php BEFORE a write tool's handler runs,
	 * so the "before" state is captured from the live site, not
	 * reconstructed after the fact. Best-effort: a tool with no paired
	 * read counterpart (or no post_id in its args) simply gets an empty
	 * snapshot — recording history is never allowed to block the actual
	 * write from happening.
	 */
	public static function capture_snapshot( $tool_name, array $args ) {
		$read_tool_name = self::WRITE_TO_READ[ $tool_name ] ?? null;
		if ( ! $read_tool_name || empty( $args['post_id'] ) ) {
			return array();
		}
		$read_tool = RankOut_Connector_Tool_Registry::get( $read_tool_name );
		if ( ! $read_tool ) {
			return array();
		}
		$read_args = array( 'post_id' => $args['post_id'] );
		if ( isset( $args['post_type'] ) ) {
			$read_args['post_type'] = $args['post_type'];
		}
		try {
			return call_user_func( $read_tool['handler'], $read_args, 0 );
		} catch ( Exception $exception ) {
			return array();
		}
	}

	/**
	 * Called by class-mcp-server.php BEFORE a content-write tool's
	 * handler runs. WordPress only creates a post revision as a side
	 * effect of wp_update_post() itself, and — confirmed empirically,
	 * not assumed — that automatic revision snapshots the POST-write
	 * content, not the pre-write content: restoring "the newest revision
	 * right after this write" is a silent no-op, since it just restores
	 * the same content the write had just set. Explicitly saving a
	 * revision of the CURRENT (about-to-be-overwritten) content here,
	 * before the write runs, is the only reliable restore point for
	 * undoing this specific write.
	 */
	public static function capture_pre_write_revision( $tool_name, array $args ) {
		if ( ! in_array( $tool_name, array( 'wp_update_post', 'wp_update_page' ), true ) || empty( $args['post_id'] ) ) {
			return null;
		}
		$revision_id = wp_save_post_revision( (int) $args['post_id'] );
		return ( $revision_id && ! is_wp_error( $revision_id ) ) ? (int) $revision_id : null;
	}

	/**
	 * Called by class-mcp-server.php AFTER a write tool's handler
	 * succeeds. Captures the "after" state the same way as the "before"
	 * snapshot. $pre_write_revision_id comes from
	 * capture_pre_write_revision(), called before the write ran — see
	 * that method for why this can't be resolved after the fact.
	 */
	public static function record( $tool_name, array $args, array $before_snapshot, array $write_result, $wp_user_id, $pre_write_revision_id = null ) {
		if ( empty( $args['post_id'] ) ) {
			return;
		}
		$after_snapshot = self::capture_snapshot( $tool_name, $args );
		$revision_id    = $pre_write_revision_id;

		global $wpdb;
		$wpdb->insert(
			RankOut_Connector_DB::history_table(),
			array(
				'post_id'     => (int) $args['post_id'],
				'tool_name'   => $tool_name,
				'args_json'   => wp_json_encode( $args ),
				'before_json' => wp_json_encode( $before_snapshot ),
				'after_json'  => wp_json_encode( $after_snapshot ),
				'revision_id' => $revision_id,
				'wp_user_id'  => $wp_user_id,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
}

RankOut_Connector_Tools_History::register();
