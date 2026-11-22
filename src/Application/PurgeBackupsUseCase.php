<?php
/**
 * Use case: clear stale backup references when no original backups remain.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\BackupStorageInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Clears counters only when the backup folder has no originals.
 */
final class PurgeBackupsUseCase {

	/**
	 * Backup storage.
	 *
	 * @var BackupStorageInterface
	 */
	private BackupStorageInterface $backups;

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/**
	 * Constructor.
	 *
	 * @param BackupStorageInterface        $backups     Backup storage.
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 */
	public function __construct(
		BackupStorageInterface $backups,
		AttachmentRepositoryInterface $attachments
	) {
		$this->backups     = $backups;
		$this->attachments = $attachments;
	}

	/**
	 * Check that no recoverable originals will be lost before clearing counters.
	 *
	 * @return array{purged: bool, freed_bytes: int}|OperationFailure
	 */
	public function execute() {
		$result = $this->attachments->with_backup_lock(
			function () {
				$has_originals = $this->backups->has_originals();
				if ( true === $has_originals ) {
					return new OperationFailure( OperationFailure::BACKUPS_PROTECTED );
				}
				if ( null === $has_originals || ! $this->attachments->clear_backup_counters() ) {
					return new OperationFailure( OperationFailure::PURGE_FAILED );
				}
				return array(
					'purged'      => true,
					'freed_bytes' => 0,
				);
			}
		);

		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}
}
