<?php
/**
 * Use case: regenerate all registered thumbnail sizes for an attachment.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Orchestrates thumbnail regeneration.
 */
final class RegenerateThumbnailsUseCase {

	/**
	 * Attachment repository.
	 *
	 * @var AttachmentRepositoryInterface
	 */
	private AttachmentRepositoryInterface $attachments;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 */
	public function __construct( AttachmentRepositoryInterface $attachments ) {
		$this->attachments = $attachments;
	}

	/**
	 * Execute thumbnail regeneration.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, int>|OperationFailure
	 */
	public function execute( int $attachment_id ) {
		$sizes = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id ): int {
				return $this->attachments->regenerate_thumbnails( $attachment_id ) ?? -1;
			}
		);
		if ( null === $sizes ) {
			return new OperationFailure( OperationFailure::BUSY );
		}
		if ( $sizes < 0 ) {
			return new OperationFailure( OperationFailure::REGEN_FAILED );
		}

		return array(
			'id'    => $attachment_id,
			'sizes' => $sizes,
		);
	}
}
