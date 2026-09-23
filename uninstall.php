<?php
// Fired only on a real "Delete" from the Plugins screen, never on
// deactivate — mirrors WordPress's own convention (see class-db.php's
// deactivate(), which deliberately leaves data in place).
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-db.php';
RankOut_Connector_DB::drop_all();
