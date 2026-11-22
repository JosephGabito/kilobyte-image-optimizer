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
use Kilobyte\ImageOptimizer\Domain\Repository\OptimizationEventsInterface;
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

	/** @var OptimizationEventsInterface */
	private OptimizationEventsInterface $events;

	/** @var SaveOptimizedVariant */
	private SaveOptimizedVariant $save_variant;

	/**
	 * Constructor.
	 *
	 * @param AttachmentRepositoryInterface $attachments Attachment repository.
	 * @param OptimizationEventsInterface    $events      Integration events.
	 * @param SaveOptimizedVariant           $save_variant Record writer.
	 */
	public function __construct( AttachmentRepositoryInterface $attachments, OptimizationEventsInterface $events, SaveOptimizedVariant $save_variant ) {
		$this->attachments  = $attachments;
		$this->events       = $events;
		$this->save_variant = $save_variant;
	}

	/**
	 * Execute skip recording.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param string $source_hash Hash of the source the browser checked.
	 * @return array<string, mixed>|OperationFailure
	 */
	public function execute( int $attachment_id, string $source_hash ) {
		$result = $this->attachments->with_attachment_lock(
			$attachment_id,
			function () use ( $attachment_id, $source_hash ) {
				return $this->record_skip( $attachment_id, $source_hash );
			}
		);
		return $result ?? new OperationFailure( OperationFailure::BUSY );
	}

	/**
	 * Record a skip without replacing existing work.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param string $source_hash Hash of the source the browser checked.
	 * @return array<string, mixed>|OperationFailure
	 */
	private function record_skip( int $attachment_id, string $source_hash ) {
		if ( ! $this->events->should_optimize( $attachment_id ) ) {
			return array(
				'id'     => $attachment_id,
				'status' => 'excluded',
			);
		}
		$image = $this->attachments->find_image( $attachment_id, true );
		if ( null === $image || ! in_array( $image['mime_type'], CompressionPolicy::SUPPORTED_SOURCE_MIMES, true ) ) {
			return new OperationFailure( OperationFailure::MISSING_SOURCE );
		}
		if ( '' === $source_hash || ! hash_equals( $image['source_hash'], $source_hash ) ) {
			return new OperationFailure( OperationFailure::SOURCE_CHANGED );
		}
		$record = $this->attachments->get_record( $attachment_id );
		if ( null !== $record && ! $record->is_unchanged() ) {
			return array(
				'id'     => $attachment_id,
				'status' => 'preserved_existing',
			);
		}
		return $this->save_variant->record_unchanged( $image, OptimizationRecord::ENGINE_BROWSER );
	}
}
