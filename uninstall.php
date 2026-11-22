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

require_once __DIR__ . '/src/Infrastructure/WordPress/WpRemovedVariantQueue.php';
require_once __DIR__ . '/src/Infrastructure/WordPress/WpSiteScope.php';

use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpRemovedVariantQueue;
use Kilobyte\ImageOptimizer\Infrastructure\WordPress\WpSiteScope;

/**
 * Remove preferences and stop scheduled cleanup while the plugin is absent.
 *
 * @return void
 */
function kilobyte_io_uninstall(): void {
	$clean_site = static function (): void {
		// Keep the state needed by retained optimization records and their files.
		try {
			WpRemovedVariantQueue::retain_legacy_events( null, true );
			wp_unschedule_hook( 'kilobyte_io_clean_retired_variants' );
			wp_unschedule_hook( 'kilobyte_io_optimize_pending_uploads' );
		} catch ( \Throwable $error ) {
			// Stored work can be retried after reinstalling if scheduling is unavailable.
			return;
		} finally {
			delete_option( 'kilobyte_io_resume_cleanup' );
			delete_option( 'kilobyte_io_settings' );
		}
	};

	// Deleting the plugin removes its files for every site in the installation.
	WpSiteScope::run( $clean_site, 0 );
}

kilobyte_io_uninstall();
