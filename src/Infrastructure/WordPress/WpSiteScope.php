<?php
/**
 * Apply plugin lifecycle changes within each affected site's context.
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

/** Keeps network plugin operations scoped to the owning site's options and cron. */
final class WpSiteScope {

	/**
	 * Visit the current site or all network sites in bounded pages.
	 * @param callable $operation  Site-level work.
	 * @param int|null $network_id Network ID, zero for all networks, or null for the current site.
	 * @return void
	 */
	public static function run( callable $operation, ?int $network_id = null ): void {
		if ( null === $network_id || ! is_multisite() ) {
			$operation();
			return;
		}

		$offset = 0;
		do {
			$site_ids = get_sites(
				array(
					'network_id'        => $network_id,
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
					$operation();
				} finally {
					restore_current_blog();
				}
			}
			$count   = count( $site_ids );
			$offset += $count;
		} while ( 100 === $count );
	}
}
