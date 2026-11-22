<?php
/**
 * Manage originals saved by earlier versions of Kilobyte.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Infrastructure\WordPress;

use Kilobyte\ImageOptimizer\Domain\Repository\BackupStorageInterface;
use WP_Filesystem_Direct;

/**
 * Reads legacy backups for recovery.
 */
final class WpFilesystemBackupStorage implements BackupStorageInterface {

	private const DIRECTORY = 'kilobyte-backups';

	/**
	 * Resolve an existing file inside the site's legacy backup directory.
	 *
	 * @param string $relative_path Relative path inside uploads.
	 * @return string|null
	 */
	public function resolve_existing( string $relative_path ): ?string {
		$filesystem = WpLocalFilesystem::get();
		$root       = $this->backup_root();
		$prefix     = self::DIRECTORY . '/';
		if ( null === $root || 0 !== strpos( $relative_path, $prefix ) || validate_file( $relative_path ) ) {
			return null;
		}

		$path = $root . '/' . substr( $relative_path, strlen( $prefix ) );
		$file = realpath( $path );
		return ! is_link( $path ) && false !== $file && 0 === strpos( $file, trailingslashit( $root ) ) && $filesystem->is_file( $file ) ? $file : null;
	}

	/**
	 * Check for originals left by earlier releases.
	 *
	 * @return bool|null Null if the folder could not be verified.
	 */
	public function has_originals(): ?bool {
		$root = $this->backup_root();
		if ( null === $root ) {
			return null;
		}

		$filesystem = WpLocalFilesystem::get();
		if ( ! $filesystem->exists( $root ) ) {
			return false;
		}
		return $this->folder_has_originals( $root, $filesystem, true );
	}

	/**
	 * Check a legacy backup directory without following symlinks.
	 *
	 * @param string               $dir        Canonical directory.
	 * @param WP_Filesystem_Direct $filesystem Filesystem access.
	 * @param bool                 $is_root    Whether this is the root with legacy guard files.
	 * @return bool|null
	 */
	private function folder_has_originals( string $dir, WP_Filesystem_Direct $filesystem, bool $is_root ): ?bool {
		$entries = $filesystem->dirlist( $dir, true );
		if ( ! is_array( $entries ) ) {
			return null;
		}
		foreach ( $entries as $name => $entry ) {
			if ( ! is_string( $name ) || wp_basename( $name ) !== $name || validate_file( $name ) ) {
				return null;
			}
			$file = $dir . '/' . $name;
			if ( is_link( $file ) || realpath( $file ) !== $file ) {
				return null;
			}
			if ( 'd' === $entry['type'] ) {
				$has_originals = $this->folder_has_originals( $file, $filesystem, false );
				if ( false !== $has_originals ) {
					return $has_originals;
				}
			} elseif ( ! $is_root || ! in_array( $name, array( 'index.php', '.htaccess' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the contained backup path without creating a directory.
	 *
	 * @return string|null
	 */
	private function backup_root(): ?string {
		$uploads = wp_get_upload_dir();
		$base    = realpath( (string) $uploads['basedir'] );
		if ( false === $base ) {
			return null;
		}
		$root = trailingslashit( $base ) . self::DIRECTORY;
		return is_link( $root ) ? null : $root;
	}
}
