<?php
/**
 * Notification port for changes to optimized images.
 *
 * @package KilobyteImageOptimizer
 */

declare(strict_types=1);

namespace Kilobyte\ImageOptimizer\Domain\Repository;

use Kilobyte\ImageOptimizer\Domain\ValueObject\OptimizationRecord;

/**
 * Integration points outside the optimizer.
 */
interface OptimizationEventsInterface {

	/**
	 * Check whether optimization is permitted for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function should_optimize( int $attachment_id ): bool;

	/**
	 * Notify integrations after an optimized copy has been saved.
	 *
	 * @param int                $attachment_id Attachment ID.
	 * @param OptimizationRecord $record        Stored record.
	 * @return void
	 */
	public function optimized( int $attachment_id, OptimizationRecord $record ): void;

	/**
	 * Notify cache integrations after delivery changes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function variant_changed( int $attachment_id ): void;
}
