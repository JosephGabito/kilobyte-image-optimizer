<?php
/**
 * Direct filesystem adapter for paths already known to be local.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use WP_Filesystem_Direct;

/**
 * Uses the permissions of the upload path rather than the core directory.
 */
final class WpLocalFilesystem {

	/**
	 * @return WP_Filesystem_Direct
	 */
	public static function get(): WP_Filesystem_Direct {
		static $filesystem;
		if ( ! $filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$filesystem = new WP_Filesystem_Direct( null );
		}

		return $filesystem;
	}
}
