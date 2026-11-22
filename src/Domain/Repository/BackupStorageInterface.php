<?php
/**
 * Port for restoring and purging backups created by earlier versions.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

/**
 * Legacy backup storage contract.
 */
interface BackupStorageInterface {

	/**
	 * Resolve a stored relative backup path.
	 *
	 * @param string $relative_path Relative path inside uploads.
	 * @return string|null
	 */
	public function resolve_existing( string $relative_path ): ?string;

	/**
	 * Check for files that may be the only copy of an original.
	 *
	 * @return bool|null
	 */
	public function has_originals(): ?bool;
}
