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

/**
 * Remove preferences and stop scheduled cleanup while the plugin is absent.
 *
 * @return void
 */
function kilobyte_io_uninstall(): void {
	$clean_site = static function (): void {
		// Keep the state needed by retained optimization records and their files.
		$cleanup = 'kilobyte_io_clean_retired_variants';
		if ( wp_next_scheduled( $cleanup ) ) {
			update_option( 'kilobyte_io_resume_cleanup', true, true );
		}
		wp_unschedule_hook( $cleanup );
		wp_unschedule_hook( 'kilobyte_io_retry_variant_cleanup' );
		delete_option( 'kilobyte_io_settings' );
	};

	if ( ! is_multisite() ) {
		$clean_site();
		return;
	}

	// Deleting the plugin removes its files for every site in the installation.
	$offset = 0;
	do {
		$site_ids = get_sites(
			array(
				'fields'            => 'ids',
				'number'            => 100,
				'offset'            => $offset,
				'orderby'           => 'id',
				'order'             => 'ASC',
				'update_site_cache' => false,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			try {
				$clean_site();
			} finally {
				restore_current_blog();
			}
		}
		$count   = count( $site_ids );
		$offset += $count;
	} while ( 100 === $count );
}

kilobyte_io_uninstall();
