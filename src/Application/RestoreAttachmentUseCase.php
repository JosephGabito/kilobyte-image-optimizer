<?php
/**
 * Use case: restore an attachment by removing its sibling variant or restoring its backup.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\BackupStorageInterface;
use Kilobyte\ImageOptimizer\Domain\Repository\VariantRetirementInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Orchestrates restoration of an attachment to its original state.
 */
final class RestoreAttachmentUseCase {

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/** @var OptimizationEventsInterface */
	private OptimizationEventsInterface $events;

	/**
	 * Backup storage.
	 *
	 * @var BackupStorageInterface
	 */
	private BackupStorageInterface $backups;

	/** @var VariantRetirementInterface */
	private VariantRetirementInterface $retirement;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 * @param VariantRetirementInterface    $retirement  Deferred cleanup.
	 * @param BackupStorageInterface        $backups     Backup storage.
	 * @param OptimizationEventsInterface    $events      Integration events.
	 */
	public function __construct(
		AttachmentRepositoryInterface $attachments,
		OptimizationEventsInterface $events,
		BackupStorageInterface $backups,
		VariantRetirementInterface $retirement
	) {
		$this->attachments = $attachments;
		$this->events      = $events;
		$this->backups     = $backups;
		$this->retirement  = $retirement;
	}

	/**
	 * Execute restore for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( int $attachment_id ) {
		$result = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id ) {
				$record = $this->attachments->get_record( $attachment_id );
				if ( null === $record || ! $record->is_restorable() ) {
					return new OperationFailure( OperationFailure::NO_BACKUP );
				}

				if ( $record->is_sibling() ) {
					if ( ! $this->attachments->has_readable_original( $attachment_id ) ) {
						return new OperationFailure( OperationFailure::ORIGINAL_MISSING );
					}
					if ( ! $this->retirement->retire( $attachment_id, $record ) || ! $this->attachments->delete_record( $attachment_id ) ) {
						return new OperationFailure( OperationFailure::RESTORE_FAILED );
					}
					$this->events->variant_changed( $attachment_id );
					return array(
						'id'       => $attachment_id,
						'restored' => true,
						'mode'     => 'sibling',
					);
				}

				return $this->attachments->with_backup_lock(
					function () use ( $attachment_id, $record ) {
						return $this->restore_backup( $attachment_id, $record );
					}
				);
			}
		);

		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}

	/**
	 * Restore an original kept in the backup folder by an earlier version.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored backup reference.
	 * @return array<string, mixed>|OperationFailure
	 */
	private function restore_backup( int $attachment_id, OptimizationRecord $record ) {
		$backup = $this->backups->resolve_existing( $record->backup_path() );
		if ( null === $backup ) {
			return new OperationFailure( OperationFailure::MISSING_BACKUP );
		}

		$restored = $this->attachments->restore_file( $attachment_id, $backup );
		if ( null === $restored ) {
			return new OperationFailure( OperationFailure::RESTORE_FAILED );
		}

		$cleared = $this->attachments->delete_record( $attachment_id );
		$this->events->variant_changed( $attachment_id );
		if ( ! $cleared ) {
			return new OperationFailure( OperationFailure::RESTORE_UNFINISHED );
		}
		return array(
			'id'               => $attachment_id,
			'restored'         => true,
			'mode'             => 'in_place',
			'backup_removed'   => false,
			'thumbnails_ready' => $restored['thumbnails_ready'],
		);
	}
}
