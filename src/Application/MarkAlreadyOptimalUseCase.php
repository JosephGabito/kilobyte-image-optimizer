<?php
/**
 * Use case: mark an attachment as already optimal so it leaves the bulk queue.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Application;

use Kilobyte\ImageOptimizer\Domain\Repository\AttachmentRepositoryInterface;
use Kilobyte\ImageOptimizer\Domain\Policy\CompressionPolicy;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;
use Kilobyte\ImageOptimizer\Domain\ValueObject\OperationFailure;

/**
 * Records an attachment as already optimal.
 */
final class MarkAlreadyOptimalUseCase {

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
	 * Execute skip recording.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( int $attachment_id ) {
		$result = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id ) {
				return $this->record_skip( $attachment_id );
			}
		);
		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}

	/**
	 * Record a skip without replacing existing work.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array<string, mixed>|OperationFailure
	 */
	private function record_skip( int $attachment_id ) {
		if ( null !== $this->attachments->get_record( $attachment_id ) ) {
			return array(
				'id'     => $attachment_id,
				'status' => 'preserved_existing',
			);
		}

		$image = $this->attachments->find_image( $attachment_id );
		if ( null === $image || ! in_array( $image['mime_type'], CompressionPolicy::SUPPORTED_SOURCE_MIMES, true ) ) {
			return new OperationFailure( OperationFailure::MISSING_SOURCE );
		}

		$size   = (int) $image['file_size'];
		$record = new OptimizationRecord(
			$size,
			$size,
			strtolower( pathinfo( (string) $image['file'], PATHINFO_EXTENSION ) ),
			(string) $image['filename'],
			'',
			(string) gmdate( 'Y-m-d H:i:s' ),
			OptimizationRecord::STATUS_ALREADY_OPTIMAL,
			OptimizationRecord::ENGINE_BROWSER
		);

		if ( ! $this->attachments->save_record( $attachment_id, $record ) ) {
			return new OperationFailure( OperationFailure::WRITE_FAILED );
		}

		return array(
			'id'     => $attachment_id,
			'status' => OptimizationRecord::STATUS_ALREADY_OPTIMAL,
		);
	}
}
