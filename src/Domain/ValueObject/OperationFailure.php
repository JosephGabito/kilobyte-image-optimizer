<?php
/**
 * A media operation that could not be completed.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\ValueObject;

/**
 * Carries a failure reason to the user interface.
 */
final class OperationFailure {

	public const BUSY               = 'locked';
	public const MISSING_SOURCE     = 'missing_source';
	public const INVALID_IMAGE      = 'invalid_image';
	public const INVALID_MIME       = 'invalid_mime';
	public const ANIMATED_IMAGE     = 'animated_image';
	public const IMAGE_TOO_LARGE    = 'image_too_large';
	public const SERVER_IMAGE_LIMIT = 'server_image_limit';
	public const UPLOAD_TOO_LARGE   = 'upload_too_large';
	public const MISSING_FILE       = 'missing_file';
	public const UPLOAD_FAILED      = 'upload_error';
	public const SOURCE_CHANGED     = 'source_changed';
	public const ORIGINAL_MISSING   = 'original_missing';
	public const NO_BACKUP          = 'no_backup';
	public const MISSING_BACKUP     = 'missing_backup';
	public const BACKUP_UNVERIFIED  = 'backup_unverified';
	public const ENCODE_FAILED      = 'encode_failed';
	public const WRITE_FAILED       = 'write_failed';
	public const RESTORE_FAILED     = 'restore_failed';
	public const RESTORE_UNFINISHED = 'restore_unfinished';
	public const BACKUPS_PROTECTED  = 'backups_protected';
	public const PURGE_FAILED       = 'purge_failed';
	public const REGEN_FAILED       = 'regen_failed';
	public const JOB_NOT_QUEUED     = 'job_not_queued';
	public const JOB_INTERRUPTED    = 'job_interrupted';

	/** @var string */
	private string $reason;

	/** @var string */
	private string $detail;

	/**
	 * @param string $reason Failure identifier.
	 * @param string $detail Optional encoder or storage message.
	 */
	public function __construct( string $reason, string $detail = '' ) {
		$this->reason = $reason;
		$this->detail = $detail;
	}

	/**
	 * @return string
	 */
	public function reason(): string {
		return $this->reason;
	}

	/**
	 * @return string
	 */
	public function detail(): string {
		return $this->detail;
	}
}
