<?php
/**
 * Uninstall cleanup for Kilobyte Image Optimizer.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kilobyte_io_settings' );
delete_option( 'kilobyte_io_stats' );
delete_option( 'kilobyte_io_backups_purged' );
wp_clear_scheduled_hook( 'kilobyte_io_clean_retired_variants' );
delete_option( 'kilobyte_io_cleanup_before' );
delete_option( 'kilobyte_io_stats_need_rebuild' );
